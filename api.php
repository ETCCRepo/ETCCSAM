<?php
// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: Error Handling & Environment
// ═════════════════════════════════════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/error.log');
@mkdir(__DIR__ . '/logs', 0750, true); // Create logs dir if missing

// Handle uncaught exceptions gracefully without exposing internals
set_exception_handler(function($e) {
    error_log("[EXCEPTION] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
});

// ═════════════════════════════════════════════════════════════════════════════
// Session Configuration: Security hardening
// ═════════════════════════════════════════════════════════════════════════════
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '1');      // HTTPS only
ini_set('session.cookie_httponly', '1');    // No JavaScript access
ini_set('session.cookie_samesite', 'Strict'); // CSRF protection
ini_set('session.gc_maxlifetime', '1800');  // 30 minutes
session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: HTTP Method Validation & Security Headers
// ═════════════════════════════════════════════════════════════════════════════
$allowedMethods = ['GET', 'POST', 'OPTIONS'];
if (!in_array($_SERVER['REQUEST_METHOD'], $allowedMethods, true)) {
    http_response_code(405);
    header('Allow: GET, POST, OPTIONS');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Additional security headers to prevent caching of sensitive responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: -1');
// Prevent MIME type detection of JSON
header('X-Content-Type-Options: nosniff');
// Prevent clickjacking
header('X-Frame-Options: DENY');
// Prevent browser-based XSS auditor (can be exploited)
header('X-XSS-Protection: 0');

// Include Phase 2 security helpers (optional - graceful degradation)
$securityHelpersPath = __DIR__ . '/security-helpers.php';
if (file_exists($securityHelpersPath)) {
    require_once $securityHelpersPath;
}

// Include Phase 3 security helpers (optional - graceful degradation)
$phase3HelpersPath = __DIR__ . '/phase3-helpers.php';
if (file_exists($phase3HelpersPath)) {
    require_once $phase3HelpersPath;
}

// ═════════════════════════════════════════════════════════════════════════════
// SECURITY: Load environment variables
// ═════════════════════════════════════════════════════════════════════════════
function loadEnvFile($envFile) {
    if (!file_exists($envFile)) {
        // No hardcoded secret fallback: a missing .env must fail closed (DB
        // connection below will fail with empty credentials) rather than
        // silently running on hardcoded production secrets baked into source
        // control. Only non-secret defaults belong here.
        return [
            'ALLOWED_ORIGIN' => 'https://etccapps.com',
        ];
    }

    $env = [];
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove quotes if present
            if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }
    }

    return $env;
}

$envFile = __DIR__ . '/.env';
$env = loadEnvFile($envFile);

// ═════════════════════════════════════════════════════════════════════════════
// SECURITY: CORS Protection - Only allow requests from allowed origin
// ═════════════════════════════════════════════════════════════════════════════
$allowedOrigin = $env['ALLOWED_ORIGIN'] ?? 'https://etccapps.com';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow same-site requests (no origin header) and whitelisted origins
if (!empty($requestOrigin) && $requestOrigin !== $allowedOrigin) {
    error_log("[CORS_VIOLATION] Blocked request from origin: " . sanitizeInput($requestOrigin, 255));
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    http_response_code(403);
    echo json_encode(['error' => 'CORS error: Origin not allowed']);
    exit;
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// SECURITY: Request Validation
// ═════════════════════════════════════════════════════════════════════════════
// Enforce maximum payload size (prevent DoS via massive requests)
$maxPayloadSize = 5 * 1024 * 1024; // 5 MB
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxPayloadSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large']);
    exit;
}

// Validate Content-Type for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['error' => 'Content-Type must be application/json']);
        exit;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// SECURITY: Input validation - Whitelist allowed actions
// ═════════════════════════════════════════════════════════════════════════════
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Validate JSON parsing
if (json_last_error() !== JSON_ERROR_NONE) {
    logSecurityEvent('INVALID_JSON', 'unknown', 'JSON decode error: ' . json_last_error_msg(), 'WARN');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$input = $input ?? [];
$action = $input['action'] ?? '';

// Validate action is a string (not array/object injection)
if (!is_string($action)) {
    logSecurityEvent('INVALID_REQUEST', 'unknown', 'Action is not a string', 'WARN');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action format']);
    exit;
}

// Health check endpoint (always available)
if ($action === 'health') {
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
    exit;
}

$allowedActions = [
    'login',
    'logout',
    'verify_settings_password',
    'set_password',
    'forgot_password',
    'reset_password',
    'get_all',
    'get_all_data',
    'set',
    'clear_all',
    'clear_data',
    'clear_auctions',
    'get_auctions',
    'save_auctions',
    'delete_auction',
    'get_items',
    'save_items',
    'get_pending_donations',
    'mark_donations_imported',
    'get_bidders',
    'save_bidders',
    'get_winners',
    'save_winners',
    'get_payments',
    'save_payments',
    'get_settings',
    'save_settings',
    'get_members',
    'save_members',
    'get_registrations',
    'save_registrations',
    'save_workflow_step',
    'delete_workflow_step',
    'get_fieldmap',
    'save_fieldmap',
    'get_emails',
    'save_emails',
    'get_regdb',
    'get_table_data',
    'db_stats',
    'show_tables',
    'show_keys',
    'init_tables',
    'log',
    'get_debug_log',
    'get_audit_log',
    'create_backup',
    'list_backups',
    'get_backup_history',
    'delete_backup',
    'download_backup',
    'get_backup_auctions',
    'restore_backup',
    'get_backup_schedule',
    'save_backup_schedule',
    'send_email',
    // Phase 3 validation actions
    'validate_payment',
    'validate_category',
    'validate_bidder',
    'check_email_scan_cooldown',
    'check_duplicate_email',
    'get_storage_report',
];

// Actions that don't require authentication. Deliberately minimal: every
// action that returns stored data (get_all, get_items, get_bidders, etc.)
// used to be public, which let anyone with the API URL — no password needed
// — dump the full bidder/donor PII, payment records, and even the admin
// password fields straight out of get_all/get_settings. All reads now require
// the same session the client already establishes via the app's password
// screen (action:'login'); the client re-syncs data immediately after login.
$publicActions = [
    'login',  // Establishes the authenticated session
    'logout', // Clears the session
    'health', // API health check
    'log',    // Debug logging — called throughout boot, before login resolves
    'forgot_password', // Emails a reset link — must work while locked out
    'reset_password',  // Consumes the token from that email — same reason
];

// Validate action is in whitelist
if (!in_array($action, $allowedActions, true)) {
    logSecurityEvent('INVALID_ACTION', sanitizeInput($action, 100), 'Action not in whitelist', 'WARN');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// SECURITY: Session validation (except for public actions)
// ═════════════════════════════════════════════════════════════════════════════
if (!in_array($action, $publicActions, true)) {
    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        logSecurityEvent('UNAUTHORIZED_ACCESS', $action, 'Session not authenticated', 'WARN');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Phase 2: Server-side session timeout validation
    $sessionTimeout = (int)($env['SESSION_TIMEOUT'] ?? 1800); // Default 30 minutes
    $sessionCheck = validateSessionTimeout($sessionTimeout);
    if (!$sessionCheck['valid']) {
        http_response_code(401);
        echo json_encode(['error' => $sessionCheck['message']]);
        exit;
    }

    // CSRF validation for destructive/irreversible actions. The client sends
    // the token issued at login (X-CSRF-Token) via secureApiFetch(). Scoped to
    // actions confirmed to go through that helper, rather than every
    // authenticated request, so a fetch() call site missed in this audit can't
    // silently break the live app.
    $csrfProtectedActions = ['save_settings', 'set_password', 'delete_auction', 'clear_all', 'clear_data', 'clear_auctions', 'delete_backup', 'restore_backup', 'save_backup_schedule'];
    if (in_array($action, $csrfProtectedActions, true)) {
        $sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sentToken)) {
            logSecurityEvent('CSRF_FAILURE', $action, 'CSRF token validation failed');
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token missing or invalid']);
            exit;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: Security Event Logging
// ═════════════════════════════════════════════════════════════════════════════
function logSecurityEvent($eventType, $action, $details = '', $severity = 'INFO') {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userId = $_SESSION['user_id'] ?? $_SESSION['authenticated_user'] ?? 'anonymous';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$severity}] {$eventType} | action:{$action} | user:{$userId} | ip:{$ip} | {$details}";

    // Log to security.log
    $logFile = __DIR__ . '/logs/security.log';
    @mkdir(__DIR__ . '/logs', 0750, true);
    error_log($logEntry, 3, $logFile);

    // Also to main error log for critical events
    if ($severity === 'CRITICAL') {
        error_log("[SECURITY_CRITICAL] {$logEntry}");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: Response Filtering (prevent sensitive data leakage)
// ═════════════════════════════════════════════════════════════════════════════
function filterSensitiveData($data) {
    if (!is_array($data) && !is_object($data)) {
        return $data;
    }

    $sensitiveFields = [
        'password', 'passwordHash', 'settingsPassword',
        'encryption_key', 'encryptionKey', 'secret', 'token',
        'api_key', 'apiKey', 'private_key', 'privateKey',
        'db_pass', 'dbPass', 'database_password', 'databasePassword'
    ];

    $isArray = is_array($data);
    $filtered = $isArray ? [] : new stdClass();

    foreach ($data as $key => $value) {
        $shouldFilter = false;

        // Check exact match
        if (in_array($key, $sensitiveFields, true)) {
            $shouldFilter = true;
        }

        // Check case-insensitive for common patterns
        $lowerKey = strtolower($key);
        foreach ($sensitiveFields as $field) {
            if (strpos($lowerKey, strtolower($field)) !== false) {
                $shouldFilter = true;
                break;
            }
        }

        if ($shouldFilter) {
            $filteredValue = '***REDACTED***';
        } else if (is_array($value) || is_object($value)) {
            $filteredValue = filterSensitiveData($value);
        } else {
            $filteredValue = $value;
        }

        if ($isArray) {
            $filtered[$key] = $filteredValue;
        } else {
            $filtered->$key = $filteredValue;
        }
    }

    return $filtered;
}

// ═════════════════════════════════════════════════════════════════════════════
// Database connection
// ═════════════════════════════════════════════════════════════════════════════
$host = $env['DB_HOST'];
$db   = $env['DB_NAME'];
$user = $env['DB_USER'];
$pass = $env['DB_PASS'];

// Allowed key suffixes (after sam_ prefix). Supports both static keys (sam_items)
// and namespaced keys (sam_{auctionId}_items)
// 'members_import_history' added 2026-09-15 — a real bug: sam_members_import_history
// (added in v6.9) was assumed to ride this generic sam_-prefix auto-sync with no new
// API action needed, but its key ('sam_' + 'members_import_history') never actually
// matched this whitelist — 'members_import_history' isn't an exact static suffix, and
// splitting at the LAST underscore gives 'history', which also isn't allowed. Every
// write was silently rejected server-side ({"error":"Invalid key"}), so the import log
// only ever lived in whichever single browser wrote it — never persisted, never synced.
$ALLOWED_SUFFIXES = ['items', 'bidders', 'winners', 'payments', 'settings', 'fieldmap', 'emails', 'members', 'members_import_history', 'regdb', 'auctions', 'current_auction'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
    ]);
    // Enforce strict SQL mode for consistency
    $pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (Exception $e) {
    error_log("[DB_ERROR] Connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit;
}

// Auto-create table (increase VARCHAR size to handle longer namespaced keys)
$pdo->exec("CREATE TABLE IF NOT EXISTS sam_store (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      LONGTEXT     NOT NULL,
    `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Holds public item-donation-form submissions (donate-item.php) until staff
// import them into the app via "Import Donated Items" on Load Item Emails.
// Deliberately its own append-only table (AUTO_INCREMENT id) rather than a
// sam_store JSON blob — donate-item.php has no session/auth, so concurrent
// public submissions must be safe without a read-modify-write race, which a
// plain INSERT gives us for free. Never written to by save_items's
// full-replace, so nothing here can be silently wiped by a normal app sync.
$pdo->exec("CREATE TABLE IF NOT EXISTS donated_items_pending (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    category_code    VARCHAR(10)  NOT NULL,
    category_name    VARCHAR(255),
    description      LONGTEXT,
    item_value       VARCHAR(20),
    donor_name       VARCHAR(255),
    donor_email      VARCHAR(255),
    donor_phone      VARCHAR(20),
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending',
    submitted_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ═════════════════════════════════════════════════════════════════════════════
// Phase 2: Rate Limiting Check
// ═════════════════════════────────────────────────────────────────────────────
$rateLimitingEnabled = $env['RATE_LIMITING_ENABLED'] ?? 'true';
if ($rateLimitingEnabled === 'true' && !in_array($action, ['init_tables', 'log'], true)) {
    $config = getRateLimitConfig($action);
    $rateCheck = checkRateLimit($action, $config['maxRequests'], $config['windowSeconds']);

    if (!$rateCheck['allowed']) {
        logSecurityEvent('RATE_LIMIT_EXCEEDED', $action, "Retry after {$rateCheck['retry_after']}s", 'WARN');
        http_response_code(429);
        header('Retry-After: ' . $rateCheck['retry_after']);
        echo json_encode([
            'error' => $rateCheck['error'],
            'retry_after' => $rateCheck['retry_after']
        ]);
        exit;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Phase 3: Set Request Timeout (prevent long-running requests)
// ═════════════════════════════════════════════════════════════════════════════
setSafeTimeout(30); // 30 second timeout for API requests

// Logging helper
function logQuery($action, $query, $status, $details = '') {
    $logFile = __DIR__ . '/debug_log.txt';
    // Use EDT timezone for consistency with UI
    date_default_timezone_set('America/New_York');
    $timestamp = date('m/d/Y, g:i:s A');
    $logEntry = "[$timestamp] [API:$action] [$status] $query";
    if ($details) $logEntry .= " | $details";
    $logEntry .= "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

logQuery($action, 'START', 'REQUEST', "Action=$action");

// ═════════════════════════════════════════════════════════════════════════════
// Settings password guard (server-side backstop against lockouts)
// ═════════════════════════════════════════════════════════════════════════════
// The sam_settings blob is a FULL overwrite written by ~15 "Save Settings"
// buttons, several load-time paths, and the localStorage auto-sync hook (which
// re-posts sam_settings via 'set' on every local write, including boot-time
// migrations that run BEFORE the tab pulls fresh data from the server).
//
// This guard used to allow a blob write to change a password as long as the
// incoming value was non-empty, different, and not the 'Gladiator#1' default.
// That still let any tab holding an OLDER real password (last synced before a
// change on another device, or before a change in this same tab reached the
// server) silently roll the stored password back — which is how the Developer
// gate ended up rejecting both the old and the new settings password.
//
// Now strict: once a password field has a stored value, a settings blob write
// can NEVER change it. The only ways to change a stored password are the
// dedicated 'set_password' action (Settings > Security's Change Password
// buttons) and 'reset_password' (emailed reset link). A blob write can still
// supply the first-ever value when nothing is stored yet.
function sam_guard_settings_passwords($incoming, PDO $pdo) {
    if (!is_array($incoming)) return $incoming;
    $existing = [];
    try {
        $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1")->fetchColumn();
        if ($val) $existing = json_decode($val, true) ?: [];
    } catch (Exception $e) {
        return $incoming; // can't read existing — don't interfere
    }
    foreach (['password', 'settingsPassword'] as $field) {
        $exVal = isset($existing[$field]) ? (string)$existing[$field] : '';
        if ($exVal === '') continue; // nothing stored yet — allow the first-time set
        $incoming[$field] = $exVal;  // stored value always wins over a blob write
    }
    return $incoming;
}

// Reads the current settingsPassword (Developer password), falling back to
// the same default DEFAULT_SETTINGS uses client-side when nothing is stored
// yet (fresh install, before the Developer password has ever been set).
// Shared by 'verify_settings_password' and 'restore_backup' (which re-verifies
// this same password inline before doing anything destructive) so the two
// checks can't drift.
function sam_get_settings_password(PDO $pdo) {
    $stored = '';
    $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1")->fetchColumn();
    if ($val) {
        $settings = json_decode($val, true);
        if (is_array($settings) && !empty($settings['settingsPassword'])) $stored = (string)$settings['settingsPassword'];
    }
    return $stored !== '' ? $stored : 'Gladiator#1';
}

// Writes ONE password field into sam_settings, leaving every other key as
// stored. Shared by 'set_password' and 'reset_password' — the only two paths
// allowed to change a password that's already set (see guard above).
function sam_write_settings_password(PDO $pdo, $field, $newPassword) {
    $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1")->fetchColumn();
    $settings = $val ? json_decode($val, true) : [];
    if (!is_array($settings)) $settings = [];
    $settings[$field] = $newPassword;
    $stmt = $pdo->prepare(
        "INSERT INTO sam_store (`key`, `value`) VALUES ('sam_settings', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    $stmt->execute([json_encode($settings)]);
}

// Validates a new password for either field. Returns an error string, or ''
// when it's acceptable. Any value is allowed, including the DEFAULT_SETTINGS
// value — the old "not the default" rule only existed to spot stale-blob
// corruption, which sam_guard_settings_passwords() now blocks outright, and it
// stopped admins deliberately choosing that password.
function sam_validate_new_password($field, $pw1, $pw2 = null) {
    if (strlen($pw1) < 6) return 'Password must be at least 6 characters.';
    if ($pw2 !== null && $pw1 !== $pw2) return 'Passwords do not match.';
    return '';
}

if ($action === 'login') {
    // Server-side authentication. Establishes $_SESSION['authenticated'] so that
    // protected write actions (set, save_*, delete_*) are permitted. The expected
    // password comes from the stored settings, falling back to the env default.
    $entered = (string)($input['password'] ?? '');

    // Accept the app password/staff-settings password (dynamic, admin-set) or
    // the env bootstrap default (only relevant before any settings row exists).
    // Also always accepts the literal 'Gladiator#1' as a second, fixed login
    // password — explicitly requested by the user (2026-09-15) as a standing
    // second password for the main app login. Unlike the dynamic
    // password/settingsPassword values above, this one is NOT stored in
    // settings and can't be changed or revoked from the UI — changing it
    // requires editing this line and redeploying api.php. If that tradeoff
    // stops being acceptable, remove this line rather than trying to manage
    // it as a "setting".
    $accepted = [$env['DEFAULT_PASSWORD'] ?? 'ETCCauctionoct2026', 'Gladiator#1'];
    try {
        $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1")->fetchColumn();
        if ($val) {
            $settings = json_decode($val, true);
            if (!empty($settings['password']))         $accepted[] = (string)$settings['password'];
            if (!empty($settings['settingsPassword'])) $accepted[] = (string)$settings['settingsPassword'];
        }
    } catch (Exception $e) {
        // Fall back to defaults if settings can't be read
    }

    $match = false;
    foreach ($accepted as $pw) {
        if ($entered !== '' && hash_equals((string)$pw, $entered)) { $match = true; break; }
    }

    if ($match) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        // Issue a server-tied CSRF token. The client stores it and sends it back
        // via X-CSRF-Token on subsequent requests; validated below for every
        // authenticated action. Previously the client generated its own token
        // that the server never checked — real protection, not just the header.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        logQuery($action, 'LOGIN', 'SUCCESS', 'Authenticated');
        logSecurityEvent('LOGIN_SUCCESS', 'login', 'User authenticated successfully', 'INFO');
        // Best-effort daily auto-backup check — see samBackupAutoCheck()'s own
        // comment in security-helpers.php for why this is SAM's stand-in for
        // a dedicated scheduled task. Never let a backup failure break login.
        try { samBackupAutoCheck($pdo, __DIR__ . '/backups', $env['DB_NAME']); } catch (Exception $e) {}
        echo json_encode(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
    } else {
        logQuery($action, 'LOGIN', 'FAIL', 'Bad password');
        logSecurityEvent('LOGIN_FAILURE', 'login', 'Invalid password provided', 'WARN');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
    }

} elseif ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['success' => true]);

} elseif ($action === 'verify_settings_password') {
    // Developer gate (the "Settings Password" prompt behind the hamburger's
    // Developer row, Delete Auction, and deep links to developer screens).
    // submitAuthPassword() used to compare against the browser's own
    // localStorage copy of sam_settings — which can be stale (another device
    // changed the password, a failed re-sync, or DEFAULT_SETTINGS filling in
    // 'Gladiator#1' when the key was missing), so the OLD password kept
    // working in one tab while the NEW one was rejected. The server's
    // sam_settings row is the source of truth; check it here instead.
    // Wrong password deliberately returns 403, never 401 — index.html's fetch
    // wrapper treats any 401 from api.php as an expired session and silently
    // re-logs-in and retries, which would double every failed attempt.
    $entered = (string)($input['password'] ?? '');
    try {
        $stored = sam_get_settings_password($pdo);
    } catch (Exception $e) {
        logQuery($action, 'VERIFY_SETTINGS_PASSWORD', 'ERROR', $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not read settings from the server.']);
        exit;
    }

    if ($entered !== '' && hash_equals($stored, $entered)) {
        logQuery($action, 'VERIFY_SETTINGS_PASSWORD', 'SUCCESS', 'Developer gate unlocked');
        logSecurityEvent('SETTINGS_AUTH_SUCCESS', $action, 'Settings password verified', 'INFO');
        echo json_encode(['success' => true]);
    } else {
        logQuery($action, 'VERIFY_SETTINGS_PASSWORD', 'FAIL', 'Bad settings password');
        logSecurityEvent('SETTINGS_AUTH_FAILURE', $action, 'Invalid settings password provided', 'WARN');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
    }

} elseif ($action === 'set_password') {
    // Settings > Security's two Change Password buttons. The only
    // authenticated path allowed to change a password that's already stored —
    // save_settings / set preserve both fields (sam_guard_settings_passwords).
    $field = (string)($input['field'] ?? '');
    $newPassword = (string)($input['password'] ?? '');
    if (!in_array($field, ['password', 'settingsPassword'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid password field.']);
        exit;
    }
    $err = sam_validate_new_password($field, $newPassword);
    if ($err !== '') {
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    try {
        sam_write_settings_password($pdo, $field, $newPassword);
        logQuery($action, 'SET_PASSWORD', 'SUCCESS', "Field: $field");
        logSecurityEvent('PASSWORD_CHANGED', $action, "Changed $field via Settings", 'INFO');
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        logQuery($action, 'SET_PASSWORD', 'ERROR', $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not save the new password — please try again.']);
    }

} elseif ($action === 'forgot_password') {
    // SAM has one shared password (no per-user accounts), so a self-service
    // reset with no identity check would just let anyone reset it. Instead
    // this emails a time-limited reset link to the club's fixed admin
    // address (RESET_ADMIN_EMAIL in .env) — whoever controls that inbox is
    // the person who should be able to reset the app password. Same pattern
    // as the Car Show app's forgot-password.php.
    //
    // scope 'settings' (from the Developer password prompt) resets
    // settingsPassword instead of the login password. Each scope keeps its
    // own token row, so requesting one kind of reset can't invalidate a
    // pending link for the other.
    $scope = (($input['scope'] ?? '') === 'settings') ? 'settings' : 'login';
    $tokenKey = $scope === 'settings' ? 'sam_settings_password_reset' : 'sam_password_reset';
    $adminEmail = $env['RESET_ADMIN_EMAIL'] ?? '';
    if (empty($adminEmail)) {
        logQuery($action, 'FORGOT_PASSWORD', 'ERROR', 'RESET_ADMIN_EMAIL not configured');
        echo json_encode(['success' => false, 'error' => 'Password reset is not configured.']);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 86400; // 24 hours
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        $stmt->execute([$tokenKey, json_encode(['token' => $token, 'expiresAt' => $expiresAt])]);
    } catch (Exception $e) {
        logQuery($action, 'FORGOT_PASSWORD', 'ERROR', $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not start a password reset right now — please try again in a moment.']);
        exit;
    }

    $resetUrl = 'https://etccapps.com/apps/sam/reset-password.html?token=' . $token . ($scope === 'settings' ? '&scope=settings' : '');
    $what = $scope === 'settings' ? 'Developer (Settings) password' : 'login';
    $subject = $scope === 'settings'
        ? 'Silent Auction Manager — Developer password reset requested'
        : 'Silent Auction Manager — password reset requested';
    $body = "A password reset was requested for Silent Auction Manager's $what.\n\n" .
        "Reset it here (link expires in 24 hours):\n" . $resetUrl . "\n\n" .
        "If you didn't request this, you can ignore this email — the link " .
        "expires on its own and nothing changes until someone opens it.";

    if (sam_send_mail($adminEmail, $subject, $body, $env)) {
        logQuery($action, 'FORGOT_PASSWORD', 'SUCCESS', "Reset email sent (scope: $scope)");
        logSecurityEvent('PASSWORD_RESET_REQUESTED', $action, "Reset link emailed to admin (scope: $scope)", 'INFO');
        echo json_encode(['success' => true]);
    } else {
        logQuery($action, 'FORGOT_PASSWORD', 'ERROR', 'SMTP send failed');
        echo json_encode(['success' => false, 'error' => 'Could not send the reset email — please try again later or contact your administrator.']);
    }

} elseif ($action === 'reset_password') {
    // Reached via the link forgot_password emails. Validates the one-time
    // token from sam_store's sam_password_reset entry, then overwrites the
    // password field in sam_settings — preserving every other settings key
    // (a naive full overwrite would silently wipe unrelated settings, e.g.
    // startingBidPct/emailFolder). Token is deleted after one use.
    // scope 'settings' validates against that scope's own token row and
    // writes settingsPassword instead — a login-reset token can't be replayed
    // to change the Developer password, or vice versa.
    $token = (string)($input['token'] ?? '');
    $pw1 = (string)($input['password'] ?? '');
    $pw2 = (string)($input['password2'] ?? '');
    $scope = (($input['scope'] ?? '') === 'settings') ? 'settings' : 'login';
    $tokenKey = $scope === 'settings' ? 'sam_settings_password_reset' : 'sam_password_reset';
    $field = $scope === 'settings' ? 'settingsPassword' : 'password';

    $valid = false;
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM sam_store WHERE `key` = ? LIMIT 1");
        $stmt->execute([$tokenKey]);
        $val = $stmt->fetchColumn();
        if ($val) {
            $reset = json_decode($val, true);
            $valid = isset($reset['token'], $reset['expiresAt']) &&
                is_string($reset['token']) && $token !== '' &&
                hash_equals($reset['token'], $token) && time() < (int)$reset['expiresAt'];
        }
    } catch (Exception $e) {
        $valid = false;
    }

    if (!$valid) {
        logSecurityEvent('PASSWORD_RESET_INVALID_TOKEN', $action, 'Invalid or expired reset token', 'WARN');
        echo json_encode(['success' => false, 'error' => 'This reset link is invalid or has expired. Request a new one.']);
        exit;
    }
    $err = sam_validate_new_password($field, $pw1, $pw2);
    if ($err !== '') {
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }

    try {
        sam_write_settings_password($pdo, $field, $pw1);
        $stmt = $pdo->prepare("DELETE FROM sam_store WHERE `key` = ?");
        $stmt->execute([$tokenKey]); // one-time use

        logQuery($action, 'RESET_PASSWORD', 'SUCCESS', "Password reset via emailed link (scope: $scope)");
        logSecurityEvent('PASSWORD_RESET_SUCCESS', $action, "Password changed via reset link (scope: $scope)", 'INFO');
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        logQuery($action, 'RESET_PASSWORD', 'ERROR', $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not save the new password — please try again.']);
    }

} elseif ($action === 'clear_data') {
    // Clear a per-auction data type either for ALL auctions or one specific
    // auction. Removes both the relational rows and the namespaced kv entries.
    $type  = $input['type'] ?? '';
    $scope = $input['scope'] ?? '';   // 'all' or a specific auction_id
    $map = [
        'items'    => 'items',
        'bidders'  => 'bidders',
        'winners'  => 'winners',
        'payments' => 'payments',
        'emails'   => 'emails',
    ];
    if (!isset($map[$type])) {
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }
    $table = $map[$type];   // table name == kv suffix
    try {
        if ($scope === 'all') {
            $pdo->exec("DELETE FROM `$table`");
            // kv keys: sam_<suffix> and sam_<auctionId>_<suffix>
            $pdo->prepare("DELETE FROM sam_store WHERE `key` = ? OR `key` LIKE ? ESCAPE '\\\\'")
                ->execute(["sam_$table", "sam\\_%\\_$table"]);
            logQuery($action, "DELETE $table", 'SUCCESS', "Cleared $type for ALL auctions");
        } else {
            $pdo->prepare("DELETE FROM `$table` WHERE auction_id = ?")->execute([$scope]);
            $pdo->prepare("DELETE FROM sam_store WHERE `key` = ?")->execute(["sam_{$scope}_$table"]);
            logQuery($action, "DELETE $table", 'SUCCESS', "Cleared $type for auction=$scope");
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, "clear_data $type", 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to clear data: ' . $e->getMessage()]);
    }

} elseif ($action === 'clear_auctions') {
    // Delete every auction AND all auction-scoped data (so nothing is orphaned).
    // Leaves club-wide data (members, registrations, settings, fieldmap) intact.
    try {
        $types = ['items', 'bidders', 'winners', 'payments', 'emails'];
        foreach ($types as $t) {
            try { $pdo->exec("DELETE FROM `$t`"); } catch (Exception $inner) {
                logQuery($action, "DELETE $t", 'WARN', $inner->getMessage());
            }
        }
        $pdo->exec("DELETE FROM auctions");
        $pdo->prepare("DELETE FROM sam_store WHERE `key` IN ('sam_auctions','sam_current_auction')")->execute();
        foreach ($types as $t) {
            $pdo->prepare("DELETE FROM sam_store WHERE `key` = ? OR `key` LIKE ? ESCAPE '\\\\'")
                ->execute(["sam_$t", "sam\\_%\\_$t"]);
        }
        logQuery($action, 'clear_auctions', 'SUCCESS', 'All auctions + scoped data cleared');
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, 'clear_auctions', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to clear auctions: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_all') {
    try {
        // Returns a flat key-value map { "sam_items": "...", ... } which the
        // client's syncFromKeyValueDB() iterates with Object.entries().
        // Do NOT paginate or wrap this — the client expects the flat shape.
        // Password-reset token rows are excluded: they're server-only, and
        // shipping them to every logged-in browser would let anyone with the
        // login password consume a pending Developer-password reset link
        // without access to the admin inbox it was emailed to.
        $query = "SELECT `key`, `value` FROM sam_store WHERE `key` NOT IN ('sam_password_reset', 'sam_settings_password_reset')";
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);

        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows));
        echo json_encode($rows ?: new stdClass());
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to get data']);
    }

} elseif ($action === 'set') {
    $key = $input['key']   ?? '';
    $val = $input['value'] ?? '';

    // Check if key is allowed. Supports static keys (sam_items,
    // sam_current_auction) and namespaced keys (sam_{auctionId}_items).
    $isAllowed = false;
    if (strpos($key, 'sam_') === 0) {
        // Extract the part after 'sam_'
        $rest = substr($key, 4);
        // Exact static match FIRST — 'current_auction' itself contains an
        // underscore, so checking only "no underscore = static" below would
        // (and for a long time silently did) misparse 'sam_current_auction'
        // as a namespaced key with suffix 'auction', which isn't allowed,
        // rejecting every write and leaving the server never knowing which
        // auction is current.
        $isAllowed = in_array($rest, $ALLOWED_SUFFIXES, true);
        if (!$isAllowed) {
            // Namespaced key like 'sam_{id}_items': suffix after last underscore
            $lastUnderscore = strrpos($rest, '_');
            if ($lastUnderscore !== false) {
                $suffix = substr($rest, $lastUnderscore + 1);
                $isAllowed = in_array($suffix, $ALLOWED_SUFFIXES, true);
            }
        }
    }
    if (!$isAllowed) {
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }
    // Guard the password fields when the full settings blob is written through
    // this generic path (the client's auto-sync hook posts sam_settings here on
    // every localStorage write — the most frequent clobber vector).
    if ($key === 'sam_settings') {
        $decoded = json_decode((string)$val, true);
        if (is_array($decoded)) {
            $decoded = sam_guard_settings_passwords($decoded, $pdo);
            $val = json_encode($decoded);
        }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    $stmt->execute([$key, $val]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'clear_all') {
    // Purge all stored data. Requires a token to prevent accidental clearing.
    $token = $input['token'] ?? '';
    if ($token !== 'samclear2026') {
        echo json_encode(['error' => 'Invalid or missing token']);
        exit;
    }
    $pdo->exec("TRUNCATE TABLE sam_store");
    echo json_encode(['ok' => true, 'message' => 'All data cleared']);

} elseif ($action === 'get_auctions') {
    // Read auctions from MySQL table
    try {
        $rows = $pdo->query("SELECT * FROM auctions")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to read auctions: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_all_data') {
    // Read all data from MySQL tables
    try {
        // Phase 3: Support pagination with per-table limits
        $page = intval($input['page'] ?? 1);
        $limit = intval($input['limit'] ?? 100);
        $table = $input['table'] ?? null; // Optional: specific table

        if ($table) {
            // Get single table with pagination
            $allowedTables = ['auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'emails', 'members', 'registrations'];
            if (!in_array($table, $allowedTables, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid table']);
                exit;
            }

            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $paginated = paginate($rows, $page, $limit);

            echo json_encode([
                'table' => $table,
                'data' => $paginated['items'],
                'page' => $paginated['page'],
                'limit' => $paginated['limit'],
                'total' => $paginated['total'],
                'totalPages' => $paginated['totalPages'],
                'hasMore' => $paginated['hasMore']
            ]);
        } else {
            // Get all tables (items may be paginated)
            $data = [
                'auctions' => $pdo->query("SELECT * FROM auctions")->fetchAll(PDO::FETCH_ASSOC),
                'items' => $pdo->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC),
                'bidders' => $pdo->query("SELECT * FROM bidders")->fetchAll(PDO::FETCH_ASSOC),
                'winners' => $pdo->query("SELECT * FROM winners")->fetchAll(PDO::FETCH_ASSOC),
                'payments' => $pdo->query("SELECT * FROM payments")->fetchAll(PDO::FETCH_ASSOC),
                'settings' => $pdo->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_ASSOC),
            ];

            // Apply pagination to items (typically the largest table)
            if (!empty($data['items'])) {
                $itemsPaginated = paginate($data['items'], $page, $limit);
                $data['items'] = $itemsPaginated['items'];
                $data['itemsPagination'] = [
                    'page' => $itemsPaginated['page'],
                    'limit' => $itemsPaginated['limit'],
                    'total' => $itemsPaginated['total'],
                    'totalPages' => $itemsPaginated['totalPages'],
                    'hasMore' => $itemsPaginated['hasMore']
                ];
            }

            echo json_encode($data);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to read data: ' . $e->getMessage()]);
    }

} elseif ($action === 'db_stats') {
    // Get database statistics from all SQL tables
    try {
        // Whitelist allowed tables to prevent arbitrary table access
        $allowedTables = ['auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'emails', 'members', 'registrations', 'fieldmap', 'workflow_steps', 'sam_store'];

        $query = "SHOW TABLES";
        $tables = $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
        $stats = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            // Only count whitelisted tables
            if (!in_array($table, $allowedTables, true)) {
                continue;
            }

            $countQuery = "SELECT COUNT(*) FROM `$table`";
            $count = $pdo->query($countQuery)->fetchColumn();
            $stats[$table] = (int)$count;
            $totalRows += $count;
        }

        logQuery($action, 'Database statistics', 'SUCCESS', 'Total rows: ' . $totalRows);
        echo json_encode([
            'stats' => $stats,
            'total_rows' => $totalRows,
            'table_count' => count($stats)
        ]);
    } catch (Exception $e) {
        logQuery($action, 'Database statistics', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to get database stats: ' . $e->getMessage()]);
    }

} elseif ($action === 'show_tables') {
    // Show all tables and their row counts
    try {
        // Whitelist allowed tables
        $allowedTables = ['auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'emails', 'members', 'registrations', 'fieldmap', 'workflow_steps', 'sam_store'];

        $query = "SHOW TABLES";
        $tables = $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        foreach ($tables as $table) {
            // Only show whitelisted tables
            if (!in_array($table, $allowedTables, true)) {
                continue;
            }

            $countQuery = "SELECT COUNT(*) FROM `$table`";
            $count = $pdo->query($countQuery)->fetchColumn();
            $descQuery = "DESCRIBE `$table`";
            $columns = $pdo->query($descQuery)->fetchAll(PDO::FETCH_ASSOC);
            $result[] = [
                'table' => $table,
                'rows' => $count,
                'columns' => count($columns),
                'fields' => array_map(function($col) { return $col['Field']; }, $columns)
            ];
        }
        logQuery($action, $query, 'SUCCESS', 'Tables: ' . count($result));
        echo json_encode($result);
    } catch (Exception $e) {
        logQuery($action, 'SHOW TABLES', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to show tables: ' . $e->getMessage()]);
    }

} elseif ($action === 'show_keys') {
    // Show all keys in sam_store
    try {
        $query = "SELECT `key` FROM sam_store ORDER BY `key`";
        $keys = $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
        logQuery($action, $query, 'SUCCESS', 'Keys: ' . count($keys));
        echo json_encode(['keys' => $keys, 'total' => count($keys)]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to show keys: ' . $e->getMessage()]);
    }

} elseif ($action === 'init_tables') {
    // Initialize application tables
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auctions (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT 'active',
            created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Per-auction tables are scoped by auction_id. They are recreated with
        // the composite-key schema; the authoritative copy lives in sam_store
        // (namespaced keys) and is rewritten on every save, so dropping here is
        // non-destructive to the app. Existing pre-scope rows cannot be reliably
        // attributed to an auction, so they are not migrated.
        $pdo->exec("DROP TABLE IF EXISTS winners");
        $pdo->exec("DROP TABLE IF EXISTS payments");
        $pdo->exec("DROP TABLE IF EXISTS items");
        $pdo->exec("DROP TABLE IF EXISTS bidders");

        $pdo->exec("CREATE TABLE items (
            auction_id VARCHAR(50) NOT NULL DEFAULT '',
            item_number VARCHAR(20) NOT NULL,
            email_message_id VARCHAR(255),
            item_category VARCHAR(255),
            description LONGTEXT,
            item_value VARCHAR(20),
            reserve_amount VARCHAR(20),
            donor_name VARCHAR(255),
            donor_email VARCHAR(255),
            donor_phone VARCHAR(20),
            submission_date VARCHAR(255),
            date_loaded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (auction_id, item_number),
            INDEX idx_category (item_category),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE bidders (
            auction_id VARCHAR(50) NOT NULL DEFAULT '',
            bidder_number INT NOT NULL,
            last_name VARCHAR(255),
            first_name VARCHAR(255),
            email VARCHAR(255),
            phone VARCHAR(20),
            bidder_type VARCHAR(50),
            PRIMARY KEY (auction_id, bidder_number),
            INDEX idx_email (email),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE winners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auction_id VARCHAR(50) NOT NULL DEFAULT '',
            item_number VARCHAR(20),
            bidder_number INT NULL,
            bidder_name VARCHAR(255),
            winning_bid VARCHAR(20),
            UNIQUE KEY unique_auction_item (auction_id, item_number),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auction_id VARCHAR(50) NOT NULL DEFAULT '',
            bidder_number INT NULL,
            checknum VARCHAR(50),
            method VARCHAR(50),
            paid INT,
            other VARCHAR(255),
            otherReason VARCHAR(255),
            UNIQUE KEY unique_auction_bidder (auction_id, bidder_number),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gmailClientId VARCHAR(255),
            inboxEmail VARCHAR(255),
            debugMode INT,
            environment VARCHAR(50),
            versionMajor INT,
            versionMinor INT,
            startingBidPct INT,
            finalBidPct INT,
            bidCount INT,
            squarePct DECIMAL(5,2),
            squareFee DECIMAL(5,2),
            created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS emails (
            id VARCHAR(255) PRIMARY KEY,
            auction_id VARCHAR(50),
            from_email VARCHAR(255),
            subject VARCHAR(255),
            body LONGTEXT,
            received TIMESTAMP,
            FOREIGN KEY (auction_id) REFERENCES auctions(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS members (
            member_number INT PRIMARY KEY,
            last_name VARCHAR(255),
            first_name VARCHAR(255),
            primary_email VARCHAR(255),
            cell_phone VARCHAR(20),
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (primary_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_number INT,
            last_name VARCHAR(255),
            first_name VARCHAR(255),
            email VARCHAR(255),
            cell_phone VARCHAR(20),
            registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_member (member_number),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        echo json_encode(['success' => true, 'message' => 'Database tables initialized successfully']);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to initialize tables: ' . $e->getMessage()]);
    }

// ═══════════════════════════════════════════════════════════════════════════
// TABLE CRUD OPERATIONS
// ═══════════════════════════════════════════════════════════════════════════

} elseif ($action === 'delete_auction') {
    $auctionId = $input['id'] ?? '';
    if (!$auctionId) {
        echo json_encode(['error' => 'No auction ID provided']);
        exit;
    }
    try {
        // Remove the auction and all of its scoped per-auction rows.
        foreach (['items', 'bidders', 'winners', 'payments', 'emails'] as $tbl) {
            try {
                $del = $pdo->prepare("DELETE FROM `$tbl` WHERE auction_id = ?");
                $del->execute([$auctionId]);
            } catch (Exception $inner) {
                // Table may not have auction_id yet (pre-migration) — skip safely
                logQuery($action, "DELETE FROM $tbl", 'WARN', $inner->getMessage());
            }
        }
        // Remove namespaced key-value entries for this auction
        $pdo->prepare("DELETE FROM sam_store WHERE `key` LIKE ?")->execute(["sam_{$auctionId}\\_%"]);

        $query = "DELETE FROM auctions WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        logQuery($action, $query, 'SUCCESS', "Deleted auction + scoped rows: $auctionId");
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to delete auction: ' . $e->getMessage()]);
    }

} elseif ($action === 'save_auctions') {
    $data = $input['data'] ?? [];
    try {
        // Upsert auctions into SQL table (insert or update if exists)
        $insertQuery = "INSERT INTO auctions (id, name, status, created, updated) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), updated = NOW()";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $auction) {
            $stmt->execute([
                $auction['id'] ?? null,
                $auction['name'] ?? null,
                $auction['status'] ?? 'active',
                $auction['created'] ?? date('c')
            ]);
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute(['sam_auctions', json_encode($data)]);

        $count = count($data);
        logQuery($action, $insertQuery, 'SUCCESS', "Saved $count auctions to SQL table");
        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save auctions: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_items') {
    try {
        $auctionId = $input['auction_id'] ?? '';
        $query = "SELECT * FROM items WHERE auction_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows) . " (auction=$auctionId)");
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM items', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_items' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '[]';
    }

} elseif ($action === 'save_items') {
    $data = $input['data'] ?? [];
    $auctionId = $input['auction_id'] ?? '';
    $count = 0;
    $totalItems = count($data);
    $userId = getAuthUserId();
    $encKey = $env['ENCRYPTION_KEY'] ?? '';
    $encryptionEnabled = !empty($encKey);

    // Log detailed info about what's being received
    error_log("[save_items] Received $totalItems items");
    if ($totalItems === 0) {
        error_log("[save_items] WARNING: Empty items array!");
    } else if ($totalItems > 0 && isset($data[0])) {
        error_log("[save_items] First item: " . json_encode($data[0]));
    }

    logQuery($action, 'START', 'INFO', "Received $totalItems items to save");

    try {
        // Full replace for this auction: clear its rows first so deletions and
        // "Clear Items" actually remove rows from the database.
        $pdo->prepare("DELETE FROM items WHERE auction_id = ?")->execute([$auctionId]);
        // Insert items
        // Map fields to correct database column names
        $insertQuery = "INSERT INTO items (auction_id, item_number, item_category, email_message_id, description, item_value, reserve_amount, donor_name, donor_email, donor_phone, submission_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE item_category=VALUES(item_category), description=VALUES(description), item_value=VALUES(item_value), reserve_amount=VALUES(reserve_amount), donor_name=VALUES(donor_name), donor_email=VALUES(donor_email), donor_phone=VALUES(donor_phone)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $idx => $item) {
            try {
                $itemNum = $item['item_number'] ?? null;
                $desc = $item['description'] ?? null;

                if (!$itemNum) {
                    logQuery($action, $insertQuery, 'ERROR', "Item #$idx has no item_number. Data: " . json_encode($item));
                    continue;
                }

                // Map item fields to correct database columns
                $category = $item['category_name'] ?? $item['item_category'] ?? $item['category_code'] ?? null;
                $value = $item['item_value'] ?? $item['value'] ?? null;
                $submissionDate = $item['submission_date'] ?? $item['loaded_date'] ?? date('Y-m-d H:i:s');

                // Phase 2: Sanitize and optionally encrypt PII fields
                $donorEmail = sanitizeEmail($item['donor_email'] ?? null);
                $donorPhone = sanitizePhone($item['donor_phone'] ?? null);

                // Encrypt PII if encryption is enabled
                if ($encryptionEnabled) {
                    if ($donorEmail) {
                        $donorEmail = encryptData($donorEmail, $encKey);
                    }
                    if ($donorPhone) {
                        $donorPhone = encryptData($donorPhone, $encKey);
                    }
                }

                $execData = [
                    $auctionId,
                    $itemNum,
                    $category,
                    $item['email_message_id'] ?? null,
                    $desc,
                    $value,
                    $item['reserve_amount'] ?? null,
                    $item['donor_name'] ?? null,
                    $donorEmail,
                    $donorPhone,
                    $submissionDate
                ];

                $stmt->execute($execData);
                $count++;

                // Phase 2: Log successful item save
                logAudit($pdo, $userId, 'save_items', 'items', $itemNum, null, ['item_number' => $itemNum, 'category' => $category], 'success', "Item saved");
            } catch (Exception $itemErr) {
                logQuery($action, $insertQuery, 'ERROR', "Failed to insert item {$item['item_number']}: " . $itemErr->getMessage());
                logAudit($pdo, $userId, 'save_items_error', 'items', $item['item_number'] ?? 'unknown', null, $item, 'failure', $itemErr->getMessage());
            }
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute([($auctionId ? "sam_{$auctionId}_items" : 'sam_items'), json_encode($data)]);

        logQuery($action, $insertQuery, 'SUCCESS', "Saved/updated $count of $totalItems items to SQL table");
        echo json_encode(['ok' => true, 'count' => $count, 'total' => $totalItems]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save items: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_pending_donations') {
    try {
        $query = "SELECT * FROM donated_items_pending WHERE status = 'pending' ORDER BY submitted_at ASC";
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows));
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to fetch pending donations: ' . $e->getMessage()]);
    }

} elseif ($action === 'mark_donations_imported') {
    $ids = $input['ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn($v) => $v > 0));
    if (!$ids) {
        echo json_encode(['ok' => true, 'count' => 0]);
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = "UPDATE donated_items_pending SET status = 'imported' WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($query);
            $stmt->execute($ids);
            logQuery($action, $query, 'SUCCESS', "Marked " . count($ids) . " donation(s) imported");
            echo json_encode(['ok' => true, 'count' => count($ids)]);
        } catch (Exception $e) {
            logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
            echo json_encode(['error' => 'Failed to mark donations imported: ' . $e->getMessage()]);
        }
    }

} elseif ($action === 'get_bidders') {
    try {
        $auctionId = $input['auction_id'] ?? '';
        $query = "SELECT * FROM bidders WHERE auction_id = ? ORDER BY bidder_number";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows) . " (auction=$auctionId)");
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM bidders', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_bidders' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '[]';
    }

} elseif ($action === 'save_bidders') {
    $data = $input['data'] ?? [];
    $auctionId = $input['auction_id'] ?? '';
    $count = 0;
    $userId = getAuthUserId();
    $encKey = $env['ENCRYPTION_KEY'] ?? '';
    $encryptionEnabled = !empty($encKey);

    try {
        // Full replace for this auction so "Clear Bidders"/deletions hit the DB.
        $pdo->prepare("DELETE FROM bidders WHERE auction_id = ?")->execute([$auctionId]);
        $insertQuery = "INSERT INTO bidders (auction_id, bidder_number, last_name, first_name, email, phone) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_name=VALUES(last_name), first_name=VALUES(first_name), email=VALUES(email), phone=VALUES(phone)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $bidder) {
            try {
                $bidNum = $bidder['bidder_number'] ?? null;

                // Phase 2: Sanitize and optionally encrypt PII
                $email = sanitizeEmail($bidder['email'] ?? null);
                $phone = sanitizePhone($bidder['phone'] ?? null);

                // Encrypt PII if encryption is enabled
                if ($encryptionEnabled) {
                    if ($email) {
                        $email = encryptData($email, $encKey);
                    }
                    if ($phone) {
                        $phone = encryptData($phone, $encKey);
                    }
                }

                $stmt->execute([
                    $auctionId,
                    $bidNum,
                    $bidder['last_name'] ?? null,
                    $bidder['first_name'] ?? null,
                    $email,
                    $phone
                ]);
                $count++;

                // Phase 2: Log successful bidder save
                logAudit($pdo, $userId, 'save_bidders', 'bidders', $bidNum, null, ['bidder_number' => $bidNum], 'success', "Bidder saved");
            } catch (Exception $bidErr) {
                logQuery($action, $insertQuery, 'ERROR', "Failed to insert bidder {$bidder['bidder_number']}: " . $bidErr->getMessage());
                logAudit($pdo, $userId, 'save_bidders_error', 'bidders', $bidder['bidder_number'] ?? 'unknown', null, $bidder, 'failure', $bidErr->getMessage());
            }
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute([($auctionId ? "sam_{$auctionId}_bidders" : 'sam_bidders'), json_encode($data)]);

        logQuery($action, $insertQuery, 'SUCCESS', "Saved/updated $count bidders to SQL table");
        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save bidders: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_winners') {
    try {
        $auctionId = $input['auction_id'] ?? '';
        $query = "SELECT * FROM winners WHERE auction_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['item_number']] = $row;
        }
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows) . " (auction=$auctionId)");
        echo json_encode($result);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM winners', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_winners' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '{}';
    }

} elseif ($action === 'save_winners') {
    $data = $input['data'] ?? [];
    $auctionId = $input['auction_id'] ?? '';
    $count = 0;
    $userId = getAuthUserId();

    // Log detailed info about what's being received
    error_log("[save_winners] Received " . count($data) . " winners");
    if (count($data) > 0) {
        $firstItem = array_key_first($data);
        if ($firstItem) {
            error_log("[save_winners] First item ($firstItem): " . json_encode($data[$firstItem]));
        }
    }

    try {
        // Self-heal the schema: ensure the winners table and all expected columns
        // exist. A mismatched/legacy prod table (e.g. missing winning_bid) makes
        // every INSERT throw "Unknown column", which previously surfaced as
        // count:0 with the data only surviving via the KV backup below.
        $pdo->exec("CREATE TABLE IF NOT EXISTS winners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auction_id VARCHAR(50) NOT NULL DEFAULT '',
            item_number VARCHAR(20),
            bidder_number INT NULL,
            bidder_name VARCHAR(255),
            winning_bid VARCHAR(20),
            UNIQUE KEY unique_auction_item (auction_id, item_number),
            INDEX idx_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add any columns missing from a legacy table. ADD COLUMN IF NOT EXISTS is
        // MariaDB syntax; on MySQL it errors, so each is wrapped and ignored.
        foreach ([
            "ALTER TABLE winners ADD COLUMN winning_bid VARCHAR(20)",
            "ALTER TABLE winners ADD COLUMN bidder_number INT NULL",
            "ALTER TABLE winners ADD COLUMN bidder_name VARCHAR(255)",
            "ALTER TABLE winners ADD COLUMN auction_id VARCHAR(50) NOT NULL DEFAULT ''",
            "ALTER TABLE winners ADD COLUMN item_number VARCHAR(20)",
        ] as $alter) {
            try { $pdo->exec($alter); } catch (Throwable $ignore) { /* column already exists */ }
        }

        // Full replace for this auction so cleared winners are removed from the DB.
        $pdo->prepare("DELETE FROM winners WHERE auction_id = ?")->execute([$auctionId]);
        $insertQuery = "INSERT INTO winners (auction_id, item_number, bidder_number, bidder_name, winning_bid) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bidder_number=VALUES(bidder_number), bidder_name=VALUES(bidder_name), winning_bid=VALUES(winning_bid)";
        $stmt = $pdo->prepare($insertQuery);

        $insertErrors = [];
        foreach ($data as $itemNum => $winner) {
            try {
                // Coerce bidder_number to a non-empty int or SQL NULL. An empty
                // string ('') is what the client sends for a bid-only row, and
                // inserting '' into an INT column throws on strict SQL modes.
                $bidNumRaw = $winner['bidder_number'] ?? null;
                $bidNum = ($bidNumRaw === null || $bidNumRaw === '') ? null : intval($bidNumRaw);
                $bidName = isset($winner['bidder_name']) && $winner['bidder_name'] !== '' ? $winner['bidder_name'] : null;
                $bid = $winner['winning_bid'] ?? null;

                // Validate winning bid (skip only on a genuine validation failure)
                $bidCheck = validateWinningBid($bid);
                if (!$bidCheck['valid']) {
                    $msg = $bidCheck['error'] ?? ($bidCheck['message'] ?? 'invalid bid');
                    $insertErrors[] = "item $itemNum: $msg";
                    logQuery($action, $insertQuery, 'ERROR', "Invalid bid for item $itemNum: $msg");
                    logAudit($pdo, $userId, 'save_winners_validation_failed', 'winners', $itemNum, null, $winner, 'failure', $msg);
                    continue;
                }

                error_log("[save_winners] Inserting: item=$itemNum, bidder_number=" . var_export($bidNum, true) . ", bidder_name=" . var_export($bidName, true) . ", winning_bid=$bid");

                $stmt->execute([
                    $auctionId,
                    $itemNum,
                    $bidNum,
                    $bidName,
                    $bid
                ]);
                $count++;

                logAudit($pdo, $userId, 'save_winners', 'winners', $itemNum, null, $winner, 'success', "Winner recorded: bidder=$bidNum, bid=$bid");
            } catch (Throwable $winErr) {
                // Catch Throwable (not just Exception) so PDO/TypeErrors surface.
                $insertErrors[] = "item $itemNum: " . $winErr->getMessage();
                logQuery($action, $insertQuery, 'ERROR', "Failed to insert winner for item $itemNum: " . $winErr->getMessage());
                logAudit($pdo, $userId, 'save_winners_error', 'winners', $itemNum, null, $winner, 'failure', $winErr->getMessage());
            }
        }

        // Also save to key-value store as backup. This is the authoritative copy
        // the client reads back via get_all/syncFromKeyValueDB, so persistence
        // works even if the relational insert above is skipped.
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        // Cast to object so the stored JSON is always a keyed object ({...}),
        // never a bare array ([]). The client treats winners as an object map;
        // a stored '[]' would round-trip into a JS Array and drop string keys.
        $kvStmt->execute([($auctionId ? "sam_{$auctionId}_winners" : 'sam_winners'), json_encode((object)$data)]);

        logQuery($action, $insertQuery, 'SUCCESS', "Saved/updated $count winners to SQL table");
        $resp = ['ok' => true, 'count' => $count];
        if (!empty($insertErrors)) $resp['insert_errors'] = $insertErrors;
        echo json_encode($resp);
    } catch (Throwable $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save winners: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_payments') {
    try {
        $auctionId = $input['auction_id'] ?? '';
        $query = "SELECT * FROM payments WHERE auction_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['bidder_number']] = $row;
        }
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows) . " (auction=$auctionId)");
        echo json_encode($result);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM payments', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_payments' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '{}';
    }

} elseif ($action === 'save_payments') {
    $data = $input['data'] ?? [];
    $auctionId = $input['auction_id'] ?? '';
    $count = 0;
    $userId = getAuthUserId();
    $encKey = $env['ENCRYPTION_KEY'] ?? '';

    // Log detailed info about what's being received
    error_log("[save_payments] Received " . count($data) . " payments");
    if (count($data) > 0) {
        $firstKey = array_key_first($data);
        if ($firstKey !== null) {
            error_log("[save_payments] First payment (bidder $firstKey): " . json_encode($data[$firstKey]));
        }
    }

    logQuery($action, 'START', 'INFO', "Received " . count($data) . " payments to save");

    try {
        // Full replace for this auction so cleared payments are removed from the DB.
        $pdo->prepare("DELETE FROM payments WHERE auction_id = ?")->execute([$auctionId]);
        $insertQuery = "INSERT INTO payments (auction_id, bidder_number, checknum, method, paid, other, otherReason) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE checknum=VALUES(checknum), method=VALUES(method), paid=VALUES(paid), other=VALUES(other), otherReason=VALUES(otherReason)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $bidderNum => $payment) {
            try {
                $checknum = $payment['checknum'] ?? null;
                $method = $payment['method'] ?? null;
                $paid = $payment['paid'] ?? null;
                $other = $payment['other'] ?? null;
                $otherReason = $payment['otherReason'] ?? null;

                // Phase 2: Validate payment data
                if ($method && !isValidPaymentMethod($method)) {
                    logQuery($action, $insertQuery, 'ERROR', "Invalid payment method: $method for bidder $bidderNum");
                    logAudit($pdo, $userId, 'save_payments_validation_failed', 'payments', $bidderNum, null, $payment, 'failure', "Invalid payment method: $method");
                    continue;
                }

                if ($paid) {
                    $amountCheck = validatePaymentAmount($paid);
                    if (!$amountCheck['valid']) {
                        logQuery($action, $insertQuery, 'ERROR', "Invalid amount $paid for bidder $bidderNum: " . $amountCheck['error']);
                        logAudit($pdo, $userId, 'save_payments_validation_failed', 'payments', $bidderNum, null, $payment, 'failure', $amountCheck['error']);
                        continue;
                    }
                }

                error_log("[save_payments] Inserting: bidder=$bidderNum, checknum=$checknum, method=$method, paid=$paid");

                $stmt->execute([
                    $auctionId,
                    $bidderNum,
                    $checknum,
                    $method,
                    $paid,
                    $other,
                    $otherReason
                ]);
                $count++;

                // Phase 2: Log successful payment save
                logAudit($pdo, $userId, 'save_payments', 'payments', $bidderNum, null, $payment, 'success', "Payment saved: method=$method, amount=$paid");
            } catch (Exception $payErr) {
                logQuery($action, $insertQuery, 'ERROR', "Failed to insert payment for bidder $bidderNum: " . $payErr->getMessage());
                logAudit($pdo, $userId, 'save_payments_error', 'payments', $bidderNum, null, $payment, 'failure', $payErr->getMessage());
            }
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute([($auctionId ? "sam_{$auctionId}_payments" : 'sam_payments'), json_encode($data)]);

        logQuery($action, $insertQuery, 'SUCCESS', "Saved/updated $count payments to SQL table");
        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save payments: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_settings') {
    try {
        $query = "SELECT * FROM settings LIMIT 1";
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', 'Rows: ' . count($rows));
        if ($rows) {
            echo json_encode($rows[0]);
        } else {
            // Fallback
            $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1";
            $val = $pdo->query($query)->fetchColumn();
            logQuery($action, $query, 'FALLBACK', 'Using key-value store');
            echo $val ?: '{}';
        }
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM settings', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '{}';
    }

} elseif ($action === 'save_settings') {
    $data = $input['data'] ?? [];
    $data = sam_guard_settings_passwords($data, $pdo); // never let a save drop/blank/default the passwords
    try {
        $query = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['sam_settings', json_encode($data)]);
        logQuery($action, $query, 'SUCCESS', 'Saved settings');
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save settings: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_members') {
    try {
        $query = "SELECT member_number, last_name, first_name, primary_email, cell_phone FROM members ORDER BY member_number";
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', 'Rows: ' . count($rows));
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM members', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_members' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '[]';
    }

} elseif ($action === 'save_members') {
    $data = $input['data'] ?? [];
    try {
        // Clear existing members
        $pdo->exec("DELETE FROM members");

        // Insert new members into SQL table
        $insertQuery = "INSERT INTO members (member_number, last_name, first_name, primary_email, cell_phone) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $member) {
            $stmt->execute([
                $member['member_number'] ?? null,
                $member['last_name'] ?? null,
                $member['first_name'] ?? null,
                $member['primary_email'] ?? null,
                $member['cell_phone'] ?? null
            ]);
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute(['sam_members', json_encode($data)]);

        $count = count($data);
        $timestamp = date('Y-m-d H:i:s');
        logQuery($action, $insertQuery, 'SUCCESS', "Inserted $count members into SQL table at $timestamp");
        echo json_encode(['ok' => true, 'count' => $count, 'imported_at' => $timestamp]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save members: ' . $e->getMessage()]);
    }

} elseif ($action === 'save_workflow_step') {
    $auctionId = $input['auction_id'] ?? '';
    $stepName = $input['step_name'] ?? '';
    $timestamp = $input['timestamp'] ?? null;

    if (!$auctionId || !$stepName) {
        echo json_encode(['error' => 'Missing auction_id or step_name']);
        exit;
    }

    try {
        $key = "sam_${auctionId}_ts_${stepName}";
        $query = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$key, (string)$timestamp]);
        logQuery($action, $query, 'SUCCESS', "Saved workflow step: $stepName for auction: $auctionId");
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save workflow step: ' . $e->getMessage()]);
    }

} elseif ($action === 'delete_workflow_step') {
    $auctionId = $input['auction_id'] ?? '';
    $stepName = $input['step_name'] ?? '';

    if (!$auctionId || !$stepName) {
        echo json_encode(['error' => 'Missing auction_id or step_name']);
        exit;
    }

    try {
        $key = "sam_${auctionId}_ts_${stepName}";
        $query = "DELETE FROM sam_store WHERE `key` = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$key]);
        logQuery($action, $query, 'SUCCESS', "Deleted workflow step: $stepName for auction: $auctionId");
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to delete workflow step: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_regdb') {
    try {
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_regdb' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'SUCCESS', 'Registration database retrieved');
        echo $val ?: '[]';
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to get registration database: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_registrations') {
    try {
        $query = "SELECT member_number, last_name, first_name, email, cell_phone FROM registrations ORDER BY member_number";
        $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', 'Rows: ' . count($rows));
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM registrations', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_regdb' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '[]';
    }

} elseif ($action === 'save_registrations') {
    $data = $input['data'] ?? [];
    try {
        // Clear existing registrations
        $pdo->exec("DELETE FROM registrations");

        // Insert new registrations into SQL table
        $insertQuery = "INSERT INTO registrations (member_number, last_name, first_name, email, cell_phone) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $reg) {
            $stmt->execute([
                $reg['member_number'] ?? null,
                $reg['last_name'] ?? null,
                $reg['first_name'] ?? null,
                $reg['email'] ?? null,
                $reg['cell_phone'] ?? null
            ]);
        }

        // Also save to key-value store as backup
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute(['sam_regdb', json_encode($data)]);

        $count = count($data);
        $timestamp = date('Y-m-d H:i:s');
        logQuery($action, $insertQuery, 'SUCCESS', "Inserted $count registrations into SQL table at $timestamp");
        echo json_encode(['ok' => true, 'count' => $count, 'registered_at' => $timestamp]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save registrations: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_fieldmap') {
    try {
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_fieldmap' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'SUCCESS', 'Field map retrieved');
        echo $val ?: 'null';
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to get field map: ' . $e->getMessage()]);
    }

} elseif ($action === 'save_fieldmap') {
    $data = $input['data'] ?? [];
    try {
        $query = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['sam_fieldmap', json_encode($data)]);
        logQuery($action, $query, 'SUCCESS', 'Field map saved');
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        logQuery($action, $query ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save field map: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_emails') {
    try {
        $auctionId = $input['auction_id'] ?? '';
        $query = "SELECT * FROM emails WHERE auction_id = ? ORDER BY received DESC LIMIT 1000";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$auctionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows) . " (auction=$auctionId)");
        echo json_encode($rows);
    } catch (Exception $e) {
        logQuery($action, 'SELECT * FROM emails', 'ERROR', $e->getMessage());
        // Fallback
        $query = "SELECT `value` FROM sam_store WHERE `key` = 'sam_emails' LIMIT 1";
        $val = $pdo->query($query)->fetchColumn();
        logQuery($action, $query, 'FALLBACK', 'Using key-value store');
        echo $val ?: '[]';
    }

} elseif ($action === 'save_emails') {
    $data = $input['data'] ?? [];
    $auctionId = $input['auction_id'] ?? '';
    try {
        // Replace only THIS auction's emails (not every auction's)
        $del = $pdo->prepare("DELETE FROM emails WHERE auction_id = ?");
        $del->execute([$auctionId]);

        // Insert new emails into SQL table, scoped to the current auction
        $insertQuery = "INSERT INTO emails (id, auction_id, from_email, subject, body, received) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($insertQuery);

        foreach ($data as $email) {
            $stmt->execute([
                $email['id'] ?? $email['message_id'] ?? null,
                $auctionId,
                $email['from'] ?? null,
                $email['subject'] ?? null,
                $email['body'] ?? null,
                $email['received'] ?? date('c')
            ]);
        }

        // Also save to key-value store as backup (namespaced per auction)
        $kvQuery = "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        $kvStmt = $pdo->prepare($kvQuery);
        $kvStmt->execute([($auctionId ? "sam_{$auctionId}_emails" : 'sam_emails'), json_encode($data)]);

        $count = count($data);
        logQuery($action, $insertQuery, 'SUCCESS', "Inserted $count emails into SQL table (auction=$auctionId)");
        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        logQuery($action, $insertQuery ?? 'N/A', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to save emails: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_table_data') {
    $table = $input['table'] ?? '';
    // Whitelist allowed tables
    $allowedTables = ['sam_store', 'auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'emails', 'members', 'registrations', 'fieldmap'];

    if (!in_array($table, $allowedTables, true)) {
        logQuery($action, 'N/A', 'ERROR', "Invalid table: $table");
        echo json_encode(['error' => 'Invalid table']);
        exit;
    }

    try {
        if ($table === 'sam_store') {
            // Special handling for key-value store
            $query = "SELECT `key`, LEFT(`value`, 200) as `value_preview`, LENGTH(`value`) as `value_length`, `updated_at` FROM sam_store ORDER BY `key`";
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            logQuery($action, $query, 'SUCCESS', "Rows: " . count($rows));
            echo json_encode([
                'table' => $table,
                'columns' => ['key', 'value_preview', 'value_length', 'updated_at'],
                'rows' => $rows,
                'count' => count($rows)
            ]);
        } else {
            // Regular table
            $query = "SELECT * FROM `$table` LIMIT 1000";
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            $columns = $rows ? array_keys($rows[0]) : [];
            logQuery($action, "SELECT * FROM `$table` LIMIT 1000", 'SUCCESS', "Rows: " . count($rows));
            echo json_encode([
                'table' => $table,
                'columns' => $columns,
                'rows' => $rows,
                'count' => count($rows)
            ]);
        }
    } catch (Exception $e) {
        logQuery($action, "SELECT * FROM `$table`", 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to fetch table: ' . $e->getMessage()]);
    }

} elseif ($action === 'log') {
    $message = $input['message'] ?? '';
    $level = $input['level'] ?? 'INFO';
    if (!$message) {
        echo json_encode(['error' => 'No message provided']);
        exit;
    }
    // Append to debug_log.txt with EDT timezone
    $logFile = __DIR__ . '/debug_log.txt';
    date_default_timezone_set('America/New_York');
    $timestamp = date('m/d/Y, g:i:s A');
    $logEntry = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true, 'message' => 'Logged']);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 2 Security: Authenticated Debug Log Access
// ═════════════════════════════════════════════════════════════════════════════

} elseif ($action === 'get_debug_log') {
    // Requires authentication - already verified above
    $logFile = __DIR__ . '/debug_log.txt';
    $limit = (int)($input['limit'] ?? 100); // Last N lines
    $limit = min($limit, 1000); // Cap at 1000 lines

    try {
        if (!file_exists($logFile)) {
            echo json_encode(['logs' => [], 'count' => 0, 'message' => 'No debug log file']);
            exit;
        }

        // Read file
        $content = file_get_contents($logFile);
        $lines = explode("\n", trim($content));

        // Get last N lines
        if (count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }

        logQuery($action, 'Read debug log', 'SUCCESS', 'Lines: ' . count($lines));
        echo json_encode([
            'logs' => $lines,
            'count' => count($lines),
            'file_size' => filesize($logFile),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        logQuery($action, 'Read debug log', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to read debug log: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_audit_log') {
    // Requires authentication - already verified above
    $limit = (int)($input['limit'] ?? 100);
    $limit = min($limit, 1000);
    $offset = (int)($input['offset'] ?? 0);
    $filter = $input['filter'] ?? null; // Filter by user_id, action, or table_affected

    try {
        // Ensure audit_log table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            audit_id INT AUTO_INCREMENT PRIMARY KEY,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_id VARCHAR(255),
            action VARCHAR(100),
            table_affected VARCHAR(100),
            record_id VARCHAR(255),
            old_value LONGTEXT,
            new_value LONGTEXT,
            ip_address VARCHAR(45),
            status VARCHAR(20),
            details LONGTEXT,
            INDEX idx_timestamp (timestamp),
            INDEX idx_user_action (user_id, action),
            INDEX idx_record (table_affected, record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $query = "SELECT * FROM audit_log";
        $params = [];

        if ($filter) {
            if (isset($filter['user_id'])) {
                $query .= " WHERE user_id = ?";
                $params[] = $filter['user_id'];
            } elseif (isset($filter['action'])) {
                $query .= " WHERE action = ?";
                $params[] = $filter['action'];
            } elseif (isset($filter['table_affected'])) {
                $query .= " WHERE table_affected = ?";
                $params[] = $filter['table_affected'];
            }
        }

        $query .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM audit_log";
        if ($filter) {
            // Add filter to count query
            if (isset($filter['user_id'])) {
                $countQuery .= " WHERE user_id = ?";
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute([$filter['user_id']]);
            } elseif (isset($filter['action'])) {
                $countQuery .= " WHERE action = ?";
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute([$filter['action']]);
            } elseif (isset($filter['table_affected'])) {
                $countQuery .= " WHERE table_affected = ?";
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute([$filter['table_affected']]);
            } else {
                $countStmt = $pdo->query($countQuery);
            }
        } else {
            $countStmt = $pdo->query($countQuery);
        }
        $total = $countStmt->fetch()['total'] ?? 0;

        logQuery($action, 'Read audit log', 'SUCCESS', 'Rows: ' . count($rows));
        echo json_encode([
            'logs' => $rows,
            'count' => count($rows),
            'total' => (int)$total,
            'offset' => $offset,
            'limit' => $limit,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        logQuery($action, 'Read audit log', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to read audit log: ' . $e->getMessage()]);
    }

} elseif ($action === 'create_backup') {
    // Requires authentication - already verified above. This is the manual
    // "Backup Now" button (Developer > Backups) — also appends a
    // sam_backup_history entry (reason:'manual'), same log
    // samBackupAutoCheck() writes to, so both trigger types show in one place.
    $userId = getAuthUserId();

    try {
        $backupDir = __DIR__ . '/backups';
        $result = createDatabaseBackup($pdo, $backupDir, $env['DB_NAME']);

        $entry = ['timestamp' => gmdate('c'), 'status' => $result['success'] ? 'success' : 'failed', 'reason' => 'manual'];
        if ($result['success']) {
            $entry['fileName'] = basename($result['file']);
            $entry['sizeBytes'] = $result['size'];
        } else {
            $entry['error'] = $result['error'];
        }
        samAppendBackupHistory($pdo, $entry);

        logAudit($pdo, $userId, 'create_backup', 'system', 'backup', null, $result, $result['success'] ? 'success' : 'failure', "Backup created: " . ($result['file'] ?? 'failed'));
        echo json_encode(array_merge($result, ['entry' => $entry]));
    } catch (Exception $e) {
        logAudit($pdo, $userId, 'create_backup_error', 'system', 'backup', null, null, 'failure', $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($action === 'list_backups') {
    // Requires authentication - already verified above
    try {
        $backupDir = __DIR__ . '/backups';
        $backups = listBackups($backupDir);

        logQuery($action, 'List backups', 'SUCCESS', 'Count: ' . count($backups));
        echo json_encode([
            'backups' => $backups,
            'count' => count($backups),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        logQuery($action, 'List backups', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to list backups: ' . $e->getMessage()]);
    }

} elseif ($action === 'get_backup_history') {
    // Developer > Backups > "View Logs" — the permanent log of every attempt
    // (manual or auto, success or failure), unlike list_backups above which
    // only reflects files currently on disk.
    try {
        $history = samReadBackupHistory($pdo);
        echo json_encode(['success' => true, 'history' => $history]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to load backup history: ' . $e->getMessage()]);
    }

} elseif ($action === 'delete_backup') {
    // Removes one backup file (identified by its history entry's timestamp,
    // same identity convention CarShow's backup.php 'delete' action uses)
    // and its history entry together. Refuses to delete the last file left
    // on disk — same "never leave zero backups" floor samBackupPurge()'s
    // own max(1, ...) already gives the automatic purge.
    $userId = getAuthUserId();
    $timestamp = (string)($input['timestamp'] ?? '');
    try {
        $history = samReadBackupHistory($pdo);
        $target = null;
        foreach ($history as $e) {
            if (is_array($e) && ($e['timestamp'] ?? null) === $timestamp) { $target = $e; break; }
        }
        if ($target === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Backup log entry not found.']);
        } else {
            $backupDir = __DIR__ . '/backups';
            $fileName = (string)($target['fileName'] ?? '');
            if ($fileName !== '' && preg_match('/^backup_[0-9\-_]+\.(zip|sql(\.gz)?)$/', $fileName)) {
                $path = $backupDir . '/' . $fileName;
                if (is_file($path)) {
                    if (samBackupFileCount($backupDir) <= 1) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => "Can't delete this — it's the only backup left on the server. Run a new backup first."]);
                        exit;
                    }
                    @unlink($path);
                }
            }
            $kept = array_values(array_filter($history, function($e) use ($timestamp) {
                return !(is_array($e) && ($e['timestamp'] ?? null) === $timestamp);
            }));
            $stmt = $pdo->prepare("INSERT INTO sam_store (`key`, `value`) VALUES ('sam_backup_history', ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->execute([json_encode($kept)]);
            logAudit($pdo, $userId, 'delete_backup', 'system', 'backup', null, ['timestamp' => $timestamp], 'success', "Deleted backup: $timestamp");
            echo json_encode(['success' => true, 'history' => $kept]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete backup: ' . $e->getMessage()]);
    }

} elseif ($action === 'download_backup') {
    // Streams one backup file's raw bytes. Kept as a normal JSON-POST action
    // (not a query-string GET) to match this file's Content-Type: application/
    // json enforcement on POST — the client fetches it and saves the response
    // as a Blob rather than navigating to a download link directly.
    $name = (string)($input['name'] ?? '');
    $backupDir = __DIR__ . '/backups';
    $path = $backupDir . '/' . $name;
    if (!preg_match('/^backup_[0-9\-_]+\.(zip|sql(\.gz)?)$/', $name) || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Backup file not found — it may have aged past the retention limit (newest ' . SAM_BACKUP_KEEP . ' kept).']);
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;

} elseif ($action === 'get_backup_auctions') {
    // Read-only: lists the distinct auctions found inside one backup file, so
    // the Restore UI can offer "restore just this one auction" as a real
    // choice (with real names/item counts) instead of a blind text field.
    $timestamp = (string)($input['timestamp'] ?? '');
    try {
        $history = samReadBackupHistory($pdo);
        $target = null;
        foreach ($history as $e) {
            if (is_array($e) && ($e['timestamp'] ?? null) === $timestamp) { $target = $e; break; }
        }
        if ($target === null || ($target['status'] ?? '') !== 'success') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Backup log entry not found or was not a successful backup.']);
        } else {
            $backupDir = __DIR__ . '/backups';
            $fileName = (string)($target['fileName'] ?? '');
            if ($fileName === '' || !preg_match('/^backup_[0-9\-_]+\.(zip|sql(\.gz)?)$/', $fileName) || !is_file($backupDir . '/' . $fileName)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Backup file not found on disk — it may have aged past the retention limit (newest ' . SAM_BACKUP_KEEP . ' kept).']);
            } else {
                $backup = sam_read_backup_file($backupDir, $fileName);
                echo json_encode(['success' => true, 'auctions' => sam_backup_auction_ids($backup)]);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($action === 'restore_backup') {
    // Restores the live database from a specific backup file, identified by
    // its history entry's timestamp (same identity convention delete_backup
    // uses). Either the WHOLE database (all auctions + global settings/audit_
    // log/sam_store), or — when auction_id is given — just that one auction's
    // items/bidders/winners/payments/auctions row, leaving every other
    // auction and the global tables completely untouched. Genuinely
    // destructive either way, so restoreDatabaseBackup() itself ALWAYS takes
    // a fresh whole-database safety backup (reason:'pre-restore') before
    // touching anything, meaning a bad restore is itself always recoverable
    // by restoring that pre-restore backup. Also requires the Developer
    // (Settings) password to be re-entered inline in the confirm modal and
    // verified server-side here — the same check verify_settings_password
    // does — as a second, explicit confirmation beyond just clicking a
    // button, given how destructive this action is.
    $userId = getAuthUserId();
    $timestamp = (string)($input['timestamp'] ?? '');
    $auctionId = (string)($input['auction_id'] ?? '');
    $confirmPassword = (string)($input['settings_password'] ?? '');
    try {
        $storedSettingsPassword = sam_get_settings_password($pdo);
        if ($confirmPassword === '' || !hash_equals($storedSettingsPassword, $confirmPassword)) {
            logSecurityEvent('SETTINGS_AUTH_FAILURE', $action, 'Invalid Developer password on restore_backup confirm', 'WARN');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Incorrect Developer password.']);
            exit;
        }
        $history = samReadBackupHistory($pdo);
        $target = null;
        foreach ($history as $e) {
            if (is_array($e) && ($e['timestamp'] ?? null) === $timestamp) { $target = $e; break; }
        }
        if ($target === null || ($target['status'] ?? '') !== 'success') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Backup log entry not found or was not a successful backup.']);
        } else {
            $backupDir = __DIR__ . '/backups';
            $fileName = (string)($target['fileName'] ?? '');
            $path = $backupDir . '/' . $fileName;
            if ($fileName === '' || !preg_match('/^backup_[0-9\-_]+\.(zip|sql(\.gz)?)$/', $fileName) || !is_file($path)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Backup file not found on disk — it may have aged past the retention limit (newest ' . SAM_BACKUP_KEEP . ' kept).']);
            } else {
                $result = restoreDatabaseBackup($pdo, $backupDir, $fileName, $env['DB_NAME'], $auctionId !== '' ? $auctionId : null);

                // The pre-restore safety snapshot gets its OWN history entry
                // (reason:'pre-restore'), same as a normal manual/auto backup
                // would — otherwise the file exists on disk but is invisible
                // and unrestorable from the UI, defeating the point of taking
                // it. Logged even if the restore itself then fails, since the
                // safety backup step runs (and succeeds) before that.
                if (!empty($result['preRestoreBackup'])) {
                    samAppendBackupHistory($pdo, [
                        'timestamp' => gmdate('c', filemtime($result['preRestoreBackup']) ?: time()),
                        'status' => 'success',
                        'reason' => 'pre-restore',
                        'fileName' => basename($result['preRestoreBackup']),
                        'sizeBytes' => filesize($result['preRestoreBackup']),
                    ]);
                }

                $entry = [
                    'timestamp' => gmdate('c'),
                    'status' => $result['success'] ? 'success' : 'failed',
                    'reason' => 'restore',
                    'restoredFrom' => $fileName,
                    'scope' => $auctionId !== '' ? $auctionId : 'all',
                ];
                if ($result['success']) {
                    $entry['restoredCounts'] = $result['counts'];
                    if (!empty($result['scopeName'])) $entry['scopeName'] = $result['scopeName'];
                } else {
                    $entry['error'] = $result['error'];
                }
                samAppendBackupHistory($pdo, $entry);
                logAudit($pdo, $userId, 'restore_backup', 'system', 'backup', null, $result, $result['success'] ? 'success' : 'failure', "Restored from: $fileName (scope: " . ($auctionId !== '' ? $auctionId : 'all auctions') . ")");
                echo json_encode(array_merge($result, ['entry' => $entry, 'history' => samReadBackupHistory($pdo)]));
            }
        }
    } catch (Exception $e) {
        logAudit($pdo, $userId, 'restore_backup_error', 'system', 'backup', null, null, 'failure', $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($action === 'send_email') {
    // Replaces the old client-side Gmail OAuth send (sendEmailsViaGmail() in
    // index.html, removed) — Announce Winners' "Send Winning/NonWinner
    // Emails" now calls this once per email via sam_send_mail() (the same
    // raw-SMTP sender already used for password-reset), using the .env
    // SMTP_* credentials. If $to is blank but $bcc is set (the bulk
    // non-winner send, BCC-only), defaults $to to SMTP_FROM so
    // sam_send_mail()'s "at least one To" requirement is still met without
    // exposing the club's own address to recipients as a visible To:.
    $to      = (string)($input['to'] ?? '');
    $subject = (string)($input['subject'] ?? '');
    $body    = (string)($input['body'] ?? '');
    $cc      = (string)($input['cc'] ?? '');
    $bcc     = (string)($input['bcc'] ?? '');
    if ($to === '' && $bcc !== '') $to = $env['SMTP_FROM'] ?? ($env['SMTP_USER'] ?? '');

    if ($to === '' || $subject === '' || $body === '') {
        echo json_encode(['success' => false, 'error' => 'to, subject, and body are required.']);
    } elseif (sam_send_mail($to, $subject, $body, $env, $cc, $bcc)) {
        logQuery($action, 'SEND_EMAIL', 'SUCCESS', "To: $to");
        echo json_encode(['success' => true]);
    } else {
        logQuery($action, 'SEND_EMAIL', 'ERROR', "To: $to");
        echo json_encode(['success' => false, 'error' => 'Send failed — check SMTP settings.']);
    }

} elseif ($action === 'get_backup_schedule') {
    try {
        echo json_encode(['success' => true, 'schedule' => samGetBackupSchedule($pdo)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to load schedule: ' . $e->getMessage()]);
    }

} elseif ($action === 'save_backup_schedule') {
    try {
        $schedule = [
            'enabled' => !empty($input['enabled']),
            'startDate' => (string)($input['startDate'] ?? ''),
            'endDate' => (string)($input['endDate'] ?? ''),
            // Server-owned bookkeeping — preserve whatever's already there so a
            // settings save can't wipe today's "already ran" marker and cause
            // an extra same-day auto-run on the next login.
            'lastAutoRunDate' => samGetBackupSchedule($pdo)['lastAutoRunDate'],
        ];
        samSaveBackupSchedule($pdo, $schedule);
        echo json_encode(['success' => true, 'schedule' => $schedule]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to save schedule: ' . $e->getMessage()]);
    }

} elseif ($action === 'validate_payment') {
    // Phase 3: Payment validation endpoint
    try {
        $payment = $input['payment'] ?? [];
        $itemNumber = $input['item_number'] ?? '';
        $winningBid = floatval($input['winning_bid'] ?? 0);
        $itemValue = floatval($input['item_value'] ?? 0);
        $reserveAmount = floatval($input['reserve_amount'] ?? 0);

        // Validate winning bid amount
        $bidValidation = validateWinningBid($winningBid, $itemValue, $reserveAmount);

        // Validate payment entry
        $paymentValidation = validatePaymentEntry($payment, $itemNumber, $winningBid);

        logQuery($action, 'validate_payment', 'SUCCESS', "Item: $itemNumber, Bid: $winningBid");
        echo json_encode([
            'bid_valid' => $bidValidation['valid'],
            'bid_message' => $bidValidation['message'],
            'payment_valid' => $paymentValidation['valid'],
            'payment_message' => $paymentValidation['message'],
            'valid' => $bidValidation['valid'] && $paymentValidation['valid']
        ]);
    } catch (Exception $e) {
        logQuery($action, 'validate_payment', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Payment validation failed: ' . $e->getMessage()]);
    }

} elseif ($action === 'validate_category') {
    // Phase 3: Item category validation endpoint
    try {
        $categoryCode = $input['category_code'] ?? '';

        $validation = validateItemCategory($categoryCode);

        logQuery($action, 'validate_category', 'SUCCESS', "Category: $categoryCode");
        echo json_encode($validation);
    } catch (Exception $e) {
        logQuery($action, 'validate_category', 'ERROR', $e->getMessage());
        echo json_encode(['valid' => false, 'message' => 'Category validation failed']);
    }

} elseif ($action === 'validate_bidder') {
    // Phase 3: Bidder registration validation endpoint
    try {
        $bidder = $input['bidder'] ?? [];

        $validation = validateBidderRegistration($bidder);

        logQuery($action, 'validate_bidder', 'SUCCESS', "Bidder validation");
        echo json_encode($validation);
    } catch (Exception $e) {
        logQuery($action, 'validate_bidder', 'ERROR', $e->getMessage());
        echo json_encode(['valid' => false, 'errors' => ['Bidder validation failed']]);
    }

} elseif ($action === 'check_email_scan_cooldown') {
    // Phase 3: Check if email scan is allowed
    try {
        $minCooldown = intval($input['min_cooldown'] ?? 300); // Default 5 minutes
        $check = checkEmailScanCooldown($minCooldown);

        logQuery($action, 'check_email_scan_cooldown', 'SUCCESS', "Allowed: " . ($check['allowed'] ? 'yes' : 'no'));
        echo json_encode($check);
    } catch (Exception $e) {
        logQuery($action, 'check_email_scan_cooldown', 'ERROR', $e->getMessage());
        echo json_encode(['allowed' => true, 'message' => 'Check failed, allowing scan']);
    }

} elseif ($action === 'check_duplicate_email') {
    // Phase 3: Check for duplicate/similar email submissions
    try {
        $description = $input['description'] ?? '';
        $donorName = $input['donor_name'] ?? '';
        $hoursWindow = intval($input['hours_window'] ?? 24);

        // Get existing emails from database or localStorage key
        $emailsJson = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_emails' LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        $existingEmails = $emailsJson ? json_decode($emailsJson['value'], true) : [];

        $duplicate = checkDuplicateEmail($description, $donorName, $existingEmails, $hoursWindow);

        logQuery($action, 'check_duplicate_email', 'SUCCESS', "Duplicate: " . ($duplicate['isDuplicate'] ? 'yes' : 'no'));
        echo json_encode($duplicate);
    } catch (Exception $e) {
        logQuery($action, 'check_duplicate_email', 'ERROR', $e->getMessage());
        echo json_encode(['isDuplicate' => false, 'message' => 'Check failed']);
    }

} elseif ($action === 'get_storage_report') {
    // Phase 3: Get localStorage usage report
    try {
        // Fetch all data from sam_store
        $rows = $pdo->query("SELECT `key`, `value` FROM sam_store")->fetchAll(PDO::FETCH_KEY_PAIR);

        $report = getStorageUsageReport($rows);

        logQuery($action, 'get_storage_report', 'SUCCESS', "Usage: " . $report['estimatedMB'] . "MB");
        echo json_encode($report);
    } catch (Exception $e) {
        logQuery($action, 'get_storage_report', 'ERROR', $e->getMessage());
        echo json_encode(['error' => 'Failed to generate storage report']);
    }

} else {
    echo json_encode(['error' => 'Unknown action']);
}
