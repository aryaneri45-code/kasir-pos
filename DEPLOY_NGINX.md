# Panduan Deploy ke Nginx

## 1. Copy project ke server

Salin folder ini ke direktori web server, misalnya:

```bash
sudo cp -r /var/www/kasir-pos /var/www/
```

Atau jika menggunakan folder lain:

```bash
sudo mkdir -p /var/www/kasir-pos
sudo cp -r . /var/www/kasir-pos/
```

## 2. Copy konfigurasi Nginx

File konfigurasi sudah tersedia di:

- nginx/kasir-pos.conf

Gunakan perintah berikut untuk mengaktifkan:

```bash
sudo cp /var/www/kasir-pos/nginx/kasir-pos.conf /etc/nginx/conf.d/kasir-pos.conf
sudo nginx -t
sudo systemctl reload nginx
```

## 3. Verifikasi

Buka browser ke:

```bash
http://alamat-server/
```

Jika konfigurasi benar, halaman login kasir akan tampil.

## 4. Catatan penting

- file utama aplikasi adalah index.kasir.html
- file index.html hanya sebagai redirect agar root URL bisa terbuka
- untuk domain custom, ubah `server_name` menjadi nama domain Anda

## 5. Contoh konfigurasi domain

```nginx
server {
    listen 80;
    server_name kasir.example.com;
    root /var/www/kasir-pos;
    index index.kasir.html;

    location / {
        try_files $uri $uri/ /index.kasir.html;
    }
}
```

## 6. Jika memakai HTTPS
Tambahkan blok SSL di Nginx dengan sertifikat Let's Encrypt.
