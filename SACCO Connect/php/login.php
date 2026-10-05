<?php
/**
 * login.php — handles POST from login.html
 * Tries staff (users table) first, then falls back to members table,
 * so one login form works for both staff and members.
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: ../login.html?error=missing_fields');
    exit;
}

// 1. Try staff login (by username)
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username AND status = "active"');
$stmt->execute([':username' => $username]);
$staff = $stmt->fetch();

if ($staff && password_verify($password, $staff['password_hash'])) {
    $_SESSION['staff_id']   = $staff['id'];
    $_SESSION['staff_name'] = $staff['full_name'];
    $_SESSION['staff_role'] = $staff['role'];
    log_audit($pdo, $staff['id'], 'login', 'Staff login: ' . $staff['username']);
    header('Location: ../dashboard.html');
    exit;
}

// 2. Try member login (by member_no or phone)
$stmt = $pdo->prepare('SELECT * FROM members WHERE (member_no = :id OR phone = :id) AND status = "active"');
$stmt->execute([':id' => $username]);
$member = $stmt->fetch();

if ($member && password_verify($password, $member['password_hash'])) {
    $_SESSION['member_id']   = $member['id'];
    $_SESSION['member_name'] = $member['full_name'];
    log_audit($pdo, null, 'login', 'Member login: ' . $member['member_no']);
    header('Location: ../member-dashboard.html');
    exit;
}

// Neither matched
header('Location: ../login.html?error=invalid_credentials');
exit;
