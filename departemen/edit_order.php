<?php
include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Departemen") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

/* =========================================================
   CSRF TOKEN
========================================================= */
if (empty($_SESSION["csrf_edit_order"])) {
    $_SESSION["csrf_edit_order"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_edit_order"];

/* =========================================================
   AMBIL ORDER (hanya milik user ini)
========================================================= */
$id_order = (int) ($_GET["id"] ?? $_POST["id_order"] ?? 0);

$st = $koneksi->prepare(
    "SELECT o.*, d.nama_divisi
     FROM order_perbaikan o
     LEFT JOIN divisi d ON o.id_divisi = d.id_divisi
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

/* Edit hanya boleh selama order masih Menunggu (belum diambil karyawan) */
if ($o["status"] !== "Menunggu") {
    $_SESSION["flash"] = "Order tidak dapat diedit karena sudah diambil atau diproses.";
    $_SESSION["flash_error"] = true;
    header("Location: order_saya.php");
    exit;
}

/* =========================================================
   DAFTAR DIVISI
========================================================= */
$daftar_divisi = [];
$q_divisi = mysqli_query($koneksi, "SELECT id_divisi, nama_divisi FROM divisi ORDER BY nama_divisi ASC");
if ($q_divisi) {
    while ($d = mysqli_fetch_assoc($q_divisi)) {
        $daftar_divisi[] = $d;
    }
}

/* =========================================================
   NILAI FORM (default = data saat ini)
========================================================= */
$judul       = $o["judul"] ?? "";
$id_divisi   = (int) ($o["id_divisi"] ?? 0);
$lokasi      = $o["lokasi"] ?? "";
$deskripsi   = $o["deskripsi"] ?? "";
$foto_lama   = !empty($o["foto"]) ? basename($o["foto"]) : "";
$pesan_error = "";

$folder_foto = __DIR__ . "/../uploads/order/";

/* =========================================================
   SIMPAN PERUBAHAN
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $judul     = trim($_POST["judul"] ?? "");
    $id_divisi = (int) ($_POST["id_divisi"] ?? 0);
    $lokasi    = trim($_POST["lokasi"] ?? "");
    $deskripsi = trim($_POST["deskripsi"] ?? "");
    $hapus_foto = !empty($_POST["hapus_foto"]);

    $divisi_valid = false;
    foreach ($daftar_divisi as $d) {
        if ((int) $d["id_divisi"] === $id_divisi) {
            $divisi_valid = true;
            break;
        }
    }

    $foto_baru = "";

    if (!hash_equals($csrf, (string) ($_POST["csrf"] ?? ""))) {

        $pesan_error = "Sesi tidak valid. Muat ulang halaman lalu coba lagi.";

    } elseif ($judul === "") {

        $pesan_error = "Nama peralatan wajib diisi.";

    } elseif (mb_strlen($judul) > 150) {

        $pesan_error = "Nama peralatan maksimal 150 karakter.";

    } elseif (!$divisi_valid) {

        $pesan_error = "Divisi tujuan tidak valid.";

    } elseif ($deskripsi === "") {

        $pesan_error = "Deskripsi kerusakan wajib diisi.";

    } else {

        /* ---------- upload foto baru (opsional) ---------- */
        if (!empty($_FILES["foto"]["name"])) {

            $berkas = $_FILES["foto"];

            if ($berkas["error"] !== UPLOAD_ERR_OK) {

                $pesan_error = "Foto gagal diunggah. Coba lagi.";

            } elseif ($berkas["size"] > 5 * 1024 * 1024) {

                $pesan_error = "Ukuran foto maksimal 5 MB.";

            } else {

                $info = @getimagesize($berkas["tmp_name"]);
                $tipe_boleh = [
                    IMAGETYPE_JPEG => "jpg",
                    IMAGETYPE_PNG  => "png",
                    IMAGETYPE_WEBP => "webp",
                ];

                if (!$info || !isset($tipe_boleh[$info[2]])) {

                    $pesan_error = "Format foto harus JPG, PNG, atau WEBP.";

                } else {

                    if (!is_dir($folder_foto)) {
                        @mkdir($folder_foto, 0755, true);
                    }

                    $nama_file = "order_" . $id_user . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $tipe_boleh[$info[2]];

                    if (move_uploaded_file($berkas["tmp_name"], $folder_foto . $nama_file)) {
                        $foto_baru = $nama_file;
                    } else {
                        $pesan_error = "Foto gagal disimpan di server.";
                    }
                }
            }
        }

        /* ---------- update database ---------- */
        if ($pesan_error === "") {

            if ($foto_baru !== "") {
                $foto_simpan = $foto_baru;
            } elseif ($hapus_foto) {
                $foto_simpan = "";
            } else {
                $foto_simpan = $foto_lama;
            }

            $st = $koneksi->prepare(
                "UPDATE order_perbaikan
                 SET judul = ?, id_divisi = ?, lokasi = ?, deskripsi = ?, foto = ?
                 WHERE id_order = ? AND id_pemesan = ? AND status = 'Menunggu'"
            );
            $st->bind_param("sisssii", $judul, $id_divisi, $lokasi, $deskripsi, $foto_simpan, $id_order, $id_user);
            $ok = $st->execute();

            $berhasil = false;

            if ($ok && $st->affected_rows > 0) {
                $berhasil = true;
            } elseif ($ok) {
                /* 0 baris berubah: bisa karena tidak ada perubahan, atau status sudah berubah */
                $cek = $koneksi->prepare("SELECT status FROM order_perbaikan WHERE id_order = ? AND id_pemesan = ? LIMIT 1");
                $cek->bind_param("ii", $id_order, $id_user);
                $cek->execute();
                $r = $cek->get_result()->fetch_assoc();

                if ($r && $r["status"] === "Menunggu") {
                    $berhasil = true;
                } else {
                    $pesan_error = "Order tidak dapat diedit karena sudah diambil atau diproses.";
                }
            } else {
                $pesan_error = "Gagal menyimpan perubahan. Silakan coba lagi.";
            }

            if ($berhasil) {
                /* hapus file foto lama bila diganti / dihapus */
                if ($foto_lama !== "" && $foto_lama !== $foto_simpan) {
                    $file_lama = $folder_foto . $foto_lama;
                    if (is_file($file_lama)) {
                        @unlink($file_lama);
                    }
                }

                $_SESSION["flash"] = "Order berhasil diperbarui.";
                header("Location: order_saya.php");
                exit;
            }

            /* gagal: buang foto baru yang sudah terlanjur diunggah */
            if ($foto_baru !== "" && is_file($folder_foto . $foto_baru)) {
                @unlink($folder_foto . $foto_baru);
            }
        } elseif ($foto_baru !== "" && is_file($folder_foto . $foto_baru)) {
            @unlink($folder_foto . $foto_baru);
        }
    }
}

$nomor_tampil = !empty($o["nomor_order"]) ? $o["nomor_order"] : "Belum ada nomor";
$foto_ada = ($foto_lama !== "" && is_file($folder_foto . $foto_lama));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Order - SIMO</title>
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
.alert-error{background:#fde8e8;color:#b93232;border-color:#f3c3c3}

/* CARD */
.card{background:#fff;border:1px solid #d9dde2;border-radius:14px;padding:28px;box-shadow:0 3px 14px rgba(20,40,70,.035);margin-top:16px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:14px;padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid #edf1f5}
.card-head h3{margin:0;font-size:18px;font-weight:700;color:#1c2636}
.job-number{font-size:12px;font-weight:700;color:#b51f2a;background:#fdecee;padding:5px 11px;border-radius:8px}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 22px}
.field{display:flex;flex-direction:column;gap:7px;min-width:0}
.field.full{grid-column:1 / -1}
.field label{font-size:12.5px;font-weight:600;color:#526176}
.field label .req{color:#b51f2a}
.input,.select,.textarea{width:100%;padding:11px 13px;border:1px solid #d9e0e7;border-radius:10px;font-size:14px;color:#263345;background:#fff;outline:none;font-family:inherit;transition:border-color .15s}
.input:focus,.select:focus,.textarea:focus{border-color:#b51f2a}
.textarea{min-height:120px;resize:vertical;line-height:1.55}
.hint{font-size:11.5px;color:#8b96a5}

/* FOTO */
.photo-current{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap}
.photo-thumb{width:200px;height:130px;border-radius:8px;border:1px solid #e7ebef;overflow:hidden;background:#fff}
.photo-thumb img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer}
.check{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:#526176;cursor:pointer}
.check input{width:16px;height:16px;accent-color:#b51f2a}
.file-input{padding:9px 11px;border:1px dashed #cfd6de;border-radius:10px;background:#fafbfc;font-size:13px;color:#526176;width:100%}
.preview-baru{display:none;margin-top:10px}
.preview-baru .photo-thumb{width:200px;height:130px}
.empty-photo{padding:16px;border:1px dashed #d9e0e7;border-radius:9px;color:#8b96a5;font-size:12.5px}

/* AKSI */
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:26px;padding-top:20px;border-top:1px solid #edf1f5;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:11px 22px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;border:1px solid transparent}
.btn-simpan{background:#b51f2a;color:#fff}
.btn-simpan:hover{background:#9f1c26}
.btn-batal{background:#fff;color:#526176;border-color:#d9e0e7}
.btn-batal:hover{background:#f4f6f9}

.footer-note{text-align:center;padding:24px 0 6px;color:#a3adba;font-size:11px}

/* MODAL FOTO */
.foto-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.82);align-items:center;justify-content:center;padding:30px}
.foto-modal.active{display:flex}
.foto-modal img{max-width:95%;max-height:90vh;width:auto;height:auto;object-fit:contain;border-radius:6px}
.close-modal{position:absolute;top:20px;right:28px;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:25px;cursor:pointer}

/* RESPONSIVE */
@media(max-width:800px){
    .content{padding:18px}
    .card{padding:20px}
    .form-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
    .page-title{align-items:flex-start;flex-direction:column}
    .page-title h2{font-size:22px}
    .photo-thumb,.preview-baru .photo-thumb{width:100%;height:170px}
    .form-actions .btn{flex:1}
}

/* DARK MODE */
body.dark-mode{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .main,body.dark-mode .content{background:#181b20!important}
body.dark-mode h1,body.dark-mode h2,body.dark-mode h3{color:#f1f3f5!important}
body.dark-mode .page-title h2{color:#f1f3f5!important}
body.dark-mode .page-title p{color:#929baa!important}
body.dark-mode .btn-back{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}
body.dark-mode .btn-back:hover{background:#323842!important}
body.dark-mode .card{background:#1f2227!important;border-color:#3a3f47!important;box-shadow:none!important}
body.dark-mode .card-head{border-bottom-color:#3a3f47!important}
body.dark-mode .job-number{background:#302126!important;color:#f07a80!important}
body.dark-mode .field label{color:#cbd2dc!important}
body.dark-mode .input,body.dark-mode .select,body.dark-mode .textarea{background:#20242a!important;border-color:#343942!important;color:#eef1f5!important}
body.dark-mode .input:focus,body.dark-mode .select:focus,body.dark-mode .textarea:focus{border-color:#b51f2a!important}
body.dark-mode .hint{color:#929baa!important}
body.dark-mode .photo-thumb{background:#20242a!important;border-color:#343942!important}
body.dark-mode .check{color:#cbd2dc!important}
body.dark-mode .file-input{background:#20242a!important;border-color:#3a3f47!important;color:#cbd2dc!important}
body.dark-mode .empty-photo{background:#20242a!important;border-color:#343942!important;color:#cbd2dc!important}
body.dark-mode .form-actions{border-top-color:#3a3f47!important}
body.dark-mode .btn-batal{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}
body.dark-mode .btn-batal:hover{background:#323842!important}
body.dark-mode .alert-error{background:#4b2d32!important;color:#ff8b92!important;border-color:#6a3a40!important}
body.dark-mode .footer-note{color:#747e8c!important}
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
            <h2>Edit Order</h2>
            <p>Ubah data order selama belum diambil oleh karyawan.</p>
        </div>

        <a href="order_saya.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5"></path>
                <path d="M12 19l-7-7 7-7"></path>
            </svg>
            Kembali
        </a>
    </div>

    <?php if ($pesan_error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($pesan_error, ENT_QUOTES, "UTF-8"); ?></div>
    <?php endif; ?>

    <div class="card">

        <div class="card-head">
            <h3>Data Order</h3>
            <span class="job-number"><?= htmlspecialchars($nomor_tampil, ENT_QUOTES, "UTF-8"); ?></span>
        </div>

        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8"); ?>">
            <input type="hidden" name="id_order" value="<?= (int) $o["id_order"]; ?>">

            <div class="form-grid">

                <div class="field">
                    <label for="judul">Nama Peralatan <span class="req">*</span></label>
                    <input type="text" id="judul" name="judul" class="input" maxlength="150" required
                           value="<?= htmlspecialchars($judul, ENT_QUOTES, "UTF-8"); ?>">
                </div>

                <div class="field">
                    <label for="id_divisi">Divisi Tujuan <span class="req">*</span></label>
                    <select id="id_divisi" name="id_divisi" class="select" required>
                        <option value="">Pilih divisi</option>
                        <?php foreach ($daftar_divisi as $d): ?>
                            <option value="<?= (int) $d["id_divisi"]; ?>" <?= ((int) $d["id_divisi"] === $id_divisi) ? "selected" : ""; ?>>
                                <?= htmlspecialchars($d["nama_divisi"], ENT_QUOTES, "UTF-8"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full">
                    <label for="lokasi">Detail Lokasi</label>
                    <input type="text" id="lokasi" name="lokasi" class="input"
                           value="<?= htmlspecialchars($lokasi, ENT_QUOTES, "UTF-8"); ?>">
                </div>

                <div class="field full">
                    <label for="deskripsi">Deskripsi Kerusakan <span class="req">*</span></label>
                    <textarea id="deskripsi" name="deskripsi" class="textarea" required><?= htmlspecialchars($deskripsi, ENT_QUOTES, "UTF-8"); ?></textarea>
                </div>

                <div class="field full">
                    <label>Foto Kerusakan</label>

                    <?php if ($foto_ada): ?>
                        <div class="photo-current">
                            <div class="photo-thumb">
                                <img src="../uploads/order/<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>" alt="Foto kerusakan saat ini" onclick="bukaFoto(this.src)">
                            </div>
                            <label class="check">
                                <input type="checkbox" name="hapus_foto" value="1" id="hapusFoto">
                                Hapus foto ini
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="empty-photo">Belum ada foto kerusakan.</div>
                    <?php endif; ?>

                    <input type="file" name="foto" id="fotoBaru" class="file-input" accept="image/jpeg,image/png,image/webp">
                    <div class="hint">
                        <?= $foto_ada ? "Pilih file baru untuk mengganti foto di atas. " : ""; ?>Format JPG, PNG, atau WEBP, maksimal 5 MB.
                    </div>

                    <div class="preview-baru" id="previewBaru">
                        <div class="photo-thumb"><img id="previewImg" src="" alt="Pratinjau foto baru"></div>
                    </div>
                </div>

            </div>

            <div class="form-actions">
                <a href="order_saya.php" class="btn btn-batal">Batal</a>
                <button type="submit" class="btn btn-simpan">Simpan Perubahan</button>
            </div>
        </form>

    </div>

    <div class="footer-note">&copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

</div>

</div>

<!-- MODAL FOTO -->
<div class="foto-modal" id="fotoModal" onclick="tutupFoto(event)">
    <button type="button" class="close-modal" onclick="tutupFoto(event)">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto Ukuran Penuh">
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

document.addEventListener("keydown", function(e){
    if (e.key === "Escape") tutupFoto(null);
});

/* Pratinjau foto baru + validasi ukuran */
(function(){
    var input   = document.getElementById("fotoBaru");
    var box     = document.getElementById("previewBaru");
    var img     = document.getElementById("previewImg");
    var hapus   = document.getElementById("hapusFoto");

    input.addEventListener("change", function(){
        var f = input.files && input.files[0];

        if (!f) {
            box.style.display = "none";
            img.src = "";
            return;
        }

        if (f.size > 5 * 1024 * 1024) {
            alert("Ukuran foto maksimal 5 MB.");
            input.value = "";
            box.style.display = "none";
            return;
        }

        img.src = URL.createObjectURL(f);
        box.style.display = "block";

        if (hapus) hapus.checked = false;
    });
})();
</script>

</body>
</html>