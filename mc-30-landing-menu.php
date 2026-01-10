<?php
/**
 * =============================================================================
 * MC 30 - Landing Menu Pages (Refactored Version)
 * =============================================================================
 * * Modul ini telah di-refactor dengan standar UI Modern (Waveapps Inspired).
 * Menggunakan Lucide Icons (Gratis) dan sistem Badge Otomatis.
 */

defined('ABSPATH') || exit;

/**
 * RENDERER UTAMA: Membangun kartu navigasi dengan styling modern
 */
function puri_render_landing_cards($title, $description, $cards) {
    // Load Lucide Icons & Inter Font
    echo '<script src="https://unpkg.com/lucide@latest"></script>';
    echo '<style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap");

        .puri-container {
            font-family: "Inter", sans-serif;
            background-color: #f8fafc;
            padding: 40px 20px;
            min-height: 100vh;
            color: #1e293b;
        }

        .puri-header-section { margin-bottom: 45px; }
        .puri-header-section h1 { 
            font-weight: 800; font-size: 2.5rem; letter-spacing: -0.03em; 
            color: #0f172a; margin: 0; 
        }
        .puri-header-section p { 
            font-weight: 300; font-size: 1.15rem; color: #64748b; 
            margin-top: 10px; max-width: 800px;
        }

        .puri-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 28px;
        }

        .puri-nav-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 32px;
            text-decoration: none;
            display: flex; flex-direction: column;
            position: relative;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .puri-nav-card:hover {
            transform: translateY(-10px);
            border-color: #2563eb;
            box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.1);
        }

        /* Badge Notification (Analogous Indigo) */
        .puri-badge {
            position: absolute; top: 25px; right: 25px;
            background: #e0e7ff; color: #4338ca;
            font-size: 10px; font-weight: 700; padding: 5px 12px;
            border-radius: 30px; text-transform: uppercase;
            border: 1px solid #c7d2fe;
        }

        /* Icon Wrapper with Soft Gradient */
        .puri-icon-wrapper {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #2563eb;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 24px;
            transition: 0.3s;
        }
        .puri-nav-card:hover .puri-icon-wrapper {
            background: #2563eb; color: #ffffff;
            transform: scale(1.1);
        }

        .puri-card-title { font-weight: 700; font-size: 1.3rem; color: #0f172a; margin-bottom: 14px; }
        .puri-card-desc { 
            font-weight: 400; font-size: 0.95rem; color: #64748b; 
            line-height: 1.8; margin-bottom: 25px;
        }

        .puri-card-footer {
            margin-top: auto; padding-top: 15px; border-top: 1px solid #f1f5f9;
            font-size: 13px; font-weight: 600; color: #2563eb;
            display: flex; align-items: center; gap: 8px;
        }
    </style>';

    echo '<div class="puri-container">';
    echo '<div class="puri-header-section"><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div>';
    echo '<div class="puri-card-grid">';

    foreach ($cards as $c) {
        $badge = isset($c['badge']) ? $c['badge'] : '';
        echo '<a href="' . esc_url(admin_url($c['link'])) . '" class="puri-nav-card">';
        
        if ($badge) echo '<div class="puri-badge">' . esc_html($badge) . '</div>';

        echo '<div class="puri-icon-wrapper"><i data-lucide="' . esc_attr($c['icon']) . '"></i></div>';
        echo '<div class="puri-card-title">' . esc_html($c['title']) . '</div>';
        echo '<div class="puri-card-desc">' . esc_html($c['desc']) . '</div>';
        echo '<div class="puri-card-footer">Buka Modul <i data-lucide="arrow-right" style="width:14px;"></i></div>';
        echo '</a>';
    }

    echo '</div></div>';
    echo '<script>lucide.createIcons();</script>';
}

/**
 * HELPER: Mengambil data real-time untuk badge
 */
function puri_get_menu_counts() {
    global $wpdb;
    return [
        'bills'     => $wpdb->get_var("SELECT COUNT(*) FROM " . puri_table_name('T_EXPENSES') . " WHERE status = 'unpaid'"),
        'journal'   => $wpdb->get_var("SELECT COUNT(*) FROM " . puri_table_name('T_JOURNAL') . " WHERE DATE(created_at) = CURDATE()"),
        'low_stock' => $wpdb->get_var("SELECT COUNT(*) FROM " . puri_table_name('T_STOCK') . " WHERE qty <= 5")
    ];
}

// === DASHBOARD ===
function puri_landing_dashboard() {
    puri_render_landing_cards('🏠 Dasbor Utama', 'Navigasi utama sistem dan monitoring aktivitas.', [
        ['title'=>'Monitoring', 'desc'=>'Pantau seluruh aktivitas operasional dan performa sistem secara real-time untuk memastikan kelancaran alur kerja. Menu ini menyajikan statistik penggunaan dan log aktivitas harian yang krusial.', 'link'=>'admin.php?page=puri-monitoring', 'icon'=>'monitor'],
        ['title'=>'Profil & Bank', 'desc'=>'Kelola identitas resmi perusahaan beserta informasi rekening bank yang digunakan untuk seluruh transaksi sistem. Informasi di sini akan menjadi referensi utama pada header dokumen resmi.', 'link'=>'admin.php?page=puri-profile', 'icon'=>'building-2'],
    ]);
}

// === PEMBELIAN ===
function puri_landing_purchase() {
    $stats = puri_get_menu_counts();
    puri_render_landing_cards('📦 Pembelian & Kulakan', 'Kelola tagihan, pemasok, dan konsinyasi.', [
        ['title'=>'Purchasing', 'desc'=>'Kelola proses pengadaan barang (procurement) mulai dari pemesanan kepada supplier hingga penerimaan stok. Modul ini memastikan setiap barang masuk tercatat harga belinya dengan akurat.', 'link'=>'admin.php?page=puri-procurement', 'icon'=>'shopping-cart'],
        ['title'=>'Tagihan', 'desc'=>'Catat seluruh biaya operasional, tagihan vendor, dan pengeluaran rutin perusahaan secara sistematis. Membantu Anda melacak jatuh tempo pembayaran agar tidak terjadi keterlambatan.', 'link'=>'admin.php?page=puri-expenses', 'icon'=>'file-text', 'badge' => ($stats['bills'] > 0) ? $stats['bills'] . ' Unpaid' : ''],
        ['title'=>'Data Pemasok / Vendor', 'desc'=>'Database lengkap mengenai vendor dan supplier yang menyediakan stok serta kebutuhan operasional. Simpan informasi kontak dan histori kerjasama untuk mempermudah koordinasi masa depan.', 'link'=>'edit.php?post_type=pr_vendor', 'icon'=>'truck'],
        ['title'=>'Konsinyasi', 'desc'=>'Kelola kerjasama barang titipan dari pihak ketiga dengan sistem bagi hasil yang telah disepakati. Memudahkan pemisahan stok milik sendiri dengan stok titipan serta perhitungan kompensasi otomatis.', 'link'=>'admin.php?page=puri-consignment', 'icon'=>'handshake'],
    ]);
}

// === PENJUALAN ===
function puri_landing_sales() {
    $stats = puri_get_menu_counts();
    puri_render_landing_cards('🛒 Penjualan & Pembayaran', 'Kelola transaksi penjualan, pelanggan, dan produk.', [
        ['title'=>'The Cockpit !! (P.O.S)', 'desc'=>'Akses panel kasir utama yang dirancang untuk memproses transaksi penjualan ritel dengan cepat dan akurat. Fitur ini mencatat mutasi kas harian, manajemen shift operator, hingga pencetakan struk.', 'link'=>'admin.php?page=puri-cockpit-pos', 'icon'=>'circle-dollar-sign'],
        ['title'=>'Daftar Pelanggan', 'desc'=>'Kelola basis data pelanggan secara terorganisir mulai dari informasi kontak hingga riwayat interaksi bisnis. Data ini digunakan untuk personalisasi faktur dan mempermudah pencarian saat transaksi.', 'link'=>'edit.php?post_type=pr_customer', 'icon'=>'users'],
        ['title'=>'Master Item SKU', 'desc'=>'Daftar lengkap item barang dan jasa beserta informasi harga serta kategori pajaknya. Anda dapat mengatur spesifikasi produk, satuan unit, dan menetapkan harga jual yang kompetitif.', 'link'=>'edit.php?post_type=pr_item', 'icon'=>'package', 'badge' => ($stats['low_stock'] > 0) ? $stats['low_stock'] . ' Alert' : ''],
    ]);
}

// === AKUNTANSI ===
function puri_landing_accounting() {
    $stats = puri_get_menu_counts();
	puri_render_landing_cards('📊 Akuntansi & Jurnal', 'Kelola transaksi keuangan, pemetaan akun, dan penyesuaian inventaris.', [
        ['title'=>'Jurnal Umum', 'desc'=>'Input dan tinjau entri jurnal manual untuk transaksi yang tidak terotomasi oleh sistem. Pastikan setiap perpindahan dana memiliki catatan debit dan kredit yang seimbang untuk menjaga integritas buku besar perusahaan.', 'link'=>'admin.php?page=puri-manual-journal', 'icon'=>'book-open', 
         'badge' => ($stats['journal'] > 0) ? $stats['journal'] . ' New' : ''],
		['title'=>'Transfer Laci', 'desc'=>'Kelola perpindahan stok dari Gudang Utama ke Laci Kasir dengan konversi otomatis dari satuan bendel ke keping (pcs). Sistem memastikan integritas data melalui update saldo atomik dan pencatatan mutasi ganda pada buku besar (ledger) untuk setiap transaksi.', 'link'=>'admin.php?page=puri-stock-transfer', 'icon'=>'arrow-right-from-line'],
        ['title'=>'Stock Opname', 'desc'=>'Lakukan rekonsiliasi stok fisik dengan data sistem secara berkala. Modul ini secara otomatis akan membuat jurnal penyesuaian keuangan jika ditemukan selisih (diff) guna mencerminkan nilai persediaan yang sebenarnya.', 'link'=>'admin.php?page=puri-stock-adj', 'icon'=>'clipboard-pen',
         'badge' => ($stats['low_stock'] > 0) ? 'Check Stock' : ''],
        ['title'=>'Chart of Accounts', 'desc'=>'Atur struktur bagan akun yang menjadi fondasi utama laporan keuangan. Anda dapat mendefinisikan klasifikasi aset, kewajiban, modal, hingga beban biaya sesuai standar akuntansi.', 'link'=>'admin.php?page=puri-coa', 'icon'=>'list-checks'],
    ]);
}

// === LAPORAN ===
function puri_landing_report() {
    $stats = puri_get_menu_counts();
	puri_render_landing_cards('📊 Akuntansi & Jurnal', 'Kelola transaksi keuangan, pemetaan akun, dan penyesuaian inventaris.', [
        ['title'=>'Laba / Rugi', 'desc'=>'Hasilkan laporan performa keuangan yang menunjukkan total pendapatan dibandingkan biaya operasional. Memberikan gambaran jelas mengenai keuntungan bersih atau kerugian bisnis Anda.', 'link'=>'admin.php?page=puri-pl-report', 'icon'=>'trending-up'],
        ['title'=>'Neraca', 'desc'=>'Tinjau posisi keuangan perusahaan yang mencakup aset, kewajiban, dan ekuitas pada titik waktu tertentu. Krusial untuk melihat kesehatan finansial jangka panjang dan likuiditas.', 'link'=>'admin.php?page=puri-balance-sheet', 'icon'=>'scale'],
        ['title'=>'Summary Riyal', 'desc'=>'Lacak pergerakan kas dan saldo secara spesifik untuk mata uang Riyal guna mendukung kebutuhan operasional internasional. Mencatat setiap arus masuk dan keluar dengan transparan.', 'link'=>'admin.php?page=puri-riyal-report', 'icon'=>'chart-scatter'],
        ['title'=>'Summary Pelanggan', 'desc'=>'Pantau histori piutang dan sisa saldo deposit milik pelanggan untuk menjaga kesehatan arus kas. Menu ini menyediakan laporan pivot mendetail mengenai kewajiban pembayaran yang masih menggantung.', 'link'=>'admin.php?page=puri-pivot-report', 'icon'=>'pie-chart'],
        ['title'=>'Transaksi Harian', 'desc'=>'Mencatat setiap arus masuk dan keluar valuta asing khususnya riyal secara teliti.', 'link'=>'admin.php?page=puri-riyal-report', 'icon'=>'calendar-1'],
    ]);
}


// === PERBANKAN ===
function puri_landing_banking() {
    puri_render_landing_cards('🏦 Perbankan & Integrasi', 'Kelola akun bank dan integrasi sistem.', [
        ['title'=>'Profil & Bank', 'desc'=>'Atur informasi rekening bank perusahaan sebagai tujuan pembayaran pelanggan atau transfer ke vendor. Detail bank yang valid sangat penting untuk memastikan integrasi modul berjalan lancar.', 'link'=>'admin.php?page=puri-profile', 'icon'=>'landmark'],
        ['title'=>'Transfer Laci', 'desc'=>'Kelola mutasi perpindahan stok dan dana antar lokasi penyimpanan atau cabang secara aman. Memastikan perpindahan fisik barang diikuti catatan administratif yang sinkron di sistem.', 'link'=>'admin.php?page=puri-stock-transfer', 'icon'=>'refresh-cw'],
        ['title'=>'Integrasi Excel', 'desc'=>'Sinkronkan data transaksi atau daftar produk dari spreadsheet eksternal melalui jalur webhook. Menghemat waktu dibandingkan input manual dan meminimalisir kesalahan manusia.', 'link'=>'admin.php?page=puri-webhook', 'icon'=>'link-2'],
    ]);
}

// === SETTING ===
function puri_landing_setting() {
    puri_render_landing_cards('⚙️ Setting & Maintenance', 'Pengaturan sistem dan alat bantu teknis.', [
        ['title'=>'Maintenance', 'desc'=>'Perawatan rutin sistem seperti reset data sementara, sinkronisasi ulang database, dan perbaikan tabel. Alat bantu teknis untuk memastikan performa database tetap ringan dan responsif.', 'link'=>'admin.php?page=puri-maintenance', 'icon'=>'wrench'],
        ['title'=>'Mass Sync SKU', 'desc'=>'Pembaruan massal SKU ke database SQL utama untuk keseragaman data. Memungkinkan sinkronisasi harga, nama, dan stok untuk ribuan item dalam hitungan detik.', 'link'=>'admin.php?page=puri-mass-sync', 'icon'=>'zap'],
    ]);
}