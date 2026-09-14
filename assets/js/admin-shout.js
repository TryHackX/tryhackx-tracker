/**
 * Settings → Shoutbox: the one control on that card that is not a setting.
 *
 * Everything else in the section is an ordinary field saved with the form. "Purge" deletes rows,
 * and rows deleted here are not coming back from a settings backup — so it asks twice: once for the
 * decision (confirmAction, with the number of days spelled out) and once for the owner's password,
 * which is what api/admin/shout_purge.php checks with requireAdminReauth().
 *
 * The day box has an id and no name on purpose: it is an argument to this button, never a setting,
 * and a `name` would send it along with every save of the page.
 */
(function () {
    'use strict';
    const root = document.getElementById('admin-shout');
    if (!root || !window.AdminCommon || typeof window.t !== 'function') return;
    const { apiCall, showToast, confirmAction, promptPassword } = window.AdminCommon;
    const t = window.t;
    const daysIn = document.getElementById('shout-purge-days');
    const btn = document.getElementById('shout-purge-run');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const raw = (daysIn && daysIn.value || '').trim();
        // Empty means EVERYTHING, and that is the reading the confirmation has to say out loud —
        // an empty number box is exactly how somebody wipes a room by accident.
        const days = raw === '' ? null : Math.max(0, Math.min(3650, parseInt(raw, 10) || 0));
        const title = t('js.shoutadmin.purge_title');
        const what = days === null ? t('js.shoutadmin.purge_all') : t('js.shoutadmin.purge_older', { days: days });
        if (!await confirmAction(title, what, { okLabel: t('js.shoutadmin.purge_ok'), danger: true })) return;
        const pw = await promptPassword(title, t('js.shoutadmin.purge_password'));
        if (!pw) return;
        btn.disabled = true;
        try {
            const r = await apiCall('admin/shout_purge', 'POST', { password: pw, older_than_days: days });
            showToast(r.success ? (r.message || t('js.shoutadmin.purge_done', { n: r.deleted || 0 }))
                                : (r.error || t('js.shoutadmin.purge_failed')), r.success ? 'success' : 'error');
            if (r.success && daysIn) daysIn.value = '';
        } catch {
            showToast(t('js.shoutadmin.purge_failed'), 'error');
        } finally {
            btn.disabled = false;
        }
    });
})();
