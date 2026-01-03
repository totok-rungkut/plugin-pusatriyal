<?php
/**
 * MC 03 - PURI Engine V6
 * Version: 6.0.1
 *
 * Purpose:
 *  - Centralized inventory & accounting engine for:
 *      * post_journal
 *      * update_stock (atomic)
 *      * get_available_stock (consider virtual locks)
 *      * calculate_moving_avg
 *      * adjust_virtual_lock
 *
 * Security & Improvements:
 *  - Use atomic DB operations (INSERT ... ON DUPLICATE KEY UPDATE, or UPDATE qty = qty + X)
 *  - Wrap multi-step operations in SQL transaction (START TRANSACTION / COMMIT / ROLLBACK)
 *  - Return WP_Error on failure; handlers should check and act accordingly
 *  - Use puri_table_name() helper to obtain table names (defined in MC00/MC01)
 */

defined('ABSPATH') || exit;

if (!defined('PURI_VERSION')) define('PURI_VERSION', '6.0.1');

// Ensure helper puri_table_name exists
if (!function_exists('puri_table_name')) {
    function puri_table_name($const_name) {
        global $wpdb;
        if (defined($const_name)) {
            return $wpdb->prefix . constant($const_name);
        }
        return '';
    }
}

if (!class_exists('PURI_Engine_V6')) {
    class PURI_Engine_V6 {
        protected $wpdb;
        protected $items_table;
        protected $stock_table;
        protected $ledger_table;
        protected $journal_table;
        protected $locks_table;

        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->items_table  = puri_table_name('T_ITEMS');
            $this->stock_table  = puri_table_name('T_STOCK');
            $this->ledger_table = puri_table_name('T_LEDGER');
            $this->journal_table= puri_table_name('T_JOURNAL');
            $this->locks_table  = puri_table_name('T_LOCKS');
        }

        /**
         * 1. Post Journal (Double-entry)
         *    Returns insert id or WP_Error.
         */
        public function post_journal($ref_id, $acc_code, $debit = 0, $credit = 0, $desc = '', $snapshot = null) {
            if (empty($ref_id) || empty($acc_code)) {
                return new WP_Error('invalid_args', 'ref_id and acc_code required');
            }
            $data = [
                'ref_id' => sanitize_text_field($ref_id),
                'account_code' => sanitize_text_field($acc_code),
                'debit' => floatval($debit),
                'credit' => floatval($credit),
                'description' => sanitize_textarea_field($desc),
                'snapshot_json' => $snapshot ? wp_json_encode($snapshot) : null,
                'created_by' => get_current_user_id(),
                'trx_date' => current_time('mysql'),
            ];
            $res = $this->wpdb->insert($this->journal_table, $data);
            if ($res === false) {
                return new WP_Error('db_error', $this->wpdb->last_error);
            }
            return (int) $this->wpdb->insert_id;
        }

        /**
         * 2. Atomic stock update.
         *    Uses INSERT ... ON DUPLICATE KEY UPDATE to avoid read-modify-write race.
         *    qty_change may be negative.
         */
        public function update_stock_atomic($location_id, $item_id, $qty_change) {
            if (empty($location_id) || empty($item_id)) {
                return new WP_Error('invalid_args', 'location_id and item_id required');
            }
            $location_id = sanitize_text_field($location_id);
            $item_id = intval($item_id);
            $qty_change = floatval($qty_change);

            // Prepare query - assumes UNIQUE(location_id, item_id) exists
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->stock_table} (location_id, item_id, qty)
                 VALUES (%s, %d, %f)
                 ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty + %f)",
                $location_id, $item_id, $qty_change, $qty_change
            );
            $ok = $this->wpdb->query($sql);
            if ($ok === false) return new WP_Error('db_error', $this->wpdb->last_error);
            return true;
        }

        /**
         * 3. Get available stock (Physical - Locked)
         */
        public function get_available_stock($location_id, $item_id) {
            $location_id = sanitize_text_field($location_id);
            $item_id = intval($item_id);
            $physical_qty = $this->wpdb->get_var($this->wpdb->prepare("SELECT qty FROM {$this->stock_table} WHERE location_id = %s AND item_id = %d", $location_id, $item_id));
            $physical_qty = $physical_qty !== null ? floatval($physical_qty) : 0;

            $locked_qty = 0;
            if ($location_id === 'laci_kasir' && $this->locks_table) {
                $locked_qty = $this->wpdb->get_var($this->wpdb->prepare("SELECT qty_lock FROM {$this->locks_table} WHERE item_id = %d", $item_id));
                $locked_qty = $locked_qty !== null ? floatval($locked_qty) : 0;
            }
            return max(0, $physical_qty - $locked_qty);
        }

        /**
         * 4. Calculate Moving Average (HPP)
         *    This method attempts to be safe: uses stock totals from DB without read-then-write on master items.
         *    Returns new average price or WP_Error.
         */
        public function calculate_moving_avg($item_id, $qty_in, $price_new) {
            $item_id = intval($item_id);
            $qty_in = floatval($qty_in);
            $price_new = floatval($price_new);

            // Begin transaction for read-consistency
            $this->wpdb->query('START TRANSACTION');

            $item = $this->wpdb->get_row($this->wpdb->prepare("SELECT base_price FROM {$this->items_table} WHERE id = %d FOR UPDATE", $item_id));
            $total_stock = $this->wpdb->get_var($this->wpdb->prepare("SELECT SUM(qty) FROM {$this->stock_table} WHERE item_id = %d", $item_id));
            $total_stock = $total_stock !== null ? floatval($total_stock) : 0;

            // Derive old_stock = total_stock - qty_in (since qty_in already included in total_stock when called after update_stock)
            $old_stock = max(0, $total_stock - $qty_in);
            $old_price = ($item && isset($item->base_price)) ? floatval($item->base_price) : 0;

            $new_avg = (($old_stock * $old_price) + ($qty_in * $price_new)) / max(1, $total_stock);

            $ok = $this->wpdb->update($this->items_table, ['base_price' => $new_avg], ['id' => $item_id]);
            if ($ok === false) {
                $this->wpdb->query('ROLLBACK');
                return new WP_Error('db_error', $this->wpdb->last_error);
            }

            $this->wpdb->query('COMMIT');
            return floatval($new_avg);
        }

        /**
         * 5. Adjust Virtual Lock
         *    Insert or update locks atomically with ON DUPLICATE KEY UPDATE.
         *    qty_change may be negative; qty_lock is floored at 0.
         */
        public function adjust_virtual_lock($item_id, $qty_change) {
            $item_id = intval($item_id);
            $qty_change = intval($qty_change);
            if (empty($this->locks_table)) return new WP_Error('no_table', 'locks table not defined');

            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->locks_table} (item_id, qty_lock) VALUES (%d, %d)
                 ON DUPLICATE KEY UPDATE qty_lock = GREATEST(0, qty_lock + %d)",
                $item_id, $qty_change, $qty_change
            );
            $ok = $this->wpdb->query($sql);
            if ($ok === false) return new WP_Error('db_error', $this->wpdb->last_error);
            return true;
        }

        /**
         * Helper: get item by id (safe)
         */
        public function get_item($item_id) {
            $item_id = intval($item_id);
            return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->items_table} WHERE id = %d", $item_id));
        }
    }
}

// Register global engine safely
if (!isset($GLOBALS['puri_engine_v6']) || !($GLOBALS['puri_engine_v6'] instanceof PURI_Engine_V6)) {
    $GLOBALS['puri_engine_v6'] = new PURI_Engine_V6();
}
$puri_engine = $GLOBALS['puri_engine_v6'];