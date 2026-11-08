<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  helpers/ActivityLogger.php
//  Writes rows to the activity_log table.
//  Fails silently — never breaks the main request.
// ═══════════════════════════════════════════════════════════════

class ActivityLogger
{
    // ── Action constants ──────────────────────────────────────
    public const SEGMENT_SUBMITTED = 'segment_submitted';
    public const SEGMENT_EDITED    = 'segment_edited';
    public const SESSION_STARTED   = 'session_started';
    public const SESSION_CLOSED    = 'session_closed';
    public const USER_LOGIN        = 'user_login';
    public const USER_LOGOUT       = 'user_logout';
    public const USER_REGISTERED   = 'user_registered';
    public const ROAD_CREATED      = 'road_created';
    public const ROAD_DELETED      = 'road_deleted';
    public const ROAD_FINALIZED    = 'road_finalized';
    public const USER_CITY_CHANGED = 'user_city_changed';
    public const USER_ROLE_CHANGED = 'user_role_changed';
    public const AUDIT_CREATED      = 'audit_created';
    public const AUDIT_UPDATED      = 'audit_updated';
    public const AUDIT_DELETED      = 'audit_deleted';
    public const AUDIT_ROAD_ADDED   = 'audit_road_added';
    public const AUDIT_ROAD_REMOVED = 'audit_road_removed';
    public const AUDIT_SEGMENT_REMOVED = 'audit_segment_removed';
    public const AUDIT_SEGMENT_ADDED   = 'audit_segment_added';
    public const AUDIT_SEGMENTS_ASSIGNED = 'audit_segments_assigned';
    public const AUDIT_ACTIVATED    = 'audit_activated';
    public const AUDIT_SEGMENT_APPROVED  = 'audit_segment_approved';
    public const AUDIT_SEGMENT_SENT_BACK = 'audit_segment_sent_back';
    public const AUDIT_CLOSED            = 'audit_closed';
    public const AUDIT_SENT_TO_ADMIN     = 'audit_sent_to_admin';
    public const AUDIT_APPROVED          = 'audit_approved';
    public const AUDIT_RETURNED          = 'audit_returned';
    public const AUDIT_UNPUBLISHED       = 'audit_unpublished';
    public const AUDIT_REPUBLISHED       = 'audit_republished';
    public const PAST_AUDIT_ADDED        = 'past_audit_added';
    public const PAST_AUDIT_DELETED      = 'past_audit_deleted';
    public const COLLABORATOR_ADDED      = 'collaborator_added';
    public const COLLABORATOR_UPDATED    = 'collaborator_updated';
    public const COLLABORATOR_SHOWN      = 'collaborator_shown';
    public const COLLABORATOR_HIDDEN     = 'collaborator_hidden';
    public const COLLABORATOR_REMOVED    = 'collaborator_removed';
    public const PASSWORD_REQUEST_APPROVED  = 'password_request_approved';
    public const PASSWORD_REQUEST_DISMISSED = 'password_request_dismissed';
    public const TEAM_MEMBER_ADDED          = 'team_member_added';
    public const TEAM_MEMBER_REMOVED        = 'team_member_removed';
    // Sign-in, accounts, passwords
    public const USER_LOGIN_FAILED          = 'user_login_failed';
    public const PROFILE_UPDATED            = 'profile_updated';
    public const PASSWORD_CHANGED           = 'password_changed';
    public const PASSWORD_RESET_REQUESTED   = 'password_reset_requested';
    public const PASSWORD_RESET_COMPLETED   = 'password_reset_completed';
    public const USER_PASSWORD_RESET        = 'user_password_reset';
    public const USER_ACTIVATED             = 'user_activated';
    public const USER_DEACTIVATED           = 'user_deactivated';
    // Roads, segments, field work
    public const ROAD_UPDATED               = 'road_updated';
    public const ROAD_SEGMENTS_SAVED        = 'road_segments_saved';
    public const SEGMENT_COMPLETED          = 'segment_completed';
    public const SEGMENT_RESET              = 'segment_reset';
    public const SEGMENT_UNLOCKED           = 'segment_unlocked';
    // Files downloaded (meta.type says which)
    public const EXPORT_DOWNLOADED          = 'export_downloaded';

    /**
     * Log an activity.
     *
     * @param PDO                  $pdo
     * @param string               $action   One of the class constants above
     * @param int|null             $userId   Authenticated user (null = anonymous)
     * @param array<string,mixed>  $meta     Any extra data to store as JSON
     */
    public static function log(
        PDO    $pdo,
        string $action,
        ?int   $userId = null,
        array  $meta   = []
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO activity_log (user_id, action, meta, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $userId,
                $action,
                empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::clientIp(),
            ]);
        } catch (Throwable $e) {
            // Never crash the main request — just log to file
            if (function_exists('appLog')) {
                appLog('warning', 'ActivityLogger::log failed: ' . $e->getMessage(), [
                    'action' => $action,
                    'userId' => $userId,
                ]);
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────

    private static function clientIp(): string
    {
        // Only trust X-Forwarded-For if the direct connection is from a
        // known Railway/private proxy range — prevents spoofing (Issue 18).
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trustedCidrs = [
            '100.64.0.0/10', '10.0.0.0/8',
            '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
        ];
        $remoteIp = inet_pton($remoteAddr);
        $trusted  = false;
        if ($remoteIp !== false) {
            foreach ($trustedCidrs as $cidr) {
                [$subnet, $bits] = explode('/', $cidr);
                $subnetIp = inet_pton($subnet);
                $mask     = str_repeat("\xff", (int)($bits / 8))
                          . (($bits % 8) ? chr(0xff << (8 - ($bits % 8))) : '')
                          . str_repeat("\x00", strlen($subnetIp) - (int)ceil($bits / 8));
                if (($remoteIp & $mask) === ($subnetIp & $mask)) {
                    $trusted = true;
                    break;
                }
            }
        }
        if ($trusted) {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($fwd !== '') {
                $ip = filter_var(trim(explode(',', $fwd)[0]), FILTER_VALIDATE_IP);
                if ($ip !== false) return $ip;
            }
        }
        return $remoteAddr;
    }
}
