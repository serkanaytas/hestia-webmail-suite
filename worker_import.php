<?php
/**
 * Hestia Webmail Suite - Background Mail Import Worker
 * Executes independently of browser session via CLI or background process.
 * Highly resilient: uses persistent single-session IMAP connections (imap_reopen),
 * native FT_UID operations, bulk duplicate avoidance, and cooperative throttling.
 */
ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Security guard: Only allow execution from CLI (or localhost internal trigger)
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
    ]);
    $pdo->exec("SET time_zone = '+03:00'");
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/import_worker_error.log', date('Y-m-d H:i:s') . " DB Error: " . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}

// Resolve Job ID
$job_id = 0;
if (isset($argv[1])) {
    $job_id = (int)$argv[1];
} elseif (isset($_GET['job_id'])) {
    $job_id = (int)$_GET['job_id'];
}

if ($job_id <= 0) {
    // Pick the oldest pending job
    $stmt = $pdo->query("SELECT id FROM mail_imports WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
    $job_id = (int)$stmt->fetchColumn();
}

if ($job_id <= 0) {
    exit(0);
}

// Fatal error catcher to prevent jobs hanging permanently in 'running'
register_shutdown_function(function() use (&$job_id, &$pdo) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = "Sunucu Hatası (Fatal): " . $error['message'] . " (Satır: " . $error['line'] . ")";
        if ($pdo && $job_id > 0) {
            try {
                $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = ?, completed_at = NOW(), source_password = NULL WHERE id = ? AND status = 'running'")
                    ->execute([$msg, $job_id]);
            } catch (Throwable $e) {}
        }
    }
});

// Helper: Parse email timestamp RFC 2822
function worker_parse_email_timestamp($date_str, $fallback_udate = 0) {
    if (!empty($date_str)) {
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

// Helper: Safely encode UTF-8 folder to IMAP modified UTF-7
function worker_safe_utf7_encode($folder) {
    if (empty($folder)) return $folder;
    if (function_exists('mb_convert_encoding')) {
        $encoded = @mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        if ($encoded !== false) return $encoded;
    }
    if (function_exists('imap_utf7_encode')) {
        return @imap_utf7_encode($folder);
    }
    return $folder;
}

// Ensure mailbox exists on target Dovecot server
function worker_ensure_imap_folder($conn, $folder, $server_prefix) {
    if (!$conn || in_array(strtoupper($folder), ['INBOX', 'SENT', 'DRAFTS', 'TRASH', 'SPAM', 'ARCHIVE'])) return true;

    $encoded = worker_safe_utf7_encode($folder);
    $fullMb = $server_prefix . $encoded;

    $old = error_reporting(0);
    $res = @imap_createmailbox($conn, $fullMb);
    if ($res) {
        @imap_subscribe($conn, $fullMb);
    }
    @imap_errors();
    @imap_alerts();
    error_reporting($old);
    return true;
}

// Helper: Check if IMAP connection resource/object is active and healthy
function worker_is_imap_alive($conn) {
    if (!$conn) return false;
    if (!is_resource($conn) && !(is_object($conn) && $conn instanceof \IMAP\Connection)) {
        return false;
    }
    $chk = @imap_check($conn);
    return ($chk !== false);
}

// Helper: Ensure Source IMAP connection is alive, reconnecting with backoff if dropped
function worker_ensure_source_connection(&$conn, $server_prefix, $sEncoded, $email, $pass) {
    if (worker_is_imap_alive($conn)) {
        return true;
    }

    if ($conn) {
        @imap_close($conn);
        $conn = null;
    }

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        usleep($attempt * 1200000); // 1.2s, 2.4s, 3.6s
        @imap_errors();
        @imap_alerts();
        $conn = @imap_open($server_prefix . $sEncoded, $email, $pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($conn && worker_is_imap_alive($conn)) {
            return true;
        }
    }
    return false;
}

// Fetch Job
$stmt = $pdo->prepare("SELECT * FROM mail_imports WHERE id = ?");
$stmt->execute([$job_id]);
$job = $stmt->fetch();

if (!$job || !in_array($job->status, ['pending', 'running'])) {
    exit(0);
}

// Fetch Target Account
$stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
$stmt->execute([$job->account_id]);
$acc = $stmt->fetch();

if (!$acc) {
    $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = 'Hedef e-posta hesabı bulunamadı', completed_at = NOW(), source_password = NULL WHERE id = ?")->execute([$job_id]);
    exit(1);
}

// Mark Job as Running
$myPid = function_exists('getmypid') ? getmypid() : 0;
$pdo->prepare("UPDATE mail_imports SET status = 'running', pid = ?, updated_at = NOW() WHERE id = ?")->execute([$myPid, $job_id]);

// Helper to check cancellation
function is_cancelled($pdo, $job_id) {
    $stmt = $pdo->prepare("SELECT status FROM mail_imports WHERE id = ?");
    $stmt->execute([$job_id]);
    $st = $stmt->fetchColumn();
    return ($st === 'cancelled');
}

$source_email = trim($job->source_email);
$source_pass  = $job->source_password;
$source_host  = trim($job->source_host);
$source_port  = (int)($job->source_port ?: 993);
$source_enc   = strtolower(trim($job->source_encryption ?: 'ssl'));
$source_folder = trim($job->source_folder ?: 'ALL');
$target_mode  = trim($job->target_mode ?: 'same');
$custom_target = trim($job->custom_target_folder ?: 'Eski_Postalar');
$date_filter  = trim($job->date_filter ?: 'all');
$limit_count  = (int)($job->limit_count ?: 0);
$skip_dup     = (bool)$job->skip_duplicates;

$flags = ($source_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : (($source_enc === 'tls' || $source_enc === 'starttls') ? "/imap/tls/novalidate-cert" : "/imap/notls");
$server_prefix = "{" . "{$source_host}:{$source_port}{$flags}" . "}";

// Check if target account has a valid password saved
if (empty($acc->imap_password)) {
    $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = 'Hedef e-posta hesabının yerel şifresi bulunamadı. Lütfen webmail oturumunuzu kapatıp şifrenizle tekrar giriş yapınız.', completed_at = NOW(), source_password = NULL WHERE id = ?")
        ->execute([$job_id]);
    exit(1);
}

// Configure IMAP socket timeouts for stability
imap_timeout(IMAP_OPENTIMEOUT, 25);
imap_timeout(IMAP_READTIMEOUT, 120);
imap_timeout(IMAP_WRITETIMEOUT, 120);
imap_timeout(IMAP_CLOSETIMEOUT, 25);

// 1. CONNECT TO TARGET DOVECOT (1 Persistent Session)
$target_host = $acc->imap_host;
$target_port = (int)($acc->imap_port ?: 993);
$target_enc  = strtolower($acc->imap_encryption ?: 'ssl');
$target_flags = ($target_enc === 'ssl') ? "/imap/ssl/novalidate-cert" : "/imap/notls";
$target_prefix = "{" . "{$target_host}:{$target_port}{$target_flags}" . "}";

$old = error_reporting(0);
$target_conn = @imap_open($target_prefix . "INBOX", $acc->imap_username, $acc->imap_password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
if (!$target_conn && $target_port === 993) {
    $spec_143 = "{" . "{$target_host}:143/imap/tls/novalidate-cert" . "}";
    $target_conn = @imap_open($spec_143 . "INBOX", $acc->imap_username, $acc->imap_password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if ($target_conn) $target_prefix = $spec_143;
}
@imap_errors();
@imap_alerts();
error_reporting($old);

if (!$target_conn) {
    $err = imap_last_error() ?: 'Hedef IMAP sunucusuna bağlanılamadı.';
    @imap_errors();
    @imap_alerts();
    $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = ?, completed_at = NOW(), source_password = NULL WHERE id = ?")
        ->execute(['Hedef posta kutusu bağlantı hatası: ' . $err, $job_id]);
    exit(1);
}

// 2. CONNECT TO SOURCE IMAP SERVER (1 Persistent Session, NO multiple logins)
$old = error_reporting(0);
$source_conn = @imap_open($server_prefix . "INBOX", $source_email, $source_pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
$raw_err = imap_last_error();
@imap_errors();
@imap_alerts();
error_reporting($old);

if (!$source_conn) {
    $err = $raw_err ?: 'Kaynak IMAP sunucusuna bağlanılamadı.';
    if (stripos($err, 'login failures') !== false) {
        $err = 'Yandex güvenlik koruması: Çok fazla bağlantı isteği nedeniyle Yandex hesabı geçici olarak bekletmeye aldı (Too many login failures). Lütfen 5-10 dakika bekleyin veya Yandex ID üzerinden yeni bir Uygulama Şifresi oluşturup deneyin.';
    }
    @imap_close($target_conn);
    $pdo->prepare("UPDATE mail_imports SET status = 'failed', error_message = ?, completed_at = NOW(), source_password = NULL WHERE id = ?")
        ->execute(['Kaynak posta kutusu bağlantı hatası: ' . $err, $job_id]);
    exit(1);
}

// 3. DISCOVER SOURCE FOLDERS (using existing source_conn)
$folders_to_import = [];
if ($source_folder !== 'ALL') {
    $folders_to_import[] = $source_folder;
} else {
    $mb_list = @imap_getmailboxes($source_conn, $server_prefix, "*");
    if ($mb_list) {
        foreach ($mb_list as $mb) {
            $raw = str_replace($server_prefix, '', $mb->name);
            $low = strtolower($raw);
            if (strpos($low, 'trash') !== false || strpos($low, 'çöp') !== false || strpos($low, 'bin') !== false) {
                continue;
            }
            $folders_to_import[] = $raw;
        }
    } else {
        $folders_to_import[] = 'INBOX';
    }
}

// Prepare search criteria
$criteria = 'ALL';
if ($date_filter === '30d') {
    $criteria = 'SINCE "' . date('j-M-Y', strtotime('-30 days')) . '"';
} elseif ($date_filter === '90d') {
    $criteria = 'SINCE "' . date('j-M-Y', strtotime('-90 days')) . '"';
} elseif ($date_filter === '180d') {
    $criteria = 'SINCE "' . date('j-M-Y', strtotime('-180 days')) . '"';
} elseif ($date_filter === '365d') {
    $criteria = 'SINCE "' . date('j-M-Y', strtotime('-365 days')) . '"';
}

// 4. PRE-SCAN MESSAGE COUNTS (using imap_reopen on existing connection, ZERO extra logins!)
$folder_uids_map = [];
$total_messages_to_import = 0;

foreach ($folders_to_import as $fName) {
    if (is_cancelled($pdo, $job_id)) break;

    $sEncoded = worker_safe_utf7_encode($fName);
    @imap_reopen($source_conn, $server_prefix . $sEncoded);

    $uids = @imap_search($source_conn, $criteria, SE_UID);
    if ($uids && is_array($uids)) {
        $valid_uids = array_values(array_filter(array_map('intval', $uids), function($u) { return $u > 0; }));
        if (!empty($valid_uids)) {
            $folder_uids_map[$fName] = $valid_uids;
            $total_messages_to_import += count($valid_uids);
        }
    }
}

if ($limit_count > 0 && $total_messages_to_import > $limit_count) {
    $total_messages_to_import = $limit_count;
}

// Save initial total_found in database
$pdo->prepare("UPDATE mail_imports SET total_found = ?, updated_at = NOW() WHERE id = ?")->execute([$total_messages_to_import, $job_id]);

$total_imported = 0;
$total_skipped = 0;
$total_failed = 0;
$errors = [];

// 5. MAIN IMPORT LOOP
foreach ($folder_uids_map as $sFld => $uids) {
    if ($limit_count > 0 && $total_imported >= $limit_count) break;

    if (is_cancelled($pdo, $job_id)) {
        @imap_close($source_conn);
        @imap_close($target_conn);
        $pdo->prepare("UPDATE mail_imports SET status = 'cancelled', error_message = 'Kullanıcı tarafından iptal edildi', completed_at = NOW(), source_password = NULL WHERE id = ?")->execute([$job_id]);
        exit(0);
    }

    // Determine target folder name
    $targetFldName = 'INBOX';
    if ($target_mode === 'custom' && !empty($custom_target)) {
        $subName = ($sFld === 'INBOX') ? 'Gelen' : $sFld;
        $targetFldName = $custom_target . '/' . $subName;
    } else {
        $lowFld = strtolower($sFld);
        if ($lowFld === 'inbox') {
            $targetFldName = 'INBOX';
        } elseif (strpos($lowFld, 'sent') !== false) {
            $targetFldName = 'Sent';
        } elseif (strpos($lowFld, 'draft') !== false) {
            $targetFldName = 'Drafts';
        } else {
            $targetFldName = $sFld;
        }
    }

    // Ensure target folder exists on Dovecot
    worker_ensure_imap_folder($target_conn, $targetFldName, $target_prefix);
    $targetBoxName = $target_prefix . worker_safe_utf7_encode($targetFldName);

    // Fast bulk duplicate check: Fetch existing Message-IDs using a single bulk overview (reopen existing target_conn)
    $existing_msgids = [];
    if ($skip_dup) {
        @imap_reopen($target_conn, $targetBoxName);
        $totalInTarget = @imap_num_msg($target_conn);
        if ($totalInTarget > 0) {
            $rangeStart = max(1, $totalInTarget - 4000 + 1);
            $overviews = @imap_fetch_overview($target_conn, "{$rangeStart}:{$totalInTarget}", 0);
            if ($overviews && is_array($overviews)) {
                foreach ($overviews as $ov) {
                    if (!empty($ov->message_id)) {
                        $existing_msgids[trim($ov->message_id)] = true;
                    }
                }
            }
        }
    }

    // Reopen source connection to current folder (NO new login if already open)
    $sEncoded = worker_safe_utf7_encode($sFld);
    $reopenOk = false;
    if (worker_is_imap_alive($source_conn)) {
        $reopenOk = @imap_reopen($source_conn, $server_prefix . $sEncoded);
    }
    if (!$reopenOk) {
        if (!worker_ensure_source_connection($source_conn, $server_prefix, $sEncoded, $source_email, $source_pass)) {
            $errors[] = "Kaynak klasöre ({$sFld}) erişilemedi.";
            $total_failed += count($uids);
            continue;
        }
    }

    // Immediately update current folder in DB so user sees active progress instantly
    $pdo->prepare("UPDATE mail_imports SET current_folder = ?, updated_at = NOW() WHERE id = ?")->execute([$sFld, $job_id]);

    // 3. STREAMING FAST IMPORT LOOP
    // We check headers directly per UID (~150-200ms). If duplicate, body is NEVER downloaded!
    // Progress is updated to the database in real time so the UI continuously moves!
    $msgCounter = 0;
    foreach ($uids as $sUid) {
        if ($limit_count > 0 && $total_imported >= $limit_count) break;

        // Check cancellation every 5 messages
        if ($msgCounter % 5 === 0) {
            if (is_cancelled($pdo, $job_id)) {
                if ($source_conn) @imap_close($source_conn);
                if ($target_conn) @imap_close($target_conn);
                $pdo->prepare("UPDATE mail_imports SET status = 'cancelled', error_message = 'Kullanıcı tarafından iptal edildi', completed_at = NOW(), source_password = NULL WHERE id = ?")->execute([$job_id]);
                exit(0);
            }
        }
        $msgCounter++;

        $sUid = (int)$sUid;
        if ($sUid <= 0) continue;

        // CRITICAL: Ensure source connection is 100% alive before any fetch to avoid TypeError
        if (!worker_is_imap_alive($source_conn)) {
            if (!worker_ensure_source_connection($source_conn, $server_prefix, $sEncoded, $source_email, $source_pass)) {
                $errors[] = "Yandex bağlantısı koptu ve yeniden bağlanılamadı.";
                break; // Break from this folder safely without throwing TypeError!
            }
        }

        // Step 1: Fetch ONLY the header directly via UID (~150-200ms)
        $header = @imap_fetchheader($source_conn, $sUid, FT_UID | FT_PREFETCHTEXT);
        if ($header === false || strlen($header) < 10) {
            $err = imap_last_error();
            @imap_errors();
            @imap_alerts();

            if ($err && (stripos($err, 'connection') !== false || stripos($err, 'broken') !== false || stripos($err, 'lost') !== false || stripos($err, 'closed') !== false)) {
                // Connection was closed by Yandex: reconnect safely
                if (worker_ensure_source_connection($source_conn, $server_prefix, $sEncoded, $source_email, $source_pass)) {
                    $header = @imap_fetchheader($source_conn, $sUid, FT_UID | FT_PREFETCHTEXT);
                }
            }

            if ($header === false || strlen($header) < 10) {
                // Unavailable / purged / deleted message on Yandex
                $total_skipped++;

                $total_processed = $total_imported + $total_skipped + $total_failed;
                if ($total_processed % 3 === 0 || $total_processed === 1) {
                    $percent = $total_messages_to_import > 0 ? min(99, round(($total_processed / $total_messages_to_import) * 100)) : 0;
                    $pdo->prepare("UPDATE mail_imports SET imported_count = ?, skipped_count = ?, failed_count = ?, current_folder = ?, progress_percent = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$total_imported, $total_skipped, $total_failed, $sFld, $percent, $job_id]);
                }
                continue;
            }
        }

        // Step 2: Extract Message-ID from header
        $msgId = '';
        if (preg_match('/^Message-ID:\s*(<[^>]+>)/im', $header, $mIdMatch)) {
            $msgId = trim($mIdMatch[1]);
        }

        // Step 3: Duplicate check - skip immediately without downloading body!
        if ($skip_dup && $msgId && isset($existing_msgids[$msgId])) {
            $total_skipped++;
            unset($header);

            $total_processed = $total_imported + $total_skipped + $total_failed;
            if ($total_processed % 3 === 0 || $total_processed === 1) {
                $percent = $total_messages_to_import > 0 ? min(99, round(($total_processed / $total_messages_to_import) * 100)) : 0;
                $pdo->prepare("UPDATE mail_imports SET imported_count = ?, skipped_count = ?, failed_count = ?, current_folder = ?, progress_percent = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$total_imported, $total_skipped, $total_failed, $sFld, $percent, $job_id]);
            }
            continue;
        }

        // Step 4: Truly NEW message! Fetch body and append to Dovecot
        $body = @imap_body($source_conn, $sUid, FT_UID | FT_PEEK);
        if ($body === false) {
            $body = '';
        }

        // Parse date from header
        $headerDate = '';
        if (preg_match('/^Date:\s*(.+)$/im', $header, $dMatch)) {
            $headerDate = trim($dMatch[1]);
        }
        $mailTs = worker_parse_email_timestamp($headerDate, 0);
        $dateStr = date('j-M-Y H:i:s O', $mailTs);

        $fullEmail = $header . "\r\n" . $body;

        $appendOk = @imap_append($target_conn, $targetBoxName, $fullEmail, "\\Seen", $dateStr);

        // If append failed, reconnect target connection and retry once
        if (!$appendOk) {
            @imap_errors();
            @imap_alerts();
            @imap_close($target_conn);
            usleep(200000);
            $target_conn = @imap_open($target_prefix . "INBOX", $acc->imap_username, $acc->imap_password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if ($target_conn) {
                worker_ensure_imap_folder($target_conn, $targetFldName, $target_prefix);
                $appendOk = @imap_append($target_conn, $targetBoxName, $fullEmail, "\\Seen", $dateStr);
            }
        }

        if ($appendOk) {
            $total_imported++;
            if ($msgId) $existing_msgids[$msgId] = true;
        } else {
            $total_failed++;
            $err = imap_last_error();
            if ($err && count($errors) < 10) {
                $errors[] = "UID {$sUid}: " . $err;
            }
        }

        unset($header, $body, $fullEmail);

        // Cooperative throttling: 15ms delay
        usleep(15000);

        // Update progress in database on every imported message
        $total_processed = $total_imported + $total_skipped + $total_failed;
        $percent = $total_messages_to_import > 0 ? min(99, round(($total_processed / $total_messages_to_import) * 100)) : 0;
        $pdo->prepare("UPDATE mail_imports SET imported_count = ?, skipped_count = ?, failed_count = ?, current_folder = ?, progress_percent = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$total_imported, $total_skipped, $total_failed, $sFld, $percent, $job_id]);
    }

    // Update after folder completion
    $total_processed = $total_imported + $total_skipped + $total_failed;
    $percent = $total_messages_to_import > 0 ? min(99, round(($total_processed / $total_messages_to_import) * 100)) : 0;
    $pdo->prepare("UPDATE mail_imports SET imported_count = ?, skipped_count = ?, failed_count = ?, current_folder = ?, progress_percent = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$total_imported, $total_skipped, $total_failed, $sFld, $percent, $job_id]);
}

if ($source_conn && worker_is_imap_alive($source_conn)) {
    @imap_close($source_conn);
}
if ($target_conn) {
    @imap_close($target_conn);
}

// Final truthful status calculation
$total_processed = $total_imported + $total_skipped + $total_failed;
$percent = $total_messages_to_import > 0 ? round(($total_processed / $total_messages_to_import) * 100) : 100;
$percent = min(100, max(0, (int)$percent));

if ($total_imported === 0 && $total_failed > 0) {
    $finalStatus = 'failed';
    $errMsg = !empty($errors) ? implode('; ', array_slice($errors, 0, 3)) : 'E-postalar aktarılamadı.';
} elseif ($total_failed > 0 || ($total_messages_to_import > 0 && $total_processed < $total_messages_to_import)) {
    $finalStatus = 'completed_with_errors';
    $errMsg = "{$total_imported} adet aktarıldı, {$total_skipped} mükerrer atlandı, {$total_failed} aktarılamadı.";
    if (!empty($errors)) {
        $errMsg .= ' Hata: ' . implode('; ', array_slice($errors, 0, 2));
    }
} else {
    $finalStatus = 'completed';
    $errMsg = !empty($errors) ? implode('; ', array_slice($errors, 0, 2)) : null;
}

$pdo->prepare("UPDATE mail_imports SET status = ?, imported_count = ?, skipped_count = ?, failed_count = ?, progress_percent = ?, error_message = ?, completed_at = NOW(), source_password = NULL, updated_at = NOW() WHERE id = ?")
    ->execute([$finalStatus, $total_imported, $total_skipped, $total_failed, $percent, $errMsg, $job_id]);

@imap_errors();
@imap_alerts();
exit(0);
