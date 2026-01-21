<?php
/**
 * =============================================================================
 * MC-03b - PURI Stock Controller (Mozart Bridge)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     7.4.0
 * @author      Mozart Engine Core
 * * Responsibilities:
 * - Update saldo fisik (qty) di tabel T_STOCK (puri_inventory_balance).
 * - Mendukung multi-item dalam satu transaksi (nampan MC-04).
 * - Update kolom 'last_ref' dan 'updated_at' untuk jejak audit fisik.
 *
 * CHANGELOG v7.4.0:
 * - Added dual-location transfer logic
 * - Added wp_post_id bridge sync
 * =============================================================================
 */


defined('ABSPATH') || exit;

if (!class_exists('PURI_Stock_Controller_V7')) {
    class PURI_Stock_Controller_V7 {

        public function update_balance($action_type, $params) {
            global $wpdb;
            $table_stock = puri_table_name('T_STOCK');
            
            if (empty($params['items'])) {
                throw new Exception("Stocker: trx_param items kosong.");
            }

            // ================================================================
            // NEW: STOCK TRANSFER HANDLER
            // ================================================================
            if ($action_type === 'stock_transfer') {
                $source_loc = $params['source_location'];
                $target_loc = $params['target_location'];
                
                foreach ($params['items'] as $it) {
                    $item_id = intval($it['item_id']);
                    $wp_id   = intval($it['wp_post_id']);
                    $qty     = floatval($it['qty']);
                    
                    if ($qty <= 0) continue;
                    
                    // --------------------------------------------------------
                    // 1. DECREASE SOURCE LOCATION
                    // --------------------------------------------------------
                    $source_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table_stock} 
                         WHERE item_id = %d AND location_id = %s", 
                        $item_id, $source_loc
                    ));
                    
                    if (!$source_exists) {
                        throw new Exception("Item ID {$item_id} tidak ditemukan di lokasi {$source_loc}");
                    }
                    
                    // Check stock sufficiency
                    $current_balance = $wpdb->get_var($wpdb->prepare(
                        "SELECT balance FROM {$table_stock} 
                         WHERE item_id = %d AND location_id = %s",
                        $item_id, $source_loc
                    ));
                    
                    if ($current_balance < $qty) {
                        throw new Exception("Stok tidak cukup! Tersedia: {$current_balance}, Diminta: {$qty}");
                    }
                    
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table_stock} 
                         SET balance = balance - %f,
                             wp_post_id = %d,
                             last_ref = %s,
                             updated_at = %s
                         WHERE item_id = %d AND location_id = %s",
                        $qty, $wp_id, $params['source_ref'], 
                        $params['created_at'], $item_id, $source_loc
                    ));
                    
                    // --------------------------------------------------------
                    // 2. INCREASE TARGET LOCATION (UPSERT)
                    // --------------------------------------------------------
                    $target_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table_stock} 
                         WHERE item_id = %d AND location_id = %s",
                        $item_id, $target_loc
                    ));
                    
                    if ($target_exists) {
                        // UPDATE existing record
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$table_stock} 
                             SET balance = balance + %f,
                                 wp_post_id = %d,
                                 last_ref = %s,
                                 updated_at = %s
                             WHERE item_id = %d AND location_id = %s",
                            $qty, $wp_id, $params['source_ref'], 
                            $params['created_at'], $item_id, $target_loc
                        ));
                    } else {
                        // INSERT new record
                        $wpdb->insert($table_stock, [
                            'item_id'     => $item_id,
                            'wp_post_id'  => $wp_id,
                            'location_id' => $target_loc,
                            'balance'     => $qty,
                            'last_ref'    => $params['source_ref'],
                            'updated_at'  => $params['created_at']
                        ]);
                    }
                    
                    if ($wpdb->last_error) {
                        throw new Exception("Stocker DB Error: " . $wpdb->last_error);
                    }
                }
                
                return true;
            }
            
            // ================================================================
            // EXISTING: PROCUREMENT & POS LOGIC (with wp_post_id patch)
            // ================================================================
            $loc_id = $params['location_id'] ?? puri_get_default_location();
            
            foreach ($params['items'] as $it) {
                $item_id = intval($it['item_id']);
                $wp_id   = intval($it['wp_post_id'] ?? 0); // PATCH: Get from payload
                $qty_change = floatval($it['qty']);

                if ($qty_change == 0) continue;

                if ($action_type === 'procurement') {
                    $sql_op = "balance + %f";
                } else {
                    $sql_op = "balance - %f";
                }

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_stock} WHERE item_id = %d AND location_id = %s", 
                    $item_id, $loc_id
                ));

                if ($exists) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table_stock} 
                         SET balance = {$sql_op}, 
                             wp_post_id = %d,
                             last_ref = %s, 
                             updated_at = %s 
                         WHERE item_id = %d AND location_id = %s",
                        $qty_change, $wp_id, $params['source_ref'],
                        $params['created_at'], $item_id, $loc_id
                    ));
                } else {
                    $wpdb->insert($table_stock, [
                        'item_id'     => $item_id,
                        'wp_post_id'  => $wp_id,
                        'location_id' => $loc_id,
                        'balance'     => ($action_type === 'procurement' ? $qty_change : -$qty_change),
                        'last_ref'    => $params['source_ref'],
                        'updated_at'  => $params['created_at']
                    ]);
                }

                if ($wpdb->last_error) {
                    throw new Exception("Stocker Database Error: " . $wpdb->last_error);
                }
            }

            return true;
        }
    }
}