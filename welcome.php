<?php
session_start();
if (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
    header("Location: user/user_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report System</title>
    <link rel="stylesheet" href="login.css?v=<?= time(); ?>">
    <link rel="icon" href="images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style> 
    
    /* ────────────────────────────────────────────────
   Landing Page Specific Styles
───────────────────────────────────────────────── */

.landing-container {
    width: 100%;
    min-height: 95vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #ea6666 0%, #a24b4b 100%);
    padding: 30px 20px;
}

.hero-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
    padding: 50px 40px;
    max-width: 580px;
    width: 100%;
    text-align: center;
}

.hero-card h1 {
    font-size: 2.1rem;
    color: #222;
    margin: 20px 0 12px;
    font-weight: 700;
}

.hero-subtitle {
    color: #555;
    font-size: 1.05rem;
    line-height: 1.6;
    margin-bottom: 40px;
}

.big-login-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    background: #fe0000ff;
    color: white;
    font-size: 1.25rem;
    font-weight: 600;
    padding: 18px 48px;
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(254, 0, 0, 0.25);
    margin: 20px 0 30px;
}

.big-login-btn:hover {
    background: #d32f2f;
    transform: translateY(-4px);
    box-shadow: 0 14px 35px rgba(254, 0, 0, 0.35);
}

.big-login-btn:active {
    transform: translateY(-1px);
}

.secondary-text {
    color: #666;
    font-size: 0.95rem;
    margin: 10px 0 40px;
}

.secondary-text i {
    color: #fe0000ff;
    margin-right: 8px;
}

.features {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 28px;
    margin-bottom: 40px;
}

.feature-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: #444;
    font-size: 0.95rem;
    font-weight: 500;
}

.feature-item i {
    font-size: 2.1rem;
    color: #fe0000ff;
    opacity: 0.9;
}

.landing-footer {
    margin-top: 30px;
    padding-top: 25px;
    border-top: 1px solid #eee;
    color: #777;
    font-size: 0.9rem;
}

.contact-hint {
    margin-top: 10px;
    color: #555;
}

.contact-hint strong {
    color: #fe0000ff;
}

/* Responsive adjustments */
@media (max-width: 480px) {
    .hero-card {
        padding: 40px 25px;
    }
    .big-login-btn {
        padding: 16px 36px;
        font-size: 1.1rem;
    }
    h1 {
        font-size: 1.8rem;
    }
}

</style>
</head>
<body>
    <div class="landing-container">
        <div class="hero-card">
            <div class="logo-container">
                <img src="images/MLW%20Logo.png" alt="QCL Logo" class="logo">
            </div>

            <h1>Financial Statement Consolidator System</h1>
            <p class="hero-subtitle">
                Manage, analyze, and generate comparative reports with ease.<br>
                Secure access for authorized users only.
            </p>

            <div class="action-area">
                <a href="login.php" class="big-login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login to Your Account</span>
                </a>

                <p class="secondary-text">
                    <i class="fas fa-shield-alt"></i>
                    Secured system • Admin-managed access
                </p>
            </div>

            <div class="features">
                <div class="feature-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Comparative Analytics</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-file-export"></i>
                    <span>Export Reports</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-users-cog"></i>
                    <span>Role-based Access</span>
                </div>
            </div>

            <footer class="landing-footer">
                <p>© <?= date("Y") ?> Comparative Report System • All rights reserved • MLFSI</p>
                <p class="contact-hint">
                    Need an account? <strong>Contact your system administrator</strong>
                </p>
            </footer>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const elements = document.querySelectorAll('.logo, h1, .hero-subtitle, .big-login-btn, .features');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.7s ease-out';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, 150 * index);
            });
        });
    </script>
</body>
</html>