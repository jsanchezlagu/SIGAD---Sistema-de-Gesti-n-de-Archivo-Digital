<?php
/**
 * Simula un POST de login con MySQL inaccesible (lo lanza probar_login.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME']    = '/login.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_POST = ['username' => 'admin', 'password' => 'x'];
require dirname(__DIR__) . '/config/config.php';
$_POST['csrf'] = csrf_token();
require dirname(__DIR__) . '/includes/pagina_login.php';
