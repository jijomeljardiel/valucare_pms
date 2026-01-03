<?php /* Login page: authentication form at session setup. */
session_start();
require_once 'config/database.php';
require_once 'includes/activity_logger.php';


function normalize_role($role){
    $r = strtolower(trim((string)$role));
    $r = str_replace([' ', '-', 'system dev', 'system_dev'], ['_', '_', 'systemdev', 'systemdev'], $r);
    if ($r === 'teamlead' || $r === 'team_lead' || $r === 'team lead' || $r === 'team-lead') $r = 'project_manager';
    return $r;
}
function role_landing($role) {
    $r = normalize_role($role ?? '');
    if ($r === 'project_manager') return 'projectmanager_dashboard.php';
    if ($r === 'systemdev') return 'dashboard.php';
    if ($r === 'admin') return 'dashboard.php';
    return 'dashboard.php';
}
if (isset($_SESSION['user_id'])) {
    $target = role_landing($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
    header('Location: ' . $target);
    exit();
}

$error_message = '';


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


$logo1_path = 'assets/images/vc.png';
$logo2_path = 'assets/images/ICT2.png';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $posted_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['csrf_token'], $posted_token)) {
        $error_message = 'Invalid request token. Please retry.';
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if (empty($username) || empty($password)) {
            $error_message = 'Please fill in all fields.';
        } else {
            try {
                $pdo = getDBConnection();
                

                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user && isset($user['password'])) {
                    $stored = $user['password'];
                    $info = password_get_info($stored);
                    $login_ok = ($info && isset($info['algo']) && $info['algo'] !== 0)
                        ? password_verify($password, $stored)
                        : hash_equals($stored, $password);

                    if ($login_ok) {

                        $role_slug = normalize_role($user['role'] ?? '');
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['role_slug'] = $role_slug;
                        $_SESSION['department'] = $user['department'];
                        $_SESSION['position'] = $user['position'];
                        $_SESSION['employee_id'] = $user['employee_id'];
                        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        if ($fullName === '') { $fullName = $user['username']; }
                        $_SESSION['login_user'] = [
                          'full_name' => $fullName,
                          'role' => $user['role'] ?? 'staff',
                          'picture' => null,
                        ];

                        try {
                            $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                            $update_stmt->execute([$user['id']]);
                        } catch (PDOException $e) {}

                        log_activity('login_success', 'user', $user['id'], ['username' => $user['username']]);
                        $target = role_landing($role_slug);
                        header('Location: ' . $target);
                        exit();
                    } else {
                        $error_message = 'Invalid username or password.';
                        log_activity('login_failed', 'user', $user['id'] ?? null, ['username' => $username]);
                    }
                } else {
                    $error_message = 'Invalid username or password.';
                    log_activity('login_failed', null, null, ['username' => $username]);
                }
            } catch (Throwable $e) {
                $error_message = 'Unable to connect to database. Please try again later.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VC PMS - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="assets/js/scroll-protection.js"></script>
    <style>
        :root {
            --bg: #f7fafc;
            --surface: #ffffff;
            --primary: #6b7ed6;
            --accent: #9bbad9;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e5e7eb;
            --focus-ring: rgba(107, 126, 214, 0.25);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: linear-gradient(135deg, var(--bg) 0%, #f9fbfd 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            position: relative; overflow-y: hidden !important; overflow-x: hidden !important;
        }
        body::-webkit-scrollbar { display: none; }
        body { -ms-overflow-style: none; scrollbar-width: none; }
        .bg-animation { position: absolute; width: 100%; height: 100%; overflow: visible; z-index: 0; }
        .bg-animation::before { content: ''; position: absolute; top:-50%; left:-50%; width:200%; height:200%; background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="rgba(255,255,255,0.1)"/></svg>') repeat; animation: float 20s infinite linear; }
        @keyframes float { 0%{ transform: translateY(0) rotate(0deg);} 100%{ transform: translateY(-100px) rotate(360deg);} }
        .login-container { background: var(--surface); backdrop-filter: blur(18px); border-radius: 16px; box-shadow: 0 16px 36px rgba(15,23,42,0.12); padding: 2rem; width:100%; max-width: 400px; position: relative; z-index:1; border:1px solid var(--border); animation: rise 420ms ease-out; will-change: transform; }
        .logo-container { text-align:center; margin-bottom:1.25rem; }
        .logo-row { display:flex; align-items:center; justify-content:center; gap:1rem; margin-bottom:0.9rem; }
        .logo { width:56px; height:48px; margin:0; filter:drop-shadow(0 4px 8px rgba(0,0,0,0.1)); object-fit:contain; }
        .brand-name { font-size:1.7rem; font-weight:700; color:var(--primary); margin-bottom:0.5rem; letter-spacing:-0.25px; }
        .brand-subtitle { font-size:0.9rem; color:var(--accent); font-weight:500; }
        .decor { position:fixed; top:0; left:0; width:100%; height:100vh; z-index:-1; pointer-events:none; }
        .decor::after { content:''; position:absolute; inset:0; background: radial-gradient(1200px 600px at 10% 10%, rgba(107,126,214,0.08), transparent 60%), radial-gradient(1200px 600px at 90% 90%, rgba(155,186,217,0.12), transparent 60%); animation: decorShift 20s ease-in-out infinite alternate; }
        .login-form { margin-top:1rem; }
        .form-group { margin-bottom:1rem; position:relative; }
        .form-group::after { content:''; position:absolute; left:-2px; top:2px; bottom:2px; width:0; background: linear-gradient(180deg, var(--accent), transparent); border-radius:8px; opacity:0.4; transition: width 200ms ease; }
        .form-group:focus-within::after { width:4px; }
        .form-label { display:block; font-size:0.875rem; font-weight:500; color:var(--muted); margin-bottom:0.4rem; }
        .form-input { width:100%; padding:0.75rem 0.9rem 0.75rem 2.5rem; border:2px solid var(--border); border-radius:12px; font-size:0.95rem; transition: box-shadow 180ms ease, border-color 180ms ease, background 180ms ease; background:#f8fafc; color:var(--text); }
        .form-input::placeholder { color:#94a3b8; }
        .form-input:focus { outline:none; border-color:var(--primary); background:var(--surface); box-shadow:0 0 0 3px var(--focus-ring); }
        .input-icon { position:absolute; left:0.9rem; top:2rem; color:var(--muted); font-size:1rem; transition: color 0.3s ease; }
        .form-input:focus + .input-icon { color:var(--primary); }
        .password-toggle { position:absolute; right:0.9rem; top:2rem; color:var(--muted); cursor:pointer; font-size:1rem; transition: color 0.3s ease; }
        .password-toggle:hover, .password-toggle:focus-visible { color:var(--primary); outline:2px solid var(--primary); outline-offset:2px; border-radius:6px; }
        .password-toggle[aria-pressed="true"] { transform: rotate(-10deg) scale(1.05); }
        .error-message { background:#fff5f5; color:#c53030; padding:0.875rem 1rem; border-radius:12px; font-size:0.9rem; margin-bottom:1.25rem; border:1px solid #fda4a4; display:flex; align-items:center; gap:0.5rem; animation: fadein 200ms ease-out; }
        .login-button { width:100%; background: linear-gradient(135deg, #8ea1e2 0%, #6b7ed6 100%); color:#fff; border:none; padding:0.85rem; border-radius:14px; font-size:0.95rem; font-weight:600; cursor:pointer; transition: transform 180ms ease, box-shadow 180ms ease; position:relative; overflow:visible; }
        .login-button:hover, .login-button:focus-visible { transform: translateY(-1px); box-shadow:0 10px 24px rgba(107,126,214,0.28); outline:2px solid var(--primary); outline-offset:2px; }
        .login-button:active { transform: translateY(0); }
        .login-button::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition:left 0.5s; }
        .login-button:hover::before { left:100%; }
        .forgot-password { text-align:center; margin-top:1rem; }
        .forgot-password a { color:var(--primary); text-decoration:none; font-size:0.875rem; font-weight:500; transition: color 0.3s ease; }
        .forgot-password a:hover, .forgot-password a:focus-visible { color:#5971cf; text-decoration:underline; outline:2px solid var(--primary); outline-offset:2px; border-radius:6px; }
        .footer-text { text-align:center; margin-top:1.25rem; padding-top:1rem; border-top:1px solid #e2e8f0; color:#718096; font-size:0.825rem; }
        @media (max-width:480px){ body{ justify-content:center; } .login-container{ margin:1rem; padding:1.75rem; max-width:92vw; } .brand-name{ font-size:1.6rem; } }
        @media (max-height: 640px){ body{ overflow-y: auto !important; } }
        .loading { display:inline-block; width:20px; height:20px; border:3px solid rgba(255,255,255,0.3); border-radius:50%; border-top-color:#fff; animation:spin 1s ease-in-out infinite; }
        @keyframes spin { to{ transform: rotate(360deg);} }
        @keyframes fadein { from{ opacity:0; transform: translateY(-2px);} to{ opacity:1; transform: translateY(0);} }
        @keyframes rise { from{ opacity:0; transform: translateY(8px);} to{ opacity:1; transform: translateY(0);} }
        @keyframes decorShift { from{ transform: translate3d(0,0,0) scale(1);} to{ transform: translate3d(0,-10px,0) scale(1.03);} }
        @media (prefers-reduced-motion: reduce){ .login-button, .form-input, .password-toggle, .login-container, .decor::after, .error-message, .theme-toggle i { transition:none !important; animation:none !important; } }
        @media (min-width:992px){ .login-container{ max-width:420px; } }
        .theme-toggle { position:absolute; top:0.75rem; right:0.75rem; display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:10px; border:1px solid var(--border); background: #f8fafc; color: var(--muted); cursor:pointer; transition: background 180ms ease, box-shadow 180ms ease, transform 180ms ease; }
        .theme-toggle:hover, .theme-toggle:focus-visible { background: var(--surface); box-shadow:0 8px 18px rgba(15,23,42,0.12); outline:2px solid var(--primary); outline-offset:2px; }
        .theme-toggle i { transition: transform 240ms ease; }
        .theme-toggle:active i { transform: rotate(20deg) scale(0.95); }
        [data-theme="dark"] { --bg: #0b1220; --surface: #0f172a; --primary: #93a4ea; --accent: #7ea0c6; --text: #e5e7eb; --muted: #94a3b8; --border: #1f2937; --focus-ring: rgba(147,164,234,0.3); }
        [data-theme="dark"] body { background: linear-gradient(135deg, var(--bg) 0%, #0d1425 100%); }
        [data-theme="dark"] .form-input { background:#0b1220; color: var(--text); border-color: #233041; }
        [data-theme="dark"] .theme-toggle { background:#0b1220; color: var(--text); border-color:#233041; }
    </style>
</head>
<body>
    <div class="decor"></div>
    <div class="bg-animation"></div>
    <div class="login-container">
        <div class="logo-container">
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
            <div class="logo-row">
                <img src="<?php echo htmlspecialchars($logo1_path); ?>" alt="Logo 1" class="logo">
                <img src="<?php echo htmlspecialchars($logo2_path); ?>" alt="Logo 2" class="logo">
            </div>
            <h1 class="brand-name">VC PMS</h1>
            <p class="brand-subtitle">Project Management System</p>
        </div>
        

        <?php if (!empty($error_message)): ?>
            <div class="error-message" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form class="login-form" method="POST" action="" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-input" 
                    placeholder="Enter your username"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    required
                >
                <i class="fas fa-user input-icon"></i>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-input" 
                    placeholder="Enter your password"
                    required
                >
                <i class="fas fa-lock input-icon"></i>
                <i class="fas fa-eye password-toggle" id="togglePassword" tabindex="0" aria-label="Toggle password visibility" aria-pressed="false"></i>
            </div>

            <button type="submit" class="login-button" id="loginBtn">
                <span id="btnText">Sign In</span>
                <span id="btnLoading" class="loading" style="display: none;"></span>
            </button>
        </form>

        <div class="forgot-password">
            <a href="#" onclick="alert('Please contact your system administrator to reset your password.')">
                Forgot your password?
            </a>
        </div>

        <div class="footer-text">
            © 2025 ValuCare Interns CAPSTONE Project. All rights reserved.
        </div>
    </div>

    <script>

        (function(){
            const toggle = document.getElementById('togglePassword');
            function runToggle(){
                const password = document.getElementById('password');
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                toggle.classList.toggle('fa-eye');
                toggle.classList.toggle('fa-eye-slash');
                toggle.setAttribute('aria-pressed', String(type === 'text'));
            }
            toggle.addEventListener('click', runToggle);
            toggle.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); runToggle(); } });
        })();


        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const btnLoading = document.getElementById('btnLoading');
            
            btn.disabled = true;
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline-block';
        });


        document.addEventListener('DOMContentLoaded', function() {
            var u = document.getElementById('username');
            if (u) u.focus();
            var root = document.documentElement;
            function setTheme(t){ root.dataset.theme = t; localStorage.setItem('vc-theme', t); updateToggleIcon(t); }
            function getInitialTheme(){
                var saved = localStorage.getItem('vc-theme');
                if (saved === 'dark' || saved === 'light') return saved;
                return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            function updateToggleIcon(t){
                var btn = document.getElementById('themeToggle');
                if (!btn) return;
                var i = btn.querySelector('i');
                if (!i) return;
                if (t === 'dark'){ i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
                else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
            }
            var init = getInitialTheme();
            root.dataset.theme = init;
            updateToggleIcon(init);
            var toggle = document.getElementById('themeToggle');
            if (toggle){
                toggle.addEventListener('click', function(){ setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark'); });
                toggle.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark'); } });
            }
        });


        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                const icon = this.nextElementSibling;
                if (icon && icon.classList.contains('input-icon')) {
                    icon.style.transform = 'translateY(-2px) scale(1.05)';
                }
            });
            
            input.addEventListener('blur', function() {
                const icon = this.nextElementSibling;
                if (icon && icon.classList.contains('input-icon')) {
                    icon.style.transform = 'translateY(0) scale(1)';
                }
            });
        });
    </script>
</body>
</html>
