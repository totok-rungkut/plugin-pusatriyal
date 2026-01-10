<?php
/**
 * MC 02 - Data Model: Master SKU
 * Version: 6.5.0 (Clean SKU Specialist)
 * * PURPOSE: Khusus menangani Master Barang (Retail, Valas, Paket).
 */

defined('ABSPATH') || exit;

// --- 1. ADMIN LIST COLUMNS (SKU ONLY) ---
add_filter('manage_pr_item_posts_columns', function($columns) {
    return [
        'cb'        => $columns['cb'],
        'sku_code'  => 'Kode SKU',
        'title'     => 'Nama Item',
        'item_type' => 'Tipe',
        'denom'     => 'Denom',
        'stock'     => 'Stok',
        'price'     => 'Harga / HPP',
    ];
});

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
            $d = floatval(get_field('denom_value', $post_id) ?: 0);
            echo ($d > 0) ? "<strong>".number_format($d)."</strong>" : "-";
            break;
        case 'stock': 
            $qty = $wpdb->get_var($wpdb->prepare("SELECT qty_balance FROM ".puri_table_name('T_STOCK')." WHERE item_id = %d", $post_id));
            echo "<strong>".number_format(floatval($qty ?: 0))."</strong>";
            break;
        case 'price':
            $jual = floatval(get_field('sell_rate', $post_id) ?: 0);
            $hpp  = floatval(get_post_meta($post_id, 'base_price', true) ?: 0);
            echo "<div style='line-height:1.2'><small>Jual: </small><span style='color:#2563eb;font-weight:600;'>".number_format($jual)."</span><br>";
            echo "<small style='color:green'>HPP: ".number_format($hpp)."</small></div>";
            break;
    }
}, 10, 2);

// --- 2. ACF FIELD GROUP (SKU ONLY) ---
add_action('acf/init', function() {
    if(!function_exists('acf_add_local_field_group')) return;

    acf_add_local_field_group([
        'key' => 'group_puri_item_v65',
        'title' => '📦 Master SKU Manager',
        'fields' => [
            [ 'key' => 'it_tab_1', 'label' => 'Identitas & Media', 'type' => 'tab' ],
            [ 'key' => 'f_item_sku', 'label' => 'Kode SKU', 'name' => 'item_sku_code', 'type' => 'text', 'required' => 1, 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_item_type', 'label' => 'Tipe Item', 'name' => 'type', 'type' => 'select', 'choices' => ['currency'=>'Valas','goods'=>'Retail','package'=>'Paket'], 'wrapper' => ['width'=>'50'] ],
            [ 'key' => 'f_item_desc', 'label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'rows' => 2 ],
            [ 'key' => 'f_item_img', 'label' => 'Foto Visual', 'name' => 'item_image', 'type' => 'image', 'return_format' => 'url' ],
            
            [ 'key' => 'it_tab_2', 'label' => 'Harga & Denom', 'type' => 'tab' ],
            [ 'key' => 'f_item_denom', 'label' => 'Nilai Denom', 'name' => 'denom_value', 'type' => 'number', 'default_value' => 1, 'conditional_logic' => [[['field'=>'f_item_type','operator'=>'==','value'=>'currency']]] ],
            [ 'key' => 'f_item_rate', 'label' => 'Harga Jual', 'name' => 'sell_rate', 'type' => 'number' ],
            [ 'key' => 'f_base_price', 'label' => 'HPP (Auto)', 'name' => 'base_price', 'type' => 'number', 'readonly' => 1 ],

            [ 'key' => 'it_tab_3', 'label' => 'Resep Paket', 'type' => 'tab', 'conditional_logic' => [[['field'=>'f_item_type','operator'=>'==','value'=>'package']]] ],
            [ 'key' => 'f_item_pkg', 'label' => 'Daftar Komponen', 'name' => 'package_contents', 'type' => 'repeater', 'layout' => 'table', 'sub_fields' => [
                ['key'=>'p_item_id', 'label'=>'Item', 'name'=>'p_item_ref', 'type'=>'post_object', 'post_type'=>['pr_item'], 'return_format'=>'id'],
                ['key'=>'p_item_qty', 'label'=>'Qty', 'name'=>'p_qty', 'type'=>'number']
            ]],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'pr_item']]],
    ]);
});

// --- 3. SANITASI SKU ---
add_action('acf/save_post', function($post_id) {
    if (get_post_type($post_id) !== 'pr_item') return;
    $rate = get_field('sell_rate', $post_id);
    update_field('sell_rate', max(0, floatval($rate)), $post_id);
}, 20);