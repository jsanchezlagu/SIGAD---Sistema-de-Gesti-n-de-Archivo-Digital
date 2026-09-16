<?php
/**
 * Prueba de búsqueda SIGAD (CLI).
 * Uso: php tests/probar_busqueda.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SIGAD_TEST', 1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/funciones.php';

$fallos = 0;
function ok(string $msg): void { echo "OK  $msg\n"; }
function fail(string $msg): void { global $fallos; $fallos++; echo "FAIL $msg\n"; }

$db = db();
asegurar_esquema_busqueda();

$cols = array_column($db->query('SHOW COLUMNS FROM expedientes')->fetchAll(), 'Field');
foreach (['cui', 'nombre_proyecto'] as $c) {
    in_array($c, $cols, true) ? ok("columna expedientes.$c") : fail("falta expedientes.$c");
}
$colsD = array_column($db->query('SHOW COLUMNS FROM documentos')->fetchAll(), 'Field');
in_array('texto_origen', $colsD, true) ? ok('columna documentos.texto_origen') : fail('falta texto_origen');

$idx = array_column($db->query('SHOW INDEX FROM expedientes')->fetchAll(), 'Key_name');
in_array('ft_exp', $idx, true) ? ok('índice FULLTEXT ft_exp') : fail('falta ft_exp');

$admin = $db->query("SELECT id FROM usuarios WHERE rol='ADMIN' LIMIT 1")->fetch();
$uid = (int)($admin['id'] ?? 1);
$pab = (int)$db->query("SELECT id FROM pabellones ORDER BY id LIMIT 1")->fetchColumn();
if ($pab < 1) fail('no hay pabellones'); 

$nro = 'EXP-TEST-' . date('His') . '-' . random_int(100, 999);
$cui = '2445678';
$proy = 'Mejoramiento de la Plaza de Armas de San Marcos';
$db->prepare("INSERT INTO expedientes
    (nro_expediente, cui, anio, fecha_expediente, area_origen, asunto, nombre_proyecto, pabellon_id, folios, creado_por)
    VALUES (?,?,?,?,?,?,?,?,?,?)")
   ->execute([$nro, $cui, 2024, '2024-05-10', 'Obras Públicas', 'Expediente técnico de obra', $proy, $pab, 12, $uid]);
$expId = (int)$db->lastInsertId();
ok("expediente $nro id=$expId");

$dir = sys_get_temp_dir();
$pdf = $dir . '/sigad_plaza.pdf';
file_put_contents($pdf, pdf_con_texto('Mejoramiento de la Plaza de Armas de San Marcos. CUI 2445678. Municipalidad Distrital de San Marcos.'));
$ext = extraer_texto_pdf($pdf);
strlen(trim($ext['texto'])) > 20
    ? ok('pdftotext extrajo capa: ' . $ext['origen'] . ' (' . strlen($ext['texto']) . ' bytes)')
    : fail('pdftotext no extrajo texto: ' . json_encode($ext));

$_SESSION['uid'] = $uid;
$r = subir_pdf(['name'=>'plaza.pdf','tmp_name'=>$pdf,'error'=>0,'size'=>filesize($pdf),'type'=>'application/pdf'], $expId, $db);
str_contains($r, 'PDF subido') ? ok("subir_pdf: $r") : fail("subir_pdf: $r");

$casos = [
    'Plaza de Armas' => 'proyecto o documento',
    '2445678'        => 'CUI',
    'plaza de armas' => 'minúsculas',
    'CUI-2445678'    => 'CUI con prefijo',
    $nro             => 'n° expediente',
];
foreach ($casos as $q => $tipo) {
    [$filas, $total] = buscar_expedientes($db, $q, '', '', false, 1, 25);
    $ids = array_column($filas, 'id');
    in_array($expId, $ids, true)
        ? ok("búsqueda «$q» ($tipo) → $total hit(s), origen=" . ($filas[0]['origen'] ?? ''))
        : fail("búsqueda «$q» ($tipo) no encontró el expediente");
}

[$vacio, $t0] = buscar_expedientes($db, 'xyzzy-no-existe-sigad-9f3a', '', '', false, 1, 25);
$idsV = array_column($vacio, 'id');
in_array($expId, $idsV, true) ? fail('falso positivo en término inexistente') : ok('sin falso positivo');

echo $fallos === 0 ? "\nTODOS LOS CHECKS OK\n" : "\n$fallos fallo(s)\n";
exit($fallos === 0 ? 0 : 1);

function pdf_con_texto(string $texto): string {
    $texto = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
    $stream = "BT /F1 12 Tf 50 700 Td ($texto) Tj ET";
    $len = strlen($stream);
    return "%PDF-1.4\n"
        . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
        . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R "
        . "/Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
        . "4 0 obj << /Length $len >> stream\n$stream\nendstream endobj\n"
        . "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
        . "trailer << /Root 1 0 R >>\n%%EOF\n";
}
