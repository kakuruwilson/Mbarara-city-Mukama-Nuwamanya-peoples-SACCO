<?php
/**
 * loans.php — handles loan applications, approvals, and repayments
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_staff_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'apply':
        require_role(['administrator', 'manager', 'cashier']);

        $memberId = (int) ($_POST['member_id'] ?? 0);
        $principal = (float) ($_POST['principal'] ?? 0);
        $interestRate = (float) ($_POST['interest_rate'] ?? 10);
        $termMonths = (int) ($_POST['term_months'] ?? 6);
        $purpose = trim($_POST['purpose'] ?? '');

        if ($memberId <= 0 || $principal <= 0 || $principal < 10000) {
            header('Location: ../loans.html?error=invalid_input');
            exit;
        }

        if ($purpose === '') {
            header('Location: ../loans.html?error=missing_purpose');
            exit;
        }

        // Check member activation status
        $stmtMember = $pdo->prepare('SELECT activation_fee_paid FROM members WHERE id = :id');
        $stmtMember->execute([':id' => $memberId]);
        $member = $stmtMember->fetch();

        if (!$member || !$member['activation_fee_paid']) {
            header('Location: ../loans.html?error=activation_required');
            exit;
        }

        // Check if member already has active loans
        $stmtCheckLoans = $pdo->prepare(
            'SELECT COUNT(*) as active_count FROM loans WHERE member_id = :member_id AND status IN ("pending","approved","active")'
        );
        $stmtCheckLoans->execute([':member_id' => $memberId]);
        $check = $stmtCheckLoans->fetch();

        // Insert loan application
        $stmt = $pdo->prepare(
            'INSERT INTO loans (member_id, principal, interest_rate, term_months, purpose, status, applied_date)
             VALUES (:member_id, :principal, :interest_rate, :term_months, :purpose, "pending", CURDATE())'
        );
        $stmt->execute([
            ':member_id' => $memberId,
            ':principal' => $principal,
            ':interest_rate' => $interestRate,
            ':term_months' => $termMonths,
            ':purpose' => $purpose,
        ]);

        $loanId = $pdo->lastInsertId();

        // Send notification to admin about new loan application
        send_notification($pdo, $memberId, 'email',
            'New Loan Application',
            "Member $memberId ($member[full_name] or member_no) has applied for a loan of UGX " . number_format($principal, 0) . " with interest $interestRate% for $termMonths months. Purpose: $purpose. Status: Pending approval. Please review and approve/reject.",
            $_SESSION['staff_id']);

        // Also notify via system
        log_audit($pdo, $_SESSION['staff_id'], 'loan_application', "New loan application #$loanId for member #$memberId, amount $principal");

        header('Location: ../loans.html?success=1&apply=1');
        exit;

    case 'approve':
        require_role(['administrator', 'manager']);

        $loanId = (int) ($_POST['loan_id'] ?? 0);

        if ($loanId <= 0) {
            header('Location: ../loans.html?error=invalid_loan');
            exit;
        }

        // Get loan details
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id');
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            header('Location: ../loans.html?error=loan_not_found');
            exit;
        }

        if ($loan['status'] !== 'pending') {
            header('Location: ../loans.html?error=loan_not_pending');
            exit;
        }

        // Update loan status
        $pdo->prepare('UPDATE loans SET status = "approved", approved_date = NOW(), approved_by = :staff_id WHERE id = :id')
            ->execute([':staff_id' => $_SESSION['staff_id'], ':id' => $loanId]);

        // Send notification to member
        $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $memberStmt->execute([':id' => $loan['member_id']]);
        $member = $memberStmt->fetch();

        send_notification($pdo, $loan['member_id'], 'email',
            'Loan Approved',
            "Your loan application #$loanId for UGX " . number_format($loan['principal'], 0) . " has been approved. Interest rate: {$loan['interest_rate']}%. Term: {$loan['term_months']} months. Please contact admin for disbursement details.",
            $_SESSION['staff_id']);

        log_audit($pdo, $_SESSION['staff_id'], 'loan_approved', "Approved loan #$loanId for member #{$loan['member_id']}, amount {$loan['principal']}");

        header('Location: ../loans.html?success=1&approved=1');
        exit;

    case 'reject':
        require_role(['administrator', 'manager']);

        $loanId = (int) ($_POST['loan_id'] ?? 0);

        if ($loanId <= 0) {
            header('Location: ../loans.html?error=invalid_loan');
            exit;
        }

        // Get loan details
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id');
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            header('Location: ../loans.html?error=loan_not_found');
            exit;
        }

        if ($loan['status'] !== 'pending') {
            header('Location: ../loans.html?error=loan_not_pending');
            exit;
        }

        // Update loan status
        $pdo->prepare('UPDATE loans SET status = "rejected" WHERE id = :id')
            ->execute([':id' => $loanId]);

        // Send notification to member
        $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $memberStmt->execute([':id' => $loan['member_id']]);
        $member = $memberStmt->fetch();

        send_notification($pdo, $loan['member_id'], 'email',
            'Loan Application Update',
            "Your loan application #$loanId for UGX " . number_format($loan['principal'], 0) . " has been rejected. Reason: Under review. You may reapply after 30 days. Contact admin for clarification.",
            $_SESSION['staff_id']);

        log_audit($pdo, $_SESSION['staff_id'], 'loan_rejected', "Rejected loan #$loanId for member #{$loan['member_id']}");

        header('Location: ../loans.html?success=1&rejected=1');
        exit;

    case 'repay':
        require_role(['administrator', 'manager', 'cashier']);

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($loanId <= 0 || $amount <= 0) {
            header('Location: ../loans.html?error=invalid_input');
            exit;
        }

        // Get loan details
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id');
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            header('Location: ../loans.html?error=loan_not_found');
            exit;
        }

        // Calculate repayment
        $outstanding = $loan['outstanding_balance'] ?? $loan['principal'];
        $newOutstanding = max(0, $outstanding - $amount);
        $newStatus = $newOutstanding <= 0 ? 'completed' : $loan['status'];

        // Update loan outstanding balance
        $pdo->prepare('UPDATE loans SET outstanding_balance = :new_outstanding, status = :new_status WHERE id = :id')
            ->execute([':new_outstanding' => $newOutstanding, ':new_status' => $newStatus, ':id' => $loanId]);

        // Record repayment
        $pdo->prepare(
            'INSERT INTO loan_repayments (loan_id, amount, balance_after, recorded_by, synced)
             VALUES (:loan_id, :amount, :new_outstanding, :staff_id, 1)'
        )->execute([
            ':loan_id' => $loanId,
            ':amount' => $amount,
            ':new_outstanding' => $newOutstanding,
            ':staff_id' => $_SESSION['staff_id'],
        ]);

        // Send notification to member
        $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = :id');
        $memberStmt->execute([':id' => $loan['member_id']]);
        $member = $memberStmt->fetch();

        $repayMessage = "Repayment of UGX " . number_format($amount, 0) . " received for loan #$loanId. Outstanding balance: UGX " . number_format($newOutstanding, 2) . ". Status: " . ($newStatus === 'completed' ? 'Loan Completed' : 'Active');

        send_notification($pdo, $loan['member_id'], 'email',
            'Loan Repayment',
            $repayMessage,
            $_SESSION['staff_id']);

        log_audit($pdo, $_SESSION['staff_id'], 'loan_repayment', "Repayment of $amount UGX for loan #$loanId by member #{$loan['member_id']}");

        header('Location: ../loans.html?success=1&repaid=1');
        exit;

    case 'list':
    default:
        // Get all loans with member info
        $stmt = $pdo->query(
            'SELECT l.id, l.principal, l.interest_rate, l.term_months, l.status, l.purpose,
                    l.applied_date, l.approved_date, l.outstanding_balance,
                    m.member_no, m.full_name, m.phone
             FROM loans l
             JOIN members m ON m.id = l.member_id
             ORDER BY l.applied_date DESC'
        );
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll());
        exit;
}