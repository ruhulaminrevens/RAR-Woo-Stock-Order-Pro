/* RAR Woo Stock & Order — admin Control Center v1.4.0 (no dependencies) */
(function () {
    'use strict';
    var C = window.RARWSOAdmin || {};
    var T = C.i18n || {};
    var root = document.getElementById('rarx');
    if (!root) return;
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    /* ---------------- toast ---------------- */
    var toastEl = null, toastTimer = 0;
    function toast(msg) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'rarx-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
    }

    /* ---------------- copy ---------------- */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
        }
        return Promise.resolve(legacyCopy(text));
    }
    function legacyCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rarx-copy],[data-rarx-copy-text]');
        if (b) {
            var text = b.getAttribute('data-rarx-copy-text');
            if (text === null) {
                var src = $(b.getAttribute('data-rarx-copy'));
                text = src ? (src.value !== undefined ? src.value : src.textContent) : '';
                if (src && src.select) src.select();
            }
            copyText(text).then(function (ok) { toast(ok ? (T.copied || 'Copied') : (T.copyFail || 'Copy failed')); });
            return;
        }
        var d = e.target.closest('[data-rarx-dismiss]');
        if (d) { var f = d.closest('.rarx-flash'); if (f) f.remove(); return; }
        var t = e.target.closest('[data-rarx-toggle]');
        if (t) { togglePanel(t); return; }
        var fo = e.target.closest('[data-rarx-focus]');
        if (fo) { setTimeout(function () { var el = $(fo.getAttribute('data-rarx-focus')); if (el) el.focus(); }, 60); }
    });

    /* ---------------- confirm + double-submit guard ---------------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!root.contains(f)) return;
        var msg = f.getAttribute('data-rarx-confirm');
        if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
        var isExport = f.querySelector('input[name="action"][value="rar_wso_export"]');
        var btn = f.querySelector('button[type="submit"],button:not([type])');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-busy');
            // Downloads don't leave the page: give the button back.
            setTimeout(function () { btn.disabled = false; btn.classList.remove('is-busy'); }, isExport ? 2500 : 15000);
        }
        if (f.hasAttribute('data-rarx-dirty-form')) dirty = false;
    });

    /* ---------------- tabs: keep the active one visible on phones ---------------- */
    var tabs = $('.rarx-tabs'), active = $('.rarx-tab.is-active');
    if (tabs && active && tabs.scrollWidth > tabs.clientWidth) tabs.scrollLeft = Math.max(0, active.offsetLeft - 24);

    /* ---------------- staff: panels, search, filters ---------------- */
    function togglePanel(btn, forceOpen) {
        var panel = $(btn.getAttribute('data-rarx-toggle'));
        if (!panel) return;
        var open = forceOpen === true ? true : panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var art = btn.closest('.rarx-person');
        if (art) art.classList.toggle('is-open', open);
        if (open) { var first = panel.querySelector('input,select'); if (first && forceOpen !== true) first.focus({ preventScroll: true }); }
    }
    var people = $('[data-rarx-people]');
    if (people) {
        var q = '', filter = 'all';
        var qInput = $('[data-rarx-staff-q]');
        var none = $('[data-rarx-nomatch]');
        var apply = function () {
            var shown = 0;
            $$('.rarx-person', people).forEach(function (a) {
                var ok = (a.getAttribute('data-filters') || '').split(' ').indexOf(filter) !== -1 && (!q || (a.getAttribute('data-search') || '').indexOf(q) !== -1);
                a.hidden = !ok;
                if (ok) shown++;
            });
            if (none) none.hidden = shown > 0;
        };
        if (qInput) qInput.addEventListener('input', function () { q = qInput.value.trim().toLowerCase(); apply(); });
        $$('[data-rarx-filter]').forEach(function (b) {
            b.addEventListener('click', function () {
                filter = b.getAttribute('data-rarx-filter');
                $$('[data-rarx-filter]').forEach(function (x) { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                apply();
            });
        });
        // Back from a staff action: re-open that person's panel.
        var m = /^#rarx-staff-(\d+)$/.exec(location.hash);
        if (m) {
            var tb = $('[data-rarx-toggle="#rarx-manage-' + m[1] + '"]');
            if (tb) togglePanel(tb, true);
            var art = document.getElementById('rarx-staff-' + m[1]);
            if (art) setTimeout(function () { art.scrollIntoView({ block: 'center' }); }, 50);
        }
    }

    /* ---------------- QR code (byte mode, error correction M, versions 1–10) ---------------- */
    var QR = (function () {
        var ECC = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
        var BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
        function rawModules(v) {
            var r = (16 * v + 128) * v + 64;
            if (v >= 2) { var n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; }
            return r;
        }
        function dataCw(v) { return Math.floor(rawModules(v) / 8) - ECC[v] * BLOCKS[v]; }
        function mul(x, y) { var z = 0; for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; } return z & 0xFF; }
        function divisor(deg) {
            var r = []; for (var i = 0; i < deg - 1; i++) r.push(0); r.push(1);
            var rt = 1;
            for (var k = 0; k < deg; k++) {
                for (var j = 0; j < r.length; j++) { r[j] = mul(r[j], rt); if (j + 1 < r.length) r[j] ^= r[j + 1]; }
                rt = mul(rt, 2);
            }
            return r;
        }
        function remainder(data, div) {
            var r = div.map(function () { return 0; });
            data.forEach(function (b) { var f = b ^ r.shift(); r.push(0); div.forEach(function (c, i) { r[i] ^= mul(c, f); }); });
            return r;
        }
        function utf8(s) {
            if (window.TextEncoder) return Array.prototype.slice.call(new TextEncoder().encode(s));
            var out = [], u = unescape(encodeURIComponent(s));
            for (var i = 0; i < u.length; i++) out.push(u.charCodeAt(i));
            return out;
        }
        function encode(text) {
            var bytes = utf8(text), ver = 0, v;
            for (v = 1; v <= 10; v++) { if (4 + (v <= 9 ? 8 : 16) + bytes.length * 8 <= dataCw(v) * 8) { ver = v; break; } }
            if (!ver) return null;
            var bits = [];
            var put = function (val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
            put(4, 4); put(bytes.length, ver <= 9 ? 8 : 16); bytes.forEach(function (b) { put(b, 8); });
            var cap = dataCw(ver) * 8;
            put(0, Math.min(4, cap - bits.length));
            put(0, (8 - bits.length % 8) % 8);
            for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
            var data = [];
            for (var i = 0; i < bits.length; i += 8) { var by = 0; for (var j = 0; j < 8; j++) by = (by << 1) | bits[i + j]; data.push(by); }
            var nb = BLOCKS[ver], el = ECC[ver], raw = Math.floor(rawModules(ver) / 8), nShort = nb - raw % nb, sLen = Math.floor(raw / nb);
            var div = divisor(el), blocks = [], k = 0;
            for (i = 0; i < nb; i++) {
                var dat = data.slice(k, k + sLen - el + (i < nShort ? 0 : 1)); k += dat.length;
                var ecc = remainder(dat, div);
                if (i < nShort) dat.push(0);
                blocks.push(dat.concat(ecc));
            }
            var all = [];
            for (i = 0; i < blocks[0].length; i++) { for (j = 0; j < blocks.length; j++) { if (i !== sLen - el || j >= nShort) all.push(blocks[j][i]); } }

            var size = ver * 4 + 17, mod = [], fn = [], y, x;
            for (y = 0; y < size; y++) { mod.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
            var set = function (xx, yy, d) { mod[yy][xx] = d; fn[yy][xx] = true; };
            for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
            var finder = function (cx, cy) {
                for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
                    var dd = Math.max(Math.abs(dx), Math.abs(dy)), px = cx + dx, py = cy + dy;
                    if (px >= 0 && px < size && py >= 0 && py < size) set(px, py, dd !== 2 && dd !== 4);
                }
            };
            finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
            var al = [];
            if (ver > 1) {
                var na = Math.floor(ver / 7) + 2, step = Math.ceil((ver * 4 + 4) / (na * 2 - 2)) * 2;
                al.push(6);
                for (var p = size - 7; al.length < na; p -= step) al.splice(1, 0, p);
            }
            for (i = 0; i < al.length; i++) for (j = 0; j < al.length; j++) {
                if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) continue;
                for (var ay = -2; ay <= 2; ay++) for (var ax = -2; ax <= 2; ax++) set(al[i] + ax, al[j] + ay, Math.max(Math.abs(ax), Math.abs(ay)) !== 1);
            }
            var format = function (mask) {
                var d = mask, r = d; // error correction level M = 00
                for (var q = 0; q < 10; q++) r = (r << 1) ^ ((r >>> 9) * 0x537);
                var b = ((d << 10) | r) ^ 0x5412, bit = function (n) { return ((b >>> n) & 1) !== 0; };
                for (q = 0; q <= 5; q++) set(8, q, bit(q));
                set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
                for (q = 9; q < 15; q++) set(14 - q, 8, bit(q));
                for (q = 0; q < 8; q++) set(size - 1 - q, 8, bit(q));
                for (q = 8; q < 15; q++) set(8, size - 15 + q, bit(q));
                set(8, size - 8, true);
            };
            format(0);
            if (ver >= 7) {
                var rv = ver;
                for (i = 0; i < 12; i++) rv = (rv << 1) ^ ((rv >>> 11) * 0x1F25);
                var vb = (ver << 12) | rv;
                for (i = 0; i < 18; i++) { var db = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, c = Math.floor(i / 3); set(a, c, db); set(c, a, db); }
            }
            var bi = 0;
            for (var right = size - 1; right >= 1; right -= 2) {
                if (right === 6) right = 5;
                for (var vert = 0; vert < size; vert++) for (j = 0; j < 2; j++) {
                    x = right - j;
                    y = ((right + 1) & 2) === 0 ? size - 1 - vert : vert;
                    if (!fn[y][x] && bi < all.length * 8) { mod[y][x] = ((all[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0; bi++; }
                }
            }
            var masks = [
                function (x, y) { return (x + y) % 2 === 0; }, function (x, y) { return y % 2 === 0; },
                function (x) { return x % 3 === 0; }, function (x, y) { return (x + y) % 3 === 0; },
                function (x, y) { return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; }, function (x, y) { return x * y % 2 + x * y % 3 === 0; },
                function (x, y) { return (x * y % 2 + x * y % 3) % 2 === 0; }, function (x, y) { return ((x + y) % 2 + x * y % 3) % 2 === 0; }
            ];
            var applyMask = function (m) { for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) if (!fn[yy][xx] && masks[m](xx, yy)) mod[yy][xx] = !mod[yy][xx]; };
            var penalty = function () {
                var s = 0, line = function (get) {
                    var t = 0;
                    for (var aa = 0; aa < size; aa++) {
                        var run = 0, col = null, row = [];
                        for (var bb = 0; bb < size; bb++) {
                            var v2 = get(aa, bb); row.push(v2 ? 1 : 0);
                            if (v2 === col) { run++; if (run === 5) t += 3; else if (run > 5) t++; } else { col = v2; run = 1; }
                        }
                        var str = '0000' + row.join('') + '0000', at = -1;
                        while ((at = str.indexOf('1011101', at + 1)) !== -1) {
                            if (str.substr(at - 4, 4) === '0000' || str.substr(at + 7, 4) === '0000') t += 40;
                        }
                    }
                    return t;
                };
                s += line(function (a2, b2) { return mod[a2][b2]; });
                s += line(function (a2, b2) { return mod[b2][a2]; });
                var dark = 0;
                for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) {
                    if (mod[yy][xx]) dark++;
                    if (yy < size - 1 && xx < size - 1) { var cc = mod[yy][xx]; if (cc === mod[yy][xx + 1] && cc === mod[yy + 1][xx] && cc === mod[yy + 1][xx + 1]) s += 3; }
                }
                var total = size * size;
                s += Math.max(0, Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
                return s;
            };
            var best = 0, bestScore = Infinity;
            for (var mm = 0; mm < 8; mm++) {
                applyMask(mm); format(mm);
                var sc = penalty();
                if (sc < bestScore) { best = mm; bestScore = sc; }
                applyMask(mm);
            }
            applyMask(best); format(best);
            return { size: size, mod: mod, version: ver, mask: best };
        }
        return { encode: encode };
    })();
    window.RARWSOQR = QR; // for tests

    var QUIET = 4, INK = '#0f172a';
    function qrSvg(text) {
        var q = QR.encode(text);
        if (!q) return '';
        var n = q.size + QUIET * 2, d = '';
        for (var y = 0; y < q.size; y++) for (var x = 0; x < q.size; x++) if (q.mod[y][x]) d += 'M' + (x + QUIET) + ',' + (y + QUIET) + 'h1v1h-1z';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" aria-hidden="true"><rect width="' + n + '" height="' + n + '" fill="#fff"/><path d="' + d + '" fill="' + INK + '"/></svg>';
    }
    function qrPng(text, scale) {
        var q = QR.encode(text);
        if (!q) return null;
        var n = (q.size + QUIET * 2) * scale, cv = document.createElement('canvas'), g = cv.getContext('2d');
        cv.width = n; cv.height = n;
        g.fillStyle = '#fff'; g.fillRect(0, 0, n, n); g.fillStyle = INK;
        for (var y = 0; y < q.size; y++) for (var x = 0; x < q.size; x++) if (q.mod[y][x]) g.fillRect((x + QUIET) * scale, (y + QUIET) * scale, scale, scale);
        return cv;
    }

    var dlg = $('#rarx-qr-dialog');
    function openQr() {
        if (!dlg) return;
        var box = $('#rarx-qr-box', dlg);
        if (box && !box.firstChild) box.innerHTML = qrSvg(C.staffUrl || location.origin) || '<p>' + (T.tooLong || 'Too long') + '</p>';
        if (typeof dlg.showModal === 'function') { if (!dlg.open) dlg.showModal(); } else dlg.setAttribute('open', '');
    }
    function closeQr() { if (!dlg) return; if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open'); }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-rarx-qr]')) { e.preventDefault(); openQr(); return; }
        if (e.target.closest('[data-rarx-close]')) { closeQr(); return; }
        if (e.target.closest('[data-rarx-print]')) {
            document.body.classList.add('rarx-printing');
            var done = function () { document.body.classList.remove('rarx-printing'); window.removeEventListener('afterprint', done); };
            window.addEventListener('afterprint', done);
            setTimeout(function () { window.print(); setTimeout(done, 1500); }, 30);
            return;
        }
        if (e.target.closest('[data-rarx-qr-png]')) {
            var cv = qrPng(C.staffUrl || location.origin, 14);
            if (!cv) return;
            var a = document.createElement('a');
            a.download = 'staff-app-qr.png';
            a.href = cv.toDataURL('image/png');
            document.body.appendChild(a); a.click(); a.remove();
        }
    });
    if (dlg) dlg.addEventListener('click', function (e) { if (e.target === dlg) closeQr(); }); // click on backdrop

    /* ---------------- Overview live refresh ---------------- */
    var live = $('[data-rarx-live]');
    if (live && C.ajaxUrl) {
        var stateEl = $('[data-rarx-live-state]'), toggle = $('[data-rarx-live-toggle]'), timer = 0, busy = false, last = Date.now();
        var ico = stateEl ? (stateEl.querySelector('svg') ? stateEl.querySelector('svg').outerHTML : '') : '';
        var refresh = function () {
            if (busy) return;
            busy = true;
            if (stateEl) stateEl.classList.add('is-busy');
            var body = new URLSearchParams();
            body.append('action', 'rar_wso_admin_live');
            body.append('nonce', C.nonce || '');
            var ctrl = window.AbortController ? new AbortController() : null;
            var to = ctrl ? setTimeout(function () { ctrl.abort(); }, 25000) : 0;
            fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: ctrl ? ctrl.signal : undefined })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.success) throw new Error('bad');
                    live.innerHTML = j.data.html;
                    last = Date.now();
                    if (stateEl) stateEl.innerHTML = ico + ' ' + (T.updated || 'Updated') + ' ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
                })
                .catch(function () { if (stateEl) stateEl.textContent = T.offline || 'Could not refresh'; })
                .then(function () { clearTimeout(to); busy = false; if (stateEl) stateEl.classList.remove('is-busy'); });
        };
        var start = function () { stop(); timer = setInterval(function () { if (!document.hidden) refresh(); }, 60000); };
        var stop = function () { if (timer) clearInterval(timer); timer = 0; };
        if (toggle) {
            try { if (localStorage.getItem('rarxLiveOff') === '1') toggle.checked = false; } catch (e) { /* storage blocked */ }
            toggle.addEventListener('change', function () {
                try { localStorage.setItem('rarxLiveOff', toggle.checked ? '0' : '1'); } catch (e) { /* storage blocked */ }
                if (toggle.checked) { refresh(); start(); } else stop();
            });
            if (toggle.checked) start();
        }
        var now = $('[data-rarx-live-now]');
        if (now) now.addEventListener('click', refresh);
        document.addEventListener('visibilitychange', function () { if (!document.hidden && toggle && toggle.checked && Date.now() - last > 60000) refresh(); });
    }

    /* ---------------- Settings ---------------- */
    var form = $('[data-rarx-dirty-form]');
    var dirty = false;
    if (form) {
        var bar = $('[data-rarx-savebar]'), msg = $('[data-rarx-dirty-msg]');
        var baseMsg = msg ? msg.innerHTML : '';
        var markDirty = function () {
            if (dirty) return;
            dirty = true;
            if (bar) bar.classList.add('is-dirty');
            if (msg) msg.innerHTML = baseMsg.replace(/<\/svg>[\s\S]*$/, '</svg> ' + (T.unsaved || 'Unsaved changes'));
        };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

        // Section nav highlights the section in view.
        var links = $$('[data-rarx-spy]');
        if (links.length && 'IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    links.forEach(function (l) { l.classList.toggle('is-active', l.getAttribute('data-rarx-spy') === en.target.id); });
                });
            }, { rootMargin: '-120px 0px -60% 0px' });
            links.forEach(function (l) { var s = document.getElementById(l.getAttribute('data-rarx-spy')); if (s) io.observe(s); });
        }

        // Brand colour: live preview across this screen.
        var color = $('#rarx-f-brand_color'), code = $('[data-rarx-color-code]'), top = $('[data-rarx-preview-top]');
        var paint = function (v) {
            if (!/^#[0-9a-f]{6}$/i.test(v)) return;
            if (code) code.textContent = v.toLowerCase();
            if (top) top.style.background = v;
            root.style.setProperty('--rarx-brand', v);
        };
        if (color) color.addEventListener('input', function () { paint(color.value); });
        $$('[data-rarx-swatch]').forEach(function (s) {
            s.addEventListener('click', function () {
                if (!color) return;
                color.value = s.getAttribute('data-rarx-swatch');
                paint(color.value);
                markDirty();
            });
        });
        var title = $('[data-rarx-preview="title"]'), ptitle = $('[data-rarx-preview-title]');
        if (title && ptitle) title.addEventListener('input', function () { ptitle.textContent = title.value || '—'; });

        // Logo from the Media Library.
        var pick = $('[data-rarx-logo-pick]'), clear = $('[data-rarx-logo-clear]'), idIn = $('[data-rarx-logo-id]'), prev = $('[data-rarx-logo-prev]'), mark = $('[data-rarx-preview-mark]');
        var frame = null, blank = prev ? prev.innerHTML : '', blankMark = mark ? mark.innerHTML : '';
        var showLogo = function (url) {
            var img = url ? '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">' : '';
            if (prev) prev.innerHTML = img || blank;
            if (mark) mark.innerHTML = img || blankMark;
            if (clear) clear.hidden = !url;
        };
        if (pick) pick.addEventListener('click', function () {
            if (!window.wp || !wp.media) return;
            if (!frame) {
                frame = wp.media({ title: T.pickLogo || 'Choose a logo', button: { text: T.useLogo || 'Use this logo' }, library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var a = frame.state().get('selection').first().toJSON();
                    var url = (a.sizes && (a.sizes.thumbnail || a.sizes.medium) ? (a.sizes.thumbnail || a.sizes.medium).url : a.url);
                    if (idIn) idIn.value = a.id;
                    showLogo(url);
                    markDirty();
                });
            }
            frame.open();
        });
        if (clear) clear.addEventListener('click', function () { if (idIn) idIn.value = '0'; showLogo(''); markDirty(); });
    }
})();
