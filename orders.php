<?php
// ============================================================================
// orders.php - Trang danh sách đơn hàng (admin only).
// Hỗ trợ: tìm kiếm theo mã đơn / tên nhà cung cấp, lọc theo trạng thái,
// phân trang 10 đơn/trang. Mỗi đơn có nút Sửa và Xóa.
// ============================================================================

session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

// Tự đăng nhập lại nếu có cookie remember-me, rồi yêu cầu phải đăng nhập.
restore_remembered_login($conn);
require_login();

// ---- 1. Đọc tham số filter từ query string (?q=...&status=...&page=...) ----

// Từ khóa tìm kiếm. trim() để bỏ khoảng trắng dư hai đầu.
$search = trim($_GET["q"] ?? "");

// Trạng thái lọc — chỉ chấp nhận giá trị nằm trong whitelist bên dưới,
// nếu không sẽ reset rỗng. Đây là cách an toàn (tránh user gõ random vào URL).
$statusFilter = $_GET["status"] ?? "";
$validStatuses = ["Pending", "Completed", "Cancelled"];

// in_array(..., $validStatuses, true): tham số thứ 3 là strict, so sánh
// kiểu (===) thay vì lỏng (==) → chính xác hơn.
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = "";
}

// ---- 2. Phân trang ----

// max(1, ...): bảo đảm page luôn ≥ 1 (kể cả khi user gõ ?page=-5).
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// ---- 3. Dựng câu WHERE động ----
// Ý tưởng: tích lũy các điều kiện vào mảng $where, song song giữ $params và
// $types để dùng cho prepared statement. Cách này tránh nối chuỗi giá trị
// vào SQL → chống SQL injection.

$where = [];
$params = [];
$types = ""; // chuỗi kiểu, ví dụ "is" = integer + string

if ($search !== "") {
    // Nếu search chỉ gồm số (digit) → có thể là MÃ ĐƠN → tìm theo cả hai
    // (order_id chính xác HOẶC supplier_name chứa chuỗi).
    if (ctype_digit($search)) {
        $where[] = "(po.order_id = ? OR s.supplier_name LIKE ?)";
        $params[] = (int) $search;
        $params[] = "%" . $search . "%";
        $types .= "is";
    } else {
        // Search có chữ → chắc chắn không phải mã, chỉ tìm theo tên NCC.
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

// Ghép các điều kiện thành chuỗi "WHERE cond1 AND cond2 AND ..." (hoặc rỗng).
$whereSql = empty($where) ? "" : "WHERE " . implode(" AND ", $where);

// ---- 4. Đếm tổng số bản ghi (để tính số trang) ----

$countSql = "
    SELECT COUNT(*) AS total
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
    $whereSql
";

$stmt = $conn->prepare($countSql);

// Nếu có tham số filter thì bind. Toán tử ... (spread) trải mảng thành
// danh sách tham số, vì bind_param yêu cầu nhiều đối số rời rạc.
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$totalRows = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
$stmt->close();

// Số trang = ceil(tổng / 10), tối thiểu 1 (để vẫn hiển thị "trang 1" khi rỗng).
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Nếu user request 1 trang ngoài phạm vi (ví dụ ?page=999) → kéo về trang cuối.
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// ---- 5. Lấy danh sách đơn hàng cho trang hiện tại ----
// JOIN 3 bảng: purchase_orders + suppliers + users (để hiển thị tên người tạo).
// ORDER BY order_id DESC: đơn mới nhất lên đầu.

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

// Câu list cần thêm 2 tham số nữa (perPage và offset) so với câu count, nên
// ta clone $params/$types rồi append "ii".
$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;

$stmt = $conn->prepare($listSql);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Lấy thông báo flash (nếu vừa redirect từ order_form/order_delete sang) rồi
// xóa khỏi session → chỉ hiện 1 lần.
$flashes = flash_pop();

// Biến cho phần view (HTML).
$pageTitle = "Đơn hàng";
// Báo cho sidebar partial biết menu nào cần highlight.
$active = "orders";

/**
 * Hàm tiện ích chỉ dùng nội bộ trong orders.php để build URL phân trang,
 * giữ nguyên các filter hiện tại. Ví dụ:
 *   build_page_url(2, "abc", "Pending") → "orders.php?page=2&q=abc&status=Pending"
 *
 * http_build_query() tự lo việc URL-encode các giá trị (dấu cách thành %20,...).
 */
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

            <?php
            // Hiển thị các flash message (success / error) từ những lần redirect trước.
            // Class "alert-success" / "alert-error" được style sẵn trong admin.css.
            foreach ($flashes as $flash):
            ?>
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
                        <?php
                        // Vòng lặp render mỗi trạng thái thành 1 <option>.
                        // selected: giữ lại lựa chọn hiện tại sau khi submit form.
                        foreach ($validStatuses as $st):
                        ?>
                            <option value="<?php echo e($st); ?>" <?php echo $statusFilter === $st ? "selected" : ""; ?>>
                                <?php // status_label() đổi "Pending" -> "Đang giao",... ?>
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
                <?php // In tổng số đơn tìm thấy (đã áp dụng filter). ?>
                <p class="panel-sub">Tìm thấy <?php echo number_value($totalRows); ?> đơn.</p>

                <?php // Nếu có ít nhất 1 đơn → hiện bảng, ngược lại hiện empty state ở dưới. ?>
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
                                <?php
                                // Vòng lặp render mỗi đơn hàng thành 1 hàng <tr>.
                                // Ép (int) cho order_id để bảo đảm in ra số nguyên (vừa an toàn
                                // vừa rõ ràng, không cần thêm e() escape).
                                foreach ($orders as $order):
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo (int) $order["order_id"]; ?></strong></td>
                                        <td><?php echo e($order["supplier_name"]); ?></td>
                                        <td><?php echo e(date_time_value($order["order_date"])); ?></td>
                                        <td><?php echo e(date_time_value($order["expected_date"])); ?></td>
                                        <td><?php // Ưu tiên full_name; nếu rỗng → fallback sang username. ?>
                                            <?php echo e($order["admin_full_name"] ?: $order["admin_name"]); ?></td>
                                        <td class="numeric"><?php echo e(money_format_vnd($order["total_amount"])); ?></td>
                                        <td>
                                            <?php // status_class trả về tên class CSS để badge có màu phù hợp với trạng thái. ?>
                                            <span class="<?php echo e(status_class($order["order_status"])); ?>">
                                                <?php echo e(status_label($order["order_status"])); ?>
                                            </span>
                                        </td>
                                        <td class="actions">
                                            <?php // Link Sửa: chuyển sang form, kèm id trong query string. ?>
                                            <a class="btn btn-outline btn-sm" href="order_form.php?id=<?php echo (int) $order["order_id"]; ?>">Sửa</a>
                                            <?php // Form Xóa: dùng POST + confirm() để chống vô tình bấm + chống CSRF cơ bản. ?>
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

                    <?php // Chỉ hiện thanh phân trang khi có hơn 1 trang. ?>
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php // Nút "Prev". Nếu đang ở trang 1 thì hiện text mờ (disabled). ?>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo e(build_page_url($page - 1, $search, $statusFilter)); ?>">&laquo;</a>
                            <?php else: ?>
                                <span class="disabled">&laquo;</span>
                            <?php endif; ?>

                            <?php // Render số trang 1..N. Trang hiện tại in dạng span "current" không bấm được. ?>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php if ($p === $page): ?>
                                    <span class="current"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo e(build_page_url($p, $search, $statusFilter)); ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php // Nút "Next". Nếu đã ở trang cuối thì disabled. ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo e(build_page_url($page + 1, $search, $statusFilter)); ?>">&raquo;</a>
                            <?php else: ?>
                                <span class="disabled">&raquo;</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php // Không có đơn nào → empty state. Nếu có filter thì gợi ý xóa filter. ?>
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
