<?php
require_once 'config/config.php';
requiere_login();
$id = (int)($_GET['id'] ?? 0);
$db = db();
$st = $db->prepare("SELECT * FROM documentos WHERE id=?");
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); exit('No encontrado'); }

// ruta fisica dentro de uploads/
$ruta = RUTA_UPLOADS . '/' . $doc['archivo'];
if (!is_file($ruta)) { http_response_code(404); exit('Archivo no disponible'); }

// cabeceras para forzar descarga/visualizacion en navegador
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $doc['nombre_original'] . '"');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private');
readfile($ruta);
exit;
