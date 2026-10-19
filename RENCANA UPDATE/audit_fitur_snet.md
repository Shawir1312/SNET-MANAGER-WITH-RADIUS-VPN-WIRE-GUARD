# 🔍 Audit Fitur — S.NET RADIUS Manager
> Hasil analisis mendalam seluruh codebase per 19 Oktober 2026

---

## ✅ Fitur yang Sudah Ada (Lengkap)

| Modul | Fitur | Status |
|---|---|---|
| **Dashboard** | Realtime auto-sync (4 detik), traffic chart per router, sales chart 7 hari, stat cards | ✅ Lengkap |
| **Hotspot Voucher** | Generate, list, print, delete, filter per router/profil/batch | ✅ Lengkap |
| **Profil / Paket** | CRUD profil, template paket siap pakai, sinkronisasi ke voucher lama, preview RADIUS | ✅ Lengkap |
| **Router / NAS** | CRUD router, status ping realtime | ✅ Lengkap |
| **Monitoring** | User aktif dari radacct, daftar MAC address | ✅ Lengkap |
| **PPPoE Pelanggan** | CRUD pelanggan, isolir/buka isolir otomatis, sinkronisasi MikroTik | ✅ Lengkap |
| **PPPoE Pembayaran** | Catat bayar kasir, Midtrans, Tripay, cetak kwitansi, kirim WA | ✅ Lengkap |
| **Laporan Penjualan** | Chart harian, per profil, per router, export CSV, reset & hitung ulang | ✅ Lengkap |
| **Laporan Penagihan Reseller** | Input setoran, kalkulasi bersih otomatis, deteksi tekor/lebih, riwayat | ✅ Lengkap |
| **Laporan Pemakaian Data** | Top user, chart harian, filter bulan/rentang, dari radacct | ✅ Lengkap |
| **WhatsApp Notifikasi** | Gateway WA, template pesan, reminder tagihan PPPoE otomatis | ✅ Lengkap |
| **Monitor ONT** | GenieACS integration, reboot ONT, detail ONT | ✅ Lengkap |
| **WireGuard VPN** | Router/peer VPN, port forwarding, logs, watchdog cron | ✅ Lengkap |
| **Kelola Admin** | CRUD admin, role superadmin/operator, akses per router | ✅ Lengkap |
| **Backup & Restore** | Export/import database, sinkronisasi RADIUS | ✅ Lengkap |
| **Audit Log** | Pencatatan aktivitas admin | ✅ Lengkap |
| **Cron Jobs** | Expire voucher, clear ghost session, bandwidth snapshot, reminder WA | ✅ Lengkap |

---

## ❌ Fitur yang BELUM ADA / Masih Kurang

### 🔴 PRIORITAS TINGGI (Langsung Berdampak ke Masalah Operasional)

#### 1. 📊 Laporan Rekonsiliasi Reseller Otomatis
**Masalah:** Tidak ada tampilan yang membandingkan secara langsung antara omzet penjualan
voucher vs total setoran reseller dalam satu tabel — menyebabkan data penjualan
tidak sesuai dengan penerimaan nyata.

**Yang kurang:**
- Tidak ada halaman **"Rekonsiliasi"** yang menampilkan: omzet penjualan vs total disetor
  vs selisih (hutang/lebih bayar) per reseller per periode
- Tidak ada **grafik tren setoran reseller** per bulan (apakah naik/turun/konsisten)
- Tidak ada **peringatan otomatis** jika reseller belum setor lebih dari X hari setelah
  voucher habis

---

#### 2. 🔔 Sistem Notifikasi / Alert Internal
**Yang kurang:**
- Tidak ada sistem alert dashboard jika: stok voucher reseller hampir habis (< 10 lembar),
  reseller belum setor > 7 hari, PPPoE akan jatuh tempo hari ini
- Tidak ada **badge notifikasi** di sidebar untuk hal-hal mendesak
- Semua notifikasi hanya via WA ke pelanggan, tidak ada notifikasi ke admin sendiri

---

#### 3. 📦 Manajemen Stok Voucher per Reseller
**Yang kurang:**
- Tidak ada fitur **"Kiriman Voucher"** — mencatat berapa lembar voucher yang dikirim ke
  reseller, kapan, dan berapa yang sudah laku vs sisa di tangan reseller
- Sistem sekarang hanya bisa generate voucher, tapi tidak bisa melacak:
  *"Reseller A pegang berapa voucher saat ini?"*
- Tidak ada fitur **transfer/pindah voucher** antar reseller

---

#### 4. 📅 Target & Proyeksi Pendapatan Bulanan
**Yang kurang:**
- Tidak ada fitur **"Target Omzet"** per cabang/router per bulan
- Tidak ada perbandingan **Realisasi vs Target** di dashboard
- Tidak ada proyeksi: *"Dengan tren sekarang, bulan ini akan mencapai berapa?"*

---

### 🟡 PRIORITAS SEDANG (Penting untuk Efisiensi Operasional)

#### 5. 🧾 Laporan Laba Rugi (P&L) Ringkas
**Yang kurang:**
- Tidak ada laporan **pendapatan bersih gabungan** (Hotspot + PPPoE - biaya operasional)
- Tidak ada fitur input **biaya operasional** (biaya VPS, listrik, dll) untuk menghitung
  laba bersih nyata
- Laporan penjualan dan penagihan masih terpisah, tidak ada satu halaman ringkasan keuangan

---

#### 6. 🔄 Riwayat Stok Voucher per Batch
**Yang kurang:**
- Di daftar voucher, tidak bisa melihat histori berapa voucher dari batch tertentu yang
  sudah dipakai per hari/per minggu
- Tidak ada grafik **laju pemakaian voucher** per batch (apakah cepat habis atau lambat)
- Tidak ada fitur **peringatan stok menipis** per profil (misal: sisa < 20 voucher → notif
  ke admin)

---

#### 7. 👤 Profil Reseller Terpisah dari Profil Paket
**Yang kurang:**
- Reseller saat ini diidentifikasi hanya lewat `reseller_percent > 0` di tabel `profiles`.
  Ini menyebabkan mixing antara data paket dan data reseller
- Tidak ada **tabel reseller terpisah** dengan info: nama reseller, nomor HP, wilayah,
  tanggal bergabung, total omzet keseluruhan
- Tidak ada **halaman profil reseller** yang menampilkan riwayat lengkap satu reseller

---

#### 8. 📤 Export Laporan ke PDF / Excel (bukan hanya CSV)
**Yang kurang:**
- Export saat ini hanya CSV (teks mentah). Tidak ada export ke **PDF** dengan kop surat
  perusahaan
- Tidak ada **struk/invoice resmi** untuk penagihan reseller yang bisa dicetak
- Laporan Penagihan tidak bisa di-export ke Excel format yang rapi

---

#### 9. 🔐 Two-Factor Authentication (2FA) untuk Login Admin
**Yang kurang:**
- Login admin hanya dengan username + password, tidak ada lapisan keamanan 2FA
  (OTP via WA/email)
- Tidak ada fitur **lupa password** self-service
- Tidak ada pengaturan **batas percobaan login** (brute-force protection)

---

#### 10. 🗺️ Peta Lokasi Pelanggan / Coverage Area
**Yang kurang:**
- Data pelanggan PPPoE tidak memiliki field **koordinat GPS** untuk ditampilkan di peta
- Tidak ada **visualisasi map** cakupan jaringan per router/cabang
- Tidak ada fitur melihat pelanggan mana saja yang dalam radius tertentu dari sebuah router

---

### 🟢 PRIORITAS RENDAH (Nice to Have)

#### 11. 📱 Progressive Web App (PWA) / Mobile View
- Tidak ada `manifest.json` atau service worker → tidak bisa di-"install" di HP

#### 12. 📊 Laporan Bandwidth per Pelanggan PPPoE
- Tidak ada laporan khusus bandwidth per pelanggan PPPoE + tren bulanan
- Tidak ada batas kuota otomatis untuk PPPoE (isolir jika kuota habis)

#### 13. 🤖 Bot WA Dua Arah untuk Self-Service Pelanggan
- WA gateway sekarang hanya kirim notifikasi (satu arah)
- Belum ada bot WA untuk pelanggan: cek saldo voucher, cek masa aktif, bayar tagihan

#### 14. 📋 Template Voucher yang Bisa Dikustomisasi dari UI
- Desain voucher cetak fixed, tidak bisa diubah dari panel admin

#### 15. 🔗 REST API Publik
- Tidak ada API endpoint untuk integrasi sistem kasir POS atau aplikasi mobile

---

## 📊 Ringkasan Prioritas

```
🔴 TINGGI (Segera)                   🟡 SEDANG (Rencanakan)           🟢 RENDAH (Nanti)
─────────────────────────────────    ─────────────────────────────    ───────────────────
1. Rekonsiliasi Reseller             5. Laporan Laba Rugi             11. PWA Mobile
2. Notifikasi/Alert Internal         6. Riwayat Stok per Batch        12. Bandwidth PPPoE
3. Manajemen Stok Voucher            7. Profil Reseller Terpisah      13. Bot WA 2 Arah
4. Target & Proyeksi Bulanan         8. Export PDF/Excel              14. Template Voucher
                                     9. 2FA Login                     15. REST API
                                    10. Peta Lokasi Pelanggan
```

---

## 💡 Rekomendasi Langkah Pertama

> **Fitur #1 — Halaman Rekonsiliasi Reseller**
>
> Satu halaman yang menampilkan side-by-side:
> - Omzet voucher terjual (dari `sales_log`)
> - Total disetor reseller (dari `penagihan`)
> - **Selisih/hutang** yang belum disetor
> - Alert merah jika reseller sudah X hari belum setor
