/**
 * S.NET RADIUS Manager — Main JavaScript
 */
'use strict';

// ── Sidebar Toggle ─────────────────────────────────────
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebarBackdrop = document.getElementById('sidebar-backdrop');

function toggleSidebar(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    if (window.innerWidth <= 768) {
        document.body.classList.toggle('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    }
}

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', toggleSidebar);
}
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', () => {
        document.body.classList.remove('sidebar-open');
    });
}
const sidebarCloseBtn = document.getElementById('sidebar-close-btn');
if (sidebarCloseBtn) {
    sidebarCloseBtn.addEventListener('click', (e) => {
        e.preventDefault();
        document.body.classList.remove('sidebar-open');
    });
}

// Auto-close sidebar drawer on mobile when clicking non-dropdown links
document.querySelectorAll('#sidebar .nav-link:not([data-bs-toggle])').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            document.body.classList.remove('sidebar-open');
        }
    });
});

// ── Toast notifications ────────────────────────────────
const toastContainer = document.getElementById('toast-container');

function showToast(message, type = 'info', duration = 4000) {
    if (!toastContainer) return;
    const el = document.createElement('div');
    el.className = `toast-item ${type}`;
    const icon = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' }[type] || 'ℹ';
    el.innerHTML = `<strong>${icon}</strong> ${message}`;
    toastContainer.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        el.style.transition = 'all .3s';
        setTimeout(() => el.remove(), 300);
    }, duration);
}

// ── Advanced Responsive Page Loader & Progress Controller ──────────
const snetLoader = (function() {
    let progressBar = null;
    let loaderEl = null;
    let titleEl = null;
    let subTitleEl = null;
    let progressTimer = null;
    let cardShowTimer = null;
    let autoDismissTimer = null;
    let currentProgress = 0;
    let isActive = false;

    function init() {
        progressBar = document.getElementById('app-progress-bar');
        loaderEl = document.getElementById('page-loader');
        titleEl = document.getElementById('page-loader-title');
        subTitleEl = document.getElementById('page-loader-subtitle');
    }

    function setProgress(val) {
        currentProgress = Math.min(100, Math.max(0, val));
        if (progressBar) {
            progressBar.style.width = currentProgress + '%';
            if (currentProgress > 0) {
                progressBar.classList.add('active');
            }
        }
    }

    function start(title, subtitle, immediateCard = false) {
        if (!progressBar) init();
        isActive = true;
        clearTimers();

        if (title && titleEl) {
            titleEl.textContent = title;
        } else if (titleEl) {
            titleEl.textContent = 'Memuat Halaman...';
        }

        if (subtitle && subTitleEl) {
            subTitleEl.textContent = subtitle;
        } else if (subTitleEl) {
            subTitleEl.textContent = 'Menyiapkan data, mohon tunggu';
        }

        // 1. Jalankan progress bar di paling atas secara instan
        setProgress(18);
        progressTimer = setInterval(() => {
            if (currentProgress < 85) {
                const step = Math.max(1, (85 - currentProgress) * 0.15);
                setProgress(currentProgress + step);
            }
        }, 160);

        // 2. Munculkan card glassmorphism
        if (immediateCard) {
            if (loaderEl) loaderEl.classList.add('active');
        } else {
            // Micro-delay (120ms) agar klik menu instan terasa sangat cepat,
            // dan transisi halaman dengan jeda server menampilkan card keren
            cardShowTimer = setTimeout(() => {
                if (isActive && loaderEl) {
                    loaderEl.classList.add('active');
                }
            }, 120);
        }

        // 3. Failsafe auto-dismiss (12 detik) agar tidak pernah macet
        autoDismissTimer = setTimeout(() => {
            done();
        }, 12000);
    }

    function done() {
        if (!progressBar) init();
        isActive = false;
        clearTimers();

        // Selesaikan bar sampai 100%
        setProgress(100);

        setTimeout(() => {
            if (progressBar) {
                progressBar.classList.remove('active');
                setTimeout(() => {
                    if (!isActive) progressBar.style.width = '0%';
                }, 300);
            }
        }, 220);

        if (loaderEl) {
            loaderEl.classList.remove('active');
        }

        // Hapus kelas navigasi di sidebar
        document.querySelectorAll('#sidebar .nav-link.is-navigating').forEach(el => {
            el.classList.remove('is-navigating');
        });
    }

    function clearTimers() {
        if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
        if (cardShowTimer) { clearTimeout(cardShowTimer); cardShowTimer = null; }
        if (autoDismissTimer) { clearTimeout(autoDismissTimer); autoDismissTimer = null; }
    }

    // Kompatibilitas fungsi lama
    window.showLoader = function() { start('Memproses...', 'Mohon tunggu', true); };
    window.hideLoader = function() { done(); };

    return {
        start: start,
        done: done,
        setProgress: setProgress
    };
})();
window.snetLoader = snetLoader;

// ── Interseptor Otomatis: Klik Menu & Link Internal ─────────────────
document.addEventListener('click', function(e) {
    const link = e.target.closest('a[href]');
    if (!link) return;

    // Lewati jika tombol aksi khusus atau tab/modal
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    if (link.dataset.noLoader !== undefined) return;
    if (link.getAttribute('data-bs-toggle') || link.getAttribute('data-bs-target')) return;
    if (link.classList.contains('btn-quick-pay') || link.classList.contains('btn-quick-wifi') || link.classList.contains('btn-quick-wa') || link.classList.contains('btn-quick-portal')) return;

    const href = link.getAttribute('href');
    if (!href || href === '#' || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('tel:') || href.startsWith('mailto:')) return;

    // Pastikan link internal
    let url;
    try {
        url = new URL(link.href, window.location.origin);
    } catch (_) { return; }
    if (url.origin !== window.location.origin) return;

    // Lewati jika URL sama persis hanya beda hash
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

    // Tentukan pesan kontekstual
    let title = 'Memuat Halaman...';
    let subtitle = 'Menyiapkan data, mohon tunggu';

    const linkText = link.textContent.trim().replace(/\s+/g, ' ');
    if (link.closest('#sidebar')) {
        link.classList.add('is-navigating');
        title = linkText ? 'Membuka ' + linkText + '...' : 'Memuat Menu...';
    } else if (link.closest('.pagination')) {
        title = 'Memuat Halaman Data...';
    } else if (href.includes('export')) {
        title = 'Menyiapkan File Export...';
        subtitle = 'Sedang mengunduh file, mohon tunggu';
        setTimeout(() => snetLoader.done(), 4000);
    } else if (linkText.length > 0 && linkText.length < 30 && !linkText.includes('\n')) {
        title = linkText.includes('...') ? linkText : 'Membuka ' + linkText + '...';
    }

    snetLoader.start(title, subtitle, false);
});

// ── Interseptor Otomatis: Submit Formulir ────────────────────────────
document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (e.defaultPrevented) return;
    if (form.target === '_blank' || form.dataset.noLoader !== undefined) return;
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

    // Nonaktifkan tombol submit untuk mencegah dobel submit
    const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitBtn) {
        setTimeout(() => {
            submitBtn.disabled = true;
            submitBtn.classList.add('disabled');
            if (submitBtn.tagName === 'BUTTON') {
                submitBtn.dataset.origHtml = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Memproses...';
            }
        }, 10);
    }

    // Judul & Subtitle dinamis sesuai aksi formulir
    let title = 'Menyimpan Data...';
    let subtitle = 'Sedang diproses oleh server, jangan tutup halaman';

    const actStr = ((form.getAttribute('action') || '') + ' ' + (form.querySelector('[name="action"]')?.value || '')).toLowerCase();
    if (actStr.includes('wifi')) {
        title = 'Mengirim Pengaturan ke ONT...';
        subtitle = 'Konfigurasi Wi-Fi sedang dikirim ke modem pelanggan via TR-069';
    } else if (actStr.includes('pay') || actStr.includes('bayar')) {
        title = 'Mencatat Pembayaran...';
        subtitle = 'Menyimpan transaksi dan memperbarui status tagihan';
    } else if (actStr.includes('voucher') || actStr.includes('generate')) {
        title = 'Membuat Voucher...';
        subtitle = 'Membuat kode voucher dan sinkronisasi ke RADIUS';
    } else if (actStr.includes('delete') || actStr.includes('hapus')) {
        title = 'Menghapus Data...';
    } else if (actStr.includes('portal')) {
        title = 'Menyimpan Akun Portal...';
    } else if (actStr.includes('setting')) {
        title = 'Menyimpan Pengaturan...';
    }

    snetLoader.start(title, subtitle, true);
});

// ── Lifecycle Failsafes: Back/Forward Cache & Load ──────────────────
window.addEventListener('pageshow', function() {
    snetLoader.done();
});
window.addEventListener('load', function() {
    snetLoader.done();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        snetLoader.done();
    }
});

// ── Confirm delete ──────────────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    const msg = btn.dataset.confirm || 'Apakah Anda yakin?';
    if (!confirm(msg)) e.preventDefault();
});

// ── Router Status Polling ──────────────────────────────
function pollRouterStatus() {
    document.querySelectorAll('[data-router-id]').forEach(el => {
        const id = el.dataset.routerId;
        fetch(`/ajax/router_status.php?id=${id}`)
            .then(r => r.json())
            .then(data => {
                const badge = el.querySelector('.router-status-badge');
                const dot   = el.querySelector('.status-dot');
                if (badge) {
                    badge.className = data.online
                        ? 'badge bg-success router-status-badge'
                        : 'badge bg-danger router-status-badge';
                    badge.textContent = data.online ? 'Online' : 'Offline';
                }
                if (dot) {
                    dot.className = data.online
                        ? 'status-dot online'
                        : 'status-dot offline';
                }
                if (el.classList.contains('router-card')) {
                    el.classList.toggle('online', data.online);
                    el.classList.toggle('offline', !data.online);
                }
                const usersEl = el.querySelector('.router-users-count');
                if (usersEl && data.active_users !== undefined) {
                    usersEl.textContent = data.active_users;
                }
            })
            .catch(() => {});
    });
}

// Start polling if router status elements exist (Every 10 seconds)
if (document.querySelector('[data-router-id]')) {
    pollRouterStatus();
    setInterval(pollRouterStatus, 10000);
}

// Removed redundant refreshActiveUsers block that conflicted with pages/monitoring/active.php

// ── Disconnect User ────────────────────────────────────
function disconnectUser(username, sessionId, btn) {
    if (btn) btn.disabled = true;
    fetch('/process/disconnect_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `username=${encodeURIComponent(username)}&session_id=${encodeURIComponent(sessionId)}&csrf=${getCsrf()}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(`User ${username} berhasil di-disconnect.`, 'success');
            if (typeof refreshActiveUsers === 'function') refreshActiveUsers();
        } else {
            showToast(`Gagal disconnect: ${data.error}`, 'error');
            if (btn) btn.disabled = false;
        }
    })
    .catch(() => { if (btn) btn.disabled = false; });
}

// ── Test Router API ────────────────────────────────────
function testRouterApi(routerId, resultEl) {
    if (resultEl) {
        resultEl.textContent = 'Testing...';
        resultEl.className = 'router-status-badge badge bg-secondary';
    }
    fetch(`/ajax/test_api.php?id=${routerId}`)
        .then(r => r.json())
        .then(data => {
            if (resultEl) {
                if (data.success) {
                    resultEl.className = 'router-status-badge badge bg-success';
                    resultEl.innerHTML = `<i class="bi bi-check-circle"></i> Connected — ${escHtml(data.identity || '')}`;
                } else {
                    resultEl.className = 'router-status-badge badge bg-danger';
                    resultEl.innerHTML = `<i class="bi bi-x-circle"></i> Failed — ${escHtml(data.error || '')}`;
                }
            }
        })
        .catch(() => {
            if (resultEl) {
                resultEl.className = 'router-status-badge badge bg-danger';
                resultEl.innerHTML = `<i class="bi bi-x-circle"></i> Request failed`;
            }
        });
}

// ── Voucher Generate: Preview profile info ─────────────
const profileSelect = document.getElementById('profile_id');
const profileInfo   = document.getElementById('profile-info');
if (profileSelect && profileInfo) {
    profileSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt.value) { profileInfo.innerHTML = ''; return; }
        fetch(`/ajax/profile_info.php?id=${opt.value}`)
            .then(r => r.json())
            .then(d => {
                profileInfo.innerHTML = d.html || '';
            })
            .catch(() => {});
    });
    profileSelect.dispatchEvent(new Event('change'));
}

// ── Select All checkbox ────────────────────────────────
const selectAll = document.getElementById('select-all');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateBulkActions();
    });
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
}

function updateBulkActions() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    const bulkBar = document.getElementById('bulk-bar');
    const countEl = document.getElementById('selected-count');
    if (bulkBar) bulkBar.style.display = checked > 0 ? 'flex' : 'none';
    if (countEl) countEl.textContent = checked;
}

// ── CSRF Token ─────────────────────────────────────────
function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// ── Utility ────────────────────────────────────────────
function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Table search filter ────────────────────────────────
const tableSearch = document.getElementById('table-search');
if (tableSearch) {
    tableSearch.addEventListener('input', function() {
        const val = this.value.toLowerCase();
        document.querySelectorAll('#data-table tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
        });
    });
}

// ── Dismiss flash after 5s ─────────────────────────────
document.querySelectorAll('.alert.auto-dismiss').forEach(el => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

// ── Theme Toggle ───────────────────────────────────────
const themeToggles = document.querySelectorAll('.theme-toggle');
function applyThemeIcons(theme) {
    document.querySelectorAll('.theme-toggle').forEach(btn => {
        const sunIcon = btn.querySelector('.sun-icon');
        const moonIcon = btn.querySelector('.moon-icon');
        if (theme === 'dark') {
            if (sunIcon) sunIcon.classList.remove('d-none');
            if (moonIcon) moonIcon.classList.add('d-none');
            btn.setAttribute('title', 'Beralih ke Mode Terang');
        } else {
            if (sunIcon) sunIcon.classList.add('d-none');
            if (moonIcon) moonIcon.classList.remove('d-none');
            btn.setAttribute('title', 'Beralih ke Mode Gelap');
        }
    });
}

if (themeToggles.length > 0) {
    applyThemeIcons(document.documentElement.getAttribute('data-bs-theme') || 'light');
    themeToggles.forEach(btn => {
        btn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('snet-theme', newTheme);
            applyThemeIcons(newTheme);
        });
    });
}
