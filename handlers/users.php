<?php
/**
 * Kelola akun. BR-9: Admin hanya boleh membuat akun Tutor; akun CS/Manager/
 * Admin/Superadmin hanya bisa dibuat Superadmin. BR-10: minimal 1 Superadmin
 * aktif harus selalu ada. BR-11: reset password Member hanya oleh Admin.
 */

function h_users_list(): void {
    $u = require_role(['superadmin', 'admin', 'manager']);
    $role = $_GET['role'] ?? null;

    if ($u['role'] === 'admin' && $role !== null && !in_array($role, ['tutor', 'member'], true)) {
        json_error('Admin hanya bisa melihat daftar Tutor atau Member.', 403);
    }
    if ($u['role'] === 'manager' && $role !== null && $role !== 'tutor') {
        json_error('Manager hanya bisa melihat daftar Tutor.', 403);
    }

    $sql = 'SELECT id, username, role, nama, no_id, email, whatsapp, is_active, created_at
            FROM users WHERE 1=1';
    $params = [];
    if ($role) {
        $sql .= ' AND role = ?';
        $params[] = $role;
    }
    $sql .= ' ORDER BY nama';
    $stmt = lms_pdo()->prepare($sql);
    $stmt->execute($params);
    json_ok(['users' => $stmt->fetchAll()]);
}

function h_users_create(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['nama', 'username']);
    $role = $data['role'] ?? 'tutor';

    if ($u['role'] === 'admin') {
        $role = 'tutor'; // BR-9: Admin hanya boleh membuat akun Tutor
    } else {
        $allowed = ['member', 'cs', 'admin', 'manager', 'tutor', 'superadmin'];
        if (!in_array($role, $allowed, true)) json_error('Role tidak valid.', 422);
    }

    $pdo = lms_pdo();
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $chk->execute([$data['username']]);
    if ($chk->fetch()) json_error('Username sudah dipakai.', 422);

    if (!empty($data['no_id'])) {
        $chkId = $pdo->prepare('SELECT id FROM users WHERE no_id = ?');
        $chkId->execute([$data['no_id']]);
        if ($chkId->fetch()) json_error('No ID sudah dipakai user lain.', 422);
    }

    $mustChange = 0;
    if ($role === 'member') {
        $plainPassword = default_password_from_dob($data['tanggal_lahir'] ?? null); // BR-3
        $mustChange = 1;
    } elseif (!empty($data['password'])) {
        $plainPassword = $data['password'];
    } else {
        $plainPassword = bin2hex(random_bytes(4)); // 8 karakter acak kalau tidak diisi
        $mustChange = 1;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, must_change_password, role, nama, no_id, tanggal_lahir, email, whatsapp)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $data['username'],
        hash_password($plainPassword),
        $mustChange,
        $role,
        $data['nama'],
        $data['no_id'] ?? null,
        $data['tanggal_lahir'] ?? null,
        $data['email'] ?? null,
        $data['whatsapp'] ?? null,
    ]);
    $newId = (int)$pdo->lastInsertId();

    log_activity((int)$u['id'], 'buat_akun', ['user_id' => $newId, 'role' => $role]);
    json_ok(['id' => $newId, 'password_awal' => $plainPassword]);
}

function h_users_set_active(): void {
    $u = require_role(['superadmin']);
    $data = body_json();
    require_fields($data, ['id', 'aktif']);
    $pdo = lms_pdo();

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$data['id']]);
    $target = $stmt->fetch();
    if (!$target) json_error('User tidak ditemukan.', 404);

    // BR-10: minimal 1 Superadmin aktif harus selalu ada.
    if (!$data['aktif'] && $target['role'] === 'superadmin'
        && count_active_superadmin($pdo, (int)$data['id']) < 1) {
        json_error('Tidak bisa menonaktifkan — minimal harus ada 1 akun Superadmin aktif (BR-10).', 422);
    }

    $upd = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $upd->execute([$data['aktif'] ? 1 : 0, $data['id']]);

    log_activity((int)$u['id'], $data['aktif'] ? 'aktifkan_akun' : 'nonaktifkan_akun', ['user_id' => $data['id']]);
    json_ok();
}

/** BR-11: reset password Member hanya oleh Admin, kembali ke default tanggal lahir. */
function h_member_reset_password(): void {
    $u = require_role(['admin', 'superadmin']);
    $data = body_json();
    require_fields($data, ['user_id']);
    $pdo = lms_pdo();

    $stmt = $pdo->prepare("SELECT id, tanggal_lahir FROM users WHERE id = ? AND role = 'member'");
    $stmt->execute([$data['user_id']]);
    $m = $stmt->fetch();
    if (!$m) json_error('Member tidak ditemukan.', 404);

    $newPass = default_password_from_dob($m['tanggal_lahir']);
    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?');
    $upd->execute([hash_password($newPass), $m['id']]);

    log_activity((int)$u['id'], 'reset_password_member', ['member_id' => $m['id']]);
    json_ok(['password_baru' => $newPass]);
}
