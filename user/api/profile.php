<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

member_require_db();
member_require_auth();

$row = member_find_by_id(member_session_id());
if ($row === null) {
    member_json(['ok' => false, 'error' => 'Utilizador não encontrado.'], 404);
}

member_json([
    'ok' => true,
    'profile' => member_public_profile($row),
]);
