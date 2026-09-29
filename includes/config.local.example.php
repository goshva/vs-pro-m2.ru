<?php
/**
 * Copy to includes/config.local.php and fill in real values.
 * config.local.php is git-ignored and must be uploaded to the hosting by hand.
 * Without it the site falls back to PHP's native mail().
 */
declare(strict_types=1);

// Yandex: smtp.yandex.ru, SSL 465. Password must be an "app password" (Почтовые клиенты).
define('SMTP_HOST', 'ssl://smtp.yandex.ru');
define('SMTP_PORT', 465);
define('SMTP_USER', 'your-account@yandex.ru');
define('SMTP_PASS', 'app-password-here');
