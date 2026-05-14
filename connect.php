<?php
// ============================================================================
// connect.php - Tạo kết nối tới MySQL database cho toàn bộ ứng dụng.
// File này được include vào hầu hết các trang qua require_once để dùng chung
// biến $conn (đối tượng mysqli) cho các câu lệnh truy vấn.
// ============================================================================

// Đặt múi giờ mặc định cho PHP là Việt Nam (UTC+7).
// Các hàm liên quan đến thời gian (date(), strtotime(), DateTime,...) sẽ
// dùng múi giờ này, tránh tình trạng giờ bị lệch khi deploy trên server.
date_default_timezone_set("Asia/Ho_Chi_Minh");

// Thông tin kết nối database. Với XAMPP mặc định:
//  - host: localhost
//  - user: root
//  - password: rỗng
//  - database: final_ltweb (cần tạo sẵn trong phpMyAdmin và import file SQL)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "final_ltweb";

// Tạo đối tượng mysqli — thư viện kết nối MySQL hướng đối tượng của PHP.
// Sau khi gọi new mysqli(...), nếu kết nối thành công thì $conn dùng được ngay
// để gọi $conn->query(), $conn->prepare(),...
$conn = new mysqli($servername, $username, $password, $dbname);

// Kiểm tra xem việc kết nối có lỗi không. Thuộc tính connect_error
// sẽ chứa thông báo lỗi nếu có (chuỗi); ngược lại là null.
if ($conn->connect_error) {
    // die() dừng toàn bộ script và in chuỗi ra trình duyệt. Trong môi trường
    // production thực tế ta nên log lỗi rồi hiện thông báo chung, nhưng ở đây
    // (project học tập) thì hiện lỗi luôn để debug nhanh.
    die("Kết nối database thất bại: " . $conn->connect_error);
}

// Đặt bảng mã ký tự là utf8mb4 — hỗ trợ Unicode đầy đủ (kể cả emoji).
// Nếu không set, MySQL có thể trả về tiếng Việt bị lỗi font (mojibake).
$conn->set_charset("utf8mb4");

// Đặt time zone của session MySQL về +07:00 để các hàm NOW(), CURDATE(),...
// trên DB cũng dùng giờ Việt Nam, đồng bộ với date_default_timezone_set ở trên.
$conn->query("SET time_zone = '+07:00'");
