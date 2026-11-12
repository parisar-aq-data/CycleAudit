# CycleAudit documentation

Technical documentation for **CycleAudit**, Parisar's cycle track auditing platform ([live site](https://cycleaudit.parisar.org/)). It is written for the next developer or operator: what the system does, where each piece of code lives, and how to run and change it safely.

Every chapter was written by reading the code, not from memory. Where something could not be proved from the repository, the chapter says "(not verified)". The code is always the final word.

## The chapters

| Chapter | Read it when you need to | Type |
| --- | --- | --- |
| [Database](database.md) | Know what each table and column means, how the tables relate, which enum values exist, how to add a migration, or run a read-only query | Reference |
| [Authentication, roles and permissions](auth-and-permissions.md) | Understand the three roles, who may do what, sessions, rate limiting, sign-up and approval, passwords, CSRF | Reference |
| [Scoring engine](scoring.md) | See exactly how a segment and a road get a 0 to 100 score, with worked examples, or change scoring safely | Reference |
| [Workflows](workflows.md) | Follow how work moves from an Admin creating a project to a published audit on the public home page, and which page, endpoint and helper does each step | Walkthrough |
| [API reference](api-reference.md) | Look up an endpoint: method, who may call it, CSRF, inputs, answers, error codes | Reference |
| [Frontend](frontend.md) | Find the page and script behind a screen, understand the CSP nonce, the CSRF token in the browser and the audit-form draft | Reference |
| [Operations](operations.md) | Deploy, read the health check, understand CI, find logs, restore a backup, or follow a runbook when something breaks | Runbook |

## Where to start

- **New developer:** [Workflows](workflows.md) for the big picture, then [Database](database.md), then the chapter for the area you will touch.
- **Changing scoring:** [Scoring](scoring.md) section 8, then run `composer test`.
- **Adding an endpoint:** [API reference](api-reference.md) section 10, then [Authentication](auth-and-permissions.md).
- **Adding a page or script:** [Frontend](frontend.md) section 12.
- **Changing the database:** [Database](database.md) section 7, then [Operations](operations.md) runbook 3.
- **Something is broken in production:** [Operations](operations.md) section 9.

## The system in one paragraph

A PHP 8.1 application with no framework and no build step. Server-rendered pages in `pages/` and `auth/` call JSON endpoints in `api/`; endpoints use repositories (SQL) and services (scoring) on a MySQL 8 database. Three roles (Admin, City Leader, Surveyor) work through city audits that surveyors fill in segment by segment. A pure-PHP scoring engine turns each audit into Safety, Comfort and Continuity scores. The app runs in a Docker container on Railway, deploys from GitHub, and is backed up daily to Backblaze B2.

## Other files at the repository root

| File | What it is |
| --- | --- |
| [README.md](../README.md) | Overview, features, local setup and configuration |
| [SECURITY.md](../SECURITY.md) | What protects the app, known limits, how to report a vulnerability, safe-change steps |
| [BACKUPS.md](../BACKUPS.md) | The backup job: Backblaze setup, Railway variables, verifying and restoring |
| `tests/e2e/README.md` | Running the end-to-end tests locally |

## Conventions used in these chapters

- Paths are relative to the repository root. `api/...` is a JSON endpoint, `pages/...` a screen, `helpers/...`, `repositories/...` and `services/...` the PHP behind them.
- Roles appear as shown in the app (Admin, City Leader, Surveyor) and as stored (`admin`, `city_leader`, `surveyor`).
- Most chapters end with a list of **known gaps and things that surprised me**, or questions to confirm: things found by reading that you may want to fix or check.
- Environment variable **names** appear in the docs; values never do.

## Keeping the docs true

These chapters describe the code as it was when they were written. When you change behaviour, change the chapter in the same pull request:

- a new or changed endpoint: [API reference](api-reference.md) (and its guard table in section 9)
- a new table, column or status value: [Database](database.md) and a numbered file in `migrations/`
- a new role rule or permission: [Authentication](auth-and-permissions.md)
- a scoring change: [Scoring](scoring.md) and its tests
- a new page, script or inline block: [Frontend](frontend.md)
- a new environment variable, workflow or runbook: [Operations](operations.md)
