<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Initialize message variables
$success_message = '';
$error_message = '';

// Handle status update (Approve/Reject)
if (isset($_POST['update_status'])) {
    $request_id = $_POST['request_id'];
    $new_status = $_POST['new_status'];
    $admin_username = $_SESSION['username'];
    
    date_default_timezone_set('Asia/Manila');
    $time_changed = date('Y-m-d H:i:s');
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, get the request details
        $get_request = $conn->prepare("SELECT username, user_type FROM fs_reports.password_reset_requests WHERE id = ?");
        $get_request->bind_param("i", $request_id);
        $get_request->execute();
        $result = $get_request->get_result();
        $request_data = $result->fetch_assoc();
        $get_request->close();
        
        if (!$request_data) {
            throw new Exception("Request not found");
        }
        
        // Update the request status
        $update_stmt = $conn->prepare("UPDATE fs_reports.password_reset_requests SET status = ?, time_changed = ?, admin_in_charge = ? WHERE id = ?");
        $update_stmt->bind_param("sssi", $new_status, $time_changed, $admin_username, $request_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating request status: " . $conn->error);
        }
        
        // If approved, reset the password to 'Mlinc1234'
        if ($new_status === 'Approved') {
            $default_password = password_hash('Mlinc1234', PASSWORD_DEFAULT);
            
            // Update password in qcl_user table
            $update_password = $conn->prepare("UPDATE qcl_user SET password = ? WHERE username = ?");
            $update_password->bind_param("ss", $default_password, $request_data['username']);
            
            if (!$update_password->execute()) {
                throw new Exception("Error resetting password: " . $conn->error);
            }
            
            if ($update_password->affected_rows === 0) {
                throw new Exception("Username '{$request_data['username']}' not found in users table");
            }
            $update_password->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Set session message for PRG pattern
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Request #$request_id has been " . strtolower($new_status) . "ed successfully." . 
                     ($new_status === 'Approved' ? " Password has been reset to 'Mlinc1234'." : "")
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Error: " . $e->getMessage()
        ];
    }
    
    if (isset($update_stmt)) $update_stmt->close();
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle delete request
if (isset($_POST['delete_request'])) {
    $request_id = $_POST['request_id'];
    
    $delete_stmt = $conn->prepare("DELETE FROM fs_reports.password_reset_requests WHERE id = ?");
    $delete_stmt->bind_param("i", $request_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Request #$request_id has been deleted successfully."
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Error deleting request: " . $conn->error
        ];
    }
    $delete_stmt->close();
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Check for flash messages
if (isset($_SESSION['flash_message'])) {
    $success_message = $_SESSION['flash_message']['type'] === 'success' ? $_SESSION['flash_message']['text'] : '';
    $error_message = $_SESSION['flash_message']['type'] === 'error' ? $_SESSION['flash_message']['text'] : '';
    // Clear the flash message
    unset($_SESSION['flash_message']);
}

// Fetch all password reset requests
$requests = [];
$stmt = $conn->prepare("SELECT * FROM fs_reports.password_reset_requests ORDER BY 
                        CASE status 
                            WHEN 'Pending' THEN 1 
                            WHEN 'Approved' THEN 2 
                            WHEN 'Rejected' THEN 3 
                        END, request_time DESC");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

// Get statistics
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total' => count($requests)
];

foreach ($requests as $req) {
    $stats[strtolower($req['status'])]++;
}

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Requests</title>
    <link rel="stylesheet" href="css/password_reset.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
   
</head>
<body>
    <div class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>
        
        <div class="content-wrapper">
        <!-- Header -->
        <div class="header">
            <h1>
                Password Reset Requests Management
                <span>Admin Panel - <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </h1>
            <div class="nav-links">
                <a href="user_dashboard.php" class="nav-link"><i class="fa-solid fa-gauge"></i> Dashboard</a>
                <a href="password_reset_requests.php" class="nav-link active"><i class="fa-solid fa-user-lock"></i> Reset Requests</a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card pending">
                <div class="stat-info">
                    <h3>Pending Requests</h3>
                    <div class="stat-number"><?php echo $stats['pending']; ?></div>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-hourglass-half" style="color: #d98900;"></i></div>
            </div>
            <div class="stat-card approved">
                <div class="stat-info">
                    <h3>Approved</h3>
                    <div class="stat-number"><?php echo $stats['approved']; ?></div>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-circle-check"  style="color: #00991c;"></i></div>
            </div>
            <div class="stat-card rejected">
                <div class="stat-info">
                    <h3>Rejected</h3>
                    <div class="stat-number"><?php echo $stats['rejected']; ?></div>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-xmark" style="color: #990000;"></i></div>
            </div>
            <div class="stat-card total">
                <div class="stat-info">
                    <h3>Total Requests</h3>
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-list-check" style="color: #004f99;"></i></div>
            </div>
        </div>

        <!-- Messages - Now using flash messages from session -->
        <?php if (!empty($success_message)): ?>
        <div class="message success-message" id="success-message">
            <span><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?></span>
            <button class="close-btn" onclick="closeMessage('success-message')">&times;</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="message error-message" id="error-message">
            <span><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_message); ?></span>
            <button class="close-btn" onclick="closeMessage('error-message')">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-section">
            <div class="table-header">
                <h2><i class="fa-solid fa-key"></i> All Password Reset Requests</h2>
            </div>

            <div class="table-wrapper">
                <?php if (empty($requests)): ?>
                    <div class="no-data">
                        <i class="fa-solid fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.3;"></i>
                        <p>No password reset requests found.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <!-- <th>ID</th> -->
                                <th>Username</th>
                                <th>User Type</th>
                                <th>Request Time</th>
                                <th>Status</th>
                                <th>Time Changed</th>
                                <th>Admin In Charge</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                            <tr>
                                <!-- <td>#<?php echo $request['id']; ?></td> -->
                                <td><strong><?php echo htmlspecialchars($request['username']); ?></strong></td>
                                <td>
                                    <span class="admin-badge">
                                        <?php echo htmlspecialchars($request['user_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($request['request_time'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($request['status']); ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if ($request['time_changed']) {
                                        echo date('M d, Y h:i A', strtotime($request['time_changed']));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($request['admin_in_charge']) {
                                        echo htmlspecialchars($request['admin_in_charge']);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($request['status'] == 'Pending'): ?>
                                        <form method="POST" style="display: inline;" class="action-form" onsubmit="return confirmApprove('<?php echo htmlspecialchars($request['username']); ?>', this);">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            <input type="hidden" name="new_status" value="Approved">
                                            <button type="submit" name="update_status" class="btn btn-approve">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" class="action-form" onsubmit="return confirmReject('<?php echo htmlspecialchars($request['username']); ?>', this);">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            <input type="hidden" name="new_status" value="Rejected">
                                            <button type="submit" name="update_status" class="btn btn-reject">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <button class="btn btn-approve" disabled title="Request already processed"><i class="fa-solid fa-check"></i> Approve</button>
                                        <button class="btn btn-reject" disabled title="Request already processed"><i class="fa-solid fa-xmark"></i> Reject</button>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" class="action-form" onsubmit="return confirmDelete(<?php echo $request['id']; ?>, this);">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            <button type="submit" name="delete_request" class="btn btn-delete">
                                                <i class="fa-solid fa-trash-can"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        </div>

        </div>

    <script>
        // Function to close message
        function closeMessage(elementId) {
            const message = document.getElementById(elementId);
            if (message) {
                message.style.display = 'none';
            }
        }

        // Confirmation functions
        function confirmApprove(username, form) {
            return confirm('Approve this password reset request?\n\nThis will reset the password for user \'' + username + '\' to default password (Mlinc1234).');
        }

        function confirmReject(username, form) {
            return confirm('Reject this password reset request for user \'' + username + '\'?');
        }

        function confirmDelete(requestId, form) {
            return confirm('Are you sure you want to delete request #' + requestId + '?\n\nThis action cannot be undone.');
        }

        // Auto-hide messages after 3 seconds with fade out effect
        setTimeout(function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                message.style.transition = 'opacity 0.5s ease';
                message.style.opacity = '0';
                setTimeout(function() {
                    message.style.display = 'none';
                }, 500);
            });
        }, 3000);

        // Simple form submission handling - removed complex loading states that might interfere
        document.addEventListener('DOMContentLoaded', function() {
            // Reset any stuck loading states
            document.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = false;
                button.classList.remove('loading');
            });
        });

        // Handle browser back/forward cache
        window.addEventListener('pageshow', function(event) {
            // Re-enable buttons when page is shown from bfcache
            if (event.persisted) {
                document.querySelectorAll('button[type="submit"]').forEach(button => {
                    button.disabled = false;
                    button.classList.remove('loading');
                });
            }
        });

        // Debug: Log when forms are submitted
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                console.log('Form submitted:', this);
                console.log('Form action:', this.action);
                console.log('Form method:', this.method);
                console.log('Form data:', new FormData(this));
            });
        });
    </script>

<?php include '../footer.php'; ?>

</body>
</html>