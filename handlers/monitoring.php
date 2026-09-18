<?php
/** Monitoring lintas kelas untuk Manager (dan Superadmin/Admin, read-only). */

function h_monitoring_kehadiran(): void {
    require_role(['superadmin', 'admin', 'manager']);
    $sql = "SELECT k.id AS kelas_id, lk.nama AS level_nama, us.nama AS tutor_nama,
                   COUNT(DISTINCT a.id) AS total_absensi_tercatat,
                   SUM(a.hadir) AS total_hadir
            FROM kelas k
            JOIN level_kursus lk ON lk.id = k.level_id
            LEFT JOIN users us ON us.id = k.tutor_id
            LEFT JOIN enrollment e ON e.kelas_id = k.id
            LEFT JOIN absensi a ON a.enrollment_id = e.id
            WHERE k.status IN ('berjalan','selesai')
            GROUP BY k.id
            ORDER BY k.created_at DESC";
    json_ok(['kehadiran' => lms_pdo()->query($sql)->fetchAll()]);
}

function h_monitoring_nilai(): void {
    require_role(['superadmin', 'admin', 'manager']);
    $sql = "SELECT k.id AS kelas_id, lk.nama AS level_nama, t.jenis,
                   COUNT(n.id) AS jumlah_dinilai, AVG(n.skor) AS rata_rata
            FROM kelas k
            JOIN level_kursus lk ON lk.id = k.level_id
            JOIN test t ON t.level_id = k.level_id
            LEFT JOIN enrollment e ON e.kelas_id = k.id
            LEFT JOIN nilai n ON n.enrollment_id = e.id AND n.test_id = t.id
            GROUP BY k.id, t.id
            ORDER BY k.id, FIELD(t.jenis,'pre','mid','final')";
    json_ok(['nilai' => lms_pdo()->query($sql)->fetchAll()]);
}

/** Proksi sederhana: kelas dengan 0 catatan kegiatan harian = belum ada materi dibawakan. */
function h_monitoring_materi(): void {
    require_role(['superadmin', 'admin', 'manager']);
    $sql = "SELECT k.id AS kelas_id, lk.nama AS level_nama, us.nama AS tutor_nama, k.status,
                   COUNT(kh.id) AS jumlah_sesi_tercatat,
                   MAX(kh.tanggal) AS sesi_terakhir
            FROM kelas k
            JOIN level_kursus lk ON lk.id = k.level_id
            LEFT JOIN users us ON us.id = k.tutor_id
            LEFT JOIN kegiatan_harian kh ON kh.kelas_id = k.id
            WHERE k.status IN ('berjalan','selesai')
            GROUP BY k.id
            ORDER BY jumlah_sesi_tercatat ASC";
    json_ok(['materi' => lms_pdo()->query($sql)->fetchAll()]);
}
