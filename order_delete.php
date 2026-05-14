<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

restore_remembered_login($conn);
require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    flash_set("error", "Yêu cầu không hợp lệ.");
    redirect("orders.php");
}

$orderId = (int) ($_POST["order_id"] ?? 0);

if ($orderId <= 0) {
    flash_set("error", "Mã đơn hàng không hợp lệ.");
    redirect("orders.php");
}

$stmt = $conn->prepare("SELECT order_id FROM purchase_orders WHERE order_id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    flash_set("error", "Không tìm thấy đơn hàng #" . $orderId);
    redirect("orders.php");
}

$stmt = $conn->prepare("DELETE FROM purchase_orders WHERE order_id = ?");
$stmt->bind_param("i", $orderId);

if ($stmt->execute()) {
    flash_set("success", "Đã xóa đơn #" . $orderId . ".");
} else {
    flash_set("error", "Xóa thất bại: " . $stmt->error);
}

$stmt->close();
redirect("orders.php");
