<?php
include "../config/session.php";

if (!isset($_SESSION['id_user']) || $_SESSION['role'] != 'Admin') {
    header('Location: ../index.php');
    exit;
}

include '../config/koneksi.php';

$id_admin = (int)$_SESSION['id_user'];

$q_admin = mysqli_query($koneksi, "SELECT * FROM users WHERE id_user='$id_admin' LIMIT 1");

if (!$q_admin || mysqli_num_rows($q_admin) == 0) {
    header('Location: ../index.php');
    exit;
}

$admin = mysqli_fetch_assoc($q_admin);
$id_divisi = (int)$admin['id_divisi'];

$id_pekerjaan = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_pekerjaan <= 0) {
    header('Location: pekerjaan.php');
    exit;
}

mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS pekerjaan_karyawan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pekerjaan INT NOT NULL,
    id_user INT NOT NULL,
    status_penugasan VARCHAR(50) DEFAULT 'Aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pekerjaan (id_pekerjaan),
    INDEX idx_user (id_user),
    CONSTRAINT fk_pk_pekerjaan
        FOREIGN KEY (id_pekerjaan) REFERENCES pekerjaan(id_pekerjaan)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pk_user
        FOREIGN KEY (id_user) REFERENCES users(id_user)
        ON DELETE CASCADE ON UPDATE CASCADE
)");

$q_pekerjaan = mysqli_query($koneksi, "SELECT * FROM pekerjaan
    WHERE id_pekerjaan='$id_pekerjaan' AND id_divisi='$id_divisi' LIMIT 1");

if (!$q_pekerjaan || mysqli_num_rows($q_pekerjaan) == 0) {
    header('Location: pekerjaan.php');
    exit;
}

$pekerjaan = mysqli_fetch_assoc($q_pekerjaan);

$q_karyawan = mysqli_query($koneksi, "SELECT id_user, nama FROM users
    WHERE id_divisi='$id_divisi' AND role='Karyawan' AND status='Aktif'
    ORDER BY nama ASC");

$data_karyawan = [];

if ($q_karyawan) {
    while ($r = mysqli_fetch_assoc($q_karyawan)) {
        $data_karyawan[] = [
            'id_user' => (int)$r['id_user'],
            'nama' => $r['nama']
        ];
    }
}

$karyawan_terpilih = [];

$q_relasi = mysqli_query($koneksi, "SELECT id_user FROM pekerjaan_karyawan
    WHERE id_pekerjaan='$id_pekerjaan' ORDER BY id ASC");

if ($q_relasi) {
    while ($r = mysqli_fetch_assoc($q_relasi)) {
        $uid = (int)$r['id_user'];
        if ($uid > 0 && !in_array($uid, $karyawan_terpilih, true)) {
            $karyawan_terpilih[] = $uid;
        }
    }
}

/*
 * PEKERJAAN YANG PERNAH DIALIHKAN
 * Karyawan lama (pengaju) yang sudah berhasil mengalihkan pekerjaan
 * tidak ditampilkan lagi di form edit, supaya tugas individu yang
 * dialihkan tetap terbaca 1 karyawan (pemegang saat ini).
 * Transfer diproses berurutan: jika karyawan lama kemudian dialihi
 * lagi sebagai pengganti, ia dihitung sebagai pemegang aktif kembali.
 */
$mantan_pemegang = [];

$q_alih = mysqli_query($koneksi, "SELECT id_karyawan_pengaju, id_karyawan_pengganti
    FROM pengalihan_pekerjaan
    WHERE id_pekerjaan='$id_pekerjaan' AND status_pengalihan='Berhasil'
    ORDER BY tanggal_pengajuan ASC, id_pengalihan ASC");

if ($q_alih) {
    while ($a = mysqli_fetch_assoc($q_alih)) {
        $pengaju = (int)($a['id_karyawan_pengaju'] ?? 0);
        $pengganti = (int)($a['id_karyawan_pengganti'] ?? 0);

        if ($pengaju > 0) {
            $mantan_pemegang[$pengaju] = true;
        }
        if ($pengganti > 0) {
            unset($mantan_pemegang[$pengganti]);
        }
    }
}

if ($mantan_pemegang) {
    $karyawan_terpilih = array_values(array_filter(
        $karyawan_terpilih,
        function ($uid) use ($mantan_pemegang) {
            return !isset($mantan_pemegang[$uid]);
        }
    ));
}

if (!$karyawan_terpilih && !empty($pekerjaan['id_karyawan'])) {
    $karyawan_terpilih[] = (int)$pekerjaan['id_karyawan'];
}

$pesan = '';
$tipe_pesan = '';

if (isset($_POST['update_pekerjaan'])) {

    $nama_pekerjaan = mysqli_real_escape_string($koneksi, trim($_POST['nama_pekerjaan'] ?? ''));
    $jenis_pekerjaan = mysqli_real_escape_string($koneksi, trim($_POST['jenis_pekerjaan'] ?? ''));
    $tipe_order = mysqli_real_escape_string($koneksi, trim($_POST['tipe_order'] ?? ''));
    $nomor_order = mysqli_real_escape_string($koneksi, trim($_POST['nomor_order'] ?? ''));
    $lokasi = mysqli_real_escape_string($koneksi, trim($_POST['lokasi'] ?? ''));
    $deskripsi = mysqli_real_escape_string($koneksi, trim($_POST['deskripsi'] ?? ''));

    $id_karyawan_post = $_POST['id_karyawan'] ?? [];

    if (!is_array($id_karyawan_post)) {
        $id_karyawan_post = [];
    }

    $id_karyawan_list = [];

    foreach ($id_karyawan_post as $id_user) {
        $id_user = (int)$id_user;
        if ($id_user > 0 && !in_array($id_user, $id_karyawan_list, true)) {
            $id_karyawan_list[] = $id_user;
        }
    }

    $tanggal_mulai_raw = trim($_POST['tanggal_mulai'] ?? '');
    $jam_mulai_raw = trim($_POST['jam_mulai'] ?? '');
    $target_selesai_raw = trim($_POST['target_selesai'] ?? '');
    $jam_selesai_raw = trim($_POST['jam_selesai'] ?? '');

    $tanggal_mulai_obj = ($tanggal_mulai_raw && $jam_mulai_raw)
        ? DateTime::createFromFormat('Y-m-d H:i', "$tanggal_mulai_raw $jam_mulai_raw")
        : false;

    $target_selesai_obj = ($target_selesai_raw && $jam_selesai_raw)
        ? DateTime::createFromFormat('Y-m-d H:i', "$target_selesai_raw $jam_selesai_raw")
        : false;

    $tanggal_mulai = $tanggal_mulai_obj ? $tanggal_mulai_obj->format('Y-m-d H:i:s') : '';
    $target_selesai = $target_selesai_obj ? $target_selesai_obj->format('Y-m-d H:i:s') : '';

    $jumlah_karyawan = (int)($_POST['jumlah_karyawan'] ?? 0);

    $valid_jumlah = $jumlah_karyawan >= 1
        && $jumlah_karyawan <= 10
        && count($id_karyawan_list) === $jumlah_karyawan;

    if (
        $nama_pekerjaan === '' ||
        $jenis_pekerjaan === '' ||
        $tipe_order === '' ||
        $lokasi === '' ||
        !$valid_jumlah ||
        !$tanggal_mulai ||
        !$target_selesai
    ) {
        $pesan = 'Data pekerjaan belum lengkap atau jumlah karyawan tidak sesuai.';
        $tipe_pesan = 'error';

    } elseif (!in_array($tipe_order, ['Order Perbaikan', 'Non Order', 'Maintenance'], true)) {
        $pesan = 'Tipe order tidak valid.';
        $tipe_pesan = 'error';

    } elseif ($tipe_order === 'Order Perbaikan' && $nomor_order === '') {
        $pesan = 'Nomor Order wajib diisi untuk Order Perbaikan.';
        $tipe_pesan = 'error';

    } elseif (!$tanggal_mulai_obj || !$target_selesai_obj || $target_selesai_obj <= $tanggal_mulai_obj) {
        $pesan = 'Tanggal dan jam target selesai harus setelah tanggal dan jam mulai.';
        $tipe_pesan = 'error';

    } else {

        if ($tipe_order !== 'Order Perbaikan') {
            $nomor_order = '';
        }

        $semua_valid = true;

        foreach ($id_karyawan_list as $uid) {
            $uid = (int)$uid;

            $cek = mysqli_query($koneksi, "SELECT id_user FROM users
                WHERE id_user='$uid' AND role='Karyawan'
                AND id_divisi='$id_divisi' AND status='Aktif' LIMIT 1");

            if (!$cek || mysqli_num_rows($cek) === 0) {
                $semua_valid = false;
                break;
            }
        }

        if (!$semua_valid) {
            $pesan = 'Terdapat karyawan yang tidak valid.';
            $tipe_pesan = 'error';

        } else {

            $id_karyawan_utama = (int)$id_karyawan_list[0];

            mysqli_begin_transaction($koneksi);

            try {
                $nomor_order_db = mysqli_real_escape_string($koneksi, $nomor_order);

                $q_update = mysqli_query($koneksi, "UPDATE pekerjaan SET
                        nama_pekerjaan='$nama_pekerjaan',
                        lokasi='$lokasi',
                        jenis_pekerjaan='$jenis_pekerjaan',
                        tipe_order='$tipe_order',
                        nomor_order='$nomor_order_db',
                        id_karyawan='$id_karyawan_utama',
                        tanggal_mulai='$tanggal_mulai',
                        target_selesai='$target_selesai',
                        deskripsi='$deskripsi'
                    WHERE id_pekerjaan='$id_pekerjaan'
                    AND id_divisi='$id_divisi'
                    LIMIT 1");

                if (!$q_update) {
                    throw new Exception('Gagal memperbarui pekerjaan.');
                }

                $hapus_relasi = mysqli_query($koneksi, "DELETE FROM pekerjaan_karyawan
                    WHERE id_pekerjaan='$id_pekerjaan'");

                if (!$hapus_relasi) {
                    throw new Exception('Gagal memperbarui data karyawan.');
                }

                foreach ($id_karyawan_list as $uid) {
                    $uid = (int)$uid;

                    $q_insert = mysqli_query($koneksi, "INSERT INTO pekerjaan_karyawan
                        (id_pekerjaan, id_user, status_penugasan)
                        VALUES ('$id_pekerjaan', '$uid', 'Aktif')");

                    if (!$q_insert) {
                        throw new Exception('Gagal menyimpan karyawan.');
                    }
                }

                mysqli_commit($koneksi);

                header('Location: pekerjaan.php?pesan=edit');
                exit;

            } catch (Exception $e) {
                mysqli_rollback($koneksi);
                $pesan = $e->getMessage();
                $tipe_pesan = 'error';
            }
        }
    }

    $pekerjaan['nama_pekerjaan'] = $_POST['nama_pekerjaan'] ?? '';
    $pekerjaan['jenis_pekerjaan'] = $_POST['jenis_pekerjaan'] ?? '';
    $pekerjaan['tipe_order'] = $_POST['tipe_order'] ?? '';
    $pekerjaan['nomor_order'] = $_POST['nomor_order'] ?? '';
    $pekerjaan['lokasi'] = $_POST['lokasi'] ?? '';
    $pekerjaan['deskripsi'] = $_POST['deskripsi'] ?? '';

    $karyawan_terpilih = $id_karyawan_list;
}

$tanggal_mulai_value = '';
$jam_mulai_value = '';
$target_selesai_value = '';
$jam_selesai_value = '';

foreach ([
    ['tanggal_mulai', 'tanggal_mulai_value', 'jam_mulai_value'],
    ['target_selesai', 'target_selesai_value', 'jam_selesai_value']
] as $set) {

    $raw = trim($pekerjaan[$set[0]] ?? '');

    if ($raw !== '') {
        $obj = DateTime::createFromFormat('Y-m-d H:i:s', $raw)
            ?: DateTime::createFromFormat('Y-m-d H:i', $raw)
            ?: DateTime::createFromFormat('Y-m-d', $raw);

        if ($obj) {
            ${$set[1]} = $obj->format('Y-m-d');
            ${$set[2]} = (strlen($raw) >= 16 && strpos($raw, ':') !== false)
                ? $obj->format('H:i')
                : '';
        }
    }
}

$jumlah_karyawan_awal = count($karyawan_terpilih);

if ($jumlah_karyawan_awal < 1) {
    $jumlah_karyawan_awal = 1;
}

if ($jumlah_karyawan_awal > 10) {
    $jumlah_karyawan_awal = 10;
}

if (count($karyawan_terpilih) === 0) {
    $karyawan_terpilih = [0];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Pekerjaan - SIMOME</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
body{background:#ffffff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.main{margin-left:240px;width:calc(100% - 240px);min-width:0;background:#ffffff}
.content{padding:26px 32px 40px;background:#ffffff}
.page-header{display:flex;justify-content:space-between;align-items:flex-end;gap:15px;margin-bottom:22px;flex-wrap:wrap}
.page-header h2{font-size:22px;color:#1c2636;margin-bottom:5px}
.page-header p{font-size:13px;color:#667488}
.breadcrumb{font-size:12.5px;color:#9aa3af}
.breadcrumb a{color:#9aa3af;font-weight:500}
.breadcrumb .current{color:#4d5969;font-weight:600}
.alert{padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:13px}
.alert.error{background:#fde2e2;color:#d94b4b}
.card{background:#ffffff;border:1px solid #d9dde2;border-radius:14px;padding:22px;box-shadow:0 1px 3px rgba(16,24,40,.04)}
.card-title{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.card-title-icon{width:34px;height:34px;border-radius:50%;background:#f7e3e5;display:flex;align-items:center;justify-content:center}
.card-title-icon svg{width:16px;height:16px;stroke:#b51f2a}
.card-title h3{font-size:15px;color:#1c2636}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-group{width:100%}
.form-group.full{grid-column:1 / -1}
.form-group label{display:block;font-size:12px;font-weight:600;color:#526176;margin-bottom:8px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#b51f2a}
.form-group textarea{min-height:95px;resize:vertical}
#nomorOrderGroup{display:none}
.penugasan-box{grid-column:1 / -1;background:#fff8f8;border:1px solid #f0dfe1;border-radius:11px;padding:18px}
.penugasan-head{margin-bottom:16px}
.penugasan-head h4{font-size:14px;color:#8f2630;margin-bottom:4px}
.penugasan-head p{font-size:11.5px;color:#8b96a5}
.jumlah-karyawan-row{margin-bottom:16px}
.jumlah-karyawan-row label{display:block;font-size:12px;font-weight:600;color:#526176;margin-bottom:7px}
.jumlah-karyawan-select{width:245px;max-width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.jumlah-karyawan-select:focus{border-color:#b51f2a}
.karyawan-area{border-top:1px solid #eadfe1;padding-top:15px}
.karyawan-area-title{display:block;font-size:12px;font-weight:600;color:#526176;margin-bottom:11px}
.karyawan-list{display:flex;flex-direction:column;gap:11px}
.karyawan-field{display:grid;grid-template-columns:105px 1fr;align-items:center;gap:14px}
.karyawan-field-title{font-size:11px;font-weight:600;color:#7b8797}
.karyawan-select{width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.karyawan-select:focus{border-color:#b51f2a}
.info-penugasan{margin-top:9px;font-size:11px;color:#8b96a5}
.jadwal-wrapper{grid-column:1 / -1;margin-top:2px;padding-top:4px;border-top:1px solid #eef1f5}
.jadwal-grid{display:grid;grid-template-columns:1fr 1fr;gap:28px}
.jadwal-column{min-width:0}
.jadwal-title{font-size:13px;font-weight:600;color:#1c2636;margin-bottom:4px}
.jadwal-subtitle{font-size:11.5px;color:#8b96a5;margin-bottom:13px}
.jadwal-fields{display:grid;grid-template-columns:1.15fr .85fr;gap:12px}
.date-time-field{min-width:0}
.date-time-field span{display:block;font-size:11px;font-weight:600;color:#526176;margin-bottom:7px}
.date-time-field input{width:100%;height:44px;padding:10px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.date-time-field input:focus{border-color:#b51f2a}
.form-button{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:22px}
.btn-kembali{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:8px;background:#f1f3f6;color:#677384;font-size:13px;font-weight:600}
.btn-simpan{border:none;background:#b51f2a;color:#fff;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.btn-simpan:hover{background:#941923}
@media(max-width:800px){
    .main{margin-left:0;width:100%}
    .content{padding:20px}
    .form-grid{grid-template-columns:1fr}
    .form-group.full,.penugasan-box,.jadwal-wrapper{grid-column:auto}
    .karyawan-field{grid-template-columns:1fr;gap:6px}
    .jadwal-grid{grid-template-columns:1fr;gap:20px}
    .jadwal-fields{grid-template-columns:1fr 1fr}
    .form-button{flex-direction:column-reverse;align-items:stretch}
}

/* DARK MODE (palet sama dengan halaman Profil) */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
body.dark-mode .main,body.dark-mode .content{background:#0f141b!important}
body.dark-mode .page-header h2,body.dark-mode .card-title h3,body.dark-mode .jadwal-title,body.dark-mode .form-group label,body.dark-mode .jumlah-karyawan-row label,body.dark-mode .karyawan-area-title,body.dark-mode .step-select-wrap label,body.dark-mode .date-time-field span{color:#edf2f7!important}
body.dark-mode .page-header p,body.dark-mode .breadcrumb,body.dark-mode .breadcrumb a,body.dark-mode .breadcrumb .current,body.dark-mode .penugasan-head p,body.dark-mode .info-penugasan,body.dark-mode .jadwal-subtitle,body.dark-mode .karyawan-field-title{color:#8f9bac!important}
body.dark-mode .card{background:#151b23!important;box-shadow:none!important;border:1px solid #2c3542!important}
body.dark-mode .card-title-icon{background:#382326!important}
body.dark-mode .card-title-icon svg{stroke:#f07a80!important}
body.dark-mode .form-group input,body.dark-mode .form-group select,body.dark-mode .form-group textarea,body.dark-mode .jumlah-karyawan-select,body.dark-mode .karyawan-select,body.dark-mode .date-time-field input{background:#202733!important;color:#e8edf4!important;border-color:#303947!important}
body.dark-mode .form-group input::placeholder,body.dark-mode .form-group textarea::placeholder{color:#7f8998!important}
body.dark-mode .form-group input:focus,body.dark-mode .form-group select:focus,body.dark-mode .form-group textarea:focus,body.dark-mode .jumlah-karyawan-select:focus,body.dark-mode .karyawan-select:focus,body.dark-mode .date-time-field input:focus{border-color:#b51f2a!important}
body.dark-mode .penugasan-box{background:#1b222c!important;border-color:#2c3542!important}
body.dark-mode .penugasan-head h4{color:#f07a80!important}
body.dark-mode .karyawan-area{border-top-color:#2c3542!important}
body.dark-mode .jadwal-wrapper{border-top-color:#2c3542!important}
body.dark-mode .btn-kembali{background:#202733!important;color:#cbd2dc!important;border:1px solid #303947!important}
body.dark-mode .btn-kembali:hover{background:#2a3342!important}
body.dark-mode .btn-simpan{background:#b51f2a!important;color:#fff!important}
body.dark-mode .btn-simpan:hover{background:#941923!important}
body.dark-mode .alert.error{background:#382326!important;color:#f08a90!important;border:1px solid #51363b!important}
body.dark-mode input[type="date"],body.dark-mode input[type="time"]{color-scheme:dark}
</style>
</head>
<body>

<?php include '../includes/admin_divisi/sidebar.php'; ?>

<div class="main">

<?php include '../includes/admin_divisi/topbar.php'; ?>

<div class="content">

<div class="page-header">
    <div>
        <h2>Edit Pekerjaan</h2>
        <p>Perbarui data pekerjaan pada divisi Anda.</p>
    </div>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        &nbsp;›&nbsp;
        <a href="pekerjaan.php">Pekerjaan</a>
        &nbsp;›&nbsp;
        <span class="current">Edit</span>
    </div>
</div>

<?php if ($pesan !== ''): ?>
<div class="alert <?= $tipe_pesan; ?>">
    <?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<div class="card">

<div class="card-title">
    <div class="card-title-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"></path>
            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path>
        </svg>
    </div>
    <h3>Data Pekerjaan</h3>
</div>

<form method="POST" id="formEditPekerjaan">

<div class="form-grid">

<div class="form-group">
    <label for="namaPekerjaan">Nama Pekerjaan</label>
    <input type="text" name="nama_pekerjaan" id="namaPekerjaan" placeholder="Masukkan nama pekerjaan"
        value="<?= htmlspecialchars($pekerjaan['nama_pekerjaan'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
</div>

<div class="form-group">
    <label for="jenisPekerjaan">Jenis Pekerjaan</label>
    <select name="jenis_pekerjaan" id="jenisPekerjaan" required>
        <option value="">Pilih jenis pekerjaan</option>
        <?php foreach (['Perbaikan', 'Pemeliharaan', 'Instalasi', 'Pengecekan'] as $jenis): ?>
        <option value="<?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8'); ?>"
            <?= (($pekerjaan['jenis_pekerjaan'] ?? '') === $jenis) ? 'selected' : ''; ?>>
            <?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8'); ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label for="lokasi">Lokasi</label>
    <input type="text" name="lokasi" id="lokasi" placeholder="Contoh: Gudang B, Lantai 2"
        value="<?= htmlspecialchars($pekerjaan['lokasi'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
</div>

<div class="form-group">
    <label for="tipeOrder">Tipe Order</label>
    <select name="tipe_order" id="tipeOrder" required>
        <option value="">Pilih tipe order</option>
        <?php foreach (['Order Perbaikan', 'Non Order', 'Maintenance'] as $t): ?>
        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
            <?= (($pekerjaan['tipe_order'] ?? '') === $t) ? 'selected' : ''; ?>>
            <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group" id="nomorOrderGroup">
    <label for="nomorOrder">Nomor Order</label>
    <input type="text" name="nomor_order" id="nomorOrder" placeholder="Masukkan nomor order"
        value="<?= htmlspecialchars($pekerjaan['nomor_order'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
</div>

<div class="penugasan-box">
    <div class="penugasan-head">
        <h4>Karyawan yang Ditugaskan</h4>
        <p>Pilih jumlah karyawan yang ditugaskan, kemudian tentukan nama karyawannya.</p>
    </div>

    <div class="jumlah-karyawan-row">
        <label for="jumlahKaryawan">Jumlah Karyawan</label>
        <select name="jumlah_karyawan" id="jumlahKaryawan" class="jumlah-karyawan-select" required>
            <?php for ($i = 1; $i <= 10; $i++): ?>
            <option value="<?= $i; ?>" <?= ($jumlah_karyawan_awal === $i) ? 'selected' : ''; ?>>
                <?= $i; ?> Karyawan
            </option>
            <?php endfor; ?>
        </select>
    </div>

    <div class="karyawan-area">
        <span class="karyawan-area-title">Karyawan yang Ditugaskan</span>
        <div class="karyawan-list" id="karyawanList"></div>
        <div class="info-penugasan" id="infoPenugasan"></div>
    </div>
</div>

<div class="jadwal-wrapper">
    <div class="jadwal-grid">

        <div class="jadwal-column">
            <div class="jadwal-title">Tanggal Mulai</div>
            <div class="jadwal-subtitle">Tentukan tanggal dan jam mulai pekerjaan.</div>
            <div class="jadwal-fields">
                <div class="date-time-field">
                    <span>Tanggal</span>
                    <input type="date" name="tanggal_mulai" id="tanggalMulai"
                        value="<?= htmlspecialchars($tanggal_mulai_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="date-time-field">
                    <span>Jam Mulai</span>
                    <input type="time" name="jam_mulai" id="jamMulai"
                        value="<?= htmlspecialchars($jam_mulai_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>
        </div>

        <div class="jadwal-column">
            <div class="jadwal-title">Target Selesai</div>
            <div class="jadwal-subtitle">Tentukan tanggal dan jam target selesai pekerjaan.</div>
            <div class="jadwal-fields">
                <div class="date-time-field">
                    <span>Tanggal</span>
                    <input type="date" name="target_selesai" id="targetSelesai"
                        value="<?= htmlspecialchars($target_selesai_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="date-time-field">
                    <span>Jam Selesai</span>
                    <input type="time" name="jam_selesai" id="jamSelesai"
                        value="<?= htmlspecialchars($jam_selesai_value, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="form-group full">
    <label for="deskripsi">Deskripsi</label>
    <textarea name="deskripsi" id="deskripsi" placeholder="Masukkan deskripsi pekerjaan"><?= htmlspecialchars($pekerjaan['deskripsi'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
</div>

</div>

<div class="form-button">
    <a href="pekerjaan.php" class="btn-kembali">Kembali</a>
    <button type="submit" name="update_pekerjaan" class="btn-simpan">Simpan Perubahan</button>
</div>

</form>

</div>

</div>

</div>

<script>
const tipeOrder = document.getElementById('tipeOrder');
const nomorOrderGroup = document.getElementById('nomorOrderGroup');
const nomorOrder = document.getElementById('nomorOrder');

function aturNomorOrder() {
    const show = tipeOrder.value === 'Order Perbaikan';
    nomorOrderGroup.style.display = show ? 'block' : 'none';
    nomorOrder.required = show;
    if (!show) {
        nomorOrder.value = '';
    }
}

tipeOrder.addEventListener('change', aturNomorOrder);
aturNomorOrder();

const dataKaryawanTerpilih = <?= json_encode(array_values($karyawan_terpilih), JSON_UNESCAPED_UNICODE); ?>;
const dataSemuaKaryawan = <?= json_encode($data_karyawan, JSON_UNESCAPED_UNICODE); ?>;

const jumlahKaryawan = document.getElementById('jumlahKaryawan');
const karyawanList = document.getElementById('karyawanList');
const infoPenugasan = document.getElementById('infoPenugasan');

function updatePilihanKaryawan() {
    const selects = document.querySelectorAll('.karyawan-select');
    const dipilih = [];

    selects.forEach(function (select) {
        if (select.value) {
            dipilih.push(select.value);
        }
    });

    selects.forEach(function (select) {
        const own = select.value;

        select.querySelectorAll('option').forEach(function (option) {
            option.disabled =
                option.value !== '' &&
                option.value !== own &&
                dipilih.includes(option.value);
        });
    });
}

function buatKaryawanFields(daftar) {
    const jumlah = parseInt(jumlahKaryawan.value) || 1;

    karyawanList.innerHTML = '';

    for (let i = 0; i < jumlah; i++) {
        const wrap = document.createElement('div');
        wrap.className = 'karyawan-field';

        const title = document.createElement('span');
        title.className = 'karyawan-field-title';
        title.textContent = 'Karyawan ' + (i + 1);

        const select = document.createElement('select');
        select.className = 'karyawan-select';
        select.name = 'id_karyawan[]';
        select.required = true;

        const awal = document.createElement('option');
        awal.value = '';
        awal.textContent = 'Pilih nama karyawan';
        select.appendChild(awal);

        dataSemuaKaryawan.forEach(function (karyawan) {
            const option = document.createElement('option');
            option.value = karyawan.id_user;
            option.textContent = karyawan.nama;

            if (String(daftar[i] ?? '') === String(karyawan.id_user)) {
                option.selected = true;
            }

            select.appendChild(option);
        });

        select.addEventListener('change', updatePilihanKaryawan);

        wrap.appendChild(title);
        wrap.appendChild(select);
        karyawanList.appendChild(wrap);
    }

    if (jumlah === 1) {
        infoPenugasan.textContent = '1 karyawan = pekerjaan individu.';
    } else {
        infoPenugasan.textContent = jumlah + ' karyawan = pekerjaan tim.';
    }

    updatePilihanKaryawan();
}

function pilihanSaatIni() {
    return Array.from(document.querySelectorAll('.karyawan-select')).map(function (select) {
        return select.value;
    });
}

jumlahKaryawan.addEventListener('change', function () {
    const jumlahBaru = parseInt(this.value) || 1;
    const pilihanUntukForm = pilihanSaatIni().slice(0, jumlahBaru);
    buatKaryawanFields(pilihanUntukForm);
});

document.getElementById('formEditPekerjaan').addEventListener('submit', function (e) {
    const selects = document.querySelectorAll('.karyawan-select');
    const vals = [];

    for (let i = 0; i < selects.length; i++) {
        if (!selects[i].value) {
            alert('Silakan pilih Karyawan ' + (i + 1) + '.');
            e.preventDefault();
            return;
        }

        if (vals.includes(selects[i].value)) {
            alert('Karyawan tidak boleh dipilih lebih dari satu kali.');
            e.preventDefault();
            return;
        }

        vals.push(selects[i].value);
    }

    const jumlah = parseInt(jumlahKaryawan.value) || 0;

    if (vals.length !== jumlah || jumlah < 1 || jumlah > 10) {
        alert('Jumlah karyawan belum sesuai.');
        e.preventDefault();
        return;
    }

    const tanggalMulai = document.getElementById('tanggalMulai');
    const jamMulai = document.getElementById('jamMulai');
    const targetSelesai = document.getElementById('targetSelesai');
    const jamSelesai = document.getElementById('jamSelesai');

    if (!tanggalMulai.value || !jamMulai.value || !targetSelesai.value || !jamSelesai.value) {
        alert('Tanggal dan jam wajib diisi.');
        e.preventDefault();
        return;
    }

    const waktuMulai = new Date(tanggalMulai.value + 'T' + jamMulai.value);
    const waktuSelesai = new Date(targetSelesai.value + 'T' + jamSelesai.value);

    if (waktuSelesai <= waktuMulai) {
        alert('Target selesai harus setelah tanggal dan jam mulai.');
        e.preventDefault();
        return;
    }

    if (tipeOrder.value === 'Order Perbaikan' && nomorOrder.value.trim() === '') {
        alert('Nomor Order wajib diisi.');
        e.preventDefault();
        return;
    }
});

buatKaryawanFields(dataKaryawanTerpilih);
</script>

</body>
</html>