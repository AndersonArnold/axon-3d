<?php
// API do site da Axon 3D para hospedagem com PHP 7.4 ou superior (sem banco de dados).
// Rotas (sempre em api.php?r=ROTA):
//   content            GET público  -> conteúdo salvo do site ({} se nada foi salvo)
//   content            POST admin   -> salva o conteúdo   (&reset=1 apaga e volta ao padrão)
//   login              POST         -> { password } => { token, exp }
//   session            GET admin    -> confirma o token
//   health             GET público  -> diagnóstico (não expõe segredo)
//   media              GET admin    -> lista a biblioteca de mídia
//   media              POST admin   -> envia imagem/vídeo (corpo binário)
//   media/ARQUIVO      POST admin   -> com &delete=1 apaga o arquivo
// As imagens e vídeos ficam em /uploads e os textos em /data/content.json.

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');

const MAX_CONTENT = 921600;            // 900 KB de JSON
const MAX_UPLOAD_WANTED = 26214400;    // 25 MB (pode ser menor, conforme a hospedagem)
const TOKEN_TTL = 43200;               // sessão de 12 horas
const DEFAULT_PASSWORD = 'troque-esta-senha';

$TYPES = [
  'image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/avif' => 'avif',
  'video/mp4' => 'mp4', 'video/webm' => 'webm',
];

function out($obj, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function ($e) {
    error_log('[axon3d] ' . $e->getMessage());
    out(['error' => 'Erro no servidor.'], 500);
});

function ini_bytes($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

function max_upload() {
    $m = MAX_UPLOAD_WANTED;
    $post = ini_bytes(ini_get('post_max_size'));
    if ($post > 0) $m = min($m, $post - 65536);
    return max($m, 0);
}

$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$PASSWORD = trim((string)(isset($cfg['password']) ? $cfg['password'] : ''));
$PASSWORD_OK = $PASSWORD !== '' && $PASSWORD !== DEFAULT_PASSWORD;
$SECRET = hash('sha256', 'axon3d|' . $PASSWORD . '|' . (isset($cfg['secret']) ? $cfg['secret'] : ''));
$DATA = __DIR__ . '/data';
$UP = __DIR__ . '/uploads';
$BASE = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api.php')), '/');

function ensure_dirs() {
    global $DATA, $UP;
    foreach ([$DATA, $UP] as $d) { if (!is_dir($d)) @mkdir($d, 0755, true); }
    $deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
    if (!is_file($DATA . '/.htaccess')) @file_put_contents($DATA . '/.htaccess', $deny);
    $noexec = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n</FilesMatch>\n";
    if (!is_file($UP . '/.htaccess')) @file_put_contents($UP . '/.htaccess', $noexec);
}

function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/')); }

function make_token() {
    global $SECRET;
    $expMs = (time() + TOKEN_TTL) * 1000;
    $body = b64u(json_encode(['exp' => $expMs]));
    return ['token' => $body . '.' . hash_hmac('sha256', $body, $SECRET), 'exp' => $expMs];
}

function authed() {
    global $SECRET, $PASSWORD_OK;
    if (!$PASSWORD_OK) return false;
    $tok = isset($_SERVER['HTTP_X_AXON_TOKEN']) ? $_SERVER['HTTP_X_AXON_TOKEN'] : '';
    $parts = explode('.', $tok);
    if (count($parts) !== 2) return false;
    if (!hash_equals(hash_hmac('sha256', $parts[0], $SECRET), $parts[1])) return false;
    $p = json_decode((string)b64u_dec($parts[0]), true);
    return is_array($p) && isset($p['exp']) && $p['exp'] > microtime(true) * 1000;
}

function require_auth() { if (!authed()) out(['error' => 'Sessão expirada ou senha incorreta. Entre novamente.'], 401); }

function sniff($head, $type) {
    switch ($type) {
        case 'image/jpeg': return substr($head, 0, 2) === "\xFF\xD8";
        case 'image/png':  return substr($head, 0, 8) === "\x89PNG\r\n\x1a\n";
        case 'image/webp': return substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
        case 'image/avif':
        case 'video/mp4':  return substr($head, 4, 4) === 'ftyp';
        case 'video/webm': return substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
    }
    return false;
}

function idx_read() {
    global $DATA;
    $f = $DATA . '/media.json';
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function idx_write($a) {
    global $DATA;
    file_put_contents($DATA . '/media.json', json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

ensure_dirs();

$route = trim(isset($_GET['r']) ? (string)$_GET['r'] : '', '/');
$parts = explode('/', $route, 2);
$root = $parts[0];
$file = isset($parts[1]) ? $parts[1] : '';
$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

// ---------- diagnóstico ----------
if ($root === 'health') {
    out(['ok' => true, 'passwordSet' => $PASSWORD_OK, 'maxUploadMB' => (int)floor(max_upload() / 1048576), 'php' => PHP_VERSION,
         'dataWritable' => is_writable($DATA), 'uploadsWritable' => is_writable($UP)]);
}

// ---------- login ----------
if ($root === 'login' && $method === 'POST') {
    if (!$PASSWORD_OK) out(['error' => 'Defina a senha do painel no arquivo config.php (troque "' . DEFAULT_PASSWORD . '").'], 500);
    $b = json_decode((string)file_get_contents('php://input'), true);
    $given = is_array($b) && isset($b['password']) && is_string($b['password']) ? trim($b['password']) : '';
    if (!hash_equals(hash('sha256', $PASSWORD), hash('sha256', $given))) { usleep(700000); out(['error' => 'Senha incorreta.'], 401); }
    out(make_token());
}

if ($root === 'session' && $method === 'GET') { require_auth(); out(['ok' => true]); }

// ---------- conteúdo ----------
if ($root === 'content') {
    $f = $DATA . '/content.json';
    if ($method === 'GET') {
        $raw = is_file($f) ? (string)file_get_contents($f) : '';
        $j = json_decode($raw, true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo is_array($j) ? $raw : '{}';
        exit;
    }
    require_auth();
    if (($method === 'POST' && isset($_GET['reset'])) || $method === 'DELETE') {
        if (is_file($f)) @unlink($f);
        out(['ok' => true]);
    }
    if ($method === 'POST' || $method === 'PUT') {
        $body = (string)file_get_contents('php://input');
        if (strlen($body) > MAX_CONTENT) out(['error' => 'Conteúdo grande demais.'], 413);
        $obj = json_decode($body, true);
        if (!is_array($obj) || $obj === [] || isset($obj[0])) out(['error' => 'Formato inválido.'], 400);
        $tmp = $f . '.tmp';
        if (file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $f)) out(['error' => 'Não foi possível gravar. Verifique a permissão da pasta data.'], 500);
        out(['ok' => true]);
    }
}

// ---------- mídia ----------
if ($root === 'media') {
    require_auth();

    if ($method === 'GET') {
        $idx = idx_read(); $items = [];
        foreach ($idx as $name => $m) {
            if (!is_file($UP . '/' . $name)) continue;
            $items[] = ['url' => $BASE . '/uploads/' . $name, 'file' => $name, 'name' => isset($m['name']) ? $m['name'] : $name,
                        'type' => isset($m['type']) ? $m['type'] : '', 'size' => isset($m['size']) ? $m['size'] : 0, 'at' => isset($m['at']) ? $m['at'] : 0];
        }
        usort($items, function ($a, $b) { return $b['at'] <=> $a['at']; });
        out(['items' => $items]);
    }

    if ($method === 'POST' && $file !== '' && isset($_GET['delete'])) {
        if (!preg_match('/^[\w.\-]+$/', $file)) out(['error' => 'Arquivo inválido.'], 400);
        @unlink($UP . '/' . $file);
        $idx = idx_read(); unset($idx[$file]); idx_write($idx);
        out(['ok' => true]);
    }

    if ($method === 'POST') {
        $type = strtolower(trim(explode(';', isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '')[0]));
        if (!isset($TYPES[$type])) out(['error' => 'Formato não aceito. Use JPG, PNG, WebP, AVIF, MP4 ou WebM.'], 415);
        $max = max_upload();
        $len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($len > $max) out(['error' => 'Arquivo maior que o limite desta hospedagem (' . floor($max / 1048576) . ' MB).'], 413);
        $ext = $TYPES[$type];
        $id = dechex(time()) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $tmp = $UP . '/.up-' . bin2hex(random_bytes(4));
        $in = fopen('php://input', 'rb'); $outf = fopen($tmp, 'wb');
        if (!$in || !$outf) out(['error' => 'Não foi possível gravar. Verifique a permissão da pasta uploads.'], 500);
        $size = stream_copy_to_stream($in, $outf, $max + 1);
        fclose($in); fclose($outf);
        if (!$size) { @unlink($tmp); out(['error' => 'Arquivo vazio ou maior que o limite da hospedagem.'], 400); }
        if ($size > $max) { @unlink($tmp); out(['error' => 'Arquivo maior que ' . floor($max / 1048576) . ' MB.'], 413); }
        $h = fopen($tmp, 'rb'); $head = (string)fread($h, 16); fclose($h);
        if (!sniff($head, $type)) { @unlink($tmp); out(['error' => 'O conteúdo do arquivo não confere com o formato.'], 400); }
        if (!@rename($tmp, $UP . '/' . $id)) { @unlink($tmp); out(['error' => 'Não foi possível gravar. Verifique a permissão da pasta uploads.'], 500); }
        @chmod($UP . '/' . $id, 0644);
        $name = 'arquivo.' . $ext;
        if (isset($_SERVER['HTTP_X_FILENAME'])) $name = rawurldecode($_SERVER['HTTP_X_FILENAME']);
        $name = substr(preg_replace('/[^\w.\- ]+/u', '_', $name), 0, 80);
        $idx = idx_read(); $idx[$id] = ['name' => $name, 'type' => $type, 'size' => (int)$size, 'at' => (int)(microtime(true) * 1000)]; idx_write($idx);
        out(['ok' => true, 'url' => $BASE . '/uploads/' . $id, 'file' => $id]);
    }
}

out(['error' => 'Rota não encontrada.'], 404);
