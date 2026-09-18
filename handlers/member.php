<?php
/** Dashboard Member. */

function h_member_dashboard(): void {
    $u = require_role(['member']);
    $pdo = lms_pdo();

    $kelas = $pdo->prepare(
        "SELECT e.id AS enrollment_id, e.status, k.id AS kelas_id, k.jadwal, k.status AS kelas_status,
                lk.nama AS level_nama, p.nama AS program_nama, us.nama AS tutor_nama
         FROM enrollment e
         JOIN kelas k ON k.id = e.kelas_id
         JOIN level_kursus lk ON lk.id = k.level_id
         JOIN program p ON p.id = lk.program_id
         LEFT JOIN users us ON us.id = k.tutor_id
         WHERE e.member_id = ?
         ORDER BY FIELD(k.status,'berjalan','dibuka','draft','selesai','arsip')"
    );
    $kelas->execute([$u['id']]);
    $kelasList = $kelas->fetchAll();

    foreach ($kelasList as &$k) {
        $zoom = $pdo->prepare(
            'SELECT tanggal_sesi, link, link_record FROM zoom_link WHERE kelas_id = ? AND tanggal_sesi >= CURDATE()
             ORDER BY tanggal_sesi ASC LIMIT 1'
        );
        $zoom->execute([$k['kelas_id']]);
        $k['zoom_terdekat'] = $zoom->fetch() ?: null;
    }

    $notif = $pdo->prepare('SELECT * FROM notifikasi WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $notif->execute([$u['id']]);

    json_ok(['kelas' => $kelasList, 'notifikasi' => $notif->fetchAll()]);
}

/** Status test per kelas Member: terkunci / tersedia / dikerjakan / dinilai (§10.3). */
function h_member_test_list(): void {
    $u = require_role(['member']);
    $enrollmentId = $_GET['enrollment_id'] ?? null;
    require_fields(['enrollment_id' => $enrollmentId], ['enrollment_id']);
    $pdo = lms_pdo();

    $own = $pdo->prepare('SELECT kelas_id FROM enrollment WHERE id = ? AND member_id = ?');
    $own->execute([$enrollmentId, $u['id']]);
    $enr = $own->fetch();
    if (!$enr) json_error('Enrollment tidak ditemukan.', 404);

    $stmt = $pdo->prepare(
        "SELECT t.id, t.jenis, t.judul,
                COALESCE(ta.terbuka, 0) AS terbuka,
                (SELECT COUNT(*) FROM test_submission ts WHERE ts.enrollment_id = ? AND ts.test_id = t.id) AS sudah_submit,
                n.skor
         FROM test t
         JOIN kelas k ON k.level_id = t.level_id AND k.id = ?
         LEFT JOIN test_akses ta ON ta.kelas_id = k.id AND ta.test_id = t.id
         LEFT JOIN nilai n ON n.enrollment_id = ? AND n.test_id = t.id
         ORDER BY FIELD(t.jenis,'pre','mid','final')"
    );
    $stmt->execute([$enrollmentId, $enr['kelas_id'], $enrollmentId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if ($r['skor'] !== null) $r['status'] = 'dinilai';
        elseif ($r['sudah_submit']) $r['status'] = 'dikerjakan';
        elseif ($r['terbuka']) $r['status'] = 'tersedia';
        else $r['status'] = 'terkunci';
    }
    json_ok(['test' => $rows]);
}

function h_member_test_submit(): void {
    $u = require_role(['member']);
    $data = body_json();
    require_fields($data, ['enrollment_id', 'test_id']);
    $pdo = lms_pdo();

    $own = $pdo->prepare('SELECT kelas_id FROM enrollment WHERE id = ? AND member_id = ?');
    $own->execute([$data['enrollment_id'], $u['id']]);
    $enr = $own->fetch();
    if (!$enr) json_error('Enrollment tidak ditemukan.', 404);

    $akses = $pdo->prepare('SELECT terbuka FROM test_akses WHERE kelas_id = ? AND test_id = ?');
    $akses->execute([$enr['kelas_id'], $data['test_id']]);
    $a = $akses->fetch();
    if (!$a || !$a['terbuka']) json_error('Akses test belum dibuka Tutor (BR-6).', 403);

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO test_submission (enrollment_id, test_id) VALUES (?, ?)'
    );
    $stmt->execute([$data['enrollment_id'], $data['test_id']]);
    json_ok();
}

function h_member_sertifikat_list(): void {
    $u = require_role(['member']);
    $pdo = lms_pdo();
    $stmt = $pdo->prepare(
        "SELECT s.*, k.id AS kelas_id, lk.nama AS level_nama
         FROM sertifikat s
         JOIN enrollment e ON e.id = s.enrollment_id
         JOIN kelas k ON k.id = e.kelas_id
         JOIN level_kursus lk ON lk.id = k.level_id
         WHERE e.member_id = ?"
    );
    $stmt->execute([$u['id']]);
    json_ok(['sertifikat' => $stmt->fetchAll()]);
}

function h_member_sertifikat_unduh(): void {
    $u = require_role(['member']);
    $data = body_json();
    require_fields($data, ['sertifikat_id']);
    $pdo = lms_pdo();
    $stmt = $pdo->prepare(
        "SELECT s.id FROM sertifikat s JOIN enrollment e ON e.id = s.enrollment_id
         WHERE s.id = ? AND e.member_id = ? AND s.status IN ('terbuka','diunduh')"
    );
    $stmt->execute([$data['sertifikat_id'], $u['id']]);
    if (!$stmt->fetch()) json_error('Sertifikat belum dibuka Admin, atau bukan milikmu.', 403);

    $pdo->prepare("UPDATE sertifikat SET status = 'diunduh' WHERE id = ?")->execute([$data['sertifikat_id']]);
    json_ok(); // frontend menampilkan halaman cetak/PDF sederhana (window.print())
}

/** Q5: percakapan ke kelas (grup) atau ke tutor (1:1). */
function h_percakapan_list(): void {
    $u = require_login();
    $kelasId = $_GET['kelas_id'] ?? null;
    $target = $_GET['target'] ?? 'kelas';
    require_fields(['kelas_id' => $kelasId], ['kelas_id']);
    $pdo = lms_pdo();

    if ($u['role'] === 'member') {
        $own = $pdo->prepare('SELECT id FROM enrollment WHERE kelas_id = ? AND member_id = ?');
        $own->execute([$kelasId, $u['id']]);
        if (!$own->fetch()) json_error('Kamu tidak terdaftar di kelas ini.', 403);
    } elseif ($u['role'] === 'tutor') {
        assert_kelas_milik_tutor($pdo, (int)$kelasId, (int)$u['id']);
    }

    $stmt = $pdo->prepare(
        "SELECT pc.*, us.nama AS pengirim, us.role AS pengirim_role FROM percakapan pc
         JOIN users us ON us.id = pc.sender_id
         WHERE pc.kelas_id = ? AND pc.target = ?
         ORDER BY pc.created_at ASC"
    );
    $stmt->execute([$kelasId, $target]);
    json_ok(['pesan' => $stmt->fetchAll()]);
}

function h_percakapan_kirim(): void {
    $u = require_login();
    if (!in_array($u['role'], ['member', 'tutor'], true)) {
        json_error('Hanya Member dan Tutor yang bisa memakai fitur percakapan.', 403);
    }
    $data = body_json();
    require_fields($data, ['kelas_id', 'pesan']);
    $target = $data['target'] ?? 'kelas';
    $pdo = lms_pdo();

    if ($u['role'] === 'member') {
        $own = $pdo->prepare('SELECT id FROM enrollment WHERE kelas_id = ? AND member_id = ?');
        $own->execute([$data['kelas_id'], $u['id']]);
        if (!$own->fetch()) json_error('Kamu tidak terdaftar di kelas ini.', 403);
    } else {
        assert_kelas_milik_tutor($pdo, (int)$data['kelas_id'], (int)$u['id']);
    }

    $stmt = $pdo->prepare('INSERT INTO percakapan (kelas_id, sender_id, target, pesan) VALUES (?,?,?,?)');
    $stmt->execute([$data['kelas_id'], $u['id'], $target, $data['pesan']]);
    json_ok(['id' => (int)$pdo->lastInsertId()]);
}
