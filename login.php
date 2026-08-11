<?php
require_once 'config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    if (!$u['activo']) {                       // suspendido por el superadmin
        header('Location: index.php?e=2'); exit;
    }

    $_SESSION['uid']    = $u['id'];
    $_SESSION['user']   = $u['username'];
    $_SESSION['rol']    = $u['rol'];
    $_SESSION['nombre'] = trim($u['nombres'] . ' ' . $u['apellidos']);

    db()->prepare("UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?")
        ->execute([$u['id']]);
    auditar('INGRESO');

    if (es_admin())      header('Location: usuarios.php');       // gestion de cuentas
    elseif (es_consulta()) header('Location: buscar.php');         // solo buscar
    else                   header('Location: panel.php');         // operador
    exit;
}
header('Location: index.php');
