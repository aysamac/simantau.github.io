<?php
include "../config/session.php";

// Waktu server mengikuti WIB (bukan UTC)
date_default_timezone_set("Asia/Jakarta");

// Hanya role Departemen yang boleh masuk
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Departemen") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

/* Kolom nama pelapor (diketik sendiri oleh pemesan) dibuat otomatis kalau belum ada */
$cek_pelapor = mysqli_query($koneksi, "SHOW COLUMNS FROM order_perbaikan LIKE 'nama_pelapor'");
if ($cek_pelapor && mysqli_num_rows($cek_pelapor) == 0) {
    mysqli_query($koneksi, "ALTER TABLE order_perbaikan ADD COLUMN nama_pelapor VARCHAR(100) NULL");
}

/* Pilihan nama peralatan (dropdown) */
$daftar_peralatan = ["Mesin", "Infrastruktur", "Peralatan", "Armada Nopol"];

/* =========================================================
   FUNGSI: simpan foto dan kompres ke sekitar 300 KB
========================================================= */
function simpan_foto_kompres($tmp, $mime, $folder)
{
    $nama_dasar = "order_" . date("YmdHis") . "_" . bin2hex(random_bytes(4));

    // Tanpa GD: simpan apa adanya
    if (!function_exists("imagecreatetruecolor")) {
        $ekstensi = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"][$mime];
        $nama = $nama_dasar . "." . $ekstensi;
        return move_uploaded_file($tmp, $folder . $nama) ? $nama : false;
    }

    if ($mime == "image/jpeg" && function_exists("imagecreatefromjpeg")) {
        $src = @imagecreatefromjpeg($tmp);
    } elseif ($mime == "image/png" && function_exists("imagecreatefrompng")) {
        $src = @imagecreatefrompng($tmp);
    } elseif ($mime == "image/webp" && function_exists("imagecreatefromwebp")) {
        $src = @imagecreatefromwebp($tmp);
    } else {
        $src = false;
    }
    if (!$src) {
        return false;
    }

    // Putar sesuai orientasi kamera (foto dari HP)
    if ($mime == "image/jpeg" && function_exists("exif_read_data")) {
        $exif = @exif_read_data($tmp);
        $o = $exif["Orientation"] ?? 1;
        if ($o == 3) { $src = imagerotate($src, 180, 0); }
        elseif ($o == 6) { $src = imagerotate($src, -90, 0); }
        elseif ($o == 8) { $src = imagerotate($src, 90, 0); }
    }

    // Perkecil kalau terlalu besar (sisi terpanjang maksimal 1600 px)
    $w = imagesx($src);
    $h = imagesy($src);
    $maks = 1600;
    if (max($w, $h) > $maks) {
        $skala = $maks / max($w, $h);
        $nw = (int) round($w * $skala);
        $nh = (int) round($h * $skala);
    } else {
        $nw = $w;
        $nh = $h;
    }

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    // Turunkan kualitas bertahap sampai sekitar 300 KB
    $data = "";
    for ($kualitas = 85; $kualitas >= 40; $kualitas -= 10) {
        ob_start();
        imagejpeg($dst, null, $kualitas);
        $data = ob_get_clean();
        if (strlen($data) <= 300 * 1024) {
            break;
        }
    }

    $nama = $nama_dasar . ".jpg";
    return file_put_contents($folder . $nama, $data) !== false ? $nama : false;
}

/* =========================================================
   DATA PEMESAN DAN PILIHAN
========================================================= */

// Departemen asal pemesan (otomatis dari akun)
$st = $koneksi->prepare(
    "SELECT users.nama, users.id_divisi, users.departemen, divisi.nama_divisi
     FROM users
     LEFT JOIN divisi ON users.id_divisi = divisi.id_divisi
     WHERE users.id_user = ?"
);
$st->bind_param("i", $id_user);
$st->execute();
$pelapor = $st->get_result()->fetch_assoc();
$id_divisi_asal = (int) ($pelapor["id_divisi"] ?? 0);

// Daftar divisi untuk dropdown "Departemen Tujuan"
$daftar_divisi = [];
$q = $koneksi->query("SELECT id_divisi, nama_divisi FROM divisi ORDER BY nama_divisi");
while ($d = $q->fetch_assoc()) {
    $daftar_divisi[(int) $d["id_divisi"]] = $d["nama_divisi"];
}

// Pesan sukses dari order sebelumnya
$pesan = $_SESSION["flash"] ?? "";
$pesan_error = false;
unset($_SESSION["flash"]);

// Nilai isian (supaya tidak hilang kalau ada error)
$deskripsi = $jenis_peralatan = $nama_peralatan = $detail_lokasi = $nama_pelapor = "";
$tujuan = 0;
$waktu_input = date("Y-m-d\TH:i");

/* =========================================================
   PROSES KIRIM
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $deskripsi      = trim($_POST["deskripsi"] ?? "");
    $jenis_peralatan = trim($_POST["jenis_peralatan"] ?? "");
    $nama_peralatan = trim($_POST["nama_peralatan"] ?? "");
    $nama_pelapor   = trim($_POST["nama_pelapor"] ?? "");
    $tujuan         = (int) ($_POST["id_divisi"] ?? 0);
    $detail_lokasi  = trim($_POST["detail_lokasi"] ?? "");
    $waktu_input    = $_POST["waktu"] ?? date("Y-m-d\TH:i");

    $error = "";
    $foto_nama = null;

    $waktu_ts = strtotime($waktu_input);

    if ($deskripsi == "") {
        $error = "Uraian kerusakan wajib diisi.";
    } elseif (!in_array($jenis_peralatan, $daftar_peralatan, true)) {
        $error = "Pilih jenis peralatan terlebih dahulu.";
    } elseif ($nama_peralatan == "") {
        $error = "Nama peralatan wajib diisi.";
    } elseif (mb_strlen($nama_peralatan) > 100) {
        $error = "Nama peralatan maksimal 100 karakter.";
    } elseif ($nama_pelapor == "") {
        $error = "Nama pelapor wajib diisi.";
    } elseif (mb_strlen($nama_pelapor) > 100) {
        $error = "Nama pelapor maksimal 100 karakter.";
    } elseif (!isset($daftar_divisi[$tujuan])) {
        $error = "Pilih departemen tujuan terlebih dahulu.";
    } elseif ($waktu_ts === false) {
        $error = "Tanggal dan jam tidak valid.";
    } elseif ($waktu_ts > time() + 300) {
        $error = "Tanggal dan jam tidak boleh melebihi waktu sekarang.";
    }

    // Proses foto (opsional)
    if ($error == "" && !empty($_FILES["foto"]["name"])) {

        if ($_FILES["foto"]["error"] !== UPLOAD_ERR_OK) {
            $error = "Foto gagal diunggah. Pastikan ukurannya tidak terlalu besar.";
        } elseif ($_FILES["foto"]["size"] > 8 * 1024 * 1024) {
            $error = "Ukuran foto maksimal 8 MB.";
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["foto"]["tmp_name"]);

            if (!in_array($mime, ["image/jpeg", "image/png", "image/webp"], true)) {
                $error = "Format foto harus JPG, PNG, atau WEBP.";
            } else {
                $folder = __DIR__ . "/../uploads/order/";
                if (!is_dir($folder)) {
                    mkdir($folder, 0777, true);
                }
                $foto_nama = simpan_foto_kompres($_FILES["foto"]["tmp_name"], $mime, $folder);
                if ($foto_nama === false) {
                    $error = "Foto gagal diproses. Coba foto lain.";
                    $foto_nama = null;
                }
            }
        }
    }

    if ($error != "") {
        $pesan = $error;
        $pesan_error = true;
    } else {
        $waktu_db = date("Y-m-d H:i:s", $waktu_ts);

        // Sama seperti di kertas order: "Infrastruktur: Pintu"
        $judul_order = $jenis_peralatan . ": " . $nama_peralatan;

        $st = $koneksi->prepare(
            "INSERT INTO order_perbaikan
                (id_pemesan, id_divisi_asal, id_divisi, judul, lokasi, deskripsi, foto, created_at, nama_pelapor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // Akun Departemen tidak punya id_divisi: simpan NULL (bukan 0) agar tidak melanggar foreign key
        $id_asal_db = $id_divisi_asal > 0 ? $id_divisi_asal : null;
        $st->bind_param(
            "iiissssss",
            $id_user, $id_asal_db, $tujuan,
            $judul_order, $detail_lokasi, $deskripsi, $foto_nama, $waktu_db, $nama_pelapor
        );
        $st->execute();

        // Nomor order TIDAK dibuat otomatis lagi.
        // Nomor diisi manual oleh karyawan yang mengerjakan atau Admin Divisi tujuan.

        // Simpan pesan lalu redirect, supaya refresh tidak mengirim order dobel
        $_SESSION["flash"] = "Order berhasil dikirim. Nomor order akan diisi oleh divisi tujuan.";
        header("Location: buat_order.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buat Order - SIMO</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; background: #f4f6f9; margin: 0; }
  .content { padding: 28px 32px; }
  .box { max-width: 700px; margin: 0 auto; background: #fff; padding: 26px 28px 24px;
         border: 1px solid #e6eaf0; border-radius: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }

  .baris { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  label { display: block; font-size: 12.5px; font-weight: 700; margin: 0 0 7px; color: #2b3445; }
  label small { font-weight: 400; color: #8b96a5; }
  .grup { margin-bottom: 20px; }

  input[type=text], input[type=datetime-local], select, textarea {
         width: 100%; padding: 11px 13px; border: 1px solid #dde2ea; border-radius: 9px;
         font-size: 14px; font-family: inherit; background: #fbfaf9; color: #1c2636; }
  input::placeholder, textarea::placeholder { color: #9aa3b0; }
  input:focus, select:focus, textarea:focus { outline: none; border-color: #b51f2a; background: #fff;
         box-shadow: 0 0 0 3px rgba(181,31,42,.10); }
  textarea { min-height: 96px; resize: vertical; }
  input[readonly] { background: #f3f1f0; color: #6b7685; border-style: dashed; cursor: default; }
  input[readonly]:focus { border-color: #dde2ea; box-shadow: none; }

  /* ---------- PILIHAN JENIS (kotak centang) ---------- */
  .pilih-jenis { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .opsi { position: relative; display: flex; align-items: center; gap: 10px; margin: 0; padding: 12px 14px;
          border: 1px solid #dde2ea; border-radius: 9px; background: #fbfaf9; cursor: pointer;
          font-size: 14px; font-weight: 600; color: #1c2636; }
  .opsi:hover { border-color: #b51f2a; }
  .opsi input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
  .opsi .kotak { width: 20px; height: 20px; border: 2px solid #b9c0cb; border-radius: 5px; background: #fff;
                 display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .opsi .kotak svg { width: 13px; height: 13px; stroke: #fff; opacity: 0; }
  .opsi:has(input:checked) { border-color: #b51f2a; background: #fdf3f4; }
  .opsi input:checked + .kotak { background: #b51f2a; border-color: #b51f2a; }
  .opsi input:checked + .kotak svg { opacity: 1; }
  .opsi input:focus-visible + .kotak { box-shadow: 0 0 0 3px rgba(181,31,42,.2); }

  /* ---------- UPLOAD FOTO ---------- */
  .upload { border: 1px dashed #d3d9e2; border-radius: 12px; background: #fbfaf9; }
  .upload-kosong { display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%;
                   padding: 26px 18px; border: 0; background: transparent; cursor: pointer;
                   font-family: inherit; border-radius: 12px; }
  .upload-kosong:hover { background: #fdf3f4; }
  .upload-ikon { width: 46px; height: 46px; border-radius: 50%; background: #fdecee; display: flex;
                 align-items: center; justify-content: center; margin-bottom: 4px; }
  .upload-ikon svg { width: 22px; height: 22px; stroke: #b51f2a; }
  .upload-judul { font-size: 13.5px; font-weight: 700; color: #2b3445; }
  .upload-sub { font-size: 12px; color: #8b96a5; }

  .upload-hasil { display: none; align-items: center; gap: 14px; padding: 14px; }
  .upload-hasil.ada { display: flex; }
  .upload.terisi .upload-kosong { display: none; }
  .upload-hasil img { width: 92px; height: 72px; object-fit: cover; border-radius: 9px; border: 1px solid #e6eaf0; flex-shrink: 0; }
  .upload-info { flex: 1; min-width: 0; }
  .upload-nama { font-size: 13px; font-weight: 700; color: #2b3445; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .upload-ukuran { font-size: 12px; color: #8b96a5; margin-top: 2px; }
  .upload-aksi { display: flex; gap: 8px; margin-top: 8px; }
  .u-btn { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;
           font-family: inherit; border: 1px solid #d5dbe4; background: #fff; color: #344054; }
  .u-btn:hover { border-color: #b51f2a; color: #b51f2a; }
  .u-btn.hapus { border-color: #efc9cc; color: #a32128; }
  .u-btn.hapus:hover { background: #fff1f2; }
  .hint { margin-top: 8px; font-size: 11.5px; color: #6b7685; }

  .aksi { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; padding-top: 20px;
          border-top: 1px solid #eceff3; }
  .btn { padding: 10px 20px; border-radius: 8px; font-size: 13.5px; font-weight: 700; cursor: pointer;
         text-decoration: none; font-family: inherit; }
  .btn-batal { background: #fff; color: #2b3445; border: 1px solid #d3d9e2; }
  .btn-batal:hover { background: #f8f9fb; }
  .btn-kirim { background: #b51f2a; color: #fff; border: 0; }
  .btn-kirim:hover { background: #9f1c26; }

  .pesan { padding: 11px 13px; border-radius: 8px; margin-bottom: 18px; font-size: 13px;
           background: #e8f7ee; color: #1b6b3a; border: 1px solid #bfe5cd; }
  .pesan.error { background: #fff1f2; color: #a32128; border-color: #efc9cc; }

  /* ---------- POP UP PILIH SUMBER FOTO ---------- */
  .sheet-latar { position: fixed; inset: 0; z-index: 200; display: none; align-items: center; justify-content: center;
                 padding: 16px; background: rgba(16, 24, 40, .55); }
  .sheet-latar.buka { display: flex; animation: latar .18s ease; }
  .sheet { width: 100%; max-width: 420px; background: #fff; border-radius: 18px; padding: 22px 20px 14px;
           box-shadow: 0 20px 50px rgba(16, 24, 40, .25); animation: naik .22s ease; }
  .sheet h4 { margin: 0 0 16px; text-align: center; font-size: 14.5px; font-weight: 700; color: #1c2636; }
  .sheet-pilih { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 15px;
                 margin-bottom: 10px; border-radius: 12px; border: 1px solid #e1e6ee; background: #f4f6f9;
                 font-family: inherit; font-size: 15px; font-weight: 700; color: #2b3445; cursor: pointer; }
  .sheet-pilih:hover, .sheet-pilih:active { background: #fdecee; border-color: #efc9cc; color: #b51f2a; }
  .sheet-pilih svg { width: 19px; height: 19px; stroke: currentColor; }
  .sheet-batal { display: block; width: 100%; padding: 14px; margin-top: 4px; border: 0; background: transparent;
                 font-family: inherit; font-size: 14.5px; font-weight: 700; color: #8b96a5; cursor: pointer; }
  .sheet-batal:hover { color: #475467; }
  @keyframes latar { from { opacity: 0; } to { opacity: 1; } }
  @keyframes naik { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }


  /* ---------- KAMERA LAYAR PENUH ---------- */
  .cam { position: fixed; inset: 0; z-index: 300; display: none; background: #000; color: #fff; }
  .cam.buka { display: block; }
  .cam video, .cam img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #000; }
  .cam img { display: none; }
  .cam.hasil img { display: block; }
  .cam.hasil video { display: none; }
  .cam-atas { position: absolute; top: 0; left: 0; right: 0; z-index: 2; display: flex; align-items: center;
              justify-content: space-between; gap: 10px;
              padding: calc(env(safe-area-inset-top, 0px) + 14px) 16px 22px;
              background: linear-gradient(rgba(0,0,0,.55), transparent); }
  .cam-atas span { font-size: 14px; font-weight: 700; }
  .cam-btn { width: 44px; height: 44px; border-radius: 50%; border: 0; cursor: pointer; color: #fff;
             background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; }
  .cam-btn:active { background: rgba(255,255,255,.3); }
  .cam-btn svg { width: 22px; height: 22px; stroke: #fff; }
  .cam-bawah { position: absolute; left: 0; right: 0; bottom: 0; z-index: 2; display: flex; align-items: center;
               justify-content: center; gap: 14px; padding: 26px 16px calc(env(safe-area-inset-bottom, 0px) + 26px);
               background: linear-gradient(transparent, rgba(0,0,0,.6)); }
  .cam-bawah[hidden] { display: none; }
  .cam-jepret { width: 74px; height: 74px; border-radius: 50%; border: 4px solid rgba(255,255,255,.55);
                background: #fff; cursor: pointer; padding: 0; background-clip: content-box; padding: 4px; }
  .cam-jepret:active { transform: scale(.94); }
  .cam-jepret:disabled { opacity: .4; }
  .cam-teks { flex: 1; max-width: 190px; padding: 13px 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,.4);
              background: rgba(255,255,255,.14); color: #fff; font-family: inherit; font-size: 14.5px; font-weight: 700; cursor: pointer; }
  .cam-teks.utama { background: #b51f2a; border-color: #b51f2a; }
  .cam-pesan { position: absolute; z-index: 3; left: 24px; right: 24px; top: 50%; transform: translateY(-50%);
               text-align: center; font-size: 14px; line-height: 1.6; display: none; }
  .cam-pesan.tampil { display: block; }

  /* ---------- DARK MODE ---------- */
  body.dark-mode .box { background: #151b23; border-color: #29313d; box-shadow: none; }
  body.dark-mode label { color: #c3ccd8; }
  body.dark-mode input[type=text], body.dark-mode input[type=datetime-local],
  body.dark-mode select, body.dark-mode textarea { background: #1a212b; border-color: #303947; color: #e8edf4; }
  body.dark-mode select option { background: #151b23; color: #e8edf4; }
  body.dark-mode input[readonly] { background: #171d26; color: #8b96a5; }
  body.dark-mode .upload { background: #1a212b; border-color: #303947; }
  body.dark-mode .upload-kosong:hover { background: #231b20; }
  body.dark-mode .upload-ikon { background: #302126; }
  body.dark-mode .upload-judul, body.dark-mode .upload-nama { color: #e8edf4; }
  body.dark-mode .upload-sub, body.dark-mode .upload-ukuran, body.dark-mode .hint { color: #8b96a5; }
  body.dark-mode .upload-hasil img { border-color: #303947; }
  body.dark-mode .u-btn { background: #202733; border-color: #353e4c; color: #c3ccd8; }
  body.dark-mode .u-btn.hapus { border-color: #4a2a2e; color: #f07a80; }
  body.dark-mode .aksi { border-top-color: #29313d; }
  body.dark-mode .btn-batal { background: #202733; color: #c3ccd8; border-color: #353e4c; }
  body.dark-mode .pesan { background: #1a2b22; color: #8fd9ab; border-color: #284a37; }
  body.dark-mode .pesan.error { background: #2b1a1d; color: #f07a80; border-color: #4a2a2e; }
  body.dark-mode .sheet { background: #151b23; box-shadow: 0 20px 50px rgba(0, 0, 0, .6); }
  body.dark-mode .sheet h4 { color: #edf2f7; }
  body.dark-mode .sheet-pilih { background: #202733; border-color: #353e4c; color: #e8edf4; }
  body.dark-mode .sheet-pilih:hover, body.dark-mode .sheet-pilih:active { background: #302126; border-color: #4a2a2e; color: #f07a80; }

  body.dark-mode .opsi { background: #1a212b; border-color: #303947; color: #e8edf4; }
  body.dark-mode .opsi:hover { border-color: #b51f2a; }
  body.dark-mode .opsi .kotak { background: #0f141b; border-color: #4a5566; }
  body.dark-mode .opsi:has(input:checked) { background: #302126; border-color: #b51f2a; }
  body.dark-mode .opsi input:checked + .kotak { background: #b51f2a; border-color: #b51f2a; }

  @media (max-width: 800px) {
    .content { padding: 18px 14px; }
    .box { padding: 20px; }
    .baris { grid-template-columns: 1fr; gap: 0; }
    /* di HP pop up muncul dari bawah layar */
    .sheet-latar { align-items: flex-end; padding: 10px; }
    .sheet { max-width: none; }
  }
</style>
</head>
<body>

<?php include "../includes/departemen/sidebar.php"; ?>

<div class="main">

  <?php include "../includes/departemen/topbar.php"; ?>

  <div class="content">
    <div class="box">

      <?php if ($pesan != ""): ?>
        <div class="pesan <?= $pesan_error ? 'error' : '' ?>"><?= htmlspecialchars($pesan) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">

        <div class="grup">
          <label>Uraian Kerusakan</label>
          <textarea name="deskripsi" placeholder="Jelaskan kerusakan yang terjadi secara detail..." required><?= htmlspecialchars($deskripsi) ?></textarea>
        </div>

        <div class="grup">
          <label>Jenis Peralatan <small>(centang salah satu)</small></label>
          <div class="pilih-jenis">
            <?php foreach ($daftar_peralatan as $i => $p): ?>
              <label class="opsi">
                <input type="radio" name="jenis_peralatan" value="<?= htmlspecialchars($p) ?>"
                       <?= $jenis_peralatan === $p ? "checked" : "" ?> <?= $i === 0 ? "required" : "" ?>>
                <span class="kotak">
                  <svg viewBox="0 0 24 24" fill="none" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>
                </span>
                <?= htmlspecialchars($p) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="grup">
          <label>Nama Peralatan</label>
          <input type="text" name="nama_peralatan" value="<?= htmlspecialchars($nama_peralatan) ?>"
                 placeholder="mis. Pintu, Pompa, Mobil Box" maxlength="100" required>
        </div>

        <div class="baris">
          <div class="grup">
            <label>Departemen Tujuan <small>(yang mengerjakan)</small></label>
            <select name="id_divisi" required>
              <option value="">Pilih departemen tujuan</option>
              <?php foreach ($daftar_divisi as $id => $nama): ?>
                <option value="<?= $id ?>" <?= $tujuan === $id ? "selected" : "" ?>><?= htmlspecialchars($nama) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grup">
            <label>Departemen Pemesan <small>(departemen Anda)</small></label>
            <input type="text" value="<?= htmlspecialchars(($pelapor["departemen"] ?? "") !== "" ? $pelapor["departemen"] : ($pelapor["nama_divisi"] ?? "-")) ?>" readonly>
          </div>
        </div>

        <div class="grup">
          <label>Detail Lokasi <small>(opsional, tulisan bebas)</small></label>
          <input type="text" name="detail_lokasi" value="<?= htmlspecialchars($detail_lokasi) ?>"
                 placeholder="mis. Lantai 2, dekat gudang B">
        </div>

        <div class="baris">
          <div class="grup">
            <label>Nama Pelapor</label>
            <input type="text" name="nama_pelapor" value="<?= htmlspecialchars($nama_pelapor) ?>"
                   placeholder="Ketik nama pelapor" maxlength="100" required>
          </div>
          <div class="grup">
            <label>Tanggal &amp; Jam Dibuat <small>(otomatis, bisa diubah)</small></label>
            <input type="datetime-local" name="waktu" value="<?= htmlspecialchars($waktu_input) ?>"
                   max="<?= date("Y-m-d\TH:i") ?>" required>
          </div>
        </div>

        <!-- FOTO KERUSAKAN -->
        <div class="grup">
          <label>Foto Kerusakan <small>(opsional)</small></label>

          <!-- input utama yang ikut terkirim ke server -->
          <input type="file" name="foto" id="foto" accept="image/jpeg,image/png,image/webp" hidden>

          <div class="upload" id="upload">
            <button type="button" class="upload-kosong" id="btnBukaSheet">
              <span class="upload-ikon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
              </span>
              <span class="upload-judul">Unggah foto kerusakan</span>
              <span class="upload-sub">Ketuk untuk ambil dari kamera atau pilih dari galeri</span>
            </button>

            <div class="upload-hasil" id="hasil">
              <img id="preview" alt="Pratinjau foto">
              <div class="upload-info">
                <div class="upload-nama" id="namaFoto"></div>
                <div class="upload-ukuran" id="ukuranFoto"></div>
                <div class="upload-aksi">
                  <button type="button" class="u-btn" id="btnGanti">Ganti foto</button>
                  <button type="button" class="u-btn hapus" id="btnHapus">Hapus</button>
                </div>
              </div>
            </div>
          </div>
          <div class="hint" id="hint">Format JPG/PNG/WEBP, maksimal 8 MB. Foto otomatis dikompres ke sekitar 300KB agar hemat penyimpanan.</div>

          <!-- input sementara untuk kamera & galeri (tidak ikut terkirim) -->
          <input type="file" id="inputKamera" accept="image/*" capture="environment" hidden>
          <input type="file" id="inputGaleri" accept="image/jpeg,image/png,image/webp" hidden>
        </div>

        <div class="aksi">
          <a href="dashboard.php" class="btn btn-batal">Batal</a>
          <button type="submit" class="btn btn-kirim">Kirim Order</button>
        </div>

      </form>
    </div>
  </div>

</div>

<!-- POP UP PILIH SUMBER FOTO -->
<div class="sheet-latar" id="sheet">
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="sheetJudul">
    <h4 id="sheetJudul">Tambah Foto Kerusakan</h4>

    <button type="button" class="sheet-pilih" id="pilihKamera">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
      Ambil dari Kamera
    </button>

    <button type="button" class="sheet-pilih" id="pilihGaleri">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
      Pilih dari Galeri
    </button>

    <button type="button" class="sheet-batal" id="sheetBatal">Batal</button>
  </div>
</div>

<!-- KAMERA LAYAR PENUH -->
<div class="cam" id="cam">
  <video id="camVideo" autoplay playsinline muted></video>
  <img id="camHasil" alt="Hasil foto">

  <div class="cam-atas">
    <button type="button" class="cam-btn" id="camTutup" aria-label="Tutup kamera">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg>
    </button>
    <span>Foto Kerusakan</span>
    <button type="button" class="cam-btn" id="camGanti" aria-label="Ganti kamera depan atau belakang">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-3l-2-2H9L7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1z"></path><path d="M9 13a3 3 0 0 1 5-2l1 1"></path><path d="M15 15a3 3 0 0 1-5 2l-1-1"></path></svg>
    </button>
  </div>

  <div class="cam-pesan" id="camPesan"></div>

  <div class="cam-bawah" id="camBawahLive">
    <button type="button" class="cam-jepret" id="camJepret" aria-label="Ambil foto"></button>
  </div>
  <div class="cam-bawah" id="camBawahHasil" hidden>
    <button type="button" class="cam-teks" id="camUlang">Ulangi</button>
    <button type="button" class="cam-teks utama" id="camPakai">Gunakan Foto</button>
  </div>
</div>

<script>
/* ---------- TANGGAL & JAM OTOMATIS ---------- */
(function () {
    var input = document.querySelector('input[name="waktu"]');
    if (!input) { return; }

    // Kalau halaman ini hasil kirim yang error, pertahankan nilai yang sudah diisi
    var diubah = <?= $_SERVER["REQUEST_METHOD"] == "POST" ? "true" : "false" ?>;

    function dua(n) { return (n < 10 ? "0" : "") + n; }
    function format(d) {
        return d.getFullYear() + "-" + dua(d.getMonth() + 1) + "-" + dua(d.getDate()) +
               "T" + dua(d.getHours()) + ":" + dua(d.getMinutes());
    }
    function perbarui() {
        var skrg = format(new Date());
        input.max = skrg;
        if (!diubah) { input.value = skrg; }
    }

    // Kalau diubah manual, berhenti mengikuti jam otomatis
    input.addEventListener("input", function () { diubah = true; });
    input.addEventListener("change", function () { diubah = true; });

    perbarui();
    setInterval(perbarui, 15000);

    // Saat Kirim: kalau tidak diubah, pakai jam saat tombol ditekan
    input.form.addEventListener("submit", function () {
        if (!diubah) { input.value = format(new Date()); }
    });
})();
</script>

<script>
(function () {
    var MAKS = 8 * 1024 * 1024;

    var foto      = document.getElementById("foto");
    var kamera    = document.getElementById("inputKamera");
    var galeri    = document.getElementById("inputGaleri");
    var sheet     = document.getElementById("sheet");
    var upload    = document.getElementById("upload");
    var hasil     = document.getElementById("hasil");
    var preview   = document.getElementById("preview");
    var hint      = document.getElementById("hint");
    var hintAwal  = hint.textContent;
    var urlPreview = null;

    function bukaSheet()  { sheet.classList.add("buka"); }
    function tutupSheet() { sheet.classList.remove("buka"); }

    function formatUkuran(b) {
        return b >= 1048576 ? (b / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(b / 1024)) + " KB";
    }

    function hapusFoto() {
        foto.value = "";
        if (urlPreview) { URL.revokeObjectURL(urlPreview); urlPreview = null; }
        preview.removeAttribute("src");
        hasil.classList.remove("ada");
        upload.classList.remove("terisi");
        hint.textContent = hintAwal;
        hint.style.color = "";
    }

    function pakaiFoto(file) {
        if (!file) { return; }

        if (file.size > MAKS) {
            hint.textContent = "Ukuran foto maksimal 8 MB. Pilih atau ambil foto lain.";
            hint.style.color = "#a32128";
            return;
        }

        // pindahkan file ke input utama (yang ikut terkirim ke server)
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            foto.files = dt.files;
        } catch (e) {
            hint.textContent = "Browser ini belum mendukung pilihan foto dari pop up. Gunakan browser yang lebih baru.";
            hint.style.color = "#a32128";
            return;
        }

        if (urlPreview) { URL.revokeObjectURL(urlPreview); }
        urlPreview = URL.createObjectURL(file);
        preview.src = urlPreview;

        document.getElementById("namaFoto").textContent = file.name;
        document.getElementById("ukuranFoto").textContent = formatUkuran(file.size);
        hasil.classList.add("ada");
        upload.classList.add("terisi");
        hint.textContent = hintAwal;
        hint.style.color = "";
    }

    document.getElementById("btnBukaSheet").addEventListener("click", bukaSheet);
    document.getElementById("btnGanti").addEventListener("click", bukaSheet);
    document.getElementById("btnHapus").addEventListener("click", hapusFoto);
    document.getElementById("sheetBatal").addEventListener("click", tutupSheet);
    sheet.addEventListener("click", function (e) { if (e.target === sheet) { tutupSheet(); } });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { tutupSheet(); if (cam && cam.classList.contains("buka")) { tutupKamera(); } }
    });

    /* ---------- KAMERA LAYAR PENUH (kamera belakang jadi default) ---------- */
    var cam       = document.getElementById("cam");
    var camVideo  = document.getElementById("camVideo");
    var camHasil  = document.getElementById("camHasil");
    var camPesan  = document.getElementById("camPesan");
    var camJepret = document.getElementById("camJepret");
    var camGanti  = document.getElementById("camGanti");
    var bawahLive  = document.getElementById("camBawahLive");
    var bawahHasil = document.getElementById("camBawahHasil");
    var stream = null;
    var arah = "environment";      // environment = belakang, user = depan
    var fotoTangkap = null;
    var urlTangkap = null;

    function kameraDidukung() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext);
    }

    function hentikanStream() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        camVideo.srcObject = null;
    }

    function tampilPesan(teks) {
        camPesan.textContent = teks || "";
        camPesan.classList.toggle("tampil", !!teks);
    }

    function modeLive() {
        cam.classList.remove("hasil");
        bawahLive.hidden = false;
        bawahHasil.hidden = true;
    }

    function modeHasil() {
        cam.classList.add("hasil");
        bawahLive.hidden = true;
        bawahHasil.hidden = false;
    }

    function mulaiKamera() {
        hentikanStream();
        tampilPesan("");
        camJepret.disabled = true;

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: arah }, width: { ideal: 1920 }, height: { ideal: 1080 } },
            audio: false
        }).then(function (s) {
            stream = s;
            camVideo.srcObject = s;
            camVideo.style.transform = (arah === "user") ? "scaleX(-1)" : "";
            camJepret.disabled = false;
            var p = camVideo.play();
            if (p && p.catch) { p.catch(function () {}); }

            // tombol ganti kamera hanya muncul kalau perangkat punya lebih dari 1 kamera
            return navigator.mediaDevices.enumerateDevices().then(function (list) {
                var jumlah = list.filter(function (d) { return d.kind === "videoinput"; }).length;
                camGanti.style.visibility = jumlah > 1 ? "visible" : "hidden";
            });
        }).catch(function (err) {
            camJepret.disabled = true;
            if (err && (err.name === "NotAllowedError" || err.name === "SecurityError")) {
                tampilPesan("Izin kamera ditolak. Aktifkan izin kamera untuk situs ini di pengaturan browser, lalu coba lagi.");
            } else if (err && err.name === "NotFoundError") {
                tampilPesan("Kamera tidak ditemukan di perangkat ini. Gunakan Pilih dari Galeri.");
            } else {
                tampilPesan("Kamera tidak bisa dibuka. Pastikan tidak sedang dipakai aplikasi lain.");
            }
        });
    }

    function bukaKamera() {
        arah = "environment";
        fotoTangkap = null;
        modeLive();
        cam.classList.add("buka");
        document.body.style.overflow = "hidden";
        mulaiKamera();
    }

    function tutupKamera() {
        hentikanStream();
        cam.classList.remove("buka");
        document.body.style.overflow = "";
        if (urlTangkap) { URL.revokeObjectURL(urlTangkap); urlTangkap = null; }
    }

    document.getElementById("pilihKamera").addEventListener("click", function () {
        tutupSheet();
        if (kameraDidukung()) {
            bukaKamera();
        } else {
            // cadangan: buka aplikasi kamera bawaan HP (perlu HTTPS untuk kamera dalam halaman)
            kamera.click();
        }
    });

    document.getElementById("camTutup").addEventListener("click", tutupKamera);

    camGanti.addEventListener("click", function () {
        arah = (arah === "environment") ? "user" : "environment";
        mulaiKamera();
    });

    camJepret.addEventListener("click", function () {
        var w = camVideo.videoWidth, h = camVideo.videoHeight;
        if (!w || !h) { return; }
        var kanvas = document.createElement("canvas");
        kanvas.width = w;
        kanvas.height = h;
        kanvas.getContext("2d").drawImage(camVideo, 0, 0, w, h);
        kanvas.toBlob(function (blob) {
            if (!blob) { tampilPesan("Gagal mengambil foto. Coba lagi."); return; }
            fotoTangkap = new File([blob], "kamera_" + Date.now() + ".jpg", { type: "image/jpeg" });
            if (urlTangkap) { URL.revokeObjectURL(urlTangkap); }
            urlTangkap = URL.createObjectURL(blob);
            camHasil.src = urlTangkap;
            hentikanStream();
            modeHasil();
        }, "image/jpeg", 0.92);
    });

    document.getElementById("camUlang").addEventListener("click", function () {
        fotoTangkap = null;
        modeLive();
        mulaiKamera();
    });

    document.getElementById("camPakai").addEventListener("click", function () {
        if (fotoTangkap) { pakaiFoto(fotoTangkap); }
        tutupKamera();
    });

    /* Galeri: membuka pemilih foto di perangkat */
    document.getElementById("pilihGaleri").addEventListener("click", function () {
        tutupSheet();
        galeri.click();
    });

    [kamera, galeri].forEach(function (input) {
        input.addEventListener("change", function () {
            pakaiFoto(input.files && input.files[0]);
            input.value = "";   // supaya memilih foto yang sama lagi tetap terbaca
        });
    });
})();
</script>

</body>
</html>