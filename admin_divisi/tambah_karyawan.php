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
$id_divisi = $admin["id_divisi"];
$nama_admin = $admin["nama"];

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT * FROM divisi WHERE id_divisi='$id_divisi'"
);

$divisi = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"];

$pesan = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nama = mysqli_real_escape_string($koneksi, $_POST["nama"]);
    $no_hp = mysqli_real_escape_string($koneksi, $_POST["no_hp"]);
    $username = mysqli_real_escape_string($koneksi, $_POST["username"]);
    $password_asli = $_POST["password"];
    $password = mysqli_real_escape_string($koneksi, $password_asli);

    if (mb_strlen($password_asli, "UTF-8") < 6) {
        $pesan = "Password minimal 6 karakter.";
    } else {
        $cek_username = mysqli_query(
            $koneksi,
            "SELECT id_user FROM users WHERE username='$username'"
        );

        if (mysqli_num_rows($cek_username) > 0) {
            $pesan = "Username sudah digunakan.";
        } else {
            $query_tambah = mysqli_query(
                $koneksi,
                "INSERT INTO users
                (nama, no_hp, username, password, role, id_divisi, status)
                VALUES
                ('$nama', '$no_hp', '$username', '$password', 'Karyawan', '$id_divisi', 'Aktif')"
            );

            if ($query_tambah) {
                header("Location: karyawan.php");
                exit;
            } else {
                $pesan = "Data karyawan gagal ditambahkan.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tambah Karyawan - SIMO</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
body{background:#fff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.main{margin-left:240px;width:calc(100% - 240px);min-width:0}
.content{padding:26px 32px 40px}

/* JUDUL */
.page-title{margin-bottom:20px}
.page-title h2{font-size:22px;font-weight:700;color:#1c2636;margin-bottom:5px}
.page-title p{font-size:13px;color:#667488}

/* CARD */
.card{background:#fff;border:1px solid #d9dde2;border-radius:13px;padding:24px;box-shadow:0 2px 7px rgba(20,40,70,.03);max-width:850px}
.card-title{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.card-title-icon{width:34px;height:34px;border-radius:50%;background:#f7e3e5;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.card-title-icon svg{width:16px;height:16px;fill:none;stroke:#b51f2a;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.card-title h3{font-size:15px;font-weight:700;color:#1c2636}

/* FORM */
.form-group{margin-bottom:17px}
.form-group label{display:block;font-size:12px;font-weight:600;color:#526176;margin-bottom:8px}
.form-group input{width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;font-size:13px;color:#405067;outline:none;background:#fff;transition:.2s}
.form-group input::placeholder{color:#9aa5b3}
.form-group input:focus{border-color:#b51f2a;box-shadow:0 0 0 3px rgba(181,31,42,.07)}
.form-group input[readonly]{background:#f4f6f9;color:#667488;border-color:#e1e6ec;cursor:not-allowed}
.form-group small{display:block;margin-top:6px;font-size:11px;color:#8b96a5}

.pesan{background:#fde2e2;color:#d94b4b;padding:11px 13px;border-radius:9px;font-size:12.5px;margin-bottom:18px}

.form-actions{display:flex;gap:10px;margin-top:22px}
.btn-simpan{border:1px solid #b51f2a;background:#b51f2a;color:#fff;padding:10px 18px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-simpan:hover{background:#941923;border-color:#941923}
.btn-batal{display:inline-flex;align-items:center;border:1px solid #dde3ea;background:#f7f8fa;color:#697587;padding:10px 18px;border-radius:9px;font-size:12px;font-weight:700}
.btn-batal:hover{background:#eef1f4}

.footer-note{text-align:center;font-size:12px;color:#a3adba;padding:26px 0 6px}

/* =========================================================
   DARK MODE (palet sama dengan pekerjaan, monitoring, karyawan)
========================================================= */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,html body.dark-mode .content{background:#0f141b!important}
html body.dark-mode .page-title h2,
html body.dark-mode .card-title h3{color:#edf2f7!important}
html body.dark-mode .page-title p{color:#8f9bac!important}
html body.dark-mode .card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .card-title-icon{background:#382326!important}
html body.dark-mode .card-title-icon svg{stroke:#f07a80!important}
html body.dark-mode .form-group label{color:#edf2f7!important}
html body.dark-mode .form-group input{background:#202733!important;border-color:#303947!important;color:#e8edf4!important;box-shadow:none!important}
html body.dark-mode .form-group input::placeholder{color:#7f8998!important}
html body.dark-mode .form-group input:focus{border-color:#b51f2a!important;box-shadow:0 0 0 3px rgba(181,31,42,.18)!important}
html body.dark-mode .form-group input[readonly]{background:#1a2029!important;border-color:#2c3542!important;color:#8f9bac!important}
html body.dark-mode .form-group small{color:#8f9bac!important}
html body.dark-mode .pesan{background:#4b2d32!important;color:#ff8b92!important}
html body.dark-mode .btn-batal{background:#202733!important;border-color:#303947!important;color:#cbd2dc!important}
html body.dark-mode .btn-batal:hover{background:#2a3342!important}
html body.dark-mode .footer-note{color:#747e8c!important}
</style>
</head>
<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="content">

    <div class="page-title">
        <h2>Tambah Karyawan</h2>
        <p>Tambahkan karyawan baru pada divisi <?= htmlspecialchars($nama_divisi); ?>.</p>
    </div>

    <div class="card">

        <div class="card-title">
            <div class="card-title-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
            </div>
            <h3>Data Karyawan</h3>
        </div>

        <?php if ($pesan != ""): ?>
            <div class="pesan"><?= htmlspecialchars($pesan); ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label>Nama Karyawan</label>
                <input type="text" name="nama" placeholder="Masukkan nama karyawan" required>
            </div>

            <div class="form-group">
                <label>No. HP</label>
                <input type="text" name="no_hp" placeholder="Masukkan nomor HP">
            </div>

            <div class="form-group">
                <label>Divisi</label>
                <input type="text" value="<?= htmlspecialchars($nama_divisi); ?>" readonly>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Masukkan username" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Masukkan password" minlength="6" required>
                <small>Minimal 6 karakter. Password digunakan karyawan untuk login.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-simpan">Simpan</button>
                <a href="karyawan.php" class="btn-batal">Batal</a>
            </div>

        </form>

    </div>

    <div class="footer-note">
        &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
    </div>

</div>

</div>

</body>
</html>