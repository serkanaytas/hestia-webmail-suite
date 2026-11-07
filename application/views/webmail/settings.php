<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<main class="main-content">
  <!-- Top Navigation Bar -->
  <header class="top-navbar">
    <div class="top-tabs">
      <a href="<?= base_url('mail') ?>" class="top-tab <?= ($active_tab === 'mail') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <span data-i18n="nav_email">E-Posta</span>
      </a>
      <a href="<?= base_url('contacts') ?>" class="top-tab <?= ($active_tab === 'contacts') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <span data-i18n="nav_contacts">Adres Defteri</span>
      </a>
      <a href="<?= base_url('calendar') ?>" class="top-tab <?= ($active_tab === 'calendar') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span data-i18n="nav_calendar">Takvim</span>
      </a>
    </div>

    <div class="top-actions">
      <button class="btn-send" style="padding:0.45rem 1rem;font-size:0.825rem;" onclick="openAccountModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span data-i18n="add_account">Yeni Mail Hesabı Ekle</span>
      </button>
    </div>
  </header>

  <div class="page-container">
    <div class="page-header-row">
      <div>
        <h1 class="page-title">Ayarlar & HestiaCP Entegrasyonu</h1>
        <div class="page-subtitle">Mail hesapları, sunucu bağlantı teşhisi ve genel tercihler</div>
      </div>
    </div>

    <!-- Settings Sub Tabs -->
    <div class="settings-tabs">
      <button class="settings-tab-btn active" onclick="switchSettingsTab('accounts', this)" data-i18n="tab_accounts">E-Posta Hesapları</button>
      <button class="settings-tab-btn" onclick="switchSettingsTab('diagnostics', this)" data-i18n="tab_diagnostics">Bağlantı Teşhisi</button>
      <button class="settings-tab-btn" onclick="switchSettingsTab('preferences', this)" data-i18n="tab_general_sig">Genel & İmza</button>
      <button class="settings-tab-btn" onclick="switchSettingsTab('hestia_guide', this)" data-i18n="tab_hestia_guide">HestiaCP Kurulum Rehberi</button>
    </div>

    <!-- TAB 1: ACCOUNTS -->
    <div id="tab-accounts" class="settings-panel">
      <?php if (empty($accounts)): ?>
        <div class="card" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 1rem;opacity:0.4;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <h3 style="font-size:1.1rem;color:var(--text-primary);margin-bottom:0.5rem;" data-i18n="no_accounts_defined">Tanımlı Hesap Yok</h3>
          <p style="font-size:0.875rem;margin-bottom:1.5rem;" data-i18n="connect_hestia_now">HestiaCP sunucunuzdaki mail hesabınızı hemen bağlayın.</p>
          <button class="btn-send" style="margin:0 auto;" onclick="openAccountModal()">+ Hestia Mail Hesabı Ekle</button>
        </div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(360px, 1fr));gap:1.25rem;">
          <?php foreach ($accounts as $acc): ?>
            <div class="card" style="position:relative;margin:0;">
              <?php if (!empty($acc->is_default)): ?>
                <span style="position:absolute;top:1rem;right:1rem;background:var(--success-light);color:var(--success);font-size:0.75rem;font-weight:700;padding:2px 8px;border-radius:var(--radius-full);">Varsayılan Hesap</span> data-i18n="default_account_badge"
              <?php endif; ?>

              <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
                <div class="account-avatar" style="width:42px;height:42px;font-size:1rem;">
                  <?= strtoupper(substr($acc->name ?: $acc->email, 0, 1)) ?>
                </div>
                <div>
                  <div style="font-size:1rem;font-weight:700;"><?= htmlspecialchars($acc->name) ?></div>
                  <div style="font-size:0.8rem;color:var(--text-muted);"><?= htmlspecialchars($acc->email) ?></div>
                </div>
              </div>

              <div style="background:var(--bg-surface-elevated);border-radius:var(--radius-sm);padding:0.75rem;font-size:0.8rem;display:flex;flex-direction:column;gap:0.35rem;margin-bottom:1.25rem;">
                <div style="display:flex;justify-content:space-between;">
                  <span style="color:var(--text-muted);">IMAP Sunucusu:</span>
                  <strong><?= htmlspecialchars($acc->imap_host) ?>:<?= $acc->imap_port ?> (<?= strtoupper($acc->imap_encryption) ?>)</strong>
                </div>
                <div style="display:flex;justify-content:space-between;">
                  <span style="color:var(--text-muted);">SMTP Sunucusu:</span>
                  <strong><?= htmlspecialchars($acc->smtp_host) ?>:<?= $acc->smtp_port ?> (<?= strtoupper($acc->smtp_encryption) ?>)</strong>
                </div>
              </div>

              <div style="display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;gap:0.5rem;">
                  <a href="<?= base_url('mail/switch_account/' . $acc->id) ?>" class="chip" style="font-weight:600;">Bu Hesaba Geç</a>
                  <?php if (empty($acc->is_default)): ?>
                    <button class="chip" onclick="setDefaultAccount(<?= $acc->id ?>)">Varsayılan Yap</button>
                  <?php endif; ?>
                </div>

                <div style="display:flex;gap:0.4rem;">
                  <button class="btn-icon" style="width:32px;height:32px;" title="Düzenle" onclick='editAccount(<?= json_encode($acc) ?>)'>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="btn-icon" style="width:32px;height:32px;color:var(--danger);" title="Sil" onclick="deleteAccount(<?= $acc->id ?>)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- TAB 2: DIAGNOSTICS -->
    <div id="tab-diagnostics" class="settings-panel" style="display:none;">
      <div class="card" style="max-width:700px;">
        <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;">Canlı HestiaCP Sunucu Bağlantı Testi</h3>
        <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:1.5rem;">
          HestiaCP sunucunuzun IMAP (993) ve SMTP (465/587) portlarına anlık test paketi gönderir; gecikme süresini, SSL el sıkışmasını ve posta kutusu klasörlerini listeler.
        </p>

        <form id="diag-form" onsubmit="runDiagnosticTest(event)">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
            <div class="form-group">
              <label class="form-label">IMAP / Posta Sunucusu Host *</label>
              <input type="text" id="diag-host" class="form-control" placeholder="mail.alanadiniz.com" value="<?= !empty($active_account) ? htmlspecialchars($active_account->imap_host) : '' ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">IMAP Portu</label>
              <input type="number" id="diag-port" class="form-control" value="993">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="form-group">
              <label class="form-label">E-Posta Adresi / Kullanıcı Adı *</label>
              <input type="email" id="diag-user" class="form-control" placeholder="info@alanadiniz.com" value="<?= !empty($active_account) ? htmlspecialchars($active_account->email) : '' ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">E-Posta Şifresi *</label>
              <input type="password" id="diag-pass" class="form-control" placeholder="HestiaCP mail şifresi" required>
            </div>
          </div>

          <button type="submit" id="btn-run-diag" class="btn-send" style="margin-top:0.5rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <span>Bağlantıyı Test Et</span>
          </button>
        </form>

        <div id="diag-result-box" style="margin-top:1.5rem;display:none;"></div>
      </div>
    </div>

    <!-- TAB 3: PREFERENCES & SIGNATURE -->
    <div id="tab-preferences" class="settings-panel" style="display:none;">
      <div class="card" style="max-width:700px;">
        <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:1.25rem;">Genel Tercihler & İmza</h3>
        <form id="pref-form" onsubmit="savePreferences(event)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
            <div class="form-group">
              <label class="form-label">Görünüm Teması</label>
              <select name="theme" class="form-control" id="pref-theme">
                <option value="dark">Karanlık Mod (Modern Dark)</option>
                <option value="light">Aydınlık Mod (Clean Light)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Sayfa Başına E-posta</label>
              <select name="page_size" class="form-control" id="pref-page-size">
                <option value="15">15 E-posta</option>
                <option value="25" selected>25 E-posta</option>
                <option value="50">50 E-posta</option>
                <option value="100">100 E-posta</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">E-posta İmzası (Otomatik eklenir)</label>
            <textarea name="signature" id="pref-signature" class="form-control" rows="4" placeholder="Saygılarımla,&#10;Adınız Soyadınız&#10;Şirketiniz | +90 555 000 00 00"><?= !empty($active_account->signature) ? htmlspecialchars($active_account->signature) : '' ?></textarea>
          </div>

          <button type="submit" class="btn-send">Tercihleri Kaydet</button>
        </form>
      </div>
    </div>

    <!-- TAB 4: HESTIACP SERVER SETUP GUIDE -->
    <div id="tab-hestia_guide" class="settings-panel" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <div class="card">
          <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            HestiaCP Mail Portları & Protokoller
          </h3>
          <p style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:1rem;">
            HestiaCP üzerinde çalışan Dovecot (IMAP) ve Exim4 (SMTP) sunucusuna bağlanırken aşağıdaki standart portlar kullanılır:
          </p>
          <ul style="font-size:0.825rem;color:var(--text-secondary);line-height:1.8;padding-left:1.25rem;">
            <li><strong>IMAP SSL (Önerilen):</strong> Port <code>993</code> (SSL/TLS şifreli)</li>
            <li><strong>IMAP STARTTLS:</strong> Port <code>143</code></li>
            <li><strong>SMTP SSL (Önerilen):</strong> Port <code>465</code> (SSL/TLS şifreli)</li>
            <li><strong>SMTP Gönderim Portu:</strong> Port <code>587</code> (STARTTLS)</li>
            <li><strong>Host Adresi:</strong> Genellikle <code>mail.alanadiniz.com</code> veya sunucu IP adresi</li>
          </ul>
        </div>

        <div class="card">
          <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            DNS & Teslim Edilebilirlik (Spam Önleme)
          </h3>
          <p style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:1rem;">
            E-postalarınızın Gmail, Hotmail vb. sağlayıcılarda spam klasörüne düşmemesi için alan adı DNS yönetiminizde tanımlamanız gereken kayıtlar:
          </p>
          <ul style="font-size:0.825rem;color:var(--text-secondary);line-height:1.8;padding-left:1.25rem;">
            <li><strong>MX Kaydı:</strong> <code>alanadiniz.com -> mail.alanadiniz.com (Öncelik 10)</code></li>
            <li><strong>SPF Kaydı (TXT):</strong> <code>v=spf1 a mx ip4:SUNUCU_IP ~all</code></li>
            <li><strong>DKIM Kaydı (TXT):</strong> HestiaCP -> Mail -> Alan Adı Düzenle -> <em>DKIM Support</em> seçildiğinde üretilen anahtar</li>
            <li><strong>DMARC Kaydı (TXT):</strong> <code>_dmarc -> v=DMARC1; p=quarantine;</code></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Add / Edit Mail Account Modal -->
<div class="compose-overlay" id="account-modal">
  <div class="compose-window" style="width:620px;">
    <div class="compose-header">
      <div class="compose-title" id="account-modal-title">HestiaCP Mail Hesabı Ekle</div>
      <button class="btn-icon" onclick="closeAccountModal()">&times;</button>
    </div>
    <form id="account-form" onsubmit="saveAccount(event)" style="padding:1.5rem;">
      <input type="hidden" name="id" id="acc-id" value="">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Hesap Adı / Görünen İsim *</label>
          <input type="text" name="name" id="acc-name" class="form-control" placeholder="Şirket Postası veya Adınız" required>
        </div>
        <div class="form-group">
          <label class="form-label">E-Posta Adresi *</label>
          <input type="email" name="email" id="acc-email" class="form-control" placeholder="info@alanadiniz.com" oninput="autoFillServerSettings(this.value)" required>
        </div>
      </div>

      <!-- IMAP Settings -->
      <div style="background:var(--bg-surface-elevated);padding:1rem;border-radius:var(--radius-sm);margin-bottom:1rem;">
        <div style="font-weight:700;font-size:0.85rem;color:var(--primary);margin-bottom:0.75rem;">Gelen E-posta (IMAP) Ayarları</div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;">
          <div class="form-group" style="margin:0;">
            <label class="form-label">IMAP Sunucusu</label>
            <input type="text" name="imap_host" id="acc-imap-host" class="form-control" placeholder="mail.alanadiniz.com" required>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Port</label>
            <input type="number" name="imap_port" id="acc-imap-port" class="form-control" value="993">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Güvenlik</label>
            <select name="imap_encryption" id="acc-imap-enc" class="form-control">
              <option value="ssl" selected>SSL</option>
              <option value="tls">TLS/STARTTLS</option>
              <option value="none">Yok</option>
            </select>
          </div>
        </div>
      </div>

      <!-- SMTP Settings -->
      <div style="background:var(--bg-surface-elevated);padding:1rem;border-radius:var(--radius-sm);margin-bottom:1rem;">
        <div style="font-weight:700;font-size:0.85rem;color:var(--success);margin-bottom:0.75rem;">Giden E-posta (SMTP) Ayarları</div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;">
          <div class="form-group" style="margin:0;">
            <label class="form-label">SMTP Sunucusu</label>
            <input type="text" name="smtp_host" id="acc-smtp-host" class="form-control" placeholder="mail.alanadiniz.com" required>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Port</label>
            <input type="number" name="smtp_port" id="acc-smtp-port" class="form-control" value="465">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Güvenlik</label>
            <select name="smtp_encryption" id="acc-smtp-enc" class="form-control">
              <option value="ssl" selected>SSL</option>
              <option value="tls">TLS/STARTTLS</option>
              <option value="none">Yok</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Password & Auth -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Kullanıcı Adı (Genelde E-posta)</label>
          <input type="text" name="imap_username" id="acc-username" class="form-control" placeholder="info@alanadiniz.com">
        </div>
        <div class="form-group">
          <label class="form-label">E-Posta Şifresi *</label>
          <input type="password" name="imap_password" id="acc-pass" class="form-control" placeholder="HestiaCP mail şifresi">
        </div>
      </div>

      <div style="margin-top:0.5rem;display:flex;align-items:center;gap:0.5rem;">
        <input type="checkbox" name="is_default" id="acc-default" value="1" checked>
        <label for="acc-default" style="font-size:0.825rem;cursor:pointer;">Bu hesabı varsayılan e-posta hesabı yap</label>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
        <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeAccountModal()">İptal</button>
        <button type="submit" class="btn-send">Hesabı Kaydet</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchSettingsTab(tabName, btn) {
  document.querySelectorAll('.settings-panel').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tabName).style.display = 'block';
  btn.classList.add('active');
}

function openAccountModal() {
  document.getElementById('account-form').reset();
  document.getElementById('acc-id').value = '';
  document.getElementById('account-modal-title').textContent = 'HestiaCP Mail Hesabı Ekle';
  document.getElementById('account-modal').classList.add('active');
}

function closeAccountModal() {
  document.getElementById('account-modal').classList.remove('active');
}

function autoFillServerSettings(email) {
  if (email.includes('@')) {
    const domain = email.split('@')[1];
    if (domain) {
      if (!document.getElementById('acc-imap-host').value) {
        document.getElementById('acc-imap-host').value = 'mail.' + domain;
      }
      if (!document.getElementById('acc-smtp-host').value) {
        document.getElementById('acc-smtp-host').value = 'mail.' + domain;
      }
      if (!document.getElementById('acc-username').value) {
        document.getElementById('acc-username').value = email;
      }
    }
  }
}

function editAccount(acc) {
  document.getElementById('acc-id').value = acc.id;
  document.getElementById('acc-name').value = acc.name || '';
  document.getElementById('acc-email').value = acc.email || '';
  document.getElementById('acc-imap-host').value = acc.imap_host || '';
  document.getElementById('acc-imap-port').value = acc.imap_port || 993;
  document.getElementById('acc-imap-enc').value = acc.imap_encryption || 'ssl';
  document.getElementById('acc-smtp-host').value = acc.smtp_host || '';
  document.getElementById('acc-smtp-port').value = acc.smtp_port || 465;
  document.getElementById('acc-smtp-enc').value = acc.smtp_encryption || 'ssl';
  document.getElementById('acc-username').value = acc.imap_username || acc.email;
  document.getElementById('acc-default').checked = acc.is_default == 1;
  document.getElementById('account-modal-title').textContent = 'Hesabı Düzenle';
  document.getElementById('account-modal').classList.add('active');
}

function saveAccount(e) {
  e.preventDefault();
  const formData = new FormData(document.getElementById('account-form'));
  fetch(BASE_URL + 'settings/save_account', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast(data.message);
        setTimeout(() => window.location.reload(), 600);
      } else {
        Webmail.showToast(data.message, 'error');
      }
    });
}

function deleteAccount(id) {
  if (!confirm('Bu hesabı silmek istediğinizden emin misiniz?')) return;
  fetch(BASE_URL + 'settings/delete_account/' + id)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast('Hesap silindi.');
        setTimeout(() => window.location.reload(), 600);
      }
    });
}

function setDefaultAccount(id) {
  fetch(BASE_URL + 'settings/set_default/' + id)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast('Varsayılan hesap güncellendi.');
        setTimeout(() => window.location.reload(), 600);
      }
    });
}

function runDiagnosticTest(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-run-diag');
  const resultBox = document.getElementById('diag-result-box');
  
  btn.disabled = true;
  btn.innerHTML = 'Test Ediliyor... (IMAP & SMTP)';
  resultBox.style.display = 'block';
  resultBox.innerHTML = '<div style="padding:1rem;background:var(--bg-surface-elevated);border-radius:var(--radius-sm);color:var(--text-muted);">Sunucuya bağlanılıyor, lütfen bekleyin...</div>';

  const formData = new FormData();
  formData.append('imap_host', document.getElementById('diag-host').value);
  formData.append('imap_port', document.getElementById('diag-port').value);
  formData.append('imap_username', document.getElementById('diag-user').value);
  formData.append('imap_password', document.getElementById('diag-pass').value);
  formData.append('smtp_host', document.getElementById('diag-host').value);
  formData.append('smtp_port', 465);
  formData.append('smtp_username', document.getElementById('diag-user').value);
  formData.append('smtp_password', document.getElementById('diag-pass').value);

  fetch(BASE_URL + 'settings/test_connection', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<span>Bağlantıyı Test Et</span>';

      let html = `<div style="padding:1.25rem;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-surface-elevated);">`;
      html += `<div style="font-weight:700;font-size:1rem;margin-bottom:0.75rem;color:${data.success ? 'var(--success)' : 'var(--danger)'};">${data.message}</div>`;
      
      // IMAP info
      html += `<div style="margin-bottom:0.75rem;padding:0.6rem;background:var(--bg-surface);border-radius:var(--radius-sm);">`;
      html += `<div><strong>IMAP (Port 993 SSL):</strong> ${data.imap.success ? '<span style="color:var(--success)">BAŞARILI (' + data.imap.latency + ' ms)</span>' : '<span style="color:var(--danger)">HATA: ' + data.imap.message + '</span>'}</div>`;
      if (data.imap.folders && data.imap.folders.length > 0) {
        html += `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">Bulunan Klasörler: ${data.imap.folders.join(', ')}</div>`;
      }
      html += `</div>`;

      // SMTP info
      html += `<div style="padding:0.6rem;background:var(--bg-surface);border-radius:var(--radius-sm);">`;
      html += `<div><strong>SMTP (Port 465 SSL):</strong> ${data.smtp.success ? '<span style="color:var(--success)">BAŞARILI (' + data.smtp.latency + ' ms)</span>' : '<span style="color:var(--danger)">HATA: ' + data.smtp.message + '</span>'}</div>`;
      html += `</div>`;

      html += `</div>`;
      resultBox.innerHTML = html;
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = '<span>Bağlantıyı Test Et</span>';
      resultBox.innerHTML = '<div style="color:var(--danger);padding:1rem;">Sunucu yanıt vermedi veya ağ hatası oluştu.</div>';
    });
}

function savePreferences(e) {
  e.preventDefault();
  const formData = new FormData(document.getElementById('pref-form'));
  fetch(BASE_URL + 'settings/save_preferences', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast(data.message);
      }
    });
}
</script>
