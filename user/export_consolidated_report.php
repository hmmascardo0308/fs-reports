<?php
// export_consolidated_report.php

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!isset($_SESSION['username'])) {
    die("Unauthorized access");
}

// ============================================================
// GET FILTERS (Matching consolidated_with_adjustment.php)
// ============================================================
$zone = $_GET['zone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

// GL Code Mode (NEW)
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Display Mode (NEW)
$display_mode = $_GET['display_mode'] ?? 'all';
$display_mode = in_array($display_mode, ['mlfsi', 'jewelers', 'all'], true) ? $display_mode : 'all';

// Auto-calculate previous period if not provided
if (!empty($primary_period) && empty($previous_period)) {
    $date_obj = DateTime::createFromFormat('Y-m', $primary_period);
    if ($date_obj) {
        $date_obj->modify('-1 month');
        $previous_period = $date_obj->format('Y-m');
    }
}

// ============================================================
// GL CODE MODE VALIDATION
// ============================================================
$valid_filters = true;
$error_message = '';

if (!empty($primary_period)) {
    if ($gl_code_mode === 'old' && !isMarch2026OrEarlier($primary_period)) {
        $error_message = 'Old GL Code is only available for March 2026 and earlier.';
        $valid_filters = false;
    }
    if ($gl_code_mode === 'new' && !isApril2026OrLater($primary_period)) {
        $error_message = 'New GL Code is only available for April 2026 onwards.';
        $valid_filters = false;
    }
}

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2026-03-01');
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') >= strtotime('2026-04-01');
}

// ============================================================
// GL TABLE (Matching consolidated_with_adjustment.php)
// ============================================================
$gl_table = ($gl_code_mode === 'new') 
    ? 'fs_reports.new_gl_codes' 
    : 'fs_reports.gl_codes';

$report_structure = [];
$sort_order_descriptions = [];

$gl_structure_query = "
    SELECT
        sort_order,
        description,
        sub_order,
        gl_id,
        gl_description_comparative
    FROM {$gl_table}
    WHERE sort_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC, id ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);

if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $sort_order = $row['sort_order'];
        $sub_order = $row['sub_order'];
        
        $key = $sort_order . '|' . ($sub_order === null ? '' : $sub_order);
        
        if (!isset($report_structure[$key])) {
            $report_structure[$key] = [
                'sort_order' => $sort_order,
                'description' => $row['description'],
                'sub_order' => $sub_order,
                'gl_description_comparative' => $row['gl_description_comparative'],
                'gl_ids' => []
            ];
        }
        
        $gl_id = trim((string)($row['gl_id'] ?? ''));
        if ($gl_id !== '' && !in_array($gl_id, $report_structure[$key]['gl_ids'], true)) {
            $report_structure[$key]['gl_ids'][] = $gl_id;
        }
        
        if (!isset($sort_order_descriptions[$sort_order]) && !empty($row['description'])) {
            $sort_order_descriptions[$sort_order] = $row['description'];
        }
    }
}

// Sort structure
uksort($report_structure, function ($a, $b) {
    [$aSort, $aSub] = array_pad(explode('|', $a, 2), 2, '');
    [$bSort, $bSub] = array_pad(explode('|', $b, 2), 2, '');
    
    $sortCompare = (int)$aSort <=> (int)$bSort;
    if ($sortCompare !== 0) return $sortCompare;
    
    if ($aSub === '' && $bSub !== '') return -1;
    if ($aSub !== '' && $bSub === '') return 1;
    return (int)$aSub <=> (int)$bSub;
});

// ============================================================
// FETCH REGIONS IN SELECTED ZONE
// ============================================================
$regions_in_zone = [];

if (!empty($zone)) {
    $r_query = "
        SELECT DISTINCT region
        FROM fs_reports.manual_adjustment
        WHERE zone = ?
          AND region IS NOT NULL
          AND region != ''
        ORDER BY region
    ";
    
    $r_stmt = mysqli_prepare($conn, $r_query);
    if ($r_stmt) {
        mysqli_stmt_bind_param($r_stmt, 's', $zone);
        mysqli_stmt_execute($r_stmt);
        $r_res = mysqli_stmt_get_result($r_stmt);
        while ($r_row = mysqli_fetch_assoc($r_res)) {
            $r_name = trim((string)($r_row['region'] ?? ''));
            if ($r_name !== '') {
                $regions_in_zone[] = $r_name;
            }
        }
        mysqli_stmt_close($r_stmt);
    }
}

$num_regions = count($regions_in_zone);
$has_regions = $num_regions > 0;

// ============================================================
// SORT ORDER RANGES
// ============================================================
function getSortOrderRanges(string $gl_code_mode): array {
    // Both old and new use the same ranges for now
    return [
        'revenue_start' => 1,
        'revenue_end' => 20,
        'cost_of_sales' => 21,
        'sa_start' => 22,
        'sa_end' => 23,
        'depreciation' => 24,
        'interest' => 25,
        'tax' => 26
    ];
}

// ============================================================
// FETCH MANUAL ADJUSTMENT DATA
// ============================================================
function get_manual_adjustment_data_export(
    mysqli $conn,
    string $period,
    string $zone,
    string $transaction_year,
    string $region = ''
): array {
    $data = [];
    
    if (empty($period)) return $data;
    
    $parts = explode('-', $period);
    $year_val = $parts[0] ?? '';
    $month_val = $period . '-01';
    
    if ($year_val === '') return $data;
    
    $where = ["transaction_year = ?", "transaction_month = ?"];
    $params = [$year_val, $month_val];
    $types = "ss";
    
    if ($zone !== '') {
        $where[] = "zone = ?";
        $params[] = $zone;
        $types .= "s";
    }
    
    if ($transaction_year !== '') {
        $where[] = "transaction_year = ?";
        $params[] = $transaction_year;
        $types .= "s";
    }
    
    if ($region !== '') {
        $where[] = "region = ?";
        $params[] = $region;
        $types .= "s";
    }
    
    $sql = "
        SELECT
            gl_id,
            region,
            SUM(COALESCE(mlfsi, 0)) AS mlfsi_amount,
            SUM(COALESCE(jewelers, 0)) AS jewelers_amount,
            SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total_amount
        FROM fs_reports.manual_adjustment
        WHERE " . implode(" AND ", $where) . "
          AND gl_id IS NOT NULL
          AND gl_id != ''
        GROUP BY gl_id, region
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return $data;
    
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $gl_id = trim((string)($row['gl_id'] ?? ''));
        $region_name = trim((string)($row['region'] ?? ''));
        if ($gl_id === '') continue;
        
        $mlfsi = (float)($row['mlfsi_amount'] ?? 0);
        $jewelers = (float)($row['jewelers_amount'] ?? 0);
        $total = $mlfsi + $jewelers;
        
        $data[$gl_id][$region_name] = [
            'mlfsi' => $mlfsi,
            'jewelers' => $jewelers,
            'total' => $total
        ];
    }
    
    mysqli_stmt_close($stmt);
    return $data;
}

// ============================================================
// COMPUTE TABLE ROWS (Matching consolidated_with_adjustment.php)
// ============================================================
function compute_table_rows_for_export(
    mysqli $conn,
    string $zone,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    array $report_structure,
    array $sort_order_descriptions,
    array $regions_in_zone,
    string $gl_code_mode,
    string $display_mode,
    bool $use_real_data = true
): array {
    
    $ranges = getSortOrderRanges($gl_code_mode);
    
    // ========================================================
    // Primary data
    // ========================================================
    $primary_data = [];
    if ($use_real_data && !empty($primary_period)) {
        $primary_data = get_manual_adjustment_data_export(
            $conn,
            $primary_period,
            $zone,
            $transaction_year,
            ''
        );
    }
    
    // ========================================================
    // Previous data
    // ========================================================
    $previous_data = [];
    if ($use_real_data && !empty($previous_period)) {
        $previous_data = get_manual_adjustment_data_export(
            $conn,
            $previous_period,
            $zone,
            '',
            ''
        );
    }
    
    $table_rows = [];
    
    // ========================================================
    // Build report rows
    // ========================================================
    foreach ($report_structure as $key => $structure) {
        $sort_order = (int)$structure['sort_order'];
        $sub_order = $structure['sub_order'];
        $gl_description = $structure['gl_description_comparative'] ?? '';
        $gl_ids = $structure['gl_ids'] ?? [];
        $is_inj2 = in_array('INJ-2', $gl_ids, true);
        
        // Region totals
        $row_region_totals = [];
        foreach ($regions_in_zone as $r_name) {
            $row_region_totals[$r_name] = [
                'mlfsi' => 0.0,
                'jewelers' => 0.0,
                'total' => 0.0
            ];
        }
        
        // Primary total
        $primary_total = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        
        // Previous total
        $previous_total = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        
        // PRIMARY
        foreach ($gl_ids as $gl_id) {
            if (!isset($primary_data[$gl_id])) continue;
            
            foreach ($primary_data[$gl_id] as $r_name => $amounts) {
                $mlfsi = (float)($amounts['mlfsi'] ?? 0);
                $jewelers = (float)($amounts['jewelers'] ?? 0);
                $region_total = $mlfsi + $jewelers;
                
                if (isset($row_region_totals[$r_name])) {
                    $row_region_totals[$r_name]['mlfsi'] += $mlfsi;
                    $row_region_totals[$r_name]['jewelers'] += $jewelers;
                    $row_region_totals[$r_name]['total'] += $region_total;
                }
                
                $primary_total['mlfsi'] += $mlfsi;
                $primary_total['jewelers'] += $jewelers;
                $primary_total['total'] += $region_total;
            }
        }
        
        // PREVIOUS
        foreach ($gl_ids as $gl_id) {
            if (!isset($previous_data[$gl_id])) continue;
            
            foreach ($previous_data[$gl_id] as $r_name => $amounts) {
                $mlfsi = (float)($amounts['mlfsi'] ?? 0);
                $jewelers = (float)($amounts['jewelers'] ?? 0);
                
                $previous_total['mlfsi'] += $mlfsi;
                $previous_total['jewelers'] += $jewelers;
                $previous_total['total'] += ($mlfsi + $jewelers);
            }
        }
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'primary_total' => $primary_total,
            'previous_total' => $previous_total,
            'region_totals' => $row_region_totals,
            'is_inj2' => $is_inj2
        ];
    }
    
    // ========================================================
    // GROUP BY SORT ORDER
    // ========================================================
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }
    
    $final_table_rows = [];
    
    // ========================================================
    // CUMULATIVE TOTALS
    // ========================================================
    $rev_mlfsi_p = 0.0;
    $rev_jew_p = 0.0;
    $rev_tot_p = 0.0;
    $rev_mlfsi_prev = 0.0;
    $rev_jew_prev = 0.0;
    $rev_tot_prev = 0.0;
    
    $sa_mlfsi_p = 0.0;
    $sa_jew_p = 0.0;
    $sa_tot_p = 0.0;
    $sa_mlfsi_prev = 0.0;
    $sa_jew_prev = 0.0;
    $sa_tot_prev = 0.0;
    
    $gp_mlfsi_p = 0.0;
    $gp_jew_p = 0.0;
    $gp_tot_p = 0.0;
    $gp_mlfsi_prev = 0.0;
    $gp_jew_prev = 0.0;
    $gp_tot_prev = 0.0;
    
    $ebitda_mlfsi_p = 0.0;
    $ebitda_jew_p = 0.0;
    $ebitda_tot_p = 0.0;
    $ebitda_mlfsi_prev = 0.0;
    $ebitda_jew_prev = 0.0;
    $ebitda_tot_prev = 0.0;
    
    $ebit_mlfsi_p = 0.0;
    $ebit_jew_p = 0.0;
    $ebit_tot_p = 0.0;
    $ebit_mlfsi_prev = 0.0;
    $ebit_jew_prev = 0.0;
    $ebit_tot_prev = 0.0;
    
    $ebt_mlfsi_p = 0.0;
    $ebt_jew_p = 0.0;
    $ebt_tot_p = 0.0;
    $ebt_mlfsi_prev = 0.0;
    $ebt_jew_prev = 0.0;
    $ebt_tot_prev = 0.0;
    
    $net_mlfsi_p = 0.0;
    $net_jew_p = 0.0;
    $net_tot_p = 0.0;
    $net_mlfsi_prev = 0.0;
    $net_jew_prev = 0.0;
    $net_tot_prev = 0.0;
    
    $rev_reg_p = [];
    $sa_reg_p = [];
    $gp_reg_p = [];
    $ebitda_reg_p = [];
    $ebit_reg_p = [];
    $ebt_reg_p = [];
    $net_reg_p = [];
    
    // ========================================================
    // PROCESS SORT ORDERS
    // ========================================================
    foreach ($grouped_rows as $sort_order => $rows) {
        $sort_num = (int)$sort_order;
        
        // Check if this is a revenue sort order (1-20)
        $is_revenue = ($sort_num >= $ranges['revenue_start'] && $sort_num <= $ranges['revenue_end']);
        
        // Hide detail rows for direct-total rows (6, 8, 11)
        if (!in_array($sort_num, [6, 8, 11], true)) {
            foreach ($rows as $row) {
                // Mark revenue detail rows for collapse
                if ($is_revenue) {
                    $row['is_revenue_detail'] = true;
                    $row['is_collapsible_detail'] = true;
                }
                $final_table_rows[] = $row;
            }
        }
        
        // Sort order total
        $total_primary = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        $total_previous = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        
        foreach ($rows as $row) {
            $total_primary['mlfsi'] += $row['primary_total']['mlfsi'];
            $total_primary['jewelers'] += $row['primary_total']['jewelers'];
            $total_primary['total'] += ($row['primary_total']['mlfsi'] + $row['primary_total']['jewelers']);
            
            $total_previous['mlfsi'] += $row['previous_total']['mlfsi'];
            $total_previous['jewelers'] += $row['previous_total']['jewelers'];
            $total_previous['total'] += ($row['previous_total']['mlfsi'] + $row['previous_total']['jewelers']);
        }
        
        // Region summary totals
        $summary_region_totals = [];
        foreach ($regions_in_zone as $r_name) {
            $summary_region_totals[$r_name] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
            foreach ($rows as $row) {
                if (isset($row['region_totals'][$r_name])) {
                    $summary_region_totals[$r_name]['mlfsi'] += $row['region_totals'][$r_name]['mlfsi'];
                    $summary_region_totals[$r_name]['jewelers'] += $row['region_totals'][$r_name]['jewelers'];
                    $summary_region_totals[$r_name]['total'] += (
                        $row['region_totals'][$r_name]['mlfsi'] + 
                        $row['region_totals'][$r_name]['jewelers']
                    );
                }
            }
        }
        
        // TOTAL REVENUES
        if ($is_revenue) {
            $rev_tot_p += $total_primary['mlfsi'] + $total_primary['jewelers'];
            $rev_tot_prev += $total_previous['mlfsi'] + $total_previous['jewelers'];
            $rev_mlfsi_p += $total_primary['mlfsi'];
            $rev_mlfsi_prev += $total_previous['mlfsi'];
            $rev_jew_p += $total_primary['jewelers'];
            $rev_jew_prev += $total_previous['jewelers'];
            
            foreach ($regions_in_zone as $rn) {
                if (!isset($rev_reg_p[$rn])) {
                    $rev_reg_p[$rn] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
                }
                $rev_reg_p[$rn]['mlfsi'] += $summary_region_totals[$rn]['mlfsi'];
                $rev_reg_p[$rn]['jewelers'] += $summary_region_totals[$rn]['jewelers'];
                $rev_reg_p[$rn]['total'] += ($summary_region_totals[$rn]['mlfsi'] + $summary_region_totals[$rn]['jewelers']);
            }
        }
        
        // SELLING & ADMIN EXPENSES
        if ($sort_num >= $ranges['sa_start'] && $sort_num <= $ranges['sa_end']) {
            $sa_tot_p += $total_primary['mlfsi'] + $total_primary['jewelers'];
            $sa_tot_prev += $total_previous['mlfsi'] + $total_previous['jewelers'];
            $sa_mlfsi_p += $total_primary['mlfsi'];
            $sa_mlfsi_prev += $total_previous['mlfsi'];
            $sa_jew_p += $total_primary['jewelers'];
            $sa_jew_prev += $total_previous['jewelers'];
            
            foreach ($regions_in_zone as $rn) {
                if (!isset($sa_reg_p[$rn])) {
                    $sa_reg_p[$rn] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
                }
                $sa_reg_p[$rn]['mlfsi'] += $summary_region_totals[$rn]['mlfsi'];
                $sa_reg_p[$rn]['jewelers'] += $summary_region_totals[$rn]['jewelers'];
                $sa_reg_p[$rn]['total'] += ($summary_region_totals[$rn]['mlfsi'] + $summary_region_totals[$rn]['jewelers']);
            }
        }
        
        // Summary row (skip depreciation, interest, tax - handled separately)
        if (!in_array($sort_num, [$ranges['depreciation'], $ranges['interest'], $ranges['tax']], true)) {
            $description = $sort_order_descriptions[$sort_num] ?? "Total for Sort Order " . $sort_num;
            
            $inc_dec = $total_primary['total'] - $total_previous['total'];
            $percentage = 0.0;
            if ($total_previous['total'] != 0) {
                $percentage = ($inc_dec / $total_previous['total']) * 100;
            } elseif ($total_primary['total'] != 0) {
                $percentage = 100;
            }
            
            $summary_row = [
                'sort_order' => $sort_num,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => [
                    'mlfsi' => $total_primary['mlfsi'],
                    'jewelers' => $total_primary['jewelers'],
                    'total' => $total_primary['mlfsi'] + $total_primary['jewelers']
                ],
                'previous_total' => [
                    'mlfsi' => $total_previous['mlfsi'],
                    'jewelers' => $total_previous['jewelers'],
                    'total' => $total_previous['mlfsi'] + $total_previous['jewelers']
                ],
                'region_totals' => $summary_region_totals,
                'inc_dec' => $inc_dec,
                'percentage' => $percentage
            ];
            
            // Mark revenue summary rows for collapse
            if ($is_revenue) {
                $summary_row['is_revenue_summary'] = true;
                $summary_row['is_collapsible_summary'] = true;
            }
            
            $final_table_rows[] = $summary_row;
            
            // Add spacer after every sort_order total row (except for revenue rows where spacer is already handled differently)
            // For revenue rows, we add a collapsible spacer
            // For non-revenue rows, we add a regular spacer
            if (!$is_revenue) {
                // Non-revenue rows get a regular spacer
                $final_table_rows[] = ['is_manual_spacer' => true];
            } else {
                // Revenue rows get a collapsible spacer (hidden during collapse)
                $spacer_row = ['is_manual_spacer' => true];
                $spacer_row['is_revenue_spacer'] = true;
                $spacer_row['is_collapsible_spacer'] = true;
                $final_table_rows[] = $spacer_row;
            }
            
            // Additional spacer for S&A range (22-23) - only one extra spacer
            if ($sort_num >= $ranges['sa_start'] && $sort_num <= $ranges['sa_end']) {
                $final_table_rows[] = ['is_manual_spacer' => true];
            }
        }
        
        // TOTAL REVENUES
        if ($sort_num == $ranges['revenue_end']) {
            $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
            $pct_rev = $rev_tot_prev != 0 ? ($inc_dec_rev / abs($rev_tot_prev)) * 100 : ($rev_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'is_total_revenues' => true,
                'primary_total' => [
                    'mlfsi' => $rev_mlfsi_p,
                    'jewelers' => $rev_jew_p,
                    'total' => $rev_mlfsi_p + $rev_jew_p
                ],
                'previous_total' => [
                    'mlfsi' => $rev_mlfsi_prev,
                    'jewelers' => $rev_jew_prev,
                    'total' => $rev_mlfsi_prev + $rev_jew_prev
                ],
                'region_totals' => $rev_reg_p,
                'inc_dec' => $inc_dec_rev,
                'percentage' => $pct_rev
            ];
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            $final_table_rows[] = [
                'sort_order' => '',
                'sub_order' => 'Cost of Sales/Service',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'inc_dec' => null,
                'percentage' => null
            ];
        }
        
        // GROSS PROFIT
        if ($sort_num == $ranges['cost_of_sales']) {
            $gp_tot_p = $rev_tot_p - ($total_primary['mlfsi'] + $total_primary['jewelers']);
            $gp_tot_prev = $rev_tot_prev - ($total_previous['mlfsi'] + $total_previous['jewelers']);
            $gp_mlfsi_p = $rev_mlfsi_p - $total_primary['mlfsi'];
            $gp_mlfsi_prev = $rev_mlfsi_prev - $total_previous['mlfsi'];
            $gp_jew_p = $rev_jew_p - $total_primary['jewelers'];
            $gp_jew_prev = $rev_jew_prev - $total_previous['jewelers'];
            
            $gp_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $gp_reg_p[$rn] = [
                    'mlfsi' => ($rev_reg_p[$rn]['mlfsi'] ?? 0) - ($summary_region_totals[$rn]['mlfsi'] ?? 0),
                    'jewelers' => ($rev_reg_p[$rn]['jewelers'] ?? 0) - ($summary_region_totals[$rn]['jewelers'] ?? 0),
                    'total' => ($rev_reg_p[$rn]['total'] ?? 0) - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }
            
            $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
            $pct_gp = $gp_tot_prev != 0 ? ($inc_dec_gp / abs($gp_tot_prev)) * 100 : ($gp_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => [
                    'mlfsi' => $gp_mlfsi_p,
                    'jewelers' => $gp_jew_p,
                    'total' => $gp_tot_p
                ],
                'previous_total' => [
                    'mlfsi' => $gp_mlfsi_prev,
                    'jewelers' => $gp_jew_prev,
                    'total' => $gp_tot_prev
                ],
                'region_totals' => $gp_reg_p,
                'inc_dec' => $inc_dec_gp,
                'percentage' => $pct_gp
            ];
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            $final_table_rows[] = [
                'sort_order' => 'SELLING & ADMIN EXPENSE',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'inc_dec' => null,
                'percentage' => null
            ];
        }
        
        // TOTAL SELLING & ADMIN EXPENSES / EBITDA
        if ($sort_num == $ranges['sa_end']) {
            $sa_total = $sa_mlfsi_p + $sa_jew_p;
            $sa_total_prev = $sa_mlfsi_prev + $sa_jew_prev;
            
            $inc_dec_sa = $sa_total - $sa_total_prev;
            $pct_sa = $sa_total_prev != 0 ? ($inc_dec_sa / abs($sa_total_prev)) * 100 : ($sa_total != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => [
                    'mlfsi' => $sa_mlfsi_p,
                    'jewelers' => $sa_jew_p,
                    'total' => $sa_total
                ],
                'previous_total' => [
                    'mlfsi' => $sa_mlfsi_prev,
                    'jewelers' => $sa_jew_prev,
                    'total' => $sa_total_prev
                ],
                'region_totals' => $sa_reg_p,
                'inc_dec' => $inc_dec_sa,
                'percentage' => $pct_sa
            ];
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            // EBITDA
            $ebitda_tot_p = $gp_tot_p - $sa_total;
            $ebitda_tot_prev = $gp_tot_prev - $sa_total_prev;
            $ebitda_mlfsi_p = $gp_mlfsi_p - $sa_mlfsi_p;
            $ebitda_mlfsi_prev = $gp_mlfsi_prev - $sa_mlfsi_prev;
            $ebitda_jew_p = $gp_jew_p - $sa_jew_p;
            $ebitda_jew_prev = $gp_jew_prev - $sa_jew_prev;
            
            $ebitda_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebitda_reg_p[$rn] = [
                    'mlfsi' => ($gp_reg_p[$rn]['mlfsi'] ?? 0) - ($sa_reg_p[$rn]['mlfsi'] ?? 0),
                    'jewelers' => ($gp_reg_p[$rn]['jewelers'] ?? 0) - ($sa_reg_p[$rn]['jewelers'] ?? 0),
                    'total' => ($gp_reg_p[$rn]['total'] ?? 0) - ($sa_reg_p[$rn]['total'] ?? 0)
                ];
            }
            
            $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
            $pct_ebitda = $ebitda_tot_prev != 0 ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100 : ($ebitda_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => [
                    'mlfsi' => $ebitda_mlfsi_p,
                    'jewelers' => $ebitda_jew_p,
                    'total' => $ebitda_tot_p
                ],
                'previous_total' => [
                    'mlfsi' => $ebitda_mlfsi_prev,
                    'jewelers' => $ebitda_jew_prev,
                    'total' => $ebitda_tot_prev
                ],
                'region_totals' => $ebitda_reg_p,
                'inc_dec' => $inc_dec_ebitda,
                'percentage' => $pct_ebitda
            ];
            
            // No spacer after EBITDA (skip_spacer is true)
        }
        
        // EBIT
        if ($sort_num == $ranges['depreciation']) {
            $ebit_tot_p = $ebitda_tot_p - ($total_primary['mlfsi'] + $total_primary['jewelers']);
            $ebit_tot_prev = $ebitda_tot_prev - ($total_previous['mlfsi'] + $total_previous['jewelers']);
            $ebit_mlfsi_p = $ebitda_mlfsi_p - $total_primary['mlfsi'];
            $ebit_mlfsi_prev = $ebitda_mlfsi_prev - $total_previous['mlfsi'];
            $ebit_jew_p = $ebitda_jew_p - $total_primary['jewelers'];
            $ebit_jew_prev = $ebitda_jew_prev - $total_previous['jewelers'];
            
            $ebit_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebit_reg_p[$rn] = [
                    'mlfsi' => ($ebitda_reg_p[$rn]['mlfsi'] ?? 0) - ($summary_region_totals[$rn]['mlfsi'] ?? 0),
                    'jewelers' => ($ebitda_reg_p[$rn]['jewelers'] ?? 0) - ($summary_region_totals[$rn]['jewelers'] ?? 0),
                    'total' => ($ebitda_reg_p[$rn]['total'] ?? 0) - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }
            
            $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
            $pct_ebit = $ebit_tot_prev != 0 ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100 : ($ebit_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => [
                    'mlfsi' => $ebit_mlfsi_p,
                    'jewelers' => $ebit_jew_p,
                    'total' => $ebit_tot_p
                ],
                'previous_total' => [
                    'mlfsi' => $ebit_mlfsi_prev,
                    'jewelers' => $ebit_jew_prev,
                    'total' => $ebit_tot_prev
                ],
                'region_totals' => $ebit_reg_p,
                'inc_dec' => $inc_dec_ebit,
                'percentage' => $pct_ebit
            ];
            
            // No spacer after EBIT (skip_spacer is true)
        }
        
        // EBT
        if ($sort_num == $ranges['interest']) {
            $ebt_tot_p = $ebit_tot_p - ($total_primary['mlfsi'] + $total_primary['jewelers']);
            $ebt_tot_prev = $ebit_tot_prev - ($total_previous['mlfsi'] + $total_previous['jewelers']);
            $ebt_mlfsi_p = $ebit_mlfsi_p - $total_primary['mlfsi'];
            $ebt_mlfsi_prev = $ebit_mlfsi_prev - $total_previous['mlfsi'];
            $ebt_jew_p = $ebit_jew_p - $total_primary['jewelers'];
            $ebt_jew_prev = $ebit_jew_prev - $total_previous['jewelers'];
            
            $ebt_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebt_reg_p[$rn] = [
                    'mlfsi' => ($ebit_reg_p[$rn]['mlfsi'] ?? 0) - ($summary_region_totals[$rn]['mlfsi'] ?? 0),
                    'jewelers' => ($ebit_reg_p[$rn]['jewelers'] ?? 0) - ($summary_region_totals[$rn]['jewelers'] ?? 0),
                    'total' => ($ebit_reg_p[$rn]['total'] ?? 0) - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }
            
            $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
            $pct_ebt = $ebt_tot_prev != 0 ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100 : ($ebt_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => [
                    'mlfsi' => $ebt_mlfsi_p,
                    'jewelers' => $ebt_jew_p,
                    'total' => $ebt_tot_p
                ],
                'previous_total' => [
                    'mlfsi' => $ebt_mlfsi_prev,
                    'jewelers' => $ebt_jew_prev,
                    'total' => $ebt_tot_prev
                ],
                'region_totals' => $ebt_reg_p,
                'inc_dec' => $inc_dec_ebt,
                'percentage' => $pct_ebt
            ];
            
            // No spacer after EBT (skip_spacer is true)
        }
        
        // NET INCOME
        if ($sort_num == $ranges['tax']) {
            $net_tot_p = $ebt_tot_p - ($total_primary['mlfsi'] + $total_primary['jewelers']);
            $net_tot_prev = $ebt_tot_prev - ($total_previous['mlfsi'] + $total_previous['jewelers']);
            $net_mlfsi_p = $ebt_mlfsi_p - $total_primary['mlfsi'];
            $net_mlfsi_prev = $ebt_mlfsi_prev - $total_previous['mlfsi'];
            $net_jew_p = $ebt_jew_p - $total_primary['jewelers'];
            $net_jew_prev = $ebt_jew_prev - $total_previous['jewelers'];
            
            $net_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $net_reg_p[$rn] = [
                    'mlfsi' => ($ebt_reg_p[$rn]['mlfsi'] ?? 0) - ($summary_region_totals[$rn]['mlfsi'] ?? 0),
                    'jewelers' => ($ebt_reg_p[$rn]['jewelers'] ?? 0) - ($summary_region_totals[$rn]['jewelers'] ?? 0),
                    'total' => ($ebt_reg_p[$rn]['total'] ?? 0) - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }
            
            $inc_dec_net = $net_tot_p - $net_tot_prev;
            $pct_net = $net_tot_prev != 0 ? ($inc_dec_net / abs($net_tot_prev)) * 100 : ($net_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = ['is_manual_spacer' => true];
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL NET INCOME/LOSS',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => [
                    'mlfsi' => $net_mlfsi_p,
                    'jewelers' => $net_jew_p,
                    'total' => $net_tot_p
                ],
                'previous_total' => [
                    'mlfsi' => $net_mlfsi_prev,
                    'jewelers' => $net_jew_prev,
                    'total' => $net_tot_prev
                ],
                'region_totals' => $net_reg_p,
                'inc_dec' => $inc_dec_net,
                'percentage' => $pct_net
            ];
            
            // No spacer after NET INCOME (it's the final row)
        }
    }
    
    return $final_table_rows;
}

// ============================================================
// COMPUTE DATA
// ============================================================
$data_rows = compute_table_rows_for_export(
    $conn,
    $zone,
    $transaction_year,
    $primary_period,
    $previous_period,
    $report_structure,
    $sort_order_descriptions,
    $regions_in_zone,
    $gl_code_mode,
    $display_mode,
    $valid_filters
);

// ============================================================
// SPREADSHEET SETUP
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Consolidated Report');
$sheet->freezePane('E12');

function colLetter(int $col): string {
    return Coordinate::stringFromColumnIndex($col);
}

// Header layout
$row = 1;
$lastColIdx = 5 + $num_regions;
$lastCol = colLetter($lastColIdx);

// Logo
$logoColIdx = max(1, (int) floor($lastColIdx / 2));
$logoColLetter = colLetter($logoColIdx);

$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $sheet->getRowDimension($row)->setRowHeight(55);
    $drawing = new Drawing();
    $drawing->setPath($logo_path);
    $drawing->setHeight(60);
    $drawing->setCoordinates($logoColLetter . '1');
    $drawing->setWorksheet($sheet);
}
$row++;

// Title
$zone_display = 'All Zones';
if (!empty($zone)) {
    $zone_map = ['VIS' => 'VISAYAS', 'LZN' => 'LUZON', 'NCR' => 'NCR', 'MIN' => 'MINDANAO'];
    $zone_display = isset($zone_map[$zone]) ? $zone_map[$zone] : $zone;
}
$sheet->setCellValue("A$row", ($zone ? $zone_display : "All Zones") . " CONSOLIDATED PROFIT & LOSS STATEMENT");
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Branch type header - Change based on display mode
$display_label = '';
if ($display_mode === 'mlfsi') {
    $display_label = 'MLFSI - PER REGION';
} elseif ($display_mode === 'jewelers') {
    $display_label = 'JEWELERS - PER REGION';
} else {
    $display_label = 'MLFSI & JEWELERS - PER REGION';
}
$sheet->setCellValue("A$row", $display_label);
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Period display
if (!empty($primary_period)) {
    $ts = strtotime($primary_period . '-01');
    $period_display = "FOR THE MONTH ENDED " . strtoupper(date('F', $ts)) . " " . date('t', $ts) . ", " . date('Y', $ts);
} else {
    $period_display = '(PRIMARY PERIOD)';
}
$sheet->setCellValue("A$row", $period_display);
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 4;

// Region headers
$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->setBold(true);
$sheet->getStyle("E$row:{$lastCol}$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("E$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->getColor()->setARGB(Color::COLOR_WHITE);

$colIdx = 5;
foreach ($regions_in_zone as $region) {
    $sheet->setCellValue(colLetter($colIdx) . $row, $region);
    $colIdx++;
}
$sheet->setCellValue(colLetter($colIdx) . $row, "GRAND TOTAL");

$row++;

// Amount header
$sheet->setCellValue("A$row", "");
$sheet->setCellValue("B$row", "Description");
$sheet->setCellValue("C$row", "Comparative Description");
$sheet->setCellValue("D$row", "");

$colIdx = 5;
foreach ($regions_in_zone as $region) {
    $sheet->setCellValue(colLetter($colIdx) . $row, "Total");
    $colIdx++;
}
$sheet->setCellValue(colLetter($colIdx) . $row, "Total");

$sheet->getStyle("A$row:{$lastCol}$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:{$lastCol}$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCCCCC');

$row += 2;

// REVENUES header - make it a collapsible toggle row
$revenues_row_start = $row;
$sheet->setCellValue("A$row", "REVENUES");
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Add collapse/expand indicator and toggle note
$sheet->setCellValue("D$row", "▼ Click to expand/collapse revenue detail");
$sheet->getStyle("D$row")->getFont()->setSize(9);
$sheet->getStyle("D$row")->getFont()->getColor()->setARGB('FF666666');
$sheet->getStyle("D$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$revenues_row = $row;
$row++;

// Store the start row of revenue details
$revenue_details_start = $row;

// ============================================================
// DATA ROWS
// ============================================================
$highlight_labels = [
    'TOTAL REVENUES',
    'GROSS PROFIT',
    'TOTAL SELLING AND ADMIN EXPENSES',
    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
    'EARNINGS BEFORE INTEREST & TAXES',
    'EARNINGS BEFORE TAXES',
    'TOTAL NET INCOME/LOSS'
];

foreach ($data_rows as $idx => $item) {
    if (isset($item['is_manual_spacer'])) {
        // Check if this is a revenue spacer that should be hidden during collapse
        $is_revenue_spacer = isset($item['is_revenue_spacer']) && $item['is_revenue_spacer'] === true;
        $is_collapsible_spacer = isset($item['is_collapsible_spacer']) && $item['is_collapsible_spacer'] === true;
        
        if ($is_collapsible_spacer) {
            // Set outline level for revenue spacers (same as detail rows - level 2)
            // This hides them when collapsed
            $sheet->getRowDimension($row)->setOutlineLevel(2);
            // Make them very small
            $sheet->getRowDimension($row)->setRowHeight(2);
        }
        $row++;
        continue;
    }
    
    if (!empty($item['is_section_header'])) {
        $label = $item['sub_order'] ?: $item['sort_order'];
        $sheet->setCellValue("A$row", $label);
        $sheet->mergeCells("A$row:{$lastCol}$row");
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFA973');
        $row++;
        continue;
    }
    
    $is_sum = $item['is_summary_row'] ?? false;
    
    if ($is_sum) {
        $label = $item['sort_order'];
        
        // Sort order column
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 25) ? '' : $label);
        
        // Description column (B)
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        
        // Amount columns - using display mode
        $colIdx = 5;
        $primary_total = $item['primary_total'] ?? ['mlfsi' => 0, 'jewelers' => 0, 'total' => 0];
        
        // Get display amount for grand total
        if ($display_mode === 'mlfsi') {
            $grand_total = (float)($primary_total['mlfsi'] ?? 0);
        } elseif ($display_mode === 'jewelers') {
            $grand_total = (float)($primary_total['jewelers'] ?? 0);
        } else {
            $grand_total = (float)($primary_total['total'] ?? 0);
        }
        
        foreach ($regions_in_zone as $rn) {
            $r_amt = $item['region_totals'][$rn] ?? ['mlfsi' => 0, 'jewelers' => 0, 'total' => 0];
            if ($display_mode === 'mlfsi') {
                $region_total = (float)($r_amt['mlfsi'] ?? 0);
            } elseif ($display_mode === 'jewelers') {
                $region_total = (float)($r_amt['jewelers'] ?? 0);
            } else {
                $region_total = (float)($r_amt['total'] ?? 0);
            }
            $sheet->setCellValue(colLetter($colIdx) . $row, $region_total);
            $colIdx++;
        }
        $sheet->setCellValue(colLetter($colIdx) . $row, $grand_total);
        
        // Bold and background
        $sheet->getStyle("A$row:{$lastCol}$row")->getFont()->setBold(true);
        
        $bg = in_array($label, $highlight_labels, true) 
            ? 'FFFFA973' 
            : (is_numeric($label) && (int)$label % 2 != 0 ? null : 'FFFDE9D9');
        
        if ($bg) {
            $sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        }
        
        // Borders
        if ($label == '21') {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        
        if (in_array($label, ["EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES'])) {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }
        
        if ($label === 'TOTAL NET INCOME/LOSS') {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }
        
        // Track revenue summary rows for collapse
        if (isset($item['is_revenue_summary']) && $item['is_revenue_summary'] === true) {
            // Set outline level for summary rows (level 1 - parent)
            $sheet->getRowDimension($row)->setOutlineLevel(1);
        }
        
        // Mark TOTAL REVENUES row
        if (isset($item['is_total_revenues']) && $item['is_total_revenues'] === true) {
            $sheet->getRowDimension($row)->setOutlineLevel(0);
        }
        
    } else {
        // Detail row
        $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        
        $colIdx = 5;
        $primary_total = $item['primary_total'] ?? ['mlfsi' => 0, 'jewelers' => 0, 'total' => 0];
        $is_inj2 = $item['is_inj2'] ?? false;
        
        if ($display_mode === 'mlfsi') {
            $grand_total = (float)($primary_total['mlfsi'] ?? 0);
        } elseif ($display_mode === 'jewelers') {
            $grand_total = (float)($primary_total['jewelers'] ?? 0);
        } else {
            $grand_total = (float)($primary_total['total'] ?? 0);
        }
        
        if ($is_inj2) $grand_total = -$grand_total;
        
        foreach ($regions_in_zone as $rn) {
            $r_amt = $item['region_totals'][$rn] ?? ['mlfsi' => 0, 'jewelers' => 0, 'total' => 0];
            if ($display_mode === 'mlfsi') {
                $region_total = (float)($r_amt['mlfsi'] ?? 0);
            } elseif ($display_mode === 'jewelers') {
                $region_total = (float)($r_amt['jewelers'] ?? 0);
            } else {
                $region_total = (float)($r_amt['total'] ?? 0);
            }
            if ($is_inj2) $region_total = -$region_total;
            $sheet->setCellValue(colLetter($colIdx) . $row, $region_total);
            $colIdx++;
        }
        $sheet->setCellValue(colLetter($colIdx) . $row, $grand_total);
        
        // Track revenue detail rows for collapse
        if (isset($item['is_revenue_detail']) && $item['is_revenue_detail'] === true) {
            // Set outline level for detail rows (level 2 - child)
            $sheet->getRowDimension($row)->setOutlineLevel(2);
        }
    }
    
    // Formatting
    $dataRange = colLetter(5) . "$row:{$lastCol}$row";
    $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    // Conditional formatting for negative values
    $conditional = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
    $conditional->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS)
                ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_LESSTHAN)
                ->addCondition('0');
    $conditional->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);
    $sheet->getStyle($dataRange)->setConditionalStyles([$conditional]);
    
    $row++;
}

// ============================================================
// COLUMN WIDTHS
// ============================================================
$sheet->getColumnDimension('A')->setWidth(2);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(35);
$sheet->getColumnDimension('D')->setWidth(25);

for ($i = 5; $i <= $lastColIdx; $i++) {
    $sheet->getColumnDimension(colLetter($i))->setAutoSize(true);
}

// ============================================================
// OUTPUT
// ============================================================
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="NOT YET FIXED LAYOUT_Consolidated_With_Adjustment_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;