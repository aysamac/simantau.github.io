<?php
include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Departemen") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

/* =========================================================
   PASTIKAN TABEL / KOLOM PENDUKUNG ADA
========================================================= */
mysqli_query(
    $koneksi,
    "CREATE TABLE IF NOT EXISTS bukti_order (
        id_bukti INT AUTO_INCREMENT PRIMARY KEY,
        id_order INT NOT NULL,
        id_user INT NOT NULL,
        foto TEXT NULL,
        catatan_karyawan TEXT NULL,
        catatan_admin TEXT NULL,
        tanggal_upload DATETIME NOT NULL,
        INDEX idx_bukti_order (id_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

mysqli_query(
    $koneksi,
    "CREATE TABLE IF NOT EXISTS order_pekerja (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_order INT NOT NULL,
        id_user INT NOT NULL,
        UNIQUE KEY uq_order_user (id_order, id_user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$cek_tipe = mysqli_query($koneksi, "SHOW COLUMNS FROM order_perbaikan LIKE 'tipe_kerja'");
if ($cek_tipe && mysqli_num_rows($cek_tipe) == 0) {
    mysqli_query($koneksi, "ALTER TABLE order_perbaikan ADD COLUMN tipe_kerja VARCHAR(10) NULL");
}

/* kolom catatan_admin (dipakai juga untuk catatan perbaikan dari pengorder) */
$cek_catatan = mysqli_query($koneksi, "SHOW COLUMNS FROM bukti_order LIKE 'catatan_admin'");
if ($cek_catatan && mysqli_num_rows($cek_catatan) == 0) {
    mysqli_query($koneksi, "ALTER TABLE bukti_order ADD COLUMN catatan_admin TEXT NULL");
}

/* =========================================================
   PESAN (flash) SETELAH AKSI
========================================================= */
$pesan_sukses = "";
$pesan_error  = "";

if (!empty($_SESSION["flash_detail_sukses"])) {
    $pesan_sukses = $_SESSION["flash_detail_sukses"];
    unset($_SESSION["flash_detail_sukses"]);
}
if (!empty($_SESSION["flash_detail_error"])) {
    $pesan_error = $_SESSION["flash_detail_error"];
    unset($_SESSION["flash_detail_error"]);
}

/* =========================================================
   FUNGSI NOTIFIKASI
========================================================= */
function kirim_notif($koneksi, $id_tujuan, $pesan)
{
    $id_tujuan = (int) $id_tujuan;
    if ($id_tujuan <= 0) {
        return;
    }
    $st = $koneksi->prepare(
        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal)
         VALUES (?, ?, 'Belum Dibaca', NOW())"
    );
    $st->bind_param("is", $id_tujuan, $pesan);
    $st->execute();
}

/* kirim ke PIC + semua pekerja order */
function notif_pekerja_order($koneksi, $id_order, $id_karyawan, $pesan)
{
    $ids = [];
    if (!empty($id_karyawan)) {
        $ids[] = (int) $id_karyawan;
    }

    $st = $koneksi->prepare("SELECT id_user FROM order_pekerja WHERE id_order = ?");
    $st->bind_param("i", $id_order);
    $st->execute();
    $hasil = $st->get_result();
    while ($r = $hasil->fetch_assoc()) {
        $ids[] = (int) $r["id_user"];
    }

    foreach (array_unique($ids) as $uid) {
        kirim_notif($koneksi, $uid, $pesan);
    }
}

/* =========================================================
   AKSI PENGORDER: KONFIRMASI SELESAI / PERLU PERBAIKAN
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $aksi_p     = $_POST["aksi"] ?? "";
    $id_order_p = (int) ($_POST["id_order"] ?? 0);
    $sukses_p   = "";
    $error_p    = "";

    $st = $koneksi->prepare(
        "SELECT * FROM order_perbaikan
         WHERE id_order = ? AND id_pemesan = ?
         LIMIT 1"
    );
    $st->bind_param("ii", $id_order_p, $id_user);
    $st->execute();
    $order_p = $st->get_result()->fetch_assoc();

    if (!$order_p) {

        $error_p = "Order tidak ditemukan.";

    } elseif ($order_p["status"] !== "Menunggu Konfirmasi") {

        $error_p = "Order tidak dapat diubah pada status ini.";

    } elseif ($aksi_p === "pengorder_selesai") {

        $st = $koneksi->prepare(
            "UPDATE order_perbaikan
             SET status = 'Selesai', tanggal_selesai = NOW()
             WHERE id_order = ? AND id_pemesan = ?
               AND status = 'Menunggu Konfirmasi'"
        );
        $st->bind_param("ii", $id_order_p, $id_user);
        $st->execute();

        if ($st->affected_rows > 0) {
            $label = trim(($order_p["nomor_order"] ?? "") . " (" . $order_p["judul"] . ")");
            notif_pekerja_order(
                $koneksi,
                $id_order_p,
                $order_p["id_karyawan"],
                "Order perbaikan $label telah dikonfirmasi selesai oleh pengorder."
            );
            $sukses_p = "Order berhasil dikonfirmasi selesai.";
        } else {
            $error_p = "Gagal mengubah status order.";
        }

    } elseif ($aksi_p === "pengorder_perbaikan") {

        $catatan = trim($_POST["catatan_perbaikan"] ?? "");

        if ($catatan === "") {

            $error_p = "Catatan perbaikan wajib diisi.";

        } else {

            $st = $koneksi->prepare(
                "SELECT id_bukti FROM bukti_order
                 WHERE id_order = ?
                 ORDER BY id_bukti DESC
                 LIMIT 1"
            );
            $st->bind_param("i", $id_order_p);
            $st->execute();
            $b = $st->get_result()->fetch_assoc();

            if (!$b) {

                $error_p = "Bukti order tidak ditemukan.";

            } else {

                $id_bukti_p = (int) $b["id_bukti"];

                $st = $koneksi->prepare("UPDATE bukti_order SET catatan_admin = ? WHERE id_bukti = ?");
                $st->bind_param("si", $catatan, $id_bukti_p);
                $ok1 = $st->execute();

                $st = $koneksi->prepare(
                    "UPDATE order_perbaikan
                     SET status = 'Perlu Perbaikan'
                     WHERE id_order = ? AND id_pemesan = ?
                       AND status = 'Menunggu Konfirmasi'"
                );
                $st->bind_param("ii", $id_order_p, $id_user);
                $ok2 = $st->execute();

                if ($ok1 && $ok2) {
                    $label = trim(($order_p["nomor_order"] ?? "") . " (" . $order_p["judul"] . ")");
                    notif_pekerja_order(
                        $koneksi,
                        $id_order_p,
                        $order_p["id_karyawan"],
                        "Order perbaikan $label perlu diperbaiki. Silakan lihat catatan pengorder."
                    );
                    $sukses_p = "Order dikembalikan untuk diperbaiki.";
                } else {
                    $error_p = "Gagal menyimpan catatan perbaikan.";
                }
            }
        }
    } else {
        $error_p = "Aksi tidak dikenal.";
    }

    /* POST -> redirect -> GET, supaya refresh tidak mengirim ulang form */
    if ($sukses_p !== "") {
        $_SESSION["flash_detail_sukses"] = $sukses_p;
    }
    if ($error_p !== "") {
        $_SESSION["flash_detail_error"] = $error_p;
    }
    header("Location: detail_order.php?id=" . $id_order_p);
    exit;
}

/* =========================================================
   AMBIL ORDER (hanya milik pelapor yang sedang login)
========================================================= */
$id_order = (int) ($_GET["id"] ?? 0);

$st = $koneksi->prepare(
    "SELECT o.*, d.nama_divisi, k.nama AS nama_karyawan
     FROM order_perbaikan o
     LEFT JOIN divisi d ON o.id_divisi = d.id_divisi
     LEFT JOIN users k ON o.id_karyawan = k.id_user
     WHERE o.id_order = ? AND o.id_pemesan = ?
     LIMIT 1"
);
$st->bind_param("ii", $id_order, $id_user);
$st->execute();
$o = $st->get_result()->fetch_assoc();

if (!$o) {
    $_SESSION["flash"] = "Order tidak ditemukan.";
    $_SESSION["flash_error"] = true;
    header("Location: order_saya.php");
    exit;
}

$status = $o["status"];

/* Bukti & pekerja sudah dikirim karyawan sejak Menunggu Konfirmasi */
$selesai      = ($status === "Selesai");
$tampil_hasil = in_array($status, ["Menunggu Konfirmasi", "Perlu Perbaikan", "Selesai"], true);
$tipe_kerja   = $o["tipe_kerja"] ?: "Individu";
$nama_pic     = $o["nama_karyawan"] ?? "";
$nama_pekerja = [];
$bukti        = null;
$foto_bukti   = [];
$foto_hilang  = 0;

if ($tampil_hasil) {
    $st = $koneksi->prepare(
        "SELECT u.nama
         FROM order_pekerja op
         JOIN users u ON u.id_user = op.id_user
         WHERE op.id_order = ?
         ORDER BY (op.id_user = ?) DESC, u.nama"
    );
    $id_pic = (int) ($o["id_karyawan"] ?? 0);
    $st->bind_param("ii", $id_order, $id_pic);
    $st->execute();
    $hasil_pekerja = $st->get_result();
    while ($rp = $hasil_pekerja->fetch_assoc()) {
        $nama_pekerja[] = $rp["nama"];
    }

    /* Bukti terbaru */
    $st = $koneksi->prepare(
        "SELECT b.*, u.nama AS nama_pengirim
         FROM bukti_order b
         LEFT JOIN users u ON u.id_user = b.id_user
         WHERE b.id_order = ?
         ORDER BY b.id_bukti DESC
         LIMIT 1"
    );
    $st->bind_param("i", $id_order);
    $st->execute();
    $bukti = $st->get_result()->fetch_assoc();

    if ($bukti && !empty($bukti["foto"])) {
        foreach (array_filter(array_map("trim", explode(",", $bukti["foto"]))) as $nama_foto) {
            $nama_foto = basename($nama_foto);
            if (is_file(__DIR__ . "/../uploads/bukti/" . $nama_foto)) {
                $foto_bukti[] = $nama_foto;
            } else {
                $foto_hilang++;
            }
        }
    }
}

/* Daftar nama yang tampil: kalau order_pekerja kosong, pakai PIC saja */
$daftar_nama = $nama_pekerja;
if (empty($daftar_nama) && $nama_pic !== "") {
    $daftar_nama = [$nama_pic];
}
$is_tim = ($tipe_kerja == "Tim" && count($daftar_nama) >= 2);

/* Foto kerusakan */
$foto_kerusakan = "";
if (!empty($o["foto"])) {
    $foto_kerusakan = basename($o["foto"]);
}

function format_tgl($t)
{
    return $t ? date("d M Y, H:i", strtotime($t)) : "-";
}

function format_durasi($mulai, $akhir)
{
    if (!$mulai || !$akhir) {
        return "-";
    }
    $menit = max(0, (int) round((strtotime($akhir) - strtotime($mulai)) / 60));
    $jam = intdiv($menit, 60);
    $sisa = $menit % 60;
    return $jam > 0 ? $jam . " jam " . $sisa . " menit" : $sisa . " menit";
}

function status_class($status)
{
    return match ($status) {
        "Selesai"             => "badge-selesai",
        "Diproses"            => "badge-sedang",
        "Menunggu Konfirmasi" => "badge-menunggu",
        "Perlu Perbaikan"     => "badge-perbaikan",
        "Ditolak"             => "badge-ditolak",
        default               => "badge-belum"
    };
}

$status_class = status_class($status);

$keterangan_status = [
    "Menunggu"            => "Order sudah masuk dan menunggu diambil oleh karyawan divisi tujuan.",
    "Diproses"            => "Order sedang dikerjakan.",
    "Menunggu Konfirmasi" => "Pekerjaan sudah dikerjakan. Silakan periksa hasilnya dan konfirmasi di bagian bawah halaman ini.",
    "Perlu Perbaikan"     => "Order dikembalikan ke karyawan untuk diperbaiki sesuai catatan.",
    "Selesai"             => "Order sudah selesai dikerjakan dan dikonfirmasi.",
    "Ditolak"             => "Order ditolak oleh divisi tujuan.",
    "Dibatalkan"          => "Order dibatalkan.",
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detail Order - SIMO</title>
<style>
*{box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
body{background:#ffffff;color:#263345;margin:0;min-height:100vh}
a{text-decoration:none;color:inherit}
.content{padding:24px 32px 40px;max-width:1400px;margin:auto}

/* JUDUL HALAMAN */
.page-title{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
.page-title h2{font-size:26px;font-weight:700;color:#1c2636;margin:0 0 4px}
.page-title p{font-size:15px;color:#7e8998;margin:0}
.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid #e4e8ed;border-radius:8px;background:#f7f8fa;color:#596678;font-size:12px;font-weight:600;white-space:nowrap}
.btn-back:hover{background:#eef1f4}
.btn-back svg{width:15px;height:15px}

/* PESAN */
.alert{padding:12px 14px;margin-bottom:16px;border-radius:10px;font-size:13.5px;font-weight:500;border:1px solid}
.alert-success{background:#e5f6ec;color:#17794a;border-color:#bfe5cf}
.alert-error{background:#fde8e8;color:#b93232;border-color:#f3c3c3}

/* CARD */
.card{background:#fff;border:1px solid #edf1f5;border-radius:14px;padding:20px;box-shadow:0 3px 14px rgba(20,40,70,.035)}
.detail-card{margin-top:16px;border:1px solid #d9dde2;padding:28px}

/* HEADER ORDER */
.job-top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:16px;border-bottom:1px solid #edf1f5}
.job-name{font-size:28px;font-weight:700;color:#1c2636}
.job-number{margin-top:6px;font-size:12px;font-weight:700;color:#b51f2a}
.job-status{flex-shrink:0;padding:7px 11px;border-radius:6px;font-size:10px;font-weight:700}
.badge-selesai{background:#e9f8f0;color:#168353}
.badge-sedang{background:#edf3ff;color:#3f65b5}
.badge-menunggu{background:#fff5dc;color:#a46b00}
.badge-perbaikan{background:#fde8e8;color:#b93232}
.badge-ditolak{background:#fde5e5;color:#c94242}
.badge-belum{background:#eef0f3;color:#667085}

/* INFO STATUS */
.status-info{margin-top:14px;padding:13px 15px;border:1px solid #cbdaf5;border-radius:9px;background:#f3f7ff}
.status-info-title{font-size:13px;font-weight:700;color:#365b9d;margin-bottom:5px}
.status-info p{font-size:13px;color:#40516b;line-height:1.6;margin:0}

/* DETAIL */
.detail-grid{display:flex;flex-direction:column;margin-top:4px}
.detail-item{display:grid;grid-template-columns:190px 1fr;align-items:start;column-gap:18px;padding:11px 0;border-bottom:1px solid #edf1f5}
.detail-label{font-size:12.5px;color:#748197;margin:0}
.detail-value{font-size:14px;font-weight:600;color:#263345}
.employee-list{display:flex;flex-direction:column;gap:7px}
.employee{display:flex;align-items:center;gap:8px}
.employee-number{width:7px;height:7px;border-radius:50%;background:#a5212b;flex-shrink:0;font-size:0}
.employee-name{font-size:13.5px;color:#263345;font-weight:600}
.employee-prev{font-size:12px;color:#8b96a5;font-weight:600}

.tipe{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11.5px;font-weight:700}
.tipe.individu{background:#eef0f3;color:#475467}
.tipe.tim{background:#e6effd;color:#1d4ed8}

/* DESKRIPSI */
.description-area{padding-top:18px}
.description-title{font-size:13px;font-weight:700;color:#263345;margin-bottom:8px}
.description-text{font-size:13.5px;font-weight:400;color:#526176;line-height:1.6}

/* CATATAN / ALASAN */
.repair-note{margin-top:14px;padding:12px 14px;border:1px solid #f0d8d3;border-radius:9px;background:#fff7f5}
.repair-note-title{font-size:12px;font-weight:700;color:#b94b3e;margin-bottom:5px}
.repair-note-text{font-size:12.5px;font-weight:400;color:#667488;line-height:1.6}
.warn-note{margin-bottom:14px;padding:10px 12px;border-radius:9px;background:#fff7e6;border:1px solid #fde3b0;color:#8a5a00;font-size:12.5px}

/* SECTION */
.section-heading{font-size:16px;font-weight:700;color:#1c2636;margin:0 0 3px}
.section-desc{font-size:12px;color:#8b96a5;margin:0 0 14px}

/* FOTO */
.photo-list{display:flex;flex-wrap:wrap;gap:12px}
.photo-item{width:200px;height:130px;border-radius:8px;border:1px solid #e7ebef;overflow:hidden;background:#fff}
.photo-item img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer}
.photo-hint{margin-top:9px;font-size:10.5px;color:#8b96a5}

/* KOLOM FOTO */
.photo-columns{display:flex;flex-wrap:wrap;align-items:flex-start;gap:20px 32px}
.photo-col{flex:0 1 auto;min-width:0}
.photo-columns.dua .photo-col + .photo-col{padding-left:32px;border-left:1px solid #edf1f5}
.photo-col-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8b96a5;margin-bottom:10px}
.evidence-meta{margin-top:8px;font-size:11px;color:#8b96a5}

/* BUKTI */
.evidence{border:1px solid #e6ebf0;border-radius:10px;padding:12px}
.evidence-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}
.evidence-person{font-size:11.5px;font-weight:700;color:#263345}
.evidence-date{font-size:10px;color:#8b96a5}
.evidence-note{background:#f7f9fb;border-radius:8px;padding:12px;margin-top:16px}
.evidence-note-title{font-size:12px;color:#6f7b8c;font-weight:700;margin-bottom:4px}
.evidence-note-text{font-size:12.5px;font-weight:400;color:#526176;line-height:1.55}
.empty-evidence{padding:18px;text-align:center;border:1px dashed #d9e0e7;border-radius:9px;color:#8b96a5;font-size:12px}

/* KONFIRMASI */
.detail-header{padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #edf1f5}
.detail-header h3{font-size:16px;font-weight:700;margin:0 0 3px;color:#1c2636}
.detail-header p{font-size:12.5px;color:#8b96a5;margin:0}
.action-area{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.btn-confirm,.btn-repair{min-height:40px;padding:11px 20px;border:0;border-radius:999px;font-size:13px;font-weight:600;color:#fff;cursor:pointer;font-family:inherit}
.btn-confirm{background:#1f8a4f}
.btn-confirm:hover{background:#197a45}
.btn-repair{background:#b51f2a}
.btn-repair:hover{background:#9a1a24}
.action-note{width:100%;min-height:80px;margin-bottom:8px;padding:11px 12px;border:1px solid #d9e0e7;border-radius:10px;font-size:13px;resize:vertical;outline:none;color:#263345;background:#fff}
.action-note:focus{border-color:#b51f2a}

/* MODAL KONFIRMASI */
.modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(18,24,34,.5);z-index:10000}
.modal.show{display:flex}
.modal-box{width:400px;max-width:100%;padding:28px 26px 24px;background:#fff;border-radius:16px;text-align:center}
.modal-box h3{margin:0 0 8px;font-size:17px;color:#1c2636}
.modal-box p{margin:0 0 22px;font-size:13.5px;color:#7e8998;line-height:1.6}
.modal-actions{display:flex;gap:10px}
.modal-btn{flex:1;min-height:42px;border-radius:999px;border:1px solid #d9e0e7;font-size:13.5px;font-weight:600;cursor:pointer;background:#fff;color:#526176;font-family:inherit}
.modal-ok{background:#1f8a4f;color:#fff;border-color:#1f8a4f}

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
    .photo-columns.dua .photo-col + .photo-col{padding-left:0;border-left:0}
    .content{padding:18px}
    .job-top{flex-direction:column}
    .detail-card{padding:20px}
}
@media(max-width:600px){
    .page-title{align-items:flex-start;flex-direction:column}
    .page-title h2{font-size:22px}
    .detail-item{grid-template-columns:1fr;row-gap:4px}
    .job-name{font-size:22px}
    .photo-item{width:100%;height:170px}
}

/* DARK MODE */
body.dark-mode{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .main,body.dark-mode .content{background:#181b20!important}
body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode label{color:#f1f3f5!important}
body.dark-mode .page-title h2{color:#f1f3f5!important}
body.dark-mode .page-title p{color:#929baa!important}
body.dark-mode .btn-back{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}
body.dark-mode .btn-back:hover{background:#323842!important}
body.dark-mode .card,body.dark-mode .detail-card{background:#1f2227!important;border-color:#3a3f47!important;color:#eef1f5!important;box-shadow:none!important}
body.dark-mode .job-name{color:#f5f6f8!important}
body.dark-mode .job-number{color:#f07a80!important}
body.dark-mode .job-top{border-bottom-color:#3a3f47!important}
body.dark-mode .job-status{font-size:11px!important;padding:8px 12px!important}
body.dark-mode .badge-belum{background:#34383e!important;color:#c5cbd3!important}
body.dark-mode .badge-sedang{background:#1d3158!important;color:#8eb5ff!important}
body.dark-mode .badge-menunggu{background:#3c3924!important;color:#f2d77d!important}
body.dark-mode .badge-perbaikan{background:#4b2d32!important;color:#ff8b92!important}
body.dark-mode .badge-ditolak{background:#4b2d32!important;color:#ff8b92!important}
body.dark-mode .badge-selesai{background:#183d2a!important;color:#72d89a!important}
body.dark-mode .status-info{background:#202a39!important;border-color:#3b4b63!important}
body.dark-mode .status-info-title{color:#a9c4ff!important}
body.dark-mode .status-info p{color:#e0e7f1!important}
body.dark-mode .detail-item{background:transparent!important;border-color:#363b43!important;padding:13px 0!important}
body.dark-mode .detail-label{color:#f1f3f5!important}
body.dark-mode .detail-value{color:#f3f5f7!important;font-size:15px!important}
body.dark-mode .employee-name{color:#f3f5f7!important}
body.dark-mode .employee-prev{color:#aeb7c5!important}
body.dark-mode .tipe.individu{background:#34383e!important;color:#c5cbd3!important}
body.dark-mode .tipe.tim{background:#1b2a47!important;color:#8fb4ff!important}
body.dark-mode .description-title{color:#f3f5f7!important}
body.dark-mode .description-text{color:#dce1e8!important}
body.dark-mode .repair-note{background:#292329!important;border-color:#4a3438!important}
body.dark-mode .repair-note-title{color:#e36b71!important}
body.dark-mode .repair-note-text{color:#d8dee7!important}
body.dark-mode .warn-note{background:#3a2e14!important;border-color:#5a4720!important;color:#f0b45a!important}
body.dark-mode .section-heading{color:#f1f3f5!important}
body.dark-mode .section-desc,body.dark-mode .photo-hint{color:#929baa!important}
body.dark-mode .photo-item{background:#20242a!important;border-color:#343942!important}
body.dark-mode .evidence{background:#1d2025!important;border-color:#343942!important}
body.dark-mode .evidence-person{color:#f1f3f5!important}
body.dark-mode .evidence-date{color:#929baa!important}
body.dark-mode .evidence-note{background:#20242a!important}
body.dark-mode .evidence-note-title{color:#f1f3f5!important}
body.dark-mode .evidence-note-text{color:#cbd2dc!important}
body.dark-mode .empty-evidence{background:#20242a!important;color:#cbd2dc!important;border-color:#343942!important}
body.dark-mode .photo-columns.dua .photo-col + .photo-col{border-left-color:#3a3f47!important}
body.dark-mode .photo-col-title,body.dark-mode .evidence-meta{color:#929baa!important}
body.dark-mode .footer-note{color:#747e8c!important}
body.dark-mode .alert-success{background:#183d2a!important;color:#72d89a!important;border-color:#245c3d!important}
body.dark-mode .alert-error{background:#4b2d32!important;color:#ff8b92!important;border-color:#6a3a40!important}
body.dark-mode .detail-header{border-bottom-color:#3a3f47!important}
body.dark-mode .detail-header h3{color:#f1f3f5!important}
body.dark-mode .detail-header p{color:#929baa!important}
body.dark-mode .action-note{background:#20242a!important;border-color:#343942!important;color:#eef1f5!important}
body.dark-mode .modal-box{background:#1f2227!important;color:#eef1f5!important}
body.dark-mode .modal-box h3{color:#f1f3f5!important}
body.dark-mode .modal-box p{color:#aeb7c5!important}
body.dark-mode .modal-btn{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}
body.dark-mode .modal-ok{background:#1f8a4f!important;border-color:#1f8a4f!important;color:#fff!important}
</style>
</head>
<body>

<?php include "../includes/departemen/sidebar.php"; ?>

<div class="main">

<?php include "../includes/departemen/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="page-title">
        <div>
            <h2>Detail Order</h2>
            <p>Informasi lengkap order perbaikan yang Anda buat.</p>
        </div>

        <a href="order_saya.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5"></path>
                <path d="M12 19l-7-7 7-7"></path>
            </svg>
            Kembali
        </a>
    </div>

    <!-- PESAN AKSI -->
    <?php if ($pesan_sukses !== ""): ?>
        <div class="alert alert-success" role="status"><?= htmlspecialchars($pesan_sukses, ENT_QUOTES, "UTF-8"); ?></div>
    <?php endif; ?>
    <?php if ($pesan_error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($pesan_error, ENT_QUOTES, "UTF-8"); ?></div>
    <?php endif; ?>

    <!-- DETAIL ORDER -->
    <div class="card detail-card">

        <div class="job-top">
            <div>
                <div class="job-name"><?= htmlspecialchars($o["judul"] ?? "-", ENT_QUOTES, "UTF-8"); ?></div>
                <div class="job-number"><?= htmlspecialchars(!empty($o["nomor_order"]) ? $o["nomor_order"] : "Belum ada nomor", ENT_QUOTES, "UTF-8"); ?></div>
            </div>
            <div class="job-status <?= $status_class; ?>"><?= htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?></div>
        </div>

        <?php if (!empty($keterangan_status[$status])): ?>
            <div class="status-info">
                <div class="status-info-title">Keterangan Status</div>
                <p><?= htmlspecialchars($keterangan_status[$status], ENT_QUOTES, "UTF-8"); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($status == "Ditolak" && !empty($o["alasan"])): ?>
            <div class="repair-note">
                <div class="repair-note-title">Alasan Penolakan</div>
                <div class="repair-note-text"><?= nl2br(htmlspecialchars($o["alasan"], ENT_QUOTES, "UTF-8")); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($status == "Perlu Perbaikan" && $bukti && trim($bukti["catatan_admin"] ?? "") !== ""): ?>
            <div class="repair-note">
                <div class="repair-note-title">Catatan Perbaikan</div>
                <div class="repair-note-text"><?= nl2br(htmlspecialchars($bukti["catatan_admin"], ENT_QUOTES, "UTF-8")); ?></div>
            </div>
        <?php endif; ?>

        <div class="detail-grid">

            <div class="detail-item">
                <div class="detail-label">Divisi Tujuan</div>
                <div class="detail-value"><?= !empty($o["nama_divisi"]) ? htmlspecialchars($o["nama_divisi"], ENT_QUOTES, "UTF-8") : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Detail Lokasi</div>
                <div class="detail-value"><?= ($o["lokasi"] ?? "") !== "" ? htmlspecialchars($o["lokasi"], ENT_QUOTES, "UTF-8") : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Dibuat</div>
                <div class="detail-value"><?= format_tgl($o["created_at"] ?? null); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Diambil</div>
                <div class="detail-value"><?= format_tgl($o["tanggal_diambil"] ?? null); ?></div>
            </div>

            <?php if ($selesai): ?>
                <div class="detail-item">
                    <div class="detail-label">Selesai</div>
                    <div class="detail-value"><?= format_tgl($o["tanggal_selesai"] ?? null); ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Durasi Pengerjaan</div>
                    <div class="detail-value"><?= format_durasi($o["tanggal_diambil"] ?? null, $o["tanggal_selesai"] ?? null); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($o["id_karyawan"])): ?>
                <?php if ($tampil_hasil): ?>
                    <div class="detail-item">
                        <div class="detail-label">Tipe Pengerjaan</div>
                        <div class="detail-value">
                            <span class="tipe <?= $tipe_kerja == "Tim" ? "tim" : "individu"; ?>"><?= htmlspecialchars($tipe_kerja, ENT_QUOTES, "UTF-8"); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="detail-item">
                    <div class="detail-label"><?= $is_tim ? "Dikerjakan Oleh (Tim)" : "Dikerjakan Oleh"; ?></div>
                    <div class="employee-list">
                        <?php
                        $daftar_tampil = ($tampil_hasil && !empty($daftar_nama)) ? $daftar_nama : [$nama_pic];
                        foreach ($daftar_tampil as $i => $np):
                            if ($np === "") { continue; }
                        ?>
                            <div class="employee">
                                <span class="employee-number"></span>
                                <span class="employee-name"><?= htmlspecialchars($np, ENT_QUOTES, "UTF-8"); ?></span>
                                <?php if ($np === $nama_pic && $is_tim): ?>
                                    <span class="employee-prev">(Penanggung Jawab)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="detail-item">
                    <div class="detail-label">Dikerjakan Oleh</div>
                    <div class="detail-value">Belum ada karyawan yang mengambil order ini.</div>
                </div>
            <?php endif; ?>

        </div>

        <div class="description-area">
            <div class="description-title">Deskripsi Kerusakan</div>
            <div class="description-text">
                <?php if (!empty($o["deskripsi"])): ?>
                    <?= nl2br(htmlspecialchars($o["deskripsi"], ENT_QUOTES, "UTF-8")); ?>
                <?php else: ?>
                    Tidak ada deskripsi kerusakan.
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- FOTO KERUSAKAN & HASIL PERBAIKAN (satu card) -->
    <div class="card detail-card">

        <h3 class="section-heading">Dokumentasi Foto</h3>
        <p class="section-desc">Foto kerusakan saat order dibuat<?= $tampil_hasil ? " dan hasil perbaikan dari karyawan" : ""; ?>.</p>

        <?php if (!$selesai && $tampil_hasil): ?>
            <div class="warn-note">Bukti perbaikan ini belum dikonfirmasi dan bisa berubah jika perlu diperbaiki.</div>
        <?php endif; ?>

        <?php if ($foto_hilang > 0): ?>
            <div class="warn-note"><?= (int) $foto_hilang; ?> foto bukti tercatat di database tetapi file-nya tidak ditemukan di server (folder uploads/bukti).</div>
        <?php endif; ?>

        <div class="photo-columns <?= $tampil_hasil ? "dua" : ""; ?>">

            <!-- Kerusakan -->
            <div class="photo-col">
                <div class="photo-col-title">Foto Kerusakan</div>
                <?php if ($foto_kerusakan !== ""): ?>
                    <div class="photo-list">
                        <div class="photo-item">
                            <img src="../uploads/order/<?= htmlspecialchars($foto_kerusakan, ENT_QUOTES, "UTF-8"); ?>" alt="Foto kerusakan" onclick="bukaFoto(this.src)">
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-evidence">Tidak ada foto kerusakan.</div>
                <?php endif; ?>
            </div>

            <!-- Hasil perbaikan -->
            <?php if ($tampil_hasil): ?>
            <div class="photo-col">
                <div class="photo-col-title">Foto Hasil Perbaikan</div>
                <?php if (!$bukti): ?>
                    <div class="empty-evidence">Tidak ada bukti yang tercatat.</div>
                <?php elseif (!empty($foto_bukti)): ?>
                    <div class="photo-list">
                        <?php foreach ($foto_bukti as $fb): ?>
                            <div class="photo-item">
                                <img src="../uploads/bukti/<?= htmlspecialchars($fb, ENT_QUOTES, "UTF-8"); ?>" alt="Foto hasil perbaikan" onclick="bukaFoto(this.src)">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-evidence">Tidak ada foto hasil perbaikan.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <div class="photo-hint">Klik foto untuk melihat ukuran penuh.</div>

        <?php if ($tampil_hasil && $bukti): ?>
            <div class="evidence-note">
                <div class="evidence-note-title">Keterangan Pekerjaan</div>
                <div class="evidence-note-text">
                    <?= trim($bukti["catatan_karyawan"] ?? "") !== "" ? nl2br(htmlspecialchars($bukti["catatan_karyawan"], ENT_QUOTES, "UTF-8")) : "Tidak ada keterangan."; ?>
                </div>
                <div class="evidence-meta">
                    Dikirim oleh <?= htmlspecialchars($bukti["nama_pengirim"] ?? "Karyawan", ENT_QUOTES, "UTF-8"); ?>
                    pada <?= format_tgl($bukti["tanggal_upload"] ?? null); ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- KONFIRMASI PENGORDER (hanya saat Menunggu Konfirmasi) -->
    <?php if ($status === "Menunggu Konfirmasi"): ?>
    <div class="card detail-card">

        <div class="detail-header">
            <h3>Konfirmasi</h3>
            <p>Periksa foto hasil perbaikan, lalu tentukan apakah pekerjaan sudah sesuai.</p>
        </div>

        <div class="action-area">
            <form method="POST" id="formSelesai">
                <input type="hidden" name="aksi" value="pengorder_selesai">
                <input type="hidden" name="id_order" value="<?= (int) $o["id_order"]; ?>">
                <button type="submit" class="btn-confirm">Konfirmasi Selesai</button>
            </form>
            <button type="button" class="btn-repair" onclick="showRepair()">Perlu Perbaikan</button>
        </div>

        <div id="repairForm" style="display:none;margin-top:12px;">
            <form method="POST">
                <input type="hidden" name="aksi" value="pengorder_perbaikan">
                <input type="hidden" name="id_order" value="<?= (int) $o["id_order"]; ?>">
                <textarea name="catatan_perbaikan" class="action-note" placeholder="Tuliskan bagian yang perlu diperbaiki..." required></textarea>
                <button type="submit" class="btn-repair">Kirim Perbaikan</button>
            </form>
        </div>

    </div>
    <?php endif; ?>

    <div class="footer-note">&copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

</div>

</div>

<!-- MODAL KONFIRMASI SELESAI -->
<div class="modal" id="confirmModal">
    <div class="modal-box">
        <h3>Konfirmasi Order?</h3>
        <p>Order akan ditandai selesai. Pastikan hasil perbaikan sudah sesuai.</p>
        <div class="modal-actions">
            <button type="button" class="modal-btn" onclick="closeConfirm()">Batal</button>
            <button type="button" class="modal-btn modal-ok" onclick="submitConfirm()">Ya, Selesai</button>
        </div>
    </div>
</div>

<!-- MODAL FOTO -->
<div class="foto-modal" id="fotoModal" onclick="tutupFoto(event)">
    <button type="button" class="close-modal" onclick="tutupFoto(event)">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto Ukuran Penuh">
</div>

<script>
/* FOTO */
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

/* KONFIRMASI SELESAI */
const confirmModal = document.getElementById("confirmModal");
const formSelesai  = document.getElementById("formSelesai");

if (formSelesai) {
    formSelesai.addEventListener("submit", function (e) {
        e.preventDefault();
        confirmModal.classList.add("show");
    });
}

function submitConfirm(){
    if (formSelesai) formSelesai.submit();
}

function closeConfirm(){
    confirmModal.classList.remove("show");
}

confirmModal.addEventListener("click", function (e) {
    if (e.target === confirmModal) closeConfirm();
});

/* FORM PERBAIKAN */
function showRepair(){
    const f = document.getElementById("repairForm");
    if (f) {
        f.style.display = "block";
        const t = f.querySelector("textarea");
        if (t) t.focus();
    }
}

document.addEventListener("keydown", function(event){
    if (event.key === "Escape") {
        tutupFoto(null);
        closeConfirm();
    }
});
</script>

</body>
</html>