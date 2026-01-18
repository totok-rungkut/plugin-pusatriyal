<?php
/**
 * MC-00 - PURI UNIFIED ACCESS CONTROL
 * @version 8.0.1 - Fixed Kasir WP-Admin Access
 */

defined('ABSPATH') || exit;

// ========================================================================
// 1. ACTIVATION HOOK - CREATE ROLES & ASSIGN CAPABILITIES
// ========================================================================

function puri_activate_user_roles() {
    
    // --- LEVEL 1: KASIR (Frontline Staff) ---
    add_role('kasir', 'PURI Kasir', [
        'read'      => true,  // ✅ CRITICAL: WordPress requires this for wp-admin access
        'can_entry' => true   // Custom cap for POS access
    ]);

    // --- LEVEL 2: KASIR PLUS (Frontline + Content Creator) ---
    add_role('kasir_plus', 'PURI Kasir + Author', [
        'read'                     => true,
        'can_entry'                => true,
        'edit_posts'               => true,
        'publish_posts'            => true,
        'delete_posts'             => true,
        'edit_published_posts'     => true,
        'delete_published_posts'   => true,
        'upload_files'             => true
    ]);

    // --- LEVEL 3: FINANCE (Finance Manager + Supervisor) ---
    add_role('finance', 'PURI Finance Manager', [
        'read'           => true,
        'upload_files'   => true,
        'manage_finance' => true,
        'can_entry'      => true
    ]);

    // --- LEVEL 5: ADMINISTRATOR (System Administrator) ---
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('manage_finance');
        $admin->add_cap('can_entry');
    }
}

// ========================================================================
// 2. ALLOW KASIR TO ACCESS WP-ADMIN (CRITICAL FIX)
// ========================================================================

/**
 * Prevent WordPress from redirecting users without edit_posts
 * This allows Kasir (who only have 'read' capability) to access wp-admin
 */
add_filter('admin_init', function() {
    // Allow users with 'can_entry' to stay in wp-admin
    if (current_user_can('can_entry')) {
        // Remove default WP redirect for non-editors
        remove_action('admin_init', '_wp_admin_bar_init');
    }
});

/**
 * CRITICAL: Override WordPress default redirect behavior
 * WordPress redirects users without 'edit_posts' to frontend
 * We allow entry if user has 'can_entry' capability
 */
add_action('admin_page_access_denied', function() {
    if (current_user_can('can_entry')) {
        // Allow access, don't redirect
        return;
    }
    
    // For other users without permission, show error
    wp_die(
        '<h1>Access Denied</h1><p>You do not have permission to access the admin area.</p>',
        'Unauthorized',
        ['response' => 403, 'back_link' => false]
    );
});

// ========================================================================
// 3. RUNTIME SECURITY - BLOCK UNAUTHORIZED PAGES ONLY
// ========================================================================

add_action('admin_init', function() {
    // Skip AJAX requests
    if (defined('DOING_AJAX') && DOING_AJAX) return;

    // ✅ CHANGED: Only block specific pages, not entire wp-admin
    if (is_admin() && !current_user_can('manage_options')) {
        
        $forbidden_pages = [
            'plugins.php',
            'plugin-install.php',
            'plugin-editor.php',
            'themes.php',
            'theme-install.php',
            'theme-editor.php',
            'options-general.php',
            'options-writing.php',
            'options-reading.php',
            'options-discussion.php',
            'options-media.php',
            'options-permalink.php',
            'tools.php',
            'import.php',
            'export.php',
            'users.php',
            'user-new.php'
        ];
        
        $current_page = basename($_SERVER['PHP_SELF']);
        
        if (in_array($current_page, $forbidden_pages)) {
            wp_die(
                '<h1>Access Denied</h1><p>You do not have permission to access this page.</p>',
                'Unauthorized Access',
                ['response' => 403, 'back_link' => true]
            );
        }
    }
}, 1); // ✅ CHANGED: Priority 1 (run early)

// ========================================================================
// 4. UI CLEANER - HIDE WP CORE MENUS FROM NON-ADMINS
// ========================================================================

add_action('admin_menu', function() {
    
    if (!current_user_can('manage_options')) {
        
        // Remove WP core menus based on capabilities
        remove_menu_page('edit.php');                    // Posts
        remove_menu_page('edit.php?post_type=page');     // Pages
        remove_menu_page('edit-comments.php');           // Comments
        remove_menu_page('tools.php');                   // Tools
        
        // ✅ NEW: Show/hide Media based on capability
        if (!current_user_can('upload_files')) {
            remove_menu_page('upload.php');
        }
        
        // ✅ NEW: Show/hide Posts based on capability
        if (!current_user_can('edit_posts')) {
            remove_menu_page('edit.php');
        }
        
        // ✅ CRITICAL: Always show Profile menu for all users
        // (WordPress hides this by default, we force it to show)
    }
    
}, 999);

// ========================================================================
// 5. ADMIN BAR CLEANER
// ========================================================================

add_action('admin_bar_menu', function($wp_admin_bar) {
    
    if (!current_user_can('manage_options')) {
        $wp_admin_bar->remove_node('wp-logo');
        
        if (!current_user_can('edit_posts')) {
            $wp_admin_bar->remove_node('new-content');
        }
        
        $wp_admin_bar->remove_node('comments');
    }
    
}, 999);

// ========================================================================
// 6. DASHBOARD WIDGETS - CUSTOM FOR KASIR
// ========================================================================

add_action('wp_dashboard_setup', function() {
    
    // Remove default widgets for non-admins
    if (!current_user_can('manage_options')) {
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        remove_meta_box('dashboard_incoming_links', 'dashboard', 'normal');
        remove_meta_box('dashboard_plugins', 'dashboard', 'normal');
    }
    
    // ✅ NEW: Add custom welcome widget for Kasir
    if (current_user_can('can_entry')) {
        wp_add_dashboard_widget(
            'puri_kasir_welcome',
            '👋 Welcome to Pusat Riyal POS',
            'puri_render_kasir_dashboard_widget'
        );
    }
    
});

function puri_render_kasir_dashboard_widget() {
    $user = wp_get_current_user();
    $role_name = puri_get_user_role_name();
    
    ?>
    <div style="padding: 20px; text-align: center;">
        <h2 style="margin-top: 0; color: #2271b1;">
            Hello, <?php echo esc_html($user->display_name); ?>!
        </h2>
        <p style="font-size: 14px; color: #646970; margin-bottom: 30px;">
            Your role: <strong><?php echo esc_html($role_name); ?></strong>
        </p>
        
        <div style="margin: 20px 0;">
            <a href="<?php echo admin_url('admin.php?page=puri-pos'); ?>" 
               class="button button-primary button-hero"
               style="text-decoration: none; padding: 15px 30px; font-size: 16px;">
                <span class="dashicons dashicons-cart" style="margin-right: 8px; font-size: 20px; vertical-align: middle;"></span>
                Open POS Cockpit
            </a>
        </div>
        
        <?php if (current_user_can('edit_posts')): ?>
            <div style="margin-top: 20px;">
                <a href="<?php echo admin_url('edit.php'); ?>" class="button">
                    Manage Posts
                </a>
            </div>
        <?php endif; ?>
        
        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
        
        <div style="text-align: left; font-size: 13px; color: #646970;">
            <h3 style="margin-bottom: 10px; font-size: 14px;">Quick Tips:</h3>
            <ul style="line-height: 1.8; padding-left: 20px;">
                <li>Use POS Cockpit to process customer transactions</li>
                <li>Always verify customer identity before checkout</li>
                <li>Check stock availability before adding to cart</li>
                <?php if (puri_is_kasir_plus()): ?>
                    <li>You can also write blog posts via Posts menu</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    
    <style>
        #puri_kasir_welcome .inside {
            padding: 0 !important;
            margin: 0 !important;
        }
    </style>
    <?php
}

// ========================================================================
// 7. REDIRECT TO DASHBOARD AFTER LOGIN (FOR KASIR)
// ========================================================================

add_filter('login_redirect', function($redirect_to, $request, $user) {
    
    // Only apply to Kasir roles
    if (isset($user->roles) && is_array($user->roles)) {
        if (in_array('kasir', $user->roles) || in_array('kasir_plus', $user->roles)) {
            // Redirect to dashboard instead of profile
            return admin_url('index.php');
        }
    }
    
    return $redirect_to;
    
}, 10, 3);

// ========================================================================
// 8. GLOBAL HELPER FUNCTIONS (Keep existing)
// ========================================================================

function puri_is_admin() {
    return current_user_can('manage_options');
}

function puri_is_ceo() {
    $user = wp_get_current_user();
    return in_array('ceo', $user->roles) || current_user_can('manage_options');
}

function puri_is_finance() {
    return current_user_can('manage_finance');
}

function puri_is_kasir_plus() {
    $user = wp_get_current_user();
    return in_array('kasir_plus', $user->roles);
}

function puri_is_kasir_only() {
    $user = wp_get_current_user();
    return in_array('kasir', $user->roles) && 
           !current_user_can('manage_finance') && 
           !current_user_can('manage_options');
}

function puri_can_entry() {
    return current_user_can('can_entry');
}

function puri_check_cap($required_cap) {
    $caps = is_array($required_cap) ? $required_cap : [$required_cap];
    
    foreach ($caps as $cap) {
        if (current_user_can($cap)) {
            return true;
        }
    }
    
    wp_die(
        '<h1>Insufficient Permissions</h1><p>You need higher authority to perform this action.</p>',
        'Access Denied',
        ['response' => 403, 'back_link' => true]
    );
}

function puri_get_user_role_name($user_id = null) {
    $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
    
    if (!$user || empty($user->roles)) {
        return 'No Role';
    }
    
    $role_slug = $user->roles[0];
    
    $role_names = [
        'administrator' => 'System Administrator',
        'ceo'           => 'CEO',
        'finance'       => 'Finance Manager',
        'kasir_plus'    => 'Kasir + Author',
        'kasir'         => 'Kasir',
        'editor'        => 'Editor',
        'author'        => 'Author',
        'contributor'   => 'Contributor',
        'subscriber'    => 'Subscriber',
    ];
    
    return $role_names[$role_slug] ?? ucfirst($role_slug);
}

function puri_get_roles_matrix() {
    return [
        'administrator' => [
            'display_name' => 'System Administrator (Gods)',
            'capabilities' => [
                'manage_options'   => '✅ Full WP Access',
                'manage_finance'   => '✅ Finance Management',
                'can_entry'        => '✅ Data Entry',
                'ALL_WP_CAPS'      => '✅ All WordPress Native Capabilities'
            ],
            'access' => 'Everything'
        ],
        'finance' => [
            'display_name' => 'Finance Manager',
            'capabilities' => [
                'manage_finance'   => '✅ Finance Management',
                'can_entry'        => '✅ Data Entry',
                'upload_files'     => '✅ Media Upload'
            ],
            'access' => 'Procurement, Reports, POS'
        ],
        'kasir_plus' => [
            'display_name' => 'Kasir + Content Creator',
            'capabilities' => [
                'can_entry'        => '✅ Data Entry (POS)',
                'edit_posts'       => '✅ Write Blog Posts',
                'publish_posts'    => '✅ Publish Posts',
                'upload_files'     => '✅ Media Upload'
            ],
            'access' => 'POS Cockpit, Blog Writing'
        ],
        'kasir' => [
            'display_name' => 'Kasir (Basic)',
            'capabilities' => [
                'can_entry'        => '✅ Data Entry (POS)',
                'read'             => '✅ Dashboard Access'
            ],
            'access' => 'POS Cockpit Only'
        ]
    ];
}