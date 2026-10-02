# **BLUEPRINT EKSEKUSI PEMROGRAMAN: SISTEM INFORMASI TATA USAHA MADRASAH TERPADU (CORE MASTER DATA & INTEGRATION HUB)**

---

## **1\. RINGKASAN ARSITEKTUR & EKOSISTEM SISTEM**

Sistem Tata Usaha Madrasah Terpadu dibangun dengan pola arsitektur Master Data Management (MDM) / Hub-and-Spoke sebagai Single Source of Truth. Sistem ini menjadi gerbang data utama madrasah (input awal PPDB dan data GTK), terintegrasi dua arah dengan kementerian, mengonsolidasikan data secara terpusat, dan menyediakannya untuk aplikasi satelit eksternal (seperti **SIPSMAD** / Sistem Informasi Presensi Siswa Madrasah, PTSP, dan E-Surat) melalui Open API Gateway.

### **A. Tech Stack Pokok:**

* **Deployment & Service Runner**: **Native Baremetal Linux (Ubuntu 26.04 LTS)** menggunakan **systemd**, **PHP-FPM**, dan **Supervisor** untuk antrean background worker Laravel.  
* Backend Framework: PHP 8.2+ dengan Laravel 11\.  
* Database Utama: **MySQL 8.0 (InnoDB Engine)** bawaan aaPanel, kompatibel 100% dengan driver default Laravel 11 (**DB\_CONNECTION=mysql**).  
* Caching & Queue Worker: Redis.  
* **Arsitektur Jembatan Frontend**: **Laravel 11 \+ Inertia.js \+ React (TypeScript) \+ Vite**.  
* Standardisasi Komponen Antarmuka: 100% shadcn/ui (React) \+ Tailwind CSS \+ Lucide React.  
* Engine Pembuat Dokumen & Cetak: Kombinasi CSS Native @media print (rendering presisi di sisi browser klien tanpa membebani RAM server) dan barryvdh/laravel-dompdf untuk ekspor dokumen unduhan PDF di backend.  
* File Storage: Google Drive API (Google Workspace for Education) via Google Service Account.  
* AI Processing Engine: Gemini 1.5 Flash API (Multimodal Document Verification).  
* Tunnel & Ingress: Cloudflare Tunnel (cloudflared) \+ Cloudflare Zero Trust.  
* **Server Management Panel**: **aaPanel** (Mengelola Nginx, PHP 8.2+/8.3, PostgreSQL, Redis, Supervisor Manager, dan Cron Job).

---

## **2\. SPESIFIKASI INFRASTRUKTUR ON-PREMISE & KEAMANAN (NATIVE BAREMETAL)**

### **A. Profil Server Fisik Lokal (Eksisting Madrasah):**

* OS: Linux Ubuntu 26.04 LTS (x86\_64).  
* Processor (CPU): Intel Core i3-3220 (2 Cores, 4 Threads @ 3.30 GHz).  
* RAM: 16 GB DDR3.

* Penyimpanan Lokal & Deployment: Seluruh layanan (Nginx, PHP-FPM, PostgreSQL 16, Redis) berjalan murni secara *native* langsung di atas kernel Ubuntu OS untuk menghemat kapasitas disk SSD 57 GB dan memangkas overhead virtualisasi pada prosesor Core i3-3220.  
* Jaringan: Download 100 Mbps / Upload 50 Mbps.

### **B. Konfigurasi Tuning MySQL 8.0 (Alokasi RAM 16 GB via GUI aaPanel):**

* innodb\_buffer\_pool\_size \= 4096M (4 GB alokasi memori buffer pool InnoDB).  
* innodb\_log\_file\_size \= 512M  
* innodb\_flush\_log\_at\_trx\_commit \= 2 (memaksimalkan kecepatan tulis transaksi pada SSD 57 GB).  
* max\_connections \= 300  
* character-set-server \= utf8mb4 dan collation-server \= utf8mb4\_unicode\_ci (dukungan penuh teks nama, simbol, dan tanda baca resmi).

### **C. Jaringan & Keamanan Akses:**

* Cloudflare Tunnel: Mengakses server lokal dari internet tanpa membutuhkan IP Publik Statis dan tanpa membuka port pada router (no port forwarding).  
* Cloudflare Zero Trust: Mengamankan rute administrasi internal (/admin, /master-data) dengan otentikasi email resmi madrasah, sementara rute publik (verifikasi QR Code surat dan formulir PPDB) dibuka untuk umum.  
* Keandalan Daya & Backup 3-2-1: Pemanfaatan UPS dengan BIOS Auto Power-On, serta cron job harian pada 00.00 WIB untuk dump database dan sinkronisasi berkas backup ke Google Drive via rclone.

### **D. Arsitektur Lingkungan aaPanel:**

* **Struktur Direktori Web**: Aplikasi diletakkan pada direktori standar **/www/wwwroot/pintu-madrasah** dengan Document Root mengarah ke **/www/wwwroot/pintu-madrasah/public**.  
* **Konfigurasi Nginx & URL Rewrite**: Memanfaatkan template URL Rewrite bawaan aaPanel khusus **laravel** (**location / { try\_files $uri $uri/ /index.php?$query\_string; }**).  
* **Ekstensi PHP Wajib (diinstal via aaPanel)**: **pdo\_mysql**, **mysqli**, **redis**, **fileinfo**, **gd**, **zip**, **opcache**, dan **curl**.  
* **Manajemen Antrean (Supervisor Manager aaPanel)**: Menggunakan plugin resmi *Supervisor Manager* di App Store aaPanel untuk menjalankan dan mengawasi proses **php artisan queue:work** secara otomatis 24/7 (dengan auto-restart jika crash).  
* **Penjadwalan Tugas (Cron aaPanel)**: Menambahkan tugas cron di menu aaPanel untuk menjalankan **\* \* \* \* \* php /www/wwwroot/pintu-madrasah/artisan schedule:run \>\> /dev/null 2\>&1**.  
* **Manajemen Database Visual (phpMyAdmin)**: **phpMyAdmin 1-Klik Bawaan aaPanel** untuk kemudahan inspeksi tabel dan ekspor-impor basis data.  
* Cadangan Data: Memanfaatkan menu Scheduled Backup Database bawaan aaPanel untuk backup otomatis harian database MySQL.  
* **Manajemen Berkas Rahasia (Secrets Management)**: File kredensial *Google Service Account JSON* ditempatkan secara aman di direktori terisolasi **/www/wwwroot/pintu-madrasah/storage/app/secrets/** (di luar Document Root web) dan terproteksi ketat di **.gitignore** untuk mencegah kebocoran repositori.

---

## **3\. SKEMA DATABASE UTAMA (MySQL 8.0 \- InnoDB Engine)**

Spesifikasi dengan dukungan penuh kolom JSON, UUID, indexing komposit, foreign keys berjenjang, dan soft deletes.

### **A. Tabel Referensi & Kelembagaan:**

* **Tabel Otentikasi Pengguna**: users: id (UUID), name, username (NIP/Email), password, role (Admin/Staf/Guru), staff\_id (FK nullable ke staff.id), is\_active, remember\_token, timestamps.  
* academic\_years (id, year, semester, is\_active, is\_locked \[BOOLEAN, default: false untuk mengunci data nilai dan rombel semester lampau menjadi Read-Only\]).  
* classes (id, grade\_level, major\_name).  
* rombels (id, academic\_year\_id, class\_id, name, homeroom\_teacher\_id).

### **B. Tabel Data Siswa:**

* students:  
  * id (UUID, Primary Key)  
  * nisn (CHAR(10), Unique, Not Null, Regex: ^\[0-9\]{10}$)  
  * nik (CHAR(16), Unique, Not Null, Regex: ^\[0-9\]{16}$)  
  * nis\_local, kk\_number  
  * name, nickname, gender (ENUM: 'L', 'P'), birth\_place, birth\_date  
  * religion, citizenship, child\_order, siblings\_count, living\_status  
  * address, rt, rw, village, district, regency, postal\_code, distance\_to\_school, transportation  
  * blood\_type, height\_cm, weight\_kg, medical\_history  
  * prev\_school\_name, prev\_exam\_number, graduation\_year  
  * father\_name, father\_nik, father\_job, father\_income, father\_phone  
  * mother\_name (Not Null \- Kunci Verval PD), mother\_nik, mother\_job, mother\_income, mother\_phone  
  * guardian\_name, guardian\_nik, guardian\_job, guardian\_phone  
  * kip\_number, pip\_eligible, pkh\_eligible, kks\_eligible  
  * status (ENUM: 'active', 'graduated', 'mutated\_out', 'dropped')  
  * is\_verified\_by\_ai (BOOLEAN, default: false)  
  * is\_locked (BOOLEAN, default: false) untuk mengunci field identitas yang telah divalidasi dokumen asli agar tidak tertimpa kembali oleh data lama EMIS.  
  * created\_at, updated\_at, deleted\_at (Soft Deletes)  
* student\_academic\_cohorts (Tabel Historis Siswa):  
  * id, student\_id, academic\_year\_id, semester, rombel\_id, attendance\_number, status  
  * Memastikan riwayat kelas masa lalu tetap utuh saat siswa naik kelas.

### **C. Tabel Data GTK (Guru & Tenaga Kependidikan):**

* staff:  
  * id (UUID, Primary Key)  
  * nip (CHAR(18), Nullable \- bagi ASN), nik (CHAR(16), Unique), npk, nuptk  
  * full\_name\_with\_titles, first\_title, last\_title  
  * gender, birth\_place, birth\_date, phone, email  
  * employment\_status (ENUM: 'pns', 'pppk', 'honorer', 'gtt', 'ptt')  
  * civil\_rank (Golongan Ruang: III/a, III/b, IV/a, dll.)  
  * civil\_rank\_tmt, tpg\_status (BOOLEAN, validasi pencairan TPG)  
  * functional\_position (Guru Mapel, Wali Kelas, Kepala TU, Staf Administrasi)  
  * highest\_education, university\_name, study\_program, graduation\_year  
  * is\_active (BOOLEAN)  
  * created\_at, updated\_at, deleted\_at

### **D. Tabel Manajemen Berkas & Log:**

* student\_documents (id, student\_id, document\_type, gdrive\_file\_id, gdrive\_view\_url, file\_size, is\_verified\_ai).  
* activity\_logs (id, user\_id, action, model\_type, model\_id, before\_payload, after\_payload, ip\_address, created\_at).

---

## **4\. PIPELINE INTEGRASI DUA ARAH KEMENAG (EMIS 4.0 & EMIS GTK)**

Sistem beroperasi dengan 2 Portal Resmi Kemenag:

### **A. Integrasi emis.kemenag.go.id (Data Siswa & Rombel):**

1. Jalur Utama API via Bookmarklet:  
   1. Staf membuka menu Siswa di Aplikasi TU  Klik "Ambil Data dari EMIS".  
   2. Server memeriksa ketersediaan token di Redis (TTL 2–8 jam). Jika masih aktif, data langsung ditarik secara otomatis.  
   3. Jika token kedaluwarsa, staf memanfaatkan tombol drag-and-drop \[⚡ Tarik Data EMIS ke TU\] yang disimpan di bilah bookmark browser.  
   4. Staf login ke EMIS di tab resmi Kemenag (menyelesaikan Google reCAPTCHA secara sah), lalu klik tombol bookmarklet 1 kali.  
   5. Token dikirim ke server lokal TU (Laravel), tab EMIS dapat langsung ditutup, dan server TU menarik seluruh data JSON siswa per rombel di latar belakang.  
   6. **Aturan Logika Selective Upsert & Field Protection**: Saat sinkronisasi ulang dari EMIS, sistem otomatis memproteksi field identitas siswa yang memiliki status **is\_locked \= true** atau **is\_verified\_by\_ai \= true**, memastikan hasil koreksi AI berdasarkan Akta/KK tidak pernah tertimpa kembali oleh data mentah EMIS.  
2. Jalur Cadangan: Fitur Smart Importer berkas rekapitulasi Excel (.xlsx) resmi EMIS 4.0.

### **B. Integrasi emisgtk.kemenag.go.id (Data 100% PTK & Agregasi SIMPEG):**

* Penarikan Massal Akun Admin: Operator TU login ke akun Admin Madrasah di emisgtk.kemenag.go.id, lalu mengklik Bookmarklet khusus GTK untuk menarik seluruh data guru & staf TU (ASN dan Non-ASN).  
* Agregasi Data SIMPEG 5 untuk Validasi TPG: EMIS GTK bertindak sebagai agregator resmi data kepegawaian SIMPEG. Untuk guru yang baru naik pangkat, operator memanfaatkan fitur Impersonate di akun admin EMIS GTK untuk memicu tombol "Sinkronkan SIMPEG", lalu menyerap data mutakhirnya ke sistem TU tanpa perlu konektor terpisah ke portal SIMPEG 5\.

### **C. Alur PPDB & Pendaftaran Siswa Baru (Tarik Siswa by NISN):**

* Pada EMIS 4.0, registrasi siswa baru tidak menggunakan impor file, melainkan penarikan data nasional (Tarik Siswa by NISN).  
* Peran Aplikasi TU sebagai PPDB Validation Hub:  
  * Form pendaftaran di TU memvalidasi ketat format 10 digit NISN, NIK, dan nama ibu kandung.  
  * Sistem menyediakan antrean terverifikasi siap tarik di EMIS lengkap dengan tombol 1-Click Copy NISN.  
  * Operator mengklaim siswa di EMIS tanpa risiko salah sasaran, kemudian menyinkronkan balik statusnya ke TU.

---

## **5\. FITUR KESISWAAN & OTOMASI ADMINISTRASI KELAS (ADAPTASI FILE ADMINISTRASI GURU)**

Menggantikan seluruh lembar kerja Excel manual (.xlsm) menjadi fitur web database otomatis:

1. Cetak Lembar Biodata Siswa / Buku Induk (F4/A4 Print-Ready):  
   1. Dokumen profil lengkap per siswa lengkap dengan pas foto digital, QR Code verifikasi keabsahan, dan tanda tangan dinas.  
   2. Fitur Bulk Print: Menggabungkan seluruh lembar siswa satu kelas menjadi 1 bundel PDF siap cetak.  
2. Instrumen Ujian & Asesmen (STS / SAS):  
   1. Cetak Kartu Peserta Ujian Massal (A4 isi 4–8 kartu per lembar).  
   2. Cetak Label Nomor Bangku/Meja Ujian.  
   3. Cetak Daftar Hadir Pengawas dan Berita Acara Ujian.  
3. Denah Tempat Duduk Siswa (Interactive Seating Chart):  
   1. Pengaturan tata letak meja dan penempatan siswa berbasis drag-and-drop.  
   2. Dokumen denah kelas resmi ukuran A4/F4 siap cetak.  
4. Buku Pembinaan Karakter, Kedisiplinan & Catatan Kasus (BK):  
   1. Jurnal catatan pembiasaan karakter dan profil pelajar P5-PPRA.  
   2. Buku catatan poin pelanggaran tata tertib terhubung ke Guru BK dan Wali Kelas.  
   3. Catatan rekap home visit dan surat pemanggilan orang tua.  
5. Leger Nilai & Jembatan Sinkronisasi RDM:  
   1. Rekap nilai formatif dan sumatif per mata pelajaran.  
   2. Leger Nilai sekelas (DataTable shadcn/ui) dengan kalkulasi rata-rata otomatis.  
   3. Ekspor data nilai terstandarisasi yang 100% kompatibel dengan skema impor Rapor Digital Madrasah (RDM).  
   4. Buku catatan poin pelanggaran tata tertib terhubung ke Guru BK dan Wali Kelas.  
6. Statistik Demografi & Bantuan Sosial:  
   1. Visualisasi rasio gender, usia, status yatim/piatu, serta pemetaan ekonomi orang tua.  
   2. Rekapitulasi daftar penerima bantuan sosial (KIP, PIP, PKH, KKS) per kelas.  
7. Kenaikan Kelas Massal (Batch Promotion) & Register Mutasi:  
   1. Promosi kenaikan kelas satu rombel sekali klik di akhir tahun ajaran.  
   2. Pencatatan register mutasi masuk dan mutasi keluar.

---

## **6\. PENYIMPANAN CLOUD GOOGLE DRIVE TERINTEGRASI**

* Mekanisme Otomasi: Menggunakan Google Service Account untuk menghubungkan backend Laravel ke folder ARSIP\_DIGITAL\_MADRASAH di Google Drive institusi (Google Workspace for Education).  
* Asynchronous Queue: Upload file scan diproses di latar belakang via Redis Queue dengan 3x percobaan ulang (retry backoff).  
* Kompresi Otomatis: Citra hasil scan/kamera ponsel otomatis dikonversi menjadi WebP 300–500 KB sebelum diunggah.  
* In-App Document Preview: Penampil PDF/gambar langsung di dalam aplikasi via modal Dialog shadcn/ui (https://drive.google.com/file/d/{FILE\_ID}/preview).  
* Kepatuhan Privasi Data (UU PDP): Folder Google Drive berstatus Restricted; dokumen sensitif (KTP/KK) dialirkan via Secure Stream Proxy dari server TU.  
* Struktur Folder Otomatis:  
  * ARSIP\_DIGITAL\_MADRASAH/SISWA/{Tahun\_Pelajaran}/{Rombel}/{NISN\_Nama\_Siswa}/  
  * ARSIP\_DIGITAL\_MADRASAH/PTK/{NIP\_Nama\_PTK}/  
  * ARSIP\_DIGITAL\_MADRASAH/PERSURATAN/{Tahun}/{Nomor\_Surat}/

---

## **7\. INTEGRASI KECERDASAN BUATAN (AI DOCUMENT VERIFIER ENGINE)**

* Teknologi: Gemini 1.5 Flash API terintegrasi di backend Laravel.  
* Fungsi: Membaca dan menganalisis berkas scan (PDF/gambar) Akta Kelahiran, Kartu Keluarga, dan Ijazah SD/MI sebelum dikirim ke Google Drive.  
* Pencocokan Silang: Membandingkan hasil ekstraksi AI dengan data inputan operator:  
  * Kartu Keluarga: Nomor KK, NIK Siswa, Nama Siswa, dan Nama Ibu Kandung murni (tanpa gelar).  
  * Akta Kelahiran: Ejaan nama lengkap, tempat lahir, dan tanggal lahir presisi.  
  * Ijazah SD/MI: NISN (10 digit numerik) dan nama sekolah asal.  
* Antarmuka Verifikasi Berdampingan (Side-by-Side Verification Widget):  
  * Modal dialog shadcn/ui menampilkan teks dokumen vs data inputan, skor kecocokan, dan tombol Auto-Fix Sekali Klik untuk memperbaiki form mengikuti dokumen asli.  
* Throttling & Fallback: Pengendalian kuota panggilan API via Redis Rate Limiter, serta tombol Manual Override jika layanan AI mengalami kendala.

---

## **8\. OPEN API GATEWAY & DEVELOPER PORTAL**

Menyediakan antarmuka data induk bagi aplikasi satelit eksternal (seperti Aplikasi Presensi mandiri, PTSP, dan E-Surat). Satelit prioritas yang akan diintegrasikan meliputi:

* **Portal Mandiri PTSP**: Menjadi aplikasi satelit resmi pertama yang akan diintegrasikan menggunakan Open API Gateway TU (*/api/v1/students*).  
* **Notifikasi WhatsApp Gateway**: Direncanakan diintegrasikan segera setelah modul PTSP rampung untuk pengiriman status tiket layanan dan notifikasi wali murid.

### **Profil Integrasi Satelit: SIPSMAD (Presensi Siswa)**

* **Identitas Klien**: **SIPSMAD** (Sistem Informasi Presensi Siswa Madrasah berbasis Web & RFID).  
* **Peran Sistem**: Aplikasi mandiri yang menangani pencatatan kehadiran siswa harian di madrasah.  
* **Izin Akses API (Granular Scopes)**: Diberikan hak akses *students:read*, *classes:read*, dan *academic-years:read*.  
* **Katalog Endpoint yang Dikonsumsi oleh SIPSMAD**:  
  * *GET /api/v1/students?status=active\&academic\_year\_id={active}* : Mengambil daftar seluruh siswa aktif beserta pembagian rombelnya untuk sinkronisasi data mesin/web presensi.  
  * *GET /api/v1/classes* : Mengambil struktur jenjang dan rombel terkini.  
* **Langganan Webhook (Event Subscription)**:  
  * *student.created* : Menambahkan data siswa baru ke antrean kartu RFID di SIPSMAD secara otomatis.  
  * *student.mutated / student.dropped* : Menonaktifkan hak presensi siswa yang telah mutasi keluar atau lulus.  
  * *student.promoted* : Memperbarui pemetaan rombel siswa di SIPSMAD saat kenaikan kelas massal.  
* Autentikasi & Scopes: Manajemen API Key & Client Secret di dashboard TU dengan izin granular (students:read, staff:read, attendance:sync, dll.).  
* Katalog Endpoint Standar (RESTful v1):  
  * GET /api/v1/students : Daftar siswa dengan filter rombel, tingkat, dan pagination.  
  * GET /api/v1/students/{nisn} : Biodata lengkap satu siswa.  
  * GET /api/v1/staff : Daftar guru dan staf TU.  
  * GET /api/v1/classes : Daftar rombel aktif dan wali kelas.  
  * GET /api/v1/academic-years/active : Informasi tahun ajaran aktif.  
* Webhook Engine: Menerbitkan notifikasi otomatis saat terjadi event (student.created, student.updated, student.mutated).  
* Dokumentasi Interaktif: Halaman Swagger / OpenAPI 3.0 bawaan di /api/documentation.  
* Rate Limiting: Pembatasan maksimal 120 request/menit per API Key untuk melindungi performa server lokal.

---

## **9\. STANDAR FRONTEND & DESIGN SYSTEM (100% SHADCN/UI)**

* Estetika: Modern Enterprise SaaS dengan palet warna Deep Emerald Green (\#064E3B) dan aksen Amber Gold (\#D97706).  
* Tipografi: Plus Jakarta Sans atau Inter.  
* Daftar Komponen Utama:  
  * DataTable (TanStack Table): Master data siswa & GTK, multi-sorting, filter kolom, dan seleksi cetak massal.  
  * Command / CommandDialog: Global Command Palette (Ctrl \+ K) untuk pencarian instan siswa, guru, surat, dan menu.  
  * Sheet (Slide-over Drawer): Pratinjau profil ringkas siswa/guru tanpa reload halaman.  
  * Tabs: Pengelompokan form biodata (Pribadi, Tempat Tinggal, Kesehatan, Pendidikan Asal, Orang Tua, Bantuan Sosial).  
  * Card & Badge: Bento Grid dashboard statistik dan status siswa/kepegawaian.  
  * Dialog & AlertDialog: Konfirmasi kritis dan modal pratinjau dokumen.  
  * Sonner: Notifikasi mengambang (floating toast).

---

## **10\. DASHBOARD EKSEKUTIF PIMPINAN (EXECUTIVE INFORMATION SYSTEM \- EIS)**

Dirancang khusus untuk Kepala Madrasah dan Pengawas Kemenag guna memantau data makro madrasah secara real-time untuk keperluan supervisi, akreditasi, dan evaluasi:

* **Ringkasan Eksekutif Kesiswaan**:  
  * Visualisasi total siswa aktif per tingkatan dan rombel (distribusi gender L/P).  
  * Status kelengkapan data Verval PD (siswa yang sudah valid vs yang membutuhkan perbaikan berkas).  
  * Rasio siswa penerima bantuan sosial (PIP, KIP, PKH) dan kesiapan kuota dana BOS.  
* **Ringkasan Eksekutif Kepegawaian (PTK & Validasi TPG)**:  
  * Komposisi status kepegawaian (PNS, PPPK, dan Honorer).  
  * Monitoring kelayakan pencairan Tunjangan Profesi Guru (TPG) berdasarkan agregasi data EMIS GTK & SIMPEG terbaru.  
  * Rasio kecukupan guru terhadap rombongan belajar.  
* **Monitoring Kepatuhan Administrasi Kelas**:  
  * Indikator real-time pemantauan kelengkapan instrumen guru (keterisian denah tempat duduk, progres input leger nilai semester, dan rekap pembinaan karakter P5-PPRA).  
* **Ekspor Laporan Supervisi & Akreditasi Sekali Klik**:  
  * Fitur cetak dokumen ringkasan data makro madrasah berstandar resmi Kemenag (PDF/Excel) yang siap diserahkan saat visitasi Pengawas Madrasah, Asesor Akreditasi, atau laporan berkala ke Kankemenag Kab/Kota.

---

## **11\. ROADMAP EKSEKUSI PEMROGRAMAN (SPRINT PLAN)**

* Sprint 1: Fondasi Infrastruktur & Inisialisasi Proyek  
  * Menambahkan website baru di menu *Website* aaPanel, memilih versi PHP 8.2/8.3, dan menerapkan rewrite rule *laravel*.  
  * Memasang ekstensi PHP yang dibutuhkan serta mengaktifkan Redis & PostgreSQL dari App Store aaPanel.  
  * Mengatur antrean Laravel worker via Supervisor Manager dan Cron di aaPanel.  
  * Menginstal dependensi Composer, setup .env (menggunakan **DB\_CONNECTION=mysql** dan port **3306**), generate key, dan menghubungkan Cloudflare Tunnel ke port/domain situs aaPanel.  
* Sprint 2: Core Master Data & Bookmarklet Integrasi Kemenag  
  * Database Migrations & Models (Siswa, GTK, Rombel, Tahun Ajaran, Riwayat Cohort).  
  * Pembangunan Bookmarklet Drag-and-Drop untuk emis.kemenag.go.id dan emisgtk.kemenag.go.id.  
  * Service penarikan API EMIS & parser data staging.  
* Sprint 3: Fitur Kesiswaan & Format Dokumen Cetak Administrasi  
  * Modul Cetak Lembar Biodata Siswa (Buku Induk A4/F4 dengan QR Code).  
  * Cetak Kartu Peserta Ujian Massal, Label Meja, dan Denah Kelas Interaktif.  
  * Leger Nilai Kelas dan modul ekspor Rapor Digital Madrasah (RDM).  
* Sprint 4: Google Drive Storage & AI Document Verifier Engine  
  * Integrasi Google Service Account & antrean upload asinkron Google Drive.  
  * Integrasi Gemini 1.5 Flash API untuk verifikasi Akta, KK, dan Ijazah.  
  * Pembuatan Side-by-Side Verification Widget (shadcn/ui) dengan tombol Auto-Fix.  
* Sprint 5: Open API Gateway & Developer Portal  
  * Pembangunan RESTful API v1 untuk konsumsi aplikasi pihak ketiga (Aplikasi Presensi mandiri dan Portal Mandiri PTSP).  
  * Implementasi Webhook Engine untuk integrasi WhatsApp Gateway dan antarmuka dokumentasi Swagger di /api/documentation.  
* Sprint 6: Hardening, Granular RBAC, Audit Trail & Peluncuran  
  * Implementasi RBAC bertingkat (spatie/laravel-permission) dan pencatatan log audit (activity\_logs).  
  * Konfigurasi proteksi Cloudflare Zero Trust pada rute internal.  
  * Pengujian beban (load test), simulasi migrasi data, dan serah terima sistem.  
    