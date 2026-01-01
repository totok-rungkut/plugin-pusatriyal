<?php
/**
 * MC 19 - CRUD Partnership (Vendor & Customer) (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Register CPT pr_vendor & pr_customer
 *  - Register ACF fields for vendor/customer KYC and snapshots
 *  - Improve admin columns and sanitization on save
 *
 * Improvements:
 *  - Capability checks not required for CPT registration (WP handles access)
 *  - ACF guard added
 *  - Sanitize fields during acf/save_post
 *  - Custom admin columns escaped
 */

defined('ABSPATH') || exit;

add_action('init', function() {
    register_post_type('pr_vendor', [
        'labels' => ['name'=>'Master Vendor','singular_name'=>'Vendor','add_new'=>'Tambah Vendor Baru'],
        'public' => true, 'show_in_menu' => false, 'supports' => ['title'], 'has_archive' => false, 'rewrite' => ['slug'=>'master-vendor']
    ]);
    register_post_type('pr_customer', [
        'labels' => ['name'=>'Master Customer','singular_name'=>'Customer','add_new'=>'Tambah Customer Baru'],
        'public' => true, 'show_in_menu' => false, 'supports' => ['title'], 'has_archive' => false, 'rewrite' => ['slug'=>'master-customer']
    ]);
});

add_action('acf/init', function() {
    if (!function_exists('acf_add_local_field_group')) return;

    acf_add_local_field_group([
        'key'=>'group_puri_vendor_detail',
        'title'=>'🏢 Profil & Performa Vendor',
        'fields'=>[
            ['key'=>'v_code','label'=>'Kode Vendor','name'=>'vendor_code','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_pic','label'=>'Nama PIC','name'=>'vendor_pic','type'=>'text','wrapper'=>['width'=>'40']],
            ['key'=>'v_phone','label'=>'Phone / WA','name'=>'vendor_phone','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_city','label'=>'Kota','name'=>'vendor_city','type'=>'text','wrapper'=>['width'=>'40']],
            ['key'=>'v_address','label'=>'Alamat Lengkap','name'=>'vendor_address','type'=>'textarea','rows'=>2],
            ['key'=>'v_snapshot','label'=>'Snapshot Performa (JSON)','name'=>'vendor_stats_json','type'=>'textarea','readonly'=>1,'instructions'=>'Aggregat: total_trx, total_riyal, last_update.'],
        ],
        'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'pr_vendor']]]
    ]);

    acf_add_local_field_group([
        'key'=>'group_puri_cust_profile',
        'title'=>'👥 Profil KYC Pelanggan',
        'fields'=>[
            ['key'=>'c_code','label'=>'Kode Customer','name'=>'customer_code','type'=>'text','wrapper'=>['width'=>'25']],
            ['key'=>'c_nik','label'=>'NIK (KTP) *Wajib','name'=>'cust_nik','type'=>'text','required'=>1,'wrapper'=>['width'=>'25']],
            ['key'=>'c_phone','label'=>'WhatsApp','name'=>'cust_phone','type'=>'text','wrapper'=>['width'=>'25']],
            ['key'=>'c_city','label'=>'Kota','name'=>'cust_city','type'=>'text','wrapper'=>['width'=>'25']],
            ['key'=>'c_address','label'=>'Alamat Sesuai KTP','name'=>'cust_address','type'=>'textarea','rows'=>2],
            ['key'=>'c_ktp_img','label'=>'Foto KTP (Image)','name'=>'cust_ktp_image','type'=>'image','return_format'=>'url','wrapper'=>['width'=>'50']],
            ['key'=>'c_type','label'=>'Tipe Pelanggan','name'=>'cust_type','type'=>'select','choices'=>['retail'=>'Umum','member'=>'Member','agent'=>'Agen'],'wrapper'=>['width'=>'50']],
            ['key'=>'c_snapshot','label'=>'Snapshot Performa (JSON)','name'=>'customer_stats_json','type'=>'textarea','readonly'=>1,'instructions'=>'Aggregat: total_trx, total_riyal, repeat_order_count.'],
        ],
        'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'pr_customer']]]
    ]);
});

// Sanitize minimal fields on acf/save_post
add_action('acf/save_post', function($post_id) {
    if (get_post_type($post_id) === 'pr_customer') {
        $nik = get_field('cust_nik', $post_id);
        if ($nik !== null) update_field('cust_nik', preg_replace('/\D/', '', sanitize_text_field($nik)), $post_id);
    }
    if (get_post_type($post_id) === 'pr_vendor') {
        $code = get_field('vendor_code', $post_id);
        if ($code !== null) update_field('vendor_code', sanitize_text_field($code), $post_id);
    }
}, 20);

// Admin columns
add_filter('manage_pr_vendor_posts_columns', function($cols) {
    return ['cb'=>$cols['cb'],'v_code'=>'Kode','title'=>'Nama Vendor','v_pic'=>'PIC','v_city'=>'Kota','v_stats'=>'Supply Value (SAR)','date'=>'Tgl Input'];
});
add_action('manage_pr_vendor_posts_custom_column', function($col,$id) {
    $stats = json_decode(get_field('vendor_stats_json',$id), true);
    switch($col){
        case 'v_code': echo '<strong>'.esc_html(get_field('vendor_code',$id)).'</strong>'; break;
        case 'v_pic': echo esc_html(get_field('vendor_pic',$id)); break;
        case 'v_city': echo esc_html(get_field('vendor_city',$id)); break;
        case 'v_stats': echo '<b>'.number_format(intval($stats['total_riyal']??0)).'</b>'; break;
    }
},10,2);

add_filter('manage_pr_customer_posts_columns', function($cols) {
    return ['cb'=>$cols['cb'],'c_nik'=>'NIK','title'=>'Nama Pelanggan','c_type'=>'Tipe','c_stats'=>'Repeat Order','c_value'=>'Total Value (SAR)'];
});
add_action('manage_pr_customer_posts_custom_column', function($col,$id) {
    $stats = json_decode(get_field('customer_stats_json',$id), true);
    switch($col){
        case 'c_nik': echo '<code>'.esc_html(get_field('cust_nik',$id)).'</code>'; break;
        case 'c_type': echo esc_html(strtoupper(get_field('cust_type',$id))); break;
        case 'c_stats': echo '<b>'.intval($stats['total_trx']??0).' Kali</b>'; break;
        case 'c_value': echo number_format(intval($stats['total_riyal']??0)); break;
    }
},10,2);