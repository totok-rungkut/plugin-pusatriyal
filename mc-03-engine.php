<?php
/**
 * MC 03 - PURI Engine V7 (MOZART)
 * Orchestrator Utama
 * ver 7.3.15 (Updated with JSON Storage)
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/mc-03a-ledger.php';
require_once __DIR__ . '/mc-03b-stocker.php';
require_once __DIR__ . '/mc-03c-journalist.php';

if (!class_exists('PURI_Engine_Mozart')) {
    class PURI_Engine_Mozart {
        protected $ledger;
        protected $stocker;
        protected $journalist;

        public function __construct() {
            $this->ledger     = new PURI_Inventory_Ledger_V7();
            $this->stocker    = new PURI_Stock_Controller_V7();
            $this->journalist = new PURI_Accounting_Journal_V7();
        }

        public function execute($action_type, $params) {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                // 1. Simpan nampan mentah ke gudang JSON (Look-up Table)
                $this->store_json($action_type, $params);

                // 2. Instruksikan asisten melakukan tugas masing-masing
                $this->stocker->update_balance($action_type, $params);
                $this->ledger->record_mutation($params);
                $this->journalist->post_to_gl($action_type, $params);

                $wpdb->query('COMMIT');
                return true;
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('execution_failed', $e->getMessage());
            }
        }

        /**
         * Private Routine: Menyimpan Snapshot JSON
         * Dipindahkan ke dalam class agar bisa dipanggil via $this->
         */
        private function store_json($action_type, $params) {
            global $wpdb;
            if (empty($params['snapshot_json'])) return;

            // Menggunakan REPLACE agar idempotent (mencegah duplikasi ref_id)
            $wpdb->replace(puri_table_name('T_JSON'), [
                'ref_id'        => $params['source_ref'],
                'source_module' => $action_type,
                'snapshot_json' => $params['snapshot_json'],
                'created_at'    => $params['created_at'] ?? current_time('mysql')
            ]);
        }

        public function execute_reversal($params) {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                $this->journalist->post_reversal($params);
                $wpdb->query('COMMIT');
                return true;
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('reversal_failed', $e->getMessage());
            }
        }
    }
}

/**
 * Global Accessor
 */
function puri_mozart() {
    static $instance = null;
    if (null === $instance) $instance = new PURI_Engine_Mozart();
    return $instance;
}