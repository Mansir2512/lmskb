<?php
/**
 * Koneksi database (PDO) + auto-heal skema.
 *
 * Pola "auto-heal": setiap request ke api.php mengecek versi skema di tabel
 * sys_meta, lalu menjalankan migrasi yang belum jalan. Jadi kalau database
 * dipulihkan dari backup lama, atau ada kolom baru nanti, sistem menambal
 * sendiri tanpa perlu jalan perintah manual di hosting.
 */

function lms_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'config.php belum ada. Salin config.sample.php jadi config.php, isi kredensial database, lalu buka install.php sekali lewat browser.',
            ]);
            exit;
        }
        $cfg = require $path;
    }
    return $cfg;
}

function lms_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = lms_config();
        $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset={$cfg['db_charset']}";
        try {
            $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Gagal konek database: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

function lms_current_schema_version(PDO $pdo): int {
    try {
        $stmt = $pdo->query("SELECT v FROM sys_meta WHERE k = 'schema_v'");
        $row = $stmt->fetch();
        return $row ? (int)$row['v'] : 0;
    } catch (Throwable $e) {
        return 0; // tabel sys_meta belum ada sama sekali (instalasi baru)
    }
}

/** Jalankan semua migrasi yang belum diterapkan. Aman dipanggil berkali-kali. */
function lms_auto_heal_schema(): void {
    $pdo = lms_pdo();
    $migrations = require __DIR__ . '/migrations.php';
    $current = lms_current_schema_version($pdo);
    $target = max(array_keys($migrations));
    if ($current >= $target) return;

    for ($v = $current + 1; $v <= $target; $v++) {
        if (!isset($migrations[$v])) continue;
        foreach ($migrations[$v] as $sql) {
            $pdo->exec($sql);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO sys_meta (k, v) VALUES ('schema_v', :v)
             ON DUPLICATE KEY UPDATE v = :v2"
        );
        $stmt->execute([':v' => (string)$v, ':v2' => (string)$v]);
    }
}
