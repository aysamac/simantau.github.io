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

$pesan = "";
$tipe_pesan = "";

if (isset($_POST["simpan"])) {

    $nama_divisi = trim($_POST["nama_divisi"] ?? "");

    if ($nama_divisi == "") {

        $pesan = "Nama divisi wajib diisi.";
        $tipe_pesan = "error";

    } else {

        $cek = mysqli_prepare(
            $koneksi,
            "SELECT id_divisi FROM divisi WHERE nama_divisi = ?"
        );

        mysqli_stmt_bind_param($cek, "s", $nama_divisi);
        mysqli_stmt_execute($cek);

        $hasil_cek = mysqli_stmt_get_result($cek);

        if (mysqli_num_rows($hasil_cek) > 0) {

            $pesan = "Nama divisi tersebut sudah ada.";
            $tipe_pesan = "error";

        } else {

            $insert = mysqli_prepare(
                $koneksi,
                "INSERT INTO divisi (nama_divisi) VALUES (?)"
            );

            mysqli_stmt_bind_param($insert, "s", $nama_divisi);

            if (mysqli_stmt_execute($insert)) {
                header("Location: divisi.php");
                exit;
            } else {
                $pesan = "Data divisi gagal disimpan.";
                $tipe_pesan = "error";
            }

            mysqli_stmt_close($insert);
        }

        mysqli_stmt_close($cek);
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tambah Divisi - SIMO</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #172033;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-height: 100vh;
        }

        .content {
            padding: 30px 34px 40px;
        }

        /* HEADER */
        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 25px;
            font-weight: 700;
            color: #172033;
            margin-bottom: 6px;
        }

        .page-header p {
            font-size: 13px;
            color: #7a8596;
            line-height: 1.5;
        }

        /* CARD FORM */
        .form-card {
            width: 100%;
            max-width: 680px;
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #344054;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d9dde2;
            border-radius: 8px;
            background: #ffffff;
            font-family: inherit;
            font-size: 13px;
            color: #344054;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-group input::placeholder {
            color: #9aa3b1;
        }

        .form-group input:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, 0.08);
        }

        .alert {
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fde5e5;
            border: 1px solid #f3c7c7;
            color: #c94242;
        }

        /* TOMBOL */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 5px;
        }

        .btn-simpan,
        .btn-kembali {
            border: none;
            padding: 10px 17px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn-simpan {
            background: #b51f2a;
            color: #ffffff;
        }

        .btn-simpan:hover {
            background: #941923;
            transform: translateY(-1px);
        }

        .btn-kembali {
            background: #eef0f3;
            color: #4c5b6e;
        }

        .btn-kembali:hover {
            background: #e3e6ea;
        }

        .footer-note {
            text-align: center;
            font-size: 11px;
            color: #9aa3b1;
            padding: 28px 0 5px;
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 22px 20px 35px;
            }

            .form-card {
                max-width: 100%;
            }
        }

        @media (max-width: 500px) {
            .content {
                padding: 18px 15px 30px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .form-card {
                padding: 20px;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-simpan,
            .btn-kembali {
                width: 100%;
            }
        }

        /* =========================================================
           DARK MODE (palet sama dengan dashboard)
        ========================================================= */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body{background:#0f141b!important}

        html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
        html body.dark-mode .main,
        html body.dark-mode .content{background:#0f141b!important}

        html body.dark-mode .page-header h1,
        html body.dark-mode .form-group label{color:#edf2f7!important}

        html body.dark-mode .page-header p,
        html body.dark-mode .footer-note{color:#8f9bac!important}

        html body.dark-mode .form-card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}

        html body.dark-mode .form-group input{background:#202733!important;color:#edf2f7!important;border-color:#2c3542!important}
        html body.dark-mode .form-group input::placeholder{color:#8f9bac!important}
        html body.dark-mode .form-group input:focus{border-color:#b51f2a!important}

        html body.dark-mode .alert-error{background:#382326!important;border-color:#51363b!important;color:#f07a80!important}

        html body.dark-mode .btn-kembali{background:#202733!important;color:#cbd2dc!important}
        html body.dark-mode .btn-kembali:hover{background:#2c3542!important}

    </style>

</head>

<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <div class="page-header">
                <h1>Tambah Divisi</h1>
                <p>Tambahkan divisi baru ke dalam sistem SIMO.</p>
            </div>

            <div class="form-card">

                <?php if ($pesan != ""): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($pesan, ENT_QUOTES, "UTF-8"); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">

                    <div class="form-group">

                        <label for="nama_divisi">Nama Divisi</label>

                        <input
                            type="text"
                            id="nama_divisi"
                            name="nama_divisi"
                            placeholder="Masukkan nama divisi"
                            value="<?= htmlspecialchars($_POST["nama_divisi"] ?? "", ENT_QUOTES, "UTF-8"); ?>"
                            required
                            autocomplete="off"
                        >

                    </div>

                    <div class="form-actions">

                        <button
                            type="submit"
                            name="simpan"
                            class="btn-simpan"
                        >
                            Simpan
                        </button>

                        <button
                            type="button"
                            class="btn-kembali"
                            onclick="window.location.href='divisi.php'"
                        >
                            Kembali
                        </button>

                    </div>

                </form>

            </div>

            <div class="footer-note">
                &copy; <?= date("Y"); ?>
                SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>

</body>

</html>