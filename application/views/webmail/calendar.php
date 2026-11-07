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
      <button class="btn-send" style="padding:0.45rem 1rem;font-size:0.825rem;" onclick="openEventModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span data-i18n="cal_new_event">Yeni Etkinlik</span>
      </button>

      <a href="<?= base_url('calendar/export_ics') ?>" class="btn-icon" title="iCalendar (.ics) Olarak İndir" data-i18n-title="cal_download_ics">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </a>
    </div>
  </header>

  <div class="page-container" style="display:flex;gap:1.5rem;overflow:hidden;">
    <!-- Calendar Main Area -->
    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
      <div class="calendar-wrapper" style="flex:1;display:flex;flex-direction:column;">
        <div class="calendar-header">
          <div style="display:flex;align-items:center;gap:1rem;">
            <h2 id="calendar-month-title" style="font-size:1.35rem;font-weight:700;">Ekim 2026</h2>
            <button class="chip" onclick="goToToday()" data-i18n="cal_today">Bugün</button>
          </div>
          <div style="display:flex;align-items:center;gap:0.5rem;">
            <button class="btn-icon" onclick="prevMonth()" title="Önceki Ay" data-i18n-title="cal_prev_month">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="btn-icon" onclick="nextMonth()" title="Sonraki Ay" data-i18n-title="cal_next_month">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>
        </div>

        <!-- Calendar Grid -->
        <div class="calendar-grid" id="calendar-grid" style="flex:1;">
          <div class="calendar-day-header" data-i18n="day_mon">Pzt</div>
          <div class="calendar-day-header" data-i18n="day_tue">Sal</div>
          <div class="calendar-day-header" data-i18n="day_wed">Çar</div>
          <div class="calendar-day-header" data-i18n="day_thu">Per</div>
          <div class="calendar-day-header" data-i18n="day_fri">Cum</div>
          <div class="calendar-day-header" data-i18n="day_sat">Cmt</div>
          <div class="calendar-day-header" data-i18n="day_sun">Paz</div>
          <!-- Days populated via JS -->
        </div>
      </div>
    </div>

    <!-- Upcoming Events Sidebar Widget -->
    <div style="width:310px;flex-shrink:0;">
      <div class="card" style="margin-bottom:1.5rem;">
        <h3 style="font-size:0.95rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span data-i18n="cal_upcoming_title">Yaklaşan Etkinlikler</span>
        </h3>

        <div style="display:flex;flex-direction:column;gap:0.75rem;" id="upcoming-list">
          <?php if (empty($upcoming_events)): ?>
            <p style="font-size:0.825rem;color:var(--text-muted);text-align:center;padding:1.5rem 0;" data-i18n="cal_no_upcoming">Yaklaşan etkinlik yok.</p>
          <?php else: ?>
            <?php foreach ($upcoming_events as $ue): ?>
              <?php 
                $startDate = date('d M H:i', strtotime($ue->start_date));
              ?>
              <div style="padding:0.75rem;background:var(--bg-surface-elevated);border-left:3px solid <?= htmlspecialchars($ue->color ?: '#4f46e5') ?>;border-radius:var(--radius-sm);cursor:pointer;" onclick="editEventById(<?= $ue->id ?>)">
                <div style="font-size:0.875rem;font-weight:600;margin-bottom:2px;"><?= htmlspecialchars($ue->title) ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.4rem;">
                  <span><?= $startDate ?></span>
                  <?php if (!empty($ue->location)): ?>
                    <span>&bull; <?= htmlspecialchars($ue->location) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Add / Edit Event Modal -->
<div class="compose-overlay" id="event-modal">
  <div class="compose-window" style="width:520px;">
    <div class="compose-header">
      <div class="compose-title" id="event-modal-title">Yeni Etkinlik Oluştur</div>
      <button class="btn-icon" onclick="closeEventModal()">&times;</button>
    </div>
    <form id="event-form" onsubmit="saveEvent(event)" style="padding:1.5rem;">
      <input type="hidden" name="id" id="event-id" value="">

      <div class="form-group">
        <label class="form-label">Etkinlik Başlığı *</label>
        <input type="text" name="title" id="e-title" class="form-control" placeholder="Örn: Proje Değerlendirme Toplantısı" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Başlangıç Tarihi & Saati *</label>
          <input type="datetime-local" name="start_date" id="e-start" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Bitiş Tarihi & Saati *</label>
          <input type="datetime-local" name="end_date" id="e-end" class="form-control" required>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label class="form-label">Kategori</label>
          <select name="category" id="e-category" class="form-control">
            <option value="Toplantı">Toplantı</option>
            <option value="Görev">Görev / İş</option>
            <option value="Hatırlatıcı">Hatırlatıcı</option>
            <option value="Kişisel">Kişisel</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Renk Etiketi</label>
          <select name="color" id="e-color" class="form-control">
            <option value="#4f46e5" style="background:#4f46e5;color:#fff;">İndigo / Mavi</option>
            <option value="#059669" style="background:#059669;color:#fff;">Zümrüt Yeşili</option>
            <option value="#d97706" style="background:#d97706;color:#fff;">Kehribar / Turuncu</option>
            <option value="#dc2626" style="background:#dc2626;color:#fff;">Kırmızı</option>
            <option value="#7c3aed" style="background:#7c3aed;color:#fff;">Mor</option>
            <option value="#0891b2" style="background:#0891b2;color:#fff;">Cam Göbeği</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Konum / Toplantı Linki</label>
        <input type="text" name="location" id="e-location" class="form-control" placeholder="Ofis Toplantı Salonu A veya Google Meet / Zoom">
      </div>

      <div class="form-group">
        <label class="form-label">Açıklama & Notlar</label>
        <textarea name="description" id="e-desc" class="form-control" rows="3" placeholder="Gündem maddeleri ve notlar..."></textarea>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem;">
        <button type="button" class="btn-icon" id="btn-delete-event" style="display:none;color:var(--danger);" onclick="deleteCurrentEvent()" title="Etkinliği Sil">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
        <div style="display:flex;gap:0.75rem;margin-left:auto;">
          <button type="button" class="btn-icon" style="width:auto;padding:0 1rem;" onclick="closeEventModal()">İptal</button>
          <button type="submit" class="btn-send">Kaydet</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth();
let cachedEvents = [];

const monthNames = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

function renderCalendar() {
  document.getElementById('calendar-month-title').textContent = `${monthNames[currentMonth]} ${currentYear}`;
  
  const firstDayOfMonth = new Date(currentYear, currentMonth, 1);
  const lastDayOfMonth = new Date(currentYear, currentMonth + 1, 0);
  
  // Starting day (Monday=1, Sunday=0 -> in TR week starts with Mon)
  let startDayOfWeek = firstDayOfMonth.getDay();
  startDayOfWeek = (startDayOfWeek === 0) ? 6 : (startDayOfWeek - 1);
  
  const totalDays = lastDayOfMonth.getDate();
  const prevMonthLastDay = new Date(currentYear, currentMonth, 0).getDate();
  
  // Clear day cells (keep headers)
  const grid = document.getElementById('calendar-grid');
  const headers = grid.querySelectorAll('.calendar-day-header');
  grid.innerHTML = '';
  headers.forEach(h => grid.appendChild(h));

  const today = new Date();
  const isCurrentMonth = (today.getFullYear() === currentYear && today.getMonth() === currentMonth);

  // Previous month trailing days
  for (let i = startDayOfWeek - 1; i >= 0; i--) {
    const dayDiv = document.createElement('div');
    dayDiv.className = 'calendar-day other-month';
    dayDiv.innerHTML = `<span class="day-number">${prevMonthLastDay - i}</span>`;
    grid.appendChild(dayDiv);
  }

  // Current month days
  for (let d = 1; d <= totalDays; d++) {
    const dayDiv = document.createElement('div');
    dayDiv.className = 'calendar-day';
    if (isCurrentMonth && today.getDate() === d) {
      dayDiv.classList.add('today');
    }

    const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    dayDiv.innerHTML = `<span class="day-number">${d}</span><div class="day-events" id="events-${dateStr}"></div>`;
    dayDiv.addEventListener('click', (e) => {
      if (e.target.closest('.event-pill')) return;
      openEventModal(dateStr);
    });

    grid.appendChild(dayDiv);
  }

  fetchEvents();
}

function fetchEvents() {
  const start = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-01 00:00:00`;
  const lastDay = new Date(currentYear, currentMonth + 1, 0).getDate();
  const end = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${lastDay} 23:59:59`;

  fetch(`${BASE_URL}calendar/events_json?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`)
    .then(r => r.json())
    .then(events => {
      cachedEvents = events;
      events.forEach(ev => {
        const evDate = ev.start.substring(0, 10);
        const container = document.getElementById(`events-${evDate}`);
        if (container) {
          const pill = document.createElement('div');
          pill.className = 'event-pill';
          pill.style.backgroundColor = ev.backgroundColor;
          pill.textContent = ev.title;
          pill.title = `${ev.title} (${ev.extendedProps.location || ''})`;
          pill.addEventListener('click', (e) => {
            e.stopPropagation();
            editEvent(ev);
          });
          container.appendChild(pill);
        }
      });
    });
}

function prevMonth() {
  currentMonth--;
  if (currentMonth < 0) {
    currentMonth = 11;
    currentYear--;
  }
  renderCalendar();
}

function nextMonth() {
  currentMonth++;
  if (currentMonth > 11) {
    currentMonth = 0;
    currentYear++;
  }
  renderCalendar();
}

function goToToday() {
  const now = new Date();
  currentYear = now.getFullYear();
  currentMonth = now.getMonth();
  renderCalendar();
}

function openEventModal(selectedDate = null) {
  document.getElementById('event-form').reset();
  document.getElementById('event-id').value = '';
  document.getElementById('btn-delete-event').style.display = 'none';
  document.getElementById('event-modal-title').textContent = 'Yeni Etkinlik Oluştur';

  const dateBase = selectedDate || new Date().toISOString().substring(0, 10);
  document.getElementById('e-start').value = `${dateBase}T09:00`;
  document.getElementById('e-end').value = `${dateBase}T10:00`;

  document.getElementById('event-modal').classList.add('active');
}

function closeEventModal() {
  document.getElementById('event-modal').classList.remove('active');
}

function editEvent(ev) {
  document.getElementById('event-id').value = ev.id;
  document.getElementById('e-title').value = ev.title;
  document.getElementById('e-start').value = ev.extendedProps.startDateRaw;
  document.getElementById('e-end').value = ev.extendedProps.endDateRaw;
  document.getElementById('e-color').value = ev.backgroundColor;
  document.getElementById('e-category').value = ev.extendedProps.category || 'Toplantı';
  document.getElementById('e-location').value = ev.extendedProps.location || '';
  document.getElementById('e-desc').value = ev.extendedProps.description || '';
  document.getElementById('btn-delete-event').style.display = 'flex';
  document.getElementById('event-modal-title').textContent = 'Etkinliği Düzenle';

  document.getElementById('event-modal').classList.add('active');
}

function editEventById(id) {
  const ev = cachedEvents.find(e => e.id == id);
  if (ev) {
    editEvent(ev);
  } else {
    fetch(`${BASE_URL}calendar/events_json`)
      .then(r => r.json())
      .then(events => {
        cachedEvents = events;
        const found = events.find(e => e.id == id);
        if (found) editEvent(found);
      });
  }
}

function saveEvent(e) {
  e.preventDefault();
  const formData = new FormData(document.getElementById('event-form'));
  fetch(`${BASE_URL}calendar/save_event`, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast(data.message);
        closeEventModal();
        renderCalendar();
      } else {
        Webmail.showToast(data.message, 'error');
      }
    });
}

function deleteCurrentEvent() {
  const id = document.getElementById('event-id').value;
  if (!id || !confirm('Bu etkinliği silmek istediğinizden emin misiniz?')) return;

  fetch(`${BASE_URL}calendar/delete_event/${id}`)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        Webmail.showToast('Etkinlik silindi.');
        closeEventModal();
        renderCalendar();
      }
    });
}

document.addEventListener('DOMContentLoaded', () => {
  renderCalendar();
});
</script>
