<?php
/** Login / logout / ganti password — dipakai semua role. */

function h_login(): void {
    $data = body_json();
    require_fields($data, ['username', 'password']);

    $stmt = lms_pdo()->prepare(
        'SELECT id, username, password_hash, role, nama, no_id, must_change_password
         FROM users WHERE username = ? AND is_active = 1'
    );
    $stmt->execute([trim($data['username'])]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($data['password'], $u['password_hash'])) {
        json_error('Username atau password salah.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];

    log_activity((int)$u['id'], 'login', ['username' => $u['username']]);

    json_ok([
        'user' => [
            'id'                   => (int)$u['id'],
            'username'             => $u['username'],
            'role'                 => $u['role'],
            'nama'                 => $u['nama'],
            'no_id'                => $u['no_id'],
            'must_change_password' => (bool)$u['must_change_password'],
        ],
    ]);
}

function h_logout(): void {
    $u = current_user();
    if ($u) log_activity((int)$u['id'], 'logout');
    $_SESSION = [];
    session_destroy();
    json_ok();
}

function h_me(): void {
    $u = current_user();
    if (!$u) json_error('Belum login.', 401);
    json_ok([
        'user' => [
            'id'                   => (int)$u['id'],
            'username'             => $u['username'],
            'role'                 => $u['role'],
            'nama'                 => $u['nama'],
            'no_id'                => $u['no_id'],
            'must_change_password' => (bool)$u['must_change_password'],
        ],
    ]);
}

/**
 * 🔸 Rekomendasi NFR §15: wajibkan ganti password saat login pertama,
 * karena password default (tanggal lahir) mudah ditebak.
 */
function h_change_password(): void {
    $u = require_login();
    $data = body_json();
    require_fields($data, ['password_baru']);

    if (strlen($data['password_baru']) < 6) {
        json_error('Password baru minimal 6 karakter.', 422);
    }

    // Kalau bukan status wajib-ganti (must_change_password), verifikasi password lama dulu.
    if (empty($u['must_change_password'])) {
        require_fields($data, ['password_lama']);
        $stmt = lms_pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($data['password_lama'], $row['password_hash'])) {
            json_error('Password lama salah.', 401);
        }
    }

    $stmt = lms_pdo()->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
    );
    $stmt->execute([hash_password($data['password_baru']), $u['id']]);

    log_activity((int)$u['id'], 'ganti_password');
    json_ok();
}
