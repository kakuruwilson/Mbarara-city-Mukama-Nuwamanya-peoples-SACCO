<?php
/**
 * monthly-payments.php — handles monthly ABSA bank payment tracking
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'record':
        require_role(['administrator', 'manager']);

        $paymentMonth = $_POST['payment_month'] ?? date('Y-m');
        $amount = (float) ($_POST['amount'] ?? 50000);
        $paymentReference = trim($_POST['payment_reference'] ?? 'SACCO-MONTHLY-' . date('Y-m'));
        $status = $_POST['status'] ?? 'paid';

        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $paymentMonth)) {
            header('Location: ../monthly-payments.html?error=invalid_month');
            exit;
        }

        // Check if payment already exists for this month
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM monthly_payments WHERE payment_month = :month AND status = "pending"'
        );
        $stmtCheck->execute([':month' => $paymentMonth]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            header('Location: ../monthly-payments.html?error=already_exists');
            exit;
        }

        // Record the payment
        $stmt = $pdo->prepare(
            'INSERT INTO monthly_payments (payment_month, amount, payment_reference, status, paid_by)
             VALUES (:payment_month, :amount, :payment_reference, :status, :staff_id)'
        );
        $stmt->execute([
            ':payment_month' => $paymentMonth,
            ':amount' => $amount,
            ':payment_reference' => $paymentReference,
            ':status' => $status,
            ':staff_id' => $_SESSION['staff_id'],
        ]);

        $paymentId = $pdo->lastInsertId();

        // Send notification to all members about monthly payment
        $stmtNotify = $pdo->prepare(
            'INSERT INTO notifications (member_id, type, recipient, subject, message, cost, status)
             VALUES (:member_id, :type, :recipient, :subject, :message, :cost, "sent")'
        );

        $allMembers = $pdo->query('SELECT id, member_no, full_name, email, phone FROM members WHERE status = "active"')->fetchAll();

        foreach ($allMembers as $m) {
            // Determine recipient based on preferred method (email by default)
            $recipient = $m['email'] ?: $m['phone'] ?: '';

            if ($recipient) {
                $stmtNotify->execute([
                    ':member_id' => $m['id'],
                    ':type' => 'email',
                    ':recipient' => $recipient,
                    ':subject' => 'Monthly SACCO Payment Due',
                    ':message' => "The monthly SACCO payment of UGX " . number_format($amount, 0) . " is due for month $paymentMonth. Payment reference: $paymentReference. Please make payment to ABSA Bank Account Number 6010003286.",
                    ':cost' => 200,
                ]);

                // Deduct notification cost
                $pdo->beginTransaction();
                try {
                    $balStmt = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
                    $balStmt->execute([':id' => $m['id']]);
                    $account = $balStmt->fetch();

                    if ($account && $account['balance'] >= 200) {
                        $newBal = $account['balance'] - 200;
                        $pdo->prepare('UPDATE savings_accounts SET balance = :bal WHERE member_id = :id')
                            ->execute([':bal' => $newBal, ':id' => $m['id']]);

                        $pdo->prepare(
                            'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes)
                             VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes)'
                        )->execute([
                            ':member_id' => $m['id'],
                            ':type' => 'withdrawal',
                            ':amount' => 200,
                            ':balance_after' => $newBal,
                            ':staff_id' => $_SESSION['staff_id'],
                            ':notes' => "Monthly fee notification: $paymentReference"
                        ]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                }
            }
        }

        log_audit($pdo, $_SESSION['staff_id'], 'monthly_payment_recorded', "Recorded monthly payment $paymentReference for $amount UGX");

        header('Location: ../monthly-payments.html?success=1');
        exit;

    case 'list':
    default:
        // Get payment history with summary
        $stmtPayments = $pdo->query(
            'SELECT mp.id, mp.payment_month, mp.amount, mp.payment_reference, mp.status,
                    u.full_name as recorded_by_name,
                    mp.paid_at
             FROM monthly_payments mp
             LEFT JOIN users u ON mp.paid_by = u.id
             ORDER BY mp.payment_month DESC
        '
        );
        $payments = $stmtPayments->fetchAll();

        // Calculate summary
        $stmtSummary = $pdo->query(
            'SELECT 
                SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END) as total_pending,
                COUNT(CASE WHEN status = "paid" THEN 1 END) as paid_count,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_count
             FROM monthly_payments'
        );
        $summary = $stmtSummary->fetch();

        // Get current month status
        $stmtCurrent = $pdo->prepare(
            'SELECT * FROM monthly_payments WHERE payment_month = :current_month ORDER BY created_at DESC LIMIT 1'
        );
        $stmtCurrent->execute([':current_month' => date('Y-m')]);
        $currentPayment = $stmtCurrent->fetch();

        // Calculate total from all time
        $stmtTotal = $pdo->query(
            'SELECT SUM(amount) as grand_total FROM monthly_payments WHERE status = "paid"'
        );
        $totalResult = $stmtTotal->fetch();

        header('Content-Type: application/json');
        echo json_encode([
            'payments' => $payments,
            'summary' => [
                'total_paid' => $summary['total_paid'] ?? 0,
                'total_pending' => $summary['total_pending'] ?? 0,
                'paid_count' => $summary['paid_count'] ?? 0,
                'pending_count' => $summary['pending_count'] ?? 0,
                'grand_total' => $totalResult['grand_total'] ?? 0,
            ],
            'current_month' => date('Y-m'),
            'current_payment' => $currentPayment,
        ]);
        exit;
}