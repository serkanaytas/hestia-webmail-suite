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
      <button class="btn-send" style="padding:0.45rem 1rem;font-size:0.825rem;" onclick="openContactModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span data-i18n="contacts_new">Yeni Kişi Ekle</span>
      </button>

      <a href="<?= base_url('contacts/export_vcf') ?>" class="btn-icon" title="vCard (.vcf) Olarak İndir">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </a>

      <button class="btn-icon" title="Kişileri İçe Aktar (vCard/CSV)" onclick="document.getElementById('import-modal').classList.add('active')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </button>
    </div>
  </header>

  <div class="page-container">
    <div class="page-header-row">
      <div>
        <h1 class="page-title" data-i18n="contacts_title">Adres Defteri & Kişiler</h1>
        <div class="page-subtitle"><?= count($contacts) ?> kayıtlı kişi listeleniyor</div>
      </div>

      <!-- Live Search & Filters -->
      <form method="GET" action="<?= base_url('contacts') ?>" style="display:flex;gap:0.75rem;align-items:center;">
        <div class="search-box" style="margin:0;width:280px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="q" class="search-input" placeholder="İsim, e-posta veya şirket ara..." data-i18n-placeholder="contacts_search_ph" value="<?= htmlspecialchars($current_search ?? '') ?>">
        </div>

        <div class="filter-chips">
          <a href="<?= base_url('contacts') ?>" class="chip <?= ($current_group === 'all' && empty($only_favorites)) ? 'active' : '' ?>" data-i18n="common_all">Tümü</a>
          <a href="<?= base_url('contacts?favorites=1') ?>" class="chip <?= !empty($only_favorites) ? 'active' : '' ?>" data-i18n="contacts_favorites">Favoriler</a>
          <?php if (!empty($groups)): ?>
            <?php foreach ($groups as $g): ?>
              <a href="<?= base_url('contacts?group=' . urlencode($g->group_name)) ?>" class="chip <?= ($current_group === $g->group_name) ? 'active' : '' ?>"><?= htmlspecialchars($g->group_name) ?> (<?= $g->total ?>)</a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php if (empty($contacts)): ?>
      <div class="card" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 1rem;opacity:0.4;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <h3 style="font-size:1.1rem;color:var(--text-primary);margin-bottom:0.5rem;" data-i18n="contacts_none_found">Kişi Bulunamadı</h3>
        <p style="font-size:0.875rem;margin-bottom:1.5rem;" data-i18n="contacts_none_found_desc">Arama kriterinize uygun veya kayıtlı kişi yok.</p>
        <button class="btn-send" style="margin:0 auto;" onclick="openContactModal()" data-i18n="contacts_add_first">+ İlk Kişiyi Ekle</button>
      </div>
    <?php else: ?>
      <div class="contacts-grid">
        <?php foreach ($contacts as $c): ?>
          <?php 
            $initials = strtoupper(mb_substr($c->first_name, 0, 1) . mb_substr($c->last_name, 0, 1));
            $avatarBg = $c->avatar_color ?: '#4f46e5';
          ?>
          <div class="contact-card" id="contact-card-<?= $c->id ?>">
            <div class="contact-top">
              <div class="contact-avatar" style="background-color: <?= htmlspecialchars($avatarBg) ?>;">
                <?= htmlspecialchars($initials) ?>
              </div>
              <div style="flex:1;min-width:0;">
                <div class="contact-name"><?= htmlspecialchars($c->first_name . ' ' . $c->last_name) ?></div>
                <div class="contact-title"><?= htmlspecialchars($c->job_title ?: ($c->company ?: 'Kişi')) ?></div>
              </div>
              <button class="star-btn <?= $c->is_favorite ? 'starred' : '' ?>" onclick="toggleContactFav(<?= $c->id ?>)" title="Favori">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="<?= $c->is_favorite ? '#fbbf24' : 'none' ?>" stroke="<?= $c->is_favorite ? '#fbbf24' : 'currentColor' ?>" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </button>
            </div>

            <div class="contact-info-list">
              <div class="contact-info-item" title="<?= htmlspecialchars($c->email) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span><?= htmlspecialchars($c->email) ?></span>
              </div>
              <?php if (!empty($c->phone)): ?>
                <div class="contact-info-item">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  <span><?= htmlspecialchars($c->phone) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($c->company)): ?>
                <div class="contact-info-item">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  <span><?= htmlspecialchars($c->company) ?></span>
                </div>
              <?php endif; ?>
            </div>

            <div class="contact-actions">
              <button class="btn-send" style="padding:0.35rem 0.75rem;font-size:0.775rem;" onclick="Webmail.openCompose('<?= htmlspecialchars($c->email) ?>')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                <span>E-posta Yaz</span>
              </button>

              <div style="display:flex;gap:0.4rem;">
                <button class="btn-icon" style="width:30px;height:30px;" title="Düzenle" onclick='editContact(<?= json_encode($c) ?>)'>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="btn-icon" style="width:30px;height:30px;" title="Sil" onclick="deleteContact(<?= $c->id ?>)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Add / Edit Contact Modal -->
<div class="compose-overlay" id="contact-modal">
  <div class="compose-window" style="width:520px;">
    <div class="compose-header">
      <div class="compose-title" id="contact-modal-title">Yeni Kişi Ekle</div>
      <button class="btn-icon" onclick="closeContactModal()">&times;</button>
    </div>
    <form id="contact-form" onsubmit="saveContact(event)" style="padding:1.5rem;">
      <input type="hidden" name="id" id="contact-id" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Adı *</label>
          <input type="text" name="first_name" id="c-first-name" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Soyadı</label>
          <input type="text" name="last_name" id="c-last-name" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">E-posta Adresi *</label>
        <input type="email" name="email" id="c-email" class="form-control" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Telefon</label>
          <input type="text" name="phone" id="c-phone" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Grup / Kategori</label>
          <input type="text" name="group_name" id="c-group" class="form-control" placeholder="Genel, Müşteri, vb.">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Şirket</label>
          <input type="text" name="company" id="c-company" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Pozisyon / Ünvan</label>
          <input type="text" name="job_title" id="c-title" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notlar</label>
        <textarea name="notes" id="c-notes" class="form-control" rows="2"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1rem;">
        <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeContactModal()">İptal</button>
        <button type="submit" class="btn-send">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<!-- Import Modal -->
<div class="compose-overlay" id="import-modal">
  <div class="compose-window" style="width:460px;">
    <div class="compose-header">
      <div class="compose-title">Kişileri İçe Aktar</div>
      <button class="btn-icon" onclick="document.getElementById('import-modal').classList.remove('active')">&times;</button>
    </div>
    <form onsubmit="importContacts(event)" style="padding:1.5rem;">
      <p style="color:var(--text-secondary);font-size:0.875rem;margin-bottom:1.25rem;">
        Outlook, Gmail, iPhone veya Android rehberinizden dışa aktardığınız <strong>.vcf (vCard)</strong> veya <strong>.csv</strong> dosyasını seçerek tüm kişilerinizi tek tıkla yükleyin.
      </p>
      <div class="form-group">
        <label class="form-label">Rehber Dosyası (.vcf veya .csv)</label>
        <input type="file" id="import-file" class="form-control" accept=".vcf,.csv" required>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
        <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="document.getElementById('import-modal').classList.remove('active')">İptal</button>
        <button type="submit" class="btn-send">İçe Aktar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openContactModal() {
  document.getElementById('contact-form').reset();
  document.getElementById('contact-id').value = '';
  document.getElementById('contact-modal-title').textContent = 'Yeni Kişi Ekle';
  document.getElementById('contact-modal').classList.add('active');
}
function closeContactModal() {
  document.getElementById('contact-modal').classList.remove('active');
}
function editContact(contact) {
  document.getElementById('contact-id').value = contact.id;
  document.getElementById('c-first-name').value = contact.first_name || '';
  document.getElementById('c-last-name').value = contact.last_name || '';
  document.getElementById('c-email').value = contact.email || '';
  document.getElementById('c-phone').value = contact.phone || '';
  document.getElementById('c-group').value = contact.group_name || 'Genel';
  document.getElementById('c-company').value = contact.company || '';
  document.getElementById('c-title').value = contact.job_title || '';
  document.getElementById('c-notes').value = contact.notes || '';
  document.getElementById('contact-modal-title').textContent = 'Kişiyi Düzenle';
  document.getElementById('contact-modal').classList.add('active');
}
function saveContact(e) {
  e.preventDefault();
  const form = document.getElementById('contact-form');
  const formData = new FormData(form);
  fetch(BASE_URL + 'contacts/save', { method: 'POST', body: formData })
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
function deleteContact(id) {
  if (!confirm('Bu kişiyi silmek istediğinizden emin misiniz?')) return;
  fetch(BASE_URL + 'contacts/delete/' + id)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast('Kişi silindi.');
        document.getElementById('contact-card-' + id)?.remove();
      }
    });
}
function toggleContactFav(id) {
  fetch(BASE_URL + 'contacts/toggle_favorite/' + id)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        window.location.reload();
      }
    });
}
function importContacts(e) {
  e.preventDefault();
  const fileInput = document.getElementById('import-file');
  if (!fileInput.files[0]) return;
  const formData = new FormData();
  formData.append('file', fileInput.files[0]);
  fetch(BASE_URL + 'contacts/import', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast(data.message);
        setTimeout(() => window.location.reload(), 1000);
      } else {
        Webmail.showToast(data.message, 'error');
      }
    });
}
</script>
