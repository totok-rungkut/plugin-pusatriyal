<?php
/**
 * MC 06 - Frontend Visitor Cockpit (Shortcode)
 * Version: 6.0.1
 * Author: Denmas Totok (refactor by Copilot)
 *
 * Purpose:
 *  - Shortcode [puri_visitor_booking] for visitor self-booking
 *  - Collect KYC basic info and cart snapshot; push to queue (MC_07) via AJAX
 *
 * Improvements:
 *  - Fix React root id mismatch (consistent id = 'puri-visitor-root')
 *  - Provide front-end nonce (puri_public_action) for AJAX and support both logged-in & guest users (wp_ajax_nopriv)
 *  - Perform AJAX booking submit to puri_submit_booking (non-blocking)
 *  - Preserve original UX: catalog selection & summary; replace native alert with booking confirmation
 */

defined('ABSPATH') || exit;

add_shortcode('puri_visitor_booking', 'puri_render_visitor_cockpit');

function puri_render_visitor_cockpit() {
    global $wpdb;

    // Prepare items (non-package) for public listing
    $items = $wpdb->get_results("
        SELECT id, sku, name, type, denom_value, sell_rate, item_image 
        FROM " . puri_table_name('T_ITEMS') . " 
        WHERE type != 'package'
    ");

    // Create a public nonce for frontend booking action
    $nonce = wp_create_nonce('puri_public_action');

    ob_start();
    ?>
    <div id="puri-visitor-root"></div>

    <script>
      // Expose AJAX target and public nonce to inline React
      window.puri_public = {
        ajax_url: "<?php echo admin_url('admin-ajax.php'); ?>",
        nonce: "<?php echo esc_js($nonce); ?>"
      };
      window.puri_items = <?php echo json_encode($items); ?>;
    </script>

    <script type="text/babel">
      const { useState, useMemo } = React;
      const VisitorApp = () => {
        const [cart, setCart] = useState([]);
        const [kyc, setKyc] = useState({ name: '', phone: '', nik: '' });
        const items = window.puri_items || [];

        const addToCart = (it) => {
          const exist = cart.find(c => c.id === it.id);
          if (exist) setCart(cart.map(c => c.id === it.id ? {...c, qty: c.qty + 1} : c));
          else setCart([...cart, {...it, qty: 1}]);
        };

        const snapshot = useMemo(() => {
          let riyal = 0, idr = 0;
          cart.forEach(c => {
            riyal += (c.qty * (c.denom_value || 1));
            idr += (c.qty * (c.denom_value || 1) * (c.sell_rate || 0));
          });
          return { total_riyal: riyal, total_idr: idr };
        }, [cart]);

        const submitBooking = () => {
          if (cart.length === 0) return alert('Pilih item terlebih dahulu.');
          if (!kyc.name || !kyc.phone) return alert('Isi nama & WA.');
          const payload = {
            name: kyc.name,
            phone: kyc.phone,
            cart: cart,
            total_idr: snapshot.total_idr,
            total_riyal: snapshot.total_riyal
          };
          jQuery.post(puri_public.ajax_url, { action: 'puri_submit_booking', payload: JSON.stringify(payload), nonce: puri_public.nonce }, function(res){
            if (res && res.success) {
              alert('Reservasi terkirim. ID Pesanan: ' + res.data.order_id + '. Silakan tunggu konfirmasi kasir.');
              setCart([]); setKyc({ name:'', phone:'', nik:'' });
            } else {
              alert('Gagal mengirim booking: ' + (res.data ? res.data : 'unknown'));
            }
          });
        };

        return (
          <div style={{maxWidth:1100, margin:'20px auto', fontFamily:'Segoe UI'}}>
            <h1 style={{fontWeight:900}}>Reservasi Mandiri Pusat Riyal</h1>
            <div style={{display:'grid', gridTemplateColumns:'1fr 400px', gap:20}}>
              <div style={{display:'grid', gridTemplateColumns:'repeat(auto-fill,minmax(150px,1fr))', gap:12}}>
                {items.map(it => (
                  <div key={it.id} style={{border:'1px solid #e2e8f0', padding:12, borderRadius:8, textAlign:'center', cursor:'pointer'}} onClick={() => addToCart(it)}>
                    <img src={it.item_image || 'https://via.placeholder.com/80?text=SAR'} style={{height:80, objectFit:'contain'}}/>
                    <div style={{fontWeight:800}}>{it.sku}</div>
                    <div style={{fontSize:12, color:'#059669'}}>Rp {parseInt(it.sell_rate||0).toLocaleString()}</div>
                  </div>
                ))}
              </div>

              <div style={{background:'white', padding:20, borderRadius:12, border:'1px solid #e2e8f0'}}>
                <h3 style={{marginTop:0}}>Data Reservasi</h3>
                <div style={{marginBottom:12}}>
                  {cart.map(c => <div key={c.id} style={{display:'flex', justifyContent:'space-between'}}>{c.sku} x{c.qty}<span>Rp {(c.qty * c.denom_value * c.sell_rate).toLocaleString()}</span></div>)}
                </div>

                <div style={{marginTop:12}}>
                  <label><b>Nama</b></label>
                  <input value={kyc.name} onChange={e => setKyc({...kyc, name: e.target.value})} style={{width:'100%', padding:8, marginTop:6}}/>
                  <label style={{marginTop:8}}><b>WA</b></label>
                  <input value={kyc.phone} onChange={e => setKyc({...kyc, phone: e.target.value})} style={{width:'100%', padding:8, marginTop:6}}/>
                </div>

                <div style={{background:'#0f172a', color:'#fff', padding:12, marginTop:12, borderRadius:8, textAlign:'right'}}>
                  <div style={{fontSize:12, opacity:0.8}}>ESTIMASI TOTAL</div>
                  <div style={{fontSize:22, fontWeight:900}}>Rp {snapshot.total_idr.toLocaleString()}</div>
                  <div>Total: {snapshot.total_riyal.toLocaleString()} Riyal</div>
                </div>

                <button onClick={submitBooking} style={{marginTop:12, width:'100%', padding:12, background:'#059669', color:'#fff', border:'none', fontWeight:900}}>KONFIRMASI BOOKING</button>
              </div>
            </div>
          </div>
        );
      };

      ReactDOM.createRoot(document.getElementById('puri-visitor-root')).render(<VisitorApp />);
    </script>
    <?php
    return ob_get_clean();
}

/* Note: booking AJAX handler is implemented in MC_07 (Queue & Order Manager) as both wp_ajax and wp_ajax_nopriv */