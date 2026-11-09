<?php
declare(strict_types=1);

// ───────────────────────────────────────────────────────────────
//  api/admin/signup_requests.php   (security review H1)
//  Approve or reject a sign-up that is waiting for approval.
//
//  POST  {"action":"approve","id":N}   -> the person can sign in
//  POST  {"action":"reject","id":N}    -> closed; they stay out (an Admin can reactivate later)
//
//  Who may act is decided on the server from the signed-in account, never from the
//  page: an Admin on anyone waiting; a City Leader on people waiting in their own city.
//  Anything else gets "Request not found.".
// ───────────────────────────────────────────────────────────────

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    error_log('api/admin/signup_requests.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../../config/admin_guard.php';
require_once __DIR__ . '/../../helpers/Registration.php';
require_once __DIR__ . '/../../helpers/ActivityLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$id     = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
$action = (string)($body['action'] ?? '');

if ($id === false || $id === null || $id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$res = signupRequestDecide($pdo, $id, $action, (string)$CURRENT_USER_ROLE, $CURRENT_USER_CITY_ID);
if ($res['ok']) {
    ActivityLogger::log(
        $pdo,
        $action === 'approve' ? ActivityLogger::USER_ACTIVATED : ActivityLogger::USER_DEACTIVATED,
        (int)$CURRENT_USER_ID,
        ['user_id' => $id, 'source' => $action === 'approve' ? 'signup_approved' : 'signup_rejected']
    );
}

http_response_code($res['http']);
$out = ['success' => $res['ok']];
if (isset($res['error'])) { $out['error'] = $res['error']; }
echo json_encode($out);
