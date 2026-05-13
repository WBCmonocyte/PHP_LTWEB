<?php
session_start();

require_once __DIR__ . "/auth.php";

clear_remember_cookie();
session_unset();
session_destroy();

header("Location: index.php");
exit();
