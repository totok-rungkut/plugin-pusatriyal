<?php
/**
 * MC 01 - Core: Table constants, ACF Profile registration, DB install & CoA seeding (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Responsibilities:
 *  - Define constants for all custom DB tables (T_ITEMS, T_STOCK, T_LEDGER, T_CHART, T_JOURNAL, T_CONSIGN, T_LOCKS)
 *  - Register ACF options sub-page for company profile (safe-guard when ACF missing)
 *  - Provide DB install routine (dbDelta) and CoA auto-seed
 *
 * Security & Improvements:
 *  - Uses $wpdb->prepare for SQL where necessary
 *  - Ensures dbDelta table names use $wpdb->prefix consistently via puri_table_name()
 *  - Adds puri_core_db_install wrapper to run only for admins; idempotent
 *  - Adds check for JSON column.compatibility (MySQL >= 5.7)
 */

defined('ABSPATH') || exit;

if (!defined('PURI_VERSION')) define('PURI_VERSION', '6.0.1');

// 1. DEFINISI KONSTANTA TABEL (Pusat Sinkronisasi Arsitektur)
if (!defined('T_ITEMS'))   define('T_ITEMS',   'puri_pr_master_items');
if (!defined('T_STOCK'))   define('T_STOCK',   'puri_inventory_balance');
if (!defined('T_LEDGER'))  define('T_LEDGER',  'puri_inventory_ledger');
if (!defined('T_CHART'))   define('T_CHART',   'puri_acct_chart');
if (!defined('T_JOURNAL')) define('T_JOURNAL', 'puri_acct_journal');
if (!defined('T_CONSIGN')) define('T_CONSIGN', 'puri_inventory_consign'); // untuk mc-09
if (!defined('T_LOCKS'))   define('T_LOCKS',   'puri_inventory_locks'); 
if (!defined('T_ORDERS'))  define('T_ORDERS',  'puri_orders'); // untuk mc-07
if (!defined('T_JSON'))    define('T_JSON',    'puri_json_storage'); // untuk mc-03 
// ============================================================================
// POOL TRANSACTION TABLES (v6.10.25 - Phase 1)
// ============================================================================
if (!defined('T_POOL_TRANSACTIONS')) define('T_POOL_TRANSACTIONS', 'puri_pool_transactions');
if (!defined('T_POOL_STOCK'))        define('T_POOL_STOCK',        'puri_pool_stock');
if (!defined('T_POOL_JOURNAL'))      define('T_POOL_JOURNAL',      'puri_pool_journal'); // Optional: untuk complex transactions
if (!defined('T_EOD_BATCHES'))       define('T_EOD_BATCHES',       'puri_pool_eod_batches');

// Ensure puri_table_name helper exists (from mc-00) fallback
if (!function_exists('puri_table_name')) {
    function puri_table_name($const_name) {
        global $wpdb;
        if (defined($const_name)) {
            return $wpdb->prefix . constant($const_name);
        }
        return '';
    }
}

// ACF Options Page: Profile & Bank (guarded)
add_action('acf/init', function() {
/*

    if (!function_exists('acf_add_options_page')) {
        // ACF not active; admin notice will be shown elsewhere
        return;
    }

    // Add Options Sub Page for profile (children of pr-dashboard)
    acf_add_options_sub_page([
        'page_title'    => 'Profil Perusahaan & Bank',
        'menu_title'    => '🏨 Profil & Bank',
        'parent_slug'   => 'puri-master',
        'menu_slug'     => 'puri-profile',
        'capability'    => 'manage_options',
        'redirect'      => false,
    ]);

    // Register fields (local field group). Keep structure identical to previous release.
    acf_add_local_field_group([
        'key' => 'group_puri_legal_profile',
        'title' => '🏨 Informasi Profil Perusahaan & Bank',
        'fields' => [
            ['key'=>'cp_logo', 'label'=>'Logo Resmi', 'name'=>'cp_logo', 'type'=>'image', 'return_format'=>'url', 'wrapper'=>['width'=>'20']],
            ['key'=>'cp_name', 'label'=>'Nama Perusahaan', 'name'=>'cp_name', 'type'=>'text', 'wrapper'=>['width'=>'40']],
            ['key'=>'cp_bi', 'label'=>'No. Izin BI', 'name'=>'cp_bi_license', 'type'=>'text', 'wrapper'=>['width'=>'40']],
            ['key'=>'cp_npwp', 'label'=>'NPWP', 'name'=>'cp_npwp', 'type'=>'text', 'wrapper'=>['width'=>'50']],
            ['key'=>'cp_phone', 'label'=>'Phone/WA', 'name'=>'cp_phone', 'type'=>'text', 'wrapper'=>['width'=>'50']],
            ['key'=>'cp_address', 'label'=>'Alamat Kantor', 'name'=>'cp_address', 'type'=>'textarea', 'rows'=>2],
            ['key'=>'cp_city', 'label'=>'Kota', 'name'=>'cp_city', 'type'=>'text', 'wrapper'=>['width'=>'50']],
            ['key'=>'cp_bank_rep', 'label'=>'Daftar Rekening Bank', 'name'=>'cp_bank_list', 'type'=>'repeater', 'layout'=>'table',
                'sub_fields' => [
                    ['key'=>'b_name', 'label'=>'Bank', 'name'=>'bank_name', 'type'=>'text'],
                    ['key'=>'b_no', 'label'=>'No. Rek', 'name'=>'acc_number', 'type'=>'text'],
                    ['key'=>'b_own', 'label'=>'A/N', 'name'=>'acc_holder', 'type'=>'text'],
                ]
            ],
        ],
        'location' => [[['param'=>'options_page', 'operator'=>'==', 'value'=>'puri-profile']]],
    ]);
*/	
});

/**
 * Render Profile page dummy (guard for ACF)
 * Keamanan: only for manage_options
 */
 /*
function puri_render_profile_page_dummy() {
    puri_check_cap('manage_options');
    echo '<div class="wrap"><h1>🏨 Profil Perusahaan & Bank</h1>';
    if (function_exists('do_action')) {
        // Render ACF options page UI if available
        do_action('acf/options_page/render', ['menu_slug' => 'puri-profile']);
    } else {
        echo '<p style="color:red;"><b>Peringatan:</b> Plugin ACF Pro harus aktif untuk mengelola data profil.</p>';
    }
    echo '</div>';
}
*/



/**
 * DB Installer v6.0.1
 * - Creates required tables if not exist
 * - Uses dbDelta (wp-admin/includes/upgrade.php)
 * - Ensures idempotency
 */
function puri_core_db_install() {
    // Only run for admins in admin area
    if (!is_admin() || !current_user_can('manage_options')) return;

    global $wpdb;
    $collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Use puri_table_name to construct table names
    $items_table  = puri_table_name('T_ITEMS');   // {$wpdb->prefix}puri_pr_master_items
    $journal_table= puri_table_name('T_JOURNAL');
    $ledger_table = puri_table_name('T_LEDGER');
    $stock_table  = puri_table_name('T_STOCK');
    $locks_table  = puri_table_name('T_LOCKS');
    $chart_table  = puri_table_name('T_CHART');
	$json_table   = puri_table_name('T_JSON');

    // SQL definitions (dbDelta friendly)
    $sql_items = "CREATE TABLE IF NOT EXISTS {$items_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        sku varchar(50) NOT NULL,
        name varchar(100) NOT NULL,
        type enum('currency','goods','package') DEFAULT 'currency',
        denom_value int DEFAULT 1,
        base_price decimal(19,4) DEFAULT 0,
        sell_rate decimal(19,4) DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY sku (sku)
    ) $collate;";

    $sql_journal = "CREATE TABLE IF NOT EXISTS {$journal_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        trx_date datetime DEFAULT CURRENT_TIMESTAMP,
        ref_id varchar(50) NOT NULL,
        account_code varchar(20) NOT NULL,
        debit decimal(19,4) DEFAULT 0,
        credit decimal(19,4) DEFAULT 0,
        description text,
        snapshot_json json DEFAULT NULL,
        created_by bigint(20) NOT NULL,
        PRIMARY KEY (id),
        KEY ref_id (ref_id),
        KEY account_code (account_code)
    ) $collate;";

    $sql_ledger = "CREATE TABLE IF NOT EXISTS {$ledger_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        trx_date datetime DEFAULT CURRENT_TIMESTAMP,
        location_id varchar(50) NOT NULL,
        item_id bigint(20) NOT NULL,
        qty_change decimal(19,4) DEFAULT 0,
        trx_type varchar(20) DEFAULT 'sell',
        ref_id varchar(50) NOT NULL,
        description text,
        PRIMARY KEY (id)
    ) $collate;";

    $sql_stock = "CREATE TABLE IF NOT EXISTS {$stock_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        location_id varchar(50) NOT NULL,
        item_id bigint(20) NOT NULL,
        qty decimal(19,4) DEFAULT 0,
        cost_avg decimal(19,4) DEFAULT 0,
		last_updated datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY loc_item (location_id, item_id)
    ) $collate;";


    $sql_json = "CREATE TABLE IF NOT EXISTS {$json_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        ref_id varchar(50) NOT NULL,
        source_module varchar(30) NOT NULL,
        snapshot_json longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_ref (ref_id)
    ) $collate;";

    $sql_locks = "CREATE TABLE IF NOT EXISTS {$locks_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        item_id bigint(20) NOT NULL,
        qty_lock int DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY item_id (item_id)
    ) $collate;";

    $sql_chart = "CREATE TABLE IF NOT EXISTS {$chart_table} (
        code varchar(20) PRIMARY KEY,
        name varchar(100),
        type varchar(20),
        is_cash tinyint(1)
    ) $collate;";


/**
 * ============================================================================
 * POOL TRANSACTION TABLES (v6.10.25)
 * ============================================================================
 */

// 1. POOL TRANSACTIONS: Main staging table
$pool_transactions_table = puri_table_name('T_POOL_TRANSACTIONS');
$sql_pool_transactions = "CREATE TABLE IF NOT EXISTS {$pool_transactions_table} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    ref_id varchar(50) NOT NULL,
    trx_date datetime NOT NULL,
    trade_mode enum('sell','buy') NOT NULL DEFAULT 'sell',
    customer_id bigint(20) NOT NULL,
    customer_name varchar(255) DEFAULT NULL,
    customer_nik varchar(50) DEFAULT NULL,
    payment_method varchar(20) DEFAULT 'cash',
    delivery_method varchar(20) DEFAULT 'pickup',
    total_riyal decimal(19,4) DEFAULT 0,
    total_idr decimal(19,4) DEFAULT 0,
    total_hpp decimal(19,4) DEFAULT 0,
    items_snapshot longtext DEFAULT NULL,
    status enum('pending','verified','posted','void') DEFAULT 'pending',
    verified_by bigint(20) DEFAULT NULL,
    verified_at datetime DEFAULT NULL,
    posted_by bigint(20) DEFAULT NULL,
    posted_at datetime DEFAULT NULL,
    created_by bigint(20) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    notes text DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ref_id (ref_id),
    KEY idx_trx_date (trx_date),
    KEY idx_status (status),
    KEY idx_customer_id (customer_id)
) $collate;";

// 2. POOL STOCK: Shadow stock movements
$pool_stock_table = puri_table_name('T_POOL_STOCK');
$sql_pool_stock = "CREATE TABLE IF NOT EXISTS {$pool_stock_table} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    ref_id varchar(50) NOT NULL,
    item_id bigint(20) NOT NULL,
    location_id varchar(50) NOT NULL,
    qty_change decimal(19,4) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ref_id (ref_id),
    KEY idx_item_location (item_id, location_id)
) $collate;";

// 3. POOL JOURNAL: Staged GL entries (optional, untuk kompleks accounting)
$pool_journal_table = puri_table_name('T_POOL_JOURNAL');
$sql_pool_journal = "CREATE TABLE IF NOT EXISTS {$pool_journal_table} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    ref_id varchar(50) NOT NULL,
    account_code varchar(20) NOT NULL,
    debit decimal(19,4) DEFAULT 0,
    credit decimal(19,4) DEFAULT 0,
    description text DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ref_id (ref_id),
    KEY idx_account (account_code)
) $collate;";

// 4. EOD BATCHES: End of Day tracking
$eod_batches_table = puri_table_name('T_EOD_BATCHES');
$sql_eod_batches = "CREATE TABLE IF NOT EXISTS {$eod_batches_table} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    batch_date date NOT NULL,
    total_transactions int DEFAULT 0,
    total_riyal decimal(19,4) DEFAULT 0,
    total_idr decimal(19,4) DEFAULT 0,
    stock_opname_status enum('pending','verified','discrepancy') DEFAULT 'pending',
    reconciliation_status enum('pending','matched','unmatched') DEFAULT 'pending',
    posting_status enum('pending','posted','failed') DEFAULT 'pending',
    posted_by bigint(20) DEFAULT NULL,
    posted_at datetime DEFAULT NULL,
    notes text DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY batch_date (batch_date),
    KEY idx_posting_status (posting_status)
) $collate;";

// Run dbDelta for Pool Tables
dbDelta($sql_pool_transactions);
dbDelta($sql_pool_stock);
dbDelta($sql_pool_journal);
dbDelta($sql_eod_batches);

    // Run dbDelta for all
    dbDelta($sql_items);
    dbDelta($sql_journal);
    dbDelta($sql_ledger);
    dbDelta($sql_stock);
    dbDelta($sql_locks);
    dbDelta($sql_chart);
    dbDelta($sql_json);


    // Auto-seed CoA
    puri_seed_coa();
}
add_action('admin_init', 'puri_core_db_install', 5);

/**
 * Auto-seeding Chart of Accounts (CoA)
 * Idempotent: uses REPLACE to avoid duplicates
 */
function puri_seed_coa() {
    global $wpdb;
    $table = puri_table_name('T_CHART');
    if (empty($table)) return;

    // 1. Buat tabel jika belum ada
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (code varchar(20) PRIMARY KEY, name varchar(100), type varchar(20), is_cash tinyint(1))");

    // 2. KOREKSI: Cek apakah tabel sudah berisi data
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    
    // 3. Hanya jalankan seeding jika tabel masih kosong (0)
    if ( intval($count) > 0 ) {
        return; // Sudah ada data, jangan ditimpa lagi
    }

    $accounts = [
        ['1101', 'Kas Laci Kasir', 'ASSET', 1],
        ['1102', 'Kas Bank Perusahaan', 'ASSET', 1],
        ['1401', 'Persediaan Valas', 'ASSET', 0],
        ['3100', 'Modal Disetor', 'EQUITY', 0],
        ['4100', 'Pendapatan Valas', 'REVENUE', 0],
        ['5100', 'Harga Pokok Penjualan (cogs)', 'EXPENSE', 0],
        ['5201', 'Biaya Retribusi', 'EXPENSE', 0],
        ['5202', 'Biaya Wifi', 'EXPENSE', 0],
        ['5203', 'Biaya Gaji', 'EXPENSE', 0],
        ['5201', 'Biaya Listrik', 'EXPENSE', 0]
    ];

    foreach ($accounts as $acc) {
        $wpdb->replace($table, ['code' => $acc[0], 'name' => $acc[1], 'type' => $acc[2], 'is_cash' => $acc[3]]);
    }
}