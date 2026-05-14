<?php
date_default_timezone_set("Asia/Ho_Chi_Minh");

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "final_ltweb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Kết nối database thất bại: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+07:00'");
