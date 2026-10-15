<?php
declare(strict_types=1);

function login_page(PDO $pdo): void
{
    $isAjax = ($_SERVER['HTTP_X_LOGIN_AJAX'] ?? '') === '1';
    $ajaxJson = static function (array $payload): never {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($isAjax && !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
            // sama dengan verify_csrf(), tetapi membalas JSON agar flash tidak terkonsumsi respons fetch
            unset($_SESSION['csrf']);
            flash('Sesi form sudah kedaluwarsa. Silakan coba lagi.');
            $ajaxJson(['ok' => false]);
        }
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
                    if ($isAjax) {
                        $ajaxJson(['ok' => true, 'to' => '?r=change_password', 'name' => (string) $user['name']]);
                    }
                    redirect_to('change_password');
                }
                if ($isAjax) {
                    $ajaxJson(['ok' => true, 'to' => '?r=dashboard', 'name' => (string) $user['name']]);
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
        if ($isAjax) {
            $ajaxJson(['ok' => false]); // flash sudah terisi; klien memuat ulang halaman login
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
            @keyframes fadeInUp{from{opacity:0;transform:translateY(24px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
            @keyframes shake{0%,100%{transform:translateX(0)}15%,45%,75%{transform:translateX(-7px)}30%,60%,90%{transform:translateX(7px)}}
            @keyframes spin{to{transform:rotate(360deg)}}
            @keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
            @keyframes blobA{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(60px,40px,0) scale(1.18)}}
            @keyframes blobB{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(-70px,-30px,0) scale(1.12)}}
            @keyframes ring{0%{transform:scale(.8);opacity:.55}100%{transform:scale(1.55);opacity:0}}
            @keyframes shine{0%,62%{transform:translateX(-130%) skewX(-18deg)}100%{transform:translateX(330%) skewX(-18deg)}}
            @keyframes sweep{0%{background-position:0% 50%}100%{background-position:200% 50%}}

            /* ---- latar luar: pastel teal bergerak ---- */
            .login-baru{min-height:100vh;display:grid;place-items:center;padding:28px;position:relative;overflow:hidden;
                background:linear-gradient(135deg,#CFF3EE 0%,#C6E9F3 45%,#D5E3FA 100%) !important}
            .bg-blob{position:absolute;border-radius:50%;filter:blur(70px);pointer-events:none;z-index:0}
            .bg-blob.a{width:520px;height:520px;left:-120px;top:-140px;background:radial-gradient(circle,#2DD4BF,rgba(45,212,191,0));opacity:.55;animation:blobA 18s ease-in-out infinite}
            .bg-blob.b{width:560px;height:560px;right:-160px;bottom:-180px;background:radial-gradient(circle,#38BDF8,rgba(56,189,248,0));opacity:.55;animation:blobB 22s ease-in-out infinite}
            .bg-blob.c{width:380px;height:380px;right:18%;top:-120px;background:radial-gradient(circle,#A5B4FC,rgba(165,180,252,0));opacity:.45;animation:blobA 26s ease-in-out infinite reverse}

            /* ---- bingkai kartu ---- */
            .shell{position:relative;z-index:2;width:min(1040px,100%);display:grid;grid-template-columns:1.05fr 1fr;gap:14px;padding:14px;border-radius:34px;
                background:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.8);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
                box-shadow:0 40px 90px rgba(11,94,88,.28),0 4px 14px rgba(11,94,88,.12),inset 0 1px 0 rgba(255,255,255,.9);animation:fadeInUp .7s ease both}

            /* ---- panel visual kiri ---- */
            .visual{position:relative;overflow:hidden;border-radius:24px;min-height:560px;color:#fff;isolation:isolate;
                background:linear-gradient(160deg,#0B5E58 0%,#0D9488 38%,#0891B2 72%,#0369A1 100%)}
            .visual .m{position:absolute;border-radius:50%;filter:blur(46px);z-index:-1}
            .visual .m1{width:340px;height:340px;left:-80px;top:-60px;background:#5EEAD4;opacity:.65;animation:blobA 14s ease-in-out infinite}
            .visual .m2{width:380px;height:380px;right:-110px;top:30%;background:#22D3EE;opacity:.55;animation:blobB 17s ease-in-out infinite}
            .visual .m3{width:300px;height:300px;left:12%;bottom:-120px;background:#A7F3D0;opacity:.5;animation:blobA 20s ease-in-out infinite reverse}
            .visual::after{content:'';position:absolute;inset:0;z-index:-1;background:repeating-linear-gradient(115deg,rgba(255,255,255,.05) 0 2px,transparent 2px 38px)}
            .visual .big{position:absolute;left:30px;top:96px;font-size:clamp(54px,8.2vw,92px);font-weight:800;letter-spacing:-.03em;line-height:.95;text-shadow:0 8px 30px rgba(3,40,38,.35);
                transform:translate3d(calc(var(--mx,0)*-14px),calc(var(--my,0)*-10px),0);transition:transform .35s ease-out}
            .visual .big small{display:block;margin-top:10px;font-size:.2em;font-weight:600;letter-spacing:.34em;opacity:.55}
            .visual .badge-top{position:absolute;left:30px;top:28px;display:inline-flex;align-items:center;gap:8px;padding:7px 13px;border-radius:99px;font-size:12px;font-weight:600;
                background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);backdrop-filter:blur(8px)}
            .visual .badge-top i{width:8px;height:8px;border-radius:50%;background:#A7F3D0;box-shadow:0 0 0 0 rgba(167,243,208,.7);animation:ring 2.2s ease-out infinite}

            /* kartu foto gedung */
            @keyframes phFloat{0%,100%{transform:translateY(0) rotate(var(--rot,0deg))}50%{transform:translateY(-10px) rotate(calc(var(--rot,0deg) + .8deg))}}
            @keyframes kenburns{0%{transform:scale(1.02) translate(0,0)}100%{transform:scale(1.13) translate(-2.5%,-1.5%)}}
            .ph{position:absolute;width:262px;transform:translate3d(calc(var(--mx,0)*var(--d,16px)),calc(var(--my,0)*var(--d,16px)),0);transition:transform .35s ease-out}
            .ph .card{position:relative;border-radius:18px;padding:6px;background:rgba(255,255,255,.92);box-shadow:0 26px 46px rgba(2,36,40,.42),0 0 0 1px rgba(255,255,255,.55);animation:phFloat var(--t,8s) ease-in-out infinite;animation-delay:var(--w,0s)}
            .ph .img{position:relative;overflow:hidden;border-radius:13px;aspect-ratio:1040/614}
            .ph .img img{display:block;width:100%;height:100%;object-fit:cover;animation:kenburns 16s ease-in-out infinite alternate}
            .ph .img::after{content:'';position:absolute;inset:0;background:linear-gradient(115deg,rgba(255,255,255,.28),transparent 40%);pointer-events:none}
            .ph .tag{position:absolute;left:14px;bottom:14px;z-index:2;display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:99px;font-size:11px;font-weight:700;color:#0F1623;background:rgba(255,255,255,.9);backdrop-filter:blur(6px);box-shadow:0 4px 12px rgba(2,36,40,.25)}
            .ph .tag i{width:7px;height:7px;border-radius:50%;background:var(--c,#0D9488)}
            .ph1{right:24px;top:232px;--rot:2.2deg;--d:22px;--t:8s}
            .ph1 .tag{left:auto;right:14px}
            .ph2{left:24px;top:342px;--rot:-2.6deg;--d:34px;--t:9.5s;--w:-3s;--c:#0891B2}
            .ph2 .img img{animation-delay:-6s}

            .visual .cap{position:absolute;left:30px;right:30px;bottom:28px}
            .visual .cap h2{margin:0 0 6px;color:#fff;font-size:19px;font-weight:700;letter-spacing:-.01em}
            .visual .cap p{margin:0;font-size:12.5px;line-height:1.55;color:rgba(255,255,255,.82);max-width:380px}

            /* ---- panel formulir kanan ---- */
            .form-side{position:relative;border-radius:24px;padding:34px 38px 26px;display:flex;flex-direction:column;
                background:linear-gradient(160deg,rgba(255,255,255,.86),rgba(236,253,250,.78));
                box-shadow:inset 0 1px 0 rgba(255,255,255,.9);background-image:linear-gradient(160deg,rgba(255,255,255,.86),rgba(236,253,250,.78)),conic-gradient(rgba(13,148,136,.04) 25%,transparent 0 50%,rgba(13,148,136,.04) 0 75%,transparent 0);background-size:auto,28px 28px}
            .logo-chip{width:128px;padding:5px 7px;border-radius:14px;background:#fff;box-shadow:0 8px 22px rgba(11,94,88,.16);animation:rise .6s .2s ease both}
            .logo-chip img{width:100%;display:block;border-radius:9px}
            .form-side h1{margin:26px 0 4px;color:#0F1623;font-size:31px;font-weight:800;letter-spacing:-.4px;animation:rise .6s .28s ease both}
            .form-side .sub{margin:0 0 22px;color:#5B6B7C;font-size:13.5px;animation:rise .6s .34s ease both}
            .form-side label{display:block;margin:0 0 6px;color:#25303F;font-size:12.5px;font-weight:600}
            .form-side .fld{margin:0 0 14px;animation:rise .6s ease both}
            .form-side .fld:nth-of-type(1){animation-delay:.4s}.form-side .fld:nth-of-type(2){animation-delay:.48s}
            .inp{position:relative;display:block}
            .inp > svg.ic{position:absolute;z-index:3;left:14px;top:50%;margin-top:-9px;width:18px;height:18px;color:#6B7C8C;pointer-events:none;transition:color .2s}
            .inp:focus-within > svg.ic{color:#0D9488}
            .form-side input[type=email],.form-side input[type=password],.form-side input[type=text]{
                width:100%;box-sizing:border-box;padding:13px 44px 13px 42px;border:1px solid #D5E3E8;border-radius:14px;background:rgba(255,255,255,.92);color:#0F1623;font-size:14.5px;outline:none;
                transition:box-shadow .2s,border-color .2s,transform .2s}
            .form-side input:focus{border-color:#14B8A6;box-shadow:0 0 0 4px rgba(20,184,166,.18);transform:translateY(-1px)}
            .form-side .inp button{position:absolute !important;z-index:3;right:6px !important;left:auto !important;top:50% !important;bottom:auto !important;transform:translateY(-50%) !important;margin:0 !important;width:34px;height:34px;padding:0 !important;display:grid;place-items:center;background:transparent !important;border:0 !important;box-shadow:none !important;cursor:pointer;color:#6B7C8C}
            .form-side #submit-btn{position:relative;overflow:hidden;width:100%;justify-content:center;margin-top:8px;padding:14px 16px;border:0;border-radius:14px;color:#fff;font-size:15.5px;font-weight:700;letter-spacing:.02em;cursor:pointer;
                background:linear-gradient(110deg,#0F766E,#0D9488,#0891B2,#0D9488,#0F766E) !important;background-size:240% 100% !important;animation:rise .6s .56s ease both,sweep 7s linear infinite;
                box-shadow:0 14px 28px rgba(13,148,136,.38),inset 0 1px 0 rgba(255,255,255,.3);transition:transform .15s,box-shadow .2s,filter .2s}
            .form-side #submit-btn::after{content:'';position:absolute;top:0;bottom:0;left:0;width:34%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.34),transparent);animation:shine 5.5s ease-in-out infinite}
            .form-side #submit-btn:hover:not(:disabled){transform:translateY(-2px);filter:brightness(1.07);box-shadow:0 18px 34px rgba(13,148,136,.46),inset 0 1px 0 rgba(255,255,255,.34)}
            .form-side #submit-btn:active:not(:disabled){transform:translateY(0)}
            .form-side #submit-btn:disabled{opacity:.75;cursor:not-allowed}
            .form-side .flash{background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;border-radius:12px;padding:10px 12px;font-size:13px;margin:0 0 14px}
            .props{margin-top:auto;padding-top:22px;animation:rise .6s .64s ease both}
            .props .chip{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:16px;background:rgba(255,255,255,.7);border:1px solid rgba(13,148,136,.16);box-shadow:0 6px 18px rgba(11,94,88,.08)}
            .props .dots{display:flex}.props .dots b{width:30px;height:30px;border-radius:50%;border:2px solid #fff;display:grid;place-items:center;font-size:9.5px;font-weight:800;color:#fff;margin-left:-5px}
            .props .dots b:first-child{margin-left:0;background:linear-gradient(135deg,#0D9488,#115E59)}.props .dots b:last-child{background:linear-gradient(135deg,#0891B2,#0369A1)}
            .props .t{font-size:12.5px;line-height:1.35;color:#25303F}.props .t span{display:block;color:#7C8B9A;font-size:11.5px}
            .login-spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite;vertical-align:-3px;margin-right:8px}
            #lock-countdown{font-variant-numeric:tabular-nums;font-weight:700}


            /* ---- transisi setelah login berhasil ---- */
            @keyframes okPop{0%{transform:scale(.6);opacity:0}60%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}
            @keyframes okDraw{to{stroke-dashoffset:0}}
            @keyframes tileBeat{0%,100%{transform:scale(1)}50%{transform:scale(1.07)}}
            @keyframes tileRing{0%{transform:scale(.9);opacity:.5}100%{transform:scale(1.7);opacity:0}}
            @keyframes wRise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
            #go-ov{position:fixed;inset:0;z-index:9999;pointer-events:none;visibility:hidden;color:#fff;display:grid;place-items:center;text-align:center;
                background:radial-gradient(120% 120% at 30% 20%,#0D9488 0%,#115E59 55%,#0B4F4A 100%);clip-path:circle(0px at var(--ox,50%) var(--oy,50%))}
            #go-ov .tile{position:relative;width:96px;height:96px;border-radius:26px;background:#fff;display:grid;place-items:center;margin:0 auto 22px;opacity:0;box-shadow:0 18px 50px rgba(2,36,40,.35)}
            #go-ov .tile img{width:72px;height:auto;display:block}
            #go-ov .tile::after{content:'';position:absolute;inset:0;border-radius:26px;border:2px solid #5EEAD4;opacity:0}
            #go-ov h2{margin:0;color:#fff;font-size:clamp(22px,4.4vw,34px);font-weight:800;letter-spacing:-.02em;opacity:0}
            #go-ov p{margin:8px 0 0;color:rgba(255,255,255,.78);font-size:14px;opacity:0}
            body.go-run #go-ov{visibility:visible}
            body.go-run #go-ov{animation:goGrow .55s .32s cubic-bezier(.65,0,.25,1) forwards}
            body.go-run #go-ov .tile{animation:okPop .4s .8s cubic-bezier(.2,.9,.3,1.3) forwards}
            body.go-run #go-ov .tile::after{animation:tileRing 1.1s .95s ease-out infinite}
            body.go-run #go-ov h2{animation:wRise .45s .9s ease forwards}
            body.go-run #go-ov p{animation:wRise .45s 1.0s ease forwards}
            @keyframes goGrow{to{clip-path:circle(150vmax at var(--ox,50%) var(--oy,50%))}}
            @keyframes goShell{to{transform:scale(.92);opacity:0;filter:blur(6px)}}
            @keyframes goBig{to{transform:translate3d(-60px,-30px,0) scale(1.6);opacity:0}}
            @keyframes goPh1{to{transform:translate3d(160px,-70px,0) scale(1.3) rotate(6deg);opacity:0}}
            @keyframes goPh2{to{transform:translate3d(-160px,90px,0) scale(1.3) rotate(-6deg);opacity:0}}
            body.go-run .shell{animation:goShell .6s .15s cubic-bezier(.5,0,.75,0) forwards}
            body.go-run .visual .big{animation:goBig .55s ease-in forwards;transition:none}
            body.go-run .ph1{animation:goPh1 .55s ease-in forwards}
            body.go-run .ph2{animation:goPh2 .55s ease-in forwards}
            body.go-run .login-baru{overflow:hidden}
            #submit-btn.ok{background:linear-gradient(110deg,#10B981,#0D9488) !important;animation:none !important;opacity:1 !important;cursor:default}
            #submit-btn.ok svg{width:20px;height:20px;vertical-align:-4px;margin-right:8px}
            #submit-btn.ok svg path{stroke-dasharray:24;stroke-dashoffset:24;animation:okDraw .35s .05s ease forwards}
            body.go-quick #go-ov{visibility:visible;clip-path:none;opacity:0;animation:wpFade .18s ease forwards}
            @keyframes wpFade{to{opacity:1}}
            body.go-quick #go-ov .tile,body.go-quick #go-ov h2,body.go-quick #go-ov p{opacity:1;animation:none}

            @media (max-width:880px){
                .shell{grid-template-columns:1fr;border-radius:28px}
                .visual{min-height:250px}
                .visual .big{top:70px;left:22px;font-size:54px}
                .ph{width:158px}.ph1{right:18px;top:132px}.ph2{display:none}.ph .tag{display:none}
                .visual .big small{display:none}
                .visual .cap{display:none}
                .visual .badge-top{left:22px;top:20px}
                .form-side{padding:26px 22px 22px}
            }
            @media (prefers-reduced-motion:reduce){
                .login-baru *,.login-baru *::before,.login-baru *::after{animation:none !important;transition:none !important}
                body.go-quick #go-ov{animation:wpFade .18s ease forwards !important}
            }
        </style>
    </head>
    <body>
    <div class="login login-baru" id="login-root">
        <div class="bg-blob a"></div><div class="bg-blob b"></div><div class="bg-blob c"></div>
        <div class="shell">
            <div class="visual" aria-hidden="true">
                <i class="m m1"></i><i class="m m2"></i><i class="m m3"></i>
                <span class="badge-top"><i></i> Revenue Analytics</span>
                <div class="big">CLARA<small>LEASING · MEDIA · GUDANG</small></div>
                <div class="ph ph1"><div class="card"><div class="img"><img src="assets/login-ewalk.jpg" alt="" width="960" height="567" loading="eager"></div><span class="tag"><i></i>e-Walk Simply FUNtastic</span></div></div>
                <div class="ph ph2"><div class="card"><div class="img"><img src="assets/login-pentacity.jpg" alt="" width="960" height="577" loading="eager"></div><span class="tag"><i></i>Pentacity Shopping Venue</span></div></div>
                <div class="cap">
                    <h2>Casual Leasing Achievement &amp; Revenue Analytics</h2>
                    <p>Pantau target, okupansi, dan pendapatan sewa Pentacity &amp; E-Walk dalam satu tempat.</p>
                </div>
            </div>
            <form class="form-side" method="post" id="login-form">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="logo-chip"><img src="assets/clara-logo.png" alt="CLARA"></div>
                <h1>Selamat datang kembali</h1>
                <p class="sub">Masuk dengan akun Anda untuk membuka dashboard CLARA.</p>
                <?php if ($hasError): ?>
                <div class="flash">
                    <?php if ($isLocked): ?>
                        Terlalu banyak percobaan login. Coba lagi dalam <strong id="lock-countdown"></strong>.
                    <?php else: ?>
                        <?= h($flashMsg) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
                <div class="fld">
                    <label for="em">Email</label>
                    <span class="inp"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg><input id="em" name="email" type="email" required autocomplete="username" autofocus placeholder="nama@perusahaan.com"></span>
                </div>
                <div class="fld" style="margin-bottom:20px">
                    <label for="pw">Password</label>
                    <span class="inp"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="3"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg><input id="pw" name="password" type="password" required autocomplete="current-password" placeholder="Masukkan password">
                        <button type="button" onclick="var i=document.getElementById('pw');i.type=i.type==='password'?'text':'password';this.innerHTML=i.type==='password'?'<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'18\' height=\'18\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z\'/><circle cx=\'12\' cy=\'12\' r=\'3\'/></svg>':'<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'18\' height=\'18\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'><path d=\'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94\'/><path d=\'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19\'/><line x1=\'1\' y1=\'1\' x2=\'23\' y2=\'23\'/></svg>'" style="position:absolute;right:0;top:0;height:100%;width:40px;background:none;border:none;cursor:pointer;color:var(--muted);display:flex;align-items:center;justify-content:center;padding:0"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </span>
                </div>
                <button type="submit" id="submit-btn">Masuk</button>
                <div class="props"><div class="chip">
                    <div class="dots"><b>PC</b><b>EW</b></div>
                    <div class="t">Pentacity Shopping Venue · E-Walk Simply FUNtastic<span>Dua properti, satu sistem pelaporan</span></div>
                </div></div>
            </form>
        </div>
    </div>
    <div id="go-ov" aria-live="polite" aria-hidden="true"><div><div class="tile"><img src="assets/clara-logo.png" alt=""></div><h2 id="go-h"></h2><p>Menyiapkan dashboard Anda…</p></div></div>
    <script>
    (function(){
        var form=document.getElementById('login-form'),btn=document.getElementById('submit-btn');
        var lockSec=<?= $lockRemaining ?>,hasError=<?= $hasError?'true':'false' ?>,isLocked=<?= $isLocked?'true':'false' ?>;
        if(hasError&&!isLocked){form.style.animation='shake .45s ease';form.addEventListener('animationend',function(){form.style.animation='';},{once:true});}
        else{form.style.animation='fadeInUp .35s ease both';form.addEventListener('animationend',function(){form.style.animation='';},{once:true});}
        if(lockSec>0){btn.disabled=true;var el=document.getElementById('lock-countdown');function tick(){if(lockSec<=0){btn.disabled=false;btn.textContent='Masuk';if(el)el.closest('.flash').textContent='Silakan coba lagi.';return;}var m=Math.floor(lockSec/60),s=lockSec%60;if(el)el.textContent=m+':'+String(s).padStart(2,'0');lockSec--;setTimeout(tick,1000);}tick();}
        var busy=false,reduce=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function reloadLogin(){location.replace(location.pathname+location.search);}
        function success(d,ev){
            var first=String(d.name||'').trim().split(/\s+/)[0]||'';
            document.getElementById('go-h').textContent='Halo'+(first?', '+first:'');
            btn.classList.add('ok');btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Berhasil';
            Array.prototype.forEach.call(form.elements,function(e){if(e!==btn)e.readOnly=true;});
            var go=function(){try{sessionStorage.setItem('clara_from_login','1')}catch(e){}location.replace(d.to);};
            if(reduce){document.body.classList.add('go-quick');setTimeout(go,220);return;}
            var r=btn.getBoundingClientRect();
            var ov=document.getElementById('go-ov');
            ov.style.setProperty('--ox',(r.left+r.width/2)+'px');ov.style.setProperty('--oy',(r.top+r.height/2)+'px');
            setTimeout(function(){document.body.classList.add('go-run');setTimeout(go,1900);},200);
        }
        form.addEventListener('submit',function(ev){
            if(btn.disabled||busy){ev.preventDefault();return false;}
            btn.disabled=true;btn.innerHTML='<span class="login-spinner"></span>Memuat…';
            if(!window.fetch||!window.FormData){return;} /* tanpa fetch: kirim biasa */
            ev.preventDefault();busy=true;
            fetch(location.href,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Login-Ajax':'1','Accept':'application/json'}})
            .then(function(r){var ct=r.headers.get('content-type')||'';if(ct.indexOf('application/json')<0)throw 0;return r.json();})
            .then(function(d){if(d&&d.ok===true&&typeof d.to==='string'&&d.to.indexOf('?r=')===0){btn.disabled=false;success(d);btn.disabled=true;}else reloadLogin();})
            .catch(reloadLogin);
        });
        var root=document.getElementById('login-root');
        if(root&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
            window.addEventListener('pointermove',function(ev){
                root.style.setProperty('--mx',((ev.clientX/window.innerWidth)-.5).toFixed(3));
                root.style.setProperty('--my',((ev.clientY/window.innerHeight)-.5).toFixed(3));
            },{passive:true});
        }
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
