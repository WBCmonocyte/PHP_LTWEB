<?php
// ============================================================================
// login.php - Trang đăng nhập. Xử lý 2 luồng:
//   1. GET: hiển thị form login (cùng ô "Ghi nhớ đăng nhập").
//   2. POST: nhận username/password, kiểm tra với DB, đăng nhập thành công
//      thì set session (+ remember cookie nếu được chọn) rồi chuyển hướng
//      sang dashboard.
// ============================================================================

// Phải gọi session_start() TRƯỚC khi có bất kỳ output nào ra trình duyệt.
// Nếu không, $_SESSION không hoạt động và header() sẽ báo lỗi
// "headers already sent".
session_start();

// Nạp 3 file dùng chung: kết nối DB, auth, helpers.
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

// Cố gắng đăng nhập tự động từ cookie remember-me (nếu có).
// Nếu thành công, hàm này sẽ set $_SESSION["user_id"] luôn.
restore_remembered_login($conn);

// Nếu đã đăng nhập rồi (qua session hoặc remember cookie) thì không cần
// xem form đăng nhập nữa — chuyển thẳng tới dashboard.
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit();
}

// Khởi tạo các biến hiển thị form. Khi load lần đầu (GET) chúng đều rỗng.
// Khi POST sai → giữ lại username và trạng thái checkbox remember để
// user không phải gõ lại từ đầu.
$error = "";
$username = "";
$remember = false;

// Chỉ xử lý đăng nhập khi form được SUBMIT (REQUEST_METHOD = POST).
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // trim() bỏ khoảng trắng 2 đầu để tránh user gõ thừa " admin " thành
    // tên khác. Operator ?? "" để khỏi warning nếu key không tồn tại.
    $username = trim($_POST["username"] ?? "");

    // Không trim password — vì có thể password thực sự có khoảng trắng đầu/cuối.
    $password = $_POST["password"] ?? "";

    // isset() trả về true nếu checkbox được tick (HTML chỉ gửi key nếu tick).
    $remember = isset($_POST["remember"]);

    // Validate: cả hai trường đều phải có giá trị.
    if ($username === "" || $password === "") {
        $error = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.";
    } else {
        // Truy DB tìm user. Dùng prepared statement để chống SQL injection
        // (nếu nối chuỗi trực tiếp, attacker gõ ' OR 1=1 -- là login được luôn).
        $stmt = $conn->prepare("SELECT admin_id AS id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
        // "s" = kiểu string; ? sẽ được thay an toàn bằng $username.
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Kiểm tra mật khẩu:
        //   - password_verify(): so sánh với hash bcrypt (cách chuẩn, an toàn).
        //   - hash_equals(): fallback so sánh trực tiếp string — chỉ để hỗ
        //     trợ dữ liệu cũ trong DB chưa được hash. Trong project mới
        //     hoàn toàn nên chỉ dùng password_verify.
        if ($user && (password_verify($password, $user["password"]) || hash_equals($user["password"], $password))) {
            // Lưu session: từ đây user đã được coi là đăng nhập.
            login_user($user);

            // Nếu user tick "Ghi nhớ", set cookie 30 ngày để tự đăng nhập lại;
            // ngược lại, xóa cookie cũ (nếu trước đó có).
            if ($remember) {
                set_remember_cookie($user);
            } else {
                clear_remember_cookie();
            }

            // Đăng nhập xong → vào trang quản trị.
            header("Location: dashboard.php");
            exit();
        }

        // Sai user hoặc sai mật khẩu — KHÔNG nói rõ "sai user" hay "sai pass"
        // để tránh attacker dò từng username có tồn tại trong hệ thống.
        $error = "Tên đăng nhập hoặc mật khẩu không đúng.";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            font-family: 'Be Vietnam Pro', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            color: #1f2937;
            font-size: 15px;
            line-height: 1.5;
        }

        input,
        button,
        select,
        textarea {
            font-family: inherit;
            font-size: inherit;
            color: inherit;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 24px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 16px;
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            opacity: 0.9;
        }

        .back-link:hover {
            opacity: 1;
            text-decoration: underline;
        }

        .login-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
        }

        .login-card h1 {
            margin-bottom: 8px;
            font-size: 30px;
            text-align: center;
            color: #111827;
        }

        .login-card > p {
            margin-bottom: 28px;
            text-align: center;
            color: #6b7280;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus {
            border-color: #4f46e5;
            outline: none;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4b5563;
        }

        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 14px;
            line-height: 1.5;
        }

        a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
        }

        a:hover {
            text-decoration: underline;
        }

        .login-button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: #4f46e5;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
        }

        .login-button:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }

        .signup-text {
            margin-top: 24px;
            text-align: center;
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <main class="login-wrapper">
        <a class="back-link" href="index.php">&larr; Quay lại trang chủ</a>
        <section class="login-card">
            <h1>Đăng nhập</h1>
            <p>Chào mừng bạn quay lại. Vui lòng nhập thông tin để tiếp tục.</p>

            <?php
            // Chỉ hiển thị box báo lỗi nếu có lỗi (sau khi POST validate fail).
            // e() escape HTML để chặn XSS — đề phòng kẻ tấn công nhồi script
            // vào ô username.
            if ($error !== ""):
            ?>
                <div class="alert"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form action="" method="post">
                <div class="form-group">
                    <label for="username">Tên đăng nhập</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Nhập tên đăng nhập"
                        <?php // Giữ lại username user vừa gõ khi POST sai, để khỏi gõ lại. ?>
                        value="<?php echo e($username); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Mật khẩu</label>
                    <input type="password" id="password" name="password" placeholder="Nhập mật khẩu" required>
                </div>

                <div class="form-options">
                    <label class="remember">
                        <?php // In thuộc tính "checked" nếu trước đó user đã tick, để khôi phục trạng thái sau POST. ?>
                        <input type="checkbox" name="remember" <?php echo $remember ? "checked" : ""; ?>>
                        Ghi nhớ đăng nhập
                    </label>
                    <a href="#">Quên mật khẩu?</a>
                </div>

                <button class="login-button" type="submit">Đăng nhập</button>
            </form>

            <div class="signup-text">
                Chưa có tài khoản? <a href="#">Đăng ký ngay</a>
            </div>
        </section>
    </main>
</body>
</html>
