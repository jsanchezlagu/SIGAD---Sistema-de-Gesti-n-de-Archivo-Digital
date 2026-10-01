<?php
/**
 * SIGAD · Monitor de desarrollador.
 *
 * Registra errores y deja un tablero (monitor.php) protegido con una clave
 * en config/monitor.key. No envía datos fuera del servidor.
 */

const SIGAD_VERSION = '2026.10';

function monitor_ruta_clave(): string {
    return (defined('RUTA_BASE') ? RUTA_BASE : dirname(__DIR__)) . '/config/monitor.key';
}

function monitor_ruta_log(): string {
    return (defined('RUTA_BASE') ? RUTA_BASE : dirname(__DIR__)) . '/config/monitor.log';
}

/** Crea o lee la clave de desarrollador (64 hex). */
function monitor_clave(): string {
    if (defined('MONITOR_KEY') && is_string(MONITOR_KEY) && MONITOR_KEY !== '') {
        return MONITOR_KEY;
    }
    $f = monitor_ruta_clave();
    if (is_file($f)) {
        $k = trim((string)file_get_contents($f));
        if ($k !== '') return $k;
    }
    $k = bin2hex(random_bytes(32));
    $dir = dirname($f);
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($f, $k . "\n");
        @chmod($f, 0600);
    }
    return $k;
}

function monitor_clave_ok(?string $recibida): bool {
    if ($recibida === null || $recibida === '') return false;
    $ok = monitor_clave();
    return $ok !== '' && hash_equals($ok, $recibida);
}

function asegurar_esquema_monitor(?PDO $pdo = null): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $pdo = $pdo ?? monitor_pdo(false);
        if (!$pdo) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_eventos (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            tipo       VARCHAR(40)  NOT NULL DEFAULT 'evento',
            nivel      VARCHAR(10)  NOT NULL DEFAULT 'info',
            mensaje    VARCHAR(2000) NOT NULL DEFAULT '',
            archivo    VARCHAR(255) NOT NULL DEFAULT '',
            linea      INT          NOT NULL DEFAULT 0,
            ip         VARCHAR(45)  NOT NULL DEFAULT '',
            usuario    VARCHAR(50)  NOT NULL DEFAULT '',
            fecha      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (fecha), INDEX (nivel), INDEX (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // Sin ALTER/CREATE no se cae la app.
    }
}

/**
 * Anota un evento. Nunca lanza: si MySQL falla, escribe config/monitor.log.
 */
function monitor_registrar(string $tipo, string $nivel, string $mensaje, string $archivo = '', int $linea = 0): void {
    static $enCurso = false;
    if ($enCurso) return;
    $enCurso = true;
    try {
        $mensaje = mb_substr(trim($mensaje), 0, 2000);
        if ($mensaje === '') {
            $enCurso = false;
            return;
        }
        $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $usr = (string)($_SESSION['user'] ?? '');
        $archivo = mb_substr($archivo, 0, 255);
        $linea = max(0, $linea);

        $pdo = monitor_pdo(false);
        if ($pdo) {
            try {
                asegurar_esquema_monitor($pdo);
                $pdo->prepare('INSERT INTO monitor_eventos (tipo,nivel,mensaje,archivo,linea,ip,usuario)
                               VALUES (?,?,?,?,?,?,?)')
                    ->execute([$tipo, $nivel, $mensaje, $archivo, $linea, $ip, $usr]);
                if (random_int(1, 40) === 1) {
                    $pdo->exec('DELETE FROM monitor_eventos WHERE id < (SELECT m FROM (SELECT MAX(id)-500 AS m FROM monitor_eventos) t)');
                }
                $enCurso = false;
                return;
            } catch (Throwable $e) {
                // cae al archivo
            }
        }
        $lineaLog = date('c') . "\t{$nivel}\t{$tipo}\t{$mensaje}\t{$ip}\n";
        @file_put_contents(monitor_ruta_log(), $lineaLog, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // silencioso
    }
    $enCurso = false;
}

function monitor_instalar_manejadores(): void {
    if (defined('SIGAD_TEST') || PHP_SAPI === 'cli') return;
    static $ok = false;
    if ($ok) return;
    $ok = true;

    set_error_handler(static function (int $sev, string $msg, string $file, int $line): bool {
        if (!(error_reporting() & $sev)) return false;
        $nivel = in_array($sev, [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE, E_DEPRECATED], true)
            ? 'aviso' : 'error';
        monitor_registrar('php', $nivel, $msg, $file, $line);
        return false;
    });

    set_exception_handler(static function (Throwable $e): void {
        monitor_registrar('excepcion', 'error', $e->getMessage(), $e->getFile(), $e->getLine());
        error_log('SIGAD: ' . $e->getMessage());
        if (!headers_sent()) http_response_code(500);
        exit;
    });

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            monitor_registrar('fatal', 'error', $e['message'], $e['file'] ?? '', (int)($e['line'] ?? 0));
        }
    });
}

/** PDO de sondeo: no redirige al login si MySQL falla. */
function monitor_pdo(bool $anotarFallo = true): ?PDO {
    static $cache = false;
    static $inst = null;
    if ($cache) return $inst;
    if (!defined('DB_HOST')) return null;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
        $inst = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $cache = true;
        return $inst;
    } catch (Throwable $e) {
        $cache = true;
        $inst = null;
        if ($anotarFallo) {
            @file_put_contents(monitor_ruta_log(), date('c') . "\terror\tmysql\t" . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        return null;
    }
}
