<?php
/**
 * ================================================================
 * AJAX ENDPOINT: Pencarian NISN
 * Diakses via AJAX dari halaman index.php
 * Mengembalikan response JSON dengan data siswa atau error
 * ================================================================
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
ensure_graduation_schema($pdo);

// Inisialisasi response
$response = [
    'success' => false,
    'message' => '',
    'html' => '',
];

try {
    // Validasi request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Cek apakah pengumuman sudah dibuka
    if (!announcement_is_open($pdo)) {
        throw new Exception('Pengumuman belum dibuka. Silakan tunggu waktu pengumuman.');
    }

    // Get dan sanitasi NISN dari POST
    $nisn = clean_input($_POST['nisn'] ?? '');
    $nisn = only_digits($nisn);

    // Validasi NISN
    if (empty($nisn) || strlen($nisn) < 6 || strlen($nisn) > 20) {
        throw new Exception('NISN tidak valid. Gunakan 6 sampai 20 digit angka.');
    }

    // Query database untuk cari siswa berdasarkan NISN
    // Menggunakan Prepared Statement untuk mencegah SQL Injection
    $stmt = $pdo->prepare(
        'SELECT id, nisn, nama, kelas, nomor_induk_siswa, tempat_lahir, tanggal_lahir, status_kelulusan 
         FROM siswa 
         WHERE nisn = :nisn 
         LIMIT 1'
    );

    $stmt->execute([':nisn' => $nisn]);
    $siswa = $stmt->fetch();

    // Jika siswa tidak ditemukan
    if (!$siswa) {
        throw new Exception('NISN tidak ditemukan dalam database. Pastikan NISN Anda benar.');
    }

    // Siapkan data untuk response
    $nama = e($siswa['nama']);
    $kelas = e($siswa['kelas']);
    $nisn_safe = e($siswa['nisn']);
    $status = $siswa['status_kelulusan'];

    // Tentukan warna badge dan icon berdasarkan status
    $badgeColor = $status === 'LULUS' ? 'success' : 'danger';
    $badgeText = $status === 'LULUS' ? 'LULUS ✓' : 'TIDAK LULUS ✗';
    $statusIcon = $status === 'LULUS' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';

    // Generate HTML untuk result card
    $html = <<<HTML
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <!-- Result Header -->
            <div class="text-center mb-4">
                <div class="mb-3" style="font-size: 3rem;">
                    $statusIcon
                </div>
                <h3 class="card-title">$nama</h3>
                <p class="text-muted">Kelas $kelas</p>
            </div>

            <!-- Data Siswa -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="data-item">
                        <label class="data-label fw-semibold text-muted">Nomor NISN:</label>
                        <p class="data-value fw-bold" style="font-size: 1.1rem;">$nisn_safe</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="data-item">
                        <label class="data-label fw-semibold text-muted">Kelas:</label>
                        <p class="data-value fw-bold" style="font-size: 1.1rem;">$kelas</p>
                    </div>
                </div>
            </div>

            <!-- Status Badge -->
            <div class="text-center mb-4">
                <span class="badge bg-$badgeColor" style="font-size: 1.2rem; padding: 10px 20px;">
                    $badgeText
                </span>
            </div>

            <!-- Action Buttons -->
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-primary btn-lg" onclick="window.location.href='cetak.php?nisn=$nisn_safe'">
                    <i class="fas fa-print"></i> Cetak Surat Keterangan Lulus
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('nisn-input').value = ''; document.getElementById('result-container').classList.add('d-none'); document.getElementById('nisn-input').focus();">
                    <i class="fas fa-redo"></i> Cari Data Lain
                </button>
            </div>
        </div>
    </div>
    HTML;

    // Set response success
    $response['success'] = true;
    $response['html'] = $html;
    $response['message'] = 'Data siswa ditemukan';
    $response['siswa'] = [
        'nama' => $siswa['nama'],
        'nisn' => $siswa['nisn'],
        'kelas' => $siswa['kelas'],
        'status_kelulusan' => $siswa['status_kelulusan'],
    ];

} catch (Exception $e) {
    // Tangkap error dan set response error
    $response['success'] = false;
    $response['message'] = e($e->getMessage());
    $response['html'] = '';
    
    // Log error untuk debugging
    error_log('AJAX Error in cek_nisn.php: ' . $e->getMessage());
}

// Output response sebagai JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
