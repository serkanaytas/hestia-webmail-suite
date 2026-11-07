<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<main class="main-content">
  <!-- Top Navigation Bar -->
  <header class="top-navbar">
    <div class="top-tabs">
      <a href="<?= base_url('mail') ?>" class="top-tab <?= ($active_tab === 'mail') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <span>Gelen / Gönderilen</span>
      </a>
      <a href="<?= base_url('contacts') ?>" class="top-tab <?= ($active_tab === 'contacts') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <span>Adres Defteri</span>
      </a>
      <a href="<?= base_url('calendar') ?>" class="top-tab <?= ($active_tab === 'calendar') ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Takvim</span>
      </a>
    </div>

    <div class="top-actions">
      <button class="btn-icon" title="Yenile" onclick="window.location.reload()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      </button>
      <a href="<?= base_url('settings') ?>" class="btn-icon" title="Sunucu & Ayarlar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </a>
    </div>
  </header>

  <?php if (!empty($needs_setup)): ?>
    <!-- Onboarding Setup View -->
    <div class="page-container" style="display:flex;align-items:center;justify-content:center;">
      <div class="card" style="max-width:620px;text-align:center;padding:2.5rem 2rem;">
        <div style="width:64px;height:64px;background:var(--primary-gradient);border-radius:var(--radius-lg);margin:0 auto 1.5rem;display:flex;align-items:center;justify-content:center;color:#fff;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <h2 style="font-size:1.5rem;font-weight:700;margin-bottom:0.75rem;">HestiaCP Mail Sunucunuza Bağlanın</h2>
        <p style="color:var(--text-secondary);font-size:0.925rem;line-height:1.6;margin-bottom:2rem;">
          HestiaCP kontrol panelinizde oluşturduğunuz e-posta hesabını (IMAP / SMTP) bağlayarak anında e-postalarınızı okuyabilir, yeni e-postalar gönderebilir, adres defterinizi ve takviminizi yönetebilirsiniz.
        </p>
        <div style="display:flex;justify-content:center;gap:1rem;">
          <a href="<?= base_url('settings') ?>" class="btn-send" style="text-decoration:none;padding:0.75rem 1.75rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Hemen E-posta Hesabı Ekle</span>
          </a>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- 2-Pane Mail Workspace -->
    <div class="mail-workspace">
      <!-- Left Pane: Mail List -->
      <section class="mail-list-pane">
        <div class="mail-list-header">
          <form method="GET" action="<?= base_url('mail/folder/' . urlencode($current_folder)) ?>" class="mail-search-toolbar">
            <div class="search-box">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:0.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" class="search-input" placeholder="E-postalarda ara..." value="<?= htmlspecialchars($this->input->get('search') ?? '') ?>" style="border-radius:0 !important;">
            </div>
            <button type="submit" class="mail-search-btn" title="Ara">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <span>Ara</span>
            </button>
          </form>

          <div class="filter-chips">
            <a href="<?= base_url('mail/folder/' . urlencode($current_folder)) ?>" class="chip <?= empty($this->input->get('filter')) ? 'active' : '' ?>">Tümü (<?= $total_messages ?>)</a>
            <a href="<?= base_url('mail/folder/' . urlencode($current_folder) . '?filter=unread') ?>" class="chip <?= ($this->input->get('filter') === 'unread') ? 'active' : '' ?>">Okunmamış</a>
            <a href="<?= base_url('mail/folder/' . urlencode($current_folder) . '?filter=starred') ?>" class="chip <?= ($this->input->get('filter') === 'starred') ? 'active' : '' ?>">Yıldızlı</a>
          </div>
        </div>

        <div class="mail-list-scroll" id="mail-list-container">
          <?php if (!empty($imap_error) && empty($messages)): ?>
            <div style="padding:2rem 1.5rem;text-align:center;color:var(--text-muted);">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" style="margin:0 auto 0.75rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div style="font-weight:600;margin-bottom:0.5rem;color:var(--text-primary);">Sunucuya Bağlanırken Bilgi</div>
              <div style="font-size:0.8rem;line-height:1.5;"><?= htmlspecialchars($imap_error) ?></div>
            </div>
          <?php elseif (empty($messages)): ?>
            <div style="padding:3rem 1.5rem;text-align:center;color:var(--text-muted);">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.4;"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
              <p style="font-size:0.875rem;">Bu klasörde e-posta bulunmuyor.</p>
            </div>
          <?php else: ?>
            <?php 
              $isSpamFolder = (strpos(strtolower($current_folder), 'junk') !== false || strpos(strtolower($current_folder), 'spam') !== false || strpos(strtolower($current_folder), 'istenmeyen') !== false);
            ?>
            <?php foreach ($messages as $msg): ?>
              <?php 
                $senderName = !empty($msg['from']['name']) ? $msg['from']['name'] : $msg['from']['email'];
                $initial = strtoupper(mb_substr($senderName, 0, 1));
                $isUnread = !$msg['is_seen'];
                $isStarred = $msg['is_flagged'];
              ?>
              <div class="mail-item <?= $isUnread ? 'unread' : '' ?>" id="mail-item-<?= $msg['uid'] ?>" onclick="Webmail.loadEmail('<?= htmlspecialchars($current_folder) ?>', <?= $msg['uid'] ?>)">
                <div class="mail-avatar"><?= $initial ?></div>
                <div class="mail-content">
                  <div class="mail-header-row">
                    <span class="mail-sender" title="<?= htmlspecialchars($msg['from']['email']) ?>"><?= htmlspecialchars($senderName) ?></span>
                    <span class="mail-date"><?= htmlspecialchars($msg['date']) ?></span>
                  </div>
                  <div class="mail-subject"><?= htmlspecialchars($msg['subject']) ?></div>
                  
                  <div class="mail-badges">
                    <button class="star-btn <?= $isStarred ? 'starred' : '' ?>" id="star-<?= $msg['uid'] ?>" onclick="Webmail.toggleStar('<?= htmlspecialchars($current_folder) ?>', <?= $msg['uid'] ?>, event)" title="Yıldızla">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="<?= $isStarred ? '#fbbf24' : 'none' ?>" stroke="<?= $isStarred ? '#fbbf24' : 'currentColor' ?>" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                    <button class="spam-action-btn <?= $isSpamFolder ? 'in-spam' : '' ?>" id="spam-<?= $msg['uid'] ?>" onclick="Webmail.markSpam('<?= htmlspecialchars($current_folder) ?>', <?= $msg['uid'] ?>, '<?= $isSpamFolder ? 'unspam' : 'spam' ?>', event)" title="<?= $isSpamFolder ? 'Spam Değil (Gelen Kutusuna Taşı)' : 'Spam Olarak İşaretle' ?>">
                      <?php if ($isSpamFolder): ?>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                      <?php else: ?>
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      <?php endif; ?>
                    </button>
                    <?php if (!empty($msg['has_attachment'])): ?>
                      <span class="badge-icon" title="Ek dosya var">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

      <!-- Right Pane: Reading Pane -->
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
  <?php endif; ?>
</main>
