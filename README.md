# 🛠️ ToolLend — Sistem Peminjaman Alat

Sistem peminjaman alat berbasis web dengan 3 level akses (Admin, Petugas, User) dan desain modern dark glassmorphism.

---

## 📁 Struktur Folder

```
peminjaman-alat/
├── api/
│   ├── alat.php            # API CRUD alat
│   ├── peminjaman.php      # API peminjaman & update status
│   ├── notifikasi.php      # API notifikasi
│   └── user.php            # API CRUD user
├── css/
│   └── style.css           # Master stylesheet
├── inc/
│   ├── header.php          # Include navbar & head
│   └── footer.php          # Include footer & scripts
├── js/
│   └── main.js             # JavaScript utama
├── database.sql            # Script database MySQL
├── config.php              # Konfigurasi DB & helper functions
├── login.php               # Halaman login
├── logout.php              # Logout handler
├── dashboard.php           # Dashboard utama
├── alat.php                # Katalog alat (browsing & pinjam)
├── peminjaman.php          # Daftar peminjaman
├── kelola-alat.php         # Kelola alat (Admin/Petugas)
├── kelola-user.php         # Kelola user (Admin)
├── laporan.php             # Laporan & statistik (Admin)
├── profil.php              # Profil pengguna
├── setup_passwords.php     # Setup awal (jalankan 1x)
└── README.md               # File ini
```

---

## ⚙️ Cara Setup

### 1. Persiapkan Server
Pastikan server lokal Anda sudah berjalan (XAMPP / MAMP / Laragon) dengan **PHP** dan **MySQL**.

### 2. Import Database
- Buka **phpMyAdmin** atau MySQL client Anda
- Buat database baru atau gunakan yang sudah ada
- Import file `database.sql`

### 3. Konfigurasi Koneksi
Buka file `config.php` dan sesuaikan:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'peminjaman_alat');   // nama database Anda
define('DB_USER', 'root');              // username MySQL
define('DB_PASS', '');                  // password MySQL
```

### 4. Jalankan Setup Password (PENTING!)
Buka browser dan akses:
```
http://localhost/peminjaman-alat/setup_passwords.php
```
Script ini akan menghasilkan password hash yang benar untuk akun demo.

### 5. Akses Aplikasi
```
http://localhost/peminjaman-alat/login.php
```

---

## 🔑 Akun Demo

| Role     | Username  | Password    |
|----------|-----------|-------------|
| Admin    | admin     | admin123    |
| Petugas  | petugas   | petugas123  |
| User     | user      | user123     |

---

## 📋 Fitur per Role

### 👑 Admin
- Dashboard dengan statistik lengkap
- Browsing & pinjam alat
- Kelola alat (tambah/edit/hapus)
- Kelola user (tambah/edit/hapus)
- Approve/tolak/kelola semua peminjaman
- Laporan & log aktivitas
- Profil & ubah password

### 🔧 Petugas
- Dashboard
- Browsing & pinjam alat
- Kelola alat (tambah/edit/hapus)
- Approve/tolak/kelola peminjaman
- Profil & ubah password

### 👤 User
- Dashboard pribadi
- Browsing katalog alat
- Ajukan permintaan pinjam alat
- Lihat status peminjaman sendiri
- Profil & ubah password
- Notifikasi real-time

---

## 🌟 Fitur Utama
- **3 Level Akses** — Admin, Petugas, User dengan kontrol akses berbasis role
- **Glassmorphism UI** — Desain modern dark theme dengan efek blur & transparansi
- **Notifikasi Real-time** — Sistem notifikasi untuk setiap perubahan status
- **Search & Filter** — Pencarian dan filter kategori di semua halaman
- **Modal System** — Form CRUD menggunakan modal elegan tanpa page reload
- **Responsive** — Cocok untuk desktop dan mobile
- **Log Aktivitas** — Pencatatan semua aktivitas pengguna
- **Validasi Lengkap** — Validasi client & server side
- **Status Workflow** — Menunggu → Disetujui → Dipinjam → Dikembalikan
- **Stok Management** — Otomatis kurang/tambah stok saat pinjam/dikembalikan
