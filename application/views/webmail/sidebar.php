<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<aside class="sidebar" id="app-sidebar">
  <div class="sidebar-header">
    <a href="<?= base_url('mail') ?>" class="brand">
      <div class="brand-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
      </div>
      <div>
        <div class="brand-title">SA Mail</div>
        <div class="brand-subtitle">Web Suite</div>
      </div>
    </a>

    <button class="btn-icon" id="theme-toggle-btn" title="Karanlık / Aydınlık Mod" data-i18n-title="nav_theme" onclick="Webmail.toggleTheme()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
    </button>
  </div>

  <div class="sidebar-compose">
    <button class="btn-compose" onclick="Webmail.openCompose()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 5v14M5 12h14"/>
      </svg>
      <span data-i18n="new_email">Yeni E-posta</span>
    </button>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-title" data-i18n="nav_modules">Modüller</div>
    <ul class="nav-links">
      <li class="nav-item">
        <a href="<?= base_url('mail') ?>" class="nav-link <?= ($active_tab === 'mail') ? 'active' : '' ?>">
          <div class="nav-link-left">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span data-i18n="nav_email">E-Posta</span>
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= base_url('contacts') ?>" class="nav-link <?= ($active_tab === 'contacts') ? 'active' : '' ?>">
          <div class="nav-link-left">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span data-i18n="nav_contacts">Adres Defteri</span>
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= base_url('calendar') ?>" class="nav-link <?= ($active_tab === 'calendar') ? 'active' : '' ?>">
          <div class="nav-link-left">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span data-i18n="nav_calendar">Takvim</span>
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= base_url('settings') ?>" class="nav-link <?= ($active_tab === 'settings') ? 'active' : '' ?>">
          <div class="nav-link-left">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span data-i18n="nav_settings">Ayarlar & Sunucu</span>
          </div>
        </a>
      </li>
    </ul>

    <?php if (!empty($mailboxes)): ?>
    <div class="nav-section-title" data-i18n="folders">Klasörler</div>
    <ul class="nav-links">
      <?php foreach ($mailboxes as $box): ?>
        <?php $isActive = (!empty($current_folder) && $current_folder === $box['name']); ?>
        <li class="nav-item">
          <a href="<?= base_url('mail/folder/' . urlencode($box['name'])) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
            <div class="nav-link-left">
              <?php if ($box['type'] === 'inbox'): ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
              <?php elseif ($box['type'] === 'sent'): ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              <?php elseif ($box['type'] === 'drafts'): ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              <?php elseif ($box['type'] === 'trash'): ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              <?php elseif ($box['type'] === 'junk'): ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <?php else: ?>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
              <?php endif; ?>
              <span><?= htmlspecialchars($box['display_name']) ?></span>
            </div>
            <?php if (!empty($box['unread']) && $box['unread'] > 0): ?>
              <span class="nav-badge"><?= $box['unread'] ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <?php if (!empty($active_account)): ?>
      <div class="account-selector" title="<?= htmlspecialchars($active_account->email) ?>">
        <div class="account-avatar">
          <?= strtoupper(substr($active_account->name ?: $active_account->email, 0, 1)) ?>
        </div>
        <div class="account-info">
          <div class="account-name"><?= htmlspecialchars($active_account->name ?: 'Hesap') ?></div>
          <div class="account-email"><?= htmlspecialchars($active_account->email) ?></div>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= base_url('settings') ?>" class="account-selector">
        <div class="account-avatar">+</div>
        <div class="account-info">
          <div class="account-name" data-i18n="add_account">Hesap Ekle</div>
          <div class="account-email" data-i18n="connect_hestia_mail">Mail Bağla</div>
        </div>
      </a>
    <?php endif; ?>

    <a href="<?= base_url('settings') ?>" class="btn-icon" title="Hesap Yönetimi">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
        <circle cx="12" cy="12" r="3"/>
      </svg>
    </a>
  </div>
</aside>
