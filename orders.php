<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

$search = trim($_GET["q"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$validStatuses = ["Pending", "Completed", "Cancelled"];

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = "";
}

$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
$types = "";

if ($search !== "") {
    if (ctype_digit($search)) {
        $where[] = "(po.order_id = ? OR s.supplier_name LIKE ?)";
        $params[] = (int) $search;
        $params[] = "%" . $search . "%";
        $types .= "is";
    } else {
        $where[] = "s.supplier_name LIKE ?";
        $params[] = "%" . $search . "%";
        $types .= "s";
    }
}

if ($statusFilter !== "") {
    $where[] = "po.order_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereSql = empty($where) ? "" : "WHERE " . implode(" AND ", $where);

$countSql = "
    SELECT COUNT(*) AS total
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    $whereSql
";

$stmt = $conn->prepare($countSql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$totalRows = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
$stmt->close();

$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT
        po.order_id,
        po.order_date,
        po.expected_date,
        po.actual_date,
        po.total_amount,
        po.order_status,
        s.supplier_name,
        u.username AS admin_name,
        u.full_name AS admin_full_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    INNER JOIN users u ON u.admin_id = po.admin_id
    $whereSql
    ORDER BY po.order_id DESC
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$stmt = $conn->prepare($listSql);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flashes = flash_pop();

$pageTitle = "Đơn hàng";
$active = "orders";

function build_page_url(int $targetPage, string $search, string $status): string
{
    $params = ["page" => $targetPage];

    if ($search !== "") {
        $params["q"] = $search;
    }

    if ($status !== "") {
        $params["status"] = $status;
    }

    return "orders.php?" . http_build_query($params);
}
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
                    <h1>Quản lý đơn hàng</h1>
                    <p>Thêm, sửa, xóa đơn mua hàng và chi tiết vật tư.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="order_form.php">+ Thêm đơn hàng</a>
                </div>
            </section>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?php echo e($flash["type"]); ?>">
                    <?php echo e($flash["message"]); ?>
                </div>
            <?php endforeach; ?>

            <form class="filter-bar" method="get" action="orders.php">
                <div class="form-group">
                    <label for="q">Tìm kiếm</label>
                    <input
                        id="q"
                        name="q"
                        type="text"
                        class="form-control"
                        placeholder="Mã đơn hoặc tên nhà cung cấp..."
                        value="<?php echo e($search); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Trạng thái</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">Tất cả</option>
                        <?php foreach ($validStatuses as $st): ?>
                            <option value="<?php echo e($st); ?>" <?php echo $statusFilter === $st ? "selected" : ""; ?>>
                                <?php echo e(status_label($st)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" type="submit">Lọc</button>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <a class="btn btn-outline" href="orders.php">Xóa lọc</a>
                </div>
            </form>

            <section class="panel">
                <h2>Danh sách đơn hàng</h2>
                <p class="panel-sub">Tìm thấy <?php echo number_value($totalRows); ?> đơn.</p>

                <?php if (count($orders) > 0): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Mã</th>
                                    <th>Nhà cung cấp</th>
                                    <th>Ngày đặt</th>
                                    <th>Hạn giao</th>
                                    <th>Người tạo</th>
                                    <th class="numeric">Tổng tiền</th>
                                    <th>Trạng thái</th>
                                    <th class="actions">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><strong>#<?php echo (int) $order["order_id"]; ?></strong></td>
                                        <td><?php echo e($order["supplier_name"]); ?></td>
                                        <td><?php echo e(date_time_value($order["order_date"])); ?></td>
                                        <td><?php echo e(date_time_value($order["expected_date"])); ?></td>
                                        <td><?php echo e($order["admin_full_name"] ?: $order["admin_name"]); ?></td>
                                        <td class="numeric"><?php echo e(money_format_vnd($order["total_amount"])); ?></td>
                                        <td>
                                            <span class="<?php echo e(status_class($order["order_status"])); ?>">
                                                <?php echo e(status_label($order["order_status"])); ?>
                                            </span>
                                        </td>
                                        <td class="actions">
                                            <a class="btn btn-outline btn-sm" href="order_form.php?id=<?php echo (int) $order["order_id"]; ?>">Sửa</a>
                                            <form
                                                action="order_delete.php"
                                                method="post"
                                                style="display:inline"
                                                onsubmit="return confirm('Bạn có chắc muốn xóa đơn #<?php echo (int) $order["order_id"]; ?>? Hành động này không thể hoàn tác.');"
                                            >
                                                <input type="hidden" name="order_id" value="<?php echo (int) $order["order_id"]; ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">Xóa</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo e(build_page_url($page - 1, $search, $statusFilter)); ?>">&laquo;</a>
                            <?php else: ?>
                                <span class="disabled">&laquo;</span>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php if ($p === $page): ?>
                                    <span class="current"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo e(build_page_url($p, $search, $statusFilter)); ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo e(build_page_url($page + 1, $search, $statusFilter)); ?>">&raquo;</a>
                            <?php else: ?>
                                <span class="disabled">&raquo;</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        Không tìm thấy đơn hàng nào.
                        <?php if ($search !== "" || $statusFilter !== ""): ?>
                            Hãy thử <a href="orders.php">xóa lọc</a>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
