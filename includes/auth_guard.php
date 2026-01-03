<?php /* Auth guard: proteksyon para sa pages; kailangan naka-login ang user. */


ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
?>