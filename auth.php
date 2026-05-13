<?php
const REMEMBER_COOKIE = "remember_user";
const REMEMBER_SECRET = "final_ltweb_remember_login_secret";
const REMEMBER_DAYS = 30;

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["role"] = $user["role"] ?? "";
}

function remember_cookie_options(int $expires): array
{
    return [
        "expires" => $expires,
        "path" => "/",
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax",
    ];
}

function remember_signature(array $user): string
{
    $data = $user["id"] . "|" . $user["username"] . "|" . $user["password"];

    return hash_hmac("sha256", $data, REMEMBER_SECRET);
}

function set_remember_cookie(array $user): void
{
    $payload = [
        "id" => (int) $user["id"],
        "username" => $user["username"],
        "signature" => remember_signature($user),
    ];

    $token = base64_encode(json_encode($payload));
    setcookie(REMEMBER_COOKIE, $token, remember_cookie_options(time() + REMEMBER_DAYS * 24 * 60 * 60));
}

function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, "", remember_cookie_options(time() - 3600));
}

function find_user_by_id(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT admin_id AS id, username, password, full_name, role FROM users WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function restore_remembered_login(mysqli $conn): bool
{
    if (isset($_SESSION["user_id"])) {
        return true;
    }

    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return false;
    }

    $payload = json_decode(base64_decode($_COOKIE[REMEMBER_COOKIE], true), true);

    if (!is_array($payload) || empty($payload["id"]) || empty($payload["signature"])) {
        clear_remember_cookie();
        return false;
    }

    $user = find_user_by_id($conn, (int) $payload["id"]);

    if (!$user || !hash_equals(remember_signature($user), $payload["signature"])) {
        clear_remember_cookie();
        return false;
    }

    login_user($user);
    set_remember_cookie($user);

    return true;
}
