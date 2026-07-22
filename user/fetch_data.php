<?php
global $conn;

// Fetch distinct regions
$regions = [];
$regionQuery = mysqli_query($conn, "SELECT DISTINCT region FROM comparative_report ORDER BY region");
while ($row = mysqli_fetch_assoc($regionQuery)) {
    $regions[] = $row['region'];
}

// Fetch distinct areas
// Fetch distinct areas (Filtered by Region if selected)
$areas = [];
$areaWhere = "";
if (!empty($_GET['region'])) {
    $safeRegion = mysqli_real_escape_string($conn, $_GET['region']);
    $areaWhere = "WHERE region = '$safeRegion'";
}

$areaQuery = mysqli_query($conn, "SELECT DISTINCT area FROM comparative_report $areaWhere ORDER BY area");
while ($row = mysqli_fetch_assoc($areaQuery)) {
    $areas[] = $row['area'];
}

// Fetch distinct transaction years (ordered newest first)
$years = [];
$yearQuery = mysqli_query($conn, "SELECT DISTINCT transaction_year FROM comparative_report ORDER BY transaction_year DESC");
while ($row = mysqli_fetch_assoc($yearQuery)) {
    $years[] = $row['transaction_year'];
}

// Fetch and process selected years (multi-select: transaction_year[])
$selected_years = $_GET['transaction_year'] ?? [];
if (!is_array($selected_years)) {
    $selected_years = [];
}
$selected_years = array_unique(array_filter($selected_years)); // clean empty/duplicates

// Enforce max 2 years (server-side)
if (count($selected_years) > 2) {
    $selected_years = array_slice($selected_years, 0, 2);
}

// Sort descending (newest first)
rsort($selected_years);

// Define current (newer) and previous (older) for consistent naming/labels
$current_year       = $selected_years[0] ?? '';
$previous_year      = $selected_years[1] ?? $current_year;
$current_year_label = $current_year;
$previous_year_label = $previous_year;

/* -----------------------------
   FILTER INPUTS (region & area)
------------------------------ */
$selected_region = $_GET['region'] ?? '';
$selected_areas  = $_GET['area'] ?? [];
if (!is_array($selected_areas)) {
    $selected_areas = [];
}
$selected_areas = array_unique(array_filter($selected_areas));

/* -----------------------------
   VALIDATE SELECTED AREAS AGAINST SELECTED REGION
------------------------------ */
if (!empty($selected_region) && !empty($selected_areas)) {
    // Intersect the user's selection with only the areas that actually exist in that region
    $selected_areas = array_intersect($selected_areas, $areas);
}


/* -----------------------------
   CHECK IF ANY FILTER IS APPLIED
------------------------------ */
$filters_applied = !empty($selected_region) || !empty($selected_areas) || !empty($selected_years);

/* -----------------------------
   MULTI-AREA LOGIC
------------------------------ */
// We only group by area when an area filter is applied (single or multiple areas selected)
// When no area filter → aggregate everything into one group (original behaviour)
// When 1 or more areas selected → show separate columns for each selected area
$has_area_filter = !empty($selected_areas);
$multi_area      = $has_area_filter && count($selected_areas) > 1;

$display_areas = $has_area_filter ? $selected_areas : [];
if ($has_area_filter) {
    sort($display_areas); // consistent ordering
}

// Areas that will actually be displayed/looped in the table
$areas_to_display = $has_area_filter ? $display_areas : ['_all'];


/* -----------------------------
   GL CODE CONFIG (unchanged)
------------------------------ */
$glMap = [
    /* money lending */
    'total_interest_income' => ['4000001','4000002','4000003','4000004','4000005','4000006','4000007','4000008','40000009','40000011','4000012','4000013','4010001','4010002','4010003','4010004','4030001','4030002','4030003','4030012','4020001','4020002','4020003','4020004','4020005'],
    'service_charge' => ['4500001'],
    'liquidated_damages' => ['4500002','4040001'],
    'gain_loss' => ['4500003'],
    'storage_fee' => ['4500004', '4040003'],
    'appraisal' => ['4500005'],
    'counter' => ['4500006'],

    /* vehicle loans */
    'motor_loan' => [''],
    'motor_loan_ho' => [''],
    'interest_income_mlautoloan' => ['4000009'],
    'car_loan_ho' => [''],
    'appli_fee_mlautoloan' => ['4040009'],
    'apprai_fee_mlautoloan' => ['4040010'],
    'pen_and_other_charges_mlautoloan' => ['4040012'],
    'chattel_mort_income_mlautoloan' => ['4040014'],
    'notarial_income' => ['4040016'],

    /* home loans */
    'interest_income_mlhomeloan' => ['4000014'],
    'real_property_ho' => [''],
    'apprai_fee_mlhomeloan' => ['4040011'],
    'pen_and_other_charges_mlhomeloan' => ['4040013'],
    'chattel_mort_income_mlhomeloan' => ['4040015'],
    'notarial_income_mlhomeloan' => ['4040017'],

    /* commercial loans */
    'interest_income_ste_sbl' => ['4000010'],
    'sbl' => ['4230345'],
    'penalty_fee_mlsbl' => ['4040007','4040008'],
    'interest_income_ml_pen_loan' => ['4000011'],
    'service_fees_ml_pen_loan' => ['4040006'],

    /* kwarta padala income */
    'kp_regular' => ['4210001'],
    'kp_to_go' => ['4200005'],
    'kp_other_income' => ['4200001'],
    'kp_cert_fee' => ['4200004'],
    'kiosk_mt' => ['4200007'],
    'kp_sendout_spec_acc_ho' => [''],

    /* payment solution */
    'payment_solution' => ['4220271','4220272','4220273','4220258','4220276','4220264','4220266','4220268','4220269','4220270','4220281','4220282','4220284','4220285','4220286','4220287','4220288','4220289','4220295'],

    /* domestic partner income */
    'express_pay' => [''],
    'bpi_to_cash' => [''],
    'lbc' => ['4220260'],
    'rural_net' => ['4220048'],
    'prime_bread' => ['4220261'],
    'starpay' => ['4220251'],
    'gcash_comm' => ['4400012'],
    'domestic_partner_ho' => [''],

    /* pos comm */
    'pos_comm' => ['4220131', '4220132', '4220312', '4220045'],

    /* mcash income */
    'mcash_op' => ['4200006'],
    'mcash_ho' => [''],

    /* ml express */
    'ml_express_op' => ['4200008'],
    'ml_express_ho' => [''],

    /* epay */
    'epay' => ['4400015'],

    /* corporate partner */
    'total_corp_partners' => ['4220051','4220054','4220066','4220229','4200003','4220119'],
    'corp_comm_ho' => [''],

    /* billspayment 4200002*/
    'total_billspayment' => ['4200002','4220087','4230002','4230001','4230003','4230004','4230005','4230006','4230006','4230007','4230009','4230010','4230011','4230012','4230013','4230014','4230015','4230018','4230020','4230024','4230025','4230032','4230035','4230028','4230030','4230031','4230037','4230034','4230038','4230039','4230040','4230041','4230043','4230047','4230048','4230049','4230134','4230052','4230057','4230058','4230059','4230064','4230065','4230067','4230068','4230066','4230069','4230070','4230075','4230078','4230079','4230080','4230081','4230082','4230085','4230087','4230083','4230088','4230105','4230113','4230114','4230115','4230117','4230119','4230121','4230122','4230125','4230130','4230131','4230133','4230139','4230141','4230143','4230144','4230145','4230147','4230149','4230150','4230151','4230154','4230158','4230159','4230163','4230164','4230161','4230170','4230171','4230176','4230178','4230180','4230181','4230183','4230091','4230093','4230094','4230095','4230097','4230098','4230099','4230100','4230102','4230104','4230107','4230188','4230189','4230200','4230203','4230205','4230213','4230206','4230219','4230221','4230222','4230224','4230229','4230235','4230234','4230243','4230244','4230245','4230248','4230254','4230255','4230262','4230271','4230278','4230279','4230289','4230292','4230294','4230303','4230306','4230312','4230328','4230329','4230332','4230335','4230411','4230492','4230499','4230521','4230529','4230530','4230535','4230536','4230537','4230539','4230541','4230542','4230544','4230546','4230547','4230549','4230553','4230554','4230557','4230558','4230560','4230561','4230567','4230568','4230578','4230572','4230580','4230581', '4230582','4230586','4230591','4230593','4230594','4230595','4230597','4230599','4230600','4230602','4230607','4230609','4230610','4230612','4230614','4230615', '4230616','4230617','4230618','4230624','4230630','4230635','4230638','4230620','4230588','4230639','4230641','4230653','4230643','4230644','4230646','4230647','4230670','4230672','4230676','4230678','4230160','4230349','4230380','4230550','4230673','4500010','4230270','4230682','4230660','4230662','4230664','4230655','4230656','4230702','4230703','4230712','4230718','4220190','4230146','4230237','4230562','4230448','4300001','4230336','4230338','4230340','4230346','4230348','4230352','4230356','4230357','4230358','4230359','4230360','4230361','4230362','4230363','4230364','4230365','4230366','4230367','4230369','4230375','4230377','4230379','4230381','4230383','4230384','4230385','4230386','4230387','4230388','4230389','4230390','4230392','4230395','4230396','4230397','4230399','4230400','4230401','4230402','4230403','4230404','4230405','4230406','4230408','4230415','4230416','4230422','4230428','4230430','4230433','4230434','4230454','4230457','4230458','4230459','4230460','4230461','4230462','4230464','4230465','4230466','4230468','4230469','4230473','4230474','4230476','4230135','4230482','4230484','4230485','4230486','4230489','4230490','4230491','4400001'],
    'billspayment_comm_ho' => [''],

    /* insurance commission */
    'comm_mli_pawner_protect' => ['4600001'],
    'comm_mli_pplus' => ['4600002'],
    'comm_mli_ofw' => ['4600006'],
    'comm_mli_fam' => ['4600008'],
    'comm_mli_kp' => ['4600009'],
    'comm_mli_pinoy' => ['4600010'],
    'comm_mli_fam_plus' => ['4600011'],
    'comm_maa_pp' => ['4600012'],
    'comm_maa_fp' => ['4600013'],
    'comm_maa_ppp' => ['4600014'],
    'comm_maa_pppf' => ['4600015'],
    'comm_maa_fpp' => ['4600016'],
    'comm_maa_fppt' => ['4600017'],
    'comm_maa_kpp' => ['4600018'],
    'comm_para_pp' => ['4600019'],
    'comm_para_fp' => ['4600020'],
    'comm_para_ppp' => ['4600021'],
    'comm_para_fpp' => ['4600022'],
    'comm_para_kpp' => ['4600023'],
    'comm_mala_pp' => ['4600024'],
    'comm_mala_fp' => ['4600025'],
    'comm_mala_ppp' => ['4600026'],
    'comm_mala_fpp' => ['4600027'],
    'comm_mala_kpp' => ['4600028'],
    'comm_deng_150' => ['4600029'],
    'comm_deng_500' => ['4600030'],
    'comm_ctpl' => ['4600031'],
    'comm_gttp_local' => ['4600032'],
    'comm_gttp_intl' => ['4600033'],
    'comm_ofw' => ['4600034'],
    'comm_compre_insu' => ['4600035'],
    'comm_er_guard' => ['4600036'],
    'comm_er_guard_plus' => ['4600037'],
    'comm_mediphone' => ['4600038'],
    'comm_maa_pp5' => ['4600039'],
    'comm_maa_fp10' => ['4600040'],
    'comm_mala_pp5' => ['4600043'],
    'comm_mala_fp10' => ['4600044'],
    'ml_gen_insu_comm' => ['4600045'],
    'maa_cust_protect_20' => ['4600046'],
    'mico_cust_protect_40' => ['4600047'],
    'okdok_quarterly' => ['4600049'],
    'okdok_annual' => ['4600050'],
    'moskibite' => ['4600051'],
    'okdok_monthly' => ['4600052'],
    'ctpl_insu' => ['4400017'],
    'phillife' => ['4400018'],
    'philam_life' => [''],
    'group_personal_accident_ho' => [''],

    /* income from jewelry */
    'sales_jewelry' => ['4100001','4100002','4110001','4110002'],
    'less_sales_return_discount' => ['4180002','4180001'],

    /* Income from Special Products */
    'dried_fruits' => ['4140001', '4140002','4140010','4140011','4140012','4141001','4141002','4141003','4141004','4141005','4141007','4141008','4141009'],
    'other_spec_prod' => [ '4120001','4120002','4120003','4120004','4120005','4120006','4120007','4120008',
    '4120009','4120010','4120011','4120012','4120013','4120014','4120015','4120016',
    '4120017','4120018','4130001','4140003','4140013','4142312','4143001','4143003',
    '4144003','4144004','4150001','4150002','4150003','4150004','4150005','4150006',
    '4170001'],

    /* income from telecom */
    'telecom' => ['4140004','4140005','4140006','4140007','4140008','4140009','4142001','4142002','4142003','4142004','4142005','4142101','4142102','4142103','4142201','4142104','4142202','4142301','4142302','4142303','4142304','4142306','4142308','4510005','4142311','4142310','4400013'],
    'discount_on_purchase_of_telecom_ho' => [''],

    /* income from other services */
    'travel_and_tours' => ['4510001','4510002','4510003','4510004','4510018','4510006','4510007','4510008','4510009','4510010','4510012','4510013','4510014','4510011','4510019'],
    'travellers_comm_ho' => [''],
    'nso' => [''],

    /* income from ml kargo padala */
    'ml_kargo_padala' => ['4190001','4400016','4510017','4510016','4510021','4510022','4191001'],
    'ml_kargo_padala_comm_ho' => [''],

    /* other income */
    'gain_loss_mcfx' => ['4500012'],
    'forex_from_corp' => ['4500017'],
    'dollars_sold_ho' => [''],
    'interest_in_bank' => ['4500007'],
    'pldt_dividend' => ['4500008'],
    'rental_income' => ['4500009'],
    'rental_income_ho' => [''],
    'other_income' => ['4500011'],
    'awards_prizes_ho' => [''],
    'towing_fee' => ['4500016'],
    'credit_surcharge' => ['4170100'],
    'gain_loss_sales_acc' => ['4500101'],
    'scrap_gold_bar_ho' => [''],
    'stpeter_life_plan_ho' => [''],
    'maa_insu_robbery' => [''],
    'maa_insu_comm_ho' => [''],
    'mmd_sales_ho' => [''],
    'richmedia_ho' => [''],
    'lavie_ho' => [''],
    'ml_express_francise_ho' => [''],
    'interest_monique' => [''],
    'workbench' => [''],
    'mlshop_jewelry' => [''],
    'mlshop_opi' => [''],

    /* cost of sales/service */
    'jewelry' => ['5000001','5000007','5010001','5010001',],
    'special_prod' => ['5000003','5000009','5000002','5000004','5000005','5000006','5000010','5000012','5000011','5000017','5000018','5000019','5000026','5000027','5000028','5000032',
    '5000033','5011017','5011012','5011015','5011016','5011001','5011008','5011011','5011006','5011007','5011003','5011010','5011002','5011005','5011014','5011009','5011013', '5020001','5020002','5020003','5020004','5020005','5020006','5020007','5020008','5020009', '5050004'],
    'telecommunication' => ['5000020','5000021','5000022','5000023','5000024','5000025','5000029','5000030','5000031','5030001','5030002','5030003','5030004','5031001','5031002','5031005','5031006','5031008','5031009','5032001','5032002','5032003','5032004','5033001',
    '5036001','5036002','5034001','5035001']
,
    'ml_kargo' => ['5191001'],
    
    /* total personal expenses */
    'salaries_wages' => ['5100001','5100002','5100003'],
    'staff_benefits' => ['5210001','5211001','5212001','5213001','5214001','5215001','5215002','5220001',
    '5220002','5220003','5230001','5230002','5240001','5250001','5260001','5270001',
    '5290001'],
    'sss_ec' => ['5410001'],
    'philhealth' => ['5420001'],
    'pagibig_expense' => ['5430001'],
    'all_bonus' => ['5280001'],
    '13th_month' => ['5280002'],

    /* total administrative expenses */
    'management_prof' => ['5300001','5300002','5300003', '5300004'],
    'taxes_licenses' => ['5310001','5310002','5310003','5310004','5310005','5310006','5310007'],
    'utilities' => ['5320001', '5320002'],
    'communication_expenses' => ['5330001','5330002','5330003'],
    'stationaries' => ['5340001'],
    'repair' => ['5350001'],
    'rent_expense' => ['5360001'],
    'sec_messenger' => ['5370001','5370002','5370003','5370004','5370005','5370006','5370007'],
    'transportation_expense' => ['5380001'],
    'delivery' => ['5390001'],
    'travelling_expense' => ['5400001'],
    'fuel_oil' => ['5440001'],
    'other_charges' => ['5450001','5450002','5900002','5450003','5450004'],
    'advertising' => ['5460001','5460002','5460003','5460004','5460005'],
    'rep_entertainment' => ['5470001'],
    'store' => ['5480001','5480002'],
    'finders_fee' => ['5510001'],
    'insu_incentive' => ['5530001','5530002','5530003'],
    'insu_expense' => [''],
    'miscellaneous' => ['5560001'],
    'software' => ['5570001', '5570002'],
    'loss_robbery' => ['5520001'],
    'ho_expense' => ['6000001'],
    'bad_debts_ho' => [''],
    'agent_share_ho' => [''],

    /* dep amor */
    'depreciation' => ['5540001','5540002','5540003','5540004','5540005','5540006'],
    'amortization' => ['5550001','5550002','5550003','5550004'],

    /* interest expense */
    'interest_expense' => ['5490001'],

    /* provision */
    'provision' => [''],
];

// Initialize totals array
$totals = [];

// Only execute query if filters are applied
if ($filters_applied) {
    /* -----------------------------
       BUILD COMMON WHERE
    ------------------------------ */
    $where = [];

    if (!empty($selected_region)) {
        $where[] = "region = '" . mysqli_real_escape_string($conn, $selected_region) . "'";
    }

    if (!empty($selected_areas)) {
        $areaFilter = implode("','", array_map(fn($a) => mysqli_real_escape_string($conn, $a), $selected_areas));
        $where[] = "area IN ('$areaFilter')";
    }

    if (!empty($selected_years)) {
        $yearList = implode("','", array_map(fn($y) => mysqli_real_escape_string($conn, $y), $selected_years));
        $where[] = "transaction_year IN ('$yearList')";
    }

    $whereSql = !empty($where) ? implode(' AND ', $where) : '1=1';

    /* -----------------------------
       VALID GL CODES
    ------------------------------ */
    $validGlCodes = [];
    foreach ($glMap as $codes) {
        $filtered = array_filter($codes, fn($c) => trim($c) !== '');
        $validGlCodes = array_merge($validGlCodes, $filtered);
    }
    $validGlCodes = array_unique($validGlCodes);
    $glList = !empty($validGlCodes) ? "'" . implode("','", $validGlCodes) . "'" : "''";

    /* -----------------------------
       AREA & BRANCH TYPE GROUPING
    ------------------------------ */
    $select_area = $has_area_filter ? "area," : "";
    $group_area  = $has_area_filter ? "area," : "";

    $select_type = "CASE WHEN transaction_type = 'Showroom' THEN 'jewelers' ELSE 'mlfsi' END AS branch_type,";
    $group_type  = "branch_type,";

    $sql = "
        SELECT 
            $select_area
            $select_type
            gl_code,
            transaction_year,
            SUM(amount) AS total
        FROM comparative_report
        WHERE gl_code IN ($glList)
          AND $whereSql
        GROUP BY $group_area $group_type gl_code, transaction_year
    ";

    $result = mysqli_query($conn, $sql);

    /* -----------------------------
       NORMALIZE RESULT
    ------------------------------ */
    $raw = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $area_key    = $has_area_filter ? $row['area'] : '_all';
        $branch_type = $row['branch_type'];
        $raw[$area_key][$branch_type][$row['gl_code']][$row['transaction_year']] = (float)$row['total'];
    }

    /* -----------------------------
       AGGREGATE PER CATEGORY
    ------------------------------ */
    foreach ($glMap as $key => $codes) {
        $codes = array_filter($codes, fn($c) => trim($c) !== '');
        if (empty($codes)) continue;

        foreach ($areas_to_display as $area_key) {
            foreach (['mlfsi', 'jewelers'] as $branch_type) {
                foreach ($selected_years as $year) {
                    $totals[$area_key][$branch_type][$key][$year] = 0;
                    foreach ($codes as $code) {
                        $totals[$area_key][$branch_type][$key][$year] += $raw[$area_key][$branch_type][$code][$year] ?? 0;
                    }
                }
            }
        }
    }

    /* -----------------------------
       EXPOSE OLD-STYLE VARIABLES (only when no area filter - backward compatibility)
    ------------------------------ */
    if (!$has_area_filter) {
        $categories = [ /* your full list of categories - same as before */ ];

        foreach ($categories as $cat) {
            ${"total_current_$cat"}  = $totals['_all'][$cat][$current_year] ?? 0;
            ${"total_previous_$cat"} = $totals['_all'][$cat][$previous_year] ?? 0;
        }
    }
    // Note: When areas are selected (single or multiple), the old $total_current_... variables are NOT set.
    // The table must use the new $totals[$area_key][$category][$year] structure instead.
}

?>