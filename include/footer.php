<?php
/**
 * S.NET RADIUS Manager — Page Footer
 * Include at bottom of every authenticated page.
 */
?>
</main><!-- /#main-content -->

<!-- Toast Container -->
<div id="toast-container"></div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="/assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<!-- Active Users Badge polling (Realtime 5s) -->
<script>
(function updateActiveBadge() {
    const isDashboard = window.location.search.includes('page=dashboard') || (window.location.search === '' && window.location.pathname.endsWith('index.php'));
    if (!isDashboard) {
        fetch('/ajax/active_users.php?count=1&_t=' + Date.now())
            .then(r => r.json())
            .then(d => {
                const badge = document.getElementById('active-users-badge');
                if (!badge) return;
                if (d.count > 0) {
                    badge.textContent = d.count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }).catch(() => {});
    }
    setTimeout(updateActiveBadge, 5000);
})();
</script>
</body>
</html>
