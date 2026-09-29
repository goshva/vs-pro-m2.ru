<?php
/**
 * Site-wide configuration. Native PHP, no framework.
 */
declare(strict_types=1);

// Switch to true only while debugging locally.
define('APP_DEBUG', false);

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

define('SITE_NAME', 'Владислав Сахнов');

// All web forms deliver here.
define('LEAD_RECIPIENT_EMAIL', 'Panorama-x@mail.ru');
define('CONTACT_PHONE', '+7 925 502-37-39');
define('CONTACT_PHONE_HREF', '+79255023739');
define('CONTACT_EMAIL', 'panorama-x@mail.ru');

// Envelope/From address for outgoing mail — must live on the site's own domain
// for best deliverability. Matches sendmail_path "-f" in the hosting's php.ini.
define('MAIL_FROM_ADDRESS', 'info@vs-pro-m2.ru');
define('MAIL_FROM_NAME', SITE_NAME);

// SMTP credentials (SMTP_HOST/PORT/USER/PASS) live in a git-ignored local file.
// Without it, send_lead_email() falls back to PHP's native mail().
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

define('SITE_ROOT', dirname(__DIR__));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
