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

$username = $_SESSION['username'] ?? "unknown";
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

// Handle Report Action - Mark ALL zones for the month
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    if ($_POST['action_type'] === 'report') {
        // Get all mainzone and zone combinations for the selected month
        $fetch_query = "SELECT DISTINCT mainzone, zone 
                        FROM fs_raw_data 
                        WHERE transaction_month BETWEEN ? AND ?";
        $fetch_stmt = $conn->prepare($fetch_query);
        $fetch_stmt->bind_param("ss", $start_date, $end_date);
        $fetch_stmt->execute();
        $fetch_result = $fetch_stmt->get_result();
        
        $groups = [];
        while ($row = $fetch_result->fetch_assoc()) {
            $groups[] = $row;
        }
        $fetch_stmt->close();
        
        if (count($groups) > 0) {
            $success_count = 0;
            $current_datetime = date('Y-m-d H:i:s');
            $new_status = 'Reported';
            
            $update_stmt = $conn->prepare("UPDATE fs_raw_data SET reported_status = ?, reported_status_by = ?, reported_status_date = ? WHERE mainzone <=> ? AND zone <=> ? AND transaction_month BETWEEN ? AND ?");
            
            foreach ($groups as $group) {
                $update_stmt->bind_param("sssssss", 
                    $new_status,           // reported_status
                    $username,             // by
                    $current_datetime,     // date
                    $group['mainzone'],    // mainzone
                    $group['zone'],        // zone
                    $start_date,
                    $end_date
                );
                
                if ($update_stmt->execute()) {
                    $success_count++;
                }
            }
            $update_stmt->close();
            
            if ($success_count > 0) {
                $message = "All $success_count zone(s) marked as reported successfully for " . date('F Y', strtotime($selected_month . '-01')) . ".";
                $message_type = "success";
            } else {
                $message = "No records updated.";
                $message_type = "error";
            }
        } else {
            $message = "No records found for the selected month.";
            $message_type = "error";
        }
    }
}

// Fetch filtered data with report information and row count
// Use MAX() to comply with ONLY_FULL_GROUP_BY
$query = "SELECT 
            mainzone, 
            zone, 
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date,
            MAX(uploaded_date) as uploaded_date,
            COUNT(*) as record_count
          FROM fs_raw_data 
          WHERE transaction_month BETWEEN ? AND ?
          GROUP BY mainzone, zone
          ORDER BY mainzone, zone";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Define the four required zones
$required_zones = ['LZN', 'NCR', 'MIN', 'VIS'];

// Get all zones present in the result
$present_zones = [];
$all_reported = true;
if ($result && $result->num_rows > 0) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        if (!in_array($row['zone'], $present_zones)) {
            $present_zones[] = $row['zone'];
        }
        if ($row['reported_status'] !== 'Reported') {
            $all_reported = false;
        }
    }
    $result->data_seek(0);
}

// Check if all four zones are present
$all_zones_present = count(array_intersect($required_zones, $present_zones)) === 4;

// Disable reporting if not all zones are present OR if all are already reported
$disable_reporting = !$all_zones_present || $all_reported;
$disable_message = '';
if (!$all_zones_present) {
    $disable_message = 'All four zones (LZN, NCR, MIN, VIS) must be present to mark as reported.';
} elseif ($all_reported && $result->num_rows > 0) {
    $disable_message = 'All zones are already marked as reported for this month.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mark as Reported</title>
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
                <h1 style="font-size: 20px; text-align: center;"> Mark as Reported (from Raw Data)</h1>
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
                        <a href="mark_reported_raw.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Zone Status Indicator -->
            <div class="zone-status-indicator" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #dee2e6;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <?php foreach ($required_zones as $zone): ?>
                            <span style="display: flex; align-items: center; gap: 5px;">
                                <?php if (in_array($zone, $present_zones)): ?>
                                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                    <span style="font-weight: 600; color: #28a745;"><?php echo $zone; ?></span>
                                    <span style="color: #6c757d; font-size: 12px;">✓</span>
                                <?php else: ?>
                                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                    <span style="font-weight: 600; color: #dc3545;"><?php echo $zone; ?></span>
                                    <span style="color: #dc3545; font-size: 12px;">✗</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <?php if ($all_zones_present && $result->num_rows > 0): ?>
                            <span style="background: #810000; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                <i class="fas fa-check-circle"></i> All Zones Present
                            </span>
                        <?php elseif ($result->num_rows > 0): ?>
                            <span style="background: #dc3545; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                <i class="fas fa-exclamation-circle"></i> Missing Zones
                            </span>
                        <?php else: ?>
                            <span style="background: #6c757d; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                <i class="fas fa-info-circle"></i> No Data
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <?php 
            $total_records = 0;
            $unique_mainzones = [];
            $unique_zones = [];
            $reported_count = 0;
            $pending_count = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    
                    if ($row['reported_status'] === 'Reported') {
                        $reported_count++;
                    } else {
                        $pending_count++;
                    }
                }
                $result->data_seek(0);
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
                    <div class="stat-label">Reported Groups</div>
                    <div class="stat-value" style="color: #28a745;"><?php echo $reported_count; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Pending Groups</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $pending_count; ?></div>
                </div>
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-size: 14px; color: #6c757d;">
                            <i class="fas fa-info-circle"></i> 
                            <?php if ($result && $result->num_rows > 0): ?>
                                Marking as reported will update all <?php echo $result->num_rows; ?> zone(s) for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>
                            <?php else: ?>
                                No data available for this month
                            <?php endif; ?>
                        </span>
                    </div>
                    <button type="submit" name="action_type" value="report" class="btn-report" 
                            onclick="return confirmReportAction()"
                            <?php echo $disable_reporting ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                        <i class="fas fa-check-circle"></i> Mark All as Reported
                     
                    </button>
                    <a href="lock_period_raw.php" class="btn-lock" style="text-decoration: none;"><i class="fas fa-lock"></i> Lock</a>
                    <a href="unlock_period_raw.php" class="btn-unlock" style="text-decoration: none;"><i class="fas fa-unlock-alt"></i> Unlock</a>
                </div>

                <?php if ($disable_reporting && $result->num_rows > 0): ?>
                    <div style="background: #ffe4e4; border: 1px solid #ff0707; color: #850404; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Notice:</strong> <?php echo $disable_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fa-solid fa-sitemap"></i> Main Zone</th>
                                <th><i class="fa-solid fa-map-location-dot"></i> Zone</th>
                                <th style="text-align: center;"><i class="fa-solid fa-hashtag"></i> Row Count</th>
                                <th><i class="fa-solid fa-circle-check"></i> Reported Status</th>
                                <th><i class="fa-solid fa-user-check"></i> Reported By / Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td style="text-align: center;"><span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></strong></td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-info" style="font-size: 14px;">
                                                <?php echo number_format($row['record_count']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($row['reported_status'] === 'Reported'): ?>
                                                <span class="badge-reported" style="background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 12px;">
                                                    <i class="fas fa-check-circle"></i> Reported
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #3f3f3f; font-style:italic; font-size: 14px;">
                                                    <i class="fas fa-clock"></i> N / A
                                                </span>
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
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="no-data">
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
                setTimeout(function() {
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

        // Confirm action - mark ALL as reported
        function confirmReportAction() {
            <?php if ($result && $result->num_rows > 0): ?>
                const zoneCount = <?php echo $result->num_rows; ?>;
                const monthName = '<?php echo date('F Y', strtotime($selected_month . '-01')); ?>';
                
                return confirm(
                    'Are you sure you want to mark ALL ' + zoneCount + ' zone(s) as reported for ' + monthName + '?\n\n' +
                    'This will update the reported status for:\n' +
                    <?php 
                    $zone_list = [];
                    $result->data_seek(0);
                    while ($row = $result->fetch_assoc()) {
                        $zone_list[] = "'" . $row['mainzone'] . ' - ' . $row['zone'] . "'";
                    }
                    $result->data_seek(0);
                    echo '[' . implode(', ', $zone_list) . '].join("\n")';
                    ?> +
                    '\n\nThis action cannot be undone without unlocking the report.'
                );
            <?php else: ?>
                alert('No data available to mark as reported.');
                return false;
            <?php endif; ?>
        }
    </script>

<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
if (isset($conn)) $conn->close(); 
?>