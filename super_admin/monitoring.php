<?php

include "../config/session.php";

if (
    !isset($_SESSION['id_user']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Super Admin'
) {
    header('Location: ../index.php');
    exit;
}

include '../config/koneksi.php';

$filter_divisi = isset($_GET['divisi']) ? (int) $_GET['divisi'] : 0;

$data_divisi = [];

$sql_divisi = "
    SELECT id_divisi, nama_divisi
    FROM divisi
";

if ($filter_divisi > 0) {
    $sql_divisi .= " WHERE id_divisi = $filter_divisi ";
}

$sql_divisi .= " ORDER BY nama_divisi ASC ";

$query_divisi = mysqli_query($koneksi, $sql_divisi);

if ($query_divisi) {

    while ($divisi = mysqli_fetch_assoc($query_divisi)) {

        $id_divisi = (int) $divisi['id_divisi'];

        $total = 0;
        $jumlah_belum = 0;
        $jumlah_menunggu = 0;
        $jumlah_perbaikan = 0;
        $jumlah_peralihan = 0;
        $jumlah_selesai = 0;

        $query_total = mysqli_query(
            $koneksi,
            "SELECT COUNT(*) AS total
             FROM pekerjaan
             WHERE id_divisi = $id_divisi"
        );

        if ($query_total) {
            $row_total = mysqli_fetch_assoc($query_total);
            $total = (int) ($row_total['total'] ?? 0);
        }

        $query_status = mysqli_query(
            $koneksi,
            "SELECT status, COUNT(*) AS jumlah
             FROM pekerjaan
             WHERE id_divisi = $id_divisi
             GROUP BY status"
        );

        if ($query_status) {

            while ($row_status = mysqli_fetch_assoc($query_status)) {

                $nama_status = strtolower(trim($row_status['status'] ?? ''));
                $jumlah = (int) ($row_status['jumlah'] ?? 0);

                if ($nama_status === 'belum dikerjakan' || $nama_status === 'belum') {

                    $jumlah_belum += $jumlah;


                } elseif (
                    $nama_status === 'menunggu konfirmasi' ||
                    $nama_status === 'menunggu'
                ) {

                    $jumlah_menunggu += $jumlah;

                } elseif (
                    $nama_status === 'perlu perbaikan' ||
                    $nama_status === 'perbaikan'
                ) {

                    $jumlah_perbaikan += $jumlah;

                } elseif ($nama_status === 'peralihan') {

                    $jumlah_peralihan += $jumlah;

                } elseif ($nama_status === 'selesai' || $nama_status === 'completed') {

                    $jumlah_selesai += $jumlah;

                }
            }
        }

        $persentase = $total > 0
            ? round(($jumlah_selesai / $total) * 100)
            : 0;

        $data_divisi[] = [
            'id_divisi' => $id_divisi,
            'nama_divisi' => $divisi['nama_divisi'],
            'total' => $total,
            'belum' => $jumlah_belum,
            'menunggu' => $jumlah_menunggu,
            'perbaikan' => $jumlah_perbaikan,
            'peralihan' => $jumlah_peralihan,
            'selesai' => $jumlah_selesai,
            'persentase' => $persentase
        ];
    }
}

$semua_divisi = [];

$query_semua_divisi = mysqli_query(
    $koneksi,
    "SELECT id_divisi, nama_divisi FROM divisi ORDER BY nama_divisi ASC"
);

if ($query_semua_divisi) {
    while ($row = mysqli_fetch_assoc($query_semua_divisi)) {
        $semua_divisi[] = $row;
    }
}

/* Daftar status yang ditampilkan: [key data, label, class titik] */
$daftar_status = [
    ['belum',     'Belum Dikerjakan',    'dot-belum'],
    ['menunggu',  'Menunggu Konfirmasi', 'dot-menunggu'],
    ['perbaikan', 'Perlu Perbaikan',     'dot-perbaikan'],
    ['peralihan', 'Peralihan',           'dot-peralihan'],
    ['selesai',   'Selesai',             'dot-selesai']
];

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Monitoring - SIMO</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #172033;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            min-width: 0;
        }

        .content {
            padding: 28px 34px 36px;
        }

        /* HEADER */
        .monitoring-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 22px;
        }

        .monitoring-title h1 {
            font-size: 24px;
            line-height: 1.25;
            font-weight: 700;
            color: #172033;
            margin-bottom: 5px;
        }

        .monitoring-title p {
            font-size: 13px;
            color: #7a8596;
        }

        .division-filter {
            min-width: 190px;
            height: 42px;
            padding: 0 13px;
            border: 1px solid #d9dde2;
            border-radius: 9px;
            background: #ffffff;
            color: #344054;
            font-size: 13px;
            outline: none;
            cursor: pointer;
        }

        .division-filter:focus {
            border-color: #b51f2a;
        }

        /* GRID CARD */
        .monitoring-grid {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            align-items: stretch;
        }

        .monitoring-card {
            width: 100%;
            min-width: 0;
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 20px 20px 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            transition: transform .28s ease, box-shadow .28s ease;
        }

        .monitoring-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 18px rgba(16, 24, 40, .08);
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 13px;
            border-bottom: 1px solid #edf0f3;
        }

        .division-info {
            min-width: 0;
        }

        .division-info h3 {
            font-size: 15px;
            line-height: 1.35;
            font-weight: 700;
            color: #172033;
            margin-bottom: 3px;
        }

        .division-code {
            font-size: 11.5px;
            color: #7a8596;
        }

        .total-box {
            flex-shrink: 0;
            text-align: right;
        }

        .total-box span {
            display: block;
            font-size: 10.5px;
            color: #7a8596;
            margin-bottom: 1px;
        }

        .total-box strong {
            display: block;
            font-size: 20px;
            line-height: 1.15;
            font-weight: 700;
            color: #172033;
        }

        /* STATUS */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 30px;
            row-gap: 2px;
            margin-top: 13px;
            padding: 12px 14px;
            background: #f8f9fb;
            border-radius: 9px;
        }

        .status-item {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 30px;
            border-bottom: 0;
        }

        .status-label {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            font-size: 13px;
            font-weight: 500;
            color: #667085;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex: 0 0 8px;
        }

        /* warna titik = palet status dashboard */
        .dot-belum     { background: #98a2b3; }
        .dot-menunggu  { background: #e0a21a; }
        .dot-perbaikan { background: #d9534f; }
        .dot-peralihan { background: #8b6bd6; }
        .dot-selesai   { background: #29a35f; }

        .status-number {
            flex-shrink: 0;
            font-size: 14px;
            font-weight: 700;
            color: #172033;
        }

        /* PROGRESS */
        .progress-section {
            padding-top: 12px;
            margin-top: 11px;
            border-top: 1px solid #edf0f3;
        }

        .progress-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
        }

        .progress-head span {
            font-size: 11.5px;
            color: #7a8596;
        }

        .progress-head strong {
            font-size: 12px;
            color: #172033;
            font-weight: 700;
        }

        .progress-track {
            width: 100%;
            height: 7px;
            overflow: hidden;
            border-radius: 999px;
            background: #f0f2f4;
        }

        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: #b51f2a;
            transition: width .25s ease;
        }

        .empty-data {
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 42px 20px;
            text-align: center;
            color: #7a8596;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
        }

        .empty-data p {
            font-size: 13px;
        }

        .footer-note {
            text-align: center;
            font-size: 11.5px;
            color: #9aa3b1;
            padding: 25px 0 4px;
        }

        @media (max-width: 1000px) {
            .monitoring-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 22px 18px 30px;
            }

            .monitoring-header {
                align-items: stretch;
                flex-direction: column;
            }

            .division-filter {
                width: 100%;
            }
        }

        @media (max-width: 520px) {
            .status-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .monitoring-card {
                transition: none;
            }
        }

        /* =========================================================
           DARK MODE (palet sama dengan dashboard)
        ========================================================= */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body{background:#0f141b!important}

        html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
        html body.dark-mode .main,
        html body.dark-mode .content{background:#0f141b!important}

        html body.dark-mode .monitoring-title h1{color:#edf2f7!important}
        html body.dark-mode .monitoring-title p{color:#8f9bac!important}

        html body.dark-mode .division-filter{background:#151b23!important;color:#edf2f7!important;border-color:#2c3542!important}
        html body.dark-mode .division-filter option{background:#151b23!important;color:#edf2f7!important}

        html body.dark-mode .monitoring-card,
        html body.dark-mode .empty-data{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}

        html body.dark-mode .card-top{border-bottom-color:#2c3542!important}
        html body.dark-mode .division-info h3,
        html body.dark-mode .total-box strong,
        html body.dark-mode .status-number,
        html body.dark-mode .progress-head strong{color:#edf2f7!important}

        html body.dark-mode .division-code,
        html body.dark-mode .total-box span,
        html body.dark-mode .progress-head span,
        html body.dark-mode .empty-data,
        html body.dark-mode .footer-note{color:#8f9bac!important}

        html body.dark-mode .status-grid{background:#202733!important}
        html body.dark-mode .status-label{color:#cbd2dc!important}

        html body.dark-mode .progress-section{border-top-color:#2c3542!important}
        html body.dark-mode .progress-track{background:#2c3542!important}
        html body.dark-mode .progress-fill{background:#b51f2a!important}

    </style>

</head>

<body>

<?php include '../includes/super_admin/sidebar.php'; ?>

<div class="main">

    <?php include '../includes/super_admin/topbar.php'; ?>

    <div class="content">

        <div class="monitoring-header">

            <div class="monitoring-title">
                <h1>Monitoring Pekerjaan</h1>
                <p>Pantau status dan tingkat penyelesaian pekerjaan pada setiap divisi.</p>
            </div>

            <form method="GET">

                <select
                    name="divisi"
                    class="division-filter"
                    onchange="this.form.submit()"
                >

                    <option value="0">Semua Divisi</option>

                    <?php foreach ($semua_divisi as $d): ?>

                        <option
                            value="<?= (int) $d['id_divisi']; ?>"
                            <?= $filter_divisi == (int) $d['id_divisi'] ? 'selected' : ''; ?>
                        >
                            <?= htmlspecialchars($d['nama_divisi'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </form>

        </div>

        <?php if (!empty($data_divisi)): ?>

            <div class="monitoring-grid">

                <?php foreach ($data_divisi as $divisi): ?>

                    <article class="monitoring-card">

                        <div class="card-top">

                            <div class="division-info">

                                <h3>
                                    <?= htmlspecialchars($divisi['nama_divisi'], ENT_QUOTES, 'UTF-8'); ?>
                                </h3>

                                <span class="division-code">
                                    DIV-<?= str_pad((int) $divisi['id_divisi'], 3, '0', STR_PAD_LEFT); ?>
                                </span>

                            </div>

                            <div class="total-box">
                                <span>Total Pekerjaan</span>
                                <strong><?= $divisi['total']; ?></strong>
                            </div>

                        </div>

                        <div class="status-grid">

                            <?php foreach ($daftar_status as $st): ?>

                                <div class="status-item">

                                    <span class="status-label">
                                        <span class="status-dot <?= $st[2]; ?>"></span>
                                        <?= $st[1]; ?>
                                    </span>

                                    <span class="status-number"><?= $divisi[$st[0]]; ?></span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <div class="progress-section">

                            <div class="progress-head">
                                <span>Tingkat Penyelesaian</span>
                                <strong><?= $divisi['persentase']; ?>%</strong>
                            </div>

                            <div class="progress-track">
                                <div
                                    class="progress-fill"
                                    style="width: <?= $divisi['persentase']; ?>%;"
                                ></div>
                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-data">
                <p>Belum ada data divisi atau pekerjaan.</p>
            </div>

        <?php endif; ?>

        <div class="footer-note">
            &copy; <?= date('Y'); ?> SIMO METRP. All rights reserved.
        </div>

    </div>

</div>

</body>

</html>