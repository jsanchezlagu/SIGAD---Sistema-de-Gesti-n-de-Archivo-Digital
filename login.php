<?php
require_once 'config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Protección CSRF
    if (!csrf_check()) {
        header('Location: index.php?e=1'); exit;
    }

    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === '' || $pass === '') {
        header('Location: index.php?e=1'); exit;
    }

    // 2) Bloqueo por fuerza bruta (por usuario o IP)
    if (login_bloqueado($user)) {
        registrar_intento_login($user, false);
        header('Location: index.php?e=3'); exit;
    }

    $st = db()->prepare("SELECT * FROM usuarios WHERE username = ?");
    $st->execute([$user]);
    $u = $st->fetch();

    // 3) Verificación de credenciales (mensaje genérico, sin filtrar si el
    //    usuario existe o no)
    if (!$u || !password_verify($pass, $u['password'])) {
        registrar_intento_login($user, false);
        header('Location: index.php?e=1'); exit;
    }
    if (!$u['activo']) {                       // suspendido por el administrador
        registrar_intento_login($user, false);
        header('Location: index.php?e=2'); exit;
    }

    // 4) Éxito: registrar, renovar el id de sesión (anti-fixation) y crear sesión
    registrar_intento_login($user, true);
    session_regenerate_id(true);

    $_SESSION['uid']    = $u['id'];
    $_SESSION['user']   = $u['username'];
    $_SESSION['rol']    = $u['rol'];
    $_SESSION['nombre'] = trim($u['nombres'] . ' ' . $u['apellidos']);

    db()->prepare("UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?")
        ->execute([$u['id']]);
    auditar('INGRESO');

    if (es_admin())        header('Location: usuarios.php');       // gestion de cuentas
    elseif (es_consulta()) header('Location: buscar.php');         // solo buscar
    else                   header('Location: panel.php');          // operador
    exit;
}
header('Location: index.php');
