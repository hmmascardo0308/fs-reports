<?php
/**
 * export_comparative_page_three.php
 * FIXED VERSION - Uses same data source as main report
 * Negative amounts and percentages displayed in RED with normal negative sign
 * With proper borders on specified rows
 * INCLUDES YEAR-OVER-YEAR COMPARISON TABLE
 */

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Conditional;

// ============================================================
// AUTHENTICATION CHECK
// ============================================================

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

// ============================================================
// GET FILTERS FROM URL
// ============================================================

$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

// ============================================================
// VALIDATE PERIODS (Same as main report)
// ============================================================

$valid_filters = false;
$error_message = '';

function compareMonths(string $month1, string $month2): int {
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2026-03-01');
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') >= strtotime('2026-04-01');
}

function is2025OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2025-12-01');
}

if (!empty($previous_period) && empty($primary_period)) {
    $error_message = 'Primary period is required when selecting a Previous period.';
} elseif (!empty($primary_period) && !empty($previous_period)) {
    if (compareMonths($primary_period, $previous_period) <= 0) {
        $error_message = 'Primary period must be later than the Previous period.';
    } elseif ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($primary_period) || !isMarch2026OrEarlier($previous_period)) {
            $error_message = 'Old GL Code is only available for March 2026 and earlier.';
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($primary_period) || !isApril2026OrLater($previous_period)) {
            $error_message = 'New GL Code is only available for April 2026 onwards.';
        }
    }
    
    if (empty($error_message)) {
        $valid_filters = true;
    }
}

// If filters are invalid, show error and exit
if (!$valid_filters && !empty($primary_period) && !empty($previous_period)) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h2>Export Error</h2>";
    echo "<p style='color:red;'>" . htmlspecialchars($error_message) . "</p>";
    echo "<p><a href='comparative_report_original_page_three.php'>Return to Report</a></p>";
    exit;
}

// ============================================================
// GL MAPPINGS (Copied from main report)
// ============================================================

$old_gl_mapping = [
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',
    'MLE-2' => 'MLE-3',
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'MLE-2',
    'TOI-22' => 'INJ-5',
    'TOI-23' => 'INJ-4',
    'TOI-24' => null,
    'VEH-5' => 'VEH-6',
    'VEH-6' => 'VEH-7',
    'VEH-7' => 'VEH-8',
    'VEH-8' => 'VEH-9',
    'VEH-9' => 'VEH-10',
];

$new_gl_mapping = [
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'COS-8',
    'TOI-22' => null,
    'TOI-23' => null,
    'TOI-24' => null,
    'VEH-5' => 'VEH-7',
    'VEH-6' => 'VEH-8',
    'VEH-7' => 'VEH-9',
    'VEH-8' => 'VEH-10',
    'VEH-9' => 'VEH-11',
    'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
    'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
    'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
    'INS-4' => ['INS-27', 'INS-46'],
    'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
    'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
    'INS-7' => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
    'INS-8' => ['INS-38'],
    'INS-9' => ['INS-48'],
    'INS-10' => ['INS-49'],
    'INS-11' => [],
    'INS-12' => []
];

$mixed_id_map = [];
if ($gl_code_mode === 'mixed') {
    $old_gl_id_to_codes = [];
    $res = mysqli_query($conn, "SELECT gl_id, gl_code FROM fs_reports.gl_codes_ho WHERE gl_code IS NOT NULL AND gl_code != ''");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $old_gl_id_to_codes[$row['gl_id']][] = trim($row['gl_code']);
        }
    }
    
    $mixed_id_map = [
        'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
        'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
        'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
        'INS-4' => ['INS-27', 'INS-46'],
        'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
        'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
        'INS-7' => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
        'INS-8' => ['INS-38'],
        'INS-9' => ['INS-48'],
        'INS-10' => ['INS-49'],
        'INS-11' => [],
        'INS-12' => [],
        'COS-5' => ['COS-2'],
        'COS-6' => ['COS-3'],
        'COS-7' => ['COS-4'],
        'COS-2' => [],
        'COS-3' => [],
        'COS-4' => [],
        'COS-8' => [],
        'COS-9' => [],
        'VEH-5' => [''],
        'VEH-6' => [''],
        'VEH-7' => ['VEH-5'],
        'VEH-8' => ['VEH-6'],
        'VEH-9' => ['VEH-7'],
        'VEH-10' => ['VEH-8'],
        'VEH-11' => ['VEH-9'],
        'TOI-33' => ['TOI-31'],
        'TOI-34' => ['TOI-32'],
        'TAE-23' => ['']
    ];
}

// ============================================================
// GET GL STRUCTURE
// ============================================================

function getSortOrderRanges(string $gl_code_mode): array {
    if ($gl_code_mode === 'old') {
        return [
            'revenue_start' => 1,
            'revenue_end' => 22,
            'cost_of_sales' => 23,
            'sa_start' => 24,
            'sa_end' => 25,
            'depreciation' => 26,
            'interest' => 27,
            'tax' => 28
        ];
    }
    return [
        'revenue_start' => 1,
        'revenue_end' => 23,
        'cost_of_sales' => 24,
        'sa_start' => 25,
        'sa_end' => 26,
        'depreciation' => 27,
        'interest' => 28,
        'tax' => 29
    ];
}

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes_ho' : 'fs_reports.new_gl_codes_ho';

$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];

$gl_structure_query = "
    SELECT DISTINCT
        sort_order,
        sub_order,
        gl_id,
        gl_code,
        gl_description_comparative,
        description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL
      AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);

if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        $gl_id = $row['gl_id'] ?? '';
        $gl_id_by_key[$key] = $gl_id;
        
        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }
        
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }
        
        $current_code = trim((string)($row['gl_code'] ?? ''));
        
        if ($gl_code_mode === 'mixed') {
            if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                $gl_mapping[$key]['new'][] = $current_code;
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
            if ($gl_code_mode === 'old') {
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
            } else {
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
            }
        }
        
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// ============================================================
// HELPER: GET TOTAL FOR GL ID (SAME AS MAIN REPORT)
// ============================================================

function get_gl_id_total(string $gl_id, array $gl_mapping, array $gl_id_by_key, array $data, string $mode): float {
    if (isset($data[$gl_id])) {
        return (float)$data[$gl_id];
    }
    $total = 0.0;
    foreach ($gl_mapping as $key => $codes_detailed) {
        if (($gl_id_by_key[$key] ?? '') !== $gl_id) {
            continue;
        }
        $codes = $codes_detailed[$mode] ?? [];
        foreach ($codes as $gl_code) {
            if (isset($data[$gl_code])) {
                $total += (float)$data[$gl_code];
            }
        }
    }
    return $total;
}

// ============================================================
// GET PAST TRANSACTION DATA (2025 and earlier)
// ============================================================

function get_past_transaction_data_export(
    mysqli $conn,
    string $period,
    array $gl_id_by_key,
    string $gl_code_mode,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    
    $data = [];
    if (empty($period)) {
        return $data;
    }
    
    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';
    
    $gl_ids_to_query = [];
    
    if ($gl_code_mode === 'mixed') {
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '') {
                    $gl_ids_to_query[] = $oid;
                }
            }
        }
    } else {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $gid) {
            if ($gid !== '') {
                $gl_ids_to_query[] = $gid;
            }
        }
        if ($gl_code_mode === 'new') {
            foreach ($new_gl_mapping as $key => $mapping) {
                if (is_array($mapping)) {
                    foreach ($mapping as $src_id) {
                        if ($src_id !== '') {
                            $gl_ids_to_query[] = $src_id;
                        }
                    }
                } elseif ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }
    }
    
    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, function($id) {
        return $id !== null && $id !== '';
    })));
    
    if (empty($gl_ids_to_query)) {
        return $data;
    }
    
    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));
    $sql = "
        SELECT
            gl_id,
            SUM(amount) AS total_amount
        FROM fs_reports.past_transaction
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
        GROUP BY gl_id
    ";
    
    $params = array_merge([$year_val, $month_val], $gl_ids_to_query);
    $types = 'ss' . str_repeat('s', count($gl_ids_to_query));
    
    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] = (float)$row['total_amount'];
        }
        mysqli_stmt_close($stmt);
    }
    
    if ($gl_code_mode === 'old') {
        foreach ($raw_data as $src_gl_id => $amount) {
            if (array_key_exists($src_gl_id, $old_gl_mapping)) {
                $mapped_gl_id = $old_gl_mapping[$src_gl_id];
                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target_gl_id = $key;
                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                        $total += (float)$raw_data[$src_gl_id];
                    }
                }
                if ($total != 0.0) {
                    $data[$target_gl_id] = $total;
                }
            } else {
                $src_gl_id = $key;
                $target_gl_id = $mapping;
                if ($src_gl_id !== '' && $target_gl_id !== null && $target_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                    if (!isset($data[$target_gl_id])) {
                        $data[$target_gl_id] = 0.0;
                    }
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }
        $mapped_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '') {
                        $mapped_source_ids[$src_gl_id] = true;
                    }
                }
            } elseif ($key !== '') {
                $mapped_source_ids[$key] = true;
            }
        }
        foreach ($raw_data as $src_gl_id => $amount) {
            if (!isset($mapped_source_ids[$src_gl_id]) && isset($gl_id_by_key) && in_array($src_gl_id, $gl_id_by_key, true)) {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } else {
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            $total = 0.0;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '' && isset($raw_data[$oid])) {
                    $total += (float)$raw_data[$oid];
                }
            }
            $data[$new_gid] = $total;
        }
    }
    
    return $data;
}

// ============================================================
// GET MANUAL ADJUSTMENT DATA (2026+)
// ============================================================

function get_manual_adjustment_data_export(
    mysqli $conn,
    string $period,
    array $gl_id_by_key,
    string $gl_code_mode,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    
    $data = [];
    if (empty($period)) {
        return $data;
    }
    
    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';
    
    $gl_ids_to_query = [];
    
    if ($gl_code_mode === 'old') {
        $gl_ids_to_query = array_unique(array_filter(array_values($gl_id_by_key)));
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        $gl_ids_to_query = array_unique(array_filter(array_values($gl_id_by_key)));
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            } else {
                if ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }
    } else {
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            if ($new_gid !== '') {
                $gl_ids_to_query[] = $new_gid;
            }
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '') {
                    $gl_ids_to_query[] = $oid;
                }
            }
        }
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') {
                $gl_ids_to_query[] = $key;
            } elseif (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            }
        }
    }
    
    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, function($id) {
        return $id !== null && $id !== '';
    })));
    
    if (empty($gl_ids_to_query)) {
        return $data;
    }
    
    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));
    $sql = "
        SELECT
            gl_id,
            SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total_amount
        FROM fs_reports.manual_adjustment
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
        GROUP BY gl_id
    ";
    
    $params = array_merge([$year_val, $month_val], $gl_ids_to_query);
    $types = 'ss' . str_repeat('s', count($gl_ids_to_query));
    
    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] = (float)$row['total_amount'];
        }
        mysqli_stmt_close($stmt);
    }
    
    if ($gl_code_mode === 'old') {
        foreach ($raw_data as $src_gl_id => $amount) {
            if (array_key_exists($src_gl_id, $old_gl_mapping)) {
                $mapped_gl_id = $old_gl_mapping[$src_gl_id];
                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target_gl_id = $key;
                $direct = array_key_exists($target_gl_id, $raw_data) ? (float)$raw_data[$target_gl_id] : null;
                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                        $total += (float)$raw_data[$src_gl_id];
                    }
                }
                if ($direct !== null && $direct != 0.0) {
                    $data[$target_gl_id] = $direct;
                } elseif ($total != 0.0) {
                    $data[$target_gl_id] = $total;
                } elseif ($direct !== null) {
                    $data[$target_gl_id] = $direct;
                }
            } else {
                $src_gl_id = $key;
                $target_gl_id = $mapping;
                if ($src_gl_id !== '' && $target_gl_id !== null && $target_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                    if (!isset($data[$target_gl_id])) {
                        $data[$target_gl_id] = 0.0;
                    }
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') {
                $scalar_source_ids[$key] = true;
            }
        }
        foreach ($gl_id_by_key as $key => $gl_id) {
            if ($gl_id !== '' && !isset($data[$gl_id]) && isset($raw_data[$gl_id]) && !isset($scalar_source_ids[$gl_id])) {
                $data[$gl_id] = (float)$raw_data[$gl_id];
            }
        }
    } else {
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '' && $mapping !== null && $mapping !== '') {
                $scalar_source_ids[$key] = true;
                if (isset($raw_data[$key])) {
                    if (!isset($data[$mapping])) {
                        $data[$mapping] = 0.0;
                    }
                    $data[$mapping] += (float)$raw_data[$key];
                }
            }
        }
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            if (isset($data[$new_gid])) {
                continue;
            }
            if (isset($scalar_source_ids[$new_gid])) {
                $data[$new_gid] = 0.0;
                continue;
            }
            $direct = array_key_exists($new_gid, $raw_data) ? (float)$raw_data[$new_gid] : null;
            $total = 0.0;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '' && isset($raw_data[$oid])) {
                    $total += (float)$raw_data[$oid];
                }
            }
            if ($direct !== null && $direct != 0.0) {
                $data[$new_gid] = $direct;
            } elseif ($total != 0.0) {
                $data[$new_gid] = $total;
            } else {
                $data[$new_gid] = $direct !== null ? $direct : 0.0;
            }
        }
    }
    
    return $data;
}

// ============================================================
// FETCH PERIOD DATA (auto-selects source based on year)
// ============================================================

function fetch_period_data_export(
    mysqli $conn,
    string $period,
    array $gl_id_by_key,
    string $gl_code_mode,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    if (!$use_real_data || empty($period)) {
        return [];
    }
    
    $year = (int)explode('-', $period)[0];
    
    if ($year >= 2026) {
        return get_manual_adjustment_data_export($conn, $period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping);
    }
    return get_past_transaction_data_export($conn, $period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping);
}

// ============================================================
// COMPUTE TABLE ROWS (with support for year-over-year)
// ============================================================

function compute_table_rows_for_export(
    mysqli $conn,
    string $primary_period,
    string $compare_period,
    string $gl_code_mode,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    array $gl_id_by_key,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    
    $primary_data = fetch_period_data_export($conn, $primary_period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $use_real_data);
    $compare_data = fetch_period_data_export($conn, $compare_period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $use_real_data);
    
    $table_rows = [];
    
    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);
        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys, true);
        $current_gl_id = $gl_id_by_key[$key] ?? '';
        
        $p_mode = $gl_code_mode;
        $c_mode = $gl_code_mode;
        
        if ($gl_code_mode === 'mixed') {
            $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
            $c_mode = isApril2026OrLater($compare_period) ? 'new' : 'old';
        }
        
        $primary_total = isset($primary_data[$current_gl_id]) ? (float)$primary_data[$current_gl_id] : 0.0;
        $compare_total = isset($compare_data[$current_gl_id]) ? (float)$compare_data[$current_gl_id] : 0.0;
        
        // SPECIAL INJ-3
        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;
        if ((int)$sort_order === $inj3_sort_order && $current_gl_id === 'INJ-3') {
            $primary_total = 0.0;
            $compare_total = 0.0;
            for ($inj_number = 1; $inj_number <= 49; $inj_number++) {
                if ($inj_number === 3) continue;
                $inj_id = 'INJ-' . $inj_number;
                $inj_primary = get_gl_id_total($inj_id, $gl_mapping, $gl_id_by_key, $primary_data, $p_mode);
                $inj_compare = get_gl_id_total($inj_id, $gl_mapping, $gl_id_by_key, $compare_data, $c_mode);
                
                $compare_is_historical = !empty($compare_period) && is2025OrEarlier($compare_period);
                
                if ($inj_number === 2) {
                    if ($compare_is_historical) {
                        $primary_total -= $inj_primary;
                        $compare_total -= $inj_compare;
                    } else {
                        $primary_total -= -$inj_primary;
                        $compare_total -= -$inj_compare;
                    }
                } else {
                    $primary_total += $inj_primary;
                    $compare_total += $inj_compare;
                }
            }
        }
        
        if ($is_inj2) {
            $primary_total = -$primary_total;
            $compare_total = -$compare_total;
        }
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_id' => $current_gl_id,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'primary_total' => $primary_total,
            'compare_total' => $compare_total,
            'is_inj2' => $is_inj2
        ];
    }
    
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }
    
    $final_table_rows = [];
    $rev_tot_p = 0;
    $rev_tot_c = 0;
    $sa_tot_p = 0;
    $sa_tot_c = 0;
    $gp_tot_p = 0;
    $gp_tot_c = 0;
    $ebitda_tot_p = 0;
    $ebitda_tot_c = 0;
    $ebit_tot_p = 0;
    $ebit_tot_c = 0;
    $ebt_tot_p = 0;
    $ebt_tot_c = 0;
    $net_tot_p = 0;
    $net_tot_c = 0;
    
    $ranges = getSortOrderRanges($gl_code_mode);
    
    foreach ($grouped_rows as $sort_order => $rows) {
        $revenue_end_for_hide = $ranges['revenue_end'];
        $cost_of_sales_order = $ranges['cost_of_sales'];
        $sort_num = (int)$sort_order;
        $is_revenue_sort = ($sort_num >= 1 && $sort_num <= $revenue_end_for_hide);
        $is_cost_of_sales_sort = ($sort_num === $cost_of_sales_order);
        
        $sa_start = $ranges['sa_start'];
        $sa_end = $ranges['sa_end'];
        $extra_hide_details = ($gl_code_mode === 'old') ? [10, 13] : [11, 14];
        $is_sa_sort = ($sort_num >= $sa_start && $sort_num <= $sa_end);
        $hide_detail_rows = $is_revenue_sort || $is_cost_of_sales_sort || $is_sa_sort;
        
        if (!$hide_detail_rows && !in_array($sort_num, $extra_hide_details, true)) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }
        
        $total_primary_total = 0.0;
        $total_compare_total = 0.0;
        
        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;
        if ((int)$sort_order === $inj3_sort_order) {
            foreach ($rows as $row) {
                if (($row['gl_id'] ?? '') === 'INJ-3') {
                    $total_primary_total = (float)($row['primary_total'] ?? 0);
                    $total_compare_total = (float)($row['compare_total'] ?? 0);
                    break;
                }
            }
        } else {
            $total_primary_total = array_sum(array_column($rows, 'primary_total'));
            $total_compare_total = array_sum(array_column($rows, 'compare_total'));
        }
        
        if ((int)$sort_order >= $ranges['revenue_start'] && (int)$sort_order <= $ranges['revenue_end']) {
            $rev_tot_p += $total_primary_total;
            $rev_tot_c += $total_compare_total;
        }
        
        if ((int)$sort_order === $sa_start || (int)$sort_order === $sa_end) {
            $sa_tot_p += $total_primary_total;
            $sa_tot_c += $total_compare_total;
        }
        
        $inc_dec = $total_primary_total - $total_compare_total;
        $percentage = 0;
        if ($total_compare_total != 0) {
            $percentage = ($inc_dec / $total_compare_total) * 100;
        } elseif ($total_primary_total != 0) {
            $percentage = 100;
        }
        
        $description = isset($sort_order_descriptions[$sort_order]) ? $sort_order_descriptions[$sort_order] : ('Total for Sort Order ' . $sort_order);
        $hide_summary = ($gl_code_mode === 'old') ? [26, 27, 28] : [27, 28, 29];
        
        if (!$is_revenue_sort && !$is_sa_sort && !in_array($sort_num, $hide_summary, true)) {
            $summary_sort_label = $sort_order;
            $summary_description = $description;
            if ($is_cost_of_sales_sort) {
                $summary_sort_label = 'COST OF SALES';
                $summary_description = '';
            }
            $final_table_rows[] = [
                'sort_order' => $summary_sort_label,
                'sub_order' => '',
                'gl_description' => $summary_description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => false,
                'primary_total' => $total_primary_total,
                'compare_total' => $total_compare_total,
                'inc_dec' => $inc_dec,
                'percentage' => $percentage
            ];
        }
        
        if ((int)$sort_order === $ranges['revenue_end']) {
            $inc_dec_rev = $rev_tot_p - $rev_tot_c;
            $pct_rev = $rev_tot_c != 0 ? ($inc_dec_rev / abs($rev_tot_c)) * 100 : ($rev_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = [
                'sort_order' => 'REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $rev_tot_p,
                'compare_total' => $rev_tot_c,
                'inc_dec' => $inc_dec_rev,
                'percentage' => $pct_rev
            ];
        }
        
        if ((int)$sort_order === $cost_of_sales_order) {
            $gp_tot_p = $rev_tot_p - $total_primary_total;
            $gp_tot_c = $rev_tot_c - $total_compare_total;
            $inc_dec_gp = $gp_tot_p - $gp_tot_c;
            $pct_gp = $gp_tot_c != 0 ? ($inc_dec_gp / abs($gp_tot_c)) * 100 : ($gp_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $gp_tot_p,
                'compare_total' => $gp_tot_c,
                'inc_dec' => $inc_dec_gp,
                'percentage' => $pct_gp
            ];
        }
        
        if ((int)$sort_order === $sa_end) {
            $inc_dec_sa = $sa_tot_p - $sa_tot_c;
            $pct_sa = $sa_tot_c != 0 ? ($inc_dec_sa / abs($sa_tot_c)) * 100 : ($sa_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = [
                'sort_order' => 'SELLING & ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => false,
                'primary_total' => $sa_tot_p,
                'compare_total' => $sa_tot_c,
                'inc_dec' => $inc_dec_sa,
                'percentage' => $pct_sa
            ];
            
            $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
            $ebitda_tot_c = $gp_tot_c - $sa_tot_c;
            $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_c;
            $pct_ebitda = $ebitda_tot_c != 0 ? ($inc_dec_ebitda / abs($ebitda_tot_c)) * 100 : ($ebitda_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebitda_tot_p,
                'compare_total' => $ebitda_tot_c,
                'inc_dec' => $inc_dec_ebitda,
                'percentage' => $pct_ebitda
            ];
        }
        
        $depreciation = $ranges['depreciation'];
        if ((int)$sort_order === $depreciation) {
            $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
            $ebit_tot_c = $ebitda_tot_c - $total_compare_total;
            $inc_dec_ebit = $ebit_tot_p - $ebit_tot_c;
            $pct_ebit = $ebit_tot_c != 0 ? ($inc_dec_ebit / abs($ebit_tot_c)) * 100 : ($ebit_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebit_tot_p,
                'compare_total' => $ebit_tot_c,
                'inc_dec' => $inc_dec_ebit,
                'percentage' => $pct_ebit
            ];
        }
        
        $interest = $ranges['interest'];
        if ((int)$sort_order === $interest) {
            $ebt_tot_p = $ebit_tot_p - $total_primary_total;
            $ebt_tot_c = $ebit_tot_c - $total_compare_total;
            $inc_dec_ebt = $ebt_tot_p - $ebt_tot_c;
            $pct_ebt = $ebt_tot_c != 0 ? ($inc_dec_ebt / abs($ebt_tot_c)) * 100 : ($ebt_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebt_tot_p,
                'compare_total' => $ebt_tot_c,
                'inc_dec' => $inc_dec_ebt,
                'percentage' => $pct_ebt
            ];
        }
        
        $tax = $ranges['tax'];
        if ((int)$sort_order === $tax) {
            $net_tot_p = $ebt_tot_p - $total_primary_total;
            $net_tot_c = $ebt_tot_c - $total_compare_total;
            $inc_dec_net = $net_tot_p - $net_tot_c;
            $pct_net = $net_tot_c != 0 ? ($inc_dec_net / abs($net_tot_c)) * 100 : ($net_tot_p != 0 ? 100 : 0);
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = [
                'sort_order' => 'NET INCOME',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $net_tot_p,
                'compare_total' => $net_tot_c,
                'inc_dec' => $inc_dec_net,
                'percentage' => $pct_net
            ];
        }
    }
    
    return $final_table_rows;
}

// ============================================================
// BUILD THE DATA
// ============================================================

// Upper Table: Primary vs Previous
$table_rows_upper = compute_table_rows_for_export(
    $conn,
    $primary_period,
    $previous_period,
    $gl_code_mode,
    $gl_mapping,
    $gl_descriptions,
    $special_keys,
    $sort_order_descriptions,
    $gl_id_by_key,
    $mixed_id_map,
    $old_gl_mapping,
    $new_gl_mapping,
    $valid_filters
);

// Lower Table: Primary vs Same Month Previous Year
$previous_year_period = '';
if (!empty($primary_period)) {
    $primary_date = new DateTime($primary_period . '-01');
    $primary_date->modify('-1 year');
    $previous_year_period = $primary_date->format('Y-m');
}

$table_rows_lower = compute_table_rows_for_export(
    $conn,
    $primary_period,
    $previous_year_period,
    $gl_code_mode,
    $gl_mapping,
    $gl_descriptions,
    $special_keys,
    $sort_order_descriptions,
    $gl_id_by_key,
    $mixed_id_map,
    $old_gl_mapping,
    $new_gl_mapping,
    $valid_filters
);

// Get total revenues for upper table
$primary_total_revenues = 0;
$previous_total_revenues = 0;
foreach ($table_rows_upper as $row) {
    if (isset($row['sort_order']) && $row['sort_order'] === 'REVENUES') {
        $primary_total_revenues = $row['primary_total'] ?? 0;
        $previous_total_revenues = $row['compare_total'] ?? 0;
        break;
    }
}

// Get total revenues for lower table
$lower_primary_revenues = 0;
$lower_compare_revenues = 0;
foreach ($table_rows_lower as $row) {
    if (isset($row['sort_order']) && $row['sort_order'] === 'REVENUES') {
        $lower_primary_revenues = $row['primary_total'] ?? 0;
        $lower_compare_revenues = $row['compare_total'] ?? 0;
        break;
    }
}

// ============================================================
// FORMAT PERIOD LABELS
// ============================================================

$primary_formatted = !empty($primary_period) 
    ? strtoupper(date('M-y', strtotime($primary_period . '-01'))) 
    : '(Primary)';

$previous_formatted = !empty($previous_period) 
    ? strtoupper(date('M-y', strtotime($previous_period . '-01'))) 
    : '(Previous)';

$previous_year_formatted = !empty($previous_year_period) 
    ? strtoupper(date('M-y', strtotime($previous_year_period . '-01'))) 
    : '(Previous Year)';

$period_comparison_upper = '';
if (!empty($primary_period) && !empty($previous_period)) {
    $period_comparison_upper = strtoupper(date('F Y', strtotime($primary_period . '-01'))) . 
                               ' vs ' . 
                               strtoupper(date('F Y', strtotime($previous_period . '-01')));
}

$period_comparison_lower = '';
if (!empty($primary_period) && !empty($previous_year_period)) {
    $period_comparison_lower = strtoupper(date('F Y', strtotime($primary_period . '-01'))) . 
                               ' vs ' . 
                               strtoupper(date('F Y', strtotime($previous_year_period . '-01')));
}

// ============================================================
// CREATE EXCEL FILE
// ============================================================

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Comparative P&L');

// Set column widths
$sheet->getColumnDimension('A')->setWidth(50);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(10);
$sheet->getColumnDimension('D')->setWidth(3);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(10);
$sheet->getColumnDimension('G')->setWidth(3);
$sheet->getColumnDimension('H')->setWidth(18);
$sheet->getColumnDimension('I')->setWidth(10);

// ============================================================
// UPPER TABLE: LOGO AND HEADER
// ============================================================

$logo_path = __DIR__ . '/../images/mlhuillier.jpg';

if (file_exists($logo_path)) {
    try {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('M&L Huillier Logo');
        $drawing->setPath($logo_path);
        $drawing->setHeight(40);
        $drawing->setCoordinates('B1');
        $drawing->setOffsetX(10);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    } catch (Exception $e) {
        $sheet->setCellValue('B1', 'M&L HUILLIER');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(20);
        $sheet->getStyle('B1')->getFont()->getColor()->setRGB('800000');
    }
} else {
    $sheet->setCellValue('B1', 'M&L HUILLIER');
    $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(20);
    $sheet->getStyle('B1')->getFont()->getColor()->setRGB('800000');
}

$sheet->getRowDimension(2)->setRowHeight(20);

// Title - Upper Table
$sheet->setCellValue('A3', 'COMPARATIVE PROFIT & LOSS STATEMENT');
$sheet->mergeCells('A3:I3');
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A4', 'MLFSI & JEWELERS');
$sheet->mergeCells('A4:I4');
$sheet->getStyle('A4')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A5', $period_comparison_upper);
$sheet->mergeCells('A5:I5');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->getRowDimension(6)->setRowHeight(10);

// ============================================================
// UPPER TABLE: ROW 7 - NATIONWIDE with BORDER TOP
// ============================================================

$sheet->setCellValue('A7', '');
$sheet->setCellValue('B7', 'NATIONWIDE');
$sheet->mergeCells('B7:I7');
$sheet->getStyle('B7')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('B7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('B7:I7')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('F79646');

$sheet->getStyle('B7:I7')->applyFromArray([
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
]);

// ============================================================
// UPPER TABLE: ROW 8 - Column Headers with BORDER TOP
// ============================================================

$sheet->setCellValue('A8', '');
$sheet->setCellValue('B8', $primary_formatted);
$sheet->setCellValue('C8', '%');
$sheet->setCellValue('D8', '');
$sheet->setCellValue('E8', $previous_formatted);
$sheet->setCellValue('F8', '%');
$sheet->setCellValue('G8', '');
$sheet->setCellValue('H8', 'INC./DEC.');
$sheet->setCellValue('I8', '%');

$headerRowStyle = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FABF8F']]
];
$sheet->getStyle('B8:I8')->applyFromArray($headerRowStyle);

$sheet->getStyle('B8:I8')->applyFromArray([
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
]);

// ============================================================
// UPPER TABLE: DATA ROWS
// ============================================================

$rowNum = 9;
$borderRows = ['REVENUES', 'GROSS PROFIT', "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES', 'NET INCOME'];

foreach ($table_rows_upper as $row) {
    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
        $sheet->getRowDimension($rowNum)->setRowHeight(15);
        $rowNum++;
        continue;
    }
    
    $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
    $is_header = !empty($row['is_section_header']);
    
    $primary_total = $row['primary_total'] ?? 0;
    $compare_total = $row['compare_total'] ?? 0;
    
    $primary_percentage_of_revenue = 0;
    if ($primary_total_revenues != 0) {
        $primary_percentage_of_revenue = ($primary_total / $primary_total_revenues) * 100;
    }
    
    $compare_percentage_of_revenue = 0;
    if ($previous_total_revenues != 0) {
        $compare_percentage_of_revenue = ($compare_total / $previous_total_revenues) * 100;
    }
    
    $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $compare_total);
    
    if (isset($row['percentage'])) {
        $percentage = $row['percentage'];
    } else {
        if ($compare_total != 0) {
            $percentage = ($inc_dec / $compare_total) * 100;
        } elseif ($primary_total != 0) {
            $percentage = 100;
        } else {
            $percentage = 0;
        }
    }
    
    $label = '';
    if ($is_summary_row) {
        $label = $row['sort_order'] ?? '';
    } elseif ($is_header) {
        $label = $row['sub_order'] ?? '';
    } else {
        $label = $row['gl_description'] ?? '';
    }
    
    $sheet->setCellValue('A' . $rowNum, $label);
    $sheet->setCellValue('B' . $rowNum, $primary_total);
    $sheet->setCellValue('C' . $rowNum, $primary_percentage_of_revenue);
    $sheet->setCellValue('E' . $rowNum, $compare_total);
    $sheet->setCellValue('F' . $rowNum, $compare_percentage_of_revenue);
    $sheet->setCellValue('H' . $rowNum, $inc_dec);
    $sheet->setCellValue('I' . $rowNum, $percentage);
    
    // Apply number formatting
    $sheet->getStyle('B' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('E' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('C' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('F' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('I' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    
    // Apply borders based on label
    if (in_array($label, $borderRows) && $is_summary_row) {
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }
    
    if ($label === 'NET INCOME' && $is_summary_row) {
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_DOUBLE,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }
    
    // Apply bold to summary rows
    if ($is_summary_row) {
        $sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getFont()->setBold(true);
        
        if ($label === 'NET INCOME') {
            $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FCD5B4');
        }
        
        if (empty($row['skip_spacer'])) {
            $sheet->getRowDimension($rowNum + 1)->setRowHeight(10);
            $rowNum += 2;
        } else {
            $rowNum++;
        }
    } else {
        $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->getFont()->setSize(10);
        $rowNum++;
    }
}

// Store the end row of upper table for reference
$upper_table_end_row = $rowNum - 1;

// ============================================================
// LOWER TABLE: FULL HEADER (starts at row 29)
// ============================================================

// Start lower table at row 29
$rowNum = 29;

// Title - Lower Table
$sheet->setCellValue('A' . $rowNum, 'COMPARATIVE PROFIT & LOSS STATEMENT');
$sheet->mergeCells('A' . $rowNum . ':I' . $rowNum);
$sheet->getStyle('A' . $rowNum)->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$rowNum++;

$sheet->setCellValue('A' . $rowNum, 'MLFSI & JEWELERS');
$sheet->mergeCells('A' . $rowNum . ':I' . $rowNum);
$sheet->getStyle('A' . $rowNum)->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$rowNum++;

$sheet->setCellValue('A' . $rowNum, $period_comparison_lower);
$sheet->mergeCells('A' . $rowNum . ':I' . $rowNum);
$sheet->getStyle('A' . $rowNum)->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$rowNum++;

$sheet->getRowDimension($rowNum)->setRowHeight(10);
$rowNum++;

// ============================================================
// LOWER TABLE: NATIONWIDE with BORDER TOP
// ============================================================

$sheet->setCellValue('A' . $rowNum, '');
$sheet->setCellValue('B' . $rowNum, 'NATIONWIDE');
$sheet->mergeCells('B' . $rowNum . ':I' . $rowNum);
$sheet->getStyle('B' . $rowNum)->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('F79646');

$sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
]);
$rowNum++;

// ============================================================
// LOWER TABLE: Column Headers with BORDER TOP
// ============================================================

$sheet->setCellValue('A' . $rowNum, '');
$sheet->setCellValue('B' . $rowNum, $primary_formatted);
$sheet->setCellValue('C' . $rowNum, '%');
$sheet->setCellValue('D' . $rowNum, '');
$sheet->setCellValue('E' . $rowNum, $previous_year_formatted);
$sheet->setCellValue('F' . $rowNum, '%');
$sheet->setCellValue('G' . $rowNum, '');
$sheet->setCellValue('H' . $rowNum, 'INC./DEC.');
$sheet->setCellValue('I' . $rowNum, '%');

$sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray($headerRowStyle);

$sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
]);
$rowNum++;

// ============================================================
// LOWER TABLE: DATA ROWS
// ============================================================

foreach ($table_rows_lower as $row) {
    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
        $sheet->getRowDimension($rowNum)->setRowHeight(15);
        $rowNum++;
        continue;
    }
    
    $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
    $is_header = !empty($row['is_section_header']);
    
    $primary_total = $row['primary_total'] ?? 0;
    $compare_total = $row['compare_total'] ?? 0;
    
    $primary_percentage_of_revenue = 0;
    if ($lower_primary_revenues != 0) {
        $primary_percentage_of_revenue = ($primary_total / $lower_primary_revenues) * 100;
    }
    
    $compare_percentage_of_revenue = 0;
    if ($lower_compare_revenues != 0) {
        $compare_percentage_of_revenue = ($compare_total / $lower_compare_revenues) * 100;
    }
    
    $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $compare_total);
    
    if (isset($row['percentage'])) {
        $percentage = $row['percentage'];
    } else {
        if ($compare_total != 0) {
            $percentage = ($inc_dec / $compare_total) * 100;
        } elseif ($primary_total != 0) {
            $percentage = 100;
        } else {
            $percentage = 0;
        }
    }
    
    $label = '';
    if ($is_summary_row) {
        $label = $row['sort_order'] ?? '';
    } elseif ($is_header) {
        $label = $row['sub_order'] ?? '';
    } else {
        $label = $row['gl_description'] ?? '';
    }
    
    $sheet->setCellValue('A' . $rowNum, $label);
    $sheet->setCellValue('B' . $rowNum, $primary_total);
    $sheet->setCellValue('C' . $rowNum, $primary_percentage_of_revenue);
    $sheet->setCellValue('E' . $rowNum, $compare_total);
    $sheet->setCellValue('F' . $rowNum, $compare_percentage_of_revenue);
    $sheet->setCellValue('H' . $rowNum, $inc_dec);
    $sheet->setCellValue('I' . $rowNum, $percentage);
    
    // Apply number formatting
    $sheet->getStyle('B' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('E' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('C' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('F' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('I' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    
    // Apply borders based on label
    if (in_array($label, $borderRows) && $is_summary_row) {
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }
    
    if ($label === 'NET INCOME' && $is_summary_row) {
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_DOUBLE,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);
    }
    
    // Apply bold to summary rows
    if ($is_summary_row) {
        $sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getFont()->setBold(true);
        
        if ($label === 'NET INCOME') {
            $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FCD5B4');
        }
        
        if (empty($row['skip_spacer'])) {
            $sheet->getRowDimension($rowNum + 1)->setRowHeight(10);
            $rowNum += 2;
        } else {
            $rowNum++;
        }
    } else {
        $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->getFont()->setSize(10);
        $rowNum++;
    }
}

// ============================================================
// APPLY CONDITIONAL FORMATTING FOR NEGATIVE VALUES (RED FONT)
// ============================================================

$lastRow = $rowNum - 1;
$columns = ['B', 'C', 'E', 'F', 'H', 'I'];

// Apply to both upper and lower tables
foreach ($columns as $col) {
    // Upper table: rows 9 to upper_table_end_row
    if ($upper_table_end_row >= 9) {
        $range = $col . '9:' . $col . $upper_table_end_row;
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_CELLIS);
        $conditional->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $conditional->addCondition(0);
        $conditional->getStyle()->getFont()->getColor()->setRGB('FF0000');
        $sheet->getStyle($range)->setConditionalStyles([$conditional]);
    }
    
    // Lower table: rows 33 to lastRow
    if ($lastRow >= 33) {
        $range = $col . '33:' . $col . $lastRow;
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_CELLIS);
        $conditional->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $conditional->addCondition(0);
        $conditional->getStyle()->getFont()->getColor()->setRGB('FF0000');
        $sheet->getStyle($range)->setConditionalStyles([$conditional]);
    }
}

// ============================================================
// OUTPUT
// ============================================================

$filename = 'Comparative_P&L_Statement_' . date('Y-m-d') . '.xlsx';

if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;
?>