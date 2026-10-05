<?php
/**
 * notifications.php — handles sending notifications to members via Email, WhatsApp, or SMS
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'send':
        require_role(['administrator', 'manager']);

        $memberId = (int) ($_POST['member_id'] ?? 0);
        $type = $_POST['type'] ?? 'email';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        // Get notification cost
        $costStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'notification_cost'");
        $costStmt->execute();
        $notificationCost = (float) ($costStmt->fetch()['setting_value'] ?? 200);

        if ($memberId > 0) {
            // Send to specific member
            $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
            $memberStmt->execute([':id' => $memberId]);
            $member = $memberStmt->fetch();

            if (!$member) {
                header('Location: ../notifications.html?error=member_not_found');
                exit;
            }

            // Determine recipient based on type
            if ($type === 'email') {
                $recipient = $member['email'] ?: '';
            } elseif ($type === 'whatsapp') {
                $recipient = $member['phone'] ?: '';
            } elseif ($type === 'sms_airtel' || $type === 'sms_mtn') {
                $provider = $type === 'sms_airtel' ? 'Airtel' : 'MTN';
                // Send SMS - in production, integrate with SMS gateway
                $recipient = $member['phone'] ?: '';
                $message .= " [SMS via $provider]";
            } else {
                $recipient = $member['email'] ?: '';
            }

            if (!$recipient) {
                header('Location: ../notifications.html?error=no_contact');
                exit;
            }

            // Send notification
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (member_id, type, recipient, subject, message, cost, status)
                 VALUES (:member_id, :type, :recipient, :subject, :message, :cost, "sent")'
            );
            $stmt->execute([
                ':member_id' => $memberId,
                ':type' => $type,
                ':recipient' => $recipient,
                ':subject' => $subject,
                ':message' => $message,
                ':cost' => $notificationCost,
            ]);

            // Deduct notification cost from member's savings
            $pdo->beginTransaction();
            try {
                $balStmt = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
                $balStmt->execute([':id' => $memberId]);
                $account = $balStmt->fetch();

                if ($account && $account['balance'] >= $notificationCost) {
                    $newBal = $account['balance'] - $notificationCost;
                    $pdo->prepare('UPDATE savings_accounts SET balance = :bal WHERE member_id = :id')
                        ->execute([':bal' => $newBal, ':id' => $memberId]);

                    // Record the notification withdrawal transaction
                    $pdo->prepare(
                        'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes)
                         VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes)'
                    )->execute([
                        ':member_id' => $memberId,
                        ':type' => 'withdrawal',
                        ':amount' => $notificationCost,
                        ':balance_after' => $newBal,
                        ':staff_id' => $_SESSION['staff_id'],
                        ':notes' => "Notification cost ($type): $subject"
                    ]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                header('Location: ../notifications.html?error=insufficient_balance');
                exit;
            }
        } else {
            // Broadcast to all active members
            $allMembers = $pdo->query(
                'SELECT id, member_no, full_name, email, phone FROM members WHERE status = "active"'
            )->fetchAll();

            foreach ($allMembers as $m) {
                // Determine recipient based on type
                $recipient = '';
                if ($type === 'email') {
                    $recipient = $m['email'] ?: '';
                } elseif ($type === 'whatsapp') {
                    $recipient = $m['phone'] ?: '';
                } elseif ($type === 'sms_airtel' || $type === 'sms_mtn') {
                    $provider = $type === 'sms_airtel' ? 'Airtel' : 'MTN';
                    $recipient = $m['phone'] ?: '';
                    $message .= " [SMS via $provider]";
                }

                if ($recipient) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO notifications (member_id, type, recipient, subject, message, cost, status)
                         VALUES (:member_id, :type, :recipient, :subject, :message, :cost, "sent")'
                    );
                    $stmt->execute([
                        ':member_id' => $m['id'],
                        ':type' => $type,
                        ':recipient' => $recipient,
                        ':subject' => $subject,
                        ':message' => $message,
                        ':cost' => $notificationCost,
                    ]);

                    // Deduct from each member's balance
                    $pdo->beginTransaction();
                    try {
                        $balStmt = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
                        $balStmt->execute([':id' => $m['id']]);
                        $account = $balStmt->fetch();

                        if ($account && $account['balance'] >= $notificationCost) {
                            $newBal = $account['balance'] - $notificationCost;
                            $pdo->prepare('UPDATE savings_accounts SET balance = :bal WHERE member_id = :id')
                                ->execute([':bal' => $newBal, ':id' => $m['id']]);

                            $pdo->prepare(
                                'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes)
                                 VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes)'
                            )->execute([
                                ':member_id' => $m['id'],
                                ':type' => 'withdrawal',
                                ':amount' => $notificationCost,
                                ':balance_after' => $newBal,
                                ':staff_id' => $_SESSION['staff_id'],
                                ':notes' => "Broadcast notification ($type): $subject"
                            ]);
                        }
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        // Continue with other members even if one fails
                    }
                }
            }
        }

        header('Location: ../notifications.html?success=1');
        exit;

    case 'history':
    default:
        // Get notification history
        $stmt = $pdo->query(
            'SELECT n.id, m.member_no, m.full_name, n.type, n.subject, n.message, n.cost, n.status, n.sent_at
             FROM notifications n
             JOIN members m ON m.id = n.member_id
             ORDER BY n.sent_at DESC
             LIMIT 100'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}