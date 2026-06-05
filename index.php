<?php
/**
 * ================================================================
 * HALAMAN UTAMA - PENCARIAN KELULUSAN SISWA
 * Fitur: Countdown Timer, Pencarian NISN via AJAX, Cetak SKL
 * ================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$schoolName = get_setting($pdo, 'nama_sekolah', SCHOOL_NAME);
$schoolAddress = get_setting($pdo, 'alamat_sekolah', SCHOOL_ADDRESS);
$announceTime = get_setting($pdo, 'waktu_pengumuman', '');
if ($announceTime === '') {
    $announceTime = get_setting($pdo, 'tanggal_pengumuman', date('Y-m-d H:i:s'));
}
$isOpen = announcement_is_open($pdo);
$tahunAjaran = get_setting($pdo, 'tahun_ajaran', '2025-2026');
$targetDate = new DateTime($announceTime, new DateTimeZone('Asia/Makassar'));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($schoolName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>" rel="stylesheet">
</head>
<body class="student-theme-page">
<nav class="navbar navbar-expand-lg student-navbar">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <span class="student-brand-icon"><i class="fa-solid fa-school"></i></span>
            <span>
                <strong><?php echo e($schoolName); ?></strong>
                <small class="d-block">Pengumuman Kelulusan Kelas VI</small>
            </span>
        </a>
        <a class="btn btn-student-outline btn-sm" href="login.php">
            <i class="fa-solid fa-user-shield me-1"></i> Admin
        </a>
    </div>
</nav>

<main class="student-hero">
    <div class="floating-shape shape-pencil"><i class="fa-solid fa-pencil"></i></div>
    <div class="floating-shape shape-book"><i class="fa-solid fa-book-open"></i></div>
    <div class="floating-shape shape-star"><i class="fa-solid fa-star"></i></div>

    <div class="container py-5">
        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <p class="student-eyebrow mb-3">Tahun Ajaran <?php echo e($tahunAjaran); ?></p>
                <h1 class="student-title mb-3">Cek Kelulusanmu dengan NISN</h1>
                <p class="student-subtitle mb-4">
                    Hai siswa hebat kelas VI. Masukkan NISN untuk melihat hasil kelulusan resmi dari
                    <?php echo e($schoolName); ?>.
                </p>
                <div class="student-mini-card">
                    <i class="fa-solid fa-calendar-days"></i>
                    <div>
                        <span>Pengumuman dibuka</span>
                        <strong><?php echo e($targetDate->format('d F Y, H:i')); ?> WITA</strong>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <section class="student-login-card">
                    <div class="student-card-top">
                        <div class="student-avatar">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div>
                            <h2>Cari Status Kelulusan</h2>
                            <p>Gunakan NISN sesuai data sekolah.</p>
                        </div>
                    </div>

                    <div class="student-countdown <?php echo $isOpen ? 'student-countdown-open' : ''; ?>" data-target="<?php echo e($targetDate->format(DateTime::ATOM)); ?>">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="fa-solid <?php echo $isOpen ? 'fa-unlock-keyhole' : 'fa-lock'; ?>"></i>
                            <strong id="countdown-title">
                                <?php echo $isOpen ? 'Pengumuman sudah dibuka' : 'Form pencarian belum dibuka'; ?>
                            </strong>
                        </div>
                        <div class="countdown-kids-grid">
                            <div><strong id="days"><?php echo $isOpen ? '00' : '00'; ?></strong><span>Hari</span></div>
                            <div><strong id="hours"><?php echo $isOpen ? '00' : '00'; ?></strong><span>Jam</span></div>
                            <div><strong id="minutes"><?php echo $isOpen ? '00' : '00'; ?></strong><span>Menit</span></div>
                            <div><strong id="seconds"><?php echo $isOpen ? '00' : '00'; ?></strong><span>Detik</span></div>
                        </div>
                        <?php if ($isOpen): ?>
                            <p class="student-help mb-0 mt-3">
                                Silakan masukkan NISN untuk melihat hasil kelulusan.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isOpen): ?>
                        <div class="alert alert-success mt-4 mb-0" role="alert">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Form pencarian akan aktif otomatis saat hitung mundur selesai.
                        </div>
                    <?php else: ?>
                        <form id="search-form" class="student-search-form" autocomplete="off">
                            <label for="nisn-input" class="form-label">Nomor Induk Siswa Nasional</label>
                            <div class="student-input-wrap">
                                <i class="fa-solid fa-id-card"></i>
                                <input
                                    type="text"
                                    id="nisn-input"
                                    class="form-control"
                                    placeholder="Contoh: 0012345686"
                                    inputmode="numeric"
                                    maxlength="20"
                                    required
                                >
                            </div>
                            <button class="btn btn-student-search w-100 mt-3" type="submit">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Lihat Hasil
                            </button>
                            <p class="student-help mb-0 mt-3">
                                Pastikan angka NISN diketik lengkap, termasuk angka 0 di depan.
                            </p>
                        </form>

                        <div id="loading-spinner" class="text-center d-none mt-4">
                            <div class="spinner-border student-spinner" role="status">
                                <span class="visually-hidden">Mencari...</span>
                            </div>
                            <p class="mt-2 mb-0 text-muted">Sedang mencari data siswa...</p>
                        </div>

                        <div id="error-message" class="alert alert-warning d-none mt-4" role="alert">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            <span id="error-text"></span>
                        </div>

                        <div id="result-container" class="d-none mt-4"></div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <section class="student-info-row">
            <div>
                <i class="fa-solid fa-file-lines"></i>
                <span>Hasil sesuai keputusan resmi sekolah.</span>
            </div>
            <div>
                <i class="fa-solid fa-print"></i>
                <span>SKL bisa dicetak setelah data ditemukan.</span>
            </div>
            <div>
                <i class="fa-solid fa-location-dot"></i>
                <span><?php echo e($schoolAddress); ?></span>
            </div>
        </section>
    </div>
</main>

<footer class="student-footer">
    <div class="container text-center">
        <strong><?php echo e($schoolName); ?></strong>
        <span>&copy; <?php echo date('Y'); ?> Pengumuman Kelulusan Kelas VI</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const isOpen = <?php echo $isOpen ? 'true' : 'false'; ?>;

if (!isOpen) {
    const targetTime = new Date('<?php echo e($targetDate->format(DateTime::ATOM)); ?>').getTime();

    function updateCountdown() {
        const timeLeft = targetTime - Date.now();
        if (timeLeft <= 0) {
            setTimeout(() => location.reload(), 800);
            return;
        }

        const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
        const hours = Math.floor((timeLeft / (1000 * 60 * 60)) % 24);
        const minutes = Math.floor((timeLeft / (1000 * 60)) % 60);
        const seconds = Math.floor((timeLeft / 1000) % 60);

        document.getElementById('days').textContent = String(days).padStart(2, '0');
        document.getElementById('hours').textContent = String(hours).padStart(2, '0');
        document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
        document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
}

const searchForm = document.getElementById('search-form');
if (searchForm) {
    searchForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const nisn = document.getElementById('nisn-input').value.trim();
        const loadingSpinner = document.getElementById('loading-spinner');
        const errorMessage = document.getElementById('error-message');
        const resultContainer = document.getElementById('result-container');
        const errorText = document.getElementById('error-text');

        if (!/^\d{6,20}$/.test(nisn)) {
            errorMessage.classList.remove('d-none');
            errorText.textContent = 'NISN harus berupa 6 sampai 20 digit angka.';
            resultContainer.classList.add('d-none');
            return;
        }

        loadingSpinner.classList.remove('d-none');
        errorMessage.classList.add('d-none');
        resultContainer.classList.add('d-none');

        try {
            const response = await fetch('cek_nisn.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'nisn=' + encodeURIComponent(nisn)
            });
            const data = await response.json();

            loadingSpinner.classList.add('d-none');

            if (data.success) {
                resultContainer.innerHTML = data.html;
                resultContainer.classList.remove('d-none');
                if (data.siswa && data.siswa.status_kelulusan === 'LULUS') {
                    showGraduationCertificate(data.siswa);
                }
                return;
            }

            errorMessage.classList.remove('d-none');
            errorText.textContent = data.message || 'Data siswa tidak ditemukan.';
        } catch (error) {
            loadingSpinner.classList.add('d-none');
            errorMessage.classList.remove('d-none');
            errorText.textContent = 'Terjadi kesalahan koneksi. Silakan coba lagi.';
        }
    });
}

function showGraduationCertificate(siswa) {
    const oldCertificate = document.querySelector('.certificate-overlay');
    if (oldCertificate) {
        oldCertificate.remove();
    }

    const overlay = document.createElement('div');
    overlay.className = 'certificate-overlay';
    overlay.innerHTML = `
        <section class="graduation-certificate" role="dialog" aria-modal="true" aria-label="PIAGAM KELULUSAN">
            <button type="button" class="certificate-close" aria-label="Tutup piagam">&times;</button>
            <div class="certificate-corners" aria-hidden="true">
                <span></span><span></span><span></span><span></span>
            </div>
            <div class="certificate-inner">
                <div class="certificate-ribbon">
                    <i class="fa-solid fa-award"></i>
                </div>
                <p class="certificate-kicker">Certificate of Graduation</p>
                <h2>PIAGAM KELULUSAN</h2>
                <p class="certificate-school"><?php echo e($schoolName); ?></p>
                <div class="certificate-divider"><span></span></div>
                <p class="certificate-presented">Diberikan dengan hormat kepada</p>
                <div class="certificate-name">${escapeHtml(siswa.nama)}</div>
                <p class="certificate-copy">
                    Atas keberhasilannya menyelesaikan Ujian Sumatif Akhir Kelas VI dan dinyatakan
                    <strong>LULUS</strong> pada Tahun Ajaran <?php echo e($tahunAjaran); ?>.
                </p>
                <div class="certificate-meta">
                    <span><strong>NISN</strong>${escapeHtml(siswa.nisn)}</span>
                    <span><strong>Kelas</strong>${escapeHtml(siswa.kelas)}</span>
                    <span><strong>Status</strong>LULUS</span>
                </div>
                <div class="certificate-actions">
                    <a class="btn btn-student-search" href="cetak.php?nisn=${encodeURIComponent(siswa.nisn)}" target="_blank" rel="noopener">
                        <i class="fa-solid fa-print me-1"></i> Cetak SKL
                    </a>
                    <button type="button" class="btn btn-outline-success certificate-ok">Tutup</button>
                </div>
            </div>
        </section>
    `;

    document.body.appendChild(overlay);
    overlay.querySelector('.certificate-close').addEventListener('click', () => overlay.remove());
    overlay.querySelector('.certificate-ok').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            overlay.remove();
        }
    });
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
</script>
</body>
</html>
