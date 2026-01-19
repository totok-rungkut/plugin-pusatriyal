<?php
/**
 * =============================================================================
 * MC-03b - PURI Stock Controller (Mozart Bridge)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     7.3.14
 * @author      Mozart Engine Core
 * * Responsibilities:
 * - Update saldo fisik (qty) di tabel T_STOCK (puri_inventory_balance).
 * - Mendukung multi-item dalam satu transaksi (nampan MC-04).
 * - Update kolom 'last_ref' dan 'updated_at' untuk jejak audit fisik.
 * =============================================================================
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Stock_Controller_V7')) {
    class PURI_Stock_Controller_V7 {

        /**
         * Update Balance Fisik
         * Dipanggil oleh Mozart::execute()
         */
        public function update_balance($action_type, $params) {
            global $wpdb;
            $table_stock = puri_table_name('T_STOCK'); // Sinkron MC-01: puri_inventory_balance
			$loc_id      = $params['location_id'] ?? 'MAIN'; // Fallback ke MAIN	
			
            if (empty($params['items'])) {
                throw new Exception("Stocker: trx_param items kosong.");
            }

            foreach ($params['items'] as $it) {
                $item_id = intval($it['item_id']);
                $qty_change = floatval($it['qty']); // Lembaran fisik

                if ($qty_change == 0) continue;

                // Tentukan arah stok berdasarkan action_type
                // Procurement = Masuk (+), POS/Sales = Keluar (-)
                if ($action_type === 'procurement') {
                    $sql_op = "qty + %f";
                } else {
                    $sql_op = "qty - %f";
                }

                /**
                 * UPSERT LOGIC (Update or Insert)
                 * Kita cek apakah baris item_id sudah ada di puri_inventory_balance.
                 */
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_stock} WHERE item_id = %d", 
                    $item_id
                ));

                if ($exists) {
                    // UPDATE: Tambah/Kurang saldo yang ada
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table_stock} 
                         SET qty = {$sql_op}, 
                             last_ref = %s, 
                             updated_at = %s 
                         WHERE item_id = %d",
                        $qty_change,
                        $params['source_ref'],
                        $params['created_at'],
                        $item_id
                    ));
                } else {
                    // INSERT: Buat baris baru jika item belum pernah ada saldo
                    $wpdb->insert($table_stock, [
                        'item_id'    => $item_id,
                        'qty'        => ($action_type === 'procurement' ? $qty_change : -$qty_change),
                        'last_ref'   => $params['source_ref'],
                        'updated_at' => $params['created_at']
                    ]);
                }

                // Cek jika terjadi error database
                if ($wpdb->last_error) {
                    throw new Exception("Stocker Database Error: " . $wpdb->last_error);
                }
            }

            return true;
        }
    }
}