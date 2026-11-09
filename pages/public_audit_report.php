<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  pages/public_audit_report.php?id=N[&download=1]
//  The detailed audit report for a PUBLISHED audit, open to anyone
//  (no login). It is the same report the City Leader prints and the
//  Admin approved: summary, scores, observations, critical issues,
//  recommendations and segment cards.
//
//  · Only audits with status "published" are shown. Anything else
//    (draft, in review, closed, voided, unknown id) gets the same
//    plain "not found" page, so nobody can probe for unpublished work.
//  · The names of the people who prepared the audit and who surveyed
//    each segment are left out. The report does name the audit's City
//    Leader and their organisation: they are the accountable lead.
//  · "download=1" opens the print dialog on load; choose "Save as PDF".
//  · Same per-IP request limit as the other public endpoints.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../helpers/CityDashboard.php';
require_once __DIR__ . '/../helpers/CityDetailedReport.php';
require_once __DIR__ . '/../repositories/CityAuditRepository.php';
require_once __DIR__ . '/../repositories/PublicAuditRepository.php';
require_once __DIR__ . '/../repositories/AuditReportRepository.php';
require_once __DIR__ . '/../services/ScoreService.php';

$nonceRaw = base64_encode(random_bytes(16));
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $nonceRaw . "'; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data:; connect-src 'self';"
);
header('Cache-Control: no-cache');

/** A small, plain "not available" page. */
function publicReportNotFound(int $status, string $message): never
{
    http_response_code($status);
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Report not available — CycleAudit</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f7f4ee;color:#181f10;display:flex;'
       . 'min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}'
       . 'a{color:#3d7a1f;font-weight:600}</style></head><body><div><h1>' . $m . '</h1>'
       . '<p><a href="../index.html#audits">← Back to the audit data</a></p></div></body></html>';
    exit;
}

$rl = checkAndRecordApiRequest($pdo, getClientIp(), 'public_report', 30, 60);
if (!$rl['allowed']) {
    header('Retry-After: ' . $rl['retry_after']);
    publicReportNotFound(429, 'Too many requests. Please try again in a minute.');
}

$audit = (new CityAuditRepository($pdo))->find((int)($_GET['id'] ?? 0));
if ($audit === null || ($audit['status'] ?? '') !== 'published' || (new PublicAuditRepository($pdo))->isHidden((int)$audit['id'])) {
    publicReportNotFound(404, 'This report is not available.');
}

$isPublic  = true;
$backUrl   = '../index.html#audits';
$backLabel = '← Back to audit data';

// Opened from the admin panel's Published Audits page ("View report" adds from=admin):
// send a signed-in admin or city leader back there instead of the public home page.
// The flag is honoured only for a visitor who already holds a signed-in admin session,
// and no session is created for anonymous visitors.
if (($_GET['from'] ?? '') === 'admin' && isset($_COOKIE[SESSION_NAME])) {
    startSecureSession();
    $viewerRole = (string)($_SESSION['user_role'] ?? '');
    if (!empty($_SESSION['user_id']) && ($viewerRole === 'admin' || $viewerRole === 'city_leader')) {
        $backUrl   = 'published_audits.php';
        $backLabel = '← Back to Published Audits';
    }
    session_write_close();
}
$autoPrint = isset($_GET['download']) && $_GET['download'] === '1';
$nonce     = htmlspecialchars($nonceRaw, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/partials/city_detailed_report.php';
