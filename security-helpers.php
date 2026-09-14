<?php
/**
 * Phase 2 Security Helpers for Silent Auction Manager
 * Provides encryption, audit logging, rate limiting, and validation functions
 */

// ═════════════════════════════════════════════════════════════════════════════
// PASSWORD RESET EMAIL - Minimal SMTP client (no external library/Composer)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Parses a comma/semicolon-separated address list into an array of valid
 * email addresses, silently dropping anything that doesn't validate.
 */
function sam_parse_addr_list($raw) {
    if (!is_string($raw) || trim($raw) === '') return [];
    $out = [];
    foreach (preg_split('/[,;]+/', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) $out[] = $part;
    }
    return $out;
}

/**
 * Sends an email via authenticated SMTP using credentials from $env
 * (SMTP_HOST/PORT/USER/PASS/FROM in .env). Ported from the Car Show app's
 * carshow_send_mail() — PHP's raw mail() was tried there first and dropped:
 * it returned success while silently failing to deliver to Gmail from a
 * Hostinger account (no SPF/DKIM behind mail()'s sendmail path).
 * @param string       $to      Primary recipient(s) — comma/semicolon-separated allowed.
 * @param string       $subject
 * @param string       $body    Plain text, or full HTML when $html is true.
 * @param array        $env
 * @param string       $cc      Optional CC recipient(s), same list syntax as $to.
 *                              Added as extra RCPT TOs (actually delivered) and a
 *                              Cc: header (shows in the message).
 * @param string       $bcc     Optional BCC recipient(s), same list syntax. Added
 *                              as extra RCPT TOs only — deliberately NO header, since
 *                              a Bcc: header would defeat the point of a blind copy.
 * @param bool         $html    When true, sends as Content-Type: text/html.
 * @return bool True if the server accepted the message for delivery.
 */
function sam_send_mail($to, $subject, $body, $env, $cc = '', $bcc = '', $html = false) {
    if (empty($env['SMTP_HOST']) || empty($env['SMTP_USER']) || empty($env['SMTP_PASS'])) return false;

    $toList  = sam_parse_addr_list($to);
    $ccList  = sam_parse_addr_list($cc);
    $bccList = sam_parse_addr_list($bcc);
    if (!$toList) return false;

    $host = $env['SMTP_HOST'];
    $port = !empty($env['SMTP_PORT']) ? (int)$env['SMTP_PORT'] : 465;
    $user = $env['SMTP_USER'];
    $pass = $env['SMTP_PASS'];
    $from = !empty($env['SMTP_FROM']) ? $env['SMTP_FROM'] : $user;
    $target = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;

    $sock = @stream_socket_client($target, $errno, $errstr, 15);
    if (!$sock) return false;
    stream_set_timeout($sock, 15);

    // Reads a full (possibly multi-line) reply: SMTP marks the final line of
    // a multi-line response with a space in the 4th column (e.g. "250 OK"
    // vs "250-continues"); anything else means keep reading.
    $read = function () use ($sock) {
        $data = '';
        while (($line = fgets($sock, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function ($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
    $expect = function ($code) use ($read) { return strpos($read(), (string)$code) === 0; };
    $fail = function () use ($sock) { fclose($sock); return false; };

    $read(); // server greeting
    $write('EHLO etccapps.com');
    $read();

    if ($port !== 465) {
        $write('STARTTLS');
        if (!$expect(220)) return $fail();
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail();
        $write('EHLO etccapps.com');
        $read();
    }

    $write('AUTH LOGIN');
    if (!$expect(334)) return $fail();
    $write(base64_encode($user));
    if (!$expect(334)) return $fail();
    $write(base64_encode($pass));
    if (!$expect(235)) return $fail();

    $write('MAIL FROM:<' . $from . '>');
    if (!$expect(250)) return $fail();
    foreach (array_merge($toList, $ccList, $bccList) as $rcpt) {
        $write('RCPT TO:<' . $rcpt . '>');
        if (!$expect(250)) return $fail();
    }
    $write('DATA');
    if (!$expect(354)) return $fail();

    $headers = "From: {$from}\r\nTo: " . implode(', ', $toList) . "\r\n" .
        ($ccList ? "Cc: " . implode(', ', $ccList) . "\r\n" : '') .
        "Subject: {$subject}\r\n" .
        "MIME-Version: 1.0\r\nContent-Type: " . ($html ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
    // Dot-stuffing: a line starting with "." in the body must be escaped to
    // ".." or the SMTP server reads it as the end-of-DATA terminator.
    $safeBody = preg_replace('/^\./m', '..', $body);
    $write($headers . "\r\n" . $safeBody . "\r\n.");
    $ok = $expect(250);
    $write('QUIT');
    fclose($sock);
    return $ok;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENCRYPTION/DECRYPTION - AES-256-CBC
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Encrypt plaintext using AES-256-CBC
 * @param string $plaintext Data to encrypt
 * @param string $encryptionKey Encryption key from environment
 * @return string Base64-encoded ciphertext with IV prepended
 */
function encryptData($plaintext, $encryptionKey) {
    if (empty($plaintext)) {
        return null;
    }

    // Use SHA-256 to derive a consistent 32-byte key
    $key = hash('sha256', $encryptionKey, true);

    // Generate random IV
    $iv = openssl_random_pseudo_bytes(16);

    // Encrypt data
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    // Return IV + encrypted data, base64 encoded for storage
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt AES-256-CBC encrypted data
 * @param string $ciphertext Base64-encoded ciphertext with IV prepended
 * @param string $encryptionKey Encryption key from environment
 * @return string|null Plaintext, or null if decryption fails
 */
function decryptData($ciphertext, $encryptionKey) {
    if (empty($ciphertext)) {
        return null;
    }

    // Decode from base64
    $decoded = base64_decode($ciphertext, true);
    if ($decoded === false) {
        return null;
    }

    // Extract IV (first 16 bytes)
    $iv = substr($decoded, 0, 16);
    $encrypted = substr($decoded, 16);

    // Use SHA-256 to derive consistent key
    $key = hash('sha256', $encryptionKey, true);

    // Decrypt
    $plaintext = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return $plaintext;
}

/**
 * Check if a value appears to be encrypted (base64-encoded with length > 50)
 */
function isEncrypted($value) {
    if (empty($value) || !is_string($value)) {
        return false;
    }

    // Encrypted values are base64, typically > 50 chars
    if (strlen($value) < 50) {
        return false;
    }

    // Check if it's valid base64
    if (base64_encode(base64_decode($value, true)) !== $value) {
        return false;
    }

    return true;
}

// ═════════════════════════════════════════════════════════════════════════════
// AUDIT LOGGING
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Log an audit event to database and file
 * @param PDO $pdo Database connection
 * @param string $userId User ID (from session)
 * @param string $action Action being logged (e.g., 'save_winners', 'delete_item')
 * @param string $tableAffected Table name affected
 * @param string|int $recordId Record ID affected
 * @param mixed $oldValue Old value (null for inserts)
 * @param mixed $newValue New value (null for deletes)
 * @param string $status 'success' or 'failure'
 * @param string $details Additional details
 */
function logAudit($pdo, $userId, $action, $tableAffected, $recordId, $oldValue, $newValue, $status = 'success', $details = '') {
    try {
        // Create audit_log table if it doesn't exist
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

        // Get client IP
        $ip = getClientIp();

        // Prepare audit entry
        $query = "INSERT INTO audit_log
                  (user_id, action, table_affected, record_id, old_value, new_value, ip_address, status, details)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $userId ?? 'system',
            $action,
            $tableAffected,
            (string)$recordId,
            is_array($oldValue) || is_object($oldValue) ? json_encode($oldValue) : (string)$oldValue,
            is_array($newValue) || is_object($newValue) ? json_encode($newValue) : (string)$newValue,
            $ip,
            $status,
            $details
        ]);
    } catch (Exception $e) {
        // Log to file if database audit fails
        error_log("[AUDIT_FAIL] $action on $tableAffected:$recordId - " . $e->getMessage());
    }
}

/**
 * Get authenticated user ID from session
 */
function getAuthUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['authenticated_user'] ?? 'anonymous';
}

/**
 * Get client IP address, accounting for proxies
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Cloudflare
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Load balancer/proxy
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
        return $_SERVER['HTTP_X_FORWARDED'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
        return $_SERVER['HTTP_FORWARDED'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// RATE LIMITING
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Check if request should be rate limited
 * Uses sliding window algorithm with per-endpoint limits
 * @param string $endpoint API endpoint/action name
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
 */
function checkRateLimit($endpoint, $maxRequests, $windowSeconds) {
    // Initialize rate limit tracking in session
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $key = $endpoint;
    $now = time();
    $windowStart = $now - $windowSeconds;

    // Initialize or clean old timestamps
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [];
    }

    // Remove timestamps outside the window
    $_SESSION['rate_limits'][$key] = array_filter(
        $_SESSION['rate_limits'][$key],
        function($ts) use ($windowStart) { return $ts >= $windowStart; }
    );

    $requestCount = count($_SESSION['rate_limits'][$key]);

    if ($requestCount >= $maxRequests) {
        // Find oldest request to calculate retry_after
        $oldestRequest = min($_SESSION['rate_limits'][$key]);
        $retryAfter = ceil(($oldestRequest + $windowSeconds - $now));

        return [
            'allowed' => false,
            'remaining' => 0,
            'retry_after' => max(1, $retryAfter),
            'error' => 'Rate limit exceeded'
        ];
    }

    // Record this request
    $_SESSION['rate_limits'][$key][] = $now;

    return [
        'allowed' => true,
        'remaining' => $maxRequests - $requestCount - 1,
        'retry_after' => 0
    ];
}

/**
 * Get rate limit configuration for endpoint
 * @param string $action API action
 * @return array ['maxRequests' => int, 'windowSeconds' => int]
 */
function getRateLimitConfig($action) {
    $limits = [
        'login' => ['maxRequests' => 8, 'windowSeconds' => 300],         // 8 per 5 minutes — brute-force guard
        'forgot_password' => ['maxRequests' => 3, 'windowSeconds' => 3600], // 3 per hour — don't spam the admin inbox
        'reset_password' => ['maxRequests' => 8, 'windowSeconds' => 300],  // 8 per 5 minutes — token brute-force guard
        'verify_settings_password' => ['maxRequests' => 8, 'windowSeconds' => 300], // 8 per 5 minutes — Developer gate brute-force guard
        'scan_inbox' => ['maxRequests' => 1, 'windowSeconds' => 300],     // 1 per 5 minutes
        'set_password' => ['maxRequests' => 5, 'windowSeconds' => 900],   // 5 per 15 minutes
        // Raised from 100 to 400 per minute — per-field inline auto-save (e.g. bid
        // sheet row height, inline item edits) resends the full array on every
        // field commit, so heavy interactive editing was hitting 100/min and
        // silently failing saves (looked like the UI was "stuck").
        'save_items' => ['maxRequests' => 400, 'windowSeconds' => 60],
        'save_bidders' => ['maxRequests' => 400, 'windowSeconds' => 60],
        'save_winners' => ['maxRequests' => 400, 'windowSeconds' => 60],
        'save_payments' => ['maxRequests' => 400, 'windowSeconds' => 60],
        'get_all_data' => ['maxRequests' => 10, 'windowSeconds' => 60],   // 10 per minute
        'clear_all' => ['maxRequests' => 1, 'windowSeconds' => 3600],     // 1 per hour
    ];

    return $limits[$action] ?? ['maxRequests' => 100, 'windowSeconds' => 60]; // Default: 100 per minute
}

// ═════════════════════════════════════════════════════════════════════════════
// SESSION MANAGEMENT
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Validate server-side session timeout
 * Checks if session has been idle for too long
 * @param int $timeoutSeconds Default: 1800 (30 minutes)
 * @return array ['valid' => bool, 'message' => string, 'remaining' => int]
 */
function validateSessionTimeout($timeoutSeconds = 1800) {
    $now = time();

    // Initialize last_activity on first request
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $now;
        return ['valid' => true, 'message' => 'Session started', 'remaining' => $timeoutSeconds];
    }

    $elapsed = $now - $_SESSION['last_activity'];

    if ($elapsed > $timeoutSeconds) {
        // Session has timed out
        session_destroy();
        return [
            'valid' => false,
            'message' => 'Session expired due to inactivity',
            'remaining' => 0
        ];
    }

    // Update last_activity for sliding window
    $_SESSION['last_activity'] = $now;
    $remaining = $timeoutSeconds - $elapsed;

    return [
        'valid' => true,
        'message' => 'Session valid',
        'remaining' => $remaining
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// DATA VALIDATION
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Validate payment method
 * @param string $method Payment method string
 * @return bool True if method is in whitelist
 */
function isValidPaymentMethod($method) {
    $whitelist = ['Cash', 'Check', 'Credit Card', 'Other'];
    return in_array($method, $whitelist, true);
}

/**
 * Validate payment amount
 * @param mixed $amount Amount to validate
 * @return array ['valid' => bool, 'error' => string]
 */
function validatePaymentAmount($amount) {
    // Convert to float
    $amt = floatval($amount);

    // Must be positive
    if ($amt <= 0) {
        return ['valid' => false, 'error' => 'Amount must be positive'];
    }

    // Check decimal places (max 2)
    if (round($amt, 2) != $amt) {
        return ['valid' => false, 'error' => 'Amount must have at most 2 decimal places'];
    }

    // Reasonable upper limit (adjust as needed)
    if ($amt > 10000) {
        return ['valid' => false, 'error' => 'Amount exceeds maximum limit ($10,000)'];
    }

    return ['valid' => true];
}

/**
 * Validate winning bid amount
 * @param mixed $bid Bid amount
 * @param mixed $itemValue Item value for reference
 * @return array ['valid' => bool, 'error' => string]
 */
function validateWinningBid($bid, $itemValue = null) {
    $bidFloat = floatval($bid);

    // Must be positive
    if ($bidFloat < 0) {
        return ['valid' => false, 'error' => 'Bid amount cannot be negative'];
    }

    // Check decimal places
    if (round($bidFloat, 2) != $bidFloat) {
        return ['valid' => false, 'error' => 'Bid must have at most 2 decimal places'];
    }

    // If itemValue provided, bid should be reasonable
    if ($itemValue !== null) {
        $value = floatval(str_replace('$', '', (string)$itemValue));
        if ($value > 0 && $bidFloat > $value * 5) {
            // Allow up to 5x item value, but log warning
            error_log("[WARNING] Bid $bidFloat is 5x+ item value $value");
        }
    }

    return ['valid' => true];
}

/**
 * Sanitize email input
 * @param string $email Email to sanitize
 * @return string|null Valid email or null
 */
function sanitizeEmail($email) {
    $email = trim((string)$email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    return null;
}

/**
 * Sanitize phone number (basic)
 * @param string $phone Phone number to sanitize
 * @return string Sanitized phone (numbers and common separators only)
 */
function sanitizePhone($phone) {
    // Keep only digits, dashes, parentheses, spaces, plus sign
    $sanitized = preg_replace('/[^0-9\-\(\)\s\+]/', '', (string)$phone);
    return trim($sanitized);
}

// ═════════════════════════════════════════════════════════════════════════════
// DEBUG LOG MANAGEMENT
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Log query/event to debug log with rotation
 * @param string $logFile Path to log file
 * @param string $action API action
 * @param string $status Status (SUCCESS, ERROR, etc)
 * @param string $message Log message
 * @param int $maxSize Max log size in bytes (default 10MB)
 */
function logToFile($logFile, $action, $status, $message, $maxSize = 10485760) {
    // Use EDT timezone
    date_default_timezone_set('America/New_York');
    $timestamp = date('m/d/Y, g:i:s A');
    $logEntry = "[$timestamp] [API:$action] [$status] $message\n";

    // Check file size and rotate if needed
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        // Rename current log to archive
        $archived = $logFile . '.' . date('Y-m-d-H-i-s') . '.txt';
        rename($logFile, $archived);
    }

    // Write entry
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Clean up old debug logs
 * @param string $logDir Directory containing logs
 * @param int $retentionDays Keep logs for this many days
 */
function cleanupOldLogs($logDir, $retentionDays = 30) {
    if (!is_dir($logDir)) {
        return;
    }

    $files = glob($logDir . '/debug_log.*.txt');
    $cutoffTime = time() - ($retentionDays * 86400);

    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            unlink($file);
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// SOFT DELETE UTILITIES
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Soft delete a record (mark deleted but don't remove)
 * @param PDO $pdo Database connection
 * @param string $table Table name
 * @param string $idField ID field name
 * @param mixed $idValue ID value
 * @return bool Success
 */
function softDeleteRecord($pdo, $table, $idField, $idValue) {
    try {
        // Ensure table has deleted_at column
        $query = "ALTER TABLE `$table` ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL";
        try {
            $pdo->exec($query);
        } catch (Exception $e) {
            // Column probably already exists
        }

        // Mark as deleted
        $query = "UPDATE `$table` SET deleted_at = NOW() WHERE `$idField` = ?";
        $stmt = $pdo->prepare($query);
        return $stmt->execute([$idValue]);
    } catch (Exception $e) {
        error_log("[SOFT_DELETE_ERROR] " . $e->getMessage());
        return false;
    }
}

/**
 * Restore a soft-deleted record
 * @param PDO $pdo Database connection
 * @param string $table Table name
 * @param string $idField ID field name
 * @param mixed $idValue ID value
 * @return bool Success
 */
function restoreSoftDeletedRecord($pdo, $table, $idField, $idValue) {
    try {
        $query = "UPDATE `$table` SET deleted_at = NULL WHERE `$idField` = ?";
        $stmt = $pdo->prepare($query);
        return $stmt->execute([$idValue]);
    } catch (Exception $e) {
        error_log("[RESTORE_ERROR] " . $e->getMessage());
        return false;
    }
}

/**
 * Permanently delete records older than retention period
 * @param PDO $pdo Database connection
 * @param string $table Table name
 * @param int $retentionDays Days to keep soft-deleted records
 * @return int Number of rows deleted
 */
function purgeExpiredSoftDeletes($pdo, $table, $retentionDays = 30) {
    try {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $query = "DELETE FROM `$table` WHERE deleted_at IS NOT NULL AND deleted_at < ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$cutoffDate]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("[PURGE_ERROR] " . $e->getMessage());
        return 0;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// BACKUP & RECOVERY
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Create a database backup
 * @param PDO $pdo Database connection
 * @param string $backupDir Directory to store backups
 * @param string $dbName Database name
 * @return array ['success' => bool, 'file' => string, 'error' => string]
 */
function createDatabaseBackup($pdo, $backupDir, $dbName) {
    try {
        // Ensure backup directory exists and is writable
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0700, true);
        }

        $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
        $zipFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.zip';
        $gzFile = $backupFile . '.gz'; // fallback only, if ZipArchive isn't available

        // SECURITY: Avoid shell_exec() (command injection risk). Use PHP-based backup instead.
        // This is safer and more portable across hosting environments.
        // SAM_BACKUP_TABLES (defined below, after this function) is the single
        // source of truth shared with restoreDatabaseBackup() — PHP's define()
        // runs at file-include time, so it's already set by the time either
        // function actually gets called from an action handler.
        $tables = SAM_BACKUP_TABLES;
        $backup = [];
        $backupMeta = [
            'backup_time' => date('Y-m-d H:i:s'),
            'database' => $dbName,
            'version' => '1.0'
        ];

        foreach ($tables as $table) {
            try {
                // Use prepared statement for safety (though table name is hardcoded)
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                $backup[$table] = $rows;
            } catch (Exception $e) {
                // Table might not exist, skip
                error_log("[BACKUP] Table $table not found: " . $e->getMessage());
            }
        }

        // Create SQL dump format for readability and restore capability
        $sqlDump = "-- Database Backup\n";
        $sqlDump .= "-- Generated: " . $backupMeta['backup_time'] . "\n";
        $sqlDump .= "-- Database: " . $backupMeta['database'] . "\n\n";
        $sqlDump .= json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write backup file with restrictive permissions
        if (!file_put_contents($backupFile, $sqlDump, LOCK_EX)) {
            throw new Exception("Failed to write backup file: $backupFile");
        }
        chmod($backupFile, 0600); // Owner read/write only

        // Package as a real .zip (a user-facing requirement — the previous
        // gzcompress()'d ".gz" file wasn't a zip archive at all, just raw
        // zlib-compressed bytes with a misleading extension; some tools/OSes
        // couldn't open it as either a .gz or a .zip). ZipArchive ships with
        // PHP's zip extension, which is present on this host; no shell_exec
        // involved, same "no shell commands" constraint as before.
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Failed to create zip archive: $zipFile");
            }
            $zip->addFile($backupFile, basename($backupFile));
            $zip->close();
            chmod($zipFile, 0600); // Owner read/write only

            // Clean up uncompressed file now that it's inside the zip
            @unlink($backupFile);

            if (file_exists($zipFile)) {
                samBackupPurge($backupDir, SAM_BACKUP_KEEP);
                return [
                    'success' => true,
                    'file' => $zipFile,
                    'size' => filesize($zipFile),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
            return ['success' => false, 'error' => 'Zip archive not created'];
        }

        // Fallback for a host without the zip extension — old .sql.gz behavior,
        // kept only so a backup still succeeds rather than hard-failing.
        $compressed = gzcompress($sqlDump, 9);
        if ($compressed === false) {
            throw new Exception("Failed to compress backup");
        }

        if (!file_put_contents($gzFile, $compressed, LOCK_EX)) {
            throw new Exception("Failed to write compressed backup: $gzFile");
        }
        chmod($gzFile, 0600); // Owner read/write only

        // Clean up uncompressed file
        @unlink($backupFile);

        if (file_exists($gzFile)) {
            samBackupPurge($backupDir, SAM_BACKUP_KEEP);
            return [
                'success' => true,
                'file' => $gzFile,
                'size' => filesize($gzFile),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } elseif (file_exists($backupFile)) {
            samBackupPurge($backupDir, SAM_BACKUP_KEEP);
            return [
                'success' => true,
                'file' => $backupFile,
                'size' => filesize($backupFile),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }

        return ['success' => false, 'error' => 'Backup file not created'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Tables createDatabaseBackup() dumps/restores. Kept as one shared constant so
// the two functions can never drift out of sync with each other.
// 'auctions' was added this session — a real gap in the original list: without
// it, a restore could put items/bidders/etc. back with auction_id values that
// no longer resolve to any auction name/status, since the auctions table
// itself (id/name/status) was never being backed up at all.
define('SAM_BACKUP_TABLES', ['auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'audit_log', 'sam_store']);

// Which column identifies "which auction" a row belongs to, for the tables a
// per-auction (scoped) restore is allowed to touch. 'auctions' itself is
// keyed by its own `id`, not an `auction_id` column. Tables NOT listed here
// (settings, audit_log, sam_store) are global/shared across every auction and
// are never touched by a scoped restore — only a whole-database restore
// (auctionId === null) restores those.
define('SAM_AUCTION_SCOPED_TABLES', [
    'auctions' => 'id',
    'items'    => 'auction_id',
    'bidders'  => 'auction_id',
    'winners'  => 'auction_id',
    'payments' => 'auction_id',
]);

// Reads one backup file (.zip, or legacy .sql/.sql.gz) back into the same
// $backup['table'] => [row, row, ...] shape createDatabaseBackup() wrote.
function sam_read_backup_file($backupDir, $fileName) {
    $path = $backupDir . '/' . $fileName;
    if (!is_file($path)) throw new Exception("Backup file not found: $fileName");

    if (preg_match('/\.zip$/i', $fileName)) {
        if (!class_exists('ZipArchive')) throw new Exception('This backup is a .zip file but the server\'s zip extension is unavailable.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new Exception("Could not open zip archive: $fileName");
        // The zip always holds exactly one entry — the .sql dump added by
        // createDatabaseBackup() — but find it by extension rather than
        // assuming index 0, in case a backup is ever hand-edited.
        $sqlEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (preg_match('/\.sql$/i', $entryName)) { $sqlEntry = $entryName; break; }
        }
        if ($sqlEntry === null) { $zip->close(); throw new Exception("No .sql file found inside $fileName"); }
        $content = $zip->getFromName($sqlEntry);
        $zip->close();
        if ($content === false) throw new Exception("Could not read $sqlEntry from $fileName");
    } elseif (preg_match('/\.sql\.gz$/i', $fileName)) {
        $raw = file_get_contents($path);
        if ($raw === false) throw new Exception("Could not read $fileName");
        $content = @gzuncompress($raw);
        if ($content === false) throw new Exception("Could not decompress $fileName");
    } else {
        $content = file_get_contents($path);
        if ($content === false) throw new Exception("Could not read $fileName");
    }

    // Format is a few "-- comment" header lines, a blank line, then the JSON
    // dump (see createDatabaseBackup()) — locate the JSON by its opening brace
    // rather than counting header lines, so this stays robust to header edits.
    $jsonStart = strpos($content, '{');
    if ($jsonStart === false) throw new Exception("$fileName does not look like a valid backup (no JSON payload found)");
    $backup = json_decode(substr($content, $jsonStart), true);
    if (!is_array($backup)) throw new Exception("$fileName's backup data could not be parsed");
    return $backup;
}

// Lists the distinct auctions found inside one backup file, for the "restore
// just one auction" UI to offer real choices instead of a blank text field.
// Prefers the backup's own 'auctions' table (id/name/status, added this
// session) for real names; falls back to bare auction_id strings (pulled from
// whichever of items/bidders/winners/payments has rows) for an OLDER backup
// made before 'auctions' was included in SAM_BACKUP_TABLES.
function sam_backup_auction_ids($backup) {
    $known = [];
    if (!empty($backup['auctions']) && is_array($backup['auctions'])) {
        foreach ($backup['auctions'] as $a) {
            if (is_array($a) && isset($a['id'])) {
                $known[(string)$a['id']] = ['id' => (string)$a['id'], 'name' => $a['name'] ?? (string)$a['id'], 'status' => $a['status'] ?? ''];
            }
        }
    }
    // Item counts (and any auction_id present in items/etc. but missing from
    // an older backup's auctions table, if it predates that table existing).
    $itemCounts = [];
    foreach (['items', 'bidders', 'winners', 'payments'] as $table) {
        if (empty($backup[$table]) || !is_array($backup[$table])) continue;
        foreach ($backup[$table] as $row) {
            $id = isset($row['auction_id']) ? (string)$row['auction_id'] : '';
            if ($id === '') continue;
            if ($table === 'items') $itemCounts[$id] = ($itemCounts[$id] ?? 0) + 1;
            if (!isset($known[$id])) $known[$id] = ['id' => $id, 'name' => $id, 'status' => ''];
        }
    }
    $result = [];
    foreach ($known as $id => $a) {
        $a['itemCount'] = $itemCounts[$id] ?? 0;
        $result[] = $a;
    }
    usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $result;
}

// Restores the live database from one backup file — either the WHOLE
// database (all auctions, plus the global settings/audit_log/sam_store rows),
// or just ONE auction's data when $auctionId is given (only the tables in
// SAM_AUCTION_SCOPED_TABLES are touched, filtered to that auction — every
// other auction's rows, and the global tables, are left completely alone).
// Destructive either way, so this ALWAYS takes a fresh WHOLE-database safety
// backup first regardless of scope (reason handled by the caller's history
// entry), making any restore itself reversible by restoring that pre-restore
// snapshot. Runs inside a transaction: a failure partway through rolls back
// everything restored so far rather than leaving the database half-old/half-new.
function restoreDatabaseBackup($pdo, $backupDir, $fileName, $dbName, $auctionId = null) {
    try {
        $backup = sam_read_backup_file($backupDir, $fileName);
        $auctionId = ($auctionId !== null && $auctionId !== '') ? (string)$auctionId : null;

        // Safety net — snapshot current state before overwriting anything,
        // even for a scoped restore (cheap, and keeps the recovery story
        // identical regardless of scope: restore that file to undo).
        $preRestore = createDatabaseBackup($pdo, $backupDir, $dbName);
        if (empty($preRestore['success'])) {
            throw new Exception('Could not take a safety backup before restoring — aborted without changing anything. (' . ($preRestore['error'] ?? 'unknown error') . ')');
        }

        $tables = $auctionId !== null ? array_keys(SAM_AUCTION_SCOPED_TABLES) : SAM_BACKUP_TABLES;

        $pdo->beginTransaction();
        // Tables outside SAM_BACKUP_TABLES (e.g. 'emails') aren't backed up or
        // touched here, but some of them (emails.auction_id) hold a foreign
        // key INTO 'auctions' — deleting an auctions row to reinsert it (same
        // id, fresh data) trips that FK constraint even though the same id
        // comes right back a moment later. FOREIGN_KEY_CHECKS is a per-
        // connection setting, NOT part of the transaction (a ROLLBACK won't
        // undo it), so it's explicitly restored to 1 in both the success and
        // failure paths below rather than relying on the transaction boundary.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $counts = [];
        try {
            foreach ($tables as $table) {
                if (!array_key_exists($table, $backup) || !is_array($backup[$table])) continue;
                $rows = $backup[$table];

                if ($auctionId !== null) {
                    // Scoped: only this auction's rows, identified by whatever
                    // column means "which auction" for this table.
                    $idCol = SAM_AUCTION_SCOPED_TABLES[$table];
                    $rows = array_values(array_filter($rows, fn($r) => isset($r[$idCol]) && (string)$r[$idCol] === $auctionId));
                    $pdo->prepare("DELETE FROM `$table` WHERE `$idCol` = ?")->execute([$auctionId]);
                } else {
                    $pdo->exec("DELETE FROM `$table`");
                }

                $inserted = 0;
                if (!empty($rows)) {
                    // Columns come from each row's own keys (captured verbatim
                    // by createDatabaseBackup()'s `SELECT *`), not a hardcoded
                    // schema — stays correct even if a table gains/loses a
                    // column between the backup being made and being restored.
                    $cols = array_keys($rows[0]);
                    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
                    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                    $stmt = $pdo->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
                    foreach ($rows as $row) {
                        $stmt->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
                        $inserted++;
                    }
                }
                $counts[$table] = $inserted;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->commit();
        } catch (Exception $e) {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Exception $ignore) {}
            $pdo->rollBack();
            throw $e;
        }

        // Resolve a human-readable name for the log, same source
        // sam_backup_auction_ids() prefers — the backup's own 'auctions' rows
        // (id/name) — falling back to the raw id for an older backup that
        // predates auction-name tracking.
        $scopeName = 'All Auctions';
        if ($auctionId !== null) {
            $scopeName = $auctionId;
            if (!empty($backup['auctions']) && is_array($backup['auctions'])) {
                foreach ($backup['auctions'] as $a) {
                    if (is_array($a) && isset($a['id']) && (string)$a['id'] === $auctionId && !empty($a['name'])) {
                        $scopeName = $a['name'];
                        break;
                    }
                }
            }
        }

        return [
            'success' => true,
            'counts' => $counts,
            'scope' => $auctionId !== null ? $auctionId : 'all',
            'scopeName' => $scopeName,
            'preRestoreBackup' => $preRestore['file'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * List available backups
 * @param string $backupDir Backup directory
 * @return array List of backup files with metadata
 */
function listBackups($backupDir) {
    $backups = [];

    if (!is_dir($backupDir)) {
        return $backups;
    }

    $files = samAllBackupFiles($backupDir);

    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'path' => $file,
            'size' => filesize($file),
            'created' => filemtime($file),
            'created_date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }

    // Sort by date descending
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });

    return $backups;
}

// ═════════════════════════════════════════════════════════════════════════════
// BACKUPS — Developer > Backups: "Backup Now" / auto-backup schedule / history log
//
// createDatabaseBackup()/listBackups() above already did the actual file work
// (a gzip'd JSON dump of every table, listed by globbing backups/) — this
// section adds three things on top, matching the CarShow app's own Backups
// feature (App/deploy/backup.php + lib.php) as closely as SAM's architecture
// allows:
//   1. Retention (samBackupPurge) — createDatabaseBackup() didn't purge old
//      files before; every "Backup Now" (or auto run) now keeps only the
//      newest SAM_BACKUP_KEEP files on disk.
//   2. A permanent history log (samAppendBackupHistory/samReadBackupHistory)
//      of every attempt, success or failure — unlike listBackups(), which
//      only reflects files currently on disk and says nothing about a failed
//      run or one since purged. Stored as a sam_store row (sam_backup_history),
//      not a separate JSON file on disk like CarShow's backup-history.json —
//      SAM already keeps all its config/state in sam_store, so this follows
//      that convention instead of introducing a new on-disk file format.
//   3. An auto-backup schedule (samGetBackupSchedule/samSaveBackupSchedule)
//      and the daily check that acts on it (samBackupAutoCheck), also stored
//      as its own sam_store row (sam_backup_schedule). CarShow's equivalent
//      check is piggybacked on a Windows Scheduled Task that polls the app
//      every ~15 minutes for an unrelated reason (the Import Schedule) — SAM
//      has no such infrastructure, so samBackupAutoCheck() is instead called
//      from api.php's 'login' action, which fires every time someone opens
//      and authenticates into the app. Less precisely "at midnight" than
//      CarShow's version (only as often as someone actually logs in), but
//      needs no new scheduled task or server cron to exist.
// ═════════════════════════════════════════════════════════════════════════════

// Keep at most this many backup files on disk — mirrors CarShow's
// CARSHOW_BACKUP_KEEP (also 30). The history log (below) is kept forever
// regardless, so a purged file's record isn't lost, only the file itself.
define('SAM_BACKUP_KEEP', 30);

// Every backup file currently on disk, any format. .zip is the current
// format (see createDatabaseBackup()); .sql/.sql.gz are matched too so
// backups created before that format change still show up, are counted
// toward retention, and remain downloadable/deletable.
function samAllBackupFiles($backupDir) {
    return array_merge(
        glob($backupDir . '/backup_*.zip') ?: [],
        glob($backupDir . '/backup_*.sql*') ?: []
    );
}

// Deletes the oldest backup files (any format) beyond $keep, oldest first.
// max(1, ...) is a floor: the newest backup is never deleted by this
// function, so a misconfigured $keep of 0 can't leave zero backups on disk.
function samBackupPurge($backupDir, $keep) {
    $keep = max(1, (int)$keep);
    $files = samAllBackupFiles($backupDir);
    if (count($files) <= $keep) return;
    usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
    foreach (array_slice($files, 0, count($files) - $keep) as $f) @unlink($f);
}

// How many backup files currently exist on disk — used by the 'delete_backup'
// action in api.php to refuse deleting the very last one, same guarantee
// samBackupPurge()'s max(1, ...) floor gives the automatic purge.
function samBackupFileCount($backupDir) {
    return count(samAllBackupFiles($backupDir));
}

// Reads the permanent history log (every attempt, success or failure) from
// sam_store. Newest-last (chronological append order) — callers that want
// newest-first reverse it themselves, matching how listBackups() above sorts
// on read rather than on write.
function samReadBackupHistory($pdo) {
    try {
        $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_backup_history' LIMIT 1")->fetchColumn();
        $decoded = $val ? json_decode($val, true) : [];
        return is_array($decoded) ? $decoded : [];
    } catch (Exception $e) {
        return [];
    }
}

// Appends one entry to the history log. Read-modify-write, same as
// listBackups()'s glob-and-sort — SAM's sam_store writes aren't
// lock-guarded the way CarShow's carshow_append_json_list() is (flock on a
// real file), but backups are rare, deliberate, low-concurrency events (one
// manual click, or one login-triggered auto-check per day), so the narrow
// race window here is an accepted tradeoff rather than an oversight.
function samAppendBackupHistory($pdo, $entry) {
    $history = samReadBackupHistory($pdo);
    $history[] = $entry;
    $stmt = $pdo->prepare("INSERT INTO sam_store (`key`, `value`) VALUES ('sam_backup_history', ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([json_encode($history)]);
}

// Reads the auto-backup schedule, defaulting every field so callers never
// have to null-check. lastAutoRunDate is server-owned bookkeeping (see
// samBackupAutoCheck()) — a client save must always preserve it, never set
// it directly, same rule CarShow's save_schedule action enforces.
function samGetBackupSchedule($pdo) {
    try {
        $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_backup_schedule' LIMIT 1")->fetchColumn();
        $s = $val ? json_decode($val, true) : [];
        if (!is_array($s)) $s = [];
    } catch (Exception $e) {
        $s = [];
    }
    return [
        'enabled' => !empty($s['enabled']),
        'startDate' => (string)($s['startDate'] ?? ''),
        'endDate' => (string)($s['endDate'] ?? ''),
        'lastAutoRunDate' => (string)($s['lastAutoRunDate'] ?? ''),
    ];
}

function samSaveBackupSchedule($pdo, $schedule) {
    $stmt = $pdo->prepare("INSERT INTO sam_store (`key`, `value`) VALUES ('sam_backup_schedule', ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([json_encode($schedule)]);
}

// Called from api.php's 'login' action on every successful login — SAM's
// stand-in for CarShow's dedicated ~15-minute scheduled-task poll (see this
// section's header comment for why). Runs at most once per calendar date
// (server's default timezone, set once near the top of api.php): the first
// login on/after midnight that finds today's date not yet recorded.
// Attempts exactly once per day regardless of outcome — lastAutoRunDate is
// set whether the run succeeded or failed, so a persistently failing backup
// surfaces once a day in the log rather than retrying on every single login.
function samBackupAutoCheck($pdo, $backupDir, $dbName) {
    $schedule = samGetBackupSchedule($pdo);
    if (!$schedule['enabled']) return;
    $today = date('Y-m-d');
    if ($schedule['startDate'] !== '' && $today < $schedule['startDate']) return;
    if ($schedule['endDate']   !== '' && $today > $schedule['endDate'])   return;
    if ($schedule['lastAutoRunDate'] === $today) return;

    $result = createDatabaseBackup($pdo, $backupDir, $dbName);
    $entry = ['timestamp' => gmdate('c'), 'status' => $result['success'] ? 'success' : 'failed', 'reason' => 'auto'];
    if ($result['success']) {
        $entry['fileName'] = basename($result['file']);
        $entry['sizeBytes'] = $result['size'];
    } else {
        $entry['error'] = $result['error'];
    }
    samAppendBackupHistory($pdo, $entry);

    $schedule['lastAutoRunDate'] = $today;
    samSaveBackupSchedule($pdo, $schedule);
}

// ═════════════════════════════════════════════════════════════════════════════
// XSS PREVENTION
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Escape HTML entities for safe display
 * @param string $text Text to escape
 * @return string Escaped text safe for HTML
 */
function escapeHtml($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/**
 * Check if a string contains HTML/JavaScript
 * @param string $text Text to check
 * @return bool True if potentially dangerous content detected
 */
function containsHtmlOrScript($text) {
    $text = (string)$text;
    $patterns = [
        '/<script/i',
        '/<iframe/i',
        '/javascript:/i',
        '/on\w+\s*=/i',  // Event handlers like onclick=
        '/<embed/i',
        '/<object/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: Enhanced Input Validation & Sanitization
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Sanitize string input: remove control chars, validate encoding, enforce length
 * @param mixed $input Input to sanitize
 * @param int $maxLength Maximum allowed length
 * @return string Sanitized string
 */
function sanitizeInput($input, $maxLength = 10000) {
    if (!is_string($input)) {
        $input = (string)$input;
    }

    // Enforce max length
    if (strlen($input) > $maxLength) {
        error_log("[INPUT_VIOLATION] Input exceeds max length ({$maxLength} chars)");
        return substr($input, 0, $maxLength);
    }

    // Remove null bytes and control characters (except newlines/tabs)
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);

    // Validate UTF-8 encoding
    if (!mb_check_encoding($input, 'UTF-8')) {
        error_log("[INPUT_ERROR] Invalid UTF-8 encoding detected");
        $input = mb_convert_encoding($input, 'UTF-8', 'UTF-8');
    }

    return trim($input);
}

/**
 * Validate email format
 * @param string $email Email to validate
 * @return bool True if valid email format
 */
function isValidEmail($email) {
    $email = sanitizeInput($email, 254);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number format (basic check)
 * @param string $phone Phone to validate
 * @return bool True if looks like a phone number
 */
function isValidPhone($phone) {
    $phone = sanitizeInput($phone, 20);
    return preg_match('/^[\d\s\-\(\)\+]+$/', $phone) === 1 && strlen($phone) >= 10;
}

/**
 * Validate numeric ID (positive integer)
 * @param mixed $id ID to validate
 * @return bool True if valid positive integer
 */
function isValidId($id) {
    $id = (int)$id;
    return $id > 0;
}

/**
 * Validate array contains only whitelisted keys
 * @param array $array Array to validate
 * @param array $allowedKeys Whitelist of allowed keys
 * @return array Filtered array with only allowed keys
 */
function filterByWhitelist($array, $allowedKeys) {
    if (!is_array($array)) {
        return [];
    }
    return array_intersect_key($array, array_flip($allowedKeys));
}

// ═════════════════════════════════════════════════════════════════════════════
// PRODUCTION HARDENING: Password & File Security
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Hash a password using bcrypt (OWASP recommendation)
 * @param string $password Password to hash
 * @param int $cost Bcrypt cost (default 12, 10-14 recommended)
 * @return string|null Hashed password or null if hash fails
 */
function hashPassword($password, $cost = 12) {
    if (empty($password) || !is_string($password)) {
        return null;
    }
    $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    return $hashed !== false ? $hashed : null;
}

/**
 * Verify password against bcrypt hash
 * @param string $password Plain text password to verify
 * @param string $hash Bcrypt hash to verify against
 * @return bool True if password matches hash
 */
function verifyPassword($password, $hash) {
    if (empty($password) || empty($hash) || !is_string($password) || !is_string($hash)) {
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * Check if a password needs rehashing (bcrypt cost changed or algorithm upgraded)
 * @param string $hash Current password hash
 * @return bool True if password should be rehashed
 */
function needsRehash($hash) {
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Validate file path is within allowed directory (prevent path traversal)
 * @param string $filePath Path to validate
 * @param string $baseDir Base directory that must contain the file
 * @return bool True if file is safely within base directory
 */
function isPathAllowed($filePath, $baseDir) {
    $baseDir = realpath($baseDir);
    $filePath = realpath($filePath);

    if (!$baseDir || !$filePath) {
        return false; // One or both paths don't exist
    }

    // Ensure file is within base directory
    return strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) === 0;
}

/**
 * Safely read file with size limits and encoding validation
 * @param string $filePath File to read
 * @param string $baseDir Base directory for path validation
 * @param int $maxSize Maximum file size to read (default 1MB)
 * @return string|null File contents or null if invalid/too large
 */
function safeReadFile($filePath, $baseDir, $maxSize = 1048576) {
    if (!isPathAllowed($filePath, $baseDir)) {
        error_log("[FILE_ERROR] Path traversal attempt: $filePath");
        return null;
    }

    if (!file_exists($filePath) || !is_readable($filePath)) {
        error_log("[FILE_ERROR] File not readable: $filePath");
        return null;
    }

    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize > $maxSize) {
        error_log("[FILE_ERROR] File too large: $filePath ($fileSize bytes)");
        return null;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        error_log("[FILE_ERROR] Failed to read file: $filePath");
        return null;
    }

    return $content;
}

/**
 * Safely write file with directory validation
 * @param string $filePath File to write
 * @param string $baseDir Base directory for path validation
 * @param string $content Content to write
 * @param int $permissions File permissions (default 0640 - restrictive)
 * @return bool True if write successful
 */
function safeWriteFile($filePath, $baseDir, $content, $permissions = 0640) {
    if (!isPathAllowed($filePath, $baseDir)) {
        error_log("[FILE_ERROR] Path traversal attempt on write: $filePath");
        return false;
    }

    // Create directory if needed (restrictive permissions)
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750, true)) {
            error_log("[FILE_ERROR] Failed to create directory: $dir");
            return false;
        }
    }

    if (file_put_contents($filePath, $content, LOCK_EX) === false) {
        error_log("[FILE_ERROR] Failed to write file: $filePath");
        return false;
    }

    // Set restrictive permissions (owner read/write, group read, no others)
    chmod($filePath, $permissions);
    return true;
}

/**
 * Validate table name is in whitelist (prevent SQL injection via table names)
 * @param string $tableName Table name to validate
 * @param array $whitelist Allowed table names
 * @return bool True if table is whitelisted
 */
function isValidTableName($tableName, $whitelist = []) {
    $defaultWhitelist = [
        'items', 'bidders', 'winners', 'payments', 'settings',
        'audit_log', 'sam_store', 'emails', 'members', 'registrations',
        'auctions', 'workflow_steps'
    ];

    $allowed = !empty($whitelist) ? $whitelist : $defaultWhitelist;
    return in_array($tableName, $allowed, true);
}

/**
 * Escape SQL identifier (table/column names) - use backticks for MySQL
 * @param string $identifier Table or column name to escape
 * @return string Escaped identifier safe for SQL
 */
function escapeSqlIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Rotate log files older than retention period (prevent disk exhaustion)
 * @param string $logDir Directory containing logs
 * @param int $retentionDays Days to keep logs (default 30)
 * @param int $maxFiles Maximum number of log files to keep
 * @return array Summary of cleanup actions
 */
function rotateLogFiles($logDir, $retentionDays = 30, $maxFiles = 100) {
    $result = ['deleted' => 0, 'errors' => 0];

    if (!is_dir($logDir)) {
        error_log("[LOG_ROTATION] Directory not found: $logDir");
        return $result;
    }

    $cutoffTime = time() - ($retentionDays * 86400);
    $files = glob($logDir . '/*.log', GLOB_NOSORT);

    if (!$files) {
        return $result;
    }

    // Sort by modification time (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    // Delete old files
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            if (!unlink($file)) {
                $result['errors']++;
                error_log("[LOG_ROTATION] Failed to delete: $file");
            } else {
                $result['deleted']++;
            }
        }
    }

    // If still too many files, delete oldest until we're under limit
    $remainingFiles = glob($logDir . '/*.log', GLOB_NOSORT);
    if (count($remainingFiles) > $maxFiles) {
        usort($remainingFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        $toDelete = count($remainingFiles) - $maxFiles;
        for ($i = 0; $i < $toDelete; $i++) {
            if (!unlink($remainingFiles[$i])) {
                $result['errors']++;
            } else {
                $result['deleted']++;
            }
        }
    }

    return $result;
}

/**
 * Validate database connection and permissions
 * @param PDO $pdo Database connection
 * @return array Status array with 'valid' => bool and any issues found
 */
function validateDatabaseSecurity($pdo) {
    $issues = [];

    try {
        // Check that strict mode is enabled
        $result = $pdo->query("SELECT @@sql_mode")->fetchColumn();
        if (strpos($result, 'STRICT_TRANS_TABLES') === false) {
            $issues[] = 'STRICT_TRANS_TABLES mode not enabled';
        }

        // Check that tables exist
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            $issues[] = 'No tables found in database';
        }

        // Check for dangerous global variables (should not allow FILE access)
        $filePriv = $pdo->query("SELECT @@secure_file_priv")->fetchColumn();
        if ($filePriv === null || $filePriv === '') {
            $issues[] = 'secure_file_priv not set (FILE operations enabled globally)';
        }

    } catch (Exception $e) {
        $issues[] = 'Database check failed: ' . $e->getMessage();
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues
    ];
}
