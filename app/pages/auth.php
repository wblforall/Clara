<?php
declare(strict_types=1);

function login_page(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $lockUntil = $_SESSION['_login_lock_until'] ?? 0;
        if ($lockUntil > time()) {
            $remaining = (int) ceil(($lockUntil - time()) / 60);
            flash("Terlalu banyak percobaan login. Coba lagi dalam {$remaining} menit.");
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([post('email')]);
            $user = $stmt->fetch();
            if ($user && ($user['status'] ?? 'active') !== 'active') {
                audit($pdo, 'login_blocked_inactive', 'users', (string) $user['id'], ['email' => post('email')], [], 'auth');
                flash('User tidak aktif. Hubungi admin.');
            } elseif ($user && password_verify((string) post('password'), $user['password_hash'])) {
                unset($_SESSION['_login_attempts'], $_SESSION['_login_lock_until']);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];

                // Load allowed properties for this user
                $propStmt = $pdo->prepare(
                    'SELECT p.id, p.key, p.name FROM properties p
                     JOIN user_properties up ON up.property_id = p.id
                     WHERE up.user_id = ? AND p.status = ? ORDER BY p.id'
                );
                $propStmt->execute([$user['id'], 'active']);
                $allowed = $propStmt->fetchAll();

                // Superadmin with no assignments gets all properties
                if (count($allowed) === 0 && in_array($user['role'], ['superadmin', 'admin'], true)) {
                    $allowed = $pdo->query("SELECT id, `key`, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();
                }
                $_SESSION['allowed_properties'] = $allowed;

                // Selalu set ke properti pertama — user ganti via tab di topbar
                if (!empty($allowed)) {
                    $_SESSION['current_property_id'] = (int)$allowed[0]['id'];
                }

                $pdo->prepare('UPDATE users SET last_login_at = ?, session_last_active = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $user['id']]);
                audit($pdo, 'login_success', 'users', (string) $user['id'], ['email' => $user['email']], [], 'auth');
                $_SESSION['_show_welcome'] = true;

                if ($user['must_change_password']) {
                    $_SESSION['_must_change_pw'] = true;
                    redirect_to('change_password');
                }
                redirect_to('dashboard');
            } else {
                $attempts = ($_SESSION['_login_attempts'] ?? 0) + 1;
                $_SESSION['_login_attempts'] = $attempts;
                audit($pdo, 'login_failed', 'users', null, ['email' => post('email')], [], 'auth');
                if ($attempts >= 5) {
                    $_SESSION['_login_lock_until'] = time() + 300;
                    unset($_SESSION['_login_attempts']);
                    flash('Terlalu banyak percobaan login. Coba lagi dalam 5 menit.');
                } else {
                    flash('Login gagal. Cek email dan password. (Percobaan ' . $attempts . '/5)');
                }
            }
        }
    }

    $lockRemaining = max(0, (int)(($_SESSION['_login_lock_until'] ?? 0) - time()));
    $flashMsg      = flash();
    $hasError      = $flashMsg !== null;
    $isLocked      = $lockRemaining > 0;
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login — <?= h(env_value('APP_NAME', 'CLARA')) ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <?php pwa_head(); ?>
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
        <style>
            @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
            @keyframes shake{0%,100%{transform:translateX(0)}15%,45%,75%{transform:translateX(-7px)}30%,60%,90%{transform:translateX(7px)}}
            @keyframes spin{to{transform:rotate(360deg)}}
            @keyframes bgShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
            @keyframes blob1{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(60px,-40px) scale(1.12)}66%{transform:translate(-30px,50px) scale(0.92)}}
            @keyframes blob2{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(-70px,30px) scale(1.08)}66%{transform:translate(40px,-60px) scale(1.15)}}
            @keyframes blob3{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(50px,40px) scale(0.9)}}

            .login{
                background:linear-gradient(-45deg,#115E59,#0D9488,#0891B2,#0369A1,#0D9488,#115E59) !important;
                background-size:400% 400% !important;
                animation:bgShift 18s ease infinite;
                position:relative;
                overflow:hidden;
            }
            .login::before,.login::after,.login-blob3{
                content:'';
                position:absolute;
                border-radius:50%;
                filter:blur(90px);
                opacity:.22;
                pointer-events:none;
                z-index:0;
            }
            .login::before{
                width:520px;height:520px;
                background:radial-gradient(circle,#99F6E4,#2DD4BF);
                top:-140px;left:-160px;
                animation:blob1 20s ease-in-out infinite;
            }
            .login::after{
                width:440px;height:440px;
                background:radial-gradient(circle,#BAE6FD,#38BDF8);
                bottom:-120px;right:-120px;
                animation:blob2 25s ease-in-out infinite;
            }
            .login-blob3{
                width:360px;height:360px;
                background:radial-gradient(circle,#A7F3D0,#6EE7B7);
                top:45%;left:55%;
                animation:blob3 30s ease-in-out infinite;
            }
            .login .panel{
                position:relative;
                z-index:1;
                background:rgba(255,255,255,.97) !important;
                box-shadow:0 24px 64px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.12),inset 0 1px 0 rgba(255,255,255,.9) !important;
                border:1px solid rgba(255,255,255,.6);
                backdrop-filter:blur(4px);
            }
            .login-spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite;vertical-align:middle;margin-right:8px}
            #submit-btn:disabled{opacity:.75;cursor:not-allowed}
            #lock-countdown{font-variant-numeric:tabular-nums;font-weight:700}
        </style>
    </head>
    <body>
    <div class="login">
        <div class="login-blob3"></div>
        <form class="panel" method="post" id="login-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <img class="login-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='flex'">
            <div style="display:none;align-items:center;gap:10px;margin-bottom:20px">
                <div class="brand-icon">CL</div>
                <div>
                    <div style="font-weight:800;font-size:16px;color:var(--ink)"><?= h(env_value('APP_NAME', 'CLARA')) ?></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5">Casual Leasing Achievement<br>& Revenue Analytics</div>
                </div>
            </div>
            <h1 style="margin-bottom:4px">Masuk</h1>
            <p class="muted" style="margin-bottom:20px">Silakan masuk dengan akun Anda</p>
            <?php if ($hasError): ?>
                <div class="flash">
                    <?php if ($isLocked): ?>
                        Terlalu banyak percobaan login. Coba lagi dalam <strong id="lock-countdown"></strong>.
                    <?php else: ?>
                        <?= h($flashMsg) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <p style="margin-bottom:14px">
                <label>Email</label>
                <input name="email" type="email" required autocomplete="username" autofocus>
            </p>
            <p style="margin-bottom:20px">
                <label>Password</label>
                <span style="position:relative;display:block">
                    <input id="pw" name="password" type="password" required autocomplete="current-password" style="width:100%;padding-right:42px;box-sizing:border-box">
                    <button type="button" onclick="var i=document.getElementById('pw');i.type=i.type==='password'?'text':'password';this.innerHTML=i.type==='password'?'<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'18\' height=\'18\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z\'/><circle cx=\'12\' cy=\'12\' r=\'3\'/></svg>':'<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'18\' height=\'18\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94\'/><path d=\'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19\'/><line x1=\'1\' y1=\'1\' x2=\'23\' y2=\'23\'/></svg>'" style="position:absolute;right:0;top:0;height:100%;width:40px;background:none;border:none;cursor:pointer;color:var(--muted);display:flex;align-items:center;justify-content:center;padding:0"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </span>
            </p>
            <button type="submit" id="submit-btn" style="width:100%;justify-content:center">Masuk</button>
        </form>
    </div>
    <script>
    (function(){
        var form=document.getElementById('login-form'),btn=document.getElementById('submit-btn');
        var lockSec=<?= $lockRemaining ?>,hasError=<?= $hasError?'true':'false' ?>,isLocked=<?= $isLocked?'true':'false' ?>;
        if(hasError&&!isLocked){form.style.animation='shake .45s ease';form.addEventListener('animationend',function(){form.style.animation='';},{once:true});}
        else{form.style.animation='fadeInUp .35s ease both';form.addEventListener('animationend',function(){form.style.animation='';},{once:true});}
        if(lockSec>0){btn.disabled=true;var el=document.getElementById('lock-countdown');function tick(){if(lockSec<=0){btn.disabled=false;btn.textContent='Masuk';if(el)el.closest('.flash').textContent='Silakan coba lagi.';return;}var m=Math.floor(lockSec/60),s=lockSec%60;if(el)el.textContent=m+':'+String(s).padStart(2,'0');lockSec--;setTimeout(tick,1000);}tick();}
        form.addEventListener('submit',function(){if(btn.disabled)return false;btn.disabled=true;btn.innerHTML='<span class="login-spinner"></span>Memuat…';});
    })();
    </script>
    </body>
    </html>
    <?php
}

function select_property_page(): void
{
    $allowed = allowed_properties();
    if (count($allowed) === 0) {
        flash('Akun Anda belum ditetapkan ke properti mana pun. Hubungi admin.');
        redirect_to('logout');
    }
    if (count($allowed) === 1) {
        $_SESSION['current_property_id'] = (int)$allowed[0]['id'];
        redirect_to('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pid = (int)post('property_id', 0);
        $ids = array_map('intval', array_column($allowed, 'id'));
        if (in_array($pid, $ids, true)) {
            $_SESSION['current_property_id'] = $pid;
            redirect_to('dashboard');
        }
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Pilih Properti — <?= h(env_value('APP_NAME', 'CLARA')) ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <?php pwa_head(); ?>
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
        <style>
            @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
            .prop-cards{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-top:24px}
            .prop-card-btn{flex:1;min-width:180px;max-width:240px;border:2px solid var(--border);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .2s,box-shadow .2s,transform .2s;background:var(--surface)}
            .prop-card-btn:hover{border-color:var(--primary);box-shadow:0 4px 16px rgba(13,148,136,.15);transform:translateY(-2px)}
            .prop-icon{width:52px;height:52px;border-radius:14px;background:var(--primary);color:#fff;font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
            .prop-name{font-weight:700;font-size:15px;color:var(--ink);line-height:1.3}
        </style>
    </head>
    <body>
    <div class="login">
        <div class="panel" style="max-width:540px;width:100%;animation:fadeInUp .35s ease both">
            <img class="login-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true">
            <h1 style="text-align:center;margin-bottom:6px">Pilih Properti</h1>
            <p class="muted" style="text-align:center;margin-bottom:4px">
                Halo, <strong><?= h($_SESSION['user']['name'] ?? '') ?></strong>.
            </p>
            <p class="muted" style="text-align:center;margin-bottom:4px">Pilih properti yang ingin Anda akses sekarang.</p>
            <div class="prop-cards">
                <?php foreach ($allowed as $p): ?>
                <form method="post" style="flex:1;min-width:180px;max-width:240px">
                    <input type="hidden" name="property_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="prop-card-btn" style="width:100%">
                        <div class="prop-icon"><?= h(strtoupper(substr((string)$p['name'], 0, 2))) ?></div>
                        <div class="prop-name"><?= h($p['name']) ?></div>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
            <p style="text-align:center;margin-top:24px">
                <a href="?r=logout" style="color:var(--muted);font-size:13px">↩ Logout</a>
            </p>
        </div>
    </div>
    </body>
    </html>
    <?php
}

function change_password_page(PDO $pdo): void
{
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $error  = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $pw  = (string) post('password');
        $pw2 = (string) post('password_confirm');

        if ($pw !== $pw2) {
            $error = 'Konfirmasi password tidak cocok.';
        } elseif ($err = validate_password($pw)) {
            $error = $err;
        } else {
            $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0, updated_at=? WHERE id=?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $userId]);
            audit($pdo, 'change_password', 'users', (string) $userId, [], [], 'auth');
            unset($_SESSION['_must_change_pw']);
            flash('Password berhasil diubah. Selamat datang!');
            redirect_to('dashboard');
        }
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Ganti Password — <?= h(env_value('APP_NAME', 'CLARA')) ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <?php pwa_head(); ?>
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
        <style>
            @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
            .login{background:linear-gradient(-45deg,#115E59,#0D9488,#0891B2,#0369A1,#0D9488,#115E59)!important;background-size:400% 400%!important}
        </style>
    </head>
    <body>
    <div class="login">
        <form class="panel" method="post" style="animation:fadeInUp .35s ease both">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <img class="login-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true">
            <h1 style="margin-bottom:4px">Ganti Password</h1>
            <p class="muted" style="margin-bottom:20px">
                Halo, <strong><?= h($_SESSION['user']['name'] ?? '') ?></strong>. Akun Anda menggunakan password default.<br>
                Silakan buat password baru sebelum melanjutkan.
            </p>
            <?php if ($error): ?>
                <div class="flash"><?= h($error) ?></div>
            <?php endif; ?>
            <p style="margin-bottom:4px">
                <label>Password Baru</label>
                <input name="password" type="password" required minlength="8" autofocus autocomplete="new-password">
            </p>
            <p style="margin-bottom:14px;font-size:12px;color:var(--muted);line-height:1.6">
                Minimal 8 karakter, mengandung huruf besar, huruf kecil, angka, dan karakter spesial (!@#$%^&* dll).
            </p>
            <p style="margin-bottom:20px">
                <label>Konfirmasi Password</label>
                <input name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
            </p>
            <button type="submit" style="width:100%;justify-content:center">Simpan Password</button>
            <p style="text-align:center;margin-top:16px">
                <a href="?r=logout" style="color:var(--muted);font-size:13px">↩ Logout</a>
            </p>
        </form>
    </div>
    </body>
    </html>
    <?php
}
