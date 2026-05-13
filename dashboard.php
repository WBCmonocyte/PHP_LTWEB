<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$fullName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Quản trị viên";
$role = $_SESSION["role"] ?? "Admin";

$supplierStats = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_suppliers,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active_suppliers,
        SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_suppliers
    FROM suppliers
");

$orderStats = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN order_status = 'Pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN order_status = 'Pending' AND expected_date < NOW() THEN 1 ELSE 0 END) AS late_orders,
        SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN order_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
        COALESCE(SUM(CASE WHEN order_status = 'Completed' THEN total_amount ELSE 0 END), 0) AS completed_revenue
    FROM purchase_orders
");

$completionStats = fetch_one($conn, "
    SELECT
        SUM(CASE WHEN order_status = 'Completed' AND DATE(COALESCE(actual_date, order_date)) = CURDATE() THEN 1 ELSE 0 END) AS completed_today,
        SUM(CASE WHEN order_status = 'Completed' AND YEAR(COALESCE(actual_date, order_date)) = YEAR(CURDATE()) AND MONTH(COALESCE(actual_date, order_date)) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS completed_month,
        SUM(CASE WHEN order_status = 'Completed' AND YEAR(COALESCE(actual_date, order_date)) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS completed_year,
        COALESCE(SUM(CASE WHEN order_status = 'Completed' AND DATE(COALESCE(actual_date, order_date)) = CURDATE() THEN total_amount ELSE 0 END), 0) AS revenue_today,
        COALESCE(SUM(CASE WHEN order_status = 'Completed' AND YEAR(COALESCE(actual_date, order_date)) = YEAR(CURDATE()) AND MONTH(COALESCE(actual_date, order_date)) = MONTH(CURDATE()) THEN total_amount ELSE 0 END), 0) AS revenue_month,
        COALESCE(SUM(CASE WHEN order_status = 'Completed' AND YEAR(COALESCE(actual_date, order_date)) = YEAR(CURDATE()) THEN total_amount ELSE 0 END), 0) AS revenue_year
    FROM purchase_orders
");

$bestSupplier = fetch_one($conn, "
    SELECT
        s.supplier_id,
        s.supplier_name,
        s.contact_email,
        s.phone_number,
        COUNT(po.order_id) AS total_orders,
        SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN po.order_status = 'Completed' AND po.actual_date IS NOT NULL AND po.actual_date <= po.expected_date THEN 1 ELSE 0 END) AS on_time_orders,
        SUM(CASE WHEN po.order_status = 'Pending' AND po.expected_date < NOW() THEN 1 ELSE 0 END) AS late_pending_orders,
        COALESCE(SUM(CASE WHEN po.order_status = 'Completed' THEN po.total_amount ELSE 0 END), 0) AS completed_revenue,
        CASE
            WHEN SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) = 0 THEN 0
            ELSE ROUND(
                SUM(CASE WHEN po.order_status = 'Completed' AND po.actual_date IS NOT NULL AND po.actual_date <= po.expected_date THEN 1 ELSE 0 END)
                / SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) * 100,
                1
            )
        END AS on_time_rate
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id = s.supplier_id
    WHERE s.status = 'Active'
    GROUP BY s.supplier_id, s.supplier_name, s.contact_email, s.phone_number
    ORDER BY completed_orders DESC, on_time_rate DESC, completed_revenue DESC, late_pending_orders ASC, total_orders DESC
    LIMIT 1
");

$supplierRanking = fetch_all($conn, "
    SELECT
        s.supplier_id,
        s.supplier_name,
        s.status,
        COUNT(po.order_id) AS total_orders,
        SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN po.order_status = 'Pending' AND po.expected_date < NOW() THEN 1 ELSE 0 END) AS late_pending_orders,
        COALESCE(SUM(CASE WHEN po.order_status = 'Completed' THEN po.total_amount ELSE 0 END), 0) AS completed_revenue,
        CASE
            WHEN SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) = 0 THEN 0
            ELSE ROUND(
                SUM(CASE WHEN po.order_status = 'Completed' AND po.actual_date IS NOT NULL AND po.actual_date <= po.expected_date THEN 1 ELSE 0 END)
                / SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) * 100,
                1
            )
        END AS on_time_rate
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id = s.supplier_id
    GROUP BY s.supplier_id, s.supplier_name, s.status
    ORDER BY completed_orders DESC, on_time_rate DESC, late_pending_orders ASC, completed_revenue DESC
    LIMIT 8
");

$lateOrders = fetch_all($conn, "
    SELECT
        po.order_id,
        po.expected_date,
        po.total_amount,
        s.supplier_name,
        s.phone_number
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.order_status = 'Pending' AND po.expected_date < NOW()
    ORDER BY po.expected_date ASC
    LIMIT 6
");

$pendingOrders = fetch_all($conn, "
    SELECT
        po.order_id,
        po.order_date,
        po.expected_date,
        po.total_amount,
        s.supplier_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.order_status = 'Pending'
    ORDER BY po.expected_date ASC
    LIMIT 8
");

$recentCompletedOrders = fetch_all($conn, "
    SELECT
        po.order_id,
        po.actual_date,
        po.order_date,
        po.expected_date,
        po.total_amount,
        s.supplier_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.order_status = 'Completed'
    ORDER BY COALESCE(po.actual_date, po.order_date) DESC
    LIMIT 8
");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard nhà cung cấp</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
            --info: #0891b2;
            --dark: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --surface: #ffffff;
            --background: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            font-family: 'Be Vietnam Pro', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        body {
            min-height: 100vh;
            background: var(--background);
            color: var(--dark);
            font-size: 15px;
            line-height: 1.5;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            padding: 28px 22px;
            background: linear-gradient(180deg, #111827, #1e1b4b);
            color: #ffffff;
        }

        .brand {
            margin-bottom: 34px;
        }

        .brand span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            margin-bottom: 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 20px;
            font-weight: 800;
        }

        .brand h2 {
            margin-bottom: 6px;
            font-size: 22px;
        }

        .brand p,
        .user-box p {
            color: #cbd5e1;
            line-height: 1.5;
            font-size: 14px;
        }

        .nav {
            display: grid;
            gap: 10px;
            margin-bottom: 32px;
        }

        .nav a {
            padding: 12px 14px;
            border-radius: 12px;
            color: #e2e8f0;
            font-weight: 700;
        }

        .nav a.active,
        .nav a:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
        }

        .user-box {
            margin-top: auto;
            padding: 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.1);
        }

        .user-box strong {
            display: block;
            margin-bottom: 4px;
        }

        .logout {
            display: inline-block;
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-weight: 700;
        }

        .main {
            padding: 30px;
            overflow-x: hidden;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 26px;
        }

        .topbar h1 {
            margin-bottom: 8px;
            font-size: 30px;
            letter-spacing: -0.03em;
        }

        .topbar p {
            color: var(--muted);
            line-height: 1.5;
        }

        .date-pill {
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--muted);
            font-weight: 700;
            white-space: nowrap;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card,
        .panel,
        .hero-card {
            border: 1px solid var(--border);
            border-radius: 22px;
            background: var(--surface);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.06);
        }

        .stat-card {
            padding: 20px;
        }

        .stat-card .label {
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .stat-card .value {
            margin-bottom: 8px;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .stat-card .hint {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .success {
            color: var(--success);
        }

        .warning {
            color: var(--warning);
        }

        .danger {
            color: var(--danger);
        }

        .info {
            color: var(--info);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel,
        .hero-card {
            padding: 22px;
        }

        .panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .panel-header h2 {
            margin-bottom: 6px;
            font-size: 20px;
        }

        .panel-header p {
            color: var(--muted);
            line-height: 1.5;
            font-size: 14px;
        }

        .hero-card {
            display: grid;
            gap: 18px;
            min-height: 100%;
            background: linear-gradient(135deg, #ffffff, #eef2ff);
        }

        .hero-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .hero-title h2 {
            font-size: 20px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: var(--primary);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge.green {
            background: #dcfce7;
            color: #166534;
        }

        .badge.orange {
            background: #ffedd5;
            color: #9a3412;
        }

        .badge.red {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.plain {
            background: transparent;
            padding: 0;
        }

        .best-name {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .best-meta {
            display: grid;
            gap: 8px;
            color: var(--muted);
            line-height: 1.5;
        }

        .metric-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .mini-metric {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.75);
        }

        .mini-metric strong {
            display: block;
            margin-bottom: 4px;
            font-size: 20px;
        }

        .mini-metric span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 10px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        td strong {
            display: block;
            margin-bottom: 3px;
        }

        .subtext {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.4;
        }

        .empty-state {
            padding: 26px;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            background: #f8fafc;
            color: var(--muted);
            line-height: 1.6;
            text-align: center;
        }

        .completion-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .completion-card {
            padding: 18px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid var(--border);
        }

        .completion-card span {
            display: block;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .completion-card strong {
            display: block;
            margin-bottom: 6px;
            font-size: 26px;
        }

        .completion-card p {
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 1180px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 780px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
            }

            .main {
                padding: 22px;
            }

            .topbar,
            .panel-header,
            .hero-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-grid,
            .metric-row,
            .completion-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <span>KT</span>
                <h2>Quản Lý Nhà Cung Cấp</h2>
                <p>Dashboard quản lý nhà cung cấp, đơn mua và hiệu suất giao hàng.</p>
            </div>

            <nav class="nav">
                <a class="active" href="#overview">Tổng quan</a>
                <a href="orders.php">Quản lý đơn hàng</a>
                <a href="#best-supplier">Nhà cung cấp tốt nhất</a>
                <a href="#late-orders">Đơn hàng muộn</a>
                <a href="#completed-orders">Đơn hoàn thành</a>
                <a href="index.php">Trang chủ công khai</a>
            </nav>

            <div class="user-box">
                <strong><?php echo htmlspecialchars($fullName, ENT_QUOTES, "UTF-8"); ?></strong>
                <p><?php echo htmlspecialchars($role, ENT_QUOTES, "UTF-8"); ?></p>
                <a class="logout" href="logout.php">Đăng xuất</a>
            </div>
        </aside>

        <main class="main">
            <section class="topbar" id="overview">
                <div>
                    <h1>Dashboard nhà cung cấp</h1>
                    <p>Theo dõi nhà cung cấp tốt nhất, đơn đang giao, đơn trễ hạn và đơn đã hoàn thành.</p>
                </div>
                <div class="date-pill"><?php echo date("d/m/Y"); ?></div>
            </section>

            <section class="stats-grid">
                <article class="stat-card">
                    <div class="label">Nhà cung cấp</div>
                    <div class="value"><?php echo number_value($supplierStats["total_suppliers"] ?? 0); ?></div>
                    <div class="hint">
                        <span class="success"><?php echo number_value($supplierStats["active_suppliers"] ?? 0); ?> đang hoạt động</span>
                        · <?php echo number_value($supplierStats["inactive_suppliers"] ?? 0); ?> tạm dừng
                    </div>
                </article>

                <article class="stat-card">
                    <div class="label">Đơn đang giao</div>
                    <div class="value info"><?php echo number_value($orderStats["pending_orders"] ?? 0); ?></div>
                    <div class="hint">Các đơn ở trạng thái Pending.</div>
                </article>

                <article class="stat-card">
                    <div class="label">Đơn đang muộn</div>
                    <div class="value danger"><?php echo number_value($orderStats["late_orders"] ?? 0); ?></div>
                    <div class="hint">Pending và đã quá ngày dự kiến giao.</div>
                </article>

                <article class="stat-card">
                    <div class="label">Giá trị hoàn thành</div>
                    <div class="value success"><?php echo money_format_vnd($orderStats["completed_revenue"] ?? 0); ?></div>
                    <div class="hint"><?php echo number_value($orderStats["completed_orders"] ?? 0); ?> đơn đã hoàn thành.</div>
                </article>
            </section>

            <section class="content-grid">
                <article class="hero-card" id="best-supplier">
                    <div class="hero-title">
                        <h2>Nhà cung cấp tốt nhất</h2>
                        <span class="badge green">Best supplier</span>
                    </div>

                    <?php if (!empty($bestSupplier) && (int) ($bestSupplier["supplier_id"] ?? 0) > 0): ?>
                        <div>
                            <div class="best-name"><?php echo htmlspecialchars($bestSupplier["supplier_name"], ENT_QUOTES, "UTF-8"); ?></div>
                            <div class="best-meta">
                                <span>Email: <?php echo htmlspecialchars($bestSupplier["contact_email"] ?: "Chưa cập nhật", ENT_QUOTES, "UTF-8"); ?></span>
                                <span>Điện thoại: <?php echo htmlspecialchars($bestSupplier["phone_number"] ?: "Chưa cập nhật", ENT_QUOTES, "UTF-8"); ?></span>
                            </div>
                        </div>

                        <div class="metric-row">
                            <div class="mini-metric">
                                <strong><?php echo number_value($bestSupplier["completed_orders"] ?? 0); ?></strong>
                                <span>Đơn hoàn thành</span>
                            </div>
                            <div class="mini-metric">
                                <strong><?php echo percent_value($bestSupplier["on_time_rate"] ?? 0); ?></strong>
                                <span>Tỷ lệ đúng hạn</span>
                            </div>
                            <div class="mini-metric">
                                <strong><?php echo money_format_vnd($bestSupplier["completed_revenue"] ?? 0); ?></strong>
                                <span>Tổng giá trị</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Chưa có nhà cung cấp nào để đánh giá. Hãy thêm nhà cung cấp và đơn hàng để dashboard tự xếp hạng.</div>
                    <?php endif; ?>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Hoàn thành theo thời gian</h2>
                            <p>Số đơn hoàn thành trong ngày, tháng và năm hiện tại.</p>
                        </div>
                    </div>

                    <div class="completion-grid">
                        <div class="completion-card">
                            <span>Hôm nay</span>
                            <strong><?php echo number_value($completionStats["completed_today"] ?? 0); ?></strong>
                            <p><?php echo money_format_vnd($completionStats["revenue_today"] ?? 0); ?></p>
                        </div>
                        <div class="completion-card">
                            <span>Tháng này</span>
                            <strong><?php echo number_value($completionStats["completed_month"] ?? 0); ?></strong>
                            <p><?php echo money_format_vnd($completionStats["revenue_month"] ?? 0); ?></p>
                        </div>
                        <div class="completion-card">
                            <span>Năm này</span>
                            <strong><?php echo number_value($completionStats["completed_year"] ?? 0); ?></strong>
                            <p><?php echo money_format_vnd($completionStats["revenue_year"] ?? 0); ?></p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="content-grid">
                <article class="panel" id="late-orders">
                    <div class="panel-header">
                        <div>
                            <h2>Nhà cung cấp đang muộn</h2>
                            <p>Các đơn Pending đã vượt quá ngày giao dự kiến.</p>
                        </div>
                        <span class="badge red"><?php echo number_value(count($lateOrders)); ?> đơn</span>
                    </div>

                    <?php if (count($lateOrders) > 0): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Nhà cung cấp</th>
                                        <th>Hạn giao</th>
                                        <th>Trạng thái</th>
                                        <th>Giá trị</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lateOrders as $order): ?>
                                        <tr>
                                            <td>#<?php echo (int) $order["order_id"]; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($order["supplier_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                                <span class="subtext"><?php echo htmlspecialchars($order["phone_number"] ?: "Chưa có SĐT", ENT_QUOTES, "UTF-8"); ?></span>
                                            </td>
                                            <td><?php echo date_time_value($order["expected_date"]); ?></td>
                                            <td><span class="badge red plain"><?php echo days_diff_label($order["expected_date"]); ?></span></td>
                                            <td><?php echo money_format_vnd($order["total_amount"]); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Không có đơn hàng đang muộn.</div>
                    <?php endif; ?>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Xếp hạng nhà cung cấp</h2>
                            <p>Xếp theo số đơn hoàn thành, tỷ lệ đúng hạn và giá trị giao dịch.</p>
                        </div>
                    </div>

                    <?php if (count($supplierRanking) > 0): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nhà cung cấp</th>
                                        <th>HT</th>
                                        <th>Đúng hạn</th>
                                        <th>Muộn</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplierRanking as $supplier): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($supplier["supplier_name"], ENT_QUOTES, "UTF-8"); ?></strong>
                                                <span class="subtext"><?php echo htmlspecialchars($supplier["status"], ENT_QUOTES, "UTF-8"); ?></span>
                                            </td>
                                            <td><?php echo number_value($supplier["completed_orders"] ?? 0); ?></td>
                                            <td><?php echo percent_value($supplier["on_time_rate"] ?? 0); ?></td>
                                            <td class="<?php echo ((int) ($supplier["late_pending_orders"] ?? 0) > 0) ? "danger" : "success"; ?>">
                                                <?php echo number_value($supplier["late_pending_orders"] ?? 0); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Chưa có dữ liệu nhà cung cấp.</div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-grid">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Đơn hàng đang giao</h2>
                            <p>Các đơn ở trạng thái Pending, sắp xếp theo hạn giao gần nhất.</p>
                        </div>
                        <span class="badge orange"><?php echo number_value(count($pendingOrders)); ?> đơn</span>
                    </div>

                    <?php if (count($pendingOrders) > 0): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Nhà cung cấp</th>
                                        <th>Ngày đặt</th>
                                        <th>Hạn giao</th>
                                        <th>Tình trạng</th>
                                        <th>Giá trị</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingOrders as $order): ?>
                                        <?php $isLate = strtotime($order["expected_date"]) < time(); ?>
                                        <tr>
                                            <td>#<?php echo (int) $order["order_id"]; ?></td>
                                            <td><?php echo htmlspecialchars($order["supplier_name"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo date_time_value($order["order_date"]); ?></td>
                                            <td><?php echo date_time_value($order["expected_date"]); ?></td>
                                            <td>
                                                <span class="badge <?php echo $isLate ? "red" : "orange"; ?> plain">
                                                    <?php echo days_diff_label($order["expected_date"]); ?>
                                                </span>
                                            </td>
                                            <td><?php echo money_format_vnd($order["total_amount"]); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Không có đơn hàng đang giao.</div>
                    <?php endif; ?>
                </article>

                <article class="panel" id="completed-orders">
                    <div class="panel-header">
                        <div>
                            <h2>Đơn đã hoàn thành gần đây</h2>
                            <p>Theo dõi các đơn Completed mới nhất.</p>
                        </div>
                        <span class="badge green"><?php echo number_value(count($recentCompletedOrders)); ?> đơn</span>
                    </div>

                    <?php if (count($recentCompletedOrders) > 0): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Nhà cung cấp</th>
                                        <th>Hoàn thành</th>
                                        <th>Đúng hạn</th>
                                        <th>Giá trị</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentCompletedOrders as $order): ?>
                                        <?php
                                        $actual = $order["actual_date"] ?: $order["order_date"];
                                        $isOnTime = strtotime($actual) <= strtotime($order["expected_date"]);
                                        ?>
                                        <tr>
                                            <td>#<?php echo (int) $order["order_id"]; ?></td>
                                            <td><?php echo htmlspecialchars($order["supplier_name"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo date_time_value($actual); ?></td>
                                            <td>
                                                <span class="badge <?php echo $isOnTime ? "green" : "red"; ?> plain">
                                                    <?php echo $isOnTime ? "Đúng hạn" : "Trễ hạn"; ?>
                                                </span>
                                            </td>
                                            <td><?php echo money_format_vnd($order["total_amount"]); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Chưa có đơn hàng hoàn thành.</div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>
</body>
</html>
