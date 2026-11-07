<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!-- Floating Compose Modal -->
<div class="compose-overlay" id="compose-modal">
  <div class="compose-window">
    <div class="compose-header">
      <div class="compose-title" data-i18n="compose_new">Yeni İleti</div>
      <button class="btn-icon" onclick="Webmail.closeCompose()" title="Kapat">&times;</button>
    </div>

    <form id="compose-form" onsubmit="Webmail.sendEmail(event)" enctype="multipart/form-data">
      <div class="compose-fields">
        <div class="compose-input-row" style="position:relative;">
          <span class="compose-label" data-i18n="compose_to_lbl">Kime:</span>
          <input type="text" id="compose-to" name="to" class="compose-input" placeholder="alıcı@alanadiniz.com (virgülle ayırın)" autocomplete="off" required>
          <div style="display:flex;gap:0.4rem;">
            <button type="button" class="chip" style="padding:2px 8px;font-size:0.7rem;" onclick="document.getElementById('cc-row').style.display = 'flex'; this.style.display='none';">Cc</button>
            <button type="button" class="chip" style="padding:2px 8px;font-size:0.7rem;" onclick="document.getElementById('bcc-row').style.display = 'flex'; this.style.display='none';">Bcc</button>
          </div>
        </div>

        <div class="compose-input-row" id="cc-row" style="display:none;">
          <span class="compose-label" data-i18n="compose_cc_lbl">Cc:</span>
          <input type="text" id="compose-cc" name="cc" class="compose-input" placeholder="Bilgi (Cc)">
        </div>

        <div class="compose-input-row" id="bcc-row" style="display:none;">
          <span class="compose-label" data-i18n="compose_bcc_lbl">Bcc:</span>
          <input type="text" id="compose-bcc" name="bcc" class="compose-input" placeholder="Gizli (Bcc)">
        </div>

        <div class="compose-input-row">
          <span class="compose-label" data-i18n="compose_subj_lbl">Konu:</span>
          <input type="text" id="compose-subject" name="subject" class="compose-input" placeholder="E-posta konusu" required>
        </div>
      </div>

      <!-- Rich Text Editor Toolbar -->
      <div class="editor-toolbar">
        <button type="button" class="tool-btn" data-cmd="bold" title="Kalın (Ctrl+B)"><strong>B</strong></button>
        <button type="button" class="tool-btn" data-cmd="italic" title="İtalik (Ctrl+I)"><em>I</em></button>
        <button type="button" class="tool-btn" data-cmd="underline" title="Altı Çizili (Ctrl+U)"><u>U</u></button>
        <button type="button" class="tool-btn" data-cmd="strikeThrough" title="Üstü Çizili"><s>S</s></button>
        <div class="tool-divider"></div>

        <button type="button" class="tool-btn" data-cmd="insertUnorderedList" title="Madde İşaretli Liste">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
        <button type="button" class="tool-btn" data-cmd="insertOrderedList" title="Numaralı Liste">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
        </button>
        <div class="tool-divider"></div>

        <button type="button" class="tool-btn" data-cmd="createLink" title="Bağlantı Ekle">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </button>
        <button type="button" class="tool-btn" data-cmd="formatBlock" data-val="blockquote" title="Alıntı Yap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </button>
        <button type="button" class="tool-btn" data-cmd="removeFormat" title="Biçimlendirmeyi Temizle">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Rich Editor Body -->
      <div id="compose-editor" class="compose-editor" contenteditable="true" placeholder="İletinizi buraya yazın..." data-i18n-placeholder="compose_placeholder"></div>

      <!-- Selected Attachments List -->
      <div class="compose-attachments-bar" id="compose-file-list"></div>

      <!-- Hidden File Input -->
      <input type="file" id="compose-attachments" name="attachments[]" multiple style="display:none;">

      <div class="compose-footer">
        <div style="display:flex;align-items:center;gap:0.5rem;">
          <button type="submit" class="btn-send" id="btn-send-mail">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <span data-i18n="compose_send">Gönder</span>
          </button>

          <button type="button" class="btn-icon" title="Dosya Ekle" onclick="const fi = document.getElementById('compose-attachments'); if(fi) fi.value = ''; fi.click();">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
          </button>
        </div>

        <button type="button" class="btn-icon" title="İletiyi Sil" onclick="Webmail.closeCompose()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </button>
      </div>
    </form>
  </div>
</div>
