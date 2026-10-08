# POS Kasir

Aplikasi Point of Sale (POS) modern untuk toko atau warung kecil yang membutuhkan sistem kasir, manajemen produk, laporan penjualan, dan kontrol akses admin/cashier dalam satu platform yang sederhana namun lengkap.

## Overview

POS Kasir adalah aplikasi web berbasis PHP dan MySQL yang dirancang untuk mencatat transaksi penjualan secara cepat, aman, dan mudah dikelola. Aplikasi ini dibuat dengan fokus pada kebutuhan operasional harian seperti:

- transaksi penjualan
- pengelolaan stok barang
- laporan harian dan bulanan
- riwayat transaksi
- kontrol admin dan kasir
- cetak struk pelanggan
- manajemen produk dan harga

## Features

### Penjualan
- pencarian dan pemilihan produk
- keranjang belanja dinamis
- penghitungan total otomatis
- pembayaran tunai
- otomatisasi kembalian
- validasi stok saat transaksi

### Admin / Owner
- riwayat transaksi terpisah
- laporan harian dan bulanan
- export data ke CSV/PDF
- manajemen menu dan harga
- tambah, ubah, dan nonaktifkan produk
- kontrol akses berdasarkan role

### Keamanan dan Stabilitas
- autentikasi login berbasis token
- role-based access control
- pemisahan route untuk multi-site deployment
- konfigurasi Nginx yang aman untuk deployment VPS
- zona waktu Asia/Jakarta untuk data transaksi

## Tech Stack

- PHP 8.3
- MySQL / MariaDB
- Nginx
- HTML5
- CSS3
- JavaScript Vanilla
- JWT-style token session

## Project Structure

```bash
kasir-pos/
├── api/
│   ├── admin.php
│   ├── bootstrap.php
│   ├── login.php
│   ├── products.php
│   ├── reports.php
│   └── transactions.php
├── assets/
│   ├── css/
│   └── js/
├── nginx/
│   └── kasir-pos.conf
├── scripts/
│   └── backup-db.sh
├── static/
├── storage/
├── admin.html
├── DEPLOY_NGINX.md
├── index.html
├── index.kasir.html
├── .gitignore
├── README.md
└── vercel.json
```

## Setup

### Prasyarat
- PHP 8.3+
- MySQL / MariaDB
- Nginx
- akses ke VPS atau server web lokal

### Database
Buat database baru dan sesuaikan konfigurasi koneksi sesuai kebutuhan environment Anda.

### Environment
Simpan semua credential database dan secret aplikasi pada environment variable atau file konfigurasi lokal yang tidak dipublikasikan ke repositori.

## Quick Start

1. Clone repository.
2. Konfigurasikan koneksi database dan secret aplikasi.
3. Jalankan aplikasi di server web Anda.
4. Login menggunakan akun yang dibuat sesuai konfigurasi environment.
5. Mulai transaksi dan kelola laporan dari admin panel.

## Admin Features

Hanya admin/owner yang dapat mengakses fitur berikut:

- riwayat transaksi
- laporan harian dan bulanan
- export data laporan
- manajemen produk
- perubahan stok dan harga
- pengaturan menu aktif/tidak aktif

## Deployment Notes

Aplikasi ini sudah disiapkan untuk deployment di VPS dengan Nginx. Struktur route dibuat agar tidak bertabrakan dengan website lain di server yang sama.

Beberapa hal penting untuk deployment produksi:

- set timezone ke Asia/Jakarta
- aktifkan PHP-FPM
- aktifkan Nginx
- aktifkan MySQL/MariaDB
- siapkan backup database otomatis
- setup HTTPS setelah membeli domain

## Security Recommendation

Untuk deployment publik, pastikan:

- tidak mempublikasikan credential database
- tidak mempublikasikan token secret atau file konfigurasi sensitif
- menggunakan HTTPS
- membatasi akses admin hanya ke perangkat yang aman
- membuat backup rutin

## License

Project ini dibuat untuk kebutuhan internal bisnis dan pengembangan lebih lanjut sesuai kebutuhan operasional.

## Author

Aryaneri
