<?php
/**
 * mobile-money.php — handles mobile money deposit verification
 * Admin verifies deposits made by members via Airtel/MTN Mobile Money
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();
require_role(['administrator', 'manager', 'cashier']);

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {
    case 'verify':
        require_role(['administrator', 'manager']);
        
        $depositId = (int) ($_POST['deposit_id'] ?? 0);
        $status = $_POST['status'] ?? ''; // 'verified' or 'rejected'
        $notes = trim($_POST['notes'] ?? '');

        if ($depositId <= 0 || !in_array($status, ['verified', 'rejected'])) {
            header('Location: ../mobile-money.html?error=invalid_input');
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Get deposit details
            $stmt = $pdo->prepare('SELECT * FROM mobile_money_deposits WHERE id = :id AND status = "pending" FOR UPDATE');
            $stmt->execute([':id' => $depositId]);
            $deposit = $stmt->fetch();
            
            if (!$deposit) throw new Exception('Deposit not found or already processed.');

            if ($status === 'verified') {
                // Update deposit status
                $pdo->prepare('UPDATE mobile_money_deposits SET status = "verified", verified_by = :staff_id, verified_at = NOW() WHERE id = :id')
                    ->execute([':staff_id' => $_SESSION['staff_id'], ':id' => $depositId]);

                // Update member's savings account
                $stmt = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
                $stmt->execute([':id' => $deposit['member_id']]);
                $account = $stmt->fetch();
                
                $newBalance = $account['balance'] + $deposit['amount'];
                $pdo->prepare('UPDATE savings_accounts SET balance = :bal WHERE member_id = :id')
                    ->execute([':bal' => $newBalance, ':id' => $deposit['member_id']]);

                // Record transaction
                $ref = 'MM-' . strtoupper(substr($deposit['provider'], 0, 3)) . '-' . date('Ymd') . '-' . str_pad((string)$depositId, 6, '0', STR_PAD_LEFT);
                $pdo->prepare(
                    'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes, reference_number, mobile_money_provider, mobile_money_number)
                     VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes, :ref, :provider, :sender)'
                )->execute([
                    ':member_id'     => $deposit['member_id'],
                    ':type'          => 'mobile_money_deposit',
                    ':amount'        => $deposit['amount'],
                    ':balance_after' => $newBalance,
                    ':staff_id'      => $_SESSION['staff_id'],
                    ':notes'         => $notes ?: "Mobile money deposit via {$deposit['provider']} from {$deposit['sender_number']}",
                    ':ref'           => $ref,
                    ':provider'      => $deposit['provider'],
                    ':sender'        => $deposit['sender_number'],
                ]);

                // Send notification to member
                send_notification($pdo, $deposit['member_id'], 'email', 
                    'Deposit Confirmed', 
                    "Your mobile money deposit of " . number_format($deposit['amount'], 0) . " UGX via " . strtoupper($deposit['provider']) . " has been verified. New balance: " . number_format($newBalance, 0) . " UGX. Reference: $ref",
                    $_SESSION['staff_id']);

                log_audit($pdo, $_SESSION['staff_id'], 'mobile_money_verified', "Verified deposit #$depositId for member #{$deposit['member_id']}, amount {$deposit['amount']}");
            } else {
                $pdo->prepare('UPDATE mobile_money_deposits SET status = "rejected", verified_by = :staff_id, verified_at = NOW() WHERE id = :id')
                    ->execute([':staff_id' => $_SESSION['staff_id'], ':id' => $depositId]);
                
                // Notify member of rejection
                send_notification($pdo, $deposit['member_id'], 'email',
                    'Deposit Rejected',
                    "Your mobile money deposit of " . number_format($deposit['amount'], 0) . " UGX via " . strtoupper($deposit['provider']) . " was rejected. Reason: " . ($notes ?: 'No reason provided'),
                    $_SESSION['staff_id']);
                
                log_audit($pdo, $_SESSION['staff_id'], 'mobile_money_rejected', "Rejected deposit #$depositId for member #{$deposit['member_id']}");
            }

            $pdo->commit();
            header('Location: ../mobile-money.html?success=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: ../mobile-money.html?error=' . urlencode($e->getMessage()));
        }
        exit;

    case 'list':
    default:
        // Pending deposits
        $stmt = $pdo->query(
            'SELECT m.id as member_id, m.member_no, m.full_name, m.phone, m.email,
                    d.id, d.amount, d.provider, d.sender_number, d.reference_number, d.transaction_message, d.created_at
             FROM mobile_money_deposits d
             JOIN members m ON m.id = d.member_id
             WHERE d.status = "pending"
             ORDER BY d.created_at DESC'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}

function send_notification(PDO $pdo, int $memberId, string $type, string $subject, string $message, int $sentBy): void {
    $stmt = $pdo->prepare('SELECT email, phone FROM members WHERE id = :id');
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch();
    if (!$member) return;

    $recipient = $type === 'email' ? $member['email'] : $member['phone'];
    if (!$recipient) return;

    $costStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'notification_cost'");
    $costStmt->execute();
    $cost = (float) ($costStmt->fetch()['setting_value'] ?? 200);

    // Deduct from member's balance
    $balStmt = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
    $balStmt->execute([':id' => $memberId]);
    $account = $balStmt->fetch();
    if ($account && $account['balance'] >= $cost) {
        $newBal = $account['balance'] - $cost;
        $pdo->prepare('UPDATE savings_accounts SET balance = :bal WHERE member_id = :id')
            ->execute([':bal' => $newBal, ':id' => $memberId]);
        
        $pdo->prepare(
            'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes)
             VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes)'
        )->execute([
            ':member_id' => $memberId,
            ':type' => 'withdrawal',
            ':amount' => $cost,
            ':balance_after' => $newBal,
            ':staff_id' => $sentBy,
            ':notes' => "Notification cost ($type): $subject"
        ]);
    }

    // Log notification
    $pdo->prepare(
        'INSERT INTO notifications (member_id, type, recipient, subject, message, cost, status)
         VALUES (:member_id, :type, :recipient, :subject, :message, :cost, "sent")'
    )->execute([
        ':member_id' => $memberId,
        ':type' => $type,
        ':recipient' => $recipient,
        ':subject' => $subject,
        ':message' => $message,
        ':cost' => $cost,
    ]);
}