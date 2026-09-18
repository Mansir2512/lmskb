<?php
/**
 * Router API tunggal. Semua request frontend (assets/app.js) dan webhook ERP
 * lewat file ini: /api.php?action=nama_aksi
 *
 * GET  -> baca data (pakai query string, mis. ?action=kelas_list)
 * POST -> ubah data (body JSON, kecuali action=materi_upload yang multipart)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // jangan tampilkan error PHP mentah ke browser

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/handlers/auth.php';
require __DIR__ . '/handlers/users.php';
require __DIR__ . '/handlers/catalog.php';
require __DIR__ . '/handlers/kelas.php';
require __DIR__ . '/handlers/tutor.php';
require __DIR__ . '/handlers/member.php';
require __DIR__ . '/handlers/cs.php';
require __DIR__ . '/handlers/notifikasi.php';
require __DIR__ . '/handlers/monitoring.php';
require __DIR__ . '/handlers/erp.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // Auto-heal skema tiap request (murah — cukup 1 query kalau sudah up-to-date).
    lms_auto_heal_schema();

    switch ($action) {
        // ---- Auth ----
        case 'login':            h_login(); break;
        case 'logout':           h_logout(); break;
        case 'me':               h_me(); break;
        case 'change_password':  h_change_password(); break;

        // ---- Users (Superadmin/Admin) ----
        case 'users_list':            h_users_list(); break;
        case 'users_create':          h_users_create(); break;
        case 'users_set_active':      h_users_set_active(); break;
        case 'member_reset_password': h_member_reset_password(); break;

        // ---- Katalog: Program / Level / Paket / Materi / Test / SOP (Manager) ----
        case 'program_list':    h_program_list(); break;
        case 'program_create':  h_program_create(); break;
        case 'program_update':  h_program_update(); break;
        case 'program_delete':  h_program_delete(); break;

        case 'level_list':    h_level_list(); break;
        case 'level_create':  h_level_create(); break;
        case 'level_update':  h_level_update(); break;
        case 'level_delete':  h_level_delete(); break;

        case 'paket_list':    h_paket_list(); break;
        case 'paket_create':  h_paket_create(); break;
        case 'paket_update':  h_paket_update(); break;
        case 'paket_delete':  h_paket_delete(); break;

        case 'materi_list':    h_materi_list(); break;
        case 'materi_upload':  h_materi_upload(); break; // multipart/form-data
        case 'materi_delete':  h_materi_delete(); break;

        case 'test_list':       h_test_list(); break;
        case 'test_create':     h_test_create(); break;
        case 'test_soal_list':  h_test_soal_list(); break;
        case 'test_soal_add':   h_test_soal_add(); break;
        case 'test_delete':     h_test_delete(); break;

        case 'sop_list':    h_sop_list(); break;
        case 'sop_create':  h_sop_create(); break;
        case 'sop_delete':  h_sop_delete(); break;

        // ---- Kelas / Enrollment / Sertifikat / Pindah Kelas / Cuti (Admin) ----
        case 'kelas_list':           h_kelas_list(); break;
        case 'kelas_create':         h_kelas_create(); break;
        case 'kelas_update_status':  h_kelas_update_status(); break;

        case 'enrollment_create':        h_enrollment_create(); break;
        case 'enrollment_pindah_kelas':  h_enrollment_pindah_kelas(); break;

        case 'sertifikat_kandidat':   h_sertifikat_kandidat(); break;
        case 'sertifikat_putuskan':   h_sertifikat_putuskan(); break;

        case 'cuti_ajukan':  h_cuti_ajukan(); break;
        case 'cuti_list':    h_cuti_list(); break;

        // ---- Dashboard Tutor ----
        case 'kelas_roster':       h_kelas_roster(); break;
        case 'absensi_input':      h_absensi_input(); break;
        case 'absensi_list':       h_absensi_list(); break;
        case 'kegiatan_input':     h_kegiatan_input(); break;
        case 'kegiatan_list':      h_kegiatan_list(); break;
        case 'test_akses_toggle':  h_test_akses_toggle(); break;
        case 'nilai_input':        h_nilai_input(); break;
        case 'nilai_list':         h_nilai_list(); break;
        case 'zoom_input':         h_zoom_input(); break;

        // ---- Dashboard Member ----
        case 'member_dashboard':       h_member_dashboard(); break;
        case 'member_test_list':       h_member_test_list(); break;
        case 'member_test_submit':     h_member_test_submit(); break;
        case 'member_sertifikat_list': h_member_sertifikat_list(); break;
        case 'member_sertifikat_unduh': h_member_sertifikat_unduh(); break;

        case 'percakapan_list':  h_percakapan_list(); break;
        case 'percakapan_kirim': h_percakapan_kirim(); break;

        // ---- Dashboard CS ----
        case 'cs_cari':  h_cs_cari(); break;

        // ---- Notifikasi ----
        case 'notifikasi_list':  h_notifikasi_list(); break;
        case 'notifikasi_read':  h_notifikasi_read(); break;

        // ---- Monitoring (Manager) ----
        case 'monitoring_kehadiran':  h_monitoring_kehadiran(); break;
        case 'monitoring_nilai':      h_monitoring_nilai(); break;
        case 'monitoring_materi':     h_monitoring_materi(); break;

        // ---- Webhook ERP (BR-3, BR-18) ----
        case 'erp_webhook':  h_erp_webhook(); break;

        default:
            json_error("Aksi '$action' tidak dikenali.", 404);
    }
} catch (Throwable $e) {
    error_log('[LMS api.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error('Terjadi kesalahan di server. Detail sudah dicatat di error log hosting.', 500);
}
