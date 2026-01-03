<?php
/**
 * MC 02 - Data Model: Items & Customers (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Define ACF field groups for Item SKU (multi-type), Package recipes (bundle), and Customer KYC.
 *  - Keep field structure compatible with previous versions so SQL sync and engine logic continue to work.
 *
 * Improvements in 6.0.1:
 *  - Strong guard: do nothing if ACF functions are not available (prevents fatal errors).
 *  - Added inline comments documenting fields and expected types.
 *  - Add acf/save_post hook to sanitize key fields (item_sku_code, denom_value, sell_rate) on save.
 *  - Maintain original field keys and conditional logic to keep UX unchanged.
 *
 * Notes:
 *  - This file only registers ACF fields and performs light sanitization on save.
 *  - Business logic (SQL sync, engine) remains in MC_00 / MC_01 / MC_03.
 */

defined('ABSPATH') || exit;

/**
 * Register ACF Field Groups for Master Item (pr_item)
 * and keep structure compatible with previous releases.
 */
add_action('acf/init', function () {

    // If ACF isn't active, exit gracefully.
    if (!function_exists('acf_add_local_field_group') || !function_exists('acf_add_options_page')) {
        // ACF not available: do not attempt to register fields.
        return;
    }

    /**
     * Field group: Konfigurasi Item & Paket
     * - Applied to post_type = pr_item (Master SKU)
     * - Keeps the same keys/names as earlier to ensure SQL sync (MC_00) still works
     */
    acf_add_local_field_group(array(
        'key' => 'group_puri_item_detail',
        'title' => 'Konfigurasi Item & Paket',
        'fields' => array(
            // 1. KODE SKU (unik, sinkron ke SQL)
            array(
                'key' => 'f_item_sku',
                'label' => 'Kode SKU',
                'name' => 'item_sku_code',
                'type' => 'text',
                'required' => 1,
                'wrapper' => array('width' => '20'),
                'instructions' => 'Kode SKU unik untuk sinkronisasi ke tabel master SQL. Tanpa spasi; uppercase dianjurkan.',
            ),

            // 2. TIPE ITEM
            array(
                'key' => 'f_item_type',
                'label' => 'Tipe Item',
                'name' => 'type',
                'type' => 'select',
                'choices' => array(
                    'currency' => 'Valas (SAR)',
                    'goods'    => 'Barang Retail',
                    'package'  => 'Paket Bundling'
                ),
                'wrapper' => array('width' => '18'),
            ),

            // 2.B Metode Harga Paket (hanya tampil jika tipe == package)
            array(
                'key' => 'f_bundle_mode',
                'label' => 'Metode Harga Paket',
                'name' => 'bundle_pricing_mode',
                'type' => 'select',
                'choices' => array(
                    'flat'    => 'Type A: Flat IDR (Override Rate)',
                    'dynamic' => 'Type B: Dinamis SAR (Sum of Component Rates)'
                ),
                'conditional_logic' => array(
                    array(
                        array('field' => 'f_item_type', 'operator' => '==', 'value' => 'package')
                    )
                ),
                'wrapper' => array('width' => '32'),
            ),

            // 3. DENOM (nilai pecahan, untuk Valas)
            array(
                'key' => 'f_item_denom',
                'label' => 'Nilai Denom',
                'name' => 'denom_value',
                'type' => 'number',
                'default_value' => 1,
                'instructions' => 'Mis: 1, 5, 10, 50, dll. Hanya relevan untuk tipe currency.',
                'conditional_logic' => array(
                    array(
                        array('field' => 'f_item_type', 'operator' => '==', 'value' => 'currency')
                    )
                ),
                'wrapper' => array('width' => '20'),
            ),

            // 4. KURS JUAL
            array(
                'key' => 'f_item_rate',
                'label' => 'Kurs Jual (Rp)',
                'name' => 'sell_rate',
                'type' => 'number',
                'wrapper' => array('width' => '20'),
                'instructions' => 'Harga jual per pecahan (denom). Untuk paket, gunakan mode harga paket sesuai kebutuhan.',
            ),

            // 5. FOTO VISUAL (pilihan)
            array(
                'key' => 'f_item_img',
                'label' => 'Foto Visual',
                'name' => 'item_image',
                'type' => 'image',
                'return_format' => 'url',
                'wrapper' => array('width' => '40'),
                'instructions' => 'URL/attachment image; ditampilkan di katalog/publikasi.',
            ),

            // 6. REPEATER BUNDLING (komposisi paket)
            array(
                'key' => 'f_item_pkg',
                'label' => 'Komposisi Paket Bundling',
                'name' => 'package_contents',
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => 'Tambah Item ke Paket',
                'conditional_logic' => array(
                    array(
                        array('field' => 'f_item_type', 'operator' => '==', 'value' => 'package')
                    )
                ),
                'sub_fields' => array(
                    array(
                        'key' => 'p_item_id',
                        'label' => 'Pilih Item',
                        'name' => 'p_item_ref',
                        'type' => 'post_object',
                        'post_type' => array('pr_item'),
                        'return_format' => 'id',
                        'instructions' => 'Pilih komponen item (referensi post pr_item).'
                    ),
                    array(
                        'key' => 'p_item_qty',
                        'label' => 'Qty',
                        'name' => 'p_qty',
                        'type' => 'number',
                        'default_value' => 1,
                        'instructions' => 'Jumlah komponen per paket (dalam satuan yang sama dengan laci — biasanya lembar/pcs).'
                    ),
                ),
            ),
        ),
        'location' => array(
            array(
                array('param' => 'post_type', 'operator' => '==', 'value' => 'pr_item')
            )
        ),
    ));

    /**
     * Field group: Profil KYC Pelanggan (pr_customer)
     * - Minimal changes to preserve UX and stored field keys
     */
    acf_add_local_field_group(array(
        'key' => 'group_puri_cust_profile',
        'title' => '🧾 Profil KYC Pelanggan',
        'fields' => array(
            array('key' => 'c_code', 'label' => 'Kode Customer', 'name' => 'customer_code', 'type' => 'text', 'wrapper' => array('width' => '25')),
            array('key' => 'c_nik', 'label' => 'NIK (KTP) *Wajib', 'name' => 'cust_nik', 'type' => 'text', 'required' => 1, 'wrapper' => array('width' => '25'), 'instructions' => 'NIK 16 digit (hanya angka).'),
            array('key' => 'c_phone', 'label' => 'WhatsApp', 'name' => 'cust_phone', 'type' => 'text', 'wrapper' => array('width' => '25')),
            array('key' => 'c_city', 'label' => 'Kota', 'name' => 'cust_city', 'type' => 'text', 'wrapper' => array('width' => '25')),
            array('key' => 'c_address', 'label' => 'Alamat Sesuai KTP', 'name' => 'cust_address', 'type' => 'textarea', 'rows' => 2),
            array('key' => 'c_ktp_img', 'label' => 'Foto KTP (Image)', 'name' => 'cust_ktp_image', 'type' => 'image', 'return_format' => 'url', 'wrapper' => array('width' => '50')),
            array('key' => 'c_type', 'label' => 'Tipe Pelanggan', 'name' => 'cust_type', 'type' => 'select',
                'choices' => array('retail' => 'Umum', 'member' => 'Member', 'agent' => 'Agen'),
                'wrapper' => array('width' => '50')
            ),
            array('key' => 'c_snapshot', 'label' => 'Snapshot Performa (JSON)', 'name' => 'customer_stats_json', 'type' => 'textarea',
                'instructions' => 'Aggregat: total_trx, total_riyal, repeat_order_count. Field read-only untuk optimasi laporan.',
                'readonly' => 1),
        ),
        'location' => array(
            array(
                array('param' => 'post_type', 'operator' => '==', 'value' => 'pr_customer')
            )
        ),
    ));
});

/**
 * Light sanitization on ACF save for pr_item
 * - Ensures item_sku_code is sanitized and uppercased; denom_value & sell_rate coerced to numeric defaults
 * - This is intentionally conservative: we do not change UX; we only sanitize raw input that may break downstream SQL sync
 *
 * Note: More complex validation (unique SKU enforcement, blocking save) can be added later if desired.
 */
add_action('acf/save_post', function ($post_id) {
    // Only run for pr_item posts (do not interfere with options or other CPTs)
    if (get_post_type($post_id) !== 'pr_item') return;

    // Avoid running during autosave / revision
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;

    // Sanitize SKU
    $sku_raw = get_field('item_sku_code', $post_id);
    if ($sku_raw !== null) {
        // Remove whitespace, uppercase, remove non-printable chars
        $sku_clean = strtoupper(preg_replace('/\s+/', '', sanitize_text_field($sku_raw)));
        update_field('item_sku_code', $sku_clean, $post_id);
    }

    // Denom: ensure integer >=1
    $denom = get_field('denom_value', $post_id);
    if ($denom === null || $denom === '') {
        update_field('denom_value', 1, $post_id);
    } else {
        update_field('denom_value', max(1, intval($denom)), $post_id);
    }

    // Sell rate: numeric non-negative
    $rate = get_field('sell_rate', $post_id);
    if ($rate === null || $rate === '') {
        update_field('sell_rate', 0, $post_id);
    } else {
        update_field('sell_rate', max(0, floatval($rate)), $post_id);
    }

    // Optional: ensure package recipe sub_fields are integers
    $package = get_field('package_contents', $post_id);
    if (is_array($package)) {
        foreach ($package as $idx => $row) {
            if (isset($row['p_qty'])) {
                $new_qty = max(1, intval($row['p_qty']));
                // update the sub field value (ACF specific)
                // Using update_sub_field would require field path; to be conservative, set the whole package back:
                $package[$idx]['p_qty'] = $new_qty;
            }
        }
        update_field('package_contents', $package, $post_id);
    }

}, 20, 1);

/**
 * Provide admin notice if ACF is missing (helps admin discover dependency)
 */
add_action('admin_notices', function () {
    if (!function_exists('acf_add_local_field_group')) {
        echo '<div class="notice notice-error"><p><strong>ACF Pro tidak aktif:</strong> Modul Data Model (MC 02) membutuhkan Advanced Custom Fields Pro untuk menampilkan field item & customer. Aktifkan ACF untuk menggunakan fitur ini.</p></div>';
    }
});