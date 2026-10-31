<?php
if (session_status() === PHP_SESSION_NONE) {
    include "../config/session.php";
}
$nama_file_sidebar = pathinfo(
    $_SERVER["PHP_SELF"],
    PATHINFO_FILENAME
);
$menu_aktif_sidebar = $nama_file_sidebar;
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600;700&family=Outfit:wght@700;800&display=swap');
    .sidebar {
        width: 260px;
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        box-sizing: border-box;
        padding: 0;
        background: #ffffff;
        z-index: 20;
        display: flex;
        flex-direction: column;
        transform: translateX(0);
        transition:
            transform 0.28s ease,
            box-shadow 0.28s ease;
    }
    .sidebar-card {
        position: relative;
        width: 260px;
        height: 100vh;
        min-height: 100vh;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        background:
            linear-gradient(
                180deg,
                #ffffff 0%,
                #ffffff 66%,
                #fdf2f3 100%
            );
        border-radius: 0;
        border: 0;
        box-shadow: none;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .sidebar-card::-webkit-scrollbar {
        width: 5px;
    }
    .sidebar-card::-webkit-scrollbar-track {
        background: transparent;
    }
    .sidebar-card::-webkit-scrollbar-thumb {
        background: #e9dcdd;
        border-radius: 10px;
    }
    .sidebar-brand {
        position: relative;
        box-sizing: border-box;
        height: 73px;
        min-height: 73px;
        flex-shrink: 0;
        padding: 0 19px;
        display: flex;
        align-items: center;
        border-bottom:
            1px solid #f0edef;
    }
    .sidebar-logo {
        width: 100%;
        height: 47px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 9px;
        margin-bottom: 0;
    }
    aside.sidebar .sidebar-r-logo {
        display: block !important;
        width: 43px !important;
        height: 43px !important;
        max-width: 43px !important;
        max-height: 43px !important;
        flex: 0 0 43px !important;
        object-fit: contain !important;
    }
    aside.sidebar .sidebar-sosro-logo {
        display: block !important;
        width: 174px !important;
        height: 43px !important;
        max-width: calc(100% - 52px) !important;
        max-height: 43px !important;
        flex: 0 1 auto !important;
        object-fit: contain !important;
        object-position: left center !important;
    }
    aside.sidebar .sidebar-logo {
        width: 100% !important;
        height: 47px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 9px !important;
        margin-bottom: 0 !important;
    }
    .sidebar-menu {
        padding:
            15px 12px 0;
    }
    .sidebar-section-title {
        padding: 0 13px;
        margin: 0 0 10px;
        font-size: 11px;
        font-weight: 700;
        color: #a1a8b3;
        letter-spacing: 1.4px;
        text-transform: uppercase;
    }
    .sidebar-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: 13px;
        width: 100%;
        min-height: 46px;
        padding: 10px 13px;
        margin-bottom: 4px;
        box-sizing: border-box;
        border-radius: 10px;
        color: #667085;
        font-size: 15px;
        font-weight: 500;
        text-decoration: none;
        transition:
            transform 0.12s ease,
            background 0.12s ease,
            color 0.12s ease,
            box-shadow 0.12s ease;
        will-change: transform, box-shadow;
    }
    .sidebar-link:hover {
        background: #ffffff;
        color: #9f202b;
        transform: translateY(-2px);
        box-shadow: 0 4px 11px rgba(31, 41, 55, 0.08);
    }
    .sidebar-link:hover svg.menu-icon {
        stroke: #a51f2a;
    }
    .sidebar-link:hover .sidebar-arrow {
        stroke: #9fa8b5 !important;
    }
    .sidebar-link:active {
        transform: translateY(0);
        box-shadow: 0 2px 6px rgba(31, 41, 55, 0.06);
    }
    .sidebar-link.active {
        position: relative;
        background: #fff7f8;
        color: #8f2029;
        font-weight: 600;
        box-shadow: none;
        transform: none;
    }
    .sidebar-link.active::before {
        content: "";
        position: absolute;
        left: -12px;
        top: 7px;
        width: 6px;
        height: 30px;
        border-radius: 0 6px 6px 0;
        background: linear-gradient(
            180deg,
            #c92834 0%,
            #9b2029 100%
        );
    }
    .sidebar-link svg.menu-icon {
        width: 21px;
        height: 21px;
        flex-shrink: 0;
        stroke: currentColor;
        stroke-width: 1.8;
    }
    .sidebar-link.active svg.menu-icon {
        stroke: #a51f2a;
    }
    .sidebar-arrow {
        width: 14px !important;
        height: 14px !important;
        margin-left: auto;
        flex-shrink: 0;
        stroke: #c0c7d0 !important;
        stroke-width: 1.8 !important;
        transition: transform 0.2s ease;
    }
    .sidebar-link.active .sidebar-arrow {
        stroke: #aeb6c1 !important;
        opacity: 1;
    }
    .sidebar-divider {
        height: 1px;
        background: #f0edef;
        margin:
            10px 22px 10px;
    }
    .sidebar-bottom {
        margin-top: auto;
        padding:
            0 14px 15px;
    }
    .sidebar-motto {
        position: relative;
        overflow: hidden;
        height: 148px;
        margin:
            5px 0 13px;
        border-radius: 19px;
        background:
            linear-gradient(
                180deg,
                #fffafa 0%,
                #fff7f7 100%
            );
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .sidebar-motto svg.wave-art {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    .sidebar-motto .motto-line {
        position: absolute;
        z-index: 5;
        width: 30px;
        height: 2px;
        left: 50%;
        bottom: 20px;
        transform:
            translateX(-50%);
        background: #96222c;
        border-radius: 5px;
    }
    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: 13px;
        width: 100%;
        min-height: 46px;
        padding: 10px 13px;
        box-sizing: border-box;
        border: 0;
        border-radius: 10px;
        color: #a52a33;
        background: transparent;
        font-size: 15px;
        font-weight: 600;
        text-decoration: none;
        transition:
            transform 0.12s ease,
            background 0.12s ease,
            color 0.12s ease,
            box-shadow 0.12s ease;
    }
    .sidebar-logout svg {
        width: 19px;
        height: 19px;
        stroke: currentColor;
        stroke-width: 2;
    }
    .sidebar-logout:hover {
        background: #fff7f8;
        color: #8f2130;
        transform: translateY(-2px);
        box-shadow: 0 5px 14px rgba(31, 41, 55, 0.08);
    }
    .sidebar-logout:active {
        transform: translateY(0);
        box-shadow: 0 2px 6px rgba(31, 41, 55, 0.05);
    }
    .main {
        margin-left: 260px;
        width:
            calc(100% - 260px);
        min-width: 0;
        min-height: 100vh;
        transition:
            margin-left 0.28s ease,
            width 0.28s ease;
    }
    body.sidebar-closed .sidebar {
        transform:
            translateX(-100%);
    }
    body.sidebar-closed .main {
        margin-left: 0;
        width: 100%;
    }
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background:
            rgba(20, 27, 38, 0.35);
        z-index: 19;
    }
    body.sidebar-open .sidebar-overlay {
        display: block;
    }
@media (max-width: 800px) {
        .sidebar {
            width: 260px;
            transform:
                translateX(-100%);
            box-shadow:
                8px 0 30px
                rgba(25, 35, 48, 0.14);
        }
        .sidebar-card {
            width: 260px;
        }
        body.sidebar-open .sidebar {
            transform:
                translateX(0);
        }
        body.sidebar-closed .sidebar {
            transform:
                translateX(-100%);
        }
        .main {
            margin-left: 0;
            width: 100%;
        }
        body.sidebar-closed .main {
            margin-left: 0;
            width: 100%;
        }
    }
body.dark-mode .sidebar{background:#11171f}
body.dark-mode .sidebar-card{background:linear-gradient(180deg,#11171f 0%,#11171f 66%,#17191f 100%)}
body.dark-mode .sidebar-brand{border-bottom-color:#29313d}
body.dark-mode .sidebar-card::-webkit-scrollbar-thumb{background:#343c48}
body.dark-mode .sidebar-section-title{color:#7f8b9c}
body.dark-mode .sidebar-link{color:#fff!important}
body.dark-mode .sidebar-link:hover{background:#202733;color:#fff!important;box-shadow:0 4px 11px rgba(0,0,0,.22)}
body.dark-mode .sidebar-link:hover svg.menu-icon{stroke:#e16a72!important}
body.dark-mode .sidebar-link:hover .sidebar-arrow{stroke:#9da8b6!important}
body.dark-mode .sidebar-link.active{background:#33272a;color:#e16a72!important}
body.dark-mode .sidebar-link.active svg.menu-icon{stroke:#e16a72!important}
body.dark-mode .sidebar-link.active .sidebar-arrow{stroke:#9da8b6!important}
body.dark-mode .sidebar-arrow{stroke:#7f8b9c!important}
body.dark-mode .sidebar-divider{background:#29313d}
body.dark-mode .sidebar-motto{background:linear-gradient(180deg,#1b1b22 0%,#17171d 100%)}
body.dark-mode .sidebar-motto .wave-art path{fill:#c62834!important;opacity:.17!important;filter:drop-shadow(0 0 7px rgba(198,40,52,.18))}
body.dark-mode .sidebar-motto .wave-art path:nth-child(2){opacity:.23!important}
body.dark-mode .sidebar-motto .wave-art path:nth-child(3){opacity:.19!important}
body.dark-mode .sidebar-logout{color:#fff!important}
body.dark-mode .sidebar-logout:hover{background:#28242a;color:#e16a72!important}

/* ===== EFEK TIMBUL SAAT KURSOR DI MENU (sama dengan sidebar divisi) ===== */
.sidebar-link,
.sidebar-logout {
    transition:
        transform .18s cubic-bezier(.2,.8,.2,1),
        box-shadow .18s ease,
        background .15s ease,
        color .15s ease;
    will-change: transform;
}
.sidebar-logout { position: relative; }

/* area hover diperlebar sedikit supaya tidak berkedip saat menu terangkat */
.sidebar-link::after,
.sidebar-logout::after {
    content: "";
    position: absolute;
    left: 0; right: 0;
    top: -4px; bottom: -4px;
}

.sidebar-link:hover,
.sidebar-link.active:hover {
    transform: translateY(-3px) scale(1.02);
    background: #ffffff;
    color: #9f202b;
    box-shadow:
        0 8px 14px -4px rgba(31, 41, 55, .20),
        0 2px 5px rgba(31, 41, 55, .08),
        0 0 0 1px #f0e4e6;
}
.sidebar-link.active:hover { background: #fff7f8; }

.sidebar-logout:hover {
    transform: translateY(-3px) scale(1.02);
    background: #ffffff;
    box-shadow:
        0 8px 14px -4px rgba(31, 41, 55, .20),
        0 2px 5px rgba(31, 41, 55, .08),
        0 0 0 1px #f0e4e6;
}

/* saat diklik: tampilan tetap sama seperti hover (tidak bergerak / bergetar) */
.sidebar-link:active,
.sidebar-link.active:active,
.sidebar-logout:active {
    transform: translateY(-3px) scale(1.02);
    box-shadow:
        0 8px 14px -4px rgba(31, 41, 55, .20),
        0 2px 5px rgba(31, 41, 55, .08),
        0 0 0 1px #f0e4e6;
}

/* dark mode */
body.dark-mode .sidebar-link:hover,
body.dark-mode .sidebar-link.active:hover,
body.dark-mode .sidebar-logout:hover {
    background: #222b38;
    box-shadow:
        0 8px 14px -4px rgba(0, 0, 0, .55),
        0 2px 5px rgba(0, 0, 0, .30),
        0 0 0 1px #313b4a;
}
body.dark-mode .sidebar-link.active:hover { background: #3a2a2e; }
body.dark-mode .sidebar-link:active,
body.dark-mode .sidebar-link.active:active,
body.dark-mode .sidebar-logout:active {
    box-shadow:
        0 8px 14px -4px rgba(0, 0, 0, .55),
        0 2px 5px rgba(0, 0, 0, .30),
        0 0 0 1px #313b4a;
}

/* ===== menu yang baru diklik tetap timbul di halaman baru
   (tidak tenggelam lalu timbul lagi) ===== */
.sidebar-link.hover-keep,
.sidebar-link.active.hover-keep,
.sidebar-logout.hover-keep {
    transform: translateY(-3px) scale(1.02);
    background: #ffffff;
    color: #9f202b;
    box-shadow:
        0 8px 14px -4px rgba(31, 41, 55, .20),
        0 2px 5px rgba(31, 41, 55, .08),
        0 0 0 1px #f0e4e6;
    transition: none !important;
}
.sidebar-link.active.hover-keep { background: #fff7f8; }
.sidebar-link.hover-keep svg.menu-icon { stroke: #a51f2a; }
.sidebar-link.hover-keep .sidebar-arrow { stroke: #9fa8b5 !important; }

body.dark-mode .sidebar-link.hover-keep,
body.dark-mode .sidebar-logout.hover-keep {
    background: #222b38;
    color: #fff !important;
    box-shadow:
        0 8px 14px -4px rgba(0, 0, 0, .55),
        0 2px 5px rgba(0, 0, 0, .30),
        0 0 0 1px #313b4a;
}
body.dark-mode .sidebar-link.active.hover-keep {
    background: #3a2a2e;
    color: #e16a72 !important;
}
body.dark-mode .sidebar-link.hover-keep svg.menu-icon { stroke: #e16a72 !important; }

/* ===== ISOLASI: tata letak sidebar tidak dipengaruhi CSS tiap halaman =====
   Tanpa ini, perbedaan line-height / box-sizing / font antar halaman
   membuat posisi menu bergeser (ada yang turun, ada yang naik). */
.sidebar,
.sidebar *,
.sidebar *::before,
.sidebar *::after {
    box-sizing: border-box;
}
.sidebar {
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 16px;
    line-height: 1.25;
    text-align: left;
    letter-spacing: normal;
}
.sidebar a {
    text-decoration: none;
}
.sidebar .sidebar-section-title {
    letter-spacing: 1.4px;
}
</style>
<aside
    class="sidebar"
    id="sidebar"
>
    <div class="sidebar-card">
        <div class="sidebar-brand">
            <div class="sidebar-logo">
                <img
                    src="../assets/img/logo-login.jpeg"
                    alt="Logo R"
                    class="sidebar-r-logo"
                >
                <img
                    src="../assets/img/logo-sosro.jpeg"
                    alt="Sinar Sosro Gunung Slamat"
                    class="sidebar-sosro-logo"
                >
            </div>
        </div>

        <div class="sidebar-menu">
            <div class="sidebar-section-title">Menu Utama</div>
            <a href="dashboard.php" class="sidebar-link <?= $menu_aktif_sidebar == 'dashboard' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>
                <span>Dashboard</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="pekerjaan.php" class="sidebar-link <?= $menu_aktif_sidebar == 'pekerjaan' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 5V3h8v2"></path><path d="M8 10h8"></path><path d="M8 14h5"></path></svg>
                <span>Pekerjaan</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="divisi.php" class="sidebar-link <?= $menu_aktif_sidebar == 'divisi' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V9l7-5 7 5v12"></path><path d="M9 21v-5h6v5"></path><path d="M8 11h1"></path><path d="M15 11h1"></path></svg>
                <span>Divisi</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="monitoring.php" class="sidebar-link <?= $menu_aktif_sidebar == 'monitoring' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5"></path><path d="M4 19h16"></path><path d="M7 15l4-4 3 2 5-6"></path></svg>
                <span>Monitoring</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="laporan.php" class="sidebar-link <?= $menu_aktif_sidebar == 'laporan' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5"></path><path d="M9 12h6"></path><path d="M9 16h6"></path></svg>
                <span>Laporan</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="pengguna.php" class="sidebar-link <?= $menu_aktif_sidebar == 'pengguna' ? 'active' : ''; ?>">
                <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"></circle><path d="M3 20c0-3.5 2.5-6 6-6s6 2.5 6 6"></path><path d="M16 11c2.5 0 5 1.5 5 4"></path><path d="M16 5.5c1.7 0 3 1.2 3 2.8"></path></svg>
                <span>Pengguna</span>
                <svg class="sidebar-arrow" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <div class="sidebar-bottom">
            <div class="sidebar-section-title">
                Profil
            </div>
            <a
                href="profil.php"
                class="sidebar-link
                    <?= $menu_aktif_sidebar == 'profil'
                        ? 'active'
                        : ''; ?>"
            >
                <svg
                    class="menu-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle
                        cx="12"
                        cy="8"
                        r="4"
                    ></circle>
                    <path
                        d="M4 20c0-4 3.6-7 8-7s8 3 8 7"
                    ></path>
                </svg>
                <span>
                    Profil
                </span>
                <svg
                    class="sidebar-arrow"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        d="M9 18l6-6-6-6"
                    ></path>
                </svg>
            </a>
            <div class="sidebar-motto">
                <svg
                    class="wave-art"
                    viewBox="0 0 220 148"
                    preserveAspectRatio="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="
                            M -20 31
                            C 12 4, 39 5, 67 20
                            C 95 35, 112 53, 139 45
                            C 167 37, 182 13, 207 14
                            C 224 15, 236 25, 248 32
                            L 248 62
                            C 232 53, 220 45, 206 44
                            C 180 42, 167 61, 140 68
                            C 112 75, 94 57, 66 43
                            C 38 29, 12 30, -20 56
                            Z
                        "
                        fill="#b51f2a"
                        opacity="0.055"
                    />
                    <path
                        d="
                            M -20 54
                            C 13 27, 40 28, 68 43
                            C 97 58, 113 76, 140 68
                            C 168 61, 182 37, 208 38
                            C 225 39, 236 49, 248 56
                            L 248 85
                            C 233 76, 220 68, 205 67
                            C 180 65, 166 84, 140 91
                            C 112 98, 94 80, 66 66
                            C 38 52, 12 53, -20 78
                            Z
                        "
                        fill="#b51f2a"
                        opacity="0.07"
                    />
                    <path
                        d="
                            M -20 78
                            C 13 52, 40 52, 68 66
                            C 97 81, 113 99, 140 91
                            C 168 84, 182 60, 208 61
                            C 225 62, 236 72, 248 79
                            L 248 108
                            C 233 99, 220 91, 205 90
                            C 180 88, 166 107, 140 114
                            C 112 121, 94 103, 66 89
                            C 38 75, 12 76, -20 102
                            Z
                        "
                        fill="#b51f2a"
                        opacity="0.055"
                    />
                </svg>
                <span class="motto-line"></span>
            </div>
            <a
                href="../logout.php"
                class="sidebar-logout"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        d="M9 5H5v14h4"
                    ></path>
                    <path
                        d="M14 8l4 4-4 4"
                    ></path>
                    <path
                        d="M18 12H9"
                    ></path>
                </svg>
                <span>
                    Keluar
                </span>
            </a>
        </div>
    </div>
</aside>
<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<script>
/* Menjaga efek "timbul" tetap ada setelah klik, supaya tidak tenggelam
   dulu lalu timbul lagi di halaman baru. */
(function () {

    var KEY = 'simo-sidebar-clicked';

    /* Hanya untuk perangkat dengan mouse (di HP tidak ada hover) */
    var punyaHover = window.matchMedia &&
        window.matchMedia('(hover: hover)').matches;

    if (!punyaHover) return;

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    /* 1. Di halaman BARU: tandai menu yang tadi diklik sebagai "timbul" */
    var tersimpan = null;

    try {
        tersimpan = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
    } catch (e) {}

    if (tersimpan) {

        var semua = sidebar.querySelectorAll('a[href]');

        for (var i = 0; i < semua.length; i++) {

            if (semua[i].getAttribute('href') === tersimpan) {

                var el = semua[i];

                el.classList.add('hover-keep');
                var lepas = function () {
                    el.classList.remove('hover-keep');
                    window.removeEventListener('mousemove', lepas, true);
                    window.removeEventListener('mousedown', lepas, true);
                    window.removeEventListener('keydown', lepas, true);
                    window.removeEventListener('wheel', lepas, true);
                };

                window.addEventListener('mousemove', lepas, true);
                window.addEventListener('mousedown', lepas, true);
                window.addEventListener('keydown', lepas, true);
                window.addEventListener('wheel', lepas, true);

                break;
            }
        }
    }

    /* 2. Di halaman LAMA: ingat menu mana yang diklik */
    sidebar.addEventListener('click', function (e) {

        var a = e.target.closest ? e.target.closest('a[href]') : null;

        if (!a) return;

        var href = a.getAttribute('href');

        if (!href || href === '#') return;

        try {
            sessionStorage.setItem(KEY, href);
        } catch (err) {}
    });

})();
</script>