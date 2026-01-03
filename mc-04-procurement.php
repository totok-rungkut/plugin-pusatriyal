<?php
/**
 * MC 04 - Procurement Hub (Pembelian Brot)
 * Filename: mc-04-procurement.php
 * Version: 6.0.3
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Admin UI for recording procurement (kulakan) transactions.
 *  - Two transaction modes: By Nominal (user supplies Total Riyal) and By Quantity (user supplies Qty).
 *  - Client-side calculations: Qty <-> Total Riyal conversions, and Total Rupiah = Total Riyal * Kurs Beli.
 *  - Safe server-side submit: nonce, capability checks, vendor existence check, item validation.
 *  - Store snapshot in journal table (snapshot_json) and insert minimal journal row.
 *
 * Integration notes:
 *  - Requires MC_01 (tables/helpers) and MC_03 (engine) modules to be loaded prior to this module.
 *    Loader should ensure mc-00, mc-01, mc-03 are loaded before this module (puri-centre loader supports priority).
 *  - This module does not automatically update stock/ledger/HPP unless $puri_engine functions are present.
 *    If $puri_engine->update_stock_atomic or equivalent exists, the code will attempt to call it (guarded).
 *
 * Security & Safety:
 *  - Only users with 'manage_options' (or puri_check_cap if available) can access.
 *  - Nonces protect form submission.
 *  - Server-side validates vendor and that at least one valid row exists.
 *  - All DB writes sanitized and prepared via $wpdb methods.
 *
 * UX:
 *  - Vendor list displayed as a listbox on the left.
 *  - Detail Transaksi supports "By Nominal" and "By Quantity" tabs.
 *  - Add / Clone / Remove rows inline; calculations performed client-side; final values posted as structured arrays.
 *
 * Notes for maintainers:
 *  - If using AJAX endpoints, handlers are implemented below as minimal JSON endpoints.
 *  - For production, extend submit handler to update inventory/ledger/HPP as needed (inside DB transaction).
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------
   Admin menu registration
   --------------------------- */
/**
 * Register submenu under Pusat Riyal dashboard.
 * Assumes main plugin has top-level 'puri-dashboard' — if not, this will still register a submenu entry.
 */
add_action( 'admin_menu', function() {
    // Add submenu page; callback renders the procurement form
    add_submenu_page(
        'puri-dashboard',                 // parent slug
        'Procurement / Kulakan',         // page title
        'Procurement',                   // menu title
        'manage_options',                // capability
        'puri-procurement',              // menu slug
        'puri_render_procurement_page'       // callback
    );
} );

/* ---------------------------
   AJAX registration (minimal handlers included)
   --------------------------- */
add_action( 'wp_ajax_puri_get_procurement_data', 'puri_get_procurement_data_handler' );
add_action( 'wp_ajax_puri_submit_procurement_ajax', 'puri_submit_procurement_ajax_handler' );

/**
 * Simple AJAX: return master items and vendors (used if UI wants AJAX-backed dropdowns)
 */
function puri_get_procurement_data_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'unauthorized', 403 );
    }
    global $wpdb;
    if ( function_exists( 'puri_table_name' ) ) {
        $items_table = puri_table_name( 'T_PR_MASTER_ITEMS' ) ?: (puri_table_name( 'T_ITEMS' ) ?: $wpdb->prefix . 'puri_pr_master_items');
    } else {
        $items_table = $wpdb->prefix . 'puri_pr_master_items';
    }
    $items = $wpdb->get_results( "SELECT id, sku, name, denom_value, base_price, sell_rate FROM {$items_table} ORDER BY denom_value ASC, sku ASC" );
    $vendors = get_posts( [ 'post_type' => 'pr_vendor', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
    wp_send_json_success( [ 'items' => $items, 'vendors' => $vendors ] );
}

/**
 * Minimal AJAX submit wrapper that calls the same logic as non-AJAX submit.
 * Returns JSON with success or error message.
 */
function puri_submit_procurement_ajax_handler() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'unauthorized', 403 );
    }
    // We emulate the normal admin-post flow by reusing puri_handle_procure_submit logic.
    // For simplicity we accept POST and return JSON result. We call the main handler but capture redirect.
    $result = puri_handle_procure_submit( /* $return_response = */ true );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( $result );
}

/* ---------------------------
   Render Procurement Page
   --------------------------- */
/**
 * Render procurement admin page (HTML + JS)
 */
function puri_render_procurement_page() {
    // capability guard: prefer puri_check_cap if defined (core helper), else standard capability
    if ( function_exists( 'puri_check_cap' ) ) {
        puri_check_cap( 'manage_options' );
    } else {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
    }

    global $wpdb;

    // table name helpers / fallbacks
    if ( function_exists( 'puri_table_name' ) ) {
        $items_table   = puri_table_name( 'T_PR_MASTER_ITEMS' ) ?: (puri_table_name( 'T_ITEMS' ) ?: $wpdb->prefix . 'puri_pr_master_items');
        $journal_table = puri_table_name( 'T_JOURNAL' ) ?: ( $wpdb->prefix . 'puri_acct_journal' );
    } else {
        $items_table   = $wpdb->prefix . 'puri_pr_master_items';
        $journal_table = $wpdb->prefix . 'puri_acct_journal';
    }

    // fetch master items for dropdown (safe)
    $items = [];
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) !== null ) {
        $items = $wpdb->get_results( "SELECT id, sku, name, denom_value, base_price, sell_rate FROM {$items_table} ORDER BY denom_value ASC, sku ASC" );
    }

    // fetch vendors (CPT pr_vendor)
    $vendors = get_posts( [
        'post_type'      => 'pr_vendor',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    // Admin notices if master items empty (friendly guidance)
    if ( empty( $items ) ) {
        echo '<div class="notice notice-warning"><p><strong>Peringatan:</strong> Tabel master items SQL kosong atau tidak tersedia. Silakan sinkronisasi Master Items ke SQL dahulu.</p></div>';
    }

    // Render page HTML
    ?>
    <div class="wrap">
        <h1>🧾 Form Kulakan</h1>

        <style>
            /* minimal admin UI styles (kept inline for module self-containment) */
            .kulakan-wrap { display:flex; gap:24px; align-items:flex-start; }
            .kulakan-left { width:220px; }
            .kulakan-right { flex:1; }
            .kulakan-listbox { width:100%; height:300px; }
            .kulakan-panel { background:#fff;border:1px solid #d1d5db;padding:12px;border-radius:6px; }
            .kulakan-tabs { margin-bottom:8px; }
            .kulakan-tabs button { margin-right:6px; padding:6px 12px; border-radius:4px; border:1px solid #cbd5e1; background:#fff; cursor:pointer; }
            .kulakan-tabs button.active { background:#7c3aed;color:#fff;border-color:#6d28d9; }
            .kulakan-rows { border:1px solid #e5e7eb;padding:12px; min-height:160px; }
            .kulakan-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
            .kulakan-row select, .kulakan-row input { padding:6px;border:1px solid #cbd5e1;border-radius:4px; min-width:120px; }
            .kulakan-row .denom, .kulakan-row .qty, .kulakan-row .total-riyal, .kulakan-row .kurs, .kulakan-row .total-rupiah { width:120px; text-align:right; }
            .kulakan-row .total-rupiah { font-weight:800; color:#0f172a; min-width:160px; text-align:right; }
            .kulakan-row .actions { display:flex; flex-direction:column; gap:6px; }
            .kulakan-add { margin-top:8px; }
            .kulakan-footer { margin-top:12px; display:flex; align-items:center; gap:12px; }
            .kulakan-submit { background:#059669; color:#fff; padding:10px 18px; border-radius:6px; border:0; cursor:pointer; }
            .kulakan-smallbtn { background:#fff;border:1px solid #ef4444;color:#ef4444;padding:4px 6px;border-radius:4px;cursor:pointer }
            .kulakan-label { min-width:70px; font-weight:600; color:#374151; }
        </style>

        <div class="kulakan-wrap">

            <!-- Left: Vendor Listbox -->
            <div class="kulakan-left kulakan-panel">
                <h3>Vendor List</h3>
                <form id="puri_vendor_select_form">
                    <select id="puri_vendor_listbox" name="vendor_id" class="kulakan-listbox" size="12" required>
                        <option value="">-- Pilih Vendor --</option>
                        <?php foreach ( $vendors as $v ) : ?>
                            <option value="<?php echo esc_attr( $v->ID ); ?>"><?php echo esc_html( $v->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- Right: Detail Transaksi -->
            <div class="kulakan-right">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <h2 style="margin:0">Detail Transaksi</h2>
                    <div><?php echo esc_html( date_i18n( 'd F Y' ) ); ?></div>
                </div>

                <div class="kulakan-panel">
                    <div class="kulakan-tabs">
                        <button type="button" id="tab_nominal" class="active">By Nominal</button>
                        <button type="button" id="tab_qty">By Quantity</button>
                    </div>

                    <form method="post" id="puri_procure_form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'puri_procure_action', 'puri_procure_nonce' ); ?>
                        <input type="hidden" name="action" value="puri_procure_submit">
                        <input type="hidden" name="mode" id="puri_mode" value="nominal">

                        <div class="kulakan-rows" id="kulakan_rows">
                            <!-- JS will populate initial rows -->
                        </div>

                        <div class="kulakan-add">
                            <button type="button" id="btn_add_row" class="button">+ Tambah Item</button>
                        </div>

                        <div class="kulakan-footer">
                            <div style="display:flex;align-items:center;gap:8px">
                                <div class="kulakan-label">Total pembelian</div>
                                <div style="display:flex;align-items:center">
                                    <span style="margin-right:8px">Rp</span>
                                    <input type="text" id="puri_total_rupiah" name="total_rupiah" readonly value="0" style="font-weight:900;padding:8px;border:1px solid #cbd5e1;border-radius:6px;width:220px;text-align:right;background:#fff" />
                                </div>
                                <label style="display:flex;align-items:center;gap:6px">
                                    <input type="checkbox" id="puri_confirm_ok" name="confirm_ok" value="1"> Data sudah benar
                                </label>
                            </div>

                            <div style="margin-left:auto">
                                <button type="submit" id="puri_submit_btn" class="kulakan-submit" disabled>SUBMIT</button>
                            </div>
                        </div>
                    </form>

                </div>

                <!-- Purchase history (simplified: list last 10) -->
                <div style="margin-top:18px" class="kulakan-panel">
                    <h3>Riwayat Pembelian Bulan ini</h3>
                    <?php
                    $journal_table_safe = esc_sql( $journal_table );
					// Jangan campurkan esc_sql dengan prepare
					$hist = $wpdb->get_results( $wpdb->prepare( "
						SELECT ref_id, trx_date, description, snapshot_json, COALESCE(SUM(debit-credit),0) AS total_idr
						FROM " . $journal_table . "
						WHERE DATE_FORMAT(trx_date, '%%Y-%%m') = %s
						GROUP BY ref_id, trx_date, description, snapshot_json
						ORDER BY trx_date DESC
						LIMIT 10
					", date_i18n( 'Y-m' ) ) );
                    ?>
                    <table class="widefat striped">
                        <thead><tr><th>Tanggal</th><th>Kode</th><th>Deskripsi Snapshot</th><th style="text-align:right">Total Rupiah</th></tr></thead>
                        <tbody>
                            <?php if ( empty( $hist ) ) : ?>
                                <tr><td colspan="4" style="text-align:center;color:#6b7280">Belum ada pembelian bulan ini.</td></tr>
                            <?php else : foreach ( $hist as $h ) : ?>
                                <tr>
                                    <td><?php echo esc_html( date_i18n( 'd-M', strtotime( $h->trx_date ) ) ); ?></td>
                                    <td><?php echo esc_html( $h->ref_id ); ?></td>
                                    <td>
                                        <?php
                                        $snap = json_decode( $h->snapshot_json, true );
                                        if ( is_array( $snap ) && ! empty( $snap['items'] ) ) {
                                            $parts = [];
                                            foreach ( $snap['items'] as $si ) {
                                                $parts[] = esc_html( ($si['sku'] ?? $si['item_id'] ?? '') . ' × ' . number_format_i18n( $si['qty'] ) . ' @' . number_format_i18n( $si['denom_value'] ?? 0 ) );
                                            }
                                            echo esc_html( implode( ' ; ', $parts ) );
                                        } else {
                                            echo esc_html( wp_trim_words( $h->description, 12 ) );
                                        }
                                        ?>
                                    </td>
                                    <td style="text-align:right"><?php echo 'Rp ' . number_format_i18n( absint( $h->total_idr ) ); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <script>
        (function(){
            // items and vendors passed from PHP (safe JSON)
            const items = <?php echo wp_json_encode( $items ?: [] ); ?>;
            const rowsContainer = document.getElementById('kulakan_rows');
            const modeInput = document.getElementById('puri_mode');
            const totalRupiahInput = document.getElementById('puri_total_rupiah');
            const submitBtn = document.getElementById('puri_submit_btn');
            const confirmChk = document.getElementById('puri_confirm_ok');
            let mode = 'nominal'; // or 'qty'
            let rowCounter = 0;

            // helpers
            function formatNumber(n){
                if (n === null || n === undefined || isNaN(n) || n === '') return '';
                return new Intl.NumberFormat('id-ID').format( parseFloat(n) );
            }
            function parseNumber(v){ v = String(v||'').replace(/[^\d\.\-]/g,''); return v === '' ? 0 : parseFloat(v); }

            function makeItemSelect(name, selectedId){
                const sel = document.createElement('select');
                sel.name = name;
                sel.required = true;
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.text = '-- Pilih Item --';
                sel.appendChild(emptyOpt);
                items.forEach(it=>{
                    const o = document.createElement('option');
                    o.value = it.id;
                    o.text = it.sku + (it.name ? ' – ' + it.name : '');
                    o.dataset.denom = it.denom_value || 1;
                    o.dataset.sellrate = it.sell_rate || 0;
                    if (selectedId && selectedId == it.id) o.selected = true;
                    sel.appendChild(o);
                });
                return sel;
            }

            function buildRow(data = {}) {
                const idx = rowCounter++;
                const row = document.createElement('div');
                row.className = 'kulakan-row';
                row.dataset.rowIndex = idx;

                // Item select
                const sel = makeItemSelect(`items[${idx}][id]`, data.item_id || '');
                row.appendChild(sel);

                // denom display
                const denom = document.createElement('input'); denom.type='text'; denom.className='denom'; denom.readOnly = true;
                denom.value = data.denom_value ? formatNumber(data.denom_value) : '';
                row.appendChild(denom);

                // qty
                const qty = document.createElement('input'); qty.type='text'; qty.className='qty';
                qty.value = data.qty ? formatNumber(data.qty) : '';
                row.appendChild(qty);

                // kurs
                const kurs = document.createElement('input'); kurs.type='text'; kurs.className='kurs';
                kurs.value = data.kurs ? formatNumber(data.kurs) : '';
                row.appendChild(kurs);

                // total riyal
                const tr = document.createElement('input'); tr.type='text'; tr.className='total-riyal'; tr.readOnly = true;
                tr.value = (data.total_riyal || data.totalRiyal) ? formatNumber(data.total_riyal || data.totalRiyal) : '';
                row.appendChild(tr);

                // total rupiah display
                const rup = document.createElement('div'); rup.className='total-rupiah';
                rup.style.minWidth = '160px'; rup.textContent = data.total_rupiah ? formatNumber(data.total_rupiah) : '0';
                row.appendChild(rup);

                // hidden values for form submission (classed for reindexing)
                const hiddenQty = document.createElement('input'); hiddenQty.type='hidden'; hiddenQty.className='hidden-qty'; hiddenQty.name=`items[${idx}][qty]`;
                const hiddenDenom = document.createElement('input'); hiddenDenom.type='hidden'; hiddenDenom.className='hidden-denom'; hiddenDenom.name=`items[${idx}][denom_value]`;
                const hiddenKurs = document.createElement('input'); hiddenKurs.type='hidden'; hiddenKurs.className='hidden-kurs'; hiddenKurs.name=`items[${idx}][kurs]`;
                const hiddenTotalRiyal = document.createElement('input'); hiddenTotalRiyal.type='hidden'; hiddenTotalRiyal.className='hidden-total-riyal'; hiddenTotalRiyal.name=`items[${idx}][total_riyal]`;
                const hiddenTotalRupiah = document.createElement('input'); hiddenTotalRupiah.type='hidden'; hiddenTotalRupiah.className='hidden-total-rupiah'; hiddenTotalRupiah.name=`items[${idx}][total_rupiah]`;
                row.appendChild(hiddenQty); row.appendChild(hiddenDenom); row.appendChild(hiddenKurs); row.appendChild(hiddenTotalRiyal); row.appendChild(hiddenTotalRupiah);

                // actions
                const actions = document.createElement('div'); actions.className='actions';
                const btnRem = document.createElement('button'); btnRem.type='button'; btnRem.className='kulakan-smallbtn'; btnRem.textContent='-';
                const btnClone = document.createElement('button'); btnClone.type='button'; btnClone.className='button'; btnClone.textContent='+';
                actions.appendChild(btnRem); actions.appendChild(btnClone);
                row.appendChild(actions);

                // logic to refresh values
                function refreshRow(){
                    const selected = sel.selectedOptions[0];
                    const denomVal = selected && selected.dataset.denom ? parseNumber(selected.dataset.denom) : 1;
                    denom.value = denomVal ? formatNumber(denomVal) : '';

                    const kursVal = parseNumber(kurs.value);
                    if (mode === 'nominal') {
                        const totalRiyalVal = parseNumber(tr.dataset.inputVal || tr.value || 0);
                        const qtyVal = denomVal ? (totalRiyalVal / denomVal) : 0;
                        qty.value = formatNumber(qtyVal);
                        const totalRup = totalRiyalVal * kursVal;
                        rup.textContent = formatNumber(totalRup);
                        hiddenQty.value = qtyVal;
                        hiddenTotalRiyal.value = totalRiyalVal;
                        hiddenTotalRupiah.value = totalRup;
                    } else {
                        const qtyVal = parseNumber(qty.value);
                        const totalRiyalVal = qtyVal * denomVal;
                        tr.value = formatNumber(totalRiyalVal);
                        const totalRup = totalRiyalVal * kursVal;
                        rup.textContent = formatNumber(totalRup);
                        hiddenQty.value = qtyVal;
                        hiddenTotalRiyal.value = totalRiyalVal;
                        hiddenTotalRupiah.value = totalRup;
                    }
                    hiddenDenom.value = denomVal;
                    hiddenKurs.value = kursVal;
                    recalculateTotal();
                }

                // events
                sel.addEventListener('change', refreshRow);
                tr.addEventListener('input', function(){ tr.dataset.inputVal = tr.value; refreshRow(); });
                qty.addEventListener('input', refreshRow);
                kurs.addEventListener('input', refreshRow);

                btnRem.addEventListener('click', function(){ row.remove(); recalculateTotal(); });
                btnClone.addEventListener('click', function(){
                    const cloneData = {
                        item_id: sel.value,
                        denom_value: denom.value ? parseNumber(denom.value) : 1,
                        qty: qty.value ? parseNumber(qty.value) : 0,
                        kurs: kurs.value ? parseNumber(kurs.value) : 0,
                        total_riyal: tr.value ? parseNumber(tr.value) : 0,
                        total_rupiah: rup.textContent ? parseNumber(rup.textContent) : 0
                    };
                    const clone = buildRow(cloneData);
                    row.parentNode.insertBefore(clone, row.nextSibling);
                });

                // initial readonly depending on mode
                if ( mode === 'nominal' ) { tr.readOnly = false; qty.readOnly = true; }
                else { tr.readOnly = true; qty.readOnly = false; }

                // initial compute
                refreshRow();
                return row;
            }

            function recalculateTotal(){
                let sum = 0;
                document.querySelectorAll('.kulakan-row').forEach(r=>{
                    const hidden = r.querySelector('.hidden-total-rupiah');
                    if (hidden) sum += parseNumber(hidden.value || 0);
                });
                totalRupiahInput.value = formatNumber(sum);
            }

            function addInitialRow(){ rowsContainer.appendChild(buildRow()); }

            document.getElementById('btn_add_row').addEventListener('click', function(){ rowsContainer.appendChild(buildRow()); });

            function applyMode(newMode){
                mode = newMode;
                modeInput.value = newMode;
                document.querySelectorAll('.kulakan-row').forEach(r=>{
                    const tr = r.querySelector('.total-riyal');
                    const q = r.querySelector('.qty');
                    if (!tr || !q) return;
                    if (newMode === 'nominal') {
                        tr.readOnly = false; q.readOnly = true; tr.dataset.inputVal = tr.value;
                    } else {
                        tr.readOnly = true; q.readOnly = false;
                    }
                    // trigger recalc
                    q.dispatchEvent(new Event('input'));
                    tr.dispatchEvent(new Event('input'));
                });
            }

            document.getElementById('tab_nominal').addEventListener('click', function(){
                document.getElementById('tab_nominal').classList.add('active');
                document.getElementById('tab_qty').classList.remove('active');
                applyMode('nominal');
            });
            document.getElementById('tab_qty').addEventListener('click', function(){
                document.getElementById('tab_qty').classList.add('active');
                document.getElementById('tab_nominal').classList.remove('active');
                applyMode('qty');
            });

            confirmChk.addEventListener('change', function(){ submitBtn.disabled = !this.checked; });

            // initial state
            addInitialRow();
            applyMode(mode);

            // before submit, reindex names and prepare hidden vendor/total fields
            document.getElementById('puri_procure_form').addEventListener('submit', function(e){
                const vendorList = document.getElementById('puri_vendor_listbox');
                if (!vendorList.value) {
                    e.preventDefault();
                    alert('Pilih vendor terlebih dahulu dari list sebelah kiri.');
                    return false;
                }
                const rows = document.querySelectorAll('.kulakan-row');
                if (!rows.length) {
                    e.preventDefault();
                    alert('Tambahkan paling tidak 1 item.');
                    return false;
                }

                // reindex rows to stable items[0]..items[N]
                Array.from(rows).forEach((r, idx) => {
                    const sel = r.querySelector('select');
                    if (sel) sel.name = `items[${idx}][id]`;
                    const hQty = r.querySelector('.hidden-qty'); if (hQty) hQty.name = `items[${idx}][qty]`;
                    const hDenom = r.querySelector('.hidden-denom'); if (hDenom) hDenom.name = `items[${idx}][denom_value]`;
                    const hKurs = r.querySelector('.hidden-kurs'); if (hKurs) hKurs.name = `items[${idx}][kurs]`;
                    const hTotR = r.querySelector('.hidden-total-riyal'); if (hTotR) hTotR.name = `items[${idx}][total_riyal]`;
                    const hTotRp = r.querySelector('.hidden-total-rupiah'); if (hTotRp) hTotRp.name = `items[${idx}][total_rupiah]`;
                });

                // vendor hidden field
                let vfield = document.querySelector('input[name="vendor_id"]');
                if (!vfield) { vfield = document.createElement('input'); vfield.type='hidden'; vfield.name='vendor_id'; this.appendChild(vfield); }
                vfield.value = vendorList.value;

                // unformat total rupiah and attach raw hidden field
                const totalRaw = (totalRupiahInput.value || '').replace(/[^\d\.\-]/g,'') || '0';
                totalRupiahInput.value = totalRaw;
                let th = document.querySelector('input[name="total_rupiah_raw"]');
                if (!th) { th = document.createElement('input'); th.type='hidden'; th.name='total_rupiah_raw'; this.appendChild(th); }
                th.value = totalRaw;
            });

        })();
        </script>

    </div>
    <?php
}

/* ---------------------------
   Submit handler (admin-post)
   --------------------------- */
/**
 * puri_handle_procure_submit
 *
 * If $return_response === true then function returns array or WP_Error (used by AJAX wrapper).
 * Otherwise it performs redirects (normal admin-post flow).
 */
add_action( 'admin_post_puri_procure_submit', 'puri_handle_procure_submit' );
function puri_handle_procure_submit( $return_response = false ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        if ( $return_response ) return new WP_Error( 'unauthorized', 'Unauthorized' );
        wp_die( 'Unauthorized', 403 );
    }

    if ( empty( $_POST ) ) {
        if ( $return_response ) return new WP_Error( 'no_post', 'No data posted' );
        wp_redirect( add_query_arg( 'puri_procure_err', 'no_post', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }

    check_admin_referer( 'puri_procure_action', 'puri_procure_nonce' );

    global $wpdb;

    // Determine journal table
    if ( function_exists( 'puri_table_name' ) ) {
        $journal_table = puri_table_name( 'T_JOURNAL' ) ?: ( $wpdb->prefix . 'puri_acct_journal' );
    } else {
        $journal_table = $wpdb->prefix . 'puri_acct_journal';
    }

    // Basic server-side validation: vendor + confirm checkbox
    $vendor_id = intval( $_POST['vendor_id'] ?? 0 );
    if ( $vendor_id <= 0 ) {
        if ( $return_response ) return new WP_Error( 'no_vendor', 'Vendor not selected' );
        wp_redirect( add_query_arg( 'puri_procure_err', 'no_vendor', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }
    if ( get_post_status( $vendor_id ) === false ) {
        if ( $return_response ) return new WP_Error( 'vendor_not_found', 'Vendor not found' );
        wp_redirect( add_query_arg( 'puri_procure_err', 'vendor_not_found', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }
    if ( empty( $_POST['confirm_ok'] ) || $_POST['confirm_ok'] != '1' ) {
        if ( $return_response ) return new WP_Error( 'not_confirmed', 'You must confirm the data is correct' );
        wp_redirect( add_query_arg( 'puri_procure_err', 'not_confirmed', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }

    $mode = sanitize_text_field( $_POST['mode'] ?? 'nominal' );
    // total_rupiah may be posted as formatted or raw; prefer raw field if present
    $total_rupiah_raw = $_POST['total_rupiah_raw'] ?? $_POST['total_rupiah'] ?? '0';
    $total_rupiah = floatval( preg_replace( '/[^\d\.\-]/', '', $total_rupiah_raw ) );

    // parse items
    $items_post = $_POST['items'] ?? [];
    $items = [];
    if ( is_array( $items_post ) ) {
        foreach ( $items_post as $it ) {
            $id = intval( $it['id'] ?? 0 );
            $qty = floatval( $it['qty'] ?? 0 );
            $denom = floatval( $it['denom_value'] ?? 0 );
            $kurs = floatval( $it['kurs'] ?? 0 );
            $total_riyal = floatval( $it['total_riyal'] ?? 0 );
            $total_rupiah = floatval( $it['total_rupiah'] ?? 0 );

            // Basic validation: require positive id, qty, and rupiah
            if ( $id <= 0 || $qty <= 0 || $total_rupiah <= 0 ) {
                continue;
            }

            // Optional: attempt to fetch SKU from master items table for snapshot completeness
            $sku = null;
            if ( function_exists( 'puri_table_name' ) ) {
                $items_table = puri_table_name( 'T_PR_MASTER_ITEMS' ) ?: (puri_table_name( 'T_ITEMS' ) ?: $wpdb->prefix . 'puri_pr_master_items');
                $sku = $wpdb->get_var( $wpdb->prepare( "SELECT sku FROM {$items_table} WHERE id = %d LIMIT 1", $id ) );
            } else {
                // fallback: attempt to treat ID as post ID (not ideal, but safe)
                $post = get_post( $id );
                if ( $post ) $sku = $post->post_title;
            }

            $items[] = [
                'item_id'     => $id,
                'sku'         => $sku,
                'qty'         => $qty,
                'denom_value' => $denom,
                'kurs'        => $kurs,
                'total_riyal' => $total_riyal,
                'total_rupiah'=> $total_rupiah,
            ];
        }
    }

    if ( empty( $items ) ) {
        if ( $return_response ) return new WP_Error( 'no_items', 'No valid items provided' );
        wp_redirect( add_query_arg( 'puri_procure_err', 'no_items', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }

    // Create snapshot for audit
    $snapshot = [
        'vendor_id'    => $vendor_id,
        'mode'         => $mode,
        'total_rupiah' => $total_rupiah,
        'items'        => $items,
        'created_at'   => current_time( 'mysql' ),
        'created_by'   => get_current_user_id(),
    ];

    $ref_id = 'PRO-' . date_i18n( 'Ymd-His' );

    // Insert journal minimal row (wrapped in transaction if possible)
    $inserted = false;
    try {
        // Try transaction if supported by $wpdb (MySQLi)
        $wpdb->query( 'START TRANSACTION' );
        $res = $wpdb->insert(
            $journal_table,
            [
                'ref_id'       => $ref_id,
                'trx_date'     => current_time( 'mysql' ),
                'description'  => 'Pembelian stok (Kulakan)',
                'account_code' => '1401',
                'debit'        => $total_rupiah,
                'credit'       => 0,
                'snapshot_json'=> wp_json_encode( $snapshot ),
                'created_by'   => get_current_user_id(),
            ],
            [ '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d' ]
        );

        if ( $res === false ) {
            throw new Exception( 'DB insert failed: ' . $wpdb->last_error );
        }

        // OPTIONAL: update stock / ledger if $puri_engine exists
        if ( class_exists( 'Puri_Engine' ) || ( isset( $GLOBALS['puri_engine'] ) && is_object( $GLOBALS['puri_engine'] ) ) ) {
            $engine = isset( $GLOBALS['puri_engine'] ) ? $GLOBALS['puri_engine'] : ( class_exists( 'Puri_Engine' ) ? new Puri_Engine() : null );
            if ( $engine && method_exists( $engine, 'update_stock_atomic' ) ) {
                foreach ( $items as $it ) {
                    // call engine update (guarded) — adapt to engine signature as needed
                    try {
                        // example: $engine->update_stock_atomic($it['item_id'], $it['qty'], 'purchase', $ref_id);
                        if ( method_exists( $engine, 'update_stock_atomic' ) ) {
                            $engine->update_stock_atomic( $it['item_id'], $it['qty'], 'purchase', $ref_id );
                        }
                    } catch ( Throwable $e ) {
                        // log but do not fail entire transaction (depending on policy you may want to rollback)
                        error_log( 'PURI engine update_stock_atomic error: ' . $e->getMessage() );
                    }
                }
            }
        }

        $wpdb->query( 'COMMIT' );
        $inserted = true;
    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        error_log( 'PURI procure error: ' . $e->getMessage() );
        if ( $return_response ) return new WP_Error( 'db_error', $e->getMessage() );
        wp_redirect( add_query_arg( 'puri_procure_err', 'db_error', admin_url( 'admin.php?page=puri-procurement' ) ) ); exit;
    }

    // success
    if ( $inserted ) {
        if ( $return_response ) {
            return [ 'ok' => true, 'ref_id' => $ref_id ];
        }
        wp_redirect( add_query_arg( 'puri_procure_ok', urlencode( "Pembelian berhasil: {$ref_id}" ), admin_url( 'admin.php?page=puri-procurement' ) ) );
        exit;
    }

    // fallback error
    if ( $return_response ) return new WP_Error( 'unknown', 'Unknown error' );
    wp_redirect( add_query_arg( 'puri_procure_err', 'unknown', admin_url( 'admin.php?page=puri-procurement' ) ) );
    exit;
}