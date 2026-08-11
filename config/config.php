<?php
/**
 * Configuracion central de SIGAD PHP.
 * En hosting compartido edita estas lineas con los datos que te da el panel
 * (phpMyAdmin / cPanel). No subas este archivo a repositorios publicos.
 */
define('DB_HOST',     'localhost');
define('DB_NAME',     'sigad');
define('DB_USER',     'sigad_user');   // <- cambiar por el usuario de tu hosting
define('DB_PASS',     'sigad_pass');   // <- cambiar por tu clave
define('DB_CHARSET',  'utf8mb4');

define('APP_NOMBRE',  'SIGAD');
define('ENTIDAD',     'MUNICIPALIDAD DISTRITAL DE SAN MARCOS');
define('RUTA_BASE',   dirname(__DIR__));                  // raiz del proyecto (padre de config/)
define('RUTA_UPLOADS', RUTA_BASE . '/uploads');
define('URL_UPLOADS', 'uploads');

// Sesion: usar el save_path por defecto de PHP (funciona en XAMPP y hosting)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Conexion PDO reutilizable. */
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/** Registra un movimiento en la tabla de auditoria. */
function auditar(string $accion, string $detalle = ''): void {
    if (!isset($_SESSION['uid'])) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $u  = db()->prepare("INSERT INTO auditoria (usuario_id, username, accion, detalle, ip)
                         VALUES (?,?,?,?,?)");
    $u->execute([$_SESSION['uid'], $_SESSION['user'], $accion, $detalle, $ip]);
}

/** Devuelve el usuario en sesion o redirige al login. */
function requiere_login(): void {
    if (empty($_SESSION['uid'])) {
        // ruta relativa a la raiz del proyecto, funciona desde / y /admin/
        $base = dirname($_SERVER['PHP_SELF']);
        $loc  = ($base === '/' || $base === '\\') ? 'index.php' : '../index.php';
        header("Location: $loc");
        exit;
    }
}

/** True si puede escribir/modificar (ADMIN u OPERADOR). */
function puede_escribir(): bool {
    return in_array($_SESSION['rol'] ?? '', ['ADMIN','OPERADOR']);
}

/** True si es ADMINISTRADOR (acceso total: gestion de usuarios, auditoria). */
function es_admin(): bool {
    return ($_SESSION['rol'] ?? '') === 'ADMIN';
}

/** True si es solo CONSULTA (solo buscar/ver/descargar). */
function es_consulta(): bool {
    return ($_SESSION['rol'] ?? '') === 'CONSULTA';
}

/**
 * Menu lateral unico y consistente para TODAS las paginas.
 * $pagina = nombre de la pagina actual (Panel, Buscar, Registrar, Pabellones,
 *          Usuarios, Areas, Auditoria) para marcarla como activa.
 * Todos los modulos del rol siempre visibles (no desaparecen al navegar).
 */
function nav_html(string $pagina): string {
    $rol = $_SESSION['rol'] ?? '';
    // modulos base: todos los roles ven Panel y Buscar
    $items = [
        ['Panel',     'panel.php'],
        ['Buscar',    'buscar.php'],
    ];
    // escritura: ADMIN y OPERADOR
    if (in_array($rol, ['ADMIN','OPERADOR'], true)) {
        $items[] = ['Registrar',   'registrar.php'];
        $items[] = ['Pabellones',  'pabellones.php'];
    }
    // gestion: solo ADMIN
    if ($rol === 'ADMIN') {
        $items[] = ['Usuarios',  'usuarios.php'];
        $items[] = ['Áreas',     'areas.php'];
        $items[] = ['Auditoría', 'auditoria.php'];
    }
    $html = '';
    foreach ($items as [$nombre, $href]) {
        $on = ($nombre === $pagina) ? ' class="on"' : '';
        $html .= "<a href=\"$href\"$on>$nombre</a>";
    }
    return $html;
}

/** Lanza JSON y termina. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
