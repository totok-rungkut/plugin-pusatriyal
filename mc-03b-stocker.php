<?php
/**
 * MC-03B - STOCK & VALUATION CONTROLLER (MOZART COMPATIBLE)
 * @version 7.2.0
 * * Responsibilities:
 * - Update physical stock in T_STOCK
 * - Calculate Moving Average (HPP) only on Procurement
 * - Protect base_price from retail volatility
 */

defined('ABSPATH') || exit;

if (!class_exists('PURI_Stock_Controller_V7')) {
    class PURI_Stock_Controller_V7 {

        /**
         * Update Stock Balance & Trigger Valuation
         */
        public function update_balance($action, $p) {
            global $wpdb;
            $table = puri_table_name('T_STOCK');

// Validation (claude.ai)
    if (empty($p['item_id']) || !is_numeric($p['item_id'])) {
        throw new Exception("Invalid item_id: " . var_export($p['item_id'], true));
    }
    
    if (!isset($p['qty']) || !is_numeric($p['qty'])) {
        throw new Exception("Invalid qty: " . var_export($p['qty'], true));
    }

            
            // Default ke 'laci_kasir' jika tidak ditentukan (untuk POS)
            // Default ke 'gudang_utama' jika action adalah procurement
            $location = isset($p['location_id']) ? $p['location_id'] : (($action === 'procurement') ? 'gudang_utama' : 'laci_kasir');

            // 1. UPDATE FISIK (Atomic Insertion/Update) - refactor by Claude after Git
$status = $wpdb->query($wpdb->prepare(
    "INSERT INTO {$table} (item_id, location_id, stock_qty, last_updated) 
     VALUES (%d, %s, %f, NOW()) 
     ON DUPLICATE KEY UPDATE 
        stock_qty = stock_qty + %f, 
        last_updated = NOW()",
    intval($p['item_id']), 
    sanitize_text_field($location), 
    floatval($p['qty']),
    floatval($p['qty'])  // Parameter tambahan untuk ON DUPLICATE KEY
));

            if ($status === false) {
                throw new Exception("CRITICAL: Gagal update stok fisik untuk Item ID: " . $p['item_id']);
            }

            // 2. UPDATE VALUASI (HPP)
            // Hanya dijalankan jika aksi adalah PROCUREMENT dari Vendor
            if ($action === 'procurement' && isset($p['unit_price'])) {
                $this->apply_moving_average($p['item_id'], $p['qty'], $p['unit_price']);
            }
        }

        /**
         * LOGIKA MOVING AVERAGE (HPP Rata-rata)
         * Rumus: ((Stok_Lama * HPP_Lama) + (Stok_Baru * Harga_Beli)) / Total_Stok_Baru
         */
        protected function apply_moving_average($item_id, $new_qty, $buy_price) {
            global $wpdb;
            $tbl_items = puri_table_name('T_ITEMS');
            $tbl_stock = puri_table_name('T_STOCK');

            // Ambil data HPP saat ini dan Total Stok di semua lokasi
            $current_data = $wpdb->get_row($wpdb->prepare(
                "SELECT i.base_price, SUM(s.stock_qty) as total_qty 
                 FROM {$tbl_items} i 
                 LEFT JOIN {$tbl_stock} s ON i.id = s.item_id 
                 WHERE i.id = %d GROUP BY i.id", 
                $item_id
            ));

            $old_hpp = floatval($current_data->base_price ?? 0);
            $total_after = floatval($current_data->total_qty ?? 0);
            
            // Kita hitung stok sebelum penambahan tadi (karena query INSERT sudah jalan di atas)
            $old_total_qty = $total_after - $new_qty;

            // Safety check: Jika stok lama minus, kita reset kalkulasi dari nol agar HPP tidak rusak
            if ($old_total_qty < 0) {
                $old_total_qty = 0;
            }

            // HITUNG HPP BARU
            $old_valuation = $old_total_qty * $old_hpp;
            $new_valuation = $new_qty * $buy_price;
            $combined_qty  = $old_total_qty + $new_qty;

            if ($combined_qty > 0) {
                $new_avg_price = ($old_valuation + $new_valuation) / $combined_qty;

                // Update Master Item dengan HPP terbaru
                $wpdb->update(
                    $tbl_items,
                    ['base_price' => $new_avg_price],
                    ['id' => $item_id],
                    ['%f'], 
                    ['%d']
                );
                
                // Log untuk kebutuhan debug/audit trail internal jika diperlukan
                // error_log("HPP Updated for Item $item_id: Old=$old_hpp, New=$new_avg_price");
            }
        }
    }
}