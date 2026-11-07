# Hestia Webmail & Kurumsal Portal Suite (Enterprise Webmail & Collaboration Portal)

<p align="center">
  <strong>Modern, Hızlı ve Güvenli Kurumsal Webmail, Takvim, Bulut Sürücü, Görev Yönetimi, Domain İçi Anlık Sohbet ve HestiaCP Yönetim Portalı</strong><br>
  <em>A Modern, High-Performance & Secure Corporate Webmail, Calendar, Cloud Drive, Task Manager, Internal Domain Chat & HestiaCP Administration Suite</em>
</p>

<p align="center">
  <a href="#-türkçe-dokümantasyon">🇹🇷 Türkçe Dokümantasyon</a> •
  <a href="#-english-documentation">🇬🇧 English Documentation</a> •
  <a href="wiki/Home.md">📚 Wiki Sayfaları / Wiki Pages</a>
</p>

---

## 📚 Wiki Dokümantasyon Sayfaları / Official Wiki Pages

| # | 🇹🇷 Türkçe Wiki Sayfası | 🇬🇧 English Wiki Page |
| :--- | :--- | :--- |
| **🏠** | [Wiki Ana Sayfa ve Mimari Şema](wiki/Home.md) | [Wiki Home & Architecture Diagram](wiki/Home.md) |
| **01** | [Kurulum Rehberi (Sihirbaz & Manuel)](wiki/1-Kurulum-Rehberi-Installation-Guide.md) | [Installation Guide (Wizard & Manual)](wiki/1-Kurulum-Rehberi-Installation-Guide.md) |
| **02** | [HestiaCP Sunucu Entegrasyonu (API & CLI)](wiki/2-HestiaCP-Entegrasyonu-HestiaCP-Integration.md) | [HestiaCP Server Integration (API & CLI)](wiki/2-HestiaCP-Entegrasyonu-HestiaCP-Integration.md) |
| **03** | [Modüller ve Detaylı Kullanım Kılavuzu](wiki/3-Moduller-ve-Kullanim-Modules-and-Usage.md) | [Modules & Detailed User Guide](wiki/3-Moduller-ve-Kullanim-Modules-and-Usage.md) |
| **04** | [Veritabanı Şeması (20 Tablo) ve Mimari](wiki/4-Veritabani-ve-Mimari-Database-and-Architecture.md) | [Database Schema (20 Tables) & Architecture](wiki/4-Veritabani-ve-Mimari-Database-and-Architecture.md) |
| **05** | [Güvenlik Mimarisi ve Performans](wiki/5-Guvenlik-ve-Performans-Security-and-Performance.md) | [Security Architecture & Performance](wiki/5-Guvenlik-ve-Performans-Security-and-Performance.md) |
| **06** | [SSS ve Sorun Giderme Kılavuzu](wiki/6-SSS-ve-Sorun-Giderme-FAQ-and-Troubleshooting.md) | [FAQ & Troubleshooting Guide](wiki/6-SSS-ve-Sorun-Giderme-FAQ-and-Troubleshooting.md) |

---

# 🇹🇷 Türkçe Dokümantasyon

## 📖 Proje Hakkında
**Hestia Webmail & Kurumsal Portal Suite**, standart IMAP/SMTP posta sunucuları ve özellikle **HestiaCP** kontrol paneli kullanan kurumlar için geliştirilmiş; tek bir arayüzde **E-Posta İstemcisi**, **Kurumsal Adres Defteri**, **Paylaşımlı Takvim**, **Bulut Dosya Sürücüsü (Drive)**, **Görev & Not Yönetimi**, **Domain İçi Anlık Mesajlaşma (Chat)** ve **HestiaCP E-Posta Yönetim Panelini** birleştiren tam kapsamlı bir kurumsal çalışma platformudur.

---

## ✨ Temel Özellikler

### 1. 📬 Gelişmiş E-Posta İstemcisi (IMAP / SMTP)
- **Tam Klasör Yönetimi:** Gelen Kutusu, Gönderilenler, Taslaklar, İstenmeyen (Spam), Çöp Kutusu ve sınırsız **Özel IMAP Klasörü** oluşturma, yeniden adlandırma, renklendirme ve silme (Türkçe/UTF-8 karakterler için tam `UTF7-IMAP` uyumluluğu).
- **Toplu İşlemler & Klasöre Taşıma:**
  - **Görünen Mailleri Seç** veya **Tümünü Seç (Klasördeki Tüm Mailler)** seçenekleriyle yüzlerce e-postayı tek tıkla seçebilme.
  - Üst araç çubuğundan veya e-posta okuma ekranından **Klasöre Taşı Dropdown** menüsüyle anında klasör değiştirme.
- **Akıllı Arama & Gelişmiş Filtreleme:**
  - Mevcut klasörde veya **Tüm Klasörlerde** eşzamanlı arama.
  - Gönderen, Alıcı, Konu, İçerik, Tarih Aralığı ve Ek Dosya durumuna göre gelişmiş arama.
- **Otomatik Gelen Posta Kuralları (Mail Filters):** Gönderen, alıcı veya konuya göre gelen e-postaları otomatik olarak belirlenen klasöre taşıma, okundu işaretleme veya silme.
- **Zengin Metin Editörü & İmza Şablonları:** Hazır kurumsal HTML imza şablonları, satır içi (inline/CID) görsel desteği, sürükle-bırak dosya ekleme ve **ekleriyle birlikte e-posta iletme (Forward with attachments)**.
- **E-Posta CSS İzolasyonu:** Gelen HTML e-postaların içindeki bozuk veya genel CSS stillerinin (`body`, `*`, `.btn` vb.) webmail arayüzünü bozmasını engelleyen otomatik CSS kapsamlandırma (scoping) motoru.
- **Toplantı Davetleri (`.ics` / `text/calendar`):** Gelen toplantı davetlerini doğrudan e-posta içinden **Kabul Et / Belki / Reddet** butonlarıyla yanıtlama ve kurumsal takvime otomatik işleme.

### 2. 👥 Akıllı Adres Defteri & Kurumsal Rehber
- **Kişisel & Kurumsal Rehber:** Kişileri gruplandırma, favorilere ekleme, detaylı düzenleme, CSV/vCard içe ve dışa aktarma.
- **Gönderilen Alıcıları Otomatik Kaydetme:** E-posta gönderdiğiniz alıcılar (`To`, `CC`, `BCC`) rehberinizde yoksa otomatik olarak `E-Posta Kişileri` grubuna eklenir (gelen spam/bülten adresleri rehberi kirletmez).
- **Domain İçi Otomatik Keşif:** Aynı domain üzerindeki tüm kurumsal hesapları otomatik senkronize eder ve e-posta yazarken otomatik tamamlama (`Select2`) sunar.

### 3. 📅 Paylaşımlı Kurumsal Takvim
- Aylık, haftalık ve liste görünümleri; kategori ve renk kodlu etkinlikler.
- Domain içi paylaşımlı etkinlikler ve yaklaşan toplantı hatırlatıcıları.
- `.ics` formatında dışa aktarma desteği.

### 4. ☁️ Bulut Sürücü (Cloud Drive), 📝 Notlar ve ✅ Görevler
- **Bulut Sürücü:** Kullanıcı bazlı özel klasörleme, güvenli dosya yükleme/indirme ve kota takibi.
- **Görev Yönetimi:** Öncelik seviyeli (`Düşük`, `Orta`, `Yüksek`), son teslim tarihli görev listesi.
- **Notlar:** Renkli, sabitlenebilir (pin) zengin metinli kişisel not kartları.

### 5. 💬 Domain İçi Anlık Mesajlaşma (Kurumsal Chat)
- Aynı kurumsal domaindeki çalışma arkadaşlarıyla **1'e 1 Özel Mesajlaşma** ve **Grup Sohbetleri**.
- Çevrimiçi / Meşgul / Uzakta durum göstergeleri, *"yazıyor..."* bildirimi, mesaj sabitleme, emoji tepkileri, dosya/görsel paylaşımı ve sesli bildirimler.

### 6. 🔄 Harici E-Posta Taşıma Sihirbazı (IMAP Migration Worker)
- Gmail, Yandex, Outlook, cPanel veya başka bir IMAP sunucusundaki eski e-postaları arka planda çalışan CLI işçisi (`worker_import.php`) ile mevcut posta kutusuna kesintisiz ve mükerrer kontrolü yaparak aktarma.

### 7. ⚙️ HestiaCP Yönetim Paneli (Admin Panel)
- Webmail arayüzünden çıkmadan HestiaCP üzerindeki e-posta hesaplarını listeleme, **yeni e-posta hesabı açma**, **şifre sıfırlama**, **kota (MB/Sınırsız) belirleme**, **takma ad (alias)**, **yönlendirme (forwarder)** ve **otomatik yanıtlayıcı (autoreply)** yönetimi.
- İki farklı çalışma modu:
  1. **Uzak Sunucu Modu (`remote`):** Port `8083` üzerinden HestiaCP REST API (`Access Key ID` + `Secret Key`) ile iletişim.
  2. **Aynı Sunucu / Yerel CLI Modu (`local`):** Webmail HestiaCP ile aynı Linux sunucuda çalışıyorsa `/usr/local/hestia/bin/v-*` komutlarıyla port veya API anahtarına ihtiyaç duymadan doğrudan çalışma.

---

## 🛠️ Sistem Gereksinimleri

- **PHP:** `8.0` veya üzeri (PHP `8.1` / `8.2` önerilir)
- **Gerekli PHP Eklentileri:**
  - `php-imap` (Posta kutusu bağlantıları için zorunlu)
  - `php-pdo` & `php-mysql` (Veritabanı işlemleri için zorunlu)
  - `php-mbstring` (Türkçe karakter ve UTF7-IMAP dönüşümleri için zorunlu)
  - `php-curl` (HestiaCP REST API bağlantısı için)
  - `php-openssl` (SSL/TLS IMAP & SMTP bağlantıları için)
- **Veritabanı:** MySQL `5.7+` / `8.0+` veya MariaDB `10.3+`
- **Web Sunucusu:** Apache (`mod_rewrite` aktif) veya Nginx

---

## 🚀 Kurulum Rehberi

Projeyi iki farklı yöntemle kurabilirsiniz: **Otomatik Kurulum Sihirbazı (Önerilen)** veya **Manuel Kurulum**.

### Yöntem 1: Otomatik Kurulum Sihirbazı (`wizard.php`) ile Kurulum (Önerilen)

1. Proje dosyalarını sunucunuzun web dizinine (örn. `/var/www/html`, `/home/user/web/mail.alanadiniz.com/public_html` veya `htdocs`) yükleyin:
   ```bash
   git clone https://github.com/serkanaytas/hestia-webmail-suite.git .
   ```
2. `uploads/` dizinine ve `config.php` dosyasına yazma izni verin:
   ```bash
   chmod -R 755 uploads/
   chmod 644 config.php
   ```
3. Tarayıcınızdan kurulum sihirbazını açın:
   ```text
   https://mail.alanadiniz.com/wizard.php
   ```
4. Sihirbazdaki 4 adımı takip edin:
   - **Adım 1:** Sunucu gereksinimleri (`imap`, `pdo_mysql`, `mbstring`, `curl`, `openssl`) otomatik kontrol edilir.
   - **Adım 2:** MySQL/MariaDB veritabanı bilgilerinizi girin. Sihirbaz veritabanını ve tüm tabloları otomatik oluşturur.
   - **Adım 3:** Kurumsal alan adınızı (`alanadiniz.com`), IMAP/SMTP sunucu adreslerinizi (`mail.alanadiniz.com`) ve ilk **Süper Yönetici (Admin)** e-posta hesabınızı tanımlayın.
   - **Adım 4:** Kurulum tamamlanır ve `config.php` otomatik olarak kaydedilir.

---

### Yöntem 2: Manuel Kurulum (`config.php` & `database_setup.sql`)

Paylaşımlı hosting veya kısıtlı dosya yazma izni olan sunucularda manuel kurulum yapabilirsiniz:

1. **Veritabanını İçe Aktarın:**
   - MySQL/MariaDB üzerinde yeni bir veritabanı oluşturun (örn. `hestia_webmail`).
   - Ana dizindeki [`database_setup.sql`](database_setup.sql) dosyasını phpMyAdmin veya komut satırı üzerinden içe aktarın:
     ```bash
     mysql -u root -p hestia_webmail < database_setup.sql
     ```
2. **Konfigürasyon Dosyasını Düzenleyin:**
   - [`config.example.php`](config.example.php) dosyasını `config.php` (veya Git güncellemelerinden etkilenmemesi için `config.local.php`) olarak kopyalayın ve kendi sunucu bilgilerinizi girin:
     ```php
     defined('DB_HOST') or define('DB_HOST', 'localhost');
     defined('DB_USER') or define('DB_USER', 'veritabani_kullanicisi');
     defined('DB_PASS') or define('DB_PASS', 'veritabani_sifresi');
     defined('DB_NAME') or define('DB_NAME', 'hestia_webmail');

     defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', 'alanadiniz.com');
     defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', 'mail.alanadiniz.com');
     defined('MAIL_IMAP_PORT') or define('MAIL_IMAP_PORT', 993);
     defined('MAIL_IMAP_ENCRYPTION') or define('MAIL_IMAP_ENCRYPTION', 'ssl');

     defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', 'mail.alanadiniz.com');
     defined('MAIL_SMTP_PORT') or define('MAIL_SMTP_PORT', 587);
     defined('MAIL_SMTP_ENCRYPTION') or define('MAIL_SMTP_ENCRYPTION', 'tls');
     ```
   - *İpucu:* Dilerseniz `wizard.php?manual=1` adresini açarak **Manuel Config Oluşturucu** ekranından `config.php` dosyanızı görsel olarak hazırlayıp indirebilirsiniz.

---

## 🔗 HestiaCP Entegrasyonu Yapılandırması

Yönetim Paneli üzerinden yeni e-posta hesabı açma, kota değiştirme, yönlendirme ve şifre sıfırlama işlemlerini kullanmak için iki moddan birini seçebilirsiniz:

### Mod A: Uzak Sunucu / REST API Modu (`remote`)
Webmail farklı bir sunucuda çalışıyorsa veya standart API kullanmak istiyorsanız:
1. HestiaCP panelinize (`https://mail.alanadiniz.com:8083`) giriş yapın.
2. **Users &rarr; admin &rarr; Access Keys** bölümünden yeni bir erişim anahtarı oluşturun.
3. Webmail içinde **Yönetim Paneli &rarr; Sunucu Ayarları** penceresini açıp:
   - **Çalışma Modu:** `Uzak Sunucu (REST API - Port 8083)`
   - **Sunucu Adresi:** `https://mail.alanadiniz.com:8083`
   - **Yetkili Kullanıcı:** `admin`
   - **Access Key ID** ve **Secret Key** alanlarını doldurup kaydedin.

### Mod B: Aynı Sunucu / Yerel CLI Modu (`local`)
Webmail doğrudan HestiaCP kurulu Linux sunucuda çalışıyorsa Port `8083` veya API anahtarına gerek kalmadan yerel CLI komutlarını kullanabilir:
1. Web sunucusu kullanıcısına (`www-data` veya site kullanıcısı) HestiaCP komutları için şifresiz sudo yetkisi tanımlayın:
   ```bash
   echo 'www-data ALL=(ALL) NOPASSWD: /usr/local/hestia/bin/*' | sudo tee /etc/sudoers.d/hestia-webmail
   sudo chmod 440 /etc/sudoers.d/hestia-webmail
   ```
2. Webmail **Yönetim Paneli &rarr; Sunucu Ayarları** bölümünden **Aynı Sunucu / Yerel CLI (`local`)** modunu seçip kaydedin.

---

## 🔒 Güvenlik Mimarisi

- **CSRF Koruması:** Tüm durum değiştiren (`POST`) API istekleri oturum bazlı `X-CSRF-Token` doğrulamasından geçer.
- **XSS & CSS İzolasyonu:** E-posta gövdelerindeki zararlı betikler (`<script>`, `onerror`, `javascript:` vb.) temizlenir; e-posta içi stiller yalnızca `.email-isolated-content` kapsayıcısına sınırlandırılır.
- **SSRF & Dizin Gezinme (Path Traversal) Koruması:** Harici IMAP taşıma ve HestiaCP API bağlantılarında yerel ağ/loopback (`127.0.0.1`, `169.254.x.x`, `10.x.x.x`) adresleri filtrelenir; dosya yüklemelerinde uzantı/MIME doğrulaması ve `.htaccess` ile PHP çalıştırma engeli (`uploads/.htaccess`) uygulanır.
- **Oturum Sertleştirme:** `HttpOnly`, `SameSite=Lax` ve `use_strict_mode` çerez politikaları aktiftir.

---
---

# 🇬🇧 English Documentation

## 📖 Overview
**Hestia Webmail & Enterprise Portal Suite** is a full-featured, self-hosted collaboration and webmail platform built for standard IMAP/SMTP mail servers and **HestiaCP** environments. It unifies an **Enterprise Email Client**, **Smart Address Book**, **Shared Corporate Calendar**, **Cloud Drive**, **Tasks & Notes**, **Internal Domain Chat**, and a **HestiaCP Mail Administration Panel** into a single, fast interface.

---

## ✨ Key Features

1. **📬 Full-Featured IMAP/SMTP Webmail:**
   - System folders (`INBOX`, `Sent`, `Drafts`, `Junk/Spam`, `Trash`) plus unlimited **Custom IMAP Folders** with full `UTF7-IMAP` international character encoding support.
   - **Bulk Selection & Move Dropdown:** Select visible emails or **Select All in Folder** (across all pages) and move/delete/mark them in bulk.
   - **Multi-Folder Search & Incoming Mail Filters:** Search across a single folder or all folders simultaneously; create automated rules to move, mark, or delete incoming messages.
   - **Rich HTML Composer & Signature Templates:** Drag-and-drop attachments, inline CID images, corporate signature templates, and **forward emails with original attachments intact**.
   - **Strict Email CSS Isolation:** Automatically scopes incoming HTML email stylesheets so third-party newsletters never break the webmail UI layout.
   - **Calendar Invites (`.ics`):** Accept, Tentatively Accept, or Decline meeting invitations directly inside the email reader.

2. **👥 Smart Address Book & Corporate Directory:**
   - Group management, favorites, contact editing, and CSV import/export.
   - **Auto-Save Sent Recipients:** Automatically adds recipients you send emails to (`To`, `CC`, `BCC`) into your address book while ignoring incoming spam/newsletters.
   - **Global Domain Directory:** Auto-discovers accounts within the same corporate domain for instant `Select2` recipient autocomplete.

3. **📅 Shared Corporate Calendar:**
   - Monthly, weekly, and agenda views with domain-wide shared events, reminders, and `.ics` export.

4. **☁️ Cloud Drive, 📝 Notes & ✅ Tasks:**
   - Personal cloud file storage with folder hierarchy, color-coded pinned notes, and priority-based task tracking.

5. **💬 Internal Domain Chat:**
   - Real-time 1-on-1 and group messaging exclusively for colleagues on the same domain, featuring online presence, typing indicators, file sharing, pinned messages, and reactions.

6. **🔄 Background IMAP Migration Worker (`worker_import.php`):**
   - Migrate historical emails from Gmail, Outlook, Yandex, or any external IMAP server in the background with duplicate detection and live progress tracking.

7. **⚙️ HestiaCP Mail Administration Panel:**
   - Create, edit, suspend, and delete domain mailboxes, manage quotas, aliases, forwarders, and autoresponders directly from the webmail UI via **Remote REST API (Port 8083)** or **Local CLI (`/usr/local/hestia/bin`)**.

---

## 🛠️ System Requirements

- **PHP:** `8.0+` (`8.1` or `8.2` recommended)
- **Required PHP Extensions:** `imap`, `pdo`, `pdo_mysql`, `mbstring`, `curl`, `openssl`
- **Database:** MySQL `5.7+` / `8.0+` or MariaDB `10.3+`
- **Web Server:** Apache (with `mod_rewrite`) or Nginx

---

## 🚀 Installation Guide

### Option 1: Interactive Setup Wizard (`wizard.php`) — Recommended

1. Clone or upload the repository to your web root:
   ```bash
   git clone https://github.com/serkanaytas/hestia-webmail-suite.git .
   ```
2. Ensure `uploads/` and `config.php` are writable by your web server:
   ```bash
   chmod -R 755 uploads/
   chmod 644 config.php
   ```
3. Open the Setup Wizard in your browser:
   ```text
   https://mail.yourdomain.com/wizard.php
   ```
4. Follow the 4-step wizard to verify PHP extensions, initialize the MySQL database schema, configure your corporate domain & IMAP/SMTP hosts, and register the initial Super Admin account.

### Option 2: Manual Installation (`config.php` & `database_setup.sql`)

1. **Import the Database Schema:**
   Create a MySQL/MariaDB database and import [`database_setup.sql`](database_setup.sql):
   ```bash
   mysql -u root -p hestia_webmail < database_setup.sql
   ```
2. **Configure `config.php` (or `config.local.php`):**
   Copy [`config.example.php`](config.example.php) to `config.php` (or `config.local.php`, which is git-ignored) and update your credentials:
   ```php
   defined('DB_HOST') or define('DB_HOST', 'localhost');
   defined('DB_USER') or define('DB_USER', 'your_db_user');
   defined('DB_PASS') or define('DB_PASS', 'your_db_password');
   defined('DB_NAME') or define('DB_NAME', 'hestia_webmail');

   defined('MAIL_DOMAIN') or define('MAIL_DOMAIN', 'yourdomain.com');
   defined('MAIL_IMAP_HOST') or define('MAIL_IMAP_HOST', 'mail.yourdomain.com');
   defined('MAIL_SMTP_HOST') or define('MAIL_SMTP_HOST', 'mail.yourdomain.com');
   ```

---

## 📄 License / Lisans
MIT License — Copyright (c) [Serkan Aytaş](https://github.com/serkanaytas)

