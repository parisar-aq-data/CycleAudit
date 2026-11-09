-- 032: self-registered accounts wait for approval (security review H1).
-- Run this ONE statement in Railway's MySQL console BEFORE deploying the code.
-- Every existing user keeps 'approved', so nothing changes for them.
ALTER TABLE users ADD COLUMN approval_status VARCHAR(10) NOT NULL DEFAULT 'approved' AFTER is_active;
