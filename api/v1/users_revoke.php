<?php
/**
 * POST v1/users/revoke — sales/shop integration: remove a group, or take back one order's time.
 * Requires the 'users' or the narrower 'shop' scope.
 *   Body: {"login"|"user_id"|"external_id": …, "group": "<slug>", "order_id": "<optional>"}
 *
 * ── two different questions ───────────────────────────────────────────────────────────────────
 * WITHOUT an order id this is the hard stop it has always been: the membership goes, now, whatever
 * paid for it. That is what an operator means by "remove this group".
 *
 * WITH one it is a REFUND of that order and nothing else, recomputed from `user_group_orders`. A
 * customer who bought three months in January and three more in March, and charges back only the
 * January payment, keeps the March one: the row remembers what the membership looked like before
 * that order, so the endpoint can put the membership back where that order found it — or, when
 * later orders have moved it since, subtract exactly the span this one added. A refunded order is
 * marked in the same row, so a shop retrying its refund webhook takes nothing away twice.
 */
requirePost();
$rawBody = apiReadRawBody();
$client = apiAuthenticate($db, $cfg, 'v1/users/revoke', $rawBody);
apiRequireScope($client, 'users', 'shop');
if (!usersEnabled($cfg)) jsonResponse(['ok' => false, 'error' => 'users_disabled'], 503);

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) jsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
$target = userApiTarget($db, $client, $payload);
if (isset($target['error'])) jsonResponse(['ok' => false, 'error' => $target['error']], (int)$target['status']);
$u = $target['user'];
$group = userGroupBySlug($db, trim((string)($payload['group'] ?? '')));
if (!$group) jsonResponse(['ok' => false, 'error' => 'group_not_found'], 404);

// The same two guards its siblings enforce, which this endpoint alone lacked.
//
// A shop's bearer token is not a stronger actor than a moderator with a session: it may move
// members between ORDINARY groups, and nothing more. Revoking a group that carries panel access is
// an administrative act (api/admin/user_revoke.php refuses it for a moderator), and stripping the
// admin group from the site owner is refused everywhere — this was the one door left open.
$gCarriesPanel = ($group['slug'] === 'admin');
foreach (array_keys(userGroupPermissions($group['permissions'] ?? '')) as $gp) {
    if (userIsPanelPermission($gp)) { $gCarriesPanel = true; break; }
}
if ($gCarriesPanel) {
    jsonResponse(['ok' => false, 'error' => 'group_not_revocable',
                  'detail' => 'A group that carries panel access can only be changed by the site owner.'], 403);
}
if ($group['slug'] === 'admin' && userIsRootAdmin($u, $cfg)) {
    jsonResponse(['ok' => false, 'error' => 'owner_protected',
                  'detail' => 'The site owner cannot lose the admin group.'], 403);
}

$uid = (int)$u['id'];
$gid = (int)$group['id'];
$orderId = trim((string)($payload['order_id'] ?? ''));

if ($orderId === '') {
    // The hard stop.
    $removed = userRevokeGroup($db, $uid, $gid);
    auditNote(['target_type' => 'user', 'target_id' => (string)$uid,
               'summary' => 'revoked "' . $group['slug'] . '"' . ($removed ? '' : ' (no membership)'),
               'detail' => ['group' => $group['slug'], 'removed' => $removed]]);
    jsonResponse(['ok' => true, 'removed' => $removed, 'expires_at' => null, 'server_time' => time()]);
}

if (!userValidOrderId($orderId)) {
    jsonResponse(['ok' => false, 'error' => 'invalid_order_id'], 422);
}
$order = userOrderFind($db, (int)$client['id'], $orderId);
if ($order === null) jsonResponse(['ok' => false, 'error' => 'order_not_found'], 404);
if ((int)$order['user_id'] !== $uid || (int)$order['group_id'] !== $gid) {
    // The order is real but names somebody else. Refusing is the only safe answer: acting on the
    // order's own target would let a typo in the login field refund the wrong customer, and acting
    // on the named one would take back time that order never gave.
    jsonResponse(['ok' => false, 'error' => 'order_mismatch',
                  'detail' => 'That order belongs to a different account or group.'], 409);
}
if ((string)$order['action'] !== 'grant') {
    jsonResponse(['ok' => true, 'removed' => false, 'replayed' => true, 'order_id' => $orderId,
                  'order_state' => $order['action'], 'expires_at' => null, 'server_time' => time()]);
}

$cur = userMembership($db, $uid, $gid);
$removed = false;
$restoredTo = null;
$adjusted = false;
if ($cur !== null) {
    $orderNew = $order['new_expires_at'];
    if ((string)$cur['expires_at'] === (string)$orderNew) {
        // Nothing has touched the membership since this order: put it back exactly.
        if (empty($order['prev_member'])) {
            $removed = userRevokeGroup($db, $uid, $gid);
        } else {
            $db->prepare("UPDATE user_group_members SET expires_at = ?, warned_at = NULL WHERE user_id = ? AND group_id = ?")
               ->execute([$order['prev_expires_at'], $uid, $gid]);
            $restoredTo = $order['prev_expires_at'];
            $adjusted = true;
        }
    } elseif ($orderNew !== null && $cur['expires_at'] !== null) {
        // Later orders have moved the expiry. Take back this order's SPAN — from where it started
        // (the expiry it found, or the moment it was made when it found nothing) to where it put it.
        $from = (!empty($order['prev_member']) && $order['prev_expires_at'] !== null)
              ? strtotime((string)$order['prev_expires_at'])
              : strtotime((string)$order['created_at']);
        $span = max(0, strtotime((string)$orderNew) - $from);
        $left = strtotime((string)$cur['expires_at']) - $span;
        if ($left <= time()) {
            $removed = userRevokeGroup($db, $uid, $gid);
        } else {
            $restoredTo = date('Y-m-d H:i:s', $left);
            $db->prepare("UPDATE user_group_members SET expires_at = ?, warned_at = NULL WHERE user_id = ? AND group_id = ?")
               ->execute([$restoredTo, $uid, $gid]);
            $adjusted = true;
        }
    }
    // The remaining case — this order granted forever, or somebody has since made the membership
    // permanent by hand — is left alone on purpose: subtracting a span from "no end" has no answer,
    // and an operator's own permanent grant is not a shop's to withdraw. The order is still marked
    // refunded, so the shop is not told to try again for ever.
}
$db->prepare("UPDATE user_group_orders SET action = 'revoked' WHERE client_id = ? AND order_id = ?")
   ->execute([(int)$client['id'], $orderId]);

auditNote(['target_type' => 'user', 'target_id' => (string)$uid,
           'summary' => 'refunded order ' . $orderId . ' of "' . $group['slug'] . '"'
                      . ($removed ? ' (membership removed)' : ($restoredTo !== null ? ' (back to ' . $restoredTo . ')' : ' (membership left as it is)')),
           'detail' => ['group' => $group['slug'], 'order_id' => $orderId, 'removed' => $removed,
                        'expires_at' => $restoredTo, 'adjusted' => $adjusted]]);
jsonResponse(['ok' => true, 'removed' => $removed, 'adjusted' => $adjusted, 'expires_at' => $restoredTo,
              'order_id' => $orderId, 'order_state' => 'revoked', 'replayed' => false, 'server_time' => time()]);
