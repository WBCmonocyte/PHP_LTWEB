<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

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

$pageTitle = "Dashboard";
$active = "dashboard";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - Supplier Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="layout">
        <?php require __DIR__ . "/partials/admin_sidebar.php"; ?>

        <main class="main">
            <section class="topbar">
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
                    <div class="value success"><?php echo e(money_format_vnd($orderStats["completed_revenue"] ?? 0)); ?></div>
                    <div class="hint"><?php echo number_value($orderStats["completed_orders"] ?? 0); ?> đơn đã hoàn thành.</div>
                </article>
            </section>

            <section class="content-grid">
                <article class="hero-card">
                    <div class="hero-title">
                        <h2>Nhà cung cấp tốt nhất</h2>
                        <span class="badge green">Best supplier</span>
                    </div>

                    <?php if (!empty($bestSupplier) && (int) ($bestSupplier["supplier_id"] ?? 0) > 0): ?>
                        <div>
                            <div class="best-name"><?php echo e($bestSupplier["supplier_name"]); ?></div>
                            <div class="best-meta">
                                <span>Email: <?php echo e($bestSupplier["contact_email"] ?: "Chưa cập nhật"); ?></span>
                                <span>Điện thoại: <?php echo e($bestSupplier["phone_number"] ?: "Chưa cập nhật"); ?></span>
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
                                <strong><?php echo e(money_format_vnd($bestSupplier["completed_revenue"] ?? 0)); ?></strong>
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
                            <p><?php echo e(money_format_vnd($completionStats["revenue_today"] ?? 0)); ?></p>
                        </div>
                        <div class="completion-card">
                            <span>Tháng này</span>
                            <strong><?php echo number_value($completionStats["completed_month"] ?? 0); ?></strong>
                            <p><?php echo e(money_format_vnd($completionStats["revenue_month"] ?? 0)); ?></p>
                        </div>
                        <div class="completion-card">
                            <span>Năm này</span>
                            <strong><?php echo number_value($completionStats["completed_year"] ?? 0); ?></strong>
                            <p><?php echo e(money_format_vnd($completionStats["revenue_year"] ?? 0)); ?></p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="content-grid">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Nhà cung cấp đang muộn</h2>
                            <p>Các đơn Pending đã vượt quá ngày giao dự kiến.</p>
                        </div>
                        <span class="badge red"><?php echo number_value(count($lateOrders)); ?> đơn</span>
                    </div>

                    <?php if (count($lateOrders) > 0): ?>
                        <div class="table-wrap">
                            <table class="table">
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
                                                <strong><?php echo e($order["supplier_name"]); ?></strong>
                                                <span class="subtext"><?php echo e($order["phone_number"] ?: "Chưa có SĐT"); ?></span>
                                            </td>
                                            <td><?php echo e(date_time_value($order["expected_date"])); ?></td>
                                            <td><span class="badge red plain"><?php echo e(days_diff_label($order["expected_date"])); ?></span></td>
                                            <td><?php echo e(money_format_vnd($order["total_amount"])); ?></td>
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
                            <table class="table">
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
                                                <strong><?php echo e($supplier["supplier_name"]); ?></strong>
                                                <span class="subtext"><?php echo e($supplier["status"]); ?></span>
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
                            <table class="table">
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
                                            <td><?php echo e($order["supplier_name"]); ?></td>
                                            <td><?php echo e(date_time_value($order["order_date"])); ?></td>
                                            <td><?php echo e(date_time_value($order["expected_date"])); ?></td>
                                            <td>
                                                <span class="badge <?php echo $isLate ? "red" : "orange"; ?> plain">
                                                    <?php echo e(days_diff_label($order["expected_date"])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo e(money_format_vnd($order["total_amount"])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Không có đơn hàng đang giao.</div>
                    <?php endif; ?>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Đơn đã hoàn thành gần đây</h2>
                            <p>Theo dõi các đơn Completed mới nhất.</p>
                        </div>
                        <span class="badge green"><?php echo number_value(count($recentCompletedOrders)); ?> đơn</span>
                    </div>

                    <?php if (count($recentCompletedOrders) > 0): ?>
                        <div class="table-wrap">
                            <table class="table">
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
                                            <td><?php echo e($order["supplier_name"]); ?></td>
                                            <td><?php echo e(date_time_value($actual)); ?></td>
                                            <td>
                                                <span class="badge <?php echo $isOnTime ? "green" : "red"; ?> plain">
                                                    <?php echo $isOnTime ? "Đúng hạn" : "Trễ hạn"; ?>
                                                </span>
                                            </td>
                                            <td><?php echo e(money_format_vnd($order["total_amount"])); ?></td>
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
