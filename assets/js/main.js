document.addEventListener('DOMContentLoaded', function () {
    // Drawer sidebar untuk layar kecil
    var toggle = document.getElementById('menuToggle');
    var overlay = document.getElementById('sidebarOverlay');
    var closeBtn = document.getElementById('menuClose');
    function setSidebar(open, restoreFocus) {
        var wasOpen = document.body.classList.contains('sidebar-open');
        document.body.classList.toggle('sidebar-open', open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !wasOpen) {
            var first = document.querySelector('#appSidebar .sidebar-link');
            if (first) first.focus();
        } else if (!open && wasOpen && restoreFocus && toggle) {
            toggle.focus();
        }
    }
    if (toggle) toggle.addEventListener('click', function () {
        setSidebar(!document.body.classList.contains('sidebar-open'));
    });
    if (overlay) overlay.addEventListener('click', function () { setSidebar(false, true); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setSidebar(false, true); });
    document.querySelectorAll('#appSidebar .sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () { setSidebar(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setSidebar(false, true);
    });

    // Tampilkan / sembunyikan password
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.querySelector(btn.dataset.togglePassword);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = '<i class="bi bi-' + (show ? 'eye-slash' : 'eye') + '"></i>';
            btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        });
    });

    // Indikator kekuatan password
    document.querySelectorAll('[data-strength-for]').forEach(function (meter) {
        var input = document.getElementById(meter.dataset.strengthFor);
        if (!input) return;
        function score(value) {
            if (!value) return 0;
            var s = 0;
            if (value.length >= 8) s++;
            if (/[A-Za-z]/.test(value) && /\d/.test(value)) s++;
            if (value.length >= 12 || /[^A-Za-z0-9]/.test(value)) s++;
            return Math.min(3, s);
        }
        function update() { meter.dataset.level = score(input.value); }
        input.addEventListener('input', update);
        update();
    });

    // Konfirmasi untuk aksi hapus
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm || 'Yakin ingin melanjutkan?')) e.preventDefault();
        });
    });

    // Tombol cetak struk
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    // Quick range laporan: hari ini / 7 hari / 30 hari
    document.querySelectorAll('[data-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var days = parseInt(btn.dataset.range, 10);
            var today = new Date();
            var from = new Date(today);
            from.setDate(today.getDate() - (days - 1));
            var fmt = function (d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            };
            var fromEl = document.getElementById('tgl_from');
            var toEl = document.getElementById('tgl_to');
            if (fromEl) fromEl.value = fmt(from);
            if (toEl) toEl.value = fmt(today);
        });
    });
});
