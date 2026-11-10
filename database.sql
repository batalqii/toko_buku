CREATE DATABASE IF NOT EXISTS toko_buku CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE toko_buku;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS books (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  author VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  isbn VARCHAR(20) DEFAULT NULL,
  description TEXT,
  price INT UNSIGNED NOT NULL DEFAULT 0,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  cover VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_books_title (title),
  INDEX idx_books_category (category),
  UNIQUE KEY uq_books_isbn (isbn)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  recipient VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  address TEXT NOT NULL,
  payment_method ENUM('transfer','ewallet','cod') NOT NULL DEFAULT 'transfer',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Menunggu pembayaran','Diproses','Dikirim','Selesai','Dibatalkan') NOT NULL DEFAULT 'Menunggu pembayaran',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NULL,
  book_title VARCHAR(200) NOT NULL,
  price INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_book_review (user_id, book_id),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO books (title,author,category,description,price,stock) VALUES
('Filosofi Teras','Henry Manampiring','Pengembangan Diri','Pengantar filsafat Stoa yang membahas cara mengelola emosi dan menghadapi hal-hal di luar kendali kita.',98000,14),
('Laut Bercerita','Leila S. Chudori','Fiksi','Novel tentang persahabatan, keluarga, kehilangan, dan perjuangan di tengah situasi politik Indonesia.',105000,9),
('Atomic Habits','James Clear','Pengembangan Diri','Panduan membangun kebiasaan baik melalui perubahan kecil yang dilakukan secara konsisten.',115000,12),
('Bumi','Tere Liye','Fiksi','Petualangan fantasi Raib, Seli, dan Ali membuka pintu menuju dunia paralel yang penuh misteri.',95000,16),
('Laskar Pelangi','Andrea Hirata','Fiksi','Kisah anak-anak Belitung yang mengejar pendidikan dan mimpi di tengah keterbatasan.',89000,8),
('Berani Tidak Disukai','Ichiro Kishimi & Fumitake Koga','Psikologi','Percakapan filosofis tentang kebebasan, hubungan sosial, dan keberanian menjalani hidup sendiri.',92000,7),
('Matematika SMA Kelas XI','Tim Edukasi','Pendidikan','Buku pendamping untuk memahami konsep matematika SMA dengan contoh dan latihan.',78000,20),
('Sebuah Seni untuk Bersikap Bodo Amat','Mark Manson','Pengembangan Diri','Perspektif tentang menentukan prioritas dan menerima keterbatasan hidup secara lebih realistis.',99000,10),
('Perahu Kertas','Dee Lestari','Fiksi','Novel tentang mimpi, cinta, persahabatan, dan perjalanan menemukan diri sendiri.',88000,11),
('Dasar-Dasar Pemrograman Web','Tim Teknologi','Teknologi','Pengenalan HTML, CSS, JavaScript, dan konsep dasar membangun situs web.',125000,6),
('Negeri 5 Menara','Ahmad Fuadi','Fiksi','Kisah persahabatan dan semangat meraih cita-cita yang berawal dari sebuah pesantren.',97000,13),
('Belajar Bahasa Inggris Praktis','Tim Edukasi','Pendidikan','Materi dan latihan dasar untuk memperkuat kosakata serta tata bahasa Inggris.',72000,18);
