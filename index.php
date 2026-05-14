<?php
// ============================================================================
// index.php - Trang chủ công khai (KHÔNG cần đăng nhập). Hiển thị các con số
// thống kê tổng quan đẹp mắt nhằm mục đích "marketing" hệ thống. Người dùng
// thấy thú vị → đăng nhập để vào dashboard quản trị chi tiết.
//
// Logic chính:
//   * Nếu đã đăng nhập (qua session/cookie): hiện nút "Vào dashboard" và "Đăng xuất"
//   * Nếu chưa: hiện CTA "Đăng nhập để quản lý"
//   * Luôn hiển thị các con số thống kê công khai (không lộ chi tiết tiền nong, đơn cụ thể)
// ============================================================================

session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

// Nếu user có cookie remember-me → tự đăng nhập lại. KHÔNG ép buộc đăng nhập
// (đây là trang công khai), chỉ để có thông tin đẹp ở nav.
restore_remembered_login($conn);

// Cờ + tên hiển thị ở nav (nếu đã đăng nhập).
$isLoggedIn = isset($_SESSION["user_id"]);
$fullName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "";

// ---- Query 1: Đếm nhà cung cấp ----
$supplierStats = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_suppliers,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active_suppliers
    FROM suppliers
");

// ---- Query 2: Đếm loại vật tư ----
$materialStats = fetch_one($conn, "
    SELECT COUNT(*) AS total_materials
    FROM materials
");

// ---- Query 3: Thống kê đơn hàng theo trạng thái ----
$orderStats = fetch_one($conn, "
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN order_status = 'Pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN order_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
    FROM purchase_orders
");

// ---- Query 4: Tỷ lệ đơn giao đúng hạn toàn hệ thống ----
// Bảo vệ chia cho 0 bằng CASE — nếu chưa có đơn Completed nào, trả về 0%.
$onTimeStats = fetch_one($conn, "
    SELECT
        CASE
            WHEN SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) = 0 THEN 0
            ELSE ROUND(
                SUM(CASE WHEN order_status = 'Completed' AND actual_date IS NOT NULL AND actual_date <= expected_date THEN 1 ELSE 0 END)
                / SUM(CASE WHEN order_status = 'Completed' THEN 1 ELSE 0 END) * 100,
                1
            )
        END AS on_time_rate
    FROM purchase_orders
");

// ---- Query 5: Top 5 NCC có nhiều đơn hoàn thành nhất ----
// HAVING completed_orders > 0: chỉ tính NCC đã từng có ít nhất 1 đơn hoàn thành
// (loại NCC chưa hoạt động ra khỏi top).
$topSuppliers = fetch_all($conn, "
    SELECT
        s.supplier_name,
        COUNT(po.order_id) AS total_orders,
        SUM(CASE WHEN po.order_status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id = s.supplier_id
    WHERE s.status = 'Active'
    GROUP BY s.supplier_id, s.supplier_name
    HAVING completed_orders > 0
    ORDER BY completed_orders DESC, total_orders DESC
    LIMIT 5
");

// ---- Query 6: Top 5 vật tư phổ biến (đặt nhiều SL nhất) ----
// LEFT JOIN order_details để bao gồm cả vật tư chưa từng đặt (sẽ có SL = 0).
// COUNT(DISTINCT order_id): mỗi vật tư xuất hiện trong bao nhiêu đơn khác nhau.
$popularMaterials = fetch_all($conn, "
    SELECT
        m.material_name,
        m.unit,
        COALESCE(SUM(od.quantity), 0) AS total_quantity,
        COUNT(DISTINCT od.order_id) AS order_count
    FROM materials m
    LEFT JOIN order_details od ON od.material_id = m.material_id
    GROUP BY m.material_id, m.material_name, m.unit
    ORDER BY total_quantity DESC, order_count DESC
    LIMIT 5
");

// ---- Tính tỷ lệ % cho thanh progress bar ----
// Ép (int) để chắc chắn kiểu số. ?? 0 phòng query lỗi.
$totalOrders = (int) ($orderStats["total_orders"] ?? 0);
$completedOrders = (int) ($orderStats["completed_orders"] ?? 0);
$pendingOrders = (int) ($orderStats["pending_orders"] ?? 0);
$cancelledOrders = (int) ($orderStats["cancelled_orders"] ?? 0);

// Tính tỷ lệ %. Nếu totalOrders = 0 thì gán 0 để tránh chia cho 0.
// round(..., 1) làm tròn 1 chữ số thập phân, ví dụ 33.33333 → 33.3.
$completedRate = $totalOrders > 0 ? round($completedOrders / $totalOrders * 100, 1) : 0;
$pendingRate = $totalOrders > 0 ? round($pendingOrders / $totalOrders * 100, 1) : 0;
$cancelledRate = $totalOrders > 0 ? round($cancelledOrders / $totalOrders * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Center - Thống kê tổng quan</title>
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

        header.navbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 32px;
            background: rgba(255, 255, 255, 0.95);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(8px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            font-size: 18px;
            color: var(--dark);
        }

        .brand-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), #06b6d4);
            color: #ffffff;
            font-weight: 800;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s, border-color 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-outline:hover {
            background: rgba(79, 70, 229, 0.08);
        }

        .hero {
            padding: 60px 32px 30px;
            text-align: center;
            background: linear-gradient(135deg, #eef2ff, #f0fdfa);
        }

        .hero h1 {
            margin-bottom: 14px;
            font-size: 40px;
            letter-spacing: -0.03em;
            color: var(--dark);
        }

        .hero p {
            max-width: 680px;
            margin: 0 auto 26px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 16px;
        }

        .hero-cta {
            display: inline-flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 30px 32px 60px;
        }

        .section-title {
            margin-bottom: 18px;
        }

        .section-title h2 {
            margin-bottom: 6px;
            font-size: 22px;
            letter-spacing: -0.02em;
        }

        .section-title p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 34px;
        }

        .stat-card,
        .panel {
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--surface);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.05);
        }

        .stat-card {
            padding: 20px;
        }

        .stat-card .label {
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-card .value {
            margin-bottom: 6px;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .stat-card .hint {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .success { color: var(--success); }
        .warning { color: var(--warning); }
        .danger { color: var(--danger); }
        .info { color: var(--info); }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .panel {
            padding: 22px;
        }

        .panel h3 {
            margin-bottom: 4px;
            font-size: 18px;
        }

        .panel .panel-sub {
            margin-bottom: 18px;
            color: var(--muted);
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        td.numeric, th.numeric {
            text-align: right;
        }

        .progress-row {
            margin-bottom: 16px;
        }

        .progress-row:last-child {
            margin-bottom: 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 700;
        }

        .progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #f1f5f9;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 999px;
        }

        .fill-green { background: linear-gradient(90deg, #16a34a, #22c55e); }
        .fill-orange { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .fill-red { background: linear-gradient(90deg, #dc2626, #ef4444); }

        .cta-banner {
            margin-top: 12px;
            padding: 28px;
            border-radius: 22px;
            background: linear-gradient(135deg, var(--primary), #06b6d4);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .cta-banner h3 {
            margin-bottom: 6px;
            font-size: 22px;
        }

        .cta-banner p {
            opacity: 0.9;
            line-height: 1.5;
            font-size: 14px;
        }

        .cta-banner .btn {
            background: #ffffff;
            color: var(--primary);
        }

        .cta-banner .btn:hover {
            background: #f8fafc;
        }

        footer {
            padding: 24px 32px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            border-top: 1px solid var(--border);
            background: var(--surface);
        }

        .empty-state {
            padding: 20px;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            background: #f8fafc;
            color: var(--muted);
            text-align: center;
            font-size: 14px;
        }

        @media (max-width: 980px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .hero h1 {
                font-size: 30px;
            }
        }

        @media (max-width: 560px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            header.navbar,
            .hero,
            .container {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>
</head>
<body>
    <header class="navbar">
        <a class="brand" href="index.php">
            <span class="brand-logo">QL</span>
            Quản lý nhà cung cấp
        </a>
        <div class="nav-actions">
            <?php
            // Nav khác nhau tùy trạng thái đăng nhập:
            //   - Đã đăng nhập: lời chào + link dashboard + đăng xuất
            //   - Chưa: nút đăng nhập
            if ($isLoggedIn):
            ?>
                <span style="color: var(--muted); font-size: 14px;">Xin chào, <strong><?php echo e($fullName); ?></strong></span>
                <a class="btn btn-primary" href="dashboard.php">Vào dashboard</a>
                <a class="btn btn-outline" href="logout.php">Đăng xuất</a>
            <?php else: ?>
                <a class="btn btn-primary" href="login.php">Đăng nhập</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="hero">
        <h1>Quản lý nhà cung cấp &amp; đơn mua thông minh</h1>
        <p>Theo dõi nhà cung cấp, vật tư và tiến độ giao hàng theo thời gian thực. Dữ liệu thống kê cơ bản được công khai bên dưới — đăng nhập để xem chi tiết đầy đủ.</p>
        <div class="hero-cta">
            <?php // CTA chính của hero — đổi text theo trạng thái đăng nhập. ?>
            <?php if ($isLoggedIn): ?>
                <a class="btn btn-primary" href="dashboard.php">Mở dashboard</a>
            <?php else: ?>
                <a class="btn btn-primary" href="login.php">Đăng nhập để quản lý</a>
                <a class="btn btn-outline" href="#stats">Xem thống kê</a>
            <?php endif; ?>
        </div>
    </section>

    <main class="container" id="stats">
        <div class="section-title">
            <h2>Thống kê tổng quan</h2>
            <p>Cập nhật trực tiếp từ hệ thống — không cần đăng nhập.</p>
        </div>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="label">Nhà cung cấp</div>
                <div class="value"><?php echo number_value($supplierStats["total_suppliers"] ?? 0); ?></div>
                <div class="hint">
                    <span class="success"><?php echo number_value($supplierStats["active_suppliers"] ?? 0); ?> đang hoạt động</span>
                </div>
            </article>

            <article class="stat-card">
                <div class="label">Loại vật tư</div>
                <div class="value info"><?php echo number_value($materialStats["total_materials"] ?? 0); ?></div>
                <div class="hint">Tổng số vật tư trong danh mục.</div>
            </article>

            <article class="stat-card">
                <div class="label">Tổng đơn hàng</div>
                <div class="value"><?php echo number_value($totalOrders); ?></div>
                <div class="hint">
                    <?php echo number_value($completedOrders); ?> hoàn thành ·
                    <?php echo number_value($pendingOrders); ?> đang giao
                </div>
            </article>

            <article class="stat-card">
                <div class="label">Tỷ lệ giao đúng hạn</div>
                <div class="value success"><?php echo percent_value($onTimeStats["on_time_rate"] ?? 0); ?></div>
                <div class="hint">Trên các đơn đã hoàn thành.</div>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel">
                <h3>Phân bổ trạng thái đơn hàng</h3>
                <p class="panel-sub">Tỷ lệ đơn theo trạng thái trên tổng <?php echo number_value($totalOrders); ?> đơn.</p>

                <?php
                // Có ít nhất 1 đơn → vẽ 3 progress bar (HT / Đang giao / Đã hủy).
                // Mỗi bar dùng width inline = %, tô màu khác nhau bằng class fill-*.
                if ($totalOrders > 0):
                ?>
                    <div class="progress-row">
                        <div class="progress-label">
                            <span>Hoàn thành</span>
                            <span class="success"><?php echo number_value($completedOrders); ?> · <?php echo percent_value($completedRate); ?></span>
                        </div>
                        <div class="progress-bar">
                            <?php // width: % tỷ lệ hoàn thành, ví dụ "55.5%". ?>
                            <div class="progress-fill fill-green" style="width: <?php echo $completedRate; ?>%"></div>
                        </div>
                    </div>

                    <div class="progress-row">
                        <div class="progress-label">
                            <span>Đang giao</span>
                            <span class="warning"><?php echo number_value($pendingOrders); ?> · <?php echo percent_value($pendingRate); ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill fill-orange" style="width: <?php echo $pendingRate; ?>%"></div>
                        </div>
                    </div>

                    <div class="progress-row">
                        <div class="progress-label">
                            <span>Đã hủy</span>
                            <span class="danger"><?php echo number_value($cancelledOrders); ?> · <?php echo percent_value($cancelledRate); ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill fill-red" style="width: <?php echo $cancelledRate; ?>%"></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Chưa có đơn hàng nào trong hệ thống.</div>
                <?php endif; ?>
            </article>

            <article class="panel">
                <h3>Top nhà cung cấp</h3>
                <p class="panel-sub">5 nhà cung cấp có nhiều đơn hoàn thành nhất.</p>

                <?php if (count($topSuppliers) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nhà cung cấp</th>
                                <th class="numeric">Đơn HT</th>
                                <th class="numeric">Tổng đơn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // foreach kèm $i => $sup: $i là index 0-based,
                            // ta cộng 1 để in số thứ tự dễ đọc cho user (1, 2, 3,...).
                            foreach ($topSuppliers as $i => $sup):
                            ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><strong><?php echo e($sup["supplier_name"]); ?></strong></td>
                                    <td class="numeric success"><?php echo number_value($sup["completed_orders"]); ?></td>
                                    <td class="numeric"><?php echo number_value($sup["total_orders"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">Chưa có nhà cung cấp nào có đơn hoàn thành.</div>
                <?php endif; ?>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel">
                <h3>Vật tư phổ biến</h3>
                <p class="panel-sub">5 vật tư được đặt hàng nhiều nhất (theo số lượng).</p>

                <?php if (count($popularMaterials) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên vật tư</th>
                                <th>ĐVT</th>
                                <th class="numeric">SL đặt</th>
                                <th class="numeric">Số đơn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Liệt kê 5 vật tư đặt nhiều nhất theo tổng số lượng.
                            // ĐVT: "kg", "cái", "thùng",... lấy từ cột m.unit.
                            foreach ($popularMaterials as $i => $mat):
                            ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><strong><?php echo e($mat["material_name"]); ?></strong></td>
                                    <td><?php echo e($mat["unit"]); ?></td>
                                    <td class="numeric"><?php echo number_value($mat["total_quantity"]); ?></td>
                                    <td class="numeric"><?php echo number_value($mat["order_count"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">Chưa có vật tư nào được đặt hàng.</div>
                <?php endif; ?>
            </article>

            <article class="panel">
                <h3>Tình hình hiện tại</h3>
                <p class="panel-sub">Một số chỉ số nhanh.</p>

                <div style="display: grid; gap: 14px;">
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border);">
                        <span style="color: var(--muted);">Đơn đang chờ xử lý</span>
                        <strong class="warning"><?php echo number_value($pendingOrders); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border);">
                        <span style="color: var(--muted);">Đơn đã hoàn thành</span>
                        <strong class="success"><?php echo number_value($completedOrders); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border);">
                        <span style="color: var(--muted);">Đơn đã hủy</span>
                        <strong class="danger"><?php echo number_value($cancelledOrders); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0;">
                        <span style="color: var(--muted);">NCC đang hoạt động</span>
                        <strong class="info"><?php echo number_value($supplierStats["active_suppliers"] ?? 0); ?></strong>
                    </div>
                </div>
            </article>
        </section>

        <?php // Banner CTA chỉ hiện cho khách chưa đăng nhập — vừa quảng cáo, vừa nhắc đăng nhập. ?>
        <?php if (!$isLoggedIn): ?>
            <section class="cta-banner">
                <div>
                    <h3>Bạn cần xem chi tiết đơn hàng và giá trị giao dịch?</h3>
                    <p>Đăng nhập để truy cập dashboard quản trị: chi tiết đơn hàng, doanh thu, NCC tốt nhất, đơn trễ hạn…</p>
                </div>
                <a class="btn" href="login.php">Đăng nhập ngay</a>
            </section>
        <?php endif; ?>
    </main>

    <footer>
        <?php // date("Y") in năm hiện tại (4 chữ số) — copyright tự cập nhật theo năm. ?>
        &copy; <?php echo date("Y"); ?> Trang quản lý nhà cung cấp công khai.
    </footer>
</body>
</html>
