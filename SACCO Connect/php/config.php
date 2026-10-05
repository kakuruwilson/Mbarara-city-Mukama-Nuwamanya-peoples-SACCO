<?php
/**
 * config.php — database connection settings.
 * Works with XAMPP/WAMP default MySQL setup (user: root, no password).
 * Update DB_USER / DB_PASS if you've secured your local MySQL install.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'sacco_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Start session on every page that includes this file
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // In production, log this instead of showing it to the user.
    die('Database connection failed. Make sure MySQL is running in XAMPP/WAMP and that the "sacco_db" database has been imported from database/sacco.sql.');
}

/**
 * Write an entry to the audit log. Call this after any meaningful action
 * (login, deposit, loan approval, settings change, etc.)
 */
function log_audit(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:user_id, :action, :details, :ip)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':action'  => $action,
        ':details' => $details,
        ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}
