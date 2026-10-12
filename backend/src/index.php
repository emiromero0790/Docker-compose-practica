<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const CACHE_KEY   = 'incidencias:listado';
const CACHE_TTL   = 60;  
const CONTADOR    = 'contador_consultas';
const PRIORIDADES = ['baja', 'media', 'alta'];
const ESTADOS     = ['abierta', 'en_proceso', 'resuelta'];

function responder(int $codigo, array $datos): never {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_NAME'));
        $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function redis(): Redis {
    static $redis = null;
    if ($redis === null) {
        $r = new Redis();
        $r->connect((string) getenv('REDIS_HOST'), (int) (getenv('REDIS_PORT') ?: 6379), 2.0);
        $redis = $r;
    }
    return $redis;
}

function leerJson(): array {
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($datos)) {
        responder(400, ['error' => 'El cuerpo de la petición debe ser JSON válido']);
    }
    return $datos;
}

function invalidarCache(): void {
    try {
        redis()->del(CACHE_KEY);
        error_log('[CACHE] Invalidada ' . CACHE_KEY);
    } catch (Throwable $e) {
        error_log('[REDIS] No se pudo invalidar: ' . $e->getMessage());
    }
}

function enviarCorreo(string $asunto, string $cuerpo): void {
    $from = getenv('MAIL_FROM') ?: 'sistema@incidencias.local';
    $to   = getenv('MAIL_TO')   ?: 'soporte@incidencias.local';
    $s = @fsockopen((string) getenv('SMTP_HOST'), (int) (getenv('SMTP_PORT') ?: 1025), $errno, $errstr, 5);
    if (!$s) {
        throw new RuntimeException("No se pudo conectar al SMTP: $errstr");
    }
    $leer = function () use ($s): string {
        $r = '';
        while (($linea = fgets($s, 515)) !== false) {
            $r .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function (string $c, string $esperado) use ($s, $leer): void {
        fwrite($s, $c . "\r\n");
        $r = $leer();
        if (!str_starts_with($r, $esperado)) throw new RuntimeException("SMTP respondió: $r");
    };
    $leer(); 
    $cmd('HELO backend', '250');
    $cmd("MAIL FROM:<$from>", '250');
    $cmd("RCPT TO:<$to>", '250');
    $cmd('DATA', '354');
    $cuerpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $cuerpo));
    $mensaje = "From: Sistema de Incidencias <$from>\r\n"
             . "To: <$to>\r\n"
             . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
             . $cuerpo . "\r\n.";
    $cmd($mensaje, '250');
    $cmd('QUIT', '221');
    fclose($s);
}

function validar(array $e, bool $parcial): array {
    $errores = [];
    $d = [];
    if (!$parcial || array_key_exists('titulo', $e)) {
        $v = trim((string) ($e['titulo'] ?? ''));
        if (mb_strlen($v) < 3 || mb_strlen($v) > 120) $errores[] = 'El título debe tener entre 3 y 120 caracteres';
        $d['titulo'] = $v;
    }
    if (!$parcial || array_key_exists('area', $e)) {
        $v = trim((string) ($e['area'] ?? ''));
        if (mb_strlen($v) < 2 || mb_strlen($v) > 80) $errores[] = 'El área debe tener entre 2 y 80 caracteres';
        $d['area'] = $v;
    }
    if (!$parcial || array_key_exists('descripcion', $e)) {
        $v = trim((string) ($e['descripcion'] ?? ''));
        if (mb_strlen($v) > 500) $errores[] = 'La descripción admite máximo 500 caracteres';
        $d['descripcion'] = $v;
    }
    if (!$parcial || array_key_exists('prioridad', $e)) {
        $v = (string) ($e['prioridad'] ?? 'media');
        if (!in_array($v, PRIORIDADES, true)) $errores[] = 'Prioridad inválida (baja, media o alta)';
        $d['prioridad'] = $v;
    }
    if (!$parcial || array_key_exists('estado', $e)) {
        $v = (string) ($e['estado'] ?? 'abierta');
        if (!in_array($v, ESTADOS, true)) $errores[] = 'Estado inválido (abierta, en_proceso o resuelta)';
        $d['estado'] = $v;
    }
    return [$d, $errores];
}

// ---------- Endpoints ----------
function health(): void {
    $r = ['status' => 'ok', 'mariadb' => 'ok', 'redis' => 'ok'];
    try { db()->query('SELECT 1'); } catch (Throwable $e) { $r['mariadb'] = 'error'; $r['status'] = 'error'; }
    try { redis()->ping(); }       catch (Throwable $e) { $r['redis'] = 'error';   $r['status'] = 'error'; }
    responder($r['status'] === 'ok' ? 200 : 503, $r);
}

function listar(): void {
    $contador = null;
    try {
        $contador = redis()->incr(CONTADOR);
        $cache = redis()->get(CACHE_KEY);
        if ($cache !== false) {
            error_log('[CACHE] HIT ' . CACHE_KEY);
            header('X-Cache: HIT');
            responder(200, [
                'origen' => 'cache', 'contador' => $contador,
                'ttl' => redis()->ttl(CACHE_KEY), 'datos' => json_decode($cache, true),
            ]);
        }
    } catch (RedisException $e) {
        error_log('[REDIS] No disponible: ' . $e->getMessage());
    }

    error_log('[CACHE] MISS, consultando MariaDB');
    $datos = db()->query('SELECT id, titulo, descripcion, area, prioridad, estado, creado_en
                          FROM incidencias ORDER BY id DESC')->fetchAll();
    try {
        redis()->setex(CACHE_KEY, CACHE_TTL, json_encode($datos, JSON_UNESCAPED_UNICODE));
    } catch (RedisException $e) {
        error_log('[REDIS] No se pudo guardar caché');
    }
    header('X-Cache: MISS');
    responder(200, ['origen' => 'mariadb', 'contador' => $contador, 'ttl' => CACHE_TTL, 'datos' => $datos]);
}

function crear(array $entrada): void {
    [$d, $errores] = validar($entrada, false);
    if ($errores) responder(422, ['error' => implode('. ', $errores)]);

    $st = db()->prepare('INSERT INTO incidencias (titulo, descripcion, area, prioridad, estado)
                         VALUES (:titulo, :descripcion, :area, :prioridad, :estado)');
    $st->execute($d);
    $id = (int) db()->lastInsertId();
    invalidarCache();

    $correo = 'enviado';
    try {
        enviarCorreo(
            "Nueva incidencia #$id: {$d['titulo']}",
            "Se registró una nueva incidencia.\n\n"
            . "ID: $id\nTítulo: {$d['titulo']}\nÁrea: {$d['area']}\n"
            . "Prioridad: {$d['prioridad']}\nEstado: {$d['estado']}\n"
            . "Descripción: {$d['descripcion']}\n\nFecha: " . date('Y-m-d H:i:s')
        );
        error_log("[MAIL] Notificación enviada para incidencia #$id");
    } catch (Throwable $e) {
        error_log('[MAIL] Error: ' . $e->getMessage());
        $correo = 'fallido';
    }
    responder(201, ['mensaje' => 'Incidencia creada', 'id' => $id, 'correo' => $correo]);
}

function actualizar(int $id, array $entrada): void {
    [$d, $errores] = validar($entrada, true);
    if ($errores) responder(422, ['error' => implode('. ', $errores)]);
    if (!$d) responder(400, ['error' => 'No se enviaron campos para actualizar']);

    $existe = db()->prepare('SELECT id FROM incidencias WHERE id = ?');
    $existe->execute([$id]);
    if (!$existe->fetch()) responder(404, ['error' => 'Incidencia no encontrada']);

    $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($d)));
    $d['id'] = $id;
    db()->prepare("UPDATE incidencias SET $set WHERE id = :id")->execute($d);
    invalidarCache();
    responder(200, ['mensaje' => 'Incidencia actualizada']);
}

function eliminar(int $id): void {
    $st = db()->prepare('DELETE FROM incidencias WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) responder(404, ['error' => 'Incidencia no encontrada']);
    invalidarCache();
    responder(200, ['mensaje' => 'Incidencia eliminada']);
}

// ---------- Enrutador ----------
$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$ruta   = preg_replace('#^/api#', '', $ruta);
$ruta   = rtrim($ruta, '/') ?: '/';

try {
    if ($ruta === '/health' && $metodo === 'GET') health();

    if ($ruta === '/incidencias') {
        if ($metodo === 'GET')  listar();
        if ($metodo === 'POST') crear(leerJson());
        responder(405, ['error' => 'Método no permitido']);
    }

    if (preg_match('#^/incidencias/(\d+)$#', $ruta, $m)) {
        if ($metodo === 'PUT')    actualizar((int) $m[1], leerJson());
        if ($metodo === 'DELETE') eliminar((int) $m[1]);
        responder(405, ['error' => 'Método no permitido']);
    }

    responder(404, ['error' => 'Ruta no encontrada']);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Ya existe una incidencia con ese título en esa área']);
    }
    error_log('[DB] ' . $e->getMessage());
    responder(500, ['error' => 'Error de base de datos']);
} catch (Throwable $e) {
    error_log('[ERROR] ' . $e->getMessage());
    responder(500, ['error' => 'Error interno del servidor']);
}