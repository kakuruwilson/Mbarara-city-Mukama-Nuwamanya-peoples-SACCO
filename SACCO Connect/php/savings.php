<?php
/**
 * savings.php — deposits and withdrawals, used by savings.html / transactions.html
 * `synced` is stored 1 on this machine always; when this database is later
 * merged with a central server, a sync script flips reconciled rows and
 * resolves duplicates by transaction timestamp + member_id.
 * 
 * NEW RULES:
 * - Min deposit: 1,000 UGX
 * - Min withdrawal: 5,000 UGX
 * - Activation fee: 15,000 UGX (non-withdrawable, one-time)
 * - Notification cost: 200 UGX per message
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? 'list';

switch ($action) {

    case 'deposit':
    case 'withdrawal':
    case 'activation_fee':
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $amount   = (float) ($_POST['amount'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');

        if ($memberId <= 0 || $amount <= 0) {
            header('Location: ../savings.html?error=invalid_input');
            exit;
        }

        // Get member info to check activation status
        $stmtMember = $pdo->prepare('SELECT activation_fee_paid, activation_fee_amount FROM members WHERE id = :id');
        $stmtMember->execute([':id' => $memberId]);
        $member = $stmtMember->fetch();

        if (!$member) {
            header('Location: ../savings.html?error=member_not_found');
            exit;
        }

        // Check activation fee status
        $activationPaid = $member['activation_fee_paid'] ?? false;
        $activationAmount = $member['activation_fee_amount'] ?? 0;

        // For activation fee, it's always accepted (sets the paid flag)
        if ($action === 'activation_fee') {
            // Activation fee is 15,000 UGX and non-withdrawable
            $requiredActivation = 15000.00;
            if ($activationPaid) {
                header('Location: ../savings.html?error=activation_paid');
                exit;
            }

            if ($amount < $requiredActivation) {
                header('Location: ../savings.html?error=activation_amount');
                exit;
            }

            // Mark activation as paid
            $pdo->beginTransaction();
            try {
                // Update member activation status
                $pdo->prepare('UPDATE members SET activation_fee_paid = TRUE, activation_fee_amount = :amt WHERE id = :id')
                    ->execute([':amt' => $requiredActivation, ':id' => $memberId]);

                // Update savings account activation fee balance
                $pdo->prepare('UPDATE savings_accounts SET activation_fee_balance = :amt WHERE member_id = :id')
                    ->execute([':amt' => $requiredActivation, ':id' => $memberId]);

                // Record activation fee transaction
                $ref = 'ACT-' . date('Ymd') . '-' . str_pad((string)$memberId, 4, '0', STR_PAD_LEFT);
                $pdo->prepare(
                    'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes, reference_number)
                     VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes, :ref)'
                )->execute([
                    ':member_id'     => $memberId,
                    ':type'          => 'activation_fee',
                    ':amount'        => $requiredActivation,
                    ':balance_after' => $requiredActivation,
                    ':staff_id'      => $_SESSION['staff_id'],
                    ':notes'         => 'Initial activation fee payment - non-withdrawable',
                    ':ref'           => $ref,
                ]);

                // Set initial balance to activation fee amount (but not available for withdrawal initially)
                $pdo->prepare('UPDATE savings_accounts SET balance = :amt WHERE member_id = :id')
                    ->execute([':amt' => $requiredActivation, ':id' => $memberId]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                header('Location: ../savings.html?error=' . urlencode($e->getMessage()));
                exit;
            }

            log_audit($pdo, $_SESSION['staff_id'], 'activation_fee_paid', "Activation fee of {$requiredActivation} UGX paid by member #$memberId");
            header('Location: ../savings.html?success=1&activation=1');
            exit;
        }

        // For regular deposits and withdrawals, check activation status
        if (!$activationPaid) {
            header('Location: ../savings.html?error=activation_required');
            exit;
        }

        // Validate amount based on action
        if ($action === 'deposit') {
            // Min deposit: 1,000 UGX
            if ($amount < 1000) {
                header('Location: ../savings.html?error=min_deposit');
                exit;
            }
        } elseif ($action === 'withdrawal') {
            // Min withdrawal: 5,000 UGX
            // Also check that balance after withdrawal doesn't go below activation fee remaining
            $stmtBal = $pdo->prepare('SELECT balance, activation_fee_balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
            $stmtBal->execute([':id' => $memberId]);
            $account = $stmtBal->fetch();

            $availableBalance = $account['balance'] - $account['activation_fee_balance'];

            if ($amount < 5000) {
                header('Location: ../savings.html?error=min_withdrawal');
                exit;
            }

            if ($amount > $availableBalance) {
                header('Location: ../savings.html?error=insufficient_balance');
                exit;
            }
        }

        // Proceed with transaction
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT balance, activation_fee_balance FROM savings_accounts WHERE member_id = :id FOR UPDATE');
            $stmt->execute([':id' => $memberId]);
            $account = $stmt->fetch();

            $newBalance = $action === 'deposit'
                ? $account['balance'] + $amount
                : $account['balance'] - $amount;

            // For withdrawals, ensure activation fee balance is preserved
            if ($action === 'withdrawal') {
                $newActivationBalance = $account['activation_fee_balance'];
                // If the withdrawal would go below the activation fee, adjust
                if ($newBalance < $account['activation_fee_balance']) {
                    $excess = $account['activation_fee_balance'] - $newBalance;
                    $newBalance = $account['activation_fee_balance'];
                    // Refund the excess (this shouldn't happen with proper validation, but just in case)
                    $newBalance = $account['balance'] - $excess;
                }
            }

            $pdo->prepare('UPDATE savings_accounts SET balance = :bal, activation_fee_balance = :act_bal WHERE member_id = :id')
                ->execute([':bal' => $newBalance, ':act_bal' => $account['activation_fee_balance'], ':id' => $memberId]);

            $pdo->prepare(
                'INSERT INTO savings_transactions (member_id, type, amount, balance_after, recorded_by, notes)
                 VALUES (:member_id, :type, :amount, :balance_after, :staff_id, :notes)'
            )->execute([
                ':member_id'     => $memberId,
                ':type'          => $action,
                ':amount'        => $amount,
                ':balance_after' => $newBalance,
                ':staff_id'      => $_SESSION['staff_id'],
                ':notes'         => $notes,
            ]);

            log_audit($pdo, $_SESSION['staff_id'], $action, "Member #$memberId, amount $amount");
            $pdo->commit();
            header('Location: ../savings.html?success=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: ../savings.html?error=' . urlencode($e->getMessage()));
        }
        exit;

    case 'list':
    default:
        $stmt = $pdo->query(
            'SELECT t.id, m.full_name, m.member_no, t.type, t.amount, t.balance_after, t.synced, t.created_at, t.reference_number
             FROM savings_transactions t
             JOIN members m ON m.id = t.member_id
             ORDER BY t.created_at DESC
             LIMIT 100'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}