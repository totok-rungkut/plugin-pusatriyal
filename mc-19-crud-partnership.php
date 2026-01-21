<?php
/**
 * MC 19 - CRUD Partnership (Vendor & Customer)
 * Version: 6.8.4 (Fix Field Prefix & Auto-Draft Logic)
 * * FIX LOG:
 * - Update Field Names to prefix: _puri_cust_
 * - Fix Typo: Removed double quotes inside field names.
 * - Fix Logic: Auto-Status DRAFT now checks the correct new field names.
 * - UI: Updated JS Highlighter and Admin Columns to match new fields.
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
            // Highlight visual untuk field yang belum lengkap
            // UPDATE: Nama field disesuaikan dengan prefix _puri_cust_
            const reqFields = ['_puri_cust_nik', '_puri_cust_phone', '_puri_cust_address', '_puri_cust_ktp_image'];
            
            reqFields.forEach(field => {
                let $wrapper = $('div[data-name="' + field + '"] .acf-input-wrap, div[data-name="' + field + '"] .acf-image-uploader');
                let val = $('div[data-name="' + field + '"] input').val();
                
                // Cek kosong atau NIK tidak 16 digit
                if(!val || val === "" || (field === '_puri_cust_nik' && val.length !== 16)) {
                    $wrapper.addClass('puri-incomplete');
                }
            });
        });
    })(jQuery);
    </script>
    <?php
});

// 3. VALIDASI SERVER-SIDE
// UPDATE: Hook disesuaikan dengan nama field baru
add_filter('acf/validate_value/name=_puri_cust_nik', function($valid, $value) {
    if (!preg_match('/^[0-9]{16}$/', $value)) return '⚠️ NIK WAJIB 16 DIGIT.' and $valid;
    return $valid;
}, 10, 4);

// 4. LOGIKA AUTO-STATUS & SYNC (Core Logic Fix)
add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
function puri_partnership_harmony_logic($post_id) {
    if (get_post_type($post_id) !== 'pr_customer') return;

    $nama    = get_the_title($post_id);
    
    // UPDATE: Get field menggunakan nama baru (_puri_cust_)
    // Perhatikan: get_field tidak perlu underscore di depan JIKA key di JSON pakai underscore
    // Tapi karena kita set 'name' => '_puri_cust_xxx', maka panggil persis namanya.
    $nik     = get_field('_puri_cust_nik', $post_id);
    $phone   = get_field('_puri_cust_phone', $post_id);
    $addr    = get_field('_puri_cust_address', $post_id);
    $id_scan = get_field('_puri_cust_ktp_image', $post_id);
    $id_type = get_field('_puri_cust_id_type', $post_id); // Ditambahkan agar tidak error undefined variable
    $type    = get_field('_puri_cust_type', $post_id);

    // Tetap sinkron meta untuk MC-25 POS
    // Note: Karena nama field ACF sudah pakai '_puri_cust_', ini sebenarnya redundant, 
    // tapi kita biarkan untuk backward compatibility jika ada query manual.
    update_post_meta($post_id, '_puri_cust_type', $type ?: 'retail');
    update_post_meta($post_id, '_puri_cust_phone', $phone);

    // Kriteria untuk bisa PUBLISH (Baut Kencang untuk POS)
    $missing = [];
    if (empty($nama) || $nama === 'Auto Draft') $missing[] = 'Nama Lengkap';
	
	if ($id_type === 'KTP') {
        // Pastikan $nik tidak null sebelum di regex
        if (!$nik || strlen(preg_replace('/\D/', '', $nik)) !== 16) $missing[] = 'NIK KTP (Harus 16 Digit)';
    } else {
        if (empty($nik)) $missing[] = 'Nomor Identitas ' . $id_type;
    }    
    
    if (empty($phone)) $missing[] = 'WhatsApp';
	if (empty($addr)) $missing[] = 'Alamat KTP';
    if (empty($id_scan)) $missing[] = 'Foto KTP';

    // Jika ada yang kurang, paksa DRAFT (Allowed Update, but Not Published)
    $status = (empty($missing)) ? 'publish' : 'draft';

    // Simpan pesan untuk Admin Notice
    set_transient('puri_kyc_notice_' . $post_id, $missing, 30);

    // Unhook untuk menghindari infinite loop saat wp_update_post
    remove_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
    
    wp_update_post([
        'ID'          => $post_id,
        'post_title'  => strtoupper($nama),
        'post_status' => $status
    ]);
    
    // Re-hook
    add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
}


// 5. ACF GROUPS
add_action('acf/init', function() {
	// -----------------------------------------------------
    // VENDOR GROUP (Legacy Stable - Tidak Diubah)
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
    // UPDATE: Menghapus tanda kutip " di dalam nama field (name)
	// -----------------------------------------------------
    acf_add_local_field_group([
        'key' => 'group_puri_customer_patch',
        'title' => '👥 Profil KYC Pelanggan',
		'fields' => [
            ['key'=>'c_tab_1','label'=>'Data Dasar','type'=>'tab'],
				// PERBAIKAN: _puri_cust_"address -> _puri_cust_address
				['key'=>'c_address','label'=>'Alamat Sesuai KTP','name'=>'_puri_cust_address','type'=>'textarea','rows'=>2,'wrapper'=>['width'=>'70']],
				['key'=>'c_city','label'=>'Kota','name'=>'_puri_cust_city','type'=>'text','wrapper'=>['width'=>'30']],			
				['key'=>'c_phone','label'=>'WhatsApp (Wajib)','name'=>'_puri_cust_phone','type'=>'text', 'required'=>0,'wrapper'=>['width'=>'25']],
				['key'=>'c_type','label'=>'Tipe Customer','name'=>'_puri_cust_type','type'=>'select','choices'=>[
					'umum'         => 'Umum',
					'member'       => 'Member',
					'agent'        => 'Agent',
					'moneychanger' => 'Money Changer',
					'bank'         => 'Bank'
				],'default_value'=>'umum','wrapper'=>['width'=>'20'] ],
				['key'=>'c_code','label'=>'Code Customer','name'=>'_puri_cust_code','type'=>'text','wrapper'=>['width'=>'20']],
				['key'=>'c_email','label'=>'Email','name'=>'_puri_cust_email','type'=>'email','wrapper'=>['width'=>'35']],
				
			['key'=>'c_tab_2','label'=>'Legalitas & Alamat','type'=>'tab'],
				['key'=>'c_id_type','label'=>'Jenis Identitas','name'=>'_puri_cust_id_type','type'=>'select','choices'=>[
					'KTP'   => 'KTP',
					'SIM'   => 'SIM',
					'PASSPORT' => 'Passport',
					'NPWP'  => 'NPWP',
					'KUPVA' => 'KUPVA'
				],'default_value'=>'KTP','wrapper'=>['width'=>'34'] ],
				['key'=>'c_nik','label'=>'Nomor Identitas (NIK/Passport)','name'=>'_puri_cust_nik','type'=>'text','required'=>0,'wrapper'=>['width'=>'33']],
				['key'=>'c_citizenship','label'=>'Kewarganegaraan','name'=>'_puri_cust_citizenship','type'=>'text','wrapper'=>['width'=>'30']],			
				['key'=>'c_occupation','label'=>'Pekerjaan','name'=>'_puri_cust_occupation','type'=>'text','wrapper'=>['width'=>'50']],
				['key'=>'c_purpose','label'=>'Tujuan Transaksi','name'=>'_puri_cust_purpose','type'=>'text','wrapper'=>['width'=>'50']],
				['key'=>'c_ktp_img','label'=>'Scan KTP/Identitas (Wajib Publish)','name'=>'_puri_cust_ktp_image','type'=>'image','return_format'=>'id','wrapper'=>['width'=>'100']],
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

// UPDATE: Kolom Admin disesuaikan dengan nama field baru
add_action('manage_pr_customer_posts_custom_column', function($col, $id) {
    switch($col){
        case 'c_address': echo '<code>'.esc_html(get_field('_puri_cust_address',$id)).'</code>'; break;
        case 'c_phone': echo esc_html(get_field('_puri_cust_phone',$id)); break;
        case 'c_type': echo esc_html(strtoupper(get_field('_puri_cust_type',$id))); break;
        case 'c_code': echo esc_html(strtoupper(get_field('_puri_cust_code',$id))); break;
        case 'c_status': 
            $status = get_post_status($id);
            echo ($status === 'publish') ? '<span style="color:#2271b1">✅ Lengkap</span>' : '<span style="color:#d63638">⚠️ Draft</span>';
            break;
    }
}, 10, 2);

// UPDATE: File renaming logic disesuaikan dengan nama field baru
add_filter('wp_handle_upload_prefilter', function($file) {
    if (isset($_POST['post_id']) && get_post_type($_POST['post_id']) === 'pr_customer') {
        $post_id = $_POST['post_id'];
        
        $id_type   = get_field('_puri_cust_id_type', $post_id) ?: 'ID';
        $cust_name = sanitize_title(get_the_title($post_id));
        $cust_id   = get_field('_puri_cust_nik', $post_id) ?: '000000';
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file['name'] = "{$id_type}_{$cust_name}_{$cust_id}.{$ext}";
    }
    return $file;
});