<?php
include "../config/session.php";

/* ================= CEK LOGIN ================= */
if (!isset($_SESSION["id_user"]) || ($_SESSION["role"] ?? "") != "Super Admin") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* ================= CEK ID PEKERJAAN ================= */
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: pekerjaan.php");
    exit;
}

$id_pekerjaan = (int) $_GET["id"];

/* ================= DATA PEKERJAAN (Super Admin: semua divisi) ================= */
$query_pekerjaan = mysqli_query($koneksi, "
    SELECT p.*, d.nama_divisi
    FROM pekerjaan p
    LEFT JOIN divisi d ON p.id_divisi = d.id_divisi
    WHERE p.id_pekerjaan = '$id_pekerjaan'
    LIMIT 1
");

if (!$query_pekerjaan || mysqli_num_rows($query_pekerjaan) == 0) {
    header("Location: pekerjaan.php");
    exit;
}

$pekerjaan = mysqli_fetch_assoc($query_pekerjaan);

$status = $pekerjaan["status"] ?? "Belum Dikerjakan";
$nama_divisi = $pekerjaan["nama_divisi"] ?? "-";
$id_karyawan_lama = (int) ($pekerjaan["id_karyawan"] ?? 0);

/* ================= INFO PERALIHAN ================= */
$info_peralihan = null;

/* Tidak bergantung pada status pekerjaan: setelah dikonfirmasi Selesai,
   riwayat peralihan yang berhasil tetap ditampilkan. */
$query_info_peralihan = mysqli_query($koneksi, "
    SELECT pp.*, pengaju.nama AS nama_pengaju, pengganti.nama AS nama_pengganti
    FROM pengalihan_pekerjaan pp
    LEFT JOIN users pengaju ON pengaju.id_user = pp.id_karyawan_pengaju
    LEFT JOIN users pengganti ON pengganti.id_user = pp.id_karyawan_pengganti
    WHERE pp.id_pekerjaan = '$id_pekerjaan'
        AND pp.status_pengalihan = 'Berhasil'
    ORDER BY pp.tanggal_update DESC, pp.tanggal_pengajuan DESC
    LIMIT 1
");

if ($query_info_peralihan && mysqli_num_rows($query_info_peralihan) > 0) {
    $info_peralihan = mysqli_fetch_assoc($query_info_peralihan);
}

/* Alasan pengalihan: cek beberapa kemungkinan nama kolom */
$alasan_peralihan = "";

if ($info_peralihan) {
    foreach ($info_peralihan as $nama_kolom => $nilai_kolom) {
        if (
            preg_match('/alasan|keterangan|catatan|reason|sebab|note/i', $nama_kolom)
            && trim((string) $nilai_kolom) !== ""
        ) {
            $alasan_peralihan = trim((string) $nilai_kolom);
            break;
        }
    }

    /* Fallback: kolom teks lain yang bukan id / tanggal / status / nama */
    if ($alasan_peralihan === "") {
        foreach ($info_peralihan as $nama_kolom => $nilai_kolom) {
            if (
                !preg_match('/^(id|tanggal|status|nama_|waktu|created|updated)/i', $nama_kolom)
                && !is_numeric($nilai_kolom)
                && trim((string) $nilai_kolom) !== ""
            ) {
                $alasan_peralihan = trim((string) $nilai_kolom);
                break;
            }
        }
    }
}

/* ================= KARYAWAN / ANGGOTA TIM ================= */
$data_tim = [];

/* Hanya karyawan yang masih memegang pekerjaan (Aktif / Peralihan).
   Karyawan yang sudah mengalihkan tidak dihitung lagi, jadi jumlah anggota tetap. */
$query_tim = mysqli_query($koneksi, "
    SELECT u.id_user, u.nama, u.jabatan, MIN(pk.id) AS urutan
    FROM pekerjaan_karyawan pk
    INNER JOIN users u ON u.id_user = pk.id_user
    WHERE pk.id_pekerjaan = '$id_pekerjaan'
        AND pk.status_penugasan IN ('Aktif', 'Peralihan', 'Selesai')
    GROUP BY u.id_user, u.nama, u.jabatan
    ORDER BY urutan ASC
");

if ($query_tim) {
    while ($row = mysqli_fetch_assoc($query_tim)) {

        $id_anggota = (int) $row["id_user"];
        $row["nama_sebelumnya"] = "";
        $row["urutan_tampil"] = (int) $row["urutan"];

        /* Cek apakah anggota ini pengganti hasil peralihan */
        $query_sebelumnya = mysqli_query($koneksi, "
            SELECT pengaju.nama AS nama_pengaju,
                (
                    SELECT MIN(pk2.id) FROM pekerjaan_karyawan pk2
                    WHERE pk2.id_pekerjaan = pp.id_pekerjaan
                        AND pk2.id_user = pp.id_karyawan_pengaju
                ) AS urutan_pengaju
            FROM pengalihan_pekerjaan pp
            LEFT JOIN users pengaju ON pengaju.id_user = pp.id_karyawan_pengaju
            WHERE pp.id_pekerjaan = '$id_pekerjaan'
                AND pp.id_karyawan_pengganti = '$id_anggota'
                AND pp.status_pengalihan = 'Berhasil'
            ORDER BY pp.tanggal_update DESC, pp.tanggal_pengajuan DESC
            LIMIT 1
        ");

        if ($query_sebelumnya && mysqli_num_rows($query_sebelumnya) > 0) {
            $sebelumnya = mysqli_fetch_assoc($query_sebelumnya);
            $row["nama_sebelumnya"] = $sebelumnya["nama_pengaju"] ?? "";

            /* Tetap di posisi karyawan yang digantikan */
            if (!empty($sebelumnya["urutan_pengaju"])) {
                $row["urutan_tampil"] = (int) $sebelumnya["urutan_pengaju"];
            }
        }

        $data_tim[] = $row;
    }

    usort($data_tim, function ($a, $b) {
        return $a["urutan_tampil"] <=> $b["urutan_tampil"];
    });
}

/* data lama tanpa pekerjaan_karyawan */
if (count($data_tim) == 0 && $id_karyawan_lama > 0) {
    $query_utama = mysqli_query($koneksi, "SELECT id_user, nama, jabatan FROM users WHERE id_user = '$id_karyawan_lama' LIMIT 1");

    if ($query_utama && mysqli_num_rows($query_utama) > 0) {
        $data_tim[] = mysqli_fetch_assoc($query_utama);
    }
}

$is_tugas_tim = count($data_tim) >= 2;

/* ================= BUKTI TERAKHIR ================= */
$bukti = null;

$query_bukti = mysqli_query($koneksi, "
    SELECT bp.*, u.nama AS nama_pengirim_bukti
    FROM bukti_pekerjaan bp
    LEFT JOIN users u ON u.id_user = bp.id_user
    WHERE bp.id_pekerjaan = '$id_pekerjaan'
    ORDER BY bp.id_bukti DESC
    LIMIT 1
");

if ($query_bukti) {
    $bukti = mysqli_fetch_assoc($query_bukti);
}

$foto_bukti_list = [];

if ($bukti && !empty($bukti["foto"])) {
    $foto_bukti_list = array_values(array_filter(array_map("trim", explode(",", $bukti["foto"]))));
    $foto_bukti_list = array_slice($foto_bukti_list, 0, 5);
}

/* Foto dihapus otomatis H+1 setelah dikonfirmasi selesai, cek file masih ada */
$jumlah_foto_tercatat = count($foto_bukti_list);

$foto_bukti_list = array_values(array_filter($foto_bukti_list, function ($nama_foto) {
    return file_exists(__DIR__ . "/../uploads/bukti/" . $nama_foto);
}));

$foto_sudah_terhapus = (
    $jumlah_foto_tercatat > 0 &&
    count($foto_bukti_list) == 0 &&
    $status == "Selesai"
);

/* ================= CATATAN PERBAIKAN ================= */
$catatan_perbaikan = "";

$query_catatan = mysqli_query($koneksi, "
    SELECT catatan_admin FROM bukti_pekerjaan
    WHERE id_pekerjaan = '$id_pekerjaan'
        AND catatan_admin IS NOT NULL
        AND catatan_admin != ''
    ORDER BY id_bukti DESC
    LIMIT 1
");

if ($query_catatan) {
    $data_catatan = mysqli_fetch_assoc($query_catatan);

    if ($data_catatan) {
        $catatan_perbaikan = $data_catatan["catatan_admin"];
    }
}

/* ================= STATUS CLASS ================= */
function status_class($status)
{
    return match ($status) {
        "Selesai"             => "badge-selesai",
        "Sedang Dikerjakan"   => "badge-sedang",
        "Peralihan"           => "badge-peralihan",
        "Menunggu Konfirmasi" => "badge-menunggu",
        "Perlu Perbaikan"     => "badge-perbaikan",
        default               => "badge-belum"
    };
}

$status_class = status_class($status);

/* ================= FORMAT TANGGAL ================= */
function formatTanggalWaktu($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00" || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }

    $timestamp = strtotime($tanggal);

    return $timestamp ? date("d M Y, H:i", $timestamp) : "-";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detail Pekerjaan - SIMO</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
body{background:#ffffff;color:#263345;min-height:100vh}
a{text-decoration:none;color:inherit}
.main{margin-left:240px;width:calc(100% - 240px);min-height:100vh}
.content{padding:24px 32px 40px;max-width:1400px;margin:auto}

/* JUDUL HALAMAN */
.page-title{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
.page-title h2{font-size:26px;font-weight:700;color:#1c2636;margin-bottom:4px}
.page-title p{font-size:15px;color:#7e8998}
.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid #e4e8ed;border-radius:8px;background:#f7f8fa;color:#596678;font-size:12px;font-weight:600;white-space:nowrap}
.btn-back:hover{background:#eef1f4}
.btn-back svg{width:15px;height:15px}

/* CARD */
.card{background:#fff;border:1px solid #edf1f5;border-radius:14px;padding:20px;box-shadow:0 3px 14px rgba(20,40,70,.035)}
.detail-card{margin-top:16px;border:1px solid #d9dde2;padding:28px}

/* HEADER PEKERJAAN */
.job-top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:16px;border-bottom:1px solid #edf1f5}
.job-name{font-size:28px;font-weight:700;color:#1c2636}
.job-status{flex-shrink:0;padding:7px 11px;border-radius:6px;font-size:10px;font-weight:700}
.badge-selesai{background:#e9f8f0;color:#168353}
.badge-sedang{background:#edf3ff;color:#3f65b5}
.badge-peralihan{background:#eee7ff;color:#7252b8}
.badge-menunggu{background:#fff5dc;color:#a46b00}
.badge-perbaikan{background:#fde5e5;color:#c94242}
.badge-belum{background:#eef0f3;color:#667085}

/* INFORMASI PERALIHAN */
.transfer-info{margin-top:14px;padding:13px 15px;border:1px solid #cbdaf5;border-radius:9px;background:#f3f7ff}
.transfer-info-title{font-size:13px;font-weight:700;color:#365b9d;margin-bottom:7px}
.transfer-info p{font-size:13px;color:#40516b;line-height:1.6}
.transfer-info strong{font-weight:700}

/* DETAIL */
.detail-grid{display:flex;flex-direction:column;margin-top:4px}
.detail-item{display:grid;grid-template-columns:190px 1fr;align-items:start;column-gap:18px;padding:11px 0;border-bottom:1px solid #edf1f5}
.detail-label{font-size:12.5px;color:#748197;margin:0}
.detail-value{font-size:14px;font-weight:600;color:#263345}
.employee-list{display:flex;flex-direction:column;gap:7px}
.employee{display:flex;align-items:center;gap:8px}
.employee-number{width:7px;height:7px;border-radius:50%;background:#a5212b;flex-shrink:0;font-size:0}
.employee-name{font-size:13.5px;color:#263345;font-weight:600}
.employee-prev{font-size:13.5px;color:#263345;font-weight:600}

/* DESKRIPSI */
.description-area{padding-top:18px}
.description-title{font-size:13px;font-weight:700;color:#263345;margin-bottom:8px}
.description-text{font-size:13.5px;font-weight:400;color:#526176;line-height:1.6}

/* CATATAN PERBAIKAN */
.repair-note{margin-top:14px;padding:12px 14px;border:1px solid #f0d8d3;border-radius:9px;background:#fff7f5}
.repair-note-title{font-size:12px;font-weight:700;color:#b94b3e;margin-bottom:5px}
.repair-note-text{font-size:12.5px;font-weight:400;color:#667488;line-height:1.6}

/* SECTION */
.section-heading{font-size:16px;font-weight:700;color:#1c2636;margin-bottom:3px}
.section-desc{font-size:10.5px;color:#8b96a5;margin-bottom:14px}

/* BUKTI */
.evidence-list{display:flex;flex-direction:column;gap:10px}
.evidence{border:1px solid #e6ebf0;border-radius:10px;padding:12px}
.evidence-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:9px}
.evidence-person{font-size:11.5px;font-weight:700;color:#263345}
.evidence-date{font-size:9.5px;color:#8b96a5}
.evidence-body{display:grid;grid-template-columns:160px 1fr;gap:12px}
.evidence-photos{display:flex;flex-direction:column;gap:10px}
.evidence-photo-item{width:160px;height:100px;border-radius:7px;border:1px solid #e7ebef;overflow:hidden;background:#fff}
.evidence-photo-item img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer}
.evidence-photo-hint{margin-top:7px;font-size:10px;color:#8b96a5}
.evidence-note{background:#f7f9fb;border-radius:8px;padding:10px}
.evidence-note-title{font-size:12px;color:#6f7b8c;font-weight:700;margin-bottom:4px}
.evidence-note-text{font-size:12.5px;font-weight:400;color:#526176;line-height:1.55}
.empty-evidence{padding:18px;text-align:center;border:1px dashed #d9e0e7;border-radius:9px;color:#8b96a5;font-size:10.5px}

/* AKSI */
.action-area{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}
.action-info{font-size:11px;color:#697586}
.action-info.success{color:#29965a;font-weight:700}

.footer-note{text-align:center;padding:24px 0 6px;color:#a3adba;font-size:11px}

/* MODAL FOTO */
.foto-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.82);align-items:center;justify-content:center;padding:30px}
.foto-modal.active{display:flex}
.foto-modal img{max-width:95%;max-height:90vh;width:auto;height:auto;object-fit:contain;border-radius:6px}
.close-modal{position:absolute;top:20px;right:28px;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:25px;cursor:pointer}

/* RESPONSIVE */
@media(max-width:900px){
    .detail-item{grid-template-columns:150px 1fr}
}
@media(max-width:800px){
    .main{margin-left:0;width:100%}
    .content{padding:18px}
    .job-top{flex-direction:column}
    .detail-card{padding:20px}
    .evidence-body{grid-template-columns:1fr}
}
@media(max-width:600px){
    .page-title{align-items:flex-start;flex-direction:column}
    .page-title h2{font-size:22px}
    .detail-item{grid-template-columns:1fr;row-gap:4px}
    .job-name{font-size:22px}
}

/* DARK MODE */
body.dark-mode{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .main,body.dark-mode .content{background:#181b20!important}
body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode label{color:#f1f3f5!important}
body.dark-mode p{color:#929baa!important}
body.dark-mode .page-title h2{color:#f1f3f5!important}
body.dark-mode .page-title p{color:#929baa!important}
body.dark-mode .btn-back{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}
body.dark-mode .btn-back:hover{background:#323842!important}
body.dark-mode .card,body.dark-mode .detail-card{background:#1f2227!important;border-color:#3a3f47!important;color:#eef1f5!important;box-shadow:none!important}
body.dark-mode .job-name{color:#f5f6f8!important;font-size:28px!important}
body.dark-mode .job-top{border-bottom-color:#3a3f47!important}
body.dark-mode .job-status{font-size:11px!important;padding:8px 12px!important}
body.dark-mode .badge-belum{background:#34383e!important;color:#c5cbd3!important}
body.dark-mode .badge-sedang{background:#1d3158!important;color:#8eb5ff!important}
body.dark-mode .badge-menunggu{background:#3c3924!important;color:#f2d77d!important}
body.dark-mode .badge-perbaikan{background:#4b2d32!important;color:#ff8b92!important}
body.dark-mode .badge-peralihan{background:#2f2748!important;color:#b9a2f0!important}
body.dark-mode .badge-selesai{background:#183d2a!important;color:#72d89a!important}
body.dark-mode .transfer-info{background:#202a39!important;border-color:#3b4b63!important}
body.dark-mode .transfer-info-title{color:#a9c4ff!important}
body.dark-mode .transfer-info p{color:#e0e7f1!important}
body.dark-mode .detail-item{background:transparent!important;border-color:#363b43!important;padding:13px 0!important}
body.dark-mode .detail-label{color:#f1f3f5!important;font-size:12.5px!important}
body.dark-mode .detail-value{color:#f3f5f7!important;font-size:15px!important;font-weight:600!important}
body.dark-mode .employee-list{color:#f3f5f7!important;gap:8px!important}
body.dark-mode .employee{color:#f3f5f7!important}
body.dark-mode .employee-name{color:#f3f5f7!important;font-size:13.5px!important}
body.dark-mode .employee-prev{color:#ffffff!important;font-size:13.5px!important;font-weight:600!important}
body.dark-mode .employee-number{background:#a5212b!important;width:8px!important;height:8px!important}
body.dark-mode .description-area{border-color:#3a3f47!important;padding-top:20px!important}
body.dark-mode .description-title{color:#f3f5f7!important;font-size:13px!important}
body.dark-mode .description-text{color:#dce1e8!important;font-size:13.5px!important}
body.dark-mode .repair-note{background:#292329!important;border-color:#4a3438!important}
body.dark-mode .repair-note-title{color:#e36b71!important}
body.dark-mode .repair-note-text{color:#d8dee7!important}
body.dark-mode .section-heading{color:#f1f3f5!important}
body.dark-mode .section-desc,body.dark-mode .evidence-photo-hint{color:#929baa!important}
body.dark-mode .evidence{background:#1d2025!important;border-color:#343942!important}
body.dark-mode .evidence-person{color:#f1f3f5!important}
body.dark-mode .evidence-date{color:#929baa!important}
body.dark-mode .evidence-photo-item{background:#20242a!important;border-color:#343942!important}
body.dark-mode .evidence-note{background:#20242a!important;border-color:#343942!important}
body.dark-mode .evidence-note-title{color:#f1f3f5!important}
body.dark-mode .evidence-note-text{color:#cbd2dc!important}
body.dark-mode .empty-evidence{background:#20242a!important;color:#cbd2dc!important;border-color:#343942!important}
body.dark-mode .action-info{color:#aeb7c5!important}
body.dark-mode .action-info.success{color:#4fc183!important}
body.dark-mode .footer-note{color:#747e8c!important}
</style>
</head>

<body>

<?php include "../includes/super_admin/sidebar.php"; ?>

<main class="main">

<?php include "../includes/super_admin/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="page-title">
        <div>
            <h2>Detail Pekerjaan</h2>
            <p>Informasi lengkap pekerjaan pada divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES, "UTF-8"); ?>.</p>
        </div>

        <a href="pekerjaan.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5"></path>
                <path d="M12 19l-7-7 7-7"></path>
            </svg>
            Kembali
        </a>
    </div>

    <!-- DETAIL PEKERJAAN -->
    <div class="card detail-card">

        <div class="job-top">
            <div class="job-name"><?= htmlspecialchars($pekerjaan["nama_pekerjaan"] ?? "-", ENT_QUOTES, "UTF-8"); ?></div>
            <div class="job-status <?= $status_class; ?>"><?= htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?></div>
        </div>

        <?php if ($info_peralihan): ?>
            <div class="transfer-info">
                <div class="transfer-info-title">Informasi Peralihan Pekerjaan</div>
                <p>Pekerjaan ini dialihkan oleh <strong><?= htmlspecialchars($info_peralihan["nama_pengaju"] ?? "-", ENT_QUOTES, "UTF-8"); ?></strong> kepada <strong><?= htmlspecialchars($info_peralihan["nama_pengganti"] ?? "-", ENT_QUOTES, "UTF-8"); ?></strong>.</p>
                <?php if (!empty($info_peralihan["tanggal_pengajuan"])): ?>
                    <p>Tanggal peralihan: <?= formatTanggalWaktu($info_peralihan["tanggal_pengajuan"]); ?></p>
                <?php endif; ?>
                <?php if ($alasan_peralihan !== ""): ?>
                    <p>Alasan: <?= nl2br(htmlspecialchars($alasan_peralihan, ENT_QUOTES, "UTF-8")); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="detail-grid">

            <div class="detail-item">
                <div class="detail-label">Jenis Pekerjaan</div>
                <div class="detail-value"><?= !empty($pekerjaan["jenis_pekerjaan"]) ? htmlspecialchars($pekerjaan["jenis_pekerjaan"], ENT_QUOTES, "UTF-8") : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Tipe Order</div>
                <div class="detail-value"><?= !empty($pekerjaan["tipe_order"]) ? htmlspecialchars($pekerjaan["tipe_order"], ENT_QUOTES, "UTF-8") : "-"; ?></div>
            </div>

            <?php if (($pekerjaan["tipe_order"] ?? "") == "Order Perbaikan" && !empty($pekerjaan["nomor_order"])): ?>
            <div class="detail-item">
                <div class="detail-label">Nomor Order</div>
                <div class="detail-value"><?= htmlspecialchars($pekerjaan["nomor_order"], ENT_QUOTES, "UTF-8"); ?></div>
            </div>
            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">Divisi</div>
                <div class="detail-value"><?= htmlspecialchars($nama_divisi, ENT_QUOTES, "UTF-8"); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label"><?= $is_tugas_tim ? "Anggota Tim" : "Karyawan"; ?></div>

                <?php if (count($data_tim) > 0): ?>
                    <div class="employee-list">
                        <?php foreach ($data_tim as $anggota): ?>
                            <div class="employee">
                                <span class="employee-number"></span>
                                <span class="employee-name"><?= htmlspecialchars($anggota["nama"], ENT_QUOTES, "UTF-8"); ?></span>
                                <?php if (!empty($anggota["nama_sebelumnya"])): ?>
                                    <span class="employee-prev">(sebelumnya <?= htmlspecialchars($anggota["nama_sebelumnya"], ENT_QUOTES, "UTF-8"); ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="detail-value">-</div>
                <?php endif; ?>
            </div>

            <div class="detail-item">
                <div class="detail-label">Tanggal Mulai</div>
                <div class="detail-value"><?= formatTanggalWaktu($pekerjaan["tanggal_mulai"] ?? ""); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Target Selesai</div>
                <div class="detail-value"><?= formatTanggalWaktu($pekerjaan["target_selesai"] ?? ""); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Lokasi</div>
                <div class="detail-value"><?= !empty($pekerjaan["lokasi"]) ? htmlspecialchars($pekerjaan["lokasi"], ENT_QUOTES, "UTF-8") : "-"; ?></div>
            </div>

        </div>

        <div class="description-area">
            <div class="description-title">Deskripsi</div>
            <div class="description-text">
                <?php if (!empty($pekerjaan["deskripsi"])): ?>
                    <?= nl2br(htmlspecialchars($pekerjaan["deskripsi"], ENT_QUOTES, "UTF-8")); ?>
                <?php else: ?>
                    Tidak ada deskripsi pekerjaan.
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($catatan_perbaikan)): ?>
            <div class="repair-note">
                <div class="repair-note-title">Catatan Perbaikan</div>
                <div class="repair-note-text"><?= nl2br(htmlspecialchars($catatan_perbaikan, ENT_QUOTES, "UTF-8")); ?></div>
            </div>
        <?php endif; ?>

    </div>

    <!-- BUKTI PEKERJAAN -->
    <div class="card detail-card">

        <h3 class="section-heading">Bukti Pekerjaan</h3>
        <p class="section-desc">Bukti pekerjaan yang dikirim untuk pekerjaan ini.</p>

        <?php if ($bukti): ?>

            <div class="evidence-list">
                <div class="evidence">

                    <div class="evidence-head">
                        <div class="evidence-person"><?= htmlspecialchars($bukti["nama_pengirim_bukti"] ?? "Karyawan", ENT_QUOTES, "UTF-8"); ?></div>

                        <?php if (!empty($bukti["tanggal_upload"])): ?>
                            <div class="evidence-date"><?= formatTanggalWaktu($bukti["tanggal_upload"]); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="evidence-body">

                        <div>
                            <?php if (!empty($foto_bukti_list)): ?>

                                <div class="evidence-photos">
                                    <?php foreach ($foto_bukti_list as $foto_bukti): ?>
                                        <div class="evidence-photo-item">
                                            <img src="../uploads/bukti/<?= htmlspecialchars($foto_bukti, ENT_QUOTES, "UTF-8"); ?>" alt="Bukti pekerjaan" onclick="bukaFotoBukti(this.src)">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="evidence-photo-hint">Klik foto untuk melihat ukuran penuh.</div>

                            <?php else: ?>

                                <div class="empty-evidence">
                                    <?php if ($foto_sudah_terhapus): ?>
                                        Foto bukti sudah dihapus otomatis oleh sistem (1 hari setelah pekerjaan dikonfirmasi selesai).
                                    <?php else: ?>
                                        <?= $jumlah_foto_tercatat > 0 ? "Foto bukti tidak tersedia." : "Tidak ada foto bukti pekerjaan."; ?>
                                    <?php endif; ?>
                                </div>

                            <?php endif; ?>
                        </div>

                        <div class="evidence-note">
                            <div class="evidence-note-title">Catatan Karyawan</div>
                            <div class="evidence-note-text">
                                <?= !empty($bukti["catatan_karyawan"]) ? nl2br(htmlspecialchars($bukti["catatan_karyawan"], ENT_QUOTES, "UTF-8")) : "Tidak ada catatan."; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        <?php else: ?>

            <div class="empty-evidence">Belum ada bukti pekerjaan yang diunggah.</div>

        <?php endif; ?>

    </div>

    <!-- AKSI -->
    <div class="card detail-card">
        <div class="action-area">
            <?php if ($status == "Selesai"): ?>
                <span class="action-info success">Pekerjaan telah selesai.</span>
            <?php else: ?>
                <span class="action-info">Hanya dapat melihat detail pekerjaan.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer-note">&copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

</div>

</main>

<div class="foto-modal" id="fotoModal" onclick="tutupFotoBukti(event)">
    <button type="button" class="close-modal" onclick="tutupFotoBukti(event)">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto Bukti Ukuran Penuh">
</div>

<script>
function bukaFotoBukti(src){
    document.getElementById("fotoModalImage").src = src;
    document.getElementById("fotoModal").classList.add("active");
    document.body.style.overflow = "hidden";
}

function tutupFotoBukti(event){
    if (event && event.target && event.target.id === "fotoModalImage") return;
    document.getElementById("fotoModal").classList.remove("active");
    document.getElementById("fotoModalImage").src = "";
    document.body.style.overflow = "";
}

document.addEventListener("keydown", function(event){
    if (event.key === "Escape") tutupFotoBukti(null);
});
</script>

</body>
</html>