/**
 * Hestia Webmail Suite - Core JavaScript Client
 */

const Webmail = {
  baseUrl: window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1),
  currentFolder: 'INBOX',
  currentUid: null,
  activeAccount: null,
  currentMail: null,
  forwardAttachments: [],
  uploadedFiles: [],
  
  escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  },
  
  init() {
    this.bindGlobalEvents();
    this.initTheme();
    this.initRichEditor();
    this.initComposeAutoSuggest();
  },

  // Toast Notification System
  showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let iconSvg = '';
    if (type === 'success') {
      iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>`;
    } else {
      iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
    }

    toast.innerHTML = `${iconSvg} <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      toast.style.transition = 'all 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  },

  // Theme Management
  initTheme() {
    const saved = localStorage.getItem('hestia_mail_theme') || document.documentElement.getAttribute('data-theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);
    this.updateThemeButton(saved);
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('hestia_mail_theme', next);
    this.updateThemeButton(next);

    // Save to backend
    fetch(`${this.baseUrl}settings/save_preferences`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `theme=${next}`
    });
  },

  updateThemeButton(theme) {
    const btn = document.getElementById('theme-toggle-btn');
    if (btn) {
      btn.innerHTML = theme === 'dark' 
        ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`
        : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
    }
  },

  // Email Reader Actions
  loadEmail(folder, uid) {
    this.currentFolder = folder;
    this.currentUid = uid;

    const readerPane = document.getElementById('mail-reader-pane');
    if (!readerPane) return;

    // Highlight row
    document.querySelectorAll('.mail-item').forEach(el => el.classList.remove('selected'));
    const row = document.getElementById(`mail-item-${uid}`);
    if (row) {
      row.classList.add('selected');
      row.classList.remove('unread');
    }

    readerPane.innerHTML = `
      <div class="reader-empty-state">
        <div style="display:inline-block;width:32px;height:32px;border:3px solid var(--border-color);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        <p>E-posta yükleniyor...</p>
      </div>
      <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
    `;

    fetch(`${this.baseUrl}mail/view_email_ajax?folder=${encodeURIComponent(folder)}&uid=${uid}`)
      .then(res => res.json())
      .then(data => {
        if (!data.success || !data.email) {
          readerPane.innerHTML = `
            <div class="reader-empty-state">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <p>${data.message || 'E-posta görüntülenemedi.'}</p>
            </div>
          `;
          return;
        }

        const mail = data.email;
        this.renderEmailView(mail);
      })
      .catch(err => {
        readerPane.innerHTML = `<div class="reader-empty-state"><p>Bağlantı hatası oluştu.</p></div>`;
      });
  },

  renderEmailView(mail) {
    this.currentMail = mail;
    this.currentUid = mail.uid;
    if (mail.folder) this.currentFolder = mail.folder;
    const readerPane = document.getElementById('mail-reader-pane');
    const senderName = mail.from.name || mail.from.email;
    const initial = senderName.charAt(0).toUpperCase();

    // Attachments HTML
    let attachmentsHtml = '';
    if (mail.attachments && mail.attachments.length > 0) {
      attachmentsHtml = `
        <div class="attachments-wrapper">
          <div class="attachments-title">${mail.attachments.length} Ek Dosya</div>
          <div class="attachment-grid">
            ${mail.attachments.map(att => `
              <a href="${this.baseUrl}mail/download_attachment?folder=${encodeURIComponent(mail.folder)}&uid=${mail.uid}&part=${att.part_no}&name=${encodeURIComponent(att.filename)}" class="attachment-chip" download="${att.filename}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <span><strong>${att.filename}</strong> (${att.size})</span>
              </a>
            `).join('')}
          </div>
        </div>
      `;
    }

    // Body content: prefer HTML, fallback to plain text with preserved line breaks
    let bodyContent = mail.has_html 
      ? mail.body_html 
      : `<pre style="white-space: pre-wrap; font-family: inherit;">${mail.body_plain}</pre>`;

    readerPane.innerHTML = `
      <div class="reader-header">
        <div class="reader-toolbar">
          <div class="toolbar-group">
            <button class="reader-action-btn" id="btn-nav-prev-mail" title="Önceki E-posta" onclick="if (typeof navigateEmail === 'function') navigateEmail('prev');">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
              <span>Önceki</span>
            </button>
            <button class="reader-action-btn" id="btn-nav-next-mail" title="Sonraki E-posta" onclick="if (typeof navigateEmail === 'function') navigateEmail('next');">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
              <span>Sonraki</span>
            </button>
            <div style="width:1px;height:32px;background:var(--border-color);margin:0 2px;align-self:center;"></div>
            <button class="reader-action-btn" title="Yanıtla" onclick="Webmail.openReply('${encodeURIComponent(JSON.stringify(mail))}')">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
              <span>Yanıtla</span>
            </button>
            <button class="reader-action-btn" title="İlet" onclick="Webmail.openForward('${encodeURIComponent(JSON.stringify(mail))}')">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/></svg>
              <span>İlet</span>
            </button>
            <button class="reader-action-btn ${mail.is_flagged ? 'starred' : ''}" title="Yıldızla" onclick="Webmail.toggleStar('${mail.folder}', ${mail.uid})">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="${mail.is_flagged ? '#fbbf24' : 'none'}" stroke="${mail.is_flagged ? '#fbbf24' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span>${mail.is_flagged ? 'Yıldızlı' : 'Yıldızla'}</span>
            </button>
            <button class="reader-action-btn" title="Filtre Oluştur" onclick="if (typeof openFilterModalForMessage === 'function') openFilterModalForMessage(${mail.uid});">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
              <span>Filtre</span>
            </button>
            <button class="reader-action-btn" title="Klasöre Taşı" onclick="if (typeof openMoveModal === 'function') openMoveModal(${mail.uid});">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="12 11 12 17 9 14"/></svg>
              <span>Taşı</span>
            </button>
            ${mail.is_spam ? `
              <button class="reader-action-btn btn-unspam" title="Spam Değil (Gelen Kutusuna Taşı)" onclick="Webmail.markSpam('${mail.folder}', ${mail.uid}, 'unspam')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Spam Değil</span>
              </button>
            ` : `
              <button class="reader-action-btn btn-spam" title="Spam Olarak İşaretle" onclick="Webmail.markSpam('${mail.folder}', ${mail.uid}, 'spam')">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Spam</span>
              </button>
            `}
            <button class="reader-action-btn btn-delete" title="Sil" onclick="Webmail.deleteEmail('${mail.folder}', ${mail.uid})">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              <span>Sil</span>
            </button>
          </div>
          <div class="toolbar-group">
            <button class="btn-icon" title="Yazdır" onclick="window.print()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            </button>
          </div>
        </div>

        ${mail.is_spam ? `
          <div class="spam-alert-banner">
            <div class="spam-alert-content">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>
                <strong style="display:block;margin-bottom:2px;">Bu ileti İstenmeyen (Spam) klasöründe bulunuyor.</strong>
                <span style="font-size:0.8rem;opacity:0.9;">Bu ileti spam değilse güvenle Gelen Kutusu'na taşıyabilirsiniz.</span>
              </div>
            </div>
            <button class="btn-unspam-banner" onclick="Webmail.markSpam('${mail.folder}', ${mail.uid}, 'unspam')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Spam Değil Olarak İşaretle</span>
            </button>
          </div>
        ` : ''}

        <h2 class="reader-subject">${mail.subject}</h2>

        <div class="reader-sender-box">
          <div class="sender-details">
            <div class="sender-avatar-lg">${initial}</div>
            <div>
              <div class="sender-name-lg">${senderName}</div>
              <div class="sender-email-lg">&lt;${mail.from.email}&gt; &bull; Alıcı: ${mail.to.raw || 'Ben'}</div>
            </div>
          </div>
          <div class="reader-date-lg">${mail.date}</div>
        </div>
      </div>

      <div class="reader-body-container">
        ${attachmentsHtml}
        <div class="reader-html-content" id="reader-html-content">
        </div>
      </div>
    `;

    const contentBox = document.getElementById('reader-html-content');
    if (contentBox) {
      if (mail.has_html) {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        this.renderEmailBodyIframe(contentBox, mail.body_html, isDark);
      } else {
        contentBox.innerHTML = `<pre style="white-space: pre-wrap; font-family: inherit; margin: 0; padding: 6px 0;">${mail.body_plain}</pre>`;
      }
    }
  },

  // Email Body CSS Isolation via Sandboxed iFrame
  renderEmailBodyIframe(containerEl, htmlContent, isDarkTheme = false) {
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
      containerEl.innerHTML = this.isolateEmailHtmlForEditor(htmlContent);
    }
  },

  isolateEmailHtmlForEditor(rawHtml) {
    if (!rawHtml || typeof rawHtml !== 'string') return '';
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(rawHtml, 'text/html');
      doc.querySelectorAll('style').forEach(styleEl => {
        const cssText = styleEl.textContent || '';
        if (cssText && typeof CSSStyleSheet !== 'undefined') {
          try {
            const sheet = new CSSStyleSheet();
            if (typeof sheet.replaceSync === 'function') {
              sheet.replaceSync(cssText);
              const rules = sheet.cssRules || [];
              for (let i = 0; i < rules.length; i++) {
                const rule = rules[i];
                if (rule.type === 1 && rule.selectorText && rule.style) {
                  const sel = rule.selectorText.trim();
                  if (/^(html|body|\*|:root)\b/i.test(sel)) continue;
                  try {
                    const matched = doc.body.querySelectorAll(sel);
                    matched.forEach(el => {
                      const existing = el.getAttribute('style') || '';
                      el.setAttribute('style', rule.style.cssText + (existing ? '; ' + existing : ''));
                    });
                  } catch (qErr) {}
                }
              }
            }
          } catch (sErr) {}
        }
        styleEl.remove();
      });
      doc.querySelectorAll('style, script, link, meta, title, base, iframe, frame, frameset, object, embed, applet').forEach(el => el.remove());
      doc.body.querySelectorAll('*').forEach(el => {
        Array.from(el.attributes).forEach(attr => {
          if (/^on/i.test(attr.name)) el.removeAttribute(attr.name);
        });
        if (el.hasAttribute('style')) {
          let st = el.getAttribute('style');
          st = st.replace(/position\s*:\s*(fixed|sticky)/gi, 'position: relative')
                 .replace(/100\s*v[wh]/gi, '100%');
          el.setAttribute('style', st);
        }
      });
      return `<div class="mail-isolated-quote" style="position:relative;contain:layout paint;isolation:isolate;overflow-x:auto;max-width:100%;box-sizing:border-box;">${doc.body.innerHTML}</div>`;
    } catch (e) {
      return rawHtml
        .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
        .replace(/<link\b[^>]*>/gi, '')
        .replace(/<base\b[^>]*>/gi, '')
        .replace(/<meta\b[^>]*>/gi, '');
    }
  },

  // Star & Flag toggle
  toggleStar(folder, uid, event) {
    if (event) event.stopPropagation();

    const starBtn = document.getElementById(`star-${uid}`);
    const isStarred = starBtn ? starBtn.classList.contains('starred') : false;
    const nextType = isStarred ? 'unflagged' : 'flagged';

    if (starBtn) {
      starBtn.classList.toggle('starred');
      starBtn.querySelector('svg').setAttribute('fill', !isStarred ? '#fbbf24' : 'none');
      starBtn.querySelector('svg').setAttribute('stroke', !isStarred ? '#fbbf24' : 'currentColor');
    }

    const formData = new FormData();
    formData.append('folder', folder);
    formData.append('uid', uid);
    formData.append('type', nextType);

    fetch(`${this.baseUrl}mail/mark_status`, {
      method: 'POST',
      body: formData
    });
  },

  // Delete Email
  deleteEmail(folder, uid) {
    if (!confirm('Bu e-postayı silmek istediğinizden emin misiniz?')) return;

    const row = document.getElementById(`mail-item-${uid}`);
    if (row) {
      row.style.opacity = '0.3';
    }

    const formData = new FormData();
    formData.append('folder', folder);
    formData.append('uid', uid);

    fetch(`${this.baseUrl}mail/delete_email`, {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        this.showToast('E-posta silindi.');
        if (row) row.remove();
        
        // Reset reader pane if deleted email was open
        if (this.currentUid === uid) {
          const readerPane = document.getElementById('mail-reader-pane');
          if (readerPane) {
            readerPane.innerHTML = `
              <div class="reader-empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>
                <p>Okumak için listeden bir e-posta seçin</p>
              </div>
            `;
          }
          this.currentUid = null;
        }

        // AJAX ile ekranı yenile
        this.loadFolderMessages(folder);
      } else {
        this.showToast('E-posta silinemedi: ' + (data.message || ''), 'error');
        if (row) row.style.opacity = '1';
      }
    });
  },

  // Refresh messages list via AJAX
  loadFolderMessages(folder = null) {
    const target = folder || this.currentFolder || 'INBOX';
    fetch(`${this.baseUrl}mail/get_messages_ajax?folder=${encodeURIComponent(target)}`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.messages) {
          const list = document.querySelector('.mail-list-scroll');
          if (list) {
            if (data.messages.length === 0) {
              list.innerHTML = `<div style="padding:2.5rem 1.5rem;text-align:center;color:var(--text-muted);"><p>Bu klasörde e-posta bulunmuyor.</p></div>`;
            } else {
              list.innerHTML = data.messages.map(m => `
                <div class="mail-item ${m.is_unseen ? 'unread' : ''}" id="mail-item-${m.uid}" onclick="Webmail.loadEmail('${m.folder}', ${m.uid})">
                  <div class="mail-item-left">
                    <button class="star-btn ${m.is_flagged ? 'starred' : ''}" id="star-${m.uid}" onclick="Webmail.toggleStar('${m.folder}', ${m.uid}, event)">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="${m.is_flagged ? '#fbbf24' : 'none'}" stroke="${m.is_flagged ? '#fbbf24' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </button>
                    <div class="mail-item-avatar">${(m.from.name || m.from.email).charAt(0).toUpperCase()}</div>
                  </div>
                  <div class="mail-item-body">
                    <div class="mail-item-header">
                      <span class="mail-sender">${m.from.name || m.from.email}</span>
                      <span class="mail-date">${m.date}</span>
                    </div>
                    <div class="mail-subject">${m.subject || '(Konu Yok)'}</div>
                    <div class="mail-snippet">${m.snippet || ''}</div>
                  </div>
                </div>
              `).join('');
            }
          }
        }
      })
      .catch(() => {});
  },

  // Mark as Spam or Unmark Spam
  markSpam(folder, uid, action = null, event = null) {
    if (event) event.stopPropagation();

    const isFolderSpam = folder.toLowerCase().includes('junk') || folder.toLowerCase().includes('spam') || folder.toLowerCase().includes('istenmeyen');
    const isSpam = (action === 'spam') || (!action && !isFolderSpam);

    const row = document.getElementById(`mail-item-${uid}`);
    if (row) {
      row.style.opacity = '0.35';
      row.style.transform = 'translateX(-8px)';
      row.style.transition = 'all 0.25s ease';
    }

    const formData = new FormData();
    formData.append('folder', folder);
    formData.append('uid', uid);
    if (action) {
      formData.append('action', action);
    }

    fetch(`${this.baseUrl}mail/mark_spam`, {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        this.showToast(data.message || (isSpam ? 'E-posta İstenmeyen (Spam) olarak işaretlendi.' : 'E-posta Gelen Kutusu\'na taşındı.'));
        if (row) {
          row.style.height = '0px';
          row.style.padding = '0px';
          row.style.margin = '0px';
          row.style.border = 'none';
          row.style.overflow = 'hidden';
          setTimeout(() => row.remove(), 250);
        }

        // Reset reader pane if marked email was open
        if (this.currentUid === uid) {
          const readerPane = document.getElementById('mail-reader-pane');
          if (readerPane) {
            readerPane.innerHTML = `
              <div class="reader-empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>
                <p>Okumak için listeden bir e-posta seçin</p>
              </div>
            `;
          }
          this.currentUid = null;
        }

        // AJAX ile ekranı yenile
        this.loadFolderMessages(folder);
      } else {
        this.showToast(data.message || 'İşlem gerçekleştirilemedi.', 'error');
        if (row) {
          row.style.opacity = '1';
          row.style.transform = 'none';
        }
      }
    })
    .catch(err => {
      this.showToast('Sunucu bağlantı hatası oluştu.', 'error');
      if (row) {
        row.style.opacity = '1';
        row.style.transform = 'none';
      }
    });
  },

  // Compose Modal Handlers
  openCompose(to = '', subject = '', body = '', forwardedAttachments = []) {
    const modal = document.getElementById('compose-modal');
    if (!modal) return;

    document.getElementById('compose-to').value = to;
    document.getElementById('compose-subject').value = subject;
    const editor = document.getElementById('compose-editor');
    if (editor) {
      editor.innerHTML = (body && /<\s*(style|link|base|script|iframe|meta)\b/i.test(body))
        ? this.isolateEmailHtmlForEditor(body)
        : body;
    }
    
    this.uploadedFiles = [];
    this.forwardAttachments = Array.isArray(forwardedAttachments) ? [...forwardedAttachments] : [];
    
    const fileInput = document.getElementById('compose-attachments');
    if (fileInput) fileInput.value = '';

    this.renderComposeAttachments();

    modal.classList.add('active');
    if (!to) {
      document.getElementById('compose-to').focus();
    } else {
      document.getElementById('compose-subject').focus();
    }
  },

  closeCompose() {
    const modal = document.getElementById('compose-modal');
    if (modal) modal.classList.remove('active');
    const editor = document.getElementById('compose-editor');
    if (editor) editor.innerHTML = '';
    this.forwardAttachments = [];
    this.uploadedFiles = [];
    const fileInput = document.getElementById('compose-attachments');
    if (fileInput) fileInput.value = '';
    const fileList = document.getElementById('compose-file-list');
    if (fileList) {
      fileList.innerHTML = '';
      fileList.style.display = 'none';
    }
  },

  openReply(rawMailJson) {
    try {
      let mail = this.currentMail;
      if (!mail && rawMailJson) {
        mail = typeof rawMailJson === 'string' ? JSON.parse(decodeURIComponent(rawMailJson)) : rawMailJson;
      }
      if (!mail) return;

      const to = mail.from ? (mail.from.email || '') : '';
      const subject = mail.subject && mail.subject.startsWith('Re:') ? mail.subject : `Re: ${mail.subject || ''}`;
      const safeBody = this.isolateEmailHtmlForEditor(mail.body_html || mail.body_plain || '');
      const quote = `<br><br><br><hr><p><strong>Kimden:</strong> ${this.escapeHtml(mail.from ? (mail.from.name || mail.from.email) : '')} &lt;${this.escapeHtml(to)}&gt;<br><strong>Tarih:</strong> ${this.escapeHtml(mail.date || '')}<br><strong>Konu:</strong> ${this.escapeHtml(mail.subject || '')}</p><blockquote>${safeBody}</blockquote>`;
      this.openCompose(to, subject, quote);
    } catch (e) {
      console.error(e);
    }
  },

  openForward(rawMailJson) {
    try {
      let mail = this.currentMail;
      if (!mail && rawMailJson) {
        mail = typeof rawMailJson === 'string' ? JSON.parse(decodeURIComponent(rawMailJson)) : rawMailJson;
      }
      if (!mail) {
        this.showToast('İletilecek e-posta verisi bulunamadı.', 'error');
        return;
      }

      const subject = mail.subject && mail.subject.startsWith('Fwd:') ? mail.subject : `Fwd: ${mail.subject || ''}`;
      const senderStr = mail.from ? (mail.from.name ? `${this.escapeHtml(mail.from.name)} &lt;${this.escapeHtml(mail.from.email)}&gt;` : this.escapeHtml(mail.from.email)) : '';
      const safeBody = this.isolateEmailHtmlForEditor(mail.body_html || mail.body_plain || '');
      const quote = `<br><br><br><hr><p><strong>---------- İletilen İleti ----------</strong><br><strong>Kimden:</strong> ${senderStr}<br><strong>Tarih:</strong> ${this.escapeHtml(mail.date || '')}<br><strong>Konu:</strong> ${this.escapeHtml(mail.subject || '')}</p><blockquote>${safeBody}</blockquote>`;

      const forwardedAttachments = (mail.attachments || []).map(att => ({
        folder: mail.folder || this.currentFolder || 'INBOX',
        uid: mail.uid || this.currentUid,
        part_no: att.part_no,
        filename: att.filename,
        size: att.size,
        mime_type: att.mime_type
      }));

      this.openCompose('', subject, quote, forwardedAttachments);
    } catch (e) {
      console.error(e);
      this.showToast('E-posta iletme hatası oluştu.', 'error');
    }
  },

  removeForwardAttachment(index) {
    if (this.forwardAttachments && this.forwardAttachments[index] !== undefined) {
      this.forwardAttachments.splice(index, 1);
      this.renderComposeAttachments();
    }
  },

  removeUploadedFile(index) {
    if (this.uploadedFiles && this.uploadedFiles[index] !== undefined) {
      this.uploadedFiles.splice(index, 1);
      const fileInput = document.getElementById('compose-attachments');
      if (fileInput && window.DataTransfer) {
        const dt = new DataTransfer();
        this.uploadedFiles.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
      }
      this.renderComposeAttachments();
    }
  },

  renderComposeAttachments() {
    const list = document.getElementById('compose-file-list');
    if (!list) return;
    list.innerHTML = '';

    const totalAttachments = (this.forwardAttachments ? this.forwardAttachments.length : 0) + 
                             (this.uploadedFiles ? this.uploadedFiles.length : 0);

    if (totalAttachments === 0) {
      list.style.display = 'none';
      return;
    }

    list.style.display = 'flex';

    // 1. Render forwarded attachments
    if (this.forwardAttachments && this.forwardAttachments.length > 0) {
      this.forwardAttachments.forEach((att, idx) => {
        const chip = document.createElement('div');
        chip.className = 'compose-attach-chip';
        chip.title = `${att.filename} (İletilen Ek)`;
        chip.innerHTML = `
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--primary);"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          <span class="chip-name">${this.escapeHtml(att.filename)}</span>
          <span class="chip-size">(${this.escapeHtml(att.size || '')})</span>
          <span class="chip-remove" onclick="Webmail.removeForwardAttachment(${idx})" title="Eki Kaldır">&times;</span>
        `;
        list.appendChild(chip);
      });
    }

    // 2. Render newly uploaded attachments
    if (this.uploadedFiles && this.uploadedFiles.length > 0) {
      this.uploadedFiles.forEach((file, idx) => {
        const chip = document.createElement('div');
        chip.className = 'compose-attach-chip';
        chip.title = file.name;
        chip.innerHTML = `
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          <span class="chip-name">${this.escapeHtml(file.name)}</span>
          <span class="chip-size">(${Math.round(file.size / 1024)} KB)</span>
          <span class="chip-remove" onclick="Webmail.removeUploadedFile(${idx})" title="Dosyayı Kaldır">&times;</span>
        `;
        list.appendChild(chip);
      });
    }
  },

  sendEmail(event) {
    if (event) event.preventDefault();

    const to = document.getElementById('compose-to').value.trim();
    const subject = document.getElementById('compose-subject').value.trim();
    const editor = document.getElementById('compose-editor');
    const body = editor.innerHTML;
    const btnSend = document.getElementById('btn-send-mail');

    if (!to) {
      this.showToast('Lütfen alıcı (Kime) adresini girin.', 'error');
      document.getElementById('compose-to').focus();
      return;
    }

    const formData = new FormData();
    formData.append('to', to);
    formData.append('subject', subject);
    formData.append('body', body);

    const cc = document.getElementById('compose-cc')?.value.trim();
    if (cc) formData.append('cc', cc);

    const bcc = document.getElementById('compose-bcc')?.value.trim();
    if (bcc) formData.append('bcc', bcc);

    // Forwarded attachments
    if (this.forwardAttachments && this.forwardAttachments.length > 0) {
      formData.append('forward_attachments', JSON.stringify(this.forwardAttachments));
    }

    // Attachments
    if (this.uploadedFiles && this.uploadedFiles.length > 0) {
      for (let i = 0; i < this.uploadedFiles.length; i++) {
        formData.append('attachments[]', this.uploadedFiles[i]);
      }
    } else {
      const fileInput = document.getElementById('compose-attachments');
      if (fileInput && fileInput.files) {
        for (let i = 0; i < fileInput.files.length; i++) {
          formData.append('attachments[]', fileInput.files[i]);
        }
      }
    }

    btnSend.disabled = true;
    btnSend.innerHTML = `
      <div style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;margin-right:6px;"></div>
      Gönderiliyor...
    `;

    fetch(`${this.baseUrl}mail/send_email`, {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      btnSend.disabled = false;
      btnSend.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        <span>Gönder</span>
      `;

      if (data.success) {
        this.showToast(data.message || 'E-posta başarıyla gönderildi!');
        this.closeCompose();
      } else {
        this.showToast(data.message || 'Gönderim hatası oluştu.', 'error');
      }
    })
    .catch(err => {
      btnSend.disabled = false;
      btnSend.innerHTML = `<span>Gönder</span>`;
      this.showToast('Sunucu bağlantı hatası oluştu.', 'error');
    });
  },

  // Rich Text Editor Toolbar Commands
  initRichEditor() {
    document.querySelectorAll('.tool-btn[data-cmd]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const cmd = btn.getAttribute('data-cmd');
        const val = btn.getAttribute('data-val') || null;

        if (cmd === 'createLink') {
          const url = prompt('Bağlantı adresi (URL):', 'https://');
          if (url) document.execCommand(cmd, false, url);
        } else {
          document.execCommand(cmd, false, val);
        }
      });
    });
  },

  // Contacts Autocomplete for Compose
  initComposeAutoSuggest() {
    const toInput = document.getElementById('compose-to');
    if (!toInput) return;

    let timeout = null;
    const suggestBox = document.createElement('div');
    suggestBox.className = 'suggest-box';
    suggestBox.style.cssText = 'position:absolute;background:var(--bg-surface-elevated);border:1px solid var(--border-color);border-radius:var(--radius-sm);z-index:100;max-height:180px;overflow-y:auto;display:none;width:350px;box-shadow:var(--shadow-lg);';
    toInput.parentNode.appendChild(suggestBox);

    toInput.addEventListener('input', (e) => {
      clearTimeout(timeout);
      const val = e.target.value;
      const lastToken = val.split(',').pop().trim();

      if (lastToken.length < 2) {
        suggestBox.style.display = 'none';
        return;
      }

      timeout = setTimeout(() => {
        fetch(`${this.baseUrl}contacts/autocomplete?q=${encodeURIComponent(lastToken)}`)
          .then(res => res.json())
          .then(items => {
            if (items && items.length > 0) {
              suggestBox.innerHTML = items.map(c => `
                <div class="suggest-item" style="padding:0.5rem 0.75rem;cursor:pointer;border-bottom:1px solid var(--border-color);font-size:0.85rem;" data-email="${c.email}">
                  <strong>${c.first_name} ${c.last_name || ''}</strong> &lt;${c.email}&gt;
                </div>
              `).join('');
              suggestBox.style.display = 'block';

              suggestBox.querySelectorAll('.suggest-item').forEach(item => {
                item.addEventListener('click', () => {
                  const email = item.getAttribute('data-email');
                  const currentTokens = toInput.value.split(',');
                  currentTokens.pop();
                  currentTokens.push(email);
                  toInput.value = currentTokens.join(', ') + ', ';
                  suggestBox.style.display = 'none';
                  toInput.focus();
                });
              });
            } else {
              suggestBox.style.display = 'none';
            }
          });
      }, 250);
    });

    document.addEventListener('click', (e) => {
      if (!toInput.contains(e.target) && !suggestBox.contains(e.target)) {
        suggestBox.style.display = 'none';
      }
    });
  },

  // Global Keybindings & Listeners
  bindGlobalEvents() {
    // ESC closes compose modal
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeCompose();
      }
    });

    // File input change indicator
    const fileInput = document.getElementById('compose-attachments');
    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
          const newFiles = Array.from(e.target.files);
          this.uploadedFiles = (this.uploadedFiles || []).concat(newFiles);
          if (window.DataTransfer) {
            const dt = new DataTransfer();
            this.uploadedFiles.forEach(f => dt.items.add(f));
            fileInput.files = dt.files;
          }
          this.renderComposeAttachments();
        }
      });
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  Webmail.init();
});
