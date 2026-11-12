# Workflows

This chapter explains how work moves through CycleAudit, from an Admin creating a project to a published audit on the public home page. It follows the business flow first, then points to the page, endpoint and helper that implement each step.

Related chapters: [database](database.md) (tables and status values), [auth and permissions](auth-and-permissions.md) (roles, guards, CSRF), [scoring](scoring.md) (how segment and road scores are computed), [API reference](api-reference.md), [frontend](frontend.md).

Everything here was read from the code. Items that could not be proved are marked "(not verified)".

## 1. The big picture

A **city audit** (also called a **project**) is one audit programme for one city and year. Three roles take part:

- **Admin** creates the project, may assign it to a City Leader, decides on the finished audit, and controls what the public sees.
- **City Leader** owns the project once assigned. They add roads, split them into segments, assign segments to surveyors, review submitted work, and send the audit on.
- **Surveyor** walks assigned segments and fills in the audit form.

```
Admin creates project ................ city_audits.status = draft
Admin assigns a City Leader (optional at creation, or later on Edit)
City Leader adds roads -> segments are generated
City Leader assigns segments to surveyors
City Leader activates ................ draft -> active
Surveyor opens a road, fills the form, submits each segment
        segment_assignments: assigned -> submitted
City Leader reviews each submitted segment
        approve ...... submitted -> approved          (audit becomes in_review)
        send back .... submitted -> needs_revisit     (surveyor fixes, submits again)
City Leader closes ................... active / in_review -> finalised
        (only when every segment is approved)
City Leader sends to Admin ........... finalised -> awaiting_approval
Admin decides
        approve ...... awaiting_approval -> published
        return ....... awaiting_approval -> in_review (with a note)
Admin can hide / show a published audit on the home page
Admin can delete a project at almost any time ... any status -> voided
```

The status names and moves above are the real ones in the code. The table-level view is in [database, section 5](database.md).

## 2. Who may do what (summary)

| Step | Who | Check in code |
| --- | --- | --- |
| Create, edit, delete project; approve or return; hide or show | Admin only | `nationalApiContext()` in `helpers/CityApi.php` |
| Add or remove roads and segments, assign, activate, review, close, send | City Leader only, and only on a project of their own city that the Admin assigned to them | `cityApiContext()` and `projectOpenableBy()` |
| Open a road, fill and submit a segment | The surveyor the segment is assigned to | `SurveyorWorkRepository::mayOpenRoad`, `surveyorSubmitBlockReason()` |

`projectOpenableBy()` (in `helpers/CityLeaderAssign.php`) lets an Admin open any project. A City Leader may open a project only if its `city_id` equals their city and `leader_user_id` equals their own id. Otherwise the City Leader endpoints answer `404 Audit not found`, so a leader cannot learn that another leader's project exists.

Every write endpoint also does these things, in this order: method check, role check, CSRF check (header `X-CSRF-Token`), JSON body check, then project lookup. A deleted (voided) project is refused with HTTP `410` and the message in `PROJECT_DELETED_MESSAGE` (`helpers/ProjectStatus.php`).

## 3. Step by step

### 3.1 Admin creates a project

- Page: `pages/platform_dashboard.php` and the new project screen (`js/admin_new_project.js`). Endpoint: `POST api/admin/audit_create.php`.
- Input: a city (existing `city_id`, or a `city_name` picked from the built-in list), `name`, `audit_date` (YYYY-MM-DD), optional `programme_info`, optional `leader_user_id`.
- Rules (`helpers/CityAudit.php`, `cityAuditValidate`): name 3 to 150 characters; a real calendar date between the years 2000 and 2100 (the audit year is taken from it); programme info up to 2000 characters. The state follows the city, so a posted state is ignored when the city is known.
- A city that is new to the app is added only together with its first project (`cityFindOrCreate`). People who named that city at sign-up are placed in it (`cityAdoptSignups`).
- The same name and year in one city is refused with `409` ("An audit with this name and year already exists for this city").
- If a leader is given, `projectLeaderError()` checks they are an active City Leader of this city. Without a leader, the project stays with the Admin until one is chosen on the Edit screen.
- Result: status `draft`. Activity log: `audit_created`. The leader gets a `project_assigned` notification.

### 3.2 Admin edits a project or changes its leader

- Endpoint: `POST api/admin/audit_update.php` (called from `js/admin_project.js`).
- Editable: name, audit date, programme info, and the leader. The city and state do not change.
- When the leader changes, the new leader is told they got the project and the old leader is told it was taken away. If only details changed, the old leader is told what changed. A notification failure never undoes the edit.
- Activity log: `audit_updated` (one entry for the details and one for the leader change).

### 3.3 City Leader adds roads and builds segments

- Page: `pages/city_audit.php?id=N` (script `js/city_audit.js`). Endpoints: `api/city/audit_road_add.php`, `audit_road_remove.php`, `audit_segment_add.php`, `audit_segment_remove.php`.
- **Roads come from the city's road list.** The road picker reads `road_groups` for the city, minus roads already in this audit (`CityAuditRepository::availableRoadGroups`). The road list itself is kept on `pages/admin.php` ("Roads page, City Leader only").
- **Adding a road** needs the road group, the total length and the segment length. Rules (`cityAuditValidateRoad`): total length 50 to 100,000 metres; segment length at least 10 metres; no more than 500 segments.
- **Segments are generated automatically** by `cityAuditSegmentPlan()`: count = ceil(total length / segment length), and the last segment is the remainder. Labels look like `500m` and `250.5m`.
- **Adding one segment** (`audit_segment_add.php`) appends a segment to the end of an existing road. It is refused if the road is finalised, already has the maximum number of segments, or would grow past 100,000 metres.
- **Removing** a road or a segment is allowed only while the audit status is `draft`, `active` or `in_review` (`CityAuditRepository::EDITABLE_STATUSES`), the road is not finalised, and auditing has not started on it. Removing deletes the segments and their assignments. Surveyors who held those segments are told.
- Activity log: `audit_road_added`, `audit_road_removed`, `audit_segment_added`, `audit_segment_removed`.

### 3.4 City Leader assigns segments to surveyors

- Endpoint: `POST api/city/audit_assign.php`. Two shapes: `{audit_id, surveyor_id, segment_ids: [...]}` or `{audit_id, surveyor_id, road_id}`. Sending both is refused. `surveyor_id` of null, empty or 0 clears the assignment. At most 500 segments per request.
- Allowed while the audit is `draft`, `active` or `in_review` (`ASSIGNABLE_STATUSES`).
- Only segments whose status is still `pending` can change. Assigning a whole road skips segments that already started and says how many were skipped; if none are left it refuses ("Auditing has already started on every segment of this road").
- The surveyor must be an active surveyor of the same city, must not be waiting to be removed, and must be in the leader's team when a team applies (`requireCitySurveyor`, `teamScope`; see [auth chapter, team roster](auth-and-permissions.md)).
- Result: rows in `segment_assignments` with status `assigned`. Activity log: `audit_segments_assigned`.
- Notifications: the new surveyor hears only about segments they did not already hold; a surveyor who loses segments (moved or cleared) is told too (`assignmentNotifyPlan`).

### 3.5 City Leader activates the audit

- Endpoint: `POST api/city/audit_activate.php`. Rules (`CityAuditRepository::activate`):
  - only a `draft` audit can be activated;
  - at least one road;
  - at least one segment assigned to a surveyor;
  - no segment assigned to a surveyor who is no longer active in this city.
- Result: `draft` to `active`. Activity log: `audit_activated`. Every surveyor with segments is notified (`workNotifyActivated`).
- Surveyors can work only while the audit is `active` or `in_review` (`SURVEYOR_WORKABLE_AUDIT_STATUSES` in `helpers/SurveyorWork.php`). A `draft` audit is not open for surveying even if segments are assigned.

### 3.6 Surveyor does the field work

Pages: `pages/dashboard.php` (list of assigned work; data from `api/dashboard/stats.php`), `pages/segment.php` (road and its segments), `pages/form.php` (the audit form). Scripts: `js/dashboard.js`, `js/segment.js` (opens sessions; calls complete, unlock and finalize), `js/form.js` (autosave, submit, reset, pre-fill).

What the surveyor sees for each assignment (`surveyorWorkState`):

| Assignment status | Shown as |
| --- | --- |
| `submitted` | Submitted |
| `needs_revisit` | Needs revisit |
| `approved` | Approved |
| `assigned`, with an active session on the road | In progress |
| `assigned`, no active session | To do |

**Opening a road** (`POST api/audit-sessions/create.php`, limited to 10 requests per minute per user):

1. Refused with `410` if the project was deleted.
2. Refused with `403` ("This road is not assigned to you") unless the user holds a segment of the road (`mayOpenRoad`).
3. If the road is finalised, no session is created; the existing session id comes back with `finalized: true`, so read-only features such as the PDF still work.
4. If the user already has an active session for the road, it is resumed (`resumed: true`).
5. Otherwise a new session is created (`session_started` in the activity log).

**Filling the form.** `js/form.js` (`FormStateManager`) saves the form to the browser's `localStorage` every 30 seconds and on every change, under the key `parisar_form_<segmentId>`. A saved draft expires after 24 hours, is restored on load if it belongs to the same segment, and is cleared after a successful submit or reset. This draft lives only in that browser; the server sees nothing until the surveyor submits.

**Submitting a segment** (`POST api/segments/submit.php`, limited to 30 requests per minute per user). Inside one database transaction, with the segment row locked (`FOR UPDATE`) so a double click cannot submit twice:

1. Refused with `409` if the road is finalised or the segment is already `completed` ("already audited and is locked").
2. Refused with `403` if the session is not the user's active session, if the segment is not on the session's road, or if `surveyorSubmitBlockReason()` says no. That function returns a message when the audit is voided, not `active` or `in_review`, the segment is not assigned to this user, or it was already submitted. Roads that do not belong to a city audit (`audit_id` null) are never blocked by it.
3. Answers are validated; bad values are refused with `422`. When the segment already has an audit row, its old obstruction and intersection rows are deleted and rewritten, so an edit replaces the earlier answers.
4. The segment is set to `completed` with `completed_at` (`segments.status`).
5. The assignment moves to `submitted` with `submitted_at`, but only from `assigned` or `needs_revisit` (`SurveyorWorkRepository::markSubmitted`).
6. The session is completed automatically when every segment of its road is `completed` (`AuditSessionRepository::autoCompleteIfReady`).
7. Road start and end points are derived: segment 1's start landmark becomes the road's start, and the last segment's end landmark becomes its end.
8. Activity log: `segment_submitted`, or `segment_edited` when the form was in edit mode.
9. After the commit, a first-time submission notifies the City Leader (`workAfterSubmit`). A notification problem never fails a saved submission.

**Other segment actions** (all `POST` or `PUT`, CSRF-checked, refused with `410` on a deleted project):

| Endpoint | What it does | Notes |
| --- | --- | --- |
| `api/segments/complete.php` (PUT) | Marks a segment `completed` and auto-completes the session | Needs the user's own active session |
| `api/segments/reset.php` (POST) | Deletes all audit data of the segment and returns it to `pending`; reopens a completed session | Needs the user's own session |
| `api/segments/unlock.php` (POST) | Returns a `completed` segment to `pending` but keeps its answers so they can be edited | Permission `unlock_segment`; refused on a finalised road |
| `api/roads/finalize.php` (PUT) | Locks a road permanently once every segment is `completed`; repeating it is harmless | Permission `finalize_road` |
| `api/segments/audit-data.php` (GET) | Returns the latest saved answers to pre-fill the form | Permission `view_audit_data` |

A finalised road (`roads.finalized_at` set) is read-only everywhere: submit, unlock and add-segment all refuse it.

### 3.7 City Leader reviews submitted segments

- Pages: `pages/city_audit.php` (list and progress), `pages/city_segment_review.php?audit_id=N&segment_id=M` (one segment with its score and everything recorded). Script: `js/city_review.js`. Endpoint: `POST api/city/audit_review.php`.
- Allowed only while the audit is `active` or `in_review` (`AUDIT_REVIEW_OPEN_STATUSES`), and only for a segment whose assignment is `submitted`.
- **Approve:** assignment becomes `approved`, the review note is cleared, and an `active` audit moves to `in_review` (`markInReview`).
- **Send back:** a note is required, at most 500 characters (`auditReviewCleanNote`). The assignment becomes `needs_revisit` with the note, the segment returns to `pending`, the surveyor's latest completed session on that road is reopened, and the audit moves to `in_review`. The leader may name a different surveyor to redo it. That person must be an active surveyor of the city, not waiting to be removed, and in the leader's team. If the original surveyor has left and no replacement is given, the request is refused.
- Two reviewers acting at once: the update only succeeds if the assignment is still `submitted`; the loser gets "That segment was already reviewed. Reload the page."
- Activity log: `audit_segment_approved` or `audit_segment_sent_back`. The surveyor is notified (`workNotifyReview`); a new surveyor is told they were handed the segment.
- After a send back, the surveyor sees "Needs revisit", reopens the segment, fixes it and submits again (back to step 3.6).

### 3.8 City Leader closes the audit and sends it to the Admin

- Endpoint: `POST api/city/audit_close.php` with `action` `close` or `send`.
- **Close:** allowed from `active` or `in_review`, only when the audit has segments and every one is `approved` (`auditReviewCanClose`). Otherwise the message says how many segments are not approved yet. Result: `finalised`. Activity log: `audit_closed`. Surveyors are notified.
- **Send:** allowed only from `finalised`. Result: `awaiting_approval`. Activity log: `audit_sent_to_admin`. Every active Admin is notified.
- Reports: `pages/city_audit_report.php?id=N` (overall report) and `pages/city_audit_report_detail.php?id=N` (printable detailed report, built by `helpers/CityDetailedReport.php`). Downloads: `api/reports/export-city-excel.php` (city-wide score sheet).

### 3.9 Admin decides

- Page: the Admin dashboard (`pages/platform_dashboard.php`, "what needs attention"; the waiting list comes from `AuditReviewRepository::awaitingApproval`). The decision buttons call the endpoint from `js/city_review.js`. Endpoint: `POST api/admin/audit_decide.php`.
- Works only while the audit is `awaiting_approval`.
- **Approve:** status `published`, `admin_note` cleared, `admin_decided_at` and `admin_decided_by` set. Activity log: `audit_approved`. The leader is notified. The public audits cache is cleared.
- **Return:** a note is required (max 500 characters). Status goes to `in_review` with the note saved in `admin_note`. Activity log: `audit_returned`. The leader is notified with the note. The leader then fixes things (for example sends a segment back), closes again and sends again.

### 3.10 Publishing, hiding and deleting

- **Hide / show on the home page** (`api/admin/audit_unpublish.php`, `audit_republish.php`, page `pages/published_audits.php`): only for a `published` audit. Hiding sets `public_hidden_at`; showing clears it. The audit stays `published`. Activity log: `audit_unpublished`, `audit_republished`.
- **Delete** (`api/admin/audit_delete.php`, called from `js/admin_project.js`, `js/admin_dashboard_delete.js` and `js/admin_published.js`): the Admin must type the project's exact name and give a reason. The project is not erased. Its status becomes `voided`, its name is changed, and any active sessions on its roads are set to `archived`. The leader and every surveyor on it are notified with the reason, and a surveyor whose removal was waiting on this project's work may now be freed (`workFinishRemovalIfDone`). Activity log: `audit_deleted` with counts of roads, segments, started work and surveyors. Deleting an already deleted project is refused.
- Hiding, showing, approving and deleting all clear the public audits cache file (section 5).

## 4. Notifications and the activity log

**Notifications** are rows in `notifications`, written through `NotificationRepository`. The bell and list are served by `api/user/notifications.php` (`GET` returns the user's unread items; `POST {action: "read_all"}` marks them read, with CSRF). Type strings in `helpers/Notifications.php`: `segments_assigned`, `segments_unassigned`, `project_deleted`, `project_updated`, `city_leader_changed`, `project_assigned`, `surveyor_removal`, `work_status`. The wording functions (`segmentSubmittedNotificationText`, `auditClosedNotificationText` and so on) live in the same file; the senders for review steps are in `helpers/WorkNotify.php`. In the endpoints read for this chapter, notification code is wrapped so that a failure is only logged and never undoes the action that triggered it.

| Event | Who is told |
| --- | --- |
| Project assigned, changed or taken away | The City Leaders involved |
| Segments assigned, moved, cleared, or removed with a road | The surveyor gaining or losing them |
| Audit activated | Surveyors with segments |
| Segment submitted (first time) | The City Leader |
| Segment approved or sent back | The surveyor (and a new surveyor if handed over) |
| Audit closed | Surveyors of the audit |
| Sent to Admin | All active Admins |
| Approved or returned by Admin | The City Leader |
| Project deleted | The City Leader and all surveyors on it |

**Activity log.** `ActivityLogger::log()` writes a row to `activity_log`. Constants for every event named above are in `helpers/ActivityLogger.php`; `pages/admin_activity.php` shows them to Admins and `helpers/ActivityLogView.php` turns them into readable lines. Logging is best effort and written after the action succeeds.

## 5. Public pages and data feeds

These need no login. Each has a per-IP request limit.

| Endpoint | Returns |
| --- | --- |
| `api/public/audits.php` | Published audits for the "Audit data" section (plus past audits the Admin added by hand). Cached in a temp file for 300 seconds (`PUBLIC_AUDITS_CACHE_TTL`); cleared when the Admin approves, hides, shows or deletes |
| `api/public/stats.php` | Landing page figures, optionally for one city (`?city_id=`) |
| `api/public/roads.php` | Verified road names, read from `road_groups` |
| `api/public/collaborators.php`, `collaborator_logo.php` | Organisations listed under "Collaborations" (hidden or removed ones are left out; no server cache) |
| `api/public/past_audit_report.php` | The PDF of a past audit; unknown or deleted ids answer 404 |

Only `published` audits are public, and only the fields chosen in `helpers/PublicAudits.php`; nothing about who created an audit is shown. `pages/public_audit_report.php?id=N` is the public detailed report of a published audit.

Past audits (done before CycleAudit) and collaborators are added by the Admin on `pages/published_audits.php` and `pages/collaborations.php`, with rules in `helpers/PastAudits.php` and `helpers/Collaborators.php`. Activity log events: `past_audit_added`, `past_audit_deleted`, `collaborator_*`.

## 6. Dashboards, reports and streaks

- **Surveyor:** `pages/dashboard.php` with `api/dashboard/stats.php`. Counts come from `helpers/SurveyorWork.php` (`surveyorWorkSummary`, `surveyorAssignedTotals`). The audit streak (`helpers/StreakCalculator.php`) counts consecutive days with at least one segment audit and keeps a streak alive until a full day is missed.
- **City Leader:** `pages/city_dashboard.php` (headline numbers, what needs attention, one card per audit) and `pages/city_completed.php` (published audits of their own projects). Rules in `helpers/CityDashboard.php`.
- **Admin:** `pages/platform_dashboard.php` (what needs attention, every audit, every city). Rules in `helpers/AdminDashboard.php`.
- **Per-road reports:** `pages/report.php` and `pages/road_result.php` in the browser; `api/reports/export-pdf.php` (mPDF) and `api/reports/export-excel.php` (PhpSpreadsheet) as downloads, both by `?session_id=`; `api/reports/session.php` returns the report data as JSON. Scores come only from `ScoreService` (see [scoring](scoring.md)).

The inner details of the dashboard queries and the report layouts were not traced line by line (not verified).

## 7. Roads created outside a city audit

`api/roads/create.php`, `update.php`, `delete.php` and `groups.php` are the older road endpoints. The permission `create_road` is granted to Admins and City Leaders only (`isAnyAdmin`), and `delete_road` to Admins only (`config/permissions.php`). Roads with no `audit_id` keep the older rules: `surveyorSubmitBlockReason()` returns nothing for them, so they are never blocked by the audit status. `api/roads/groups.php` (GET) lists the unflagged road names of the viewer's city. Whether this older path is still used from any page was not checked (not verified).

## 8. Common questions

**Why can't a surveyor open a segment?** The audit may still be `draft` (activate it), the segment may be assigned to someone else, the audit may be `finalised` or later, or the project may have been deleted.

**Why can't I remove a road or reassign a segment?** Auditing has already started on it (the segment is no longer `pending`), or the audit has moved past `in_review`.

**Why does Close say segments are not approved yet?** Every segment must be `approved`. Segments that are unassigned, assigned, `submitted` or `needs_revisit` all count as not approved.

**A surveyor was deactivated while holding work. What happens?** See "Removing a surveyor who has work" in the [auth chapter](auth-and-permissions.md). Activation also refuses segments held by inactive surveyors.

**Where do I change a status rule?** Pure rules are in `helpers/CityAudit.php`, `AuditReview.php`, `SurveyorWork.php` and `CityLeaderAssign.php` (unit tested). The database moves are in `repositories/CityAuditRepository.php`, `AuditReviewRepository.php` and `SurveyorWorkRepository.php`. If you change a status name, also update the dashboards, the notifications and [database, section 5](database.md).

## Known gaps and things that surprised me

- `api/segments/submit.php` writes the segment status with its own SQL (`UPDATE segments SET status = 'completed'`) instead of calling `SegmentRepository::markCompleted`, which `complete.php` uses. Both end in the same state, but a change to one needs checking in the other.
- Several endpoints (`complete.php`, `reset.php`, `unlock.php`, `finalize.php`, `submit.php`) check CSRF inline instead of through `cityApiContext()`. The per-endpoint table is in the API reference.
- In `api/segments/reset.php` there is no check of the audit status, the finalised state of the road, or the assignment; only the deleted-project check and session ownership. Whether this can leave a submitted assignment pointing at an emptied segment was not tested.
- Hide and show on the home page only change `public_hidden_at`; there is no separate status for "hidden".
