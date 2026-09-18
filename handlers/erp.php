<?php
/**
 * Webhook dari ERP (Laravel erp.globalenglish.id / sinkronisasi WooCommerce).
 * ❓ BELUM DIKONFIRMASI: skema payload asli ERP. Endpoint ini adalah STUB
 * yang mengikuti pola SyncController::syncWoo() yang disebut di dokumen
 * internal kamu — sesuaikan nama field payload begitu skema ERP final ada.
 *
 * Autentikasi: HMAC-SHA256 di header X-Signature, dari body mentah + app_secret
 * (bukan session login, karena dipanggil server-ke-server oleh ERP).
 *
 * BR-3: akun Member dibuat otomatis, username = no_id, password = tanggal
 * lahir (DDMMYYYY).
 * BR-18: kalau no_id sudah pernah terdaftar, JANGAN buat akun baru — cukup
 * tambahkan Enrollment baru ke akun lama (repeat customer).
 */

function verify_erp_signature(string $rawBody): bool {
    $cfg = lms_config();
    $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if (!$sig) return false;
    $expected = hash_hmac('sha256', $rawBody, $cfg['app_secret']);
    return hash_equals($expected, $sig);
}

function h_erp_webhook(): void {
    $raw = file_get_contents('php://input');
    if (!verify_erp_signature($raw)) {
        json_error('Tanda tangan (X-Signature) tidak valid.', 401);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Payload bukan JSON valid.', 422);
    require_fields($data, ['no_id', 'nama']);

    $pdo = lms_pdo();
    $status = 'gagal';
    $matchedId = null;

    try {
        // BR-18: cek dulu apakah No ID sudah pernah ada.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE no_id = ? AND role = 'member'");
        $stmt->execute([$data['no_id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $matchedId = (int)$existing['id'];
            $status = 'repeat_customer_matched';
            // update data ringan kalau ada perubahan (nama/kontak), tanpa reset password.
            $upd = $pdo->prepare('UPDATE users SET nama = ?, email = COALESCE(?, email), whatsapp = COALESCE(?, whatsapp) WHERE id = ?');
            $upd->execute([$data['nama'], $data['email'] ?? null, $data['whatsapp'] ?? null, $matchedId]);
        } else {
            $username = $data['no_id']; // BR-3: username = No ID member
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $chk->execute([$username]);
            if ($chk->fetch()) $username = $username . '_' . substr(bin2hex(random_bytes(2)), 0, 3);

            $plainPassword = default_password_from_dob($data['tanggal_lahir'] ?? null);
            $ins = $pdo->prepare(
                'INSERT INTO users (username, password_hash, must_change_password, role, nama, no_id, tanggal_lahir, email, whatsapp)
                 VALUES (?,?,1,\'member\',?,?,?,?,?)'
            );
            $ins->execute([
                $username, hash_password($plainPassword), $data['nama'], $data['no_id'],
                $data['tanggal_lahir'] ?? null, $data['email'] ?? null, $data['whatsapp'] ?? null,
            ]);
            $matchedId = (int)$pdo->lastInsertId();
            $status = 'akun_baru_dibuat';
        }

        // Kalau payload menyertakan kelas_id, langsung daftarkan (opsional).
        if (!empty($data['kelas_id'])) {
            $kelasStmt = $pdo->prepare('SELECT kuota_maks FROM kelas WHERE id = ?');
            $kelasStmt->execute([$data['kelas_id']]);
            $kelas = $kelasStmt->fetch();
            if ($kelas) {
                $cnt = $pdo->prepare('SELECT COUNT(*) c FROM enrollment WHERE kelas_id = ?');
                $cnt->execute([$data['kelas_id']]);
                if ((int)$cnt->fetch()['c'] < (int)$kelas['kuota_maks']) {
                    $insEnr = $pdo->prepare(
                        'INSERT IGNORE INTO enrollment (member_id, kelas_id, paket_id) VALUES (?,?,?)'
                    );
                    $insEnr->execute([$matchedId, $data['kelas_id'], $data['paket_id'] ?? null]);
                    if ($insEnr->rowCount() > 0) {
                        $enrollmentId = (int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO sertifikat (enrollment_id, status) VALUES (?, 'terkunci')")
                            ->execute([$enrollmentId]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $status = 'error: ' . $e->getMessage();
    }

    $log = $pdo->prepare('INSERT INTO erp_webhook_log (payload, matched_user_id, status) VALUES (?,?,?)');
    $log->execute([$raw, $matchedId, $status]);

    if (strpos($status, 'error') === 0) json_error($status, 500);
    json_ok(['user_id' => $matchedId, 'status' => $status]);
}
