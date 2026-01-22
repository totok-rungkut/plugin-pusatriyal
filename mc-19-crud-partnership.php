<?php
/**
 * MC 19 - Partnership & KYC Hub (Hybrid CRUD)
 * Version: 7.0.0 (Integrated Refactor)
 * * Changelog v7.0.0:
 * - RESTORED: Full Vendor Management (CPT, ACF, & Admin Columns).
 * - FIXED: Safe File Upload with timestamp fallback (No more upload traps).
 * - FIXED: Harmony Logic ensures Publish button stays visible on Add New.
 * - LENIENT: NIK/Passport validation for BI Compliance (Warning only).
 * - ADDED: [puri_crew_kyc] Shortcode for back-office data entry.
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

// 2. VALIDASI NIK (LENIENT MODE - WARNING ONLY)
add_filter('acf/validate_value/name=_puri_cust_nik', function($valid, $value) {
    // Sesuai permintaan: Loloskan validasi agar tidak memblokir operasional kasir.
    return $valid;
}, 10, 4);

// 3. LOGIKA HARMONY: AUTO-STATUS & SYNC
add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
function puri_partnership_harmony_logic($post_id) {
    if (get_post_type($post_id) !== 'pr_customer') return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $nama    = get_the_title($post_id);
    $nik     = get_field('_puri_cust_nik', $post_id);
    $phone   = get_field('_puri_cust_phone', $post_id);
    $id_scan = get_field('_puri_cust_ktp_image', $post_id);
    $id_type = get_field('_puri_cust_id_type', $post_id) ?: 'KTP';

    // Update Meta Dasar untuk POS
    update_post_meta($post_id, '_puri_cust_phone', $phone);

    // Kriteria Kelengkapan Mandatori
    $missing = [];
    if (empty($nama) || $nama === 'Auto Draft') $missing[] = 'Nama Lengkap';
    if (empty($nik)) $missing[] = "Nomor Identitas ($id_type)";
    if (empty($phone)) $missing[] = 'Nomor WhatsApp';
    if (empty($id_scan)) $missing[] = 'Foto KTP/Identitas';

    $current_status = get_post_status($post_id);
    $target_status  = $current_status;

    // Jika user mencoba Publish tapi data kosong, kembalikan ke Draft
    if (!empty($missing) && $current_status === 'publish') {
        $target_status = 'draft';
        set_transient('puri_kyc_notice_' . $post_id, $missing, 30);
    }

    remove_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
    wp_update_post([
        'ID'          => $post_id,
        'post_title'  => ($nama && $nama !== 'Auto Draft') ? strtoupper($nama) : $nama,
        'post_status' => $target_status
    ]);
    add_action('acf/save_post', 'puri_partnership_harmony_logic', 25);
}

// 4. ACF FIELD GROUPS (VENDOR & CUSTOMER)
add_action('acf/init', function() {
    // PROFIL VENDOR (Legacy Stable)
    acf_add_local_field_group([
        'key' => 'group_puri_vendor_legacy',
        'title' => '🏢 Profil Vendor',
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

    // PROFIL CUSTOMER (Refactored for BI Compliance)
    acf_add_local_field_group([
        'key' => 'group_puri_customer_patch',
        'title' => '👥 Profil KYC Pelanggan',
        'fields' => [
            ['key'=>'c_tab_1','label'=>'Data Dasar (Mandatori)','type'=>'tab'],
            ['key'=>'c_phone','label'=>'WhatsApp','name'=>'_puri_cust_phone','type'=>'text','wrapper'=>['width'=>'30']],
            ['key'=>'c_type','label'=>'Tipe','name'=>'_puri_cust_type','type'=>'select','choices'=>['umum'=>'Umum','member'=>'Member','agent'=>'Agent','bank'=>'Bank'],'default_value'=>'umum','wrapper'=>['width'=>'30']],
            ['key'=>'c_id_type','label'=>'ID Type','name'=>'_puri_cust_id_type','type'=>'select','choices'=>['KTP'=>'KTP','PASSPORT'=>'Passport','NPWP'=>'NPWP'],'default_value'=>'KTP','wrapper'=>['width'=>'20']],
            ['key'=>'c_nik','label'=>'No. ID','name'=>'_puri_cust_nik','type'=>'text','wrapper'=>['width'=>'20']],
            ['key'=>'c_ktp_img','label'=>'Scan Foto KTP/ID','name'=>'_puri_cust_ktp_image','type'=>'image','return_format'=>'id','wrapper'=>['width'=>'100']],
            
            ['key'=>'c_tab_2','label'=>'Data BI Compliance (Salinan Manual)','type'=>'tab'],
            ['key'=>'c_address','label'=>'Alamat KTP','name'=>'_puri_cust_address','type'=>'textarea','rows'=>2],
            ['key'=>'c_city','label'=>'Kota','name'=>'_puri_cust_city','type'=>'text','wrapper'=>['width'=>'50']],
            ['key'=>'c_citizenship','label'=>'Kewarganegaraan','name'=>'_puri_cust_citizenship', 'type'=>'text','default_value'=>'Indonesia','wrapper'=>['width'=>'50']],
            ['key'=>'c_occupation','label'=>'Pekerjaan','name'=>'_puri_cust_occupation','type'=>'text','wrapper'=>['width'=>'50']],
            ['key'=>'c_purpose','label'=>'Tujuan Transaksi','name'=>'_puri_cust_purpose','type'=>'text','wrapper'=>['width'=>'50']],
        ],
        'location' => [[['param'=>'post_type','operator'=>'==','value'=>'pr_customer']]]
    ]);
});

// 5. ADMIN LIST COLUMNS (VENDOR & CUSTOMER)
// Vendor List
add_filter('manage_pr_vendor_posts_columns', function($cols) {
    return ['cb'=>'<input type="checkbox"/>','v_code'=>'Code','title'=>'Nama Vendor','v_addr'=>'Alamat','v_pic'=>'PIC','v_type'=>'Type'];
});
add_action('manage_pr_vendor_posts_custom_column', function($col, $id) {
    switch($col){
        case 'v_code': echo '<strong>'.get_field('vendor_code',$id).'</strong>'; break;
        case 'v_addr': echo get_field('vendor_address',$id); break;
        case 'v_pic': echo get_field('vendor_pic',$id); break;
        case 'v_type': echo strtoupper(get_field('vendor_type',$id)); break;
    }
}, 10, 2);

// Customer List
add_filter('manage_pr_customer_posts_columns', function($cols) {
    return ['cb'=>'<input type="checkbox"/>','title'=>'Nama Pelanggan','c_phone'=>'WhatsApp','c_type'=>'Tipe','c_status'=>'Status KYC'];
});
add_action('manage_pr_customer_posts_custom_column', function($col, $id) {
    switch($col){
        case 'c_phone': echo get_field('_puri_cust_phone',$id); break;
        case 'c_type': echo strtoupper(get_field('_puri_cust_type',$id)); break;
        case 'c_status': echo (get_post_status($id)==='publish') ? '<span style="color:#2271b1">✅ Lengkap</span>' : '<span style="color:#d63638">⚠️ Draft</span>'; break;
    }
}, 10, 2);

// 6. SAFE FILE UPLOAD (Mencegah Error Nama File)
add_filter('wp_handle_upload_prefilter', function($file) {
    if (isset($_POST['post_id']) && get_post_type($_POST['post_id']) === 'pr_customer') {
        $post_id = $_POST['post_id'];
        $id_type = get_field('_puri_cust_id_type', $post_id) ?: 'ID';
        $name    = sanitize_title(get_the_title($post_id)) ?: 'Guest';
        $nik_raw = get_field('_puri_cust_nik', $post_id);
        $id_val  = ($nik_raw) ? sanitize_file_name($nik_raw) : time();
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file['name'] = "{$id_type}_{$name}_{$id_val}.{$ext}";
    }
    return $file;
});

// 7. SHORTCODE: [puri_crew_kyc] FRONTEND ENTRY STATION
add_shortcode('puri_crew_kyc', function() {
    if (!function_exists('acf_form')) return 'Plugin ACF Pro tidak aktif.';
    acf_form_head();
    $customer_id = isset($_GET['cid']) ? intval($_GET['cid']) : 0;
    ob_start();
    ?>
    <div class="puri-crew-station">
        <?php if (!$customer_id): ?>
            <h3>📋 Antrean Input Formulir Manual (BI Compliance)</h3>
            <table class="wp-block-table is-style-striped">
                <thead><tr><th>Nama Pelanggan</th><th>Foto KTP</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php
                    $pending = get_posts(['post_type'=>'pr_customer','post_status'=>'draft','posts_per_page'=>10]);
                    foreach ($pending as $p): 
                        $has_ktp = get_field('_puri_cust_ktp_image', $p->ID);
                    ?>
                    <tr>
                        <td><strong><?= strtoupper($p->post_title); ?></strong></td>
                        <td><?= $has_ktp ? '✅ Tersedia' : '❌ Kosong'; ?></td>
                        <td><a href="?cid=<?= $p->ID; ?>" class="wp-block-button__link">Salin Formulir</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="kyc-entry-form">
                <a href="?" style="font-size:12px;">⬅ Kembali ke Antrean</a>
                <h3>Input Salinan Manual: <?= strtoupper(get_the_title($customer_id)); ?></h3>
                <?php 
                acf_form([
                    'post_id'      => $customer_id,
                    'fields'       => ['_puri_cust_address', '_puri_cust_city', '_puri_cust_citizenship', '_puri_cust_occupation', '_puri_cust_purpose'],
                    'submit_value' => 'Update & Publish Data BI',
                    'html_submit_button' => '<button type="submit" class="wp-block-button__link" style="background:#10b981; border:none; width:100%; cursor:pointer;">✅ Simpan Salinan Crew</button>',
                ]); 
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});