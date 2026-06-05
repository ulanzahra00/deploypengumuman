<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_admin();
ensure_graduation_schema($pdo);

function normalize_skl_subject_bulk(string $subject): string
{
    $subject = trim($subject);
    if (strcasecmp($subject, 'Muatan Lokal Bahasa Mongondow') === 0 || strcasecmp($subject, 'Bahasa Mongondow') === 0) {
        return 'a. Bahasa Mongondow';
    }
    if (strcasecmp($subject, 'Pendidikan Lingkungan Hidup dan Budaya') === 0) {
        return 'b. Pendidikan Lingkungan Hidup dan Budaya';
    }
    return $subject;
}

function build_display_scores(array $scores): array
{
    $scoresBySubject = [];
    foreach ($scores as $score) {
        $scoresBySubject[normalize_skl_subject_bulk((string) $score['mata_pelajaran'])] = $score['nilai'];
    }

    $displayScores = [];
    foreach (DEFAULT_SKL_SUBJECTS as $subject) {
        $displayScores[] = [
            'mata_pelajaran' => $subject,
            'nilai' => $scoresBySubject[$subject] ?? '',
        ];
        unset($scoresBySubject[$subject]);
    }

    foreach ($scoresBySubject as $subject => $value) {
        $displayScores[] = [
            'mata_pelajaran' => $subject,
            'nilai' => $value,
        ];
    }

    return $displayScores;
}

function score_average(array $scores): string
{
    $numericScores = array_values(array_filter(array_map(
        static fn(array $row): ?float => is_numeric((string) $row['nilai']) ? (float) $row['nilai'] : null,
        $scores
    ), static fn($value) => $value !== null));

    return $numericScores ? (string) round(array_sum($numericScores) / count($numericScores)) : '';
}

try {
    $students = $pdo->query(
        'SELECT id, nisn, nama, kelas, nomor_induk_siswa, nomor_surat_skl_siswa, tempat_lahir, tanggal_lahir, status_kelulusan
         FROM siswa
         ORDER BY nama ASC'
    )->fetchAll();

    $scoresByStudent = [];
    if ($students) {
        $ids = array_map(static fn(array $student): int => (int) $student['id'], $students);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $scoreStmt = $pdo->prepare(
            'SELECT siswa_id, mata_pelajaran, nilai
             FROM nilai_siswa
             WHERE siswa_id IN (' . $placeholders . ')
             ORDER BY siswa_id ASC, urutan ASC, mata_pelajaran ASC'
        );
        $scoreStmt->execute($ids);
        foreach ($scoreStmt->fetchAll() as $score) {
            $scoresByStudent[(int) $score['siswa_id']][] = $score;
        }
    }
} catch (Exception $e) {
    error_log('Error cetak semua SKL: ' . $e->getMessage());
    http_response_code(500);
    exit('<div style="padding:20px;text-align:center"><h2>Terjadi kesalahan</h2><p><a href="admin/dashboard.php">Kembali</a></p></div>');
}

$schoolName = get_setting($pdo, 'nama_sekolah', SCHOOL_NAME);
$kepalaSekolah = get_setting($pdo, 'kepala_sekolah', 'GANTI NAMA KEPALA SEKOLAH, S.Pd');
$nipKepalaSekolah = get_setting($pdo, 'nip_kepala_sekolah', '');
$tahunAjaran = get_setting($pdo, 'tahun_ajaran', '2025-2026');
$nomorSuratSklDefault = get_setting($pdo, 'nomor_surat_skl', '422/C1/SDN1MLW/2026');
$nomorSuratKeputusan = get_setting($pdo, 'nomor_surat_keputusan', '. . ./C.1/SDN1MLW/VI/2026');
$tanggalSurat = get_setting($pdo, 'tanggal_surat', '2 Juni 2026');
$tempatSurat = get_setting($pdo, 'tempat_surat', 'Kotamobagu');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cetak Semua Surat Keterangan Lulus</title>
    <style>
        * {
            box-sizing: border-box;
            letter-spacing: 0;
        }

        body {
            margin: 0;
            padding: 18px;
            color: #111;
            background: #e9ecef;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
        }

        .print-controls {
            margin: 0 auto 16px;
            text-align: center;
        }

        .btn {
            display: inline-block;
            margin: 4px;
            padding: 10px 16px;
            color: #fff;
            background: #1f2937;
            border: 0;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }

        .btn.secondary {
            background: #6b7280;
        }

        .paper {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 18px;
            padding: 18mm 22mm 18mm;
            background: #fff;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.16);
            page-break-after: always;
            break-after: page;
        }

        .paper:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .letterhead {
            position: relative;
            margin-bottom: 22px;
            padding-bottom: 8px;
            text-align: center;
            border-bottom: 2px solid #111;
            font-weight: 700;
            font-size: 15pt;
            line-height: 1.35;
        }

        .letterhead .school {
            font-size: 15pt;
        }

        .letterhead-logo {
            position: absolute;
            left: 0;
            top: -4px;
            width: 58px;
            height: auto;
        }

        .title {
            text-align: center;
            font-weight: 700;
            font-size: 12pt;
            line-height: 1.45;
        }

        .number {
            margin-top: 4px;
            text-align: center;
            font-size: 12pt;
        }

        .body {
            margin-top: 24px;
            line-height: 1.55;
        }

        .decision-text {
            text-align: justify;
        }

        .identity {
            width: 100%;
            margin: 22px 0 18px;
            border-collapse: collapse;
        }

        .identity td {
            padding: 2px 0;
            vertical-align: top;
        }

        .identity td:first-child {
            width: 235px;
        }

        .identity td:nth-child(2) {
            width: 20px;
            text-align: center;
        }

        .status {
            margin: 22px 0 28px;
            text-align: center;
            font-size: 17pt;
            font-weight: 800;
        }

        .status span {
            display: inline-block;
            padding: 0 3px;
        }

        .status .crossed {
            text-decoration: line-through;
        }

        .score-title {
            margin: 0 0 4px;
        }

        .scores {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5pt;
        }

        .scores th,
        .scores td {
            border: 1.5px solid #111;
            padding: 5px 7px;
        }

        .scores th {
            text-align: center;
            font-weight: 700;
        }

        .scores .no {
            width: 38px;
            text-align: center;
        }

        .scores .score {
            width: 150px;
            text-align: center;
        }

        .scores .average-label,
        .scores .average-score,
        .scores .subject-group {
            font-weight: 700;
        }

        .scores .average-label,
        .scores .average-score {
            text-align: center;
        }

        .signature {
            display: flex;
            justify-content: flex-end;
            margin-top: 34px;
        }

        .signature-box {
            width: 285px;
            text-align: left;
        }

        .signature-space {
            position: relative;
            height: 86px;
        }

        .signature-image {
            position: absolute;
            left: calc(8px - 5mm);
            bottom: -5mm;
            width: 190px;
            height: auto;
            mix-blend-mode: multiply;
            opacity: 0.94;
            filter: contrast(1.08) saturate(1.14);
        }

        .principal {
            font-weight: 700;
            text-decoration: underline;
        }

        .empty-state {
            width: min(100%, 720px);
            margin: 80px auto;
            padding: 24px;
            text-align: center;
            background: #fff;
            border-radius: 8px;
        }

        @media print {
            @page {
                size: A4;
                margin: 0;
            }

            body {
                padding: 0;
                background: #fff;
            }

            .print-controls {
                display: none !important;
            }

            .paper {
                width: 210mm;
                height: 297mm;
                min-height: 0;
                margin: 0;
                padding: 10mm 18mm 7mm;
                box-shadow: none;
                overflow: hidden;
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .letterhead {
                margin-bottom: 10px;
                padding-bottom: 5px;
                line-height: 1.15;
            }

            .letterhead-logo {
                top: -2px;
                width: 46px;
            }

            .title {
                line-height: 1.25;
            }

            .number {
                margin-top: 2px;
            }

            .body {
                margin-top: 12px;
                line-height: 1.28;
            }

            .body p {
                margin: 7px 0;
            }

            .identity {
                margin: 10px 0 8px;
            }

            .identity td {
                padding: 1px 0;
            }

            .status {
                margin: 10px 0 14px;
                font-size: 14pt;
            }

            .score-title {
                margin-bottom: 2px;
            }

            .scores {
                font-size: 9.5pt;
            }

            .scores th,
            .scores td {
                padding: 2px 5px;
                border-width: 1px;
            }

            .signature {
                margin-top: calc(22px + 10mm);
            }

            .signature-space {
                height: 78px;
            }

            .signature-image {
                left: calc(12px - 5mm);
                bottom: -5mm;
                width: 165px;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button class="btn" type="button" onclick="window.print()">Cetak Semua / Simpan PDF</button>
        <a class="btn secondary" href="admin/dashboard.php">Kembali ke Admin</a>
    </div>

    <?php if (!$students): ?>
        <div class="empty-state">
            <h2>Belum ada data siswa</h2>
            <p>Import atau tambah data siswa terlebih dahulu dari dashboard admin.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($students as $siswa): ?>
        <?php
        $ttl = trim((string) ($siswa['tempat_lahir'] ?? ''));
        if (!empty($siswa['tanggal_lahir'])) {
            $ttl .= ($ttl !== '' ? ', ' : '') . format_indonesian_date($siswa['tanggal_lahir']);
        }
        $displayScores = build_display_scores($scoresByStudent[(int) $siswa['id']] ?? []);
        $average = score_average($displayScores);
        $isPassed = (string) $siswa['status_kelulusan'] === 'LULUS';
        $nomorSuratSkl = clean_input($siswa['nomor_surat_skl_siswa'] ?? '');
        if ($nomorSuratSkl === '') {
            $nomorSuratSkl = $nomorSuratSklDefault;
        }
        ?>
        <main class="paper">
            <header>
                <div class="letterhead">
                    <img class="letterhead-logo" src="assets/logo-kk.png" alt="Logo Kota Kotamobagu">
                    <div>PEMERINTAH KOTAMOBAGU</div>
                    <div>DINAS PENDIDIKAN</div>
                    <div class="school">SD NEGERI 1 MOLINOW</div>
                </div>
                <div class="title">
                    SURAT KETERANGAN LULUS<br>
                    TAHUN AJARAN <?= e($tahunAjaran); ?>
                </div>
                <div class="number">NOMOR : <?= e($nomorSuratSkl); ?></div>
            </header>

            <section class="body">
                <p>Yang bertanda tangan di bawah ini Kepala <?= e($schoolName); ?> menerangkan bahwa :</p>

                <table class="identity">
                    <tr>
                        <td>Nama Lengkap</td>
                        <td>:</td>
                        <td><?= e($siswa['nama']); ?></td>
                    </tr>
                    <tr>
                        <td>Tempat, Tanggal Lahir</td>
                        <td>:</td>
                        <td><?= e($ttl); ?></td>
                    </tr>
                    <tr>
                        <td>Nomor Induk Siswa</td>
                        <td>:</td>
                        <td><?= e($siswa['nomor_induk_siswa'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>Nomor Induk Siswa Nasional</td>
                        <td>:</td>
                        <td><?= e($siswa['nisn']); ?></td>
                    </tr>
                </table>

                <p class="decision-text">
                    Berdasarkan Keputusan Kepala <?= e($schoolName); ?> Nomor <?= e($nomorSuratKeputusan); ?>
                    Penetapan Kelulusan Peserta Didik <?= e($schoolName); ?> tahun Ajaran <?= e($tahunAjaran); ?>,
                    maka yang bersangkutan dinyatakan :
                </p>

                <div class="status">
                    <span class="<?= $isPassed ? '' : 'crossed'; ?>">LULUS</span>
                    /
                    <span class="<?= $isPassed ? 'crossed' : ''; ?>">TIDAK LULUS</span>
                </div>

                <p class="score-title">Dengan hasil sebagai berikut :</p>
                <table class="scores">
                    <thead>
                        <tr>
                            <th class="no">No</th>
                            <th>Mata pelajaran</th>
                            <th class="score">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowNo = 1; ?>
                        <?php foreach ($displayScores as $score): ?>
                            <?php
                            $subjectName = (string) $score['mata_pelajaran'];
                            $isSubjectGroup = $subjectName === 'Muatan Lokal';
                            $isLocalSubject = str_starts_with($subjectName, 'a. ') || str_starts_with($subjectName, 'b. ');
                            ?>
                            <tr>
                                <td class="no"><?= $isLocalSubject ? '' : e((string) $rowNo++); ?></td>
                                <td class="<?= $isSubjectGroup ? 'subject-group' : ''; ?>"><?= e($score['mata_pelajaran']); ?></td>
                                <td class="score"><?= is_numeric((string) $score['nilai']) ? e((string) round((float) $score['nilai'])) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="2" class="average-label">Nilai Rata-rata</td>
                            <td class="average-score"><?= e($average); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="signature">
                <div class="signature-box">
                    <div><?= e($tempatSurat); ?>, <?= e($tanggalSurat); ?></div>
                    <div>Kepala Sekolah</div>
                    <div class="signature-space">
                        <img class="signature-image" src="assets/ttd-stempel-sahyudi-amparodo-transparan.png" alt="Tanda tangan dan stempel Sahyudi Amparodo">
                    </div>
                    <div class="principal"><?= e($kepalaSekolah); ?></div>
                    <?php if ($nipKepalaSekolah !== ''): ?>
                        <div>NIP. <?= e($nipKepalaSekolah); ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    <?php endforeach; ?>
</body>
</html>
