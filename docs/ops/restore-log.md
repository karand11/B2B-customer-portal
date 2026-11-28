# Restore drill log

Record every drill (monthly, and after any release with a migration). Phase 8 adds the production runs.

| Date (UTC) | Environment | Source | Result | Tables / rows | Files | App boot | Notes / who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 2026-11-27 | local Docker (Phase 7 build) | `portal_e2e` after a seed and a Playwright run | OK | 32 tables, content hashes identical; 9 triggers, 95 indexes, 30 migrations | 2 test files identical | `migrate:status`: nothing pending | main session, first run of `ops/backup/restore-drill.sh` |
