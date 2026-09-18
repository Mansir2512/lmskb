<?php
/**
 * Salin file ini jadi config.php (di folder yang sama), lalu isi sesuai
 * database di hosting kamu. Jangan commit/upload config.php ke tempat publik
 * kalau pakai Git.
 */
return [
    'db_host'    => 'localhost',
    'db_name'    => 'lms_kelasbahasa',
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_charset' => 'utf8mb4',

    // String acak, dipakai untuk verifikasi tanda tangan webhook ERP (HMAC).
    // Generate salah satu dengan menjalankan di terminal:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'GANTI_DENGAN_STRING_ACAK_PANJANG',

    // Kunci sekali-pakai yang diminta install.php sebelum membuat tabel &
    // akun Superadmin pertama. Ganti dulu sebelum upload ke hosting.
    'install_key' => 'GANTI_KUNCI_INSTALL_INI',

    // Folder tempat file materi (PPT/PDF) disimpan, relatif terhadap folder proyek.
    'upload_dir' => 'uploads/materi',
];
