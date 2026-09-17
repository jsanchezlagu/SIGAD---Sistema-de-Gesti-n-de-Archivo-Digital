<?php
/**
 * Ingreso SIGAD. El formulario vive también en index.php: si este archivo
 * no se reemplaza en cPanel, el login antiguo redirige a index.php y el
 * POST se procesa ahí (evita el HTTP 500 de Chrome).
 */
$pagina = __DIR__ . '/includes/pagina_login.php';
if (is_file($pagina)) {
    require $pagina;
    exit;
}
http_response_code(200);
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>SIGAD</title></head>
<body style="font-family:sans-serif;max-width:480px;margin:40px auto">
<p>Falta <code>includes/pagina_login.php</code>. Extraiga el ZIP dentro de la carpeta del subdominio (donde ya están index.php y login.php) y abra <a href="install.php">install.php</a>.</p>
</body></html>
