<?php
session_start();

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/auth.php";

restore_remembered_login($conn);

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$username = "";
$remember = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $remember = isset($_POST["remember"]);

    if ($username === "" || $password === "") {
        $error = "Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.";
    } else {
        $stmt = $conn->prepare("SELECT admin_id AS id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && (password_verify($password, $user["password"]) || hash_equals($user["password"], $password))) {
            login_user($user);

            if ($remember) {
                set_remember_cookie($user);
            } else {
                clear_remember_cookie();
            }

            header("Location: dashboard.php");
            exit();
        }

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

            <?php if ($error !== ""): ?>
                <div class="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div>
            <?php endif; ?>

            <form action="" method="post">
                <div class="form-group">
                    <label for="username">Tên đăng nhập</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Nhập tên đăng nhập"
                        value="<?php echo htmlspecialchars($username, ENT_QUOTES, "UTF-8"); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Mật khẩu</label>
                    <input type="password" id="password" name="password" placeholder="Nhập mật khẩu" required>
                </div>

                <div class="form-options">
                    <label class="remember">
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
