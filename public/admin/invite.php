<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/preview_data.php';
require_once __DIR__ . '/../../src/csrf.php';
require_once __DIR__ . '/../../src/admin_layout.php';

csrf_ensure_session();

// Invitation workflow retired — accounts are created by a super admin in Staff.
header('Location: login.php');
exit;
