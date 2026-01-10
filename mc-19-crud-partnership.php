<?php
/**
 * MC 19 - CRUD Partnership (Vendor & Customer)
 * Version: 6.8.2 (Restoration & Iron-Locked)
 * * FIX LOG:
 * - Restore pr_vendor CPT & Fields (Fix MC-04 Broken Link)
 * - Restore cust_nik & cust_type mapping (Fix MC-25 Logic)
 * - Keep JS Enforcement for NIK (16 chars) & Phone (08xxx)
 */

defined('ABSPATH') || exit;

// 1. REGISTRASI CPT (VENDOR & CUSTOMER) - KEMBALI SEPERTI v6.0.1
add_action('init', function() {
    register_post_type('pr_vendor', [
        'labels' => ['name'=>'Master Vendor','add_new'=>'Tambah Vendor Baru'],
        'public' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-store', 'supports' => ['title']
    ]);
    register_post_type('pr_customer', [
        'labels' => ['name'=>'Master Customer','add_new'=>'Tambah Customer Baru'],
        'public' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-id', 'supports' => ['title']
    ]);
});

// 2. JAVASCRIPT ENFORCEMENT (Tetap Kencang pada baut yang benar)
add_action('acf/input/admin_footer', function() {
    ?>
    <script type="text/javascript">
    (function($){
        function lockField(selector, limit, onlyNum = true) {
            $(document).on('input paste', 'div[data-name="' + selector + '"] input', function(e) {
                var $input = $(this);
                setTimeout(function() {
                    var val = onlyNum ? $input.val().replace(/[^0-9]/g, '') : $input.val();
                    if (val.length > limit) $input.val(val.substring(0, limit));
                    else $input.val(val);
                }, 10);
            });
        }
        lockField('cust_nik', 16);    // Mapping kembali ke cust_nik sesuai v6.0.1
        lockField('cust_phone', 13);
    })(jQuery);
    </script>
    <?php
});

// 3. VALIDASI SERVER-SIDE
add_filter('acf/validate_value/name=cust_nik', function($valid, $value) {
    if (!preg_match('/^[0-9]{16}$/', $value)) return '⚠️ DATA DITOLAK: NIK harus tepat 16 digit angka.';
    return $valid;
}, 10, 4);

add_filter('acf/validate_value/name=cust_phone', function($valid, $value) {
    if (!preg_match('/^0[0-9]{9,12}$/', $value)) return '⚠️ DATA DITOLAK: No HP harus diawali 0 (10-13 digit).';
    return $valid;
}, 10, 4);

// 4. LOGIKA AUTO-STATUS & SYNC (Kompatibel dengan MC-04 & MC-25)
add_action('acf/save_post', 'puri_partnership_restoration_logic', 25);
function puri_partnership_restoration_logic($post_id) {
    $type = get_post_type($post_id);
    if ($type !== 'pr_customer' && $type !== 'pr_vendor') return;

    $nama = get_the_title($post_id);
    
    // Logic khusus Customer
    if ($type === 'pr_customer') {
        $nik     = get_field('cust_nik', $post_id);
        $phone   = get_field('cust_phone', $post_id);
        $c_type  = get_field('cust_type', $post_id) ?: 'retail';
        $id_scan = get_field('cust_ktp_image', $post_id);

        // Sync meta untuk MC-25 Cockpit
        update_post_meta($post_id, '_puri_cust_type', $c_type);
        update_post_meta($post_id, '_puri_cust_phone', $phone);
        update_post_meta($post_id, '_puri_cust_nik', $nik);

        // Compliance Check
        $is_complete = (!empty($nama) && strlen($nik) === 16 && !empty($phone) && !empty($id_scan));
        $status = ($is_complete) ? 'publish' : 'draft';

        remove_action('acf/save_post', 'puri_partnership_restoration_logic', 25);
        wp_update_post(['ID'=>$post_id, 'post_title'=>strtoupper($nama), 'post_status'=>$status]);
        add_action('acf/save_post', 'puri_partnership_restoration_logic', 25);
    }
}

// 5. ACF GROUPS (RESTORE SEMUA FIELD v6.0.1)
add_action('acf/init', function() {
    // Restore Vendor Group (Penting untuk MC-04)
    acf_add_local_field_group([
        'key' => 'group_puri_vendor_detail_v682',
        'title' => '🏢 Profil & Performa Vendor',
        'fields' => [
            ['key'=>'v_code','label'=>'Kode Vendor','name'=>'vendor_code','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_pic','label'=>'Nama PIC','name'=>'vendor_pic','type'=>'text','wrapper'=>['width'=>'40']],
            ['key'=>'v_phone','label'=>'Phone / WA','name'=>'vendor_phone','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_city','label'=>'Kota','name'=>'vendor_city','type'=>'text','wrapper'=>['width'=>'40']],
            ['key'=>'v_address','label'=>'Alamat Lengkap','name'=>'vendor_address','type'=>'textarea','rows'=>2],
            ['key'=>'v_snapshot','label'=>'Snapshot Performa (JSON)','name'=>'vendor_stats_json','type'=>'textarea','readonly'=>1],
        ],
        'location' => [[['param'=>'post_type','operator'=>'==','value'=>'pr_vendor']]]
    ]);

    // Restore Customer Group (Penting untuk MC-25)
    acf_add_local_field_group([
        'key' => 'group_puri_cust_profile_v682',
        'title' => '👥 Profil KYC Pelanggan',
        'fields' => [
            ['key'=>'c_tab_1','label'=>'Identitas','type'=>'tab'],
            ['key'=>'c_type','label'=>'Tipe Pelanggan','name'=>'cust_type','type'=>'select','choices'=>['retail'=>'Umum','member'=>'Member','agent'=>'Agen'],'wrapper'=>['width'=>'30']],
            ['key'=>'c_nik','label'=>'NIK (Wajib 16 Digit)','name'=>'cust_nik','type'=>'text','required'=>1,'wrapper'=>['width'=>'40']],
            ['key'=>'c_phone','label'=>'WhatsApp','name'=>'cust_phone', 'type'=>'text','required'=>1,'wrapper'=>['width'=>'30']],
            
            ['key'=>'c_tab_2','label'=>'Alamat & Legal','type'=>'tab'],
            ['key'=>'c_city','label'=>'Kota','name'=>'cust_city','type'=>'text','wrapper'=>['width'=>'50']],
            ['key'=>'c_address','label'=>'Alamat Sesuai KTP','name'=>'cust_address','type'=>'textarea','rows'=>2],
            ['key'=>'c_ktp_img','label'=>'Foto KTP (< 200KB)','name'=>'cust_ktp_image','type'=>'image','return_format'=>'id','wrapper'=>['width'=>'50']],
            ['key'=>'c_snapshot','label'=>'Snapshot Performa','name'=>'customer_stats_json','type'=>'textarea','readonly'=>1],
        ],
        'location' => [[['param'=>'post_type','operator'=>'==','value'=>'pr_customer']]]
    ]);
});

// 6. CUSTOM ADMIN COLUMNS (Tampilan List Gabungan)
add_filter('manage_pr_customer_posts_columns', function($cols) {
    return [
        'cb' => $cols['cb'],
        'title' => 'Nama Pelanggan',
        'c_nik' => 'NIK',
        'c_phone' => 'WhatsApp',
        'c_type' => 'Tipe',
        'c_status' => 'Status KYC'
    ];
});

add_action('manage_pr_customer_posts_custom_column', function($col, $id) {
    switch($col){
        case 'c_nik': echo '<code>'.esc_html(get_field('cust_nik',$id)).'</code>'; break;
        case 'c_phone': echo esc_html(get_field('cust_phone',$id)); break;
        case 'c_type': 
            $t = get_field('cust_type',$id);
            echo '<span class="badge-'.esc_attr($t).'">'.esc_html(strtoupper($t)).'</span>';
            break;
        case 'c_status':
            $status = get_post_status($id);
            echo ($status === 'publish') ? '✅ Lengkap' : '⚠️ Draft (Incomplete)';
            break;
    }
}, 10, 2);