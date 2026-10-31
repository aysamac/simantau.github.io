<?php
include "../config/session.php";
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Admin") {
    header("Location: ../index.php");
    exit;
}
include "../config/koneksi.php";

$id_admin = $_SESSION["id_user"];

$query_admin = mysqli_query(
    $koneksi,
    "SELECT * FROM users WHERE id_user='$id_admin'"
);
$admin = mysqli_fetch_assoc($query_admin);
$id_divisi  = $admin["id_divisi"];
$nama_admin = $admin["nama"];

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT * FROM divisi WHERE id_divisi='$id_divisi'"
);
$divisi = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"];

$query_notifikasi = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS jumlah
     FROM notifikasi
     WHERE id_user='$id_admin'
     AND status_baca='Belum Dibaca'"
);
$jumlah_notifikasi = 0;
if ($query_notifikasi) {
    $data_notifikasi = mysqli_fetch_assoc($query_notifikasi);
    $jumlah_notifikasi = (int)($data_notifikasi["jumlah"] ?? 0);
}

$query_total = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE id_divisi='$id_divisi'
     AND role='Karyawan'"
);
$data_total = mysqli_fetch_assoc($query_total);
$total_karyawan = (int)($data_total["total"] ?? 0);

$query_aktif = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE id_divisi='$id_divisi'
     AND role='Karyawan'
     AND status='Aktif'"
);
$data_aktif = mysqli_fetch_assoc($query_aktif);
$total_aktif = (int)($data_aktif["total"] ?? 0);

$query_tidak_aktif = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE id_divisi='$id_divisi'
     AND role='Karyawan'
     AND status='Tidak Aktif'"
);
$data_tidak_aktif = mysqli_fetch_assoc($query_tidak_aktif);
$total_tidak_aktif = (int)($data_tidak_aktif["total"] ?? 0);

$query_karyawan = mysqli_query(
    $koneksi,
    "SELECT *
     FROM users
     WHERE id_divisi='$id_divisi'
     AND role='Karyawan'
     ORDER BY
        CASE
            WHEN status='Aktif' THEN 0
            ELSE 1
        END ASC,
        nama ASC"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Karyawan - SIMO</title>
<style>

/* =========================================================
   TOKEN WARNA (terang & gelap)
========================================================= */
body {
    --bg: #ffffff;
    --surface: #ffffff;
    --surface-2: #f8f9fb;
    --line: #e2e6ec;
    --line-soft: #eceff3;
    --text: #1a2230;
    --text-2: #4b576b;
    --text-3: #7b8699;

    --brand: #b51f2a;
    --brand-hover: #9a1a24;
    --brand-ink: #b51f2a;
    --brand-soft: #fbeced;
    --brand-ring: rgba(181, 31, 42, .18);

    --ok-bg: #e6f5ea;     --ok: #2f8f50;
    --off-bg: #eeeeef;    --off: #999fa7;
    --danger-bg: #fde8e8; --danger: #b93232;

    /* tombol aksi tabel */
    --btn-bg: #ffffff;
    --btn-line: #d9dde2;
    --btn-edit: #2f6fd0;
    --btn-edit-bg: #eef4ff;
    --btn-edit-line: #b9d0f7;
    --btn-del: #d92d20;
    --btn-del-bg: #fef3f2;
    --btn-del-line: #fecdca;

    --toggle-off: #cbd2da;
    --toggle-on: #70a873;

    --shadow: 0 1px 2px rgba(18, 28, 45, .05), 0 1px 3px rgba(18, 28, 45, .04);
    --shadow-modal: 0 24px 60px rgba(15, 23, 36, .22);
}

body.dark-mode {
    --bg: #0f141b;
    --surface: #151b23;
    --surface-2: #1a212b;
    --line: #2c3542;
    --line-soft: #232b36;
    --text: #edf2f7;
    --text-2: #cbd2dc;
    --text-3: #8f9bac;

    --brand-ink: #f07a80;
    --brand-soft: #382326;
    --brand-ring: rgba(240, 122, 128, .25);

    --ok-bg: #183d2a;     --ok: #72d89a;
    --off-bg: #34383e;    --off: #c5cbd3;
    --danger-bg: #4b2d32; --danger: #ff8b92;

    --btn-bg: #151b23;
    --btn-line: #2c3542;
    --btn-edit: #8eb5ff;
    --btn-edit-bg: #1d3158;
    --btn-edit-line: #2f4570;
    --btn-del: #ff8b92;
    --btn-del-bg: #4b2d32;
    --btn-del-line: #70444b;

    --toggle-off: #2c3542;
    --toggle-on: #29a35f;

    --shadow: none;
    --shadow-modal: 0 24px 60px rgba(0, 0, 0, .5);
}

html:has(body.dark-mode) { background: #0f141b; }

/* =========================================================
   DASAR
========================================================= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
}

body {
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

a { text-decoration: none; color: inherit; }

:focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
}

.main {
    margin-left: 240px;
    width: calc(100% - 240px);
    min-width: 0;
    min-height: 100vh;
}

.content {
    max-width: 1320px;
    margin: 0 auto;
    padding: 28px 32px 40px;
}

/* =========================================================
   HEADER HALAMAN
========================================================= */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.page-header h2 {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -.2px;
    color: var(--text);
    line-height: 1.25;
}

.page-header p {
    margin-top: 3px;
    font-size: 13.5px;
    color: var(--text-3);
}

.btn-tambah {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 40px;
    padding: 0 18px;
    flex-shrink: 0;
    white-space: nowrap;
    border-radius: 9px;
    background: var(--brand);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    box-shadow: var(--shadow);
    transition: background .15s;
}

.btn-tambah:hover { background: var(--brand-hover); }
.btn-tambah svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }

/* KARTU */
.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: var(--shadow);
}

/* =========================================================
   STATISTIK (desain asli)
========================================================= */
.statistik {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}

.stat-card {
    padding: 12px 16px;
    min-height: 0;
}

.stat-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 6px;
}

.stat-label { font-size: 13px; color: var(--text-2); font-weight: 600; }

.stat-icon {
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--brand-ink);
}

.stat-icon svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }

.stat-bottom {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
}

.stat-number { font-size: 24px; line-height: 1; font-weight: 700; color: var(--text); }

.stat-description {
    font-size: 11px;
    line-height: 1.3;
    color: var(--text-3);
    text-align: right;
    margin: 0;
    max-width: 62%;
}

/* =========================================================
   DAFTAR
========================================================= */
.list-card { overflow: hidden; }

.list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px 20px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--line);
}

.daftar-label { display: block; font-size: 15px; font-weight: 700; color: var(--text); }
.daftar-desc { margin-top: 2px; font-size: 12.5px; color: var(--text-3); }

.filter-area { display: flex; align-items: center; gap: 10px; min-width: 0; }

.search-box { position: relative; flex: 0 1 340px; min-width: 0; }

.search-box svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    fill: none;
    stroke: var(--text-3);
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    pointer-events: none;
}

.search-box input,
.status-filter {
    height: 38px;
    border: 1px solid var(--line);
    border-radius: 9px;
    background: var(--surface);
    color: var(--text);
    font-size: 13.5px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.search-box input { width: 100%; min-width: 140px; padding: 0 12px 0 36px; }
.search-box input::placeholder { color: var(--text-3); }
.status-filter { flex-shrink: 0; min-width: 150px; padding: 0 12px; cursor: pointer; }

.search-box input:focus,
.status-filter:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }

body.dark-mode .status-filter { color-scheme: dark; }

/* =========================================================
   TABEL
========================================================= */
.table-wrapper { width: 100%; overflow-x: auto; }

.tabel-karyawan {
    width: 100%;
    min-width: 900px;
    table-layout: fixed;
    border-collapse: collapse;
    border-spacing: 0;
}

.tabel-karyawan,
.tabel-karyawan * { font-family: "Segoe UI", Arial, sans-serif; }

.tabel-karyawan th {
    padding: 13px 8px 13px 16px;
    text-align: left;
    white-space: nowrap;
    background: #b51f2a;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    border-bottom: 1px solid #8f1821;
}

.tabel-karyawan td {
    padding: 10px 8px 10px 16px;
    text-align: left;
    vertical-align: middle;
    line-height: 1.4;
    border-bottom: 1px solid var(--line-soft);
    background: var(--surface);
    color: var(--text);
    font-size: 13.5px;
    overflow-wrap: anywhere;
}

/* kolom No dan Aksi di tengah */
.tabel-karyawan th:first-child,
.tabel-karyawan td:first-child {
    text-align: center;
    padding-left: 8px;
    padding-right: 8px;
}

.tabel-karyawan th:last-child,
.tabel-karyawan td:last-child {
    text-align: center;
    padding-left: 8px;
    padding-right: 8px;
}

/* beri jarak antara kolom No dan Nama */
.tabel-karyawan th:nth-child(2),
.tabel-karyawan td:nth-child(2) {
    padding-left: 32px;
}

.tabel-karyawan tbody tr:last-child td { border-bottom: none; }
.tabel-karyawan tbody tr.row-klik { cursor: pointer; }
.tabel-karyawan tbody tr.row-klik:hover td { background: var(--surface-2); }
tr.row-klik:focus { outline: none; }
tr.row-klik:focus-visible td { background: var(--brand-soft); }

.nama-karyawan { color: var(--text); font-weight: 500; }

/* baris tidak aktif */
.row-tidak-aktif td { color: var(--text-3); }
.row-tidak-aktif .nama-karyawan { color: var(--text-3); }

/* STATUS */
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    line-height: 1.4;
    white-space: nowrap;
}

.status-aktif { background: var(--ok-bg); color: var(--ok); }
.status-tidak-aktif { background: var(--off-bg); color: var(--off); }

/* AKSI: toggle + label | edit | hapus (selalu sejajar, di tengah) */
.aksi { display: flex; align-items: center; justify-content: center; gap: 10px; }

.switch { position: relative; display: block; width: 38px; height: 22px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; inset: 0; background: var(--toggle-off); border-radius: 30px; transition: background .2s ease; }
.slider:before { content: ""; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); transition: transform .2s ease; }
.switch input:checked + .slider { background: var(--toggle-on); }
.switch input:checked + .slider:before { transform: translateX(16px); }
.switch input:focus-visible + .slider { outline: 2px solid var(--brand); outline-offset: 2px; }

/* lebar tetap supaya "Aktif" dan "Nonaktif" tidak menggeser tombol */
.toggle-label {
    display: inline-block;
    flex-shrink: 0;
    width: 62px;
    padding-right: 12px;
    border-right: 1px solid var(--line);
    font-size: 10.5px;
    font-weight: 600;
    line-height: 22px;
    text-align: left;
    white-space: nowrap;
}

.toggle-label.aktif { color: var(--ok); }
.toggle-label.nonaktif { color: var(--off); }

.aksi-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--btn-bg);
    border: 1px solid var(--btn-line);
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s ease, border-color .15s ease, color .15s ease;
}

.aksi-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

.btn-edit { color: var(--btn-edit); }
.btn-edit:hover { background: var(--btn-edit-bg); border-color: var(--btn-edit-line); }

.btn-hapus { color: var(--btn-del); }
.btn-hapus:hover { background: var(--btn-del-bg); border-color: var(--btn-del-line); }

.empty { padding: 48px 20px !important; text-align: center !important; color: var(--text-3) !important; }

.no-result {
    display: none;
    text-align: center;
    padding: 44px 20px;
    color: var(--text-3);
    font-size: 13px;
}

.table-foot {
    padding: 12px 20px;
    border-top: 1px solid var(--line);
    font-size: 12.5px;
    color: var(--text-3);
}

.table-foot strong { color: var(--text-2); font-weight: 700; }

/* =========================================================
   POPUP
========================================================= */
.popup-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(18, 24, 34, .5);
    z-index: 10000;
}

.popup-overlay.show { display: flex; }

.popup-box {
    width: 400px;
    max-width: 100%;
    padding: 28px 26px 24px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    text-align: center;
    box-shadow: var(--shadow-modal);
}

.popup-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--danger-bg);
    color: var(--danger);
}

.popup-icon.ok { background: var(--ok-bg); color: var(--ok); }
.popup-icon.off { background: var(--off-bg); color: var(--off); }

.popup-icon svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.popup-box h3 { margin-bottom: 8px; font-size: 17px; font-weight: 700; color: var(--text); }

.popup-box p {
    margin: 0 auto 22px;
    max-width: 320px;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--text-3);
}

.popup-box p strong { color: var(--text); font-weight: 700; }

.popup-buttons { display: flex; gap: 10px; flex-wrap: wrap; }

.popup-btn {
    flex: 1;
    min-width: 104px;
    min-height: 42px;
    padding: 10px 16px;
    border-radius: 9px;
    border: 1px solid var(--line);
    font-size: 13.5px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
}

.popup-batal { background: var(--surface); color: var(--text-2); }
.popup-batal:hover { background: var(--surface-2); }

.popup-nonaktif { background: #6b7482; color: #fff; border-color: #6b7482; }
.popup-nonaktif:hover { filter: brightness(.92); }
body.dark-mode .popup-nonaktif { background: #4a515b; border-color: #4a515b; color: #edf2f7; }

.popup-aktifkan { background: #1f8a4f; color: #fff; border-color: #1f8a4f; }
.popup-aktifkan:hover { background: #197a45; border-color: #197a45; }

.popup-hapus { background: var(--brand); color: #fff; border-color: var(--brand); }
.popup-hapus:hover { background: var(--brand-hover); border-color: var(--brand-hover); }

.footer-note {
    text-align: center;
    padding: 28px 0 4px;
    color: var(--text-3);
    font-size: 12px;
}

/* =========================================================
   RESPONSIF
========================================================= */
@media (max-width: 1000px) {
    .statistik { grid-template-columns: 1fr; gap: 12px; }
}

@media (max-width: 800px) {
    .main { margin-left: 0; width: 100%; }
    .content { padding: 20px 16px 32px; }

    .page-header { align-items: flex-start; flex-wrap: wrap; }
    .btn-tambah { width: 100%; }

    .list-header { flex-direction: column; align-items: stretch; padding: 16px; }
    .filter-area { flex-direction: column; align-items: stretch; }
    .search-box { flex: none; width: 100%; }
    .status-filter { width: 100%; }

    /* tabel jadi kartu di HP */
    .table-wrapper { overflow: visible; padding: 14px 16px 6px; }
    .tabel-karyawan { min-width: 0; table-layout: auto; }
    .tabel-karyawan, .tabel-karyawan tbody { display: block; width: 100%; }
    .tabel-karyawan thead, .tabel-karyawan colgroup { display: none; }

    .tabel-karyawan tbody tr {
        display: flex;
        flex-direction: column;
        margin-bottom: 12px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: var(--surface);
        overflow: hidden;
    }

    .tabel-karyawan tbody tr.row-klik:hover td,
    .tabel-karyawan tbody tr.row-klik:focus-visible td { background: transparent; }
    .tabel-karyawan tbody tr.row-klik:active { background: var(--surface-2); }

    .tabel-karyawan td,
    .tabel-karyawan td:first-child,
    .tabel-karyawan td:nth-child(2),
    .tabel-karyawan td:last-child {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 10px 14px;
        border-bottom: 1px solid var(--line-soft);
        background: transparent;
        text-align: right;
    }

    .tabel-karyawan td::before {
        content: attr(data-label);
        flex-shrink: 0;
        text-align: left;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-3);
    }

    .tabel-karyawan td:last-child { border-bottom: none; }
    .tabel-karyawan td.col-no { display: none; }

    .tabel-karyawan td.col-nama {
        order: -1;
        justify-content: flex-start;
        padding: 13px 14px;
        background: var(--surface-2);
        text-align: left;
        font-size: 15px;
    }
    .tabel-karyawan td.col-nama::before { display: none; }

    .aksi { justify-content: flex-end; }

    .tabel-karyawan td.empty { display: block; border: 0; }
    .tabel-karyawan td.empty::before { display: none; }
    .tabel-karyawan tbody tr:has(.empty) { border: 0; }

    .table-foot { padding: 12px 16px; }
}

</style>
</head>
<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="content">

    <div class="page-header">
        <div>
            <h2>Karyawan</h2>
            <p>Kelola data karyawan pada divisi <?= htmlspecialchars($nama_divisi); ?>.</p>
        </div>
        <a href="tambah_karyawan.php" class="btn-tambah">
            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            Tambah Karyawan
        </a>
    </div>

    <div class="statistik">

        <div class="card stat-card total">
            <div class="stat-card-top">
                <div class="stat-label">Total Akun</div>
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-bottom">
                <div class="stat-number" id="statTotal"><?= $total_karyawan; ?></div>
                <div class="stat-description">Seluruh akun karyawan di divisi</div>
            </div>
        </div>

        <div class="card stat-card aktif">
            <div class="stat-card-top">
                <div class="stat-label">Karyawan Aktif</div>
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M9 12l2 2 4-4"></path>
                        <circle cx="12" cy="12" r="9"></circle>
                    </svg>
                </div>
            </div>
            <div class="stat-bottom">
                <div class="stat-number" id="statAktif"><?= $total_aktif; ?></div>
                <div class="stat-description">Karyawan yang sedang aktif</div>
            </div>
        </div>

        <div class="card stat-card tidak-aktif">
            <div class="stat-card-top">
                <div class="stat-label">Karyawan Tidak Aktif</div>
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"></path>
                        <line x1="17" y1="8" x2="22" y2="13"></line>
                        <line x1="22" y1="8" x2="17" y2="13"></line>
                    </svg>
                </div>
            </div>
            <div class="stat-bottom">
                <div class="stat-number" id="statTidakAktif"><?= $total_tidak_aktif; ?></div>
                <div class="stat-description">Karyawan yang sedang tidak aktif</div>
            </div>
        </div>

    </div>

    <div class="card list-card">

        <div class="list-header">
            <div>
                <strong class="daftar-label">Daftar Karyawan</strong>
                <p class="daftar-desc">Data karyawan divisi <?= htmlspecialchars($nama_divisi); ?>.</p>
            </div>

            <div class="filter-area">
                <div class="search-box">
                    <svg class="search-icon" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.5-3.5"></path>
                    </svg>
                    <input
                        type="text"
                        id="searchKaryawan"
                        placeholder="Cari nama, No. HP, atau username..."
                        aria-label="Cari karyawan"
                        autocomplete="off"
                    >
                </div>

                <select id="filterStatus" class="status-filter" aria-label="Filter status">
                    <option value="Semua">Semua Status</option>
                    <option value="Aktif">Aktif</option>
                    <option value="Tidak Aktif">Tidak Aktif</option>
                </select>
            </div>
        </div>

        <div class="table-wrapper">

            <table id="tabelKaryawan" class="tabel-karyawan">
                <colgroup>
                    <col style="width:6%">
                    <col style="width:20%">
                    <col style="width:16%">
                    <col style="width:16%">
                    <col style="width:13%">
                    <col style="width:29%">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>No. HP</th>
                        <th>Username</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $no = 1;
                if ($query_karyawan && mysqli_num_rows($query_karyawan) > 0):
                    while ($row = mysqli_fetch_assoc($query_karyawan)):
                        $isAktif = ($row["status"] == "Aktif");
                        $rowClass = $isAktif ? "" : "row-tidak-aktif";
                        $statusClass = $isAktif ? "status-aktif" : "status-tidak-aktif";
                ?>
                    <tr
                        id="row-<?= $row["id_user"]; ?>"
                        class="row-klik <?= $rowClass; ?>"
                        data-status="<?= htmlspecialchars($row["status"]); ?>"
                        data-id="<?= (int) $row["id_user"]; ?>"
                        tabindex="0"
                        title="Klik untuk mengedit karyawan"
                    >
                        <td class="col-no" data-label="No"><?= $no++; ?></td>

                        <td class="col-nama" data-label="Nama">
                            <span class="nama-karyawan"><?= htmlspecialchars($row["nama"]); ?></span>
                        </td>

                        <td data-label="No. HP"><?= htmlspecialchars($row["no_hp"] ?? "-"); ?></td>

                        <td data-label="Username"><?= htmlspecialchars($row["username"]); ?></td>

                        <td data-label="Status">
                            <span
                                id="status-<?= $row["id_user"]; ?>"
                                class="status-badge <?= $statusClass; ?>"
                            ><?= htmlspecialchars($row["status"]); ?></span>
                        </td>

                        <td data-label="Aksi">
                            <div class="aksi">

                                <label
                                    class="switch"
                                    title="<?= $isAktif ? 'Nonaktifkan Karyawan' : 'Aktifkan Karyawan'; ?>"
                                >
                                    <input
                                        type="checkbox"
                                        <?= $isAktif ? "checked" : ""; ?>
                                        onchange="ubahStatus(<?= (int)$row["id_user"] ?>, this)"
                                    >
                                    <span class="slider"></span>
                                </label>

                                <span
                                    id="label-<?= $row["id_user"]; ?>"
                                    class="toggle-label <?= $isAktif ? 'aktif' : 'nonaktif'; ?>"
                                ><?= $isAktif ? "Aktif" : "Nonaktif"; ?></span>

                                <a
                                    href="edit_karyawan.php?id=<?= $row["id_user"]; ?>"
                                    class="aksi-btn btn-edit"
                                    title="Edit Karyawan"
                                    aria-label="Edit Karyawan"
                                >
                                    <svg viewBox="0 0 24 24">
                                        <path d="M4 20h4.2L19 9.2a1.8 1.8 0 0 0 0-2.5L17.3 5a1.8 1.8 0 0 0-2.5 0L5.7 14.1V20Z"></path>
                                        <path d="M13.5 7.5 16.5 10.5"></path>
                                    </svg>
                                </a>

                                <a
                                    href="#"
                                    class="aksi-btn btn-hapus"
                                    title="Hapus Karyawan"
                                    aria-label="Hapus Karyawan"
                                    onclick="prosesHapusKaryawan(event, <?= (int)$row["id_user"] ?>, <?= htmlspecialchars(json_encode($row["nama"]), ENT_QUOTES, 'UTF-8'); ?>);"
                                >
                                    <svg viewBox="0 0 24 24">
                                        <path d="M3 6h18"></path>
                                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                    </svg>
                                </a>

                            </div>
                        </td>
                    </tr>
                <?php
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="6" class="empty">Belum ada data karyawan.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div id="noResult" class="no-result">
                Data karyawan yang dicari tidak ditemukan.
            </div>

        </div>

        <div class="table-foot">
            <span>Total <strong><?= $total_karyawan; ?></strong> karyawan di divisi <?= htmlspecialchars($nama_divisi); ?></span>
        </div>

    </div>

    <div class="footer-note">
        &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
    </div>

</div>

</div>

<!-- POPUP KONFIRMASI AKTIFKAN / NONAKTIFKAN -->
<div class="popup-overlay" id="popupStatus">
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="popupStatusJudul">
        <div class="popup-icon" id="popupStatusIkon"></div>
        <h3 id="popupStatusJudul"></h3>
        <p id="popupStatusPesan"></p>
        <div class="popup-buttons">
            <button type="button" class="popup-btn popup-batal" id="popupStatusBatal">Batal</button>
            <button type="button" class="popup-btn" id="popupStatusLanjut"></button>
        </div>
    </div>
</div>

<!-- POPUP HAPUS / NONAKTIFKAN -->
<div class="popup-overlay" id="popupHapusKaryawan">
    <div class="popup-box" id="popupBox" role="dialog" aria-modal="true" aria-labelledby="popupJudulHapus">
        <div class="popup-icon">
            <svg viewBox="0 0 24 24">
                <path d="M5 7h14"></path>
                <path d="M10 11v6"></path>
                <path d="M14 11v6"></path>
                <path d="M7 7l1 13h8l1-13"></path>
                <path d="M9 7V4h6v3"></path>
            </svg>
        </div>
        <h3 id="popupJudulHapus">Hapus Karyawan</h3>
        <p id="popupPesanHapus"></p>
        <div class="popup-buttons" id="popupTombolHapus"></div>
    </div>
</div>

<!-- POPUP BERHASIL (setelah hapus / nonaktifkan dari popup hapus) -->
<div class="popup-overlay" id="popupBerhasil">
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="popupBerhasilJudul">
        <div class="popup-icon ok">
            <svg viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M8.5 12.5l2.5 2.5 4.5-5"></path>
            </svg>
        </div>
        <h3 id="popupBerhasilJudul">Berhasil</h3>
        <p id="popupBerhasilPesan"></p>
        <div class="popup-buttons">
            <button type="button" class="popup-btn popup-aktifkan" id="popupBerhasilOk">OK</button>
        </div>
    </div>
</div>

<script>
const searchInput = document.getElementById("searchKaryawan");
const filterStatus = document.getElementById("filterStatus");
const table = document.getElementById("tabelKaryawan");
const noResult = document.getElementById("noResult");

function filterKaryawan() {

    const keyword = searchInput.value.toLowerCase().trim();
    const statusDipilih = filterStatus.value;
    const rows = table.querySelectorAll("tbody tr[data-status]");
    let jumlahTampil = 0;

    rows.forEach(function (row) {

        const nama = row.cells[1].textContent.toLowerCase();
        const noHp = row.cells[2].textContent.toLowerCase();
        const username = row.cells[3].textContent.toLowerCase();
        const status = row.getAttribute("data-status");

        const cocokSearch =
            nama.includes(keyword) ||
            noHp.includes(keyword) ||
            username.includes(keyword);

        const cocokStatus =
            statusDipilih === "Semua" ||
            status === statusDipilih;

        if (cocokSearch && cocokStatus) {
            row.style.display = "";
            jumlahTampil++;
        } else {
            row.style.display = "none";
        }
    });

    noResult.style.display = jumlahTampil === 0 ? "block" : "none";
}

searchInput.addEventListener("input", filterKaryawan);
filterStatus.addEventListener("change", filterKaryawan);

/* =========================================================
   KARTU STATISTIK (angka ikut berubah saat status diubah)
========================================================= */
function perbaruiStatistik(statusBaru) {

    const elAktif = document.getElementById("statAktif");
    const elTidakAktif = document.getElementById("statTidakAktif");

    let aktif = parseInt(elAktif.textContent, 10) || 0;
    let tidakAktif = parseInt(elTidakAktif.textContent, 10) || 0;

    if (statusBaru === "Tidak Aktif") {
        aktif = Math.max(0, aktif - 1);
        tidakAktif = tidakAktif + 1;
    } else {
        aktif = aktif + 1;
        tidakAktif = Math.max(0, tidakAktif - 1);
    }

    elAktif.textContent = aktif;
    elTidakAktif.textContent = tidakAktif;
}

/* =========================================================
   UBAH STATUS (dengan popup konfirmasi)
========================================================= */
const popupStatus = document.getElementById("popupStatus");
const popupStatusIkon = document.getElementById("popupStatusIkon");
const popupStatusJudul = document.getElementById("popupStatusJudul");
const popupStatusPesan = document.getElementById("popupStatusPesan");
const popupStatusLanjut = document.getElementById("popupStatusLanjut");

const ikonNonaktif = '<svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"></circle><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"></path><line x1="17" y1="8" x2="22" y2="13"></line><line x1="22" y1="8" x2="17" y2="13"></line></svg>';
const ikonAktif = '<svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"></path><circle cx="12" cy="12" r="9"></circle></svg>';

let statusTertunda = null;

function ubahStatus(idUser, toggle) {

    const row = document.getElementById("row-" + idUser);
    const statusBaru = toggle.checked ? "Aktif" : "Tidak Aktif";
    const namaEl = row ? row.querySelector(".nama-karyawan") : null;
    const nama = namaEl ? namaEl.textContent.trim() : "karyawan ini";

    statusTertunda = { idUser: idUser, toggle: toggle, statusBaru: statusBaru };

    popupStatusPesan.textContent = "";

    if (statusBaru === "Aktif") {
        popupStatusIkon.className = "popup-icon ok";
        popupStatusIkon.innerHTML = ikonAktif;
        popupStatusJudul.textContent = "Aktifkan Karyawan?";
        popupStatusPesan.append("Anda akan mengaktifkan kembali karyawan ");
        popupStatusLanjut.className = "popup-btn popup-aktifkan";
        popupStatusLanjut.textContent = "Ya, Aktifkan";
    } else {
        popupStatusIkon.className = "popup-icon off";
        popupStatusIkon.innerHTML = ikonNonaktif;
        popupStatusJudul.textContent = "Nonaktifkan Karyawan?";
        popupStatusPesan.append("Anda akan menonaktifkan karyawan ");
        popupStatusLanjut.className = "popup-btn popup-nonaktif";
        popupStatusLanjut.textContent = "Ya, Nonaktifkan";
    }

    const tebal = document.createElement("strong");
    tebal.textContent = nama;
    popupStatusPesan.append(tebal);

    if (statusBaru === "Aktif") {
        popupStatusPesan.append(".");
    } else {
        popupStatusPesan.append(". Data dan riwayat pekerjaannya tetap disimpan.");
    }

    popupStatus.classList.add("show");
}

function batalStatus() {
    if (statusTertunda) {
        statusTertunda.toggle.checked = !statusTertunda.toggle.checked;
        statusTertunda = null;
    }
    popupStatus.classList.remove("show");
}

function lanjutStatus() {
    const data = statusTertunda;
    statusTertunda = null;
    popupStatus.classList.remove("show");
    if (data) {
        jalankanUbahStatus(data.idUser, data.toggle, data.statusBaru);
    }
}

document.getElementById("popupStatusBatal").addEventListener("click", batalStatus);
popupStatusLanjut.addEventListener("click", lanjutStatus);
popupStatus.addEventListener("click", function (event) {
    if (event.target === popupStatus) {
        batalStatus();
    }
});

function jalankanUbahStatus(idUser, toggle, statusBaru) {

    const row = document.getElementById("row-" + idUser);
    const status = document.getElementById("status-" + idUser);
    const label = document.getElementById("label-" + idUser);

    toggle.disabled = true;

    fetch("ubah_status_karyawan.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "id_user=" + encodeURIComponent(idUser) +
            "&status=" + encodeURIComponent(statusBaru)
    })
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {

        if (data.success) {

            status.textContent = statusBaru;
            row.setAttribute("data-status", statusBaru);

            if (statusBaru === "Tidak Aktif") {
                row.classList.add("row-tidak-aktif");
                status.classList.remove("status-aktif");
                status.classList.add("status-tidak-aktif");
                if (label) {
                    label.textContent = "Nonaktif";
                    label.classList.remove("aktif");
                    label.classList.add("nonaktif");
                }
            } else {
                row.classList.remove("row-tidak-aktif");
                status.classList.remove("status-tidak-aktif");
                status.classList.add("status-aktif");
                if (label) {
                    label.textContent = "Aktif";
                    label.classList.remove("nonaktif");
                    label.classList.add("aktif");
                }
            }

            const switchLabel = toggle.closest(".switch");

            if (switchLabel) {
                switchLabel.title =
                    statusBaru === "Aktif"
                    ? "Nonaktifkan Karyawan"
                    : "Aktifkan Karyawan";
            }

            perbaruiStatistik(statusBaru);
            filterKaryawan();

        } else {
            alert(data.message || "Gagal mengubah status karyawan.");
            toggle.checked = !toggle.checked;
        }

        toggle.disabled = false;
    })
    .catch(function (error) {
        console.error(error);
        alert("Terjadi kesalahan saat mengubah status karyawan.");
        toggle.checked = !toggle.checked;
        toggle.disabled = false;
    });
}

/* =========================================================
   HAPUS KARYAWAN
========================================================= */
let idKaryawanDipilih = 0;

function prosesHapusKaryawan(event, idUser, namaKaryawan) {

    event.preventDefault();

    idKaryawanDipilih = idUser;

    fetch("hapus_karyawan.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "aksi=cek" +
            "&id_user=" + encodeURIComponent(idUser)
    })
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {

        if (!data.success) {
            alert(data.message || "Gagal memeriksa data karyawan.");
            return;
        }

        if (!data.ada_riwayat) {
            bukaPopup(
                "Hapus Karyawan",
                "Karyawan ini tidak memiliki riwayat pekerjaan.<br>Yakin ingin menghapus?",
                `
                    <button type="button" class="popup-btn popup-batal" onclick="tutupPopup()">Batal</button>
                    <button type="button" class="popup-btn popup-hapus" onclick="kirimAksiKaryawan('hapus')">Hapus</button>
                `
            );
            return;
        }

        bukaPopup(
            "Karyawan Memiliki Riwayat",
            "Karyawan ini memiliki riwayat pekerjaan.",
            `
                <button type="button" class="popup-btn popup-batal" onclick="tutupPopup()">Batal</button>
                <button type="button" class="popup-btn popup-nonaktif" onclick="kirimAksiKaryawan('nonaktifkan')">Nonaktifkan</button>
                <button type="button" class="popup-btn popup-hapus" onclick="kirimAksiKaryawan('hapus')">Hapus Permanen</button>
            `
        );
    })
    .catch(function (error) {
        console.error(error);
        alert("Terjadi kesalahan saat memeriksa data karyawan.");
    });
}

function bukaPopup(judul, pesan, tombol) {
    document.getElementById("popupJudulHapus").textContent = judul;
    document.getElementById("popupPesanHapus").innerHTML = pesan;
    document.getElementById("popupTombolHapus").innerHTML = tombol;
    document.getElementById("popupHapusKaryawan").classList.add("show");
}

function tutupPopup() {
    document.getElementById("popupHapusKaryawan").classList.remove("show");
}

/* POPUP BERHASIL: pengganti alert, tombol OK memuat ulang halaman */
function bukaPopupBerhasil(judul, pesan) {
    document.getElementById("popupBerhasilJudul").textContent = judul;
    document.getElementById("popupBerhasilPesan").textContent = pesan;
    document.getElementById("popupBerhasil").classList.add("show");
    document.getElementById("popupBerhasilOk").focus();
}

function tutupPopupBerhasil() {
    document.getElementById("popupBerhasil").classList.remove("show");
    window.location.reload();
}

document.getElementById("popupBerhasilOk").addEventListener("click", tutupPopupBerhasil);

function kirimAksiKaryawan(aksi) {

    tutupPopup();

    fetch("hapus_karyawan.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "aksi=" + encodeURIComponent(aksi) +
            "&id_user=" + encodeURIComponent(idKaryawanDipilih)
    })
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {

        if (data.success) {
            bukaPopupBerhasil(
                aksi === "hapus" ? "Karyawan Dihapus" : "Karyawan Dinonaktifkan",
                data.message || (aksi === "hapus"
                    ? "Karyawan berhasil dihapus secara permanen."
                    : "Karyawan berhasil dinonaktifkan.")
            );
        } else {
            alert(data.message || "Gagal memproses data karyawan.");
        }
    })
    .catch(function (error) {
        console.error(error);
        alert("Terjadi kesalahan saat memproses data karyawan.");
    });
}

document.getElementById("popupHapusKaryawan").addEventListener("click", function (event) {
    if (event.target === this) {
        tutupPopup();
    }
});

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        batalStatus();
        tutupPopup();
    }
});

/* KLIK BARIS -> HALAMAN EDIT */
document.querySelectorAll("tr.row-klik").forEach(function (baris) {

    function bukaEdit() {
        window.location.href = "edit_karyawan.php?id=" + encodeURIComponent(baris.dataset.id);
    }

    baris.addEventListener("click", function (event) {
        if (event.target.closest(".aksi")) {
            return;
        }
        bukaEdit();
    });

    baris.addEventListener("keydown", function (event) {
        if (event.target !== baris) {
            return;
        }
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            bukaEdit();
        }
    });
});
</script>

</body>
</html>