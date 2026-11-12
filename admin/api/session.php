<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$accounts = admin_accounts_enabled();
$profile = null;
$authenticated = false;

if ($accounts) {
    $userId = admin_session_user_id();
    if ($userId !== null) {
        $row = admin_find_by_id($userId);
        if ($row !== null && (int) ($row['ativo'] ?? 0) === 1) {
            $authenticated = true;
            $profile = admin_public_profile($row);
        } else {
            unset($_SESSION['admin_user_id'], $_SESSION['admin_ok']);
        }
    }
} elseif (!admin_pin_required()) {
    $_SESSION['admin_ok'] = true;
    $authenticated = true;
} else {
    $authenticated = !empty($_SESSION['admin_ok']);
}

if (!$authenticated) {
    admin_json([
        'ok' => true,
        'authenticated' => false,
        'php' => true,
        'pinRequired' => !$accounts && admin_pin_required(),
        'accounts' => $accounts,
    ]);
}

admin_json([
    'ok' => true,
    'authenticated' => true,
    'php' => true,
    'pinRequired' => !$accounts && admin_pin_required(),
    'accounts' => $accounts,
    'authMode' => $accounts ? 'account' : (admin_pin_required() ? 'pin' : 'none'),
    'database' => admin_db_connected(),
    'useDatabase' => admin_use_database(),
    'profile' => $profile,
]);
