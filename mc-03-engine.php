<?php
/**
 * =============================================================================
 * MC 03 - PURI Engine V6
 * =============================================================================
 * 
 * @package     Pusat Riyal
 * @module      MC-03
 * @version     6.0.2
 * @author      Denmas Totok (refactor by Copilot)
 * @updated     2026-01-03
 * 
 * =============================================================================
 * PURPOSE / TUJUAN
 * =============================================================================
 * 
 * Engine utama untuk operasi inventory dan akuntansi: 
 *   - post_journal()         : Catat jurnal double-entry
 *   - update_stock_atomic()  : Update stok dengan atomic operation
 *   - get_available_stock()  : Hitung stok tersedia (physical - locked)
 *   - calculate_moving_avg() : Hitung HPP dengan metode Moving Average
 *   - adjust_virtual_lock()  : Kelola virtual lock untuk paket bundling
 * 
 * =============================================================================
 * SECURITY & DATA INTEGRITY
 * =============================================================================
 * 
 *   ✓ Atomic DB operations (INSERT ...  ON DUPLICATE KEY UPDATE)
 *   ✓ Row-level locking (SELECT ... FOR UPDATE)
 *   ✓ Transaction support dengan proper nesting detection
 *   ✓ Deadlock retry mechanism (3x dengan exponential backoff)
 *   ✓ WP_Error return untuk error handling
 *   ✓ Comprehensive logging untuk debugging
 * 
 * =============================================================================
 * DEPENDENCIES
 * =============================================================================
 * 
 *   - mc-00-admin-hub. php :  puri_table_name()
 *   - mc-01-core.php      : Table constants (T_ITEMS, T_STOCK, etc)
 * 
 * =============================================================================
 * USAGE
 * =============================================================================
 * 
 *   // Cara 1: Via helper function (recommended)
 *   $result = puri_engine()->post_journal($ref_id, '4100', 0, $amount, $desc);
 * 
 *   // Cara 2: Via global variable (legacy)
 *   global $puri_engine;
 *   $result = $puri_engine->update_stock_atomic('laci_kasir', $item_id, -$qty);
 * 
 * =============================================================================
 * CHANGELOG
 * =============================================================================
 * 
 * [6.0.2] 2026-01-03
 *   - Fixed:  Race condition di calculate_moving_avg()
 *   - Added: Transaction nesting detection via is_in_transaction()
 *   - Added:  Deadlock retry mechanism (3x attempts)
 *   - Added:  Proper row locking untuk T_STOCK
 *   - Added: puri_engine() helper function (singleton pattern)
 *   - Added: Comprehensive error & debug logging
 *   - Improved: Input validation di semua method
 * 
 * [6.0.1] 2025-12-xx
 *   - Initial refactored version
 * 
 * =============================================================================
 */

defined('ABSPATH') || exit;

if (!defined('PURI_VERSION')) define('PURI_VERSION', '6.0.2');

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
        
        /**
         * WordPress database object
         * @var wpdb
         */
        protected $wpdb;
        
        /**
         * Table names
         */
        protected $items_table;
        protected $stock_table;
        protected $ledger_table;
        protected $journal_table;
        protected $locks_table;
        
        /**
         * Track apakah sedang dalam transaction yang dimulai oleh engine ini
         * @var bool
         */
        protected $in_transaction = false;
        
        /**
         * Maximum retry untuk deadlock
         * @var int
         */
        const MAX_DEADLOCK_RETRIES = 3;

        /**
         * Constructor
         */
        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->items_table   = puri_table_name('T_ITEMS');
            $this->stock_table   = puri_table_name('T_STOCK');
            $this->ledger_table  = puri_table_name('T_LEDGER');
            $this->journal_table = puri_table_name('T_JOURNAL');
            $this->locks_table   = puri_table_name('T_LOCKS');
        }

        /**
         * =========================================================================
         * TRANSACTION HELPERS
         * =========================================================================
         */
        
        /**
         * Cek apakah sudah ada transaction yang aktif
         * 
         * Menggunakan MySQL variable @@in_transaction (tersedia di MySQL 5.7. 2+)
         * 
         * @return bool True jika dalam transaction
         */
        public function is_in_transaction() {
            $result = $this->wpdb->get_var("SELECT @@in_transaction");
            return (bool) $result;
        }
        
        /**
         * Mulai transaction dengan safe nesting detection
         * 
         * Jika sudah ada transaction aktif, tidak akan memulai yang baru
         * untuk menghindari implicit commit. 
         * 
         * @return bool True jika transaction baru dimulai, False jika sudah ada
         */
        public function begin_transaction() {
            if ($this->is_in_transaction()) {
                // Sudah dalam transaction (dari caller), jangan mulai baru
                return false;
            }
            
            $this->wpdb->query('START TRANSACTION');
            $this->in_transaction = true;
            return true;
        }
        
        /**
         * Commit transaction jika dimulai oleh engine ini
         * 
         * @return void
         */
        public function commit_transaction() {
            if ($this->in_transaction) {
                $this->wpdb->query('COMMIT');
                $this->in_transaction = false;
            }
        }
        
        /**
         * Rollback transaction jika dimulai oleh engine ini
         * 
         * @return void
         */
        public function rollback_transaction() {
            if ($this->in_transaction) {
                $this->wpdb->query('ROLLBACK');
                $this->in_transaction = false;
            }
        }

        /**
         * =========================================================================
         * 1. POST JOURNAL (Double-entry Accounting)
         * =========================================================================
         * 
         * Mencatat entri jurnal akuntansi.  Setiap transaksi harus memiliki
         * pasangan debit dan credit yang seimbang.
         * 
         * @param string     $ref_id   Reference ID transaksi (e.g., 'SLS-20260103-001')
         * @param string     $acc_code Kode akun dari T_CHART (e.g., '1101', '4100')
         * @param float      $debit    Nominal debit (default: 0)
         * @param float      $credit   Nominal kredit (default: 0)
         * @param string     $desc     Deskripsi transaksi
         * @param array|null $snapshot Data snapshot untuk invoice/reporting (optional)
         * 
         * @return int|WP_Error Insert ID jika sukses, WP_Error jika gagal
         */
        public function post_journal($ref_id, $acc_code, $debit = 0, $credit = 0, $desc = '', $snapshot = null) {
            // Validasi required fields
            if (empty($ref_id) || empty($acc_code)) {
                return new WP_Error('invalid_args', 'ref_id dan acc_code wajib diisi');
            }
            
            // Sanitize dan prepare data
            $data = [
                'ref_id'        => sanitize_text_field($ref_id),
                'account_code'  => sanitize_text_field($acc_code),
                'debit'         => floatval($debit),
                'credit'        => floatval($credit),
                'description'   => sanitize_textarea_field($desc),
                'snapshot_json' => $snapshot ? wp_json_encode($snapshot) : null,
                'created_by'    => get_current_user_id(),
                'trx_date'      => current_time('mysql'),
            ];
            
            // Insert ke database
            $result = $this->wpdb->insert($this->journal_table, $data);
            
            if ($result === false) {
                $this->log_error('post_journal', $this->wpdb->last_error);
                return new WP_Error('db_error', $this->sanitize_error_message($this->wpdb->last_error));
            }
            
            return (int) $this->wpdb->insert_id;
        }

        /**
         * =========================================================================
         * 2.  ATOMIC STOCK UPDATE
         * =========================================================================
         * 
         * Update stok dengan atomic operation menggunakan INSERT ...  ON DUPLICATE KEY UPDATE. 
         * Ini menghindari race condition karena tidak ada gap antara SELECT dan UPDATE.
         * 
         * @param string $location_id Lokasi stok ('gudang_utama', 'laci_kasir')
         * @param int    $item_id     ID item dari T_ITEMS
         * @param float  $qty_change  Perubahan qty (positif = tambah, negatif = kurang)
         * 
         * @return bool|WP_Error True jika sukses, WP_Error jika gagal
         */
        public function update_stock_atomic($location_id, $item_id, $qty_change) {
            // Validasi input
            if (empty($location_id) || empty($item_id)) {
                return new WP_Error('invalid_args', 'location_id dan item_id wajib diisi');
            }
            
            $location_id = sanitize_text_field($location_id);
            $item_id     = intval($item_id);
            $qty_change  = floatval($qty_change);

            // Atomic upsert - tidak perlu SELECT dulu, langsung INSERT atau UPDATE
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->stock_table} (location_id, item_id, qty)
                 VALUES (%s, %d, GREATEST(0, %f))
                 ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty + %f)",
                $location_id, 
                $item_id, 
                $qty_change,  // Initial value jika record baru
                $qty_change   // Change value jika update
            );
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                $this->log_error('update_stock_atomic', $this->wpdb->last_error);
                return new WP_Error('db_error', $this->sanitize_error_message($this->wpdb->last_error));
            }
            
            return true;
        }

        /**
         * =========================================================================
         * 3. GET AVAILABLE STOCK
         * =========================================================================
         * 
         * Menghitung stok yang tersedia untuk dijual: 
         * Available = Physical Stock - Virtual Locks
         * 
         * Virtual locks digunakan untuk mengunci stok komponen paket bundling.
         * 
         * @param string $location_id Lokasi stok
         * @param int    $item_id     ID item
         * 
         * @return float Qty tersedia (minimum 0)
         */
        public function get_available_stock($location_id, $item_id) {
            $location_id = sanitize_text_field($location_id);
            $item_id     = intval($item_id);
            
            // Get physical stock
            $physical_qty = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT qty FROM {$this->stock_table} 
                 WHERE location_id = %s AND item_id = %d",
                $location_id, 
                $item_id
            ));
            $physical_qty = $physical_qty !== null ? floatval($physical_qty) : 0;

            // Get virtual locks (hanya berlaku untuk laci_kasir)
            $locked_qty = 0;
            if ($location_id === 'laci_kasir' && $this->locks_table) {
                $locked_qty = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT qty_lock FROM {$this->locks_table} WHERE item_id = %d",
                    $item_id
                ));
                $locked_qty = $locked_qty !== null ? floatval($locked_qty) : 0;
            }
            
            return max(0, $physical_qty - $locked_qty);
        }

        /**
         * =========================================================================
         * 4. CALCULATE MOVING AVERAGE (HPP) - RACE CONDITION SAFE
         * =========================================================================
         * 
         * Menghitung Harga Pokok Pembelian (HPP) dengan metode Moving Average. 
         * 
         * Formula: 
         *   New Avg = ((Old Stock × Old Price) + (Qty In × New Price)) / Total Stock
         * 
         * PENTING: 
         *   - Fungsi ini TIDAK memulai transaction sendiri
         *   - Caller HARUS wrap dalam transaction jika diperlukan konsistensi
         *   - Menggunakan row-level locking (FOR UPDATE) untuk mencegah race condition
         *   - Memiliki retry mechanism untuk menangani deadlock
         * 
         * @param int   $item_id    ID item dari T_ITEMS
         * @param float $qty_in     Qty yang masuk (harus positif)
         * @param float $price_new  Harga beli per unit (harus >= 0)
         * @param bool  $use_lock   Gunakan row locking FOR UPDATE (default: true)
         * 
         * @return float|WP_Error Harga average baru atau WP_Error jika gagal
         */
        public function calculate_moving_avg($item_id, $qty_in, $price_new, $use_lock = true) {
            // Sanitize dan validasi input
            $item_id   = intval($item_id);
            $qty_in    = floatval($qty_in);
            $price_new = floatval($price_new);
            
            if ($item_id <= 0) {
                return new WP_Error('invalid_args', 'item_id harus lebih dari 0');
            }
            if ($qty_in <= 0) {
                return new WP_Error('invalid_args', 'qty_in harus positif');
            }
            if ($price_new < 0) {
                return new WP_Error('invalid_args', 'price_new tidak boleh negatif');
            }

            // Retry mechanism untuk menangani deadlock
            $retries = 0;
            $last_error = null;
            
            while ($retries < self::MAX_DEADLOCK_RETRIES) {
                try {
                    $result = $this->do_calculate_moving_avg($item_id, $qty_in, $price_new, $use_lock);
                    return $result;
                    
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                    
                    // Cek apakah error adalah deadlock atau lock timeout
                    if (strpos($last_error, 'Deadlock') !== false || 
                        strpos($last_error, 'Lock wait timeout') !== false) {
                        $retries++;
                        $this->log_error('calculate_moving_avg', "Deadlock detected, retry #{$retries}");
                        
                        // Exponential backoff:  100ms, 200ms, 300ms
                        usleep(100000 * $retries);
                        continue;
                    }
                    
                    // Bukan deadlock, langsung break dan return error
                    break;
                }
            }
            
            $this->log_error('calculate_moving_avg', "Failed after {$retries} retries: {$last_error}");
            return new WP_Error('db_error', $this->sanitize_error_message($last_error ?: 'Gagal menghitung moving average'));
        }
        
        /**
         * Internal:  Eksekusi actual moving average calculation
         * 
         * Method ini dipisah untuk memudahkan retry mechanism. 
         * 
         * @param int   $item_id
         * @param float $qty_in
         * @param float $price_new
         * @param bool  $use_lock
         * 
         * @return float New average price
         * @throws Exception Jika terjadi error database
         */
        protected function do_calculate_moving_avg($item_id, $qty_in, $price_new, $use_lock) {
            // Lock query suffix untuk row-level locking
            $lock_suffix = $use_lock ? ' FOR UPDATE' : '';
            
            // ─────────────────────────────────────────────────────────────────
            // STEP 1: Lock dan baca data item (harga lama)
            // ─────────────────────────────────────────────────────────────────
            $item = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, base_price FROM {$this->items_table} 
                 WHERE id = %d {$lock_suffix}",
                $item_id
            ));
            
            if (! $item) {
                throw new Exception("Item dengan ID {$item_id} tidak ditemukan");
            }
            
            $old_price = floatval($item->base_price);
            
            // ─────────────────────────────────────────────────────────────────
            // STEP 2: Lock dan hitung total stok dari SEMUA lokasi
            // ─────────────────────────────────────────────────────────────────
            // Lock semua row yang terkait item ini untuk mencegah race condition
            $stock_rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT location_id, qty FROM {$this->stock_table} 
                 WHERE item_id = %d {$lock_suffix}",
                $item_id
            ));
            
            $total_stock_after = 0;
            foreach ($stock_rows as $row) {
                $total_stock_after += floatval($row->qty);
            }
            
            // Jika belum ada stock record, berarti qty_in adalah stok pertama
            if ($total_stock_after <= 0) {
                $total_stock_after = $qty_in;
            }
            
            // ─────────────────────────────────────────────────────────────────
            // STEP 3: Hitung stok SEBELUM penambahan
            // ─────────────────────────────────────────────────────────────────
            // Catatan: update_stock_atomic() dipanggil SEBELUM calculate_moving_avg()
            // Jadi total_stock_after sudah termasuk qty_in
            $old_stock = max(0, $total_stock_after - $qty_in);
            
            // ─────────────────────────────────────────────────────────────────
            // STEP 4: Hitung Moving Average
            // ─────────────────────────────────────────────────────────────────
            // Formula: ((old_stock × old_price) + (qty_in × price_new)) / total_stock
            $numerator   = ($old_stock * $old_price) + ($qty_in * $price_new);
            $denominator = $total_stock_after;
            
            // Hindari division by zero
            if ($denominator <= 0) {
                $new_avg = $price_new; // Fallback ke harga baru
            } else {
                $new_avg = $numerator / $denominator;
            }
            
            // Pastikan tidak negatif dan presisi 4 decimal
            $new_avg = round(max(0, $new_avg), 4);
            
            // ─────────────────────────────────────────────────────────────────
            // STEP 5: Update base_price di T_ITEMS
            // ─────────────────────────────────────────────────────────────────
            $update_result = $this->wpdb->update(
                $this->items_table,
                ['base_price' => $new_avg],
                ['id' => $item_id],
                ['%f'],
                ['%d']
            );
            
            if ($update_result === false) {
                throw new Exception($this->wpdb->last_error ?: 'Gagal update base_price');
            }
            
            // Log untuk debugging (hanya jika WP_DEBUG aktif)
            $this->log_debug('calculate_moving_avg', sprintf(
                'Item #%d:  old_stock=%.2f, old_price=%.4f, qty_in=%.2f, price_new=%.4f → new_avg=%.4f',
                $item_id, $old_stock, $old_price, $qty_in, $price_new, $new_avg
            ));
            
            return floatval($new_avg);
        }

        /**
         * =========================================================================
         * 5. ADJUST VIRTUAL LOCK
         * =========================================================================
         * 
         * Virtual lock digunakan untuk "mengunci" stok komponen ketika paket 
         * bundling dibuat, sehingga tidak bisa terjual sebagai eceran.
         * 
         * Contoh: 
         *   - Paket Haji berisi 10 lembar SAR 100
         *   - Saat buat paket:  adjust_virtual_lock(item_sar100, +10)
         *   - Saat jual paket: adjust_virtual_lock(item_sar100, -10)
         * 
         * @param int $item_id    ID item yang di-lock
         * @param int $qty_change Perubahan lock (positif = lock, negatif = unlock)
         * 
         * @return bool|WP_Error True jika sukses, WP_Error jika gagal
         */
        public function adjust_virtual_lock($item_id, $qty_change) {
            $item_id    = intval($item_id);
            $qty_change = intval($qty_change);
            
            if (empty($this->locks_table)) {
                return new WP_Error('no_table', 'Tabel locks tidak terdefinisi');
            }
            
            if ($item_id <= 0) {
                return new WP_Error('invalid_args', 'item_id harus lebih dari 0');
            }

            // Atomic upsert - tidak perlu SELECT dulu
            $sql = $this->wpdb->prepare(
                "INSERT INTO {$this->locks_table} (item_id, qty_lock) 
                 VALUES (%d, GREATEST(0, %d))
                 ON DUPLICATE KEY UPDATE qty_lock = GREATEST(0, qty_lock + %d)",
                $item_id, 
                $qty_change,
                $qty_change
            );
            
            $result = $this->wpdb->query($sql);
            
            if ($result === false) {
                $this->log_error('adjust_virtual_lock', $this->wpdb->last_error);
                return new WP_Error('db_error', $this->sanitize_error_message($this->wpdb->last_error));
            }
            
            return true;
        }

        /**
         * =========================================================================
         * 6. GET ITEM BY ID
         * =========================================================================
         * 
         * Mengambil data item dari T_ITEMS berdasarkan ID. 
         * 
         * @param int $item_id ID item
         * 
         * @return object|null Object item atau null jika tidak ditemukan
         */
        public function get_item($item_id) {
            $item_id = intval($item_id);
            
            if ($item_id <= 0) {
                return null;
            }
            
            return $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->items_table} WHERE id = %d",
                $item_id
            ));
        }
        
        /**
         * =========================================================================
         * 7. GET ITEM BY SKU
         * =========================================================================
         * 
         * Mengambil data item dari T_ITEMS berdasarkan SKU.
         * 
         * @param string $sku Kode SKU item
         * 
         * @return object|null Object item atau null jika tidak ditemukan
         */
        public function get_item_by_sku($sku) {
            $sku = sanitize_text_field($sku);
            
            if (empty($sku)) {
                return null;
            }
            
            return $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->items_table} WHERE sku = %s",
                $sku
            ));
        }

        /**
         * =========================================================================
         * LOGGING HELPERS
         * =========================================================================
         */
        
        /**
         * Log error message ke error_log
         * 
         * Hanya aktif jika WP_DEBUG = true
         * 
         * @param string $method  Nama method yang error
         * @param string $message Pesan error
         */
        protected function log_error($method, $message) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[PURI Engine ERROR] %s(): %s', $method, $message));
            }
        }
        
        /**
         * Log debug message ke error_log
         * 
         * Hanya aktif jika WP_DEBUG = true DAN WP_DEBUG_LOG = true
         * 
         * @param string $method  Nama method
         * @param string $message Pesan debug
         */
        protected function log_debug($method, $message) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf('[PURI Engine DEBUG] %s(): %s', $method, $message));
            }
        }
        
        /**
         * Sanitize error message untuk ditampilkan ke user
         * 
         * Menyembunyikan detail teknis jika bukan debug mode
         * 
         * @param string $error Raw error message
         * 
         * @return string Sanitized error message
         */
        protected function sanitize_error_message($error) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return $error;
            }
            
            // Di production, tampilkan pesan generic
            return 'Terjadi kesalahan database.  Silakan coba lagi atau hubungi administrator. ';
        }
    }
}

/**
 * =============================================================================
 * HELPER FUNCTION (Singleton Pattern)
 * =============================================================================
 * 
 * Cara yang direkomendasikan untuk mengakses engine: 
 * 
 *   $result = puri_engine()->post_journal(... );
 *   $stock  = puri_engine()->get_available_stock('laci_kasir', 5);
 * 
 * @return PURI_Engine_V6
 */
function puri_engine() {
    if (! isset($GLOBALS['puri_engine_v6']) || ! ($GLOBALS['puri_engine_v6'] instanceof PURI_Engine_V6)) {
        $GLOBALS['puri_engine_v6'] = new PURI_Engine_V6();
    }
    return $GLOBALS['puri_engine_v6'];
}

// Register global engine instance
if (!isset($GLOBALS['puri_engine_v6']) || !($GLOBALS['puri_engine_v6'] instanceof PURI_Engine_V6)) {
    $GLOBALS['puri_engine_v6'] = new PURI_Engine_V6();
}

// Backward compatibility untuk kode lama yang pakai $puri_engine langsung
$puri_engine = $GLOBALS['puri_engine_v6'];