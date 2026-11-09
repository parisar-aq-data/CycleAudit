<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/permissions.php';

// Only admins define roads. A surveyor opens this page for an assigned road
// (?road_id=) or when returning from a submitted audit (?status=done).
$canDefineRoad = isAnyAdmin($CURRENT_USER_ROLE);
if (!$canDefineRoad && empty($_GET['road_id']) && ($_GET['status'] ?? '') !== 'done') {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="can-define-road" content="<?= $canDefineRoad ? '1' : '0' ?>">
  <title>Road Setup — CycleAudit</title>
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/segment.css?v=<?= filemtime(__DIR__ . '/../css/segment.css') ?>">
  <link nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" href="../css/segment-dropdown.css?v=<?= filemtime(__DIR__ . '/../css/segment-dropdown.css') ?>">
</head>
<body>

<header>
  <div class="header-logo">
    <div class="logo-dot">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
    </div>
    Road Audit
  </div>
  <div class="step-indicator" id="stepIndicator">
    <div class="step active" id="step1"><div class="step-num">1</div><span>Road Info</span></div>
    <span class="step-sep">›</span>
    <div class="step" id="step2"><div class="step-num">2</div><span>Segments</span></div>
    <span class="step-sep">›</span>
    <div class="step" id="step3"><div class="step-num">3</div><span>Audit</span></div>
  </div>
</header>

<div class="toast" id="toast"></div>
<div class="container">
  <a href="dashboard.php" id="backToDashboard" class="btn btn-secondary btn-sm" style="display:inline-block;text-decoration:none;margin:4px 0 14px">← Back to dashboard</a>

  <!-- ── SECTION 1: Road Setup ── -->
  <div id="roadSetupSection" style="display:none">
    <div class="page-title">Define Road</div>
    <div class="page-subtitle">Set up the road details before adding audit segments.</div>

    <div class="card">
      <div class="card-title">
        <div class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg></div>
        Road Information
      </div>

      <div class="form-group">
        <label class="required">Road Name</label>

        <!-- Hidden real value holder (read by segment.js via #roadName) -->
        <select id="roadName" style="display:none" aria-hidden="true">
          <option value="">— Select a Road —</option>
          <option>BANER ROAD</option>
          <option>BIBVEWADI ROAD</option>
          <option>DECCAN COLLEGE ROAD</option>
          <option>DP ROAD</option>
          <option>F.C. ROAD</option>
          <option>GANGADHAM ROAD</option>
          <option>J.M. ROAD</option>
          <option>KARVE ROAD</option>
          <option>KHADKI ROAD</option>
          <option>PASHAN ROAD</option>
          <option>PASHAN SUS ROAD</option>
          <option>PMC ROAD</option>
          <option>PRATHAMESH PARK ROAD</option>
          <option>SANGAMWADI ROAD</option>
          <option>S.B. ROAD</option>
          <option>SINHAGAD ROAD</option>
          <option>SPICER COLLEGE ROAD</option>
          <option>SWAMI VIVEKANAD ROAD</option>
          <?php if (isAnyAdmin($CURRENT_USER_ROLE)): ?>
          <option value="__custom__">Other / Custom Road</option>
          <?php endif; ?>
        </select>

        <!-- Visible searchable UI -->
        <div class="road-search-wrap" id="roadSearchWrap">
          <input type="text" id="roadSearchInput" class="road-search-input"
                 placeholder="Search or type a road name…"
                 autocomplete="off"
                 data-segment-action="road-search">
          <div class="road-dropdown" id="roadDropdown"></div>
        </div>

        <div class="field-error" id="err-roadName">Please select or enter a road name.</div>

        <?php if (isAnyAdmin($CURRENT_USER_ROLE)): ?>
        <!-- Custom road entry (shown when Other is chosen) — admin only -->
        <div class="custom-road-wrap" id="customRoadWrap">
          <div class="form-group">
            <input type="text" id="customRoadName"
                   placeholder="Type the full road name (e.g. MODEL COLONY ROAD)"
                   maxlength="200" data-segment-action="validate-custom-road"
                   autocomplete="off" style="text-transform:uppercase">
            <div class="road-hint" id="customRoadHint">Min 3 characters. Use official road name if possible.</div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="required">Total Road Length (meters)</label>
        <input type="text" inputmode="decimal" id="roadLength" placeholder="e.g. 1500" data-segment-action="sanitize-numeric">
        <div class="input-hint">Minimum 50 m. Used to calculate segment distribution.</div>
        <div class="field-error" id="err-roadLength">Enter a valid length of at least 50 m.</div>
      </div>
      <div class="input-hint" style="margin-top:-4px">
        Start/End landmark &amp; GPS are captured automatically from Segment 1's start and the last segment's end during the audit.
      </div>
    </div>

    <!-- Segmentation Method -->
    <div class="card">
      <div class="card-title">
        <div class="icon"><svg viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg></div>
        Segmentation Method
      </div>

      <div class="method-desc" style="margin-bottom:12px">Divide into equal segments by standard length</div>

      <div id="autoContent" class="method-content active">
        <div class="form-group">
          <label>Standard Segment Length</label>
          <select id="segmentLength">
            <option value="100">100 meters</option>
            <option value="200">200 meters</option>
            <option value="300">300 meters</option>
            <option value="500" selected>500 meters</option>
            <option value="custom">Custom length…</option>
          </select>
        </div>
        <div id="customLengthInput" style="display:none">
          <div class="form-group">
            <label>Custom Length (meters)</label>
            <input type="text" inputmode="decimal" id="customSegmentLength" placeholder="e.g. 150" data-segment-action="sanitize-numeric">
          </div>
        </div>
        <div class="preview-box" id="autoPreview">
          <div class="preview-row">
            <span>Estimated segments</span>
            <strong id="previewCount">—</strong>
          </div>
          <div class="preview-row">
            <span>Each segment</span>
            <strong id="previewLength">—</strong>
          </div>
          <div class="preview-row">
            <span>Last segment</span>
            <strong id="previewLast">—</strong>
          </div>
        </div>
        <div class="btn-row" style="margin-top:16px">
          <button class="btn btn-primary" data-segment-action="generate-segments">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Generate &amp; Save Segments
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── SECTION 2: Segments View ── -->
  <div id="segmentsSection" style="display:none">
    <div class="road-header">
      <div>
        <div class="page-title" id="roadNameDisplay"></div>
        <div class="page-subtitle" id="roadRouteDisplay"></div>
      </div>
      <div class="btn-row">
        <button class="btn btn-secondary btn-sm" id="editRoadBtn" data-segment-action="edit-road">✏️ Edit Road</button>
      </div>
    </div>

    <div class="road-pills" id="roadPills"></div>

    <div class="progress-section">
      <div class="progress-header">
        <span id="progressLabel">Loading…</span>
        <span id="progressPercent">0%</span>
      </div>
      <div class="progress-bar-wrap">
        <div class="progress-bar" id="progressBar" style="width:0%"></div>
      </div>
    </div>

    <div id="completionBanner" class="banner banner-success" style="display:none">
      <span id="completionTitle"></span>
    </div>
    <div id="lockedBanner" class="banner banner-locked" style="display:none">
      <span>🔒 This audit has been finalized and can no longer be edited.</span>
    </div>
    <div id="blockedBanner" class="banner banner-warn" style="display:none">
      <span id="blockedText"></span>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="icon"><svg viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg></div>
        Segments
      </div>
      <div id="segmentsList"></div>
    </div>

    <div class="btn-row" id="bottomActions" style="justify-content:center;margin-top:24px;gap:14px;flex-wrap:wrap">
      <button class="btn btn-secondary btn-lg" id="backToDashboardBtn" data-segment-action="go-dashboard" style="display:none">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back to Dashboard
      </button>
      <button class="btn btn-primary btn-lg" id="dlPdfBtn" data-segment-action="download-score" style="display:none">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download Road Score PDF
      </button>
      <button class="btn btn-primary btn-lg" id="finalSubmitBtn" data-segment-action="finalize-road" style="display:none">✅ Final Submit</button>
    </div>
  </div>

</div>

<!-- Edit Road Modal -->
<div id="editModal" class="modal-overlay" data-segment-action="modal-backdrop">
  <div class="modal-box">
    <div class="modal-icon">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div class="modal-heading">Edit Road Information?</div>
    <div class="modal-body" id="editModalBody"></div>
    <div class="modal-warning" id="editModalWarning"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" data-segment-action="close-edit-modal">Cancel</button>
      <button class="btn btn-primary" data-segment-action="confirm-edit-road">Yes, Edit Road</button>
    </div>
  </div>
</div>

<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  window.IS_ADMIN = <?= isAnyAdmin($CURRENT_USER_ROLE) ? 'true' : 'false' ?>;
</script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/segment.js?v=<?= filemtime(__DIR__ . '/../js/segment.js') ?>"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/segment-roads.js?v=<?= filemtime(__DIR__ . '/../js/segment-roads.js') ?>"></script>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/segment-handlers.js?v=<?= filemtime(__DIR__ . '/../js/segment-handlers.js') ?>"></script>

<?php if (!$canDefineRoad && (int)($_GET['road_id'] ?? 0) > 0): ?>
<script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>" src="../js/project_watch.js?v=<?= filemtime(__DIR__ . '/../js/project_watch.js') ?>" data-road-id="<?= (int)$_GET['road_id'] ?>" data-home="dashboard.php?notice=project_deleted"></script>
<?php endif; ?>
</body>
</html>