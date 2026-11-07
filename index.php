<?php
/**
 * Hestia Webmail Suite - Standalone Main Portal
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');

// Harden session cookies against XSS & CSRF
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
if ($is_https) {
    @ini_set('session.cookie_secure', '1');
}
session_start();

// Send HTTP Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/config.php';

// If setup is not completed, redirect to setup wizard
if (!defined('MAIL_DOMAIN') || empty(MAIL_DOMAIN) || !defined('DB_NAME') || empty(DB_NAME)) {
    header("Location: wizard.php");
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+03:00'");
} catch (Exception $e) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage(),
            'recovery_url' => 'wizard.php?manual=1'
        ]);
        exit;
    }

    $db_err = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $cur_host = htmlspecialchars(defined('DB_HOST') ? DB_HOST : 'localhost', ENT_QUOTES, 'UTF-8');
    $cur_name = htmlspecialchars(defined('DB_NAME') ? DB_NAME : '', ENT_QUOTES, 'UTF-8');
    $cur_user = htmlspecialchars(defined('DB_USER') ? DB_USER : '', ENT_QUOTES, 'UTF-8');
    $has_pass = defined('DB_PASS') && strlen(DB_PASS) > 0;
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Veritabanı Bağlantı Hatası - Yapılandırma Gerekli</title>
      <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=1">
      <link rel="shortcut icon" href="favicon.ico?v=1">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; border-radius: 0 !important; font-family: 'Plus Jakarta Sans', sans-serif; }
        body {
          background-color: #0b1120;
          color: #f8fafc;
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1.5rem;
        }
        .error-card {
          width: 100%;
          max-width: 760px;
          background: #1e293b;
          border: 1px solid #334155;
          box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
          overflow: hidden;
        }
        .card-header {
          background: #0f172a;
          border-bottom: 1px solid #334155;
          padding: 1.25rem 1.75rem;
          display: flex;
          align-items: center;
          gap: 1rem;
        }
        .badge-err {
          background: rgba(239, 68, 68, 0.15);
          color: #ef4444;
          border: 1px solid rgba(239, 68, 68, 0.3);
          font-size: 0.75rem;
          font-weight: 700;
          padding: 0.35rem 0.65rem;
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }
        .card-title {
          font-size: 1.15rem;
          font-weight: 700;
          color: #f8fafc;
        }
        .card-body {
          padding: 1.75rem;
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
        }
        .alert-box {
          background: rgba(239, 68, 68, 0.08);
          border-left: 4px solid #ef4444;
          padding: 1rem 1.25rem;
          color: #fca5a5;
          font-size: 0.88rem;
          line-height: 1.5;
        }
        .err-code {
          background: #0f172a;
          border: 1px solid #334155;
          padding: 0.85rem 1rem;
          font-family: 'JetBrains Mono', monospace;
          font-size: 0.85rem;
          color: #f87171;
          word-break: break-all;
          margin-top: 0.5rem;
        }
        .info-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
          gap: 0.75rem;
          background: #0f172a;
          border: 1px solid #334155;
          padding: 1rem;
        }
        .info-item {
          display: flex;
          flex-direction: column;
          gap: 0.2rem;
        }
        .info-label {
          font-size: 0.72rem;
          color: #94a3b8;
          text-transform: uppercase;
          font-weight: 600;
          letter-spacing: 0.04em;
        }
        .info-val {
          font-size: 0.9rem;
          color: #e2e8f0;
          font-weight: 600;
          font-family: 'JetBrains Mono', monospace;
        }
        .tips-list {
          list-style: none;
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
          font-size: 0.85rem;
          color: #94a3b8;
          padding-left: 0;
        }
        .tips-list li {
          display: flex;
          align-items: flex-start;
          gap: 0.5rem;
          line-height: 1.45;
        }
        .tips-list li::before {
          content: "•";
          color: #f59e0b;
          font-weight: bold;
          font-size: 1.1rem;
          line-height: 1;
        }
        .card-actions {
          background: #0f172a;
          border-top: 1px solid #334155;
          padding: 1.25rem 1.75rem;
          display: flex;
          flex-wrap: wrap;
          gap: 0.75rem;
          justify-content: flex-end;
        }
        .btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.5rem;
          padding: 0.75rem 1.25rem;
          font-size: 0.88rem;
          font-weight: 600;
          text-decoration: none;
          cursor: pointer;
          border: 1px solid transparent;
          transition: all 0.15s ease;
        }
        .btn-primary {
          background: #4f46e5;
          color: #ffffff;
          border-color: #4f46e5;
        }
        .btn-primary:hover {
          background: #4338ca;
          border-color: #4338ca;
        }
        .btn-secondary {
          background: #1e293b;
          color: #f8fafc;
          border-color: #475569;
        }
        .btn-secondary:hover {
          background: #334155;
        }
        .btn-outline {
          background: transparent;
          color: #94a3b8;
          border-color: #334155;
        }
        .btn-outline:hover {
          color: #f8fafc;
          border-color: #64748b;
        }
      </style>
    </head>
    <body>
      <div class="error-card">
        <div class="card-header">
          <span class="badge-err">Bağlantı Hatası</span>
          <div class="card-title">MySQL Veritabanına Erişilemedi</div>
        </div>
        <div class="card-body">
          <div class="alert-box">
            Sistem <strong>config.php</strong> dosyasındaki hosting veritabanı bilgileriyle MySQL sunucusuna bağlanamadı:
            <div class="err-code"><?= $db_err ?></div>
          </div>

          <div>
            <div style="font-size:0.75rem; color:#94a3b8; text-transform:uppercase; font-weight:700; margin-bottom:0.5rem; letter-spacing:0.05em;">Mevcut Yapılandırma Değerleri</div>
            <div class="info-grid">
              <div class="info-item">
                <span class="info-label">DB Host</span>
                <span class="info-val"><?= $cur_host ?></span>
              </div>
              <div class="info-item">
                <span class="info-label">DB Adı</span>
                <span class="info-val"><?= $cur_name ?: '<em>(Belirtilmemiş)</em>' ?></span>
              </div>
              <div class="info-item">
                <span class="info-label">DB Kullanıcısı</span>
                <span class="info-val"><?= $cur_user ?: '<em>(Belirtilmemiş)</em>' ?></span>
              </div>
              <div class="info-item">
                <span class="info-label">Şifre Durumu</span>
                <span class="info-val"><?= $has_pass ? '•••••••• (Dolu)' : 'Boş' ?></span>
              </div>
            </div>
          </div>

          <div>
            <div style="font-size:0.75rem; color:#94a3b8; text-transform:uppercase; font-weight:700; margin-bottom:0.5rem; letter-spacing:0.05em;">Olası Nedenler & Çözümler</div>
            <ul class="tips-list">
              <li>Hosting sunucunuz <strong>localhost</strong> yerine <strong>127.0.0.1</strong> veya hosting IP adresi talep ediyor olabilir.</li>
              <li>Hosting panelinde (cPanel / HestiaCP / Plesk) veritabanı veya kullanıcısı henüz oluşturulmamış veya kullanıcıya veritabanı için <em>"Tüm Yetkiler" (ALL PRIVILEGES)</em> verilmemiş olabilir.</li>
              <li>Veritabanı şifresi veya kullanıcı adı kopyalanırken eksik veya hatalı karakter girilmiş olabilir.</li>
            </ul>
          </div>
        </div>
        <div class="card-actions">
          <button type="button" onclick="window.location.reload();" class="btn btn-outline">🔄 Tekrar Dene</button>
          <a href="wizard.php?reconfig=1" class="btn btn-secondary">⚡ Sihirbazı Aç</a>
          <a href="wizard.php?manual=1" class="btn btn-primary">🛠️ Manuel Config Formunu Aç</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle logout request
if (isset($_GET['logout'])) {
    unset($_SESSION['mail_logged_in'], $_SESSION['active_account_id'], $_SESSION['user_email']);
    @session_destroy();
    header("Location: index.php");
    exit;
}

// Authentication Check: Only allow if explicitly logged in
$is_logged_in = !empty($_SESSION['mail_logged_in']) && !empty($_SESSION['active_account_id']);
$active_account = null;

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT * FROM mail_accounts WHERE id = ?");
    $stmt->execute([$_SESSION['active_account_id']]);
    $active_account = $stmt->fetch();
    if (!$active_account) {
        unset($_SESSION['mail_logged_in'], $_SESSION['active_account_id'], $_SESSION['user_email']);
        $is_logged_in = false;
    }
}

// Release PHP session lock immediately so concurrent AJAX requests are never blocked
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Fetch Preferences
$prefs = [];
$pref_rows = $pdo->query("SELECT setting_key, setting_value FROM mail_settings")->fetchAll();
foreach ($pref_rows as $pr) {
    $prefs[$pr->setting_key] = $pr->setting_value;
}
$theme = $prefs['theme'] ?? 'light';
$language = $prefs['language'] ?? 'tr';
$custom_wallpaper = $prefs['custom_wallpaper'] ?? '';
$panel_opacity = isset($prefs['panel_opacity']) ? max(20, min(100, intval($prefs['panel_opacity']))) : 85;
if (!in_array($language, ['tr', 'en', 'de'])) {
    $language = 'tr';
}
$app_title = !empty($prefs['app_title']) ? $prefs['app_title'] : 'Hestia Mail';
$app_subtitle = !empty($prefs['app_subtitle']) ? $prefs['app_subtitle'] : 'Web Suite';
$auto_clean_trash = isset($prefs['auto_clean_trash']) ? ($prefs['auto_clean_trash'] === '1' || $prefs['auto_clean_trash'] === 'true' || $prefs['auto_clean_trash'] === 1) : true;
$trash_retention_days = isset($prefs['trash_retention_days']) ? max(1, (int)$prefs['trash_retention_days']) : 30;
$auto_clean_spam = isset($prefs['auto_clean_spam']) ? ($prefs['auto_clean_spam'] === '1' || $prefs['auto_clean_spam'] === 'true' || $prefs['auto_clean_spam'] === 1) : true;
$spam_retention_days = isset($prefs['spam_retention_days']) ? max(1, (int)$prefs['spam_retention_days']) : 30;

// Fetch Contacts Count for active account
$contacts_count = 0;
$active_domain = '';
if ($active_account) {
    if (!empty($active_account->email)) {
        $em_parts = explode('@', $active_account->email);
        $active_domain = strtolower($em_parts[1] ?? '');
    }
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE account_id = ?");
    $stmtC->execute([$active_account->id]);
    $contacts_count = $stmtC->fetchColumn();
}

// Fetch Upcoming Events for active account & shared domain events
$upcoming_events = [];
if ($active_account) {
    $now_str = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM calendar_events 
        WHERE (account_id = ? OR (domain = ? AND (is_shared = 1 OR is_shared IS NULL))) 
          AND end_date >= ? 
        ORDER BY start_date ASC LIMIT 5");
    $stmt->execute([$active_account->id, $active_domain, $now_str]);
    $upcoming_events = $stmt->fetchAll();
}

// Helper: Safely decode IMAP modified UTF-7 folder name to UTF-8
if (!function_exists('decode_imap_folder_name')) {
    function decode_imap_folder_name($str) {
        if ($str === null || $str === '') return '';
        if (strpos($str, '&') !== false && preg_match('/&[A-Za-z0-9+,]*-/', $str)) {
            if (function_exists('mb_convert_encoding')) {
                if (!preg_match('/[^\x00-\x7F]/', $str)) {
                    $dec = @mb_convert_encoding($str, 'UTF-8', 'UTF7-IMAP');
                    if ($dec !== false && $dec !== '' && strpos($dec, '?') === false) {
                        return $dec;
                    }
                }
            }
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
}

// Fetch Custom Folders for active account
$custom_folders = [];
if ($active_account) {
    $stmt = $pdo->prepare("SELECT * FROM mail_folders WHERE account_id = ? ORDER BY display_name ASC");
    $stmt->execute([$active_account->id]);
    $custom_folders = $stmt->fetchAll();
    foreach ($custom_folders as $cf) {
        $curName = $cf->display_name;
        $fName = $cf->folder_name;
        if (strpos($curName, '??') !== false || empty($curName)) {
            $decName = decode_imap_folder_name($fName);
        } else {
            $decName = decode_imap_folder_name($curName);
        }
        if ($decName) {
            $cf->display_name = $decName;
        }
    }
}
// Fetch All Accounts (for migration dropdown) only when logged in
$all_accounts = [];
if ($active_account) {
    try {
        $all_accounts = $pdo->query("SELECT id, name, email, imap_host, imap_port, imap_encryption, imap_username FROM mail_accounts ORDER BY email ASC")->fetchAll();
    } catch (Exception $e) {}
}

// Read CSS content to embed directly so styling NEVER fails
// CSS is loaded via <link rel="stylesheet"> with filemtime cache-busting
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language) ?>" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title id="app-page-title"><?= htmlspecialchars($app_title . ' - ' . $app_subtitle) ?></title>

  <!-- Favicon / Browser Tab Icon -->
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=1">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png?v=1">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png?v=1">
  <link rel="shortcut icon" href="favicon.ico?v=1">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=1">
  
  <!-- Modern Google Font: Roboto -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
  
  <!-- jQuery (Required for Select2) -->
  <script src="assets/vendor/jquery.min.js"></script>

  <!-- SweetAlert2 CDN -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Select2 CSS & JS -->
  <link rel="stylesheet" href="assets/vendor/select2.min.css">
  <script src="assets/vendor/select2.min.js"></script>
  <script src="assets/vendor/select2.tr.js"></script>

  <!-- Multi-Language i18n Engine -->
  <script src="assets/js/i18n.js?v=<?= @filemtime(__DIR__ . '/assets/js/i18n.js') ?: time() ?>"></script>
  <?php
  // Ensure server JSON translations from /languages/ are merged into I18N_TRANSLATIONS
  $embedded_translations = [];
  foreach (['tr', 'en', 'de'] as $lcode) {
      $lfile = __DIR__ . '/languages/' . $lcode . '.json';
      if (file_exists($lfile)) {
          $ljson = json_decode(file_get_contents($lfile), true);
          if ($ljson) {
              $embedded_translations[$lcode] = $ljson['translations'] ?? $ljson;
              unset($embedded_translations[$lcode]['_meta']);
          }
      }
  }
  ?>
  <script>
    try {
      const _srvTrans = <?= json_encode($embedded_translations, JSON_UNESCAPED_UNICODE) ?>;
      if (typeof I18N_TRANSLATIONS !== 'undefined' && _srvTrans) {
        Object.keys(_srvTrans).forEach(function(l) {
          I18N_TRANSLATIONS[l] = Object.assign(I18N_TRANSLATIONS[l] || {}, _srvTrans[l]);
        });
      }
    } catch(e) {}
  </script>

  <!-- External CSS Fallback -->
  <link rel="stylesheet" href="assets/css/webmail.css?v=<?= @filemtime(__DIR__ . '/assets/css/webmail.css') ?: time() ?>">
  
  <!-- Immediate Theme & Language Initialization to avoid flicker -->
  <script>
    (function() {
      try {
        const savedLang = localStorage.getItem('hestia_lang') || '<?= htmlspecialchars($language) ?>';
        if (['tr', 'en', 'de'].includes(savedLang)) {
          document.documentElement.setAttribute('lang', savedLang);
          if (typeof currentLang !== 'undefined') currentLang = savedLang;
        }
        const saved = localStorage.getItem('hestia_theme') || '<?= htmlspecialchars($theme) ?>';
        if (saved === 'system') {
          const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
          document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        } else if (['dark', 'light', 'light-sky', 'light-emerald', 'light-lavender', 'light-rose', 'light-amber', 'light-slate', 'emerald', 'ocean', 'amethyst', 'carbon', 'wallpaper-teddy', 'wallpaper-sea', 'wallpaper-nature', 'wallpaper-hearts', 'wallpaper-custom'].includes(saved)) {
          document.documentElement.setAttribute('data-theme', saved);
        }

        const savedOpacity = localStorage.getItem('hestia_opacity') || '<?= $panel_opacity ?>';
        if (savedOpacity) {
          const o = (parseInt(savedOpacity, 10) / 100).toFixed(2);
          document.documentElement.style.setProperty('--panel-opacity', o);
        }
        const savedCustomBg = localStorage.getItem('hestia_custom_wallpaper') || '<?= htmlspecialchars(addslashes($custom_wallpaper)) ?>';
        if (savedCustomBg) {
          document.documentElement.style.setProperty('--custom-wallpaper-url', `url("${savedCustomBg}")`);
        }
      } catch(e) {}
    })();
  </script>

  <!-- Embedded Master CSS (Guarantees styling displays properly 100% of the time) -->
  <style>
  :root {
    --panel-opacity: <?= number_format($panel_opacity / 100, 2, '.', '') ?>;
    <?php if (!empty($custom_wallpaper)): ?>
    --custom-wallpaper-url: url('<?= htmlspecialchars($custom_wallpaper) ?>');
    <?php endif; ?>
  }
  </style>
</head>
<body>

<?php if (empty($active_account)): ?>
  <!-- Theme & Language Controls for Login Screen -->
  <div style="position:fixed;top:1.25rem;right:1.25rem;z-index:90;display:flex;align-items:center;gap:0.5rem;">
    <!-- Language Selector Dropdown -->
    <div class="lang-selector-wrapper" id="login-lang-selector-wrapper">
      <button type="button" class="lang-btn" id="login-lang-toggle-btn" title="Dil / Language / Sprache" data-i18n-title="lang_menu_title" onclick="toggleLoginLangMenu(event)" style="height:36px;padding:0 0.65rem;background:var(--bg-surface);border:1px solid var(--border-color);box-shadow:var(--shadow-md);">
        <span class="current-lang-flag"><svg class="flag-icon" width="18" height="12" viewBox="0 0 600 400" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="600" height="400" fill="#E30A17"/><circle cx="200" cy="200" r="100" fill="#ffffff"/><circle cx="225" cy="200" r="80" fill="#E30A17"/><polygon points="280,200 342,220 304,168 304,232 342,180" fill="#ffffff"/></svg></span>
        <span class="current-lang-code-text" style="font-size:0.75rem;font-weight:700;">TR</span>
        <svg class="lang-btn-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="lang-dropdown-menu" id="login-lang-dropdown-menu">
        <a href="javascript:void(0)" class="lang-item active" data-lang="tr" onclick="setLanguage('tr')">
          <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 600 400" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="600" height="400" fill="#E30A17"/><circle cx="200" cy="200" r="100" fill="#ffffff"/><circle cx="225" cy="200" r="80" fill="#E30A17"/><polygon points="280,200 342,220 304,168 304,232 342,180" fill="#ffffff"/></svg></span>
          <span style="font-weight:600;" data-i18n="lang_tr">Türkçe</span>
          <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </a>
        <a href="javascript:void(0)" class="lang-item" data-lang="en" onclick="setLanguage('en')">
          <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 60 30" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="60" height="30" fill="#012169"/><path d="M0 0 L60 30 M60 0 L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0 L60 30 M60 0 L0 30" stroke="#C8102E" stroke-width="4"/><path d="M30 0 v30 M0 15 h60" stroke="#fff" stroke-width="10"/><path d="M30 0 v30 M0 15 h60" stroke="#C8102E" stroke-width="6"/></svg></span>
          <span style="font-weight:600;" data-i18n="lang_en">English</span>
          <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </a>
        <a href="javascript:void(0)" class="lang-item" data-lang="de" onclick="setLanguage('de')">
          <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 5 3" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="5" height="1" y="0" fill="#000000"/><rect width="5" height="1" y="1" fill="#DD0000"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg></span>
          <span style="font-weight:600;" data-i18n="lang_de">Deutsch</span>
          <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </a>
      </div>
    </div>

    <!-- Theme Toggle Button -->
    <button type="button" class="btn-icon theme-toggle-btn" onclick="toggleTheme()" title="Karanlık / Aydınlık Mod" data-i18n-title="nav_theme" style="width:40px;height:40px;background:var(--bg-surface);border:1px solid var(--border-color);box-shadow:var(--shadow-md);">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
    </button>
  </div>

  <!-- ==========================================
       LOGIN VIEW: ONLY EMAIL & PASSWORD REQUIRED
       ========================================== -->
  <div class="login-wrapper" style="display:flex;align-items:center;justify-content:center;min-height:100vh;width:100vw;background:var(--bg-app);padding:1.5rem;">
    <div class="card" style="width:100%;max-width:440px;padding:2.75rem 2.25rem;border:1px solid var(--border-color);background:var(--bg-surface);box-shadow:var(--shadow-lg);">
      <div style="text-align:center;margin-bottom:2rem;">
        <div class="brand-icon" style="width:52px;height:52px;margin:0 auto 1.25rem;">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
        </div>
        <h2 id="login-brand-title" style="font-size:1.5rem;font-weight:700;margin-bottom:0.2rem;letter-spacing:-0.02em;"><?= htmlspecialchars($app_title) ?></h2>
        <div id="login-brand-subtitle" style="font-size:0.825rem;color:var(--text-muted);font-weight:600;margin-bottom:0.35rem;text-transform:uppercase;letter-spacing:0.5px;"><?= htmlspecialchars($app_subtitle) ?></div>
        <div style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.25rem 0.75rem;background:var(--bg-hover);border:1px solid var(--border-color);font-size:0.8rem;color:var(--text-secondary);margin-top:0.25rem;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <span><?= htmlspecialchars(MAIL_DOMAIN) ?></span>
        </div>
      </div>

      <!-- Alert Box for Errors -->
      <div id="login-alert-box" style="display:none;padding:0.75rem 1rem;background:rgba(239, 68, 68, 0.1);border-left:3px solid var(--danger, #ef4444);color:var(--danger, #ef4444);font-size:0.85rem;margin-bottom:1.5rem;"></div>

      <form onsubmit="handleLogin(event)">
        <div class="form-group" style="margin-bottom:1.25rem;">
          <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
            <span data-i18n="login_user_label">Kullanıcı Adı</span>
            <span style="font-size:0.75rem;color:var(--text-muted);font-weight:normal;">@<?= htmlspecialchars(MAIL_DOMAIN) ?></span>
          </label>
          <div style="position:relative;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.9rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" id="login-username" class="form-control" placeholder="Örn: kullanici veya adiniz@<?= htmlspecialchars(MAIL_DOMAIN) ?>" data-i18n-placeholder="login_user_ph" required autofocus style="padding-left:2.5rem;padding-top:0.75rem;padding-bottom:0.75rem;font-size:0.95rem;">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:1.75rem;">
          <label class="form-label" data-i18n="login_pass_label">Şifre</label>
          <div style="position:relative;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.9rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="login-password" class="form-control" placeholder="••••••••" required style="padding-left:2.5rem;padding-right:2.5rem;padding-top:0.75rem;padding-bottom:0.75rem;font-size:0.95rem;">
            <button type="button" onclick="togglePasswordVisibility()" title="Şifreyi Göster/Gizle" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;align-items:center;">
              <svg id="pass-toggle-eye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" id="btn-login-submit" class="btn-send" style="width:100%;justify-content:center;padding:0.85rem;font-size:0.95rem;font-weight:700;display:flex;align-items:center;letter-spacing:0.02em;">
          <span data-i18n="login_submit_btn">Giriş Yap</span>
        </button>

        <div style="margin-top:1.25rem;text-align:center;font-size:0.775rem;color:var(--text-muted);line-height:1.5;" data-i18n="login_info_text">
          HestiaCP sunucunuzdaki e-posta hesabı bilgilerinizle doğrudan güvenli IMAP/SSL bağlantısı kurulur.
        </div>
      </form>
    </div>
  </div>


<?php else: ?>
  <!-- ==========================================
       MAIN WEBMAIL APPLICATION (LOGGED IN)
       ========================================== -->
  <div class="app-container">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="app-sidebar">
      <div class="sidebar-header">
        <a href="index.php" class="brand">
          <div class="brand-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
          </div>
          <div>
            <div class="brand-title" id="app-brand-title"><?= htmlspecialchars($app_title) ?></div>
            <div class="brand-subtitle" id="app-brand-subtitle"><?= htmlspecialchars($app_subtitle) ?></div>
          </div>
        </a>

        <div style="display:flex;align-items:center;gap:4px;">
          <button class="btn-icon theme-toggle-btn" id="theme-toggle-btn" title="Karanlık / Aydınlık Mod" onclick="toggleTheme()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
          </button>
          <button type="button" class="btn-icon mobile-close-sidebar-btn" onclick="toggleMobileSidebar(false)" title="Menüyü Kapat" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>

      <div class="sidebar-compose">
        <button class="btn-compose" onclick="openComposeModal()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14M5 12h14"/>
          </svg>
          <span data-i18n="new_email">Yeni E-posta</span>
        </button>
      </div>

      <nav class="sidebar-nav">

        <div class="nav-section-title" style="display:flex;align-items:center;justify-content:space-between;padding-right:0.5rem;">
          <span data-i18n="folders">Klasörler</span>
          <button type="button" class="btn-icon" style="width:22px;height:22px;border:none;color:var(--primary);cursor:pointer;" title="Yeni Klasör Ekle" data-i18n-title="new_folder" onclick="openNewFolderModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          </button>
        </div>
        <ul class="nav-links">
          <li class="nav-item">
            <a href="javascript:void(0)" class="nav-link active" id="folder-link-INBOX" onclick="selectFolder('INBOX', this)">
              <div class="nav-link-left">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                <span data-i18n="inbox">Gelen Kutusu</span>
              </div>
              <span class="nav-badge" id="folder-count-INBOX" style="display:none;"></span>
            </a>
          </li>
          <li class="nav-item">
            <a href="javascript:void(0)" class="nav-link" id="folder-link-Sent" onclick="selectFolder('Sent', this)">
              <div class="nav-link-left">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                <span data-i18n="sent">Gönderilenler</span>
              </div>
              <span class="nav-badge" id="folder-count-Sent" style="display:none;"></span>
            </a>
          </li>
          <li class="nav-item">
            <a href="javascript:void(0)" class="nav-link" id="folder-link-Drafts" onclick="selectFolder('Drafts', this)">
              <div class="nav-link-left">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span data-i18n="drafts">Taslaklar</span>
              </div>
              <span class="nav-badge" id="folder-count-Drafts" style="display:none;"></span>
            </a>
          </li>
          <li class="nav-item">
            <a href="javascript:void(0)" class="nav-link" id="folder-link-Spam" onclick="selectFolder('Spam', this)">
              <div class="nav-link-left">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span data-i18n="junk">İstenmeyen (Spam)</span>
              </div>
              <span class="nav-badge" id="folder-count-Spam" style="display:none;"></span>
            </a>
          </li>
          <li class="nav-item">
            <a href="javascript:void(0)" class="nav-link" id="folder-link-Trash" onclick="selectFolder('Trash', this)">
              <div class="nav-link-left">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span data-i18n="trash">Çöp Kutusu</span>
              </div>
              <span class="nav-badge" id="folder-count-Trash" style="display:none;"></span>
            </a>
          </li>
        </ul>

        <!-- Özel / IMAP Klasörleri (Klasörler Başlığı Altında) -->
        <div style="height:1px;background:var(--border-color);margin:0.4rem 0.75rem 0.35rem;opacity:0.6;"></div>

        <ul class="nav-links" id="sidebar-custom-folders">
          <?php if (!empty($custom_folders)): ?>
            <?php foreach ($custom_folders as $cf): 
              $is_sub = strpos($cf->folder_name, '/') !== false;
              $d_name = $is_sub ? substr(strrchr($cf->display_name, '/'), 1) : $cf->display_name;
              $p_left = $is_sub ? 'style="padding-left:1.75rem;"' : '';
              $f_color = $cf->color ?: '#4f46e5';
            ?>
              <li class="nav-item" style="position:relative;" id="folder-item-<?= htmlspecialchars($cf->folder_name) ?>" oncontextmenu="handleFolderContextMenu(event, '<?= htmlspecialchars(addslashes($cf->folder_name)) ?>', '<?= htmlspecialchars(addslashes($cf->display_name)) ?>', '<?= htmlspecialchars(addslashes($f_color)) ?>', <?= (int)$cf->id ?>)">
                <a href="javascript:void(0)" class="nav-link" id="folder-link-<?= htmlspecialchars($cf->folder_name) ?>" onclick="selectFolder('<?= htmlspecialchars(addslashes($cf->folder_name)) ?>', this)" ondblclick="openRenameFolderModal('<?= htmlspecialchars(addslashes($cf->folder_name)) ?>', '<?= htmlspecialchars(addslashes($cf->display_name)) ?>', '<?= htmlspecialchars(addslashes($f_color)) ?>', <?= (int)$cf->id ?>, event)" <?= $p_left ?>>
                  <div class="nav-link-left">
                    <?php if ($is_sub): ?>
                      <span style="display:inline-block;width:6px;height:6px;border-left:2px solid var(--text-muted);border-bottom:2px solid var(--text-muted);margin-right:6px;margin-bottom:2px;opacity:0.6;"></span>
                    <?php else: ?>
                      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="<?= htmlspecialchars($f_color) ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    <?php endif; ?>
                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:145px;" title="<?= htmlspecialchars($cf->display_name) ?>"><?= htmlspecialchars($d_name) ?></span>
                  </div>
                  <span class="nav-badge" id="folder-count-<?= htmlspecialchars($cf->folder_name) ?>" style="display:none;margin-right:48px;"></span>
                </a>
                <div class="folder-action-buttons" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:2px;">
                  <button type="button" class="btn-icon rename-folder-btn" onclick="openRenameFolderModal('<?= htmlspecialchars(addslashes($cf->folder_name)) ?>', '<?= htmlspecialchars(addslashes($cf->display_name)) ?>', '<?= htmlspecialchars(addslashes($f_color)) ?>', <?= (int)$cf->id ?>, event)" title="Yeniden Adlandır" data-i18n-title="rename_folder" style="width:20px;height:20px;border:none;color:var(--text-muted);opacity:0.6;padding:0;background:transparent;cursor:pointer;" onmouseover="this.style.opacity='1';this.style.color='var(--primary)';" onmouseout="this.style.opacity='0.6';this.style.color='var(--text-muted)';">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                  </button>
                  <button type="button" class="btn-icon delete-folder-btn" onclick="deleteCustomFolder(<?= (int)$cf->id ?>, '<?= htmlspecialchars(addslashes($cf->folder_name)) ?>', '<?= htmlspecialchars(addslashes($cf->display_name)) ?>', event)" title="Klasörü Sil" data-i18n-title="delete_folder" style="width:20px;height:20px;border:none;color:var(--text-muted);opacity:0.6;padding:0;background:transparent;cursor:pointer;" onmouseover="this.style.opacity='1';this.style.color='var(--danger)';" onmouseout="this.style.opacity='0.6';this.style.color='var(--text-muted)';">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </nav>

      <div class="sidebar-footer">
        <div class="account-selector" onclick="goToProfileSettings()" title="Profili ve Görünen Adı Düzenle" data-i18n-title="nav_profile" style="cursor:pointer;">
          <div class="account-avatar">
            <?= strtoupper(substr($active_account->name ?: $active_account->email, 0, 1)) ?>
          </div>
          <div class="account-info">
            <div class="account-name" style="display:flex;align-items:center;gap:0.35rem;">
              <span><?= htmlspecialchars($active_account->name ?: 'Hesap') ?></span>
              <?php if (!empty($active_account->is_admin)): ?>
                <span class="admin-badge admin-badge-gold" style="font-size:9px;padding:1px 5px;">Yönetici</span>
              <?php endif; ?>
            </div>
            <div class="account-email"><?= htmlspecialchars($active_account->email) ?></div>
          </div>
        </div>

        <button class="btn-icon" title="Çıkış Yap" data-i18n-title="nav_logout" onclick="handleLogout()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </button>
      </div>
    </aside>

    <!-- Sidebar Backdrop for Mobile Drawer -->
    <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleMobileSidebar(false)"></div>

    <!-- MAIN CONTENT AREA -->
    <main class="main-content">
      <!-- Top Navigation Bar -->
      <header class="top-navbar">
        <!-- Mobile Menu Hamburger Button -->
        <button type="button" class="btn-icon mobile-menu-btn" id="mobile-menu-toggle-btn" onclick="toggleMobileSidebar()" title="Klasörler ve Menü" style="display:none;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
        </button>
        <div class="top-tabs">
          <a href="javascript:void(0)" class="top-tab active" id="tab-top-mail" onclick="switchMainTab('mail')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span data-i18n="nav_email">E-Posta</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-contacts" onclick="switchMainTab('contacts')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <span data-i18n="nav_contacts">Adres Defteri</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-calendar" onclick="switchMainTab('calendar')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span data-i18n="nav_calendar">Takvim</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-tasks" onclick="switchMainTab('tasks')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <span data-i18n="nav_tasks">Yapılacaklar</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-notes" onclick="switchMainTab('notes')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span data-i18n="nav_notes">Notlar</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-drive" onclick="switchMainTab('drive')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><polyline points="12 13 12 9 10 11"/><polyline points="12 9 14 11"/></svg>
            <span data-i18n="nav_drive">Disk</span>
          </a>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-chat" onclick="switchMainTab('chat')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <span data-i18n="nav_chat">Mesaj</span>
            <span id="top-chat-unread-badge" class="chat-tab-badge" style="display:none;">0</span>
          </a>
          <?php if (!empty($active_account->is_admin)): ?>
          <a href="javascript:void(0)" class="top-tab" id="tab-top-admin" onclick="switchMainTab('admin')" style="color:#f59e0b;font-weight:600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span data-i18n="nav_admin">Yönetim Paneli</span>
          </a>
          <?php endif; ?>
        </div>

        <div class="top-actions">
          <!-- Language Selector Dropdown -->
          <div class="lang-selector-wrapper" id="lang-selector-wrapper">
            <button type="button" class="lang-btn" id="lang-toggle-btn" title="Dil / Language / Sprache" data-i18n-title="lang_menu_title" onclick="toggleLangMenu(event)">
              <span class="current-lang-flag"><svg class="flag-icon" width="18" height="12" viewBox="0 0 600 400" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="600" height="400" fill="#E30A17"/><circle cx="200" cy="200" r="100" fill="#ffffff"/><circle cx="225" cy="200" r="80" fill="#E30A17"/><polygon points="280,200 342,220 304,168 304,232 342,180" fill="#ffffff"/></svg></span>
              <span class="current-lang-code-text">TR</span>
              <svg class="lang-btn-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="lang-dropdown-menu" id="lang-dropdown-menu">
              <a href="javascript:void(0)" class="lang-item active" data-lang="tr" onclick="setLanguage('tr')">
                <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 600 400" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="600" height="400" fill="#E30A17"/><circle cx="200" cy="200" r="100" fill="#ffffff"/><circle cx="225" cy="200" r="80" fill="#E30A17"/><polygon points="280,200 342,220 304,168 304,232 342,180" fill="#ffffff"/></svg></span>
                <span style="font-weight:600;" data-i18n="lang_tr">Türkçe</span>
                <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
              </a>
              <a href="javascript:void(0)" class="lang-item" data-lang="en" onclick="setLanguage('en')">
                <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 60 30" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="60" height="30" fill="#012169"/><path d="M0 0 L60 30 M60 0 L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0 L60 30 M60 0 L0 30" stroke="#C8102E" stroke-width="4"/><path d="M30 0 v30 M0 15 h60" stroke="#fff" stroke-width="10"/><path d="M30 0 v30 M0 15 h60" stroke="#C8102E" stroke-width="6"/></svg></span>
                <span style="font-weight:600;" data-i18n="lang_en">English</span>
                <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
              </a>
              <a href="javascript:void(0)" class="lang-item" data-lang="de" onclick="setLanguage('de')">
                <span class="lang-flag-svg"><svg class="flag-icon" width="18" height="12" viewBox="0 0 5 3" style="display:inline-block;vertical-align:middle;box-shadow:0 0 1px rgba(0,0,0,0.4);flex-shrink:0;"><rect width="5" height="1" y="0" fill="#000000"/><rect width="5" height="1" y="1" fill="#DD0000"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg></span>
                <span style="font-weight:600;" data-i18n="lang_de">Deutsch</span>
                <svg class="lang-item-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
              </a>
            </div>
          </div>

          <button class="btn-icon" title="Yenile" data-i18n-title="nav_refresh" onclick="refreshCurrentView()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          </button>
          <button class="btn-icon" title="Sunucu & Ayarlar" data-i18n-title="nav_settings" onclick="switchMainTab('settings')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </button>
          <button class="btn-icon" id="btn-lock-screen" title="Ekranı Kilitle (Ctrl+Alt+L)" data-i18n-title="nav_lock_screen" onclick="lockScreen()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </button>
          <button class="btn-icon" title="Oturumu Kapat (Çıkış)" data-i18n-title="nav_logout" onclick="handleLogout()" style="color:var(--text-secondary);" onmouseover="this.style.color='var(--danger)';" onmouseout="this.style.color='var(--text-secondary)';">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          </button>
        </div>
      </header>

      <!-- SECTION 1: MAIL WORKSPACE -->
      <div id="view-mail" class="mail-workspace" style="display:flex;">
        <!-- Mail List Pane -->
        <section class="mail-list-pane" id="mail-list-pane">
          <script>try{const _w=localStorage.getItem('webmail_list_pane_width');if(_w)document.getElementById('mail-list-pane').style.width=_w+'px';}catch(e){}</script>
          <div class="mail-list-header">
            <div class="mail-search-toolbar">
              <div class="search-box">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="mail-search-input" class="search-input" placeholder="Seçili klasörde ara..." data-i18n-placeholder="search_placeholder" oninput="handleMailSearchInput(this.value)" onkeydown="if(event.key==='Enter') executeMailSearch(this.value)" style="border-radius:0 !important;">
                <button type="button" id="mail-search-clear" onclick="clearMailSearch()" title="Aramayı Temizle" data-i18n-title="search_clear" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;line-height:1;padding:2px 4px;">&times;</button>
              </div>
              <button type="button" id="btn-mail-search-submit" onclick="executeMailSearch(document.getElementById('mail-search-input').value)" title="Ara" data-i18n-title="search_btn" class="mail-search-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span data-i18n="search_btn">Ara</span>
              </button>
              <button type="button" id="btn-toggle-adv-search" onclick="toggleAdvancedSearchPanel()" title="Detaylı Arama (Gönderen, Alıcı, Konu, Klasör vb.)" data-i18n-title="search_adv_btn" class="mail-adv-search-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                <span class="adv-btn-text" data-i18n="search_adv_btn_label">Detaylı Arama</span>
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="adv-chevron"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
            </div>

            <!-- ADVANCED SEARCH PANEL (POPOVER) -->
            <div id="adv-search-panel" class="adv-search-panel">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.65rem;padding-bottom:0.4rem;border-bottom:1px solid var(--border-color);">
                <div style="font-weight:700;font-size:0.825rem;color:var(--text-primary);display:flex;align-items:center;gap:5px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <span data-i18n="adv_search_title">Detaylı E-Posta Araması</span>
                </div>
                <button type="button" onclick="toggleAdvancedSearchPanel(false)" style="background:none;border:none;font-size:18px;line-height:1;color:var(--text-muted);cursor:pointer;">&times;</button>
              </div>

              <!-- Klasör Seçimi (Hangi klasörde arasın: Tüm klasörler veya seçili) -->
              <div style="margin-bottom:0.5rem;">
                <label class="adv-search-label" data-i18n="adv_search_folder">Hangi Klasörde Aransın?</label>
                <select id="adv-search-folder" class="adv-search-select">
                  <option value="CURRENT">📁 Seçili Klasör (<span id="adv-fld-name-text">Gelen Kutusu</span>)</option>
                  <option value="ALL" data-i18n="adv_fld_all">🌐 Tüm Klasörler (Tüm Posta Kutuları)</option>
                  <option value="INBOX" data-i18n="adv_fld_inbox">📥 Gelen Kutusu (INBOX)</option>
                  <option value="Sent" data-i18n="adv_fld_sent">📤 Gönderilenler (Sent)</option>
                  <option value="Drafts" data-i18n="adv_fld_drafts">📝 Taslaklar (Drafts)</option>
                  <option value="Trash" data-i18n="adv_fld_trash">🗑️ Çöp Kutusu (Trash)</option>
                  <?php if (!empty($custom_folders)): ?>
                    <?php foreach ($custom_folders as $cf): ?>
                      <option value="<?= htmlspecialchars($cf->folder_name) ?>"><?= htmlspecialchars($cf->display_name) ?></option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <div class="adv-search-row">
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_from">Gönderen (Kimden / E-posta / Ad)</label>
                  <input type="text" id="adv-search-from" class="adv-search-input" placeholder="Örn: ahmet@ veya Ahmet">
                </div>
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_to">Alıcı (Kime / Gönderici)</label>
                  <input type="text" id="adv-search-to" class="adv-search-input" placeholder="Örn: muhasebe@ veya Serkan">
                </div>
              </div>

              <div class="adv-search-row">
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_subject">Konu Başlığı</label>
                  <input type="text" id="adv-search-subject" class="adv-search-input" placeholder="Konuda geçen kelime...">
                </div>
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_body">İçerik (Metin / Gövde)</label>
                  <input type="text" id="adv-search-body" class="adv-search-input" placeholder="E-posta metninde geçen kelime...">
                </div>
              </div>

              <div class="adv-search-row">
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_date_from">Başlangıç Tarihi</label>
                  <input type="date" id="adv-search-date-from" class="adv-search-input">
                </div>
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_date_to">Bitiş Tarihi</label>
                  <input type="date" id="adv-search-date-to" class="adv-search-input">
                </div>
              </div>

              <div class="adv-search-row" style="align-items:center;margin-top:0.25rem;">
                <div class="adv-search-col">
                  <label class="adv-search-label" data-i18n="adv_status">Okunma Durumu</label>
                  <select id="adv-search-status" class="adv-search-select">
                    <option value="" data-i18n="adv_status_all">Tümü</option>
                    <option value="unread" data-i18n="adv_status_unread">Yalnızca Okunmamış</option>
                    <option value="starred" data-i18n="adv_status_starred">Yalnızca Yıldızlı</option>
                  </select>
                </div>
                <div class="adv-search-col" style="padding-top:1.1rem;">
                  <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-size:0.775rem;user-select:none;font-weight:600;">
                    <input type="checkbox" id="adv-search-has-attachment" style="accent-color:var(--primary);border-radius:0 !important;">
                    <span data-i18n="adv_has_attachment">Sadece Ekli E-Postalar</span>
                  </label>
                </div>
              </div>

              <div style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;margin-top:0.75rem;padding-top:0.5rem;border-top:1px solid var(--border-color);">
                <button type="button" class="chip" onclick="resetAdvancedSearchForm()" style="border-radius:0 !important;padding:0.35rem 0.75rem;" data-i18n="adv_reset">Sıfırla</button>
                <button type="button" class="btn-primary" onclick="submitAdvancedSearch()" style="border-radius:0 !important;padding:0.35rem 0.95rem;font-size:0.775rem;font-weight:700;display:inline-flex;align-items:center;gap:5px;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <span data-i18n="adv_search_submit">Filtrele ve Ara</span>
                </button>
              </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
              <!-- Active Folder Title Badge -->
              <div id="current-folder-active-indicator" style="display:inline-flex;align-items:center;gap:6px;padding:3px 8px;background:var(--primary-light, rgba(79,70,229,0.12));border:1px solid var(--border-color);border-left:3px solid var(--primary);font-size:0.75rem;font-weight:700;color:var(--primary);letter-spacing:-0.01em;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                <span id="current-folder-title-text" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Gelen Kutusu</span>
              </div>

              <div class="filter-chips">
                <button class="chip active" id="chip-filter-all" onclick="setMailFilter('', this)" style="border-radius:0 !important;" data-i18n="filter_all">Tümü</button>
                <button class="chip" id="chip-filter-unread" onclick="setMailFilter('unread', this)" style="border-radius:0 !important;" data-i18n="filter_unread">Okunmamış</button>
                <button class="chip" id="chip-filter-starred" onclick="setMailFilter('starred', this)" style="border-radius:0 !important;" data-i18n="filter_starred">Yıldızlı</button>
              </div>

              <!-- Search Status Indicator / Result Bar -->
              <div id="mail-search-status-bar" style="display:none;align-items:center;gap:0.4rem;font-size:0.725rem;color:var(--primary);background:rgba(79,70,229,0.1);padding:2px 8px;border:1px solid rgba(79,70,229,0.25);border-radius:0 !important;">
                <span id="mail-search-status-text" data-i18n="search_results">Arama Sonuçları</span>
                <button type="button" onclick="clearMailSearch()" title="Aramayı Kapat" style="background:none;border:none;color:var(--primary);font-weight:700;cursor:pointer;padding:0 2px;line-height:1;">&times;</button>
              </div>
            </div>
          </div>

          <!-- Bulk Actions Toolbar (Multi-Select Delete, Move & Batch Actions) -->
          <div id="mail-bulk-toolbar" style="display:flex;align-items:center;justify-content:space-between;padding:0.45rem 0.85rem;background:var(--bg-surface-elevated);border-bottom:1px solid var(--border-color);font-size:0.775rem;min-height:36px;border-radius:0 !important;gap:0.5rem;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap;">
              <!-- Select Mode Control (Görünenleri Seç / Tümünü Seç Dropdown & Quick Actions) -->
              <div id="mail-select-menu-container" style="position:relative;display:inline-flex;align-items:center;gap:0.35rem;">
                <label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;user-select:none;font-weight:600;white-space:nowrap;margin:0;" title="Klasördeki tüm e-postaları seç / seçimi kaldır">
                  <input type="checkbox" id="mail-select-all-chk" onchange="toggleSelectAllMails(this.checked)" style="width:15px;height:15px;cursor:pointer;accent-color:var(--primary);border-radius:0 !important;margin:0;">
                  <span id="mail-select-count-text" data-i18n="bulk_select_all">Tümünü Seç</span>
                </label>
                <button type="button" id="mail-select-dropdown-btn" onclick="toggleMailSelectDropdown(event)" title="Seçim Seçenekleri (Görünenleri Seç / Tüm Klasörü Seç)" style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:22px;padding:0;background:transparent;border:1px solid transparent;color:var(--text-secondary);cursor:pointer;border-radius:0 !important;">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <button type="button" id="mail-select-visible-quick-btn" onclick="selectVisibleMails()" title="Sadece ekranda görünen (yüklenmiş) e-postaları seç" style="display:inline-flex;align-items:center;padding:2px 6px;font-size:0.7rem;font-weight:600;color:var(--text-secondary);background:var(--bg-surface);border:1px solid var(--border-color);cursor:pointer;border-radius:0 !important;white-space:nowrap;">
                  Görünenleri Seç
                </button>

                <!-- Selection Mode Dropdown Menu -->
                <div id="mail-select-dropdown" class="mail-sort-dropdown" style="left:0;right:auto;width:235px;" onclick="event.stopPropagation()">
                  <div class="mail-sort-heading">Seçim Kapsamı</div>
                  <button type="button" class="mail-sort-item" id="select-opt-visible" onclick="selectVisibleMails(); closeMailSelectDropdown();">
                    <span class="sort-check" id="select-chk-visible"></span>
                    <span>Görünen Mailleri Seç <small id="select-visible-count-hint" style="color:var(--text-muted);"></small></span>
                  </button>
                  <button type="button" class="mail-sort-item" id="select-opt-all-folder" onclick="selectAllFolderMails(); closeMailSelectDropdown();">
                    <span class="sort-check" id="select-chk-all-folder"></span>
                    <span>Tümünü Seç (Tüm Klasör) <small id="select-folder-count-hint" style="color:var(--text-muted);"></small></span>
                  </button>
                  <div class="mail-sort-divider"></div>
                  <button type="button" class="mail-sort-item" onclick="clearMailSelection(); closeMailSelectDropdown();">
                    <span class="sort-check"></span>
                    <span style="color:var(--danger);">Seçimi Temizle</span>
                  </button>
                </div>
              </div>

              <div id="mail-bulk-buttons" style="display:none;align-items:center;gap:0.35rem;padding-left:0.65rem;border-left:1px solid var(--border-color);flex-wrap:wrap;">
                <!-- Klasöre Taşı (Dropdown) -->
                <div id="bulk-move-container" style="position:relative;display:inline-flex;">
                  <button type="button" class="bulk-btn" id="bulk-move-btn" onclick="toggleBulkMoveDropdown(event)" title="Seçilenleri Klasöre Taşı" style="padding:0 0.5rem;gap:0.3rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="12 11 12 17 9 14"/></svg>
                    <span style="font-size:0.72rem;font-weight:600;">Taşı</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                  </button>
                  <div id="bulk-move-dropdown" class="mail-sort-dropdown" style="left:0;right:auto;width:225px;max-height:280px;overflow-y:auto;" onclick="event.stopPropagation()">
                    <!-- Populated dynamically via JS -->
                  </div>
                </div>
                <!-- Çöp Tenekesi (Sil) -->
                <button type="button" class="bulk-btn bulk-btn-danger" id="bulk-delete-btn" onclick="bulkDeleteSelectedMails()" title="Seçilenleri Sil">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                </button>
                <!-- Açık Zarf (Okundu Yap) -->
                <button type="button" class="bulk-btn" id="bulk-read-btn" onclick="bulkMarkStatus('seen')" title="Okundu Olarak İşaretle">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 10.5V19a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-8.5"/><path d="m2 10.5 8.7 5.8a2.4 2.4 0 0 0 2.6 0l8.7-5.8"/><path d="M2 10.5 12 3l10 7.5"/></svg>
                </button>
                <!-- Kapalı Zarf (Okunmadı Yap) -->
                <button type="button" class="bulk-btn" id="bulk-unread-btn" onclick="bulkMarkStatus('unseen')" title="Okunmadı Olarak İşaretle">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </button>
                <!-- Spam İşareti (Spam Bildir / Taşı) -->
                <button type="button" class="bulk-btn" id="bulk-spam-btn" onclick="bulkMarkSpamSelectedMails()" title="İstenmeyen (Spam) Olarak İşaretle">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </button>
                <!-- Seçimi Temizle (×) -->
                <button type="button" class="bulk-btn bulk-btn-close" id="bulk-close-btn" onclick="clearMailSelection()" title="Seçimi Temizle">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>

            <!-- Email List Sort Dropdown Control -->
            <div id="mail-sort-container" class="mail-sort-container">
              <button type="button" id="mail-sort-btn" onclick="toggleMailSortMenu(event)" class="mail-sort-btn" title="E-postaları Sırala" data-i18n-title="sort_btn_label">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18M6 12h12m-9 6h6"/></svg>
                <span id="mail-sort-btn-label" data-i18n="sort_date_desc">Tarih: En Yeni Önce</span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
              </button>

              <div id="mail-sort-dropdown" class="mail-sort-dropdown" onclick="event.stopPropagation()">
                <div class="mail-sort-heading" data-i18n="sort_title">Sıralama Ölçütü</div>
                
                <button type="button" class="mail-sort-item active" data-sort="date_desc" onclick="selectMailSort('date_desc')">
                  <span class="sort-check">✓</span>
                  <span class="sort-text" data-i18n="sort_date_desc">Tarih: En Yeni Önce</span>
                </button>
                <button type="button" class="mail-sort-item" data-sort="date_asc" onclick="selectMailSort('date_asc')">
                  <span class="sort-check"></span>
                  <span class="sort-text" data-i18n="sort_date_asc">Tarih: En Eski Önce</span>
                </button>

                <div class="mail-sort-divider"></div>
                <div class="mail-sort-heading" data-i18n="sort_by_sender">Gönderen / İsim</div>

                <button type="button" class="mail-sort-item" data-sort="from_asc" onclick="selectMailSort('from_asc')">
                  <span class="sort-check"></span>
                  <span class="sort-text" data-i18n="sort_from_asc">İsim: A'dan Z'ye</span>
                </button>
                <button type="button" class="mail-sort-item" data-sort="from_desc" onclick="selectMailSort('from_desc')">
                  <span class="sort-check"></span>
                  <span class="sort-text" data-i18n="sort_from_desc">İsim: Z'den A'ya</span>
                </button>

                <div class="mail-sort-divider"></div>
                <div class="mail-sort-heading" data-i18n="sort_by_subject">Konu</div>

                <button type="button" class="mail-sort-item" data-sort="subject_asc" onclick="selectMailSort('subject_asc')">
                  <span class="sort-check"></span>
                  <span class="sort-text" data-i18n="sort_subject_asc">Konu: A'dan Z'ye</span>
                </button>
                <button type="button" class="mail-sort-item" data-sort="subject_desc" onclick="selectMailSort('subject_desc')">
                  <span class="sort-check"></span>
                  <span class="sort-text" data-i18n="sort_subject_desc">Konu: Z'den A'ya</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Trash Folder Auto-Clean Banner Notice -->
          <div id="mail-trash-notice-bar" style="display:none;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.45rem 0.85rem;background:rgba(239,68,68,0.08);border-bottom:1px solid rgba(239,68,68,0.22);font-size:0.75rem;color:var(--text-primary);border-radius:0 !important;">
            <div style="display:flex;align-items:center;gap:0.45rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--danger, #ef4444);flex-shrink:0;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              <span id="trash-notice-text">Çöp kutusundaki <strong><?= (int)$trash_retention_days ?> günden eski</strong> e-postalar otomatik olarak kalıcı silinir.</span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;flex-shrink:0;">
              <button type="button" class="bulk-btn bulk-btn-danger" onclick="triggerCleanTrashNow(<?= (int)$trash_retention_days ?>)" style="padding:2px 8px;font-size:0.72rem;display:inline-flex;align-items:center;gap:4px;" title="<?= (int)$trash_retention_days ?> günden eski e-postaları kalıcı olarak temizle">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span>Şimdi Temizle</span>
              </button>
              <button type="button" class="bulk-btn" onclick="openTrashSettingsTab()" style="padding:3px 9px;font-size:0.75rem;display:inline-flex;align-items:center;gap:6px;" title="Ayarları Düzenle">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Ayarla</span>
              </button>
            </div>
          </div>

          <!-- Spam Folder Auto-Clean Banner Notice -->
          <div id="mail-spam-notice-bar" style="display:none;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.45rem 0.85rem;background:rgba(245,158,11,0.08);border-bottom:1px solid rgba(245,158,11,0.25);font-size:0.75rem;color:var(--text-primary);border-radius:0 !important;">
            <div style="display:flex;align-items:center;gap:0.45rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#f59e0b;flex-shrink:0;"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span id="spam-notice-text">İstenmeyen (Spam) kutusundaki <strong><?= (int)$spam_retention_days ?> günden eski</strong> e-postalar otomatik olarak kalıcı silinir.</span>
            </div>
            <div style="display:flex;align-items:center;gap:0.4rem;flex-shrink:0;">
              <button type="button" class="bulk-btn" onclick="triggerCleanSpamNow(<?= (int)$spam_retention_days ?>)" style="padding:3px 9px;font-size:0.75rem;display:inline-flex;align-items:center;gap:6px;border-color:rgba(245,158,11,0.4);color:#b45309;" title="<?= (int)$spam_retention_days ?> günden eski spam e-postaları kalıcı olarak temizle">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span>Şimdi Temizle</span>
              </button>
              <button type="button" class="bulk-btn" onclick="openSpamSettingsTab()" style="padding:3px 9px;font-size:0.75rem;display:inline-flex;align-items:center;gap:6px;" title="Ayarları Düzenle">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Ayarla</span>
              </button>
            </div>
          </div>

          <div class="mail-list-scroll" id="mail-list-container">
            <!-- Loaded via JS -->
          </div>
        </section>
        
        <!-- Draggable Resizer Divider between List and Reader -->
        <div class="pane-resizer" id="mail-pane-resizer" title="Okuma alanı genişliğini ayarlamak için sürükleyin (Varsayılana dönmek için çift tıklayın)">
          <div class="resizer-handle-grip"></div>
        </div>

        <!-- Reading Pane -->
        <section class="mail-reader-pane" id="mail-reader-pane">
          <div class="reader-empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <p data-i18n="no_mail_selected">Okumak için listeden bir e-posta seçin</p>
          </div>
        </section>
      </div>

      <!-- SECTION 2: CONTACTS -->
      <div id="view-contacts" class="page-container" style="display:none;">
        <div class="page-header-row" style="flex-wrap:wrap;gap:1rem;">
          <div>
            <h1 class="page-title" data-i18n="contacts_title">Adres Defteri &amp; Kişiler</h1>
            <div class="page-subtitle" id="contacts-header-subtitle"><?= $contacts_count ?> kayıtlı kişi listeleniyor</div>
          </div>

          <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
            <!-- Contacts Search Box -->
            <div class="search-box" style="margin-bottom:0;min-width:240px;position:relative;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--text-muted);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="contacts-search-input" class="search-input" placeholder="Kişilerde ara (ad, e-posta, tel)..." data-i18n-placeholder="contacts_search_ph" oninput="handleContactsSearch(this.value)" style="padding-left:2.2rem;padding-right:2rem;height:38px;border-radius:0 !important;">
              <button type="button" id="contacts-search-clear" onclick="clearContactsSearch()" style="display:none;position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;line-height:1;padding:4px;">&times;</button>
            </div>

            <!-- Global / Server Contacts Sync Button -->
            <button type="button" id="btn-sync-global-contacts" class="btn-tool" onclick="syncGlobalContacts()" title="Aynı sunucudaki tüm kurumsal mailleri rehberinize ekleyin" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.85rem;border:1px solid var(--border-color);background:var(--bg-surface);height:38px;font-size:0.825rem;border-radius:0 !important;cursor:pointer;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
              <span data-i18n="contacts_fetch_global">Global Rehberden Çek</span>
            </button>

            <!-- Mail Extraction Button -->
            <button type="button" id="btn-extract-contacts" class="btn-tool" onclick="extractContactsFromMailbox()" title="Gelen ve Gönderilen maillerden kişileri otomatik tara ve ekle" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.85rem;border:1px solid var(--border-color);background:var(--bg-surface);height:38px;font-size:0.825rem;border-radius:0 !important;cursor:pointer;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
              <span data-i18n="contacts_scan_mails">Maillerden Tara</span>
            </button>

            <!-- CSV Import Button & Hidden File Input -->
            <input type="file" id="contacts-csv-file" accept=".csv,text/csv" style="display:none;" onchange="handleContactsCsvUpload(event)">
            <button type="button" class="btn-tool" onclick="triggerContactsCsvUpload()" title="CSV Dosyasından Kişi İçe Aktar" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.85rem;border:1px solid var(--border-color);background:var(--bg-surface);height:38px;font-size:0.825rem;border-radius:0 !important;cursor:pointer;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <span data-i18n="contacts_import_csv">CSV İçe Aktar</span>
            </button>

            <button type="button" class="btn-tool" onclick="downloadSampleContactsCsv()" title="Örnek CSV şablonunu indir" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.45rem 0.65rem;border:1px solid var(--border-color);background:var(--bg-surface);height:38px;font-size:0.825rem;border-radius:0 !important;cursor:pointer;" title="Örnek CSV Formatı">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
              <span data-i18n="contacts_template">Şablon</span>
            </button>

            <!-- New Contact Button -->
            <button type="button" class="btn-send" onclick="openContactModal()" style="height:38px;padding:0.45rem 1rem;font-size:0.825rem;border-radius:0 !important;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              <span data-i18n="contacts_new">Yeni Kişi</span>
            </button>
          </div>
        </div>

        <!-- CONTACTS SUB-TABS: Personal vs Domain Global -->
        <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border-color);margin-bottom:1.25rem;padding-bottom:0.75rem;flex-wrap:wrap;gap:0.75rem;">
          <div style="display:flex;gap:0.5rem;">
            <button type="button" id="tab-btn-contacts-personal" class="btn-tool" onclick="switchContactsTab('personal')" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;font-weight:600;font-size:0.85rem;border:1px solid var(--border-color);background:var(--bg-surface-elevated);color:var(--text-main);cursor:pointer;border-radius:0 !important;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <span data-i18n="contacts_my_contacts">Kişisel Rehberim</span>
              <span id="contacts-personal-badge" style="padding:1px 6px;font-size:0.75rem;background:var(--bg-surface);border:1px solid var(--border-color);margin-left:4px;"><?= $contacts_count ?></span>
            </button>
            <button type="button" id="tab-btn-contacts-global" class="btn-tool" onclick="switchContactsTab('global')" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;font-weight:600;font-size:0.85rem;border:1px solid var(--border-color);background:var(--bg-surface);color:var(--text-muted);cursor:pointer;border-radius:0 !important;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span>Domain Kullanıcıları (@<?= htmlspecialchars($active_domain) ?>)</span>
              <span id="contacts-global-badge" style="padding:1px 6px;font-size:0.75rem;background:rgba(79,70,229,0.15);color:var(--primary);margin-left:4px;">...</span>
            </button>
          </div>
          <div id="contacts-global-info-badge" style="display:none;font-size:0.75rem;color:var(--text-muted);">
            Sadece <strong>@<?= htmlspecialchars($active_domain) ?></strong> alan adına ait sisteme giriş yapan kullanıcılar listelenir.
          </div>
        </div>

        <div class="contacts-grid" id="contacts-grid-container">
          <!-- Rendered via JS -->
        </div>
      </div>

      <!-- SECTION 3: CALENDAR -->
      <div id="view-calendar" class="page-container" style="display:none;">
        <div style="display:flex;gap:1.5rem;height:100%;">
          <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
            <div class="calendar-wrapper" style="flex:1;display:flex;flex-direction:column;">
              <div class="calendar-header">
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                  <h2 id="calendar-month-title" style="font-size:1.35rem;font-weight:700;">Ekim 2026</h2>
                  <button class="chip" onclick="calendarGoToToday()" style="border-radius:0 !important;" data-i18n="cal_today">Bugün</button>
                  <span class="badge" style="background:rgba(79,70,229,0.12);color:var(--primary);border:1px solid rgba(79,70,229,0.25);font-size:0.75rem;padding:0.25rem 0.65rem;font-weight:600;display:inline-flex;align-items:center;gap:0.4rem;border-radius:0 !important;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Ortak Kurumsal Takvim (@<?= htmlspecialchars($active_domain) ?>)
                  </span>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                  <button class="btn-send" style="padding:0.45rem 1rem;font-size:0.825rem;border-radius:0 !important;" onclick="openEventModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span data-i18n="cal_new_event">Yeni Etkinlik</span>
                  </button>
                  <button class="btn-icon" onclick="calendarPrevMonth()" title="Önceki Ay" style="border-radius:0 !important;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                  </button>
                  <button class="btn-icon" onclick="calendarNextMonth()" title="Sonraki Ay" style="border-radius:0 !important;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
              </div>

              <div class="calendar-grid" id="calendar-grid-box" style="flex:1;">
                <div class="calendar-day-header" data-i18n="day_mon">Pzt</div>
                <div class="calendar-day-header" data-i18n="day_tue">Sal</div>
                <div class="calendar-day-header" data-i18n="day_wed">Çar</div>
                <div class="calendar-day-header" data-i18n="day_thu">Per</div>
                <div class="calendar-day-header" data-i18n="day_fri">Cum</div>
                <div class="calendar-day-header" data-i18n="day_sat">Cmt</div>
                <div class="calendar-day-header" data-i18n="day_sun">Paz</div>
              </div>
            </div>
          </div>

          <!-- Upcoming Events Widget -->
          <div style="width:310px;flex-shrink:0;">
            <div class="card" style="border-radius:0 !important;">
              <h3 style="font-size:0.95rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span data-i18n="cal_upcoming_title">Yaklaşan Etkinlikler</span>
              </h3>
              <div style="display:flex;flex-direction:column;gap:0.75rem;" id="calendar-upcoming-list">
                <?php if (empty($upcoming_events)): ?>
                  <p style="font-size:0.825rem;color:var(--text-muted);text-align:center;padding:1.5rem 0;" data-i18n="cal_no_upcoming">Yaklaşan etkinlik yok.</p>
                <?php else: ?>
                  <?php foreach ($upcoming_events as $ue): ?>
                    <?php 
                      $isSharedEvent = !isset($ue->is_shared) || $ue->is_shared == 1;
                      $creatorDisp = !empty($ue->creator_name) ? $ue->creator_name : (!empty($ue->creator_email) ? explode('@', $ue->creator_email)[0] : '');
                    ?>
                    <div style="padding:0.75rem;background:var(--bg-surface-elevated);border-left:3px solid <?= htmlspecialchars($ue->color ?: '#4f46e5') ?>;border-radius:0 !important;cursor:pointer;" onclick="editEventById(<?= $ue->id ?>)">
                      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:2px;">
                        <span style="font-size:0.875rem;font-weight:600;"><?= htmlspecialchars($ue->title) ?></span>
                        <?php if ($isSharedEvent): ?>
                          <span title="Ortak Domain Etkinliği" style="font-size:0.7rem;color:var(--primary);display:inline-flex;align-items:center;gap:2px;">
                            👥 Ortak
                          </span>
                        <?php endif; ?>
                      </div>
                      <div style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
                        <span><?= date('d M H:i', strtotime($ue->start_date)) ?></span>
                        <span>&bull;</span>
                        <span><?= htmlspecialchars($ue->location ?: 'Toplantı') ?></span>
                        <?php if (!empty($creatorDisp)): ?>
                          <span>&bull;</span>
                          <span style="color:var(--primary);">Oluşturan: <?= htmlspecialchars($creatorDisp) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- SECTION 4: TASKS (YAPILACAKLAR) -->
      <div id="view-tasks" class="page-container" style="display:none;">
        <div class="tasks-container">
          <div class="page-header-row" style="margin-bottom:1rem;flex-wrap:wrap;gap:0.75rem;">
            <div>
              <h1 class="page-title" data-i18n="tasks_page_title">Yapılacaklar & Görevler</h1>
              <div class="page-subtitle" id="tasks-header-subtitle" data-i18n="tasks_page_sub">Yalnızca sizin kullanıcınıza özel kişisel yapılacaklar listesi</div>
            </div>
            <button type="button" class="btn-send" onclick="openTaskModal()" style="padding:0.5rem 1rem;font-size:0.825rem;border-radius:0 !important;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              <span data-i18n="tasks_new_task">Yeni Görev</span>
            </button>
          </div>

          <!-- Quick Add Bar -->
          <div class="tasks-quick-card">
            <form onsubmit="handleQuickAddTask(event)" class="tasks-quick-form">
              <input type="text" id="quick-task-title" class="task-input" placeholder="Hızlıca yeni bir görev yazın ve Enter'a basın..." data-i18n-placeholder="tasks_placeholder" required autocomplete="off">
              <select id="quick-task-priority" class="task-select" title="Öncelik">
                <option value="medium">Orta Öncelik</option>
                <option value="high">Yüksek Öncelik</option>
                <option value="low">Düşük Öncelik</option>
              </select>
              <input type="date" id="quick-task-date" class="task-select" title="Son Tarih (Opsiyonel)">
              <button type="submit" class="btn-send" style="padding:0.5rem 1rem;font-size:0.825rem;border-radius:0 !important;">
                <span data-i18n="tasks_add_btn">Ekle</span>
              </button>
            </form>
          </div>

          <!-- Filter Bar -->
          <div class="tasks-filter-bar">
            <div class="task-filter-chips">
              <button type="button" class="task-filter-chip active" id="chip-task-all" onclick="filterTasks('all', this)">Tümü (<span id="task-cnt-all">0</span>)</button>
              <button type="button" class="task-filter-chip" id="chip-task-pending" onclick="filterTasks('pending', this)">Devam Edenler (<span id="task-cnt-pending">0</span>)</button>
              <button type="button" class="task-filter-chip" id="chip-task-completed" onclick="filterTasks('completed', this)">Tamamlananlar (<span id="task-cnt-completed">0</span>)</button>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);">
              <span id="task-stat-text">0 görev yüklendi</span>
            </div>
          </div>

          <!-- Tasks List Container -->
          <div id="tasks-list-box" style="display:flex;flex-direction:column;gap:0.6rem;">
            <!-- Rendered via JS -->
          </div>
        </div>
      </div>

      <!-- SECTION 5: NOTES (NOTLAR) -->
      <div id="view-notes" class="page-container" style="display:none;">
        <div class="notes-container">
          <div class="page-header-row" style="margin-bottom:1rem;flex-wrap:wrap;gap:0.75rem;">
            <div>
              <h1 class="page-title" data-i18n="notes_page_title">Kişisel Notlar</h1>
              <div class="page-subtitle" id="notes-header-subtitle" data-i18n="notes_page_sub">Yalnızca sizin görebildiğiniz özel notlarınız ve karalamalarınız</div>
            </div>
            <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
              <div class="search-box" style="margin-bottom:0;min-width:220px;position:relative;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="notes-search-input" class="search-input" placeholder="Notlarda ara..." data-i18n-placeholder="notes_search_ph" oninput="handleNotesSearch(this.value)" style="padding-left:2.2rem;height:36px;border-radius:0 !important;">
              </div>
              <button type="button" class="btn-send" onclick="openNoteModal()" style="padding:0.45rem 1rem;font-size:0.825rem;border-radius:0 !important;height:36px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span data-i18n="notes_new_btn">Yeni Not Ekle</span>
              </button>
            </div>
          </div>

          <!-- Notes Grid Container -->
          <div id="notes-grid-box" class="notes-grid">
            <!-- Rendered via JS -->
          </div>
        </div>
      </div>

      <!-- SECTION: DRIVE (BULUT ALANI) -->
      <div id="view-drive" class="page-container" style="display:none;">
        <div class="drive-container">
          <!-- Disk Header -->
          <div class="page-header-row" style="margin-bottom:0.75rem;flex-wrap:wrap;gap:0.75rem;">
            <div>
              <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                <h1 class="page-title" style="margin:0;" data-i18n="drive_title">Disk (Bulut Depolama)</h1>
                <div class="drive-storage-pill" id="drive-storage-badge" title="Sunucunuzda kullanılan toplam depolama alanı">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                  <span id="drive-storage-text" data-i18n="drive_storage_calc">Hesaplanıyor...</span>
                </div>
              </div>
              <div class="page-subtitle" data-i18n="drive_subtitle">Hosting sunucunuzda barındırılan güvenli ve kişisel disk depolama alanınız</div>
            </div>

            <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
              <div class="search-box" style="margin-bottom:0;min-width:210px;position:relative;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="drive-search-input" class="search-input" placeholder="Disk'te ara..." data-i18n-placeholder="drive_search_ph" oninput="handleDriveSearch(this.value)" style="padding-left:2.2rem;height:36px;border-radius:0 !important;">
              </div>

              <button type="button" class="btn-secondary" onclick="openNewDriveFolderPrompt()" style="height:36px;border-radius:0 !important;display:inline-flex;align-items:center;gap:0.4rem;padding:0 0.85rem;font-size:0.825rem;font-weight:600;border:1px solid var(--border-color);background:var(--bg-surface);color:var(--text-primary);cursor:pointer;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                <span data-i18n="drive_new_folder">Yeni Klasör</span>
              </button>

              <button type="button" class="btn-send" onclick="triggerDriveFileInput()" style="height:36px;border-radius:0 !important;display:inline-flex;align-items:center;gap:0.4rem;padding:0 1rem;font-size:0.825rem;cursor:pointer;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                <span data-i18n="drive_upload_btn">Dosya Yükle</span>
              </button>

              <!-- Disk View Mode Switcher (Grid vs List) -->
              <div class="disk-view-switcher" style="display:inline-flex;border:1px solid var(--border-color);height:36px;background:var(--bg-surface);border-radius:0 !important;overflow:hidden;">
                <button type="button" class="disk-view-btn" id="disk-view-grid-btn" onclick="setDiskViewMode('grid')" title="Izgara Görünümü" data-i18n-title="disk_view_grid">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
                <button type="button" class="disk-view-btn" id="disk-view-list-btn" onclick="setDiskViewMode('list')" title="Liste Görünümü" data-i18n-title="disk_view_list" style="border-left:1px solid var(--border-color);">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
              </div>

              <input type="file" id="drive-hidden-file-input" multiple style="display:none;" onchange="handleDriveFileSelect(event)">
            </div>
          </div>

          <!-- Breadcrumbs Bar -->
          <div class="drive-breadcrumbs-bar" id="drive-breadcrumbs">
            <!-- Rendered by JS -->
          </div>

          <!-- Disk Bulk Actions Bar -->
          <div id="disk-bulk-bar" class="disk-bulk-bar" style="display:none;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <label style="display:inline-flex;align-items:center;gap:0.45rem;cursor:pointer;font-weight:600;font-size:0.85rem;color:var(--text-primary);user-select:none;">
                <input type="checkbox" id="disk-select-all-cb" onchange="toggleSelectAllDisk(this.checked)" style="width:16px;height:16px;cursor:pointer;">
                <span id="disk-bulk-count" data-i18n="drive_selected_items">0 öğe seçildi</span>
              </label>
              <button type="button" class="btn-tool" onclick="clearDiskSelection()" data-i18n="disk_clear_selection" style="height:26px;padding:0 0.55rem;font-size:0.75rem;border:1px solid var(--border-color);background:var(--bg-surface);border-radius:0 !important;cursor:pointer;">Seçimi Temizle</button>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;">
              <button type="button" class="btn-danger" onclick="executeDiskBulkDelete()" style="height:30px;padding:0 0.85rem;font-size:0.8rem;border-radius:0 !important;display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;background:#ef4444;color:#fff;border:none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span data-i18n="disk_bulk_delete">Seçilenleri Sil</span>
              </button>
            </div>
          </div>

          <!-- Folders Section -->
          <div id="drive-folders-section" style="display:none;">
            <div class="drive-section-header">
              <div class="drive-section-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                <span data-i18n="drive_folders_title">Klasörler</span>
                <span style="font-size:0.75rem;color:var(--text-muted);font-weight:normal;" id="drive-folders-count"></span>
              </div>
            </div>
            <div class="drive-folders-grid" id="drive-folders-grid">
              <!-- Rendered by JS -->
            </div>
          </div>

          <!-- Files Section -->
          <div id="drive-files-section" style="display:none;">
            <div class="drive-section-header">
              <div class="drive-section-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span data-i18n="drive_files_title">Dosyalar</span>
                <span style="font-size:0.75rem;color:var(--text-muted);font-weight:normal;" id="drive-files-count"></span>
              </div>
            </div>
            <div class="drive-files-grid" id="drive-files-grid">
              <!-- Rendered by JS -->
            </div>
          </div>

          <!-- List View Container (Table Mode) -->
          <div id="disk-list-view-container" style="display:none;margin-top:0.75rem;">
            <div class="disk-list-table-wrapper" style="border:1px solid var(--border-color);background:var(--bg-surface);overflow-x:auto;">
              <table class="disk-list-table">
                <thead>
                  <tr>
                    <th style="width:40px;text-align:center;">
                      <input type="checkbox" id="disk-list-header-cb" onchange="toggleSelectAllDisk(this.checked)">
                    </th>
                    <th data-i18n="disk_th_name">Ad</th>
                    <th style="width:130px;" data-i18n="disk_th_size">Boyut</th>
                    <th style="width:170px;" data-i18n="disk_th_date">Tarih</th>
                    <th style="width:140px;text-align:right;" data-i18n="disk_th_actions">İşlemler</th>
                  </tr>
                </thead>
                <tbody id="disk-list-table-body">
                  <!-- Rendered by JS -->
                </tbody>
              </table>
            </div>
          </div>

          <!-- Empty State Container -->
          <div id="drive-empty-container" style="display:none;">
            <!-- Rendered by JS -->
          </div>

          <!-- Drag and Drop Dropzone (Moved to Bottom) -->
          <div class="drive-dropzone" id="drive-dropzone" onclick="triggerDriveFileInput()" style="margin-top:1.25rem;">
            <div class="drive-dropzone-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/><polyline points="12 13 12 9 10 11"/><polyline points="12 9 14 11"/></svg>
            </div>
            <div style="font-size:0.85rem;color:var(--text-primary);font-weight:600;" data-i18n="drive_dropzone_title">
              Dosyalarınızı buraya sürükleyip bırakın veya <span style="color:var(--primary);text-decoration:underline;">cihazınızdan seçin</span>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);" data-i18n="drive_dropzone_sub">
              Tek veya çoklu dosya yükleyebilirsiniz. Dosyalarınız doğrudan hosting sunucunuzdaki güvenli alanınıza kaydedilir.
            </div>
          </div>
        </div>
      </div>

      <!-- SECTION: DOMAIN INTERNAL CHAT & MESSENGER (XMPP-LIKE) -->
      <div id="view-chat" class="chat-workspace-wrapper" style="display:none;width:100%;height:calc(100vh - 56px);overflow:hidden;position:relative;">
        <!-- Chat Lock removed - Ekran kilidi kullanılmaktadır -->
        <div id="chat-lock-overlay" style="display:none;"></div>

        <div class="chat-workspace" id="chat-workspace">
          <!-- Chat Left Sidebar -->
          <aside class="chat-sidebar">
            <div class="chat-sidebar-header">
              <!-- Current User Status Card -->
              <div class="chat-user-status-card">
                <div class="chat-my-info">
                  <div class="chat-my-avatar" id="chat-my-avatar">
                    <?= strtoupper(substr($active_account->name ?: $active_account->email, 0, 1)) ?>
                    <span class="status-dot online" id="chat-my-status-dot" style="position:absolute;bottom:0;right:0;border:2px solid var(--bg-surface);"></span>
                  </div>
                  <div class="chat-my-text">
                    <div class="chat-my-name" id="chat-my-name"><?= htmlspecialchars($active_account->name ?: $active_account->email) ?></div>
                    <div class="chat-my-status-label" id="chat-my-status-label" onclick="promptCustomStatus()" title="Durum iletisi belirle">
                      <span id="chat-my-custom-status-text">Çevrimiçi</span>
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </div>
                  </div>
                </div>

                <div style="display:flex;align-items:center;gap:4px;">
                  <?php if (!empty($active_account->is_admin)): ?>
                  <!-- Admin Monitoring Mode Quick Button -->
                  <button type="button" class="chat-presence-btn" id="chat-admin-mode-quick-btn" onclick="toggleAdminChatMode()" title="Tüm Şirket Görüşmelerini İzle (Yönetici)" style="color:#d97706;border-color:rgba(245,158,11,0.3);background:rgba(245,158,11,0.08);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  </button>
                  <?php endif; ?>

                  <!-- Presence Status Dropdown Trigger -->
                  <div style="position:relative;">
                    <button type="button" class="chat-presence-btn" id="chat-presence-toggle-btn" onclick="togglePresenceMenu(event)">
                      <span class="status-dot online" id="chat-presence-btn-dot"></span>
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div id="chat-presence-dropdown" class="lang-dropdown-menu" style="display:none;right:0;left:auto;min-width:180px;top:calc(100% + 6px);">
                      <a href="javascript:void(0)" class="lang-item" onclick="changeChatPresence('online')">
                        <span class="status-dot online"></span>
                        <span data-i18n="chat_online">Çevrimiçi</span>
                      </a>
                      <a href="javascript:void(0)" class="lang-item" onclick="changeChatPresence('away')">
                        <span class="status-dot away"></span>
                        <span data-i18n="chat_away">Uzakta</span>
                      </a>
                      <a href="javascript:void(0)" class="lang-item" onclick="changeChatPresence('busy')">
                        <span class="status-dot busy"></span>
                        <span data-i18n="chat_busy">Rahatsız Etmeyin</span>
                      </a>
                      <a href="javascript:void(0)" class="lang-item" onclick="changeChatPresence('offline')">
                        <span class="status-dot offline"></span>
                        <span data-i18n="chat_offline">Çevrimdışı</span>
                      </a>
                      <div style="height:1px;background:var(--border-color);margin:4px 0;"></div>
                      <a href="javascript:void(0)" class="lang-item" onclick="promptCustomStatus()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        <span style="font-size:0.75rem;">Durum İletisi Yaz...</span>
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Search Bar -->
              <div style="display:flex;align-items:center;gap:0.4rem;">
                <div class="search-box" style="flex:1;margin-bottom:0;position:relative;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.6rem;top:50%;transform:translateY(-50%);color:var(--text-muted);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="chat-search-input" class="search-input" placeholder="Mesaj veya kişi ara..." data-i18n-placeholder="chat_search_ph" oninput="handleChatSearch(this.value)" style="padding-left:2rem;height:34px;font-size:0.8rem;border-radius:6px !important;">
                </div>
              </div>

              <!-- Filter Tabs: Tümü | Mesajlar | Kişiler | Yönetici İzleme -->
              <div class="chat-filters-row">
                <button type="button" class="chat-filter-chip active" id="chat-filter-all" onclick="setChatFilter('all')">Tümü</button>
                <button type="button" class="chat-filter-chip" id="chat-filter-messages" onclick="setChatFilter('messages')">Mesajlar</button>
                <button type="button" class="chat-filter-chip" id="chat-filter-colleagues" onclick="setChatFilter('colleagues')">Kişiler</button>
                <?php if (!empty($active_account->is_admin)): ?>
                <button type="button" class="chat-filter-chip chat-filter-chip-admin" id="chat-filter-admin_all" onclick="setChatFilter('admin_all')" title="Tüm Şirket Görüşmeleri (Yönetici İzleme)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right:3px;vertical-align:middle;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  Yönetici İzleme
                </button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Conversations & Colleagues List -->
            <div class="chat-conv-list" id="chat-conv-list">
              <div style="text-align:center;padding:2rem 1rem;color:var(--text-muted);font-size:0.825rem;">
                Mesajlar yükleniyor...
              </div>
            </div>
          </aside>

          <!-- Chat Main Messaging Area -->
          <section class="chat-main-area" id="chat-main-area">
            <!-- Empty State (No Active Conversation) -->
            <div class="chat-empty-state" id="chat-empty-state">
              <button type="button" class="btn-icon mobile-only" onclick="backToChatSidebar()" title="Geri" aria-label="Geri" style="display:none;position:absolute;top:1rem;left:1rem;width:34px;height:34px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <div class="chat-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
              </div>
              <h2 class="chat-empty-title">Kurumsal Birebir Mesajlaşma</h2>
              <p class="chat-empty-desc">
                Sol menüden bir çalışma arkadaşınızı seçerek güvenli ve anlık birebir mesajlaşmaya başlayabilirsiniz.
              </p>
            </div>

            <!-- Active Conversation Workspace -->
            <div id="chat-active-conv" style="display:none;flex-direction:column;width:100%;height:100%;overflow:hidden;">
              <!-- Header -->
              <div class="chat-main-header">
                <div class="chat-header-info">
                  <button type="button" class="btn-icon mobile-only" onclick="backToChatSidebar()" title="Geri" aria-label="Geri" style="display:none;width:32px;height:32px;margin-right:4px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                  </button>
                  <div class="chat-header-avatar" id="chat-active-avatar">
                    @
                  </div>
                  <div class="chat-header-text">
                    <div class="chat-header-title" id="chat-active-title">
                      Mesajlaşma
                    </div>
                    <div class="chat-header-subtitle" id="chat-active-subtitle">
                      <span class="status-dot online"></span>
                      <span>Bağlı</span>
                    </div>
                  </div>
                </div>

                <div class="chat-header-actions">
                  <!-- Delete Conversation button -->
                  <button type="button" class="btn-icon" id="chat-btn-delete-conv" onclick="deleteActiveChatConversation()" title="Mesajlaşmayı Sil" data-i18n-title="chat_delete_conv_title" style="width:36px;height:36px;color:var(--danger);display:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                  </button>
                  <!-- Refresh Messages -->
                  <button type="button" class="btn-icon" onclick="reloadActiveConversation()" title="Mesajları Yenile" style="width:36px;height:36px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                  </button>
                </div>
              </div>

              <!-- Messages Container -->
              <div class="chat-messages-container" id="chat-messages-container" onscroll="handleChatMessagesScroll(event)">
                <!-- Messages rendered by JS -->
              </div>

              <!-- Typing Indicator Banner -->
              <div class="chat-typing-bar" id="chat-typing-banner" style="display:none;">
                <div class="chat-typing-dots"><span></span><span></span><span></span></div>
                <span id="chat-typing-text">yazıyor...</span>
              </div>

              <!-- Message Input / Composer -->
              <div class="chat-composer">
                <!-- Staged Attachment Card -->
                <div class="chat-staged-attachment" id="chat-staged-attachment">
                  <div style="display:flex;align-items:center;gap:0.5rem;overflow:hidden;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    <span id="chat-staged-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;">dosya.png</span>
                    <span id="chat-staged-size" style="color:var(--text-muted);font-size:0.75rem;">(1.2 MB)</span>
                  </div>
                  <button type="button" onclick="cancelStagedAttachment()" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px;line-height:1;padding:2px 4px;">&times;</button>
                </div>

                <div class="chat-composer-row">
                  <div class="chat-composer-tools">
                    <!-- File Attachment Button -->
                    <button type="button" class="chat-tool-btn" onclick="triggerChatFileInput()" title="Dosya veya Görsel Ekle" data-i18n-title="chat_file_upload_btn">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    </button>
                    <!-- Emoji Picker Button -->
                    <button type="button" class="chat-tool-btn" onclick="toggleChatEmojiPalette(event)" title="Emoji Ekle">
                      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                    </button>
                  </div>

                  <!-- Textarea -->
                  <textarea id="chat-message-input" class="chat-textarea" placeholder="Bir mesaj yazın... (Enter gönderir, Shift+Enter yeni satır)" data-i18n-placeholder="chat_type_placeholder" rows="1" oninput="handleChatInputAutoGrow(this)" onkeydown="handleChatInputKeyDown(event)"></textarea>

                  <!-- Send Button -->
                  <button type="button" id="chat-send-btn" class="chat-send-btn" onclick="sendChatMessage()" title="Gönder" data-i18n-title="chat_send_btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                  </button>
                </div>

                <!-- Emoji Popover -->
                <div class="chat-emoji-popover" id="chat-emoji-palette">
                  <div class="chat-emoji-grid" id="chat-emoji-grid">
                    <!-- Loaded by JS -->
                  </div>
                </div>

                <!-- Hidden File Input -->
                <input type="file" id="chat-hidden-file-input" style="display:none;" onchange="handleChatFileSelected(event)">
              </div>

              <!-- Admin Read-Only Audit Bar (Visible only when Admin is inspecting third-party conversations) -->
              <div id="chat-admin-readonly-bar" class="chat-admin-readonly-bar" style="display:none;">
                <div style="display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:0.825rem;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <span><strong>Yönetici İzleme Modu:</strong> Bu görüşme geçmişi personel denetimi amacıyla incelenmektedir (Salt Okunur).</span>
                </div>
                <button type="button" class="btn" onclick="reloadActiveConversation()" style="padding:4px 10px;font-size:0.8rem;display:inline-flex;align-items:center;gap:4px;border-radius:6px !important;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                  <span>Yenile</span>
                </button>
              </div>
            </div>
          </section>
        </div>
      </div>

      <!-- SECTION 6: SETTINGS & DIAGNOSTICS -->
      <div id="view-settings" class="page-container" style="display:none;width:100%;max-width:100%;box-sizing:border-box;">
        <div class="page-header-row">
          <div>
            <h1 class="page-title" data-i18n="settings_title">Ayarlar & Tercihler</h1>
            <div class="page-subtitle" data-i18n="settings_subtitle">Görünüm temaları, e-posta imzası ve filtre kuralları</div>
          </div>
        </div>

        <div class="settings-tabs">
          <button class="settings-tab-btn active" onclick="switchSettingsSubTab('appearance', this)" data-i18n="tab_appearance">Görünüm & Tema</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('profile', this)" data-i18n="tab_profile">Profil & Görünen Ad</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('password', this)" data-i18n="tab_password">Şifre Değiştir</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('signature', this)" data-i18n="tab_signature">İmza Ayarları</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('folders', this)" data-i18n="tab_folders">Klasörler</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('filters', this)" data-i18n="tab_filters">Filtreler & Kurallar</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('languages', this)" data-i18n="tab_languages">Dil Yönetimi</button>
          <button class="settings-tab-btn" onclick="switchSettingsSubTab('import', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:5px;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span data-i18n="tab_import">İçe Aktar</span>
          </button>
          <?php if (!empty($active_account->is_admin)): ?>
          <button class="settings-tab-btn" onclick="switchMainTab('admin')" style="color:#f59e0b;font-weight:600;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:5px;vertical-align:middle;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span data-i18n="nav_admin">Yönetim Paneli</span>
          </button>
          <?php endif; ?>
        </div>

        <!-- Profile & Display Name Tab -->
        <div id="subtab-profile" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);">
              <div class="account-avatar" id="profile-preview-avatar" style="width:54px;height:54px;font-size:1.35rem;border-radius:0 !important;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
                <?= strtoupper(substr($active_account->name ?: $active_account->email, 0, 1)) ?>
              </div>
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;" data-i18n="settings_profile_title">Kullanıcı Profili &amp; Görünen Ad</h3>
                <p style="color:var(--text-muted);font-size:0.825rem;margin:0;" data-i18n="settings_profile_sub">Giden e-postalarda ve takvim etkinliklerinde diğer kullanıcılara görünecek adınızı buradan belirleyin.</p>
              </div>
            </div>

            <form onsubmit="handleUpdateProfile(event)" id="form-update-profile">
              <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" style="font-weight:600;" data-i18n="settings_display_name_label">Görünen Ad (Ad Soyad) *</label>
                <input type="text" id="prof-display-name" class="form-control" style="border-radius:0 !important;" value="<?= htmlspecialchars($active_account->name ?? '') ?>" placeholder="Örn: Serkan Aytaş" required oninput="updateProfileLivePreview(this.value)">
                <small style="color:var(--text-muted);font-size:0.75rem;display:block;margin-top:0.35rem;" data-i18n="settings_display_name_hint">Gönderdiğiniz maillerde alıcılar "Kimden" alanında bu adı görecektir.</small>
              </div>

              <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" data-i18n="settings_email_locked">E-Posta Adresi (Kilitli)</label>
                <input type="email" class="form-control" style="border-radius:0 !important;background:var(--bg-surface-elevated);cursor:not-allowed;" value="<?= htmlspecialchars($active_account->email ?? '') ?>" readonly>
              </div>

              <div class="form-group" style="margin-bottom:1.5rem;">
                <label class="form-label" data-i18n="settings_domain_info">Domain Bilgisi</label>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                  <span class="badge" style="background:rgba(79,70,229,0.12);color:var(--primary);border:1px solid rgba(79,70,229,0.25);font-size:0.85rem;padding:0.4rem 0.8rem;border-radius:0 !important;">
                    @<?= htmlspecialchars($active_domain) ?>
                  </span>
                  <span style="font-size:0.775rem;color:var(--text-muted);" data-i18n="settings_domain_label">Yetkili Kurumsal Posta Alan Adı</span>
                </div>
              </div>

              <!-- Live Mail Preview -->
              <div style="margin-bottom:1.5rem;padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:0 !important;">
                <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:0.5rem;letter-spacing:0.05em;" data-i18n="profile_sender_title">Alıcının Göreceği Gönderici Başlığı:</div>
                <div style="font-family:monospace;font-size:0.9rem;color:var(--text-main);" id="prof-live-sender-preview">
                  Kimden: "<strong><?= htmlspecialchars($active_account->name ?: explode('@', $active_account->email)[0]) ?></strong>" &lt;<?= htmlspecialchars($active_account->email) ?>&gt;
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:1rem;">
                <button type="submit" id="btn-save-profile" class="btn-send" style="padding:0.75rem 1.75rem;font-weight:700;border-radius:0 !important;">
                  <span data-i18n="settings_save_profile">Profili Kaydet</span>
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Appearance & Theme Tab -->
        <div id="subtab-appearance" style="display:block;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.4rem;" data-i18n="settings_appearance_title">Görünüm & Renk Paletleri</h3>
            <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:1.5rem;">
              <span data-i18n="settings_appearance_sub">Webmail suite için dilediğiniz renk paletini seçin. Seçtiğiniz tema anında tüm arayüze uygulanır ve hatırlanır.</span>
            </p>

            <!-- DİL SEÇİMİ (MULTI-LANGUAGE i18n) -->
            <div style="margin-bottom:2.25rem;padding-bottom:1.75rem;border-bottom:1px solid var(--border-color);">
              <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2">
                  <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                  <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                <h4 style="font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-primary);margin:0;" data-i18n="settings_lang_title">Dil Tercihi / Language / Sprache</h4>
              </div>
              <p style="color:var(--text-muted);font-size:0.825rem;margin-bottom:0.85rem;" data-i18n="settings_lang_sub">
                Webmail arayüzünün görüntüleneceği dili seçin. Seçtiğiniz dil anında uygulanır ve kaydedilir.
              </p>
              <div class="lang-settings-grid">
                <div class="lang-card-option" id="lang-opt-tr" data-lang="tr" onclick="setLanguage('tr')">
                  <span class="lang-flag" style="font-size:1.5rem;">🇹🇷</span>
                  <div>
                    <div style="font-weight:700;font-size:0.9rem;" data-i18n="lang_tr">Türkçe</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" data-i18n="lang_default_desc">Varsayılan sistem dili</div>
                  </div>
                </div>
                <div class="lang-card-option" id="lang-opt-en" data-lang="en" onclick="setLanguage('en')">
                  <span class="lang-flag" style="font-size:1.5rem;">🇬🇧</span>
                  <div>
                    <div style="font-weight:700;font-size:0.9rem;" data-i18n="lang_en">English</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" data-i18n="lang_en_desc">Uluslararası İngilizce</div>
                  </div>
                </div>
                <div class="lang-card-option" id="lang-opt-de" data-lang="de" onclick="setLanguage('de')">
                  <span class="lang-flag" style="font-size:1.5rem;">🇩🇪</span>
                  <div>
                    <div style="font-weight:700;font-size:0.9rem;" data-i18n="lang_de">Deutsch</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" data-i18n="lang_de_desc">Standart Almanca</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- AÇIK RENK TEMALARI (LIGHT MOD) -->
            <div style="margin-bottom:2rem;">
              <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.85rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <h4 style="font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-primary);margin:0;"><span data-i18n="theme_light_mode_title">Açık Renk Temaları (Light Mod)</span></h4>
                <span class="badge" style="font-size:0.7rem;background:var(--primary-light);color:var(--primary);" data-i18n="theme_palette_count_7">7 Renk Paleti</span>
              </div>
              <div class="theme-options-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:1rem;">
                <!-- 1. Light (Açık İndigo / Klasik) -->
                <div class="theme-card-option" id="theme-opt-light" data-theme-choice="light" onclick="selectThemeChoice('light')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_indigo_name">Açık İndigo</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_indigo_desc">Ferah beyaz zemin &amp; modern indigo mavi</div>
                </div>

                <!-- 2. Light Sky (Açık Gökyüzü / Berrak Mavi) -->
                <div class="theme-card-option" id="theme-opt-light-sky" data-theme-choice="light-sky" onclick="selectThemeChoice('light-sky')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-sky">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_sky_name">Açık Masmavi</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_sky_desc">Berrak gökyüzü mavisi ve aydınlık zemin</div>
                </div>

                <!-- 3. Light Emerald (Açık Ferah Nane / Zümrüt) -->
                <div class="theme-card-option" id="theme-opt-light-emerald" data-theme-choice="light-emerald" onclick="selectThemeChoice('light-emerald')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-emerald">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_emerald_name">Açık Zümrüt &amp; Nane</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_emerald_desc">Ferah açık yeşil tonları ve nane tazeliği</div>
                </div>

                <!-- 4. Light Lavender (Açık Lavanta / Soft Mor) -->
                <div class="theme-card-option" id="theme-opt-light-lavender" data-theme-choice="light-lavender" onclick="selectThemeChoice('light-lavender')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-lavender">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_lavender_name">Açık Lavanta</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_lavender_desc">Zarif pastel leylak ve lavanta moru</div>
                </div>

                <!-- 5. Light Rose (Açık Gül & Pudra) -->
                <div class="theme-card-option" id="theme-opt-light-rose" data-theme-choice="light-rose" onclick="selectThemeChoice('light-rose')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-rose">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_rose_name">Açık Gül &amp; Pudra</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_rose_desc">Huzur veren soft pembe ve gül vurgusu</div>
                </div>

                <!-- 6. Light Amber (Açık Sıcak Kehribar / Kum) -->
                <div class="theme-card-option" id="theme-opt-light-amber" data-theme-choice="light-amber" onclick="selectThemeChoice('light-amber')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-amber">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_amber_name">Açık Kehribar</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_amber_desc">Sıcak bal ve altın kum açık paleti</div>
                </div>

                <!-- 7. Light Slate (Açık Minimal Gri / Slate) -->
                <div class="theme-card-option" id="theme-opt-light-slate" data-theme-choice="light-slate" onclick="selectThemeChoice('light-slate')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-light-slate">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_light_slate_name">Açık Minimal Gri</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_light_slate_desc">Kurumsal nötr gri ve parlak safir vurgu</div>
                </div>
              </div>
            </div>

            <!-- KOYU RENK TEMALARI (DARK MOD) -->
            <div style="margin-bottom:2rem;">
              <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.85rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <h4 style="font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-primary);margin:0;"><span data-i18n="theme_dark_mode_title">Koyu Renk Temaları (Dark Mod)</span></h4>
                <span class="badge" style="font-size:0.7rem;background:var(--bg-hover);color:var(--text-secondary);" data-i18n="theme_palette_count_5">5 Renk Paleti</span>
              </div>
              <div class="theme-options-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:1rem;">
                <!-- Dark (Koyu Lacivert) -->
                <div class="theme-card-option" id="theme-opt-dark" data-theme-choice="dark" onclick="selectThemeChoice('dark')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-dark">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_dark_indigo_name">Koyu Lacivert</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_dark_indigo_desc">Gözü yormayan modern indigo gece teması</div>
                </div>

                <!-- Ocean (Okyanus) -->
                <div class="theme-card-option" id="theme-opt-ocean" data-theme-choice="ocean" onclick="selectThemeChoice('ocean')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-ocean">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_dark_ocean_name">Okyanus Mavisi</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_dark_ocean_desc">Derin gece mavisi ve parlak gök mavisi</div>
                </div>

                <!-- Emerald (Koyu Zümrüt) -->
                <div class="theme-card-option" id="theme-opt-emerald" data-theme-choice="emerald" onclick="selectThemeChoice('emerald')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-emerald">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_dark_emerald_name">Koyu Zümrüt</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_dark_emerald_desc">Doğal koyu tonlar ve zümrüt yeşili vurgusu</div>
                </div>

                <!-- Amethyst (Ametist Moru) -->
                <div class="theme-card-option" id="theme-opt-amethyst" data-theme-choice="amethyst" onclick="selectThemeChoice('amethyst')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-amethyst">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_dark_amethyst_name">Ametist Moru</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_dark_amethyst_desc">Asil mor ve kadife gece tonları</div>
                </div>

                <!-- Carbon (Karbon Grafit) -->
                <div class="theme-card-option" id="theme-opt-carbon" data-theme-choice="carbon" onclick="selectThemeChoice('carbon')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-carbon">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_dark_carbon_name">Karbon Grafit</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_dark_carbon_desc">Kömür siyahı ve amber/altın vurgu</div>
                </div>
              </div>
            </div>

            <!-- ÖZEL ARKAPLANLI TEMALAR & CANLI SAYDAMLIK (OPACITY) KONTROLÜ -->
            <div style="margin-bottom:2.25rem;padding-bottom:1.75rem;border-bottom:1px solid var(--border-color);">
              <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.85rem;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  <h4 style="font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-primary);margin:0;"><span data-i18n="theme_wallpaper_mode_title">Özel Arkaplanlı Temalar</span></h4>
                  <span class="badge" style="font-size:0.7rem;background:var(--primary-light);color:var(--primary);" data-i18n="theme_wallpaper_badge">5 Manzara &amp; Konsept</span>
                </div>
                <div style="font-size:0.8rem;color:var(--text-muted);" data-i18n="theme_wallpaper_section_hint">Seçilen arkaplan tüm ekranda tam boy gösterilir, saydamlık slider ile ayarlanabilir.</div>
              </div>

              <!-- 5 Arkaplanlı Tema Seçenekleri (Ayıcık, Deniz, Doğa, Kalpli, Özel) -->
              <div class="theme-options-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:1rem;margin-bottom:1.25rem;">
                <!-- 1. Sevimli Ayıcık -->
                <div class="theme-card-option" id="theme-opt-wallpaper-teddy" data-theme-choice="wallpaper-teddy" onclick="selectThemeChoice('wallpaper-teddy')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-wallpaper-teddy">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span>🧸</span> <span data-i18n="theme_wallpaper_teddy_name">Sevimli Ayıcık</span>
                  </div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_wallpaper_teddy_desc">Sıcak bal ve karamel tonlarında sevimli ayıcık manzarası</div>
                </div>

                <!-- 2. Berrak Deniz & Okyanus -->
                <div class="theme-card-option" id="theme-opt-wallpaper-sea" data-theme-choice="wallpaper-sea" onclick="selectThemeChoice('wallpaper-sea')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-wallpaper-sea">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span>🌊</span> <span data-i18n="theme_wallpaper_sea_name">Berrak Deniz &amp; Okyanus</span>
                  </div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_wallpaper_sea_desc">Ferah turkuaz dalgalar ve kristal berraklığında sahil</div>
                </div>

                <!-- 3. Huzurlu Doğa & Orman -->
                <div class="theme-card-option" id="theme-opt-wallpaper-nature" data-theme-choice="wallpaper-nature" onclick="selectThemeChoice('wallpaper-nature')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-wallpaper-nature">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span>🌲</span> <span data-i18n="theme_wallpaper_nature_name">Huzurlu Doğa &amp; Orman</span>
                  </div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_wallpaper_nature_desc">Yeşilin binbir tonu, dağ ormanları ve dingin atmosfer</div>
                </div>

                <!-- 4. Romantik Kalpler -->
                <div class="theme-card-option" id="theme-opt-wallpaper-hearts" data-theme-choice="wallpaper-hearts" onclick="selectThemeChoice('wallpaper-hearts')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-wallpaper-hearts">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span>💖</span> <span data-i18n="theme_wallpaper_hearts_name">Romantik Kalpler</span>
                  </div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_wallpaper_hearts_desc">Rüya gibi pembe ve gül ışıltılı romantik kalpler</div>
                </div>

                <!-- 5. Özel Arkaplan -->
                <div class="theme-card-option" id="theme-opt-wallpaper-custom" data-theme-choice="wallpaper-custom" onclick="selectThemeChoice('wallpaper-custom')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-wallpaper-custom" id="theme-preview-box-custom">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span>🖼️</span> <span data-i18n="theme_wallpaper_custom_name">Özel Arkaplanım</span>
                  </div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_wallpaper_custom_desc">Yüklediğiniz tam ekran özel arkaplan görseli</div>
                </div>
              </div>

              <!-- CANLI OPACITY KONTROLÜ VE ÖZEL GÖRSEL YÖNETİMİ PANELİ -->
              <div class="card settings-section-card" style="background:var(--bg-surface-elevated);border:1px solid var(--border-color);padding:1.25rem;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:1.5rem;align-items:center;">
                  <!-- Opacity Slider Kutusu -->
                  <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                      <label for="theme-opacity-slider" style="font-size:0.875rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:6px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20Z"/></svg>
                        <span data-i18n="theme_opacity_slider_title">Saydamlık &amp; Opaklık Ayarı:</span>
                      </label>
                      <span id="theme-opacity-val" style="font-size:0.875rem;font-weight:700;color:var(--primary);background:var(--primary-light);padding:2px 10px;border-radius:4px;"><?= $panel_opacity ?>%</span>
                    </div>

                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.6rem;">
                      <span style="font-size:0.75rem;color:var(--text-muted);font-weight:600;">%20 (Cam)</span>
                      <input type="range" id="theme-opacity-slider" min="20" max="100" value="<?= $panel_opacity ?>" step="1" oninput="updateLiveOpacity(this.value)" onchange="saveOpacityPreference(this.value)" style="flex:1;accent-color:var(--primary);cursor:pointer;height:6px;">
                      <span style="font-size:0.75rem;color:var(--text-muted);font-weight:600;">%100 (Katı)</span>
                    </div>

                    <!-- Hızlı Önayarlar (Quick Presets) -->
                    <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;margin-bottom:0.45rem;">
                      <span style="font-size:0.72rem;color:var(--text-muted);margin-right:2px;" data-i18n="theme_presets_label">Önayarlar:</span>
                      <button type="button" class="chip" onclick="applyOpacityPreset(35)" style="padding:2px 8px;font-size:0.72rem;" data-i18n="theme_preset_glass">%35 Cam</button>
                      <button type="button" class="chip" onclick="applyOpacityPreset(60)" style="padding:2px 8px;font-size:0.72rem;" data-i18n="theme_preset_trans">%60 Şeffaf</button>
                      <button type="button" class="chip" onclick="applyOpacityPreset(85)" style="padding:2px 8px;font-size:0.72rem;" data-i18n="theme_preset_balanced">%85 Dengeli</button>
                      <button type="button" class="chip" onclick="applyOpacityPreset(100)" style="padding:2px 8px;font-size:0.72rem;" data-i18n="theme_preset_solid">%100 Katı</button>
                    </div>

                    <div style="font-size:0.75rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_opacity_all_desc">
                      Tüm arkaplanlı temalarda (Ayıcık, Deniz, Doğa, Kalpli ve Özel) panellerin şeffaflık seviyesini gerçek zamanlı ayarlar.
                    </div>
                  </div>

                  <!-- Özel Görsel Yükleme & Durum Kutusu -->
                  <div style="border-left:1px solid var(--border-color);padding-left:1.25rem;">
                    <div style="font-size:0.875rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                      <span data-i18n="theme_custom_box_title">Özel Görsel Yükle</span>
                      <div style="display:flex;align-items:center;gap:0.4rem;">
                        <input type="file" id="custom-wallpaper-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="handleWallpaperUpload(this)">
                        <button type="button" class="btn-primary" onclick="document.getElementById('custom-wallpaper-input').click()" style="padding:0.35rem 0.85rem;font-size:0.8rem;">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                          <span data-i18n="theme_upload_bg_btn">Görsel Seç</span>
                        </button>
                        <button type="button" id="btn-remove-wallpaper" class="btn-icon" onclick="removeCustomWallpaper()" title="Özel Arkaplanı Kaldır" data-i18n-title="theme_remove_bg_btn" style="<?= empty($custom_wallpaper) ? 'display:none;' : '' ?>color:var(--danger);border-color:rgba(239,68,68,0.3);width:30px;height:30px;">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                      </div>
                    </div>

                    <div id="custom-wallpaper-preview-wrap" style="display:flex;align-items:center;gap:0.85rem;background:rgba(255,255,255,0.03);padding:0.65rem 0.85rem;border:1px solid var(--border-color);">
                      <div id="custom-wallpaper-thumb" style="width:58px;height:38px;border:1px solid var(--border-color);background-size:cover;background-position:center;background-repeat:no-repeat;flex-shrink:0;background-image:<?= !empty($custom_wallpaper) ? "url('{$custom_wallpaper}')" : 'none' ?>;background-color:var(--bg-hover);display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
                        <?= empty($custom_wallpaper) ? '🖼️' : '' ?>
                      </div>
                      <div style="flex:1;min-width:0;">
                        <div id="custom-wallpaper-status-text" style="font-size:0.825rem;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                          <?= !empty($custom_wallpaper) ? 'Özel görsel aktif' : 'Özel görsel henüz yüklenmedi' ?>
                        </div>
                        <div style="font-size:0.725rem;color:var(--text-muted);" data-i18n="theme_custom_format_hint">JPG, PNG, WEBP veya GIF (Maks. 12MB)</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- OTOMATİK / SİSTEM MODU -->
            <div>
              <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.85rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                <h4 style="font-size:0.95rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-primary);margin:0;" data-i18n="theme_auto_title">Otomatik Algılama</h4>
              </div>
              <div class="theme-options-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:1rem;">
                <div class="theme-card-option" id="theme-opt-system" data-theme-choice="system" onclick="selectThemeChoice('system')">
                  <div class="theme-badge-check">&#10003;</div>
                  <div class="theme-preview-box theme-preview-system">
                    <div class="p-side"></div>
                    <div class="p-main">
                      <div class="p-bar pri"></div>
                      <div class="p-bar" style="width:80%;"></div>
                      <div class="p-bar" style="width:65%;"></div>
                    </div>
                  </div>
                  <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.25rem;" data-i18n="theme_system_name">Sistem Teması</div>
                  <div style="font-size:0.775rem;color:var(--text-muted);line-height:1.4;" data-i18n="theme_system_desc">İşletim sisteminizin açık veya koyu moduna otomatik uyum sağlar</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Password Change Tab -->
        <div id="subtab-password" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">
              <div class="brand-icon" style="width:38px;height:38px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </div>
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;" data-i18n="pwd_change_heading">E-Posta Şifresi Değiştir</h3>
                <div style="color:var(--text-muted);font-size:0.8rem;" data-i18n="pwd_change_sub">HestiaCP e-posta kutusu şifrenizi güvenle güncelleyin</div>
              </div>
            </div>

            <form onsubmit="handlePasswordChange(event)" style="margin-top:1.5rem;max-width:480px;">
              <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" data-i18n="pwd_account_label">E-Posta Hesabı</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($active_account ? $active_account->email : '') ?>" readonly disabled style="opacity:0.85;background:var(--bg-hover);">
              </div>

              <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" data-i18n="settings_current_password">Mevcut Şifre *</label>
                <div style="position:relative;">
                  <input type="password" id="pwd-current" class="form-control" placeholder="Mevcut posta şifreniz" data-i18n-placeholder="pwd_current_ph" required style="padding-right:2.5rem;">
                  <button type="button" onclick="toggleInputVisibility('pwd-current', this)" title="Göster / Gizle" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>

              <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" data-i18n="settings_new_password">Yeni Şifre *</label>
                <div style="position:relative;">
                  <input type="password" id="pwd-new" class="form-control" placeholder="En az 8 karakter (Büyük, küçük harf, rakam)" data-i18n-placeholder="pwd_new_ph" required minlength="8" style="padding-right:2.5rem;">
                  <button type="button" onclick="toggleInputVisibility('pwd-new', this)" title="Göster / Gizle" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.35rem;" data-i18n="pwd_policy_hint">* Sunucu kuralı: En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.</div>
              </div>

              <div class="form-group" style="margin-bottom:1.5rem;">
                <label class="form-label" data-i18n="settings_new_password_confirm">Yeni Şifre (Tekrar) *</label>
                <div style="position:relative;">
                  <input type="password" id="pwd-confirm" class="form-control" placeholder="Yeni şifreyi doğrulayın" data-i18n-placeholder="pwd_confirm_ph" required minlength="8" style="padding-right:2.5rem;">
                  <button type="button" onclick="toggleInputVisibility('pwd-confirm', this)" title="Göster / Gizle" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:1rem;">
                <button type="submit" id="btn-change-pwd" class="btn-send" style="padding:0.75rem 1.75rem;font-weight:700;">
                  <span data-i18n="pwd_btn_change">Şifreyi Değiştir</span>
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Filters & Rules Tab -->
        <div id="subtab-filters" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;margin-bottom:1.5rem;border-radius:0 !important;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;" data-i18n="filters_heading">E-Posta Filtreleri & Otomatik Yönlendirme</h3>
                <p style="color:var(--text-muted);font-size:0.85rem;" data-i18n="filters_heading_sub">Gelen e-postaları konu, gönderen veya içeriğe göre otomatik olarak klasörlere taşıyın veya etiketleyin.</p>
              </div>
              <div style="display:flex;gap:0.75rem;">
                <button type="button" class="btn-send" style="background:var(--bg-surface-elevated);border:1px solid var(--border-color);color:var(--text-primary);" onclick="triggerRunFilters()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                  <span data-i18n="filters_run_now_btn">Filtreleri Şimdi Çalıştır</span>
                </button>
                <button type="button" class="btn-send" onclick="openFilterModal()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span data-i18n="filters_create_btn">Yeni Filtre Oluştur</span>
                </button>
              </div>
            </div>

            <!-- Filter list table -->
            <div id="filters-list-container">
              <div style="padding:2rem;text-align:center;color:var(--text-muted);" data-i18n="filter_loading">Filtreler yükleniyor...</div>
            </div>
          </div>
        </div>

        <!-- Folders Management Tab (Klasör Yönetimi) -->
        <div id="subtab-folders" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;margin-bottom:1.5rem;border-radius:0 !important;padding:1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;" data-i18n="settings_folders_title">Klasör Yönetimi & Yeniden Adlandırma</h3>
                <p style="color:var(--text-muted);font-size:0.85rem;" data-i18n="settings_folders_sub">E-postalarınızı gruplamak için klasörler oluşturun, mevcut klasörlerin isimlerini ve renklerini düzenleyin.</p>
              </div>
              <button type="button" class="btn-send" onclick="openNewFolderModal()" style="display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 1.15rem;font-size:0.85rem;border-radius:0 !important;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span data-i18n="new_folder">Yeni Klasör Ekle</span>
              </button>
            </div>

            <!-- Folders Table Container -->
            <div id="settings-folders-table-wrap" style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                <thead>
                  <tr style="border-bottom:2px solid var(--border-color);text-align:left;color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <th style="padding:0.75rem 0.6rem;width:40px;">Renk</th>
                    <th style="padding:0.75rem 0.6rem;">Klasör Adı</th>
                    <th style="padding:0.75rem 0.6rem;">Sunucu IMAP Yolu</th>
                    <th style="padding:0.75rem 0.6rem;text-align:center;">Okunmamış / Toplam</th>
                    <th style="padding:0.75rem 0.6rem;text-align:right;">İşlemler</th>
                  </tr>
                </thead>
                <tbody id="settings-folders-tbody">
                  <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted);">Klasörler yükleniyor...</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Trash Folder Retention & Auto-Clean Policy Card -->
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;padding:1.5rem;margin-top:1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;display:flex;align-items:center;gap:0.5rem;" data-i18n="settings_trash_retention_title">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--danger, #ef4444);"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  <span>Otomatik Çöp Kutusu Temizliği (30 Gün)</span>
                </h3>
                <p style="color:var(--text-muted);font-size:0.85rem;" data-i18n="settings_trash_retention_sub">Belirlenen saklama süresini aşan e-postaları çöp kutusundan otomatik olarak kalıcı siler.</p>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" class="btn-send" onclick="triggerCleanTrashNow(document.getElementById('setting-trash-retention-days')?.value || 30)" style="display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 1.15rem;font-size:0.85rem;border-radius:0 !important;background:var(--danger,#ef4444);border-color:var(--danger,#ef4444);">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  <span>Şimdi Temizle</span>
                </button>
              </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:1.25rem;">
              <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
                <div>
                  <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.25rem;">Otomatik Çöp Kutusu Temizlemeyi Aç</div>
                  <div style="font-size:0.8rem;color:var(--text-muted);">Açık olduğunda (varsayılan açık), belirlenen süreden daha eski silinmiş postalar çöp kutusundan kalıcı olarak temizlenir.</div>
                </div>
                <label style="position:relative;display:inline-block;width:44px;height:24px;cursor:pointer;flex-shrink:0;margin-left:1rem;">
                  <input type="checkbox" id="setting-auto-clean-trash" <?= ($auto_clean_trash) ? 'checked' : '' ?> onchange="saveTrashRetentionSettings(true)" style="opacity:0;width:0;height:0;">
                  <span class="custom-toggle-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#475569;transition:.3s;border-radius:24px;"></span>
                </label>
              </div>

              <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
                <div>
                  <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.25rem;">Saklama Süresi (Varsayılan: 30 Gün)</div>
                  <div style="font-size:0.8rem;color:var(--text-muted);">Çöp kutusunda bu gün sayısından daha eski olan e-postalar otomatik olarak kalıcı silinir.</div>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;margin-left:1rem;">
                  <input type="number" id="setting-trash-retention-days" class="form-control" min="1" max="365" value="<?= htmlspecialchars((string)$trash_retention_days) ?>" style="width:85px;text-align:center;height:38px;border-radius:0 !important;font-weight:700;font-size:0.95rem;" onchange="saveTrashRetentionSettings(true)">
                  <span style="font-size:0.85rem;color:var(--text-muted);font-weight:600;">gün</span>
                </div>
              </div>

              <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.8rem;color:var(--text-muted);padding:0 0.25rem;flex-wrap:wrap;gap:0.5rem;">
                <span id="trash-clean-status-info">Durum: <?= ($auto_clean_trash) ? 'Otomatik temizleme aktif (' . (int)$trash_retention_days . ' gün)' : 'Otomatik temizleme kapalı' ?></span>
                <button type="button" class="btn-primary" onclick="saveTrashRetentionSettings(true)" style="height:34px;padding:0 1.25rem;font-size:0.825rem;border-radius:0 !important;display:inline-flex;align-items:center;gap:6px;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  <span>Ayarları Kaydet</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Spam Folder Retention & Auto-Clean Policy Card -->
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;padding:1.5rem;margin-top:1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;display:flex;align-items:center;gap:0.5rem;" data-i18n="settings_spam_retention_title">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#f59e0b;"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <span>Otomatik Spam (İstenmeyen) Temizliği (30 Gün)</span>
                </h3>
                <p style="color:var(--text-muted);font-size:0.85rem;" data-i18n="settings_spam_retention_sub">Belirlenen saklama süresini aşan e-postaları spam/istenmeyen klasöründen otomatik olarak kalıcı siler.</p>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" class="btn-send" onclick="triggerCleanSpamNow(document.getElementById('setting-spam-retention-days')?.value || 30)" style="display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 1.15rem;font-size:0.85rem;border-radius:0 !important;background:#d97706;border-color:#d97706;">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  <span>Şimdi Temizle</span>
                </button>
              </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:1.25rem;">
              <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
                <div>
                  <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.25rem;">Otomatik Spam Temizlemeyi Aç</div>
                  <div style="font-size:0.8rem;color:var(--text-muted);">Açık olduğunda (varsayılan açık), belirlenen süreden daha eski spam postalar kalıcı olarak temizlenir.</div>
                </div>
                <label style="position:relative;display:inline-block;width:44px;height:24px;cursor:pointer;flex-shrink:0;margin-left:1rem;">
                  <input type="checkbox" id="setting-auto-clean-spam" <?= ($auto_clean_spam) ? 'checked' : '' ?> onchange="saveSpamRetentionSettings(true)" style="opacity:0;width:0;height:0;">
                  <span class="custom-toggle-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#475569;transition:.3s;border-radius:24px;"></span>
                </label>
              </div>

              <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
                <div>
                  <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.25rem;">Saklama Süresi (Varsayılan: 30 Gün)</div>
                  <div style="font-size:0.8rem;color:var(--text-muted);">Spam kutusunda bu gün sayısından daha eski olan e-postalar otomatik olarak kalıcı silinir.</div>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;margin-left:1rem;">
                  <input type="number" id="setting-spam-retention-days" class="form-control" min="1" max="365" value="<?= htmlspecialchars((string)$spam_retention_days) ?>" style="width:85px;text-align:center;height:38px;border-radius:0 !important;font-weight:700;font-size:0.95rem;" onchange="saveSpamRetentionSettings(true)">
                  <span style="font-size:0.85rem;color:var(--text-muted);font-weight:600;">gün</span>
                </div>
              </div>

              <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.8rem;color:var(--text-muted);padding:0 0.25rem;flex-wrap:wrap;gap:0.5rem;">
                <span id="spam-clean-status-info">Durum: <?= ($auto_clean_spam) ? 'Otomatik temizleme aktif (' . (int)$spam_retention_days . ' gün)' : 'Otomatik temizleme kapalı' ?></span>
                <button type="button" class="btn-primary" onclick="saveSpamRetentionSettings(true)" style="height:34px;padding:0 1.25rem;font-size:0.825rem;border-radius:0 !important;display:inline-flex;align-items:center;gap:6px;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  <span>Ayarları Kaydet</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Signature Tab (İmza Ayarları) -->
        <div id="subtab-signature" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;" data-i18n="sig_heading">E-Posta İmzası Yönetimi</h3>
                <div style="color:var(--text-muted);font-size:0.85rem;">
                  <strong><?= htmlspecialchars($active_account ? $active_account->email : '') ?></strong> hesabı için giden e-postalara otomatik eklenecek profesyonel imzanızı oluşturun.
                </div>
              </div>
              <button type="button" class="btn-send" onclick="saveSignature(event)" id="btn-save-sig" style="padding:0.6rem 1.4rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span data-i18n="signature_save">İmzayı Kaydet</span>
              </button>
            </div>

            <!-- Quick Template Shortcuts -->
            <div style="margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
              <span style="font-size:0.8rem;color:var(--text-muted);font-weight:600;" data-i18n="sig_tpl_label">Hazır Şablonlar:</span>
              <button type="button" class="signature-tpl-btn" onclick="applySignatureTemplate('simple')">
                <span data-i18n="sig_tpl_simple">📄 Sade &amp; Klasik</span>
              </button>
              <button type="button" class="signature-tpl-btn" onclick="applySignatureTemplate('corporate')">
                <span data-i18n="sig_tpl_corporate">🏢 Kurumsal &amp; Çizgili</span>
              </button>
              <button type="button" class="signature-tpl-btn" onclick="applySignatureTemplate('minimal')">
                <span data-i18n="sig_tpl_minimal">✨ Minimal</span>
              </button>
              <button type="button" class="signature-tpl-btn" onclick="clearSignatureEditor()">
                <span data-i18n="sig_tpl_clear">🗑️ Temizle</span>
              </button>
            </div>

            <!-- Signature Editor Toolbar -->
            <div class="editor-toolbar" style="border:1px solid var(--border-color);border-bottom:none;">
              <button type="button" class="tool-btn" title="Kalın" onclick="formatSig('bold')"><strong>B</strong></button>
              <button type="button" class="tool-btn" title="İtalik" onclick="formatSig('italic')"><em>I</em></button>
              <button type="button" class="tool-btn" title="Altı Çizili" onclick="formatSig('underline')"><u>U</u></button>
              <div class="tool-divider"></div>
              <label class="tool-color-label" title="Yazı Rengi">
                <span style="font-weight:700;font-size:0.85rem;color:var(--text-primary);border-bottom:2px solid var(--primary);line-height:1;">A</span>
                <input type="color" value="#0f172a" onchange="formatSig('foreColor', this.value)">
              </label>
              <div class="tool-divider"></div>
              <button type="button" class="tool-btn" title="Link Ekle" onclick="insertSigLink()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
              <button type="button" class="tool-btn" title="Biçimi Temizle" onclick="formatSig('removeFormat')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>

            <!-- Signature Contenteditable Area -->
            <div id="sig-editor" class="compose-editor" contenteditable="true" oninput="updateSigLivePreview()" style="min-height:160px;max-height:260px;border:1px solid var(--border-color);background:var(--bg-surface);" placeholder="İmza metninizi veya HTML içeriğinizi buraya girin..."><?= !empty($active_account->signature) ? $active_account->signature : '' ?></div>

            <!-- Live Preview -->
            <div style="margin-top:1.5rem;">
              <div style="font-size:0.85rem;font-weight:700;margin-bottom:0.5rem;color:var(--text-secondary);display:flex;align-items:center;gap:0.4rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span data-i18n="sig_live_preview">Canlı Önizleme (Alıcıların Göreceği Hali):</span>
              </div>
              <div class="signature-preview-card" id="sig-live-preview">
                <div style="color:var(--text-muted);font-size:0.8rem;margin-bottom:0.75rem;">--</div>
                <div id="sig-preview-content"><?= !empty($active_account->signature) ? $active_account->signature : '<span style="color:var(--text-muted);font-style:italic;" data-i18n="sig_not_defined_yet">Henüz imza tanımlanmadı.</span>' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Languages & Translations Tab -->
        <div id="subtab-languages" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;background:var(--bg-surface);border:1px solid var(--border-color);padding:1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);flex-wrap:wrap;">
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;color:var(--text-primary);" data-i18n="settings_lang_manager_title">Dil Yönetimi &amp; Çeviri Editörü</h3>
                <p style="color:var(--text-muted);font-size:0.825rem;margin:0;" data-i18n="settings_lang_manager_sub">Sistem dillerini düzenleyin, yeni dil ekleyin veya JSON formatında dil dosyası yükleyip dışa aktarın.</p>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" class="btn-secondary" onclick="openNewLanguageModal()" style="height:36px;padding:0 0.95rem;font-size:0.825rem;font-weight:600;">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span data-i18n="settings_btn_new_lang">Yeni Dil Ekle</span>
                </button>
                <button type="button" class="btn-send" onclick="triggerLanguageUpload()" style="height:36px;padding:0 1rem;font-size:0.825rem;">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span data-i18n="settings_btn_upload_lang">JSON Dil Dosyası Yükle</span>
                </button>
                <input type="file" id="lang-file-upload-input" accept=".json" style="display:none;" onchange="handleLanguageFileUpload(event)">
              </div>
            </div>

            <!-- Installed Languages Table -->
            <div style="overflow-x:auto;">
              <table class="lang-manager-table">
                <thead>
                  <tr>
                    <th style="width:70px;text-align:center;" data-i18n="settings_th_flag">Bayrak</th>
                    <th data-i18n="settings_th_name">Dil Adı</th>
                    <th style="width:110px;" data-i18n="settings_th_code">Dil Kodu</th>
                    <th style="width:130px;" data-i18n="settings_th_keys">Kelime / Anahtar</th>
                    <th style="width:120px;" data-i18n="settings_th_status">Durum</th>
                    <th style="width:130px;text-align:right;" data-i18n="settings_th_actions">İşlemler</th>
                  </tr>
                </thead>
                <tbody id="lang-manager-table-body">
                  <tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--text-muted);" data-i18n="lang_loading">Diller yükleniyor...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Email Import & Migration Tab -->
        <div id="subtab-import" style="display:none;">
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;background:var(--bg-surface);border:1px solid var(--border-color);padding:1.5rem;margin-bottom:1.5rem;">
            <!-- Header -->
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color);flex-wrap:wrap;">
              <div style="width:48px;height:48px;background:rgba(79,70,229,0.12);color:var(--primary);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
              </div>
              <div>
                <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:0.25rem;color:var(--text-primary);" data-i18n="import_title">Diğer E-Posta Hesabından İçe Aktar (Mail Migration)</h3>
                <p style="color:var(--text-muted);font-size:0.825rem;margin:0;" data-i18n="import_subtitle">Eski hostinginizdeki veya harici bir IMAP sunucusundaki tüm e-postaları bu hesaba aktarın.</p>
              </div>
            </div>

            <!-- Provider Preset -->
            <div style="margin-bottom:1.5rem;">
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                <button type="button" class="chip active import-preset-btn" data-preset="custom" onclick="applyImportPreset('custom', this)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                  <span>Özel IMAP Sunucusu</span>
                </button>
              </div>
            </div>

            <!-- Provider Specific Tips Box -->
            <div id="import-provider-tip" style="display:none;margin-bottom:1.5rem;padding:0.75rem 1rem;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);font-size:0.8rem;color:var(--text-primary);line-height:1.5;">
              <div style="font-weight:700;display:flex;align-items:center;gap:0.4rem;color:#d97706;margin-bottom:0.25rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="import-tip-title">Önemli Bilgilendirme</span>
              </div>
              <div id="import-tip-desc"></div>
            </div>

            <form id="form-email-import" onsubmit="event.preventDefault();">
              <!-- Connection Fields -->
              <div style="margin-bottom:1.5rem;">
                <label style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);display:block;margin-bottom:0.6rem;">Kaynak Posta Kutusu Giriş Bilgileri (IMAP)</label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:1rem;">
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Kaynak E-Posta Adresi *</label>
                    <input type="email" id="imp-email" class="form-control" placeholder="Örn: adiniz@digerfirma.com veya adiniz@alanadi.com" required style="border-radius:0 !important;font-size:0.875rem;">
                  </div>
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Şifre / Parola *</label>
                    <div style="position:relative;">
                      <input type="password" id="imp-password" class="form-control" placeholder="••••••••••••" required style="border-radius:0 !important;font-size:0.875rem;padding-right:2.5rem;">
                      <button type="button" onclick="toggleImportPassVisibility()" title="Şifreyi Göster" style="position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;">
                        <svg id="imp-eye-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                    </div>
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1rem;margin-top:0.75rem;">
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">IMAP Sunucu Adresi (Host) *</label>
                    <input type="text" id="imp-host" class="form-control" placeholder="mail.alanadiniz.com veya imap.sunucu.com" required style="border-radius:0 !important;font-size:0.875rem;">
                  </div>
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Port</label>
                    <input type="number" id="imp-port" class="form-control" value="993" style="border-radius:0 !important;font-size:0.875rem;">
                  </div>
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Şifreleme (SSL/TLS)</label>
                    <select id="imp-enc" class="form-control" style="border-radius:0 !important;font-size:0.875rem;">
                      <option value="ssl" selected>SSL / TLS (993)</option>
                      <option value="tls">STARTTLS (143)</option>
                      <option value="none">Şifresiz (143)</option>
                    </select>
                  </div>
                </div>
              </div>

              <!-- Options Grid -->
              <div style="margin-bottom:1.5rem;padding:1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:0 !important;">
                <label style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);display:block;margin-bottom:0.75rem;">Aktarım Tercihleri &amp; Klasör Hedefi</label>
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:1rem;margin-bottom:1rem;">
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Hangi Klasör Aktarılsın?</label>
                    <select id="imp-source-folder" class="form-control" style="border-radius:0 !important;font-size:0.85rem;">
                      <option value="ALL">📦 Tüm Klasörler (Gelen, Gönderilen, Arşiv vb.)</option>
                      <option value="INBOX">📥 Sadece Gelen Kutusu (INBOX)</option>
                    </select>
                    <small style="color:var(--text-muted);font-size:0.75rem;">Bağlantı test edildikten sonra sunucudaki diğer klasörler otomatik listelenir.</small>
                  </div>

                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Hedef Klasör Yapısı</label>
                    <select id="imp-target-mode" class="form-control" style="border-radius:0 !important;font-size:0.85rem;" onchange="toggleCustomTargetInput(this.value)">
                      <option value="same">📂 Klasörleri Birebir Eşleştir (INBOX -> Gelen, Sent -> Gönderilen)</option>
                      <option value="custom">📁 Özel Bir Arşiv Klasörünün Altına Topla</option>
                    </select>
                    <div id="imp-custom-target-wrap" style="display:none;margin-top:0.4rem;">
                      <input type="text" id="imp-custom-target" class="form-control" value="Eski_Postalar" placeholder="Hedef Klasör Adı" style="border-radius:0 !important;font-size:0.85rem;">
                    </div>
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:1rem;">
                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Tarih Filtresi</label>
                    <select id="imp-date-filter" class="form-control" style="border-radius:0 !important;font-size:0.85rem;">
                      <option value="all" selected>Tüm E-Postalar (Tüm Zamanlar)</option>
                      <option value="30d">Son 30 Günün E-Postaları</option>
                      <option value="90d">Son 90 Gün (3 Ay)</option>
                      <option value="180d">Son 6 Ay</option>
                      <option value="365d">Son 1 Yıl</option>
                    </select>
                  </div>

                  <div class="form-group">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600;">Maksimum E-Posta Limiti (Test/Kota)</label>
                    <select id="imp-limit-count" class="form-control" style="border-radius:0 !important;font-size:0.85rem;">
                      <option value="0" selected>Tümü (Limitsiz Aktarım)</option>
                      <option value="50">İlk 50 E-posta (Hızlı Deneme)</option>
                      <option value="100">İlk 100 E-posta</option>
                      <option value="500">İlk 500 E-posta</option>
                      <option value="1000">İlk 1.000 E-posta</option>
                    </select>
                  </div>

                  <div class="form-group" style="display:flex;align-items:flex-end;">
                    <label style="display:inline-flex;align-items:center;gap:0.45rem;cursor:pointer;font-size:0.825rem;font-weight:600;margin-bottom:0.4rem;user-select:none;">
                      <input type="checkbox" id="imp-skip-duplicates" checked style="accent-color:var(--primary);width:16px;height:16px;">
                      <span>Mükerrer e-postaları atla (Message-ID)</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Action Buttons -->
              <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <button type="button" id="btn-test-import" class="btn-secondary" onclick="testImportConnection()" style="height:42px;padding:0 1.25rem;font-weight:600;display:inline-flex;align-items:center;gap:0.5rem;border-radius:0 !important;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <span>Bağlantıyı Test Et &amp; Tara</span>
                </button>

                <button type="button" id="btn-start-import" class="btn-send" onclick="startEmailImport()" style="height:42px;padding:0 1.5rem;font-weight:700;display:inline-flex;align-items:center;gap:0.5rem;border-radius:0 !important;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  <span>İçe Aktarmayı Başlat</span>
                </button>
              </div>

              <!-- Discovered Folders Preview Box (Hidden initially) -->
              <div id="import-discovery-box" style="display:none;margin-top:1.5rem;padding:1.25rem;background:var(--bg-surface-elevated);border:1px solid rgba(16,185,129,0.3);border-left:4px solid #10b981;border-radius:0 !important;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
                  <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span style="color:#10b981;font-weight:700;font-size:0.95rem;">✓ Bağlantı Başarılı</span>
                    <span id="import-discovery-summary" style="font-size:0.85rem;color:var(--text-muted);"></span>
                  </div>
                  <span id="import-discovery-badge" class="badge" style="background:#10b981;color:#fff;font-weight:700;padding:3px 10px;font-size:0.8rem;border-radius:0 !important;"></span>
                </div>
                <div id="import-folders-list" style="display:flex;gap:0.5rem;flex-wrap:wrap;max-height:160px;overflow-y:auto;padding-top:0.25rem;"></div>
              </div>

              <!-- Live Background Import Progress Box -->
              <div id="import-progress-box" style="display:none;margin-top:1.5rem;padding:1.25rem 1.5rem;background:var(--bg-surface-elevated);border:1px solid var(--primary);border-left:4px solid var(--primary);border-radius:0 !important;box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
                  <div style="display:flex;align-items:center;gap:0.6rem;">
                    <div id="import-spinner-icon" style="display:inline-block;width:18px;height:18px;border:3px solid var(--border-color);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                    <strong style="font-size:0.95rem;color:var(--text-primary);" id="import-progress-status">E-postalar arka planda aktarılıyor...</strong>
                    <span id="import-live-badge" class="badge" style="background:#10b981;color:#fff;font-size:0.75rem;font-weight:700;padding:2px 8px;border-radius:0 !important;">● Arka Planda Aktif</span>
                  </div>
                  <div>
                    <button type="button" class="btn-secondary" id="btn-cancel-import" onclick="cancelActiveImport()" style="height:30px;padding:0 0.85rem;font-size:0.75rem;color:var(--danger);border-color:var(--danger);border-radius:0 !important;cursor:pointer;">
                      ⏹️ Aktarımı Durdur
                    </button>
                  </div>
                </div>

                <div style="background:rgba(79,70,229,0.08);border:1px dashed var(--primary);padding:0.65rem 0.9rem;font-size:0.8rem;color:var(--text-primary);margin-bottom:0.85rem;display:flex;align-items:center;gap:0.5rem;">
                  <span style="font-size:1.1rem;">🛡️</span>
                  <span><strong>Sayfayı veya tarayıcınızı güvenle kapatabilirsiniz:</strong> Bu işlem sunucu arka planında bağımsız olarak çalışmaktadır. Sayfayı kapatsanız dahi aktarım kesintisiz devam edecektir.</span>
                </div>

                <!-- Progress Bar -->
                <div style="width:100%;height:10px;background:var(--border-color);overflow:hidden;position:relative;margin-bottom:0.6rem;">
                  <div id="import-progress-bar" style="width:0%;height:100%;background:linear-gradient(90deg, var(--primary) 0%, #818cf8 100%);transition:width 0.4s ease;"></div>
                </div>

                <div style="font-size:0.8rem;color:var(--text-muted);display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;" id="import-progress-sub">
                  <span id="import-stat-details">Sunucuya bağlanılıyor ve kuyruk hazırlanıyor...</span>
                  <span id="import-stat-percent" style="font-weight:700;color:var(--primary);font-size:0.9rem;">%0</span>
                </div>
              </div>
            </form>
          </div>

          <!-- History / Log Card -->
          <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;background:var(--bg-surface);border:1px solid var(--border-color);padding:1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
              <h4 style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:0;display:flex;align-items:center;gap:0.5rem;" data-i18n="import_history_title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Son Yapılan E-Posta Aktarımları (Geçmiş)
              </h4>
              <button type="button" class="btn-icon" onclick="loadImportHistory()" title="Geçmişi Yenile" style="width:28px;height:28px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              </button>
            </div>
            <div style="overflow-x:auto;">
              <table class="lang-manager-table">
                <thead>
                  <tr>
                    <th style="width:160px;">Tarih</th>
                    <th>Kaynak E-Posta</th>
                    <th>Sunucu</th>
                    <th style="width:140px;">Kaynak Klasör</th>
                    <th style="width:140px;">Hedef Klasör</th>
                    <th style="width:110px;text-align:center;">Aktarılan</th>
                    <th style="width:100px;text-align:center;">Durum</th>
                  </tr>
                </thead>
                <tbody id="import-history-table-body">
                  <tr><td colspan="7" style="text-align:center;padding:1.5rem;color:var(--text-muted);">Henüz bir aktarım yapılmadı.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- SECTION 7: ADMIN MANAGEMENT PANEL -->
      <?php if (!empty($active_account->is_admin)): ?>
      <div id="view-admin" class="page-container" style="display:none;width:100%;max-width:100%;box-sizing:border-box;">
        <!-- Header Row -->
        <div class="page-header-row" style="margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
          <div>
            <div style="display:flex;align-items:center;gap:0.6rem;">
              <span style="font-size:1.6rem;line-height:1;">👑</span>
              <h1 class="page-title" style="margin:0;font-size:1.5rem;">Yönetim Paneli & Kurumsal Hesaplar</h1>
            </div>
            <div class="page-subtitle" style="margin-top:0.35rem;color:var(--text-secondary);">
              Tüm kurumsal e-posta hesaplarını görüntüleyin, yeni adresler tanımlayın ve yönetici yetkilerini atayın.
            </div>
          </div>
          <div style="display:flex;gap:0.65rem;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary" onclick="openBrandingModal()" style="padding:0.55rem 0.95rem;display:inline-flex;align-items:center;gap:0.4rem;background:var(--bg-surface);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.85rem;" title="Sol Menüdeki 'Hestia Mail - Web Suite' Başlığını ve Sloganını Değiştir">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              🏷️ Başlık & İsim Ayarları
            </button>
            <button type="button" class="btn btn-secondary" onclick="openHestiaConfigModal()" style="padding:0.55rem 0.95rem;display:inline-flex;align-items:center;gap:0.4rem;background:var(--bg-surface);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.85rem;" title="HestiaCP API Host, Kullanıcı ve Access Key Ayarları">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              ⚙️ HestiaCP API Ayarları
            </button>
            <button type="button" class="btn btn-secondary" id="btn-sync-hestia" onclick="syncHestiaAccounts()" style="padding:0.55rem 0.95rem;display:inline-flex;align-items:center;gap:0.4rem;background:var(--bg-surface);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.85rem;" title="HestiaCP sunucusundaki tüm posta kutularını çek ve senkronize et">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              <span id="sync-hestia-text">🔄 Hestia'dan Çek</span>
            </button>
            <button type="button" class="btn btn-primary" onclick="openAdminCreateModal()" style="padding:0.55rem 1.15rem;display:inline-flex;align-items:center;gap:0.45rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:0.85rem;font-weight:600;box-shadow:var(--shadow-sm);">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Yeni E-Posta Hesabı Aç
            </button>
          </div>
        </div>

        <!-- HestiaCP Connection Alert Banner -->
        <div id="admin-hestia-alert" style="display:none;margin-bottom:1.25rem;padding:0.85rem 1.15rem;border-radius:0;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:1rem;background:var(--bg-hover);">
          <div style="display:flex;align-items:center;gap:0.75rem;">
            <span id="admin-hestia-alert-icon" style="font-size:1.25rem;">⚠️</span>
            <div>
              <div id="admin-hestia-alert-title" style="font-weight:600;font-size:0.875rem;color:var(--text-primary);">HestiaCP REST API Yapılandırması Gerekli</div>
              <div id="admin-hestia-alert-desc" style="font-size:0.775rem;color:var(--text-secondary);margin-top:0.15rem;">Sunucudaki tüm posta kutularını canlı çekmek ve disk kotalarını anlık izlemek için API anahtarınızı tanımlayın.</div>
            </div>
          </div>
          <button type="button" id="admin-hestia-alert-btn" class="btn btn-secondary" onclick="openHestiaConfigModal()" style="padding:0.45rem 0.95rem;font-size:0.8rem;white-space:nowrap;background:var(--bg-surface);border:1px solid var(--border-color);cursor:pointer;color:var(--text-primary);font-weight:600;">
            API Ayarlarını Yap
          </button>
        </div>

        <!-- Stats Cards Grid -->
        <div class="admin-stats-grid">
          <div class="admin-stat-card">
            <div class="admin-stat-icon">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="admin-stat-value" id="admin-stat-total-accounts">--</div>
              <div class="admin-stat-label">Toplam E-Posta Hesabı</div>
            </div>
          </div>

          <div class="admin-stat-card">
            <div class="admin-stat-icon gold">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="admin-stat-value" id="admin-stat-total-admins" style="color:#f59e0b;">--</div>
              <div class="admin-stat-label">Yönetici (Admin) Sayısı</div>
            </div>
          </div>

          <div class="admin-stat-card">
            <div class="admin-stat-icon emerald">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="admin-stat-value" id="admin-stat-total-disk" style="font-size:1.2rem;color:var(--success);">--</div>
              <div class="admin-stat-label">Hestia Toplam Disk Kullanımı</div>
            </div>
          </div>

          <div class="admin-stat-card">
            <div class="admin-stat-icon blue">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="admin-stat-value" id="admin-stat-server-status" style="font-size:0.95rem;color:var(--text-primary);">Kontrol ediliyor...</div>
              <div class="admin-stat-label">HestiaCP API Durumu</div>
            </div>
          </div>
        </div>

        <!-- Main Table Card -->
        <div class="card" style="width:100%;max-width:100%;box-sizing:border-box;border-radius:0 !important;background:var(--bg-surface);border:1px solid var(--border-color);padding:1.5rem;">
          
          <!-- Filter & Search Toolbar -->
          <div class="admin-actions-bar">
            <div class="admin-search-wrapper">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="admin-search-input" placeholder="İsim veya e-posta adresiyle filtrele..." oninput="handleAdminSearchInput()">
            </div>

            <div class="admin-filter-group">
              <span style="font-size:0.775rem;color:var(--text-secondary);font-weight:600;margin-right:0.35rem;">FİLTRE:</span>
              <button type="button" class="admin-filter-btn active" id="admin-filter-all" onclick="filterAdminRole('all', this)">Tümü (<span id="admin-count-all">0</span>)</button>
              <button type="button" class="admin-filter-btn" id="admin-filter-admin" onclick="filterAdminRole('admin', this)">👑 Yöneticiler (<span id="admin-count-admins">0</span>)</button>
              <button type="button" class="admin-filter-btn" id="admin-filter-user" onclick="filterAdminRole('user', this)">Kullanıcılar (<span id="admin-count-users">0</span>)</button>
            </div>
          </div>

          <!-- Accounts Table -->
          <div style="overflow-x:auto;">
            <table class="lang-manager-table" style="width:100%;">
              <thead>
                <tr>
                  <th style="width:230px;">Kullanıcı & E-Posta</th>
                  <th style="width:130px;text-align:center;">Yetki / Rol</th>
                  <th style="width:170px;">Disk & Kota (Hestia)</th>
                  <th style="width:120px;text-align:center;">Sunucu Durumu</th>
                  <th style="width:110px;">Tarih</th>
                  <th style="text-align:right;">İşlemler</th>
                </tr>
              </thead>
              <tbody id="admin-accounts-tbody">
                <tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-muted);">Hesaplar yükleniyor...</td></tr>
              </tbody>
            </table>
          </div>

        </div>
      </div>
      <?php endif; ?>
    </main>
  </div>

  <?php if (!empty($active_account->is_admin)): ?>
  <!-- ADMIN: CREATE EMAIL ACCOUNT MODAL -->
  <div id="admin-create-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:var(--bg-surface);width:520px;max-width:92vw;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.75rem;box-sizing:border-box;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);">
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <span style="font-size:1.35rem;">✉️</span>
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-primary);">Yeni E-Posta Hesabı Tanımla</h3>
        </div>
        <button type="button" class="btn-icon" onclick="closeAdminCreateModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
      </div>

      <form id="admin-create-account-form" onsubmit="submitAdminCreateAccount(event)">
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Ad Soyad / Görünen İsim *</label>
          <input type="text" id="admin-new-name" class="form-control" placeholder="Örn: Ahmet Yılmaz" required style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
        </div>

        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">E-Posta Adresi *</label>
          <div style="display:flex;align-items:center;">
            <input type="text" id="admin-new-user" class="form-control" placeholder="ahmet.yilmaz" required style="flex:1;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);border-right:none;color:var(--text-primary);box-sizing:border-box;">
            <span style="height:38px;padding:0 0.75rem;background:var(--bg-hover);border:1px solid var(--border-color);display:inline-flex;align-items:center;font-weight:600;font-size:0.85rem;color:var(--text-secondary);box-sizing:border-box;">@<?= htmlspecialchars(MAIL_DOMAIN) ?></span>
          </div>
          <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.25rem;">Tüm hesaplar kurumsal @<?= htmlspecialchars(MAIL_DOMAIN) ?> alan adına bağlı açılır.</div>
        </div>

        <div style="margin-bottom:1.25rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Şifre * (En az 6 karakter)</label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <div style="position:relative;flex:1;">
              <input type="password" id="admin-new-pass" class="form-control" placeholder="••••••••" required minlength="6" style="width:100%;height:38px;padding:0 2.25rem 0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
              <button type="button" onclick="togglePassVisibility('admin-new-pass', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Şifreyi Göster">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <button type="button" class="btn-secondary" onclick="generateAdminPass('admin-new-pass')" style="height:38px;padding:0 0.75rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.775rem;font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;gap:0.3rem;">
              🎲 Rastgele Üret
            </button>
          </div>
        </div>

        <div style="margin-bottom:1.5rem;background:var(--bg-hover);padding:0.85rem 1rem;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:1rem;">
          <div>
            <div style="font-weight:600;font-size:0.85rem;color:var(--text-primary);display:flex;align-items:center;gap:0.4rem;">
              <span>👑</span> Bu Kullanıcıyı Yönetici Yap
            </div>
            <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.15rem;">Yönetici paneline erişebilir, yeni mail açabilir ve diğer yetkileri düzenleyebilir.</div>
          </div>
          <label style="position:relative;display:inline-block;width:42px;height:24px;cursor:pointer;flex-shrink:0;">
            <input type="checkbox" id="admin-new-is-admin" style="opacity:0;width:0;height:0;">
            <span class="custom-toggle-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#475569;transition:.3s;border-radius:24px;"></span>
          </label>
        </div>
        <div style="margin-bottom:1.25rem;border:1px solid var(--border-color);background:var(--bg-hover);">
          <button type="button" onclick="const adv = document.getElementById('admin-create-advanced'); adv.style.display = adv.style.display === 'none' ? 'block' : 'none';" style="width:100%;padding:0.65rem 0.85rem;background:transparent;border:none;color:var(--text-primary);font-size:0.8rem;font-weight:600;display:flex;align-items:center;justify-content:space-between;cursor:pointer;">
            <span style="display:inline-flex;align-items:center;gap:0.4rem;">⚙️ Gelişmiş HestiaCP Ayarları (Kota, Alias, Yönlendirme)</span>
            <span style="font-size:0.75rem;color:var(--text-muted);">▼ Aç / Kapat</span>
          </button>
          <div id="admin-create-advanced" style="display:none;padding:0.85rem;border-top:1px solid var(--border-color);background:var(--bg-surface);">
            <div style="margin-bottom:0.75rem;">
              <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:0.25rem;color:var(--text-secondary);">Kota (MB cinsinden - 0 veya boş = Sınırsız):</label>
              <input type="number" id="admin-new-quota" class="form-control" placeholder="0 (Sınırsız)" style="width:100%;height:34px;padding:0 0.6rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            </div>
            <div style="margin-bottom:0.75rem;">
              <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:0.25rem;color:var(--text-secondary);">Takma Adlar (Domain olmadan yerel bölüm):</label>
              <input type="text" id="admin-new-aliases" class="form-control" placeholder="Örn: destek, bilgi" style="width:100%;height:34px;padding:0 0.6rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            </div>
            <div style="margin-bottom:0.75rem;">
              <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:0.25rem;color:var(--text-secondary);">Şuraya Yönlendir (E-posta adresi):</label>
              <input type="text" id="admin-new-forward" class="form-control" placeholder="yonlendirilecek@<?= htmlspecialchars(MAIL_DOMAIN) ?>" style="width:100%;height:34px;padding:0 0.6rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            </div>
            <div style="margin-bottom:0.75rem;">
              <label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.775rem;">
                <input type="checkbox" id="admin-new-forward-only" style="accent-color:var(--primary);">
                <span>Yönlendirilmiş postaları depolama (Yalnızca yönlendir)</span>
              </label>
            </div>
            <div>
              <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:0.25rem;color:var(--text-secondary);">Hız Sınırı (e-posta / saat):</label>
              <input type="number" id="admin-new-rate" class="form-control" placeholder="Sistem varsayılanı" style="width:100%;height:34px;padding:0 0.6rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            </div>
          </div>
        </div>

        <?php if (defined('HESTIA_API_HOST') && HESTIA_API_HOST && defined('HESTIA_API_KEY') && HESTIA_API_KEY): ?>
        <div style="margin-bottom:1.5rem;padding:0.65rem 0.85rem;background:rgba(16, 185, 129, 0.1);border:1px solid rgba(16, 185, 129, 0.25);color:var(--success);font-size:0.775rem;display:flex;align-items:center;gap:0.5rem;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          <span>HestiaCP API devrede: Posta kutusu sunucuda otomatik oluşturulacaktır.</span>
        </div>
        <?php endif; ?>

        <div style="display:flex;justify-content:flex-end;gap:0.65rem;">
          <button type="button" class="btn-secondary" onclick="closeAdminCreateModal()" style="height:36px;padding:0 1rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-secondary);cursor:pointer;font-size:0.85rem;">Vazgeç</button>
          <button type="submit" id="btn-submit-create-account" class="btn-primary" style="height:36px;padding:0 1.25rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.4rem;">
            Hesabı Oluştur
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADMIN: RESET / CHANGE PASSWORD MODAL -->
  <div id="admin-pass-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:var(--bg-surface);width:460px;max-width:92vw;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.75rem;box-sizing:border-box;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);">
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <span style="font-size:1.35rem;">🔑</span>
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-primary);">Şifre Değiştir</h3>
        </div>
        <button type="button" class="btn-icon" onclick="closeAdminPassModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
      </div>

      <form id="admin-change-pass-form" onsubmit="submitAdminPasswordChange(event)">
        <input type="hidden" id="admin-pass-target-id" value="">
        
        <div style="margin-bottom:1rem;background:var(--bg-hover);padding:0.75rem 1rem;border:1px solid var(--border-color);">
          <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;font-weight:600;">Hedef E-Posta Hesabı</div>
          <div id="admin-pass-target-email" style="font-weight:700;color:var(--text-primary);margin-top:0.2rem;font-size:0.95rem;">--</div>
        </div>

        <div style="margin-bottom:1.5rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Yeni Şifre * (En az 6 karakter)</label>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <div style="position:relative;flex:1;">
              <input type="password" id="admin-reset-pass" class="form-control" placeholder="••••••••" required minlength="6" style="width:100%;height:38px;padding:0 2.25rem 0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
              <button type="button" onclick="togglePassVisibility('admin-reset-pass', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Şifreyi Göster">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <button type="button" class="btn-secondary" onclick="generateAdminPass('admin-reset-pass')" style="height:38px;padding:0 0.75rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.775rem;font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;gap:0.3rem;">
              🎲 Rastgele
            </button>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:0.65rem;">
          <button type="button" class="btn-secondary" onclick="closeAdminPassModal()" style="height:36px;padding:0 1rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-secondary);cursor:pointer;font-size:0.85rem;">Vazgeç</button>
          <button type="submit" id="btn-submit-change-pass" class="btn-primary" style="height:36px;padding:0 1.25rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.85rem;">
            Şifreyi Güncelle
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADMIN: EDIT ACCOUNT & HESTIACP FEATURES MODAL (MATCHING SCREENSHOT) -->
  <div id="admin-account-edit-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:1rem;box-sizing:border-box;">
    <div style="background:var(--bg-surface);width:600px;max-width:96vw;max-height:92vh;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.5rem 1.75rem;box-sizing:border-box;display:flex;flex-direction:column;">
      
      <!-- Modal Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
          <span style="font-size:1.35rem;">⚙️</span>
          <div>
            <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-primary);">Hesap Ayarları & HestiaCP Özellikleri</h3>
            <div id="admin-edit-subtitle" style="font-size:0.75rem;color:var(--text-secondary);margin-top:2px;">Canlı HestiaCP posta kutusu parametreleri</div>
          </div>
        </div>
        <button type="button" class="btn-icon" onclick="closeAdminAccountEditModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
      </div>

      <!-- Scrollable Form Body -->
      <form id="admin-edit-account-form" onsubmit="submitAdminAccountEdit(event)" style="overflow-y:auto;flex:1;padding-right:0.35rem;">
        <input type="hidden" id="admin-edit-id" value="">

        <!-- 1. Hesap (E-Posta) Readonly -->
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Hesap</label>
          <input type="text" id="admin-edit-email" class="form-control" readonly style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;font-weight:600;cursor:not-allowed;">
        </div>

        <!-- 2. Görünen İsim (Ad Soyad) -->
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Görünen İsim (Ad Soyad)</label>
          <input type="text" id="admin-edit-name" class="form-control" placeholder="Ad Soyad" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
        </div>

        <!-- 3. Parola & Gereksinimler -->
        <div style="margin-bottom:1rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.35rem;">
            <label style="font-size:0.8rem;font-weight:600;color:var(--text-secondary);display:inline-flex;align-items:center;gap:0.4rem;">
              <span>Parola</span>
              <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);">(Yalnızca değiştirmek istiyorsanız doldurun)</span>
            </label>
            <button type="button" onclick="generateHestiaValidPass('admin-edit-password')" style="border:none;background:transparent;color:var(--primary);cursor:pointer;font-size:0.775rem;font-weight:600;display:inline-flex;align-items:center;gap:3px;padding:0;" title="Kurallara uygun rastgele şifre üret">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              <span>Yenile / Rastgele</span>
            </button>
          </div>
          <div style="position:relative;">
            <input type="password" id="admin-edit-password" class="form-control" placeholder="Değiştirmek istemiyorsanız boş bırakın" style="width:100%;height:38px;padding:0 2.25rem 0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            <button type="button" onclick="togglePassVisibility('admin-edit-password', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Şifreyi Göster">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>

          <!-- Requirements Hint Box (matching Hestia UI exactly) -->
          <div style="background:var(--bg-hover);border:1px solid var(--border-color);padding:0.6rem 0.85rem;margin-top:0.4rem;font-size:0.75rem;color:var(--text-secondary);line-height:1.5;">
            <div style="font-weight:600;color:var(--text-primary);margin-bottom:0.25rem;">Parolanız aşağıdakileri karşılamalıdır:</div>
            <ul style="margin:0;padding-left:1.2rem;">
              <li>8 karakter uzunluğunda</li>
              <li>1 büyük harf & 1 küçük harf</li>
              <li>1 rakam</li>
            </ul>
          </div>
        </div>

        <!-- 4. Giriş kimlik bilgilerini e-posta ile gönder -->
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Giriş kimlik bilgilerini e-posta ile gönder:</label>
          <input type="email" id="admin-edit-send-email" class="form-control" placeholder="Örn: yonetici@<?= htmlspecialchars(MAIL_DOMAIN) ?> (isteğe bağlı)" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
        </div>

        <!-- 5. Kota (MB cinsinden) -->
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Kota (MB cinsinden)</label>
          <div style="display:flex;align-items:center;gap:0.5rem;">
            <input type="text" id="admin-edit-quota" class="form-control" placeholder="Sınırsız veya MB örn: 1000" style="flex:1;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            <button type="button" class="btn-secondary" onclick="setEditQuotaUnlimited()" style="height:38px;padding:0 0.95rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.85rem;font-weight:600;display:inline-flex;align-items:center;gap:0.35rem;" title="Sınırsız (0 MB) olarak ayarla">
              <span style="font-size:1.1rem;line-height:1;">∞</span> Sınırsız
            </button>
          </div>
          <div style="font-size:0.725rem;color:var(--text-muted);margin-top:0.25rem;">0 veya 'Sınırsız' yazıldığında kota sınırı uygulanmaz.</div>
        </div>

        <!-- 6. Takma Adlar (Aliases) -->
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Takma Adlar <span style="font-weight:400;color:var(--text-muted);">(Domain olmadan yerel bölümü kullanın)</span></label>
          <textarea id="admin-edit-aliases" rows="2" class="form-control" placeholder="destek, satis, muhasebe" style="width:100%;padding:0.55rem 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;font-family:inherit;font-size:0.825rem;resize:vertical;"></textarea>
          <div style="font-size:0.725rem;color:var(--text-muted);margin-top:0.25rem;">Birden fazla rumuzu virgül veya yeni satır ile ayırarak yazabilirsiniz.</div>
        </div>

        <!-- 7. Checkboxes: Blackhole & Forward Only -->
        <div style="margin-bottom:1rem;background:var(--bg-hover);padding:0.75rem 0.9rem;border:1px solid var(--border-color);display:flex;flex-direction:column;gap:0.6rem;">
          <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.825rem;font-weight:600;color:var(--text-primary);user-select:none;">
            <input type="checkbox" id="admin-edit-blackhole" onchange="toggleEditBlackhole(this.checked)" style="accent-color:var(--primary);width:16px;height:16px;">
            <span>Tüm postaları iptal et (:blackhole:)</span>
          </label>
          <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.825rem;font-weight:600;color:var(--text-primary);user-select:none;">
            <input type="checkbox" id="admin-edit-forward-only" style="accent-color:var(--primary);width:16px;height:16px;">
            <span>Yönlendirilmiş postaları depolama (Yalnızca yönlendir)</span>
          </label>
        </div>

        <!-- 8. Şuraya yönlendir (Forwarders) -->
        <div id="admin-edit-forward-wrapper" style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Şuraya yönlendir <span style="font-weight:400;color:var(--text-muted);">(bir veya daha fazla e-posta adresi)</span></label>
          <textarea id="admin-edit-forward" rows="3" class="form-control" placeholder="kullanici1@<?= htmlspecialchars(MAIL_DOMAIN) ?>&#10;kullanici2@<?= htmlspecialchars(MAIL_DOMAIN) ?>" style="width:100%;padding:0.55rem 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;font-family:inherit;font-size:0.825rem;resize:vertical;"></textarea>
          <div style="font-size:0.725rem;color:var(--text-muted);margin-top:0.25rem;">Birden fazla adresi her satıra bir adet veya virgülle ayırarak giriniz.</div>
        </div>

        <!-- 9. Otomatik Cevap (Autoreply) -->
        <div style="margin-bottom:1rem;background:var(--bg-hover);padding:0.75rem 0.9rem;border:1px solid var(--border-color);">
          <label style="display:inline-flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.825rem;font-weight:600;color:var(--text-primary);user-select:none;">
            <input type="checkbox" id="admin-edit-autoreply" onchange="toggleEditAutoreply(this.checked)" style="accent-color:var(--primary);width:16px;height:16px;">
            <span>Otomatik Cevap (Out of Office)</span>
          </label>
          <div id="admin-edit-autoreply-box" style="display:none;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
            <label style="display:block;font-size:0.775rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Otomatik Cevap Mesajı:</label>
            <textarea id="admin-edit-autoreply-msg" rows="4" class="form-control" placeholder="Şu anda ofis dışındayım, acil durumlarda lütfen telefon ile iletişime geçiniz..." style="width:100%;padding:0.55rem 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;font-family:inherit;font-size:0.825rem;resize:vertical;"></textarea>
          </div>
        </div>

        <!-- 10. Hız Sınırı (Rate limit) -->
        <div style="margin-bottom:1.25rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Hız Sınırı (e-posta / saat)</label>
          <input type="number" id="admin-edit-rate" class="form-control" placeholder="Boş bırakırsanız sistem varsayılanı geçerlidir" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          <div style="font-size:0.725rem;color:var(--text-muted);margin-top:0.25rem;">Örn: 100. Bu kullanıcının bir saatte gönderebileceği maksimum mail adedi.</div>
        </div>

        <!-- Modal Footer Actions -->
        <div style="display:flex;justify-content:flex-end;gap:0.65rem;padding-top:1rem;border-top:1px solid var(--border-color);position:sticky;bottom:0;background:var(--bg-surface);">
          <button type="button" class="btn-secondary" onclick="closeAdminAccountEditModal()" style="height:38px;padding:0 1.25rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-secondary);cursor:pointer;font-size:0.85rem;">Vazgeç</button>
          <button type="submit" id="btn-submit-edit-account" class="btn-primary" style="height:38px;padding:0 1.5rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <span>💾 Değişiklikleri Kaydet</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADMIN: HESTIACP API CONFIGURATION MODAL (REMOTE REST API OR LOCAL SAME-SERVER CLI) -->
  <div id="admin-hestia-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:var(--bg-surface);width:600px;max-width:94vw;max-height:92vh;overflow-y:auto;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.75rem;box-sizing:border-box;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.15rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);">
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <span style="font-size:1.35rem;">⚙️</span>
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-primary);">HestiaCP Entegrasyon & Sunucu Ayarları</h3>
        </div>
        <button type="button" class="btn-icon" onclick="closeHestiaConfigModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
      </div>

      <!-- MODE SELECTION CARDS -->
      <div style="margin-bottom:1.25rem;">
        <label style="display:block;font-size:0.75rem;font-weight:700;margin-bottom:0.45rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Çalışma & Bağlantı Modu</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <!-- MODE REMOTE -->
          <div id="card-mode-remote" onclick="setHestiaServerMode('remote')" style="border:2px solid var(--primary);background:var(--bg-app);padding:0.75rem 0.85rem;cursor:pointer;transition:all 0.2s;position:relative;">
            <div style="display:flex;align-items:center;gap:0.45rem;margin-bottom:0.35rem;">
              <input type="radio" name="hestia_server_mode_radio" id="mode-radio-remote" value="remote" checked style="accent-color:var(--primary);margin:0;cursor:pointer;">
              <span style="font-size:0.85rem;font-weight:700;color:var(--text-primary);">🌐 Uzak Sunucu (API)</span>
            </div>
            <div style="font-size:0.72rem;color:var(--text-secondary);line-height:1.35;margin-left:1.35rem;">
              REST API & Port 8083 üzerinden harici API anahtarı ile bağlanır.
            </div>
            <span style="position:absolute;top:0.4rem;right:0.5rem;font-size:0.65rem;font-weight:600;background:var(--bg-hover);color:var(--text-muted);padding:0.1rem 0.35rem;border:1px solid var(--border-color);">Varsayılan</span>
          </div>

          <!-- MODE LOCAL -->
          <div id="card-mode-local" onclick="setHestiaServerMode('local')" style="border:1px solid var(--border-color);background:var(--bg-app);padding:0.75rem 0.85rem;cursor:pointer;transition:all 0.2s;position:relative;">
            <div style="display:flex;align-items:center;gap:0.45rem;margin-bottom:0.35rem;">
              <input type="radio" name="hestia_server_mode_radio" id="mode-radio-local" value="local" style="accent-color:var(--primary);margin:0;cursor:pointer;">
              <span style="font-size:0.85rem;font-weight:700;color:var(--text-primary);">⚡ Aynı Sunucu (Yerel CLI)</span>
            </div>
            <div style="font-size:0.72rem;color:var(--text-secondary);line-height:1.35;margin-left:1.35rem;">
              Webmail HestiaCP ile aynı sunucuda. API anahtarsız, yerel komutla anında çalışır.
            </div>
            <span style="position:absolute;top:0.4rem;right:0.5rem;font-size:0.65rem;font-weight:600;background:rgba(16,185,129,0.12);color:var(--success);padding:0.1rem 0.35rem;border:1px solid rgba(16,185,129,0.25);">API'siz</span>
          </div>
        </div>
      </div>

      <form id="admin-hestia-config-form" autocomplete="off" onsubmit="submitHestiaConfig(event)">
        <!-- Decoy inputs to defeat browser password manager autofill -->
        <input type="text" style="display:none!important;" tabindex="-1" autocomplete="username">
        <input type="password" style="display:none!important;" tabindex="-1" autocomplete="current-password">

        <!-- ==================== REMOTE REST API FIELDS ==================== -->
        <div id="hestia-remote-fields" style="display:block;">
          <div style="margin-bottom:1rem;padding:0.75rem 0.95rem;background:var(--bg-hover);border:1px solid var(--border-color);font-size:0.775rem;color:var(--text-secondary);line-height:1.5;">
            <strong style="color:var(--text-primary);display:block;margin-bottom:0.25rem;">HestiaCP Panelinizdeki Erişim Anahtarları:</strong>
            HestiaCP panelinizde <em>Users &rarr; admin &rarr; Access Keys</em> bölümünden oluşturduğunuz <strong>Erişim Anahtarı ID</strong> ve <strong>Gizli Anahtar</strong> bilgilerini giriniz.
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">HestiaCP Sunucu Adresi (URL & Port) *</label>
            <input type="text" id="admin-hestia-host" name="hcp_host_val" autocomplete="off" class="form-control" placeholder="https://mail.<?= htmlspecialchars(MAIL_DOMAIN) ?>:8083" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">HestiaCP Yetkili Kullanıcı Adı *</label>
            <input type="text" id="admin-hestia-user" name="hcp_admin_account_name" autocomplete="off" class="form-control" placeholder="admin" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">Erişim Anahtarı ID (Access Key ID) *</label>
            <input type="text" id="admin-hestia-key-id" name="hcp_access_key_ident" autocomplete="off" class="form-control" placeholder="Örn: WGOI18l3IJOe1jSrYa2I" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          </div>

          <div style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
              <label style="font-size:0.8rem;font-weight:600;color:var(--text-secondary);margin:0;">Gizli Anahtar (Secret Key) *</label>
              <span id="hestia-saved-key-notice" style="font-size:0.75rem;color:var(--success);display:none;font-weight:600;">✓ Kayıtlı Anahtar Mevcut</span>
            </div>
            <div style="position:relative;">
              <input type="password" id="admin-hestia-secret-key" name="hcp_secret_token_val" autocomplete="new-password" class="form-control" placeholder="Örn: Jyy8ujn6ZnvLWjqYbMUGiqzbZpjb_6NUXAYHgxWW" style="width:100%;height:38px;padding:0 2.25rem 0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
              <button type="button" onclick="togglePassVisibility('admin-hestia-secret-key', this)" style="position:absolute;right:0.6rem;top:50%;transform:translateY(-50%);border:none;background:transparent;cursor:pointer;color:var(--text-muted);padding:0;" title="Anahtarı Göster / Gizle">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <!-- IP Restriction Help Box -->
          <div style="margin-bottom:1.25rem;padding:0.75rem 0.9rem;background:rgba(245, 158, 11, 0.08);border:1px solid rgba(245, 158, 11, 0.3);font-size:0.775rem;color:var(--text-primary);line-height:1.45;">
            <div style="font-weight:700;color:#f59e0b;margin-bottom:0.25rem;display:flex;align-items:center;gap:0.35rem;">
              <span>⚠️</span> HestiaCP İzin Verilen IP Uyarısı:
            </div>
            HestiaCP panelinde bu anahtarı düzenlerken <strong>İzin Verilen IP Adresleri (Allowed IP)</strong> alanına webmail sunucunuzun IP adresi olan <strong id="hestia-client-ip-val" style="background:var(--bg-surface);padding:0.1rem 0.35rem;border:1px solid var(--border-color);"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '91.93.120.222') ?></strong> veya tüm IP'lere izin vermek için <strong style="background:var(--bg-surface);padding:0.1rem 0.35rem;border:1px solid var(--border-color);">0.0.0.0/0</strong> yazılmalıdır.
          </div>
        </div>

        <!-- ==================== LOCAL SAME-SERVER CLI FIELDS ==================== -->
        <div id="hestia-local-fields" style="display:none;">
          <!-- System detection status -->
          <div id="hestia-local-detect-banner" style="margin-bottom:1rem;padding:0.75rem 0.95rem;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.25);font-size:0.775rem;line-height:1.45;color:var(--text-primary);">
            <div style="font-weight:700;color:var(--success);margin-bottom:0.2rem;display:flex;align-items:center;gap:0.35rem;">
              <span id="hestia-local-detect-icon">✓</span> <span id="hestia-local-detect-title">Aynı Sunucu (Yerel CLI) Modu</span>
            </div>
            <div id="hestia-local-detect-desc" style="color:var(--text-secondary);">
              Webmail ve HestiaCP aynı sunucuda barındırıldığında, Port 8083 veya API anahtarına gerek kalmadan komutlar doğrudan yerel sunucu işlemcisi tarafından milisaniyeler içinde çalıştırılır.
            </div>
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">HestiaCP Yetkili Kullanıcı Adı *</label>
            <input type="text" id="admin-hestia-local-user" autocomplete="off" class="form-control" placeholder="admin" value="admin" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            <small style="display:block;margin-top:0.25rem;font-size:0.72rem;color:var(--text-muted);">Hestia panelinde posta kutularını barındıran yetkili kullanıcı adı (Genellikle <code>admin</code>).</small>
          </div>

          <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">HestiaCP CLI Komut Dizini *</label>
            <input type="text" id="admin-hestia-cli-path" autocomplete="off" class="form-control" placeholder="/usr/local/hestia/bin" value="/usr/local/hestia/bin" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
            <small style="display:block;margin-top:0.25rem;font-size:0.72rem;color:var(--text-muted);">Standart HestiaCP kurulumlarında komut dosyaları <code>/usr/local/hestia/bin</code> dizinindedir.</small>
          </div>

          <div style="margin-bottom:1.25rem;padding:0.75rem 0.85rem;background:var(--bg-hover);border:1px solid var(--border-color);">
            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.8rem;font-weight:600;color:var(--text-primary);margin:0;">
              <input type="checkbox" id="admin-hestia-use-sudo" checked style="accent-color:var(--primary);width:16px;height:16px;cursor:pointer;">
              Komutları <code>sudo</code> yetkisiyle çalıştır (Önerilen)
            </label>
            <small style="display:block;margin-top:0.35rem;margin-left:1.5rem;font-size:0.72rem;color:var(--text-muted);line-height:1.4;">
              HestiaCP kurulumu varsayılan olarak sudoers izinlerini içerir. Komutların hesap ekleme/silme/şifre değiştirme yetkisi için sudo önerilir.
            </small>
          </div>
        </div>

        <div id="hestia-test-feedback" style="display:none;margin-bottom:1.25rem;padding:0.75rem;font-size:0.8rem;border:1px solid var(--border-color);"></div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:0.65rem;flex-wrap:wrap;">
          <button type="button" class="btn-secondary" id="btn-test-hestia-conn" onclick="testHestiaConnectionModal()" style="height:36px;padding:0 1rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:0.825rem;font-weight:600;display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span id="btn-test-text">Bağlantıyı / Komutları Test Et</span>
          </button>
          <div style="display:flex;gap:0.5rem;align-items:center;">
            <button type="button" class="btn-secondary" onclick="closeHestiaConfigModal()" style="height:36px;padding:0 1rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-secondary);cursor:pointer;font-size:0.85rem;">Vazgeç</button>
            <button type="submit" id="btn-submit-hestia-config" class="btn-primary" style="height:36px;padding:0 1.25rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.4rem;">
              Kaydet & Uygula
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ADMIN: BRANDING & APPLICATION TITLE MODAL -->
  <div id="admin-branding-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:var(--bg-surface);width:540px;max-width:92vw;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);padding:1.75rem;box-sizing:border-box;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border-color);">
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <span style="font-size:1.35rem;">🏷️</span>
          <h3 style="margin:0;font-size:1.15rem;font-weight:700;color:var(--text-primary);">Uygulama & Marka İsim Ayarları</h3>
        </div>
        <button type="button" class="btn-icon" onclick="closeBrandingModal()" style="border:none;background:transparent;cursor:pointer;color:var(--text-muted);font-size:1.4rem;line-height:1;">&times;</button>
      </div>

      <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;background:var(--bg-hover);border:1px solid var(--border-color);font-size:0.775rem;color:var(--text-secondary);line-height:1.5;">
        Sol menüde, sayfa başlığında ve giriş ekranında görünen <strong>"Hestia Mail"</strong> ve <strong>"Web Suite"</strong> isimlerini kurumunuza özel olarak güncelleyebilirsiniz.
      </div>

      <!-- Live Preview Card -->
      <div style="margin-bottom:1.25rem;padding:1rem;background:var(--bg-app);border:1px dashed var(--border-color);">
        <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.5px;margin-bottom:0.6rem;">
          Canlı Önizleme (Sol Menü Görünümü):
        </div>
        <div style="display:inline-flex;align-items:center;gap:0.75rem;padding:0.6rem 1rem;background:var(--bg-surface);border:1px solid var(--border-color);box-shadow:var(--shadow-sm);">
          <div class="brand-icon" style="width:36px;height:36px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
          </div>
          <div>
            <div id="preview-brand-title" style="font-size:1rem;font-weight:700;color:var(--text-primary);line-height:1.2;"><?= htmlspecialchars($app_title) ?></div>
            <div id="preview-brand-subtitle" style="font-size:0.7rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($app_subtitle) ?></div>
          </div>
        </div>
      </div>

      <form id="admin-branding-form" onsubmit="submitBrandingConfig(event)">
        <div style="margin-bottom:1rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">
            Ana Başlık (Brand Title) *
          </label>
          <input type="text" id="admin-input-app-title" class="form-control" value="<?= htmlspecialchars($app_title) ?>" placeholder="Örn: Hestia Mail veya Kurumsal E-Posta" required oninput="updateBrandingPreview()" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.25rem;">Sol üstteki büyük ana marka adı.</div>
        </div>

        <div style="margin-bottom:1.25rem;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.35rem;color:var(--text-secondary);">
            Alt Başlık / Slogan (Brand Subtitle) *
          </label>
          <input type="text" id="admin-input-app-subtitle" class="form-control" value="<?= htmlspecialchars($app_subtitle) ?>" placeholder="Örn: Web Suite veya Kurumsal Webmail" required oninput="updateBrandingPreview()" style="width:100%;height:38px;padding:0 0.75rem;background:var(--bg-app);border:1px solid var(--border-color);color:var(--text-primary);box-sizing:border-box;">
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.25rem;">Başlığın altındaki küçük açıklama (örn: "Web Suite", "Kurumsal E-Posta").</div>
        </div>

        <!-- Quick Templates -->
        <div style="margin-bottom:1.25rem;display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
          <span style="font-size:0.72rem;color:var(--text-muted);font-weight:600;">Hızlı Şablonlar:</span>
          <button type="button" class="btn btn-outline" onclick="applyBrandingTemplate('Hestia Mail', 'Web Suite')" style="font-size:0.72rem;padding:0.25rem 0.55rem;background:var(--bg-surface);border:1px solid var(--border-color);cursor:pointer;color:var(--text-secondary);">
            Hestia Mail / Web Suite
          </button>
          <button type="button" class="btn btn-outline" onclick="applyBrandingTemplate('<?= htmlspecialchars(ucwords(explode('.', MAIL_DOMAIN)[0])) ?> Mail', 'Kurumsal E-Posta')" style="font-size:0.72rem;padding:0.25rem 0.55rem;background:var(--bg-surface);border:1px solid var(--border-color);cursor:pointer;color:var(--text-secondary);">
            <?= htmlspecialchars(ucwords(explode('.', MAIL_DOMAIN)[0])) ?> Mail / Kurumsal
          </button>
          <button type="button" class="btn btn-outline" onclick="applyBrandingTemplate('Kurumsal Webmail', '<?= htmlspecialchars(MAIL_DOMAIN) ?>')" style="font-size:0.72rem;padding:0.25rem 0.55rem;background:var(--bg-surface);border:1px solid var(--border-color);cursor:pointer;color:var(--text-secondary);">
            Kurumsal Webmail / <?= htmlspecialchars(MAIL_DOMAIN) ?>
          </button>
        </div>

        <div id="branding-feedback" style="display:none;margin-bottom:1.25rem;padding:0.75rem;font-size:0.8rem;border:1px solid var(--border-color);"></div>

        <div style="display:flex;justify-content:flex-end;align-items:center;gap:0.5rem;">
          <button type="button" class="btn-secondary" onclick="closeBrandingModal()" style="height:36px;padding:0 1rem;background:var(--bg-hover);border:1px solid var(--border-color);color:var(--text-secondary);cursor:pointer;font-size:0.85rem;">Vazgeç</button>
          <button type="submit" id="btn-submit-branding" class="btn-primary" style="height:36px;padding:0 1.35rem;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.4rem;">
            Kaydet & Uygula
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- TRANSLATION EDITOR MODAL -->
  <div id="trans-editor-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999999;align-items:center;justify-content:center;">
    <div style="background:var(--bg-surface);width:900px;max-width:95vw;height:85vh;display:flex;flex-direction:column;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);border-radius:0 !important;">
      <!-- Modal Header: Language Details (Flag, Name, Code) -->
      <div style="padding:0.85rem 1.25rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;background:var(--bg-surface-elevated);gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.75rem;flex:1;min-width:320px;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <label style="font-size:0.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;" data-i18n="settings_th_flag">Bayrak</label>
            <input type="text" id="trans-modal-flag-input" style="width:52px;height:34px;font-size:1.35rem;text-align:center;border:1px solid var(--border-color);background:var(--bg-surface);border-radius:0 !important;color:var(--text-primary);cursor:pointer;" maxlength="8" title="Bayrak / İkon Emoji">
          </div>
          <div style="display:flex;flex-direction:column;gap:3px;flex:1;max-width:280px;">
            <label style="font-size:0.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;" data-i18n="settings_th_name">Dil Adı</label>
            <input type="text" id="trans-modal-name-input" class="form-control" style="height:34px;font-weight:700;font-size:0.875rem;border-radius:0 !important;" placeholder="Dil Adı" required>
          </div>
          <div style="display:flex;flex-direction:column;gap:3px;">
            <label style="font-size:0.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;" data-i18n="settings_th_code">Dil Kodu</label>
            <code style="display:inline-flex;align-items:center;height:34px;background:var(--bg-hover);padding:0 12px;border:1px solid var(--border-color);font-size:0.85rem;font-weight:700;box-sizing:border-box;color:var(--text-secondary);" id="trans-modal-code"></code>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <button type="button" class="btn-icon" onclick="closeTranslationEditor()" style="width:32px;height:32px;border:1px solid var(--border-color);background:var(--bg-surface);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;border-radius:0 !important;" title="Kapat" data-i18n-title="close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>

      <!-- Modal Controls: Search & Mode Switcher -->
      <div style="padding:0.75rem 1.25rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;background:var(--bg-hover);">
        <div style="flex:1;min-width:240px;">
          <input type="text" id="trans-search-input" class="form-control" placeholder="Çeviri anahtarlarında veya metinlerde ara..." oninput="filterTranslationKeys(this.value)" style="height:36px;border-radius:0 !important;padding-left:0.75rem;">
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <span id="trans-keys-count-badge" style="font-size:0.8rem;color:var(--text-muted);">0 çeviri</span>
          <button type="button" class="btn-secondary" id="trans-mode-btn" onclick="toggleTranslationMode()" style="height:34px;padding:0 0.75rem;font-size:0.785rem;border-radius:0 !important;" data-i18n="lang_raw_json_tab">Ham JSON Görünümü</button>
        </div>
      </div>

      <!-- Modal Body: Table Editor vs Raw JSON Editor -->
      <div id="trans-modal-table-container" style="flex:1;overflow-y:auto;padding:0.75rem 1.25rem;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
          <thead>
            <tr style="background:var(--bg-hover);border-bottom:2px solid var(--border-color);text-align:left;font-size:0.775rem;color:var(--text-secondary);text-transform:uppercase;">
              <th style="width:230px;padding:0.6rem;" data-i18n="lang_col_key">Anahtar (Key)</th>
              <th style="width:260px;padding:0.6rem;" data-i18n="lang_col_reference">Türkçe Referans</th>
              <th style="padding:0.6rem;" data-i18n="lang_col_value">Çeviri Değeri</th>
            </tr>
          </thead>
          <tbody id="trans-editor-table-body">
            <!-- Rendered by JS -->
          </tbody>
        </table>
      </div>

      <div id="trans-modal-raw-container" style="display:none;flex:1;padding:1rem 1.25rem;">
        <textarea id="trans-raw-textarea" style="width:100%;height:100%;font-family:monospace;font-size:0.85rem;border:1px solid var(--border-color);background:var(--bg-surface);color:var(--text-primary);padding:0.75rem;border-radius:0 !important;resize:none;box-sizing:border-box;"></textarea>
      </div>

      <!-- Modal Footer -->
      <div style="padding:0.85rem 1.25rem;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:flex-end;gap:0.6rem;background:var(--bg-surface-elevated);">
        <button type="button" class="btn-secondary" onclick="closeTranslationEditor()" style="height:38px;padding:0 1.25rem;border-radius:0 !important;cursor:pointer;" data-i18n="common_cancel">Vazgeç</button>
        <button type="button" class="btn-send" onclick="saveActiveLanguageTranslations()" style="height:38px;padding:0 1.5rem;border-radius:0 !important;cursor:pointer;font-weight:600;" data-i18n="lang_save_changes">Değişiklikleri Kaydet</button>
      </div>
    </div>
  </div>

  <!-- NEW LANGUAGE MODAL -->
  <div id="new-lang-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999999;align-items:center;justify-content:center;">
    <div style="background:var(--bg-surface);width:440px;max-width:92vw;border:1px solid var(--border-color);box-shadow:var(--shadow-xl);border-radius:0 !important;padding:1.5rem;">
      <h3 style="margin:0 0 0.5rem 0;font-size:1.15rem;font-weight:700;color:var(--text-primary);" data-i18n="lang_modal_add_title">Yeni Dil Ekle</h3>
      <p style="margin:0 0 1.25rem 0;font-size:0.825rem;color:var(--text-muted);" data-i18n="lang_modal_add_sub">Sisteme yeni bir dil ekleyin ve çevirilerini özelleştirin.</p>
      
      <div style="margin-bottom:1rem;">
        <label style="display:block;font-size:0.825rem;font-weight:600;margin-bottom:0.35rem;" data-i18n="lang_code_label">Dil Kodu * (Örn: fr, es, it, ru, ar)</label>
        <input type="text" id="new-lang-code" class="form-control" placeholder="fr" style="height:36px;border-radius:0 !important;" maxlength="10">
      </div>
      
      <div style="margin-bottom:1rem;">
        <label style="display:block;font-size:0.825rem;font-weight:600;margin-bottom:0.35rem;" data-i18n="lang_name_label">Dil Adı * (Örn: Français, Español)</label>
        <input type="text" id="new-lang-name" class="form-control" placeholder="Français" style="height:36px;border-radius:0 !important;">
      </div>
      
      <div style="margin-bottom:1rem;">
        <label style="display:block;font-size:0.825rem;font-weight:600;margin-bottom:0.35rem;" data-i18n="lang_flag_label">Bayrak / İkon Emoji (Örn: 🇫🇷, 🇪🇸, 🇮🇹)</label>
        <input type="text" id="new-lang-flag" class="form-control" placeholder="🇫🇷" value="🌐" style="height:36px;border-radius:0 !important;" maxlength="8">
      </div>
      
      <div style="margin-bottom:1.5rem;">
        <label style="display:block;font-size:0.825rem;font-weight:600;margin-bottom:0.35rem;" data-i18n="lang_copy_source_label">Başlangıç Çevirilerini Kopyala</label>
        <select id="new-lang-copy" class="form-control" style="height:36px;border-radius:0 !important;">
          <option value="tr">Türkçe (tr) şablonundan kopyala</option>
          <option value="en">İngilizce (en) şablonundan kopyala</option>
        </select>
      </div>
      
      <div style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;">
        <button type="button" class="btn-secondary" onclick="closeNewLanguageModal()" style="height:36px;padding:0 1rem;border-radius:0 !important;cursor:pointer;" data-i18n="common_cancel">Vazgeç</button>
        <button type="button" class="btn-send" onclick="submitCreateNewLanguage()" style="height:36px;padding:0 1.25rem;border-radius:0 !important;cursor:pointer;" data-i18n="lang_create_btn">Dili Oluştur</button>
      </div>
    </div>
  </div>



  <!-- CHAT: IMAGE LIGHTBOX MODAL -->
  <div class="compose-overlay" id="modal-chat-lightbox" onclick="closeChatLightbox(event)" style="display:none;z-index:10000;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;">
    <div style="position:relative;max-width:90vw;max-height:90vh;display:flex;flex-direction:column;align-items:center;">
      <img id="chat-lightbox-img" src="" alt="Görsel" style="max-width:100%;max-height:85vh;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.5);">
      <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.75rem;">
        <a id="chat-lightbox-download" href="" download class="btn-send" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:6px !important;text-decoration:none;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span>İndir</span>
        </a>
        <button type="button" onclick="closeChatLightbox()" class="btn-secondary" style="padding:0.4rem 0.85rem;font-size:0.8rem;border-radius:6px !important;">Kapat</button>
      </div>
    </div>
  </div>

  <!-- COMPOSE MODAL -->
  <div class="compose-overlay" id="compose-modal">
    <div class="compose-window">
      <div class="compose-header">
        <div class="compose-title" data-i18n="compose_new">Yeni İleti</div>
        <div style="display:flex;align-items:center;gap:0.35rem;">
          <button type="button" class="btn-icon" id="btn-maximize-compose" onclick="toggleMaximizeCompose()" title="Büyüt / Küçült" data-i18n-title="compose_maximize">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
          </button>
          <button type="button" class="btn-icon" onclick="closeComposeModal()" title="Kapat" data-i18n-title="close">&times;</button>
        </div>
      </div>

      <form class="compose-form" id="compose-form" onsubmit="sendMail(event)" enctype="multipart/form-data">
        <div class="compose-fields">
          <div class="compose-input-row" style="position:relative;align-items:flex-start;">
            <span class="compose-label" style="margin-top:6px;" data-i18n="compose_to_lbl">Kime:</span>
            <div style="flex:1;">
              <select id="compose-to" class="compose-select2" multiple="multiple" style="width:100%;"></select>
            </div>
            <div style="display:flex;gap:0.4rem;margin-top:4px;">
              <button type="button" class="chip" id="btn-show-cc" onclick="document.getElementById('cc-row').style.display='flex';this.style.display='none';">Cc</button>
              <button type="button" class="chip" id="btn-show-bcc" onclick="document.getElementById('bcc-row').style.display='flex';this.style.display='none';">Bcc</button>
            </div>
          </div>

          <div class="compose-input-row" id="cc-row" style="display:none;align-items:flex-start;">
            <span class="compose-label" style="margin-top:6px;" data-i18n="compose_cc_lbl">Cc:</span>
            <div style="flex:1;">
              <select id="compose-cc" class="compose-select2" multiple="multiple" style="width:100%;"></select>
            </div>
          </div>

          <div class="compose-input-row" id="bcc-row" style="display:none;align-items:flex-start;">
            <span class="compose-label" style="margin-top:6px;" data-i18n="compose_bcc_lbl">Bcc:</span>
            <div style="flex:1;">
              <select id="compose-bcc" class="compose-select2" multiple="multiple" style="width:100%;"></select>
            </div>
          </div>

          <div class="compose-input-row">
            <span class="compose-label" data-i18n="compose_subj_lbl">Konu:</span>
            <input type="text" id="compose-subject" class="compose-input" placeholder="E-posta konusu" required>
          </div>
        </div>

        <!-- Rich Text Editor Toolbar -->
        <div class="editor-toolbar">
          <!-- Paragraph / Headings -->
          <select class="tool-select" onchange="formatDoc('formatBlock', this.value); this.selectedIndex=0;" title="Biçim / Paragraf">
            <option value="" disabled selected>Biçim</option>
            <option value="p">Normal Metin</option>
            <option value="h1">Büyük Başlık (H1)</option>
            <option value="h2">Orta Başlık (H2)</option>
            <option value="h3">Küçük Başlık (H3)</option>
            <option value="pre">Kod Bloğu</option>
          </select>

          <!-- Font Family -->
          <select class="tool-select" onchange="formatDoc('fontName', this.value); this.selectedIndex=0;" title="Yazı Tipi">
            <option value="" disabled selected>Yazı Tipi</option>
            <option value="Inter, sans-serif">Inter</option>
            <option value="Arial, sans-serif">Arial</option>
            <option value="'Segoe UI', sans-serif">Segoe UI</option>
            <option value="'Times New Roman', serif">Times New Roman</option>
            <option value="Georgia, serif">Georgia</option>
            <option value="'Courier New', monospace">Courier New</option>
            <option value="Verdana, sans-serif">Verdana</option>
            <option value="Tahoma, sans-serif">Tahoma</option>
          </select>

          <!-- Font Size -->
          <select class="tool-select" onchange="formatDoc('fontSize', this.value); this.selectedIndex=0;" title="Boyut">
            <option value="" disabled selected>Boyut</option>
            <option value="1">10 pt</option>
            <option value="2">12 pt (Küçük)</option>
            <option value="3">14 pt (Normal)</option>
            <option value="4">16 pt (Orta)</option>
            <option value="5">18 pt (Büyük)</option>
            <option value="6">24 pt (Başlık)</option>
          </select>

          <div class="tool-divider"></div>

          <!-- Bold, Italic, Underline, Strikethrough, Subscript, Superscript -->
          <button type="button" class="tool-btn" title="Kalın (Ctrl+B)" onclick="formatDoc('bold')"><strong>B</strong></button>
          <button type="button" class="tool-btn" title="İtalik (Ctrl+I)" onclick="formatDoc('italic')"><em>I</em></button>
          <button type="button" class="tool-btn" title="Altı Çizili (Ctrl+U)" onclick="formatDoc('underline')"><u>U</u></button>
          <button type="button" class="tool-btn" title="Üstü Çizili" onclick="formatDoc('strikeThrough')"><s>S</s></button>
          <button type="button" class="tool-btn" title="Alt Simge (x₂)" onclick="formatDoc('subscript')"><span style="font-size:0.75rem;font-weight:700;">x₂</span></button>
          <button type="button" class="tool-btn" title="Üst Simge (x²)" onclick="formatDoc('superscript')"><span style="font-size:0.75rem;font-weight:700;">x²</span></button>

          <div class="tool-divider"></div>

          <!-- Color Pickers -->
          <label class="tool-color-label" title="Yazı Rengi">
            <span style="font-weight:700;font-size:0.85rem;color:var(--text-primary);border-bottom:2px solid var(--primary);line-height:1;">A</span>
            <input type="color" value="#0f172a" onchange="formatDoc('foreColor', this.value)">
          </label>
          <label class="tool-color-label" title="Vurgu / Arka Plan Rengi">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
            <input type="color" value="#fef08a" onchange="formatDoc('hiliteColor', this.value)">
          </label>

          <div class="tool-divider"></div>

          <!-- Alignment -->
          <button type="button" class="tool-btn" title="Sola Hizala" onclick="formatDoc('justifyLeft')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="Ortala" onclick="formatDoc('justifyCenter')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="18" y1="18" x2="6" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="Sağa Hizala" onclick="formatDoc('justifyRight')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="İki Yana Yasla" onclick="formatDoc('justifyFull')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="3" y2="18"/></svg></button>

          <div class="tool-divider"></div>

          <!-- Lists & Indent -->
          <button type="button" class="tool-btn" title="Madde İmleri" onclick="formatDoc('insertUnorderedList')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="Numaralı Liste" onclick="formatDoc('insertOrderedList')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg></button>
          <button type="button" class="tool-btn" title="Girintiyi Azalt" onclick="formatDoc('outdent')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="7 8 3 12 7 16"/><line x1="21" y1="12" x2="11" y2="12"/><line x1="21" y1="6" x2="11" y2="6"/><line x1="21" y1="18" x2="11" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="Girintiyi Artır" onclick="formatDoc('indent')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="11 8 15 12 11 16"/><line x1="21" y1="12" x2="9" y2="12"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="18" x2="3" y2="18"/></svg></button>

          <div class="tool-divider"></div>

          <!-- Inserts: Link, Image, Table, HR, Blockquote -->
          <button type="button" class="tool-btn" title="Bağlantı (Link) Ekle" onclick="insertLinkModal()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
          <button type="button" class="tool-btn" title="Görsel (Resim) Ekle" onclick="insertImageModal()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></button>
          <button type="button" class="tool-btn" title="Tablo Ekle" onclick="insertTablePrompt()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg></button>
          <button type="button" class="tool-btn" title="Yatay Çizgi" onclick="formatDoc('insertHorizontalRule')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="12" x2="22" y2="12"/></svg></button>
          <button type="button" class="tool-btn" title="Alıntı Bloğu" onclick="formatDoc('formatBlock', 'blockquote')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg></button>

          <div class="tool-divider"></div>

          <!-- Signature Insert -->
          <button type="button" class="tool-btn" title="Kayıtlı İmzayı Ekle" onclick="insertUserSignature()" style="width:auto;padding:0 6px;gap:4px;font-size:0.75rem;font-weight:600;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            <span data-i18n="compose_add_sig_btn">İmza Ekle</span>
          </button>

          <div class="tool-divider"></div>

          <!-- Clear format, Undo, Redo, HTML Code -->
          <button type="button" class="tool-btn" title="Biçimlendirmeyi Temizle" onclick="formatDoc('removeFormat')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          <button type="button" class="tool-btn" title="Geri Al (Ctrl+Z)" onclick="formatDoc('undo')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg></button>
          <button type="button" class="tool-btn" title="İleri Al (Ctrl+Y)" onclick="formatDoc('redo')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg></button>
          <button type="button" class="tool-btn" id="btn-toggle-source" title="HTML Kaynak Kodu Düzenle" onclick="toggleSourceCode()" style="font-family:monospace;font-size:0.8rem;font-weight:700;">&lt;/&gt;</button>
        </div>

        <div id="compose-editor" class="compose-editor" contenteditable="true" placeholder="İletinizi buraya yazın..."></div>
        <textarea id="compose-html-source" class="compose-html-view" placeholder="HTML kaynak kodu..."></textarea>

        <!-- Interactive Attachment Dropzone -->
        <div class="compose-dropzone" id="compose-dropzone" onclick="document.getElementById('compose-attachments').click()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span data-i18n-html="compose_dropzone_html">Dosyaları buraya sürükleyip bırakın veya <strong>seçmek için tıklayın</strong></span>
        </div>
        <div class="compose-attachments-bar" id="compose-file-list" style="display:none;"></div>
        <input type="file" id="compose-attachments" multiple style="display:none;" onchange="onFilesSelected(event)">

        <div class="compose-footer">
          <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <button type="submit" class="btn-send" id="btn-send-mail">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              <span data-i18n="compose_send">Gönder</span>
            </button>
            <button type="button" class="btn-save-draft" id="btn-save-draft" onclick="saveDraft()" title="Taslak Olarak Kaydet" data-i18n-title="compose_save_draft">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <span data-i18n="compose_save_draft">Taslağı Kaydet</span>
            </button>
            <button type="button" class="btn-icon" title="Dosya Ekle" data-i18n-title="compose_attach" onclick="document.getElementById('compose-attachments').click()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            </button>
            <span id="draft-save-status" style="font-size:0.75rem;color:var(--text-muted);display:none;align-items:center;gap:4px;margin-left:4px;"></span>
          </div>
          <button type="button" class="btn-icon" onclick="closeComposeModal()" title="Kapat" data-i18n-title="close">&times;</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SIMPLE 2-FIELD ACCOUNT MODAL (ONLY EMAIL & PASSWORD) -->


  <!-- CONTACT MODAL -->
  <div class="compose-overlay" id="contact-modal">
    <div class="compose-window" style="width:520px;">
      <div class="compose-header">
        <div class="compose-title" id="contact-modal-title" data-i18n="contact_modal_title_new">Yeni Kişi Ekle</div>
        <button class="btn-icon" onclick="closeContactModal()">&times;</button>
      </div>
      <form onsubmit="saveContact(event)" style="padding:1.5rem;">
        <input type="hidden" id="c-id" value="">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label class="form-label" data-i18n="contact_fname_label">Adı *</label>
            <input type="text" id="c-first" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" data-i18n="contact_lname_label">Soyadı</label>
            <input type="text" id="c-last" class="form-control">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" data-i18n="contact_email_label">E-posta Adresi *</label>
          <input type="email" id="c-email" class="form-control" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label class="form-label" data-i18n="contact_phone_label">Telefon</label>
            <input type="text" id="c-phone" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label" data-i18n="contact_category_label">Kategori / Grup</label>
            <input type="text" id="c-group" class="form-control" value="Genel">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label class="form-label" data-i18n="contact_company_label">Şirket</label>
            <input type="text" id="c-company" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label" data-i18n="contact_position_label">Pozisyon</label>
            <input type="text" id="c-title" class="form-control">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" data-i18n="contact_notes_label">Notlar</label>
          <textarea id="c-notes" class="form-control" rows="2"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeContactModal()" data-i18n="common_cancel">İptal</button>
          <button type="submit" class="btn-send" data-i18n="common_save">Kaydet</button>
        </div>
      </form>
    </div>
  </div>

  <!-- EVENT MODAL -->
  <div class="compose-overlay" id="event-modal">
    <div class="compose-window" style="width:520px;">
      <div class="compose-header">
        <div class="compose-title" id="event-modal-title" data-i18n="event_modal_title_new">Yeni Etkinlik</div>
        <button class="btn-icon" onclick="closeEventModal()">&times;</button>
      </div>
      <form onsubmit="saveCalendarEvent(event)" style="padding:1.5rem;">
        <input type="hidden" id="e-id" value="">
        <div class="form-group">
          <label class="form-label" data-i18n="event_title_label">Etkinlik Başlığı *</label>
          <input type="text" id="e-title" class="form-control" placeholder="Toplantı konusu" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label class="form-label" data-i18n="event_start_label">Başlangıç *</label>
            <input type="datetime-local" id="e-start" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" data-i18n="event_end_label">Bitiş *</label>
            <input type="datetime-local" id="e-end" class="form-control" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group">
            <label class="form-label" data-i18n="event_cat_label">Kategori</label>
            <select id="e-category" class="form-control">
              <option value="Toplantı">Toplantı</option>
              <option value="Görev">Görev</option>
              <option value="Hatırlatıcı">Hatırlatıcı</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" data-i18n="event_color_label">Renk</label>
            <select id="e-color" class="form-control">
              <option value="#4f46e5">İndigo / Mavi</option>
              <option value="#059669">Zümrüt Yeşili</option>
              <option value="#d97706">Turuncu</option>
              <option value="#dc2626">Kırmızı</option>
              <option value="#7c3aed">Mor</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" data-i18n="event_location_label">Konum / Link</label>
          <input type="text" id="e-location" class="form-control" placeholder="Ofis Toplantı Salonu veya Online Link">
        </div>

        <div class="form-group">
          <label class="form-label" data-i18n="event_desc_label">Açıklama</label>
          <textarea id="e-desc" class="form-control" rows="2"></textarea>
        </div>

        <!-- Domain Shared Calendar Option -->
        <div class="form-group" style="background:var(--bg-surface-elevated);padding:0.75rem 1rem;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;border-radius:0 !important;">
          <div>
            <div style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span data-i18n="event_share_domain_label">Domain Ortak Takvimi ile Paylaş</span>
            </div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">@<?= htmlspecialchars($active_domain) ?> domainindeki çalışma arkadaşlarınız bu etkinliği takvimlerinde görebilir.</div>
          </div>
          <input type="checkbox" id="e-is-shared" value="1" checked style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary);border-radius:0 !important;margin-left:1rem;">
        </div>

        <!-- Creator Info Row (Shown when viewing/editing existing events) -->
        <div id="event-modal-creator-row" style="display:none;margin-bottom:1rem;padding:0.6rem 0.85rem;background:rgba(79,70,229,0.08);border:1px solid rgba(79,70,229,0.25);font-size:0.8rem;color:var(--text-main);border-radius:0 !important;">
          <span style="font-weight:600;color:var(--primary);" data-i18n="event_created_by_label">Etkinliği Oluşturan:</span> <span id="event-modal-creator-text"></span>
        </div>

        <div style="display:flex;justify-content:space-between;margin-top:1.5rem;">
          <button type="button" class="btn-icon" id="btn-del-event" style="display:none;color:var(--danger);border-radius:0 !important;" onclick="deleteCalendarEvent()" title="Sil">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
          <div style="display:flex;gap:0.75rem;margin-left:auto;">
            <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;border-radius:0 !important;" onclick="closeEventModal()" data-i18n="common_cancel">İptal</button>
            <button type="submit" class="btn-send" style="border-radius:0 !important;">Kaydet</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- CREATE FOLDER MODAL -->
  <div class="compose-overlay" id="folder-modal">
    <div class="compose-window" style="width:420px;">
      <div class="compose-header">
        <div class="compose-title" data-i18n="folder_modal_title">Yeni Klasör Oluştur</div>
        <button class="btn-icon" onclick="closeFolderModal()">&times;</button>
      </div>
      <form onsubmit="handleCreateFolder(event)" style="padding:1.5rem;">
        <div class="form-group">
          <label class="form-label" data-i18n="folder_name_label">Klasör Adı *</label>
          <input type="text" id="folder-input-name" class="form-control" placeholder="Örn: Faturalar, Müşteriler, Projeler" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label" data-i18n="folder_color_label">Klasör Rengi</label>
          <div style="display:flex;gap:0.75rem;align-items:center;">
            <input type="color" id="folder-input-color" value="#4f46e5" style="width:45px;height:38px;padding:2px;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-surface-elevated);cursor:pointer;">
            <span style="font-size:0.8rem;color:var(--text-muted);" data-i18n="folder_color_desc">Sol menüde klasör simgesi rengi</span>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeFolderModal()" data-i18n="common_cancel">İptal</button>
          <button type="submit" class="btn-send" data-i18n="folder_create_btn">Klasörü Oluştur</button>
        </div>
      </form>
    </div>
  </div>

  <!-- RENAME FOLDER MODAL -->
  <div class="compose-overlay" id="rename-folder-modal">
    <div class="compose-window" style="width:420px;">
      <div class="compose-header">
        <div class="compose-title" data-i18n="rename_folder_title">Klasörü Yeniden Adlandır</div>
        <button class="btn-icon" onclick="closeRenameFolderModal()">&times;</button>
      </div>
      <form onsubmit="handleRenameFolder(event)" style="padding:1.5rem;">
        <input type="hidden" id="rename-folder-old-name" value="">
        <input type="hidden" id="rename-folder-id" value="0">
        <div class="form-group">
          <label class="form-label" data-i18n="folder_new_name_label">Klasörün Yeni Adı *</label>
          <input type="text" id="rename-folder-new-name" class="form-control" placeholder="Örn: Faturalar" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label" data-i18n="folder_color_label">Klasör Rengi</label>
          <div style="display:flex;gap:0.75rem;align-items:center;">
            <input type="color" id="rename-folder-color" value="#4f46e5" style="width:45px;height:38px;padding:2px;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-surface-elevated);cursor:pointer;">
            <span style="font-size:0.8rem;color:var(--text-muted);" data-i18n="folder_color_desc">Sol menüde klasör simgesi rengi</span>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeRenameFolderModal()" data-i18n="common_cancel">İptal</button>
          <button type="submit" class="btn-send" data-i18n="folder_rename_btn">Adı Güncelle</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MOVE EMAIL TO FOLDER MODAL -->
  <div class="compose-overlay" id="move-modal">
    <div class="compose-window" style="width:380px;">
      <div class="compose-header">
        <div class="compose-title" data-i18n="move_modal_title">Klasöre Taşı</div>
        <button class="btn-icon" onclick="closeMoveModal()">&times;</button>
      </div>
      <div style="padding:1.25rem;">
        <p style="font-size:0.825rem;color:var(--text-muted);margin-bottom:0.75rem;" data-i18n="move_select_target">Taşımak istediğiniz hedef klasörü seçin:</p>
        <div id="move-folders-list" style="display:flex;flex-direction:column;gap:0.4rem;max-height:260px;overflow-y:auto;padding-right:4px;">
          <!-- Populated via JS -->
        </div>
        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;">
          <button type="button" class="chip" onclick="closeMoveModal();openNewFolderModal();" data-i18n="move_new_folder">+ Yeni Klasör Aç</button>
          <button type="button" class="btn-icon" style="width:auto;padding:0 0.85rem;" onclick="closeMoveModal()" data-i18n="common_cancel">Vazgeç</button>
        </div>
      </div>
    </div>
  </div>

  <!-- CREATE / EDIT FILTER MODAL -->
  <div class="compose-overlay" id="filter-modal">
    <div class="compose-window" style="width:520px;">
      <div class="compose-header">
        <div class="compose-title" id="filter-modal-title" data-i18n="filter_modal_title_new">Yeni Filtre & Yönlendirme Kuralı</div>
        <button class="btn-icon" onclick="closeFilterModal()">&times;</button>
      </div>
      <form onsubmit="handleSaveFilter(event)" style="padding:1.5rem;">
        <input type="hidden" id="filter-id" value="">

        <div class="form-group">
          <label class="form-label" data-i18n="filter_name_label">Kural Adı *</label>
          <input type="text" id="filter-name" class="form-control" placeholder="Örn: Fatura ve Ödeme Bildirimleri" required>
        </div>

        <div style="background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1.25rem;">
          <div style="font-size:0.8rem;font-weight:700;margin-bottom:0.75rem;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.05em;" data-i18n="filter_criteria_heading">Eşleşme Kriteri (Eğer)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
            <div>
              <label class="form-label" data-i18n="filter_field_label">Alan</label>
              <select id="filter-match-field" class="form-control">
                <option value="subject">Konu (Subject)</option>
                <option value="from">Kimden (Gönderen / From)</option>
                <option value="to">Kime (Alıcı / To)</option>
                <option value="body">Mesaj İçeriği</option>
              </select>
            </div>
            <div>
              <label class="form-label" data-i18n="filter_condition_label">Koşul</label>
              <select id="filter-match-type" class="form-control">
                <option value="contains">İçeriyorsa</option>
                <option value="equals">Tam Eşleşiyorsa</option>
                <option value="starts_with">Bununla Başlıyorsa</option>
              </select>
            </div>
          </div>
          <div>
            <label class="form-label" data-i18n="filter_value_label">Aranacak Değer / Kelime *</label>
            <input type="text" id="filter-match-value" class="form-control" placeholder="Örn: fatura, dekont, muhasebe@..." required>
          </div>
        </div>

        <div style="background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1.25rem;">
          <div style="font-size:0.8rem;font-weight:700;margin-bottom:0.75rem;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.05em;" data-i18n="filter_action_heading">Uygulanacak Eylem (O Halde)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <div>
              <label class="form-label" data-i18n="filter_action_label">Eylem</label>
              <select id="filter-action-type" class="form-control" onchange="toggleFilterTargetBox(this.value)">
                <option value="move">Klasöre Taşı</option>
                <option value="star">Yıldız Ekle (Önemli Yap)</option>
                <option value="read">Okundu Olarak İşaretle</option>
                <option value="delete">Çöp Kutusuna Taşı</option>
              </select>
            </div>
            <div id="filter-target-group">
              <label class="form-label" data-i18n="filter_target_label">Hedef Klasör</label>
              <select id="filter-action-target" class="form-control">
                <!-- Populated via JS with custom + system folders -->
              </select>
            </div>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.5rem;">
          <div style="display:flex;align-items:center;gap:0.5rem;">
            <input type="checkbox" id="filter-is-active" checked style="width:16px;height:16px;cursor:pointer;">
            <label for="filter-is-active" style="font-size:0.85rem;cursor:pointer;" data-i18n="filter_is_active_label">Bu kural hemen aktif olsun</label>
          </div>
          <div style="display:flex;align-items:center;gap:0.5rem;">
            <input type="checkbox" id="filter-run-now" checked style="width:16px;height:16px;cursor:pointer;">
            <label for="filter-run-now" style="font-size:0.85rem;cursor:pointer;color:var(--primary);font-weight:600;" data-i18n="filter_apply_existing_label">Kaydettikten sonra mevcut e-postalara hemen uygula (çalıştır)</label>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeFilterModal()" data-i18n="common_cancel">İptal</button>
          <button type="submit" class="btn-send" data-i18n="filter_save_btn">Kuralı Kaydet</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TASK ADD / EDIT MODAL -->
  <div class="compose-overlay" id="task-modal" style="display:none;">
    <div class="compose-window" style="width:480px;border-radius:0 !important;">
      <div class="compose-header">
        <div class="compose-title" id="task-modal-title" data-i18n="task_modal_title_new">Yeni Görev</div>
        <button type="button" class="btn-icon" onclick="closeTaskModal()">&times;</button>
      </div>
      <form onsubmit="handleSaveTask(event)" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
        <input type="hidden" id="task-modal-id" value="0">
        <div>
          <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="task_title_label">Görev Başlığı *</label>
          <input type="text" id="task-modal-title-input" class="search-input" required placeholder="Örn: Haftalık satış raporunu hazırla" style="border-radius:0 !important;padding:0.5rem 0.75rem;width:100%;">
        </div>
        <div>
          <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="task_desc_label">Açıklama / Detaylar</label>
          <textarea id="task-modal-desc-input" class="search-input" rows="3" placeholder="Görevle ilgili ek bilgiler veya adımlar..." style="border-radius:0 !important;padding:0.5rem 0.75rem;width:100%;height:auto;resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:1rem;">
          <div style="flex:1;">
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="task_priority_label">Öncelik</label>
            <select id="task-modal-priority-input" class="task-select" style="width:100%;">
              <option value="medium">Orta Öncelik</option>
              <option value="high">Yüksek Öncelik</option>
              <option value="low">Düşük Öncelik</option>
            </select>
          </div>
          <div style="flex:1;">
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="task_due_label">Son Tarih</label>
            <input type="date" id="task-modal-due-input" class="task-select" style="width:100%;">
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:0.5rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;border-radius:0 !important;" onclick="closeTaskModal()" data-i18n="common_cancel">Vazgeç</button>
          <button type="submit" class="btn-send" style="border-radius:0 !important;padding:0.5rem 1.25rem;">Kaydet</button>
        </div>
      </form>
    </div>
  </div>

  <!-- NOTE ADD / EDIT MODAL -->
  <div class="compose-overlay" id="note-modal" style="display:none;">
    <div class="compose-window" style="width:520px;border-radius:0 !important;">
      <div class="compose-header">
        <div class="compose-title" id="note-modal-title" data-i18n="notes_modal_title_new">Yeni Not</div>
        <button type="button" class="btn-icon" onclick="closeNoteModal()">&times;</button>
      </div>
      <form onsubmit="handleSaveNote(event)" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
        <input type="hidden" id="note-modal-id" value="0">
        <div>
          <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="note_title_label">Not Başlığı *</label>
          <input type="text" id="note-modal-title-input" class="search-input" required placeholder="Not başlığı..." style="border-radius:0 !important;padding:0.5rem 0.75rem;width:100%;">
        </div>
        <div>
          <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.25rem;" data-i18n="note_content_label">Not İçeriği</label>
          <textarea id="note-modal-content-input" class="search-input" rows="6" placeholder="Notunuzu buraya yazın..." style="border-radius:0 !important;padding:0.5rem 0.75rem;width:100%;height:auto;resize:vertical;"></textarea>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
          <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;" data-i18n="note_color_label">Renk Vurgusu</label>
            <div style="display:flex;gap:0.5rem;" id="note-color-picker">
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#4f46e5" checked style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot active" style="display:inline-block;width:24px;height:24px;background:#4f46e5;border:2px solid #ffffff;outline:2px solid #4f46e5;"></span></label>
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#10b981" style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot" style="display:inline-block;width:24px;height:24px;background:#10b981;border:2px solid transparent;"></span></label>
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#f59e0b" style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot" style="display:inline-block;width:24px;height:24px;background:#f59e0b;border:2px solid transparent;"></span></label>
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#ef4444" style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot" style="display:inline-block;width:24px;height:24px;background:#ef4444;border:2px solid transparent;"></span></label>
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#06b6d4" style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot" style="display:inline-block;width:24px;height:24px;background:#06b6d4;border:2px solid transparent;"></span></label>
              <label style="cursor:pointer;"><input type="radio" name="note_color_radio" value="#8b5cf6" style="display:none;" onchange="updateNoteColorSelection(this.value)"><span class="color-dot" style="display:inline-block;width:24px;height:24px;background:#8b5cf6;border:2px solid transparent;"></span></label>
            </div>
            <input type="hidden" id="note-modal-color-val" value="#4f46e5">
          </div>
          <div style="display:flex;align-items:center;gap:0.4rem;padding-top:1rem;">
            <input type="checkbox" id="note-modal-pin" style="width:16px;height:16px;accent-color:var(--primary);cursor:pointer;">
            <label for="note-modal-pin" style="font-size:0.8rem;font-weight:600;cursor:pointer;color:var(--text-secondary);" data-i18n="note_pin_label">Başa Sabitle</label>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:0.5rem;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;border-radius:0 !important;" onclick="closeNoteModal()" data-i18n="common_cancel">Vazgeç</button>
          <button type="submit" class="btn-send" style="border-radius:0 !important;padding:0.5rem 1.25rem;">Kaydet</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

  <!-- Custom Context Menu for Emails -->
  <div id="mail-context-menu" class="custom-context-menu">
    <div class="context-menu-item" onclick="contextMenuAction('filter')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <span data-i18n="mail_act_create_filter">Filtre / Kural Oluştur</span>
    </div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="contextMenuAction('toggle_read')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      <span data-i18n="mail_act_toggle_read">Okundu / Okunmadı Yap</span>
    </div>
    <div class="context-menu-item" onclick="contextMenuAction('star')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      <span data-i18n="mail_act_toggle_star">Yıldız Ekle / Kaldır</span>
    </div>
    <div class="context-menu-item" onclick="contextMenuAction('move')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="12 11 12 17 9 14"/></svg>
      <span data-i18n="mail_act_move">Klasöre Taşı</span>
    </div>
    <div class="context-menu-item" onclick="contextMenuAction('spam')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span id="ctx-spam-label" data-i18n="mail_act_spam">Spam Olarak İşaretle</span>
    </div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item danger" onclick="contextMenuAction('delete')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      <span data-i18n="mail_act_delete">E-postayı Sil</span>
    </div>
  </div>

  <!-- Custom Context Menu for Folders -->
  <div id="folder-context-menu" class="custom-context-menu">
    <div class="context-menu-item" onclick="folderContextMenuAction('rename')">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      <span data-i18n="rename_folder">Klasörü Yeniden Adlandır</span>
    </div>
    <div class="context-menu-item" onclick="folderContextMenuAction('color')">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
      <span data-i18n="folder_change_color">Klasör Rengini Değiştir</span>
    </div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item danger" onclick="folderContextMenuAction('delete')">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      <span data-i18n="delete_folder">Klasörü Sil</span>
    </div>
  </div>

<!-- CLIENT JAVASCRIPT ENGINE -->
<script>
window.USER_SIGNATURE = <?= json_encode($active_account ? ($active_account->signature ?? '') : '') ?>;
window.CURRENT_USER_EMAIL = <?= json_encode($active_account ? ($active_account->email ?? '') : '') ?>;
window.CURRENT_USER_NAME = <?= json_encode($active_account ? ($active_account->name ?: '') : '') ?>;
window.DEFAULT_THEME = <?= json_encode($theme) ?>;
window.DEFAULT_CUSTOM_WALLPAPER = <?= json_encode(!empty($custom_wallpaper) ? $custom_wallpaper : '') ?>;
window.MAIL_DOMAIN = <?= json_encode(defined("MAIL_DOMAIN") ? MAIL_DOMAIN : "") ?>;
window.HAS_ACTIVE_ACCOUNT = <?= !empty($active_account) ? 'true' : 'false' ?>;
window.IS_ADMIN_ACCOUNT = <?= !empty($active_account->is_admin) ? 'true' : 'false' ?>;
</script>
<script src="assets/js/portal-app.js?v=<?= @filemtime(__DIR__ . '/assets/js/portal-app.js') ?: time() ?>"></script>

<?php if (!empty($active_account)): ?>
<!-- Fullscreen Screen Lock Overlay -->
<div id="screen-lock-overlay" class="screen-lock-overlay">
  <div class="screen-lock-card">
    <div id="screen-lock-clock" class="screen-lock-time">12:00:00</div>
    <div id="screen-lock-date" class="screen-lock-date">31 Ekim 2026, Cumartesi</div>
    
    <div class="screen-lock-avatar-wrap">
      <div class="screen-lock-avatar" id="screen-lock-avatar">
        <?= strtoupper(substr($active_account->name ?: $active_account->email, 0, 1)) ?>
      </div>
      <div class="screen-lock-badge" title="Oturum Kilitlendi">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
    </div>

    <div class="screen-lock-user-name" id="screen-lock-name"><?= htmlspecialchars($active_account->name ?: $active_account->email) ?></div>
    <div class="screen-lock-user-email" id="screen-lock-email"><?= htmlspecialchars($active_account->email) ?></div>

    <form onsubmit="handleScreenUnlock(event); return false;">
      <div class="screen-lock-input-group">
        <input type="password" id="screen-lock-password" class="screen-lock-input" placeholder="Hesap Şifresi..." data-i18n-placeholder="lock_screen_password_ph" autocomplete="current-password" required>
        <button type="button" class="screen-lock-toggle-pass" onclick="toggleScreenLockPasswordVis()" title="Şifreyi Göster/Gizle">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <button type="submit" class="screen-lock-btn-unlock" id="screen-lock-btn-submit">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
        <span data-i18n="lock_screen_unlock_btn">Kilidi Aç</span>
      </button>
      <div id="screen-lock-error" class="screen-lock-error"></div>
    </form>

    <div class="screen-lock-footer">
      <a onclick="handleLogout()" title="Oturumu Kapat">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:2px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span data-i18n="lock_screen_logout">Farklı Hesapla Giriş Yap</span>
      </a>
    </div>
  </div>
</div>

<button type="button" id="floating-chat-btn" class="floating-chat-launcher" onclick="handleFloatingChatClick()" title="Domain İçi Mesajlaşma" data-i18n-title="chat_floating_tooltip">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
  </svg>
  <span id="floating-chat-badge" class="floating-chat-badge" style="display:none;">0</span>
</button>

<?php endif; ?>

</body>
</html>


