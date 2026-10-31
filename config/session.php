<?php
$folder_sesi = __DIR__ . '/../sessions';

if (!is_dir($folder_sesi)) {
    mkdir($folder_sesi, 0700, true);
}

$htaccess = $folder_sesi . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

ini_set('session.save_path', $folder_sesi);
ini_set('session.gc_maxlifetime', 28800);   // 8 jam
ini_set('session.use_strict_mode', 1);

session_set_cookie_params([
    'lifetime' => 0,        
    'path'     => '/',    
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}