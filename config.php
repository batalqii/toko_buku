<?php
declare(strict_types=1);
session_start();

$host = '127.0.0.1';
$db   = 'toko_buku';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $isbnColumn = $pdo->query("SHOW COLUMNS FROM books LIKE 'isbn'")->fetch();
    if (!$isbnColumn) {
        $pdo->exec('ALTER TABLE books ADD COLUMN isbn VARCHAR(20) DEFAULT NULL AFTER category');
    }
    $isbnIndex = $pdo->query("SHOW INDEX FROM books WHERE Key_name = 'uq_books_isbn'")->fetch();
    if (!$isbnIndex) {
        $pdo->exec('ALTER TABLE books ADD UNIQUE KEY uq_books_isbn (isbn)');
    }
} 
 catch (PDOException $e) {
    http_response_code(500);
    exit('Detail error database: ' . $e->getMessage());


}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
function rupiah(int|float $number): string {
    return 'Rp' . number_format((float)$number, 0, ',', '.');
}
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}
function is_admin(): bool {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}
function require_login(): void {
    if (!current_user()) {
        flash('Silakan masuk terlebih dahulu.');
        header('Location: index.php?page=login');
        exit;
    }
}
function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        exit('Akses ditolak. Halaman ini hanya untuk admin.');
    }
}
function flash(string $message): void {
    $_SESSION['flash'] = $message;
}
function redirect(string $url): never {
    header("Location: $url");
    exit;
}
function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            exit('Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.');
        }
    }
}
verify_csrf();
