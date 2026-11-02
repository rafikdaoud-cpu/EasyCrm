# AI / maintainer handoff — announcements, presence and statistics

Updated 2026-11-02 (Africa/Algiers). User-facing specifications and technical decisions are recorded here so Claude, Codex or another maintainer can continue without the chat.

## Repository and delivery

Repository: `rafikdaoud-cpu/EasyCrm`. Starting commit: `f2189af55f246b430837aa939a4a402518f889e7` (main). This work changes `callcrm/` and `nextcloud/`, not the Odoo editions, Android applications, or WhatsApp gateway.

| Edition | Folder / namespace | App version | New migrations |
|---|---|---|---|
| CSM Call CRM | callcrm / OCA\CallCrm | 0.23.2 | 1009 announcements, 1010 presence/history |
| EasyCrm Nextcloud | nextcloud / OCA\EasyCrm | 1.4.2 | 1005 announcements, 1006 presence/history |

Working branch: `feat/annonces-presence-statistiques`. Changes are delivered through that feature branch for review; publication must be established from the actual Git push result, not inferred from a local commit. The new Statistiques response-type table counts all call outcomes (not distinct dossiers), includes configured zero-count types and observed historical types, and sorts by clickable headings. Never hard-code counts from the screenshot.

Versioned ZIPs can be generated with `tools/package-nextcloud.py OUTPUT_DIRECTORY`. Never include the checkout's local environment or credentials in archives.

## Accepted user requirements

1. Group client dossier states under **Dossiers Clients**. Open **À traiter** on initial load and whenever that main tab is clicked. Other states are secondary controls; retain pre-existing access restrictions for Tous.
2. Separate **Annonce** tab for admin/supervisor: select one, several, or all commercial-group recipients. Message is one-way and shown as a popup while Call CRM/EasyCrm is open, or on the commercial's next visit. Persistent dismissal; no reply UI.
3. **Commerciaux** tab for admin/supervisor: current En poste / En pause / Absent states, current pause duration, work/pause durations, dossiers processed and breakdown by outcome. Clicking a commercial's name opens an individually targeted message dialog.
4. Commercial may manually enter/exit pause. A blocking dialog with a stopwatch persists across reload. Escape/outside click cannot end pause. Resume only succeeds after server confirmation. Work in the page is blocked using a modal and inert root.
5. Commercial sees only their own time and dossier indicators in **Mes statistiques**. The existing call journal/statistics remain available.
6. Both views have Jour, Semaine, Mois or inclusive manual Du/Au date filters. Current-day/week/month presets use the CRM timezone; week starts Monday. The same selected range controls time, processed count and outcome breakdown. Call-journal filters are independent and explicitly labeled as such.
7. Sort by **clicking column headings**, clicking again reverses direction. Show arrows and aria-sort. No sort dropdown. In the supervisor table each outcome has a numeric sortable column; personal outcome table has État and Nombre headings. Preserve sort through automatic refreshes.

## Domain definitions / timing

- Browser heartbeat every 30 seconds. Presence expires at last_seen + 120 seconds. Supervisor/personal counters refresh every 15 seconds when their page is visible. Pause clock updates every second.
- Presence indicates a connected CRM page, not physical attendance, mouse/keyboard activity or verified payroll hours. A user who leaves the page open must select pause. Browser throttling, sleep, network loss and session expiry can cause absence.
- The final 120-second grace window may be counted after an unobservable disconnect. Beyond it, absence is excluded. Timer stops advancing beyond confirmed last_seen + tolerance if disconnected.
- Pause preference is shared across a user's devices/tabs. Heartbeats do not overwrite it. Other tabs learn changes on their next heartbeat. After an offline gap the paused preference remains, but a new measured segment/stopwatch starts, excluding the gap.
- A processed dossier is a **distinct prospect per commercial with a recorded call outcome inside the selected interval**. Repeated calls on it count once. Outcome breakdown uses that commercial's latest outcome in the interval, ordered by created_at then id. This is not necessarily the dossier's present global state. Totals across outcomes equal processed dossiers.
- The team total sums per-commercial workloads: the same dossier worked by two people counts for each.
- The roster's presence badge is always current, even for a historical reporting period. Time tracking begins at feature installation; old work/pause history is not fabricated. Existing call history can provide older dossier counts.

## Code map

Matching files exist in each edition:

- `lib/Controller/AnnouncementController.php`: send, list own pending, dismiss own delivery. Admin/supervisor-only sending, validate commercial-group membership, deduplicate UIDs, 2,000-character message limit; no public/NoCSRFRequired write endpoints.
- `lib/Service/Announcements.php`: one delivery row per recipient; transactional all-or-nothing fan-out; pending limit 20; recipient-scoped idempotent dismissal.
- `lib/Controller/PresenceController.php`: authenticated commercial heartbeat/pause, own scoped report, admin/supervisor roster. Invalid reporting filters return HTTP 400.
- `lib/Service/Presence.php`: single per-user state + revision; transactional compare-and-swap/retry, close prior segments on pause/resume or offline gap; bounded open intervals. Report aggregates selected intervals and latest dossier outcomes.
- `lib/Service/PresenceRange.php`: strict local civil date parsing, inclusive Au -> exclusive next midnight, including DST. Default timezone is the existing CRM setting, Africa/Algiers fallback.
- `lib/Migration/Version…`: app-specific announcements table, presence current state and presence_log. All new index names differ between applications. Existing schemas/migrations are preserved.
- `appinfo/routes.php`, `info.xml`: routes and version required for Nextcloud upgrade.
- `js/app.js`: navigation, presence controls/modal, polling, personal/roster filters, outcome tables, sortable heading buttons, sender dialog and receiver popup. User text is rendered as text nodes, not HTML.
- `css/style.css`: additions only, retaining each edition's theme.

Both editions preserve the original workflows outside these additions. EasyCrm retains its conditional WhatsApp navigation (`TEL.wa === 'api'`), PBX integrations, i18n runtime and RTL rules. New EasyCrm static UI strings are passed through T where applicable; existing dictionary translations remain intact. Newly introduced phrases without dictionary entries fall back to French. Full new-string translations and linguistic review remain future work. Do not claim all new UI has been translated.

## Testing / reproducibility

Requirements: PHP 8.1+ with mbstring, PDO and pdo_sqlite; Node.js 18+; jsdom 26.1.0. No real Nextcloud installation is needed for the isolated tests.

```sh
npm install --prefix /tmp/crm-ui-tests --no-audit --no-fund jsdom@26.1.0
NODE_PATH=/tmp/crm-ui-tests/node_modules tools/test-nextcloud.sh
```

Set `PHP_BIN` if PHP is not on PATH. Keep test dependency downloads outside the checkout. Commands return nonzero on failure.

Per edition, `tests/presence.php` includes the announcements suite and currently checks 91 conditions: roles, CSRF annotations, recipient scoping, transactional failures, manual date validation, day/week/month/DST boundaries, gap clipping, distinct dossiers and last-result tie handling. SQLite is real; Nextcloud interfaces and query builder are doubles. UI tests execute real functions plus complete app startup/statistics paths in jsdom: Call CRM 21 tests, EasyCrm 22 tests (extra conditional WhatsApp navigation regression). Shared `tests/migrations.php` executes actual new migration callbacks against a schema double, verifies required fields, repeatability, preservation of pre-existing tables and unique cross-app index names.

These are **local validations, not proof of production Nextcloud compatibility**. No PHP/Nextcloud server integration, actual MySQL/MariaDB/PostgreSQL migration, browser visual review, real concurrent multi-process stress or live VPS deployment was performed by this agent. Before deployment, validate both apps on a staging Nextcloud with actual commercial/admin/supervisor accounts. Test pause/reload, multi-tab updates, offline/resume, custom ranges, header sorting, persistent message dismissal and sending from the roster. Verify PBX/WhatsApp and existing call stats still operate on the target installation.

## Deployment / rollback

See `docs/DEPLOY_NEXTCLOUD.md`. Never replace `callcrm` with `easycrm` or vice versa. The user reported Call CRM under `/home/csmtak11/cloud.csmtaksit.dz/custom_apps/callcrm`, Nextcloud 35.0.2. The chat explicitly showed upgrade to Call CRM 0.18.1; later ZIP installation commands were discussed but exact later installed_version was not supplied. Recheck actual installed_version and local modifications before deployment.

An SSH public key was exchanged during the chat, but the agent environment lacked the VPN/TCP route and no SSH connection was established. No credentials/private keys are in this repository. Do not assume root access. Do not add a key, bypass network controls or disable host verification based on this document. Publication to GitHub is separate from deployment to the VPS.

## Known limits and future considerations

- Pending popups require an open CRM page; there is no push service or native background notification.
- Delivery acknowledgment is dismissal, not proof the person read the message. No response or admin message history UI was requested.
- Retention/purge, exports of presence reports and arbitrary historical calendar navigation beyond Du/Au are not implemented. History tables grow; consider indexed reporting/retention under real load.
- Reporting loads call outcomes for the selected commercial group/range to choose the latest per dossier. Large historical ranges should be profiled before broad rollout. No artificial passing tests or weakened verification should be introduced to hide scaling problems.
- Pause blocks the UI, not every unrelated API action as a security policy. Authentication/permissions remain enforced at endpoints. It does not prove physical attendance.
- The pre-existing recording upload path can leave a pending row after storage failure and falsely treat a retry as duplicate. This was identified in the original review but intentionally left outside the requested feature work. It needs a separately reviewed fix/test; do not claim it is solved.
- Tests are duplicated per edition to exercise each actual app. Keep new service/controller changes aligned and run both suites; do not regenerate whole UI files and overwrite edition differences.

## Final display requirement: outcome detail on EACH commercial's row

The user rejected separating time and dossier details into two tables. **Do not restore the separate outcome table.** The current roster has seven columns: Commercial, État actuel, Pause en cours, Temps en poste, Temps de pause, Dossiers traités, and **Détail des dossiers traités**. Each commercial's last cell contains labeled counters for À rappeler (occupé), Faux numéro, Intéressé, Ne répond pas, Non éligible, Pas intéressé, Transmis sur Plateforme, and any other configured/observed outcome, including zeros. These counters come from that row's by_result, NOT the aggregate team counts. The example names showing 6 processed dossiers must show their own distribution, not the team total.

The detail header provides clickable outcome labels to preserve per-outcome sorting without extending the table far off-screen. Headings and badges wrap; the detail cell receives 45% of the table width. On mobile the table can scroll horizontally. The regression uses two commercial accounts with different counts and verifies every zero/nonzero outcome on the same row as that person's total. A visual check in the user's actual browser remains necessary.

The initial Git push required explicit confirmation of the public destination. The user subsequently confirmed publication. On 2026-11-02 the branch `feat/annonces-presence-statistiques` was successfully pushed to `https://github.com/rafikdaoud-cpu/EasyCrm`, including commits `6de8f8f`, `cbc9d6d` and `ec10991`. The final implementation is the inline per-commercial outcome detail from `ec10991`; the intermediate separate-table layout is superseded. At the user’s request, the feature branch was subsequently merged into `main` by fast-forward on 2026-11-02, preserving all feature commits. Git publication does not establish VPS deployment. See the branch history for subsequent documentation commits.
