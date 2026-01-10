<?php
/**
 * MC 19 - CRUD Partnership (Vendor & Customer)
 * Version: 6.8.3 (Legacy Harmony & Compliance)
 * * FIX LOG:
 * - Restore Vendor Fields mapping (vendor_code, vendor_type, vendor_pic, etc.)
 * - Add Customer Fields: cust_email, cust_code.
 * - Logic: Auto-Status DRAFT if required fields (NIK, Phone, Address, Scan KTP) incomplete.
 * - UI: Custom Admin Columns for Vendor & Customer.
 */

defined('ABSPATH') || exit;

// 1. REGISTRASI CPT (VENDOR & CUSTOMER)
add_action('init', function() {
    register_post_type('pr_vendor', [
        'labels' => ['name'=>'Master Vendor','add_new'=>'Tambah Vendor'],
        'public' => true, 'show_in_menu' => false, 'menu_icon' => 'dashicons-store', 'supports' => ['title']
    ]);
    register_post_type('pr_customer', [
        'labels' => ['name'=>'Master Customer','add_new'=>'Tambah Customer'],
        'public' => true, 'show_in_menu' => false, 'menu_icon' => 'dashicons-id', 'supports' => ['title']
    ]);
});

// 2. UI ENFORCEMENT & HIGHLIGHTER (Patch: Attention Only)
add_action('acf/input/admin_head', function() {
    ?>
    <style>
        /* Highlight field wajib yang kosong atau salah */
        .puri-incomplete { border: 2px solid #ffb900 !important; background: #fffcf5 !important; }
        .puri-notice-compliance { border-left-color: #ffb900 !important; }
    </style>
    <script type="text/javascript">
    (function($){
        $(document).ready(function(){
            // Highlight visual untuk field yang belum lengkap (NIK, Phone, Address, KTP)
            const reqFields = ['cust_nik', 'cust_phone', 'cust_address', 'cust_ktp_image'];
            reqFields.forEach(field => {
                let $wrapper = $('div[data-name="' + field + '"] .acf-input-wrap, div[data-name="' + field + '"] .acf-image-uploader');
                let val = $('div[data-name="' + field + '"] input').val();
                if(!val || val === "" || (field === 'cust_nik' && val.length !== 16)) {
                    $wrapper.addClass('puri-incomplete');
                }
            });
        });
    })(jQuery);
    </script>
    <?php
});



// 3. VALIDASI SERVER-SIDE
add_filter('acf/validate_value/name=cust_nik', function($valid, $value) {
    if (!preg_match('/^[0-9]{16}$/', $value)) return '⚠️ NIK WAJIB 16 DIGIT.' and $valid;
    return $valid;
}, 10, 4);

// 4. LOGIKA AUTO-STATUS & SYNC (Patch: Attention & Soft-Required)
add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
function puri_partnership_harmony_logic($post_id) {
    if (get_post_type($post_id) !== 'pr_customer') return;

    $nama    = get_the_title($post_id);
    $nik     = get_field('cust_nik', $post_id);
    $phone   = get_field('cust_phone', $post_id);
    $addr    = get_field('cust_address', $post_id);
    $id_scan = get_field('cust_ktp_image', $post_id);

    // Tetap sinkron meta untuk MC-25 POS
    update_post_meta($post_id, '_puri_cust_type', get_field('cust_type', $post_id) ?: 'retail');
    update_post_meta($post_id, '_puri_cust_phone', $phone);

    // Kriteria untuk bisa PUBLISH (Baut Kencang untuk POS)
    $missing = [];
    if (empty($nama) || $nama === 'Auto Draft') $missing[] = 'Nama Lengkap';
    if (strlen($nik) !== 16) $missing[] = 'NIK (16 Digit)';
    if (empty($phone)) $missing[] = 'WhatsApp';
    if (empty($addr)) $missing[] = 'Alamat KTP';
    if (empty($id_scan)) $missing[] = 'Foto KTP';

    // Jika ada yang kurang, paksa DRAFT (Allowed Update, but Not Published)
    $status = (empty($missing)) ? 'publish' : 'draft';

    // Simpan pesan untuk Admin Notice
    set_transient('puri_kyc_notice_' . $post_id, $missing, 30);

    remove_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
    wp_update_post([
        'ID'          => $post_id,
        'post_title'  => strtoupper($nama),
        'post_status' => $status
    ]);
    add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
}


// 5. ACF GROUPS
add_action('acf/init', function() {
	// -----------------------------------------------------
    // VENDOR GROUP (Sesuai Foto Meta Key Anda)
	// -----------------------------------------------------
    acf_add_local_field_group([
        'key' => 'group_puri_vendor_legacy',
        'title' => '🏢 Profil Vendor (Legacy Stable)',
        'fields' => [
            ['key'=>'v_code','label'=>'Vendor Code','name'=>'vendor_code','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_type','label'=>'Vendor Type','name'=>'vendor_type','type'=>'select','choices'=>['bank'=>'Bank','money_changer'=>'Money Changer','umum'=>'Umum'],'wrapper'=>['width'=>'30']],
            ['key'=>'v_pic','label'=>'PIC Name','name'=>'vendor_pic','type'=>'text','wrapper'=>['width'=>'40']],
            ['key'=>'v_phone','label'=>'Phone','name'=>'vendor_phone','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_city','label'=>'City','name'=>'vendor_city','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'v_addr','label'=>'Address','name'=>'vendor_address', 'type'=>'textarea','rows'=>2],
        ],
        'location' => [[['param'=>'post_type','operator'=>'==','value'=>'pr_vendor']]]
    ]);

	// -----------------------------------------------------
    // CUSTOMER GROUP (Restore & Patch)
	// -----------------------------------------------------
    acf_add_local_field_group([
        'key' => 'group_puri_customer_patch',
        'title' => '👥 Profil KYC Pelanggan',
        'fields' => [
//            ['key'=>'c_tab_1','label'=>'Data Dasar','type'=>'tab'],
            ['key'=>'c_address','label'=>'Alamat Sesuai KTP','name'=>'cust_address','type'=>'textarea','rows'=>2,'wrapper'=>['width'=>'80']],
			['key'=>'c_city','label'=>'Kota','name'=>'cust_city','type'=>'text','wrapper'=>['width'=>'20']],
            ['key'=>'c_phone','label'=>'WhatsApp (Wajib)','name'=>'cust_phone','type'=>'text', 'required'=>0,'wrapper'=>['width'=>'30']],
            ['key'=>'c_nik','label'=>'NIK KTP (Wajib 16 Digit)','name'=>'cust_nik','type'=>'text','required'=>0,'wrapper'=>['width'=>'30']],
			['key'=>'c_code','label'=>'Code','name'=>'cust_code','type'=>'text','wrapper'=>['width'=>'15']],
            ['key'=>'c_type','label'=>'Tipe','name'=>'cust_type','type'=>'select','choices'=>['retail'=>'Umum','member'=>'Member','agent'=>'Agen'],'wrapper'=>['width'=>'25']],
            ['key'=>'c_email','label'=>'Email','name'=>'cust_email','type'=>'email','wrapper'=>['width'=>'40']],
//            ['key'=>'c_tab_2','label'=>'Legalitas & Alamat','type'=>'tab'],
           
            ['key'=>'c_ktp_img','label'=>'Scan KTP (Wajib Publish)','name'=>'cust_ktp_image','type'=>'image','return_format'=>'id','wrapper'=>['width'=>'60']],
            
        ],
        'location' => [[['param'=>'post_type','operator'=>'==','value'=>'pr_customer']]]
    ]);
});

// 6. CUSTOM ADMIN LIST TABLE (VENDOR & CUSTOMER)


// -----------------------------------------------------
// Vendor List
// -----------------------------------------------------
add_filter('manage_pr_vendor_posts_columns', function($cols) {
    return [
        'cb' => '<input type="checkbox" />',
        'v_code' => 'Code',
        'title' => 'Nama Vendor',
        'v_addr' => 'Alamat',
        'v_city' => 'Kota',
        'v_pic' => 'PIC',
        'v_phone' => 'Phone',
        'v_type' => 'Type'
    ];
});

add_action('manage_pr_vendor_posts_custom_column', function($col, $id) {
    switch($col){
        case 'v_code': echo '<strong>'.esc_html(get_field('vendor_code',$id)).'</strong>'; break;
        case 'v_addr': echo esc_html(get_field('vendor_address',$id)); break;
        case 'v_city': echo esc_html(get_field('vendor_city',$id)); break;
        case 'v_pic': echo esc_html(get_field('vendor_pic',$id)); break;
        case 'v_phone': echo esc_html(get_field('vendor_phone',$id)); break;
        case 'v_type': echo '<span class="dashicons dashicons-id"></span> '.esc_html(strtoupper(get_field('vendor_type',$id))); break;
    }
}, 10, 2);

// -----------------------------------------------------
// Customer List
// -----------------------------------------------------
add_filter('manage_pr_customer_posts_columns', function($cols) {
    return [
        'cb' => '<input type="checkbox" />',
        'title' => 'Nama Pelanggan',
        'c_address' => 'Alamat',
        'c_phone' => 'WhatsApp',
        'c_type' => 'Tipe',
        'c_code' => 'Code',
        'c_status' => 'Status KYC'
    ];
});

add_action('manage_pr_customer_posts_custom_column', function($col, $id) {
    switch($col){
        case 'c_address': echo '<code>'.esc_html(get_field('cust_address',$id)).'</code>'; break;
        case 'c_phone': echo esc_html(get_field('cust_phone',$id)); break;
        case 'c_type': echo esc_html(strtoupper(get_field('cust_type',$id))); break;
        case 'c_code': echo esc_html(strtoupper(get_field('cust_code',$id))); break;
        case 'c_status': 
            $status = get_post_status($id);
            echo ($status === 'publish') ? '<span style="color:#2271b1">✅ Lengkap</span>' : '<span style="color:#d63638">⚠️ Draft</span>';
            break;
    }
}, 10, 2);