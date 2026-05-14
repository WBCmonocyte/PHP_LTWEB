<?php

function fetch_one(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    return $result->fetch_assoc() ?: [];
}

function fetch_all(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function money_format_vnd($amount): string
{
    return number_format((float) $amount, 0, ",", ".") . " đ";
}

function number_value($value): string
{
    return number_format((float) $value, 0, ",", ".");
}

function format_decimal($value): string
{
    $str = number_format((float) $value, 4, ".", "");

    return rtrim(rtrim($str, "0"), ".");
}

function percent_value($value): string
{
    return number_format((float) $value, 1, ",", ".") . "%";
}

function date_time_value(?string $value): string
{
    if (!$value) {
        return "Chưa có";
    }

    return date("d/m/Y H:i", strtotime($value));
}

function days_diff_label(?string $expectedDate): string
{
    if (!$expectedDate) {
        return "Chưa có hạn";
    }

    $today = new DateTime("today");
    $expected = new DateTime(date("Y-m-d", strtotime($expectedDate)));
    $days = (int) $today->diff($expected)->format("%r%a");

    if ($days < 0) {
        return "Trễ " . abs($days) . " ngày";
    }

    if ($days === 0) {
        return "Đến hạn hôm nay";
    }

    return "Còn " . $days . " ngày";
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function require_login(): void
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit();
    }
}

function redirect(string $path): void
{
    header("Location: " . $path);
    exit();
}

function flash_set(string $type, string $message): void
{
    if (!isset($_SESSION["_flash"])) {
        $_SESSION["_flash"] = [];
    }

    $_SESSION["_flash"][] = ["type" => $type, "message" => $message];
}

function flash_pop(): array
{
    $messages = $_SESSION["_flash"] ?? [];
    unset($_SESSION["_flash"]);
    return $messages;
}

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

function status_label(string $status): string
{
    return match ($status) {
        "Pending" => "Đang giao",
        "Completed" => "Hoàn thành",
        "Cancelled" => "Đã hủy",
        default => $status,
    };
}

function status_class(string $status): string
{
    return match ($status) {
        "Pending" => "badge orange plain",
        "Completed" => "badge green plain",
        "Cancelled" => "badge red plain",
        default => "badge plain",
    };
}
