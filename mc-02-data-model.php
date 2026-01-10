<?php
/**
 * MC 02 - Data Model: Master SKU & Customer KYC (Professional Refactor)
 * Version: 6.4.0
 * * * INTEGRITY GUARD:
 * - Mengembalikan sanitasi data yang hilang di v6.3.0 (Denom, Sell Rate, Package).
 * - Menambahkan Image Placeholder (Visual Instructions) di bagian Entry.
 * - Memisahkan kolom admin menggunakan hook spesifik agar tidak tabrakan.
 */

defined('ABSPATH') || exit;

// ==========================================================
// 1. ADMIN LIST COLUMNS (ITEM & CUSTOMER)
// ==========================================================

// --- Kolom Master SKU (Item) ---
add_filter('manage_pr_item_posts_columns', function($columns) {
    return [
        'cb'        => $columns['cb'],
        'sku_code'  => 'Kode SKU',
        'title'     => 'Nama Item', // Menjaga link edit utama
        'item_type' => 'Tipe',
        'denom'     => 'Denom',
        'stock'     => 'Stok',
        'price'     => 'Harga / HPP',
    ];
});

// --- Kolom Master Customer (KYC) ---
add_filter('manage_pr_customer_posts_columns', function($columns) {
    return [
        'cb'         => $columns['cb'],
        'title'      => 'Nama Customer',
        'cust_id'    => 'ID / Identitas',
        'cust_phone' => 'No. WhatsApp',
        'cust_kyc'   => 'Status Dokumen',
    ];
});

// Render Kolom Item
add_action('manage_pr_item_posts_custom_column', function($column, $post_id) {
    global $wpdb;
    switch ($column) {
        case 'sku_code': 
            echo '<code>'.esc_html(get_post_meta($post_id, 'item_sku_code', true) ?: '-').'</code>'; 
            break;
        case 'item_type': 
            $t = get_field('type', $post_id);
            $labels = ['currency'=>'Valas','goods'=>'Retail','package'=>'Paket'];
            echo "<span class='puri-badge ".($t=='currency'?'blue':'gray')."'>".strtoupper($labels[$t]??$t)."</span>";
            break;
        case 'denom':
            $d = get_field('denom_value', $post_id);
            echo ($d > 0) ? "<strong>".number_format($d)."</strong>" : "-";
            break;
        case 'stock': 
            $qty = $wpdb->get_var($wpdb->prepare("SELECT qty_balance FROM ".puri_table_name('T_STOCK')." WHERE item_id = %d", $post_id));
            echo "<strong>".number_format($qty?:0)."</strong>";
            break;
case 'price':
            // ANTI-CRASH LOGIC: Paksa ke float agar number_format tidak fatal error
            $jual = floatval(get_field('sell_rate', $post_id) ?: 0);
            $hpp  = floatval(get_post_meta($post_id, 'base_price', true) ?: 0);
            echo "<div style='line-height:1.2'><small>Jual: </small><span style='color:#2563eb;font-weight:600;'>".number_format($jual)."</span><br>";
            echo "<small style='color:green'>HPP: ".number_format($hpp)."</small></div>";
            break;		
    }
}, 10, 2);

// Render Kolom Customer
add_action('manage_pr_customer_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'cust_id': 
            echo "<strong>".get_field('cust_id_type', $post_id).":</strong> ".get_field('cust_id_number', $post_id);
            break;
        case 'cust_phone': echo get_field('cust_phone', $post_id); break;
        case 'cust_kyc':
            echo get_field('cust_id_scan', $post_id) ? '✅ Lengkap' : '⚠️ <span style="color:red">Belum Upload</span>';
            break;
    }
}, 10, 2);

// ==========================================================
// 2. ACF FIELD GROUPS (TABS & PLACEHOLDERS)
// ==========================================================

add_action('acf/init', function() {
    if(!function_exists('acf_add_local_field_group')) return;

    // --- FIELD GROUP: MASTER SKU ---
    acf_add_local_field_group([
        'key' => 'group_puri_item_v64',
        'title' => '📦 Manajemen Master SKU',
        'fields' => [
            [ 'key' => 'it_tab_1', 'label' => 'Identitas & Media', 'type' => 'tab' ],
            // Image Placeholder Logic (Visual Hint)
            [
                'key' => 'f_item_img_placeholder',
                'label' => 'Petunjuk Foto',
                'name' => '',
                'type' => 'message',
                'message' => '📸 <strong>Foto Produk:</strong> Gunakan latar belakang putih agar rapi di struk digital.',
                'new_lines' => 'wpautop',
            ],
            [ 'key' => 'f_item_sku', 'label' => 'Kode SKU', 'name' => 'item_sku_code', 'type' => 'text', 'required' => 1, 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_item_type', 'label' => 'Tipe Item', 'name' => 'type', 'type' => 'select', 'choices' => ['currency'=>'Valas (Currency)','goods'=>'Barang Retail','package'=>'Paket Bundling'], 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_item_desc', 'label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'rows' => 2 ],
            [ 'key' => 'f_item_img', 'label' => 'Foto Visual', 'name' => 'item_image', 'type' => 'image', 'return_format' => 'url' ],
            
            [ 'key' => 'it_tab_2', 'label' => 'Harga & Denom', 'type' => 'tab' ],
            [ 'key' => 'f_item_denom', 'label' => 'Nilai Denom', 'name' => 'denom_value', 'type' => 'number', 'default_value' => 1, 'conditional_logic' => [[['field'=>'f_item_type','operator'=>'==','value'=>'currency']]], 'wrapper' => ['width'=>'33'] ],
            [ 'key' => 'f_item_rate', 'label' => 'Harga Jual', 'name' => 'sell_rate', 'type' => 'number', 'wrapper' => ['width'=>'33'] ],
            [ 'key' => 'f_base_price', 'label' => 'HPP (Auto)', 'name' => 'base_price', 'type' => 'number', 'readonly' => 1, 'wrapper' => ['width'=>'34'] ],
            
            [ 'key' => 'it_tab_3', 'label' => 'Resep Bundling', 'type' => 'tab', 'conditional_logic' => [[['field'=>'f_item_type','operator'=>'==','value'=>'package']]] ],
            [ 'key' => 'f_item_pkg', 'label' => 'Daftar Komponen', 'name' => 'package_contents', 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Tambah Item', 'sub_fields' => [
                ['key'=>'p_item_id', 'label'=>'Pilih Item', 'name'=>'p_item_ref', 'type'=>'post_object', 'post_type'=>['pr_item'], 'return_format'=>'id'],
                ['key'=>'p_item_qty', 'label'=>'Qty', 'name'=>'p_qty', 'type'=>'number', 'default_value' => 1]
            ]],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'pr_item']]],
    ]);

    // --- FIELD GROUP: CUSTOMER KYC ---
    acf_add_local_field_group([
        'key' => 'group_puri_customer_v64',
        'title' => '👤 Customer KYC & Legalitas',
        'fields' => [
            [ 'key' => 'c_tab_1', 'label' => 'Profil', 'type' => 'tab' ],
            [ 'key' => 'f_cust_phone', 'label' => 'WhatsApp', 'name' => 'cust_phone', 'type' => 'text', 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_cust_address', 'label' => 'Alamat', 'name' => 'cust_address', 'type' => 'textarea', 'rows' => 2 ],

            [ 'key' => 'c_tab_2', 'label' => 'Identitas (KYC)', 'type' => 'tab' ],
            [ 'key' => 'f_kyc_hint', 'label' => 'Petunjuk Identitas', 'name' => '', 'type' => 'message', 'message' => '🪪 <strong>Compliance:</strong> Pastikan scan kartu ID terbaca jelas (tidak blur).' ],
            [ 'key' => 'f_cust_id_type', 'label' => 'Tipe ID', 'name' => 'cust_id_type', 'type' => 'select', 'choices' => ['KTP'=>'KTP','PASPOR'=>'PASPOR','SIM'=>'SIM'], 'wrapper' => ['width'=>'30'] ],
            [ 'key' => 'f_cust_id_num', 'label' => 'No. Identitas', 'name' => 'cust_id_number', 'type' => 'text', 'wrapper' => ['width'=>'70'] ],
            [ 'key' => 'f_cust_id_scan', 'label' => 'Scan Kartu ID', 'name' => 'cust_id_scan', 'type' => 'image', 'return_format' => 'id', 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_cust_snapshot', 'label' => 'Snapshot Wajah', 'name' => 'cust_snapshot', 'type' => 'image', 'return_format' => 'id', 'wrapper' => ['width'=>'50'] ],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'pr_customer']]],
    ]);
});

// ==========================================================
// 3. SANITASI DATA (THE RE-SYNC BOLT)
// ==========================================================
add_action('acf/save_post', function($post_id) {
    if (get_post_type($post_id) !== 'pr_item') return;

    // Sanitasi Denom (Force 1 if not currency)
    $type = get_field('type', $post_id);
    if ($type !== 'currency') {
        update_field('denom_value', 1, $post_id);
    }

    // Sanitasi Harga Jual
    $rate = get_field('sell_rate', $post_id);
    update_field('sell_rate', max(0, floatval($rate)), $post_id);

    // Sanitasi Resep Bundling
    $package = get_field('package_contents', $post_id);
    if (is_array($package)) {
        foreach ($package as $idx => $row) {
            if (isset($row['p_qty'])) {
                $package[$idx]['p_qty'] = max(1, intval($row['p_qty']));
            }
        }
        update_field('package_contents', $package, $post_id);
    }
}, 20);