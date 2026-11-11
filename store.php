<?php
declare(strict_types=1);

/**
 * store.php 2.2 – speichert Single-File-TiddlyWikis auf reinen PHP-Webspaces.
 *
 * Nachfolger der BidiX store.php 1.6.1 (2007). Läuft mit PHP 8.0 oder neuer (getestet mit 8.3).
 *
 * Unterstützte Wege zum Speichern:
 *
 *  1. TiddlyWiki5 "upload"-Saver (und klassisches UploadPlugin)
 *     POST multipart/form-data
 *       UploadPlugin = "backupDir=<dir>;user=<user>;password=<pw>;uploaddir=<dir>;;"
 *       userfile     = <die Wiki-Datei>
 *     Antwort (immer HTTP 200, text/plain): beginnt mit "0 - " bei Erfolg.
 *
 *  2. TiddlyWiki5 "put"-Saver (WebDAV-artig), wenn das Wiki über dieses Skript
 *     aufgerufen wird:  https://example.org/wiki/store.php?wiki=index.html
 *       GET/HEAD  liefert das Wiki mit ETag aus
 *       OPTIONS   meldet "DAV: 1", damit TiddlyWiki den PUT-Saver aktiviert
 *       PUT       speichert (HTTP Basic Auth, If-Match/ETag-Konfliktschutz)
 *
 * Benutzer und Passwort-Hashes stehen in auth.php (siehe auth.example.php).
 * Hash erzeugen:  store.php?hash im Browser  oder  php store.php hash
 *
 * Lizenz: BSD 3-Clause, siehe LICENSE – https://github.com/lasjoh-toho/store_TW
 * Neufassung der store.php 1.6.1 von BidiX (BidiX@BidiX.info, 2005–2007).
 */

/* ===================================================================
 * Konfiguration
 * Wer die Einstellungen nicht hier ändern möchte, kann eine Datei
 * store-config.php danebenlegen, die ein Array zurückgibt, z. B.
 *   <?php return ['enable_put' => false];
 * =================================================================== */
$CONFIG = [
    // Alle Wikis und Backups müssen unterhalb dieses Ordners liegen.
    'base_dir'           => __DIR__,

    // Erlaubte Dateiendungen der Wiki-Dateien (niemals php o. ä. eintragen!).
    'allowed_extensions' => ['html', 'htm'],

    // Nur Dateien annehmen, die wie ein TiddlyWiki aussehen.
    'require_tiddlywiki' => true,

    // Maximale Größe einer Wiki-Datei in Bytes (zusätzlich gelten die
    // PHP-Grenzen upload_max_filesize und post_max_size).
    'max_size'           => 100 * 1024 * 1024,

    // Backup-Ordner: null = den Wert verwenden, den TiddlyWiki schickt
    // ($:/UploadBackupDir, Standard "."). Ein String erzwingt einen festen
    // Ordner (relativ zu base_dir), '' schaltet Backups ab.
    'backup_dir'         => null,

    // Backup-Ordner für PUT-Speicherungen (relativ zu base_dir), '' = keine.
    'put_backup_dir'     => 'backups',

    // Backups ausdünnen wie die alte Version: alle aus der aktuellen Stunde,
    // je eines pro Stunde von heute, pro Tag dieses Monats, pro Monat davor.
    'thin_backups'       => true,

    // Zusätzlich höchstens so viele Backups je Wiki behalten (0 = unbegrenzt).
    'max_backups'        => 100,

    // PUT-Saver (WebDAV-artig) erlauben.
    'enable_put'         => true,

    // Auch zum Lesen des Wikis über store.php?wiki=... anmelden lassen.
    'read_requires_auth' => false,

    // Hash-Generator unter store.php?hash anbieten. Nach der Einrichtung
    // kann man ihn abschalten.
    'hash_tool'          => true,

    // Upload in Stücken (für das Plugin "Chunked Upload Saver"), falls der
    // Provider große Anfragen ablehnt ("413 Request Entity Too Large").
    'enable_chunks'      => true,

    // Größe eines Stücks in Bytes. Wird automatisch kleiner, wenn die
    // PHP-Grenzen niedriger sind.
    'chunk_size'         => 1024 * 1024,

    // Prüfseite unter store.php?check (Server-Einstellungen + Test-Upload).
    'check_tool'         => true,

    // Andere Origins, die per POST speichern dürfen (z. B. 'null' für ein
    // lokal per file:// geöffnetes Wiki). Leer = nur gleiche Domain.
    'cors_origins'       => [],

    // Sekunden Wartezeit nach einem falschen Passwort (Bremse gegen Raten).
    'auth_fail_delay'    => 2,

    // Name des Realms für HTTP Basic Auth.
    'realm'              => 'TiddlyWiki',

    // Zeitzone für die Zeitstempel in den Backup-Namen.
    'timezone'           => 'Europe/Berlin',
];

const STORE_VERSION = '2.2.0';

if (is_file(__DIR__ . '/store-config.php')) {
    $override = require __DIR__ . '/store-config.php';
    if (is_array($override)) {
        $CONFIG = array_replace($CONFIG, $override);
    }
}

/* ===================================================================
 * Laufzeit-Grundlagen: keine Warnungen in die Antwort mischen
 * (genau daran ist die alte Version unter PHP 8 gescheitert).
 * =================================================================== */
ini_set('display_errors', '0');
if (!@date_default_timezone_set((string) $CONFIG['timezone'])) {
    date_default_timezone_set('UTC');
}
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    error_log("store.php: $msg in $file:$line");
    return true;
});

final class StoreError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

/* ===================================================================
 * CLI: php store.php hash
 * =================================================================== */
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'hash') {
        fwrite(STDOUT, 'Passwort: ');
        $pw = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDOUT, password_hash($pw, PASSWORD_DEFAULT) . PHP_EOL);
        exit(0);
    }
    fwrite(STDOUT, "store.php " . STORE_VERSION . "\nAufruf: php store.php hash\n");
    exit(0);
}

/* ===================================================================
 * Hilfsfunktionen
 * =================================================================== */

function base_dir(): string
{
    global $CONFIG;
    $base = realpath($CONFIG['base_dir']);
    if ($base === false || !is_dir($base)) {
        throw new StoreError('base_dir existiert nicht', 500);
    }
    return $base;
}

/**
 * Prüft einen relativen Ordnerpfad und liefert den absoluten Pfad unterhalb
 * von base_dir. Legt den Ordner auf Wunsch an.
 */
function safe_dir(string $rel, bool $create): string
{
    $base = base_dir();
    $rel = str_replace('\\', '/', trim($rel));
    if (str_contains($rel, "\0")) {
        throw new StoreError('Ungültiger Ordnername');
    }
    $parts = [];
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..' || !preg_match('/^[\p{L}\p{N}_\-. ]+$/u', $part) || $part[0] === '.') {
            throw new StoreError("Ungültiger Ordnername: $rel");
        }
        $parts[] = $part;
    }
    $dir = $base . ($parts ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) : '');
    if (!is_dir($dir)) {
        if (!$create || !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StoreError("Ordner kann nicht angelegt werden: $rel", 500);
        }
    }
    // Symlinks dürfen nicht aus base_dir herausführen.
    $real = realpath($dir);
    if ($real === false || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR))) {
        throw new StoreError("Ordner liegt außerhalb des erlaubten Bereichs: $rel");
    }
    return $real;
}

/** Prüft einen Dateinamen (ohne Pfad) auf Zeichen und erlaubte Endung. */
function safe_filename(string $name): string
{
    global $CONFIG;
    $name = trim($name);
    if (
        $name === '' || strlen($name) > 200 || $name[0] === '.'
        || !preg_match('/^[\p{L}\p{N}_\-. ]+$/u', $name)
    ) {
        throw new StoreError("Ungültiger Dateiname: $name");
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = array_map('strtolower', $CONFIG['allowed_extensions']);
    if (!in_array($ext, $allowed, true)) {
        throw new StoreError("Dateiendung nicht erlaubt: .$ext (erlaubt: " . implode(', ', $allowed) . ')');
    }
    return $name;
}

/** Zerlegt "unter/ordner/datei.html" in sicheren Ordner + Dateinamen. */
function safe_wiki_path(string $rel, bool $createDir): string
{
    $rel = str_replace('\\', '/', $rel);
    $pos = strrpos($rel, '/');
    $dir = $pos === false ? '' : substr($rel, 0, $pos);
    $file = $pos === false ? $rel : substr($rel, $pos + 1);
    return safe_dir($dir, $createDir) . DIRECTORY_SEPARATOR . safe_filename($file);
}

function load_users(): array
{
    $file = __DIR__ . '/auth.php';
    if (!is_file($file)) {
        throw new StoreError('auth.php fehlt – bitte nach Anleitung anlegen', 500);
    }
    $USERS = [];
    $ret = include $file;
    if (is_array($ret)) {
        $USERS = $ret;
    }
    return is_array($USERS) ? $USERS : [];
}

function check_credentials(string $user, string $password): bool
{
    global $CONFIG;
    $users = load_users();
    // Unbekannte Benutzer kosten genauso viel Zeit wie bekannte.
    $hash = $users[$user] ?? '$2y$10$65z1rpZhP5hvlk178blB0O/AD.4t6yKu/z5Vnr3wKrvIWaBfHKl2e';
    $info = password_get_info($hash);
    $ok = $user !== '' && $password !== '' && isset($users[$user])
        && $info['algo'] !== null && $info['algo'] !== 0
        && password_verify($password, $hash);
    if (!$ok) {
        sleep((int) $CONFIG['auth_fail_delay']);
        foreach ($users as $h) {
            $i = password_get_info((string) $h);
            if ($i['algo'] === null || $i['algo'] === 0) {
                throw new StoreError('UserName or Password do not match – auth.php enthält noch '
                    . 'Klartext-Passwörter, bitte durch Hashes ersetzen (store.php?hash)', 403);
            }
        }
    }
    return $ok;
}

/** Liest HTTP Basic Auth, auch hinter CGI/FastCGI. */
function basic_auth(): ?array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'basic ') === 0) {
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            return explode(':', $decoded, 2);
        }
    }
    return null;
}

function require_basic_auth(): void
{
    global $CONFIG;
    $cred = basic_auth();
    if ($cred === null || !check_credentials($cred[0], $cred[1])) {
        header('WWW-Authenticate: Basic realm="' . addslashes($CONFIG['realm']) . '", charset="UTF-8"');
        throw new StoreError('Anmeldung erforderlich', 401);
    }
}

function looks_like_tiddlywiki(string $path): bool
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = (string) fread($fh, 256 * 1024);
    fclose($fh);
    return stripos($head, 'tiddlywiki') !== false;
}

function etag_for(string $path): string
{
    clearstatcache(true, $path);
    return '"' . hash_file('sha256', $path) . '"';
}

/** Vergleicht ETags tolerant (W/-Präfix, -gzip/-br-Suffix von mod_deflate). */
function etag_matches(string $ifMatch, string $etag): bool
{
    $norm = static fn(string $t): string =>
        preg_replace('/-(gzip|br|deflate|zstd)$/', '', trim(preg_replace('/^W\//', '', trim($t)), '"'));
    foreach (explode(',', $ifMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*' || $norm($candidate) === $norm($etag)) {
            return true;
        }
    }
    return false;
}

/**
 * Ersetzt $dest atomar durch $tmp und sichert die alte Fassung vorher.
 * Liefert den Pfad des Backups oder ''.
 */
function replace_with_backup(string $tmp, string $dest, ?string $backupDir, bool $locked = false): string
{
    $backup = '';
    $lock = $locked ? false : fopen(wiki_lock_path($dest), 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    try {
        if ($backupDir !== null && is_file($dest)) {
            $name = basename($dest);
            $dot = strrpos($name, '.');
            $stem = $dot === false ? $name : substr($name, 0, $dot);
            $ext = $dot === false ? '' : substr($name, $dot);
            // Zeitpunkt, zu dem diese (alte) Fassung gespeichert wurde,
            // z. B. index.2026-10-31_20-44-12.html
            clearstatcache(true, $dest);
            $stamp = date('.Y-m-d_H-i-s', filemtime($dest) ?: time());
            $backup = $backupDir . DIRECTORY_SEPARATOR . $stem . $stamp . $ext;
            for ($i = 1; file_exists($backup); $i++) {
                $backup = $backupDir . DIRECTORY_SEPARATOR . $stem . $stamp . "-$i" . $ext;
            }
            if (!copy($dest, $backup)) {
                throw new StoreError('Backup konnte nicht geschrieben werden – Datei NICHT gespeichert', 500);
            }
            @chmod($backup, 0644);
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $dest)) {
            throw new StoreError('Datei konnte nicht ersetzt werden – Schreibrechte prüfen', 500);
        }
        if ($backup !== '') {
            clean_backups($backupDir, basename($dest));
        }
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
    return $backup;
}

/**
 * Sperrdatei eines Wikis (im geschützten Ordner .store-chunks, bleibt liegen:
 * gelöschte Sperrdateien würden gleichzeitige Speichervorgänge nicht mehr trennen).
 */
function wiki_lock_path(string $dest): string
{
    return chunk_dir() . DIRECTORY_SEPARATOR . 'lock-' . substr(sha1($dest), 0, 16);
}

/** Dünnt Backups aus (Logik wie in Version 1.6.1) und begrenzt die Anzahl. */
function clean_backups(string $dir, string $wikiName): void
{
    global $CONFIG;
    $dot = strrpos($wikiName, '.');
    $stem = $dot === false ? $wikiName : substr($wikiName, 0, $dot);
    $ext = $dot === false ? '' : substr($wikiName, $dot);
    // Neues Format name.2026-10-31_20-44-12.html und altes name.20261031.204412.html
    $pattern = '/^' . preg_quote($stem, '/')
        . '\.(\d{4})-?(\d{2})-?(\d{2})[._](\d{2})-?(\d{2})-?(\d{2})(?:-\d+)?'
        . preg_quote($ext, '/') . '$/';

    $files = [];
    foreach (scandir($dir) ?: [] as $f) {
        if (preg_match($pattern, $f, $m)) {
            $files[$f] = $m[1] . $m[2] . $m[3] . $m[4] . $m[5] . $m[6];
        }
    }
    arsort($files, SORT_STRING); // neueste zuerst
    $now = date('YmdH');
    $delete = [];
    $seen = [];
    if ($CONFIG['thin_backups']) {
        foreach ($files as $f => $ts) {
            if (substr($ts, 0, 10) === $now) {
                continue;                                   // aktuelle Stunde: alle
            } elseif (substr($ts, 0, 8) === substr($now, 0, 8)) {
                $key = 'h' . substr($ts, 0, 10);            // heute: eins pro Stunde
            } elseif (substr($ts, 0, 6) === substr($now, 0, 6)) {
                $key = 'd' . substr($ts, 0, 8);             // dieser Monat: eins pro Tag
            } else {
                $key = 'm' . substr($ts, 0, 6);             // älter: eins pro Monat
            }
            if (isset($seen[$key])) {
                $delete[$f] = true;
            } else {
                $seen[$key] = true;
            }
        }
    }
    $max = (int) $CONFIG['max_backups'];
    if ($max > 0) {
        $kept = array_values(array_diff(array_keys($files), array_keys($delete)));
        foreach (array_slice($kept, $max) as $f) {
            $delete[$f] = true;
        }
    }
    foreach (array_keys($delete) as $f) {
        @unlink($dir . DIRECTORY_SEPARATOR . $f);
    }
}

function rel_path(string $abs): string
{
    $base = base_dir();
    return ltrim(str_replace('\\', '/', substr($abs, strlen($base))), '/');
}

function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '') {
        return 0;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

function send_cors(): void
{
    global $CONFIG;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $CONFIG['cors_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, HEAD, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, If-Match, X-Store-Auth');
        header('Access-Control-Expose-Headers: ETag');
        header('Vary: Origin');
    }
}

/* ===================================================================
 * 1. Upload (POST multipart) – TiddlyWiki5 "upload"-Saver
 * =================================================================== */

/** Liest das UploadPlugin-Feld; Passwörter dürfen auch ";" enthalten. */
function parse_upload_options(string $raw): array
{
    $raw = trim($raw);
    $opts = [];
    if (preg_match('/^backupDir=(.*?);user=(.*?);password=(.*);uploaddir=(.*?);;?$/s', $raw, $m)) {
        $opts = ['backupDir' => $m[1], 'user' => $m[2], 'password' => $m[3], 'uploaddir' => $m[4]];
    } else {
        foreach (explode(';', $raw) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $opts[trim($k)] = $v;
        }
    }
    return $opts + ['backupDir' => '', 'user' => '', 'password' => '', 'uploaddir' => ''];
}

function handle_upload(): string
{
    global $CONFIG;

    if (!ini_get('file_uploads')) {
        throw new StoreError('file_uploads ist in der PHP-Konfiguration abgeschaltet', 500);
    }
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_bytes('post_max_size');
    if ($postMax > 0 && $len > $postMax) {
        throw new StoreError(sprintf(
            'Wiki ist zu groß (%.1f MB). PHP erlaubt post_max_size=%s – Wert beim Provider erhöhen',
            $len / 1048576, ini_get('post_max_size')
        ), 413);
    }

    $opts = parse_upload_options((string) ($_POST['UploadPlugin'] ?? ''));

    // Anmeldung: Felder aus dem Formular, sonst HTTP Basic Auth
    // (für $:/UploadWithUrlOnly = yes).
    if ($opts['user'] === '' && $opts['password'] === '') {
        require_basic_auth();
    } elseif (!check_credentials($opts['user'], $opts['password'])) {
        throw new StoreError('UserName or Password do not match', 403);
    }

    $file = $_FILES['userfile'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        throw new StoreError('Keine Datei im Feld "userfile" empfangen');
    }
    $err = (int) $file['error'];
    if ($err !== UPLOAD_ERR_OK) {
        throw new StoreError(match ($err) {
            UPLOAD_ERR_INI_SIZE => 'Wiki ist größer als upload_max_filesize=' . ini_get('upload_max_filesize')
                . ' – Wert beim Provider erhöhen',
            UPLOAD_ERR_FORM_SIZE => 'Wiki ist zu groß',
            UPLOAD_ERR_PARTIAL => 'Upload wurde nur teilweise empfangen',
            UPLOAD_ERR_NO_FILE => 'Keine Datei empfangen',
            UPLOAD_ERR_NO_TMP_DIR => 'Kein temporärer Ordner auf dem Server (upload_tmp_dir)',
            UPLOAD_ERR_CANT_WRITE => 'Temporäre Datei konnte nicht geschrieben werden',
            UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload gestoppt',
            default => "Upload-Fehler $err",
        }, 500);
    }
    if ((int) $file['size'] > (int) $CONFIG['max_size']) {
        throw new StoreError('Wiki ist größer als max_size in store.php', 413);
    }

    $name = safe_filename(basename(str_replace('\\', '/', (string) $file['name'])));
    $dir = safe_dir($opts['uploaddir'], true);
    $dest = $dir . DIRECTORY_SEPARATOR . $name;

    if ($CONFIG['require_tiddlywiki'] && !looks_like_tiddlywiki((string) $file['tmp_name'])) {
        throw new StoreError('Die Datei sieht nicht wie ein TiddlyWiki aus');
    }

    $backupDir = null;
    $wanted = $CONFIG['backup_dir'] ?? $opts['backupDir'];
    if ($wanted !== '') {
        $backupDir = safe_dir((string) $wanted, true);
    }

    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (!move_uploaded_file((string) $file['tmp_name'], $tmp)) {
        throw new StoreError('Hochgeladene Datei konnte nicht verschoben werden – Schreibrechte prüfen', 500);
    }
    $backup = replace_with_backup($tmp, $dest, $backupDir);

    $out = '0 - File successfully loaded in ' . rel_path($dest) . "\n";
    $out .= 'destfile:' . rel_path($dest) . "\n";
    if ($backup !== '') {
        $out .= 'backupfile:' . rel_path($backup) . "\n";
    }
    clearstatcache(true, $dest);
    $out .= 'mtime:' . filemtime($dest);
    return $out;
}

/* ===================================================================
 * 2. WebDAV-artiger Zugriff über store.php?wiki=datei.html
 * =================================================================== */

function handle_wiki(string $method, string $rel): void
{
    global $CONFIG;

    if ($method === 'OPTIONS') {
        header('Allow: OPTIONS, GET, HEAD' . ($CONFIG['enable_put'] ? ', PUT' : ''));
        if ($CONFIG['enable_put']) {
            header('DAV: 1');
        }
        header('Content-Length: 0');
        http_response_code(200);
        return;
    }

    if ($method === 'GET' || $method === 'HEAD') {
        if ($CONFIG['read_requires_auth']) {
            require_basic_auth();
        }
        $path = safe_wiki_path($rel, false);
        if (!is_file($path)) {
            throw new StoreError('Wiki nicht gefunden', 404);
        }
        $etag = etag_for($path);
        header('Content-Type: text/html; charset=utf-8');
        header('ETag: ' . $etag);
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        if ($CONFIG['enable_put']) {
            header('DAV: 1');
        }
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '' && etag_matches($inm, $etag)) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($path));
        if ($method === 'GET') {
            readfile($path);
        }
        return;
    }

    if ($method === 'PUT') {
        if (!$CONFIG['enable_put']) {
            throw new StoreError('PUT ist abgeschaltet', 405);
        }
        require_basic_auth();
        $dest = safe_wiki_path($rel, true);
        $dir = dirname($dest);

        if (is_file($dest)) {
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? '';
            if ($ifMatch !== '' && !etag_matches($ifMatch, etag_for($dest))) {
                throw new StoreError('Das Wiki wurde inzwischen woanders geändert', 412);
            }
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($dest) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            throw new StoreError('Temporäre Datei konnte nicht angelegt werden – Schreibrechte prüfen', 500);
        }
        $size = 0;
        $max = (int) $CONFIG['max_size'];
        while (!feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            if ($size > $max) {
                fclose($out);
                @unlink($tmp);
                throw new StoreError('Wiki ist größer als max_size in store.php', 413);
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);

        if ($size === 0 || ($CONFIG['require_tiddlywiki'] && !looks_like_tiddlywiki($tmp))) {
            @unlink($tmp);
            throw new StoreError('Die Datei sieht nicht wie ein TiddlyWiki aus');
        }

        $backupDir = $CONFIG['put_backup_dir'] !== '' ? safe_dir((string) $CONFIG['put_backup_dir'], true) : null;
        $existed = is_file($dest);
        replace_with_backup($tmp, $dest, $backupDir);

        header('ETag: ' . etag_for($dest));
        http_response_code($existed ? 204 : 201);
        return;
    }

    header('Allow: OPTIONS, GET, HEAD' . ($CONFIG['enable_put'] ? ', PUT' : ''));
    throw new StoreError('Methode nicht erlaubt', 405);
}

/* ===================================================================
 * 3. Upload in Stücken – für das TiddlyWiki-Plugin "Chunked Upload Saver"
 *    POST store.php?chunk=begin|part|finish, Anmeldung im Header
 *    X-Store-Auth: base64("benutzer:passwort"), Antworten als JSON.
 * =================================================================== */

function chunk_dir(): string
{
    $dir = base_dir() . DIRECTORY_SEPARATOR . '.store-chunks';
    if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) {
        throw new StoreError('Ordner .store-chunks kann nicht angelegt werden – Schreibrechte prüfen', 500);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function chunk_user(): string
{
    $raw = base64_decode((string) ($_SERVER['HTTP_X_STORE_AUTH'] ?? ''), true);
    $cred = ($raw !== false && str_contains($raw, ':')) ? explode(':', $raw, 2) : basic_auth();
    if ($cred === null || !check_credentials((string) $cred[0], (string) $cred[1])) {
        throw new StoreError('UserName or Password do not match', 403);
    }
    return (string) $cred[0];
}

function chunk_size_limit(): int
{
    global $CONFIG;
    $size = (int) $CONFIG['chunk_size'];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $limit = ini_bytes($key);
        if ($limit > 0) {
            $size = min($size, $limit - 64 * 1024);
        }
    }
    return max($size, 64 * 1024);
}

const STORE_START = '<script class="tiddlywiki-tiddler-store" type="application/json">[';

/**
 * Zerlegt ein TiddlyWiki in Kopf, Tiddler-Zeilen und Rest. Liefert null, wenn
 * die Datei nicht genau dem bekannten Aufbau entspricht.
 */
function split_store(string $html): ?array
{
    $i = strpos($html, STORE_START);
    if ($i === false || strpos($html, STORE_START, $i + 1) !== false) {
        return null;
    }
    $b = $i + strlen(STORE_START);
    $j = strpos($html, ']</script>', $b);
    if ($j === false) {
        return null;
    }
    $body = substr($html, $b, $j - $b);
    if (strlen($body) < 2 || $body[0] !== "\n" || $body[strlen($body) - 1] !== "\n") {
        return null;
    }
    return [substr($html, 0, $b), explode(",\n", substr($body, 1, -1)), substr($html, $j)];
}

/**
 * Wendet eine Änderungsliste ("nur geänderte Tiddler") auf das Wiki auf dem
 * Server an und schreibt das Ergebnis nach $tmp. Es wird nur gespeichert, wenn
 * das Ergebnis Byte für Byte dem entspricht, was TiddlyWiki selbst erzeugt hätte
 * (Prüfsumme), sonst schickt das Plugin das ganze Wiki.
 */
function apply_patch(string $tmp, string $dest): void
{
    $patch = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($patch) || !is_array($patch['ops'] ?? null)) {
        throw new StoreError('Änderungsliste ist ungültig', 422);
    }
    $current = is_file($dest) ? (string) file_get_contents($dest) : '';
    if ($current === '' || !hash_equals(hash('sha256', $current), (string) ($patch['base'] ?? ''))) {
        throw new StoreError('Wiki auf dem Server ist nicht der erwartete Stand', 409);
    }
    $parts = split_store($current);
    unset($current);
    if ($parts === null) {
        throw new StoreError('Wiki auf dem Server hat einen unbekannten Aufbau', 409);
    }
    [$head, $lines, $tail] = $parts;
    $new = [];
    foreach ($patch['ops'] as $op) {
        if (is_array($op) && ($op[0] ?? null) === 'k' && is_int($op[1] ?? null) && is_int($op[2] ?? null)
            && $op[1] >= 0 && $op[2] > 0 && $op[1] + $op[2] <= count($lines)) {
            array_push($new, ...array_slice($lines, $op[1], $op[2]));
        } elseif (is_array($op) && ($op[0] ?? null) === 'n' && is_string($op[1] ?? null)) {
            $new[] = $op[1];
        } else {
            throw new StoreError('Änderungsliste ist ungültig', 422);
        }
    }
    $out = (is_string($patch['head'] ?? null) ? $patch['head'] : $head) . "\n" . implode(",\n", $new)
        . "\n" . (is_string($patch['tail'] ?? null) ? $patch['tail'] : $tail);
    if (!hash_equals(hash('sha256', $out), (string) ($patch['result'] ?? ''))) {
        throw new StoreError('Ergebnis stimmt nicht mit dem Wiki im Browser überein', 409);
    }
    if (file_put_contents($tmp, $out) !== strlen($out)) {
        throw new StoreError('Temporäre Datei konnte nicht geschrieben werden', 500);
    }
}

/** Kurze Prüfsumme einer Tiddler-Zeile (16 Hex-Zeichen von SHA-256), wie im Plugin. */
function line_hash(string $line): string
{
    return substr(hash('sha256', $line), 0, 16);
}

/** Titel einer Tiddler-Zeile oder null. */
function line_title(string $line): ?string
{
    $t = json_decode($line, true);
    return is_array($t) && is_string($t['title'] ?? null) ? $t['title'] : null;
}

/** Stand der Datei auf dem Server (SHA-256 des ganzen Wikis, '' = gibt es nicht). */
function wiki_version(string $path): string
{
    clearstatcache(true, $path);
    return is_file($path) ? hash_file('sha256', $path) : '';
}

/**
 * Speichern pro Tiddler (Modus "merge", ab 2.2): Die Änderungen des Browsers
 * werden in den AKTUELLEN Stand auf dem Server eingebaut. Was andere inzwischen
 * gespeichert haben, bleibt erhalten.
 *
 *   {"set": [{"title", "line", "prev"}], "del": [{"title", "prev"}], "head"?, "tail"?}
 *
 * "prev" ist die Prüfsumme (line_hash) der Zeile, die der Browser vorher kannte
 * ('' = Tiddler war ihm neu). Hat jemand anders denselben Tiddler inzwischen
 * geändert, bleibt dessen Fassung stehen und die des Browsers kommt als Kopie
 * "<Titel> (Konflikt <Zeit>)" dazu. Gelöscht wird nur, was niemand geändert hat.
 * Schreibt das Ergebnis nach $tmp; muss unter der Sperre des Wikis laufen.
 */
function apply_merge(string $tmp, string $dest): array
{
    $m = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($m) || !is_array($m['set'] ?? []) || !is_array($m['del'] ?? [])) {
        throw new StoreError('Änderungsliste ist ungültig', 422);
    }
    clearstatcache(true, $dest);
    $current = is_file($dest) ? (string) file_get_contents($dest) : '';
    if ($current === '') {
        throw new StoreError('Auf dem Server liegt noch kein Wiki – einmal ganz hochladen', 409);
    }
    $before = hash('sha256', $current);
    $parts = split_store($current);
    unset($current);
    if ($parts === null) {
        throw new StoreError('Wiki auf dem Server hat einen unbekannten Aufbau', 409);
    }
    [$head, $lines, $tail] = $parts;
    if (count($lines) === 1 && $lines[0] === '') {
        $lines = [];
    }
    $index = [];
    foreach ($lines as $i => $line) {
        $t = line_title($line);
        if ($t !== null) {
            $index[$t] = $i;
        }
    }
    $stamp = date('Y-m-d H:i');
    $conflicts = [];
    $kept = [];
    $changed = 0;
    $copyOf = static function (string $title, string $line) use (&$index, &$lines, $stamp): string {
        $copy = "$title (Konflikt $stamp)";
        for ($n = 2; isset($index[$copy]); $n++) {
            $copy = "$title (Konflikt $stamp, $n)";
        }
        $t = json_decode($line, true);
        $t['title'] = $copy;
        $lines[] = str_replace('<', '\u003C', (string) json_encode($t, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $index[$copy] = count($lines) - 1;
        return $copy;
    };
    foreach ($m['set'] ?? [] as $s) {
        $title = is_array($s) ? ($s['title'] ?? null) : null;
        $line = is_array($s) ? ($s['line'] ?? null) : null;
        $prev = is_array($s) ? (string) ($s['prev'] ?? '') : '';
        if (!is_string($title) || !is_string($line) || line_title($line) !== $title) {
            throw new StoreError('Änderungsliste ist ungültig', 422);
        }
        if (isset($index[$title])) {
            $cur = $lines[$index[$title]];
            if ($cur === $line) {
                continue;
            }
            if (($prev !== '' && hash_equals(line_hash($cur), $prev)) || str_starts_with($title, '$:/')) {
                // Unverändert seit dem Stand des Browsers – oder ein System-Tiddler
                // (Einstellungen, Plugins): da hilft keine Kopie, die neueste Fassung gilt.
                $lines[$index[$title]] = $line;
                $changed++;
            } else {
                // Inzwischen von jemand anderem geändert (oder neu angelegt).
                $conflicts[] = ['title' => $title, 'copy' => $copyOf($title, $line)];
                $changed++;
            }
        } else {
            // Neu – oder auf dem Server inzwischen gelöscht, hier aber bearbeitet: wieder anlegen.
            $lines[] = $line;
            $index[$title] = count($lines) - 1;
            $changed++;
        }
    }
    $drop = [];
    foreach ($m['del'] ?? [] as $d) {
        $title = is_array($d) ? ($d['title'] ?? null) : null;
        if (!is_string($title)) {
            throw new StoreError('Änderungsliste ist ungültig', 422);
        }
        if (!isset($index[$title])) {
            continue;
        }
        if (hash_equals(line_hash($lines[$index[$title]]), (string) ($d['prev'] ?? ''))) {
            $drop[$index[$title]] = true;
            $changed++;
        } else {
            $kept[] = $title; // inzwischen geändert: bleibt
        }
    }
    if ($drop) {
        $lines = array_values(array_filter($lines, static fn($i) => !isset($drop[$i]), ARRAY_FILTER_USE_KEY));
    }
    foreach (['head', 'tail'] as $k) {
        if (is_string($m[$k] ?? null) && $m[$k] !== $$k) {
            $$k = $m[$k];
            $changed++;
        }
    }
    $out = $head . "\n" . implode(",\n", $lines) . "\n" . $tail;
    if (file_put_contents($tmp, $out) !== strlen($out)) {
        throw new StoreError('Temporäre Datei konnte nicht geschrieben werden', 500);
    }
    return ['before' => $before, 'conflicts' => $conflicts, 'kept' => $kept, 'changed' => $changed,
        'head' => line_hash($head), 'tail' => line_hash($tail)];
}

/** Ziel-Wiki aus den Feldern file/dir einer Anfrage. */
function chunk_target(bool $create): string
{
    $name = safe_filename((string) ($_POST['file'] ?? ''));
    return safe_dir((string) ($_POST['dir'] ?? ''), $create) . DIRECTORY_SEPARATOR . $name;
}

/** Lädt die Verwaltungsdaten eines laufenden Uploads. */
function chunk_meta(string $id, string $user): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new StoreError('Ungültige Upload-ID');
    }
    $file = chunk_dir() . DIRECTORY_SEPARATOR . $id . '.json';
    $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($meta) || ($meta['user'] ?? null) !== $user) {
        throw new StoreError('Upload nicht gefunden oder abgelaufen – bitte erneut speichern', 404);
    }
    return $meta;
}

function chunk_save_meta(array $meta): void
{
    file_put_contents(chunk_dir() . DIRECTORY_SEPARATOR . $meta['id'] . '.json', json_encode($meta), LOCK_EX);
}

function chunk_discard(string $id): void
{
    $dir = chunk_dir();
    @unlink("$dir/$id.json");
    @unlink("$dir/$id.bin");
}

function handle_chunk(string $action): array
{
    global $CONFIG;
    if (!$CONFIG['enable_chunks']) {
        throw new StoreError('Upload in Stücken ist abgeschaltet', 403);
    }
    $user = chunk_user();

    if ($action === 'begin') {
        // Liegengebliebene Uploads (älter als 2 Stunden) aufräumen.
        foreach (glob(chunk_dir() . '/*.{json,bin}', GLOB_BRACE) ?: [] as $old) {
            if (filemtime($old) < time() - 7200) {
                @unlink($old);
            }
        }
        $total = (int) ($_POST['total'] ?? -1);
        $encoding = (string) ($_POST['encoding'] ?? 'identity');
        $mode = (string) ($_POST['mode'] ?? 'full');
        if (!in_array($mode, ['full', 'patch', 'merge'], true)) {
            throw new StoreError('Unbekannter Modus');
        }
        if ($total <= 0 || $total > (int) $CONFIG['max_size']) {
            throw new StoreError('Wiki ist größer als max_size in store.php', 413);
        }
        if (!in_array($encoding, ['identity', 'gzip'], true)) {
            throw new StoreError('Unbekannte Kodierung');
        }
        if ($encoding === 'gzip' && !function_exists('gzopen')) {
            throw new StoreError('zlib fehlt in PHP – Plugin bitte ohne Komprimierung verwenden', 500);
        }
        $name = safe_filename((string) ($_POST['file'] ?? ''));
        $dir = safe_dir((string) ($_POST['dir'] ?? ''), true);
        $wantedBackup = $CONFIG['backup_dir'] ?? (string) ($_POST['backupDir'] ?? '');
        $backup = $wantedBackup !== '' ? rel_path(safe_dir((string) $wantedBackup, true)) : null;
        $meta = [
            'id'       => bin2hex(random_bytes(16)),
            'user'     => $user,
            'dest'     => ltrim(rel_path($dir) . '/' . $name, '/'),
            'backup'   => $backup,
            'total'    => $total,
            'encoding' => $encoding,
            'mode'     => $mode,
            'next'     => 0,
            'received' => 0,
        ];
        chunk_save_meta($meta);
        touch(chunk_dir() . DIRECTORY_SEPARATOR . $meta['id'] . '.bin');
        return ['ok' => true, 'id' => $meta['id'], 'chunkSize' => chunk_size_limit()];
    }

    // Stand abfragen (ab 2.2): Version, Prüfsumme jeder Tiddler-Zeile, einzelne Tiddler.
    if ($action === 'state' || $action === 'index' || $action === 'fetch') {
        $path = chunk_target(false);
        $version = wiki_version($path);
        $out = ['ok' => true, 'version' => $version, 'store' => STORE_VERSION];
        if ($action === 'state' || $version === '') {
            return $out;
        }
        $parts = split_store((string) file_get_contents($path));
        if ($parts === null) {
            throw new StoreError('Wiki auf dem Server hat einen unbekannten Aufbau', 409);
        }
        if ($action === 'index') {
            $out['head'] = line_hash($parts[0]);
            $out['tail'] = line_hash($parts[2]);
            $out['tiddlers'] = new stdClass();
            foreach ($parts[1] as $line) {
                $t = line_title($line);
                if ($t !== null) {
                    $out['tiddlers']->$t = line_hash($line);
                }
            }
            return $out;
        }
        $want = json_decode((string) ($_POST['titles'] ?? '[]'), true);
        $want = is_array($want) ? array_flip(array_filter($want, 'is_string')) : [];
        $out['lines'] = new stdClass();
        foreach ($parts[1] as $line) {
            $t = line_title($line);
            if ($t !== null && isset($want[$t])) {
                $out['lines']->$t = $line;
            }
        }
        return $out;
    }

    $meta = chunk_meta((string) ($_POST['id'] ?? ''), $user);
    $bin = chunk_dir() . DIRECTORY_SEPARATOR . $meta['id'] . '.bin';

    if ($action === 'part') {
        $index = (int) ($_POST['index'] ?? -1);
        $file = $_FILES['data'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new StoreError('Stück ' . $index . ' nicht empfangen (Upload-Fehler '
                . (int) ($file['error'] ?? -1) . ')');
        }
        if ($index === $meta['next'] - 1) {
            return ['ok' => true, 'received' => $meta['received']]; // Wiederholung, schon da
        }
        if ($index !== $meta['next']) {
            throw new StoreError('Stück in falscher Reihenfolge');
        }
        $size = (int) $file['size'];
        if ($meta['received'] + $size > $meta['total']) {
            chunk_discard($meta['id']);
            throw new StoreError('Mehr Daten als angekündigt');
        }
        $in = fopen((string) $file['tmp_name'], 'rb');
        $out = fopen($bin, 'ab');
        if ($in === false || $out === false || stream_copy_to_stream($in, $out) !== $size) {
            throw new StoreError('Stück konnte nicht gespeichert werden – Schreibrechte prüfen', 500);
        }
        fclose($in);
        fclose($out);
        $meta['next']++;
        $meta['received'] += $size;
        chunk_save_meta($meta);
        return ['ok' => true, 'received' => $meta['received']];
    }

    if ($action === 'finish') {
        try {
            clearstatcache(true, $bin);
            if ($meta['received'] !== $meta['total'] || filesize($bin) !== $meta['total']) {
                throw new StoreError('Upload unvollständig – bitte erneut speichern');
            }
            $sha = strtolower((string) ($_POST['sha256'] ?? ''));
            if ($sha !== '' && !hash_equals(hash_file('sha256', $bin), $sha)) {
                throw new StoreError('Prüfsumme stimmt nicht – Daten beschädigt, bitte erneut speichern');
            }
            $dest = safe_wiki_path($meta['dest'], true);
            $dir = dirname($dest);
            $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($dest) . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if ($meta['encoding'] === 'gzip') {
                $in = gzopen($bin, 'rb');
                $out = fopen($tmp, 'wb');
                if ($in === false || $out === false) {
                    throw new StoreError('Temporäre Datei konnte nicht angelegt werden', 500);
                }
                $written = 0;
                while (!gzeof($in)) {
                    $buf = gzread($in, 1 << 20);
                    if ($buf === false) {
                        break;
                    }
                    $written += strlen($buf);
                    if ($written > (int) $CONFIG['max_size']) {
                        gzclose($in);
                        fclose($out);
                        @unlink($tmp);
                        throw new StoreError('Wiki ist größer als max_size in store.php', 413);
                    }
                    fwrite($out, $buf);
                }
                gzclose($in);
                fclose($out);
            } elseif (!copy($bin, $tmp)) {
                throw new StoreError('Temporäre Datei konnte nicht angelegt werden', 500);
            }
            // Lesen, Einbauen und Ersetzen unter einer Sperre: zwei gleichzeitige
            // Speichervorgänge kommen nacheinander dran und sehen jeweils den neuesten Stand.
            $lock = fopen(wiki_lock_path($dest), 'c');
            if ($lock !== false) {
                flock($lock, LOCK_EX);
            }
            $merged = null;
            try {
                $before = wiki_version($dest);
                try {
                    if (($meta['mode'] ?? 'full') === 'patch') {
                        apply_patch($tmp, $dest);
                    } elseif (($meta['mode'] ?? 'full') === 'merge') {
                        $merged = apply_merge($tmp, $dest);
                    }
                } catch (Throwable $e) {
                    @unlink($tmp);
                    throw $e;
                }
                if ($CONFIG['require_tiddlywiki'] && !looks_like_tiddlywiki($tmp)) {
                    @unlink($tmp);
                    throw new StoreError('Die Datei sieht nicht wie ein TiddlyWiki aus');
                }
                $backupDir = $meta['backup'] !== null ? safe_dir((string) $meta['backup'], true) : null;
                if ($merged !== null && $merged['changed'] === 0) {
                    @unlink($tmp); // nichts zu tun: keine neue Fassung, kein Backup
                    $backup = '';
                } else {
                    $backup = replace_with_backup($tmp, $dest, $backupDir, true);
                }
                $version = wiki_version($dest);
            } finally {
                if ($lock !== false) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        } finally {
            chunk_discard($meta['id']);
        }
        return [
            'ok'        => true,
            'message'   => '0 - File successfully loaded in ' . rel_path($dest),
            'backup'    => $backup !== '' ? rel_path($backup) : null,
            'before'    => $before,
            'version'   => $version,
            'conflicts' => $merged['conflicts'] ?? [],
            'kept'      => $merged['kept'] ?? [],
            'head'      => $merged['head'] ?? null,
            'tail'      => $merged['tail'] ?? null,
        ];
    }

    throw new StoreError('Unbekannte Aktion');
}

/* ===================================================================
 * Infoseite und Hash-Generator
 * =================================================================== */

function html_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem}'
        . 'code,input{font:inherit}code{background:#eee;padding:0 .3em}input{padding:.3em;width:100%;box-sizing:border-box}'
        . 'pre{background:#eee;padding:.7em;overflow:auto;white-space:pre-wrap;word-break:break-all}</style>'
        . '</head><body>' . $body . '</body></html>';
}

function check_page(): void
{
    $h = static fn(string $t): string => htmlspecialchars($t);
    $rows = [];
    $add = static function (bool $ok, string $what, string $detail) use (&$rows, $h): void {
        $rows[] = '<tr><td>' . ($ok ? '✅' : '❌') . '</td><td>' . $h($what) . '</td><td>' . $h($detail) . '</td></tr>';
    };
    $add(PHP_VERSION_ID >= 80000, 'PHP-Version', PHP_VERSION);
    $add((bool) ini_get('file_uploads'), 'file_uploads', ini_get('file_uploads') ? 'an' : 'aus');
    $up = ini_bytes('upload_max_filesize');
    $post = ini_bytes('post_max_size');
    $add($up === 0 || $up >= 8 * 1048576, 'upload_max_filesize', ini_get('upload_max_filesize')
        . ' (ein leeres TiddlyWiki 5 hat ca. 2,5 MB, empfohlen: 64M)');
    $add($post === 0 || $post >= 8 * 1048576, 'post_max_size', (string) ini_get('post_max_size'));
    $tmp = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    $add(is_writable($tmp), 'Temporärer Upload-Ordner beschreibbar', is_writable($tmp) ? 'ja' : 'nein');
    $add(is_writable(__DIR__), 'Ordner von store.php beschreibbar', is_writable(__DIR__) ? 'ja' : 'nein');
    $authFile = __DIR__ . '/auth.php';
    if (!is_file($authFile)) {
        $add(false, 'auth.php', 'fehlt');
    } else {
        try {
            $users = load_users();
            $plain = 0;
            foreach ($users as $hash) {
                $i = password_get_info((string) $hash);
                if ($i['algo'] === null || $i['algo'] === 0) {
                    $plain++;
                }
            }
            $add($users && !$plain, 'auth.php', count($users) . ' Benutzer'
                . ($plain ? ", davon $plain mit Klartext statt Hash" : ', alle mit Hash'));
        } catch (Throwable $e) {
            $add(false, 'auth.php', 'kann nicht gelesen werden (Syntaxfehler?)');
        }
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $add($https, 'Aufruf über https', $https ? 'ja' : 'nein – wenn das Wiki über https läuft, muss auch die '
        . 'Server-URL im Wiki mit https:// beginnen, sonst blockiert der Browser das Speichern ohne Meldung');

    html_page('store.php prüfen', '<h1>Einrichtung prüfen</h1>'
        . '<table cellpadding="4">' . implode('', $rows) . '</table>'
        . '<h2>Test-Upload</h2>'
        . '<p>Schickt eine Datei genau so, wie es TiddlyWiki tut. Die Antwort des Servers erscheint '
        . 'danach direkt im Browser. Beginnt sie mit <code>0 - </code>, funktioniert store.php. '
        . 'Kommt stattdessen eine Seite wie „403 Forbidden“ oder „Not Acceptable“, blockiert eine '
        . 'Schutzfunktion des Providers (meist mod_security) den Upload.</p>'
        . '<form method="post" action="' . $h(strtok((string) ($_SERVER['REQUEST_URI'] ?? 'store.php'), '?')) . '" enctype="multipart/form-data">'
        . '<p><label>UploadPlugin<br><input name="UploadPlugin" value="backupDir=backups;user=;password=;uploaddir=.;;"></label></p>'
        . '<p><label>Wiki-Datei (Achtung: wird auf dem Server ersetzt, vorher gesichert)<br>'
        . '<input type="file" name="userfile" accept=".html,.htm"></label></p>'
        . '<p><button>Hochladen</button></p></form>'
        . '<p>Nach der Einrichtung kann diese Seite mit <code>\'check_tool\' =&gt; false</code> abgeschaltet werden.</p>');
}

function info_page(): void
{
    global $CONFIG;
    $hashLink = ($CONFIG['hash_tool'] ? '<p><a href="?hash">Passwort-Hash für auth.php erzeugen</a></p>' : '')
        . ($CONFIG['check_tool'] ? '<p><a href="?check">Einrichtung prüfen</a></p>' : '');
    html_page('store.php', '<h1>store.php ' . STORE_VERSION . '</h1>'
        . '<p>Dieses Skript speichert <a href="https://tiddlywiki.com/">TiddlyWiki</a>-Dateien '
        . 'über den Saver „upload“ oder „put“.</p>' . $hashLink);
}

function hash_page(): void
{
    $result = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['hashpassword'])) {
        $pw = (string) $_POST['hashpassword'];
        if ($pw !== '') {
            $user = preg_replace('/[^\w\-.@]/u', '', (string) ($_POST['hashuser'] ?? 'name')) ?: 'name';
            $line = "    '" . $user . "' => '" . password_hash($pw, PASSWORD_DEFAULT) . "',";
            $result = '<p>Diese Zeile in <code>auth.php</code> eintragen:</p><pre>'
                . htmlspecialchars($line) . '</pre>';
        }
    }
    html_page('Passwort-Hash', '<h1>Passwort-Hash erzeugen</h1>' . $result
        . '<form method="post" action="?hash" autocomplete="off">'
        . '<p><label>Benutzername<br><input name="hashuser" required></label></p>'
        . '<p><label>Passwort<br><input name="hashpassword" type="password" required></label></p>'
        . '<p><button>Hash erzeugen</button></p></form>'
        . '<p>Das Passwort wird nicht gespeichert. Nach der Einrichtung kann diese Seite in '
        . 'store.php mit <code>\'hash_tool\' =&gt; false</code> abgeschaltet werden.</p>');
}

/* ===================================================================
 * Verteiler
 * =================================================================== */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
send_cors();
header('Cache-Control: no-store', false);

try {
    if (isset($_GET['hash']) && $CONFIG['hash_tool']) {
        hash_page();
    } elseif (isset($_GET['check']) && $CONFIG['check_tool'] && $method === 'GET') {
        check_page();
    } elseif (isset($_GET['chunk']) && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode(handle_chunk((string) $_GET['chunk']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (StoreError $e) {
            http_response_code($e->status);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } elseif (isset($_GET['wiki'])) {
        handle_wiki($method, (string) $_GET['wiki']);
    } elseif ($method === 'POST') {
        try {
            $msg = handle_upload();
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg;
        } catch (StoreError $e) {
            // Der upload-Saver wertet nur Antworten mit Status 200 aus und zeigt
            // dann den Text als Fehlermeldung. Nur 401 (Login-Dialog) bleibt.
            if ($e->status === 401) {
                throw $e;
            }
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Error : ' . $e->getMessage() . "\n";
        }
    } elseif ($method === 'OPTIONS') {
        header('Allow: OPTIONS, GET, POST');
        header('Content-Length: 0');
    } elseif ($method === 'GET' || $method === 'HEAD') {
        info_page();
    } else {
        header('Allow: OPTIONS, GET, POST');
        throw new StoreError('Methode nicht erlaubt', 405);
    }
} catch (StoreError $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error : ' . $e->getMessage() . "\n";
} catch (Throwable $e) {
    error_log('store.php: ' . $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error : Interner Fehler – Details im PHP-Fehlerlog des Servers\n";
}
