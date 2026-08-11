<?php
/** Funciones compartidas del sistema SIGAD (PHP puro, sin dependencias). */

/** Guarda un PDF, calcula SHA-256 y extrae texto para busqueda (OCR de capa). */
function subir_pdf(array $f, int $exp_id, PDO $db): string {
    $max_bytes = 400 * 1024 * 1024; // 400 MB por tomo
    $err = $f['error'] ?? UPLOAD_ERR_OK;
    if ($err !== UPLOAD_ERR_OK) {
        return match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'El archivo supera el límite permitido (máx. 400 MB por tomo).',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'Error al subir el archivo (código ' . $err . ').',
        };
    }
    if (!is_uploaded_file($f['tmp_name'])) return 'Archivo no válido.';

    $nombre = basename($f['name']);
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf')
        return 'Solo se aceptan PDF.';
    if (filesize($f['tmp_name']) > $max_bytes)
        return 'El archivo supera el límite permitido (máx. 400 MB por tomo).';

    $hash = hash_file('sha256', $f['tmp_name']);
    $dest = RUTA_UPLOADS . '/' . $exp_id . '_' . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre);
    if (!move_uploaded_file($f['tmp_name'], $dest) && !copy($f['tmp_name'], $dest)) {
        return 'No se pudo guardar el archivo en el servidor.';
    }

    // OCR de capa: si pdftotext esta disponible, extrae texto real
    $texto = '';
    if (function_exists('shell_exec')) {
        $out = @shell_exec('pdftotext ' . escapeshellarg($dest) . ' - 2>/dev/null');
        if ($out) $texto = mb_substr($out, 0, 200000);
    }

    $mb  = round(filesize($dest) / 1048576, 1);
    $ver = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM documentos WHERE expediente_id=?");
    $ver->execute([$exp_id]);
    $v = $ver->fetchColumn();

    $db->prepare("INSERT INTO documentos
        (expediente_id, nombre_original, archivo, tamano_bytes, tamano_mb, hash_sha256, texto, version, subido_por)
        VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$exp_id, $nombre, basename($dest), filesize($dest), $mb, $hash, $texto, $v, $_SESSION['uid'] ?? null]);
    if (function_exists('auditar')) auditar('SUBIO PDF', $nombre . " ($mb MB)");
    return "PDF subido (SHA-256 confirmado).";
}
