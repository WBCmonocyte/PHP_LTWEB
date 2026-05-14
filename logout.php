<?php
// ============================================================================
// logout.php - Đăng xuất người dùng:
//   1. Xóa cookie remember-me trên trình duyệt.
//   2. Xóa toàn bộ dữ liệu trong session ($_SESSION).
//   3. Hủy session ID.
//   4. Chuyển hướng về trang chủ công khai.
// ============================================================================

// Khởi động session để các hàm session_unset/destroy hoạt động.
session_start();

// Cần auth.php để dùng clear_remember_cookie().
require_once __DIR__ . "/auth.php";

// Xóa cookie remember-me — tránh việc lần truy cập sau lại tự đăng nhập lại.
clear_remember_cookie();

// session_unset(): xóa tất cả biến trong $_SESSION (nhưng session vẫn tồn tại).
session_unset();

// session_destroy(): hủy luôn session trên server.
// Kết hợp 2 lệnh trên đảm bảo cả dữ liệu lẫn ID session đều bị xóa sạch.
session_destroy();

// Sau khi đăng xuất, đưa user về trang chủ công khai (index.php).
header("Location: index.php");
exit();
