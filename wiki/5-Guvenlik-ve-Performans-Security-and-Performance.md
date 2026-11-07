# 🛡️ 5. Güvenlik ve Performans / Security & Performance

<p>
  <a href="#-türkçe-güvenlik-ve-performans-standartları">🇹🇷 Türkçe</a> •
  <a href="#-english-security-and-performance-standards">🇬🇧 English</a>
</p>

---

# 🇹🇷 Türkçe Güvenlik ve Performans Standartları

## 1. Güvenlik Katmanları
1. **CSRF (Siteler Arası İstek Sahteciliği) Koruması:**
   - Tüm durum değiştiren `POST` istekleri oturum bazlı kriptografik `X-CSRF-Token` başlığı veya `csrf_token` parametresi ile doğrulanır (`hash_equals`).
2. **E-Posta HTML/XSS Temizliği & CSS İzolasyonu:**
   - Gelen e-postalardaki `<script>`, `<iframe>`, `<object>`, `onload/onerror` olayları ve `javascript:` bağlantıları render edilmeden önce temizlenir.
   - E-posta içindeki `<style>` blokları otomatik olarak `.email-isolated-content` kapsayıcısına sınırlandırılır (`scoping`); böylece dışarıdan gelen hiçbir e-posta webmail arayüzünün tasarımını bozamaz.
3. **SSRF (Sunucu Taraflı İstek Sahteciliği) Koruması:**
   - Harici IMAP taşıma ve HestiaCP API bağlantılarında loopback (`127.0.0.1`), yerel ağ (`10.0.0.0/8`, `192.168.0.0/16`) ve bulut metadata (`169.254.169.254`) IP adresleri engellenir.
4. **Güvenli Dosya Yükleme & `.htaccess` Koruması:**
   - Yüklenen dosyalar rastgele güvenli isimlerle (`stored_name`) kaydedilir; çalıştırılabilir uzantılar (`.php`, `.phtml`, `.phar`, `.sh`, `.exe`) reddedilir ve `uploads/.htaccess` içinde PHP motoru tamamen kapatılmıştır.
5. **SQL Injection Koruması:**
   - Tüm veritabanı sorguları `PDO` hazırlanmış ifadeleri (`Prepared Statements`) kullanır.

---

## 2. Performans Optimizasyonları
- **Oturum Kilidi Serbest Bırakma (`session_write_close()`):**
  - Uzun süren IMAP veya HestiaCP ağ işlemleri başlamadan önce PHP oturum kilidi serbest bırakılır; böylece kullanıcı aynı anda klasör değiştirebilir veya başka bir sekmede işlem yapabilir.
- **Akıllı İstemci ve Sunucu Önbelleklemesi:**
  - Klasör sayaçları, mesaj listeleri ve ek dosya durumları önbelleklenerek tekrarlı IMAP bağlantıları en aza indirilir.
- **Arka Plan İşlemleri (`fastcgi_finish_request`):**
  - Takvim daveti yanıtları (`Accept / Decline`) gibi işlemlerde kullanıcıya yanıt anında döndürülür, SMTP bildirim e-postası arka planda gönderilir.

---
---

# 🇬🇧 English Security and Performance Standards

## 1. Security Architecture
- **CSRF Verification:** Every state-changing `POST` request is validated against a cryptographic session token using timing-safe `hash_equals`.
- **XSS Sanitization & Email CSS Isolation:** Malicious scripts and event handlers are stripped from HTML emails, and embedded `<style>` rules are automatically scoped to `.email-isolated-content`.
- **SSRF & Upload Hardening:** Private/loopback IP ranges are blocked on external connections, executable file extensions are rejected, and PHP execution inside `uploads/` is disabled via `.htaccess`.
- **Prepared Statements:** 100% of database queries use parameterized `PDO` prepared statements.

## 2. Performance Optimizations
- **Early Session Unlock (`session_write_close()`):** Prevents concurrent AJAX requests from blocking each other during IMAP/SMTP network calls.
- **Non-Blocking Responses:** Meeting invite RSVPs flush the JSON response immediately to the browser before sending background SMTP notifications.

