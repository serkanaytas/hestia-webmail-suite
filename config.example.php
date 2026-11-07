<?php
/**
 * Hestia Webmail & Kurumsal Portal Suite - Örnek Konfigürasyon Dosyası
 * Sample Configuration File (Copy to config.php or config.local.php)
 */

date_default_timezone_set('Europe/Istanbul');

// Database Settings (MySQL / MariaDB)
defined('DB_HOST') or define('DB_HOST', 'localhost');
defined('DB_USER') or define('DB_USER', 'root');
defined('DB_PASS') or define('DB_PASS', '');
defined('DB_NAME') or define('DB_NAME', 'hestia_webmail');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// Authorized Corporate Domain & Mail Server Settings
defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', 'example.com');

// IMAP (Incoming Mail)
defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', 'mail.' . MAIL_DOMAIN);
defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', 993);              // SSL: 993, STARTTLS: 143
defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', 'ssl'); // 'ssl', 'tls', 'none'

// SMTP (Outgoing Mail)
defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', 'mail.' . MAIL_DOMAIN);
defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', 587);              // STARTTLS: 587, SSL: 465
defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', 'tls'); // 'tls', 'ssl', 'none'

// HestiaCP Integration Settings ('remote' REST API or 'local' CLI)
defined('HESTIA_SERVER_MODE') or define('HESTIA_SERVER_MODE', 'remote');
defined('HESTIA_CLI_PATH') or define('HESTIA_CLI_PATH', '/usr/local/hestia/bin');
defined('HESTIA_USE_SUDO') or define('HESTIA_USE_SUDO', true);

defined('HESTIA_API_HOST') or define('HESTIA_API_HOST', 'https://mail.' . MAIL_DOMAIN . ':8083');
defined('HESTIA_API_USER') or define('HESTIA_API_USER', 'admin');
defined('HESTIA_API_KEY') or define('HESTIA_API_KEY', ''); // Format: ACCESS_KEY_ID:SECRET_KEY

