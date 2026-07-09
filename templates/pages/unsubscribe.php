<?php
$email = $_GET['email'] ?? '';
$token = $_GET['token'] ?? '';
$valid = false;
$prefs = [];

if ($email && $token) {
    $hmacSecret = $cfg['hmac_secret'] ?? '';
    if (verifyUnsubscribeToken($email, $token, $hmacSecret)) {
        $valid = true;

        // Load current preferences
        $types = ['submission', 'review', 'status', 'custom', 'appeal'];
        foreach ($types as $t) {
            $prefs[$t] = true; // default: enabled
        }

        // Check legacy full-unsubscribe
        $isLegacy = false;
        $stmt = $db->prepare("SELECT COUNT(*) FROM unsubscribed_emails WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $isLegacy = true;
            foreach ($types as $t) $prefs[$t] = false;
        }

        // Check per-type preferences (overrides legacy)
        $stmt = $db->prepare("SELECT type, enabled FROM email_preferences WHERE email = ?");
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            foreach ($rows as $r) {
                if (in_array($r['type'], $types, true)) {
                    $prefs[$r['type']] = (bool)(int)$r['enabled'];
                }
            }
        }
    }
}
?>

<?php if (!$valid): ?>
<div class="unsub-msg">
    <h1>Notification Preferences</h1>
    <div class="alert show alert-error">Invalid or expired unsubscribe link.</div>
</div>
<?php else: ?>
<div class="unsub-page">
    <h1>Notification Preferences</h1>
    <p class="unsub-desc">Manage which email notifications you receive for <strong><?= sanitize($email) ?></strong></p>

    <div id="unsub-alert" class="alert"></div>

    <div class="unsub-prefs">
        <div class="unsub-pref-item unsub-pref-master">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">All Notifications</span>
                <span class="unsub-pref-hint">Master switch — turns all notifications on or off</span>
            </div>
            <label class="toggle">
                <input type="checkbox" id="pref-all" <?= (array_filter($prefs) === $prefs && count(array_filter($prefs)) === count($prefs)) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="unsub-pref-divider"></div>

        <div class="unsub-pref-item">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">Submission Confirmations</span>
                <span class="unsub-pref-hint">Confirmation email after submitting a new report</span>
            </div>
            <label class="toggle">
                <input type="checkbox" data-pref="submission" <?= $prefs['submission'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="unsub-pref-item">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">Under Review</span>
                <span class="unsub-pref-hint">Notification when an admin starts reviewing your report</span>
            </div>
            <label class="toggle">
                <input type="checkbox" data-pref="review" <?= $prefs['review'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="unsub-pref-item">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">Status Updates</span>
                <span class="unsub-pref-hint">Notifications when your report status changes (reviewed, blocked, archived)</span>
            </div>
            <label class="toggle">
                <input type="checkbox" data-pref="status" <?= $prefs['status'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="unsub-pref-item">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">Admin Messages</span>
                <span class="unsub-pref-hint">Custom messages sent by the admin regarding your report</span>
            </div>
            <label class="toggle">
                <input type="checkbox" data-pref="custom" <?= $prefs['custom'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="unsub-pref-item">
            <div class="unsub-pref-info">
                <span class="unsub-pref-label">Appeal Notifications</span>
                <span class="unsub-pref-hint">Confirmation and decision emails for appeals you submit</span>
            </div>
            <label class="toggle">
                <input type="checkbox" data-pref="appeal" <?= $prefs['appeal'] ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <div class="form-center mt-1">
        <button type="button" class="btn" id="unsub-save">Save Preferences</button>
    </div>
</div>

<script>
(function() {
    const masterToggle = document.getElementById('pref-all');
    const typeToggles = document.querySelectorAll('[data-pref]');
    const saveBtn = document.getElementById('unsub-save');
    const alert = document.getElementById('unsub-alert');

    function syncMaster() {
        const all = [...typeToggles].every(cb => cb.checked);
        masterToggle.checked = all;
    }

    masterToggle.addEventListener('change', () => {
        typeToggles.forEach(cb => cb.checked = masterToggle.checked);
    });

    typeToggles.forEach(cb => cb.addEventListener('change', syncMaster));

    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        const preferences = {};
        typeToggles.forEach(cb => {
            preferences[cb.dataset.pref] = cb.checked;
        });

        try {
            const res = await fetch(APP_API + 'save_email_preferences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: <?= json_encode($email) ?>,
                    token: <?= json_encode($token) ?>,
                    preferences: preferences
                })
            });
            const json = await res.json();
            if (json.success) {
                alert.className = 'alert alert-success show';
                alert.textContent = 'Preferences saved successfully.';
            } else {
                alert.className = 'alert alert-error show';
                alert.textContent = json.error || 'Failed to save preferences.';
            }
        } catch {
            alert.className = 'alert alert-error show';
            alert.textContent = 'Network error. Please try again.';
        }

        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Preferences';
        setTimeout(() => { alert.className = 'alert'; }, 5000);
    });
})();
</script>
<?php endif; ?>
