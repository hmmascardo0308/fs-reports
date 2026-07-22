<?php
session_start();
require_once __DIR__ . '/config/config.php';

// 1. If already logged in, redirect based on type
if (isset($_SESSION['username'])) {
    if ($_SESSION['user_type'] === 'admin') {
        header("Location: user/user_dashboard.php");
    } else {
        header("Location: user/user_dashboard.php");
    }
    exit;
}

// 2. Handle login form submission
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $errors = [];

    if (!$username) $errors[] = "Username is required.";
    if (!$password) $errors[] = "Password is required.";

    if (empty($errors)) {
        // Initialize variables to prevent "Undefined variable" notices from static analysis
        $id = null;
        $id_number = null;
        $full_name = null;
        $user_type = null;
        $db_password = null;
        $stmt = $conn->prepare("SELECT id, id_number, full_name, user_type, password FROM fs_reports.qcl_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $id_number, $full_name, $user_type, $db_password);
            $stmt->fetch();
            
            // Ensure $db_password is not null before verification
            if ($db_password !== null && password_verify($password, $db_password)) {
                // Set session variables
                $_SESSION['user_id'] = $id;
                $_SESSION['id_number'] = $id_number;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $full_name;
                $_SESSION['user_type'] = $user_type;
                
                // Set flag for sign-in success animation
                $_SESSION['just_logged_in'] = true;

                date_default_timezone_set('Asia/Manila');
                $now = date('Y-m-d H:i:s');
                $update = $conn->prepare("UPDATE fs_reports.qcl_user SET last_online = ? WHERE id = ?");
                $update->bind_param("si", $now, $id);
                $update->execute();
                $update->close();

                // Successful Login Redirect
                if ($user_type === 'admin') {
                    header("Location: user/user_dashboard.php");
                } else {
                    // All other user types go here
                    header("Location: user/user_dashboard.php");
                }
                exit;
            } else {
                $errors[] = "Incorrect password.";
            }
        } else {
            $errors[] = "Username not found.";
        }
        $stmt->close();
    }
}

// 3. Handle password reset request
if (isset($_POST['reset_password'])) {
    $reset_username = trim($_POST['reset_username']);
    $reset_errors = [];
    $reset_success = "";
    $show_modal = true; // Track whether to show modal
    $user_type = null; // Initialize user_type for this block

    if (!$reset_username) {
        $reset_errors[] = "Username is required.";
        $show_modal = true; // Keep modal open
    } else {
        // Check if username exists in qcl_user table
        $check_stmt = $conn->prepare("SELECT user_type FROM fs_reports.qcl_user WHERE username = ?");
        $check_stmt->bind_param("s", $reset_username);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows === 1) {
            $check_stmt->bind_result($user_type);
            $check_stmt->fetch();
            
            // Check if there's already a pending request for this username
            $pending_check = $conn->prepare("SELECT id FROM fs_reports.password_reset_requests WHERE username = ? AND status = 'Pending'");
            $pending_check->bind_param("s", $reset_username);
            $pending_check->execute();
            $pending_check->store_result();
            
            if ($pending_check->num_rows > 0) {
                $reset_errors[] = "There is already a pending password reset request for this username. Please wait for administrator approval.";
                $show_modal = true; // Keep modal open
            } else {
                // Insert into password_reset_requests table
                date_default_timezone_set('Asia/Manila');
                $request_time = date('Y-m-d H:i:s');
                $status = 'Pending';
                
                $insert_stmt = $conn->prepare("INSERT INTO fs_reports.password_reset_requests (username, user_type, request_time, status, time_changed, admin_in_charge) VALUES (?, ?, ?, ?, NULL, NULL)");
                $insert_stmt->bind_param("ssss", $reset_username, $user_type, $request_time, $status);
                
                if ($insert_stmt->execute()) {
                    $reset_success = "Password reset request submitted successfully! An administrator will review your request shortly. Please contact administrator for follow-up.";
                    
                    // Set session variable to indicate successful reset
                    $_SESSION['reset_success'] = true;
                    $_SESSION['reset_message'] = $reset_success;
                    
                    // Don't show modal on page refresh
                    $show_modal = false;
                    
                    // Redirect to clear POST data
                    header("Location: " . $_SERVER['PHP_SELF'] . "?reset_success=1");
                    exit;
                } else {
                    $reset_errors[] = "Error submitting request. Please try again.";
                    $show_modal = true; // Keep modal open
                }
                $insert_stmt->close();
            }
            $pending_check->close();
        } else {
            $reset_errors[] = "Username not found in our records. Please check your username and try again.";
            $show_modal = true; // Keep modal open to show error
        }
        $check_stmt->close();
    }
    
    // Store errors in session to persist after potential redirect
    if (!empty($reset_errors)) {
        $_SESSION['reset_errors'] = $reset_errors;
        $_SESSION['show_modal'] = $show_modal;
    }
}

// Check for session messages
$reset_success = '';
$reset_errors = [];
$show_modal = false;

if (isset($_SESSION['reset_success']) && $_SESSION['reset_success']) {
    $reset_success = $_SESSION['reset_message'];
    unset($_SESSION['reset_success']);
    unset($_SESSION['reset_message']);
}

if (isset($_SESSION['reset_errors']) && !empty($_SESSION['reset_errors'])) {
    $reset_errors = $_SESSION['reset_errors'];
    $show_modal = isset($_SESSION['show_modal']) ? $_SESSION['show_modal'] : true;
    unset($_SESSION['reset_errors']);
    unset($_SESSION['show_modal']);
}

// Check for URL parameter for success message
if (isset($_GET['reset_success']) && $_GET['reset_success'] == 1) {
    $reset_success = "Password reset request submitted successfully! An administrator will review your request shortly. Please contact administrator for follow-up.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Financial Statement Consolidator System</title>
    <link rel="stylesheet" href="login.css?v=<?= time(); ?>">
    <link rel="icon" href="images/MLW%20Logo.png" type="image/png"/>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="logo-container">
                <img src="images/MLW%20Logo.png" alt="QCL Logo" class="logo">
            </div>
            <h2>Financial Statement Consolidator System</h2>
            <p class="subtitle">Please login to your account.</p>

            <?php
            if (!empty($errors)) {
                echo "<div class='message error-msg'>";
                echo "<i class='fas fa-exclamation-circle'></i>";
                echo "<div>";
                foreach ($errors as $e) echo "<p>$e</p>";
                echo "</div>";
                echo "</div>";
            }
            if (isset($_SESSION['success'])) {
                echo "<div class='message success-msg'>" . $_SESSION['success'] . "</div>";
                unset($_SESSION['success']);
            }
            ?>

            <form method="post" class="login-form">
                <div class="input-group">
                    <label for="username">
                        <i class="fas fa-user"></i>
                        <span>Username</span>
                    </label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>

                <div class="input-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        <span>Password</span>
                    </label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                        <span class="toggle-password" id="togglePassword">
                            <i class="fa-solid fa-eye"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" name="login" class="login-btn">
                    <span>Login</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="forgot-password-section">
                <p class="forgot-password-link" onclick="openModal()">
                    <i class="fas fa-key"></i>
                    Forgot your password?
                </p>
                <p class="contact-admin">
                    <i class="fas fa-envelope"></i>
                    Don't have an account? Please contact your administrator
                </p>
            </div>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Reset Password</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p class="modal-info"><i class="fas fa-info-circle"></i> Enter your username to request a password reset. An administrator will review your request and assist you.</p>
                
                <?php if (!empty($reset_errors)): ?>
                    <div class='message error-msg'>
                        <i class='fas fa-exclamation-circle'></i>
                        <div>
                            <?php foreach ($reset_errors as $e): ?>
                                <p><?php echo htmlspecialchars($e); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="post" class="reset-form" id="resetForm">
                    <div class="input-group">
                        <label for="reset_username">
                            <i class="fas fa-user"></i>
                            <span>Username</span>
                        </label>
                        <input type="text" id="reset_username" name="reset_username" placeholder="Enter your username" 
                               value="<?php echo isset($_POST['reset_username']) ? htmlspecialchars($_POST['reset_username']) : ''; ?>" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn" onclick="cancelReset()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                        <button type="submit" name="reset_password" class="submit-btn"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast notification for success message -->
    <?php if ($reset_success): ?>
    <div id="toastSuccess" class="toast-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($reset_success); ?></span>
    </div>
    <?php endif; ?>

    <script>
        // Password visibility toggle
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');
        const modal = document.getElementById('resetModal');
        const toastSuccess = document.getElementById('toastSuccess');

        if (togglePassword) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Change the icon based on password visibility
                const icon = this.querySelector('i');
                if (type === 'password') {
                    icon.className = 'fa-solid fa-eye';
                } else {
                    icon.className = 'fa-solid fa-eye-slash';
                }
            });
        }

        function openModal() {
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden'; // Prevent scrolling
                // Clear any existing error messages when manually opening
                const errorDiv = modal.querySelector('.error-msg');
                if (errorDiv && !<?php echo !empty($reset_errors) ? 'true' : 'false'; ?>) {
                    errorDiv.remove();
                }
            }
        }

        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto'; // Restore scrolling
                // Clear the form when closing
                const resetForm = document.getElementById('resetForm');
                if (resetForm) {
                    resetForm.reset();
                }
                // Remove error messages when closing
                const errorDiv = modal.querySelector('.error-msg');
                if (errorDiv) {
                    errorDiv.remove();
                }
            }
        }

        function cancelReset() {
            closeModal();
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }

        // Auto-hide toast notification after 5 seconds
        if (toastSuccess) {
            setTimeout(function() {
                toastSuccess.style.animation = 'slideOut 0.3s ease-in-out forwards';
                setTimeout(function() {
                    if (toastSuccess && toastSuccess.remove) {
                        toastSuccess.remove();
                    }
                }, 300);
            }, 5000);
        }

        // Add slideOut animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Open modal if there are errors
        <?php if ($show_modal && !empty($reset_errors)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openModal();
        });
        <?php endif; ?>

        // Form validation before submit
        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function(e) {
                const usernameInput = document.getElementById('reset_username');
                if (usernameInput && !usernameInput.value.trim()) {
                    e.preventDefault();
                    alert('Please enter your username.');
                    return false;
                }
                return true;
            });
        }

        // Clear form on modal close - ensure this runs
        const closeSpan = document.querySelector('.close');
        if (closeSpan) {
            closeSpan.onclick = function() {
                closeModal();
            };
        }
    </script>
</body>
</html>