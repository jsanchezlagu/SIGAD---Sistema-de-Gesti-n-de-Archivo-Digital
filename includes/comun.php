<?php
/**
 * SIGAD · Núcleo común (seguridad + helpers).
 *
 * Este archivo NO contiene credenciales. Lo incluye config/config.php después
 * de definir las constantes DB_* y las rutas. Al centralizar aquí la sesión,
 * las cabeceras de seguridad, el CSRF y los helpers de rol, el instalador puede
 * regenerar config.php sin duplicar (ni desincronizar) esta lógica.
 */

if (!defined('DB_HOST')) {
    // No se debe incluir directamente; siempre a través de config/config.php.
    http_response_code(500);
    exit('Configuración no cargada.');
}

/* ------------------------------------------------------------------ *
 *  Sesión endurecida
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    // Marca la cookie como Secure sólo cuando la petición llega por HTTPS
    // (incluye el caso de proxy inverso de cPanel con X-Forwarded-Proto).
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,          // no accesible desde JavaScript
        'samesite' => 'Lax',         // mitiga CSRF entre sitios
        'secure'   => $https,        // sólo por HTTPS cuando aplica
    ]);
    session_name('SIGADSESID');
    session_start();
}

/* ------------------------------------------------------------------ *
 *  Cabeceras de seguridad (defensa en profundidad)
 * ------------------------------------------------------------------ */
function enviar_cabeceras_seguridad(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');                 // anti-clickjacking
    header('Referrer-Policy: no-referrer');
    // La app usa estilos/JS en línea, por eso 'unsafe-inline'. El escapado de
    // salida es la defensa principal contra XSS; la CSP es refuerzo.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    header_remove('X-Powered-By');
}
enviar_cabeceras_seguridad();

/* ------------------------------------------------------------------ *
 *  Conexión PDO reutilizable
 * ------------------------------------------------------------------ */
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  Protección CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto listo para insertar en un <form>. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** True si el token recibido (POST o GET) coincide con el de sesión. */
function csrf_check(): bool {
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

/** Aborta con 403 si el token CSRF es inválido. Usar al inicio de cada acción. */
function requiere_csrf(): void {
    if (!csrf_check()) {
        http_response_code(403);
        exit('Solicitud rechazada: token de seguridad (CSRF) ausente o inválido.');
    }
}

/* ------------------------------------------------------------------ *
 *  Control de fuerza bruta en el login (degrada con elegancia si la
 *  tabla login_intentos aún no existe en instalaciones antiguas)
 * ------------------------------------------------------------------ */
const LOGIN_MAX_FALLOS  = 8;     // intentos fallidos permitidos
const LOGIN_VENTANA_MIN = 15;    // dentro de esta ventana (minutos)

function registrar_intento_login(string $usuario, bool $exito): void {
    try {
        db()->prepare("INSERT INTO login_intentos (usuario, ip, exito) VALUES (?,?,?)")
            ->execute([mb_substr($usuario, 0, 50), $_SERVER['REMOTE_ADDR'] ?? '', $exito ? 1 : 0]);
    } catch (Throwable $e) {
        // Tabla ausente u otro problema: no bloquear el login por esto.
    }
}

function login_bloqueado(string $usuario): bool {
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM login_intentos
             WHERE exito = 0 AND (usuario = ? OR ip = ?)
               AND fecha > (NOW() - INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE)");
        $st->execute([mb_substr($usuario, 0, 50), $_SERVER['REMOTE_ADDR'] ?? '']);
        return ((int)$st->fetchColumn()) >= LOGIN_MAX_FALLOS;
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------ *
 *  Auditoría
 * ------------------------------------------------------------------ */
function auditar(string $accion, string $detalle = ''): void {
    if (!isset($_SESSION['uid'])) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $u  = db()->prepare("INSERT INTO auditoria (usuario_id, username, accion, detalle, ip)
                         VALUES (?,?,?,?,?)");
    $u->execute([$_SESSION['uid'], $_SESSION['user'], $accion, $detalle, $ip]);
}

/* ------------------------------------------------------------------ *
 *  Sesión / roles
 * ------------------------------------------------------------------ */
function requiere_login(): void {
    if (empty($_SESSION['uid'])) {
        $base = dirname($_SERVER['PHP_SELF']);
        $loc  = ($base === '/' || $base === '\\') ? 'index.php' : '../index.php';
        header("Location: $loc");
        exit;
    }
    asegurar_esquema_busqueda();
}

/** True si puede escribir/modificar (ADMIN u OPERADOR). */
function puede_escribir(): bool {
    return in_array($_SESSION['rol'] ?? '', ['ADMIN', 'OPERADOR'], true);
}

/** True si es ADMINISTRADOR (acceso total). */
function es_admin(): bool {
    return ($_SESSION['rol'] ?? '') === 'ADMIN';
}

/** True si es solo CONSULTA. */
function es_consulta(): bool {
    return ($_SESSION['rol'] ?? '') === 'CONSULTA';
}

/** Exige un rol concreto; si no lo cumple, redirige al panel. */
function requiere_rol(string ...$roles): void {
    requiere_login();
    if (!in_array($_SESSION['rol'] ?? '', $roles, true)) {
        header('Location: panel.php');
        exit;
    }
}

/**
 * Menú lateral consistente para todas las páginas.
 */
function nav_html(string $pagina): string {
    $rol   = $_SESSION['rol'] ?? '';
    $items = [
        ['Panel',  'panel.php'],
        ['Buscar', 'buscar.php'],
    ];
    if (in_array($rol, ['ADMIN', 'OPERADOR'], true)) {
        $items[] = ['Registrar',  'registrar.php'];
        $items[] = ['Pabellones', 'pabellones.php'];
    }
    if ($rol === 'ADMIN') {
        $items[] = ['Usuarios',  'usuarios.php'];
        $items[] = ['Áreas',     'areas.php'];
        $items[] = ['Auditoría', 'auditoria.php'];
    }
    $html = '';
    foreach ($items as [$nombre, $href]) {
        $on = ($nombre === $pagina) ? ' class="on"' : '';
        $html .= "<a href=\"$href\"$on>" . htmlspecialchars($nombre) . "</a>";
    }
    return $html;
}

/** Enlace de cierre de sesión con token CSRF (evita logout forzado). */
function logout_href(): string {
    return 'logout.php?csrf=' . urlencode(csrf_token());
}

/** Lanza JSON y termina. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/busqueda.php';
