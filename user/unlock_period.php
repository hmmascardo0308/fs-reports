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

// Handle Unlock Action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {
        $success_count = 0;
        $already_processed_count = 0;
        $total_selected = count($_POST['selected_groups']);
        $action = $_POST['action_type']; // 'unlock'
        
        // Prepare check statement
        $check_stmt = $conn->prepare("SELECT status, status_void FROM comparative_report WHERE region <=> ? AND mainzone <=> ? AND zone <=> ? AND transaction_type <=> ? AND uploaded_date <=> ? LIMIT 1");
        $update_stmt = null;

        if ($action === 'unlock') {
            // Unlock action: set status = 'Unlocked', unlock_by, unlock_date
            $new_status = 'Unlocked';
            $update_stmt = $conn->prepare("UPDATE comparative_report SET status = ?, unlock_by = ?, unlock_date = ? WHERE region <=> ? AND mainzone <=> ? AND zone <=> ? AND transaction_type <=> ? AND uploaded_date <=> ?");
        }

        if ($update_stmt) {
            foreach ($_POST['selected_groups'] as $group_json) {
                $parts = json_decode($group_json, true);
                if (is_array($parts) && count($parts) === 5) {
                    
                    // Check current status
                    $current_status = null;
                    $current_status_void = null;
                    $check_stmt->bind_param("sssss", $parts[0], $parts[1], $parts[2], $parts[3], $parts[4]);
                    $check_stmt->execute();
                    $check_stmt->bind_result($current_status, $current_status_void);
                    $check_stmt->fetch();
                    $check_stmt->free_result();

                    if ($action === 'unlock' && $current_status !== 'Locked') {
                        $already_processed_count++;
                        continue;
                    }

                    if ($action === 'unlock' && $current_status_void === 'Void') {
                        $already_processed_count++;
                        continue;
                    }

                    $current_datetime = date('Y-m-d H:i:s');
                    
                    $update_stmt->bind_param("ssssssss", 
                        $new_status,           // status
                        $username,              // by
                        $current_datetime,      // date
                        $parts[0],              // region
                        $parts[1],              // mainzone
                        $parts[2],              // zone
                        $parts[3],              // transaction_type
                        $parts[4]               // uploaded_date
                    );
                    
                    if ($update_stmt->execute()) {
                        $success_count++;
                    }
                }
            }
            $update_stmt->close();
        }
        
        $check_stmt->close();
        
        if ($success_count > 0) {
            $message = "$success_count group(s) unlocked successfully.";
            $message_type = "success";
        } elseif ($already_processed_count == $total_selected) {
            $message = "Transactions are already unlocked or have no status. No changes made.";
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

// Fetch filtered data with lock/unlock information
$query = "SELECT DISTINCT 
            region,
            mainzone, 
            zone, 
            transaction_type,
            uploaded_by, 
            uploaded_date,
            COUNT(*) as record_count,
            MAX(status) as status,
            MAX(status_void) as status_void,
            MAX(locked_by) as locked_by,
            MAX(locked_date) as locked_date,
            MAX(unlock_by) as unlock_by,
            MAX(unlock_date) as unlock_date
          FROM comparative_report 
          WHERE transaction_month BETWEEN ? AND ?";

if (!empty($search_region)) {
    $query .= " AND region LIKE ?";
    $query .= " GROUP BY region, mainzone, zone, transaction_type, uploaded_by, uploaded_date ORDER BY mainzone, zone, region,uploaded_by, uploaded_date";
    $stmt = $conn->prepare($query);
    $search_param = "%" . $search_region . "%";
    $stmt->bind_param("sss", $start_date, $end_date, $search_param);
} else {
    $query .= " GROUP BY region, mainzone, zone, transaction_type, uploaded_by,uploaded_date ORDER BY mainzone, zone, region,uploaded_by, uploaded_date";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $start_date, $end_date);
}

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
    
    <style>
        .badge-branch {
            background-color: #e0f2fe;
            color: #003450;
            border: 1px solid #00456b;
        }
        .badge-showroom {
            background-color: #fef3c7;
            color: #702b00;
            border: 1px solid #6c5600;
        }
    </style>
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
                    
                    <div class="filter-group">
                        <label for="search_region"><i class="fas fa-search"></i> Search Region</label>
                        <input 
                            type="text" 
                            name="search_region" 
                            id="search_region" 
                            value="<?php echo htmlspecialchars($search_region); ?>" 
                            placeholder="All Region..."
                        >
                    </div>

                    <div class="filter-group action-buttons">
                        <button type="submit"><i class="fas fa-filter"></i> Apply Filter</button>
                        <a href="unlock_period.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <?php 
            $total_records = 0;
            $unique_regions = [];
            $unique_mainzones = [];
            $unique_zones = [];
            $unique_types = [];
            $locked_count = 0;
            $unlocked_count = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0); // Reset pointer
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_regions[$row['region']] = true;
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    $unique_types[$row['transaction_type']] = true;
                    
                    if ($row['status'] === 'Locked') {
                        $locked_count++;
                    } elseif ($row['status'] === 'Unlocked') {
                        $unlocked_count++;
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
                    <div class="stat-label">Regions</div>
                    <div class="stat-value"><?php echo count($unique_regions); ?></div>
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
                <div class="stat-item">
                    <div class="stat-label">Unlocked Groups</div>
                    <div class="stat-value" style="color: #28a745;"><?php echo $unlocked_count; ?></div>
                </div>
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <a href="lock_period.php" class="btn-lock" style="text-decoration: none;"><i class="fas fa-lock"></i> Lock</a>

                    <button type="submit" name="action_type" value="unlock" class="btn-unlock" onclick="return confirmAction('unlock')">
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
<th><i class="fa-solid fa-earth-asia"></i> Region</th>
<th><i class="fa-solid fa-code-branch"></i> Branch Type</th>
<th><i class="fa-solid fa-calendar-days"></i> Uploaded Date</th>
<th><i class="fa-solid fa-database"></i> Record Count</th>
<th><i class="fa-solid fa-user"></i> Uploaded By</th>
<th><i class="fa-solid fa-circle-check"></i> Status</th>
<th><i class="fa-solid fa-list-check"></i> Additional Status</th>
<th><i class="fa-solid fa-unlock-keyhole"></i> Unlocked By / Date</th>
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
                                        <td><?php echo htmlspecialchars($row['region'] ?: 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            $type = $row['transaction_type'] ?? '';
                                            $type_class = ($type === 'Branch') ? 'badge-branch' : (($type === 'Showroom') ? 'badge-showroom' : '');
                                            ?>
                                            <span class="badge <?= $type_class ?>"><?= htmlspecialchars($type ?: 'N/A') ?></span>
                                        </td>
                                        <td><?php echo date('F d, Y h:i:s A', strtotime($row['uploaded_date'])); ?></td>
                                        <td style="text-align: center;"><strong><?php echo number_format($row['record_count']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['uploaded_by'] ?: 'N/A'); ?></td>

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
                                                <span class="badge-void" style="color: red; font-weight: bold;">Void</span>
                                            <?php else: ?>
                                                <span>-</span>
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
                                            <input type="checkbox" 
                                                   name="selected_groups[]" 
                                                   class="row-checkbox" 
                                                   value="<?php echo htmlspecialchars(json_encode([
                                                        $row['region'],
                                                        $row['mainzone'], 
                                                        $row['zone'], 
                                                        $row['transaction_type'], 
                                                        $row['uploaded_date']
                                                    ])); ?>"
                                                   data-status="<?php echo $row['status']; ?>"
                                                   data-void="<?php echo htmlspecialchars($row['status_void'] ?? ''); ?>"
                                                   onchange="updateSelectionCount()">
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="no-data">
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
            // This function can be expanded if a selection count display is added
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
            
            updateSelectionCount();
        });

        // Confirm action based on selected items
        function confirmAction(action) {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one item to ' + action + '.');
                return false;
            }

            // Check for voided items
            let hasVoid = false;
            checkboxes.forEach(cb => {
                if (cb.getAttribute('data-void') === 'Void') {
                    hasVoid = true;
                }
            });

            if (hasVoid && action === 'unlock') {
                alert("These transactions are already void and can't be edited (unlock) anymore.");
                return false;
            }
            
            return confirm('Are you sure you want to ' + action + ' the selected ' + checkboxes.length + ' group(s)?');
        }
    </script>
<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
$conn->close(); 
?>