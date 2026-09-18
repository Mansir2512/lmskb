<?php
/**
 * Kelas, Enrollment, Sertifikat, Pindah Kelas, Cuti.
 * BR-1/BR-17: kuota beda per tipe (Regular 5–10, Private 1, Private Couple 2).
 * BR-2: kelas Regular tetap bisa jalan meski belum capai kuota minimal.
 * BR-4/BR-5/Q11: kelayakan sertifikat dicek manual per Member oleh Admin.
 * BR-12: pindah kelas maksimal H+2. BR-13: cuti maksimal H-3.
 */

function h_kelas_list(): void {
    $u = require_role(['superadmin', 'admin', 'manager', 'tutor', 'cs']);
    $pdo = lms_pdo();
    $sql = 'SELECT k.*, lk.nama AS level_nama, p.nama AS program_nama, us.nama AS tutor_nama,
                   (SELECT COUNT(*) FROM enrollment e WHERE e.kelas_id = k.id) AS jumlah_member
            FROM kelas k
            JOIN level_kursus lk ON lk.id = k.level_id
            JOIN program p ON p.id = lk.program_id
            LEFT JOIN users us ON us.id = k.tutor_id
            WHERE 1=1';
    $params = [];
    if ($u['role'] === 'tutor') {
        $sql .= ' AND k.tutor_id = ?';
        $params[] = $u['id'];
    }
    $sql .= ' ORDER BY k.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(['kelas' => $stmt->fetchAll()]);
}

/** BR-1/BR-17: kuota mengikuti tipe kelas; Regular boleh dioverride dalam batas wajar. */
function h_kelas_create(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['level_id', 'tipe']);

    $tipe = $data['tipe'];
    if (!in_array($tipe, ['regular', 'private', 'private_couple'], true)) {
        json_error('Tipe kelas tidak valid.', 422);
    }
    $default = kuota_default_untuk_tipe($tipe);
    $kuotaMin = $tipe === 'regular' ? ($data['kuota_min'] ?? $default['min']) : $default['min'];
    $kuotaMaks = $tipe === 'regular' ? ($data['kuota_maks'] ?? $default['maks']) : $default['maks'];

    $pdo = lms_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO kelas (level_id, tutor_id, tipe, kuota_min, kuota_maks, jadwal, tanggal_mulai, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $data['level_id'], $data['tutor_id'] ?? null, $tipe, $kuotaMin, $kuotaMaks,
        $data['jadwal'] ?? null, $data['tanggal_mulai'] ?? null, 'dibuka', $u['id'],
    ]);
    $id = (int)$pdo->lastInsertId();

    log_activity((int)$u['id'], 'buka_kelas', ['id' => $id, 'level_id' => $data['level_id'], 'tipe' => $tipe]);
    json_ok(['id' => $id]);
}

function h_kelas_update_status(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['id', 'status']);
    if (!in_array($data['status'], ['draft', 'dibuka', 'berjalan', 'selesai', 'arsip'], true)) {
        json_error('Status tidak valid.', 422);
    }
    lms_pdo()->prepare('UPDATE kelas SET status = ? WHERE id = ?')->execute([$data['status'], $data['id']]);
    log_activity((int)$u['id'], 'ubah_status_kelas', ['id' => $data['id'], 'status' => $data['status']]);
    json_ok();
}

/** BR-1: kelas Regular menolak penambahan member ke-11 (dan tipe lain menolak lewat kuota tetapnya). */
function h_enrollment_create(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['member_id', 'kelas_id']);
    $pdo = lms_pdo();

    $stmt = $pdo->prepare('SELECT kuota_maks FROM kelas WHERE id = ?');
    $stmt->execute([$data['kelas_id']]);
    $kelas = $stmt->fetch();
    if (!$kelas) json_error('Kelas tidak ditemukan.', 404);

    $cnt = $pdo->prepare('SELECT COUNT(*) c FROM enrollment WHERE kelas_id = ?');
    $cnt->execute([$data['kelas_id']]);
    if ((int)$cnt->fetch()['c'] >= (int)$kelas['kuota_maks']) {
        json_error('Kelas sudah penuh sesuai kuota (BR-1/BR-17).', 422);
    }

    $ins = $pdo->prepare(
        'INSERT INTO enrollment (member_id, kelas_id, paket_id, status) VALUES (?,?,?,?)'
    );
    $ins->execute([$data['member_id'], $data['kelas_id'], $data['paket_id'] ?? null, 'terdaftar']);
    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO sertifikat (enrollment_id, status) VALUES (?, 'terkunci')")->execute([$id]);

    log_activity((int)$u['id'], 'daftarkan_member_ke_kelas', ['enrollment_id' => $id]);
    json_ok(['id' => $id]);
}

/**
 * BR-12/Q6: pindah kelas maksimal H+2 dari tanggal_mulai kelas asal, dengan
 * syarat kelas tujuan berstatus dibuka/berjalan (tersedia).
 */
function h_enrollment_pindah_kelas(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['enrollment_id', 'kelas_tujuan_id']);
    $pdo = lms_pdo();

    $stmt = $pdo->prepare(
        'SELECT e.id, e.kelas_id, k.tanggal_mulai FROM enrollment e
         JOIN kelas k ON k.id = e.kelas_id WHERE e.id = ?'
    );
    $stmt->execute([$data['enrollment_id']]);
    $enr = $stmt->fetch();
    if (!$enr) json_error('Enrollment tidak ditemukan.', 404);

    if ($enr['tanggal_mulai']) {
        $mulai = new DateTime($enr['tanggal_mulai']);
        $batas = (clone $mulai)->modify('+2 days');
        if (new DateTime() > $batas) {
            json_error('Sudah lewat batas pindah kelas H+2 dari tanggal mulai (BR-12).', 422);
        }
    }

    $tuj = $pdo->prepare("SELECT id, status FROM kelas WHERE id = ?");
    $tuj->execute([$data['kelas_tujuan_id']]);
    $kelasTujuan = $tuj->fetch();
    if (!$kelasTujuan || !in_array($kelasTujuan['status'], ['dibuka', 'berjalan'], true)) {
        json_error('Kelas tujuan tidak tersedia/belum dibuka (BR-12).', 422);
    }

    $pdo->prepare('UPDATE enrollment SET kelas_id = ? WHERE id = ?')
        ->execute([$data['kelas_tujuan_id'], $data['enrollment_id']]);
    $pdo->prepare(
        'INSERT INTO pindah_kelas_log (enrollment_id, kelas_asal_id, kelas_tujuan_id, diproses_oleh) VALUES (?,?,?,?)'
    )->execute([$data['enrollment_id'], $enr['kelas_id'], $data['kelas_tujuan_id'], $u['id']]);

    log_activity((int)$u['id'], 'pindah_kelas', ['enrollment_id' => $data['enrollment_id']]);
    json_ok();
}

/** Kandidat sertifikat: enrollment di kelas berstatus selesai, belum diputuskan lulus/tidak. */
function h_sertifikat_kandidat(): void {
    require_role(['superadmin', 'admin']);
    $pdo = lms_pdo();
    $sql = "SELECT e.id AS enrollment_id, us.nama AS member_nama, us.no_id, k.id AS kelas_id,
                   lk.nama AS level_nama,
                   (SELECT COUNT(*) FROM absensi a WHERE a.enrollment_id = e.id AND a.hadir = 1) AS hadir,
                   (SELECT COUNT(*) FROM absensi a WHERE a.enrollment_id = e.id) AS total_sesi_tercatat
            FROM enrollment e
            JOIN users us ON us.id = e.member_id
            JOIN kelas k ON k.id = e.kelas_id
            JOIN level_kursus lk ON lk.id = k.level_id
            WHERE k.status = 'selesai' AND e.status IN ('terdaftar','aktif')
            ORDER BY k.id";
    json_ok(['kandidat' => $pdo->query($sql)->fetchAll()]);
}

/**
 * BR-4/Q11: Admin cek manual per Member (bukan otomatis sistem).
 * lulus=true -> enrollment=bersertifikat, sertifikat=terbuka.
 * lulus=false -> enrollment=tidak_lulus, sertifikat tetap terkunci (Q4).
 */
function h_sertifikat_putuskan(): void {
    $u = require_role(['superadmin', 'admin']);
    $data = body_json();
    require_fields($data, ['enrollment_id', 'lulus']);
    $pdo = lms_pdo();

    if ($data['lulus']) {
        $pdo->prepare("UPDATE enrollment SET status = 'bersertifikat' WHERE id = ?")
            ->execute([$data['enrollment_id']]);
        // upsert: enrollment yang masuk lewat webhook ERP tidak selalu sudah punya baris sertifikat.
        $pdo->prepare(
            "INSERT INTO sertifikat (enrollment_id, status, dibuka_oleh, waktu_buka)
             VALUES (?, 'terbuka', ?, ?)
             ON DUPLICATE KEY UPDATE status = 'terbuka', dibuka_oleh = VALUES(dibuka_oleh), waktu_buka = VALUES(waktu_buka)"
        )->execute([$data['enrollment_id'], $u['id'], pdo_now()]);

        $mem = $pdo->prepare('SELECT member_id FROM enrollment WHERE id = ?');
        $mem->execute([$data['enrollment_id']]);
        $row = $mem->fetch();
        if ($row) notify((int)$row['member_id'], 'Sertifikat sudah bisa diunduh', 'Selamat! Sertifikat kelas kamu sudah dibuka.');

        log_activity((int)$u['id'], 'buka_sertifikat', ['enrollment_id' => $data['enrollment_id']]);
    } else {
        $pdo->prepare("UPDATE enrollment SET status = 'tidak_lulus' WHERE id = ?")
            ->execute([$data['enrollment_id']]);
        log_activity((int)$u['id'], 'tandai_tidak_lulus', ['enrollment_id' => $data['enrollment_id']]);
    }
    json_ok();
}

/** BR-13/Q6: cuti harus diajukan maksimal H-3 sebelum tanggal sesi yang dicuti. */
function h_cuti_ajukan(): void {
    $u = require_role(['member', 'admin', 'superadmin']);
    $data = body_json();
    require_fields($data, ['enrollment_id', 'tanggal_sesi_dicuti']);

    $batasAjukan = (new DateTime($data['tanggal_sesi_dicuti']))->modify('-3 days');
    if (new DateTime() > $batasAjukan) {
        json_error('Pengajuan cuti harus paling lambat H-3 sebelum tanggal sesi (BR-13).', 422);
    }

    $stmt = lms_pdo()->prepare(
        'INSERT INTO cuti (enrollment_id, tanggal_sesi_dicuti, keterangan) VALUES (?,?,?)'
    );
    $stmt->execute([$data['enrollment_id'], $data['tanggal_sesi_dicuti'], $data['keterangan'] ?? null]);
    json_ok(['id' => (int)lms_pdo()->lastInsertId()]);
}

function h_cuti_list(): void {
    require_role(['superadmin', 'admin', 'tutor']);
    $kelasId = $_GET['kelas_id'] ?? null;
    $sql = "SELECT c.*, us.nama AS member_nama FROM cuti c
            JOIN enrollment e ON e.id = c.enrollment_id
            JOIN users us ON us.id = e.member_id WHERE 1=1";
    $params = [];
    if ($kelasId) { $sql .= ' AND e.kelas_id = ?'; $params[] = $kelasId; }
    $sql .= ' ORDER BY c.tanggal_pengajuan DESC';
    $stmt = lms_pdo()->prepare($sql);
    $stmt->execute($params);
    json_ok(['cuti' => $stmt->fetchAll()]);
}
