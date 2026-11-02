# Instructions for future agents

This repository has independent Nextcloud applications and Odoo editions. Read [docs/AI_HANDOFF.md](docs/AI_HANDOFF.md) before continuing the announcements/presence/statistics work. User requests take precedence.

- `callcrm/`: internal CSM edition, app ID `callcrm`, namespace `OCA\CallCrm`.
- `nextcloud/`: EasyCrm Nextcloud edition, app ID `easycrm`, namespace `OCA\EasyCrm`.
- Preserve edition-specific telephony, WhatsApp, languages and configuration. Never copy a whole application over the other. Keep corresponding new services/controllers behaviorally aligned, while converting IDs, table prefixes, group prefixes and migration/index names.
- Application versions: Call CRM 0.23.1 and EasyCrm 1.4.1 for this change. Increment versions for subsequent shipped changes; do not rewrite migrations that may already have run.
- Use the existing checkout; cloud tasks are isolated. Do not create worktrees unless requested.
- Run `tools/test-nextcloud.sh`; prerequisites and limitations are in the handoff. Keep tests meaningful and preserve command failures.
- Preserve server-side role checks, CSRF protection, user isolation, transactionality and single-account time accounting. Do not use client-provided user IDs or time counters as authority.
- Update the handoff and deployment notes with new behavior and tests. Distinguish local tests from live Nextcloud validation and Git publication from deployment.
- Never commit SSH private keys, tokens, backups, node_modules or local runtime files. No VPS access is established by this repository. Do not claim or perform remote changes without the actual connection and user authorization.
