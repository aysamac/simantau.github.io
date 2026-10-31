<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || ($_SESSION["role"] ?? "") != "Karyawan") {
    header("Location: ../index.php");
    exit;
}
include "../config/koneksi.php";

$id_karyawan = (int) $_SESSION["id_user"];

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: riwayat.php");
    exit;
}
$id_pekerjaan = (int) $_GET["id"];

$query_karyawan = mysqli_query($koneksi, "
    SELECT u.id_user, u.nama, u.id_divisi, d.nama_divisi
    FROM users u
    LEFT JOIN divisi d ON d.id_divisi=u.id_divisi
    WHERE u.id_user='$id_karyawan' AND u.role='Karyawan'
    LIMIT 1
");
if (!$query_karyawan) {
    die("Data karyawan gagal dimuat: " . mysqli_error($koneksi));
}
$karyawan = mysqli_fetch_assoc($query_karyawan);
if (!$karyawan) {
    header("Location: riwayat.php");
    exit;
}
$id_divisi = (int) $karyawan["id_divisi"];

$query_pekerjaan = mysqli_query($koneksi, "
    SELECT DISTINCT
        p.id_pekerjaan, p.nama_pekerjaan, p.jenis_pekerjaan, p.tipe_order,
        p.nomor_order, p.tanggal_mulai, p.target_selesai, p.deskripsi,
        p.status, p.lokasi, p.id_divisi, p.id_karyawan,
        d.nama_divisi, u.nama AS nama_penanggung_jawab
    FROM pekerjaan p
    LEFT JOIN divisi d ON d.id_divisi=p.id_divisi
    LEFT JOIN users u ON u.id_user=p.id_karyawan
    LEFT JOIN pekerjaan_karyawan pk
        ON pk.id_pekerjaan=p.id_pekerjaan AND pk.id_user='$id_karyawan'
    LEFT JOIN pengalihan_pekerjaan pp
        ON pp.id_pekerjaan=p.id_pekerjaan
        AND pp.id_karyawan_pengaju='$id_karyawan'
        AND pp.status_pengalihan='Berhasil'
    WHERE p.id_pekerjaan='$id_pekerjaan'
        AND p.id_divisi='$id_divisi'
        AND (p.id_karyawan='$id_karyawan' OR pk.id IS NOT NULL OR pp.id_pengalihan IS NOT NULL)
    LIMIT 1
");
if (!$query_pekerjaan) {
    die("Data pekerjaan gagal dimuat: " . mysqli_error($koneksi));
}
$pekerjaan = mysqli_fetch_assoc($query_pekerjaan);
if (!$pekerjaan) {
    header("Location: riwayat.php");
    exit;
}

$query_tim = mysqli_query($koneksi, "
    SELECT DISTINCT u.id_user, u.nama
    FROM pekerjaan_karyawan pk
    INNER JOIN users u ON u.id_user=pk.id_user
    WHERE pk.id_pekerjaan='$id_pekerjaan'
    ORDER BY u.nama ASC
");
$data_tim = [];
if ($query_tim) {
    while ($anggota = mysqli_fetch_assoc($query_tim)) {
        $data_tim[] = $anggota;
    }
}
$is_tugas_tim = count($data_tim) > 1;

$status_penugasan_saya = "-";
$query_status_penugasan = mysqli_query($koneksi, "
    SELECT status_penugasan FROM pekerjaan_karyawan
    WHERE id_pekerjaan='$id_pekerjaan' AND id_user='$id_karyawan'
    ORDER BY id DESC LIMIT 1
");
if ($query_status_penugasan && mysqli_num_rows($query_status_penugasan) > 0) {
    $d = mysqli_fetch_assoc($query_status_penugasan);
    $status_penugasan_saya = $d["status_penugasan"];
}

$query_alih = mysqli_query($koneksi, "
    SELECT
        pp.id_pengalihan, pp.id_karyawan_pengaju, pp.id_karyawan_pengganti,
        pp.deskripsi_pengalihan, pp.status_pengalihan,
        pp.tanggal_pengajuan, pp.tanggal_update,
        pengaju.nama AS nama_pengaju, pengganti.nama AS nama_pengganti
    FROM pengalihan_pekerjaan pp
    LEFT JOIN users pengaju ON pengaju.id_user=pp.id_karyawan_pengaju
    LEFT JOIN users pengganti ON pengganti.id_user=pp.id_karyawan_pengganti
    WHERE pp.id_pekerjaan='$id_pekerjaan' AND pp.status_pengalihan='Berhasil'
    ORDER BY pp.tanggal_pengajuan DESC
");
if (!$query_alih) {
    die("Data alih tugas gagal dimuat: " . mysqli_error($koneksi));
}
$jumlah_alih = mysqli_num_rows($query_alih);

$query_bukti = mysqli_query($koneksi, "
    SELECT
        bp.id_bukti, bp.id_pekerjaan, bp.id_user, bp.foto,
        bp.catatan_karyawan, bp.catatan_admin, bp.tanggal_upload,
        u.nama AS nama_pengirim_bukti
    FROM bukti_pekerjaan bp
    LEFT JOIN users u ON u.id_user=bp.id_user
    WHERE bp.id_pekerjaan='$id_pekerjaan'
    ORDER BY bp.id_bukti DESC
    LIMIT 1
");
$data_bukti = null;
if ($query_bukti && mysqli_num_rows($query_bukti) > 0) {
    $data_bukti = mysqli_fetch_assoc($query_bukti);
}

function e($value)
{
    return htmlspecialchars((string) ($value ?? "-"), ENT_QUOTES, "UTF-8");
}

function formatTanggalWaktu($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }
    $timestamp = strtotime($tanggal);
    return $timestamp ? date("d M Y, H:i", $timestamp) : "-";
}

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

if ($pekerjaan["status"] == "Perlu Perbaikan") {
    $status_pekerjaan = "Perlu Perbaikan";
} elseif ($pekerjaan["status"] == "Menunggu Konfirmasi") {
    $status_pekerjaan = "Menunggu Konfirmasi";
} elseif ($pekerjaan["status"] == "Selesai") {
    $status_pekerjaan = "Selesai";
} elseif (!empty($status_penugasan_saya) && $status_penugasan_saya != "-") {
    $status_pekerjaan = $status_penugasan_saya;
} else {
    $status_pekerjaan = $pekerjaan["status"] ?? "-";
}

$foto_masih_tersedia = false;
$nama_foto = "";
$file_fisik = "";
$url_foto = "";

if ($data_bukti && !empty($data_bukti["foto"]) && !empty($data_bukti["tanggal_upload"])) {
    $nama_foto = basename(trim($data_bukti["foto"]));
    $waktu_upload = strtotime($data_bukti["tanggal_upload"]);
    if ($waktu_upload) {
        $foto_masih_tersedia = time() < ($waktu_upload + 24 * 60 * 60);
    }
    $file_fisik = $_SERVER["DOCUMENT_ROOT"] . "/simo/uploads/bukti/" . $nama_foto;
    $url_foto = "/simo/uploads/bukti/" . rawurlencode($nama_foto);
}

$catatan_admin = ($data_bukti && !empty($data_bukti["catatan_admin"])) ? $data_bukti["catatan_admin"] : "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detail Riwayat Pekerjaan - SIMO</title>
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
.job-status{flex-shrink:0;padding:7px 11px;border-radius:20px;font-size:10px;font-weight:700}
.badge-selesai{background:#e8f5ed;color:#29965a}
.badge-sedang{background:#fff0d5;color:#c87b0c}
.badge-peralihan{background:#e9f1ff;color:#4d6fae}
.badge-menunggu{background:#eee5ff;color:#7650a8}
.badge-perbaikan{background:#fde2d8;color:#c35c32}
.badge-belum{background:#eaf0f8;color:#607087}

/* INFORMASI PERALIHAN (posisi sama seperti di detail tugas: di bawah header, sebelum detail) */
.transfer-list{display:flex;flex-direction:column;gap:12px;margin:16px 0 8px}
.transfer{padding:14px 20px;border:1px solid #cbdaf5;border-radius:10px;background:#f3f7ff}
.transfer-title{font-size:14px;font-weight:700;color:#365b9d;margin-bottom:8px}
.transfer p{font-size:14px;color:#40516b;line-height:1.6}
.transfer strong{font-weight:700}
.transfer-desc{margin-top:8px;padding-top:8px;border-top:1px solid #dce6f8;font-size:13px;font-weight:400;line-height:1.5;color:#526176;overflow-wrap:anywhere}

/* DETAIL */
.detail-grid{display:flex;flex-direction:column;margin-top:4px}
.detail-item{display:grid;grid-template-columns:190px 1fr;align-items:start;column-gap:18px;padding:11px 0;border-bottom:1px solid #edf1f5}
.detail-label{font-size:12.5px;color:#748197;margin:0}
.detail-value{font-size:14px;font-weight:600;color:#263345}
.employee-list{display:flex;flex-direction:column;gap:7px}
.employee{display:flex;align-items:center;gap:8px}
.employee-number{width:7px;height:7px;border-radius:50%;background:#a5212b;flex-shrink:0;font-size:0}
.employee-name{font-size:13.5px;color:#263345;font-weight:600}

/* DESKRIPSI */
.description-area{padding-top:18px}
.description-title{font-size:13px;font-weight:700;color:#263345;margin-bottom:8px}
.description-text{font-size:13.5px;font-weight:400;color:#526176;line-height:1.6}

/* SECTION */
.section-heading{font-size:16px;font-weight:700;color:#1c2636;margin-bottom:3px}
.section-desc{font-size:10.5px;color:#8b96a5;margin-bottom:14px}

/* BUKTI */
.evidence-list{display:flex;flex-direction:column;gap:10px}
.evidence{border:1px solid #e6ebf0;border-radius:10px;padding:12px}
.evidence-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:9px}
.evidence-person{font-size:11.5px;font-weight:700;color:#263345}
.evidence-date{font-size:9.5px;color:#8b96a5;flex-shrink:0;white-space:nowrap}
.evidence-body{display:grid;grid-template-columns:160px minmax(0,1fr);gap:12px}
.evidence-photo{width:160px;height:100px;object-fit:cover;border-radius:7px;border:1px solid #e7ebef;cursor:pointer;display:block}
.evidence-note{background:#f7f9fb;border-radius:8px;padding:10px;min-width:0;overflow-wrap:anywhere}
.evidence-note-title{font-size:12px;color:#6f7b8c;font-weight:700;margin-bottom:4px}
.evidence-note-text{font-size:12.5px;font-weight:400;color:#526176;line-height:1.55}
.empty-evidence{padding:18px;text-align:center;border:1px dashed #d9e0e7;border-radius:9px;color:#8b96a5;font-size:10.5px}

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
body.dark-mode .detail-item{background:transparent!important;border-color:#363b43!important;padding:13px 0!important}
body.dark-mode .detail-label{color:#f1f3f5!important;font-size:12.5px!important}
body.dark-mode .detail-value{color:#f3f5f7!important;font-size:15px!important;font-weight:600!important}
body.dark-mode .employee-list{color:#f3f5f7!important;gap:8px!important}
body.dark-mode .employee{color:#f3f5f7!important}
body.dark-mode .employee-name{color:#f3f5f7!important;font-size:13.5px!important}
body.dark-mode .employee-number{background:#a5212b!important;width:8px!important;height:8px!important}
body.dark-mode .description-area{border-color:#3a3f47!important;padding-top:20px!important}
body.dark-mode .description-title{color:#f3f5f7!important;font-size:13px!important}
body.dark-mode .description-text{color:#dce1e8!important;font-size:13.5px!important}
body.dark-mode .section-heading{color:#f1f3f5!important}
body.dark-mode .section-desc{color:#929baa!important}
body.dark-mode .evidence{background:#1d2025!important;border-color:#343942!important}
body.dark-mode .evidence-person{color:#f1f3f5!important}
body.dark-mode .evidence-date{color:#929baa!important}
body.dark-mode .evidence-photo{border-color:#343942!important}
body.dark-mode .evidence-note{background:#20242a!important;border-color:#343942!important}
body.dark-mode .evidence-note-title{color:#f1f3f5!important}
body.dark-mode .evidence-note-text{color:#cbd2dc!important}
body.dark-mode .empty-evidence{background:#20242a!important;color:#cbd2dc!important;border-color:#343942!important}
body.dark-mode .transfer{background:#202a39!important;border-color:#3b4b63!important}
body.dark-mode .transfer-title{color:#a9c4ff!important}
body.dark-mode .transfer p{color:#e0e7f1!important}
body.dark-mode .transfer-desc{border-top-color:#3b4b63!important;color:#cbd2dc!important}
body.dark-mode .footer-note{color:#747e8c!important}
</style>
</head>
<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

<?php include "../includes/karyawan/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="page-title">
        <div>
            <h2>Detail Riwayat Pekerjaan</h2>
            <p>Informasi pekerjaan yang terdapat dalam riwayat Anda.</p>
        </div>
        <a href="riwayat.php" class="btn-back">
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
            <div class="job-name"><?= e($pekerjaan["nama_pekerjaan"] ?? "-"); ?></div>
            <div class="job-status <?= status_class($status_pekerjaan); ?>"><?= e($status_pekerjaan); ?></div>
        </div>

        <!-- INFORMASI PERALIHAN (di atas detail, sama seperti halaman Detail Tugas) -->
        <?php if ($jumlah_alih > 0): ?>
            <div class="transfer-list">
                <?php while ($alih = mysqli_fetch_assoc($query_alih)): ?>
                    <div class="transfer">
                        <div class="transfer-title">Informasi Peralihan Pekerjaan</div>
                        <p>Pekerjaan ini dialihkan oleh <strong><?= e($alih["nama_pengaju"] ?? "-"); ?></strong> kepada <strong><?= e($alih["nama_pengganti"] ?? "-"); ?></strong>.</p>
                        <p>Tanggal peralihan: <?= formatTanggalWaktu($alih["tanggal_pengajuan"] ?? ""); ?></p>
                        <?php if (!empty($alih["deskripsi_pengalihan"])): ?>
                            <div class="transfer-desc">
                                <strong>Alasan Pengalihan:</strong><br>
                                <?= nl2br(e($alih["deskripsi_pengalihan"])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Jenis Pekerjaan</div>
                <div class="detail-value"><?= !empty($pekerjaan["jenis_pekerjaan"]) ? e($pekerjaan["jenis_pekerjaan"]) : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Tipe Order</div>
                <div class="detail-value"><?= !empty($pekerjaan["tipe_order"]) ? e($pekerjaan["tipe_order"]) : "-"; ?></div>
            </div>

            <?php if (!empty($pekerjaan["nomor_order"])): ?>
            <div class="detail-item">
                <div class="detail-label">Nomor Order</div>
                <div class="detail-value"><?= e($pekerjaan["nomor_order"]); ?></div>
            </div>
            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">Divisi</div>
                <div class="detail-value"><?= e($pekerjaan["nama_divisi"] ?? "-"); ?></div>
            </div>

            <?php if ($is_tugas_tim): ?>
            <div class="detail-item">
                <div class="detail-label">Anggota Tim</div>
                <div class="employee-list">
                    <?php foreach ($data_tim as $i => $anggota): ?>
                        <div class="employee">
                            <span class="employee-number"><?= $i + 1; ?></span>
                            <span class="employee-name"><?= e($anggota["nama"]); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="detail-item">
                <div class="detail-label">Penanggung Jawab</div>
                <div class="detail-value"><?= e($pekerjaan["nama_penanggung_jawab"] ?? "-"); ?></div>
            </div>
            <?php endif; ?>

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
                <div class="detail-value"><?= !empty($pekerjaan["lokasi"]) ? e($pekerjaan["lokasi"]) : "-"; ?></div>
            </div>
        </div>

        <div class="description-area">
            <div class="description-title">Deskripsi</div>
            <div class="description-text">
                <?= !empty($pekerjaan["deskripsi"])
                    ? nl2br(e($pekerjaan["deskripsi"]))
                    : "Tidak ada deskripsi pekerjaan."; ?>
            </div>
        </div>
    </div>

    <!-- BUKTI PEKERJAAN -->
    <div class="card detail-card">
        <h3 class="section-heading">Bukti Pekerjaan</h3>
        <p class="section-desc">Bukti pekerjaan yang dikirim oleh karyawan.</p>

        <?php if ($data_bukti): ?>
            <div class="evidence-list">
                <div class="evidence">
                    <div class="evidence-head">
                        <div class="evidence-person"><?= e($data_bukti["nama_pengirim_bukti"] ?? "Karyawan"); ?></div>
                        <div class="evidence-date"><?= formatTanggalWaktu($data_bukti["tanggal_upload"] ?? ""); ?></div>
                    </div>

                    <div class="evidence-body">
                        <div>
                            <?php if (!empty($data_bukti["foto"]) && $foto_masih_tersedia && file_exists($file_fisik)): ?>
                                <img src="<?= e($url_foto); ?>" class="evidence-photo" alt="Bukti pekerjaan" onclick="bukaFoto(this.src)">
                            <?php elseif (!empty($data_bukti["foto"]) && $foto_masih_tersedia): ?>
                                <div class="empty-evidence">Foto tercatat di sistem, tetapi file fotonya tidak ditemukan.</div>
                            <?php elseif (!empty($data_bukti["foto"])): ?>
                                <div class="empty-evidence">Foto bukti sudah melewati masa penyimpanan 24 jam.</div>
                            <?php else: ?>
                                <div class="empty-evidence">Tidak ada foto bukti pekerjaan.</div>
                            <?php endif; ?>
                        </div>

                        <div class="evidence-note">
                            <div class="evidence-note-title">Catatan Karyawan</div>
                            <div class="evidence-note-text">
                                <?= !empty($data_bukti["catatan_karyawan"])
                                    ? nl2br(e($data_bukti["catatan_karyawan"]))
                                    : "Tidak ada catatan."; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($catatan_admin)): ?>
                        <div class="evidence-note" style="margin-top:9px;">
                            <div class="evidence-note-title">Catatan Admin</div>
                            <div class="evidence-note-text"><?= nl2br(e($catatan_admin)); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-evidence">Belum ada bukti pekerjaan yang dikirim.</div>
        <?php endif; ?>
    </div>

    <div class="footer-note">&copy; <?= date("Y"); ?> SIMOME. All rights reserved.</div>
</div>
</main>

<div class="foto-modal" id="fotoModal" onclick="tutupFoto(event)">
    <button type="button" class="close-modal" onclick="tutupFoto(event)">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto Bukti Ukuran Penuh">
</div>

<script>
function bukaFoto(src){
    document.getElementById("fotoModalImage").src = src;
    document.getElementById("fotoModal").classList.add("active");
    document.body.style.overflow = "hidden";
}
function tutupFoto(event){
    if (event && event.target && event.target.id === "fotoModalImage") return;
    document.getElementById("fotoModal").classList.remove("active");
    document.getElementById("fotoModalImage").src = "";
    document.body.style.overflow = "";
}
document.addEventListener("keydown", function(event){
    if (event.key === "Escape") tutupFoto(null);
});
</script>

</body>
</html>