<?php
/**
 * ================================================================
 * KONFIGURASI DATABASE & FUNGSI GLOBAL
 * Aplikasi: Pengumuman Kelulusan Kelas VI
 * ================================================================
 */
declare(strict_types=1);

// Set timezone ke Asia/Makassar
date_default_timezone_set('Asia/Makassar');

// ================================================================
// KONFIGURASI DATABASE
// ================================================================
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'pengumuman_kelulusan');
define('DB_USER', 'root');
define('DB_PASS', '');

// ================================================================
// KONFIGURASI APLIKASI
// ================================================================
define('APP_NAME', 'Pengumuman Kelulusan Kelas VI');
define('SCHOOL_NAME', 'SD Negeri 1 Molinow');
define('SCHOOL_ADDRESS', 'Jl.Veteran Kel.Molinow Kec.Kotamobagu Barat');

define('DEFAULT_SKL_SUBJECTS', [
    'Pendidikan Agama dan Budi Pekerti',
    'Pendidikan Pancasila',
    'Bahasa Indonesia',
    'Matematika',
    'Ilmu Pengetahuan Alam dan Sosial',
    'Bahasa Inggris',
    'Seni Budaya',
    'Pendidikan Jasmani, Olahraga, dan Kesehatan',
    'Muatan Lokal',
    'a. Bahasa Mongondow',
    'b. Pendidikan Lingkungan Hidup dan Budaya',
]);

// ================================================================
// KONEKSI DATABASE PDO
// ================================================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,  // Prevent SQL injection
        ]
    );
} catch (PDOException $e) {
    // Log error securely (jangan tampilkan detail ke user)
    error_log('Database Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Koneksi database gagal. Hubungi administrator sekolah.');
}

// ================================================================
// FUNGSI KEAMANAN & SANITASI
// ================================================================

/**
 * Escape HTML special characters untuk mencegah XSS
 * @param string|null $value Input string
 * @return string Escaped string
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sanitasi input: trim whitespace
 * @param string|null $value Input string
 * @return string Sanitized string
 */
function clean_input(?string $value): string
{
    return trim((string) $value);
}

/**
 * Extract hanya digit dari string (untuk NISN)
 * @param string|null $value Input string
 * @return string String hanya dengan digit
 */
function only_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

// ================================================================
// MANAJEMEN SESSION & SECURITY
// ================================================================

/**
 * Start secure session dengan proteksi session hijacking
 * - Browser fingerprint validation
 * - Session regeneration setiap 15 menit
 * - Secure cookie flags
 */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Deteksi HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    // Set secure cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,           // Session cookie (dihapus saat browser ditutup)
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,      // HTTPS only
        'httponly' => true,        // Tidak bisa diakses JavaScript
        'samesite' => 'Lax',       // CSRF protection
    ]);

    session_start();

    // Browser fingerprinting untuk deteksi session hijacking
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    
    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
        $_SESSION['last_regenerated'] = time();
        session_regenerate_id(true);
    }

    // Jika fingerprint tidak cocok, destroy session (session hijacking detected)
    if (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?error=session_hijack');
        exit;
    }

    // Regenerate session ID setiap 15 menit untuk keamanan
    if ((time() - (int) ($_SESSION['last_regenerated'] ?? 0)) > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

/**
 * Generate CSRF token untuk form submission
 * @return string CSRF token
 */
function csrf_token(): string
{
    secure_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifikasi CSRF token dari form submission
 * @param string|null $token Token yang dikirim user
 * @return bool True jika token valid
 */
function verify_csrf(?string $token): bool
{
    secure_session_start();
    return isset($_SESSION['csrf_token']) 
        && is_string($token) 
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ================================================================
// FUNGSI AUTENTIKASI ADMIN
// ================================================================

/**
 * Cek apakah admin sudah login
 * @return bool
 */
function is_admin_logged_in(): bool
{
    secure_session_start();
    return !empty($_SESSION['admin_id']);
}

/**
 * Require admin login, jika belum redirect ke login
 */
function require_admin(): void
{
    if (!is_admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// ================================================================
// FUNGSI DATABASE UTILITIES
// ================================================================

/**
 * Get setting value dari tabel pengaturan
 * @param PDO $pdo PDO instance
 * @param string $key Kunci pengaturan
 * @param string $default Nilai default jika key tidak ditemukan
 * @return string Nilai pengaturan
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT nilai_pengaturan FROM pengaturan WHERE kunci_pengaturan = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string) $value : $default;
}

/**
 * Update setting value di tabel pengaturan
 * @param PDO $pdo PDO instance
 * @param string $key Kunci pengaturan
 * @param string $value Nilai yang ingin disimpan
 * @return bool True jika berhasil
 */
function update_setting(PDO $pdo, string $key, string $value): bool
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO pengaturan (kunci_pengaturan, nilai_pengaturan) 
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE nilai_pengaturan = :value'
        );
        return $stmt->execute([':key' => $key, ':value' => $value]);
    } catch (PDOException $e) {
        error_log('Error updating setting: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cek apakah pengumuman sudah dibuka
 * Dibandingkan waktu sekarang dengan waktu_pengumuman dari database
 * @param PDO $pdo PDO instance
 * @return bool True jika pengumuman sudah dibuka
 */
function announcement_is_open(PDO $pdo): bool
{
    $announceTime = get_setting($pdo, 'waktu_pengumuman', '');
    if ($announceTime === '') {
        $announceTime = get_setting($pdo, 'tanggal_pengumuman', date('Y-m-d H:i:s'));
    }
    
    try {
        $targetDateTime = new DateTime($announceTime, new DateTimeZone('Asia/Makassar'));
        $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Makassar'));
        return $currentDateTime >= $targetDateTime;
    } catch (Exception $e) {
        error_log('Error comparing announcement time: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get time remaining sampai pengumuman dibuka (dalam milliseconds)
 * @param PDO $pdo PDO instance
 * @return int Milliseconds remaining, atau -1 jika sudah dibuka
 */
function get_announcement_countdown_ms(PDO $pdo): int
{
    $announceTime = get_setting($pdo, 'waktu_pengumuman', '');
    if ($announceTime === '') {
        $announceTime = get_setting($pdo, 'tanggal_pengumuman', date('Y-m-d H:i:s'));
    }
    
    try {
        $targetDateTime = new DateTime($announceTime, new DateTimeZone('Asia/Makassar'));
        $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Makassar'));
        
        if ($currentDateTime >= $targetDateTime) {
            return -1; // Sudah dibuka
        }
        
        $interval = $currentDateTime->diff($targetDateTime);
        $ms = ($interval->days * 86400000) 
            + ($interval->h * 3600000) 
            + ($interval->i * 60000) 
            + ($interval->s * 1000);
        
        return $ms;
    } catch (Exception $e) {
        error_log('Error calculating countdown: ' . $e->getMessage());
        return -1;
    }
}

// ================================================================
// FUNGSI UTILITY UMUM
// ================================================================

/**
 * Redirect ke halaman lain
 * @param string $path URL atau path tujuan
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Get statistik siswa
 * @param PDO $pdo PDO instance
 * @return array Array dengan keys: total, lulus, tidak_lulus
 */
function get_student_statistics(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status_kelulusan = "LULUS" THEN 1 ELSE 0 END) as lulus,
            SUM(CASE WHEN status_kelulusan = "TIDAK LULUS" THEN 1 ELSE 0 END) as tidak_lulus
        FROM siswa
    ');
    
    $result = $stmt->fetch() ?: ['total' => 0, 'lulus' => 0, 'tidak_lulus' => 0];
    return [
        'total' => (int) ($result['total'] ?? 0),
        'lulus' => (int) ($result['lulus'] ?? 0),
        'tidak_lulus' => (int) ($result['tidak_lulus'] ?? 0),
    ];
}

/**
 * Pastikan struktur database mendukung format SKL dan import nilai.
 */
function ensure_graduation_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'nomor_induk_siswa' => 'VARCHAR(30) NULL AFTER kelas',
        'nomor_surat_skl_siswa' => 'VARCHAR(120) NULL AFTER nomor_induk_siswa',
        'tempat_lahir' => 'VARCHAR(100) NULL AFTER nomor_induk_siswa',
        'tanggal_lahir' => 'DATE NULL AFTER tempat_lahir',
    ];

    foreach ($columns as $column => $definition) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "siswa"
               AND COLUMN_NAME = :column'
        );
        $stmt->execute(['column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE siswa ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nilai_siswa (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            siswa_id INT UNSIGNED NOT NULL,
            mata_pelajaran VARCHAR(150) NOT NULL,
            nilai DECIMAL(5,2) NOT NULL DEFAULT 0,
            urutan INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_siswa_mapel (siswa_id, mata_pelajaran),
            CONSTRAINT fk_nilai_siswa
                FOREIGN KEY (siswa_id) REFERENCES siswa(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $defaults = [
        'nama_sekolah' => SCHOOL_NAME,
        'alamat_sekolah' => SCHOOL_ADDRESS,
        'tahun_ajaran' => '2025-2026',
        'nomor_surat_skl' => '422/C1/SDN1MLW/2026',
        'nomor_surat_keputusan' => '. . ./C.1/SDN1MLW/VI/2026',
        'tanggal_surat' => '2 Juni 2026',
        'tempat_surat' => 'Kotamobagu',
        'kepala_sekolah' => 'GANTI NAMA KEPALA SEKOLAH, S.Pd',
        'nip_kepala_sekolah' => '',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = $pdo->prepare(
            'INSERT INTO pengaturan (kunci_pengaturan, nilai_pengaturan)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE nilai_pengaturan = nilai_pengaturan'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    $stmt = $pdo->prepare(
        'UPDATE pengaturan
         SET nilai_pengaturan = :new_value
         WHERE kunci_pengaturan = :key AND nilai_pengaturan = :old_value'
    );
    $stmt->execute([
        'key' => 'nomor_surat_keputusan',
        'old_value' => '43/C.1/SDN2POB/V/2026',
        'new_value' => '. . ./C.1/SDN1MLW/VI/2026',
    ]);
    $stmt->execute([
        'key' => 'nomor_surat_skl',
        'old_value' => '52 /C.1/SDN2POB/VI/2026',
        'new_value' => '422/C1/SDN1MLW/2026',
    ]);
    $stmt->execute([
        'key' => 'tahun_ajaran',
        'old_value' => '2025/2026',
        'new_value' => '2025-2026',
    ]);
    $stmt->execute([
        'key' => 'tempat_surat',
        'old_value' => 'Pobundayan',
        'new_value' => 'Kotamobagu',
    ]);

    $checked = true;
}

function format_indonesian_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    try {
        $dt = new DateTime($date);
    } catch (Exception $e) {
        return $date;
    }

    $months = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember',
    ];

    return $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}

function parse_import_date(?string $value): ?string
{
    $value = clean_input($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial > 1) {
            $timestamp = ((int) $serial - 25569) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'j F Y', 'j M Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

// EOF
