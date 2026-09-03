/**
 * BOOKMARKLET EMIS SISWA — PINTU MADRASAH
 *
 * File ini adalah SOURCE CODE yang terbaca (unminified).
 * Versi minified untuk href bookmarklet ada di: emis-siswa.min.js
 *
 * Cara kerja:
 * 1. Staf login ke emis.kemenag.go.id
 * 2. Staf klik tombol bookmarklet ini (yang sudah tersimpan di bookmark bar)
 * 3. Script berjalan di konteks halaman EMIS, membaca JWT token
 * 4. Token dikirim ke server TU via fetch()
 * 5. Server TU menyimpan token di Redis dan memulai penarikan data di background
 */
(function () {
    'use strict';

    // ── Konfigurasi (diganti otomatis saat generate) ──────────────────
    var SERVER_URL = window.PINTU_SERVER_URL || 'https://pintu.madrasah.sch.id';
    var ENDPOINT   = SERVER_URL + '/api/emis/token';

    // ── Notifikasi UI (injected ke halaman EMIS) ──────────────────────
    function showNotif(message, type) {
        var existing = document.getElementById('__pintu-notif__');
        if (existing) existing.remove();

        var colors = {
            success : { bg: '#65a30d', border: '#4d7c0f' },
            error   : { bg: '#dc2626', border: '#b91c1c' },
            loading : { bg: '#1d4ed8', border: '#1e40af' },
        };
        var c = colors[type] || colors.loading;

        var el = document.createElement('div');
        el.id = '__pintu-notif__';
        el.style.cssText = [
            'position:fixed', 'top:20px', 'right:20px', 'z-index:2147483647',
            'background:' + c.bg, 'border:2px solid ' + c.border,
            'color:#fff', 'padding:14px 20px', 'border-radius:10px',
            'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            'font-size:14px', 'line-height:1.5', 'max-width:340px',
            'box-shadow:0 8px 24px rgba(0,0,0,0.25)',
            'transition:opacity 0.3s ease',
        ].join(';');
        el.innerHTML = message;
        document.body.appendChild(el);

        if (type !== 'loading') {
            setTimeout(function () {
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 300);
            }, 5000);
        }
    }

    // ── Cari Token JWT dari berbagai lokasi ───────────────────────────
    function findToken() {
        var token = null;

        // 1. localStorage — lokasi paling umum untuk SPA (Vue/React)
        var lsKeys = ['token', 'access_token', 'accessToken', 'jwt', 'authToken', 'auth_token'];
        for (var i = 0; i < lsKeys.length; i++) {
            token = localStorage.getItem(lsKeys[i]);
            if (token && token.split('.').length === 3) break;
            token = null;
        }

        // 2. sessionStorage
        if (!token) {
            for (var j = 0; j < lsKeys.length; j++) {
                token = sessionStorage.getItem(lsKeys[j]);
                if (token && token.split('.').length === 3) break;
                token = null;
            }
        }

        // 3. Cookie (nama 'token' atau 'laravel_session' — jarang di EMIS)
        if (!token) {
            var cookies = document.cookie.split(';');
            for (var k = 0; k < cookies.length; k++) {
                var parts = cookies[k].trim().split('=');
                if (['token', 'access_token'].indexOf(parts[0]) > -1) {
                    var val = decodeURIComponent(parts[1] || '');
                    if (val.split('.').length === 3) { token = val; break; }
                }
            }
        }

        // 4. Axios default headers (beberapa SPA menyimpan di sini)
        if (!token && window.axios && window.axios.defaults && window.axios.defaults.headers) {
            var auth = window.axios.defaults.headers.common &&
                       window.axios.defaults.headers.common['Authorization'];
            if (auth && typeof auth === 'string') {
                token = auth.replace(/^Bearer\s+/i, '');
                if (!token || token.split('.').length !== 3) token = null;
            }
        }

        return token;
    }

    // ── Main ──────────────────────────────────────────────────────────
    showNotif('⚡ <strong>PINTU MADRASAH</strong><br>Mencari token EMIS...', 'loading');

    var token = findToken();

    if (!token) {
        showNotif(
            '❌ <strong>Token tidak ditemukan</strong><br>' +
            'Pastikan Anda sudah login ke EMIS dan coba lagi.',
            'error'
        );
        return;
    }

    fetch(ENDPOINT, {
        method  : 'POST',
        headers : {
            'Content-Type' : 'application/json',
            'Accept'       : 'application/json',
            'X-Source'     : 'bookmarklet-emis-siswa',
        },
        body: JSON.stringify({
            token     : token,
            source    : 'emis_siswa',
            timestamp : Date.now(),
            url       : window.location.href,
        }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            showNotif(
                '✅ <strong>Token berhasil dikirim!</strong><br>' +
                'Data siswa sedang ditarik otomatis.<br>' +
                '<small style="opacity:0.8">Tab ini bisa ditutup.</small>',
                'success'
            );
        } else {
            showNotif('❌ <strong>Gagal:</strong> ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(function (e) {
        showNotif(
            '❌ <strong>Koneksi ke Server TU gagal</strong><br>' +
            '<small>' + e.message + '</small>',
            'error'
        );
    });

})();
