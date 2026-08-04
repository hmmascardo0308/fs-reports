<?php
session_start();
require_once __DIR__ . '/../config/config.php'; 

// Session Management (Simplified for clarity)
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

// Use existing database connection from config.php
global $conn;

// Fetch distinct regions for filter dropdown (filtered for GL codes 4,5,6)
$regionQuery = "SELECT DISTINCT region_id, gl_region 
                FROM fs_reports.fs_raw_data_summary 
                WHERE gl_code LIKE '4%' OR gl_code LIKE '5%' OR gl_code LIKE '6%'
                ORDER BY gl_region";
$regionResult = $conn->query($regionQuery);

// Fetch distinct areas for filter dropdown (filtered for GL codes 4,5,6)
$areaQuery = "SELECT DISTINCT area 
              FROM fs_reports.fs_raw_data_summary 
              WHERE (gl_code LIKE '4%' OR gl_code LIKE '5%' OR gl_code LIKE '6%')
              AND area IS NOT NULL AND area != '' 
              ORDER BY area";
$areaResult = $conn->query($areaQuery);

// Initialize filter variables
$selectedRegion = isset($_GET['region']) ? $_GET['region'] : '';
$selectedArea = isset($_GET['area']) ? $_GET['area'] : '';
$selectedGlCode = isset($_GET['gl_code']) ? $_GET['gl_code'] : '';
$selectedMonth = isset($_GET['transaction_month']) ? $_GET['transaction_month'] : '';

// Build the main query with filters (only GL codes 4,5,6)
$whereClause = "WHERE (r.gl_code LIKE '4%' OR r.gl_code LIKE '5%' OR r.gl_code LIKE '6%')";
if (!empty($selectedRegion)) {
    $whereClause .= " AND r.region_id = '" . $conn->real_escape_string($selectedRegion) . "'";
}
if (!empty($selectedArea)) {
    $whereClause .= " AND r.area = '" . $conn->real_escape_string($selectedArea) . "'";
}
if (!empty($selectedGlCode)) {
    $whereClause .= " AND r.gl_code = '" . $conn->real_escape_string($selectedGlCode) . "'";
}
if (!empty($selectedMonth)) {
    $whereClause .= " AND DATE_FORMAT(r.transaction_month, '%Y-%m') = '" . $conn->real_escape_string(date('Y-m', strtotime($selectedMonth))) . "'";
}

$query = "
    SELECT 
        r.gl_code,
        r.gl_desc,
        SUM(CASE WHEN r.transaction_type = 'Branch' THEN r.amount ELSE 0 END) as branch_amount,
        SUM(CASE WHEN r.transaction_type = 'Showroom' THEN r.amount ELSE 0 END) as showroom_amount,
        SUM(r.amount) as total_amount
    FROM fs_reports.fs_raw_data_summary r
    $whereClause
    GROUP BY r.gl_code, r.gl_desc
    ORDER BY r.gl_code
";

$result = $conn->query($query);

// Fetch GL codes for additional filter (only GL codes 4,5,6 and based on selected region/area)
$glCodeQuery = "
    SELECT DISTINCT gl_code, gl_desc 
    FROM fs_reports.fs_raw_data_summary 
    WHERE (gl_code LIKE '4%' OR gl_code LIKE '5%' OR gl_code LIKE '6%')
";
if (!empty($selectedRegion)) {
    $glCodeQuery .= " AND region_id = '" . $conn->real_escape_string($selectedRegion) . "'";
}
if (!empty($selectedArea)) {
    $glCodeQuery .= " AND area = '" . $conn->real_escape_string($selectedArea) . "'";
}
if (!empty($selectedMonth)) {
    $glCodeQuery .= " AND DATE_FORMAT(transaction_month, '%Y-%m') = '" . $conn->real_escape_string(date('Y-m', strtotime($selectedMonth))) . "'";
}
$glCodeQuery .= " ORDER BY gl_code";
$glCodeResult = $conn->query($glCodeQuery);

// Get available months for the month filter (distinct months from data)
$monthQuery = "
    SELECT DISTINCT DATE_FORMAT(transaction_month, '%Y-%m') as month_value,
                    DATE_FORMAT(transaction_month, '%M %Y') as month_label,
                    transaction_month
    FROM fs_reports.fs_raw_data_summary 
    WHERE (gl_code LIKE '4%' OR gl_code LIKE '5%' OR gl_code LIKE '6%')
    ORDER BY transaction_month DESC
";
$monthResult = $conn->query($monthQuery);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Per Region Area Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/per_region_area.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            background: white;
        }
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.1);
        }
        .filter-group input[type="month"] {
            min-height: 38px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #1e7e34;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #bd2130;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            max-height: 500px;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        table thead {
            background: #f1f3f5;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        table td {
            padding: 10px 15px;
            border-bottom: 1px solid #e9ecef;
        }
        table tbody tr:hover {
            background: #f8f9fa;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .total-row {
            background: #f8f9fa !important;
            font-weight: 600;
        }
        .total-row td {
            border-top: 2px solid #dee2e6;
        }
        .subtotal-row {
            background: #e9ecef !important;
            font-weight: 700;
        }
        .subtotal-row td {
            border-top: 2px solid #adb5bd;
            border-bottom: 2px solid #adb5bd;
        }
        .subtotal-row-gl4 {
            background: #d4edda !important;
        }
        .subtotal-row-gl56 {
            background: #cce5ff !important;
        }
        .grand-total-row {
            background: #f8f9fa !important;
            font-weight: 800;
        }
        .grand-total-row td {
            border-top: 3px solid #495057;
            border-bottom: 3px solid #495057;
        }
        .grand-total-row.negative {
            background: #f8d7da !important;
            color: #721c24;
        }
        .grand-total-row.positive {
            background: #d4edda !important;
            color: #155724;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-branch {
            background: #d4edda;
            color: #155724;
        }
        .badge-showroom {
            background: #cce5ff;
            color: #004085;
        }
        .badge-gl4 {
            background: #28a745;
            color: white;
        }
        .badge-gl56 {
            background: #007bff;
            color: white;
        }
        .summary-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            background: white;
            padding: 15px 25px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            flex: 1;
            min-width: 150px;
        }
        .stat-card .label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-top: 5px;
        }
        .stat-card .value.green {
            color: #28a745;
        }
        .stat-card .value.blue {
            color: #007bff;
        }
        .stat-card .value.purple {
            color: #6f42c1;
        }
        .stat-card .value.red {
            color: #dc3545;
        }
        .export-section {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .filter-info {
            background: #e7f3ff;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
            font-size: 14px;
            color: #004085;
        }
        .filter-info i {
            margin-right: 8px;
        }
        .gl-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .gl-badge-4 {
            background: #d4edda;
            color: #155724;
        }
        .gl-badge-56 {
            background: #cce5ff;
            color: #004085;
        }
        .active-filters {
            background: #fff3cd;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            font-size: 13px;
            color: #856404;
        }
        .active-filters i {
            margin-right: 8px;
        }
        .active-filters .filter-tag {
            display: inline-block;
            background: #fff;
            padding: 2px 12px;
            border-radius: 12px;
            margin: 3px 5px 3px 0;
            border: 1px solid #ffc107;
            font-size: 12px;
        }
        @media (max-width: 768px) {
            .filter-group {
                min-width: 100%;
            }
            .filter-actions {
                width: 100%;
            }
            .filter-actions .btn {
                flex: 1;
                text-align: center;
            }
            .stat-card {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <h2 style="text-align: center; margin-top: -2%;">Per Region Area Report</h2>

            <!-- Filter Info -->
            <div class="filter-info">
                <i class="fas fa-info-circle"></i> 
                Showing data for GL Codes starting with <strong>4, 5, or 6</strong> only
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="filter-form" id="filterForm">
                    <div class="filter-group">
                        <label for="region">Region</label>
                        <select name="region" id="region">
                            <option value="">All Regions</option>
                            <?php while($row = $regionResult->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($row['region_id']) ?>" 
                                    <?= ($selectedRegion == $row['region_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['gl_region']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="area">Area</label>
                        <select name="area" id="area">
                            <option value="">All Areas</option>
                            <?php 
                            // Reset pointer and re-fetch areas
                            if ($areaResult) {
                                $areaResult->data_seek(0);
                                while($row = $areaResult->fetch_assoc()): 
                            ?>
                                <option value="<?= htmlspecialchars($row['area']) ?>" 
                                    <?= ($selectedArea == $row['area']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['area']) ?>
                                </option>
                            <?php 
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="gl_code">GL Code</label>
                        <select name="gl_code" id="gl_code">
                            <option value="">All GL Codes</option>
                            <?php while($row = $glCodeResult->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($row['gl_code']) ?>" 
                                    <?= ($selectedGlCode == $row['gl_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['gl_code']) ?> - <?= htmlspecialchars($row['gl_desc']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="transaction_month">Transaction Month</label>
                        <input type="month" name="transaction_month" id="transaction_month" 
                               value="<?= htmlspecialchars($selectedMonth) ?>"
                               min="2020-01" max="<?= date('Y-m') ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Active Filters Display -->
            <?php if (!empty($selectedRegion) || !empty($selectedArea) || !empty($selectedGlCode) || !empty($selectedMonth)): ?>
            <div class="active-filters">
                <i class="fas fa-tags"></i> Active Filters:
                <?php if (!empty($selectedRegion)): ?>
                    <span class="filter-tag"><strong>Region:</strong> <?= htmlspecialchars($selectedRegion) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedArea)): ?>
                    <span class="filter-tag"><strong>Area:</strong> <?= htmlspecialchars($selectedArea) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedGlCode)): ?>
                    <span class="filter-tag"><strong>GL Code:</strong> <?= htmlspecialchars($selectedGlCode) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedMonth)): ?>
                    <span class="filter-tag"><strong>Month:</strong> <?= date('F Y', strtotime($selectedMonth)) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($result && $result->num_rows > 0): 
                // Get all rows for summary calculations
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                $totalBranch = array_sum(array_column($rows, 'branch_amount'));
                $totalShowroom = array_sum(array_column($rows, 'showroom_amount'));
                $totalOverall = array_sum(array_column($rows, 'total_amount'));
                
                // Separate rows by GL code prefix
                $gl4Rows = [];
                $gl56Rows = [];
                
                foreach ($rows as $row) {
                    $glCode = (string)$row['gl_code'];
                    if (strpos($glCode, '4') === 0) {
                        $gl4Rows[] = $row;
                    } elseif (strpos($glCode, '5') === 0 || strpos($glCode, '6') === 0) {
                        $gl56Rows[] = $row;
                    }
                }
                
                // Calculate subtotals
                $gl4Branch = array_sum(array_column($gl4Rows, 'branch_amount'));
                $gl4Showroom = array_sum(array_column($gl4Rows, 'showroom_amount'));
                $gl4Total = array_sum(array_column($gl4Rows, 'total_amount'));
                
                $gl56Branch = array_sum(array_column($gl56Rows, 'branch_amount'));
                $gl56Showroom = array_sum(array_column($gl56Rows, 'showroom_amount'));
                $gl56Total = array_sum(array_column($gl56Rows, 'total_amount'));
                
                // Calculate Grand Total as SUBTRACTION: GL4 - GL5&6
                $grandBranch = $gl4Branch - $gl56Branch;
                $grandShowroom = $gl4Showroom - $gl56Showroom;
                $grandTotal = $gl4Total - $gl56Total;
                
                // Determine if grand total is negative for styling
                $isNegative = $grandTotal < 0;
            ?>
                <!-- Summary Statistics -->
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="label">Total Branch Amount</div>
                        <div class="value green">₱ <?= number_format($totalBranch, 2) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Total Showroom Amount</div>
                        <div class="value blue">₱ <?= number_format($totalShowroom, 2) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Total Overall Amount</div>
                        <div class="value purple">₱ <?= number_format($totalOverall, 2) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Total Records</div>
                        <div class="value"><?= count($rows) ?></div>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>GL Code</th>
                                <th>GL Description</th>
                                <th class="text-right">Branch Amount</th>
                                <th class="text-right">Showroom Amount</th>
                                <th class="text-right">Total Amount</th>
                                <th class="text-center">Branch %</th>
                                <th class="text-center">Showroom %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Display GL 4 rows
                            if (!empty($gl4Rows)) {
                                foreach($gl4Rows as $row): 
                                    $branchAmt = (float)$row['branch_amount'];
                                    $showroomAmt = (float)$row['showroom_amount'];
                                    $totalAmt = (float)$row['total_amount'];
                                    
                                    $branchPercent = $totalAmt > 0 ? ($branchAmt / $totalAmt) * 100 : 0;
                                    $showroomPercent = $totalAmt > 0 ? ($showroomAmt / $totalAmt) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['gl_code']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($row['gl_desc']) ?></td>
                                    <td class="text-right">₱ <?= number_format($branchAmt, 2) ?></td>
                                    <td class="text-right">₱ <?= number_format($showroomAmt, 2) ?></td>
                                    <td class="text-right"><strong>₱ <?= number_format($totalAmt, 2) ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge badge-branch"><?= number_format($branchPercent, 1) ?>%</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-showroom"><?= number_format($showroomPercent, 1) ?>%</span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                                
                                // Subtotal for GL 4
                            ?>
                                <tr class="subtotal-row subtotal-row-gl4">
                                    <td colspan="2"><strong>SUBTOTAL - GL Codes starting with 4</strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl4Branch, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl4Showroom, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl4Total, 2) ?></strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php 
                            }
                            
                            // Display GL 5 & 6 rows
                            if (!empty($gl56Rows)) {
                                foreach($gl56Rows as $row): 
                                    $branchAmt = (float)$row['branch_amount'];
                                    $showroomAmt = (float)$row['showroom_amount'];
                                    $totalAmt = (float)$row['total_amount'];
                                    
                                    $branchPercent = $totalAmt > 0 ? ($branchAmt / $totalAmt) * 100 : 0;
                                    $showroomPercent = $totalAmt > 0 ? ($showroomAmt / $totalAmt) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['gl_code']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($row['gl_desc']) ?></td>
                                    <td class="text-right">₱ <?= number_format($branchAmt, 2) ?></td>
                                    <td class="text-right">₱ <?= number_format($showroomAmt, 2) ?></td>
                                    <td class="text-right"><strong>₱ <?= number_format($totalAmt, 2) ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge badge-branch"><?= number_format($branchPercent, 1) ?>%</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-showroom"><?= number_format($showroomPercent, 1) ?>%</span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                                
                                // Subtotal for GL 5 & 6
                            ?>
                                <tr class="subtotal-row subtotal-row-gl56">
                                    <td colspan="2"><strong>SUBTOTAL - GL Codes starting with 5 & 6</strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl56Branch, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl56Showroom, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($gl56Total, 2) ?></strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php 
                            }
                            ?>
                            
                            <!-- Grand Total = Subtotal GL4 - Subtotal GL56 -->
                            <tr class="grand-total-row <?= $isNegative ? 'negative' : 'positive' ?>">
                                <td colspan="2"><strong>GRAND TOTAL (GL4 - GL5&6)</strong></td>
                                <td class="text-right"><strong>₱ <?= number_format($grandBranch, 2) ?></strong></td>
                                <td class="text-right"><strong>₱ <?= number_format($grandShowroom, 2) ?></strong></td>
                                <td class="text-right"><strong>₱ <?= number_format($grandTotal, 2) ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Export Buttons -->
                <div class="export-section">
                    <button onclick="exportData()" class="btn btn-success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>

            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-database" style="font-size: 48px; color: #ccc;"></i>
                    <h3>No Data Available</h3>
                    <p>No records found for GL codes starting with 4, 5, or 6 with the selected filters.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <?php include '../footer.php'; ?>

    <script>
        // Export functionality
        function exportData() {
            const table = document.querySelector('table');
            if (!table) {
                alert('No data to export');
                return;
            }

            let csv = '';
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cols = row.querySelectorAll('td, th');
                const rowData = [];
                cols.forEach(col => {
                    let text = col.innerText.trim();
                    // Remove currency symbol and commas for numeric values
                    if (text.includes('₱')) {
                        text = text.replace('₱', '').replace(/,/g, '').trim();
                    }
                    // Handle percentage
                    if (text.includes('%')) {
                        text = text.replace('%', '').trim();
                    }
                    rowData.push(text);
                });
                csv += rowData.join(',') + '\n';
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `per_region_area_report_${new Date().toISOString().slice(0,10)}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Add a loading state when filters are applied
        document.getElementById('filterForm').addEventListener('submit', function() {
            const btn = this.querySelector('.btn-primary');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            btn.disabled = true;
        });

        // Auto-submit on month change (optional)
        document.getElementById('transaction_month').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    </script>
</body>
</html>

<?php
// No need to close connection if it's managed in config.php
// $conn->close();
?>