# 🧩 3. Modüller ve Kullanım Kılavuzu / Modules & User Guide

<p>
  <a href="#-türkçe-kullanım-kılavuzu">🇹🇷 Türkçe Kullanım Kılavuzu</a> •
  <a href="#-english-user-guide">🇬🇧 English User Guide</a>
</p>

---

# 🇹🇷 Türkçe Kullanım Kılavuzu

## 1. 📬 E-Posta Yönetimi (Webmail)
- **Seçim & Toplu İşlemler:**
  - Liste üstündeki seçim kutusunun yanındaki oka tıklayarak **"Görünen Mailleri Seç"** (yalnızca aktif sayfadaki 25 e-posta) veya **"Tümünü Seç (Klasördeki Tüm Mailler)"** (klasördeki tüm sayfalardaki e-postalar) seçimini yapabilirsiniz.
  - Seçilen e-postaları üst araç çubuğundaki **"Klasöre Taşı"** açılır menüsü (dropdown) ile istediğiniz sistem klasörüne veya özel klasöre tek tıkla taşıyabilirsiniz.
- **Özel Klasör Oluşturma & Silme:**
  - Sol menüdeki **Klasörler** başlığının yanındaki `+` butonundan veya **Ayarlar &rarr; Klasörler** sekmesinden renk kodlu özel IMAP klasörleri oluşturabilir, yeniden adlandırabilir ve silebilirsiniz.
- **Gelen Posta Filtreleri (Otomatik Kurallar):**
  - **Ayarlar &rarr; Filtreler** bölümünden *"Gönderen şunu içeriyorsa &rarr; Şu klasöre taşı / Okundu işaretle / Sil"* kuralları tanımlayabilirsiniz.
- **E-Posta İletme (Forward) ve Ekler:**
  - Bir e-postayı **İlet (Forward)** butonuna bastığınızda orijinal e-postadaki tüm ek dosyalar otomatik olarak yeni iletiye aktarılır.

---

## 2. 👥 Adres Defteri & Kurumsal Rehber
- **Otomatik Alıcı Kaydı:**
  - Sadece **sizin e-posta gönderdiğiniz** alıcılar (`Kime`, `CC`, `BCC`) rehberinizde kayıtlı değilse otomatik olarak `E-Posta Kişileri` grubuna eklenir. Gelen kutusundaki bülten veya spam göndericileri rehberinize eklenmez.
- **Kişi Düzenleme & Gruplar:**
  - Rehberdeki her kişinin yanındaki **Düzenle (Kalem)** ikonuna tıklayarak Ad, Soyad, E-Posta, Telefon, Şirket, Unvan, Grup ve Not bilgilerini güncelleyebilirsiniz.
- **CSV İçe / Dışa Aktarma:**
  - Toplu kişi listelerinizi `.csv` formatında içe veya dışa aktarabilirsiniz.

---

## 3. 📅 Paylaşımlı Kurumsal Takvim
- **Etkinlik & Toplantı Yönetimi:**
  - Aylık, haftalık ve ajanda görünümlerinde toplantı, hatırlatma ve kurumsal etkinlikler oluşturabilirsiniz.
  - **"Kurumsal Paylaşımlı Etkinlik"** seçeneği işaretlendiğinde aynı domaindeki tüm çalışma arkadaşlarınız etkinliği kendi takvimlerinde görebilir.
- **E-Posta Davetiyeleri (`.ics`):**
  - Gelen e-postalardaki toplantı davetlerini (**Kabul Et / Belki / Reddet**) doğrudan e-posta okuma ekranından yanıtlayabilirsiniz.

---

## 4. 💬 Domain İçi Anlık Mesajlaşma (Kurumsal Chat)
- Sağ alttaki yüzen sohbet butonundan veya sol menüdeki **Mesajlaşma** sekmesinden açılır.
- Aynı kurumsal domaindeki çalışma arkadaşlarınızla **1'e 1 özel sohbet** veya **grup sohbeti** başlatabilirsiniz.
- Dosya/görsel gönderimi, mesaj sabitleme (pin), emoji tepkileri, çevrimiçi durum (`Çevrimiçi`, `Meşgul`, `Uzakta`) ve *"yazıyor..."* bildirimlerini destekler.

---

## 5. ☁️ Bulut Sürücü (Drive), 📝 Notlar ve ✅ Görevler
- **Bulut Sürücü:** Kişisel klasörler oluşturup belgelerinizi güvenli şekilde sunucuda saklayabilir, indirebilir ve kota kullanımınızı takip edebilirsiniz.
- **Notlar:** Renk kodlu ve sabitlenebilir (pin) hızlı not kartları.
- **Görevler:** Son teslim tarihi ve öncelik derecesi (`Yüksek`, `Orta`, `Düşük`) içeren yapılacaklar listesi.

---

## 6. 🔄 Harici E-Posta Taşıma Aracı (`worker_import.php`)
- **Ayarlar &rarr; E-Posta Taşıma (İçe Aktar)** sekmesinden eski Gmail, Yandex, Outlook veya cPanel posta kutunuzun IMAP bilgilerini girerek tüm geçmiş e-postalarınızı arka planda mevcut hesabınıza aktarabilirsiniz.
- Arka plan işçisi (`worker_import.php`) mükerrer (duplicate) kontrolü yapar ve canlı ilerleme yüzdesi sunar.

---
---

# 🇬🇧 English User Guide

## 1. 📬 Webmail & Folder Management
- **Bulk Selection ("Select Visible" vs. "Select All in Folder"):** Use the dropdown arrow next to the top checkbox to select either the current page or every message in the active folder, then move, mark, or delete them using the **Move to Folder** dropdown.
- **Custom IMAP Folders & Mail Filters:** Create color-coded IMAP folders and automated incoming rules under **Settings &rarr; Folders / Filters**.
- **Forward with Attachments:** Forwarding an email automatically preserves and attaches all original files.

## 2. 👥 Smart Address Book
- **Outgoing Auto-Save Only:** Only recipients you actively send emails to (`To`, `CC`, `BCC`) are automatically saved to `E-Posta Kişileri`. Incoming emails never pollute your address book.
- **Full Contact Editing & CSV Import/Export:** Edit contact details at any time or bulk import/export via CSV.

## 3. 📅 Shared Corporate Calendar, ☁️ Cloud Drive, 📝 Notes & ✅ Tasks
- Schedule personal or domain-wide shared events, respond to `.ics` meeting invites inline, store files in Cloud Drive, and manage pinned notes and prioritized tasks.

## 4. 💬 Internal Domain Chat & 🔄 IMAP Migration Worker
- Collaborate in real time with colleagues on your domain via 1-on-1 and group chats, and migrate legacy mailboxes in the background using `worker_import.php`.
