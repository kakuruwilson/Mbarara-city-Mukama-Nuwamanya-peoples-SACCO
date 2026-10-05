<?php
/**
 * register.php — handles POST from register.html (public "Register Member" page)
 * Creates a new member with status "active" and an auto-generated member number.
 * Requires activation fee payment after registration.
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../register.html');
    exit;
}

$fullName = trim($_POST['full_name'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$email    = trim($_POST['email'] ?? '');
$gender   = $_POST['gender'] ?? null;
$address  = trim($_POST['address'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

if ($fullName === '' || $phone === '' || $password === '') {
    header('Location: ../register.html?error=missing_fields');
    exit;
}
if ($password !== $confirm) {
    header('Location: ../register.html?error=password_mismatch');
    exit;
}

// Generate the next member number, e.g. MCMNP-0001
$countStmt = $pdo->query('SELECT COUNT(*) AS total FROM members');
$next = (int) $countStmt->fetch()['total'] + 1;
$memberNo = 'MCMNP-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);

$stmt = $pdo->prepare(
    'INSERT INTO members (member_no, full_name, gender, phone, email, address, password_hash)
     VALUES (:member_no, :full_name, :gender, :phone, :email, :address, :password_hash)'
);
$stmt->execute([
    ':member_no'     => $memberNo,
    ':full_name'     => $fullName,
    ':gender'        => $gender,
    ':phone'         => $phone,
    ':email'         => $email ?: null,
    ':address'       => $address,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

$memberId = (int) $pdo->lastInsertId();

// Create an empty savings account and shares record for the new member
$pdo->prepare('INSERT INTO savings_accounts (member_id, balance, activation_fee_balance) VALUES (:id, 0, 0)')->execute([':id' => $memberId]);
$pdo->prepare('INSERT INTO shares (member_id, shares_count) VALUES (:id, 0)')->execute([':id' => $memberId]);

log_audit($pdo, null, 'member_registered', "New member: $memberNo ($fullName)");

header('Location: ../login.html?registered=' . urlencode($memberNo . ' / please pay 15,000 UGX activation fee to activate your account'));
exit;