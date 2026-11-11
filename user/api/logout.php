<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

unset($_SESSION['member_user_id']);
member_json(['ok' => true]);
