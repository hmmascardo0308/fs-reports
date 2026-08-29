<?php
// consolidated_with_adjustment.php
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

// ============================================================
// FILTERS
// ============================================================
$zone = $_GET['zone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';

$gl_code_mode = in_array(
    $gl_code_mode,
    ['old', 'new'],
    true
)
    ? $gl_code_mode
    : 'old';

// ============================================================
// NEW FILTER: DISPLAY MODE
// ============================================================
$display_mode = $_GET['display_mode'] ?? 'all';

$display_mode = in_array(
    $display_mode,
    ['mlfsi', 'jewelers', 'all'],
    true
)
    ? $display_mode
    : 'all';

// ============================================================
// REGIONS
// Same regional layout as consolidated.php.
// manual_adjustment supplies the region columns.
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

        mysqli_stmt_bind_param(
            $r_stmt,
            's',
            $zone
        );

        mysqli_stmt_execute($r_stmt);

        $r_res = mysqli_stmt_get_result($r_stmt);

        while ($r_row = mysqli_fetch_assoc($r_res)) {

            $r_name = trim(
                (string)($r_row['region'] ?? '')
            );

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
// PERIOD HELPERS
// ============================================================
$error_message = '';

if (
    !empty($primary_period) &&
    empty($previous_period)
) {

    $date_obj =
        DateTime::createFromFormat(
            'Y-m',
            $primary_period
        );

    if ($date_obj) {

        $date_obj->modify('-1 month');

        $previous_period =
            $date_obj->format('Y-m');
    }
}

function compareMonths(
    string $month1,
    string $month2
): int {

    return
        strtotime($month1 . '-01') -
        strtotime($month2 . '-01');
}

function isMarch2026OrEarlier(
    string $month
): bool {

    if (empty($month)) {
        return true;
    }

    return
        strtotime($month . '-01') <=
        strtotime('2026-03-01');
}

function isApril2026OrLater(
    string $month
): bool {

    if (empty($month)) {
        return true;
    }

    return
        strtotime($month . '-01') >=
        strtotime('2026-04-01');
}

// ============================================================
// GL CODE MODE VALIDATION
// ============================================================
$show_error = false;
$valid_filters = true;

if (!empty($primary_period)) {

    if (
        $gl_code_mode === 'old' &&
        !isMarch2026OrEarlier($primary_period)
    ) {

        $error_message =
            'Old GL Code is only available for March 2026 and earlier.';

        $show_error = true;
        $valid_filters = false;
    }

    if (
        $gl_code_mode === 'new' &&
        !isApril2026OrLater($primary_period)
    ) {

        $error_message =
            'New GL Code is only available for April 2026 onwards.';

        $show_error = true;
        $valid_filters = false;
    }
}

// ============================================================
// RESET
// ============================================================
if (
    isset($_GET['reset']) &&
    $_GET['reset'] === '1'
) {

    header(
        "Location: consolidated_with_adjustment.php"
    );

    exit;
}

// ============================================================
// DROPDOWN OPTIONS
// ============================================================
$distinct_zn = [];
$distinct_years = [];

// ============================================================
// ZONES
// ============================================================
$hierarchy_query = "
    SELECT DISTINCT zone
    FROM fs_reports.manual_adjustment
    WHERE zone IS NOT NULL
      AND zone != ''
    ORDER BY zone
";

$hierarchy_res =
    mysqli_query(
        $conn,
        $hierarchy_query
    );

if ($hierarchy_res) {

    while (
        $h = mysqli_fetch_assoc(
            $hierarchy_res
        )
    ) {

        $zn = trim(
            (string)($h['zone'] ?? '')
        );

        if (
            $zn !== '' &&
            !in_array(
                $zn,
                $distinct_zn,
                true
            )
        ) {

            $distinct_zn[] = $zn;
        }
    }
}

sort($distinct_zn);

// ============================================================
// YEARS
// ============================================================
$years_query = "
    SELECT DISTINCT transaction_year
    FROM fs_reports.manual_adjustment
    WHERE transaction_year IS NOT NULL
    ORDER BY transaction_year DESC
";

$years_res =
    mysqli_query(
        $conn,
        $years_query
    );

if ($years_res) {

    while (
        $y = mysqli_fetch_assoc(
            $years_res
        )
    ) {

        $yr = trim(
            (string)($y['transaction_year'] ?? '')
        );

        if (
            $yr !== '' &&
            !in_array(
                $yr,
                $distinct_years,
                true
            )
        ) {

            $distinct_years[] = $yr;
        }
    }
}

// ============================================================
// REPORT STRUCTURE
//
// OLD -> fs_reports.gl_codes
// NEW -> fs_reports.new_gl_codes
//
// gl_id is the comparison key.
// ============================================================
$gl_table =
    ($gl_code_mode === 'new')
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

$gl_structure_result =
    mysqli_query(
        $conn,
        $gl_structure_query
    );

if ($gl_structure_result) {

    while (
        $row = mysqli_fetch_assoc(
            $gl_structure_result
        )
    ) {

        $sort_order = $row['sort_order'];
        $sub_order = $row['sub_order'];

        $key =
            $sort_order .
            '|' .
            (
                $sub_order === null
                    ? ''
                    : $sub_order
            );

        if (
            !isset(
                $report_structure[$key]
            )
        ) {

            $report_structure[$key] = [

                'sort_order' =>
                    $sort_order,

                'description' =>
                    $row['description'],

                'sub_order' =>
                    $sub_order,

                'gl_description_comparative' =>
                    $row['gl_description_comparative'],

                'gl_ids' => []
            ];
        }

        $gl_id = trim(
            (string)($row['gl_id'] ?? '')
        );

        if (
            $gl_id !== '' &&
            !in_array(
                $gl_id,
                $report_structure[$key]['gl_ids'],
                true
            )
        ) {

            $report_structure[$key]['gl_ids'][] =
                $gl_id;
        }

        if (
            !isset(
                $sort_order_descriptions[$sort_order]
            ) &&
            !empty($row['description'])
        ) {

            $sort_order_descriptions[$sort_order] =
                $row['description'];
        }
    }
}

// ============================================================
// SORT STRUCTURE
// ============================================================
uksort(
    $report_structure,
    function ($a, $b) {

        [
            $aSort,
            $aSub
        ] =
            array_pad(
                explode('|', $a, 2),
                2,
                ''
            );

        [
            $bSort,
            $bSub
        ] =
            array_pad(
                explode('|', $b, 2),
                2,
                ''
            );

        $sortCompare =
            (int)$aSort <=>
            (int)$bSort;

        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        if (
            $aSub === '' &&
            $bSub !== ''
        ) {
            return -1;
        }

        if (
            $aSub !== '' &&
            $bSub === ''
        ) {
            return 1;
        }

        return
            (int)$aSub <=>
            (int)$bSub;
    }
);

// ============================================================
// SORT ORDER RANGES
// ============================================================
function getSortOrderRanges(
    string $gl_code_mode
): array {

    if ($gl_code_mode === 'old') {

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
//
// Match ONLY by gl_id.
//
// $data[gl_id][region] =
// [
//     'mlfsi' => ...,
//     'jewelers' => ...,
//     'total' => ...
// ]
//
// total = mlfsi + jewelers
// ============================================================
function get_manual_adjustment_data(
    mysqli $conn,
    string $period,
    string $zone,
    string $transaction_year,
    string $region = ''
): array {

    $data = [];

    if (empty($period)) {
        return $data;
    }

    $parts =
        explode('-', $period);

    $year_val =
        $parts[0] ?? '';

    $month_val =
        $period . '-01';

    if ($year_val === '') {
        return $data;
    }

    $where = [

        "transaction_year = ?",

        "transaction_month = ?"
    ];

    $params = [

        $year_val,

        $month_val
    ];

    $types = "ss";

    if ($zone !== '') {

        $where[] =
            "zone = ?";

        $params[] =
            $zone;

        $types .= "s";
    }

    if ($transaction_year !== '') {

        $where[] =
            "transaction_year = ?";

        $params[] =
            $transaction_year;

        $types .= "s";
    }

    if ($region !== '') {

        $where[] =
            "region = ?";

        $params[] =
            $region;

        $types .= "s";
    }

    $sql = "
        SELECT
            gl_id,
            region,

            SUM(
                COALESCE(mlfsi, 0)
            ) AS mlfsi_amount,

            SUM(
                COALESCE(jewelers, 0)
            ) AS jewelers_amount,

            SUM(
                COALESCE(mlfsi, 0) +
                COALESCE(jewelers, 0)
            ) AS total_amount

        FROM fs_reports.manual_adjustment

        WHERE " .
        implode(
            " AND ",
            $where
        ) . "

          AND gl_id IS NOT NULL

          AND gl_id != ''

        GROUP BY
            gl_id,
            region
    ";

    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );

    if (!$stmt) {
        return $data;
    }

    mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$params
    );

    mysqli_stmt_execute(
        $stmt
    );

    $result =
        mysqli_stmt_get_result(
            $stmt
        );

    while (
        $row =
            mysqli_fetch_assoc($result)
    ) {

        $gl_id =
            trim(
                (string)(
                    $row['gl_id'] ?? ''
                )
            );

        $region_name =
            trim(
                (string)(
                    $row['region'] ?? ''
                )
            );

        if ($gl_id === '') {
            continue;
        }

        $mlfsi =
            (float)(
                $row['mlfsi_amount'] ?? 0
            );

        $jewelers =
            (float)(
                $row['jewelers_amount'] ?? 0
            );

        // IMPORTANT:
        // Displayed region amount is always
        // MLFSi + Jewelers.
        $total =
            $mlfsi +
            $jewelers;

        $data[$gl_id][$region_name] = [

            'mlfsi' =>
                $mlfsi,

            'jewelers' =>
                $jewelers,

            'total' =>
                $total
        ];
    }

    mysqli_stmt_close(
        $stmt
    );

    return $data;
}

// ============================================================
// COMPUTE TABLE ROWS
// ============================================================
function compute_table_rows_for_region_area(
    mysqli $conn,
    string $mainzone,
    string $zone,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    array $report_structure,
    array $sort_order_descriptions,
    string $region,
    string $area,
    array $regions_in_zone,
    string $gl_code_mode,
    string $display_mode,
    bool $use_real_data = true
): array {

    $ranges =
        getSortOrderRanges(
            $gl_code_mode
        );

    // --------------------------------------------------------
    // Primary / Previous data
    // --------------------------------------------------------
    $primary_data = [];
    $previous_data = [];

    if (
        $use_real_data &&
        !empty($primary_period)
    ) {

        $primary_data =
            get_manual_adjustment_data(
                $conn,
                $primary_period,
                $zone,
                $transaction_year,
                $region
            );
    }

    if (
        $use_real_data &&
        !empty($previous_period)
    ) {

        $previous_data =
            get_manual_adjustment_data(
                $conn,
                $previous_period,
                $zone,
                '',
                $region
            );
    }

    $table_rows = [];

    // --------------------------------------------------------
    // Build report rows
    // --------------------------------------------------------
    foreach (
        $report_structure as $key => $structure
    ) {

        $sort_order =
            (int)$structure['sort_order'];

        $sub_order =
            $structure['sub_order'];

        $gl_description =
            $structure[
                'gl_description_comparative'
            ] ?? '';

        $gl_ids =
            $structure['gl_ids'] ?? [];

        $is_inj2 =
            in_array(
                'INJ-2',
                $gl_ids,
                true
            );

        // ----------------------------------------------------
        // Region totals
        //
        // Each region has:
        // MLFSi + Jewelers
        // ----------------------------------------------------
        $row_region_totals = [];

        foreach (
            $regions_in_zone as $r_name
        ) {

            $row_region_totals[$r_name] = [

                'mlfsi' => 0.0,

                'jewelers' => 0.0,

                'total' => 0.0
            ];
        }

        // ----------------------------------------------------
        // Primary total
        // ----------------------------------------------------
        $primary_total = [

            'mlfsi' => 0.0,

            'jewelers' => 0.0,

            'total' => 0.0
        ];

        // ----------------------------------------------------
        // Previous total
        // ----------------------------------------------------
        $previous_total = [

            'mlfsi' => 0.0,

            'jewelers' => 0.0,

            'total' => 0.0
        ];

        // ====================================================
        // PRIMARY
        // ====================================================
        foreach (
            $gl_ids as $gl_id
        ) {

            if (
                !isset(
                    $primary_data[$gl_id]
                )
            ) {
                continue;
            }

            foreach (
                $primary_data[$gl_id]
                as $r_name => $amounts
            ) {

                if (
                    $region !== '' &&
                    $r_name !== $region
                ) {
                    continue;
                }

                $mlfsi =
                    (float)(
                        $amounts['mlfsi'] ?? 0
                    );

                $jewelers =
                    (float)(
                        $amounts['jewelers'] ?? 0
                    );

                // Always calculate this.
                $region_total =
                    $mlfsi +
                    $jewelers;

                if (
                    isset(
                        $row_region_totals[$r_name]
                    )
                ) {

                    $row_region_totals[
                        $r_name
                    ]['mlfsi'] +=
                        $mlfsi;

                    $row_region_totals[
                        $r_name
                    ]['jewelers'] +=
                        $jewelers;

                    $row_region_totals[
                        $r_name
                    ]['total'] +=
                        $region_total;
                }

                $primary_total['mlfsi'] +=
                    $mlfsi;

                $primary_total['jewelers'] +=
                    $jewelers;

                $primary_total['total'] +=
                    $region_total;
            }
        }

        // ====================================================
        // PREVIOUS
        // ====================================================
        foreach (
            $gl_ids as $gl_id
        ) {

            if (
                !isset(
                    $previous_data[$gl_id]
                )
            ) {
                continue;
            }

            foreach (
                $previous_data[$gl_id]
                as $r_name => $amounts
            ) {

                if (
                    $region !== '' &&
                    $r_name !== $region
                ) {
                    continue;
                }

                $mlfsi =
                    (float)(
                        $amounts['mlfsi'] ?? 0
                    );

                $jewelers =
                    (float)(
                        $amounts['jewelers'] ?? 0
                    );

                $previous_total['mlfsi'] +=
                    $mlfsi;

                $previous_total['jewelers'] +=
                    $jewelers;

                $previous_total['total'] +=
                    (
                        $mlfsi +
                        $jewelers
                    );
            }
        }

        $table_rows[] = [

            'sort_order' =>
                $sort_order,

            'sub_order' =>
                $sub_order,

            'gl_description' =>
                $gl_description,

            'is_section_header' =>
                false,

            'is_summary_row' =>
                false,

            'primary_total' =>
                $primary_total,

            'previous_total' =>
                $previous_total,

            'region_totals' =>
                $row_region_totals,

            'is_inj2' =>
                $is_inj2
        ];
    }

    // ========================================================
    // GROUP BY SORT ORDER
    // ========================================================
    $grouped_rows = [];

    foreach (
        $table_rows as $row
    ) {

        $sort_order =
            $row['sort_order'];

        if (
            !isset(
                $grouped_rows[$sort_order]
            )
        ) {

            $grouped_rows[$sort_order] = [];
        }

        $grouped_rows[$sort_order][] =
            $row;
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

    // ========================================================
    // REGION CUMULATIVE ARRAYS
    // ========================================================
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
    foreach (
        $grouped_rows as $sort_order => $rows
    ) {

        $sort_num =
            (int)$sort_order;

        // ----------------------------------------------------
        // Hide detail rows for direct-total rows.
        // ----------------------------------------------------
        if (
            !in_array(
                $sort_num,
                [6, 8, 11],
                true
            )
        ) {

            foreach (
                $rows as $row
            ) {

                $final_table_rows[] =
                    $row;
            }
        }

        // ====================================================
        // SORT ORDER TOTAL
        // ====================================================
        $total_primary = [

            'mlfsi' => 0.0,

            'jewelers' => 0.0,

            'total' => 0.0
        ];

        $total_previous = [

            'mlfsi' => 0.0,

            'jewelers' => 0.0,

            'total' => 0.0
        ];

        foreach (
            $rows as $row
        ) {

            $total_primary['mlfsi'] +=
                $row['primary_total']['mlfsi'];

            $total_primary['jewelers'] +=
                $row['primary_total']['jewelers'];

            $total_primary['total'] +=
                (
                    $row['primary_total']['mlfsi'] +
                    $row['primary_total']['jewelers']
                );

            $total_previous['mlfsi'] +=
                $row['previous_total']['mlfsi'];

            $total_previous['jewelers'] +=
                $row['previous_total']['jewelers'];

            $total_previous['total'] +=
                (
                    $row['previous_total']['mlfsi'] +
                    $row['previous_total']['jewelers']
                );
        }

        // ====================================================
        // REGION SUMMARY TOTALS
        // ====================================================
        $summary_region_totals = [];

        foreach (
            $regions_in_zone as $r_name
        ) {

            $summary_region_totals[$r_name] = [

                'mlfsi' => 0.0,

                'jewelers' => 0.0,

                'total' => 0.0
            ];

            foreach (
                $rows as $row
            ) {

                if (
                    isset(
                        $row['region_totals'][$r_name]
                    )
                ) {

                    $summary_region_totals[
                        $r_name
                    ]['mlfsi'] +=
                        $row[
                            'region_totals'
                        ][$r_name]['mlfsi'];

                    $summary_region_totals[
                        $r_name
                    ]['jewelers'] +=
                        $row[
                            'region_totals'
                        ][$r_name]['jewelers'];

                    // IMPORTANT:
                    // Region display total =
                    // MLFSi + Jewelers.
                    $summary_region_totals[
                        $r_name
                    ]['total'] +=
                        (
                            $row[
                                'region_totals'
                            ][$r_name]['mlfsi']
                            +
                            $row[
                                'region_totals'
                            ][$r_name]['jewelers']
                        );
                }
            }
        }

        // ====================================================
        // TOTAL REVENUES
        // ====================================================
        if (
            $sort_num >=
                $ranges['revenue_start'] &&
            $sort_num <=
                $ranges['revenue_end']
        ) {

            $rev_tot_p +=
                $total_primary['mlfsi'] +
                $total_primary['jewelers'];

            $rev_tot_prev +=
                $total_previous['mlfsi'] +
                $total_previous['jewelers'];

            $rev_mlfsi_p +=
                $total_primary['mlfsi'];

            $rev_mlfsi_prev +=
                $total_previous['mlfsi'];

            $rev_jew_p +=
                $total_primary['jewelers'];

            $rev_jew_prev +=
                $total_previous['jewelers'];

            foreach (
                $regions_in_zone as $rn
            ) {

                if (
                    !isset(
                        $rev_reg_p[$rn]
                    )
                ) {

                    $rev_reg_p[$rn] = [

                        'mlfsi' => 0.0,

                        'jewelers' => 0.0,

                        'total' => 0.0
                    ];
                }

                $rev_reg_p[$rn]['mlfsi'] +=
                    $summary_region_totals[
                        $rn
                    ]['mlfsi'];

                $rev_reg_p[$rn]['jewelers'] +=
                    $summary_region_totals[
                        $rn
                    ]['jewelers'];

                $rev_reg_p[$rn]['total'] +=
                    (
                        $summary_region_totals[
                            $rn
                        ]['mlfsi']
                        +
                        $summary_region_totals[
                            $rn
                        ]['jewelers']
                    );
            }
        }

        // ====================================================
        // SELLING & ADMIN EXPENSES
        // ====================================================
        if (
            $sort_num >=
                $ranges['sa_start'] &&
            $sort_num <=
                $ranges['sa_end']
        ) {

            $sa_tot_p +=
                $total_primary['mlfsi'] +
                $total_primary['jewelers'];

            $sa_tot_prev +=
                $total_previous['mlfsi'] +
                $total_previous['jewelers'];

            $sa_mlfsi_p +=
                $total_primary['mlfsi'];

            $sa_mlfsi_prev +=
                $total_previous['mlfsi'];

            $sa_jew_p +=
                $total_primary['jewelers'];

            $sa_jew_prev +=
                $total_previous['jewelers'];

            foreach (
                $regions_in_zone as $rn
            ) {

                if (
                    !isset(
                        $sa_reg_p[$rn]
                    )
                ) {

                    $sa_reg_p[$rn] = [

                        'mlfsi' => 0.0,

                        'jewelers' => 0.0,

                        'total' => 0.0
                    ];
                }

                $sa_reg_p[$rn]['mlfsi'] +=
                    $summary_region_totals[
                        $rn
                    ]['mlfsi'];

                $sa_reg_p[$rn]['jewelers'] +=
                    $summary_region_totals[
                        $rn
                    ]['jewelers'];

                $sa_reg_p[$rn]['total'] +=
                    (
                        $summary_region_totals[
                            $rn
                        ]['mlfsi']
                        +
                        $summary_region_totals[
                            $rn
                        ]['jewelers']
                    );
            }
        }

        // ====================================================
        // INCREASE / DECREASE
        // ====================================================
        $inc_dec =
            $total_primary['total'] -
            $total_previous['total'];

        $percentage = 0.0;

        if (
            $total_previous['total'] != 0
        ) {

            $percentage =
                (
                    $inc_dec /
                    $total_previous['total']
                ) * 100;

        } elseif (
            $total_primary['total'] != 0
        ) {

            $percentage = 100;
        }

        $description =
            $sort_order_descriptions[
                $sort_num
            ] ??
            "Total for Sort Order " .
            $sort_num;

        // ====================================================
        // SUMMARY ROW
        // ====================================================
        if (
            !in_array(
                $sort_num,
                [
                    $ranges['depreciation'],
                    $ranges['interest'],
                    $ranges['tax']
                ],
                true
            )
        ) {

            $final_table_rows[] = [

                'sort_order' =>
                    $sort_num,

                'sub_order' => '',

                'gl_description' =>
                    $description,

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $total_primary['mlfsi'],

                    'jewelers' =>
                        $total_primary['jewelers'],

                    'total' =>
                        (
                            $total_primary['mlfsi'] +
                            $total_primary['jewelers']
                        )
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $total_previous['mlfsi'],

                    'jewelers' =>
                        $total_previous['jewelers'],

                    'total' =>
                        (
                            $total_previous['mlfsi'] +
                            $total_previous['jewelers']
                        )
                ],

                'region_totals' =>
                    $summary_region_totals,

                'inc_dec' =>
                    $inc_dec,

                'percentage' =>
                    $percentage
            ];

            if (
                $sort_num >=
                    $ranges['sa_start'] &&
                $sort_num <=
                    $ranges['sa_end']
            ) {

                $final_table_rows[] = [

                    'is_manual_spacer' =>
                        true
                ];
            }
        }

        // ====================================================
        // TOTAL REVENUES
        // ====================================================
        if (
            $sort_num ==
            $ranges['revenue_end']
        ) {

            $inc_dec_rev =
                $rev_tot_p -
                $rev_tot_prev;

            $pct_rev =
                $rev_tot_prev != 0
                    ? (
                        $inc_dec_rev /
                        abs($rev_tot_prev)
                    ) * 100
                    : (
                        $rev_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    'TOTAL REVENUES',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $rev_mlfsi_p,

                    'jewelers' =>
                        $rev_jew_p,

                    'total' =>
                        $rev_mlfsi_p +
                        $rev_jew_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $rev_mlfsi_prev,

                    'jewelers' =>
                        $rev_jew_prev,

                    'total' =>
                        $rev_mlfsi_prev +
                        $rev_jew_prev
                ],

                'region_totals' =>
                    $rev_reg_p,

                'inc_dec' =>
                    $inc_dec_rev,

                'percentage' =>
                    $pct_rev
            ];

            $final_table_rows[] = [

                'is_manual_spacer' =>
                    true
            ];

            $final_table_rows[] = [

                'sort_order' => '',

                'sub_order' =>
                    'Cost of Sales/Service',

                'gl_description' => '',

                'is_section_header' =>
                    true,

                'is_summary_row' =>
                    true,

                'inc_dec' => null,

                'percentage' => null
            ];
        }

        // ====================================================
        // GROSS PROFIT
        // ====================================================
        if (
            $sort_num ==
            $ranges['cost_of_sales']
        ) {

            $gp_tot_p =
                $rev_tot_p -
                (
                    $total_primary['mlfsi'] +
                    $total_primary['jewelers']
                );

            $gp_tot_prev =
                $rev_tot_prev -
                (
                    $total_previous['mlfsi'] +
                    $total_previous['jewelers']
                );

            $gp_mlfsi_p =
                $rev_mlfsi_p -
                $total_primary['mlfsi'];

            $gp_mlfsi_prev =
                $rev_mlfsi_prev -
                $total_previous['mlfsi'];

            $gp_jew_p =
                $rev_jew_p -
                $total_primary['jewelers'];

            $gp_jew_prev =
                $rev_jew_prev -
                $total_previous['jewelers'];

            $gp_reg_p = [];

            foreach (
                $regions_in_zone as $rn
            ) {

                $gp_reg_p[$rn] = [

                    'mlfsi' =>
                        (
                            $rev_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['mlfsi'] ?? 0
                        ),

                    'jewelers' =>
                        (
                            $rev_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['jewelers'] ?? 0
                        ),

                    'total' =>
                        (
                            $rev_reg_p[
                                $rn
                            ]['total'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['total'] ?? 0
                        )
                ];
            }

            $inc_dec_gp =
                $gp_tot_p -
                $gp_tot_prev;

            $pct_gp =
                $gp_tot_prev != 0
                    ? (
                        $inc_dec_gp /
                        abs($gp_tot_prev)
                    ) * 100
                    : (
                        $gp_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    'GROSS PROFIT',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $gp_mlfsi_p,

                    'jewelers' =>
                        $gp_jew_p,

                    'total' =>
                        $gp_tot_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $gp_mlfsi_prev,

                    'jewelers' =>
                        $gp_jew_prev,

                    'total' =>
                        $gp_tot_prev
                ],

                'region_totals' =>
                    $gp_reg_p,

                'inc_dec' =>
                    $inc_dec_gp,

                'percentage' =>
                    $pct_gp
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'SELLING & ADMIN EXPENSE',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    true,

                'is_summary_row' =>
                    true,

                'inc_dec' => null,

                'percentage' => null
            ];
        }

        // ====================================================
        // TOTAL SELLING & ADMIN EXPENSES / EBITDA
        // ====================================================
        if (
            $sort_num ==
            $ranges['sa_end']
        ) {

            $sa_total =
                $sa_mlfsi_p +
                $sa_jew_p;

            $sa_total_prev =
                $sa_mlfsi_prev +
                $sa_jew_prev;

            $inc_dec_sa =
                $sa_total -
                $sa_total_prev;

            $pct_sa =
                $sa_total_prev != 0
                    ? (
                        $inc_dec_sa /
                        abs($sa_total_prev)
                    ) * 100
                    : (
                        $sa_total != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    'TOTAL SELLING AND ADMIN EXPENSES',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $sa_mlfsi_p,

                    'jewelers' =>
                        $sa_jew_p,

                    'total' =>
                        $sa_total
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $sa_mlfsi_prev,

                    'jewelers' =>
                        $sa_jew_prev,

                    'total' =>
                        $sa_total_prev
                ],

                'region_totals' =>
                    $sa_reg_p,

                'inc_dec' =>
                    $inc_dec_sa,

                'percentage' =>
                    $pct_sa
            ];

            $final_table_rows[] = [

                'is_manual_spacer' =>
                    true
            ];

            // =================================================
            // EBITDA
            // =================================================
            $ebitda_tot_p =
                $gp_tot_p -
                $sa_total;

            $ebitda_tot_prev =
                $gp_tot_prev -
                $sa_total_prev;

            $ebitda_mlfsi_p =
                $gp_mlfsi_p -
                $sa_mlfsi_p;

            $ebitda_mlfsi_prev =
                $gp_mlfsi_prev -
                $sa_mlfsi_prev;

            $ebitda_jew_p =
                $gp_jew_p -
                $sa_jew_p;

            $ebitda_jew_prev =
                $gp_jew_prev -
                $sa_jew_prev;

            $ebitda_reg_p = [];

            foreach (
                $regions_in_zone as $rn
            ) {

                $ebitda_reg_p[$rn] = [

                    'mlfsi' =>
                        (
                            $gp_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ) -
                        (
                            $sa_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ),

                    'jewelers' =>
                        (
                            $gp_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ) -
                        (
                            $sa_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ),

                    'total' =>
                        (
                            $gp_reg_p[
                                $rn
                            ]['total'] ?? 0
                        ) -
                        (
                            $sa_reg_p[
                                $rn
                            ]['total'] ?? 0
                        )
                ];
            }

            $inc_dec_ebitda =
                $ebitda_tot_p -
                $ebitda_tot_prev;

            $pct_ebitda =
                $ebitda_tot_prev != 0
                    ? (
                        $inc_dec_ebitda /
                        abs($ebitda_tot_prev)
                    ) * 100
                    : (
                        $ebitda_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $ebitda_mlfsi_p,

                    'jewelers' =>
                        $ebitda_jew_p,

                    'total' =>
                        $ebitda_tot_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $ebitda_mlfsi_prev,

                    'jewelers' =>
                        $ebitda_jew_prev,

                    'total' =>
                        $ebitda_tot_prev
                ],

                'region_totals' =>
                    $ebitda_reg_p,

                'inc_dec' =>
                    $inc_dec_ebitda,

                'percentage' =>
                    $pct_ebitda
            ];
        }

        // ====================================================
        // EBIT
        // ====================================================
        if (
            $sort_num ==
            $ranges['depreciation']
        ) {

            $ebit_tot_p =
                $ebitda_tot_p -
                (
                    $total_primary['mlfsi'] +
                    $total_primary['jewelers']
                );

            $ebit_tot_prev =
                $ebitda_tot_prev -
                (
                    $total_previous['mlfsi'] +
                    $total_previous['jewelers']
                );

            $ebit_mlfsi_p =
                $ebitda_mlfsi_p -
                $total_primary['mlfsi'];

            $ebit_mlfsi_prev =
                $ebitda_mlfsi_prev -
                $total_previous['mlfsi'];

            $ebit_jew_p =
                $ebitda_jew_p -
                $total_primary['jewelers'];

            $ebit_jew_prev =
                $ebitda_jew_prev -
                $total_previous['jewelers'];

            $ebit_reg_p = [];

            foreach (
                $regions_in_zone as $rn
            ) {

                $ebit_reg_p[$rn] = [

                    'mlfsi' =>
                        (
                            $ebitda_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['mlfsi'] ?? 0
                        ),

                    'jewelers' =>
                        (
                            $ebitda_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['jewelers'] ?? 0
                        ),

                    'total' =>
                        (
                            $ebitda_reg_p[
                                $rn
                            ]['total'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['total'] ?? 0
                        )
                ];
            }

            $inc_dec_ebit =
                $ebit_tot_p -
                $ebit_tot_prev;

            $pct_ebit =
                $ebit_tot_prev != 0
                    ? (
                        $inc_dec_ebit /
                        abs($ebit_tot_prev)
                    ) * 100
                    : (
                        $ebit_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'is_manual_spacer' =>
                    true
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'EARNINGS BEFORE INTEREST & TAXES',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $ebit_mlfsi_p,

                    'jewelers' =>
                        $ebit_jew_p,

                    'total' =>
                        $ebit_tot_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $ebit_mlfsi_prev,

                    'jewelers' =>
                        $ebit_jew_prev,

                    'total' =>
                        $ebit_tot_prev
                ],

                'region_totals' =>
                    $ebit_reg_p,

                'inc_dec' =>
                    $inc_dec_ebit,

                'percentage' =>
                    $pct_ebit
            ];
        }

        // ====================================================
        // EBT
        // ====================================================
        if (
            $sort_num ==
            $ranges['interest']
        ) {

            $ebt_tot_p =
                $ebit_tot_p -
                (
                    $total_primary['mlfsi'] +
                    $total_primary['jewelers']
                );

            $ebt_tot_prev =
                $ebit_tot_prev -
                (
                    $total_previous['mlfsi'] +
                    $total_previous['jewelers']
                );

            $ebt_mlfsi_p =
                $ebit_mlfsi_p -
                $total_primary['mlfsi'];

            $ebt_mlfsi_prev =
                $ebit_mlfsi_prev -
                $total_previous['mlfsi'];

            $ebt_jew_p =
                $ebit_jew_p -
                $total_primary['jewelers'];

            $ebt_jew_prev =
                $ebit_jew_prev -
                $total_previous['jewelers'];

            $ebt_reg_p = [];

            foreach (
                $regions_in_zone as $rn
            ) {

                $ebt_reg_p[$rn] = [

                    'mlfsi' =>
                        (
                            $ebit_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['mlfsi'] ?? 0
                        ),

                    'jewelers' =>
                        (
                            $ebit_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['jewelers'] ?? 0
                        ),

                    'total' =>
                        (
                            $ebit_reg_p[
                                $rn
                            ]['total'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['total'] ?? 0
                        )
                ];
            }

            $inc_dec_ebt =
                $ebt_tot_p -
                $ebt_tot_prev;

            $pct_ebt =
                $ebt_tot_prev != 0
                    ? (
                        $inc_dec_ebt /
                        abs($ebt_tot_prev)
                    ) * 100
                    : (
                        $ebt_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'is_manual_spacer' =>
                    true
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'EARNINGS BEFORE TAXES',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $ebt_mlfsi_p,

                    'jewelers' =>
                        $ebt_jew_p,

                    'total' =>
                        $ebt_tot_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $ebt_mlfsi_prev,

                    'jewelers' =>
                        $ebt_jew_prev,

                    'total' =>
                        $ebt_tot_prev
                ],

                'region_totals' =>
                    $ebt_reg_p,

                'inc_dec' =>
                    $inc_dec_ebt,

                'percentage' =>
                    $pct_ebt
            ];
        }

        // ====================================================
        // NET INCOME
        // ====================================================
        if (
            $sort_num ==
            $ranges['tax']
        ) {

            $net_tot_p =
                $ebt_tot_p -
                (
                    $total_primary['mlfsi'] +
                    $total_primary['jewelers']
                );

            $net_tot_prev =
                $ebt_tot_prev -
                (
                    $total_previous['mlfsi'] +
                    $total_previous['jewelers']
                );

            $net_mlfsi_p =
                $ebt_mlfsi_p -
                $total_primary['mlfsi'];

            $net_mlfsi_prev =
                $ebt_mlfsi_prev -
                $total_previous['mlfsi'];

            $net_jew_p =
                $ebt_jew_p -
                $total_primary['jewelers'];

            $net_jew_prev =
                $ebt_jew_prev -
                $total_previous['jewelers'];

            $net_reg_p = [];

            foreach (
                $regions_in_zone as $rn
            ) {

                $net_reg_p[$rn] = [

                    'mlfsi' =>
                        (
                            $ebt_reg_p[
                                $rn
                            ]['mlfsi'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['mlfsi'] ?? 0
                        ),

                    'jewelers' =>
                        (
                            $ebt_reg_p[
                                $rn
                            ]['jewelers'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['jewelers'] ?? 0
                        ),

                    'total' =>
                        (
                            $ebt_reg_p[
                                $rn
                            ]['total'] ?? 0
                        ) -
                        (
                            $summary_region_totals[
                                $rn
                            ]['total'] ?? 0
                        )
                ];
            }

            $inc_dec_net =
                $net_tot_p -
                $net_tot_prev;

            $pct_net =
                $net_tot_prev != 0
                    ? (
                        $inc_dec_net /
                        abs($net_tot_prev)
                    ) * 100
                    : (
                        $net_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'is_manual_spacer' =>
                    true
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'TOTAL NET INCOME/LOSS',

                'sub_order' => '',

                'gl_description' => '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' => [

                    'mlfsi' =>
                        $net_mlfsi_p,

                    'jewelers' =>
                        $net_jew_p,

                    'total' =>
                        $net_tot_p
                ],

                'previous_total' => [

                    'mlfsi' =>
                        $net_mlfsi_prev,

                    'jewelers' =>
                        $net_jew_prev,

                    'total' =>
                        $net_tot_prev
                ],

                'region_totals' =>
                    $net_reg_p,

                'inc_dec' =>
                    $inc_dec_net,

                'percentage' =>
                    $pct_net
            ];
        }
    }

    return $final_table_rows;
}

// ============================================================
// BUILD TABLES
// ============================================================
$tables_by_region = [

    '' => [

        [

            'area' => '',

            'rows' =>
                compute_table_rows_for_region_area(

                    $conn,

                    '',

                    $zone,

                    $transaction_year,

                    $primary_period,

                    $previous_period,

                    $report_structure,

                    $sort_order_descriptions,

                    '',

                    '',

                    $regions_in_zone,

                    $gl_code_mode,

                    $display_mode,

                    $valid_filters
                )
        ]
    ]
];

// ============================================================
// SORT RANGES FOR JAVASCRIPT
// ============================================================
$ranges =
    getSortOrderRanges(
        $gl_code_mode
    );

// ============================================================
// TABLE COLUMN COUNT
//
// 4 fixed columns:
//
// 1. Sort Order
// 2. Description
// 3. GL Description
// 4. Blank
//
// Then:
// 1 column per region
// + 1 Grand Total column
// ============================================================
$total_columns =
    $has_regions
        ? ($num_regions + 5)
        : 5;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Consolidated Report with Adjustment
    </title>

    <link
        rel="icon"
        href="../images/MLW%20Logo.png"
        type="image/png"
    />

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="css/comparative_original.css?v=<?= time(); ?>"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    >

    <style>

        .data-table td,
        .data-table th {
            white-space: nowrap;
        }

        .grand-total-cell {
            font-weight: 600;
        }

        .filter-group--display-mode {
            min-width: 180px;
        }

        .filter-group--display-mode .radio-group {
            display: flex;
            gap: 10px;
        }

        .filter-group--display-mode .radio-option {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .filter-group--display-mode .radio-option:hover {
            background: #f3f4f6;
        }

        .filter-group--display-mode .radio-option input[type="radio"] {
            margin: 0;
            cursor: pointer;
        }

        .filter-group--display-mode .radio-option span {
            user-select: none;
        }

        .display-mode-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e5e7eb;
            color: #374151;
        }

        .display-mode-badge.mlfsi {
            background: #dbeafe;
            color: #1e40af;
        }

        .display-mode-badge.jewelers {
            background: #fce4ec;
            color: #b71c1c;
        }

        .display-mode-badge.all {
            background: #d1fae5;
            color: #065f46;
        }

    </style>

</head>

<body>

<main class="main-content">

    <header class="top-bar">

        <h2>

            <a
                href="settings.php"
                style="
                    font-size: 16px;
                    text-decoration: none;
                "
            >
                ⬅ Back
            </a>

        </h2>

        <div class="user-badge">

            <span>

                <?php
                echo htmlspecialchars(
                    $username
                );
                ?>

                (<?php
                echo htmlspecialchars(
                    $user_type
                );
                ?>)

            </span>

            <div class="avatar">

                <?php
                echo strtoupper(
                    substr(
                        $full_name,
                        0,
                        1
                    )
                );
                ?>

            </div>

        </div>

    </header>

    <div class="content-wrapper">

        <div class="page-title">

            Consolidated Report with Adjustment

        </div>

        <!-- ====================================================
             ERROR BANNER
        ===================================================== -->
        <?php if (
            $show_error &&
            !empty($error_message)
        ): ?>

            <div class="error-banner">

                <i
                    class="fa-solid fa-circle-exclamation"
                ></i>

                <span>

                    <?php
                    echo htmlspecialchars(
                        $error_message
                    );
                    ?>

                </span>

            </div>

        <?php endif; ?>

        <!-- ====================================================
             FILTER FORM
        ===================================================== -->
        <form
            method="GET"
            class="filter-form"
            id="filterForm"
        >

            <div class="filter-group">

                <label>
                    Zone
                </label>

                <select
                    name="zone"
                    id="zoneSelect"
                >

                    <option value="">
                        Zones
                    </option>

                    <?php foreach (
                        $distinct_zn
                        as $zn_val
                    ): ?>

                        <option
                            value="<?= htmlspecialchars($zn_val) ?>"
                            <?= $zone === $zn_val
                                ? 'selected'
                                : '' ?>
                        >
                            <?= htmlspecialchars($zn_val) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="filter-group">

                <label>
                    Transaction Year
                </label>

                <select
                    name="transaction_year"
                    id="yearSelect"
                >

                    <option value="">
                        Years
                    </option>

                    <?php foreach (
                        $distinct_years
                        as $yr
                    ): ?>

                        <option
                            value="<?= htmlspecialchars($yr) ?>"
                            <?= $transaction_year === $yr
                                ? 'selected'
                                : '' ?>
                        >
                            <?= htmlspecialchars($yr) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- ====================================================
                 NEW DISPLAY MODE FILTER
            ===================================================== -->
            <div class="filter-group filter-group--display-mode">

                <label>
                    DisplaY Amount
                    <span class="display-mode-badge <?= $display_mode ?>">
                        <?= strtoupper($display_mode) ?>
                    </span>
                </label>

                <div
                    class="radio-group"
                    role="radiogroup"
                    aria-label="Display Mode"
                >

                    <label
                        class="radio-option"
                    >

                        <input
                            type="radio"
                            name="display_mode"
                            value="mlfsi"
                            id="displayMlfsi"
                            <?= $display_mode === 'mlfsi'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            MLFSi
                        </span>

                    </label>

                    <label
                        class="radio-option"
                    >

                        <input
                            type="radio"
                            name="display_mode"
                            value="jewelers"
                            id="displayJewelers"
                            <?= $display_mode === 'jewelers'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            Jewelers
                        </span>

                    </label>

                    <label
                        class="radio-option"
                    >

                        <input
                            type="radio"
                            name="display_mode"
                            value="all"
                            id="displayAll"
                            <?= $display_mode === 'all'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            All Total
                        </span>

                    </label>

                </div>

            </div>

            <div class="filter-group filter-group--gl-mode">

                <label>
                    GL Code
                </label>

                <div
                    class="radio-group"
                    role="radiogroup"
                    aria-label="GL Code Mode"
                >

                    <label
                        class="radio-option"
                    >

                        <input
                            type="radio"
                            name="gl_code_mode"
                            value="old"
                            id="glOldRadio"
                            <?= $gl_code_mode === 'old'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            Old GL Code
                        </span>

                    </label>

                    <label
                        class="radio-option"
                    >

                        <input
                            type="radio"
                            name="gl_code_mode"
                            value="new"
                            id="glNewRadio"
                            <?= $gl_code_mode === 'new'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            New GL Code
                        </span>

                    </label>

                </div>

            </div>

            <div class="filter-group">

                <label>
                    Transaction Month
                </label>

                <input
                    type="month"
                    name="primary_period"
                    id="primaryPeriodSelect"
                    value="<?= htmlspecialchars($primary_period) ?>"
                >

            </div>

            <div class="filter-actions">

                <button
                    type="submit"
                    class="btn-filter"
                >

                    <i
                        class="fa-solid fa-filter"
                    ></i>

                    Filter

                </button>

                <button
                    type="button"
                    class="btn-collapse"
                    id="collapseBtn"
                >

                    <i
                        class="fa-solid fa-compress"
                    ></i>

                    Collapse

                </button>

                <a
                    href="export_consolidated_report.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"
                    class="btn-export"
                >

                    <i
                        class="fa-solid fa-file-excel"
                    ></i>

                    Export Excel

                </a>

                <a
                    href="?reset=1"
                    class="btn-reset"
                >

                    <i
                        class="fa-solid fa-rotate-left"
                    ></i>

                    Clear

                </a>

            </div>

        </form>

        <!-- ====================================================
             TABLES
        ===================================================== -->
        <?php foreach (
            $tables_by_region
            as $region_name => $tables
        ): ?>

            <div class="region-block">

                <div class="tables-scroll">

                    <div class="tables-grid">

                        <?php foreach (
                            $tables as $t
                        ): ?>

                            <?php

                            $table_rows =
                                $t['rows'];

                            $display_region =
                                $region_name;

                            $display_area =
                                $t['area'];

                            ?>

                            <div
                                class="table-container"
                            >

                                <table
                                    class="data-table"
                                >

                                    <!-- ====================================================
                                         TABLE HEADER
                                    ==================================================== -->
                                    <thead>

                                        <!-- Report title -->
                                        <tr>

                                            <th colspan="4">

                                                <?php

                                                echo !empty($zone)

                                                    ? 'Zone: ' .
                                                        htmlspecialchars(
                                                            $zone
                                                        )

                                                    : 'All Zones';

                                                ?>

                                            </th>

                                            <th
                                                colspan="<?= $has_regions
                                                    ? ($num_regions + 1)
                                                    : 1 ?>"
                                            >

                                                CONSOLIDATED
                                                PROFIT & LOSS
                                                STATEMENT

                                            </th>

                                        </tr>

                                        <!-- Period and Display Mode -->
                                        <tr>

                                            <th colspan="4"></th>

                                            <?php

                                            $period_display =

                                                !empty(
                                                    $primary_period
                                                )

                                                ? strtoupper(
                                                    date(
                                                        'F Y',
                                                        strtotime(
                                                            $primary_period .
                                                            '-01'
                                                        )
                                                    )
                                                )

                                                : '(Transaction Month)';

                                            ?>

                                            <th
                                                colspan="<?= $has_regions
                                                    ? ($num_regions + 1)
                                                    : 1 ?>"
                                            >

                                                <?= $period_display ?>

                                                <span style="font-weight:400;font-size:11px;margin-left:8px;">
                                                    (<?= strtoupper($display_mode) ?>)
                                                </span>

                                            </th>

                                        </tr>

                                        <!-- ====================================================
                                             REGION HEADER
                                        ==================================================== -->
                                        <tr>

                                            <th colspan="4"></th>

                                            <?php if (
                                                $has_regions
                                            ): ?>

                                                <?php foreach (
                                                    $regions_in_zone
                                                    as $r
                                                ): ?>

                                                    <th>
                                                        <?= htmlspecialchars(
                                                            $r
                                                        ) ?>
                                                    </th>

                                                <?php endforeach; ?>

                                                <th>
                                                    Grand Total
                                                </th>

                                            <?php else: ?>

                                                <th>
                                                    Grand Total
                                                </th>

                                            <?php endif; ?>

                                        </tr>

                                        <!-- ====================================================
                                             AMOUNT HEADER
                                        ==================================================== -->
                                        <tr>

                                            <th></th>
                                            <th>Description</th>
                                            <th colspan="2">Comparative Description</th>


                                            <?php if (
                                                $has_regions
                                            ): ?>

                                                <?php foreach (
                                                    $regions_in_zone
                                                    as $r
                                                ): ?>

                                                    <th>
                                                        Total
                                                    </th>

                                                <?php endforeach; ?>

                                                <th>
                                                    Total
                                                </th>

                                            <?php else: ?>

                                                <th>
                                                    Total
                                                </th>

                                            <?php endif; ?>

                                        </tr>

                                    </thead>

                                    <!-- ====================================================
                                         TABLE BODY
                                    ==================================================== -->
                                    <tbody
                                        class="report-tbody"
                                    >

                                        <!-- Initial spacer -->
                                        <tr
                                            class="initial-spacer"
                                        >

                                            <td
                                                colspan="<?= $total_columns ?>"
                                            ></td>

                                        </tr>

                                        <!-- Revenues header -->
                                        <tr
                                            class="revenues-header-row"
                                        >

                                            <td
                                                style="
                                                    background-color: #ff7f29;
                                                    font-weight: bold;
                                                "
                                            >
                                                REVENUES
                                            </td>

                                            <td
                                                colspan="<?= $total_columns - 1 ?>"
                                                style="
                                                    background-color: #ff7f29;
                                                    font-weight: bold;
                                                "
                                            ></td>

                                        </tr>

                                        <?php if (
                                            empty($table_rows)
                                        ): ?>

                                            <tr>

                                                <td
                                                    colspan="<?= $total_columns ?>"
                                                    style="
                                                        text-align: center;
                                                    "
                                                >
                                                    No data structure available
                                                </td>

                                            </tr>

                                        <?php else: ?>

                                            <?php foreach (
                                                $table_rows
                                                as $row
                                            ): ?>

                                                <!-- ====================================================
                                                     MANUAL SPACER
                                                ==================================================== -->
                                                <?php if (
                                                    isset(
                                                        $row[
                                                            'is_manual_spacer'
                                                        ]
                                                    ) &&
                                                    $row[
                                                        'is_manual_spacer'
                                                    ]
                                                ): ?>

                                                    <tr
                                                        class="spacer-row"
                                                        style="
                                                            height: 20px;
                                                        "
                                                    >

                                                        <td
                                                            colspan="<?= $total_columns ?>"
                                                        ></td>

                                                    </tr>

                                                    <?php
                                                    continue;
                                                    ?>

                                                <?php endif; ?>

                                                <?php

                                                $is_summary_row =

                                                    isset(
                                                        $row[
                                                            'is_summary_row'
                                                        ]
                                                    ) &&
                                                    $row[
                                                        'is_summary_row'
                                                    ] === true;

                                                $is_header =
                                                    !empty(
                                                        $row[
                                                            'is_section_header'
                                                        ]
                                                    );

                                                $primary_total =

                                                    $row[
                                                        'primary_total'
                                                    ]
                                                    ??
                                                    [
                                                        'mlfsi' =>
                                                            0,

                                                        'jewelers' =>
                                                            0,

                                                        'total' =>
                                                            0
                                                    ];

                                                // ====================================================
                                                // GRAND TOTAL based on display mode
                                                // ====================================================
                                                if ($display_mode === 'mlfsi') {
                                                    $grand_total = (float)($primary_total['mlfsi'] ?? 0);
                                                } elseif ($display_mode === 'jewelers') {
                                                    $grand_total = (float)($primary_total['jewelers'] ?? 0);
                                                } else {
                                                    $grand_total = (float)($primary_total['total'] ?? 0);
                                                }

                                                // ====================================================
                                                // INJ-2 DISPLAY SIGN
                                                // ====================================================
                                                if (
                                                    !$is_summary_row &&
                                                    !empty(
                                                        $row[
                                                            'is_inj2'
                                                        ]
                                                    )
                                                ) {

                                                    $grand_total =
                                                        -$grand_total;
                                                }

                                                ?>

                                                <!-- ====================================================
                                                     REPORT ROW
                                                ==================================================== -->
                                                <tr
                                                    class="<?= $is_summary_row
                                                        ? 'summary-row'
                                                        : 'data-row' ?>"
                                                    data-sort-order="<?= htmlspecialchars(
                                                        $row[
                                                            'sort_order'
                                                        ] ?? ''
                                                    ) ?>"
                                                    <?php if (
                                                        !$is_summary_row
                                                    ): ?>

                                                        data-is-detail="true"

                                                    <?php endif; ?>
                                                >

                                                    <!-- ====================================================
                                                         SORT ORDER
                                                    ==================================================== -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    >

                                                        <?= $is_summary_row

                                                            ? htmlspecialchars(
                                                                $row[
                                                                    'sort_order'
                                                                ]
                                                            )

                                                            : '' ?>

                                                    </td>

                                                    <!-- ====================================================
                                                         DESCRIPTION
                                                    ==================================================== -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    >

                                                        <?php if (
                                                            $is_header
                                                        ): ?>

                                                            <strong>

                                                                <?= htmlspecialchars(
                                                                    $row[
                                                                        'sub_order'
                                                                    ]
                                                                ) ?>

                                                            </strong>

                                                        <?php elseif (
                                                            $is_summary_row
                                                        ): ?>

                                                            <strong>

                                                                <?= htmlspecialchars(
                                                                    $row[
                                                                        'gl_description'
                                                                    ]
                                                                ) ?>

                                                            </strong>

                                                        <?php endif; ?>

                                                    </td>

                                                    <!-- ====================================================
                                                         GL DESCRIPTION
                                                    ==================================================== -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell summary-description'
                                                            : '' ?>"
                                                    >

                                                        <?= !$is_summary_row

                                                            ? htmlspecialchars(
                                                                $row[
                                                                    'gl_description'
                                                                ]
                                                            )

                                                            : '' ?>

                                                    </td>

                                                    <!-- ====================================================
                                                         BLANK COLUMN
                                                    ==================================================== -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    ></td>

                                                    <!-- ====================================================
                                                         ONE TOTAL COLUMN PER REGION
                                                         
                                                         Region Total based on display mode
                                                    ==================================================== -->
                                                    <?php if (
                                                        $has_regions
                                                    ): ?>

                                                        <?php foreach (
                                                            $regions_in_zone
                                                            as $rn
                                                        ): ?>

                                                            <?php

                                                            $r_amt =

                                                                $row[
                                                                    'region_totals'
                                                                ][$rn]
                                                                ??
                                                                [
                                                                    'mlfsi' =>
                                                                        0,

                                                                    'jewelers' =>
                                                                        0,

                                                                    'total' =>
                                                                        0
                                                                ];

                                                            if ($display_mode === 'mlfsi') {
                                                                $region_total = (float)($r_amt['mlfsi'] ?? 0);
                                                            } elseif ($display_mode === 'jewelers') {
                                                                $region_total = (float)($r_amt['jewelers'] ?? 0);
                                                            } else {
                                                                $region_total = (float)($r_amt['total'] ?? 0);
                                                            }

                                                            // INJ-2 is displayed
                                                            // with reversed sign.
                                                            if (
                                                                !$is_summary_row &&
                                                                !empty(
                                                                    $row[
                                                                        'is_inj2'
                                                                    ]
                                                                )
                                                            ) {

                                                                $region_total =
                                                                    -$region_total;
                                                            }

                                                            ?>

                                                            <td
                                                                class="
                                                                    numeric-cell
                                                                    <?= $is_summary_row
                                                                        ? 'summary-cell'
                                                                        : '' ?>
                                                                "
                                                                style="
                                                                    text-align: right;
                                                                    <?= $region_total < 0
                                                                        ? 'color: red;'
                                                                        : '' ?>
                                                                "
                                                            >

                                                                <?php if (
                                                                    !$is_header
                                                                ): ?>

                                                                    <?php if (
                                                                        $is_summary_row
                                                                    ): ?>

                                                                        <strong>

                                                                            <?= number_format(
                                                                                $region_total,
                                                                                2
                                                                            ) ?>

                                                                        </strong>

                                                                    <?php else: ?>

                                                                        <?= number_format(
                                                                            $region_total,
                                                                            2
                                                                        ) ?>

                                                                    <?php endif; ?>

                                                                <?php endif; ?>

                                                            </td>

                                                        <?php endforeach; ?>

                                                    <?php endif; ?>

                                                    <!-- ====================================================
                                                         GRAND TOTAL
                                                    ==================================================== -->
                                                    <td
                                                        class="
                                                            numeric-cell
                                                            grand-total-cell
                                                            <?= $is_summary_row
                                                                ? 'summary-cell'
                                                                : '' ?>
                                                        "
                                                        style="
                                                            text-align: right;
                                                            <?= $grand_total < 0
                                                                ? 'color: red;'
                                                                : '' ?>
                                                        "
                                                    >

                                                        <?php if (
                                                            !$is_header
                                                        ): ?>

                                                            <?php if (
                                                                $is_summary_row
                                                            ): ?>

                                                                <strong>

                                                                    <?= number_format(
                                                                        $grand_total,
                                                                        2
                                                                    ) ?>

                                                                </strong>

                                                            <?php else: ?>

                                                                <?= number_format(
                                                                    $grand_total,
                                                                    2
                                                                ) ?>

                                                            <?php endif; ?>

                                                        <?php endif; ?>

                                                    </td>

                                                </tr>

                                                <!-- ====================================================
                                                     SPACER AFTER SUMMARY
                                                ==================================================== -->
                                                <?php if (
                                                    $is_summary_row &&
                                                    !$is_header &&
                                                    empty(
                                                        $row[
                                                            'skip_spacer'
                                                        ]
                                                    )
                                                ): ?>

                                                    <tr
                                                        class="spacer-row"
                                                        data-spacer-for="<?= htmlspecialchars(
                                                            $row[
                                                                'sort_order'
                                                            ] ?? ''
                                                        ) ?>"
                                                        style="
                                                            height: 20px;
                                                        "
                                                    >

                                                        <td
                                                            colspan="<?= $total_columns ?>"
                                                        ></td>

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

        <!-- ====================================================
             JAVASCRIPT
        ==================================================== -->
        <script>

            function compareMonths(
                month1,
                month2
            ) {

                if (
                    !month1 ||
                    !month2
                ) {
                    return 0;
                }

                return new Date(
                    month1 + '-01'
                ) -
                new Date(
                    month2 + '-01'
                );
            }

            let activeModal = null;

            function showModal(
                message
            ) {

                if (activeModal) {
                    activeModal.remove();
                }

                const modalOverlay =
                    document.createElement(
                        'div'
                    );

                modalOverlay.className =
                    'modal-overlay';

                modalOverlay.innerHTML = `

                    <div class="modal-container">

                        <div class="modal-header">

                            <h3>

                                <i
                                    class="fa-solid fa-triangle-exclamation"
                                ></i>

                                Validation Error

                            </h3>

                        </div>

                        <div class="modal-body">

                            <p>
                                ${escapeHtml(message)}
                            </p>

                        </div>

                        <div class="modal-footer">

                            <button
                                onclick="closeModal()"
                            >
                                OK
                            </button>

                        </div>

                    </div>
                `;

                document.body.appendChild(
                    modalOverlay
                );

                activeModal =
                    modalOverlay;
            }

            window.closeModal =
                function() {

                    if (activeModal) {

                        activeModal.remove();

                        activeModal = null;
                    }
                };

            function escapeHtml(
                text
            ) {

                const div =
                    document.createElement(
                        'div'
                    );

                div.textContent =
                    text;

                return div.innerHTML;
            }

            function validateForm() {
                return true;
            }

            // ====================================================
            // COLLAPSE / UNCOLLAPSE
            // ====================================================
            document.addEventListener(
                'DOMContentLoaded',
                function() {

                    const collapseBtn =
                        document.getElementById(
                            'collapseBtn'
                        );

                    let isCollapsed =
                        false;

                    // PHP determines the correct
                    // revenue ending sort order.
                    //
                    // OLD = 22
                    // NEW = 23
                    const maxRevenueSortOrder =
                        <?= (int)$ranges['revenue_end'] ?>;

                    if (collapseBtn) {

                        collapseBtn.addEventListener(
                            'click',
                            function() {

                                isCollapsed =
                                    !isCollapsed;

                                const tbodies =
                                    document.querySelectorAll(
                                        '.report-tbody'
                                    );

                                tbodies.forEach(
                                    tbody => {

                                        const rows =
                                            Array.from(
                                                tbody.rows
                                            );

                                        rows.forEach(
                                            row => {

                                                const sortOrder =
                                                    row.getAttribute(
                                                        'data-sort-order'
                                                    );

                                                const isDetail =
                                                    row.getAttribute(
                                                        'data-is-detail'
                                                    ) === 'true';

                                                const spacerFor =
                                                    row.getAttribute(
                                                        'data-spacer-for'
                                                    );

                                                const sortNum =
                                                    parseInt(
                                                        sortOrder,
                                                        10
                                                    );

                                                const isRevenueRange =
                                                    !isNaN(
                                                        sortNum
                                                    ) &&
                                                    sortNum >= 1 &&
                                                    sortNum <=
                                                        maxRevenueSortOrder;

                                                // --------------------------------------------
                                                // Hide detail rows
                                                // --------------------------------------------
                                                if (
                                                    isRevenueRange &&
                                                    isDetail
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                            ? 'none'
                                                            : '';
                                                }

                                                // --------------------------------------------
                                                // Hide spacers
                                                // --------------------------------------------
                                                if (
                                                    spacerFor
                                                ) {

                                                    const spacerNum =
                                                        parseInt(
                                                            spacerFor,
                                                            10
                                                        );

                                                    if (
                                                        !isNaN(
                                                            spacerNum
                                                        ) &&
                                                        spacerNum >= 1 &&
                                                        spacerNum <=
                                                            maxRevenueSortOrder
                                                    ) {

                                                        row.style.display =
                                                            isCollapsed
                                                                ? 'none'
                                                                : '';
                                                    }
                                                }

                                                // --------------------------------------------
                                                // Hide revenues header
                                                // --------------------------------------------
                                                if (
                                                    row.classList.contains(
                                                        'revenues-header-row'
                                                    )
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                            ? 'none'
                                                            : '';
                                                }

                                                // --------------------------------------------
                                                // Hide initial spacer
                                                // --------------------------------------------
                                                if (
                                                    row.classList.contains(
                                                        'initial-spacer'
                                                    )
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                            ? 'none'
                                                            : '';
                                                }

                                            }
                                        );
                                    }
                                );

                                collapseBtn.innerHTML =
                                    isCollapsed

                                        ? '<i class="fa-solid fa-expand"></i> Uncollapse'

                                        : '<i class="fa-solid fa-compress"></i> Collapse';

                                collapseBtn.style.backgroundColor =
                                    isCollapsed

                                        ? '#1f2937'

                                        : '#4b5563';

                            }
                        );
                    }

                }
            );

        </script>

    </div>

</main>

<?php include '../footer.php'; ?>

</body>

</html>

<?php

$conn->close();

?>