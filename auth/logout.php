<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

auth_logout();
auth_flash('info', 'Sie wurden abgemeldet.');
auth_redirect('/auth/login.php');
