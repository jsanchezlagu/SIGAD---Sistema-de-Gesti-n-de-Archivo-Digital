<?php
/**
 * Ventana de ingreso SIGAD.
 * GET: muestra el formulario.
 * POST: valida usuario. Si MySQL falla, se muestra el aviso en esta misma
 * ventana (nunca un HTTP 500 vacío).
 */
$err = '';
$csrfHtml = '';

try {
    require_once __DIR__ . '/config/config.php';
} catch (Throwable $e) {
    $err = 'No se pudo cargar la configuración. Suba config/ e includes/ o ejecute install.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    try {
        if (function_exists('csrf_check') && !csrf_check()) {
            $err = 'Usuario o contraseña incorrectos.';
        } else {
            $user = trim((string)($_POST['username'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            if ($user === '' || $pass === '') {
                $err = 'Usuario o contraseña incorrectos.';
            } elseif (function_exists('login_bloqueado') && login_bloqueado($user)) {
                if (function_exists('registrar_intento_login')) registrar_intento_login($user, false);
                $err = 'Demasiados intentos fallidos. Espere unos minutos e inténtelo de nuevo.';
            } else {
                $st = db()->prepare('SELECT * FROM usuarios WHERE username = ?');
                $st->execute([$user]);
                $u = $st->fetch();
                if (!$u || !password_verify($pass, $u['password'])) {
                    if (function_exists('registrar_intento_login')) registrar_intento_login($user, false);
                    $err = 'Usuario o contraseña incorrectos.';
                } elseif (empty($u['activo'])) {
                    if (function_exists('registrar_intento_login')) registrar_intento_login($user, false);
                    $err = 'Su cuenta está suspendida. Contacte al administrador.';
                } else {
                    if (function_exists('registrar_intento_login')) registrar_intento_login($user, true);
                    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
                    $_SESSION['uid']    = $u['id'];
                    $_SESSION['user']   = $u['username'];
                    $_SESSION['rol']    = $u['rol'];
                    $_SESSION['nombre'] = trim(($u['nombres'] ?? '') . ' ' . ($u['apellidos'] ?? ''));
                    db()->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([$u['id']]);
                    if (function_exists('auditar')) auditar('INGRESO');
                    if (function_exists('es_admin') && es_admin()) header('Location: usuarios.php');
                    elseif (function_exists('es_consulta') && es_consulta()) header('Location: buscar.php');
                    else header('Location: panel.php');
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('SIGAD login: ' . $e->getMessage());
        $err = 'No se pudo conectar a MySQL. En cPanel abra install.php y use el usuario, la clave y el nombre exactos de la BD (MySQL Databases). Servidor: localhost.';
    }
}

if (isset($_GET['e']) && $err === '') {
    $cod = (int)$_GET['e'];
    $err = $cod === 2 ? 'Su cuenta está suspendida. Contacte al administrador.'
         : ($cod === 3 ? 'Demasiados intentos fallidos. Espere unos minutos e inténtelo de nuevo.'
         : ($cod === 4 ? 'No se pudo conectar a MySQL. Abra install.php y use el usuario, la clave y el nombre de la BD de cPanel.'
         : 'Usuario o contraseña incorrectos.'));
}

if (function_exists('csrf_field')) {
    try { $csrfHtml = csrf_field(); } catch (Throwable $e) { $csrfHtml = ''; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGAD - Archivo Central Municipalidad Distrital de San Marcos</title>
<link rel="icon" href="assets/logo-msm.png">
<link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-bg" style="background-image:url('assets/fondo-municipalidad.jpg')"></div>
  <div class="login-box">
    <img src="assets/logo-msm.png" class="logo-login" alt="Escudo Municipalidad Distrital de San Marcos">
    <h1>SIGAD</h1>
    <p class="sub">Sistema de Gestión de Archivo Digital<br>
      <b>MUNICIPALIDAD DISTRITAL DE SAN MARCOS</b><br>
      <span style="font-size:11px">Archivo Central</span></p>
    <?php if ($err !== ''): ?>
      <div class="login-err"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" autocomplete="off">
      <?= $csrfHtml ?>
      <label>Usuario</label>
      <input name="username" autocomplete="username" autofocus>
      <label>Contraseña</label>
      <input name="password" type="password" autocomplete="current-password">
      <button class="btn" type="submit">Ingresar</button>
    </form>
    <div class="pie-login">Municipalidad Distrital de San Marcos · Huari · Áncash</div>
  </div>
</div>
</body>
</html>
