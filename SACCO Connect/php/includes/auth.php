<?php
/**
 * auth.php — session and role-guard helpers.
 * Include this after config.php on every protected page.
 */

/** Redirect to login if no staff user is signed in. */
function require_staff_login(): void
{
    if (empty($_SESSION['staff_id'])) {
        header('Location: ../login.html');
        exit;
    }
}

/** Redirect to member login if no member is signed in. */
function require_member_login(): void
{
    if (empty($_SESSION['member_id'])) {
        header('Location: ../login.html');
        exit;
    }
}

/** Restrict a page to specific staff roles, e.g. require_role(['administrator']); */
function require_role(array $allowedRoles): void
{
    require_staff_login();
    if (!in_array($_SESSION['staff_role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        die('You do not have permission to view this page.');
    }
}
