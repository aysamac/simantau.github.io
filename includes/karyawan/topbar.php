<?php

if (session_status() === PHP_SESSION_NONE) {

    include "../config/session.php";

}

include_once __DIR__ . "/../../config/koneksi.php";

$id_user_topbar = (int) ($_SESSION["id_user"] ?? 0);

$nama_user_topbar = $_SESSION["nama_lengkap"] ?? $_SESSION["nama"] ?? "Karyawan";

$nama_divisi_topbar = $_SESSION["nama_divisi"] ?? "Karyawan";

if ($id_user_topbar > 0) {

    $q = mysqli_query($koneksi, "SELECT users.nama, divisi.nama_divisi FROM users LEFT JOIN divisi ON users.id_divisi = divisi.id_divisi WHERE users.id_user='$id_user_topbar' LIMIT 1");

    if ($q && ($d = mysqli_fetch_assoc($q))) {

        $nama_user_topbar = $d["nama"] ?? $nama_user_topbar;

        $nama_divisi_topbar = $d["nama_divisi"] ?? $nama_divisi_topbar;

    }

}

$jumlah_notifikasi_topbar = 0;

if ($id_user_topbar > 0) {

    $q = mysqli_query($koneksi, "SELECT COUNT(*) AS jumlah FROM notifikasi WHERE id_user='$id_user_topbar' AND status_baca='Belum Dibaca'");

    if ($q) {

        $d = mysqli_fetch_assoc($q);

        $jumlah_notifikasi_topbar = (int) ($d["jumlah"] ?? 0);

    }

}

$halaman_topbar = basename($_SERVER["PHP_SELF"]);

if ($halaman_topbar === "riwayat.php") {

    $judul_topbar = "Riwayat";

} elseif ($halaman_topbar === "order_masuk.php") {

    $judul_topbar = "Order Masuk";

} elseif (in_array($halaman_topbar, ["tugas.php", "detail_tugas.php", "mulai_pekerjaan.php", "kirim_bukti.php"])) {

    $judul_topbar = "Tugas Saya";

} elseif ($halaman_topbar === "profil.php") {

    $judul_topbar = "Profil";

} else {

    $judul_topbar = "Dashboard";

}

?>

<style>

.topbar{width:100%;height:73px;min-height:73px;background:#fff;border-bottom:1px solid #e9edf2;display:flex;align-items:center;justify-content:space-between;padding:0 32px;gap:22px;box-sizing:border-box;position:sticky;top:0;z-index:900;flex-shrink:0}

.topbar-left{display:flex;align-items:center;gap:15px;min-width:0}

.topbar-menu{width:22px;height:22px;color:#526176;flex:0 0 22px;cursor:pointer;display:block}

.topbar-menu:hover{color:#b51f2a}

.topbar-title{font-size:18px;font-weight:700;color:#1c2636;white-space:nowrap}

.topbar-right{display:flex;align-items:center;gap:22px;margin-left:auto}

.bell{position:relative;width:22px;height:22px;display:block;color:#526176;cursor:pointer;flex:0 0 22px}

.bell:hover{color:#b51f2a}

.bell svg{width:22px;height:22px;display:block}

.notification-badge{position:absolute;top:-7px;right:-7px;min-width:16px;height:16px;padding:0 4px;border-radius:20px;background:#b51f2a;color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-sizing:border-box}

.profile{position:relative;display:flex;align-items:center;gap:10px;cursor:pointer;padding:5px 7px;border-radius:10px;transition:background .15s ease}

.profile:hover,.profile.open{background:#fff7f8}

.profile-avatar{width:36px;height:36px;border-radius:50%;background:#f8e9eb;color:#b51f2a;display:flex;align-items:center;justify-content:center;flex:0 0 36px}

.profile-avatar svg{width:18px;height:18px}

.profile-info{display:flex;flex-direction:column;gap:2px;min-width:75px}

.profile-name{font-size:13.5px;font-weight:700;color:#1c2636;line-height:1.1;white-space:nowrap}

.profile-role{font-size:11.5px;color:#7c8796;line-height:1.1;white-space:nowrap}

.profile-chevron{width:15px;height:15px;color:#7c8796;flex:0 0 15px;transition:transform .2s ease}

.profile.open .profile-chevron{transform:rotate(180deg)}

.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:175px;background:#fff;border:1px solid #e6e9ee;border-radius:11px;box-shadow:0 8px 24px rgba(31,41,55,.12);padding:6px;display:none;z-index:1000}

.profile-dropdown.show{display:block}

.profile-dropdown a{display:flex;align-items:center;gap:10px;width:100%;min-height:40px;padding:8px 10px;box-sizing:border-box;border-radius:8px;color:#667085;font-size:13px;font-weight:500;text-decoration:none;transition:background .15s ease,color .15s ease}

.profile-dropdown a:hover{background:#fff7f8;color:#a51f2a}

.profile-dropdown a.logout{color:#a52a33}

.profile-dropdown a.logout:hover{background:#fff1f2;color:#8f2029}

.profile-dropdown svg{width:18px;height:18px;flex-shrink:0}

@media(max-width:800px){
    .topbar{height:64px;min-height:64px;padding:0 20px}
    .topbar-menu{display:block;width:22px;height:22px;cursor:pointer;pointer-events:auto;position:relative;z-index:1301}
    .topbar-left{min-width:0}
    .sidebar{position:fixed!important;left:0!important;top:0!important;height:100vh!important;z-index:1200!important;transform:translateX(-105%)!important;transition:transform .25s ease!important}
    .sidebar.mobile-open{transform:translateX(0)!important}
    .sidebar-overlay{display:none;position:fixed!important;inset:0!important;z-index:1150!important;background:rgba(0,0,0,.45)!important}
    .sidebar-overlay.mobile-open{display:block!important}
}

@media(max-width:480px){

    .topbar{padding:0 14px}

    .topbar-left{gap:11px}

    .topbar-title{font-size:16px}

}

.theme-toggle{width:36px;height:36px;border:1px solid #e2e6ec;border-radius:10px;background:#fff;color:#526176;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;flex:0 0 36px}

.theme-toggle:hover{background:#fff7f8;color:#b51f2a;border-color:#f0d5d8}

.theme-icon{width:18px;height:18px}.theme-moon{display:none}

body.dark-mode .theme-sun{display:none}body.dark-mode .theme-moon{display:block}

body.dark-mode{background:#0f141b;color:#e8edf4}

body.dark-mode .topbar{background:#151b23;border-bottom-color:#29313d}

body.dark-mode .topbar-title,body.dark-mode .profile-name{color:#edf2f7}

body.dark-mode .topbar-menu,body.dark-mode .bell,body.dark-mode .profile-chevron{color:#aab5c4}

body.dark-mode .theme-toggle{background:#202733;color:#f0b5b8;border-color:#353e4c}

body.dark-mode .profile:hover,body.dark-mode .profile.open{background:#202733}

body.dark-mode .profile-role{color:#9aa6b6}

body.dark-mode .profile-avatar{background:#302126;color:#e36b71}

body.dark-mode .profile-dropdown{background:#1a212b;border-color:#303947;box-shadow:0 8px 24px rgba(0,0,0,.35)}

body.dark-mode .profile-dropdown a{color:#b7c1cf}body.dark-mode .profile-dropdown a:hover{background:#28212a;color:#f07a80}

body.dark-mode .sidebar{background:#11171f!important;border-color:#29313d!important;color:#aeb8c6}

body.dark-mode .sidebar *{border-color:#29313d}

body.dark-mode .sidebar a,body.dark-mode .sidebar button{color:#aeb8c6}

body.dark-mode .sidebar .active,body.dark-mode .sidebar .menu-item.active{background:#302126!important;color:#f07a80!important}

body.dark-mode .main,body.dark-mode .content{background:#0f141b!important}

body.dark-mode .card,body.dark-mode .panel,body.dark-mode .table-container,body.dark-mode .dashboard-card,body.dark-mode .content-card,body.dark-mode .box{background:#151b23!important;border-color:#2c3542!important;color:#e8edf4}

body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode h4,body.dark-mode .page-title,body.dark-mode .section-title{color:#edf2f7}

body.dark-mode p,body.dark-mode .muted,body.dark-mode .page-desc,body.dark-mode .card-desc{color:#9eabbc}

body.dark-mode th{background:#202733!important;color:#dce4ed!important;border-color:#303947!important}

body.dark-mode td{background:#151b23!important;color:#c8d1dc!important;border-color:#29313d!important}

body.dark-mode input,body.dark-mode select,body.dark-mode textarea{background:#1a212b!important;color:#e8edf4!important;border-color:#343e4c!important}

body.dark-mode input::placeholder,body.dark-mode textarea::placeholder{color:#7f8b9c}

</style>

<header class="topbar">

    <div class="topbar-left">

        <svg class="topbar-menu" id="menuToggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" role="button" tabindex="0" aria-label="Buka menu" aria-expanded="false">

            <path d="M4 6h16"></path>

            <path d="M4 12h16"></path>

            <path d="M4 18h16"></path>

        </svg>

        <div class="topbar-title"><?= htmlspecialchars($judul_topbar); ?></div>

    </div>

    <div class="topbar-right">

        <button type="button" class="theme-toggle" id="themeToggle" title="Mode malam" aria-label="Mode malam">

        <svg class="theme-icon theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>

        <svg class="theme-icon theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z"></path></svg>

        </button>

        <a href="../notifikasi.php" class="bell" title="Notifikasi" aria-label="Notifikasi">

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">

                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>

                <path d="M10 21h4"></path>

            </svg>

            <?php if ($jumlah_notifikasi_topbar > 0): ?>

                <span class="notification-badge"><?= $jumlah_notifikasi_topbar > 99 ? "99+" : $jumlah_notifikasi_topbar; ?></span>

            <?php endif; ?>

        </a>

        <div class="profile" id="profileMenu" tabindex="0" aria-haspopup="true" aria-expanded="false">

            <div class="profile-avatar">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">

                    <circle cx="12" cy="8" r="3.5"></circle>

                    <path d="M5 20c.8-3.2 3.2-5 7-5s6.2 1.8 7 5"></path>

                </svg>

            </div>

            <div class="profile-info">

                <div class="profile-name"><?= htmlspecialchars($nama_user_topbar); ?></div>

                <div class="profile-role"><?= htmlspecialchars($nama_divisi_topbar); ?></div>

            </div>

            <svg class="profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">

                <path d="M7 10l5 5 5-5"></path>

            </svg>

            <div class="profile-dropdown" id="profileDropdown">

                <a href="profil.php">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">

                        <circle cx="12" cy="8" r="4"></circle>

                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path>

                    </svg>

                    <span>Profil</span>

                </a>

                <a href="../logout.php" class="logout">

                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">

                        <path d="M9 5H5v14h4"></path>

                        <path d="M14 8l4 4-4 4"></path>

                        <path d="M9 12h9"></path>

                    </svg>

                    <span>Keluar</span>

                </a>

            </div>

        </div>

    </div>

</header>

<script>

(function(){

const themeToggle=document.getElementById("themeToggle");

if(localStorage.getItem("simo-theme")==="dark")document.body.classList.add("dark-mode");

if(themeToggle)themeToggle.addEventListener("click",function(){document.body.classList.toggle("dark-mode");localStorage.setItem("simo-theme",document.body.classList.contains("dark-mode")?"dark":"light");});

})();

document.addEventListener("DOMContentLoaded",function(){
    const menuToggle=document.getElementById("menuToggle");
    const sidebar=document.querySelector(".sidebar");
    const overlay=document.querySelector(".sidebar-overlay");
    const profileMenu=document.getElementById("profileMenu");
    const profileDropdown=document.getElementById("profileDropdown");
    if(profileMenu&&profileDropdown){
        profileMenu.addEventListener("click",function(event){
            event.stopPropagation();
            const open=profileDropdown.classList.toggle("show");
            profileMenu.classList.toggle("open",open);
            profileMenu.setAttribute("aria-expanded",open?"true":"false");
        });
        profileMenu.addEventListener("keydown",function(event){
            if(event.key==="Enter"||event.key===" "){
                event.preventDefault();
                const open=profileDropdown.classList.toggle("show");
                profileMenu.classList.toggle("open",open);
                profileMenu.setAttribute("aria-expanded",open?"true":"false");
            }else if(event.key==="Escape"){
                profileDropdown.classList.remove("show");
                profileMenu.classList.remove("open");
                profileMenu.setAttribute("aria-expanded","false");
            }
        });
        profileDropdown.querySelectorAll("a").forEach(function(link){
            link.addEventListener("click",function(event){event.stopPropagation();});
        });
        document.addEventListener("click",function(){
            profileDropdown.classList.remove("show");
            profileMenu.classList.remove("open");
            profileMenu.setAttribute("aria-expanded","false");
        });
    }
    if(!menuToggle||!sidebar||!overlay)return;
    const BREAKPOINT=800;
    function isMobile(){return window.innerWidth<=BREAKPOINT}
    function updateAria(){
        const isOpen=isMobile()?sidebar.classList.contains("mobile-open"):!document.body.classList.contains("sidebar-closed");
        menuToggle.setAttribute("aria-expanded",isOpen?"true":"false");
    }
    function toggleSidebar(){
        if(isMobile()){
            sidebar.classList.toggle("mobile-open");
            overlay.classList.toggle("mobile-open");
        }else{
            document.body.classList.toggle("sidebar-closed");
            localStorage.setItem("simo-sidebar",document.body.classList.contains("sidebar-closed")?"closed":"open");
        }
        updateAria();
    }
    function closeMobileSidebar(){
        sidebar.classList.remove("mobile-open");
        overlay.classList.remove("mobile-open");
        updateAria();
    }
    menuToggle.addEventListener("click",function(event){
        event.preventDefault();
        event.stopPropagation();
        toggleSidebar();
    });
    menuToggle.addEventListener("keydown",function(event){
        if(event.key==="Enter"||event.key===" "){
            event.preventDefault();
            toggleSidebar();
        }
    });
    overlay.addEventListener("click",closeMobileSidebar);
    let wasMobile=isMobile();
    window.addEventListener("resize",function(){
        const nowMobile=isMobile();
        if(nowMobile!==wasMobile){
            sidebar.classList.remove("mobile-open");
            overlay.classList.remove("mobile-open");
            document.body.classList.remove("sidebar-closed");
            wasMobile=nowMobile;
        }
        updateAria();
    });
    updateAria();
});

</script>