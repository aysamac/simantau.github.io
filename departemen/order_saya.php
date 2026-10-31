<?php
include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Departemen") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

// Pesan dari aksi sebelumnya
$pesan = $_SESSION["flash"] ?? "";
$pesan_error = !empty($_SESSION["flash_error"]);
unset($_SESSION["flash"], $_SESSION["flash_error"]);

// Pelapor menghapus order miliknya sendiri (semua status boleh dihapus)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["batalkan"])) {
    $id_batal = (int) $_POST["batalkan"];

    // Ambil nama foto dulu supaya filenya ikut dihapus
    $st = $koneksi->prepare(
        "SELECT foto FROM order_perbaikan
         WHERE id_order = ? AND id_pemesan = ?"
    );
    $st->bind_param("ii", $id_batal, $id_user);
    $st->execute();
    $baris_batal = $st->get_result()->fetch_assoc();

    $st = $koneksi->prepare(
        "DELETE FROM order_perbaikan
         WHERE id_order = ? AND id_pemesan = ?"
    );
    $st->bind_param("ii", $id_batal, $id_user);
    $st->execute();

    if ($st->affected_rows > 0) {
        if (!empty($baris_batal["foto"])) {
            $file_foto = __DIR__ . "/../uploads/order/" . basename($baris_batal["foto"]);
            if (is_file($file_foto)) {
                unlink($file_foto);
            }
        }
        $_SESSION["flash"] = "Order berhasil dihapus.";
    } else {
        $_SESSION["flash"] = "Order tidak ditemukan atau bukan milik Anda.";
        $_SESSION["flash_error"] = true;
    }
    header("Location: order_saya.php");
    exit;
}

$st = $koneksi->prepare(
    "SELECT o.id_order, o.nomor_order, o.judul, o.lokasi, o.status, o.alasan, o.created_at, o.tanggal_diambil AS waktu_mulai, COALESCE(o.tanggal_selesai, o.waktu_selesai) AS waktu_selesai, d.nama_divisi, k.nama AS nama_karyawan
     FROM order_perbaikan o
     LEFT JOIN divisi d ON o.id_divisi = d.id_divisi
     LEFT JOIN users k ON o.id_karyawan = k.id_user
     WHERE o.id_pemesan = ?
     ORDER BY o.id_order DESC"
);
$st->bind_param("i", $id_user);
$st->execute();
$hasil = $st->get_result();
$total_order = $hasil->num_rows;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Saya - SIMO</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; background: #f4f6f9; margin: 0; color: #263345; }
  a { text-decoration: none; color: inherit; }
  .content { padding: 28px 32px 40px; }

  /* JUDUL HALAMAN */
  .page-title { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
  .page-title h2 { margin: 0 0 4px; font-size: 24px; font-weight: 700; color: #1c2636; }
  .page-title p { margin: 0; font-size: 14px; color: #7e8998; }
  .btn-order { display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; border-radius: 9px;
               background: #b51f2a; color: #fff; font-weight: 600; font-size: 13px;
               box-shadow: 0 4px 12px rgba(181, 31, 42, .25); transition: .15s; }
  .btn-order:hover { background: #9f1c26; transform: translateY(-1px); }
  .btn-order svg { width: 15px; height: 15px; stroke: #fff; }

  /* CARD TABEL */
  .panel { background: #fff; border: 1px solid #e6eaf0; border-radius: 14px; overflow: hidden;
           box-shadow: 0 3px 14px rgba(20, 40, 70, .05); }
  .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px;
                padding: 16px 22px; border-bottom: 1px solid #eef0f3; }
  .panel-head h4 { margin: 0; font-size: 15px; font-weight: 700; color: #1c2636; }
  .total { padding: 4px 12px; border-radius: 20px; background: #fdecee; color: #b51f2a; font-size: 12px; font-weight: 700; }

  .scroll { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; }

  /* HEADER TABEL MERAH */
  thead th { text-align: left; padding: 14px 22px; background: #b51f2a; color: #fff; font-size: 11.5px;
             font-weight: 700; text-transform: uppercase; letter-spacing: .8px; white-space: nowrap; }

  td { padding: 15px 22px; border-bottom: 1px solid #eef0f3; color: #344054; white-space: nowrap; vertical-align: middle; }
  tbody tr { transition: background .12s; }
  tbody tr:nth-child(even) td { background: #fbfcfd; }
  tbody tr:hover td { background: #fff5f6; }
  tbody tr:last-child td { border-bottom: 0; }

  .nomor a { display: inline-block; padding: 4px 11px; border-radius: 8px; background: #fdecee; color: #b51f2a;
             font-weight: 700; font-size: 12.5px; }
  .nomor a:hover { background: #b51f2a; color: #fff; }
  .nama { font-weight: 600; color: #1c2636; }
  .muted { color: #98a2b3; }
  .tgl b { display: block; font-weight: 600; color: #344054; }
  .tgl span { font-size: 12px; color: #98a2b3; }
  .waktu b { display: block; font-weight: 600; color: #344054; }
  .waktu span { font-size: 12px; color: #98a2b3; }

  .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px;
           font-size: 12px; font-weight: 600; }
  .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .Menunggu { background: #fff4e0; color: #a86400; }
  .MenungguKonfirmasi { background: #fff4e0; color: #a86400; }
  .Diproses { background: #e8f0ff; color: #1c4fa8; }
  .Selesai  { background: #e3f6ea; color: #1b6b3a; }
  .Ditolak  { background: #fde8ea; color: #a32128; }
  .Dibatalkan { background: #eef0f3; color: #475467; }
  .alasan { margin-top: 6px; font-size: 12px; color: #8b96a5; white-space: normal; max-width: 220px; }

  .aksi { display: flex; align-items: center; gap: 8px; }
  .btn-detail, .btn-edit, .btn-batal { display: inline-flex; align-items: center; justify-content: center;
                width: 34px; height: 34px; padding: 0; border-radius: 8px; cursor: pointer;
                font-family: inherit; transition: .15s; }
  .btn-detail svg, .btn-edit svg, .btn-batal svg { width: 16px; height: 16px; stroke: currentColor; fill: none;
                stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
  .btn-detail { border: 1px solid #d5dbe4; background: #fff; color: #344054; }
  .btn-detail:hover { background: #b51f2a; border-color: #b51f2a; color: #fff; }
  .btn-edit { border: 1px solid #c9d6f2; background: #fff; color: #1c4fa8; }
  .btn-edit:hover { background: #e8f0ff; }
  .btn-batal { border: 1px solid #efc9cc; background: #fff; color: #a32128; }
  .btn-batal:hover { background: #fff1f2; }

  /* POP UP HAPUS */
  .modal-latar { position: fixed; inset: 0; z-index: 100; display: none; align-items: center; justify-content: center;
                 padding: 20px; background: rgba(16, 24, 40, .55); backdrop-filter: blur(2px); }
  .modal-latar.buka { display: flex; animation: latar .18s ease; }
  .modal { width: 100%; max-width: 410px; background: #fff; border-radius: 16px; padding: 30px 28px 24px;
           text-align: center; box-shadow: 0 20px 50px rgba(16, 24, 40, .25); animation: muncul .2s ease; }
  .modal-ikon { width: 58px; height: 58px; margin: 0 auto 16px; border-radius: 50%; background: #fdecee;
                display: flex; align-items: center; justify-content: center; }
  .modal-ikon svg { width: 26px; height: 26px; stroke: #b51f2a; }
  .modal h4 { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #1c2636; }
  .modal p { margin: 0; font-size: 13.5px; line-height: 1.6; color: #667085; }
  .modal p b { color: #1c2636; }
  .modal .peringatan { margin-top: 14px; padding: 10px 12px; border-radius: 9px; background: #fff7e6;
                       border: 1px solid #fde3b0; color: #8a5a00; font-size: 12.5px; }
  .modal-aksi { display: flex; gap: 10px; margin-top: 22px; }
  .modal-aksi button { flex: 1; padding: 11px 16px; border-radius: 10px; font-size: 14px; font-weight: 600;
                       cursor: pointer; font-family: inherit; }
  .m-tidak { background: #fff; color: #344054; border: 1px solid #d5dbe4; }
  .m-tidak:hover { background: #f4f6f9; }
  .m-ya { background: #b51f2a; color: #fff; border: 0; }
  .m-ya:hover { background: #9f1c26; }
  @keyframes latar { from { opacity: 0; } to { opacity: 1; } }
  @keyframes muncul { from { opacity: 0; transform: scale(.94) translateY(8px); } to { opacity: 1; transform: none; } }

  .pesan { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding: 13px 16px;
           border-radius: 10px; font-size: 13.5px; background: #e8f7ee; color: #1b6b3a; border: 1px solid #bfe5cd; }
  .pesan.error { background: #fff1f2; color: #a32128; border-color: #efc9cc; }

  .kosong { padding: 56px 22px; text-align: center; color: #8b96a5; font-size: 14px; }
  .kosong-ikon { width: 60px; height: 60px; margin: 0 auto 14px; border-radius: 50%; background: #fdecee;
                 display: flex; align-items: center; justify-content: center; }
  .kosong-ikon svg { width: 28px; height: 28px; stroke: #b51f2a; }

  /* ---------- DARK MODE ---------- */
  body.dark-mode { color: #c3ccd8; }
  body.dark-mode .page-title h2 { color: #edf2f7; }
  body.dark-mode .page-title p { color: #929baa; }
  body.dark-mode .panel { background: #151b23; border-color: #29313d; box-shadow: none; }
  body.dark-mode .panel-head { border-bottom-color: #29313d; }
  body.dark-mode .panel-head h4, body.dark-mode .nama { color: #edf2f7; }
  body.dark-mode .total { background: #302126; color: #f07a80; }
  body.dark-mode thead th { background: #8f1a24; color: #fff; }
  body.dark-mode td { border-bottom-color: #29313d; color: #c3ccd8; }
  body.dark-mode tbody tr:nth-child(even) td { background: #181f28; }
  body.dark-mode tbody tr:hover td { background: #231b20; }
  body.dark-mode .nomor a { background: #302126; color: #f07a80; }
  body.dark-mode .nomor a:hover { background: #b51f2a; color: #fff; }
  body.dark-mode .tgl b, body.dark-mode .waktu b { color: #c3ccd8; }
  body.dark-mode .Menunggu, body.dark-mode .MenungguKonfirmasi { background: #3a2e14; color: #f0b45a; }
  body.dark-mode .Diproses { background: #172a4a; color: #7fb0ff; }
  body.dark-mode .Selesai  { background: #15301f; color: #7fd9a0; }
  body.dark-mode .Ditolak  { background: #3a1a1e; color: #f07a80; }
  body.dark-mode .Dibatalkan { background: #202733; color: #aab5c4; }
  body.dark-mode .btn-detail { background: #202733; border-color: #353e4c; color: #c3ccd8; }
  body.dark-mode .btn-detail:hover { background: #b51f2a; border-color: #b51f2a; color: #fff; }
  body.dark-mode .btn-edit { background: #202733; border-color: #2f4268; color: #7fb0ff; }
  body.dark-mode .btn-edit:hover { background: #172a4a; }
  body.dark-mode .btn-batal { background: #202733; border-color: #4a2a2e; color: #f07a80; }
  body.dark-mode .btn-batal:hover { background: #2b1a1d; }
  body.dark-mode .kosong-ikon { background: #302126; }
  body.dark-mode .modal { background: #151b23; box-shadow: 0 20px 50px rgba(0, 0, 0, .6); }
  body.dark-mode .modal-ikon { background: #302126; }
  body.dark-mode .modal h4, body.dark-mode .modal p b { color: #edf2f7; }
  body.dark-mode .modal p { color: #9aa6b6; }
  body.dark-mode .modal .peringatan { background: #3a2e14; border-color: #5a4720; color: #f0b45a; }
  body.dark-mode .m-tidak { background: #202733; color: #c3ccd8; border-color: #353e4c; }
  body.dark-mode .pesan { background: #1a2b22; color: #8fd9ab; border-color: #284a37; }
  body.dark-mode .pesan.error { background: #2b1a1d; color: #f07a80; border-color: #4a2a2e; }

  /* ---------- RESPONSIVE ---------- */
  /* Laptop kecil / tablet landscape */
  @media (max-width: 1100px) {
    .content { padding: 22px 20px 36px; }
    thead th, td { padding-left: 14px; padding-right: 14px; }
    table { font-size: 13px; }
  }

  /* Tablet portrait & HP: tabel berubah jadi kartu ringkas */
  @media (max-width: 800px) {
    .content { padding: 14px 12px 28px; }

    /* judul + tombol Buat Order sejajar, tombol kecil */
    .page-title { flex-wrap: nowrap; align-items: center; gap: 10px; margin-bottom: 14px; }
    .page-title h2 { font-size: 20px; margin-bottom: 2px; }
    .page-title p { font-size: 12px; line-height: 1.4; }
    .btn-order { flex-shrink: 0; width: auto; padding: 9px 13px; font-size: 12.5px; box-shadow: none; }

    /* "Daftar Order / 2 order" disembunyikan di HP supaya tidak dobel */
    .panel-head { display: none; }
    .panel { background: transparent; border: 0; box-shadow: none; overflow: visible; }
    .scroll { overflow: visible; }

    table, tbody, tr, td { display: block; width: auto; }
    thead { display: none; }

    tbody tr { position: relative; display: grid; grid-template-columns: 1fr 1fr; background: #fff;
               border: 1px solid #e6eaf0; border-radius: 14px; margin-bottom: 12px; overflow: hidden;
               box-shadow: 0 2px 10px rgba(20, 40, 70, .06); }
    tbody tr:hover td, tbody tr:nth-child(even) td { background: transparent; }

    td { padding: 0 16px 12px; border-bottom: 0; text-align: left; white-space: normal; min-width: 0; }
    td::before { content: attr(data-label); display: block; margin-bottom: 3px; font-size: 10.5px; font-weight: 700;
                 text-transform: uppercase; letter-spacing: .6px; color: #98a2b3; }

    /* kepala kartu merah: nomor di kiri, tanggal di kanan */
    td.nomor { grid-column: 1 / -1; display: flex; align-items: center; height: 50px; padding: 0 16px;
               margin-bottom: 12px; background: #b51f2a !important; }
    td.nomor::before { display: none; }
    td.nomor a { padding: 0; background: none; color: #fff; font-size: 14px; letter-spacing: .3px; }
    td.nomor a:hover { background: none; color: #fff; text-decoration: underline; }

    td.tgl { position: absolute; top: 0; right: 0; height: 50px; padding: 0 16px; display: flex;
             align-items: center; gap: 5px; background: transparent !important; }
    td.tgl::before { display: none; }
    td.tgl b { display: inline; color: #fff; font-size: 12px; }
    td.tgl span { color: rgba(255, 255, 255, .75); font-size: 12px; }
    td.tgl span::before { content: "·  "; }

    /* nama peralatan jadi judul kartu */
    td.nama { grid-column: 1 / -1; font-size: 16px; }
    td.nama::before { display: none; }

    .alasan { max-width: none; }

    td.td-aksi { grid-column: 1 / -1; padding: 12px 16px; border-top: 1px solid #eef0f3; background: #fafbfc; }
    td.td-aksi::before { display: none; }
    .aksi { width: 100%; }
    .aksi { justify-content: flex-end; }

    /* dark mode untuk kartu */
    body.dark-mode tbody tr { background: #151b23; border-color: #29313d; box-shadow: none; }
    body.dark-mode td.nomor { background: #8f1a24 !important; }
    body.dark-mode td.nomor a { background: none; color: #fff; }
    body.dark-mode td.tgl b { color: #fff; }
    body.dark-mode td.td-aksi { background: #181f28; border-top-color: #29313d; }
  }

  /* HP kecil */
  @media (max-width: 420px) {
    .modal { padding: 24px 18px 18px; }
    .modal-aksi { flex-direction: column-reverse; }
  }
</style>
</head>
<body>

<?php include "../includes/departemen/sidebar.php"; ?>

<div class="main">

  <?php include "../includes/departemen/topbar.php"; ?>

  <div class="content">

    <div class="page-title">
      <div>
        <h2>Order Saya</h2>
        <p>Pantau semua order perbaikan yang Anda buat.</p>
      </div>
      <a href="buat_order.php" class="btn-order">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        Buat Order
      </a>
    </div>

    <?php if ($pesan != ""): ?>
      <div class="pesan <?= $pesan_error ? 'error' : '' ?>"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-head">
        <h4>Daftar Order</h4>
        <span class="total"><?= (int) $total_order ?> order</span>
      </div>

      <?php if ($total_order == 0): ?>
        <div class="kosong">
          <div class="kosong-ikon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V3h6v1"></path><path d="M9 11h6"></path><path d="M9 15h4"></path></svg>
          </div>
          Belum ada order. Klik "Buat Order" untuk membuat order pertama.
        </div>
      <?php else: ?>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th>Nomor Order</th>
                <th>Nama Peralatan</th>
                <th>Divisi Tujuan</th>
                <th>Detail Lokasi</th>
                <th>Status</th>
                <th>Dikerjakan Oleh</th>
                <th>Mulai</th>
                <th>Selesai</th>
                <th>Tanggal Order</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($r = $hasil->fetch_assoc()): ?>
                <tr>
                  <td class="nomor" data-label="Nomor Order">
                    <a href="detail_order.php?id=<?= (int) $r["id_order"] ?>"><?= htmlspecialchars(!empty($r["nomor_order"]) ? $r["nomor_order"] : "Belum ada nomor") ?></a>
                  </td>
                  <td class="nama" data-label="Nama Peralatan"><?= htmlspecialchars($r["judul"]) ?></td>
                  <td data-label="Divisi Tujuan"><?= htmlspecialchars($r["nama_divisi"] ?? "-") ?></td>
                  <td data-label="Detail Lokasi"><?= htmlspecialchars(($r["lokasi"] ?? "") !== "" ? $r["lokasi"] : "-") ?></td>
                  <td data-label="Status">
                    <span class="badge <?= htmlspecialchars(str_replace(" ", "", $r["status"])) ?>"><?= htmlspecialchars($r["status"]) ?></span>
                    <?php if ($r["status"] == "Ditolak" && !empty($r["alasan"])): ?>
                      <div class="alasan"><?= htmlspecialchars($r["alasan"]) ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Dikerjakan Oleh">
                    <?php if (!empty($r["nama_karyawan"])): ?>
                      <?= htmlspecialchars($r["nama_karyawan"]) ?>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="waktu" data-label="Mulai">
                    <?php if (!empty($r["waktu_mulai"])): ?>
                      <b><?= date("d/m/Y", strtotime($r["waktu_mulai"])) ?></b>
                      <span><?= date("H:i", strtotime($r["waktu_mulai"])) ?></span>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="waktu" data-label="Selesai">
                    <?php if (!empty($r["waktu_selesai"])): ?>
                      <b><?= date("d/m/Y", strtotime($r["waktu_selesai"])) ?></b>
                      <span><?= date("H:i", strtotime($r["waktu_selesai"])) ?></span>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="tgl" data-label="Tanggal">
                    <b><?= date("d/m/Y", strtotime($r["created_at"])) ?></b>
                    <span><?= date("H:i", strtotime($r["created_at"])) ?></span>
                  </td>
                  <td class="td-aksi" data-label="Aksi">
                    <div class="aksi">
                      <!-- Detail: selalu tampil di semua status -->
                      <a href="detail_order.php?id=<?= (int) $r["id_order"] ?>" class="btn-detail" title="Detail" aria-label="Detail">
                        <svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                      </a>

                      <!-- Edit: tampil di semua status kecuali Selesai -->
                      <?php if ($r["status"] != "Selesai"): ?>
                        <a href="edit_order.php?id=<?= (int) $r["id_order"] ?>" class="btn-edit" title="Edit" aria-label="Edit">
                          <svg viewBox="0 0 24 24"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path></svg>
                        </a>
                      <?php endif; ?>

                      <!-- Hapus: tampil di semua status -->
                      <button type="button" class="btn-batal" title="Hapus" aria-label="Hapus"
                              data-id="<?= (int) $r["id_order"] ?>"
                              data-nomor="<?= htmlspecialchars(!empty($r["nomor_order"]) ? $r["nomor_order"] : "tanpa nomor") ?>"
                              data-judul="<?= htmlspecialchars($r["judul"]) ?>">
                        <svg viewBox="0 0 24 24"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M6 6l1 14h10l1-14"></path><path d="M10 11v5"></path><path d="M14 11v5"></path></svg>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- POP UP KONFIRMASI HAPUS -->
<div class="modal-latar" id="modalBatal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mJudulModal">
    <div class="modal-ikon">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M6 6l1 14h10l1-14"></path><path d="M10 11v5"></path><path d="M14 11v5"></path></svg>
    </div>
    <h4 id="mJudulModal">Hapus order ini?</h4>
    <p>Order <b id="mNomor"></b> (<span id="mNama"></span>) akan dihapus.</p>
    <div class="peringatan">Data order dihapus permanen dan tidak dapat dikembalikan.</div>
    <form method="post" class="modal-aksi">
      <input type="hidden" name="batalkan" id="mId">
      <button type="button" class="m-tidak" id="mTutup">Tidak, kembali</button>
      <button type="submit" class="m-ya">Ya, Hapus</button>
    </form>
  </div>
</div>

<script>
(function () {
    var latar = document.getElementById("modalBatal");

    function tutup() { latar.classList.remove("buka"); }

    document.querySelectorAll(".btn-batal").forEach(function (tombol) {
        tombol.addEventListener("click", function () {
            document.getElementById("mId").value = tombol.dataset.id;
            document.getElementById("mNomor").textContent = tombol.dataset.nomor || "";
            document.getElementById("mNama").textContent = tombol.dataset.judul || "";
            latar.classList.add("buka");
        });
    });

    document.getElementById("mTutup").addEventListener("click", tutup);
    latar.addEventListener("click", function (e) { if (e.target === latar) tutup(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") tutup(); });
})();
</script>

</body>
</html>