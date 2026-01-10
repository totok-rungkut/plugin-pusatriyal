<?php
/**
 * =============================================================================
 * MC 29 - Configuration Base (Advanced Profile)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     2.2.0 (Adopted Legacy mc-01 Features)
 * @author      Denmas Totok (Engineered by Gemini)
 * @description Modul Profile Native dengan dukungan Logo & Multiple Bank Accounts.
 * =============================================================================
 */

defined('ABSPATH') || exit;

// Pastikan WP Media Library tersedia
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'puri-profile') !== false) {
        wp_enqueue_media();
    }
});

function puri_render_profile_page() {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized'));

    $message = '';
    
    // 1. LOGIC SIMPAN DATA
    if (isset($_POST['puri_save_profile'])) {
        if (check_admin_referer('puri_profile_action', 'puri_nonce')) {
            
            // Simpan Data Text Dasar
            update_option('puri_comp_name', sanitize_text_field($_POST['comp_name']));
            update_option('puri_comp_legal', sanitize_text_field($_POST['comp_legal']));
            update_option('puri_comp_address', sanitize_textarea_field($_POST['comp_address']));
            update_option('puri_comp_logo', sanitize_text_field($_POST['comp_logo_id'])); // Simpan ID Lampiran

            // Simpan Data Repeater Bank (Array)
            $banks = [];
            if (isset($_POST['banks']) && is_array($_POST['banks'])) {
                foreach ($_POST['banks'] as $bank) {
                    if (!empty($bank['acc_no'])) {
                        $banks[] = [
                            'name'    => sanitize_text_field($bank['name']),
                            'acc_no'  => sanitize_text_field($bank['acc_no']),
                            'holder'  => sanitize_text_field($bank['holder']),
                        ];
                    }
                }
            }
            update_option('puri_bank_accounts', $banks);
            
            $message = '<div class="updated"><p>✅ Profil & Daftar Rekening Berhasil Diperbarui!</p></div>';
        }
    }

    // 2. AMBIL DATA
    $logo_id = get_option('puri_comp_logo', '');
    $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
    $banks = get_option('puri_bank_accounts', []);

    ?>
    <div class="wrap">
        <h1>◬ Profil Perusahaan</h1>
        <?php echo $message; ?>

        <form method="post" action="">
            <?php wp_nonce_field('puri_profile_action', 'puri_nonce'); ?>
            
            <div style="display: grid; grid-template-columns: 1fr 350px; gap: 20px; margin-top:20px;">
                
                <div class="card" style="max-width: 100%; border:1px solid #ccd0d4; box-shadow:none;">
                    
                    <h3>🖼️ Logo Institusi</h3>
                    <div class="puri-logo-uploader" style="display: flex; align-items: center; gap: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                        <div id="logo-preview-container" style="width: 100px; height: 100px; border: 2px dashed #ccc; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff;">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" style="max-width: 100%; height: auto;">
                            <?php else: ?>
                                <span style="color: #ccc; font-size: 11px;">No Logo</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <input type="hidden" name="comp_logo_id" id="comp_logo_id" value="<?php echo esc_attr($logo_id); ?>">
                            <button type="button" class="button" id="btn_upload_logo">Pilih Logo</button>
                            <button type="button" class="button" id="btn_remove_logo" style="color: #d63638;">Hapus</button>
                            <p class="description">Gunakan file .png transparan untuk hasil terbaik di struk.</p>
                        </div>
                    </div>

                    <hr>

                    <h3>🏢 Detail Entitas</h3>
                    <table class="form-table">
                        <tr>
                            <th>Nama Unit Bisnis</th>
                            <td><input name="comp_name" type="text" value="<?php echo esc_attr(get_option('puri_comp_name')); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th>Alamat Operasional</th>
                            <td><textarea name="comp_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('puri_comp_address')); ?></textarea></td>
                        </tr>
                    </table>

                    <hr>

                    <h3>🏦 Daftar Rekening Bank (Repeater)</h3>
                    <table class="wp-list-table widefat fixed striped" id="bank-repeater">
                        <thead>
                            <tr>
                                <th>Nama Bank</th>
                                <th>Nomor Rekening</th>
                                <th>Atas Nama</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($banks)): foreach ($banks as $index => $bank): ?>
                            <tr>
                                <td><input type="text" name="banks[<?php echo $index; ?>][name]" value="<?php echo esc_attr($bank['name']); ?>" class="widefat"></td>
                                <td><input type="text" name="banks[<?php echo $index; ?>][acc_no]" value="<?php echo esc_attr($bank['acc_no']); ?>" class="widefat"></td>
                                <td><input type="text" name="banks[<?php echo $index; ?>][holder]" value="<?php echo esc_attr($bank['holder']); ?>" class="widefat"></td>
                                <td><button type="button" class="button remove-row">×</button></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td><input type="text" name="banks[0][name]" class="widefat" placeholder="Contoh: BSI"></td>
                                <td><input type="text" name="banks[0][acc_no]" class="widefat"></td>
                                <td><input type="text" name="banks[0][holder]" class="widefat"></td>
                                <td><button type="button" class="button remove-row">×</button></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top: 10px;">
                        <button type="button" class="button button-secondary" id="add-bank-row">+ Tambah Rekening</button>
                    </div>

                    <p class="submit">
                        <input type="submit" name="puri_save_profile" class="button button-primary button-large" value="Simpan Perubahan">
                    </p>
                </div>

                <div class="puri-config-sidebar">
                    <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 8px;">
                        <h4>Preview Header Struk</h4>
                        <div id="thermal-preview" style="background: #f1f1f1; padding: 10px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.2; text-align: center; border: 1px solid #ddd;">
                            <div id="preview-logo"><?php if($logo_url) echo '<img src="'.$logo_url.'" style="max-width:50px; height:auto; filter: grayscale(1);"><br>'; ?></div>
                            <strong id="preview-name"><?php echo get_option('puri_comp_name') ?: 'Pusat Riyal'; ?></strong><br>
                            <span id="preview-address" style="font-size: 10px;"><?php echo get_option('puri_comp_address') ?: 'Alamat Belum Diatur'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
	
    jQuery(document).ready(function($){
        // 1. Media Uploader Logic
        $('#btn_upload_logo').click(function(e) {
            e.preventDefault();
            var image = wp.media({ title: 'Upload Logo', multiple: false }).open()
            .on('select', function(e){
                var uploaded_image = image.state().get('selection').first();
                var image_url = uploaded_image.toJSON().url;
                var image_id = uploaded_image.toJSON().id;
                $('#comp_logo_id').val(image_id);
                $('#logo-preview-container').html('<img src="'+image_url+'" style="max-width:100%;">');
                $('#preview-logo').html('<img src="'+image_url+'" style="max-width:50px; filter:grayscale(1);">');
            });
        });

        $('#btn_remove_logo').click(function() {
            $('#comp_logo_id').val('');
            $('#logo-preview-container').html('<span style="color: #ccc; font-size: 11px;">No Logo</span>');
            $('#preview-logo').html('');
        });

        // 2. Repeater Logic
        $('#add-bank-row').click(function() {
            var rowCount = $('#bank-repeater tbody tr').length;
            var newRow = `<tr>
                <td><input type="text" name="banks[${rowCount}][name]" class="widefat"></td>
                <td><input type="text" name="banks[${rowCount}][acc_no]" class="widefat"></td>
                <td><input type="text" name="banks[${rowCount}][holder]" class="widefat"></td>
                <td><button type="button" class="button remove-row">×</button></td>
            </tr>`;
            $('#bank-repeater tbody').append(newRow);
        });

        $(document).on('click', '.remove-row', function() {
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}





//=========================================/
// SEGALA SESUATU YANG BERHUBUNGAN DENGAN
//    ADVANCED CUSTOM FIELDS (ACF) PRO
//=========================================/

// Sembunyikan menu ACF di sidebar kecuali untuk user ID tertentu (developer)
add_filter('acf/settings/show_admin', function($show) {
    return (get_current_user_id() === 1); // Hanya user ID 1 yang bisa lihat menu ACF
});

// Merapihkan ACF option page
add_action('acf/input/admin_head', function() {
    ?>
    <style type="text/css">
        /* Merampingkan Padding ACF agar tidak makan tempat */
        .acf-field { padding: 12px 12px !important; }
        .acf-label label { font-weight: 600 !important; color: #2c3338; }
        
        /* Memberikan gaya pada Tab ACF agar lebih modern */
        .acf-tab-wrap.-top { background: #f0f6fb; border-radius: 5px 5px 0 0; }
        .acf-tab-group li.active a { background: #fff !important; border-bottom-color: transparent !important; }

        /* Styling Postbox ACF */
        .postbox.acf-postbox { border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    </style>
    <?php
});

/**
 * PURI UI Harmonizer - Clean & Professional Styling
 */
add_action('admin_head', function() {
    ?>
    <style>
        /* 1. Global Cleanup */
        :root {
            --puri-primary: #1a4a7c; /* Professional Navy */
            --puri-accent: #2271b1;
            --puri-bg: #f0f6fb;
            --puri-border: #dcdcde;
        }

        /* Wrap styling */
        .wrap h1.wp-heading-inline {
            font-weight: 700;
            color: #1d2327;
            letter-spacing: -0.02em;
            margin-bottom: 20px;
        }

        /* Professional Cards */
        .puri-card, .postbox, .acf-postbox {
            border-radius: 8px !important;
            border: 1px solid var(--puri-border) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03) !important;
            overflow: hidden;
        }

        .postbox .handle-order-higher, .postbox .hndle {
            background: #fff !important;
            border-bottom: 1px solid #f0f0f0 !important;
            padding: 12px 15px !important;
        }

        /* 2. ACF Field Harmonization */
        .acf-field {
            border-top: 1px solid #f9f9f9 !important;
            padding: 15px 20px !important;
        }

        .acf-label label {
            font-weight: 600 !important;
            color: #3c434a !important;
            margin-bottom: 8px !important;
            display: block;
        }

        /* Styling ACF Tabs agar seperti navigasi App */
        .acf-tab-wrap {
            background: #f8fafc !important;
            border-bottom: 1px solid var(--puri-border) !important;
        }

        .acf-tab-group {
            border: none !important;
            padding: 0 10px !important;
        }

        .acf-tab-group li a {
            background: transparent !important;
            border: none !important;
            color: #64748b !important;
            font-weight: 500 !important;
            padding: 12px 20px !important;
            transition: all 0.2s;
        }

        .acf-tab-group li.active a {
            color: var(--puri-primary) !important;
            box-shadow: inset 0 -2px 0 var(--puri-primary) !important;
        }

        /* 3. Buttons Refinement */
        .button-primary {
            background: var(--puri-primary) !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 0 20px !important;
            height: 36px !important;
            line-height: 36px !important;
            box-shadow: 0 2px 4px rgba(26, 74, 124, 0.2) !important;
            text-shadow: none !important;
        }

        .button-primary:hover {
            background: #133a63 !important;
        }

        /* 4. Sidebar Nav Cleanup */
        #toplevel_page_puri-master .wp-menu-image img {
            padding-top: 0 !important;
        }
        
        /* Merapikan Form Tables */
        .form-table th {
            font-weight: 600;
            color: #50575e;
            width: 220px;
        }
        
        input[type="text"], input[type="number"], select, textarea {
            border-radius: 6px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 6px 12px !important;
            box-shadow: none !important;
        }

        input[type="text"]:focus {
            border-color: var(--puri-accent) !important;
            box-shadow: 0 0 0 1px var(--puri-accent) !important;
        }
    </style>
    <?php
});

/**
 * PURI UI Decrudder - Menghilangkan noise di halaman PURI
 */
add_action('admin_head', function() {
    $screen = get_current_screen();
    // Hanya aktifkan "Pembersihan" jika user berada di menu PURI
    if ( strpos($screen->id, 'puri') !== false ) {
        ?>
        <style>
            /* Sembunyikan Notifikasi dari plugin lain yang mengganggu */
            .notice:not(.puri-notice), .updated:not(.puri-notice), .error:not(.puri-notice) {
                display: none !important;
            }
            
            /* Sembunyikan tombol "Screen Options" dan "Help" agar clean */
            #screen-meta-links { display: none; }
            
            /* Footer Cleanup */
            #wpfooter { display: none; }
            #wpbody-content { padding-bottom: 50px; }
        </style>
        <?php
    }
});

