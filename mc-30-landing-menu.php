<?php
/**
 * =============================================================================
 * MC 30 - Landing Menu Pages (Refactored Version) PATCHED 2.0 GPT
 * =============================================================================
 * * Modul ini telah di-refactor dengan standar UI Modern (Waveapps Inspired).
 * Menggunakan Lucide Icons (Gratis) dan sistem Badge Otomatis.
 */

defined('ABSPATH') || exit;

/**
 * RENDERER UTAMA: Membangun kartu navigasi dengan styling modern (Metro-style)
 */
function puri_render_landing_cards($title, $description, $cards) {
    // Load Lucide Icons & Inter Font
    echo '<script src="https://unpkg.com/lucide@latest"></script>';
echo '<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap");

.puri-container {
    font-family: "Inter", sans-serif;
    background: #f8fafc;
    padding: 30px 20px;
    min-height: 100vh;
    color: #0f172a;
}

.puri-header-section { margin-bottom: 58px; }
.puri-header-section h1 {
    font-weight: 800;
    font-size: 2.1rem;
    letter-spacing: 0.03em;
    margin: 0;
}
.puri-header-section p {
    margin-top: 6px;
    font-size: 1.02rem;
    color: #64748b;
    max-width: 880px;
}

/* === GRID SYSTEM (Metro + Masonry) === */
.puri-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, 172px);
    grid-auto-rows: 172px;
    gap: 17px;
    grid-auto-flow: dense;
}

/* === BASE CARD === */
.puri-nav-card {
    background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
    border-radius: 10px;
    padding: 20px;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}

.puri-nav-card:hover {
    transform: translateY(-6px) scale(1.01);
    box-shadow: 0 14px 28px rgba(37,99,235,0.18);
    border-color: #2563eb;
}

/* === SIZE VARIANTS === */
.card1 { grid-column: span 1; grid-row: span 1; }
.card2 { grid-column: span 2; grid-row: span 2; }
.card3 { grid-column: span 3; grid-row: span 3; }
.card4 { grid-column: span 2; grid-row: span 1; }
.card5 { grid-column: span 2; grid-row: span 3; }
.card6 { grid-column: span 3; grid-row: span 1; }
.card7 { grid-column: span 3; grid-row: span 2; }
.card-11 { grid-column: span 1; grid-row: span 1; }
.card-22 { grid-column: span 2; grid-row: span 2; }
.card-33 { grid-column: span 3; grid-row: span 3; }
.card-21 { grid-column: span 2; grid-row: span 1; }
.card-23 { grid-column: span 2; grid-row: span 3; }
.card-31 { grid-column: span 3; grid-row: span 1; }
.card-32 { grid-column: span 3; grid-row: span 2; }

/* === BADGE === */
.puri-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    letter-spacing: 0.05em;
}

/* === ICON === */
.puri-icon-wrapper {
    width: 48px;
    height: 48px;
    min-width: 48px;
    min-height: 48px;
    max-width: 48px;
    max-height: 48px;
    flex-shrink: 0;
    border-radius: 12px;
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
    transition: 0.3s ease;
}

.puri-nav-card:hover .puri-icon-wrapper {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    transform: scale(1.08);
}

.puri-icon-wrapper svg {
    width: 22px;
    height: 22px;
}


/* === TEXT === */
.puri-card-title {
    font-weight: 700;
    font-size: 1.05rem;
    color: #0f172a;
    margin-bottom: 6px;
    line-height: 1.25;
    max-height: 2.6em; /* 2 lines */
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.puri-card-desc {
    font-size: 0.92rem;
    color: #475569;
    line-height: 1.5;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 3; /* max 3 lines */
    -webkit-box-orient: vertical;
}

/* === FOOTER === */
.puri-card-footer {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
    font-size: 13px;
    font-weight: 600;
    color: #2563eb;
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>';

    echo '<div class="puri-container">';
    echo '<div class="puri-header-section"><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div>';
    echo '<div class="puri-card-grid">';

    foreach ($cards as $c) {
        $badge = isset($c['badge']) ? $c['badge'] : '';
        $card_size = isset($c['card']) ? $c['card'] : 'card1';

        echo '<a href="' . esc_url(admin_url($c['link'])) . '" class="puri-nav-card ' . esc_attr($card_size) . '">';

        if ($badge) echo '<div class="puri-badge">' . esc_html($badge) . '</div>';

        echo '<div class="puri-icon-wrapper"><i data-lucide="' . esc_attr($c['icon']) . '"></i></div>';
        echo '<div class="puri-card-title">' . esc_html($c['title']) . '</div>';
        echo '<div class="puri-card-desc">' . esc_html($c['desc']) . '</div>';
//        echo '<div class="puri-card-footer">Buka Modul <i data-lucide="arrow-right" style="width:14px;"></i></div>';
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
        ['title'=>'Monitoring', 'desc'=>'Pantau seluruh aktivitas operasional dan performa sistem secara real-time untuk memastikan kelancaran alur kerja. Menu ini menyajikan statistik penggunaan dan log aktivitas harian yang krusial.', 'link'=>'admin.php?page=puri-monitoring', 'icon'=>'monitor', 'card'=>'card2'],
        ['title'=>'Profil & Bank', 'desc'=>'Kelola identitas resmi perusahaan beserta informasi rekening bank yang digunakan untuk seluruh transaksi sistem. Informasi di sini akan menjadi referensi utama pada header dokumen resmi.', 'link'=>'admin.php?page=puri-profile', 'icon'=>'building-2', 'card'=>'card1'],
    ]);
}

// === PEMBELIAN ===
function puri_landing_purchase() {
    $stats = puri_get_menu_counts();
    puri_render_landing_cards('📦 Pembelian & Pengeluaran Kas', 'Kelola tagihan, pemasok, dan konsinyasi.', [
        ['title'=>'Purchasing', 'card'=>'card2', 'desc'=>'Kelola proses pengadaan barang (procurement) mulai dari pemesanan kepada supplier hingga penerimaan stok. Modul ini memastikan setiap barang masuk tercatat harga belinya dengan akurat.', 'link'=>'admin.php?page=puri-procurement', 'icon'=>'shopping-cart'],
        ['title'=>'Jurnal Biaya', 'card'=>'card4', 'desc'=>'Catat seluruh biaya operasional, tagihan vendor, dll.', 'link'=>'admin.php?page=puri-expenses', 'icon'=>'file-text', 'badge' => ($stats['bills'] > 0) ? $stats['bills'] . " Unpaid" : ""],
        ['title'=>'Data Pemasok / Vendor', 'desc'=>'Database lengkap mengenai vendor dan supplier yang menyediakan stok serta kebutuhan operasional. Simpan informasi kontak dan histori kerjasama untuk mempermudah koordinasi masa depan.', 'link'=>'edit.php?post_type=pr_vendor', 'icon'=>'truck', 'card'=>'card1'],
        ['title'=>'Konsinyasi', 'desc'=>'Kelola kerjasama barang titipan dari pihak ketiga dengan sistem bagi hasil yang telah disepakati. Memudahkan pemisahan stok milik sendiri dengan stok titipan serta perhitungan kompensasi otomatis.', 'link'=>'admin.php?page=puri-consignment', 'icon'=>'handshake', 'card'=>'card4'],
    ]);
}

// === PENJUALAN ===
function puri_landing_sales() {
    $stats = puri_get_menu_counts();
    puri_render_landing_cards('🛒 Penjualan & Pembayaran', 'Kelola transaksi penjualan, pelanggan, dan produk.', [
        ['title'=>'The Cockpit !! (P.O.S)', 'card'=>'card-33', 'desc'=>'Akses panel kasir utama yang dirancang untuk memproses transaksi penjualan ritel dengan cepat dan akurat. Fitur ini mencatat mutasi kas harian, manajemen shift operator, hingga pencetakan struk.', 'link'=>'admin.php?page=puri-cockpit-pos', 'icon'=>'circle-dollar-sign'],
        ['title'=>'Master Item SKU', 'card'=>'card-21', 'desc'=>'Daftar lengkap item valuta asing dan barang informasi.', 
								     'link'=>'edit.php?post_type=pr_item', 'icon'=>'package', 'badge' => ($stats['low_stock'] > 0) ? $stats['low_stock'] . " Alert" : ""],
        ['title'=>'Transaksi Harian', 'card'=>'card-31', 'desc'=>'Mencatat setiap arus masuk dan keluar valuta asing khususnya riyal secara teliti.', 'link'=>'admin.php?page=puri-riyal-report', 'icon'=>'calendar-1'],
        ['title'=>'Daftar Pelanggan', 'card'=>'card-22', 'desc'=>'Kelola basis data pelanggan secara terorganisir mulai dari informasi kontak 
											   hingga riwayat interaksi bisnis.', 'link'=>'edit.php?post_type=pr_customer', 'icon'=>'users'],
    ]);
}

// === AKUNTANSI ===
function puri_landing_accounting() {
    $stats = puri_get_menu_counts();
    puri_render_landing_cards('📊 Akuntansi & Jurnal', 'Kelola transaksi keuangan, pemetaan akun, dan penyesuaian inventaris.', [
        ['title'=>'Jurnal Umum', 'desc'=>'Input dan tinjau entri jurnal manual untuk transaksi yang tidak terotomasi oleh sistem. Pastikan setiap perpindahan dana memiliki catatan debit dan kredit yang seimbang untuk menjaga integritas buku besar perusahaan.', 'link'=>'admin.php?page=puri-manual-journal', 'icon'=>'book-open', 'badge' => ($stats['journal'] > 0) ? $stats['journal'] . " New" : "", 'card'=>'card2'],
        ['title'=>'Transfer Laci', 'desc'=>'Kelola perpindahan stok dari Gudang Utama ke Laci Kasir dengan konversi otomatis dari satuan bendel ke keping (pcs). Sistem memastikan integritas data melalui update saldo atomik dan pencatatan mutasi ganda pada buku besar (ledger) untuk setiap transaksi.', 'link'=>'admin.php?page=puri-stock-transfer', 'icon'=>'arrow-right-from-line', 'card'=>'card4'],
        ['title'=>'Stock Opname', 'desc'=>'Lakukan rekonsiliasi stok fisik dengan data sistem secara berkala. Modul ini secara otomatis akan membuat jurnal penyesuaian keuangan jika ditemukan selisih (diff) guna mencerminkan nilai persediaan yang sebenarnya.', 'link'=>'admin.php?page=puri-stock-adj', 'icon'=>'clipboard-pen', 'badge' => ($stats['low_stock'] > 0) ? "Check Stock" : "", 'card'=>'card4'],
        ['title'=>'Chart of Accounts', 'desc'=>'Atur struktur bagan akun yang menjadi fondasi utama laporan keuangan. Anda dapat mendefinisikan klasifikasi aset, kewajiban, modal, hingga beban biaya sesuai standar akuntansi.', 'link'=>'admin.php?page=puri-coa', 'icon'=>'list-checks', 'card'=>'card1'],
    ]);
}

// === LAPORAN ===
function puri_landing_report() {
    puri_render_landing_cards('📊 Akuntansi & Jurnal', 'Kelola transaksi keuangan, pemetaan akun, dan penyesuaian inventaris.', [
        ['title'=>'Laba / Rugi', 'desc'=>'Hasilkan laporan performa keuangan yang menunjukkan total pendapatan dibandingkan biaya operasional. Memberikan gambaran jelas mengenai keuntungan bersih atau kerugian bisnis Anda.', 'link'=>'admin.php?page=puri-pl-report', 'icon'=>'trending-up', 'card'=>'card2'],
        ['title'=>'Neraca', 'desc'=>'Tinjau posisi keuangan perusahaan yang mencakup aset, kewajiban, dan ekuitas pada titik waktu tertentu. Krusial untuk melihat kesehatan finansial jangka panjang dan likuiditas.', 'link'=>'admin.php?page=puri-balance-sheet', 'icon'=>'scale', 'card'=>'card1'],
        ['title'=>'Summary Riyal', 'desc'=>'Lacak pergerakan kas dan saldo secara spesifik untuk mata uang Riyal guna mendukung kebutuhan operasional internasional. Mencatat setiap arus masuk dan keluar dengan transparan.', 'link'=>'admin.php?page=puri-riyal-report', 'icon'=>'chart-scatter', 'card'=>'card1'],
        ['title'=>'Summary Pelanggan', 'desc'=>'Pantau histori piutang dan sisa saldo deposit milik pelanggan untuk menjaga kesehatan arus kas. Menu ini menyediakan laporan pivot mendetail mengenai kewajiban pembayaran yang masih menggantung.', 'link'=>'admin.php?page=puri-pivot-report', 'icon'=>'pie-chart', 'card'=>'card4'],
        ['title'=>'Transaksi Harian', 'desc'=>'Mencatat setiap arus masuk dan keluar valuta asing khususnya riyal secara teliti.', 'link'=>'admin.php?page=puri-riyal-report', 'icon'=>'calendar-1', 'card'=>'card1'],
    ]);
}

// === PERBANKAN ===
function puri_landing_banking() {
    puri_render_landing_cards('🏦 Perbankan & Integrasi', 'Kelola akun bank dan integrasi sistem.', [
        ['title'=>'Profil & Bank', 'desc'=>'Atur informasi rekening bank perusahaan sebagai tujuan pembayaran pelanggan atau transfer ke vendor. Detail bank yang valid sangat penting untuk memastikan integrasi modul berjalan lancar.', 'link'=>'admin.php?page=puri-profile', 'icon'=>'landmark', 'card'=>'card2'],
        ['title'=>'Transfer Laci', 'desc'=>'Kelola mutasi perpindahan stok dan dana antar lokasi penyimpanan atau cabang secara aman. Memastikan perpindahan fisik barang diikuti catatan administratif yang sinkron di sistem.', 'link'=>'admin.php?page=puri-stock-transfer', 'icon'=>'refresh-cw', 'card'=>'card4'],
        ['title'=>'Integrasi Excel', 'desc'=>'Sinkronkan data transaksi atau daftar produk dari spreadsheet eksternal melalui jalur webhook. Menghemat waktu dibandingkan input manual dan meminimalisir kesalahan manusia.', 'link'=>'admin.php?page=puri-webhook', 'icon'=>'link-2', 'card'=>'card1'],
    ]);
}

// === SETTING ===
function puri_landing_setting() {
    puri_render_landing_cards('⚙️ Setting & Maintenance', 'Pengaturan sistem dan alat bantu teknis.', [
        ['title'=>'Maintenance', 'desc'=>'Perawatan rutin sistem seperti reset data sementara, sinkronisasi ulang database, dan perbaikan tabel. Alat bantu teknis untuk memastikan performa database tetap ringan dan responsif.', 'link'=>'admin.php?page=puri-maintenance', 'icon'=>'wrench', 'card'=>'card2'],
        ['title'=>'Mass Sync SKU', 'desc'=>'Pembaruan massal SKU ke database SQL utama untuk keseragaman data. Memungkinkan sinkronisasi harga, nama, dan stok untuk ribuan item dalam hitungan detik.', 'link'=>'admin.php?page=puri-mass-sync', 'icon'=>'zap', 'card'=>'card4'],
    ]);
}