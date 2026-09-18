<?php

function h_notifikasi_list(): void {
    $u = require_login();
    $stmt = lms_pdo()->prepare('SELECT * FROM notifikasi WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
    $stmt->execute([$u['id']]);
    json_ok(['notifikasi' => $stmt->fetchAll()]);
}

function h_notifikasi_read(): void {
    $u = require_login();
    $data = body_json();
    require_fields($data, ['id']);
    $stmt = lms_pdo()->prepare('UPDATE notifikasi SET dibaca = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$data['id'], $u['id']]);
    json_ok();
}
