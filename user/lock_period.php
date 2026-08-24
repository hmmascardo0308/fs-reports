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

// Fetch filtered data with void and reported information
$query = "SELECT 
            mainzone, 
            zone, 
            transaction_type, 
            MAX(uploaded_date) as uploaded_date,
            COUNT(*) as record_count,
            COUNT(DISTINCT CASE WHEN status_void = 'Void' THEN CONCAT(region, '-', area) END) as void_region_count,
            GROUP_CONCAT(DISTINCT CASE WHEN status_void = 'Void' THEN CONCAT(region, '-', area) END SEPARATOR ', ') as void_details,
            MAX(voided_by) as voided_by,
            MAX(voided_at) as voided_at,
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date
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
    <title>Monthly Financial Report Status</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/lock_unlock.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
</head>
<body>

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
                <h1 style="font-size: 20px; text-align: center;"> Monthly Financial Report Status</h1>
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
            $total_void_region_areas = 0;
            $groups_with_void = 0;
            $total_reported = 0;
            $total_not_reported = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0); // Reset pointer
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    $unique_types[$row['transaction_type']] = true;
                    
                    // Count void region-areas
                    if ($row['void_region_count'] > 0) {
                        $total_void_region_areas += $row['void_region_count'];
                        $groups_with_void++;
                    }
                    
                    // Count reported status
                    if (!empty($row['reported_status']) && $row['reported_status'] === 'Reported') {
                        $total_reported++;
                    } else {
                        $total_not_reported++;
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
                    <div class="stat-label">Void Region-Areas</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo number_format($total_void_region_areas); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Reported Groups</div>
                    <div class="stat-value" style="color: #28a745;"><?php echo $total_reported; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Not Reported Groups</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $total_not_reported; ?></div>
                </div>
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
                            <th><i class="fa-solid fa-clipboard-check"></i> Void Status</th>
                            <th><i class="fa-solid fa-ban"></i> Voided By / Date</th>
                            <th><i class="fa-solid fa-flag"></i> Reported Status</th>
                            <th><i class="fa-solid fa-user-check"></i> Reported By / Date</th>
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
                                    <td style="text-align: center;">
                                        <?php 
                                        if ($row['void_region_count'] > 0) {
                                            echo '<span class="badge-void" style="color: red; font-weight: bold;">Void Region - Area: ' . $row['void_region_count'] . '</span>';
                                        } else {
                                            echo '<span>-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['voided_by']) && !empty($row['voided_at'])): ?>
                                            <span class="audit-info">
                                                <i class="fas fa-user-slash"></i> <?php echo htmlspecialchars($row['voided_by']); ?><br>
                                                <small><?php echo date('M d, Y h:i A', strtotime($row['voided_at'])); ?></small>
                                            </span>
                                        <?php else: ?>
                                            <span class="audit-info text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php 
                                        if (!empty($row['reported_status']) && $row['reported_status'] === 'Reported') {
                                            echo '<span class="badge-reported" style="background-color: #28a745; color: white; padding: 4px 12px; border-radius: 12px; font-weight: bold; display: inline-block;">
                                                    <i class="fas fa-check-circle"></i> Reported
                                                  </span>';
                                        } else {
                                            echo '<span class="badge-not-reported" style="font-style: italic; color: #4b4c4d; font-size: 13px;">
                                                    <i class="fas fa-clock"></i> Not Reported
                                                  </span>';
                                        }
                                        ?>
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
        </div>
    </main>

    <script>
        // Month validation
        document.getElementById('transaction_month').addEventListener('change', function() {
            const selectedDate = new Date(this.value + '-01');
            const today = new Date();
            if (selectedDate > today) {
                alert('Please select a month that is not in the future.');
                this.value = '<?php echo date('Y-m'); ?>';
            }
        });
    </script>
<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
$conn->close(); 
?>