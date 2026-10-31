<?php
include "../config/session.php";
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Karyawan") { header("Location: ../index.php"); exit; }
include "../config/koneksi.php";

$id_karyawan = (int) $_SESSION["id_user"];

$query_karyawan = mysqli_query($koneksi, "SELECT * FROM users WHERE id_user='$id_karyawan'");
$karyawan = mysqli_fetch_assoc($query_karyawan);
$nama_karyawan = $karyawan["nama"] ?? "";
$id_divisi = $karyawan["id_divisi"] ?? 0;

$query_divisi = mysqli_query($koneksi, "SELECT * FROM divisi WHERE id_divisi='$id_divisi'");
$divisi = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"] ?? "";
$milik = "(
    EXISTS (
        SELECT 1 FROM pekerjaan_karyawan pk_milik
        WHERE pk_milik.id_pekerjaan=p.id_pekerjaan
        AND pk_milik.id_user='$id_karyawan'
    )
    OR (
        p.id_karyawan='$id_karyawan'
        AND NOT EXISTS (
            SELECT 1 FROM pekerjaan_karyawan pk_lama
            WHERE pk_lama.id_pekerjaan=p.id_pekerjaan
        )
    )
)";

function hitungPekerjaan($koneksi, $milik, $status = "")
{
    $sql = "SELECT COUNT(DISTINCT p.id_pekerjaan) AS total FROM pekerjaan p WHERE $milik";
    if ($status !== "") {
        $status = mysqli_real_escape_string($koneksi, $status);
        $sql .= " AND p.status='$status'";
    }
    $q = mysqli_query($koneksi, $sql);
    if (!$q) { return 0; }
    return (int) (mysqli_fetch_assoc($q)["total"] ?? 0);
}

$total_tugas         = hitungPekerjaan($koneksi, $milik);
$belum_dikerjakan    = hitungPekerjaan($koneksi, $milik, "Belum Dikerjakan");
$sedang_dikerjakan   = hitungPekerjaan($koneksi, $milik, "Sedang Dikerjakan");
$selesai             = hitungPekerjaan($koneksi, $milik, "Selesai");
$menunggu_konfirmasi = hitungPekerjaan($koneksi, $milik, "Menunggu Konfirmasi");
$perlu_perbaikan     = hitungPekerjaan($koneksi, $milik, "Perlu Perbaikan");

$query_tugas = mysqli_query($koneksi, "SELECT DISTINCT p.* FROM pekerjaan p WHERE $milik ORDER BY p.tanggal_mulai DESC, p.id_pekerjaan DESC LIMIT 5");

$query_deadline = mysqli_query($koneksi, "SELECT DISTINCT p.* FROM pekerjaan p WHERE $milik AND p.status NOT IN ('Selesai','Menunggu Konfirmasi') AND p.target_selesai IS NOT NULL AND p.target_selesai > '1000-01-01' ORDER BY p.target_selesai ASC LIMIT 1");
$tugas_deadline = $query_deadline ? mysqli_fetch_assoc($query_deadline) : null;

$id_div_popup = (int) $id_divisi;
$status_aktif_popup = "'Belum Dikerjakan','Menunggu Konfirmasi','Perlu Perbaikan','Peralihan'";

$cond_individu = "(
    p.id_divisi='$id_div_popup'
    AND p.status IN ($status_aktif_popup)
    AND (
        EXISTS (SELECT 1 FROM pekerjaan_karyawan a1 WHERE a1.id_pekerjaan=p.id_pekerjaan AND a1.id_user='$id_karyawan')
        OR (p.id_karyawan='$id_karyawan' AND NOT EXISTS (SELECT 1 FROM pekerjaan_karyawan a2 WHERE a2.id_pekerjaan=p.id_pekerjaan))
    )
    AND (SELECT COUNT(DISTINCT a3.id_user) FROM pekerjaan_karyawan a3 WHERE a3.id_pekerjaan=p.id_pekerjaan) < 2
    AND (
        (
            p.status IN ('Menunggu Konfirmasi','Perlu Perbaikan')
            AND (
                p.id_karyawan='$id_karyawan'
                OR EXISTS (SELECT 1 FROM pekerjaan_karyawan a4 WHERE a4.id_pekerjaan=p.id_pekerjaan AND a4.id_user='$id_karyawan')
            )
        )
        OR (
            p.status NOT IN ('Menunggu Konfirmasi','Perlu Perbaikan')
            AND (
                EXISTS (SELECT 1 FROM pekerjaan_karyawan a5 WHERE a5.id_pekerjaan=p.id_pekerjaan AND a5.id_user='$id_karyawan' AND a5.status_penugasan IN ('Aktif','Peralihan'))
                OR (p.id_karyawan='$id_karyawan' AND NOT EXISTS (SELECT 1 FROM pekerjaan_karyawan a6 WHERE a6.id_pekerjaan=p.id_pekerjaan))
            )
        )
    )
)";

$cond_tim = "(
    p.id_divisi='$id_div_popup'
    AND p.status IN ($status_aktif_popup)
    AND EXISTS (SELECT 1 FROM pekerjaan_karyawan b1 WHERE b1.id_pekerjaan=p.id_pekerjaan AND b1.id_user='$id_karyawan')
    AND (SELECT COUNT(DISTINCT b2.id_user) FROM pekerjaan_karyawan b2 WHERE b2.id_pekerjaan=p.id_pekerjaan) >= 2
    AND (
        p.status IN ('Menunggu Konfirmasi','Perlu Perbaikan')
        OR EXISTS (SELECT 1 FROM pekerjaan_karyawan b3 WHERE b3.id_pekerjaan=p.id_pekerjaan AND b3.id_user='$id_karyawan' AND b3.status_penugasan IN ('Aktif','Peralihan'))
    )
)";

$tugas_saya = "($cond_individu OR $cond_tim)";

function hitungTugasSaya($koneksi, $tugas_saya, $tambahan = "")
{
    $q = mysqli_query($koneksi, "SELECT COUNT(DISTINCT p.id_pekerjaan) AS total FROM pekerjaan p WHERE $tugas_saya $tambahan");
    if (!$q) { return 0; }
    return (int) (mysqli_fetch_assoc($q)["total"] ?? 0);
}

/* Tugas baru = status Belum Dikerjakan / Peralihan (sama dengan badge merah di tab Tugas Saya) */
$popup_baru_total = hitungTugasSaya($koneksi, $tugas_saya, "AND p.status IN ('Belum Dikerjakan','Peralihan')");

/* Deadline: tugas yang masih harus dikerjakan, jatuh tempo <= N hari lagi (termasuk yang sudah lewat) */
$batas_hari_deadline = 3;
$filter_dl = "AND p.status IN ('Belum Dikerjakan','Perlu Perbaikan','Peralihan') AND p.target_selesai IS NOT NULL AND p.target_selesai > '1000-01-01'";
$jumlah_deadline = hitungTugasSaya($koneksi, $tugas_saya, "$filter_dl AND p.target_selesai <= DATE_ADD(NOW(), INTERVAL $batas_hari_deadline DAY)");
$jumlah_telat    = hitungTugasSaya($koneksi, $tugas_saya, "$filter_dl AND p.target_selesai < NOW()");
$jumlah_dekat    = max(0, $jumlah_deadline - $jumlah_telat);

$tampil_popup = false;
if (empty($_SESSION["popup_dashboard_tampil"])) {
    $_SESSION["popup_dashboard_tampil"] = true;
    if ($popup_baru_total > 0 || $jumlah_deadline > 0) {
        $tampil_popup = true;
    }
}

/* Tanggal dipisah: tanggal & jam tidak terpotong di tengah, tapi boleh turun baris jika sempit */
function tglJam($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00" || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }
    $t = strtotime($tanggal);
    if (!$t) {
        return "-";
    }
    return '<span class="tgl">' . date("d M Y", $t) . ',</span> <span class="tgl">' . date("H:i", $t) . '</span>';
}

/* Status "Peralihan" ditampilkan sebagai "Belum Dikerjakan" (sama seperti halaman Tugas Saya) */
function statusTampil($status)
{
    return $status == "Peralihan" ? "Belum Dikerjakan" : $status;
}

function classStatus($status)
{
    if ($status == "Selesai") { return "badge-selesai"; }
    if ($status == "Sedang Dikerjakan") { return "badge-sedang"; }
    if ($status == "Menunggu Konfirmasi") { return "badge-menunggu"; }
    if ($status == "Perlu Perbaikan") { return "badge-perbaikan"; }
    if ($status == "Peralihan") { return "badge-peralihan"; }
    return "badge-belum";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - SIMO</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
body{background:#fff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.layout{min-height:100vh;width:100%}
.main{margin-left:280px;width:calc(100% - 280px);min-height:100vh;overflow-x:hidden}
.content{padding:28px 32px 40px;background:#fff}
.dashboard-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.dashboard-head h1{font-size:28px;color:#1c2636;font-weight:700}
.date-box{display:flex;align-items:center;gap:8px;border:1px solid #e1e6ed;border-radius:10px;padding:10px 14px;color:#667488;font-size:12px;background:#fff}
.date-box svg{width:16px;height:16px;stroke:#b51f2a}
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.stat-card{background:#fff;border:1px solid #e6b7bb;border-radius:13px;padding:16px 18px;height:108px;min-height:108px;position:relative;display:flex;flex-direction:column;justify-content:space-between;transition:transform .2s ease,box-shadow .2s ease}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(16,24,40,.08)}
.stat-top{display:flex;align-items:center;justify-content:space-between}
.stat-label{font-size:14px;font-weight:500;color:#344054}
.stat-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#b51f2a}
.stat-icon svg{width:21px;height:21px;stroke:currentColor}
.stat-card > div:last-child{display:flex;align-items:flex-end;justify-content:space-between;gap:10px}
.stat-number{font-size:28px;font-weight:700;color:#1c2636;line-height:1;letter-spacing:-.5px;margin:0}
.stat-desc{font-size:11px;color:#8b96a5;margin:0 0 1px 0;white-space:nowrap}
.dashboard-grid{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(280px,1fr);gap:18px}
.card{background:#fff;border:1px solid #e1e6ed;border-radius:13px;padding:20px}
.card-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:17px}
.card-title h3{font-size:16px;color:#1c2636}
.card-title p{font-size:11px;color:#8b96a5;margin-top:3px}
.lihat-semua{font-size:11px;color:#b51f2a;font-weight:600}

/* ===== KARTU & TABEL TUGAS TERBARU (desain mengikuti tabel Laporan) ===== */
.table-card{padding:0;overflow:hidden;border:1px solid #d8dde4;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.table-card .card-title{margin:0;padding:18px 22px;border-bottom:1px solid #edf1f6}
.table-card .card-title h3{font-size:14px;font-weight:700;color:#1c2636}

.table-wrapper{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;table-layout:auto}
th,td{vertical-align:middle}
th{background:#b51f2a;padding:13px 10px;text-align:center;font-size:12.5px;color:#fff;font-weight:700;border:0;border-bottom:1px solid #8f1821;white-space:nowrap}
td{padding:14px 10px;font-size:12.5px;color:#4c5b6e;text-align:left;border-bottom:1px solid #f0f3f7;background:#fff;white-space:normal}
td:nth-child(2){color:#1c2636;font-weight:400}
td:nth-child(1){color:#667488}
td:nth-child(7),td:nth-child(8){white-space:nowrap}
tbody tr:hover td{background:#fafbfd}
th:nth-child(1),td:nth-child(1),
th:nth-child(7),td:nth-child(7),
th:nth-child(8),td:nth-child(8){text-align:center}
.tgl{white-space:nowrap}

.status-badge{display:inline-block;padding:5px 9px;border-radius:6px;font-size:10.5px;font-weight:700;line-height:1.3;white-space:nowrap}
.badge-selesai{background:#dff5e8;color:#29965a}.badge-sedang{background:#fff0d5;color:#c87b0c}.badge-menunggu{background:#eaf2ff;color:#3478d4}.badge-perbaikan{background:#fde2e2;color:#d94b4b}.badge-belum{background:#eaf0f8;color:#607087}.badge-peralihan{background:#fff1d9;color:#b7791f}
.btn-lihat{display:inline-block;padding:6px 11px;background:#f9e8ea;color:#b51f2a;border-radius:7px;font-size:10px;font-weight:600}
.btn-lihat:hover{background:#f5dadd;color:#981923}
.empty{text-align:center;padding:30px;color:#7d8998}
.attention-item{display:flex;align-items:center;gap:12px;padding:13px;background:#fff;border-bottom:1px solid #edf1f5}
.attention-item:last-child{border-bottom:none}
.attention-number{width:38px;height:38px;background:#fde0e0;color:#d94b4b;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;flex-shrink:0}
.attention-text{flex:1}.attention-text strong{display:block;font-size:11px;color:#26364e;margin-bottom:3px}.attention-text span{font-size:10px;color:#7e8998}.attention-arrow{color:#d94b4b;font-size:18px}
.deadline{margin-top:18px}.deadline-box{display:flex;align-items:stretch;border:1px solid #e1e6ed;border-radius:10px;overflow:hidden}.deadline-date{width:78px;background:#fff7e9;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px;flex-shrink:0}.deadline-date strong{font-size:21px;color:#26364e}.deadline-date span{font-size:9px;color:#667488}.deadline-info{padding:10px 12px;min-width:0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex:1}.deadline-info strong{display:block;font-size:11px;color:#26364e;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.deadline-info span{font-size:10px;color:#e14b4b}.deadline-arrow{font-size:20px;color:#405067}


/* ===== TABEL TUGAS TERBARU DI HP / LAYAR SEMPIT: tiap baris jadi kartu ===== */
@media (max-width:1000px){
    .table-wrapper{overflow-x:visible}
    .table-card table,.table-card tbody,.table-card tr,.table-card td{display:block;width:100%}
    .table-card thead{display:none}
    .table-card tbody{padding:12px}
    .table-card tbody tr{border:1px solid #edf1f6;border-radius:10px;margin-bottom:12px;overflow:hidden;background:#fff}
    .table-card tbody tr:last-child{margin-bottom:0}
    .table-card td{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 14px;text-align:right;white-space:normal;border-bottom:1px solid #f0f3f7}
    .table-card td::before{content:attr(data-label);font-size:11.5px;font-weight:600;color:#8b96a5;text-align:left;flex-shrink:0}
    .table-card td:nth-child(1){display:none}
    .table-card td:nth-child(2){display:block;background:#fdf3f4;color:#1c2636;font-weight:700;font-size:13.5px;text-align:left;padding:12px 14px}
    .table-card td:nth-child(2)::before{display:none}
    .table-card td:nth-child(7),.table-card td:nth-child(8){white-space:normal}
    .table-card td:last-child{border-bottom:0;padding:12px 14px;display:block}
    .table-card td:last-child::before{display:none}
    .table-card td:last-child .btn-lihat{display:block;width:100%;text-align:center;padding:9px 11px;font-size:12px}
    .table-card td.empty{display:block;text-align:center;border:0}
    .table-card td.empty::before{display:none}
    .table-card tbody tr:hover td{background:inherit}
    .table-card tbody tr:hover td:nth-child(2){background:#fdf3f4}
}

/* RESPONSIVE */
@media (max-width:1200px){
    .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .dashboard-grid{grid-template-columns:minmax(0,1fr);width:100%;min-width:0}
    .dashboard-grid>.card,.dashboard-grid>div{width:100%;max-width:100%;min-width:0}
}
@media (max-width:800px){
    .main{margin-left:0;width:100%;min-width:0}
    .content{width:100%;padding:20px;min-width:0}
    .dashboard-head{align-items:flex-start;gap:15px}
    .dashboard-head h1{font-size:24px}
}
@media (max-width:600px){
    html,body{width:100%;max-width:100%;overflow-x:hidden}
    .main{width:100%;max-width:100%;margin-left:0;min-width:0}
    .content{width:100%;max-width:100%;min-width:0;padding:18px 14px 30px;overflow-x:hidden}
    .dashboard-head{flex-direction:column;width:100%}
    .date-box{align-self:flex-start}
    .stat-grid{gap:10px}
    .stat-card{width:100%;min-width:0;height:108px;min-height:108px;padding:14px 13px}
    .stat-label{font-size:12px}
    .stat-icon{width:24px;height:24px}
    .stat-icon svg{width:19px;height:19px}
    .stat-number{font-size:25px}
    .stat-desc{font-size:9px}
    .dashboard-grid{gap:14px}
    .card{width:100%;max-width:100%;min-width:0;padding:16px 12px;border-radius:13px}
    .table-card{padding:0}
    .table-card .card-title{padding:16px 14px}
    .card-title{width:100%;min-width:0}
    .card-title p{font-size:11px}
    .attention-item,.deadline,.deadline-box{width:100%;max-width:100%}
    .deadline{margin-top:0}
    .btn-lihat{font-size:9.5px;padding:5px 7px}
}


/* ===== POP UP INFO LOGIN (simpel, gaya sama dengan dashboard admin) ===== */
.popup-overlay{position:fixed;inset:0;background:rgba(20,27,38,.35);z-index:3000;display:none;align-items:center;justify-content:center;padding:20px}
.popup-overlay.show{display:flex;animation:popFade .2s ease}
.popup-box{
    --pb-bg:#ffffff;
    --pb-border:#e6e9ee;
    --pb-title:#172033;
    --pb-text:#667085;
    --pb-line:#eef0f3;
    --pb-accent:#b51f2a;
    --pb-warn:#c87b0c;
    --pb-btn-bg:#ffffff;
    --pb-btn-text:#475467;
    --pb-btn-border:#d9dee5;
    --pb-btn-hover:#f5f6f8;
    width:100%;max-width:340px;
    background:var(--pb-bg);
    border:1px solid var(--pb-border);
    border-radius:12px;
    box-shadow:0 10px 30px rgba(16,24,40,.16);
    padding:20px 20px 16px;
    animation:popUp .25s cubic-bezier(.2,.8,.2,1);
}
.popup-box h3{font-size:15px;font-weight:700;color:var(--pb-title);margin:0}
.popup-sub{font-size:12.5px;color:var(--pb-text);margin-top:3px}
.popup-list{list-style:none;margin:14px 0 0;border-top:1px solid var(--pb-line)}
.popup-list li{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--pb-line)}
.popup-label{font-size:13px;font-weight:600;color:var(--pb-title)}
.popup-label small{display:block;font-size:11.5px;font-weight:400;color:var(--pb-text);margin-top:2px}
.popup-num{font-size:22px;font-weight:700;line-height:1;color:var(--pb-accent);flex-shrink:0}
.popup-num.warn{color:var(--pb-warn)}
.popup-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.popup-btn{font:inherit;font-size:12.5px;font-weight:600;height:36px;padding:0 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:background .15s ease,border-color .15s ease}
.popup-btn.secondary{background:var(--pb-btn-bg);color:var(--pb-btn-text);border:1px solid var(--pb-btn-border)}
.popup-btn.secondary:hover{background:var(--pb-btn-hover)}
.popup-btn.primary{background:var(--pb-accent);color:#fff;border:1px solid var(--pb-accent)}
.popup-btn.primary:hover{background:#981923;border-color:#981923}
@keyframes popFade{from{opacity:0}to{opacity:1}}
@keyframes popUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.lihat-semua{border:1px solid #b51f2a!important;border-radius:6px;padding:6px 9px}
table thead th{background:#b51f2a!important;color:#fff!important}
</style>
</head>
<body>

<div class="layout">

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

<?php include "../includes/karyawan/topbar.php"; ?>

<div class="content">

<div class="dashboard-head"><h1>Dashboard</h1><div class="date-box"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path></svg><?= date("l, d M Y"); ?></div></div>

<section class="stat-grid">

<div class="stat-card"><div class="stat-top"><div class="stat-label">Total Pekerjaan</div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"></rect><path d="M9 3h6v3H9z"></path><path d="M8 11h8M8 15h6"></path></svg></div></div><div><div class="stat-number"><?= $total_tugas; ?></div><div class="stat-desc">Seluruh pekerjaan yang diberikan</div></div></div>

<div class="stat-card"><div class="stat-top"><div class="stat-label">Pekerjaan Selesai</div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M8 12l3 3 5-6"></path></svg></div></div><div><div class="stat-number"><?= $selesai; ?></div><div class="stat-desc">Pekerjaan yang telah selesai</div></div></div>

<div class="stat-card"><div class="stat-top"><div class="stat-label">Sedang Dikerjakan</div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></div></div><div><div class="stat-number"><?= $sedang_dikerjakan; ?></div><div class="stat-desc">Sedang dikerjakan</div></div></div>

<div class="stat-card"><div class="stat-top"><div class="stat-label">Perlu Perbaikan</div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l10 18H2L12 3z"></path><path d="M12 9v5M12 17h.01"></path></svg></div></div><div><div class="stat-number"><?= $perlu_perbaikan; ?></div><div class="stat-desc">Menunggu tindak lanjut</div></div></div>

</section>

<section class="dashboard-grid">

<div class="card table-card"><div class="card-title"><div><h3>Tugas Terbaru</h3><p>Daftar tugas terbaru yang diberikan kepadamu.</p></div><a href="tugas.php" class="lihat-semua">Lihat Semua →</a></div><div class="table-wrapper"><table><thead><tr><th>No</th><th>Nama Pekerjaan</th><th>Jenis</th><th>Tipe Order</th><th>Tanggal Mulai</th><th>Target Selesai</th><th>Status</th><th>Aksi</th></tr></thead><tbody>

<?php $no = 1; if ($query_tugas && mysqli_num_rows($query_tugas) > 0): while ($row = mysqli_fetch_assoc($query_tugas)): $status = statusTampil($row["status"]); $class = classStatus($status); $id_row = (int) $row["id_pekerjaan"]; ?>

<tr><td data-label="No"><?= $no++; ?></td><td data-label="Nama Pekerjaan"><?= htmlspecialchars($row["nama_pekerjaan"]); ?></td><td data-label="Jenis"><?= htmlspecialchars($row["jenis_pekerjaan"] ?? "-"); ?></td><td data-label="Tipe Order"><?= htmlspecialchars($row["tipe_order"] ?? "-"); ?></td><td data-label="Tanggal Mulai"><span><?= tglJam($row["tanggal_mulai"] ?? ""); ?></span></td><td data-label="Target Selesai"><span><?= tglJam($row["target_selesai"] ?? ""); ?></span></td><td data-label="Status"><span class="status-badge <?= $class; ?>"><?= htmlspecialchars($status); ?></span></td><td data-label="Aksi"><a href="detail_tugas.php?id=<?= $id_row; ?>" class="btn-lihat">Lihat</a></td></tr>

<?php endwhile; else: ?><tr><td colspan="8" class="empty">Belum ada tugas.</td></tr><?php endif; ?></tbody></table></div></div>

<div><div class="card"><div class="card-title"><div><h3>Perlu Perhatian</h3><p>Tugas yang membutuhkan tindak lanjut.</p></div></div>

<?php if ($menunggu_konfirmasi > 0): ?><a href="tugas.php?status=<?= urlencode("Menunggu Konfirmasi"); ?>" class="attention-item"><div class="attention-number"><?= $menunggu_konfirmasi; ?></div><div class="attention-text"><strong>Pekerjaan menunggu konfirmasi</strong><span>Menunggu pemeriksaan admin.</span></div><div class="attention-arrow">›</div></a><?php endif; ?>

<?php if ($perlu_perbaikan > 0): ?><a href="tugas.php?status=<?= urlencode("Perlu Perbaikan"); ?>" class="attention-item"><div class="attention-number"><?= $perlu_perbaikan; ?></div><div class="attention-text"><strong>Pekerjaan perlu perbaikan</strong><span>Lakukan perbaikan sesuai catatan.</span></div><div class="attention-arrow">›</div></a><?php endif; ?>

<?php if ($menunggu_konfirmasi == 0 && $perlu_perbaikan == 0): ?><div class="empty">Tidak ada pekerjaan yang perlu perhatian.</div><?php endif; ?></div>

<div class="card deadline"><div class="card-title"><div><h3>Jadwal Terdekat</h3><p>Tugas dengan deadline paling dekat.</p></div></div><?php if ($tugas_deadline): ?><a href="detail_tugas.php?id=<?= (int) $tugas_deadline["id_pekerjaan"]; ?>" class="deadline-box"><div class="deadline-date"><strong><?= date("d", strtotime($tugas_deadline["target_selesai"])); ?></strong><span><?= date("M Y", strtotime($tugas_deadline["target_selesai"])); ?></span></div><div class="deadline-info"><div><strong><?= htmlspecialchars($tugas_deadline["nama_pekerjaan"]); ?></strong><span>Target penyelesaian</span></div><div class="deadline-arrow">›</div></div></a><?php else: ?><div class="empty">Tidak ada deadline terdekat.</div><?php endif; ?></div></div>

</section>

</div>

</main>

</div>

<style>
/* =========================
   DARK MODE (warna sama dengan tugas.php)
========================= */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .layout,html body.dark-mode .main,html body.dark-mode main.main,html body.dark-mode .content,html body.dark-mode div.content{background:#0f141b!important}
html body.dark-mode .dashboard-head h1{color:#edf2f7!important}
html body.dark-mode .date-box{background:#151b23!important;border-color:#303947!important;color:#aeb8c6!important}
html body.dark-mode .stat-card,html body.dark-mode .card,html body.dark-mode div.card,html body.dark-mode .card.deadline{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .stat-card:hover{box-shadow:0 5px 14px rgba(0,0,0,.28)!important}
html body.dark-mode .stat-label,html body.dark-mode .card-title h3{color:#edf2f7!important}
html body.dark-mode .table-card .card-title{border-bottom-color:#2c3542!important}
html body.dark-mode .stat-number{color:#edf2f7!important}
html body.dark-mode .stat-desc,html body.dark-mode .card-title p{color:#8f9bac!important}
html body.dark-mode th,html body.dark-mode table thead th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
html body.dark-mode td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
html body.dark-mode td:nth-child(1){color:#cbd2dc!important}
html body.dark-mode tbody tr:hover td{background:#202733!important}
html body.dark-mode .empty{color:#8f9bac!important}
html body.dark-mode .attention-item{background:#151b23!important;border-bottom-color:#2c3542!important}
html body.dark-mode .attention-text strong{color:#edf0f4!important}
html body.dark-mode .attention-text span{color:#8f9bac!important}
html body.dark-mode .deadline-box{border-color:#2c3542!important}
html body.dark-mode .deadline-date{background:#3b3020!important}
html body.dark-mode .deadline-date strong{color:#edf0f4!important}
html body.dark-mode .deadline-date span{color:#9ba6b5!important}
html body.dark-mode .deadline-info strong{color:#edf0f4!important}
html body.dark-mode .deadline-info span{color:#ed7777!important}
html body.dark-mode .deadline-arrow{color:#aeb8c6!important}
html body.dark-mode .btn-lihat{background:#382326!important;color:#f07a80!important}
html body.dark-mode .btn-lihat:hover{background:#4a292d!important;color:#ff9298!important}
html body.dark-mode .attention-number{background:#43282b!important;color:#ed7777!important}
html body.dark-mode .badge-belum{background:#29313d!important;color:#aeb8c6!important}
html body.dark-mode .badge-sedang{background:#3b3020!important;color:#e4aa4f!important}
html body.dark-mode .badge-menunggu{background:#202f45!important;color:#78a8eb!important}
html body.dark-mode .badge-perbaikan{background:#43282b!important;color:#ed7777!important}
html body.dark-mode .badge-peralihan{background:#3d3221!important;color:#d6a34c!important}
html body.dark-mode .badge-selesai{background:#21392c!important;color:#6dce96!important}

/* pop-up (dark) - warna lewat variabel --pb-* */
html body.dark-mode .popup-overlay{background:rgba(0,0,0,.55)!important}
html body.dark-mode .popup-box{
    --pb-bg:#151b23;
    --pb-border:#2c3542;
    --pb-title:#edf2f7;
    --pb-text:#9eabbc;
    --pb-line:#2c3542;
    --pb-accent:#e5424d;
    --pb-warn:#e4aa4f;
    --pb-btn-bg:#202733;
    --pb-btn-text:#cbd2dc;
    --pb-btn-border:#2c3542;
    --pb-btn-hover:#28303d;
    box-shadow:0 10px 30px rgba(0,0,0,.5)!important;
}
html body.dark-mode .popup-btn.primary{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
html body.dark-mode .popup-btn.primary:hover{background:#c92834!important;border-color:#c92834!important}

@media (max-width:1000px){
html body.dark-mode .table-card tbody tr{background:#151b23!important;border-color:#2c3542!important}
html body.dark-mode .table-card td:nth-child(2){background:#1b222c!important}
html body.dark-mode .table-card tbody tr:hover td:nth-child(2){background:#1b222c!important}
html body.dark-mode .table-card td::before{color:#8f9bac!important}
}
</style>


<?php if ($tampil_popup): ?>
<div class="popup-overlay show" id="popupInfo" role="dialog" aria-modal="true" aria-labelledby="popupJudul">
    <div class="popup-box">
        <h3 id="popupJudul">Halo, <?= htmlspecialchars($nama_karyawan); ?></h3>
        <p class="popup-sub">Ringkasan pekerjaanmu hari ini.</p>

        <ul class="popup-list">

            <?php if ($popup_baru_total > 0): ?>
            <li>
                <div class="popup-label">
                    Tugas baru
                    <small>Belum kamu kerjakan</small>
                </div>
                <div class="popup-num"><?= $popup_baru_total; ?></div>
            </li>
            <?php endif; ?>

            <?php if ($jumlah_deadline > 0): ?>
            <li>
                <div class="popup-label">
                    Deadline
                    <small><?php
                        $ket = [];
                        if ($jumlah_telat > 0) { $ket[] = $jumlah_telat . " sudah terlambat"; }
                        if ($jumlah_dekat > 0) { $ket[] = $jumlah_dekat . " jatuh tempo &le; " . $batas_hari_deadline . " hari"; }
                        echo implode(" &bull; ", $ket);
                    ?></small>
                </div>
                <div class="popup-num <?= $jumlah_telat > 0 ? "" : "warn"; ?>"><?= $jumlah_deadline; ?></div>
            </li>
            <?php endif; ?>

        </ul>

        <div class="popup-actions">
            <button type="button" class="popup-btn secondary" id="popupTutup">Tutup</button>
            <a href="tugas.php" class="popup-btn primary">Lihat Tugas</a>
        </div>
    </div>
</div>
<script>
(function(){
    var pop = document.getElementById("popupInfo");
    if (!pop) return;
    function tutup(){ pop.classList.remove("show"); }
    document.getElementById("popupTutup").addEventListener("click", tutup);
    pop.addEventListener("click", function(e){ if (e.target === pop) tutup(); });
    document.addEventListener("keydown", function(e){ if (e.key === "Escape") tutup(); });
})();
</script>
<?php endif; ?>

</body>
</html>