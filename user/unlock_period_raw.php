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
$search_region = isset($_GET['search_region']) ? trim($_GET['search_region']) : '';
$message = '';
$message_type = '';

// Handle Unlock Action - Unlock by ZONE only
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {
        $success_count = 0;
        $already_processed_count = 0;
        $reported_count = 0;
        $total_selected = count($_POST['selected_groups']);
        $action = $_POST['action_type']; // 'unlock'
        
        // Prepare check statement - Check by zone only
        $check_stmt = $conn->prepare("SELECT status, status_void, reported_status FROM fs_raw_data WHERE mainzone <=> ? AND zone <=> ? LIMIT 1");
        $update_stmt = null;

        if ($action === 'unlock') {
            // Unlock action: set status = 'Unlocked', unlock_by, unlock_date for ALL records in the zone
            $new_status = 'Unlocked';
            $update_stmt = $conn->prepare("UPDATE fs_raw_data SET status = ?, unlock_by = ?, unlock_date = ? WHERE mainzone <=> ? AND zone <=> ?");
        }

        if ($update_stmt) {
            foreach ($_POST['selected_groups'] as $group_json) {
                $parts = json_decode($group_json, true);
                if (is_array($parts) && count($parts) === 2) {
                    
                    // Check current status for this zone
                    $current_status = null;
                    $current_status_void = null;
                    $current_reported_status = null;
                    $check_stmt->bind_param("ss", $parts[0], $parts[1]);
                    $check_stmt->execute();
                    $check_stmt->bind_result($current_status, $current_status_void, $current_reported_status);
                    $check_stmt->fetch();
                    $check_stmt->free_result();

                    // Skip if already unlocked
                    if ($action === 'unlock' && $current_status !== 'Locked') {
                        $already_processed_count++;
                        continue;
                    }

                    // Skip if void
                    if ($action === 'unlock' && $current_status_void === 'Void') {
                        $already_processed_count++;
                        continue;
                    }

                    // Skip if reported_status is 'Reported' - CANNOT UNLOCK REPORTED TRANSACTIONS
                    if ($action === 'unlock' && $current_reported_status === 'Reported') {
                        $reported_count++;
                        continue;
                    }

                    $current_datetime = date('Y-m-d H:i:s');
                    
                    // Update ALL records in this zone - 5 parameters for 5 placeholders
                    $update_stmt->bind_param("sssss", 
                        $new_status,           // status
                        $username,              // unlock_by
                        $current_datetime,      // unlock_date
                        $parts[0],              // mainzone
                        $parts[1]               // zone
                    );
                    
                    if ($update_stmt->execute()) {
                        $success_count++;
                    }
                }
            }
            $update_stmt->close();
        }
        
        $check_stmt->close();
        
        // Build appropriate message
        if ($success_count > 0) {
            $message = "$success_count zone(s) unlocked successfully.";
            if ($reported_count > 0) {
                $message .= " $reported_count zone(s) skipped because they are marked as Reported.";
            }
            if ($already_processed_count > 0) {
                $message .= " $already_processed_count zone(s) skipped because they are already unlocked or void.";
            }
            $message_type = "success";
        } elseif ($reported_count == $total_selected) {
            $message = "Cannot unlock zones that are marked as Reported.";
            $message_type = "error";
        } elseif ($already_processed_count == $total_selected) {
            $message = "Zones are already unlocked, void, or not locked. No changes made.";
            $message_type = "error";
        } else {
            $message = "No records updated.";
            $message_type = "error";
        }
    } else {
        $message = "No items selected.";
        $message_type = "error";
    }
}

// Validate date format (should be YYYY-MM)
if ($selected_month && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Optimize Date Filter: Use BETWEEN instead of DATE_FORMAT to utilize indexes
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Fetch filtered data - Group by zone only (not by branch type or uploaded date)
$query = "SELECT 
            mainzone, 
            zone,
            COUNT(*) as record_count,
            GROUP_CONCAT(DISTINCT transaction_type ORDER BY transaction_type SEPARATOR '|') as transaction_types,
            GROUP_CONCAT(DISTINCT DATE(uploaded_date) ORDER BY uploaded_date SEPARATOR '|') as uploaded_dates,
            GROUP_CONCAT(DISTINCT uploaded_by ORDER BY uploaded_by SEPARATOR '|') as uploaded_bys,
            MAX(status) as status,
            MAX(status_void) as status_void,
            MAX(locked_by) as locked_by,
            MAX(locked_date) as locked_date,
            MAX(unlock_by) as unlock_by,
            MAX(unlock_date) as unlock_date,
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date
          FROM fs_raw_data 
          WHERE transaction_month BETWEEN ? AND ?
          GROUP BY mainzone, zone
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
    <title>Unlock Financial Month</title>
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
                <h1 style="font-size: 20px; text-align: center;"> Unlock Financial Month</h1>
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
                        <a href="unlock_period_raw.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px; background-color: #6c757d;"><i class="fa-solid fa-rotate"></i> Clear</a>
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
            $unlocked_count = 0;
            $reported_count = 0;
            $not_reported_count = 0;
            $rows_data = [];
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    $rows_data[] = $row;
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    
                    // Split combined transaction types
                    $types = explode('|', $row['transaction_types']);
                    foreach ($types as $type) {
                        $unique_types[trim($type)] = true;
                    }
                    
                    if ($row['status'] === 'Locked') {
                        $locked_count++;
                    } elseif ($row['status'] === 'Unlocked') {
                        $unlocked_count++;
                    }
                    
                    if ($row['reported_status'] === 'Reported') {
                        $reported_count++;
                    } else {
                        $not_reported_count++;
                    }
                }
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
                    <div class="stat-label">Locked Zones</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo $locked_count; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Unlocked Zones</div>
                    <div class="stat-value" style="color: #28a745;"><?php echo $unlocked_count; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Reported Zones</div>
                    <div class="stat-value" style="color: #155724;"><?php echo $reported_count; ?></div>
                </div>
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <a href="mark_reported.php" class="btn-report" style="text-decoration: none;">
                        <i class="fas fa-check-circle"></i> Mark as Reported
                    </a>

                    <a href="lock_period_raw.php" class="btn-lock" style="text-decoration: none; padding: 10px 20px; background-color: #dc3545; color: white; border-radius: 4px; display: inline-block;">
                        <i class="fas fa-lock"></i> Lock
                    </a>

                    <button type="submit" name="action_type" value="unlock" class="btn-unlock" onclick="return confirmAction('unlock')" >
                        <i class="fas fa-unlock-alt"></i> Unlock
                    </button>
                </div>

                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fa-solid fa-layer-group"></i> Main Zone</th>
                                <th><i class="fa-solid fa-map"></i> Zone</th>
                                <th><i class="fa-solid fa-code-branch"></i> Branch Types</th>
                                <th><i class="fa-solid fa-calendar-days"></i> Uploaded Dates</th>
                                <th><i class="fa-solid fa-database"></i> Record Count</th>
                                <th><i class="fa-solid fa-user"></i> Uploaded By</th>
                                <th><i class="fa-solid fa-circle-check"></i> Status</th>
                                <th><i class="fa-solid fa-list-check"></i> Void Status</th>
                                <th><i class="fa-solid fa-flag"></i> Reported Status</th>
                                <th><i class="fa-solid fa-user-check"></i> Reported By / Date</th>
                                <th><i class="fa-solid fa-unlock-keyhole"></i> Unlocked By / Date</th>
                                <th style="text-align: center;">
                                    <i class="fa-solid fa-gears"></i> Action
                                    <input type="checkbox" id="checkAll" onclick="toggleAll(this)"
                                           style="vertical-align: middle; margin-left: 5px;">
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows_data)): ?>
                                <?php 
                                $current_mainzone = '';
                                foreach ($rows_data as $row): 
                                    $is_reported = ($row['reported_status'] === 'Reported');
                                    $is_locked = ($row['status'] === 'Locked');
                                    $can_unlock = $is_locked && !$is_reported;
                                    
                                    // Display mainzone headers for grouping
                                    $show_mainzone = ($current_mainzone !== $row['mainzone']);
                                    if ($show_mainzone) {
                                        $current_mainzone = $row['mainzone'];
                                    }
                                    
                                    // Split and display branch types
                                    $types = explode('|', $row['transaction_types']);
                                    $type_badges = '';
                                    foreach ($types as $type) {
                                        $type = trim($type);
                                        $type_class = 'badge';
                                        if ($type === 'Branch') {
                                            $type_class .= ' badge-branch';
                                        } elseif ($type === 'Showroom') {
                                            $type_class .= ' badge-showroom';
                                        } elseif ($type === 'Entity') {
                                            $type_class .= ' badge-entity';
                                        } else {
                                            $type_class .= ' badge-secondary';
                                        }
                                        $type_badges .= '<span class="' . $type_class . '">' . htmlspecialchars($type) . '</span> ';
                                    }
                                    
                                    // Split uploaded dates
                                    $uploaded_dates = explode('|', $row['uploaded_dates']);
                                    $dates_display = '';
                                    foreach ($uploaded_dates as $date) {
                                        $dates_display .= date('M d, Y', strtotime(trim($date))) . '<br>';
                                    }
                                    
                                    // Split uploaded by
                                    $uploaded_bys = explode('|', $row['uploaded_bys']);
                                    $bys_display = '';
                                    foreach ($uploaded_bys as $by) {
                                        $bys_display .= htmlspecialchars(trim($by)) . '<br>';
                                    }
                                ?>
                                    <tr class="<?php echo $is_reported ? 'reported-row' : ''; ?>">
                                        <td style="text-align: center;">
                                            <?php if ($show_mainzone): ?>
                                                <span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo $type_badges; ?>
                                        </td>
                                        <td><?php echo $dates_display; ?></td>
                                        <td style="text-align: center;">
                                            <span class="record-count-badge"><?php echo number_format($row['record_count']); ?></span>
                                        </td>
                                        <td><?php echo $bys_display; ?></td>

                                        <td style="text-align: center;">
                                            <?php if ($row['status'] === 'Locked'): ?>
                                                <span class="badge-locked"><i class="fas fa-lock"></i> Locked</span>
                                            <?php elseif ($row['status'] === 'Unlocked'): ?>
                                                <span class="badge-unlocked"><i class="fas fa-unlock"></i> Unlocked</span>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($row['status_void'] === 'Void'): ?>
                                                <span class="badge-void"><i class="fas fa-ban"></i> Void</span>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($row['reported_status'] === 'Reported'): ?>
                                                <span class="badge-reported"><i class="fas fa-check-circle"></i> Reported</span>
                                            <?php else: ?>
                                                <span class="badge-not-reported" style="font-style:italic; font-size: 12px; color:#6c757d"><i class="fas fa-times-circle"></i> Not Reported</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['reported_status_by']) && !empty($row['reported_status_date'])): ?>
                                                <span class="audit-info">
                                                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($row['reported_status_by']); ?><br>
                                                    <small><?php echo date('M d, Y h:i A', strtotime($row['reported_status_date'])); ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="audit-info text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['unlock_by']) && !empty($row['unlock_date'])): ?>
                                                <span class="audit-info">
                                                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($row['unlock_by']); ?><br>
                                                    <small><?php echo date('M d, Y h:i A', strtotime($row['unlock_date'])); ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="audit-info text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($can_unlock): ?>
                                                <input type="checkbox" 
                                                       name="selected_groups[]" 
                                                       class="row-checkbox" 
                                                       value="<?php echo htmlspecialchars(json_encode([
                                                            $row['mainzone'], 
                                                            $row['zone']
                                                        ])); ?>"
                                                       data-status="<?php echo $row['status']; ?>"
                                                       data-void="<?php echo htmlspecialchars($row['status_void'] ?? ''); ?>"
                                                       data-reported="<?php echo htmlspecialchars($row['reported_status'] ?? ''); ?>"
                                                       onchange="updateSelectionCount()">
                                            <?php else: ?>
                                                <span style="color: #6c757d; font-size: 11px;">
                                                    <?php if ($is_reported): ?>
                                                        <i class="fas fa-lock" style="color: #856404;"></i> Reported
                                                    <?php elseif (!$is_locked): ?>
                                                        <i class="fas fa-unlock" style="color: #28a745;"></i> Unlocked
                                                    <?php else: ?>
                                                        <i class="fas fa-ban" style="color: #dc3545;"></i> Void
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="no-data">
                                        <i class="fas fa-inbox"></i>
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
            const total = document.querySelectorAll('.row-checkbox').length;
        }

        // Toggle all checkboxes (only enabled ones)
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.row-checkbox:not(:disabled)');
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
                        const enabledCheckboxes = document.querySelectorAll('.row-checkbox:not(:disabled)');
                        const checkedEnabled = document.querySelectorAll('.row-checkbox:not(:disabled):checked');
                        checkAll.checked = (enabledCheckboxes.length > 0 && checkedEnabled.length === enabledCheckboxes.length);
                        updateSelectionCount();
                    });
                });
            }
            
            updateSelectionCount();
        });

        // Confirm action based on selected items
        function confirmAction(action) {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one zone to ' + action + '.');
                return false;
            }

            // Check for voided items
            let hasVoid = false;
            let hasReported = false;
            
            checkboxes.forEach(cb => {
                if (cb.getAttribute('data-void') === 'Void') {
                    hasVoid = true;
                }
                if (cb.getAttribute('data-reported') === 'Reported') {
                    hasReported = true;
                }
            });

            if (hasVoid && action === 'unlock') {
                alert("These zones are already void and can't be edited (unlock) anymore.");
                return false;
            }

            if (hasReported && action === 'unlock') {
                alert("Cannot unlock zones that are marked as 'Reported'.");
                return false;
            }
            
            return confirm('Are you sure you want to ' + action + ' the selected ' + checkboxes.length + ' zone(s)? This will unlock ALL records in these zones.');
        }
    </script>
    
<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
$conn->close(); 
?>