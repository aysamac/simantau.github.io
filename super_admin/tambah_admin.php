<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Super Admin") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$pesan = "";
$tipe_pesan = "";

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT id_divisi, nama_divisi
     FROM divisi
     ORDER BY id_divisi ASC"
);

if (isset($_POST["simpan"])) {

    $nama      = trim($_POST["nama"] ?? "");
    $username  = trim($_POST["username"] ?? "");
    $no_hp     = trim($_POST["no_hp"] ?? "");
    $password  = trim($_POST["password"] ?? "");
    $id_divisi = (int) ($_POST["id_divisi"] ?? 0);

    $status = "Aktif";
    $role = "Admin";

    if (
        $nama == "" ||
        $username == "" ||
        $no_hp == "" ||
        $password == "" ||
        $id_divisi <= 0
    ) {

        $pesan = "Semua data wajib diisi.";
        $tipe_pesan = "error";

    } else {

        $cek_username = mysqli_prepare(
            $koneksi,
            "SELECT id_user
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($cek_username, "s", $username);
        mysqli_stmt_execute($cek_username);
        mysqli_stmt_store_result($cek_username);

        if (mysqli_stmt_num_rows($cek_username) > 0) {

            $pesan = "Username sudah digunakan. Silakan gunakan username lain.";
            $tipe_pesan = "error";

        } else {

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO users
                (nama, username, no_hp, password, role, status, id_divisi)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssi",
                $nama,
                $username,
                $no_hp,
                $password,
                $role,
                $status,
                $id_divisi
            );

            if (mysqli_stmt_execute($stmt)) {

                mysqli_stmt_close($stmt);
                mysqli_stmt_close($cek_username);

                header("Location: pengguna.php?tab=admin&pesan=admin_berhasil");
                exit;

            } else {

                $pesan = "Admin gagal ditambahkan: " . mysqli_error($koneksi);
                $tipe_pesan = "error";

                mysqli_stmt_close($stmt);

            }
        }

        mysqli_stmt_close($cek_username);
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Admin - SIMO</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #222;
        }

        .main {
            margin-left: 280px;
            width: calc(100% - 280px);
            min-height: 100vh;
            background: #ffffff;
        }

        .main > .topbar {
            width: 100%;
            height: 72px !important;
            min-height: 72px !important;
            padding: 0 32px !important;
            box-sizing: border-box;
        }

        .content {
            padding: 26px 32px 40px;
        }

        .page-title {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 700;
            color: #202124;
        }

        .breadcrumb {
            font-size: 13px;
            color: #777;
            margin-bottom: 24px;
        }

        .breadcrumb a {
            color: #777;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #b51f2a;
        }

        .alert {
            max-width: 850px;
            padding: 12px 15px;
            border-radius: 7px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .alert-error {
            background: #fff1f2;
            border: 1px solid #f3c5ca;
            color: #a61b27;
        }

        .form-card {
            max-width: 850px;
            background: #ffffff;
            border: 1px solid #e5e8ec;
            border-radius: 12px;
            padding: 26px 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }

        .form-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf0f3;
        }

        .form-header h2 {
            margin: 0 0 5px;
            font-size: 17px;
            font-weight: 600;
            color: #222;
        }

        .form-header p {
            margin: 0;
            font-size: 13px;
            color: #777;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 7px;
        }

        .required {
            color: #b51f2a;
        }

        .form-control {
            width: 100%;
            height: 42px;
            padding: 0 13px;
            border: 1px solid #d9dde3;
            border-radius: 7px;
            background: #fff;
            color: #333;
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: 0.2s;
        }

        .form-control:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, 0.08);
        }

        select.form-control {
            cursor: pointer;
        }

        .hint {
            margin-top: 5px;
            font-size: 11px;
            color: #888;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .role-input {
            background: #f5f6f8;
            color: #777;
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid #edf0f3;
        }

        .btn {
            height: 40px;
            padding: 0 18px;
            border-radius: 7px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-cancel {
            background: #f1f3f5;
            color: #555;
        }

        .btn-cancel:hover {
            background: #e5e7ea;
        }

        .btn-save {
            background: #b51f2a;
            color: #fff;
        }

        .btn-save:hover {
            background: #971923;
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 20px;
            }

            .form-card {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }

        /* =====================================================
           DARK MODE (palet sama dengan Dashboard)
        ===================================================== */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body { background: #0f141b !important; }

        html body.dark-mode { background: #0f141b !important; color: #e8edf4 !important; }

        html body.dark-mode .main,
        html body.dark-mode .content { background: #0f141b !important; }

        html body.dark-mode .page-title,
        html body.dark-mode .form-header h2,
        html body.dark-mode .form-group label { color: #edf2f7 !important; }

        html body.dark-mode .breadcrumb,
        html body.dark-mode .breadcrumb a,
        html body.dark-mode .form-header p,
        html body.dark-mode .hint { color: #8f9bac !important; }

        html body.dark-mode .breadcrumb a:hover { color: #f07a80 !important; }

        html body.dark-mode .required { color: #f07a80 !important; }

        html body.dark-mode .form-card {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            box-shadow: none !important;
        }

        html body.dark-mode .form-header,
        html body.dark-mode .form-actions { border-color: #2c3542 !important; }

        html body.dark-mode .form-control {
            background: #0f141b !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
            color-scheme: dark !important;
        }

        html body.dark-mode .form-control::placeholder { color: #6f7a8a !important; }

        html body.dark-mode .form-control:focus {
            border-color: #b51f2a !important;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, .16) !important;
        }

        html body.dark-mode select.form-control option {
            background: #151b23 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .role-input {
            background: #151b23 !important;
            color: #8f9bac !important;
        }

        html body.dark-mode .btn-cancel { background: #202733 !important; color: #cbd2dc !important; }
        html body.dark-mode .btn-cancel:hover { background: #2a3342 !important; }

        html body.dark-mode .btn-save { background: #b51f2a !important; color: #fff !important; }
        html body.dark-mode .btn-save:hover { background: #d12b36 !important; }

        html body.dark-mode .alert-error {
            background: #302326 !important;
            border-color: #493338 !important;
            color: #f08a90 !important;
        }

    </style>

</head>

<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <h1 class="page-title">
                Tambah Admin
            </h1>

            <div class="breadcrumb">
                <a href="pengguna.php?tab=admin">Pengguna</a>
                <span>/ Tambah Admin</span>
            </div>

            <?php if ($pesan != "") { ?>

                <div class="alert alert-error">
                    <?= htmlspecialchars($pesan); ?>
                </div>

            <?php } ?>

            <div class="form-card">

                <div class="form-header">
                    <h2>Data Admin</h2>
                    <p>Tambahkan akun admin baru ke dalam sistem.</p>
                </div>

                <form method="POST" action="">

                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input
                            type="text"
                            name="nama"
                            class="form-control"
                            placeholder="Masukkan nama lengkap"
                            value="<?= isset($_POST["nama"]) ? htmlspecialchars($_POST["nama"]) : ''; ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Username</label>
                        <input
                            type="text"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= isset($_POST["username"]) ? htmlspecialchars($_POST["username"]) : ''; ?>"
                            required
                        >
                        <div class="hint">
                            Username digunakan untuk login ke dalam sistem.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>No. HP</label>
                        <input
                            type="tel"
                            name="no_hp"
                            class="form-control"
                            placeholder="Contoh: 081234567890"
                            value="<?= isset($_POST["no_hp"]) ? htmlspecialchars($_POST["no_hp"]) : ''; ?>"
                            maxlength="20"
                            required
                        >
                        <div class="hint">
                            Masukkan nomor HP yang masih aktif.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            required
                        >
                    </div>

                    <div class="form-row">

                        <div class="form-group">

                            <label>Departemen Utama</label>

                            <select name="id_divisi" class="form-control" required>

                                <option value="">-- Pilih Departemen --</option>

                                <?php
                                if ($query_divisi && mysqli_num_rows($query_divisi) > 0) {

                                    while ($divisi = mysqli_fetch_assoc($query_divisi)) {

                                        $selected = "";

                                        if (
                                            isset($_POST["id_divisi"]) &&
                                            $_POST["id_divisi"] == $divisi["id_divisi"]
                                        ) {
                                            $selected = "selected";
                                        }
                                ?>

                                    <option
                                        value="<?= $divisi["id_divisi"]; ?>"
                                        <?= $selected; ?>
                                    >
                                        <?= htmlspecialchars($divisi["nama_divisi"]); ?>
                                    </option>

                                <?php
                                    }
                                }
                                ?>

                            </select>

                        </div>

                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <input
                            type="text"
                            class="form-control role-input"
                            value="Admin"
                            readonly
                        >
                        <div class="hint">
                            Role otomatis ditetapkan sebagai Admin.
                        </div>
                    </div>

                    <div class="form-actions">

                        <a href="pengguna.php?tab=admin" class="btn btn-cancel">
                            Batal
                        </a>

                        <button type="submit" name="simpan" class="btn btn-save">
                            Simpan Admin
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</body>

</html>