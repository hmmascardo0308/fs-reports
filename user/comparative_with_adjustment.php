<?php
session_start();
require_once __DIR__ . '/../config/config.php';

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

// Initialize filter variables
$mainzone = $_GET['mainzone'] ?? '';
$zone = $_GET['zone'] ?? '';
$region = $_GET['region'] ?? '';
$primary_month = $_GET['primary_month'] ?? '';
$primary_year = $_GET['primary_year'] ?? '';
$previous_month = $_GET['previous_month'] ?? '';
$previous_year = $_GET['previous_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

if (empty($primary_period) && !empty($primary_month) && !empty($primary_year)) {
    $primary_period = sprintf('%04d-%02d', (int)$primary_year, (int)date('m', strtotime($primary_month)));
}
if (empty($previous_period) && !empty($previous_month) && !empty($previous_year)) {
    $previous_period = sprintf('%04d-%02d', (int)$previous_year, (int)date('m', strtotime($previous_month)));
}

if (!empty($primary_period) && preg_match('/^\d{4}-\d{2}$/', $primary_period)) {
    $primary_year = (int)substr($primary_period, 0, 4);
    $primary_month = $primary_period . '-01';
}
if (!empty($previous_period) && preg_match('/^\d{4}-\d{2}$/', $previous_period)) {
    $previous_year = (int)substr($previous_period, 0, 4);
    $previous_month = $previous_period . '-01';
}

// Flag to check if we have enough filters to show data
$has_filters = !empty($primary_month) && !empty($primary_year) && !empty($previous_month) && !empty($previous_year);

// Handle reset
if (isset($_GET['reset']) && $_GET['reset'] == 1) {
    $mainzone = $zone = $region = $primary_month = $primary_year = $previous_month = $previous_year = '';
    $primary_period = $previous_period = '';
    $has_filters = false;
}

// Function to get regions based on selected mainzone/zone
function getRegionsToDisplay(mysqli $conn, string $mainzone, string $zone, string $region): array {
    $regions = [];
    
    if (!empty($region)) {
        // If a specific region is selected, only show that region
        $regions[] = $region;
    } elseif (!empty($zone)) {
        // If only zone is selected, get all regions under that zone
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE zone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $zone);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
        $stmt->close();
    } elseif (!empty($mainzone)) {
        // If only mainzone is selected, get all regions under that mainzone
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE mainzone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mainzone);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
        $stmt->close();
    } else {
        // No mainzone/zone selected - get all regions (limit to avoid too many tables)
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE region IS NOT NULL AND region != '' ORDER BY region LIMIT 20";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
    }
    
    return $regions;
}

// Function to fetch data for a specific region and period
function fetchRegionData(mysqli $conn, string $region, string $month, int $year, string $mainzone, string $zone): array {
    $data = [];
    $where_conditions = ["region = ?", "transaction_month = ?", "transaction_year = ?"];
    $params = [$region, $month, $year];
    $types = "ssi";
    
    if (!empty($mainzone)) {
        $where_conditions[] = "mainzone = ?";
        $params[] = $mainzone;
        $types .= "s";
    }
    if (!empty($zone)) {
        $where_conditions[] = "zone = ?";
        $params[] = $zone;
        $types .= "s";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    $sql = "SELECT sort_order, description, sub_order, gl_description_comparative, 
                   mlfsi, jewelers, mainzone, zone, region, 
                   transaction_month, transaction_year 
            FROM manual_adjustment 
            $where_clause 
            ORDER BY sort_order, sub_order";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = $row['sort_order'] . '|' . $row['sub_order'];
            $data[$key] = $row;
        }
        $stmt->close();
    }
    
    return $data;
}

// Function to get skeleton structure
function getSkeletonData(mysqli $conn): array {
    $data = [];
    $sql_skeleton = "SELECT DISTINCT sort_order, description, sub_order, gl_description_comparative 
                     FROM gl_codes 
                     ORDER BY sort_order, sub_order";
    $res_skeleton = $conn->query($sql_skeleton);
    if ($res_skeleton && $res_skeleton->num_rows > 0) {
        while ($row = $res_skeleton->fetch_assoc()) {
            $key = $row['sort_order'] . '|' . $row['sub_order'];
            $row['mlfsi'] = 0;
            $row['jewelers'] = 0;
            $data[$key] = $row;
        }
    }
    return $data;
}

// Fetch distinct filter options for dropdowns
$mz_to_zn = [];
$zn_to_reg = [];
$distinct_mz = [];
$distinct_zn = [];
$distinct_reg = [];

$hierarchy_query = "SELECT DISTINCT mainzone, zone, region FROM manual_adjustment WHERE mainzone IS NOT NULL AND mainzone != '' ORDER BY mainzone, zone, region";
$hierarchy_res = $conn->query($hierarchy_query);
if ($hierarchy_res) {
    while ($h_row = $hierarchy_res->fetch_assoc()) {
        $mz = $h_row['mainzone'];
        $zn = $h_row['zone'] ?? '';
        $reg = $h_row['region'] ?? '';
        
        if (!in_array($mz, $distinct_mz)) $distinct_mz[] = $mz;
        if ($zn !== '' && !in_array($zn, $distinct_zn)) $distinct_zn[] = $zn;
        if ($reg !== '' && !in_array($reg, $distinct_reg)) $distinct_reg[] = $reg;

        if (!isset($mz_to_zn[$mz])) $mz_to_zn[$mz] = [];
        if ($zn !== '' && !in_array($zn, $mz_to_zn[$mz])) $mz_to_zn[$mz][] = $zn;
        if ($zn !== '' && !isset($zn_to_reg[$zn])) $zn_to_reg[$zn] = [];
        if ($zn !== '' && $reg !== '' && !in_array($reg, $zn_to_reg[$zn])) $zn_to_reg[$zn][] = $reg;
    }
}

// Determine which regions to display
$regions_to_display = [];
$skeleton_data = [];
if ($has_filters) {
    $regions_to_display = getRegionsToDisplay($conn, $mainzone, $zone, $region);
} else {
    $skeleton_data = getSkeletonData($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report with Adjustment</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="manual_adjustment_new.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">Comparative Report with Adjustment</div>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label>Main Zone</label>
                    <select name="mainzone" id="mainzoneSelect">
                        <option value="">All Main Zones</option>
                        <?php foreach($distinct_mz as $mz_val): ?>
                            <option value="<?= htmlspecialchars($mz_val) ?>" <?= $mainzone == $mz_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mz_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Zone</label>
                    <select name="zone" id="zoneSelect">
                        <option value="">All Zones</option>
                        <?php foreach($distinct_zn as $zn_val): ?>
                            <option value="<?= htmlspecialchars($zn_val) ?>" <?= $zone == $zn_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($zn_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Region</label>
                    <select name="region" id="regionSelect">
                        <option value="">All Regions</option>
                        <?php foreach($distinct_reg as $reg_val): ?>
                            <option value="<?= htmlspecialchars($reg_val) ?>" <?= $region == $reg_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($reg_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Transaction Month</label>
                    <input type="month" name="primary_period" required value="<?= htmlspecialchars($primary_period) ?>">
                </div>
                <p style="color:red; font-weight: bold;">VS</p>
                <div class="filter-group">
                    <label>Transaction Month</label>
                    <input type="month" name="previous_period" required value="<?= htmlspecialchars($previous_period) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply Filter</button>
                    <button type="button" class="btn-collapse"><i class="fa-solid fa-compress"></i> Collapse View</button>
                    <button type="button" class="btn-export" onclick="exportAllTables()"><i class="fa-solid fa-file-excel"></i> Export File</button>
                    <a href="?reset=1" class="btn-reset"><i class="fas fa-undo-alt"></i> Clear</a>
                </div>
            </form>

            <!-- Data Tables Container -->
            <div id="dataTablesContainer">
                <?php if ($has_filters && !empty($regions_to_display)): ?>
                    <?php foreach ($regions_to_display as $current_region): ?>
                        <?php
                        // Fetch data for primary and previous periods for this region
                        $primary_data = fetchRegionData($conn, $current_region, $primary_month, $primary_year, $mainzone, $zone);
                        $previous_data = fetchRegionData($conn, $current_region, $previous_month, $previous_year, $mainzone, $zone);
                        
                        // Check if there's any data for this region
                        $has_primary_data = !empty($primary_data);
                        $has_previous_data = !empty($previous_data);
                        
                        if (!$has_primary_data && !$has_previous_data) {
                            continue; // Skip regions with no data
                        }
                        
                        // Initialize calculation variables
                        $total_rev_mlfsi_primary = 0;
                        $total_rev_jewelers_primary = 0;
                        $total_rev_total_primary = 0;
                        $total_rev_mlfsi_previous = 0;
                        $total_rev_jewelers_previous = 0;
                        $total_rev_total_previous = 0;
                        
                        $total_sa_mlfsi_primary = 0;
                        $total_sa_jewelers_primary = 0;
                        $total_sa_total_primary = 0;
                        $total_sa_mlfsi_previous = 0;
                        $total_sa_jewelers_previous = 0;
                        $total_sa_total_previous = 0;
                        
                        $gp_mlfsi_primary = 0;
                        $gp_jewelers_primary = 0;
                        $gp_total_primary = 0;
                        $gp_mlfsi_previous = 0;
                        $gp_jewelers_previous = 0;
                        $gp_total_previous = 0;
                        
                        $ebitda_mlfsi_primary = 0;
                        $ebitda_jewelers_primary = 0;
                        $ebitda_total_primary = 0;
                        $ebitda_mlfsi_previous = 0;
                        $ebitda_jewelers_previous = 0;
                        $ebitda_total_previous = 0;
                        
                        $ebit_mlfsi_primary = 0;
                        $ebit_jewelers_primary = 0;
                        $ebit_total_primary = 0;
                        $ebit_mlfsi_previous = 0;
                        $ebit_jewelers_previous = 0;
                        $ebit_total_previous = 0;
                        
                        $ebt_mlfsi_primary = 0;
                        $ebt_jewelers_primary = 0;
                        $ebt_total_primary = 0;
                        $ebt_mlfsi_previous = 0;
                        $ebt_jewelers_previous = 0;
                        $ebt_total_previous = 0;
                        
                        // Collect all rows
                        $all_keys = array_unique(array_merge(array_keys($primary_data), array_keys($previous_data)));
                        usort($all_keys, function($a, $b) {
                            $partsA = explode('|', $a);
                            $partsB = explode('|', $b);
                            if ($partsA[0] != $partsB[0]) return (int)$partsA[0] - (int)$partsB[0];
                            return (int)$partsA[1] - (int)$partsB[1];
                        });
                        
                        $all_rows = [];
                        foreach ($all_keys as $key) {
                            $row = $primary_data[$key] ?? null;
                            $prev = $previous_data[$key] ?? null;
                            $display_row = $row ?: $prev;
                            
                            $s = (int)$display_row['sort_order'];
                            $o = (int)$display_row['sub_order'];
                            $is_special = ($s === 15 && $o === 2);

                            $primary_total = ($row ? $row['mlfsi'] : 0) + ($row ? $row['jewelers'] : 0);
                            $previous_total = $prev ? ($prev['mlfsi'] + $prev['jewelers']) : 0;
                                                        
                            $percent_change = 0;
                            if ($is_special) {
                                // Calculate percent change using flipped values for proper percentage display
                                $flipped_primary = -$primary_total;
                                $flipped_previous = -$previous_total;
                                
                                if ($flipped_previous != 0) {
                                    $inc_dec = $flipped_primary - $flipped_previous;
                                    $percent_change = ($inc_dec / $flipped_previous) * 100;
                                } elseif ($flipped_primary > 0) {
                                    $percent_change = 100;
                                }
                            } else {
                                // Calculate percentage as (Inc./Dec. / Previous Total) * 100
                                if ($previous_total != 0) {
                                    $inc_dec = $primary_total - $previous_total;
                                    $percent_change = ($inc_dec / $previous_total) * 100;
                                } elseif ($primary_total > 0) {
                                    $percent_change = 100;
                                }
                            }
                                                        
                            $all_rows[$display_row['sort_order']][] = [
                                'row' => $display_row,
                                'primary_val' => $row,
                                'prev' => $prev,
                                'primary_total' => $primary_total,
                                'previous_total' => $previous_total,
                                'percent_change' => $percent_change
                            ];
                        }
                        ?>
                        <div class="region-header">
                                <i class="fas fa-map-marker-alt"></i> 
                                Region: <?= htmlspecialchars($current_region) ?>
                                <?php if (!empty($mainzone)): ?>
                                    | Main Zone: <?= htmlspecialchars($mainzone) ?>
                                <?php endif; ?>
                                <?php if (!empty($zone)): ?>
                                    | Zone: <?= htmlspecialchars($zone) ?>
                                <?php endif; ?>
                            </div>
                            <br>
                        <div class="region-table-container" data-region="<?= htmlspecialchars($current_region) ?>">
                            
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th colspan="4"></th>
                                        <th colspan="10">
                                            <?= date('F Y', strtotime($primary_month)) ?> vs <?= date('F Y', strtotime($previous_month)) ?>
                                        </th>
                                    </tr>
                                    <tr>
                                        <th colspan="4"></th>
                                        <th colspan="3">(<?= date('F Y', strtotime($primary_month)) ?>)</th>
                                        <th></th>
                                        <th colspan="3">(<?= date('F Y', strtotime($previous_month)) ?>)</th>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                    </tr>
                                    <tr>
                                        <th>Sort Order</th>
                                        <th>Description</th>
                                        <th>Comparative Description</th>
                                        <th></th>
                                        <th>MLFSI</th>
                                        <th>JEWELERS</th>
                                        <th>TOTAL</th>
                                        <th></th>
                                        <th>MLFSI</th>
                                        <th>JEWELERS</th>
                                        <th>TOTAL</th>
                                        <th></th>
                                        <th>Inc./Dec.</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $group_footer_only_sorts = [6, 8, 11];
                                    foreach ($all_rows as $sort_order => $rows):
                                        // Calculate group totals
                                        $group_mlfsi_primary = 0;
                                        $group_jewelers_primary = 0;
                                        $group_total_primary = 0;
                                        $group_mlfsi_previous = 0;
                                        $group_jewelers_previous = 0;
                                        $group_total_previous = 0;
                                        
                                        foreach ($rows as $data) {
                                            $row = $data['primary_val'];
                                            $prev = $data['prev'];
                                            $s = (int)$data['row']['sort_order'];
                                            $o = (int)$data['row']['sub_order'];

                                            // Handle special case: treat sort_order 15 sub_order 2 as a deduction (subtract its absolute value)
                                            // Handle special case: sort_order 15 sub_order 2
                                            if ($s === 15 && $o === 2) {
                                                // Get the original values
                                                $ml_p_orig = $row ? $row['mlfsi'] : 0;
                                                $j_p_orig = $row ? $row['jewelers'] : 0;
                                                $t_p_orig = $data['primary_total'];
                                                $ml_prev_orig = $prev ? $prev['mlfsi'] : 0;
                                                $j_prev_orig = $prev ? $prev['jewelers'] : 0;
                                                $t_prev_orig = $data['previous_total'];
                                                
                                                // If original is negative, flip to positive and subtract (deduction)
                                                // If original is positive, keep positive and add
                                                $ml_p = $ml_p_orig < 0 ? abs($ml_p_orig) : $ml_p_orig;
                                                $j_p = $j_p_orig < 0 ? abs($j_p_orig) : $j_p_orig;
                                                $t_p = $t_p_orig < 0 ? abs($t_p_orig) : $t_p_orig;
                                                $ml_prev = $ml_prev_orig < 0 ? abs($ml_prev_orig) : $ml_prev_orig;
                                                $j_prev = $j_prev_orig < 0 ? abs($j_prev_orig) : $j_prev_orig;
                                                $t_prev = $t_prev_orig < 0 ? abs($t_prev_orig) : $t_prev_orig;
                                                
                                                // If original was negative, we subtract (deduction)
                                                // If original was positive, we add (income/addition)
                                                if ($ml_p_orig < 0) {
                                                    $group_mlfsi_primary -= $ml_p;
                                                } else {
                                                    $group_mlfsi_primary += $ml_p;
                                                }
                                                
                                                if ($j_p_orig < 0) {
                                                    $group_jewelers_primary -= $j_p;
                                                } else {
                                                    $group_jewelers_primary += $j_p;
                                                }
                                                
                                                if ($t_p_orig < 0) {
                                                    $group_total_primary -= $t_p;
                                                } else {
                                                    $group_total_primary += $t_p;
                                                }
                                                
                                                if ($ml_prev_orig < 0) {
                                                    $group_mlfsi_previous -= $ml_prev;
                                                } else {
                                                    $group_mlfsi_previous += $ml_prev;
                                                }
                                                
                                                if ($j_prev_orig < 0) {
                                                    $group_jewelers_previous -= $j_prev;
                                                } else {
                                                    $group_jewelers_previous += $j_prev;
                                                }
                                                
                                                if ($t_prev_orig < 0) {
                                                    $group_total_previous -= $t_prev;
                                                } else {
                                                    $group_total_previous += $t_prev;
                                                }
                                            } else {
                                                $group_mlfsi_primary += $row ? $row['mlfsi'] : 0;
                                                $group_jewelers_primary += $row ? $row['jewelers'] : 0;
                                                $group_total_primary += $data['primary_total'];
                                                $group_mlfsi_previous += $prev ? $prev['mlfsi'] : 0;
                                                $group_jewelers_previous += $prev ? $prev['jewelers'] : 0;
                                                $group_total_previous += $data['previous_total'];
                                            }
                                        }
                                        
                                        // Accumulate totals
                                        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
                                            $total_rev_mlfsi_primary += $group_mlfsi_primary;
                                            $total_rev_jewelers_primary += $group_jewelers_primary;
                                            $total_rev_total_primary += $group_total_primary;
                                            $total_rev_mlfsi_previous += $group_mlfsi_previous;
                                            $total_rev_jewelers_previous += $group_jewelers_previous;
                                            $total_rev_total_previous += $group_total_previous;
                                        }
                                        
                                        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
                                            $total_sa_mlfsi_primary += $group_mlfsi_primary;
                                            $total_sa_jewelers_primary += $group_jewelers_primary;
                                            $total_sa_total_primary += $group_total_primary;
                                            $total_sa_mlfsi_previous += $group_mlfsi_previous;
                                            $total_sa_jewelers_previous += $group_jewelers_previous;
                                            $total_sa_total_previous += $group_total_previous;
                                        }
                                        
                                        $group_variance = $group_total_primary - $group_total_previous;
                                        $group_percent_change = 0;
                                        if ($group_total_previous > 0) {
                                            $group_percent_change = ($group_variance / $group_total_previous) * 100;
                                        } elseif ($group_total_primary > 0) {
                                            $group_percent_change = 100;
                                        }
                                        
                                        $group_pct_display = ($group_percent_change < 0 ? '-' : '') . number_format(abs($group_percent_change), 2) . '%';
                                        $group_pct_style = $group_percent_change < 0 ? 'color: red;' : '';
                                        if ($group_percent_change >= 1000) {
                                            $group_pct_display = 'mat';
                                            $group_pct_style = 'color: black;';
                                        } elseif ($group_percent_change <= -1000) {
                                            $group_pct_display = 'mat';
                                            $group_pct_style = 'color: red;';
                                        }
                                        
                                        $first_description = $rows[0]['row']['description'];
                                    ?>
                                        <?php if (!in_array((int)$sort_order, $group_footer_only_sorts)): ?>
                                            <?php foreach ($rows as $data): 
                                                $display_row = $data['row'];
                                                $row = $data['primary_val'];
                                                $prev = $data['prev'];
                                                $primary_total = $data['primary_total'];
                                                $previous_total = $data['previous_total'];
                                                $percent_change = $data['percent_change'];
                                                
                                              $s = (int)$display_row['sort_order'];
                                                $o = (int)$display_row['sub_order'];
                                                $is_special = ($s === 15 && $o === 2);

                                                if ($is_special) {
                                                    // FLIP THE SIGNS FOR DISPLAY: negative becomes positive, positive becomes negative
                                                    $orig_ml_cur = $row ? $row['mlfsi'] : 0;
                                                    $orig_jew_cur = $row ? $row['jewelers'] : 0;
                                                    $orig_tot_cur = $primary_total;
                                                    $orig_ml_prev = $prev ? $prev['mlfsi'] : 0;
                                                    $orig_jew_prev = $prev ? $prev['jewelers'] : 0;
                                                    $orig_tot_prev = $previous_total;
                                                    
                                                    // FLIP THE SIGNS for display values
                                                    $disp_ml_cur = -$orig_ml_cur;
                                                    $disp_jew_cur = -$orig_jew_cur;
                                                    $disp_tot_cur = -$orig_tot_cur;
                                                    $disp_ml_prev = -$orig_ml_prev;
                                                    $disp_jew_prev = -$orig_jew_prev;
                                                    $disp_tot_prev = -$orig_tot_prev;
                                                    
                                                    // Calculate variance based on flipped values (this will give you -129.72 + (-513,806.26) = -513,935.98)
                                                    $disp_diff = $disp_tot_cur - $disp_tot_prev;
                                                } else {
                                                    $disp_ml_cur = $row ? $row['mlfsi'] : 0;
                                                    $disp_jew_cur = $row ? $row['jewelers'] : 0;
                                                    $disp_tot_cur = $primary_total;
                                                    $disp_ml_prev = $prev ? $prev['mlfsi'] : 0;
                                                    $disp_jew_prev = $prev ? $prev['jewelers'] : 0;
                                                    $disp_tot_prev = $previous_total;
                                                    $disp_diff = $primary_total - $previous_total;
                                                }

                                                $pct_display = ($percent_change < 0 ? '-' : '') . number_format(abs($percent_change), 2) . '%';
                                                $pct_style = $percent_change < 0 ? 'color: red;' : '';
                                                if (abs($percent_change) >= 1000) {
                                                    $pct_display = 'mat';
                                                    $pct_style = ($percent_change < 0) ? 'color: red;' : 'color: black;';
                                                }
                                            ?>
                                                <tr class="detail-row" data-sort="<?= (int)$sort_order ?>">
                                                    <td></td>
                                                    <td></td>
                                                    <td><?= htmlspecialchars($display_row['gl_description_comparative']) ?></td>
                                                    <td></td>
                                                    <td style="text-align: right;<?= ($disp_ml_cur < 0) ? ' color: red;' : '' ?>"><?= $row ? number_format($disp_ml_cur, 2) : '-' ?></td>
                                                    <td style="text-align: right;<?= ($disp_jew_cur < 0) ? ' color: red;' : '' ?>"><?= $row ? number_format($disp_jew_cur, 2) : '-' ?></td>
                                                    <td style="text-align: right;<?= ($disp_tot_cur < 0) ? ' color: red;' : '' ?>"><?= number_format($disp_tot_cur, 2) ?></td>
                                                    <td></td>
                                                    <td style="text-align: right;<?= ($disp_ml_prev < 0) ? ' color: red;' : '' ?>"><?= $prev ? number_format($disp_ml_prev, 2) : '-' ?></td>
                                                    <td style="text-align: right;<?= ($disp_jew_prev < 0) ? ' color: red;' : '' ?>"><?= $prev ? number_format($disp_jew_prev, 2) : '-' ?></td>
                                                    <td style="text-align: right;<?= ($disp_tot_prev < 0) ? ' color: red;' : '' ?>"><?= number_format($disp_tot_prev, 2) ?></td>
                                                    <td></td>
                                                    <td style="text-align: right;<?= ($disp_diff < 0) ? ' color: red;' : '' ?>"><?= number_format($disp_diff, 2) ?></td>
                                                    <td style="text-align: right; <?= $pct_style ?>"><?= $pct_display ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <!-- category total row -->
                                        <?php if (!in_array((int)$sort_order, [24, 25, 26])): ?>
                                            <tr style="font-weight: bold; background-color: #ffdbc7;">
                                                <td><?= htmlspecialchars($sort_order) ?></td>
                                                <td><?= htmlspecialchars($first_description) ?></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_mlfsi_primary < 0) ? ' color: red;' : '' ?>"><?= number_format($group_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_jewelers_primary < 0) ? ' color: red;' : '' ?>"><?= number_format($group_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_total_primary < 0) ? ' color: red;' : '' ?>"><?= number_format($group_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_mlfsi_previous < 0) ? ' color: red;' : '' ?>"><?= number_format($group_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_jewelers_previous < 0) ? ' color: red;' : '' ?>"><?= number_format($group_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_total_previous < 0) ? ' color: red;' : '' ?>"><?= number_format($group_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($group_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($group_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $group_pct_style ?>"><?= $group_pct_display ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <tr class="spacer-row" data-sort="<?= (int)$sort_order ?>" style="height: 20px;">
                                            <td colspan="14" style="border-bottom: 1px solid #e2e8f0;"></td>
                                        </tr>
                                        
                                        <?php if ((int)$sort_order == 20): 
                                            $rev_variance = $total_rev_total_primary - $total_rev_total_previous;
                                            $rev_percent_change = 0;
                                            if ($total_rev_total_previous > 0) {
                                                $rev_percent_change = ($rev_variance / $total_rev_total_previous) * 100;
                                            } elseif ($total_rev_total_primary > 0) {
                                                $rev_percent_change = 100;
                                            }
                                            $rev_pct_display = ($rev_percent_change < 0 ? '-' : '') . number_format(abs($rev_percent_change), 2) . '%';
                                            $rev_pct_style = $rev_percent_change < 0 ? 'color: red;' : '';
                                            if ($rev_percent_change >= 1000) {
                                                $rev_pct_display = 'mat';
                                                $rev_pct_style = 'color: black;';
                                            } elseif ($rev_percent_change <= -1000) {
                                                $rev_pct_display = 'mat';
                                                $rev_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>TOTAL REVENUES</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_rev_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($rev_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($rev_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $rev_pct_style ?>"><?= $rev_pct_display ?></td>
                                            </tr>
                                            <tr class="spacer-row" style="height: 20px;">
                                                <td colspan="14" style="border-bottom: 1px solid #e2e8f0;"></td>
                                            </tr>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td style="border-bottom: 1px solid #e2e8f0;">Cost of Sales/Service</td>
                                                <td colspan="13"></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ((int)$sort_order == 21): 
                                            $gp_mlfsi_primary = $total_rev_mlfsi_primary - $group_mlfsi_primary;
                                            $gp_jewelers_primary = $total_rev_jewelers_primary - $group_jewelers_primary;
                                            $gp_total_primary = $total_rev_total_primary - $group_total_primary;
                                            $gp_mlfsi_previous = $total_rev_mlfsi_previous - $group_mlfsi_previous;
                                            $gp_jewelers_previous = $total_rev_jewelers_previous - $group_jewelers_previous;
                                            $gp_total_previous = $total_rev_total_previous - $group_total_previous;
                                            
                                            $gp_variance = $gp_total_primary - $gp_total_previous;
                                            $gp_percent_change = 0;
                                            if ($gp_total_previous > 0) {
                                                $gp_percent_change = ($gp_variance / $gp_total_previous) * 100;
                                            } elseif ($gp_total_primary > 0) {
                                                $gp_percent_change = 100;
                                            }
                                            $gp_pct_display = ($gp_percent_change < 0 ? '-' : '') . number_format(abs($gp_percent_change), 2) . '%';
                                            $gp_pct_style = $gp_percent_change < 0 ? 'color: red;' : '';
                                            if ($gp_percent_change >= 1000) {
                                                $gp_pct_display = 'mat';
                                                $gp_pct_style = 'color: black;';
                                            } elseif ($gp_percent_change <= -1000) {
                                                $gp_pct_display = 'mat';
                                                $gp_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>GROSS PROFIT</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($gp_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($gp_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($gp_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $gp_pct_style ?>"><?= $gp_pct_display ?></td>
                                            </tr>
                                            <tr class="spacer-row" style="height: 20px;">
                                                <td colspan="14" style="border-bottom: 1px solid #e2e8f0;"></td>
                                            </tr>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td style="border-bottom: 1px solid #e2e8f0;">SELLING & ADMIN EXPENSE</td>
                                                <td colspan="13"></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ((int)$sort_order == 23): 
                                            $sa_variance = $total_sa_total_primary - $total_sa_total_previous;
                                            $sa_percent_change = 0;
                                            if ($total_sa_total_previous != 0) {
                                                $sa_percent_change = ($sa_variance / $total_sa_total_previous) * 100;
                                            } elseif ($total_sa_total_primary > 0) {
                                                $sa_percent_change = 100;
                                            }
                                            $sa_pct_display = ($sa_percent_change < 0 ? '-' : '') . number_format(abs($sa_percent_change), 2) . '%';
                                            $sa_pct_style = $sa_percent_change < 0 ? 'color: red;' : '';
                                            if ($sa_percent_change >= 1000) {
                                                $sa_pct_display = 'mat';
                                                $sa_pct_style = 'color: black;';
                                            } elseif ($sa_percent_change <= -1000) {
                                                $sa_pct_display = 'mat';
                                                $sa_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>TOTAL SELLING AND ADMIN EXPENSES</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($total_sa_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($sa_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($sa_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $sa_pct_style ?>"><?= $sa_pct_display ?></td>
                                            </tr>
                                            <tr class="spacer-row" style="height: 20px;">
                                                <td colspan="14" style="border-bottom: 1px solid #e2e8f0;"></td>
                                            </tr>
                                            <?php
                                            $ebitda_mlfsi_primary = $gp_mlfsi_primary - $total_sa_mlfsi_primary;
                                            $ebitda_jewelers_primary = $gp_jewelers_primary - $total_sa_jewelers_primary;
                                            $ebitda_total_primary = $gp_total_primary - $total_sa_total_primary;
                                            $ebitda_mlfsi_previous = $gp_mlfsi_previous - $total_sa_mlfsi_previous;
                                            $ebitda_jewelers_previous = $gp_jewelers_previous - $total_sa_jewelers_previous;
                                            $ebitda_total_previous = $gp_total_previous - $total_sa_total_previous;
                                            
                                            $ebitda_variance = $ebitda_total_primary - $ebitda_total_previous;
                                            $ebitda_percent_change = 0;
                                            if ($ebitda_total_previous != 0) {
                                                $ebitda_percent_change = ($ebitda_variance / $ebitda_total_previous) * 100;
                                            } elseif ($ebitda_total_primary > 0) {
                                                $ebitda_percent_change = 100;
                                            }
                                            $ebitda_pct_display = ($ebitda_percent_change < 0 ? '-' : '') . number_format(abs($ebitda_percent_change), 2) . '%';
                                            $ebitda_pct_style = $ebitda_percent_change < 0 ? 'color: red;' : '';
                                            if ($ebitda_percent_change >= 1000) {
                                                $ebitda_pct_display = 'mat';
                                                $ebitda_pct_style = 'color: black;';
                                            } elseif ($ebitda_percent_change <= -1000) {
                                                $ebitda_pct_display = 'mat';
                                                $ebitda_pct_style = 'color: red;';
                                            }
                                            ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebitda_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($ebitda_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($ebitda_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $ebitda_pct_style ?>"><?= $ebitda_pct_display ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ((int)$sort_order == 24): 
                                            $ebit_mlfsi_primary = $ebitda_mlfsi_primary - $group_mlfsi_primary;
                                            $ebit_jewelers_primary = $ebitda_jewelers_primary - $group_jewelers_primary;
                                            $ebit_total_primary = $ebitda_total_primary - $group_total_primary;
                                            $ebit_mlfsi_previous = $ebitda_mlfsi_previous - $group_mlfsi_previous;
                                            $ebit_jewelers_previous = $ebitda_jewelers_previous - $group_jewelers_previous;
                                            $ebit_total_previous = $ebitda_total_previous - $group_total_previous;
                                            
                                            $ebit_variance = $ebit_total_primary - $ebit_total_previous;
                                            $ebit_percent_change = 0;
                                            if ($ebit_total_previous != 0) {
                                                $ebit_percent_change = ($ebit_variance / $ebit_total_previous) * 100;
                                            } elseif ($ebit_total_primary > 0) {
                                                $ebit_percent_change = 100;
                                            }
                                            $ebit_pct_display = ($ebit_percent_change < 0 ? '-' : '') . number_format(abs($ebit_percent_change), 2) . '%';
                                            $ebit_pct_style = $ebit_percent_change < 0 ? 'color: red;' : '';
                                            if ($ebit_percent_change >= 1000) {
                                                $ebit_pct_display = 'mat';
                                                $ebit_pct_style = 'color: black;';
                                            } elseif ($ebit_percent_change <= -1000) {
                                                $ebit_pct_display = 'mat';
                                                $ebit_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>EARNINGS BEFORE INTEREST & TAXES</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebit_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($ebit_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($ebit_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $ebit_pct_style ?>"><?= $ebit_pct_display ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ((int)$sort_order == 25): 
                                            $ebt_mlfsi_primary = $ebit_mlfsi_primary - $group_mlfsi_primary;
                                            $ebt_jewelers_primary = $ebit_jewelers_primary - $group_jewelers_primary;
                                            $ebt_total_primary = $ebit_total_primary - $group_total_primary;
                                            $ebt_mlfsi_previous = $ebit_mlfsi_previous - $group_mlfsi_previous;
                                            $ebt_jewelers_previous = $ebit_jewelers_previous - $group_jewelers_previous;
                                            $ebt_total_previous = $ebit_total_previous - $group_total_previous;
                                            
                                            $ebt_variance = $ebt_total_primary - $ebt_total_previous;
                                            $ebt_percent_change = 0;
                                            if ($ebt_total_previous != 0) {
                                                $ebt_percent_change = ($ebt_variance / $ebt_total_previous) * 100;
                                            } elseif ($ebt_total_primary > 0) {
                                                $ebt_percent_change = 100;
                                            }
                                            $ebt_pct_display = ($ebt_percent_change < 0 ? '-' : '') . number_format(abs($ebt_percent_change), 2) . '%';
                                            $ebt_pct_style = $ebt_percent_change < 0 ? 'color: red;' : '';
                                            if ($ebt_percent_change >= 1000) {
                                                $ebt_pct_display = 'mat';
                                                $ebt_pct_style = 'color: black;';
                                            } elseif ($ebt_percent_change <= -1000) {
                                                $ebt_pct_display = 'mat';
                                                $ebt_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>EARNINGS BEFORE TAXES</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($ebt_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($ebt_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($ebt_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $ebt_pct_style ?>"><?= $ebt_pct_display ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php if ((int)$sort_order == 26): 
                                            $net_mlfsi_primary = $ebt_mlfsi_primary - $group_mlfsi_primary;
                                            $net_jewelers_primary = $ebt_jewelers_primary - $group_jewelers_primary;
                                            $net_total_primary = $ebt_total_primary - $group_total_primary;
                                            $net_mlfsi_previous = $ebt_mlfsi_previous - $group_mlfsi_previous;
                                            $net_jewelers_previous = $ebt_jewelers_previous - $group_jewelers_previous;
                                            $net_total_previous = $ebt_total_previous - $group_total_previous;
                                            
                                            $net_variance = $net_total_primary - $net_total_previous;
                                            $net_percent_change = 0;
                                            if ($net_total_previous != 0) {
                                                $net_percent_change = ($net_variance / $net_total_previous) * 100;
                                            } elseif ($net_total_primary > 0) {
                                                $net_percent_change = 100;
                                            }
                                            $net_pct_display = ($net_percent_change < 0 ? '-' : '') . number_format(abs($net_percent_change), 2) . '%';
                                            $net_pct_style = $net_percent_change < 0 ? 'color: red;' : '';
                                            if ($net_percent_change >= 1000) {
                                                $net_pct_display = 'mat';
                                                $net_pct_style = 'color: black;';
                                            } elseif ($net_percent_change <= -1000) {
                                                $net_pct_display = 'mat';
                                                $net_pct_style = 'color: red;';
                                            }
                                        ?>
                                            <tr style="font-weight: bold; background-color: #ffad76; color: #000;">
                                                <td>TOTAL NET INCOME/LOSS</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_mlfsi_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_jewelers_primary, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_total_primary, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_mlfsi_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_jewelers_previous, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold;"><?= number_format($net_total_previous, 2) ?></td>
                                                <td></td>
                                                <td style="text-align: right; font-weight: bold;<?= ($net_variance < 0) ? ' color: red;' : '' ?>"><?= number_format($net_variance, 2) ?></td>
                                                <td style="text-align: right; font-weight: bold; <?= $net_pct_style ?>"><?= $net_pct_display ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($regions_to_display)): ?>
                        <div class="no-data-message">
                            <i class="fas fa-info-circle"></i> No data found for the selected filters.
                        </div>
                    <?php endif; ?>
                    
                <?php elseif (!$has_filters): ?>
                    <div class="no-data-message">
                        <i class="fas fa-filter"></i> Please select filters and click "Compare" to view comparative report.
                    </div>
                <?php else: ?>
                    <div class="no-data-message">
                        <i class="fas fa-database"></i> No data available for the selected criteria.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Auto-sort months to ensure primary is more recent than previous
        const primaryInput = document.querySelector('input[name="primary_period"]');
        const previousInput = document.querySelector('input[name="previous_period"]');
        
        function validateMonthOrder() {
            if (primaryInput && previousInput && primaryInput.value && previousInput.value) {
                const primaryDate = new Date(primaryInput.value + "-01");
                const previousDate = new Date(previousInput.value + "-01");
                if (primaryDate < previousDate) {
                    const tempValue = primaryInput.value;
                    primaryInput.value = previousInput.value;
                    previousInput.value = tempValue;
                    console.log('Months were swapped to ensure primary is more recent');
                }
            }
        }
        
        if (primaryInput && previousInput) {
            primaryInput.addEventListener('change', validateMonthOrder);
            previousInput.addEventListener('change', validateMonthOrder);
        }

        // Collapse/Uncollapse functionality for all tables
        document.addEventListener('DOMContentLoaded', function() {
            const collapseBtn = document.querySelector('.btn-collapse');
            if (collapseBtn) {
                let isCollapsed = false;
                collapseBtn.addEventListener('click', function() {
                    isCollapsed = !isCollapsed;
                    const allDetailRows = document.querySelectorAll('#dataTablesContainer .detail-row');
                    const allSpacerRows = document.querySelectorAll('#dataTablesContainer .spacer-row');
                    
                    allDetailRows.forEach(row => {
                        const sort = parseInt(row.getAttribute('data-sort')) || 0;
                        if (sort >= 1 && sort <= 20) {
                            row.style.display = isCollapsed ? 'none' : '';
                        }
                    });
                    
                    allSpacerRows.forEach(row => {
                        const sort = parseInt(row.getAttribute('data-sort')) || 0;
                        if (sort >= 1 && sort <= 20) {
                            row.style.display = isCollapsed ? 'none' : '';
                        }
                    });
                    
                    collapseBtn.innerHTML = isCollapsed 
                        ? '<i class="fa-solid fa-expand"></i> Uncollapse View' 
                        : '<i class="fa-solid fa-compress"></i> Collapse View';
                });
            }
        });

        // Cascading Dropdowns Logic
        (function() {
            const mzToZn = <?php echo json_encode($mz_to_zn); ?>;
            const znToReg = <?php echo json_encode($zn_to_reg); ?>;
            
            const mzSelect = document.getElementById('mainzoneSelect');
            const znSelect = document.getElementById('zoneSelect');
            const regSelect = document.getElementById('regionSelect');

            if (!mzSelect || !znSelect || !regSelect) return;

            function updateZones() {
                const selectedMz = mzSelect.value;
                const currentZn = znSelect.value;
                
                znSelect.innerHTML = '<option value="">All Zones</option>';
                
                let zonesToShow = [];
                if (selectedMz && mzToZn[selectedMz]) {
                    zonesToShow = mzToZn[selectedMz];
                } else {
                    const allZn = new Set();
                    Object.values(mzToZn).forEach(arr => arr.forEach(z => allZn.add(z)));
                    zonesToShow = Array.from(allZn).sort();
                }

                zonesToShow.forEach(zn => {
                    const opt = document.createElement('option');
                    opt.value = zn;
                    opt.textContent = zn;
                    if (zn === currentZn) opt.selected = true;
                    znSelect.appendChild(opt);
                });
            }

            function updateRegions() {
                const selectedZn = znSelect.value;
                const selectedMz = mzSelect.value;
                const currentReg = regSelect.value;
                
                regSelect.innerHTML = '<option value="">All Regions</option>';
                
                let regionsToShow = [];
                if (selectedZn && znToReg[selectedZn]) {
                    regionsToShow = znToReg[selectedZn];
                } else if (selectedMz && mzToZn[selectedMz]) {
                    const regionsSet = new Set();
                    mzToZn[selectedMz].forEach(zn => {
                        if (znToReg[zn]) znToReg[zn].forEach(r => regionsSet.add(r));
                    });
                    regionsToShow = Array.from(regionsSet).sort();
                } else {
                    const allReg = new Set();
                    Object.values(znToReg).forEach(arr => arr.forEach(r => allReg.add(r)));
                    regionsToShow = Array.from(allReg).sort();
                }

                regionsToShow.forEach(reg => {
                    const opt = document.createElement('option');
                    opt.value = reg;
                    opt.textContent = reg;
                    if (reg === currentReg) opt.selected = true;
                    regSelect.appendChild(opt);
                });
            }

            mzSelect.addEventListener('change', function() {
                updateZones();
                updateRegions();
            });

            znSelect.addEventListener('change', function() {
                updateRegions();
            });

            if (mzSelect.value) updateZones();
            if (znSelect.value || mzSelect.value) updateRegions();
        })();

        // Export all tables to CSV
        function exportAllTables() {
            const form = document.getElementById('filterForm');
            if (!form) return;
            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();
            window.location.href = 'export_comparative_adjustment.php?' + params;
        }
    </script>
<?php include '../footer.php'; ?>

</body>
</html>

<?php
// Close connection
$conn->close();
?>