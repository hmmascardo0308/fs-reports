<?php
// comparative_report_original_page_three.php
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

$username  = $_SESSION['username'] ?? 'unknown';
$full_name = $_SESSION['full_name'] ?? 'unknown';
$user_type = $_SESSION['user_type'] ?? 'unknown';

// FILTERS
$primary_period  = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

// AUTO-SET PREVIOUS PERIOD TO LAST MONTH IF PRIMARY IS SET BUT PREVIOUS IS EMPTY
if (!empty($primary_period) && empty($previous_period) && !isset($_GET['previous_period'])) {
    $primary_date = new DateTime($primary_period . '-01');
    $primary_date->modify('-1 month');
    $previous_period = $primary_date->format('Y-m');
}

$gl_code_mode    = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode    = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

// Determine INJ sort order based on GL mode
$inj_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

// ERROR MESSAGE
$error_message = '';

// HELPER: COMPARE MONTHS
function compareMonths(string $month1, string $month2): int {
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

// HELPER: MARCH 2026 OR EARLIER
function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2026-03-01');
}

// HELPER: APRIL 2026 OR LATER
function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') >= strtotime('2026-04-01');
}

// HELPER: CHECK IF MONTH IS 2025 OR EARLIER
function is2025OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2025-12-01');
}

// HELPER: SORT ORDER RANGES
function getSortOrderRanges(string $gl_code_mode): array {
    if ($gl_code_mode === 'old') {
        return [
            'revenue_start' => 1, 'revenue_end' => 22, 'cost_of_sales' => 23,
            'sa_start' => 24, 'sa_end' => 25, 'depreciation' => 26, 'interest' => 27, 'tax' => 28
        ];
    }
    return [
        'revenue_start' => 1, 'revenue_end' => 23, 'cost_of_sales' => 24,
        'sa_start' => 25, 'sa_end' => 26, 'depreciation' => 27, 'interest' => 28, 'tax' => 29
    ];
}

// HARDCODED GL MAPPING FOR OLD GL CODES
$old_gl_mapping = [
    'COS-2' => 'COS-5', 'COS-3' => 'COS-6', 'COS-4' => 'COS-7',
    'MLE-2' => 'MLE-3',
    'TAE-15' => 'TAE-16', 'TAE-16' => 'TAE-17', 'TAE-17' => 'TAE-18', 'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20', 'TAE-20' => 'TAE-21', 'TAE-21' => 'TAE-22', 'TAE-22' => 'TAE-23', 'TAE-23' => null,
    'TOI-18' => null, 'TOI-19' => 'TOI-18', 'TOI-20' => 'MLE-2', 'TOI-22' => 'INJ-5', 'TOI-23' => 'INJ-4', 'TOI-24' => null,
    'VEH-5' => 'VEH-6', 'VEH-6' => 'VEH-7', 'VEH-7' => 'VEH-8', 'VEH-8' => 'VEH-9', 'VEH-9' => 'VEH-10',
];

// HARDCODED GL MAPPING FOR NEW GL CODES
$new_gl_mapping = [
    'COS-2' => 'COS-5', 'COS-3' => 'COS-6', 'COS-4' => 'COS-7',
    'TAE-15' => 'TAE-16', 'TAE-16' => 'TAE-17', 'TAE-17' => 'TAE-18', 'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20', 'TAE-20' => 'TAE-21', 'TAE-21' => 'TAE-22', 'TAE-22' => 'TAE-23', 'TAE-23' => null,
    'TOI-18' => null, 'TOI-19' => 'TOI-18', 'TOI-20' => 'COS-8', 'TOI-22' => null, 'TOI-23' => null, 'TOI-24' => null,
    'VEH-5' => 'VEH-7', 'VEH-6' => 'VEH-8', 'VEH-7' => 'VEH-9', 'VEH-8' => 'VEH-10', 'VEH-9' => 'VEH-11',
    'INS-1' => ['INS-28','INS-29','INS-30','INS-31','INS-34','INS-39'],
    'INS-2' => ['INS-25','INS-26','INS-44','INS-47'],
    'INS-3' => ['INS-32','INS-33','INS-42','INS-43','INS-45'],
    'INS-4' => ['INS-27','INS-46'],
    'INS-5' => ['INS-20','INS-21','INS-22','INS-23','INS-24','INS-37','INS-41'],
    'INS-6' => ['INS-1','INS-2','INS-3','INS-4','INS-5','INS-6','INS-7','INS-8','INS-9','INS-10','INS-11','INS-12','INS-13','INS-14','INS-35','INS-36','INS-40'],
    'INS-7' => ['INS-15','INS-16','INS-17','INS-18','INS-19'],
    'INS-8' => ['INS-38'], 'INS-9' => ['INS-48'], 'INS-10' => ['INS-49'],
    'INS-11' => [], 'INS-12' => []
];

// VALIDATE PERIODS
$show_error = false;
$valid_filters = false;

if (!empty($previous_period) && empty($primary_period)) {
    $error_message = 'Primary period is required when selecting a Previous period.';
    $show_error = true;
}

if (!$show_error && !empty($primary_period) && !empty($previous_period)) {
    if (compareMonths($primary_period, $previous_period) <= 0) {
        $error_message = 'Primary period must be later than the Previous period.';
        $show_error = true;
    }
    if (!$show_error) {
        if ($gl_code_mode === 'old') {
            if (!isMarch2026OrEarlier($primary_period) || !isMarch2026OrEarlier($previous_period)) {
                $error_message = 'Old GL Code is only available for March 2026 and earlier. Both Primary and Previous periods must be March 2026 or earlier.';
                $show_error = true;
            }
        } elseif ($gl_code_mode === 'new') {
            if (!isApril2026OrLater($primary_period) || !isApril2026OrLater($previous_period)) {
                $error_message = 'New GL Code is only available for April 2026 onwards. Both Primary and Previous periods must be April 2026 or later.';
                $show_error = true;
            }
        }
        // mixed allows both sides of the cutoff
    }
    if (!$show_error) $valid_filters = true;
}

// RESET
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original_page_three.php");
    exit;
}

// GL STRUCTURE
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];

// MIXED MODE
$old_gl_id_to_codes = [];
$mixed_id_map = [];

if ($gl_code_mode === 'mixed') {
    $res = mysqli_query($conn, "SELECT gl_id, gl_code FROM fs_reports.gl_codes_ho WHERE gl_code IS NOT NULL AND gl_code != ''");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $old_gl_id_to_codes[$row['gl_id']][] = trim($row['gl_code']);
        }
    }
    $mixed_id_map = [
        'INS-1' => ['INS-28','INS-29','INS-30','INS-31','INS-34','INS-39'],
        'INS-2' => ['INS-25','INS-26','INS-44','INS-47'],
        'INS-3' => ['INS-32','INS-33','INS-42','INS-43','INS-45'],
        'INS-4' => ['INS-27','INS-46'],
        'INS-5' => ['INS-20','INS-21','INS-22','INS-23','INS-24','INS-37','INS-41'],
        'INS-6' => ['INS-1','INS-2','INS-3','INS-4','INS-5','INS-6','INS-7','INS-8','INS-9','INS-10','INS-11','INS-12','INS-13','INS-14','INS-35','INS-36','INS-40'],
        'INS-7' => ['INS-15','INS-16','INS-17','INS-18','INS-19'],
        'INS-8' => ['INS-38'], 'INS-9' => ['INS-48'], 'INS-10' => ['INS-49'],
        'INS-11' => [], 'INS-12' => [],
        'COS-5' => ['COS-2'], 'COS-6' => ['COS-3'], 'COS-7' => ['COS-4'],
        'COS-2' => [], 'COS-3' => [], 'COS-4' => [], 'COS-8' => [], 'COS-9' => [],
        'VEH-5' => [''], 'VEH-6' => [''],
        'VEH-7' => ['VEH-5'], 'VEH-8' => ['VEH-6'], 'VEH-9' => ['VEH-7'], 'VEH-10' => ['VEH-8'], 'VEH-11' => ['VEH-9'],
        'TOI-33' => ['TOI-31'], 'TOI-34' => ['TOI-32'], 'TAE-23' => ['']
    ];
}

// DETERMINE GL STRUCTURE TABLE
$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes_ho' : 'fs_reports.new_gl_codes_ho';

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
        $gl_id_by_key[$key] = $gl_id;

        if ($gl_id === 'INJ-2') $special_keys[] = $key;

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
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) $gl_mapping[$key]['old'][] = $current_code;
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) $gl_mapping[$key]['new'][] = $current_code;
            } else {
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) $gl_mapping[$key]['new'][] = $current_code;
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) $gl_mapping[$key]['old'][] = $current_code;
            }
        }

        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// HELPER: GET TOTAL FOR GL ID
function get_gl_id_total(string $gl_id, array $gl_mapping, array $gl_id_by_key, array $data, string $mode): float {
    if (isset($data[$gl_id])) return (float)$data[$gl_id];
    $total = 0.0;
    foreach ($gl_mapping as $key => $codes_detailed) {
        if (($gl_id_by_key[$key] ?? '') !== $gl_id) continue;
        $codes = $codes_detailed[$mode] ?? [];
        foreach ($codes as $gl_code) {
            if (isset($data[$gl_code])) $total += (float)$data[$gl_code];
        }
    }
    return $total;
}

// GET MANUAL ADJUSTMENT DATA (2026+)
function get_manual_adjustment_data(
    mysqli $conn, string $period, array $gl_id_by_key, string $gl_code_mode,
    array $mixed_id_map = [], array $old_gl_mapping = [], array $new_gl_mapping = []
): array {
    $data = [];
    if (empty($period)) return $data;

    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';
    $gl_ids_to_query = [];

    if ($gl_code_mode === 'old') {
        $gl_ids_to_query = array_unique(array_filter(array_values($gl_id_by_key)));
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') $gl_ids_to_query[] = $src_id;
        }
    } elseif ($gl_code_mode === 'new') {
        $gl_ids_to_query = array_unique(array_filter(array_values($gl_id_by_key)));
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) if ($src_id !== '') $gl_ids_to_query[] = $src_id;
            } else {
                if ($key !== '') $gl_ids_to_query[] = $key;
            }
        }
    } else { // mixed
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            if ($new_gid !== '') $gl_ids_to_query[] = $new_gid;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) if ($oid !== '') $gl_ids_to_query[] = $oid;
        }
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') {
                $gl_ids_to_query[] = $key;
            } elseif (is_array($mapping)) {
                foreach ($mapping as $src_id) if ($src_id !== '') $gl_ids_to_query[] = $src_id;
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, fn($id) => $id !== null && $id !== '')));
    if (empty($gl_ids_to_query)) return $data;

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));
    $sql = "
        SELECT gl_id, SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total_amount
        FROM fs_reports.manual_adjustment
        WHERE transaction_year = ? AND transaction_month = ? AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL AND gl_id != ''
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

    // MAP OLD
    if ($gl_code_mode === 'old') {
        foreach ($raw_data as $src_gl_id => $amount) {
            if (array_key_exists($src_gl_id, $old_gl_mapping)) {
                $mapped_gl_id = $old_gl_mapping[$src_gl_id];
                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) $data[$mapped_gl_id] = 0.0;
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) $data[$src_gl_id] = 0.0;
                $data[$src_gl_id] += $amount;
            }
        }
    // MAP NEW
    } elseif ($gl_code_mode === 'new') {
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target_gl_id = $key;
                $direct = array_key_exists($target_gl_id, $raw_data) ? (float)$raw_data[$target_gl_id] : null;
                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '' && isset($raw_data[$src_gl_id])) $total += (float)$raw_data[$src_gl_id];
                }
                if ($direct !== null && $direct != 0.0) $data[$target_gl_id] = $direct;
                elseif ($total != 0.0) $data[$target_gl_id] = $total;
                elseif ($direct !== null) $data[$target_gl_id] = $direct;
            } else {
                $src_gl_id = $key;
                $target_gl_id = $mapping;
                if ($src_gl_id !== '' && $target_gl_id !== null && $target_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                    if (!isset($data[$target_gl_id])) $data[$target_gl_id] = 0.0;
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') $scalar_source_ids[$key] = true;
        }
        foreach ($gl_id_by_key as $key => $gl_id) {
            if ($gl_id !== '' && !isset($data[$gl_id]) && isset($raw_data[$gl_id]) && !isset($scalar_source_ids[$gl_id])) {
                $data[$gl_id] = (float)$raw_data[$gl_id];
            }
        }
    // MIXED MAPPING
    } else {
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '' && $mapping !== null && $mapping !== '') {
                $scalar_source_ids[$key] = true;
                if (isset($raw_data[$key])) {
                    if (!isset($data[$mapping])) $data[$mapping] = 0.0;
                    $data[$mapping] += (float)$raw_data[$key];
                }
            }
        }
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            if (isset($data[$new_gid])) continue;
            if (isset($scalar_source_ids[$new_gid])) {
                $data[$new_gid] = 0.0;
                continue;
            }
            $direct = array_key_exists($new_gid, $raw_data) ? (float)$raw_data[$new_gid] : null;
            $total = 0.0;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '' && isset($raw_data[$oid])) $total += (float)$raw_data[$oid];
            }
            if ($direct !== null && $direct != 0.0) $data[$new_gid] = $direct;
            elseif ($total != 0.0) $data[$new_gid] = $total;
            else $data[$new_gid] = $direct !== null ? $direct : 0.0;
        }
    }
    return $data;
}

// GET PAST TRANSACTION DATA (2025 and earlier)
function get_past_transaction_data(
    mysqli $conn, string $period, array $gl_id_by_key, string $gl_code_mode,
    array $mixed_id_map = [], array $old_gl_mapping = [], array $new_gl_mapping = []
): array {
    $data = [];
    if (empty($period)) return $data;

    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';

    $gl_ids_to_query = [];

    if ($gl_code_mode === 'mixed') {
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid) {
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '') $gl_ids_to_query[] = $oid;
            }
        }
    } else {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') $gl_ids_to_query[] = $src_id;
        }
        foreach (array_unique(array_filter(array_values($gl_id_by_key))) as $gid) {
            if ($gid !== '') $gl_ids_to_query[] = $gid;
        }
        if ($gl_code_mode === 'new') {
            foreach ($new_gl_mapping as $key => $mapping) {
                if (is_array($mapping)) {
                    foreach ($mapping as $src_id) {
                        if ($src_id !== '') $gl_ids_to_query[] = $src_id;
                    }
                } elseif ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, fn($id) => $id !== null && $id !== '')));
    if (empty($gl_ids_to_query)) return $data;

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));
    $sql = "
        SELECT gl_id, SUM(amount) AS total_amount
        FROM fs_reports.past_transaction
        WHERE transaction_year = ? AND transaction_month = ? AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL AND gl_id != ''
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
                    if (!isset($data[$mapped_gl_id])) $data[$mapped_gl_id] = 0.0;
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) $data[$src_gl_id] = 0.0;
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
                    if (!isset($data[$target_gl_id])) $data[$target_gl_id] = 0.0;
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }
        $mapped_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '') $mapped_source_ids[$src_gl_id] = true;
                }
            } elseif ($key !== '') {
                $mapped_source_ids[$key] = true;
            }
        }
        foreach ($raw_data as $src_gl_id => $amount) {
            if (!isset($mapped_source_ids[$src_gl_id]) && isset($gl_id_by_key) && in_array($src_gl_id, $gl_id_by_key, true)) {
                if (!isset($data[$src_gl_id])) $data[$src_gl_id] = 0.0;
                $data[$src_gl_id] += $amount;
            }
        }
    } else { // mixed
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

// FETCH PERIOD DATA (auto-selects source based on year)
function fetch_period_data(
    mysqli $conn, string $period, array $gl_id_by_key, string $gl_code_mode,
    array $mixed_id_map = [], array $old_gl_mapping = [], array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    if (!$use_real_data || empty($period)) return [];
    
    $year = (int)explode('-', $period)[0];
    
    if ($year >= 2026) {
        return get_manual_adjustment_data($conn, $period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping);
    }
    return get_past_transaction_data($conn, $period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping);
}

// MAIN TABLE CALCULATION
function compute_table_rows(
    mysqli $conn, string $primary_period, string $compare_period, string $gl_code_mode,
    array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions,
    array $gl_id_by_key, array $mixed_id_map = [], array $old_gl_mapping = [], array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    
    $primary_data = fetch_period_data($conn, $primary_period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $use_real_data);
    $compare_data = fetch_period_data($conn, $compare_period, $gl_id_by_key, $gl_code_mode, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $use_real_data);

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
                
                // Check if compare period is historical (2025 or earlier) for INJ-2 handling
                $compare_is_historical = !empty($compare_period) && is2025OrEarlier($compare_period);
                
                if ($inj_number === 2) {
                    if ($compare_is_historical) {
                        // Historical: INJ-3 = INJ-1 - INJ-2 (no sign flip for INJ-2)
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

        // INJ-2 SIGN FLIP
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

    // GROUP BY SORT ORDER
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $grouped_rows[$row['sort_order']][] = $row;
    }

    $final_table_rows = [];
    $rev_tot_p = $rev_tot_c = $sa_tot_p = $sa_tot_c = 0;
    $gp_tot_p = $gp_tot_c = $ebitda_tot_p = $ebitda_tot_c = 0;
    $ebit_tot_p = $ebit_tot_c = $ebt_tot_p = $ebt_tot_c = $net_tot_p = $net_tot_c = 0;

    $ranges = getSortOrderRanges($gl_code_mode);

    foreach ($grouped_rows as $sort_order => $rows) {
        $revenue_end_for_hide = $ranges['revenue_end'];
        $cost_of_sales_order  = $ranges['cost_of_sales'];
        $sort_num = (int)$sort_order;

        $is_revenue_sort = ($sort_num >= 1 && $sort_num <= $revenue_end_for_hide);
        $is_cost_of_sales_sort = ($sort_num === $cost_of_sales_order);
        $sa_start = $ranges['sa_start'];
        $sa_end   = $ranges['sa_end'];
        $extra_hide_details = ($gl_code_mode === 'old') ? [10, 13] : [11, 14];
        $is_sa_sort = ($sort_num >= $sa_start && $sort_num <= $sa_end);
        $hide_detail_rows = $is_revenue_sort || $is_cost_of_sales_sort || $is_sa_sort;

        if (!$hide_detail_rows && !in_array($sort_num, $extra_hide_details, true)) {
            foreach ($rows as $row) $final_table_rows[] = $row;
        }

        // INJ-3 TOTAL
        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;
        if ((int)$sort_order === $inj3_sort_order) {
            $total_primary_total = $total_compare_total = 0.0;
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

        // REVENUES
        if ((int)$sort_order >= $ranges['revenue_start'] && (int)$sort_order <= $ranges['revenue_end']) {
            $rev_tot_p += $total_primary_total;
            $rev_tot_c += $total_compare_total;
        }

        // SELLING & ADMIN
        if ((int)$sort_order === $sa_start || (int)$sort_order === $sa_end) {
            $sa_tot_p += $total_primary_total;
            $sa_tot_c += $total_compare_total;
        }

        $inc_dec = $total_primary_total - $total_compare_total;
        $percentage = ($total_compare_total != 0) ? ($inc_dec / $total_compare_total) * 100 : ($total_primary_total != 0 ? 100 : 0);

        $description = $sort_order_descriptions[$sort_order] ?? ('Total for Sort Order ' . $sort_order);

        $hide_summary = ($gl_code_mode === 'old') ? [26, 27, 28] : [27, 28, 29];
        $is_revenue_summary = ($sort_num >= 1 && $sort_num <= $ranges['revenue_end']);
        $is_sa_summary = ($sort_num >= $sa_start && $sort_num <= $sa_end);

        // INDIVIDUAL SUMMARY ROW
        if (!$is_revenue_summary && !$is_sa_summary && !in_array($sort_num, $hide_summary, true)) {
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

        // REVENUES TOTAL
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

        // GROSS PROFIT
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

        // SELLING & ADMIN EXPENSES + EBITDA
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

        // EBIT
        if ((int)$sort_order === $ranges['depreciation']) {
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

        // EBT
        if ((int)$sort_order === $ranges['interest']) {
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

        // NET INCOME
        if ((int)$sort_order === $ranges['tax']) {
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

// BUILD TABLE - Upper Table (Primary vs Previous)
$table_rows_upper = compute_table_rows(
    $conn, $primary_period, $previous_period, $gl_code_mode,
    $gl_mapping, $gl_descriptions, $special_keys, $sort_order_descriptions,
    $gl_id_by_key, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $valid_filters
);

// BUILD TABLE - Lower Table (Primary vs Same Month Previous Year)
// Calculate the same month previous year from primary period
$previous_year_period = '';
if (!empty($primary_period)) {
    $primary_date = new DateTime($primary_period . '-01');
    $primary_date->modify('-1 year');
    $previous_year_period = $primary_date->format('Y-m');
    
    // If previous_year_period is 2025 or earlier, it will use past_transaction data automatically
    // If it's 2026+, it will use manual_adjustment data
    // The fetch_period_data function handles this based on year
}

$table_rows_lower = compute_table_rows(
    $conn, $primary_period, $previous_year_period, $gl_code_mode,
    $gl_mapping, $gl_descriptions, $special_keys, $sort_order_descriptions,
    $gl_id_by_key, $mixed_id_map, $old_gl_mapping, $new_gl_mapping, $valid_filters
);

// HELPER: GET REVENUES FROM TABLE ROWS
function getTotalRevenuesFromRows($table_rows) {
    foreach ($table_rows as $row) {
        if (isset($row['sort_order']) && $row['sort_order'] === 'REVENUES') {
            return $row['primary_total'] ?? 0;
        }
    }
    return 0;
}

$primary_total_revenues = getTotalRevenuesFromRows($table_rows_upper);
$previous_total_revenues = 0;
foreach ($table_rows_upper as $row) {
    if (isset($row['sort_order']) && $row['sort_order'] === 'REVENUES') {
        $previous_total_revenues = $row['compare_total'] ?? 0;
        break;
    }
}

// Get revenues for lower table
$lower_primary_revenues = getTotalRevenuesFromRows($table_rows_lower);
$lower_compare_revenues = 0;
foreach ($table_rows_lower as $row) {
    if (isset($row['sort_order']) && $row['sort_order'] === 'REVENUES') {
        $lower_compare_revenues = $row['compare_total'] ?? 0;
        break;
    }
}

// Format date for display
$primary_display = !empty($primary_period) ? strtoupper(date('F Y', strtotime($primary_period . '-01'))) : '(Primary Period)';
$previous_display = !empty($previous_period) ? strtoupper(date('F Y', strtotime($previous_period . '-01'))) : '(Previous Period)';
$previous_year_display = !empty($previous_year_period) ? strtoupper(date('F Y', strtotime($previous_year_period . '-01'))) : '(Previous Year)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COMPARATIVE PROFIT & LOSS STATEMENT</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .table-separator {
            border-top: 3px solid #e5e7eb;
            margin: 40px 0 30px 0;
            padding-top: 20px;
        }
        .lower-table-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 15px;
            padding: 10px 15px;
            background: #f3f4f6;
            border-radius: 8px;
            border-left: 4px solid #ff0000;
        }
        .lower-table-title i {
            margin-right: 10px;
            color: #ff0000;
        }
    </style>
</head>
<body>
<main class="main-content">
    <header class="top-bar">
        <h2><a href="fs_reports.php" style="font-size:16px;text-decoration:none;">⬅ Back</a></h2>
        <div class="user-badge">
            <span><?= htmlspecialchars($username); ?> (<?= htmlspecialchars($user_type); ?>)</span>
            <div class="avatar"><?= strtoupper(substr($full_name, 0, 1)); ?></div>
        </div>
    </header>

    <div class="content-wrapper">
        <div class="page-title">COMPARATIVE PROFIT & LOSS STATEMENT</div>

        <?php if ($show_error && !empty($error_message)): ?>
            <div class="error-banner">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">
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
                    <label class="radio-option">
                        <input type="radio" name="gl_code_mode" value="mixed" id="glMixedRadio" <?= $gl_code_mode === 'mixed' ? 'checked' : '' ?>>
                        <span>Mix</span>
                    </label>
                </div>
            </div>

            <div class="filter-group">
                <label>Primary Period</label>
                <input type="month" name="primary_period" id="primaryPeriodSelect" value="<?= htmlspecialchars($primary_period) ?>">
            </div>
            <p style="color:red;font-weight:bold;">VS</p>
            <div class="filter-group">
                <label>Previous Period</label>
                <input type="month" name="previous_period" id="previousPeriodSelect" value="<?= htmlspecialchars($previous_period) ?>">
                
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="export_comparative_page_three.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
            </div>
        </form>

        <!-- UPPER TABLE: Primary vs Previous -->
        <div class="region-block">
            <div class="tables-scroll">
                <div class="tables-grid">
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Comparative Report</th>
                                    <th colspan="2">
                                        <?= $primary_display ?>
                                    </th>
                                    <th></th>
                                    <th colspan="2">
                                        <?= $previous_display ?>
                                    </th>
                                    <th></th>
                                    <th colspan="2">INCREASE / DECREASE</th>
                                    <th></th>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="report-tbody">
                                <?php if (empty($table_rows_upper)): ?>
                                    <tr><td colspan="10" style="text-align:center;">No data structure available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($table_rows_upper as $row):
                                        if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                            echo '<tr class="spacer-row" style="height:20px;"><td colspan="10"></td></tr>';
                                            continue;
                                        }

                                        $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
                                        $is_header = !empty($row['is_section_header']);
                                        $primary_total  = $row['primary_total'] ?? 0;
                                        $compare_total = $row['compare_total'] ?? 0;

                                        $primary_percentage_of_revenue  = ($primary_total_revenues != 0) ? ($primary_total / $primary_total_revenues) * 100 : 0;
                                        $compare_percentage_of_revenue = ($previous_total_revenues != 0) ? ($compare_total / $previous_total_revenues) * 100 : 0;

                                        $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $compare_total);
                                        $percentage = isset($row['percentage'])
                                            ? $row['percentage']
                                            : ($compare_total != 0 ? ($inc_dec / abs($compare_total)) * 100 : ($primary_total != 0 ? 100 : 0));

                                        $inc_dec_class    = $inc_dec > 0 ? 'positive' : ($inc_dec < 0 ? 'negative' : '');
                                        $percentage_class = $percentage > 0 ? 'positive' : ($percentage < 0 ? 'negative' : '');
                                    ?>
                                    <tr class="<?= $is_summary_row ? 'summary-row' : 'data-row' ?>" data-sort-order="<?= htmlspecialchars($row['sort_order'] ?? '') ?>">
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>" style="text-align:left;">
                                            <?php if ($is_summary_row): ?>
                                                <?= htmlspecialchars($row['sort_order']) ?>
                                            <?php elseif ($is_header): ?>
                                                <strong><?= htmlspecialchars($row['sub_order']) ?></strong>
                                            <?php else: ?>
                                                <?= htmlspecialchars($row['gl_description']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $primary_total < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : ($is_summary_row ? '<strong>' . number_format($primary_total, 2) . '</strong>' : number_format($primary_total, 2)) ?>
                                        </td>
                                        <td class="percentage-cell <?= $is_summary_row ? 'summary-cell' : '' ?>">
                                            <?php if (!$is_header): ?><?= number_format($primary_percentage_of_revenue, 2) ?>%<?php endif; ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $compare_total < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : ($is_summary_row ? '<strong>' . number_format($compare_total, 2) . '</strong>' : number_format($compare_total, 2)) ?>
                                        </td>
                                        <td class="percentage-cell <?= $is_summary_row ? 'summary-cell' : '' ?>">
                                            <?php if (!$is_header): ?><?= number_format($compare_percentage_of_revenue, 2) ?>%<?php endif; ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                        <td class="numeric-cell <?= $inc_dec_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $inc_dec < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : number_format($inc_dec, 2) ?>
                                        </td>
                                        <td class="percentage-cell <?= $percentage_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $percentage < 0 ? 'color:red;' : '' ?>">
                                            <?php
                                            if ($is_header) echo '';
                                            elseif ($percentage >= 1000 || $percentage <= -1000) echo 'mat';
                                            else echo number_format($percentage, 2) . '%';
                                            ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                    </tr>
                                    <?php if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): ?>
                                        <tr class="spacer-row" style="height:20px;"><td colspan="10"></td></tr>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLE SEPARATOR -->
        <div class="table-separator"></div>

        <!-- LOWER TABLE: Primary vs Same Month Previous Year -->
        <div class="lower-table-title">
            <i class="fa-solid fa-calendar-check"></i> 
            Year-over-Year Comparison: <?= $primary_display ?> vs <?= $previous_year_display ?>
        </div>

        <div class="region-block">
            <div class="tables-scroll">
                <div class="tables-grid">
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Comparative Report</th>
                                    <th colspan="2">
                                        <?= $primary_display ?>
                                    </th>
                                    <th></th>
                                    <th colspan="2">
                                        <?= $previous_year_display ?>
                                    </th>
                                    <th></th>
                                    <th colspan="2">INCREASE / DECREASE</th>
                                    <th></th>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                    <th style="text-align:center;">Amount</th>
                                    <th style="text-align:center;">%</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="report-tbody">
                                <?php if (empty($table_rows_lower)): ?>
                                    <tr><td colspan="10" style="text-align:center;">No data structure available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($table_rows_lower as $row):
                                        if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                            echo '<tr class="spacer-row" style="height:20px;"><td colspan="10"></td></tr>';
                                            continue;
                                        }

                                        $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
                                        $is_header = !empty($row['is_section_header']);
                                        $primary_total  = $row['primary_total'] ?? 0;
                                        $compare_total = $row['compare_total'] ?? 0;

                                        $primary_percentage_of_revenue  = ($lower_primary_revenues != 0) ? ($primary_total / $lower_primary_revenues) * 100 : 0;
                                        $compare_percentage_of_revenue = ($lower_compare_revenues != 0) ? ($compare_total / $lower_compare_revenues) * 100 : 0;

                                        $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $compare_total);
                                        $percentage = isset($row['percentage'])
                                            ? $row['percentage']
                                            : ($compare_total != 0 ? ($inc_dec / abs($compare_total)) * 100 : ($primary_total != 0 ? 100 : 0));

                                        $inc_dec_class    = $inc_dec > 0 ? 'positive' : ($inc_dec < 0 ? 'negative' : '');
                                        $percentage_class = $percentage > 0 ? 'positive' : ($percentage < 0 ? 'negative' : '');
                                    ?>
                                    <tr class="<?= $is_summary_row ? 'summary-row' : 'data-row' ?>" data-sort-order="<?= htmlspecialchars($row['sort_order'] ?? '') ?>">
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>" style="text-align:left;">
                                            <?php if ($is_summary_row): ?>
                                                <?= htmlspecialchars($row['sort_order']) ?>
                                            <?php elseif ($is_header): ?>
                                                <strong><?= htmlspecialchars($row['sub_order']) ?></strong>
                                            <?php else: ?>
                                                <?= htmlspecialchars($row['gl_description']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $primary_total < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : ($is_summary_row ? '<strong>' . number_format($primary_total, 2) . '</strong>' : number_format($primary_total, 2)) ?>
                                        </td>
                                        <td class="percentage-cell <?= $is_summary_row ? 'summary-cell' : '' ?>">
                                            <?php if (!$is_header): ?><?= number_format($primary_percentage_of_revenue, 2) ?>%<?php endif; ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $compare_total < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : ($is_summary_row ? '<strong>' . number_format($compare_total, 2) . '</strong>' : number_format($compare_total, 2)) ?>
                                        </td>
                                        <td class="percentage-cell <?= $is_summary_row ? 'summary-cell' : '' ?>">
                                            <?php if (!$is_header): ?><?= number_format($compare_percentage_of_revenue, 2) ?>%<?php endif; ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                        <td class="numeric-cell <?= $inc_dec_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $inc_dec < 0 ? 'color:red;' : '' ?>">
                                            <?= $is_header ? '' : number_format($inc_dec, 2) ?>
                                        </td>
                                        <td class="percentage-cell <?= $percentage_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $percentage < 0 ? 'color:red;' : '' ?>">
                                            <?php
                                            if ($is_header) echo '';
                                            elseif ($percentage >= 1000 || $percentage <= -1000) echo 'mat';
                                            else echo number_format($percentage, 2) . '%';
                                            ?>
                                        </td>
                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                    </tr>
                                    <?php if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): ?>
                                        <tr class="spacer-row" style="height:20px;"><td colspan="10"></td></tr>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function compareMonths(month1, month2) {
    if (!month1 || !month2) return 0;
    return new Date(month1 + '-01') - new Date(month2 + '-01');
}
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
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'modal-overlay';
    modalOverlay.innerHTML = `
        <div class="modal-container">
            <div class="modal-header"><h3><i class="fa-solid fa-triangle-exclamation"></i> Validation Error</h3></div>
            <div class="modal-body"><p>${escapeHtml(message)}</p></div>
            <div class="modal-footer"><button onclick="closeModal()">OK</button></div>
        </div>`;
    document.body.appendChild(modalOverlay);
    activeModal = modalOverlay;
}
window.closeModal = function () {
    if (activeModal) { activeModal.remove(); activeModal = null; }
};
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// AUTO-SET PREVIOUS PERIOD TO LAST MONTH
function autoSetPreviousPeriod() {
    const primaryInput = document.getElementById('primaryPeriodSelect');
    const previousInput = document.getElementById('previousPeriodSelect');
    
    if (primaryInput.value && !previousInput.value) {
        const primaryDate = new Date(primaryInput.value + '-01');
        primaryDate.setMonth(primaryDate.getMonth() - 1);
        const previousMonth = primaryDate.getFullYear() + '-' + 
                             String(primaryDate.getMonth() + 1).padStart(2, '0');
        previousInput.value = previousMonth;
    }
}

function autoSetAndSubmit() {
    autoSetPreviousPeriod();
}

document.addEventListener('DOMContentLoaded', function() {
    const primaryInput = document.getElementById('primaryPeriodSelect');
    if (primaryInput) {
        autoSetPreviousPeriod();
        primaryInput.addEventListener('change', autoSetAndSubmit);
        primaryInput.addEventListener('input', autoSetPreviousPeriod);
    }
    
    const radioButtons = document.querySelectorAll('input[name="gl_code_mode"]');
    radioButtons.forEach(radio => {
        radio.addEventListener('change', function() {
            setTimeout(autoSetPreviousPeriod, 100);
        });
    });
});

function validateForm() {
    const primaryPeriod  = document.getElementById('primaryPeriodSelect').value;
    const previousPeriod = document.getElementById('previousPeriodSelect').value;
    const glOldRadio     = document.getElementById('glOldRadio');
    const glNewRadio     = document.getElementById('glNewRadio');
    const glMixedRadio   = document.getElementById('glMixedRadio');
    const glCodeMode = glOldRadio.checked ? 'old' : (glNewRadio.checked ? 'new' : (glMixedRadio.checked ? 'mixed' : 'old'));

    if (previousPeriod && !primaryPeriod) {
        showModal('Primary period is required when selecting a Previous period.');
        return false;
    }
    if (!primaryPeriod || !previousPeriod) return true;

    if (compareMonths(primaryPeriod, previousPeriod) <= 0) {
        showModal('Primary period must be later than the Previous period.');
        return false;
    }
    if (glCodeMode === 'old') {
        if (!isMarch2026OrEarlier(primaryPeriod) || !isMarch2026OrEarlier(previousPeriod)) {
            showModal('Old GL Code is only available for March 2026 and earlier. Both Primary and Previous periods must be March 2026 or earlier.');
            return false;
        }
    } else if (glCodeMode === 'new') {
        if (!isApril2026OrLater(primaryPeriod) || !isApril2026OrLater(previousPeriod)) {
            showModal('New GL Code is only available for April 2026 onwards. Both Primary and Previous periods must be April 2026 or later.');
            return false;
        }
    }
    return true;
}
</script>

<?php include '../footer.php'; ?>
</body>
</html>
<?php $conn->close(); ?>