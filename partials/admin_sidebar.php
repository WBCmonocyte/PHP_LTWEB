<?php
// ============================================================================
// partials/admin_sidebar.php - Thanh sidebar dùng chung cho các trang admin
// (dashboard.php, orders.php, order_form.php).
//
// Cách dùng: trang gọi tới set biến $active = "dashboard" | "orders" trước
// khi require file này. Biến $active được dùng để thêm class "active" vào
// đúng menu, tạo hiệu ứng highlight tab hiện tại.
// ============================================================================

// Fallback khi trang gọi không set $active — tránh warning "undefined variable".
$active = $active ?? "";

// Lấy tên đầy đủ và role để hiển thị ở box user phía dưới sidebar.
// ?? "..." là toán tử null-coalescing: dùng giá trị bên phải nếu bên trái null/undefined.
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
        <?php // In class="active" cho link đang ở trang hiện tại để highlight. ?>
        <a class="<?php echo $active === "dashboard" ? "active" : ""; ?>" href="dashboard.php">Tổng quan</a>
        <a class="<?php echo $active === "orders" ? "active" : ""; ?>" href="orders.php">Đơn hàng</a>
        <a href="index.php">Trang chủ công khai</a>
    </nav>

    <div class="user-box">
        <?php // e() để escape HTML, tránh XSS nếu full_name chứa ký tự đặc biệt. ?>
        <strong><?php echo e($fullName); ?></strong>
        <p><?php echo e($role); ?></p>
        <a class="logout" href="logout.php">Đăng xuất</a>
    </div>
</aside>
