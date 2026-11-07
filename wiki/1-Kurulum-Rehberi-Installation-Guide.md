# 📖 1. Kurulum Rehberi / Installation Guide

<p>
  <a href="#-türkçe-kurulum-rehberi">🇹🇷 Türkçe Kurulum Rehberi</a> •
  <a href="#-english-installation-guide">🇬🇧 English Installation Guide</a>
</p>

---

# 🇹🇷 Türkçe Kurulum Rehberi

## 1. Sistem Gereksinimleri
Kuruluma başlamadan önce sunucunuzda aşağıdaki bileşenlerin yüklü ve aktif olduğundan emin olun:

| Bileşen | Minimum Sürüm | Önerilen | Açıklama |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.0` | `8.1` veya `8.2` | Hızlı bellek yönetimi ve modern tip güvenliği |
| **MySQL / MariaDB** | MySQL `5.7+` / MariaDB `10.3+` | MySQL `8.0+` / MariaDB `10.6+` | `utf8mb4_unicode_ci` tam emoji ve çok dilli karakter desteği |
| **`php-imap`** | Aktif | Aktif | IMAP posta kutusu okuma, klasör yönetimi ve arama için **zorunlu** |
| **`php-pdo_mysql`** | Aktif | Aktif | Veritabanı bağlantısı için **zorunlu** |
| **`php-mbstring`** | Aktif | Aktif | Türkçe karakter ve `UTF7-IMAP` dönüşümleri için **zorunlu** |
| **`php-curl`** | Aktif | Aktif | HestiaCP Uzak REST API (`Port 8083`) iletişimi için gerekli |
| **`php-openssl`** | Aktif | Aktif | SSL/TLS (993 / 465 / 587) şifreli bağlantılar için gerekli |

Ubuntu/Debian (veya HestiaCP) sunucularda eksik PHP eklentilerini kurmak için:
```bash
sudo apt update
sudo apt install php8.2-imap php8.2-mysql php8.2-mbstring php8.2-curl php8.2-xml
sudo systemctl restart apache2 nginx php8.2-fpm
```

---

## 2. Yöntem A: Otomatik Kurulum Sihirbazı (`wizard.php`) — Önerilen

1. **Dosyaları İndirin:**
   Web sunucunuzun kök dizinine (ör. `/home/admin/web/mail.alanadiniz.com/public_html`) bağlanıp projeyi klonlayın:
   ```bash
   cd /home/admin/web/mail.alanadiniz.com/public_html
   git clone https://github.com/serkanaytas/hestia-webmail-suite.git .
   ```
2. **Dizin İzinlerini Ayarlayın:**
   ```bash
   chmod -R 755 uploads/
   chmod 644 config.php
   ```
3. **Kurulum Sihirbazını Başlatın:**
   Tarayıcınızdan `https://mail.alanadiniz.com/wizard.php` adresini ziyaret edin:
   - **Adım 1 (Sistem Denetimi):** PHP sürümü ve eklentiler otomatik denetlenir.
   - **Adım 2 (Veritabanı Kurulumu):** MySQL sunucu (`localhost`), kullanıcı adı, şifre ve veritabanı adını (`hestia_webmail`) girin. Sihirbaz 20 tablonun tamamını otomatik oluşturur.
   - **Adım 3 (Kurumsal Domain & İlk Yönetici):**
     - Hizmet verilecek kurumsal domaini (örn. `sirketiniz.com`) girin.
     - IMAP (`mail.sirketiniz.com:993` SSL) ve SMTP (`mail.sirketiniz.com:587` TLS) ayarlarını doğrulayın.
     - İlk **Süper Yönetici (Admin)** e-posta hesabını ve şifresini girin.
   - **Adım 4 (Tamamlandı):** `config.php` otomatik oluşturulur ve giriş ekranına yönlendirilirsiniz.

---

## 3. Yöntem B: Manuel Kurulum (`config.php` & `database_setup.sql`)

1. **Veritabanını Oluşturun ve Şemayı Yükleyin:**
   ```bash
   mysql -u root -p -e "CREATE DATABASE hestia_webmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p hestia_webmail < database_setup.sql
   ```
2. **Konfigürasyon Dosyasını Hazırlayın:**
   `config.example.php` dosyasını `config.php` (veya Git güncellemelerinden etkilenmemesi için `config.local.php`) olarak kopyalayın:
   ```bash
   cp config.example.php config.local.php
   ```
   Dosya içindeki veritabanı ve domain bilgilerini kendi sunucunuza göre düzenleyin.
3. **Görsel Config Oluşturucu (Alternatif):**
   Tarayıcıdan `https://mail.alanadiniz.com/wizard.php?manual=1` adresini açarak veritabanı bağlantınızı canlı test edebilir ve üretilen `config.php` dosyasını tek tıkla indirebilirsiniz.

---
---

# 🇬🇧 English Installation Guide

## 1. System Requirements
Ensure your server meets the following requirements before installing:

- **PHP:** `8.0+` (`8.1` or `8.2` recommended)
- **Database:** MySQL `5.7+` / `8.0+` or MariaDB `10.3+`
- **PHP Extensions:** `php-imap`, `php-pdo_mysql`, `php-mbstring`, `php-curl`, `php-openssl`

---

## 2. Method A: Interactive Setup Wizard (`wizard.php`) — Recommended

1. **Clone the Repository:**
   ```bash
   cd /var/www/html
   git clone https://github.com/serkanaytas/hestia-webmail-suite.git .
   ```
2. **Set File Permissions:**
   ```bash
   chmod -R 755 uploads/
   chmod 644 config.php
   ```
3. **Run the Setup Wizard:**
   Open `https://mail.yourdomain.com/wizard.php` in your browser and complete the 4 guided steps (Environment Check &rarr; Database Initialization &rarr; Domain, IMAP/SMTP & Super Admin Registration &rarr; Finish).

---

## 3. Method B: Manual Setup (`config.php` & `database_setup.sql`)

1. **Import Database Schema:**
   ```bash
   mysql -u root -p -e "CREATE DATABASE hestia_webmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p hestia_webmail < database_setup.sql
   ```
2. **Configure Credentials:**
   Copy `config.example.php` to `config.local.php` (or edit `config.php` directly) and set `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `MAIL_DOMAIN`, `MAIL_IMAP_HOST`, and `MAIL_SMTP_HOST`.
