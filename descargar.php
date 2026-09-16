<?php
require_once 'config/config.php';
requiere_login();
$id = (int)($_GET['id'] ?? 0);
$db = db();

// Traer el documento junto con el estado de su expediente para poder aplicar
// control de acceso por rol.
$st = $db->prepare("SELECT d.*, e.estado AS exp_estado
                    FROM documentos d
                    JOIN expedientes e ON e.id = d.expediente_id
                    WHERE d.id = ?");
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); exit('No encontrado'); }

// El rol CONSULTA sólo puede descargar documentos de expedientes APROBADOS.
if (es_consulta() && $doc['exp_estado'] !== 'APROBADO') {
    http_response_code(403); exit('No autorizado');
}

// Resolver la ruta física y evitar cualquier salida fuera de uploads/
// (defensa contra path traversal si el nombre almacenado fuese manipulado).
$ruta = RUTA_UPLOADS . '/' . basename($doc['archivo']);
$real = realpath($ruta);
$baseReal = realpath(RUTA_UPLOADS);
if ($real === false || $baseReal === false || strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); exit('Archivo no disponible');
}
if (!is_file($real)) { http_response_code(404); exit('Archivo no disponible'); }

auditar('DESCARGO PDF', $doc['nombre_original']);

// Nombre de archivo saneado para la cabecera (sin CRLF ni comillas).
$nombre = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)$doc['nombre_original']);
if ($nombre === '' ) $nombre = 'documento.pdf';

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $nombre . '"; '
     . "filename*=UTF-8''" . rawurlencode((string)$doc['nombre_original']));
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, no-store');
readfile($real);
exit;
