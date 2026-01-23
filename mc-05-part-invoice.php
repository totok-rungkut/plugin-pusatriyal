<?php
/**
 * ============================================================================
 * MC-05 PART INVOICE - Pool Transaction Receipt Generator
 * ============================================================================
 * Version: 7.10.0
 * Author: Denmas Totok
 * 
 * INTEGRATION POINTS:
 * - Called from: ajax_process_checkout() success (auto-print)
 * - Called from: History panel "Print" button (reprint)
 * 
 * RECEIPT COLOR SCHEME (Manual System Concept):
 * - RED (Agent/Consignment): Customer dengan status PENDING/VERIFIED (belum bayar lunas)
 * - BLUE (Retail Paid): Customer dengan status POSTED (sudah bayar lunas)
 * 
 * WATERMARK LOGIC:
 * - PENDING: "BELUM LUNAS" (Red)
 * - VERIFIED: "VERIFIED - BELUM POSTING" (Orange)
 * - POSTED: "LUNAS" (Blue)
 * - VOID: "BATAL" (Gray, strikethrough)
 * 
 * DATA SOURCE:
 * - Main: T_POOL_TRANSACTIONS (ref_id primary key)
 * - Customer: pr_customer CPT
 * - Items: items_snapshot JSON field
 * - Company Profile: ACF Options
 * ============================================================================
 */

defined('ABSPATH') || exit;

/**
 * ============================================================================
 * AJAX HANDLER: Get Invoice Data
 * ============================================================================
 * Fetch complete transaction data for invoice rendering
 * 
 * @return JSON {success, data: {transaction, customer, company, items}}
 */
add_action('wp_ajax_puri_pos_get_invoice', 'puri_pos_ajax_get_invoice');

function puri_pos_ajax_get_invoice() {
    check_ajax_referer('puri_pos_checkout', 'nonce');
    global $wpdb;
    
    // ========================================================================
    // 1. GET TRANSACTION DATA
    // ========================================================================
    $ref_id = sanitize_text_field($_POST['ref_id'] ?? '');
    
    if (empty($ref_id)) {
        wp_send_json_error(['message' => 'Reference ID tidak valid']);
    }
    
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    $transaction = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tbl_pool} WHERE ref_id = %s",
        $ref_id
    ));
    
    if (!$transaction) {
        wp_send_json_error(['message' => 'Transaksi tidak ditemukan']);
    }
    
    // ========================================================================
    // 2. GET CUSTOMER DATA
    // ========================================================================
    $customer = null;
    if ($transaction->customer_id > 0) {
        $cust_post = get_post($transaction->customer_id);
        if ($cust_post) {
            $customer = [
                'name' => $cust_post->post_title,
                'nik' => get_post_meta($transaction->customer_id, '_puri_cust_nik', true) ?: $transaction->customer_nik,
                'phone' => get_post_meta($transaction->customer_id, '_puri_cust_phone', true) ?: '-',
                'address' => get_post_meta($transaction->customer_id, '_puri_cust_address', true) ?: '-',
                'city' => get_post_meta($transaction->customer_id, '_puri_cust_city', true) ?: '-',
                'type' => get_post_meta($transaction->customer_id, '_puri_cust_type', true) ?: 'umum'
            ];
        }
    }
    
    // Fallback to transaction stored data
    if (!$customer) {
        $customer = [
            'name' => $transaction->customer_name ?: 'Customer Umum',
            'nik' => $transaction->customer_nik ?: '-',
            'phone' => '-',
            'address' => '-',
            'city' => '-',
            'type' => 'umum'
        ];
    }
    
    // ========================================================================
    // 3. GET COMPANY PROFILE
    // ========================================================================
    $company = [
        'logo' => function_exists('get_field') ? get_field('cp_logo', 'option') : '',
        'name' => function_exists('get_field') ? (get_field('cp_name', 'option') ?: 'Pusat Riyal') : 'Pusat Riyal',
        'bi_license' => function_exists('get_field') ? (get_field('cp_bi_license', 'option') ?: '-') : '-',
        'npwp' => function_exists('get_field') ? (get_field('cp_npwp', 'option') ?: '-') : '-',
        'address' => function_exists('get_field') ? (get_field('cp_address', 'option') ?: '-') : '-',
        'city' => function_exists('get_field') ? (get_field('cp_city', 'option') ?: '-') : '-',
        'phone' => function_exists('get_field') ? (get_field('cp_phone', 'option') ?: '-') : '-'
    ];
    
    // ========================================================================
    // 4. PARSE ITEMS SNAPSHOT
    // ========================================================================
    $items_raw = json_decode($transaction->items_snapshot, true);
    
    if (!is_array($items_raw)) {
        wp_send_json_error(['message' => 'Data item tidak valid']);
    }
    
    // ========================================================================
    // 5. GET CASHIER INFO
    // ========================================================================
    $cashier = get_user_by('id', $transaction->created_by);
    $cashier_name = $cashier ? $cashier->display_name : 'System';
    
    // ========================================================================
    // 6. RETURN COMPLETE DATA
    // ========================================================================
    wp_send_json_success([
        'transaction' => [
            'ref_id' => $transaction->ref_id,
            'trx_date' => $transaction->trx_date,
            'trade_mode' => $transaction->trade_mode,
            'payment_method' => $transaction->payment_method,
            'delivery_method' => $transaction->delivery_method ?? 'pickup',
            'total_riyal' => floatval($transaction->total_riyal),
            'total_idr' => floatval($transaction->total_idr),
            'total_hpp' => floatval($transaction->total_hpp),
            'status' => $transaction->status,
            'notes' => $transaction->notes
        ],
        'customer' => $customer,
        'company' => $company,
        'items' => $items_raw,
        'cashier' => $cashier_name
    ]);
}

/**
 * ============================================================================
 * RENDER: Invoice Modal (Embedded in POS UI)
 * ============================================================================
 * Modal window for invoice preview & print
 */
function puri_pos_render_invoice_modal() {
    ?>
    <!-- ======================================================================= -->
    <!-- INVOICE MODAL -->
    <!-- ======================================================================= -->
    <div id="modal_invoice" class="puri-modal" style="display:none;">
        <div class="puri-modal-content invoice-modal-content">
            
            <!-- Close Button -->
            <button type="button" class="puri-modal-close" onclick="closeInvoiceModal()">&times;</button>
            
            <!-- Invoice Container -->
            <div id="invoice_container" class="invoice-print-area">
                <!-- Content akan di-render via JavaScript -->
            </div>
            
            <!-- Action Buttons (Hide on print) -->
            <div class="invoice-actions no-print">
                <button type="button" class="button button-primary button-large" onclick="printInvoice()">
                    <i class="fa-solid fa-print"></i> Print Invoice
                </button>
                <button type="button" class="button button-secondary" onclick="closeInvoiceModal()">
                    <i class="fa-solid fa-times"></i> Close
                </button>
            </div>
            
        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- INVOICE STYLES (Print-optimized) -->
    <!-- ======================================================================= -->
    <style>
    /* ============================================ */
    /* MODAL OVERLAY */
    /* ============================================ */
    .invoice-modal-content {
        max-width: 450px;
        background: #fff;
        padding: 0;
    }
    
    .invoice-print-area {
        padding: 20px;
        background: #fff;
    }
    
    .invoice-actions {
        border-top: 2px solid #e5e7eb;
        padding: 20px;
        display: flex;
        gap: 10px;
        justify-content: center;
        background: #f9fafb;
    }
    
    /* ============================================ */
    /* PRINT MEDIA QUERY */
    /* ============================================ */
    @media print {
        /* Hide WordPress Admin UI */
        #adminmenuback, #adminmenuwrap, #wpadminbar, #wpfooter,
        .puri-modal-close, .invoice-actions, .no-print {
            display: none !important;
        }
        
        /* Reset body */
        body {
            margin: 0;
            padding: 0;
            background: #fff;
        }
        
        /* Force modal to fullscreen */
        .puri-modal {
            position: static !important;
            background: none !important;
        }
        
        .invoice-modal-content {
            max-width: 100% !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        
        /* Page break control */
        .invoice-box {
            page-break-inside: avoid;
        }
    }
    
    /* ============================================ */
    /* RECEIPT BOX STYLING */
    /* ============================================ */
    .invoice-box {
        font-family: 'Courier New', Courier, monospace;
        max-width: 400px;
        margin: 0 auto;
        border: 2px solid #000;
        padding: 20px;
        position: relative;
        background: #fff;
    }
    
    /* ============================================ */
    /* WATERMARK SYSTEM (Color-coded by status) */
    /* ============================================ */
    .invoice-box::before {
        content: attr(data-watermark);
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-45deg);
        font-size: 60px;
        font-weight: 900;
        opacity: 0.1;
        pointer-events: none;
        z-index: 1;
        white-space: nowrap;
    }
    
    /* RED Theme: PENDING (Agent/Consignment - Belum Bayar) */
    .invoice-box.status-pending {
        border-color: #dc2626;
        background: linear-gradient(to bottom, #fef2f2 0%, #fff 30%);
    }
    
    .invoice-box.status-pending::before {
        content: 'BELUM LUNAS';
        color: #dc2626;
    }
    
    .invoice-box.status-pending .watermark-badge {
        background: #dc2626;
        color: #fff;
    }
    
    /* ORANGE Theme: VERIFIED (Verified but not posted) */
    .invoice-box.status-verified {
        border-color: #f59e0b;
        background: linear-gradient(to bottom, #fffbeb 0%, #fff 30%);
    }
    
    .invoice-box.status-verified::before {
        content: 'VERIFIED';
        color: #f59e0b;
    }
    
    .invoice-box.status-verified .watermark-badge {
        background: #f59e0b;
        color: #fff;
    }
    
    /* BLUE Theme: POSTED (Lunas - Sudah Posting) */
    .invoice-box.status-posted {
        border-color: #2563eb;
        background: linear-gradient(to bottom, #eff6ff 0%, #fff 30%);
    }
    
    .invoice-box.status-posted::before {
        content: 'LUNAS';
        color: #2563eb;
    }
    
    .invoice-box.status-posted .watermark-badge {
        background: #2563eb;
        color: #fff;
    }
    
    /* GRAY Theme: VOID (Canceled) */
    .invoice-box.status-void {
        border-color: #6b7280;
        background: linear-gradient(to bottom, #f9fafb 0%, #fff 30%);
        opacity: 0.7;
    }
    
    .invoice-box.status-void::before {
        content: 'BATAL';
        color: #6b7280;
    }
    
    .invoice-box.status-void .watermark-badge {
        background: #6b7280;
        color: #fff;
    }
    
    .invoice-box.status-void .invoice-content {
        text-decoration: line-through;
    }
    
    /* ============================================ */
    /* HEADER SECTION */
    /* ============================================ */
    .invoice-header {
        text-align: center;
        border-bottom: 2px dashed #000;
        padding-bottom: 12px;
        margin-bottom: 12px;
        position: relative;
        z-index: 2;
    }
    
    .invoice-header img {
        max-height: 60px;
        margin-bottom: 8px;
    }
    
    .company-name {
        display: block;
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 4px;
    }
    
    .company-info {
        font-size: 11px;
        line-height: 1.4;
        color: #374151;
    }
    
    /* ============================================ */
    /* TRANSACTION INFO */
    /* ============================================ */
    .transaction-info {
        font-size: 12px;
        margin-bottom: 12px;
        position: relative;
        z-index: 2;
    }
    
    .transaction-info table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .transaction-info td {
        padding: 3px 0;
        vertical-align: top;
    }
    
    .transaction-info td:first-child {
        width: 100px;
        font-weight: 600;
    }
    
    .watermark-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        margin-left: 8px;
    }
    
    /* ============================================ */
    /* ITEMS TABLE */
    /* ============================================ */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        margin: 12px 0;
        position: relative;
        z-index: 2;
    }
    
    .items-table thead th {
        border-bottom: 2px solid #000;
        padding: 6px 4px;
        text-align: left;
        font-weight: bold;
    }
    
    .items-table thead th:nth-child(2),
    .items-table thead th:nth-child(3) {
        text-align: right;
    }
    
    .items-table tbody td {
        padding: 6px 4px;
        border-bottom: 1px dashed #ddd;
    }
    
    .items-table tbody td:nth-child(2),
    .items-table tbody td:nth-child(3) {
        text-align: right;
    }
    
    /* ============================================ */
    /* TOTALS SECTION */
    /* ============================================ */
    .invoice-totals {
        border-top: 2px dashed #000;
        margin-top: 12px;
        padding-top: 12px;
        text-align: right;
        position: relative;
        z-index: 2;
    }
    
    .invoice-totals .total-line {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;
        font-size: 13px;
    }
    
    .invoice-totals .grand-total {
        font-size: 18px;
        font-weight: 900;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #000;
    }
    
    /* ============================================ */
    /* FOOTER */
    /* ============================================ */
    .invoice-footer {
        text-align: center;
        margin-top: 16px;
        font-size: 11px;
        color: #6b7280;
        position: relative;
        z-index: 2;
    }
    
    .invoice-footer .thank-you {
        margin-top: 12px;
        font-style: italic;
    }
    
    /* ============================================ */
    /* TRADE MODE INDICATORS */
    /* ============================================ */
    .mode-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
    }
    
    .mode-badge.sell {
        background: #10b981;
        color: #fff;
    }
    
    .mode-badge.buy {
        background: #f59e0b;
        color: #fff;
    }
    </style>

    <!-- ======================================================================= -->
    <!-- INVOICE JAVASCRIPT MODULE -->
    <!-- ======================================================================= -->
    <script>
    (function($) {
        'use strict';
        
        // ====================================================================
        // INVOICE MODULE (Exposed to window scope)
        // ====================================================================
        window.POS_Invoice = {
            
            /**
             * Open Invoice Modal
             * @param {string} refId - Transaction reference ID
             * @param {boolean} autoPrint - Auto-trigger print after load
             */
            open: function(refId, autoPrint = false) {
                console.log('📄 Opening invoice for:', refId);
                
                // Show loading state
                $('#invoice_container').html(`
                    <div style="text-align:center; padding:60px 20px;">
                        <i class="fa fa-spinner fa-spin" style="font-size:40px; color:#2563eb;"></i>
                        <p style="margin-top:20px; color:#6b7280;">Loading invoice data...</p>
                    </div>
                `);
                
                $('#modal_invoice').fadeIn(200);
                
                // Fetch invoice data
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'puri_pos_get_invoice',
                        ref_id: refId,
                        nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('✅ Invoice data loaded:', response.data);
                            window.POS_Invoice.render(response.data);
                            
                            // Auto-print if requested
                            if (autoPrint) {
                                setTimeout(() => window.print(), 500);
                            }
                        } else {
                            Swal.fire('Error', response.data.message || 'Failed to load invoice', 'error');
                            $('#modal_invoice').fadeOut(200);
                        }
                    },
                    error: function(xhr) {
                        console.error('❌ Invoice load error:', xhr.responseText);
                        Swal.fire('Error', 'Network error while loading invoice', 'error');
                        $('#modal_invoice').fadeOut(200);
                    }
                });
            },
            
            /**
             * Render Invoice HTML
             * @param {object} data - Complete invoice data from backend
             */
            render: function(data) {
                const t = data.transaction;
                const c = data.customer;
                const co = data.company;
                const items = data.items;
                const cashier = data.cashier;
                
                // Format date
                const trxDate = new Date(t.trx_date);
                const dateStr = trxDate.toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                // Build items rows
                let itemsHtml = '';
                items.forEach(item => {
                    const qty = parseFloat(item.qty || 0);
                    const denom = parseFloat(item.denom || item.denom_value || 1);
                    const rate = parseFloat(item.rate || 0);
                    const totalRiyal = qty * denom;
                    const subtotalIdr = totalRiyal * rate;
                    
                    itemsHtml += `
                        <tr>
                            <td>${item.sku || item.name}<br>
                                <small style="color:#6b7280;">SAR ${denom.toLocaleString('id-ID')}</small>
                            </td>
                            <td>${qty.toLocaleString('id-ID')}</td>
                            <td>Rp ${subtotalIdr.toLocaleString('id-ID')}</td>
                        </tr>
                    `;
                });
                
                // Watermark text based on status
                let watermarkText = 'RECEIPT';
                switch(t.status) {
                    case 'pending': watermarkText = 'BELUM LUNAS'; break;
                    case 'verified': watermarkText = 'VERIFIED'; break;
                    case 'posted': watermarkText = 'LUNAS'; break;
                    case 'void': watermarkText = 'BATAL'; break;
                }
                
                // Build complete invoice HTML
                const html = `
                    <div class="invoice-box status-${t.status}" data-watermark="${watermarkText}">
                        
                        <!-- HEADER -->
                        <div class="invoice-header">
                            ${co.logo ? `<img src="${co.logo}" alt="${co.name}">` : ''}
                            <span class="company-name">${co.name}</span>
                            <div class="company-info">
                                Izin BI: ${co.bi_license} | NPWP: ${co.npwp}<br>
                                ${co.address}, ${co.city}<br>
                                Telp: ${co.phone}
                            </div>
                        </div>
                        
                        <!-- TRANSACTION INFO -->
                        <div class="transaction-info">
                            <table>
                                <tr>
                                    <td>No. Ref</td>
                                    <td>: <strong>${t.ref_id}</strong>
                                        <span class="watermark-badge">${t.status.toUpperCase()}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Tanggal</td>
                                    <td>: ${dateStr}</td>
                                </tr>
                                <tr>
                                    <td>Customer</td>
                                    <td>: ${c.name}
                                        ${c.nik !== '-' ? `<br>&nbsp;&nbsp;<small>NIK: ${c.nik}</small>` : ''}
                                    </td>
                                </tr>
                                <tr>
                                    <td>Kasir</td>
                                    <td>: ${cashier}</td>
                                </tr>
                                <tr>
                                    <td>Mode</td>
                                    <td>: <span class="mode-badge ${t.trade_mode}">
                                        ${t.trade_mode === 'sell' ? '📤 JUAL SAR' : '📥 BELI SAR'}
                                    </span></td>
                                </tr>
                            </table>
                        </div>
                        
                        <hr style="border:none; border-top:2px dashed #000; margin:12px 0;">
                        
                        <!-- ITEMS TABLE -->
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${itemsHtml}
                            </tbody>
                        </table>
                        
                        <!-- TOTALS -->
                        <div class="invoice-totals">
                            <div class="total-line">
                                <span>Total Riyal:</span>
                                <span><strong>SAR ${parseFloat(t.total_riyal).toLocaleString('id-ID')}</strong></span>
                            </div>
                            <div class="total-line grand-total">
                                <span>TOTAL BAYAR:</span>
                                <span>Rp ${parseFloat(t.total_idr).toLocaleString('id-ID')}</span>
                            </div>
                        </div>
                        
                        <!-- FOOTER -->
                        <div class="invoice-footer">
                            <div>Payment: <strong>${t.payment_method.toUpperCase()}</strong></div>
                            <div>Delivery: <strong>${t.delivery_method.toUpperCase()}</strong></div>
                            <div class="thank-you">
                                Terima kasih telah bertransaksi di ${co.name}
                            </div>
                        </div>
                        
                    </div>
                `;
                
                $('#invoice_container').html(html);
            }
        };
        
        // ====================================================================
        // GLOBAL FUNCTIONS (for inline onclick handlers)
        // ====================================================================
        window.printInvoice = function() {
            window.print();
        };
        
        window.closeInvoiceModal = function() {
            $('#modal_invoice').fadeOut(200);
        };
        
        // ====================================================================
        // EVENT DELEGATION (untuk tombol Print di history panel)
        // ====================================================================
        $(document).on('click', '.btn-print-invoice', function(e) {
            e.preventDefault();
            const refId = $(this).data('ref-id');
            window.POS_Invoice.open(refId, false); // false = jangan auto-print
        });
        
    })(jQuery);
    </script>
    <?php
}