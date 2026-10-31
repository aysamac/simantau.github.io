<?php
include "../config/session.php";

date_default_timezone_set("Asia/Jakarta");

// Hanya role Departemen yang boleh masuk
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Departemen") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

// Nama departemen akun ini (mis. PMD)
$st0 = $koneksi->prepare("SELECT departemen FROM users WHERE id_user = ?");
$st0->bind_param("i", $id_user);
$st0->execute();
$nama_dep = trim($st0->get_result()->fetch_assoc()["departemen"] ?? "");

// Hitung order per status
$jumlah = ["Menunggu" => 0, "Diproses" => 0, "Selesai" => 0, "Ditolak" => 0];

$st = $koneksi->prepare(
    "SELECT status, COUNT(*) AS jml
     FROM order_perbaikan
     WHERE id_pemesan = ?
     GROUP BY status"
);
$st->bind_param("i", $id_user);
$st->execute();
$hasil = $st->get_result();
while ($r = $hasil->fetch_assoc()) {
    $jumlah[$r["status"]] = (int) $r["jml"];
}
$total = array_sum($jumlah);
$persen_selesai = $total > 0 ? round($jumlah["Selesai"] / $total * 100) : 0;

// 5 order terbaru
$st2 = $koneksi->prepare(
    "SELECT id_order, nomor_order, judul, lokasi, status, created_at
     FROM order_perbaikan
     WHERE id_pemesan = ?
     ORDER BY id_order DESC
     LIMIT 5"
);
$st2->bind_param("i", $id_user);
$st2->execute();
$terbaru = $st2->get_result();

// Tanggal hari ini (bahasa Indonesia)
$hari  = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
$bulan = [1 => "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli",
          "Agustus", "September", "Oktober", "November", "Desember"];
$tanggal_hari_ini = $hari[(int) date("w")] . ", " . date("j") . " " . $bulan[(int) date("n")] . " " . date("Y");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - SIMO</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; background: #fff; color: #172033; margin: 0; }
  a { text-decoration: none; color: inherit; }
  .content { padding: 24px 26px 36px; }

  /* ---------- HEADER ---------- */
  .head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
  .head h1 { margin: 0; font-size: 28px; font-weight: 700; line-height: 1.2; color: #172033; }
  .head p { margin: 4px 0 0; font-size: 13px; color: #7a8596; }
  .date-box { background: #fff; border: 1px solid #d9dde2; border-radius: 10px; padding: 10px 14px;
              font-size: 13px; color: #667085; white-space: nowrap; }

  /* ---------- KARTU (gaya Super Admin) ---------- */
  .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
  .kpi-card { background: #fff; border: 1px solid #f0c3c7; border-radius: 12px; padding: 16px 18px;
              height: 108px; display: flex; flex-direction: column; justify-content: space-between;
              box-shadow: 0 1px 4px rgba(16, 24, 40, .04);
              transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
  .kpi-card:hover { transform: translateY(-2px); border-color: #e5a0a6; box-shadow: 0 5px 14px rgba(16, 24, 40, .08); }
  .kpi-top { display: flex; align-items: center; justify-content: space-between; }
  .kpi-label { font-size: 14px; font-weight: 500; color: #344054; }
  .kpi-icon { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; color: #b51f2a; }
  .kpi-icon svg { width: 21px; height: 21px; }
  .kpi-bottom { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; }
  .kpi-number { font-size: 28px; font-weight: 700; color: #172033; line-height: 1; letter-spacing: -.5px; }
  .kpi-foot { font-size: 11px; color: #7a8596; margin-bottom: 1px; white-space: nowrap; }
  .kpi-foot .up { color: #b51f2a; font-weight: 600; }

  /* ---------- PANEL ---------- */
  .card { background: #fff; border: 1px solid #d9dde2; border-radius: 14px; overflow: hidden;
          box-shadow: 0 1px 3px rgba(16, 24, 40, .04); }
  .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px;
               padding: 18px 20px 14px; }
  .card-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: #172033; }
  .card-head p { margin: 3px 0 0; font-size: 12px; color: #8b96a5; }
  .aksi { display: flex; align-items: center; gap: 16px; }
  .link-semua { font-size: 13px; color: #b51f2a; font-weight: 600; }
  .link-semua:hover { text-decoration: underline; }
  .btn-order { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 8px;
               background: #b51f2a; color: #fff; font-weight: 600; font-size: 13px; }
  .btn-order:hover { background: #9f1c26; }
  .btn-order svg { width: 15px; height: 15px; stroke: #fff; }

  /* ---------- TABEL (header merah) ---------- */
  .scroll { overflow-x: auto; padding: 0 20px 14px; }
  table { width: 100%; border-collapse: collapse; min-width: 720px; }
  th { background: #b51f2a; color: #fff; font-size: 12px; font-weight: 600; text-align: left;
       padding: 10px 12px; border-bottom: 1px solid #b51f2a; white-space: nowrap; }
  th:first-child { border-top-left-radius: 8px; }
  th:last-child { border-top-right-radius: 8px; }
  td { font-size: 13.5px; color: #172033; padding: 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; }
  tr:last-child td { border-bottom: 0; }
  tbody tr:hover td { background: #fafbfc; }
  .nomor { font-weight: 600; white-space: nowrap; }
  .nomor a:hover { color: #b51f2a; text-decoration: underline; }
  .nowrap { white-space: nowrap; }

  .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 20px;
           font-size: 12px; font-weight: 600; white-space: nowrap; }
  .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .Menunggu { background: #fff4e0; color: #a86400; }
  .Diproses { background: #e8f0ff; color: #1c4fa8; }
  .Selesai  { background: #e3f6ea; color: #1b6b3a; }
  .Ditolak  { background: #fde8ea; color: #a32128; }
  .kosong { padding: 42px 22px 46px; text-align: center; color: #8b96a5; font-size: 14px; }

  /* ---------- DARK MODE (sama dengan Super Admin) ---------- */
  html:has(body.dark-mode), html:has(body.dark-mode) body { background: #0f141b !important; }
  html body.dark-mode { background: #0f141b !important; color: #e8edf4; }
  html body.dark-mode .head h1, html body.dark-mode .card-head h2,
  html body.dark-mode .kpi-label, html body.dark-mode .kpi-number { color: #edf2f7; }
  html body.dark-mode .head p, html body.dark-mode .card-head p, html body.dark-mode .kpi-foot { color: #8f9bac; }
  html body.dark-mode .date-box { background: #151b23; border-color: #2c3542; color: #8f9bac; }
  html body.dark-mode .kpi-card, html body.dark-mode .card { background: #151b23; border-color: #2c3542; box-shadow: none; }
  html body.dark-mode .kpi-icon { color: #f07a80; }
  html body.dark-mode .kpi-foot .up, html body.dark-mode .link-semua { color: #f07a80; }
  html body.dark-mode th { background: #b51f2a; color: #fff; border-bottom-color: #b51f2a; }
  html body.dark-mode td { color: #edf2f7; border-bottom-color: #2c3542; }
  html body.dark-mode tbody tr:hover td { background: #202733; }
  html body.dark-mode .Menunggu { background: #3a2e14; color: #f0b45a; }
  html body.dark-mode .Diproses { background: #172a4a; color: #7fb0ff; }
  html body.dark-mode .Selesai  { background: #15301f; color: #7fd9a0; }
  html body.dark-mode .Ditolak  { background: #3a1a1e; color: #f07a80; }

  @media (max-width: 1000px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 800px) {
    .content { padding: 18px 14px 30px; }
    .head h1 { font-size: 24px; }
    .date-box { display: none; }
    .kpi-card { padding: 14px; }
    .card-head { flex-wrap: wrap; padding: 16px 16px 12px; }
    .scroll { padding: 0 12px 12px; }
  }
  @media (max-width: 520px) { .kpi-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?php include "../includes/departemen/sidebar.php"; ?>

<div class="main">

  <?php include "../includes/departemen/topbar.php"; ?>

  <div class="content">

    <div class="head">
      <div>
        <h1>Dashboard</h1>
        <?php if ($nama_dep !== ""): ?>
          <p>Order perbaikan dari <?= htmlspecialchars($nama_dep) ?></p>
        <?php endif; ?>
      </div>
      <div class="date-box">📅 <?= htmlspecialchars($tanggal_hari_ini) ?></div>
    </div>

    <!-- KARTU STATISTIK -->
    <div class="kpi-grid">

      <div class="kpi-card">
        <div class="kpi-top">
          <div class="kpi-label">Total Order</div>
          <div class="kpi-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M8 8h8M8 12h8M8 16h5"></path></svg>
          </div>
        </div>
        <div class="kpi-bottom">
          <div class="kpi-number"><?= $total ?></div>
          <div class="kpi-foot">
            <?php if ($jumlah["Ditolak"] > 0): ?>
              <span class="up"><?= $jumlah["Ditolak"] ?></span> ditolak
            <?php else: ?>
              Semua order Anda
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <div class="kpi-label">Menunggu</div>
          <div class="kpi-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
          </div>
        </div>
        <div class="kpi-bottom">
          <div class="kpi-number"><?= $jumlah["Menunggu"] ?></div>
          <div class="kpi-foot">Belum diambil</div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <div class="kpi-label">Diproses</div>
          <div class="kpi-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"></path></svg>
          </div>
        </div>
        <div class="kpi-bottom">
          <div class="kpi-number"><?= $jumlah["Diproses"] ?></div>
          <div class="kpi-foot">Sedang dikerjakan</div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <div class="kpi-label">Selesai</div>
          <div class="kpi-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M8 12.5l3 3 5-6"></path></svg>
          </div>
        </div>
        <div class="kpi-bottom">
          <div class="kpi-number"><?= $jumlah["Selesai"] ?></div>
          <div class="kpi-foot"><span class="up"><?= $persen_selesai ?>%</span> dari total order</div>
        </div>
      </div>

    </div>

    <!-- ORDER TERBARU -->
    <div class="card">
      <div class="card-head">
        <div>
          <h2>Order Terbaru</h2>
          <p>5 order terakhir yang Anda buat</p>
        </div>
        <div class="aksi">
          <a href="order_saya.php" class="link-semua">Lihat semua</a>
          <a href="buat_order.php" class="btn-order">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            Buat Order
          </a>
        </div>
      </div>

      <?php if ($terbaru->num_rows == 0): ?>
        <div class="kosong">Belum ada order. Klik "Buat Order" untuk membuat order pertama.</div>
      <?php else: ?>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th>Nomor Order</th>
                <th>Nama Peralatan</th>
                <th>Detail Lokasi</th>
                <th>Status</th>
                <th>Tanggal</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($r = $terbaru->fetch_assoc()): ?>
                <tr>
                  <td class="nomor"><a href="detail_order.php?id=<?= (int) $r["id_order"] ?>"><?= htmlspecialchars(!empty($r["nomor_order"]) ? $r["nomor_order"] : "Belum ada nomor") ?></a></td>
                  <td><?= htmlspecialchars($r["judul"]) ?></td>
                  <td><?= htmlspecialchars(($r["lokasi"] ?? "") !== "" ? $r["lokasi"] : "-") ?></td>
                  <td><span class="badge <?= htmlspecialchars($r["status"]) ?>"><?= htmlspecialchars($r["status"]) ?></span></td>
                  <td class="nowrap"><?= date("d/m/Y H:i", strtotime($r["created_at"])) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

</div>

</body>
</html>