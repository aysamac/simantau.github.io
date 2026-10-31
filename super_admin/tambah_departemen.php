<?php
include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "Super Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
$mode_edit = $id > 0;
$error = "";
$nama = "";
$username = "";
$departemen = "";

// Mode edit: ambil data lama
if ($mode_edit) {
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT nama, username, departemen FROM users
         WHERE id_user = ? AND role = 'Departemen'
         AND COALESCE(TRIM(status), '') <> 'Dihapus'"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$data) {
        header("Location: pengguna.php?tab=departemen");
        exit;
    }

    $nama = $data["nama"];
    $username = $data["username"];
    $departemen = $data["departemen"] ?? "";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nama = trim($_POST["nama"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $departemen = trim($_POST["departemen"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($nama === "" || $username === "" || $departemen === "") {
        $error = "Nama, username, dan departemen wajib diisi.";
    } elseif (!$mode_edit && strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    } elseif ($mode_edit && $password !== "" && strlen($password) < 6) {
        $error = "Password baru minimal 6 karakter.";
    } else {
        // Cek username kembar (kecuali milik akun ini sendiri)
        $cek = mysqli_prepare(
            $koneksi,
            "SELECT id_user FROM users WHERE username = ? AND id_user <> ? LIMIT 1"
        );
        mysqli_stmt_bind_param($cek, "si", $username, $id);
        mysqli_stmt_execute($cek);
        mysqli_stmt_store_result($cek);
        $kembar = mysqli_stmt_num_rows($cek) > 0;
        mysqli_stmt_close($cek);

        if ($kembar) {
            $error = "Username sudah dipakai, gunakan username lain.";
        } else {
            if ($mode_edit) {
                if ($password !== "") {
                    $hash = $password; // login SIMO membandingkan teks biasa, sama seperti tambah_admin
                    $st = mysqli_prepare(
                        $koneksi,
                        "UPDATE users SET nama = ?, username = ?, departemen = ?, password = ?
                         WHERE id_user = ? AND role = 'Departemen'"
                    );
                    mysqli_stmt_bind_param($st, "ssssi", $nama, $username, $departemen, $hash, $id);
                } else {
                    $st = mysqli_prepare(
                        $koneksi,
                        "UPDATE users SET nama = ?, username = ?, departemen = ?
                         WHERE id_user = ? AND role = 'Departemen'"
                    );
                    mysqli_stmt_bind_param($st, "sssi", $nama, $username, $departemen, $id);
                }
                $kode = "departemen_diubah";
            } else {
                $hash = $password; // login SIMO membandingkan teks biasa, sama seperti tambah_admin
                $st = mysqli_prepare(
                    $koneksi,
                    "INSERT INTO users (nama, username, departemen, password, role, status)
                     VALUES (?, ?, ?, ?, 'Departemen', 'Aktif')"
                );
                mysqli_stmt_bind_param($st, "ssss", $nama, $username, $departemen, $hash);
                $kode = "departemen_berhasil";
            }

            try {
                if ($st && mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header("Location: pengguna.php?tab=departemen&pesan=" . $kode);
                    exit;
                }
                $error = "Gagal menyimpan data: " . mysqli_error($koneksi);
            } catch (mysqli_sql_exception $e) {
                $error = "Gagal menyimpan data: " . $e->getMessage();
            }
        }
    }
}

$judul = $mode_edit ? "Edit Akun Departemen" : "Tambah Akun Departemen";
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $judul; ?> - SIMO</title>

    <style>
    * { margin:0; padding:0; box-sizing:border-box; }

    body { font-family:"Segoe UI", Arial, sans-serif; background:#fff; color:#253044; }

    a { text-decoration:none; color:inherit; }

    .main { min-height:100vh; margin-left:240px; width:calc(100% - 240px); min-width:0; background:#fff; }

    .content { padding:30px 34px 40px; }

    .page-title { margin-bottom:24px; }
    .page-title h1 { font-size:25px; font-weight:700; color:#182230; letter-spacing:-.3px; margin-bottom:6px; }
    .page-title p { font-size:13px; color:#8a95a5; }

    .form-card {
        max-width:560px;
        background:#fff;
        border:1px solid #d9dde2;
        border-radius:14px;
        padding:26px;
        box-shadow:0 1px 3px rgba(16,24,40,.04);
    }

    .form-group { margin-bottom:18px; }
    .form-group label { display:block; margin-bottom:6px; font-size:12px; font-weight:600; color:#667085; }
    .form-group input {
        width:100%; height:41px; padding:0 12px;
        border:1px solid #d5dbe2; border-radius:8px;
        font-family:inherit; font-size:13px; color:#344054; background:#fff; outline:none;
    }
    .form-group input:focus { border-color:#b52a31; box-shadow:0 0 0 3px rgba(181,42,49,.08); }
    .form-hint { margin-top:5px; font-size:11px; color:#8b96a5; }

    .form-actions { display:flex; gap:10px; margin-top:24px; }
    .btn-simpan, .btn-batal {
        display:inline-flex; align-items:center; justify-content:center;
        height:40px; padding:0 20px; border-radius:8px;
        font-family:inherit; font-size:12px; font-weight:700; cursor:pointer;
    }
    .btn-simpan { border:0; background:#b52a31; color:#fff; }
    .btn-simpan:hover { background:#9d232a; }
    .btn-batal { border:1px solid #d5dbe2; background:#fff; color:#667085; }
    .btn-batal:hover { background:#f8f9fa; }

    .alert-msg { padding:12px 15px; border-radius:9px; margin-bottom:18px; font-size:13px; font-weight:500; max-width:560px; }
    .alert-error { background:#fff2f3; border:1px solid #efd4d7; color:#b51f2a; }

    /* Dark mode */
    html:has(body.dark-mode), html:has(body.dark-mode) body { background:#0f141b !important; }
    html body.dark-mode { background:#0f141b !important; color:#e8edf4 !important; }
    html body.dark-mode .main, html body.dark-mode .content { background:#0f141b !important; }
    html body.dark-mode .page-title h1 { color:#edf2f7 !important; }
    html body.dark-mode .page-title p, html body.dark-mode .form-hint { color:#8f9bac !important; }
    html body.dark-mode .form-card { background:#151b23 !important; border-color:#2c3542 !important; box-shadow:none !important; }
    html body.dark-mode .form-group label { color:#cbd2dc !important; }
    html body.dark-mode .form-group input { background:#151b23 !important; border-color:#2c3542 !important; color:#edf2f7 !important; }
    html body.dark-mode .btn-batal { background:#202733 !important; border-color:#2c3542 !important; color:#cbd2dc !important; }
    html body.dark-mode .alert-error { background:#302326 !important; border-color:#493338 !important; color:#f08a90 !important; }

    @media (max-width:800px) {
        .main { margin-left:0; width:100%; }
        .content { padding:20px; }
    }
    </style>
</head>

<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <div class="page-title">
                <h1><?= $judul; ?></h1>
                <p>
                    <?= $mode_edit
                        ? "Perbarui data akun Departemen."
                        : "Buat akun baru untuk Departemen."; ?>
                </p>
            </div>

            <?php if ($error !== ""): ?>
                <div class="alert-msg alert-error"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="form-card" autocomplete="off">

                <div class="form-group">
                    <label for="nama">Nama</label>
                    <input type="text" id="nama" name="nama" value="<?= htmlspecialchars($nama); ?>" required>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($username); ?>" required>
                </div>

                <div class="form-group">
                    <label for="departemen">Departemen</label>
                    <input type="text" id="departemen" name="departemen" value="<?= htmlspecialchars($departemen); ?>" placeholder="Contoh: PMD, QC" required>
                    <div class="form-hint">Ketik nama departemen secara manual.</div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        <?= $mode_edit ? "" : "required"; ?>
                        autocomplete="new-password"
                    >
                    <?php if ($mode_edit): ?>
                        <div class="form-hint">Kosongkan jika tidak ingin mengubah password.</div>
                    <?php else: ?>
                        <div class="form-hint">Minimal 6 karakter.</div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-simpan">Simpan</button>
                    <a href="pengguna.php?tab=departemen" class="btn-batal">Batal</a>
                </div>

            </form>

        </div>

    </div>

</body>

</html>