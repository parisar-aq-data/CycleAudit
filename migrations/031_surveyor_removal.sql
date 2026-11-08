-- ═══════════════════════════════════════════════════════════════
--  031_surveyor_removal.sql
--  A surveyor who is removed (taken out of a City Leader's team, or
--  deactivated) while they still have segments they have started keeps
--  working until those are submitted, then is removed automatically.
--  These columns remember that a removal is waiting.
--
--    removal_pending_at    when the removal was asked for (NULL = none waiting)
--    removal_action        'team' (leave the team) or 'deactivate'
--    removal_requested_by  who asked, so they are told when it completes
--
--  Additions only. Railway's query console runs ONE statement per
--  query: this file is a single statement.
--
--  Rollback:
--    ALTER TABLE users DROP COLUMN removal_requested_by,
--      DROP COLUMN removal_action, DROP COLUMN removal_pending_at;
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE users
  ADD COLUMN removal_pending_at   DATETIME    NULL DEFAULT NULL,
  ADD COLUMN removal_action       VARCHAR(12) NULL DEFAULT NULL,
  ADD COLUMN removal_requested_by INT         NULL DEFAULT NULL;
