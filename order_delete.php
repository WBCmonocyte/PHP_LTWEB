<?php
// ============================================================================
// order_delete.php - Xử lý xóa 1 đơn hàng. Endpoint này nhận POST từ
// nút "Xóa" trong orders.php và trả về (redirect) lại orders.php kèm flash.
//
// Chú ý: chi tiết đơn (order_details) cần được xóa cùng — thường được lo
// bằng ràng buộc FOREIGN KEY ... ON DELETE CASCADE trong DB. Nếu DB chưa
// đặt CASCADE thì phải DELETE order_details trước.
// ============================================================================

session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

// Chỉ chấp nhận POST. Phương thức GET không nên có side-effect (xóa dữ liệu)
// vì nó có thể bị crawler / prefetch của trình duyệt gọi nhầm.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    flash_set("error", "Yêu cầu không hợp lệ.");
    redirect("orders.php");
}

// Lấy id từ form. Ép (int) để loại bỏ ký tự không phải số → an toàn cho SQL.
$orderId = (int) ($_POST["order_id"] ?? 0);

// Bảo vệ trước trường hợp id rỗng / âm.
if ($orderId <= 0) {
    flash_set("error", "Mã đơn hàng không hợp lệ.");
    redirect("orders.php");
}

// Kiểm tra đơn có tồn tại không (trước khi DELETE) — để báo lỗi rõ ràng
// cho user thay vì âm thầm xóa 0 dòng.
$stmt = $conn->prepare("SELECT order_id FROM purchase_orders WHERE order_id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    flash_set("error", "Không tìm thấy đơn hàng #" . $orderId);
    redirect("orders.php");
}

// Thực hiện xóa. Dùng prepared statement để an toàn — dù đã ép (int) ở trên
// thì đây vẫn là best practice nhất quán.
$stmt = $conn->prepare("DELETE FROM purchase_orders WHERE order_id = ?");
$stmt->bind_param("i", $orderId);

// execute() trả về bool: true nếu OK, false nếu lỗi (ví dụ vướng FK constraint).
if ($stmt->execute()) {
    flash_set("success", "Đã xóa đơn #" . $orderId . ".");
} else {
    // $stmt->error chứa message MySQL trả về — hữu ích khi debug, nhưng
    // trong production có thể chỉ nên hiển thị message chung.
    flash_set("error", "Xóa thất bại: " . $stmt->error);
}

$stmt->close();
// Quay lại danh sách đơn — orders.php sẽ gọi flash_pop() để hiện thông báo.
redirect("orders.php");
