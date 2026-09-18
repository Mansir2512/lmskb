<?php
/**
 * Katalog Program/Level/Paket (BR-15, BR-16), Materi & Test per Level (BR-7),
 * dan SOP (dibuat Manager, tampil di dashboard Tutor & Member).
 * Manager & Superadmin: full CRUD. Role lain: baca saja (untuk pilih Level
 * saat buka kelas, dsb).
 */

const CATALOG_READ_ROLES  = ['superadmin', 'admin', 'manager', 'tutor', 'cs'];
const CATALOG_WRITE_ROLES = ['superadmin', 'manager'];

// ---------- Program ----------

function h_program_list(): void {
    require_role(CATALOG_READ_ROLES);
    $stmt = lms_pdo()->query('SELECT * FROM program ORDER BY nama');
    json_ok(['program' => $stmt->fetchAll()]);
}

function h_program_create(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['nama']);
    $stmt = lms_pdo()->prepare('INSERT INTO program (nama, deskripsi) VALUES (?, ?)');
    $stmt->execute([$data['nama'], $data['deskripsi'] ?? null]);
    $id = (int)lms_pdo()->lastInsertId();
    log_activity((int)$u['id'], 'tambah_program', ['id' => $id, 'nama' => $data['nama']]);
    json_ok(['id' => $id]);
}

function h_program_update(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id', 'nama']);
    $stmt = lms_pdo()->prepare('UPDATE program SET nama = ?, deskripsi = ? WHERE id = ?');
    $stmt->execute([$data['nama'], $data['deskripsi'] ?? null, $data['id']]);
    log_activity((int)$u['id'], 'ubah_program', ['id' => $data['id']]);
    json_ok();
}

function h_program_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    lms_pdo()->prepare('DELETE FROM program WHERE id = ?')->execute([$data['id']]);
    log_activity((int)$u['id'], 'hapus_program', ['id' => $data['id']]);
    json_ok();
}

// ---------- Level ----------

function h_level_list(): void {
    require_role(CATALOG_READ_ROLES);
    $programId = $_GET['program_id'] ?? null;
    $sql = 'SELECT l.*, p.nama AS program_nama FROM level_kursus l
            JOIN program p ON p.id = l.program_id WHERE 1=1';
    $params = [];
    if ($programId) { $sql .= ' AND l.program_id = ?'; $params[] = $programId; }
    $sql .= ' ORDER BY p.nama, l.nama';
    $stmt = lms_pdo()->prepare($sql);
    $stmt->execute($params);
    json_ok(['level' => $stmt->fetchAll()]);
}

function h_level_create(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['program_id', 'nama']);
    $stmt = lms_pdo()->prepare(
        'INSERT INTO level_kursus (program_id, nama, deskripsi, rekomendasi, durasi_belajar) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([
        $data['program_id'], $data['nama'], $data['deskripsi'] ?? null,
        $data['rekomendasi'] ?? null, $data['durasi_belajar'] ?? null,
    ]);
    $id = (int)lms_pdo()->lastInsertId();
    log_activity((int)$u['id'], 'tambah_level', ['id' => $id, 'nama' => $data['nama']]);
    json_ok(['id' => $id]);
}

function h_level_update(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id', 'nama']);
    $stmt = lms_pdo()->prepare(
        'UPDATE level_kursus SET nama=?, deskripsi=?, rekomendasi=?, durasi_belajar=? WHERE id=?'
    );
    $stmt->execute([
        $data['nama'], $data['deskripsi'] ?? null, $data['rekomendasi'] ?? null,
        $data['durasi_belajar'] ?? null, $data['id'],
    ]);
    log_activity((int)$u['id'], 'ubah_level', ['id' => $data['id']]);
    json_ok();
}

function h_level_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    lms_pdo()->prepare('DELETE FROM level_kursus WHERE id = ?')->execute([$data['id']]);
    log_activity((int)$u['id'], 'hapus_level', ['id' => $data['id']]);
    json_ok();
}

// ---------- Paket ----------

function h_paket_list(): void {
    require_role(array_merge(CATALOG_READ_ROLES, ['member']));
    $stmt = lms_pdo()->query('SELECT * FROM paket WHERE aktif = 1 ORDER BY nama');
    $paket = $stmt->fetchAll();
    $pdo = lms_pdo();
    foreach ($paket as &$p) {
        $lv = $pdo->prepare(
            'SELECT lk.id, lk.nama FROM paket_level pl
             JOIN level_kursus lk ON lk.id = pl.level_id WHERE pl.paket_id = ?'
        );
        $lv->execute([$p['id']]);
        $p['level_pilihan'] = $lv->fetchAll();
    }
    json_ok(['paket' => $paket]);
}

function h_paket_create(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['nama', 'tipe']);
    $pdo = lms_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO paket (nama, tipe, harga, durasi, fasilitas, jadwal_jam, link_pembayaran)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $data['nama'], $data['tipe'], $data['harga'] ?? 0, $data['durasi'] ?? null,
        $data['fasilitas'] ?? null, $data['jadwal_jam'] ?? null, $data['link_pembayaran'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();

    if (!empty($data['level_ids']) && is_array($data['level_ids'])) {
        $ins = $pdo->prepare('INSERT INTO paket_level (paket_id, level_id) VALUES (?, ?)');
        foreach ($data['level_ids'] as $lid) $ins->execute([$id, (int)$lid]);
    }

    log_activity((int)$u['id'], 'tambah_paket', ['id' => $id, 'nama' => $data['nama']]);
    json_ok(['id' => $id]);
}

function h_paket_update(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id', 'nama', 'tipe']);
    $pdo = lms_pdo();
    $stmt = $pdo->prepare(
        'UPDATE paket SET nama=?, tipe=?, harga=?, durasi=?, fasilitas=?, jadwal_jam=?, link_pembayaran=? WHERE id=?'
    );
    $stmt->execute([
        $data['nama'], $data['tipe'], $data['harga'] ?? 0, $data['durasi'] ?? null,
        $data['fasilitas'] ?? null, $data['jadwal_jam'] ?? null, $data['link_pembayaran'] ?? null, $data['id'],
    ]);

    if (isset($data['level_ids']) && is_array($data['level_ids'])) {
        $pdo->prepare('DELETE FROM paket_level WHERE paket_id = ?')->execute([$data['id']]);
        $ins = $pdo->prepare('INSERT INTO paket_level (paket_id, level_id) VALUES (?, ?)');
        foreach ($data['level_ids'] as $lid) $ins->execute([$data['id'], (int)$lid]);
    }

    log_activity((int)$u['id'], 'ubah_paket', ['id' => $data['id']]);
    json_ok();
}

function h_paket_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    lms_pdo()->prepare('UPDATE paket SET aktif = 0 WHERE id = ?')->execute([$data['id']]);
    log_activity((int)$u['id'], 'nonaktifkan_paket', ['id' => $data['id']]);
    json_ok();
}

// ---------- Materi ----------

function h_materi_list(): void {
    require_role(array_merge(CATALOG_READ_ROLES, ['member']));
    $levelId = $_GET['level_id'] ?? null;
    require_fields(['level_id' => $levelId], ['level_id']);
    $stmt = lms_pdo()->prepare('SELECT id, judul, file_path, created_at FROM materi WHERE level_id = ? ORDER BY created_at DESC');
    $stmt->execute([$levelId]);
    json_ok(['materi' => $stmt->fetchAll()]);
}

/** Dipanggil khusus dari api.php sebagai multipart/form-data, bukan JSON. */
function h_materi_upload(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $levelId = $_POST['level_id'] ?? null;
    $judul = $_POST['judul'] ?? null;
    if (!$levelId || !$judul) json_error('level_id dan judul wajib diisi.', 422);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_error('File tidak ditemukan / gagal diupload.', 422);
    }

    $allowedExt = ['pdf', 'ppt', 'pptx'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        json_error('Format file harus PDF/PPT/PPTX.', 422);
    }

    $dir = require_upload_dir();
    $safeName = 'materi_' . $levelId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $safeName;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        json_error('Gagal menyimpan file di server.', 500);
    }

    $cfg = lms_config();
    $relPath = rtrim($cfg['upload_dir'], '/') . '/' . $safeName;
    $stmt = lms_pdo()->prepare('INSERT INTO materi (level_id, judul, file_path, uploaded_by) VALUES (?,?,?,?)');
    $stmt->execute([$levelId, $judul, $relPath, $u['id']]);
    $id = (int)lms_pdo()->lastInsertId();

    log_activity((int)$u['id'], 'upload_materi', ['id' => $id, 'level_id' => $levelId]);
    json_ok(['id' => $id, 'file_path' => $relPath]);
}

function h_materi_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    $pdo = lms_pdo();
    $stmt = $pdo->prepare('SELECT file_path FROM materi WHERE id = ?');
    $stmt->execute([$data['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $full = __DIR__ . '/../' . $row['file_path'];
        if (is_file($full)) @unlink($full);
    }
    $pdo->prepare('DELETE FROM materi WHERE id = ?')->execute([$data['id']]);
    log_activity((int)$u['id'], 'hapus_materi', ['id' => $data['id']]);
    json_ok();
}

// ---------- Test (bank soal pre/mid/final per Level) ----------

function h_test_list(): void {
    require_role(array_merge(CATALOG_READ_ROLES, ['member']));
    $levelId = $_GET['level_id'] ?? null;
    require_fields(['level_id' => $levelId], ['level_id']);
    $stmt = lms_pdo()->prepare('SELECT * FROM test WHERE level_id = ? ORDER BY FIELD(jenis, "pre","mid","final")');
    $stmt->execute([$levelId]);
    json_ok(['test' => $stmt->fetchAll()]);
}

function h_test_create(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['level_id', 'jenis', 'judul']);
    if (!in_array($data['jenis'], ['pre', 'mid', 'final'], true)) {
        json_error('Jenis test harus pre/mid/final.', 422);
    }
    $stmt = lms_pdo()->prepare('INSERT INTO test (level_id, jenis, judul, created_by) VALUES (?,?,?,?)');
    $stmt->execute([$data['level_id'], $data['jenis'], $data['judul'], $u['id']]);
    $id = (int)lms_pdo()->lastInsertId();
    log_activity((int)$u['id'], 'tambah_test', ['id' => $id, 'level_id' => $data['level_id'], 'jenis' => $data['jenis']]);
    json_ok(['id' => $id]);
}

function h_test_soal_list(): void {
    require_role(array_merge(CATALOG_READ_ROLES, ['member']));
    $testId = $_GET['test_id'] ?? null;
    require_fields(['test_id' => $testId], ['test_id']);
    $stmt = lms_pdo()->prepare('SELECT * FROM test_soal WHERE test_id = ? ORDER BY urutan');
    $stmt->execute([$testId]);
    json_ok(['soal' => $stmt->fetchAll()]);
}

function h_test_soal_add(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['test_id', 'pertanyaan']);
    $stmt = lms_pdo()->prepare(
        'INSERT INTO test_soal (test_id, urutan, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, jawaban_benar)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $data['test_id'], $data['urutan'] ?? 1, $data['pertanyaan'],
        $data['pilihan_a'] ?? null, $data['pilihan_b'] ?? null, $data['pilihan_c'] ?? null, $data['pilihan_d'] ?? null,
        $data['jawaban_benar'] ?? null,
    ]);
    json_ok(['id' => (int)lms_pdo()->lastInsertId()]);
}

function h_test_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    lms_pdo()->prepare('DELETE FROM test WHERE id = ?')->execute([$data['id']]);
    log_activity((int)$u['id'], 'hapus_test', ['id' => $data['id']]);
    json_ok();
}

// ---------- SOP ----------

function h_sop_list(): void {
    require_role(['superadmin', 'admin', 'manager', 'tutor', 'member']);
    $levelId = $_GET['level_id'] ?? null;
    $sql = 'SELECT * FROM sop WHERE 1=1';
    $params = [];
    if ($levelId) { $sql .= ' AND (level_id = ? OR level_id IS NULL)'; $params[] = $levelId; }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = lms_pdo()->prepare($sql);
    $stmt->execute($params);
    json_ok(['sop' => $stmt->fetchAll()]);
}

function h_sop_create(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['judul', 'isi']);
    $stmt = lms_pdo()->prepare('INSERT INTO sop (level_id, judul, isi, created_by) VALUES (?,?,?,?)');
    $stmt->execute([$data['level_id'] ?? null, $data['judul'], $data['isi'], $u['id']]);
    log_activity((int)$u['id'], 'tambah_sop', ['judul' => $data['judul']]);
    json_ok(['id' => (int)lms_pdo()->lastInsertId()]);
}

function h_sop_delete(): void {
    $u = require_role(CATALOG_WRITE_ROLES);
    $data = body_json();
    require_fields($data, ['id']);
    lms_pdo()->prepare('DELETE FROM sop WHERE id = ?')->execute([$data['id']]);
    json_ok();
}
