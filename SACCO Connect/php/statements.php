<?php
/**
 * statements.php — handles SACCO statement generation and balance checks
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {
    case 'generate':
        require_role(['administrator', 'manager']);

        $memberId = (int) ($_POST['member_id'] ?? 0);
        $stmtType = $_POST['statement_type'] ?? 'monthly';
        $delivery = $_POST['delivery_method'] ?? 'download';
        $periodStart = $_POST['period_start'] ?? date('Y-m-01');
        $periodEnd = $_POST['period_end'] ?? date('Y-m-t');
        $costStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'notification_cost'");
        $costStmt->execute();
        $notificationCost = (float) ($costStmt->fetch()['setting_value'] ?? 200);

        if ($memberId <= 0) {
            header('Location: ../statements.html?error=invalid_member');
            exit;
        }

        // Get member info
        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $stmt->execute([':id' => $memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            header('Location: ../statements.html?error=member_not_found');
            exit;
        }

        // Generate statement data
        $stmtTransactions = $pdo->query(
            'SELECT t.id, t.type, t.amount, t.balance_after, t.created_at, 
                    CASE WHEN t.type = "deposit" THEN "Deposit" 
                         WHEN t.type = "withdrawal" THEN "Withdrawal" 
                         ELSE t.type END as display_type
             FROM savings_transactions t
             WHERE t.member_id = :member_id
             ORDER BY t.created_at DESC'
        );
        $transactions = $stmtTransactions->fetchAll();

        $stmtLoan = $pdo->query(
            'SELECT l.id, l.principal, l.interest_rate, l.term_months, l.status,
                    SUM(IFNULL(lr.amount, 0)) as total_repaid
             FROM loans l
             LEFT JOIN loan_repayments lr ON l.id = lr.loan_id
             WHERE l.member_id = :member_id
             GROUP BY l.id
             ORDER BY l.applied_date DESC
        '
        );
        $loans = $stmtLoan->fetchAll();

        $stmtShares = $pdo->query(
            'SELECT COALESCE(SUM(shares_count), 0) as total_shares FROM shares WHERE member_id = :member_id'
        );
        $shares = $stmtShares->fetch();

        // Calculate total balance
        $stmtBal = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id');
        $stmtBal->execute([':id' => $memberId]);
        $balance = $stmtBal->fetch()['balance'];

        // Create statement record
        $stmtRef = 'STT-' . date('YmdHis') . '-' . str_pad((string)$memberId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO statements (member_id, statement_type, period_start, period_end, generated_by, file_path, status)
             VALUES (:member_id, :stmt_type, :period_start, :period_end, :gen_by, :file_path, "generated")'
        )->execute([
            ':member_id' => $memberId,
            ':stmt_type' => $stmtType,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':gen_by' => $_SESSION['staff_id'],
            ':file_path' => "statements/{$memberId}_{$stmtType}_{date('YmdHis')}.html"
        ]);

        // In a full implementation, this would generate a PDF/HTML file
        // For now, we'll mark it as generated and send notification
        $pdo->prepare('UPDATE statements SET status = "generated" WHERE id = :id')
            ->execute([':id' => $pdo->lastInsertId()]);

        // Send notification to member about statement availability
        send_notification($pdo, $memberId, 'email',
            'Statement Available',
            "Your SACCO statement for the period $periodStart to $periodEnd is now available. Statement Reference: $stmtRef. Download from your member portal or contact admin for a copy. Cost: {$notificationCost} UGX.",
            $_SESSION['staff_id']);

        header('Location: ../statements.html?success=1&stmt=' . $memberId);
        exit;

    case 'balance_check':
        require_role(['administrator', 'manager', 'cashier']);

        $memberId = (int) ($_POST['member_id'] ?? 0);
        $delivery = $_POST['delivery_method'] ?? 'email';

        if ($memberId <= 0) {
            header('Location: ../statements.html?error=invalid_member');
            exit;
        }

        // Get member info
        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $stmt->execute([':id' => $memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            header('Location: ../statements.html?error=member_not_found');
            exit;
        }

        // Get current balance
        $stmtBal = $pdo->prepare('SELECT balance FROM savings_accounts WHERE member_id = :id');
        $stmtBal->execute([':id' => $memberId]);
        $balance = $stmtBal->fetch()['balance'];

        // Get recent transactions for context
        $stmtTxns = $pdo->query(
            'SELECT t.id, t.type, t.amount, t.balance_after, t.created_at
             FROM savings_transactions t
             WHERE t.member_id = :member_id
             ORDER BY t.created_at DESC
             LIMIT 10'
        );
        $recentTxns = $stmtTxns->fetchAll();

        // Calculate available balance (after activation fee if not paid)
        $stmtFee = $pdo->prepare('SELECT activation_fee_paid, activation_fee_amount FROM members WHERE id = :id');
        $stmtFee->execute([':id' => $memberId]);
        $memberInfo = $stmtFee->fetch();
        $activationPaid = $memberInfo['activation_fee_paid'] ?? false;
        $activationAmount = $memberInfo['activation_fee_amount'] ?? 0;
        $availableBalance = $balance - $activationAmount;

        // Send notification to member
        $stmtType = $_POST['delivery_method'] ?? 'email';
        send_notification($pdo, $memberId, $stmtType,
            'Balance Information',
            "Your current savings balance is UGX " . number_format($balance, 2) . ". Available for withdrawal: UGX " . number_format($availableBalance, 2) . ". Activation fee paid: " . ($activationPaid ? 'Yes' : 'No - 15,000 UGX required.'). ". Recent activity: " . count($recentTxns) . " transactions.",
            $_SESSION['staff_id']);

        // Return JSON response for AJAX use
        header('Content-Type: application/json');
        echo json_encode([
            'member_no' => $member['member_no'],
            'full_name' => $member['full_name'],
            'balance' => $balance,
            'available_balance' => $availableBalance,
            'activation_fee_paid' => $activationPaid,
            'recent_transactions' => array_map(function($t) {
                return [
                    'type' => $t['display_type'],
                    'amount' => $t['amount'],
                    'balance_after' => $t['balance_after'],
                    'date' => $t['created_at']
                ];
            }, $recentTxns)
        ]);
        exit;

    case 'list':
    default:
        header('Location: ../statements.html');
        exit;
}