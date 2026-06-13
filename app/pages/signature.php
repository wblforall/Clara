<?php
// Tanda Tangan Saya — sales & manager unggah PNG TTD mereka sendiri.
// Disimpan ke users.signature_path (utk manager/penyetuju) DAN master_pic
// .signature_path bila akun ini terhubung ke PIC (utk sales/pembuat).
// Dipakai sebagai bukti "TTD terdaftar" pada validasi dokumen via QR.

function _sig_dir(): string { return dirname(__DIR__, 2) . '/public/uploads/signatures'; }

function my_signature_save(PDO $pdo): void
{
    require_login();
    verify_csrf();
    $uid  = (int) ($_SESSION['user']['id'] ?? 0);
    $name = $_SESSION['user']['name'] ?? '';
    if (!$uid) { flash('Sesi tidak valid.'); redirect_to('my_signature'); }

    if (empty($_FILES['signature']['tmp_name']) || !is_uploaded_file($_FILES['signature']['tmp_name'])) {
        flash('Pilih file tanda tangan dulu.'); redirect_to('my_signature');
    }
    $f = $_FILES['signature'];
    if ($f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) { flash('Ukuran maksimal 2 MB.'); redirect_to('my_signature'); }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) { flash('Format harus PNG/JPG/WEBP.'); redirect_to('my_signature'); }

    $dir = _sig_dir();
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fname = 'sig_u' . $uid . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) { flash('Gagal mengunggah.'); redirect_to('my_signature'); }
    $rel = 'uploads/signatures/' . $fname;

    $pdo->prepare('UPDATE users SET signature_path=? WHERE id=?')->execute([$rel, $uid]);
    // PIC yang terhubung ke akun ini (sales) — bisa lebih dari satu properti.
    $pdo->prepare('UPDATE master_pic SET signature_path=? WHERE user_id=?')->execute([$rel, $uid]);
    audit($pdo, 'update', 'users', (string) $uid, ['signature' => $rel]);
    flash('Tanda tangan tersimpan. Akan dipakai sebagai validasi QR di dokumen.');
    redirect_to('my_signature');
}

function my_signature_page(PDO $pdo): void
{
    require_login();
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    $st = $pdo->prepare('SELECT signature_path FROM users WHERE id=?');
    $st->execute([$uid]);
    $cur = $st->fetchColumn() ?: '';
    // Apakah akun ini terhubung ke PIC (sales)?
    $pc = $pdo->prepare('SELECT name FROM master_pic WHERE user_id=? AND status="active"');
    $pc->execute([$uid]);
    $pics = $pc->fetchAll(PDO::FETCH_COLUMN) ?: [];

    layout('Tanda Tangan Saya', function () use ($cur, $pics) {
        ?>
        <div class="panel" style="max-width:560px">
            <h3 style="margin-top:0">Tanda Tangan Saya</h3>
            <p style="color:var(--muted);font-size:13px;margin-top:0">Unggah gambar tanda tangan Anda (PNG/JPG, latar transparan disarankan, maks 2 MB). Pada PDF dokumen, tanda tangan Anda tampil sebagai <strong>QR validasi</strong> — bukan gambar — sehingga tidak mudah dijiplak. Saat di-scan, QR menunjukkan dokumen <strong>sah & terdaftar</strong> di CLARA.</p>
            <?php if ($pics): ?><div style="font-size:12.5px;color:#0f766e;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:8px 12px;margin-bottom:12px">Akun ini terhubung sebagai PIC: <strong><?= h(implode(', ', $pics)) ?></strong> — TTD dipakai pada blok "Dibuat Oleh".</div><?php endif; ?>

            <?php if ($cur): ?>
            <div style="margin-bottom:14px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Tanda tangan saat ini:</div>
                <img src="<?= h($cur) ?>" alt="TTD" style="max-height:90px;max-width:240px;border:1px solid var(--line);border-radius:8px;background:#fff;padding:6px">
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;margin-bottom:12px">Belum ada tanda tangan terdaftar.</div>
            <?php endif; ?>

            <form method="post" action="?r=my_signature_save" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <label>Pilih gambar tanda tangan</label>
                <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" required style="display:block;margin:6px 0 12px">
                <button type="submit">💾 Simpan Tanda Tangan</button>
            </form>
        </div>
        <?php
    });
}
