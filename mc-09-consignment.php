<?php
/**
 * MC 09 - Consignment Engine (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Manage consignment (titip jual) to agents: record outbound, ledger, consign table and journal entry
 *
 * Improvements:
 *  - Nonce & capability checks on admin submission
 *  - Table creation idempotent
 *  - Uses $puri_engine for stock update and ledger insertion
 *  - Sanitization of inputs and error handling with rollback
 */

defined('ABSPATH') || exit;




add_action('admin_init', function() {
    // Ensure consign table exists (admin only)
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $sql = "CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . T_CONSIGN . " (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        date_out datetime DEFAULT CURRENT_TIMESTAMP,
        agent_id bigint(20) NOT NULL,
        item_id bigint(20) NOT NULL,
        qty_out decimal(19,4) DEFAULT 0,
        taken_rate decimal(19,4) DEFAULT 0,
        status enum('active','returned','sold') DEFAULT 'active',
        PRIMARY KEY (id)
    ) $collate;";
    dbDelta($sql);
});

function puri_render_consignment_page() {
    puri_check_cap('manage_options');
    global $wpdb, $puri_engine;

    $agents = get_users(['role__in'=>['administrator','editor','author','shop_manager'],'number'=>-1]); // simplistic; adapt to real agent filter
    $items = $wpdb->get_results("SELECT id, sku FROM " . puri_table_name('T_ITEMS') . " WHERE type = 'currency'");

    // handle POST submit
    if (isset($_POST['puri_submit_consign'])) {
        if (!wp_verify_nonce($_POST['puri_admin_nonce'] ?? '', 'puri_admin_action')) {
            add_action('admin_notices', function(){ echo '<div class="notice notice-error"><p>Nonce failed.</p></div>'; });
        } else {
            $agent_id = intval($_POST['agent_id'] ?? 0);
            $item_id = intval($_POST['item_id'] ?? 0);
            $qty_lembar = intval($_POST['qty_lembar'] ?? 0);
            $taken_rate = floatval($_POST['taken_rate'] ?? 0);
            if ($agent_id<=0 || $item_id<=0 || $qty_lembar<=0 || $taken_rate<=0) {
                add_action('admin_notices', function(){ echo '<div class="notice notice-error"><p>Invalid input.</p></div>'; });
            } else {
                // transaction
                $wpdb->query('START TRANSACTION');
                try {
                    // check stock in laci
                    $stock_laci = $wpdb->get_var($wpdb->prepare("SELECT qty FROM " . puri_table_name('T_STOCK') . " WHERE location_id='laci_kasir' AND item_id=%d", $item_id));
                    $stock_laci = $stock_laci ? floatval($stock_laci) : 0;
                    if ($stock_laci < $qty_lembar) throw new Exception('Stok laci tidak mencukupi');

                    // update stock
                    $res = $puri_engine->update_stock_atomic('laci_kasir', $item_id, -$qty_lembar);
                    if (is_wp_error($res)) throw new Exception($res->get_error_message());

                    // ledger
                    $ref_id = 'CSN-' . date('YmdHis');
                    $ins = $wpdb->insert(puri_table_name('T_LEDGER'), [
                        'location_id'=>'laci_kasir','item_id'=>$item_id,'qty_change'=>-$qty_lembar,'ref_id'=>$ref_id,'description'=>'Titip jual (Konsinyasi) ke Agen ID: '.$agent_id,'trx_date'=>current_time('mysql')
                    ]);
                    if ($ins === false) throw new Exception($wpdb->last_error);

                    // insert consign record
                    $wpdb->insert($wpdb->prefix . T_CONSIGN, [
                        'agent_id'=>$agent_id,'item_id'=>$item_id,'qty_out'=>$qty_lembar,'taken_rate'=>$taken_rate,'status'=>'active'
                    ]);

                    // journal (debit persediaan consign)
                    $jr = $puri_engine->post_journal($ref_id, '1401', $qty_lembar * $taken_rate, 0, "Konsinyasi out ke Agen ID: $agent_id");
                    if (is_wp_error($jr)) throw new Exception($jr->get_error_message());

                    $wpdb->query('COMMIT');
                    add_action('admin_notices', function(){ echo '<div class="notice notice-success"><p>✅ Sukses! Barang telah diserahkan ke Agen.</p></div>'; });
                } catch (Exception $e) {
                    $wpdb->query('ROLLBACK');
                    add_action('admin_notices', function() use ($e){ echo '<div class="notice notice-error"><p>Gagal: '.esc_html($e->getMessage()).'</p></div>'; });
                }
            }
        }
    }

    ?>
    <div class="wrap">
      <h1>🤝 Penyerahan Konsinyasi Agen</h1>
      <form method="post">
        <?php puri_create_admin_nonce_field(); ?>
        <table class="form-table">
          <tr><th><label>Pilih Agen</label></th><td>
            <select name="agent_id" required>
              <option value="">-- Pilih Agen --</option>
              <?php foreach($agents as $ag): ?><option value="<?php echo intval($ag->ID); ?>"><?php echo esc_html($ag->display_name); ?></option><?php endforeach; ?>
            </select>
          </td></tr>
          <tr><th><label>Item Valas</label></th><td>
            <select name="item_id" required><?php foreach($items as $it): ?><option value="<?php echo intval($it->id); ?>"><?php echo esc_html($it->sku); ?></option><?php endforeach; ?></select>
          </td></tr>
          <tr><th><label>Qty (Lembar)</label></th><td><input type="number" name="qty_lembar" required></td></tr>
          <tr><th><label>Kurs Pengikatan</label></th><td><input type="number" name="taken_rate" required></td></tr>
        </table>
        <p><button class="button button-primary" type="submit" name="puri_submit_consign">SERAHKAN BARANG KE AGEN</button></p>
      </form>
    </div>
    <?php
}