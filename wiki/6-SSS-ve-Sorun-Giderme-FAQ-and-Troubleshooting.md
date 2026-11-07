# ❓ 6. SSS ve Sorun Giderme / FAQ & Troubleshooting

<p>
  <a href="#-türkçe-sss-ve-sorun-giderme">🇹🇷 Türkçe SSS</a> •
  <a href="#-english-faq-and-troubleshooting">🇬🇧 English FAQ</a>
</p>

---

# 🇹🇷 Türkçe SSS ve Sorun Giderme

### S1: Kurulumdan sonra yerel ayarlarımı Git güncellemelerinden (`git pull`) nasıl korurum?
**Cevap:** Ana dizinde `config.local.php` adında bir dosya oluşturup kendi `DB_NAME`, `DB_USER`, `DB_PASS` ve `MAIL_DOMAIN` sabitlerinizi bu dosyaya yazabilirsiniz. `config.php` bu dosyayı otomatik olarak yükler ve `.gitignore` sayesinde `config.local.php` hiçbir zaman Git tarafından üzerine yazılmaz veya GitHub'a gönderilmez.

---

### S2: HestiaCP Yerel CLI (`local`) modunda *"Şifresiz sudo (NOPASSWD) yetkisi bulunmuyor"* hatası alıyorum. Nasıl çözerim?
**Cevap:** Linux sunucunuzda SSH ile bağlanıp web sunucusu kullanıcısına (`www-data`) `/usr/local/hestia/bin/*` izni tanımlayın:
```bash
echo 'www-data ALL=(ALL) NOPASSWD: /usr/local/hestia/bin/*' | sudo tee /etc/sudoers.d/hestia-webmail
sudo chmod 440 /etc/sudoers.d/hestia-webmail
```

---

### S3: HestiaCP Uzak REST API (`remote`) modunda bağlantı kurulamıyor. Neleri kontrol etmeliyim?
**Cevap:**
1. HestiaCP sunucunuzda `8083` portunun güvenlik duvarında (Firewall) açık olduğundan emin olun.
2. **HestiaCP &rarr; Server Settings (Sunucu Ayarları) &rarr; Security / API** bölümünde API erişiminin aktif olduğunu ve Webmail sunucunuzun IP adresinin izin verilen IP listesinde (`Allowed IP addresses for API`) bulunduğunu doğrulayın.

---

### S4: Türkçe karakterli özel klasörler (Örn: `Özel`, `Şirket`, `İrsaliye`) oluşturduğumda sorun yaşar mıyım?
**Cevap:** Hayır. Sistem RFC 3501 standardına uygun `UTF7-IMAP` kodlama ve çözümleme motoruna sahiptir; Türkçe karakterli klasörler hem IMAP sunucusunda hem veritabanında tam uyumlu şekilde oluşturulur, yeniden adlandırılır ve silinir.

---
---

# 🇬🇧 English FAQ and Troubleshooting

### Q1: How can I keep my production credentials safe across `git pull` updates?
**Answer:** Create a `config.local.php` file in the root directory and define your constants (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `MAIL_DOMAIN`) there. `config.php` automatically loads `config.local.php` first, and `.gitignore` ensures it is never tracked by Git.

### Q2: How do I fix the `sudo: a password is required` error in HestiaCP Local CLI mode?
**Answer:** Grant passwordless `sudo` permission strictly for `/usr/local/hestia/bin/*` to your web server user (`www-data`):
```bash
echo 'www-data ALL=(ALL) NOPASSWD: /usr/local/hestia/bin/*' | sudo tee /etc/sudoers.d/hestia-webmail
sudo chmod 440 /etc/sudoers.d/hestia-webmail
```

### Q3: Why can't Remote REST API mode connect to Port 8083?
**Answer:** Verify that Port `8083` is open in your firewall and that your webmail server's IP address is whitelisted under **HestiaCP &rarr; Server Settings &rarr; Security &rarr; Allowed IP addresses for API**.

