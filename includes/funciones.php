<?php
/** Funciones compartidas del sistema SIGAD (PHP puro, sin dependencias). */

/** True si el archivo parece un PDF (extensión + magic bytes + finfo). */
function es_pdf_valido(string $tmp, string $nombre): bool {
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') return false;
    $fh = fopen($tmp, 'rb');
    if ($fh === false) return false;
    $magic = fread($fh, 5);
    fclose($fh);
    if ($magic !== '%PDF-') return false;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $tmp);
            finfo_close($fi);
            if ($mime && $mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
                return false;
            }
        }
    }
    return true;
}

/**
 * Extrae texto indexable de un PDF.
 * 1) Capa de texto (pdftotext) — PDFs nativos / digitalizados con OCR previo.
 * 2) OCR con Tesseract si existe en el servidor y la capa está vacía (escaneos).
 * En cPanel compartido lo habitual es (1); (2) requiere SSH/binarios.
 *
 * @return array{texto:string, origen:string, paginas:int}
 */
function extraer_texto_pdf(string $ruta): array {
    $texto = '';
    $origen = 'vacio';
    $paginas = 0;

    if (!is_file($ruta) || !function_exists('shell_exec')) {
        return ['texto' => '', 'origen' => $origen, 'paginas' => 0];
    }

    $arg = escapeshellarg($ruta);
    $info = @shell_exec('pdfinfo ' . $arg . ' 2>/dev/null');
    if (is_string($info) && preg_match('/Pages:\s+(\d+)/i', $info, $m)) {
        $paginas = (int)$m[1];
    }

    $out = @shell_exec('pdftotext -layout -enc UTF-8 ' . $arg . ' - 2>/dev/null');
    if (is_string($out) && strlen(trim($out)) >= 40) {
        $texto = $out;
        $origen = 'capa';
    } else {
        $ocr = extraer_ocr_paginas($ruta, 3);
        if ($ocr !== '') {
            $texto = $ocr;
            $origen = 'ocr';
        }
    }

    $texto = mb_substr($texto, 0, 200000);
    return ['texto' => $texto, 'origen' => $origen, 'paginas' => $paginas];
}

/** OCR de las primeras N páginas (pdftoppm + tesseract). Vacío si no hay binarios. */
function extraer_ocr_paginas(string $ruta, int $maxPag = 3): string {
    if (!function_exists('shell_exec')) return '';
    $whichTess = trim((string)@shell_exec('command -v tesseract 2>/dev/null'));
    $whichPpm  = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($whichTess === '' || $whichPpm === '') return '';

    $dir = sys_get_temp_dir() . '/sigad_ocr_' . bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0700, true)) return '';
    $pref = $dir . '/p';
    @shell_exec('pdftoppm -png -f 1 -l ' . (int)$maxPag . ' -r 150 '
        . escapeshellarg($ruta) . ' ' . escapeshellarg($pref) . ' 2>/dev/null');
    $textos = [];
    foreach (glob($pref . '-*.png') ?: [] as $img) {
        $lang = 'spa+eng';
        $probe = trim((string)@shell_exec('tesseract --list-langs 2>/dev/null'));
        if ($probe !== '' && !str_contains($probe, 'spa')) $lang = 'eng';
        $t = @shell_exec('tesseract ' . escapeshellarg($img) . ' stdout -l ' . $lang . ' 2>/dev/null');
        if (is_string($t) && trim($t) !== '') $textos[] = $t;
        @unlink($img);
    }
    @rmdir($dir);
    return trim(implode("\n", $textos));
}

/** Guarda un PDF, calcula SHA-256 y extrae texto para búsqueda. */
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
    $tmp = $f['tmp_name'] ?? '';
    $okTmp = is_uploaded_file($tmp)
        || ((PHP_SAPI === 'cli' || defined('SIGAD_TEST')) && is_file($tmp));
    if ($tmp === '' || !$okTmp) return 'Archivo no válido.';

    $nombre = basename((string)($f['name'] ?? 'documento.pdf'));
    if (!es_pdf_valido($tmp, $nombre)) return 'Solo se aceptan archivos PDF válidos.';
    if (filesize($tmp) > $max_bytes)
        return 'El archivo supera el límite permitido (máx. 400 MB por tomo).';

    if (!is_dir(RUTA_UPLOADS)) @mkdir(RUTA_UPLOADS, 0755, true);

    $hash = hash_file('sha256', $tmp);
    $dest = RUTA_UPLOADS . '/' . $exp_id . '_' . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre);
    if (!move_uploaded_file($tmp, $dest) && !copy($tmp, $dest)) {
        return 'No se pudo guardar el archivo en el servidor.';
    }

    $ext = extraer_texto_pdf($dest);
    $texto  = $ext['texto'];
    $origen = $ext['origen'];
    $pags   = $ext['paginas'];

    $mb  = round(filesize($dest) / 1048576, 1);
    $ver = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM documentos WHERE expediente_id=?");
    $ver->execute([$exp_id]);
    $v = $ver->fetchColumn();

    $db->prepare("INSERT INTO documentos
        (expediente_id, nombre_original, archivo, tamano_bytes, tamano_mb, hash_sha256, texto, texto_origen, paginas, version, subido_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $exp_id, $nombre, basename($dest), filesize($dest), $mb, $hash,
           $texto, $origen, $pags, $v, $_SESSION['uid'] ?? null,
       ]);
    if (function_exists('auditar')) auditar('SUBIO PDF', $nombre . " ($mb MB)");

    $msg = 'PDF subido (SHA-256 confirmado).';
    if ($origen === 'capa') $msg .= ' Texto del documento indexado para búsqueda.';
    elseif ($origen === 'ocr') $msg .= ' Texto extraído por OCR e indexado.';
    else $msg .= ' El PDF no tiene capa de texto (posible escaneo). Use el nombre del proyecto y el CUI para buscarlo.';
    return $msg;
}
