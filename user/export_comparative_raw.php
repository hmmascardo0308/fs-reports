<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

// Get filter parameters from URL
$mainzone = $_GET['mainzone'] ?? '';
$zone = $_GET['zone'] ?? '';
$selected_regions = $_GET['region'] ?? [];
$selected_areas = $_GET['area'] ?? [];
$selected_branches = $_GET['branch'] ?? [];
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

if (!is_array($selected_regions)) {
    $selected_regions = $selected_regions !== '' ? [$selected_regions] : [];
}
if (!is_array($selected_areas)) {
    $selected_areas = $selected_areas !== '' ? [$selected_areas] : [];
}
if (!is_array($selected_branches)) {
    $selected_branches = $selected_branches !== '' ? [$selected_branches] : [];
}
$selected_regions = array_values(array_filter(array_map('trim', $selected_regions), fn($v) => $v !== ''));
$selected_areas = array_values(array_filter(array_map('trim', $selected_areas), fn($v) => $v !== ''));
$selected_branches = array_values(array_filter(array_map('trim', $selected_branches), fn($v) => $v !== ''));

// Helper functions
function compareMonths(string $month1, string $month2): int {
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

function colLetter(int $columnIndex): string {
    return Coordinate::stringFromColumnIndex($columnIndex);
}

function cellAddr(int $startColumnIndex, int $offset, int $row): string {
    return colLetter($startColumnIndex + $offset) . $row;
}

function rangeAddr(int $startColumnIndex, int $startOffset, int $endOffset, int $row): string {
    return cellAddr($startColumnIndex, $startOffset, $row) . ':' . cellAddr($startColumnIndex, $endOffset, $row);
}

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

// Validate filters
$valid_filters = false;
if (!empty($primary_period) && !empty($previous_period)) {
    if (compareMonths($primary_period, $previous_period) > 0) {
        if ($gl_code_mode === 'old') {
            if (isMarch2026OrEarlier($primary_period) && isMarch2026OrEarlier($previous_period)) {
                $valid_filters = true;
            }
        } elseif ($gl_code_mode === 'new') {
            if (isApril2026OrLater($primary_period) && isApril2026OrLater($previous_period)) {
                $valid_filters = true;
            }
        } elseif ($gl_code_mode === 'mixed') {
            $valid_filters = true;
        }
    }
}

// Get hierarchy data
$distinct_reg = [];
$distinct_area = [];
$mz_to_zn = [];
$zn_to_reg = [];
$reg_to_area = [];
$area_to_branch = [];
$branch_info = [];

$hierarchy_query = "
    SELECT DISTINCT mainzone, mlmatic_zone as zone, region, area as area
    FROM fs_reports.fs_raw_data_summary
    WHERE mainzone IS NOT NULL AND mainzone != ''
    ORDER BY mainzone, mlmatic_zone, region, area
";
$hierarchy_res = mysqli_query($conn, $hierarchy_query);
if ($hierarchy_res) {
    while ($h = mysqli_fetch_assoc($hierarchy_res)) {
        $mz = trim((string)($h['mainzone'] ?? ''));
        $zn = trim((string)($h['zone'] ?? ''));
        $rg = trim((string)($h['region'] ?? ''));
        $ar = trim((string)($h['area'] ?? ''));

        if ($rg !== '' && !in_array($rg, $distinct_reg, true)) $distinct_reg[] = $rg;
        if ($ar !== '' && !in_array($ar, $distinct_area, true)) $distinct_area[] = $ar;

        if ($mz !== '' && $zn !== '') {
            $mz_to_zn[$mz] = $mz_to_zn[$mz] ?? [];
            if (!in_array($zn, $mz_to_zn[$mz], true)) $mz_to_zn[$mz][] = $zn;
        }
        if ($zn !== '' && $rg !== '') {
            $zn_to_reg[$zn] = $zn_to_reg[$zn] ?? [];
            if (!in_array($rg, $zn_to_reg[$zn], true)) $zn_to_reg[$zn][] = $rg;
        }
        if ($rg !== '' && $ar !== '') {
            $reg_to_area[$rg] = $reg_to_area[$rg] ?? [];
            if (!in_array($ar, $reg_to_area[$rg], true)) $reg_to_area[$rg][] = $ar;
        }
    }
}
sort($distinct_reg);
sort($distinct_area);

// Get distinct branches
$branch_query = "
    SELECT DISTINCT branch_id, branch_name, area as area, region
    FROM fs_reports.fs_raw_data_summary
    WHERE branch_id IS NOT NULL AND branch_id != ''
    AND branch_name IS NOT NULL AND branch_name != ''
    ORDER BY branch_name
";
$branch_res = mysqli_query($conn, $branch_query);
if ($branch_res) {
    while ($b = mysqli_fetch_assoc($branch_res)) {
        $branch_id = trim((string)($b['branch_id'] ?? ''));
        $branch_name = trim((string)($b['branch_name'] ?? ''));
        $area = trim((string)($b['area'] ?? ''));
        $region = trim((string)($b['region'] ?? ''));
        
        if ($branch_id !== '' && $branch_name !== '') {
            $branch_key = $branch_id . '|' . $branch_name;
            
            $branch_info[$branch_key] = [
                'id' => $branch_id,
                'name' => $branch_name,
                'area' => $area,
                'region' => $region
            ];
            
            if ($area !== '') {
                $area_to_branch[$area] = $area_to_branch[$area] ?? [];
                if (!in_array($branch_key, $area_to_branch[$area], true)) {
                    $area_to_branch[$area][] = $branch_key;
                }
            }
        }
    }
}

// Get GL mapping
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];

$old_gl_id_to_codes = [];
$mixed_id_map = [];
if ($gl_code_mode === 'mixed') {
    $res = mysqli_query($conn, "SELECT gl_id, gl_code FROM fs_reports.gl_codes WHERE gl_code IS NOT NULL AND gl_code != ''");
    while ($row = mysqli_fetch_assoc($res)) {
        $old_gl_id_to_codes[$row['gl_id']][] = trim($row['gl_code']);
    }

    $mixed_id_map = [
        'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
        'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
        'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
        'INS-4' => ['INS-27', 'INS-46'],
        'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
        'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
        'INS-7' => ['INS-15', 'INS-16','INS-17','INS-18','INS-19'],
        'INS-8' => ['INS-38'],
        'INS-9' => ['INS-48'],
        'INS-10' => ['INS-49']
    ];
}

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes' : 'fs_reports.new_gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        $gl_id = $row['gl_id'] ?? '';

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        $code = trim((string)($row['gl_code'] ?? ''));

        if ($gl_code_mode === 'mixed') {
            if ($code !== '' && !in_array($code, $gl_mapping[$key]['new'], true)) {
                $gl_mapping[$key]['new'][] = $code;
            }
            
            $target_old_ids = $mixed_id_map[$gl_id] ?? [$gl_id];
            foreach ($target_old_ids as $oid) {
                if (isset($old_gl_id_to_codes[$oid])) {
                    foreach ($old_gl_id_to_codes[$oid] as $oc) {
                        if (!in_array($oc, $gl_mapping[$key]['old'], true)) {
                            $gl_mapping[$key]['old'][] = $oc;
                        }
                    }
                }
            }
        } else {
            if ($code !== '') {
                if (!in_array($code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $code;
                }
                if (!in_array($code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $code;
                }
            }
        }
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// Function to compute table rows - MATCHING the main page logic
function compute_table_rows_for_export(
    mysqli $conn,
    string $mainzone,
    string $zone,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    string $gl_code_mode,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    string $region,
    string $area,
    array $selected_branches = [],
    bool $valid_filters
): array {
    $where_conditions = [];
    $params = [];
    $types = "";

    if (!empty($mainzone)) {
        $where_conditions[] = "mainzone = ?";
        $params[] = $mainzone;
        $types .= "s";
    }
    if (!empty($zone)) {
        $where_conditions[] = "mlmatic_zone = ?";
        $params[] = $zone;
        $types .= "s";
    }
    if (!empty($region)) {
        $where_conditions[] = "region = ?";
        $params[] = $region;
        $types .= "s";
    }
    if (!empty($area)) {
        $where_conditions[] = "area = ?";
        $params[] = $area;
        $types .= "s";
    }
    if (!empty($selected_branches)) {
        $branch_conditions = [];
        foreach ($selected_branches as $branch) {
            $branch_parts = explode('|', $branch);
            if (count($branch_parts) == 2) {
                $branch_id = trim($branch_parts[0]);
                $branch_name = trim($branch_parts[1]);
                $branch_conditions[] = "(branch_id = ? AND branch_name = ?)";
                $params[] = $branch_id;
                $params[] = $branch_name;
                $types .= "ss";
            }
        }
        if (!empty($branch_conditions)) {
            $where_conditions[] = "(" . implode(" OR ", $branch_conditions) . ")";
        }
    }
    if (!empty($transaction_year)) {
        $where_conditions[] = "transaction_year = ?";
        $params[] = $transaction_year;
        $types .= "s";
    }

    $base_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE 1=1";

    // Primary period data
    $primary_data = [];
    if ($valid_filters && !empty($primary_period)) {
        $p_parts = explode('-', $primary_period);
        $p_year = $p_parts[0];
        $p_month_val = $primary_period . '-01';
        
        $primary_sql = "
            SELECT 
                gl_code,
                SUM(CASE WHEN transaction_type = 'Branch' THEN amount ELSE 0 END) as branch_amount,
                SUM(CASE WHEN transaction_type = 'Showroom' THEN amount ELSE 0 END) as showroom_amount
            FROM fs_reports.fs_raw_data_summary
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        $primary_params = array_merge($params, [$p_year, $p_month_val]);
        $primary_types = $types . "ss";
        
        $primary_stmt = mysqli_prepare($conn, $primary_sql);
        if ($primary_stmt) {
            if (!empty($primary_params)) {
                mysqli_stmt_bind_param($primary_stmt, $primary_types, ...$primary_params);
            }
            mysqli_stmt_execute($primary_stmt);
            $primary_result = mysqli_stmt_get_result($primary_stmt);
            while ($row = mysqli_fetch_assoc($primary_result)) {
                $primary_data[$row['gl_code']] = [
                    'mlfsi' => floatval($row['branch_amount']),
                    'jewelers' => floatval($row['showroom_amount']),
                    'total' => floatval($row['branch_amount']) + floatval($row['showroom_amount'])
                ];
            }
            mysqli_stmt_close($primary_stmt);
        }
    }

    // Previous period data
    $previous_data = [];
    if ($valid_filters && !empty($previous_period)) {
        $prev_parts = explode('-', $previous_period);
        $prev_year_val = $prev_parts[0];
        $prev_month_val = $previous_period . '-01';
        
        $previous_sql = "
            SELECT 
                gl_code,
                SUM(CASE WHEN transaction_type = 'Branch' THEN amount ELSE 0 END) as branch_amount,
                SUM(CASE WHEN transaction_type = 'Showroom' THEN amount ELSE 0 END) as showroom_amount
            FROM fs_reports.fs_raw_data_summary
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        $previous_params = array_merge($params, [$prev_year_val, $prev_month_val]);
        $previous_types = $types . "ss";
        
        $previous_stmt = mysqli_prepare($conn, $previous_sql);
        if ($previous_stmt) {
            if (!empty($previous_params)) {
                mysqli_stmt_bind_param($previous_stmt, $previous_types, ...$previous_params);
            }
            mysqli_stmt_execute($previous_stmt);
            $previous_result = mysqli_stmt_get_result($previous_stmt);
            while ($row = mysqli_fetch_assoc($previous_result)) {
                $previous_data[$row['gl_code']] = [
                    'mlfsi' => floatval($row['branch_amount']),
                    'jewelers' => floatval($row['showroom_amount']),
                    'total' => floatval($row['branch_amount']) + floatval($row['showroom_amount'])
                ];
            }
            mysqli_stmt_close($previous_stmt);
        }
    }

    // Build table rows - MATCHING main page logic
    $table_rows = [];
    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);
        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys);
        
        $p_mode = $gl_code_mode;
        $prev_mode = $gl_code_mode;
        if ($gl_code_mode === 'mixed') {
            $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
            $prev_mode = isApril2026OrLater($previous_period) ? 'new' : 'old';
        }

        $p_codes = $codes_detailed[$p_mode];
        $prev_codes = $codes_detailed[$prev_mode];

        $primary_mlfsi = 0;
        $primary_jewelers = 0;
        $previous_mlfsi = 0;
        $previous_jewelers = 0;
        
        foreach ($p_codes as $gl_code) {
            if (isset($primary_data[$gl_code])) {
                $primary_mlfsi += $primary_data[$gl_code]['mlfsi'];
                $primary_jewelers += $primary_data[$gl_code]['jewelers'];
            }
        }
        foreach ($prev_codes as $gl_code) {
            if (isset($previous_data[$gl_code])) {
                $previous_mlfsi += $previous_data[$gl_code]['mlfsi'];
                $previous_jewelers += $previous_data[$gl_code]['jewelers'];
            }
        }
        
        $primary_total = $primary_mlfsi + $primary_jewelers;
        $previous_total = $previous_mlfsi + $previous_jewelers;
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_description' => $gl_description,
            'primary_mlfsi' => $primary_mlfsi,
            'primary_jewelers' => $primary_jewelers,
            'primary_total' => $primary_total,
            'previous_mlfsi' => $previous_mlfsi,
            'previous_jewelers' => $previous_jewelers,
            'previous_total' => $previous_total,
            'is_inj2' => $is_inj2,
        ];
    }

    // Group by sort_order and add summary rows - MATCHING main page
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $grouped_rows[$row['sort_order']][] = $row;
    }

    $final_table_rows = [];
    $rev_mlfsi_p = 0; $rev_jew_p = 0; $rev_tot_p = 0;
    $rev_mlfsi_prev = 0; $rev_jew_prev = 0; $rev_tot_prev = 0;
    $sa_mlfsi_p = 0; $sa_jew_p = 0; $sa_tot_p = 0;
    $sa_mlfsi_prev = 0; $sa_jew_prev = 0; $sa_tot_prev = 0;
    $gp_mlfsi_p = 0; $gp_jew_p = 0; $gp_tot_p = 0;
    $gp_mlfsi_prev = 0; $gp_jew_prev = 0; $gp_tot_prev = 0;
    $ebitda_mlfsi_p = 0; $ebitda_jew_p = 0; $ebitda_tot_p = 0;
    $ebitda_mlfsi_prev = 0; $ebitda_jew_prev = 0; $ebitda_tot_prev = 0;
    $ebit_mlfsi_p = 0; $ebit_jew_p = 0; $ebit_tot_p = 0;
    $ebit_mlfsi_prev = 0; $ebit_jew_prev = 0; $ebit_tot_prev = 0;
    $ebt_mlfsi_p = 0; $ebt_jew_p = 0; $ebt_tot_p = 0;
    $ebt_mlfsi_prev = 0; $ebt_jew_prev = 0; $ebt_tot_prev = 0;
    $net_mlfsi_p = 0; $net_jew_p = 0; $net_tot_p = 0;
    $net_mlfsi_prev = 0; $net_jew_prev = 0; $net_tot_prev = 0;

    foreach ($grouped_rows as $sort_order => $rows) {
        // Add REVENUES header before sort_order 1
        if (!isset($revenues_header_added) && (int)$sort_order >= 1) {
            $final_table_rows[] = [
                'is_section_header' => true,
                'section_name' => 'REVENUES',
                'sort_order' => 'REVENUES'
            ];
            $revenues_header_added = true;
        }
        
        // Skip sort orders 6, 8, 11 for detail rows (matching main page)
        if (!in_array((int)$sort_order, [6, 8, 11])) {
            foreach ($rows as $row) {
                $display_row = $row;
                // Apply INJ-2 negation (matching main page)
                if ($row['is_inj2']) {
                    $display_row['primary_mlfsi'] = -$row['primary_mlfsi'];
                    $display_row['primary_jewelers'] = -$row['primary_jewelers'];
                    $display_row['primary_total'] = -$row['primary_total'];
                    $display_row['previous_mlfsi'] = -$row['previous_mlfsi'];
                    $display_row['previous_jewelers'] = -$row['previous_jewelers'];
                    $display_row['previous_total'] = -$row['previous_total'];
                }
                $final_table_rows[] = array_merge($display_row, ['is_summary_row' => false]);
            }
        }
        
        $total_primary_mlfsi = array_sum(array_column($rows, 'primary_mlfsi'));
        $total_primary_jewelers = array_sum(array_column($rows, 'primary_jewelers'));
        $total_primary_total = array_sum(array_column($rows, 'primary_total'));
        $total_previous_mlfsi = array_sum(array_column($rows, 'previous_mlfsi'));
        $total_previous_jewelers = array_sum(array_column($rows, 'previous_jewelers'));
        $total_previous_total = array_sum(array_column($rows, 'previous_total'));

        // Accumulate revenues (sort orders 1-20)
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $rev_mlfsi_p += $total_primary_mlfsi;
            $rev_jew_p += $total_primary_jewelers;
            $rev_tot_p += $total_primary_total;
            $rev_mlfsi_prev += $total_previous_mlfsi;
            $rev_jew_prev += $total_previous_jewelers;
            $rev_tot_prev += $total_previous_total;
        }
        
        // Accumulate selling & admin (sort orders 22-23)
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
            $sa_mlfsi_p += $total_primary_mlfsi;
            $sa_jew_p += $total_primary_jewelers;
            $sa_tot_p += $total_primary_total;
            $sa_mlfsi_prev += $total_previous_mlfsi;
            $sa_jew_prev += $total_previous_jewelers;
            $sa_tot_prev += $total_previous_total;
        }
        
        $inc_dec = $total_primary_total - $total_previous_total;
        $percentage = 0;
        if ($total_previous_total != 0) {
            $percentage = ($inc_dec / $total_previous_total) * 100;
        } elseif ($total_primary_total != 0) {
            $percentage = 100;
        }
        
        $description = $sort_order_descriptions[$sort_order] ?? "Total for Sort Order " . $sort_order;
        
        // Add summary row for sort orders except 24, 25, 26 (matching main page)
        if (!in_array((int)$sort_order, [24, 25, 26])) {
            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'gl_description' => $description,
                'primary_mlfsi' => $total_primary_mlfsi,
                'primary_jewelers' => $total_primary_jewelers,
                'primary_total' => $total_primary_total,
                'previous_mlfsi' => $total_previous_mlfsi,
                'previous_jewelers' => $total_previous_jewelers,
                'previous_total' => $total_previous_total,
                'inc_dec' => $inc_dec,
                'percentage' => $percentage,
                'is_summary_row' => true
            ];
        }

        // TOTAL REVENUES (after sort order 20)
        if ((int)$sort_order == 20) {
            $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
            $pct_rev = ($rev_tot_prev != 0) ? ($inc_dec_rev / abs($rev_tot_prev)) * 100 : ($rev_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'gl_description' => '',
                'primary_mlfsi' => $rev_mlfsi_p,
                'primary_jewelers' => $rev_jew_p,
                'primary_total' => $rev_tot_p,
                'previous_mlfsi' => $rev_mlfsi_prev,
                'previous_jewelers' => $rev_jew_prev,
                'previous_total' => $rev_tot_prev,
                'inc_dec' => $inc_dec_rev,
                'percentage' => $pct_rev,
                'is_summary_row' => true
            ];
            
            $final_table_rows[] = [
                'is_section_header' => true,
                'section_name' => 'Cost of Sales/Service',
                'sort_order' => 'COST_OF_SALES'
            ];
        }

        // GROSS PROFIT (after sort order 21)
        if ((int)$sort_order == 21) {
            $gp_mlfsi_p = $rev_mlfsi_p - $total_primary_mlfsi;
            $gp_jew_p = $rev_jew_p - $total_primary_jewelers;
            $gp_tot_p = $rev_tot_p - $total_primary_total;
            $gp_mlfsi_prev = $rev_mlfsi_prev - $total_previous_mlfsi;
            $gp_jew_prev = $rev_jew_prev - $total_previous_jewelers;
            $gp_tot_prev = $rev_tot_prev - $total_previous_total;
            
            $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
            $pct_gp = ($gp_tot_prev != 0) ? ($inc_dec_gp / abs($gp_tot_prev)) * 100 : ($gp_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'gl_description' => '',
                'primary_mlfsi' => $gp_mlfsi_p,
                'primary_jewelers' => $gp_jew_p,
                'primary_total' => $gp_tot_p,
                'previous_mlfsi' => $gp_mlfsi_prev,
                'previous_jewelers' => $gp_jew_prev,
                'previous_total' => $gp_tot_prev,
                'inc_dec' => $inc_dec_gp,
                'percentage' => $pct_gp,
                'is_summary_row' => true
            ];
            
            $final_table_rows[] = [
                'is_section_header' => true,
                'section_name' => 'SELLING & ADMIN EXPENSE',
                'sort_order' => 'SELLING_ADMIN'
            ];
        }

        // TOTAL SELLING AND ADMIN EXPENSES & EBITDA (after sort order 23)
        if ((int)$sort_order == 23) {
            $inc_dec_sa = $sa_tot_p - $sa_tot_prev;
            $pct_sa = ($sa_tot_prev != 0) ? ($inc_dec_sa / abs($sa_tot_prev)) * 100 : ($sa_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'gl_description' => '',
                'primary_mlfsi' => $sa_mlfsi_p,
                'primary_jewelers' => $sa_jew_p,
                'primary_total' => $sa_tot_p,
                'previous_mlfsi' => $sa_mlfsi_prev,
                'previous_jewelers' => $sa_jew_prev,
                'previous_total' => $sa_tot_prev,
                'inc_dec' => $inc_dec_sa,
                'percentage' => $pct_sa,
                'is_summary_row' => true
            ];
            
            $ebitda_mlfsi_p = $gp_mlfsi_p - $sa_mlfsi_p;
            $ebitda_jew_p = $gp_jew_p - $sa_jew_p;
            $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
            $ebitda_mlfsi_prev = $gp_mlfsi_prev - $sa_mlfsi_prev;
            $ebitda_jew_prev = $gp_jew_prev - $sa_jew_prev;
            $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;
            
            $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
            $pct_ebitda = ($ebitda_tot_prev != 0) ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100 : ($ebitda_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'gl_description' => '',
                'primary_mlfsi' => $ebitda_mlfsi_p,
                'primary_jewelers' => $ebitda_jew_p,
                'primary_total' => $ebitda_tot_p,
                'previous_mlfsi' => $ebitda_mlfsi_prev,
                'previous_jewelers' => $ebitda_jew_prev,
                'previous_total' => $ebitda_tot_prev,
                'inc_dec' => $inc_dec_ebitda,
                'percentage' => $pct_ebitda,
                'is_summary_row' => true
            ];
        }

        // EARNINGS BEFORE INTEREST & TAXES (after sort order 24)
        if ((int)$sort_order == 24) {
            $ebit_mlfsi_p = $ebitda_mlfsi_p - $total_primary_mlfsi;
            $ebit_jew_p = $ebitda_jew_p - $total_primary_jewelers;
            $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
            $ebit_mlfsi_prev = $ebitda_mlfsi_prev - $total_previous_mlfsi;
            $ebit_jew_prev = $ebitda_jew_prev - $total_previous_jewelers;
            $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;

            $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
            $pct_ebit = ($ebit_tot_prev != 0) ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100 : ($ebit_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'gl_description' => '',
                'primary_mlfsi' => $ebit_mlfsi_p,
                'primary_jewelers' => $ebit_jew_p,
                'primary_total' => $ebit_tot_p,
                'previous_mlfsi' => $ebit_mlfsi_prev,
                'previous_jewelers' => $ebit_jew_prev,
                'previous_total' => $ebit_tot_prev,
                'inc_dec' => $inc_dec_ebit,
                'percentage' => $pct_ebit,
                'is_summary_row' => true
            ];
        }

        // EARNINGS BEFORE TAXES (after sort order 25)
        if ((int)$sort_order == 25) {
            $ebt_mlfsi_p = $ebit_mlfsi_p - $total_primary_mlfsi;
            $ebt_jew_p = $ebit_jew_p - $total_primary_jewelers;
            $ebt_tot_p = $ebit_tot_p - $total_primary_total;
            $ebt_mlfsi_prev = $ebit_mlfsi_prev - $total_previous_mlfsi;
            $ebt_jew_prev = $ebit_jew_prev - $total_previous_jewelers;
            $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;

            $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
            $pct_ebt = ($ebt_tot_prev != 0) ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100 : ($ebt_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'gl_description' => '',
                'primary_mlfsi' => $ebt_mlfsi_p,
                'primary_jewelers' => $ebt_jew_p,
                'primary_total' => $ebt_tot_p,
                'previous_mlfsi' => $ebt_mlfsi_prev,
                'previous_jewelers' => $ebt_jew_prev,
                'previous_total' => $ebt_tot_prev,
                'inc_dec' => $inc_dec_ebt,
                'percentage' => $pct_ebt,
                'is_summary_row' => true
            ];
        }

        // TOTAL NET INCOME/LOSS (after sort order 26)
        if ((int)$sort_order == 26) {
            $net_mlfsi_p = $ebt_mlfsi_p - $total_primary_mlfsi;
            $net_jew_p = $ebt_jew_p - $total_primary_jewelers;
            $net_tot_p = $ebt_tot_p - $total_primary_total;
            $net_mlfsi_prev = $ebt_mlfsi_prev - $total_previous_mlfsi;
            $net_jew_prev = $ebt_jew_prev - $total_previous_jewelers;
            $net_tot_prev = $ebt_tot_prev - $total_previous_total;

            $inc_dec_net = $net_tot_p - $net_tot_prev;
            $pct_net = ($net_tot_prev != 0) ? ($inc_dec_net / abs($net_tot_prev)) * 100 : ($net_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'TOTAL NET INCOME/LOSS',
                'gl_description' => '',
                'primary_mlfsi' => $net_mlfsi_p,
                'primary_jewelers' => $net_jew_p,
                'primary_total' => $net_tot_p,
                'previous_mlfsi' => $net_mlfsi_prev,
                'previous_jewelers' => $net_jew_prev,
                'previous_total' => $net_tot_prev,
                'inc_dec' => $inc_dec_net,
                'percentage' => $pct_net,
                'is_summary_row' => true
            ];
        }
    }
    
    return $final_table_rows;
}

// Function to set manual column widths
function setManualColumnWidths(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $startColumnIndex = 1): void {
    $columnWidthsByOffset = [
        0 => 2,   // A
        1 => 2,   // B
        2 => 50,  // C
        3 => 1,   // D
        4 => 20,  // E
        5 => 20,  // F
        6 => 20,  // G
        7 => 2,   // H
        8 => 20,  // I
        9 => 20,  // J
        10 => 20, // K
        11 => 2,  // L
        12 => 20, // M
        13 => 15, // N
        14 => 2,  // O
    ];

    foreach ($columnWidthsByOffset as $offset => $width) {
        $sheet->getColumnDimension(colLetter($startColumnIndex + $offset))->setWidth($width);
    }
}

// Function to apply border styles to specific rows
function applyBorderStyles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $label, int $startColumnIndex = 1): void {
    $columnOffsets = [4, 5, 6, 7, 8, 9, 10, 11, 12, 13];
    
    if ($label == '21') {
        foreach ($columnOffsets as $offset) {
            $sheet->getStyle(cellAddr($startColumnIndex, $offset, $row))->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN);
        }
    } elseif (in_array($label, [
        "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
        'EARNINGS BEFORE INTEREST & TAXES',
        'EARNINGS BEFORE TAXES'
    ])) {
        foreach ($columnOffsets as $offset) {
            $sheet->getStyle(cellAddr($startColumnIndex, $offset, $row))->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THIN);
        }
    } elseif ($label == 'TOTAL NET INCOME/LOSS') {
        foreach ($columnOffsets as $offset) {
            $sheet->getStyle(cellAddr($startColumnIndex, $offset, $row))->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle(cellAddr($startColumnIndex, $offset, $row))->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_DOUBLE);
        }
    }
}

// Create new spreadsheet
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($_SESSION['full_name'] ?? 'FS Reports User')
    ->setLastModifiedBy($_SESSION['full_name'] ?? 'FS Reports User')
    ->setTitle('Comparative Profit & Loss Statement')
    ->setSubject('Comparative Report')
    ->setDescription('Comparative Profit & Loss Statement Export');

// Build export data - ONE SHEET PER REGION with all areas side by side
$region_groups = [];

if (!empty($selected_branches)) {
    // If branches are selected, group by region
    foreach ($selected_branches as $branch_key) {
        if (isset($branch_info[$branch_key])) {
            $info = $branch_info[$branch_key];
            $region = $info['region'] ?: 'Unknown Region';
            $area = $info['area'] ?: 'Unknown Area';
            $branch_name = $info['name'];
            $branch_id = $info['id'];
            
            if (!isset($region_groups[$region])) {
                $region_groups[$region] = [];
            }
            $region_groups[$region][] = [
                'area' => $area,
                'branches' => [$branch_key],
                'branch_name' => $branch_name,
                'branch_id' => $branch_id,
                'is_individual_branch' => true
            ];
        }
    }
} else {
    // No branches selected - group by region with areas
    if (!empty($selected_regions)) {
        foreach ($selected_regions as $rg) {
            $allowed_areas = $reg_to_area[$rg] ?? [];
            $areas_for_region = [];

            if (!empty($selected_areas)) {
                foreach ($selected_areas as $ar) {
                    if (in_array($ar, $allowed_areas, true)) {
                        $areas_for_region[] = $ar;
                    }
                }
            }

            if (empty($areas_for_region)) {
                $areas_for_region = [''];
            }

            $region_groups[$rg] = [];
            foreach ($areas_for_region as $ar) {
                $region_groups[$rg][] = [
                    'area' => $ar,
                    'branches' => [],
                    'branch_name' => '',
                    'branch_id' => '',
                    'is_individual_branch' => false
                ];
            }
        }
    } else {
        // No region selected - nationwide
        $region_groups['NATIONWIDE'] = [[
            'area' => '',
            'branches' => [],
            'branch_name' => '',
            'branch_id' => '',
            'is_individual_branch' => false
        ]];
    }
}

$sheet_index = 0;

foreach ($region_groups as $region_name => $area_combos) {
    // Create one sheet per region
    if ($sheet_index > 0) {
        $spreadsheet->createSheet();
    }
    $sheet = $spreadsheet->getSheet($sheet_index);
    $sheet->setShowSummaryBelow(true);
    
    // Set sheet title
    $sheet_title = !empty($region_name) ? substr(strtoupper($region_name), 0, 31) : 'NATIONWIDE';
    $sheet->setTitle($sheet_title);

    // Format period names
    $primary_month_display = !empty($primary_period) ? date('F Y', strtotime($primary_period . '-01')) : '(Primary Period)';
    $previous_month_display = !empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous Period)';
    $primary_month_only = !empty($primary_period) ? date('F', strtotime($primary_period . '-01')) : '(Primary)';
    $previous_month_only = !empty($previous_period) ? date('F', strtotime($previous_period . '-01')) : '(Previous)';
    
    $region_display = !empty($region_name) ? strtoupper($region_name) : 'NATIONWIDE';
    
    // Get logo path
    $logo_path = __DIR__ . '/../images/weblogo.png';
    $logo_exists = file_exists($logo_path);
    
    // For each area in this region, place it side by side using 15 columns each
    foreach ($area_combos as $area_index => $area_combo) {
        $startColumnIndex = 1 + ($area_index * 15);
        $area_name = $area_combo['area'];
        $branches = $area_combo['branches'] ?? [];
        $branch_name = $area_combo['branch_name'] ?? '';
        $branch_id = $area_combo['branch_id'] ?? '';
        $is_individual_branch = $area_combo['is_individual_branch'] ?? false;
        
        setManualColumnWidths($sheet, $startColumnIndex);

        $rows = compute_table_rows_for_export(
            $conn,
            $mainzone,
            $zone,
            $transaction_year,
            $primary_period,
            $previous_period,
            $gl_code_mode,
            $gl_mapping,
            $gl_descriptions,
            $special_keys,
            $sort_order_descriptions,
            $region_name,
            $area_name,
            $branches,
            $valid_filters
        );

        // Each area block starts at row 1
        $current_row = 1;

        // Row 1: Logo (EVERY area has this)
        if ($logo_exists) {
            $sheet->getRowDimension($current_row)->setRowHeight(50);

            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo_' . $area_index);
            $drawing->setDescription('Company Logo ' . $area_index);
            $drawing->setPath($logo_path);
            $drawing->setHeight(60);
            $drawing->setCoordinates(cellAddr($startColumnIndex, 5, $current_row));
            $drawing->setOffsetX(70);
            $drawing->setWorksheet($sheet);
        }
        $current_row++;

        // Row 2: Region COMPARATIVE PROFIT & LOSS STATEMENT (EACH area has this)
        $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), $region_display . ' COMPARATIVE PROFIT & LOSS STATEMENT');
        $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 14, $current_row));
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $current_row++;

        // Row 3: MLFSI & JEWELERS (EACH area has this)
        $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), 'MLFSI & JEWELERS');
        $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 14, $current_row));
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $current_row++;

        // Row 4: PRIMARY PERIOD VS PREVIOUS PERIOD (EACH area has this)
        $sheet->setCellValue(
            cellAddr($startColumnIndex, 0, $current_row),
            strtoupper($primary_month_display . ' VS ' . $previous_month_display)
        );
        $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 14, $current_row));
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $current_row++;

        // Row 5: AREA / BRANCH name with Area and Branch ID (EACH area has its own)
        // For individual branch, show both area and branch ID
        if ($is_individual_branch && !empty($branch_name)) {
            $area_display = 'BRANCH NAME: ' . $branch_name . ' (BRANCH ID: ' . $branch_id . ') | AREA ' . $area_name;
        } else {
            $area_display = !empty($area_name) ? 'AREA ' . $area_name : '';
        }

        $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), $area_display);
        $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 14, $current_row));
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $current_row++;

        // Row 6: Empty spacer
        $current_row++;

        // Row 7: Period headers
        $sheet->setCellValue(cellAddr($startColumnIndex, 4, $current_row), strtoupper($primary_month_only));
        $sheet->mergeCells(rangeAddr($startColumnIndex, 4, 6, $current_row));

        $sheet->setCellValue(cellAddr($startColumnIndex, 8, $current_row), strtoupper($previous_month_only));
        $sheet->mergeCells(rangeAddr($startColumnIndex, 8, 10, $current_row));

        $sheet->getStyle(rangeAddr($startColumnIndex, 4, 6, $current_row))->getFont()->setBold(true);
        $sheet->getStyle(rangeAddr($startColumnIndex, 8, 10, $current_row))->getFont()->setBold(true);

        $sheet->getStyle(rangeAddr($startColumnIndex, 4, 6, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle(rangeAddr($startColumnIndex, 8, 10, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Add background colors for period headers
        $sheet->getStyle(rangeAddr($startColumnIndex, 4, 6, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFB990');
        $sheet->getStyle(rangeAddr($startColumnIndex, 8, 10, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFB990');
        $current_row++;

        // Row 8: Column Headers
        $headers = ['', '', '', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'INC./DEC.', '%', ''];
        foreach ($headers as $offset => $value) {
            $addr = cellAddr($startColumnIndex, (int)$offset, $current_row);

            if ($value === 'INC./DEC.') {
                $richText = new RichText();
                $incPart = $richText->createTextRun('INC./');
                $incPart->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_BLACK);

                $decPart = $richText->createTextRun('DEC.');
                $decPart->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_RED);

                $sheet->setCellValue($addr, $richText);
            } else {
                $sheet->setCellValue($addr, $value);
            }

            if (!empty($value)) {
                $sheet->getStyle($addr)->getFont()->setBold(true);
                $sheet->getStyle($addr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        // Fill trailing spacer (O) with black color in the header row
        $sheet->getStyle(cellAddr($startColumnIndex, 14, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF000000');

        $sheet->getStyle(rangeAddr($startColumnIndex, 4, 10, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFA46E');
        $sheet->getStyle(rangeAddr($startColumnIndex, 12, 13, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFA46E');
        $current_row++;

        // Row 9: Empty spacer
        $sheet->getStyle(cellAddr($startColumnIndex, 14, $current_row))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF000000');
        $current_row++;

        // Row 10+: Data rows
        $data_start_row = $current_row;

        foreach ($rows as $row) {
            if (isset($row['is_section_header']) && $row['is_section_header']) {
                $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), $row['section_name']);
                $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 13, $current_row));
                $sheet->getStyle(cellAddr($startColumnIndex, 0, $current_row))->getFont()->setBold(true);
                $sheet->getStyle(rangeAddr($startColumnIndex, 0, 13, $current_row))->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFA973');
                $current_row++;
            } elseif (isset($row['is_summary_row']) && $row['is_summary_row']) {
                $inc_dec = $row['inc_dec'] ?? ($row['primary_total'] - $row['previous_total']);
                $pct = $row['percentage'] ?? (($row['previous_total'] != 0) ? ($inc_dec / abs($row['previous_total'])) * 100 : (($row['primary_total'] != 0) ? 100 : 0));

                $label = $row['sort_order'];

                // Add spacer row above specific summary rows
                if (in_array($label, [
                    'EARNINGS BEFORE INTEREST & TAXES',
                    'EARNINGS BEFORE TAXES',
                    'TOTAL NET INCOME/LOSS'
                ])) {
                    $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 13, $current_row));
                    $sheet->getRowDimension($current_row)->setRowHeight(15);
                    $current_row++;
                }

                $sortVal = $row['sort_order'];
                if (is_numeric($sortVal) && (int)$sortVal >= 1 && (int)$sortVal <= 23) {
                    $sortVal = '';
                }

                $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), $sortVal);
                $sheet->setCellValue(cellAddr($startColumnIndex, 1, $current_row), $row['gl_description']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 4, $current_row), $row['primary_mlfsi']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 5, $current_row), $row['primary_jewelers']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 6, $current_row), $row['primary_total']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 8, $current_row), $row['previous_mlfsi']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 9, $current_row), $row['previous_jewelers']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 10, $current_row), $row['previous_total']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 12, $current_row), $inc_dec);
                if ($pct >= 1000 || $pct <= -1000) {
                    $sheet->setCellValue(cellAddr($startColumnIndex, 13, $current_row), 'mat');
                    $sheet->getStyle(cellAddr($startColumnIndex, 13, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $sheet->setCellValue(cellAddr($startColumnIndex, 13, $current_row), (float)$pct);
                }

                $sheet->getStyle(rangeAddr($startColumnIndex, 0, 13, $current_row))->getFont()->setBold(true);

                // Apply background color to specific summary rows
                if (in_array($label, [
                    'TOTAL REVENUES',
                    'GROSS PROFIT',
                    'TOTAL SELLING AND ADMIN EXPENSES',
                    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                    'EARNINGS BEFORE INTEREST & TAXES',
                    'EARNINGS BEFORE TAXES',
                    'TOTAL NET INCOME/LOSS'
                ])) {
                    $sheet->getStyle(rangeAddr($startColumnIndex, 0, 13, $current_row))->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFA973');
                } elseif (is_numeric($label) && (int)$label >= 1 && (int)$label <= 20) {
                    $sortOrderNum = (int)$label;
                    if ($sortOrderNum % 2 == 0) {
                        $sheet->getStyle(rangeAddr($startColumnIndex, 0, 13, $current_row))->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFFDE9D9');
                    }
                } elseif (is_numeric($label) && (int)$label >= 21 && (int)$label <= 23) {
                    $sheet->getStyle(rangeAddr($startColumnIndex, 0, 13, $current_row))->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFDE9D9');
                }

                $sheet->getStyle(rangeAddr($startColumnIndex, 4, 6, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle(rangeAddr($startColumnIndex, 8, 10, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle(rangeAddr($startColumnIndex, 12, 13, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');

                applyBorderStyles($sheet, $current_row, (string)$label, $startColumnIndex);

                if ($inc_dec < 0) {
                    $sheet->getStyle(cellAddr($startColumnIndex, 12, $current_row))->getFont()->getColor()->setARGB('FFFF0000');
                    $sheet->getStyle(cellAddr($startColumnIndex, 13, $current_row))->getFont()->getColor()->setARGB('FFFF0000');
                }

                // Add spacer row after summary row, except for specific rows
                if (!in_array($label, [
                    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                    'EARNINGS BEFORE INTEREST & TAXES',
                    'EARNINGS BEFORE TAXES',
                    'TOTAL NET INCOME/LOSS'
                ])) {
                    $current_row++;
                    $sheet->mergeCells(rangeAddr($startColumnIndex, 0, 13, $current_row));
                    $sheet->getRowDimension($current_row)->setRowHeight(15);

                    $is_rev_related = (is_numeric($label) && (int)$label >= 1 && (int)$label <= 20);
                    if ($is_rev_related) {
                        $sheet->getRowDimension($current_row)->setOutlineLevel(1)->setVisible(false);
                    }
                }

                $current_row++;
            } else {
                // Detail row - apply INJ-2 negation
                $primary_mlfsi = $row['primary_mlfsi'];
                $primary_jewelers = $row['primary_jewelers'];
                $primary_total = $row['primary_total'];
                $previous_mlfsi = $row['previous_mlfsi'];
                $previous_jewelers = $row['previous_jewelers'];
                $previous_total = $row['previous_total'];
                
                // Apply INJ-2 negation for detail rows too
                if ($row['is_inj2']) {
                    $primary_mlfsi = -$primary_mlfsi;
                    $primary_jewelers = -$primary_jewelers;
                    $primary_total = -$primary_total;
                    $previous_mlfsi = -$previous_mlfsi;
                    $previous_jewelers = -$previous_jewelers;
                    $previous_total = -$previous_total;
                }
                
                $sheet->setCellValue(cellAddr($startColumnIndex, 0, $current_row), '');
                $sheet->setCellValue(cellAddr($startColumnIndex, 2, $current_row), $row['gl_description']);
                $sheet->setCellValue(cellAddr($startColumnIndex, 4, $current_row), $primary_mlfsi);
                $sheet->setCellValue(cellAddr($startColumnIndex, 5, $current_row), $primary_jewelers);
                $sheet->setCellValue(cellAddr($startColumnIndex, 6, $current_row), $primary_total);
                $sheet->setCellValue(cellAddr($startColumnIndex, 8, $current_row), $previous_mlfsi);
                $sheet->setCellValue(cellAddr($startColumnIndex, 9, $current_row), $previous_jewelers);
                $sheet->setCellValue(cellAddr($startColumnIndex, 10, $current_row), $previous_total);

                $inc_dec = $primary_total - $previous_total;
                $pct = ($previous_total != 0) ? ($inc_dec / abs($previous_total)) * 100 : (($primary_total != 0) ? 100 : 0);

                $sheet->setCellValue(cellAddr($startColumnIndex, 12, $current_row), $inc_dec);
                if ($pct >= 1000 || $pct <= -1000) {
                    $sheet->setCellValue(cellAddr($startColumnIndex, 13, $current_row), 'mat');
                    $sheet->getStyle(cellAddr($startColumnIndex, 13, $current_row))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $sheet->setCellValue(cellAddr($startColumnIndex, 13, $current_row), (float)$pct);
                }

                $sheet->getStyle(rangeAddr($startColumnIndex, 4, 6, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle(rangeAddr($startColumnIndex, 8, 10, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle(rangeAddr($startColumnIndex, 12, 13, $current_row))->getNumberFormat()->setFormatCode('#,##0.00');

                $sort_val = $row['sort_order'] ?? 0;
                if (is_numeric($sort_val) && (int)$sort_val >= 1 && (int)$sort_val <= 20) {
                    $sheet->getRowDimension($current_row)->setOutlineLevel(1)->setVisible(false);
                }

                if ($inc_dec < 0) {
                    $sheet->getStyle(cellAddr($startColumnIndex, 12, $current_row))->getFont()->getColor()->setARGB('FFFF0000');
                    $sheet->getStyle(cellAddr($startColumnIndex, 13, $current_row))->getFont()->getColor()->setARGB('FFFF0000');
                }
                $current_row++;
            }
        }

        // Fill trailing spacer column (O) with black color for the entire data range
        $last_data_row = $current_row - 1;
        if ($last_data_row >= $data_start_row) {
            $sheet->getStyle(
                cellAddr($startColumnIndex, 14, $data_start_row) . ':' . cellAddr($startColumnIndex, 14, $last_data_row)
            )->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF000000');
        }
    }
    
    // Freeze ONLY rows 1-10 (no column freeze)
    $sheet->freezePane('A11');
    
    $sheet_index++;
}

// Remove default empty sheet if we created additional sheets
while ($spreadsheet->getSheetCount() > count($region_groups)) {
    $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
}

// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Comparative_Report_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;
?>