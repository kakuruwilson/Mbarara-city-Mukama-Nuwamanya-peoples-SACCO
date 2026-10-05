<?php
/**
 * settings.php — handles system settings updates including activation fee, min deposit/withdrawal,
 * notification cost, ABSA account details, and admin password changes
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();
require_role(['administrator']);

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'update_settings':
        require_role(['administrator']);

        // Update system settings
        $settingsToUpdate = [
            'activation_fee' => (float) ($_POST['activation_fee'] ?? 15000),
            'min_deposit' => (float) ($_POST['min_deposit'] ?? 1000),
            'min_withdrawal' => (float) ($_POST['min_withdrawal'] ?? 5000),
            'notification_cost' => (float) ($_POST['notification_cost'] ?? 200),
            'monthly_fee' => (float) ($_POST['monthly_fee'] ?? 50000),
            'absa_account' => trim($_POST['absa_account'] ?? '6010003286'),
        ];

        foreach ($settingsToUpdate as $key => $value) {
            $stmt = $pdo->prepare(
                "UPDATE settings SET setting_value = :value WHERE setting_key = :key"
            );
            $stmt->execute([
                ':value' => $value,
                ':key' => $key,
            ]);
        }

        // Handle share value update
        $shareValue = (float) ($_POST['share_value'] ?? 20000);
        $stmtShare = $pdo->prepare(
            "UPDATE settings SET setting_value = :value WHERE setting_key = 'share_value'"
        );
        $stmtShare->execute([':value' => $shareValue]);

        log_audit($pdo, $_SESSION['staff_id'], 'settings_updated', "Updated system settings: activation_fee={$settingsToUpdate['activation_fee']}, min_deposit={$settingsToUpdate['min_deposit']}, min_withdrawal={$settingsToUpdate['min_withdrawal']}, notification_cost={$settingsToUpdate['notification_cost']}, monthly_fee={$settingsToUpdate['monthly_fee']}");

        header('Location: ../settings.html?success=1');
        exit;

    case 'change_password':
        require_role(['administrator']);

        $currentPassword = trim($_POST['current_password'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            header('Location: ../settings.html?error=missing_fields');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            header('Location: ../settings.html?error=password_mismatch');
            exit;
        }

        // Verify current password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE username = 'admin' AND id = 1");
        $stmt->execute();
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
            header('Location: ../settings.html?error=wrong_password');
            exit;
        }

        // Update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = :hash, password_changed = TRUE WHERE username = 'admin' AND id = 1")
            ->execute([':hash' => $newHash]);

        log_audit($pdo, $_SESSION['staff_id'], 'admin_password_changed', "Admin password changed by staff #{$_SESSION['staff_id']}");

        header('Location: ../settings.html?success=1&password_changed=1');
        exit;

    case 'add_user':
        require_role(['administrator']);

        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'cashier';

        if ($username === '' || $fullName === '' || $role === '') {
            header('Location: ../settings.html?error=missing_fields');
            exit;
        }

        // Generate temporary password
        $tempPassword = substr(bin2hex(random_bytes(4)), 0, 8);
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, email, phone)
             VALUES (:username, :password_hash, :full_name, :role, :email, :phone)'
        );
        $stmt->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':full_name' => $fullName,
            ':role' => $role,
            ':email' => $email ?: null,
            ':phone' => $phone,
        ]);

        log_audit($pdo, $_SESSION['staff_id'], 'user_added', "Added new user: $username as $role");

        // Send temp password via email
        send_notification($pdo, (int) $pdo->lastInsertId(), 'email',
            'New Account Credentials',
            "Your SACCO account has been created. Username: $username. Temporary password: $tempPassword. Please login and change your password immediately.",
            $_SESSION['staff_id']);

        header('Location: ../settings.html?success=1&user_added=' . urlencode($username));
        exit;

    case 'list':
    default:
        // Get all users with their details
        $stmt = $pdo->query(
            'SELECT u.id, u.username, u.full_name, u.role, u.email, u.phone, u.status,
                    u.password_changed,
                    CASE WHEN u.password_changed THEN "Yes" ELSE "No" END as pw_status
             FROM users u
             ORDER BY u.role, u.full_name'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}