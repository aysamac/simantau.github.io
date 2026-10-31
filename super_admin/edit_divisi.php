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

/* =========================================================
   ID DIVISI
========================================================= */
$id_divisi = isset($_GET["id"]) ? (int) $_GET["id"] : 0;

if ($id_divisi <= 0) {
    header("Location: divisi.php");
    exit;
}

$pesan = "";
$tipe_pesan = "";

/* =========================================================
   AMBIL DATA DIVISI
   (akun berstatus 'Dihapus' tidak dihitung)
========================================================= */
function ambil_divisi($koneksi, $id_divisi)
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT
            d.id_divisi,
            d.nama_divisi,
            (
                SELECT COUNT(*) FROM users uk
                WHERE uk.id_divisi = d.id_divisi
                  AND uk.role = 'Karyawan'
                  AND COALESCE(TRIM(uk.status), '') <> 'Dihapus'
            ) AS jumlah_karyawan,
            (
                SELECT COUNT(*) FROM users ua
                WHERE ua.id_divisi = d.id_divisi
                  AND ua.role = 'Admin'
                  AND COALESCE(TRIM(ua.status), '') <> 'Dihapus'
            ) AS jumlah_admin
         FROM divisi d
         WHERE d.id_divisi = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $id_divisi);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $data = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $data;
}

$data_divisi = ambil_divisi($koneksi, $id_divisi);

/* Data tidak ada -> kembali ke halaman Divisi (bukan Pengguna) */
if (!$data_divisi) {
    header("Location: divisi.php");
    exit;
}

/* =========================================================
   SIMPAN PERUBAHAN
========================================================= */
if (isset($_POST["simpan"])) {

    $nama_divisi_baru = trim($_POST["nama_divisi"] ?? "");

    if ($nama_divisi_baru === "") {

        $pesan = "Nama divisi wajib diisi.";
        $tipe_pesan = "error";

    } elseif (mb_strlen($nama_divisi_baru) < 2) {

        $pesan = "Nama divisi minimal 2 karakter.";
        $tipe_pesan = "error";

    } else {

        $cek = mysqli_prepare(
            $koneksi,
            "SELECT id_divisi
             FROM divisi
             WHERE nama_divisi = ?
               AND id_divisi != ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($cek, "si", $nama_divisi_baru, $id_divisi);
        mysqli_stmt_execute($cek);
        mysqli_stmt_store_result($cek);

        $nama_dipakai = mysqli_stmt_num_rows($cek) > 0;

        mysqli_stmt_close($cek);

        if ($nama_dipakai) {

            $pesan = "Nama divisi sudah digunakan oleh divisi lain.";
            $tipe_pesan = "error";

        } else {

            $stmt_update = mysqli_prepare(
                $koneksi,
                "UPDATE divisi
                 SET nama_divisi = ?
                 WHERE id_divisi = ?"
            );

            mysqli_stmt_bind_param($stmt_update, "si", $nama_divisi_baru, $id_divisi);

            if (mysqli_stmt_execute($stmt_update)) {

                mysqli_stmt_close($stmt_update);

                header("Location: divisi.php?pesan=divisi_diubah");
                exit;

            } else {

                $pesan = "Data divisi gagal diubah: " . mysqli_error($koneksi);
                $tipe_pesan = "error";

                mysqli_stmt_close($stmt_update);

            }
        }

        /* Tampilkan kembali nama yang diketik jika gagal */
        $data_divisi["nama_divisi"] = $nama_divisi_baru;
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Divisi - SIMO</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #263247;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button,
        input {
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-height: 100vh;
            min-width: 0;
            background: #ffffff;
        }

        .content {
            padding: 28px 34px 34px;
        }

        .page-title {
            margin-bottom: 8px;
        }

        .page-title h1 {
            font-size: 24px;
            line-height: 1.2;
            font-weight: 700;
            color: #182338;
            margin-bottom: 6px;
        }

        .page-title p {
            font-size: 13px;
            line-height: 1.5;
            color: #8290a3;
        }

        .breadcrumb {
            font-size: 12.5px;
            color: #8290a3;
            margin-bottom: 22px;
        }

        .breadcrumb a:hover {
            color: #b51f2a;
        }

        .alert {
            max-width: 850px;
            padding: 12px 15px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 13px;
            font-weight: 500;
        }

        .alert.success {
            background: #edf8f0;
            border: 1px solid #d6ecd9;
            color: #2f8650;
        }

        .alert.error {
            background: #fff2f3;
            border: 1px solid #efd4d7;
            color: #b51f2a;
        }

        /* CARD */
        .form-card {
            max-width: 850px;
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 26px 28px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
        }

        .form-header {
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e4e8ed;
        }

        .form-header h2 {
            font-size: 19px;
            font-weight: 700;
            color: #182338;
            margin-bottom: 5px;
        }

        .form-header p {
            font-size: 13px;
            color: #8290a3;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }

        .field {
            min-height: 72px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #768397;
            margin-bottom: 7px;
        }

        .required {
            color: #b51f2a;
        }

        .field-input {
            width: 100%;
            height: 44px;
            border: 1px solid #d9dfe7;
            border-radius: 8px;
            background: #ffffff;
            color: #344054;
            padding: 0 12px;
            font-size: 13px;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease;
        }

        .field-input:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, 0.06);
        }

        .field-value {
            min-height: 44px;
            display: flex;
            align-items: center;
            width: 100%;
            padding: 0 12px;
            border: 1px solid #d9dfe7;
            border-radius: 8px;
            background: #f8fafc;
            color: #667085;
            font-size: 13px;
        }

        .field-hint {
            margin-top: 6px;
            font-size: 11px;
            line-height: 1.4;
            color: #8b97a8;
        }

        .form-actions {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e4e8ed;
        }

        .btn-save,
        .btn-cancel {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s ease, color .18s ease, border-color .18s ease;
        }

        .btn-save {
            border: none;
            background: #b51f2a;
            color: #ffffff;
        }

        .btn-save:hover {
            background: #941923;
        }

        .btn-cancel {
            border: 1px solid #d9dfe7;
            background: #ffffff;
            color: #667085;
        }

        .btn-cancel:hover {
            background: #f8f9fa;
        }

        .footer {
            text-align: center;
            color: #8b96a6;
            font-size: 11px;
            margin-top: 24px;
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 22px 18px 28px;
            }

            .form-card {
                padding: 20px;
            }
        }

        @media (max-width: 620px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-wrap: wrap;
            }

            .btn-save,
            .btn-cancel {
                width: 100%;
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

        html body.dark-mode .page-title h1,
        html body.dark-mode .form-header h2 { color: #edf2f7 !important; }

        html body.dark-mode .page-title p,
        html body.dark-mode .breadcrumb,
        html body.dark-mode .form-header p,
        html body.dark-mode .field-hint,
        html body.dark-mode .footer { color: #8f9bac !important; }

        html body.dark-mode .breadcrumb a:hover { color: #f07a80 !important; }

        html body.dark-mode .form-card {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            box-shadow: none !important;
        }

        html body.dark-mode .form-header,
        html body.dark-mode .form-actions { border-color: #2c3542 !important; }

        html body.dark-mode .field-label { color: #cbd2dc !important; }
        html body.dark-mode .required { color: #f07a80 !important; }

        html body.dark-mode .field-input {
            background: #0f141b !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
            color-scheme: dark !important;
        }

        html body.dark-mode .field-input:focus {
            border-color: #b51f2a !important;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, .16) !important;
        }

        html body.dark-mode .field-value {
            background: #1b222c !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .btn-cancel {
            background: #202733 !important;
            border-color: #2c3542 !important;
            color: #cbd2dc !important;
        }

        html body.dark-mode .btn-cancel:hover { background: #2a3342 !important; }

        html body.dark-mode .btn-save {
            background: #b51f2a !important;
            color: #fff !important;
        }

        html body.dark-mode .btn-save:hover { background: #d12b36 !important; }

        html body.dark-mode .alert.success {
            background: #183d2a !important;
            border-color: #2b5a3d !important;
            color: #72d89a !important;
        }

        html body.dark-mode .alert.error {
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

            <div class="page-title">
                <h1>Edit Divisi</h1>
                <p>Perbarui informasi divisi yang terdapat dalam sistem SIMO.</p>
            </div>

            <div class="breadcrumb">
                <a href="divisi.php">Divisi</a>
                <span>/ Edit Divisi</span>
            </div>

            <?php if ($pesan !== ""): ?>

                <div class="alert <?= htmlspecialchars($tipe_pesan) ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>

            <?php endif; ?>

            <div class="form-card">

                <div class="form-header">
                    <h2>Data Divisi</h2>
                    <p>Nama divisi akan tampil pada data pengguna dan pekerjaan.</p>
                </div>

                <form method="POST" action="" autocomplete="off">

                    <div class="form-grid">

                        <div class="field full">
                            <label class="field-label" for="nama_divisi">
                                Nama Divisi <span class="required">*</span>
                            </label>
                            <input
                                type="text"
                                id="nama_divisi"
                                name="nama_divisi"
                                class="field-input"
                                value="<?= htmlspecialchars($data_divisi["nama_divisi"] ?? "", ENT_QUOTES, "UTF-8") ?>"
                                maxlength="100"
                                required
                            >
                            <div class="field-hint">
                                Contoh: Transportasi, ITAS, Mekanikal Infrastruktur.
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Jumlah Karyawan</span>
                            <div class="field-value">
                                <?= number_format((int) $data_divisi["jumlah_karyawan"]) ?> Orang
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Admin Divisi</span>
                            <div class="field-value">
                                <?= number_format((int) $data_divisi["jumlah_admin"]) ?> Orang
                            </div>
                        </div>

                    </div>

                    <div class="form-actions">
                        <a href="divisi.php" class="btn-cancel">Batal</a>
                        <button type="submit" name="simpan" class="btn-save">Simpan Perubahan</button>
                    </div>

                </form>

            </div>

            <div class="footer">
                &copy; <?= date("Y") ?> SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>

</body>

</html>