# 🗄️ 4. Veritabanı ve Sistem Mimarisi / Database & System Architecture

<p>
  <a href="#-türkçe-mimari-dokümantasyon">🇹🇷 Türkçe Mimari Dokümantasyon</a> •
  <a href="#-english-architecture-documentation">🇬🇧 English Architecture Documentation</a>
</p>

---

# 🇹🇷 Türkçe Mimari Dokümantasyon

## 1. Dizin ve Dosya Yapısı
```text
├── api.php                 # Merkezi JSON API motoru (IMAP, SMTP, Rehber, Takvim, Drive, Chat, HestiaCP)
├── index.php               # Ana uygulama arayüzü (SPA mimarisi, HTML5/CSS3 şablonları)
├── wizard.php              # 4 adımlı interaktif kurulum sihirbazı & Manuel Config Oluşturucu
├── worker_import.php       # Harici IMAP posta kutularını arka planda taşıyan CLI işçisi
├── config.php              # Merkezi yapılandırma dosyası (config.local.php destekli)
├── config.example.php      # Örnek yapılandırma şablonu
├── database_setup.sql      # 20 tabloluk tam MySQL / MariaDB veritabanı şeması
├── assets/
│   ├── css/webmail.css     # Keskin köşeli kurumsal arayüz stilleri & karanlık/açık tema
│   ├── js/portal-app.js    # İstemci tarafı uygulama mantığı, önbellekleme ve olay yöneticisi
│   └── js/i18n.js          # Çoklu dil motoru (TR / EN / DE)
├── languages/              # Dil paketleri (tr.json, en.json, de.json)
└── uploads/                # Güvenli (.htaccess korumalı) kullanıcı Drive ve Chat yüklemeleri
```

---

## 2. Veritabanı Tabloları (`database_setup.sql` — 20 Tablo)

| # | Tablo Adı | Görevi / Açıklaması |
| :--- | :--- | :--- |
| 1 | `mail_accounts` | Sisteme giriş yapan e-posta hesapları, IMAP/SMTP bilgileri, imza ve yönetici (`is_admin`) rolleri |
| 2 | `domain_directory` | Aynı kurumsal domaindeki tüm e-posta hesaplarının otomatik keşfedilen global rehberi |
| 3 | `contacts` | Kullanıcıların kişisel adres defteri kayıtları, grupları ve favorileri |
| 4 | `calendar_events` | Kişisel ve domain içi paylaşımlı takvim etkinlikleri ve toplantılar |
| 5 | `mail_folders` | Kullanıcıların oluşturduğu özel IMAP klasörleri, ikon ve renk tanımları |
| 6 | `mail_filters` | Gelen e-postalar için otomatik filtreleme ve klasöre taşıma kuralları |
| 7 | `mail_drafts` | Otomatik ve manuel kaydedilen e-posta taslakları |
| 8 | `mail_imports` | `worker_import.php` tarafından yürütülen harici IMAP e-posta aktarım görevleri |
| 9 | `drive_folders` | Bulut Sürücü (Cloud Drive) klasör hiyerarşisi |
| 10 | `drive_files` | Bulut Sürücüye yüklenen dosyaların meta verileri ve boyutları |
| 11 | `user_notes` | Renk kodlu ve sabitlenebilir kişisel notlar |
| 12 | `user_tasks` | Öncelik ve son teslim tarihli görev kayıtları |
| 13 | `chat_conversations` | Domain içi 1'e 1 (`direct`) ve grup (`group`) sohbet odaları |
| 14 | `chat_messages` | Sohbet mesajları, dosya ekleri, sabitlemeler (`is_pinned`) ve emoji tepkileri |
| 15 | `chat_participants` | Sohbet odası katılımcıları ve okunmamış mesaj sayaçları |
| 16 | `chat_presence` | Kullanıcıların anlık çevrimiçi durumu (`online`, `busy`, `away`) ve *"yazıyor..."* bilgisi |
| 17 | `chat_hidden_colleagues` | Sohbet listesinden gizlenen kullanıcılar |
| 18 | `chat_removed_members` | Grup sohbetlerinden çıkarılan üyeler |
| 19 | `chat_settings` | Domain içi sohbet genel ayarları |
| 20 | `mail_settings` | Portal genel ayarları (Marka başlığı, tema, çöp/spam temizleme süreleri, HestiaCP ayarları) |

---
---

# 🇬🇧 English Architecture Documentation

## 1. Project File Structure
- **`api.php`**: Standalone JSON API handling IMAP/SMTP operations, Contacts, Calendar, Cloud Drive, Tasks, Notes, Internal Domain Chat, and HestiaCP administration.
- **`index.php` & `assets/js/portal-app.js`**: Single-Page Application (SPA) frontend with client-side folder/message caching and instant UI transitions.
- **`wizard.php`**: Interactive 4-step installer and manual configuration generator.
- **`worker_import.php`**: Background PHP CLI daemon for migrating external IMAP mailboxes without HTTP timeouts.
- **`database_setup.sql`**: Complete 20-table UTF8MB4 database schema.

