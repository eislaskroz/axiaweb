<?php
require __DIR__.'/lib/bootstrap.php';
if (current_user() && (current_user()['role'] ?? '') === 'admin_axia') {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = 'Captura correo y contraseña.';
    } else {
        $r = supabase_request('POST', '/auth/v1/token?grant_type=password', ['email'=>$email,'password'=>$password]);
        if ($r['status'] >= 200 && $r['status'] < 300 && !empty($r['data']['access_token'])) {
            $token = $r['data']['access_token'];
            $userId = $r['data']['user']['id'] ?? '';
            $profile = $userId ? fetch_profile($token, $userId) : null;
            if (!$profile || ($profile['role'] ?? '') !== 'admin_axia') {
                $error = 'Tu cuenta no tiene permisos administrativos para este portal.';
            } else {
                session_regenerate_id(true);
                $_SESSION['access_token'] = $token;
                $_SESSION['refresh_token'] = $r['data']['refresh_token'] ?? null;
                $_SESSION['user'] = $profile;
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $error = 'Credenciales incorrectas o no fue posible conectar con Supabase.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            AXIA Tickets · Acceso
        </title>
        <link rel="stylesheet" href="assets/css/portal.css">
    </head>
    <body class="login-body">
        <div class="login-shell">
            <section class="login-brand">
                <div class="brand-glow">
                </div>
                <img src="assets/img/logo-axia.webp" alt="AXIA" class="login-logo">
                <div class="eyebrow">
                    CENTRO DE OPERACIONES
                </div>
                <h1>
                    Administración
                    <br>
                    de Tickets
                </h1>
                <p>
                    Consulta, asigna y da seguimiento a las solicitudes generadas desde AXIA Tickets.
                </p>
                <div class="login-features">
                    <span>
                        ✓ Acceso privado
                    </span>
                    <span>
                        ✓ Datos en tiempo real
                    </span>
                    <span>
                        ✓ Control de atención
                    </span>
                </div>
            </section>
            <section class="login-panel">
                <div class="login-card">
                    <div class="mini-logo">
                        <img src="assets/img/logo-axia.webp" alt="AXIA">
                    </div>
                    <div class="eyebrow dark">
                        PORTAL INTERNO AXIA
                    </div>
                    <h2>
                        Bienvenido
                    </h2>
                    <p class="muted">
                        Ingresa con una cuenta administrativa.
                    </p>
                    <?php if($error): ?>
                        <div class="alert error">
                            <?=h($error)?>
                        </div>
                    <?php endif; ?>
                    <form method="post" autocomplete="on">
                        <label>
                            Correo electrónico
                        </label>
                        <div class="field">
                            <span>
                                ✉
                            </span>
                            <input type="email" name="email" placeholder="usuario@axiacomunicaciones.mx" required autocomplete="username">
                        </div>
                        <label>
                            Contraseña
                        </label>
                        <div class="field">
                            <span>
                                🔒
                            </span>
                            <input id="password" type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                            <button type="button" class="eye" onclick="togglePassword()">
                                ◉
                            </button>
                        </div>
                        <button class="primary-btn" type="submit">
                            <span>
                                →
                            </span>
                            INGRESAR AL PORTAL
                        </button>
                    </form>
                    <div class="security-note">
                        🔐 Sesión protegida mediante Supabase Authentication
                    </div>
                </div>
            </section>
        </div>
        <script src="assets/js/portal.js">
        </script>
    </body>
</html>
