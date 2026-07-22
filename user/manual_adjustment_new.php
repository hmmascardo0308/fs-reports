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

// Filters — only Region and single transaction month
$selected_regions = $_GET['region'] ?? [];
$transaction_month = $_GET['transaction_month'] ?? ''; // YYYY-MM
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Error messages for validation
$error_message = '';

// Helper function to check if a month is March 2026 or earlier
function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

// Helper function to check if a month is April 2026 or later
function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

// Validate GL code mode vs selected month
$show_error = false;
$valid_filters = false;

if (!empty($transaction_month)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($transaction_month)) {
            $error_message = 'Old GL Code is only available for March 2026 and earlier.';
            $show_error = true;
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($transaction_month)) {
            $error_message = 'New GL Code is only available for April 2026 onwards.';
            $show_error = true;
        }
    }

    if (!$show_error) {
        $valid_filters = true;
    }
}

if (!is_array($selected_regions)) {
    $selected_regions = $selected_regions !== '' ? [$selected_regions] : [];
}
$selected_regions = array_values(array_filter(array_map('trim', $selected_regions), fn($v) => $v !== ''));

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: manual_adjustment_new.php");
    exit;
}

// Dropdown options — Region only
$distinct_reg  = [];
$reg_to_area   = []; // kept for internal area resolution if needed
$distinct_years = [];

$hierarchy_query = "
    SELECT DISTINCT region, area
    FROM fs_reports.comparative_report
    WHERE region IS NOT NULL AND region != ''
    ORDER BY region, area
";
$hierarchy_res = mysqli_query($conn, $hierarchy_query);
if ($hierarchy_res) {
    while ($h = mysqli_fetch_assoc($hierarchy_res)) {
        $rg = trim((string)($h['region'] ?? ''));
        $ar = trim((string)($h['area'] ?? ''));

        if ($rg !== '' && !in_array($rg, $distinct_reg, true)) $distinct_reg[] = $rg;

        if ($rg !== '' && $ar !== '') {
            $reg_to_area[$rg] = $reg_to_area[$rg] ?? [];
            if (!in_array($ar, $reg_to_area[$rg], true)) $reg_to_area[$rg][] = $ar;
        }
    }
}
sort($distinct_reg);

// ============================================================
// GET GL MAPPING: sort_order + sub_order -> gl_id -> gl_codes
// ============================================================
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$gl_ids = [];
$special_keys = [];

// Determine which table to use based on GL code mode
$gl_table = ($gl_code_mode === 'new') ? 'new_gl_codes' : 'gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM fs_reports.{$gl_table}
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";
$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];

        if ($row['gl_id'] === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? $row['gl_description'] ?? '';
            $gl_ids[$key] = $row['gl_id'] ?? '';
        }

        // For old GL code, just use gl_code directly
        // For new GL code, use gl_code (since it's from new_gl_codes table)
        $resolved_gl_code = trim((string)($row['gl_code'] ?? ''));
        
        if ($resolved_gl_code !== '' && !in_array($resolved_gl_code, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $resolved_gl_code;
        }

        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// ============================================================
// COMPUTE TABLE ROWS — single period, region only
// ============================================================
function compute_table_rows_for_region(
    mysqli $conn,
    string $transaction_month,
    array  $gl_mapping,
    array  $gl_descriptions,
    array  $gl_ids,
    array  $special_keys,
    array  $sort_order_descriptions,
    string $region,
    bool   $use_real_data = true
): array {
    // Build WHERE clause
    $where_conditions = [];
    $params = [];
    $types  = "";

    if (!empty($region)) {
        $where_conditions[] = "region = ?";
        $params[] = $region;
        $types   .= "s";
    }

    $base_where  = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE 1=1";
    $base_where .= " AND (status_void IS NULL OR status_void != 'Void')";

    // Fetch period data
    $period_data = []; // gl_code -> [mlfsi, jewelers, total]
    if ($use_real_data && !empty($transaction_month)) {
        $parts       = explode('-', $transaction_month);
        $t_year      = $parts[0];
        $t_month_val = $transaction_month . '-01';

        $sql = "
            SELECT
                gl_code,
                SUM(CASE WHEN transaction_type = 'Branch'   THEN amount ELSE 0 END) AS branch_amount,
                SUM(CASE WHEN transaction_type = 'Showroom' THEN amount ELSE 0 END) AS showroom_amount
            FROM fs_reports.comparative_report
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        $query_params = array_merge($params, [$t_year, $t_month_val]);
        $query_types  = $types . "ss";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            if (!empty($query_params)) {
                mysqli_stmt_bind_param($stmt, $query_types, ...$query_params);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $period_data[$row['gl_code']] = [
                    'mlfsi'    => floatval($row['branch_amount']),
                    'jewelers' => floatval($row['showroom_amount']),
                    'total'    => floatval($row['branch_amount']) + floatval($row['showroom_amount']),
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Build detail rows
    $table_rows = [];
    foreach ($gl_mapping as $key => $gl_codes) {
        [$sort_order, $sub_order] = explode('|', $key);
        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2        = in_array($key, $special_keys);

        $gl_id    = $gl_ids[$key] ?? '';
        $mlfsi    = 0;
        $jewelers = 0;
        foreach ($gl_codes as $gl_code) {
            if (isset($period_data[$gl_code])) {
                $mlfsi    += $period_data[$gl_code]['mlfsi'];
                $jewelers += $period_data[$gl_code]['jewelers'];
            }
        }
        $total = $mlfsi + $jewelers;

        $table_rows[] = [
            'sort_order'       => $sort_order,
            'sub_order'        => $sub_order,
            'description'      => $sort_order_descriptions[$sort_order] ?? '',
            'gl_description'   => $gl_description,
            'is_section_header'=> false,
            'is_summary_row'   => false,
            'mlfsi'            => $mlfsi,
            'gl_id'            => $gl_id,
            'jewelers'         => $jewelers,
            'total'            => $total,
            'is_inj2'          => $is_inj2,
        ];
    }

    // Group by sort_order and add summary/computed rows
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $grouped_rows[$row['sort_order']][] = $row;
    }

    $final_rows = [];

    // Cumulative accumulators
    $rev_mlfsi    = 0; $rev_jew    = 0; $rev_tot    = 0;
    $sa_mlfsi     = 0; $sa_jew     = 0; $sa_tot     = 0;
    $gp_mlfsi     = 0; $gp_jew     = 0; $gp_tot     = 0;
    $ebitda_mlfsi = 0; $ebitda_jew = 0; $ebitda_tot = 0;
    $ebit_mlfsi   = 0; $ebit_jew   = 0; $ebit_tot   = 0;
    $ebt_mlfsi    = 0; $ebt_jew    = 0; $ebt_tot    = 0;

    foreach ($grouped_rows as $sort_order => $rows) {
        // Emit detail rows (skip sort orders 6, 8, 11)
        if (!in_array((int)$sort_order, [6, 8, 11])) {
            foreach ($rows as $row) {
                $final_rows[] = $row;
            }
        }

        $tot_mlfsi = array_sum(array_column($rows, 'mlfsi'));
        $tot_jew   = array_sum(array_column($rows, 'jewelers'));
        $tot_total = array_sum(array_column($rows, 'total'));

        // Accumulate revenues (sort 1-20)
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $rev_mlfsi += $tot_mlfsi;
            $rev_jew   += $tot_jew;
            $rev_tot   += $tot_total;
        }

        // Accumulate selling & admin (sort 22, 23)
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
            $sa_mlfsi += $tot_mlfsi;
            $sa_jew   += $tot_jew;
            $sa_tot   += $tot_total;
        }

        $description = $sort_order_descriptions[$sort_order] ?? "Total for Sort Order $sort_order";

        // Emit summary row (hide 24, 25, 26)
        if (!in_array((int)$sort_order, [24, 25, 26])) {
            $final_rows[] = [
                'sort_order'        => $sort_order,
                'sub_order'         => '',
                'gl_description'    => $description,
                'is_section_header' => false,
                'is_summary_row'    => true,
                'mlfsi'             => $tot_mlfsi,
                'jewelers'          => $tot_jew,
                'total'             => $tot_total,
            ];
        }

        // ---- TOTAL REVENUES (after sort 20) ----
        if ((int)$sort_order == 20) {
            $final_rows[] = [
                'sort_order'        => 'TOTAL REVENUES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'mlfsi'             => $rev_mlfsi,
                'jewelers'          => $rev_jew,
                'total'             => $rev_tot,
            ];
            $final_rows[] = [
                'sort_order'        => '',
                'sub_order'         => 'Cost of Sales/Service',
                'gl_description'    => '',
                'is_section_header' => true,
                'is_summary_row'    => true,
                'mlfsi'             => null,
                'jewelers'          => null,
                'total'             => null,
            ];
        }

        // ---- GROSS PROFIT (after sort 21) ----
        if ((int)$sort_order == 21) {
            $gp_mlfsi = $rev_mlfsi - $tot_mlfsi;
            $gp_jew   = $rev_jew   - $tot_jew;
            $gp_tot   = $rev_tot   - $tot_total;

            $final_rows[] = [
                'sort_order'        => 'GROSS PROFIT',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'mlfsi'             => $gp_mlfsi,
                'jewelers'          => $gp_jew,
                'total'             => $gp_tot,
            ];
            $final_rows[] = [
                'sort_order'        => 'SELLING & ADMIN EXPENSE',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => true,
                'is_summary_row'    => true,
                'mlfsi'             => null,
                'jewelers'          => null,
                'total'             => null,
            ];
        }

        // ---- TOTAL S&A + EBITDA (after sort 23) ----
        if ((int)$sort_order == 23) {
            $final_rows[] = [
                'sort_order'        => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'mlfsi'             => $sa_mlfsi,
                'jewelers'          => $sa_jew,
                'total'             => $sa_tot,
            ];

            $ebitda_mlfsi = $gp_mlfsi - $sa_mlfsi;
            $ebitda_jew   = $gp_jew   - $sa_jew;
            $ebitda_tot   = $gp_tot   - $sa_tot;

            $final_rows[] = [
                'sort_order'        => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'mlfsi'             => $ebitda_mlfsi,
                'jewelers'          => $ebitda_jew,
                'total'             => $ebitda_tot,
            ];
        }

        // ---- EBIT (after sort 24) ----
        if ((int)$sort_order == 24) {
            $ebit_mlfsi = $ebitda_mlfsi - $tot_mlfsi;
            $ebit_jew   = $ebitda_jew   - $tot_jew;
            $ebit_tot   = $ebitda_tot   - $tot_total;

            $final_rows[] = ['is_manual_spacer' => true];
            $final_rows[] = [
                'sort_order'        => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'mlfsi'             => $ebit_mlfsi,
                'jewelers'          => $ebit_jew,
                'total'             => $ebit_tot,
            ];
        }

        // ---- EBT (after sort 25) ----
        if ((int)$sort_order == 25) {
            $ebt_mlfsi = $ebit_mlfsi - $tot_mlfsi;
            $ebt_jew   = $ebit_jew   - $tot_jew;
            $ebt_tot   = $ebit_tot   - $tot_total;

            $final_rows[] = ['is_manual_spacer' => true];
            $final_rows[] = [
                'sort_order'        => 'EARNINGS BEFORE TAXES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'mlfsi'             => $ebt_mlfsi,
                'jewelers'          => $ebt_jew,
                'total'             => $ebt_tot,
            ];
        }

        // ---- NET INCOME/LOSS (after sort 26) ----
        if ((int)$sort_order == 26) {
            $net_mlfsi = $ebt_mlfsi - $tot_mlfsi;
            $net_jew   = $ebt_jew   - $tot_jew;
            $net_tot   = $ebt_tot   - $tot_total;

            $final_rows[] = ['is_manual_spacer' => true];
            $final_rows[] = [
                'sort_order'        => 'TOTAL NET INCOME/LOSS',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'mlfsi'             => $net_mlfsi,
                'jewelers'          => $net_jew,
                'total'             => $net_tot,
            ];
        }
    }

    return $final_rows;
}

// ============================================================
// BUILD TABLE(S) FOR SELECTED REGIONS
// ============================================================
$tables_by_region = [];

if (!empty($selected_regions)) {
    foreach ($selected_regions as $rg) {
        $tables_by_region[$rg][] = [
            'rows' => compute_table_rows_for_region(
                $conn,
                $transaction_month,
                $gl_mapping,
                $gl_descriptions,
                $gl_ids,
                $special_keys,
                $sort_order_descriptions,
                $rg,
                $valid_filters
            ),
        ];
    }
} else {
    $tables_by_region[''] = [[
        'rows' => compute_table_rows_for_region(
            $conn,
            $transaction_month,
            $gl_mapping,
            $gl_descriptions,
            $gl_ids,
            $special_keys,
            $sort_order_descriptions,
            '',
            $valid_filters
        ),
    ]];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Adjustment</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Target only detail row amount cells for MLFSI and JEWELERS */
        tr[data-is-detail="true"] td:nth-child(5):hover,
        tr[data-is-detail="true"] td:nth-child(6):hover {
            cursor: cell;
            background-color: rgba(255, 127, 41, 0.1);
            transition: background-color 0.2s;
        }
        tr[data-is-detail="true"] td:nth-child(5),
        tr[data-is-detail="true"] td:nth-child(6) {
            user-select: none; /* Prevents text selection on double-click */
        }

        /* Standard Modal Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .reallocate-active {
            cursor: crosshair !important;
        }
        .reallocate-active .report-tbody tr[data-is-detail="true"]:hover td:nth-child(5),
        .reallocate-active .report-tbody tr[data-is-detail="true"]:hover td:nth-child(6) {
            background-color: rgba(59, 130, 246, 0.2) !important;
        }
        .reallocate-banner {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            padding: 12px 24px;
            background: #1e293b;
            color: white;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            display: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="settings.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">Manual Adjustment</div>

            <!-- Error Banner -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">

                <!-- Region multi-select -->
                <div class="filter-group">
                    <label>Region</label>
                    <select name="region" id="regionSelect" class="filter-select">
                        <option value="">All Regions</option>
                        <?php foreach($distinct_reg as $reg_val): ?>
                            <option value="<?= htmlspecialchars($reg_val) ?>" <?= (in_array($reg_val, $selected_regions)) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($reg_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- GL Code mode -->
                <div class="filter-group filter-group--gl-mode">
                    <label>GL Code</label>
                    <div class="radio-group" role="radiogroup" aria-label="GL Code Mode">
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="old" id="glOldRadio" <?= $gl_code_mode === 'old' ? 'checked' : '' ?>>
                            <span>Old GL Code</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="new" id="glNewRadio" <?= $gl_code_mode === 'new' ? 'checked' : '' ?>>
                            <span>New GL Code</span>
                        </label>
                    </div>
                </div>

                <!-- Single transaction month -->
                <div class="filter-group">
                    <label>Transaction Month</label>
                    <input type="month" name="transaction_month" id="transactionMonthSelect"
                           value="<?= htmlspecialchars($transaction_month) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button type="button" class="btn-collapse" id="collapseBtn"><i class="fa-solid fa-compress"></i> Collapse</button>
                    <button type="button" class="btn-save" id="saveAdjustmentBtn"><i class="fa-solid fa-floppy-disk"></i> Save Adjustment</button>
                    <a href="comparative_with_adjustment.php" class="btn-export"><i class="fa-solid fa-file"></i> Generate Report</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                </div>
                <p style="font-size: 12px; font-style: italic;">Double click amount under MLFSI or JEWELERS column to edit/allocate amount.</p>
            </form>

            <!-- Tables -->
            <?php foreach ($tables_by_region as $region_name => $tables): ?>
                <div class="region-block">
                    <div class="tables-scroll">
                        <div class="tables-grid">
                            <?php foreach ($tables as $t): ?>
                                <?php
                                    $table_rows     = $t['rows'];
                                    $display_region = $region_name;
                                    $period_label   = !empty($transaction_month)
                                        ? strtoupper(date('F Y', strtotime($transaction_month . '-01')))
                                        : '(Transaction Month)';
                                ?>
                                <div class="table-container">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th colspan="4">Region: <?php echo !empty($display_region) ? htmlspecialchars($display_region) : 'All Regions'; ?></th>
                                                <th colspan="7"><?= htmlspecialchars($period_label) ?></th>
                                            </tr>
                                            <tr>
                                                <th></th>
                                                <th></th>
                                                <th></th>
                                                <th></th>
                                                <th>MLFSI</th>
                                                <th>JEWELERS</th>
                                                <th>TOTAL</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody class="report-tbody">
                                            <tr class="initial-spacer"><td colspan="8"></td></tr>
                                            <tr class="revenues-header-row">
                                                <td style="background-color:#ff7f29;font-weight:bold;">REVENUES</td>
                                                <td colspan="7" style="background-color:#ff7f29;font-weight:bold;"></td>
                                            </tr>

                                            <?php if (empty($table_rows)): ?>
                                                <tr>
                                                    <td colspan="8" style="text-align:center;">No data structure available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($table_rows as $row):
                                                    // Manual spacer
                                                    if (!empty($row['is_manual_spacer'])) {
                                                        echo '<tr class="spacer-row" style="height:20px;"><td colspan="8"></td></tr>';
                                                        continue;
                                                    }

                                                    $is_summary_row = !empty($row['is_summary_row']);
                                                    $is_header      = !empty($row['is_section_header']);

                                                    $mlfsi    = $row['mlfsi']    ?? 0;
                                                    $jewelers = $row['jewelers'] ?? 0;
                                                    $total    = $row['total']    ?? 0;

                                                    // Negate INJ-2 detail rows
                                                    if (!$is_summary_row && !empty($row['is_inj2'])) {
                                                        $mlfsi    = -$mlfsi;
                                                        $jewelers = -$jewelers;
                                                        $total    = -$total;
                                                    }
                                                ?>
                                                    <tr class="<?= $is_summary_row ? 'summary-row' : 'data-row' ?>"
                                                        data-sort-order="<?= htmlspecialchars($row['sort_order'] ?? '') ?>"
                                                        data-description="<?= htmlspecialchars($row['description'] ?? $row['gl_description'] ?? '', ENT_QUOTES) ?>"
                                                        <?php if (!$is_summary_row): ?>
                                                            data-is-detail="true" 
                                                            data-sub-order="<?= htmlspecialchars($row['sub_order'] ?? '') ?>"
                                                            data-gl-id="<?= htmlspecialchars($row['gl_id'] ?? '') ?>" 
                                                            data-comp="<?= htmlspecialchars($row['gl_description'] ?? '') ?>"
                                                            data-is-inj2="<?= !empty($row['is_inj2']) ? 'true' : 'false' ?>"
                                                        <?php endif; ?>>

                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>">
                                                            <?= $is_summary_row ? htmlspecialchars($row['sort_order']) : '' ?>
                                                        </td>

                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>">
                                                            <?php if ($is_header): ?>
                                                                <strong><?= htmlspecialchars($row['sub_order']) ?></strong>
                                                            <?php elseif ($is_summary_row): ?>
                                                                <strong><?= htmlspecialchars($row['gl_description']) ?></strong>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td class="<?= $is_summary_row ? 'summary-cell summary-description' : '' ?>">
                                                            <?= !$is_summary_row ? htmlspecialchars($row['gl_description']) : '' ?>
                                                        </td>

                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>

                                                        <!-- MLFSI -->
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>"
                                                            style="<?= $mlfsi < 0 ? 'color:red;' : '' ?>">
                                                            <?php if (!$is_header):
                                                                echo $is_summary_row ? '<strong>' : '';
                                                                echo number_format($mlfsi, 2);
                                                                echo $is_summary_row ? '</strong>' : '';
                                                            endif; ?>
                                                        </td>

                                                        <!-- JEWELERS -->
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>"
                                                            style="<?= $jewelers < 0 ? 'color:red;' : '' ?>">
                                                            <?php if (!$is_header):
                                                                echo $is_summary_row ? '<strong>' : '';
                                                                echo number_format($jewelers, 2);
                                                                echo $is_summary_row ? '</strong>' : '';
                                                            endif; ?>
                                                        </td>

                                                        <!-- TOTAL -->
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>"
                                                            style="<?= $total < 0 ? 'color:red;' : '' ?>">
                                                            <?php if (!$is_header):
                                                                echo $is_summary_row ? '<strong>' : '';
                                                                echo number_format($total, 2);
                                                                echo $is_summary_row ? '</strong>' : '';
                                                            endif; ?>
                                                        </td>

                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                    </tr>

                                                    <?php if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): ?>
                                                        <tr class="spacer-row"
                                                            data-spacer-for="<?= htmlspecialchars($row['sort_order'] ?? '') ?>"
                                                            style="height:20px;">
                                                            <td colspan="8"></td>
                                                        </tr>
                                                    <?php endif; ?>

                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Reallocation Banner -->
            <div id="reallocateBanner" class="reallocate-banner">
                <i class="fas fa-mouse-pointer"></i> Select target row in <span id="targetColName"></span> column to complete reallocation.
            </div>

            <!-- Reallocate Modal -->
            <div id="reallocateModal" class="modal">
                <div class="modal-content" style="width: 500px;">
                    <div class="modal-header">
                        <h3>Reallocate Amount</h3>
                        <!-- <span class="close-reallocate" onclick="closeReallocateModal()" style="cursor:pointer; font-size: 24px;">&times;</span> -->
                    </div>
                    <div class="modal-body" style="padding: 20px;">
                        <p><strong>From:</strong> <span id="sourceInfo">-</span></p>
                        <p><strong>Available:</strong> <span id="sourceAvailable">-</span></p>
                        <div class="filter-group" style="margin-top: 15px;">
                            <label style="display:block; margin-bottom: 5px;">Amount to deduct:</label>
                            <input type="number" id="deductAmount" class="filter-select" step="0.01" style="width: 100%; padding: 8px; border: 1px solid #cbd5e0; border-radius: 6px;" placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-footer" style="justify-content: flex-end; gap: 10px; padding: 15px; border-top: 1px solid #edf2f7;">
                        <button type="button" class="btn-reset" onclick="closeReallocateModal()" style="padding: 8px 16px; background-color: #1e293b;">Cancel</button>
                        <button type="button" class="btn-filter" id="selectTargetBtn" style="padding: 8px 16px;">Select Target Row</button>
                    </div>
                </div>
            </div>

            <script>
                // GL code mode validation
                function isMarch2026OrEarlier(month) {
                    if (!month) return true;
                    return new Date(month + '-01') <= new Date('2026-03-01');
                }
                function isApril2026OrLater(month) {
                    if (!month) return true;
                    return new Date(month + '-01') >= new Date('2026-04-01');
                }

                let activeModal = null;
                function showModal(message) {
                    if (activeModal) activeModal.remove();
                    const overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    overlay.innerHTML = `
                        <div class="modal-container">
                            <div class="modal-header">
                                <h3><i class="fa-solid fa-triangle-exclamation"></i> Validation Error</h3>
                            </div>
                            <div class="modal-body"><p>${escapeHtml(message)}</p></div>
                            <div class="modal-footer"><button onclick="closeModal()">OK</button></div>
                        </div>`;
                    document.body.appendChild(overlay);
                    activeModal = overlay;
                }
                window.closeModal = function () {
                    if (activeModal) { activeModal.remove(); activeModal = null; }
                };
                function escapeHtml(text) {
                    const d = document.createElement('div');
                    d.textContent = text;
                    return d.innerHTML;
                }

                function validateForm() {
                    const month      = document.getElementById('transactionMonthSelect').value;
                    const glOld      = document.getElementById('glOldRadio').checked;
                    const glCodeMode = glOld ? 'old' : 'new';

                    if (!month) return true; // allow empty — shows zero data

                    if (glCodeMode === 'old' && !isMarch2026OrEarlier(month)) {
                        showModal('Old GL Code is only available for March 2026 and earlier.');
                        return false;
                    }
                    if (glCodeMode === 'new' && !isApril2026OrLater(month)) {
                        showModal('New GL Code is only available for April 2026 onwards.');
                        return false;
                    }
                    return true;
                }

                // Collapse / expand detail rows
                document.addEventListener('DOMContentLoaded', function () {
                    const collapseBtn = document.getElementById('collapseBtn');
                    let isCollapsed = false;

                    if (collapseBtn) {
                        collapseBtn.addEventListener('click', function () {
                            isCollapsed = !isCollapsed;
                            document.querySelectorAll('.report-tbody').forEach(tbody => {
                                Array.from(tbody.rows).forEach(row => {
                                    const sortOrder = row.getAttribute('data-sort-order');
                                    const isDetail  = row.getAttribute('data-is-detail') === 'true';
                                    const spacerFor = row.getAttribute('data-spacer-for');
                                    const sortNum   = parseInt(sortOrder);
                                    const in1to20   = !isNaN(sortNum) && sortNum >= 1 && sortNum <= 20;

                                    if (in1to20 && isDetail) row.style.display = isCollapsed ? 'none' : '';
                                    if (spacerFor) {
                                        const sn = parseInt(spacerFor);
                                        if (!isNaN(sn) && sn >= 1 && sn <= 20) row.style.display = isCollapsed ? 'none' : '';
                                    }
                                    if (row.classList.contains('revenues-header-row')) row.style.display = isCollapsed ? 'none' : '';
                                    if (row.classList.contains('initial-spacer'))      row.style.display = isCollapsed ? 'none' : '';
                                });
                            });

                            collapseBtn.innerHTML = isCollapsed
                                ? '<i class="fa-solid fa-expand"></i> Uncollapse'
                                : '<i class="fa-solid fa-compress"></i> Collapse';
                            collapseBtn.style.backgroundColor = isCollapsed ? '#1f2937' : '#4b5563';
                        });
                    }

                    const reallocateModal = document.getElementById('reallocateModal');
                    const deductInput = document.getElementById('deductAmount');
                    const banner = document.getElementById('reallocateBanner');
                    let selectionMode = false;
                    let reallocateData = {
                        sourceCell: null,
                        sourceRow: null,
                        amount: 0,
                        cellIndex: -1
                    };
                    let hasManualChanges = false;

                    window.closeReallocateModal = function() {
                        reallocateModal.style.display = 'none';
                        deductInput.value = '';
                    }

                    // Allow double-click to adjust amounts for MLFSI and JEWELERS on detail rows
                    document.querySelectorAll('.report-tbody').forEach(tbody => {
                        tbody.addEventListener('dblclick', function(e) {
                            if (selectionMode) return;
                            const cell = e.target.closest('td');
                            if (!cell) return;

                            const row = cell.parentElement;
                            const isDetail = row.getAttribute('data-is-detail') === 'true';
                            const cellIndex = cell.cellIndex;

                            if (isDetail && (cellIndex === 4 || cellIndex === 5)) {
                                const selectedRegion = document.getElementById('regionSelect').value;
                                if (!selectedRegion) {
                                    alert("Please select a region before reallocating amounts.");
                                    return;
                                }

                                const currentVal = cell.textContent.trim();
                                const numericVal = parseFloat(currentVal.replace(/,/g, '')) || 0;

                                if (numericVal === 0) {
                                    alert("Selection invalid: Cannot reallocate from a row with 0.00 amount.");
                                    return;
                                }

                                reallocateData.sourceCell = cell;
                                reallocateData.sourceRow = row;
                                reallocateData.cellIndex = cellIndex;
                                
                                const columnLabel = cellIndex === 4 ? 'MLFSI' : 'JEWELERS';
                                const glId = row.dataset.glId;
                                const comp = row.dataset.comp;

                                document.getElementById('sourceInfo').textContent = `${glId} - ${comp}`;
                                document.getElementById('sourceAvailable').textContent = currentVal;
                                document.getElementById('targetColName').textContent = columnLabel;
                                
                                reallocateModal.style.display = 'flex';
                                deductInput.focus();
                            }
                        });

                        tbody.addEventListener('click', function(e) {
                            if (!selectionMode) return;
                            const cell = e.target.closest('td');
                            if (!cell) return;

                            const row = cell.parentElement;
                            const isDetail = row.getAttribute('data-is-detail') === 'true';
                            const cellIndex = cell.cellIndex;

                            if (isDetail && cellIndex === reallocateData.cellIndex) {
                                if (row === reallocateData.sourceRow) {
                                    alert("Source and target rows cannot be the same.");
                                    return;
                                }

                                // Perform reallocation
                                const deduct = reallocateData.amount;
                                
                                // Update Source
                                updateCellAndRowTotal(reallocateData.sourceCell, -deduct);
                                
                                // Update Target
                                updateCellAndRowTotal(cell, deduct);

                                // Refresh all summary rows for this specific table
                                refreshAllCalculations(tbody);
                                hasManualChanges = true;

                                // Reset state
                                selectionMode = false;
                                document.body.classList.remove('reallocate-active');
                                banner.style.display = 'none';
                            }
                        });
                    });

                    document.getElementById('selectTargetBtn').addEventListener('click', function() {
                        const amount = parseFloat(deductInput.value);
                        if (isNaN(amount) || amount <= 0) {
                            alert("Please enter a valid amount greater than zero.");
                            return;
                        }

                        const available = parseFloat(reallocateData.sourceCell.textContent.replace(/,/g, '')) || 0;
                        if (amount > available) {
                            alert("Deduction failed: The amount to deduct cannot exceed the available balance of " + formatCurrency(available) + ".");
                            return;
                        }
                        
                        reallocateData.amount = amount;
                        selectionMode = true;
                        closeReallocateModal();
                        document.body.classList.add('reallocate-active');
                        banner.style.display = 'block';
                    });

                    const saveAdjustmentBtn = document.getElementById('saveAdjustmentBtn');
                    if (saveAdjustmentBtn) {
                        saveAdjustmentBtn.addEventListener('click', async function() {
                            const formData = new FormData(document.getElementById('filterForm'));
                            const filters = {
                                region: (formData.get('region') || '').toString().trim(),
                                transaction_month: (formData.get('transaction_month') || '').toString().trim(),
                                transaction_year: ''
                            };

                            if (!filters.region) {
                                alert('Please select a specific region before saving adjustments.');
                                return;
                            }

                            if (!filters.transaction_month) {
                                alert('Please select a transaction month before saving adjustments.');
                                return;
                            }

                            if (!hasManualChanges) {
                                alert('No changes detected. Please adjust amounts before saving.');
                                return;
                            }

                            const adjustments = collectAdjustmentData();
                            if (adjustments.length === 0) {
                                alert('No adjustment rows found to save.');
                                return;
                            }

                            const nonZeroCount = adjustments.filter(item => item.mlfsi !== 0 || item.jewelers !== 0).length;
                            const zeroCount = adjustments.length - nonZeroCount;
                            const confirmMessage = `Are you sure you want to save adjustments for ${filters.region}?\n\n` +
                                `Total rows to save: ${adjustments.length}\n` +
                                `Rows with amounts: ${nonZeroCount}\n` +
                                `Rows with zeros: ${zeroCount}\n\n` +
                                `This will overwrite any existing adjustments for this region and month.`;

                            if (!confirm(confirmMessage)) return;

                            saveAdjustmentBtn.disabled = true;
                            saveAdjustmentBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                            try {
                                const response = await fetch('save_manual_adjustment.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        adjustments: adjustments,
                                        filters: filters,
                                        hasChanges: true,
                                        includeZeros: true
                                    })
                                });

                                const result = await response.json();
                                if (result.success) {
                                    hasManualChanges = false;
                                    alert(result.message || 'Successfully saved adjustments.');
                                } else {
                                    alert('Error: ' + (result.error || 'Failed to save adjustments.'));
                                }
                            } catch (err) {
                                alert('Network error: ' + err.message);
                            } finally {
                                saveAdjustmentBtn.disabled = false;
                                saveAdjustmentBtn.innerHTML = '<i class="fa-solid fa-coins"></i> Save Adjustment';
                            }
                        });
                    }

                    function collectAdjustmentData() {
                        const adjustments = [];
                        const summaryOnlySorts = new Set(['6', '8', '11']);

                        document.querySelectorAll('.report-tbody tr[data-is-detail="true"]').forEach(row => {
                            const sortOrder = row.dataset.sortOrder || '';
                            if (!sortOrder || summaryOnlySorts.has(sortOrder)) return;

                            adjustments.push({
                                sort_order: parseInt(sortOrder) || 0,
                                description: row.dataset.description || '',
                                sub_order: row.dataset.subOrder === '' ? null : (parseInt(row.dataset.subOrder || '') || 0),
                                gl_description_comparative: row.dataset.comp || null,
                                mlfsi: parseAmount(row.cells[4].textContent),
                                jewelers: parseAmount(row.cells[5].textContent)
                            });
                        });

                        document.querySelectorAll('.report-tbody tr.summary-row[data-sort-order]').forEach(row => {
                            const sortOrder = row.dataset.sortOrder || '';
                            if (!summaryOnlySorts.has(sortOrder)) return;

                            adjustments.push({
                                sort_order: parseInt(sortOrder) || 0,
                                description: row.dataset.description || '',
                                sub_order: null,
                                gl_description_comparative: null,
                                mlfsi: parseAmount(row.cells[4].textContent),
                                jewelers: parseAmount(row.cells[5].textContent)
                            });
                        });

                        return adjustments;
                    }

                    function parseAmount(value) {
                        return parseFloat((value || '').replace(/,/g, '').trim()) || 0;
                    }

                    function refreshAllCalculations(container) {
                        const detailRows = container.querySelectorAll('tr[data-is-detail="true"]');
                        const summaryRows = container.querySelectorAll('tr.summary-row[data-sort-order]');
                        
                        // 1. Calculate Group Totals (Category Totals)
                        const categoryMap = {};
                        
                        detailRows.forEach(row => {
                            const sortOrder = row.dataset.sortOrder;
                            const isInj2 = row.dataset.isInj2 === 'true';
                            
                            const mlfsi = parseFloat(row.cells[4].textContent.replace(/,/g, '')) || 0;
                            const jewelers = parseFloat(row.cells[5].textContent.replace(/,/g, '')) || 0;
                            
                            // To get raw values for accumulation, we flip sign if isInj2 was applied during PHP render
                            const rawMlfsi = isInj2 ? -mlfsi : mlfsi;
                            const rawJewelers = isInj2 ? -jewelers : jewelers;
                            const rawTotal = rawMlfsi + rawJewelers;
                            
                            if (!categoryMap[sortOrder]) {
                                categoryMap[sortOrder] = { mlfsi: 0, jewelers: 0, total: 0 };
                            }
                            categoryMap[sortOrder].mlfsi += rawMlfsi;
                            categoryMap[sortOrder].jewelers += rawJewelers;
                            categoryMap[sortOrder].total += rawTotal;
                        });

                        // Update Category Summary Rows (1, 2, 3, etc.)
                        summaryRows.forEach(sRow => {
                            const sortOrder = sRow.dataset.sortOrder;
                            if (!isNaN(parseInt(sortOrder))) {
                                const totals = categoryMap[sortOrder] || { mlfsi: 0, jewelers: 0, total: 0 };
                                updateNumericCell(sRow.cells[4], totals.mlfsi);
                                updateNumericCell(sRow.cells[5], totals.jewelers);
                                updateNumericCell(sRow.cells[6], totals.total);
                            }
                        });

                        // 2. Calculate Special Computed Rows
                        const sumRange = (start, end) => {
                            let ml = 0, jw = 0, tot = 0;
                            for (let i = start; i <= end; i++) {
                                if (categoryMap[i]) {
                                    ml += categoryMap[i].mlfsi;
                                    jw += categoryMap[i].jewelers;
                                    tot += categoryMap[i].total;
                                }
                            }
                            return { ml, jw, tot };
                        };

                        const totalRevenues = sumRange(1, 20);
                        updateSpecialRow(container, 'TOTAL REVENUES', totalRevenues);

                        const costOfSales = categoryMap[21] || { mlfsi: 0, jewelers: 0, total: 0 };
                        const grossProfit = {
                            ml: totalRevenues.ml - costOfSales.mlfsi,
                            jw: totalRevenues.jw - costOfSales.jewelers,
                            tot: totalRevenues.tot - costOfSales.total
                        };
                        updateSpecialRow(container, 'GROSS PROFIT', grossProfit);

                        const totalSellingAdmin = sumRange(22, 23);
                        updateSpecialRow(container, 'TOTAL SELLING AND ADMIN EXPENSES', totalSellingAdmin);

                        const ebitda = {
                            ml: grossProfit.ml - totalSellingAdmin.ml,
                            jw: grossProfit.jw - totalSellingAdmin.jw,
                            tot: grossProfit.tot - totalSellingAdmin.tot
                        };
                        updateSpecialRow(container, "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", ebitda);

                        const sort24 = categoryMap[24] || { mlfsi: 0, jewelers: 0, total: 0 };
                        const ebit = { ml: ebitda.ml - sort24.mlfsi, jw: ebitda.jw - sort24.jewelers, tot: ebitda.tot - sort24.total };
                        updateSpecialRow(container, 'EARNINGS BEFORE INTEREST & TAXES', ebit);

                        const sort25 = categoryMap[25] || { mlfsi: 0, jewelers: 0, total: 0 };
                        const ebt = { ml: ebit.ml - sort25.mlfsi, jw: ebit.jw - sort25.jewelers, tot: ebit.tot - sort25.total };
                        updateSpecialRow(container, 'EARNINGS BEFORE TAXES', ebt);

                        const sort26 = categoryMap[26] || { mlfsi: 0, jewelers: 0, total: 0 };
                        updateSpecialRow(container, 'TOTAL NET INCOME/LOSS', { ml: ebt.ml - sort26.mlfsi, jw: ebt.jw - sort26.jewelers, tot: ebt.tot - sort26.total });
                    }

                    function updateNumericCell(cell, value) {
                        if (!cell) return;
                        cell.innerHTML = '<strong>' + formatCurrency(value) + '</strong>';
                        cell.style.color = value < 0 ? 'red' : 'black';
                    }

                    function updateSpecialRow(container, sortOrderStr, values) {
                        const row = container.querySelector(`.summary-row[data-sort-order="${sortOrderStr}"]`);
                        if (row) {
                            updateNumericCell(row.cells[4], values.ml);
                            updateNumericCell(row.cells[5], values.jw);
                            updateNumericCell(row.cells[6], values.tot);
                        }
                    }

                    function updateCellAndRowTotal(cell, delta) {
                        const currentVal = parseFloat(cell.textContent.replace(/,/g, '')) || 0;
                        const newVal = currentVal + delta;
                        
                        // Update column cell
                        cell.innerHTML = '<strong>' + formatCurrency(newVal) + '</strong>';
                        cell.style.color = newVal < 0 ? 'red' : 'black';

                        // Update row TOTAL cell (index 6)
                        const row = cell.parentElement;
                        const totalCell = row.cells[6];
                        const currentTotal = parseFloat(totalCell.textContent.replace(/,/g, '')) || 0;
                        const newTotal = currentTotal + delta;
                        
                        totalCell.innerHTML = '<strong>' + formatCurrency(newTotal) + '</strong>';
                        totalCell.style.color = newTotal < 0 ? 'red' : 'black';
                    }

                    function formatCurrency(val) {
                        return val.toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                });
            </script>
        </div>
    </main>
<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>