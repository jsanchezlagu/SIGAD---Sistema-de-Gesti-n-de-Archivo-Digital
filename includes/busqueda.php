<?php
/**
 * SIGAD · Búsqueda de expedientes.
 *
 * Combina:
 *  - Campos estructurados (CUI, n° expediente, nombre de proyecto)
 *  - FULLTEXT de MariaDB sobre metadatos y texto extraído del PDF
 *  - LIKE como respaldo (códigos cortos, instalaciones sin índice FULLTEXT)
 *
 * asegurar_esquema_busqueda() aplica la migración en caliente para que un
 * cPanel ya instalado reciba las columnas nuevas al subir estos archivos.
 */

/** Quita tildes y pasa a minúsculas para comparar en español. */
function normalizar_texto(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $from = ['á','é','í','ó','ú','ü','ñ','à','è','ì','ò','ù'];
    $to   = ['a','e','i','o','u','u','n','a','e','i','o','u'];
    return str_replace($from, $to, $s);
}

/** Tokens alfanuméricos de la consulta (mín. 2 caracteres). */
function tokens_busqueda(string $q): array {
    $q = normalizar_texto($q);
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $t) {
        if (mb_strlen($t) >= 2) $out[] = $t;
    }
    return $out;
}

/**
 * Expresión BOOLEAN MODE. Ignora operadores FULLTEXT del usuario.
 * Palabras de 3+ caracteres usan prefijo (plaza* encuentra plazas).
 */
function boolean_fulltext(string $q): string {
    $out = [];
    foreach (tokens_busqueda($q) as $t) {
        $t = preg_replace('/[+\-><()~*"@]/', '', $t) ?? '';
        if (mb_strlen($t) < 3) continue;
        $out[] = '+' . $t . '*';
    }
    return implode(' ', array_unique($out));
}

/** Recorte de texto alrededor de la primera coincidencia, con <mark>. */
function snippet_coincidencia(?string $texto, string $q, int $radio = 90): string {
    if ($texto === null || $texto === '' || $q === '') return '';
    $hay = normalizar_texto($texto);
    $needle = '';
    foreach (tokens_busqueda($q) as $t) {
        $pos = mb_strpos($hay, $t);
        if ($pos !== false) { $needle = $t; break; }
    }
    if ($needle === '') {
        $plain = trim(preg_replace('/\s+/', ' ', $texto) ?? '');
        return mb_substr($plain, 0, 180) . (mb_strlen($plain) > 180 ? '…' : '');
    }
    $pos = (int)mb_strpos($hay, $needle);
    $ini = max(0, $pos - $radio);
    $frag = mb_substr($texto, $ini, $radio * 2 + mb_strlen($needle));
    $frag = trim(preg_replace('/\s+/', ' ', $frag) ?? '');
    if ($ini > 0) $frag = '…' . $frag;
    if ($ini + $radio * 2 < mb_strlen($texto)) $frag .= '…';
    $safe = htmlspecialchars($frag, ENT_QUOTES, 'UTF-8');
    foreach (tokens_busqueda($q) as $t) {
        if (mb_strlen($t) < 3) continue;
        $safe = preg_replace(
            '/(' . preg_quote($t, '/') . ')/iu',
            '<mark>$1</mark>',
            $safe
        ) ?? $safe;
    }
    return $safe;
}

/**
 * Añade CUI, nombre_proyecto e índices FULLTEXT si aún no existen.
 * Silencioso si el usuario de BD no tiene permiso ALTER (no tumba la app).
 */
function asegurar_esquema_busqueda(): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $db = db();

        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM expedientes')->fetchAll() as $c) {
            $cols[$c['Field']] = true;
        }
        if (empty($cols['cui'])) {
            $db->exec("ALTER TABLE expedientes
                ADD COLUMN cui VARCHAR(40) NOT NULL DEFAULT '' AFTER nro_expediente");
        }
        if (empty($cols['nombre_proyecto'])) {
            $db->exec("ALTER TABLE expedientes
                ADD COLUMN nombre_proyecto VARCHAR(255) NOT NULL DEFAULT '' AFTER asunto");
        }

        $idxExp = [];
        foreach ($db->query('SHOW INDEX FROM expedientes')->fetchAll() as $i) {
            $idxExp[$i['Key_name']] = true;
        }
        if (empty($idxExp['idx_cui'])) {
            $db->exec('ALTER TABLE expedientes ADD INDEX idx_cui (cui)');
        }
        if (empty($idxExp['ft_exp'])) {
            $db->exec('ALTER TABLE expedientes
                ADD FULLTEXT INDEX ft_exp (nro_expediente, cui, nombre_proyecto, asunto, area_origen)');
        }

        $colsDoc = [];
        foreach ($db->query('SHOW COLUMNS FROM documentos')->fetchAll() as $c) {
            $colsDoc[$c['Field']] = true;
        }
        if (empty($colsDoc['texto_origen'])) {
            $db->exec("ALTER TABLE documentos
                ADD COLUMN texto_origen VARCHAR(10) NOT NULL DEFAULT '' AFTER texto");
        }

        $idxDoc = [];
        foreach ($db->query('SHOW INDEX FROM documentos')->fetchAll() as $i) {
            $idxDoc[$i['Key_name']] = true;
        }
        if (empty($idxDoc['ft_texto'])) {
            $db->exec('ALTER TABLE documentos ADD FULLTEXT INDEX ft_texto (texto)');
        }
    } catch (Throwable $e) {
        // Instalación antigua sin ALTER: la búsqueda sigue con LIKE sobre columnas existentes.
    }
}

/**
 * @return array{0: list<array<string,mixed>>, 1: int} filas y total
 */
function buscar_expedientes(PDO $db, string $q, string $pab, string $anio, bool $soloAprobados, int $pag, int $porPagina = 25): array {
    $q    = trim($q);
    $pab  = trim($pab);
    $pag  = max(1, $pag);
    $off  = ($pag - 1) * $porPagina;
    $like = '%' . $q . '%';
    $cuiN = preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '';
    $cuiL = $cuiN !== '' ? '%' . $cuiN . '%' : $like;
    $bool = $q !== '' ? boolean_fulltext($q) : '';

    $where  = ['1=1'];
    $params = [];

    if ($soloAprobados) {
        $where[] = "e.estado = 'APROBADO'";
    }
    if ($pab !== '') {
        $where[] = 'p.codigo = ?';
        $params[] = $pab;
    }
    if ($anio !== '' && ctype_digit($anio)) {
        $where[] = 'e.anio = ?';
        $params[] = (int)$anio;
    }

    $usaFt = $bool !== '';
    if ($q !== '') {
        $or = [
            'e.nro_expediente LIKE ?',
            'e.cui LIKE ?',
            "REPLACE(REPLACE(e.cui,'-',''),' ','') LIKE ?",
            'e.nombre_proyecto LIKE ?',
            'e.asunto LIKE ?',
            'e.area_origen LIKE ?',
        ];
        array_push($params, $like, $like, $cuiL, $like, $like, $like);
        if ($usaFt) {
            $or[] = 'MATCH(e.nro_expediente, e.cui, e.nombre_proyecto, e.asunto, e.area_origen) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $bool;
            $or[] = 'EXISTS (SELECT 1 FROM documentos d WHERE d.expediente_id = e.id
                             AND MATCH(d.texto) AGAINST(? IN BOOLEAN MODE))';
            $params[] = $bool;
        }
        $or[] = 'EXISTS (SELECT 1 FROM documentos d WHERE d.expediente_id = e.id AND d.texto LIKE ?)';
        $params[] = $like;
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    $sqlWhere = implode(' AND ', $where);

    $selectBase = "SELECT e.id, e.nro_expediente, e.cui, e.nombre_proyecto, e.anio, e.fecha_expediente,
                   e.area_origen, e.asunto, p.codigo AS pab, e.estado,
                   (SELECT COUNT(*) FROM documentos d WHERE d.expediente_id = e.id) AS docs,
                   (SELECT d.texto FROM documentos d
                     WHERE d.expediente_id = e.id AND d.texto IS NOT NULL AND d.texto <> ''
                     LIMIT 1) AS texto_pdf";

    $relParams = [];
    if ($q === '') {
        $sql = "$selectBase, 0 AS relevancia
                FROM expedientes e
                JOIN pabellones p ON p.id = e.pabellon_id
                WHERE $sqlWhere
                ORDER BY e.creado_en DESC
                LIMIT $porPagina OFFSET $off";
    } else {
        $relFt = $usaFt
            ? 'MATCH(e.nro_expediente, e.cui, e.nombre_proyecto, e.asunto, e.area_origen) AGAINST(? IN BOOLEAN MODE)'
            : '0';
        $sql = "$selectBase,
                   (
                     (CASE WHEN e.nro_expediente LIKE ? OR e.cui LIKE ?
                            OR REPLACE(REPLACE(e.cui,'-',''),' ','') LIKE ? THEN 40 ELSE 0 END)
                   + (CASE WHEN e.nombre_proyecto LIKE ? THEN 30 ELSE 0 END)
                   + (CASE WHEN e.asunto LIKE ? THEN 12 ELSE 0 END)
                   + ($relFt) * 8
                   ) AS relevancia
                FROM expedientes e
                JOIN pabellones p ON p.id = e.pabellon_id
                WHERE $sqlWhere
                ORDER BY relevancia DESC, e.creado_en DESC
                LIMIT $porPagina OFFSET $off";
        $relParams = [$like, $like, $cuiL, $like, $like];
        if ($usaFt) $relParams[] = $bool;
    }

    $countSql = "SELECT COUNT(*) FROM expedientes e
                 JOIN pabellones p ON p.id = e.pabellon_id
                 WHERE $sqlWhere";

    $run = function (string $sql, array $bind) use ($db, $usaFt) {
        $st = $db->prepare($sql);
        $st->execute($bind);
        return $st;
    };

    try {
        $total = (int)$run($countSql, $params)->fetchColumn();
        $filas = $run($sql, array_merge($relParams, $params))->fetchAll();
    } catch (Throwable $e) {
        // Sin FULLTEXT (permisos / índice ausente): repetir sólo con LIKE.
        if (!$usaFt) throw $e;
        return buscar_expedientes_like($db, $q, $pab, $anio, $soloAprobados, $pag, $porPagina);
    }

    foreach ($filas as &$r) {
        $r['snippet'] = snippet_coincidencia($r['texto_pdf'] ?? '', $q);
        $r['origen']  = origen_coincidencia($r, $q);
        unset($r['texto_pdf']);
    }
    unset($r);

    return [$filas, $total];
}

/** Respaldo sin MATCH() para hosting donde no se pudo crear el índice. */
function buscar_expedientes_like(PDO $db, string $q, string $pab, string $anio, bool $soloAprobados, int $pag, int $porPagina): array {
    $q    = trim($q);
    $pag  = max(1, $pag);
    $off  = ($pag - 1) * $porPagina;
    $like = '%' . $q . '%';
    $cuiN = preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '';
    $cuiL = $cuiN !== '' ? '%' . $cuiN . '%' : $like;

    $where = ['1=1'];
    $params = [];
    if ($soloAprobados) $where[] = "e.estado = 'APROBADO'";
    if ($pab !== '') { $where[] = 'p.codigo = ?'; $params[] = $pab; }
    if ($anio !== '' && ctype_digit($anio)) { $where[] = 'e.anio = ?'; $params[] = (int)$anio; }
    if ($q !== '') {
        $where[] = "(e.nro_expediente LIKE ? OR e.cui LIKE ?
                     OR REPLACE(REPLACE(e.cui,'-',''),' ','') LIKE ?
                     OR e.nombre_proyecto LIKE ? OR e.asunto LIKE ? OR e.area_origen LIKE ?
                     OR EXISTS (SELECT 1 FROM documentos d WHERE d.expediente_id=e.id AND d.texto LIKE ?))";
        array_push($params, $like, $like, $cuiL, $like, $like, $like, $like);
    }
    $sqlWhere = implode(' AND ', $where);
    $st = $db->prepare("SELECT COUNT(*) FROM expedientes e JOIN pabellones p ON p.id=e.pabellon_id WHERE $sqlWhere");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT e.id, e.nro_expediente, e.cui, e.nombre_proyecto, e.anio,
                               e.fecha_expediente, e.area_origen, e.asunto, p.codigo AS pab, e.estado,
                               (SELECT COUNT(*) FROM documentos d WHERE d.expediente_id=e.id) AS docs,
                               (SELECT d.texto FROM documentos d
                                 WHERE d.expediente_id=e.id AND d.texto IS NOT NULL AND d.texto<>''
                                 LIMIT 1) AS texto_pdf
                        FROM expedientes e JOIN pabellones p ON p.id=e.pabellon_id
                        WHERE $sqlWhere
                        ORDER BY e.creado_en DESC
                        LIMIT $porPagina OFFSET $off");
    $st->execute($params);
    $filas = $st->fetchAll();
    foreach ($filas as &$r) {
        $r['snippet'] = snippet_coincidencia($r['texto_pdf'] ?? '', $q);
        $r['origen']  = origen_coincidencia($r, $q);
        unset($r['texto_pdf']);
    }
    unset($r);
    return [$filas, $total];
}

function origen_coincidencia(array $r, string $q): string {
    if ($q === '') return '';
    $n = normalizar_texto($q);
    $cuiN = preg_replace('/[^A-Za-z0-9]/', '', $n) ?? '';
    $nro  = normalizar_texto((string)($r['nro_expediente'] ?? ''));
    $cui  = preg_replace('/[^A-Za-z0-9]/', '', normalizar_texto((string)($r['cui'] ?? ''))) ?? '';
    if ($cui !== '' && $cuiN !== '' && (str_contains($cui, $cuiN) || str_contains($cuiN, $cui))) {
        return 'código';
    }
    if (str_contains($nro, $n) || ($cuiN !== '' && str_contains($nro, $cuiN))) {
        return 'código';
    }
    if (str_contains(normalizar_texto((string)($r['nombre_proyecto'] ?? '')), $n)
        || tokens_en((string)($r['nombre_proyecto'] ?? ''), $q)) {
        return 'proyecto';
    }
    if (tokens_en((string)($r['asunto'] ?? ''), $q)) return 'asunto';
    if (!empty($r['snippet'])) return 'documento';
    return '';
}

function tokens_en(string $campo, string $q): bool {
    $hay = normalizar_texto($campo);
    $ok = false;
    foreach (tokens_busqueda($q) as $t) {
        if (mb_strlen($t) < 3) continue; // ignora "de", "la", "el"
        if (str_contains($hay, $t)) $ok = true;
        else return false;
    }
    return $ok;
}

/**
 * Sugerencias rápidas mientras se escribe (sin escanear el texto del PDF).
 * Prefijo de CUI/EXP primero, luego nombre de proyecto.
 *
 * @return list<array{id:int,nro:string,cui:string,titulo:string,anio:int,origen:string}>
 */
function sugerencias_expedientes(PDO $db, string $q, bool $soloAprobados, int $limite = 8): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $pref = $q . '%';
    $cuiN = preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '';
    $cuiL = $cuiN !== '' ? '%' . $cuiN . '%' : $like;
    $limite = max(1, min(15, $limite));

    $where = ['1=1'];
    $params = [];
    if ($soloAprobados) $where[] = "e.estado = 'APROBADO'";
    $where[] = "(e.nro_expediente LIKE ? OR e.cui LIKE ?
                 OR REPLACE(REPLACE(e.cui,'-',''),' ','') LIKE ?
                 OR e.nombre_proyecto LIKE ? OR e.asunto LIKE ?)";
    array_push($params, $like, $like, $cuiL, $like, $like);
    $sqlWhere = implode(' AND ', $where);

    $sql = "SELECT e.id, e.nro_expediente, e.cui, e.nombre_proyecto, e.asunto, e.anio
            FROM expedientes e
            WHERE $sqlWhere
            ORDER BY
              CASE
                WHEN e.cui LIKE ? OR e.nro_expediente LIKE ? THEN 0
                WHEN e.nombre_proyecto LIKE ? THEN 1
                ELSE 2
              END,
              e.creado_en DESC
            LIMIT $limite";
    $st = $db->prepare($sql);
    $st->execute(array_merge($params, [$pref, $pref, $pref]));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $titulo = trim((string)($r['nombre_proyecto'] !== '' ? $r['nombre_proyecto'] : $r['asunto']));
        $out[] = [
            'id'     => (int)$r['id'],
            'nro'    => (string)$r['nro_expediente'],
            'cui'    => (string)$r['cui'],
            'titulo' => $titulo,
            'anio'   => (int)$r['anio'],
            'origen' => origen_coincidencia($r, $q) ?: 'proyecto',
        ];
    }
    return $out;
}
