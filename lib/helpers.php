<?php
/**
 * Kumpulan fungsi bantu dipakai di seluruh api.php + handlers/.
 */

// ---------- Response JSON ----------

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

function json_ok(array $data = []): void {
    json_out(array_merge(['ok' => true], $data));
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_fields(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') {
            json_error("Field '$f' wajib diisi.", 422);
        }
    }
}

// ---------- Auth & RBAC ----------
// BR: role guard ditegakkan di server (api.php), bukan cuma di tampilan.

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = lms_pdo()->prepare(
        'SELECT id, username, role, nama, no_id, must_change_password
         FROM users WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$_SESSION['uid']]);
    $u = $stmt->fetch();
    $cache = $u ?: null;
    return $cache;
}

function require_login(): array {
    $u = current_user();
    if (!$u) json_error('Belum login.', 401);
    return $u;
}

/** @param string[] $roles daftar role yang boleh akses */
function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        json_error('Kamu tidak punya akses untuk aksi ini.', 403);
    }
    return $u;
}

function hash_password(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}

/** BR-3: password default member = tanggal lahir format DDMMYYYY (🔸 ASUMSI format, lihat README). */
function default_password_from_dob(?string $dob): string {
    if (!$dob) return 'ganti123' . random_int(100, 999);
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$d) return 'ganti123' . random_int(100, 999);
    return $d->format('dmY');
}

/** BR-10: sistem selalu menjamin minimal 1 akun superadmin aktif. */
function count_active_superadmin(PDO $pdo, ?int $excludeId = null): int {
    $sql = "SELECT COUNT(*) c FROM users WHERE role = 'superadmin' AND is_active = 1";
    $params = [];
    if ($excludeId) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetch()['c'];
}

// ---------- Notifikasi & audit log ----------

function notify(int $userId, string $judul, string $pesan = ''): void {
    $stmt = lms_pdo()->prepare('INSERT INTO notifikasi (user_id, judul, pesan) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $judul, $pesan]);
}

/** §15 NFR: audit trail — log aksi kritis + aktor + waktu. */
function log_activity(?int $actorId, string $aksi, $detail = null): void {
    $stmt = lms_pdo()->prepare('INSERT INTO activity_log (actor_id, aksi, detail) VALUES (?, ?, ?)');
    $stmt->execute([$actorId, $aksi, $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

// ---------- Aturan bisnis kuota kelas (BR-1 / BR-17) ----------

function kuota_default_untuk_tipe(string $tipe): array {
    switch ($tipe) {
        case 'private':         return ['min' => 1, 'maks' => 1];
        case 'private_couple':  return ['min' => 2, 'maks' => 2];
        case 'regular':
        default:                return ['min' => 5, 'maks' => 10];
    }
}

// ---------- Util kecil ----------

function pdo_now(): string {
    return date('Y-m-d H:i:s');
}

function require_upload_dir(): string {
    $cfg = lms_config();
    $dir = __DIR__ . '/../' . ltrim($cfg['upload_dir'], '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}
