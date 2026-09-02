# S.NET RADIUS & VPN WIREGUARD MANAGER

<p align="center">
  <strong>PT Network Inovation Solutions</strong><br>
  All-in-One ISP &amp; RT/RW-Net Management Platform: FreeRADIUS WiFi Hotspot, Broadband PPPoE Billing, GenieACS TR-069, dan WireGuard VPN Remote Hub.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/FreeRADIUS-3.x-00599C?style=flat-square&logo=cplusplus&logoColor=white" alt="FreeRADIUS">
  <img src="https://img.shields.io/badge/WireGuard-VPN-88171A?style=flat-square&logo=wireguard&logoColor=white" alt="WireGuard">
  <img src="https://img.shields.io/badge/MikroTik-RouterOS_v7-EE3124?style=flat-square" alt="MikroTik">
  <img src="https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL">
</p>

---

## 🚀 Fitur Utama

### 1. 📡 FreeRADIUS WiFi Hotspot Manager
- **Multi-NAS / Multi-Router**: Hubungkan banyak router MikroTik ke satu server pusat.
- **Generator Voucher Fleksibel**: Generate ribuan voucher sekaligus dengan format prefix, panjang karakter, dan kuota waktu/kuota data.
- **Template Desain Voucher Cetak**: Siap cetak ke format thermal paper atau label kertas.
- **User Tracking & Live Monitoring**: Pantau pengguna aktif, traffic bandwidth (upload/download), dan waktu online secara real-time.
- **Auto-Expire Voucher**: Sistem otomatis menonaktifkan voucher kedaluwarsa via background cron job.

### 2. 🌐 WireGuard VPN Hub & Interkoneksi Router (Baru!)
- **Hub-and-Spoke VPN**: Hubungkan router MikroTik cabang atau router klien di balik CGNAT / IP Dinamis langsung ke VPS.
- **Generator Skrip MikroTik 1-Klik**: Otomatis menghasilkan skrip RouterOS v7 lengkap dengan keypair kriptografi dan IP Tunnel.
- **Site-to-Site LAN Routing**: Dukungan routing subnet LAN antar-cabang secara transparan.
- **Diagnostic Tools**: Ping test live, pengecekan port MikroTik API (8728) dan Winbox (8291) langsung dari web.
- **QR Code & Config Download**: Unduh file `.conf` atau scan QR Code untuk perangkat HP (Android/iOS) atau PC.

### 3. 🔀 Remote Access NAT / Port Forwarding (Baru!)
- **Remote Winbox & Webfig Tanpa IP Publik Statis**: Buka akses Winbox router MikroTik di lokasi terpencil melalui IP Publik VPS Anda (contoh: `IP_VPS:8292` &rarr; `10.66.66.2:8291`).
- **Otomasi Firewall iptables**: Aturan port forwarding (DNAT & MASQUERADE) otomatis disinkronkan ke kernel Linux.

### 4. 📶 Broadband PPPoE Billing & Customer Management
- **Manajemen Pelanggan Rumahan**: Pencatatan data pelanggan, paket bulanan, tanggal jatuh tempo, dan nomor SN ONT.
- **Sinkronisasi 2-Arah MikroTik ↔ Database**: Import & sinkronisasi otomatis seluruh `/ppp/secret` dari MikroTik ke database web panel.
- **Sistem Isolir Otomatis**: Pelanggan yang menunggak otomatis dialihkan ke profil isolir, sesi di-kick, dan ONT di-reboot otomatis via GenieACS TR-069.
- **Payment Gateway Midtrans Snap**: Pembayaran tagihan online otomatis via QRIS, Virtual Account (BCA, Mandiri, BNI, BRI), dan E-Wallet dengan auto-reaktivasi instan detik itu juga.
- **WhatsApp Gateway & Auto-Reminder**: Pengiriman pesan pengingat tagihan otomatis (H-3, H-1, Hari H), pemberitahuan isolir, konfirmasi pembayaran lunas, dan kirim pesan manual via Fonnte / Ultramsg / Green API.
- **Cetak Struk & Invoice**: Struk bukti pembayaran kasir format thermal 58mm/80mm siap cetak.

### 5. 📡 TR-069 GenieACS ONT Management
- Manajemen terpusat ONT FiberHome, ZTE, Huawei, CData.
- Remote provisioning konfigurasi WiFi (SSID/Password), konfigurasi WAN PPPoE, dan parameter binding.
- Portal Mandiri Pelanggan (`/portal/`): Pelanggan dapat ganti nama WiFi & password sendiri langsung dari HP.

### 6. 📊 Laporan Finansial & Audit Trail
- Laporan penjualan voucher hotspot, pembayaran bulanan PPPoE, dan rekap penagihan kasir.
- Ekspor laporan ke format CSV / Excel.
- Audit log aktivitas setiap tindakan admin untuk keamanan.

---

## 📋 Persyaratan Sistem

- **Server VPS**: Ubuntu 20.04 / 22.04 / 24.04 LTS atau Debian 11 / 12
- **Web Server**: Nginx atau Apache (Disarankan menggunakan aaPanel)
- **PHP**: Versi 8.0 atau lebih baru dengan ekstensi: `mysqli`, `pdo_mysql`, `curl`, `json`, `mbstring`, `session`
- **Database**: MySQL 5.7+ / MariaDB 10.4+
- **FreeRADIUS**: Versi 3.2.x dengan modul `freeradius-mysql`
- **WireGuard**: Kernel Linux dengan modul WireGuard & `wireguard-tools`

---

## ⚡ Panduan Instalasi Cepat (Quick Start)

### 1. Unduh Source Code dari GitHub
Masuk ke direktori web root Anda (misal: `/www/wwwroot/s.shawir.id`):
```bash
cd /www/wwwroot/s.shawir.id
git clone https://github.com/Shawir1312/SNET-MANAGER-WITH-RADIUS-VPN-WIRE-GUARD.git .
chown -R www:www /www/wwwroot/s.shawir.id
chmod -R 775 config/
chmod +x setup_freeradius.sh setup_wireguard.sh scripts/*.sh
```

### 2. Jalankan Web Installer
1. Buat database MySQL baru di panel hosting/VPS Anda (misal nama database: `radius`).
2. Buka browser dan akses URL: `http://domain-anda/install.php`
3. Masukkan informasi koneksi database MySQL &rarr; Klik **Test & Lanjut**.
4. Klik **Buat Tabel** (sistem akan otomatis membuat seluruh tabel FreeRADIUS, Hotspot, PPPoE, GenieACS, WireGuard, dan WhatsApp Gateway).
5. Buat Akun Superadmin pertama &rarr; Selesai!

### 3. Konfigurasi FreeRADIUS Otomatis
Jalankan skrip auto-konfigurasi FreeRADIUS di terminal VPS Anda:
```bash
sudo bash setup_freeradius.sh
```
*Skrip ini akan otomatis membaca database Anda, mengonfigurasi `/etc/freeradius/3.0/mods-available/sql`, mengaktifkan modul SQL, dan merestart service FreeRADIUS.*

### 4. Konfigurasi WireGuard Server Otomatis
Jalankan skrip auto-konfigurasi WireGuard di terminal VPS Anda:
```bash
sudo bash setup_wireguard.sh
```
*Skrip ini akan otomatis menginstal paket WireGuard, mengaktifkan `ip_forward`, men-generate keypair server (Subnet `10.66.66.1/24`), mengatur hak akses `sudoers`, dan mengaktifkan service `wg-quick@wg0`.*

---

## ⏱️ Pengaturan Cron Job Otomatis

Tambahkan baris perintah berikut ke dalam Crontab (`crontab -e`):

```bash
# Auto-expire voucher hotspot yang telah habis durasi (setiap 5 menit)
*/5 * * * * php /www/wwwroot/s.shawir.id/cron/expire_vouchers.php >> /var/log/snet_voucher_cron.log 2>&1

# Pembersih sesi hantu / nyangkut saat router mati lampu (setiap 5 menit)
*/5 * * * * php /www/wwwroot/s.shawir.id/cron/auto_clear_ghosts.php >> /var/log/snet_ghosts_cron.log 2>&1

# Cek jatuh tempo PPPoE & isolir otomatis (setiap hari jam 01:00)
0 1 * * * php /www/wwwroot/s.shawir.id/process/cron_pppoe.php >> /var/log/snet_pppoe_cron.log 2>&1

# Kirim pengingat tagihan WhatsApp otomatis H-3, H-1, dan Hari H (setiap hari jam 08:00)
0 8 * * * php /www/wwwroot/s.shawir.id/cron/cron_pppoe_reminder.php >> /var/log/snet_wa_reminder.log 2>&1
```

---

## 📂 Struktur Direktori Proyek

```
├── config/                  # Konfigurasi aplikasi & database
│   ├── config.php           # Konstanta utama aplikasi
│   ├── database.php         # Koneksi DB MySQL singleton
│   ├── auth.php             # Session handler & RBAC
│   └── db_local.php         # Kredensial DB lokal (dibuat installer)
├── include/                 # Komponen layout & fungsi helper
│   ├── header.php           # Topbar & navigasi atas
│   ├── sidebar.php          # Menu navigasi samping
│   ├── footer.php           # Closing script & footer
│   ├── functions.php        # Helper aplikasi umum
│   └── wireguard_functions.php # Helper WireGuard, keygen & NAT
├── pages/                   # Modul Tampilan Web Admin
│   ├── dashboard.php        # Dashboard statistik utama
│   ├── wireguard/           # Modul VPN WireGuard (Routers, NAT, Settings, Logs)
│   ├── routers/             # Modul NAS / RADIUS Router MikroTik
│   ├── profiles/            # Modul Profil / Paket Hotspot
│   ├── vouchers/            # Modul Voucher Hotspot & Cetak
│   ├── pppoe/               # Modul Pelanggan PPPoE, Pembayaran & Isolir
│   ├── genieacs/            # Modul Manajemen Server GenieACS TR-069
│   ├── monitor_ont/         # Modul Monitoring ONT Klien
│   ├── reports/             # Laporan Penjualan, Pemakaian & Penagihan
│   └── settings/            # Pengaturan Sistem, Backup & Audit Log
├── process/                 # Backend POST action handlers
│   └── wireguard/           # Handler simpan router, port forward & setting
├── portal/                  # Halaman portal pelanggan
│   └── isolir.php           # Portal Isolir PPPoE interaktif & Midtrans
├── scripts/                 # Shell script otomasi kernel
│   ├── wg-add-peer.sh       # Skrip tambah peer live
│   ├── wg-update-peer.sh    # Skrip update AllowedIPs live
│   └── wg-remove-peer.sh    # Skrip hapus peer live
├── setup_freeradius.sh      # Skrip Auto-Setup FreeRADIUS Server
├── setup_wireguard.sh       # Skrip Auto-Setup WireGuard VPN Server
├── index.php                # Dispatcher routing utama
├── install.php              # Multi-step Web Installation Wizard
├── login.php                # Halaman autentikasi login admin
└── logout.php               # Halaman logout & destroy session
```

---

## 🔒 Arsitektur Keamanan & Jaringan

```
[ MikroTik Client / Cabang ]
       │ (WireGuard Tunnel 10.66.66.x)
       ▼
[ Server VPS Hub (S.NET Manager) ]
 ├── WireGuard Interface (wg0: 10.66.66.1/24)
 ├── FreeRADIUS Auth/Acct Server (Port 1812/1813 UDP)
 ├── Port Forwarding Engine (iptables NAT: Remote Winbox 8291)
 ├── GenieACS TR-069 ACS (Port 7547)
 └── Web Admin Panel & MySQL (Protected with Prepared Statements & CSRF)
```

- **Keamanan Data**: Seluruh query menggunakan *Prepared Statements* (Anti SQL Injection).
- **Perlindungan Form**: Dilengkapi verifikasi token CSRF di setiap aksi.
- **Kriptografi Kunci**: Password admin di-hash dengan standar `password_hash()` (Bcrypt).
- **Proteksi Akses**: Konfigurasi sensitif dilindungi dari akses publik langsung.

---

## 📄 Lisensi & Hak Cipta

Dikembangkan oleh **PT Network Inovation Solutions**  
*Hak Cipta &copy; 2026 PT Network Inovation Solutions. Seluruh hak cipta dilindungi undang-undang.*
