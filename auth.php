<?php
// ============================================================================
// auth.php - Toàn bộ logic xác thực người dùng:
//   * login_user($user): set session sau khi đăng nhập thành công
//   * set_remember_cookie / clear_remember_cookie: cookie "ghi nhớ đăng nhập"
//   * restore_remembered_login: tự đăng nhập lại từ cookie ở lần truy cập sau
//   * find_user_by_id: tra user theo id (dùng cho cookie validation)
// ============================================================================

// Tên cookie lưu token "remember me" trên trình duyệt người dùng.
const REMEMBER_COOKIE = "remember_user";

// Khóa bí mật server-side để ký (HMAC) token remember-me. Trong production
// nên đưa vào biến môi trường, không hard-code. Chỉ server biết → kẻ tấn
// công không thể giả mạo cookie nếu không có chuỗi này.
const REMEMBER_SECRET = "final_ltweb_remember_login_secret";

// Số ngày cookie remember-me sẽ tồn tại trên trình duyệt.
const REMEMBER_DAYS = 30;

/**
 * Đăng nhập 1 user vào session: lưu các trường cần thiết vào $_SESSION.
 * Chỉ gọi hàm này SAU KHI đã verify thành công username/password.
 */
function login_user(array $user): void
{
    // Cấp lại session ID (regenerate) để chống session-fixation:
    // nếu kẻ tấn công đã biết session ID cũ (qua phishing chẳng hạn) thì
    // sau khi ta đổi ID, session cũ đó vô tác dụng.
    session_regenerate_id(true);

    // Lưu các thông tin user thường xuyên dùng vào session để các trang
    // sau không phải truy DB lại mỗi request.
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["role"] = $user["role"] ?? "";
}

/**
 * Tập options dùng cho setcookie() khi set/clear cookie remember-me.
 * Đặt thành 1 hàm để hai chỗ (set & clear) đảm bảo dùng cùng cấu hình
 * (path, secure, httponly, samesite) — nếu khác nhau, cookie có thể không
 * bị xóa được khi logout.
 */
function remember_cookie_options(int $expires): array
{
    return [
        "expires" => $expires,
        "path" => "/",
        // secure = true nếu đang chạy HTTPS → cookie chỉ gửi qua SSL.
        // Trên localhost (HTTP) thì false để vẫn hoạt động được.
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        // httponly: JS phía client KHÔNG đọc được cookie này → giảm rủi ro XSS
        // đánh cắp token đăng nhập.
        "httponly" => true,
        // samesite=Lax: cookie chỉ được gửi kèm khi user vào link trực tiếp,
        // không gửi khi trang khác submit POST sang ta → chống CSRF cơ bản.
        "samesite" => "Lax",
    ];
}

/**
 * Tạo chữ ký HMAC cho token remember-me. Ý tưởng:
 *   - Ghép id + username + password (hash trong DB) thành chuỗi data
 *   - Hash data đó với khóa bí mật → ra "signature"
 * Khi user đổi mật khẩu, chuỗi password thay đổi → signature cũ vô hiệu →
 * cookie remember-me cũ tự động không dùng được nữa (security tốt).
 */
function remember_signature(array $user): string
{
    $data = $user["id"] . "|" . $user["username"] . "|" . $user["password"];

    return hash_hmac("sha256", $data, REMEMBER_SECRET);
}

/**
 * Set cookie remember-me trên trình duyệt user, chứa:
 *   - id, username: để biết user nào
 *   - signature: để xác thực tính nguyên vẹn
 * Đóng gói thành JSON → base64 (an toàn khi đặt trong cookie).
 */
function set_remember_cookie(array $user): void
{
    $payload = [
        "id" => (int) $user["id"],
        "username" => $user["username"],
        "signature" => remember_signature($user),
    ];

    $token = base64_encode(json_encode($payload));

    // time() + 30 * 86400 = hết hạn 30 ngày kể từ bây giờ.
    setcookie(REMEMBER_COOKIE, $token, remember_cookie_options(time() + REMEMBER_DAYS * 24 * 60 * 60));
}

/**
 * Xóa cookie remember-me bằng cách set lại cookie với expires ở quá khứ.
 * Đây là cách chuẩn để xóa cookie (không có hàm "removeCookie" trực tiếp).
 */
function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, "", remember_cookie_options(time() - 3600));
}

/**
 * Tìm user theo admin_id. Trả về mảng các cột cần thiết hoặc null nếu không có.
 * Dùng prepared statement (bind_param) để chống SQL injection.
 *
 *   AS id: đặt alias cho admin_id, để code khác dùng $user["id"] cho gọn.
 */
function find_user_by_id(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT admin_id AS id, username, password, full_name, role FROM users WHERE admin_id = ? LIMIT 1");
    // bind_param("i", ...): "i" = kiểu integer; tham số ? sẽ được thay
    // bằng giá trị $id một cách an toàn (escape tự động).
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

/**
 * Khôi phục đăng nhập từ cookie remember-me. Gọi ở đầu mỗi trang có thể
 * truy cập (login.php, dashboard.php,...). Logic:
 *   1. Nếu user đã có trong session → return true (đã đăng nhập).
 *   2. Nếu không có cookie → return false (vẫn ẩn danh).
 *   3. Decode cookie. Nếu hỏng → xóa cookie, return false.
 *   4. Lookup user theo id; kiểm tra signature có khớp không.
 *   5. Khớp → login user, làm mới cookie để tiếp tục đếm 30 ngày.
 */
function restore_remembered_login(mysqli $conn): bool
{
    // Đã đăng nhập rồi, không cần làm gì thêm.
    if (isset($_SESSION["user_id"])) {
        return true;
    }

    // Không có cookie remember-me → user chưa từng "Ghi nhớ đăng nhập".
    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return false;
    }

    // Giải mã cookie: base64 → JSON → mảng PHP.
    $payload = json_decode(base64_decode($_COOKIE[REMEMBER_COOKIE], true), true);

    // Nếu cookie bị sửa hoặc lỗi format → xóa luôn và bỏ qua.
    if (!is_array($payload) || empty($payload["id"]) || empty($payload["signature"])) {
        clear_remember_cookie();
        return false;
    }

    // Lấy user hiện tại trong DB theo id.
    $user = find_user_by_id($conn, (int) $payload["id"]);

    // So sánh signature bằng hash_equals() — đây là hàm so sánh chuỗi
    // chống "timing attack" (so sánh ký tự theo thời gian cố định, không
    // leak thông tin qua thời gian phản hồi).
    if (!$user || !hash_equals(remember_signature($user), $payload["signature"])) {
        clear_remember_cookie();
        return false;
    }

    // Tới đây nghĩa là cookie hợp lệ → đăng nhập lại user và làm mới cookie.
    login_user($user);
    set_remember_cookie($user);

    return true;
}
