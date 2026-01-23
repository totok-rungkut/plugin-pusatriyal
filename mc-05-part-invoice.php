<?php
/**
 * ============================================================================
 * MC-05 PART INVOICE - Pool Transaction Receipt Generator
 * ============================================================================
 * Version: 7.10.1 (Integration with MC-29 Configuration)
 * Author: Denmas Totok
 * * INTEGRATION POINTS:
 * - Called from: ajax_process_checkout() success (auto-print)
 * - Called from: History panel "Print" button (reprint)
 * * RECEIPT COLOR SCHEME (Manual System Concept):
 * - RED (Agent/Consignment): Customer dengan status PENDING/VERIFIED (belum bayar lunas)
 * - BLUE (Retail Paid): Customer dengan status POSTED (sudah bayar lunas)
 * * DATA SOURCE:
 * - Main: T_POOL_TRANSACTIONS (ref_id primary key)
 * - Customer: pr_customer CPT
 * - Company Profile: MC-29 Configuration (wp_options) [UPDATED]
 * ============================================================================
 */


defined('ABSPATH') || exit;

/**
 * ============================================================
 * AJAX: GET INVOICE DATA
 * ============================================================
 */
add_action('wp_ajax_puri_pos_get_invoice', 'puri_pos_ajax_get_invoice');

/**
 * ============================================================
 * AJAX: PRINT INVOICE PAGE (Open in New Window)
 * ============================================================
 */
add_action('wp_ajax_puri_pos_print_invoice', 'puri_pos_ajax_print_invoice');

function puri_pos_ajax_print_invoice() {
    global $wpdb;
    
    $ref_id = sanitize_text_field($_GET['ref_id'] ?? '');
    
    if (!$ref_id) {
        wp_die('Reference ID tidak valid');
    }
    
    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');
    
    $transaction = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$tbl_pool} WHERE ref_id = %s", $ref_id)
    );
    
    if (!$transaction) {
        wp_die('Transaksi tidak ditemukan');
    }
    
    // ================= CUSTOMER =================
    $customer = [
        'name'    => $transaction->customer_name ?: 'Customer Umum',
        'nik'     => $transaction->customer_nik ?: '-',
        'phone'   => '-',
        'address' => '-',
        'city'    => '-'
    ];
    
    if ($transaction->customer_id > 0) {
        $cust_post = get_post($transaction->customer_id);
        if ($cust_post) {
            $customer['name']    = $cust_post->post_title;
            $customer['nik']     = get_post_meta($transaction->customer_id, '_puri_cust_nik', true) ?: '-';
            $customer['phone']   = get_post_meta($transaction->customer_id, '_puri_cust_phone', true) ?: '-';
            $customer['address'] = get_post_meta($transaction->customer_id, '_puri_cust_address', true) ?: '-';
            $customer['city']    = get_post_meta($transaction->customer_id, '_puri_cust_city', true) ?: '-';
        }
    }
    
    // ================= COMPANY =================
    $logo_id  = get_option('puri_comp_logo', '');
    $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
    
    $company = [
        'logo'    => $logo_url,
        'name'    => get_option('puri_comp_name', 'Pusat Riyal'),
        'address' => get_option('puri_comp_address', '-'),
        'phone'   => get_option('puri_comp_phone', '-'),
        'legal'   => get_option('puri_comp_legal', '-')
    ];
    
    // ================= ITEMS =================
    $items = json_decode($transaction->items_snapshot, true);
    if (!is_array($items)) {
        wp_die('Data item tidak valid');
    }
    
    // ================= CASHIER =================
    $cashier = get_user_by('id', $transaction->created_by);
    $cashier_name = $cashier ? $cashier->display_name : 'System';
    
    // ================= STATUS =================
    $status_map = [
        'posted' => 'LUNAS',
        'pending' => 'BELUM LUNAS',
        'verified' => 'VERIFIED',
        'void' => 'BATAL'
    ];
    //$status_text = $status_map[$transaction->status] ?? 'LUNAS';
    $status_text = 'L.U.N.A.S';
    
    // ================= BUILD HTML =================
    $trx_date = date('d/m/Y H:i', strtotime($transaction->trx_date));
    $print_date = date('d/m/Y H:i');
    
    $rows = '';
    $total_riyal = 0;
    
    foreach ($items as $i => $it) {
        $qty = floatval($it['qty'] ?? 0);
        $denom = floatval($it['denom'] ?? 1);
        $rate = floatval($it['rate'] ?? 0);
        $riyal = $qty * $denom;
        $rupiah = $riyal * $rate;
        $total_riyal += $riyal;
        
        $rows .= sprintf(
            '<tr>
                <td>%d</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
            </tr>',
            $i + 1,
            esc_html($it['name'] ?? $it['sku']),
            number_format($qty, 0, ',', '.'),
            number_format($riyal, 0, ',', '.'),
            number_format($rate, 0, ',', '.'),
            number_format($rupiah, 0, ',', '.')
        );
    }
    
    // ================= TERBILANG =================
    $terbilang = puri_terbilang(floatval($transaction->total_idr));
    
    // ================= CSS URL =================
    $css_url = plugin_dir_url(__FILE__) . '../assets/css/kwitansi.css';
    
    // ================= RENDER DOCUMENT =================
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Print Invoice - <?php echo esc_html($ref_id); ?></title>
        <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
    </head>
    <body>
        <div class="print-wrapper">
            
            <!-- COPY 1 (Original) -->
            <section class="a5-sheet copy-1">
                <div class="watermark"><?php echo esc_html($status_text); ?></div>
                
                <div class="invoice-header">
                    <div class="header-left">
                        <?php if ($company['logo']): ?>
                            <img src="<?php echo esc_url($company['logo']); ?>" alt="Logo">
                        <?php endif; ?>
                        <div class="company-name"><?php echo esc_html($company['name']); ?></div>
                        <div class="company-info">
                            <?php echo esc_html($company['address']); ?><br>
                            <?php echo esc_html($company['phone']); ?><br>
                            <?php echo esc_html($company['legal']); ?>
                        </div>
                    </div>
                    <div class="header-right">
                        <strong>No: <?php echo esc_html($transaction->ref_id); ?></strong><br>
                        <span class="cust"><?php echo esc_html($customer['name']); ?></span><br>
                        <?php echo esc_html($customer['address']); ?>, <?php echo esc_html($customer['city']); ?><br>
                        <?php echo esc_html($customer['phone']); ?>
                    </div>
                </div>
                
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Total Riyal</th>
                            <th>Kurs</th>
                            <th>Rupiah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $rows; ?>
                    </tbody>
                </table>
                
                <div class="subtotal-box">
                    <span>Total Riyal: <?php echo number_format($total_riyal, 0, ',', '.'); ?></span>
                    <span>Total Rupiah: <?php echo number_format($transaction->total_idr, 0, ',', '.'); ?></span>
                </div>
                
                <div class="invoice-footer">
                    <div class="footer-left">
                        <strong>Terbilang:</strong><br>
                        <em><?php echo esc_html($terbilang); ?></em><br><br>
                        Pembayaran: <?php echo esc_html($transaction->payment_method); ?><br>
                        Delivery: <?php echo esc_html($transaction->delivery_method); ?>
                    </div>
                    <div class="footer-right">
                        Tgl Transaksi: <?php echo $trx_date; ?><br>
                        Dicetak: <?php echo $print_date; ?><br>
                        Kasir: <?php echo esc_html($cashier_name); ?>
                    </div>
                </div>
                
                <div class="fold-line"></div>
            </section>
            
            <!-- COPY 2 (Duplicate) -->
            <section class="a5-sheet copy-2">
                <div class="watermark">COPY</div>
                
                <div class="invoice-header">
                    <div class="header-left">
                        <?php if ($company['logo']): ?>
                            <img src="<?php echo esc_url($company['logo']); ?>" alt="Logo">
                        <?php endif; ?>
                        <div class="company-name"><?php echo esc_html($company['name']); ?></div>
                        <div class="company-info">
                            <?php echo esc_html($company['address']); ?><br>
                            <?php echo esc_html($company['phone']); ?><br>
                            <?php echo esc_html($company['legal']); ?>
                        </div>
                    </div>
                    <div class="header-right">
                        <strong>No: <?php echo esc_html($transaction->ref_id); ?></strong><br>
                        <?php echo esc_html($customer['name']); ?><br>
                        <?php echo esc_html($customer['address']); ?>, <?php echo esc_html($customer['city']); ?><br>
                        <?php echo esc_html($customer['phone']); ?>
                    </div>
                </div>
                
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Total Riyal</th>
                            <th>Kurs</th>
                            <th>Rupiah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $rows; ?>
                    </tbody>
                </table>
                
                <div class="subtotal-box">
                    <span>Total Riyal: <?php echo number_format($total_riyal, 0, ',', '.'); ?></span>
                    <span>Total Rupiah: <?php echo number_format($transaction->total_idr, 0, ',', '.'); ?></span>
                </div>
                
                <div class="invoice-footer">
                    <div class="footer-left">
                        <strong>Terbilang:</strong><br>
                        <em><?php echo esc_html($terbilang); ?></em><br><br>
                        Pembayaran: <?php echo esc_html($transaction->payment_method); ?><br>
                        Delivery: <?php echo esc_html($transaction->delivery_method); ?>
                    </div>
                    <div class="footer-right">
                        Tgl Transaksi: <?php echo $trx_date; ?><br>
                        Dicetak: <?php echo $print_date; ?><br>
                        Kasir: <?php echo esc_html($cashier_name); ?>
                    </div>
                </div>
            </section>
            
        </div>
        
        <script>
        // Auto-focus for print, but let user preview first
        window.onload = function() {
            // User can press Ctrl+P manually
            document.title = 'Invoice <?php echo esc_js($ref_id); ?> - Siap Print';
        };
        </script>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Helper: Terbilang Indonesia
 */
function puri_terbilang($angka) {
    $angka = floor(abs($angka));
    $huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
    
    $temp = '';
    
    if ($angka < 12) {
        $temp = ' ' . $huruf[$angka];
    } elseif ($angka < 20) {
        $temp = puri_terbilang($angka - 10) . ' Belas';
    } elseif ($angka < 100) {
        $temp = puri_terbilang($angka / 10) . ' Puluh' . puri_terbilang($angka % 10);
    } elseif ($angka < 200) {
        $temp = ' Seratus' . puri_terbilang($angka - 100);
    } elseif ($angka < 1000) {
        $temp = puri_terbilang($angka / 100) . ' Ratus' . puri_terbilang($angka % 100);
    } elseif ($angka < 2000) {
        $temp = ' Seribu' . puri_terbilang($angka - 1000);
    } elseif ($angka < 1000000) {
        $temp = puri_terbilang($angka / 1000) . ' Ribu' . puri_terbilang($angka % 1000);
    } elseif ($angka < 1000000000) {
        $temp = puri_terbilang($angka / 1000000) . ' Juta' . puri_terbilang($angka % 1000000);
    } elseif ($angka < 1000000000000) {
        $temp = puri_terbilang($angka / 1000000000) . ' Miliar' . puri_terbilang(fmod($angka, 1000000000));
    }
    
    return trim($temp) . ' Rupiah';
}


function puri_pos_ajax_get_invoice() {
    check_ajax_referer('puri_pos_checkout', 'nonce');
    global $wpdb;

    $ref_id = sanitize_text_field($_POST['ref_id'] ?? '');

    if (!$ref_id) {
        wp_send_json_error(['message' => 'Reference ID tidak valid']);
    }

    $tbl_pool = puri_table_name('T_POOL_TRANSACTIONS');

    $transaction = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$tbl_pool} WHERE ref_id = %s", $ref_id)
    );

    if (!$transaction) {
        wp_send_json_error(['message' => 'Transaksi tidak ditemukan']);
    }

    // ================= CUSTOMER =================
    $customer = [
        'name'    => $transaction->customer_name ?: 'Customer Umum',
        'nik'     => $transaction->customer_nik ?: '-',
        'phone'   => '-',
        'address' => '-',
        'city'    => '-'
    ];

    if ($transaction->customer_id > 0) {
        $cust_post = get_post($transaction->customer_id);
        if ($cust_post) {
            $customer['name']    = $cust_post->post_title;
            $customer['nik']     = get_post_meta($transaction->customer_id, '_puri_cust_nik', true) ?: '-';
            $customer['phone']   = get_post_meta($transaction->customer_id, '_puri_cust_phone', true) ?: '-';
            $customer['address'] = get_post_meta($transaction->customer_id, '_puri_cust_address', true) ?: '-';
            $customer['city']    = get_post_meta($transaction->customer_id, '_puri_cust_city', true) ?: '-';
        }
    }

    // ================= COMPANY =================
    $logo_id  = get_option('puri_comp_logo', '');
    $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';

    $company = [
        'logo'    => $logo_url,
        'name'    => get_option('puri_comp_name', 'Pusat Riyal'),
        'address' => get_option('puri_comp_address', '-'),
        'phone'   => get_option('puri_comp_phone', '-'),
        'legal'   => get_option('puri_comp_legal', '-')
    ];

    // ================= ITEMS =================
    $items = json_decode($transaction->items_snapshot, true);
    if (!is_array($items)) {
        wp_send_json_error(['message' => 'Data item tidak valid']);
    }

    // ================= CASHIER =================
    $cashier = get_user_by('id', $transaction->created_by);
    $cashier_name = $cashier ? $cashier->display_name : 'System';

    wp_send_json_success([
        'transaction' => $transaction,
        'customer'    => $customer,
        'company'     => $company,
        'items'       => $items,
        'cashier'     => $cashier_name
    ]);
}


/**
 * ============================================================
 * RENDER MODAL HTML
 * ============================================================
 */
function puri_pos_render_invoice_modal() {
?>
<div id="modal_invoice" class="puri-modal" style="display:none;">
    <div class="puri-modal-content invoice-modal-content">
        <button type="button" class="puri-modal-close" onclick="closeInvoiceModal()">&times;</button>

        <div id="invoice_container"></div>

        <div class="invoice-actions">
            <button type="button" class="button button-primary button-large" onclick="triggerPrintInvoice()">
                🖨 Print Kwitansi
            </button>
            <button type="button" class="button" onclick="closeInvoiceModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
(function($){

    window.POS_Invoice = {

        open(refId, autoPrint = false, forcePrint = false) {


            $('#invoice_container').html('<p>Loading invoice...</p>');
            $('#modal_invoice').fadeIn(200);

            $.post(ajaxurl, {
                action: 'puri_pos_get_invoice',
                ref_id: refId,
                nonce: '<?php echo wp_create_nonce("puri_pos_checkout"); ?>'
            }, function(response){

                if(!response.success){
                    alert(response.data.message || 'Gagal load invoice');
                    return;
                }

POS_Invoice.render(response.data);

// 🔥 PRINT otomatis jika dari checkout ATAU dari history
if (autoPrint || forcePrint) {
    setTimeout(() => triggerPrintInvoice(), 500);
}

            });
        },

        render(data){
            const t  = data.transaction;
            const c  = data.customer;
            const co = data.company;
            const items = data.items;
            const cashier = data.cashier;

            const trxDate = new Date(t.trx_date).toLocaleString('id-ID');

            let rows = '';
            let totalRiyal = 0;

            items.forEach((it, i) => {
                const qty   = parseFloat(it.qty || 0);
                const denom = parseFloat(it.denom || 1);
                const rate  = parseFloat(it.rate || 0);

                const riyal = qty * denom;
                const rupiah = riyal * rate;
                totalRiyal += riyal;

                rows += `
                    <tr>
                        <td>${i+1}</td>
                        <td>${it.name || it.sku}</td>
                        <td>${qty}</td>
                        <td>${riyal.toLocaleString('id-ID')}</td>
                        <td>${rate.toLocaleString('id-ID')}</td>
                        <td>${rupiah.toLocaleString('id-ID')}</td>
                    </tr>`;
            });

            const statusMap = {
                posted: 'LUNAS',
                pending: 'BELUM LUNAS',
                void: 'BATAL'
            };

            const statusText = statusMap[t.status] || 'LUNAS';

            const html = `
                <div class="invoice-header">
                    <div class="header-left">
                        ${co.logo ? `<img src="${co.logo}">` : ''}
                        <div class="company-name">${co.name}</div>
                        <div class="company-info">
                            ${co.address}<br>
                            ${co.phone}<br>
                            ${co.legal}
                        </div>
                    </div>
                    <div class="header-right">
                        <strong>No: ${t.ref_id}</strong><br>
                        <span class="cust">${c.name}</span><br>
                        ${c.address}, ${c.city}<br>
                        ${c.phone}
                    </div>
                </div>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th>No</th><th>Item</th><th>Qty</th>
                            <th>Total Riyal</th><th>Kurs</th><th>Rupiah</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>

                <div class="subtotal-box">
                    <span class="total-riyal">Total Riyal: ${totalRiyal.toLocaleString('id-ID')}</span>
                    <span class="total-idr">Total Rupiah: IDR ${parseFloat(t.total_idr).toLocaleString('id-ID')}</span>
                </div>

                <div class="invoice-footer">
					<div class="footer-left">
						<strong>Terbilang:</strong><br>
						<em><span class="terbilang-text"></span></em><br><br>
						Pembayaran: ${t.payment_method}<br>
						Delivery: ${t.delivery_method}
					</div>

                    <div class="footer-right">
                        Tgl Transaksi: ${trxDate}<br>
                        Dicetak: ${new Date().toLocaleString('id-ID')}<br>
                        Kasir: ${cashier}
                    </div>
                </div>
            `;

			$('#invoice_container').html(html);
			$('#invoice_container').data('status', statusText);
			$('#invoice_container').data('total', parseFloat(t.total_idr));

        }
    };

})(jQuery);


function triggerPrintInvoice(){
    const container = document.getElementById('invoice_container');
    const html   = container.innerHTML;
    const status = container.dataset.status || 'LUNAS';
    const total  = parseFloat(container.dataset.total || 0);

    PuriKwitansi.print(html, status, total);
}


function closeInvoiceModal(){
    jQuery('#modal_invoice').fadeOut(200);
}
</script>
<?php
}
