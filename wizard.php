<?php
/**
 * Hestia Webmail Suite - İlk Kurulum & Domain Kilitleme Sihirbazı (Setup Wizard)
 * 
 * Sistem gereksinimlerini denetler, veritabanını kurar, yetkili kurumsal domaini belirler
 * ve sistemi yalnızca bu domaine hizmet verecek şekilde kilitler.
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
session_start();

$config_file = __DIR__ . '/config.php';
$has_config = file_exists($config_file);

// Load current configuration if exists
$current_db_host = 'localhost';
$current_db_user = 'root';
$current_db_pass = '';
$current_db_name = 'hestia_webmail';
$current_domain = 'example.com';
$current_imap_host = 'mail.example.com';
$current_imap_port = '993';
$current_imap_enc = 'ssl';
$current_smtp_host = 'mail.example.com';
$current_smtp_port = '587';
$current_smtp_enc = 'tls';
$current_hestia_host = '';
$current_hestia_key = '';

if ($has_config) {
    require_once $config_file;
    if (defined('DB_HOST')) $current_db_host = DB_HOST;
    if (defined('DB_USER')) $current_db_user = DB_USER;
    if (defined('DB_PASS')) $current_db_pass = DB_PASS;
    if (defined('DB_NAME')) $current_db_name = DB_NAME;
    if (defined('MAIL_DOMAIN')) $current_domain = MAIL_DOMAIN;
    if (defined('MAIL_IMAP_HOST')) $current_imap_host = MAIL_IMAP_HOST;
    if (defined('MAIL_IMAP_PORT')) $current_imap_port = MAIL_IMAP_PORT;
    if (defined('MAIL_IMAP_ENCRYPTION')) $current_imap_enc = MAIL_IMAP_ENCRYPTION;
    if (defined('MAIL_SMTP_HOST')) $current_smtp_host = MAIL_SMTP_HOST;
    if (defined('MAIL_SMTP_PORT')) $current_smtp_port = MAIL_SMTP_PORT;
    if (defined('MAIL_SMTP_ENCRYPTION')) $current_smtp_enc = MAIL_SMTP_ENCRYPTION;
    if (defined('HESTIA_API_HOST')) $current_hestia_host = HESTIA_API_HOST;
    if (defined('HESTIA_API_KEY')) $current_hestia_key = HESTIA_API_KEY;
}

// Check if existing config can actually connect to DB
$db_connected = false;
$db_connect_error = '';
$is_wizard_admin = false;
$has_any_accounts = false;
if ($has_config && defined('DB_HOST') && defined('DB_NAME')) {
    try {
        $test_conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        $db_connected = true;
        try {
            $accCount = (int)$test_conn->query("SELECT COUNT(*) FROM mail_accounts")->fetchColumn();
            $has_any_accounts = ($accCount > 0);
            if (!empty($_SESSION['mail_logged_in']) && !empty($_SESSION['active_account_id'])) {
                $stAdm = $test_conn->prepare("SELECT is_admin, role FROM mail_accounts WHERE id = ?");
                $stAdm->execute([(int)$_SESSION['active_account_id']]);
                $admRow = $stAdm->fetch(PDO::FETCH_OBJ);
                if ($admRow && (!empty($admRow->is_admin) || ($admRow->role ?? '') === 'admin')) {
                    $is_wizard_admin = true;
                }
            }
        } catch (Exception $ex) {}
    } catch (Exception $e) {
        $db_connected = false;
        $db_connect_error = $e->getMessage();
    }
}

// Never expose existing DB password or Hestia API key in HTML to unauthenticated users
if (!$is_wizard_admin) {
    $current_db_pass = '';
    $current_hestia_key = '';
}

// Kurulum daha önce tamamlanmış VE veritabanına sorunsuz bağlanıyorsa:
// Sadece yetkili Admin oturumu varsa (veya henüz hiç hesap yoksa) yeniden yapılandırmaya izin ver
$force_wizard = isset($_GET['reconfig']) || isset($_GET['manual']) || (isset($_GET['action']) && $_GET['action'] === 'manual');
$is_already_installed = ($has_config && defined('MAIL_DOMAIN') && !empty(MAIL_DOMAIN) && defined('DB_NAME') && !empty(DB_NAME) && $db_connected);

if ($is_already_installed && $has_any_accounts && !$is_wizard_admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Güvenlik Engeli: Sistem zaten kurulu. Yeniden yapılandırma için Sistem Yöneticisi (Admin) olarak giriş yapmalısınız.']);
        exit;
    }
    header("Location: index.php");
    exit;
}

if ($is_already_installed && !$force_wizard && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// -------------------------------------------------------------
// AJAX ENDPOINTS FOR WIZARD
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    // 1. TEST DATABASE CONNECTION
    if ($action === 'test_db' || $action === 'test_db_manual') {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $user = trim($_POST['db_user'] ?? 'root');
        $pass = $_POST['db_pass'] ?? '';
        $name = trim($_POST['db_name'] ?? '');

        try {
            $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // Check if database exists, create if not (wrap in try/catch for hosts where user has no CREATE DATABASE privilege)
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $ce) {}
            
            $pdo->exec("USE `{$name}`");

            echo json_encode(['success' => true, 'message' => "Veritabanı bağlantısı başarılı! '{$name}' veritabanı hazır."]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. TEST IMAP / POSTA SUNUCUSU
    if ($action === 'test_imap') {
        $domain = trim($_POST['domain'] ?? '');
        $imap_host = trim($_POST['imap_host'] ?? '');
        $imap_port = (int)($_POST['imap_port'] ?? 993);

        if (empty($imap_host)) {
            $imap_host = 'mail.' . $domain;
        }

        $fp = @fsockopen($imap_host, $imap_port, $errno, $errstr, 5);
        if ($fp) {
            fclose($fp);
            echo json_encode(['success' => true, 'message' => "IMAP sunucusuna ({$imap_host}:{$imap_port}) ağ erişimi başarılı!"]);
        } else {
            echo json_encode(['success' => false, 'message' => "IMAP sunucusuna erişilemedi: ({$errno}) {$errstr}. Port ve hostu kontrol edin."]);
        }
        exit;
    }

    // 3. COMPLETE SETUP & SAVE CONFIG
    if ($action === 'save_setup') {
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_user = trim($_POST['db_user'] ?? 'root');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_name = trim($_POST['db_name'] ?? 'hestia_webmail');

        $domain = strtolower(trim($_POST['domain'] ?? ''));
        // Clean domain if user typed @domain or http://
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        $domain = ltrim($domain, '@');
        $domain = trim($domain, '/');

        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => 'Yetkili Kurumsal Alan Adı (Domain) boş bırakılamaz!']);
            exit;
        }

        $imap_host = trim($_POST['imap_host'] ?? '') ?: ('mail.' . $domain);
        $imap_port = (int)($_POST['imap_port'] ?? 993);
        $imap_enc = trim($_POST['imap_enc'] ?? 'ssl');

        $smtp_host = trim($_POST['smtp_host'] ?? '') ?: ('mail.' . $domain);
        $smtp_port = (int)($_POST['smtp_port'] ?? 587);
        $smtp_enc = trim($_POST['smtp_enc'] ?? 'tls');

        $hestia_host = trim($_POST['hestia_host'] ?? '');
        $hestia_user = trim($_POST['hestia_user'] ?? 'admin');
        $hestia_key = trim($_POST['hestia_key'] ?? '');

        // 1. Connect and initialize database tables
        try {
            $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");

            // Create tables if not exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `mail_accounts` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(150) NOT NULL,
                  `email` varchar(150) NOT NULL,
                  `imap_host` varchar(150) NOT NULL,
                  `imap_port` int(11) NOT NULL DEFAULT 993,
                  `imap_encryption` varchar(10) NOT NULL DEFAULT 'ssl',
                  `imap_username` varchar(150) NOT NULL,
                  `imap_password` varchar(255) NOT NULL,
                  `smtp_host` varchar(150) NOT NULL,
                  `smtp_port` int(11) NOT NULL DEFAULT 587,
                  `smtp_encryption` varchar(10) NOT NULL DEFAULT 'tls',
                  `smtp_username` varchar(150) NOT NULL,
                  `smtp_password` varchar(255) NOT NULL,
                  `signature` text DEFAULT NULL,
                  `is_default` tinyint(1) NOT NULL DEFAULT 0,
                  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
                  `role` varchar(20) NOT NULL DEFAULT 'user',
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `contacts` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `first_name` varchar(100) NOT NULL,
                  `last_name` varchar(100) DEFAULT NULL,
                  `email` varchar(150) NOT NULL,
                  `phone` varchar(50) DEFAULT NULL,
                  `company` varchar(150) DEFAULT NULL,
                  `job_title` varchar(100) DEFAULT NULL,
                  `group_name` varchar(50) DEFAULT 'Genel',
                  `notes` text DEFAULT NULL,
                  `avatar_color` varchar(20) DEFAULT '#4f46e5',
                  `is_favorite` tinyint(1) DEFAULT 0,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `calendar_events` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `domain` varchar(100) DEFAULT NULL,
                  `is_shared` tinyint(1) DEFAULT 1,
                  `creator_name` varchar(150) DEFAULT NULL,
                  `creator_email` varchar(150) DEFAULT NULL,
                  `title` varchar(200) NOT NULL,
                  `description` text DEFAULT NULL,
                  `location` varchar(255) DEFAULT NULL,
                  `start_date` datetime NOT NULL,
                  `end_date` datetime NOT NULL,
                  `all_day` tinyint(1) DEFAULT 0,
                  `color` varchar(20) DEFAULT '#4f46e5',
                  `category` varchar(50) DEFAULT 'Toplantı',
                  `status` varchar(20) DEFAULT 'confirmed',
                  `reminder_minutes` int(11) DEFAULT 15,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`),
                  KEY `idx_domain_shared` (`domain`, `is_shared`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `mail_filters` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) DEFAULT 0,
                  `filter_name` varchar(150) NOT NULL DEFAULT '',
                  `match_field` varchar(50) NOT NULL DEFAULT 'from',
                  `match_type` varchar(50) NOT NULL DEFAULT 'contains',
                  `match_value` varchar(255) NOT NULL DEFAULT '',
                  `action_type` varchar(50) NOT NULL DEFAULT 'move',
                  `action_target` varchar(255) DEFAULT NULL,
                  `is_active` tinyint(1) DEFAULT 1,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `mail_folders` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `folder_name` varchar(100) NOT NULL,
                  `display_name` varchar(100) NOT NULL,
                  `parent_folder` varchar(100) DEFAULT NULL,
                  `icon` varchar(50) DEFAULT 'folder',
                  `color` varchar(20) DEFAULT '#64748b',
                  `sort_order` int(11) DEFAULT 0,
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `mail_settings` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `setting_key` varchar(100) NOT NULL,
                  `setting_value` text DEFAULT NULL,
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `setting_key` (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `mail_drafts` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `to_address` text DEFAULT NULL,
                  `cc_address` text DEFAULT NULL,
                  `bcc_address` text DEFAULT NULL,
                  `subject` varchar(255) DEFAULT NULL,
                  `body` longtext DEFAULT NULL,
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `drive_folders` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `parent_id` int(11) DEFAULT 0,
                  `name` varchar(255) NOT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_acc_parent` (`account_id`, `parent_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `drive_files` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `folder_id` int(11) DEFAULT 0,
                  `original_name` varchar(255) NOT NULL,
                  `stored_name` varchar(255) NOT NULL,
                  `file_size` bigint(20) DEFAULT 0,
                  `mime_type` varchar(100) DEFAULT 'application/octet-stream',
                  `extension` varchar(20) DEFAULT '',
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_acc_folder` (`account_id`, `folder_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `user_tasks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL DEFAULT 0,
                  `title` varchar(255) NOT NULL DEFAULT '',
                  `description` text DEFAULT NULL,
                  `due_date` date DEFAULT NULL,
                  `priority` varchar(20) DEFAULT 'medium',
                  `is_completed` tinyint(1) DEFAULT 0,
                  `completed_at` datetime DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`),
                  KEY `idx_task_comp` (`is_completed`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `user_notes` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `title` varchar(255) NOT NULL,
                  `content` longtext DEFAULT NULL,
                  `color` varchar(20) DEFAULT '#fef08a',
                  `is_pinned` tinyint(1) DEFAULT 0,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `account_id` (`account_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS `mail_imports` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `account_id` int(11) NOT NULL,
                  `source_email` varchar(255) NOT NULL,
                  `source_password` text DEFAULT NULL,
                  `source_host` varchar(255) NOT NULL,
                  `source_port` int(11) DEFAULT 993,
                  `source_encryption` varchar(20) DEFAULT 'ssl',
                  `source_folder` varchar(255) DEFAULT 'ALL',
                  `target_mode` varchar(20) DEFAULT 'same',
                  `custom_target_folder` varchar(255) DEFAULT 'Eski_Postalar',
                  `date_filter` varchar(50) DEFAULT 'all',
                  `limit_count` int(11) DEFAULT 0,
                  `skip_duplicates` tinyint(4) DEFAULT 1,
                  `target_folder` varchar(255) DEFAULT 'INBOX',
                  `current_folder` varchar(255) DEFAULT NULL,
                  `progress_percent` int(11) DEFAULT 0,
                  `pid` int(11) DEFAULT NULL,
                  `imported_count` int(11) DEFAULT 0,
                  `total_found` int(11) DEFAULT 0,
                  `skipped_count` int(11) DEFAULT 0,
                  `status` varchar(50) DEFAULT 'pending',
                  `error_message` text DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  `completed_at` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `idx_acc_status` (`account_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure calendar_events domain column exists
            $cols = $pdo->query("DESCRIBE calendar_events")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('domain', $cols)) {
                $pdo->exec("ALTER TABLE calendar_events ADD COLUMN domain VARCHAR(100) DEFAULT NULL AFTER account_id");
            }
            if (!in_array('is_shared', $cols)) {
                $pdo->exec("ALTER TABLE calendar_events ADD COLUMN is_shared TINYINT(1) DEFAULT 1 AFTER domain");
            }
            if (!in_array('creator_name', $cols)) {
                $pdo->exec("ALTER TABLE calendar_events ADD COLUMN creator_name VARCHAR(150) DEFAULT NULL AFTER is_shared");
            }
            if (!in_array('creator_email', $cols)) {
                $pdo->exec("ALTER TABLE calendar_events ADD COLUMN creator_email VARCHAR(150) DEFAULT NULL AFTER creator_name");
            }

            // Ensure mail_accounts admin columns exist
            $colsAcc = $pdo->query("DESCRIBE mail_accounts")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_admin', $colsAcc)) {
                $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default");
            }
            if (!in_array('role', $colsAcc)) {
                $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER is_admin");
            }

            // Create or promote initial admin account if provided
            $admin_user = trim($_POST['admin_user'] ?? '');
            $admin_pass = $_POST['admin_pass'] ?? '';
            $admin_name = trim($_POST['admin_name'] ?? '') ?: 'Sistem Yöneticisi';

            if (!empty($admin_user) && !empty($admin_pass)) {
                $admin_email = (strpos($admin_user, '@') === false) ? ($admin_user . '@' . $domain) : $admin_user;
                $chkAdmin = $pdo->prepare("SELECT id FROM mail_accounts WHERE email = ?");
                $chkAdmin->execute([$admin_email]);
                $existsAdmin = $chkAdmin->fetch();
                if ($existsAdmin) {
                    $updAdmin = $pdo->prepare("UPDATE mail_accounts SET is_admin = 1, role = 'admin', is_default = 1, name = ?, imap_password = ?, smtp_password = ? WHERE id = ?");
                    $updAdmin->execute([$admin_name, $admin_pass, $admin_pass, $existsAdmin->id]);
                } else {
                    $insAdmin = $pdo->prepare("INSERT INTO mail_accounts (name, email, imap_host, imap_port, imap_encryption, imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, is_default, is_admin, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'admin')");
                    $insAdmin->execute([$admin_name, $admin_email, $imap_host, $imap_port, $imap_enc, $admin_email, $admin_pass, $smtp_host, $smtp_port, $smtp_enc, $admin_email, $admin_pass]);
                }
            } else {
                // If no admin user specified, ensure at least one admin exists if accounts are present
                $adminCount = $pdo->query("SELECT COUNT(*) FROM mail_accounts WHERE is_admin = 1")->fetchColumn();
                if ($adminCount == 0) {
                    $firstId = $pdo->query("SELECT id FROM mail_accounts ORDER BY is_default DESC, id ASC LIMIT 1")->fetchColumn();
                    if ($firstId) {
                        $pdo->prepare("UPDATE mail_accounts SET is_admin = 1, role = 'admin' WHERE id = ?")->execute([$firstId]);
                    }
                }
            }

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Veritabanı yapılandırma hatası: ' . $e->getMessage()]);
            exit;
        }

        // 2. Generate and write config.php
        $esc_db_host = addslashes($db_host);
        $esc_db_user = addslashes($db_user);
        $esc_db_pass = addslashes($db_pass);
        $esc_db_name = addslashes($db_name);
        $esc_domain = addslashes($domain);
        $esc_imap_host = addslashes($imap_host);
        $esc_smtp_host = addslashes($smtp_host);
        $esc_hestia_host = addslashes($hestia_host);
        $esc_hestia_user = addslashes($hestia_user);
        $esc_hestia_key = addslashes($hestia_key);

        $config_code = <<<PHP
<?php
/**
 * Hestia Webmail Suite - Merkezi Konfigürasyon Dosyası
 * 
 * Kurulum Sihirbazı tarafından oluşturulmuştur.
 * Bu sistem yalnızca yetkilendirilen tek bir kurumsal domaine hizmet vermek üzere kilitlenmiştir.
 */

// -------------------------------------------------------------
// VERİTABANI BAĞLANTI AYARLARI
// -------------------------------------------------------------
defined('DB_HOST') or define('DB_HOST', '{$esc_db_host}');
defined('DB_USER') or define('DB_USER', '{$esc_db_user}');
defined('DB_PASS') or define('DB_PASS', '{$esc_db_pass}');
defined('DB_NAME') or define('DB_NAME', '{$esc_db_name}');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// -------------------------------------------------------------
// YETKİLİ KURUMSAL ALAN ADI (TEK DOMAIN GÜVENLİK KİLİDİ)
// -------------------------------------------------------------
// Sistem SADECE bu domaine hizmet eder. Başka hiçbir domain ile giriş yapılamaz!
defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', '{$esc_domain}');
defined('STRICT_DOMAIN_LOCK') or define('STRICT_DOMAIN_LOCK', true);

// IMAP Sunucusu (Gelen Posta)
defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', '{$esc_imap_host}');
defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', {$imap_port});              // SSL: 993, STARTTLS: 143
defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', '{$imap_enc}'); // 'ssl', 'tls', 'none'

// SMTP Sunucusu (Giden Posta)
defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', '{$esc_smtp_host}');
defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', {$smtp_port});              // STARTTLS: 587, SSL: 465
defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', '{$smtp_enc}'); // 'tls', 'ssl', 'none'

// -------------------------------------------------------------
// HESTIACP API AYARLARI (İsteğe Bağlı)
// -------------------------------------------------------------
defined('HESTIA_API_HOST') or define('HESTIA_API_HOST', '{$esc_hestia_host}');
defined('HESTIA_API_USER') or define('HESTIA_API_USER', '{$esc_hestia_user}');
defined('HESTIA_API_KEY') or define('HESTIA_API_KEY', '{$esc_hestia_key}');

PHP;

        if (@file_put_contents($config_file, $config_code) === false) {
            echo json_encode([
                'success' => false, 
                'message' => 'config.php dosyasına yazılamadı. Lütfen dosya yazma yetkilerini (permissions) kontrol edin.'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => "Kurulum başarıyla tamamlandı! Sistem yalnızca '@{$domain}' alan adına hizmet vermek üzere kilitlendi.",
            'domain' => $domain
        ]);
        exit;
    }

    // 4. MANUAL CONFIG SAVE
    if ($action === 'save_manual_config') {
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_user = trim($_POST['db_user'] ?? 'root');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_name = trim($_POST['db_name'] ?? '');
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        $domain = ltrim($domain, '@');
        $domain = trim($domain, '/');

        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen kurumsal alan adını (Domain) belirtiniz.']);
            exit;
        }

        $imap_host = trim($_POST['imap_host'] ?? '') ?: ('mail.' . $domain);
        $imap_port = (int)($_POST['imap_port'] ?? 993);
        $imap_enc = trim($_POST['imap_enc'] ?? 'ssl');

        $smtp_host = trim($_POST['smtp_host'] ?? '') ?: ('mail.' . $domain);
        $smtp_port = (int)($_POST['smtp_port'] ?? 587);
        $smtp_enc = trim($_POST['smtp_enc'] ?? 'tls');

        $hestia_host = trim($_POST['hestia_host'] ?? '');
        $hestia_user = trim($_POST['hestia_user'] ?? 'admin');
        $hestia_key = trim($_POST['hestia_key'] ?? '');

        $esc_db_host = addslashes($db_host);
        $esc_db_user = addslashes($db_user);
        $esc_db_pass = addslashes($db_pass);
        $esc_db_name = addslashes($db_name);
        $esc_domain = addslashes($domain);
        $esc_imap_host = addslashes($imap_host);
        $esc_smtp_host = addslashes($smtp_host);
        $esc_hestia_host = addslashes($hestia_host);
        $esc_hestia_user = addslashes($hestia_user);
        $esc_hestia_key = addslashes($hestia_key);

        $config_code = <<<PHP
<?php
/**
 * Hestia Webmail Suite - Merkezi Konfigürasyon Dosyası
 * 
 * Manuel Config Oluşturucu tarafından oluşturulmuştur.
 * Bu sistem yalnızca yetkilendirilen kurumsal domaine hizmet verir.
 */

// -------------------------------------------------------------
// VERİTABANI BAĞLANTI AYARLARI
// -------------------------------------------------------------
defined('DB_HOST') or define('DB_HOST', '{$esc_db_host}');
defined('DB_USER') or define('DB_USER', '{$esc_db_user}');
defined('DB_PASS') or define('DB_PASS', '{$esc_db_pass}');
defined('DB_NAME') or define('DB_NAME', '{$esc_db_name}');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// -------------------------------------------------------------
// YETKİLİ KURUMSAL ALAN ADI (TEK DOMAIN GÜVENLİK KİLİDİ)
// -------------------------------------------------------------
defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', '{$esc_domain}');
defined('STRICT_DOMAIN_LOCK') or define('STRICT_DOMAIN_LOCK', true);

// IMAP Sunucusu (Gelen Posta)
defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', '{$esc_imap_host}');
defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', {$imap_port});
defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', '{$imap_enc}');

// SMTP Sunucusu (Giden Posta)
defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', '{$esc_smtp_host}');
defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', {$smtp_port});
defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', '{$smtp_enc}');

// -------------------------------------------------------------
// HESTIACP API AYARLARI (İsteğe Bağlı)
// -------------------------------------------------------------
defined('HESTIA_API_HOST') or define('HESTIA_API_HOST', '{$esc_hestia_host}');
defined('HESTIA_API_USER') or define('HESTIA_API_USER', '{$esc_hestia_user}');
defined('HESTIA_API_KEY') or define('HESTIA_API_KEY', '{$esc_hestia_key}');

PHP;

        if (@file_put_contents($config_file, $config_code) === false) {
            echo json_encode([
                'success' => false,
                'message' => 'Sunucu dosya yazma yetkisi (permissions) nedeniyle config.php dosyasına otomatik yazılamadı. Lütfen "config.php Olarak İndir" butonunu kullanarak dosyayı manuel yükleyin.',
                'config_code' => $config_code
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'config.php dosyası sunucuda başarıyla kaydedildi!',
            'config_code' => $config_code
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
    exit;
}

// Check system requirements
$php_version = phpversion();
$php_ok = version_compare($php_version, '7.4.0', '>=');
$ext_imap = extension_loaded('imap');
$ext_openssl = extension_loaded('openssl');
$ext_pdo = extension_loaded('pdo_mysql');
$ext_mbstring = extension_loaded('mbstring');
$writable_config = is_writable($config_file) || is_writable(__DIR__);
$all_sys_ok = ($php_ok && $ext_imap && $ext_openssl && $ext_pdo && $writable_config);
?>
<!DOCTYPE html>
<html lang="tr" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hestia Webmail Suite - İlk Kurulum Sihirbazı</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=1">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png?v=1">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png?v=1">
  <link rel="shortcut icon" href="favicon.ico?v=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --bg-app: #0f172a;
      --bg-surface: #1e293b;
      --bg-surface-elevated: #334155;
      --bg-hover: #2d3748;
      --border-color: #334155;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --primary: #4f46e5;
      --primary-hover: #4338ca;
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      border-radius: 0 !important;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    body {
      background-color: var(--bg-app);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .wizard-container {
      width: 100%;
      max-width: 820px;
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
    }
    .wizard-header {
      padding: 1.75rem 2rem;
      border-bottom: 1px solid var(--border-color);
      background: rgba(15, 23, 42, 0.4);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .wizard-steps {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      border-bottom: 1px solid var(--border-color);
      background: rgba(15, 23, 42, 0.2);
    }
    .step-item {
      padding: 1rem 0.75rem;
      text-align: center;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--text-muted);
      border-right: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    .step-item:last-child {
      border-right: none;
    }
    .step-item.active {
      color: #fff;
      background: rgba(79, 70, 229, 0.15);
      border-bottom: 2px solid var(--primary);
    }
    .step-item.completed {
      color: var(--success);
    }
    .step-num {
      width: 22px;
      height: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      background: var(--border-color);
      color: var(--text-main);
    }
    .step-item.active .step-num {
      background: var(--primary);
      color: #fff;
    }
    .step-item.completed .step-num {
      background: var(--success);
      color: #fff;
    }
    .wizard-body {
      padding: 2rem;
    }
    .wizard-footer {
      padding: 1.25rem 2rem;
      border-top: 1px solid var(--border-color);
      background: rgba(15, 23, 42, 0.4);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .form-group {
      margin-bottom: 1.25rem;
    }
    .form-label {
      display: block;
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 0.4rem;
      color: var(--text-main);
    }
    .form-control {
      width: 100%;
      height: 42px;
      padding: 0 0.85rem;
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      font-size: 0.875rem;
      outline: none;
      transition: border-color 0.2s;
    }
    .form-control:focus {
      border-color: var(--primary);
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      height: 42px;
      padding: 0 1.25rem;
      font-size: 0.875rem;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.2s;
    }
    .btn-primary {
      background: var(--primary);
      color: #fff;
    }
    .btn-primary:hover {
      background: var(--primary-hover);
    }
    .btn-secondary {
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-color);
      color: var(--text-main);
    }
    .btn-secondary:hover {
      background: var(--bg-hover);
    }
    .btn-success {
      background: var(--success);
      color: #fff;
    }
    .check-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.85rem 1rem;
      background: var(--bg-surface-elevated);
      border: 1px solid var(--border-color);
      margin-bottom: 0.6rem;
      font-size: 0.875rem;
    }
    .badge-ok {
      background: rgba(16, 185, 129, 0.2);
      color: var(--success);
      padding: 0.2rem 0.6rem;
      font-size: 0.75rem;
      font-weight: 700;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .badge-err {
      background: rgba(239, 68, 68, 0.2);
      color: var(--danger);
      padding: 0.2rem 0.6rem;
      font-size: 0.75rem;
      font-weight: 700;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }
    .domain-lock-banner {
      background: rgba(79, 70, 229, 0.12);
      border: 1px solid rgba(79, 70, 229, 0.3);
      border-left: 4px solid var(--primary);
      padding: 1.25rem;
      margin-bottom: 1.5rem;
    }
  </style>
</head>
<body>

<div class="wizard-container">
  <!-- HEADER -->
  <div class="wizard-header">
    <div style="display:flex;align-items:center;gap:0.75rem;">
      <div style="width:38px;height:38px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:#fff;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
      <div>
        <h1 style="font-size:1.15rem;font-weight:700;">Hestia Webmail Suite</h1>
        <div style="font-size:0.775rem;color:var(--text-muted);">Kurulum &amp; Kurumsal Domain Kilitleme Sihirbazı</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.6rem;">
      <button type="button" class="btn btn-secondary" onclick="openManualConfigModal()" style="height:34px;padding:0 0.85rem;font-size:0.775rem;border-color:rgba(245, 158, 11, 0.4);color:#f59e0b;background:rgba(245, 158, 11, 0.08);display:inline-flex;align-items:center;gap:0.4rem;" title="Hosting bilgileriyle config.php dosyasını elle oluştur veya indir">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        <span>🛠️ Manuel Config Formu</span>
      </button>
      <span style="font-size:0.75rem;background:var(--bg-surface-elevated);padding:0.35rem 0.65rem;border:1px solid var(--border-color);color:var(--text-muted);">
        v2.4 Production
      </span>
    </div>
  </div>

  <?php if (!empty($db_connect_error)): ?>
  <div style="background:rgba(239, 68, 68, 0.12);border-bottom:1px solid rgba(239, 68, 68, 0.3);border-left:4px solid var(--danger);padding:0.9rem 1.5rem;font-size:0.825rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <div>
      <strong style="color:var(--danger);display:block;font-size:0.875rem;margin-bottom:0.2rem;">⚠️ Hosting / Veritabanı Bağlantı Hatası:</strong>
      <span style="color:var(--text-main);">Mevcut <code>config.php</code> bilgileriyle MySQL'e bağlanılamadı: <code><?= htmlspecialchars($db_connect_error) ?></code></span>
    </div>
    <button type="button" class="btn btn-secondary" onclick="openManualConfigModal()" style="height:32px;padding:0 0.85rem;font-size:0.775rem;background:var(--warning);border-color:var(--warning);color:#000;font-weight:700;">
      🛠️ Manuel Config Formu ile Düzelt
    </button>
  </div>
  <?php endif; ?>

  <!-- STEPS PROGRESS -->
  <div class="wizard-steps">
    <div class="step-item active" id="step-nav-1"><span class="step-num">1</span> <span>Sistem Kontrolü</span></div>
    <div class="step-item" id="step-nav-2"><span class="step-num">2</span> <span>Veritabanı</span></div>
    <div class="step-item" id="step-nav-3"><span class="step-num">3</span> <span>Domain Kilidi</span></div>
    <div class="step-item" id="step-nav-4"><span class="step-num">4</span> <span>Tamamlandı</span></div>
  </div>

  <!-- STEP 1: SYSTEM REQUIREMENTS -->
  <div class="wizard-body" id="step-content-1">
    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;">Sistem &amp; Güvenlik Gereksinimleri</h2>
    <p style="font-size:0.825rem;color:var(--text-muted);margin-bottom:1.5rem;">
      Hestia Webmail Suite'in sorunsuz, güvenli ve izole çalışabilmesi için sunucu gereksinimleri kontrol edilmektedir.
    </p>

    <div class="check-row">
      <div>
        <strong>PHP Sürümü:</strong> <?= $php_version ?> (Gerekli: >= 7.4)
      </div>
      <span class="<?= $php_ok ? 'badge-ok' : 'badge-err' ?>"><?= $php_ok ? '✓ UYGUN' : '✗ YETERSİZ' ?></span>
    </div>

    <div class="check-row">
      <div>
        <strong>PHP IMAP Eklentisi (php-imap):</strong> E-posta okuma ve IMAP SSL senkronizasyonu
      </div>
      <span class="<?= $ext_imap ? 'badge-ok' : 'badge-err' ?>"><?= $ext_imap ? '✓ AKTİF' : '✗ EKSİK' ?></span>
    </div>

    <div class="check-row">
      <div>
        <strong>OpenSSL Eklentisi:</strong> SSL/TLS 993 &amp; 587 şifreli bağlantı güvenliği
      </div>
      <span class="<?= $ext_openssl ? 'badge-ok' : 'badge-err' ?>"><?= $ext_openssl ? '✓ AKTİF' : '✗ EKSİK' ?></span>
    </div>

    <div class="check-row">
      <div>
        <strong>PDO MySQL Eklentisi:</strong> Kurumsal adres defteri, paylaşılan takvim ve filtreler
      </div>
      <span class="<?= $ext_pdo ? 'badge-ok' : 'badge-err' ?>"><?= $ext_pdo ? '✓ AKTİF' : '✗ EKSİK' ?></span>
    </div>

    <div class="check-row">
      <div>
        <strong>Dosya Yazma İzni:</strong> config.php dosyasının kaydedilebilmesi
      </div>
      <span class="<?= $writable_config ? 'badge-ok' : 'badge-err' ?>"><?= $writable_config ? '✓ YAZILABİLİR' : '✗ YAZILAMAZ' ?></span>
    </div>

    <?php if (!$all_sys_ok): ?>
      <div style="margin-top:1.25rem;padding:0.85rem;background:rgba(239,68,68,0.1);border-left:3px solid var(--danger);color:var(--danger);font-size:0.85rem;">
        Sunucu gereksinimlerinden bazıları karşılanmıyor. Lütfen eksik PHP modüllerini aktif edip sayfayı yenileyin.
      </div>
    <?php endif; ?>
  </div>

  <!-- STEP 2: DATABASE CONFIG -->
  <div class="wizard-body" id="step-content-2" style="display:none;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
      <div>
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:0.25rem;">Veritabanı Yapılandırması</h2>
        <p style="font-size:0.825rem;color:var(--text-muted);margin:0;">
          Ortak takvim, domain kullanıcı listesi, disk dosyaları ve adres defteri için MySQL bağlantısını tanımlayın.
        </p>
      </div>
      <button type="button" class="btn btn-secondary" onclick="openManualConfigModal()" style="height:32px;padding:0 0.85rem;font-size:0.775rem;border-color:rgba(245, 158, 11, 0.4);color:#f59e0b;">
        🛠️ Manuel Config Formu
      </button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <div class="form-group">
        <label class="form-label">Veritabanı Sunucusu (Host) *</label>
        <input type="text" id="wiz-db-host" class="form-control" value="<?= htmlspecialchars($current_db_host) ?>" required oninput="resetDbTestState()">
        <div style="display:flex;gap:0.35rem;margin-top:0.35rem;">
          <button type="button" onclick="document.getElementById('wiz-db-host').value='localhost';resetDbTestState();" style="border:1px solid var(--border-color);background:var(--bg-hover);color:var(--text-muted);font-size:0.7rem;padding:2px 8px;cursor:pointer;">localhost</button>
          <button type="button" onclick="document.getElementById('wiz-db-host').value='127.0.0.1';resetDbTestState();" style="border:1px solid var(--border-color);background:var(--bg-hover);color:var(--text-muted);font-size:0.7rem;padding:2px 8px;cursor:pointer;">127.0.0.1</button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Veritabanı Adı (DB Name) *</label>
        <input type="text" id="wiz-db-name" class="form-control" value="<?= htmlspecialchars($current_db_name) ?>" required oninput="resetDbTestState()">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
      <div class="form-group">
        <label class="form-label">Veritabanı Kullanıcısı *</label>
        <input type="text" id="wiz-db-user" class="form-control" value="<?= htmlspecialchars($current_db_user) ?>" required oninput="resetDbTestState()">
      </div>
      <div class="form-group">
        <label class="form-label">Veritabanı Şifresi</label>
        <div style="position:relative;">
          <input type="password" id="wiz-db-pass" class="form-control" style="padding-right:2.5rem;" value="<?= htmlspecialchars($current_db_pass) ?>" placeholder="Şifre yoksa boş bırakın" oninput="resetDbTestState()">
          <button type="button" onclick="togglePassVisibility('wiz-db-pass', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Göster/Gizle">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Prominent Test Action Bar -->
    <div style="margin-top:0.75rem;padding:0.9rem 1.15rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
        <button type="button" id="btn-wiz-test-db" class="btn btn-primary" onclick="testDatabaseConnection()" style="height:38px;padding:0 1.25rem;font-weight:700;display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>⚡ Bilgileri Test Et</span>
        </button>
        <span id="db-test-result" style="font-size:0.875rem;"></span>
      </div>
      <div style="font-size:0.75rem;color:var(--text-muted);">
        Bilgileri girdikten sonra butona basarak bağlantıyı anında doğrulayın.
      </div>
    </div>

    <!-- Error Helper & Manual Config Link (displayed if test fails) -->
    <div id="db-error-helper" style="display:none;margin-top:1.25rem;padding:1rem 1.25rem;background:rgba(239, 68, 68, 0.1);border:1px solid rgba(239, 68, 68, 0.3);border-left:4px solid var(--danger);">
      <div style="font-weight:700;color:var(--danger);font-size:0.9rem;margin-bottom:0.35rem;display:flex;align-items:center;gap:0.4rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Hosting / Veritabanı Bilgileri Hatalı veya Bağlantı Kurulamadı</span>
      </div>
      <p id="db-error-helper-detail" style="font-size:0.8rem;color:#fca5a5;font-family:monospace;background:rgba(0,0,0,0.3);padding:0.4rem 0.6rem;margin-bottom:0.6rem;word-break:break-all;"></p>
      <p style="font-size:0.8rem;color:var(--text-main);line-height:1.5;margin-bottom:0.75rem;">
        Hosting firmanızda MySQL sunucusu <code>localhost</code> yerine farklı bir host adresi (örn: <code>127.0.0.1</code>) gerektiriyor olabilir veya kullanıcı şifresi / adı henüz yetkilendirilmemiş olabilir.
      </p>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="openManualConfigModal()" style="height:36px;font-size:0.8rem;padding:0 1rem;background:var(--warning);border-color:var(--warning);color:#000;font-weight:700;">
          🛠️ Manuel Config Formunu Aç / Dosya İndir
        </button>
      </div>
    </div>
  </div>

  <!-- STEP 3: DOMAIN LOCK & MAIL SERVER -->
  <div class="wizard-body" id="step-content-3" style="display:none;">
    <div class="domain-lock-banner">
      <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <strong style="color:var(--text-main);font-size:0.95rem;">ZORUNLU DOMAIN İZOLASYON KİLİDİ:</strong>
      </div>
      <p style="font-size:0.825rem;color:var(--text-muted);line-height:1.5;">
        Bu sunucu kurulumu yapıldıktan sonra <strong>kesinlikle belirtilen domain haricinde hiçbir domaine hizmet vermeyecektir</strong>. Başka alan adları (@gmail.com, @hotmail.com veya diğer kurumsal alanlar) ile sisteme giriş yapılamaz, hesap eklenemez ve dış domainlere veri sızdırılamaz.
      </p>
    </div>

    <div class="form-group">
      <label class="form-label" style="font-size:0.95rem;font-weight:700;">Hizmet Verilecek Yetkili Kurumsal Alan Adı (Domain) *</label>
      <div style="position:relative;">
        <span style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);font-weight:700;color:var(--primary);">@</span>
        <input type="text" id="wiz-domain" class="form-control" style="padding-left:2.5rem;font-size:1rem;font-weight:600;" value="<?= htmlspecialchars($current_domain) ?>" placeholder="Örn: example.com" required oninput="autoUpdateMailHosts(this.value)">
      </div>
      <small style="color:var(--text-muted);font-size:0.75rem;display:block;margin-top:0.35rem;">
        Kullanıcılar girişte sadece kullanıcı adı (örn: <code>kullanici</code>) yazdıklarında sistem otomatik olarak bu domaini ekler.
      </small>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1.5rem;">
      <!-- IMAP Settings -->
      <div style="padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
        <div style="font-weight:700;font-size:0.875rem;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.4rem;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          IMAP Gelen Posta Sunucusu
        </div>
        <div class="form-group">
          <label class="form-label">IMAP Host</label>
          <input type="text" id="wiz-imap-host" class="form-control" value="<?= htmlspecialchars($current_imap_host) ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" id="wiz-imap-port" class="form-control" value="<?= htmlspecialchars($current_imap_port) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Şifreleme</label>
            <select id="wiz-imap-enc" class="form-control">
              <option value="ssl" <?= $current_imap_enc === 'ssl' ? 'selected' : '' ?>>SSL (993)</option>
              <option value="tls" <?= $current_imap_enc === 'tls' ? 'selected' : '' ?>>STARTTLS (143)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- SMTP Settings -->
      <div style="padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
        <div style="font-weight:700;font-size:0.875rem;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.4rem;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          SMTP Giden Posta Sunucusu
        </div>
        <div class="form-group">
          <label class="form-label">SMTP Host</label>
          <input type="text" id="wiz-smtp-host" class="form-control" value="<?= htmlspecialchars($current_smtp_host) ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" id="wiz-smtp-port" class="form-control" value="<?= htmlspecialchars($current_smtp_port) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Şifreleme</label>
            <select id="wiz-smtp-enc" class="form-control">
              <option value="tls" <?= $current_smtp_enc === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
              <option value="ssl" <?= $current_smtp_enc === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Initial Administrator Account Setup -->
    <div style="margin-top:1.5rem;padding:1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-left:4px solid #f59e0b;">
      <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.4rem;color:var(--text-main);">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        <span>İlk Yönetici (Admin) Hesabı</span>
      </div>
      <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;line-height:1.4;">
        Kurulumu tamamlayan bu hesap, sistemin ilk <strong>Süper Yöneticisi</strong> olacak; Yönetim Paneli üzerinden yeni e-postalar açabilecek ve diğer kullanıcılara yöneticilik yetkisi atayabilecektir.
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Yönetici E-Posta / Kullanıcı Adı *</label>
          <input type="text" id="wiz-admin-user" class="form-control" placeholder="Örn: admin veya ad.soyad" value="admin">
        </div>
        <div class="form-group">
          <label class="form-label">Yönetici Ad Soyad</label>
          <input type="text" id="wiz-admin-name" class="form-control" placeholder="Örn: Sistem Yöneticisi" value="Sistem Yöneticisi">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Yönetici Şifresi *</label>
        <input type="password" id="wiz-admin-pass" class="form-control" placeholder="Yönetici posta kutusu şifresi">
      </div>
    </div>
  </div>

  <!-- STEP 4: FINISH & SUCCESS -->
  <div class="wizard-body" id="step-content-4" style="display:none;text-align:center;padding:3rem 2rem;">
    <div style="width:64px;height:64px;background:rgba(16,185,129,0.15);color:var(--success);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.25rem;border:1px solid rgba(16,185,129,0.3);">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:0.5rem;">Kurulum Tamamlandı!</h2>
    <p style="font-size:0.9rem;color:var(--text-muted);max-width:540px;margin:0 auto 1.5rem;line-height:1.6;">
      Sistem başarıyla kuruldu. Veritabanı tabloları oluşturuldu ve posta sunucusu yalnızca <strong id="finish-domain-name" style="color:var(--text-main);"></strong> alan adına hizmet verecek şekilde kilitlendi.
    </p>

    <div style="display:inline-flex;gap:1rem;">
      <a href="index.php" class="btn btn-primary" style="padding:0.75rem 2rem;text-decoration:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span>Webmail Girişine Git</span>
      </a>
    </div>
  </div>

  <!-- FOOTER CONTROLS -->
  <div class="wizard-footer" id="wizard-footer">
    <button type="button" class="btn btn-secondary" id="btn-wiz-prev" onclick="prevStep()" style="display:none;">
      &larr; Geri
    </button>
    <div style="margin-left:auto;display:flex;gap:0.75rem;">
      <button type="button" class="btn btn-primary" id="btn-wiz-next" onclick="nextStep()">
        Devam Et &rarr;
      </button>
    </div>
  </div>
</div>

<script>
let currentStep = 1;
const totalSteps = 4;

function updateStepUI() {
  for (let i = 1; i <= totalSteps; i++) {
    const nav = document.getElementById(`step-nav-${i}`);
    const content = document.getElementById(`step-content-${i}`);
    if (nav) {
      nav.classList.remove('active');
      if (i < currentStep) nav.classList.add('completed');
      else nav.classList.remove('completed');
      if (i === currentStep) nav.classList.add('active');
    }
    if (content) {
      content.style.display = (i === currentStep) ? 'block' : 'none';
    }
  }

  const btnPrev = document.getElementById('btn-wiz-prev');
  const btnNext = document.getElementById('btn-wiz-next');
  const footer = document.getElementById('wizard-footer');

  if (btnPrev) btnPrev.style.display = (currentStep > 1 && currentStep < 4) ? 'inline-flex' : 'none';

  if (currentStep === 3) {
    btnNext.innerHTML = '<span>Kurulumu Kaydet &amp; Kilitle</span> <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
    btnNext.className = 'btn btn-success';
  } else if (currentStep === 4) {
    footer.style.display = 'none';
  } else {
    btnNext.innerHTML = '<span>Devam Et &rarr;</span>';
    btnNext.className = 'btn btn-primary';
  }
}

function nextStep() {
  if (currentStep === 1) {
    currentStep = 2;
    updateStepUI();
  } else if (currentStep === 2) {
    // Validate DB inputs
    const host = document.getElementById('wiz-db-host').value.trim();
    const name = document.getElementById('wiz-db-name').value.trim();
    const user = document.getElementById('wiz-db-user').value.trim();
    if (!host || !name || !user) {
      Swal.fire({ icon: 'warning', title: 'Eksik Alan', text: 'Lütfen veritabanı host, ad ve kullanıcı bilgilerini doldurun.' });
      return;
    }
    currentStep = 3;
    updateStepUI();
  } else if (currentStep === 3) {
    saveSetup();
  }
}

function prevStep() {
  if (currentStep > 1) {
    currentStep--;
    updateStepUI();
  }
}

function autoUpdateMailHosts(domain) {
  domain = domain.trim().replace(/^https?:\/\//i, '').replace(/^@+/, '');
  const imapHost = document.getElementById('wiz-imap-host');
  const smtpHost = document.getElementById('wiz-smtp-host');
  if (domain) {
    imapHost.value = 'mail.' + domain;
    smtpHost.value = 'mail.' + domain;
  }
}

function escapeHtml(text) {
  if (!text) return '';
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

function resetDbTestState() {
  const resultEl = document.getElementById('db-test-result');
  if (resultEl) resultEl.innerHTML = '';
  const errorHelper = document.getElementById('db-error-helper');
  if (errorHelper) errorHelper.style.display = 'none';
}

function togglePassVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const isPass = (input.type === 'password');
  input.type = isPass ? 'text' : 'password';
  if (btn) {
    btn.style.color = isPass ? 'var(--primary)' : 'var(--text-muted)';
  }
}

function testDatabaseConnection() {
  const btn = document.getElementById('btn-wiz-test-db');
  const resultEl = document.getElementById('db-test-result');
  const errorHelper = document.getElementById('db-error-helper');
  const errorDetail = document.getElementById('db-error-helper-detail');

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 0.8s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>
      <span>Test Ediliyor...</span>
    `;
  }
  resultEl.innerHTML = '<span style="color:var(--text-muted);">MySQL sunucusuna bağlanılıyor...</span>';
  if (errorHelper) errorHelper.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'test_db');
  fd.append('db_host', document.getElementById('wiz-db-host').value.trim());
  fd.append('db_name', document.getElementById('wiz-db-name').value.trim());
  fd.append('db_user', document.getElementById('wiz-db-user').value.trim());
  fd.append('db_pass', document.getElementById('wiz-db-pass').value);

  fetch('wizard.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>⚡ Bilgileri Test Et</span>
        `;
      }
      if (data.success) {
        resultEl.innerHTML = `<span style="color:var(--success);font-weight:700;display:inline-flex;align-items:center;gap:0.35rem;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> ${escapeHtml(data.message)}</span>`;
        if (errorHelper) errorHelper.style.display = 'none';
      } else {
        resultEl.innerHTML = `<span style="color:var(--danger);font-weight:700;display:inline-flex;align-items:center;gap:0.35rem;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ${escapeHtml(data.message)}</span>`;
        if (errorHelper) {
          errorHelper.style.display = 'block';
          if (errorDetail) errorDetail.textContent = data.message;
        }
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>⚡ Bilgileri Test Et</span>
        `;
      }
      resultEl.innerHTML = `<span style="color:var(--danger);">Bağlantı hatası: ${escapeHtml(err.message)}</span>`;
      if (errorHelper) {
        errorHelper.style.display = 'block';
        if (errorDetail) errorDetail.textContent = err.message;
      }
    });
}

function saveSetup() {
  const domain = document.getElementById('wiz-domain').value.trim().replace(/^@+/, '');
  if (!domain) {
    Swal.fire({ icon: 'warning', title: 'Domain Gerekli', text: 'Lütfen yetkili kurumsal alan adını girin.' });
    return;
  }

  const btnNext = document.getElementById('btn-wiz-next');
  btnNext.disabled = true;
  btnNext.innerHTML = '<span>Kaydediliyor...</span>';

  const fd = new FormData();
  fd.append('action', 'save_setup');
  fd.append('db_host', document.getElementById('wiz-db-host').value.trim());
  fd.append('db_name', document.getElementById('wiz-db-name').value.trim());
  fd.append('db_user', document.getElementById('wiz-db-user').value.trim());
  fd.append('db_pass', document.getElementById('wiz-db-pass').value);
  fd.append('domain', domain);
  fd.append('imap_host', document.getElementById('wiz-imap-host').value.trim());
  fd.append('imap_port', document.getElementById('wiz-imap-port').value.trim());
  fd.append('imap_enc', document.getElementById('wiz-imap-enc').value);
  fd.append('smtp_host', document.getElementById('wiz-smtp-host').value.trim());
  fd.append('smtp_port', document.getElementById('wiz-smtp-port').value.trim());
  fd.append('smtp_enc', document.getElementById('wiz-smtp-enc').value);

  // Admin account info
  const admUser = document.getElementById('wiz-admin-user');
  const admPass = document.getElementById('wiz-admin-pass');
  const admName = document.getElementById('wiz-admin-name');
  if (admUser && admUser.value.trim()) fd.append('admin_user', admUser.value.trim());
  if (admPass && admPass.value) fd.append('admin_pass', admPass.value);
  if (admName && admName.value.trim()) fd.append('admin_name', admName.value.trim());

  fetch('wizard.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btnNext.disabled = false;
      if (data.success) {
        document.getElementById('finish-domain-name').textContent = '@' + domain;
        currentStep = 4;
        updateStepUI();
      } else {
        btnNext.innerHTML = '<span>Kurulumu Kaydet &amp; Kilitle</span>';
        Swal.fire({
          icon: 'error',
          title: 'Hata',
          text: data.message,
          showCancelButton: true,
          cancelButtonText: 'Kapat',
          confirmButtonColor: '#f59e0b',
          confirmButtonText: '🛠️ Manuel Config Formunu Aç'
        }).then(res => {
          if (res.isConfirmed) {
            openManualConfigModal();
          }
        });
      }
    })
    .catch(err => {
      btnNext.disabled = false;
      btnNext.innerHTML = '<span>Kurulumu Kaydet &amp; Kilitle</span>';
      Swal.fire({ icon: 'error', title: 'Bağlantı Hatası', text: err.message });
    });
}

// -------------------------------------------------------------
// MANUEL CONFIG FORMU & JAVASCRIPT CONTROLLERS
// -------------------------------------------------------------
function openManualConfigModal() {
  const dbHost = document.getElementById('wiz-db-host');
  const dbName = document.getElementById('wiz-db-name');
  const dbUser = document.getElementById('wiz-db-user');
  const dbPass = document.getElementById('wiz-db-pass');
  const domain = document.getElementById('wiz-domain');
  const imapHost = document.getElementById('wiz-imap-host');
  const imapPort = document.getElementById('wiz-imap-port');
  const imapEnc = document.getElementById('wiz-imap-enc');
  const smtpHost = document.getElementById('wiz-smtp-host');
  const smtpPort = document.getElementById('wiz-smtp-port');
  const smtpEnc = document.getElementById('wiz-smtp-enc');

  if (dbHost && dbHost.value) document.getElementById('m-db-host').value = dbHost.value.trim();
  if (dbName && dbName.value) document.getElementById('m-db-name').value = dbName.value.trim();
  if (dbUser && dbUser.value) document.getElementById('m-db-user').value = dbUser.value.trim();
  if (dbPass) document.getElementById('m-db-pass').value = dbPass.value;
  if (domain && domain.value) document.getElementById('m-domain').value = domain.value.trim().replace(/^@+/, '');
  if (imapHost && imapHost.value) document.getElementById('m-imap-host').value = imapHost.value.trim();
  if (imapPort && imapPort.value) document.getElementById('m-imap-port').value = imapPort.value.trim();
  if (imapEnc && imapEnc.value) document.getElementById('m-imap-enc').value = imapEnc.value;
  if (smtpHost && smtpHost.value) document.getElementById('m-smtp-host').value = smtpHost.value.trim();
  if (smtpPort && smtpPort.value) document.getElementById('m-smtp-port').value = smtpPort.value.trim();
  if (smtpEnc && smtpEnc.value) document.getElementById('m-smtp-enc').value = smtpEnc.value;

  updateManualConfigCode();
  initSqlSchemaCode();

  const modal = document.getElementById('manual-config-modal');
  if (modal) modal.style.display = 'flex';
}

function closeManualConfigModal() {
  const modal = document.getElementById('manual-config-modal');
  if (modal) modal.style.display = 'none';
}

function autoUpdateManualMailHosts(val) {
  const clean = val.trim().replace(/^@+/, '');
  if (clean) {
    document.getElementById('m-imap-host').value = 'mail.' + clean;
    document.getElementById('m-smtp-host').value = 'mail.' + clean;
  }
}

function updateManualConfigCode() {
  const dbHost = (document.getElementById('m-db-host').value.trim() || 'localhost').replace(/'/g, "\\'");
  const dbUser = (document.getElementById('m-db-user').value.trim() || 'root').replace(/'/g, "\\'");
  const dbPass = (document.getElementById('m-db-pass').value || '').replace(/'/g, "\\'");
  const dbName = (document.getElementById('m-db-name').value.trim() || 'hestia_webmail').replace(/'/g, "\\'");
  const domain = (document.getElementById('m-domain').value.trim().replace(/^@+/, '') || 'example.com').replace(/'/g, "\\'");
  const imapHost = (document.getElementById('m-imap-host').value.trim() || ('mail.' + domain)).replace(/'/g, "\\'");
  const imapPort = parseInt(document.getElementById('m-imap-port').value) || 993;
  const imapEnc = document.getElementById('m-imap-enc').value || 'ssl';
  const smtpHost = (document.getElementById('m-smtp-host').value.trim() || ('mail.' + domain)).replace(/'/g, "\\'");
  const smtpPort = parseInt(document.getElementById('m-smtp-port').value) || 587;
  const smtpEnc = document.getElementById('m-smtp-enc').value || 'tls';
  const hestiaHost = (document.getElementById('m-hestia-host').value.trim() || '').replace(/'/g, "\\'");
  const hestiaUser = (document.getElementById('m-hestia-user').value.trim() || 'admin').replace(/'/g, "\\'");
  const hestiaKey = (document.getElementById('m-hestia-key').value.trim() || '').replace(/'/g, "\\'");

  const phpOpen = '<' + '?php';
  const code = `${phpOpen}
/**
 * Hestia Webmail Suite - Merkezi Konfigürasyon Dosyası
 * 
 * Manuel Config Oluşturucu tarafından oluşturulmuştur.
 * Bu sistem yalnızca yetkilendirilen kurumsal domaine hizmet verir.
 */

// -------------------------------------------------------------
// VERİTABANI BAĞLANTI AYARLARI
// -------------------------------------------------------------
defined('DB_HOST') or define('DB_HOST', '${dbHost}');
defined('DB_USER') or define('DB_USER', '${dbUser}');
defined('DB_PASS') or define('DB_PASS', '${dbPass}');
defined('DB_NAME') or define('DB_NAME', '${dbName}');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// -------------------------------------------------------------
// YETKİLİ KURUMSAL ALAN ADI (TEK DOMAIN GÜVENLİK KİLİDİ)
// -------------------------------------------------------------
defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', '${domain}');
defined('STRICT_DOMAIN_LOCK') or define('STRICT_DOMAIN_LOCK', true);

// IMAP Sunucusu (Gelen Posta)
defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', '${imapHost}');
defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', ${imapPort});
defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', '${imapEnc}');

// SMTP Sunucusu (Giden Posta)
defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', '${smtpHost}');
defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', ${smtpPort});
defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', '${smtpEnc}');

// -------------------------------------------------------------
// HESTIACP API AYARLARI (İsteğe Bağlı)
// -------------------------------------------------------------
defined('HESTIA_API_HOST') or define('HESTIA_API_HOST', '${hestiaHost}');
defined('HESTIA_API_USER') or define('HESTIA_API_USER', '${hestiaUser}');
defined('HESTIA_API_KEY') or define('HESTIA_API_KEY', '${hestiaKey}');
`;

  const ta = document.getElementById('manual-config-preview');
  if (ta) ta.value = code;
}

function copyManualConfigCode() {
  const code = document.getElementById('manual-config-preview').value;
  if (!code) return;
  navigator.clipboard.writeText(code).then(() => {
    Swal.fire({
      icon: 'success',
      title: 'Kopyalandı!',
      text: 'config.php dosya içeriği panoya kopyalandı. Sitenizin ana dizinindeki config.php dosyasına yapıştırıp kaydedebilirsiniz.',
      timer: 2500,
      showConfirmButton: false
    });
  }).catch(() => {
    const ta = document.getElementById('manual-config-preview');
    ta.select();
    document.execCommand('copy');
    Swal.fire('Kopyalandı!', 'config.php dosya içeriği panoya kopyalandı.', 'success');
  });
}

function downloadManualConfigFile() {
  const code = document.getElementById('manual-config-preview').value;
  if (!code) return;
  const blob = new Blob([code], { type: 'application/x-php;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'config.php';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(a.href);

  Swal.fire({
    icon: 'success',
    title: 'config.php İndirildi',
    text: 'İndirilen config.php dosyasını FTP veya cPanel Dosya Yöneticisi ile web sitenizin ana dizinine yükleyiniz.',
    confirmButtonText: 'Anladım'
  });
}

function saveManualConfigToServer() {
  const btn = document.getElementById('btn-manual-save-server');
  btn.disabled = true;
  btn.textContent = 'Kaydediliyor...';

  const fd = new FormData();
  fd.append('action', 'save_manual_config');
  fd.append('db_host', document.getElementById('m-db-host').value.trim());
  fd.append('db_name', document.getElementById('m-db-name').value.trim());
  fd.append('db_user', document.getElementById('m-db-user').value.trim());
  fd.append('db_pass', document.getElementById('m-db-pass').value);
  fd.append('domain', document.getElementById('m-domain').value.trim().replace(/^@+/, ''));
  fd.append('imap_host', document.getElementById('m-imap-host').value.trim());
  fd.append('imap_port', document.getElementById('m-imap-port').value.trim());
  fd.append('imap_enc', document.getElementById('m-imap-enc').value);
  fd.append('smtp_host', document.getElementById('m-smtp-host').value.trim());
  fd.append('smtp_port', document.getElementById('m-smtp-port').value.trim());
  fd.append('smtp_enc', document.getElementById('m-smtp-enc').value);
  fd.append('hestia_host', document.getElementById('m-hestia-host').value.trim());
  fd.append('hestia_user', document.getElementById('m-hestia-user').value.trim());
  fd.append('hestia_key', document.getElementById('m-hestia-key').value.trim());

  fetch('wizard.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.textContent = '💾 Sunucuya Kaydet (config.php Üzerine Yaz)';
      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Kaydedildi!',
          text: data.message,
          confirmButtonText: 'Giriş Sayfasına Git'
        }).then(() => {
          window.location.href = 'index.php';
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Yazılamadı',
          text: data.message
        });
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.textContent = '💾 Sunucuya Kaydet (config.php Üzerine Yaz)';
      Swal.fire('Hata', 'Sunucu bağlantı hatası: ' + err.message, 'error');
    });
}

function testManualDbConnection() {
  const btn = document.getElementById('btn-manual-test-db');
  const resultEl = document.getElementById('m-db-test-result');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Test Ediliyor...';
  }
  resultEl.innerHTML = '<span style="color:var(--text-muted);display:inline-flex;align-items:center;gap:0.3rem;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 0.8s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg> Bağlantı test ediliyor...</span>';

  const fd = new FormData();
  fd.append('action', 'test_db_manual');
  fd.append('db_host', document.getElementById('m-db-host').value.trim());
  fd.append('db_name', document.getElementById('m-db-name').value.trim());
  fd.append('db_user', document.getElementById('m-db-user').value.trim());
  fd.append('db_pass', document.getElementById('m-db-pass').value);

  fetch('wizard.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = '⚡ Bağlantıyı Test Et';
      }
      if (data.success) {
        resultEl.innerHTML = '<span style="color:var(--success);font-weight:700;">✓ ' + escapeHtml(data.message) + '</span>';
      } else {
        resultEl.innerHTML = '<span style="color:var(--danger);font-weight:700;">✗ ' + escapeHtml(data.message) + '</span>';
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = '⚡ Bağlantıyı Test Et';
      }
      resultEl.innerHTML = '<span style="color:var(--danger);">Bağlantı hatası: ' + escapeHtml(err.message) + '</span>';
    });
}

function initSqlSchemaCode() {
  const sql = `-- Hestia Webmail Suite - Veritabanı Tablo Şeması
-- phpMyAdmin üzerinden içe aktarabilirsiniz

CREATE TABLE IF NOT EXISTS \`mail_accounts\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`name\` varchar(150) NOT NULL,
  \`email\` varchar(150) NOT NULL,
  \`imap_host\` varchar(150) NOT NULL,
  \`imap_port\` int(11) NOT NULL DEFAULT 993,
  \`imap_encryption\` varchar(10) NOT NULL DEFAULT 'ssl',
  \`imap_username\` varchar(150) NOT NULL,
  \`imap_password\` varchar(255) NOT NULL,
  \`smtp_host\` varchar(150) NOT NULL,
  \`smtp_port\` int(11) NOT NULL DEFAULT 587,
  \`smtp_encryption\` varchar(10) NOT NULL DEFAULT 'tls',
  \`smtp_username\` varchar(150) NOT NULL,
  \`smtp_password\` varchar(255) NOT NULL,
  \`signature\` text DEFAULT NULL,
  \`is_default\` tinyint(1) NOT NULL DEFAULT 0,
  \`is_admin\` tinyint(1) NOT NULL DEFAULT 0,
  \`role\` varchar(20) NOT NULL DEFAULT 'user',
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`email\` (\`email\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`domain_directory\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`name\` varchar(150) NOT NULL,
  \`email\` varchar(150) NOT NULL,
  \`department\` varchar(100) DEFAULT NULL,
  \`role\` varchar(50) DEFAULT 'user',
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`email\` (\`email\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`contacts\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`first_name\` varchar(100) NOT NULL,
  \`last_name\` varchar(100) DEFAULT NULL,
  \`email\` varchar(150) NOT NULL,
  \`phone\` varchar(50) DEFAULT NULL,
  \`company\` varchar(150) DEFAULT NULL,
  \`job_title\` varchar(100) DEFAULT NULL,
  \`group_name\` varchar(50) DEFAULT 'Genel',
  \`notes\` text DEFAULT NULL,
  \`avatar_color\` varchar(20) DEFAULT '#4f46e5',
  \`is_favorite\` tinyint(1) DEFAULT 0,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`calendar_events\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`domain\` varchar(100) DEFAULT NULL,
  \`is_shared\` tinyint(1) DEFAULT 1,
  \`creator_name\` varchar(150) DEFAULT NULL,
  \`creator_email\` varchar(150) DEFAULT NULL,
  \`title\` varchar(200) NOT NULL,
  \`description\` text DEFAULT NULL,
  \`location\` varchar(255) DEFAULT NULL,
  \`start_date\` datetime NOT NULL,
  \`end_date\` datetime NOT NULL,
  \`all_day\` tinyint(1) DEFAULT 0,
  \`color\` varchar(20) DEFAULT '#4f46e5',
  \`category\` varchar(50) DEFAULT 'Toplantı',
  \`status\` varchar(20) DEFAULT 'confirmed',
  \`reminder_minutes\` int(11) DEFAULT 15,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`),
  KEY \`idx_domain_shared\` (\`domain\`, \`is_shared\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`mail_filters\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) DEFAULT 0,
  \`filter_name\` varchar(150) NOT NULL DEFAULT '',
  \`match_field\` varchar(50) NOT NULL DEFAULT 'from',
  \`match_type\` varchar(50) NOT NULL DEFAULT 'contains',
  \`match_value\` varchar(255) NOT NULL DEFAULT '',
  \`action_type\` varchar(50) NOT NULL DEFAULT 'move',
  \`action_target\` varchar(255) DEFAULT NULL,
  \`is_active\` tinyint(1) DEFAULT 1,
  \`created_at\` datetime DEFAULT current_timestamp(),
  \`updated_at\` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`mail_folders\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`folder_name\` varchar(100) NOT NULL,
  \`display_name\` varchar(100) NOT NULL,
  \`parent_folder\` varchar(100) DEFAULT NULL,
  \`icon\` varchar(50) DEFAULT 'folder',
  \`color\` varchar(20) DEFAULT '#64748b',
  \`sort_order\` int(11) DEFAULT 0,
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`mail_settings\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`setting_key\` varchar(100) NOT NULL,
  \`setting_value\` text DEFAULT NULL,
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`setting_key\` (\`setting_key\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`mail_drafts\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`to_address\` text DEFAULT NULL,
  \`cc_address\` text DEFAULT NULL,
  \`bcc_address\` text DEFAULT NULL,
  \`subject\` varchar(255) DEFAULT NULL,
  \`body\` longtext DEFAULT NULL,
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`mail_imports\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`source_email\` varchar(255) NOT NULL,
  \`source_password\` text DEFAULT NULL,
  \`source_host\` varchar(255) NOT NULL,
  \`source_port\` int(11) DEFAULT 993,
  \`source_encryption\` varchar(20) DEFAULT 'ssl',
  \`source_folder\` varchar(255) DEFAULT 'ALL',
  \`target_mode\` varchar(20) DEFAULT 'same',
  \`custom_target_folder\` varchar(255) DEFAULT 'Eski_Postalar',
  \`date_filter\` varchar(50) DEFAULT 'all',
  \`limit_count\` int(11) DEFAULT 0,
  \`skip_duplicates\` tinyint(4) DEFAULT 1,
  \`target_folder\` varchar(255) DEFAULT 'INBOX',
  \`current_folder\` varchar(255) DEFAULT NULL,
  \`progress_percent\` int(11) DEFAULT 0,
  \`pid\` int(11) DEFAULT NULL,
  \`imported_count\` int(11) DEFAULT 0,
  \`total_found\` int(11) DEFAULT 0,
  \`skipped_count\` int(11) DEFAULT 0,
  \`status\` varchar(50) DEFAULT 'pending',
  \`error_message\` text DEFAULT NULL,
  \`created_at\` datetime DEFAULT current_timestamp(),
  \`updated_at\` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  \`completed_at\` datetime DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  KEY \`idx_acc_status\` (\`account_id\`, \`status\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`drive_folders\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`parent_id\` int(11) DEFAULT 0,
  \`name\` varchar(255) NOT NULL,
  \`created_at\` datetime DEFAULT current_timestamp(),
  \`updated_at\` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`idx_acc_parent\` (\`account_id\`, \`parent_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`drive_files\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`folder_id\` int(11) DEFAULT 0,
  \`original_name\` varchar(255) NOT NULL,
  \`stored_name\` varchar(255) NOT NULL,
  \`file_size\` bigint(20) DEFAULT 0,
  \`mime_type\` varchar(100) DEFAULT 'application/octet-stream',
  \`extension\` varchar(20) DEFAULT '',
  \`created_at\` datetime DEFAULT current_timestamp(),
  \`updated_at\` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`idx_acc_folder\` (\`account_id\`, \`folder_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`user_tasks\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL DEFAULT 0,
  \`title\` varchar(255) NOT NULL DEFAULT '',
  \`description\` text DEFAULT NULL,
  \`due_date\` date DEFAULT NULL,
  \`priority\` varchar(20) DEFAULT 'medium',
  \`is_completed\` tinyint(1) DEFAULT 0,
  \`completed_at\` datetime DEFAULT NULL,
  \`created_at\` datetime DEFAULT current_timestamp(),
  \`updated_at\` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`),
  KEY \`idx_task_comp\` (\`is_completed\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`user_notes\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`account_id\` int(11) NOT NULL,
  \`title\` varchar(255) NOT NULL,
  \`content\` longtext DEFAULT NULL,
  \`color\` varchar(20) DEFAULT '#fef08a',
  \`is_pinned\` tinyint(1) DEFAULT 0,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  \`updated_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (\`id\`),
  KEY \`account_id\` (\`account_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
`;
  const el = document.getElementById('sql-schema-code');
  if (el) el.value = sql;
}

function copySqlSchemaCode() {
  const el = document.getElementById('sql-schema-code');
  if (!el || !el.value) return;
  navigator.clipboard.writeText(el.value).then(() => {
    Swal.fire({
      icon: 'success',
      title: 'SQL Kopyalandı!',
      text: 'phpMyAdmin üzerinden veritabanınıza aktarabilirsiniz.',
      timer: 2000,
      showConfirmButton: false
    });
  });
}

// Auto open manual config modal if requested via URL
if (window.location.search.includes('manual=1')) {
  setTimeout(() => {
    openManualConfigModal();
  }, 200);
}
</script>

<!-- MANUAL CONFIG GENERATOR & RECOVERY MODAL -->
<div id="manual-config-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:1rem;box-sizing:border-box;">
  <div style="background:var(--bg-surface);width:820px;max-width:96vw;max-height:92vh;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.5rem 1.75rem;box-sizing:border-box;display:flex;flex-direction:column;">
    
    <!-- Modal Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:0.6rem;">
        <span style="font-size:1.4rem;">🛠️</span>
        <div>
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-main);">Manuel Config Oluşturucu &amp; Hosting Ayarları</h3>
          <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
            Hosting / MySQL bilgilerinizi elle tanımlayın, canlı config.php kodunu kopyalayın veya dosya olarak indirin.
          </div>
        </div>
      </div>
      <button type="button" onclick="closeManualConfigModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
    </div>

    <!-- Scrollable Body -->
    <div style="overflow-y:auto;flex:1;padding-right:0.35rem;">
      
      <!-- Hosting DB Info Box -->
      <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;background:rgba(245, 158, 11, 0.1);border:1px solid rgba(245, 158, 11, 0.3);border-left:4px solid var(--warning);font-size:0.8rem;color:var(--text-main);line-height:1.5;">
        <strong style="color:#f59e0b;display:block;margin-bottom:0.25rem;">Hosting İpucu:</strong>
        Paylaşımlı hostinglerde (cPanel, Plesk, DirectAdmin, Hestia) MySQL sunucu adresi çoğunlukla <code>localhost</code> veya <code>127.0.0.1</code>'dir. Kullanıcı adı ve veritabanı adı genellikle hosting kullanıcı ön ekiyle başlar (Örn: <code>kullanici_webmail</code>).
      </div>

      <!-- Section: Veritabanı Bilgileri -->
      <div style="margin-bottom:1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);padding:1rem;">
        <div style="font-weight:700;font-size:0.875rem;margin-bottom:0.75rem;display:flex;align-items:center;justify-content:space-between;">
          <span style="display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
            1. Veritabanı (MySQL / MariaDB) Bilgileri
          </span>
          <button type="button" id="btn-manual-test-db" class="btn btn-secondary" onclick="testManualDbConnection()" style="height:28px;padding:0 0.65rem;font-size:0.75rem;cursor:pointer;">
            ⚡ Bağlantıyı Test Et
          </button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="form-label" style="font-size:0.775rem;">Sunucu Host (DB_HOST) *</label>
            <input type="text" id="m-db-host" class="form-control" style="height:36px;" value="localhost" oninput="updateManualConfigCode()">
            <div style="display:flex;gap:0.35rem;margin-top:0.3rem;">
              <button type="button" onclick="document.getElementById('m-db-host').value='localhost';updateManualConfigCode();" style="border:1px solid var(--border-color);background:var(--bg-hover);color:var(--text-muted);font-size:0.7rem;padding:1px 6px;cursor:pointer;">localhost</button>
              <button type="button" onclick="document.getElementById('m-db-host').value='127.0.0.1';updateManualConfigCode();" style="border:1px solid var(--border-color);background:var(--bg-hover);color:var(--text-muted);font-size:0.7rem;padding:1px 6px;cursor:pointer;">127.0.0.1</button>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="form-label" style="font-size:0.775rem;">Veritabanı Adı (DB_NAME) *</label>
            <input type="text" id="m-db-name" class="form-control" style="height:36px;" value="hestia_webmail" oninput="updateManualConfigCode()">
          </div>
          <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="form-label" style="font-size:0.775rem;">Veritabanı Kullanıcısı (DB_USER) *</label>
            <input type="text" id="m-db-user" class="form-control" style="height:36px;" value="root" oninput="updateManualConfigCode()">
          </div>
          <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="form-label" style="font-size:0.775rem;">Veritabanı Şifresi (DB_PASS)</label>
            <div style="position:relative;">
              <input type="password" id="m-db-pass" class="form-control" style="height:36px;padding-right:2.2rem;" value="" placeholder="Veritabanı şifreniz" oninput="updateManualConfigCode()">
              <button type="button" onclick="togglePassVisibility('m-db-pass', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Göster/Gizle">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
        </div>
        <div id="m-db-test-result" style="font-size:0.775rem;margin-top:0.25rem;"></div>
      </div>

      <!-- Section: Domain ve Posta Sunucusu -->
      <div style="margin-bottom:1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);padding:1rem;">
        <div style="font-weight:700;font-size:0.875rem;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.4rem;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          2. Yetkili Domain &amp; Mail Sunucuları
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
          <label class="form-label" style="font-size:0.775rem;">Hizmet Verilecek Kurumsal Domain (MAIL_DOMAIN) *</label>
          <input type="text" id="m-domain" class="form-control" style="height:36px;" value="example.com" oninput="autoUpdateManualMailHosts(this.value); updateManualConfigCode();">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <div>
            <label class="form-label" style="font-size:0.775rem;">IMAP Host &amp; Port &amp; SSL</label>
            <div style="display:flex;gap:0.35rem;">
              <input type="text" id="m-imap-host" class="form-control" style="height:36px;flex:2;" value="mail.example.com" oninput="updateManualConfigCode()">
              <input type="number" id="m-imap-port" class="form-control" style="height:36px;flex:1;" value="993" oninput="updateManualConfigCode()">
              <select id="m-imap-enc" class="form-control" style="height:36px;flex:1;" onchange="updateManualConfigCode()">
                <option value="ssl" selected>ssl</option>
                <option value="tls">tls</option>
                <option value="none">none</option>
              </select>
            </div>
          </div>
          <div>
            <label class="form-label" style="font-size:0.775rem;">SMTP Host &amp; Port &amp; TLS</label>
            <div style="display:flex;gap:0.35rem;">
              <input type="text" id="m-smtp-host" class="form-control" style="height:36px;flex:2;" value="mail.example.com" oninput="updateManualConfigCode()">
              <input type="number" id="m-smtp-port" class="form-control" style="height:36px;flex:1;" value="587" oninput="updateManualConfigCode()">
              <select id="m-smtp-enc" class="form-control" style="height:36px;flex:1;" onchange="updateManualConfigCode()">
                <option value="tls" selected>tls</option>
                <option value="ssl">ssl</option>
                <option value="none">none</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Section: HestiaCP REST API (İsteğe Bağlı) -->
      <div style="margin-bottom:1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);padding:1rem;">
        <div style="font-weight:700;font-size:0.875rem;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.4rem;">
          <span>⚙️</span>
          3. HestiaCP REST API (İsteğe Bağlı)
        </div>
        <div style="display:grid;grid-template-columns:1.5fr 1fr 2fr;gap:0.75rem;">
          <div>
            <label class="form-label" style="font-size:0.775rem;">Sunucu Host</label>
            <input type="text" id="m-hestia-host" class="form-control" style="height:36px;" value="https://mail.example.com:8083" oninput="updateManualConfigCode()">
          </div>
          <div>
            <label class="form-label" style="font-size:0.775rem;">Kullanıcı</label>
            <input type="text" id="m-hestia-user" class="form-control" style="height:36px;" value="admin" oninput="updateManualConfigCode()">
          </div>
          <div>
            <label class="form-label" style="font-size:0.775rem;">Access Key (ID:SECRET)</label>
            <input type="text" id="m-hestia-key" class="form-control" style="height:36px;" placeholder="ID:SECRET anahtarı" oninput="updateManualConfigCode()">
          </div>
        </div>
      </div>

      <!-- Live Code Preview Box -->
      <div style="margin-bottom:1rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.4rem;">
          <label style="font-weight:700;font-size:0.825rem;color:var(--text-main);display:flex;align-items:center;gap:0.4rem;">
            <span>📄</span> Üretilen config.php Dosya İçeriği (Canlı Önizleme)
          </label>
          <div style="display:flex;gap:0.4rem;">
            <button type="button" class="btn btn-secondary" onclick="copyManualConfigCode()" style="height:30px;padding:0 0.75rem;font-size:0.75rem;">
              📋 Kodu Kopyala
            </button>
            <button type="button" class="btn btn-secondary" onclick="downloadManualConfigFile()" style="height:30px;padding:0 0.75rem;font-size:0.75rem;color:#10b981;border-color:rgba(16,185,129,0.35);">
              📥 config.php Olarak İndir
            </button>
          </div>
        </div>
        <textarea id="manual-config-preview" readonly style="width:100%;height:180px;background:#090d16;color:#a5b4fc;border:1px solid var(--border-color);padding:0.75rem;font-family:'Courier New', monospace;font-size:0.775rem;line-height:1.5;box-sizing:border-box;resize:vertical;"></textarea>
      </div>

      <!-- SQL Table Schema Helper -->
      <div style="border:1px solid var(--border-color);background:var(--bg-surface-elevated);margin-bottom:1rem;">
        <button type="button" onclick="const s = document.getElementById('sql-schema-drawer'); s.style.display = s.style.display === 'none' ? 'block' : 'none';" style="width:100%;padding:0.6rem 0.85rem;background:transparent;border:none;color:var(--text-main);font-size:0.8rem;font-weight:600;display:flex;align-items:center;justify-content:space-between;cursor:pointer;">
          <span style="display:inline-flex;align-items:center;gap:0.4rem;">
            <span>🗄️</span> phpMyAdmin için SQL Tablolarını Görüntüle / İçe Aktar (İsteğe Bağlı)
          </span>
          <span style="font-size:0.75rem;color:var(--text-muted);">▼ Göster / Gizle</span>
        </button>
        <div id="sql-schema-drawer" style="display:none;padding:0.85rem;border-top:1px solid var(--border-color);background:#090d16;">
          <p style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.5rem;">
            Eğer hosting veritabanı kullanıcınızda otomatik tablo oluşturma yetkisi yoksa, aşağıdaki SQL kodunu phpMyAdmin'de <strong>SQL</strong> sekmesine yapıştırıp çalıştırabilirsiniz:
          </p>
          <textarea id="sql-schema-code" readonly style="width:100%;height:140px;background:#05070c;color:#6ee7b7;border:1px solid var(--border-color);padding:0.6rem;font-family:'Courier New', monospace;font-size:0.75rem;line-height:1.4;box-sizing:border-box;resize:vertical;"></textarea>
          <div style="margin-top:0.4rem;display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="copySqlSchemaCode()" style="height:28px;font-size:0.725rem;padding:0 0.65rem;">
              📋 SQL Kodunu Kopyala
            </button>
          </div>
        </div>
      </div>

    </div>

    <!-- Modal Footer -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding-top:1rem;border-top:1px solid var(--border-color);flex-shrink:0;">
      <button type="button" class="btn btn-secondary" onclick="closeManualConfigModal()" style="height:38px;padding:0 1.25rem;">Kapat</button>
      <div style="display:flex;gap:0.5rem;">
        <button type="button" class="btn btn-secondary" onclick="downloadManualConfigFile()" style="height:38px;padding:0 1.25rem;color:#10b981;border-color:rgba(16,185,129,0.35);">
          📥 config.php İndir
        </button>
        <button type="button" id="btn-manual-save-server" class="btn btn-primary" onclick="saveManualConfigToServer()" style="height:38px;padding:0 1.5rem;">
          💾 Sunucuya Kaydet (config.php Üzerine Yaz)
        </button>
      </div>
    </div>

  </div>
</div>

</body>
</html>
