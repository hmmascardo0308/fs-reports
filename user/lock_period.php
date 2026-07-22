<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Session Management
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Get filter parameters
$selected_month = isset($_GET['transaction_month']) ? $_GET['transaction_month'] : date('Y-m');

// Validate date format (should be YYYY-MM)
if ($selected_month && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Optimize Date Filter: Use BETWEEN instead of DATE_FORMAT to utilize indexes
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

$message = '';
$message_type = '';

// Handle lock Action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {
        $success_count = 0;
        $action = $_POST['action_type']; // 'lock'

        $update_stmt = null;
        $new_status = '';

        if ($action === 'lock') {
            // Lock action: set status = 'Locked', locked_by, locked_date
            $new_status = 'Locked';
            $update_stmt = $conn->prepare("UPDATE comparative_report SET status = ?, locked_by = ?, locked_date = ? WHERE mainzone <=> ? AND zone <=> ? AND transaction_type <=> ? AND transaction_month BETWEEN ? AND ?");
        }

        if ($update_stmt) {
            foreach ($_POST['selected_groups'] as $group_json) {
                $parts = json_decode($group_json, true);
                if (is_array($parts) && count($parts) === 3) {
                    
                    $current_datetime = date('Y-m-d H:i:s');
                    
                    $update_stmt->bind_param("ssssssss", 
                        $new_status,           // status
                        $username,              // by
                        $current_datetime,      // date
                        $parts[0],              // mainzone
                        $parts[1],              // zone
                        $parts[2],              // transaction_type
                        $start_date,
                        $end_date
                    );
                    
                    if ($update_stmt->execute()) {
                        $success_count++;
                    }
                }
            }
            $update_stmt->close();
        }

        if ($success_count > 0) {
            $message = "$success_count group(s) locked successfully.";
            $message_type = "success";
        } else {
            $message = "No records updated.";
            $message_type = "error";
        }
    } else {
        $message = "No items selected.";
        $message_type = "error";
    }
}

// Fetch filtered data with lock information
$query = "SELECT 
            mainzone, 
            zone, 
            transaction_type, 
            MAX(uploaded_date) as uploaded_date,
            COUNT(*) as record_count,
            COUNT(DISTINCT CASE WHEN status = 'Locked' THEN region END) as locked_region_count,
            COUNT(DISTINCT CASE WHEN status = 'Unlocked' THEN region END) as unlocked_region_count,
            COUNT(DISTINCT CASE WHEN status IS NULL OR status NOT IN ('Locked', 'Unlocked') THEN region END) as no_status_region_count,
            COUNT(DISTINCT CASE WHEN status_void = 'Void' THEN region END) as void_region_count,
            MAX(locked_by) as locked_by,
            MAX(locked_date) as locked_date
          FROM comparative_report 
          WHERE transaction_month BETWEEN ? AND ?
          GROUP BY mainzone, zone, transaction_type
          ORDER BY mainzone, zone";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lock Financial Month</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/lock_unlock.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
</head>
<body>

    <?php if (!empty($message)): ?>
        <div id="statusModal" class="modal-overlay">
            <div class="modal-box <?php echo $message_type; ?>">
                <div class="modal-icon">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                </div>
                <div class="modal-message"><?php echo htmlspecialchars($message); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge" style="font-weight: bold;">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-header">
                <h1 style="font-size: 20px; text-align: center;"> Lock Financial Month</h1>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label for="transaction_month"><i class="fas fa-calendar"></i> Transaction Month</label>
                        <input 
                            type="month" 
                            name="transaction_month" 
                            id="transaction_month" 
                            value="<?php echo htmlspecialchars($selected_month); ?>"
                            required
                            max="<?php echo date('Y-m'); ?>"
                        >
                    </div>
                    
                    <div class="filter-group action-buttons">
                        <button type="submit"><i class="fas fa-filter"></i> Apply Filter</button>
                        <a href="lock_period.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <?php 
            $total_records = 0;
            $unique_mainzones = [];
            $unique_zones = [];
            $unique_types = [];
            $locked_count = 0;
            $pending_count = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0); // Reset pointer
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    $unique_types[$row['transaction_type']] = true;
                    
                    if ($row['unlocked_region_count'] == 0 && $row['no_status_region_count'] == 0 && $row['locked_region_count'] > 0) {
                        $locked_count++;
                    } else {
                        $pending_count++;
                    }
                }
                $result->data_seek(0); // Reset pointer again for display
            }
            ?>
            
            <div class="summary-stats">
                <div class="stat-item">
                    <div class="stat-label">Total Records</div>
                    <div class="stat-value"><?php echo number_format($total_records); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Main Zones</div>
                    <div class="stat-value"><?php echo count($unique_mainzones); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Zones</div>
                    <div class="stat-value"><?php echo count($unique_zones); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Branch Types</div>
                    <div class="stat-value"><?php echo count($unique_types); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Locked Groups</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo $locked_count; ?></div>
                </div>
                <!-- <div class="stat-item">
                    <div class="stat-label">No Status Groups</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $pending_count; ?></div>
                </div> -->
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <!-- <span id="selectedCount" class="selection-count">0 items selected</span> -->
                    <button type="submit" name="action_type" value="lock" class="btn-lock" onclick="return confirmLockAction('lock')">
                        <i class="fas fa-lock"></i> Lock
                    </button>
                    <a href="unlock_period.php" class="btn-unlock" style="text-decoration: none;"><i class="fas fa-unlock-alt"></i> Unlock</a>
                </div>

                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                               <th><i class="fa-solid fa-sitemap"></i> Main Zone</th>
<th><i class="fa-solid fa-map-location-dot"></i> Zone</th>
<th><i class="fa-solid fa-building"></i> Branch Type</th>
<th><i class="fa-solid fa-upload"></i> Uploaded Date</th>
<th><i class="fa-solid fa-table-list"></i> Record Count</th>
<th><i class="fa-solid fa-circle-check"></i> Status</th>
<th><i class="fa-solid fa-clipboard-check"></i> Additional Status</th>
<th><i class="fa-solid fa-lock"></i> Locked By / Date</th>
<th style="text-align: center;">
    <i class="fa-solid fa-gears"></i> Action
    <input type="checkbox" id="checkAll" onclick="toggleAll(this)"
           style="vertical-align: middle; margin-left: 5px;">
</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td style="text-align: center;"><span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['transaction_type'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('F d, Y h:i:s A', strtotime($row['uploaded_date'])); ?></td>
                                        <td style="text-align: center;"><strong><?php echo number_format($row['record_count']); ?></strong></td>
                                        <td style="text-align: left; padding-left: 15px;">
                                            <?php
                                            $status_parts = [];
                                            if ($row['locked_region_count'] > 0) {
                                                $status_parts[] = '<span class="badge-locked" style="display: inline-block; margin-bottom: 2px;"><i class="fas fa-lock"></i> Locked Regions: ' . $row['locked_region_count'] . '</span>';
                                            }
                                            if ($row['unlocked_region_count'] > 0) {
                                                $status_parts[] = '<span class="badge-unlocked" style="display: inline-block; margin-bottom: 2px;"><i class="fas fa-unlock"></i> Unlocked Regions: ' . $row['unlocked_region_count'] . '</span>';
                                            }
                                            // if ($row['no_status_region_count'] > 0) {
                                            //     $status_parts[] = '<span class="badge-nostatus" style="display: inline-block; margin-bottom: 2px;"><i class="fas fa-question-circle"></i> No Status: ' . $row['no_status_region_count'] . '</span>';
                                            // }

                                            if (empty($status_parts)) {
                                                echo '<span>-</span>';
                                            } else {
                                                echo implode('<br>', $status_parts);
                                            }
                                            ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php 
                                            if ($row['void_region_count'] > 0) {
                                                echo '<span class="badge-void" style="color: red; font-weight: bold;">Void Regions: ' . $row['void_region_count'] . '</span>';
                                            } else {
                                                echo '<span>-</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['locked_by']) && !empty($row['locked_date'])): ?>
                                                <span class="audit-info">
                                                    <i class="fas fa-user-lock"></i> <?php echo htmlspecialchars($row['locked_by']); ?><br>
                                                    <small><?php echo date('M d, Y h:i A', strtotime($row['locked_date'])); ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="audit-info text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <input type="checkbox" 
                                                   name="selected_groups[]" 
                                                   class="row-checkbox" 
                                                   value="<?php echo htmlspecialchars(json_encode([
                                                        $row['mainzone'], 
                                                        $row['zone'], 
                                                        $row['transaction_type']
                                                    ])); ?>"
                                                   onchange="updateSelectionCount()">
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="no-data">
                                        <i class="fas fa-inbox" style="font-size: 48px; color: #dee2e6; margin-bottom: 10px;"></i>
                                        <br>
                                        No uploaded reports found for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </main>


    <script>
        // Auto-hide modal after 3 seconds and redirect
        <?php if (!empty($message)): ?>
        setTimeout(function() {
            const modal = document.getElementById('statusModal');
            if (modal) {
                modal.style.transition = 'opacity 0.5s ease';
                modal.style.opacity = '0';
                setTimeout(() => {
                    window.location.href = window.location.pathname + window.location.search;
                }, 500);
            }
        }, 3000);
        <?php endif; ?>
        
        // Month validation
        document.getElementById('transaction_month').addEventListener('change', function() {
            const selectedDate = new Date(this.value + '-01');
            const today = new Date();
            if (selectedDate > today) {
                alert('Please select a month that is not in the future.');
                this.value = '<?php echo date('Y-m'); ?>';
            }
        });

        // Update selection count
        function updateSelectionCount() {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count + ' item(s) selected';
        }

        // Toggle all checkboxes
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            updateSelectionCount();
        }

        // Update "Check All" checkbox when individual row checkboxes change
        document.addEventListener('DOMContentLoaded', function() {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const checkAll = document.getElementById('checkAll');

            if (rowCheckboxes.length > 0 && checkAll) {
                rowCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(rowCheckboxes).every(cb => cb.checked);
                        checkAll.checked = allChecked;
                        updateSelectionCount();
                    });
                });
            }
            
            // Initialize selection count
            updateSelectionCount();
        });

        // Confirm action based on selected items
        function confirmLockAction(action) {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one item to ' + action + '.');
                return false;
            }
            
            return confirm('Are you sure you want to lock the selected ' + checkboxes.length + ' group(s)?');
        }
    </script>
<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
$conn->close(); 
?>