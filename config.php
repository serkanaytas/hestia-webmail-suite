<?php
/**
 * Hestia Webmail & Kurumsal Portal Suite - Merkezi Konfigürasyon Dosyası
 * Central Configuration File
 *
 * Domain, posta sunucusu (IMAP/SMTP), veritabanı ve HestiaCP entegrasyon
 * ayarlarını bu dosyadan veya Kurulum Sihirbazı (wizard.php) üzerinden yönetebilirsiniz.
 */

// -------------------------------------------------------------
// YEREL KONFİGÜRASYON (İsteğe Bağlı / Git tarafından yoksayılır)
// -------------------------------------------------------------
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// -------------------------------------------------------------
// ZAMAN DİLİMİ AYARI (GMT+3 / Türkiye)
// -------------------------------------------------------------
date_default_timezone_set('Europe/Istanbul');

// -------------------------------------------------------------
// VERİTABANI BAĞLANTI AYARLARI (MySQL / MariaDB)
// -------------------------------------------------------------
defined('DB_HOST') or define('DB_HOST', 'localhost');
defined('DB_USER') or define('DB_USER', 'root');
defined('DB_PASS') or define('DB_PASS', '');
defined('DB_NAME') or define('DB_NAME', 'hestia_webmail');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// -------------------------------------------------------------
// DOMAIN VE POSTA SUNUCUSU AYARLARI
// -------------------------------------------------------------
// Kullanıcı girişte sadece kullanıcı adı yazdığında (örn: 'kullanici')
// arkasına otomatik eklenecek yetkili e-posta domaini:
defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', 'example.com');

// IMAP Sunucusu (Gelen Posta)
defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', 'mail.' . MAIL_DOMAIN);
defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', 993);              // SSL: 993, STARTTLS: 143
defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', 'ssl'); // 'ssl', 'tls', 'none'

// SMTP Sunucusu (Giden Posta)
defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', 'mail.' . MAIL_DOMAIN);
defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', 587);              // STARTTLS: 587, SSL: 465
defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', 'tls'); // 'tls', 'ssl', 'none'

// -------------------------------------------------------------
// HESTIACP ÇALIŞMA MODU VE ENTEGRASYON AYARLARI
// -------------------------------------------------------------
// ÇALIŞMA MODU:
// 'remote' = Uzak Sunucu / REST API (Port 8083 & API Anahtarı ile cURL)
// 'local'  = Aynı Sunucu / Yerel Mod (Webmail HestiaCP ile aynı sunucuda ise /usr/local/hestia/bin/v-* CLI komutlarıyla doğrudan çalışır)
defined('HESTIA_SERVER_MODE') or define('HESTIA_SERVER_MODE', 'remote');
defined('HESTIA_CLI_PATH') or define('HESTIA_CLI_PATH', '/usr/local/hestia/bin');
defined('HESTIA_USE_SUDO') or define('HESTIA_USE_SUDO', true);

// HestiaCP REST API Ayarları (Uzak Sunucu / 'remote' modunda kullanılır):
// HestiaCP panelinizde 'Users -> admin -> Access Keys' bölümünden API anahtarı oluşturabilirsiniz:
defined('HESTIA_API_HOST') or define('HESTIA_API_HOST', 'https://mail.' . MAIL_DOMAIN . ':8083');
defined('HESTIA_API_USER') or define('HESTIA_API_USER', 'admin');
defined('HESTIA_API_KEY') or define('HESTIA_API_KEY', '');
