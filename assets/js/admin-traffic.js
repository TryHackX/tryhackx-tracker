/**
 * The Traffic page's own wiring — everything the two cards on it do NOT do themselves.
 *
 * The swarm timeline (stats-timeline.js) and the UDP traffic card (admin-netlimit.js) are both
 * self-contained: each finds its own mount point and does nothing when it is absent. What neither
 * of them owns is the page furniture, and Logout is the whole of it — every other admin page binds
 * that button from its own page script, so without this the button would be decoration.
 */
(function () {
    'use strict';
    if (typeof window.AdminCommon === 'undefined') return;
    const { apiCall } = window.AdminCommon;

    const logout = document.getElementById('btn-logout');
    if (logout) {
        logout.addEventListener('click', async () => {
            await apiCall('admin/logout', 'POST', {});
            const base = (document.body.dataset.apiBase || '').replace('api.php?endpoint=', '');
            window.location.href = base + '?action=' + (document.body.dataset.loginPath || 'admin');
        });
    }
})();
