<?php
/**
 * form-handler.php — Löwe Smart Devices Landingpages
 * ════════════════════════════════════════════════════════════════════════════
 * PHPMailer + IONOS SMTP. Kein mail()-Fallback.
 * PHP 7.4+ kompatibel (keine union/never-Typen).
 *
 * DOMAIN-WECHSEL jonathanfuchs.de → loewe-smartdevices.com:
 * Nur den Abschnitt "=== KONFIGURATION ===" anpassen — Rest bleibt identisch.
 * ════════════════════════════════════════════════════════════════════════════
 */

// ════════════════════════════════════════════════════════════════════════════
// === KONFIGURATION — beim Domain-Wechsel NUR HIER anfassen ================
// ════════════════════════════════════════════════════════════════════════════

// Live-Domain (ohne Protokoll, ohne Slash)
define('LIVE_DOMAIN', 'jonathanfuchs.de');

// Basis-URL: Schema + Domain, KEIN trailing slash, KEIN Unterverzeichnis hier.
// Unterverzeichnisse werden in den Pfaden unten geführt.
define('BASE_URL', 'https://jonathanfuchs.de');

// Pfad-Präfix des Projekts (Unterverzeichnis auf dem Server, mit führendem Slash)
// Bei Liveschaltung auf Root: define('PATH_PREFIX', '');
define('PATH_PREFIX', '/loewe');

// Empfänger
define('FORM_RECEIVER_EMAIL', 'lead@jonathanfuchs.de');
define('FORM_RECEIVER_NAME',  'Jonathan Fuchs Testprojekt');

// SMTP (IONOS)
define('SMTP_HOST',       'smtp.ionos.de');
define('SMTP_PORT',       465);
define('SMTP_USERNAME',   'lead@jonathanfuchs.de');
define('SMTP_PASSWORD',   '{{HIER_PASSWORT_EINTRAGEN}}');
define('SMTP_ENCRYPTION', 'ssl');   // 'ssl' für Port 465 | 'tls' für Port 587

// Absender (muss zur authentifizierten SMTP-Domain passen)
define('FROM_EMAIL', 'lead@jonathanfuchs.de');
define('FROM_NAME',  'Loewe Smart Devices');

// Debug-Modus (false im Live-Betrieb)
define('DEBUG', false);

// Cloudflare Turnstile — standardmäßig deaktiviert
define('TURNSTILE_ENABLED',    false);
define('TURNSTILE_SITE_KEY',   '');
define('TURNSTILE_SECRET_KEY', '');

// ── Log-Pfade ────────────────────────────────────────────────────────────────
// WICHTIG: Diese Pfade sollten AUSSERHALB des öffentlich erreichbaren Webroot
// liegen. Typische IONOS-Struktur:
//
//   /httpdocs/         ← öffentlicher Webroot
//   /private_logs/     ← HIER lagern, nicht erreichbar per HTTP
//
// Wenn nur ein Verzeichnis verfügbar ist, alternativ:
//   __DIR__ . '/../private_logs/'  (eine Ebene über /loewe/)
//
// Notfalls (Shared Hosting ohne Zugriff außerhalb httpdocs):
//   __DIR__ . '/logs/'  + .htaccess mit "Deny from all"
//
define('LOG_PATH',        __DIR__ . '/../private_logs/form-handler.log');
define('RATE_LIMIT_PATH', __DIR__ . '/../private_logs/form-rate-limit.json');

// ════════════════════════════════════════════════════════════════════════════
// === WHITELISTS — beim Domain-Wechsel Pfade anpassen ======================
// ════════════════════════════════════════════════════════════════════════════

// Erlaubte source_page-Werte (exakt wie im HTML-Hidden-Field gesetzt).
// Sowohl kurze Form (wie im HTML) als auch vollständiger Pfad werden akzeptiert.
$ALLOWED_SOURCE_PAGES = [
    // Kurze Form (wie im name="source_page" value="..." im HTML)
    'loewe-autohaus.html',
    'loewe-hotel.html',
    'loewe-industrie.html',
    'loewe-klinik.html',
    'loewe-reinigungsunternehmen.html',
    'loewe-retail-einkaufszentrum.html',
    'loewe-v3.html',
    // Vollständiger Pfad (zur Absicherung)
    PATH_PREFIX . '/loewe-autohaus.html',
    PATH_PREFIX . '/loewe-hotel.html',
    PATH_PREFIX . '/loewe-industrie.html',
    PATH_PREFIX . '/loewe-klinik.html',
    PATH_PREFIX . '/loewe-reinigungsunternehmen.html',
    PATH_PREFIX . '/loewe-retail-einkaufszentrum.html',
    PATH_PREFIX . '/loewe-v3.html',
];

// Erlaubte return_target-Werte (kurze Form wie im HTML-Hidden-Field).
// redirect_to() baut daraus die vollständige URL mit BASE_URL + PATH_PREFIX.
$ALLOWED_RETURN_TARGETS = [
    'thank-you.html?from=autohaus',
    'thank-you.html?from=hotel',
    'thank-you.html?from=industrie',
    'thank-you.html?from=klinik',
    'thank-you.html?from=reinigungsunternehmen',
    'thank-you.html?from=retail',
    'thank-you.html?from=v3',
    'thank-you.html?from=main',
    'thank-you.html',
];

// Fallback-Redirect (vollständige URL)
define('FALLBACK_REDIRECT', BASE_URL . PATH_PREFIX . '/thank-you.html');

// ════════════════════════════════════════════════════════════════════════════
// === AB HIER NICHTS ÄNDERN ================================================
// ════════════════════════════════════════════════════════════════════════════

// ── Nur POST erlauben ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . PATH_PREFIX . '/loewe-v3.html', true, 303);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// === HILFSFUNKTIONEN ======================================================
// ════════════════════════════════════════════════════════════════════════════

/**
 * Bereinigt einen String: kein HTML, UTF-8, auf Maxlänge gekürzt.
 */
function fh_sanitize($val, $maxlen = 255)
{
    return mb_substr(trim(strip_tags((string)$val)), 0, $maxlen, 'UTF-8');
}

/**
 * Schreibt eine Zeile ins Log-File.
 * Erstellt Verzeichnis wenn nötig.
 */
function fh_log($level, $message, $context = [])
{
    $logPath = LOG_PATH;
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // .htaccess-Schutz für den Log-Ordner falls im Webroot
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    $ip = fh_get_client_ip();
    $line = sprintf(
        "[%s] [%s] [IP:%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $ip,
        $message,
        $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Ermittelt die Client-IP (Cloudflare-aware).
 */
function fh_get_client_ip()
{
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return 'unknown';
}

/**
 * Baut eine interne Redirect-URL und sendet den Redirect.
 * $target darf ein kurzer Pfad ('thank-you.html?from=klinik')
 * oder eine vollständige URL (BASE_URL + ...) sein.
 * Externe URLs werden immer auf FALLBACK_REDIRECT umgeleitet.
 */
function fh_redirect($target)
{
    if (strpos($target, 'http') === 0) {
        // Vollständige URL: nur zulassen wenn Base-URL stimmt
        if (strpos($target, BASE_URL) === 0) {
            $url = $target;
        } else {
            $url = FALLBACK_REDIRECT;
        }
    } else {
        // Kurzer Pfad: Präfix anhängen
        $url = BASE_URL . PATH_PREFIX . '/' . ltrim($target, '/');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * Abbruch mit Log + Redirect.
 */
function fh_abort($reason, $redirect_to = '')
{
    fh_log('WARN', 'Aborted: ' . $reason);
    fh_redirect($redirect_to ?: FALLBACK_REDIRECT);
    // fh_redirect() macht exit; diese Zeile wird nie erreicht
}

// ════════════════════════════════════════════════════════════════════════════
// === RATE LIMITING ========================================================
// ════════════════════════════════════════════════════════════════════════════

function fh_check_rate_limit($ip)
{
    $path   = RATE_LIMIT_PATH;
    $dir    = dirname($path);
    $limit  = 5;    // max. Requests
    $window = 900;  // Zeitfenster: 15 Minuten

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $data = [];
    if (file_exists($path)) {
        $raw  = @file_get_contents($path);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }

    $now = time();
    // Alte Einträge bereinigen
    foreach (array_keys($data) as $k) {
        $data[$k] = array_values(array_filter($data[$k], function($t) use ($now, $window) {
            return ($now - $t) < $window;
        }));
        if (empty($data[$k])) {
            unset($data[$k]);
        }
    }

    $key  = md5($ip); // IP-Hash statt Klartext
    $hits = $data[$key] ?? [];

    if (count($hits) >= $limit) {
        @file_put_contents($path, json_encode($data), LOCK_EX);
        return false;
    }

    $hits[]     = $now;
    $data[$key] = $hits;
    @file_put_contents($path, json_encode($data), LOCK_EX);
    return true;
}

// ════════════════════════════════════════════════════════════════════════════
// === CLOUDFLARE TURNSTILE (optional) =====================================
// ════════════════════════════════════════════════════════════════════════════

function fh_verify_turnstile($token)
{
    if (!TURNSTILE_ENABLED || empty(TURNSTILE_SECRET_KEY)) {
        return true; // deaktiviert → immer OK
    }
    $ip = fh_get_client_ip();
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'secret'   => TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => $ip,
        ]),
        'timeout' => 5,
    ]]);
    $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if (!$raw) {
        return false;
    }
    $result = json_decode($raw, true);
    return !empty($result['success']);
}

// ════════════════════════════════════════════════════════════════════════════
// === SECURITY-CHECKS ======================================================
// ════════════════════════════════════════════════════════════════════════════

$ip = fh_get_client_ip();

// 1. Rate Limit
if (!fh_check_rate_limit($ip)) {
    fh_log('WARN', 'Rate limit hit', ['ip' => $ip]);
    fh_abort('rate_limit');
}

// 2. Origin / Referer-Prüfung
// Ziel: Requests von fremden Domains blockieren.
// Strategie: nur blockieren wenn ein Referer/Origin gesetzt ist UND nicht zur eigenen Domain passt.
// Fehlendes Header = nicht automatisch blockiert (Privacy-Browser, direkte Requests).
$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$check_source = $origin ?: $referer;

if (!empty($check_source)) {
    // Erlaubte Host-Patterns (Domain + Subdomains)
    $allowed_hosts = [LIVE_DOMAIN, 'www.' . LIVE_DOMAIN];
    $host = parse_url($check_source, PHP_URL_HOST) ?: '';
    $host = strtolower(trim($host));
    if (!empty($host) && !in_array($host, $allowed_hosts, true)) {
        fh_log('WARN', 'Origin/Referer blocked', ['host' => $host, 'ip' => $ip]);
        fh_abort('bad_origin');
    }
}

// 3. Honeypot (website-Feld muss leer sein)
if (!empty($_POST['website'])) {
    fh_log('WARN', 'Honeypot triggered', ['ip' => $ip]);
    fh_redirect(FALLBACK_REDIRECT); // Stille Weiterleitung
}

// 4. Zeitfalle — form_ts muss vorhanden und plausibel sein
$form_ts_raw = $_POST['form_ts'] ?? '';
if (!is_numeric($form_ts_raw) || (int)$form_ts_raw <= 0) {
    fh_log('WARN', 'form_ts missing or invalid', ['ip' => $ip, 'val' => $form_ts_raw]);
    fh_abort('ts_invalid');
}
$elapsed = time() - (int)$form_ts_raw;
if ($elapsed < 4) {
    // Zu schnell ausgefüllt (Bot)
    fh_log('WARN', 'Time trap: too fast', ['elapsed' => $elapsed, 'ip' => $ip]);
    fh_abort('ts_too_fast');
}
if ($elapsed > 7200) {
    // Älter als 2 Stunden (vergessenes Tab, verdächtiger Replay-Versuch)
    fh_log('WARN', 'Time trap: too old', ['elapsed' => $elapsed, 'ip' => $ip]);
    fh_abort('ts_too_old');
}

// 5. Turnstile
if (TURNSTILE_ENABLED) {
    $ts_token = fh_sanitize($_POST['cf-turnstile-response'] ?? '', 2048);
    if (!fh_verify_turnstile($ts_token)) {
        fh_log('WARN', 'Turnstile failed', ['ip' => $ip]);
        fh_abort('turnstile_fail');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// === FELDER EINLESEN + VALIDIEREN =========================================
// ════════════════════════════════════════════════════════════════════════════

$vorname      = fh_sanitize($_POST['vorname']    ?? '', 100);
$nachname     = fh_sanitize($_POST['nachname']   ?? '', 100);
$firma        = fh_sanitize($_POST['firma']      ?? '', 200);
$email_raw    = trim($_POST['email']             ?? '');
$telefon      = fh_sanitize($_POST['telefon']    ?? '', 50);
$bereich      = fh_sanitize($_POST['bereich']    ?? '', 200);
$nachricht    = fh_sanitize($_POST['nachricht']  ?? '', 1000);
$datenschutz  = !empty($_POST['datenschutz']);
$source_page  = fh_sanitize($_POST['source_page']   ?? '', 100);
$source_branch = fh_sanitize($_POST['source_branch'] ?? 'unbekannt', 50);
$return_raw   = fh_sanitize($_POST['return_target']  ?? '', 200);

$errors = [];

if (empty($vorname))   $errors[] = 'vorname';
if (empty($nachname))  $errors[] = 'nachname';
if (empty($firma))     $errors[] = 'firma';
if (empty($telefon))   $errors[] = 'telefon';
if (!$datenschutz)     $errors[] = 'datenschutz';

// E-Mail
$email = filter_var($email_raw, FILTER_VALIDATE_EMAIL);
if (!$email || empty($email)) {
    $errors[] = 'email';
}

// Telefon: mindestens 6 Ziffern
if (!empty($telefon)) {
    $digits_only = preg_replace('/[^0-9]/', '', $telefon);
    if (strlen($digits_only) < 6) {
        $errors[] = 'telefon_invalid';
    }
}

// Source-Page gegen Whitelist prüfen
$source_page_valid = in_array($source_page, $ALLOWED_SOURCE_PAGES, true);
if (!$source_page_valid) {
    fh_log('WARN', 'source_page not in whitelist', ['val' => $source_page, 'ip' => $ip]);
    $source_page = 'unbekannt'; // Sanitized fallback für Log, kein Redirect-Missbrauch
}

// Validierungsfehler → zurück zur Quellseite wenn möglich
if (!empty($errors)) {
    fh_log('WARN', 'Validation failed', ['errors' => $errors, 'ip' => $ip]);
    // Sicher zurückleiten zur Quellseite (wenn aus Whitelist)
    if ($source_page_valid && in_array($source_page, $ALLOWED_SOURCE_PAGES, true)) {
        // Kurze Form → Redirect zurück mit Fehlerparameter
        $clean_src = preg_replace('/[^a-zA-Z0-9\-_.]/', '', basename($source_page));
        if (!empty($clean_src)) {
            fh_redirect($clean_src . '?fehler=pflicht#termin');
        }
    }
    fh_redirect(FALLBACK_REDIRECT);
}

// Return-Target gegen Whitelist prüfen
$return_target = FALLBACK_REDIRECT; // Default
foreach ($ALLOWED_RETURN_TARGETS as $allowed) {
    if ($return_raw === $allowed) {
        $return_target = $allowed; // Kurze Form — fh_redirect() macht daraus vollständige URL
        break;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// === E-MAIL VERSAND via PHPMailer =========================================
// ════════════════════════════════════════════════════════════════════════════

// PHPMailer-Pfade (Composer-Autoload hat Priorität)
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$direct_paths = [
    __DIR__ . '/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../phpmailer/src/PHPMailer.php',
];

$phpmailer_loaded = false;
foreach ($autoload_paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $phpmailer_loaded = true;
        break;
    }
}
if (!$phpmailer_loaded) {
    foreach ($direct_paths as $p) {
        if (file_exists($p)) {
            require_once $p;
            require_once dirname($p) . '/SMTP.php';
            require_once dirname($p) . '/Exception.php';
            $phpmailer_loaded = true;
            break;
        }
    }
}

// Kein Fallback auf mail() — PHPMailer ist Pflicht
if (!$phpmailer_loaded) {
    fh_log('ERROR', 'PHPMailer not found — install via: composer require phpmailer/phpmailer', [
        'searched' => array_merge($autoload_paths, $direct_paths),
    ]);
    // Nutzer landet auf Thank-you (kein technischer Fehler sichtbar)
    // Intern ist der Fehler geloggt, Admin wird benachrichtigt sobald Log geprüft wird
    fh_redirect(FALLBACK_REDIRECT);
}

$mail_ok    = false;
$mail_error = '';

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet   = 'UTF-8';
    $mail->Encoding  = 'base64';
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = (SMTP_ENCRYPTION === 'tls')
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = SMTP_PORT;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ];

    if (DEBUG) {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            fh_log('DEBUG', 'SMTP: ' . $str);
        };
    }

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress(FORM_RECEIVER_EMAIL, FORM_RECEIVER_NAME);
    $mail->addReplyTo($email, $vorname . ' ' . $nachname);

    $betreff = sprintf('[Anfrage] %s %s — %s — %s',
        $vorname, $nachname, ucfirst($source_branch), date('d.m.Y H:i')
    );
    $mail->Subject = $betreff;
    $mail->isHTML(false);

    // Mail-Body
    $separator = str_repeat('═', 54);
    $body_lines = [
        'NEUE ANFRAGE — Löwe Smart Devices',
        $separator,
        '',
        'HERKUNFT',
        '  Seite:     ' . $source_page,
        '  Branche:   ' . $source_branch,
        '  Datum:     ' . date('d.m.Y H:i') . ' Uhr',
        '  Domain:    ' . LIVE_DOMAIN,
        '',
        'KONTAKTDATEN',
        '  Vorname:   ' . $vorname,
        '  Nachname:  ' . $nachname,
        '  Firma:     ' . $firma,
        '  E-Mail:    ' . $email,
        '  Telefon:   ' . $telefon,
    ];
    if (!empty($bereich)) {
        $body_lines[] = '  Bereich:   ' . $bereich;
    }
    if (!empty($nachricht)) {
        $body_lines[] = '';
        $body_lines[] = 'NACHRICHT';
        $body_lines[] = '  ' . str_replace("\n", "\n  ", wordwrap($nachricht, 72, "\n", false));
    }
    $body_lines[] = '';
    $body_lines[] = $separator;
    $body_lines[] = 'Gesendet via form-handler.php | ' . BASE_URL . PATH_PREFIX;

    $mail->Body = implode("\n", $body_lines);
    $mail->send();
    $mail_ok = true;

} catch (PHPMailer\PHPMailer\Exception $e) {
    $mail_error = $e->getMessage();
    fh_log('ERROR', 'PHPMailer send failed', ['error' => substr($mail_error, 0, 200)]);
} catch (Exception $e) {
    $mail_error = $e->getMessage();
    fh_log('ERROR', 'Generic exception in mailer', ['error' => substr($mail_error, 0, 200)]);
}

// ════════════════════════════════════════════════════════════════════════════
// === LOGGING + REDIRECT ===================================================
// ════════════════════════════════════════════════════════════════════════════

if ($mail_ok) {
    // E-Mail-Adresse nur teilweise loggen (Datenschutz)
    $email_parts = explode('@', $email);
    $email_log   = substr($email_parts[0], 0, 3) . '***@' . ($email_parts[1] ?? '');
    fh_log('INFO', 'Form OK', [
        'source'  => $source_page,
        'branch'  => $source_branch,
        'email'   => $email_log,
    ]);
    fh_redirect($return_target);
} else {
    // Nutzer landet auf Thank-you (Fehler nicht nach außen geben)
    // Admin sollte LOG_PATH regelmäßig prüfen
    fh_redirect(FALLBACK_REDIRECT);
}
