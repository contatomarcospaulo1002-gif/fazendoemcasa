<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

member_require_db();

$authenticated = member_session_id() !== null;
$profile = null;
if ($authenticated) {
    $row = member_find_by_id(member_session_id());
    if ($row === null || (int) ($row['ativo'] ?? 0) !== 1) {
        unset($_SESSION['member_user_id']);
        $authenticated = false;
    } else {
        $profile = member_public_profile($row);
    }
}

member_json([
    'ok' => true,
    'authenticated' => $authenticated,
    'php' => true,
    'database' => true,
    'profile' => $profile,
]);
