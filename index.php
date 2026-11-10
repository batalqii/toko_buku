<?php
require __DIR__ . '/config.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home','catalog','detail','cart','checkout','login','register','account','logout','admin','admin_books','admin_orders'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

function normalize_isbn(string $value): string {
    return strtoupper(preg_replace('/[^0-9X]/', '', $value) ?? '');
}

function http_get_json(string $url): ?array {
    $json = @file_get_contents($url);
    if ($json !== false) {
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    if (function_exists('shell_exec') && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = 'curl.exe -L --silent --show-error --max-time 12 ' . escapeshellarg($url);
        $json = @shell_exec($command);
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        }
    }
    return null;
}

function fetch_isbn_metadata(string $isbn): ?array {
    $googleUrl = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . urlencode($isbn);
    $data = http_get_json($googleUrl);
    if (!empty($data['items'][0]['volumeInfo'])) {
        $book = $data['items'][0]['volumeInfo'];
        $cover = $book['imageLinks']['thumbnail'] ?? $book['imageLinks']['smallThumbnail'] ?? '';
        $cover = str_replace('http://', 'https://', $cover);
        return [
            'title' => trim((string)($book['title'] ?? '')),
            'author' => trim(implode(', ', $book['authors'] ?? [])),
            'category' => trim((string)($book['categories'][0] ?? 'Umum')),
            'description' => trim((string)($book['description'] ?? '')),
            'cover' => $cover,
        ];
    }

    $openLibraryUrl = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn) . '&jscmd=data&format=json';
    $data = http_get_json($openLibraryUrl);
    $book = $data['ISBN:' . $isbn] ?? null;
    if ($book) {
        $authors = [];
        foreach ($book['authors'] ?? [] as $author) $authors[] = $author['name'] ?? '';
        return [
            'title' => trim((string)($book['title'] ?? '')),
            'author' => trim(implode(', ', array_filter($authors))),
            'category' => trim((string)($book['subjects'][0]['name'] ?? $book['subjects'][0] ?? 'Umum')),
            'description' => '',
            'cover' => trim((string)($book['cover']['large'] ?? $book['cover']['medium'] ?? $book['cover']['small'] ?? '')),
        ];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash('Isi nama dan email yang valid. Kata sandi minimal 8 karakter.');
            redirect('index.php?page=register');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'customer']);
            $_SESSION['user'] = ['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'email'=>$email,'role'=>'customer'];
            flash('Akun berhasil dibuat. Selamat datang!');
            redirect('index.php?page=account');
        } catch (PDOException $e) {
            flash('Email sudah terdaftar atau data tidak valid.');
            redirect('index.php?page=register');
        }
    }

    if ($action === 'admin_lookup_isbn') {
        require_admin();
        $isbn = normalize_isbn($_POST['isbn'] ?? '');
        if (!preg_match('/^(?:[0-9]{13}|[0-9]{9}[0-9X])$/', $isbn)) {
            flash('ISBN tidak valid. Gunakan ISBN-10 atau ISBN-13 yang terdiri dari angka dan, khusus ISBN-10, boleh diakhiri X.');
            redirect('index.php?page=admin_books');
        }

        $metadata = fetch_isbn_metadata($isbn);
        if (!$metadata || $metadata['title'] === '') {
            flash('Data buku untuk ISBN ' . e($isbn) . ' tidak ditemukan. Anda tetap dapat mengisi form secara manual.');
            $_SESSION['isbn_lookup'] = ['isbn' => $isbn];
        } else {
            $metadata['isbn'] = $isbn;
            $_SESSION['isbn_lookup'] = $metadata;
            flash('Data buku berhasil diambil melalui ISBN. Periksa dan simpan ke database.');
        }
        redirect('index.php?page=admin_books');
    }

    if ($action === 'admin_import_isbn') {
        require_admin();
        $isbn = normalize_isbn($_POST['isbn'] ?? '');
        if (!preg_match('/^(?:[0-9]{13}|[0-9]{9}[0-9X])$/', $isbn)) {
            flash('ISBN tidak valid. Gunakan ISBN-10 atau ISBN-13.');
            redirect('index.php?page=admin_books');
        }
        $metadata = fetch_isbn_metadata($isbn);
        if (!$metadata || $metadata['title'] === '') {
            flash('Data buku tidak ditemukan untuk ISBN tersebut, sehingga belum disimpan.');
            redirect('index.php?page=admin_books');
        }
        try {
            $check = $pdo->prepare('SELECT id FROM books WHERE isbn=? LIMIT 1');
            $check->execute([$isbn]);
            if ($check->fetch()) throw new RuntimeException('Buku dengan ISBN tersebut sudah ada di database.');
            $title = $metadata['title'];
            $author = $metadata['author'] !== '' ? $metadata['author'] : 'Penulis tidak tersedia';
            $category = $metadata['category'] !== '' ? $metadata['category'] : 'Umum';
            $description = $metadata['description'];
            $cover = $metadata['cover'];
            $pdo->prepare('INSERT INTO books (title,author,category,isbn,description,price,stock,cover,is_active) VALUES (?,?,?,?,?,?,?,?,1)')
                ->execute([$title,$author,$category,$isbn,$description,0,0,$cover !== '' ? $cover : null]);
            unset($_SESSION['isbn_lookup']);
            flash('Data buku berhasil diambil dan disimpan ke database otomatis via ISBN.');
        } catch (Throwable $ex) {
            flash('Buku gagal disimpan: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'periksa koneksi database.'));
        }
        redirect('index.php?page=admin_books');
    }

    if ($action === 'login') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare('SELECT id,name,email,password,role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
            flash('Berhasil masuk.');
            redirect($user['role'] === 'admin' ? 'index.php?page=admin' : 'index.php?page=account');
        }
        flash('Email atau kata sandi salah.');
        redirect('index.php?page=login');
    }

    if ($action === 'add_cart') {
        $id = (int)($_POST['book_id'] ?? 0);
        $qty = max(1, min(20, (int)($_POST['qty'] ?? 1)));
        $stmt = $pdo->prepare('SELECT id,stock FROM books WHERE id=? AND is_active=1');
        $stmt->execute([$id]);
        $book = $stmt->fetch();
        if (!$book || (int)$book['stock'] < 1) {
            flash('Buku sedang tidak tersedia.');
        } else {
            $_SESSION['cart'] ??= [];
            $current = (int)($_SESSION['cart'][$id] ?? 0);
            $_SESSION['cart'][$id] = min((int)$book['stock'], $current + $qty);
            flash('Buku ditambahkan ke keranjang.');
        }
        redirect('index.php?page=cart');
    }

    if ($action === 'update_cart') {
        foreach (($_POST['qty'] ?? []) as $id => $qty) {
            $id = (int)$id; $qty = (int)$qty;
            if ($qty <= 0) { unset($_SESSION['cart'][$id]); continue; }
            $stmt = $pdo->prepare('SELECT stock FROM books WHERE id=?');
            $stmt->execute([$id]); $row = $stmt->fetch();
            if ($row) $_SESSION['cart'][$id] = min(max(1, $qty), (int)$row['stock']);
        }
        flash('Keranjang diperbarui.');
        redirect('index.php?page=cart');
    }

    if ($action === 'remove_cart') {
        unset($_SESSION['cart'][(int)($_POST['book_id'] ?? 0)]);
        flash('Buku dihapus dari keranjang.');
        redirect('index.php?page=cart');
    }

    if ($action === 'checkout') {
        require_login();
        $cart = $_SESSION['cart'] ?? [];
        if (!$cart) { flash('Keranjang masih kosong.'); redirect('index.php?page=cart'); }
        $recipient = trim($_POST['recipient'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $payment = $_POST['payment'] ?? 'transfer';
        if ($recipient === '' || $phone === '' || $address === '' || !in_array($payment, ['transfer','cod','ewallet'], true)) {
            flash('Lengkapi data pengiriman dan pilih metode pembayaran.');
            redirect('index.php?page=checkout');
        }
        try {
            $pdo->beginTransaction();
            $items = []; $total = 0;
            $ids = array_map('intval', array_keys($cart));
            $stmt = $pdo->prepare('SELECT id,title,price,stock FROM books WHERE id=? AND is_active=1 FOR UPDATE');
            foreach ($cart as $id => $qty) {
                $stmt->execute([(int)$id]); $book = $stmt->fetch();
                if (!$book || (int)$book['stock'] < (int)$qty) throw new RuntimeException('Stok buku tidak mencukupi: ' . ($book['title'] ?? 'buku'));
                $items[] = ['book'=>$book,'qty'=>(int)$qty];
                $total += (int)$book['price'] * (int)$qty;
            }
            $stmt = $pdo->prepare('INSERT INTO orders (user_id,recipient,phone,address,payment_method,total,status) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([current_user()['id'],$recipient,$phone,$address,$payment,$total,'Menunggu pembayaran']);
            $orderId = (int)$pdo->lastInsertId();
            foreach ($items as $item) {
                $b = $item['book']; $q = $item['qty'];
                $pdo->prepare('INSERT INTO order_items (order_id,book_id,book_title,price,quantity) VALUES (?,?,?,?,?)')
                    ->execute([$orderId,$b['id'],$b['title'],$b['price'],$q]);
                $pdo->prepare('UPDATE books SET stock=stock-? WHERE id=?')->execute([$q,$b['id']]);
            }
            $pdo->commit();
            $_SESSION['cart'] = [];
            flash("Pesanan #$orderId berhasil dibuat. Ini adalah demo checkout, pembayaran belum diproses otomatis.");
            redirect('index.php?page=account');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Pesanan gagal dibuat. Silakan coba lagi.');
            redirect('index.php?page=checkout');
        }
    }

    if ($action === 'review') {
        require_login();
        $bookId = (int)($_POST['book_id'] ?? 0);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $comment = trim($_POST['comment'] ?? '');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.user_id=? AND oi.book_id=? AND o.status IN ("Diproses","Dikirim","Selesai")');
        $stmt->execute([current_user()['id'], $bookId]);
        if ((int)$stmt->fetchColumn() < 1) {
            flash('Ulasan hanya dapat diberikan untuk buku yang pernah dipesan dan diproses.');
        } elseif ($comment === '') {
            flash('Komentar ulasan tidak boleh kosong.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO reviews (user_id,book_id,rating,comment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)');
            $stmt->execute([current_user()['id'],$bookId,$rating,$comment]);
            flash('Ulasan berhasil disimpan.');
        }
        redirect('index.php?page=detail&id='.$bookId);
    }

    if ($action === 'admin_add_book' || $action === 'admin_update_book') {
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $isbn = normalize_isbn($_POST['isbn'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = max(0, (int)($_POST['price'] ?? 0));
        $stock = max(0, (int)($_POST['stock'] ?? 0));
        $cover = trim($_POST['cover'] ?? '');
        if ($title === '' || $author === '' || $category === '') {
            flash('Judul, penulis, dan kategori wajib diisi.');
            redirect('index.php?page=admin_books');
        }
        if ($isbn !== '' && !preg_match('/^(?:[0-9]{13}|[0-9]{9}[0-9X])$/', $isbn)) {
            flash('ISBN tidak valid. Gunakan ISBN-10 atau ISBN-13 yang terdiri dari angka dan, khusus ISBN-10, boleh diakhiri X.');
            redirect('index.php?page=admin_books');
        }
        if ($cover !== '' && !filter_var($cover, FILTER_VALIDATE_URL)) {
            flash('URL gambar sampul tidak valid. Gunakan alamat yang diawali https:// atau http://');
            redirect('index.php?page=admin_books');
        }
        try {
            if ($action === 'admin_add_book') {
                if ($isbn !== '') {
                    $check = $pdo->prepare('SELECT id FROM books WHERE isbn=? LIMIT 1');
                    $check->execute([$isbn]);
                    if ($check->fetch()) throw new RuntimeException('Buku dengan ISBN tersebut sudah ada di database.');
                }
                $pdo->prepare('INSERT INTO books (title,author,category,isbn,description,price,stock,cover,is_active) VALUES (?,?,?,?,?,?,?,?,1)')
                    ->execute([$title,$author,$category,$isbn !== '' ? $isbn : null,$description,$price,$stock,$cover !== '' ? $cover : null]);
                unset($_SESSION['isbn_lookup']);
                flash('Buku baru berhasil ditambahkan beserta ISBN-nya dan tampil di katalog.');
            } else {
                if ($id < 1) throw new RuntimeException('Buku yang akan diedit tidak ditemukan.');
                if ($isbn !== '') {
                    $check = $pdo->prepare('SELECT id FROM books WHERE isbn=? AND id<>? LIMIT 1');
                    $check->execute([$isbn,$id]);
                    if ($check->fetch()) throw new RuntimeException('ISBN tersebut sudah digunakan oleh buku lain.');
                }
                $pdo->prepare('UPDATE books SET title=?,author=?,category=?,isbn=?,description=?,price=?,stock=?,cover=? WHERE id=?')
                    ->execute([$title,$author,$category,$isbn !== '' ? $isbn : null,$description,$price,$stock,$cover !== '' ? $cover : null,$id]);
                unset($_SESSION['isbn_lookup']);
                flash('Data buku beserta ISBN berhasil diperbarui.');
            }
        } catch (Throwable $ex) {
            flash('Buku gagal disimpan: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'periksa kembali data yang dimasukkan.'));
        }
        redirect('index.php?page=admin_books');
    }

    if ($action === 'admin_delete_book') {
        require_admin();
        $pdo->prepare('UPDATE books SET is_active=0 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Buku disembunyikan dari katalog.');
        redirect('index.php?page=admin_books');
    }

    if ($action === 'admin_order_status') {
        require_admin();
        $status = $_POST['status'] ?? '';
        $valid = ['Menunggu pembayaran','Diproses','Dikirim','Selesai','Dibatalkan'];
        if (in_array($status, $valid, true)) {
            $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,(int)($_POST['order_id'] ?? 0)]);
            flash('Status pesanan diperbarui.');
        }
        redirect('index.php?page=admin_orders');
    }
}

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

function book_card(array $book): void { ?>
    <article class="book-card">
        <a class="cover-link" href="index.php?page=detail&id=<?= (int)$book['id'] ?>">
            <?php if (!empty($book['cover'])): ?><img class="book-cover" src="<?= e($book['cover']) ?>" alt="Sampul <?= e($book['title']) ?>" loading="lazy">
            <?php else: ?><div class="book-cover placeholder-cover"><span>BUKU</span><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></div><?php endif; ?>
        </a>
        <div class="book-info">
            <span class="category"><?= e($book['category']) ?></span>
            <h3><a href="index.php?page=detail&id=<?= (int)$book['id'] ?>"><?= e($book['title']) ?></a></h3>
            <p class="muted"><?= e($book['author']) ?></p>
            <div class="book-bottom"><strong class="price"><?= rupiah($book['price']) ?></strong><span class="stock"><?= (int)$book['stock'] > 0 ? 'Stok: '.(int)$book['stock'] : 'Habis' ?></span></div>
            <form method="post" class="add-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>"><input type="hidden" name="qty" value="1"><button class="btn btn-small" <?= (int)$book['stock'] < 1 ? 'disabled' : '' ?>>+ Keranjang</button></form>
        </div>
    </article>
<?php }

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$cartCount = array_sum($_SESSION['cart'] ?? []);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(ucfirst($page)) ?> — PustakaKita</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<header class="site-header">
    <div class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Pustaka<span class="brand-accent">Kita</span><small>Temukan cerita, temukan dunia.</small></span></a>
    <form class="search-form" action="index.php" method="get"><input type="hidden" name="page" value="catalog"><input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Cari judul, penulis, kategori..." aria-label="Cari buku"><button aria-label="Cari">⌕</button></form>
    <nav class="header-actions"><a href="index.php?page=account">Akun</a><a class="cart-link" href="index.php?page=cart">Keranjang <span><?= (int)$cartCount ?></span></a><button class="menu-toggle" aria-label="Buka menu">☰</button></nav></div>
    <nav class="main-nav"><a href="index.php">Beranda</a><a href="index.php?page=catalog">Katalog Buku</a><a href="index.php?page=catalog&category=Fiksi">Fiksi</a><a href="index.php?page=catalog&category=Pengembangan Diri">Pengembangan Diri</a><a href="index.php?page=catalog&category=Pendidikan">Pendidikan</a><?php if (is_admin()): ?><a href="index.php?page=admin">Dashboard Admin</a><?php endif; ?></nav>
</header>
<main class="container">
<?php if ($flash): ?><div class="flash" role="status"><?= e($flash) ?></div><?php endif; ?>

<?php if ($page === 'home'):
    $featured = $pdo->query('SELECT * FROM books WHERE is_active=1 ORDER BY created_at DESC LIMIT 8')->fetchAll();
?>
<section class="hero">
    <div class="hero-copy"><span class="eyebrow">DUNIA BARU DI SETIAP HALAMAN</span><h1>Setiap buku punya <em>cerita.</em><br>Temukan milikmu.</h1><p>Jelajahi koleksi bacaan pilihan yang menemani ide, imajinasi, dan perjalananmu.</p><div class="hero-actions"><a class="btn" href="index.php?page=catalog">Jelajahi Katalog <span>↗</span></a><span class="hero-note">✦ Pilihan bacaan untuk semua</span></div></div>
    <div class="hero-art"><div class="sun"></div><div class="hero-book book-one"><small>PUSTAKA KITA</small><b>Seni<br>Memahami<br>Hidup</b><i>edisi pilihan</i></div><div class="hero-book book-two"><small>SEBUAH NOVEL</small><b>Langit<br>yang Kita<br>Janjikan</b></div><div class="hero-book book-three"><small>CATATAN KECIL</small><b>Mulai<br>Dari Sini</b></div><div class="art-caption">Baca. Bertumbuh. Berulang.</div></div>
</section>
<section class="benefits"><div><span>✧</span><p><strong>Koleksi Pilihan</strong><small>Bacaan berkualitas untukmu</small></p></div><div><span>⌁</span><p><strong>Belanja Praktis</strong><small>Pesan buku dengan mudah</small></p></div><div><span>♡</span><p><strong>Ruang Pembaca</strong><small>Bagikan ulasan dan cerita</small></p></div></section>
<section class="section-head"><div><span class="eyebrow">DIPILIH UNTUKMU</span><h2>Jelajahi rak buku</h2><p>Temukan bacaan yang sesuai dengan rasa ingin tahumu.</p></div><a class="text-link" href="index.php?page=catalog">Lihat semua buku ↗</a></section>
<div class="book-grid"><?php foreach ($featured as $book) book_card($book); ?></div>
<section class="newsletter"><div><span class="eyebrow">CATATAN DARI PUSTAKA</span><h2>Selalu ada cerita baru.</h2><p>Jadikan membaca bagian kecil yang menyenangkan dari harimu.</p></div><a class="btn btn-light" href="index.php?page=catalog">Cari bacaanmu ↗</a></section>

<?php elseif ($page === 'catalog'):
    $q = trim($_GET['q'] ?? ''); $category = trim($_GET['category'] ?? '');
    $sql = 'SELECT b.*, COALESCE(AVG(r.rating),0) avg_rating FROM books b LEFT JOIN reviews r ON r.book_id=b.id WHERE b.is_active=1';
    $params = [];
    if ($q !== '') { $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
    if ($category !== '') { $sql .= ' AND b.category=?'; $params[]=$category; }
    $sql .= ' GROUP BY b.id ORDER BY b.created_at DESC';
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $books=$stmt->fetchAll();
    $categories=$pdo->query('SELECT DISTINCT category FROM books WHERE is_active=1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
?>
<section class="page-heading"><span class="eyebrow">RAK BACAAN</span><h1>Katalog buku</h1><p>Pilih buku berikutnya yang akan menemanimu.</p></section>
<div class="catalog-toolbar"><span><?= count($books) ?> buku ditemukan<?= $q ? ' untuk “'.e($q).'”' : '' ?></span><div class="chips"><a class="chip <?= !$category?'active':'' ?>" href="index.php?page=catalog">Semua</a><?php foreach($categories as $cat): ?><a class="chip <?= $category===$cat?'active':'' ?>" href="index.php?page=catalog&category=<?= urlencode($cat) ?>"><?= e($cat) ?></a><?php endforeach; ?></div></div>
<?php if ($books): ?><div class="book-grid"><?php foreach($books as $book) book_card($book); ?></div><?php else: ?><div class="empty-state"><span>⌕</span><h2>Buku belum ditemukan</h2><p>Coba kata kunci atau kategori lain.</p><a class="btn" href="index.php?page=catalog">Lihat semua buku</a></div><?php endif; ?>

<?php elseif ($page === 'detail'):
    $id=(int)($_GET['id']??0); $stmt=$pdo->prepare('SELECT b.*, COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.id) review_count FROM books b LEFT JOIN reviews r ON r.book_id=b.id WHERE b.id=? AND b.is_active=1 GROUP BY b.id'); $stmt->execute([$id]); $book=$stmt->fetch();
    if (!$book) { echo '<div class="empty-state"><h2>Buku tidak ditemukan</h2><a href="index.php?page=catalog">Kembali ke katalog</a></div>'; }
    else {
?>
<div class="breadcrumbs"><a href="index.php">Beranda</a> / <a href="index.php?page=catalog">Katalog</a> / <?= e($book['title']) ?></div>
<section class="detail-layout"><div class="detail-cover"><?php if($book['cover']): ?><img src="<?= e($book['cover']) ?>" alt="Sampul <?= e($book['title']) ?>"><?php else: ?><div class="book-cover placeholder-cover large"><span>PUSTAKAKITA</span><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></div><?php endif; ?></div>
<div class="detail-copy"><span class="category"><?= e($book['category']) ?></span><h1><?= e($book['title']) ?></h1><p class="detail-author">oleh <?= e($book['author']) ?></p><div class="rating-line"><span class="stars">★★★★★</span> <?= number_format((float)$book['avg_rating'],1) ?> / 5 · <?= (int)$book['review_count'] ?> ulasan</div><div class="detail-price"><?= rupiah($book['price']) ?></div><p class="stock-line"><?= (int)$book['stock']>0 ? '✓ Tersedia · '.$book['stock'].' buku di stok' : 'Stok habis' ?></p><p class="description"><?= nl2br(e($book['description'])) ?></p><form method="post" class="detail-add"><?= csrf_field() ?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>"><label>Jumlah <input type="number" name="qty" min="1" max="<?= max(1,(int)$book['stock']) ?>" value="1"></label><button class="btn" <?= (int)$book['stock']<1?'disabled':'' ?>>Tambahkan ke keranjang</button></form></div></section>
<section class="reviews-section"><div class="section-head"><div><span class="eyebrow">CERITA PEMBACA</span><h2>Ulasan buku</h2></div></div>
<?php $stmt=$pdo->prepare('SELECT r.*,u.name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.book_id=? ORDER BY r.created_at DESC'); $stmt->execute([$id]); $reviews=$stmt->fetchAll(); ?>
<?php if($reviews): foreach($reviews as $review): ?><article class="review"><div class="review-avatar"><?= e(strtoupper(substr($review['name'],0,1))) ?></div><div><strong><?= e($review['name']) ?></strong><div class="stars"><?= str_repeat('★',(int)$review['rating']) ?><span class="muted"><?= str_repeat('★',5-(int)$review['rating']) ?></span></div><p><?= nl2br(e($review['comment'])) ?></p><small class="muted"><?= e($review['created_at']) ?></small></div></article><?php endforeach; else: ?><p class="muted">Belum ada ulasan untuk buku ini.</p><?php endif; ?>
<?php if(current_user()): ?><form method="post" class="review-form panel"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="book_id" value="<?= $id ?>"><h3>Tulis ulasanmu</h3><label>Rating <select name="rating"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> bintang</option><?php endfor; ?></select></label><label>Komentar<textarea name="comment" rows="3" required placeholder="Apa yang kamu suka dari buku ini?"></textarea></label><button class="btn">Kirim ulasan</button><small class="muted">Ulasan tersedia bagi pelanggan yang pernah memesan buku dan pesanannya telah diproses.</small></form><?php else: ?><p><a href="index.php?page=login">Masuk</a> untuk menulis ulasan setelah membeli buku.</p><?php endif; ?>
</section>
<?php } ?>

<?php elseif ($page === 'cart'):
    $cart=$_SESSION['cart']??[]; $items=[]; $total=0;
    foreach($cart as $id=>$qty){$stmt=$pdo->prepare('SELECT * FROM books WHERE id=? AND is_active=1');$stmt->execute([(int)$id]);if($b=$stmt->fetch()){$b['qty']=(int)$qty;$b['subtotal']=$b['price']*$b['qty'];$items[]=$b;$total+=$b['subtotal'];}}
?>
<section class="page-heading"><span class="eyebrow">SEBELUM MELANJUTKAN</span><h1>Keranjang belanja</h1><p>Periksa kembali pilihan bacaanmu.</p></section>
<?php if(!$items): ?><div class="empty-state"><span>♡</span><h2>Keranjangmu masih kosong</h2><p>Masih banyak cerita menarik yang menunggu.</p><a class="btn" href="index.php?page=catalog">Jelajahi katalog</a></div>
<?php else: ?><form method="post" class="cart-layout"><?= csrf_field() ?><input type="hidden" name="action" value="update_cart"><div class="cart-items"><?php foreach($items as $b): ?><article class="cart-item"><div class="mini-cover"><?php if($b['cover']): ?><img src="<?= e($b['cover']) ?>" alt=""><?php else: ?>📚<?php endif; ?></div><div class="cart-item-main"><a class="cart-title" href="index.php?page=detail&id=<?= $b['id'] ?>"><?= e($b['title']) ?></a><p class="muted"><?= e($b['author']) ?></p><strong><?= rupiah($b['price']) ?></strong></div><label class="qty-label">Qty<input type="number" name="qty[<?= $b['id'] ?>]" min="0" max="<?= max(1,(int)$b['stock']) ?>" value="<?= $b['qty'] ?>"></label><strong><?= rupiah($b['subtotal']) ?></strong><button class="remove-btn" type="submit" form="remove-<?= $b['id'] ?>" title="Hapus">×</button></article><?php endforeach; ?><button class="btn btn-outline" type="submit">Perbarui keranjang</button></div><aside class="summary panel"><h2>Ringkasan</h2><div class="summary-row"><span>Subtotal</span><strong><?= rupiah($total) ?></strong></div><div class="summary-row"><span>Pengiriman</span><span>Dihitung saat demo checkout</span></div><div class="summary-row total-row"><span>Total sementara</span><strong><?= rupiah($total) ?></strong></div><a class="btn btn-block" href="index.php?page=checkout">Lanjut ke pembayaran →</a><p class="muted tiny">Pembayaran di website ini masih berupa simulasi.</p></aside></form>
<?php foreach($items as $b): ?><form id="remove-<?= $b['id'] ?>" method="post" hidden><?= csrf_field() ?><input name="action" value="remove_cart"><input name="book_id" value="<?= $b['id'] ?>"></form><?php endforeach; endif; ?>

<?php elseif ($page === 'checkout'):
    require_login(); $cart=$_SESSION['cart']??[]; $total=0;
    foreach($cart as $id=>$qty){$stmt=$pdo->prepare('SELECT price FROM books WHERE id=?');$stmt->execute([(int)$id]);if($r=$stmt->fetch())$total+=(int)$r['price']*(int)$qty;}
?>
<section class="page-heading"><span class="eyebrow">LANGKAH TERAKHIR</span><h1>Checkout</h1><p>Isi alamat pengiriman dan pilih metode pembayaran.</p></section>
<?php if(!$cart): ?><div class="empty-state"><h2>Keranjang masih kosong</h2><a class="btn" href="index.php?page=catalog">Cari buku</a></div><?php else: ?><div class="checkout-layout"><form method="post" class="panel checkout-form"><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><h2>Informasi pengiriman</h2><label>Nama penerima<input name="recipient" required value="<?= e(current_user()['name']) ?>"></label><label>Nomor telepon<input name="phone" required inputmode="tel" placeholder="08xxxxxxxxxx"></label><label>Alamat lengkap<textarea name="address" required rows="4" placeholder="Nama jalan, kecamatan, kota, kode pos"></textarea></label><h2>Metode pembayaran</h2><label class="payment-option"><input type="radio" name="payment" value="transfer" checked> Transfer bank <small>Instruksi pembayaran demo</small></label><label class="payment-option"><input type="radio" name="payment" value="ewallet"> E-wallet <small>Simulasi pembayaran</small></label><label class="payment-option"><input type="radio" name="payment" value="cod"> Bayar di tempat (COD)</label><button class="btn btn-block">Buat pesanan</button><small class="muted">Belum ada transaksi uang nyata atau integrasi payment gateway.</small></form><aside class="panel summary"><h2>Pesananmu</h2><?php foreach($cart as $id=>$qty):$stmt=$pdo->prepare('SELECT title,price FROM books WHERE id=?');$stmt->execute([(int)$id]);if($b=$stmt->fetch()): ?><div class="summary-row"><span><?= e($b['title']) ?> × <?= (int)$qty ?></span><strong><?= rupiah($b['price']*$qty) ?></strong></div><?php endif; endforeach; ?><div class="summary-row total-row"><span>Total</span><strong><?= rupiah($total) ?></strong></div></aside></div><?php endif; ?>

<?php elseif ($page === 'login' || $page === 'register'): ?>
<section class="auth-wrap"><div class="auth-art"><span class="eyebrow">SELAMAT DATANG DI PUSTAKAKITA</span><h2>Temukan tempatmu di antara halaman-halaman cerita.</h2><p>Simpan bacaan favorit dan ikuti perjalanan pesananmu.</p><div class="auth-art-books">▰ ▰ ▰</div></div><form method="post" class="panel auth-form"><?= csrf_field() ?><span class="eyebrow"><?= $page==='login'?'AKUN PEMBACA':'MULAI PERJALANAN' ?></span><h1><?= $page==='login'?'Selamat datang kembali':'Buat akun baru' ?></h1><p class="muted"><?= $page==='login'?'Masuk untuk melanjutkan membaca.':'Daftar dan temukan bacaan favoritmu.' ?></p><input type="hidden" name="action" value="<?= $page==='login'?'login':'register' ?>"><?php if($page==='register'): ?><label>Nama lengkap<input name="name" required autocomplete="name"></label><?php endif; ?><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Kata sandi<input type="password" name="password" required minlength="8" autocomplete="<?= $page==='login'?'current-password':'new-password' ?>"></label><button class="btn btn-block"><?= $page==='login'?'Masuk':'Buat akun' ?></button><p class="auth-switch"><?= $page==='login'?'Belum punya akun?':'Sudah punya akun?' ?> <a href="index.php?page=<?= $page==='login'?'register':'login' ?>"><?= $page==='login'?'Daftar sekarang':'Masuk' ?></a></p></form></section>

<?php elseif ($page === 'account'):
    require_login();
    $stmt=$pdo->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC');$stmt->execute([current_user()['id']]);$orders=$stmt->fetchAll();
?>
<section class="page-heading"><span class="eyebrow">RUANG PEMBACA</span><h1>Halo, <?= e(current_user()['name']) ?>!</h1><p>Kelola akun dan lihat perjalanan pesananmu.</p></section>
<div class="account-grid"><aside class="panel account-card"><div class="profile-avatar"><?= e(strtoupper(substr(current_user()['name'],0,1))) ?></div><h2><?= e(current_user()['name']) ?></h2><p class="muted"><?= e(current_user()['email']) ?></p><span class="chip active"><?= is_admin()?'Administrator':'Pelanggan' ?></span><a class="btn btn-outline btn-block" href="index.php?page=logout">Keluar akun</a><?php if(is_admin()): ?><a class="text-link" href="index.php?page=admin">Buka dashboard admin →</a><?php endif; ?></aside><section class="panel orders-panel"><h2>Riwayat pesanan</h2><?php if(!$orders): ?><div class="empty-inline"><p>Belum ada pesanan. Waktunya menemukan buku baru!</p><a href="index.php?page=catalog">Jelajahi katalog →</a></div><?php else: foreach($orders as $order): ?><article class="order-card"><div class="order-top"><strong>Pesanan #<?= (int)$order['id'] ?></strong><span class="status"><?= e($order['status']) ?></span></div><p class="muted"><?= e($order['created_at']) ?> · <?= e(strtoupper($order['payment_method'])) ?></p><strong><?= rupiah($order['total']) ?></strong><details><summary>Lihat detail pesanan</summary><?php $stmt=$pdo->prepare('SELECT * FROM order_items WHERE order_id=?');$stmt->execute([$order['id']]);foreach($stmt->fetchAll() as $item): ?><p><?= e($item['book_title']) ?> × <?= (int)$item['quantity'] ?> — <?= rupiah($item['price']*$item['quantity']) ?></p><?php endforeach; ?></details></article><?php endforeach; endif; ?></section></div>

<?php elseif ($page === 'admin' || $page === 'admin_books' || $page === 'admin_orders'):
    require_admin();
    $bookCount=(int)$pdo->query('SELECT COUNT(*) FROM books WHERE is_active=1')->fetchColumn();
    $orderCount=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $customerCount=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $revenue=(int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('Diproses','Dikirim','Selesai')")->fetchColumn();
?>
<div class="admin-heading"><div><span class="eyebrow">PENGELOLA TOKO</span><h1>Dashboard admin</h1><p>Kelola koleksi, pantau stok, dan perbarui pesanan.</p></div><div class="admin-heading-actions"><a class="btn" href="index.php?page=admin_books">+ Tambah buku</a><a class="btn btn-outline" href="index.php">Lihat toko ↗</a></div></div>
<nav class="admin-tabs"><a class="<?= $page==='admin'?'active':'' ?>" href="index.php?page=admin">Ringkasan</a><a class="<?= $page==='admin_books'?'active':'' ?>" href="index.php?page=admin_books">Kelola buku</a><a class="<?= $page==='admin_orders'?'active':'' ?>" href="index.php?page=admin_orders">Kelola pesanan</a></nav>
<?php if($page==='admin'): ?>
<div class="stats-grid"><div class="stat-card"><span>Total buku aktif</span><strong><?= $bookCount ?></strong><small>Judul di katalog</small></div><div class="stat-card"><span>Total pesanan</span><strong><?= $orderCount ?></strong><small>Semua status</small></div><div class="stat-card"><span>Pelanggan</span><strong><?= $customerCount ?></strong><small>Akun terdaftar</small></div><div class="stat-card"><span>Nilai pesanan diproses</span><strong><?= rupiah($revenue) ?></strong><small>Diproses, dikirim, selesai</small></div></div>
<div class="panel admin-panel"><div class="section-head"><div><h2>Pesanan terbaru</h2><p class="muted">Pantau status transaksi pelanggan.</p></div><a class="text-link" href="index.php?page=admin_orders">Semua pesanan →</a></div><?php $orders=$pdo->query('SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 6')->fetchAll(); if(!$orders): ?><p class="muted">Belum ada pesanan.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Pelanggan</th><th>Tanggal</th><th>Total</th><th>Status</th></tr></thead><tbody><?php foreach($orders as $o): ?><tr><td>#<?= $o['id'] ?></td><td><?= e($o['name']) ?></td><td><?= e($o['created_at']) ?></td><td><?= rupiah($o['total']) ?></td><td><span class="status"><?= e($o['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php elseif($page==='admin_books'):
    $edit=null;if(isset($_GET['edit'])){$stmt=$pdo->prepare('SELECT * FROM books WHERE id=?');$stmt->execute([(int)$_GET['edit']]);$edit=$stmt->fetch();}
    $books=$pdo->query('SELECT * FROM books WHERE is_active=1 ORDER BY created_at DESC')->fetchAll();
    $isbnDraft=$_SESSION['isbn_lookup']??[];
    $form=$edit ?: $isbnDraft;
?>
<div class="admin-content-grid">
<div class="admin-left-column">
<?php if(!$edit): ?>
<div class="isbn-import-column">
<form method="post" class="panel isbn-import-card">
<?= csrf_field() ?>
<h2>Tambah buku via ISBN</h2>
<div class="isbn-lookup"><label>Nomor ISBN<input name="isbn" required pattern="[0-9Xx -]+" value="<?= e($isbnDraft['isbn']??'') ?>" placeholder="Contoh: 9786020332956"></label><div class="isbn-actions"><button class="btn btn-outline" type="submit" name="action" value="admin_lookup_isbn">Ambil & isi form</button><button class="btn" type="submit" name="action" value="admin_import_isbn">Ambil & simpan otomatis</button></div></div>
<p class="muted tiny">ISBN dan data buku akan diambil otomatis; harga dan stok awal ditetapkan 0.</p>
</form>
</div>
<?php endif; ?>
<form method="post" class="panel admin-form">
<?= csrf_field() ?>
<h2><?= $edit?'Edit buku':'Lengkapi atau edit data buku' ?></h2>
<input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
<?php if($edit): ?><label>Nomor ISBN<input name="isbn" value="<?= e($form['isbn']??'') ?>" placeholder="ISBN-10 atau ISBN-13"></label><?php endif; ?>
<label>Judul buku<input name="title" required value="<?= e($form['title']??'') ?>"></label>
<label>Penulis<input name="author" required value="<?= e($form['author']??'') ?>"></label>
<label>Kategori<input name="category" required value="<?= e($form['category']??'') ?>" placeholder="Fiksi, Pendidikan, dll."></label>
<label>Harga (rupiah)<input type="number" name="price" min="0" required value="<?= (int)($form['price']??0) ?>"></label>
<label>Stok<input type="number" name="stock" min="0" required value="<?= (int)($form['stock']??0) ?>"></label>
<label>URL gambar sampul (opsional)<input name="cover" type="url" value="<?= e($form['cover']??'') ?>" placeholder="https://..."></label>
<label>Deskripsi<textarea name="description" rows="4"><?= e($form['description']??'') ?></textarea></label>
<button class="btn btn-block" type="submit" name="action" value="<?= $edit?'admin_update_book':'admin_add_book' ?>"><?= $edit?'Simpan perubahan':'Simpan buku ke database' ?></button>
<?php if($edit): ?><a class="text-link" href="index.php?page=admin_books">Batal edit</a><?php endif; ?>
</form>
</div>
<section class="panel admin-panel"><h2>Daftar buku</h2><div class="table-wrap"><table><thead><tr><th>Buku</th><th>ISBN</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr></thead><tbody><?php foreach($books as $b): ?><tr><td><strong><?= e($b['title']) ?></strong><small class="table-sub"><?= e($b['author']) ?></small></td><td><?= e($b['isbn']??'—') ?></td><td><?= rupiah($b['price']) ?></td><td><span class="<?= $b['stock']<5?'low-stock':'' ?>"><?= (int)$b['stock'] ?></span></td><td><div class="table-actions"><a href="index.php?page=admin_books&edit=<?= $b['id'] ?>">Edit</a><form method="post" data-confirm="Sembunyikan buku ini dari katalog?"><?= csrf_field() ?><input type="hidden" name="action" value="admin_delete_book"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="danger-link">Nonaktifkan</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></section></div>
<?php else:
    $orders=$pdo->query('SELECT o.*,u.name,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC')->fetchAll();
?>
<section class="panel admin-panel"><div class="section-head"><div><h2>Semua pesanan</h2><p class="muted">Perbarui status pesanan sesuai proses toko.</p></div></div><div class="table-wrap"><table><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Alamat</th><th>Total</th><th>Status & aksi</th></tr></thead><tbody><?php foreach($orders as $o): ?><tr><td><strong>#<?= $o['id'] ?></strong><small class="table-sub"><?= e($o['created_at']) ?></small><small class="table-sub"><?= e($o['payment_method']) ?></small></td><td><?= e($o['name']) ?><small class="table-sub"><?= e($o['email']) ?></small><small class="table-sub"><?= e($o['phone']) ?></small></td><td class="address-cell"><?= e($o['recipient']) ?><br><?= e($o['address']) ?></td><td><?= rupiah($o['total']) ?></td><td><form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="admin_order_status"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><select name="status"><?php foreach(['Menunggu pembayaran','Diproses','Dikirim','Selesai','Dibatalkan'] as $s): ?><option <?= $s===$o['status']?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select><button class="btn btn-small">Simpan</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php endif; ?>
</main>
<footer class="site-footer"><div class="footer-inner"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Pustaka<span class="brand-accent">Kita</span><small>Temukan cerita, temukan dunia.</small></span></a><p>Ruang kecil untuk cerita-cerita besar.</p><small>© <?= date('Y') ?> PustakaKita · Website demo toko buku.</small></div></footer>
</body></html>
