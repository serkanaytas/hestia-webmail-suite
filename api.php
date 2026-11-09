<?php
/**
 * Hestia Webmail Suite - Standalone API Engine
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');

// Session & HTTP Security Hardening
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

session_start();

// Helper: IP + Action File-based Rate Limiter (Brute-force protection)
function check_rate_limit($actionKey, $maxAttempts = 8, $windowSeconds = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_rl_' . md5($actionKey . '_' . $ip) . '.json';
    $now = time();
    $attempts = [];
    if (@file_exists($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw ? @json_decode($raw, true) : [];
        if (is_array($decoded)) {
            foreach ($decoded as $ts) {
                if (($now - (int)$ts) < $windowSeconds) {
                    $attempts[] = (int)$ts;
                }
            }
        }
    }
    if (count($attempts) >= $maxAttempts) {
        $waitSec = max(1, $windowSeconds - ($now - min($attempts)));
        $waitMin = ceil($waitSec / 60);
        echo json_encode([
            'success' => false,
            'message' => "Güvenlik Koruması: Çok fazla başarısız deneme yapıldı. Lütfen {$waitMin} dakika bekleyip tekrar deneyin."
        ]);
        exit;
    }
    $attempts[] = $now;
    @file_put_contents($file, json_encode($attempts), LOCK_EX);
}

function clear_rate_limit($actionKey) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_rl_' . md5($actionKey . '_' . $ip) . '.json';
    if (@file_exists($file)) {
        @unlink($file);
    }
}

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+03:00'");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()]);
    exit;
}

// Load PHPMailer via Composer if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Concurrency optimization:
// Release session lock immediately for actions that do not modify session state.
// This prevents slow IMAP operations (like folder count sync or message fetches) from freezing
// disk operations, calendar, contacts, notes or tasks.
$session_write_actions = [
    'login', 'logout', 'switch_account', 'change_active_account', 'verify_2fa', 'setup_2fa'
];
if (!in_array($action, $session_write_actions) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Helper: Fast file-based schema verification cache (avoids running DDL/SHOW COLUMNS on every poll)
function is_schema_verified($schemaKey, $ttl = 3600) {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_schema_' . md5(DB_NAME . '_' . $schemaKey) . '.ok';
    return @file_exists($file) && (time() - @filemtime($file) < $ttl);
}

function mark_schema_verified($schemaKey) {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_schema_' . md5(DB_NAME . '_' . $schemaKey) . '.ok';
    @file_put_contents($file, (string)time(), LOCK_EX);
}

// Helper: Ensure Drive / Cloud Storage tables exist
function ensure_drive_tables($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('drive_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
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
        ");
        
        $driveDir = __DIR__ . '/uploads/drive';
        if (!is_dir($driveDir)) {
            @mkdir($driveDir, 0777, true);
        }
        mark_schema_verified('drive_v1');
    } catch (Exception $e) {}
}

// Helper: Ensure mail_accounts table has required columns (self-healing migration)
function ensure_mail_accounts_columns($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('mail_accounts_v1')) return;
    $checked = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM mail_accounts")->fetchAll(PDO::FETCH_COLUMN);
        $colMap = array_map('strtolower', $cols);
        
        if (!in_array('updated_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!in_array('created_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP");
        }
        if (!in_array('is_admin', $colMap)) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN `is_admin` TINYINT(1) DEFAULT 0");
        }
        if (!in_array('role', $colMap)) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN `role` VARCHAR(20) DEFAULT 'user'");
        }
        mark_schema_verified('mail_accounts_v1');
    } catch (Exception $e) {}
}

// Helper: Ensure user_tasks table exists and has all required columns
function ensure_tasks_tables($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('tasks_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
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
              KEY `idx_acc_task` (`account_id`),
              KEY `idx_task_comp` (`is_completed`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        $cols = $pdo->query("SHOW COLUMNS FROM user_tasks")->fetchAll(PDO::FETCH_COLUMN);
        $colMap = array_map('strtolower', $cols);

        // 1. account_id
        if (!in_array('account_id', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `account_id` int(11) NOT NULL DEFAULT 0 AFTER `id`");
        }

        // 2. title
        if (!in_array('title', $colMap)) {
            if (in_array('task_name', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `task_name` `title` varchar(255) NOT NULL DEFAULT ''");
            } elseif (in_array('name', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `name` `title` varchar(255) NOT NULL DEFAULT ''");
            } else {
                $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `title` varchar(255) NOT NULL DEFAULT '' AFTER `account_id`");
            }
        }

        // 3. description
        if (!in_array('description', $colMap)) {
            if (in_array('details', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `details` `description` text DEFAULT NULL");
            } elseif (in_array('desc', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `desc` `description` text DEFAULT NULL");
            } elseif (in_array('note', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `note` `description` text DEFAULT NULL");
            } elseif (in_array('content', $colMap)) {
                $pdo->exec("ALTER TABLE user_tasks CHANGE COLUMN `content` `description` text DEFAULT NULL");
            } else {
                $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `description` text DEFAULT NULL AFTER `title`");
            }
        }

        // 4. due_date
        if (!in_array('due_date', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `due_date` date DEFAULT NULL AFTER `description`");
        }

        // 5. priority
        if (!in_array('priority', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `priority` varchar(20) DEFAULT 'medium' AFTER `due_date`");
        }

        // 6. is_completed
        if (!in_array('is_completed', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `is_completed` tinyint(1) DEFAULT 0 AFTER `priority`");
        }

        // 7. completed_at
        if (!in_array('completed_at', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `completed_at` datetime DEFAULT NULL AFTER `is_completed`");
        }

        // 8. created_at
        if (!in_array('created_at', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `created_at` datetime DEFAULT current_timestamp()");
        }

        // 9. updated_at
        if (!in_array('updated_at', $colMap)) {
            $pdo->exec("ALTER TABLE user_tasks ADD COLUMN `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()");
        }
        mark_schema_verified('tasks_v1');
    } catch (Throwable $e) {
        error_log("ensure_tasks_tables error: " . $e->getMessage());
    }
}

// Helper: Ensure user_notes table exists and has all required columns
function ensure_notes_tables($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('notes_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_notes` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `account_id` int(11) NOT NULL DEFAULT 0,
              `title` varchar(255) NOT NULL DEFAULT 'Başlıksız Not',
              `content` longtext DEFAULT NULL,
              `color` varchar(20) DEFAULT '#fef08a',
              `is_pinned` tinyint(1) DEFAULT 0,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_acc_notes` (`account_id`),
              KEY `idx_note_pinned` (`is_pinned`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $cols = $pdo->query("SHOW COLUMNS FROM user_notes")->fetchAll(PDO::FETCH_COLUMN);
        $colMap = array_map('strtolower', $cols);

        if (!in_array('account_id', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `account_id` int(11) NOT NULL DEFAULT 0 AFTER `id`");
        }
        if (!in_array('title', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `title` varchar(255) NOT NULL DEFAULT 'Başlıksız Not' AFTER `account_id`");
        }
        if (!in_array('content', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `content` longtext DEFAULT NULL AFTER `title`");
        }
        if (!in_array('color', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `color` varchar(20) DEFAULT '#fef08a' AFTER `content`");
        }
        if (!in_array('is_pinned', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `is_pinned` tinyint(1) DEFAULT 0 AFTER `color`");
        }
        if (!in_array('created_at', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `created_at` datetime DEFAULT current_timestamp()");
        }
        if (!in_array('updated_at', $colMap)) {
            $pdo->exec("ALTER TABLE user_notes ADD COLUMN `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()");
        }
        mark_schema_verified('notes_v1');
    } catch (Throwable $e) {
        error_log("ensure_notes_tables error: " . $e->getMessage());
    }
}

// Helper: Ensure domain chat tables and default channels exist
function ensure_chat_tables($pdo, $domain = '') {
    static $checked = false;
    if ($checked || is_schema_verified('chat_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `chat_conversations` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `type` enum('direct','group') NOT NULL DEFAULT 'direct',
              `domain` varchar(150) NOT NULL,
              `title` varchar(255) DEFAULT NULL,
              `created_by` varchar(255) NOT NULL,
              `last_message` text DEFAULT NULL,
              `last_message_sender` varchar(150) DEFAULT NULL,
              `last_message_at` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_domain_type` (`domain`,`type`),
              KEY `idx_last_msg_at` (`last_message_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `chat_participants` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `conversation_id` int(11) NOT NULL,
              `email` varchar(255) NOT NULL,
              `name` varchar(150) DEFAULT NULL,
              `role` varchar(20) DEFAULT 'member',
              `last_read_message_id` int(11) DEFAULT 0,
              `unread_count` int(11) DEFAULT 0,
              `is_muted` tinyint(1) DEFAULT 0,
              `joined_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_conv_email` (`conversation_id`,`email`),
              KEY `idx_chat_email` (`email`),
              KEY `idx_chat_conv` (`conversation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `chat_messages` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `conversation_id` int(11) NOT NULL,
              `sender_email` varchar(255) NOT NULL,
              `sender_name` varchar(150) NOT NULL,
              `message` longtext DEFAULT NULL,
              `attachment_url` varchar(255) DEFAULT NULL,
              `attachment_name` varchar(255) DEFAULT NULL,
              `attachment_size` int(11) DEFAULT NULL,
              `attachment_type` varchar(100) DEFAULT NULL,
              `is_deleted` tinyint(1) DEFAULT 0,
              `is_pinned` tinyint(1) DEFAULT 0,
              `reactions` text DEFAULT NULL,
              `created_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_msg_conv_created` (`conversation_id`,`created_at`),
              KEY `idx_msg_sender` (`sender_email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `chat_presence` (
              `email` varchar(255) NOT NULL,
              `domain` varchar(150) NOT NULL,
              `name` varchar(150) DEFAULT NULL,
              `status` varchar(20) DEFAULT 'online',
              `custom_status` varchar(255) DEFAULT NULL,
              `typing_in` int(11) DEFAULT 0,
              `typing_at` datetime DEFAULT NULL,
              `last_seen` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`email`),
              KEY `idx_pres_domain` (`domain`,`status`),
              KEY `idx_pres_seen` (`last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `chat_removed_members` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `conversation_id` int(11) NOT NULL,
              `email` varchar(255) NOT NULL,
              `removed_by` varchar(255) NOT NULL,
              `removed_at` datetime DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_conv_email_rem` (`conversation_id`,`email`),
              KEY `idx_rem_conv` (`conversation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `chat_hidden_colleagues` (
              `domain` varchar(150) NOT NULL,
              `email` varchar(255) NOT NULL,
              `hidden_by` varchar(255) DEFAULT '',
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`domain`, `email`),
              KEY `idx_domain_hidden` (`domain`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $chatUploadDir = __DIR__ . '/uploads/chat';
        if (!is_dir($chatUploadDir)) {
            @mkdir($chatUploadDir, 0777, true);
        }

        if (!empty($domain)) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `chat_settings` (
                    `setting_key` varchar(100) NOT NULL PRIMARY KEY,
                    `setting_val` text DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
        mark_schema_verified('chat_v1');
    } catch (Throwable $e) {
        error_log("ensure_chat_tables error: " . $e->getMessage());
    }
}

// Helper: Get domain colleagues with real-time presence and conversation info
function get_domain_colleagues($pdo, $domain, $myEmail) {
    $colleagues = [];
    $myEmail = strtolower(trim($myEmail));
    $domain = strtolower(trim($domain));
    if (empty($domain)) return [];

    // Hidden colleagues map for this domain (hidden by admin)
    $hiddenMap = [];
    try {
        $stmtH = $pdo->prepare("SELECT LOWER(email) FROM chat_hidden_colleagues WHERE domain = ?");
        $stmtH->execute([$domain]);
        while ($hEm = $stmtH->fetchColumn()) {
            $hiddenMap[strtolower(trim($hEm))] = true;
        }
    } catch (Throwable $e) {}

    // 1. Gather users ONLY from active mail_accounts (accounts with configured credentials/logged in)
    try {
        $stmtA = $pdo->prepare("
            SELECT email, name, role, is_admin 
            FROM mail_accounts 
            WHERE LOWER(email) LIKE ? 
              AND imap_password IS NOT NULL 
              AND imap_password != ''
            ORDER BY name ASC, email ASC
        ");
        $stmtA->execute(['%@' . $domain]);
        while ($r = $stmtA->fetch(PDO::FETCH_ASSOC)) {
            $em = strtolower(trim($r['email']));
            if ($em === $myEmail) continue;
            if (isset($hiddenMap[$em])) continue; // Excluded by admin
            $colleagues[$em] = [
                'email' => $em,
                'name' => trim($r['name'] ?: explode('@', $em)[0]),
                'role' => $r['role'] ?? 'user',
                'is_admin' => (int)($r['is_admin'] ?? 0)
            ];
        }
    } catch (Throwable $e) {}

    if (empty($colleagues)) return [];

    // 2. Fetch presence for domain users
    $presenceMap = [];
    try {
        $stmtP = $pdo->prepare("SELECT email, status, custom_status, last_seen, (CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END) as is_online FROM chat_presence WHERE domain = ?");
        $stmtP->execute([$domain]);
        while ($p = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            $em = strtolower(trim($p['email']));
            $presenceMap[$em] = $p;
        }
    } catch (Throwable $e) {}

    // 3. Check existing direct conversations with $myEmail
    $directMap = [];
    try {
        $stmtC = $pdo->prepare("
            SELECT c.id as conversation_id, c.last_message, c.last_message_at, p2.email as other_email, p1.unread_count
            FROM chat_conversations c
            JOIN chat_participants p1 ON c.id = p1.conversation_id AND p1.email = ?
            JOIN chat_participants p2 ON c.id = p2.conversation_id AND p2.email != ?
            WHERE c.type = 'direct'
        ");
        $stmtC->execute([$myEmail, $myEmail]);
        while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $em = strtolower(trim($c['other_email']));
            $directMap[$em] = $c;
        }
    } catch (Throwable $e) {}

    // Format final list
    $result = [];
    foreach ($colleagues as $em => $col) {
        $p = $presenceMap[$em] ?? null;
        $isOnline = $p ? (int)$p['is_online'] : 0;
        $status = 'offline';
        if ($isOnline) {
            $status = in_array($p['status'] ?? '', ['online', 'away', 'busy']) ? $p['status'] : 'online';
        }

        $directInfo = $directMap[$em] ?? null;

        $result[] = [
            'email' => $em,
            'name' => $col['name'],
            'role' => $col['role'],
            'is_admin' => $col['is_admin'],
            'status' => $status,
            'custom_status' => $p['custom_status'] ?? '',
            'last_seen' => $p['last_seen'] ?? null,
            'conversation_id' => $directInfo ? (int)$directInfo['conversation_id'] : 0,
            'unread_count' => $directInfo ? (int)$directInfo['unread_count'] : 0,
            'last_message' => $directInfo['last_message'] ?? '',
            'last_message_at' => $directInfo['last_message_at'] ?? null,
        ];
    }

    // Sort: unread first, then online first, then alphabetical by name
    usort($result, function($a, $b) {
        if ($a['unread_count'] !== $b['unread_count']) {
            return $b['unread_count'] <=> $a['unread_count'];
        }
        $scoreA = ($a['status'] === 'online') ? 3 : (($a['status'] === 'away') ? 2 : (($a['status'] === 'busy') ? 1 : 0));
        $scoreB = ($b['status'] === 'online') ? 3 : (($b['status'] === 'away') ? 2 : (($b['status'] === 'busy') ? 1 : 0));
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $result;
}

// Helper: Ensure mail_filters table exists and has all required columns
function ensure_filters_tables($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('filters_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
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
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $cols = $pdo->query("SHOW COLUMNS FROM mail_filters")->fetchAll(PDO::FETCH_COLUMN);
        $colMap = array_map('strtolower', $cols);

        // 1. account_id
        if (!in_array('account_id', $colMap)) {
            $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `account_id` int(11) DEFAULT 0 AFTER `id`");
        }

        // 2. filter_name (migrate from legacy rule_name or name if present)
        if (!in_array('filter_name', $colMap)) {
            if (in_array('rule_name', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `rule_name` `filter_name` varchar(150) NOT NULL DEFAULT ''");
            } elseif (in_array('name', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `name` `filter_name` varchar(150) NOT NULL DEFAULT ''");
            } else {
                $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `filter_name` varchar(150) NOT NULL DEFAULT '' AFTER `account_id`");
            }
        }

        // 3. match_field (migrate from field if present)
        if (!in_array('match_field', $colMap)) {
            if (in_array('field', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `field` `match_field` varchar(50) NOT NULL DEFAULT 'from'");
            } else {
                $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `match_field` varchar(50) NOT NULL DEFAULT 'from'");
            }
        }

        // 4. match_type (migrate from condition_type if present)
        if (!in_array('match_type', $colMap)) {
            if (in_array('condition_type', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `condition_type` `match_type` varchar(50) NOT NULL DEFAULT 'contains'");
            } else {
                $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `match_type` varchar(50) NOT NULL DEFAULT 'contains'");
            }
        }

        // 5. match_value (migrate from keyword if present)
        if (!in_array('match_value', $colMap)) {
            if (in_array('keyword', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `keyword` `match_value` varchar(255) NOT NULL DEFAULT ''");
            } else {
                $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `match_value` varchar(255) NOT NULL DEFAULT ''");
            }
        }

        // 6. action_type
        if (!in_array('action_type', $colMap)) {
            $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `action_type` varchar(50) NOT NULL DEFAULT 'move'");
        }

        // 7. action_target (migrate from action_value if present)
        if (!in_array('action_target', $colMap)) {
            if (in_array('action_value', $colMap)) {
                $pdo->exec("ALTER TABLE mail_filters CHANGE COLUMN `action_value` `action_target` varchar(255) DEFAULT NULL");
            } else {
                $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `action_target` varchar(255) DEFAULT NULL");
            }
        }

        // 8. is_active
        if (!in_array('is_active', $colMap)) {
            $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `is_active` tinyint(1) DEFAULT 1");
        }

        // 9. created_at
        if (!in_array('created_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `created_at` datetime DEFAULT current_timestamp()");
        }

        // 10. updated_at
        if (!in_array('updated_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_filters ADD COLUMN `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()");
        }
        mark_schema_verified('filters_v1');
    } catch (Throwable $e) {
        error_log("ensure_filters_tables error: " . $e->getMessage());
    }
}

// Helper: Ensure mail_imports table exists with all required columns
function ensure_mail_imports_table($pdo) {
    static $checked = false;
    if ($checked || is_schema_verified('mail_imports_v1')) return;
    $checked = true;
    try {
        $pdo->exec("
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

        $cols = $pdo->query("SHOW COLUMNS FROM mail_imports")->fetchAll(PDO::FETCH_COLUMN);
        $colMap = array_map('strtolower', $cols);

        // 1. Detect and heal/drop any legacy columns (e.g. folder_name, uid from old schemas)
        try {
            try { $pdo->exec("ALTER TABLE mail_imports DROP INDEX `acc_fld_uid`"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE mail_imports DROP INDEX `idx_acc_folder`"); } catch (Throwable $e) {}
            if (in_array('folder_name', $colMap)) {
                try { $pdo->exec("ALTER TABLE mail_imports DROP COLUMN `folder_name`"); } catch (Throwable $e) {
                    try { $pdo->exec("ALTER TABLE mail_imports MODIFY COLUMN `folder_name` varchar(255) NULL DEFAULT NULL"); } catch (Throwable $e2) {}
                }
            }
            if (in_array('uid', $colMap)) {
                try { $pdo->exec("ALTER TABLE mail_imports DROP COLUMN `uid`"); } catch (Throwable $e) {
                    try { $pdo->exec("ALTER TABLE mail_imports MODIFY COLUMN `uid` int(11) NULL DEFAULT NULL"); } catch (Throwable $e2) {}
                }
            }

            $rawCols = $pdo->query("SHOW FULL COLUMNS FROM mail_imports")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawCols as $rc) {
                $field = $rc['Field'];
                if ($rc['Null'] === 'NO' && $rc['Default'] === null && $rc['Extra'] !== 'auto_increment' && !in_array(strtolower($field), ['account_id', 'source_email', 'source_host'])) {
                    try {
                        $pdo->exec("ALTER TABLE mail_imports MODIFY COLUMN `{$field}` {$rc['Type']} NULL DEFAULT NULL");
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}

        if (!in_array('source_email', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_email` varchar(255) NOT NULL DEFAULT '' AFTER `account_id`");
        }
        if (!in_array('source_password', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_password` text DEFAULT NULL AFTER `source_email`");
        }
        if (!in_array('source_host', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_host` varchar(255) NOT NULL DEFAULT '' AFTER `source_password`");
        }
        if (!in_array('source_port', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_port` int(11) DEFAULT 993 AFTER `source_host`");
        }
        if (!in_array('source_encryption', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_encryption` varchar(20) DEFAULT 'ssl' AFTER `source_port`");
        }
        if (!in_array('source_folder', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `source_folder` varchar(255) DEFAULT 'ALL' AFTER `source_encryption`");
        }
        if (!in_array('target_mode', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `target_mode` varchar(20) DEFAULT 'same' AFTER `source_folder`");
        }
        if (!in_array('custom_target_folder', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `custom_target_folder` varchar(255) DEFAULT 'Eski_Postalar' AFTER `target_mode`");
        }
        if (!in_array('date_filter', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `date_filter` varchar(50) DEFAULT 'all' AFTER `custom_target_folder`");
        }
        if (!in_array('limit_count', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `limit_count` int(11) DEFAULT 0 AFTER `date_filter`");
        }
        if (!in_array('skip_duplicates', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `skip_duplicates` tinyint(4) DEFAULT 1 AFTER `limit_count`");
        }
        if (!in_array('target_folder', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `target_folder` varchar(255) DEFAULT 'INBOX' AFTER `skip_duplicates`");
        }
        if (!in_array('current_folder', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `current_folder` varchar(255) DEFAULT NULL AFTER `target_folder`");
        }
        if (!in_array('progress_percent', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `progress_percent` int(11) DEFAULT 0 AFTER `current_folder`");
        }
        if (!in_array('pid', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `pid` int(11) DEFAULT NULL AFTER `progress_percent`");
        }
        if (!in_array('imported_count', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `imported_count` int(11) DEFAULT 0 AFTER `pid`");
        }
        if (!in_array('total_found', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `total_found` int(11) DEFAULT 0 AFTER `imported_count`");
        }
        if (!in_array('skipped_count', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `skipped_count` int(11) DEFAULT 0 AFTER `total_found`");
        }
        if (!in_array('failed_count', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `failed_count` int(11) DEFAULT 0 AFTER `skipped_count`");
        }
        if (!in_array('status', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `status` varchar(50) DEFAULT 'pending' AFTER `skipped_count`");
        }
        if (!in_array('error_message', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `error_message` text DEFAULT NULL AFTER `status`");
        }
        if (!in_array('created_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `created_at` datetime DEFAULT current_timestamp()");
        }
        if (!in_array('updated_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()");
        }
        if (!in_array('completed_at', $colMap)) {
            $pdo->exec("ALTER TABLE mail_imports ADD COLUMN `completed_at` datetime DEFAULT NULL");
        }
        mark_schema_verified('mail_imports_v1');
    } catch (Throwable $e) {
        error_log('ensure_mail_imports_table error: ' . $e->getMessage());
    }
}

// Helper: Get active account
function get_active_account($pdo) {
    if (empty($_SESSION['mail_logged_in']) || empty($_SESSION['active_account_id'])) {
        return null;
    }
    $acc_id = (int)$_SESSION['active_account_id'];
    $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
    $stmt->execute([$acc_id]);
    return $stmt->fetch() ?: null;
}

// Helper: Safely decode IMAP modified UTF-7 folder name to UTF-8
function decode_imap_folder_name($str) {
    if ($str === null || $str === '') return '';
    // Only decode if string actually contains IMAP UTF-7 markers (&...-)
    if (strpos($str, '&') !== false && preg_match('/&[A-Za-z0-9+,]*-/', $str)) {
        if (function_exists('mb_convert_encoding')) {
            // If string is pure ASCII, mb_convert_encoding can decode standard IMAP UTF-7
            if (!preg_match('/[^\x00-\x7F]/', $str)) {
                $dec = @mb_convert_encoding($str, 'UTF-8', 'UTF7-IMAP');
                if ($dec !== false && $dec !== '' && strpos($dec, '?') === false) {
                    return $dec;
                }
            }
        }
        // Fallback: decode &...- blocks individually so existing UTF-8 characters are never damaged
        return preg_replace_callback('/&([A-Za-z0-9+,]+)?-/', function($m) {
            if (empty($m[1])) return '&';
            $b64 = str_replace(',', '/', $m[1]);
            $pad = strlen($b64) % 4;
            if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
            $bin = base64_decode($b64, true);
            if ($bin !== false && strlen($bin) > 0) {
                $conv = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
                if ($conv !== false && $conv !== '') return $conv;
            }
            return $m[0];
        }, $str);
    }
    return $str;
}

// Helper: Safely encode UTF-8 folder name to IMAP modified UTF-7 (prevents double-encoding)
function encode_imap_folder_name($str) {
    if ($str === null || $str === '') return '';
    // First decode any existing mUTF-7 blocks so we never double-encode '&...-' into '&-...-'
    $cleanUtf8 = decode_imap_folder_name($str);
    if (function_exists('mb_convert_encoding')) {
        $enc = @mb_convert_encoding($cleanUtf8, 'UTF7-IMAP', 'UTF-8');
        if ($enc !== false && $enc !== '') return $enc;
    }
    return $str;
}

// Helper: Track explicitly deleted custom folders per account so IMAP sync never resurrects them
function get_deleted_folders_map($acc_id) {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_del_folders_' . (int)$acc_id . '.cache';
    if (@file_exists($file)) {
        $data = @json_decode(@file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_deleted_folders_map($acc_id, array $map) {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wm_del_folders_' . (int)$acc_id . '.cache';
    @file_put_contents($file, json_encode($map), LOCK_EX);
}

// Helper: Require authenticated administrator account
function require_admin($pdo) {
    $acc = get_active_account($pdo);
    if (!$acc) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapınız.']);
        exit;
    }
    if (empty($acc->is_admin)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Bu işlem için yönetici yetkisi gereklidir.']);
        exit;
    }
    return $acc;
}

// Helper: Get HestiaCP configuration from config.php or mail_settings database
function get_hestia_config($pdo = null) {
    $host = defined('HESTIA_API_HOST') ? HESTIA_API_HOST : '';
    $user = defined('HESTIA_API_USER') ? HESTIA_API_USER : 'admin';
    $key  = defined('HESTIA_API_KEY') ? HESTIA_API_KEY : '';
    $server_mode = defined('HESTIA_SERVER_MODE') ? HESTIA_SERVER_MODE : 'remote'; // 'remote' or 'local'
    $cli_path = defined('HESTIA_CLI_PATH') ? HESTIA_CLI_PATH : '/usr/local/hestia/bin';
    $use_sudo = defined('HESTIA_USE_SUDO') ? HESTIA_USE_SUDO : true;

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('hestia_api_host', 'hestia_api_user', 'hestia_api_key', 'hestia_server_mode', 'hestia_cli_path', 'hestia_use_sudo')");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($r['setting_key'] === 'hestia_api_host' && !empty($r['setting_value'])) $host = $r['setting_value'];
                if ($r['setting_key'] === 'hestia_api_user' && !empty($r['setting_value'])) $user = $r['setting_value'];
                if ($r['setting_key'] === 'hestia_api_key' && !empty($r['setting_value'])) $key = $r['setting_value'];
                if ($r['setting_key'] === 'hestia_server_mode' && !empty($r['setting_value'])) $server_mode = $r['setting_value'];
                if ($r['setting_key'] === 'hestia_cli_path' && !empty($r['setting_value'])) $cli_path = $r['setting_value'];
                if ($r['setting_key'] === 'hestia_use_sudo') $use_sudo = ($r['setting_value'] === '1' || $r['setting_value'] === 1 || $r['setting_value'] === true || $r['setting_value'] === '1');
            }
        } catch (Exception $e) {}
    }

    $is_local_installed = @is_executable(rtrim($cli_path, '/') . '/v-list-mail-accounts') || @file_exists(rtrim($cli_path, '/') . '/v-list-mail-accounts');

    // In local mode: configured if local CLI is available or user explicitly selected local mode
    // In remote mode: configured if host and key exist (100% existing behaviour)
    $is_configured = ($server_mode === 'local') ? true : (!empty($host) && !empty($key));

    return [
        'host' => rtrim($host, '/'),
        'user' => $user ?: 'admin',
        'key'  => $key,
        'server_mode' => $server_mode,
        'cli_path' => rtrim($cli_path, '/'),
        'use_sudo' => (bool)$use_sudo,
        'is_local_installed' => (bool)$is_local_installed,
        'is_configured' => $is_configured
    ];
}

// Helper: Execute HestiaCP command via direct local CLI (Aynı Sunucu / Yerel Mod)
function execute_hestia_cli($cmd, $args = [], $useSudo = true, $cliPath = '/usr/local/hestia/bin', $returnCodeOnly = false) {
    $cleanCmd = basename($cmd);
    $binary = rtrim($cliPath, '/') . '/' . $cleanCmd;

    // Check if exec() is enabled in php.ini
    if (!function_exists('exec')) {
        return [
            'success' => false,
            'message' => 'PHP exec() fonksiyonu sunucunuzda devre dışı bırakılmış (php.ini disable_functions). Yerel CLI modu için exec() fonksiyonunu etkinleştiriniz veya Uzak Sunucu (API) moduna geçiniz.',
            'raw' => '',
            'mode' => 'local_cli'
        ];
    }

    // Check if running on Windows (where /usr/local/hestia/bin cannot exist)
    if (DIRECTORY_SEPARATOR === '\\') {
        if (!@file_exists($binary)) {
            return [
                'success' => false,
                'message' => "Şu an yerel Windows (XAMPP) ortamındasınız. '/usr/local/hestia/bin/' dizini yalnızca Linux sunucularda bulunmaktadır. Windows üzerinden test yaparken lütfen Yönetim Paneli -> Sunucu Ayarları bölümünden 'Uzak Sunucu (REST API)' modunu seçiniz veya projeyi doğrudan Linux sunucunuza yükleyiniz.",
                'raw' => '',
                'mode' => 'local_cli'
            ];
        }
    }

    // Build arguments list safely
    $argParts = [];
    foreach ($args as $a) {
        $argParts[] = escapeshellarg((string)$a);
    }
    $argStr = implode(' ', $argParts);

    // Get current process username for informative error messages
    $currentSystemUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'web-user') : get_current_user();

    // CRITICAL: MUST use 'sudo -n' (non-interactive). Never run 'sudo' without '-n' from a web server!
    // Without '-n', sudo will hang waiting for a password on stdin until Apache/Nginx gateway timeout!
    $runWithSudo = $useSudo && (DIRECTORY_SEPARATOR !== '\\');
    
    // Environment setup: Export HESTIA and set PATH
    $envPrefix = (DIRECTORY_SEPARATOR !== '\\') ? 'export HESTIA=/usr/local/hestia; export PATH=$PATH:/usr/local/hestia/bin; ' : '';
    
    // Check if timeout command is available to prevent any command hang
    $timeoutPrefix = (DIRECTORY_SEPARATOR !== '\\' && @is_executable('/usr/bin/timeout')) ? '/usr/bin/timeout 8 ' : '';
    
    $execCmd = $envPrefix . $timeoutPrefix . ($runWithSudo ? 'sudo -n ' : '') . escapeshellcmd($binary) . ($argStr !== '' ? ' ' . $argStr : '') . ' 2>&1';

    $output = [];
    $returnVar = 0;
    @exec($execCmd, $output, $returnVar);
    $res = trim(implode("\n", $output));

    // Fallback: If sudo failed due to password prompt or sudoers permission, try directly without sudo
    if ($runWithSudo && $returnVar !== 0 && (stripos($res, 'password is required') !== false || stripos($res, 'not allowed to run sudo') !== false || stripos($res, 'sudo: command not found') !== false || stripos($res, 'a terminal is required') !== false)) {
        $execCmdNoSudo = $envPrefix . $timeoutPrefix . escapeshellcmd($binary) . ($argStr !== '' ? ' ' . $argStr : '') . ' 2>&1';
        $outputNoSudo = [];
        $returnVarNoSudo = 0;
        @exec($execCmdNoSudo, $outputNoSudo, $returnVarNoSudo);
        if ($returnVarNoSudo === 0) {
            $output = $outputNoSudo;
            $returnVar = 0;
            $res = trim(implode("\n", $output));
        } else if (stripos($res, 'password is required') !== false || stripos($res, 'not allowed to run sudo') !== false) {
            $errMsg = "Web sunucu kullanıcısının ('{$currentSystemUser}') şifresiz sudo (NOPASSWD) yetkisi bulunmuyor.\n"
                    . "Çözüm: Sunucunuzda SSH terminalinden şu komutu çalıştırınız:\n"
                    . "echo '{$currentSystemUser} ALL=(ALL) NOPASSWD: /usr/local/hestia/bin/*' | sudo tee /etc/sudoers.d/hestia-webmail\n"
                    . "veya 'Komutları sudo ile çalıştır' kutucuğunun işaretini kaldırıp deneyiniz.";
            return [
                'success' => false,
                'message' => $errMsg,
                'raw' => $res,
                'return_code' => $returnVar,
                'mode' => 'local_cli'
            ];
        }
    }

    // Command not found check (Linux exit code 127)
    if ($returnVar === 127 || stripos($res, 'No such file or directory') !== false || (stripos($res, 'command not found') !== false && stripos($res, 'sudo') === false)) {
        return [
            'success' => false,
            'message' => "HestiaCP komut dosyası bu sunucuda bulunamadı: '{$binary}'. (Çıktı: {$res})",
            'raw' => $res,
            'return_code' => $returnVar,
            'mode' => 'local_cli'
        ];
    }

    // Exit code 0 indicates success in HestiaCP CLI scripts
    if ($returnVar === 0) {
        return [
            'success' => true,
            'raw' => ($res === '' && $returnCodeOnly ? '0' : $res),
            'http_code' => 200,
            'mode' => 'local_cli'
        ];
    }

    $errMsg = $res;
    if (empty($errMsg)) {
        $errMsg = "Hestia CLI komutu ({$cleanCmd}) hata kodu döndürdü (Kod: {$returnVar})";
    }

    return [
        'success' => false,
        'message' => $errMsg,
        'raw' => $res,
        'return_code' => $returnVar,
        'mode' => 'local_cli'
    ];
}

// Helper: Call HestiaCP REST API (via cURL to Port 8083)
function call_hestia_rest_api($conf, $cmd, $args = [], $returnCodeOnly = false) {
    if (empty($conf['host']) || empty($conf['key'])) {
        return ['success' => false, 'message' => 'HestiaCP API anahtarı veya sunucu adresi yapılandırılmamış.'];
    }

    $postData = [
        'cmd' => $cmd
    ];
    if ($returnCodeOnly) {
        $postData['returncode'] = 'yes';
    }

    $key = trim($conf['key']);
    if (strpos($key, ':') !== false) {
        list($kUser, $kSecret) = explode(':', $key, 2);
        $postData['access_key'] = trim($kUser);
        $postData['secret_key'] = trim($kSecret);
        $postData['user'] = trim($kUser);
        $postData['password'] = trim($kSecret);
    } else {
        $postData['user'] = $conf['user'];
        $postData['password'] = $key;
    }

    for ($i = 0; $i < count($args); $i++) {
        $postData['arg' . ($i + 1)] = $args[$i];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($conf['host'], '/') . '/api/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => 'CURL Bağlantı Hatası: ' . $err];
    }

    $cleanRes = trim($res);

    // HTTP Error Handling (401 Unauthorized, 403 Forbidden, 404, 500 vb.)
    if ($httpCode >= 400 || $httpCode === 0) {
        if ($httpCode === 401) {
            if ($cleanRes === '9' || stripos($cleanRes, "doesn't exist") !== false) {
                $msg = "HestiaCP API Yetki Hatası (401): API Anahtarı (Access Key) sunucuda bulunamadı veya geçersiz. Lütfen Yönetim Paneli -> Sunucu Ayarları bölümünden geçerli bir HestiaCP Access Key ID ve Secret Key giriniz.";
            } else {
                $msg = "HestiaCP API Yetkilendirme Hatası (401): Kullanıcı adı veya API anahtarı geçersiz. (" . ($cleanRes ?: 'Yetkisiz erişim') . ")";
            }
        } else if (stripos($cleanRes, 'IP is not allowed') !== false) {
            $msg = "HestiaCP API Hatası: Web sunucusu IP adresinin bu API anahtarına erişim izni yok. Hestia panelinde Access Key ayarlarında 'İzin Verilen IP Adresleri'ne 0.0.0.0/0 veya IP adresinizi ekleyiniz.";
        } else {
            $msg = "HestiaCP API Hatası (HTTP {$httpCode}): " . ($cleanRes ?: 'Sunucu yanıt vermedi');
        }
        return ['success' => false, 'message' => $msg, 'raw' => $res, 'http_code' => $httpCode];
    }

    if (strpos($cleanRes, 'Error:') !== false) {
        return ['success' => false, 'message' => $cleanRes, 'raw' => $res, 'http_code' => $httpCode];
    }

    if ($returnCodeOnly) {
        if ($cleanRes === '0' || $cleanRes === 'OK') {
            return ['success' => true, 'raw' => $res, 'http_code' => $httpCode];
        }

        $hestia_codes = [
            1 => 'Geçersiz parametre sayısı (E_ARGS)',
            2 => 'Geçersiz değer veya şifre çok zayıf (E_INVALID)',
            3 => 'Hesap veya alan adı HestiaCP üzerinde bulunamadı (E_NOTEXIST)',
            4 => 'Nesne zaten mevcut (E_EXISTS)',
            5 => 'Hesap askıya alınmış (E_SUSPENDED)',
            6 => 'Hesap aktif (E_UNSUSPENDED)',
            7 => 'Kaynak kullanımda (E_INUSE)',
            8 => 'Kullanıcı kotası veya limit aşıldı (E_LIMIT)',
            9 => 'Erişim reddedildi veya şifre kurallara uymuyor (E_PASSWORD)',
            10 => 'Erişim engellendi (E_FORBIDDEN)',
            11 => 'Posta servisi devre dışı (E_DISABLED)',
            12 => 'Konfigürasyon ayrıştırma hatası (E_PARSING)',
            13 => 'Disk alanı yetersiz (E_DISK)',
            14 => 'İşlem ertelendi (E_LAZY)',
            15 => 'Sunucu servisine bağlanılamadı (E_CONNECT)',
            16 => 'Mail servisi yeniden başlatılamadı (E_RESTART)'
        ];

        $codeNum = is_numeric($cleanRes) ? (int)$cleanRes : -1;
        $failDesc = $hestia_codes[$codeNum] ?? ($cleanRes ?: "Bilinmeyen hata kodu ({$codeNum})");

        return [
            'success' => false,
            'message' => "HestiaCP komut hatası: {$failDesc}",
            'raw' => $res,
            'return_code' => $codeNum,
            'http_code' => $httpCode
        ];
    }

    return ['success' => true, 'raw' => $res, 'http_code' => $httpCode];
}

// Helper: Call HestiaCP with Seamless Bidirectional Fallback between Local CLI and Remote REST API
function call_hestia_api($pdo, $cmd, $args = [], $returnCodeOnly = false) {
    $conf = get_hestia_config($pdo);

    // MODE 1: YEREL / AYNI SUNUCU MODU
    if ($conf['server_mode'] === 'local') {
        $cliRes = execute_hestia_cli($cmd, $args, $conf['use_sudo'], $conf['cli_path'], $returnCodeOnly);
        if ($cliRes['success']) {
            return $cliRes;
        }
        // Otomatik Yedek: Yerel CLI başarısız olursa ve REST API ayarları varsa REST API ile dene
        if (!empty($conf['host']) && !empty($conf['key'])) {
            $restRes = call_hestia_rest_api($conf, $cmd, $args, $returnCodeOnly);
            if ($restRes['success']) {
                return $restRes;
            }
        }
        return $cliRes;
    }

    // MODE 2: UZAK SUNUCU / REST API MODU
    $restRes = call_hestia_rest_api($conf, $cmd, $args, $returnCodeOnly);
    if ($restRes['success']) {
        return $restRes;
    }
    // Otomatik Yedek: REST API başarısız olursa ve Linux ortamında ise yerel CLI ile dene
    if (DIRECTORY_SEPARATOR !== '\\') {
        $cliRes = execute_hestia_cli($cmd, $args, $conf['use_sudo'], $conf['cli_path'], $returnCodeOnly);
        if ($cliRes['success']) {
            return $cliRes;
        }
    }
    return $restRes;
}

// Helper: Fetch all mail accounts from HestiaCP for a domain (with 60s cache + stale fallback)
function fetch_hestia_mail_accounts($pdo, $domain, $force = false) {
    static $staticCache = [];
    if (!$force && isset($staticCache[$domain])) {
        return $staticCache[$domain];
    }

    $cacheDir = __DIR__ . '/uploads/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/hestia_mail_' . md5($domain) . '.json';

    // 1. Fresh cache if under 60 seconds old
    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 60)) {
        $cachedData = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cachedData)) {
            $staticCache[$domain] = $cachedData;
            return $cachedData;
        }
    }

    $conf = get_hestia_config($pdo);
    if (!$conf['is_configured']) {
        return null;
    }

    $data = null;
    // Try format 1: arg1 = user, arg2 = domain, arg3 = json
    $res1 = call_hestia_api($pdo, 'v-list-mail-accounts', [$conf['user'], $domain, 'json'], false);
    if ($res1['success']) {
        $parsed = @json_decode($res1['raw'], true);
        if (is_array($parsed)) $data = $parsed;
    }

    // Only try format 2 if format 1 didn't fail with a connection error
    if (!$data && (!isset($res1['message']) || strpos($res1['message'], 'CURL Hatası') === false)) {
        // Try format 2: arg1 = domain, arg2 = json
        $res2 = call_hestia_api($pdo, 'v-list-mail-accounts', [$domain, 'json'], false);
        if ($res2['success']) {
            $parsed = @json_decode($res2['raw'], true);
            if (is_array($parsed)) $data = $parsed;
        }
    }

    if (is_array($data)) {
        $staticCache[$domain] = $data;
        @file_put_contents($cacheFile, json_encode($data));
        return $data;
    }

    // Fallback: If network connection failed or server timed out, return stale cache (up to 24 hours old)
    if (file_exists($cacheFile)) {
        $staleData = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($staleData)) {
            $staticCache[$domain] = $staleData;
            return $staleData;
        }
    }

    return null;
}

// Helper: Sync mail accounts from HestiaCP into local database
function sync_hestia_accounts_to_db($pdo, $domain = null, $force = false) {
    if (!$domain) {
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';
    }
    if (!$domain) return ['success' => false, 'message' => 'Alan adı (domain) tanımlanmamış.'];

    $conf = get_hestia_config($pdo);
    if (!$conf['is_configured']) {
        return ['success' => false, 'message' => 'HestiaCP API yapılandırılmamış.'];
    }

    $hestiaData = fetch_hestia_mail_accounts($pdo, $domain, $force);
    if (!is_array($hestiaData)) {
        return ['success' => false, 'message' => 'HestiaCP sunucusundan hesap verileri alınamadı. Port 8083 ve API anahtarını kontrol ediniz.'];
    }

    $imap_host = defined('MAIL_IMAP_HOST') ? MAIL_IMAP_HOST : (defined('IMAP_HOST') ? IMAP_HOST : 'mail.' . $domain);
    $imap_port = defined('MAIL_IMAP_PORT') ? MAIL_IMAP_PORT : 993;
    $imap_enc  = defined('MAIL_IMAP_ENCRYPTION') ? MAIL_IMAP_ENCRYPTION : 'ssl';
    $smtp_host = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : (defined('SMTP_HOST') ? SMTP_HOST : 'mail.' . $domain);
    $smtp_port = defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 587;
    $smtp_enc  = defined('MAIL_SMTP_ENCRYPTION') ? MAIL_SMTP_ENCRYPTION : 'tls';

    $added = 0;
    $synced = 0;

    foreach ($hestiaData as $accKey => $info) {
        $accUser = trim($accKey);
        if (strpos($accUser, '@') !== false) {
            $fullEmail = strtolower($accUser);
            $localPart = explode('@', $accUser)[0];
        } else {
            $localPart = $accUser;
            $fullEmail = strtolower($accUser . '@' . $domain);
        }

        // Formatted human readable name
        $displayName = ucwords(str_replace(['.', '_', '-'], ' ', $localPart));

        // Check if already in mail_accounts
        $chk = $pdo->prepare("SELECT id, name FROM mail_accounts WHERE LOWER(email) = ?");
        $chk->execute([$fullEmail]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        $createdAt = date('Y-m-d H:i:s');
        if (!empty($info['DATE'])) {
            $timePart = !empty($info['TIME']) ? $info['TIME'] : '00:00:00';
            $createdAt = $info['DATE'] . ' ' . $timePart;
        }

        if (!$existing) {
            $ins = $pdo->prepare("INSERT INTO mail_accounts (name, email, imap_host, imap_port, imap_encryption, imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, is_default, is_admin, role, created_at) VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, '', 0, 0, 'user', ?)");
            $ins->execute([
                $displayName,
                $fullEmail,
                $imap_host,
                $imap_port,
                $imap_enc,
                $fullEmail,
                $smtp_host,
                $smtp_port,
                $smtp_enc,
                $fullEmail,
                $createdAt
            ]);
            $added++;

            // Sync with domain_directory
            try {
                $dirStmt = $pdo->prepare("INSERT INTO domain_directory (name, email, role) VALUES (?, ?, 'user') ON DUPLICATE KEY UPDATE name = VALUES(name)");
                $dirStmt->execute([$displayName, $fullEmail]);
            } catch (Exception $e) {}
        }
        $synced++;
    }

    return [
        'success' => true,
        'synced_count' => $synced,
        'added_count' => $added,
        'hestia_accounts' => $hestiaData
    ];
}

// Helper: Safe UTF7-IMAP encoder (prevents double-encoding)
function safe_utf7_encode($folder) {
    if (empty($folder) || strtoupper($folder) === 'INBOX') return 'INBOX';
    if (!function_exists('mb_convert_encoding')) return $folder;
    $decoded = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
    if ($decoded !== $folder && @mb_convert_encoding($decoded, 'UTF7-IMAP', 'UTF-8') === $folder) {
        return $folder;
    }
    return @mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
}

// Helper: Turkish character transliteration for fast resilient search
function tr_imap_transliterate($str) {
    $map = [
        'ç' => 'c', 'Ç' => 'C',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'I' => 'I',
        'İ' => 'I', 'i' => 'i',
        'ö' => 'o', 'Ö' => 'O',
        'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U'
    ];
    return strtr($str, $map);
}

// Helper: Turkish-aware string comparison for sorting
function tr_compare_strings($a, $b) {
    $map = ['İ'=>'i', 'I'=>'ı', 'ı'=>'i', 'Ç'=>'ç', 'Ş'=>'ş', 'Ğ'=>'ğ', 'Ü'=>'ü', 'Ö'=>'ö'];
    $sa = mb_strtolower(strtr((string)$a, $map), 'UTF-8');
    $sb = mb_strtolower(strtr((string)$b, $map), 'UTF-8');
    return strcmp($sa, $sb);
}

// Helper: IMAP connection
function connect_imap($acc, $folder = 'INBOX') {
    if (!function_exists('imap_open')) return false;
    if (!$acc) return false;
    $host = $acc->imap_host;
    $port = (int)($acc->imap_port ?: 993);
    $enc = strtolower($acc->imap_encryption ?: 'ssl');
    $user = $acc->imap_username ?: $acc->email;
    $pass = $acc->imap_password;

    $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : (($enc === 'tls' || $enc === 'starttls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
    $encoded_folder = safe_utf7_encode($folder);
    $mailbox = "{" . "{$host}:{$port}{$flags}" . "}" . $encoded_folder;

    $old = error_reporting(0);
    $conn = @imap_open($mailbox, $user, $pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    error_reporting($old);
    return $conn;
}

// Helper: Ensure IMAP mailbox exists
function ensure_imap_folder($acc, $folder) {
    if (!$acc || in_array(strtoupper($folder), ['INBOX', 'SENT', 'DRAFTS', 'TRASH', 'SPAM', 'ARCHIVE'])) return true;
    static $ensured = [];
    $cache_key = ($acc->id ?? 0) . '_' . $folder;
    if (!empty($ensured[$cache_key])) return true;

    $conn = connect_imap($acc, 'INBOX');
    if (!$conn) return false;
    $host = $acc->imap_host;
    $port = (int)($acc->imap_port ?: 993);
    $enc = strtolower($acc->imap_encryption ?: 'ssl');
    $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
    $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";
    
    $encoded = safe_utf7_encode($folder);
    $fullMb = $server_prefix . $encoded;
    
    $res = @imap_createmailbox($conn, $fullMb);
    if ($res) {
        @imap_subscribe($conn, $fullMb);
    }
    @imap_errors(); // Suppress any ALREADYEXISTS notice
    @imap_close($conn);
    $ensured[$cache_key] = true;
    return true;
}

// Helper: Match string according to rule condition (PHP 7.4 safe)
function filter_match_rule($haystack, $needle, $type) {
    $haystack = mb_strtolower(trim((string)$haystack), 'UTF-8');
    $needle = mb_strtolower(trim((string)$needle), 'UTF-8');
    if ($needle === '') return false;
    if ($type === 'equals') return ($haystack === $needle);
    if ($type === 'starts_with') return (mb_substr($haystack, 0, mb_strlen($needle, 'UTF-8'), 'UTF-8') === $needle);
    if ($type === 'ends_with') return (mb_substr($haystack, -mb_strlen($needle, 'UTF-8'), null, 'UTF-8') === $needle);
    return (mb_strpos($haystack, $needle, 0, 'UTF-8') !== false);
}

/**
 * Automatically purges emails in Trash older than the retention period (default: 30 days).
 *
 * @param PDO $pdo Database connection
 * @param object $acc Active mail account
 * @param bool $force If true, ignores throttling interval and auto_clean disabled flag
 * @param resource|null $conn Existing IMAP connection if already open
 * @param string $folder Trash folder name (defaults to 'Trash')
 * @param int|null $override_days Explicit days cutoff override (0 = purge all, >0 = specific days)
 * @return array
 */
function auto_clean_trash_folder($pdo, $acc, $force = false, $conn = null, $folder = 'Trash', $override_days = null) {
    if (!$acc) return ['success' => false, 'deleted' => 0, 'message' => 'Geçersiz e-posta hesabı.'];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('auto_clean_trash', 'trash_retention_days', 'last_trash_clean_at')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $settings = [];
    }

    // Default: '1' (enabled)
    $auto_clean = isset($settings['auto_clean_trash']) ? ($settings['auto_clean_trash'] === '1' || $settings['auto_clean_trash'] === 'true' || $settings['auto_clean_trash'] === 1) : true;
    $retention_days = ($override_days !== null) ? max(0, (int)$override_days) : (isset($settings['trash_retention_days']) ? max(1, (int)$settings['trash_retention_days']) : 30);

    // If not forced and auto-clean is disabled, skip
    if (!$force && !$auto_clean) {
        return ['success' => true, 'deleted' => 0, 'status' => 'disabled'];
    }

    $now = time();
    $last_clean = (int)($settings['last_trash_clean_at'] ?? 0);

    // Throttle automatic background runs to at most once every 15 minutes (900 seconds) unless forced
    if (!$force && ($now - $last_clean) < 900) {
        return ['success' => true, 'deleted' => 0, 'status' => 'throttled'];
    }

    $should_close = false;
    $actual_conn = $conn;
    $actual_folder = $folder;

    $is_trash_folder = (!empty($folder) && (stripos($folder, 'trash') !== false || stripos($folder, 'çöp') !== false));
    if ($actual_conn && !$is_trash_folder) {
        $actual_conn = null;
    }

    if (!$actual_conn) {
        $valid_trash_names = ['Trash', 'INBOX.Trash', 'Çöp Kutusu', 'INBOX.Çöp Kutusu', 'Deleted Items', 'INBOX.Deleted Items'];
        $candidates = [];
        if ($is_trash_folder) {
            $candidates[] = $folder;
        }
        foreach ($valid_trash_names as $vt) {
            $candidates[] = $vt;
        }
        $candidates = array_unique($candidates);
        foreach ($candidates as $cand) {
            $test_conn = @connect_imap($acc, $cand);
            if ($test_conn) {
                $actual_conn = $test_conn;
                $actual_folder = $cand;
                $should_close = true;
                break;
            }
        }
    }

    if (!$actual_conn) {
        return ['success' => false, 'deleted' => 0, 'message' => 'Çöp kutusuna bağlanılamadı.'];
    }

    // Determine IMAP search criteria
    if ($retention_days === 0) {
        $search_criteria = 'ALL';
        $cutoff_date = null;
    } else {
        // RFC 3501 date format: j-M-Y (e.g. 4-Oct-2026)
        $cutoff_ts = strtotime("-{$retention_days} days");
        $cutoff_date = date('j-M-Y', $cutoff_ts);
        $search_criteria = 'BEFORE "' . $cutoff_date . '"';
    }

    $uids = @imap_search($actual_conn, $search_criteria, SE_UID);
    $deleted_count = 0;

    if (!empty($uids) && is_array($uids)) {
        $chunks = array_chunk($uids, 100);
        foreach ($chunks as $chunk) {
            $seq = implode(',', $chunk);
            if (function_exists('imap_setflag_full')) {
                @imap_setflag_full($actual_conn, $seq, "\\Deleted", ST_UID);
            } else {
                foreach ($chunk as $u) {
                    @imap_delete($actual_conn, (string)$u, FT_UID);
                }
            }
        }
        @imap_expunge($actual_conn);
        $deleted_count = count($uids);
    }
    @imap_errors();

    if ($should_close) {
        @imap_close($actual_conn);
    }

    // Update last run timestamp in mail_settings
    try {
        $stmt_up = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('last_trash_clean_at', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt_up->execute([(string)$now, (string)$now]);
    } catch (Throwable $e) {}

    return [
        'success' => true,
        'deleted' => $deleted_count,
        'retention_days' => $retention_days,
        'cutoff_date' => $cutoff_date,
        'folder' => $actual_folder
    ];
}

/**
 * Automatically purges emails in Spam / Junk folder older than the retention period (default: 30 days).
 *
 * @param PDO $pdo Database connection
 * @param object $acc Active mail account
 * @param bool $force If true, ignores throttling interval and auto_clean disabled flag
 * @param resource|null $conn Existing IMAP connection if already open
 * @param string|null $folder Spam folder name (defaults to auto-detected)
 * @param int|null $override_days Explicit days cutoff override (0 = purge all, >0 = specific days)
 * @return array
 */
function auto_clean_spam_folder($pdo, $acc, $force = false, $conn = null, $folder = null, $override_days = null) {
    if (!$acc) return ['success' => false, 'deleted' => 0, 'message' => 'Geçersiz e-posta hesabı.'];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('auto_clean_spam', 'spam_retention_days', 'last_spam_clean_at')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $settings = [];
    }

    // Default: '1' (enabled)
    $auto_clean = isset($settings['auto_clean_spam']) ? ($settings['auto_clean_spam'] === '1' || $settings['auto_clean_spam'] === 'true' || $settings['auto_clean_spam'] === 1) : true;
    $retention_days = ($override_days !== null) ? max(0, (int)$override_days) : (isset($settings['spam_retention_days']) ? max(1, (int)$settings['spam_retention_days']) : 30);

    // If not forced and auto-clean is disabled, skip
    if (!$force && !$auto_clean) {
        return ['success' => true, 'deleted' => 0, 'status' => 'disabled'];
    }

    $now = time();
    $last_clean = (int)($settings['last_spam_clean_at'] ?? 0);

    // Throttle automatic background runs to at most once every 15 minutes (900 seconds) unless forced
    if (!$force && ($now - $last_clean) < 900) {
        return ['success' => true, 'deleted' => 0, 'status' => 'throttled'];
    }

    $should_close = false;
    $actual_conn = $conn;
    $actual_folder = $folder;

    $is_spam_folder = (!empty($folder) && (stripos($folder, 'spam') !== false || stripos($folder, 'junk') !== false || stripos($folder, 'istenmeyen') !== false));
    if ($actual_conn && !$is_spam_folder) {
        $actual_conn = null;
    }

    if (!$actual_conn) {
        $valid_spam_names = ['Junk', 'Spam', 'INBOX.Junk', 'INBOX.Spam', 'İstenmeyen', 'INBOX.İstenmeyen'];
        $candidates = [];
        if ($is_spam_folder) {
            $candidates[] = $folder;
        }
        foreach ($valid_spam_names as $vs) {
            $candidates[] = $vs;
        }
        $candidates = array_unique($candidates);
        foreach ($candidates as $cand) {
            $test_conn = @connect_imap($acc, $cand);
            if ($test_conn) {
                $actual_conn = $test_conn;
                $actual_folder = $cand;
                $should_close = true;
                break;
            }
        }
    }

    if (!$actual_conn) {
        return ['success' => false, 'deleted' => 0, 'message' => 'Spam klasörüne bağlanılamadı.'];
    }

    // Determine IMAP search criteria
    if ($retention_days === 0) {
        $search_criteria = 'ALL';
        $cutoff_date = null;
    } else {
        // RFC 3501 date format: j-M-Y (e.g. 4-Oct-2026)
        $cutoff_ts = strtotime("-{$retention_days} days");
        $cutoff_date = date('j-M-Y', $cutoff_ts);
        $search_criteria = 'BEFORE "' . $cutoff_date . '"';
    }

    $uids = @imap_search($actual_conn, $search_criteria, SE_UID);
    $deleted_count = 0;

    if (!empty($uids) && is_array($uids)) {
        $chunks = array_chunk($uids, 100);
        foreach ($chunks as $chunk) {
            $seq = implode(',', $chunk);
            if (function_exists('imap_setflag_full')) {
                @imap_setflag_full($actual_conn, $seq, "\\Deleted", ST_UID);
            } else {
                foreach ($chunk as $u) {
                    @imap_delete($actual_conn, (string)$u, FT_UID);
                }
            }
        }
        @imap_expunge($actual_conn);
        $deleted_count = count($uids);
    }
    @imap_errors();

    if ($should_close) {
        @imap_close($actual_conn);
    }

    // Update last run timestamp in mail_settings
    try {
        $stmt_up = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('last_spam_clean_at', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt_up->execute([(string)$now, (string)$now]);
    } catch (Throwable $e) {}

    return [
        'success' => true,
        'deleted' => $deleted_count,
        'retention_days' => $retention_days,
        'cutoff_date' => $cutoff_date,
        'folder' => $actual_folder
    ];
}

// Helper: Applies active mail filter rules to incoming messages in INBOX
function apply_incoming_mail_filters($pdo, $acc, $conn = null, $scan_limit = 60) {
    if (!$acc) return 0;

    // Throttle automatic background scans ($scan_limit <= 60) so concurrent requests don't re-scan INBOX repeatedly
    if ($scan_limit <= 60) {
        $flt_lock_file = sys_get_temp_dir() . '/wm_flt_run_' . (int)$acc->id . '.tmp';
        if (file_exists($flt_lock_file) && (time() - @filemtime($flt_lock_file) < 15)) {
            return 0;
        }
        @touch($flt_lock_file);
    }

    // 1. Fetch active filters for this account
    try {
        $stmt = $pdo->prepare("SELECT * FROM mail_filters WHERE (account_id = ? OR account_id = 0) AND is_active = 1 ORDER BY id ASC");
        $stmt->execute([$acc->id]);
        $filters = $stmt->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    if (empty($filters)) return 0;

    // 2. Open connection if not provided
    $should_close = false;
    if (!$conn) {
        $conn = connect_imap($acc, 'INBOX');
        if (!$conn) return 0;
        $should_close = true;
    }

    $num_msgs = @imap_num_msg($conn);
    if ($num_msgs <= 0) {
        if ($should_close) @imap_close($conn);
        return 0;
    }

    // 3. Scan the most recent messages (top incoming messages)
    $scan_count = min($num_msgs, max(1, (int)$scan_limit));
    $start_msg = max(1, $num_msgs - $scan_count + 1);
    $range_str = "{$start_msg}:{$num_msgs}";

    $overviews = @imap_fetch_overview($conn, $range_str);
    if (empty($overviews)) {
        if ($should_close) @imap_close($conn);
        return 0;
    }

    $filtered_count = 0;
    $needs_expunge = false;
    $ensured_targets = [];

    // Iterate messages in reverse order (newest first)
    $ov_list = array_reverse($overviews);
    foreach ($ov_list as $ov) {
        $mno = $ov->msgno ?? 0;
        $uid = $ov->uid ?? 0;
        if (!$mno || !$uid) continue;

        $from_raw = $ov->from ?? '';
        $from_decoded = decode_mime($from_raw);
        $subject_raw = $ov->subject ?? '';
        $subject_decoded = decode_mime($subject_raw);
        $to_raw = $ov->to ?? '';
        $to_decoded = decode_mime($to_raw);

        foreach ($filters as $filter) {
            $match_field = strtolower(trim($filter->match_field ?: 'from'));
            $match_type = strtolower(trim($filter->match_type ?: 'contains'));
            $match_val = trim($filter->match_value ?: '');
            if ($match_val === '') continue;

            $matched = false;
            if ($match_field === 'from') {
                $matched = filter_match_rule($from_decoded, $match_val, $match_type) 
                        || filter_match_rule($from_raw, $match_val, $match_type);
            } elseif ($match_field === 'subject') {
                $matched = filter_match_rule($subject_decoded, $match_val, $match_type) 
                        || filter_match_rule($subject_raw, $match_val, $match_type);
            } elseif ($match_field === 'to') {
                $matched = filter_match_rule($to_decoded, $match_val, $match_type) 
                        || filter_match_rule($to_raw, $match_val, $match_type);
            } elseif ($match_field === 'body') {
                $body_snippet = @imap_fetchbody($conn, $mno, '1', FT_PEEK) ?: '';
                $matched = filter_match_rule($body_snippet, $match_val, $match_type);
            }

            if ($matched) {
                $action_type = strtolower(trim($filter->action_type ?: 'move'));
                $action_target = trim($filter->action_target ?: '');

                if ($action_type === 'move' && !empty($action_target)) {
                    if (empty($ensured_targets[$action_target])) {
                        ensure_imap_folder($acc, $action_target);
                        $ensured_targets[$action_target] = true;
                    }
                    $target_mb = safe_utf7_encode($action_target);
                    $mvd = @imap_mail_move($conn, (string)$uid, $target_mb, CP_UID);
                    if (!$mvd) {
                        $mvd = @imap_mail_move($conn, (string)$uid, "INBOX." . $target_mb, CP_UID);
                    }
                    if ($mvd) {
                        $needs_expunge = true;
                        $filtered_count++;
                    }
                } elseif ($action_type === 'star') {
                    @imap_setflag_full($conn, (string)$uid, "\\Flagged", ST_UID);
                    $filtered_count++;
                } elseif ($action_type === 'read') {
                    @imap_setflag_full($conn, (string)$uid, "\\Seen", ST_UID);
                    $filtered_count++;
                } elseif ($action_type === 'delete') {
                    ensure_imap_folder($acc, 'Trash');
                    $mvd = @imap_mail_move($conn, (string)$uid, 'Trash', CP_UID);
                    if ($mvd) {
                        $needs_expunge = true;
                        $filtered_count++;
                    }
                }
                break;
            }
        }
    }

    if ($needs_expunge) {
        @imap_expunge($conn);
    }

    if ($should_close) {
        @imap_close($conn);
    }

    return $filtered_count;
}

// Helper: Ensure valid UTF-8 string, repairing or cleaning malformed sequences
function safe_utf8($string) {
    if ($string === null || $string === '') return '';
    if (!is_string($string)) return (string)$string;
    if (mb_check_encoding($string, 'UTF-8')) {
        return $string;
    }
    // Attempt Turkish legacy charsets if strictly valid in one
    if (@mb_check_encoding($string, 'ISO-8859-9')) {
        $conv = @mb_convert_encoding($string, 'UTF-8', 'ISO-8859-9');
        if (@mb_check_encoding($conv, 'UTF-8')) return $conv;
    }
    if (@mb_check_encoding($string, 'Windows-1254')) {
        $conv = @mb_convert_encoding($string, 'UTF-8', 'Windows-1254');
        if (@mb_check_encoding($conv, 'UTF-8')) return $conv;
    }
    // Convert and discard/substitute invalid UTF-8 bytes
    $clean = @mb_convert_encoding($string, 'UTF-8', 'UTF-8');
    if (@mb_check_encoding($clean, 'UTF-8')) return $clean;
    return @iconv('UTF-8', 'UTF-8//IGNORE', $string) ?: '';
}

// Helper: Fail-safe JSON encoder that never crashes on malformed UTF-8
function safe_json_encode($data, $flags = 0) {
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $res = @json_encode($data, $flags);
    if ($res !== false) {
        return $res;
    }
    // Deep sanitize array/object strings if still failing
    $deepClean = function($val) use (&$deepClean) {
        if (is_array($val)) return array_map($deepClean, $val);
        if (is_object($val)) {
            $obj = clone $val;
            foreach ($obj as $k => $v) $obj->$k = $deepClean($v);
            return $obj;
        }
        if (is_string($val)) return safe_utf8($val);
        return $val;
    };
    $cleaned = $deepClean($data);
    $res = @json_encode($cleaned, $flags);
    return ($res !== false) ? $res : json_encode(['success' => false, 'message' => 'JSON encode error: ' . json_last_error_msg()]);
}

// Helper: MIME header decode
function decode_mime($string) {
    if (empty($string)) return '';
    $elements = @imap_mime_header_decode($string);
    if (!$elements) return safe_utf8($string);
    $output = '';
    foreach ($elements as $el) {
        $charset = strtoupper($el->charset);
        $text = $el->text;
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            $output .= ($converted !== false) ? $converted : $text;
        } else {
            $output .= $text;
        }
    }
    return safe_utf8($output);
}

// Helper: Parse Address
function parse_address($str) {
    $str = safe_utf8(trim((string)$str));
    if (preg_match('/^(.*?)\s*<(.+?)>$/', $str, $m)) {
        return ['name' => safe_utf8(trim(trim($m[1]), '"\'')), 'email' => safe_utf8(trim($m[2])), 'raw' => $str];
    } elseif (filter_var($str, FILTER_VALIDATE_EMAIL)) {
        return ['name' => explode('@', $str)[0], 'email' => $str, 'raw' => $str];
    }
    return ['name' => $str, 'email' => $str, 'raw' => $str];
}

// Helper: Parse Address List (for To, Cc, Bcc)
function parse_address_list($address_objects, $address_string = '') {
    $result = [];
    if (!empty($address_objects) && is_array($address_objects)) {
        foreach ($address_objects as $obj) {
            $email = '';
            if (!empty($obj->mailbox) && !empty($obj->host)) {
                $email = $obj->mailbox . '@' . $obj->host;
            }
            $name = !empty($obj->personal) ? decode_mime($obj->personal) : '';
            if (empty($name) && !empty($email)) {
                $name = explode('@', $email)[0];
            }
            if (!empty($email)) {
                $result[] = [
                    'name' => safe_utf8($name ?: $email),
                    'email' => safe_utf8($email)
                ];
            }
        }
    }
    if (empty($result) && !empty($address_string)) {
        $decoded = decode_mime($address_string);
        $parts = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $decoded);
        foreach ($parts as $p) {
            $parsed = parse_address($p);
            if (!empty($parsed['email'])) {
                $result[] = [
                    'name' => safe_utf8($parsed['name'] ?: $parsed['email']),
                    'email' => safe_utf8($parsed['email'])
                ];
            }
        }
    }
    return $result;
}

// Helper: Summarize recipient(s) for message list rows (especially Sent folder)
function parse_recipient_summary($to_raw) {
    if (empty($to_raw)) {
        return ['name' => '', 'email' => '', 'raw' => ''];
    }
    $list = parse_address_list(null, $to_raw);
    if (!empty($list)) {
        $names = [];
        $emails = [];
        foreach ($list as $item) {
            $n = trim($item['name'] ?? '');
            $e = trim($item['email'] ?? '');
            if ($n !== '') $names[] = $n;
            elseif ($e !== '') $names[] = $e;
            if ($e !== '') $emails[] = $e;
        }
        $joinedNames = implode(', ', $names);
        $joinedEmails = implode(', ', $emails);
        return [
            'name' => safe_utf8($joinedNames ?: $joinedEmails),
            'email' => safe_utf8($joinedEmails),
            'raw' => safe_utf8(decode_mime($to_raw))
        ];
    }
    $single = parse_address(decode_mime($to_raw));
    return [
        'name' => $single['name'] ?? '',
        'email' => $single['email'] ?? '',
        'raw' => $single['raw'] ?? ''
    ];
}

// Helper: Robustly parse email date from Date: header, falling back to udate only if missing or unparseable
function parse_email_timestamp($date_str, $fallback_udate = 0) {
    if (!empty($date_str)) {
        // Strip timezone comments or names in parentheses: e.g. "Thu, 15 Mar 2018 10:18:22 +0300 (EEST)" -> "Thu, 15 Mar 2018 10:18:22 +0300"
        $cleaned = trim(preg_replace('/\s*\([^)]*\)/', '', (string)$date_str));
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

        $ts = strtotime($cleaned);
        if ($ts !== false && $ts > 946684800) {
            return $ts;
        }

        try {
            $dt = new DateTime($cleaned);
            if ($dt) {
                $ts = $dt->getTimestamp();
                if ($ts > 946684800) return $ts;
            }
        } catch (Throwable $e) {}

        // Handle Turkish month names if localized date was sent
        $tr_months = [
            'Ocak' => 'Jan', 'Şubat' => 'Feb', 'Mart' => 'Mar', 'Nisan' => 'Apr', 'Mayıs' => 'May', 'Haziran' => 'Jun',
            'Temmuz' => 'Jul', 'Ağustos' => 'Aug', 'Eylül' => 'Sep', 'Ekim' => 'Oct', 'Kasım' => 'Nov', 'Aralık' => 'Dec',
            'Oca' => 'Jan', 'Şub' => 'Feb', 'Mar' => 'Mar', 'Nis' => 'Apr', 'May' => 'May', 'Haz' => 'Jun',
            'Tem' => 'Jul', 'Ağu' => 'Aug', 'Eyl' => 'Sep', 'Eki' => 'Oct', 'Kas' => 'Nov', 'Ara' => 'Dec'
        ];
        $with_en = str_ireplace(array_keys($tr_months), array_values($tr_months), $cleaned);
        $ts = strtotime($with_en);
        if ($ts !== false && $ts > 946684800) {
            return $ts;
        }
    }

    if (!empty($fallback_udate) && (int)$fallback_udate > 946684800) {
        return (int)$fallback_udate;
    }

    return time();
}

// Helper: Format Date
function format_email_date($timestamp) {
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');
    if ($timestamp >= $today) {
        return date('H:i', $timestamp);
    } elseif ($timestamp >= $yesterday) {
        return 'Dün ' . date('H:i', $timestamp);
    } else {
        $months = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $m = $months[(int)date('n', $timestamp)] ?? '';
        if (date('Y', $timestamp) === date('Y')) {
            return date('j ', $timestamp) . $m;
        } else {
            return date('j ', $timestamp) . $m . date(' Y', $timestamp);
        }
    }
}

// Helper: Format Size
function format_bytes($bytes) {
    $u = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $p = floor(($bytes ? log($bytes) : 0) / log(1024));
    $p = min($p, count($u) - 1);
    return round($bytes / pow(1024, $p), 1) . ' ' . $u[$p];
}

// Helper: Sanitize HTML
function sanitize_email_html($html) {
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
    $html = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $html);
    $html = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $html);
    $html = preg_replace('/<embed\b[^>]*>/is', '', $html);
    $html = preg_replace('/<applet\b[^>]*>(.*?)<\/applet>/is', '', $html);
    $html = preg_replace('/<form\b[^>]*>(.*?)<\/form>/is', '', $html);
    $html = preg_replace('/<base\b[^>]*>/is', '', $html);
    $html = preg_replace('/<meta\b[^>]*http-equiv\s*=\s*(["\']?)\s*refresh[^>]*>/is', '', $html);
    $html = preg_replace('/\s*on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src|action|formaction)\s*=\s*(["\']?)\s*(javascript|vbscript|data\s*:\s*text\/html)\s*:[^"\'\s>]*/i', '$1=$2#', $html);
    $html = preg_replace('/<a\s+(?!.*target=)([^>]+)>/i', '<a target="_blank" rel="noopener noreferrer" $1>', $html);
    return $html;
}

// Helper: Synchronize Corporate Domain Global Directory & User Contacts
function sync_domain_global_directory($pdo, $acc) {
    if (!$acc) return ['success' => false, 'message' => 'Geçersiz hesap', 'total' => 0, 'added_to_personal' => 0];

    // Ensure domain_directory table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS domain_directory (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(120) NOT NULL,
          email VARCHAR(191) NOT NULL UNIQUE,
          name VARCHAR(191) NOT NULL,
          source VARCHAR(50) DEFAULT 'auto',
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (domain),
          INDEX (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {}

    $myEmail = strtolower(trim($acc->email));
    $parts = explode('@', $myEmail);
    $domain = strtolower($parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : ''));
    if (empty($domain)) return ['success' => false, 'message' => 'Alan adı bulunamadı', 'total' => 0, 'added_to_personal' => 0];

    $discovered = []; // email => name

    // 1. From existing domain_directory table cache
    try {
        $dirStmt = $pdo->prepare("SELECT email, name FROM domain_directory WHERE domain = ?");
        $dirStmt->execute([$domain]);
        while ($r = $dirStmt->fetch()) {
            $em = strtolower(trim($r->email));
            $nm = trim($r->name ?? '');
            $discovered[$em] = $nm;
        }
    } catch (Exception $e) {}

    // 2. From all users who have logged in (mail_accounts)
    try {
        $stmt = $pdo->prepare("SELECT email, name FROM mail_accounts WHERE LOWER(email) LIKE ?");
        $stmt->execute(['%@' . $domain]);
        while ($r = $stmt->fetch()) {
            $em = strtolower(trim($r->email));
            $nm = trim($r->name ?? '');
            if (empty($discovered[$em]) || !empty($nm)) {
                $discovered[$em] = $nm;
            }
        }
    } catch (Exception $e) {}

    // 3. From HestiaCP API if configured (v-list-mail-accounts)
    if (defined('HESTIA_API_HOST') && HESTIA_API_HOST && defined('HESTIA_API_KEY') && HESTIA_API_KEY) {
        $hestia_user = defined('HESTIA_API_USER') ? HESTIA_API_USER : 'admin';
        $postvars = [
            'user' => $hestia_user,
            'password' => HESTIA_API_KEY,
            'cmd' => 'v-list-mail-accounts',
            'arg1' => $hestia_user,
            'arg2' => $domain,
            'arg3' => 'json'
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim(HESTIA_API_HOST, '/') . '/api/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postvars));
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $hData = json_decode($res, true);
            if (is_array($hData)) {
                foreach ($hData as $accName => $accMeta) {
                    $em = strtolower($accName . '@' . $domain);
                    if (!isset($discovered[$em])) {
                        $discovered[$em] = $accMeta['ALIAS'] ?? '';
                    }
                }
            }
        }
    }

    // 4. Fast IMAP auto-discovery from recent internal emails (Sample up to 60 messages)
    $conn = connect_imap($acc, 'INBOX');
    if ($conn) {
        $res1 = @imap_search($conn, 'FROM "' . $domain . '"', SE_FREE, "UTF-8") ?: [];
        $res2 = @imap_search($conn, 'TO "' . $domain . '"', SE_FREE, "UTF-8") ?: [];
        $combined = array_unique(array_merge($res1, $res2));
        if (!empty($combined)) {
            rsort($combined);
            $sample = array_slice($combined, 0, 60);
            $overviews = @imap_fetch_overview($conn, implode(',', $sample));
            if ($overviews) {
                foreach ($overviews as $ov) {
                    foreach (['from', 'to'] as $fld) {
                        if (!empty($ov->$fld)) {
                            if (preg_match_all('/(?:(?:"?([^"<]+)"?\s*)?<)?([a-zA-Z0-9._%+-]+@' . preg_quote($domain, '/') . ')>?/i', $ov->$fld, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $m) {
                                    $em = strtolower(trim($m[2]));
                                    $disp = trim($m[1] ?? '');
                                    if (!empty($disp)) {
                                        if (strpos($disp, '=?') !== false && function_exists('imap_mime_header_decode')) {
                                            $dec = imap_mime_header_decode($disp);
                                            $disp = '';
                                            foreach ($dec as $elem) $disp .= $elem->text;
                                        }
                                        $disp = trim($disp, "\"'\t\n\r ");
                                    }
                                    if (!isset($discovered[$em]) || empty($discovered[$em])) {
                                        $discovered[$em] = $disp;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        @imap_close($conn);
    }

    // 5. Save all discovered accounts into domain_directory table
    try {
        $dirInsert = $pdo->prepare("INSERT INTO domain_directory (domain, email, name, source) VALUES (?, ?, ?, 'auto') ON DUPLICATE KEY UPDATE name = VALUES(name)");
        foreach ($discovered as $em => $rawName) {
            $cleanName = trim($rawName);
            if (empty($cleanName)) {
                $userPrefix = explode('@', $em)[0];
                $userPrefix = str_replace(['.', '_', '-'], ' ', $userPrefix);
                $cleanName = mb_convert_case($userPrefix, MB_CASE_TITLE, 'UTF-8');
            }
            $dirInsert->execute([$domain, $em, $cleanName]);
        }
    } catch (Exception $e) {}

    // 6. Sync into current user's personal contacts table
    $existingStmt = $pdo->prepare("SELECT LOWER(TRIM(email)) FROM contacts WHERE account_id = ?");
    $existingStmt->execute([$acc->id]);
    $alreadyInContacts = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#0284c7', '#10b981'];
    $insertedCount = 0;

    $insStmt = $pdo->prepare("INSERT INTO contacts (account_id, first_name, last_name, email, company, job_title, avatar_color, is_favorite, group_name, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'Kurumsal', ?)");

    foreach ($discovered as $em => $rawName) {
        if ($em === $myEmail) continue; // Don't add self
        if (isset($alreadyInContacts[$em])) continue; // Already in personal contacts

        $cleanName = trim($rawName);
        if (empty($cleanName)) {
            $userPrefix = explode('@', $em)[0];
            $userPrefix = str_replace(['.', '_', '-'], ' ', $userPrefix);
            $cleanName = mb_convert_case($userPrefix, MB_CASE_TITLE, 'UTF-8');
        }

        $parts = preg_split('/\s+/', $cleanName, 2);
        $firstName = $parts[0] ?? $cleanName;
        $lastName = $parts[1] ?? '';
        $color = $colors[abs(crc32($em)) % count($colors)];

        try {
            $companyLabel = ucfirst(explode('.', $domain)[0] ?? 'Kurumsal');
            $insStmt->execute([
                $acc->id,
                $firstName,
                $lastName,
                $em,
                $companyLabel,
                'Kurumsal E-posta',
                $color,
                'Global rehberden otomatik senkronize edildi.'
            ]);
            $insertedCount++;
        } catch (Exception $e) {}
    }

    return [
        'success' => true,
        'domain' => $domain,
        'total' => count($discovered),
        'added_to_personal' => $insertedCount
    ];
}

// Helper: Automatically save incoming & outgoing contacts if missing from address book
function auto_save_contacts_if_missing($pdo, $acc, array $people, $default_group = 'E-Posta Kişileri', $respect_deleted = true) {
    if (!$acc || empty($people)) return 0;

    $myEmail = strtolower(trim($acc->email ?? ''));
    $deletedMap = [];
    if ($respect_deleted) {
        $delFile = sys_get_temp_dir() . '/wm_del_contacts_' . (int)$acc->id . '.cache';
        if (file_exists($delFile)) {
            $loaded = @unserialize(@file_get_contents($delFile));
            if (is_array($loaded)) $deletedMap = $loaded;
        }
    }

    $candidates = []; // email => name
    foreach ($people as $p) {
        if (!is_array($p)) continue;
        $em = strtolower(trim($p['email'] ?? ''));
        $nm = trim($p['name'] ?? '');
        if (empty($em) || $em === $myEmail || $em === 'draft@placeholder.local') continue;
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
        if (preg_match('/(no-?reply|mailer-daemon|postmaster|bounce|notifications|donotreply|auto-?reply)/i', $em)) continue;
        if ($respect_deleted && isset($deletedMap[$em])) continue;

        if (!isset($candidates[$em]) || (empty($candidates[$em]) && !empty($nm) && strtolower($nm) !== $em && $nm !== 'Bilinmeyen' && $nm !== 'Ben')) {
            $candidates[$em] = $nm;
        }
    }

    if (empty($candidates)) return 0;

    try {
        $existingStmt = $pdo->prepare("SELECT LOWER(TRIM(email)) FROM contacts WHERE account_id = ?");
        $existingStmt->execute([$acc->id]);
        $existingMap = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return 0;
    }

    $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#0284c7', '#10b981'];
    $addedCount = 0;

    try {
        $insStmt = $pdo->prepare("INSERT INTO contacts (account_id, first_name, last_name, email, group_name, avatar_color) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($candidates as $em => $fullName) {
            if (isset($existingMap[$em])) continue;

            $fullName = trim($fullName, " \t\n\r\0\x0B\"'");
            if (empty($fullName) || strtolower($fullName) === $em || $fullName === 'Bilinmeyen' || $fullName === 'Ben') {
                $prefix = explode('@', $em)[0];
                $parts = preg_split('/[\._\-]+/', $prefix);
                $first_name = mb_convert_case($parts[0] ?? $prefix, MB_CASE_TITLE, 'UTF-8');
                $last_name = isset($parts[1]) ? mb_convert_case(implode(' ', array_slice($parts, 1)), MB_CASE_TITLE, 'UTF-8') : '';
            } else {
                $parts = preg_split('/\s+/', $fullName);
                $first_name = array_shift($parts);
                $last_name = implode(' ', $parts);
            }

            $color = $colors[abs(crc32($em)) % count($colors)];
            $insStmt->execute([$acc->id, $first_name, $last_name, $em, $default_group, $color]);
            $existingMap[$em] = true;
            $addedCount++;
        }
    } catch (Throwable $e) {}

    return $addedCount;
}

// Helper: Lightweight throttled background sync of recent Sent (outgoing) contacts only
function auto_sync_recent_mailbox_contacts($pdo, $acc, $limit_per_folder = 80) {
    if (!$acc) return 0;

    $syncLockFile = sys_get_temp_dir() . '/wm_auto_mail_contacts_' . (int)$acc->id . '.tmp';
    if (file_exists($syncLockFile) && (time() - @filemtime($syncLockFile) < 120)) {
        return 0;
    }
    @touch($syncLockFile);

    $people = [];

    // Scan recent Sent (outgoing recipients only)
    $connSent = connect_imap($acc, 'Sent');
    if (!$connSent) $connSent = connect_imap($acc, 'INBOX.Sent');
    if ($connSent) {
        $num = @imap_num_msg($connSent);
        if ($num > 0) {
            $start = max(1, $num - $limit_per_folder + 1);
            $ovs = @imap_fetch_overview($connSent, "{$start}:{$num}");
            if ($ovs) {
                foreach ($ovs as $ov) {
                    if (!empty($ov->to)) {
                        foreach (parse_address_list(null, $ov->to) as $tp) {
                            if (!empty($tp['email'])) $people[] = $tp;
                        }
                    }
                    if (!empty($ov->cc)) {
                        foreach (parse_address_list(null, $ov->cc) as $cp) {
                            if (!empty($cp['email'])) $people[] = $cp;
                        }
                    }
                }
            }
        }
        @imap_close($connSent);
    }

    return auto_save_contacts_if_missing($pdo, $acc, $people, 'E-Posta Kişileri', true);
}

// Helper: Resolve target UIDs for bulk actions (either explicit UIDs or entire folder)
function resolve_bulk_target_uids($conn) {
    $select_all_folder = !empty($_POST['select_all_folder']) && $_POST['select_all_folder'] == '1';
    if ($select_all_folder && $conn) {
        $filter = trim($_POST['filter'] ?? 'all');
        $search = trim($_POST['search'] ?? '');
        $extra = ($filter === 'unread' ? ' UNSEEN' : ($filter === 'starred' ? ' FLAGGED' : ''));

        if (!empty($search)) {
            $cleanSearch = trim(str_replace(['"', "\r", "\n", "\\"], ' ', $search));
            $searchTerms = [$cleanSearch];
            if (function_exists('tr_imap_transliterate')) {
                $translit = tr_imap_transliterate($cleanSearch);
                if ($translit !== $cleanSearch && mb_strlen($translit, 'UTF-8') >= 2) {
                    $searchTerms[] = $translit;
                }
            }
            $matched = [];
            foreach ($searchTerms as $term) {
                foreach (['SUBJECT', 'FROM', 'TO', 'CC'] as $field) {
                    $res = @imap_search($conn, $field . ' "' . $term . '"' . $extra, SE_UID, "UTF-8") ?: [];
                    foreach ($res as $u) $matched[(int)$u] = true;
                }
            }
            return array_values(array_filter(array_keys($matched)));
        }

        $crit = ($filter === 'unread') ? 'UNSEEN' : (($filter === 'starred') ? 'FLAGGED' : 'ALL');
        $found = @imap_search($conn, $crit, SE_UID);
        if (is_array($found) && !empty($found)) {
            return array_values(array_filter(array_map('intval', $found)));
        }
        return [];
    }

    $uids_raw = $_POST['uids'] ?? '';
    if (is_array($uids_raw)) {
        return array_values(array_filter(array_map('intval', $uids_raw)));
    }
    return array_values(array_filter(array_map('intval', explode(',', (string)$uids_raw))));
}

// ROUTER
header('Content-Type: application/json; charset=utf-8');

switch ($action) {

    // 1. GET MESSAGES LIST (Supports Selected Folder, Multi-Folder All Search, & Advanced Filters)
    case 'get_messages':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo safe_json_encode(['success' => false, 'message' => 'Lütfen önce e-posta hesabı ekleyin.', 'needs_setup' => true]);
            exit;
        }

        $folder = trim($_GET['folder'] ?? 'INBOX');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 25;
        
        $search = trim($_GET['search'] ?? '');
        $adv_from = trim($_GET['adv_from'] ?? '');
        $adv_to = trim($_GET['adv_to'] ?? '');
        $adv_subject = trim($_GET['adv_subject'] ?? '');
        $adv_body = trim($_GET['adv_body'] ?? '');
        $adv_date_from = trim($_GET['adv_date_from'] ?? '');
        $adv_date_to = trim($_GET['adv_date_to'] ?? '');
        $has_attachment = !empty($_GET['has_attachment']) ? 1 : 0;
        $filter = $_GET['filter'] ?? ($_GET['adv_status'] ?? '');
        $sort = strtolower(trim($_GET['sort'] ?? 'date_desc'));
        if (!in_array($sort, ['date_desc', 'date_asc', 'from_asc', 'from_desc', 'name_asc', 'name_desc', 'subject_asc', 'subject_desc'])) {
            $sort = 'date_desc';
        }

        $is_advanced = (!empty($adv_from) || !empty($adv_to) || !empty($adv_subject) || !empty($adv_body) || !empty($adv_date_from) || !empty($adv_date_to) || $has_attachment);
        $is_all_folders = ($folder === 'ALL' || $folder === 'ALL_FOLDERS' || $folder === '*');

        // Helper to build IMAP criteria string
        $build_criteria = function($adv_from, $adv_to, $adv_subject, $adv_body, $adv_date_from, $adv_date_to, $filter) {
            $parts = [];
            if (!empty($adv_from)) {
                $parts[] = 'FROM "' . trim(str_replace(['"', "\r", "\n", "\\"], ' ', $adv_from)) . '"';
            }
            if (!empty($adv_to)) {
                $parts[] = 'TO "' . trim(str_replace(['"', "\r", "\n", "\\"], ' ', $adv_to)) . '"';
            }
            if (!empty($adv_subject)) {
                $parts[] = 'SUBJECT "' . trim(str_replace(['"', "\r", "\n", "\\"], ' ', $adv_subject)) . '"';
            }
            if (!empty($adv_body)) {
                $parts[] = 'BODY "' . trim(str_replace(['"', "\r", "\n", "\\"], ' ', $adv_body)) . '"';
            }
            if (!empty($adv_date_from)) {
                $ts = strtotime($adv_date_from);
                if ($ts) $parts[] = 'SINCE "' . date('j-M-Y', $ts) . '"';
            }
            if (!empty($adv_date_to)) {
                $ts = strtotime($adv_date_to);
                if ($ts) $parts[] = 'BEFORE "' . date('j-M-Y', $ts + 86400) . '"';
            }
            if ($filter === 'unread') {
                $parts[] = 'UNSEEN';
            } elseif ($filter === 'starred') {
                $parts[] = 'FLAGGED';
            }
            return implode(' ', $parts);
        };

        // =========================================================================
        // CASE A: SEARCH ACROSS ALL FOLDERS (Tüm Klasörler)
        // =========================================================================
        if ($is_all_folders) {
            $targetFolders = ['INBOX', 'Sent', 'Drafts', 'Trash'];
            try {
                $cf_stmt = $pdo->prepare("SELECT folder_name, display_name FROM mail_custom_folders WHERE account_id = ? ORDER BY folder_name ASC");
                $cf_stmt->execute([(int)$acc->id]);
                $cfs = $cf_stmt->fetchAll();
                foreach ($cfs as $cf) {
                    if (!in_array($cf->folder_name, $targetFolders)) {
                        $targetFolders[] = $cf->folder_name;
                    }
                }
            } catch (Exception $e) {}

            $allCollected = [];
            $totalFoundAll = 0;

            foreach ($targetFolders as $tFolder) {
                $conn = connect_imap($acc, $tFolder);
                if (!$conn) continue;

                $matched_map = [];
                $extra = ($filter === 'unread' ? ' UNSEEN' : ($filter === 'starred' ? ' FLAGGED' : ''));

                if ($is_advanced) {
                    $crit = $build_criteria($adv_from, $adv_to, $adv_subject, $adv_body, $adv_date_from, $adv_date_to, $filter);
                    if (empty($crit)) $crit = 'ALL';
                    $res = @imap_search($conn, $crit, SE_FREE, "UTF-8") ?: [];
                    foreach ($res as $n) $matched_map[$n] = true;
                } elseif (!empty($search)) {
                    $cleanSearch = trim(str_replace(['"', "\r", "\n", "\\"], ' ', $search));
                    $searchTerms = [$cleanSearch];
                    $translit = tr_imap_transliterate($cleanSearch);
                    if ($translit !== $cleanSearch && mb_strlen($translit, 'UTF-8') >= 2) {
                        $searchTerms[] = $translit;
                    }
                    foreach ($searchTerms as $term) {
                        $r1 = @imap_search($conn, 'SUBJECT "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                        foreach ($r1 as $n) $matched_map[$n] = true;
                        $r2 = @imap_search($conn, 'FROM "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                        foreach ($r2 as $n) $matched_map[$n] = true;
                        $r3 = @imap_search($conn, 'TO "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                        foreach ($r3 as $n) $matched_map[$n] = true;
                    }
                } else {
                    $crit = ($filter === 'unread') ? 'UNSEEN' : (($filter === 'starred') ? 'FLAGGED' : 'ALL');
                    $res = @imap_search($conn, $crit, SE_FREE, "UTF-8") ?: [];
                    foreach ($res as $n) $matched_map[$n] = true;
                }

                $folderMsgNums = array_keys($matched_map);
                $cnt = count($folderMsgNums);
                $totalFoundAll += $cnt;

                if ($cnt > 0) {
                    rsort($folderMsgNums);
                    $topNums = array_slice($folderMsgNums, 0, 40);
                    $overviews = @imap_fetch_overview($conn, implode(',', $topNums));
                    if ($overviews) {
                        foreach ($overviews as $ov) {
                            $mno = $ov->msgno;
                            $has_att = false;
                            $struct = @imap_fetchstructure($conn, $mno);
                            if ($struct && isset($struct->parts) && count($struct->parts) > 1) {
                                foreach ($struct->parts as $p) {
                                    if (isset($p->disposition) && strtolower($p->disposition) === 'attachment') {
                                        $has_att = true; break;
                                    }
                                }
                            }
                            if ($has_attachment && !$has_att) continue;

                            $timestamp = parse_email_timestamp($ov->date ?? '', $ov->udate ?? 0);
                            $is_t_sent = (stripos($tFolder, 'sent') !== false || mb_stripos($tFolder, 'gönderilen', 0, 'UTF-8') !== false);
                            $allCollected[] = [
                                'folder' => $tFolder,
                                'folder_tag' => ($tFolder === 'INBOX' ? 'Gelen' : ($tFolder === 'Sent' ? 'Giden' : ($tFolder === 'Trash' ? 'Çöp' : ($tFolder === 'Drafts' ? 'Taslak' : $tFolder)))),
                                'uid' => $ov->uid,
                                'msg_no' => $mno,
                                'subject' => ($ov && !empty($ov->subject)) ? decode_mime($ov->subject) : '(Konusuz)',
                                'from' => ($ov && !empty($ov->from)) ? parse_address(decode_mime($ov->from)) : ['name' => 'Bilinmeyen', 'email' => ''],
                                'to' => ($ov && !empty($ov->to)) ? parse_recipient_summary($ov->to) : ['name' => '', 'email' => '', 'raw' => ''],
                                'is_sent' => $is_t_sent,
                                'date' => format_email_date($timestamp),
                                'timestamp' => $timestamp,
                                'is_seen' => !empty($ov->seen),
                                'is_flagged' => !empty($ov->flagged),
                                'has_attachment' => $has_att
                            ];
                        }
                    }
                }
                @imap_close($conn);
            }

            usort($allCollected, function($a, $b) use ($sort) {
                if ($sort === 'date_asc') {
                    return $a['timestamp'] - $b['timestamp'];
                } elseif ($sort === 'from_asc' || $sort === 'name_asc') {
                    $pA = (!empty($a['is_sent']) && !empty($a['to']['name'])) ? $a['to'] : $a['from'];
                    $pB = (!empty($b['is_sent']) && !empty($b['to']['name'])) ? $b['to'] : $b['from'];
                    $nA = $pA['name'] ?: $pA['email'] ?: '';
                    $nB = $pB['name'] ?: $pB['email'] ?: '';
                    return tr_compare_strings($nA, $nB);
                } elseif ($sort === 'from_desc' || $sort === 'name_desc') {
                    $pA = (!empty($a['is_sent']) && !empty($a['to']['name'])) ? $a['to'] : $a['from'];
                    $pB = (!empty($b['is_sent']) && !empty($b['to']['name'])) ? $b['to'] : $b['from'];
                    $nA = $pA['name'] ?: $pA['email'] ?: '';
                    $nB = $pB['name'] ?: $pB['email'] ?: '';
                    return tr_compare_strings($nB, $nA);
                } elseif ($sort === 'subject_asc') {
                    return tr_compare_strings($a['subject'], $b['subject']);
                } elseif ($sort === 'subject_desc') {
                    return tr_compare_strings($b['subject'], $a['subject']);
                } else {
                    return $b['timestamp'] - $a['timestamp'];
                }
            });

            $total = count($allCollected);
            $pages = max(1, (int)ceil($total / $per_page));
            $pagedMessages = array_slice($allCollected, ($page - 1) * $per_page, $per_page);

            echo safe_json_encode([
                'success' => true,
                'messages' => $pagedMessages,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'folder' => 'ALL',
                'is_all_folders' => true
            ]);
            exit;
        }

        // =========================================================================
        // CASE B: SEARCH OR LIST IN SPECIFIC FOLDER
        // =========================================================================
        $conn = connect_imap($acc, $folder);
        if (!$conn) {
            $err = imap_last_error() ?: 'IMAP bağlantı hatası';
            echo json_encode(['success' => false, 'message' => $err, 'messages' => [], 'total' => 0]);
            exit;
        }

        // Apply incoming mail filters automatically if loading INBOX
        if (strtoupper($folder) === 'INBOX' && empty($search) && !$is_advanced) {
            apply_incoming_mail_filters($pdo, $acc, $conn);
        }

        // Auto clean trash if viewing Trash folder (default 30 days retention)
        $is_trash_folder = (strpos(strtolower($folder), 'trash') !== false || strpos(strtolower($folder), 'çöp') !== false);
        if ($is_trash_folder && empty($search) && !$is_advanced) {
            auto_clean_trash_folder($pdo, $acc, false, $conn, $folder);
        }

        // Auto clean spam if viewing Spam / Junk folder (default 30 days retention)
        $is_spam_folder = (strpos(strtolower($folder), 'junk') !== false || strpos(strtolower($folder), 'spam') !== false || strpos(strtolower($folder), 'istenmeyen') !== false);
        if ($is_spam_folder && empty($search) && !$is_advanced) {
            auto_clean_spam_folder($pdo, $acc, false, $conn, $folder);
        }

        // Detect Sent folder so recipient (To) is used for display & name sorting
        $is_sent_folder = (stripos($folder, 'sent') !== false || mb_stripos($folder, 'gönderilen', 0, 'UTF-8') !== false);

        // Map requested sort order to IMAP criteria
        $sort_crit = SORTDATE;
        $sort_reverse = 1; // Default: newest first (date_desc)
        if ($sort === 'date_asc') {
            $sort_crit = SORTDATE;
            $sort_reverse = 0;
        } elseif ($sort === 'from_asc' || $sort === 'name_asc') {
            $sort_crit = $is_sent_folder ? SORTTO : SORTFROM;
            $sort_reverse = 0;
        } elseif ($sort === 'from_desc' || $sort === 'name_desc') {
            $sort_crit = $is_sent_folder ? SORTTO : SORTFROM;
            $sort_reverse = 1;
        } elseif ($sort === 'subject_asc') {
            $sort_crit = SORTSUBJECT;
            $sort_reverse = 0;
        } elseif ($sort === 'subject_desc') {
            $sort_crit = SORTSUBJECT;
            $sort_reverse = 1;
        }

        $sorted_nums = null;

        if ($is_advanced) {
            $crit = $build_criteria($adv_from, $adv_to, $adv_subject, $adv_body, $adv_date_from, $adv_date_to, $filter);
            if (empty($crit)) $crit = 'ALL';
            $sorted_nums = @imap_sort($conn, $sort_crit, $sort_reverse, 0, $crit, 'UTF-8');
            if ($sorted_nums === false || $sorted_nums === null) {
                $sorted_nums = @imap_search($conn, $crit, SE_FREE, "UTF-8") ?: [];
                if ($sort_reverse === 1) rsort($sorted_nums); else sort($sorted_nums);
            }
        } elseif (!empty($search)) {
            $cleanSearch = trim(str_replace(['"', "\r", "\n", "\\"], ' ', $search));
            $extra = ($filter === 'unread' ? ' UNSEEN' : ($filter === 'starred' ? ' FLAGGED' : ''));

            $searchTerms = [$cleanSearch];
            $translit = tr_imap_transliterate($cleanSearch);
            if ($translit !== $cleanSearch && mb_strlen($translit, 'UTF-8') >= 2) {
                $searchTerms[] = $translit;
            }

            $matched_map = [];
            foreach ($searchTerms as $term) {
                // 1. Subject (Konu)
                $res_sub = @imap_search($conn, 'SUBJECT "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                foreach ($res_sub as $n) $matched_map[$n] = true;

                // 2. From (Gönderen)
                $res_from = @imap_search($conn, 'FROM "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                foreach ($res_from as $n) $matched_map[$n] = true;

                // 3. To (Alıcı)
                $res_to = @imap_search($conn, 'TO "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                foreach ($res_to as $n) $matched_map[$n] = true;

                // 4. CC (Bilgi)
                $res_cc = @imap_search($conn, 'CC "' . $term . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                foreach ($res_cc as $n) $matched_map[$n] = true;
            }

            // Safe body check if headers found nothing and mailbox is small (<= 300 msgs)
            if (empty($matched_map)) {
                $chk = @imap_check($conn);
                $totalInFolder = $chk ? $chk->Nmsgs : 0;
                if ($totalInFolder > 0 && $totalInFolder <= 300) {
                    $res_body = @imap_search($conn, 'BODY "' . $cleanSearch . '"' . $extra, SE_FREE, "UTF-8") ?: [];
                    foreach ($res_body as $n) $matched_map[$n] = true;
                }
            }

            $search_msg_nums = array_keys($matched_map);
            if (empty($search_msg_nums)) {
                @imap_close($conn);
                echo json_encode(['success' => true, 'messages' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'folder' => $folder]);
                exit;
            }

            // If search result set <= 1000 messages, fetch overview and sort accurately by date/sender/subject
            if (count($search_msg_nums) <= 1000) {
                $ovs = @imap_fetch_overview($conn, implode(',', $search_msg_nums));
                $all_search_msgs = [];
                if ($ovs) {
                    foreach ($ovs as $ov) {
                        $mno = (int)$ov->msgno;
                        $ts = parse_email_timestamp($ov->date ?? '', $ov->udate ?? 0);
                        $all_search_msgs[] = [
                            'mno' => $mno,
                            'timestamp' => $ts,
                            'from_raw' => $is_sent_folder ? ($ov->to ?? $ov->from ?? '') : ($ov->from ?? ''),
                            'subject' => $ov->subject ?? ''
                        ];
                    }
                }
                if ($sort === 'date_asc') {
                    usort($all_search_msgs, fn($a, $b) => ($a['timestamp'] - $b['timestamp']) ?: ($a['mno'] - $b['mno']));
                } elseif ($sort === 'from_asc' || $sort === 'name_asc') {
                    usort($all_search_msgs, fn($a, $b) => tr_compare_strings($a['from_raw'], $b['from_raw']));
                } elseif ($sort === 'from_desc' || $sort === 'name_desc') {
                    usort($all_search_msgs, fn($a, $b) => tr_compare_strings($b['from_raw'], $a['from_raw']));
                } elseif ($sort === 'subject_asc') {
                    usort($all_search_msgs, fn($a, $b) => tr_compare_strings($a['subject'], $b['subject']));
                } elseif ($sort === 'subject_desc') {
                    usort($all_search_msgs, fn($a, $b) => tr_compare_strings($b['subject'], $a['subject']));
                } else {
                    // Default date_desc
                    usort($all_search_msgs, fn($a, $b) => ($b['timestamp'] - $a['timestamp']) ?: ($b['mno'] - $a['mno']));
                }
                $sorted_nums = array_column($all_search_msgs, 'mno');
            } else {
                if ($sort_reverse === 1) {
                    rsort($search_msg_nums);
                } else {
                    sort($search_msg_nums);
                }
                $sorted_nums = $search_msg_nums;
            }
        } else {
            // Standard folder browsing or status filter (unread / starred)
            $search_criteria = 'ALL';
            if ($filter === 'unread') {
                $search_criteria = 'UNSEEN';
            } elseif ($filter === 'starred') {
                $search_criteria = 'FLAGGED';
            }

            // Temp file caching for large folders to make scrolling & pagination instant
            $cache_key = md5("wm_sort_{$acc->id}_{$folder}_{$sort}_{$filter}_{$search_criteria}");
            $cache_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cache_key . '.cache';

            if (file_exists($cache_file) && (time() - filemtime($cache_file) < 45)) {
                $cached_data = @unserialize(@file_get_contents($cache_file));
                if (is_array($cached_data) && isset($cached_data['nums'])) {
                    $chk = @imap_check($conn);
                    $cur_total = $chk ? (int)$chk->Nmsgs : 0;
                    if ($cur_total === (int)($cached_data['nmsgs'] ?? -1)) {
                        $sorted_nums = $cached_data['nums'];
                    }
                }
            }

            if ($sorted_nums === null) {
                $sorted_nums = @imap_sort($conn, $sort_crit, $sort_reverse, 0, $search_criteria, 'UTF-8');
                if ($sorted_nums === false || $sorted_nums === null) {
                    // Fallback to sequence range or imap_search if imap_sort fails
                    if ($search_criteria === 'ALL') {
                        $chk = @imap_check($conn);
                        $cur_total = $chk ? (int)$chk->Nmsgs : 0;
                        if ($cur_total > 0) {
                            $sorted_nums = ($sort_reverse === 1) ? range($cur_total, 1) : range(1, $cur_total);
                        } else {
                            $sorted_nums = [];
                        }
                    } else {
                        $sorted_nums = @imap_search($conn, $search_criteria, SE_FREE, "UTF-8") ?: [];
                        if ($sort_reverse === 1) {
                            rsort($sorted_nums);
                        } else {
                            sort($sorted_nums);
                        }
                    }
                }

                if (is_array($sorted_nums)) {
                    $chk = @imap_check($conn);
                    $cur_total = $chk ? (int)$chk->Nmsgs : 0;
                    @file_put_contents($cache_file, serialize(['nmsgs' => $cur_total, 'nums' => $sorted_nums]), LOCK_EX);
                }
            }
        }

        if (empty($sorted_nums)) {
            @imap_close($conn);
            echo json_encode(['success' => true, 'messages' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'folder' => $folder]);
            exit;
        }

        $total = count($sorted_nums);
        $pages = max(1, (int)ceil($total / $per_page));
        $page_nums = array_slice($sorted_nums, ($page - 1) * $per_page, $per_page);

        $messages = [];
        $overviews = @imap_fetch_overview($conn, implode(',', $page_nums));
        $ov_map = [];
        if ($overviews) {
            foreach ($overviews as $ov) $ov_map[$ov->msgno] = $ov;
        }

        // Cache attachment flags per immutable UID to avoid 25x imap_fetchstructure network round-trips per page view
        $att_cache_file = sys_get_temp_dir() . '/wm_att_' . md5($acc->id . '_' . $folder) . '.cache';
        $att_cache = [];
        $att_cache_dirty = false;
        if (file_exists($att_cache_file)) {
            $loaded_att = @unserialize(@file_get_contents($att_cache_file));
            if (is_array($loaded_att)) $att_cache = $loaded_att;
        }

        foreach ($page_nums as $mno) {
            $ov = $ov_map[$mno] ?? null;
            $uid = $ov ? $ov->uid : @imap_uid($conn, $mno);
            $subject = ($ov && !empty($ov->subject)) ? decode_mime($ov->subject) : '(Konusuz)';
            $from = ($ov && !empty($ov->from)) ? parse_address(decode_mime($ov->from)) : ['name' => 'Bilinmeyen', 'email' => ''];
            $to = ($ov && !empty($ov->to)) ? parse_recipient_summary($ov->to) : ['name' => '', 'email' => '', 'raw' => ''];
            $timestamp = parse_email_timestamp($ov->date ?? '', $ov->udate ?? 0);

            // Attachment check (cached by UID)
            $uid_key = (int)$uid;
            if ($uid_key > 0 && array_key_exists($uid_key, $att_cache)) {
                $has_attachment_mail = (bool)$att_cache[$uid_key];
            } else {
                $has_attachment_mail = false;
                $struct = @imap_fetchstructure($conn, $mno);
                if ($struct && isset($struct->parts) && count($struct->parts) > 1) {
                    foreach ($struct->parts as $p) {
                        if (isset($p->disposition) && strtolower($p->disposition) === 'attachment') {
                            $has_attachment_mail = true; break;
                        }
                    }
                }
                if ($uid_key > 0) {
                    $att_cache[$uid_key] = $has_attachment_mail ? 1 : 0;
                    $att_cache_dirty = true;
                }
            }

            if ($has_attachment && !$has_attachment_mail) {
                continue;
            }

            $messages[] = [
                'folder' => $folder,
                'uid' => $uid,
                'msg_no' => $mno,
                'subject' => $subject,
                'from' => $from,
                'to' => $to,
                'is_sent' => $is_sent_folder,
                'date' => format_email_date($timestamp),
                'timestamp' => $timestamp,
                'is_seen' => !empty($ov->seen),
                'is_flagged' => !empty($ov->flagged),
                'has_attachment' => $has_attachment_mail
            ];
        }

        if ($att_cache_dirty) {
            if (count($att_cache) > 5000) {
                $att_cache = array_slice($att_cache, -4000, null, true);
            }
            @file_put_contents($att_cache_file, serialize($att_cache), LOCK_EX);
        }

        // Auto-add outgoing recipients ONLY when viewing the Sent folder
        if ($is_sent_folder && !empty($overviews)) {
            $page_people = [];
            foreach ($overviews as $ov) {
                if (!empty($ov->to)) {
                    foreach (parse_address_list(null, $ov->to) as $tp) {
                        if (!empty($tp['email'])) $page_people[] = $tp;
                    }
                }
                if (!empty($ov->cc)) {
                    foreach (parse_address_list(null, $ov->cc) as $cp) {
                        if (!empty($cp['email'])) $page_people[] = $cp;
                    }
                }
            }
            if (!empty($page_people)) {
                auto_save_contacts_if_missing($pdo, $acc, $page_people, 'E-Posta Kişileri', true);
            }
        }

        // Explicitly sort the messages on this page to ensure strict order
        if ($sort === 'date_desc') {
            usort($messages, function($a, $b) {
                $diff = ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
                return $diff !== 0 ? $diff : (($b['msg_no'] ?? 0) - ($a['msg_no'] ?? 0));
            });
        } elseif ($sort === 'date_asc') {
            usort($messages, function($a, $b) {
                $diff = ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
                return $diff !== 0 ? $diff : (($a['msg_no'] ?? 0) - ($b['msg_no'] ?? 0));
            });
        } elseif ($sort === 'from_asc' || $sort === 'name_asc') {
            usort($messages, function($a, $b) {
                $pA = (!empty($a['is_sent']) && !empty($a['to']['name'])) ? $a['to'] : $a['from'];
                $pB = (!empty($b['is_sent']) && !empty($b['to']['name'])) ? $b['to'] : $b['from'];
                $nA = $pA['name'] ?: $pA['email'] ?: '';
                $nB = $pB['name'] ?: $pB['email'] ?: '';
                return tr_compare_strings($nA, $nB);
            });
        } elseif ($sort === 'from_desc' || $sort === 'name_desc') {
            usort($messages, function($a, $b) {
                $pA = (!empty($a['is_sent']) && !empty($a['to']['name'])) ? $a['to'] : $a['from'];
                $pB = (!empty($b['is_sent']) && !empty($b['to']['name'])) ? $b['to'] : $b['from'];
                $nA = $pA['name'] ?: $pA['email'] ?: '';
                $nB = $pB['name'] ?: $pB['email'] ?: '';
                return tr_compare_strings($nB, $nA);
            });
        } elseif ($sort === 'subject_asc') {
            usort($messages, function($a, $b) {
                return tr_compare_strings($a['subject'], $b['subject']);
            });
        } elseif ($sort === 'subject_desc') {
            usort($messages, function($a, $b) {
                return tr_compare_strings($b['subject'], $a['subject']);
            });
        }

        @imap_close($conn);
        echo safe_json_encode(['success' => true, 'messages' => $messages, 'total' => $total, 'pages' => $pages, 'page' => $page, 'folder' => $folder]);
        exit;

    // 2. VIEW SINGLE EMAIL
    case 'view_email':
        $acc = get_active_account($pdo);
        $folder = $_GET['folder'] ?? 'INBOX';
        $uid = (int)($_GET['uid'] ?? 0);

        $conn = connect_imap($acc, $folder);
        if (!$conn || !$uid) {
            echo json_encode(['success' => false, 'message' => 'E-posta yüklenemedi.']);
            exit;
        }

        $mno = @imap_msgno($conn, $uid);
        if (!$mno) {
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'E-posta bulunamadı.']);
            exit;
        }

        $header = @imap_headerinfo($conn, $mno);
        $structure = @imap_fetchstructure($conn, $mno);

        $subject = ($header && !empty($header->subject)) ? decode_mime($header->subject) : '(Konusuz)';
        $from = ($header && !empty($header->fromaddress)) ? parse_address(decode_mime($header->fromaddress)) : ['name' => 'Bilinmeyen', 'email' => ''];
        $to = ($header && !empty($header->toaddress)) ? parse_address(decode_mime($header->toaddress)) : ['name' => 'Ben', 'email' => ''];
        $to_list = parse_address_list($header->to ?? null, $header->toaddress ?? '');
        $cc_list = parse_address_list($header->cc ?? null, $header->ccaddress ?? '');
        $bcc_list = parse_address_list($header->bcc ?? null, $header->bccaddress ?? '');

        if (empty($bcc_list)) {
            $raw_headers = @imap_fetchheader($conn, $mno) ?: '';
            if (!empty($raw_headers) && preg_match('/^(?:Bcc|X-Bcc):\s*(.+?)(?=\r?\n[^\s]|\z)/mis', $raw_headers, $bm)) {
                $bcc_raw = preg_replace('/\r?\n\s+/', ' ', trim($bm[1]));
                $bcc_list = parse_address_list(null, $bcc_raw);
            }
        }
        $timestamp = parse_email_timestamp($header->date ?? '', $header->udate ?? 0);
        $date = date('d.m.Y H:i', $timestamp);

        // Parse body & attachments
        $body_plain = '';
        $body_html = '';
        $attachments = [];

        function parse_standalone_parts($conn, $mno, $part, $prefix, &$plain, &$html, &$atts) {
            if (!$part) return;
            if (isset($part->parts) && count($part->parts) > 0) {
                foreach ($part->parts as $idx => $sub) {
                    $pno = empty($prefix) ? ($idx + 1) : ($prefix . '.' . ($idx + 1));
                    parse_standalone_parts($conn, $mno, $sub, $pno, $plain, $html, $atts);
                }
                return;
            }

            $part_num = empty($prefix) ? 1 : $prefix;
            $data = @imap_fetchbody($conn, $mno, $part_num);
            if ($part->encoding == 4) $data = quoted_printable_decode($data);
            elseif ($part->encoding == 3) $data = base64_decode($data);

            $charset = 'UTF-8';
            if ($part->ifparameters) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute) === 'charset') $charset = $p->value;
                }
            }
            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
                if ($converted) $data = $converted;
            }

            $filename = '';
            if ($part->ifparameters) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute) === 'filename' || strtolower($p->attribute) === 'name') $filename = decode_mime($p->value);
                }
            }
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $p) {
                    if (strtolower($p->attribute) === 'filename' || strtolower($p->attribute) === 'name') $filename = decode_mime($p->value);
                }
            }

            $is_att = (isset($part->disposition) && strtolower($part->disposition) === 'attachment') || !empty($filename);
            if ($is_att) {
                $atts[] = [
                    'part_no' => $part_num,
                    'filename' => $filename ?: ('dosya_' . $part_num),
                    'size' => format_bytes(strlen($data))
                ];
            } else {
                if ($part->type == 0) {
                    if (strtolower($part->subtype) === 'plain') $plain .= $data;
                    elseif (strtolower($part->subtype) === 'html') $html .= $data;
                }
            }
        }

        parse_standalone_parts($conn, $mno, $structure, '', $body_plain, $body_html, $attachments);

        if (empty($body_plain) && empty($body_html)) {
            $raw = @imap_body($conn, $mno);
            $body_plain = decode_mime($raw);
        }

        // Mark as seen
        @imap_setflag_full($conn, (string)$uid, "\\Seen", ST_UID);
        @imap_close($conn);

        // Automatically add recipients to contacts ONLY if viewing a Sent email
        $v_folder_lower = strtolower($folder);
        $v_is_sent = (strpos($v_folder_lower, 'sent') !== false || strpos($v_folder_lower, 'gönderilen') !== false);
        if ($v_is_sent) {
            $v_people = [];
            if (!empty($to_list)) $v_people = array_merge($v_people, $to_list);
            if (!empty($cc_list)) $v_people = array_merge($v_people, $cc_list);
            if (!empty($v_people)) {
                auto_save_contacts_if_missing($pdo, $acc, $v_people, 'E-Posta Kişileri', true);
            }
        }

        echo safe_json_encode([
            'success' => true,
            'email' => [
                'uid' => $uid,
                'folder' => $folder,
                'subject' => $subject,
                'from' => $from,
                'to' => $to,
                'to_list' => $to_list,
                'cc' => !empty($header->ccaddress) ? decode_mime($header->ccaddress) : '',
                'cc_list' => $cc_list,
                'bcc' => !empty($header->bccaddress) ? decode_mime($header->bccaddress) : '',
                'bcc_list' => $bcc_list,
                'date' => $date,
                'timestamp' => $timestamp,
                'is_flagged' => isset($header->Flagged) && $header->Flagged === 'F',
                'is_spam' => (strpos(strtolower($folder), 'junk') !== false || strpos(strtolower($folder), 'spam') !== false || strpos(strtolower($folder), 'istenmeyen') !== false),
                'is_draft' => (strpos(strtolower($folder), 'draft') !== false || strpos(strtolower($folder), 'taslak') !== false || (isset($header->Draft) && $header->Draft === 'X')),
                'body_html' => sanitize_email_html($body_html),
                'body_plain' => $body_plain,
                'has_html' => !empty($body_html),
                'attachments' => $attachments
            ]
        ]);
        exit;

    case 'download_attachment':
        $acc = get_active_account($pdo);
        $folder = $_GET['folder'] ?? 'INBOX';
        $uid = (int)($_GET['uid'] ?? 0);
        $part_no = (string)($_GET['part'] ?? '1');
        $req_filename = trim($_GET['filename'] ?? '');

        $conn = connect_imap($acc, $folder);
        if (!$conn || !$uid) {
            die('E-posta açılamadı.');
        }

        $mno = @imap_msgno($conn, $uid);
        if (!$mno) {
            @imap_close($conn);
            die('E-posta bulunamadı.');
        }

        $structure = @imap_fetchstructure($conn, $mno);

        if (!function_exists('find_part_struct_recursive')) {
            function find_part_struct_recursive($part, $pno, $target_pno) {
                if ((string)$pno === (string)$target_pno) return $part;
                if (isset($part->parts) && count($part->parts) > 0) {
                    foreach ($part->parts as $idx => $sub) {
                        $curr = empty($pno) ? (string)($idx + 1) : ($pno . '.' . ($idx + 1));
                        $res = find_part_struct_recursive($sub, $curr, $target_pno);
                        if ($res) return $res;
                    }
                }
                return null;
            }
        }

        $part_struct = find_part_struct_recursive($structure, '', $part_no);
        $data = @imap_fetchbody($conn, $mno, $part_no);

        if ($part_struct) {
            if ($part_struct->encoding == 4) $data = quoted_printable_decode($data);
            elseif ($part_struct->encoding == 3) $data = base64_decode($data);
        } else {
            $data = base64_decode($data);
        }

        @imap_close($conn);

        $out_name = $req_filename ?: ('ek_dosya_' . $part_no);
        $out_name = str_replace(['/', '\\', '"', "\r", "\n"], '_', $out_name);
        $safe_ascii = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $out_name);
        if (empty($safe_ascii)) $safe_ascii = 'ek_dosya';

        $mime_type = 'application/octet-stream';
        if ($part_struct) {
            $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
            $prim = $types[$part_struct->type] ?? 'application';
            $sub = strtolower($part_struct->subtype ?? 'octet-stream');
            $mime_type = $prim . '/' . $sub;
        }

        // Clean buffer before sending binary data
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $safe_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($out_name));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;

    // 3. SEND EMAIL VIA SMTP
    case 'send_email':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.']);
            exit;
        }

        $to = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $bcc = trim($_POST['bcc'] ?? '');

        if (empty($to)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen alıcı (Kime) alanını doldurun.']);
            exit;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $acc->smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $acc->smtp_username ?: $acc->email;
            $mail->Password = $acc->smtp_password;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            $enc = strtolower($acc->smtp_encryption ?: 'ssl');
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = (int)($acc->smtp_port ?: 465);
            } else {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = (int)($acc->smtp_port ?: 587);
            }

            $mail->SMTPOptions = [
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
            ];

            $mail->setFrom($acc->email, $acc->name ?: $acc->email);

            $parse_addrs = function($str) {
                $out = [];
                $items = preg_split('/[,;]+/', (string)$str);
                foreach ($items as $item) {
                    $item = trim($item);
                    if (empty($item)) continue;
                    if (preg_match('/^(.*?)\s*<([^>]+)>$/', $item, $m)) {
                        $nm = trim($m[1], '"\' ');
                        $em = trim($m[2]);
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $out[] = ['email' => $em, 'name' => $nm];
                        }
                    } elseif (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                        $out[] = ['email' => $item, 'name' => ''];
                    }
                }
                return $out;
            };

            $to_list = $parse_addrs($to);
            if (empty($to_list)) {
                echo json_encode(['success' => false, 'message' => 'Lütfen geçerli en az bir alıcı (Kime) adresi girin.']);
                exit;
            }

            foreach ($to_list as $t) {
                $mail->addAddress($t['email'], $t['name']);
            }
            foreach ($parse_addrs($cc) as $c) {
                $mail->addCC($c['email'], $c['name']);
            }
            foreach ($parse_addrs($bcc) as $b) {
                $mail->addBCC($b['email'], $b['name']);
            }

            // Append signature (only if not already included in compose body)
            if (!empty($acc->signature)) {
                $cleanSig = trim(strip_tags($acc->signature));
                $cleanBody = strip_tags($body);
                if (empty($cleanSig) || (mb_stripos($cleanBody, $cleanSig) === false && mb_stripos($body, $acc->signature) === false)) {
                    $body .= "<br><br>--<br>" . $acc->signature;
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<p>', '</p>'], ["\n", "\n", "\n\n"], $body));

            // Attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $mail->addAttachment($_FILES['attachments']['tmp_name'][$i], $_FILES['attachments']['name'][$i]);
                    }
                }
            }

            // Forwarded / existing IMAP attachments
            $forward_atts_json = $_POST['forward_attachments'] ?? '';
            if (!empty($forward_atts_json)) {
                $forward_atts = is_string($forward_atts_json) ? json_decode($forward_atts_json, true) : $forward_atts_json;
                if (is_array($forward_atts)) {
                    if (!function_exists('find_part_struct_recursive')) {
                        function find_part_struct_recursive($part, $pno, $target_pno) {
                            if ((string)$pno === (string)$target_pno) return $part;
                            if (isset($part->parts) && count($part->parts) > 0) {
                                foreach ($part->parts as $idx => $sub) {
                                    $curr = empty($pno) ? (string)($idx + 1) : ($pno . '.' . ($idx + 1));
                                    $res = find_part_struct_recursive($sub, $curr, $target_pno);
                                    if ($res) return $res;
                                }
                            }
                            return null;
                        }
                    }

                    $atts_by_folder = [];
                    foreach ($forward_atts as $fa) {
                        if (!empty($fa['uid']) && isset($fa['part_no'])) {
                            $f_folder = !empty($fa['folder']) ? $fa['folder'] : 'INBOX';
                            $atts_by_folder[$f_folder][] = $fa;
                        }
                    }

                    foreach ($atts_by_folder as $f_folder => $fa_list) {
                        $f_conn = connect_imap($acc, $f_folder);
                        if (!$f_conn) continue;

                        foreach ($fa_list as $fa) {
                            $f_uid = (int)$fa['uid'];
                            $f_part_no = (string)$fa['part_no'];
                            $f_filename = trim($fa['filename'] ?? '') ?: ('ek_dosya_' . $f_part_no);

                            $f_mno = @imap_msgno($f_conn, $f_uid);
                            if (!$f_mno) continue;

                            $f_struct = @imap_fetchstructure($f_conn, $f_mno);
                            $f_part_struct = $f_struct ? find_part_struct_recursive($f_struct, '', $f_part_no) : null;
                            $f_data = @imap_fetchbody($f_conn, $f_mno, $f_part_no);
                            if ($f_data === false) continue;

                            if ($f_part_struct) {
                                if ($f_part_struct->encoding == 4) $f_data = quoted_printable_decode($f_data);
                                elseif ($f_part_struct->encoding == 3) $f_data = base64_decode($f_data);
                            } else {
                                $f_data = base64_decode($f_data);
                            }

                            $f_mime = 'application/octet-stream';
                            if ($f_part_struct) {
                                $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
                                $prim = $types[$f_part_struct->type] ?? 'application';
                                $sub = strtolower($f_part_struct->subtype ?? 'octet-stream');
                                $f_mime = $prim . '/' . $sub;
                            }

                            $mail->addStringAttachment($f_data, $f_filename, 'base64', $f_mime);
                        }

                        @imap_close($f_conn);
                    }
                }
            }

            $mail->send();

            // Automatically save outgoing recipients (To, CC, BCC) into address book if missing
            $outgoing_people = array_merge($to_list, $parse_addrs($cc), $parse_addrs($bcc));
            if (!empty($outgoing_people)) {
                auto_save_contacts_if_missing($pdo, $acc, $outgoing_people, 'E-Posta Kişileri', false);
            }

            // Append to IMAP Sent
            $raw_mime = $mail->getSentMIMEMessage();
            $conn = connect_imap($acc, 'Sent');
            if (!$conn) $conn = connect_imap($acc, 'INBOX.Sent');
            if ($conn) {
                @imap_append($conn, "{" . "{$acc->imap_host}:{$acc->imap_port}/imap/ssl/novalidate-cert" . "}Sent", $raw_mime, "\\Seen", date("d-M-Y H:i:s O"));
                @imap_close($conn);
            }

            // If this email was opened from a draft, remove the draft now that it's sent
            $draft_uid = (int)($_POST['draft_uid'] ?? 0);
            if ($draft_uid > 0) {
                $d_conn = connect_imap($acc, 'Drafts');
                if (!$d_conn) $d_conn = connect_imap($acc, 'INBOX.Drafts');
                if (!$d_conn) $d_conn = connect_imap($acc, 'Taslaklar');
                if ($d_conn) {
                    @imap_delete($d_conn, (string)$draft_uid, FT_UID);
                    @imap_expunge($d_conn);
                    @imap_close($d_conn);
                }
                try {
                    @$pdo->prepare("DELETE FROM mail_drafts WHERE id = ? AND account_id = ?")->execute([$draft_uid, $acc->id]);
                } catch (Exception $ex) {}
            }

            echo json_encode(['success' => true, 'message' => 'E-posta başarıyla gönderildi!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gönderim hatası: ' . ($mail->ErrorInfo ?: $e->getMessage())]);
        }
        exit;

    case 'save_draft':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo safe_json_encode(['success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.']);
            exit;
        }

        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $bcc = trim($_POST['bcc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $draft_uid = (int)($_POST['draft_uid'] ?? 0);

        // Optional DB persistence in mail_drafts table
        $db_draft_id = 0;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `mail_drafts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `account_id` INT NULL DEFAULT 0,
                `to_email` TEXT NULL,
                `cc_email` TEXT NULL,
                `bcc_email` TEXT NULL,
                `subject` VARCHAR(255) NULL,
                `body` LONGTEXT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            if ($draft_uid > 0) {
                $st = $pdo->prepare("SELECT id FROM mail_drafts WHERE account_id = ? AND id = ?");
                $st->execute([$acc->id, $draft_uid]);
                if ($st->fetch()) {
                    $up = $pdo->prepare("UPDATE mail_drafts SET to_email = ?, cc_email = ?, bcc_email = ?, subject = ?, body = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
                    $up->execute([$to, $cc, $bcc, $subject, $body, $draft_uid, $acc->id]);
                    $db_draft_id = $draft_uid;
                }
            }
            if (!$db_draft_id) {
                $ins = $pdo->prepare("INSERT INTO mail_drafts (account_id, to_email, cc_email, bcc_email, subject, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$acc->id, $to, $cc, $bcc, $subject, $body]);
                $db_draft_id = (int)$pdo->lastInsertId();
            }
        } catch (Exception $e) {}

        // Save into IMAP Drafts mailbox
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $new_imap_uid = 0;
        $imap_saved = false;

        try {
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($acc->email, $acc->name ?: $acc->email);

            $parse_addrs = function($str) {
                $out = [];
                $items = preg_split('/[,;]+/', (string)$str);
                foreach ($items as $item) {
                    $item = trim($item);
                    if (empty($item)) continue;
                    if (preg_match('/^(.*?)\s*<([^>]+)>$/', $item, $m)) {
                        $nm = trim($m[1], '"\' ');
                        $em = trim($m[2]);
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $out[] = ['email' => $em, 'name' => $nm];
                        }
                    } elseif (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                        $out[] = ['email' => $item, 'name' => ''];
                    }
                }
                return $out;
            };

            $toList = $parse_addrs($to);
            $ccList = $parse_addrs($cc);
            $bccList = $parse_addrs($bcc);

            $has_recip = false;
            foreach ($toList as $t) {
                $mail->addAddress($t['email'], $t['name']);
                $has_recip = true;
            }
            foreach ($ccList as $c) {
                $mail->addCC($c['email'], $c['name']);
                $has_recip = true;
            }
            foreach ($bccList as $b) {
                $mail->addBCC($b['email'], $b['name']);
                $has_recip = true;
            }

            if (!$has_recip) {
                $mail->addAddress('draft@placeholder.local', '');
            }

            $mail->isHTML(true);
            $mail->Subject = !empty($subject) ? $subject : '(Taslak)';
            $mail->Body = !empty($body) ? $body : '<p></p>';
            $mail->AltBody = strip_tags(str_replace(['<br>', '<p>', '</p>'], ["\n", "\n", "\n\n"], $mail->Body));

            // Attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $mail->addAttachment($_FILES['attachments']['tmp_name'][$i], $_FILES['attachments']['name'][$i]);
                    }
                }
            }

            // Forwarded / existing IMAP attachments
            $forward_atts_json = $_POST['forward_attachments'] ?? '';
            if (!empty($forward_atts_json)) {
                $forward_atts = is_string($forward_atts_json) ? json_decode($forward_atts_json, true) : $forward_atts_json;
                if (is_array($forward_atts)) {
                    if (!function_exists('find_part_struct_recursive')) {
                        function find_part_struct_recursive($part, $pno, $target_pno) {
                            if ((string)$pno === (string)$target_pno) return $part;
                            if (isset($part->parts) && count($part->parts) > 0) {
                                foreach ($part->parts as $idx => $sub) {
                                    $curr = empty($pno) ? (string)($idx + 1) : ($pno . '.' . ($idx + 1));
                                    $res = find_part_struct_recursive($sub, $curr, $target_pno);
                                    if ($res) return $res;
                                }
                            }
                            return null;
                        }
                    }

                    $atts_by_folder = [];
                    foreach ($forward_atts as $fa) {
                        if (!empty($fa['uid']) && isset($fa['part_no'])) {
                            $f_folder = !empty($fa['folder']) ? $fa['folder'] : 'INBOX';
                            $atts_by_folder[$f_folder][] = $fa;
                        }
                    }

                    foreach ($atts_by_folder as $f_folder => $fa_list) {
                        $f_conn = connect_imap($acc, $f_folder);
                        if (!$f_conn) continue;

                        foreach ($fa_list as $fa) {
                            $f_uid = (int)$fa['uid'];
                            $f_part_no = (string)$fa['part_no'];
                            $f_filename = trim($fa['filename'] ?? '') ?: ('ek_dosya_' . $f_part_no);

                            $f_mno = @imap_msgno($f_conn, $f_uid);
                            if (!$f_mno) continue;

                            $f_struct = @imap_fetchstructure($f_conn, $f_mno);
                            $f_part_struct = $f_struct ? find_part_struct_recursive($f_struct, '', $f_part_no) : null;
                            $f_data = @imap_fetchbody($f_conn, $f_mno, $f_part_no);
                            if ($f_data === false) continue;

                            if ($f_part_struct) {
                                if ($f_part_struct->encoding == 4) $f_data = quoted_printable_decode($f_data);
                                elseif ($f_part_struct->encoding == 3) $f_data = base64_decode($f_data);
                            } else {
                                $f_data = base64_decode($f_data);
                            }

                            $f_mime = 'application/octet-stream';
                            if ($f_part_struct) {
                                $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
                                $prim = $types[$f_part_struct->type] ?? 'application';
                                $sub = strtolower($f_part_struct->subtype ?? 'octet-stream');
                                $f_mime = $prim . '/' . $sub;
                            }

                            $mail->addStringAttachment($f_data, $f_filename, 'base64', $f_mime);
                        }

                        @imap_close($f_conn);
                    }
                }
            }

            $mail->preSend();
            $raw_mime = $mail->getSentMIMEMessage();
            if (!$has_recip) {
                $raw_mime = preg_replace('/^To:\s*draft@placeholder\.local\r?\n/m', "To: \r\n", $raw_mime, 1);
            }

            $target_draft_folder = 'Drafts';
            $conn = connect_imap($acc, 'Drafts');
            if (!$conn) {
                $conn = connect_imap($acc, 'INBOX.Drafts');
                if ($conn) $target_draft_folder = 'INBOX.Drafts';
            }
            if (!$conn) {
                $conn = connect_imap($acc, 'Taslaklar');
                if ($conn) $target_draft_folder = 'Taslaklar';
            }

            if ($conn) {
                if ($draft_uid > 0) {
                    @imap_delete($conn, (string)$draft_uid, FT_UID);
                    @imap_expunge($conn);
                }

                $flags = (strtolower($acc->imap_encryption ?: 'ssl') === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
                $mailbox_spec = "{" . "{$acc->imap_host}:{$acc->imap_port}{$flags}" . "}" . safe_utf7_encode($target_draft_folder);
                
                $appended = @imap_append($conn, $mailbox_spec, $raw_mime, "\\Draft \\Seen", date("d-M-Y H:i:s O"));
                if ($appended) {
                    $imap_saved = true;
                    $check = @imap_check($conn);
                    if ($check && $check->Nmsgs > 0) {
                        $new_imap_uid = @imap_uid($conn, $check->Nmsgs);
                    }
                }
                @imap_close($conn);
            }
        } catch (Exception $e) {
            // Ignore IMAP error if DB saved
        }

        echo safe_json_encode([
            'success' => true,
            'message' => 'Taslak başarıyla kaydedildi.',
            'draft_uid' => $new_imap_uid ?: ($draft_uid ?: $db_draft_id),
            'db_draft_id' => $db_draft_id,
            'time' => date('H:i')
        ]);
        exit;

    // 4. MARK STATUS (FLAG/UNFLAG/SEEN/UNSEEN)
    case 'mark_status':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        $uid = (int)($_POST['uid'] ?? 0);
        $type = $_POST['type'] ?? '';

        $conn = connect_imap($acc, $folder);
        if (!$conn || !$uid) {
            echo json_encode(['success' => false]);
            exit;
        }

        if ($type === 'flagged') @imap_setflag_full($conn, (string)$uid, "\\Flagged", ST_UID);
        elseif ($type === 'unflagged') @imap_clearflag_full($conn, (string)$uid, "\\Flagged", ST_UID);
        elseif ($type === 'seen') @imap_setflag_full($conn, (string)$uid, "\\Seen", ST_UID);
        elseif ($type === 'unseen') @imap_clearflag_full($conn, (string)$uid, "\\Seen", ST_UID);

        @imap_close($conn);
        echo json_encode(['success' => true]);
        exit;

    // 5. DELETE EMAIL
    case 'delete_email':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        $uid = (int)($_POST['uid'] ?? 0);

        $conn = connect_imap($acc, $folder);
        if (!$conn || !$uid) {
            echo json_encode(['success' => false]);
            exit;
        }

        // Try moving to Trash if not already in Trash
        $is_trash = (strpos(strtolower($folder), 'trash') !== false || strpos(strtolower($folder), 'çöp') !== false);
        if (!$is_trash) {
            $moved = @imap_mail_move($conn, (string)$uid, 'Trash', CP_UID);
            if (!$moved) {
                $moved = @imap_mail_move($conn, (string)$uid, 'INBOX.Trash', CP_UID);
            }
            if ($moved) {
                @imap_expunge($conn);
                @imap_close($conn);
                echo json_encode(['success' => true]);
                exit;
            }
        }

        @imap_delete($conn, (string)$uid, FT_UID);
        @imap_expunge($conn);
        @imap_close($conn);
        echo json_encode(['success' => true]);
        exit;

    // 5.01 SINGLE MARK SPAM / UNMARK SPAM
    case 'mark_spam':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        $uid = (int)($_POST['uid'] ?? 0);
        $spam_action = $_POST['spam_action'] ?? null; // 'spam' or 'unspam' or null for auto

        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmamış veya hesap bulunamadı.']);
            exit;
        }

        $conn = connect_imap($acc, $folder);
        if (!$conn || !$uid) {
            echo json_encode(['success' => false, 'message' => 'Bağlantı kurulamadı veya geçersiz e-posta UID.']);
            exit;
        }

        $is_in_spam = (strpos(strtolower($folder), 'junk') !== false || strpos(strtolower($folder), 'spam') !== false || strpos(strtolower($folder), 'istenmeyen') !== false);

        if ($spam_action === 'unspam' || ($spam_action === null && $is_in_spam)) {
            $moved = @imap_mail_move($conn, (string)$uid, 'INBOX', CP_UID);
            @imap_expunge($conn);
            @imap_close($conn);

            echo json_encode([
                'success' => (bool)$moved,
                'is_spam' => false,
                'message' => $moved ? 'E-posta spam listesinden çıkarıldı ve Gelen Kutusu\'na taşındı.' : 'E-posta taşınamadı.'
            ]);
            exit;
        } else {
            $target_candidates = ['Junk', 'Spam', 'INBOX.Junk', 'INBOX.Spam'];
            $moved = false;
            foreach ($target_candidates as $cand) {
                if (@imap_mail_move($conn, (string)$uid, safe_utf7_encode($cand), CP_UID)) {
                    $moved = true;
                    break;
                }
            }

            if (!$moved) {
                ensure_imap_folder($acc, 'Junk');
                $moved = @imap_mail_move($conn, (string)$uid, 'Junk', CP_UID);
            }

            @imap_expunge($conn);
            @imap_close($conn);

            echo json_encode([
                'success' => (bool)$moved,
                'is_spam' => true,
                'message' => $moved ? 'E-posta İstenmeyen (Spam) olarak işaretlendi ve taşındı.' : 'E-posta taşınamadı.'
            ]);
            exit;
        }

    // 5.02 BULK MARK SPAM (SEÇİLENLERİ SPAM / SPAM DEĞİL YAP)
    case 'bulk_mark_spam':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        $spam_action = $_POST['spam_action'] ?? null;
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.']);
            exit;
        }

        $conn = connect_imap($acc, $folder);
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı.']);
            exit;
        }

        $uids = resolve_bulk_target_uids($conn);
        if (empty($uids)) {
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Lütfen en az bir e-posta seçin.']);
            exit;
        }

        $chunks = array_chunk($uids, 500);
        $is_in_spam = (strpos(strtolower($folder), 'junk') !== false || strpos(strtolower($folder), 'spam') !== false || strpos(strtolower($folder), 'istenmeyen') !== false);

        if ($spam_action === 'unspam' || ($spam_action === null && $is_in_spam)) {
            $moved = false;
            foreach ($chunks as $chunk) {
                if (@imap_mail_move($conn, implode(',', $chunk), 'INBOX', CP_UID)) {
                    $moved = true;
                }
            }
            @imap_expunge($conn);
            @imap_close($conn);
            $cnt = count($uids);
            echo json_encode([
                'success' => (bool)$moved,
                'is_spam' => false,
                'count' => $cnt,
                'message' => "Seçilen {$cnt} e-posta Gelen Kutusu'na taşındı."
            ]);
            exit;
        } else {
            $target_candidates = ['Junk', 'Spam', 'INBOX.Junk', 'INBOX.Spam'];
            $moved = false;
            $valid_cand = null;
            foreach ($target_candidates as $cand) {
                $enc = safe_utf7_encode($cand);
                if (@imap_mail_move($conn, implode(',', $chunks[0]), $enc, CP_UID)) {
                    $moved = true;
                    $valid_cand = $enc;
                    break;
                }
            }
            if (!$moved) {
                ensure_imap_folder($acc, 'Junk');
                $valid_cand = 'Junk';
                $moved = @imap_mail_move($conn, implode(',', $chunks[0]), 'Junk', CP_UID);
            }
            if ($moved && $valid_cand && count($chunks) > 1) {
                for ($ci = 1; $ci < count($chunks); $ci++) {
                    @imap_mail_move($conn, implode(',', $chunks[$ci]), $valid_cand, CP_UID);
                }
            }
            @imap_expunge($conn);
            @imap_close($conn);
            $cnt = count($uids);
            echo json_encode([
                'success' => (bool)$moved,
                'is_spam' => true,
                'count' => $cnt,
                'message' => "Seçilen {$cnt} e-posta İstenmeyen (Spam) klasörüne taşındı."
            ]);
            exit;
        }

    // 5.1 BULK DELETE EMAILS (ÇOKLU SEÇİMLE SİLME)
    case 'bulk_delete_emails':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.']);
            exit;
        }

        $conn = connect_imap($acc, $folder);
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı.']);
            exit;
        }

        $uids = resolve_bulk_target_uids($conn);
        if (empty($uids)) {
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Lütfen silinecek en az bir e-posta seçin.']);
            exit;
        }

        $chunks = array_chunk($uids, 500);
        $is_trash = (strpos(strtolower($folder), 'trash') !== false || strpos(strtolower($folder), 'çöp') !== false);

        foreach ($chunks as $chunk) {
            $uid_seq = implode(',', $chunk);
            if (!$is_trash) {
                $moved = @imap_mail_move($conn, $uid_seq, 'Trash', CP_UID);
                if (!$moved) {
                    $moved = @imap_mail_move($conn, $uid_seq, 'INBOX.Trash', CP_UID);
                }
                if (!$moved) {
                    @imap_setflag_full($conn, $uid_seq, "\\Deleted", ST_UID);
                }
            } else {
                @imap_setflag_full($conn, $uid_seq, "\\Deleted", ST_UID);
            }
        }

        @imap_expunge($conn);
        @imap_close($conn);

        $cnt = count($uids);
        echo json_encode([
            'success' => true,
            'count' => $cnt,
            'message' => "Seçilen {$cnt} e-posta başarıyla " . ($is_trash ? "kalıcı olarak silindi." : "Çöp Kutusuna taşındı.")
        ]);
        exit;

    // 5.2 BULK MARK STATUS (SEÇİLENLERİ OKUNDU / OKUNMADI / YILDIZLI YAP)
    case 'bulk_mark_status':
        $acc = get_active_account($pdo);
        $folder = $_POST['folder'] ?? 'INBOX';
        $type = $_POST['type'] ?? '';
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.']);
            exit;
        }

        $conn = connect_imap($acc, $folder);
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı.']);
            exit;
        }

        $uids = resolve_bulk_target_uids($conn);
        if (empty($uids)) {
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Lütfen en az bir e-posta seçin.']);
            exit;
        }

        $chunks = array_chunk($uids, 500);
        foreach ($chunks as $chunk) {
            $uid_seq = implode(',', $chunk);
            if ($type === 'seen') @imap_setflag_full($conn, $uid_seq, "\\Seen", ST_UID);
            elseif ($type === 'unseen') @imap_clearflag_full($conn, $uid_seq, "\\Seen", ST_UID);
            elseif ($type === 'flagged') @imap_setflag_full($conn, $uid_seq, "\\Flagged", ST_UID);
            elseif ($type === 'unflagged') @imap_clearflag_full($conn, $uid_seq, "\\Flagged", ST_UID);
        }

        @imap_close($conn);
        echo json_encode(['success' => true, 'count' => count($uids)]);
        exit;

    // 5.3 BULK MOVE EMAILS (ÇOKLU VEYA TÜM KLASÖRÜ TAŞIMA)
    case 'bulk_move_emails':
        $acc = get_active_account($pdo);
        $from_folder = $_POST['from_folder'] ?? ($_POST['folder'] ?? 'INBOX');
        $to_folder = trim($_POST['to_folder'] ?? '');

        if (!$acc || empty($to_folder)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz klasör parametresi.']);
            exit;
        }
        if ($from_folder === $to_folder) {
            echo json_encode(['success' => false, 'message' => 'Hedef klasör mevcut klasörle aynı olamaz.']);
            exit;
        }

        $conn = connect_imap($acc, $from_folder);
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı.']);
            exit;
        }

        $uids = resolve_bulk_target_uids($conn);
        if (empty($uids)) {
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Taşınacak e-posta bulunamadı.']);
            exit;
        }

        ensure_imap_folder($acc, $to_folder);

        $encoded_target = safe_utf7_encode($to_folder);
        $candidates = [$encoded_target];
        if (stripos($encoded_target, 'INBOX.') !== 0) {
            $candidates[] = 'INBOX.' . $encoded_target;
        } else {
            $candidates[] = substr($encoded_target, 6);
        }
        if ($encoded_target !== $to_folder) {
            $candidates[] = $to_folder;
        }
        $candidates = array_unique($candidates);

        $chunks = array_chunk($uids, 500);
        $moved = false;
        $valid_cand = null;

        foreach ($candidates as $cand) {
            if (@imap_mail_move($conn, implode(',', $chunks[0]), $cand, CP_UID)) {
                $moved = true;
                $valid_cand = $cand;
                break;
            }
        }

        if ($moved && $valid_cand && count($chunks) > 1) {
            for ($ci = 1; $ci < count($chunks); $ci++) {
                @imap_mail_move($conn, implode(',', $chunks[$ci]), $valid_cand, CP_UID);
            }
        }

        if ($moved) {
            @imap_expunge($conn);
            @imap_close($conn);
            $cnt = count($uids);
            echo json_encode([
                'success' => true,
                'count' => $cnt,
                'message' => "{$cnt} adet e-posta başarıyla taşındı."
            ]);
        } else {
            $err = imap_last_error() ?: 'Taşıma işlemi gerçekleştirilemedi.';
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Taşıma hatası: ' . $err]);
        }
        exit;

    // 6. CONTACTS: LIST, SAVE, DELETE, FAVORITE, AUTOCOMPLETE
    case 'get_contacts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.', 'contacts' => [], 'groups' => []]);
            exit;
        }

        $search = trim($_GET['q'] ?? '');
        $group = $_GET['group'] ?? 'all';
        $fav = ($_GET['favorites'] ?? '') == '1';

        // Gelen ve gönderilen maillerdeki kişileri rehberde yoksa otomatik tamamla (120 sn korumalı)
        if (empty($search) && $group === 'all' && !$fav) {
            auto_sync_recent_mailbox_contacts($pdo, $acc, 80);
        }

        $sql = "SELECT * FROM contacts WHERE account_id = ?";
        $params = [$acc->id];

        if (!empty($search)) {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ? OR phone LIKE ?)";
            $term = "%{$search}%";
            $params = array_merge($params, [$term, $term, $term, $term, $term]);
        }
        if ($group !== 'all') {
            $sql .= " AND group_name = ?";
            $params[] = $group;
        }
        if ($fav) {
            $sql .= " AND is_favorite = 1";
        }
        $sql .= " ORDER BY is_favorite DESC, first_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();

        // İlk girişte rehber boş ise kurumsal global rehberden otomatik çek
        if (empty($contacts) && empty($search) && $group === 'all' && !$fav) {
            $syncRes = sync_domain_global_directory($pdo, $acc);
            if (!empty($syncRes['added_to_personal'])) {
                $stmt->execute($params);
                $contacts = $stmt->fetchAll();
            }
        }

        // Groups for active account
        $stmtGrps = $pdo->prepare("SELECT group_name, COUNT(id) as total FROM contacts WHERE account_id = ? GROUP BY group_name ORDER BY group_name ASC");
        $stmtGrps->execute([$acc->id]);
        $groups = $stmtGrps->fetchAll();

        echo json_encode(['success' => true, 'contacts' => $contacts, 'groups' => $groups]);
        exit;

    case 'get_global_contacts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.', 'contacts' => [], 'domain' => '']);
            exit;
        }

        $myEmail = strtolower(trim($acc->email));
        $parts = explode('@', $myEmail);
        $domain = strtolower($parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : ''));

        if (empty($domain)) {
            echo json_encode(['success' => true, 'contacts' => [], 'domain' => '']);
            exit;
        }

        $search = trim($_GET['q'] ?? '');

        // Fetch all domain directory accounts + logged in mail_accounts
        $allDict = []; // email => name

        // A. From domain_directory
        try {
            $ddSql = "SELECT email, name FROM domain_directory WHERE domain = ?";
            $ddParams = [$domain];
            if (!empty($search)) {
                $ddSql .= " AND (name LIKE ? OR email LIKE ?)";
                $ddParams[] = "%{$search}%";
                $ddParams[] = "%{$search}%";
            }
            $ddStmt = $pdo->prepare($ddSql);
            $ddStmt->execute($ddParams);
            while ($r = $ddStmt->fetch()) {
                $allDict[strtolower(trim($r->email))] = trim($r->name);
            }
        } catch (Exception $e) {}

        // B. From mail_accounts
        try {
            $maSql = "SELECT email, name FROM mail_accounts WHERE LOWER(email) LIKE ?";
            $maParams = ['%@' . $domain];
            if (!empty($search)) {
                $maSql .= " AND (name LIKE ? OR email LIKE ?)";
                $maParams[] = "%{$search}%";
                $maParams[] = "%{$search}%";
            }
            $maStmt = $pdo->prepare($maSql);
            $maStmt->execute($maParams);
            while ($r = $maStmt->fetch()) {
                $em = strtolower(trim($r->email));
                if (empty($allDict[$em]) || !empty($r->name)) {
                    $allDict[$em] = trim($r->name);
                }
            }
        } catch (Exception $e) {}

        // Check which ones are already saved in active user's personal address book
        $chkStmt = $pdo->prepare("SELECT LOWER(TRIM(email)) FROM contacts WHERE account_id = ?");
        $chkStmt->execute([$acc->id]);
        $existingEmails = array_flip($chkStmt->fetchAll(PDO::FETCH_COLUMN));

        $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#0284c7', '#10b981'];
        $globalContacts = [];

        foreach ($allDict as $em => $nameStr) {
            $isMe = ($em === $myEmail);
            if (empty($nameStr)) {
                $userPart = explode('@', $em)[0];
                $cleanUser = str_replace(['.', '_', '-'], ' ', $userPart);
                $nameStr = mb_convert_case($cleanUser, MB_CASE_TITLE, 'UTF-8');
            }

            $globalContacts[] = [
                'id' => abs(crc32($em)),
                'email' => $em,
                'name' => $nameStr,
                'domain' => $domain,
                'is_me' => $isMe,
                'is_saved' => isset($existingEmails[$em]),
                'avatar_color' => $colors[abs(crc32($em)) % count($colors)]
            ];
        }

        // Sort by name ASC
        usort($globalContacts, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        echo json_encode([
            'success' => true, 
            'domain' => $domain, 
            'total' => count($globalContacts),
            'contacts' => $globalContacts
        ]);
        exit;

    case 'autocomplete_contacts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['results' => []]);
            exit;
        }

        $q = trim($_GET['q'] ?? '');
        $results = [];
        $seen = [];

        // 1. Account's own contacts from address book
        $sql = "SELECT id, first_name, last_name, email, company, job_title, avatar_color, is_favorite 
                FROM contacts 
                WHERE account_id = ?";
        $params = [$acc->id];
        if (!empty($q)) {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)";
            $term = "%{$q}%";
            $params = array_merge($params, [$term, $term, $term, $term]);
        }
        $sql .= " ORDER BY is_favorite DESC, first_name ASC LIMIT 35";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();

        foreach ($contacts as $c) {
            $email = strtolower(trim($c->email));
            if (empty($email) || isset($seen[$email])) continue;
            $seen[$email] = true;
            $fullName = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
            $displayText = $fullName ? "{$fullName} <{$c->email}>" : $c->email;
            $results[] = [
                'id' => $c->email,
                'text' => $displayText,
                'name' => $fullName,
                'email' => $c->email,
                'company' => $c->company ?? '',
                'avatar_color' => $c->avatar_color ?: '#4f46e5',
                'is_favorite' => (bool)$c->is_favorite,
                'is_global' => false
            ];
        }

        // 2. Global accounts belonging ONLY to THIS DOMAIN that have logged into the system
        $myEmail = strtolower(trim($acc->email));
        $myParts = explode('@', $myEmail);
        $myDomain = $myParts[1] ?? '';

        if (!empty($myDomain)) {
            $srvSql = "SELECT id, email, name FROM mail_accounts WHERE email != ? AND LOWER(email) LIKE ?";
            $srvParams = [$acc->email, '%@' . $myDomain];
            if (!empty($q)) {
                $srvSql .= " AND (email LIKE ? OR name LIKE ?)";
                $term = "%{$q}%";
                $srvParams = array_merge($srvParams, [$term, $term]);
            }
            $srvSql .= " ORDER BY email ASC LIMIT 15";
            $stmtSrv = $pdo->prepare($srvSql);
            $stmtSrv->execute($srvParams);
            $srvAccounts = $stmtSrv->fetchAll();

            foreach ($srvAccounts as $sa) {
                $email = strtolower(trim($sa->email));
                if (empty($email) || isset($seen[$email])) continue;
                $seen[$email] = true;
                $fullName = trim($sa->name ?? '');
                $displayText = $fullName ? "{$fullName} <{$sa->email}>" : $sa->email;
                $results[] = [
                    'id' => $sa->email,
                    'text' => $displayText,
                    'name' => $fullName,
                    'email' => $sa->email,
                    'company' => '@' . $myDomain,
                    'avatar_color' => '#059669',
                    'is_favorite' => false,
                    'is_global' => true
                ];
            }
        }

        echo json_encode(['results' => $results]);
        exit;

    case 'save_contact':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $title = trim($_POST['job_title'] ?? '');
        $group = trim($_POST['group_name'] ?? 'Genel');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($first) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Ad ve E-posta alanları zorunludur.']);
            exit;
        }

        // Duplicate email validation within this account's contacts
        $email_clean = strtolower(trim($email));
        $chk = $pdo->prepare("SELECT id FROM contacts WHERE account_id = ? AND LOWER(TRIM(email)) = ? AND id != ?");
        $chk->execute([$acc->id, $email_clean, $id]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => "Bu e-posta adresi ({$email}) rehberinizde zaten kayıtlı! Aynı e-posta adresiyle mükerrer kayıt oluşturulamaz."]);
            exit;
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE contacts SET first_name=?, last_name=?, email=?, phone=?, company=?, job_title=?, group_name=?, notes=? WHERE id=? AND account_id=?");
            $stmt->execute([$first, $last, $email, $phone, $company, $title, $group, $notes, $id, $acc->id]);
            $msg = 'Kişi güncellendi.';
        } else {
            $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2'];
            $color = $colors[array_rand($colors)];
            $stmt = $pdo->prepare("INSERT INTO contacts (account_id, first_name, last_name, email, phone, company, job_title, group_name, notes, avatar_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$acc->id, $first, $last, $email, $phone, $company, $title, $group, $notes, $color]);
            $id = $pdo->lastInsertId();
            $msg = 'Kişi eklendi.';
        }

        // Remove from deleted-contacts ignore list if manually saved
        $delFile = sys_get_temp_dir() . '/wm_del_contacts_' . (int)$acc->id . '.cache';
        if (file_exists($delFile)) {
            $delMap = @unserialize(@file_get_contents($delFile));
            if (is_array($delMap) && isset($delMap[$email_clean])) {
                unset($delMap[$email_clean]);
                @file_put_contents($delFile, serialize($delMap), LOCK_EX);
            }
        }

        echo json_encode(['success' => true, 'message' => $msg, 'id' => $id]);
        exit;

    case 'delete_contact':
        $acc = get_active_account($pdo);
        if (!$acc) exit;
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

        // Record manually deleted email so background auto-sync doesn't immediately re-add it
        $stDel = $pdo->prepare("SELECT email FROM contacts WHERE id = ? AND account_id = ?");
        $stDel->execute([$id, $acc->id]);
        $delEmail = strtolower(trim((string)$stDel->fetchColumn()));
        if (!empty($delEmail)) {
            $delFile = sys_get_temp_dir() . '/wm_del_contacts_' . (int)$acc->id . '.cache';
            $delMap = [];
            if (file_exists($delFile)) {
                $loaded = @unserialize(@file_get_contents($delFile));
                if (is_array($loaded)) $delMap = $loaded;
            }
            $delMap[$delEmail] = time();
            @file_put_contents($delFile, serialize($delMap), LOCK_EX);
        }

        $pdo->prepare("DELETE FROM contacts WHERE id = ? AND account_id = ?")->execute([$id, $acc->id]);
        echo json_encode(['success' => true]);
        exit;

    case 'toggle_contact_fav':
        $acc = get_active_account($pdo);
        if (!$acc) exit;
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT is_favorite FROM contacts WHERE id = ? AND account_id = ?");
        $stmt->execute([$id, $acc->id]);
        $curr = $stmt->fetchColumn();
        $next = $curr ? 0 : 1;
        $pdo->prepare("UPDATE contacts SET is_favorite = ? WHERE id = ? AND account_id = ?")->execute([$next, $id, $acc->id]);
        echo json_encode(['success' => true, 'is_favorite' => $next]);
        exit;

    // AUTO SYNC CONTACTS FROM SENT FOLDER ONLY
    case 'sync_contacts_from_mailbox':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Aktif mail hesabı bulunamadı.']);
            exit;
        }

        $host = $acc->imap_host;
        $port = (int)($acc->imap_port ?: 993);
        $enc = strtolower($acc->imap_encryption ?: 'ssl');
        $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
        $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

        $discovered = [];

        // Scan Sent folder only (outgoing emails)
        $conn = @imap_open($server_prefix . 'Sent', $acc->imap_username, $acc->imap_password, 0, 1);
        if (!$conn) $conn = @imap_open($server_prefix . 'INBOX.Sent', $acc->imap_username, $acc->imap_password, 0, 1);
        if ($conn) {
            $num = imap_num_msg($conn);
            if ($num > 0) {
                $start = max(1, $num - 600);
                $overviews = @imap_fetch_overview($conn, "{$start}:{$num}");
                if ($overviews) {
                    foreach ($overviews as $ov) {
                        $targets = [];
                        if (!empty($ov->to)) $targets[] = $ov->to;
                        if (!empty($ov->cc)) $targets[] = $ov->cc;
                        foreach ($targets as $tgt) {
                            $parsed = @imap_rfc822_parse_adrlist($tgt, 'default');
                            if ($parsed) {
                                foreach ($parsed as $ad) {
                                    if (!empty($ad->mailbox) && !empty($ad->host) && $ad->host !== '.SYNTAX-ERROR.') {
                                        $em = strtolower(trim($ad->mailbox . '@' . $ad->host));
                                        $nm = !empty($ad->personal) ? decode_mime($ad->personal) : '';
                                        if (filter_var($em, FILTER_VALIDATE_EMAIL) && !isset($discovered[$em])) {
                                            $discovered[$em] = $nm;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            @imap_close($conn);
        }

        $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#0284c7', '#10b981'];
        $added_count = 0;

        foreach ($discovered as $em => $fullName) {
            if (preg_match('/(no-?reply|mailer-daemon|postmaster|bounce|notifications)/i', $em)) continue;

            $fullName = trim($fullName);
            if (empty($fullName) || $fullName === $em) {
                $prefix = explode('@', $em)[0];
                $parts = explode('.', str_replace(['_', '-'], '.', $prefix));
                $first_name = ucfirst($parts[0]);
                $last_name = isset($parts[1]) ? ucfirst($parts[1]) : '';
            } else {
                $parts = explode(' ', $fullName);
                $first_name = array_shift($parts);
                $last_name = implode(' ', $parts);
            }

            $chk = $pdo->prepare("SELECT id FROM contacts WHERE account_id = ? AND LOWER(TRIM(email)) = ?");
            $chk->execute([$acc->id, strtolower(trim($em))]);
            if (!$chk->fetch()) {
                $color = $colors[array_rand($colors)];
                $ins = $pdo->prepare("INSERT INTO contacts (account_id, first_name, last_name, email, group_name, avatar_color) VALUES (?, ?, ?, ?, 'E-Posta Kişileri', ?)");
                $ins->execute([$acc->id, $first_name, $last_name, $em, $color]);
                $added_count++;
            }
        }

        $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE account_id = ?");
        $stmtTot->execute([$acc->id]);
        $total_contacts = $stmtTot->fetchColumn();

        echo json_encode([
            'success' => true,
            'message' => "{$added_count} yeni kişi maillerden aktarıldı. Toplam {$total_contacts} kişi kayıtlı.",
            'added_count' => $added_count,
            'total' => (int)$total_contacts
        ]);
        exit;

    // SYNC GLOBAL SERVER CONTACTS (ACCOUNTS ON SAME SERVER / DOMAIN)
    case 'sync_global_contacts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Aktif mail hesabı bulunamadı.']);
            exit;
        }

        $res = sync_domain_global_directory($pdo, $acc);

        $totalContactsStmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE account_id = ?");
        $totalContactsStmt->execute([$acc->id]);
        $totalContacts = (int)$totalContactsStmt->fetchColumn();

        $existingCount = max(0, $res['total'] - $res['added_to_personal']);

        echo json_encode([
            'success' => true,
            'message' => $res['added_to_personal'] > 0 
                ? "Global kurumsal rehberden {$res['added_to_personal']} yeni e-posta adresi adres defterinize eklendi!" 
                : "Domaine ait tüm kurumsal hesaplar ({$res['total']} adet) zaten defterinizde kayıtlı.",
            'added_count' => $res['added_to_personal'],
            'existing_count' => $existingCount,
            'total_server_accounts' => $res['total'],
            'total' => $totalContacts
        ]);
        exit;

    // IMPORT CONTACTS FROM CSV FILE
    case 'import_contacts_csv':
        $acc = get_active_account($pdo);
        $uploadField = !empty($_FILES['file']) ? 'file' : (!empty($_FILES['csv_file']) ? 'csv_file' : null);
        if (!$uploadField || $_FILES[$uploadField]['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Lütfen geçerli bir CSV dosyası yükleyin.']);
            exit;
        }

        $tmpPath = $_FILES[$uploadField]['tmp_name'];
        $content = file_get_contents($tmpPath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        $lines = preg_split('/[\r\n]+/', trim($content));
        if (empty($lines)) {
            echo json_encode(['success' => false, 'message' => 'CSV dosyası boş.']);
            exit;
        }

        $firstLine = $lines[0];
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $header = str_getcsv($lines[0], $delimiter);
        $headerMap = [];
        foreach ($header as $idx => $col) {
            $colClean = mb_strtolower(trim(str_replace(['"', "'"], '', $col)), 'UTF-8');
            $colAscii = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'], ['i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c'], $colClean);
            if (in_array($colAscii, ['ad', 'first_name', 'firstname', 'isim', 'given name'])) $headerMap['first_name'] = $idx;
            elseif (in_array($colAscii, ['soyad', 'last_name', 'lastname', 'soyisim', 'family name'])) $headerMap['last_name'] = $idx;
            elseif (in_array($colAscii, ['ad soyad', 'name', 'full_name', 'fullname', 'kisi', 'kisi adi'])) $headerMap['full_name'] = $idx;
            elseif (in_array($colAscii, ['email', 'eposta', 'e-posta', 'e-mail', 'mail', 'primary email', 'e posta'])) $headerMap['email'] = $idx;
            elseif (in_array($colAscii, ['telefon', 'phone', 'tel', 'mobile', 'cep', 'gsm'])) $headerMap['phone'] = $idx;
            elseif (in_array($colAscii, ['sirket', 'company', 'organization', 'kurum', 'firma'])) $headerMap['company'] = $idx;
            elseif (in_array($colAscii, ['gorev', 'unvan', 'title', 'job title', 'pozisyon'])) $headerMap['job_title'] = $idx;
            elseif (in_array($colAscii, ['grup', 'kategori', 'group', 'category'])) $headerMap['group_name'] = $idx;
        }

        $hasHeader = isset($headerMap['email']) || isset($headerMap['first_name']) || isset($headerMap['full_name']);
        $startIdx = $hasHeader ? 1 : 0;
        $colors = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#0284c7', '#10b981'];
        $imported = 0;
        $seenInBatch = [];

        for ($i = $startIdx; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            $row = str_getcsv($line, $delimiter);

            $email = '';
            $first = '';
            $last = '';
            $phone = '';
            $company = '';
            $title = '';

            if ($hasHeader) {
                if (isset($headerMap['email']) && isset($row[$headerMap['email']])) $email = trim($row[$headerMap['email']]);
                if (isset($headerMap['first_name']) && isset($row[$headerMap['first_name']])) $first = trim($row[$headerMap['first_name']]);
                if (isset($headerMap['last_name']) && isset($row[$headerMap['last_name']])) $last = trim($row[$headerMap['last_name']]);
                if (isset($headerMap['full_name']) && isset($row[$headerMap['full_name']])) {
                    $parts = explode(' ', trim($row[$headerMap['full_name']]));
                    $first = array_shift($parts);
                    $last = implode(' ', $parts);
                }
                if (isset($headerMap['phone']) && isset($row[$headerMap['phone']])) $phone = trim($row[$headerMap['phone']]);
                if (isset($headerMap['company']) && isset($row[$headerMap['company']])) $company = trim($row[$headerMap['company']]);
                if (isset($headerMap['job_title']) && isset($row[$headerMap['job_title']])) $title = trim($row[$headerMap['job_title']]);
                if (isset($headerMap['group_name']) && isset($row[$headerMap['group_name']])) $groupName = trim($row[$headerMap['group_name']]);
            } else {
                $first = $row[0] ?? '';
                $last = $row[1] ?? '';
                $email = $row[2] ?? '';
                $phone = $row[3] ?? '';
                $company = $row[4] ?? '';
                if (filter_var($last, FILTER_VALIDATE_EMAIL) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = $last;
                    $last = '';
                }
            }

            if (empty($groupName)) $groupName = 'İçe Aktarılanlar';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                foreach ($row as $cell) {
                    if (filter_var(trim($cell), FILTER_VALIDATE_EMAIL)) {
                        $email = trim($cell);
                        break;
                    }
                }
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            if (empty($first)) {
                $prefix = explode('@', $email)[0];
                $parts = explode('.', str_replace(['_', '-'], '.', $prefix));
                $first = ucfirst($parts[0]);
                if (empty($last) && isset($parts[1])) $last = ucfirst($parts[1]);
            }

            $clean_email = strtolower(trim($email));
            if (isset($seenInBatch[$clean_email])) continue;
            $seenInBatch[$clean_email] = true;

            $chk = $pdo->prepare("SELECT id FROM contacts WHERE account_id = ? AND LOWER(TRIM(email)) = ?");
            $chk->execute([$acc->id, $clean_email]);
            if (!$chk->fetch()) {
                $color = $colors[array_rand($colors)];
                $ins = $pdo->prepare("INSERT INTO contacts (account_id, first_name, last_name, email, phone, company, job_title, group_name, avatar_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$acc->id, $first, $last, $email, $phone, $company, $title, $groupName, $color]);
                $imported++;
            }
        }

        $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE account_id = ?");
        $stmtTot->execute([$acc->id]);
        $total_contacts = $stmtTot->fetchColumn();

        echo json_encode([
            'success' => true,
            'message' => "{$imported} kişi başarıyla içe aktarıldı. Toplam {$total_contacts} kişi kayıtlı.",
            'imported_count' => $imported,
            'total' => (int)$total_contacts
        ]);
        exit;

    case 'autocomplete_contacts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode([]);
            exit;
        }
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            echo json_encode([]);
            exit;
        }
        $term = "%{$q}%";
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, company FROM contacts WHERE account_id = ? AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) LIMIT 10");
        $stmt->execute([$acc->id, $term, $term, $term]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also include matching accounts from the same server
        $seenEmails = array_map(function($r) { return strtolower($r['email']); }, $results);
        $serverStmt = $pdo->prepare("SELECT id, name, email FROM mail_accounts WHERE id != ? AND (name LIKE ? OR email LIKE ?) LIMIT 5");
        $serverStmt->execute([$acc->id, $term, $term]);
        foreach ($serverStmt->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
            $sEmail = strtolower(trim($sRow['email']));
            if (!in_array($sEmail, $seenEmails)) {
                $nameParts = preg_split('/\s+/', trim($sRow['name'] ?: $sRow['email']));
                $results[] = [
                    'id' => 'server_' . $sRow['id'],
                    'first_name' => $nameParts[0],
                    'last_name' => count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '',
                    'email' => $sEmail,
                    'company' => 'Sunucu / Kurumsal'
                ];
                $seenEmails[] = $sEmail;
            }
        }
        echo json_encode($results);
        exit;

    // 7. CALENDAR: EVENTS LIST, SAVE, DELETE, ICS EXPORT
    // 7. CALENDAR: DOMAIN-SHARED & PERSONAL EVENTS
    case 'get_calendar_events':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode([]);
            exit;
        }
        $start = $_GET['start'] ?? date('Y-m-01 00:00:00');
        $end = $_GET['end'] ?? date('Y-m-t 23:59:59');

        $accId = (int)$acc->id;
        $parts = explode('@', $acc->email);
        $myDomain = strtolower($parts[1] ?? '');

        // Query: personal events of this account OR shared events of this domain!
        if (!empty($myDomain)) {
            $stmt = $pdo->prepare("SELECT * FROM calendar_events 
                WHERE (account_id = ? OR (domain = ? AND (is_shared = 1 OR is_shared IS NULL))) 
                  AND start_date <= ? AND end_date >= ? 
                ORDER BY start_date ASC");
            $stmt->execute([$accId, $myDomain, $end, $start]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE account_id = ? AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC");
            $stmt->execute([$accId, $end, $start]);
        }
        $events = $stmt->fetchAll();

        $formatted = [];
        foreach ($events as $ev) {
            $isShared = !isset($ev->is_shared) || $ev->is_shared == 1;
            $isCreator = ($acc && $ev->account_id == $acc->id);
            $creatorInfo = $ev->creator_name ? $ev->creator_name : ($ev->creator_email ? explode('@', $ev->creator_email)[0] : '');

            $formatted[] = [
                'id' => $ev->id,
                'title' => $ev->title,
                'start' => date('c', strtotime($ev->start_date)),
                'end' => date('c', strtotime($ev->end_date)),
                'allDay' => (bool)$ev->all_day,
                'backgroundColor' => $ev->color ?: '#4f46e5',
                'extendedProps' => [
                    'location' => $ev->location,
                    'description' => $ev->description,
                    'category' => $ev->category,
                    'is_shared' => $isShared,
                    'domain' => $ev->domain,
                    'is_creator' => $isCreator,
                    'creator_name' => $creatorInfo,
                    'creator_email' => $ev->creator_email,
                    'startDateRaw' => date('Y-m-d\TH:i', strtotime($ev->start_date)),
                    'endDateRaw' => date('Y-m-d\TH:i', strtotime($ev->end_date))
                ]
            ];
        }
        echo json_encode($formatted);
        exit;

    case 'save_calendar_event':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen önce giriş yapın.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        $color = $_POST['color'] ?? '#4f46e5';
        $category = $_POST['category'] ?? 'Toplantı';
        $location = trim($_POST['location'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $is_shared = isset($_POST['is_shared']) ? (int)$_POST['is_shared'] : 1; // Default 1 = shared with domain

        if (empty($title) || empty($start) || empty($end)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen başlık, başlangıç ve bitiş tarihlerini doldurun.']);
            exit;
        }

        $s_date = date('Y-m-d H:i:s', strtotime($start));
        $e_date = date('Y-m-d H:i:s', strtotime($end));

        $accId = (int)$acc->id;
        $creatorName = $acc->name ?: explode('@', $acc->email)[0];
        $creatorEmail = $acc->email;
        $domain = strtolower(explode('@', $acc->email)[1] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE calendar_events SET title=?, start_date=?, end_date=?, color=?, category=?, location=?, description=?, is_shared=? WHERE id=? AND (account_id=? OR domain=?)");
            $stmt->execute([$title, $s_date, $e_date, $color, $category, $location, $desc, $is_shared, $id, $accId, $domain]);
            $msg = 'Etkinlik güncellendi.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO calendar_events (account_id, domain, is_shared, creator_name, creator_email, title, start_date, end_date, color, category, location, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$accId, $domain, $is_shared, $creatorName, $creatorEmail, $title, $s_date, $e_date, $color, $category, $location, $desc]);
            $id = $pdo->lastInsertId();
            $msg = 'Etkinlik oluşturuldu.';
        }
        echo json_encode(['success' => true, 'message' => $msg, 'id' => $id]);
        exit;

    case 'delete_calendar_event':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $myDomain = strtolower(explode('@', $acc->email)[1] ?? '');
        $pdo->prepare("DELETE FROM calendar_events WHERE id = ? AND (account_id = ? OR domain = ?)")->execute([$id, $acc->id, $myDomain]);
        echo json_encode(['success' => true]);
        exit;

    // 8. SETTINGS & DIAGNOSTICS
    case 'test_connection':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $host = trim($_POST['imap_host'] ?? '');
        $port = (int)($_POST['imap_port'] ?? 993);
        $user = trim($_POST['imap_username'] ?? '');
        $pass = $_POST['imap_password'] ?? '';
        $smtp_host = trim($_POST['smtp_host'] ?? $host);
        $smtp_port = (int)($_POST['smtp_port'] ?? 465);

        // IMAP Test
        $imap_start = microtime(true);
        $imap_spec = "{" . "{$host}:{$port}/imap/ssl/novalidate-cert" . "}INBOX";
        $old = error_reporting(0);
        $imap_conn = @imap_open($imap_spec, $user, $pass, OP_HALFOPEN, 1);
        $imap_time = round((microtime(true) - $imap_start) * 1000);
        error_reporting($old);

        $imap_ok = (bool)$imap_conn;
        $folders = [];
        if ($imap_conn) {
            $boxes = @imap_getmailboxes($imap_conn, "{" . "{$host}:{$port}/imap/ssl/novalidate-cert" . "}", "*");
            if ($boxes) {
                foreach ($boxes as $b) {
                    $folders[] = str_replace("{" . "{$host}:{$port}/imap/ssl/novalidate-cert" . "}", '', $b->name);
                }
            }
            @imap_close($imap_conn);
        }

        // SMTP Test
        $smtp_start = microtime(true);
        $smtp_ok = false;
        $smtp_err = '';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtp_port;
            $mail->Timeout = 10;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            $smtp_ok = $mail->smtpConnect();
            if ($smtp_ok) $mail->smtpClose();
        } catch (Exception $e) {
            $smtp_err = $e->getMessage();
        }
        $smtp_time = round((microtime(true) - $smtp_start) * 1000);

        $all_ok = $imap_ok && $smtp_ok;
        echo json_encode([
            'success' => $all_ok,
            'message' => $all_ok ? 'Tüm bağlantılar (IMAP & SMTP) başarıyla doğrulandı!' : 'Bağlantı hatası oluştu.',
            'imap' => [
                'success' => $imap_ok,
                'latency' => $imap_time,
                'message' => $imap_ok ? 'Bağlandı' : (imap_last_error() ?: 'IMAP bağlantısı başarısız'),
                'folders' => $folders
            ],
            'smtp' => [
                'success' => $smtp_ok,
                'latency' => $smtp_time,
                'message' => $smtp_ok ? 'Bağlandı' : ($smtp_err ?: 'SMTP bağlantısı başarısız')
            ]
        ]);
        exit;

    // USERNAME & PASSWORD LOGIN (USES CONFIG.PHP DOMAIN & SERVER SETTINGS)
    case 'login':
        $user_input = trim($_POST['username'] ?? $_POST['email'] ?? '');
        $password = $_POST['password'] ?? $_POST['imap_password'] ?? '';

        if (empty($user_input) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen kullanıcı adınızı ve şifrenizi girin.']);
            exit;
        }

        check_rate_limit('login', 8, 300);

        // Otomatik domain ekleme & KESİNLİKLE BAŞKA DOMAİNE HİZMET ETMEME KİLİDİ
        $targetDomain = strtolower(trim(defined('MAIL_DOMAIN') ? MAIL_DOMAIN : ''));

        if (strpos($user_input, '@') === false) {
            $email = $user_input . '@' . $targetDomain;
            $username = $user_input;
        } else {
            $parts = explode('@', $user_input);
            $inputDomain = strtolower(trim($parts[1] ?? ''));
            $username = $parts[0];

            // GÜVENLİK KİLİDİ: Domain dışında başka hiçbir domaine hizmet verme!
            if (!empty($targetDomain) && strcasecmp($inputDomain, $targetDomain) !== 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Güvenlik Engeli: Bu webmail sistemi yalnızca @{$targetDomain} kurumsal alan adına hizmet vermektedir. '{$inputDomain}' alan adı ile giriş yapılamaz!"
                ]);
                exit;
            }
            $email = $user_input;
        }

        $imap_host = defined('MAIL_IMAP_HOST') ? MAIL_IMAP_HOST : ('mail.' . MAIL_DOMAIN);
        $imap_port = defined('MAIL_IMAP_PORT') ? (int)MAIL_IMAP_PORT : 993;
        $imap_enc = defined('MAIL_IMAP_ENCRYPTION') ? MAIL_IMAP_ENCRYPTION : 'ssl';
        $smtp_host = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : ('mail.' . MAIL_DOMAIN);
        $smtp_port = defined('MAIL_SMTP_PORT') ? (int)MAIL_SMTP_PORT : 587;
        $smtp_enc = defined('MAIL_SMTP_ENCRYPTION') ? MAIL_SMTP_ENCRYPTION : 'tls';

        // IMAP Bağlantı ve Kimlik Doğrulama Testi
        imap_timeout(IMAP_OPENTIMEOUT, 8);
        $flag = ($imap_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/tls/novalidate-cert";
        $imap_spec = "{" . "{$imap_host}:{$imap_port}{$flag}" . "}INBOX";
        $old = error_reporting(0);
        $test_conn = @imap_open($imap_spec, $email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        error_reporting($old);

        $err_last = imap_last_error() ?: '';
        $err_all = imap_errors() ?: [];
        $err_summary = implode(' ', $err_all) . ' ' . $err_last;

        // Kimlik (kullanıcı adı / şifre) hatası tespiti
        // Şifre yanlışsa ASLA port 143'e düşmemeliyiz! Aksi halde gereksiz 2. deneme Fail2Ban'ı tetikler ve yanıltıcı SSL hatası üretir.
        $is_auth_error = (
            stripos($err_summary, 'AUTHENTICATIONFAILED') !== false ||
            stripos($err_summary, 'Authentication failed') !== false ||
            stripos($err_summary, 'LOGIN failed') !== false ||
            stripos($err_summary, 'Invalid credentials') !== false ||
            stripos($err_summary, 'Too many login failures') !== false
        );

        if (!$test_conn && $is_auth_error) {
            if (stripos($err_summary, 'Too many login failures') !== false) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Giriş yapılamadı: Çok fazla hatalı deneme yapıldı. Sunucu güvenlik koruması nedeniyle lütfen birkaç dakika bekleyip tekrar deneyin.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Giriş yapılamadı: Kullanıcı adı veya şifre hatalı. Lütfen şifrenizi kontrol edip tekrar deneyin.'
                ]);
            }
            exit;
        }

        // Port 993 bağlantı hatası verdiyse (Kimlik hatası DEĞİL, port/erişim hatası ise) alternatif portları dene
        if (!$test_conn && $imap_port === 993) {
            $spec_143 = "{" . "{$imap_host}:143/imap/tls/novalidate-cert" . "}INBOX";
            $old = error_reporting(0);
            $test_conn = @imap_open($spec_143, $email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            error_reporting($old);
            if ($test_conn) {
                $imap_port = 143;
                $imap_enc = 'tls';
            } else {
                $spec_143_plain = "{" . "{$imap_host}:143/imap/notls" . "}INBOX";
                $old = error_reporting(0);
                $test_conn = @imap_open($spec_143_plain, $email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
                error_reporting($old);
                if ($test_conn) {
                    $imap_port = 143;
                    $imap_enc = 'none';
                }
            }
        }

        if (!$test_conn) {
            $err = imap_last_error() ?: '';
            $errs = imap_errors() ?: [];
            $all_e = implode(' ', $errs) . ' ' . $err;

            if (stripos($all_e, 'AUTHENTICATIONFAILED') !== false || stripos($all_e, 'Authentication failed') !== false) {
                $msg = 'Kullanıcı adı veya şifre hatalı. Lütfen şifrenizi kontrol edip tekrar deneyin.';
            } elseif (stripos($all_e, 'Timed out') !== false || stripos($all_e, "Can't connect") !== false) {
                $msg = "Mail sunucusuna ({$imap_host}) bağlanılamadı (Zaman aşımı). Çok sayıda hatalı deneme nedeniyle sunucu güvenlik duvarı (Fail2Ban) IP adresinizi geçici olarak engellemiş olabilir. Lütfen 5-10 dakika sonra tekrar deneyin.";
            } elseif (stripos($all_e, 'SSL negotiation failed') !== false) {
                $msg = "Mail sunucusu SSL/TLS el sıkışması başarısız oldu. Sunucu SSL sertifikasını veya port ayarlarını kontrol edin.";
            } else {
                $msg = $err ?: 'Kullanıcı adı veya şifre hatalı.';
            }

            echo json_encode(['success' => false, 'message' => 'Giriş yapılamadı: ' . $msg]);
            exit;
        }
        @imap_close($test_conn);

        // Mail hesabını otomatik veritabanına kaydet / güncelle
        $stmt = $pdo->prepare("SELECT id FROM mail_accounts WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        $name = $username;
        $pdo->exec("UPDATE mail_accounts SET is_default = 0");

        if ($existing) {
            $acc_id = $existing->id;
            $stmt = $pdo->prepare("UPDATE mail_accounts SET imap_host=?, imap_port=?, imap_encryption=?, imap_username=?, imap_password=?, smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, smtp_password=?, is_default=1 WHERE id=?");
            $stmt->execute([$imap_host, $imap_port, $imap_enc, $email, $password, $smtp_host, $smtp_port, $smtp_enc, $email, $password, $acc_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO mail_accounts (name, email, imap_host, imap_port, imap_encryption, imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$name, $email, $imap_host, $imap_port, $imap_enc, $email, $password, $smtp_host, $smtp_port, $smtp_enc, $email, $password]);
            $acc_id = $pdo->lastInsertId();
        }

        clear_rate_limit('login');
        @session_regenerate_id(true);
        $_SESSION['mail_logged_in'] = true;
        $_SESSION['active_account_id'] = $acc_id;
        $_SESSION['user_email'] = $email;

        // İlk girişte / her girişte kurumsal global rehberi otomatik senkronize et
        try {
            sync_domain_global_directory($pdo, (object)[
                'id' => $acc_id, 
                'email' => $email, 
                'imap_host' => $imap_host, 
                'imap_port' => $imap_port, 
                'imap_encryption' => $imap_enc, 
                'imap_username' => $email, 
                'imap_password' => $password
            ]);
        } catch (Exception $e) {}
        echo json_encode(['success' => true, 'message' => 'Giriş başarılı!', 'id' => $acc_id, 'email' => $email]);
        exit;

    case 'logout':
        unset($_SESSION['mail_logged_in'], $_SESSION['active_account_id'], $_SESSION['user_email']);
        @session_destroy();
        echo json_encode(['success' => true]);
        exit;

    case 'verify_screen_lock':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
            exit;
        }
        $password = trim($_POST['password'] ?? '');
        if ($password === '') {
            echo json_encode(['success' => false, 'message' => 'Lütfen şifrenizi girin.']);
            exit;
        }
        check_rate_limit('screen_lock_' . $acc->id, 8, 300);

        $isValid = false;
        // 1. Direct match with imap_password in mail_accounts
        if (!empty($acc->imap_password) && $password === $acc->imap_password) {
            $isValid = true;
        } else {
            // 2. Fallback IMAP live authentication test
            $imap_host = !empty($acc->imap_host) ? $acc->imap_host : (defined('MAIL_IMAP_HOST') ? MAIL_IMAP_HOST : '127.0.0.1');
            $imap_port = !empty($acc->imap_port) ? (int)$acc->imap_port : 993;
            $imap_enc  = !empty($acc->imap_encryption) ? $acc->imap_encryption : 'ssl';
            $flag = ($imap_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/tls/novalidate-cert";
            $imap_spec = "{" . "{$imap_host}:{$imap_port}{$flag}" . "}INBOX";

            imap_timeout(IMAP_OPENTIMEOUT, 4);
            $oldErr = error_reporting(0);
            $test_conn = @imap_open($imap_spec, $acc->email, $password, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            error_reporting($oldErr);

            if ($test_conn) {
                $isValid = true;
                @imap_close($test_conn);
                try {
                    $pdo->prepare("UPDATE mail_accounts SET imap_password = ? WHERE id = ?")->execute([$password, $acc->id]);
                } catch (Exception $e) {}
            }
        }

        if ($isValid) {
            clear_rate_limit('screen_lock_' . $acc->id);
            echo json_encode(['success' => true, 'message' => 'Kilit başarıyla açıldı.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hatalı şifre. Lütfen tekrar deneyin.']);
        }
        exit;


    case 'change_password':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen önce giriş yapın.']);
            exit;
        }

        $current_pass = trim($_POST['current_password'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        $confirm_pass = trim($_POST['confirm_password'] ?? '');

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen tüm alanları doldurun.']);
            exit;
        }

        if ($new_pass !== $confirm_pass) {
            echo json_encode(['success' => false, 'message' => 'Yeni şifreler birbiriyle eşleşmiyor.']);
            exit;
        }

        if (strlen($new_pass) < 8 || !preg_match('/[A-Z]/', $new_pass) || !preg_match('/[a-z]/', $new_pass) || !preg_match('/[0-9]/', $new_pass)) {
            echo json_encode(['success' => false, 'message' => 'Yeni şifre HestiaCP sunucu standardına uygun olmalıdır: En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.']);
            exit;
        }

        // 1. Verify current password via IMAP
        $imap_host = $acc->imap_host ?: (defined('MAIL_IMAP_HOST') ? MAIL_IMAP_HOST : 'mail.' . MAIL_DOMAIN);
        $imap_port = (int)($acc->imap_port ?: (defined('MAIL_IMAP_PORT') ? MAIL_IMAP_PORT : 993));
        $imap_enc = $acc->imap_encryption ?: (defined('MAIL_IMAP_ENCRYPTION') ? MAIL_IMAP_ENCRYPTION : 'ssl');
        $flag = ($imap_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/tls/novalidate-cert";
        $spec = "{" . "{$imap_host}:{$imap_port}{$flag}" . "}INBOX";

        imap_timeout(IMAP_OPENTIMEOUT, 6);
        $old = error_reporting(0);
        $test_conn = @imap_open($spec, $acc->imap_username, $current_pass, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        error_reporting($old);

        if (!$test_conn && $current_pass !== $acc->imap_password) {
            echo json_encode(['success' => false, 'message' => 'Mevcut şifreniz hatalı.']);
            exit;
        }
        if ($test_conn) {
            @imap_close($test_conn);
        }

        // 2. Call HestiaCP to change password on the mail server
        $conf = get_hestia_config($pdo);
        if (!$conf['is_configured']) {
            echo json_encode([
                'success' => false,
                'message' => 'HestiaCP sunucu entegrasyonu yapılandırılmamış. Şifrenin mail sunucusuyla senkronize değişebilmesi için Sistem Yöneticisinin (Admin) Sunucu Ayarları bölümünden HestiaCP API anahtarını tanımlaması gerekmektedir.'
            ]);
            exit;
        }

        $user_parts = explode('@', $acc->email);
        $account_name = $user_parts[0];
        $domain_name = $user_parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        $callRes = call_hestia_api($pdo, 'v-change-mail-account-password', [$conf['user'], $domain_name, $account_name, $new_pass], true);

        if (!$callRes['success']) {
            $failMsg = !empty($callRes['message']) ? $callRes['message'] : 'Mail sunucusu şifre güncelleme komutunu reddetti.';
            echo json_encode([
                'success' => false,
                'message' => 'Şifre mail sunucusunda güncellenemedi: ' . $failMsg . ' (Sunucu ve veritabanı senkronizasyonunun bozulmaması için şifre değiştirilmedi).'
            ]);
            exit;
        }

        // 3. Mail server successfully updated! Now update database in lockstep
        $stmt = $pdo->prepare("UPDATE mail_accounts SET imap_password = ?, smtp_password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_pass, $new_pass, $acc->id]);

        echo json_encode([
            'success' => true,
            'message' => 'Şifreniz hem mail sunucusunda hem de webmail sisteminde başarıyla güncellendi!'
        ]);
        exit;

    case 'save_signature':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
            exit;
        }
        $sig = trim($_POST['signature'] ?? '');
        $stmt = $pdo->prepare("UPDATE mail_accounts SET signature = ? WHERE id = ?");
        $stmt->execute([$sig, $acc->id]);
        echo json_encode(['success' => true, 'message' => 'E-posta imzanız başarıyla kaydedildi.', 'signature' => $sig]);
        exit;

    case 'get_signature':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'signature' => '']);
            exit;
        }
        echo json_encode(['success' => true, 'signature' => $acc->signature ?? '']);
        exit;

    // USER PROFILE & DISPLAY NAME UPDATE
    case 'update_profile':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapın.']);
            exit;
        }
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen geçerli bir görünen ad (Ad Soyad) girin.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE mail_accounts SET name = ? WHERE id = ?");
        $stmt->execute([$name, $acc->id]);

        $initial = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        echo json_encode([
            'success' => true,
            'message' => 'Görünen adınız ve profiliniz başarıyla güncellendi.',
            'name' => $name,
            'initial' => $initial
        ]);
        exit;

    case 'save_account':
        require_admin($pdo);
        $id = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['imap_password'] ?? $_POST['password'] ?? '';
        $name = trim($_POST['name'] ?? '') ?: explode('@', $email)[0];

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'E-posta adresi zorunludur.']);
            exit;
        }

        $domain = substr(strrchr($email, "@"), 1);
        $targetDomain = strtolower(trim(defined('MAIL_DOMAIN') ? MAIL_DOMAIN : ''));
        if (!empty($targetDomain) && strcasecmp($domain, $targetDomain) !== 0) {
            echo json_encode(['success' => false, 'message' => "Güvenlik Engeli: Bu sistem yalnızca @{$targetDomain} kurumsal domainine hizmet vermektedir."]);
            exit;
        }
        $imap_host = trim($_POST['imap_host'] ?? '') ?: ("mail." . $domain);
        $smtp_host = trim($_POST['smtp_host'] ?? '') ?: ("mail." . $domain);
        $imap_port = (int)($_POST['imap_port'] ?? 993) ?: 993;
        $smtp_port = (int)($_POST['smtp_port'] ?? 465) ?: 465;
        $imap_enc = trim($_POST['imap_encryption'] ?? 'ssl') ?: 'ssl';
        $smtp_enc = trim($_POST['smtp_encryption'] ?? 'ssl') ?: 'ssl';
        $is_def = !empty($_POST['is_default']) ? 1 : 0;

        if ($is_def) {
            $pdo->exec("UPDATE mail_accounts SET is_default = 0");
        }

        if ($id > 0) {
            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE mail_accounts SET name=?, email=?, imap_host=?, imap_port=?, imap_encryption=?, imap_username=?, imap_password=?, smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, smtp_password=?, is_default=? WHERE id=?");
                $stmt->execute([$name, $email, $imap_host, $imap_port, $imap_enc, $email, $password, $smtp_host, $smtp_port, $smtp_enc, $email, $password, $is_def, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE mail_accounts SET name=?, email=?, imap_host=?, imap_port=?, imap_encryption=?, imap_username=?, smtp_host=?, smtp_port=?, smtp_encryption=?, smtp_username=?, is_default=? WHERE id=?");
                $stmt->execute([$name, $email, $imap_host, $imap_port, $imap_enc, $email, $smtp_host, $smtp_port, $smtp_enc, $email, $is_def, $id]);
            }
            $msg = 'Hesap güncellendi.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO mail_accounts (name, email, imap_host, imap_port, imap_encryption, imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $imap_host, $imap_port, $imap_enc, $email, $password, $smtp_host, $smtp_port, $smtp_enc, $email, $password, $is_def]);
            $id = $pdo->lastInsertId();
            $msg = 'Hesap eklendi.';
        }

        $_SESSION['active_account_id'] = $id;
        echo json_encode(['success' => true, 'message' => $msg, 'id' => $id]);
        exit;

    case 'delete_account':
        require_admin($pdo);
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM mail_accounts WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;

    case 'set_default_account':
        require_admin($pdo);
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $pdo->exec("UPDATE mail_accounts SET is_default = 0");
        $pdo->prepare("UPDATE mail_accounts SET is_default = 1 WHERE id = ?")->execute([$id]);
        $_SESSION['active_account_id'] = $id;
        echo json_encode(['success' => true]);
        exit;

    case 'switch_account':
        require_admin($pdo);
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $_SESSION['active_account_id'] = $id;
        echo json_encode(['success' => true]);
        exit;

    case 'save_theme':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $theme = trim($_POST['theme'] ?? $_GET['theme'] ?? 'light');
        $allowed = [
            'dark', 'light', 'system',
            'light-sky', 'light-emerald', 'light-lavender', 'light-rose', 'light-amber', 'light-slate',
            'emerald', 'ocean', 'amethyst', 'carbon',
            'wallpaper-teddy', 'wallpaper-sea', 'wallpaper-nature', 'wallpaper-hearts', 'wallpaper-custom'
        ];
        if (!in_array($theme, $allowed)) {
            $theme = 'dark';
        }
        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('theme', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$theme, $theme]);
        echo json_encode(['success' => true, 'theme' => $theme]);
        exit;

    case 'save_language':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $lang = strtolower(trim($_POST['lang'] ?? $_GET['lang'] ?? 'tr'));
        if (!in_array($lang, ['tr', 'en', 'de'])) {
            $lang = 'tr';
        }
        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('language', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$lang, $lang]);
        echo json_encode(['success' => true, 'language' => $lang]);
        exit;

    case 'upload_custom_wallpaper':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'error' => 'Oturum açılmadı.']);
            exit;
        }
        if (empty($_FILES['wallpaper']['tmp_name'])) {
            echo json_encode(['success' => false, 'error' => 'Lütfen bir resim dosyası seçin.']);
            exit;
        }

        $file = $_FILES['wallpaper'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Yükleme hatası kodu: ' . $file['error']]);
            exit;
        }

        // Limit: 12MB
        if ($file['size'] > 12 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Dosya boyutu çok büyük (Maksimum 12MB).']);
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/pjpeg'=> 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];

        if (!isset($allowed_mimes[$mime])) {
            echo json_encode(['success' => false, 'error' => 'Desteklenmeyen dosya formatı. Lütfen JPG, PNG veya WEBP yükleyin.']);
            exit;
        }

        $ext = $allowed_mimes[$mime];
        $target_dir = __DIR__ . '/assets/images/themes';
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0777, true);
        }

        // Clean up old custom wallpapers
        foreach (glob($target_dir . '/custom_wallpaper_*.*') as $old) {
            @unlink($old);
        }

        $filename = 'custom_wallpaper_' . time() . '.' . $ext;
        $target_path = $target_dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            echo json_encode(['success' => false, 'error' => 'Görsel sunucuya kaydedilemedi. Klasör izinlerini kontrol edin.']);
            exit;
        }

        $web_url = 'assets/images/themes/' . $filename;

        // Save custom_wallpaper in mail_settings
        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('custom_wallpaper', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$web_url, $web_url]);

        // Auto activate wallpaper-custom theme
        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('theme', 'wallpaper-custom') ON DUPLICATE KEY UPDATE setting_value = 'wallpaper-custom'");
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'url' => $web_url,
            'theme' => 'wallpaper-custom'
        ]);
        exit;

    case 'remove_custom_wallpaper':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $target_dir = __DIR__ . '/assets/images/themes';
        foreach (glob($target_dir . '/custom_wallpaper_*.*') as $old) {
            @unlink($old);
        }
        $pdo->query("DELETE FROM mail_settings WHERE setting_key = 'custom_wallpaper'");
        $stmt = $pdo->prepare("UPDATE mail_settings SET setting_value = 'dark' WHERE setting_key = 'theme' AND setting_value = 'wallpaper-custom'");
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;

    case 'save_panel_opacity':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $opacity = (int)($_POST['opacity'] ?? 85);
        if ($opacity < 20) $opacity = 20;
        if ($opacity > 100) $opacity = 100;
        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('panel_opacity', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$opacity, $opacity]);
        echo json_encode(['success' => true, 'opacity' => $opacity]);
        exit;

    // TRASH RETENTION & AUTO-CLEAN (30 GÜN ÇÖP KUTUSU TEMİZLİĞİ)
    case 'get_trash_settings':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('auto_clean_trash', 'trash_retention_days', 'last_trash_clean_at')");
        $sets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $auto_clean = isset($sets['auto_clean_trash']) ? ($sets['auto_clean_trash'] === '1' || $sets['auto_clean_trash'] === 'true' || $sets['auto_clean_trash'] === 1) : true;
        $retention_days = isset($sets['trash_retention_days']) ? max(1, (int)$sets['trash_retention_days']) : 30;
        echo json_encode([
            'success' => true,
            'auto_clean' => $auto_clean,
            'retention_days' => $retention_days,
            'last_clean_at' => $sets['last_trash_clean_at'] ?? null
        ]);
        exit;

    case 'save_trash_settings':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $auto_clean = isset($_POST['auto_clean']) ? (($_POST['auto_clean'] === '1' || $_POST['auto_clean'] === 'true' || $_POST['auto_clean'] === 1 || $_POST['auto_clean'] === true) ? '1' : '0') : '1';
        $retention_days = max(1, min(365, (int)($_POST['retention_days'] ?? 30)));

        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('auto_clean_trash', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$auto_clean, $auto_clean]);

        $stmt2 = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('trash_retention_days', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt2->execute([(string)$retention_days, (string)$retention_days]);

        echo json_encode([
            'success' => true,
            'auto_clean' => ($auto_clean === '1'),
            'retention_days' => $retention_days,
            'message' => 'Çöp kutusu saklama ayarları başarıyla kaydedildi.'
        ]);
        exit;

    case 'clean_trash_now':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.']);
            exit;
        }
        $override_days = isset($_POST['days']) ? (int)$_POST['days'] : null;
        $folder = trim($_POST['folder'] ?? 'Trash');
        $res = auto_clean_trash_folder($pdo, $acc, true, null, $folder, $override_days);

        $deleted = $res['deleted'] ?? 0;
        $days = $res['retention_days'] ?? 30;
        $msg = ($override_days === 0)
            ? "Çöp kutusu tamamen boşaltıldı. Toplam {$deleted} adet e-posta kalıcı olarak silindi."
            : "{$days} günden eski {$deleted} adet e-posta çöp kutusundan kalıcı olarak temizlendi.";

        echo json_encode([
            'success' => ($res['success'] ?? false),
            'deleted' => $deleted,
            'message' => $msg,
            'detail' => $res
        ]);
        exit;

    // SPAM / JUNK RETENTION & AUTO-CLEAN (30 GÜN SPAM TEMİZLİĞİ)
    case 'get_spam_settings':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('auto_clean_spam', 'spam_retention_days', 'last_spam_clean_at')");
        $sets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $auto_clean = isset($sets['auto_clean_spam']) ? ($sets['auto_clean_spam'] === '1' || $sets['auto_clean_spam'] === 'true' || $sets['auto_clean_spam'] === 1) : true;
        $retention_days = isset($sets['spam_retention_days']) ? max(1, (int)$sets['spam_retention_days']) : 30;
        echo json_encode([
            'success' => true,
            'auto_clean' => $auto_clean,
            'retention_days' => $retention_days,
            'last_clean_at' => $sets['last_spam_clean_at'] ?? null
        ]);
        exit;

    case 'save_spam_settings':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $auto_clean = isset($_POST['auto_clean']) ? (($_POST['auto_clean'] === '1' || $_POST['auto_clean'] === 'true' || $_POST['auto_clean'] === 1 || $_POST['auto_clean'] === true) ? '1' : '0') : '1';
        $retention_days = max(1, min(365, (int)($_POST['retention_days'] ?? 30)));

        $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('auto_clean_spam', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$auto_clean, $auto_clean]);

        $stmt2 = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES ('spam_retention_days', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt2->execute([(string)$retention_days, (string)$retention_days]);

        echo json_encode([
            'success' => true,
            'auto_clean' => ($auto_clean === '1'),
            'retention_days' => $retention_days,
            'message' => 'Spam saklama ayarları başarıyla kaydedildi.'
        ]);
        exit;

    case 'clean_spam_now':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Aktif e-posta hesabı bulunamadı.']);
            exit;
        }
        $override_days = isset($_POST['days']) ? (int)$_POST['days'] : null;
        $folder = trim($_POST['folder'] ?? '');
        $res = auto_clean_spam_folder($pdo, $acc, true, null, $folder, $override_days);

        $deleted = $res['deleted'] ?? 0;
        $days = $res['retention_days'] ?? 30;
        $msg = ($override_days === 0)
            ? "İstenmeyen (Spam) klasörü tamamen boşaltıldı. Toplam {$deleted} adet e-posta kalıcı olarak silindi."
            : "{$days} günden eski {$deleted} adet spam e-posta kalıcı olarak temizlendi.";

        echo json_encode([
            'success' => ($res['success'] ?? false),
            'deleted' => $deleted,
            'message' => $msg,
            'detail' => $res
        ]);
        exit;


    // FOLDERS & MOVING
    case 'get_folders':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode([
                'success' => false,
                'system_folders' => [],
                'custom_folders' => [],
                'counts' => []
            ]);
            exit;
        }

        $host = $acc->imap_host;
        $port = (int)($acc->imap_port ?: 993);
        $enc = strtolower($acc->imap_encryption ?: 'ssl');
        $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
        $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

        $deletedFolders = get_deleted_folders_map($acc->id);

        // Sync folders from IMAP server if available
        $conn = connect_imap($acc, 'INBOX');
        $detected_spam = null;
        if ($conn) {
            $mailboxes = @imap_getmailboxes($conn, $server_prefix, "*");
            if ($mailboxes) {
                $system_names = ['INBOX', 'SENT', 'DRAFTS', 'TRASH', 'SPAM', 'JUNK'];
                foreach ($mailboxes as $mb) {
                    $rawName = str_replace($server_prefix, '', $mb->name);
                    $decodedName = decode_imap_folder_name($rawName);
                    $low = strtolower($rawName);
                    $lowDec = strtolower($decodedName);
                    $mbLowRaw = mb_strtolower($rawName, 'UTF-8');
                    $mbLowDec = mb_strtolower($decodedName, 'UTF-8');

                    if (strpos($low, 'junk') !== false || strpos($low, 'spam') !== false || strpos($lowDec, 'istenmeyen') !== false) {
                        if (!$detected_spam) {
                            $detected_spam = $rawName;
                        }
                    }

                    if (in_array(strtoupper($rawName), $system_names) || in_array(strtoupper($decodedName), $system_names)) {
                        continue;
                    }

                    // Skip folders explicitly deleted by the user (and attempt server cleanup)
                    if (isset($deletedFolders[$mbLowRaw]) || isset($deletedFolders[$mbLowDec])) {
                        @imap_unsubscribe($conn, $mb->name);
                        @imap_deletemailbox($conn, $mb->name);
                        continue;
                    }

                    $chk = $pdo->prepare("SELECT id FROM mail_folders WHERE (folder_name = ? OR folder_name = ? OR display_name = ?) AND account_id = ?");
                    $chk->execute([$rawName, $decodedName, $decodedName, $acc->id]);
                    if (!$chk->fetch()) {
                        $ins = $pdo->prepare("INSERT INTO mail_folders (account_id, folder_name, display_name, color, icon) VALUES (?, ?, ?, '#4f46e5', 'folder')");
                        $ins->execute([$acc->id, $rawName, $decodedName]);
                    }
                }
            }
        }

        if (!$detected_spam) {
            $detected_spam = 'Junk';
            ensure_imap_folder($acc, 'Junk');
        }

        $stmt = $pdo->prepare("SELECT * FROM mail_folders WHERE account_id = ? ORDER BY display_name ASC");
        $stmt->execute([$acc->id]);
        $raw_custom_folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $custom_folders = [];

        // Ensure display_name is cleanly decoded UTF-8 for all folders, heal '??', and filter out deleted folders
        foreach ($raw_custom_folders as $cf) {
            $curName = $cf['display_name'];
            $fName = $cf['folder_name'];
            if (strpos($curName, '??') !== false || empty($curName)) {
                $decName = decode_imap_folder_name($fName);
            } else {
                $decName = decode_imap_folder_name($curName);
            }
            $fLow = mb_strtolower((string)$fName, 'UTF-8');
            $dLow = mb_strtolower((string)$decName, 'UTF-8');
            if (isset($deletedFolders[$fLow]) || isset($deletedFolders[$dLow])) {
                try {
                    $pdo->prepare("DELETE FROM mail_folders WHERE id = ? AND account_id = ?")->execute([$cf['id'], $acc->id]);
                } catch (Throwable $e) {}
                continue;
            }
            if ($decName && $decName !== $cf['display_name']) {
                $cf['display_name'] = $decName;
                try {
                    $pdo->prepare("UPDATE mail_folders SET display_name = ? WHERE id = ?")->execute([$decName, $cf['id']]);
                } catch (Throwable $e) {}
            }
            $custom_folders[] = $cf;
        }

        $system_folders = [
            ['name' => 'INBOX', 'display_name' => 'Gelen Kutusu', 'icon' => 'inbox'],
            ['name' => 'Sent', 'display_name' => 'Gönderilenler', 'icon' => 'send'],
            ['name' => 'Drafts', 'display_name' => 'Taslaklar', 'icon' => 'draft'],
            ['name' => $detected_spam, 'display_name' => 'İstenmeyen (Spam)', 'icon' => 'alert-octagon', 'color' => '#f59e0b', 'type' => 'junk'],
            ['name' => 'Trash', 'display_name' => 'Çöp Kutusu', 'icon' => 'trash']
        ];

        // Apply incoming mail filters automatically before gathering counts
        apply_incoming_mail_filters($pdo, $acc, null, 60);

        // Gather counts for all folders
        $counts = [];
        if ($conn) {
            $all_to_count = ['INBOX', 'Sent', 'Drafts', 'Trash', $detected_spam, 'Junk', 'Spam'];
            foreach ($custom_folders as $cf) {
                $fName = is_array($cf) ? ($cf['folder_name'] ?? '') : ($cf->folder_name ?? '');
                if ($fName && !in_array($fName, $all_to_count)) {
                    $all_to_count[] = $fName;
                }
            }
            foreach ($all_to_count as $fld) {
                $encoded = safe_utf7_encode($fld);
                $st = @imap_status($conn, $server_prefix . $encoded, SA_MESSAGES | SA_UNSEEN);
                if ($st) {
                    $counts[$fld] = [
                        'total' => (int)$st->messages,
                        'unseen' => (int)$st->unseen,
                        'formatted' => number_format((int)$st->messages, 0, ',', '.')
                    ];
                } else {
                    $counts[$fld] = ['total' => 0, 'unseen' => 0, 'formatted' => '0'];
                }
            }

            if (isset($counts[$detected_spam])) {
                $counts['Junk'] = $counts[$detected_spam];
                $counts['Spam'] = $counts[$detected_spam];
            }

            @imap_close($conn);
        }

        echo json_encode([
            'success' => true,
            'spam_folder' => $detected_spam,
            'system_folders' => $system_folders,
            'custom_folders' => $custom_folders,
            'counts' => $counts
        ]);
        exit;

    case 'get_folder_counts':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'counts' => []]);
            exit;
        }

        $host = $acc->imap_host;
        $port = (int)($acc->imap_port ?: 993);
        $enc = strtolower($acc->imap_encryption ?: 'ssl');
        $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
        $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

        $conn = @imap_open($server_prefix . "INBOX", $acc->imap_username, $acc->imap_password, OP_HALFOPEN, 1);
        if (!$conn) {
            echo json_encode(['success' => false, 'counts' => []]);
            exit;
        }

        // Apply incoming mail filters automatically in background before counting!
        apply_incoming_mail_filters($pdo, $acc, null, 60);

        // Auto clean trash folder (throttled to run once every 15 minutes)
        auto_clean_trash_folder($pdo, $acc, false);

        // Auto clean spam folder (throttled to run once every 15 minutes)
        auto_clean_spam_folder($pdo, $acc, false);

        $target_folders = ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam', 'Junk', 'INBOX.Spam', 'INBOX.Junk'];
        try {
            $cfStmt = $pdo->prepare("SELECT folder_name FROM mail_folders WHERE account_id = ?");
            $cfStmt->execute([$acc->id]);
            while ($r = $cfStmt->fetch()) {
                if (!in_array($r->folder_name, $target_folders)) {
                    $target_folders[] = $r->folder_name;
                }
            }
        } catch (Exception $e) {}

        $counts = [];
        foreach ($target_folders as $fld) {
            $encoded = safe_utf7_encode($fld);
            $st = @imap_status($conn, $server_prefix . $encoded, SA_MESSAGES | SA_UNSEEN);
            if ($st) {
                $counts[$fld] = [
                    'total' => (int)$st->messages,
                    'unseen' => (int)$st->unseen,
                    'formatted' => number_format((int)$st->messages, 0, ',', '.')
                ];
            } else {
                $counts[$fld] = ['total' => 0, 'unseen' => 0, 'formatted' => '0'];
            }
        }

        // Pick whichever spam mailbox actually has counts on this server
        $sp = null;
        if (!empty($counts['Spam']) && $counts['Spam']['total'] > 0) {
            $sp = $counts['Spam'];
        } elseif (!empty($counts['Junk']) && $counts['Junk']['total'] > 0) {
            $sp = $counts['Junk'];
        } elseif (!empty($counts['INBOX.Spam']) && $counts['INBOX.Spam']['total'] > 0) {
            $sp = $counts['INBOX.Spam'];
        } elseif (!empty($counts['INBOX.Junk']) && $counts['INBOX.Junk']['total'] > 0) {
            $sp = $counts['INBOX.Junk'];
        } else {
            $sp = $counts['Spam'] ?? $counts['Junk'] ?? null;
        }
        if ($sp) {
            $counts['Junk'] = $sp;
            $counts['Spam'] = $sp;
        }

        @imap_close($conn);

        echo json_encode(['success' => true, 'counts' => $counts]);
        exit;

    case 'create_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $name = trim($_POST['name'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '') ?: $name;
        $color = trim($_POST['color'] ?? '#4f46e5');
        $icon = trim($_POST['icon'] ?? 'folder');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen bir klasör adı giriniz.']);
            exit;
        }

        // Clean folder name for IMAP (avoid delimiter errors)
        $clean_folder = trim(str_replace(['.', '\\'], '', $name));
        if (empty($clean_folder)) {
            echo json_encode(['success' => false, 'message' => 'Geçerli bir klasör adı giriniz.']);
            exit;
        }

        // 1. Create and subscribe directly on IMAP server
        $imap_ok = true;
        $imap_err = '';
        $conn = connect_imap($acc, 'INBOX');
        if ($conn) {
            $host = $acc->imap_host;
            $port = (int)($acc->imap_port ?: 993);
            $enc = strtolower($acc->imap_encryption ?: 'ssl');
            $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
            $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

            $encoded_folder = function_exists('mb_convert_encoding') ? @mb_convert_encoding($clean_folder, 'UTF7-IMAP', 'UTF-8') : $clean_folder;
            $fullMb = $server_prefix . $encoded_folder;

            $res = @imap_createmailbox($conn, $fullMb);
            if ($res) {
                @imap_subscribe($conn, $fullMb);
            } else {
                $err = imap_last_error();
                if ($err && (stripos($err, 'already exists') !== false || stripos($err, 'mevcut') !== false)) {
                    // Already exists on server, that's fine
                } else {
                    $imap_ok = false;
                    $imap_err = $err ?: 'IMAP sunucusunda klasör oluşturulamadı.';
                }
            }
            @imap_close($conn);
        }

        if (!$imap_ok) {
            echo json_encode(['success' => false, 'message' => 'IMAP Hatası: ' . $imap_err]);
            exit;
        }

        // 2. Persist in database
        $encoded_clean = encode_imap_folder_name($clean_folder);
        $deletedFolders = get_deleted_folders_map($acc->id);
        unset(
            $deletedFolders[mb_strtolower($clean_folder, 'UTF-8')],
            $deletedFolders[mb_strtolower($encoded_clean, 'UTF-8')],
            $deletedFolders[mb_strtolower($display_name, 'UTF-8')],
            $deletedFolders['inbox.' . mb_strtolower($clean_folder, 'UTF-8')],
            $deletedFolders['inbox.' . mb_strtolower($encoded_clean, 'UTF-8')]
        );
        save_deleted_folders_map($acc->id, $deletedFolders);

        $stmt = $pdo->prepare("SELECT id FROM mail_folders WHERE (folder_name = ? OR folder_name = ?) AND account_id = ?");
        $stmt->execute([$clean_folder, $encoded_clean, $acc->id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE mail_folders SET display_name = ?, color = ?, icon = ? WHERE id = ? AND account_id = ?");
            $stmt->execute([$display_name, $color, $icon, $existing->id, $acc->id]);
            $folder_id = $existing->id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO mail_folders (account_id, folder_name, display_name, color, icon) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$acc->id, $encoded_clean, $display_name, $color, $icon]);
            $folder_id = $pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => '"' . htmlspecialchars($display_name) . '" IMAP klasörü başarıyla oluşturuldu.',
            'folder' => ['id' => $folder_id, 'folder_name' => $encoded_clean, 'display_name' => $display_name, 'color' => $color]
        ]);
        exit;

    case 'rename_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Lütfen giriş yapınız.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $old_name = trim($_POST['old_folder_name'] ?? '');
        $new_name = trim($_POST['new_name'] ?? '');
        $color = trim($_POST['color'] ?? '');

        if ($id > 0 && empty($old_name)) {
            $stmt = $pdo->prepare("SELECT folder_name FROM mail_folders WHERE id = ? AND account_id = ?");
            $stmt->execute([$id, $acc->id]);
            $old_name = $stmt->fetchColumn() ?: '';
        }

        if (empty($old_name)) {
            echo json_encode(['success' => false, 'message' => 'Yeniden adlandırılacak klasör belirlenemedi.']);
            exit;
        }

        $clean_new_name = trim(str_replace(['.', '\\'], '', $new_name));
        if (empty($clean_new_name)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen geçerli bir klasör adı giriniz.']);
            exit;
        }

        $system_names = ['INBOX', 'SENT', 'DRAFTS', 'TRASH', 'SPAM', 'JUNK'];
        if (in_array(strtoupper($clean_new_name), $system_names)) {
            echo json_encode(['success' => false, 'message' => 'Sistem klasör adları kullanılamaz.']);
            exit;
        }

        // 1. Rename on IMAP server
        $conn = connect_imap($acc, 'INBOX');
        $imap_ok = true;
        $imap_err = '';
        $encoded_old = encode_imap_folder_name($old_name);
        $encoded_new = encode_imap_folder_name($clean_new_name);

        if ($conn) {
            $host = $acc->imap_host;
            $port = (int)($acc->imap_port ?: 993);
            $enc = strtolower($acc->imap_encryption ?: 'ssl');
            $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
            $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

            $old_mb = $server_prefix . $encoded_old;
            $new_mb = $server_prefix . $encoded_new;

            // Attempt rename on server
            $renamed = @imap_renamemailbox($conn, $old_mb, $new_mb);
            if (!$renamed && $old_name !== $encoded_old) {
                // Try with raw old_name if it wasn't encoded on the server
                $old_mb_raw = $server_prefix . $old_name;
                $renamed = @imap_renamemailbox($conn, $old_mb_raw, $new_mb);
                if ($renamed) {
                    $old_mb = $old_mb_raw;
                }
            }

            if ($renamed) {
                @imap_unsubscribe($conn, $old_mb);
                @imap_subscribe($conn, $new_mb);
            } else {
                $err = imap_last_error();
                $imap_ok = false;
                $imap_err = $err ?: 'Sunucuda klasör adı güncellenemedi.';
            }
            @imap_close($conn);
        }

        if (!$imap_ok) {
            echo json_encode(['success' => false, 'message' => 'IMAP Sunucu Hatası: ' . $imap_err]);
            exit;
        }

        // 2. Update in database (mail_folders)
        $new_folder_key = $encoded_new;
        $new_display_name = $clean_new_name;

        $deletedFolders = get_deleted_folders_map($acc->id);
        $decoded_old = decode_imap_folder_name($old_name);
        $deletedFolders[mb_strtolower($old_name, 'UTF-8')] = time();
        $deletedFolders[mb_strtolower($encoded_old, 'UTF-8')] = time();
        $deletedFolders[mb_strtolower($decoded_old, 'UTF-8')] = time();
        unset(
            $deletedFolders[mb_strtolower($new_folder_key, 'UTF-8')],
            $deletedFolders[mb_strtolower($new_display_name, 'UTF-8')]
        );
        save_deleted_folders_map($acc->id, $deletedFolders);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE mail_folders SET folder_name = ?, display_name = ?, color = COALESCE(NULLIF(?, ''), color), updated_at = NOW() WHERE id = ? AND account_id = ?");
            $stmt->execute([$new_folder_key, $new_display_name, $color, $id, $acc->id]);
        } else {
            $stmt = $pdo->prepare("UPDATE mail_folders SET folder_name = ?, display_name = ?, color = COALESCE(NULLIF(?, ''), color), updated_at = NOW() WHERE (folder_name = ? OR folder_name = ?) AND account_id = ?");
            $stmt->execute([$new_folder_key, $new_display_name, $color, $old_name, $encoded_old, $acc->id]);
        }

        // 3. Update mail_filters rules if they moved emails to this folder
        try {
            $pdo->prepare("UPDATE mail_filters SET action_target = ? WHERE (action_target = ? OR action_target = ?) AND account_id = ?")
                ->execute([$new_folder_key, $old_name, $encoded_old, $acc->id]);
        } catch (Throwable $e) {}

        echo json_encode([
            'success' => true,
            'message' => 'Klasör adı "' . htmlspecialchars($new_display_name) . '" olarak güncellendi.',
            'old_folder_name' => $old_name,
            'new_folder_name' => $new_folder_key,
            'display_name' => $new_display_name,
            'color' => $color
        ]);
        exit;

    case 'delete_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $folder_name = trim($_POST['folder_name'] ?? '');
        $disp_name = '';

        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT folder_name, display_name FROM mail_folders WHERE id = ? AND account_id = ?");
            $stmt->execute([$id, $acc->id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $folder_name = $row['folder_name'] ?: $folder_name;
                $disp_name = $row['display_name'] ?: '';
            }
        }

        $decoded_folder = decode_imap_folder_name($folder_name);
        $encoded_folder = encode_imap_folder_name($decoded_folder);
        if (empty($disp_name)) {
            $disp_name = $decoded_folder ?: $folder_name;
        }

        // 1. Delete ALL matching rows in mail_folders (by id, raw folder_name, decoded, encoded, or display_name)
        if ($id > 0) {
            $pdo->prepare("DELETE FROM mail_folders WHERE id = ? AND account_id = ?")->execute([$id, $acc->id]);
        }
        if (!empty($folder_name)) {
            $pdo->prepare("DELETE FROM mail_folders WHERE account_id = ? AND (folder_name IN (?, ?, ?, ?) OR display_name IN (?, ?))")
                ->execute([
                    $acc->id,
                    $folder_name,
                    $decoded_folder,
                    $encoded_folder,
                    'INBOX.' . $encoded_folder,
                    $disp_name,
                    $decoded_folder
                ]);
        }

        // 2. Record in deleted folders cache so get_folders never resurrects it if IMAP server lags or keeps ghost mailbox
        $deletedFolders = get_deleted_folders_map($acc->id);
        foreach ([$folder_name, $decoded_folder, $encoded_folder, $disp_name, 'INBOX.' . $encoded_folder, 'INBOX.' . $folder_name] as $candKey) {
            $candKey = trim((string)$candKey);
            if ($candKey !== '') {
                $deletedFolders[mb_strtolower($candKey, 'UTF-8')] = time();
            }
        }
        save_deleted_folders_map($acc->id, $deletedFolders);

        // 3. Remove mailbox on IMAP server across all candidate names (and empty it first if server refuses non-empty delete)
        if (!empty($folder_name) && $acc) {
            $conn = connect_imap($acc, 'INBOX');
            if ($conn) {
                $host = $acc->imap_host;
                $port = (int)($acc->imap_port ?: 993);
                $enc = strtolower($acc->imap_encryption ?: 'ssl');
                $flags = ($enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
                $server_prefix = "{" . "{$host}:{$port}{$flags}" . "}";

                $stripped_folder = (stripos($encoded_folder, 'INBOX.') === 0) ? substr($encoded_folder, 6) : $encoded_folder;
                $candidates = array_values(array_unique(array_filter([
                    $encoded_folder,
                    $folder_name,
                    $decoded_folder,
                    'INBOX.' . $stripped_folder,
                    $stripped_folder
                ])));

                foreach ($candidates as $cand) {
                    $fullMb = $server_prefix . $cand;
                    @imap_unsubscribe($conn, $fullMb);
                    if (!@imap_deletemailbox($conn, $fullMb)) {
                        // Some IMAP servers refuse deleting a mailbox that contains messages; expunge and retry
                        $subConn = @imap_open($fullMb, $acc->imap_username, $acc->imap_password, 0, 1);
                        if ($subConn) {
                            $mc = @imap_num_msg($subConn);
                            if ($mc > 0) {
                                @imap_delete($subConn, '1:*');
                                @imap_expunge($subConn);
                            }
                            @imap_close($subConn);
                            @imap_deletemailbox($conn, $fullMb);
                        }
                    }
                }
                @imap_close($conn);
            }
        }

        echo json_encode([
            'success' => true,
            'deleted_id' => $id,
            'deleted_folder' => $folder_name,
            'message' => '"' . htmlspecialchars($disp_name) . '" klasörü başarıyla silindi.'
        ]);
        exit;

    case 'move_email':
        $acc = get_active_account($pdo);
        $uid = (int)($_POST['uid'] ?? 0);
        $from_folder = $_POST['from_folder'] ?? 'INBOX';
        $to_folder = trim($_POST['to_folder'] ?? '');

        if (!$acc || !$uid || empty($to_folder)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz parametreler.']);
            exit;
        }

        $conn = connect_imap($acc, $from_folder);
        if (!$conn) {
            echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı.']);
            exit;
        }

        ensure_imap_folder($acc, $to_folder);

        // Safe mUTF-7 encoding for Dovecot/IMAP compliance (handles Turkish chars: İş, Çöp, etc.)
        $encoded_target = safe_utf7_encode($to_folder);
        $candidates = [$encoded_target];

        if (stripos($encoded_target, 'INBOX.') !== 0) {
            $candidates[] = 'INBOX.' . $encoded_target;
        } else {
            $candidates[] = substr($encoded_target, 6);
        }

        if ($encoded_target !== $to_folder) {
            // Also try with decoded or raw if pure ascii
            $candidates[] = $to_folder;
        }

        $candidates = array_unique($candidates);

        $moved = false;
        foreach ($candidates as $cand) {
            $moved = @imap_mail_move($conn, (string)$uid, $cand, CP_UID);
            if ($moved) {
                break;
            }
        }

        if ($moved) {
            @imap_expunge($conn);
            @imap_close($conn);
            echo json_encode(['success' => true, 'message' => 'E-posta "' . htmlspecialchars($to_folder) . '" klasörüne taşındı.']);
        } else {
            $err = imap_last_error() ?: 'Taşıma işlemi gerçekleştirilemedi.';
            @imap_close($conn);
            echo json_encode(['success' => false, 'message' => 'Taşıma hatası: ' . $err]);
        }
        exit;

    // FILTERS & RULES
    case 'get_filters':
        ensure_filters_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            $stmt = $pdo->prepare("SELECT * FROM mail_filters WHERE account_id = ? OR account_id = 0 ORDER BY id DESC");
            $stmt->execute([$acc ? $acc->id : 0]);
            $filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'filters' => $filters]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Filtreler listelenemedi: ' . $e->getMessage(), 'filters' => []]);
        }
        exit;

    case 'save_filter':
        ensure_filters_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            $id = (int)($_POST['id'] ?? 0);
            $filter_name = trim($_POST['filter_name'] ?? '');
            $match_field = trim($_POST['match_field'] ?? 'from');
            $match_type = trim($_POST['match_type'] ?? 'contains');
            $match_value = trim($_POST['match_value'] ?? '');
            $action_type = trim($_POST['action_type'] ?? 'move');
            $action_target = trim($_POST['action_target'] ?? '');
            $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

            if (empty($filter_name) || empty($match_value)) {
                echo json_encode(['success' => false, 'message' => 'Lütfen kural adı ve eşleşecek değeri giriniz.']);
                exit;
            }

            // Non-blocking IMAP folder check: don't freeze/timeout if IMAP is slow
            if ($action_type === 'move' && !empty($action_target) && $acc) {
                try {
                    ensure_imap_folder($acc, $action_target);
                } catch (Throwable $ignore) {}
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE mail_filters SET filter_name=?, match_field=?, match_type=?, match_value=?, action_type=?, action_target=?, is_active=? WHERE id=?");
                $stmt->execute([$filter_name, $match_field, $match_type, $match_value, $action_type, $action_target, $is_active, $id]);
                $msg = 'Filtre kuralı güncellendi.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO mail_filters (account_id, filter_name, match_field, match_type, match_value, action_type, action_target, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$acc ? $acc->id : 0, $filter_name, $match_field, $match_type, $match_value, $action_type, $action_target, $is_active]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Filtre kuralı kaydedildi.';
            }

            echo json_encode(['success' => true, 'message' => $msg, 'id' => $id]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Filtre kaydedilemedi: ' . $e->getMessage()]);
        }
        exit;

    case 'delete_filter':
        ensure_filters_tables($pdo);
        try {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            $pdo->prepare("DELETE FROM mail_filters WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Filtre silindi.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Filtre silinemedi: ' . $e->getMessage()]);
        }
        exit;

    case 'toggle_filter':
        ensure_filters_tables($pdo);
        try {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            $pdo->prepare("UPDATE mail_filters SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Filtre durumu güncellenemedi: ' . $e->getMessage()]);
        }
        exit;

    case 'run_filters':
        ensure_filters_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Aktif mail hesabı bulunamadı.']);
                exit;
            }

            $folder = $_POST['folder'] ?? $_GET['folder'] ?? 'INBOX';
            $conn = connect_imap($acc, $folder);
            if (!$conn) {
                echo json_encode(['success' => false, 'message' => 'IMAP bağlantısı kurulamadı: ' . imap_last_error()]);
                exit;
            }

            $filter_id = (int)($_POST['filter_id'] ?? $_GET['filter_id'] ?? 0);
            if ($filter_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM mail_filters WHERE id = ? AND (account_id = ? OR account_id = 0)");
                $stmt->execute([$filter_id, $acc->id]);
                $filters = $stmt->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT * FROM mail_filters WHERE (account_id = ? OR account_id = 0) AND is_active = 1 ORDER BY id ASC");
                $stmt->execute([$acc->id]);
                $filters = $stmt->fetchAll();
            }

            if (empty($filters)) {
                @imap_close($conn);
                echo json_encode(['success' => true, 'message' => 'Aktif filtre kuralı bulunmuyor.', 'matched_count' => 0]);
                exit;
            }

            $matched_count = 0;
            $needs_expunge = false;
            foreach ($filters as $filter) {
                $search_val = trim($filter->match_value ?? '');
                if (empty($search_val)) continue;

                $safe_val = str_replace(['"', "\\"], '', $search_val);
                if ($filter->match_field === 'from') {
                    $criteria = 'FROM "' . $safe_val . '"';
                } elseif ($filter->match_field === 'subject') {
                    $criteria = 'SUBJECT "' . $safe_val . '"';
                } elseif ($filter->match_field === 'to') {
                    $criteria = 'TO "' . $safe_val . '"';
                } elseif ($filter->match_field === 'body') {
                    $criteria = 'BODY "' . $safe_val . '"';
                } else {
                    $criteria = 'TEXT "' . $safe_val . '"';
                }

                $msg_nums = @imap_search($conn, $criteria);
                if (!empty($msg_nums)) {
                    $target_mb = '';
                    if ($filter->action_type === 'move' && !empty($filter->action_target)) {
                        ensure_imap_folder($acc, $filter->action_target);
                        $target_mb = safe_utf7_encode($filter->action_target);
                    }

                    $slice = array_slice($msg_nums, -150);
                    $uids = [];
                    foreach ($slice as $mno) {
                        $u = @imap_uid($conn, $mno);
                        if ($u) $uids[] = $u;
                    }

                    if (!empty($uids)) {
                        $uid_str = implode(',', $uids);
                        if ($filter->action_type === 'move' && !empty($target_mb)) {
                            $mvd = @imap_mail_move($conn, $uid_str, $target_mb, CP_UID);
                            if (!$mvd) {
                                $mvd = @imap_mail_move($conn, $uid_str, "INBOX." . $target_mb, CP_UID);
                            }
                            if ($mvd) {
                                $needs_expunge = true;
                                $matched_count += count($uids);
                            }
                        } elseif ($filter->action_type === 'star') {
                            @imap_setflag_full($conn, $uid_str, "\\Flagged", ST_UID);
                            $matched_count += count($uids);
                        } elseif ($filter->action_type === 'read') {
                            @imap_setflag_full($conn, $uid_str, "\\Seen", ST_UID);
                            $matched_count += count($uids);
                        } elseif ($filter->action_type === 'delete') {
                            ensure_imap_folder($acc, 'Trash');
                            $mvd = @imap_mail_move($conn, $uid_str, 'Trash', CP_UID);
                            if ($mvd) {
                                $needs_expunge = true;
                                $matched_count += count($uids);
                            }
                        }
                    }
                }
            }

            if ($needs_expunge) {
                @imap_expunge($conn);
            }
            @imap_close($conn);

            echo json_encode([
                'success' => true,
                'message' => ($matched_count > 0)
                    ? "Filtreler başarıyla çalıştırıldı! {$matched_count} adet e-posta kurala göre işlendi."
                    : "Filtreler çalıştırıldı. Eşleşen yeni e-posta bulunamadı.",
                'matched_count' => $matched_count
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Filtre çalıştırma hatası: ' . $e->getMessage(), 'matched_count' => 0]);
        }
        exit;

    // --- USER TASKS (YAPILACAKLAR - KULLANICIYA ÖZEL) ---
    case 'get_tasks':
        ensure_tasks_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.', 'tasks' => []]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM user_tasks WHERE account_id = ? ORDER BY is_completed ASC, CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC, id DESC");
            $stmt->execute([$acc->id]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'tasks' => $tasks]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Görevler yüklenemedi: ' . $e->getMessage(), 'tasks' => []]);
        }
        exit;

    case 'save_task':
        ensure_tasks_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı. Lütfen sayfayı yenileyip tekrar deneyin.']);
                exit;
            }
            $task_id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $due_date = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
            if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
                $due_date = null;
            }
            $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high']) ? $_POST['priority'] : 'medium';

            if (empty($title)) {
                echo json_encode(['success' => false, 'message' => 'Görev başlığı boş bırakılamaz.']);
                exit;
            }

            if ($task_id > 0) {
                $stmt = $pdo->prepare("UPDATE user_tasks SET title = ?, description = ?, due_date = ?, priority = ? WHERE id = ? AND account_id = ?");
                $stmt->execute([$title, $description, $due_date, $priority, $task_id, $acc->id]);
                $msg = 'Görev güncellendi.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO user_tasks (account_id, title, description, due_date, priority) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$acc->id, $title, $description, $due_date, $priority]);
                $task_id = (int)$pdo->lastInsertId();
                $msg = 'Yeni görev eklendi.';
            }

            echo json_encode(['success' => true, 'message' => $msg, 'task_id' => $task_id]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Görev kaydedilemedi: ' . $e->getMessage()]);
        }
        exit;

    case 'toggle_task':
        ensure_tasks_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $task_id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_completed FROM user_tasks WHERE id = ? AND account_id = ?");
            $stmt->execute([$task_id, $acc->id]);
            $task = $stmt->fetch();
            if (!$task) {
                echo json_encode(['success' => false, 'message' => 'Görev bulunamadı.']);
                exit;
            }

            $new_status = $task->is_completed ? 0 : 1;
            $completed_at = $new_status ? date('Y-m-d H:i:s') : null;
            $up = $pdo->prepare("UPDATE user_tasks SET is_completed = ?, completed_at = ? WHERE id = ? AND account_id = ?");
            $up->execute([$new_status, $completed_at, $task_id, $acc->id]);

            echo json_encode(['success' => true, 'is_completed' => $new_status, 'message' => $new_status ? 'Görev tamamlandı!' : 'Görev aktif edildi.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'İşlem başarısız: ' . $e->getMessage()]);
        }
        exit;

    case 'delete_task':
        ensure_tasks_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $task_id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM user_tasks WHERE id = ? AND account_id = ?");
            $stmt->execute([$task_id, $acc->id]);
            echo json_encode(['success' => true, 'message' => 'Görev silindi.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Görev silinemedi: ' . $e->getMessage()]);
        }
        exit;

    // --- USER NOTES (NOTLAR - KULLANICIYA ÖZEL) ---
    case 'get_notes':
        ensure_notes_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM user_notes WHERE account_id = ? ORDER BY is_pinned DESC, updated_at DESC, id DESC");
            $stmt->execute([$acc->id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'notes' => $notes]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Notlar listelenemedi: ' . $e->getMessage(), 'notes' => []]);
        }
        exit;

    case 'save_note':
        ensure_notes_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $note_id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $color = trim($_POST['color'] ?? '#6366f1');
            $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;

            if (empty($title)) {
                $title = 'Başlıksız Not';
            }

            if ($note_id > 0) {
                $stmt = $pdo->prepare("UPDATE user_notes SET title = ?, content = ?, color = ?, is_pinned = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
                $stmt->execute([$title, $content, $color, $is_pinned, $note_id, $acc->id]);
                $msg = 'Not güncellendi.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO user_notes (account_id, title, content, color, is_pinned) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$acc->id, $title, $content, $color, $is_pinned]);
                $note_id = (int)$pdo->lastInsertId();
                $msg = 'Yeni not kaydedildi.';
            }

            echo json_encode(['success' => true, 'message' => $msg, 'note_id' => $note_id]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Not kaydedilemedi: ' . $e->getMessage()]);
        }
        exit;

    case 'toggle_pin_note':
        ensure_notes_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $note_id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_pinned FROM user_notes WHERE id = ? AND account_id = ?");
            $stmt->execute([$note_id, $acc->id]);
            $note = $stmt->fetch();
            if (!$note) {
                echo json_encode(['success' => false, 'message' => 'Not bulunamadı.']);
                exit;
            }

            $new_pin = $note->is_pinned ? 0 : 1;
            $up = $pdo->prepare("UPDATE user_notes SET is_pinned = ? WHERE id = ? AND account_id = ?");
            $up->execute([$new_pin, $note_id, $acc->id]);

            echo json_encode(['success' => true, 'is_pinned' => $new_pin, 'message' => $new_pin ? 'Not başa sabitlendi.' : 'Not sabitlemesi kaldırıldı.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'İşlem başarısız: ' . $e->getMessage()]);
        }
        exit;

    case 'delete_note':
        ensure_notes_tables($pdo);
        try {
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
                exit;
            }
            $note_id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM user_notes WHERE id = ? AND account_id = ?");
            $stmt->execute([$note_id, $acc->id]);
            echo json_encode(['success' => true, 'message' => 'Not silindi.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Not silinemedi: ' . $e->getMessage()]);
        }
        exit;
        exit;

    // --- CLOUD STORAGE (DRIVE - BULUT ALANI) ---
    case 'drive_list':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }

        ensure_drive_tables($pdo);

        try {
            $folder_id = (int)($_GET['folder_id'] ?? 0);
            $search = trim($_GET['search'] ?? '');

            if (!empty($search)) {
                $like = '%' . $search . '%';
                $stmtFld = $pdo->prepare("SELECT * FROM drive_folders WHERE account_id = ? AND name LIKE ? ORDER BY name ASC");
                $stmtFld->execute([$acc->id, $like]);
                $folders = $stmtFld->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $stmtFil = $pdo->prepare("SELECT * FROM drive_files WHERE account_id = ? AND original_name LIKE ? ORDER BY original_name ASC");
                $stmtFil->execute([$acc->id, $like]);
                $files = $stmtFil->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $stmtFld = $pdo->prepare("SELECT * FROM drive_folders WHERE account_id = ? AND parent_id = ? ORDER BY name ASC");
                $stmtFld->execute([$acc->id, $folder_id]);
                $folders = $stmtFld->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $stmtFil = $pdo->prepare("SELECT * FROM drive_files WHERE account_id = ? AND folder_id = ? ORDER BY original_name ASC");
                $stmtFil->execute([$acc->id, $folder_id]);
                $files = $stmtFil->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            // Add formatted file size and image flag
            foreach ($files as &$f) {
                $f['formatted_size'] = format_bytes((int)($f['file_size'] ?? 0));
                $f['is_image'] = in_array(strtolower($f['extension'] ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
            }
            unset($f);

            // Build breadcrumbs
            $breadcrumbs = [];
            $curr = $folder_id;
            $depth = 0;
            while ($curr > 0 && $depth < 20) {
                $depth++;
                $st = $pdo->prepare("SELECT id, name, parent_id FROM drive_folders WHERE id = ? AND account_id = ?");
                $st->execute([$curr, $acc->id]);
                $cf = $st->fetch(PDO::FETCH_ASSOC);
                if ($cf) {
                    array_unshift($breadcrumbs, ['id' => (int)$cf['id'], 'name' => $cf['name']]);
                    $curr = (int)$cf['parent_id'];
                } else {
                    break;
                }
            }
            array_unshift($breadcrumbs, ['id' => 0, 'name' => 'Disk']);

            // Calculate total storage
            $stTot = $pdo->prepare("SELECT SUM(file_size) as total_size, COUNT(*) as total_files FROM drive_files WHERE account_id = ?");
            $stTot->execute([$acc->id]);
            $tot = $stTot->fetch(PDO::FETCH_ASSOC);
            $totalBytes = (int)($tot['total_size'] ?? 0);
            $totalFiles = (int)($tot['total_files'] ?? 0);

            echo json_encode([
                'success' => true,
                'current_folder_id' => $folder_id,
                'breadcrumbs' => $breadcrumbs,
                'folders' => $folders,
                'files' => $files,
                'storage' => [
                    'total_bytes' => $totalBytes,
                    'total_files' => $totalFiles,
                    'formatted' => format_bytes($totalBytes)
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Disk verileri alınamadı: ' . $e->getMessage(),
                'folders' => [],
                'files' => [],
                'breadcrumbs' => [['id' => 0, 'name' => 'Disk']],
                'storage' => ['total_bytes' => 0, 'total_files' => 0, 'formatted' => '0 B']
            ]);
        }
        exit;

    case 'drive_create_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Klasör adı boş bırakılamaz.']);
            exit;
        }

        $name = preg_replace('/[\\\\\\/:\*\?"<>\|]/', '', $name);
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz klasör adı.']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM drive_folders WHERE account_id = ? AND parent_id = ? AND name = ?");
        $check->execute([$acc->id, $parent_id, $name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Bu isimde bir klasör zaten mevcut.']);
            exit;
        }

        $ins = $pdo->prepare("INSERT INTO drive_folders (account_id, parent_id, name) VALUES (?, ?, ?)");
        $ins->execute([$acc->id, $parent_id, $name]);
        $new_id = (int)$pdo->lastInsertId();

        echo json_encode(['success' => true, 'message' => 'Klasör oluşturuldu.', 'folder_id' => $new_id]);
        exit;

    case 'drive_rename_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $folder_id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Klasör adı boş bırakılamaz.']);
            exit;
        }

        $name = preg_replace('/[\\\\\\/:\*\?"<>\|]/', '', $name);

        $up = $pdo->prepare("UPDATE drive_folders SET name = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
        $up->execute([$name, $folder_id, $acc->id]);

        echo json_encode(['success' => true, 'message' => 'Klasör adı güncellendi.']);
        exit;

    case 'drive_delete_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $folder_id = (int)($_POST['id'] ?? 0);

        if (!function_exists('drive_subfolder_ids')) {
            function drive_subfolder_ids($pdo, $acc_id, $f_id) {
                $ids = [$f_id];
                $st = $pdo->prepare("SELECT id FROM drive_folders WHERE account_id = ? AND parent_id = ?");
                $st->execute([$acc_id, $f_id]);
                $subs = $st->fetchAll(PDO::FETCH_COLUMN);
                foreach ($subs as $sub_id) {
                    $ids = array_merge($ids, drive_subfolder_ids($pdo, $acc_id, (int)$sub_id));
                }
                return $ids;
            }
        }

        $all_folders = drive_subfolder_ids($pdo, $acc->id, $folder_id);
        $inClause = implode(',', array_fill(0, count($all_folders), '?'));

        $params = array_merge([$acc->id], $all_folders);
        $stFiles = $pdo->prepare("SELECT stored_name FROM drive_files WHERE account_id = ? AND folder_id IN ($inClause)");
        $stFiles->execute($params);
        $filesToDelete = $stFiles->fetchAll(PDO::FETCH_COLUMN);

        $userDir = __DIR__ . '/uploads/drive/' . $acc->id;
        foreach ($filesToDelete as $sf) {
            $fpath = $userDir . '/' . $sf;
            if (file_exists($fpath)) {
                @unlink($fpath);
            }
        }

        $delFiles = $pdo->prepare("DELETE FROM drive_files WHERE account_id = ? AND folder_id IN ($inClause)");
        $delFiles->execute($params);

        $delFolders = $pdo->prepare("DELETE FROM drive_folders WHERE account_id = ? AND id IN ($inClause)");
        $delFolders->execute($params);

        echo json_encode(['success' => true, 'message' => 'Klasör ve içeriği silindi.']);
        exit;

    case 'drive_bulk_delete':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }

        $folder_ids = $_POST['folder_ids'] ?? [];
        if (is_string($folder_ids)) {
            $folder_ids = array_filter(array_map('intval', explode(',', $folder_ids)));
        } elseif (is_array($folder_ids)) {
            $folder_ids = array_filter(array_map('intval', $folder_ids));
        }

        $file_ids = $_POST['file_ids'] ?? [];
        if (is_string($file_ids)) {
            $file_ids = array_filter(array_map('intval', explode(',', $file_ids)));
        } elseif (is_array($file_ids)) {
            $file_ids = array_filter(array_map('intval', $file_ids));
        }

        if (empty($folder_ids) && empty($file_ids)) {
            echo json_encode(['success' => false, 'message' => 'Silinecek öğe seçilmedi.']);
            exit;
        }

        $userDir = __DIR__ . '/uploads/drive/' . $acc->id;

        // 1. Delete standalone files
        if (!empty($file_ids)) {
            $inF = implode(',', array_fill(0, count($file_ids), '?'));
            $params = array_merge([$acc->id], $file_ids);
            $stFiles = $pdo->prepare("SELECT id, stored_name FROM drive_files WHERE account_id = ? AND id IN ($inF)");
            $stFiles->execute($params);
            $rows = $stFiles->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $fpath = $userDir . '/' . basename($r['stored_name']);
                if (file_exists($fpath)) {
                    @unlink($fpath);
                }
            }
            $delStmt = $pdo->prepare("DELETE FROM drive_files WHERE account_id = ? AND id IN ($inF)");
            $delStmt->execute($params);
        }

        // 2. Delete folders and all nested contents
        if (!empty($folder_ids)) {
            if (!function_exists('drive_subfolder_ids')) {
                function drive_subfolder_ids($pdo, $acc_id, $f_id) {
                    $ids = [$f_id];
                    $st = $pdo->prepare("SELECT id FROM drive_folders WHERE account_id = ? AND parent_id = ?");
                    $st->execute([$acc_id, $f_id]);
                    $subs = $st->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($subs as $sub_id) {
                        $ids = array_merge($ids, drive_subfolder_ids($pdo, $acc_id, (int)$sub_id));
                    }
                    return $ids;
                }
            }

            $allFolders = [];
            foreach ($folder_ids as $fid) {
                $allFolders = array_merge($allFolders, drive_subfolder_ids($pdo, $acc->id, $fid));
            }
            $allFolders = array_values(array_unique($allFolders));

            if (!empty($allFolders)) {
                $inClause = implode(',', array_fill(0, count($allFolders), '?'));
                $params = array_merge([$acc->id], $allFolders);

                $stFiles = $pdo->prepare("SELECT stored_name FROM drive_files WHERE account_id = ? AND folder_id IN ($inClause)");
                $stFiles->execute($params);
                $filesToDelete = $stFiles->fetchAll(PDO::FETCH_COLUMN);
                foreach ($filesToDelete as $sf) {
                    $fpath = $userDir . '/' . basename($sf);
                    if (file_exists($fpath)) {
                        @unlink($fpath);
                    }
                }

                $delFiles = $pdo->prepare("DELETE FROM drive_files WHERE account_id = ? AND folder_id IN ($inClause)");
                $delFiles->execute($params);

                $delFolders = $pdo->prepare("DELETE FROM drive_folders WHERE account_id = ? AND id IN ($inClause)");
                $delFolders->execute($params);
            }
        }

        $totalDeleted = count($file_ids) + count($folder_ids);
        echo json_encode(['success' => true, 'message' => "Seçilen {$totalDeleted} öğe başarıyla silindi."]);
        exit;

    case 'drive_upload':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $folder_id = (int)($_POST['folder_id'] ?? 0);

        if (empty($_FILES['files']) && empty($_FILES['file'])) {
            echo json_encode(['success' => false, 'message' => 'Yüklenecek dosya seçilmedi.']);
            exit;
        }

        $userDir = __DIR__ . '/uploads/drive/' . $acc->id;
        if (!is_dir($userDir)) {
            @mkdir($userDir, 0777, true);
        }

        $uploaded_count = 0;
        $filesArr = $_FILES['files'] ?? $_FILES['file'];

        $fileList = [];
        if (is_array($filesArr['name'])) {
            $count = count($filesArr['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($filesArr['error'][$i] === UPLOAD_ERR_OK) {
                    $fileList[] = [
                        'name' => $filesArr['name'][$i],
                        'tmp_name' => $filesArr['tmp_name'][$i],
                        'size' => $filesArr['size'][$i],
                        'type' => $filesArr['type'][$i]
                    ];
                }
            }
        } elseif ($filesArr['error'] === UPLOAD_ERR_OK) {
            $fileList[] = $filesArr;
        }

        $disallowed_drive_exts = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'pht', 'pgif',
            'shtml', 'htaccess', 'cgi', 'pl', 'py', 'sh', 'bat', 'exe', 'cmd', 'vbs', 'scr', 'com', 'jar', 'jsp', 'asp', 'aspx'
        ];
        $blocked_count = 0;

        foreach ($fileList as $item) {
            $origName = basename($item['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, $disallowed_drive_exts, true)) {
                $blocked_count++;
                continue;
            }
            $fsize = (int)$item['size'];
            $mime = !empty($item['type']) ? $item['type'] : 'application/octet-stream';

            $storedName = md5(uniqid($acc->id . '_', true)) . '_' . time() . ($ext ? '.' . $ext : '');
            $targetPath = $userDir . '/' . $storedName;

            if (move_uploaded_file($item['tmp_name'], $targetPath)) {
                if (function_exists('finfo_open')) {
                    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $detectedMime = @finfo_file($finfo, $targetPath);
                        @finfo_close($finfo);
                        if ($detectedMime) $mime = $detectedMime;
                    }
                }
                $ins = $pdo->prepare("INSERT INTO drive_files (account_id, folder_id, original_name, stored_name, file_size, mime_type, extension) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$acc->id, $folder_id, $origName, $storedName, $fsize, $mime, $ext]);
                $uploaded_count++;
            }
        }

        if ($uploaded_count === 0 && $blocked_count > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Güvenlik nedeniyle çalıştırılabilir/komut dosyalarının (.php, .exe, .sh vb.) yüklenmesine izin verilmemektedir.',
                'uploaded_count' => 0
            ]);
            exit;
        }

        echo json_encode([
            'success' => ($uploaded_count > 0),
            'message' => ($uploaded_count > 0) ? "{$uploaded_count} adet dosya başarıyla yüklendi." : "Dosya yüklenemedi.",
            'uploaded_count' => $uploaded_count
        ]);
        exit;

    case 'drive_download':
    case 'drive_preview':
        $acc = get_active_account($pdo);
        if (!$acc) {
            http_response_code(403);
            die('Oturum açılmadı.');
        }
        $file_id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND account_id = ?");
        $stmt->execute([$file_id, $acc->id]);
        $f = $stmt->fetch();
        if (!$f) {
            http_response_code(404);
            die('Dosya bulunamadı.');
        }

        $filePath = __DIR__ . '/uploads/drive/' . $acc->id . '/' . basename($f->stored_name);
        if (!file_exists($filePath)) {
            http_response_code(404);
            die('Dosya sunucuda bulunamadı.');
        }

        $safe_inline_mimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'
        ];
        $mime_out = strtolower(trim($f->mime_type ?: 'application/octet-stream'));
        $is_preview = ($action === 'drive_preview') && in_array($mime_out, $safe_inline_mimes, true);
        $disposition = $is_preview ? 'inline' : 'attachment';
        if (!$is_preview) {
            $mime_out = 'application/octet-stream';
        }

        header('Content-Description: File Transfer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        header('Content-Type: ' . $mime_out);
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($f->original_name) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;

    case 'drive_rename_file':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $file_id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Dosya adı boş bırakılamaz.']);
            exit;
        }

        $name = preg_replace('/[\\\\\\/:\*\?"<>\|]/', '', $name);

        $up = $pdo->prepare("UPDATE drive_files SET original_name = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
        $up->execute([$name, $file_id, $acc->id]);

        echo json_encode(['success' => true, 'message' => 'Dosya adı güncellendi.']);
        exit;

    case 'drive_delete_file':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $file_id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT stored_name FROM drive_files WHERE id = ? AND account_id = ?");
        $stmt->execute([$file_id, $acc->id]);
        $f = $stmt->fetch();
        if ($f) {
            $fpath = __DIR__ . '/uploads/drive/' . $acc->id . '/' . basename($f->stored_name);
            if (file_exists($fpath)) {
                @unlink($fpath);
            }
            $del = $pdo->prepare("DELETE FROM drive_files WHERE id = ? AND account_id = ?");
            $del->execute([$file_id, $acc->id]);
        }

        echo json_encode(['success' => true, 'message' => 'Dosya silindi.']);
        exit;

    case 'drive_move_file':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $file_id = (int)($_POST['id'] ?? 0);
        $target_folder_id = (int)($_POST['target_folder_id'] ?? 0);

        $chkFile = $pdo->prepare("SELECT id FROM drive_files WHERE id = ? AND account_id = ?");
        $chkFile->execute([$file_id, $acc->id]);
        if (!$chkFile->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Dosya bulunamadı.']);
            exit;
        }

        if ($target_folder_id > 0) {
            $chkFld = $pdo->prepare("SELECT id FROM drive_folders WHERE id = ? AND account_id = ?");
            $chkFld->execute([$target_folder_id, $acc->id]);
            if (!$chkFld->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Hedef klasör bulunamadı.']);
                exit;
            }
        }

        $up = $pdo->prepare("UPDATE drive_files SET folder_id = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
        $up->execute([$target_folder_id, $file_id, $acc->id]);

        echo json_encode(['success' => true, 'message' => 'Dosya başarıyla taşındı.']);
        exit;

    case 'drive_move_folder':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum açılmadı.']);
            exit;
        }
        $folder_id = (int)($_POST['id'] ?? 0);
        $target_folder_id = (int)($_POST['target_folder_id'] ?? 0);

        if ($folder_id === $target_folder_id) {
            echo json_encode(['success' => false, 'message' => 'Klasör kendi içine taşınamaz.']);
            exit;
        }

        $chkFld = $pdo->prepare("SELECT id FROM drive_folders WHERE id = ? AND account_id = ?");
        $chkFld->execute([$folder_id, $acc->id]);
        if (!$chkFld->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Klasör bulunamadı.']);
            exit;
        }

        if ($target_folder_id > 0) {
            // Check if target folder exists
            $chkTgt = $pdo->prepare("SELECT id FROM drive_folders WHERE id = ? AND account_id = ?");
            $chkTgt->execute([$target_folder_id, $acc->id]);
            if (!$chkTgt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Hedef klasör bulunamadı.']);
                exit;
            }

            // Check if target folder is a descendant of folder_id
            $curr = $target_folder_id;
            while ($curr > 0) {
                if ($curr === $folder_id) {
                    echo json_encode(['success' => false, 'message' => 'Klasör kendi alt klasörünün içine taşınamaz.']);
                    exit;
                }
                $stP = $pdo->prepare("SELECT parent_id FROM drive_folders WHERE id = ? AND account_id = ?");
                $stP->execute([$curr, $acc->id]);
                $curr = (int)$stP->fetchColumn();
            }
        }

        $up = $pdo->prepare("UPDATE drive_folders SET parent_id = ?, updated_at = NOW() WHERE id = ? AND account_id = ?");
        $up->execute([$target_folder_id, $folder_id, $acc->id]);

        echo json_encode(['success' => true, 'message' => 'Klasör başarıyla taşındı.']);
        exit;

    case 'languages_list':
        $langDir = __DIR__ . '/languages';
        if (!is_dir($langDir)) {
            @mkdir($langDir, 0777, true);
        }
        $files = glob($langDir . '/*.json');
        $languages = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $json = json_decode($content, true);
            $base = basename($file, '.json');
            if ($json && isset($json['_meta'])) {
                $count = isset($json['translations']) ? count($json['translations']) : 0;
                $languages[] = [
                    'code' => $json['_meta']['code'] ?? $base,
                    'name' => $json['_meta']['name'] ?? ucfirst($base),
                    'flag' => $json['_meta']['flag'] ?? '🌐',
                    'is_default' => !empty($json['_meta']['is_default']),
                    'keys_count' => $count,
                    'key_count' => $count,
                    'updated_at' => date('Y-m-d H:i:s', filemtime($file))
                ];
            } else {
                $count = is_array($json) ? count($json) : 0;
                $languages[] = [
                    'code' => $base,
                    'name' => ucfirst($base),
                    'flag' => '🌐',
                    'is_default' => ($base === 'tr'),
                    'keys_count' => $count,
                    'key_count' => $count,
                    'updated_at' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }
        echo json_encode(['success' => true, 'languages' => $languages, 'data' => $languages]);
        exit;

    case 'language_get':
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_GET['code'] ?? $_POST['code'] ?? 'tr'));
        $filePath = __DIR__ . '/languages/' . $code . '.json';
        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'message' => 'Dil dosyası bulunamadı: ' . $code]);
            exit;
        }
        $json = json_decode(file_get_contents($filePath), true);
        if (!$json) {
            echo json_encode(['success' => false, 'message' => 'Dil dosyası bozuk veya geçersiz JSON.']);
            exit;
        }
        $meta = $json['_meta'] ?? ['code' => $code, 'name' => ucfirst($code), 'flag' => '🌐'];
        $translations = $json['translations'] ?? $json;
        unset($translations['_meta']);
        echo json_encode([
            'success' => true, 
            'meta' => $meta, 
            'translations' => $translations,
            'data' => [
                '_meta' => $meta,
                'translations' => $translations
            ]
        ]);
        exit;

    case 'language_save':
        $acc = require_admin($pdo);
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_POST['code'] ?? ''));
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz dil kodu.']);
            exit;
        }
        $name = trim($_POST['name'] ?? ucfirst($code));
        $flag = trim($_POST['flag'] ?? '🌐');
        $rawTrans = $_POST['translations'] ?? [];
        if (is_string($rawTrans)) {
            $trans = json_decode($rawTrans, true);
        } else {
            $trans = (array)$rawTrans;
        }
        if (!is_array($trans)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz çeviri formatı.']);
            exit;
        }

        $langDir = __DIR__ . '/languages';
        if (!is_dir($langDir)) @mkdir($langDir, 0777, true);

        $filePath = $langDir . '/' . $code . '.json';
        $payload = [
            '_meta' => [
                'code' => $code,
                'name' => $name,
                'flag' => $flag,
                'is_default' => ($code === 'tr')
            ],
            'translations' => $trans
        ];

        file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'message' => "'{$name}' dil dosyası başarıyla kaydedildi."]);
        exit;

    case 'language_create':
        $acc = require_admin($pdo);
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_POST['code'] ?? ''));
        if (strlen($code) < 2) {
            echo json_encode(['success' => false, 'message' => 'Dil kodu en az 2 karakter olmalıdır (örn: fr, es, it).']);
            exit;
        }
        $name = trim($_POST['name'] ?? ucfirst($code));
        $flag = trim($_POST['flag'] ?? '🌐');
        $copyFrom = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_POST['copy_from'] ?? 'tr'));

        $langDir = __DIR__ . '/languages';
        if (!is_dir($langDir)) @mkdir($langDir, 0777, true);

        $targetFile = $langDir . '/' . $code . '.json';
        if (file_exists($targetFile)) {
            echo json_encode(['success' => false, 'message' => "'{$code}' dil dosyası zaten mevcut."]);
            exit;
        }

        $sourceFile = $langDir . '/' . $copyFrom . '.json';
        $trans = [];
        if (file_exists($sourceFile)) {
            $srcJson = json_decode(file_get_contents($sourceFile), true);
            $trans = $srcJson['translations'] ?? $srcJson;
            unset($trans['_meta']);
        }

        $payload = [
            '_meta' => [
                'code' => $code,
                'name' => $name,
                'flag' => $flag,
                'is_default' => false
            ],
            'translations' => $trans
        ];

        file_put_contents($targetFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'message' => "'{$name}' dili başarıyla oluşturuldu."]);
        exit;

    case 'language_delete':
        $acc = require_admin($pdo);
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_POST['code'] ?? ''));
        if ($code === 'tr') {
            echo json_encode(['success' => false, 'message' => 'Varsayılan Türkçe dili silinemez.']);
            exit;
        }
        $filePath = __DIR__ . '/languages/' . $code . '.json';
        if (file_exists($filePath)) {
            @unlink($filePath);
            echo json_encode(['success' => true, 'message' => 'Dil dosyası başarıyla silindi.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Dil dosyası bulunamadı.']);
        }
        exit;

    case 'language_upload':
        $acc = require_admin($pdo);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi.']);
            exit;
        }
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $json = json_decode($raw, true);
        if (!$json || !is_array($json)) {
            echo json_encode(['success' => false, 'message' => 'Yüklenen dosya geçerli bir JSON dosyası değil.']);
            exit;
        }

        $origName = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($json['_meta']['code'] ?? $origName));
        if (strlen($code) < 2) {
            $code = 'custom_' . time();
        }
        $name = trim($json['_meta']['name'] ?? ucfirst($code));
        $flag = trim($json['_meta']['flag'] ?? '🌐');

        $trans = $json['translations'] ?? $json;
        unset($trans['_meta']);

        $langDir = __DIR__ . '/languages';
        if (!is_dir($langDir)) @mkdir($langDir, 0777, true);

        $payload = [
            '_meta' => [
                'code' => $code,
                'name' => $name,
                'flag' => $flag,
                'is_default' => ($code === 'tr')
            ],
            'translations' => $trans
        ];

        file_put_contents($langDir . '/' . $code . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'message' => "'{$name}' dil dosyası başarıyla yüklendi ve eklendi."]);
        exit;

    case 'language_download':
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($_GET['code'] ?? 'tr'));
        $filePath = __DIR__ . '/languages/' . $code . '.json';
        if (!file_exists($filePath)) {
            die('Dil dosyası bulunamadı.');
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $code . '.json"');
        readfile($filePath);
        exit;

    // ==========================================
    // EMAIL IMPORT / MIGRATION ACTIONS
    // ==========================================
    case 'test_import_connection':
        try {
            ensure_mail_imports_table($pdo);
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo safe_json_encode(['success' => false, 'message' => 'Oturum açılmamış.']);
                exit;
            }

            $source_email = trim($_POST['source_email'] ?? '');
            $source_pass  = $_POST['source_password'] ?? '';
            $source_host  = trim($_POST['source_host'] ?? '');
            $source_port  = (int)($_POST['source_port'] ?? 993);
            $source_enc   = strtolower(trim($_POST['source_encryption'] ?? 'ssl'));

            if (empty($source_email) || empty($source_pass) || empty($source_host)) {
                echo safe_json_encode(['success' => false, 'message' => 'Lütfen e-posta adresi, şifre ve sunucu adresini eksiksiz girin.']);
                exit;
            }

            $flags = ($source_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : (($source_enc === 'tls' || $source_enc === 'starttls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
            $server_prefix = "{" . "{$source_host}:{$source_port}{$flags}" . "}";

            imap_timeout(IMAP_OPENTIMEOUT, 8);
            $old = error_reporting(0);
            $conn = @imap_open($server_prefix . "INBOX", $source_email, $source_pass, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            $imap_err = imap_last_error();
            @imap_errors();
            @imap_alerts();
            error_reporting($old);

            if (!$conn) {
                $err_msg = 'Dış IMAP sunucusuna bağlanılamadı.';
                if ($imap_err) {
                    if (stripos($imap_err, 'authentication') !== false || stripos($imap_err, 'logon') !== false || stripos($imap_err, 'password') !== false) {
                        $err_msg = 'Kullanıcı adı veya şifre hatalı. (Gmail veya Yandex kullanıyorsanız hesap ayarlarından bir "Uygulama Şifresi" almanız gerekir).';
                    } elseif (stripos($imap_err, 'login failures') !== false) {
                        $err_msg = 'Çok fazla hatalı deneme yapıldı. Yandex/Gmail güvenlik koruması nedeniyle lütfen birkaç dakika bekleyip tekrar deneyin.';
                    } else {
                        $err_msg .= ' (' . $imap_err . ')';
                    }
                }
                echo safe_json_encode(['success' => false, 'message' => $err_msg]);
                exit;
            }

            $mailboxes = @imap_getmailboxes($conn, $server_prefix, "*");
            $folders = [];
            $total_msgs = 0;

            if ($mailboxes) {
                foreach ($mailboxes as $mb) {
                    $rawName = str_replace($server_prefix, '', $mb->name);
                    $decoded = function_exists('mb_convert_encoding') ? @mb_convert_encoding($rawName, 'UTF-8', 'UTF7-IMAP') : $rawName;
                    $st = @imap_status($conn, $server_prefix . safe_utf7_encode($rawName), SA_MESSAGES | SA_UNSEEN);
                    $count = $st ? (int)$st->messages : 0;
                    $unseen = $st ? (int)$st->unseen : 0;
                    $total_msgs += $count;
                    $folders[] = [
                        'name' => $rawName,
                        'display_name' => $decoded ?: $rawName,
                        'total' => $count,
                        'unseen' => $unseen
                    ];
                }
            } else {
                $st = @imap_status($conn, $server_prefix . 'INBOX', SA_MESSAGES | SA_UNSEEN);
                $count = $st ? (int)$st->messages : 0;
                $unseen = $st ? (int)$st->unseen : 0;
                $total_msgs += $count;
                $folders[] = [
                    'name' => 'INBOX',
                    'display_name' => 'Gelen Kutusu (INBOX)',
                    'total' => $count,
                    'unseen' => $unseen
                ];
            }

            @imap_close($conn);
            @imap_errors();
            @imap_alerts();

            echo safe_json_encode([
                'success' => true,
                'message' => "Bağlantı başarılı! Toplam {$total_msgs} e-posta tespit edildi.",
                'total_messages' => $total_msgs,
                'folders' => $folders
            ]);
            exit;
        } catch (Throwable $e) {
            echo safe_json_encode(['success' => false, 'message' => 'Bağlantı hatası: ' . $e->getMessage()]);
            exit;
        }

    case 'start_email_import':
        try {
            ensure_mail_imports_table($pdo);
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo safe_json_encode(['success' => false, 'message' => 'Aktif hesap oturumu bulunamadı. Lütfen sayfayı yenileyip tekrar giriş yapın.']);
                exit;
            }

            $source_email = trim($_POST['source_email'] ?? '');
            $source_pass  = $_POST['source_password'] ?? '';
            $source_host  = trim($_POST['source_host'] ?? '');
            $source_port  = (int)($_POST['source_port'] ?? 993);
            $source_enc   = strtolower(trim($_POST['source_encryption'] ?? 'ssl'));
            $source_folder = trim($_POST['source_folder'] ?? 'ALL');
            $target_mode  = trim($_POST['target_mode'] ?? 'same'); // 'same' or 'custom'
            $custom_target = trim($_POST['custom_target_folder'] ?? 'Eski_Postalar');
            $date_filter  = trim($_POST['date_filter'] ?? 'all');
            $limit_count  = (int)($_POST['limit_count'] ?? 0);
            $skip_dup     = !empty($_POST['skip_duplicates']) ? 1 : 0;

            if (empty($source_email) || empty($source_pass) || empty($source_host)) {
                echo safe_json_encode(['success' => false, 'message' => 'Lütfen kaynak e-posta bilgilerini eksiksiz girin.']);
                exit;
            }

            if (!empty($_POST['target_password'])) {
                $tp = trim($_POST['target_password']);
                $pdo->prepare("UPDATE mail_accounts SET imap_password = ?, smtp_password = ? WHERE id = ?")->execute([$tp, $tp, $acc->id]);
                $acc->imap_password = $tp;
            }

            if (empty($acc->imap_password)) {
                echo safe_json_encode([
                    'success' => false,
                    'need_target_password' => true,
                    'message' => 'Hedef e-posta kutunuzun (yerel hesabın) şifresi eksik. Lütfen hedef hesap şifrenizi girin.'
                ]);
                exit;
            }

            // Check if an active import job is already running for this account
            $checkActive = $pdo->prepare("SELECT id, status, updated_at FROM mail_imports WHERE account_id = ? AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1");
            $checkActive->execute([$acc->id]);
            $activeJob = $checkActive->fetch(PDO::FETCH_ASSOC);

            if ($activeJob) {
                // Check if job is stale (older than 20 minutes without update)
                $lastUpdate = strtotime($activeJob['updated_at'] ?? 'now');
                if ((time() - $lastUpdate) < 1200) {
                    echo safe_json_encode([
                        'success' => false,
                        'message' => 'Zaten arka planda devam eden bir içe aktarma işleminiz bulunmaktadır. İlerlemesini ekrandan takip edebilir veya durdurabilirsiniz.',
                        'job_id' => $activeJob['id']
                    ]);
                    exit;
                } else {
                    // Mark stale job as failed
                    $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = 'İşlem zaman aşımına uğradı', completed_at = NOW(), source_password = NULL WHERE id = ?")->execute([$activeJob['id']]);
                }
            }

            // Quick credential check (6 sec timeout)
            $flags = ($source_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : (($source_enc === 'tls' || $source_enc === 'starttls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
            $server_prefix = "{" . "{$source_host}:{$source_port}{$flags}" . "}";
            imap_timeout(IMAP_OPENTIMEOUT, 6);
            $old = error_reporting(0);
            $quickConn = @imap_open($server_prefix . "INBOX", $source_email, $source_pass, OP_HALFOPEN, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            $imap_err = imap_last_error();
            @imap_errors();
            @imap_alerts();
            error_reporting($old);

            if (!$quickConn) {
                $err = $imap_err ?: 'Sunucuya bağlanılamadı. Lütfen sunucu adresi, port, kullanıcı adı ve şifrenizi kontrol edin.';
                if (stripos($err, 'login failures') !== false) {
                    $err = 'Çok fazla bağlantı denemesi yapıldı. Yandex/Gmail güvenlik koruması nedeniyle lütfen birkaç dakika bekleyip tekrar deneyin.';
                }
                echo safe_json_encode(['success' => false, 'message' => 'Giriş Başarısız: ' . $err]);
                exit;
            }
            @imap_close($quickConn);
            @imap_errors();
            @imap_alerts();

            // Insert job into database
            $stmt = $pdo->prepare("INSERT INTO mail_imports (
                account_id, source_email, source_password, source_host, source_port, source_encryption,
                source_folder, target_folder, target_mode, custom_target_folder, date_filter, limit_count,
                skip_duplicates, imported_count, total_found, skipped_count, progress_percent, status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, 0, 0, 0, 0, 'pending', NOW(), NOW()
            )");

            $targetFolderLabel = ($target_mode === 'custom') ? $custom_target : 'Aynı Klasörler';
            $stmt->execute([
                $acc->id, $source_email, $source_pass, $source_host, $source_port, $source_enc,
                $source_folder, $targetFolderLabel, $target_mode, $custom_target, $date_filter, $limit_count,
                $skip_dup
            ]);
            $job_id = (int)$pdo->lastInsertId();

            // Launch background worker
            $workerScript = __DIR__ . '/worker_import.php';
            $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

            $phpCli = 'php';
            $candidates = [
                defined('PHP_BINARY') ? PHP_BINARY : '',
                dirname(dirname(defined('PHP_BINARY') ? PHP_BINARY : '')) . '/php/php.exe',
                'E:\\xampp\\php\\php.exe',
                'C:\\xampp\\php\\php.exe',
                'D:\\xampp\\php\\php.exe'
            ];
            foreach ($candidates as $cand) {
                if (!empty($cand) && file_exists($cand) && (stripos($cand, 'php.exe') !== false || stripos($cand, 'php-cli') !== false || substr($cand, -3) === 'php')) {
                    $phpCli = $cand;
                    break;
                }
            }

            if ($isWindows) {
                // Must redirect stdout & stderr to NUL and use start "" /B so popen/pclose does not block the web server!
                $cmd = 'start "" /B ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($workerScript) . ' ' . $job_id . ' > NUL 2> NUL';
                @pclose(@popen($cmd, "r"));
            } else {
                $cmd = escapeshellarg($phpCli) . " " . escapeshellarg($workerScript) . " " . $job_id . " > /dev/null 2>&1 &";
                @exec($cmd);
            }

            echo safe_json_encode([
                'success' => true,
                'job_id' => $job_id,
                'message' => 'İçe aktarma işlemi sunucu arka planında başlatıldı! Tarayıcınızı veya bu sayfayı güvenle kapatabilirsiniz, işlem sunucuda devam edecektir.'
            ]);
            exit;
        } catch (Throwable $e) {
            echo safe_json_encode([
                'success' => false,
                'message' => 'Aktarım başlatma hatası: ' . $e->getMessage()
            ]);
            exit;
        }

    case 'get_active_import':
        try {
            ensure_mail_imports_table($pdo);
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo safe_json_encode(['success' => false, 'has_active' => false]);
                exit;
            }

            // Check running or pending job
            $stmt = $pdo->prepare("SELECT id, source_email, source_host, source_folder, target_folder, imported_count, total_found, skipped_count, current_folder, progress_percent, status, error_message, created_at, updated_at FROM mail_imports WHERE account_id = ? AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1");
            $stmt->execute([$acc->id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($job) {
                echo safe_json_encode([
                    'success' => true,
                    'has_active' => true,
                    'has_target_password' => !empty($acc->imap_password),
                    'job' => $job
                ]);
                exit;
            }

            // Check recently completed/failed/cancelled job (within last 35 seconds)
            $stmtRecent = $pdo->prepare("SELECT id, source_email, source_host, source_folder, target_folder, imported_count, total_found, skipped_count, current_folder, progress_percent, status, error_message, completed_at FROM mail_imports WHERE account_id = ? AND status IN ('completed', 'completed_with_errors', 'failed', 'cancelled') AND completed_at >= (NOW() - INTERVAL 35 SECOND) ORDER BY id DESC LIMIT 1");
            $stmtRecent->execute([$acc->id]);
            $recent = $stmtRecent->fetch(PDO::FETCH_ASSOC);

            echo safe_json_encode([
                'success' => true,
                'has_active' => false,
                'has_target_password' => !empty($acc->imap_password),
                'recent_job' => $recent ?: null
            ]);
            exit;
        } catch (Throwable $e) {
            echo safe_json_encode(['success' => false, 'has_active' => false, 'error' => $e->getMessage()]);
            exit;
        }

    case 'cancel_email_import':
        try {
            ensure_mail_imports_table($pdo);
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo safe_json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
                exit;
            }

            $job_id = (int)($_POST['job_id'] ?? 0);
            if ($job_id > 0) {
                $stmt = $pdo->prepare("UPDATE mail_imports SET status = 'cancelled', error_message = 'Kullanıcı tarafından durduruldu', completed_at = NOW(), source_password = NULL WHERE id = ? AND account_id = ?");
                $stmt->execute([$job_id, $acc->id]);
            } else {
                $stmt = $pdo->prepare("UPDATE mail_imports SET status = 'cancelled', error_message = 'Kullanıcı tarafından durduruldu', completed_at = NOW(), source_password = NULL WHERE account_id = ? AND status IN ('pending', 'running')");
                $stmt->execute([$acc->id]);
            }

            echo safe_json_encode(['success' => true, 'message' => 'İçe aktarma işlemi durduruldu.']);
            exit;
        } catch (Throwable $e) {
            echo safe_json_encode(['success' => false, 'message' => 'Durdurma hatası: ' . $e->getMessage()]);
            exit;
        }

    case 'get_import_history':
        try {
            ensure_mail_imports_table($pdo);
            $acc = get_active_account($pdo);
            if (!$acc) {
                echo safe_json_encode(['success' => false, 'history' => []]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM mail_imports WHERE account_id = ? ORDER BY created_at DESC LIMIT 15");
            $stmt->execute([$acc->id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo safe_json_encode(['success' => true, 'history' => $rows ?: []]);
            exit;
        } catch (Throwable $e) {
            echo safe_json_encode(['success' => false, 'history' => [], 'error' => $e->getMessage()]);
            exit;
        }

    /* ==============================================================
       ADMIN MANAGEMENT PANEL ENDPOINTS
       ============================================================== */
    case 'admin_get_stats':
        require_admin($pdo);
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';
        $conf = get_hestia_config($pdo);
        
        $total_accounts = (int)$pdo->query("SELECT COUNT(*) FROM mail_accounts")->fetchColumn();
        $total_admins = (int)$pdo->query("SELECT COUNT(*) FROM mail_accounts WHERE is_admin = 1")->fetchColumn();
        
        $hestia_active = $conf['is_configured'];
        $hestia_connected = false;
        $hestia_account_count = 0;
        $hestia_total_disk_mb = 0;
        
        if ($hestia_active) {
            $hData = fetch_hestia_mail_accounts($pdo, $domain);
            if (is_array($hData)) {
                $hestia_connected = true;
                $hestia_account_count = count($hData);
                foreach ($hData as $hItem) {
                    $hestia_total_disk_mb += (float)($hItem['U_DISK'] ?? 0);
                }
            }
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'total_accounts' => $total_accounts,
                'total_admins' => $total_admins,
                'domain' => $domain,
                'hestia_active' => (bool)$hestia_active,
                'hestia_connected' => (bool)$hestia_connected,
                'server_mode' => $conf['server_mode'] ?? 'remote',
                'is_local_installed' => (bool)$conf['is_local_installed'],
                'hestia_host' => $conf['host'],
                'hestia_user' => $conf['user'],
                'hestia_account_count' => $hestia_account_count,
                'hestia_total_disk_mb' => round($hestia_total_disk_mb, 1)
            ]
        ]);
        exit;

    case 'admin_list_accounts':
        try {
            ensure_mail_accounts_columns($pdo);
            $current_admin = require_admin($pdo);
            $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
            $role_filter = trim($_GET['role'] ?? $_POST['role'] ?? '');
            $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';

            // Fetch Hestia data for status & disk quota decorating (reads from cache, never stalls)
            $conf = get_hestia_config($pdo);
            $hestiaData = null;
            if ($conf['is_configured']) {
                try {
                    $hestiaData = fetch_hestia_mail_accounts($pdo, $domain);
                } catch (Throwable $th) {
                    $hestiaData = null;
                }
            }

            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = "(name LIKE ? OR email LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }
            if ($role_filter === 'admin') {
                $where[] = "is_admin = 1";
            } else if ($role_filter === 'user') {
                $where[] = "(is_admin = 0 OR is_admin IS NULL)";
            }

            $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
            $stmt = $pdo->prepare("SELECT id, name, email, imap_host, is_default, is_admin, role, created_at FROM mail_accounts {$whereClause} ORDER BY is_admin DESC, is_default DESC, name ASC, email ASC");
            $stmt->execute($params);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Auto-sync fallback ONLY IF local database is completely empty and Hestia is configured
            if (empty($accounts) && $search === '' && $role_filter === '' && $conf['is_configured']) {
                $syncRes = sync_hestia_accounts_to_db($pdo, $domain, true);
                if ($syncRes['success'] && !empty($syncRes['hestia_accounts'])) {
                    $hestiaData = $syncRes['hestia_accounts'];
                    $stmt->execute($params);
                    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            foreach ($accounts as &$accItem) {
                $accItem['is_current'] = ((int)$accItem['id'] === (int)$current_admin->id);
                $accItem['is_admin'] = (int)($accItem['is_admin'] ?? 0);
                if (empty($accItem['created_at'])) {
                    $accItem['created_at'] = '-';
                }

                // Default Hestia metadata
                $accItem['hestia_synced'] = false;
                $accItem['disk_used_mb'] = 0;
                $accItem['quota_mb'] = 0;
                $accItem['is_suspended'] = false;
                $accItem['aliases'] = '';
                $accItem['forward'] = '';
                $accItem['autoreply'] = false;
                $accItem['server_status'] = 'active';

                // Match with live/cached HestiaCP account data if fetched
                if (is_array($hestiaData)) {
                    $emailParts = explode('@', strtolower($accItem['email']));
                    $uName = $emailParts[0];
                    $hInfo = null;

                    if (isset($hestiaData[$uName])) {
                        $hInfo = $hestiaData[$uName];
                    } else if (isset($hestiaData[strtolower($accItem['email'])])) {
                        $hInfo = $hestiaData[strtolower($accItem['email'])];
                    } else {
                        foreach ($hestiaData as $hk => $hv) {
                            if (strtolower($hk) === $uName || strtolower($hk) === strtolower($accItem['email'])) {
                                $hInfo = $hv;
                                break;
                            }
                        }
                    }

                    if ($hInfo) {
                        $accItem['hestia_synced'] = true;
                        $accItem['disk_used_mb'] = isset($hInfo['U_DISK']) ? (float)$hInfo['U_DISK'] : 0;
                        $accItem['quota_mb'] = isset($hInfo['QUOTA']) ? (float)$hInfo['QUOTA'] : 0;
                        $accItem['is_suspended'] = (strtolower($hInfo['SUSPENDED'] ?? 'no') === 'yes');
                        $accItem['aliases'] = trim($hInfo['ALIAS'] ?? '');
                        $accItem['forward'] = trim($hInfo['FORWARD'] ?? '');
                        $accItem['autoreply'] = (strtolower($hInfo['AUTOREPLY'] ?? 'no') === 'yes');
                        $accItem['server_status'] = $accItem['is_suspended'] ? 'suspended' : 'active';
                        if (!empty($hInfo['DATE']) && ($accItem['created_at'] === '-' || empty($accItem['created_at']))) {
                            $accItem['created_at'] = $hInfo['DATE'] . ' ' . ($hInfo['TIME'] ?? '00:00:00');
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'accounts' => $accounts,
                'hestia_active' => (bool)$conf['is_configured'],
                'hestia_connected' => is_array($hestiaData)
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Hesaplar listelenirken hata oluştu: ' . $e->getMessage(),
                'accounts' => []
            ]);
            exit;
        }

    case 'admin_create_account':
        $current_admin = require_admin($pdo);
        $name = trim($_POST['name'] ?? '');
        $email_input = trim($_POST['email'] ?? $_POST['email_user'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $is_admin = !empty($_POST['is_admin']) ? 1 : 0;
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';

        if (empty($email_input)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen e-posta adresini giriniz.']);
            exit;
        }

        // Parse and validate email & domain lock
        if (strpos($email_input, '@') !== false) {
            $parts = explode('@', $email_input, 2);
            $local_part = trim($parts[0]);
            $domain_part = trim($parts[1]);
            if ($domain && strtolower($domain_part) !== strtolower($domain)) {
                echo json_encode(['success' => false, 'message' => "Yeni e-posta hesabı yalnızca @{$domain} alan adına ait olabilir."]);
                exit;
            }
        } else {
            $local_part = $email_input;
        }

        $clean_local = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($local_part));
        if (empty($clean_local)) {
            echo json_encode(['success' => false, 'message' => 'Geçerli bir kullanıcı adı giriniz (yalnızca harf, rakam, nokta ve tire içerebilir).']);
            exit;
        }

        $full_email = $domain ? ($clean_local . '@' . $domain) : $clean_local;

        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Şifre en az 6 karakter olmalıdır.']);
            exit;
        }

        // Check uniqueness in mail_accounts
        $chk = $pdo->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
        $chk->execute([strtolower($full_email)]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => "'{$full_email}' adresi zaten kayıtlı bir hesaptır."]);
            exit;
        }

        // If HestiaCP API configured, create mailbox on server and apply options
        $hestia_note = '';
        $conf = get_hestia_config($pdo);
        if ($conf['is_configured']) {
            $domain_name = $domain ?: explode('@', $full_email)[1];
            $quotaRaw = trim($_POST['quota'] ?? '0');
            $quota = (empty($quotaRaw) || strtolower($quotaRaw) === 'unlimited' || strtolower($quotaRaw) === 'sınırsız') ? 0 : (int)$quotaRaw;
            
            $callRes = call_hestia_api($pdo, 'v-add-mail-account', [$conf['user'], $domain_name, $clean_local, $password, $quota], true);
            if ($callRes['success']) {
                $hestia_note = ' (HestiaCP sunucusunda posta kutusu oluşturuldu)';

                // A. Takma adlar (Aliases)
                $aliases_input = trim($_POST['aliases'] ?? '');
                if (!empty($aliases_input)) {
                    $cleanedAliases = preg_replace('/[\r\n,]+/', ' ', $aliases_input);
                    $aliasList = array_unique(array_filter(array_map('trim', explode(' ', $cleanedAliases))));
                    foreach ($aliasList as $al) {
                        $alClean = strtolower(explode('@', $al)[0]);
                        if (!empty($alClean)) {
                            call_hestia_api($pdo, 'v-add-mail-account-alias', [$conf['user'], $domain_name, $clean_local, $alClean], true);
                        }
                    }
                }

                // B. Yönlendirme (Forwarders) & Blackhole
                $blackhole = !empty($_POST['blackhole']) ? 1 : 0;
                $forward_input = trim($_POST['forward'] ?? '');
                if ($blackhole) {
                    call_hestia_api($pdo, 'v-add-mail-account-forward', [$conf['user'], $domain_name, $clean_local, ':blackhole:'], true);
                } else if (!empty($forward_input)) {
                    $cleanedFwds = preg_replace('/[\r\n,]+/', ' ', $forward_input);
                    $fwdList = array_unique(array_filter(array_map('trim', explode(' ', $cleanedFwds))));
                    foreach ($fwdList as $fwd) {
                        if (!empty($fwd) && filter_var($fwd, FILTER_VALIDATE_EMAIL)) {
                            call_hestia_api($pdo, 'v-add-mail-account-forward', [$conf['user'], $domain_name, $clean_local, strtolower($fwd)], true);
                        }
                    }
                }

                // C. Yönlendirilmiş postaları depolama (Forward Only)
                if (!empty($_POST['forward_only'])) {
                    call_hestia_api($pdo, 'v-add-mail-account-fwd-only', [$conf['user'], $domain_name, $clean_local], true);
                }

                // D. Hız Sınırı (Rate limit)
                $rate_limit = trim($_POST['rate_limit'] ?? '');
                if (!empty($rate_limit) && is_numeric($rate_limit)) {
                    call_hestia_api($pdo, 'v-change-mail-account-rate-limit', [$conf['user'], $domain_name, $clean_local, (int)$rate_limit], true);
                }
            } else if (!empty($callRes['message'])) {
                $hestia_note = ' (HestiaCP Bildirimi: ' . trim($callRes['message']) . ')';
            }
        }

        $imap_host = defined('IMAP_HOST') ? IMAP_HOST : (defined('DEFAULT_IMAP_HOST') ? DEFAULT_IMAP_HOST : 'localhost');
        $imap_port = defined('IMAP_PORT') ? IMAP_PORT : (defined('DEFAULT_IMAP_PORT') ? DEFAULT_IMAP_PORT : 993);
        $imap_enc  = defined('IMAP_ENCRYPTION') ? IMAP_ENCRYPTION : (defined('DEFAULT_IMAP_ENCRYPTION') ? DEFAULT_IMAP_ENCRYPTION : 'ssl');
        $smtp_host = defined('SMTP_HOST') ? SMTP_HOST : (defined('DEFAULT_SMTP_HOST') ? DEFAULT_SMTP_HOST : 'localhost');
        $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : (defined('DEFAULT_SMTP_PORT') ? DEFAULT_SMTP_PORT : 587);
        $smtp_enc  = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : (defined('DEFAULT_SMTP_ENCRYPTION') ? DEFAULT_SMTP_ENCRYPTION : 'tls');

        $role = $is_admin ? 'admin' : 'user';
        $displayName = $name ?: ucfirst($clean_local);

        $stmt = $pdo->prepare("INSERT INTO mail_accounts (name, email, imap_host, imap_port, imap_encryption, imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, is_default, is_admin, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");
        $stmt->execute([
            $displayName,
            $full_email,
            $imap_host,
            $imap_port,
            $imap_enc,
            $full_email,
            $password,
            $smtp_host,
            $smtp_port,
            $smtp_enc,
            $full_email,
            $password,
            $is_admin,
            $role
        ]);
        $new_id = (int)$pdo->lastInsertId();

        // Update domain directory
        try {
            $stmtDir = $pdo->prepare("INSERT INTO domain_directory (name, email, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role)");
            $stmtDir->execute([$displayName, $full_email, $role]);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'message' => "Yeni e-posta hesabı ({$full_email}) başarıyla oluşturuldu{$hestia_note}.",
            'account_id' => $new_id
        ]);
        exit;

    case 'admin_toggle_role':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_POST['id'] ?? 0);
        $make_admin = !empty($_POST['is_admin']) ? 1 : 0;

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hesap ID.']);
            exit;
        }

        // Safety check: Cannot demote the last remaining admin
        if ($make_admin === 0) {
            $total_admins = (int)$pdo->query("SELECT COUNT(*) FROM mail_accounts WHERE is_admin = 1")->fetchColumn();
            if ($total_admins <= 1) {
                $isTargetAdmin = (int)$pdo->query("SELECT is_admin FROM mail_accounts WHERE id = " . $target_id)->fetchColumn();
                if ($isTargetAdmin === 1) {
                    echo json_encode(['success' => false, 'message' => 'Sistemde en az bir yönetici bulunmalıdır. Son yöneticinin yetkisini kaldıramazsınız!']);
                    exit;
                }
            }
        }

        $role = $make_admin ? 'admin' : 'user';
        $stmt = $pdo->prepare("UPDATE mail_accounts SET is_admin = ?, role = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$make_admin, $role, $target_id]);

        // Sync directory
        try {
            $targetEmail = $pdo->query("SELECT email FROM mail_accounts WHERE id = " . $target_id)->fetchColumn();
            if ($targetEmail) {
                $pdo->prepare("UPDATE domain_directory SET role = ? WHERE LOWER(email) = ?")->execute([$role, strtolower($targetEmail)]);
            }
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'message' => $make_admin ? 'Kullanıcıya başarıyla Yönetici (Admin) yetkisi verildi.' : 'Kullanıcının yönetici yetkisi kaldırıldı.',
            'is_admin' => $make_admin,
            'role' => $role
        ]);
        exit;

    case 'admin_change_password':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_POST['id'] ?? 0);
        $new_pass = trim($_POST['password'] ?? '');

        if ($target_id <= 0 || empty($new_pass)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen geçerli bir hesap ve şifre giriniz.']);
            exit;
        }
        if (strlen($new_pass) < 8 || !preg_match('/[A-Z]/', $new_pass) || !preg_match('/[a-z]/', $new_pass) || !preg_match('/[0-9]/', $new_pass)) {
            echo json_encode(['success' => false, 'message' => 'Şifre HestiaCP sunucu standardına uygun olmalıdır: En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        $target_acc = $stmt->fetch();
        if (!$target_acc) {
            echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı.']);
            exit;
        }

        $conf = get_hestia_config($pdo);
        if (!$conf['is_configured']) {
            echo json_encode([
                'success' => false,
                'message' => 'HestiaCP sunucu entegrasyonu yapılandırılmamış. Şifrenin mail sunucusuyla senkronize değişebilmesi için Sunucu Ayarları bölümünden geçerli HestiaCP API anahtarını tanımlayınız.'
            ]);
            exit;
        }

        $user_parts = explode('@', $target_acc->email);
        $account_name = $user_parts[0];
        $domain_name = $user_parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        $callRes = call_hestia_api($pdo, 'v-change-mail-account-password', [$conf['user'], $domain_name, $account_name, $new_pass], true);
        if (!$callRes['success']) {
            $failMsg = !empty($callRes['message']) ? $callRes['message'] : 'Mail sunucusu şifre güncelleme komutunu reddetti.';
            echo json_encode([
                'success' => false,
                'message' => 'Şifre mail sunucusunda güncellenemedi: ' . $failMsg . ' (Sunucu ve veritabanı senkronizasyonunun bozulmaması için veritabanı şifresi değiştirilmedi).'
            ]);
            exit;
        }

        $up = $pdo->prepare("UPDATE mail_accounts SET imap_password = ?, smtp_password = ?, updated_at = NOW() WHERE id = ?");
        $up->execute([$new_pass, $new_pass, $target_id]);

        echo json_encode([
            'success' => true,
            'message' => "Hesap şifresi hem mail sunucusunda hem de sistemde başarıyla güncellendi."
        ]);
        exit;

    case 'admin_get_account_details':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hesap ID.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        $target_acc = $stmt->fetch();
        if (!$target_acc) {
            echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı.']);
            exit;
        }

        $emailParts = explode('@', strtolower($target_acc->email));
        $account_name = $emailParts[0];
        $domain_name = $emailParts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        $details = [
            'id' => (int)$target_acc->id,
            'name' => $target_acc->name,
            'email' => $target_acc->email,
            'local_part' => $account_name,
            'domain' => $domain_name,
            'is_admin' => (int)($target_acc->is_admin ?? 0),
            'role' => $target_acc->role ?? 'user',
            'quota' => 0,
            'aliases' => '',
            'forward' => '',
            'forward_only' => false,
            'blackhole' => false,
            'autoreply' => false,
            'autoreply_message' => '',
            'rate_limit' => '',
            'is_suspended' => false,
            'disk_used_mb' => 0,
            'hestia_synced' => false
        ];

        $conf = get_hestia_config($pdo);
        if ($conf['is_configured']) {
            $callRes = call_hestia_api($pdo, 'v-list-mail-account', [$conf['user'], $domain_name, $account_name, 'json'], false);
            if ($callRes['success']) {
                $hData = @json_decode($callRes['raw'], true);
                $accInfo = $hData[$account_name] ?? reset($hData) ?? null;
                if (is_array($accInfo)) {
                    $details['hestia_synced'] = true;
                    $details['quota'] = isset($accInfo['QUOTA']) ? (int)$accInfo['QUOTA'] : 0;
                    $details['disk_used_mb'] = isset($accInfo['U_DISK']) ? (float)$accInfo['U_DISK'] : 0;
                    $details['is_suspended'] = (strtolower($accInfo['SUSPENDED'] ?? 'no') === 'yes');
                    $details['rate_limit'] = (!empty($accInfo['RATE_LIMIT']) && $accInfo['RATE_LIMIT'] !== 'system') ? $accInfo['RATE_LIMIT'] : '';

                    // Takma Adlar (Aliases)
                    $rawAliases = trim($accInfo['ALIAS'] ?? '');
                    if ($rawAliases !== '') {
                        $details['aliases'] = str_replace(',', "\n", $rawAliases);
                    }

                    // Yönlendirme (Forwarders) & Blackhole
                    $rawFwd = trim($accInfo['FWD'] ?? $accInfo['FORWARD'] ?? '');
                    if ($rawFwd === ':blackhole:') {
                        $details['blackhole'] = true;
                        $details['forward'] = '';
                    } else {
                        $details['blackhole'] = false;
                        $details['forward'] = str_replace(',', "\n", $rawFwd);
                    }

                    // Yönlendirilmiş postaları depolama (Forward Only)
                    $details['forward_only'] = (strtolower($accInfo['FWD_ONLY'] ?? 'no') === 'yes');

                    // Otomatik Cevap (Autoreply)
                    $details['autoreply'] = (strtolower($accInfo['AUTOREPLY'] ?? 'no') === 'yes');
                    if ($details['autoreply']) {
                        $autoRes = call_hestia_api($pdo, 'v-list-mail-account-autoreply', [$conf['user'], $domain_name, $account_name, 'json'], false);
                        if ($autoRes['success']) {
                            $autoData = @json_decode($autoRes['raw'], true);
                            $autoInfo = $autoData[$account_name] ?? reset($autoData) ?? null;
                            if (is_array($autoInfo) && isset($autoInfo['MSG'])) {
                                $details['autoreply_message'] = str_replace('\n', "\n", $autoInfo['MSG']);
                            }
                        }
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'details' => $details
        ]);
        exit;

    case 'admin_update_account_details':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_POST['id'] ?? 0);

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hesap ID.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        $target_acc = $stmt->fetch();
        if (!$target_acc) {
            echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı.']);
            exit;
        }

        $emailParts = explode('@', strtolower($target_acc->email));
        $account_name = $emailParts[0];
        $domain_name = $emailParts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        $name = trim($_POST['name'] ?? '');
        $new_password = trim($_POST['password'] ?? '');
        $send_credentials_email = trim($_POST['send_credentials_email'] ?? '');
        $quota = trim($_POST['quota'] ?? '0');
        $quotaVal = (empty($quota) || strtolower($quota) === 'unlimited' || strtolower($quota) === 'sınırsız') ? 0 : (int)$quota;
        $aliases_input = trim($_POST['aliases'] ?? '');
        $forward_input = trim($_POST['forward'] ?? '');
        $blackhole = !empty($_POST['blackhole']) ? 1 : 0;
        $forward_only = !empty($_POST['forward_only']) ? 1 : 0;
        $autoreply = !empty($_POST['autoreply']) ? 1 : 0;
        $autoreply_message = trim($_POST['autoreply_message'] ?? '');
        $rate_limit = trim($_POST['rate_limit'] ?? '');

        // Update local name if changed
        if (!empty($name) && $name !== $target_acc->name) {
            $pdo->prepare("UPDATE mail_accounts SET name = ?, updated_at = NOW() WHERE id = ?")->execute([$name, $target_id]);
            try {
                $pdo->prepare("UPDATE domain_directory SET name = ? WHERE LOWER(email) = ?")->execute([$name, strtolower($target_acc->email)]);
            } catch (Exception $e) {}
        }

        $conf = get_hestia_config($pdo);
        $hestia_msgs = [];

        // 1. Password update if supplied
        if (!empty($new_password)) {
            // Validate password (min 8 chars, 1 uppercase, 1 lowercase, 1 digit)
            if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Şifre HestiaCP kurallarına uygun olmalıdır: En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.'
                ]);
                exit;
            }

            if ($conf['is_configured']) {
                $res = call_hestia_api($pdo, 'v-change-mail-account-password', [$conf['user'], $domain_name, $account_name, $new_password], true);
                if (!$res['success']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Şifre mail sunucusunda güncellenemedi: ' . ($res['message'] ?? 'Bilinmeyen hata') . ' (Sunucu senkronizasyonunun bozulmaması için şifre değiştirilmedi).'
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'HestiaCP sunucu entegrasyonu yapılandırılmamış. Şifrenin mail sunucusuyla senkronize değişebilmesi için Sunucu Ayarları bölümünden geçerli HestiaCP API anahtarını tanımlayınız.'
                ]);
                exit;
            }
            $pdo->prepare("UPDATE mail_accounts SET imap_password = ?, smtp_password = ?, updated_at = NOW() WHERE id = ?")->execute([$new_password, $new_password, $target_id]);

            // If send credentials email requested
            if (!empty($send_credentials_email) && filter_var($send_credentials_email, FILTER_VALIDATE_EMAIL)) {
                $subject = "E-Posta Giriş Bilgileriniz: {$target_acc->email}";
                $host = $_SERVER['HTTP_HOST'] ?? 'mail.' . $domain_name;
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $webmailUrl = $scheme . $host;
                $mailBody = "Merhaba,\n\n"
                    . "E-posta hesabınızın giriş bilgileri güncellenmiştir:\n\n"
                    . "E-Posta Adresi: {$target_acc->email}\n"
                    . "Yeni Şifre: {$new_password}\n"
                    . "Webmail Girişi: {$webmailUrl}\n\n"
                    . "IMAP Sunucu: mail.{$domain_name} (Port: 993, SSL/TLS)\n"
                    . "SMTP Sunucu: mail.{$domain_name} (Port: 587, STARTTLS veya 465 SSL)\n\n"
                    . "İyi çalışmalar dileriz.";
                
                try {
                    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mailer->CharSet = 'UTF-8';
                    $mailer->setFrom($target_acc->email, $name ?: 'Posta Yöneticisi');
                    $mailer->addAddress($send_credentials_email);
                    $mailer->Subject = $subject;
                    $mailer->Body = $mailBody;
                    $mailer->isHTML(false);
                    $mailer->isMail();
                    @$mailer->send();
                    $hestia_msgs[] = "Bilgiler {$send_credentials_email} adresine e-posta ile iletildi.";
                } catch (Exception $e) {}
            }
        }

        // 2. HestiaCP Server Updates
        if ($conf['is_configured']) {
            $currCall = call_hestia_api($pdo, 'v-list-mail-account', [$conf['user'], $domain_name, $account_name, 'json'], false);
            $currData = [];
            if ($currCall['success']) {
                $parsed = @json_decode($currCall['raw'], true);
                $currData = $parsed[$account_name] ?? reset($parsed) ?? [];
            }

            // A. Quota (MB)
            $currQuota = isset($currData['QUOTA']) ? (int)$currData['QUOTA'] : -1;
            if ($currQuota !== $quotaVal) {
                $qRes = call_hestia_api($pdo, 'v-change-mail-account-quota', [$conf['user'], $domain_name, $account_name, $quotaVal], true);
                if (!$qRes['success']) {
                    $hestia_msgs[] = 'Kota güncellenemedi: ' . ($qRes['message'] ?? '');
                }
            }

            // B. Hız Sınırı (Rate limit)
            $currRate = !empty($currData['RATE_LIMIT']) ? $currData['RATE_LIMIT'] : 'system';
            $newRate = (!empty($rate_limit) && is_numeric($rate_limit)) ? (int)$rate_limit : 'system';
            if ((string)$currRate !== (string)$newRate) {
                $rRes = call_hestia_api($pdo, 'v-change-mail-account-rate-limit', [$conf['user'], $domain_name, $account_name, $newRate], true);
                if (!$rRes['success']) {
                    $hestia_msgs[] = 'Hız sınırı güncellenemedi: ' . ($rRes['message'] ?? '');
                }
            }

            // C. Takma Adlar (Aliases)
            $rawAliases = trim($currData['ALIAS'] ?? '');
            $currAliases = $rawAliases !== '' ? array_map('trim', explode(',', $rawAliases)) : [];
            $currAliases = array_values(array_filter($currAliases));

            $cleanedNewAliases = preg_replace('/[\r\n,]+/', ' ', $aliases_input);
            $newAliases = array_values(array_filter(array_map('trim', explode(' ', $cleanedNewAliases))));
            $newAliases = array_map(function($a) {
                return strtolower(explode('@', $a)[0]);
            }, $newAliases);
            $newAliases = array_values(array_unique($newAliases));

            // Silinen alias'lar
            $delAliases = array_diff($currAliases, $newAliases);
            foreach ($delAliases as $dAlias) {
                if (!empty($dAlias)) {
                    call_hestia_api($pdo, 'v-delete-mail-account-alias', [$conf['user'], $domain_name, $account_name, $dAlias], true);
                }
            }
            // Eklenen yeni alias'lar
            $addAliases = array_diff($newAliases, $currAliases);
            foreach ($addAliases as $aAlias) {
                if (!empty($aAlias)) {
                    call_hestia_api($pdo, 'v-add-mail-account-alias', [$conf['user'], $domain_name, $account_name, $aAlias], true);
                }
            }

            // D. Yönlendirme (Forwarders) & Blackhole
            $rawFwd = trim($currData['FWD'] ?? $currData['FORWARD'] ?? '');
            $currFwds = $rawFwd !== '' ? array_map('trim', explode(',', $rawFwd)) : [];
            $currFwds = array_values(array_filter($currFwds));

            if ($blackhole) {
                // Tüm postaları iptal et (:blackhole:)
                foreach ($currFwds as $oldFwd) {
                    if ($oldFwd !== ':blackhole:') {
                        call_hestia_api($pdo, 'v-delete-mail-account-forward', [$conf['user'], $domain_name, $account_name, $oldFwd], true);
                    }
                }
                if (!in_array(':blackhole:', $currFwds)) {
                    call_hestia_api($pdo, 'v-add-mail-account-forward', [$conf['user'], $domain_name, $account_name, ':blackhole:'], true);
                }
            } else {
                // Blackhole seçili değilse, varsa :blackhole: kaydını temizle
                if (in_array(':blackhole:', $currFwds)) {
                    call_hestia_api($pdo, 'v-delete-mail-account-forward', [$conf['user'], $domain_name, $account_name, ':blackhole:'], true);
                    $currFwds = array_values(array_diff($currFwds, [':blackhole:']));
                }

                $cleanedNewFwds = preg_replace('/[\r\n,]+/', ' ', $forward_input);
                $newFwds = array_values(array_filter(array_map('trim', explode(' ', $cleanedNewFwds))));
                $newFwds = array_values(array_unique(array_map('strtolower', $newFwds)));

                // Silinen forwarder'lar
                $delFwds = array_diff($currFwds, $newFwds);
                foreach ($delFwds as $dFwd) {
                    if (!empty($dFwd)) {
                        call_hestia_api($pdo, 'v-delete-mail-account-forward', [$conf['user'], $domain_name, $account_name, $dFwd], true);
                    }
                }
                // Eklenen forwarder'lar
                $addFwds = array_diff($newFwds, $currFwds);
                foreach ($addFwds as $aFwd) {
                    if (!empty($aFwd)) {
                        call_hestia_api($pdo, 'v-add-mail-account-forward', [$conf['user'], $domain_name, $account_name, $aFwd], true);
                    }
                }
            }

            // E. Yönlendirilmiş postaları depolama (Forward Only)
            $currFwdOnly = (strtolower($currData['FWD_ONLY'] ?? 'no') === 'yes');
            if ($forward_only && !$currFwdOnly) {
                call_hestia_api($pdo, 'v-add-mail-account-fwd-only', [$conf['user'], $domain_name, $account_name], true);
            } else if (!$forward_only && $currFwdOnly) {
                call_hestia_api($pdo, 'v-delete-mail-account-fwd-only', [$conf['user'], $domain_name, $account_name], true);
            }

            // F. Otomatik Cevap (Autoreply)
            $currAutoreply = (strtolower($currData['AUTOREPLY'] ?? 'no') === 'yes');
            if ($autoreply) {
                if (!empty($autoreply_message)) {
                    call_hestia_api($pdo, 'v-add-mail-account-autoreply', [$conf['user'], $domain_name, $account_name, $autoreply_message], true);
                }
            } else if ($currAutoreply) {
                call_hestia_api($pdo, 'v-delete-mail-account-autoreply', [$conf['user'], $domain_name, $account_name], true);
            }
        }

        $extraMsg = !empty($hestia_msgs) ? ' (' . implode(', ', $hestia_msgs) . ')' : '';
        echo json_encode([
            'success' => true,
            'message' => "Hesap ayarları ({$target_acc->email}) ve HestiaCP özellikleri başarıyla kaydedildi{$extraMsg}."
        ]);
        exit;

    case 'admin_delete_account':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_POST['id'] ?? 0);

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hesap ID.']);
            exit;
        }

        if ($target_id === (int)$current_admin->id) {
            echo json_encode(['success' => false, 'message' => 'Şu anda kullandığınız aktif hesabınızı silemezsiniz!']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        $target_acc = $stmt->fetch();
        if (!$target_acc) {
            echo json_encode(['success' => false, 'message' => 'Silinecek hesap bulunamadı.']);
            exit;
        }

        if (!empty($target_acc->is_admin)) {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM mail_accounts WHERE is_admin = 1")->fetchColumn();
            if ($adminCount <= 1) {
                echo json_encode(['success' => false, 'message' => 'Sistemdeki son yönetici hesabı silinemez.']);
                exit;
            }
        }

        // Optionally delete on HestiaCP mail server if requested
        $delete_server = !empty($_POST['delete_from_server']);
        $hestia_note = '';
        $conf = get_hestia_config($pdo);
        if ($delete_server && $conf['is_configured']) {
            $user_parts = explode('@', $target_acc->email);
            $callRes = call_hestia_api($pdo, 'v-delete-mail-account', [$conf['user'], $user_parts[1] ?? MAIL_DOMAIN, $user_parts[0]], true);
            if ($callRes['success']) {
                $hestia_note = ' (HestiaCP sunucusundan da kaldırıldı)';
            } else if (!empty($callRes['message'])) {
                $hestia_note = ' (HestiaCP: ' . trim($callRes['message']) . ')';
            }
        }

        // Clean up from database
        try {
            @$pdo->prepare("DELETE FROM mail_folders WHERE account_id = ?")->execute([$target_id]);
            @$pdo->prepare("DELETE FROM mail_imports WHERE account_id = ?")->execute([$target_id]);
            @$pdo->prepare("DELETE FROM mail_drafts WHERE account_id = ?")->execute([$target_id]);
            @$pdo->prepare("DELETE FROM mail_filters WHERE account_id = ?")->execute([$target_id]);
            @$pdo->prepare("DELETE FROM calendar_events WHERE account_id = ?")->execute([$target_id]);
            $pdo->prepare("DELETE FROM mail_accounts WHERE id = ?")->execute([$target_id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Silme hatası: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => "Hesap ({$target_acc->email}) başarıyla silindi{$hestia_note}."
        ]);
        exit;

    case 'admin_switch_account':
        $current_admin = require_admin($pdo);
        $target_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

        if ($target_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hesap.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, email FROM mail_accounts WHERE id = ?");
        $stmt->execute([$target_id]);
        $target_acc = $stmt->fetch();
        if (!$target_acc) {
            echo json_encode(['success' => false, 'message' => 'Hedef hesap bulunamadı.']);
            exit;
        }

        $_SESSION['active_account_id'] = (int)$target_acc->id;
        $_SESSION['mail_logged_in'] = true;
        $_SESSION['user_email'] = $target_acc->email;

        echo json_encode([
            'success' => true,
            'message' => "{$target_acc->email} hesabına geçiş yapıldı.",
            'account' => $target_acc
        ]);
        exit;

    case 'admin_get_hestia_config':
        require_admin($pdo);
        $conf = get_hestia_config($pdo);
        $key_id = '';
        $secret_key = '';
        if (!empty($conf['key'])) {
            if (strpos($conf['key'], ':') !== false) {
                list($kId, $kSec) = explode(':', $conf['key'], 2);
                $key_id = $kId;
                $secret_key = $kSec;
            } else {
                $secret_key = $conf['key'];
            }
        }
        $secret_masked = (strlen($secret_key) > 8) ? substr($secret_key, 0, 4) . '••••••••' . substr($secret_key, -4) : '••••••••';
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        echo json_encode([
            'success' => true,
            'config' => [
                'server_mode' => $conf['server_mode'] ?? 'remote',
                'host' => $conf['host'],
                'user' => $conf['user'],
                'key_id' => $key_id,
                'secret_key' => $secret_key,
                'has_key' => !empty($conf['key']),
                'masked_key' => $secret_masked,
                'client_ip' => $client_ip,
                'cli_path' => $conf['cli_path'] ?? '/usr/local/hestia/bin',
                'use_sudo' => $conf['use_sudo'] ? 1 : 0,
                'is_local_installed' => (bool)$conf['is_local_installed'],
                'is_linux' => (DIRECTORY_SEPARATOR !== '\\'),
                'is_configured' => $conf['is_configured']
            ]
        ]);
        exit;

    case 'admin_save_hestia_config':
        require_admin($pdo);
        $server_mode = trim($_POST['server_mode'] ?? 'remote');
        if (!in_array($server_mode, ['remote', 'local'])) {
            $server_mode = 'remote';
        }
        $host = trim($_POST['host'] ?? '');
        $user = trim($_POST['user'] ?? 'admin');
        $key  = trim($_POST['key'] ?? '');
        $key_id = trim($_POST['key_id'] ?? '');
        $secret_key = trim($_POST['secret_key'] ?? '');
        $cli_path = trim($_POST['cli_path'] ?? '/usr/local/hestia/bin');
        $use_sudo = isset($_POST['use_sudo']) ? (int)$_POST['use_sudo'] : 1;

        if ($server_mode === 'remote') {
            if (empty($host)) {
                echo json_encode(['success' => false, 'message' => 'Lütfen HestiaCP Sunucu URL adresini giriniz (Örn: https://mail.example.com:8083).']);
                exit;
            }

            // Normalize host
            $host = rtrim($host, '/');
            if (!preg_match('/^https?:\/\//i', $host)) {
                $host = 'https://' . $host;
            }

            // If key_id & secret_key provided, combine them as ID:SECRET
            if (!empty($key_id) && !empty($secret_key)) {
                $key = $key_id . ':' . $secret_key;
            } else if (!empty($secret_key)) {
                $key = $secret_key;
            } else if (empty($key)) {
                // Retain existing key if none provided
                $curr = get_hestia_config($pdo);
                $key = $curr['key'];
            }
        } else {
            // Local mode: host and key are optional, retain existing values if empty
            if (empty($cli_path)) {
                $cli_path = '/usr/local/hestia/bin';
            }
            $curr = get_hestia_config($pdo);
            if (empty($host)) $host = $curr['host'] ?: 'https://localhost:8083';
            if (!empty($key_id) && !empty($secret_key)) {
                $key = $key_id . ':' . $secret_key;
            } else if (!empty($secret_key)) {
                $key = $secret_key;
            } else {
                $key = $curr['key'];
            }
        }

        // Save into mail_settings
        $settingsToSave = [
            'hestia_server_mode' => $server_mode,
            'hestia_api_host'    => $host,
            'hestia_api_user'    => $user ?: 'admin',
            'hestia_api_key'     => $key,
            'hestia_cli_path'    => $cli_path,
            'hestia_use_sudo'    => (string)$use_sudo
        ];

        foreach ($settingsToSave as $sKey => $sVal) {
            $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$sKey, $sVal]);
        }

        // Test connection immediately
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';
        $rawTest = call_hestia_api($pdo, 'v-list-mail-accounts', [$user ?: 'admin', $domain, 'json'], false);
        if (!$rawTest['success'] || empty($rawTest['raw'])) {
            $rawTest = call_hestia_api($pdo, 'v-list-mail-accounts', [$domain, 'json'], false);
        }

        $testRes = @json_decode($rawTest['raw'] ?? '', true);
        $isConnected = is_array($testRes);
        $accCount = $isConnected ? count($testRes) : 0;

        if ($isConnected) {
            try {
                sync_hestia_accounts_to_db($pdo, $domain);
            } catch (Throwable $th) {}
            $modeTitle = ($server_mode === 'local') ? 'Aynı Sunucu (Yerel CLI Modu)' : 'Uzak Sunucu (REST API Modu)';
            $msg = "{$modeTitle} ayarları kaydedildi ve bağlantı doğrulandı! Sunucuda {$accCount} adet e-posta hesabı bulundu.";
        } else {
            $rawMsg = !empty($rawTest['message']) ? $rawTest['message'] : (!empty($rawTest['raw']) ? $rawTest['raw'] : '');
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($server_mode === 'local') {
                if (DIRECTORY_SEPARATOR === '\\') {
                    $msg = "Aynı sunucu (Yerel CLI) modu seçildi ancak şu an Windows (XAMPP) ortamındasınız. '/usr/local/hestia/bin/' dizini yalnızca projeniz canlı Linux HestiaCP sunucusuna taşındığında aktif olur. Şu anki testleriniz ve şifre senkronizasyonu için lütfen 'Uzak Sunucu (REST API)' modunu seçiniz.";
                } else {
                    $msg = "Aynı sunucu (Yerel CLI) ayarları kaydedildi. Yanıt: " . ($rawMsg ?: 'Komut yürütülemedi. Sunucunuzda /usr/local/hestia/bin izinlerini kontrol ediniz.');
                }
            } else if (stripos($rawMsg, 'IP is not allowed') !== false) {
                $msg = "HestiaCP API anahtarı doğru fakat sunucu IP kısıtlaması uyguluyor (Error: IP is not allowed to connect with API). Lütfen HestiaCP panelinde bu Access Key'in 'İzin Verilen IP Adresleri' kısmına webmail IP'nizi ({$clientIp}) veya '0.0.0.0/0' ekleyiniz.";
            } else {
                $msg = "HestiaCP API ayarları kaydedildi ancak sunucuya bağlanılamadı. Yanıt: " . ($rawMsg ?: 'Bilinmeyen hata');
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'connected' => $isConnected,
            'account_count' => $accCount,
            'server_mode' => $server_mode
        ]);
        exit;

    case 'admin_test_hestia_connection':
        require_admin($pdo);
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';
        $conf = get_hestia_config($pdo);
        if (!$conf['is_configured']) {
            echo json_encode(['success' => false, 'message' => 'HestiaCP ayarları henüz tanımlanmamış.']);
            exit;
        }

        $rawTest = call_hestia_api($pdo, 'v-list-mail-accounts', [$conf['user'] ?: 'admin', $domain, 'json'], false);
        if (!$rawTest['success'] || empty($rawTest['raw'])) {
            $rawTest = call_hestia_api($pdo, 'v-list-mail-accounts', [$domain, 'json'], false);
        }

        $testRes = @json_decode($rawTest['raw'] ?? '', true);
        if (is_array($testRes)) {
            $modeTitle = ($conf['server_mode'] === 'local') ? 'Yerel CLI' : 'REST API';
            echo json_encode([
                'success' => true,
                'message' => "HestiaCP ({$modeTitle}) bağlantısı başarılı! Sunucudaki @{$domain} alan adına ait " . count($testRes) . " adet e-posta kutusu başarıyla sorgulandı.",
                'accounts_count' => count($testRes),
                'server_mode' => $conf['server_mode']
            ]);
        } else {
            $rawMsg = !empty($rawTest['message']) ? $rawTest['message'] : (!empty($rawTest['raw']) ? $rawTest['raw'] : '');
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($conf['server_mode'] === 'local') {
                if (DIRECTORY_SEPARATOR === '\\') {
                    $rawMsg = "Webmail şu anda Windows (XAMPP) ortamında çalışmaktadır. '/usr/local/hestia/bin/' dizini yalnızca projeniz canlı Linux HestiaCP sunucusuna taşındığında aktif olur. Şu anki testleriniz ve şifre senkronizasyonu için lütfen 'Uzak Sunucu (REST API)' modunu seçiniz.";
                }
                echo json_encode([
                    'success' => false,
                    'message' => "Yerel CLI çalıştırma yanıtı: " . ($rawMsg ?: 'Komut yürütülemedi. Sunucuda /usr/local/hestia/bin izinlerini kontrol ediniz.')
                ]);
            } else if (stripos($rawMsg, 'IP is not allowed') !== false) {
                echo json_encode([
                    'success' => false,
                    'message' => "HestiaCP sunucusu yanıt verdi fakat IP kısıtlaması nedeniyle reddetti (Error: IP is not allowed to connect with API). Lütfen HestiaCP panelinde bu Access Key'in 'İzin Verilen IP Adresleri' alanına şu anki IP adresinizi ({$clientIp}) veya '0.0.0.0/0' ekleyiniz."
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "HestiaCP sunucusu yanıtı: " . ($rawMsg ?: 'Bilinmeyen bağlantı hatası')
                ]);
            }
        }
        exit;

    case 'admin_sync_hestia_accounts':
        require_admin($pdo);
        $domain = defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '';
        $syncRes = sync_hestia_accounts_to_db($pdo, $domain, true);
        if ($syncRes['success']) {
            echo json_encode([
                'success' => true,
                'message' => "HestiaCP senkronizasyonu tamamlandı. Sunucudan {$syncRes['synced_count']} hesap okundu, {$syncRes['added_count']} yeni hesap veritabanına eklendi.",
                'synced_count' => $syncRes['synced_count'],
                'added_count' => $syncRes['added_count']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $syncRes['message']]);
        }
        exit;

    case 'admin_get_branding':
        require_admin($pdo);
        $title = 'Hestia Mail';
        $subtitle = 'Web Suite';
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM mail_settings WHERE setting_key IN ('app_title', 'app_subtitle')");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($r['setting_key'] === 'app_title' && trim($r['setting_value']) !== '') {
                    $title = $r['setting_value'];
                }
                if ($r['setting_key'] === 'app_subtitle' && trim($r['setting_value']) !== '') {
                    $subtitle = $r['setting_value'];
                }
            }
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'app_title' => $title,
            'app_subtitle' => $subtitle,
            'domain' => defined('MAIL_DOMAIN') ? MAIL_DOMAIN : ''
        ]);
        exit;

    case 'admin_save_branding':
        require_admin($pdo);
        $title = trim($_POST['app_title'] ?? $_GET['app_title'] ?? '');
        $subtitle = trim($_POST['app_subtitle'] ?? $_GET['app_subtitle'] ?? '');

        if ($title === '') {
            $title = 'Hestia Mail';
        }
        if ($subtitle === '') {
            $subtitle = 'Web Suite';
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO mail_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['app_title', $title]);
            $stmt->execute(['app_subtitle', $subtitle]);

            echo json_encode([
                'success' => true,
                'message' => 'Uygulama başlığı ve marka isimleri başarıyla güncellendi.',
                'app_title' => $title,
                'app_subtitle' => $subtitle
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Ayarlar kaydedilemedi: ' . $e->getMessage()
            ]);
        }
        exit;

    // ==========================================
    // DOMAIN INTERNAL CHAT & MESSENGER API
    // ==========================================

    case 'chat_bootstrap':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        ensure_chat_tables($pdo, $myDomain);

        // Register / update current user presence
        try {
            $stmtP = $pdo->prepare("
                INSERT INTO chat_presence (email, domain, name, status, last_seen)
                VALUES (?, ?, ?, 'online', NOW())
                ON DUPLICATE KEY UPDATE domain = VALUES(domain), name = VALUES(name), last_seen = NOW()
            ");
            $stmtP->execute([$myEmail, $myDomain, $myName]);
        } catch (Exception $e) {}

        // Get all direct conversations for current user
        $conversations = [];
        try {
            $stmtC = $pdo->prepare("
                SELECT c.id, c.type, c.domain, c.title, c.created_by, c.last_message, c.last_message_sender, 
                       c.last_message_at, c.created_at, cp.unread_count, cp.last_read_message_id, cp.role
                FROM chat_conversations c
                JOIN chat_participants cp ON c.id = cp.conversation_id AND cp.email = ?
                WHERE c.type = 'direct'
                ORDER BY c.last_message_at DESC, c.id DESC
            ");
            $stmtC->execute([$myEmail]);
            $rawConvs = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rawConvs as $cv) {
                $cId = (int)$cv['id'];
                $cType = $cv['type'];

                // Get participants
                $stmtPart = $pdo->prepare("SELECT email, name, role FROM chat_participants WHERE conversation_id = ?");
                $stmtPart->execute([$cId]);
                $cParts = $stmtPart->fetchAll(PDO::FETCH_ASSOC);

                $otherEmail = '';
                $otherName = '';
                $otherStatus = 'offline';
                $otherCustomStatus = '';

                if ($cType === 'direct') {
                    foreach ($cParts as $pt) {
                        if (strtolower(trim($pt['email'])) !== $myEmail) {
                            $otherEmail = strtolower(trim($pt['email']));
                            $otherName = $pt['name'] ?: explode('@', $otherEmail)[0];
                            break;
                        }
                    }
                    if (!empty($otherEmail)) {
                        $stmtPresOther = $pdo->prepare("SELECT status, custom_status, last_seen, (CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END) as is_online FROM chat_presence WHERE email = ?");
                        $stmtPresOther->execute([$otherEmail]);
                        $oPres = $stmtPresOther->fetch(PDO::FETCH_ASSOC);
                        if ($oPres && (int)$oPres['is_online'] === 1 && in_array($oPres['status'], ['online', 'away', 'busy'])) {
                            $otherStatus = $oPres['status'];
                        }
                        $otherCustomStatus = $oPres['custom_status'] ?? '';
                    }
                }

                $conversations[] = [
                    'id' => $cId,
                    'type' => $cType,
                    'title' => ($cType === 'direct') ? ($otherName ?: $otherEmail) : ($cv['title'] ?: 'Grup Mesajlaşması'),
                    'other_email' => $otherEmail,
                    'other_name' => $otherName,
                    'other_status' => $otherStatus,
                    'other_custom_status' => $otherCustomStatus,
                    'last_message' => $cv['last_message'] ?? '',
                    'last_message_sender' => $cv['last_message_sender'] ?? '',
                    'last_message_at' => $cv['last_message_at'] ?? '',
                    'unread_count' => (int)($cv['unread_count'] ?? 0),
                    'member_count' => count($cParts),
                    'participants' => $cParts
                ];
            }
        } catch (Exception $e) {}

        // Get all colleagues in domain
        $colleagues = get_domain_colleagues($pdo, $myDomain, $myEmail);

        // Calculate total unread
        $totalUnread = 0;
        try {
            $stmtTot = $pdo->prepare("SELECT COALESCE(SUM(unread_count), 0) FROM chat_participants WHERE email = ?");
            $stmtTot->execute([$myEmail]);
            $totalUnread = (int)$stmtTot->fetchColumn();
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'my_profile' => [
                'email' => $myEmail,
                'name' => $myName,
                'domain' => $myDomain,
                'status' => $myPres['status'] ?? 'online',
                'custom_status' => $myPres['custom_status'] ?? '',
                'is_admin' => !empty($acc->is_admin) ? 1 : 0
            ],
            'domain' => $myDomain,
            'conversations' => $conversations,
            'colleagues' => $colleagues,
            'total_unread' => $totalUnread
        ]);
        exit;

    case 'chat_heartbeat':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        // Optimized: ensure_chat_tables is removed from heartbeat (guaranteed by chat_bootstrap)

        // Update presence (throttled to avoid heavy disk/row-lock writes on every tick)
        $statusUpdate = trim($_POST['status'] ?? $_GET['status'] ?? '');
        try {
            if (in_array($statusUpdate, ['online', 'away', 'busy', 'offline'])) {
                $stmtP = $pdo->prepare("UPDATE chat_presence SET status = ?, last_seen = NOW() WHERE email = ?");
                $stmtP->execute([$statusUpdate, $myEmail]);
            } else {
                // Throttle last_seen: only write if last_seen is older than 30 seconds
                $stmtP = $pdo->prepare("UPDATE chat_presence SET last_seen = NOW() WHERE email = ? AND (last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 30 SECOND))");
                $stmtP->execute([$myEmail]);
            }
        } catch (Exception $e) {}

        $activeConvId = (int)($_POST['active_conv_id'] ?? $_GET['active_conv_id'] ?? 0);
        $afterMsgId = (int)($_POST['after_msg_id'] ?? $_GET['after_msg_id'] ?? 0);
        $isChatActive = (int)($_POST['is_chat_active'] ?? $_GET['is_chat_active'] ?? 1);

        $newMessages = [];
        $typingUsers = [];

        if ($activeConvId > 0) {
            // Verify user is a participant in this conversation (Prevent IDOR)
            $isConvMember = false;
            try {
                $stmtChkP = $pdo->prepare("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND email = ?");
                $stmtChkP->execute([$activeConvId, $myEmail]);
                $isConvMember = ((int)$stmtChkP->fetchColumn() > 0);
            } catch (Exception $e) {}

            if ($isConvMember) {
                // Mark as read immediately
                try {
                    $updRead = $pdo->prepare("
                        UPDATE chat_participants 
                        SET unread_count = 0, 
                            last_read_message_id = (SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE conversation_id = ?) 
                        WHERE conversation_id = ? AND email = ?
                    ");
                    $updRead->execute([$activeConvId, $activeConvId, $myEmail]);
                } catch (Exception $e) {}

                // Fetch any new messages in active conv
                if ($afterMsgId > 0) {
                    try {
                        $stmtM = $pdo->prepare("
                            SELECT id, conversation_id, sender_email, sender_name, message, attachment_url, 
                                   attachment_name, attachment_size, attachment_type, is_deleted, reactions, created_at
                            FROM chat_messages
                            WHERE conversation_id = ? AND id > ? AND (is_deleted = 0 OR is_deleted IS NULL)
                            ORDER BY id ASC
                        ");
                        $stmtM->execute([$activeConvId, $afterMsgId]);
                        while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
                            $m['is_me'] = (strtolower(trim($m['sender_email'])) === $myEmail);
                            $m['reactions'] = $m['reactions'] ? json_decode($m['reactions'], true) : [];
                            $newMessages[] = $m;
                        }
                    } catch (Exception $e) {}
                }

                // Check typing in active conv
                try {
                    $stmtT = $pdo->prepare("
                        SELECT name, email FROM chat_presence 
                        WHERE typing_in = ? AND email != ? AND typing_at >= DATE_SUB(NOW(), INTERVAL 6 SECOND)
                    ");
                    $stmtT->execute([$activeConvId, $myEmail]);
                    $typingUsers = $stmtT->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }
        }

        // Check unread counts
        $unreadMap = [];
        $totalUnread = 0;
        try {
            $stmtU = $pdo->prepare("SELECT conversation_id, unread_count FROM chat_participants WHERE email = ?");
            $stmtU->execute([$myEmail]);
            while ($row = $stmtU->fetch(PDO::FETCH_ASSOC)) {
                $cId = (int)$row['conversation_id'];
                $uCount = (int)$row['unread_count'];
                if ($uCount > 0) {
                    $unreadMap[$cId] = $uCount;
                    $totalUnread += $uCount;
                }
            }
        } catch (Exception $e) {}

        // Fetch latest incoming unread message preview for notifications
        $latestIncoming = null;
        if ($totalUnread > 0) {
            try {
                $stmtInc = $pdo->prepare("
                    SELECT m.id, m.conversation_id, m.sender_email, m.sender_name, m.message, m.attachment_name, m.created_at
                    FROM chat_messages m
                    JOIN chat_participants p ON m.conversation_id = p.conversation_id AND p.email = ?
                    WHERE m.sender_email != ? AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
                    ORDER BY m.id DESC LIMIT 1
                ");
                $stmtInc->execute([$myEmail, $myEmail]);
                $latestIncoming = $stmtInc->fetch(PDO::FETCH_ASSOC);
                if ($latestIncoming) {
                    $latestIncoming['id'] = (int)$latestIncoming['id'];
                    $latestIncoming['conversation_id'] = (int)$latestIncoming['conversation_id'];
                }
            } catch (Exception $e) {}
        }

        // Active users in domain - only query if chat is active on screen to save CPU
        $onlineUsers = [];
        if ($isChatActive === 1) {
            try {
                $stmtOn = $pdo->prepare("
                    SELECT email, status, custom_status, last_seen,
                           (CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END) as is_online
                    FROM chat_presence WHERE domain = ?
                ");
                $stmtOn->execute([$myDomain]);
                while ($on = $stmtOn->fetch(PDO::FETCH_ASSOC)) {
                    $emKey = strtolower(trim($on['email']));
                    $onlineUsers[$emKey] = [
                        'status' => ((int)$on['is_online'] === 1 && in_array($on['status'], ['online', 'away', 'busy'])) ? $on['status'] : 'offline',
                        'custom_status' => $on['custom_status'] ?? '',
                        'last_seen' => $on['last_seen']
                    ];
                }
            } catch (Exception $e) {}
        }

        // Fetch active conversations summary for real-time sidebar syncing (only if chat active or new unread)
        $latestConvs = [];
        if ($isChatActive === 1 || $totalUnread > 0) {
            try {
                $stmtC = $pdo->prepare("
                    SELECT c.id, c.type, c.title, c.created_by, c.last_message, c.last_message_sender, 
                           c.last_message_at, c.created_at, p.unread_count, p.last_read_message_id
                    FROM chat_conversations c
                    JOIN chat_participants p ON c.id = p.conversation_id AND p.email = ?
                    WHERE c.domain = ? AND c.type = 'direct'
                    ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
                ");
                $stmtC->execute([$myEmail, $myDomain]);
                while ($cRow = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                    $cId = (int)$cRow['id'];
                    $cRow['id'] = $cId;
                    $cRow['unread_count'] = (int)($cRow['unread_count'] ?? 0);
                    if ($cRow['type'] === 'direct') {
                        $stmtO = $pdo->prepare("SELECT email, name FROM chat_participants WHERE conversation_id = ? AND email != ? LIMIT 1");
                        $stmtO->execute([$cId, $myEmail]);
                        $oth = $stmtO->fetch(PDO::FETCH_ASSOC);
                        $othEmail = strtolower(trim($oth['email'] ?? ''));
                        $cRow['target_email'] = $othEmail;
                        $cRow['other_email'] = $othEmail;

                        $othName = $oth['name'] ?? '';
                        if (empty($othName) && !empty($othEmail)) {
                            $stmtTN = $pdo->prepare("SELECT name FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
                            $stmtTN->execute([$othEmail]);
                            $othName = $stmtTN->fetchColumn() ?: explode('@', $othEmail)[0];
                        }
                        $cRow['other_name'] = $othName ?: 'Kullanıcı';
                        $cRow['title'] = $cRow['other_name'];
                    }
                    $latestConvs[] = $cRow;
                }
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success' => true,
            'new_messages' => $newMessages,
            'typing' => $typingUsers,
            'unread_map' => $unreadMap,
            'total_unread' => $totalUnread,
            'latest_incoming' => $latestIncoming,
            'presence_map' => $onlineUsers,
            'conversations' => $latestConvs
        ]);
        exit;

    case 'chat_get_messages':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        ensure_chat_tables($pdo, $myDomain);

        $convId = (int)($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
        $targetEmail = strtolower(trim($_GET['target_email'] ?? $_POST['target_email'] ?? ''));
        $beforeId = (int)($_GET['before_id'] ?? $_POST['before_id'] ?? 0);
        $limit = 50;

        // Fetch conversation metadata first
        $conv = null;
        if ($convId > 0) {
            $stmtC = $pdo->prepare("SELECT * FROM chat_conversations WHERE id = ?");
            $stmtC->execute([$convId]);
            $conv = $stmtC->fetch(PDO::FETCH_ASSOC);
        }

        // If conversation was deleted by the other party, auto-recover or create if targetEmail is given
        if (!$conv && !empty($targetEmail)) {
            $stmtFind = $pdo->prepare("
                SELECT c.id FROM chat_conversations c
                JOIN chat_participants p1 ON c.id = p1.conversation_id AND p1.email = ?
                JOIN chat_participants p2 ON c.id = p2.conversation_id AND p2.email = ?
                WHERE c.type = 'direct' LIMIT 1
            ");
            $stmtFind->execute([$myEmail, $targetEmail]);
            $foundId = (int)$stmtFind->fetchColumn();

            if ($foundId > 0) {
                $convId = $foundId;
            } else {
                $stmtInsC = $pdo->prepare("
                    INSERT INTO chat_conversations (type, domain, created_by, last_message_at, created_at)
                    VALUES ('direct', ?, ?, NOW(), NOW())
                ");
                $stmtInsC->execute([$myDomain, $myEmail]);
                $convId = (int)$pdo->lastInsertId();

                $stmtTargetName = $pdo->prepare("SELECT name FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
                $stmtTargetName->execute([$targetEmail]);
                $targetName = $stmtTargetName->fetchColumn() ?: explode('@', $targetEmail)[0];

                $stmtInsP = $pdo->prepare("INSERT INTO chat_participants (conversation_id, email, name, role) VALUES (?, ?, ?, 'member')");
                $stmtInsP->execute([$convId, $myEmail, $myName]);
                $stmtInsP->execute([$convId, $targetEmail, $targetName]);
            }

            $stmtC = $pdo->prepare("SELECT * FROM chat_conversations WHERE id = ?");
            $stmtC->execute([$convId]);
            $conv = $stmtC->fetch(PDO::FETCH_ASSOC);
        }

        if (!$conv) {
            echo json_encode(['success' => false, 'message' => 'Mesajlaşma veya kanal bulunamadı.', 'not_found' => true]);
            exit;
        }
        $conv['id'] = (int)$conv['id'];

        // Enrich metadata for direct conversations
        if ($conv['type'] === 'direct') {
            $stmtOther = $pdo->prepare("SELECT email, name FROM chat_participants WHERE conversation_id = ? AND email != ? LIMIT 1");
            $stmtOther->execute([$convId, $myEmail]);
            $oth = $stmtOther->fetch(PDO::FETCH_ASSOC);
            $othEmail = strtolower(trim($oth['email'] ?? $targetEmail));
            $conv['target_email'] = $othEmail;
            $conv['other_email'] = $othEmail;

            $othName = $oth['name'] ?? '';
            if (empty($othName) && !empty($othEmail)) {
                $stmtTN = $pdo->prepare("SELECT name FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
                $stmtTN->execute([$othEmail]);
                $othName = $stmtTN->fetchColumn() ?: explode('@', $othEmail)[0];
            }
            $conv['other_name'] = $othName ?: 'Kullanıcı';
            $conv['title'] = $conv['other_name'];
        }

        // Check if user is participant; if direct, only allow if capacity < 2 or already a party
        $stmtChk = $pdo->prepare("SELECT id FROM chat_participants WHERE conversation_id = ? AND email = ?");
        $stmtChk->execute([$convId, $myEmail]);
        if (!$stmtChk->fetchColumn()) {
            if ($conv['type'] === 'direct') {
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ?");
                $stmtCount->execute([$convId]);
                if ((int)$stmtCount->fetchColumn() < 2) {
                    $pdo->prepare("INSERT INTO chat_participants (conversation_id, email, name, role) VALUES (?, ?, ?, 'member')")->execute([$convId, $myEmail, $myName]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Bu özel mesaja erişim yetkiniz yok.']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Grup veya kanal özelliği devre dışı bırakılmıştır.']);
                exit;
            }
        }

        // Mark as read
        $pdo->prepare("
            UPDATE chat_participants 
            SET unread_count = 0, 
                last_read_message_id = (SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE conversation_id = ?) 
            WHERE conversation_id = ? AND email = ?
        ")->execute([$convId, $convId, $myEmail]);

        // Fetch participants
        $stmtP = $pdo->prepare("SELECT p.email, p.name, p.role, pr.status, pr.custom_status, pr.last_seen, (CASE WHEN pr.last_seen >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END) as is_online FROM chat_participants p LEFT JOIN chat_presence pr ON p.email = pr.email WHERE p.conversation_id = ?");
        $stmtP->execute([$convId]);
        $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // Fetch messages
        $sql = "SELECT id, conversation_id, sender_email, sender_name, message, attachment_url, 
                       attachment_name, attachment_size, attachment_type, is_deleted, reactions, created_at
                FROM chat_messages
                WHERE conversation_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)";
        $params = [$convId];
        if ($beforeId > 0) {
            $sql .= " AND id < ?";
            $params[] = $beforeId;
        }
        $sql .= " ORDER BY id DESC LIMIT " . $limit;

        $stmtM = $pdo->prepare($sql);
        $stmtM->execute($params);
        $messages = [];
        while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
            $m['is_me'] = (strtolower(trim($m['sender_email'])) === $myEmail);
            $m['reactions'] = $m['reactions'] ? json_decode($m['reactions'], true) : [];
            $messages[] = $m;
        }
        $messages = array_reverse($messages);

        echo json_encode([
            'success' => true,
            'conversation' => $conv,
            'participants' => $participants,
            'messages' => $messages
        ]);
        exit;

    case 'chat_send_message':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        ensure_chat_tables($pdo, $myDomain);

        $convId = (int)($_POST['conversation_id'] ?? 0);
        $targetEmail = strtolower(trim($_POST['target_email'] ?? ''));
        $message = trim($_POST['message'] ?? '');
        $attachUrl = trim($_POST['attachment_url'] ?? '');
        $attachName = trim($_POST['attachment_name'] ?? '');
        $attachSize = (int)($_POST['attachment_size'] ?? 0);
        $attachType = trim($_POST['attachment_type'] ?? '');

        if ($convId <= 0 && empty($targetEmail)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz mesajlaşma']);
            exit;
        }

        if (empty($message) && empty($attachUrl)) {
            echo json_encode(['success' => false, 'message' => 'Mesaj veya dosya içeriği boş olamaz']);
            exit;
        }

        // Fetch conversation metadata
        $conv = null;
        if ($convId > 0) {
            $stmtC = $pdo->prepare("SELECT id, type, title FROM chat_conversations WHERE id = ?");
            $stmtC->execute([$convId]);
            $conv = $stmtC->fetch(PDO::FETCH_ASSOC);
        }

        // If conversation was deleted by the other party, auto-recover or create direct conversation
        if (!$conv) {
            if (!empty($targetEmail)) {
                $stmtFind = $pdo->prepare("
                    SELECT c.id FROM chat_conversations c
                    JOIN chat_participants p1 ON c.id = p1.conversation_id AND p1.email = ?
                    JOIN chat_participants p2 ON c.id = p2.conversation_id AND p2.email = ?
                    WHERE c.type = 'direct' LIMIT 1
                ");
                $stmtFind->execute([$myEmail, $targetEmail]);
                $reusedId = (int)$stmtFind->fetchColumn();

                if ($reusedId > 0) {
                    $convId = $reusedId;
                } else {
                    $stmtInsC = $pdo->prepare("
                        INSERT INTO chat_conversations (type, domain, created_by, last_message_at, created_at)
                        VALUES ('direct', ?, ?, NOW(), NOW())
                    ");
                    $stmtInsC->execute([$myDomain, $myEmail]);
                    $convId = (int)$pdo->lastInsertId();

                    $stmtTargetName = $pdo->prepare("SELECT name FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
                    $stmtTargetName->execute([$targetEmail]);
                    $targetName = $stmtTargetName->fetchColumn() ?: explode('@', $targetEmail)[0];

                    $stmtInsP = $pdo->prepare("INSERT INTO chat_participants (conversation_id, email, name, role) VALUES (?, ?, ?, 'member')");
                    $stmtInsP->execute([$convId, $myEmail, $myName]);
                    $stmtInsP->execute([$convId, $targetEmail, $targetName]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Mesajlaşma bulunamadı. Lütfen listeden kişiyi tekrar seçin.']);
                exit;
            }
        } else {
            // Conversation exists in DB. Check participation
            $stmtChk = $pdo->prepare("SELECT id FROM chat_participants WHERE conversation_id = ? AND email = ?");
            $stmtChk->execute([$convId, $myEmail]);
            if (!$stmtChk->fetchColumn()) {
                if ($conv['type'] === 'direct') {
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ?");
                    $stmtCount->execute([$convId]);
                    if ((int)$stmtCount->fetchColumn() < 2) {
                        $pdo->prepare("INSERT INTO chat_participants (conversation_id, email, name, role) VALUES (?, ?, ?, 'member')")->execute([$convId, $myEmail, $myName]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Bu mesajlaşmaya ileti gönderme yetkiniz yok.']);
                        exit;
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Grup veya kanal özelliği devre dışı bırakılmıştır.']);
                    exit;
                }
            }
        }

        // Insert message
        $stmtIns = $pdo->prepare("
            INSERT INTO chat_messages (conversation_id, sender_email, sender_name, message, 
                                      attachment_url, attachment_name, attachment_size, attachment_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtIns->execute([$convId, $myEmail, $myName, $message, $attachUrl ?: null, $attachName ?: null, $attachSize ?: null, $attachType ?: null]);
        $msgId = (int)$pdo->lastInsertId();

        $snippet = !empty($message) ? (mb_strlen($message) > 60 ? mb_substr($message, 0, 57) . '...' : $message) : ($attachName ? '📎 ' . $attachName : 'Dosya gönderildi');

        // Update conversation
        $pdo->prepare("
            UPDATE chat_conversations 
            SET last_message = ?, last_message_sender = ?, last_message_at = NOW() 
            WHERE id = ?
        ")->execute([$snippet, $myName, $convId]);

        // Increment unread count for other participants
        $pdo->prepare("
            UPDATE chat_participants 
            SET unread_count = unread_count + 1 
            WHERE conversation_id = ? AND email != ?
        ")->execute([$convId, $myEmail]);

        // Sender's unread count stays 0 and last_read_message_id is updated
        $pdo->prepare("
            UPDATE chat_participants 
            SET unread_count = 0, last_read_message_id = ? 
            WHERE conversation_id = ? AND email = ?
        ")->execute([$msgId, $convId, $myEmail]);

        // Reset typing
        $pdo->prepare("UPDATE chat_presence SET typing_in = 0, typing_at = NULL WHERE email = ?")->execute([$myEmail]);

        // Fetch created message
        $stmtNew = $pdo->prepare("SELECT * FROM chat_messages WHERE id = ?");
        $stmtNew->execute([$msgId]);
        $createdMsg = $stmtNew->fetch(PDO::FETCH_ASSOC);
        $createdMsg['is_me'] = true;
        $createdMsg['reactions'] = [];

        echo json_encode([
            'success' => true,
            'conversation_id' => $convId,
            'message' => $createdMsg
        ]);
        exit;

    case 'chat_start_direct':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        $targetEmail = strtolower(trim($_POST['target_email'] ?? ''));

        if (empty($targetEmail) || $targetEmail === $myEmail) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz hedef kullanıcı']);
            exit;
        }

        ensure_chat_tables($pdo, $myDomain);

        // Look for existing direct conversation
        $stmtChk = $pdo->prepare("
            SELECT c.id, c.last_message, c.last_message_at, c.created_at FROM chat_conversations c
            JOIN chat_participants p1 ON c.id = p1.conversation_id AND p1.email = ?
            JOIN chat_participants p2 ON c.id = p2.conversation_id AND p2.email = ?
            WHERE c.type = 'direct' LIMIT 1
        ");
        $stmtChk->execute([$myEmail, $targetEmail]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);

        // Get target user name
        $targetName = explode('@', $targetEmail)[0];
        $stmtN = $pdo->prepare("SELECT name FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
        $stmtN->execute([$targetEmail]);
        $fName = $stmtN->fetchColumn();
        if ($fName) {
            $targetName = $fName;
        } else {
            $stmtD = $pdo->prepare("SELECT name FROM domain_directory WHERE LOWER(email) = ? LIMIT 1");
            $stmtD->execute([$targetEmail]);
            $dName = $stmtD->fetchColumn();
            if ($dName) $targetName = $dName;
        }

        if ($existing) {
            $existingId = (int)$existing['id'];
            $convData = [
                'id' => $existingId,
                'type' => 'direct',
                'title' => $targetName,
                'target_email' => $targetEmail,
                'other_email' => $targetEmail,
                'other_name' => $targetName,
                'last_message' => $existing['last_message'] ?? '',
                'last_message_at' => $existing['last_message_at'] ?? $existing['created_at'],
                'unread_count' => 0
            ];
            echo json_encode(['success' => true, 'conversation_id' => $existingId, 'conversation' => $convData]);
            exit;
        }

        // Create new direct conversation
        $pdo->beginTransaction();
        try {
            $stmtInsC = $pdo->prepare("
                INSERT INTO chat_conversations (type, domain, created_by, last_message_at, created_at)
                VALUES ('direct', ?, ?, NOW(), NOW())
            ");
            $stmtInsC->execute([$myDomain, $myEmail]);
            $newConvId = (int)$pdo->lastInsertId();

            $stmtInsP = $pdo->prepare("INSERT INTO chat_participants (conversation_id, email, name, role) VALUES (?, ?, ?, 'member')");
            $stmtInsP->execute([$newConvId, $myEmail, $myName]);
            $stmtInsP->execute([$newConvId, $targetEmail, $targetName]);

            $pdo->commit();
            $convData = [
                'id' => $newConvId,
                'type' => 'direct',
                'title' => $targetName,
                'target_email' => $targetEmail,
                'other_email' => $targetEmail,
                'other_name' => $targetName,
                'last_message' => '',
                'last_message_at' => date('Y-m-d H:i:s'),
                'unread_count' => 0
            ];
            echo json_encode(['success' => true, 'conversation_id' => $newConvId, 'conversation' => $convData]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Mesajlaşma başlatılamadı: ' . $e->getMessage()]);
            exit;
        }

    case 'chat_create_group':
    case 'chat_remove_participant':
    case 'chat_add_participant':
        echo json_encode(['success' => false, 'message' => 'Grup ve kanal özellikleri devre dışıdır.']);
        exit;

    case 'chat_delete_conversation':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $convId = (int)($_POST['conversation_id'] ?? 0);

        if ($convId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz mesajlaşma ID']);
            exit;
        }

        $stmtC = $pdo->prepare("SELECT * FROM chat_conversations WHERE id = ?");
        $stmtC->execute([$convId]);
        $conv = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            echo json_encode(['success' => false, 'message' => 'Mesajlaşma bulunamadı']);
            exit;
        }

        // Direkt 1-e-1 mesajlaşma ise katılımcı veya yönetici silebilir
        $stmtP = $pdo->prepare("SELECT role FROM chat_participants WHERE conversation_id = ? AND email = ?");
        $stmtP->execute([$convId, $myEmail]);
        $myRole = $stmtP->fetchColumn();

        if ($myRole === false && empty($acc->is_admin)) {
            echo json_encode(['success' => false, 'message' => 'Bu mesajlaşmayı silme yetkiniz bulunmuyor.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM chat_messages WHERE conversation_id = ?")->execute([$convId]);
            $pdo->prepare("DELETE FROM chat_participants WHERE conversation_id = ?")->execute([$convId]);
            $pdo->prepare("DELETE FROM chat_conversations WHERE id = ?")->execute([$convId]);
            $pdo->commit();

            echo json_encode([
                'success' => true, 
                'message' => 'Mesajlaşma silindi.', 
                'deleted_id' => $convId,
                'is_direct' => true
            ]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız: ' . $e->getMessage()]);
            exit;
        }

    case 'chat_set_status':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $myName = trim($acc->name ?: explode('@', $myEmail)[0]);
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        $status = trim($_POST['status'] ?? 'online');
        if (!in_array($status, ['online', 'away', 'busy', 'offline'])) {
            $status = 'online';
        }
        $customStatus = trim($_POST['custom_status'] ?? '');

        ensure_chat_tables($pdo, $myDomain);

        $stmt = $pdo->prepare("
            INSERT INTO chat_presence (email, domain, name, status, custom_status, last_seen)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), custom_status = VALUES(custom_status), last_seen = NOW()
        ");
        $stmt->execute([$myEmail, $myDomain, $myName, $status, $customStatus]);

        echo json_encode(['success' => true, 'status' => $status, 'custom_status' => $customStatus]);
        exit;

    case 'chat_set_typing':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $convId = (int)($_POST['conversation_id'] ?? 0);
        $isTyping = (int)($_POST['is_typing'] ?? 0);

        if ($isTyping && $convId > 0) {
            $pdo->prepare("UPDATE chat_presence SET typing_in = ?, typing_at = NOW() WHERE email = ?")->execute([$convId, $myEmail]);
        } else {
            $pdo->prepare("UPDATE chat_presence SET typing_in = 0, typing_at = NULL WHERE email = ?")->execute([$myEmail]);
        }
        echo json_encode(['success' => true]);
        exit;

    case 'chat_toggle_reaction':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $msgId = (int)($_POST['message_id'] ?? 0);
        $reaction = trim($_POST['reaction'] ?? '');

        if ($msgId <= 0 || empty($reaction)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz parametreler']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT conversation_id, reactions FROM chat_messages WHERE id = ?");
        $stmt->execute([$msgId]);
        $msgRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msgRow) {
            echo json_encode(['success' => false, 'message' => 'Mesaj bulunamadı']);
            exit;
        }
        $stmtChkP = $pdo->prepare("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND email = ?");
        $stmtChkP->execute([(int)$msgRow['conversation_id'], $myEmail]);
        if ((int)$stmtChkP->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'Bu sohbete erişim yetkiniz yok']);
            exit;
        }
        $raw = $msgRow['reactions'];
        $reactions = $raw ? json_decode($raw, true) : [];
        if (!is_array($reactions)) $reactions = [];

        if (!isset($reactions[$reaction])) {
            $reactions[$reaction] = [];
        }

        $idx = array_search($myEmail, $reactions[$reaction]);
        if ($idx !== false) {
            array_splice($reactions[$reaction], $idx, 1);
            if (empty($reactions[$reaction])) {
                unset($reactions[$reaction]);
            }
        } else {
            $reactions[$reaction][] = $myEmail;
        }

        $newJson = !empty($reactions) ? json_encode($reactions, JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare("UPDATE chat_messages SET reactions = ? WHERE id = ?")->execute([$newJson, $msgId]);

        echo json_encode(['success' => true, 'reactions' => $reactions]);
        exit;

    case 'chat_delete_message':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $isAdmin = !empty($acc->is_admin);
        $msgId = (int)($_POST['message_id'] ?? 0);

        if ($msgId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz mesaj ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT sender_email, conversation_id FROM chat_messages WHERE id = ?");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg) {
            echo json_encode(['success' => false, 'message' => 'Mesaj bulunamadı']);
            exit;
        }

        $isDirectParticipant = false;
        if (!empty($msg['conversation_id'])) {
            $stmtC = $pdo->prepare("SELECT type FROM chat_conversations WHERE id = ?");
            $stmtC->execute([$msg['conversation_id']]);
            $convType = $stmtC->fetchColumn();
            if ($convType === 'direct') {
                $stmtP = $pdo->prepare("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND email = ?");
                $stmtP->execute([$msg['conversation_id'], $myEmail]);
                if ((int)$stmtP->fetchColumn() > 0) {
                    $isDirectParticipant = true;
                }
            }
        }

        if (strtolower(trim($msg['sender_email'])) !== $myEmail && !$isAdmin && !$isDirectParticipant) {
            echo json_encode(['success' => false, 'message' => 'Bu mesajı silme yetkiniz yok']);
            exit;
        }

        $pdo->prepare("UPDATE chat_messages SET is_deleted = 1 WHERE id = ?")->execute([$msgId]);

        echo json_encode(['success' => true]);
        exit;

    case 'chat_upload_attachment':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/chat';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $file = $_FILES['file'];
        $maxSize = 25 * 1024 * 1024; // 25 MB
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'Dosya boyutu çok büyük (Maksimum 25 MB)']);
            exit;
        }

        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $disallowed = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'pht', 'pgif',
            'shtml', 'htaccess', 'html', 'htm', 'svg', 'js', 'jsp', 'asp', 'aspx', 'py',
            'cgi', 'pl', 'exe', 'bat', 'sh', 'cmd', 'vbs', 'scr', 'com', 'jar'
        ];
        if (in_array($ext, $disallowed) || empty($ext)) {
            echo json_encode(['success' => false, 'message' => 'Bu dosya türünün yüklenmesine güvenlik nedeniyle izin verilmiyor.']);
            exit;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $storedName = time() . '_' . substr(md5(uniqid(rand(), true)), 0, 8) . '_' . $safeName . '.' . $ext;
        $targetPath = $uploadDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => false, 'message' => 'Dosya sunucuya kaydedilemedi']);
            exit;
        }

        $mime = mime_content_type($targetPath) ?: 'application/octet-stream';
        $relUrl = 'uploads/chat/' . $storedName;

        echo json_encode([
            'success' => true,
            'url' => $relUrl,
            'name' => $origName,
            'size' => (int)$file['size'],
            'type' => $mime,
            'is_image' => strpos($mime, 'image/') === 0
        ]);
        exit;

    case 'chat_get_colleagues':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        ensure_chat_tables($pdo, $myDomain);
        $colleagues = get_domain_colleagues($pdo, $myDomain, $myEmail);
        echo json_encode(['success' => true, 'colleagues' => $colleagues]);
        exit;

    case 'chat_hide_colleague':
        $acc = get_active_account($pdo);
        if (!$acc || empty($acc->is_admin)) {
            echo json_encode(['status' => 'error', 'message' => 'Bu işlem için yönetici yetkisi gereklidir.']);
            exit;
        }
        $targetEmail = strtolower(trim($_POST['email'] ?? ''));
        if (empty($targetEmail)) {
            echo json_encode(['status' => 'error', 'message' => 'Geçersiz e-posta adresi']);
            exit;
        }
        $parts = explode('@', $acc->email);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        ensure_chat_tables($pdo, $myDomain);

        try {
            $ins = $pdo->prepare("REPLACE INTO chat_hidden_colleagues (domain, email, hidden_by, created_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$myDomain, $targetEmail, $acc->email]);
            echo json_encode([
                'status' => 'success',
                'message' => 'Kullanıcı mesajlaşma listesinden kaldırıldı.',
                'email' => $targetEmail
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
        exit;

    case 'chat_unhide_colleague':
        $acc = get_active_account($pdo);
        if (!$acc || empty($acc->is_admin)) {
            echo json_encode(['status' => 'error', 'message' => 'Bu işlem için yönetici yetkisi gereklidir.']);
            exit;
        }
        $targetEmail = strtolower(trim($_POST['email'] ?? ''));
        if (empty($targetEmail)) {
            echo json_encode(['status' => 'error', 'message' => 'Geçersiz e-posta adresi']);
            exit;
        }
        $parts = explode('@', $acc->email);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');

        try {
            $del = $pdo->prepare("DELETE FROM chat_hidden_colleagues WHERE domain = ? AND LOWER(email) = ?");
            $del->execute([$myDomain, $targetEmail]);
            echo json_encode([
                'status' => 'success',
                'message' => 'Kullanıcı başarıyla listeye geri eklendi.',
                'email' => $targetEmail
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
        exit;

    case 'chat_verify_pin':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        ensure_chat_tables($pdo, $myDomain);

        $pin = trim($_POST['pin'] ?? '');
        if ($pin === '') {
            echo json_encode(['success' => false, 'message' => 'Lütfen PIN kodunuzu girin.']);
            exit;
        }
        check_rate_limit('chat_pin_' . $acc->id, 8, 300);

        // Get stored PIN for this user
        $settingKey = 'chat_pin_' . $myEmail;
        $stmt = $pdo->prepare("SELECT setting_val FROM chat_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$settingKey]);
        $storedPin = $stmt->fetchColumn();

        // Default PIN is 1234 if never set
        if ($storedPin === false || $storedPin === null || $storedPin === '') {
            $storedPin = '1234';
        }

        // Strictly verify PIN - explicitly reject account login password
        if ($pin === (string)$storedPin) {
            clear_rate_limit('chat_pin_' . $acc->id);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hatalı PIN kodu. (Varsayılan PIN: 1234)']);
        }
        exit;

    case 'chat_set_pin':
        $acc = get_active_account($pdo);
        if (!$acc) {
            echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
            exit;
        }
        $myEmail = strtolower(trim($acc->email));
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        ensure_chat_tables($pdo, $myDomain);

        $currentPin = trim($_POST['current_pin'] ?? '');
        $newPin = trim($_POST['new_pin'] ?? '');

        if ($currentPin === '') {
            echo json_encode(['success' => false, 'message' => 'Mevcut PIN kodunuzu girin.']);
            exit;
        }

        if (!preg_match('/^[0-9]{4}$/', $newPin)) {
            echo json_encode(['success' => false, 'message' => 'Yeni PIN kodu 4 haneli rakam olmalıdır.']);
            exit;
        }

        // Check current PIN
        $settingKey = 'chat_pin_' . $myEmail;
        $stmt = $pdo->prepare("SELECT setting_val FROM chat_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$settingKey]);
        $storedPin = $stmt->fetchColumn();

        if ($storedPin === false || $storedPin === null || $storedPin === '') {
            $storedPin = '1234';
        }

        if ($currentPin !== (string)$storedPin) {
            echo json_encode(['success' => false, 'message' => 'Mevcut PIN kodu hatalı.']);
            exit;
        }

        // Update to new PIN
        $stmt = $pdo->prepare("REPLACE INTO chat_settings (setting_key, setting_val) VALUES (?, ?)");
        $stmt->execute([$settingKey, $newPin]);

        echo json_encode(['success' => true, 'message' => 'Sohbet PIN kodunuz başarıyla güncellendi.']);
        exit;

    case 'chat_admin_get_conversations':
        $acc = get_active_account($pdo);
        if (!$acc || empty($acc->is_admin)) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim. Sadece sistem yöneticileri görüntüleyebilir.']);
            exit;
        }

        $myEmail = strtolower(trim($acc->email));
        $parts = explode('@', $myEmail);
        $myDomain = $parts[1] ?? (defined('MAIL_DOMAIN') ? MAIL_DOMAIN : '');
        ensure_chat_tables($pdo, $myDomain);

        try {
            // Fetch all conversations in domain (or all if domain is empty)
            $sql = "
                SELECT c.id, c.type, c.domain, c.title, c.created_by, c.last_message, c.last_message_sender, 
                       c.last_message_at, c.created_at,
                       (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND (m.is_deleted = 0 OR m.is_deleted IS NULL)) as total_messages
                FROM chat_conversations c
                WHERE (c.domain = ? OR ? = '' OR c.domain = '')
                ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$myDomain, $myDomain]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $conversations = [];
            // Pre-fetch account names map in PHP safely to avoid any MySQL cross-table collation mismatch
            $accountNamesMap = [];
            try {
                $stmtAccs = $pdo->query("SELECT email, name FROM mail_accounts");
                while ($accRow = $stmtAccs->fetch(PDO::FETCH_ASSOC)) {
                    $em = strtolower(trim($accRow['email'] ?? ''));
                    if ($em !== '') {
                        $accountNamesMap[$em] = trim($accRow['name'] ?? '');
                    }
                }
            } catch (Exception $e) {}

            foreach ($rows as $r) {
                $convId = (int)$r['id'];

                // Fetch participants (no cross-table JOIN)
                $stmtP = $pdo->prepare("
                    SELECT cp.email, cp.name, cp.role
                    FROM chat_participants cp
                    WHERE cp.conversation_id = ?
                    ORDER BY cp.id ASC
                ");
                $stmtP->execute([$convId]);
                $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

                $partsClean = [];
                foreach ($participants as $p) {
                    $pEmail = strtolower(trim($p['email']));
                    $accName = $accountNamesMap[$pEmail] ?? '';
                    $pName = trim($p['name'] ?: ($accName ?: explode('@', $pEmail)[0]));
                    $partsClean[] = [
                        'email' => $pEmail,
                        'name' => $pName,
                        'role' => $p['role'] ?? 'member'
                    ];
                }

                $title = '';
                $subtitle = '';
                if ($r['type'] === 'direct') {
                    if (count($partsClean) >= 2) {
                        $title = $partsClean[0]['name'] . ' ↔ ' . $partsClean[1]['name'];
                        $subtitle = $partsClean[0]['email'] . ' • ' . $partsClean[1]['email'];
                    } elseif (count($partsClean) === 1) {
                        $title = $partsClean[0]['name'];
                        $subtitle = $partsClean[0]['email'];
                    } else {
                        $title = 'Birebir Görüşme #' . $convId;
                        $subtitle = 'Katılımcı bilgisi bulunamadı';
                    }
                } else {
                    $title = $r['title'] ?: 'Grup Görüşmesi #' . $convId;
                    $names = array_map(function($p) { return $p['name']; }, $partsClean);
                    $subtitle = implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' kişi' : '');
                }

                $conversations[] = [
                    'id' => $convId,
                    'type' => $r['type'],
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'participants' => $partsClean,
                    'total_messages' => (int)$r['total_messages'],
                    'last_message' => $r['last_message'] ?? '',
                    'last_message_sender' => $r['last_message_sender'] ?? '',
                    'last_message_at' => $r['last_message_at'] ?? '',
                    'created_at' => $r['created_at'] ?? ''
                ];
            }

            echo json_encode([
                'success' => true,
                'conversations' => $conversations,
                'total_count' => count($conversations)
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
            exit;
        }

    case 'chat_admin_get_conversation_messages':
        $acc = get_active_account($pdo);
        if (!$acc || empty($acc->is_admin)) {
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim. Sadece sistem yöneticileri görüntüleyebilir.']);
            exit;
        }

        $convId = (int)($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
        if ($convId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz görüşme ID']);
            exit;
        }

        $stmtC = $pdo->prepare("SELECT * FROM chat_conversations WHERE id = ?");
        $stmtC->execute([$convId]);
        $conv = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            echo json_encode(['success' => false, 'message' => 'Görüşme bulunamadı.']);
            exit;
        }

        // Fetch participants (no cross-table JOIN)
        $stmtP = $pdo->prepare("
            SELECT cp.email, cp.name, cp.role
            FROM chat_participants cp
            WHERE cp.conversation_id = ?
            ORDER BY cp.id ASC
        ");
        $stmtP->execute([$convId]);
        $participants = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // Pre-fetch presence map and account names map in PHP safely
        $presenceMap = [];
        try {
            $stmtPr = $pdo->query("
                SELECT email, status, custom_status, last_seen,
                       (CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END) as is_online
                FROM chat_presence
            ");
            while ($prRow = $stmtPr->fetch(PDO::FETCH_ASSOC)) {
                $em = strtolower(trim($prRow['email'] ?? ''));
                if ($em !== '') {
                    $presenceMap[$em] = $prRow;
                }
            }
        } catch (Exception $e) {}

        $accountNamesMap = [];
        try {
            $stmtAccs = $pdo->query("SELECT email, name FROM mail_accounts");
            while ($accRow = $stmtAccs->fetch(PDO::FETCH_ASSOC)) {
                $em = strtolower(trim($accRow['email'] ?? ''));
                if ($em !== '') {
                    $accountNamesMap[$em] = trim($accRow['name'] ?? '');
                }
            }
        } catch (Exception $e) {}

        $partsClean = [];
        foreach ($participants as $p) {
            $pEmail = strtolower(trim($p['email']));
            $accName = $accountNamesMap[$pEmail] ?? '';
            $pName = trim($p['name'] ?: ($accName ?: explode('@', $pEmail)[0]));
            $pres = $presenceMap[$pEmail] ?? [];
            $partsClean[] = [
                'email' => $pEmail,
                'name' => $pName,
                'role' => $p['role'] ?? 'member',
                'status' => $pres['status'] ?? 'offline',
                'is_online' => (int)($pres['is_online'] ?? 0),
                'custom_status' => $pres['custom_status'] ?? ''
            ];
        }

        if ($conv['type'] === 'direct') {
            if (count($partsClean) >= 2) {
                $conv['display_title'] = $partsClean[0]['name'] . ' ↔ ' . $partsClean[1]['name'];
                $conv['display_subtitle'] = $partsClean[0]['email'] . ' • ' . $partsClean[1]['email'];
            } elseif (count($partsClean) === 1) {
                $conv['display_title'] = $partsClean[0]['name'];
                $conv['display_subtitle'] = $partsClean[0]['email'];
            } else {
                $conv['display_title'] = 'Birebir Görüşme #' . $convId;
                $conv['display_subtitle'] = '';
            }
        } else {
            $conv['display_title'] = $conv['title'] ?: 'Grup Görüşmesi #' . $convId;
            $conv['display_subtitle'] = count($partsClean) . ' Katılımcı';
        }

        // Fetch messages chronologically
        $stmtM = $pdo->prepare("
            SELECT id, conversation_id, sender_email, sender_name, message, attachment_url, 
                   attachment_name, attachment_size, attachment_type, is_deleted, reactions, created_at
            FROM chat_messages
            WHERE conversation_id = ?
            ORDER BY id ASC
        ");
        $stmtM->execute([$convId]);
        $messages = [];
        $myEmail = strtolower(trim($acc->email));

        while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
            $m['is_me'] = (strtolower(trim($m['sender_email'])) === $myEmail);
            $m['reactions'] = !empty($m['reactions']) ? json_decode($m['reactions'], true) : [];
            $messages[] = $m;
        }

        echo json_encode([
            'success' => true,
            'conversation' => $conv,
            'participants' => $partsClean,
            'messages' => $messages,
            'is_admin_mode' => true
        ]);
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem: ' . htmlspecialchars($action)]);
        exit;
}
