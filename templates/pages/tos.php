<h1>Terms of Service</h1>
<p>By using this tracker, you agree to the following terms:</p>

<ol class="tos-list">
    <li>The service is free for personal use. Commercial organizations require written permission.</li>
    <li>User tracking, DoS/DDoS attacks, and any attempts to disrupt the service are prohibited.</li>
    <li>We do not guarantee service uptime. Availability may be limited without prior notice.</li>
    <li>The user bears full responsibility for the legality of shared content in their jurisdiction.</li>
    <li>Commercial use policy violations are subject to a fee of EUR 5,000.</li>
    <li>We reserve the right to publish information about policy violations.</li>
    <li>We respect user privacy. We do not store personal data beyond what is necessary for tracker operation.</li>
<?php if (trackerMode($cfg) === 'whitelist'): ?>
    <li>Whitelist registrations are free and anonymous; the registrant's IP address is stored to detect abuse. Registered info hashes may be removed or banned at any time, and abusive registrants may be banned.</li>
<?php endif; ?>
    <li>Terms may change. Continued use of the service constitutes acceptance of changes.</li>
    <li><strong>Connecting to the tracker constitutes acceptance of these terms.</strong></li>
</ol>

<?php if (usersEnabled($cfg)): ?>
<h2>User accounts</h2>
<ol class="tos-list">
    <li>Creating an account is free and optional — the tracker itself works without one. An account only unlocks member features (such as the catalogue search) according to the groups granted to it.</li>
    <li>For an account we store: the username, the password (as a salted hash — never in plain text), the email address<?= userEmailVerifyRequired($cfg) ? ' (required and confirmed by a verification link)' : ' (optional)' ?>, the IP addresses used at registration and sign-in (abuse prevention), group memberships with their expiry dates, and in-app notifications.</li>
    <li>Emails are used solely for account operation: verification links, password resets, email-change confirmations and expiry/security notices. Account notices can be disabled on the account page; we never share addresses with third parties.</li>
    <li>Changing the account email requires confirmation from the current address and then from the new one; a cool-down period applies between changes. This protects accounts from hijacking.</li>
    <li>Session cookies (and the optional "stay signed in" token) are strictly functional. The sign-in duration is chosen at login; tokens are stored only as hashes and are invalidated by a password change or sign-out.</li>
    <li>Accounts used for abuse (spam, attacks on the service, deliberately registering infringing content after warnings) may be suspended or deleted, together with their whitelist registrations.</li>
    <li>To have your account and its data removed, contact the site email from your account address (or use the report form). Read notifications are pruned automatically after 90 days.</li>
</ol>
<?php endif; ?>
