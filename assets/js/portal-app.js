let currentFolder = 'INBOX';
let currentFilter = '';
let currentSearch = '';
var currentUid = null;
var currentEmailUid = null;
window.currentUid = null;
window.currentEmailUid = null;
try {
  Object.defineProperty(window, 'currentEmailUid', {
    get() { return currentUid; },
    set(v) { currentUid = v; },
    configurable: true
  });
} catch (e) {}
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth();
let cachedCalendarEvents = [];
let cachedMessages = {};
let contextMenuTargetUid = null;
let mailSearchTimeout = null;
let activeAdvSearch = null;
let isAllFoldersSearch = false;
let mailFetchAbortController = null;
let currentSort = localStorage.getItem('mail_sort_order') || 'date_desc';

function escapeHtml(text) {
  if (!text) return '';
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function parseApiResponseJson(res) {
  try {
    const text = await res.text();
    if (!text || text.trim() === '') {
      return { success: false, message: 'Sunucudan boş yanıt döndü.' };
    }
    try {
      return JSON.parse(text);
    } catch (err) {
      console.warn('API JSON parse error:', err, 'Response was:', text);
      const cleanMsg = (text.length < 200 && !text.includes('<html')) ? text.trim() : 'Sunucudan geçersiz veri formatı döndü.';
      return { success: false, message: cleanMsg };
    }
  } catch (netErr) {
    return { success: false, message: 'Ağ veya bağlantı hatası: ' + (netErr.message || netErr) };
  }
}

function downloadAttachmentFile(event, url, filename) {
  if (event) {
    if (typeof event.preventDefault === 'function') event.preventDefault();
    if (typeof event.stopPropagation === 'function') event.stopPropagation();
  }
  const a = document.createElement('a');
  a.style.display = 'none';
  a.href = url;
  a.setAttribute('download', filename);
  document.body.appendChild(a);
  a.click();
  setTimeout(() => a.remove(), 400);
}

function showToast(msg, type = 'success') {
  if (typeof Swal !== 'undefined') {
    const iconMap = {
      success: 'success',
      error: 'error',
      danger: 'error',
      warning: 'warning',
      info: 'info'
    };
    const Toast = Swal.mixin({
      toast: true,
      position: 'bottom-end',
      showConfirmButton: false,
      timer: 3500,
      timerProgressBar: true,
      customClass: {
        popup: 'swal2-toast-popup'
      },
      didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
      }
    });
    Toast.fire({
      icon: iconMap[type] || 'info',
      title: msg
    });
    return;
  }

  let c = document.getElementById('toast-container');
  if (!c) {
    c = document.createElement('div');
    c.id = 'toast-container';
    c.className = 'toast-container';
    document.body.appendChild(c);
  }
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.innerHTML = `<span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    setTimeout(() => t.remove(), 300);
  }, 3500);
}

function showAlert(title, message, icon = 'info') {
  if (typeof Swal !== 'undefined') {
    return Swal.fire({
      title: title,
      html: message,
      icon: icon,
      confirmButtonText: 'Tamam'
    });
  } else {
    alert(title + (message ? "\n\n" + message : ''));
  }
}

// Override window.alert globally across the webmail client
if (typeof window !== 'undefined') {
  window.alert = function(msg) {
    showAlert('Bilgilendirme', msg, 'info');
  };
}

// Mobile Responsive Navigation Helpers
function toggleMobileSidebar(force = null) {
  const sidebar = document.getElementById('app-sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  if (!sidebar) return;
  const isOpen = (force !== null) ? force : !sidebar.classList.contains('open');
  if (isOpen) {
    sidebar.classList.add('open');
    if (backdrop) backdrop.classList.add('active');
  } else {
    sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('active');
  }
}

function backToMailList() {
  document.getElementById('view-mail')?.classList.remove('mobile-reading-active');
  document.querySelectorAll('.mail-item').forEach(el => el.classList.remove('selected'));
}

function backToChatSidebar() {
  document.getElementById('view-chat')?.classList.remove('mobile-conversation-active');
  document.getElementById('chat-workspace')?.classList.remove('mobile-conversation-active');
}

// Safely decode IMAP modified UTF-7 string to UTF-8
function decodeImapUtf7(str) {
  if (!str || typeof str !== 'string') return str || '';
  if (!str.includes('&')) return str;
  return str.replace(/&([A-Za-z0-9+,]+)?-/g, function(match, b64) {
    if (!b64) return '&';
    try {
      let standardB64 = b64.replace(/,/g, '/');
      while (standardB64.length % 4 !== 0) standardB64 += '=';
      let bin = atob(standardB64);
      let res = '';
      for (let i = 0; i < bin.length; i += 2) {
        if (i + 1 < bin.length) {
          res += String.fromCharCode((bin.charCodeAt(i) << 8) | bin.charCodeAt(i + 1));
        }
      }
      return res || match;
    } catch (e) {
      return match
        .replace(/&AR8-/g, 'ğ').replace(/&AR4-/g, 'Ğ')
        .replace(/&AV8-/g, 'ş').replace(/&AV4-/g, 'Ş')
        .replace(/&AMc-/g, 'ç').replace(/&AMI-/g, 'Ç')
        .replace(/&ANY-/g, 'ö').replace(/&ANk-/g, 'Ö')
        .replace(/&APw-/g, 'ü').replace(/&ANw-/g, 'Ü')
        .replace(/&ATE-/g, 'ı').replace(/&ATA-/g, 'İ')
        .replace(/&-/g, '&');
    }
  });
}

function swalConfirm(title, text, confirmText = 'Evet', icon = 'warning') {
  if (typeof Swal !== 'undefined') {
    return Swal.fire({
      title: title,
      text: text,
      icon: icon,
      showCancelButton: true,
      showDenyButton: false,
      confirmButtonText: confirmText,
      cancelButtonText: 'Vazgeç',
      reverseButtons: true
    });
  }
  return Promise.resolve({ isConfirmed: confirm(`${title}\n${text}`) });
}

function showUndoToast(msg, undoCallback, durationMs = 5000) {
  let c = document.getElementById('toast-container');
  if (!c) {
    c = document.createElement('div');
    c.id = 'toast-container';
    c.className = 'toast-container';
    document.body.appendChild(c);
  }

  const existingUndo = document.getElementById('toast-undo-active');
  if (existingUndo) existingUndo.remove();

  const t = document.createElement('div');
  t.id = 'toast-undo-active';
  t.className = 'toast';
  t.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:1.25rem;min-width:320px;border-left:4px solid var(--danger);position:relative;overflow:hidden;background:var(--bg-surface-elevated);box-shadow:var(--shadow-xl);padding:0.9rem 1.15rem;';

  const progress = document.createElement('div');
  progress.style.cssText = `position:absolute;bottom:0;left:0;height:3px;background:var(--danger);width:100%;transition:width ${durationMs}ms linear;`;

  t.innerHTML = `
    <div style="display:flex;align-items:center;gap:0.6rem;font-size:0.875rem;font-weight:500;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      <span>${msg}</span>
    </div>
    <button type="button" id="btn-toast-undo" style="background:var(--primary);color:#fff;border:none;padding:5px 12px;border-radius:var(--radius-sm);font-weight:700;font-size:0.8rem;cursor:pointer;display:flex;align-items:center;gap:4px;">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
      <span>Geri Al (5s)</span>
    </button>
  `;
  t.appendChild(progress);
  c.appendChild(t);

  setTimeout(() => { progress.style.width = '0%'; }, 20);

  let remainingSec = Math.round(durationMs / 1000);
  const undoBtn = t.querySelector('#btn-toast-undo');
  const secInterval = setInterval(() => {
    remainingSec--;
    if (remainingSec > 0 && undoBtn) {
      undoBtn.querySelector('span').textContent = `Geri Al (${remainingSec}s)`;
    } else {
      clearInterval(secInterval);
    }
  }, 1000);

  undoBtn.onclick = (e) => {
    e.stopPropagation();
    clearInterval(secInterval);
    t.style.opacity = '0';
    setTimeout(() => t.remove(), 200);
    undoCallback();
  };

  setTimeout(() => {
    clearInterval(secInterval);
    if (t.parentElement) {
      t.style.opacity = '0';
      setTimeout(() => t.remove(), 300);
    }
  }, durationMs);
}

// THEME & SIGNATURE GLOBAL
window.USER_SIGNATURE = window.USER_SIGNATURE || '';
window.CURRENT_USER_EMAIL = window.CURRENT_USER_EMAIL || '';

function getSavedThemeChoice() {
  return localStorage.getItem('hestia_theme') || window.DEFAULT_THEME || 'dark';
}

function applyTheme(choice, saveToServer = true) {
  let effectiveTheme = choice;
  if (choice === 'system') {
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    effectiveTheme = prefersDark ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', effectiveTheme);
  localStorage.setItem('hestia_theme', choice);

  updateThemeUI(effectiveTheme, choice);

  if (saveToServer) {
    const fd = new FormData();
    fd.append('action', 'save_theme');
    fd.append('theme', choice);
    fetch('api.php', { method: 'POST', body: fd }).catch(() => {});
  }
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme') || 'dark';
  const isLight = current.startsWith('light') || current === 'wallpaper-teddy';
  const next = isLight ? 'dark' : 'light';
  applyTheme(next, true);
  showToast(next === 'light' ? (typeof t === 'function' ? t('theme_toast_light_switched', 'Aydınlık tema aktif edildi') : 'Aydınlık tema aktif edildi') : (typeof t === 'function' ? t('theme_toast_dark_switched', 'Karanlık tema aktif edildi') : 'Karanlık tema aktif edildi'), 'info');
}

function selectThemeChoice(choice) {
  if (choice === 'wallpaper-custom') {
    const hasCustom = localStorage.getItem('hestia_custom_wallpaper') || window.DEFAULT_CUSTOM_WALLPAPER || '';
    if (!hasCustom) {
      showToast(typeof t === 'function' ? t('theme_custom_prompt_upload', 'Lütfen önce özel bir arkaplan görseli seçin.') : 'Lütfen önce özel bir arkaplan görseli seçin.', 'info');
      const inp = document.getElementById('custom-wallpaper-input');
      if (inp) inp.click();
      return;
    }
  }

  applyTheme(choice, true);

  const _t = (k, f) => (typeof t === 'function' ? t(k, f) : f);
  const titles = {
    'light': _t('theme_toast_light', 'Açık İndigo tema aktif edildi'),
    'light-sky': _t('theme_toast_light_sky', 'Açık Masmavi tema aktif edildi'),
    'light-emerald': _t('theme_toast_light_emerald', 'Açık Zümrüt & Nane tema aktif edildi'),
    'light-lavender': _t('theme_toast_light_lavender', 'Açık Lavanta tema aktif edildi'),
    'light-rose': _t('theme_toast_light_rose', 'Açık Gül & Pudra tema aktif edildi'),
    'light-amber': _t('theme_toast_light_amber', 'Açık Sıcak Kehribar tema aktif edildi'),
    'light-slate': _t('theme_toast_light_slate', 'Açık Minimal Gri tema aktif edildi'),
    'dark': _t('theme_toast_dark', 'Koyu Lacivert tema aktif edildi'),
    'ocean': _t('theme_toast_ocean', 'Okyanus Mavisi tema aktif edildi'),
    'emerald': _t('theme_toast_emerald', 'Koyu Zümrüt tema aktif edildi'),
    'amethyst': _t('theme_toast_amethyst', 'Ametist Moru tema aktif edildi'),
    'carbon': _t('theme_toast_carbon', 'Karbon Grafit tema aktif edildi'),
    'wallpaper-teddy': _t('theme_toast_wallpaper_teddy', '🧸 Sevimli Ayıcık teması aktif edildi'),
    'wallpaper-sea': _t('theme_toast_wallpaper_sea', '🌊 Berrak Deniz teması aktif edildi'),
    'wallpaper-nature': _t('theme_toast_wallpaper_nature', '🌲 Huzurlu Doğa teması aktif edildi'),
    'wallpaper-hearts': _t('theme_toast_wallpaper_hearts', '💖 Romantik Kalpler teması aktif edildi'),
    'wallpaper-custom': _t('theme_toast_wallpaper_custom', '🖼️ Özel Arkaplan teması aktif edildi'),
    'system': _t('theme_toast_system', 'Sistem teması aktif edildi')
  };
  showToast(titles[choice] || _t('theme_toast_updated', 'Tema güncellendi'));
}

function updateLiveOpacity(val) {
  const o = (parseInt(val, 10) / 100).toFixed(2);
  document.documentElement.style.setProperty('--panel-opacity', o);
  const badge = document.getElementById('theme-opacity-val');
  if (badge) badge.textContent = val + '%';
  const slider = document.getElementById('theme-opacity-slider');
  if (slider && slider.value !== String(val)) slider.value = val;
  localStorage.setItem('hestia_opacity', val);
}

function applyOpacityPreset(val) {
  updateLiveOpacity(val);
  saveOpacityPreference(val);
}

let _opacitySaveTimeout = null;
function saveOpacityPreference(val) {
  clearTimeout(_opacitySaveTimeout);
  _opacitySaveTimeout = setTimeout(() => {
    const fd = new FormData();
    fd.append('action', 'save_panel_opacity');
    fd.append('opacity', val);
    fetch('api.php', { method: 'POST', body: fd }).catch(() => {});
  }, 250);
}

function handleWallpaperUpload(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const _t = (k, f) => (typeof t === 'function' ? t(k, f) : f);

  if (file.size > 12 * 1024 * 1024) {
    showToast(_t('theme_upload_size_err', 'Görsel boyutu 12MB\'den büyük olamaz.'), 'error');
    input.value = '';
    return;
  }

  showToast(_t('theme_uploading_toast', 'Arkaplan görseli yükleniyor...'), 'info');

  const fd = new FormData();
  fd.append('action', 'upload_custom_wallpaper');
  fd.append('wallpaper', file);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      input.value = '';
      if (res.success && res.url) {
        localStorage.setItem('hestia_custom_wallpaper', res.url);
        document.documentElement.style.setProperty('--custom-wallpaper-url', `url("${res.url}")`);

        const thumb = document.getElementById('custom-wallpaper-thumb');
        if (thumb) {
          thumb.style.backgroundImage = `url("${res.url}")`;
          thumb.innerHTML = '';
        }
        const st = document.getElementById('custom-wallpaper-status-text');
        if (st) st.textContent = _t('theme_custom_active', 'Özel görsel aktif');
        const rmBtn = document.getElementById('btn-remove-wallpaper');
        if (rmBtn) rmBtn.style.display = 'inline-flex';

        const previewBox = document.getElementById('theme-preview-box-custom');
        if (previewBox) previewBox.style.backgroundImage = `url("${res.url}")`;

        applyTheme('wallpaper-custom', true);
        showToast(_t('theme_upload_success', 'Özel arkaplan başarıyla yüklendi ve tam ekran uygulandı!'), 'success');
      } else {
        showToast(res.error || _t('theme_upload_error', 'Görsel yüklenirken bir hata oluştu.'), 'error');
      }
    })
    .catch(err => {
      input.value = '';
      showToast(_t('theme_upload_error', 'Görsel yüklenirken bir hata oluştu: ') + err.message, 'error');
    });
}

function removeCustomWallpaper() {
  const _t = (k, f) => (typeof t === 'function' ? t(k, f) : f);
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: _t('theme_remove_confirm_title', 'Özel Arkaplanı Kaldır?'),
      text: _t('theme_remove_confirm_text', 'Yüklediğiniz özel görsel silinecek ve varsayılan temaya dönülecek.'),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: _t('btn_confirm', 'Evet, Kaldır'),
      cancelButtonText: _t('btn_cancel', 'İptal')
    }).then(r => {
      if (r.isConfirmed) executeRemoveWallpaper();
    });
  } else {
    if (confirm('Özel arkaplan görseli kaldırılsın mı?')) {
      executeRemoveWallpaper();
    }
  }
}

function executeRemoveWallpaper() {
  const _t = (k, f) => (typeof t === 'function' ? t(k, f) : f);
  const fd = new FormData();
  fd.append('action', 'remove_custom_wallpaper');
  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        localStorage.removeItem('hestia_custom_wallpaper');
        document.documentElement.style.removeProperty('--custom-wallpaper-url');
        
        const thumb = document.getElementById('custom-wallpaper-thumb');
        if (thumb) {
          thumb.style.backgroundImage = 'none';
          thumb.innerHTML = '🖼️';
        }
        const st = document.getElementById('custom-wallpaper-status-text');
        if (st) st.textContent = _t('theme_no_custom_bg', 'Özel görsel henüz yüklenmedi');
        const rmBtn = document.getElementById('btn-remove-wallpaper');
        if (rmBtn) rmBtn.style.display = 'none';

        const previewBox = document.getElementById('theme-preview-box-custom');
        if (previewBox) previewBox.style.backgroundImage = 'none';

        applyTheme('dark', true);
        showToast(_t('theme_remove_success', 'Özel arkaplan kaldırıldı.'), 'info');
      }
    })
    .catch(() => {});
}

function updateThemeUI(effectiveTheme, choice) {
  document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
    if (effectiveTheme === 'dark') {
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
          <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>`;
      btn.setAttribute('title', 'Aydınlık Moda Geç');
    } else {
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>`;
      btn.setAttribute('title', 'Karanlık Moda Geç');
    }
  });

  document.querySelectorAll('.theme-card-option').forEach(card => {
    if (card.dataset.themeChoice === choice) {
      card.classList.add('active');
    } else {
      card.classList.remove('active');
    }
  });
}

function initTheme() {
  const choice = getSavedThemeChoice();
  applyTheme(choice, false);
}

try {
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getSavedThemeChoice() === 'system') {
        applyTheme('system', false);
      }
    });
  }
} catch(e) {}

function toggleAdvancedLogin() {
  const box = document.getElementById('advanced-login-box');
  const arrow = document.getElementById('adv-arrow');
  if (box.style.display === 'none') {
    box.style.display = 'block';
    arrow.innerHTML = '&#9662;';
  } else {
    box.style.display = 'none';
    arrow.innerHTML = '&#9656;';
  }
}

// TOGGLE PASSWORD VISIBILITY ON LOGIN
function togglePasswordVisibility() {
  const inp = document.getElementById('login-password');
  const eye = document.getElementById('pass-toggle-eye');
  if (!inp) return;
  if (inp.type === 'password') {
    inp.type = 'text';
    if (eye) {
      eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    }
  } else {
    inp.type = 'password';
    if (eye) {
      eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
  }
}

// SIMPLE USERNAME & PASSWORD LOGIN HANDLER
function handleLogin(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-login-submit');
  btn.disabled = true;
  btn.innerHTML = 'Giriş Yapılıyor...';

  const formData = new FormData();
  formData.append('action', 'login');
  formData.append('username', document.getElementById('login-username').value.trim());
  formData.append('password', document.getElementById('login-password').value);

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = 'Giriş Yap';
      if (data.success) {
        showToast('Giriş başarılı! Yükleniyor...');
        setTimeout(() => window.location.reload(), 400);
      } else {
        const alertBox = document.getElementById('login-alert-box');
        if (alertBox) {
          alertBox.textContent = data.message;
          alertBox.style.display = 'block';
        }
        showToast(data.message, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = 'Giriş Yap';
      showToast('Sunucu bağlantı hatası oluştu.', 'error');
    });
}

function handleLogout() {
  swalConfirm('Oturumu Kapat', 'Oturumu kapatmak istediğinizden emin misiniz?', 'Çıkış Yap', 'question').then(res => {
    if (res.isConfirmed) {
      fetch('api.php?action=logout')
        .then(() => window.location.reload());
    }
  });
}

function saveSimpleAccount(e) {
  e.preventDefault();
  const formData = new FormData();
  formData.append('action', 'save_account');
  formData.append('email', document.getElementById('acc-email').value.trim());
  formData.append('password', document.getElementById('acc-pass').value);
  formData.append('is_default', '1');

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        setTimeout(() => window.location.reload(), 600);
      } else {
        showToast(data.message, 'error');
      }
    });
}

function switchMainTab(tab) {
  if (typeof toggleMobileSidebar === 'function') toggleMobileSidebar(false);
  window.currentActiveTab = tab;
  if (tab === 'chat') {
    if (typeof clearChatTitleNotification === 'function') clearChatTitleNotification();
    if (typeof triggerChatPollNow === 'function') triggerChatPollNow();
  } else {
    if (typeof scheduleChatHeartbeat === 'function') scheduleChatHeartbeat();
  }

  document.querySelectorAll('.top-tab').forEach(t => t.classList.remove('active'));
  if (tab !== 'mail') {
    document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
  } else {
    syncActiveFolderUI(currentFolder);
  }

  document.getElementById('view-mail').style.display = (tab === 'mail') ? 'flex' : 'none';
  document.getElementById('view-contacts').style.display = (tab === 'contacts') ? 'block' : 'none';
  document.getElementById('view-calendar').style.display = (tab === 'calendar') ? 'block' : 'none';
  document.getElementById('view-tasks').style.display = (tab === 'tasks') ? 'block' : 'none';
  document.getElementById('view-notes').style.display = (tab === 'notes') ? 'block' : 'none';
  document.getElementById('view-drive').style.display = (tab === 'drive') ? 'block' : 'none';
  document.getElementById('view-chat').style.display = (tab === 'chat') ? 'block' : 'none';
  document.getElementById('view-settings').style.display = (tab === 'settings') ? 'block' : 'none';
  const adminView = document.getElementById('view-admin');
  if (adminView) adminView.style.display = (tab === 'admin') ? 'block' : 'none';

  document.getElementById('tab-top-' + tab)?.classList.add('active');
  document.getElementById('nav-btn-' + tab)?.classList.add('active');

  if (tab === 'contacts') loadContacts();
  if (tab === 'calendar') renderCalendar();
  if (tab === 'tasks') loadTasks();
  if (tab === 'notes') loadNotes();
  if (tab === 'drive') loadDrive();
  if (tab === 'chat') openChatTab();
  if (tab === 'admin') loadAdminDashboard();

  // Hide floating chat launcher when chat view is active, show otherwise
  const floatBtn = document.getElementById('floating-chat-btn');
  if (floatBtn) floatBtn.style.display = (tab === 'chat') ? 'none' : 'flex';
}

function syncActiveFolderUI(targetFolder) {
  const fld = targetFolder || currentFolder || 'INBOX';
  let matchedEl = null;

  document.querySelectorAll('.sidebar .nav-links a.nav-link').forEach(a => {
    a.classList.remove('active');
    const onclickAttr = a.getAttribute('onclick') || '';
    const idAttr = a.id || '';
    if (
      idAttr === 'folder-link-' + fld ||
      onclickAttr.includes(`'${fld}'`) ||
      onclickAttr.includes(`"${fld}"`)
    ) {
      matchedEl = a;
    }
  });

  // Fallback for special folder names (e.g. INBOX, Spam vs Junk)
  if (!matchedEl) {
    if (fld.toLowerCase().includes('inbox') || fld === 'INBOX') {
      matchedEl = document.getElementById('folder-link-INBOX');
    } else if (fld.toLowerCase().includes('sent') || fld.toLowerCase().includes('gönderilen')) {
      matchedEl = document.getElementById('folder-link-Sent');
    } else if (fld.toLowerCase().includes('draft') || fld.toLowerCase().includes('taslak')) {
      matchedEl = document.getElementById('folder-link-Drafts');
    } else if (fld.toLowerCase().includes('trash') || fld.toLowerCase().includes('çöp')) {
      matchedEl = document.getElementById('folder-link-Trash');
    } else if (fld.toLowerCase().includes('spam') || fld.toLowerCase().includes('junk') || fld.toLowerCase().includes('istenmeyen')) {
      matchedEl = document.getElementById('folder-link-Spam');
    }
  }

  if (matchedEl) {
    matchedEl.classList.add('active');
  }

  // Update folder indicator and placeholders
  const folderName = matchedEl ? (matchedEl.querySelector('.nav-link-left span')?.textContent?.trim() || fld) : fld;
  const titleTextEl = document.getElementById('current-folder-title-text');
  if (titleTextEl) {
    titleTextEl.textContent = folderName;
    titleTextEl.title = folderName;
  }
  const advFldName = document.getElementById('adv-fld-name-text');
  if (advFldName) advFldName.textContent = folderName;

  const searchInput = document.getElementById('mail-search-input');
  if (searchInput && !searchInput.value.trim()) {
    searchInput.placeholder = `"${folderName}" klasöründe ara...`;
  }
}

function selectFolder(folder, el) {
  if (typeof toggleMobileSidebar === 'function') toggleMobileSidebar(false);
  document.getElementById('view-mail')?.classList.remove('mobile-reading-active');
  currentFolder = folder;
  activeAdvSearch = null;
  currentSearch = '';
  isAllFoldersSearch = false;

  if (typeof isWideReaderActive !== 'undefined' && isWideReaderActive) {
    toggleWideReadingPane();
  }

  switchMainTab('mail');
  syncActiveFolderUI(folder);

  const searchInput = document.getElementById('mail-search-input');
  if (searchInput) {
    searchInput.value = '';
  }
  const clearBtn = document.getElementById('mail-search-clear');
  if (clearBtn) clearBtn.style.display = 'none';
  const statusBar = document.getElementById('mail-search-status-bar');
  if (statusBar) statusBar.style.display = 'none';

  const advFolderSel = document.getElementById('adv-search-folder');
  if (advFolderSel) advFolderSel.value = 'CURRENT';

  toggleAdvancedSearchPanel(false);
  clearMailSelection();

  // Update trash and spam auto-clean banner visibility
  const isTrash = (currentFolder.toLowerCase().includes('trash') || currentFolder.toLowerCase().includes('çöp'));
  const isSpam = (currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen'));

  const trashBar = document.getElementById('mail-trash-notice-bar');
  if (trashBar) {
    trashBar.style.display = isTrash ? 'flex' : 'none';
  }
  const spamBar = document.getElementById('mail-spam-notice-bar');
  if (spamBar) {
    spamBar.style.display = isSpam ? 'flex' : 'none';
  }

  loadMessages(true);
}

function setMailFilter(filter, btn) {
  currentFilter = filter;
  document.querySelectorAll('.filter-chips .chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  clearMailSelection();
  loadMessages(true);
}

// ==========================================
// SEARCH & ADVANCED SEARCH ENGINE
// ==========================================

function toggleAdvancedSearchPanel(forceState) {
  const panel = document.getElementById('adv-search-panel');
  const toggleBtn = document.getElementById('btn-toggle-adv-search');
  if (!panel) return;
  const shouldOpen = (typeof forceState === 'boolean') ? forceState : !panel.classList.contains('active');
  if (shouldOpen) {
    panel.classList.add('active');
    if (toggleBtn) toggleBtn.classList.add('active');
    const currentLink = document.querySelector('.sidebar .nav-links a.active');
    const folderName = currentLink ? (currentLink.querySelector('span')?.textContent?.trim() || currentFolder) : currentFolder;
    const advFldName = document.getElementById('adv-fld-name-text');
    if (advFldName) advFldName.textContent = folderName;
    document.getElementById('adv-search-from')?.focus();
  } else {
    panel.classList.remove('active');
    if (toggleBtn) toggleBtn.classList.remove('active');
  }
}

// Close advanced search if clicked outside
document.addEventListener('click', (e) => {
  const panel = document.getElementById('adv-search-panel');
  const toggleBtn = document.getElementById('btn-toggle-adv-search');
  if (panel && panel.classList.contains('active')) {
    if (!panel.contains(e.target) && !toggleBtn?.contains(e.target)) {
      panel.classList.remove('active');
      if (toggleBtn) toggleBtn.classList.remove('active');
    }
  }
});

function resetAdvancedSearchForm() {
  const ids = ['adv-search-from', 'adv-search-to', 'adv-search-subject', 'adv-search-body', 'adv-search-date-from', 'adv-search-date-to'];
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const fld = document.getElementById('adv-search-folder');
  if (fld) fld.value = 'CURRENT';
  const status = document.getElementById('adv-search-status');
  if (status) status.value = '';
  const att = document.getElementById('adv-search-has-attachment');
  if (att) att.checked = false;
}

function submitAdvancedSearch() {
  const fldSelect = document.getElementById('adv-search-folder')?.value || 'CURRENT';
  const fromVal = document.getElementById('adv-search-from')?.value.trim() || '';
  const toVal = document.getElementById('adv-search-to')?.value.trim() || '';
  const subjectVal = document.getElementById('adv-search-subject')?.value.trim() || '';
  const bodyVal = document.getElementById('adv-search-body')?.value.trim() || '';
  const dateFromVal = document.getElementById('adv-search-date-from')?.value || '';
  const dateToVal = document.getElementById('adv-search-date-to')?.value || '';
  const statusVal = document.getElementById('adv-search-status')?.value || '';
  const hasAttVal = document.getElementById('adv-search-has-attachment')?.checked ? 1 : 0;

  if (!fromVal && !toVal && !subjectVal && !bodyVal && !dateFromVal && !dateToVal && !statusVal && !hasAttVal && fldSelect !== 'ALL') {
    showToast('Lütfen en az bir arama kriteri girin.', 'info');
    return;
  }

  activeAdvSearch = {
    folder: (fldSelect === 'CURRENT') ? currentFolder : fldSelect,
    from: fromVal,
    to: toVal,
    subject: subjectVal,
    body: bodyVal,
    date_from: dateFromVal,
    date_to: dateToVal,
    status: statusVal,
    has_attachment: hasAttVal
  };

  isAllFoldersSearch = (fldSelect === 'ALL');
  currentSearch = '';
  const searchInput = document.getElementById('mail-search-input');
  if (searchInput) searchInput.value = '';

  toggleAdvancedSearchPanel(false);

  // Update status bar
  const statusBar = document.getElementById('mail-search-status-bar');
  const statusText = document.getElementById('mail-search-status-text');
  if (statusBar && statusText) {
    const targetName = isAllFoldersSearch ? 'Tüm Klasörler' : (activeAdvSearch.folder);
    const details = [];
    if (fromVal) details.push(`Kimden: ${fromVal}`);
    if (toVal) details.push(`Kime: ${toVal}`);
    if (subjectVal) details.push(`Konu: ${subjectVal}`);
    if (bodyVal) details.push(`İçerik: ${bodyVal}`);
    if (dateFromVal || dateToVal) details.push(`Tarih: ${dateFromVal || '...'} / ${dateToVal || '...'}`);
    if (hasAttVal) details.push('Ekli');
    if (statusVal) details.push(statusVal === 'unread' ? 'Okunmamış' : 'Yıldızlı');
    
    statusText.textContent = `[${targetName}] ${details.join(', ') || 'Gelişmiş Arama'}`;
    statusBar.style.display = 'inline-flex';
  }

  const clearBtn = document.getElementById('mail-search-clear');
  if (clearBtn) clearBtn.style.display = 'block';

  clearMailSelection();
  loadMessages(true);
}

function handleMailSearchInput(val) {
  clearTimeout(mailSearchTimeout);
  const clearBtn = document.getElementById('mail-search-clear');
  if (clearBtn) clearBtn.style.display = val.trim() ? 'block' : (activeAdvSearch ? 'block' : 'none');

  mailSearchTimeout = setTimeout(() => {
    executeMailSearch(val);
  }, 400);
}

function executeMailSearch(val) {
  activeAdvSearch = null;
  currentSearch = val.trim();
  isAllFoldersSearch = false;

  const statusBar = document.getElementById('mail-search-status-bar');
  const statusText = document.getElementById('mail-search-status-text');
  const clearBtn = document.getElementById('mail-search-clear');

  if (currentSearch) {
    if (statusBar && statusText) {
      statusText.textContent = `"${currentSearch}" aranıyor...`;
      statusBar.style.display = 'inline-flex';
    }
    if (clearBtn) clearBtn.style.display = 'block';
  } else {
    if (statusBar) statusBar.style.display = 'none';
    if (clearBtn) clearBtn.style.display = 'none';
  }

  clearMailSelection();
  loadMessages(true);
}

function clearMailSearch() {
  const input = document.getElementById('mail-search-input');
  if (input) input.value = '';
  const clearBtn = document.getElementById('mail-search-clear');
  if (clearBtn) clearBtn.style.display = 'none';
  const statusBar = document.getElementById('mail-search-status-bar');
  if (statusBar) statusBar.style.display = 'none';
  
  activeAdvSearch = null;
  currentSearch = '';
  isAllFoldersSearch = false;
  resetAdvancedSearchForm();
  
  clearMailSelection();
  loadMessages(true);
}

function onSearchMail(e) {
  if (e.key === 'Enter') {
    executeMailSearch(e.target.value);
  }
}

function refreshCurrentView() {
  const activeTab = document.querySelector('.top-tab.active')?.id.replace('tab-top-', '') || 'mail';
  if (activeTab === 'mail') {
    clearMailSelection();
    loadMessages();
    if (typeof updateFolderCounts === 'function') updateFolderCounts();
  }
  else if (activeTab === 'contacts') loadContacts();
  else if (activeTab === 'calendar') renderCalendar();
  else if (activeTab === 'tasks') loadTasks();
  else if (activeTab === 'notes') loadNotes();
  else if (activeTab === 'drive') loadDrive();
}

// ==========================================
// MULTI-SELECT & BULK ACTIONS ENGINE
// ==========================================
let selectedMailUids = new Set();
let isAllFolderSelected = false;
let currentFolderTotalCount = 0;

function onMailItemCheckboxChange(uid, isChecked) {
  if (isChecked) {
    selectedMailUids.add(uid);
    document.getElementById(`mail-item-${uid}`)?.classList.add('selected');
  } else {
    isAllFolderSelected = false;
    selectedMailUids.delete(uid);
    document.getElementById(`mail-item-${uid}`)?.classList.remove('selected');
  }
  updateBulkToolbarUI();
}

function toggleSelectAllMails(isChecked) {
  if (isChecked) {
    // "Tümünü Seç" selects all mails in the current folder
    selectAllFolderMails();
  } else {
    clearMailSelection();
  }
}

function selectVisibleMails() {
  isAllFolderSelected = false;
  selectedMailUids.clear();
  const checkboxes = document.querySelectorAll('.mail-select-chk');
  checkboxes.forEach(chk => {
    const uid = parseInt(chk.getAttribute('data-uid'));
    if (!uid) return;
    chk.checked = true;
    selectedMailUids.add(uid);
    document.getElementById(`mail-item-${uid}`)?.classList.add('selected');
  });
  updateBulkToolbarUI();
}

function selectAllFolderMails() {
  const checkboxes = document.querySelectorAll('.mail-select-chk');
  checkboxes.forEach(chk => {
    const uid = parseInt(chk.getAttribute('data-uid'));
    if (!uid) return;
    chk.checked = true;
    selectedMailUids.add(uid);
    document.getElementById(`mail-item-${uid}`)?.classList.add('selected');
  });
  isAllFolderSelected = (checkboxes.length > 0 || currentFolderTotalCount > 0);
  updateBulkToolbarUI();
}

function toggleMailSelectDropdown(event) {
  if (event) event.stopPropagation();
  closeBulkMoveDropdown();
  closeReaderMoveDropdown();
  const menu = document.getElementById('mail-select-dropdown');
  if (!menu) return;
  const isShown = (menu.style.display === 'block');
  if (isShown) {
    closeMailSelectDropdown();
  } else {
    const visCount = document.querySelectorAll('.mail-select-chk').length;
    const totalCount = Math.max(currentFolderTotalCount, visCount);
    const visHint = document.getElementById('select-visible-count-hint');
    const foldHint = document.getElementById('select-folder-count-hint');
    if (visHint) visHint.textContent = visCount > 0 ? `(${visCount})` : '';
    if (foldHint) foldHint.textContent = totalCount > 0 ? `(${totalCount.toLocaleString('tr-TR')})` : '';
    menu.style.display = 'block';
  }
}

function closeMailSelectDropdown() {
  const menu = document.getElementById('mail-select-dropdown');
  if (menu) menu.style.display = 'none';
}

function clearMailSelection() {
  isAllFolderSelected = false;
  selectedMailUids.clear();
  document.querySelectorAll('.mail-select-chk').forEach(chk => chk.checked = false);
  document.querySelectorAll('.mail-item.selected').forEach(el => el.classList.remove('selected'));
  const allChk = document.getElementById('mail-select-all-chk');
  if (allChk) allChk.checked = false;
  closeBulkMoveDropdown();
  updateBulkToolbarUI();
}

function getEffectiveSelectedCount() {
  if (isAllFolderSelected) {
    return Math.max(currentFolderTotalCount, selectedMailUids.size);
  }
  return selectedMailUids.size;
}

function updateBulkToolbarUI() {
  const visCount = document.querySelectorAll('.mail-select-chk').length;
  const count = getEffectiveSelectedCount();
  const countText = document.getElementById('mail-select-count-text');
  const bulkButtons = document.getElementById('mail-bulk-buttons');
  const moveBtn = document.getElementById('bulk-move-btn');
  const deleteBtn = document.getElementById('bulk-delete-btn');
  const readBtn = document.getElementById('bulk-read-btn');
  const unreadBtn = document.getElementById('bulk-unread-btn');
  const spamBtn = document.getElementById('bulk-spam-btn');
  const closeBtn = document.getElementById('bulk-close-btn');
  const allChk = document.getElementById('mail-select-all-chk');
  const quickBtn = document.getElementById('mail-select-visible-quick-btn');
  const chkVis = document.getElementById('select-chk-visible');
  const chkAll = document.getElementById('select-chk-all-folder');

  const isTrash = (currentFolder.toLowerCase().includes('trash') || currentFolder.toLowerCase().includes('çöp'));
  const isSpamFolder = (currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen'));

  if (count > 0) {
    if (allChk) allChk.checked = true;
    const formattedCount = count.toLocaleString('tr-TR');
    if (isAllFolderSelected) {
      if (countText) countText.textContent = `Tüm Klasör (${formattedCount})`;
      if (chkAll) chkAll.textContent = '✓';
      if (chkVis) chkVis.textContent = '';
      if (quickBtn) {
        if (currentFolderTotalCount > visCount && visCount > 0) {
          quickBtn.textContent = `Sadece Görünenleri Seç (${visCount})`;
          quickBtn.onclick = () => selectVisibleMails();
          quickBtn.style.display = 'inline-flex';
        } else {
          quickBtn.style.display = 'none';
        }
      }
    } else {
      if (countText) countText.textContent = `${formattedCount} seçildi`;
      if (chkVis) chkVis.textContent = (selectedMailUids.size === visCount && visCount > 0) ? '✓' : '';
      if (chkAll) chkAll.textContent = '';
      if (quickBtn) {
        const totalAll = Math.max(currentFolderTotalCount, visCount);
        if (totalAll > selectedMailUids.size) {
          quickBtn.textContent = `Tüm Klasörü Seç (${totalAll.toLocaleString('tr-TR')})`;
          quickBtn.onclick = () => selectAllFolderMails();
          quickBtn.style.display = 'inline-flex';
        } else {
          quickBtn.style.display = 'none';
        }
      }
    }

    if (moveBtn) {
      moveBtn.title = `Seçilenleri Klasöre Taşı (${formattedCount})`;
    }
    if (deleteBtn) {
      deleteBtn.title = isTrash ? `Kalıcı Olarak Sil (${formattedCount})` : `Seçilenleri Sil (${formattedCount})`;
    }
    if (readBtn) {
      readBtn.title = `Okundu Olarak İşaretle (${formattedCount})`;
    }
    if (unreadBtn) {
      unreadBtn.title = `Okunmadı Olarak İşaretle (${formattedCount})`;
    }
    if (spamBtn) {
      spamBtn.title = isSpamFolder 
        ? `Spam Listesinden Çıkar / Gelen Kutusuna Taşı (${formattedCount})` 
        : `İstenmeyen (Spam) Olarak İşaretle (${formattedCount})`;
    }
    if (closeBtn) {
      closeBtn.title = `Seçimi Temizle (${formattedCount} öğe)`;
    }
    if (bulkButtons) bulkButtons.style.display = 'inline-flex';
  } else {
    if (countText) countText.textContent = 'Tümünü Seç';
    if (bulkButtons) bulkButtons.style.display = 'none';
    if (allChk) allChk.checked = false;
    if (chkVis) chkVis.textContent = '';
    if (chkAll) chkAll.textContent = '';
    if (quickBtn) {
      quickBtn.textContent = 'Görünenleri Seç';
      quickBtn.onclick = () => selectVisibleMails();
      quickBtn.style.display = 'inline-flex';
    }
  }
}

function getAvailableMoveTargetFolders(excludeFolder = null) {
  const activeFolder = excludeFolder || currentFolder;
  const allFolders = [
    { name: 'INBOX', display_name: 'Gelen Kutusu', color: 'var(--primary)' },
    { name: 'Sent', display_name: 'Gönderilenler', color: 'var(--info)' },
    { name: 'Drafts', display_name: 'Taslaklar', color: 'var(--warning)' },
    { name: window.systemSpamFolderName || 'Junk', display_name: 'İstenmeyen (Spam)', color: '#f59e0b' },
    { name: 'Trash', display_name: 'Çöp Kutusu', color: 'var(--danger)' },
    ...(Array.isArray(cachedCustomFolders) ? cachedCustomFolders.map(cf => ({
      name: cf.folder_name,
      display_name: decodeImapUtf7(cf.display_name || cf.folder_name),
      color: cf.color || 'var(--primary)'
    })) : [])
  ];
  return allFolders.filter(f => f.name !== activeFolder);
}

function toggleBulkMoveDropdown(event) {
  if (event) event.stopPropagation();
  closeMailSelectDropdown();
  closeReaderMoveDropdown();
  const menu = document.getElementById('bulk-move-dropdown');
  if (!menu) return;
  const isShown = (menu.style.display === 'block');
  if (isShown) {
    closeBulkMoveDropdown();
    return;
  }

  const available = getAvailableMoveTargetFolders(currentFolder);
  let html = '<div class="mail-sort-heading">Klasöre Taşı</div>';
  if (available.length === 0) {
    html += '<div style="padding:8px 12px;color:var(--text-muted);font-size:0.75rem;">Taşınabilecek klasör yok.</div>';
  } else {
    available.forEach(f => {
      const safeName = escapeHtml(f.name).replace(/'/g, "\\'");
      const safeDisp = escapeHtml(f.display_name).replace(/'/g, "\\'");
      html += `
        <button type="button" class="mail-sort-item" onclick="bulkMoveSelectedMails('${safeName}', '${safeDisp}')">
          <span style="display:inline-block;width:8px;height:8px;background:${f.color || 'var(--primary)'};flex-shrink:0;"></span>
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(f.display_name)}</span>
        </button>
      `;
    });
  }
  html += `
    <div class="mail-sort-divider"></div>
    <button type="button" class="mail-sort-item" onclick="closeBulkMoveDropdown(); openNewFolderModal();">
      <span style="color:var(--primary);font-weight:700;">+</span>
      <span style="color:var(--primary);font-weight:600;">Yeni Klasör Oluştur</span>
    </button>
  `;
  menu.innerHTML = html;
  menu.style.display = 'block';
}

function closeBulkMoveDropdown() {
  const menu = document.getElementById('bulk-move-dropdown');
  if (menu) menu.style.display = 'none';
}

function bulkMoveSelectedMails(toFolder, displayName = '') {
  closeBulkMoveDropdown();
  const count = getEffectiveSelectedCount();
  if (count === 0 || !toFolder) return;

  const wasAllFolder = isAllFolderSelected;
  const uids = Array.from(selectedMailUids);
  const targetLabel = displayName || toFolder;

  const proceedMove = () => {
    showToast(`E-postalar "${targetLabel}" klasörüne taşınıyor...`, 'info');
    const fd = new FormData();
    fd.append('action', 'bulk_move_emails');
    fd.append('from_folder', currentFolder);
    fd.append('to_folder', toFolder);
    fd.append('uids', uids.join(','));
    if (wasAllFolder) {
      fd.append('select_all_folder', '1');
      fd.append('filter', currentFilter || 'all');
      fd.append('search', currentSearch || '');
    }

    fetch('api.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showToast(data.message || `${data.count || count} e-posta "${targetLabel}" klasörüne taşındı.`, 'success');
          const wasActiveMoved = wasAllFolder || uids.some(u => String(u) === String(currentUid || currentEmailUid));
          clearMailSelection();
          if (wasAllFolder) {
            loadMessages(true);
          } else {
            uids.forEach(uid => removeMailItemFromList(uid, false));
          }
          if (wasActiveMoved) {
            currentUid = null;
            currentEmailUid = null;
            const reader = document.getElementById('mail-reader-pane');
            if (reader) {
              reader.innerHTML = `
                <div class="reader-empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                  </svg>
                  <p>${(typeof t === 'function' ? t('select_email_to_read', 'Okumak için listeden bir e-posta seçin') : 'Okumak için listeden bir e-posta seçin')}</p>
                </div>
              `;
            }
          }
          if (typeof updateFolderCounts === 'function') updateFolderCounts();
        } else {
          showToast(data.message || 'Taşıma işlemi başarısız oldu.', 'error');
        }
      })
      .catch(err => showToast('Bağlantı hatası: ' + err.message, 'error'));
  };

  if (wasAllFolder && count > 25) {
    swalConfirm(
      'Tüm Klasörü Taşı',
      `Klasördeki tüm e-postaları (${count.toLocaleString('tr-TR')} adet) "${targetLabel}" klasörüne taşımak istediğinize emin misiniz?`,
      'Tümünü Taşı',
      'info'
    ).then(res => {
      if (res.isConfirmed) proceedMove();
    });
  } else {
    proceedMove();
  }
}

function bulkDeleteSelectedMails() {
  const count = getEffectiveSelectedCount();
  if (count === 0) return;

  const wasAllFolder = isAllFolderSelected;
  const uids = Array.from(selectedMailUids);
  const isTrash = (currentFolder.toLowerCase().includes('trash') || currentFolder.toLowerCase().includes('çöp'));
  const title = isTrash ? 'E-postaları Kalıcı Olarak Sil' : 'Seçilenleri Çöp Kutusuna Taşı';
  const text = `${ wasAllFolder ? 'Klasördeki tüm e-postaları (' + count.toLocaleString('tr-TR') + ' adet)' : count + ' adet e-postayı' } ${isTrash ? 'kalıcı olarak silmek' : 'çöp kutusuna taşımak'} istediğinize emin misiniz?`;
  const confirmText = isTrash ? 'Kalıcı Olarak Sil' : 'Sil';

  swalConfirm(title, text, confirmText, 'warning').then(res => {
    if (res.isConfirmed) {
      showToast('E-postalar siliniyor...', 'info');

      const fd = new FormData();
      fd.append('action', 'bulk_delete_emails');
      fd.append('folder', currentFolder);
      fd.append('uids', uids.join(','));
      if (wasAllFolder) {
        fd.append('select_all_folder', '1');
        fd.append('filter', currentFilter || 'all');
        fd.append('search', currentSearch || '');
      }

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || `${data.count || count} e-posta silindi.`, 'success');
            const wasActiveDeleted = wasAllFolder || uids.some(u => String(u) === String(currentUid || currentEmailUid));
            clearMailSelection();
            if (wasAllFolder) {
              loadMessages(true);
            } else {
              uids.forEach(uid => removeMailItemFromList(uid, false));
            }
            if (wasActiveDeleted) {
              const nextRemItem = !wasAllFolder ? document.querySelector('.mail-item:not(.is-deleting)') : null;
              if (nextRemItem) {
                setTimeout(() => { nextRemItem.click(); }, 60);
              } else {
                currentUid = null;
                currentEmailUid = null;
                const reader = document.getElementById('mail-reader-pane');
                if (reader) {
                  reader.innerHTML = `
                    <div class="reader-empty-state">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                      </svg>
                      <p>${(typeof t === 'function' ? t('select_email_to_read', 'Okumak için listeden bir e-posta seçin') : 'Okumak için listeden bir e-posta seçin')}</p>
                    </div>
                  `;
                }
              }
            }
            if (typeof updateFolderCounts === 'function') updateFolderCounts();
          } else {
            showToast(data.message || 'Silme işlemi başarısız oldu.', 'error');
          }
        })
        .catch(err => {
          showToast('Bağlantı hatası: ' + err.message, 'error');
        });
    }
  });
}

function bulkMarkStatus(type) {
  const count = getEffectiveSelectedCount();
  if (count === 0) return;

  const wasAllFolder = isAllFolderSelected;
  const uids = Array.from(selectedMailUids);
  const fd = new FormData();
  fd.append('action', 'bulk_mark_status');
  fd.append('folder', currentFolder);
  fd.append('type', type);
  fd.append('uids', uids.join(','));
  if (wasAllFolder) {
    fd.append('select_all_folder', '1');
    fd.append('filter', currentFilter || 'all');
    fd.append('search', currentSearch || '');
  }

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(`Seçilen ${data.count || count} e-posta güncellendi.`, 'success');
        document.querySelectorAll('.mail-item').forEach(item => {
          const u = parseInt(item.id.replace('mail-item-', ''), 10);
          if (wasAllFolder || selectedMailUids.has(u)) {
            if (type === 'seen') item.classList.remove('unread');
            else if (type === 'unseen') item.classList.add('unread');
          }
        });
        clearMailSelection();
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast(data.message || 'İşlem başarısız oldu.', 'error');
      }
    })
    .catch(() => showToast('Bağlantı hatası', 'error'));
}

function handleMailItemClick(e, uid, folder = null) {
  if (e.target.closest('.mail-select-chk') || e.target.closest('.star-btn') || e.target.closest('.spam-action-btn')) return;
  viewEmail(uid, folder);
}

// Bulk Mark as Spam / Unmark Spam
function bulkMarkSpamSelectedMails() {
  const count = getEffectiveSelectedCount();
  if (count === 0) return;

  const wasAllFolder = isAllFolderSelected;
  const uids = Array.from(selectedMailUids);
  const isSpamFolder = (currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen'));
  const title = isSpamFolder ? 'Seçilenleri Spam Listesinden Çıkar' : 'Seçilenleri Spam Olarak İşaretle';
  const text = `${wasAllFolder ? 'Klasördeki tüm e-postaları (' + count.toLocaleString('tr-TR') + ' adet)' : count + ' adet e-postayı'} ${isSpamFolder ? 'Gelen Kutusu\'na taşımak' : 'İstenmeyen (Spam) klasörüne taşımak'} istediğinize emin misiniz?`;
  const confirmText = isSpamFolder ? 'Gelen Kutusu\'na Taşı' : 'Spam Olarak İşaretle';

  swalConfirm(title, text, confirmText, isSpamFolder ? 'info' : 'warning').then(res => {
    if (res.isConfirmed) {
      showToast(isSpamFolder ? 'E-postalar taşınıyor...' : 'Spam olarak işaretleniyor...', 'info');

      const fd = new FormData();
      fd.append('action', 'bulk_mark_spam');
      fd.append('folder', currentFolder);
      fd.append('spam_action', isSpamFolder ? 'unspam' : 'spam');
      fd.append('uids', uids.join(','));
      if (wasAllFolder) {
        fd.append('select_all_folder', '1');
        fd.append('filter', currentFilter || 'all');
        fd.append('search', currentSearch || '');
      }

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || `${data.count || count} e-posta güncellendi.`, 'success');
            const wasActiveDeleted = wasAllFolder || uids.some(u => String(u) === String(currentUid || currentEmailUid));
            clearMailSelection();
            if (wasAllFolder) {
              loadMessages(true);
            } else {
              uids.forEach(uid => removeMailItemFromList(uid, false));
            }
            if (wasActiveDeleted) {
              const nextRemItem = !wasAllFolder ? document.querySelector('.mail-item:not(.is-deleting)') : null;
              if (nextRemItem) {
                setTimeout(() => { nextRemItem.click(); }, 60);
              } else {
                currentUid = null;
                currentEmailUid = null;
                const reader = document.getElementById('mail-reader-pane');
                if (reader) {
                  reader.innerHTML = `
                    <div class="reader-empty-state">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                      </svg>
                      <p>${(typeof t === 'function' ? t('select_email_to_read', 'Okumak için listeden bir e-posta seçin') : 'Okumak için listeden bir e-posta seçin')}</p>
                    </div>
                  `;
                }
              }
            }
            if (typeof updateFolderCounts === 'function') updateFolderCounts();
          } else {
            showToast(data.message || 'İşlem başarısız oldu.', 'error');
          }
        })
        .catch(err => {
          showToast('Bağlantı hatası: ' + err.message, 'error');
        });
    }
  });
}

// Single Mark as Spam / Unmark Spam
function markEmailSpam(uid, spamAction = null, folder = null) {
  const targetFolder = folder || currentFolder;
  const isSpamFolder = (targetFolder.toLowerCase().includes('junk') || targetFolder.toLowerCase().includes('spam') || targetFolder.toLowerCase().includes('istenmeyen'));
  const isActionSpam = (spamAction === 'spam') || (!spamAction && !isSpamFolder);

  const row = document.getElementById('mail-item-' + uid);
  if (row) {
    row.style.transition = 'all 0.25s ease';
    row.style.opacity = '0.35';
    row.style.transform = 'translateX(-10px)';
  }

  const fd = new FormData();
  fd.append('action', 'mark_spam');
  fd.append('folder', targetFolder);
  fd.append('uid', uid);
  if (spamAction) {
    fd.append('spam_action', spamAction);
  }

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message || (isActionSpam ? 'E-posta İstenmeyen (Spam) klasörüne taşındı.' : 'E-posta Gelen Kutusu\'na taşındı.'), 'success');
        removeMailItemFromList(uid);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast(data.message || 'İşlem başarısız oldu.', 'error');
        if (row) {
          row.style.opacity = '1';
          row.style.transform = 'none';
        }
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
      if (row) {
        row.style.opacity = '1';
        row.style.transform = 'none';
      }
    });
}

// ==========================================
// INFINITE SCROLL & MAIL LIST ENGINE
// ==========================================
let mailPage = 1;
let mailTotalPages = 1;
let mailIsLoadingMore = false;
let mailScrollListenerAttached = false;

function buildMailItemHtml(msg) {
  if (cachedMessages[msg.uid]) {
    cachedMessages[msg.uid] = Object.assign({}, cachedMessages[msg.uid], msg);
  } else {
    cachedMessages[msg.uid] = msg;
  }
  if (isAllFolderSelected && msg.uid) {
    selectedMailUids.add(msg.uid);
  }
  const isSelected = isAllFolderSelected || selectedMailUids.has(msg.uid);
  const msgFolder = msg.folder || currentFolder || '';
  const isSentMsg = Boolean(
    msg.is_sent ||
    msgFolder.toLowerCase().includes('sent') ||
    msgFolder.toLowerCase().includes('gönderilen')
  );
  const sender = (msg.from && (msg.from.name || msg.from.email)) ? (msg.from.name || msg.from.email) : (typeof msg.from === 'string' ? msg.from : 'Bilinmeyen');
  const recipient = (msg.to && (msg.to.name || msg.to.email)) ? (msg.to.name || msg.to.email) : (typeof msg.to === 'string' ? msg.to : '');
  const displayPerson = (isSentMsg && recipient) ? recipient : sender;
  const displayTooltip = (isSentMsg && msg.to && (msg.to.raw || msg.to.email || recipient))
    ? ('Kime: ' + (msg.to.raw || msg.to.email || recipient))
    : sender;
  const initial = (displayPerson.replace(/^["'\s]+/, '').charAt(0) || '?').toUpperCase();
  const folderTag = (msg.folder_tag && (isAllFoldersSearch || currentFolder === 'ALL')) 
    ? `<span class="mail-folder-pill" title="Klasör: ${escapeHtml(msg.folder)}">${escapeHtml(msg.folder_tag)}</span>` 
    : '';

  return `
    <div class="mail-item ${!msg.is_seen ? 'unread' : ''} ${isSelected ? 'selected' : ''}" id="mail-item-${msg.uid}" 
         onclick="handleMailItemClick(event, ${msg.uid}, '${escapeHtml(msgFolder)}')"
         oncontextmenu="handleMailContextMenu(event, ${msg.uid}, '${escapeHtml(msgFolder)}')">
      <div style="display:flex;align-items:center;padding-right:2px;flex-shrink:0;" onclick="event.stopPropagation()">
        <input type="checkbox" class="mail-select-chk" data-uid="${msg.uid}" ${isSelected ? 'checked' : ''} onchange="onMailItemCheckboxChange(${msg.uid}, this.checked)" style="width:14px;height:14px;cursor:pointer;accent-color:var(--primary);border-radius:0 !important;margin:0;">
      </div>
      <div class="mail-avatar" title="${escapeHtml(displayTooltip)}">${initial}</div>
      <div class="mail-content">
        <div class="mail-header-row">
          <span class="mail-sender" title="${escapeHtml(displayTooltip)}">${escapeHtml(displayPerson)}</span>
          <div class="mail-badges-inline">
            ${msg.has_attachment ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);vertical-align:middle;" title="Ek dosya var"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>' : ''}
            <button class="star-btn ${msg.is_flagged ? 'starred' : ''}" onclick="toggleStar(${msg.uid}, event, '${escapeHtml(msgFolder)}')" title="${msg.is_flagged ? 'Yıldızı Kaldır' : 'Yıldızla'}">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="${msg.is_flagged ? '#fbbf24' : 'none'}" stroke="${msg.is_flagged ? '#fbbf24' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </button>
            <button class="spam-action-btn ${(currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen')) ? 'in-spam' : ''}" onclick="markEmailSpam(${msg.uid}, ${(currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen')) ? '\'unspam\'' : '\'spam\''}, '${escapeHtml(msgFolder)}'); event.stopPropagation();" title="${(currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen')) ? 'Spam Değil (Gelen Kutusuna Taşı)' : 'Spam Olarak İşaretle'}">
              ${(currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen')) 
                ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>' 
                : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'}
            </button>
            <span class="mail-date">${msg.date}</span>
          </div>
        </div>
        <div class="mail-subject" title="${escapeHtml(msg.subject)}">${folderTag}${escapeHtml(msg.subject)}</div>
      </div>
    </div>
  `;
}

// ==========================================
// CONTEXT MENU LOGIC (RIGHT CLICK ON EMAIL)
// ==========================================
function handleMailContextMenu(e, uid) {
  e.preventDefault();
  e.stopPropagation();
  contextMenuTargetUid = uid;

  const menu = document.getElementById('mail-context-menu');
  if (!menu) return;

  const isSpamFolder = (currentFolder.toLowerCase().includes('junk') || currentFolder.toLowerCase().includes('spam') || currentFolder.toLowerCase().includes('istenmeyen'));
  const ctxSpamLabel = document.getElementById('ctx-spam-label');
  if (ctxSpamLabel) {
    ctxSpamLabel.textContent = isSpamFolder ? 'Spam Değil (Gelen Kutusuna Taşı)' : 'Spam Olarak İşaretle';
  }

  menu.style.display = 'block';

  const menuWidth = 230;
  const menuHeight = 250;
  let x = e.clientX;
  let y = e.clientY;

  if (x + menuWidth > window.innerWidth) {
    x = window.innerWidth - menuWidth - 10;
  }
  if (y + menuHeight > window.innerHeight) {
    y = window.innerHeight - menuHeight - 10;
  }

  menu.style.left = `${Math.max(10, x)}px`;
  menu.style.top = `${Math.max(10, y)}px`;
}

function hideContextMenu() {
  const menu = document.getElementById('mail-context-menu');
  if (menu) menu.style.display = 'none';
}

let folderContextMenuTarget = null;

function handleFolderContextMenu(e, folderName, displayName, color, id) {
  e.preventDefault();
  e.stopPropagation();
  hideContextMenu();
  hideFolderContextMenu();

  let showName = displayName;
  if (!showName || showName.includes('??')) {
    showName = decodeImapUtf7(folderName);
  } else {
    showName = decodeImapUtf7(showName);
  }

  folderContextMenuTarget = { folderName, displayName: showName, color: color || '#4f46e5', id: id || 0 };

  const menu = document.getElementById('folder-context-menu');
  if (!menu) return;

  menu.style.display = 'block';

  const menuWidth = 220;
  const menuHeight = 130;
  let x = e.clientX;
  let y = e.clientY;

  if (x + menuWidth > window.innerWidth) {
    x = window.innerWidth - menuWidth - 10;
  }
  if (y + menuHeight > window.innerHeight) {
    y = window.innerHeight - menuHeight - 10;
  }

  menu.style.left = `${Math.max(10, x)}px`;
  menu.style.top = `${Math.max(10, y)}px`;
}

function hideFolderContextMenu() {
  const menu = document.getElementById('folder-context-menu');
  if (menu) menu.style.display = 'none';
}

function folderContextMenuAction(act) {
  const target = folderContextMenuTarget;
  hideFolderContextMenu();
  if (!target) return;

  if (act === 'rename' || act === 'color') {
    openRenameFolderModal(target.folderName, target.displayName, target.color, target.id);
  } else if (act === 'delete') {
    deleteCustomFolder(target.id, target.folderName, target.displayName);
  }
}

document.addEventListener('click', (e) => {
  hideContextMenu();
  hideFolderContextMenu();
  if (!e.target.closest('#mail-sort-container')) {
    closeMailSortMenu();
  }
  if (!e.target.closest('#mail-select-menu-container')) {
    closeMailSelectDropdown();
  }
  if (!e.target.closest('#bulk-move-container')) {
    closeBulkMoveDropdown();
  }
  if (!e.target.closest('#reader-move-container')) {
    closeReaderMoveDropdown();
  }
});
document.addEventListener('contextmenu', (e) => {
  if (!e.target.closest('.mail-item')) hideContextMenu();
  if (!e.target.closest('.nav-item')) hideFolderContextMenu();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    hideContextMenu();
    hideFolderContextMenu();
    closeMailSortMenu();
    closeMailSelectDropdown();
    closeBulkMoveDropdown();
    closeReaderMoveDropdown();
  }

  // Metin kutularında, imza editöründe veya aktif modal varsa kısayolları yoksay
  const tag = (e.target.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable || (e.target.closest && e.target.closest('[contenteditable="true"]'))) {
    return;
  }
  if (document.querySelector('.modal.active') || document.querySelector('.swal2-container')) {
    return;
  }

  // Delete veya Backspace: Açık e-postayı siler ve otomatik sonraki e-postaya geçer
  if (e.key === 'Delete') {
    const activeId = currentUid || currentEmailUid;
    if (activeId) {
      e.preventDefault();
      deleteEmail(activeId);
    }
  } else if ((e.key === 'ArrowDown' || e.key === 'j') && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const activeId = currentUid || currentEmailUid;
    if (activeId) {
      e.preventDefault();
      navigateEmail('next');
    }
  } else if ((e.key === 'ArrowUp' || e.key === 'k') && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const activeId = currentUid || currentEmailUid;
    if (activeId) {
      e.preventDefault();
      navigateEmail('prev');
    }
  }
});

function contextMenuAction(act) {
  const uid = contextMenuTargetUid;
  hideContextMenu();
  if (!uid) return;

  if (act === 'filter') {
    openFilterModalForMessage(uid);
  } else if (act === 'toggle_read') {
    toggleReadStatus(uid);
  } else if (act === 'star') {
    toggleStar(uid);
  } else if (act === 'move') {
    openMoveModal(uid);
  } else if (act === 'spam') {
    markEmailSpam(uid);
  } else if (act === 'delete') {
    deleteEmail(uid);
  }
}

function toggleReadStatus(uid) {
  const item = document.getElementById('mail-item-' + uid);
  const isCurrentlyUnread = item ? item.classList.contains('unread') : false;
  const targetType = isCurrentlyUnread ? 'seen' : 'unseen';

  const formData = new FormData();
  formData.append('action', 'mark_status');
  formData.append('folder', currentFolder);
  formData.append('uid', uid);
  formData.append('type', targetType);

  fetch('api.php', { method: 'POST', body: formData })
    .then(() => {
      if (item) {
        if (isCurrentlyUnread) {
          item.classList.remove('unread');
          if (cachedFolderCounts && cachedFolderCounts[currentFolder] && cachedFolderCounts[currentFolder].unseen > 0) {
            cachedFolderCounts[currentFolder].unseen--;
            applyFolderCounts(cachedFolderCounts);
          }
        } else {
          item.classList.add('unread');
          if (cachedFolderCounts && cachedFolderCounts[currentFolder]) {
            cachedFolderCounts[currentFolder].unseen = (cachedFolderCounts[currentFolder].unseen || 0) + 1;
            applyFolderCounts(cachedFolderCounts);
          }
        }
      }
      showToast(isCurrentlyUnread ? 'Okundu olarak işaretlendi' : 'Okunmadı olarak işaretlendi');
    });
}

function ensureFilterTargetDropdown(callback) {
  const select = document.getElementById('filter-action-target');
  if (select && select.options.length > 0) {
    if (callback) callback();
    return;
  }
  fetch('api.php?action=get_folders')
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        cachedCustomFolders = data.custom_folders || [];
        populateFilterTargetDropdown(cachedCustomFolders, data.system_folders);
        if (callback) callback();
      }
    });
}

function openFilterModalForMessage(uid) {
  hideContextMenu();
  const msg = cachedMessages[uid];
  const senderEmail = (msg && msg.from && msg.from.email) ? msg.from.email : '';
  const senderName = (msg && msg.from && msg.from.name) ? msg.from.name : senderEmail;

  ensureFilterTargetDropdown(() => {
    let targetFolder = '';
    const targetSelect = document.getElementById('filter-action-target');
    if (targetSelect && targetSelect.options.length > 0) {
      targetFolder = targetSelect.options[0].value;
    }

    openFilterModal({
      id: '',
      filter_name: senderName ? `${senderName} Kuralı` : 'Gelen E-posta Kuralı',
      match_field: 'from',
      match_type: 'contains',
      match_value: senderEmail,
      action_type: 'move',
      action_target: targetFolder,
      is_active: 1
    });
  });
}

function buildGetMessagesUrl(page = 1) {
  let url = `api.php?action=get_messages&page=${page}&sort=${encodeURIComponent(currentSort)}`;
  if (activeAdvSearch) {
    url += `&folder=${encodeURIComponent(activeAdvSearch.folder)}`;
    if (activeAdvSearch.from) url += `&adv_from=${encodeURIComponent(activeAdvSearch.from)}`;
    if (activeAdvSearch.to) url += `&adv_to=${encodeURIComponent(activeAdvSearch.to)}`;
    if (activeAdvSearch.subject) url += `&adv_subject=${encodeURIComponent(activeAdvSearch.subject)}`;
    if (activeAdvSearch.body) url += `&adv_body=${encodeURIComponent(activeAdvSearch.body)}`;
    if (activeAdvSearch.date_from) url += `&adv_date_from=${encodeURIComponent(activeAdvSearch.date_from)}`;
    if (activeAdvSearch.date_to) url += `&adv_date_to=${encodeURIComponent(activeAdvSearch.date_to)}`;
    if (activeAdvSearch.status) url += `&adv_status=${encodeURIComponent(activeAdvSearch.status)}`;
    if (activeAdvSearch.has_attachment) url += `&has_attachment=1`;
  } else {
    url += `&folder=${encodeURIComponent(currentFolder)}&search=${encodeURIComponent(currentSearch)}&filter=${currentFilter}`;
  }
  return url;
}

// ==========================================
// EMAIL SORTING LOGIC
// ==========================================
const SORT_LABELS = {
  'date_desc': 'Tarih: En Yeni Önce',
  'date_asc': 'Tarih: En Eski Önce',
  'from_asc': 'İsim: A\'dan Z\'ye',
  'from_desc': 'İsim: Z\'den A\'ya',
  'subject_asc': 'Konu: A\'dan Z\'ye',
  'subject_desc': 'Konu: Z\'den A\'ya'
};

const SORT_I18N_KEYS = {
  'date_desc': 'sort_date_desc',
  'date_asc': 'sort_date_asc',
  'from_asc': 'sort_from_asc',
  'from_desc': 'sort_from_desc',
  'subject_asc': 'sort_subject_asc',
  'subject_desc': 'sort_subject_desc'
};

function toggleMailSortMenu(event) {
  if (event) {
    event.stopPropagation();
  }
  const menu = document.getElementById('mail-sort-dropdown');
  const btn = document.getElementById('mail-sort-btn');
  if (!menu) return;
  const isShown = (menu.style.display === 'block');
  if (isShown) {
    closeMailSortMenu();
  } else {
    menu.style.display = 'block';
    if (btn) btn.classList.add('active');
    updateMailSortDropdownUI();
  }
}

function closeMailSortMenu() {
  const menu = document.getElementById('mail-sort-dropdown');
  const btn = document.getElementById('mail-sort-btn');
  if (menu) menu.style.display = 'none';
  if (btn) btn.classList.remove('active');
}

function updateMailSortDropdownUI() {
  const labelEl = document.getElementById('mail-sort-btn-label');
  if (labelEl) {
    const i18nKey = SORT_I18N_KEYS[currentSort] || 'sort_date_desc';
    const fallbackText = SORT_LABELS[currentSort] || 'Tarih: En Yeni Önce';
    labelEl.setAttribute('data-i18n', i18nKey);
    labelEl.textContent = (typeof t === 'function') ? t(i18nKey, fallbackText) : fallbackText;
  }

  const items = document.querySelectorAll('.mail-sort-item');
  items.forEach(item => {
    const sKey = item.getAttribute('data-sort');
    const checkEl = item.querySelector('.sort-check');
    if (sKey === currentSort) {
      item.classList.add('active');
      if (checkEl) checkEl.textContent = '✓';
    } else {
      item.classList.remove('active');
      if (checkEl) checkEl.textContent = '';
    }
  });
}

function selectMailSort(sortKey) {
  if (!SORT_LABELS[sortKey]) sortKey = 'date_desc';
  currentSort = sortKey;
  try {
    localStorage.setItem('mail_sort_order', sortKey);
  } catch (e) {}

  updateMailSortDropdownUI();
  closeMailSortMenu();

  // 1. Immediately sort visible items in DOM if available
  sortVisibleMailItems(currentSort);

  // 2. Reload messages from server with the new sort parameter
  loadMessages(true);
}

function sortVisibleMailItems(sortKey) {
  const container = document.getElementById('mail-list-container');
  if (!container) return;
  const items = Array.from(container.querySelectorAll('.mail-item'));
  if (items.length <= 1) return;
  const loader = document.getElementById('mail-infinite-loader');

  let collator = null;
  try {
    collator = new Intl.Collator('tr', { sensitivity: 'base', numeric: true });
  } catch (e) {}

  items.sort((a, b) => {
    const uidA = parseInt(a.id.replace('mail-item-', ''), 10);
    const uidB = parseInt(b.id.replace('mail-item-', ''), 10);
    const msgA = cachedMessages[uidA] || {};
    const msgB = cachedMessages[uidB] || {};

    if (sortKey === 'date_asc') {
      return (msgA.timestamp || 0) - (msgB.timestamp || 0);
    } else if (sortKey === 'date_desc') {
      return (msgB.timestamp || 0) - (msgA.timestamp || 0);
    } else if (sortKey === 'from_asc') {
      const pA = (msgA.is_sent && msgA.to && (msgA.to.name || msgA.to.email)) ? msgA.to : msgA.from;
      const pB = (msgB.is_sent && msgB.to && (msgB.to.name || msgB.to.email)) ? msgB.to : msgB.from;
      const nameA = (pA && (pA.name || pA.email)) ? (pA.name || pA.email) : '';
      const nameB = (pB && (pB.name || pB.email)) ? (pB.name || pB.email) : '';
      return collator ? collator.compare(nameA, nameB) : nameA.localeCompare(nameB);
    } else if (sortKey === 'from_desc') {
      const pA = (msgA.is_sent && msgA.to && (msgA.to.name || msgA.to.email)) ? msgA.to : msgA.from;
      const pB = (msgB.is_sent && msgB.to && (msgB.to.name || msgB.to.email)) ? msgB.to : msgB.from;
      const nameA = (pA && (pA.name || pA.email)) ? (pA.name || pA.email) : '';
      const nameB = (pB && (pB.name || pB.email)) ? (pB.name || pB.email) : '';
      return collator ? collator.compare(nameB, nameA) : nameB.localeCompare(nameA);
    } else if (sortKey === 'subject_asc') {
      const subA = msgA.subject || '';
      const subB = msgB.subject || '';
      return collator ? collator.compare(subA, subB) : subA.localeCompare(subB);
    } else if (sortKey === 'subject_desc') {
      const subA = msgA.subject || '';
      const subB = msgB.subject || '';
      return collator ? collator.compare(subB, subA) : subB.localeCompare(subA);
    }
    return 0;
  });

  items.forEach(el => {
    if (loader) {
      container.insertBefore(el, loader);
    } else {
      container.appendChild(el);
    }
  });
}

function sortMessagesArray(msgs, sortKey) {
  if (!Array.isArray(msgs) || msgs.length <= 1) return msgs;
  let collator = null;
  try {
    collator = new Intl.Collator('tr', { sensitivity: 'base', numeric: true });
  } catch (e) {}

  msgs.sort((a, b) => {
    if (sortKey === 'date_asc') {
      const diff = (a.timestamp || 0) - (b.timestamp || 0);
      return diff !== 0 ? diff : ((a.msg_no || 0) - (b.msg_no || 0));
    } else if (sortKey === 'date_desc') {
      const diff = (b.timestamp || 0) - (a.timestamp || 0);
      return diff !== 0 ? diff : ((b.msg_no || 0) - (a.msg_no || 0));
    } else if (sortKey === 'from_asc') {
      const pA = (a.is_sent && a.to && (a.to.name || a.to.email)) ? a.to : a.from;
      const pB = (b.is_sent && b.to && (b.to.name || b.to.email)) ? b.to : b.from;
      const nameA = (pA && (pA.name || pA.email)) ? (pA.name || pA.email) : '';
      const nameB = (pB && (pB.name || pB.email)) ? (pB.name || pB.email) : '';
      return collator ? collator.compare(nameA, nameB) : nameA.localeCompare(nameB);
    } else if (sortKey === 'from_desc') {
      const pA = (a.is_sent && a.to && (a.to.name || a.to.email)) ? a.to : a.from;
      const pB = (b.is_sent && b.to && (b.to.name || b.to.email)) ? b.to : b.from;
      const nameA = (pA && (pA.name || pA.email)) ? (pA.name || pA.email) : '';
      const nameB = (pB && (pB.name || pB.email)) ? (pB.name || pB.email) : '';
      return collator ? collator.compare(nameB, nameA) : nameB.localeCompare(nameA);
    } else if (sortKey === 'subject_asc') {
      const subA = a.subject || '';
      const subB = b.subject || '';
      return collator ? collator.compare(subA, subB) : subA.localeCompare(subB);
    } else if (sortKey === 'subject_desc') {
      const subA = a.subject || '';
      const subB = b.subject || '';
      return collator ? collator.compare(subB, subA) : subB.localeCompare(subA);
    }
    return 0;
  });
  return msgs;
}

function loadFolderMessages(folder = null, page = 1, reset = true) {
  if (folder) currentFolder = folder;
  if (typeof page === 'number' && page > 0) mailPage = page;
  if (typeof loadMessages === 'function') {
    loadMessages(reset);
  }
}

function loadMessages(reset = true) {
  const container = document.getElementById('mail-list-container');
  if (!container) return;

  if (mailFetchAbortController) {
    mailFetchAbortController.abort();
  }
  mailFetchAbortController = new AbortController();

  if (reset) {
    mailPage = 1;
    mailTotalPages = 1;
    mailIsLoadingMore = false;
    container.innerHTML = `
      <div style="padding:2.5rem 1.5rem;text-align:center;color:var(--text-muted);">
        <div style="display:inline-block;width:24px;height:24px;border:3px solid var(--border-color);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:0.5rem;"></div>
        <p style="font-size:0.8rem;">Yükleniyor...</p>
      </div>
    `;
  }

  const url = buildGetMessagesUrl(mailPage);

  fetch(url, { signal: mailFetchAbortController.signal })
    .then(async r => {
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error('Sunucu yanıtı okunamadı (Geçersiz format)');
      }
    })
    .then(data => {
      if (!data || !data.success) {
        const msg = (data && data.message) ? data.message : 'E-postalar alınamadı.';
        container.innerHTML = `
          <div style="padding:2.5rem 1.5rem;text-align:center;color:var(--danger);font-size:0.85rem;">
            <p style="margin-bottom:0.75rem;">${escapeHtml(msg)}</p>
            <button type="button" class="bulk-btn bulk-btn-primary" onclick="loadMessages(true)">Tekrar Dene</button>
          </div>
        `;
        return;
      }

      mailTotalPages = data.pages || 1;
      currentFolderTotalCount = (typeof data.total === 'number') ? data.total : (Array.isArray(data.messages) ? data.messages.length : 0);

      if (!activeAdvSearch && !currentSearch && !currentFilter && typeof data.total === 'number') {
        const curBadge = document.getElementById('folder-count-' + currentFolder);
        if (curBadge) {
          if (cachedFolderCounts && cachedFolderCounts[currentFolder]) {
            cachedFolderCounts[currentFolder].total = data.total;
            cachedFolderCounts[currentFolder].formatted = data.total.toLocaleString('tr-TR');
            renderFolderBadge(curBadge, cachedFolderCounts[currentFolder]);
          } else {
            renderFolderBadge(curBadge, { total: data.total, formatted: data.total.toLocaleString('tr-TR'), unseen: 0 });
          }
        }
      }

      // Update search status bar
      const statusBar = document.getElementById('mail-search-status-bar');
      const statusText = document.getElementById('mail-search-status-text');
      if (activeAdvSearch || currentSearch) {
        if (statusBar && statusText) {
          const scopeName = isAllFoldersSearch ? 'Tüm Klasörler' : (activeAdvSearch ? activeAdvSearch.folder : currentFolder);
          const qText = currentSearch ? `"${currentSearch}"` : 'Gelişmiş Arama';
          statusText.textContent = `${scopeName} (${data.total} sonuç)`;
          statusBar.style.display = 'inline-flex';
        }
      }

      if (!data.messages || data.messages.length === 0) {
        const isFiltered = activeAdvSearch || currentSearch || currentFilter;
        container.innerHTML = `
          <div style="padding:2.5rem 1.5rem;text-align:center;color:var(--text-muted);">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.6rem;opacity:0.4;"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            <p style="font-size:0.825rem;font-weight:600;margin-bottom:0.25rem;">${isFiltered ? 'Arama kriterlerine uygun e-posta bulunamadı.' : 'Bu klasörde e-posta bulunmuyor.'}</p>
            ${isFiltered ? '<button type="button" onclick="clearMailSearch()" class="bulk-btn bulk-btn-primary" style="margin-top:0.5rem;">Aramayı Temizle</button>' : ''}
          </div>
        `;
        return;
      }

      sortMessagesArray(data.messages, currentSort);
      let html = data.messages.map(buildMailItemHtml).join('');
      html += `
        <div id="mail-infinite-loader" style="display:none;padding:0.65rem;text-align:center;color:var(--text-muted);font-size:0.75rem;">
          <div style="display:inline-block;width:14px;height:14px;border:2px solid var(--border-color);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px;"></div>
          Daha fazla e-posta yükleniyor...
        </div>
      `;
      container.innerHTML = html;
      updateBulkToolbarUI();

      if (!mailScrollListenerAttached) {
        mailScrollListenerAttached = true;
        container.addEventListener('scroll', handleMailListScroll);
      }
    })
    .catch(err => {
      if (err.name === 'AbortError') return;
      container.innerHTML = `
        <div style="padding:2.5rem 1.5rem;text-align:center;color:var(--danger);font-size:0.85rem;">
          <p style="margin-bottom:0.75rem;">Bağlantı hatası: ${escapeHtml(err.message || 'Sunucu yanıt vermedi')}</p>
          <button type="button" class="bulk-btn bulk-btn-primary" onclick="loadMessages(true)">Tekrar Dene</button>
        </div>
      `;
    });
}

function handleMailListScroll() {
  const container = document.getElementById('mail-list-container');
  if (!container || mailIsLoadingMore || mailPage >= mailTotalPages) return;

  if (container.scrollTop + container.clientHeight >= container.scrollHeight - 90) {
    loadNextPageMessages();
  }
}

function loadNextPageMessages() {
  if (mailIsLoadingMore || mailPage >= mailTotalPages) return;
  mailIsLoadingMore = true;

  const container = document.getElementById('mail-list-container');
  const loader = document.getElementById('mail-infinite-loader');
  if (loader) loader.style.display = 'block';

  mailPage++;

  const url = buildGetMessagesUrl(mailPage);

  fetch(url)
    .then(async r => {
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        return null;
      }
    })
    .then(data => {
      if (loader) loader.style.display = 'none';

      if (data && data.success && data.messages && data.messages.length > 0) {
        sortMessagesArray(data.messages, currentSort);
        const temp = document.createElement('div');
        temp.innerHTML = data.messages.map(buildMailItemHtml).join('');
        while (temp.firstChild) {
          container.insertBefore(temp.firstChild, loader);
        }
        updateBulkToolbarUI();
      }

      mailIsLoadingMore = false;
    })
    .catch(() => {
      if (loader) loader.style.display = 'none';
      mailIsLoadingMore = false;
    });
}

// ==========================================
// EMAIL NAVIGATION (ÖNCEKİ / SONRAKİ POSTA)
// ==========================================
function navigateEmail(direction) {
  const items = Array.from(document.querySelectorAll('.mail-item'));
  if (items.length === 0) return;

  const currentIndex = items.findIndex(el => el.id === 'mail-item-' + currentUid);
  let targetIndex = -1;

  if (currentIndex === -1) {
    targetIndex = 0;
  } else {
    targetIndex = (direction === 'prev') ? currentIndex - 1 : currentIndex + 1;
  }

  if (targetIndex >= 0 && targetIndex < items.length) {
    const targetItem = items[targetIndex];
    targetItem.click();
    targetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } else {
    showToast(direction === 'prev' ? 'Listenin başındasınız (Önceki e-posta yok).' : 'Listenin sonundasınız (Sonraki e-posta yok).', 'info');
  }
}

function updateEmailNavButtons() {
  const items = Array.from(document.querySelectorAll('.mail-item'));
  const currentIndex = items.findIndex(el => el.id === 'mail-item-' + currentUid);

  const prevBtn = document.getElementById('btn-nav-prev-mail');
  const nextBtn = document.getElementById('btn-nav-next-mail');

  if (prevBtn) {
    const hasPrev = (currentIndex > 0);
    prevBtn.disabled = !hasPrev;
  }
  if (nextBtn) {
    const hasNext = (currentIndex >= 0 && currentIndex < items.length - 1);
    nextBtn.disabled = !hasNext;
  }
}

let currentViewEmailFolder = 'INBOX';

function viewEmail(uid, folder = null) {
  currentUid = uid;
  currentEmailUid = uid;
  window.currentUid = uid;
  window.currentEmailUid = uid;
  currentViewEmailFolder = folder || currentFolder;
  const targetFolder = currentViewEmailFolder;

  document.querySelectorAll('.mail-item').forEach(el => el.classList.remove('selected'));
  document.getElementById('view-mail')?.classList.add('mobile-reading-active');
  const row = document.getElementById('mail-item-' + uid);
  if (row) {
    row.classList.add('selected');
    if (row.classList.contains('unread')) {
      row.classList.remove('unread');
      if (cachedFolderCounts && cachedFolderCounts[targetFolder] && cachedFolderCounts[targetFolder].unseen > 0) {
        cachedFolderCounts[targetFolder].unseen--;
        applyFolderCounts(cachedFolderCounts);
      }
    }
  }
  updateEmailNavButtons();

  const reader = document.getElementById('mail-reader-pane');
  reader.innerHTML = `
    <div class="reader-empty-state">
      <div style="display:inline-block;width:28px;height:28px;border:3px solid var(--border-color);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
      <p style="font-size:0.85rem;margin-top:0.5rem;">Yükleniyor...</p>
    </div>
  `;

  fetch(`api.php?action=view_email&folder=${encodeURIComponent(targetFolder)}&uid=${uid}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.email) {
        reader.innerHTML = `<div class="reader-empty-state"><p>${data.message || 'E-posta açılamadı.'}</p></div>`;
        return;
      }

      const m = data.email;
      if (!m.folder) m.folder = targetFolder;
      cachedMessages[m.uid] = m;
      window.currentViewedEmail = m;
      const sName = m.from.name || m.from.email;
      const sInitial = sName.charAt(0).toUpperCase();
      const isDraft = m.is_draft || (targetFolder.toLowerCase().includes('draft') || targetFolder.toLowerCase().includes('taslak'));

      function renderRecipientChips(list, rawStr) {
        if (Array.isArray(list) && list.length > 0) {
          return list.map(item => {
            const name = item.name || item.email || '';
            const email = item.email || '';
            const showEmail = email && name !== email;
            return `<span class="reader-recip-chip" title="${escapeHtml(email || name)}">
              <span class="recip-name">${escapeHtml(name)}</span>
              ${showEmail ? `<span class="recip-email">&lt;${escapeHtml(email)}&gt;</span>` : ''}
            </span>`;
          }).join('');
        }
        if (rawStr && typeof rawStr === 'string' && rawStr.trim() !== '') {
          return `<span class="reader-recip-chip"><span class="recip-name">${escapeHtml(rawStr.trim())}</span></span>`;
        }
        return '';
      }

      const toFallback = m.to ? (m.to.name ? (m.to.name + ' <' + m.to.email + '>') : m.to.email) : '';
      const toChips = renderRecipientChips(m.to_list, toFallback);
      const ccChips = renderRecipientChips(m.cc_list, m.cc);
      const bccChips = renderRecipientChips(m.bcc_list, m.bcc);

      let attsHtml = '';
      if (m.attachments && m.attachments.length > 0) {
        attsHtml = `
          <div class="attachments-wrapper">
            <div class="attachments-title">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span>${m.attachments.length} Ek Dosya</span>
            </div>
            <div class="attachment-grid">
              ${m.attachments.map(a => `
                <a href="api.php?action=download_attachment&folder=${encodeURIComponent(targetFolder)}&uid=${m.uid}&part=${a.part_no || 1}&filename=${encodeURIComponent(a.filename)}" 
                   download="${escapeHtml(a.filename)}" 
                   class="attachment-chip" 
                   title="${escapeHtml(a.filename)} (${a.size}) - İndirmek için tıklayın">
                  <div class="att-chip-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                  </div>
                  <div class="att-chip-meta">
                    <span class="att-chip-name">${escapeHtml(a.filename)}</span>
                    <span class="att-chip-size">(${a.size})</span>
                  </div>
                  <div class="att-chip-dl-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  </div>
                </a>
              `).join('')}
            </div>
          </div>
        `;
      }

      reader.innerHTML = `
        <div class="reader-header">
          <div class="reader-toolbar">
            <div class="toolbar-group">
              <!-- Mobil Geri Dön Düğmesi -->
              <button class="reader-action-btn mobile-reader-back-btn" title="Listeye Dön" onclick="backToMailList()" style="display:none;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                <span>Geri</span>
              </button>

              <!-- Önceki / Sonraki İleti Düğmeleri -->
              <button class="reader-action-btn" id="btn-nav-prev-mail" title="Önceki E-posta" onclick="navigateEmail('prev')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
                <span>Önceki</span>
              </button>
              <button class="reader-action-btn" id="btn-nav-next-mail" title="Sonraki E-posta" onclick="navigateEmail('next')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
                <span>Sonraki</span>
              </button>

              <div style="width:1px;height:32px;background:var(--border-color);margin:0 2px;align-self:center;"></div>

              ${isDraft ? `
                <button class="reader-action-btn" title="${typeof t === 'function' ? t('draft_resume_btn', 'Taslağı Düzenle ve Gönder') : 'Taslağı Düzenle ve Gönder'}" onclick="resumeDraft(${m.uid})" style="color:var(--primary);border-color:var(--primary);font-weight:600;" data-i18n-title="draft_resume_btn">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                  <span data-i18n="draft_edit_btn">${typeof t === 'function' ? t('draft_edit_btn', 'Düzenle') : 'Düzenle'}</span>
                </button>
              ` : `
                <button class="reader-action-btn" title="${typeof t === 'function' ? t('reply', 'Yanıtla') : 'Yanıtla'}" onclick="replyEmail(${m.uid})" data-i18n-title="reply">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                  <span data-i18n="reply">${typeof t === 'function' ? t('reply', 'Yanıtla') : 'Yanıtla'}</span>
                </button>
                <button class="reader-action-btn btn-text-wrap" title="${typeof t === 'function' ? t('reply_all_desc', 'Tümünü Yanıtla (Cc ve Bcc dahil)') : 'Tümünü Yanıtla (Cc ve Bcc dahil)'}" onclick="replyAllEmail(${m.uid})" data-i18n-title="reply_all_desc">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="7 17 2 12 7 7"/>
                    <polyline points="12 17 7 12 12 7"/>
                    <path d="M22 18v-2a4 4 0 0 0-4-4H7"/>
                  </svg>
                  <span data-i18n="reply_all_btn">${typeof t === 'function' ? t('reply_all_btn', 'T. Yanıtla') : 'T. Yanıtla'}</span>
                </button>
                <button class="reader-action-btn" title="${typeof t === 'function' ? t('forward', 'İlet') : 'İlet'}" onclick="forwardEmail(${m.uid})" data-i18n-title="forward">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/></svg>
                  <span data-i18n="forward">${typeof t === 'function' ? t('forward', 'İlet') : 'İlet'}</span>
                </button>
              `}
              <button class="reader-action-btn ${m.is_flagged ? 'starred' : ''}" id="reader-star-btn" title="${m.is_flagged ? 'Yıldızı Kaldır' : 'Yıldızla'}" onclick="toggleStar(${m.uid}, event, '${escapeHtml(targetFolder)}')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="${m.is_flagged ? '#fbbf24' : 'none'}" stroke="${m.is_flagged ? '#fbbf24' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <span>${m.is_flagged ? 'Yıldızlı' : 'Yıldızla'}</span>
              </button>
              <button class="reader-action-btn" title="Bu Gönderene Filtre / Kural Oluştur" onclick="openFilterModalForMessage(${m.uid})">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                <span>Filtre</span>
              </button>
              <div id="reader-move-container" style="position:relative;display:inline-flex;">
                <button class="reader-action-btn" title="Klasöre Taşı" onclick="toggleReaderMoveDropdown(event, ${m.uid}, '${escapeHtml(targetFolder)}')">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="12 11 12 17 9 14"/></svg>
                  <span>Taşı ▾</span>
                </button>
                <div id="reader-move-dropdown" class="mail-sort-dropdown" style="left:0;right:auto;top:calc(100% + 4px);width:225px;max-height:280px;overflow-y:auto;" onclick="event.stopPropagation()"></div>
              </div>
              ${m.is_spam ? `
                <button class="reader-action-btn btn-unspam" title="Spam Değil (Gelen Kutusuna Taşı)" onclick="markEmailSpam(${m.uid}, 'unspam', '${escapeHtml(targetFolder)}')">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                  <span>Spam Değil</span>
                </button>
              ` : `
                <button class="reader-action-btn btn-spam" title="Spam Olarak İşaretle" onclick="markEmailSpam(${m.uid}, 'spam', '${escapeHtml(targetFolder)}')">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <span>Spam</span>
                </button>
              `}
              <button class="reader-action-btn btn-delete" title="Sil" onclick="deleteEmail(${m.uid}, '${escapeHtml(targetFolder)}')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <span>Sil</span>
              </button>
            </div>
            <div class="toolbar-group">
              <button class="reader-action-btn ${isWideReaderActive ? 'active' : ''}" id="btn-toggle-wide-reader" title="${isWideReaderActive ? 'Standart Okuma Alanı' : 'Tam / Geniş Okuma Alanı'}" onclick="toggleWideReadingPane()">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  ${isWideReaderActive ? '<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/>' : '<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>'}
                </svg>
                <span>${isWideReaderActive ? 'Daralt' : 'Genişlet'}</span>
              </button>
            </div>
          </div>

          ${isDraft ? `
            <div class="draft-alert-banner">
              <div class="draft-alert-content">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <div>
                  <strong style="display:block;margin-bottom:2px;" data-i18n="draft_banner_title">${typeof t === 'function' ? t('draft_banner_title', 'Bu bir taslaktır.') : 'Bu bir taslaktır.'}</strong>
                  <span style="font-size:0.8rem;opacity:0.9;" data-i18n="draft_banner_desc">${typeof t === 'function' ? t('draft_banner_desc', 'Bu ileti henüz gönderilmedi. Göndermek veya devam etmek için düzenleme penceresini açabilirsiniz.') : 'Bu ileti henüz gönderilmedi. Göndermek veya devam etmek için düzenleme penceresini açabilirsiniz.'}</span>
                </div>
              </div>
              <button class="btn-resume-draft-banner" onclick="resumeDraft(${m.uid})">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                <span data-i18n="draft_resume_btn">${typeof t === 'function' ? t('draft_resume_btn', 'Taslağı Düzenle ve Gönder') : 'Taslağı Düzenle ve Gönder'}</span>
              </button>
            </div>
          ` : ''}

          ${m.is_spam ? `
            <div class="spam-alert-banner">
              <div class="spam-alert-content">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div>
                  <strong style="display:block;margin-bottom:2px;">Bu ileti İstenmeyen (Spam) klasöründe bulunuyor.</strong>
                  <span style="font-size:0.8rem;opacity:0.9;">Bu ileti spam değilse güvenle Gelen Kutusu'na taşıyabilirsiniz.</span>
                </div>
              </div>
              <button class="btn-unspam-banner" onclick="markEmailSpam(${m.uid}, 'unspam', '${escapeHtml(targetFolder)}')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Spam Değil Olarak İşaretle</span>
              </button>
            </div>
          ` : ''}

          <h2 class="reader-subject">${escapeHtml(m.subject)}</h2>

          <div class="reader-sender-box">
            <div class="sender-details">
              <div class="sender-avatar-lg">${sInitial}</div>
              <div class="sender-info-col">
                <div class="sender-row-main">
                  <span class="sender-name-lg">${escapeHtml(sName)}</span>
                  <span class="sender-email-lg">&lt;${escapeHtml(m.from.email)}&gt;</span>
                </div>
                <div class="reader-recipients-box">
                  ${toChips ? `
                    <div class="reader-recip-row">
                      <span class="reader-recip-label" data-i18n="reader_to">${typeof t === 'function' ? t('reader_to', 'Kime:') : 'Kime:'}</span>
                      <div class="reader-recip-list">${toChips}</div>
                    </div>
                  ` : ''}
                  ${ccChips ? `
                    <div class="reader-recip-row reader-recip-row-cc">
                      <span class="reader-recip-label" data-i18n="reader_cc">${typeof t === 'function' ? t('reader_cc', 'Cc:') : 'Cc:'}</span>
                      <div class="reader-recip-list">${ccChips}</div>
                    </div>
                  ` : ''}
                  ${bccChips ? `
                    <div class="reader-recip-row reader-recip-row-bcc">
                      <span class="reader-recip-label" data-i18n="reader_bcc">${typeof t === 'function' ? t('reader_bcc', 'Bcc:') : 'Bcc:'}</span>
                      <div class="reader-recip-list">
                        <span class="reader-bcc-badge" data-i18n="reader_bcc_badge" title="Gizli Karbon Kopya">${typeof t === 'function' ? t('reader_bcc_badge', 'Gizli') : 'Gizli'}</span>
                        ${bccChips}
                      </div>
                    </div>
                  ` : ''}
                </div>
              </div>
            </div>
            <div class="reader-date-lg">${m.date}</div>
          </div>
        </div>

        <div class="reader-body-container">
          ${attsHtml}
          <div class="reader-html-content" id="reader-html-content">
          </div>
        </div>
      `;

      const contentBox = document.getElementById('reader-html-content');
      if (contentBox) {
        const cleanHtmlText = (m.body_html || '')
          .replace(new RegExp('<style[^>]*>[\\s\\S]*?<\\/style>', 'gi'), '')
          .replace(new RegExp('<script[^>]*>[\\s\\S]*?<\\/script>', 'gi'), '')
          .replace(/<[^>]+>/g, '')
          .replace(/&nbsp;/gi, '')
          .trim();
        const hasHtmlContent = m.has_html && m.body_html && (cleanHtmlText.length > 0 || m.body_html.includes('<img'));
        const hasPlainContent = m.body_plain && m.body_plain.trim().length > 0;

        if (hasHtmlContent) {
          const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
          renderEmailBodyIframe(contentBox, m.body_html, isDark);
        } else if (hasPlainContent) {
          contentBox.innerHTML = `<pre style="white-space:pre-wrap;font-family:inherit;margin:0;padding:6px 0;">${escapeHtml(m.body_plain)}</pre>`;
        } else {
          contentBox.innerHTML = `
            <div class="email-body-empty-notice" style="padding:2.5rem 1.5rem;text-align:center;color:var(--text-muted);background:var(--bg-surface-elevated);border:1px dashed var(--border-color);margin:1rem 0;">
              <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="margin:0 auto 0.65rem;opacity:0.55;display:block;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
              <div style="font-weight:600;font-size:0.95rem;color:var(--text-primary);margin-bottom:0.25rem;" data-i18n="mail_body_empty">${t('mail_body_empty', 'Bu iletinin içeriği boş.')}</div>
              <div style="font-size:0.8rem;color:var(--text-muted);" data-i18n="mail_body_empty_desc">${t('mail_body_empty_desc', 'Gönderen herhangi bir metin veya içerik eklemedi.')}</div>
            </div>
          `;
        }
      }
      updateEmailNavButtons();
    });
}

// ==========================================
// EMAIL BODY CSS ISOLATION (SANDBOXED IFRAME)
// Prevents email internal styles from bleeding/leaking into main Webmail UI
// ==========================================
function renderEmailBodyIframe(containerEl, htmlContent, isDarkTheme = false) {
  if (!containerEl) return;

  const iframe = document.createElement('iframe');
  iframe.className = 'email-body-iframe';
  iframe.setAttribute('frameborder', '0');
  iframe.setAttribute('scrolling', 'no');
  iframe.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox allow-same-origin');
  iframe.style.width = '100%';
  iframe.style.border = 'none';
  iframe.style.minHeight = '180px';
  iframe.style.background = 'transparent';
  iframe.style.overflow = 'hidden';
  iframe.style.display = 'block';

  containerEl.innerHTML = '';
  containerEl.appendChild(iframe);

  const defaultColor = isDarkTheme ? '#f1f5f9' : '#1e293b';

  const fullDocument = `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <base target="_blank">
  <style>
    html, body {
      margin: 0 !important;
      padding: 6px 4px !important;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      font-size: 14px;
      line-height: 1.6;
      color: ${defaultColor};
      background: transparent !important;
      word-break: break-word;
      overflow-wrap: break-word;
    }
    img {
      max-width: 100% !important;
      height: auto !important;
    }
    a {
      color: #3b82f6;
    }
    table {
      max-width: 100% !important;
    }
  </style>
</head>
<body>${htmlContent}</body>
</html>`;

  const adjustHeight = () => {
    try {
      const iDoc = iframe.contentDocument || iframe.contentWindow?.document;
      if (iDoc && iDoc.body) {
        const h = Math.max(
          iDoc.body.scrollHeight,
          iDoc.documentElement.scrollHeight,
          iDoc.body.offsetHeight,
          iDoc.documentElement.offsetHeight
        );
        iframe.style.height = (h + 24) + 'px';
      }
    } catch (e) {}
  };

  try {
    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(fullDocument);
    doc.close();

    iframe.onload = adjustHeight;
    setTimeout(adjustHeight, 50);
    setTimeout(adjustHeight, 250);
    setTimeout(adjustHeight, 800);
    setTimeout(adjustHeight, 2000);

    const images = doc.querySelectorAll('img');
    images.forEach(img => {
      img.addEventListener('load', adjustHeight);
    });

    if (window.ResizeObserver && doc.body) {
      const ro = new ResizeObserver(() => adjustHeight());
      ro.observe(doc.body);
    }
  } catch (err) {
    containerEl.innerHTML = isolateEmailHtmlForEditor(htmlContent);
  }
}

function isolateEmailHtmlForEditor(rawHtml) {
  if (!rawHtml || typeof rawHtml !== 'string') return '';
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(rawHtml, 'text/html');
    if (!doc || !doc.body) {
      return rawHtml
        .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/<link\b[^>]*>/gi, '')
        .replace(/<base\b[^>]*>/gi, '')
        .replace(/<meta\b[^>]*>/gi, '')
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
    }

    let bodyBgColor = doc.body.getAttribute('bgcolor') || doc.body.style.backgroundColor || '';
    let bodyTextColor = doc.body.getAttribute('text') || doc.body.style.color || '';

    const styleEls = Array.from(doc.querySelectorAll('style'));
    styleEls.forEach(styleEl => {
      const cssText = styleEl.textContent || '';
      if (cssText.trim() && typeof CSSStyleSheet !== 'undefined') {
        try {
          const sheet = new CSSStyleSheet();
          sheet.replaceSync(cssText);
          const rules = Array.from(sheet.cssRules || []);
          rules.forEach(rule => {
            if (rule.type === 1 && rule.selectorText && rule.style) {
              const selText = rule.selectorText.trim();
              if (/^(html|body|html\s*,\s*body|body\s*,\s*html)$/i.test(selText)) {
                if (!bodyBgColor && rule.style.backgroundColor) bodyBgColor = rule.style.backgroundColor;
                if (!bodyTextColor && rule.style.color) bodyTextColor = rule.style.color;
                return;
              }
              const cleanSel = selText
                .split(',')
                .map(s => s.trim())
                .filter(s => s && s !== '*' && !/^(html|body|:root)$/i.test(s) && !s.includes(':hover') && !s.includes(':focus') && !s.includes('::'))
                .map(s => s.replace(/^(html|body)\s+/i, ''))
                .filter(Boolean)
                .join(', ');

              if (cleanSel) {
                try {
                  const matched = doc.body.querySelectorAll(cleanSel);
                  matched.forEach(el => {
                    const existingInline = el.getAttribute('style') || '';
                    el.setAttribute('style', rule.style.cssText + '; ' + existingInline);
                  });
                } catch (selErr) {}
              }
            }
          });
        } catch (sheetErr) {}
      }
      styleEl.remove();
    });

    doc.querySelectorAll('style, script, link, meta, title, base, iframe, frame, frameset, object, embed, applet').forEach(el => el.remove());

    doc.body.querySelectorAll('*').forEach(el => {
      if (el.hasAttribute('style')) {
        let st = el.getAttribute('style') || '';
        st = st.replace(/position\s*:\s*(fixed|sticky)/gi, 'position: relative');
        st = st.replace(/\b100v[wh]\b/gi, '100%');
        el.setAttribute('style', st);
      }
    });

    const wrapperStyle = [
      'position:relative',
      'contain:layout paint',
      'isolation:isolate',
      'overflow-x:auto',
      'max-width:100%',
      'box-sizing:border-box',
      bodyBgColor ? `background-color:${bodyBgColor}` : '',
      bodyTextColor ? `color:${bodyTextColor}` : ''
    ].filter(Boolean).join(';');

    return `<div class="mail-isolated-quote" style="${wrapperStyle}">${doc.body.innerHTML}</div>`;
  } catch (e) {
    return rawHtml
      .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
      .replace(/<link\b[^>]*>/gi, '')
      .replace(/<base\b[^>]*>/gi, '')
      .replace(/<meta\b[^>]*>/gi, '')
      .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  }
}

function toggleStar(uid, event, folder = null) {
  if (event) event.stopPropagation();
  const targetFolder = folder || currentFolder;

  // 1. Liste öğesindeki yıldız butonunu anında güncelle
  const starBtn = document.querySelector(`#mail-item-${uid} .star-btn`);
  if (starBtn) {
    const isNowStarred = !starBtn.classList.contains('starred');
    starBtn.classList.toggle('starred', isNowStarred);
    starBtn.title = isNowStarred ? 'Yıldızı Kaldır' : 'Yıldızla';
    const svg = starBtn.querySelector('svg');
    if (svg) {
      svg.setAttribute('fill', isNowStarred ? '#fbbf24' : 'none');
      svg.setAttribute('stroke', isNowStarred ? '#fbbf24' : 'currentColor');
    }
    if (cachedMessages[uid]) {
      cachedMessages[uid].is_flagged = isNowStarred;
    }
  }

  // 2. Açık olan okuma panelindeki yıldız butonunu anında güncelle
  const readerStarBtn = document.getElementById('reader-star-btn');
  if (readerStarBtn && currentUid === uid) {
    const isNowStarred = !readerStarBtn.classList.contains('starred');
    readerStarBtn.classList.toggle('starred', isNowStarred);
    const starLabel = readerStarBtn.querySelector('span');
    if (starLabel) starLabel.textContent = isNowStarred ? 'Yıldızlı' : 'Yıldızla';
    const svg = readerStarBtn.querySelector('svg');
    if (svg) {
      svg.setAttribute('fill', isNowStarred ? '#fbbf24' : 'none');
      svg.setAttribute('stroke', isNowStarred ? '#fbbf24' : 'currentColor');
    }
    readerStarBtn.setAttribute('title', isNowStarred ? 'Yıldızı Kaldır' : 'Yıldızla');
  }

  const formData = new FormData();
  formData.append('action', 'mark_status');
  formData.append('folder', targetFolder);
  formData.append('uid', uid);
  formData.append('type', 'flagged');

  // Listeyi yeniden yüklemeden arka planda kaydet
  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .catch(() => {});
}

// ==========================================
// SMOOTH EMAIL REMOVAL & AUTO-ADVANCE TO NEXT EMAIL
// ==========================================
function removeMailItemFromList(uid, autoAdvance = true) {
  const item = document.getElementById('mail-item-' + uid);
  const wasUnread = item ? item.classList.contains('unread') : false;
  delete cachedMessages[uid];
  selectedMailUids.delete(uid);
  if (typeof updateBulkToolbarUI === 'function') updateBulkToolbarUI();

  // Aktif okunan posta mı kontrol et
  const activeId = currentUid || currentEmailUid;
  const wasActive = (activeId && (activeId == uid || String(activeId) === String(uid)));

  let nextTargetItem = null;
  if (wasActive && autoAdvance) {
    const domItems = Array.from(document.querySelectorAll('.mail-item'));
    const currentIndex = domItems.findIndex(el => el.id === ('mail-item-' + uid));

    if (currentIndex !== -1) {
      // 1. Önce silinen e-postanın bir altındaki (sonraki) e-postayı bul
      for (let i = currentIndex + 1; i < domItems.length; i++) {
        if (domItems[i].style.display !== 'none' && !domItems[i].classList.contains('is-deleting') && domItems[i].id !== ('mail-item-' + uid)) {
          nextTargetItem = domItems[i];
          break;
        }
      }
      // 2. Eğer listenin sonundaysa, bir üstteki (önceki) e-postayı bul
      if (!nextTargetItem) {
        for (let i = currentIndex - 1; i >= 0; i--) {
          if (domItems[i].style.display !== 'none' && !domItems[i].classList.contains('is-deleting') && domItems[i].id !== ('mail-item-' + uid)) {
            nextTargetItem = domItems[i];
            break;
          }
        }
      }
    }
  }

  if (item) {
    item.classList.add('is-deleting');
    item.style.transition = 'all 0.25s cubic-bezier(0.4, 0, 0.2, 1)';
    item.style.opacity = '0';
    item.style.transform = 'translateX(-25px)';
    item.style.maxHeight = item.offsetHeight + 'px';
    setTimeout(() => {
      item.style.maxHeight = '0px';
      item.style.paddingTop = '0px';
      item.style.paddingBottom = '0px';
      item.style.marginTop = '0px';
      item.style.marginBottom = '0px';
      item.style.border = 'none';
      item.style.overflow = 'hidden';
      setTimeout(() => {
        item.remove();
        checkIfMailListEmpty();
      }, 250);
    }, 50);
  } else {
    checkIfMailListEmpty();
  }

  // Okuma panelinde açık olan posta silindiyse otomatik sonraki e-postaya geç
  if (wasActive) {
    if (autoAdvance && nextTargetItem) {
      setTimeout(() => {
        if (nextTargetItem && document.body.contains(nextTargetItem)) {
          nextTargetItem.click();
          nextTargetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }, 50);
    } else if (!autoAdvance || !nextTargetItem) {
      currentUid = null;
      currentEmailUid = null;
      window.currentUid = null;
      window.currentEmailUid = null;
      const reader = document.getElementById('mail-reader-pane');
      if (reader) {
        reader.innerHTML = `
          <div class="reader-empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <p>${(typeof t === 'function' ? t('select_email_to_read', 'Okumak için listeden bir e-posta seçin') : 'Okumak için listeden bir e-posta seçin')}</p>
          </div>
        `;
      }
    }
  }

  decrementFolderCount(currentFolder, 1, wasUnread);
}

function decrementFolderCount(folderName, amount = 1, decrementUnseen = false) {
  if (cachedFolderCounts && cachedFolderCounts[folderName]) {
    cachedFolderCounts[folderName].total = Math.max(0, (cachedFolderCounts[folderName].total || 0) - amount);
    if (decrementUnseen && cachedFolderCounts[folderName].unseen > 0) {
      cachedFolderCounts[folderName].unseen = Math.max(0, cachedFolderCounts[folderName].unseen - 1);
    }
    cachedFolderCounts[folderName].formatted = cachedFolderCounts[folderName].total.toLocaleString('tr-TR');
    applyFolderCounts(cachedFolderCounts);
  } else {
    const badge = document.getElementById('folder-count-' + folderName);
    if (badge && badge.textContent) {
      const rawNum = parseInt(badge.textContent.replace(/\D/g, ''), 10);
      if (!isNaN(rawNum) && rawNum > 0) {
        const nextNum = Math.max(0, rawNum - amount);
        badge.textContent = nextNum.toLocaleString('tr-TR');
        if (nextNum === 0) badge.style.display = 'none';
      }
    }
  }
}

function checkIfMailListEmpty() {
  const container = document.getElementById('mail-list-container');
  if (!container) return;
  const remaining = container.querySelectorAll('.mail-item:not(.is-deleting)');
  if (remaining.length === 0) {
    container.innerHTML = `
      <div style="padding:2.5rem 1.5rem;text-align:center;color:var(--text-muted);">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.6rem;opacity:0.4;"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
        <p style="font-size:0.825rem;font-weight:600;margin-bottom:0.25rem;">Bu klasörde e-posta bulunmuyor.</p>
      </div>
    `;
  }
}

function deleteEmail(uid, folder = null) {
  const targetFolder = folder || currentFolder;

  // Anında listeden aradan çıkar ve açık e-postaysa sonraki e-postaya otomatik geç (Hızlı & Akıcı)
  removeMailItemFromList(uid, true);

  const formData = new FormData();
  formData.append('action', 'delete_email');
  formData.append('folder', targetFolder);
  formData.append('uid', uid);

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        showToast(data.message || 'E-posta silindi.', 'success');
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast((data && data.message) || 'E-posta silinemedi.', 'error');
        if (typeof loadMessages === 'function') loadMessages(false);
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
      if (typeof loadMessages === 'function') loadMessages(false);
    });
}

function undoDeleteEmail(uid) {
  const p = pendingDeletes[uid];
  if (!p) return;

  clearTimeout(p.timer);

  // Restore DOM item in mail list
  if (p.element) {
    p.element.style.display = 'flex';
    setTimeout(() => {
      p.element.style.opacity = '1';
      p.element.style.transform = 'none';
    }, 20);
  }

  delete pendingDeletes[uid];
  showToast('Silme işlemi geri alındı.', 'success');

  // Re-open in reader pane
  viewEmail(uid);
}

// ==========================================
// RESIZABLE READING PANE ENGINE
// ==========================================
let isWideReaderActive = false;
let preWidePaneWidth = null;

function initPaneResizer() {
  const resizer = document.getElementById('mail-pane-resizer');
  const listPane = document.getElementById('mail-list-pane');
  const workspace = document.getElementById('view-mail');
  if (!resizer || !listPane || !workspace) return;

  // Restore saved width from localStorage
  try {
    const savedWidth = localStorage.getItem('webmail_list_pane_width');
    if (savedWidth) {
      const parsed = parseInt(savedWidth, 10);
      if (!isNaN(parsed) && parsed >= 260 && parsed <= 900) {
        listPane.style.width = parsed + 'px';
      }
    }
  } catch (e) {}

  let isDragging = false;
  let startX = 0;
  let startWidth = 0;

  function onMouseDown(e) {
    if (e.button !== 0 && e.type !== 'touchstart') return;
    if (isWideReaderActive) return;
    isDragging = true;
    startX = (e.type === 'touchstart') ? e.touches[0].clientX : e.clientX;
    startWidth = listPane.getBoundingClientRect().width;

    resizer.classList.add('is-resizing');
    document.body.classList.add('is-resizing-pane');

    window.addEventListener('mousemove', onMouseMove, { passive: false });
    window.addEventListener('mouseup', onMouseUp);
    window.addEventListener('touchmove', onMouseMove, { passive: false });
    window.addEventListener('touchend', onMouseUp);
  }

  function onMouseMove(e) {
    if (!isDragging) return;
    const clientX = (e.type === 'touchmove') ? e.touches[0].clientX : e.clientX;
    const deltaX = clientX - startX;
    let newWidth = startWidth + deltaX;

    const workspaceWidth = workspace.getBoundingClientRect().width;
    const minWidth = 260;
    const maxWidth = Math.max(minWidth, workspaceWidth - 320);

    if (newWidth < minWidth) newWidth = minWidth;
    if (newWidth > maxWidth) newWidth = maxWidth;

    listPane.style.width = Math.round(newWidth) + 'px';
    if (e.cancelable) e.preventDefault();
  }

  function onMouseUp() {
    if (!isDragging) return;
    isDragging = false;
    resizer.classList.remove('is-resizing');
    document.body.classList.remove('is-resizing-pane');

    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    window.removeEventListener('touchmove', onMouseMove);
    window.removeEventListener('touchend', onMouseUp);

    const currentWidth = Math.round(listPane.getBoundingClientRect().width);
    try {
      localStorage.setItem('webmail_list_pane_width', currentWidth);
    } catch (e) {}
  }

  resizer.addEventListener('mousedown', onMouseDown);
  resizer.addEventListener('touchstart', onMouseDown, { passive: true });

  resizer.addEventListener('dblclick', () => {
    listPane.style.width = '440px';
    try {
      localStorage.removeItem('webmail_list_pane_width');
    } catch (e) {}
    showToast('Bölme genişliği varsayılana sıfırlandı (440px).', 'info');
  });
}

function toggleWideReadingPane() {
  const listPane = document.getElementById('mail-list-pane');
  const resizer = document.getElementById('mail-pane-resizer');
  const btn = document.getElementById('btn-toggle-wide-reader');
  if (!listPane) return;

  isWideReaderActive = !isWideReaderActive;
  if (isWideReaderActive) {
    preWidePaneWidth = listPane.style.width || (listPane.getBoundingClientRect().width + 'px');
    listPane.style.display = 'none';
    if (resizer) resizer.style.display = 'none';
    if (btn) {
      btn.title = 'Standart Okuma Alanı (Listeyi Göster)';
      btn.classList.add('active');
      btn.innerHTML = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg><span>Daralt</span>`;
    }
  } else {
    listPane.style.display = 'flex';
    if (resizer) resizer.style.display = 'block';
    if (preWidePaneWidth) listPane.style.width = preWidePaneWidth;
    if (btn) {
      btn.title = 'Tam / Geniş Okuma Alanı (Listeyi Gizle)';
      btn.classList.remove('active');
      btn.innerHTML = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg><span>Genişlet</span>`;
    }
  }
}

// ==========================================
// USER TASKS ENGINE (YAPILACAKLAR)
// ==========================================
let cachedTasks = [];
let currentTaskFilter = 'all';

function loadTasks() {
  fetch('api.php?action=get_tasks')
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success && Array.isArray(data.tasks)) {
        cachedTasks = data.tasks;
        renderTasksList();
      } else {
        const box = document.getElementById('tasks-list-box');
        if (box) {
          box.innerHTML = `
            <div style="padding:2.5rem 1rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">
              Görevler yüklenemedi: ${escapeHtml((data && data.message) || '')}
            </div>
          `;
        }
      }
    })
    .catch(err => {
      const box = document.getElementById('tasks-list-box');
      if (box) {
        box.innerHTML = `
          <div style="padding:2.5rem 1rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">
            Sunucuya bağlanırken bir hata oluştu: ${escapeHtml(err.message || '')}
          </div>
        `;
      }
    });
}

function renderTasksList() {
  const box = document.getElementById('tasks-list-box');
  if (!box) return;

  const total = cachedTasks.length;
  const pending = cachedTasks.filter(t => !t.is_completed || t.is_completed == 0).length;
  const completed = total - pending;

  const cntAll = document.getElementById('task-cnt-all');
  const cntPen = document.getElementById('task-cnt-pending');
  const cntCom = document.getElementById('task-cnt-completed');
  const statText = document.getElementById('task-stat-text');

  if (cntAll) cntAll.textContent = total;
  if (cntPen) cntPen.textContent = pending;
  if (cntCom) cntCom.textContent = completed;
  if (statText) statText.textContent = `${total} görev (${pending} devam eden, ${completed} tamamlanan)`;

  let list = cachedTasks;
  if (currentTaskFilter === 'pending') {
    list = cachedTasks.filter(t => !t.is_completed || t.is_completed == 0);
  } else if (currentTaskFilter === 'completed') {
    list = cachedTasks.filter(t => t.is_completed && t.is_completed == 1);
  }

  if (list.length === 0) {
    box.innerHTML = `
      <div style="padding:3rem 1rem;text-align:center;color:var(--text-muted);background:var(--bg-surface);border:1px solid var(--border-color);border-radius:0 !important;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.4;"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <p style="font-size:0.9rem;font-weight:600;color:var(--text-primary);margin-bottom:0.25rem;">
          ${currentTaskFilter === 'completed' ? 'Tamamlanan görev bulunmuyor.' : (currentTaskFilter === 'pending' ? 'Tebrikler! Devam eden bir göreviniz yok.' : 'Henüz hiç görev eklenmedi.')}
        </p>
        <p style="font-size:0.8rem;color:var(--text-muted);">Yukarıdaki alandan hızlıca yeni görev ekleyebilirsiniz.</p>
      </div>
    `;
    return;
  }

  const todayStr = new Date().toISOString().substring(0, 10);

  box.innerHTML = list.map(t => {
    const isDone = (t.is_completed == 1);
    let pClass = 'task-priority-medium';
    let pLabel = 'Orta';
    if (t.priority === 'high') { pClass = 'task-priority-high'; pLabel = 'Yüksek'; }
    else if (t.priority === 'low') { pClass = 'task-priority-low'; pLabel = 'Düşük'; }

    let dateBadge = '';
    if (t.due_date) {
      const isOverdue = !isDone && (t.due_date < todayStr);
      dateBadge = `
        <span class="task-badge-date ${isOverdue ? 'overdue' : ''}" title="${isOverdue ? 'Süresi Geçti!' : 'Son Tarih'}">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          ${escapeHtml(t.due_date)}
        </span>
      `;
    }

    return `
      <div class="task-item ${isDone ? 'completed' : ''}" id="task-row-${t.id}">
        <input type="checkbox" class="task-checkbox" ${isDone ? 'checked' : ''} onchange="toggleTaskCompleted(${t.id}, this)" title="${isDone ? 'Tamamlanmadı olarak işaretle' : 'Tamamlandı olarak işaretle'}">
        <div class="task-item-content">
          <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <span class="task-title">${escapeHtml(t.title)}</span>
            <span class="task-badge-priority ${pClass}">${pLabel}</span>
            ${dateBadge}
          </div>
          ${t.description ? `<div class="task-desc">${escapeHtml(t.description)}</div>` : ''}
        </div>
        <div class="task-actions">
          <button type="button" class="btn-icon" title="Düzenle" onclick="openTaskModal(${t.id})">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
          </button>
          <button type="button" class="btn-icon" title="Sil" onclick="deleteTaskById(${t.id})" onmouseover="this.style.color='var(--danger)';" onmouseout="this.style.color='var(--text-muted)';">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function filterTasks(status, btn) {
  currentTaskFilter = status;
  document.querySelectorAll('.task-filter-chip').forEach(c => c.classList.remove('active'));
  if (btn) btn.classList.add('active');
  renderTasksList();
}

function handleQuickAddTask(e) {
  e.preventDefault();
  const titleInput = document.getElementById('quick-task-title');
  const prioSelect = document.getElementById('quick-task-priority');
  const dateInput = document.getElementById('quick-task-date');

  const title = titleInput.value.trim();
  if (!title) return;

  const fd = new FormData();
  fd.append('action', 'save_task');
  fd.append('title', title);
  fd.append('priority', prioSelect.value);
  if (dateInput.value) fd.append('due_date', dateInput.value);

  fetch('api.php?action=save_task', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        titleInput.value = '';
        dateInput.value = '';
        showToast(data.message || 'Görev eklendi.', 'success');
        loadTasks();
      } else {
        showToast((data && data.message) || 'Görev eklenemedi.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function openTaskModal(taskId = 0) {
  const modal = document.getElementById('task-modal');
  const idInput = document.getElementById('task-modal-id');
  const titleInput = document.getElementById('task-modal-title-input');
  const descInput = document.getElementById('task-modal-desc-input');
  const prioInput = document.getElementById('task-modal-priority-input');
  const dueInput = document.getElementById('task-modal-due-input');
  const mTitle = document.getElementById('task-modal-title');

  if (taskId > 0) {
    const t = cachedTasks.find(item => item.id == taskId);
    if (!t) return;
    idInput.value = t.id;
    titleInput.value = t.title || '';
    descInput.value = t.description || '';
    prioInput.value = t.priority || 'medium';
    dueInput.value = t.due_date || '';
    mTitle.textContent = 'Görevi Düzenle';
  } else {
    idInput.value = '0';
    titleInput.value = '';
    descInput.value = '';
    prioInput.value = 'medium';
    dueInput.value = '';
    mTitle.textContent = 'Yeni Görev Ekle';
  }

  modal.style.display = 'flex';
  setTimeout(() => titleInput.focus(), 50);
}

function closeTaskModal() {
  document.getElementById('task-modal').style.display = 'none';
}

function handleSaveTask(e) {
  e.preventDefault();
  const id = document.getElementById('task-modal-id').value;
  const title = document.getElementById('task-modal-title-input').value.trim();
  const desc = document.getElementById('task-modal-desc-input').value.trim();
  const prio = document.getElementById('task-modal-priority-input').value;
  const due = document.getElementById('task-modal-due-input').value;

  if (!title) {
    showToast('Lütfen görev başlığı girin.', 'error');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'save_task');
  fd.append('id', id);
  fd.append('title', title);
  fd.append('description', desc);
  fd.append('priority', prio);
  if (due) fd.append('due_date', due);

  fetch('api.php?action=save_task', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        closeTaskModal();
        showToast(data.message || 'Görev kaydedildi.', 'success');
        loadTasks();
      } else {
        showToast((data && data.message) || 'Hata oluştu.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function toggleTaskCompleted(taskId, checkbox) {
  const fd = new FormData();
  fd.append('action', 'toggle_task');
  fd.append('id', taskId);

  fetch('api.php?action=toggle_task', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        const item = cachedTasks.find(t => t.id == taskId);
        if (item) item.is_completed = data.is_completed;
        renderTasksList();
        showToast(data.message || 'Görev durumu güncellendi.', 'success');
      } else {
        checkbox.checked = !checkbox.checked;
        showToast((data && data.message) || 'İşlem başarısız.', 'error');
      }
    })
    .catch(() => {
      checkbox.checked = !checkbox.checked;
    });
}

function deleteTaskById(taskId) {
  swalConfirm('Görevi Sil', 'Bu görevi silmek istediğinize emin misiniz?', 'Sil').then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'delete_task');
      fd.append('id', taskId);

      fetch('api.php?action=delete_task', { method: 'POST', body: fd })
        .then(parseApiResponseJson)
        .then(data => {
          if (data && data.success) {
            cachedTasks = cachedTasks.filter(t => t.id != taskId);
            renderTasksList();
            showToast(data.message || 'Görev silindi.', 'success');
          } else {
            showToast((data && data.message) || 'Görev silinemedi.', 'error');
          }
        })
        .catch(err => {
          showToast('Bağlantı hatası: ' + err.message, 'error');
        });
    }
  });
}

// ==========================================
// USER NOTES ENGINE (NOTLAR)
// ==========================================
let cachedNotes = [];
let currentNoteSearch = '';

function loadNotes() {
  fetch('api.php?action=get_notes')
    .then(r => r.json())
    .then(data => {
      if (data.success && Array.isArray(data.notes)) {
        cachedNotes = data.notes;
        renderNotesGrid();
      } else {
        const box = document.getElementById('notes-grid-box');
        if (box) {
          box.innerHTML = `
            <div style="grid-column:1/-1;padding:2.5rem 1rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">
              Notlar yüklenemedi: ${escapeHtml(data.message || '')}
            </div>
          `;
        }
      }
    })
    .catch(() => {
      const box = document.getElementById('notes-grid-box');
      if (box) {
        box.innerHTML = `
          <div style="grid-column:1/-1;padding:2.5rem 1rem;text-align:center;color:var(--text-muted);font-size:0.875rem;">
            Sunucuya bağlanırken bir hata oluştu.
          </div>
        `;
      }
    });
}

function renderNotesGrid() {
  const box = document.getElementById('notes-grid-box');
  if (!box) return;

  let list = cachedNotes;
  if (currentNoteSearch) {
    const q = currentNoteSearch.toLowerCase();
    list = cachedNotes.filter(n => (n.title || '').toLowerCase().includes(q) || (n.content || '').toLowerCase().includes(q));
  }

  const sub = document.getElementById('notes-header-subtitle');
  if (sub) sub.textContent = `${cachedNotes.length} kişisel not kayıtlı`;

  if (list.length === 0) {
    box.innerHTML = `
      <div style="grid-column:1/-1;padding:3.5rem 1rem;text-align:center;color:var(--text-muted);background:var(--bg-surface);border:1px solid var(--border-color);border-radius:0 !important;">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.4;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <p style="font-size:0.95rem;font-weight:600;color:var(--text-primary);margin-bottom:0.25rem;">
          ${currentNoteSearch ? 'Aramanızla eşleşen not bulunamadı.' : 'Henüz hiç not eklemediniz.'}
        </p>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;">Toplantı notları, şifreler, yapılacaklar veya fikirlerinizi kaydedin.</p>
        <button type="button" class="btn-send" onclick="openNoteModal()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1rem;font-size:0.825rem;border-radius:0 !important;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span>İlk Notunuzu Ekleyin</span>
        </button>
      </div>
    `;
    return;
  }

  box.innerHTML = list.map(n => {
    const isPinned = (n.is_pinned == 1);
    const nColor = n.color || '#4f46e5';
    const dateFormatted = n.updated_at ? n.updated_at.substring(0, 16).replace('T', ' ') : '';

    return `
      <div class="note-card ${isPinned ? 'pinned' : ''}" style="--note-color:${escapeHtml(nColor)};" id="note-card-${n.id}">
        <div class="note-card-header">
          <div class="note-card-title">${escapeHtml(n.title)}</div>
          <button type="button" class="note-pin-btn ${isPinned ? 'pinned' : ''}" title="${isPinned ? 'Sabitlemeyi kaldır' : 'Başa sabitle'}" onclick="togglePinNote(${n.id}, event)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="${isPinned ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          </button>
        </div>
        <div class="note-card-content" onclick="openNoteModal(${n.id})" style="cursor:pointer;" title="Notu düzenlemek için tıklayın">${escapeHtml(n.content || '')}</div>
        <div class="note-card-footer">
          <span>${dateFormatted}</span>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <button type="button" class="btn-icon" title="Düzenle" onclick="openNoteModal(${n.id})">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
            </button>
            <button type="button" class="btn-icon" title="Sil" onclick="deleteNoteById(${n.id})" onmouseover="this.style.color='var(--danger)';" onmouseout="this.style.color='var(--text-muted)';">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function handleNotesSearch(val) {
  currentNoteSearch = (val || '').trim();
  renderNotesGrid();
}

function updateNoteColorSelection(color) {
  const colorInput = document.getElementById('note-modal-color-val');
  if (colorInput) colorInput.value = color;
  const dots = document.querySelectorAll('#note-color-picker .color-dot');
  dots.forEach(dot => {
    const parentRadio = dot.previousElementSibling;
    if (parentRadio && parentRadio.value === color) {
      dot.style.outline = '2px solid ' + color;
      dot.style.border = '2px solid #ffffff';
    } else {
      dot.style.outline = 'none';
      dot.style.border = '2px solid transparent';
    }
  });
}

function openNoteModal(noteId = 0) {
  const modal = document.getElementById('note-modal');
  const idInput = document.getElementById('note-modal-id');
  const titleInput = document.getElementById('note-modal-title-input');
  const contentInput = document.getElementById('note-modal-content-input');
  const pinInput = document.getElementById('note-modal-pin');
  const mTitle = document.getElementById('note-modal-title');

  if (noteId > 0) {
    const n = cachedNotes.find(item => item.id == noteId);
    if (!n) return;
    idInput.value = n.id;
    titleInput.value = n.title || '';
    contentInput.value = n.content || '';
    pinInput.checked = (n.is_pinned == 1);
    updateNoteColorSelection(n.color || '#4f46e5');
    mTitle.textContent = 'Notu Düzenle';
  } else {
    idInput.value = '0';
    titleInput.value = '';
    contentInput.value = '';
    pinInput.checked = false;
    updateNoteColorSelection('#4f46e5');
    mTitle.textContent = 'Yeni Not Ekle';
  }

  modal.style.display = 'flex';
  setTimeout(() => titleInput.focus(), 50);
}

function closeNoteModal() {
  document.getElementById('note-modal').style.display = 'none';
}

function handleSaveNote(e) {
  e.preventDefault();
  const id = document.getElementById('note-modal-id').value;
  const title = document.getElementById('note-modal-title-input').value.trim();
  const content = document.getElementById('note-modal-content-input').value.trim();
  const color = document.getElementById('note-modal-color-val').value;
  const isPinned = document.getElementById('note-modal-pin').checked ? 1 : 0;

  if (!title) {
    showToast('Lütfen not başlığı girin.', 'error');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'save_note');
  fd.append('id', id);
  fd.append('title', title);
  fd.append('content', content);
  fd.append('color', color);
  fd.append('is_pinned', isPinned);

  fetch('api.php?action=save_note', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        closeNoteModal();
        showToast(data.message || 'Not kaydedildi.', 'success');
        loadNotes();
      } else {
        showToast((data && data.message) || 'Not kaydedilemedi.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function togglePinNote(noteId, e) {
  if (e) e.stopPropagation();
  const fd = new FormData();
  fd.append('action', 'toggle_pin_note');
  fd.append('id', noteId);

  fetch('api.php?action=toggle_pin_note', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(res => {
      if (res && res.success) {
        if (res.message) showToast(res.message, 'success');
        loadNotes();
      } else {
        showToast((res && res.message) || 'Sabitleme durumu güncellenemedi.', 'error');
      }
    })
    .catch(err => console.error('Pin note error:', err));
}

function deleteNoteById(noteId) {
  swalConfirm('Notu Sil', 'Bu notu silmek istediğinize emin misiniz?', 'Sil').then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'delete_note');
      fd.append('id', noteId);

      fetch('api.php?action=delete_note', { method: 'POST', body: fd })
        .then(parseApiResponseJson)
        .then(data => {
          if (data && data.success) {
            cachedNotes = cachedNotes.filter(n => n.id != noteId);
            renderNotesGrid();
            showToast(data.message || 'Not silindi.', 'success');
          } else {
            showToast((data && data.message) || 'Not silinemedi.', 'error');
          }
        })
        .catch(err => showToast('Bağlantı hatası: ' + err.message, 'error'));
    }
  });
}

let currentDriveFolderId = 0;
let currentDriveSearch = '';
let cachedDriveData = null;
let driveDropzoneInitialized = false;
let currentDriveViewMode = localStorage.getItem('disk_view_mode') || 'grid';
let selectedDriveFolders = new Set();
let selectedDriveFiles = new Set();

function setDiskViewMode(mode) {
  currentDriveViewMode = mode;
  localStorage.setItem('disk_view_mode', mode);
  const gridBtn = document.getElementById('disk-view-grid-btn');
  const listBtn = document.getElementById('disk-view-list-btn');
  if (gridBtn) gridBtn.classList.toggle('active', mode === 'grid');
  if (listBtn) listBtn.classList.toggle('active', mode === 'list');
  if (cachedDriveData) {
    renderDriveView(cachedDriveData);
  }
}

function updateDiskBulkBar() {
  const total = selectedDriveFolders.size + selectedDriveFiles.size;
  const bar = document.getElementById('disk-bulk-bar');
  const countSpan = document.getElementById('disk-bulk-count');
  const selectAllCb = document.getElementById('disk-select-all-cb');
  const headerCb = document.getElementById('disk-list-header-cb');

  if (bar) {
    bar.style.display = total > 0 ? 'flex' : 'none';
  }
  if (countSpan) {
    countSpan.textContent = `${total} öğe seçildi`;
  }

  const allItemsCount = (cachedDriveData?.folders?.length || 0) + (cachedDriveData?.files?.length || 0);
  const isAll = (allItemsCount > 0 && total === allItemsCount);
  if (selectAllCb) selectAllCb.checked = isAll;
  if (headerCb) headerCb.checked = isAll;
}

function toggleDiskFolderSelection(fId, checked) {
  if (checked) selectedDriveFolders.add(Number(fId));
  else selectedDriveFolders.delete(Number(fId));
  
  const card = document.getElementById('drive-folder-card-' + fId);
  if (card) card.classList.toggle('selected', checked);
  const row = document.getElementById('disk-list-row-f-' + fId);
  if (row) row.classList.toggle('selected', checked);
  
  updateDiskBulkBar();
}

function toggleDiskFileSelection(fileId, checked) {
  if (checked) selectedDriveFiles.add(Number(fileId));
  else selectedDriveFiles.delete(Number(fileId));
  
  const card = document.getElementById('drive-file-' + fileId);
  if (card) card.classList.toggle('selected', checked);
  const row = document.getElementById('disk-list-row-file-' + fileId);
  if (row) row.classList.toggle('selected', checked);
  
  updateDiskBulkBar();
}

function toggleSelectAllDisk(checked) {
  const folders = cachedDriveData?.folders || [];
  const files = cachedDriveData?.files || [];

  if (checked) {
    folders.forEach(f => selectedDriveFolders.add(Number(f.id)));
    files.forEach(f => selectedDriveFiles.add(Number(f.id)));
  } else {
    selectedDriveFolders.clear();
    selectedDriveFiles.clear();
  }

  document.querySelectorAll('.disk-folder-cb').forEach(cb => cb.checked = checked);
  document.querySelectorAll('.disk-file-cb').forEach(cb => cb.checked = checked);
  document.querySelectorAll('.drive-folder-card').forEach(el => el.classList.toggle('selected', checked));
  document.querySelectorAll('.drive-file-card').forEach(el => el.classList.toggle('selected', checked));
  document.querySelectorAll('.disk-list-row').forEach(el => el.classList.toggle('selected', checked));

  updateDiskBulkBar();
}

function clearDiskSelection() {
  selectedDriveFolders.clear();
  selectedDriveFiles.clear();
  document.querySelectorAll('.disk-folder-cb').forEach(cb => cb.checked = false);
  document.querySelectorAll('.disk-file-cb').forEach(cb => cb.checked = false);
  document.querySelectorAll('.drive-folder-card').forEach(el => el.classList.remove('selected'));
  document.querySelectorAll('.drive-file-card').forEach(el => el.classList.remove('selected'));
  document.querySelectorAll('.disk-list-row').forEach(el => el.classList.remove('selected'));
  updateDiskBulkBar();
}

function executeDiskBulkDelete() {
  const total = selectedDriveFolders.size + selectedDriveFiles.size;
  if (total === 0) {
    showToast('Silmek için lütfen en az bir öğe seçin.', 'info');
    return;
  }

  swalConfirm(
    'Seçilenleri Sil',
    `Seçilen ${total} öğeyi kalıcı olarak silmek istediğinize emin misiniz? (Klasörler içerikleriyle birlikte silinecektir.)`,
    'Evet, Sil',
    'warning'
  ).then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'drive_bulk_delete');
      fd.append('folder_ids', Array.from(selectedDriveFolders).join(','));
      fd.append('file_ids', Array.from(selectedDriveFiles).join(','));

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || 'Öğeler silindi.', 'success');
            clearDiskSelection();
            loadDrive(currentDriveFolderId);
          } else {
            showToast(data.message || 'Silinemedi.', 'error');
          }
        })
        .catch(err => showToast('Hata: ' + err.message, 'error'));
    }
  });
}

function loadDrive(folderId = currentDriveFolderId, search = currentDriveSearch) {
  currentDriveFolderId = folderId;
  currentDriveSearch = search;

  initDriveDropzone();

  const url = `api.php?action=drive_list&folder_id=${folderId}&search=${encodeURIComponent(search)}`;
  fetch(url)
    .then(async r => {
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error(text.trim().substring(0, 120) || `Sunucu boş yanıt döndürdü (HTTP ${r.status})`);
      }
    })
    .then(data => {
      if (data.success) {
        cachedDriveData = data;
        renderDriveView(data);
      } else {
        showToast(data.message || 'Disk verileri alınamadı.', 'error');
      }
    })
    .catch(err => {
      showToast('Sunucu bağlantı hatası: ' + err.message, 'error');
    });
}

function initDriveDropzone() {
  if (driveDropzoneInitialized) return;
  const dropzone = document.getElementById('drive-dropzone');
  if (!dropzone) return;

  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, e => {
      e.preventDefault();
      e.stopPropagation();
    }, false);
  });

  ['dragenter', 'dragover'].forEach(eventName => {
    dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'), false);
  });

  ['dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'), false);
  });

  dropzone.addEventListener('drop', e => {
    const dt = e.dataTransfer;
    if (dt && dt.files && dt.files.length > 0) {
      uploadDriveFiles(dt.files);
    }
  });

  driveDropzoneInitialized = true;
}

function renderDriveView(data) {
  // Update view mode switcher buttons
  const gridBtn = document.getElementById('disk-view-grid-btn');
  const listBtn = document.getElementById('disk-view-list-btn');
  if (gridBtn) gridBtn.classList.toggle('active', currentDriveViewMode === 'grid');
  if (listBtn) listBtn.classList.toggle('active', currentDriveViewMode === 'list');

  // 1. Breadcrumbs
  const bBox = document.getElementById('drive-breadcrumbs');
  if (bBox && Array.isArray(data.breadcrumbs)) {
    const isRoot = (data.breadcrumbs.length <= 1);
    const parentFolder = !isRoot ? data.breadcrumbs[data.breadcrumbs.length - 2] : null;
    const parentId = parentFolder ? parentFolder.id : 0;

    const homeTitle = (typeof t === 'function' ? t('drive_home_btn') : 'Ana Dizin (Disk)') || 'Ana Dizin (Disk)';
    const backTitle = (typeof t === 'function' ? t('drive_back_btn') : 'Üst Klasöre Çık') || 'Üst Klasöre Çık';

    const navButtonsHtml = `
      <div class="drive-nav-buttons">
        <button type="button" class="drive-nav-btn" onclick="navigateToDriveFolder(0)" ondragover="handleDriveDragOver(event)" ondragleave="handleDriveDragLeave(event)" ondrop="handleDriveDropOnFolder(event, 0)" title="${escapeHtml(homeTitle)}" data-i18n-title="drive_home_btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </button>
        <button type="button" class="drive-nav-btn ${isRoot ? 'disabled' : ''}" ${isRoot ? 'disabled' : `onclick="navigateToDriveFolder(${parentId})" ondragover="handleDriveDragOver(event)" ondragleave="handleDriveDragLeave(event)" ondrop="handleDriveDropOnFolder(event, ${parentId})"`} title="${escapeHtml(backTitle)}" data-i18n-title="drive_back_btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
      </div>
      <span class="drive-nav-divider"></span>
    `;

    const crumbsHtml = data.breadcrumbs.map((b, idx) => {
      const isLast = (idx === data.breadcrumbs.length - 1);
      const icon = (idx === 0) 
        ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>`
        : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>`;
      
      const itemHtml = isLast 
        ? `<span class="drive-crumb-item current">${icon}${escapeHtml(b.name)}</span>`
        : `<a href="javascript:void(0)" class="drive-crumb-item" onclick="navigateToDriveFolder(${b.id})" ondragover="handleDriveDragOver(event)" ondragleave="handleDriveDragLeave(event)" ondrop="handleDriveDropOnFolder(event, ${b.id})" title="Bu klasöre taşımak için buraya bırakın">${icon}${escapeHtml(b.name)}</a>`;
      
      return (idx > 0) ? `<span class="drive-crumb-separator">/</span>${itemHtml}` : itemHtml;
    }).join('');

    bBox.innerHTML = navButtonsHtml + crumbsHtml;
  }

  // 2. Storage text
  const stText = document.getElementById('drive-storage-text');
  if (stText && data.storage) {
    stText.textContent = `${data.storage.formatted} (${data.storage.total_files} dosya)`;
  }

  const folders = data.folders || [];
  const files = data.files || [];
  const listContainer = document.getElementById('disk-list-view-container');
  const listTbody = document.getElementById('disk-list-table-body');
  const foldersSec = document.getElementById('drive-folders-section');
  const foldersGrid = document.getElementById('drive-folders-grid');
  const foldersCnt = document.getElementById('drive-folders-count');
  const filesSec = document.getElementById('drive-files-section');
  const filesGrid = document.getElementById('drive-files-grid');
  const filesCnt = document.getElementById('drive-files-count');

  if (currentDriveViewMode === 'list') {
    if (foldersSec) foldersSec.style.display = 'none';
    if (filesSec) filesSec.style.display = 'none';
    if (listContainer) {
      if (folders.length > 0 || files.length > 0) {
        listContainer.style.display = 'block';
        let listRowsHtml = '';

        // Folder rows
        folders.forEach(f => {
          const isSelected = selectedDriveFolders.has(Number(f.id));
          const dateStr = f.updated_at ? f.updated_at.substring(0, 16).replace('T', ' ') : '-';
          listRowsHtml += `
            <tr id="disk-list-row-f-${f.id}" class="disk-list-row ${isSelected ? 'selected' : ''}" ondragover="handleDriveDragOver(event)" ondragleave="handleDriveDragLeave(event)" ondrop="handleDriveDropOnFolder(event, ${f.id})">
              <td style="text-align:center;">
                <input type="checkbox" class="disk-folder-cb" onchange="toggleDiskFolderSelection(${f.id}, this.checked)" ${isSelected ? 'checked' : ''}>
              </td>
              <td>
                <div class="disk-list-name-cell" onclick="navigateToDriveFolder(${f.id})" title="Açmak için tıklayın">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" style="flex-shrink:0;">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                  </svg>
                  <strong>${escapeHtml(f.name)}</strong>
                </div>
              </td>
              <td style="color:var(--text-muted);font-size:0.8rem;">Klasör</td>
              <td style="color:var(--text-muted);font-size:0.8rem;">${dateStr}</td>
              <td style="text-align:right;">
                <div style="display:inline-flex;align-items:center;gap:0.35rem;">
                  <button type="button" class="drive-action-btn" title="Yeniden Adlandır" onclick="promptRenameFolder(${f.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                  </button>
                  <button type="button" class="drive-action-btn btn-danger" title="Klasörü Sil" onclick="promptDeleteFolder(${f.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          `;
        });

        // File rows
        files.forEach(file => {
          const isSelected = selectedDriveFiles.has(Number(file.id));
          const ext = (file.extension || '').toLowerCase();
          const iconStyle = getDriveFileIconMeta(ext);
          const dateStr = file.created_at ? file.created_at.substring(0, 16).replace('T', ' ') : '';
          const canPreview = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'txt'].includes(ext);

          listRowsHtml += `
            <tr id="disk-list-row-file-${file.id}" class="disk-list-row ${isSelected ? 'selected' : ''}" draggable="true" ondragstart="handleDriveDragStart(event, ${file.id})" ondragend="handleDriveDragEnd(event)">
              <td style="text-align:center;">
                <input type="checkbox" class="disk-file-cb" onchange="toggleDiskFileSelection(${file.id}, this.checked)" ${isSelected ? 'checked' : ''}>
              </td>
              <td>
                <div class="disk-list-name-cell">
                  <span class="drive-file-icon" style="background:${iconStyle.bg};color:${iconStyle.color};width:24px;height:24px;font-size:0.65rem;line-height:24px;text-align:center;font-weight:700;display:inline-block;flex-shrink:0;">${iconStyle.label}</span>
                  <span title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</span>
                </div>
              </td>
              <td style="color:var(--text-secondary);font-size:0.8rem;font-weight:600;">${escapeHtml(file.formatted_size || '')}</td>
              <td style="color:var(--text-muted);font-size:0.8rem;">${dateStr}</td>
              <td style="text-align:right;">
                <div style="display:inline-flex;align-items:center;gap:0.35rem;">
                  <a href="api.php?action=drive_download&id=${file.id}" class="drive-action-btn" title="İndir" download>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                  </a>
                  ${canPreview ? `
                    <a href="api.php?action=drive_preview&id=${file.id}" target="_blank" rel="noopener noreferrer" class="drive-action-btn" title="Önizle / Yeni Sekmede Aç">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </a>
                  ` : ''}
                  <button type="button" class="drive-action-btn" title="Yeniden Adlandır" onclick="promptRenameFile(${file.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                  </button>
                  <button type="button" class="drive-action-btn btn-danger" title="Sil" onclick="promptDeleteFile(${file.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          `;
        });

        listTbody.innerHTML = listRowsHtml;
      } else {
        listContainer.style.display = 'none';
        listTbody.innerHTML = '';
      }
    }
  } else {
    // Grid View
    if (listContainer) listContainer.style.display = 'none';

    // 3. Folders Grid
    if (foldersSec && foldersGrid) {
      if (folders.length > 0) {
        foldersSec.style.display = 'block';
        if (foldersCnt) foldersCnt.textContent = `(${folders.length})`;
        foldersGrid.innerHTML = folders.map(f => {
          const isSelected = selectedDriveFolders.has(Number(f.id));
          return `
            <div id="drive-folder-card-${f.id}" class="drive-folder-card ${isSelected ? 'selected' : ''}" onclick="navigateToDriveFolder(${f.id})" ondragover="handleDriveDragOver(event)" ondragleave="handleDriveDragLeave(event)" ondrop="handleDriveDropOnFolder(event, ${f.id})" title="Açmak için tıklayın, dosya taşımak için üzerine bırakın">
              <input type="checkbox" class="drive-card-checkbox disk-folder-cb" onclick="event.stopPropagation()" onchange="toggleDiskFolderSelection(${f.id}, this.checked)" ${isSelected ? 'checked' : ''} title="Seç">
              <div class="drive-folder-left" title="${escapeHtml(f.name)}">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                  <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                </svg>
                <div class="drive-folder-name">${escapeHtml(f.name)}</div>
              </div>
              <div class="drive-item-actions" onclick="event.stopPropagation()">
                <button type="button" class="drive-action-btn" title="Yeniden Adlandır" onclick="promptRenameFolder(${f.id})">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                </button>
                <button type="button" class="drive-action-btn btn-danger" title="Klasörü Sil" onclick="promptDeleteFolder(${f.id})">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
          `;
        }).join('');
      } else {
        foldersSec.style.display = 'none';
        foldersGrid.innerHTML = '';
      }
    }

    // 4. Files Grid
    if (filesSec && filesGrid) {
      if (files.length > 0) {
        filesSec.style.display = 'block';
        if (filesCnt) filesCnt.textContent = `(${files.length})`;
        filesGrid.innerHTML = files.map(file => {
          const isSelected = selectedDriveFiles.has(Number(file.id));
          const ext = (file.extension || '').toLowerCase();
          const iconStyle = getDriveFileIconMeta(ext);
          const dateStr = file.created_at ? file.created_at.substring(0, 16).replace('T', ' ') : '';
          const canPreview = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'txt'].includes(ext);

          return `
            <div class="drive-file-card ${isSelected ? 'selected' : ''}" id="drive-file-${file.id}" draggable="true" ondragstart="handleDriveDragStart(event, ${file.id})" ondragend="handleDriveDragEnd(event)" title="Klasöre taşımak için sürükleyin">
              <input type="checkbox" class="drive-card-checkbox disk-file-cb" onclick="event.stopPropagation()" onchange="toggleDiskFileSelection(${file.id}, this.checked)" ${isSelected ? 'checked' : ''} title="Seç">
              <div class="drive-file-top">
                <div class="drive-file-icon" style="background:${iconStyle.bg};color:${iconStyle.color};">
                  ${iconStyle.label}
                </div>
                <div class="drive-file-info">
                  <div class="drive-file-name" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</div>
                  <div class="drive-file-meta">
                    <span>${escapeHtml(file.formatted_size || '')}</span>
                    <span>•</span>
                    <span>${dateStr}</span>
                  </div>
                </div>
              </div>
              <div class="drive-file-bottom">
                <div style="display:flex;align-items:center;gap:0.35rem;">
                  <a href="api.php?action=drive_download&id=${file.id}" class="drive-action-btn" title="İndir" download>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                  </a>
                  ${canPreview ? `
                    <a href="api.php?action=drive_preview&id=${file.id}" target="_blank" rel="noopener noreferrer" class="drive-action-btn" title="Önizle / Yeni Sekmede Aç">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </a>
                  ` : ''}
                </div>
                <div class="drive-item-actions">
                  <button type="button" class="drive-action-btn" title="Yeniden Adlandır" onclick="promptRenameFile(${file.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                  </button>
                  <button type="button" class="drive-action-btn btn-danger" title="Sil" onclick="promptDeleteFile(${file.id})">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  </button>
                </div>
              </div>
            </div>
          `;
        }).join('');
      } else {
        filesSec.style.display = 'none';
        filesGrid.innerHTML = '';
      }
    }
  updateDiskBulkBar();
}

  // 5. Empty State
  const emptyBox = document.getElementById('drive-empty-container');
  if (emptyBox) {
    if (folders.length === 0 && files.length === 0) {
      emptyBox.style.display = 'block';
      const isSearch = Boolean(currentDriveSearch);
      emptyBox.innerHTML = `
        <div class="drive-empty-card">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.35;">
            <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
          </svg>
          <p style="font-size:0.95rem;font-weight:600;color:var(--text-primary);margin-bottom:0.25rem;">
            ${isSearch ? `"${escapeHtml(currentDriveSearch)}" aramasıyla eşleşen klasör veya dosya bulunamadı.` : 'Bu klasör henüz boş.'}
          </p>
          <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1.25rem;">
            ${isSearch ? 'Farklı bir arama terimi deneyebilir veya aramayı temizleyebilirsiniz.' : 'Belgelerinizi, resimlerinizi ve dosyalarınızı hosting sunucunuza yükleyin.'}
          </p>
          ${!isSearch ? `
            <div style="display:flex;align-items:center;justify-content:center;gap:0.75rem;flex-wrap:wrap;">
              <button type="button" class="btn-send" onclick="triggerDriveFileInput()" style="border-radius:0 !important;padding:0.45rem 1rem;font-size:0.825rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Dosya Yükle
              </button>
              <button type="button" class="btn-secondary" onclick="openNewDriveFolderPrompt()" style="border-radius:0 !important;padding:0.45rem 1rem;font-size:0.825rem;border:1px solid var(--border-color);background:var(--bg-surface);color:var(--text-primary);cursor:pointer;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                Yeni Klasör
              </button>
            </div>
          ` : ''}
        </div>
      `;
    } else {
      emptyBox.style.display = 'none';
      emptyBox.innerHTML = '';
    }
  }
}

function getDriveFileIconMeta(ext) {
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) {
    return { bg: 'rgba(139, 92, 246, 0.12)', color: '#8b5cf6', label: ext ? ext.toUpperCase() : 'IMG' };
  }
  if (['pdf'].includes(ext)) {
    return { bg: 'rgba(239, 68, 68, 0.12)', color: '#ef4444', label: 'PDF' };
  }
  if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) {
    return { bg: 'rgba(37, 99, 235, 0.12)', color: '#2563eb', label: 'DOC' };
  }
  if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) {
    return { bg: 'rgba(16, 185, 129, 0.12)', color: '#10b981', label: 'XLS' };
  }
  if (['ppt', 'pptx'].includes(ext)) {
    return { bg: 'rgba(249, 115, 22, 0.12)', color: '#f97316', label: 'PPT' };
  }
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
    return { bg: 'rgba(245, 158, 11, 0.12)', color: '#f59e0b', label: 'ZIP' };
  }
  if (['mp3', 'wav', 'ogg', 'm4a'].includes(ext)) {
    return { bg: 'rgba(20, 184, 166, 0.12)', color: '#14b8a6', label: 'AUD' };
  }
  if (['mp4', 'mkv', 'avi', 'mov'].includes(ext)) {
    return { bg: 'rgba(236, 72, 153, 0.12)', color: '#ec4899', label: 'VID' };
  }
  if (['txt', 'log', 'md'].includes(ext)) {
    return { bg: 'rgba(100, 116, 139, 0.12)', color: '#64748b', label: 'TXT' };
  }
  return { bg: 'rgba(79, 70, 229, 0.12)', color: '#4f46e5', label: ext ? (ext.substring(0, 4).toUpperCase()) : 'FILE' };
}

function promptRenameFolder(folderId) {
  const f = (cachedDriveData?.folders || []).find(x => x.id == folderId);
  if (f) openRenameDriveFolderPrompt(f.id, f.name);
}

function promptDeleteFolder(folderId) {
  const f = (cachedDriveData?.folders || []).find(x => x.id == folderId);
  if (f) deleteDriveFolder(f.id, f.name);
}

function promptRenameFile(fileId) {
  const f = (cachedDriveData?.files || []).find(x => x.id == fileId);
  if (f) openRenameDriveFilePrompt(f.id, f.original_name);
}

function promptDeleteFile(fileId) {
  const f = (cachedDriveData?.files || []).find(x => x.id == fileId);
  if (f) deleteDriveFile(f.id, f.original_name);
}

// Drive Drag & Drop File Moving
let draggedDriveFileId = null;

function handleDriveDragStart(e, fileId) {
  draggedDriveFileId = fileId;
  e.dataTransfer.setData('text/plain', String(fileId));
  e.dataTransfer.effectAllowed = 'move';
  const card = document.getElementById('drive-file-' + fileId);
  if (card) {
    setTimeout(() => card.classList.add('is-dragging'), 10);
  }
}

function handleDriveDragEnd(e) {
  draggedDriveFileId = null;
  document.querySelectorAll('.drive-file-card.is-dragging').forEach(c => c.classList.remove('is-dragging'));
  document.querySelectorAll('.drag-target-hover').forEach(el => el.classList.remove('drag-target-hover'));
}

function handleDriveDragOver(e) {
  if (!draggedDriveFileId) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const target = e.currentTarget;
  if (target && !target.classList.contains('drag-target-hover')) {
    target.classList.add('drag-target-hover');
  }
}

function handleDriveDragLeave(e) {
  const target = e.currentTarget;
  if (target) {
    target.classList.remove('drag-target-hover');
  }
}

function handleDriveDropOnFolder(e, targetFolderId) {
  e.preventDefault();
  e.stopPropagation();
  const target = e.currentTarget;
  if (target) target.classList.remove('drag-target-hover');

  const fileId = draggedDriveFileId || e.dataTransfer.getData('text/plain');
  if (!fileId) return;

  const fd = new FormData();
  fd.append('action', 'drive_move_file');
  fd.append('id', fileId);
  fd.append('target_folder_id', targetFolderId);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message || 'Dosya başarıyla taşındı.', 'success');
        loadDrive(currentDriveFolderId);
      } else {
        showToast(data.message || 'Dosya taşınamadı.', 'error');
      }
    })
    .catch(err => showToast('Hata: ' + err.message, 'error'))
    .finally(() => {
      draggedDriveFileId = null;
    });
}

function navigateToDriveFolder(folderId) {
  currentDriveFolderId = folderId;
  currentDriveSearch = '';
  const sInput = document.getElementById('drive-search-input');
  if (sInput) sInput.value = '';
  loadDrive(folderId, '');
}

let driveSearchDebounceTimer = null;
function handleDriveSearch(val) {
  clearTimeout(driveSearchDebounceTimer);
  driveSearchDebounceTimer = setTimeout(() => {
    loadDrive(currentDriveFolderId, (val || '').trim());
  }, 250);
}

function openNewDriveFolderPrompt() {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Yeni Klasör',
      text: 'Oluşturmak istediğiniz klasörün adını girin:',
      input: 'text',
      inputPlaceholder: 'Örn: Muhasebe Evrakları',
      showCancelButton: true,
      confirmButtonText: 'Oluştur',
      cancelButtonText: 'Vazgeç',
      reverseButtons: true,
      inputValidator: (value) => {
        if (!value || !value.trim()) {
          return 'Lütfen bir klasör adı yazın!';
        }
      }
    }).then(res => {
      if (res.isConfirmed && res.value) {
        const folderName = res.value.trim();
        const fd = new FormData();
        fd.append('action', 'drive_create_folder');
        fd.append('parent_id', currentDriveFolderId);
        fd.append('name', folderName);

        fetch('api.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              showToast(data.message || 'Klasör oluşturuldu.', 'success');
              loadDrive(currentDriveFolderId);
            } else {
              showToast(data.message || 'Klasör oluşturulamadı.', 'error');
            }
          })
          .catch(err => showToast('Hata: ' + err.message, 'error'));
      }
    });
  } else {
    const fName = prompt('Yeni Klasör Adı:');
    if (fName && fName.trim()) {
      const fd = new FormData();
      fd.append('action', 'drive_create_folder');
      fd.append('parent_id', currentDriveFolderId);
      fd.append('name', fName.trim());
      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast('Klasör oluşturuldu.', 'success');
            loadDrive(currentDriveFolderId);
          } else {
            showToast(data.message || 'Hata oluştu.', 'error');
          }
        });
    }
  }
}

function openRenameDriveFolderPrompt(folderId, currentName) {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Klasörü Yeniden Adlandır',
      input: 'text',
      inputValue: currentName,
      showCancelButton: true,
      confirmButtonText: 'Kaydet',
      cancelButtonText: 'Vazgeç',
      reverseButtons: true,
      inputValidator: (value) => {
        if (!value || !value.trim()) {
          return 'Klasör adı boş olamaz!';
        }
      }
    }).then(res => {
      if (res.isConfirmed && res.value) {
        const fd = new FormData();
        fd.append('action', 'drive_rename_folder');
        fd.append('id', folderId);
        fd.append('name', res.value.trim());

        fetch('api.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              showToast('Klasör adı güncellendi.', 'success');
              loadDrive(currentDriveFolderId);
            } else {
              showToast(data.message || 'Güncellenemedi.', 'error');
            }
          })
          .catch(err => showToast('Hata: ' + err.message, 'error'));
      }
    });
  }
}

function deleteDriveFolder(folderId, folderName) {
  swalConfirm(
    'Klasörü Sil',
    `"${folderName}" klasörünü ve içindeki tüm alt klasörler ile dosyaları kalıcı olarak silmek istediğinize emin misiniz?`,
    'Evet, Klasörü Sil',
    'warning'
  ).then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'drive_delete_folder');
      fd.append('id', folderId);

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || 'Klasör silindi.', 'success');
            loadDrive(currentDriveFolderId);
          } else {
            showToast(data.message || 'Silinemedi.', 'error');
          }
        })
        .catch(err => showToast('Hata: ' + err.message, 'error'));
    }
  });
}

function triggerDriveFileInput() {
  const input = document.getElementById('drive-hidden-file-input');
  if (input) {
    input.value = '';
    input.click();
  }
}

function handleDriveFileSelect(event) {
  const files = event.target.files;
  if (files && files.length > 0) {
    uploadDriveFiles(files);
  }
}

function uploadDriveFiles(fileList) {
  if (!fileList || fileList.length === 0) return;

  const count = fileList.length;
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Hosting Sunucusuna Yükleniyor...',
      text: `${count} adet dosya yükleniyor, lütfen bekleyin...`,
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });
  }

  const fd = new FormData();
  fd.append('action', 'drive_upload');
  fd.append('folder_id', currentDriveFolderId);

  for (let i = 0; i < count; i++) {
    fd.append('files[]', fileList[i]);
  }

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (typeof Swal !== 'undefined') Swal.close();
      if (data.success) {
        showToast(data.message || `${count} dosya yüklendi.`, 'success');
        loadDrive(currentDriveFolderId);
      } else {
        showToast(data.message || 'Yükleme başarısız oldu.', 'error');
      }
    })
    .catch(err => {
      if (typeof Swal !== 'undefined') Swal.close();
      showToast('Yükleme hatası: ' + err.message, 'error');
    });
}

function openRenameDriveFilePrompt(fileId, currentName) {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Dosyayı Yeniden Adlandır',
      input: 'text',
      inputValue: currentName,
      showCancelButton: true,
      confirmButtonText: 'Kaydet',
      cancelButtonText: 'Vazgeç',
      reverseButtons: true,
      inputValidator: (value) => {
        if (!value || !value.trim()) {
          return 'Dosya adı boş olamaz!';
        }
      }
    }).then(res => {
      if (res.isConfirmed && res.value) {
        const fd = new FormData();
        fd.append('action', 'drive_rename_file');
        fd.append('id', fileId);
        fd.append('name', res.value.trim());

        fetch('api.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              showToast('Dosya adı güncellendi.', 'success');
              loadDrive(currentDriveFolderId);
            } else {
              showToast(data.message || 'Güncellenemedi.', 'error');
            }
          })
          .catch(err => showToast('Hata: ' + err.message, 'error'));
      }
    });
  }
}

function deleteDriveFile(fileId, fileName) {
  swalConfirm(
    'Dosyayı Sil',
    `"${fileName}" dosyasını kalıcı olarak silmek istediğinize emin misiniz?`,
    'Evet, Dosyayı Sil',
    'warning'
  ).then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'drive_delete_file');
      fd.append('id', fileId);

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast('Dosya silindi.', 'success');
            loadDrive(currentDriveFolderId);
          } else {
            showToast(data.message || 'Silinemedi.', 'error');
          }
        })
        .catch(err => showToast('Hata: ' + err.message, 'error'));
    }
  });
}

// ==========================================================================
// DOMAIN INTERNAL CHAT & MESSENGER JAVASCRIPT ENGINE (XMPP-LIKE)
// ==========================================================================

let chatBootstrapData = null;
let currentChatConvId = null;
let chatFilter = 'all'; // 'all', 'messages', 'colleagues', 'admin_all'
let chatSearchQuery = '';
let adminChatConversations = null;
let isAdminChatMode = false;
let chatHeartbeatTimeout = null;
let isChatHeartbeatRunning = false;
let lastNotifiedIncomingMsgId = 0;
let originalDocumentTitle = document.title || 'Hestia Webmail & Portal';
let titleBlinkInterval = null;
let isDocumentTitleNotifying = false;
let chatLastMsgId = 0;
let chatTypingTimeout = null;
let isChatSending = false;
let stagedChatAttachment = null;
let chatAudioContext = null;
let hasChatLoadedOnce = false;
let chatUnreadSoundCooldown = false;

// Adaptive polling delays
function getChatHeartbeatDelay() {
  if (document.hidden) {
    return 120000; // 120s (2 mins) when tab is minimized/in background
  }
  if (window.currentActiveTab === 'chat') {
    return 8000; // 8s when active in chat view
  }
  return 40000; // 40s when browsing emails, contacts, calendar etc.
}

function scheduleChatHeartbeat(explicitDelay = null) {
  if (chatHeartbeatTimeout) {
    clearTimeout(chatHeartbeatTimeout);
    chatHeartbeatTimeout = null;
  }
  const delay = (explicitDelay !== null) ? explicitDelay : getChatHeartbeatDelay();
  chatHeartbeatTimeout = setTimeout(() => {
    runChatHeartbeat();
  }, delay);
}

function triggerChatPollNow() {
  scheduleChatHeartbeat(50);
}

function setChatTitleNotification(unreadCount, senderName) {
  if (!originalDocumentTitle) {
    originalDocumentTitle = document.title || 'Hestia Webmail & Portal';
  }
  if (isDocumentTitleNotifying) return;
  isDocumentTitleNotifying = true;
  if (titleBlinkInterval) clearInterval(titleBlinkInterval);

  let isOriginal = false;
  const countStr = (unreadCount > 99) ? '99+' : unreadCount;
  const alertTitle = `(${countStr}) 💬 Yeni Mesajınız Var`;
  document.title = alertTitle;

  titleBlinkInterval = setInterval(() => {
    document.title = isOriginal ? alertTitle : originalDocumentTitle;
    isOriginal = !isOriginal;
  }, 2000);
}

function clearChatTitleNotification() {
  if (titleBlinkInterval) {
    clearInterval(titleBlinkInterval);
    titleBlinkInterval = null;
  }
  isDocumentTitleNotifying = false;
  if (originalDocumentTitle) {
    document.title = originalDocumentTitle;
  }
}

let miniChatNoticeTimeout = null;

function showIncomingChatToast(msg) {
  const existing = document.getElementById('chat-mini-notice');
  if (existing) existing.remove();
  if (miniChatNoticeTimeout) {
    clearTimeout(miniChatNoticeTimeout);
    miniChatNoticeTimeout = null;
  }

  const convId = (msg && msg.conversation_id) ? parseInt(msg.conversation_id, 10) : 0;
  const notice = document.createElement('div');
  notice.id = 'chat-mini-notice';
  notice.className = 'chat-mini-notice';
  notice.setAttribute('role', 'alert');
  notice.innerHTML = `
    <div class="chat-mini-notice-body" onclick="handleChatMiniNoticeClick(${convId})">
      <div class="chat-mini-notice-icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span class="chat-mini-notice-dot"></span>
      </div>
      <span class="chat-mini-notice-text">Yeni mesajınız var</span>
    </div>
    <button type="button" class="chat-mini-notice-close" onclick="closeChatMiniNotice(event)" title="Kapat">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  `;

  document.body.appendChild(notice);

  requestAnimationFrame(() => {
    notice.classList.add('visible');
  });

  miniChatNoticeTimeout = setTimeout(() => {
    closeChatMiniNotice();
  }, 4500);
}

function closeChatMiniNotice(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  const notice = document.getElementById('chat-mini-notice');
  if (!notice) return;
  notice.classList.remove('visible');
  setTimeout(() => {
    if (notice && notice.parentNode) {
      notice.parentNode.removeChild(notice);
    }
  }, 250);
}

function handleChatMiniNoticeClick(convId) {
  closeChatMiniNotice();
  clearChatTitleNotification();
  switchMainTab('chat');
  if (convId) {
    selectConversation(convId);
  }
}

// Audio Chime notification using Web Audio API (crisp pleasant modern tone)
function playChatNotificationSound() {
  try {
    if (chatUnreadSoundCooldown) return;
    chatUnreadSoundCooldown = true;
    setTimeout(() => { chatUnreadSoundCooldown = false; }, 1500);

    if (!chatAudioContext) {
      chatAudioContext = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (chatAudioContext.state === 'suspended') {
      chatAudioContext.resume();
    }
    const osc = chatAudioContext.createOscillator();
    const gain = chatAudioContext.createGain();
    osc.connect(gain);
    gain.connect(chatAudioContext.destination);

    // Two-tone bell chord
    const now = chatAudioContext.currentTime;
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, now); // D5
    osc.frequency.setValueAtTime(880, now + 0.08); // A5
    gain.gain.setValueAtTime(0.001, now);
    gain.gain.linearRampToValueAtTime(0.18, now + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.45);

    osc.start(now);
    osc.stop(now + 0.45);
  } catch (e) {}
}

function showChatDesktopNotification() {
  try {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
      const n = new Notification('Yeni Mesajınız Var', {
        body: 'Yeni bir mesaj aldınız.',
        icon: 'assets/images/brand-icon.png',
        tag: 'chat_msg_notice'
      });
      n.onclick = () => {
        window.focus();
        switchMainTab('chat');
      };
    } else if (Notification.permission !== 'denied') {
      Notification.requestPermission();
    }
  } catch (e) {}
}

// Global start from DOMContentLoaded
function startChatEngine() {
  loadChatBootstrap(null, true);
  scheduleChatHeartbeat(300);

  // Setup tab visibility and window focus listeners
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      if (window.currentActiveTab === 'chat') {
        clearChatTitleNotification();
      }
      triggerChatPollNow();
    } else {
      scheduleChatHeartbeat(120000);
    }
  });

  window.addEventListener('focus', () => {
    if (window.currentActiveTab === 'chat') {
      clearChatTitleNotification();
    }
    triggerChatPollNow();
  });
}

// User clicks top tab or floating button
function openChatTab(convIdToOpen = null) {
  const overlay = document.getElementById('chat-lock-overlay');
  if (overlay) overlay.style.display = 'none';

  if (!convIdToOpen) {
    chatView?.classList.remove('mobile-conversation-active');
    document.getElementById('chat-workspace')?.classList.remove('mobile-conversation-active');
  }

  if (!hasChatLoadedOnce) {
    loadChatBootstrap(convIdToOpen, false);
  } else if (convIdToOpen) {
    selectConversation(convIdToOpen);
  } else if (window.innerWidth > 768 && !currentChatConvId && chatBootstrapData && chatBootstrapData.conversations && chatBootstrapData.conversations.length > 0) {
    const first = chatBootstrapData.conversations[0];
    selectConversation(first.id, first.other_email || first.target_email);
  }
}

function handleFloatingChatClick() {
  switchMainTab('chat');
  if (window.innerWidth <= 768) {
    if (currentChatConvId) {
      selectConversation(currentChatConvId);
    } else if (chatBootstrapData && chatBootstrapData.conversations && chatBootstrapData.conversations.length > 0) {
      selectConversation(chatBootstrapData.conversations[0].id, chatBootstrapData.conversations[0].other_email || chatBootstrapData.conversations[0].target_email);
    }
  }
}

// Bootstrap loader
function loadChatBootstrap(autoOpenConvId = null, isBackground = false) {
  fetch('api.php?action=chat_bootstrap')
    .then(r => r.json())
    .then(res => {
      if (!res.success) return;
      chatBootstrapData = res;
      hasChatLoadedOnce = true;
      if (typeof res.has_pin !== 'undefined') {
        chatHasPin = !!res.has_pin;
        if (typeof updateChatLockBtnUI === 'function') updateChatLockBtnUI();
      }

      // Update Top Nav and Floating Badges
      updateChatBadges(res.total_unread);

      // Update Current User status card
      if (res.my_profile) {
        const myNameEl = document.getElementById('chat-my-name');
        if (myNameEl) myNameEl.textContent = res.my_profile.name || res.my_profile.email;

        const myAvatarEl = document.getElementById('chat-my-avatar');
        if (myAvatarEl) {
          const init = (res.my_profile.name || res.my_profile.email).charAt(0).toUpperCase();
          myAvatarEl.innerHTML = `${init}<span class="status-dot ${res.my_profile.status || 'online'}" id="chat-my-status-dot" style="position:absolute;bottom:0;right:0;border:2px solid var(--bg-surface);"></span>`;
        }

        const myStatusText = document.getElementById('chat-my-custom-status-text');
        if (myStatusText) {
          myStatusText.textContent = res.my_profile.custom_status || getStatusLabel(res.my_profile.status);
        }

        const btnDot = document.getElementById('chat-presence-btn-dot');
        if (btnDot) {
          btnDot.className = 'status-dot ' + (res.my_profile.status || 'online');
        }
      }

      // Render Sidebar
      renderChatSidebar();

      // Auto-open conversation if specified or select the first direct conversation on desktop
      if (!isBackground) {
        if (autoOpenConvId) {
          selectConversation(autoOpenConvId);
        } else if (!currentChatConvId && res.conversations && res.conversations.length > 0) {
          if (window.innerWidth > 768) {
            selectConversation(res.conversations[0].id, res.conversations[0].other_email || res.conversations[0].target_email);
          }
        }
      }
    })
    .catch(err => {
      console.error('Chat bootstrap error:', err);
    });
}

function updateChatBadges(unreadCount) {
  const topBadge = document.getElementById('top-chat-unread-badge');
  const floatBadge = document.getElementById('floating-chat-badge');
  const count = parseInt(unreadCount || 0, 10);

  if (topBadge) {
    if (count > 0) {
      topBadge.textContent = count > 99 ? '99+' : count;
      topBadge.style.display = 'inline-block';
      topBadge.classList.add('chat-badge-pulse');
    } else {
      topBadge.style.display = 'none';
      topBadge.classList.remove('chat-badge-pulse');
    }
  }

  if (floatBadge) {
    if (count > 0) {
      floatBadge.textContent = count > 99 ? '99+' : count;
      floatBadge.style.display = 'flex';
    } else {
      floatBadge.style.display = 'none';
    }
  }
}

function getStatusLabel(status) {
  switch (status) {
    case 'online': return 'Çevrimiçi';
    case 'away': return 'Uzakta';
    case 'busy': return 'Rahatsız Etmeyin';
    case 'offline': return 'Çevrimdışı';
    default: return 'Çevrimiçi';
  }
}

// Filter and render conversations + colleagues
function renderChatSidebar() {
  const listEl = document.getElementById('chat-conv-list');
  if (!listEl || !chatBootstrapData) return;

  const isAdmin = !!(chatBootstrapData.my_profile && (parseInt(chatBootstrapData.my_profile.is_admin) === 1 || chatBootstrapData.my_profile.is_admin === true));
  const convs = chatBootstrapData.conversations || [];
  const colleagues = chatBootstrapData.colleagues || [];
  const q = chatSearchQuery.toLowerCase().trim();

  // Admin All Conversations View
  if (chatFilter === 'admin_all') {
    if (!adminChatConversations) {
      listEl.innerHTML = `
        <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);font-size:0.85rem;">
          <div class="spinner-border spinner-border-sm" style="margin-bottom:0.5rem;display:inline-block;"></div><br>
          Tüm görüşme geçmişi yükleniyor...
        </div>
      `;
      fetchAdminChatConversations();
      return;
    }

    const adminConvs = adminChatConversations || [];
    const filteredAdminConvs = adminConvs.filter(c => {
      if (!q) return true;
      const title = (c.title || '').toLowerCase();
      const sub = (c.subtitle || '').toLowerCase();
      const lastMsg = (c.last_message || '').toLowerCase();
      const parts = (c.participants || []).map(p => (p.name + ' ' + p.email).toLowerCase()).join(' ');
      return title.includes(q) || sub.includes(q) || lastMsg.includes(q) || parts.includes(q);
    });

    let adminHtml = `
      <div class="chat-conv-section-header" style="display:flex;align-items:center;justify-content:space-between;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.22);padding:6px 10px;border-radius:6px;margin:4px 4px 8px 4px;">
        <span style="color:#d97706;font-weight:700;display:flex;align-items:center;gap:6px;font-size:0.775rem;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Tüm Şirket Görüşmeleri (${filteredAdminConvs.length})
        </span>
        <button type="button" onclick="fetchAdminChatConversations(true)" style="background:none;border:none;color:#d97706;cursor:pointer;padding:2px 4px;border-radius:4px;display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;" title="Listeyi Yenile">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Yenile
        </button>
      </div>
    `;

    if (filteredAdminConvs.length === 0) {
      adminHtml += `
        <div style="text-align:center;padding:2.5rem 1rem;color:var(--text-muted);font-size:0.85rem;">
          ${q ? 'Aramanıza uygun görüşme bulunamadı.' : 'Sistemde henüz kayıtlı bir görüşme bulunmuyor.'}
        </div>
      `;
      listEl.innerHTML = adminHtml;
      return;
    }

    filteredAdminConvs.forEach(ac => {
      const isActive = (currentChatConvId === ac.id && isAdminChatMode);
      const timeStr = ac.last_message_at ? formatChatTimestamp(ac.last_message_at) : '';
      const msgCount = parseInt(ac.total_messages || 0, 10);
      const parts = ac.participants || [];
      const init1 = (parts[0] ? (parts[0].name || parts[0].email).charAt(0) : 'A').toUpperCase();
      const init2 = (parts[1] ? (parts[1].name || parts[1].email).charAt(0) : 'B').toUpperCase();
      const avatarText = parts.length >= 2 ? `${init1}·${init2}` : init1;

      adminHtml += `
        <div class="chat-conv-item ${isActive ? 'active' : ''}" onclick="openAdminConversation(${ac.id})">
          <div class="chat-conv-avatar chat-conv-avatar-admin" style="font-size:0.75rem;letter-spacing:-0.5px;">
            ${avatarText}
            <span class="status-dot online chat-avatar-status" style="background:#f59e0b;border-color:var(--bg-surface);"></span>
          </div>
          <div class="chat-conv-content">
            <div class="chat-conv-top">
              <div class="chat-conv-title" title="${escapeHtml(ac.title)} (${escapeHtml(ac.subtitle)})">
                <span>${escapeHtml(ac.title)}</span>
              </div>
              <div class="chat-conv-time">${timeStr}</div>
            </div>
            <div class="chat-conv-bottom">
              <div class="chat-conv-snippet" title="${escapeHtml(ac.last_message || '')}">
                ${ac.last_message ? escapeHtml(ac.last_message) : '<span style="color:var(--text-muted);font-style:italic;">Mesajlaşma başlatıldı</span>'}
              </div>
              <div style="display:flex;align-items:center;gap:4px;">
                <span class="chat-conv-badge-admin">${msgCount} mesaj</span>
                <button type="button" class="chat-conv-delete-btn" onclick="deleteAdminChatConversation(${ac.id}, event)" title="Görüşmeyi ve Mesajları Sil">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
            <div style="font-size:0.7rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              ${escapeHtml(ac.subtitle)}
            </div>
          </div>
        </div>
      `;
    });

    listEl.innerHTML = adminHtml;
    return;
  }

  // 1. Direct Conversations (Mesajlar)
  const directConvs = convs.filter(c => c.type === 'direct');
  const filteredDirectConvs = directConvs.filter(c => {
    if (!q) return true;
    const name = c.other_name || c.title || '';
    const email = c.other_email || c.target_email || '';
    return name.toLowerCase().includes(q) || 
           email.toLowerCase().includes(q) || 
           (c.last_message && c.last_message.toLowerCase().includes(q));
  });

  // Colleagues presence lookup
  const colleagueStatusMap = {};
  colleagues.forEach(col => {
    colleagueStatusMap[col.email.toLowerCase()] = col;
  });

  // 2. Colleagues (Rehber)
  const filteredColleagues = colleagues.filter(col => {
    if (!q) return true;
    return (col.name && col.name.toLowerCase().includes(q)) || 
           (col.email && col.email.toLowerCase().includes(q));
  });

  if (filteredDirectConvs.length === 0 && filteredColleagues.length === 0) {
    listEl.innerHTML = `
      <div style="text-align:center;padding:2.5rem 1rem;color:var(--text-muted);font-size:0.85rem;">
        Sonuç bulunamadı.
      </div>
    `;
    return;
  }

  let html = '';
  const showMessages = (chatFilter === 'all' || chatFilter === 'messages');
  const showColleagues = (chatFilter === 'all' || chatFilter === 'colleagues');

  // 1. Render Direct Chats Section
  if (showMessages && filteredDirectConvs.length > 0) {
    html += `
      <div class="chat-conv-section-header">
        <span>Mesajlar (${filteredDirectConvs.length})</span>
      </div>
    `;

    filteredDirectConvs.forEach(dc => {
      const isActive = (currentChatConvId === dc.id);
      const unread = parseInt(dc.unread_count || 0, 10);
      const timeStr = dc.last_message_at ? formatChatTimestamp(dc.last_message_at) : '';
      const otherEmail = (dc.other_email || dc.target_email || '').toLowerCase();
      const colInfo = colleagueStatusMap[otherEmail];
      const statusClass = colInfo ? (colInfo.status || 'offline') : 'offline';
      const displayName = dc.other_name || dc.title || (colInfo ? colInfo.name : otherEmail);
      const initials = (displayName || 'K').charAt(0).toUpperCase();

      const colors = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777'];
      let hash = 0;
      for (let i = 0; i < otherEmail.length; i++) hash = otherEmail.charCodeAt(i) + ((hash << 5) - hash);
      const avatarBg = colors[Math.abs(hash) % colors.length];

      html += `
        <div class="chat-conv-item ${isActive ? 'active' : ''}" onclick="selectConversation(${dc.id}, '${escapeHtml(otherEmail)}')">
          <div class="chat-conv-avatar" style="background:${avatarBg};">
            ${initials}
            <span class="status-dot ${statusClass} chat-avatar-status"></span>
          </div>
          <div class="chat-conv-content">
            <div class="chat-conv-top">
              <div class="chat-conv-title" title="${escapeHtml(displayName)} (${escapeHtml(otherEmail)})">
                <span>${escapeHtml(displayName)}</span>
              </div>
              <div class="chat-conv-time">${timeStr}</div>
            </div>
            <div class="chat-conv-bottom">
              <div class="chat-conv-snippet" title="${escapeHtml(dc.last_message || '')}">
                ${dc.last_message ? escapeHtml(dc.last_message) : '<span style="color:var(--text-muted);font-style:italic;">Mesajlaşma başlatıldı</span>'}
              </div>
              <div style="display:flex;align-items:center;gap:4px;">
                ${unread > 0 ? `<span class="chat-conv-badge">${unread > 99 ? '99+' : unread}</span>` : ''}
                <button type="button" class="chat-conv-delete-btn" onclick="deleteChatConversation(${dc.id}, event)" title="Mesajlaşmayı Sil">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
    });
  }

  // 2. Render Colleagues Section (Domain Directory)
  if (showColleagues && filteredColleagues.length > 0) {
    html += `
      <div class="chat-conv-section-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span>Çalışma Arkadaşları (${filteredColleagues.length})</span>
        ${isAdmin ? `
          <button type="button" onclick="openManageHiddenColleaguesModal(event)" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;padding:2px 6px;border-radius:4px;" title="Gizlenen Kullanıcıları Yönet">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            Gizlenenler
          </button>
        ` : ''}
      </div>
    `;

    filteredColleagues.forEach(col => {
      const em = col.email.toLowerCase();
      const existingConv = directConvs.find(dc => (dc.other_email || dc.target_email || '').toLowerCase() === em);
      const cId = existingConv ? existingConv.id : null;
      const isActive = (cId && currentChatConvId === cId);
      const initials = (col.name || col.email).charAt(0).toUpperCase();
      const statusClass = col.status || 'offline';
      const snippet = existingConv && existingConv.last_message ? existingConv.last_message : (col.custom_status || col.email);

      const colors = ['#4f46e5', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777'];
      let hash = 0;
      for (let i = 0; i < em.length; i++) hash = em.charCodeAt(i) + ((hash << 5) - hash);
      const avatarBg = colors[Math.abs(hash) % colors.length];

      html += `
        <div class="chat-conv-item ${isActive ? 'active' : ''}" onclick="handleColleagueClick('${escapeHtml(col.email)}', ${cId})">
          <div class="chat-conv-avatar" style="background:${avatarBg};">
            ${initials}
            <span class="status-dot ${statusClass} chat-avatar-status"></span>
          </div>
          <div class="chat-conv-content">
            <div class="chat-conv-top">
              <div class="chat-conv-title" title="${escapeHtml(col.name)} (${escapeHtml(col.email)})">
                <span>${escapeHtml(col.name)}</span>
              </div>
            </div>
            <div class="chat-conv-bottom">
              <div class="chat-conv-snippet" title="${escapeHtml(snippet)}">
                ${escapeHtml(snippet)}
              </div>
              <div style="display:flex;align-items:center;gap:4px;">
                <span style="font-size:11px;color:var(--primary);font-weight:600;">+ Mesaj</span>
                ${isAdmin ? `
                  <button type="button" class="chat-colleague-hide-btn" onclick="hideColleagueFromChat('${escapeHtml(col.email)}', '${escapeHtml(col.name || col.email)}', event)" title="Listeden Gizle / Kaldır">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                  </button>
                ` : ''}
              </div>
            </div>
          </div>
        </div>
      `;
    });
  }

  listEl.innerHTML = html;
}

function deleteActiveChatConversation() {
  if (!currentChatConvId) return;
  if (isAdminChatMode) {
    deleteAdminChatConversation(currentChatConvId);
  } else {
    deleteChatConversation(currentChatConvId);
  }
}

function deleteChatConversation(convId, e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  convId = parseInt(convId, 10);
  if (!convId || !chatBootstrapData) return;

  const conv = (chatBootstrapData.conversations || []).find(c => parseInt(c.id, 10) === convId);
  const title = conv ? (conv.other_name || conv.title || 'Mesajlaşma') : 'Mesajlaşma';
  const promptText = `"${escapeHtml(title)}" ile olan mesajlaşma geçmişini silmek istediğinize emin misiniz?`;

  const confirmAction = () => {
    const fd = new FormData();
    fd.append('action', 'chat_delete_conversation');
    fd.append('conversation_id', convId);

    fetch('api.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showToast(res.message || 'Silindi.', 'success');
          if (chatBootstrapData && chatBootstrapData.conversations) {
            chatBootstrapData.conversations = chatBootstrapData.conversations.filter(c => parseInt(c.id, 10) !== convId);
          }
          if (currentChatConvId === convId) {
            currentChatConvId = null;
            currentChatOtherEmail = '';
            const remaining = (chatBootstrapData && chatBootstrapData.conversations) || [];
            if (remaining.length > 0) {
              const nextTarget = remaining[0].other_email || remaining[0].target_email || null;
              selectConversation(remaining[0].id, nextTarget);
            } else {
              const emptyState = document.getElementById('chat-empty-state');
              const activeView = document.getElementById('chat-active-conv');
              if (emptyState) emptyState.style.display = 'flex';
              if (activeView) activeView.style.display = 'none';
            }
          }
          renderChatSidebar();
        } else {
          showToast(res.message || 'Silinemedi', 'error');
        }
      })
      .catch(err => {
        showToast('Bağlantı hatası: ' + err.message, 'error');
      });
  };

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Mesajlaşmayı Sil',
      html: promptText,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Evet, Sil',
      cancelButtonText: 'Vazgeç'
    }).then(result => {
      if (result.isConfirmed) confirmAction();
    });
  } else if (confirm(promptText)) {
    confirmAction();
  }
}

function handleColleagueClick(email, convId) {
  email = (email || '').toLowerCase().trim();
  currentChatOtherEmail = email;
  convId = parseInt(convId, 10);
  const exists = convId && chatBootstrapData && chatBootstrapData.conversations && 
                 chatBootstrapData.conversations.some(c => parseInt(c.id, 10) === convId);
  if (exists) {
    selectConversation(convId, email);
  } else {
    startDirectChat(email);
  }
}

function hideColleagueFromChat(email, name, e) {
  if (e) e.stopPropagation();
  if (!email) return;

  const displayName = name || email;
  const promptText = `<b>${escapeHtml(displayName)}</b> kullanıcısını mesajlaşma listesinden kaldırmak (gizlemek) istiyor musunuz?<br><br><span style="font-size:0.8rem;color:var(--text-muted);">Bu kullanıcı artık çalışma arkadaşları listesinde görünmeyecektir. Dilediğiniz zaman "Gizlenenler" menüsünden tekrar ekleyebilirsiniz.</span>`;

  const doHide = () => {
    const fd = new FormData();
    fd.append('email', email);

    fetch('api.php?action=chat_hide_colleague', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          showToast('Kullanıcı listeden kaldırıldı.', 'success');
          if (chatBootstrapData && chatBootstrapData.colleagues) {
            chatBootstrapData.colleagues = chatBootstrapData.colleagues.filter(c => c.email.toLowerCase() !== email.toLowerCase());
          }
          renderChatSidebar();
        } else {
          showToast(res.message || 'Kaldırılamadı', 'error');
        }
      })
      .catch(err => {
        showToast('Bağlantı hatası: ' + err.message, 'error');
      });
  };

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Listeden Kaldır',
      html: promptText,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Evet, Listeden Kaldır',
      cancelButtonText: 'Vazgeç',
      reverseButtons: true
    }).then(result => {
      if (result.isConfirmed) doHide();
    });
  } else if (confirm(displayName + ' kullanıcısını listeden kaldırmak istediğinize emin misiniz?')) {
    doHide();
  }
}

function openManageHiddenColleaguesModal(e) {
  if (e) e.stopPropagation();

  fetch('api.php?action=chat_get_hidden_colleagues')
    .then(r => r.json())
    .then(res => {
      if (res.status !== 'success') {
        showToast(res.message || 'Gizlenen kullanıcılar alınamadı.', 'error');
        return;
      }
      const hiddenList = res.hidden_colleagues || [];
      if (hiddenList.length === 0) {
        Swal.fire({
          title: 'Gizlenen Kullanıcılar',
          text: 'Şu anda listeden gizlenmiş veya kaldırılmış bir kullanıcı bulunmuyor.',
          icon: 'info',
          confirmButtonColor: 'var(--primary, #2563eb)',
          confirmButtonText: 'Tamam'
        });
        return;
      }

      let rowsHtml = '';
      hiddenList.forEach(item => {
        const em = escapeHtml(item.email);
        const name = escapeHtml(item.name || item.email);
        rowsHtml += `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--border-color);background:var(--bg-surface);margin-bottom:6px;border-radius:6px;text-align:left;">
            <div style="overflow:hidden;padding-right:8px;">
              <div style="font-weight:600;font-size:0.875rem;color:var(--text-primary);">${name}</div>
              <div style="font-size:0.75rem;color:var(--text-muted);">${em}</div>
            </div>
            <button type="button" class="btn-send" onclick="unhideColleagueFromChat('${em}')" style="height:28px;padding:0 10px;font-size:0.75rem;background:#10b981;border-color:#10b981;border-radius:4px !important;white-space:nowrap;flex-shrink:0;">
              Geri Ekle
            </button>
          </div>
        `;
      });

      Swal.fire({
        title: 'Gizlenen Kullanıcıları Yönet',
        html: `
          <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;text-align:left;">
            Aşağıdaki kullanıcılar domain içi mesajlaşma çalışma arkadaşları listesinden gizlenmiştir. İstediğiniz kullanıcıyı tekrar listeye dahil edebilirsiniz.
          </p>
          <div style="max-height:280px;overflow-y:auto;padding-right:4px;">
            ${rowsHtml}
          </div>
        `,
        showConfirmButton: true,
        confirmButtonText: 'Kapat',
        confirmButtonColor: '#64748b',
        width: '520px'
      });
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function unhideColleagueFromChat(email) {
  if (!email) return;
  const fd = new FormData();
  fd.append('email', email);

  fetch('api.php?action=chat_unhide_colleague', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.status === 'success') {
        showToast('Kullanıcı listeye geri eklendi.', 'success');
        fetch('api.php?action=chat_get_colleagues')
          .then(r => r.json())
          .then(cRes => {
            if (cRes.success && chatBootstrapData) {
              chatBootstrapData.colleagues = cRes.colleagues || [];
              renderChatSidebar();
            }
          });
        Swal.close();
      } else {
        showToast(res.message || 'İşlem başarısız', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function setChatFilter(filter) {
  chatFilter = filter;
  document.querySelectorAll('.chat-filter-chip').forEach(c => c.classList.remove('active'));
  const btn = document.getElementById('chat-filter-' + filter);
  if (btn) btn.classList.add('active');

  const quickBtn = document.getElementById('chat-admin-mode-quick-btn');
  if (quickBtn) {
    if (filter === 'admin_all') {
      quickBtn.style.background = '#f59e0b';
      quickBtn.style.color = '#ffffff';
      quickBtn.style.borderColor = '#f59e0b';
    } else {
      quickBtn.style.background = 'rgba(245,158,11,0.08)';
      quickBtn.style.color = '#d97706';
      quickBtn.style.borderColor = 'rgba(245,158,11,0.3)';
    }
  }

  if (filter === 'admin_all') {
    fetchAdminChatConversations();
  }
  renderChatSidebar();
}

function toggleAdminChatMode() {
  if (chatFilter === 'admin_all') {
    setChatFilter('all');
  } else {
    setChatFilter('admin_all');
  }
}

function handleChatSearch(val) {
  chatSearchQuery = val;
  renderChatSidebar();
}

let currentChatOtherEmail = '';

// Select and open conversation
function selectConversation(convId, targetEmailHint = null, isAutoScroll = true) {
  currentChatConvId = parseInt(convId, 10);
  if (!currentChatConvId) return;

  isAdminChatMode = false;

  const emptyState = document.getElementById('chat-empty-state');
  const activeView = document.getElementById('chat-active-conv');
  if (emptyState) emptyState.style.display = 'none';
  if (activeView) activeView.style.display = 'flex';

  // Restore regular chat composer, hide admin readonly bar
  const composer = document.querySelector('.chat-composer');
  if (composer) composer.style.display = 'flex';
  const adminBar = document.getElementById('chat-admin-readonly-bar');
  if (adminBar) adminBar.style.display = 'none';

  cancelStagedAttachment();
  document.getElementById('view-chat')?.classList.add('mobile-conversation-active');
  document.getElementById('chat-workspace')?.classList.add('mobile-conversation-active');

  // Accurately determine targetEmail for this exact conversation
  let targetEmail = (targetEmailHint || '').toLowerCase().trim();
  if (!targetEmail && chatBootstrapData && chatBootstrapData.conversations) {
    const cObj = chatBootstrapData.conversations.find(c => parseInt(c.id, 10) === currentChatConvId);
    if (cObj) {
      if (cObj.type === 'direct') {
        targetEmail = (cObj.other_email || cObj.target_email || '').toLowerCase().trim();
      } else {
        targetEmail = '';
      }
    }
  }
  currentChatOtherEmail = targetEmail;

  renderChatSidebar();

  const container = document.getElementById('chat-messages-container');
  if (container) {
    container.innerHTML = `
      <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);font-size:0.85rem;">
        Mesajlar yükleniyor...
      </div>
    `;
  }

  let fetchUrl = 'api.php?action=chat_get_messages&conversation_id=' + currentChatConvId;
  if (targetEmail) {
    fetchUrl += '&target_email=' + encodeURIComponent(targetEmail);
  }

  fetch(fetchUrl)
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        if (res.not_found) {
          const badConvId = currentChatConvId;
          currentChatConvId = null;
          currentChatOtherEmail = '';
          if (chatBootstrapData && chatBootstrapData.conversations) {
            chatBootstrapData.conversations = chatBootstrapData.conversations.filter(c => parseInt(c.id, 10) !== badConvId);
          }
          renderChatSidebar();
          const remaining = (chatBootstrapData && chatBootstrapData.conversations) || [];
          if (remaining.length > 0) {
            const nextTarget = remaining[0].other_email || remaining[0].target_email || null;
            selectConversation(remaining[0].id, nextTarget);
          } else {
            if (emptyState) emptyState.style.display = 'flex';
            if (activeView) activeView.style.display = 'none';
          }
          return;
        }
        showToast(res.message || 'Mesajlar yüklenemedi', 'error');
        return;
      }

      const conv = res.conversation;
      if (conv) {
        if (conv.id && parseInt(conv.id, 10) !== currentChatConvId) {
          currentChatConvId = parseInt(conv.id, 10);
        }
        if (conv.type === 'direct') {
          currentChatOtherEmail = (conv.other_email || conv.target_email || targetEmail || '').toLowerCase().trim();
        } else {
          currentChatOtherEmail = '';
        }
      }
      const parts = res.participants || [];
      const titleEl = document.getElementById('chat-active-title');
      const subtitleEl = document.getElementById('chat-active-subtitle');
      const avatarEl = document.getElementById('chat-active-avatar');

      if (conv) {
        const myEmail = ((window.CURRENT_USER_EMAIL || (chatBootstrapData && chatBootstrapData.my_profile ? chatBootstrapData.my_profile.email : '')) || '').toLowerCase().trim();
        // The conversation partner is strictly the participant whose email is NOT mine
        let otherPart = parts.find(p => p.email && p.email.toLowerCase() !== myEmail);
        if (!otherPart && parts.length > 0) {
          otherPart = parts[0];
        }
        let otherEmail = otherPart ? otherPart.email.toLowerCase().trim() : (targetEmail || currentChatOtherEmail || '').toLowerCase().trim();
        if (otherEmail === myEmail && conv.other_email && conv.other_email.toLowerCase() !== myEmail) {
          otherEmail = conv.other_email.toLowerCase().trim();
        }
        currentChatOtherEmail = otherEmail;

        // Lookup colleague directory for accurate name and presence
        const colInfo = (chatBootstrapData && chatBootstrapData.colleagues) ? chatBootstrapData.colleagues.find(c => c.email.toLowerCase() === otherEmail) : null;
        const otherName = (colInfo && colInfo.name) || (otherPart && otherPart.name) || conv.other_name || (otherEmail ? otherEmail.split('@')[0] : 'Mesajlaşma');
        const otherStatus = (otherPart && otherPart.is_online) ? (otherPart.status || 'online') : (colInfo ? colInfo.status : 'offline');
        const otherStatusLabel = (otherPart && otherPart.custom_status) || (colInfo && colInfo.custom_status) || getStatusLabel(otherStatus);

        if (titleEl) titleEl.textContent = otherName;
        if (avatarEl) {
          avatarEl.textContent = otherName.charAt(0).toUpperCase();
          avatarEl.className = 'chat-header-avatar';
          avatarEl.style.background = 'linear-gradient(135deg, #4f46e5, #3b82f6)';
        }
        if (subtitleEl) {
          subtitleEl.innerHTML = `<span class="status-dot ${otherStatus}"></span> <span>${escapeHtml(otherStatusLabel)}</span>`;
        }

        const deleteBtn = document.getElementById('chat-btn-delete-conv');
        if (deleteBtn) {
          deleteBtn.style.display = 'flex';
          deleteBtn.title = 'Mesajlaşmayı Sil';
        }
      }

      renderMessages(res.messages || []);

      if (chatBootstrapData && chatBootstrapData.conversations) {
        const cObj = chatBootstrapData.conversations.find(c => parseInt(c.id, 10) === currentChatConvId);
        if (cObj && cObj.unread_count > 0) {
          chatBootstrapData.total_unread = Math.max(0, (chatBootstrapData.total_unread || 0) - cObj.unread_count);
          cObj.unread_count = 0;
          updateChatBadges(chatBootstrapData.total_unread);
          if (chatBootstrapData.total_unread === 0) {
            clearChatTitleNotification();
          }
          renderChatSidebar();
        }
      }

      const inp = document.getElementById('chat-message-input');
      if (inp) inp.focus();
    })
    .catch(err => {
      console.error('get_messages error:', err);
    });
}

function reloadActiveConversation() {
  if (currentChatConvId) {
    if (isAdminChatMode) {
      openAdminConversation(currentChatConvId);
    } else {
      selectConversation(currentChatConvId);
    }
  }
}

// =========================================================
// ADMIN CHAT MONITORING JAVASCRIPT LOGIC
// =========================================================
function fetchAdminChatConversations(forceRefresh = false) {
  if (adminChatConversations && !forceRefresh) {
    renderChatSidebar();
    return;
  }

  fetch('api.php?action=chat_admin_get_conversations')
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showToast(res.message || 'Görüşmeler alınamadı', 'error');
        return;
      }
      adminChatConversations = res.conversations || [];
      if (chatFilter === 'admin_all') {
        renderChatSidebar();
      }
    })
    .catch(err => {
      console.error('fetchAdminChatConversations error:', err);
    });
}

function openAdminConversation(convId) {
  convId = parseInt(convId, 10);
  if (!convId) return;

  isAdminChatMode = true;
  currentChatConvId = convId;

  const emptyState = document.getElementById('chat-empty-state');
  const activeView = document.getElementById('chat-active-conv');
  if (emptyState) emptyState.style.display = 'none';
  if (activeView) activeView.style.display = 'flex';

  cancelStagedAttachment();
  document.getElementById('view-chat')?.classList.add('mobile-conversation-active');
  document.getElementById('chat-workspace')?.classList.add('mobile-conversation-active');

  // Hide regular chat composer, show admin read-only bar
  const composer = document.querySelector('.chat-composer');
  if (composer) composer.style.display = 'none';
  const adminBar = document.getElementById('chat-admin-readonly-bar');
  if (adminBar) adminBar.style.display = 'flex';

  renderChatSidebar();

  const container = document.getElementById('chat-messages-container');
  if (container) {
    container.innerHTML = `
      <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);font-size:0.85rem;">
        <div class="spinner-border spinner-border-sm" style="margin-bottom:0.5rem;display:inline-block;"></div><br>
        Görüşme geçmişi yükleniyor...
      </div>
    `;
  }

  fetch('api.php?action=chat_admin_get_conversation_messages&conversation_id=' + convId)
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showToast(res.message || 'Görüşme geçmişi yüklenemedi', 'error');
        return;
      }

      const conv = res.conversation;
      const parts = res.participants || [];
      const messages = res.messages || [];

      // Update header
      const titleEl = document.getElementById('chat-active-title');
      const subtitleEl = document.getElementById('chat-active-subtitle');
      const avatarEl = document.getElementById('chat-active-avatar');
      const deleteBtn = document.getElementById('chat-btn-delete-conv');

      const title = conv.display_title || (parts.length >= 2 ? (parts[0].name + ' ↔ ' + parts[1].name) : 'Görüşme #' + convId);
      const subtitle = conv.display_subtitle || parts.map(p => p.email).join(' • ');

      if (titleEl) {
        titleEl.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px;">${escapeHtml(title)}</span>`;
      }
      if (avatarEl) {
        avatarEl.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`;
        avatarEl.className = 'chat-header-avatar chat-conv-avatar-admin';
      }
      if (subtitleEl) {
        subtitleEl.innerHTML = `
          <span style="color:#d97706;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Yönetici Denetim Modu
          </span>
          <span style="color:var(--text-muted);margin:0 4px;">•</span>
          <span>${messages.length} Mesaj</span>
          <span style="color:var(--text-muted);margin:0 4px;">•</span>
          <span style="color:var(--text-muted);" title="${escapeHtml(subtitle)}">${escapeHtml(subtitle)}</span>
        `;
      }

      if (deleteBtn) {
        deleteBtn.style.display = 'flex';
        deleteBtn.title = 'Görüşmeyi ve Mesajları Sil';
        deleteBtn.onclick = () => deleteAdminChatConversation(convId);
      }

      // Render Messages in Admin Mode
      renderAdminMessages(messages, parts);
    })
    .catch(err => {
      console.error('openAdminConversation error:', err);
      showToast('Görüşme yüklenirken hata oluştu', 'error');
    });
}

function renderAdminMessages(messages, participants) {
  const container = document.getElementById('chat-messages-container');
  if (!container) return;

  const part1Email = (participants[0] ? participants[0].email : '').toLowerCase();
  const part2Email = (participants[1] ? participants[1].email : '').toLowerCase();

  let html = `
    <div class="chat-admin-audit-notice">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink:0;margin-top:2px;">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
      <div>
        <strong>Yönetici Görüşme İzleme Modu</strong>
        <div style="font-size:0.775rem;opacity:0.9;margin-top:2px;">
          Bu görüşme personeller arası gerçekleşen iletişimi denetlemek amacıyla incelenmektedir. Kimin kime ne yazdığı, gönderilen dosyalar ve saat bilgileri aşağıda kronolojik olarak listelenmektedir.
        </div>
      </div>
    </div>
  `;

  if (messages.length === 0) {
    html += `
      <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);font-size:0.85rem;">
        Bu görüşmede henüz mesaj bulunmamaktadır.
      </div>
    `;
    container.innerHTML = html;
    return;
  }

  let lastDateStr = '';
  messages.forEach(m => {
    const mDate = m.created_at ? m.created_at.substring(0, 10) : '';
    if (mDate && mDate !== lastDateStr) {
      lastDateStr = mDate;
      html += `
        <div class="chat-date-divider">
          <span>${formatChatDateDivider(mDate)}</span>
        </div>
      `;
    }

    const sEmail = (m.sender_email || '').toLowerCase().trim();
    // Participant 1 on the left (incoming), Participant 2 on the right (outgoing)
    const isSideB = (part2Email && sEmail === part2Email);
    const rowClass = isSideB ? 'outgoing' : 'incoming';
    const timeStr = m.created_at ? m.created_at.substring(11, 16) : '';

    // Sender display tag
    const senderName = m.sender_name || (participants.find(p => p.email.toLowerCase() === sEmail)?.name) || sEmail;

    // Attachments
    let attachHtml = '';
    if (m.attachment_url) {
      const isImg = (m.attachment_type && m.attachment_type.startsWith('image/')) || 
                    (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(m.attachment_url));
      if (isImg) {
        attachHtml = `<img src="${escapeHtml(m.attachment_url)}" alt="Görsel" class="chat-attachment-img" onclick="openChatLightbox('${escapeHtml(m.attachment_url)}')">`;
      } else {
        const sizeStr = m.attachment_size ? formatBytes(m.attachment_size) : '';
        attachHtml = `
          <a href="${escapeHtml(m.attachment_url)}" download="${escapeHtml(m.attachment_name || 'dosya')}" class="chat-file-card">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <div style="min-width:0;flex:1;">
              <div class="chat-file-name" title="${escapeHtml(m.attachment_name || 'Dosya')}">${escapeHtml(m.attachment_name || 'Dosya')}</div>
              <div class="chat-file-size">${sizeStr}</div>
            </div>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          </a>
        `;
      }
    }

    const isDeleted = parseInt(m.is_deleted || 0, 10) === 1;
    const formattedText = m.message ? autolinkText(escapeHtml(m.message)) : '';

    html += `
      <div class="chat-msg-row ${rowClass}" id="chat-msg-${m.id}">
        <div class="chat-msg-bubble-wrap">
          <div class="chat-msg-actions">
            <button type="button" class="chat-msg-act-btn delete-btn" onclick="deleteChatMessage(${m.id})" title="Yönetici Olarak Mesajı Sil" style="color:#ef4444;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </button>
          </div>
          <div class="chat-msg-bubble ${isDeleted ? 'chat-msg-deleted' : ''}">
            <div class="chat-msg-sender" style="display:flex;align-items:center;gap:6px;font-weight:700;margin-bottom:3px;">
              <span>${escapeHtml(senderName)}</span>
              <span style="font-size:0.7rem;font-weight:400;color:var(--text-muted);">(${escapeHtml(sEmail)})</span>
            </div>
            ${isDeleted ? `
              <div style="color:var(--text-muted);font-style:italic;font-size:0.8rem;display:flex;align-items:center;gap:4px;margin-bottom:3px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                Bu mesaj silinmiştir (Yönetici Görünümü):
              </div>
            ` : ''}
            ${formattedText ? `<div class="chat-msg-text" style="${isDeleted ? 'opacity:0.65;text-decoration:line-through;' : ''}">${formattedText}</div>` : ''}
            ${attachHtml}
            <div class="chat-msg-meta">
              <span>${timeStr}</span>
              <button type="button" class="chat-msg-delete-quick-btn" onclick="deleteChatMessage(${m.id}); event.stopPropagation();" title="Mesajı Sil">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  });

  container.innerHTML = html;
  container.scrollTop = container.scrollHeight;
}

function deleteAdminChatConversation(convId, e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  convId = parseInt(convId, 10);
  if (!convId) return;

  if (!confirm('Bu görüşmeyi ve tüm mesaj geçmişini kalıcı olarak silmek istediğinizden emin misiniz?')) {
    return;
  }

  const formData = new FormData();
  formData.append('conversation_id', convId);

  fetch('api.php?action=chat_delete_conversation', {
    method: 'POST',
    body: formData
  })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showToast(res.message || 'Silme işlemi başarısız', 'error');
        return;
      }
      showToast('Görüşme ve mesajları silindi', 'success');
      if (adminChatConversations) {
        adminChatConversations = adminChatConversations.filter(c => parseInt(c.id, 10) !== convId);
      }
      if (currentChatConvId === convId) {
        currentChatConvId = null;
        isAdminChatMode = false;
        const emptyState = document.getElementById('chat-empty-state');
        const activeView = document.getElementById('chat-active-conv');
        if (emptyState) emptyState.style.display = 'flex';
        if (activeView) activeView.style.display = 'none';
      }
      renderChatSidebar();
    })
    .catch(err => {
      console.error('deleteAdminChatConversation error:', err);
      showToast('İşlem başarısız', 'error');
    });
}

// Render message stream
function renderMessages(messages, isAppend = false) {
  const container = document.getElementById('chat-messages-container');
  if (!container) return;

  if (messages.length === 0 && !isAppend) {
    container.innerHTML = `
      <div style="text-align:center;padding:3rem 1rem;color:var(--text-muted);font-size:0.85rem;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.6;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <div>Henüz bir mesaj yok. Mesajlaşmayı siz başlatın!</div>
      </div>
    `;
    return;
  }

  let html = isAppend ? container.innerHTML : '';
  let lastDateStr = '';

  messages.forEach(m => {
    chatLastMsgId = Math.max(chatLastMsgId, parseInt(m.id, 10));

    const mDate = m.created_at ? m.created_at.substring(0, 10) : '';
    if (mDate && mDate !== lastDateStr && !isAppend) {
      lastDateStr = mDate;
      html += `<div class="chat-date-divider">${formatChatDateDivider(mDate)}</div>`;
    }

    html += buildSingleMessageHtml(m);
  });

  if (isAppend) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = messages.map(m => buildSingleMessageHtml(m)).join('');
    while (tempDiv.firstChild) {
      container.appendChild(tempDiv.firstChild);
    }
  } else {
    container.innerHTML = html;
  }

  container.scrollTop = container.scrollHeight;
}

function buildSingleMessageHtml(m) {
  const myEmail = (chatBootstrapData && chatBootstrapData.my_profile && chatBootstrapData.my_profile.email)
    ? chatBootstrapData.my_profile.email.toLowerCase().trim()
    : (window.CURRENT_USER_EMAIL || '').toLowerCase().trim();
  const isAdmin = !!(chatBootstrapData && chatBootstrapData.my_profile && (parseInt(chatBootstrapData.my_profile.is_admin) === 1 || chatBootstrapData.my_profile.is_admin === true));
  const senderEmail = (m.sender_email || '').toLowerCase().trim();
  const isMe = (m.is_me === true || m.is_me === 1 || (senderEmail && senderEmail === myEmail));
  const canDelete = isMe || isAdmin;
  const timeStr = m.created_at ? m.created_at.substring(11, 16) : '';

  // Parse reactions
  const reactions = m.reactions || {};
  let reactionsHtml = '';
  const reactionKeys = Object.keys(reactions);
  if (reactionKeys.length > 0) {
    reactionsHtml = `<div class="chat-reactions-row">`;
    reactionKeys.forEach(rk => {
      const users = reactions[rk] || [];
      const hasMe = users.includes(myEmail);
      reactionsHtml += `
        <span class="chat-reaction-chip ${hasMe ? 'active' : ''}" onclick="toggleChatReaction(${m.id}, '${rk}')" title="${users.join(', ')}">
          <span>${rk}</span>
          <span style="font-size:0.7rem;font-weight:700;">${users.length}</span>
        </span>
      `;
    });
    reactionsHtml += `</div>`;
  }

  // Parse attachments
  let attachHtml = '';
  if (m.attachment_url) {
    const isImg = (m.attachment_type && m.attachment_type.startsWith('image/')) || 
                  (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(m.attachment_url));
    if (isImg) {
      attachHtml = `
        <img src="${escapeHtml(m.attachment_url)}" alt="Görsel" class="chat-attachment-img" onclick="openChatLightbox('${escapeHtml(m.attachment_url)}')">
      `;
    } else {
      const sizeStr = m.attachment_size ? formatBytes(m.attachment_size) : '';
      attachHtml = `
        <a href="${escapeHtml(m.attachment_url)}" download="${escapeHtml(m.attachment_name || 'dosya')}" class="chat-file-card">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <div style="min-width:0;flex:1;">
            <div class="chat-file-name" title="${escapeHtml(m.attachment_name || 'Dosya')}">${escapeHtml(m.attachment_name || 'Dosya')}</div>
            <div class="chat-file-size">${sizeStr}</div>
          </div>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
      `;
    }
  }

  const formattedText = m.message ? autolinkText(escapeHtml(m.message)) : '';

  return `
    <div class="chat-msg-row ${isMe ? 'outgoing' : 'incoming'}" id="chat-msg-${m.id}">
      <div class="chat-msg-bubble-wrap">
        <div class="chat-msg-actions">
          <button type="button" class="chat-msg-act-btn" onclick="toggleChatReaction(${m.id}, '👍')" title="Beğen (👍)">👍</button>
          <button type="button" class="chat-msg-act-btn" onclick="toggleChatReaction(${m.id}, '❤️')" title="Sevdim (❤️)">❤️</button>
          <button type="button" class="chat-msg-act-btn" onclick="toggleChatReaction(${m.id}, '😂')" title="Güldüm (😂)">😂</button>
          <button type="button" class="chat-msg-act-btn" onclick="toggleChatReaction(${m.id}, '🎉')" title="Tebrikler (🎉)">🎉</button>
          ${canDelete ? `
            <button type="button" class="chat-msg-act-btn delete-btn" onclick="deleteChatMessage(${m.id})" title="Mesajı Sil" style="color:#ef4444;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </button>
          ` : ''}
        </div>

        <div class="chat-msg-bubble">
          ${!isMe ? `<div class="chat-msg-sender">${escapeHtml(m.sender_name || m.sender_email)}</div>` : ''}
          ${formattedText ? `<div class="chat-msg-text">${formattedText}</div>` : ''}
          ${attachHtml}
          <div class="chat-msg-meta">
            <span>${timeStr}</span>
            ${isMe ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' : ''}
            ${canDelete ? `
              <button type="button" class="chat-msg-delete-quick-btn" onclick="deleteChatMessage(${m.id}); event.stopPropagation();" title="Mesajı Sil">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
              </button>
            ` : ''}
          </div>
        </div>

        ${reactionsHtml}
      </div>
    </div>
  `;
}

function autolinkText(text) {
  if (!text) return '';
  const urlRegex = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
  return text.replace(urlRegex, url => `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`);
}

function formatChatDateDivider(dateStr) {
  const today = new Date().toISOString().substring(0, 10);
  const yesterday = new Date(Date.now() - 86400000).toISOString().substring(0, 10);
  if (dateStr === today) return 'Bugün';
  if (dateStr === yesterday) return 'Dün';
  const parts = dateStr.split('-');
  return `${parts[2]}.${parts[1]}.${parts[0]}`;
}

function formatChatTimestamp(ts) {
  if (!ts) return '';
  const today = new Date().toISOString().substring(0, 10);
  const mDate = ts.substring(0, 10);
  const time = ts.substring(11, 16);
  if (mDate === today) return time;
  const parts = mDate.split('-');
  return `${parts[2]}.${parts[1]}`;
}

// Send message
function sendChatMessage() {
  if (isChatSending || !currentChatConvId) return;

  const inp = document.getElementById('chat-message-input');
  if (!inp) return;
  const text = inp.value.trim();

  if (!text && !stagedChatAttachment) return;

  isChatSending = true;
  const fd = new FormData();
  fd.append('action', 'chat_send_message');
  fd.append('conversation_id', currentChatConvId);

  let targetEmail = '';
  if (chatBootstrapData && chatBootstrapData.conversations) {
    const cObj = chatBootstrapData.conversations.find(c => parseInt(c.id, 10) === currentChatConvId);
    if (cObj && cObj.type === 'direct') {
      targetEmail = (cObj.other_email || cObj.target_email || currentChatOtherEmail || '').toLowerCase().trim();
    }
  } else if (currentChatOtherEmail) {
    targetEmail = currentChatOtherEmail.toLowerCase().trim();
  }
  if (targetEmail) {
    fd.append('target_email', targetEmail);
  }

  fd.append('message', text);

  if (stagedChatAttachment) {
    fd.append('attachment_url', stagedChatAttachment.url);
    fd.append('attachment_name', stagedChatAttachment.name);
    fd.append('attachment_size', stagedChatAttachment.size || 0);
    fd.append('attachment_type', stagedChatAttachment.type || '');
  }

  inp.value = '';
  inp.style.height = '42px';
  cancelStagedAttachment();

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      isChatSending = false;
      if (res.success && res.message) {
        if (res.conversation_id && parseInt(res.conversation_id, 10) !== currentChatConvId) {
          currentChatConvId = parseInt(res.conversation_id, 10);
        }
        renderMessages([res.message], true);

        if (chatBootstrapData && chatBootstrapData.conversations) {
          const cObj = chatBootstrapData.conversations.find(c => parseInt(c.id, 10) === currentChatConvId);
          if (cObj) {
            cObj.last_message = res.message.message || res.message.attachment_name;
            cObj.last_message_at = res.message.created_at;
          } else {
            loadChatBootstrap(currentChatConvId, true);
          }
          renderChatSidebar();
        }
        if (typeof triggerChatPollNow === 'function') triggerChatPollNow();
      } else {
        showToast(res.message || 'Mesaj gönderilemedi', 'error');
      }
    })
    .catch(err => {
      isChatSending = false;
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function handleChatInputKeyDown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChatMessage();
  } else {
    emitChatTyping();
  }
}

function handleChatInputAutoGrow(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 140) + 'px';
}

function emitChatTyping() {
  if (!currentChatConvId) return;
  clearTimeout(chatTypingTimeout);
  const fd = new FormData();
  fd.append('action', 'chat_set_typing');
  fd.append('conversation_id', currentChatConvId);
  fd.append('is_typing', '1');
  fetch('api.php', { method: 'POST', body: fd }).catch(() => {});

  chatTypingTimeout = setTimeout(() => {
    const fd2 = new FormData();
    fd2.append('action', 'chat_set_typing');
    fd2.append('conversation_id', currentChatConvId);
    fd2.append('is_typing', '0');
    fetch('api.php', { method: 'POST', body: fd2 }).catch(() => {});
  }, 4000);
}

// Reactions & Delete
function toggleChatReaction(msgId, reaction) {
  const fd = new FormData();
  fd.append('action', 'chat_toggle_reaction');
  fd.append('message_id', msgId);
  fd.append('reaction', reaction);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success && res.reactions) {
        updateMessageReactionsInDom(msgId, res.reactions);
      }
    })
    .catch(() => {});
}

function updateMessageReactionsInDom(msgId, reactions) {
  const msgEl = document.getElementById('chat-msg-' + msgId);
  if (!msgEl) return;
  const wrap = msgEl.querySelector('.chat-msg-bubble-wrap');
  if (!wrap) return;

  let row = wrap.querySelector('.chat-reactions-row');
  const myEmail = (chatBootstrapData && chatBootstrapData.my_profile) ? chatBootstrapData.my_profile.email.toLowerCase() : '';
  const rKeys = Object.keys(reactions);

  if (rKeys.length === 0) {
    if (row) row.remove();
    return;
  }

  let html = '';
  rKeys.forEach(rk => {
    const users = reactions[rk] || [];
    const hasMe = users.includes(myEmail);
    html += `
      <span class="chat-reaction-chip ${hasMe ? 'active' : ''}" onclick="toggleChatReaction(${msgId}, '${rk}')" title="${users.join(', ')}">
        <span>${rk}</span>
        <span style="font-size:0.7rem;font-weight:700;">${users.length}</span>
      </span>
    `;
  });

  if (!row) {
    row = document.createElement('div');
    row.className = 'chat-reactions-row';
    wrap.appendChild(row);
  }
  row.innerHTML = html;
}

function deleteChatMessage(msgId) {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Mesajı Sil',
      text: 'Bu mesajı silmek istediğinize emin misiniz?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Evet, Sil',
      cancelButtonText: 'Vazgeç',
      confirmButtonColor: '#ef4444'
    }).then(res => {
      if (res.isConfirmed) {
        executeDeleteMessage(msgId);
      }
    });
  } else if (confirm('Mesajı silmek istediğinize emin misiniz?')) {
    executeDeleteMessage(msgId);
  }
}

function executeDeleteMessage(msgId) {
  const fd = new FormData();
  fd.append('action', 'chat_delete_message');
  fd.append('message_id', msgId);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const el = document.getElementById('chat-msg-' + msgId);
        if (el) el.remove();
      } else {
        showToast(res.message || 'Silinemedi', 'error');
      }
    });
}

// Attachments upload
function triggerChatFileInput() {
  const fileInp = document.getElementById('chat-hidden-file-input');
  if (fileInp) {
    fileInp.value = '';
    fileInp.click();
  }
}

function handleChatFileSelected(e) {
  const files = e.target.files;
  if (!files || files.length === 0) return;
  const file = files[0];

  const fd = new FormData();
  fd.append('action', 'chat_upload_attachment');
  fd.append('file', file);

  showToast('Dosya yükleniyor...', 'info');

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        stagedChatAttachment = res;
        const bar = document.getElementById('chat-staged-attachment');
        const nameEl = document.getElementById('chat-staged-name');
        const sizeEl = document.getElementById('chat-staged-size');
        if (bar && nameEl) {
          nameEl.textContent = res.name;
          if (sizeEl) sizeEl.textContent = `(${formatBytes(res.size)})`;
          bar.style.display = 'flex';
        }
        showToast('Dosya eklendi, mesajınızı yazıp gönderebilirsiniz.', 'success');
      } else {
        showToast(res.message || 'Yükleme başarısız', 'error');
      }
    })
    .catch(err => {
      showToast('Yükleme hatası: ' + err.message, 'error');
    });
}

function cancelStagedAttachment() {
  stagedChatAttachment = null;
  const bar = document.getElementById('chat-staged-attachment');
  if (bar) bar.style.display = 'none';
}

// Lightbox for full size image
function openChatLightbox(url) {
  const modal = document.getElementById('modal-chat-lightbox');
  const img = document.getElementById('chat-lightbox-img');
  const download = document.getElementById('chat-lightbox-download');
  if (modal && img) {
    img.src = url;
    if (download) download.href = url;
    modal.style.display = 'flex';
  }
}

function closeChatLightbox(e) {
  if (e && e.target && e.target.tagName === 'IMG') return;
  const modal = document.getElementById('modal-chat-lightbox');
  if (modal) modal.style.display = 'none';
}

// Emoji Palette
const CHAT_EMOJIS = [
  '😀', '😃', '😄', '😁', '😆', '😅', '😂',
  '🤣', '😊', '😇', '🙂', '😉', '😍', '🥰',
  '😘', '😋', '😎', '🤩', '🥳', '😏', '🤔',
  '😴', '😷', '🤯', '😱', '👍', '👎', '👏',
  '🙌', '🤝', '✌️', '🤞', '👌', '🙏', '💪',
  '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤',
  '🔥', '⭐', '🎉', '🚀', '💡', '⚡', '🔔',
  '📌', '✅', '❌', '☕', '💻', '📱', '✉️'
];

function toggleChatEmojiPalette(e) {
  if (e) e.stopPropagation();
  const palette = document.getElementById('chat-emoji-palette');
  if (!palette) return;

  if (palette.style.display === 'block') {
    palette.style.display = 'none';
  } else {
    const grid = document.getElementById('chat-emoji-grid');
    if (grid && !grid.children.length) {
      grid.innerHTML = CHAT_EMOJIS.map(em => `
        <button type="button" class="chat-emoji-item" onclick="insertEmoji('${em}')">${em}</button>
      `).join('');
    }
    palette.style.display = 'block';

    const hideOnClickOutside = function(evt) {
      if (!palette.contains(evt.target)) {
        palette.style.display = 'none';
        document.removeEventListener('click', hideOnClickOutside);
      }
    };
    setTimeout(() => document.addEventListener('click', hideOnClickOutside), 10);
  }
}

function insertEmoji(emoji) {
  const inp = document.getElementById('chat-message-input');
  if (!inp) return;
  const start = inp.selectionStart || inp.value.length;
  const end = inp.selectionEnd || inp.value.length;
  inp.value = inp.value.substring(0, start) + emoji + inp.value.substring(end);
  inp.selectionStart = inp.selectionEnd = start + emoji.length;
  inp.focus();
  const palette = document.getElementById('chat-emoji-palette');
  if (palette) palette.style.display = 'none';
}

// Presence status change
function togglePresenceMenu(e) {
  if (e) e.stopPropagation();
  const menu = document.getElementById('chat-presence-dropdown');
  if (!menu) return;
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';

  const closeMenu = (evt) => {
    if (!menu.contains(evt.target)) {
      menu.style.display = 'none';
      document.removeEventListener('click', closeMenu);
    }
  };
  setTimeout(() => document.addEventListener('click', closeMenu), 10);
}

function changeChatPresence(status) {
  const fd = new FormData();
  fd.append('action', 'chat_set_status');
  fd.append('status', status);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if (chatBootstrapData && chatBootstrapData.my_profile) {
          chatBootstrapData.my_profile.status = status;
        }
        const btnDot = document.getElementById('chat-presence-btn-dot');
        if (btnDot) btnDot.className = 'status-dot ' + status;

        const myDot = document.getElementById('chat-my-status-dot');
        if (myDot) myDot.className = 'status-dot ' + status;

        const myStatusLabel = document.getElementById('chat-my-custom-status-text');
        if (myStatusLabel) myStatusLabel.textContent = getStatusLabel(status);

        showToast('Durum güncellendi: ' + getStatusLabel(status), 'success');
      }
    });
}

function promptCustomStatus() {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Durum İletisi Belirle',
      input: 'text',
      inputPlaceholder: 'Örn: Toplantıda, Öğle arasında, Sahada...',
      inputValue: (chatBootstrapData && chatBootstrapData.my_profile && chatBootstrapData.my_profile.custom_status) || '',
      showCancelButton: true,
      confirmButtonText: 'Kaydet',
      cancelButtonText: 'Vazgeç'
    }).then(res => {
      if (res.isConfirmed) {
        const text = (res.value || '').trim();
        const fd = new FormData();
        fd.append('action', 'chat_set_status');
        fd.append('custom_status', text);
        fetch('api.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              if (chatBootstrapData && chatBootstrapData.my_profile) {
                chatBootstrapData.my_profile.custom_status = text;
              }
              const myStatusLabel = document.getElementById('chat-my-custom-status-text');
              if (myStatusLabel) {
                myStatusLabel.textContent = text || getStatusLabel(chatBootstrapData.my_profile.status);
              }
              showToast('Durum iletisi kaydedildi.', 'success');
            }
          });
      }
    });
  }
}

// Start direct chat with colleague
function startDirectChat(targetEmail) {
  targetEmail = (targetEmail || '').toLowerCase().trim();
  if (!targetEmail) {
    showToast('Geçersiz kullanıcı', 'error');
    return;
  }
  currentChatOtherEmail = targetEmail;

  const fd = new FormData();
  fd.append('action', 'chat_start_direct');
  fd.append('target_email', targetEmail);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success && res.conversation_id) {
        if (chatBootstrapData && chatBootstrapData.conversations) {
          if (res.conversation) {
            chatBootstrapData.conversations = chatBootstrapData.conversations.filter(c => parseInt(c.id, 10) !== parseInt(res.conversation.id, 10));
            chatBootstrapData.conversations.unshift(res.conversation);
          }
          renderChatSidebar();
        }
        selectConversation(res.conversation_id, targetEmail);
      } else {
        showToast(res.message || 'Mesajlaşma başlatılamadı', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

// (Grup ve kanal ozellikleri devredisi - yalnizca birebir mesajlasma)


// Periodic Adaptive Heartbeat (chained with single in-flight lock)
function runChatHeartbeat() {
  if (isChatHeartbeatRunning) {
    scheduleChatHeartbeat();
    return;
  }
  isChatHeartbeatRunning = true;

  const isChatActive = (window.currentActiveTab === 'chat') ? 1 : 0;
  const fd = new FormData();
  fd.append('action', 'chat_heartbeat');
  fd.append('is_chat_active', isChatActive);

  if (isChatActive && currentChatConvId) {
    fd.append('active_conv_id', currentChatConvId);
    fd.append('after_msg_id', chatLastMsgId);
  }

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      isChatHeartbeatRunning = false;
      scheduleChatHeartbeat();

      if (!res.success) return;

      // 1. Incoming new messages in active conversation
      if (res.new_messages && res.new_messages.length > 0) {
        renderMessages(res.new_messages, true);

        const hasIncoming = res.new_messages.some(m => !m.is_me);
        if (hasIncoming) {
          playChatNotificationSound();
          if (document.hidden) {
            showChatDesktopNotification();
            setChatTitleNotification(res.total_unread || 1);
          }
        }
      }

      // 2. Interactive Notification if a new incoming message arrived from someone else
      if (res.latest_incoming && res.latest_incoming.id) {
        const incId = parseInt(res.latest_incoming.id, 10);
        const incConvId = parseInt(res.latest_incoming.conversation_id, 10);

        if (incId > lastNotifiedIncomingMsgId) {
          const isCurrentlyViewingConv = (window.currentActiveTab === 'chat' && parseInt(currentChatConvId, 10) === incConvId && !document.hidden);
          if (!isCurrentlyViewingConv) {
            playChatNotificationSound();
            if (document.hidden) {
              showChatDesktopNotification();
            }
            showIncomingChatToast(res.latest_incoming);
            setChatTitleNotification(res.total_unread || 1);
          }
          lastNotifiedIncomingMsgId = incId;
        }
      }

      // 3. Typing indicator in active conversation
      const typingBanner = document.getElementById('chat-typing-banner');
      const typingText = document.getElementById('chat-typing-text');
      if (typingBanner && typingText) {
        if (res.typing && res.typing.length > 0) {
          const names = res.typing.map(t => t.name).join(', ');
          typingText.textContent = names + ' yazıyor...';
          typingBanner.style.display = 'flex';
        } else {
          typingBanner.style.display = 'none';
        }
      }

      // 4. Unread counts & Badges
      if (res.total_unread !== undefined) {
        if (chatBootstrapData) chatBootstrapData.total_unread = res.total_unread;
        updateChatBadges(res.total_unread);
        if (res.total_unread === 0 && window.currentActiveTab === 'chat') {
          clearChatTitleNotification();
        }
      }

      if (res.unread_map && chatBootstrapData && chatBootstrapData.conversations) {
        chatBootstrapData.conversations.forEach(c => {
          c.unread_count = res.unread_map[c.id] || 0;
        });
      }

      // 5. Conversations Live Sync
      if (res.conversations && res.conversations.length > 0 && chatBootstrapData) {
        const prevCount = (chatBootstrapData.conversations || []).length;
        const newCount = res.conversations.length;
        const prevFirstId = (chatBootstrapData.conversations && chatBootstrapData.conversations[0]) ? chatBootstrapData.conversations[0].id : 0;
        const newFirstId = (res.conversations[0]) ? res.conversations[0].id : 0;
        const prevLatestMsg = (chatBootstrapData.conversations && chatBootstrapData.conversations[0]) ? chatBootstrapData.conversations[0].last_message_at : '';
        const newLatestMsg = (res.conversations[0]) ? res.conversations[0].last_message_at : '';

        chatBootstrapData.conversations = res.conversations;

        if (prevCount !== newCount || prevFirstId !== newFirstId || prevLatestMsg !== newLatestMsg) {
          renderChatSidebar();
        }
      }

      // 6. Online Presence Updates
      if (res.presence_map && Object.keys(res.presence_map).length > 0 && chatBootstrapData && chatBootstrapData.colleagues) {
        chatBootstrapData.colleagues.forEach(col => {
          const p = res.presence_map[col.email.toLowerCase()];
          if (p) {
            col.status = p.status;
            col.custom_status = p.custom_status;
            col.last_seen = p.last_seen;
          }
        });
        renderChatSidebar();
      }
    })
    .catch(() => {
      isChatHeartbeatRunning = false;
      scheduleChatHeartbeat();
    });
}

/* ==========================================================================
   Chat Lock System - Sohbet Kilidi Kaldırıldı (Genel Ekran Kilidi Aktif)
   ========================================================================== */
function isChatUnlocked() {
  return true;
}

function unlockChat() {
  const overlay = document.getElementById('chat-lock-overlay');
  if (overlay) overlay.style.display = 'none';
}

function lockChat(manual = false) {
  // Sohbet kilidi yerine uygulamanın genel Ekran Kilitleme fonksiyonu çağrılır
  if (typeof lockScreen === 'function') {
    lockScreen();
  }
}

function promptChangeChatPin() {}
function updateChatLockBtnUI() {}

function executeServerDelete(uid, folder) {
  const formData = new FormData();
  formData.append('action', 'delete_email');
  formData.append('folder', folder || currentFolder);
  formData.append('uid', uid);

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      removeMailItemFromList(uid);
      if (typeof updateFolderCounts === 'function') updateFolderCounts();
    })
    .catch(() => {});
}

function buildReplyQuote(m) {
  if (!m) return '';
  const fromStr = escapeHtml(m.from ? (m.from.name && m.from.email && m.from.name !== m.from.email ? `${m.from.name} <${m.from.email}>` : (m.from.email || m.from.name || '')) : '');
  const rawQuoteHtml = m.body_html || (m.body_plain ? `<pre style="font-family:inherit;white-space:pre-wrap;margin:0;">${escapeHtml(m.body_plain)}</pre>` : (m.body || ''));
  const quoteBody = isolateEmailHtmlForEditor(rawQuoteHtml);
  const sigHtml = window.USER_SIGNATURE ? `<p><br></p><div class="signature-block-in-mail">--<br>${isolateEmailHtmlForEditor(window.USER_SIGNATURE)}</div>` : '';
  const wroteLabel = (typeof t === 'function' ? t('mail_reply_header', 'yazdı:') : 'yazdı:');
  return `<p><br></p>${sigHtml}<p><br></p><blockquote style="margin:16px 0;padding:0 0 0 12px;border-left:2px solid var(--border-color, #cbd5e1);color:var(--text-muted, #64748b);font-size:0.92em;"><p style="margin:0 0 8px 0;color:var(--text-secondary, #475569);"><b>${escapeHtml(m.date || '')}, ${fromStr} ${wroteLabel}</b></p>${quoteBody}</blockquote>`;
}

function replyEmail(uidOrTo, sub) {
  if (typeof uidOrTo === 'number' || (typeof uidOrTo === 'string' && /^\d+$/.test(uidOrTo))) {
    const m = cachedMessages[uidOrTo];
    if (m) {
      const myEmail = (window.CURRENT_USER_EMAIL || '').toLowerCase().trim();
      const fromEmail = (m.from && m.from.email ? m.from.email : '').toLowerCase().trim();
      let toRecipient = '';
      if (myEmail && fromEmail === myEmail) {
        toRecipient = m.to ? (m.to.name && m.to.email && m.to.name !== m.to.email ? `${m.to.name} <${m.to.email}>` : (m.to.email || m.to.name)) : '';
      } else {
        toRecipient = m.from ? (m.from.name && m.from.email && m.from.name !== m.from.email ? `${m.from.name} <${m.from.email}>` : (m.from.email || m.from.name)) : '';
      }
      const origSubject = m.subject || '';
      const subject = origSubject.toLowerCase().startsWith('re:') ? origSubject : 'Re: ' + origSubject;
      const quote = buildReplyQuote(m);
      openComposeModal(toRecipient, subject, quote);
      return;
    }
  }
  const subject = sub ? (unescape(sub).toLowerCase().startsWith('re:') ? unescape(sub) : 'Re: ' + unescape(sub)) : 'Re: ';
  openComposeModal(uidOrTo, subject);
}

function replyAllEmail(uid) {
  const m = cachedMessages[uid];
  if (!m) return;

  const myEmail = (window.CURRENT_USER_EMAIL || '').toLowerCase().trim();
  const fromEmail = (m.from && m.from.email ? m.from.email : '').toLowerCase().trim();

  const seenEmails = new Set();
  if (myEmail) seenEmails.add(myEmail);

  function filterAddresses(list, rawStr) {
    const results = [];
    if (Array.isArray(list) && list.length > 0) {
      list.forEach(item => {
        const email = (item.email || '').trim();
        const emLower = email.toLowerCase();
        if (emLower && !seenEmails.has(emLower)) {
          seenEmails.add(emLower);
          const name = (item.name || '').trim();
          results.push(name && name !== email ? `${name} <${email}>` : email);
        }
      });
    } else if (rawStr && typeof rawStr === 'string' && rawStr.trim() !== '') {
      const parts = rawStr.split(/[,;]+/);
      parts.forEach(p => {
        const trimmed = p.trim();
        if (!trimmed) return;
        let email = trimmed;
        let name = '';
        const match = trimmed.match(/^(.*?)\s*<([^>]+)>/);
        if (match) {
          name = match[1].trim().replace(/^["']|["']$/g, '');
          email = match[2].trim();
        }
        const emLower = email.toLowerCase();
        if (emLower && !seenEmails.has(emLower)) {
          seenEmails.add(emLower);
          results.push(name && name !== email ? `${name} <${email}>` : email);
        }
      });
    }
    return results;
  }

  const isSentByMe = myEmail && fromEmail === myEmail;
  let toRecipient = '';
  let ccRecipients = [];

  if (isSentByMe) {
    const originalTo = filterAddresses(m.to_list, (m.to ? (m.to.name ? `${m.to.name} <${m.to.email}>` : m.to.email) : ''));
    toRecipient = originalTo.join(', ');
    ccRecipients = filterAddresses(m.cc_list, m.cc);
  } else {
    toRecipient = m.from ? (m.from.name && m.from.email && m.from.name !== m.from.email ? `${m.from.name} <${m.from.email}>` : (m.from.email || m.from.name)) : '';
    if (fromEmail) seenEmails.add(fromEmail);

    const otherTo = filterAddresses(m.to_list, (m.to ? (m.to.name ? `${m.to.name} <${m.to.email}>` : m.to.email) : ''));
    const origCc = filterAddresses(m.cc_list, m.cc);
    ccRecipients = [...otherTo, ...origCc];
  }

  // Include original BCC recipients if any exist
  const bccRecipients = filterAddresses(m.bcc_list, m.bcc);

  const origSubject = m.subject || '';
  const subject = origSubject.toLowerCase().startsWith('re:') ? origSubject : 'Re: ' + origSubject;
  const quote = buildReplyQuote(m);

  openComposeModal(
    toRecipient,
    subject,
    quote,
    ccRecipients.join(', '),
    bccRecipients.join(', ')
  );
}

function forwardEmail(uid) {
  let m = (window.currentViewedEmail && window.currentViewedEmail.uid == uid) ? window.currentViewedEmail : cachedMessages[uid];
  if (!m) return;

  const openFwdWithMail = (mailObj) => {
    const fromStr = escapeHtml(mailObj.from ? (mailObj.from.name && mailObj.from.email && mailObj.from.name !== mailObj.from.email ? `${mailObj.from.name} <${mailObj.from.email}>` : (mailObj.from.email || mailObj.from.name || '')) : '');
    const rawQuoteHtml = mailObj.body_html || (mailObj.body_plain ? `<pre style="font-family:inherit;white-space:pre-wrap;margin:0;">${escapeHtml(mailObj.body_plain)}</pre>` : (mailObj.body || ''));
    const quoteBody = isolateEmailHtmlForEditor(rawQuoteHtml);
    const sigHtml = window.USER_SIGNATURE ? `<p><br></p><div class="signature-block-in-mail">--<br>${isolateEmailHtmlForEditor(window.USER_SIGNATURE)}</div>` : '';
    const origSubject = mailObj.subject || '';
    const fwdSubject = origSubject.toLowerCase().startsWith('fwd:') ? origSubject : 'Fwd: ' + origSubject;
    const fwdHeader = `<p><br></p>${sigHtml}<p><br></p><hr style="border:0;border-top:1px solid #cbd5e1;margin:15px 0;"><p><b>-------- İletilen İleti --------</b><br><b>Kimden:</b> ${fromStr}<br><b>Tarih:</b> ${escapeHtml(mailObj.date || '')}<br><b>Konu:</b> ${escapeHtml(origSubject)}</p><br>` + quoteBody;
    const targetFolder = mailObj.folder || currentViewEmailFolder || currentFolder || 'INBOX';
    const fwdAttachments = (mailObj.attachments || []).map(a => ({
      folder: targetFolder,
      uid: mailObj.uid || uid,
      part_no: a.part_no || 1,
      filename: a.filename || 'ek_dosya',
      size: a.size || ''
    }));
    openComposeModal('', fwdSubject, fwdHeader, '', '', fwdAttachments);
  };

  if (!Array.isArray(m.attachments) && m.body_html === undefined) {
    const targetFolder = m.folder || currentViewEmailFolder || currentFolder || 'INBOX';
    fetch(`api.php?action=view_email&folder=${encodeURIComponent(targetFolder)}&uid=${uid}`)
      .then(r => r.json())
      .then(data => {
        if (data && data.success && data.email) {
          if (!data.email.folder) data.email.folder = targetFolder;
          cachedMessages[uid] = data.email;
          openFwdWithMail(data.email);
        } else {
          openFwdWithMail(m);
        }
      })
      .catch(() => openFwdWithMail(m));
    return;
  }

  openFwdWithMail(m);
}

function resumeDraft(uid) {
  let m = (window.currentViewedEmail && window.currentViewedEmail.uid == uid) ? window.currentViewedEmail : cachedMessages[uid];
  if (!m) return;
  const toList = (m.to_list && m.to_list.length) ? m.to_list : (m.to ? (m.to.name && m.to.email && m.to.name !== m.to.email ? `${m.to.name} <${m.to.email}>` : (m.to.email || m.to.name || '')) : '');
  const ccList = (m.cc_list && m.cc_list.length) ? m.cc_list : (m.cc || '');
  const bccList = (m.bcc_list && m.bcc_list.length) ? m.bcc_list : (m.bcc || '');
  const subject = m.subject || '';
  const rawBody = m.body_html || (m.body_plain ? escapeHtml(m.body_plain).replace(/\n/g, '<br>') : '');
  const body = isolateEmailHtmlForEditor(rawBody);
  const targetFolder = m.folder || currentViewEmailFolder || currentFolder || 'Drafts';
  const draftAttachments = (m.attachments || []).map(a => ({
    folder: targetFolder,
    uid: m.uid || uid,
    part_no: a.part_no || 1,
    filename: a.filename || 'ek_dosya',
    size: a.size || ''
  }));

  currentDraftUid = m.uid;
  openComposeModal(toList, subject, body, ccList, bccList, draftAttachments);
}

// COMPOSE & RICH TEXT EDITOR
let select2Initialized = false;

function initSelect2Recipients() {
  if (typeof $ === 'undefined' || typeof $.fn.select2 === 'undefined') return;

  const fields = [
    { id: '#compose-to', ph: 'Alıcıları yazın veya rehberden seçin...' },
    { id: '#compose-cc', ph: 'Cc (Bilgi) alıcıları...' },
    { id: '#compose-bcc', ph: 'Bcc (Gizli) alıcıları...' }
  ];

  fields.forEach(f => {
    const $el = $(f.id);
    if (!$el.hasClass('select2-hidden-accessible')) {
      $el.select2({
        tags: true,
        tokenSeparators: [',', ';'],
        language: 'tr',
        placeholder: f.ph,
        dropdownParent: $('#compose-modal'),
        ajax: {
          url: 'api.php?action=autocomplete_contacts',
          dataType: 'json',
          delay: 200,
          data: function(params) {
            return { q: params.term || '' };
          },
          processResults: function(data) {
            return { results: data.results || [] };
          },
          cache: true
        },
        templateResult: function(item) {
          if (item.loading) return item.text;
          if (!item.email) return $('<span>').text(item.text || item.id);
          const name = item.name ? $('<div>').text(item.name).html() : '';
          const email = $('<div>').text(item.email).html();
          const company = item.company ? `<span class="contact-pill-company">${$('<div>').text(item.company).html()}</span>` : '';
          const isGlobal = item.is_global ? '<span class="contact-pill-tag">Sunucu</span>' : '';
          return $(`
            <div class="select2-contact-item">
              <div class="select2-contact-avatar" style="background:${item.avatar_color || 'var(--primary)'}">
                ${(item.name || item.email).charAt(0).toUpperCase()}
              </div>
              <div class="select2-contact-info">
                <div class="select2-contact-name">${name || email} ${company} ${isGlobal}</div>
                <div class="select2-contact-email">${email}</div>
              </div>
            </div>
          `);
        },
        templateSelection: function(item) {
          return item.email || item.text || item.id;
        },
        createTag: function(params) {
          let term = $.trim(params.term).replace(/[,;]/g, '');
          if (!term) return null;
          return { id: term, text: term, isNew: true };
        }
      });
    }
  });
  select2Initialized = true;
}

function toggleMaximizeCompose() {
  const win = document.querySelector('#compose-modal .compose-window');
  const btn = document.getElementById('btn-maximize-compose');
  if (!win) return;
  win.classList.toggle('is-maximized');
  const isMax = win.classList.contains('is-maximized');
  if (btn) {
    btn.innerHTML = isMax
      ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M10 14L3 21"/></svg>'
      : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';
  }
}

// ATTACHMENTS STATE & DROPZONE
let composeAttachments = [];
let composeForwardedAttachments = [];

function addAttachments(files) {
  if (!files || !files.length) return;
  for (let i = 0; i < files.length; i++) {
    const f = files[i];
    if (!composeAttachments.some(ex => ex.name === f.name && ex.size === f.size)) {
      composeAttachments.push(f);
    }
  }
  renderComposeAttachments();
}

function removeAttachment(index) {
  composeAttachments.splice(index, 1);
  renderComposeAttachments();
}

function removeForwardedAttachment(index) {
  composeForwardedAttachments.splice(index, 1);
  renderComposeAttachments();
}

function renderComposeAttachments() {
  const box = document.getElementById('compose-file-list');
  if (!box) return;
  const totalCount = (composeForwardedAttachments ? composeForwardedAttachments.length : 0) + (composeAttachments ? composeAttachments.length : 0);
  if (totalCount === 0) {
    box.innerHTML = '';
    box.style.display = 'none';
    return;
  }
  box.style.display = 'flex';
  box.innerHTML = '';

  if (composeForwardedAttachments && composeForwardedAttachments.length > 0) {
    composeForwardedAttachments.forEach((att, idx) => {
      const chip = document.createElement('div');
      chip.className = 'compose-attach-chip';
      chip.title = `${att.filename} (İletilen Ek)`;
      chip.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--primary);flex-shrink:0;"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        <span class="chip-name" title="${escapeHtml(att.filename)}">${escapeHtml(att.filename)}</span>
        ${att.size ? `<span class="chip-size">(${escapeHtml(att.size)})</span>` : ''}
        <span class="chip-remove" onclick="removeForwardedAttachment(${idx})" title="Eki Kaldır">&times;</span>
      `;
      box.appendChild(chip);
    });
  }

  composeAttachments.forEach((f, idx) => {
    const sizeStr = f.size > 1048576 
      ? (f.size / 1048576).toFixed(1) + ' MB' 
      : Math.round(f.size / 1024) + ' KB';
    const chip = document.createElement('div');
    chip.className = 'compose-attach-chip';
    chip.innerHTML = `
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
      <span class="chip-name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span>
      <span class="chip-size">(${sizeStr})</span>
      <span class="chip-remove" onclick="removeAttachment(${idx})" title="Eki Kaldır">&times;</span>
    `;
    box.appendChild(chip);
  });
}

function onFilesSelected(e) {
  if (e.target.files) addAttachments(e.target.files);
  e.target.value = '';
}

function setupComposeDropzone() {
  const dz = document.getElementById('compose-dropzone');
  const win = document.querySelector('.compose-window');
  if (!dz || !win) return;

  ['dragenter', 'dragover'].forEach(evt => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.add('drag-active');
    });
    win.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
    });
  });

  ['dragleave', 'drop'].forEach(evt => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.remove('drag-active');
    });
    win.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
    });
  });

  dz.addEventListener('drop', (e) => {
    if (e.dataTransfer && e.dataTransfer.files) {
      addAttachments(e.dataTransfer.files);
    }
  });

  win.addEventListener('drop', (e) => {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      addAttachments(e.dataTransfer.files);
    }
  });
}

function openComposeModal(to = '', subject = '', body = '', cc = '', bcc = '', forwardedAttachments = []) {
  document.getElementById('compose-modal').classList.add('active');
  initSelect2Recipients();

  $('#compose-to').val(null).trigger('change');
  $('#compose-cc').val(null).trigger('change');
  $('#compose-bcc').val(null).trigger('change');
  document.getElementById('cc-row').style.display = 'none';
  document.getElementById('bcc-row').style.display = 'none';
  const btnCc = document.getElementById('btn-show-cc');
  const btnBcc = document.getElementById('btn-show-bcc');
  if (btnCc) btnCc.style.display = 'inline-block';
  if (btnBcc) btnBcc.style.display = 'inline-block';

  function populateSelect2Field(selector, rowId, btnId, val) {
    if (!val) return;
    let list = [];
    if (Array.isArray(val)) {
      list = val;
    } else if (typeof val === 'string') {
      list = val.split(/[,;]+/).map(s => s.trim()).filter(Boolean);
    }
    if (!list.length) return;

    const $select = $(selector);
    const selectEl = $select[0];
    if (!selectEl) return;

    const selectedValues = [];

    list.forEach(item => {
      let addr = '';
      let label = '';
      if (typeof item === 'object' && item !== null) {
        addr = (item.email || item.name || '').trim();
        const nm = (item.name || '').trim();
        label = (nm && addr && nm !== addr) ? `${nm} <${addr}>` : addr;
      } else if (typeof item === 'string') {
        const str = item.trim();
        if (!str) return;
        const m = str.match(/^(.*?)\s*<([^>]+)>/);
        if (m) {
          label = str;
          addr = m[2].trim();
        } else {
          addr = str;
          label = str;
        }
      }
      if (!addr) return;

      const exists = Array.from(selectEl.options).some(opt => opt.value === addr);
      if (!exists) {
        const newOption = new Option(label, addr, true, true);
        $select.append(newOption);
      }
      selectedValues.push(addr);
    });

    if (selectedValues.length > 0) {
      $select.val(selectedValues).trigger('change');
      if (rowId) {
        const row = document.getElementById(rowId);
        if (row) row.style.display = 'flex';
      }
      if (btnId) {
        const btn = document.getElementById(btnId);
        if (btn) btn.style.display = 'none';
      }
    }
  }

  if (to) {
    populateSelect2Field('#compose-to', null, null, to);
  }
  if (cc) {
    populateSelect2Field('#compose-cc', 'cc-row', 'btn-show-cc', cc);
  }
  if (bcc) {
    populateSelect2Field('#compose-bcc', 'bcc-row', 'btn-show-bcc', bcc);
  }

  document.getElementById('compose-subject').value = subject;
  
  const editor = document.getElementById('compose-editor');
  const htmlView = document.getElementById('compose-html-source');
  if (htmlView) htmlView.style.display = 'none';
  if (editor) editor.style.display = 'block';

  // If opening fresh compose (no body provided) and user has a saved signature, insert it with spacing!
  if (!body && window.USER_SIGNATURE) {
    editor.innerHTML = `<p><br></p><p><br></p><div class="signature-block-in-mail">--<br>${isolateEmailHtmlForEditor(window.USER_SIGNATURE)}</div>`;
  } else {
    editor.innerHTML = (body && /<\s*(style|link|base|script|iframe|meta)\b/i.test(body))
      ? isolateEmailHtmlForEditor(body)
      : body;
  }

  if (editor && !editor.dataset.cssGuardBound) {
    editor.dataset.cssGuardBound = '1';
    editor.addEventListener('input', () => {
      const dangerousNodes = editor.querySelectorAll('style, link, script, base, meta, title, iframe');
      if (dangerousNodes.length > 0) {
        editor.innerHTML = isolateEmailHtmlForEditor(editor.innerHTML);
      }
    });
  }

  // Clear / init attachments
  composeAttachments = [];
  composeForwardedAttachments = Array.isArray(forwardedAttachments) ? [...forwardedAttachments] : [];
  renderComposeAttachments();

  // Reset / init draft status
  isDraftSavedRecently = false;
  const statusEl = document.getElementById('draft-save-status');
  if (statusEl) {
    if (currentDraftUid) {
      statusEl.style.display = 'inline-flex';
      statusEl.textContent = typeof t === 'function' ? t('compose_draft_saved', 'Taslak') : 'Taslak';
    } else {
      statusEl.style.display = 'none';
      statusEl.textContent = '';
    }
  }
  const btnSaveDraft = document.getElementById('btn-save-draft');
  if (btnSaveDraft) btnSaveDraft.disabled = false;

  setTimeout(() => {
    if (editor) editor.focus();
  }, 150);
}

let currentDraftUid = null;
let isDraftSavedRecently = false;
let isDraftSaving = false;

function closeComposeModal(force = false) {
  if (!force) {
    const editor = document.getElementById('compose-editor');
    const subj = document.getElementById('compose-subject').value.trim();
    const toList = $('#compose-to').val() || [];
    const ccList = $('#compose-cc').val() || [];
    const bccList = $('#compose-bcc').val() || [];
    const bodyText = (editor ? editor.innerText || '' : '').trim();
    
    const hasContent = toList.length > 0 || ccList.length > 0 || bccList.length > 0 || subj.length > 0 || (bodyText.length > 0 && (!window.USER_SIGNATURE || !window.USER_SIGNATURE.includes(bodyText)));
    if (hasContent && !isDraftSavedRecently) {
      swalConfirm(
        typeof t === 'function' ? t('save_draft_confirm_title', 'Taslak Kaydedilsin mi?') : 'Taslak Kaydedilsin mi?',
        typeof t === 'function' ? t('save_draft_confirm_desc', 'Kapatmadan önce bu iletiyi taslak olarak kaydetmek ister misiniz?') : 'Kapatmadan önce bu iletiyi taslak olarak kaydetmek ister misiniz?',
        typeof t === 'function' ? t('save_draft_yes', 'Taslağı Kaydet') : 'Taslağı Kaydet',
        'question'
      ).then(res => {
        if (res && res.isConfirmed) {
          saveDraft(true);
        } else {
          doCloseComposeModal();
        }
      });
      return;
    }
  }
  doCloseComposeModal();
}

function doCloseComposeModal() {
  document.getElementById('compose-modal').classList.remove('active');
  const editor = document.getElementById('compose-editor');
  if (editor) editor.innerHTML = '';
  const htmlView = document.getElementById('compose-html-source');
  if (htmlView) {
    htmlView.value = '';
    htmlView.style.display = 'none';
  }
  currentDraftUid = null;
  isDraftSavedRecently = false;
  composeAttachments = [];
  composeForwardedAttachments = [];
  const statusEl = document.getElementById('draft-save-status');
  if (statusEl) {
    statusEl.style.display = 'none';
    statusEl.textContent = '';
  }
}

// RICH TEXT EDITOR HELPERS
function formatDoc(cmd, val = null) {
  const ed = document.getElementById('compose-editor');
  if (!ed) return;
  ed.focus();
  document.execCommand(cmd, false, val);
}

function insertImageModal() {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Resim (Görsel) Ekle',
      html: `
        <input id="swal-img-url" class="swal2-input" placeholder="Görsel URL (https://...)" style="border-radius:0 !important;">
        <div style="margin:12px 0 6px;font-size:0.8rem;color:var(--text-muted);">veya bilgisayarınızdan seçin:</div>
        <input type="file" id="swal-img-file" accept="image/*" class="swal2-file" style="border-radius:0 !important;width:80%;">
      `,
      showCancelButton: true,
      confirmButtonText: 'Ekle',
      cancelButtonText: 'İptal',
      didOpen: () => {
        const fileInput = document.getElementById('swal-img-file');
        const urlInput = document.getElementById('swal-img-url');
        fileInput.addEventListener('change', () => {
          if (fileInput.files && fileInput.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
              urlInput.value = e.target.result;
            };
            reader.readAsDataURL(fileInput.files[0]);
          }
        });
      },
      preConfirm: () => {
        const url = document.getElementById('swal-img-url').value.trim();
        if (!url) {
          Swal.showValidationMessage('Lütfen bir resim adresi girin veya dosya seçin');
          return false;
        }
        return url;
      }
    }).then(res => {
      if (res.isConfirmed && res.value) {
        const ed = document.getElementById('compose-editor');
        if (ed) {
          ed.focus();
          document.execCommand('insertHTML', false, `<p><img src="${res.value}" style="max-width:100%;height:auto;margin:8px 0;border:1px solid #cbd5e1;" /></p><p><br></p>`);
        }
      }
    });
  } else {
    const url = prompt('Resim URL adresi:');
    if (url) formatDoc('insertImage', url);
  }
}

function insertLinkModal() {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Bağlantı (Link) Ekle',
      html: `
        <input id="swal-link-url" class="swal2-input" placeholder="https://example.com" style="border-radius:0 !important;">
        <input id="swal-link-text" class="swal2-input" placeholder="Görünecek Metin (İsteğe Bağlı)" style="border-radius:0 !important;">
      `,
      showCancelButton: true,
      confirmButtonText: 'Ekle',
      cancelButtonText: 'İptal',
      focusConfirm: false,
      preConfirm: () => {
        const url = document.getElementById('swal-link-url').value.trim();
        const text = document.getElementById('swal-link-text').value.trim();
        if (!url) {
          Swal.showValidationMessage('Lütfen geçerli bir URL adresi girin');
          return false;
        }
        return { url, text };
      }
    }).then(result => {
      if (result.isConfirmed && result.value) {
        const { url, text } = result.value;
        const ed = document.getElementById('compose-editor');
        ed.focus();
        if (text) {
          document.execCommand('insertHTML', false, `<a href="${url}" target="_blank" style="color:var(--primary);text-decoration:underline;">${text}</a>`);
        } else {
          document.execCommand('createLink', false, url);
        }
      }
    });
  } else {
    const url = prompt('Link URL (https://...):');
    if (url) formatDoc('createLink', url);
  }
}

function insertTablePrompt() {
  const tableHtml = `
    <table style="border-collapse:collapse;width:100%;margin:10px 0;border:1px solid #cbd5e1;">
      <tbody>
        <tr>
          <td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;font-weight:bold;">Başlık 1</td>
          <td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;font-weight:bold;">Başlık 2</td>
          <td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;font-weight:bold;">Başlık 3</td>
        </tr>
        <tr>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 1</td>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 2</td>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 3</td>
        </tr>
        <tr>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 4</td>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 5</td>
          <td style="border:1px solid #cbd5e1;padding:8px;">Hücre 6</td>
        </tr>
      </tbody>
    </table>
    <p><br></p>
  `;
  formatDoc('insertHTML', tableHtml);
}

function insertUserSignature() {
  if (!window.USER_SIGNATURE) {
    showToast('Kayıtlı bir imzanız bulunmuyor. Ayarlar > İmza bölümünden ekleyebilirsiniz.', 'warning');
    return;
  }
  const sigHtml = `<div class="signature-block-in-mail" style="margin-top:1.5rem;">--<br>${isolateEmailHtmlForEditor(window.USER_SIGNATURE)}</div>`;
  formatDoc('insertHTML', sigHtml);
}

function toggleSourceCode() {
  const ed = document.getElementById('compose-editor');
  const src = document.getElementById('compose-html-source');
  const btn = document.getElementById('btn-toggle-source');
  if (!ed || !src) return;

  if (src.style.display === 'none' || !src.style.display) {
    src.value = ed.innerHTML;
    ed.style.display = 'none';
    src.style.display = 'block';
    if (btn) btn.classList.add('active');
    src.focus();
  } else {
    ed.innerHTML = /<\s*(style|link|base|script|iframe|meta)\b/i.test(src.value)
      ? isolateEmailHtmlForEditor(src.value)
      : src.value;
    src.style.display = 'none';
    ed.style.display = 'block';
    if (btn) btn.classList.remove('active');
    ed.focus();
  }
}

function sendMail(e) {
  e.preventDefault();
  const toList = $('#compose-to').val() || [];
  if (!toList.length) {
    showToast('Lütfen en az bir alıcı (Kime) girin veya rehberden seçin.', 'error');
    return;
  }

  // Sync HTML source view if currently active
  const htmlView = document.getElementById('compose-html-source');
  const editor = document.getElementById('compose-editor');
  if (htmlView && htmlView.style.display !== 'none') {
    editor.innerHTML = /<\s*(style|link|base|script|iframe|meta)\b/i.test(htmlView.value)
      ? isolateEmailHtmlForEditor(htmlView.value)
      : htmlView.value;
  }

  const rawText = (editor ? editor.innerText || '' : '').trim();
  const rawHtml = (editor ? editor.innerHTML || '' : '')
    .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, '')
    .trim();
  const isBodyEmpty = rawText.length === 0 && rawHtml.length === 0 && !(editor && editor.innerHTML.includes('<img'));

  const hasAnyAttachments = (composeAttachments && composeAttachments.length > 0) || (composeForwardedAttachments && composeForwardedAttachments.length > 0);

  if (isBodyEmpty && !hasAnyAttachments) {
    swalConfirm(
      t('warning', 'Uyarı'),
      t('compose_empty_body_warning', 'İleti içeriği boş. Yine de göndermek istiyor musunuz?'),
      t('btn_send', 'Gönder'),
      'warning'
    ).then(res => {
      if (res && res.isConfirmed) {
        executeSendMailRequest(toList, editor);
      }
    });
    return;
  }

  executeSendMailRequest(toList, editor);
}

function saveDraft(andClose = false) {
  if (isDraftSaving) return;

  const editor = document.getElementById('compose-editor');
  const htmlView = document.getElementById('compose-html-source');
  if (htmlView && htmlView.style.display !== 'none') {
    editor.innerHTML = /<\s*(style|link|base|script|iframe|meta)\b/i.test(htmlView.value)
      ? isolateEmailHtmlForEditor(htmlView.value)
      : htmlView.value;
  }

  const toList = $('#compose-to').val() || [];
  const ccList = $('#compose-cc').val() || [];
  const bccList = $('#compose-bcc').val() || [];
  const subject = document.getElementById('compose-subject').value.trim();
  const body = editor ? editor.innerHTML : '';
  const rawText = (editor ? editor.innerText || '' : '').trim();

  // If completely empty, no need to save
  if (!toList.length && !ccList.length && !bccList.length && !subject && !rawText && (!composeAttachments || !composeAttachments.length) && (!composeForwardedAttachments || !composeForwardedAttachments.length)) {
    if (andClose) {
      doCloseComposeModal();
    } else {
      showToast(typeof t === 'function' ? t('compose_draft_empty_hint', 'Kaydedilecek herhangi bir içerik bulunmuyor.') : 'Kaydedilecek herhangi bir içerik bulunmuyor.', 'info');
    }
    return;
  }

  isDraftSaving = true;
  const btn = document.getElementById('btn-save-draft');
  const statusEl = document.getElementById('draft-save-status');
  if (btn) btn.disabled = true;
  if (statusEl) {
    statusEl.style.display = 'inline-flex';
    statusEl.textContent = typeof t === 'function' ? t('compose_draft_saving', 'Taslak kaydediliyor...') : 'Taslak kaydediliyor...';
  }

  const formData = new FormData();
  formData.append('action', 'save_draft');
  formData.append('to', toList.join(', '));
  if (ccList.length) formData.append('cc', ccList.join(', '));
  if (bccList.length) formData.append('bcc', bccList.join(', '));
  formData.append('subject', subject);
  formData.append('body', body);
  if (currentDraftUid) {
    formData.append('draft_uid', currentDraftUid);
  }
  if (composeForwardedAttachments && composeForwardedAttachments.length > 0) {
    formData.append('forward_attachments', JSON.stringify(composeForwardedAttachments));
  }

  for (let i = 0; i < composeAttachments.length; i++) {
    formData.append('attachments[]', composeAttachments[i]);
  }

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      isDraftSaving = false;
      if (btn) btn.disabled = false;
      if (data.success) {
        if (data.draft_uid) {
          currentDraftUid = data.draft_uid;
        }
        isDraftSavedRecently = true;
        if (statusEl) {
          statusEl.style.display = 'inline-flex';
          const savedMsg = typeof t === 'function' ? t('compose_draft_saved', 'Taslak kaydedildi') : 'Taslak kaydedildi';
          statusEl.textContent = `${savedMsg} (${data.time || ''})`;
        }
        showToast(data.message || (typeof t === 'function' ? t('compose_draft_saved', 'Taslak kaydedildi.') : 'Taslak kaydedildi.'), 'success');
        
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
        if (currentFolder.toLowerCase().includes('draft') || currentFolder.toLowerCase().includes('taslak')) {
          if (typeof loadMessages === 'function') loadMessages(false);
        }

        if (andClose) {
          doCloseComposeModal();
        }
      } else {
        if (statusEl) statusEl.style.display = 'none';
        showToast(data.message || 'Taslak kaydedilemedi.', 'error');
        if (andClose) doCloseComposeModal();
      }
    })
    .catch(err => {
      isDraftSaving = false;
      if (btn) btn.disabled = false;
      if (statusEl) statusEl.style.display = 'none';
      showToast('Bağlantı hatası: ' + err.message, 'error');
      if (andClose) doCloseComposeModal();
    });
}

function executeSendMailRequest(toList, editor) {
  const btn = document.getElementById('btn-send-mail');
  btn.disabled = true;
  btn.innerHTML = 'Gönderiliyor...';

  const formData = new FormData();
  formData.append('action', 'send_email');
  formData.append('to', toList.join(', '));
  const ccList = $('#compose-cc').val() || [];
  if (ccList.length) formData.append('cc', ccList.join(', '));
  const bccList = $('#compose-bcc').val() || [];
  if (bccList.length) formData.append('bcc', bccList.join(', '));
  formData.append('subject', document.getElementById('compose-subject').value.trim());
  formData.append('body', editor ? editor.innerHTML : '');
  if (currentDraftUid) {
    formData.append('draft_uid', currentDraftUid);
  }
  if (composeForwardedAttachments && composeForwardedAttachments.length > 0) {
    formData.append('forward_attachments', JSON.stringify(composeForwardedAttachments));
  }

  for (let i = 0; i < composeAttachments.length; i++) {
    formData.append('attachments[]', composeAttachments[i]);
  }

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = 'Gönder';
      if (data.success) {
        showToast(data.message || 'E-posta başarıyla gönderildi.');
        const sentDraftUid = currentDraftUid;
        currentDraftUid = null;
        isDraftSavedRecently = false;
        closeComposeModal(true);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
        if (currentFolder.toLowerCase().includes('draft') || currentFolder.toLowerCase().includes('taslak')) {
          if (sentDraftUid) removeMailItemFromList(sentDraftUid);
          if (typeof loadMessages === 'function') loadMessages(false);
        }
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = 'Gönder';
      showToast('Sunucu hatası oluştu', 'error');
    });
}

// CONTACTS API
let contactsSearchTimeout = null;
let currentContactsTab = 'personal';

function switchContactsTab(tab) {
  currentContactsTab = tab;
  const btnPersonal = document.getElementById('tab-btn-contacts-personal');
  const btnGlobal = document.getElementById('tab-btn-contacts-global');
  const infoBadge = document.getElementById('contacts-global-info-badge');
  const searchInput = document.getElementById('contacts-search-input');
  if (searchInput) searchInput.value = '';

  if (tab === 'personal') {
    if (btnPersonal) {
      btnPersonal.style.background = 'var(--bg-surface-elevated)';
      btnPersonal.style.color = 'var(--text-main)';
    }
    if (btnGlobal) {
      btnGlobal.style.background = 'var(--bg-surface)';
      btnGlobal.style.color = 'var(--text-muted)';
    }
    if (infoBadge) infoBadge.style.display = 'none';
    loadContacts('');
  } else {
    if (btnPersonal) {
      btnPersonal.style.background = 'var(--bg-surface)';
      btnPersonal.style.color = 'var(--text-muted)';
    }
    if (btnGlobal) {
      btnGlobal.style.background = 'var(--bg-surface-elevated)';
      btnGlobal.style.color = 'var(--text-main)';
    }
    if (infoBadge) infoBadge.style.display = 'block';
    loadGlobalContacts('');
  }
}

function handleContactsSearch(query) {
  clearTimeout(contactsSearchTimeout);
  contactsSearchTimeout = setTimeout(() => {
    if (currentContactsTab === 'global') {
      loadGlobalContacts(query);
    } else {
      loadContacts(query);
    }
  }, 250);
}

function clearContactsSearch() {
  const input = document.getElementById('contacts-search-input');
  if (input) input.value = '';
  if (currentContactsTab === 'global') {
    loadGlobalContacts('');
  } else {
    loadContacts('');
  }
}

function updateGlobalContactsCount() {
  fetch('api.php?action=get_global_contacts')
    .then(r => r.json())
    .then(data => {
      if (data.success && data.contacts) {
        const badge = document.getElementById('contacts-global-badge');
        if (badge) badge.textContent = data.contacts.length;
      }
    })
    .catch(() => {});
}

let loadedContactsMap = {};

function loadContacts(query = '') {
  const container = document.getElementById('contacts-grid-container');
  const clearBtn = document.getElementById('contacts-search-clear');
  if (clearBtn) clearBtn.style.display = query.trim() ? 'block' : 'none';

  updateGlobalContactsCount();

  const url = 'api.php?action=get_contacts' + (query ? `&q=${encodeURIComponent(query)}` : '');
  fetch(url)
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.contacts || data.contacts.length === 0) {
        loadedContactsMap = {};
        container.innerHTML = `<div class="card" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);grid-column:1/-1;border-radius:0 !important;">
          ${query ? 'Aramanıza uygun kişi bulunamadı.' : 'Kayıtlı kişi bulunamadı.'}
        </div>`;
        const sub = document.getElementById('contacts-header-subtitle');
        if (sub) sub.textContent = query ? `"${query}" için sonuç bulunamadı` : '0 kayıtlı kişi';
        const pBadge = document.getElementById('contacts-personal-badge');
        if (pBadge && !query) pBadge.textContent = '0';
        return;
      }

      const sub = document.getElementById('contacts-header-subtitle');
      if (sub) {
        sub.textContent = query
          ? `"${query}" araması için ${data.contacts.length} kişi bulundu`
          : `${data.contacts.length} kayıtlı kişi listeleniyor`;
      }

      const countEl = document.getElementById('sidebar-contacts-count');
      if (!query && countEl) countEl.textContent = data.contacts.length;
      const pBadge = document.getElementById('contacts-personal-badge');
      if (!query && pBadge) pBadge.textContent = data.contacts.length;

      loadedContactsMap = {};
      let html = '';
      data.contacts.forEach(c => {
        loadedContactsMap[c.id] = c;
        const initial = ((c.first_name ? c.first_name.charAt(0) : '') + (c.last_name ? c.last_name.charAt(0) : '')).toUpperCase() || '?';
        const fullName = `${c.first_name || ''} ${c.last_name || ''}`.trim() || c.email;
        const subTitle = c.job_title || c.company || c.group_name || 'Kişi';

        html += `
          <div class="contact-card" id="contact-card-${c.id}" style="border-radius:0 !important;">
            <div class="contact-top">
              <div class="contact-avatar" style="background-color:${c.avatar_color || '#4f46e5'};border-radius:0 !important;">${initial}</div>
              <div style="flex:1;min-width:0;">
                <div class="contact-name" title="${escapeHtml(fullName)}">${escapeHtml(fullName)}</div>
                <div class="contact-title" title="${escapeHtml(subTitle)}">${escapeHtml(subTitle)}</div>
              </div>
              <button class="star-btn ${c.is_favorite == 1 ? 'starred' : ''}" onclick="toggleContactFav(${c.id})">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="${c.is_favorite == 1 ? '#fbbf24' : 'none'}" stroke="${c.is_favorite == 1 ? '#fbbf24' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </button>
            </div>
            <div class="contact-info-list">
              <div class="contact-info-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span title="${escapeHtml(c.email)}">${escapeHtml(c.email)}</span>
              </div>
              ${c.phone ? `
                <div class="contact-info-item">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  <span>${escapeHtml(c.phone)}</span>
                </div>
              ` : ''}
              ${c.company ? `
                <div class="contact-info-item" style="color:var(--text-muted);font-size:0.75rem;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  <span>${escapeHtml(c.company)}</span>
                </div>
              ` : ''}
            </div>
            <div class="contact-actions" style="margin-top:auto;display:flex;justify-content:space-between;align-items:center;padding-top:0.75rem;border-top:1px solid var(--border-color);">
              <button class="btn-send" style="padding:0.35rem 0.75rem;font-size:0.775rem;border-radius:0 !important;" onclick="openComposeModal('${escapeHtml(c.email)}')">E-posta Yaz</button>
              <div style="display:flex;gap:0.4rem;align-items:center;">
                <button class="btn-tool" style="padding:0.3rem 0.6rem;font-size:0.75rem;height:30px;display:inline-flex;align-items:center;gap:0.3rem;border:1px solid var(--border-color);background:var(--bg-surface-elevated);color:var(--text-main);cursor:pointer;border-radius:0 !important;" onclick="editContact(${c.id})" title="Kişiyi Düzenle">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  <span>Düzenle</span>
                </button>
                <button class="btn-icon" style="width:30px;height:30px;border-radius:0 !important;" onclick="deleteContact(${c.id})" title="Kişiyi Sil">&times;</button>
              </div>
            </div>
          </div>
        `;
      });
      container.innerHTML = html;
    })
    .catch(() => {
      container.innerHTML = `<div class="card" style="text-align:center;padding:2rem;color:var(--text-muted);grid-column:1/-1;border-radius:0 !important;">Kişiler yüklenirken hata oluştu.</div>`;
    });
}

function loadGlobalContacts(query = '') {
  const container = document.getElementById('contacts-grid-container');
  const clearBtn = document.getElementById('contacts-search-clear');
  if (clearBtn) clearBtn.style.display = query.trim() ? 'block' : 'none';

  const url = 'api.php?action=get_global_contacts' + (query ? `&q=${encodeURIComponent(query)}` : '');
  fetch(url)
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.contacts || data.contacts.length === 0) {
        container.innerHTML = `<div class="card" style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);grid-column:1/-1;border-radius:0 !important;">
          ${query ? 'Aramanıza uygun kurumsal kullanıcı bulunamadı.' : 'Bu domaine ait sisteme giriş yapmış kullanıcı bulunamadı.'}
        </div>`;
        const sub = document.getElementById('contacts-header-subtitle');
        if (sub) sub.textContent = query ? `"${query}" için kurumsal sonuç bulunamadı` : '0 kurumsal kullanıcı';
        return;
      }

      const sub = document.getElementById('contacts-header-subtitle');
      if (sub) {
        sub.textContent = query
          ? `"${query}" araması için ${data.contacts.length} kurumsal kullanıcı bulundu`
          : `@${data.domain} alan adına ait ${data.contacts.length} kurumsal kullanıcı listeleniyor`;
      }

      const badge = document.getElementById('contacts-global-badge');
      if (badge && !query) badge.textContent = data.contacts.length;

      let html = '';
      data.contacts.forEach(c => {
        const parts = (c.name || '').trim().split(/\s+/);
        let initials = '?';
        if (parts.length >= 2) {
          initials = (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        } else if (parts.length === 1 && parts[0]) {
          initials = parts[0].substring(0, 2).toUpperCase();
        }

        const isMeBadge = c.is_me 
          ? `<span style="font-size:0.7rem;background:rgba(79,70,229,0.15);color:var(--primary);padding:2px 6px;font-weight:600;margin-left:6px;border-radius:0 !important;">(Siz)</span>` 
          : '';

        let actionBtn = '';
        if (c.is_me) {
          actionBtn = `<span style="font-size:0.75rem;color:var(--text-muted);padding:0.35rem 0.6rem;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:0 !important;">Kendi Hesabınız</span>`;
        } else if (c.is_saved) {
          actionBtn = `<span style="font-size:0.75rem;color:var(--success);display:inline-flex;align-items:center;gap:3px;padding:0.35rem 0.6rem;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.25);border-radius:0 !important;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Rehberde Kayıtlı
          </span>`;
        } else {
          actionBtn = `<button class="btn-tool" style="padding:0.35rem 0.75rem;font-size:0.775rem;border-radius:0 !important;display:inline-flex;align-items:center;gap:3px;background:var(--bg-surface-elevated);" onclick="quickAddGlobalContact('${escapeHtml(c.name)}', '${escapeHtml(c.email)}')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> + Rehbere Ekle
          </button>`;
        }

        html += `
          <div class="contact-card" style="border-radius:0 !important;">
            <div class="contact-top">
              <div class="contact-avatar" style="background-color:${c.avatar_color || '#4f46e5'};border-radius:0 !important;">${initials}</div>
              <div style="flex:1;min-width:0;">
                <div class="contact-name" title="${escapeHtml(c.name)}">${escapeHtml(c.name)} ${isMeBadge}</div>
                <div class="contact-title" style="color:var(--primary);font-size:0.75rem;">@${escapeHtml(c.domain)} Kurumsal Kullanıcı</div>
              </div>
            </div>
            <div class="contact-info-list">
              <div class="contact-info-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span title="${escapeHtml(c.email)}">${escapeHtml(c.email)}</span>
              </div>
            </div>
            <div class="contact-actions" style="margin-top:auto;display:flex;justify-content:space-between;align-items:center;gap:0.5rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
              <button class="btn-send" style="padding:0.35rem 0.75rem;font-size:0.775rem;border-radius:0 !important;" onclick="openComposeModal('${escapeHtml(c.email)}')">E-posta Yaz</button>
              <div>${actionBtn}</div>
            </div>
          </div>
        `;
      });
      container.innerHTML = html;
    })
    .catch(() => {
      container.innerHTML = `<div class="card" style="text-align:center;padding:2rem;color:var(--text-muted);grid-column:1/-1;border-radius:0 !important;">Global kurumsal rehber yüklenirken hata oluştu.</div>`;
    });
}

function quickAddGlobalContact(name, email) {
  const parts = (name || '').trim().split(/\s+/);
  const firstName = parts[0] || email;
  const lastName = parts.length > 1 ? parts.slice(1).join(' ') : '';

  const formData = new FormData();
  formData.append('action', 'save_contact');
  formData.append('id', '');
  formData.append('first_name', firstName);
  formData.append('last_name', lastName);
  formData.append('email', email);
  formData.append('group_name', 'Kurumsal');

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(`${name || email} kişisel rehberinize eklendi!`, 'success');
        const pBadge = document.getElementById('contacts-personal-badge');
        if (pBadge) pBadge.textContent = parseInt(pBadge.textContent || '0') + 1;
        loadGlobalContacts(document.getElementById('contacts-search-input')?.value || '');
      } else {
        showToast(res.message || 'Kişi eklenemedi', 'error');
      }
    })
    .catch(e => {
      showToast('Hata: ' + e.message, 'error');
    });
}

function triggerContactsCsvUpload() {
  const fileInput = document.getElementById('contacts-csv-file');
  if (fileInput) {
    fileInput.value = '';
    fileInput.click();
  }
}

function handleContactsCsvUpload(e) {
  const file = e.target.files && e.target.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append('file', file);

  showToast('CSV dosyası işleniyor...', 'info');

  fetch('api.php?action=import_contacts_csv', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      showToast(res.message, 'success');
      loadContacts();
    } else {
      showToast(res.message || res.error || 'CSV içe aktarılamadı.', 'error');
    }
  })
  .catch(err => {
    showToast('CSV yükleme sırasında hata oluştu: ' + err.message, 'error');
  });
}

function downloadSampleContactsCsv() {
  const csvContent = "\uFEFFAd;Soyad;Email;Telefon;Şirket;Görev;Grup\nAhmet;Yılmaz;ahmet@ornek.com;05551234567;ABC Ltd;Müdür;Müşteriler\nMehmet;Kaya;mehmet@ornek.com;05429876543;XYZ A.Ş;Uzman;Tedarikçiler\nAyşe;Demir;ayse@ornek.com;;;;Genel\n";
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.setAttribute('download', 'adres_defteri_ornek.csv');
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function extractContactsFromMailbox() {
  const btn = document.getElementById('btn-extract-contacts');
  const oldHtml = btn ? btn.innerHTML : '';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `<svg width="15" height="15" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg><span>Taranıyor...</span>`;
  }

  showToast('Gelen ve giden kutuları taranıyor, kişiler toplanıyor...', 'info');

  fetch('api.php?action=sync_contacts_from_mailbox', { method: 'POST' })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message, 'success');
        loadContacts();
      } else {
        showToast(res.message || res.error || 'Kişiler aktarılamadı.', 'error');
      }
    })
    .catch(err => {
      showToast('Kişiler taranırken hata: ' + err.message, 'error');
    })
    .finally(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
      }
    });
}

function openContactModal(contact = null) {
  const titleEl = document.getElementById('contact-modal-title');
  if (contact && contact.id) {
    if (titleEl) titleEl.textContent = 'Kişiyi Düzenle';
    document.getElementById('c-id').value = contact.id;
    document.getElementById('c-first').value = contact.first_name || '';
    document.getElementById('c-last').value = contact.last_name || '';
    document.getElementById('c-email').value = contact.email || '';
    document.getElementById('c-phone').value = contact.phone || '';
    document.getElementById('c-group').value = contact.group_name || 'Genel';
    document.getElementById('c-company').value = contact.company || '';
    document.getElementById('c-title').value = contact.job_title || '';
    document.getElementById('c-notes').value = contact.notes || '';
  } else {
    if (titleEl) titleEl.textContent = 'Yeni Kişi Ekle';
    document.getElementById('c-id').value = '';
    document.getElementById('c-first').value = '';
    document.getElementById('c-last').value = '';
    document.getElementById('c-email').value = '';
    document.getElementById('c-phone').value = '';
    document.getElementById('c-group').value = 'Genel';
    document.getElementById('c-company').value = '';
    document.getElementById('c-title').value = '';
    document.getElementById('c-notes').value = '';
  }
  document.getElementById('contact-modal').classList.add('active');
}

function editContact(id) {
  const c = loadedContactsMap[id];
  if (!c) return;
  openContactModal(c);
}

function closeContactModal() {
  document.getElementById('contact-modal').classList.remove('active');
}

function saveContact(e) {
  e.preventDefault();
  const formData = new FormData();
  formData.append('action', 'save_contact');
  formData.append('id', document.getElementById('c-id').value);
  formData.append('first_name', document.getElementById('c-first').value.trim());
  formData.append('last_name', document.getElementById('c-last').value.trim());
  formData.append('email', document.getElementById('c-email').value.trim());
  formData.append('phone', document.getElementById('c-phone').value.trim());
  formData.append('group_name', document.getElementById('c-group').value.trim());
  formData.append('company', document.getElementById('c-company').value.trim());
  formData.append('job_title', document.getElementById('c-title').value.trim());
  formData.append('notes', document.getElementById('c-notes').value.trim());

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        closeContactModal();
        loadContacts(document.getElementById('contacts-search-input')?.value || '');
      } else {
        showToast(data.message || 'Kayıt başarısız', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function deleteContact(id) {
  swalConfirm('Kişiyi Sil', 'Bu kişiyi rehberden silmek istediğinize emin misiniz?', 'Sil', 'warning')
    .then(res => {
      if (res.isConfirmed) {
        fetch(`api.php?action=delete_contact&id=${id}`)
          .then(() => {
            showToast('Kişi silindi.');
            document.getElementById('contact-card-' + id)?.remove();
            loadContacts();
          });
      }
    });
}

function toggleContactFav(id) {
  fetch(`api.php?action=toggle_contact_fav&id=${id}`)
    .then(() => loadContacts());
}

// CALENDAR API
const monthNames = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

function renderCalendar() {
  document.getElementById('calendar-month-title').textContent = `${monthNames[currentMonth]} ${currentYear}`;
  const firstDay = new Date(currentYear, currentMonth, 1);
  const lastDay = new Date(currentYear, currentMonth + 1, 0);

  let startDay = firstDay.getDay();
  startDay = (startDay === 0) ? 6 : (startDay - 1);
  const totalDays = lastDay.getDate();
  const prevLast = new Date(currentYear, currentMonth, 0).getDate();

  const grid = document.getElementById('calendar-grid-box');
  const headers = grid.querySelectorAll('.calendar-day-header');
  grid.innerHTML = '';
  headers.forEach(h => grid.appendChild(h));

  const today = new Date();
  const isCurMonth = (today.getFullYear() === currentYear && today.getMonth() === currentMonth);

  for (let i = startDay - 1; i >= 0; i--) {
    const d = document.createElement('div');
    d.className = 'calendar-day other-month';
    d.innerHTML = `<span class="day-number">${prevLast - i}</span>`;
    grid.appendChild(d);
  }

  for (let d = 1; d <= totalDays; d++) {
    const dayDiv = document.createElement('div');
    dayDiv.className = 'calendar-day';
    if (isCurMonth && today.getDate() === d) dayDiv.classList.add('today');

    const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    dayDiv.innerHTML = `<span class="day-number">${d}</span><div class="day-events" id="c-events-${dateStr}"></div>`;
    dayDiv.addEventListener('click', (e) => {
      if (e.target.closest('.event-pill')) return;
      openEventModal(dateStr);
    });
    grid.appendChild(dayDiv);
  }

  fetchCalendarEvents();
}

function fetchCalendarEvents() {
  const start = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-01 00:00:00`;
  const last = new Date(currentYear, currentMonth + 1, 0).getDate();
  const end = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${last} 23:59:59`;

  fetch(`api.php?action=get_calendar_events&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`)
    .then(r => r.json())
    .then(events => {
      cachedCalendarEvents = events;
      events.forEach(ev => {
        const evDate = ev.start.substring(0, 10);
        const box = document.getElementById(`c-events-${evDate}`);
        if (box) {
          const pill = document.createElement('div');
          pill.className = 'event-pill';
          pill.style.backgroundColor = ev.backgroundColor;
          pill.style.borderRadius = '0px';

          const isShared = ev.extendedProps && ev.extendedProps.is_shared;
          const creator = (ev.extendedProps && ev.extendedProps.creator_name) ? ev.extendedProps.creator_name : '';
          const isCreator = ev.extendedProps && ev.extendedProps.is_creator;

          let pillLabel = ev.title;
          if (isShared && !isCreator && creator) {
            pillLabel = `[${creator}] ${ev.title}`;
          }

          pill.innerHTML = `${isShared ? '<span style="font-size:11px;opacity:0.95;margin-right:2px;">👥</span>' : ''}<span>${escapeHtml(pillLabel)}</span>`;
          pill.title = `${ev.title}${creator ? ' (Oluşturan: ' + creator + ')' : ''}${isShared ? ' - Domain Ortak Takvimi' : ''}`;

          pill.addEventListener('click', (e) => {
            e.stopPropagation();
            editEvent(ev);
          });
          box.appendChild(pill);
        }
      });
    });
}

function calendarPrevMonth() {
  currentMonth--;
  if (currentMonth < 0) { currentMonth = 11; currentYear--; }
  renderCalendar();
}

function calendarNextMonth() {
  currentMonth++;
  if (currentMonth > 11) { currentMonth = 0; currentYear++; }
  renderCalendar();
}

function calendarGoToToday() {
  const n = new Date();
  currentYear = n.getFullYear();
  currentMonth = n.getMonth();
  renderCalendar();
}

function openEventModal(dateStr = null) {
  document.getElementById('e-id').value = '';
  document.getElementById('e-title').value = '';
  document.getElementById('e-category').value = 'Toplantı';
  document.getElementById('e-color').value = '#4f46e5';
  document.getElementById('e-location').value = '';
  document.getElementById('e-desc').value = '';
  
  const shareChk = document.getElementById('e-is-shared');
  if (shareChk) shareChk.checked = true; // Default: shared across domain!

  const creatorRow = document.getElementById('event-modal-creator-row');
  if (creatorRow) creatorRow.style.display = 'none';

  document.getElementById('btn-del-event').style.display = 'none';

  const base = dateStr || new Date().toISOString().substring(0, 10);
  document.getElementById('e-start').value = `${base}T09:00`;
  document.getElementById('e-end').value = `${base}T10:00`;
  document.getElementById('event-modal').classList.add('active');
}

function closeEventModal() {
  document.getElementById('event-modal').classList.remove('active');
}

function editEvent(ev) {
  document.getElementById('e-id').value = ev.id;
  document.getElementById('e-title').value = ev.title;
  document.getElementById('e-start').value = ev.extendedProps.startDateRaw;
  document.getElementById('e-end').value = ev.extendedProps.endDateRaw;
  document.getElementById('e-category').value = ev.extendedProps.category || 'Toplantı';
  document.getElementById('e-color').value = ev.backgroundColor;
  document.getElementById('e-location').value = ev.extendedProps.location || '';
  document.getElementById('e-desc').value = ev.extendedProps.description || '';

  const shareChk = document.getElementById('e-is-shared');
  if (shareChk) {
    shareChk.checked = (ev.extendedProps.is_shared !== undefined) ? (ev.extendedProps.is_shared == 1 || ev.extendedProps.is_shared === true) : true;
  }

  const creatorRow = document.getElementById('event-modal-creator-row');
  const creatorText = document.getElementById('event-modal-creator-text');
  if (creatorRow && creatorText) {
    const cName = ev.extendedProps.creator_name || '';
    const cMail = ev.extendedProps.creator_email || '';
    if (cName || cMail) {
      creatorText.textContent = `${cName} ${cMail ? '(' + cMail + ')' : ''}${ev.extendedProps.is_creator ? ' - (Siz)' : ''}`;
      creatorRow.style.display = 'block';
    } else {
      creatorRow.style.display = 'none';
    }
  }

  document.getElementById('btn-del-event').style.display = 'flex';
  document.getElementById('event-modal').classList.add('active');
}

function editEventById(id) {
  const found = cachedCalendarEvents.find(e => e.id == id);
  if (found) editEvent(found);
}

function saveCalendarEvent(e) {
  e.preventDefault();
  const formData = new FormData();
  formData.append('action', 'save_calendar_event');
  formData.append('id', document.getElementById('e-id').value);
  formData.append('title', document.getElementById('e-title').value.trim());
  formData.append('start_date', document.getElementById('e-start').value);
  formData.append('end_date', document.getElementById('e-end').value);
  formData.append('category', document.getElementById('e-category').value);
  formData.append('color', document.getElementById('e-color').value);
  formData.append('location', document.getElementById('e-location').value.trim());
  formData.append('description', document.getElementById('e-desc').value.trim());
  
  const shareChk = document.getElementById('e-is-shared');
  formData.append('is_shared', shareChk && shareChk.checked ? 1 : 0);

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        closeEventModal();
        renderCalendar();
      } else {
        showToast(data.message || 'Hata oluştu.', 'error');
      }
    })
    .catch(() => {
      showToast('Sunucu hatası oluştu', 'error');
    });
}

function deleteCalendarEvent() {
  const id = document.getElementById('e-id').value;
  if (!id) return;
  swalConfirm('Etkinliği Sil', 'Bu takvim etkinliğini silmek istediğinize emin misiniz?', 'Sil', 'warning')
    .then(res => {
      if (res.isConfirmed) {
        fetch(`api.php?action=delete_calendar_event&id=${id}`)
          .then(() => {
            showToast('Etkinlik silindi.');
            closeEventModal();
            renderCalendar();
          });
      }
    });
}

// ==========================================
// NOTIFICATION & AUDIO CHIME ENGINE
// ==========================================
let mailAudioContext = null;
let previousFolderCounts = null;

function getMailAudioContext() {
  if (!mailAudioContext) {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (AudioContextClass) {
      mailAudioContext = new AudioContextClass();
    }
  }
  return mailAudioContext;
}

// Unlock Web Audio and register notification permission on first user gesture
(function initAudioUnlock() {
  const unlockEvents = ['click', 'keydown', 'touchstart'];
  const unlocker = () => {
    try {
      const ctx = getMailAudioContext();
      if (ctx && ctx.state === 'suspended') {
        ctx.resume();
      }
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    } catch (e) {}
  };
  unlockEvents.forEach(evt => document.addEventListener(evt, unlocker, { once: true, passive: true }));
})();

function playNewMailSound() {
  if (localStorage.getItem('webmail_sound_enabled') === 'false') return;
  try {
    const ctx = getMailAudioContext();
    if (!ctx) return;

    if (ctx.state === 'suspended') {
      ctx.resume().then(() => emitMailChime(ctx)).catch(() => {});
    } else {
      emitMailChime(ctx);
    }
  } catch (err) {
    console.warn('Mail sound could not be played:', err);
  }
}

function emitMailChime(ctx) {
  const now = ctx.currentTime;
  // Professional 3-tone harmonic chime (E5 -> A5 -> E6)
  const notes = [
    { freq: 659.25, time: 0, dur: 0.35, gain: 0.20 },
    { freq: 880.00, time: 0.08, dur: 0.45, gain: 0.22 },
    { freq: 1318.51, time: 0.16, dur: 0.65, gain: 0.25 }
  ];

  notes.forEach(n => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(n.freq, now + n.time);

    gain.gain.setValueAtTime(0, now + n.time);
    gain.gain.linearRampToValueAtTime(n.gain, now + n.time + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + n.time + n.dur);

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start(now + n.time);
    osc.stop(now + n.time + n.dur);
  });
}

function showNewMailNotification(count = 1) {
  const title = t('new_mail_notification_title', 'Yeni E-Posta Geldi');
  const bodyText = (count > 1) 
    ? `${count} ${t('new_mails_received_desc', 'yeni e-posta alındı.')}`
    : t('new_mail_received_desc', 'Gelen kutunuzda yeni bir e-posta var.');

  // In-app toast notification
  if (typeof showToast === 'function') {
    showToast(`📬 ${title} (${count})`, 'info');
  }

  // Desktop notification
  if ('Notification' in window && Notification.permission === 'granted') {
    try {
      const notif = new Notification(title, {
        body: bodyText,
        icon: 'assets/favicon.ico'
      });
      notif.onclick = function() {
        window.focus();
        if (typeof selectFolder === 'function') selectFolder('INBOX');
        notif.close();
      };
    } catch (e) {}
  }
}

function checkNewMailArrival(newCounts) {
  if (!newCounts) return;

  if (previousFolderCounts !== null) {
    const prevInbox = previousFolderCounts['INBOX'] || { total: 0, unseen: 0 };
    const curInbox = newCounts['INBOX'] || { total: 0, unseen: 0 };

    const totalDiff = (curInbox.total || 0) - (prevInbox.total || 0);
    const unseenDiff = (curInbox.unseen || 0) - (prevInbox.unseen || 0);

    // Trigger sound and alert when new emails arrive
    if (totalDiff > 0 || unseenDiff > 0) {
      const arrivedCount = Math.max(totalDiff, unseenDiff, 1);
      playNewMailSound();
      showNewMailNotification(arrivedCount);

      // Auto reload message list if currently in INBOX without active filter
      if (currentFolder === 'INBOX' && !currentSearch && !activeAdvSearch) {
        if (typeof loadMessages === 'function') {
          loadMessages(false);
        }
      }
      if (typeof loadCustomFolders === 'function') {
        loadCustomFolders();
      }
    }
  }

  // Deep clone to remember current counts
  previousFolderCounts = JSON.parse(JSON.stringify(newCounts));
}

// ==========================================
// CUSTOM FOLDERS ENGINE & UNREAD BADGES
// ==========================================
let cachedCustomFolders = [];
let cachedFolderCounts = {};
let moveTargetUid = null;

function renderFolderBadge(el, info) {
  if (!el || !info) return;
  const total = (info.total !== undefined) ? parseInt(info.total, 10) : (parseInt(String(info.formatted).replace(/\D/g, ''), 10) || 0);
  const unseen = (info.unseen !== undefined) ? parseInt(info.unseen, 10) : 0;
  const formattedTotal = info.formatted || total.toLocaleString('tr-TR');

  if (total <= 0 && unseen <= 0) {
    el.style.display = 'none';
    el.innerHTML = '';
    return;
  }

  el.style.display = 'inline-flex';
  el.style.alignItems = 'center';

  if (unseen > 0) {
    el.classList.add('has-unread');
    el.title = `${unseen} ${t('unread_count_badge', 'okunmamış')} / ${formattedTotal} ${t('total_count_badge', 'toplam')} e-posta`;
    el.innerHTML = `<span class="badge-unread-val" title="${unseen} ${t('unread_count_badge', 'okunmamış')}">${unseen}</span><span class="badge-sep">/</span><span class="badge-total-val" title="${formattedTotal} ${t('total_count_badge', 'toplam')}">${formattedTotal}</span>`;
  } else {
    el.classList.remove('has-unread');
    el.title = `Toplam: ${formattedTotal} e-posta`;
    el.innerHTML = `<span class="badge-total-val">${formattedTotal}</span>`;
  }
}

function updateFolderCounts(counts = null) {
  if (counts) {
    checkNewMailArrival(counts);
    applyFolderCounts(counts);
    return;
  }
  fetch('api.php?action=get_folder_counts')
    .then(r => r.json())
    .then(data => {
      if (data.success && data.counts) {
        checkNewMailArrival(data.counts);
        applyFolderCounts(data.counts);
      }
    })
    .catch(() => {});
}

function applyFolderCounts(counts) {
  cachedFolderCounts = counts;
  for (const [folder, info] of Object.entries(counts)) {
    const el = document.getElementById('folder-count-' + folder);
    if (el) {
      renderFolderBadge(el, info);
    }
  }

  // Fallback for Junk / Spam sync
  const spamCountInfo = counts['Junk'] || counts['Spam'] || counts['INBOX.Junk'] || counts['INBOX.Spam'] || counts['junk'] || counts['spam'];
  if (spamCountInfo) {
    ['folder-count-Spam', 'folder-count-Junk'].forEach(id => {
      const elSpam = document.getElementById(id);
      if (elSpam) {
        renderFolderBadge(elSpam, spamCountInfo);
      }
    });
  }

  // Also update unread chip counter for currentFolder
  const curFldCounts = counts[currentFolder];
  const curUnseen = curFldCounts ? (parseInt(curFldCounts.unseen, 10) || 0) : 0;
  const unreadChip = document.getElementById('chip-filter-unread');
  if (unreadChip) {
    const baseText = t('filter_unread', 'Okunmamış');
    unreadChip.innerHTML = curUnseen > 0 
      ? `${baseText} <span class="chip-count" style="font-weight:700;margin-left:2px;opacity:0.9;">(${curUnseen})</span>`
      : baseText;
  }
}

function loadCustomFolders() {
  fetch('api.php?action=get_folders')
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (data.spam_folder) {
          window.systemSpamFolderName = data.spam_folder;
        }
        cachedCustomFolders = data.custom_folders || [];
        if (data.counts) {
          applyFolderCounts(data.counts);
        }
        renderSidebarCustomFolders(cachedCustomFolders);
        if (typeof syncActiveFolderUI === 'function') {
          syncActiveFolderUI(currentFolder);
        }
        if (typeof renderSettingsFoldersTable === 'function') {
          renderSettingsFoldersTable();
        }
        populateFilterTargetDropdown(cachedCustomFolders, data.system_folders);
      }
    })
    .catch(() => {
      if (typeof updateFolderCounts === 'function') updateFolderCounts();
    });
}

function renderSidebarCustomFolders(folders) {
  const container = document.getElementById('sidebar-custom-folders');
  if (!container) return;
  if (!folders || folders.length === 0) {
    container.innerHTML = '';
    return;
  }
  container.innerHTML = folders.map(cf => {
    const isSub = cf.folder_name && cf.folder_name.includes('/');
    let cleanDisplay = cf.display_name;
    if (!cleanDisplay || cleanDisplay.includes('??')) {
      cleanDisplay = decodeImapUtf7(cf.folder_name);
    } else {
      cleanDisplay = decodeImapUtf7(cleanDisplay);
    }
    const dName = isSub ? cleanDisplay.split('/').pop() : cleanDisplay;
    const pLeft = isSub ? 'style="padding-left:1.75rem;"' : '';
    const fColor = cf.color || '#4f46e5';
    const escapedFld = (cf.folder_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const escapedDisp = (cleanDisplay || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const fldCountInfo = cachedFolderCounts && cachedFolderCounts[cf.folder_name];
    const total = fldCountInfo ? (fldCountInfo.total ?? (parseInt(String(fldCountInfo.formatted).replace(/\D/g, ''), 10) || 0)) : 0;
    const unseen = fldCountInfo ? (parseInt(fldCountInfo.unseen, 10) || 0) : 0;
    const formattedTotal = fldCountInfo ? fldCountInfo.formatted : '';
    const hasUnseen = unseen > 0;
    let badgeHtml = '';
    if (total > 0 || unseen > 0) {
      if (hasUnseen) {
        badgeHtml = `<span class="badge-unread-val" title="${unseen} ${t('unread_count_badge', 'okunmamış')}">${unseen}</span><span class="badge-sep">/</span><span class="badge-total-val" title="${formattedTotal} ${t('total_count_badge', 'toplam')}">${formattedTotal}</span>`;
      } else {
        badgeHtml = `<span class="badge-total-val">${formattedTotal}</span>`;
      }
    }
    return `
      <li class="nav-item" style="position:relative;" id="folder-item-${escapeHtml(cf.folder_name)}" oncontextmenu="handleFolderContextMenu(event, '${escapedFld}', '${escapedDisp}', '${fColor}', ${cf.id})">
        <a href="javascript:void(0)" class="nav-link ${currentFolder === cf.folder_name ? 'active' : ''}" id="folder-link-${escapeHtml(cf.folder_name)}" onclick="selectFolder('${escapedFld}', this)" ondblclick="openRenameFolderModal('${escapedFld}', '${escapedDisp}', '${fColor}', ${cf.id}, event)" ${pLeft}>
          <div class="nav-link-left">
            ${isSub 
              ? `<span style="display:inline-block;width:6px;height:6px;border-left:2px solid var(--text-muted);border-bottom:2px solid var(--text-muted);margin-right:6px;margin-bottom:2px;opacity:0.6;"></span>` 
              : `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="${fColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>`
            }
            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:145px;" title="${escapeHtml(cleanDisplay)}">${escapeHtml(dName)}</span>
          </div>
          <span class="nav-badge ${hasUnseen ? 'has-unread' : ''}" id="folder-count-${escapeHtml(cf.folder_name)}" style="${badgeHtml ? 'display:inline-flex;align-items:center;' : 'display:none;'}margin-right:48px;">${badgeHtml}</span>
        </a>
        <div class="folder-action-buttons" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:2px;">
          <button type="button" class="btn-icon rename-folder-btn" onclick="openRenameFolderModal('${escapedFld}', '${escapedDisp}', '${fColor}', ${cf.id}, event)" title="${t('rename_folder', 'Yeniden Adlandır')}" style="width:20px;height:20px;border:none;color:var(--text-muted);opacity:0.6;padding:0;background:transparent;cursor:pointer;" onmouseover="this.style.opacity='1';this.style.color='var(--primary)';" onmouseout="this.style.opacity='0.6';this.style.color='var(--text-muted)';">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
          </button>
          <button type="button" class="btn-icon delete-folder-btn" onclick="deleteCustomFolder(${cf.id}, '${escapedFld}', '${escapedDisp}', event)" title="${t('delete_folder', 'Klasörü Sil')}" style="width:20px;height:20px;border:none;color:var(--text-muted);opacity:0.6;padding:0;background:transparent;cursor:pointer;" onmouseover="this.style.opacity='1';this.style.color='var(--danger)';" onmouseout="this.style.opacity='0.6';this.style.color='var(--text-muted)';">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </li>
    `;
  }).join('');
}

function openNewFolderModal() {
  document.getElementById('folder-input-name').value = '';
  document.getElementById('folder-input-color').value = '#4f46e5';
  document.getElementById('folder-modal').classList.add('active');
  document.getElementById('folder-input-name').focus();
}

function closeFolderModal() {
  document.getElementById('folder-modal').classList.remove('active');
}

function handleCreateFolder(e) {
  e.preventDefault();
  const name = document.getElementById('folder-input-name').value.trim();
  const color = document.getElementById('folder-input-color').value;

  const fd = new FormData();
  fd.append('action', 'create_folder');
  fd.append('name', name);
  fd.append('display_name', name);
  fd.append('color', color);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        closeFolderModal();
        loadCustomFolders();
      } else {
        showToast(data.message, 'error');
      }
    });
}

function openRenameFolderModal(folderName, displayName, color, id, e) {
  if (e) e.stopPropagation();
  let showName = displayName;
  if (!showName || showName.includes('??')) {
    showName = decodeImapUtf7(folderName);
  } else {
    showName = decodeImapUtf7(showName);
  }
  document.getElementById('rename-folder-old-name').value = folderName;
  document.getElementById('rename-folder-id').value = id || 0;
  document.getElementById('rename-folder-new-name').value = showName;
  document.getElementById('rename-folder-color').value = color || '#4f46e5';
  document.getElementById('rename-folder-modal').classList.add('active');
  const input = document.getElementById('rename-folder-new-name');
  setTimeout(() => { if (input) { input.focus(); input.select(); } }, 60);
}

function closeRenameFolderModal() {
  document.getElementById('rename-folder-modal').classList.remove('active');
}

function handleRenameFolder(e) {
  e.preventDefault();
  const oldName = document.getElementById('rename-folder-old-name').value;
  const folderId = document.getElementById('rename-folder-id').value;
  const newName = document.getElementById('rename-folder-new-name').value.trim();
  const color = document.getElementById('rename-folder-color').value;

  if (!newName) {
    showToast(t('folder_name_empty', 'Lütfen geçerli bir klasör adı giriniz.'), 'error');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'rename_folder');
  fd.append('id', folderId);
  fd.append('old_folder_name', oldName);
  fd.append('new_name', newName);
  fd.append('color', color);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        closeRenameFolderModal();
        if (currentFolder === oldName || (data.old_folder_name && currentFolder === data.old_folder_name)) {
          currentFolder = data.new_folder_name;
        }
        loadCustomFolders();
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(err => {
      showToast('İşlem sırasında bir hata oluştu: ' + err.message, 'error');
    });
}

function deleteCustomFolder(id, folderName, displayName, e) {
  if (e) e.stopPropagation();
  let showName = displayName;
  if (!showName || showName.includes('??')) {
    showName = decodeImapUtf7(folderName);
  } else {
    showName = decodeImapUtf7(showName);
  }
  swalConfirm('Klasörü Sil', `"${showName}" klasörünü ve bu klasör tanımını silmek istediğinize emin misiniz?`, 'Sil', 'warning')
    .then(res => {
      if (res.isConfirmed) {
        const fd = new FormData();
        fd.append('action', 'delete_folder');
        fd.append('id', id);
        fd.append('folder_name', folderName);

        fetch('api.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              showToast(data.message);
              // Immediately remove from local cache and UI before async reload completes
              const domEl = document.getElementById('folder-item-' + folderName);
              if (domEl) domEl.remove();
              if (Array.isArray(cachedCustomFolders)) {
                const decTarget = decodeImapUtf7(folderName).toLowerCase();
                cachedCustomFolders = cachedCustomFolders.filter(cf => {
                  const cfDec = decodeImapUtf7(cf.folder_name || '').toLowerCase();
                  return String(cf.id) !== String(id) && cf.folder_name !== folderName && cfDec !== decTarget;
                });
                renderSidebarCustomFolders(cachedCustomFolders);
                renderSettingsFoldersTable();
              }
              if (currentFolder === folderName) {
                selectFolder('INBOX', document.getElementById('folder-link-INBOX'));
              }
              loadCustomFolders();
            } else {
              showToast(data.message, 'error');
            }
          });
      }
    });
}

// ==========================================
// MOVE EMAIL ENGINE (DROPDOWN & MODAL)
// ==========================================
function toggleReaderMoveDropdown(event, uid, fromFolder = null) {
  if (event) event.stopPropagation();
  closeMailSelectDropdown();
  closeBulkMoveDropdown();
  const menu = document.getElementById('reader-move-dropdown');
  if (!menu) return;
  const isShown = (menu.style.display === 'block');
  if (isShown) {
    closeReaderMoveDropdown();
    return;
  }

  const srcFolder = fromFolder || currentFolder;
  const available = getAvailableMoveTargetFolders(srcFolder);
  let html = '<div class="mail-sort-heading">Klasöre Taşı</div>';
  if (available.length === 0) {
    html += '<div style="padding:8px 12px;color:var(--text-muted);font-size:0.75rem;">Taşınabilecek klasör yok.</div>';
  } else {
    available.forEach(f => {
      const safeName = escapeHtml(f.name).replace(/'/g, "\\'");
      const safeSrc = escapeHtml(srcFolder).replace(/'/g, "\\'");
      html += `
        <button type="button" class="mail-sort-item" onclick="readerMoveEmailToFolder(${uid}, '${safeName}', '${safeSrc}')">
          <span style="display:inline-block;width:8px;height:8px;background:${f.color || 'var(--primary)'};flex-shrink:0;"></span>
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(f.display_name)}</span>
        </button>
      `;
    });
  }
  html += `
    <div class="mail-sort-divider"></div>
    <button type="button" class="mail-sort-item" onclick="closeReaderMoveDropdown(); openNewFolderModal();">
      <span style="color:var(--primary);font-weight:700;">+</span>
      <span style="color:var(--primary);font-weight:600;">Yeni Klasör Oluştur</span>
    </button>
  `;
  menu.innerHTML = html;
  menu.style.display = 'block';
}

function closeReaderMoveDropdown() {
  const menu = document.getElementById('reader-move-dropdown');
  if (menu) menu.style.display = 'none';
}

function readerMoveEmailToFolder(uid, toFolder, fromFolder = null) {
  closeReaderMoveDropdown();
  moveTargetUid = uid;
  const srcFolder = fromFolder || currentFolder;
  const fd = new FormData();
  fd.append('action', 'move_email');
  fd.append('uid', uid);
  fd.append('from_folder', srcFolder);
  fd.append('to_folder', toFolder);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        removeMailItemFromList(uid);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast(data.message, 'error');
      }
    });
}

function openMoveModal(uid) {
  moveTargetUid = uid;
  const list = document.getElementById('move-folders-list');
  list.innerHTML = '';

  const allFolders = [
    { name: 'INBOX', display_name: 'Gelen Kutusu', color: 'var(--primary)' },
    { name: 'Sent', display_name: 'Gönderilenler', color: 'var(--info)' },
    { name: 'Drafts', display_name: 'Taslaklar', color: 'var(--warning)' },
    { name: window.systemSpamFolderName || 'Junk', display_name: 'İstenmeyen (Spam)', color: '#f59e0b' },
    { name: 'Trash', display_name: 'Çöp Kutusu', color: 'var(--danger)' },
    ...cachedCustomFolders.map(cf => ({ name: cf.folder_name, display_name: cf.display_name, color: cf.color }))
  ];

  const available = allFolders.filter(f => f.name !== currentFolder);
  if (available.length === 0) {
    list.innerHTML = '<div style="padding:1rem;color:var(--text-muted);font-size:0.85rem;text-align:center;">Taşınabilecek başka klasör yok.</div>';
  } else {
    available.forEach(f => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'top-tab';
      btn.style.width = '100%';
      btn.style.justifyContent = 'flex-start';
      btn.style.padding = '0.65rem 0.85rem';
      btn.style.marginBottom = '3px';
      btn.style.borderRadius = 'var(--radius-sm)';
      btn.style.border = '1px solid var(--border-color)';
      btn.style.background = 'var(--bg-surface-elevated)';
      btn.innerHTML = `
        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${f.color || 'var(--primary)'};margin-right:6px;"></span>
        <span style="font-weight:600;font-size:0.85rem;color:var(--text-primary);">${f.display_name}</span>
      `;
      btn.onclick = () => moveEmailToFolder(f.name);
      list.appendChild(btn);
    });
  }

  document.getElementById('move-modal').classList.add('active');
}

function closeMoveModal() {
  document.getElementById('move-modal').classList.remove('active');
  moveTargetUid = null;
}

function moveEmailToFolder(toFolder) {
  if (!moveTargetUid) return;
  const uid = moveTargetUid;

  const fd = new FormData();
  fd.append('action', 'move_email');
  fd.append('uid', uid);
  fd.append('from_folder', currentFolder);
  fd.append('to_folder', toFolder);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message);
        closeMoveModal();
        removeMailItemFromList(uid);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast(data.message, 'error');
      }
    });
}

// ==========================================
// FILTERS & RULES ENGINE
// ==========================================
let cachedFilters = [];

function loadFilters() {
  const container = document.getElementById('filters-list-container');
  if (!container) return;

  fetch('api.php?action=get_filters')
    .then(parseApiResponseJson)
    .then(data => {
      if (!data || !data.success) {
        container.innerHTML = `<div style="padding:1.5rem;color:var(--danger);">${escapeHtml((data && data.message) || 'Filtreler yüklenemedi.')}</div>`;
        return;
      }
      cachedFilters = data.filters || [];
      if (cachedFilters.length === 0) {
        container.innerHTML = `
          <div style="padding:3rem 1.5rem;text-align:center;color:var(--text-muted);">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 0.75rem;opacity:0.4;">
              <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
            </svg>
            <p style="font-size:0.9rem;margin-bottom:0.5rem;font-weight:600;">Henüz tanımlanmış bir filtre kuralı yok.</p>
            <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;">Gelen postaları otomatik olarak klasörlere taşımak için kural ekleyin.</p>
            <button class="btn-send" style="margin:0 auto;" onclick="openFilterModal()">İlk Filtrenizi Oluşturun</button>
          </div>
        `;
        return;
      }

      const fieldNames = { 'subject': 'Konu', 'from': 'Gönderen', 'to': 'Alıcı', 'body': 'İçerik' };
      const matchTypeNames = { 'contains': 'içeriyorsa', 'equals': 'eşitse', 'starts_with': 'başlıyorsa' };
      const actionNames = { 'move': 'Klasöre Taşı:', 'star': 'Yıldızla', 'read': 'Okundu Yap', 'delete': 'Çöpe Taşı' };

      container.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:0.75rem;">
          ${cachedFilters.map(f => `
            <div style="padding:1rem 1.25rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
              <div style="flex:1;min-width:240px;">
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.35rem;">
                  <strong style="font-size:0.95rem;color:var(--text-primary);">${escapeHtml(f.filter_name)}</strong>
                  <span style="font-size:0.7rem;padding:2px 7px;border-radius:var(--radius-full);background:${f.is_active == 1 ? 'var(--success-light)' : 'var(--bg-hover)'};color:${f.is_active == 1 ? 'var(--success)' : 'var(--text-muted)'};font-weight:700;">
                    ${f.is_active == 1 ? 'Aktif' : 'Pasif'}
                  </span>
                </div>
                <div style="font-size:0.825rem;color:var(--text-secondary);display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                  <span>Eğer <strong>${fieldNames[f.match_field] || escapeHtml(f.match_field)}</strong> alanı <em>"${escapeHtml(f.match_value)}"</em> ${matchTypeNames[f.match_type] || escapeHtml(f.match_type)} &rarr;</span>
                  <span class="chip" style="font-size:0.75rem;padding:2px 8px;font-weight:600;">
                    ${actionNames[f.action_type] || escapeHtml(f.action_type)} ${f.action_target ? '<strong>' + escapeHtml(f.action_target) + '</strong>' : ''}
                  </span>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" class="chip" onclick="toggleFilter(${f.id})" title="Durumu Değiştir">
                  ${f.is_active == 1 ? 'Durdur' : 'Etkinleştir'}
                </button>
                <button type="button" class="btn-icon" style="color:var(--text-secondary);" title="Düzenle" onclick="editFilterById(${f.id})">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button type="button" class="btn-icon" style="color:var(--danger);" title="Sil" onclick="deleteFilter(${f.id})">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    })
    .catch(err => {
      container.innerHTML = `<div style="padding:1.5rem;color:var(--danger);">Bağlantı hatası: ${escapeHtml(err.message || '')}</div>`;
    });
}

function populateFilterTargetDropdown(customFolders, systemFolders) {
  const select = document.getElementById('filter-action-target');
  if (!select) return;
  select.innerHTML = '';

  const groups = [
    { label: 'Özel Klasörler', items: (customFolders || []).map(cf => ({ val: cf.folder_name, name: cf.display_name })) },
    { label: 'Sistem Klasörleri', items: [
      { val: 'INBOX', name: 'Gelen Kutusu' },
      { val: 'Junk', name: 'İstenmeyen (Spam)' },
      { val: 'Trash', name: 'Çöp Kutusu' },
      { val: 'Drafts', name: 'Taslaklar' },
      { val: 'Sent', name: 'Gönderilenler' }
    ]}
  ];

  groups.forEach(g => {
    if (g.items.length > 0) {
      const optGroup = document.createElement('optgroup');
      optGroup.label = g.label;
      g.items.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.val;
        opt.textContent = it.name;
        optGroup.appendChild(opt);
      });
      select.appendChild(optGroup);
    }
  });
}

function openFilterModal(filterObj = null) {
  ensureFilterTargetDropdown(() => {
    document.getElementById('filter-id').value = filterObj ? filterObj.id : '';
    document.getElementById('filter-name').value = filterObj ? filterObj.filter_name : '';
    document.getElementById('filter-match-field').value = filterObj ? filterObj.match_field : 'subject';
    document.getElementById('filter-match-type').value = filterObj ? filterObj.match_type : 'contains';
    document.getElementById('filter-match-value').value = filterObj ? filterObj.match_value : '';
    document.getElementById('filter-action-type').value = filterObj ? filterObj.action_type : 'move';
    document.getElementById('filter-is-active').checked = filterObj ? (filterObj.is_active == 1) : true;
    if (document.getElementById('filter-run-now')) {
      document.getElementById('filter-run-now').checked = true;
    }
    document.getElementById('filter-modal-title').textContent = filterObj ? 'Filtre Kuralını Düzenle' : 'Yeni Filtre & Yönlendirme Kuralı';

    toggleFilterTargetBox(document.getElementById('filter-action-type').value);
    if (filterObj && filterObj.action_target) {
      document.getElementById('filter-action-target').value = filterObj.action_target;
    }

    document.getElementById('filter-modal').classList.add('active');
  });
}

function closeFilterModal() {
  document.getElementById('filter-modal').classList.remove('active');
}

function toggleFilterTargetBox(actionType) {
  const grp = document.getElementById('filter-target-group');
  if (grp) grp.style.display = (actionType === 'move') ? 'block' : 'none';
}

function editFilterById(id) {
  const f = cachedFilters.find(x => x.id == id);
  if (f) openFilterModal(f);
}

function handleSaveFilter(e) {
  e.preventDefault();
  const filterId = document.getElementById('filter-id').value;
  const targetEl = document.getElementById('filter-action-target');
  const fd = new FormData();
  fd.append('action', 'save_filter');
  fd.append('id', filterId);
  fd.append('filter_name', document.getElementById('filter-name').value.trim());
  fd.append('match_field', document.getElementById('filter-match-field').value);
  fd.append('match_type', document.getElementById('filter-match-type').value);
  fd.append('match_value', document.getElementById('filter-match-value').value.trim());
  fd.append('action_type', document.getElementById('filter-action-type').value);
  fd.append('action_target', targetEl ? targetEl.value : '');
  fd.append('is_active', document.getElementById('filter-is-active').checked ? '1' : '0');

  const runNow = document.getElementById('filter-run-now')?.checked;

  fetch('api.php?action=save_filter', { method: 'POST', body: fd })
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        showToast(data.message || 'Filtre kuralı kaydedildi.', 'success');
        closeFilterModal();
        loadFilters();

        if (runNow && (data.id || filterId)) {
          const runId = data.id || filterId;
          showToast('Filtre kuralı mevcut e-postalara uygulanıyor...', 'info');
          fetch(`api.php?action=run_filters&filter_id=${runId}&folder=${encodeURIComponent(currentFolder)}`)
            .then(parseApiResponseJson)
            .then(res => {
              if (res && res.success) {
                showToast(res.message, 'success');
                loadMessages();
                if (typeof updateFolderCounts === 'function') updateFolderCounts();
                if (typeof loadCustomFolders === 'function') loadCustomFolders();
              } else {
                showToast((res && res.message) || 'Filtre uygulanamadı', 'error');
              }
            })
            .catch(() => loadMessages());
        }
      } else {
        showToast((data && data.message) || 'Filtre kaydedilemedi.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function deleteFilter(id) {
  swalConfirm('Filtreyi Sil', 'Bu filtre kuralını silmek istediğinize emin misiniz?', 'Sil', 'warning')
    .then(res => {
      if (res.isConfirmed) {
        fetch(`api.php?action=delete_filter&id=${id}`)
          .then(parseApiResponseJson)
          .then(data => {
            if (data && data.success) {
              showToast(data.message || 'Filtre kuralı silindi.', 'success');
              loadFilters();
            } else {
              showToast((data && data.message) || 'Filtre silinemedi.', 'error');
            }
          })
          .catch(err => {
            showToast('Bağlantı hatası: ' + err.message, 'error');
          });
      }
    });
}

function toggleFilter(id) {
  fetch(`api.php?action=toggle_filter&id=${id}`)
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        showToast('Filtre durumu güncellendi.', 'success');
        loadFilters();
      } else {
        showToast((data && data.message) || 'Filtre durumu güncellenemedi.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function triggerRunFilters() {
  showToast('Filtreler taranıyor ve çalıştırılıyor...', 'info');
  fetch(`api.php?action=run_filters&folder=${encodeURIComponent(currentFolder)}`)
    .then(parseApiResponseJson)
    .then(data => {
      if (data && data.success) {
        showToast(data.message, 'success');
        loadMessages();
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
        if (typeof loadCustomFolders === 'function') loadCustomFolders();
      } else {
        showToast((data && data.message) || 'Hata oluştu', 'error');
      }
    })
    .catch(() => showToast('Filtre çalıştırma sırasında hata oluştu.', 'error'));
}

// SETTINGS & DIAGNOSTICS
// SETTINGS SUBTAB SWITCHER
function switchSettingsSubTab(sub, btn) {
  document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const appTab = document.getElementById('subtab-appearance');
  if (appTab) appTab.style.display = (sub === 'appearance') ? 'block' : 'none';
  const profTab = document.getElementById('subtab-profile');
  if (profTab) profTab.style.display = (sub === 'profile') ? 'block' : 'none';
  const pwdTab = document.getElementById('subtab-password');
  if (pwdTab) pwdTab.style.display = (sub === 'password') ? 'block' : 'none';
  const sigTab = document.getElementById('subtab-signature');
  if (sigTab) {
    sigTab.style.display = (sub === 'signature') ? 'block' : 'none';
    if (sub === 'signature') updateSigLivePreview();
  }
  const fltTab = document.getElementById('subtab-filters');
  if (fltTab) fltTab.style.display = (sub === 'filters') ? 'block' : 'none';
  const fldTab = document.getElementById('subtab-folders');
  if (fldTab) {
    fldTab.style.display = (sub === 'folders') ? 'block' : 'none';
    if (sub === 'folders') {
      renderSettingsFoldersTable();
      loadTrashRetentionSettings();
      loadSpamRetentionSettings();
    }
  }
  const langTab = document.getElementById('subtab-languages');
  if (langTab) {
    langTab.style.display = (sub === 'languages') ? 'block' : 'none';
    if (sub === 'languages') loadSettingsLanguages();
  }
  const impTab = document.getElementById('subtab-import');
  if (impTab) {
    impTab.style.display = (sub === 'import') ? 'block' : 'none';
    if (sub === 'import') {
      loadImportHistory();
      checkActiveImportStatus();
    }
  }

  if (sub === 'filters') loadFilters();
}

function renderSettingsFoldersTable() {
  const tbody = document.getElementById('settings-folders-tbody');
  if (!tbody) return;

  if (!cachedCustomFolders || cachedCustomFolders.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" style="padding:2.5rem;text-align:center;color:var(--text-muted);">${t('no_custom_folders', 'Henüz özel bir klasör oluşturulmamış.')}</td></tr>`;
    return;
  }

  tbody.innerHTML = cachedCustomFolders.map(cf => {
    let cleanDisplay = cf.display_name;
    if (!cleanDisplay || cleanDisplay.includes('??')) {
      cleanDisplay = decodeImapUtf7(cf.folder_name);
    } else {
      cleanDisplay = decodeImapUtf7(cleanDisplay);
    }
    const isSub = cf.folder_name && cf.folder_name.includes('/');
    const fColor = cf.color || '#4f46e5';
    const escapedFld = (cf.folder_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const escapedDisp = (cleanDisplay || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    const fldCountInfo = cachedFolderCounts && cachedFolderCounts[cf.folder_name];
    const total = fldCountInfo ? (fldCountInfo.total ?? (parseInt(String(fldCountInfo.formatted).replace(/\D/g, ''), 10) || 0)) : 0;
    const unseen = fldCountInfo ? (parseInt(fldCountInfo.unseen, 10) || 0) : 0;

    return `
      <tr style="border-bottom:1px solid var(--border-color);transition:background 0.15s ease;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='transparent'">
        <td style="padding:0.85rem 0.6rem;text-align:center;vertical-align:middle;">
          <span style="display:inline-block;width:14px;height:14px;background:${fColor};border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,0.2);"></span>
        </td>
        <td style="padding:0.85rem 0.6rem;font-weight:600;color:var(--text-primary);vertical-align:middle;">
          <div style="display:flex;align-items:center;gap:6px;">
            ${isSub ? '<span style="opacity:0.5;font-size:0.75rem;">↳</span>' : ''}
            <span>${escapeHtml(cleanDisplay)}</span>
          </div>
        </td>
        <td style="padding:0.85rem 0.6rem;color:var(--text-muted);font-family:monospace;font-size:0.8rem;vertical-align:middle;">
          ${escapeHtml(cf.folder_name)}
        </td>
        <td style="padding:0.85rem 0.6rem;text-align:center;vertical-align:middle;">
          <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.85rem;">
            <strong style="color:${unseen > 0 ? 'var(--primary)' : 'inherit'};">${unseen}</strong>
            <span style="opacity:0.4;">/</span>
            <span>${total}</span>
          </span>
        </td>
        <td style="padding:0.85rem 0.6rem;text-align:right;vertical-align:middle;">
          <div style="display:inline-flex;gap:6px;align-items:center;">
            <button type="button" class="btn-icon" onclick="openRenameFolderModal('${escapedFld}', '${escapedDisp}', '${fColor}', ${cf.id})" title="${t('rename_folder', 'Yeniden Adlandır')}" style="width:auto;height:30px;padding:0 0.65rem;border:1px solid var(--border-color);display:inline-flex;align-items:center;gap:5px;font-size:0.785rem;border-radius:0 !important;background:var(--bg-surface);cursor:pointer;" onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)';" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='inherit';">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              <span>${t('rename_folder', 'Yeniden Adlandır')}</span>
            </button>
            <button type="button" class="btn-icon" onclick="deleteCustomFolder(${cf.id}, '${escapedFld}', '${escapedDisp}')" title="${t('delete_folder', 'Klasörü Sil')}" style="width:auto;height:30px;padding:0 0.65rem;border:1px solid var(--border-color);color:var(--danger);display:inline-flex;align-items:center;gap:5px;font-size:0.785rem;border-radius:0 !important;background:var(--bg-surface);cursor:pointer;" onmouseover="this.style.borderColor='var(--danger)';this.style.background='var(--danger-light)';" onmouseout="this.style.borderColor='var(--border-color)';this.style.background='var(--bg-surface)';">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              <span>${t('common_delete', 'Sil')}</span>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// TRASH RETENTION & AUTO-CLEAN FUNCTIONS (30 GÜN ÇÖP KUTUSU TEMİZLİĞİ)
function loadTrashRetentionSettings() {
  fetch('api.php?action=get_trash_settings')
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        const chk = document.getElementById('setting-auto-clean-trash');
        const daysInp = document.getElementById('setting-trash-retention-days');
        const info = document.getElementById('trash-clean-status-info');
        const noticeText = document.getElementById('trash-notice-text');

        if (chk) chk.checked = !!data.auto_clean;
        if (daysInp) daysInp.value = data.retention_days || 30;
        if (info) {
          info.textContent = data.auto_clean 
            ? `Durum: Otomatik temizleme aktif (${data.retention_days || 30} gün)` 
            : 'Durum: Otomatik temizleme kapalı';
        }
        if (noticeText) {
          noticeText.innerHTML = `Çöp kutusundaki <strong>${data.retention_days || 30} günden eski</strong> e-postalar otomatik olarak kalıcı silinir.`;
        }
      }
    })
    .catch(() => {});
}

function saveTrashRetentionSettings(notify = false) {
  const autoClean = document.getElementById('setting-auto-clean-trash')?.checked ? 1 : 0;
  const days = parseInt(document.getElementById('setting-trash-retention-days')?.value || '30', 10);

  const fd = new FormData();
  fd.append('action', 'save_trash_settings');
  fd.append('auto_clean', autoClean);
  fd.append('retention_days', isNaN(days) || days < 1 ? 30 : days);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        if (notify) showToast(data.message || 'Çöp kutusu ayarları kaydedildi.', 'success');
        const statusEl = document.getElementById('trash-clean-status-info');
        if (statusEl) {
          statusEl.textContent = autoClean 
            ? `Durum: Otomatik temizleme aktif (${days} gün)` 
            : 'Durum: Otomatik temizleme kapalı';
        }
        const noticeText = document.getElementById('trash-notice-text');
        if (noticeText) {
          noticeText.innerHTML = `Çöp kutusundaki <strong>${days} günden eski</strong> e-postalar otomatik olarak kalıcı silinir.`;
        }
      } else {
        if (notify) showToast((data && data.message) || 'Ayarlar kaydedilemedi.', 'error');
      }
    })
    .catch(err => {
      if (notify) showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function triggerCleanTrashNow(days = 30) {
  const retentionDays = parseInt(days, 10) || 30;

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Çöp Kutusunu Temizle',
      text: 'Çöp kutusundaki e-postaları nasıl temizlemek istersiniz?',
      icon: 'warning',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: `${retentionDays} Günden Eskileri Temizle`,
      denyButtonText: 'Tüm Çöp Kutusunu Boşalt',
      cancelButtonText: 'Vazgeç',
      confirmButtonColor: '#4f46e5',
      denyButtonColor: '#ef4444',
      reverseButtons: false
    }).then(res => {
      if (res.isConfirmed) {
        executeCleanTrash(retentionDays);
      } else if (res.isDenied) {
        executeCleanTrash(0);
      }
    });
  } else {
    if (confirm(`Çöp kutusundaki ${retentionDays} günden eski e-postaları kalıcı olarak temizlemek istiyor musunuz?`)) {
      executeCleanTrash(retentionDays);
    }
  }
}

function executeCleanTrash(cleanDays) {
  const isAll = (cleanDays === 0);
  showToast(isAll ? 'Çöp kutusu tamamen boşaltılıyor...' : `${cleanDays} günden eski e-postalar temizleniyor...`, 'info');

  const fd = new FormData();
  fd.append('action', 'clean_trash_now');
  fd.append('days', cleanDays);
  fd.append('folder', currentFolder || 'Trash');

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        showToast(data.message || 'Çöp kutusu temizlendi.', 'success');
        if (typeof loadMessages === 'function') loadMessages(true);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast((data && data.message) || 'Temizleme sırasında hata oluştu.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function openTrashSettingsTab() {
  switchMainTab('settings');
  const btn = document.querySelector(`.settings-tab-btn[onclick*="'folders'"]`);
  if (btn) switchSettingsSubTab('folders', btn);
  setTimeout(() => {
    const el = document.getElementById('setting-trash-retention-days');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, 100);
}

// SPAM RETENTION & AUTO-CLEAN FUNCTIONS (30 GÜN SPAM TEMİZLİĞİ)
function loadSpamRetentionSettings() {
  fetch('api.php?action=get_spam_settings')
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        const chk = document.getElementById('setting-auto-clean-spam');
        const daysInp = document.getElementById('setting-spam-retention-days');
        const info = document.getElementById('spam-clean-status-info');
        const noticeText = document.getElementById('spam-notice-text');

        if (chk) chk.checked = !!data.auto_clean;
        if (daysInp) daysInp.value = data.retention_days || 30;
        if (info) {
          info.textContent = data.auto_clean 
            ? `Durum: Otomatik temizleme aktif (${data.retention_days || 30} gün)` 
            : 'Durum: Otomatik temizleme kapalı';
        }
        if (noticeText) {
          noticeText.innerHTML = `İstenmeyen (Spam) kutusundaki <strong>${data.retention_days || 30} günden eski</strong> e-postalar otomatik olarak kalıcı silinir.`;
        }
      }
    })
    .catch(() => {});
}

function saveSpamRetentionSettings(notify = false) {
  const autoClean = document.getElementById('setting-auto-clean-spam')?.checked ? 1 : 0;
  const days = parseInt(document.getElementById('setting-spam-retention-days')?.value || '30', 10);

  const fd = new FormData();
  fd.append('action', 'save_spam_settings');
  fd.append('auto_clean', autoClean);
  fd.append('retention_days', isNaN(days) || days < 1 ? 30 : days);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        if (notify) showToast(data.message || 'Spam saklama ayarları kaydedildi.', 'success');
        const statusEl = document.getElementById('spam-clean-status-info');
        if (statusEl) {
          statusEl.textContent = autoClean 
            ? `Durum: Otomatik temizleme aktif (${days} gün)` 
            : 'Durum: Otomatik temizleme kapalı';
        }
        const noticeText = document.getElementById('spam-notice-text');
        if (noticeText) {
          noticeText.innerHTML = `İstenmeyen (Spam) kutusundaki <strong>${days} günden eski</strong> e-postalar otomatik olarak kalıcı silinir.`;
        }
      } else {
        if (notify) showToast((data && data.message) || 'Ayarlar kaydedilemedi.', 'error');
      }
    })
    .catch(err => {
      if (notify) showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function triggerCleanSpamNow(days = 30) {
  const retentionDays = parseInt(days, 10) || 30;

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Spam Kutusunu Temizle',
      text: 'İstenmeyen (Spam) kutusundaki e-postaları nasıl temizlemek istersiniz?',
      icon: 'warning',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: `${retentionDays} Günden Eskileri Temizle`,
      denyButtonText: 'Tüm Spam Kutusunu Boşalt',
      cancelButtonText: 'Vazgeç',
      confirmButtonColor: '#4f46e5',
      denyButtonColor: '#ef4444',
      reverseButtons: false
    }).then(res => {
      if (res.isConfirmed) {
        executeCleanSpam(retentionDays);
      } else if (res.isDenied) {
        executeCleanSpam(0);
      }
    });
  } else {
    if (confirm(`Spam kutusundaki ${retentionDays} günden eski e-postaları kalıcı olarak temizlemek istiyor musunuz?`)) {
      executeCleanSpam(retentionDays);
    }
  }
}

function executeCleanSpam(cleanDays) {
  const isAll = (cleanDays === 0);
  showToast(isAll ? 'Spam kutusu tamamen boşaltılıyor...' : `${cleanDays} günden eski spam e-postalar temizleniyor...`, 'info');

  const fd = new FormData();
  fd.append('action', 'clean_spam_now');
  fd.append('days', cleanDays);
  fd.append('folder', currentFolder || '');

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        showToast(data.message || 'Spam kutusu temizlendi.', 'success');
        if (typeof loadMessages === 'function') loadMessages(true);
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
      } else {
        showToast((data && data.message) || 'Temizleme sırasında hata oluştu.', 'error');
      }
    })
    .catch(err => {
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function openSpamSettingsTab() {
  switchMainTab('settings');
  const btn = document.querySelector(`.settings-tab-btn[onclick*="'folders'"]`);
  if (btn) switchSettingsSubTab('folders', btn);
  setTimeout(() => {
    const el = document.getElementById('setting-spam-retention-days');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, 100);
}

// SIGNATURE TAB MANAGEMENT FUNCTIONS
function formatSig(cmd, val = null) {
  const ed = document.getElementById('sig-editor');
  if (!ed) return;
  ed.focus();
  document.execCommand(cmd, false, val);
  updateSigLivePreview();
}

function insertSigLink() {
  const url = prompt('Bağlantı linki (https://...):');
  if (url) formatSig('createLink', url);
}

function updateSigLivePreview() {
  const ed = document.getElementById('sig-editor');
  const prev = document.getElementById('sig-preview-content');
  if (!ed || !prev) return;
  const content = ed.innerHTML.trim();
  prev.innerHTML = content || '<span style="color:var(--text-muted);font-style:italic;">Henüz imza tanımlanmadı.</span>';
}

function applySignatureTemplate(tpl) {
  const ed = document.getElementById('sig-editor');
  if (!ed) return;
  const userEmail = window.CURRENT_USER_EMAIL || '';
  const userName = window.CURRENT_USER_NAME || (userEmail.split('@')[0] || '').replace('.', ' ');
  const domain = userEmail.split('@')[1] || window.MAIL_DOMAIN || '';

  let html = '';
  if (tpl === 'simple') {
    html = `<p style="margin:0;font-size:14px;color:#333;"><strong>Saygılarımla,</strong></p>` +
           `<p style="margin:4px 0 0 0;font-size:14px;color:#111;"><strong>${userName}</strong></p>` +
           `<p style="margin:2px 0 0 0;font-size:12px;color:#666;">${domain ? domain.toUpperCase() : 'Kurumsal İletişim'} | Tel: +90 (000) 000 00 00</p>` +
           `<p style="margin:2px 0 0 0;font-size:12px;color:#4f46e5;">${userEmail}</p>`;
  } else if (tpl === 'corporate') {
    html = `<table style="border-collapse:collapse;font-family:Arial,sans-serif;color:#333;margin-top:10px;">` +
           `  <tr>` +
           `    <td style="border-left:3px solid #4f46e5;padding-left:14px;">` +
           `      <div style="font-size:15px;font-weight:bold;color:#0f172a;">${userName}</div>` +
           `      <div style="font-size:12px;color:#4f46e5;font-weight:600;margin-top:2px;">Kurumsal İletişim &amp; Satış</div>` +
           `      <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;">` +
           `        <strong>Tel:</strong> +90 (312) 000 00 00 &nbsp;|&nbsp; <strong>GSM:</strong> +90 (555) 000 00 00<br>` +
           `        <strong>Web:</strong> <a href="https://${domain}" target="_blank" style="color:#4f46e5;text-decoration:none;">${domain}</a> &nbsp;|&nbsp; <strong>E-Posta:</strong> ${userEmail}` +
           `      </div>` +
           `    </td>` +
           `  </tr>` +
           `</table>`;
  } else if (tpl === 'minimal') {
    html = `<p style="margin:0;font-size:13px;color:#64748b;">İyi çalışmalar dilerim,</p>` +
           `<div style="margin-top:6px;font-size:14px;font-weight:bold;color:#0f172a;">${userName}</div>` +
           `<div style="font-size:12px;color:#64748b;">${userEmail}</div>`;
  }
  ed.innerHTML = html;
  updateSigLivePreview();
}

function clearSignatureEditor() {
  const ed = document.getElementById('sig-editor');
  if (ed) {
    ed.innerHTML = '';
    updateSigLivePreview();
  }
}

function saveSignature(e) {
  if (e) e.preventDefault();
  const ed = document.getElementById('sig-editor');
  const sigContent = ed ? ed.innerHTML : '';
  const btn = document.getElementById('btn-save-sig');
  const oldText = btn ? btn.innerHTML : '';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span>Kaydediliyor...</span>';
  }

  const fd = new FormData();
  fd.append('action', 'save_signature');
  fd.append('signature', sigContent);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldText;
      }
      if (data.success) {
        window.USER_SIGNATURE = sigContent;
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'success',
            title: 'Başarılı!',
            text: data.message,
            confirmButtonText: 'Tamam'
          });
        } else {
          showToast(data.message, 'success');
        }
      } else {
        showToast(data.message || 'İmza kaydedilemedi.', 'error');
      }
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldText;
      }
      showToast('Bağlantı hatası.', 'error');
    });
}

// GLOBAL SERVER CONTACTS SYNC
function syncGlobalContacts() {
  const btn = document.getElementById('btn-sync-global-contacts');
  const oldHtml = btn ? btn.innerHTML : '';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `<svg width="15" height="15" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:0.3rem;"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg><span>Çekiliyor...</span>`;
  }

  fetch('api.php?action=sync_global_contacts', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
      }
      if (data.success) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'success',
            title: 'Global Sunucu Rehberi',
            html: `
              <div style="text-align:left;font-size:0.9rem;line-height:1.6;">
                <p>${data.message}</p>
                <div style="margin-top:0.75rem;padding:0.75rem;background:var(--bg-surface-elevated);border:1px solid var(--border-color);">
                  <div><strong>Eklenen Yeni Kişi:</strong> ${data.added_count}</div>
                  <div><strong>Zaten Kayıtlı Olan:</strong> ${data.existing_count}</div>
                  <div><strong>Toplam Rehber Kişi Sayısı:</strong> ${data.total}</div>
                </div>
              </div>
            `,
            confirmButtonText: 'Tamam'
          });
        } else {
          showToast(data.message, 'success');
        }
        loadContacts();
        const sub = document.getElementById('contacts-header-subtitle');
        if (sub && data.total) sub.textContent = `${data.total} kayıtlı kişi listeleniyor`;
      } else {
        showToast(data.message || 'Global rehber senkronizasyonu başarısız oldu.', 'error');
      }
    })
    .catch(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
      }
      showToast('Sunucu bağlantı hatası.', 'error');
    });
}

function goToProfileSettings() {
  switchMainTab('settings');
  const btn = document.querySelector('.settings-tab-btn[onclick*="profile"]');
  if (btn) switchSettingsSubTab('profile', btn);
}

function updateProfileLivePreview(val) {
  const preview = document.getElementById('prof-live-sender-preview');
  const avatar = document.getElementById('profile-preview-avatar');
  const email = window.CURRENT_USER_EMAIL || '';
  const name = val.trim() || email.split('@')[0];
  if (preview) {
    preview.innerHTML = `Kimden: "<strong>${escapeHtml(name)}</strong>" &lt;${escapeHtml(email)}&gt;`;
  }
  if (avatar) {
    avatar.textContent = name.charAt(0).toUpperCase();
  }
}

function handleUpdateProfile(e) {
  e.preventDefault();
  const name = document.getElementById('prof-display-name').value.trim();
  if (!name) {
    showToast('Lütfen geçerli bir görünen ad girin.', 'error');
    return;
  }
  const btn = document.getElementById('btn-save-profile');
  const oldText = btn.innerHTML;
  btn.disabled = true;
  btn.textContent = 'Kaydediliyor...';

  const formData = new FormData();
  formData.append('action', 'update_profile');
  formData.append('name', name);

  fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      if (data.success) {
        showToast(data.message, 'success');
        document.querySelectorAll('.account-name').forEach(el => el.textContent = data.name);
        document.querySelectorAll('.account-avatar').forEach(el => el.textContent = data.initial);
      } else {
        showToast(data.message || 'Profil güncellenemedi.', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      showToast('Bağlantı hatası.', 'error');
    });
}

function goToPasswordChange() {
  switchMainTab('settings');
  const btn = document.querySelector('.settings-tab-btn[onclick*="password"]');
  if (btn) switchSettingsSubTab('password', btn);
}

function handlePasswordChange(e) {
  e.preventDefault();
  const currentPass = document.getElementById('pwd-current').value;
  const newPass = document.getElementById('pwd-new').value;
  const confirmPass = document.getElementById('pwd-confirm').value;

  if (newPass !== confirmPass) {
    showToast('Yeni şifreler birbiriyle eşleşmiyor.', 'error');
    return;
  }
  if (newPass.length < 8 || !/[A-Z]/.test(newPass) || !/[a-z]/.test(newPass) || !/[0-9]/.test(newPass)) {
    showToast('Yeni şifre sunucu kuralına uygun olmalıdır: En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.', 'warning');
    return;
  }

  const btn = document.getElementById('btn-change-pwd');
  const oldText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg width="15" height="15" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:0.4rem;"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg><span>Güncelleniyor...</span>`;

  const fd = new FormData();
  fd.append('action', 'change_password');
  fd.append('current_password', currentPass);
  fd.append('new_password', newPass);
  fd.append('confirm_password', confirmPass);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      if (res.success) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'success',
            title: 'Başarılı!',
            text: res.message,
            confirmButtonText: 'Tamam'
          });
        } else {
          showToast(res.message, 'success');
        }
        document.getElementById('pwd-current').value = '';
        document.getElementById('pwd-new').value = '';
        document.getElementById('pwd-confirm').value = '';
      } else {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'error',
            title: 'Hata',
            text: res.message || 'Şifre güncellenemedi.',
            confirmButtonText: 'Tekrar Dene'
          });
        } else {
          showToast(res.message || 'Şifre güncellenemedi.', 'error');
        }
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function toggleInputVisibility(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  inp.type = (inp.type === 'password') ? 'text' : 'password';
}

function openAccountModal() {
  document.getElementById('acc-id').value = '';
  document.getElementById('acc-email').value = '';
  document.getElementById('acc-pass').value = '';
  document.getElementById('account-modal').classList.add('active');
}

function closeAccountModal() {
  document.getElementById('account-modal').classList.remove('active');
}

function deleteAccount(id) {
  swalConfirm('Hesabı Sil', 'Hesabı silmek istiyor musunuz?', 'Sil', 'warning')
    .then(res => {
      if (res.isConfirmed) {
        fetch(`api.php?action=delete_account&id=${id}`)
          .then(() => {
            showToast('Hesap silindi.');
            setTimeout(() => window.location.reload(), 600);
          });
      }
    });
}

function switchAccount(id) {
  fetch(`api.php?action=switch_account&id=${id}`)
    .then(() => window.location.reload());
}

// ==========================================
// LANGUAGE MANAGEMENT & TRANSLATION EDITOR
// ==========================================
let activeEditingLangCode = null;
let activeEditingLangData = null;
let referenceTurkishTrans = null;
let transEditorMode = 'table'; // 'table' or 'raw'

function loadSettingsLanguages() {
  const tbody = document.getElementById('lang-manager-table-body');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--text-muted);">${typeof t === 'function' ? t('loading', 'Yükleniyor...') : 'Yükleniyor...'}</td></tr>`;

  fetch('api.php?action=languages_list')
    .then(r => r.json())
    .then(res => {
      if (res.success && Array.isArray(res.data)) {
        renderSettingsLanguagesTable(res.data);
        if (typeof renderLanguageMenus === 'function') {
          renderLanguageMenus(res.data);
        }
      } else {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--danger);">${res.message || 'Diller listelenemedi.'}</td></tr>`;
      }
    })
    .catch(err => {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--danger);">Bağlantı hatası: ${err.message}</td></tr>`;
    });
}

function renderSettingsLanguagesTable(langs) {
  const tbody = document.getElementById('lang-manager-table-body');
  if (!tbody) return;

  if (langs.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--text-muted);">Hiçbir dil dosyası bulunamadı.</td></tr>`;
    return;
  }

  tbody.innerHTML = langs.map(l => {
    const isCurrent = (l.code === currentLang);
    const flag = l.flag || '🌐';
    const langName = l.name || (typeof t === 'function' ? t('lang_' + l.code, l.code) : l.code);

    let statusBadge = '';
    if (isCurrent) {
      statusBadge = `<span class="badge" style="background:#10b981;color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;">${typeof t === 'function' ? t('settings_status_active', 'Aktif Dil') : 'Aktif Dil'}</span>`;
    } else if (l.is_default) {
      statusBadge = `<span class="badge" style="background:var(--primary);color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;">${typeof t === 'function' ? t('settings_status_default', 'Varsayılan') : 'Varsayılan'}</span>`;
    } else {
      statusBadge = `<span class="badge" style="background:var(--bg-hover);color:var(--text-secondary);border:1px solid var(--border-color);border-radius:0 !important;font-size:0.75rem;padding:3px 7px;">${typeof t === 'function' ? t('settings_status_installed', 'Yüklü') : 'Yüklü'}</span>`;
    }

    const deleteBtn = !l.is_default 
      ? `<button type="button" class="lang-manager-icon-btn btn-danger" title="${typeof t === 'function' ? t('settings_btn_delete_lang', 'Dili Sil') : 'Dili Sil'}" onclick="promptDeleteLanguage('${l.code}', '${escapeHtml(langName)}')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>`
      : '';

    const count = (l.keys_count !== undefined) ? l.keys_count : ((l.key_count !== undefined) ? l.key_count : 0);
    let flagDisplay = flag;
    if (flag === '🇹🇷' || flag === '🇬🇧' || flag === '🇩🇪' || !flag || flag === '🌐') {
      const flagSvg = typeof getLangFlagIconHtml === 'function' ? getLangFlagIconHtml(l.code) : '';
      if (flagSvg && flagSvg.includes('<svg')) {
        flagDisplay = flagSvg;
      }
    }

    return `
      <tr style="border-bottom:1px solid var(--border-color);">
        <td style="text-align:center;font-size:1.25rem;vertical-align:middle;width:48px;">${flagDisplay}</td>
        <td style="vertical-align:middle;">
          <strong style="color:var(--text-primary);font-size:0.9rem;">${escapeHtml(langName)}</strong>
        </td>
        <td style="vertical-align:middle;"><code style="background:var(--bg-hover);padding:2px 6px;border:1px solid var(--border-color);font-size:0.8rem;">${escapeHtml(l.code)}</code></td>
        <td style="vertical-align:middle;"><span style="font-weight:600;font-size:0.85rem;">${count}</span> <span style="color:var(--text-muted);font-size:0.75rem;">anahtar</span></td>
        <td style="vertical-align:middle;">${statusBadge}</td>
        <td style="text-align:right;vertical-align:middle;">
          <div style="display:inline-flex;align-items:center;gap:0.4rem;">
            <button type="button" class="lang-manager-icon-btn" title="${typeof t === 'function' ? t('settings_btn_edit_trans', 'Düzenle') : 'Düzenle'}" onclick="openTranslationEditor('${l.code}', '${escapeHtml(langName)}', '${escapeHtml(flag)}')">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <a href="api.php?action=language_download&code=${l.code}" class="lang-manager-icon-btn" title="${typeof t === 'function' ? t('settings_btn_export_json', 'JSON İndir') : 'JSON İndir'}">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </a>
            ${deleteBtn}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function openTranslationEditor(code, name, flag) {
  activeEditingLangCode = code;
  transEditorMode = 'table';

  const flagInp = document.getElementById('trans-modal-flag-input');
  if (flagInp) flagInp.value = flag || '🌐';

  const nameInp = document.getElementById('trans-modal-name-input');
  if (nameInp) nameInp.value = name || code;

  const codeEl = document.getElementById('trans-modal-code');
  if (codeEl) codeEl.textContent = (code || '').toUpperCase();

  const searchInp = document.getElementById('trans-search-input');
  if (searchInp) searchInp.value = '';

  const tableContainer = document.getElementById('trans-modal-table-container');
  const rawContainer = document.getElementById('trans-modal-raw-container');
  const modeBtn = document.getElementById('trans-mode-btn');

  if (tableContainer) tableContainer.style.display = 'block';
  if (rawContainer) rawContainer.style.display = 'none';
  if (modeBtn) modeBtn.textContent = 'Ham JSON Görünümü';

  const modal = document.getElementById('trans-editor-modal');
  if (modal) modal.style.display = 'flex';

  // Load Turkish reference first if not cached
  const ensureTurkish = (code === 'tr') 
    ? Promise.resolve() 
    : (referenceTurkishTrans ? Promise.resolve() : fetch('api.php?action=language_get&code=tr').then(r => r.json()).then(res => { if (res.success) referenceTurkishTrans = res.data.translations; }));

  ensureTurkish.then(() => {
    fetch('api.php?action=language_get&code=' + encodeURIComponent(code))
      .then(r => r.json())
      .then(res => {
        if (res.success && res.data) {
          activeEditingLangData = res.data;
          if (res.data._meta) {
            if (flagInp && res.data._meta.flag) flagInp.value = res.data._meta.flag;
            if (nameInp && res.data._meta.name) nameInp.value = res.data._meta.name;
          }
          if (code === 'tr') referenceTurkishTrans = res.data.translations;
          renderTranslationEditorRows('');
          updateRawJsonView();
        } else {
          showToast(res.message || 'Çeviriler yüklenemedi.', 'error');
        }
      })
      .catch(err => showToast('Hata: ' + err.message, 'error'));
  });
}

function closeTranslationEditor() {
  const modal = document.getElementById('trans-editor-modal');
  if (modal) modal.style.display = 'none';
  activeEditingLangCode = null;
  activeEditingLangData = null;
}

function renderTranslationEditorRows(filterText = '') {
  const tbody = document.getElementById('trans-editor-table-body');
  if (!tbody || !activeEditingLangData) return;

  const trans = activeEditingLangData.translations || {};
  const refTrans = referenceTurkishTrans || {};
  
  // Combine all keys from Turkish reference and current translation
  const allKeysSet = new Set([...Object.keys(refTrans), ...Object.keys(trans)]);
  const allKeys = Array.from(allKeysSet).sort();

  const query = (filterText || '').trim().toLowerCase();
  const filteredKeys = allKeys.filter(k => {
    if (!query) return true;
    const val = String(trans[k] || '').toLowerCase();
    const refVal = String(refTrans[k] || '').toLowerCase();
    return k.toLowerCase().includes(query) || val.includes(query) || refVal.includes(query);
  });

  const countBadge = document.getElementById('trans-keys-count-badge');
  if (countBadge) {
    countBadge.textContent = `${filteredKeys.length} / ${allKeys.length} çeviri`;
  }

  if (filteredKeys.length === 0) {
    tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--text-muted);">Aramanızla eşleşen çeviri anahtarı bulunamadı.</td></tr>`;
    return;
  }

  tbody.innerHTML = filteredKeys.map(k => {
    const refVal = refTrans[k] !== undefined ? String(refTrans[k]) : '';
    const currentVal = trans[k] !== undefined ? String(trans[k]) : '';

    return `
      <tr style="border-bottom:1px solid var(--border-color);vertical-align:middle;">
        <td style="padding:0.6rem;font-family:monospace;font-size:0.8rem;color:var(--primary);font-weight:600;">
          ${escapeHtml(k)}
        </td>
        <td style="padding:0.6rem;color:var(--text-muted);font-size:0.825rem;">
          ${escapeHtml(refVal)}
        </td>
        <td style="padding:0.4rem 0.6rem;">
          <input type="text" class="form-control trans-val-input" data-key="${escapeHtml(k)}" value="${escapeHtml(currentVal)}" style="height:34px;font-size:0.85rem;border-radius:0 !important;width:100%;box-sizing:border-box;">
        </td>
      </tr>
    `;
  }).join('');
}

function filterTranslationKeys(query) {
  renderTranslationEditorRows(query);
}

function toggleTranslationMode() {
  const tableContainer = document.getElementById('trans-modal-table-container');
  const rawContainer = document.getElementById('trans-modal-raw-container');
  const modeBtn = document.getElementById('trans-mode-btn');

  if (transEditorMode === 'table') {
    // Switch to Raw JSON
    syncTableInputsToActiveData();
    updateRawJsonView();
    transEditorMode = 'raw';
    if (tableContainer) tableContainer.style.display = 'none';
    if (rawContainer) rawContainer.style.display = 'block';
    if (modeBtn) modeBtn.textContent = 'Görsel Tablo Görünümü';
  } else {
    // Switch back to Table view (validate JSON)
    const rawText = document.getElementById('trans-raw-textarea').value;
    try {
      const parsed = JSON.parse(rawText);
      const newTrans = parsed.translations || parsed;
      delete newTrans._meta;
      activeEditingLangData.translations = newTrans;
      if (parsed._meta) {
        if (parsed._meta.name && document.getElementById('trans-modal-name-input')) {
          document.getElementById('trans-modal-name-input').value = parsed._meta.name;
        }
        if (parsed._meta.flag && document.getElementById('trans-modal-flag-input')) {
          document.getElementById('trans-modal-flag-input').value = parsed._meta.flag;
        }
      }
      transEditorMode = 'table';
      if (tableContainer) tableContainer.style.display = 'block';
      if (rawContainer) rawContainer.style.display = 'none';
      if (modeBtn) modeBtn.textContent = 'Ham JSON Görünümü';
      renderTranslationEditorRows(document.getElementById('trans-search-input').value);
    } catch(err) {
      showToast('JSON sözdizimi hatalı: ' + err.message, 'error');
    }
  }
}

function syncTableInputsToActiveData() {
  if (!activeEditingLangData) return;
  if (!activeEditingLangData.translations) activeEditingLangData.translations = {};
  document.querySelectorAll('.trans-val-input').forEach(inp => {
    const key = inp.getAttribute('data-key');
    activeEditingLangData.translations[key] = inp.value;
  });
}

function updateRawJsonView() {
  const textarea = document.getElementById('trans-raw-textarea');
  if (textarea && activeEditingLangData) {
    const flagInp = document.getElementById('trans-modal-flag-input');
    const nameInp = document.getElementById('trans-modal-name-input');
    if (!activeEditingLangData._meta) activeEditingLangData._meta = {};
    if (flagInp && flagInp.value.trim()) activeEditingLangData._meta.flag = flagInp.value.trim();
    if (nameInp && nameInp.value.trim()) activeEditingLangData._meta.name = nameInp.value.trim();
    textarea.value = JSON.stringify(activeEditingLangData, null, 2);
  }
}

function saveActiveLanguageTranslations() {
  if (!activeEditingLangCode || !activeEditingLangData) return;

  const flagInp = document.getElementById('trans-modal-flag-input');
  const nameInp = document.getElementById('trans-modal-name-input');
  const newFlag = (flagInp && flagInp.value.trim()) ? flagInp.value.trim() : (activeEditingLangData._meta?.flag || '🌐');
  const newName = (nameInp && nameInp.value.trim()) ? nameInp.value.trim() : (activeEditingLangData._meta?.name || activeEditingLangCode);

  if (transEditorMode === 'raw') {
    const rawText = document.getElementById('trans-raw-textarea').value;
    try {
      const parsed = JSON.parse(rawText);
      const newTrans = parsed.translations || parsed;
      delete newTrans._meta;
      activeEditingLangData.translations = newTrans;
    } catch(err) {
      showToast('Geçersiz JSON formatı: ' + err.message, 'error');
      return;
    }
  } else {
    syncTableInputsToActiveData();
  }

  if (!activeEditingLangData._meta) activeEditingLangData._meta = {};
  activeEditingLangData._meta.name = newName;
  activeEditingLangData._meta.flag = newFlag;

  const fd = new FormData();
  fd.append('action', 'language_save');
  fd.append('code', activeEditingLangCode);
  fd.append('name', newName);
  fd.append('flag', newFlag);
  fd.append('translations', JSON.stringify(activeEditingLangData.translations));

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message || 'Çeviriler başarıyla kaydedildi.', 'success');
        
        // Update in-memory translations
        if (typeof I18N_TRANSLATIONS !== 'undefined') {
          I18N_TRANSLATIONS[activeEditingLangCode] = activeEditingLangData.translations;
        }
        
        // Re-apply DOM if currently active language
        if (activeEditingLangCode === currentLang && typeof applyDomTranslations === 'function') {
          applyDomTranslations();
        }

        closeTranslationEditor();
        loadSettingsLanguages();
      } else {
        showToast(res.message || 'Kayıt sırasında hata oluştu.', 'error');
      }
    })
    .catch(err => showToast('Sunucu hatası: ' + err.message, 'error'));
}

function openNewLanguageModal() {
  document.getElementById('new-lang-code').value = '';
  document.getElementById('new-lang-name').value = '';
  document.getElementById('new-lang-flag').value = '🌐';
  document.getElementById('new-lang-modal').style.display = 'flex';
}

function closeNewLanguageModal() {
  document.getElementById('new-lang-modal').style.display = 'none';
}

function submitCreateNewLanguage() {
  const code = document.getElementById('new-lang-code').value.trim().toLowerCase();
  const name = document.getElementById('new-lang-name').value.trim();
  const flag = document.getElementById('new-lang-flag').value.trim() || '🌐';
  const copyFrom = document.getElementById('new-lang-copy').value;

  if (!code || code.length < 2) {
    showToast('Lütfen en az 2 karakterli bir dil kodu girin (örn: fr, es, it).', 'error');
    return;
  }
  if (!name) {
    showToast('Lütfen dil adını girin (örn: Français, Español).', 'error');
    return;
  }

  const fd = new FormData();
  fd.append('action', 'language_create');
  fd.append('code', code);
  fd.append('name', name);
  fd.append('flag', flag);
  fd.append('copy_from', copyFrom);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message || 'Dil başarıyla oluşturuldu.', 'success');
        closeNewLanguageModal();
        loadSettingsLanguages();
        openTranslationEditor(code, name, flag);
      } else {
        showToast(res.message || 'Dil oluşturulamadı.', 'error');
      }
    })
    .catch(err => showToast('Hata: ' + err.message, 'error'));
}

function triggerLanguageUpload() {
  const inp = document.getElementById('lang-file-upload-input');
  if (inp) {
    inp.value = '';
    inp.click();
  }
}

function handleLanguageFileUpload(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  const fd = new FormData();
  fd.append('action', 'language_upload');
  fd.append('file', file);

  showToast('Dil dosyası yükleniyor...', 'info');

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message || 'Dil dosyası başarıyla yüklendi.', 'success');
        loadSettingsLanguages();
      } else {
        showToast(res.message || 'Yükleme başarısız.', 'error');
      }
    })
    .catch(err => showToast('Hata: ' + err.message, 'error'));
}

function promptDeleteLanguage(code, name) {
  swalConfirm(
    'Dili Sil',
    `"${name}" (${code}) dil dosyasını silmek istediğinize emin misiniz?`,
    'Evet, Dili Sil',
    'warning'
  ).then(res => {
    if (res.isConfirmed) {
      const fd = new FormData();
      fd.append('action', 'language_delete');
      fd.append('code', code);

      fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || 'Dil silindi.', 'success');
            if (currentLang === code) {
              setLanguage('tr');
            }
            loadSettingsLanguages();
          } else {
            showToast(data.message || 'Silinemedi.', 'error');
          }
        })
        .catch(err => showToast('Hata: ' + err.message, 'error'));
    }
  });
}

// ==========================================
// EMAIL IMPORT & MIGRATION MANAGEMENT
// ==========================================
const IMPORT_PRESETS = {
  custom: {
    host: '',
    port: 993,
    enc: 'ssl',
    tipTitle: '',
    tipDesc: ''
  }
};

function applyImportPreset(type, btn) {
  document.querySelectorAll('.import-preset-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  const preset = IMPORT_PRESETS[type] || IMPORT_PRESETS.custom;
  const hostInp = document.getElementById('imp-host');
  const portInp = document.getElementById('imp-port');
  const encSel  = document.getElementById('imp-enc');
  const tipBox  = document.getElementById('import-provider-tip');
  const tipT    = document.getElementById('import-tip-title');
  const tipD    = document.getElementById('import-tip-desc');

  if (hostInp && preset.host) hostInp.value = preset.host;
  if (portInp && preset.port) portInp.value = preset.port;
  if (encSel && preset.enc) encSel.value = preset.enc;

  if (preset.tipTitle && preset.tipDesc) {
    if (tipT) tipT.innerHTML = preset.tipTitle;
    if (tipD) tipD.innerHTML = preset.tipDesc;
    if (tipBox) tipBox.style.display = 'block';
  } else {
    if (tipBox) tipBox.style.display = 'none';
  }
}

function applyRegisteredAccountPreset(jsonStr) {
  if (!jsonStr) return;
  try {
    const acc = JSON.parse(jsonStr);
    const emInp   = document.getElementById('imp-email');
    const hostInp = document.getElementById('imp-host');
    const portInp = document.getElementById('imp-port');
    const encSel  = document.getElementById('imp-enc');
    const pwdInp  = document.getElementById('imp-password');

    if (emInp && acc.email) emInp.value = acc.email;
    if (hostInp && acc.host) hostInp.value = acc.host;
    if (portInp && acc.port) portInp.value = acc.port || 993;
    if (encSel && acc.enc) encSel.value = acc.enc || 'ssl';
    
    document.querySelectorAll('.import-preset-btn').forEach(b => b.classList.remove('active'));
    const customBtn = document.querySelector('.import-preset-btn[data-preset="custom"]');
    if (customBtn) customBtn.classList.add('active');

    const tipBox = document.getElementById('import-provider-tip');
    if (tipBox) tipBox.style.display = 'none';

    if (pwdInp) {
      pwdInp.value = '';
      pwdInp.focus();
    }
    showToast('Sunucu ve e-posta bilgileri aktarıldı, lütfen şifrenizi girin.', 'info');
  } catch (e) {
    console.error(e);
  }
}

function toggleCustomTargetInput(mode) {
  const wrap = document.getElementById('imp-custom-target-wrap');
  if (wrap) {
    wrap.style.display = (mode === 'custom') ? 'block' : 'none';
  }
}

function toggleImportPassVisibility() {
  const inp = document.getElementById('imp-password');
  const icon = document.getElementById('imp-eye-icon');
  if (!inp) return;
  if (inp.type === 'password') {
    inp.type = 'text';
    if (icon) {
      icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    }
  } else {
    inp.type = 'password';
    if (icon) {
      icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
  }
}

async function safeFetchJson(url, options = {}) {
  const r = await fetch(url, options);
  const text = await r.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    let cleanText = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
    if (cleanText.length > 200) cleanText = cleanText.substring(0, 200) + '...';
    throw new Error(cleanText || `Sunucu geçersiz yanıt verdi (HTTP ${r.status})`);
  }
}

function testImportConnection() {
  const email = (document.getElementById('imp-email')?.value || '').trim();
  const pass  = document.getElementById('imp-password')?.value || '';
  const host  = (document.getElementById('imp-host')?.value || '').trim();
  const port  = (document.getElementById('imp-port')?.value || '993').trim();
  const enc   = document.getElementById('imp-enc')?.value || 'ssl';

  if (!email || !pass || !host) {
    showToast('Lütfen e-posta adresi, şifre ve sunucu adını girin.', 'error');
    return;
  }

  const btn = document.getElementById('btn-test-import');
  const oldText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg width="15" height="15" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:0.4rem;"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg><span>Bağlantı Test Ediliyor...</span>`;

  const fd = new FormData();
  fd.append('action', 'test_import_connection');
  fd.append('source_email', email);
  fd.append('source_password', pass);
  fd.append('source_host', host);
  fd.append('source_port', port);
  fd.append('source_encryption', enc);

  safeFetchJson('api.php', { method: 'POST', body: fd })
    .then(res => {
      btn.disabled = false;
      btn.innerHTML = oldText;

      const dBox = document.getElementById('import-discovery-box');
      if (res.success) {
        if (dBox) dBox.style.display = 'block';

        const sumEl = document.getElementById('import-discovery-summary');
        if (sumEl) sumEl.textContent = `— Toplam ${res.total_messages || 0} e-posta tespit edildi (${res.folders ? res.folders.length : 0} klasör).`;

        const badgeEl = document.getElementById('import-discovery-badge');
        if (badgeEl) badgeEl.textContent = `${res.total_messages || 0} E-Posta`;

        // Update folder select
        const fldSelect = document.getElementById('imp-source-folder');
        if (fldSelect && Array.isArray(res.folders)) {
          let opts = `<option value="ALL">📦 Tüm Klasörler (Tümü: ${res.total_messages || 0} e-posta)</option>`;
          res.folders.forEach(f => {
            opts += `<option value="${escapeHtml(f.name)}">📁 ${escapeHtml(f.display_name || f.name)} (${f.total || 0} e-posta)</option>`;
          });
          fldSelect.innerHTML = opts;
        }

        // Render folder chips preview
        const fldList = document.getElementById('import-folders-list');
        if (fldList && Array.isArray(res.folders)) {
          fldList.innerHTML = res.folders.map(f => `
            <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:3px 8px;background:var(--bg-hover);border:1px solid var(--border-color);font-size:0.75rem;border-radius:0 !important;">
              <span>${escapeHtml(f.display_name || f.name)}:</span>
              <strong style="color:var(--primary);">${f.total || 0}</strong>
            </span>
          `).join('');
        }

        showToast(res.message || 'Bağlantı ve tarama başarılı!', 'success');
      } else {
        if (dBox) dBox.style.display = 'none';
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'error',
            title: 'Bağlantı Hatası',
            html: `<div style="text-align:left;font-size:0.9rem;line-height:1.5;">${escapeHtml(res.message || 'Dış posta sunucusuna bağlanılamadı.')}</div>`,
            confirmButtonText: 'Tamam'
          });
        } else {
          showToast(res.message || 'Bağlantı hatası.', 'error');
        }
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

let importPollInterval = null;
let currentActiveJobId = null;

function checkActiveImportStatus(showNotificationOnComplete = false) {
  safeFetchJson('api.php?action=get_active_import')
    .then(res => {
      const progBox = document.getElementById('import-progress-box');
      const btnStart = document.getElementById('btn-start-import');
      const btnTest = document.getElementById('btn-test-import');
      const bar = document.getElementById('import-progress-bar');
      const statDetails = document.getElementById('import-stat-details');
      const statPercent = document.getElementById('import-stat-percent');
      const statusTitle = document.getElementById('import-progress-status');

      if (res.success && res.has_active && res.job) {
        const job = res.job;
        currentActiveJobId = job.id;

        if (progBox) progBox.style.display = 'block';
        if (btnStart) btnStart.disabled = true;
        if (btnTest) btnTest.disabled = true;

        const imported = parseInt(job.imported_count || 0, 10);
        const skipped = parseInt(job.skipped_count || 0, 10);
        const failed = parseInt(job.failed_count || 0, 10);
        const total = (job.total_found && parseInt(job.total_found) > 0) ? parseInt(job.total_found, 10) : 0;
        const totalProcessed = imported + skipped + failed;

        let pct = parseInt(job.progress_percent || 0, 10);
        if (total > 0 && totalProcessed > 0) {
          pct = Math.max(pct, Math.min(99, Math.round((totalProcessed / total) * 100)));
        }
        pct = Math.min(100, Math.max(0, pct));
        if (bar) bar.style.width = pct + '%';
        if (statPercent) statPercent.textContent = '%' + pct;

        const currentFld = job.current_folder ? ` &bull; Aktarılan Klasör: <strong>${escapeHtml(job.current_folder)}</strong>` : '';
        const skippedTxt = skipped > 0 ? ` <span style="background:rgba(245,158,11,0.15);color:#d97706;padding:2px 6px;border-radius:3px;font-size:0.75rem;font-weight:600;margin-left:4px;">${skipped} mükerrer atlandı</span>` : '';
        const totalDisplay = total > 0 ? total : '?';

        if (statDetails) {
          statDetails.innerHTML = `Kaynak: <strong>${escapeHtml(job.source_email)}</strong> &bull; Aktarılan: <strong style="color:var(--primary);">${imported} / ${totalDisplay}</strong>${skippedTxt}${currentFld}`;
        }
        if (statusTitle) {
          let statusSub = `${imported} adet aktarıldı`;
          if (skipped > 0) statusSub += `, ${skipped} mükerrer atlandı`;
          statusTitle.textContent = `E-postalar arka planda aktarılıyor (${statusSub})...`;
        }

        // Keep polling every 3 seconds
        if (!importPollInterval) {
          importPollInterval = setInterval(() => checkActiveImportStatus(true), 3000);
        }
      } else if (res.recent_job && showNotificationOnComplete) {
        // Just finished!
        if (importPollInterval) {
          clearInterval(importPollInterval);
          importPollInterval = null;
        }

        if (progBox) progBox.style.display = 'none';
        if (btnStart) btnStart.disabled = false;
        if (btnTest) btnTest.disabled = false;
        currentActiveJobId = null;

        const rj = res.recent_job;
        if (rj.status === 'completed') {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'success',
              title: 'İçe Aktarma Tamamlandı!',
              html: `<div style="text-align:left;font-size:0.95rem;line-height:1.6;">
                <p><strong>${rj.imported_count || 0}</strong> adet e-posta sunucu arka planında başarıyla aktarıldı.</p>
                ${rj.skipped_count > 0 ? `<p style="color:var(--text-muted);font-size:0.85rem;">${rj.skipped_count} adet daha önce aktarılmış mükerrer e-posta atlandı.</p>` : ''}
              </div>`,
              confirmButtonText: 'Harika'
            });
          } else {
            showToast(`İçe aktarma tamamlandı: ${rj.imported_count || 0} e-posta aktarıldı.`, 'success');
          }
        } else if (rj.status === 'completed_with_errors') {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'warning',
              title: 'Aktarım Kısmen Tamamlandı',
              html: `<div style="text-align:left;font-size:0.95rem;line-height:1.6;">
                <p><strong>${rj.imported_count || 0}</strong> adet e-posta başarıyla aktarıldı.</p>
                ${rj.skipped_count > 0 ? `<p style="color:var(--text-muted);font-size:0.85rem;">${rj.skipped_count} adet mükerrer e-posta atlandı.</p>` : ''}
                ${rj.error_message ? `<p style="color:var(--danger);font-size:0.85rem;margin-top:8px;">${escapeHtml(rj.error_message)}</p>` : ''}
              </div>`,
              confirmButtonText: 'Tamam'
            });
          } else {
            showToast(`Aktarım kısmen tamamlandı: ${rj.imported_count || 0} aktarıldı.`, 'warning');
          }
        } else if (rj.status === 'failed') {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'error',
              title: 'İçe Aktarma Başarısız Oldu',
              text: rj.error_message || 'Bilinmeyen bir hata oluştu.',
              confirmButtonText: 'Kapat'
            });
          } else {
            showToast('İçe aktarma başarısız: ' + (rj.error_message || ''), 'error');
          }
        } else if (rj.status === 'cancelled') {
          showToast('İçe aktarma işlemi durduruldu.', 'info');
        }

        loadImportHistory();
        if (typeof updateFolderCounts === 'function') updateFolderCounts();
        if (typeof loadMessages === 'function') loadMessages(false);
        if (typeof loadCustomFolders === 'function') loadCustomFolders();
      } else {
        // No active job
        if (importPollInterval) {
          clearInterval(importPollInterval);
          importPollInterval = null;
        }
        if (progBox) progBox.style.display = 'none';
        if (btnStart) btnStart.disabled = false;
        if (btnTest) btnTest.disabled = false;
        currentActiveJobId = null;
      }
    })
    .catch(() => {});
}

function cancelActiveImport() {
  const performCancel = () => {
    const fd = new FormData();
    fd.append('action', 'cancel_email_import');
    if (currentActiveJobId) fd.append('job_id', currentActiveJobId);

    safeFetchJson('api.php', { method: 'POST', body: fd })
      .then(res => {
        showToast(res.message || 'İçe aktarma durduruldu.', 'info');
        checkActiveImportStatus();
        loadImportHistory();
      })
      .catch(err => {
        showToast('Durdurma hatası: ' + err.message, 'error');
      });
  };

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'İçe Aktarma Durdurulsun mu?',
      text: 'Arka planda çalışan aktarım işlemi durdurulacaktır. O ana kadar aktarılmış olan e-postalar posta kutunuzda korunur.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Evet, Durdur',
      cancelButtonText: 'Vazgeç',
      confirmButtonColor: '#ef4444'
    }).then(res => {
      if (res.isConfirmed) {
        performCancel();
      }
    });
  } else {
    if (confirm('İçe aktarmayı durdurmak istediğinize emin misiniz?')) {
      performCancel();
    }
  }
}

function startEmailImport() {
  const email = (document.getElementById('imp-email')?.value || '').trim();
  const pass  = document.getElementById('imp-password')?.value || '';
  const host  = (document.getElementById('imp-host')?.value || '').trim();
  const port  = (document.getElementById('imp-port')?.value || '993').trim();
  const enc   = document.getElementById('imp-enc')?.value || 'ssl';

  const sourceFolder = document.getElementById('imp-source-folder')?.value || 'ALL';
  const targetMode   = document.getElementById('imp-target-mode')?.value || 'same';
  const customTarget = (document.getElementById('imp-custom-target')?.value || 'Eski_Postalar').trim();
  const dateFilter   = document.getElementById('imp-date-filter')?.value || 'all';
  const limitCount   = document.getElementById('imp-limit-count')?.value || '0';
  const skipDup      = document.getElementById('imp-skip-duplicates')?.checked ? 1 : 0;

  if (!email || !pass || !host) {
    showToast('Lütfen e-posta adresi, şifre ve sunucu bilgilerini girin.', 'error');
    return;
  }

  const proceedWithImport = (targetPassword = '') => {
    const btnStart = document.getElementById('btn-start-import');
    const oldHtml = btnStart ? btnStart.innerHTML : '';
    if (btnStart) {
      btnStart.disabled = true;
      btnStart.innerHTML = '<div style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;margin-right:5px;vertical-align:middle;"></div> Başlatılıyor...';
    }

    const fd = new FormData();
    fd.append('action', 'start_email_import');
    fd.append('source_email', email);
    fd.append('source_password', pass);
    fd.append('source_host', host);
    fd.append('source_port', port);
    fd.append('source_encryption', enc);
    fd.append('source_folder', sourceFolder);
    fd.append('target_mode', targetMode);
    fd.append('custom_target_folder', customTarget);
    fd.append('date_filter', dateFilter);
    fd.append('limit_count', limitCount);
    fd.append('skip_duplicates', skipDup);
    if (targetPassword) {
      fd.append('target_password', targetPassword);
    }

    safeFetchJson('api.php', { method: 'POST', body: fd })
      .then(res => {
        if (btnStart) btnStart.innerHTML = oldHtml;
        if (res.success) {
          showToast(res.message || 'İçe aktarma arka planda başlatıldı! Sayfayı kapatabilirsiniz.', 'success');
          checkActiveImportStatus(true);
          loadImportHistory();
        } else if (res.need_target_password && typeof Swal !== 'undefined') {
          if (btnStart) btnStart.disabled = false;
          Swal.fire({
            title: 'Hedef Hesap Şifresi Gerekli',
            html: '<p style="font-size:0.9rem;text-align:left;">Bu posta kutusunun yerel şifresi sistemde kayıtlı değil. E-postaların bu hesaba aktarılabilmesi için lütfen <strong>bu hesabın şifresini</strong> girin:</p>',
            input: 'password',
            inputPlaceholder: 'Hesabınızın şifresi...',
            showCancelButton: true,
            confirmButtonText: 'Şifreyi Kaydet ve Aktarımı Başlat',
            cancelButtonText: 'İptal'
          }).then(pRes => {
            if (pRes.isConfirmed && pRes.value) {
              proceedWithImport(pRes.value);
            }
          });
        } else {
          if (btnStart) btnStart.disabled = false;
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'error',
              title: 'Aktarım Başlatılamadı',
              text: res.message || 'Bilinmeyen bir hata oluştu.',
              confirmButtonText: 'Tamam'
            });
          } else {
            showToast(res.message || 'Aktarım başlatılamadı.', 'error');
          }
        }
      })
      .catch(err => {
        if (btnStart) {
          btnStart.disabled = false;
          btnStart.innerHTML = oldHtml;
        }
        showToast('Hata: ' + err.message, 'error');
      });
  };

  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Arka Planda İçe Aktarma Başlatılsın mı?',
      html: `<div style="text-align:left;font-size:0.875rem;line-height:1.6;">
        <p><strong>${escapeHtml(email)}</strong> adresinden e-postalar bu hesaba aktarılacaktır.</p>
        <div style="background:rgba(79,70,229,0.08);padding:0.6rem;margin-top:0.6rem;border-left:3px solid var(--primary);font-size:0.8rem;">
          🛡️ <strong>Arka Plan İşi:</strong> İşlem başlatıldıktan sonra tarayıcınızı veya bu sayfayı güvenle kapatabilirsiniz. Sunucu arka planda aktarımı kesintisiz sürdürmeye devam edecektir.
        </div>
      </div>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Evet, Arka Planda Başlat',
      cancelButtonText: 'İptal',
      confirmButtonColor: '#4f46e5'
    }).then(res => {
      if (res.isConfirmed) {
        proceedWithImport();
      }
    });
  } else {
    if (confirm('İçe aktarma işlemi arka planda başlatılsın mı? Sayfayı kapatsanız bile sunucuda devam edecektir.')) {
      proceedWithImport();
    }
  }
}

function loadImportHistory() {
  const tbody = document.getElementById('import-history-table-body');
  if (!tbody) return;

  safeFetchJson('api.php?action=get_import_history')
    .then(res => {
      if (res.success && Array.isArray(res.history)) {
        if (res.history.length === 0) {
          tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:1.5rem;color:var(--text-muted);">Henüz bir aktarım yapılmadı.</td></tr>';
          return;
        }

        tbody.innerHTML = res.history.map(row => {
          let statusBadge = '';
          if (row.status === 'completed') {
            statusBadge = '<span class="badge" style="background:#10b981;color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;">Tamamlandı</span>';
          } else if (row.status === 'completed_with_errors') {
            statusBadge = '<span class="badge" style="background:#f59e0b;color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;" title="' + escapeHtml(row.error_message || '') + '">Kısmen Tamamlandı</span>';
          } else if (row.status === 'failed') {
            statusBadge = '<span class="badge" style="background:var(--danger);color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;" title="' + escapeHtml(row.error_message || '') + '">Başarısız</span>';
          } else {
            statusBadge = '<span class="badge" style="background:#6366f1;color:#fff;border-radius:0 !important;font-size:0.75rem;padding:3px 7px;">' + escapeHtml(row.status || 'İşleniyor') + '</span>';
          }

          const dateStr = row.created_at ? new Date(row.created_at.replace(' ', 'T')).toLocaleString('tr-TR') : '-';
          const countsInfo = `<strong>${row.imported_count || 0}</strong>` + (row.skipped_count > 0 ? ` <span style="color:var(--text-muted);font-size:0.75rem;" title="Mükerrer Atlanan">(+${row.skipped_count})</span>` : '');

          return `
            <tr>
              <td style="font-size:0.8rem;color:var(--text-secondary);">${dateStr}</td>
              <td style="font-weight:600;font-size:0.85rem;">${escapeHtml(row.source_email || '-')}</td>
              <td style="font-size:0.8rem;color:var(--text-muted);">${escapeHtml(row.source_host || '-')}</td>
              <td style="font-size:0.8rem;"><span class="badge" style="background:var(--bg-hover);color:var(--text-primary);border:1px solid var(--border-color);border-radius:0 !important;">${escapeHtml(row.source_folder || 'ALL')}</span></td>
              <td style="font-size:0.8rem;">${escapeHtml(row.target_folder || 'Aynı')}</td>
              <td style="text-align:center;font-size:0.85rem;">${countsInfo}</td>
              <td style="text-align:center;">${statusBadge}</td>
            </tr>
          `;
        }).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:1.5rem;color:var(--danger);">Geçmiş yüklenemedi.</td></tr>';
      }
    })
    .catch(err => {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:1.5rem;color:var(--danger);">Hata: ${err.message}</td></tr>`;
    });
}

// ==========================================
// ADMIN MANAGEMENT PANEL CONTROLLERS
// ==========================================
let adminAccountsCache = [];
let currentAdminRoleFilter = 'all';

function loadAdminDashboard() {
  const tbody = document.getElementById('admin-accounts-tbody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-muted);"><i class="fas fa-spinner fa-spin me-2"></i>Hesaplar ve sunucu verileri yükleniyor...</td></tr>';
  }

  // 1. Load Stats & Hestia Status
  fetch('api.php?action=admin_get_stats')
    .then(async r => {
      try {
        const text = await r.text();
        return JSON.parse(text);
      } catch (e) {
        return { success: false };
      }
    })
    .then(res => {
      if (res && res.success && res.stats) {
        const s = res.stats;
        const totalEl = document.getElementById('admin-stat-total-accounts');
        if (totalEl) totalEl.textContent = s.total_accounts;
        const adminEl = document.getElementById('admin-stat-total-admins');
        if (adminEl) adminEl.textContent = s.total_admins;
        
        // Total disk usage from Hestia
        const diskEl = document.getElementById('admin-stat-total-disk');
        if (diskEl) {
          if (s.hestia_connected) {
            const diskMb = parseFloat(s.hestia_total_disk_mb) || 0;
            diskEl.textContent = diskMb >= 1024 ? (diskMb / 1024).toFixed(2) + ' GB' : diskMb.toFixed(1) + ' MB';
            diskEl.style.fontSize = '1.2rem';
            diskEl.style.color = 'var(--success)';
            diskEl.title = `${s.hestia_account_count} HestiaCP posta kutusunun toplam disk alanı`;
          } else {
            diskEl.textContent = s.hestia_active ? 'Bağlanıyor...' : 'Yapılandırılmadı';
            diskEl.style.fontSize = '0.95rem';
            diskEl.style.color = 'var(--text-secondary)';
          }
        }

        // Server Status Card
        const serverEl = document.getElementById('admin-stat-server-status');
        if (serverEl) {
          const isLocal = (s.server_mode === 'local');
          if (s.hestia_connected) {
            const modeBadge = isLocal ? '⚡ Yerel CLI' : '✓ API Bağlı';
            serverEl.innerHTML = `<span class="admin-badge" style="background:var(--success-light);color:var(--success);border:1px solid rgba(16,185,129,0.3);font-size:0.775rem;">${modeBadge} (${s.hestia_account_count} Sunucu Hesabı)</span>`;
          } else if (isLocal) {
            serverEl.innerHTML = `<span class="admin-badge" style="background:rgba(59,130,246,0.15);color:var(--primary);border:1px solid rgba(59,130,246,0.3);font-size:0.775rem;">⚡ Yerel Mod</span>`;
          } else if (s.hestia_active) {
            serverEl.innerHTML = '<span class="admin-badge" style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);font-size:0.775rem;">⚠️ Bağlantı Bekliyor</span>';
          } else {
            serverEl.innerHTML = '<span class="admin-badge" style="background:var(--bg-hover);color:var(--text-secondary);border:1px solid var(--border-color);font-size:0.775rem;">API Tanımsız</span>';
          }
        }

        // Alert Banner
        const alertEl = document.getElementById('admin-hestia-alert');
        if (alertEl) {
          if (s.server_mode === 'local') {
            if (s.hestia_connected) {
              alertEl.style.display = 'none';
            } else if (!s.is_local_installed) {
              alertEl.style.display = 'flex';
              const icon = document.getElementById('admin-hestia-alert-icon');
              if (icon) icon.textContent = 'ℹ️';
              const title = document.getElementById('admin-hestia-alert-title');
              if (title) title.textContent = 'Aynı Sunucu (Yerel CLI) Modu Aktif';
              const desc = document.getElementById('admin-hestia-alert-desc');
              if (desc) desc.textContent = 'Webmail yerel CLI modunda yapılandırıldı. Projenizi Linux HestiaCP sunucunuza aktardığınızda /usr/local/hestia/bin komutları otomatik çalışacaktır.';
            } else {
              alertEl.style.display = 'none';
            }
          } else {
            if (!s.hestia_active) {
              alertEl.style.display = 'flex';
              const icon = document.getElementById('admin-hestia-alert-icon');
              if (icon) icon.textContent = '⚙️';
              const title = document.getElementById('admin-hestia-alert-title');
              if (title) title.textContent = 'HestiaCP REST API Yapılandırması Gerekli';
              const desc = document.getElementById('admin-hestia-alert-desc');
              if (desc) desc.textContent = 'HestiaCP üzerindeki tüm kurumsal mail hesaplarını, disk boyutlarını ve kotaları canlı çekmek için API anahtarınızı tanımlayın.';
            } else if (s.hestia_active && !s.hestia_connected) {
              alertEl.style.display = 'flex';
              const icon = document.getElementById('admin-hestia-alert-icon');
              if (icon) icon.textContent = '⚠️';
              const title = document.getElementById('admin-hestia-alert-title');
              if (title) title.textContent = 'HestiaCP Sunucusuna Erişilemedi';
              const desc = document.getElementById('admin-hestia-alert-desc');
              if (desc) desc.textContent = 'HestiaCP sunucu adresi veya API anahtarı geçersiz olabilir. Lütfen port 8083 ve Access Key yetkilerini test ediniz.';
            } else {
              alertEl.style.display = 'none';
            }
          }
        }
      }
    })
    .catch(err => console.error('Admin stats fetch error:', err));

  // 2. Load Accounts List (fast local query with cached Hestia CP metadata)
  fetch('api.php?action=admin_list_accounts')
    .then(async r => {
      const text = await r.text();
      let res;
      try {
        res = JSON.parse(text);
      } catch (e) {
        throw new Error(text && text.length < 250 ? text : 'Sunucudan geçersiz veri döndü (' + e.message + ')');
      }
      return res;
    })
    .then(res => {
      if (res && res.success && Array.isArray(res.accounts)) {
        adminAccountsCache = res.accounts;
        updateAdminFilterBadges();
        applyAdminFilters();
      } else {
        if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--danger);"><i class="fas fa-exclamation-triangle me-2"></i>${escapeHtml((res && res.message) || 'Hesaplar listelenemedi.')}</td></tr>`;
      }
    })
    .catch(err => {
      if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--danger);"><i class="fas fa-exclamation-triangle me-2"></i>Bağlantı hatası: ${escapeHtml(err.message)}</td></tr>`;
    });
}

function updateAdminFilterBadges() {
  const total = adminAccountsCache.length;
  const admins = adminAccountsCache.filter(a => parseInt(a.is_admin) === 1).length;
  const users = total - admins;

  const countAll = document.getElementById('admin-count-all');
  if (countAll) countAll.textContent = total;
  const countAdmins = document.getElementById('admin-count-admins');
  if (countAdmins) countAdmins.textContent = admins;
  const countUsers = document.getElementById('admin-count-users');
  if (countUsers) countUsers.textContent = users;
}

function filterAdminRole(role, btn) {
  currentAdminRoleFilter = role;
  document.querySelectorAll('.admin-filter-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  applyAdminFilters();
}

function handleAdminSearchInput() {
  applyAdminFilters();
}

function applyAdminFilters() {
  const searchInput = document.getElementById('admin-search-input');
  const query = (searchInput ? searchInput.value : '').toLowerCase().trim();

  let filtered = adminAccountsCache.filter(acc => {
    // Role filter
    if (currentAdminRoleFilter === 'admin' && parseInt(acc.is_admin) !== 1) return false;
    if (currentAdminRoleFilter === 'user' && parseInt(acc.is_admin) === 1) return false;

    // Search query
    if (query !== '') {
      const name = (acc.name || '').toLowerCase();
      const email = (acc.email || '').toLowerCase();
      if (!name.includes(query) && !email.includes(query)) return false;
    }
    return true;
  });

  renderAdminAccountsTable(filtered);
}

function renderAdminAccountsTable(accounts) {
  const tbody = document.getElementById('admin-accounts-tbody');
  if (!tbody) return;

  if (accounts.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-muted);">Eşleşen e-posta hesabı bulunamadı.</td></tr>';
    return;
  }

  tbody.innerHTML = accounts.map(acc => {
    const isCurrent = !!acc.is_current;
    const isAdmin = parseInt(acc.is_admin) === 1;
    const isSuspended = !!acc.is_suspended;
    const initial = escapeHtml((acc.name || acc.email || 'U').charAt(0).toUpperCase());

    // Role badge
    const roleBadge = isAdmin
      ? '<span class="admin-badge admin-badge-gold">👑 Yönetici (Admin)</span>'
      : '<span class="admin-badge admin-badge-user">Standart Kullanıcı</span>';

    // Current user badge
    const currentBadge = isCurrent
      ? '<span class="admin-badge admin-badge-current" style="margin-left:6px;">Siz (Aktif)</span>'
      : '';

    // Extra aliases / forward badge
    let extraMeta = '';
    if (acc.aliases) {
      const aliasList = acc.aliases.split(',').map(s => s.trim()).filter(Boolean);
      if (aliasList.length > 0) {
        extraMeta += `<span class="admin-badge" style="background:var(--bg-hover);color:var(--text-secondary);font-size:0.68rem;padding:0.1rem 0.4rem;border:1px solid var(--border-color);margin-top:3px;display:inline-block;" title="Rumuzlar (Alias): ${escapeHtml(acc.aliases)}">+${aliasList.length} alias</span>`;
      }
    }
    if (acc.forward) {
      extraMeta += `<span class="admin-badge" style="background:var(--bg-hover);color:var(--text-secondary);font-size:0.68rem;padding:0.1rem 0.4rem;border:1px solid var(--border-color);margin-top:3px;margin-left:4px;display:inline-block;" title="Yönlendirme (Forward): ${escapeHtml(acc.forward)}">↪ ${escapeHtml(acc.forward)}</span>`;
    }

    // Disk & Quota Column (Hestia live data)
    let quotaHtml = '';
    if (acc.hestia_synced) {
      const diskUsed = parseFloat(acc.disk_used_mb) || 0;
      const quota = parseFloat(acc.quota_mb) || 0;

      if (quota > 0) {
        const percent = Math.min(100, Math.round((diskUsed / quota) * 100));
        const barColor = percent > 85 ? 'var(--danger, #ef4444)' : (percent > 65 ? '#f59e0b' : 'var(--primary, #6366f1)');
        quotaHtml = `
          <div style="font-size:0.775rem;font-weight:600;color:var(--text-primary);">
            ${diskUsed} MB <span style="font-weight:400;color:var(--text-secondary);">/ ${quota} MB</span>
            <span style="font-size:0.7rem;color:var(--text-muted);margin-left:4px;">(${percent}%)</span>
          </div>
          <div style="height:5px;width:100%;max-width:140px;background:var(--bg-hover);border:1px solid var(--border-color);margin-top:4px;overflow:hidden;">
            <div style="height:100%;width:${percent}%;background:${barColor};transition:width .3s;"></div>
          </div>
        `;
      } else {
        quotaHtml = `
          <div style="font-size:0.775rem;font-weight:600;color:var(--text-primary);">
            ${diskUsed} MB <span style="font-weight:400;color:var(--text-secondary);">/ ∞ (Sınırsız)</span>
          </div>
          <div style="height:5px;width:100%;max-width:140px;background:var(--bg-hover);border:1px solid var(--border-color);margin-top:4px;overflow:hidden;">
            <div style="height:100%;width:15%;background:var(--success, #10b981);"></div>
          </div>
        `;
      }
    } else {
      quotaHtml = `
        <div style="font-size:0.75rem;color:var(--text-muted);">
          Yerel Veritabanı
        </div>
      `;
    }

    // Server Status Column
    let statusHtml = '';
    if (isSuspended) {
      statusHtml = `
        <span class="admin-badge" style="background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);border:1px solid rgba(239,68,68,0.3);font-size:0.75rem;font-weight:600;padding:0.2rem 0.55rem;">
          <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--danger, #ef4444);margin-right:4px;"></span> Askıda
        </span>
      `;
    } else if (acc.hestia_synced) {
      statusHtml = `
        <span class="admin-badge" style="background:var(--success-light);color:var(--success);border:1px solid rgba(16,185,129,0.3);font-size:0.75rem;font-weight:600;padding:0.2rem 0.55rem;">
          <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--success);margin-right:4px;"></span> Aktif
        </span>
      `;
    } else {
      statusHtml = `
        <span class="admin-badge" style="background:var(--bg-hover);color:var(--text-secondary);border:1px solid var(--border-color);font-size:0.75rem;padding:0.2rem 0.55rem;">
          Yerel DB
        </span>
      `;
    }

    // Action buttons
    let actionButtons = '';
    const editBtn = `
      <button type="button" class="admin-table-action-btn" title="Hesap ve HestiaCP ayarlarını düzenle" onclick="openAdminAccountEditModal(${acc.id})" style="color:var(--primary);border-color:rgba(99,102,241,0.35);">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Ayarlar
      </button>
    `;

    if (isCurrent) {
      actionButtons = `
        <div style="display:inline-flex;gap:0.35rem;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
          ${editBtn}
          <button type="button" class="admin-table-action-btn" onclick="openAdminPasswordModal(${acc.id})">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-2-2l2 2m7 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            Şifre Değiştir
          </button>
        </div>
      `;
    } else {
      const toggleBtn = isAdmin
        ? `<button type="button" class="admin-table-action-btn warning" title="Yönetici yetkisini kaldır" onclick="toggleAdminRole(${acc.id}, 0)">
             <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
             Yetkiyi Kaldır
           </button>`
        : `<button type="button" class="admin-table-action-btn" style="color:#f59e0b;border-color:rgba(245,158,11,0.4);" title="Yönetici (Admin) yetkisi ata" onclick="toggleAdminRole(${acc.id}, 1)">
             👑 Yönetici Yap
           </button>`;

      const passBtn = `
        <button type="button" class="admin-table-action-btn" title="Şifreyi sıfırla / belirle" onclick="openAdminPasswordModal(${acc.id})">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Şifre
        </button>
      `;

      const switchBtn = `
        <button type="button" class="admin-table-action-btn" title="Bu kullanıcının gelen kutusuna geçiş yap" onclick="adminSwitchAccount(${acc.id})">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
          Geç
        </button>
      `;

      const deleteBtn = `
        <button type="button" class="admin-table-action-btn danger" title="Hesabı tamamen sil" onclick="deleteAccountAdmin(${acc.id})">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      `;

      actionButtons = `
        <div style="display:inline-flex;gap:0.35rem;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
          ${editBtn}
          ${toggleBtn}
          ${passBtn}
          ${switchBtn}
          ${deleteBtn}
        </div>
      `;
    }

    const createdFormatted = acc.created_at && acc.created_at !== '-'
      ? new Date(acc.created_at.replace(' ', 'T')).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
      : '-';

    return `
      <tr id="admin-row-${acc.id}">
        <td>
          <div style="display:flex;align-items:center;gap:0.75rem;">
            <div style="width:34px;height:34px;background:var(--bg-hover);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;color:var(--text-primary);flex-shrink:0;">
              ${initial}
            </div>
            <div style="min-width:0;">
              <div style="font-weight:600;color:var(--text-primary);display:flex;align-items:center;">
                <span class="text-truncate" style="max-width:180px;">${escapeHtml(acc.name || acc.email)}</span>
                ${currentBadge}
              </div>
              <div style="font-size:0.775rem;color:var(--text-secondary);">${escapeHtml(acc.email)}</div>
              <div>${extraMeta}</div>
            </div>
          </div>
        </td>
        <td style="text-align:center;">${roleBadge}</td>
        <td>${quotaHtml}</td>
        <td style="text-align:center;">${statusHtml}</td>
        <td style="font-size:0.8rem;color:var(--text-secondary);">${createdFormatted}</td>
        <td style="text-align:right;">${actionButtons}</td>
      </tr>
    `;
  }).join('');
}

function toggleAdminRole(id, makeAdmin) {
  const acc = adminAccountsCache.find(a => parseInt(a.id) === parseInt(id));
  const email = acc ? acc.email : 'Seçilen hesap';

  const actionText = makeAdmin ? 'Yönetici (Admin) Yetkisi Ver' : 'Yönetici Yetkisini Kaldır';
  const confirmMsg = makeAdmin
    ? `<strong>${escapeHtml(email)}</strong> kullanıcısına Yönetici (Admin) yetkisi vermek istediğinize emin misiniz?<br><small style="color:var(--text-secondary);">Bu kullanıcı tüm e-posta hesaplarını görüntüleyebilir ve yeni hesaplar ekleyebilir.</small>`
    : `<strong>${escapeHtml(email)}</strong> kullanıcısının Yönetici yetkisini kaldırmak istediğinize emin misiniz?<br><small style="color:var(--text-secondary);">Kullanıcı standart kullanıcı rolüne geri dönecektir.</small>`;

  Swal.fire({
    title: actionText,
    html: confirmMsg,
    icon: makeAdmin ? 'question' : 'warning',
    showCancelButton: true,
    confirmButtonColor: makeAdmin ? 'var(--primary, #6366f1)' : '#f59e0b',
    cancelButtonColor: '#64748b',
    confirmButtonText: makeAdmin ? 'Evet, Yönetici Yap' : 'Evet, Yetkiyi Kaldır',
    cancelButtonText: 'Vazgeç'
  }).then(result => {
    if (result.isConfirmed) {
      const formData = new FormData();
      formData.append('id', id);
      formData.append('is_admin', makeAdmin ? '1' : '0');

      fetch('api.php?action=admin_toggle_role', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          Swal.fire({
            icon: 'success',
            title: 'Başarılı',
            text: res.message,
            timer: 2000,
            showConfirmButton: false
          });
          loadAdminDashboard();
        } else {
          Swal.fire({
            icon: 'error',
            title: 'İşlem Başarısız',
            text: res.message || 'Yetki güncellenemedi.'
          });
        }
      })
      .catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Hata',
          text: 'Sunucu bağlantı hatası: ' + err.message
        });
      });
    }
  });
}

function openAdminCreateModal() {
  const form = document.getElementById('admin-create-account-form');
  if (form) form.reset();
  const modal = document.getElementById('admin-create-modal');
  if (modal) modal.style.display = 'flex';
  setTimeout(() => {
    const input = document.getElementById('admin-new-name');
    if (input) input.focus();
  }, 100);
}

function closeAdminCreateModal() {
  const modal = document.getElementById('admin-create-modal');
  if (modal) modal.style.display = 'none';
}

function generateAdminPass(targetInputId) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
  let pass = '';
  for (let i = 0; i < 10; i++) {
    pass += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  const el = document.getElementById(targetInputId);
  if (el) {
    el.value = pass;
    el.type = 'text';
    el.focus();
    showToast('Güvenli şifre oluşturuldu: ' + pass);
  }
}

function togglePassVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  if (input.type === 'password') {
    input.type = 'text';
    if (btn) btn.style.color = 'var(--primary)';
  } else {
    input.type = 'password';
    if (btn) btn.style.color = 'var(--text-muted)';
  }
}

function submitAdminCreateAccount(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-create-account');
  const name = document.getElementById('admin-new-name').value.trim();
  const user = document.getElementById('admin-new-user').value.trim();
  const pass = document.getElementById('admin-new-pass').value;
  const isAdmin = document.getElementById('admin-new-is-admin').checked ? '1' : '0';

  if (!user) {
    showToast('Lütfen e-posta adresini giriniz.', 'error');
    return;
  }
  if (!pass || pass.length < 6) {
    showToast('Şifre en az 6 karakter olmalıdır.', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Oluşturuluyor...';
  }

  const quota = document.getElementById('admin-new-quota') ? document.getElementById('admin-new-quota').value.trim() : '';
  const aliases = document.getElementById('admin-new-aliases') ? document.getElementById('admin-new-aliases').value.trim() : '';
  const forward = document.getElementById('admin-new-forward') ? document.getElementById('admin-new-forward').value.trim() : '';
  const forwardOnly = (document.getElementById('admin-new-forward-only') && document.getElementById('admin-new-forward-only').checked) ? '1' : '0';
  const rateLimit = document.getElementById('admin-new-rate') ? document.getElementById('admin-new-rate').value.trim() : '';

  const formData = new FormData();
  formData.append('name', name);
  formData.append('email_user', user);
  formData.append('password', pass);
  formData.append('is_admin', isAdmin);
  formData.append('quota', quota);
  formData.append('aliases', aliases);
  formData.append('forward', forward);
  formData.append('forward_only', forwardOnly);
  formData.append('rate_limit', rateLimit);

  fetch('api.php?action=admin_create_account', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Hesabı Oluştur';
    }
    if (res.success) {
      closeAdminCreateModal();
      Swal.fire({
        icon: 'success',
        title: 'Hesap Oluşturuldu',
        text: res.message,
        confirmButtonText: 'Tamam'
      });
      loadAdminDashboard();
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Oluşturulamadı',
        text: res.message || 'Hesap oluşturulurken hata meydana geldi.'
      });
    }
  })
  .catch(err => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Hesabı Oluştur';
    }
    Swal.fire({
      icon: 'error',
      title: 'Hata',
      text: 'Sunucu bağlantı hatası: ' + err.message
    });
  });
}

function openAdminPasswordModal(id) {
  const acc = adminAccountsCache.find(a => parseInt(a.id) === parseInt(id));
  const email = acc ? acc.email : '';

  document.getElementById('admin-pass-target-id').value = id;
  document.getElementById('admin-pass-target-email').textContent = email;
  document.getElementById('admin-reset-pass').value = '';
  document.getElementById('admin-reset-pass').type = 'password';
  const modal = document.getElementById('admin-pass-modal');
  if (modal) modal.style.display = 'flex';
  setTimeout(() => {
    const input = document.getElementById('admin-reset-pass');
    if (input) input.focus();
  }, 100);
}

function closeAdminPassModal() {
  const modal = document.getElementById('admin-pass-modal');
  if (modal) modal.style.display = 'none';
}

function submitAdminPasswordChange(e) {
  e.preventDefault();
  const id = document.getElementById('admin-pass-target-id').value;
  const pass = document.getElementById('admin-reset-pass').value;
  const btn = document.getElementById('btn-submit-change-pass');

  if (!pass || pass.length < 6) {
    showToast('Şifre en az 6 karakter olmalıdır.', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Güncelleniyor...';
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('password', pass);

  fetch('api.php?action=admin_change_password', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Şifreyi Güncelle';
    }
    if (res.success) {
      closeAdminPassModal();
      Swal.fire({
        icon: 'success',
        title: 'Şifre Güncellendi',
        text: res.message,
        timer: 2000,
        showConfirmButton: false
      });
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Hata',
        text: res.message || 'Şifre güncellenemedi.'
      });
    }
  })
  .catch(err => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Şifreyi Güncelle';
    }
    Swal.fire({
      icon: 'error',
      title: 'Hata',
      text: 'Bağlantı hatası: ' + err.message
    });
  });
}

// ----------------------------------------------------
// ADMIN: ACCOUNT & HESTIACP FEATURES CONTROLLERS
// ----------------------------------------------------
function openAdminAccountEditModal(id) {
  Swal.fire({
    title: 'HestiaCP Verileri Alınıyor...',
    text: 'Hesap parametreleri sunucudan çekiliyor, lütfen bekleyiniz.',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  fetch(`api.php?action=admin_get_account_details&id=${id}`)
    .then(r => r.json())
    .then(res => {
      Swal.close();
      if (!res.success || !res.details) {
        Swal.fire('Hata', res.message || 'Hesap detayları yüklenemedi.', 'error');
        return;
      }
      const d = res.details;
      document.getElementById('admin-edit-id').value = d.id;
      document.getElementById('admin-edit-email').value = d.email;
      document.getElementById('admin-edit-name').value = d.name || '';
      document.getElementById('admin-edit-password').value = '';
      document.getElementById('admin-edit-password').type = 'password';
      document.getElementById('admin-edit-send-email').value = '';

      // Quota
      const quotaInput = document.getElementById('admin-edit-quota');
      if (d.quota === 0 || !d.quota) {
        quotaInput.value = '0 (Sınırsız)';
      } else {
        quotaInput.value = d.quota;
      }

      // Aliases
      document.getElementById('admin-edit-aliases').value = d.aliases || '';

      // Blackhole & Forwarding
      const bhChk = document.getElementById('admin-edit-blackhole');
      bhChk.checked = !!d.blackhole;
      toggleEditBlackhole(!!d.blackhole);

      document.getElementById('admin-edit-forward-only').checked = !!d.forward_only;
      document.getElementById('admin-edit-forward').value = d.forward || '';

      // Autoreply
      const autoChk = document.getElementById('admin-edit-autoreply');
      autoChk.checked = !!d.autoreply;
      toggleEditAutoreply(!!d.autoreply);
      document.getElementById('admin-edit-autoreply-msg').value = d.autoreply_message || '';

      // Rate limit
      document.getElementById('admin-edit-rate').value = d.rate_limit || '';

      // Subtitle status badge
      const sub = document.getElementById('admin-edit-subtitle');
      if (sub) {
        if (d.hestia_synced) {
          sub.innerHTML = `✓ HestiaCP ile bağlı (${d.disk_used_mb} MB disk kullanılıyor)`;
          sub.style.color = 'var(--success)';
        } else {
          sub.innerHTML = `⚠️ Yerel veritabanı kaydı (Hestia bağlantısı bekleniyor)`;
          sub.style.color = '#f59e0b';
        }
      }

      const modal = document.getElementById('admin-account-edit-modal');
      if (modal) modal.style.display = 'flex';
    })
    .catch(err => {
      Swal.close();
      Swal.fire('Hata', 'Sunucuya bağlanılamadı: ' + err.message, 'error');
    });
}

function closeAdminAccountEditModal() {
  const modal = document.getElementById('admin-account-edit-modal');
  if (modal) modal.style.display = 'none';
}

function setEditQuotaUnlimited() {
  const input = document.getElementById('admin-edit-quota');
  if (input) input.value = '0 (Sınırsız)';
}

function toggleEditBlackhole(checked) {
  const fwdArea = document.getElementById('admin-edit-forward');
  if (fwdArea) {
    fwdArea.disabled = checked;
    fwdArea.style.opacity = checked ? '0.45' : '1';
  }
}

function toggleEditAutoreply(checked) {
  const box = document.getElementById('admin-edit-autoreply-box');
  if (box) box.style.display = checked ? 'block' : 'none';
}

function generateHestiaValidPass(targetInputId) {
  // Parola kuralları: en az 8 karakter, 1 büyük harf, 1 küçük harf, 1 rakam
  const uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const lowers = 'abcdefghjkmnpqrstuvwxyz';
  const digits = '23456789';
  const specials = '!@#$%&*';

  let pass = '';
  pass += uppers.charAt(Math.floor(Math.random() * uppers.length));
  pass += lowers.charAt(Math.floor(Math.random() * lowers.length));
  pass += digits.charAt(Math.floor(Math.random() * digits.length));
  pass += specials.charAt(Math.floor(Math.random() * specials.length));

  const all = uppers + lowers + digits + specials;
  for (let i = 0; i < 6; i++) {
    pass += all.charAt(Math.floor(Math.random() * all.length));
  }
  pass = pass.split('').sort(() => 0.5 - Math.random()).join('');

  const el = document.getElementById(targetInputId);
  if (el) {
    el.value = pass;
    el.type = 'text';
    el.focus();
    showToast('Hestia kurallarına uygun parola üretildi: ' + pass);
  }
}

function submitAdminAccountEdit(e) {
  e.preventDefault();
  const id = document.getElementById('admin-edit-id').value;
  const name = document.getElementById('admin-edit-name').value.trim();
  const password = document.getElementById('admin-edit-password').value;
  const sendEmail = document.getElementById('admin-edit-send-email').value.trim();
  const quotaRaw = document.getElementById('admin-edit-quota').value.trim();
  const aliases = document.getElementById('admin-edit-aliases').value.trim();
  const blackhole = document.getElementById('admin-edit-blackhole').checked ? '1' : '0';
  const forwardOnly = document.getElementById('admin-edit-forward-only').checked ? '1' : '0';
  const forward = document.getElementById('admin-edit-forward').value.trim();
  const autoreply = document.getElementById('admin-edit-autoreply').checked ? '1' : '0';
  const autoreplyMsg = document.getElementById('admin-edit-autoreply-msg').value.trim();
  const rateLimit = document.getElementById('admin-edit-rate').value.trim();
  const btn = document.getElementById('btn-submit-edit-account');

  // Password validation if entered
  if (password && (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password))) {
    Swal.fire('Geçersiz Parola', 'Parolanız en az 8 karakter uzunluğunda olmalı, en az 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.', 'warning');
    return;
  }

  let quotaClean = quotaRaw.replace(/[^0-9]/g, '');
  if (!quotaClean || quotaRaw.toLowerCase().includes('sınırsız')) {
    quotaClean = '0';
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Kaydediliyor...';
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('name', name);
  formData.append('password', password);
  formData.append('send_credentials_email', sendEmail);
  formData.append('quota', quotaClean);
  formData.append('aliases', aliases);
  formData.append('blackhole', blackhole);
  formData.append('forward_only', forwardOnly);
  formData.append('forward', forward);
  formData.append('autoreply', autoreply);
  formData.append('autoreply_message', autoreplyMsg);
  formData.append('rate_limit', rateLimit);

  fetch('api.php?action=admin_update_account_details', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<span>💾 Değişiklikleri Kaydet</span>';
    }
    if (res.success) {
      closeAdminAccountEditModal();
      Swal.fire({
        icon: 'success',
        title: 'Başarılı',
        text: res.message,
        confirmButtonText: 'Tamam'
      });
      loadAdminDashboard();
    } else {
      Swal.fire('Kaydedilemedi', res.message || 'İşlem tamamlanamadı.', 'error');
    }
  })
  .catch(err => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<span>💾 Değişiklikleri Kaydet</span>';
    }
    Swal.fire('Hata', 'Sunucu bağlantı hatası: ' + err.message, 'error');
  });
}

function deleteAccountAdmin(id) {
  const acc = adminAccountsCache.find(a => parseInt(a.id) === parseInt(id));
  const email = acc ? acc.email : 'Seçilen hesap';

  Swal.fire({
    title: 'Hesabı Sil',
    html: `<strong>${escapeHtml(email)}</strong> hesabı ve yerel e-postaları kalıcı olarak silinecektir.<br><br>
           <label style="font-size:0.85rem;display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer;">
             <input type="checkbox" id="swal-delete-from-server"> HestiaCP posta sunucusundan da tamamen kaldır
           </label>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: 'var(--danger, #ef4444)',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Evet, Hesabı Sil',
    cancelButtonText: 'Vazgeç'
  }).then(result => {
    if (result.isConfirmed) {
      const deleteFromServer = document.getElementById('swal-delete-from-server')?.checked ? '1' : '0';
      const formData = new FormData();
      formData.append('id', id);
      formData.append('delete_from_server', deleteFromServer);

      fetch('api.php?action=admin_delete_account', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          Swal.fire({
            icon: 'success',
            title: 'Hesap Silindi',
            text: res.message,
            timer: 2000,
            showConfirmButton: false
          });
          loadAdminDashboard();
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Silinemedi',
            text: res.message || 'Hesap silinirken hata oluştu.'
          });
        }
      })
      .catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Hata',
          text: 'Bağlantı hatası: ' + err.message
        });
      });
    }
  });
}

function adminSwitchAccount(id) {
  const acc = adminAccountsCache.find(a => parseInt(a.id) === parseInt(id));
  const email = acc ? acc.email : 'Seçilen hesap';

  Swal.fire({
    title: 'Hesaba Geçiş Yap',
    text: `${email} hesabının oturumuna geçiş yapmak istiyor musunuz?`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: 'var(--primary, #6366f1)',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Evet, Hesaba Geç',
    cancelButtonText: 'Vazgeç'
  }).then(result => {
    if (result.isConfirmed) {
      const formData = new FormData();
      formData.append('id', id);

      fetch('api.php?action=admin_switch_account', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showToast(res.message);
          setTimeout(() => window.location.reload(), 500);
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Geçiş Yapılamadı',
            text: res.message || 'Hesaba geçiş başarısız.'
          });
        }
      })
      .catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Hata',
          text: 'Bağlantı hatası: ' + err.message
        });
      });
    }
  });
}

// ==========================================
// ADMIN: BRANDING / APPLICATION TITLE SETTINGS
// ==========================================
function openBrandingModal() {
  const modal = document.getElementById('admin-branding-modal');
  const feedback = document.getElementById('branding-feedback');
  if (feedback) feedback.style.display = 'none';

  // Fetch current branding values
  fetch('api.php?action=admin_get_branding')
    .then(r => r.json())
    .then(res => {
      if (res && res.success) {
        const titleInp = document.getElementById('admin-input-app-title');
        const subInp = document.getElementById('admin-input-app-subtitle');
        if (titleInp && res.app_title) titleInp.value = res.app_title;
        if (subInp && res.app_subtitle) subInp.value = res.app_subtitle;
        updateBrandingPreview();
      }
      if (modal) modal.style.display = 'flex';
    })
    .catch(() => {
      if (modal) modal.style.display = 'flex';
    });
}

function closeBrandingModal() {
  const modal = document.getElementById('admin-branding-modal');
  if (modal) modal.style.display = 'none';
}

function updateBrandingPreview() {
  const titleVal = (document.getElementById('admin-input-app-title')?.value || '').trim() || 'Hestia Mail';
  const subVal = (document.getElementById('admin-input-app-subtitle')?.value || '').trim() || 'Web Suite';
  
  const prevTitle = document.getElementById('preview-brand-title');
  const prevSub = document.getElementById('preview-brand-subtitle');
  if (prevTitle) prevTitle.textContent = titleVal;
  if (prevSub) prevSub.textContent = subVal;
}

function applyBrandingTemplate(title, subtitle) {
  const titleInp = document.getElementById('admin-input-app-title');
  const subInp = document.getElementById('admin-input-app-subtitle');
  if (titleInp) titleInp.value = title;
  if (subInp) subInp.value = subtitle;
  updateBrandingPreview();
}

function submitBrandingConfig(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-branding');
  const feedback = document.getElementById('branding-feedback');
  const title = (document.getElementById('admin-input-app-title')?.value || '').trim();
  const subtitle = (document.getElementById('admin-input-app-subtitle')?.value || '').trim();

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Kaydediliyor...';
  }

  const formData = new FormData();
  formData.append('app_title', title);
  formData.append('app_subtitle', subtitle);

  fetch('api.php?action=admin_save_branding', {
    method: 'POST',
    body: formData
  })
    .then(r => r.json())
    .then(res => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = 'Kaydet & Uygula';
      }

      if (res && res.success) {
        // Update DOM elements in live session immediately
        const brandTitleEl = document.getElementById('app-brand-title');
        const brandSubEl = document.getElementById('app-brand-subtitle');
        const loginTitleEl = document.getElementById('login-brand-title');
        const loginSubEl = document.getElementById('login-brand-subtitle');
        const pageTitleEl = document.getElementById('app-page-title');

        if (brandTitleEl) brandTitleEl.textContent = res.app_title;
        if (brandSubEl) brandSubEl.textContent = res.app_subtitle;
        if (loginTitleEl) loginTitleEl.textContent = res.app_title;
        if (loginSubEl) loginSubEl.textContent = res.app_subtitle;
        if (pageTitleEl) pageTitleEl.textContent = res.app_title + ' - ' + res.app_subtitle;
        document.title = res.app_title + ' - ' + res.app_subtitle;

        if (feedback) {
          feedback.style.display = 'block';
          feedback.style.background = 'var(--success-light, rgba(16, 185, 129, 0.15))';
          feedback.style.borderColor = 'rgba(16, 185, 129, 0.4)';
          feedback.style.color = 'var(--success, #10b981)';
          feedback.innerHTML = '✓ ' + escapeHtml(res.message);
        }

        setTimeout(() => {
          closeBrandingModal();
        }, 1200);
      } else {
        if (feedback) {
          feedback.style.display = 'block';
          feedback.style.background = 'rgba(239, 68, 68, 0.1)';
          feedback.style.borderColor = 'rgba(239, 68, 68, 0.3)';
          feedback.style.color = 'var(--danger, #ef4444)';
          feedback.innerHTML = 'Hata: ' + escapeHtml((res && res.message) || 'Ayarlar kaydedilemedi.');
        }
      }
    })
    .catch(err => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = 'Kaydet & Uygula';
      }
      if (feedback) {
        feedback.style.display = 'block';
        feedback.style.background = 'rgba(239, 68, 68, 0.1)';
        feedback.style.borderColor = 'rgba(239, 68, 68, 0.3)';
        feedback.style.color = 'var(--danger, #ef4444)';
        feedback.innerHTML = 'Bağlantı hatası: ' + escapeHtml(err.message);
      }
    });
}

// ==========================================
// HESTIACP SERVER MODE & CONFIG CONTROLLERS
// ==========================================
function setHestiaServerMode(mode) {
  const isLocal = (mode === 'local');
  const radioRemote = document.getElementById('mode-radio-remote');
  const radioLocal = document.getElementById('mode-radio-local');
  const cardRemote = document.getElementById('card-mode-remote');
  const cardLocal = document.getElementById('card-mode-local');
  const remoteFields = document.getElementById('hestia-remote-fields');
  const localFields = document.getElementById('hestia-local-fields');
  const btnTestText = document.getElementById('btn-test-text');

  if (isLocal) {
    if (radioLocal) radioLocal.checked = true;
    if (cardLocal) {
      cardLocal.style.borderColor = 'var(--primary)';
      cardLocal.style.borderWidth = '2px';
    }
    if (cardRemote) {
      cardRemote.style.borderColor = 'var(--border-color)';
      cardRemote.style.borderWidth = '1px';
    }
    if (remoteFields) remoteFields.style.display = 'none';
    if (localFields) localFields.style.display = 'block';
    if (btnTestText) btnTestText.textContent = 'Yerel Komutları Test Et';
  } else {
    if (radioRemote) radioRemote.checked = true;
    if (cardRemote) {
      cardRemote.style.borderColor = 'var(--primary)';
      cardRemote.style.borderWidth = '2px';
    }
    if (cardLocal) {
      cardLocal.style.borderColor = 'var(--border-color)';
      cardLocal.style.borderWidth = '1px';
    }
    if (remoteFields) remoteFields.style.display = 'block';
    if (localFields) localFields.style.display = 'none';
    if (btnTestText) btnTestText.textContent = 'API Bağlantısını Test Et';
  }
}

function openHestiaConfigModal() {
  const modal = document.getElementById('admin-hestia-modal');
  const feedback = document.getElementById('hestia-test-feedback');
  if (feedback) feedback.style.display = 'none';

  // Fetch current config
  fetch('api.php?action=admin_get_hestia_config')
    .then(r => r.json())
    .then(res => {
      if (res.success && res.config) {
        const c = res.config;
        const currentMode = c.server_mode || 'remote';
        setHestiaServerMode(currentMode);

        const hostInput = document.getElementById('admin-hestia-host');
        if (hostInput && c.host) hostInput.value = c.host;
        const userInput = document.getElementById('admin-hestia-user');
        if (userInput && c.user) userInput.value = c.user;
        const localUserInput = document.getElementById('admin-hestia-local-user');
        if (localUserInput && c.user) localUserInput.value = c.user;

        const cliPathInput = document.getElementById('admin-hestia-cli-path');
        if (cliPathInput && c.cli_path) cliPathInput.value = c.cli_path;

        const sudoCheckbox = document.getElementById('admin-hestia-use-sudo');
        if (sudoCheckbox) sudoCheckbox.checked = (c.use_sudo !== 0 && c.use_sudo !== false);

        const keyIdInput = document.getElementById('admin-hestia-key-id');
        if (keyIdInput && c.key_id) keyIdInput.value = c.key_id;

        const notice = document.getElementById('hestia-saved-key-notice');
        const secInput = document.getElementById('admin-hestia-secret-key');
        if (c.has_key) {
          if (notice) notice.style.display = 'inline';
          if (secInput) {
            secInput.placeholder = c.masked_key ? `Mevcut: ${c.masked_key}` : 'Kayıtlı anahtar korunacak';
            secInput.value = c.secret_key || '';
          }
        } else {
          if (notice) notice.style.display = 'none';
          if (secInput) {
            secInput.placeholder = 'Örn: Jyy8ujn6ZnvLWjqYbMUGiqzbZpjb_6NUXAYHgxWW';
            secInput.value = '';
          }
        }

        // Detection status banner update
        const detectBanner = document.getElementById('hestia-local-detect-banner');
        const detectIcon = document.getElementById('hestia-local-detect-icon');
        const detectTitle = document.getElementById('hestia-local-detect-title');
        const detectDesc = document.getElementById('hestia-local-detect-desc');
        if (detectBanner && detectTitle && detectDesc) {
          if (c.is_local_installed) {
            detectBanner.style.background = 'rgba(16, 185, 129, 0.1)';
            detectBanner.style.borderColor = 'rgba(16, 185, 129, 0.35)';
            if (detectIcon) detectIcon.textContent = '🟢';
            detectTitle.textContent = 'HestiaCP Yerel Kurulumu Tespit Edildi';
            detectTitle.style.color = 'var(--success)';
            detectDesc.innerHTML = `Sunucunuzdaki <code>${escapeHtml(c.cli_path || '/usr/local/hestia/bin')}</code> dizini ve Hestia CLI komutları başarıyla doğrulandı. API anahtarsız ve port 8083'e ihtiyaç duymadan doğrudan çalıştırabilirsiniz.`;
          } else if (c.is_linux) {
            detectBanner.style.background = 'rgba(59, 130, 246, 0.08)';
            detectBanner.style.borderColor = 'rgba(59, 130, 246, 0.3)';
            if (detectIcon) detectIcon.textContent = 'ℹ️';
            detectTitle.textContent = 'Linux Sunucu Ortamı';
            detectTitle.style.color = 'var(--primary)';
            detectDesc.innerHTML = `Webmail Linux ortamında çalışıyor. HestiaCP CLI komut yolu olarak <code>${escapeHtml(c.cli_path || '/usr/local/hestia/bin')}</code> kullanılacaktır.`;
          } else {
            detectBanner.style.background = 'rgba(245, 158, 11, 0.08)';
            detectBanner.style.borderColor = 'rgba(245, 158, 11, 0.3)';
            if (detectIcon) detectIcon.textContent = '💻';
            detectTitle.textContent = 'Geliştirme / Windows Ortamı';
            detectTitle.style.color = '#f59e0b';
            detectDesc.innerHTML = `Şu anda yerel test ortamındasınız. Projenizi Linux HestiaCP sunucunuza aktardığınızda bu mod otomatik olarak sunucu CLI komutlarını kullanacaktır.`;
          }
        }

        // Prevent browser password manager from overwriting with saved website/db password
        setTimeout(() => {
          if (c.has_key && c.secret_key && secInput) {
            secInput.value = c.secret_key;
          }
          if (c.user && userInput) {
            userInput.value = c.user;
          }
        }, 100);

        const ipVal = document.getElementById('hestia-client-ip-val');
        if (ipVal && c.client_ip) ipVal.textContent = c.client_ip;
      }
      if (modal) modal.style.display = 'flex';
    })
    .catch(() => {
      if (modal) modal.style.display = 'flex';
    });
}

function closeHestiaConfigModal() {
  const modal = document.getElementById('admin-hestia-modal');
  if (modal) modal.style.display = 'none';
  const secInput = document.getElementById('admin-hestia-secret-key');
  if (secInput && secInput.type === 'text') {
    secInput.type = 'password';
  }
}

function testHestiaConnectionModal() {
  const btn = document.getElementById('btn-test-hestia-conn');
  const feedback = document.getElementById('hestia-test-feedback');
  const serverMode = document.querySelector('input[name="hestia_server_mode_radio"]:checked')?.value || 'remote';
  const host = document.getElementById('admin-hestia-host').value.trim();
  const user = (serverMode === 'local' ? document.getElementById('admin-hestia-local-user') : document.getElementById('admin-hestia-user')).value.trim() || 'admin';
  const keyId = document.getElementById('admin-hestia-key-id').value.trim();
  const secretKey = document.getElementById('admin-hestia-secret-key').value.trim();
  const cliPath = document.getElementById('admin-hestia-cli-path')?.value.trim() || '/usr/local/hestia/bin';
  const useSudo = document.getElementById('admin-hestia-use-sudo')?.checked ? 1 : 0;

  if (serverMode === 'remote' && !host) {
    showToast('Lütfen sunucu adresini giriniz.', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Test Ediliyor...';
  }
  if (feedback) {
    feedback.style.display = 'block';
    feedback.style.background = 'var(--bg-hover)';
    feedback.style.color = 'var(--text-primary)';
    feedback.style.borderColor = 'var(--border-color)';
    feedback.innerHTML = `<i class="fas fa-spinner fa-spin me-1"></i> ${serverMode === 'local' ? 'Yerel HestiaCP CLI komutu test ediliyor...' : 'HestiaCP REST API sunucusuna bağlantı testi yapılıyor...'}`;
  }

  const formData = new FormData();
  formData.append('server_mode', serverMode);
  formData.append('host', host);
  formData.append('user', user);
  formData.append('key_id', keyId);
  formData.append('secret_key', secretKey);
  formData.append('cli_path', cliPath);
  formData.append('use_sudo', useSudo);

  fetch('api.php?action=admin_save_hestia_config', {
    method: 'POST',
    body: formData
  })
  .then(async r => {
    const text = await r.text();
    let res;
    try {
      res = JSON.parse(text);
    } catch (e) {
      let cleanText = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
      if (cleanText.length > 250) cleanText = cleanText.substring(0, 250) + '...';
      throw new Error(cleanText || `Sunucu geçersiz yanıt verdi (HTTP ${r.status})`);
    }
    return res;
  })
  .then(res => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> <span id="btn-test-text">' + (serverMode === 'local' ? 'Yerel Komutları Test Et' : 'Bağlantıyı Test Et') + '</span>';
    }
    if (feedback) {
      feedback.style.display = 'block';
      if (res.connected) {
        feedback.style.background = 'var(--success-light, rgba(16,185,129,0.15))';
        feedback.style.color = 'var(--success)';
        feedback.style.borderColor = 'rgba(16,185,129,0.3)';
        feedback.innerHTML = `<strong>✓ Bağlantı Başarılı:</strong> ${escapeHtml(res.message)}`;
      } else {
        feedback.style.background = 'rgba(239,68,68,0.1)';
        feedback.style.color = 'var(--danger)';
        feedback.style.borderColor = 'rgba(239,68,68,0.3)';
        feedback.innerHTML = `<strong>⚠️ Bildirim:</strong> ${escapeHtml(res.message).replace(/\n/g, '<br>')}`;
      }
    }
  })
  .catch(err => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<span id="btn-test-text">Bağlantıyı / Komutları Test Et</span>';
    }
    if (feedback) {
      feedback.style.display = 'block';
      feedback.style.background = 'rgba(239,68,68,0.1)';
      feedback.style.color = 'var(--danger)';
      feedback.style.borderColor = 'rgba(239,68,68,0.3)';
      feedback.innerHTML = `<strong>Hata:</strong> ${escapeHtml(err.message)}`;
    }
  });
}

function submitHestiaConfig(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-hestia-config');
  const serverMode = document.querySelector('input[name="hestia_server_mode_radio"]:checked')?.value || 'remote';
  const host = document.getElementById('admin-hestia-host').value.trim();
  const user = (serverMode === 'local' ? document.getElementById('admin-hestia-local-user') : document.getElementById('admin-hestia-user')).value.trim() || 'admin';
  const keyId = document.getElementById('admin-hestia-key-id').value.trim();
  const secretKey = document.getElementById('admin-hestia-secret-key').value.trim();
  const cliPath = document.getElementById('admin-hestia-cli-path')?.value.trim() || '/usr/local/hestia/bin';
  const useSudo = document.getElementById('admin-hestia-use-sudo')?.checked ? 1 : 0;

  if (serverMode === 'remote' && !host) {
    showToast('Lütfen sunucu adresini giriniz.', 'error');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Kaydediliyor...';
  }

  const formData = new FormData();
  formData.append('server_mode', serverMode);
  formData.append('host', host);
  formData.append('user', user);
  formData.append('key_id', keyId);
  formData.append('secret_key', secretKey);
  formData.append('cli_path', cliPath);
  formData.append('use_sudo', useSudo);

  fetch('api.php?action=admin_save_hestia_config', {
    method: 'POST',
    body: formData
  })
  .then(async r => {
    const text = await r.text();
    let res;
    try {
      res = JSON.parse(text);
    } catch (e) {
      let cleanText = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
      if (cleanText.length > 250) cleanText = cleanText.substring(0, 250) + '...';
      throw new Error(cleanText || `Sunucu geçersiz yanıt verdi (HTTP ${r.status})`);
    }
    return res;
  })
  .then(res => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Kaydet & Uygula';
    }
    if (res.success) {
      closeHestiaConfigModal();
      Swal.fire({
        icon: res.connected ? 'success' : (res.message && res.message.includes('IP') ? 'warning' : 'info'),
        title: res.connected ? 'HestiaCP Bağlantısı Başarılı' : 'Ayarlar Kaydedildi',
        text: res.message,
        confirmButtonText: 'Tamam'
      });
      loadAdminDashboard();
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Kayıt Başarısız',
        text: res.message || 'Ayarlar kaydedilemedi.'
      });
    }
  })
  .catch(err => {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = 'Kaydet & Uygula';
    }
    Swal.fire({
      icon: 'error',
      title: 'Hata',
      text: 'Bağlantı hatası: ' + err.message
    });
  });
}

function syncHestiaAccounts() {
  const btn = document.getElementById('btn-sync-hestia');
  const textEl = document.getElementById('sync-hestia-text');

  if (btn) btn.disabled = true;
  if (textEl) textEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Senkronize Ediliyor...';

  fetch('api.php?action=admin_sync_hestia_accounts', { method: 'POST' })
    .then(r => r.json())
    .then(res => {
      if (btn) btn.disabled = false;
      if (textEl) textEl.innerHTML = '🔄 Hestia\'dan Çek';

      if (res.success) {
        Swal.fire({
          icon: 'success',
          title: 'HestiaCP Senkronizasyonu Tamamlandı',
          text: res.message,
          timer: 3000,
          showConfirmButton: false
        });
        loadAdminDashboard();
      } else {
        Swal.fire({
          icon: 'warning',
          title: 'Senkronizasyon Yapılamadı',
          text: res.message,
          showCancelButton: true,
          confirmButtonText: 'API Ayarlarını Yap',
          cancelButtonText: 'Kapat'
        }).then(result => {
          if (result.isConfirmed) {
            openHestiaConfigModal();
          }
        });
      }
    })
    .catch(err => {
      if (btn) btn.disabled = false;
      if (textEl) textEl.innerHTML = '🔄 Hestia\'dan Çek';
      Swal.fire({
        icon: 'error',
        title: 'Hata',
        text: 'Sunucu bağlantı hatası: ' + err.message
      });
    });
}

// ==========================================
// SCREEN LOCK (EKRAN KİLİTLEME) ENGINE
// ==========================================
let screenLockClockInterval = null;
let lastUserActivityTime = Date.now();
const AUTO_LOCK_IDLE_MINUTES = 15; // 15 dakika hareketsizlikte otomatik kilitle

function lockScreen() {
  const overlay = document.getElementById('screen-lock-overlay');
  if (!overlay) return;

  try {
    localStorage.setItem('webmail_screen_locked', '1');
    sessionStorage.setItem('webmail_screen_locked', '1');
  } catch (e) {}

  overlay.style.display = 'flex';

  const errEl = document.getElementById('screen-lock-error');
  if (errEl) errEl.style.display = 'none';

  const passInp = document.getElementById('screen-lock-password');
  if (passInp) {
    passInp.value = '';
    setTimeout(() => passInp.focus(), 150);
  }

  updateScreenLockClock();
  if (!screenLockClockInterval) {
    screenLockClockInterval = setInterval(updateScreenLockClock, 1000);
  }
}

function unlockScreen() {
  const overlay = document.getElementById('screen-lock-overlay');
  if (overlay) overlay.style.display = 'none';

  try {
    localStorage.removeItem('webmail_screen_locked');
    sessionStorage.removeItem('webmail_screen_locked');
  } catch (e) {}

  if (screenLockClockInterval) {
    clearInterval(screenLockClockInterval);
    screenLockClockInterval = null;
  }
}

function handleScreenUnlock(e) {
  if (e) e.preventDefault();
  const passInp = document.getElementById('screen-lock-password');
  const errEl = document.getElementById('screen-lock-error');
  const btn = document.getElementById('screen-lock-btn-submit');
  if (!passInp) return;

  const password = passInp.value.trim();
  if (!password) {
    if (errEl) {
      errEl.textContent = 'Lütfen şifrenizi girin.';
      errEl.style.display = 'block';
    }
    return;
  }

  if (btn) btn.disabled = true;
  if (errEl) errEl.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'verify_screen_lock');
  fd.append('password', password);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (btn) btn.disabled = false;
      if (res.success) {
        unlockScreen();
        showToast('Kilit açıldı.', 'success');
      } else {
        if (errEl) {
          errEl.textContent = res.message || 'Hatalı şifre. Lütfen tekrar deneyin.';
          errEl.style.display = 'block';
        }
        passInp.select();
      }
    })
    .catch(err => {
      if (btn) btn.disabled = false;
      if (errEl) {
        errEl.textContent = 'Bağlantı hatası: ' + err.message;
        errEl.style.display = 'block';
      }
    });
}

function toggleScreenLockPasswordVis() {
  const inp = document.getElementById('screen-lock-password');
  if (!inp) return;
  inp.type = (inp.type === 'password') ? 'text' : 'password';
}

function updateScreenLockClock() {
  const clockEl = document.getElementById('screen-lock-clock');
  const dateEl = document.getElementById('screen-lock-date');
  const now = new Date();

  if (clockEl) {
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    clockEl.textContent = `${hh}:${mm}:${ss}`;
  }

  if (dateEl) {
    dateEl.textContent = now.toLocaleDateString('tr-TR', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  }
}

function saveScreenLockSettings(notify = false) {
  const chk = document.getElementById('setting-auto-lock-enabled');
  const minInp = document.getElementById('setting-auto-lock-minutes');
  const autoLockEnabled = chk ? (chk.checked ? 1 : 0) : 1;
  let minutes = parseInt(minInp?.value || '15', 10);
  if (isNaN(minutes) || minutes < 1) minutes = 1;
  if (minutes > 480) minutes = 480;
  if (minInp) minInp.value = minutes;

  window.AUTO_LOCK_ENABLED = Boolean(autoLockEnabled);
  window.AUTO_LOCK_MINUTES = minutes;
  lastUserActivityTime = Date.now();

  const fd = new FormData();
  fd.append('action', 'save_screen_lock_settings');
  fd.append('auto_lock_enabled', autoLockEnabled);
  fd.append('auto_lock_minutes', minutes);

  fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        if (notify) showToast(data.message || 'Otomatik ekran kilitleme ayarları kaydedildi.', 'success');
        const statusEl = document.getElementById('screen-lock-status-info');
        if (statusEl) {
          statusEl.textContent = autoLockEnabled
            ? `Durum: Otomatik kilitleme aktif (${minutes} dakika)`
            : 'Durum: Otomatik kilitleme kapalı';
        }
      } else {
        if (notify) showToast((data && data.message) || 'Ayarlar kaydedilemedi.', 'error');
      }
    })
    .catch(err => {
      if (notify) showToast('Bağlantı hatası: ' + err.message, 'error');
    });
}

function initScreenLockSystem() {
  // Check if previously locked
  try {
    if (localStorage.getItem('webmail_screen_locked') === '1' || sessionStorage.getItem('webmail_screen_locked') === '1') {
      lockScreen();
    }
  } catch (e) {}

  // Keyboard shortcut: Ctrl+Alt+L or Ctrl+L
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey && e.altKey && (e.key === 'l' || e.key === 'L')) || (e.ctrlKey && !e.shiftKey && !e.altKey && (e.key === 'l' || e.key === 'L'))) {
      const activeEl = document.activeElement;
      if (e.altKey || (!activeEl || (activeEl.tagName !== 'INPUT' && activeEl.tagName !== 'TEXTAREA'))) {
        e.preventDefault();
        lockScreen();
      }
    }
  });

  // Track idle activity for auto lock
  const resetIdle = () => {
    lastUserActivityTime = Date.now();
  };
  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(evt => {
    window.addEventListener(evt, resetIdle, { passive: true });
  });

  setInterval(() => {
    try {
      const autoLockEnabled = (window.AUTO_LOCK_ENABLED !== false);
      if (!autoLockEnabled) return;
      const idleLimitMinutes = Math.max(1, parseInt(window.AUTO_LOCK_MINUTES, 10) || AUTO_LOCK_IDLE_MINUTES);
      if (localStorage.getItem('webmail_screen_locked') !== '1') {
        const idleMinutes = (Date.now() - lastUserActivityTime) / 60000;
        if (idleMinutes >= idleLimitMinutes) {
          lockScreen();
        }
      }
    } catch (e) {}
  }, 30000);
}

// Initial Load
document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  setupComposeDropzone();
  initPaneResizer();
  updateMailSortDropdownUI();
  initScreenLockSystem();
  if (window.HAS_ACTIVE_ACCOUNT) {
    loadCustomFolders();
    loadMessages();
    setInterval(updateFolderCounts, 60000);
    checkActiveImportStatus();
    startChatEngine();
    if (window.location.hash === '#chat' || new URLSearchParams(window.location.search).get('tab') === 'chat') {
      switchMainTab('chat');
    }
    if (window.IS_ADMIN_ACCOUNT) {
      if (window.location.hash === '#admin' || new URLSearchParams(window.location.search).get('tab') === 'admin') {
        switchMainTab('admin');
      }
    }
  }
});
