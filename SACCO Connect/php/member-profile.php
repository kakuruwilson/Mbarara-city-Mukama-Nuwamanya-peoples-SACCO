<?php
/**
 * member-profile.php — handles member profile updates and password changes
 */

require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'update_profile':
        $memberId = (int) ($_POST['member_id'] ?? 0);

        if ($memberId <= 0) {
            header('Location: ../member-profile.html?error=invalid');
            exit;
        }

        // Update member profile
        $stmt = $pdo->prepare(
            'UPDATE members SET full_name = :full_name, phone = :phone, email = :email,
                    gender = :gender, address = :address, national_id = :national_id,
                    nok_name = :nok_name, nok_phone = :nok_phone
             WHERE id = :id'
        );
        $stmt->execute([
            ':full_name' => trim($_POST['full_name'] ?? ''),
            ':phone' => trim($_POST['phone'] ?? ''),
            ':email' => trim($_POST['email'] ?? '') ?: null,
            ':gender' => $_POST['gender'] ?? 'other',
            ':address' => trim($_POST['address'] ?? ''),
            ':national_id' => trim($_POST['national_id'] ?? ''),
            ':nok_name' => trim($_POST['nok_name'] ?? ''),
            ':nok_phone' => trim($_POST['nok_phone'] ?? ''),
            ':id' => $memberId,
        ]);

        log_audit($pdo, null, 'member_profile_updated', "Updated profile for member #$memberId");

        header('Location: ../member-profile.html?success=1&member=' . $memberId);
        exit;

    case 'change_password':
        $memberId = (int) ($_POST['member_id'] ?? 0);

        if ($memberId <= 0) {
            header('Location: ../member-profile.html?error=invalid');
            exit;
        }

        $currentPassword = trim($_POST['current_password'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            header('Location: ../member-profile.html?error=missing_fields');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            header('Location: ../member-profile.html?error=password_mismatch');
            exit;
        }

        // Get current member password hash
        $stmt = $pdo->prepare("SELECT password_hash FROM members WHERE id = :id");
        $stmt->execute([':id' => $memberId]);
        $member = $stmt->fetch();

        if (!$member || !password_verify($currentPassword, $member['password_hash'])) {
            header('Location: ../member-profile.html?error=wrong_password');
            exit;
        }

        // Update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE members SET password_hash = :hash WHERE id = :id")
            ->execute([':hash' => $newHash, ':id' => $memberId]);

        log_audit($pdo, null, 'member_password_changed', "Password changed for member #$memberId");

        header('Location: ../member-profile.html?success=1&member=' . $memberId . '&password_changed=1');
        exit;

    case 'list':
    default:
        // Get member info
        $memberId = (int) ($_GET['member'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $stmt->execute([':id' => $memberId]);
        $member = $stmt->fetch();

        header('Content-Type: application/json');
        echo json_encode($member);
        exit;
}