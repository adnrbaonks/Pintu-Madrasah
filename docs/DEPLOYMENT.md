# Deployment PINTU MADRASAH

Dokumen ini adalah acuan deploy **staging** dan **production**. Jalankan dari
root aplikasi (`pintu-madrasah/`) sebagai user yang memiliki akses tulis ke
`storage/` dan `bootstrap/cache/`.

## 1. Prasyarat server

| Komponen | Versi minimum | Catatan |
|---|---|---|
| PHP | 8.3 (disarankan 8.4) | ekstensi: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `redis`, `bcmath`, `openssl`, `curl`, `fileinfo` |
| Composer | 2.x | `composer install --no-dev --optimize-autoloader` di production |
| MySQL | 8.0 | `utf8mb4`, zona waktu server UTC |
| Redis | 7.x | session, cache, dan queue |
| Node.js | 22 LTS | hanya untuk build aset |
| Supervisor | 4.x | worker queue |
| cron | — | scheduler Laravel |
| `mysqldump` | sesuai MySQL | dipakai `php artisan backup:run` |

Cek ekstensi:

```bash
php -m | grep -Ei 'pdo_mysql|mbstring|intl|gd|zip|redis|bcmath|fileinfo'
```

## 2. Instalasi awal (staging)

```bash
git clone <repo> pintu-madrasah
cd pintu-madrasah

composer install --no-interaction --prefer-dist
cp .env.staging.example .env.staging
# sesuaikan DB_*, REDIS_*, APP_URL, dan kredensial lain di .env.staging

# Gunakan .env.staging sebagai environment aktif saat deploy:
# (pilih salah satu mekanisme server Anda, misalnya symlink atau --env)
ln -sf .env.staging .env

php artisan key:generate
php artisan migrate --force
SEED_ADMIN_PASSWORD='<password-kuat>' php artisan db:seed --force
php artisan storage:link

npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Jangan** menjalankan `migrate:fresh` di staging/production yang sudah berisi
data. `migrate:fresh` hanya untuk database uji.

## 3. Infrastruktur staging dengan Docker Compose

Untuk staging kecil atau rehearsal lokal:

```bash
cp .env.staging.example .env.staging
docker compose -f docker-compose.staging.yml up -d
```

Compose menyediakan MySQL 8 dan Redis 7. Bila port default bentrok:

```bash
MYSQL_PORT=3307 REDIS_PORT=6380 docker compose -f docker-compose.staging.yml up -d
```

Sesuaikan `DB_PORT`/`REDIS_PORT` di `.env.staging`. Data disimpan di volume
Docker (`pintu-mysql-data`, `pintu-redis-data`).

## 4. Permission direktori

```bash
chown -R <user-web>:<group-web> storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Pastikan `storage/app/backups/` dapat ditulis. File service account Google
Drive sebaiknya di luar web root, misalnya
`storage/app/secrets/service-account.json`, dengan permission `600`.

## 5. Worker queue

Queue yang dipakai aplikasi:

| Queue | Isi |
|---|---|
| `default` | upload Drive, job umum |
| `emis` | pemrosesan dokumen, import awal EMIS (`CommitEmisStagingJob`, `CommitEmisGtkStagingJob`), dan penarikan staging EMIS |
| `webhooks` | pengiriman webhook ke aplikasi satelit (wajib memiliki ekstensi PHP `curl` aktif karena `WebhookUrlGuard` menerapkan verifikasi transport `CURLOPT_RESOLVE` secara *fail-closed*) |

Contoh Supervisor (`/etc/supervisor/conf.d/pintu-worker.conf`):

```ini
[program:pintu-default]
command=php /var/www/pintu-madrasah/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
directory=/var/www/pintu-madrasah
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/pintu-default.log

[program:pintu-emis]
command=php /var/www/pintu-madrasah/artisan queue:work redis --queue=emis --sleep=3 --tries=3 --max-time=3600
directory=/var/www/pintu-madrasah
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/pintu-emis.log

[program:pintu-webhooks]
command=php /var/www/pintu-madrasah/artisan queue:work redis --queue=webhooks --sleep=3 --tries=3 --max-time=3600
directory=/var/www/pintu-madrasah
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/pintu-webhooks.log
```

Muat ulang:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Setiap deploy yang mengubah kode job, jalankan:

```bash
php artisan queue:restart
```

### Uji worker

```bash
php artisan tinker --execute="\App\Jobs\QueueHealthCheckJob::dispatch('default')->onQueue('default');"
php artisan queue:work redis --queue=default --once

php artisan tinker --execute="cache('queue-health:default');"
```

Ulangi untuk queue `emis` dan `webhooks`. Nilai cache harus terisi.

## 6. Scheduler

Tambahkan cron:

```cron
* * * * * cd /var/www/pintu-madrasah && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler saat ini menjalankan:
- Backup harian pukul 00.00 WIB (`backup:run --keep=7`), dengan penyimpanan lokal dan upload Google Drive best-effort bila dikonfigurasi.
- Pemrosesan Transactional Outbox setiap menit (`webhooks:process-outbox --limit=100` dengan `withoutOverlapping(5)`) untuk memastikan event tertunda atau *expired lease* dipulihkan secara otomatis.

## 7. Zona waktu

- `APP_TIMEZONE=UTC` — semua timestamp disimpan UTC.
- `APP_DISPLAY_TIMEZONE=Asia/Jakarta` — hanya untuk tampilan.
- Gunakan `App\Support\LocalTime` saat memformat waktu untuk pengguna.
- Jangan mengubah `APP_TIMEZONE` ke Asia/Jakarta pada data lama; timestamp
  akan bergeser 7 jam.

## 8. Smoke test setelah deploy

1. `GET /up` mengembalikan HTTP 200.
2. Halaman `/login` tampil dan login admin berhasil.
3. Dashboard, `/students`, dan `/users` dapat dibuka sesuai role.
4. Worker memproses satu `QueueHealthCheckJob` di setiap queue.
5. `php artisan schedule:list` menampilkan backup harian.
6. Backup baru muncul di `storage/app/private/backups/` (atau
   `storage/app/backups/` sesuai konfigurasi disk) dan terupload bila Drive aktif.

## 9. Backup dan restore

Backup manual:

```bash
php artisan backup:run --keep=7
```

Backup membutuhkan `mysqldump` di `PATH`. Untuk MySQL 8, pastikan user backup
memiliki hak `SELECT`, `LOCK TABLES`, `SHOW VIEW`, dan `TRIGGER`.

Rehearsal restore (jangan lakukan di production):

```bash
# 1. Buat database tujuan
mysql -u root -p -e "CREATE DATABASE pintu_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Restore dump
mysql -u root -p pintu_restore < storage/app/backups/backup-YYYYMMDD-HHMMSS.sql

# 3. Verifikasi jumlah tabel dan data kunci
mysql -u root -p -N -e "
  SELECT 'tables', COUNT(*) FROM information_schema.tables WHERE table_schema='pintu_restore'
  UNION ALL SELECT 'users', COUNT(*) FROM pintu_restore.users
  UNION ALL SELECT 'students', COUNT(*) FROM pintu_restore.students
  UNION ALL SELECT 'subjects', COUNT(*) FROM pintu_restore.subjects;"
```

Catat waktu backup dan waktu restore aktual sebagai RPO/RTO. Backup harian
memberi RPO maksimal 24 jam; RTO bergantung ukuran database dan kecepatan
disk. Pada rehearsal 19 November 2026, dump database uji 68 KB dan restore
ke MySQL 8 selesai dalam ±3 detik. Angka production harus diukur ulang.

## 10. Urutan deploy dan rollback

Urutan deploy:

```bash
php artisan down --retry=60
git fetch --all && git checkout <tag>
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Rollback aplikasi:

```bash
php artisan down
git checkout <tag-sebelumnya>
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate:rollback --step=1   # hanya bila migrasi terakhir reversible
npm ci && npm run build
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Bila rollback migrasi tidak aman (misalnya kolom UUID berisi data), pulihkan
dari backup yang sudah diverifikasi, bukan memaksa `migrate:rollback`.

## 11. CI

`.github/workflows/ci.yml` menjalankan:

- `composer validate`
- `composer install`
- `pint --test`
- `php artisan test` di SQLite
- `migrate:fresh --seed` + test di MySQL 8 dengan Redis
- Integration test antrean Redis nyata (`QUEUE_CONNECTION=redis` pada `tests/Feature/RedisQueueOutboxIntegrationTest.php`)
- `npm ci` + `npm run build`

CI harus hijau sebelum merge. Untuk menjalankan pemeriksaan yang sama lokal:

```bash
php composer.phar validate --no-check-publish
./vendor/bin/pint --test app config database routes tests bootstrap/app.php
php artisan test
npm ci && npm run build
```

## 12. Higiene data

File PII/scratch (PDF scan, Excel administrasi, `test_*.php`, `scratch.json`)
tidak boleh berada di working tree aplikasi maupun repository. Simpan di
direktori terpisah di luar repo dengan akses terbatas. `.gitignore` sudah
menutup pola umum, tetapi tetap periksa `git status` sebelum commit.

## 13. Branch protection `main` (WP0.5.11)

Status: **aktif dan terverifikasi** via `gh api repos/adnrbaonks/Pintu-Madrasah/branches/main/protection`:

- Pull request wajib sebelum merge (stale approval di-dismiss, conversation harus resolved).
- Required status checks (strict/up-to-date): `Validate & format`, `Tests (SQLite)`, `Tests (MySQL 8 + Redis)`, `Build frontend assets`.
- Force push dan penghapusan branch diblokir; berlaku juga untuk administrator.
- Jumlah approval wajib = 0 (repo satu maintainer); naikkan bila tim bertambah.

## 14. Catatan deploy WP0.5.12

Jalankan `php artisan db:seed --class=RolesAndPermissionsSeeder --force` agar permission `reconcile_emis` terbentuk dan diberikan ke operator/admin. Tanpa itu, rekonsiliasi dari sesi akan ditolak 403. Klien yang membaca `data.items` dari respons reconcile harus pindah ke endpoint `/reconciliation-batches/{batch}/items`.

## 14. WP0.5.13 — Token ability & import staging

1. `php artisan migrate` (kolom `abilities`, `import_session_id`).
2. Token integrasi lama menjadi read-only. Terbitkan ulang token bookmarklet EMIS dengan ability yang dibutuhkan (`staging:write`, `credentials:update`, dst.) lalu cabut yang lama.
3. Frontend/klien yang membaca `emis_gtk` dari `/api/emis/status` harus pindah ke `/api/emis-gtk/status`.
4. Import GTK memerlukan cache driver yang mendukung lock atomik (database/redis).

## 15. WP0.5.13.1 — Sesi import GTK

1. `php artisan migrate` (tabel `emis_import_sessions`, `emis_import_session_pages`).
2. Klien import GTK harus mengirim `import_session_id` (dari respons halaman 1) pada halaman berikutnya. Klien lama yang mengirim halaman > 1 tanpa ID mendapat `422 IMPORT_SESSION_REQUIRED`.
3. Pemulihan: ulangi import dari halaman 1. Staging aktif tidak berubah sampai seluruh halaman valid diterima. Bersihkan sesi lama bila perlu: `DELETE FROM emis_import_sessions WHERE status <> 'open' AND updated_at < NOW() - INTERVAL 30 DAY`.
4. Lock finalisasi (`emis_gtk:import:finalize`) memerlukan cache driver dengan lock atomik (database/redis).
5. Matriks ability token tidak berubah dari WP0.5.13 (lihat `docs/api/CHANGELOG.md`); import butuh `staging:write`, `clear_staging` butuh `staging:write`, commit dan PATCH task tetap hanya untuk sesi + permission.
