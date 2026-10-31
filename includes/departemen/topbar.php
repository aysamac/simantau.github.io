<?php
if (session_status() === PHP_SESSION_NONE) {
    include "../config/session.php";
}
if (!isset($koneksi)) {
    include __DIR__ . "/../../config/koneksi.php";
}

$id_admin_topbar = isset($_SESSION["id_user"]) ? (int) $_SESSION["id_user"] : 0;
$nama_admin_topbar = "";
$nama_divisi_topbar = "";

if ($id_admin_topbar > 0) {
    $query_topbar = mysqli_query($koneksi, "SELECT users.nama, users.departemen, divisi.nama_divisi FROM users LEFT JOIN divisi ON users.id_divisi = divisi.id_divisi WHERE users.id_user='$id_admin_topbar' LIMIT 1");
    if ($query_topbar) {
        $data_topbar = mysqli_fetch_assoc($query_topbar);
        if ($data_topbar) {
            $nama_admin_topbar = $data_topbar["nama"] ?? "";
            $nama_divisi_topbar = ($data_topbar["departemen"] ?? "") !== "" ? $data_topbar["departemen"] : ($data_topbar["nama_divisi"] ?? "");
        }
    }
}

$jumlah_notifikasi_topbar = 0;
if ($id_admin_topbar > 0) {
    $query_notifikasi_topbar = mysqli_query($koneksi, "SELECT COUNT(*) AS jumlah FROM notifikasi WHERE id_user='$id_admin_topbar' AND status_baca='Belum Dibaca'");
    if ($query_notifikasi_topbar) {
        $data_notifikasi_topbar = mysqli_fetch_assoc($query_notifikasi_topbar);
        $jumlah_notifikasi_topbar = (int) ($data_notifikasi_topbar["jumlah"] ?? 0);
    }
}

$halaman_aktif_topbar = ucfirst(str_replace("_", " ", pathinfo($_SERVER["PHP_SELF"], PATHINFO_FILENAME)));
?>
<style>
.topbar{background:#fff;padding:0 32px;box-sizing:border-box;height:73px;min-height:73px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e7ecf3;position:sticky;top:0;z-index:5}
.topbar-left{display:flex;align-items:center;gap:14px}
.menu-toggle{width:22px;height:22px;border:none;background:transparent;padding:0;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#4c5b6e}
.menu-toggle:hover{color:#b51f2a}
.menu-toggle svg{width:22px;height:22px;stroke:currentColor}
.topbar-left h2{font-size:18px;color:#1c2636;margin:0}
.topbar-right{display:flex;align-items:center;gap:22px}
.bell{position:relative;width:22px;height:22px;display:block;cursor:pointer}
.bell svg{width:22px;height:22px;stroke:#4c5b6e}
.bell:hover svg{stroke:#b51f2a}
.bell .dot{position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;padding:0 3px;background:#b51f2a;color:#fff;border-radius:50%;font-size:9px;display:flex;align-items:center;justify-content:center;font-weight:700}
.theme-toggle{width:36px;height:36px;border:1px solid #e2e6ec;border-radius:10px;background:#fff;color:#526176;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;flex:0 0 36px}
.theme-toggle:hover{background:#fff7f8;color:#b51f2a;border-color:#f0d5d8}
.theme-icon{width:18px;height:18px}
.theme-moon{display:none}
body.dark-mode .theme-sun{display:none}
body.dark-mode .theme-moon{display:block}
body.dark-mode{background:#0f141b;color:#e8edf4}
body.dark-mode .topbar{background:#151b23;border-bottom-color:#29313d}
body.dark-mode .topbar-left h2{color:#edf2f7}
body.dark-mode .menu-toggle{color:#aab5c4}
body.dark-mode .menu-toggle:hover{color:#b51f2a}
body.dark-mode .bell svg{stroke:#aab5c4}
body.dark-mode .bell:hover svg{stroke:#b51f2a}
body.dark-mode .theme-toggle{background:#202733;color:#f0b5b8;border-color:#353e4c}
.user-menu{position:relative}
.user-chip{display:flex;align-items:center;gap:10px;cursor:pointer;padding:4px 6px;border-radius:10px;transition:.15s}
.user-chip:hover,.user-menu.open .user-chip{background:#faf2f3}
.user-chip .avatar{width:36px;height:36px;border-radius:50%;background:#f8e9eb;display:flex;align-items:center;justify-content:center}
.user-chip .avatar svg{width:18px;height:18px;stroke:#b51f2a}
.user-chip .name{font-size:13.5px;font-weight:700;color:#1c2636}
.user-chip .role{font-size:11.5px;color:#8b96a5}
.user-chip svg.chevron{width:14px;height:14px;stroke:#8b96a5;transition:transform .2s}
.user-menu.open .chevron{transform:rotate(180deg)}
.profile-dropdown{display:none;position:absolute;right:0;top:calc(100% + 10px);width:175px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(31,41,55,.12);padding:6px;z-index:100}
.user-menu.open .profile-dropdown{display:block}
.profile-dropdown a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:7px;text-decoration:none;color:#475467;font-size:13px;font-weight:500}
.profile-dropdown a:hover{background:#fff5f6;color:#a51f2a}
.profile-dropdown a svg{width:18px;height:18px;stroke:currentColor;flex-shrink:0}
.profile-dropdown .logout-link{color:#a51f2a}
body.dark-mode .user-chip:hover,body.dark-mode .user-menu.open .user-chip{background:#202733}
body.dark-mode .user-chip .avatar{background:#302126}
body.dark-mode .user-chip .avatar svg{stroke:#e36b71}
body.dark-mode .user-chip .name{color:#edf2f7}
body.dark-mode .user-chip .role{color:#9aa6b6}
body.dark-mode .user-chip svg.chevron{stroke:#9aa6b6}
body.dark-mode .profile-dropdown{background:#1a212b;border-color:#303947;box-shadow:0 8px 24px rgba(0,0,0,.35)}
body.dark-mode .profile-dropdown a{color:#b7c1cf}
body.dark-mode .profile-dropdown a:hover{background:#28212a;color:#f07a80}
body.dark-mode .profile-dropdown .logout-link{color:#f07a80}
@media(max-width:800px){
    .topbar{padding:0 18px}
    .topbar-right{gap:14px}
    .user-chip>div:nth-child(2),.user-chip .chevron{display:none}
}
</style>

<div class="topbar">
    <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggle" title="Buka/Tutup Menu" aria-label="Buka/Tutup Menu" aria-expanded="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 6h16"></path>
                <path d="M4 12h16"></path>
                <path d="M4 18h16"></path>
            </svg>
        </button>
        <h2><?= htmlspecialchars($halaman_aktif_topbar); ?></h2>
    </div>

    <div class="topbar-right">
        <button type="button" class="theme-toggle" id="themeToggle" title="Mode malam" aria-label="Mode malam">
            <svg class="theme-icon theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="4"></circle>
                <path d="M12 2v2"></path>
                <path d="M12 20v2"></path>
                <path d="M4.93 4.93l1.41 1.41"></path>
                <path d="M17.66 17.66l1.41 1.41"></path>
                <path d="M2 12h2"></path>
                <path d="M20 12h2"></path>
                <path d="M4.93 19.07l1.41-1.41"></path>
                <path d="M17.66 6.34l1.41-1.41"></path>
            </svg>
            <svg class="theme-icon theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z"></path>
            </svg>
        </button>

        <a href="#" class="bell" title="Notifikasi" aria-label="Notifikasi" onclick="return false;">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>
            </svg>
            <span class="dot"><?= $jumlah_notifikasi_topbar; ?></span>
        </a>

        <div class="user-menu" id="userMenu">
            <div class="user-chip" id="userChip" role="button" tabindex="0" aria-expanded="false">
                <div class="avatar">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"></path>
                    </svg>
                </div>
                <div>
                    <div class="name"><?= htmlspecialchars($nama_admin_topbar); ?></div>
                    <div class="role"><?= htmlspecialchars($nama_divisi_topbar); ?></div>
                </div>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 9l6 6 6-6"></path>
                </svg>
            </div>

            <div class="profile-dropdown" id="profileDropdown">
                <a href="../logout.php" class="logout-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 5H5v14h4"></path>
                        <path d="M14 8l4 4-4 4"></path>
                        <path d="M18 12H9"></path>
                    </svg>
                    <span>Keluar</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const themeToggle=document.getElementById("themeToggle");
    if(localStorage.getItem("simo-theme")==="dark")document.body.classList.add("dark-mode");
    if(themeToggle)themeToggle.addEventListener("click",function(){
        document.body.classList.toggle("dark-mode");
        localStorage.setItem("simo-theme",document.body.classList.contains("dark-mode")?"dark":"light");
    });
})();

(function(){
    const menuToggle=document.getElementById("menuToggle");
    const overlay=document.getElementById("sidebarOverlay");
    const userMenu=document.getElementById("userMenu");
    const userChip=document.getElementById("userChip");

    if(menuToggle){
        menuToggle.addEventListener("click",function(){
            if(window.innerWidth<=800){
                document.body.classList.toggle("sidebar-open");
                const terbuka=document.body.classList.contains("sidebar-open");
                menuToggle.setAttribute("aria-expanded",terbuka?"true":"false");
            }else{
                document.body.classList.toggle("sidebar-closed");
                const terbuka=!document.body.classList.contains("sidebar-closed");
                menuToggle.setAttribute("aria-expanded",terbuka?"true":"false");
            }
        });
    }

    if(overlay){
        overlay.addEventListener("click",function(){
            document.body.classList.remove("sidebar-open");
        });
    }

    if(userChip&&userMenu){
        userChip.addEventListener("click",function(e){
            e.stopPropagation();
            const terbuka=userMenu.classList.toggle("open");
            userChip.setAttribute("aria-expanded",terbuka?"true":"false");
        });

        userChip.addEventListener("keydown",function(e){
            if(e.key==="Enter"||e.key===" "){
                e.preventDefault();
                userChip.click();
            }
        });

        document.addEventListener("click",function(e){
            if(!userMenu.contains(e.target)){
                userMenu.classList.remove("open");
                userChip.setAttribute("aria-expanded","false");
            }
        });
    }

    window.addEventListener("resize",function(){
        if(window.innerWidth>800){
            document.body.classList.remove("sidebar-open");
        }else{
            document.body.classList.remove("sidebar-closed");
        }
    });
})();
</script>