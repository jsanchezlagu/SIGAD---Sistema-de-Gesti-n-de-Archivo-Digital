<?php
require_once 'config/config.php';
// Exigir token CSRF para evitar cierre de sesión forzado desde otro sitio.
if (!csrf_check()) { header('Location: index.php'); exit; }
auditar('SALIDA');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: index.php');
