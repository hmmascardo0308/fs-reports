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

// Handle AJAX request for saving adjusted amount
if (isset($_POST['action']) && $_POST['action'] === 'save_adjusted_amount') {
    header('Content-Type: application/json');
    
    $id = intval($_POST['id'] ?? 0);
    $adjusted_amount = floatval($_POST['adjusted_amount'] ?? 0);
    
    if ($id > 0) {
        // Get the original amount to calculate new amount
        $query = "SELECT amount FROM comparative_report WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $original_amount = floatval($row['amount']);
            $new_amount = $original_amount + $adjusted_amount;
            
            // Update the record
            $update = "UPDATE comparative_report 
                       SET adjusted_amount = ?, new_amount = ? 
                       WHERE id = ?";
            $stmt = $conn->prepare($update);
            $stmt->bind_param("ddi", $adjusted_amount, $new_amount, $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'new_amount' => number_format($new_amount, 2)]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    exit;
}

// Handle AJAX request for getting areas by region
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_areas') {
    header('Content-Type: application/json');
    
    $region = $_GET['region'] ?? '';
    $areas = [];
    
    if (!empty($region)) {
        $query = "SELECT DISTINCT area FROM comparative_report WHERE region = ? ORDER BY area ASC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $region);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $areas[] = $row['area'];
        }
    }
    
    echo json_encode($areas);
    exit;
}

// Fetch filter options
$regions = $conn->query("SELECT DISTINCT region FROM comparative_report ORDER BY region ASC");
$areas = $conn->query("SELECT DISTINCT area FROM comparative_report ORDER BY area ASC");

// Initialize filter variables
$filter_region = $_GET['region'] ?? '';
$filter_month  = $_GET['month'] ?? '';
$filter_areas  = $_GET['area'] ?? [];
if (!is_array($filter_areas)) {
    $filter_areas = [];
}
$transaction_type = $_GET['transaction_type'] ?? 'all';

// Build common WHERE clauses to reduce duplication
$region_where = '';
if (!empty($filter_region)) {
    $region_where = " AND region = '" . $conn->real_escape_string($filter_region) . "'";
}

$month_where = '';
if (!empty($filter_month)) {
    $month_parts = explode('-', $filter_month);
    if (count($month_parts) == 2) {
        $year = intval($month_parts[0]);
        $month = intval($month_parts[1]);
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        $month_where = " AND transaction_month >= '" . $conn->real_escape_string($start_date) . "' 
                        AND transaction_month <= '" . $conn->real_escape_string($end_date) . "'";
    }
}

$areas_in = '';
if (!empty($filter_areas)) {
    $sanitized_areas = array_map(function($a) use ($conn) {
        return "'" . $conn->real_escape_string($a) . "'";
    }, $filter_areas);
    $areas_in = " AND area IN (" . implode(',', $sanitized_areas) . ")";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uploaded Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/report.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>

<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="comparative_report.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <h2 style="text-align: center;">Review Uploaded File</h1>

            <section class="filter-section">
                <form method="GET" action="" class="filter-form" id="filterForm">
                    <div class="filter-group">
                        <label for="region">Region</label>
                        <select name="region" id="region" onchange="loadAreasByRegion()">
                            <option value="">All Regions</option>
                            <?php while($row = $regions->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($row['region']) ?>" <?= ($filter_region === $row['region']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['region']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Area</label>
                        <div class="dropdown-checkbox" id="dropdownCheckbox">
                            <button type="button" class="dropdown-btn" onclick="toggleDropdown()" id="dropdownBtn">
                                Select Area(s)
                            </button>
                            <div class="dropdown-content" id="areaDropdown">
                                <div class="loading-areas" id="loadingAreas" style="display: none;">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Loading areas...
                                </div>
                                <div id="areaCheckboxes">
                                    <?php if (empty($filter_region)): ?>
                                        <?php mysqli_data_seek($areas, 0); ?>
                                        <?php while($row = $areas->fetch_assoc()): ?>
                                            <label class="checkbox-item">
                                                <input type="checkbox" name="area[]" value="<?= htmlspecialchars($row['area']) ?>"
                                                    <?= in_array($row['area'], $filter_areas) ? 'checked' : '' ?>>
                                                <?= htmlspecialchars($row['area']) ?>
                                            </label>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <!-- Areas will be loaded via AJAX based on selected region -->
                                        <div class="loading-areas">
                                            <i class="fa-solid fa-spinner fa-spin"></i> Loading areas...
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="filter-group">
                        <label for="transaction_type">Branch Type</label>
                        <select name="transaction_type" id="transaction_type">
                            <option value="all" <?= ($transaction_type === 'all') ? 'selected' : '' ?>>All</option>
                            <option value="branch" <?= ($transaction_type === 'branch') ? 'selected' : '' ?>>Branch</option>
                            <option value="showroom" <?= ($transaction_type === 'showroom') ? 'selected' : '' ?>>Showroom</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="month">Month</label>
                        <input type="month" name="month" id="month" value="<?= htmlspecialchars($filter_month) ?>">
                    </div>

                    <button type="submit" class="btn-filter">
                        <i class="fa-solid fa-filter"></i> Apply Filters
                    </button>

                    <a href="report.php" class="btn-reset">
                        <i class="fa-solid fa-rotate"></i> Reset
                    </a>
                </form>
            </section>

            <div class="reports-flex-container">
                <?php if (empty($_GET)): ?>
                    <div style="width: 100%; text-align: center; color: #999; padding: 40px;">
                        Select Transaction Type, Region, Area(s), & Month to view the report.
                    </div>
                <?php else: ?>
                    <?php $has_data = false; ?>

                    <!-- Branch/Area Tables (shown for 'branch' or 'all') -->
                    <?php if (in_array($transaction_type, ['branch', 'all'])): ?>
                        <?php
                        $branch_where = "transaction_type = 'Branch'";
                        $sql_groups = "SELECT DISTINCT area, region 
                                       FROM comparative_report 
                                       WHERE $branch_where $region_where $month_where $areas_in 
                                       ORDER BY region ASC, area ASC";
                        $result_groups = $conn->query($sql_groups);

                        if ($result_groups && $result_groups->num_rows > 0):
                            $has_data = true;
                            while ($row = $result_groups->fetch_assoc()):
                                $current_area = $row['area'];
                                $current_region = $row['region'];
                                $sql_data = "SELECT id, gl_code, gl_description, amount, adjusted_amount, new_amount, percentage 
                                             FROM comparative_report 
                                             WHERE area = '" . $conn->real_escape_string($current_area) . "'
                                             AND region = '" . $conn->real_escape_string($current_region) . "'
                                             AND $branch_where $region_where $month_where
                                             ORDER BY gl_code ASC";
                                $results = $conn->query($sql_data);
                        ?>
                                <div class="report-table-wrapper">
                                    <div class="area-title">
                                        Region: <?= htmlspecialchars($current_region) ?> | Area: <?= htmlspecialchars($current_area) ?>
                                        <span style="color:white; font-weight:500; font-size:14px;">(Branch)</span>
                                    </div>
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>GL Code</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($results && $results->num_rows > 0):
                                                $total_revenue = 0;
                                                $total_expenses = 0;
                                                $has_switched = false;
                                                while ($data_row = $results->fetch_assoc()):
                                                    $gl_prefix = substr($data_row['gl_code'], 0, 1);
                                                    
                                                    if (!$has_switched && in_array($gl_prefix, ['5', '6']) && $total_revenue > 0):
                                                        $has_switched = true; ?>
                                                        <tr class="total-row">
                                                            <td colspan="2" style="text-align: right; font-weight: bold;">Revenue:</td>
                                                            <td style="text-align: right; font-weight: bold; border-top: 1px solid #333;">
                                                                <?= number_format($total_revenue, 2); ?>
                                                            </td>
                                                            <td></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <tr data-id="<?= $data_row['id'] ?>">
                                                        <td><?= htmlspecialchars($data_row['gl_code']); ?></td>
                                                        <td><?= htmlspecialchars($data_row['gl_description']); ?></td>
                                                        <td class="amount-column"><?= number_format($data_row['amount'], 2); ?></td>
                                                        <td style="text-align: center;"><?= htmlspecialchars($data_row['percentage']); ?></td>
                                                    </tr>
                                                    <?php
                                                    $amount_for_total = $data_row['amount'];
                                                    if ($gl_prefix == '4') {
                                                        $total_revenue += $amount_for_total;
                                                    } elseif (in_array($gl_prefix, ['5', '6'])) {
                                                        $total_expenses += $amount_for_total;
                                                    }
                                                    ?>
                                                <?php endwhile;
                                                if ($total_expenses > 0): ?>
                                                    <tr class="total-row">
                                                        <td colspan="2" style="text-align: right; font-weight: bold;">Expense:</td>
                                                        <td style="text-align: right; font-weight: bold; border-top: 1px solid #333;">
                                                            <?= number_format($total_expenses, 2); ?>
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                <?php endif;
                                                $net_revenue = $total_revenue - $total_expenses;
                                                $text_color = ($net_revenue >= 0) ? '#28a745' : '#dc3545';
                                                ?>
                                                <tr class="net-income-row" style="background-color: #f8f9fa;">
                                                    <td colspan="2" style="text-align: right; font-weight: 800;">Net Income:</td>
                                                    <td style="text-align: right; font-weight: 800; color: <?= $text_color ?>;">
                                                        <?= number_format($net_revenue, 2); ?>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" style="text-align: center; color: red; padding: 20px; font-weight: bold;">
                                                        No data for this area.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Showroom Tables (shown for 'showroom' or 'all') -->
                    <?php if (in_array($transaction_type, ['showroom', 'all'])): ?>
                        <?php
                        $showroom_where = "transaction_type = 'Showroom'";
                        $sql_groups = "SELECT DISTINCT area, region 
                                       FROM comparative_report 
                                       WHERE $showroom_where $region_where $month_where $areas_in 
                                       ORDER BY region ASC, area ASC";
                        $result_groups = $conn->query($sql_groups);

                        if ($result_groups && $result_groups->num_rows > 0):
                            $has_data = true;
                            while ($row = $result_groups->fetch_assoc()):
                                $current_area = $row['area'];
                                $current_region = $row['region'];
                                $sql_data = "SELECT id, gl_code, gl_description, amount, adjusted_amount, new_amount, percentage 
                                             FROM comparative_report 
                                             WHERE area = '" . $conn->real_escape_string($current_area) . "'
                                             AND region = '" . $conn->real_escape_string($current_region) . "'
                                             AND $showroom_where $region_where $month_where
                                             ORDER BY gl_code ASC";
                                $results = $conn->query($sql_data);
                        ?>
                                <div class="report-table-wrapper">
                                    <div class="area-title" style="background: #2c3e50;">
                                        Region: <?= htmlspecialchars($current_region) ?> | Area: <?= htmlspecialchars($current_area) ?>
                                        <span style="color:#f1c40f; font-weight:500; font-size:14px;">(Showroom)</span>
                                    </div>
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>GL Code</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($results && $results->num_rows > 0):
                                                $total_revenue = 0;
                                                $total_expenses = 0;
                                                $has_switched = false;
                                                while ($data_row = $results->fetch_assoc()):
                                                    $gl_prefix = substr($data_row['gl_code'], 0, 1);
                                                    
                                                    if (!$has_switched && in_array($gl_prefix, ['5', '6']) && $total_revenue > 0):
                                                        $has_switched = true; ?>
                                                        <tr class="total-row">
                                                            <td colspan="2" style="text-align: right; font-weight: bold;">Revenue:</td>
                                                            <td style="text-align: right; font-weight: bold; border-top: 1px solid #333;">
                                                                <?= number_format($total_revenue, 2); ?>
                                                            </td>
                                                            <td></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <tr data-id="<?= $data_row['id'] ?>">
                                                        <td><?= htmlspecialchars($data_row['gl_code']); ?></td>
                                                        <td><?= htmlspecialchars($data_row['gl_description']); ?></td>
                                                        <td class="amount-column"><?= number_format($data_row['amount'], 2); ?></td>
                                                        <td style="text-align: center;"><?= htmlspecialchars($data_row['percentage']); ?></td>
                                                    </tr>
                                                    <?php
                                                    $amount_for_total = $data_row['amount'];
                                                    if ($gl_prefix == '4') {
                                                        $total_revenue += $amount_for_total;
                                                    } elseif (in_array($gl_prefix, ['5', '6'])) {
                                                        $total_expenses += $amount_for_total;
                                                    }
                                                    ?>
                                                <?php endwhile;
                                                if ($total_expenses > 0): ?>
                                                    <tr class="total-row">
                                                        <td colspan="2" style="text-align: right; font-weight: bold;">Expense:</td>
                                                        <td style="text-align: right; font-weight: bold; border-top: 1px solid #333;">
                                                            <?= number_format($total_expenses, 2); ?>
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                <?php endif;
                                                $net_revenue = $total_revenue - $total_expenses;
                                                $text_color = ($net_revenue >= 0) ? '#28a745' : '#dc3545';
                                                ?>
                                                <tr class="net-income-row" style="background-color: #f8f9fa;">
                                                    <td colspan="2" style="text-align: right; font-weight: 800;">Net Income:</td>
                                                    <td style="text-align: right; font-weight: 800; color: <?= $text_color ?>;">
                                                        <?= number_format($net_revenue, 2); ?>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" style="text-align: center; color: red; padding: 20px; font-weight: bold;">
                                                        No data for this area.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$has_data): ?>
                        <div style="width: 100%; text-align: center; color: #999; padding: 40px;">
                            No data found for the selected filters.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
    // Store all areas for filtering
    const allAreas = <?php 
        $areas->data_seek(0);
        $areasList = [];
        while($row = $areas->fetch_assoc()) {
            $areasList[] = $row['area'];
        }
        echo json_encode($areasList);
    ?>;
    
    // Store region-area mapping
    const regionAreas = {};

    function toggleDropdown() {
        document.getElementById("areaDropdown").classList.toggle("show");
    }

    // Close dropdown when clicking outside
    document.addEventListener("click", function(e) {
        const dropdown = document.querySelector(".dropdown-checkbox");
        if (dropdown && !dropdown.contains(e.target)) {
            document.getElementById("areaDropdown").classList.remove("show");
        }
    });

    function loadAreasByRegion() {
        const region = document.getElementById('region').value;
        const areaCheckboxes = document.getElementById('areaCheckboxes');
        const loadingAreas = document.getElementById('loadingAreas');
        const dropdownCheckbox = document.getElementById('dropdownCheckbox');
        
        // Show loading
        loadingAreas.style.display = 'block';
        areaCheckboxes.innerHTML = '';
        
        if (!region) {
            // If "All Regions" selected, show all areas
            loadingAreas.style.display = 'none';
            let html = '';
            allAreas.forEach(area => {
                html += `<label class="checkbox-item">
                    <input type="checkbox" name="area[]" value="${area}">
                    ${area}
                </label>`;
            });
            areaCheckboxes.innerHTML = html;
            return;
        }
        
        // Fetch areas for selected region
        fetch(`?ajax=get_areas&region=${encodeURIComponent(region)}`)
            .then(response => response.json())
            .then(areas => {
                loadingAreas.style.display = 'none';
                
                if (areas.length === 0) {
                    areaCheckboxes.innerHTML = '<div class="loading-areas">No areas found for this region</div>';
                    return;
                }
                
                let html = '';
                areas.forEach(area => {
                    // Check if this area was previously selected
                    const selectedAreas = <?= json_encode($filter_areas) ?>;
                    const checked = selectedAreas.includes(area) ? 'checked' : '';
                    
                    html += `<label class="checkbox-item">
                        <input type="checkbox" name="area[]" value="${area}" ${checked}>
                        ${area}
                    </label>`;
                });
                areaCheckboxes.innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading areas:', error);
                loadingAreas.style.display = 'none';
                areaCheckboxes.innerHTML = '<div class="loading-areas">Error loading areas</div>';
            });
    }

    // Load areas on page load if region is pre-selected
    document.addEventListener('DOMContentLoaded', function() {
        const selectedRegion = document.getElementById('region').value;
        if (selectedRegion) {
            loadAreasByRegion();
        }
    });

    function updateAmount(input, id) {
        const adjustedAmount = parseFloat(input.value) || 0;
        const indicator = document.getElementById('indicator-' + id);
        
        // Show loading indicator
        indicator.style.display = 'inline';
        indicator.className = 'saving-indicator';
        
        // Disable input while saving
        input.disabled = true;
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=save_adjusted_amount&id=' + id + '&adjusted_amount=' + adjustedAmount
        })
        .then(response => response.json())
        .then(data => {
            input.disabled = false;
            
            if (data.success) {
                // Update the new amount cell
                document.getElementById('new-amount-' + id).textContent = data.new_amount;
                
                // Show success indicator briefly
                indicator.innerHTML = '<i class="fa-solid fa-check"></i>';
                indicator.className = 'saving-indicator save-success';
                
                setTimeout(() => {
                    indicator.style.display = 'none';
                }, 2000);
            } else {
                // Show error
                indicator.innerHTML = '<i class="fa-solid fa-exclamation-triangle"></i>';
                indicator.className = 'saving-indicator save-error';
                
                setTimeout(() => {
                    indicator.style.display = 'none';
                }, 3000);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            input.disabled = false;
            indicator.innerHTML = '<i class="fa-solid fa-exclamation-triangle"></i>';
            indicator.className = 'saving-indicator save-error';
            
            setTimeout(() => {
                indicator.style.display = 'none';
            }, 3000);
        });
    }
    </script>

<?php include '../footer.php'; ?>

</body>
</html>