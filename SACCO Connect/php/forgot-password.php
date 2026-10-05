<?php
/**
 * forgot-password.php — handles password reset requests
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forgot-password.html');
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');

if ($identifier === '') {
    header('Location: ../forgot-password.html?error=missing_fields');
    exit;
}

// Try to find member by member number or phone
$stmt = $pdo->prepare('SELECT * FROM members WHERE member_no = :id OR phone = :id');
$stmt->execute([':id' => $identifier]);
$member = $stmt->fetch();

if (!$member) {
    header('Location: ../forgot-password.html?error=not_found');
    exit;
}

// Generate temporary password
$tempPassword = substr(bin2hex(random_bytes(3)), 0, 8);
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

// Update member password
$pdo->prepare('UPDATE members SET password_hash = :hash WHERE id = :id')
    ->execute([':hash' => $passwordHash, ':id' => $member['id']]);

// Send temp password via email and/or SMS
$subject = 'MBARARA CITY MUKAMA NUWAMANYA PEOPLE\'S SACCO - Password Reset';
$message = "Your password has been reset. Temporary password: $tempPassword. Please login and change your immediate password. Member number: $member[member_no]";

$stmt = $pdo->prepare('UPDATE members SET password_hash = :hash WHERE id = :id');
$stmt->execute([':hash' => $passwordHash, ':id' => $member['id']]);

// Log the password reset
log_audit($pdo, null, 'password_reset', "Password reset for member #{$member['id']} ($member[member_no])");

header('Location: ../forgot-password.html?sent=1');
exit;