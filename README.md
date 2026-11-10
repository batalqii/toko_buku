# PustakaKita — Website Toko Buku Online

Website toko buku dengan PHP native, MySQL, HTML, CSS, dan JavaScript. Cocok untuk dijalankan secara lokal melalui XAMPP.

## Fitur
- Beranda dan katalog buku
- Pencarian berdasarkan judul, penulis, dan kategori
- Halaman detail buku dan ulasan
- Keranjang belanja dan checkout
- Registrasi, login, profil, serta riwayat pesanan
- Dashboard admin untuk menambah/mengedit/menonaktifkan buku, mengelola stok, dan mengubah status pesanan
- Password hashing, prepared statements PDO, session, CSRF token, dan pemeriksaan akses admin

## Cara menjalankan di XAMPP
1. Ekstrak folder `toko_buku_online` ke `C:\\xampp\\htdocs\\toko_buku_online`.
2. Buka XAMPP Control Panel lalu jalankan **Apache** dan **MySQL**.
3. Buka `http://localhost/phpmyadmin`.
4. Pilih menu **Import**, lalu impor file `database.sql`.
5. Buka `http://localhost/toko_buku_online/`.

Jika username/password MySQL lokalmu berbeda, sesuaikan `config.php`.

## Membuat akun admin
1. Daftar melalui halaman **Akun → Daftar sekarang**.
2. Buka phpMyAdmin → database `toko_buku` → tabel `users` → tab SQL.
3. Jalankan query berikut dengan email yang kamu daftarkan:

```sql
UPDATE users SET role = 'admin' WHERE email = 'emailkamu@example.com';
```

4. Keluar lalu masuk kembali. Menu **Dashboard Admin** akan muncul.

## Catatan penting
- Ini adalah starter project/demo, bukan sistem produksi yang sudah diaudit.
- Pembayaran masih simulasi; tidak ada uang yang benar-benar ditransaksikan. Untuk pembayaran nyata, integrasikan payment gateway resmi dan verifikasi callback di server.
- Form sampul buku menerima URL gambar. Untuk produksi, implementasikan unggah file yang divalidasi.
- Sebelum dipasang di internet, tambahkan HTTPS, rate limiting login, validasi dan otorisasi lebih ketat, kebijakan privasi, backup, serta pengujian keamanan.
- Perubahan status pesanan di admin belum otomatis mengembalikan stok saat status pesanan diubah menjadi Dibatalkan; tangani pembatalan dan pengembalian stok secara transaksional sebelum digunakan untuk transaksi nyata.
