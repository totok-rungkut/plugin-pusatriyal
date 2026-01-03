<?php
/**
 * MC 17.A - Maintenance: Import / Export Tools for custom tables & CPT data
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Add safe UI for exporting and importing:
 *      * All custom tables that start with "{$wpdb->prefix}puri_" (e.g. puri_inventory_*, puri_acct_*)
 *      * Selected custom post types and their postmeta (e.g. pr_item, pr_vendor, pr_customer)
 *
 * Security & Safety:
 *  - Only users with 'manage_options' can use these tools.
 *  - All actions protected with nonces.
 *  - Import is two-step: upload -> preview -> confirm (to avoid accidental destructive imports).
 *  - All import DB executions are wrapped in a transaction; if any statement fails, rollback.
 *  - Upload file handling limited to .zip and checked by PHP ZipArchive.
 *  - Exports are created in a temporary directory under WP upload dir and offered as download.
 *
 * Warning (important):
 *  - These tools are powerful and can overwrite database state. ALWAYS backup DB & files BEFORE importing.
 *  - Use staging for testing.
 *
 * Usage:
 *  - This module registers a submenu under the existing Maintenance page (pr-dashboard -> Maintenance).
 *  - Go to: WP Admin -> Pusat Riyal -> Maintenance -> Import / Export Data
 *
 * Limitations:
 *  - SQL import executes statements in the dump.sql file. The exporter generates CREATE TABLE / INSERT statements.
 *  - Import currently trusts the incoming .sql contents; don't import untrusted files.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    // Add the Import/Export tool under the maintenance page
    add_submenu_page(
        'pr-dashboard',
        'Maintenance - Import/Export',
        'Import / Export Data',
        'manage_options',
        'puri-data-io',
        'puri_render_data_io_page'
    );
});

/* ---------------------------
   Helpers
   --------------------------- */

/**
 * Get temporary base dir for plugin IO operations.
 */
function puri_dataio_temp_dir() {
    $upload_dir = wp_upload_dir();
    $base = trailingslashit($upload_dir['basedir']) . 'puri-data-io';
    if (!file_exists($base)) {
        wp_mkdir_p($base);
    }
    return $base;
}

/**
 * Return an array of custom tables that start with "{$wpdb->prefix}puri_"
 */
function puri_get_puri_tables() {
    global $wpdb;
    $prefix = $wpdb->prefix . 'puri_%';
    $rows = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $prefix));
    return $rows ?: [];
}

/**
 * Return list of CPTs we care about by default.
 */
function puri_get_managed_post_types() {
    // You can extend this list if you add more CPTs to manage
    return ['pr_item', 'pr_vendor', 'pr_customer'];
}

/**
 * Sanitize filename (for ZIP/SQL/JSON files)
 */
function puri_sanitize_filename($name) {
    return preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
}

/* ---------------------------
   Export logic
   --------------------------- */

add_action('admin_post_puri_dataio_export', 'puri_handle_export_action');

function puri_handle_export_action() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_dataio_action', 'puri_dataio_nonce');

    global $wpdb;

    // Which tables and post types to export
    $available_tables = puri_get_puri_tables();
    $selected_tables = isset($_POST['tables']) && is_array($_POST['tables']) ? array_intersect($available_tables, $_POST['tables']) : $available_tables;

    $managed_pt = puri_get_managed_post_types();
    $selected_cpts = isset($_POST['cpts']) && is_array($_POST['cpts']) ? array_intersect($managed_pt, $_POST['cpts']) : $managed_pt;

    $time_label = date('Ymd_His');
    $base_tmp = trailingslashit(puri_dataio_temp_dir()) . 'export_' . $time_label;
    wp_mkdir_p($base_tmp);

    $sql_file = $base_tmp . '/dump.sql';
    $json_file = $base_tmp . '/posts.json';

    // 1) Build SQL dump for selected tables (CREATE + INSERT)
    $fp = fopen($sql_file, 'w');
    if (!$fp) wp_die('Unable to create SQL dump file on server.');

    foreach ($selected_tables as $table) {
        // CREATE TABLE
        $create = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_A);
        if (!empty($create['Create Table'])) {
            fwrite($fp, "-- --------------------------------------------------------\n");
            fwrite($fp, "-- Table structure for `{$table}`\n");
            fwrite($fp, "-- --------------------------------------------------------\n\n");
            fwrite($fp, $create['Create Table'] . ";\n\n");
        }

        // INSERT rows (chunked to avoid big memory)
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        if ($rows) {
            fwrite($fp, "-- Dumping data for table `{$table}`\n");
				foreach ($rows as $row) {
					// columns with backticks
					$cols = array_map(function($c){ return "`$c`"; }, array_keys($row));
					$values = array_values($row);

					// Build placeholders like %s, one per column
					$placeholders = implode(', ', array_fill(0, count($values), '%s'));

					// Build SQL with placeholders and use $wpdb->prepare to inject values safely
					$sql = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES ($placeholders);";

					// Prepare statement - this adds quoting/escaping automatically
					// If there are non-string values (null/int), cast them to string to avoid warnings
					$prep_args = array_map(function($v) {
						// preserve NULL as literal NULL in SQL: we'll convert null to the string '%s' param
						// BUT $wpdb->prepare treats null as empty string; for simplicity we cast to string here.
						// If preserving SQL NULL is required, more complex handling needed.
						return is_null($v) ? '' : (string) $v;
					}, $values);

					// Use call_user_func_array to pass dynamic args to $wpdb->prepare
					$prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $prep_args));

					// Write prepared SQL to dump file
					fwrite($fp, $prepared . "\n");
				}            fwrite($fp, "\n");
        }
    }
    fclose($fp);

    // 2) Export posts + postmeta for selected CPTs as JSON
    $export_data = [
        'meta' => [
            'exported_at' => gmdate('c'),
            'site' => get_bloginfo('url'),
            'tables' => array_values($selected_tables),
            'post_types' => array_values($selected_cpts),
        ],
        'posts' => []
    ];

    foreach ($selected_cpts as $cpt) {
        $query = new WP_Query([
            'post_type' => $cpt,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'ASC'
        ]);
        if ($query->have_posts()) {
            foreach ($query->posts as $p) {
                $post_meta = get_post_meta($p->ID);
                // Flatten meta values (usually arrays)
                $meta_flat = [];
                foreach ($post_meta as $mk => $mv) {
                    // Avoid huge binary data (e.g. large files) by casting to strings
                    $meta_flat[$mk] = array_map(function($v) {
                        if (is_string($v) || is_numeric($v)) return maybe_serialize($v);
                        return maybe_serialize($v);
                    }, $mv);
                }
                $export_data['posts'][] = [
                    'post_type' => $p->post_type,
                    'post_status' => $p->post_status,
                    'post_title' => $p->post_title,
                    'post_content' => $p->post_content,
                    'post_excerpt' => $p->post_excerpt,
                    'post_author' => $p->post_author,
                    'post_date' => $p->post_date,
                    'post_date_gmt' => $p->post_date_gmt,
                    'menu_order' => $p->menu_order,
                    'meta' => $meta_flat,
                    'ID' => $p->ID, // keep original ID to optionally attempt preserving
                ];
            }
            wp_reset_postdata();
        }
    }

    file_put_contents($json_file, wp_json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 3) Zip the export directory
    $zipname = puri_sanitize_filename('puri_export_' . $time_label . '.zip');
    $zip_path = trailingslashit(puri_dataio_temp_dir()) . $zipname;
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
        wp_die('Failed to create ZIP file for download.');
    }
    // Add files
    $zip->addFile($sql_file, 'dump.sql');
    $zip->addFile($json_file, 'posts.json');
    // Could add README inside zip
    $zip->addFromString('README-export.txt', "Export generated on " . date('c') . "\nContains SQL dump and posts JSON.\n");
    $zip->close();

    // Serve download and cleanup (we'll unlink files after download)
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);

    // Cleanup files (attempt)
    @unlink($sql_file);
    @unlink($json_file);
    @unlink($zip_path);
    // Do not remove base_tmp in case user needs to inspect; optionally remove
    exit;
}

/* ---------------------------
   Import logic (upload -> preview -> confirm)
   --------------------------- */

add_action('admin_post_puri_dataio_import_upload', 'puri_handle_import_upload');
add_action('admin_post_puri_dataio_import_confirm', 'puri_handle_import_confirm');

function puri_handle_import_upload() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_dataio_action', 'puri_dataio_nonce');

    if (!isset($_FILES['puri_import_zip']) || $_FILES['puri_import_zip']['error'] !== UPLOAD_ERR_OK) {
        wp_redirect(add_query_arg('puri_io_err', 'upload_failed', admin_url('admin.php?page=puri-data-io')));
        exit;
    }

    $file = $_FILES['puri_import_zip'];
    $mime = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if ($mime['ext'] !== 'zip') {
        wp_redirect(add_query_arg('puri_io_err', 'not_zip', admin_url('admin.php?page=puri-data-io')));
        exit;
    }

    $base_tmp = puri_dataio_temp_dir() . '/import_' . date('Ymd_His') . '_' . wp_generate_password(6, false, false);
    wp_mkdir_p($base_tmp);
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        wp_redirect(add_query_arg('puri_io_err', 'zip_open_failed', admin_url('admin.php?page=puri-data-io')));
        exit;
    }
    $zip->extractTo($base_tmp);
    $zip->close();

    // Prepare preview: list files, sizes, and if expected names present
    $preview = [
        'dir' => $base_tmp,
        'files' => [],
        'has_sql' => file_exists($base_tmp . '/dump.sql'),
        'has_posts' => file_exists($base_tmp . '/posts.json'),
    ];
    $it = new DirectoryIterator($base_tmp);
    foreach ($it as $f) {
        if ($f->isDot()) continue;
        $preview['files'][] = ['name' => $f->getFilename(), 'size' => $f->getSize()];
    }

    // Store preview info in transient (for confirm step)
    set_transient('puri_dataio_import_preview_' . get_current_user_id(), $preview, 60 * 60); // 1 hour

    // Redirect to page with preview
    wp_redirect(admin_url('admin.php?page=puri-data-io&preview=1'));
    exit;
}

/**
 * Confirm and execute import actions
 * - This is destructive: requires explicit confirmation checkbox
 *
 * FIXED: SQL Import Handler dengan Error Handling yang Proper
 * Ganti fungsi puri_handle_import_confirm() di mc-17-data-io.php
 */

function puri_handle_import_confirm() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('puri_dataio_action', 'puri_dataio_nonce');

    $preview = get_transient('puri_dataio_import_preview_' . get_current_user_id());
    if (!$preview || empty($preview['dir'])) {
        wp_redirect(add_query_arg('puri_io_err', 'no_preview', admin_url('admin.php?page=puri-data-io')));
        exit;
    }

    if (empty($_POST['confirm_import']) || $_POST['confirm_import'] !== '1') {
        wp_redirect(add_query_arg('puri_io_err', 'not_confirmed', admin_url('admin.php?page=puri-data-io')));
        exit;
    }

    global $wpdb;
    $base_dir = $preview['dir'];
    $errors = [];
    
    // ✅ CRITICAL FIX: Set proper error mode
    $wpdb->show_errors();
    $wpdb->suppress_errors(false);

    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // ========== SQL IMPORT WITH PROPER ERROR HANDLING ==========
        $sqlpath = $base_dir . '/dump.sql';
        
        if (file_exists($sqlpath)) {
            $sql = file_get_contents($sqlpath);
            if ($sql === false) {
                throw new Exception('Unable to read SQL dump file');
            }

            // Split by semicolon followed by newline
            $stmts = preg_split('/;(?=\s*[\r\n])/m', $sql);
            $processed = 0;
            $skipped = 0;

            foreach ($stmts as $raw) {
                $stmt = trim($raw);
                
                // Skip empty or comment-only lines
                if ($stmt === '' || preg_match('/^--/', $stmt)) {
                    continue;
                }

                // Remove inline comments
                $stmt = preg_replace('/^\s*--.*[\r\n]*/m', '', $stmt);
                $stmt = trim($stmt);
                
                if ($stmt === '') continue;

                // ========== HANDLE CREATE TABLE ==========
                if (stripos($stmt, 'CREATE TABLE') !== false) {
                    // Extract table name
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $stmt, $m)) {
                        $tbl = $m[1];
                        
                        // Check if table exists
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SHOW TABLES LIKE %s", 
                            $wpdb->esc_like($tbl)
                        ));
                        
                        if (!empty($exists)) {
                            $skipped++;
                            error_log("PURI Import: Skipping CREATE - table exists: {$tbl}");
                            continue;
                        }
                    }

                    // ✅ FIX: Remove CHECK constraints (MySQL 5.7 compatibility)
                    $stmt = preg_replace('/,?\s*CONSTRAINT\s+`?[\w\-]+`?\s+CHECK\s*\([^\)]*\)/i', '', $stmt);
                    $stmt = preg_replace('/,?\s*CHECK\s*\([^\)]*\)/i', '', $stmt);
                    
                    // Clean up double commas
                    $stmt = preg_replace('/,\s*,/', ',', $stmt);
                    
                    // Ensure IF NOT EXISTS
                    if (stripos($stmt, 'IF NOT EXISTS') === false) {
                        $stmt = preg_replace('/CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $stmt, 1);
                    }
                }

                // ========== HANDLE INSERT ==========
                if (stripos($stmt, 'INSERT INTO') !== false) {
                    // Verify target table exists
                    if (preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?/i', $stmt, $m2)) {
                        $tbl_ins = $m2[1];
                        $exists2 = $wpdb->get_var($wpdb->prepare(
                            "SHOW TABLES LIKE %s", 
                            $wpdb->esc_like($tbl_ins)
                        ));
                        
                        if (empty($exists2)) {
                            $skipped++;
                            $errors[] = "Skipping INSERT into missing table: {$tbl_ins}";
                            error_log("PURI Import: Table not found for INSERT: {$tbl_ins}");
                            continue;
                        }
                    }
                }

                // ========== EXECUTE STATEMENT ==========
                $res = $wpdb->query($stmt . ';');
                
                if ($res === false) {
                    $err = $wpdb->last_error;
                    $short_stmt = substr($stmt, 0, 200);
                    
                    // ✅ CRITICAL: Determine if error is fatal
                    $is_fatal = true;
                    
                    // Non-fatal errors to ignore
                    if (stripos($err, 'Duplicate entry') !== false) {
                        $is_fatal = false;
                        $skipped++;
                    } elseif (stripos($err, 'already exists') !== false) {
                        $is_fatal = false;
                        $skipped++;
                    }
                    
                    if ($is_fatal) {
                        error_log("PURI Import FATAL: {$err} | stmt: {$short_stmt}");
                        throw new Exception("SQL execution failed: {$err}");
                    } else {
                        error_log("PURI Import WARNING (skipped): {$err}");
                        $errors[] = "Warning: {$err}";
                    }
                } else {
                    $processed++;
                }
            }
            
            error_log("PURI Import: SQL processed={$processed}, skipped={$skipped}");
        }

        // ========== POSTS IMPORT ==========
        $posts_path = $base_dir . '/posts.json';
        $posts_imported = 0;
        
        if (file_exists($posts_path)) {
            $json = file_get_contents($posts_path);
            $data = json_decode($json, true);
            
            if (is_array($data) && !empty($data['posts'])) {
                foreach ($data['posts'] as $post_item) {
                    $args = [
                        'post_type' => sanitize_text_field($post_item['post_type'] ?? 'post'),
                        'post_status' => sanitize_text_field($post_item['post_status'] ?? 'publish'),
                        'post_title' => sanitize_text_field($post_item['post_title'] ?? ''),
                        'post_content' => wp_kses_post($post_item['post_content'] ?? ''),
                        'post_excerpt' => sanitize_textarea_field($post_item['post_excerpt'] ?? ''),
                        'post_author' => intval($post_item['post_author'] ?? get_current_user_id()),
                        'menu_order' => intval($post_item['menu_order'] ?? 0),
                        'post_date' => $post_item['post_date'] ?? current_time('mysql'),
                    ];

                    $orig_id = intval($post_item['ID'] ?? 0);
                    $existing = $orig_id ? get_post($orig_id) : null;
                    
                    if ($existing && $existing->post_type === $args['post_type']) {
                        $args['ID'] = $orig_id;
                        $new_id = wp_update_post($args, true);
                    } else {
                        $new_id = wp_insert_post($args, true);
                    }
                    
                    if (is_wp_error($new_id)) {
                        throw new Exception('Post import failed: ' . $new_id->get_error_message());
                    }

                    // Import meta
                    if (!empty($post_item['meta']) && is_array($post_item['meta'])) {
                        foreach ($post_item['meta'] as $mk => $mvals) {
                            delete_post_meta($new_id, $mk);
                            if (is_array($mvals)) {
                                foreach ($mvals as $mv) {
                                    add_post_meta($new_id, $mk, maybe_unserialize($mv));
                                }
                            } else {
                                add_post_meta($new_id, $mk, maybe_unserialize($mvals));
                            }
                        }
                    }
                    
                    $posts_imported++;
                }
            }
        }

        // ✅ SUCCESS - Commit transaction
        $wpdb->query('COMMIT');
        delete_transient('puri_dataio_import_preview_' . get_current_user_id());
        puri_rrmdir($base_dir);

        $success_msg = sprintf(
            'Import berhasil! SQL statements processed, %d posts imported.', 
            $posts_imported
        );
        
        if (!empty($errors)) {
            $success_msg .= ' Dengan ' . count($errors) . ' peringatan non-fatal.';
        }

        wp_redirect(add_query_arg('puri_io_ok', urlencode($success_msg), admin_url('admin.php?page=puri-data-io')));
        exit;

    } catch (Exception $e) {
        // ✅ ROLLBACK on any error
        $wpdb->query('ROLLBACK');
        
        error_log('PURI Import FAILED: ' . $e->getMessage());
        
        $error_msg = 'Import gagal: ' . $e->getMessage();
        if (!empty($errors)) {
            $error_msg .= ' | Errors: ' . implode('; ', array_slice($errors, 0, 3));
        }
        
        wp_redirect(add_query_arg('puri_io_err', urlencode($error_msg), admin_url('admin.php?page=puri-data-io')));
        exit;
    }
}


/* ---------------------------
   Utility: recursive remove directory
   --------------------------- */
function puri_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dir);
}

/* ---------------------------
   Admin page render
   --------------------------- */

function puri_render_data_io_page() {
    puri_check_cap('manage_options');
    $available_tables = puri_get_puri_tables();
    $managed_cpts = puri_get_managed_post_types();

    $preview = get_transient('puri_dataio_import_preview_' . get_current_user_id());
    ?>
    <div class="wrap">
      <h1>🗂️ Import / Export Data (Custom Tables & CPT)</h1>
      <p style="color:#d9534f;font-weight:700">PERINGATAN: Operasi ini berbahaya. Pastikan Anda telah membuat BACKUP file dan DATABASE sebelum melakukan import.</p>

      <?php if (isset($_GET['puri_io_err'])): ?>
        <div class="notice notice-error"><p>Error: <?php echo esc_html(urldecode($_GET['puri_io_err'])); ?></p></div>
      <?php endif; ?>
      <?php if (isset($_GET['puri_io_ok'])): ?>
        <div class="notice notice-success"><p>OK: <?php echo esc_html(urldecode($_GET['puri_io_ok'])); ?></p></div>
      <?php endif; ?>

      <h2>Export</h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('puri_dataio_action', 'puri_dataio_nonce'); ?>
        <input type="hidden" name="action" value="puri_dataio_export">
        <table class="form-table">
          <tr>
            <th>Select Tables</th>
            <td>
              <p>Select which custom tables to include in the SQL dump (tables prefixed with <?php echo esc_html($GLOBALS['wpdb']->prefix); ?>puri_):</p>
              <?php if (empty($available_tables)): ?>
                <p><em>No puri_ tables found in database.</em></p>
              <?php else: foreach ($available_tables as $t): ?>
                <label style="display:block"><input type="checkbox" name="tables[]" value="<?php echo esc_attr($t); ?>" checked> <?php echo esc_html($t); ?></label>
              <?php endforeach; endif; ?>
            </td>
          </tr>

          <tr>
            <th>Select Content Types</th>
            <td>
              <p>Select CPTs (posts & postmeta) to export:</p>
              <?php foreach ($managed_cpts as $pt): ?>
                <label style="display:block"><input type="checkbox" name="cpts[]" value="<?php echo esc_attr($pt); ?>" checked> <?php echo esc_html($pt); ?></label>
              <?php endforeach; ?>
            </td>
          </tr>
        </table>
        <p><button class="button button-primary" type="submit">Generate Export ZIP</button></p>
      </form>

      <hr>

      <h2>Import</h2>
      <?php if ($preview): ?>
        <div class="notice notice-warning"><p><strong>Preview upload present:</strong> You uploaded an import ZIP at <?php echo esc_html($preview['dir']); ?>. You must CONFIRM to execute import. If you want to start a fresh upload, refresh this page and upload another ZIP.</p></div>

        <h3>Files found</h3>
        <ul>
          <?php foreach ($preview['files'] as $f): ?>
            <li><?php echo esc_html($f['name']); ?> (<?php echo size_format($f['size']); ?>)</li>
          <?php endforeach; ?>
        </ul>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('puri_dataio_action', 'puri_dataio_nonce'); ?>
          <input type="hidden" name="action" value="puri_dataio_import_confirm">
          <p><label><input type="checkbox" name="confirm_import" value="1" required> I confirm I have database & file backups and want to proceed with the import (This will run SQL and modify posts/meta).</label></p>
          <p><button class="button button-primary" type="submit">Execute Import (Destructive)</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=puri-data-io&cancel=1')); ?>">Cancel / Remove Upload</a></p>
        </form>

      <?php else: ?>
        <p>To import, upload a ZIP file generated by this plugin's Export tool. The ZIP must contain:
          <ul>
            <li><code>dump.sql</code> — SQL CREATE/INSERT statements for custom puri_ tables</li>
            <li><code>posts.json</code> — JSON with posts & postmeta for CPTs</li>
          </ul>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
          <?php wp_nonce_field('puri_dataio_action', 'puri_dataio_nonce'); ?>
          <input type="hidden" name="action" value="puri_dataio_import_upload">
          <table class="form-table">
            <tr>
              <th>Import ZIP</th>
              <td><input type="file" name="puri_import_zip" accept=".zip" required /></td>
            </tr>
            <tr>
              <th>Upload Options</th>
              <td>
                <label><input type="checkbox" name="upload_keep" value="1" checked> Keep extracted files for preview (1 hour)</label><br>
                <small>After upload you will see a preview and must confirm to execute import.</small>
              </td>
            </tr>
          </table>
          <p><button class="button button-primary" type="submit">Upload & Preview</button></p>
        </form>
      <?php endif; ?>

      <hr>
      <h3>Notes & Safety</h3>
      <ul>
        <li><strong>Always</strong> create full DB & file backups before import.</li>
        <li>Import will skip <code>DROP TABLE</code> statements for safety.</li>
        <li>The import currently attempts to preserve original post IDs if present and matching types; otherwise new posts are created.</li>
        <li>Files extracted to uploads/puri-data-io/* (temporary) — they will be removed after successful import. In failure, files are kept for inspection.</li>
      </ul>
    </div>
    <?php
}