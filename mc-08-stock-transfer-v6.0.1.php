<?php
/**
 * MC 08 - Stock Transfer & Laci Controller (Refactor)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Admin UI to transfer stock from Gudang Utama -> Laci Kasir (unit: Brot/Bendel)
 *  - Performs atomic stock updates and ledger entries
 *
 * Security & Improvements:
 *  - Nonce & capability checks for AJAX
 *  - Input validation and sanitization
 *  - Uses $puri_engine->update_stock_atomic for atomic stock changes
 *  - Wraps multi-item transfer inside DB transaction for consistency
 *  - Provides sound UX but preserves original UI look/behavior
 *
 * Dependencies: MC_01 (tables), MC_03 (engine)
 */

defined('ABSPATH') || exit;

add_action('wp_ajax_puri_execute_transfer_ajax', 'puri_execute_transfer_ajax_handler');

function puri_render_transfer_page() {
    puri_check_cap('manage_options');
    global $wpdb;

    $items = $wpdb->get_results("
        SELECT i.id, i.sku, i.name, i.denom_value, COALESCE(s.qty, 0) as stock_gudang
        FROM " . puri_table_name('T_ITEMS') . " i
        LEFT JOIN " . puri_table_name('T_STOCK') . " s ON i.id = s.item_id AND s.location_id = 'gudang_utama'
        WHERE i.type = 'currency'
        ORDER BY i.denom_value ASC, i.sku ASC
    ");

    ?>
    <div class="wrap">
      <style>.ts-card{background:#fff;padding:18px;border:1px solid #e2e8f0;border-radius:12px}</style>
      <h1>🚚 Transfer ke Laci</h1>
      <p style="color:#64748b">Klik item untuk memindahkan 1 Bendel (100 pcs) ke Laci Kasir.</p>

      <div class="ts-card">
        <div style="display:grid;grid-template-columns:1fr 420px;gap:18px">
          <div>
            <h3>Gudang Utama</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
              <?php foreach($items as $it): 
                $available_bendel = floor(floatval($it->stock_gudang) / 100);
              ?>
                <div style="border:1px solid #e2e8f0;padding:12px;border-radius:8px;cursor:pointer;" data-id="<?php echo intval($it->id); ?>" data-bendel="<?php echo $available_bendel; ?>" class="ts-item">
                  <strong><?php echo esc_html($it->sku); ?></strong><br>
                  <small><?php echo esc_html($it->name); ?></small>
                  <div style="margin-top:8px;color:#059669;font-weight:800"><?php echo intval(floor($it->stock_gudang/1000)); ?> Brot / <?php echo intval(floor(($it->stock_gudang%1000)/100)); ?> Bendel</div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <h3>Antrean Pindah Laci</h3>
            <div id="transfer-list" style="min-height:200px;border:1px dashed #e2e8f0;padding:12px;border-radius:8px"></div>
            <div style="margin-top:12px">
              <?php puri_create_admin_nonce_field(); ?>
              <button id="confirm-transfer" class="button button-primary" disabled>KONFIRMASI PINDAH SEKARANG</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function($){
      const list = [];
      function renderList() {
        if (list.length === 0) {
          $('#transfer-list').html('<div style="color:#94a3b8;padding:20px;text-align:center">Klik item di kiri untuk menambahkan ke antrean.</div>');
          $('#confirm-transfer').prop('disabled', true);
          return;
        }
        let html = '';
        list.forEach(i => {
          html += '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9"><div><strong>'+i.sku+'</strong><br><small>'+i.name+'</small></div><div><button data-id="'+i.id+'" class="remove btn">BATAL</button> <span style="background:#0f172a;color:#fff;padding:6px 10px;border-radius:6px">'+i.bendel+' Bendel</span></div></div>';
        });
        $('#transfer-list').html(html);
        $('#confirm-transfer').prop('disabled', false);
      }

      $('.ts-item').on('click', function(){
        const id = $(this).data('id'), bendel = parseInt($(this).data('bendel'));
        if (bendel <= 0) { alert('Stok gudang tidak cukup'); return; }
        const sku = $(this).find('strong').text(), name = $(this).find('small').text();
        const exist = list.find(x=>x.id==id);
        if (exist) exist.bendel++;
        else list.push({id:id, sku:sku, name:name, bendel:1});
        renderList();
      });

      $(document).on('click','.remove', function(){ const id = $(this).data('id'); for(let i=0;i<list.length;i++){ if(list[i].id==id){ list.splice(i,1); break; } } renderList(); });

      $('#confirm-transfer').on('click', function(){
        if (!confirm('Konfirmasi pindah stok ke laci?')) return;
        const payload = list.map(i=>({id:i.id,bendel:i.bendel}));
        $.post(ajaxurl, { action:'puri_execute_transfer_ajax', puri_admin_nonce: $('input[name="puri_admin_nonce"]').val(), items: JSON.stringify(payload) }, function(res){
          if (res.success) { alert(res.data.message); location.reload(); } else { alert('Gagal: '+(res.data.message||res.data)); }
        });
      });

      renderList();
    })(jQuery);
    </script>
    <?php
}

function puri_execute_transfer_ajax_handler() {
    check_ajax_referer('puri_admin_action', 'puri_admin_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized']);

    global $wpdb, $puri_engine;
    if (!isset($puri_engine)) wp_send_json_error(['message'=>'Engine not available']);

    $items_raw = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : null;
    if (!is_array($items_raw) || empty($items_raw)) wp_send_json_error(['message'=>'Invalid payload']);

    $wpdb->query('START TRANSACTION');
    try {
        foreach ($items_raw as $it) {
            $id = intval($it['id']);
            $bendel = intval($it['bendel']);
            if ($id<=0 || $bendel<=0) throw new Exception('Invalid item');
            $pcs = $bendel * 100;
            // Subtract from gudang, add to laci
            $res1 = $puri_engine->update_stock_atomic('gudang_utama', $id, -$pcs);
            if (is_wp_error($res1)) throw new Exception($res1->get_error_message());
            $res2 = $puri_engine->update_stock_atomic('laci_kasir', $id, $pcs);
            if (is_wp_error($res2)) throw new Exception($res2->get_error_message());
            // ledger entries
            $ins1 = $wpdb->insert(puri_table_name('T_LEDGER'), ['location_id'=>'gudang_utama','item_id'=>$id,'qty_change'=>-$pcs,'ref_id'=>'TFR-'.date('YmdHis'),'description'=>'Transfer ke Laci','trx_date'=>current_time('mysql')]);
            $ins2 = $wpdb->insert(puri_table_name('T_LEDGER'), ['location_id'=>'laci_kasir','item_id'=>$id,'qty_change'=>$pcs,'ref_id'=>'TFR-'.date('YmdHis'),'description'=>'Transfer dari Gudang','trx_date'=>current_time('mysql')]);
            if ($ins1 === false || $ins2 === false) throw new Exception($wpdb->last_error);
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['message'=>'Stok berhasil dipindah.']);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message'=>'Transfer failed: '.$e->getMessage()]);
    }
}