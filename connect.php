<?php
date_default_timezone_set("Asia/Ho_Chi_Minh");

$servername = "localhost";
$username = "root";      
$password = "";          // Mật khẩu mặc định của XAMPP để trống
$dbname = "final_ltweb"; // Thay bằng tên database bạn đã tạo trong phpMyAdmin (http://localhost/phpmyadmin)

// Tạo kết nối
$conn = new mysqli($servername, $username, $password, $dbname);

// Kiểm tra kết nối
if ($conn->connect_error) {
    // Nếu lỗi, dừng chương trình và in ra lỗi
    die("Kết nối database thất bại: " . $conn->connect_error); 
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+07:00'");

// Viết các câu truy vấn SQL ở đây...

// Đóng kết nối khi xử lý xong
// $conn->close();
?>