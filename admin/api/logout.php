<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

unset($_SESSION['admin_user_id'], $_SESSION['admin_ok']);
admin_json(['ok' => true]);
