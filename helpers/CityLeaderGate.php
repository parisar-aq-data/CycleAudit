<?php
declare(strict_types=1);

// ===============================================================
//  helpers/CityLeaderGate.php
//  City Leaders (role city_leader) may only open the screens listed
//  here. Everything else -- pages and APIs -- is closed to them.
//  config/auth_guard.php enforces this on every protected request.
//  Pure functions (no DB, no session) so they are unit-tested.
//
//  As new City Leader screens are built, add their paths below.
// ===============================================================

const CITY_LEADER_ALLOWED_SCRIPTS = [
    'pages/city_dashboard.php',
    'pages/city_audit.php',
    'api/city/audit_road_add.php',
    'api/city/audit_road_remove.php',
    'api/city/audit_segment_remove.php',
    'api/city/audit_segment_add.php',
    'api/city/audit_assign.php',
    'api/city/audit_activate.php',
    'api/city/audit_review.php',
    'api/city/audit_close.php',
    'pages/city_segment_review.php',
    'pages/city_audit_report.php',
    'pages/city_audit_report_detail.php',
    'pages/profile.php',
    'api/user/profile.php',
    // The notifications panel on their dashboard: mark one / all as read.
    'api/user/notifications.php',
    // Roads: the City Leader keeps their city's road list (the roads an
    // audit can use), so this page and its APIs are theirs, not the Admin's.
    'pages/admin.php',
    'api/admin/roads.php',
    'api/admin/export-roads.php',
    // Approve / dismiss a surveyor's "forgot password" request (panel on their dashboard).
    'api/admin/password_requests.php',
    // Approve / reject people who signed up themselves (panel on their dashboard).
    'api/admin/signup_requests.php',
    // Choose which surveyors are in their own team (panel on their dashboard).
    'api/admin/team.php',
    'pages/city_team.php',
    // Their finished (published) audits, listed from the sidebar.
    'pages/city_completed.php',
    'api/reports/export-city-excel.php',
];

function cityLeaderNormalize(string $scriptName): string
{
    return '/' . ltrim(str_replace('\\', '/', $scriptName), '/');
}

/** True when a City Leader may open this script (e.g. "/pages/city_dashboard.php"). */
function cityLeaderMayAccess(string $scriptName): bool
{
    $path = cityLeaderNormalize($scriptName);
    foreach (CITY_LEADER_ALLOWED_SCRIPTS as $allowed) {
        if (str_ends_with($path, '/' . $allowed)) {
            return true;
        }
    }
    return false;
}

/**
 * Where to send a City Leader who opened a closed PAGE, or null when the
 * request should get a 403 JSON response instead (APIs and unknown paths).
 */
function cityLeaderRedirectTarget(string $scriptName): ?string
{
    $path = cityLeaderNormalize($scriptName);
    if (str_contains($path, '/api/')) {
        return null;
    }
    $pos = strrpos($path, '/pages/');
    if ($pos === false) {
        return null;
    }
    return substr($path, 0, $pos) . '/pages/city_dashboard.php';
}
