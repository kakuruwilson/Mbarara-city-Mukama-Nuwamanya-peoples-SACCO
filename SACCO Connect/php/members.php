<?php
/**
 * members.php — member management actions, used by members.html
 * Supports: action=add, action=edit, action=deactivate
 * Staff only (any active role can view; add/edit restricted below).
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'add':
        require_role(['administrator', 'manager']);

        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        if ($fullName === '' || $phone === '') {
            header('Location: ../members.html?error=missing_fields');
            exit;
        }

        $countStmt = $pdo->query('SELECT COUNT(*) AS total FROM members');
        $next = (int) $countStmt->fetch()['total'] + 1;
        $memberNo = 'MCMNP-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $tempPassword = substr(bin2hex(random_bytes(4)), 0, 8); // give staff a temp password to hand to the member

        $stmt = $pdo->prepare(
            'INSERT INTO members (member_no, full_name, phone, email, gender, address, national_id, next_of_kin_name, next_of_kin_phone, password_hash, activation_fee_paid, activation_fee_amount)
             VALUES (:member_no, :full_name, :phone, :email, :gender, :address, :national_id, :nok_name, :nok_phone, :password_hash, FALSE, 0)'
        );
        $stmt->execute([
            ':member_no'   => $memberNo,
            ':full_name'   => $fullName,
            ':phone'       => $phone,
            ':email'       => trim($_POST['email'] ?? '') ?: null,
            ':gender'      => $_POST['gender'] ?? null,
            ':address'     => trim($_POST['address'] ?? ''),
            ':national_id' => trim($_POST['national_id'] ?? ''),
            ':nok_name'    => trim($_POST['nok_name'] ?? ''),
            ':nok_phone'   => trim($_POST['nok_phone'] ?? ''),
            ':password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
        ]);

        $memberId = (int) $pdo->lastInsertId();
        // Create savings account with activation fee tracking
        $pdo->prepare('INSERT INTO savings_accounts (member_id, balance, activation_fee_balance) VALUES (:id, 0, 0)')->execute([':id' => $memberId]);

        // Send temp password and activation info to member via email
        send_notification($pdo, $memberId, 'email',
            'Welcome to MBARARA CITY MUKAMA NUWAMANYA PEOPLE\'S SACCO',
            "Welcome! Your member number is $memberNo. Temporary password: $tempPassword. To activate your account, you must pay a one-time activation fee of 15,000 UGX. This fee is non-withdrawable and will be added to your account upon payment. Minimum deposit: 1,000 UGX. Minimum withdrawal: 5,000 UGX. Notifications cost 200 UGX each.",
            $_SESSION['staff_id']);

        log_audit($pdo, $_SESSION['staff_id'], 'member_added', "Added member $memberNo by staff #{$_SESSION['staff_id']}");
        header('Location: ../members.html?added=' . urlencode($memberNo . ' / temp password: ' . $tempPassword . ' / activation fee: 15,000 UGX required'));
        exit;

    case 'edit':
        require_role(['administrator', 'manager']);
        $memberId = (int) ($_POST['member_id'] ?? 0);

        $stmt = $pdo->prepare(
            'UPDATE members SET full_name=:full_name, phone=:phone, email=:email, address=:address,
                    activation_fee_paid=:activation_paid, activation_fee_amount=:activation_amount
             WHERE id=:id'
        );
        $stmt->execute([
            ':full_name' => trim($_POST['full_name'] ?? ''),
            ':phone'     => trim($_POST['phone'] ?? ''),
            ':email'     => trim($_POST['email'] ?? '') ?: null,
            ':address'   => trim($_POST['address'] ?? ''),
            ':activation_paid' => isset($_POST['activation_paid']) ? 1 : 0,
            ':activation_amount' => (float) ($_POST['activation_amount'] ?? 0),
            ':id'        => $memberId,
        ]);
        log_audit($pdo, $_SESSION['staff_id'], 'member_edited', "Edited member #$memberId");
        header('Location: ../members.html?updated=1');
        exit;

    case 'deactivate':
        require_role(['administrator', 'manager']);
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $pdo->prepare('UPDATE members SET status = "suspended" WHERE id = :id')->execute([':id' => $memberId]);
        log_audit($pdo, $_SESSION['staff_id'], 'member_suspended', "Suspended member #$memberId");
        header('Location: ../members.html?suspended=1');
        exit;

    case 'list':
    default:
        // Returns JSON for members.html to render into the table via fetch()
        $stmt = $pdo->query(
            'SELECT m.id, m.member_no, m.full_name, m.phone, m.email, m.status, m.joined_date,
                    s.balance, s.activation_fee_balance,
                    CASE WHEN m.activation_fee_paid = TRUE THEN "Paid" WHEN m.activation_fee_amount > 0 THEN "Pending" ELSE "Not Required" END as activation_status
             FROM members m
             LEFT JOIN savings_accounts s ON s.member_id = m.id
             ORDER BY m.id DESC'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}