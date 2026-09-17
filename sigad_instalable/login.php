<?php
/**
 * login.php antiguo endurecido: GET muestra aviso y envía a index.php;
 * POST nunca deja un HTTP 500 vacío si MySQL falla.
 */
try {
    require_once 'config/config.php';
} catch (Throwable $e) {
    header('Location: index.php?e=4');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === '' || $pass === '') {
        header('Location: index.php?e=1'); exit;
    }
    $st = db()->prepare("SELECT * FROM usuarios WHERE username = ?");
    $st->execute([$user]);
    $u = $st->fetch();

    if (!$u || !password_verify($pass, $u['password'])) {
        header('Location: index.php?e=1'); exit;
    }
    if (!$u['activo']) {
        header('Location: index.php?e=2'); exit;
    }

    $_SESSION['uid']    = $u['id'];
    $_SESSION['user']   = $u['username'];
    $_SESSION['rol']    = $u['rol'];
    $_SESSION['nombre'] = trim($u['nombres'] . ' ' . $u['apellidos']);

    db()->prepare("UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?")
        ->execute([$u['id']]);
    auditar('INGRESO');

    if (es_admin())      header('Location: usuarios.php');
    elseif (es_consulta()) header('Location: buscar.php');
    else                   header('Location: panel.php');
    exit;
} catch (Throwable $e) {
    error_log('SIGAD login: ' . $e->getMessage());
    header('Location: index.php?e=4');
    exit;
}
