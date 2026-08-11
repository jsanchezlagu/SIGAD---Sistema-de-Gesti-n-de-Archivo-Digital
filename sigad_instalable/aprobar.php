<?php
require_once 'config/config.php';
requiere_login();
if (!es_admin()) { header('Location: panel.php'); exit; }
$db = db();
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $db->prepare("UPDATE expedientes SET estado='APROBADO', aprobado_por=?, aprobado_en=NOW() WHERE id=? AND estado<>'APROBADO'")
        ->execute([$_SESSION['uid'], $id]);
    $nro = $db->prepare("SELECT nro_expediente FROM expedientes WHERE id=?");
    $nro->execute([$id]); $nro = $nro->fetch();
    if ($nro) auditar('APROBO EXPEDIENTE', $nro['nro_expediente']);
}
header('Location: buscar.php');
exit;
