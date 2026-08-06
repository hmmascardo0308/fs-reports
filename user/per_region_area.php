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
$selectedMonth = isset($_GET['transaction_month']) ? $_GET['transaction_month'] : '';

// Check if any filter is applied
$hasFilters = !empty($selectedRegion) || !empty($selectedArea) || !empty($selectedMonth);

// Only fetch data if filters are applied
$result = null;
$rows = [];
$totalBranch = 0;
$totalShowroom = 0;
$totalOverall = 0;
$gl4Rows = [];
$gl56Rows = [];
$gl4Branch = $gl4Showroom = $gl4Total = 0;
$gl56Branch = $gl56Showroom = $gl56Total = 0;
$grandBranch = $grandShowroom = $grandTotal = 0;
$isNegative = false;

if ($hasFilters) {
    // Build the main query with filters (only GL codes 4,5,6)
    // JOIN with new_gl_codes table to get consistent descriptions
    $whereClause = "WHERE (r.gl_code LIKE '4%' OR r.gl_code LIKE '5%' OR r.gl_code LIKE '6%')";
    if (!empty($selectedRegion)) {
        $whereClause .= " AND r.region_id = '" . $conn->real_escape_string($selectedRegion) . "'";
    }
    if (!empty($selectedArea)) {
        $whereClause .= " AND r.area = '" . $conn->real_escape_string($selectedArea) . "'";
    }
    if (!empty($selectedMonth)) {
        $whereClause .= " AND DATE_FORMAT(r.transaction_month, '%Y-%m') = '" . $conn->real_escape_string(date('Y-m', strtotime($selectedMonth))) . "'";
    }

    // Updated query: GROUP BY gl_code only, and get a single description
    $query = "
        SELECT 
            r.gl_code,
            COALESCE(
                (SELECT gl_description FROM fs_reports.new_gl_codes WHERE gl_code = r.gl_code LIMIT 1),
                MAX(r.gl_desc)
            ) as gl_desc,
            SUM(CASE WHEN r.transaction_type = 'Branch' THEN r.amount ELSE 0 END) as branch_amount,
            SUM(CASE WHEN r.transaction_type = 'Showroom' THEN r.amount ELSE 0 END) as showroom_amount,
            SUM(r.amount) as total_amount
        FROM fs_reports.fs_raw_data_summary r
        $whereClause
        GROUP BY r.gl_code
        ORDER BY r.gl_code
    ";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
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
    }
}

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
                        <label for="transaction_month">Transaction Month</label>
                        <input type="month" name="transaction_month" id="transaction_month" 
                               value="<?= htmlspecialchars($selectedMonth) ?>"
                               min="2020-01" max="<?= date('Y-m') ?>"
                               placeholder="Select month">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Active Filters Display -->
            <?php if (!empty($selectedRegion) || !empty($selectedArea) || !empty($selectedMonth)): ?>
            <div class="active-filters">
                <i class="fas fa-tags"></i> Active Filters:
                <?php if (!empty($selectedRegion)): ?>
                    <span class="filter-tag"><strong>Region ID:</strong> <?= htmlspecialchars($selectedRegion) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedArea)): ?>
                    <span class="filter-tag"><strong>Area:</strong> <?= htmlspecialchars($selectedArea) ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedMonth)): ?>
                    <span class="filter-tag"><strong>Transaction Month:</strong> <?= date('F Y', strtotime($selectedMonth)) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Report Display Section -->
            <?php if ($hasFilters): ?>
                <?php if ($result && $result->num_rows > 0): ?>
                    <!-- Summary Statistics -->
                    <div class="summary-stats">
                        <div class="stat-card">
                            <div class="label">Revenue</div>
                            <div class="value green">₱ <?= number_format($gl4Total, 2) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="label">Expense</div>
                            <div class="value red">₱ <?= number_format($gl56Total, 2) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="label">Net Income</div>
                            <div class="value <?= $isNegative ? 'red' : 'orange' ?>">₱ <?= number_format($grandTotal, 2) ?></div>
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
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['gl_code']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($row['gl_desc']) ?></td>
                                        <td class="text-right">₱ <?= number_format($branchAmt, 2) ?></td>
                                        <td class="text-right">₱ <?= number_format($showroomAmt, 2) ?></td>
                                        <td class="text-right"><strong>₱ <?= number_format($totalAmt, 2) ?></strong></td>
                                    </tr>
                                <?php 
                                    endforeach;
                                    
                                    // Subtotal for GL 4
                                ?>
                                    <tr class="subtotal-row subtotal-row-gl4">
                                        <td colspan="2"><strong>REVENUES</strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl4Branch, 2) ?></strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl4Showroom, 2) ?></strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl4Total, 2) ?></strong></td>
                                    </tr>
                                <?php 
                                }
                                
                                // Display GL 5 & 6 rows
                                if (!empty($gl56Rows)) {
                                    foreach($gl56Rows as $row): 
                                        $branchAmt = (float)$row['branch_amount'];
                                        $showroomAmt = (float)$row['showroom_amount'];
                                        $totalAmt = (float)$row['total_amount'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['gl_code']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($row['gl_desc']) ?></td>
                                        <td class="text-right">₱ <?= number_format($branchAmt, 2) ?></td>
                                        <td class="text-right">₱ <?= number_format($showroomAmt, 2) ?></td>
                                        <td class="text-right"><strong>₱ <?= number_format($totalAmt, 2) ?></strong></td>
                                    </tr>
                                <?php 
                                    endforeach;
                                    
                                    // Subtotal for GL 5 & 6
                                ?>
                                    <tr class="subtotal-row subtotal-row-gl56">
                                        <td colspan="2"><strong>EXPENSE</strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl56Branch, 2) ?></strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl56Showroom, 2) ?></strong></td>
                                        <td class="text-right"><strong>₱ <?= number_format($gl56Total, 2) ?></strong></td>
                                    </tr>
                                <?php 
                                }
                                ?>
                                
                                <!-- Grand Total = Subtotal GL4 - Subtotal GL56 -->
                                <tr class="grand-total-row <?= $isNegative ? 'negative' : 'positive' ?>">
                                    <td colspan="2"><strong>NET INCOME</strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($grandBranch, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($grandShowroom, 2) ?></strong></td>
                                    <td class="text-right"><strong>₱ <?= number_format($grandTotal, 2) ?></strong></td>
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
                        <p><small>Try adjusting your filters.</small></p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Empty State - No filters selected -->
                <div class="empty-state">
                    <i class="fas fa-filter"></i>
                    <h3>Apply Filters to View Report</h3>
                    <p>Please select at least one filter above to generate the report.</p>
                    <p style="font-size: 14px; color: #999;">You can filter by Region, Area, or Transaction Month.</p>
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