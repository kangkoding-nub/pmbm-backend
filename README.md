# Backend Deployment - PMBM Yayasan Darul Hikmah

Panduan ini menjelaskan alur deployment untuk backend aplikasi PMBM Yayasan Darul Hikmah.

## Prasyarat
- PHP >= 8.4
- MySQL / MariaDB
- Composer
- Git

## Alur Deployment (Manual)

1. **Clone Repository**
   ```bash
   git clone <repository-url> backend
   cd backend
   ```

2. **Setup Environment**
   Salin file `.env.example` menjadi `.env` dan sesuaikan konfigurasinya (Database, WhatsApp Service, dll).
   ```bash
   cp .env.example .env
   ```

3. **Install Dependensi**
   Gunakan instruksi berikut untuk menginstall library yang diperlukan secara optimal untuk production.
   ```bash
   composer install --no-interaction --optimize-autoloader --no-dev
   ```

4. **Generate App Key**
   ```bash
   php artisan key:generate
   ```

5. **Storage Link**
   Buat link simbolik untuk akses file publik.
   ```bash
   php artisan storage:link
   ```

6. **Migrasi Database**
   Jalankan migrasi untuk membuat tabel yang diperlukan.
   ```bash
   php artisan migrate --force
   ```

## Upgrade dari Versi Sebelum Hardening Keamanan

Bagian ini wajib dibaca jika Anda menarik update yang membawa perubahan keamanan
(password hashing, role middleware, file privat, dll). Ikuti urutan langkahnya
agar pengguna lama tidak terkunci dan file pendaftar lama tetap dapat diakses.

### 1. Tarik kode dan install dependensi

```bash
git pull
composer install --no-interaction --optimize-autoloader --no-dev
```

### 2. Konfigurasi `.env` baru

Tambahkan / perbarui variabel berikut di `.env`:

```env
# WAJIB di production
APP_DEBUG=false

# Frontend SPA (boleh comma-separated untuk multi domain)
FRONTEND_URL=https://app.example.com

# Sanctum SPA (kosongkan jika frontend pakai Bearer token saja)
SANCTUM_STATEFUL_DOMAINS=app.example.com
```

Lalu refresh cache konfigurasi:

```bash
php artisan config:clear
php artisan cache:clear
```

### 3. Migrasi password lama → bcrypt

Pengguna existing pasti tersimpan dengan format `Crypt::encryptString` lama.
Mereka tetap bisa login (sistem akan rehash otomatis di login pertama),
namun untuk men-tutup akses lama secara serentak jalankan command bulk:

```bash
# Preview lebih dulu
php artisan users:rehash-passwords --dry-run

# Eksekusi sungguhan
php artisan users:rehash-passwords
```

Command bersifat idempoten — aman dijalankan ulang.

### 4. Migrasi file pendaftar ke disk privat

Berkas KK / KTP / akta / ijazah / SKL / KIP / pas foto / sertifikat prestasi
sebelumnya berada di `storage/app/public/images/...` dan dapat diakses
oleh siapa saja yang mengetahui URL-nya. Setelah hardening, berkas ini
disajikan lewat **signed URL** yang berlaku 10 menit dan dilindungi
ownership check.

```bash
# Preview rencana migrasi
php artisan students:migrate-file-storage --dry-run

# Eksekusi (pindah file fisik + update path di DB)
php artisan students:migrate-file-storage
```

Command ini idempoten dan membaca file yang sudah dipindahkan akan
di-skip otomatis. Setelah selesai dan diverifikasi, folder
`storage/app/public/images/files/` dan `images/achievement/` boleh
dihapus karena sudah kosong.

> [!IMPORTANT]
> Pastikan direktori `storage/app/student-files/` dapat ditulis oleh
> user web server (`www-data`). Buat dulu sebelum menjalankan command:
> ```bash
> sudo -u www-data mkdir -p storage/app/student-files
> ```

### 5. Verifikasi

```bash
# Pastikan endpoint sensitif punya middleware role
php artisan route:list -v --path=api/v1/user
php artisan route:list -v --path=api/v1/whatsapp
php artisan route:list -v --path=api/v1/announcement

# Pastikan endpoint signed download terdaftar
php artisan route:list --path=api/v1/student/file
```

Manual smoke test (dengan akun pendaftar role 4):
- Login → ambil token Bearer
- `GET /api/v1/student/file/{id}` milik diri sendiri → 200, URL signed
- `GET /api/v1/student/file/{id}` milik pendaftar lain → 403
- Buka URL signed → file ter-stream
- Tunggu 11 menit → buka URL yang sama → 403

## Konfigurasi Queue Worker (Persistent Service)

Untuk production, menjalankan `queue:work` via cronjob kurang efisien karena prosesnya akan terus mati dan hidup. Disarankan menggunakan **process manager** agar worker selalu berjalan dan otomatis restart jika server melakukan reboot.

### Opsi 1: Menggunakan Systemd (Rekomendasi Built-in Linux)

Cara ini paling stabil karena tidak memerlukan instalasi software tambahan di kebanyakan distro modern (Ubuntu, Debian, dll).

1. Buat file service baru:
   ```bash
   sudo nano /etc/systemd/system/laravel-worker.service
   ```

2. Masukkan konfigurasi berikut (sesuaikan path):
   ```ini
   [Unit]
   Description=Laravel Queue Worker
   After=network.target mysql.service

   [Service]
   User=www-data
   Group=www-data
   Restart=always
   ExecStart=/usr/bin/php /var/www/html/backend/artisan queue:work --tries=3 --timeout=90
   WorkingDirectory=/var/www/html/backend

   [Install]
   WantedBy=multi-user.target
   ```

3. Jalankan dan aktifkan agar start otomatis saat boot:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable laravel-worker.service
   sudo systemctl start laravel-worker.service
   ```

### Opsi 2: Menggunakan Supervisor

Jika Anda lebih suka menggunakan Supervisor:

1. Install supervisor:
   ```bash
   sudo apt-get install supervisor
   ```

2. Konfigurasi worker:
   ```bash
   sudo nano /etc/supervisor/conf.d/laravel-worker.conf
   ```

3. Masukkan konfigurasi:
   ```ini
   [program:laravel-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /var/www/html/backend/artisan queue:work --tries=3 --timeout=90
   autostart=true
   autorestart=true
   user=www-data
   numprocs=1
   redirect_stderr=true
   stdout_logfile=/var/www/html/backend/storage/logs/worker.log
   ```

4. Update dan jalankan:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start laravel-worker:*
   ```

> [!IMPORTANT]
> Ganti `/var/www/html/backend` dengan path absolut ke project Anda. Pastikan pula user `www-data` memiliki izin akses ke folder tersebut.
