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

// ── Page Loader ────────────────────────────────────────
const loader = document.getElementById('page-loader');
function showLoader()  { if (loader) loader.classList.add('active'); }
function hideLoader()  { if (loader) loader.classList.remove('active'); }

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
        const darkIcon = btn.querySelector('.dark-icon');
        const lightIcon = btn.querySelector('.light-icon');
        if (darkIcon && lightIcon) {
            if (theme === 'dark') {
                darkIcon.classList.remove('d-none');
                lightIcon.classList.add('d-none');
            } else {
                darkIcon.classList.add('d-none');
                lightIcon.classList.remove('d-none');
            }
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
