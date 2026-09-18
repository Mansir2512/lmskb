<?php
// Migrasi skema database. Dijalankan otomatis (auto-heal) tiap request oleh
// lib/db.php, dan sekali secara eksplisit oleh install.php.
// Tambah versi baru (2, 3, ...) kalau nanti perlu ALTER TABLE — JANGAN edit
// isi versi 1 setelah live, supaya instalasi lama tidak rusak.
return [
    1 => [
        'CREATE TABLE IF NOT EXISTS sys_meta (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  role ENUM(\'member\',\'cs\',\'admin\',\'manager\',\'tutor\',\'superadmin\') NOT NULL,
  nama VARCHAR(150) NOT NULL,
  no_id VARCHAR(60) NULL UNIQUE COMMENT \'No ID dari ERP, khusus role member — BR-3\',
  tanggal_lahir DATE NULL COMMENT \'sumber default password member — BR-3\',
  email VARCHAR(150) NULL,
  whatsapp VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS program (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  deskripsi TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS level_kursus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  nama VARCHAR(150) NOT NULL,
  deskripsi TEXT NULL,
  rekomendasi TEXT NULL,
  durasi_belajar VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_level_program FOREIGN KEY (program_id) REFERENCES program(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS paket (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  tipe ENUM(\'regular\',\'private\',\'private_couple\',\'bundling\',\'elearning\',\'test_only\') NOT NULL,
  harga INT NOT NULL DEFAULT 0 COMMENT \'dalam ribuan rupiah\',
  durasi VARCHAR(100) NULL,
  fasilitas TEXT NULL,
  jadwal_jam VARCHAR(255) NULL,
  link_pembayaran VARCHAR(255) NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS paket_level (
  paket_id INT NOT NULL,
  level_id INT NOT NULL,
  PRIMARY KEY (paket_id, level_id),
  CONSTRAINT fk_pl_paket FOREIGN KEY (paket_id) REFERENCES paket(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_level FOREIGN KEY (level_id) REFERENCES level_kursus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS materi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NOT NULL,
  judul VARCHAR(200) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_materi_level FOREIGN KEY (level_id) REFERENCES level_kursus(id) ON DELETE CASCADE,
  CONSTRAINT fk_materi_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS test (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NOT NULL,
  jenis ENUM(\'pre\',\'mid\',\'final\') NOT NULL,
  judul VARCHAR(200) NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_test_level FOREIGN KEY (level_id) REFERENCES level_kursus(id) ON DELETE CASCADE,
  CONSTRAINT fk_test_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_level_jenis (level_id, jenis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS test_soal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  test_id INT NOT NULL,
  urutan INT NOT NULL DEFAULT 1,
  pertanyaan TEXT NOT NULL,
  pilihan_a VARCHAR(255) NULL,
  pilihan_b VARCHAR(255) NULL,
  pilihan_c VARCHAR(255) NULL,
  pilihan_d VARCHAR(255) NULL,
  jawaban_benar CHAR(1) NULL COMMENT \'a/b/c/d\',
  CONSTRAINT fk_soal_test FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS sop (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NULL COMMENT \'NULL = SOP umum, tidak terikat 1 Level\',
  judul VARCHAR(200) NOT NULL,
  isi TEXT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sop_level FOREIGN KEY (level_id) REFERENCES level_kursus(id) ON DELETE CASCADE,
  CONSTRAINT fk_sop_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS kelas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NOT NULL,
  tutor_id INT NULL,
  tipe ENUM(\'regular\',\'private\',\'private_couple\') NOT NULL DEFAULT \'regular\',
  kuota_min INT NOT NULL DEFAULT 5,
  kuota_maks INT NOT NULL DEFAULT 10,
  jadwal VARCHAR(255) NULL,
  tanggal_mulai DATE NULL,
  status ENUM(\'draft\',\'dibuka\',\'berjalan\',\'selesai\',\'arsip\') NOT NULL DEFAULT \'draft\',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_kelas_level FOREIGN KEY (level_id) REFERENCES level_kursus(id),
  CONSTRAINT fk_kelas_tutor FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_kelas_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS enrollment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  kelas_id INT NOT NULL,
  paket_id INT NULL,
  status ENUM(\'terdaftar\',\'aktif\',\'selesai\',\'tidak_lulus\',\'bersertifikat\') NOT NULL DEFAULT \'terdaftar\',
  tanggal_daftar DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_enr_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_paket FOREIGN KEY (paket_id) REFERENCES paket(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_member_kelas (member_id, kelas_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS nilai (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  test_id INT NOT NULL,
  skor DECIMAL(5,2) NULL,
  diinput_oleh INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nilai_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE,
  CONSTRAINT fk_nilai_test FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
  CONSTRAINT fk_nilai_user FOREIGN KEY (diinput_oleh) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_enrollment_test (enrollment_id, test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS test_akses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kelas_id INT NOT NULL,
  test_id INT NOT NULL,
  terbuka TINYINT(1) NOT NULL DEFAULT 0,
  dibuka_oleh INT NULL,
  waktu_buka DATETIME NULL,
  CONSTRAINT fk_ta_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_test FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_user FOREIGN KEY (dibuka_oleh) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_kelas_test (kelas_id, test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS test_submission (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  test_id INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_test FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_enr_test (enrollment_id, test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  tanggal_sesi DATE NOT NULL,
  hadir TINYINT(1) NOT NULL DEFAULT 0,
  dicatat_oleh INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_abs_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE,
  CONSTRAINT fk_abs_user FOREIGN KEY (dicatat_oleh) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_enrollment_tanggal (enrollment_id, tanggal_sesi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS kegiatan_harian (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kelas_id INT NOT NULL,
  tutor_id INT NULL,
  tanggal DATE NOT NULL,
  catatan TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_kh_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
  CONSTRAINT fk_kh_tutor FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS sertifikat (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL UNIQUE,
  status ENUM(\'terkunci\',\'terbuka\',\'diunduh\') NOT NULL DEFAULT \'terkunci\',
  dibuka_oleh INT NULL,
  waktu_buka DATETIME NULL,
  CONSTRAINT fk_sert_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE,
  CONSTRAINT fk_sert_user FOREIGN KEY (dibuka_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS percakapan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kelas_id INT NOT NULL,
  sender_id INT NOT NULL,
  target ENUM(\'kelas\',\'tutor\') NOT NULL DEFAULT \'kelas\',
  pesan TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pc_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_user FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS zoom_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kelas_id INT NOT NULL,
  tanggal_sesi DATE NOT NULL,
  link VARCHAR(255) NULL,
  link_record VARCHAR(255) NULL,
  diinput_oleh INT NULL,
  CONSTRAINT fk_zl_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
  CONSTRAINT fk_zl_user FOREIGN KEY (diinput_oleh) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_kelas_tanggal (kelas_id, tanggal_sesi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS notifikasi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  judul VARCHAR(200) NOT NULL,
  pesan TEXT NULL,
  dibaca TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS pindah_kelas_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  kelas_asal_id INT NOT NULL,
  kelas_tujuan_id INT NOT NULL,
  diproses_oleh INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pk_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE,
  CONSTRAINT fk_pk_user FOREIGN KEY (diproses_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS cuti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  tanggal_sesi_dicuti DATE NOT NULL,
  tanggal_pengajuan DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  keterangan VARCHAR(255) NULL,
  CONSTRAINT fk_cuti_enr FOREIGN KEY (enrollment_id) REFERENCES enrollment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_id INT NULL,
  aksi VARCHAR(100) NOT NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
        'CREATE TABLE IF NOT EXISTS erp_webhook_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  matched_user_id INT NULL,
  status VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
    ],
];
