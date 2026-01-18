<?php
/**
 * ============================================================================
 * MC-29 - CONFIGURATION BASE (UX REFACTORED)
 * ============================================================================
 * 
 * @package     Puri_Money_Changer
 * @subpackage  Configuration
 * @version     3.0.0 (UX Refactored - Professional Look)
 * @author      Denmas Totok (Architecture)
 * @refactor    Claude AI Assistant (UX Enhancement)
 * @since       2026-01-15
 * 
 * ============================================================================
 * CHANGELOG v3.0.0
 * ============================================================================
 * [2026-01-15] MAJOR UX REFACTOR
 * - Modern card-based layout inspired by MC-05
 * - Enhanced logo uploader with drag & drop
 * - Better bank account repeater with visual feedback
 * - Live thermal preview with realistic styling
 * - Improved mobile responsiveness
 * - Professional color scheme and typography
 * 
 * ============================================================================
 * FEATURES
 * ============================================================================
 * 1. Company Profile Management
 *    - Logo upload with preview
 *    - Business name & address
 *    - Legal entity information
 * 
 * 2. Bank Account Manager
 *    - Multiple accounts support
 *    - Repeater interface with add/remove
 *    - Auto-indexing and validation
 * 
 * 3. Thermal Receipt Preview
 *    - Real-time preview
 *    - Monospace font simulation
 *    - Logo grayscale conversion
 * 
 * ============================================================================
 */

defined('ABSPATH') || exit;

class Puri_Configuration_Base {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue Assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'puri-profile') !== false) {
            wp_enqueue_media();
            wp_enqueue_style('fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
        }
    }

    /**
     * Render Configuration Page
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'puri'));
        }

        $message = '';

        // =====================================================================
        // SAVE LOGIC
        // =====================================================================
        if (isset($_POST['puri_save_profile'])) {
            if (check_admin_referer('puri_profile_action', 'puri_nonce')) {

                // Basic Info
                $comp_name    = sanitize_text_field($_POST['comp_name'] ?? '');
                $comp_legal   = sanitize_text_field($_POST['comp_legal'] ?? '');
                $comp_address = sanitize_textarea_field($_POST['comp_address'] ?? '');
                $comp_phone   = sanitize_text_field($_POST['comp_phone'] ?? '');
                $comp_email   = sanitize_email($_POST['comp_email'] ?? '');
                $comp_logo_id = sanitize_text_field($_POST['comp_logo_id'] ?? '');

                update_option('puri_comp_name', $comp_name);
                update_option('puri_comp_legal', $comp_legal);
                update_option('puri_comp_address', $comp_address);
                update_option('puri_comp_phone', $comp_phone);
                update_option('puri_comp_email', $comp_email);
                update_option('puri_comp_logo', $comp_logo_id);

                // Bank Accounts
                $banks = [];
                if (isset($_POST['banks']) && is_array($_POST['banks'])) {
                    foreach (array_values($_POST['banks']) as $bank) {
                        $name   = sanitize_text_field($bank['name'] ?? '');
                        $acc_no = sanitize_text_field($bank['acc_no'] ?? '');
                        $holder = sanitize_text_field($bank['holder'] ?? '');

                        if ($acc_no !== '') {
                            $banks[] = [
                                'name'   => $name,
                                'acc_no' => $acc_no,
                                'holder' => $holder,
                            ];
                        }
                    }
                }
                update_option('puri_bank_accounts', $banks);

                $message = '<div class="notice notice-success is-dismissible"><p><i class="fa-solid fa-check-circle"></i> <strong>Profile updated successfully!</strong></p></div>';
            }
        }

        // =====================================================================
        // LOAD DATA
        // =====================================================================
        $logo_id  = get_option('puri_comp_logo', '');
        $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
        $banks    = get_option('puri_bank_accounts', []);
        if (!is_array($banks)) $banks = [];

        ?>
        <div class="wrap puri-config-wrapper">
            <h1 class="wp-heading-inline">
                <i class="fa-solid fa-building"></i> Company Profile
                <span class="version-badge">v3.0.0</span>
            </h1>
            <p class="description">Manage your business information, logo, and bank accounts for receipts and reports.</p>
            <hr class="wp-header-end">

            <?php echo $message; ?>

            <form method="post" action="" class="puri-config-form">
                <?php wp_nonce_field('puri_profile_action', 'puri_nonce'); ?>

                <div class="config-container">
                    
                    <!-- LEFT COLUMN: Main Configuration -->
                    <div class="config-main">
                        
                        <!-- LOGO SECTION -->
                        <div class="config-panel panel-logo">
                            <div class="panel-header">
                                <i class="fa-solid fa-image"></i> Company Logo
                            </div>
                            <div class="panel-body">
                                <div class="logo-uploader-container">
                                    <div class="logo-preview-box" id="logo-preview-container">
                                        <?php if ($logo_url): ?>
                                            <img src="<?php echo esc_url($logo_url); ?>" alt="Company Logo">
                                        <?php else: ?>
                                            <div class="logo-placeholder">
                                                <i class="fa-solid fa-image"></i>
                                                <span>No Logo</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="logo-controls">
                                        <input type="hidden" name="comp_logo_id" id="comp_logo_id" value="<?php echo esc_attr($logo_id); ?>">
                                        <button type="button" class="button button-primary" id="btn_upload_logo">
                                            <i class="fa-solid fa-upload"></i> Choose Logo
                                        </button>
                                        <button type="button" class="button button-secondary" id="btn_remove_logo">
                                            <i class="fa-solid fa-trash"></i> Remove
                                        </button>
                                        <p class="description">
                                            <i class="fa-solid fa-info-circle"></i> 
                                            Recommended: PNG with transparent background, 500x500px
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- COMPANY INFO SECTION -->
                        <div class="config-panel panel-info">
                            <div class="panel-header">
                                <i class="fa-solid fa-building"></i> Business Information
                            </div>
                            <div class="panel-body">
                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label for="comp_name">
                                            Business Name <span class="required">*</span>
                                        </label>
                                        <input type="text" id="comp_name" name="comp_name" 
                                               value="<?php echo esc_attr(get_option('puri_comp_name')); ?>" 
                                               class="puri-input" required>
                                        <span class="field-hint">This will appear on receipts and reports</span>
                                    </div>

                                    <div class="form-group">
                                        <label for="comp_legal">Legal Entity Name</label>
                                        <input type="text" id="comp_legal" name="comp_legal" 
                                               value="<?php echo esc_attr(get_option('puri_comp_legal')); ?>" 
                                               class="puri-input">
                                    </div>

                                    <div class="form-group">
                                        <label for="comp_phone">Phone Number</label>
                                        <input type="tel" id="comp_phone" name="comp_phone" 
                                               value="<?php echo esc_attr(get_option('puri_comp_phone')); ?>" 
                                               class="puri-input" placeholder="+62 xxx xxxx xxxx">
                                    </div>

                                    <div class="form-group full-width">
                                        <label for="comp_email">Email Address</label>
                                        <input type="email" id="comp_email" name="comp_email" 
                                               value="<?php echo esc_attr(get_option('puri_comp_email')); ?>" 
                                               class="puri-input" placeholder="info@company.com">
                                    </div>

                                    <div class="form-group full-width">
                                        <label for="comp_address">Business Address <span class="required">*</span></label>
                                        <textarea id="comp_address" name="comp_address" rows="3" 
                                                  class="puri-input" required><?php echo esc_textarea(get_option('puri_comp_address')); ?></textarea>
                                        <span class="field-hint">Full address including city and postal code</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- BANK ACCOUNTS SECTION -->
                        <div class="config-panel panel-banks">
                            <div class="panel-header">
                                <div class="header-left">
                                    <i class="fa-solid fa-university"></i> Bank Accounts
                                </div>
                                <div class="header-right">
                                    <button type="button" class="button button-secondary" id="add-bank-row">
                                        <i class="fa-solid fa-plus"></i> Add Account
                                    </button>
                                </div>
                            </div>
                            <div class="panel-body">
                                <div class="info-box info-primary" style="margin-bottom: 15px;">
                                    <i class="fa-solid fa-info-circle"></i>
                                    <span>Bank accounts will be displayed on customer receipts for payment reference.</span>
                                </div>

                                <div class="bank-accounts-list" id="bank-repeater">
                                    <?php if (!empty($banks)): ?>
                                        <?php foreach ($banks as $index => $bank): ?>
                                            <div class="bank-account-item">
                                                <div class="bank-drag-handle">
                                                    <i class="fa-solid fa-grip-vertical"></i>
                                                </div>
                                                <div class="bank-fields">
                                                    <div class="bank-field">
                                                        <label>Bank Name</label>
                                                        <input type="text" 
                                                               name="banks[<?php echo (int)$index; ?>][name]" 
                                                               value="<?php echo esc_attr($bank['name'] ?? ''); ?>" 
                                                               class="puri-input" 
                                                               placeholder="e.g. Bank Syariah Indonesia">
                                                    </div>
                                                    <div class="bank-field">
                                                        <label>Account Number</label>
                                                        <input type="text" 
                                                               name="banks[<?php echo (int)$index; ?>][acc_no]" 
                                                               value="<?php echo esc_attr($bank['acc_no'] ?? ''); ?>" 
                                                               class="puri-input account-number" 
                                                               placeholder="1234567890">
                                                    </div>
                                                    <div class="bank-field">
                                                        <label>Account Holder</label>
                                                        <input type="text" 
                                                               name="banks[<?php echo (int)$index; ?>][holder]" 
                                                               value="<?php echo esc_attr($bank['holder'] ?? ''); ?>" 
                                                               class="puri-input" 
                                                               placeholder="Account owner name">
                                                    </div>
                                                </div>
                                                <button type="button" class="bank-remove-btn remove-row" title="Remove account">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="bank-account-item">
                                            <div class="bank-drag-handle">
                                                <i class="fa-solid fa-grip-vertical"></i>
                                            </div>
                                            <div class="bank-fields">
                                                <div class="bank-field">
                                                    <label>Bank Name</label>
                                                    <input type="text" name="banks[0][name]" class="puri-input" placeholder="e.g. Bank Syariah Indonesia">
                                                </div>
                                                <div class="bank-field">
                                                    <label>Account Number</label>
                                                    <input type="text" name="banks[0][acc_no]" class="puri-input account-number" placeholder="1234567890">
                                                </div>
                                                <div class="bank-field">
                                                    <label>Account Holder</label>
                                                    <input type="text" name="banks[0][holder]" class="puri-input" placeholder="Account owner name">
                                                </div>
                                            </div>
                                            <button type="button" class="bank-remove-btn remove-row" title="Remove account">
                                                <i class="fa-solid fa-times"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="empty-state" id="bank-empty-state" style="display: none;">
                                    <i class="fa-solid fa-inbox"></i>
                                    <p>No bank accounts added yet</p>
                                    <button type="button" class="button button-primary" id="add-first-bank">
                                        <i class="fa-solid fa-plus"></i> Add First Account
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- SAVE BUTTON -->
                        <div class="config-actions">
                            <button type="submit" name="puri_save_profile" class="button button-primary button-hero">
                                <i class="fa-solid fa-save"></i> Save Configuration
                            </button>
                            <button type="button" class="button button-secondary" id="btn-reset">
                                <i class="fa-solid fa-undo"></i> Reset Changes
                            </button>
                        </div>

                    </div>

                    <!-- RIGHT COLUMN: Live Preview -->
                    <div class="config-sidebar">
                        
                        <!-- Receipt Preview -->
                        <div class="preview-panel">
                            <div class="preview-header">
                                <i class="fa-solid fa-receipt"></i> Receipt Preview
                            </div>
                            <div class="preview-body">
                                <div class="thermal-preview" id="thermal-preview">
                                    <div class="thermal-logo" id="preview-logo">
                                        <?php if ($logo_url): ?>
                                            <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
                                        <?php endif; ?>
                                    </div>
                                    <div class="thermal-name" id="preview-name">
                                        <?php echo esc_html(get_option('puri_comp_name') ?: 'Company Name'); ?>
                                    </div>
                                    <div class="thermal-address" id="preview-address">
                                        <?php echo esc_html(get_option('puri_comp_address') ?: 'Business Address'); ?>
                                    </div>
                                    <div class="thermal-contact" id="preview-contact">
                                        <?php 
                                        $phone = get_option('puri_comp_phone');
                                        $email = get_option('puri_comp_email');
                                        if ($phone || $email) {
                                            echo $phone ? 'Tel: ' . esc_html($phone) : '';
                                            if ($phone && $email) echo '<br>';
                                            echo $email ? esc_html($email) : '';
                                        }
                                        ?>
                                    </div>
                                    <div class="thermal-divider">================================</div>
                                    <div class="thermal-sample">
                                        TRANSACTION SAMPLE<br>
                                        Date: <?php echo date('d/m/Y H:i'); ?><br>
                                        Ref: POS-20260115-001<br>
                                        ================================<br>
                                        1x SAR 500    Rp 2,100,000<br>
                                        --------------------------------<br>
                                        TOTAL         Rp 2,100,000<br>
                                        ================================
                                    </div>
                                </div>
                                <p class="preview-note">
                                    <i class="fa-solid fa-info-circle"></i> 
                                    This is how your receipt header will look on thermal printer
                                </p>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="stats-panel">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fa-solid fa-university"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value" id="stat-banks"><?php echo count($banks); ?></div>
                                    <div class="stat-label">Bank Accounts</div>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fa-solid fa-check-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-value"><?php echo $logo_id ? 'âœ"' : 'âœ—'; ?></div>
                                    <div class="stat-label">Logo Set</div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
            </form>
        </div>

        <style>
            /* ============================================ */
            /* GLOBAL STYLES                              */
            /* ============================================ */
            .puri-config-wrapper {
                box-sizing: border-box;
                padding: 20px;
            }

            .version-badge {
                font-size: 11px;
                background: #2271b1;
                color: white;
                padding: 2px 8px;
                border-radius: 3px;
                font-weight: normal;
                margin-left: 10px;
            }

            /* ============================================ */
            /* LAYOUT                                     */
            /* ============================================ */
            .config-container {
                display: grid;
                grid-template-columns: 1fr 350px;
                gap: 20px;
                margin-top: 20px;
            }

            .config-main {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            .config-sidebar {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }

            /* ============================================ */
            /* PANELS                                     */
            /* ============================================ */
            .config-panel, .preview-panel, .stats-panel {
                background: white;
                border: 1px solid #c3c4c7;
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                overflow: hidden;
            }

            .panel-header, .preview-header {
                background: #f6f7f7;
                padding: 12px 20px;
                border-bottom: 1px solid #c3c4c7;
                font-weight: 600;
                font-size: 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .panel-header i, .preview-header i {
                margin-right: 8px;
                color: #2271b1;
            }

            .panel-body, .preview-body {
                padding: 20px;
            }

            /* ============================================ */
            /* LOGO UPLOADER                              */
            /* ============================================ */
            .logo-uploader-container {
                display: flex;
                gap: 20px;
                align-items: flex-start;
            }

            .logo-preview-box {
                width: 150px;
                height: 150px;
                border: 2px dashed #cbd5e1;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f8fafc;
                overflow: hidden;
                flex-shrink: 0;
                transition: all 0.3s;
            }

            .logo-preview-box:hover {
                border-color: #2271b1;
                background: #f0f6fb;
            }

            .logo-preview-box img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }

            .logo-placeholder {
                text-align: center;
                color: #94a3b8;
            }

            .logo-placeholder i {
                font-size: 40px;
                margin-bottom: 10px;
                display: block;
            }

            .logo-placeholder span {
                font-size: 12px;
            }

            .logo-controls {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .logo-controls .button {
                width: 100%;
                justify-content: center;
                display: flex;
                gap: 8px;
                align-items: center;
            }

            .logo-controls .description {
                margin-top: 10px;
                padding: 10px;
                background: #fffbeb;
                border-left: 3px solid #fbbf24;
                border-radius: 4px;
                font-size: 12px;
                line-height: 1.5;
            }

            /* ============================================ */
            /* FORM GRID                                  */
            /* ============================================ */
            .form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .form-group {
                display: flex;
                flex-direction: column;
            }

            .form-group.full-width {
                grid-column: 1 / -1;
            }

            .form-group label {
                font-weight: 600;
                font-size: 13px;
                color: #1d2327;
                margin-bottom: 8px;
            }

            .form-group label .required {
                color: #d63638;
            }

            .puri-input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid #8c8f94;
                border-radius: 6px;
                font-size: 14px;
                transition: all 0.2s;
            }

            .puri-input:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
                outline: none;
            }

            .field-hint {
                font-size: 12px;
                color: #646970;
                margin-top: 5px;
            }

            /* ============================================ */
            /* BANK ACCOUNTS                              */
            /* ============================================ */
            .bank-accounts-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .bank-account-item {
                display: flex;
                gap: 10px;
                padding: 15px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                align-items: flex-start;
                transition: all 0.2s;
            }

            .bank-account-item:hover {
                background: #f0f6fb;
                border-color: #2271b1;
            }

            .bank-drag-handle {
                width: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #9ca3af;
                cursor: move;
                padding-top: 25px;
            }

            .bank-fields {
                flex: 1;
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 10px;
            }

            .bank-field {
                display: flex;
                flex-direction: column;
            }

            .bank-field label {
                font-size: 11px;
                font-weight: 600;
                color: #646970;
                margin-bottom: 5px;
                text-transform: uppercase;
            }

            .bank-remove-btn {
                width: 36px;
                height: 36px;
                border: 1px solid #d63638;
                background: white;
                color: #d63638;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                margin-top: 20px;
            }

            .bank-remove-btn:hover {
                background: #d63638;
                color: white;
            }

            .empty-state {
                text-align: center;
                padding: 60px 20px;
                color: #6b7280;
            }

            .empty-state i {
                font-size: 60px;
                opacity: 0.3;
                margin-bottom: 15px;
            }

            /* ============================================ */
            /* INFO BOX                                   */
            /* ============================================ */
            .info-box {
                padding: 12px 15px;
                border-radius: 6px;
                display: flex;
                gap: 10px;
                align-items: flex-start;
                font-size: 13px;
            }

            .info-primary {
                background: #eff6ff;
                border: 1px solid #3b82f6;
                color: #1e40af;
            }

            .info-primary i {
                color: #3b82f6;
            }

            /* ============================================ */
            /* THERMAL PREVIEW                            */
            /* ============================================ */
            .thermal-preview {
                background: #ffffff;
                border: 2px solid #e5e7eb;
                border-radius: 6px;
                padding: 20px;
                font-family: 'Courier New', 'Courier', monospace;
                font-size: 11px;
                line-height: 1.4;
                text-align: center;
                color: #1f2937;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
            }

            .thermal-logo {
                margin-bottom: 10px;
            }

            .thermal-logo img {
                max-width: 60px;
                height: auto;
                filter: grayscale(1);
            }

            .thermal-name {
                font-weight: bold;
                font-size: 13px;
                margin-bottom: 5px;
            }

            .thermal-address {
                font-size: 10px;
                margin-bottom: 5px;
                color: #4b5563;
            }

            .thermal-contact {
                font-size: 9px;
                margin-bottom: 8px;
                color: #6b7280;
            }

            .thermal-divider {
                margin: 10px 0;
                color: #9ca3af;
            }

            .thermal-sample {
                font-size: 10px;
                text-align: left;
                white-space: pre-line;
            }

            .preview-note {
                margin-top: 15px;
                font-size: 12px;
                color: #6b7280;
                text-align: center;
            }

            /* ============================================ */
            /* STATS PANEL                                */
            /* ============================================ */
            .stats-panel {
                padding: 15px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .stat-item {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 12px;
                background: #f9fafb;
                border-radius: 6px;
            }

            .stat-icon {
                width: 45px;
                height: 45px;
                background: #dbeafe;
                color: #2271b1;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
            }

            .stat-content {
                flex: 1;
            }

            .stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #1d2327;
            }

            .stat-label {
                font-size: 12px;
                color: #6b7280;
            }

            /* ============================================ */
            /* ACTIONS                                    */
            /* ============================================ */
            .config-actions {
                display: flex;
                gap: 10px;
                padding: 20px;
                background: #f9fafb;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
            }

            .button-hero {
                height: 50px !important;
                font-size: 15px !important;
                padding: 0 30px !important;
                flex: 1;
            }

            .button {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border-radius: 6px !important;
                transition: all 0.2s;
            }

            .button-primary {
                background: #2271b1 !important;
                border-color: #2271b1 !important;
                box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2) !important;
            }

            .button-primary:hover {
                background: #135e96 !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(34, 113, 177, 0.3) !important;
            }

            .button-secondary {
                background: white !important;
                border: 1px solid #8c8f94 !important;
            }

            .button-secondary:hover {
                background: #f6f7f7 !important;
                border-color: #646970 !important;
            }

            /* ============================================ */
            /* RESPONSIVE DESIGN                          */
            /* ============================================ */
            @media (max-width: 1200px) {
                .config-container {
                    grid-template-columns: 1fr;
                }

                .config-sidebar {
                    order: -1;
                }
            }

            @media (max-width: 768px) {
                .form-grid {
                    grid-template-columns: 1fr;
                }

                .bank-fields {
                    grid-template-columns: 1fr;
                }

                .logo-uploader-container {
                    flex-direction: column;
                }

                .logo-preview-box {
                    width: 100%;
                    height: 200px;
                }
            }

            /* ============================================ */
            /* ANIMATIONS                                 */
            /* ============================================ */
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .bank-account-item {
                animation: fadeIn 0.3s ease-out;
            }

            /* ============================================ */
            /* ACCOUNT NUMBER FORMATTING                  */
            /* ============================================ */
            .account-number {
                font-family: 'Courier New', 'Courier', monospace;
                letter-spacing: 1px;
                font-weight: 600;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            
            /**
             * ====================================================
             * LOGO UPLOADER
             * ====================================================
             */
            let mediaUploader;

            $('#btn_upload_logo').on('click', function(e) {
                e.preventDefault();

                // If uploader exists, reopen it
                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }

                // Create new media uploader
                mediaUploader = wp.media({
                    title: 'Choose Company Logo',
                    button: {
                        text: 'Use this logo'
                    },
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                });

                // When image is selected
                mediaUploader.on('select', function() {
                    const attachment = mediaUploader.state().get('selection').first().toJSON();
                    
                    // Update hidden input
                    $('#comp_logo_id').val(attachment.id);
                    
                    // Update preview
                    $('#logo-preview-container').html(
                        '<img src="' + attachment.url + '" alt="Company Logo">'
                    );
                    
                    // Update thermal preview
                    $('#preview-logo').html(
                        '<img src="' + attachment.url + '" alt="Logo">'
                    );
                    
                    // Update stats
                    updateStats();
                    
                    // Visual feedback
                    showNotification('Logo uploaded successfully!', 'success');
                });

                mediaUploader.open();
            });

            // Remove logo
            $('#btn_remove_logo').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to remove the logo?')) {
                    return;
                }

                $('#comp_logo_id').val('');
                $('#logo-preview-container').html(
                    '<div class="logo-placeholder">' +
                        '<i class="fa-solid fa-image"></i>' +
                        '<span>No Logo</span>' +
                    '</div>'
                );
                $('#preview-logo').html('');
                
                updateStats();
                showNotification('Logo removed', 'info');
            });

            /**
             * ====================================================
             * LIVE PREVIEW UPDATE
             * ====================================================
             */
            
            // Update company name preview
            $('#comp_name').on('input', function() {
                const value = $(this).val() || 'Company Name';
                $('#preview-name').text(value);
            });

            // Update address preview
            $('#comp_address').on('input', function() {
                const value = $(this).val() || 'Business Address';
                $('#preview-address').text(value);
            });

            // Update contact preview
            $('#comp_phone, #comp_email').on('input', function() {
                const phone = $('#comp_phone').val();
                const email = $('#comp_email').val();
                let contact = '';
                
                if (phone) contact += 'Tel: ' + phone;
                if (phone && email) contact += '<br>';
                if (email) contact += email;
                
                $('#preview-contact').html(contact || '');
            });

            /**
             * ====================================================
             * BANK ACCOUNTS REPEATER
             * ====================================================
             */
            
            let bankIndex = <?php echo count($banks); ?>;

            // Add bank account
            $('#add-bank-row, #add-first-bank').on('click', function() {
                const newRow = `
                    <div class="bank-account-item">
                        <div class="bank-drag-handle">
                            <i class="fa-solid fa-grip-vertical"></i>
                        </div>
                        <div class="bank-fields">
                            <div class="bank-field">
                                <label>Bank Name</label>
                                <input type="text" name="banks[${bankIndex}][name]" 
                                       class="puri-input" placeholder="e.g. Bank Syariah Indonesia">
                            </div>
                            <div class="bank-field">
                                <label>Account Number</label>
                                <input type="text" name="banks[${bankIndex}][acc_no]" 
                                       class="puri-input account-number" placeholder="1234567890">
                            </div>
                            <div class="bank-field">
                                <label>Account Holder</label>
                                <input type="text" name="banks[${bankIndex}][holder]" 
                                       class="puri-input" placeholder="Account owner name">
                            </div>
                        </div>
                        <button type="button" class="bank-remove-btn remove-row" title="Remove account">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                `;
                
                $('.bank-accounts-list').append(newRow);
                bankIndex++;
                
                updateBankEmptyState();
                reindexBankRows();
                updateStats();
                
                // Scroll to new item
                $('.bank-accounts-list').animate({
                    scrollTop: $('.bank-accounts-list')[0].scrollHeight
                }, 300);
            });

            // Remove bank account
            $(document).on('click', '.remove-row', function() {
                const $item = $(this).closest('.bank-account-item');
                
                if ($('.bank-account-item').length <= 1) {
                    if (!confirm('Remove the last bank account?')) {
                        return;
                    }
                }
                
                $item.fadeOut(300, function() {
                    $(this).remove();
                    reindexBankRows();
                    updateBankEmptyState();
                    updateStats();
                });
            });

            // Reindex bank rows
            function reindexBankRows() {
                $('.bank-account-item').each(function(index) {
                    $(this).find('input').each(function() {
                        const name = $(this).attr('name');
                        if (name) {
                            const newName = name.replace(/banks\[\d+\]/, 'banks[' + index + ']');
                            $(this).attr('name', newName);
                        }
                    });
                });
            }

            // Update empty state
            function updateBankEmptyState() {
                const hasItems = $('.bank-account-item').length > 0;
                
                if (hasItems) {
                    $('.bank-accounts-list').show();
                    $('#bank-empty-state').hide();
                } else {
                    $('.bank-accounts-list').hide();
                    $('#bank-empty-state').show();
                }
            }

            // Update stats
            function updateStats() {
                const bankCount = $('.bank-account-item').length;
                const hasLogo = $('#comp_logo_id').val() !== '';
                
                $('#stat-banks').text(bankCount);
                $('.stat-item:last-child .stat-value').html(hasLogo ? 'âœ"' : 'âœ—');
            }

            /**
             * ====================================================
             * FORM VALIDATION
             * ====================================================
             */
            
            $('.puri-config-form').on('submit', function(e) {
                let isValid = true;
                let errors = [];

                // Check required fields
                if ($('#comp_name').val().trim() === '') {
                    errors.push('Business name is required');
                    $('#comp_name').focus();
                    isValid = false;
                }

                if ($('#comp_address').val().trim() === '') {
                    errors.push('Business address is required');
                    if (isValid) $('#comp_address').focus();
                    isValid = false;
                }

                // Validate bank accounts
                let hasValidBank = false;
                $('.bank-account-item').each(function() {
                    const accNo = $(this).find('input[name*="[acc_no]"]').val();
                    if (accNo && accNo.trim() !== '') {
                        hasValidBank = true;
                    }
                });

                if (!hasValidBank && $('.bank-account-item').length > 0) {
                    errors.push('At least one bank account must have an account number');
                    isValid = false;
                }

                if (!isValid) {
                    e.preventDefault();
                    showNotification(errors.join('<br>'), 'error');
                    return false;
                }

                // Show loading state
                const $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true)
                          .html('<i class="fa-solid fa-spinner fa-spin"></i> Saving...');
            });

            /**
             * ====================================================
             * RESET BUTTON
             * ====================================================
             */
            
            $('#btn-reset').on('click', function() {
                if (confirm('Are you sure you want to reset all changes?')) {
                    location.reload();
                }
            });

            /**
             * ====================================================
             * ACCOUNT NUMBER FORMATTING
             * ====================================================
             */
            
            $(document).on('input', '.account-number', function() {
                // Remove non-numeric characters
                let value = $(this).val().replace(/\D/g, '');
                
                // Optional: Add spacing for readability (e.g., 1234 5678 90)
                // Uncomment if needed:
                // value = value.match(/.{1,4}/g)?.join(' ') || value;
                
                $(this).val(value);
            });

            /**
             * ====================================================
             * NOTIFICATION HELPER
             * ====================================================
             */
            
            function showNotification(message, type = 'info') {
                const icons = {
                    success: 'fa-check-circle',
                    error: 'fa-exclamation-circle',
                    warning: 'fa-exclamation-triangle',
                    info: 'fa-info-circle'
                };

                const colors = {
                    success: '#10b981',
                    error: '#ef4444',
                    warning: '#f59e0b',
                    info: '#3b82f6'
                };

                const notification = $('<div>', {
                    class: 'puri-notification',
                    html: `<i class="fa-solid ${icons[type]}"></i> ${message}`,
                    css: {
                        position: 'fixed',
                        top: '80px',
                        right: '20px',
                        background: 'white',
                        border: `2px solid ${colors[type]}`,
                        borderRadius: '8px',
                        padding: '15px 20px',
                        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                        zIndex: 999999,
                        minWidth: '300px',
                        maxWidth: '500px',
                        opacity: 0,
                        transform: 'translateX(400px)'
                    }
                });

                $('body').append(notification);

                // Animate in
                notification.animate({
                    opacity: 1,
                    right: '20px'
                }, 300);

                // Auto remove
                setTimeout(function() {
                    notification.animate({
                        opacity: 0,
                        right: '-400px'
                    }, 300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }

            /**
             * ====================================================
             * DRAG & DROP FOR BANKS (Optional Enhancement)
             * ====================================================
             */
            
            // Make bank accounts sortable (requires jQuery UI)
            if ($.fn.sortable) {
                $('.bank-accounts-list').sortable({
                    handle: '.bank-drag-handle',
                    axis: 'y',
                    opacity: 0.7,
                    cursor: 'move',
                    update: function() {
                        reindexBankRows();
                    }
                });
            }

            /**
             * ====================================================
             * KEYBOARD SHORTCUTS
             * ====================================================
             */
            
            $(document).on('keydown', function(e) {
                // Ctrl+S or Cmd+S to save
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    $('.puri-config-form').submit();
                }
            });

            // Initialize
            updateBankEmptyState();
            updateStats();

        });
        </script>

        <?php
    }

} // End Class

// ============================================================================
// INITIALIZATION
// ============================================================================

$puri_configuration_base = new Puri_Configuration_Base();

if (!function_exists('puri_render_profile_page')) {
    /**
     * Global wrapper function for menu rendering
     */
    function puri_render_profile_page() {
        global $puri_configuration_base;
        if ($puri_configuration_base instanceof Puri_Configuration_Base) {
            $puri_configuration_base->render_page();
        } else {
            echo '<div class="wrap"><h1>Error</h1><p>Configuration module not initialized.</p></div>';
        }
    }
}

/**
 * ============================================================================
 * ACF VISIBILITY & ADMIN UI HARMONIZER
 * ============================================================================
 */

// Hide ACF menu except for developer
add_filter('acf/settings/show_admin', function ($show) {
    return (get_current_user_id() === 1);
});

// Clean ACF admin UI
add_action('acf/input/admin_head', function () {
    ?>
    <style type="text/css">
        .acf-field { 
            padding: 12px 12px !important; 
        }
        .acf-label label { 
            font-weight: 600 !important; 
            color: #2c3338; 
        }
        .acf-tab-wrap.-top { 
            background: #f0f6fb; 
            border-radius: 5px 5px 0 0; 
        }
        .acf-tab-group li.active a { 
            background: #fff !important; 
            border-bottom-color: transparent !important; 
        }
        .postbox.acf-postbox { 
            border-radius: 8px; 
            border: 1px solid #ccd0d4; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
        }
    </style>
    <?php
});

/**
 * ============================================================================
 * GLOBAL PURI UI HARMONIZER
 * ============================================================================
 */
add_action('admin_head', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $is_puri = $screen && is_string($screen->id) && strpos($screen->id, 'puri') !== false;

    ?>
    <style>
        :root {
            --puri-primary: #2271b1;
            --puri-primary-dark: #135e96;
            --puri-accent: #0a7abf;
            --puri-bg: #f0f6fb;
            --puri-border: #c3c4c7;
            --puri-text: #1d2327;
            --puri-text-light: #646970;
        }

        /* Header Styling */
        .wrap h1.wp-heading-inline {
            font-weight: 700;
            color: var(--puri-text);
            letter-spacing: -0.02em;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .wrap .description {
            color: var(--puri-text-light);
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Card Styling */
        .puri-card, .postbox, .acf-postbox {
            border-radius: 8px !important;
            border: 1px solid var(--puri-border) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03) !important;
            overflow: hidden;
            background: white;
        }

        /* Form Elements */
        input[type="text"], 
        input[type="number"], 
        input[type="email"],
        input[type="tel"],
        select, 
        textarea {
            border-radius: 6px !important;
            border: 1px solid #8c8f94 !important;
            padding: 8px 12px !important;
            box-shadow: none !important;
            transition: all 0.2s;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--puri-primary) !important;
            box-shadow: 0 0 0 1px var(--puri-primary) !important;
            outline: none !important;
        }

        /* Button Styling */
        .button {
            border-radius: 6px !important;
            transition: all 0.2s !important;
            font-weight: 500 !important;
        }

        .button-primary {
            background: var(--puri-primary) !important;
            border-color: var(--puri-primary) !important;
            box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2) !important;
            text-shadow: none !important;
        }

        .button-primary:hover, 
        .button-primary:focus {
            background: var(--puri-primary-dark) !important;
            border-color: var(--puri-primary-dark) !important;
            box-shadow: 0 4px 8px rgba(34, 113, 177, 0.3) !important;
            transform: translateY(-1px);
        }

        .button-primary:active {
            transform: translateY(0);
        }

        /* Menu Icon */
        #toplevel_page_puri-master .wp-menu-image img {
            padding-top: 0 !important;
        }

        /* Table Styling */
        .form-table th {
            font-weight: 600;
            color: #50575e;
            width: 220px;
        }

        /* SKU & Badges */
        .puri-sku-tag {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 12px;
            border: 1px solid #e2e8f0;
        }

        .puri-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }

        .puri-badge-blue { 
            background: #dbeafe; 
            color: #1e40af; 
        }

        .puri-badge-gray { 
            background: #f3f4f6; 
            color: #374151; 
        }

        .puri-badge-green { 
            background: #d1fae5; 
            color: #065f46; 
        }

        .puri-badge-red { 
            background: #fee2e2; 
            color: #991b1b; 
        }

        /* Price Display */
        .puri-price-avg {
            color: #059669;
            font-weight: 700;
            font-family: 'Monaco', 'Consolas', monospace;
        }
    </style>
    <?php

    // Hide notices on PURI pages
    if ($is_puri) : ?>
        <style>
            .notice:not(.puri-notice), 
            .updated:not(.puri-notice), 
            .error:not(.puri-notice) {
                display: none !important;
            }
            #screen-meta-links { 
                display: none; 
            }
            #wpfooter { 
                display: none; 
            }
            #wpbody-content { 
                padding-bottom: 50px; 
            }
        </style>
    <?php
    endif;
});

/**
 * ============================================================================
 * HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Get company logo URL
 */
if (!function_exists('puri_get_company_logo')) {
    function puri_get_company_logo() {
        $logo_id = get_option('puri_comp_logo', '');
        return $logo_id ? wp_get_attachment_url($logo_id) : '';
    }
}

/**
 * Get company name
 */
if (!function_exists('puri_get_company_name')) {
    function puri_get_company_name() {
        return get_option('puri_comp_name', 'Puri Money Changer');
    }
}

/**
 * Get company address
 */
if (!function_exists('puri_get_company_address')) {
    function puri_get_company_address() {
        return get_option('puri_comp_address', '');
    }
}

/**
 * Get bank accounts
 */
if (!function_exists('puri_get_bank_accounts')) {
    function puri_get_bank_accounts() {
        $banks = get_option('puri_bank_accounts', []);
        return is_array($banks) ? $banks : [];
    }
}

/**
 * ============================================================================
 * END OF FILE
 * ============================================================================
 * 
 * Integration Notes:
 * - Add to menu via add_submenu_page() in main plugin file
 * - Requires WordPress Media Library
 * - Compatible with WordPress 5.8+
 * - Modern browsers (Chrome 90+, Firefox 88+, Safari 14+)
 * 
 * ============================================================================
 */