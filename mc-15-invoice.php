<?php
/**
 * MC 15 - Invoice & Receipt Generator
 * Version: 6.0.1
 * Changes:
 * - Menempel pada Pilar 2 (puri-transaksi) sebagai menu tersembunyi.
 * - Menggunakan puri_check_cap() dan puri_table_name() v6.0.1.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    // Daftarkan invoice di bawah Transaksi, tapi sembunyikan dari sidebar jika ingin bersih
    // Atau tampilkan agar admin bisa cetak ulang manual
    add_submenu_page(
        'puri-transaksi', 
        'Invoice', 
        '📄 Invoice Viewer', 
        'manage_options', 
        'puri-invoice', 
        'puri_render_invoice_page'
    );
}, 25);

function puri_render_invoice_page() {
    puri_check_cap('manage_options'); //
    global $wpdb;

    $ref_id = isset($_GET['ref_id']) ? sanitize_text_field($_GET['ref_id']) : '';
    if (!$ref_id) {
        echo '<div class="notice notice-error"><p>Reference ID tidak ditemukan.</p></div>';
        return;
    }

    // Ambil data dari Jurnal (Snapshot JSON v5.1.7 style)
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . puri_table_name('T_JOURNAL') . " WHERE ref_id = %s LIMIT 1", 
        $ref_id
    ));

    if (!$invoice) {
        echo '<div class="notice notice-error"><p>Data Invoice tidak ditemukan di database.</p></div>';
        return;
    }

    ?>
    <div class="wrap">
        <div style="background:#fff; padding:30px; border:1px solid #d1d5db; max-width:500px; margin: 20px auto; border-radius:8px; font-family:monospace;">
            <center>
                <h2 style="margin:0;">PUSAT RIYAL</h2>
                <p style="font-size:12px;">Bukti Transaksi Penjualan</p>
                <hr style="border:none; border-top:1px dashed #000;">
            </center>
            
            <table style="width:100%; font-size:13px;">
                <tr><td>Ref ID</td><td>: <?php echo esc_html($invoice->ref_id); ?></td></tr>
                <tr><td>Tanggal</td><td>: <?php echo esc_html($invoice->trx_date); ?></td></tr>
                <tr><td>Ket</td><td>: <?php echo esc_html($invoice->description); ?></td></tr>
            </table>

            <hr style="border:none; border-top:1px dashed #000;">
            
            <div style="text-align:right; font-weight:900; font-size:18px;">
                TOTAL: Rp <?php echo number_format($invoice->debit + $invoice->credit); ?>
            </div>

            <div style="margin-top:30px; text-align:center;">
                <button class="button button-primary no-print" onclick="window.print()">CETAK STRUK</button>
                <a href="admin.php?page=puri-pos" class="button no-print">KEMBALI KE KASIR</a>
            </div>
        </div>

        <style>
            @media print { .no-print { display:none; } }
        </style>
    </div>
    <?php
}