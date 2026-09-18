<?php
/**
 * Dashboard Tutor. BR-7 (test terkunci sampai Tutor buka akses per kelas),
 * dan isolasi: Tutor hanya bisa bertindak di kelas yang dia pegang sendiri.
 */

function assert_kelas_milik_tutor(PDO $pdo, int $kelasId, int $tutorId): void {
    $stmt = $pdo->prepare('SELECT id FROM kelas WHERE id = ? AND tutor_id = ?');
    $stmt->execute([$kelasId, $tutorId]);
    if (!$stmt->fetch()) {
        json_error('Kelas ini bukan kelas yang kamu pegang.', 403);
    }
}

function h_kelas_roster(): void {
    $u = require_role(['tutor', 'superadmin', 'admin', 'manager']);
    $kelasId = $_GET['kelas_id'] ?? null;
    require_fields(['kelas_id' => $kelasId], ['kelas_id']);
    $pdo = lms_pdo();
    if ($u['role'] === 'tutor') assert_kelas_milik_tutor($pdo, (int)$kelasId, (int)$u['id']);

    $stmt = $pdo->prepare(
        'SELECT e.id AS enrollment_id, e.status, us.id AS member_id, us.nama, us.no_id
         FROM enrollment e JOIN users us ON us.id = e.member_id
         WHERE e.kelas_id = ? ORDER BY us.nama'
    );
    $stmt->execute([$kelasId]);
    json_ok(['roster' => $stmt->fetchAll()]);
}

/** records: [{enrollment_id, hadir: true/false}] */
function h_absensi_input(): void {
    $u = require_role(['tutor', 'admin', 'superadmin']);
    $data = body_json();
    require_fields($data, ['kelas_id', 'tanggal_sesi', 'records']);
    $pdo = lms_pdo();
    if ($u['role'] === 'tutor') assert_kelas_milik_tutor($pdo, (int)$data['kelas_id'], (int)$u['id']);

    $stmt = $pdo->prepare(
        'INSERT INTO absensi (enrollment_id, tanggal_sesi, hadir, dicatat_oleh)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE hadir = VALUES(hadir), dicatat_oleh = VALUES(dicatat_oleh)'
    );
    foreach ($data['records'] as $r) {
        $stmt->execute([$r['enrollment_id'], $data['tanggal_sesi'], !empty($r['hadir']) ? 1 : 0, $u['id']]);
    }
    log_activity((int)$u['id'], 'input_absensi', ['kelas_id' => $data['kelas_id'], 'tanggal' => $data['tanggal_sesi']]);
    json_ok();
}

function h_absensi_list(): void {
    $u = require_role(['tutor', 'admin', 'superadmin', 'manager']);
    $kelasId = $_GET['kelas_id'] ?? null;
    require_fields(['kelas_id' => $kelasId], ['kelas_id']);
    $pdo = lms_pdo();
    if ($u['role'] === 'tutor') assert_kelas_milik_tutor($pdo, (int)$kelasId, (int)$u['id']);
    $stmt = $pdo->prepare(
        'SELECT a.*, us.nama AS member_nama FROM absensi a
         JOIN enrollment e ON e.id = a.enrollment_id
         JOIN users us ON us.id = e.member_id
         WHERE e.kelas_id = ? ORDER BY a.tanggal_sesi DESC'
    );
    $stmt->execute([$kelasId]);
    json_ok(['absensi' => $stmt->fetchAll()]);
}

function h_kegiatan_input(): void {
    $u = require_role(['tutor']);
    $data = body_json();
    require_fields($data, ['kelas_id', 'tanggal', 'catatan']);
    $pdo = lms_pdo();
    assert_kelas_milik_tutor($pdo, (int)$data['kelas_id'], (int)$u['id']);

    $stmt = $pdo->prepare('INSERT INTO kegiatan_harian (kelas_id, tutor_id, tanggal, catatan) VALUES (?,?,?,?)');
    $stmt->execute([$data['kelas_id'], $u['id'], $data['tanggal'], $data['catatan']]);
    json_ok(['id' => (int)$pdo->lastInsertId()]);
}

function h_kegiatan_list(): void {
    $u = require_role(['tutor', 'admin', 'superadmin', 'manager']);
    $kelasId = $_GET['kelas_id'] ?? null;
    require_fields(['kelas_id' => $kelasId], ['kelas_id']);
    $pdo = lms_pdo();
    if ($u['role'] === 'tutor') assert_kelas_milik_tutor($pdo, (int)$kelasId, (int)$u['id']);
    $stmt = $pdo->prepare('SELECT * FROM kegiatan_harian WHERE kelas_id = ? ORDER BY tanggal DESC');
    $stmt->execute([$kelasId]);
    json_ok(['kegiatan' => $stmt->fetchAll()]);
}

/** BR-6: buka/tutup akses test untuk seluruh member di 1 kelas. */
function h_test_akses_toggle(): void {
    $u = require_role(['tutor']);
    $data = body_json();
    require_fields($data, ['kelas_id', 'test_id', 'terbuka']);
    $pdo = lms_pdo();
    assert_kelas_milik_tutor($pdo, (int)$data['kelas_id'], (int)$u['id']);

    $stmt = $pdo->prepare(
        'INSERT INTO test_akses (kelas_id, test_id, terbuka, dibuka_oleh, waktu_buka)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE terbuka = VALUES(terbuka), dibuka_oleh = VALUES(dibuka_oleh), waktu_buka = VALUES(waktu_buka)'
    );
    $stmt->execute([$data['kelas_id'], $data['test_id'], $data['terbuka'] ? 1 : 0, $u['id'], pdo_now()]);

    if ($data['terbuka']) {
        $roster = $pdo->prepare('SELECT member_id FROM enrollment WHERE kelas_id = ?');
        $roster->execute([$data['kelas_id']]);
        foreach ($roster->fetchAll() as $r) {
            notify((int)$r['member_id'], 'Akses test dibuka', 'Tutor sudah membuka akses test untuk kelasmu.');
        }
    }
    log_activity((int)$u['id'], 'toggle_akses_test', $data);
    json_ok();
}

function h_nilai_input(): void {
    $u = require_role(['tutor']);
    $data = body_json();
    require_fields($data, ['enrollment_id', 'test_id', 'skor']);
    $pdo = lms_pdo();

    $chk = $pdo->prepare(
        'SELECT k.tutor_id FROM enrollment e JOIN kelas k ON k.id = e.kelas_id WHERE e.id = ?'
    );
    $chk->execute([$data['enrollment_id']]);
    $row = $chk->fetch();
    if (!$row || (int)$row['tutor_id'] !== (int)$u['id']) {
        json_error('Kamu tidak bisa menilai member ini.', 403);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO nilai (enrollment_id, test_id, skor, diinput_oleh) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE skor = VALUES(skor), diinput_oleh = VALUES(diinput_oleh)'
    );
    $stmt->execute([$data['enrollment_id'], $data['test_id'], $data['skor'], $u['id']]);
    json_ok();
}

function h_nilai_list(): void {
    $u = require_role(['tutor', 'admin', 'superadmin', 'manager']);
    $kelasId = $_GET['kelas_id'] ?? null;
    require_fields(['kelas_id' => $kelasId], ['kelas_id']);
    $pdo = lms_pdo();
    if ($u['role'] === 'tutor') assert_kelas_milik_tutor($pdo, (int)$kelasId, (int)$u['id']);
    $stmt = $pdo->prepare(
        'SELECT n.*, us.nama AS member_nama, t.jenis FROM nilai n
         JOIN enrollment e ON e.id = n.enrollment_id
         JOIN users us ON us.id = e.member_id
         JOIN test t ON t.id = n.test_id
         WHERE e.kelas_id = ? ORDER BY us.nama'
    );
    $stmt->execute([$kelasId]);
    json_ok(['nilai' => $stmt->fetchAll()]);
}

function h_zoom_input(): void {
    $u = require_role(['tutor']);
    $data = body_json();
    require_fields($data, ['kelas_id', 'tanggal_sesi']);
    $pdo = lms_pdo();
    assert_kelas_milik_tutor($pdo, (int)$data['kelas_id'], (int)$u['id']);

    $stmt = $pdo->prepare(
        'INSERT INTO zoom_link (kelas_id, tanggal_sesi, link, link_record, diinput_oleh) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE link = VALUES(link), link_record = VALUES(link_record), diinput_oleh = VALUES(diinput_oleh)'
    );
    $stmt->execute([
        $data['kelas_id'], $data['tanggal_sesi'], $data['link'] ?? null, $data['link_record'] ?? null, $u['id'],
    ]);

    if (!empty($data['link'])) {
        $roster = $pdo->prepare('SELECT member_id FROM enrollment WHERE kelas_id = ?');
        $roster->execute([$data['kelas_id']]);
        foreach ($roster->fetchAll() as $r) {
            notify((int)$r['member_id'], 'Link Zoom sesi ' . $data['tanggal_sesi'], $data['link']);
        }
    }
    json_ok();
}
