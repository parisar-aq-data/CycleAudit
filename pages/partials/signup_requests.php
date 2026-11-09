<?php
// "Sign-up requests" panel for the City Leader and Admin dashboards (security review H1).
// People who sign up themselves cannot sign in until someone approves them here.
// Expects: $pdo, $CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID. Renders nothing when nobody is waiting,
// and never breaks the dashboard if migration 032 has not been run yet.
require_once __DIR__ . '/../../helpers/Registration.php';

$suRows = [];
try {
    $suRows = signupRequestsOpenFor($pdo, (string)$CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
} catch (Throwable $e) {
    error_log('signup_requests partial: ' . $e->getMessage());
}
if ($suRows):
    $suH     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $suNonce = $suH($_SESSION['csp_nonce'] ?? '');
    $suAdmin = $CURRENT_USER_ROLE === 'admin';
?>
<section class="su-panel" id="suPanel" data-csrf="<?= $suH($_SESSION['csrf_token'] ?? '') ?>" aria-labelledby="suTitle">
  <h3 id="suTitle">Sign-up requests <span class="su-count" id="suCount"><?= count($suRows) ?></span></h3>
  <p class="su-help">
    These people signed up themselves and <b>cannot sign in until you approve them</b>.
    Approve only people you know or whom your organisation invited. Do not rely on the phone number
    they typed: check the name and organisation with someone you trust. Reject anyone you do not recognise.
  </p>
  <ul class="su-list">
    <?php foreach ($suRows as $r): ?>
    <li class="su-item" data-id="<?= (int)$r['id'] ?>">
      <div class="su-who">
        <b><?= $suH($r['name']) ?></b>
        <small><?= $suH($r['email']) ?></small>
        <small>
          <?= trim((string)($r['organisation'] ?? '')) !== '' ? $suH($r['organisation']) : 'No organisation given' ?>
          · said: <?= $suH(trim(implode(', ', array_filter([(string)($r['home_city'] ?? ''), (string)($r['home_state'] ?? '')])))) ?: 'no city given' ?>
          <?php if ($suAdmin): ?> · placed in: <?= $suH($r['city_name'] ?? 'no city yet') ?><?php endif; ?>
          · signed up <?= $suH(date('j M Y', (int)strtotime((string)$r['created_at']))) ?>
        </small>
        <small>
          <?php if (trim((string)($r['phone'] ?? '')) !== ''): ?>
            Phone they gave: <a href="tel:<?= $suH(preg_replace('/[^0-9+]/', '', (string)$r['phone'])) ?>"><?= $suH($r['phone']) ?></a>
          <?php else: ?>
            No phone number given.
          <?php endif; ?>
        </small>
      </div>
      <div class="su-act">
        <button type="button" class="su-approve">Approve</button>
        <button type="button" class="su-reject">Reject</button>
      </div>
      <p class="su-msg" role="status" aria-live="polite"></p>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<style nonce="<?= $suNonce ?>">
.su-panel{background:#fff;border:1px solid #e3ded0;border-left:4px solid #2f6f9f;border-radius:12px;padding:16px 18px;margin:0 0 16px}
.su-panel h3{margin:0 0 6px;font-size:1.05rem;display:flex;align-items:center;gap:8px}
.su-count{padding:2px 9px;border-radius:99px;background:#2f6f9f;color:#fff;font-size:.72rem;font-weight:700}
.su-help{margin:0 0 12px;font-size:.88rem;line-height:1.5;color:#4b5a3f}
.su-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.su-item{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px;background:#faf8f1;border:1px solid #e3ded0;border-radius:10px}
.su-who{display:flex;flex-direction:column;gap:2px;min-width:200px;flex:1}
.su-who small{color:#4b5a3f;font-size:.8rem}
.su-act{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.su-approve,.su-reject{height:38px;padding:0 14px;border-radius:8px;font:inherit;font-weight:600;cursor:pointer}
.su-approve{background:#3d8a24;color:#fff;border:0}
.su-reject{background:#fff;color:#b42318;border:1px solid #e0b4ae}
.su-approve:disabled,.su-reject:disabled{opacity:.55;cursor:default}
.su-msg{flex-basis:100%;margin:0;font-size:.85rem;min-height:0}
.su-msg.err{color:#b42318}
.su-msg.ok{color:#1f4d14;font-weight:600}
.su-item.done{opacity:.8}
</style>
<script nonce="<?= $suNonce ?>" src="../js/signup_requests.js?v=<?= filemtime(__DIR__ . '/../../js/signup_requests.js') ?>"></script>
<?php endif; ?>
