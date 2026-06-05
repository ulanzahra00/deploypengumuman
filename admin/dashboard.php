<?php
require_once __DIR__ . '/../config.php';
require_admin();
ensure_graduation_schema($pdo);

$message = '';
$error = '';

function import_header_key(string $header): string
{
    $header = strtolower(trim($header));
    $header = str_replace(['.', '-', '/', '(', ')'], ' ', $header);
    $header = preg_replace('/\s+/', '_', $header) ?? $header;
    return trim($header, '_');
}

function normalize_import_subject(string $subject): string
{
    $key = import_header_key($subject);
    if (in_array($key, ['muatan_lokal_bahasa_mongondow', 'bahasa_mongondow', 'nilai_bahasa_mongondow'], true)) {
        return 'a. Bahasa Mongondow';
    }
    if (in_array($key, ['pendidikan_lingkungan_hidup_dan_budaya', 'nilai_pendidikan_lingkungan_hidup_dan_budaya'], true)) {
        return 'b. Pendidikan Lingkungan Hidup dan Budaya';
    }
    if ($key === 'muatan_lokal') {
        return 'Muatan Lokal';
    }
    return trim($subject);
}

function xlsx_column_index(string $cellRef): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef)) ?? '';
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return max(0, $index - 1);
}

function read_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File XLSX tidak bisa dibuka.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared !== false) {
            foreach ($shared->si as $item) {
                $text = '';
                if (isset($item->t)) {
                    $text = (string) $item->t;
                } else {
                    foreach ($item->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Sheet pertama tidak ditemukan pada file XLSX.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Isi XLSX tidak valid.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $attributes = $cell->attributes();
            $index = xlsx_column_index((string) ($attributes['r'] ?? 'A'));
            $type = (string) ($attributes['t'] ?? '');
            $raw = isset($cell->v) ? (string) $cell->v : '';

            if ($type === 's') {
                $raw = $sharedStrings[(int) $raw] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $raw = (string) $cell->is->t;
            }

            $values[$index] = trim($raw);
        }

        if ($values) {
            ksort($values);
            $max = max(array_keys($values));
            $rows[] = array_map(static fn($value) => (string) $value, array_replace(array_fill(0, $max + 1, ''), $values));
        }
    }

    return $rows;
}

function read_csv_rows(string $path): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('File CSV tidak bisa dibuka.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        return [];
    }
    rewind($handle);

    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = array_map(static fn($value) => trim((string) $value), $row);
    }
    fclose($handle);
    return $rows;
}

function read_import_rows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file gagal.');
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($extension === 'xlsx') {
        return read_xlsx_rows((string) $file['tmp_name']);
    }
    if ($extension === 'csv') {
        return read_csv_rows((string) $file['tmp_name']);
    }

    throw new RuntimeException('Format file harus .xlsx atau .csv.');
}

function import_students_and_scores(PDO $pdo, array $file): array
{
    $rows = read_import_rows($file);
    $rows = array_values(array_filter($rows, static fn($row) => count(array_filter($row, static fn($value) => trim((string) $value) !== '')) > 0));
    if (count($rows) < 2) {
        throw new RuntimeException('File import harus memiliki header dan minimal 1 baris data.');
    }

    $header = array_shift($rows);
    $knownColumns = [
        'nisn' => 'nisn',
        'nomor_induk_siswa_nasional' => 'nisn',
        'nama' => 'nama',
        'nama_lengkap' => 'nama',
        'kelas' => 'kelas',
        'status' => 'status_kelulusan',
        'status_kelulusan' => 'status_kelulusan',
        'nomor_induk_siswa' => 'nomor_induk_siswa',
        'nis' => 'nomor_induk_siswa',
        'nomor_surat_skl' => 'nomor_surat_skl_siswa',
        'nomor_surat_skl_siswa' => 'nomor_surat_skl_siswa',
        'nomor_skl' => 'nomor_surat_skl_siswa',
        'tempat_lahir' => 'tempat_lahir',
        'tanggal_lahir' => 'tanggal_lahir',
        'tempat_tanggal_lahir' => 'tempat_tanggal_lahir',
    ];

    $columns = [];
    foreach ($header as $index => $label) {
        $label = trim((string) $label);
        $key = import_header_key($label);
        $columns[$index] = [
            'label' => $label,
            'field' => $knownColumns[$key] ?? null,
            'subject' => normalize_import_subject(str_starts_with($key, 'nilai_') ? trim(substr($label, 6)) : $label),
        ];
    }

    $imported = 0;
    $scoresImported = 0;
    $skipped = 0;
    $seenNisn = [];
    $duplicateNisn = [];
    $totalDataRows = count($rows);
    $pdo->beginTransaction();

    try {
        foreach ($rows as $row) {
            $data = [
                'nisn' => '',
                'nama' => '',
                'kelas' => 'VI',
                'status_kelulusan' => 'LULUS',
                'nomor_induk_siswa' => '',
                'nomor_surat_skl_siswa' => '',
                'tempat_lahir' => '',
                'tanggal_lahir' => null,
            ];
            $scores = [];

            foreach ($columns as $index => $column) {
                $value = clean_input($row[$index] ?? '');
                if ($column['field']) {
                    if ($column['field'] === 'tempat_tanggal_lahir') {
                        $parts = array_map('trim', explode(',', $value, 2));
                        $data['tempat_lahir'] = $parts[0] ?? '';
                        $data['tanggal_lahir'] = parse_import_date($parts[1] ?? '') ?? null;
                    } elseif ($column['field'] === 'tanggal_lahir') {
                        $data['tanggal_lahir'] = parse_import_date($value);
                    } else {
                        $data[$column['field']] = $value;
                    }
                    continue;
                }

                if ($column['subject'] !== '' && $column['subject'] !== 'Muatan Lokal' && $value !== '' && is_numeric(str_replace(',', '.', $value))) {
                    $scores[$column['subject']] = (float) str_replace(',', '.', $value);
                }
            }

            $data['nisn'] = only_digits($data['nisn']);
            $data['status_kelulusan'] = strtoupper($data['status_kelulusan']) === 'TIDAK LULUS' ? 'TIDAK LULUS' : 'LULUS';
            if ($data['nisn'] === '' || $data['nama'] === '') {
                $skipped++;
                continue;
            }
            if (isset($seenNisn[$data['nisn']])) {
                $duplicateNisn[$data['nisn']] = true;
            }
            $seenNisn[$data['nisn']] = true;

            $stmt = $pdo->prepare(
                'INSERT INTO siswa (nisn, nama, kelas, nomor_induk_siswa, nomor_surat_skl_siswa, tempat_lahir, tanggal_lahir, status_kelulusan)
                 VALUES (:nisn, :nama, :kelas, :nomor_induk_siswa, :nomor_surat_skl_siswa, :tempat_lahir, :tanggal_lahir, :status_kelulusan)
                 ON DUPLICATE KEY UPDATE
                    nama = VALUES(nama),
                    kelas = VALUES(kelas),
                    nomor_induk_siswa = VALUES(nomor_induk_siswa),
                    nomor_surat_skl_siswa = VALUES(nomor_surat_skl_siswa),
                    tempat_lahir = VALUES(tempat_lahir),
                    tanggal_lahir = VALUES(tanggal_lahir),
                    status_kelulusan = VALUES(status_kelulusan)'
            );
            $stmt->execute($data);

            $studentIdStmt = $pdo->prepare('SELECT id FROM siswa WHERE nisn = :nisn LIMIT 1');
            $studentIdStmt->execute(['nisn' => $data['nisn']]);
            $studentId = (int) $studentIdStmt->fetchColumn();

            $pdo->prepare('DELETE FROM nilai_siswa WHERE siswa_id = :siswa_id')->execute(['siswa_id' => $studentId]);
            $order = 1;
            foreach ($scores as $subject => $score) {
                $scoreStmt = $pdo->prepare(
                    'INSERT INTO nilai_siswa (siswa_id, mata_pelajaran, nilai, urutan)
                     VALUES (:siswa_id, :mata_pelajaran, :nilai, :urutan)'
                );
                $scoreStmt->execute([
                    'siswa_id' => $studentId,
                    'mata_pelajaran' => $subject,
                    'nilai' => $score,
                    'urutan' => $order++,
                ]);
                $scoresImported++;
            }

            $imported++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'rows' => $totalDataRows,
        'students' => $imported,
        'scores' => $scoresImported,
        'skipped' => $skipped,
        'duplicates' => count($duplicateNisn),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $action = clean_input($_POST['action'] ?? '');

        try {
            if ($action === 'save_setting') {
                $waktu = clean_input($_POST['waktu_pengumuman'] ?? '');
                $nomorSuratSkl = clean_input($_POST['nomor_surat_skl'] ?? '');
                $tahunAjaran = clean_input($_POST['tahun_ajaran'] ?? '');
                $nomorSuratKeputusan = clean_input($_POST['nomor_surat_keputusan'] ?? '');
                $tanggalSurat = clean_input($_POST['tanggal_surat'] ?? '');
                $tempatSurat = clean_input($_POST['tempat_surat'] ?? '');
                $kepalaSekolah = clean_input($_POST['kepala_sekolah'] ?? '');
                $nipKepalaSekolah = clean_input($_POST['nip_kepala_sekolah'] ?? '');
                if (!DateTime::createFromFormat('Y-m-d\TH:i', $waktu)) {
                    throw new RuntimeException('Format tanggal dan jam tidak valid.');
                }
                if ($nomorSuratSkl === '') {
                    throw new RuntimeException('Nomor surat SKL wajib diisi.');
                }
                if ($tahunAjaran === '' || $nomorSuratKeputusan === '' || $tanggalSurat === '' || $tempatSurat === '' || $kepalaSekolah === '') {
                    throw new RuntimeException('Pengaturan SKL wajib diisi lengkap.');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO pengaturan (kunci_pengaturan, nilai_pengaturan)
                     VALUES (:kunci, :nilai)
                     ON DUPLICATE KEY UPDATE nilai_pengaturan = VALUES(nilai_pengaturan)'
                );
                $settings = [
                    'waktu_pengumuman' => $waktu,
                    'tahun_ajaran' => $tahunAjaran,
                    'nomor_surat_skl' => $nomorSuratSkl,
                    'nomor_surat_keputusan' => $nomorSuratKeputusan,
                    'tanggal_surat' => $tanggalSurat,
                    'tempat_surat' => $tempatSurat,
                    'kepala_sekolah' => $kepalaSekolah,
                    'nip_kepala_sekolah' => $nipKepalaSekolah,
                ];
                foreach ($settings as $key => $value) {
                    $stmt->execute(['kunci' => $key, 'nilai' => $value]);
                }
                $message = 'Pengaturan berhasil diperbarui.';
            }

            if ($action === 'save_student') {
                $id = (int) ($_POST['id'] ?? 0);
                $nisn = only_digits($_POST['nisn'] ?? '');
                $nama = clean_input($_POST['nama'] ?? '');
                $kelas = clean_input($_POST['kelas'] ?? '');
                $nomorIndukSiswa = clean_input($_POST['nomor_induk_siswa'] ?? '');
                $nomorSuratSklSiswa = clean_input($_POST['nomor_surat_skl_siswa'] ?? '');
                $tempatLahir = clean_input($_POST['tempat_lahir'] ?? '');
                $tanggalLahir = parse_import_date($_POST['tanggal_lahir'] ?? '');
                $status = clean_input($_POST['status_kelulusan'] ?? '');

                if ($nisn === '' || strlen($nisn) < 6 || strlen($nisn) > 20) {
                    throw new RuntimeException('NISN harus 6 sampai 20 digit angka.');
                }
                if ($nama === '' || $kelas === '') {
                    throw new RuntimeException('Nama dan kelas wajib diisi.');
                }
                if (!in_array($status, ['LULUS', 'TIDAK LULUS'], true)) {
                    throw new RuntimeException('Status kelulusan tidak valid.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE siswa
                         SET nisn = :nisn,
                             nama = :nama,
                             kelas = :kelas,
                             nomor_induk_siswa = :nomor_induk_siswa,
                             nomor_surat_skl_siswa = :nomor_surat_skl_siswa,
                             tempat_lahir = :tempat_lahir,
                             tanggal_lahir = :tanggal_lahir,
                             status_kelulusan = :status
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'nisn' => $nisn,
                        'nama' => $nama,
                        'kelas' => $kelas,
                        'nomor_induk_siswa' => $nomorIndukSiswa,
                        'nomor_surat_skl_siswa' => $nomorSuratSklSiswa,
                        'tempat_lahir' => $tempatLahir,
                        'tanggal_lahir' => $tanggalLahir,
                        'status' => $status,
                        'id' => $id,
                    ]);
                    $message = 'Data siswa berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO siswa (nisn, nama, kelas, nomor_induk_siswa, nomor_surat_skl_siswa, tempat_lahir, tanggal_lahir, status_kelulusan)
                         VALUES (:nisn, :nama, :kelas, :nomor_induk_siswa, :nomor_surat_skl_siswa, :tempat_lahir, :tanggal_lahir, :status)'
                    );
                    $stmt->execute([
                        'nisn' => $nisn,
                        'nama' => $nama,
                        'kelas' => $kelas,
                        'nomor_induk_siswa' => $nomorIndukSiswa,
                        'nomor_surat_skl_siswa' => $nomorSuratSklSiswa,
                        'tempat_lahir' => $tempatLahir,
                        'tanggal_lahir' => $tanggalLahir,
                        'status' => $status,
                    ]);
                    $message = 'Data siswa berhasil ditambahkan.';
                }
            }

            if ($action === 'delete_student') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('ID siswa tidak valid.');
                }

                $stmt = $pdo->prepare('DELETE FROM siswa WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $message = 'Data siswa berhasil dihapus.';
            }

            if ($action === 'delete_all_students') {
                $pdo->exec('DELETE FROM siswa');
                $message = 'Semua data siswa dan nilai berhasil dihapus.';
            }

            if ($action === 'import_scores') {
                $result = import_students_and_scores($pdo, $_FILES['file_import'] ?? []);
                $message = 'Import berhasil: ' . $result['rows'] . ' baris dibaca, ' . $result['students'] . ' siswa dan ' . $result['scores'] . ' nilai diproses.';
                if ($result['skipped'] > 0) {
                    $message .= ' ' . $result['skipped'] . ' baris dilewati karena NISN atau nama kosong.';
                }
                if ($result['duplicates'] > 0) {
                    $message .= ' Ada ' . $result['duplicates'] . ' NISN duplikat di file, data terakhir menimpa data sebelumnya.';
                }
            }
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000'
                ? 'NISN sudah digunakan oleh siswa lain.'
                : 'Terjadi kesalahan database.';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editStudent = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM siswa WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $editStudent = $stmt->fetch() ?: null;
}

$stats = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM siswa')->fetchColumn(),
    'lulus' => (int) $pdo->query("SELECT COUNT(*) FROM siswa WHERE status_kelulusan = 'LULUS'")->fetchColumn(),
    'tidak_lulus' => (int) $pdo->query("SELECT COUNT(*) FROM siswa WHERE status_kelulusan = 'TIDAK LULUS'")->fetchColumn(),
];

$waktuPengumuman = get_setting($pdo, 'waktu_pengumuman', date('Y-m-d\TH:i'));
$tahunAjaranSetting = get_setting($pdo, 'tahun_ajaran', '2025-2026');
$nomorSuratSkl = get_setting($pdo, 'nomor_surat_skl', '422/C1/SDN1MLW/2026');
$nomorSuratKeputusan = get_setting($pdo, 'nomor_surat_keputusan', '. . ./C.1/SDN1MLW/VI/2026');
$tanggalSurat = get_setting($pdo, 'tanggal_surat', '2 Juni 2026');
$tempatSurat = get_setting($pdo, 'tempat_surat', 'Kotamobagu');
$kepalaSekolahSetting = get_setting($pdo, 'kepala_sekolah', 'GANTI NAMA KEPALA SEKOLAH, S.Pd');
$nipKepalaSekolahSetting = get_setting($pdo, 'nip_kepala_sekolah', '');
$stmt = $pdo->query('SELECT id, nisn, nama, kelas, nomor_induk_siswa, nomor_surat_skl_siswa, tempat_lahir, tanggal_lahir, status_kelulusan FROM siswa ORDER BY nama ASC');
$students = $stmt->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Admin - <?= e(SCHOOL_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
</head>
<body class="admin-page">
<nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <span class="brand-mark"><i class="fa-solid fa-school"></i></span>
            <span>
                <strong>Dashboard Admin</strong>
                <small class="d-block"><?= e(SCHOOL_NAME); ?></small>
            </span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-light btn-sm" href="../index.php" target="_blank" rel="noopener">
                <i class="fa-solid fa-globe me-1"></i> Publik
            </a>
            <a class="btn btn-light btn-sm" href="../logout.php">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<main class="container-fluid px-lg-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="eyebrow text-maroon mb-2">Administrator</p>
            <h1 class="h3 mb-0">Pengelolaan Kelulusan Kelas VI</h1>
        </div>
        <div class="text-muted">Login sebagai <strong><?= e($_SESSION['admin_username'] ?? 'admin'); ?></strong></div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= e($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div><span>Total Siswa</span><strong><?= e((string) $stats['total']); ?></strong></div>
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card success">
                <div><span>Lulus</span><strong><?= e((string) $stats['lulus']); ?></strong></div>
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card danger">
                <div><span>Tidak Lulus</span><strong><?= e((string) $stats['tidak_lulus']); ?></strong></div>
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <section class="admin-panel mb-4">
                <button
                    class="btn btn-link w-100 d-flex justify-content-between align-items-center p-0 text-decoration-none text-dark"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#settingsFormCollapse"
                    aria-expanded="false"
                    aria-controls="settingsFormCollapse"
                >
                    <span class="h5 mb-0"><i class="fa-solid fa-gear me-2 text-maroon"></i>Pengaturan</span>
                    <i class="fa-solid fa-chevron-down text-muted"></i>
                </button>
                <div class="collapse mt-3" id="settingsFormCollapse">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_setting">
                        <div class="mb-3">
                            <label class="form-label" for="waktu_pengumuman">Tanggal dan Jam Aktif</label>
                            <input type="datetime-local" class="form-control" id="waktu_pengumuman" name="waktu_pengumuman" value="<?= e($waktuPengumuman); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="tahun_ajaran">Tahun Ajaran</label>
                            <input type="text" class="form-control" id="tahun_ajaran" name="tahun_ajaran" value="<?= e($tahunAjaranSetting); ?>" maxlength="30" placeholder="2025-2026" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nomor_surat_skl">Nomor Surat SKL</label>
                            <input type="text" class="form-control" id="nomor_surat_skl" name="nomor_surat_skl" value="<?= e($nomorSuratSkl); ?>" maxlength="100" placeholder="Contoh: 422/C1/SDN1MLW/2026" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nomor_surat_keputusan">Nomor Keputusan Kelulusan</label>
                            <input type="text" class="form-control" id="nomor_surat_keputusan" name="nomor_surat_keputusan" value="<?= e($nomorSuratKeputusan); ?>" maxlength="120" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label" for="tempat_surat">Tempat Surat</label>
                                <input type="text" class="form-control" id="tempat_surat" name="tempat_surat" value="<?= e($tempatSurat); ?>" maxlength="80" required>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label" for="tanggal_surat">Tanggal Surat</label>
                                <input type="text" class="form-control" id="tanggal_surat" name="tanggal_surat" value="<?= e($tanggalSurat); ?>" maxlength="80" placeholder="2 Juni 2026" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="kepala_sekolah">Kepala Sekolah</label>
                            <input type="text" class="form-control" id="kepala_sekolah" name="kepala_sekolah" value="<?= e($kepalaSekolahSetting); ?>" maxlength="120" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nip_kepala_sekolah">NIP Kepala Sekolah</label>
                            <input type="text" class="form-control" id="nip_kepala_sekolah" name="nip_kepala_sekolah" value="<?= e($nipKepalaSekolahSetting); ?>" maxlength="40">
                        </div>
                        <button class="btn btn-maroon mt-3" type="submit">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Pengaturan
                        </button>
                    </form>
                </div>
            </section>

            <section class="admin-panel mb-4">
                <h2 class="h5 mb-3"><i class="fa-solid fa-file-excel me-2 text-maroon"></i>Import Excel Nilai</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="import_scores">
                    <div class="mb-3">
                        <label class="form-label" for="file_import">File .xlsx atau .csv</label>
                        <input type="file" class="form-control" id="file_import" name="file_import" accept=".xlsx,.csv" required>
                    </div>
                    <p class="small text-muted">
                        Header wajib: nisn, nama. Header opsional: kelas, status_kelulusan, nomor_induk_siswa, nomor_surat_skl, tempat_lahir, tanggal_lahir.
                        Header lainnya yang berisi angka akan dianggap sebagai mata pelajaran.
                        <a href="../template_import_nilai.csv" download>Unduh template CSV</a>.
                    </p>
                    <button class="btn btn-outline-success" type="submit">
                        <i class="fa-solid fa-upload me-1"></i> Import Data
                    </button>
                </form>
            </section>

            <section class="admin-panel">
                <button
                    class="btn btn-link w-100 d-flex justify-content-between align-items-center p-0 text-decoration-none text-dark"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#studentFormCollapse"
                    aria-expanded="<?= $editStudent ? 'true' : 'false'; ?>"
                    aria-controls="studentFormCollapse"
                >
                    <span class="h5 mb-0">
                        <i class="fa-solid fa-user-graduate me-2 text-maroon"></i>
                        <?= $editStudent ? 'Edit Siswa' : 'Tambah Siswa'; ?>
                    </span>
                    <i class="fa-solid fa-chevron-down text-muted"></i>
                </button>
                <div class="collapse <?= $editStudent ? 'show' : ''; ?> mt-3" id="studentFormCollapse">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_student">
                        <input type="hidden" name="id" value="<?= e((string) ($editStudent['id'] ?? 0)); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="nisn">NISN</label>
                            <input class="form-control" id="nisn" name="nisn" inputmode="numeric" maxlength="20" value="<?= e($editStudent['nisn'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nama">Nama Siswa</label>
                            <input class="form-control" id="nama" name="nama" maxlength="120" value="<?= e($editStudent['nama'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="kelas">Kelas</label>
                            <input class="form-control" id="kelas" name="kelas" maxlength="20" value="<?= e($editStudent['kelas'] ?? 'VI'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nomor_induk_siswa">Nomor Induk Siswa</label>
                            <input class="form-control" id="nomor_induk_siswa" name="nomor_induk_siswa" maxlength="30" value="<?= e($editStudent['nomor_induk_siswa'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="nomor_surat_skl_siswa">Nomor Surat SKL Siswa</label>
                            <input class="form-control" id="nomor_surat_skl_siswa" name="nomor_surat_skl_siswa" maxlength="120" value="<?= e($editStudent['nomor_surat_skl_siswa'] ?? ''); ?>" placeholder="Kosongkan untuk memakai nomor default">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="tempat_lahir">Tempat Lahir</label>
                            <input class="form-control" id="tempat_lahir" name="tempat_lahir" maxlength="100" value="<?= e($editStudent['tempat_lahir'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="tanggal_lahir">Tanggal Lahir</label>
                            <input type="date" class="form-control" id="tanggal_lahir" name="tanggal_lahir" value="<?= e($editStudent['tanggal_lahir'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="status_kelulusan">Status Kelulusan</label>
                            <select class="form-select" id="status_kelulusan" name="status_kelulusan" required>
                                <?php $selectedStatus = $editStudent['status_kelulusan'] ?? 'LULUS'; ?>
                                <option value="LULUS" <?= $selectedStatus === 'LULUS' ? 'selected' : ''; ?>>LULUS</option>
                                <option value="TIDAK LULUS" <?= $selectedStatus === 'TIDAK LULUS' ? 'selected' : ''; ?>>TIDAK LULUS</option>
                            </select>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-maroon" type="submit">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Simpan
                            </button>
                            <?php if ($editStudent): ?>
                                <a class="btn btn-outline-secondary" href="dashboard.php">Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-md-8">
            <section class="admin-panel">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-table-list me-2 text-maroon"></i>Data Siswa</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small"><?= count($students); ?> data</span>
                        <?php if ($students): ?>
                            <a class="btn btn-sm btn-outline-success" href="../cetak_semua.php" target="_blank" rel="noopener">
                                <i class="fa-solid fa-print me-1"></i> Cetak Semua
                            </a>
                            <form method="post" onsubmit="return confirm('Hapus SEMUA data siswa dan nilai? Tindakan ini tidak bisa dibatalkan.');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete_all_students">
                                <button class="btn btn-sm btn-outline-danger" type="submit">
                                    <i class="fa-solid fa-trash-can me-1"></i> Hapus Semua
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle admin-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>NISN</th>
                                <th>NIS</th>
                                <th>No. SKL</th>
                                <th>TTL</th>
                                <th>Kelas</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$students): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Belum ada data siswa.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($student['nama']); ?></td>
                                <td><?= e($student['nisn']); ?></td>
                                <td><?= e($student['nomor_induk_siswa'] ?? '-'); ?></td>
                                <td><?= e(($student['nomor_surat_skl_siswa'] ?? '') !== '' ? $student['nomor_surat_skl_siswa'] : '-'); ?></td>
                                <td>
                                    <?= e(trim(($student['tempat_lahir'] ?? '') . (($student['tanggal_lahir'] ?? '') ? ', ' . format_indonesian_date($student['tanggal_lahir']) : '')) ?: '-'); ?>
                                </td>
                                <td><?= e($student['kelas']); ?></td>
                                <td>
                                    <span class="badge <?= $student['status_kelulusan'] === 'LULUS' ? 'bg-success' : 'bg-danger'; ?>">
                                        <?= e($student['status_kelulusan']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="dashboard.php?edit=<?= e((string) $student['id']); ?>">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus data siswa ini?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="id" value="<?= e((string) $student['id']); ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
