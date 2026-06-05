<?php
/**
 * ================================================================
 * HALAMAN LOGIN ADMIN
 * ================================================================
 */
declare(strict_types=1);
require_once 'config.php';

secure_session_start();

// Jika sudah login, redirect ke dashboard
if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

$error = '';
$username = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifikasi CSRF token
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi form tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        // Sanitasi input
        $username = clean_input($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Validasi input
        if (empty($username) || empty($password)) {
            $error = 'Username dan password tidak boleh kosong';
        } else {
            try {
                // Query user dari database
                $stmt = $pdo->prepare(
                    'SELECT id, username, password 
                     FROM users 
                     WHERE username = :username 
                     LIMIT 1'
                );
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                // Verifikasi password menggunakan password_verify
                if ($user && password_verify($password, $user['password'])) {
                    // Login berhasil
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = (int) $user['id'];
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['last_regenerated'] = time();
                    
                    // Redirect ke dashboard
                    redirect('admin/dashboard.php');
                } else {
                    // Login gagal
                    $error = 'Username atau password salah';
                    error_log('Failed login attempt for username: ' . $username . ' at ' . date('Y-m-d H:i:s'));
                }
            } catch (PDOException $e) {
                $error = 'Terjadi kesalahan database. Silakan coba lagi.';
                error_log('Login error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo e(APP_NAME); ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #cc0000 0%, #ffffff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 20px;
        }

        .login-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .login-header {
            background: #cc0000;
            color: white;
            padding: 40px 20px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 2rem;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-header p {
            margin: 10px 0 0 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .login-body {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-control {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px 15px;
            font-size: 1rem;
        }

        .form-control:focus {
            border-color: #cc0000;
            box-shadow: 0 0 0 0.2rem rgba(204, 0, 0, 0.25);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #cc0000;
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.3s;
        }

        .btn-login:hover {
            background: #aa0000;
            color: white;
            text-decoration: none;
        }

        .alert {
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .footer-text {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 0.9rem;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #cc0000;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #ddd;
            color: #cc0000;
        }

        @media (max-width: 576px) {
            .login-header {
                padding: 30px 15px;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }

            .login-body {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card login-card">
            <!-- LOGIN HEADER -->
            <div class="login-header">
                <h1>
                    <i class="fas fa-user-shield"></i> Admin
                </h1>
                <p><?php echo e(APP_NAME); ?></p>
            </div>

            <!-- LOGIN BODY -->
            <div class="login-body">
                <!-- ERROR MESSAGE -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Login Gagal!</strong> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- LOGIN FORM -->
                <form method="POST" action="">
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <!-- Username Field -->
                    <div class="form-group">
                        <label for="username" class="form-label">
                            <i class="fas fa-user"></i> Username
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-at"></i>
                            </span>
                            <input 
                                type="text" 
                                id="username" 
                                name="username" 
                                class="form-control" 
                                placeholder="Masukkan username"
                                value="<?php echo e($username); ?>"
                                autocomplete="username"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-key"></i>
                            </span>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control" 
                                placeholder="Masukkan password"
                                autocomplete="current-password"
                                required
                            >
                        </div>
                    </div>

                    <!-- Login Button -->
                    <button type="submit" class="btn btn-login">
                        <i class="fas fa-sign-in-alt"></i> Masuk
                    </button>
                </form>

                <!-- FOOTER TEXT -->
                <div class="footer-text">
                    <p>Gunakan akun admin yang telah didaftarkan</p>
                </div>

                <!-- BACK LINK -->
                <div class="back-link">
                    <a href="index.php">
                        <i class="fas fa-arrow-left"></i> Kembali ke Halaman Utama
                    </a>
                </div>
            </div>
        </div>

        <!-- ADDITIONAL INFO -->
        <div style="text-align: center; margin-top: 30px; color: white;">
            <small>
                &copy; 2026 <?php echo e(SCHOOL_NAME); ?> - Sistem Pengumuman Kelulusan
            </small>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
