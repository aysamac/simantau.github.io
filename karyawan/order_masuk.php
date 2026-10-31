<?php
include "../config/session.php";

// Hanya role Karyawan yang boleh masuk
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Karyawan") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* =========================================================
   PASTIKAN TABEL / KOLOM TIM SUDAH ADA
   (supaya halaman ini tidak error walau kirim_bukti.php
    belum pernah dibuka)
========================================================= */
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

$id_user = (int) $_SESSION["id_user"];

// Divisi karyawan ini (menentukan order mana yang tersedia untuknya)
$st = $koneksi->prepare("SELECT id_divisi FROM users WHERE id_user = ?");
$st->bind_param("i", $id_user);
$st->execute();
$id_divisi = (int) ($st->get_result()->fetch_assoc()["id_divisi"] ?? 0);

/* =========================================================
   PROSES AKSI: AMBIL
   (penyelesaian order sekarang lewat halaman kirim_bukti.php)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $aksi     = $_POST["aksi"] ?? "";
    $id_order = (int) ($_POST["id_order"] ?? 0);
    $tab_tuju = "tersedia";

    if ($aksi == "ambil") {
        // Hanya berhasil kalau order masih Menunggu, belum ada yang mengambil,
        // dan memang ditujukan ke divisi karyawan ini.
        $st = $koneksi->prepare(
            "UPDATE order_perbaikan
             SET status = 'Diproses', id_karyawan = ?, tanggal_diambil = NOW()
             WHERE id_order = ? AND status = 'Menunggu'
               AND id_karyawan IS NULL AND id_divisi = ?"
        );
        $st->bind_param("iii", $id_user, $id_order, $id_divisi);
        $st->execute();

        if ($st->affected_rows > 0) {
            $_SESSION["flash_order"] = "Order berhasil diambil. Selamat bekerja.";
            $tab_tuju = "saya";
        } else {
            $_SESSION["flash_order"] = "Order sudah diambil oleh karyawan lain atau tidak lagi tersedia.";
            $_SESSION["flash_order_error"] = true;
        }
    }

    header("Location: order_masuk.php?tab=" . $tab_tuju);
    exit;
}

$pesan = $_SESSION["flash_order"] ?? "";
$pesan_error = !empty($_SESSION["flash_order_error"]);
unset($_SESSION["flash_order"], $_SESSION["flash_order_error"]);

/* =========================================================
   JUMLAH PER TAB
   Tab Selesai: order yang dikerjakan sendiri (PIC)
   ATAU order tempat karyawan ini tercatat sebagai anggota tim.
========================================================= */
$st = $koneksi->prepare(
    "SELECT
        COALESCE(SUM(status = 'Menunggu' AND id_divisi = ?), 0) AS tersedia,
        COALESCE(SUM(status IN ('Diproses', 'Menunggu Konfirmasi') AND id_karyawan = ?), 0) AS saya,
        COALESCE(SUM(status = 'Selesai' AND (
            id_karyawan = ?
            OR id_order IN (SELECT id_order FROM order_pekerja WHERE id_user = ?)
        )), 0) AS selesai
     FROM order_perbaikan"
);
$st->bind_param("iiii", $id_divisi, $id_user, $id_user, $id_user);
$st->execute();
$jumlah = $st->get_result()->fetch_assoc();

/* =========================================================
   DAFTAR ORDER SESUAI TAB
========================================================= */
$tab = $_GET["tab"] ?? "tersedia";
if (!in_array($tab, ["tersedia", "saya", "selesai"], true)) {
    $tab = "tersedia";
}

$dasar =
    "SELECT o.id_order, o.nomor_order, o.judul, o.lokasi, o.deskripsi, o.foto,
            o.created_at, o.tanggal_diambil, o.tanggal_selesai, o.tipe_kerja, o.id_karyawan, o.status,
            p.nama AS nama_pelapor, COALESCE(NULLIF(p.departemen, ''), da.nama_divisi) AS divisi_asal
     FROM order_perbaikan o
     LEFT JOIN users p ON o.id_pemesan = p.id_user
     LEFT JOIN divisi da ON o.id_divisi_asal = da.id_divisi ";

if ($tab == "tersedia") {
    $st = $koneksi->prepare($dasar . "WHERE o.status = 'Menunggu' AND o.id_divisi = ? ORDER BY o.created_at ASC");
    $st->bind_param("i", $id_divisi);
} elseif ($tab == "saya") {
    $st = $koneksi->prepare($dasar . "WHERE o.status IN ('Diproses', 'Menunggu Konfirmasi') AND o.id_karyawan = ? ORDER BY o.tanggal_diambil DESC");
    $st->bind_param("i", $id_user);
} else {
    $st = $koneksi->prepare(
        $dasar . "WHERE o.status = 'Selesai'
                    AND (o.id_karyawan = ?
                         OR o.id_order IN (SELECT id_order FROM order_pekerja WHERE id_user = ?))
                  ORDER BY o.tanggal_selesai DESC"
    );
    $st->bind_param("ii", $id_user, $id_user);
}
$st->execute();
$daftar = $st->get_result();

/* Nama-nama pekerja per order (hanya untuk tab Selesai) */
$nama_pekerja = [];
if ($tab == "selesai") {
    $st = $koneksi->prepare(
        "SELECT op.id_order, u.nama
         FROM order_pekerja op
         JOIN users u ON u.id_user = op.id_user
         WHERE op.id_order IN (
             SELECT o.id_order FROM order_perbaikan o
             WHERE o.status = 'Selesai'
               AND (o.id_karyawan = ?
                    OR o.id_order IN (SELECT id_order FROM order_pekerja WHERE id_user = ?))
         )
         ORDER BY u.nama"
    );
    $st->bind_param("ii", $id_user, $id_user);
    $st->execute();
    $hasil_nama = $st->get_result();
    while ($rn = $hasil_nama->fetch_assoc()) {
        $nama_pekerja[(int) $rn["id_order"]][] = $rn["nama"];
    }
}

function format_tgl($t)
{
    return $t ? date("d/m/Y H:i", strtotime($t)) : "-";
}

function format_durasi($mulai, $akhir)
{
    if (!$mulai || !$akhir) {
        return "-";
    }
    $menit = max(0, (int) round((strtotime($akhir) - strtotime($mulai)) / 60));
    $jam = intdiv($menit, 60);
    $sisa = $menit % 60;
    if ($jam > 0) {
        return $jam . " jam " . $sisa . " menit";
    }
    return $sisa . " menit";
}

$teks_kosong = [
    "tersedia" => ["Belum ada order baru", "Order dari departemen lain yang ditujukan ke divisi Anda akan muncul di sini."],
    "saya"     => ["Tidak ada order yang sedang dikerjakan", "Ambil order dari tab Tersedia untuk mulai bekerja."],
    "selesai"  => ["Belum ada order yang selesai", "Order yang Anda selesaikan akan tercatat di sini."],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Masuk - SIMO</title>
<style>
  * { box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
  body { background: #f4f6f9; margin: 0; color: #2c3644; }
  a { text-decoration: none; color: inherit; }
  .content { padding: 26px 32px 40px; max-width: 1180px; }

  /* ---------- JUDUL HALAMAN ---------- */
  .page-header { margin-bottom: 22px; }
  .page-header h1 { font-size: 22px; color: #1c2636; margin: 0 0 5px; }
  .page-header p { font-size: 13px; color: #8b96a5; margin: 0; }

  /* ---------- TAB ---------- */
  .tabs { display: flex; gap: 6px; margin-bottom: 20px; padding: 5px; background: #fff; border: 1px solid #e6eaf0;
          border-radius: 12px; width: fit-content; max-width: 100%; overflow-x: auto; }
  .tab { display: flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 9px; font-size: 12px;
         font-weight: 600; color: #667085; text-decoration: none; white-space: nowrap; }
  .tab:hover { background: #f8f9fb; color: #1c2636; }
  .tab.aktif { background: #fdecee; color: #b51f2a; }
  .tab .jml { min-width: 19px; height: 19px; padding: 0 6px; border-radius: 20px; background: #eef0f3; color: #5b6676;
              font-size: 9.5px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
  .tab.aktif .jml { background: #b51f2a; color: #fff; }

  /* ---------- DAFTAR ORDER ---------- */
  .daftar { display: flex; flex-direction: column; gap: 14px; }
  .oc { display: flex; align-items: stretch; gap: 20px; padding: 18px; background: #fff; border: 1px solid #e6eaf0;
        border-radius: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
  .oc:hover { border-color: #dcc3c6; }

  .oc-foto { width: 110px; height: 110px; flex-shrink: 0; border-radius: 11px; overflow: hidden; background: #f1f3f6;
             border: 1px solid #e6eaf0; display: flex; align-items: center; justify-content: center; }
  .oc-foto img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; }
  .oc-foto svg { width: 34px; height: 34px; stroke: #b9c0cb; }

  .oc-isi { flex: 1; min-width: 0; }
  .oc-atas { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; flex-wrap: wrap; }
  .oc-nomor { font-size: 10.5px; font-weight: 700; color: #b51f2a; background: #fdecee; padding: 3px 10px; border-radius: 20px; }
  .oc-waktu { font-size: 11px; color: #8b96a5; }
  .oc-isi h4 { margin: 0 0 6px; font-size: 13.5px; font-weight: 700; color: #1c2636; }
  .oc-desk { margin: 0 0 12px; font-size: 13px; line-height: 1.55; color: #5b6676;
             display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .oc-meta { display: flex; flex-wrap: wrap; gap: 8px 26px; }
  .oc-meta div { font-size: 11.5px; color: #8b96a5; }
  .oc-meta b { color: #344054; font-weight: 600; }

  /* lencana tipe kerja */
  .tipe { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 700; }
  .tipe.individu { background: #eef0f3; color: #475467; }
  .tipe.tim { background: #e6effd; color: #1d4ed8; }

  .oc-aksi { display: flex; align-items: center; flex-shrink: 0; }
  .btn { padding: 10px 22px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;
         border: 0; white-space: nowrap; }
  .btn-ambil { background: #b51f2a; color: #fff; box-shadow: 0 1px 2px rgba(181,31,42,.3); }
  .btn-ambil:hover { background: #9f1c26; }

  /* tombol Kirim Bukti (sama seperti di Tugas Saya) */
  .btn-kirim { display: inline-flex; align-items: center; justify-content: center; padding: 10px 18px;
               background: #f9e8ea; color: #b51f2a; border-radius: 7px; font-size: 12px; font-weight: 600;
               white-space: nowrap; transition: background .15s ease; }
  .btn-kirim:hover { background: #f5dadd; color: #981923; }

  .lencana { padding: 7px 14px; border-radius: 20px; font-size: 10.5px; font-weight: 700; background: #e3f6ea; color: #1b6b3a; }

  .lencana.tunggu { background: #fff4e0; color: #9a5b00; }

  /* ---------- KOSONG ---------- */
  .kosong { padding: 56px 20px; text-align: center; background: #fff; border: 1px solid #e6eaf0; border-radius: 14px; }
  .kosong svg { width: 46px; height: 46px; stroke: #c4cad3; margin-bottom: 12px; }
  .kosong h4 { margin: 0 0 6px; font-size: 14px; color: #344054; }
  .kosong p { margin: 0; font-size: 12px; color: #8b96a5; }

  /* ---------- PESAN ---------- */
  .pesan { padding: 13px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px;
           background: #e8f7ee; color: #1b6b3a; border: 1px solid #bfe5cd; }
  .pesan.error { background: #fff1f2; color: #a32128; border-color: #efc9cc; }

  /* ---------- POP UP ---------- */
  .modal-latar { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center;
                 padding: 20px; background: rgba(16, 24, 40, .55); backdrop-filter: blur(2px); }
  .modal-latar.buka { display: flex; animation: latar .18s ease; }
  .modal { width: 100%; max-width: 410px; background: #fff; border-radius: 16px; padding: 30px 28px 24px;
           text-align: center; box-shadow: 0 20px 50px rgba(16, 24, 40, .25); animation: muncul .2s ease; }
  .modal-ikon { width: 58px; height: 58px; margin: 0 auto 16px; border-radius: 50%; display: flex;
                align-items: center; justify-content: center; background: #fdecee; }
  .modal-ikon svg { width: 26px; height: 26px; stroke: #b51f2a; }
  .modal h4 { margin: 0 0 8px; font-size: 16px; font-weight: 700; color: #1c2636; }
  .modal p { margin: 0; font-size: 12.5px; line-height: 1.6; color: #667085; }
  .modal p b { color: #1c2636; }
  .modal-aksi { display: flex; gap: 10px; margin-top: 22px; }
  .modal-aksi button { flex: 1; padding: 11px 16px; border-radius: 8px; font-size: 12px; font-weight: 600;
                       cursor: pointer; }
  .m-tidak { background: #fff; color: #344054; border: 1px solid #d5dbe4; }
  .m-tidak:hover { background: #f4f6f9; }
  .m-ya { color: #fff; border: 0; background: #b51f2a; }
  .m-ya:hover { background: #9f1c26; }
  @keyframes latar { from { opacity: 0; } to { opacity: 1; } }
  @keyframes muncul { from { opacity: 0; transform: scale(.94) translateY(8px); } to { opacity: 1; transform: none; } }

  .lightbox { position: fixed; inset: 0; z-index: 2100; display: none; align-items: center; justify-content: center;
              padding: 24px; background: rgba(10, 15, 25, .85); cursor: zoom-out; }
  .lightbox.buka { display: flex; }
  .lightbox img { max-width: 100%; max-height: 100%; border-radius: 10px; }

  /* ---------- DARK MODE ---------- */
  body.dark-mode .page-header h1 { color: #edf2f7; }
  body.dark-mode .page-header p { color: #8f9bac; }
  body.dark-mode .tabs, body.dark-mode .oc, body.dark-mode .kosong { background: #151b23 !important; border-color: #29313d !important; box-shadow: none; }
  body.dark-mode .tab { color: #9aa6b6; }
  body.dark-mode .tab:hover { background: #1a212b; color: #edf2f7; }
  body.dark-mode .tab.aktif { background: #302126; color: #f07a80; }
  body.dark-mode .tab .jml { background: #202733; color: #aab5c4; }
  body.dark-mode .tab.aktif .jml { background: #b51f2a; color: #fff; }
  body.dark-mode .oc:hover { border-color: #4a2a2e !important; }
  body.dark-mode .oc-foto { background: #1a212b; border-color: #29313d; }
  body.dark-mode .oc-nomor { background: #302126; color: #f07a80; }
  body.dark-mode .oc-isi h4 { color: #edf2f7; }
  body.dark-mode .oc-desk { color: #9aa6b6 !important; }
  body.dark-mode .oc-meta b { color: #c3ccd8; }
  body.dark-mode .tipe.individu { background: #202733; color: #aab5c4; }
  body.dark-mode .tipe.tim { background: #1b2a47; color: #8fb4ff; }
  body.dark-mode .btn-kirim { background: #382326; color: #f07a80; }
  body.dark-mode .btn-kirim:hover { background: #4a292d; color: #ff9298; }
  body.dark-mode .lencana { background: #15301f; color: #7fd9a0; }
  body.dark-mode .lencana.tunggu { background: #332a17; color: #f0c36d; }
  body.dark-mode .kosong h4 { color: #c3ccd8; }
  body.dark-mode .pesan { background: #1a2b22; color: #8fd9ab; border-color: #284a37; }
  body.dark-mode .pesan.error { background: #2b1a1d; color: #f07a80; border-color: #4a2a2e; }
  body.dark-mode .modal { background: #151b23; box-shadow: 0 20px 50px rgba(0,0,0,.6); }
  body.dark-mode .modal-ikon { background: #302126; }
  body.dark-mode .modal h4, body.dark-mode .modal p b { color: #edf2f7; }
  body.dark-mode .modal p { color: #9aa6b6 !important; }
  body.dark-mode .m-tidak { background: #202733; color: #c3ccd8; border-color: #353e4c; }

  @media (max-width: 800px) {
    .content { padding: 18px 14px 32px; }
    .oc { flex-direction: column; gap: 14px; }
    .oc-foto { width: 100%; height: 170px; }
    .oc-aksi { width: 100%; }
    .oc-aksi .btn, .oc-aksi .btn-kirim { width: 100%; }
  }
</style>
</head>
<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<div class="main">

  <?php include "../includes/karyawan/topbar.php"; ?>

  <div class="content">

    <section class="page-header">
      <h1>Order Masuk</h1>
      <p>Daftar order perbaikan untuk divisi Anda.</p>
    </section>

    <?php if ($pesan != ""): ?>
      <div class="pesan <?= $pesan_error ? 'error' : '' ?>"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <!-- TAB -->
    <div class="tabs">
      <a href="order_masuk.php?tab=tersedia" class="tab <?= $tab == 'tersedia' ? 'aktif' : '' ?>">
        Tersedia <span class="jml"><?= (int) $jumlah["tersedia"] ?></span>
      </a>
      <a href="order_masuk.php?tab=saya" class="tab <?= $tab == 'saya' ? 'aktif' : '' ?>">
        Saya Kerjakan <span class="jml"><?= (int) $jumlah["saya"] ?></span>
      </a>
      <a href="order_masuk.php?tab=selesai" class="tab <?= $tab == 'selesai' ? 'aktif' : '' ?>">
        Selesai <span class="jml"><?= (int) $jumlah["selesai"] ?></span>
      </a>
    </div>

    <!-- DAFTAR -->
    <?php if ($daftar->num_rows == 0): ?>
      <div class="kosong">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"></path></svg>
        <h4><?= $teks_kosong[$tab][0] ?></h4>
        <p><?= $teks_kosong[$tab][1] ?></p>
      </div>
    <?php else: ?>
      <div class="daftar">
        <?php while ($r = $daftar->fetch_assoc()): ?>
          <div class="oc">

            <div class="oc-foto">
              <?php if (!empty($r["foto"])): ?>
                <img src="../uploads/order/<?= htmlspecialchars(basename($r["foto"])) ?>" alt="Foto kerusakan"
                     data-foto="../uploads/order/<?= htmlspecialchars(basename($r["foto"])) ?>">
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="9" r="1.5"></circle><path d="M4 16l4-4 4 4 3-3 5 5"></path></svg>
              <?php endif; ?>
            </div>

            <div class="oc-isi">
              <div class="oc-atas">
                <span class="oc-nomor"><?= htmlspecialchars(!empty($r["nomor_order"]) ? $r["nomor_order"] : "Belum ada nomor") ?></span>
                <span class="oc-waktu">Masuk <?= format_tgl($r["created_at"]) ?></span>
              </div>
              <h4><?= htmlspecialchars($r["judul"]) ?></h4>
              <p class="oc-desk"><?= htmlspecialchars($r["deskripsi"]) ?></p>
              <div class="oc-meta">
                <div>Pelapor: <b><?= htmlspecialchars($r["nama_pelapor"] ?? "-") ?></b></div>
                <div>Departemen: <b><?= htmlspecialchars($r["divisi_asal"] ?? "-") ?></b></div>
                <div>Lokasi: <b><?= htmlspecialchars($r["lokasi"] !== "" ? $r["lokasi"] : "-") ?></b></div>
                <?php if ($tab == "saya"): ?>
                  <div>Diambil: <b><?= format_tgl($r["tanggal_diambil"]) ?></b></div>
                <?php elseif ($tab == "selesai"): ?>
                  <?php
                    $tipe_kerja = $r["tipe_kerja"] ?: "Individu";
                    $kelas_tipe = $tipe_kerja == "Tim" ? "tim" : "individu";
                    $anggota    = $nama_pekerja[(int) $r["id_order"]] ?? [];
                  ?>
                  <div>Diambil: <b><?= format_tgl($r["tanggal_diambil"]) ?></b></div>
                  <div>Selesai: <b><?= format_tgl($r["tanggal_selesai"]) ?></b></div>
                  <div>Durasi: <b><?= format_durasi($r["tanggal_diambil"], $r["tanggal_selesai"]) ?></b></div>
                  <div>Tipe: <span class="tipe <?= $kelas_tipe ?>"><?= htmlspecialchars($tipe_kerja) ?></span></div>
                  <?php if ($tipe_kerja == "Tim" && !empty($anggota)): ?>
                    <div>Pekerja: <b><?= htmlspecialchars(implode(", ", $anggota)) ?></b></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="oc-aksi">
              <?php if ($tab == "tersedia"): ?>
                <button type="button" class="btn btn-ambil aksi-btn" data-aksi="ambil"
                        data-id="<?= (int) $r["id_order"] ?>"
                        data-nomor="<?= htmlspecialchars($r["nomor_order"] ?? "") ?>"
                        data-judul="<?= htmlspecialchars($r["judul"]) ?>">Ambil Order</button>
              <?php elseif ($tab == "saya"): ?>
                <?php if ($r["status"] == "Menunggu Konfirmasi"): ?>
                  <span class="lencana tunggu">Menunggu Konfirmasi</span>
                <?php else: ?>
                  <a href="kirim_bukti.php?id=<?= (int) $r["id_order"] ?>&tipe=order" class="btn-kirim">Kirim Bukti</a>
                <?php endif; ?>
              <?php else: ?>
                <span class="lencana">Selesai</span>
              <?php endif; ?>
            </div>

          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>

  </div>

</div>

<!-- POP UP KONFIRMASI AMBIL ORDER -->
<div class="modal-latar" id="modalAksi">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mJudul">
    <div class="modal-ikon">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"></path></svg>
    </div>
    <h4 id="mJudul">Ambil order ini?</h4>
    <p id="mTeks"></p>
    <form method="post" class="modal-aksi">
      <input type="hidden" name="aksi" value="ambil">
      <input type="hidden" name="id_order" id="mId">
      <button type="button" class="m-tidak" id="mTutup">Kembali</button>
      <button type="submit" class="m-ya">Ya, Ambil</button>
    </form>
  </div>
</div>

<!-- PERBESAR FOTO -->
<div class="lightbox" id="lightbox"><img id="lightboxImg" alt="Foto kerusakan"></div>

<script>
(function () {
    var latar = document.getElementById("modalAksi");

    function tutup() { latar.classList.remove("buka"); }

    document.querySelectorAll(".aksi-btn").forEach(function (tombol) {
        tombol.addEventListener("click", function () {
            document.getElementById("mId").value = tombol.dataset.id;

            var teks = document.getElementById("mTeks");
            teks.innerHTML = "";
            teks.appendChild(document.createTextNode("Order "));
            var b = document.createElement("b");
            b.textContent = tombol.dataset.nomor
                ? tombol.dataset.nomor + " (" + (tombol.dataset.judul || "") + ")"
                : (tombol.dataset.judul || "");
            teks.appendChild(b);
            teks.appendChild(document.createTextNode(
                " akan Anda kerjakan. Setelah diambil, order tidak lagi tersedia untuk karyawan lain."
            ));

            latar.classList.add("buka");
        });
    });

    document.getElementById("mTutup").addEventListener("click", tutup);
    latar.addEventListener("click", function (e) { if (e.target === latar) tutup(); });

    /* Perbesar foto */
    var lb = document.getElementById("lightbox");
    document.querySelectorAll(".oc-foto img").forEach(function (img) {
        img.addEventListener("click", function () {
            document.getElementById("lightboxImg").src = img.dataset.foto;
            lb.classList.add("buka");
        });
    });
    lb.addEventListener("click", function () { lb.classList.remove("buka"); });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { tutup(); lb.classList.remove("buka"); }
    });
})();
</script>

</body>
</html>