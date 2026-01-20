<?php
/**
 * ============================================================================
 * MC-29 - CONFIGURATION BASE (ULTIMATE EDITION)
 * ============================================================================
 * @package     Puri_Money_Changer
 * @version     3.1.3 (ES6 + Inventory Locations)
 */

defined('ABSPATH') || exit;

class Puri_Configuration_Base {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'puri-profile') !== false) {
            wp_enqueue_media();
            wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        $message = '';

        // =====================================================================
        // SAVE HANDLER
        // =====================================================================
        if (isset($_POST['puri_save_profile'])) {
            check_admin_referer('puri_profile_action', 'puri_nonce');

            // 1. Save Basic Info
            update_option('puri_comp_name', sanitize_text_field($_POST['comp_name'] ?? ''));
            update_option('puri_comp_legal', sanitize_text_field($_POST['comp_legal'] ?? ''));
            update_option('puri_comp_phone', sanitize_text_field($_POST['comp_phone'] ?? ''));
            update_option('puri_comp_email', sanitize_email($_POST['comp_email'] ?? ''));
            update_option('puri_comp_address', sanitize_textarea_field($_POST['comp_address'] ?? ''));
            update_option('puri_comp_logo', sanitize_text_field($_POST['comp_logo_id'] ?? ''));

            // 2. Save Bank Accounts (Repeater)
            $banks = $_POST['banks'] ?? [];
            $clean_banks = [];
            if (is_array($banks)) {
                foreach ($banks as $bank) {
                    if (!empty($bank['acc_no'])) {
                        $clean_banks[] = [
                            'name'   => sanitize_text_field($bank['name']),
                            'acc_no' => sanitize_text_field($bank['acc_no']),
                            'holder' => sanitize_text_field($bank['holder']),
                        ];
                    }
                }
            }
            update_option('puri_bank_accounts', $clean_banks);

            // 3. Save Inventory Locations (New Repeater)
            $locs = $_POST['puri_inv_locations'] ?? [];
            $clean_locs = [];
            if (is_array($locs)) {
foreach ($locs as $loc) {
    $id = !empty($loc['id']) ? sanitize_title($loc['id']) : sanitize_title($loc['name']);
    if (!empty($loc['name'])) {
        $clean_locs[] = [
            'id'   => $id,
            'name' => sanitize_text_field($loc['name']),
        ];
    }
}
            }
            update_option('puri_inv_locations', $clean_locs);

            $message = '<div class="notice notice-success is-dismissible"><p>Konfigurasi berhasil disimpan!</p></div>';
        }

        // =====================================================================
        // LOAD DATA
        // =====================================================================
        $logo_id   = get_option('puri_comp_logo', '');
        $logo_url  = $logo_id ? wp_get_attachment_url($logo_id) : '';
        $banks     = get_option('puri_bank_accounts', []);
		$locations = get_option('puri_inv_locations', []);

        ?>
        <style>
            :root {
                --puri-primary: #2563eb;
                --puri-bg: #f3f4f6;
                --panel-bg: #ffffff;
                --border-color: #e5e7eb;
                --text-main: #1f2937;
                --text-muted: #6b7280;
            }

            .puri-config-wrapper { margin-top: 20px; font-family: 'Inter', sans-serif; }
            .config-container { display: grid; grid-template-columns: 1fr 350px; gap: 25px; align-items: start; }
            
            /* Panels */
            .config-panel { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .panel-header { padding: 15px 20px; background: #fafafa; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-weight: 600; color: var(--text-main); }
            .panel-body { padding: 20px; }

            /* Logo Uploader */
            .logo-uploader-container { display: flex; gap: 20px; align-items: center; }
            .logo-preview-box { width: 120px; height: 120px; border: 2px dashed #ccc; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f9fafb; overflow: hidden; }
            .logo-preview-box img { max-width: 100%; height: auto; }

            /* Form Grid */
            .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
            .full-width { grid-column: span 2; }
            .puri-input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }

            /* Repeater Styling */
            .bank-account-item, .location-row { background: #f9fafb; padding: 15px; border: 1px solid #eee; border-radius: 8px; margin-bottom: 10px; display: flex; gap: 10px; align-items: flex-end; }
            .remove-row, .remove-loc { color: #ef4444; border: 1px solid #fee2e2; background: #fff; cursor: pointer; padding: 5px 10px; border-radius: 4px; }

            /* Thermal Preview (The Receipt) */
            .thermal-preview { background: #555; padding: 20px; border-radius: 10px; position: sticky; top: 50px; }
            .receipt-paper { background: white; width: 100%; min-height: 400px; padding: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); font-family: 'Courier New', monospace; font-size: 12px; color: #000; }
            .receipt-header { text-align: center; border-bottom: 1px dashed #ccc; padding-bottom: 10px; margin-bottom: 10px; }
            #preview-logo img { max-width: 60px; filter: grayscale(1); }
            .receipt-divider { border-top: 1px dashed #000; margin: 10px 0; }
            
            /* Notification */
            .puri-notification { position: fixed; top: 100px; right: 20px; background: #fff; border-left: 5px solid var(--puri-primary); padding: 15px; box-shadow: 0 10px 15px rgba(0,0,0,0.1); z-index: 9999; border-radius: 4px; transition: 0.4s; transform: translateX(120%); }
        </style>

        <div class="wrap puri-config-wrapper">
            <h1><i class="fa-solid fa-gear"></i> System Configuration</h1>
            <?php echo $message; ?>

            <form method="post" action="" class="puri-config-form">
                <?php wp_nonce_field('puri_profile_action', 'puri_nonce'); ?>
                
                <div class="config-container">
                    <div class="config-main">
                        
                        <div class="config-panel">
                            <div class="panel-header"><i class="fa-solid fa-building"></i> Company Profile</div>
                            <div class="panel-body">
                                <div class="logo-uploader-container" style="margin-bottom: 20px;">
                                    <div class="logo-preview-box" id="logo-preview-container">
                                        <?php if ($logo_url): ?>
                                            <img src="<?php echo esc_url($logo_url); ?>">
                                        <?php else: ?>
                                            <span>No Logo</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="logo-controls">
                                        <input type="hidden" name="comp_logo_id" id="comp_logo_id" value="<?php echo esc_attr($logo_id); ?>">
                                        <button type="button" class="button" id="btn_upload_logo">Pilih Logo</button>
                                        <button type="button" class="button" id="btn_remove_logo">Hapus</button>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label>Nama Bisnis</label>
                                        <input type="text" name="comp_name" id="comp_name" class="puri-input" value="<?php echo esc_attr(get_option('puri_comp_name')); ?>">
                                    </div>
                                    <div class="form-group full-width">
                                        <label>Alamat Lengkap</label>
                                        <textarea name="comp_address" id="comp_address" class="puri-input" rows="3"><?php echo esc_textarea(get_option('puri_comp_address')); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="config-panel">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-map-location-dot"></i> Inventory Locations</span>
                                <button type="button" class="button button-secondary" id="add-loc">Tambah Lokasi</button>
                            </div>
                            <div class="panel-body" id="location-repeater">
<?php foreach ($locations as $index => $loc): ?>
<div class="location-row">
  <div style="flex:2">
    <label style="font-size:11px">Nama Lokasi</label>
    <input type="text" name="puri_inv_locations[<?php echo $index; ?>][name]" 
           class="puri-input loc-name-field" 
           value="<?php echo esc_attr($loc['name']); ?>" 
           placeholder="Nama Lokasi">
  </div>
  <div style="flex:1">
    <label style="font-size:11px">Slug ID</label>
    <input type="text" name="puri_inv_locations[<?php echo $index; ?>][id]" 
           class="puri-input loc-id-field" 
           value="<?php echo esc_attr($loc['id']); ?>" 
           placeholder="slug-id">
  </div>
  <button type="button" class="remove-loc">❌</button>
</div>
<?php endforeach; ?>

                            </div>
                        </div>

                        <div class="config-panel">
                            <div class="panel-header">
                                <span><i class="fa-solid fa-university"></i> Bank Accounts</span>
                                <button type="button" class="button button-secondary" id="add-bank">Tambah Bank</button>
                            </div>
                            <div class="panel-body" id="bank-repeater">
                                <?php foreach ($banks as $index => $bank): ?>
                                <div class="bank-account-item">
                                    <input type="text" name="banks[<?php echo $index; ?>][name]" class="puri-input" value="<?php echo esc_attr($bank['name']); ?>" placeholder="Nama Bank">
                                    <input type="text" name="banks[<?php echo $index; ?>][acc_no]" class="puri-input account-number" value="<?php echo esc_attr($bank['acc_no']); ?>" placeholder="No. Rekening">
                                    <input type="text" name="banks[<?php echo $index; ?>][holder]" class="puri-input" value="<?php echo esc_attr($bank['holder']); ?>" placeholder="Atas Nama">
                                    <button type="button" class="remove-row">❌</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" name="puri_save_profile" class="button button-primary button-hero">Simpan Perubahan</button>
                        </div>
                    </div>

                    <div class="config-side">
                        <div class="thermal-preview">
                            <div class="receipt-paper">
                                <div class="receipt-header">
                                    <div id="preview-logo"><?php if($logo_url) echo '<img src="'.$logo_url.'">'; ?></div>
                                    <div id="preview-name" style="font-weight:bold; font-size:14px; margin-top:5px;"><?php echo get_option('puri_comp_name', 'Puri Money Changer'); ?></div>
                                    <div id="preview-address" style="font-size:10px;"><?php echo get_option('puri_comp_address'); ?></div>
                                </div>
                                <div style="text-align:center; margin:10px 0;">*** STRUK TRANSAKSI ***</div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Tgl: <?php echo date('d/m/Y'); ?></span>
                                    <span>ID: #123456</span>
                                </div>
                                <div class="receipt-divider"></div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>JUAL SAR 100 @4.350</span>
                                    <span>435.000</span>
                                </div>
                                <div class="receipt-divider"></div>
                                <div style="text-align:right; font-weight:bold;">TOTAL IDR 435.000</div>
                                <div class="receipt-divider"></div>
                                <div style="text-align:center; font-size:10px;">Terima Kasih Atas Kunjungan Anda</div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <script type="text/javascript">
        /**
         * PURI CONFIG MANAGER ES6
         */
        class PuriConfigManager {
            constructor() {
                this.mediaUploader = null;
                this.bankIndex = <?php echo count($banks); ?>;
                jQuery(document).ready(($) => this.init($));
            }

            init($) {
                this.initLogo($);
                this.initLocations($);
                this.initBanks($);
                this.initLivePreview($);
                console.log('🚀 PURI Config ES6 Ready');
            }

            initLogo($) {
                $('#btn_upload_logo').on('click', (e) => {
                    e.preventDefault();
                    if (this.mediaUploader) { this.mediaUploader.open(); return; }
                    this.mediaUploader = wp.media({ title: 'Pilih Logo', button: { text: 'Gunakan Logo' }, multiple: false, library: { type: 'image' } });
                    this.mediaUploader.on('select', () => {
                        const attachment = this.mediaUploader.state().get('selection').first().toJSON();
                        $('#comp_logo_id').val(attachment.id);
                        $('#logo-preview-container').html(`<img src="${attachment.url}">`);
                        $('#preview-logo').html(`<img src="${attachment.url}" style="max-width:60px; filter:grayscale(1);">`);
                        this.showNotification('Logo berhasil diunggah', 'success');
                    });
                    this.mediaUploader.open();
                });

                $('#btn_remove_logo').on('click', (e) => {
                    if(!confirm('Hapus logo?')) return;
                    $('#comp_logo_id').val('');
                    $('#logo-preview-container').html('<span>No Logo</span>');
                    $('#preview-logo').html('');
                });
            }

            initLocations($) {
                $('#add-loc').on('click', () => {
                    const idx = Date.now();
                    const html = `
                        <div class="location-row">
                            <div style="flex:2"><input type="text" name="puri_inv_locations[${idx}][name]" class="puri-input loc-name-field" placeholder="Nama Lokasi Baru"></div>
                            <div style="flex:1"><input type="text" name="puri_inv_locations[${idx}][id]" class="puri-input loc-id-field" style="background:#f0f0fc"></div>
                            <button type="button" class="remove-loc">❌</button>
                        </div>`;
                    $('#location-repeater').append(html);
                });

    // 👉 Di sini kamu taruh blok auto‑slug
    $(document).on('input', '.loc-name-field', function() {
        const slug = this.value.toLowerCase()
            .replace(/[^a-z0-9 ]/g, '')
            .replace(/\s+/g, '-');
        const idField = $(this).closest('.location-row').find('.loc-id-field');
        if (!idField.val()) { // hanya isi otomatis kalau kosong
            idField.val(slug);
        }
    });

    // Handler hapus lokasi

                $(document).on('input', '.loc-name-field', function() {
                    const slug = this.value.toLowerCase().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, '-');
                    $(this).closest('.location-row').find('.loc-id-field').val(slug);
                });

                $(document).on('click', '.remove-loc', function() { $(this).closest('.location-row').remove(); });
            }

            initBanks($) {
                $('#add-bank').on('click', () => {
                    const idx = this.bankIndex++;
                    const html = `
                        <div class="bank-account-item">
                            <input type="text" name="banks[${idx}][name]" class="puri-input" placeholder="Nama Bank">
                            <input type="text" name="banks[${idx}][acc_no]" class="puri-input account-number" placeholder="No. Rekening">
                            <input type="text" name="banks[${idx}][holder]" class="puri-input" placeholder="Atas Nama">
                            <button type="button" class="remove-row">❌</button>
                        </div>`;
                    $('#bank-repeater').append(html);
                });
                $(document).on('click', '.remove-row', function() { $(this).closest('.bank-account-item').remove(); });
                $(document).on('input', '.account-number', function() { this.value = this.value.replace(/\D/g, ''); });
            }

            initLivePreview($) {
                $('#comp_name').on('input', function() { $('#preview-name').text(this.value || 'Puri Money Changer'); });
                $('#comp_address').on('input', function() { $('#preview-address').text(this.value); });
            }

            showNotification(msg, type) {
                const color = type === 'success' ? '#10b981' : '#3b82f6';
                const el = jQuery('<div>', { class: 'puri-notification', html: msg, css: { borderLeftColor: color } }).appendTo('body');
                setTimeout(() => el.css('transform', 'translateX(0)'), 100);
                setTimeout(() => { el.css('transform', 'translateX(120%)'); setTimeout(() => el.remove(), 400); }, 3000);
            }
        }
        new PuriConfigManager();
        </script>
        <?php
    }
}

new Puri_Configuration_Base();



// Tambahkan ini di paling bawah mc-29 (di luar class)
if (!function_exists('puri_render_profile_page')) {
    function puri_render_profile_page() {
        $manager = new Puri_Configuration_Base();
        $manager->render_page();
    }
}