<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Check for just logged in status
$just_logged_in = false;
if (isset($_SESSION['just_logged_in']) && $_SESSION['just_logged_in'] === true) {
    $just_logged_in = true;
    // Clear the flag so it only shows once
    unset($_SESSION['just_logged_in']);
}

if (!isset($_SESSION['username'])) {
    header("Location: ../welcome.php");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../welcome.php");
    exit;
}

$username   = $_SESSION['username'] ?? "unknown";
$full_name  = $_SESSION['full_name'] ?? "unknown";
$user_type  = $_SESSION['user_type'] ?? "unknown";
$user_id    = $_SESSION['user_id'] ?? 0;

$last_online = null;
$is_default_password = false;

$db_password = null; // Initialize to prevent "Undefined variable" notice
if (!empty($username)) {
    $stmt = $conn->prepare("
        SELECT last_online, password 
        FROM qcl_user 
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($last_online, $db_password);
    $stmt->fetch();
    $stmt->close();
    
    // Check if password is the default password
      if ($db_password !== null && password_verify('Mlinc1234', $db_password)) {
        $is_default_password = true;
    }
}

// Handle password change
$password_error = '';
$password_success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $stored_password = null; // Initialize to prevent "Undefined variable" notice
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM qcl_user WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($stored_password);
    $stmt->fetch();
    $stmt->close();
    
    // Check if stored_password is not null before verification
    if ($stored_password === null) {
        $password_error = "User account not found.";
    } elseif (!password_verify($current_password, $stored_password)) {
        $password_error = "Current password is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $password_error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $password_error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $password_error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $password_error = "Password must contain at least one number.";
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
        $password_error = "Password must contain at least one special character.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New password and confirm password do not match.";
    } elseif ($new_password === 'Mlinc1234') {
        $password_error = "New password cannot be the default password.";
    } else {
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE qcl_user SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed_password, $user_id);
        
        if ($update->execute()) {
            $password_success = "Password changed successfully!";
            $is_default_password = false;
            
            // Set a session flag to show success message and refresh
            $_SESSION['password_change_success'] = true;
            $_SESSION['success_message'] = "Password changed successfully!";
            
            // Refresh the page to hide the modal
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $password_error = "Failed to update password. Please try again.";
        }
        $update->close();
    }
}

// Check if we need to show a success message from session
$show_success_message = false;
$success_message = '';
if (isset($_SESSION['password_change_success']) && $_SESSION['password_change_success']) {
    $show_success_message = true;
    $success_message = $_SESSION['success_message'] ?? 'Password changed successfully!';
    // Clear the session flags
    unset($_SESSION['password_change_success']);
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= time(); ?>">
    <title>Dashboard | Control Panel</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    
</head>
<body>

    <!-- Sign In Success Loading Effect -->
    <?php if ($just_logged_in): ?>
    <div id="signin-overlay" class="signin-overlay">
        <div class="signin-loader">
            <div class="loader-circle">
                <div class="loader-checkmark">
                    <svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                        <circle class="checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                        <path class="checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                    </svg>
                </div>
            </div>
            <h3 class="loader-text">Welcome, <?php echo htmlspecialchars($full_name); ?>!</h3>
            <p class="loader-subtext">Redirecting to dashboard...</p>
        </div>
    </div>
    
    <script>
        // Auto-hide after 5 seconds and refresh
        setTimeout(function() {
            const overlay = document.getElementById('signin-overlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(function() {
                    window.location.reload();
                }, 500);
            }
        }, 5000);
    </script>
    <?php endif; ?>

    <!-- Password Change Modal -->
    <div id="passwordChangeModal" class="password-modal <?php echo $is_default_password ? 'show' : ''; ?>">
        <div class="password-modal-content">
            <h2>
                <i class="fas fa-shield-alt"></i>
                Change Password Required
            </h2>
            
            <?php if ($is_default_password): ?>
                <div class="warning-text">
                    <i class="fas fa-exclamation-triangle"></i>
                    You are currently using the default password. For security reasons, please change your password immediately.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($password_error)): ?>
                <div class="error-message">
                    <i class="fas fa-times-circle"></i>
                    <?php echo htmlspecialchars($password_error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" id="passwordChangeForm">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="current_password" name="current_password" required placeholder="Enter your current password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('current_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="new_password" name="new_password" required placeholder="Enter new password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('new_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm new password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('confirm_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <p><i class="fas fa-list"></i> Password Requirements:</p>
                    <ul>
                        <li id="req-length" class="invalid">
                            <i class="fas fa-times-circle"></i> At least 8 characters
                        </li>
                        <li id="req-uppercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one uppercase letter
                        </li>
                        <li id="req-lowercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one lowercase letter
                        </li>
                        <li id="req-number" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one number
                        </li>
                        <li id="req-special" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one special character (!@#$%^&*)
                        </li>
                        <li id="req-not-default" class="invalid">
                            <i class="fas fa-times-circle"></i> Not the default password
                        </li>
                    </ul>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-save"></i> Change Password
                    </button>
                    <?php if (!$is_default_password): ?>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Later
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($is_default_password): ?>
                    <small style="display: block; text-align: center; margin-top: 15px;">
                        <i class="fas fa-info-circle"></i> 
                        You must change your password before accessing the dashboard.
                    </small>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Success Toast Notification -->
    <?php if ($show_success_message): ?>
    <div id="successToast" class="success-toast">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($success_message); ?></span>
        <span class="close-toast" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <script>
        // Auto-hide toast after 3 seconds
        setTimeout(function() {
            var toast = document.getElementById('successToast');
            if (toast) {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(function() {
                    if (toast) toast.remove();
                }, 300);
            }
        }, 3000);
    </script>
    <?php endif; ?>

    <!-- Professional Sidebar Navigation -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <i class="fas fa-chart-line"></i>
                <span>FS Reports</span>
            </div>
        </div>
        
        <div class="sidebar-user">
            <div class="user-avatar">
                <span><?php echo substr($full_name, 0, 1); ?></span>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_type)); ?></div>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="user_dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <?php if ($user_type === "admin" || $user_type === "reports" || $user_type === "bookkeeper") : ?>
                <!-- Comparative Report Dropdown -->
                <li class="has-dropdown">
                    <a href="javascript:void(0)" class="dropdown-trigger">
                        <i class="fas fa-chart-bar"></i>
                        <span>Comparative Report</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="javascript:void(0)" onclick="openFileUploadModal()">
                                <i class="fas fa-upload"></i> Upload Report
                            </a>
                        </li>

                           <li>
                                <a href="upload_raw_data.php">
                                    <i class="fa-solid fa-database"></i> Upload Raw Data
                                </a>
                            </li>

                        <li>
                            <a href="javascript:void(0)" onclick="openLockUnlockModal()">
                                <i class="fas fa-lock"></i> Lock / Unlock Period
                            </a>
                        </li>
                        <?php if ($user_type === "admin" || $user_type === "reports") : ?>
                            <li>
                                <a href="settings.php">
                                    <i class="fas fa-chart-pie"></i> Comparative Report
                                </a>
                            </li>
                            <li>
                                <a href="fs_reports.php">
                                    <i class="fas fa-file-invoice-dollar"></i> Comparative Report (With HO) / Cumulative Report
                                </a>
                            </li>
                            <li>
                                <a href="upload_previous.php">
                                    <i class="fas fa-history"></i> Previous Report
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($user_type === "admin") : ?>
                <?php
                // Get count of pending password reset requests
                $pending_count = 0;
                $count_query = "SELECT COUNT(*) as total FROM fs_reports.password_reset_requests WHERE status = 'Pending'";
                $count_result = $conn->query($count_query);
                if ($count_result && $row = $count_result->fetch_assoc()) {
                    $pending_count = $row['total'];
                }
                ?>
                <li class="has-dropdown">
                    <a href="javascript:void(0)" class="dropdown-trigger">
                        <i class="fas fa-user-cog"></i>
                        <span>Users</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="all_users.php">
                                <i class="fas fa-users"></i> All Users
                            </a>
                        </li>
                        <li>
                            <a href="password_reset_requests.php">
                                <i class="fas fa-key"></i> Password Reset Requests
                                <?php if ($pending_count > 0): ?>
                                    <span class="pending-badge"><?php echo $pending_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>
            
            <li class="logout-item">
                <a href="?action=logout" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-bar">
            <div class="page-title">
                <i class="fas fa-home"></i>
                <h2>Dashboard Overview</h2>
            </div>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?></span>
                <div class="avatar"><?php echo substr($full_name, 0, 1); ?></div>
            </div>
        </header>

        <div class="dashboard-grid">
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <h3>Account Information</h3>
                <div class="info-row">
                    <span class="info-label">Full Name:</span>
                    <span><?php echo htmlspecialchars($full_name); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Access Level:</span>
                    <span class="access-level">
                        <?php echo strtoupper(htmlspecialchars($user_type)); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="status-badge online">
                        <i class="fas fa-circle"></i> Online
                    </span>
                </div>
                <?php if ($is_default_password): ?>
                    <div class="info-row">
                        <span class="info-label">Password Status:</span>
                        <span class="status-badge warning">
                            <i class="fas fa-exclamation-triangle"></i> Default Password
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>System Overview</h3>
                <div class="last-login">
                    <i class="fas fa-clock"></i>
                    <div>
                        <span class="info-label">Last Login:</span>
                        <?php
                            echo $last_online 
                                ? date('F j, Y h:i:s A', strtotime($last_online)) 
                                : 'N/A';
                        ?>
                    </div>
                </div>
                <div class="system-stats">
                    <!-- <div class="stat">
                        <span class="stat-value"><?php echo date('F Y'); ?></span>
                        <span class="stat-label">Current Period</span>
                    </div> -->
                </div>
            </div>
        </div>
        
        <?php if ($is_default_password): ?>
            <div class="security-alert">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <strong>Security Alert:</strong> You are currently using the default password. 
                    <a href="javascript:void(0)" onclick="document.getElementById('passwordChangeModal').classList.add('show');">
                        Change your password now
                    </a>.
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Upload Report Selection Modal -->
    <div id="uploadFileModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 400px; text-align: center; position: relative;">
            <span onclick="closeUploadFileModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.5rem; text-align: center;">
                <i class="fas fa-file-upload" style="color: #007bff; margin-right: 10px;"></i>Upload Report<br>(Per Region-Area)
            </h2>
            <p style="margin-bottom: 30px; color: #666; font-size: 1rem;">Please select the file format you wish to upload:</p>
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <a href="comparative_report.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-file-excel" style="margin-right: 8px;"></i> Upload Excel File (.xlsx, .xls)
                </a>
                <a href="comparative_report_csv.php" class="btn" style="background-color: #17a2b8; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-file-csv" style="margin-right: 8px;"></i> Upload CSV File (.csv)
                </a>
            </div>
        </div>
    </div>

    <!-- Lock/Unlock Selection Modal -->
    <div id="lockUnlockModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 400px; text-align: center; position: relative;">
            <span onclick="closeLockUnlockModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.5rem;">
                <i class="fas fa-calendar-alt" style="color: #007bff; margin-right: 10px;"></i>Manage Period
            </h2>
            <p style="margin-bottom: 30px; color: #666; font-size: 1rem;">Please select an action to proceed:</p>
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <a href="lock_period.php" class="btn" style="background-color: #dc3545; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-lock" style="margin-right: 8px;"></i> Lock Period
                </a>
                <a href="unlock_period.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-unlock" style="margin-right: 8px;"></i> Unlock Period
                </a>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('passwordChangeModal').classList.remove('show');
        }

        function openLockUnlockModal() {
            document.getElementById('lockUnlockModal').classList.add('show');
        }

        function closeLockUnlockModal() {
            document.getElementById('lockUnlockModal').classList.remove('show');
        }

        function openFileUploadModal() {
            document.getElementById('uploadFileModal').classList.add('show');
        }

        function closeUploadFileModal() {
            document.getElementById('uploadFileModal').classList.remove('show');
        }
        
        // Dropdown toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize dropdown toggles
            const dropdownTriggers = document.querySelectorAll('.dropdown-trigger');
            
            dropdownTriggers.forEach(trigger => {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    const parent = this.closest('.has-dropdown');
                    const dropdownMenu = parent.querySelector('.dropdown-menu');
                    const icon = this.querySelector('.dropdown-icon');
                    
                    // Close other open dropdowns
                    dropdownTriggers.forEach(otherTrigger => {
                        if (otherTrigger !== trigger) {
                            const otherParent = otherTrigger.closest('.has-dropdown');
                            const otherMenu = otherParent.querySelector('.dropdown-menu');
                            const otherIcon = otherTrigger.querySelector('.dropdown-icon');
                            if (otherMenu.classList.contains('show')) {
                                otherMenu.classList.remove('show');
                                otherIcon.classList.remove('rotate');
                            }
                        }
                    });
                    
                    // Toggle current dropdown
                    dropdownMenu.classList.toggle('show');
                    if (icon) {
                        icon.classList.toggle('rotate');
                    }
                });
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.has-dropdown')) {
                    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                        menu.classList.remove('show');
                    });
                    document.querySelectorAll('.dropdown-icon.rotate').forEach(icon => {
                        icon.classList.remove('rotate');
                    });
                }
            });
            
            // Real-time password validation
            const newPassword = document.getElementById('new_password');
            
            if (newPassword) {
                newPassword.addEventListener('input', function() {
                    const password = this.value;
                    
                    // Check length
                    const reqLength = document.getElementById('req-length');
                    if (password.length >= 8) {
                        reqLength.className = 'valid';
                        reqLength.innerHTML = '<i class="fas fa-check-circle"></i> At least 8 characters';
                    } else {
                        reqLength.className = 'invalid';
                        reqLength.innerHTML = '<i class="fas fa-times-circle"></i> At least 8 characters';
                    }
                    
                    // Check uppercase
                    const reqUppercase = document.getElementById('req-uppercase');
                    if (/[A-Z]/.test(password)) {
                        reqUppercase.className = 'valid';
                        reqUppercase.innerHTML = '<i class="fas fa-check-circle"></i> At least one uppercase letter';
                    } else {
                        reqUppercase.className = 'invalid';
                        reqUppercase.innerHTML = '<i class="fas fa-times-circle"></i> At least one uppercase letter';
                    }
                    
                    // Check lowercase
                    const reqLowercase = document.getElementById('req-lowercase');
                    if (/[a-z]/.test(password)) {
                        reqLowercase.className = 'valid';
                        reqLowercase.innerHTML = '<i class="fas fa-check-circle"></i> At least one lowercase letter';
                    } else {
                        reqLowercase.className = 'invalid';
                        reqLowercase.innerHTML = '<i class="fas fa-times-circle"></i> At least one lowercase letter';
                    }
                    
                    // Check number
                    const reqNumber = document.getElementById('req-number');
                    if (/[0-9]/.test(password)) {
                        reqNumber.className = 'valid';
                        reqNumber.innerHTML = '<i class="fas fa-check-circle"></i> At least one number';
                    } else {
                        reqNumber.className = 'invalid';
                        reqNumber.innerHTML = '<i class="fas fa-times-circle"></i> At least one number';
                    }
                    
                    // Check special character
                    const reqSpecial = document.getElementById('req-special');
                    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                        reqSpecial.className = 'valid';
                        reqSpecial.innerHTML = '<i class="fas fa-check-circle"></i> At least one special character';
                    } else {
                        reqSpecial.className = 'invalid';
                        reqSpecial.innerHTML = '<i class="fas fa-times-circle"></i> At least one special character';
                    }
                    
                    // Check not default password
                    const reqNotDefault = document.getElementById('req-not-default');
                    if (password !== 'Mlinc1234') {
                        reqNotDefault.className = 'valid';
                        reqNotDefault.innerHTML = '<i class="fas fa-check-circle"></i> Not the default password';
                    } else {
                        reqNotDefault.className = 'invalid';
                        reqNotDefault.innerHTML = '<i class="fas fa-times-circle"></i> Not the default password';
                    }
                });
            }
            
            // Prevent closing modal if default password
            <?php if ($is_default_password): ?>
                const modal = document.getElementById('passwordChangeModal');
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            <?php endif; ?>
        });
    </script>

<?php include '../footer.php'; ?>


</body>
</html>

<?php
$conn->close();
?>