<?php /* Logout handler: tinatapos ang session at nagre-redirect pabalik. */
session_start();
require_once 'includes/activity_logger.php';


if (isset($_SESSION['user_id'])) { log_activity('logout', 'user', $_SESSION['user_id']); }
$_SESSION = array();


if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}


session_destroy();


header("Location: login.php");
exit();
?>