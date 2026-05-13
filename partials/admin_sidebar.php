<?php
$active = $active ?? "";
$fullName = $_SESSION["full_name"] ?? $_SESSION["username"] ?? "Quản trị viên";
$role = $_SESSION["role"] ?? "Admin";
?>
<aside class="sidebar">
    <div class="brand">
        <span class="logo">SC</span>
        <h2>Supplier Center</h2>
        <p>Quản lý nhà cung cấp &amp; đơn mua.</p>
    </div>

    <nav class="nav">
        <a class="<?php echo $active === "dashboard" ? "active" : ""; ?>" href="dashboard.php">Tổng quan</a>
        <a class="<?php echo $active === "orders" ? "active" : ""; ?>" href="orders.php">Đơn hàng</a>
        <a href="index.php">Trang chủ công khai</a>
    </nav>

    <div class="user-box">
        <strong><?php echo e($fullName); ?></strong>
        <p><?php echo e($role); ?></p>
        <a class="logout" href="logout.php">Đăng xuất</a>
    </div>
</aside>
