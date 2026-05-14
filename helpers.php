<?php
// ============================================================================
// helpers.php - Tập hợp các hàm tiện ích (helper) dùng chung cho toàn web:
//   * Truy vấn database đơn giản: fetch_one, fetch_all
//   * Format dữ liệu hiển thị: money_format_vnd, number_value, percent_value,
//     date_time_value, days_diff_label, format_decimal
//   * Bảo mật HTML output: e()
//   * Điều hướng / phiên đăng nhập: require_login(), redirect()
//   * Flash message: flash_set(), flash_pop()
//   * Chuyển đổi datetime giữa DB và input HTML
//   * Nhãn / class CSS cho trạng thái đơn hàng
// ============================================================================

/**
 * Chạy 1 câu SELECT và trả về 1 dòng đầu tiên dưới dạng mảng associative.
 * Dùng cho các query thống kê tổng hợp (COUNT, SUM) chỉ có 1 dòng kết quả.
 *
 * @return array  Mảng cột => giá trị; trả về [] nếu query lỗi hoặc rỗng.
 */
function fetch_one(mysqli $conn, string $sql): array
{
    // $conn->query() trả về:
    //   - false nếu câu SQL lỗi
    //   - mysqli_result nếu là SELECT thành công
    $result = $conn->query($sql);

    // Bảo vệ trước trường hợp truy vấn lỗi: trả về mảng rỗng để code phía sau
    // dùng "?? 0" hoặc count() vẫn an toàn, không bị fatal error.
    if (!$result) {
        return [];
    }

    // fetch_assoc() lấy 1 dòng kế tiếp, dạng [tên_cột => giá_trị].
    // Nếu không có dòng nào (kết quả rỗng) thì nó trả về null → ép thành [].
    return $result->fetch_assoc() ?: [];
}

/**
 * Chạy 1 câu SELECT và trả về TẤT CẢ các dòng dưới dạng mảng các mảng.
 * Dùng cho danh sách (list orders, list suppliers, top 5 vật tư,...).
 *
 * @return array  Mảng các record; trả về [] nếu query lỗi.
 */
function fetch_all(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    // fetch_all(MYSQLI_ASSOC) lấy hết các dòng còn lại, mỗi dòng là associative
    // array. Khác với fetch_all() mặc định trả về indexed array.
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Format số tiền sang chuỗi VND, ví dụ: 1500000 -> "1.500.000 đ".
 * number_format($value, $decimals, $dec_sep, $thousands_sep).
 */
function money_format_vnd($amount): string
{
    return number_format((float) $amount, 0, ",", ".") . " đ";
}

/**
 * Format số nguyên với dấu phân cách hàng nghìn (dấu chấm theo phong cách VN).
 * Dùng cho số đơn, số nhà cung cấp, số lượng vật tư,...
 */
function number_value($value): string
{
    return number_format((float) $value, 0, ",", ".");
}

/**
 * Format số thập phân dùng cho DB (unit_price). Trả về chuỗi gọn nhất, ví dụ:
 *   12000.0000 -> "12000"
 *   12000.5000 -> "12000.5"
 * Mục đích: hiển thị lại giá trị trong <input type="number"> mà không có ".00" thừa.
 */
function format_decimal($value): string
{
    // Bước 1: format ra 4 chữ số thập phân, dùng "." làm dấu thập phân
    // (vì <input type="number"> chỉ chấp nhận dấu chấm).
    $str = number_format((float) $value, 4, ".", "");

    // Bước 2: cắt bớt các số 0 phía sau, rồi cắt luôn dấu chấm nếu không còn
    // phần thập phân (kết quả: "12000.5000" -> "12000.5", "12000.0000" -> "12000").
    return rtrim(rtrim($str, "0"), ".");
}

/**
 * Format số % với 1 chữ số thập phân, dấu phẩy kiểu VN. Ví dụ: 95.5 -> "95,5%".
 */
function percent_value($value): string
{
    return number_format((float) $value, 1, ",", ".") . "%";
}

/**
 * Đổi chuỗi datetime từ DB (kiểu "YYYY-MM-DD HH:MM:SS") sang dạng dễ đọc
 * "dd/mm/YYYY HH:MM". Nếu giá trị null/rỗng thì trả về "Chưa có".
 */
function date_time_value(?string $value): string
{
    if (!$value) {
        return "Chưa có";
    }

    // strtotime() phân tích chuỗi datetime thành timestamp (số giây);
    // sau đó date() format lại theo định dạng mong muốn.
    return date("d/m/Y H:i", strtotime($value));
}

/**
 * So sánh ngày dự kiến với hôm nay và trả về nhãn thân thiện:
 *   - "Trễ N ngày"     nếu đã qua hạn
 *   - "Đến hạn hôm nay" nếu trùng ngày hôm nay
 *   - "Còn N ngày"     nếu còn hạn
 */
function days_diff_label(?string $expectedDate): string
{
    if (!$expectedDate) {
        return "Chưa có hạn";
    }

    // DateTime("today") => 00:00 hôm nay; so sánh theo ngày, không tính giờ.
    $today = new DateTime("today");
    $expected = new DateTime(date("Y-m-d", strtotime($expectedDate)));

    // diff() trả về DateInterval; format("%r%a") cho ra số ngày có dấu
    // (%r = dấu - nếu âm, %a = số ngày tuyệt đối).
    $days = (int) $today->diff($expected)->format("%r%a");

    if ($days < 0) {
        return "Trễ " . abs($days) . " ngày";
    }

    if ($days === 0) {
        return "Đến hạn hôm nay";
    }

    return "Còn " . $days . " ngày";
}

/**
 * Escape HTML — viết tắt cho htmlspecialchars(). Mỗi khi in dữ liệu từ DB/user
 * ra HTML đều phải qua e() để chặn XSS (Cross-Site Scripting).
 * ENT_QUOTES: escape cả " và ' (an toàn khi nằm trong attribute).
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/**
 * Bắt buộc người dùng phải đăng nhập mới được vào trang.
 * Nếu chưa có user_id trong session thì redirect sang login.php và dừng script.
 * Gọi hàm này ở đầu mỗi trang admin (dashboard.php, orders.php,...).
 */
function require_login(): void
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        // exit() rất quan trọng — nếu không có, code sau header() vẫn chạy
        // và có thể leak nội dung trang ra cho người chưa đăng nhập.
        exit();
    }
}

/**
 * Helper redirect ngắn gọn: gửi header Location rồi exit().
 */
function redirect(string $path): void
{
    header("Location: " . $path);
    exit();
}

/**
 * Lưu một flash message vào session để hiển thị ở trang kế tiếp.
 * Pattern thường dùng: sau khi xử lý POST thành công → flash_set() rồi
 * redirect; trang đích gọi flash_pop() để lấy và xóa luôn.
 */
function flash_set(string $type, string $message): void
{
    if (!isset($_SESSION["_flash"])) {
        $_SESSION["_flash"] = [];
    }

    $_SESSION["_flash"][] = ["type" => $type, "message" => $message];
}

/**
 * Lấy toàn bộ flash messages rồi xóa chúng khỏi session (one-shot).
 * Bảo đảm thông báo chỉ hiện đúng 1 lần ở lần load trang tiếp theo.
 */
function flash_pop(): array
{
    $messages = $_SESSION["_flash"] ?? [];
    unset($_SESSION["_flash"]);
    return $messages;
}

/**
 * Đổi chuỗi datetime từ DB sang định dạng mà <input type="datetime-local">
 * yêu cầu: "YYYY-MM-DDTHH:MM" (chú ý chữ T ở giữa).
 * Dấu \T trong format string là để escape chữ T literal — nếu không escape,
 * date() sẽ hiểu T là viết tắt timezone (ví dụ "ICT").
 */
function datetime_to_input(?string $value): string
{
    if (!$value) {
        return "";
    }

    $ts = strtotime($value);

    if ($ts === false || $ts <= 0) {
        return "";
    }

    return date("Y-m-d\TH:i", $ts);
}

/**
 * Đổi giá trị từ <input type="datetime-local"> ("YYYY-MM-DDTHH:MM")
 * thành định dạng MySQL DATETIME ("YYYY-MM-DD HH:MM:SS") để insert/update.
 */
function input_to_datetime(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return null;
    }

    return date("Y-m-d H:i:s", $ts);
}

/**
 * Đổi mã trạng thái đơn (giá trị lưu DB) sang label tiếng Việt để hiển thị.
 * Dùng cú pháp match (PHP 8+) — gọn và rõ ràng hơn switch.
 */
function status_label(string $status): string
{
    return match ($status) {
        "Pending" => "Đang giao",
        "Completed" => "Hoàn thành",
        "Cancelled" => "Đã hủy",
        default => $status,
    };
}

/**
 * Trả về chuỗi class CSS tương ứng với trạng thái đơn — để badge có màu phù hợp.
 * "badge orange plain" → tag badge với màu cam, không có nền (plain).
 */
function status_class(string $status): string
{
    return match ($status) {
        "Pending" => "badge orange plain",
        "Completed" => "badge green plain",
        "Cancelled" => "badge red plain",
        default => "badge plain",
    };
}
