<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';
tvr_secure_session_start(true);
tvr_security_headers(true);
