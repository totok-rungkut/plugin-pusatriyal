<?php
/**
 * =============================================================================
 * MC-03a - PURI Inventory Ledger (Mozart Bridge)
 * =============================================================================
 * @package     Pusat Riyal
 * @version     7.4.0
 * @author      Mozart Engine Core
 * * Responsibilities:
 * - Mencatat riwayat mutasi stok barang ke tabel T_LEDGER.
 * - Menjaga audit trail pergerakan barang (Qty Change & Balance Snapshot).
 * - Sinkronisasi ref_id dengan Jurnal Umum untuk pelacakan lintas modul.
 * 
 * CHANGELOG v7.4.0:
 * - Added stock_transfer mutation recording
 * - Added wp_post_id bridge column
 * =============================================================================
 */


defined('ABSPATH') || exit;

if (!class_exists('PURI_Inventory_Ledger_V7')) {
    class PURI_Inventory_Ledger_V7 {

        public function record_mutation($params) {
            global $wpdb;
            $table_ledger = puri_table_name('T_LEDGER');
            
            if (empty($params['items'])) {
                return;
            }

            foreach ($params['items'] as $it) {
                $item_id = intval($it['item_id']);
                $wp_id   = intval($it['wp_post_id'] ?? 0);
                $qty     = floatval($it['qty']);

                if ($qty == 0) continue;

                // ============================================================
                // RESOLUSI ACTION TYPE & DIRECTION
                // ============================================================
                $action     = $params['source'] ?? '';
                $is_buyback = $params['is_buyback'] ?? false;
                
                // ============================================================
                // NEW: STOCK TRANSFER HANDLER
                // ============================================================
                if ($action === 'stock_transfer') {
                    $source_loc = $params['source_location'];
                    $target_loc = $params['target_location'];
                    $item_name  = $it['name'] ?? 'Unknown Item';
                    
                    // 1. RECORD OUTBOUND (Source Location)
                    $wpdb->insert($table_ledger, [
                        'trx_date'    => $params['created_at'],
                        'location_id' => $source_loc,
                        'item_id'     => $item_id,
                        'wp_post_id'  => $wp_id,
                        'qty_change'  => -$qty, // Negative = OUT
                        'trx_type'    => 'transfer_out',
                        'ref_id'      => $params['source_ref'],
                        'description' => sprintf(
                            "Transfer OUT: %s (Qty: %s) ke %s",
                            $item_name,
                            number_format($qty, 0, ',', '.'),
                            $target_loc
                        )
                    ]);
                    
                    // 2. RECORD INBOUND (Target Location)
                    $wpdb->insert($table_ledger, [
                        'trx_date'    => $params['created_at'],
                        'location_id' => $target_loc,
                        'item_id'     => $item_id,
                        'wp_post_id'  => $wp_id,
                        'qty_change'  => +$qty, // Positive = IN
                        'trx_type'    => 'transfer_in',
                        'ref_id'      => $params['source_ref'],
                        'description' => sprintf(
                            "Transfer IN: %s (Qty: %s) dari %s",
                            $item_name,
                            number_format($qty, 0, ',', '.'),
                            $source_loc
                        )
                    ]);
                    
                    continue; // Skip to next item
                }
                
                // ============================================================
                // EXISTING: PROCUREMENT & POS LOGIC
                // ============================================================
                $prefix = "Procurement";
                $direction = 1;

                if ($action === 'pos_submission') {
                    if ($is_buyback) {
                        $prefix = "POS Buyback";
                        $direction = 1;
                    } else {
                        $prefix = "POS Sales";
                        $direction = -1;
                    }
                }

                $qty_change = $qty * $direction;
                $item_name = $it['name'] ?? '';
                
                if (empty($item_name)) {
                    $item_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT name FROM " . puri_table_name('T_ITEMS') . " WHERE id = %d", 
                        $item_id
                    )) ?: 'Unknown Item #' . $item_id;
                }

                $mutation_desc = sprintf(
                    "%s: %s (Qty: %s)", 
                    $prefix,
                    $item_name, 
                    number_format($qty, 0, ',', '.')
                );

                if (!empty($params['description'])) {
                    $mutation_desc .= " | " . $params['description'];
                }

                $wpdb->insert($table_ledger, [
                    'trx_date'    => $params['created_at'] ?? current_time('mysql'),
                    'location_id' => $params['location_id'] ?? puri_get_default_location(),
                    'item_id'     => $item_id,
                    'wp_post_id'  => $wp_id,
                    'qty_change'  => $qty_change,
                    'trx_type'    => $action,
                    'ref_id'      => $params['source_ref'],
                    'description' => $mutation_desc
                ]);

                if ($wpdb->last_error) {
                    throw new Exception("Ledger Error: " . $wpdb->last_error);
                }
            }

            return true;
        }
    }
}