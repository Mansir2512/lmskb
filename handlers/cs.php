<?php
/** BR-8: CS read-only — cari member by No ID, tidak ada aksi ubah data. */

function h_cs_cari(): void {
    require_role(['cs', 'superadmin', 'admin']);
    $noId = $_GET['no_id'] ?? null;
    require_fields(['no_id' => $noId], ['no_id']);
    $pdo = lms_pdo();

    $mem = $pdo->prepare("SELECT id, nama, no_id, email, whatsapp FROM users WHERE no_id = ? AND role = 'member'");
    $mem->execute([$noId]);
    $member = $mem->fetch();
    if (!$member) json_error('Member dengan No ID tersebut tidak ditemukan.', 404);

    $kelas = $pdo->prepare(
        "SELECT e.id AS enrollment_id, e.status, k.id AS kelas_id, k.status AS kelas_status,
                lk.nama AS level_nama, p.nama AS program_nama, us.nama AS tutor_nama,
                (SELECT COUNT(*) FROM absensi a WHERE a.enrollment_id = e.id AND a.hadir = 1) AS hadir,
                (SELECT COUNT(*) FROM absensi a WHERE a.enrollment_id = e.id) AS total_sesi_tercatat,
                s.status AS sertifikat_status
         FROM enrollment e
         JOIN kelas k ON k.id = e.kelas_id
         JOIN level_kursus lk ON lk.id = k.level_id
         JOIN program p ON p.id = lk.program_id
         LEFT JOIN users us ON us.id = k.tutor_id
         LEFT JOIN sertifikat s ON s.enrollment_id = e.id
         WHERE e.member_id = ?
         ORDER BY e.tanggal_daftar DESC"
    );
    $kelas->execute([$member['id']]);
    $kelasList = $kelas->fetchAll();

    foreach ($kelasList as &$k) {
        $nilai = $pdo->prepare(
            "SELECT t.jenis, n.skor FROM nilai n JOIN test t ON t.id = n.test_id WHERE n.enrollment_id = ?"
        );
        $nilai->execute([$k['enrollment_id']]);
        $k['nilai'] = $nilai->fetchAll();
    }

    json_ok(['member' => $member, 'riwayat_kelas' => $kelasList]);
}
