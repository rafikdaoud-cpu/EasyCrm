# AI / maintainer handoff — announcements, presence and statistics

## Portage EasyCrm 1.9.0

Voir `nextcloud/APPELS_MANQUES.md` : interface 0.35, appels manqués (via le webhook PBX générique,
migration 1011), session, objectifs (`GoalsController`), Ma journée, réponses WhatsApp rapides. Non
portés : fonctions liées à Yeastar 30011/30012 et au service wa-nextcloud. EasyCrm n'est pas installé
sur le VPS CSM Taksit : aucun déploiement, seulement le dépôt.

## Appels manqués, session d'appels, objectifs — Call CRM 0.36

Voir `callcrm/APPELS_MANQUES.md`. Migration 1019 (`callcrm_missed`), tâche `SyncMissedCalls`,
`MissedCalls` / `MissedController`, objectifs dans `Prefs::goals()` + `DashboardController::goals`.
Règle « répondu par une personne » : poste de commercial ou conversation ≥ `missed_min_talk` (25 s).
Déployé sur le VPS (0.36.2). Tests : `tests/callcrm-missed-ui.cjs` + contrôle réel en transaction.

## Envoi WhatsApp depuis la fiche et modèles approuvés — Call CRM 0.34.0

Détails : `callcrm/WHATSAPP_ENVOI.md`. Bouton « Envoyer un message WhatsApp » sur la fiche
(commercial de la fiche, superviseurs, admin), même sans conversation ; texte libre dans la
fenêtre de 24 h, sinon modèle approuvé Meta avec variables et aperçu ; conversation fermée :
modèle proposé. CRM `GET /api/wa/templates`, `POST /api/wa/prospect/{pid}/send`, `template`
accepté par `/api/wa/{id}/send`. Service `wa-nextcloud` : `GET /templates`, `/send` modèle,
WABA mémorisé depuis le webhook. Au 17/11/2026 le compte n'a **aucun modèle** : à créer dans
le Gestionnaire WhatsApp. Déployé sur le VPS (service redémarré, version sans maintenance).
Tests : `tests/callcrm-whatsapp-ui.cjs` + contrôle du contrôleur réel (rollback).

## Appels en cours, fiche à la prise d'appel, onglet Enregistrements — Call CRM 0.33.0

Détails : `callcrm/APPELS.md`. Le popup de rappel attend la fin d'un appel
(appel lancé depuis la fiche ouverte jusqu'au résultat, 20 min max ; saisie non
enregistrée ; notification Yeastar non terminée ; création de fiche ; autre
fenêtre). Appel entrant décroché (30011 ANSWER/ANSWERED) : fiche accessible
ouverte une fois, numéro inconnu → « Nouveau client » pré-rempli, jamais de
doublon ni de navigation pendant une saisie. `incoming` renvoie `known`.
Onglet « Enregistrements » admin/superviseur : `GET /api/yeastar/recordings/all`
(filtres, 50 par page). Aucune migration. Tests : `tests/callcrm-calls-ui.cjs`.
Constat VPS du 17/11/2026 : le P550 n'envoie que 30012 ; il faut ajouter 30011
au Webhook pour la notification et l'ouverture automatique. Déployé sur le VPS
en 0.33.0 (occ upgrade). Pas de portage dans l'édition EasyCrm.

Attention déploiement : changer `<version>` met tout Nextcloud en « mise à jour
requise » (503, Webhook compris) jusqu'à `occ upgrade` ; le lancer aussitôt.

## Message après composition — Call CRM 0.30.2

À la demande utilisateur, le succès call/dial affiche un message role=status non
bloquant, retiré après 4,5 secondes, au lieu de window.alert/OK. Le focus et les
saisies sont conservés. Les erreurs restent explicitement affichées. Linkus Web
continue de nécessiter le décroché via son bouton vert. Le test jsdom existant
vérifie l’absence d’alerte et la disparition du message. Suite complète locale
`tools/test-nextcloud.sh` réussie. Pas de migration nouvelle.


## Correctif verrou SQL Yeastar — 2026-11-10

Call CRM 0.30.1 : le journal de production montre LockedException dans
DBLockingProvider.acquireLock. La clé précédente préfixe + SHA256 dépassait les
64 caractères de file_locks.key. La ressource est désormais le SHA256 complet
du namespace et de la clé (64 caractères). Lecture du jeton en cache avant
verrou, puis double vérification sous verrou. Une vraie contention retourne un
message de nouvel essai au lieu d’un 500. Aucun verrou de fichiers n’est supprimé,
aucune table Nextcloud purgée. Régression testée avec la limite SQL de 64 caractères
et contention simulée, y compris absence de libération d’un verrou non acquis.
Suite complète `tools/test-nextcloud.sh` réussie localement.
Pas de nouvelle migration. Recette P550 réelle à refaire par l’utilisateur.


## Raccordement Yeastar complet — 2026-11-10

Call CRM 0.30.0 remplace la configuration seule par un connecteur actif : test API,
get_token chiffré/caché sous verrou, call/dial depuis les liens tel: de l’application,
Webhooks 30011 signés HMAC-SHA256, notifications entrantes et journal technique
par client. Migration additive 1016 (`callcrm_pbx_events`). Lire YEASTAR.md pour
installation, association des extensions, activation, réglages P550 et limites.
Linkus Web reste requis pour le son et le décroché. Aucun CDR/durée ni modification
des résultats commerciaux. Aucun accès de l’agent au P550/VPS, API simulée aux
tests : recette réelle requise par l’utilisateur. EasyCrm conserve son intégration.


## Certificat Yeastar temporaire — 2026-11-10

Call CRM 0.29.6 : à la demande explicite de l’utilisateur, ajout de la case
« Autoriser le certificat autosigné (temporaire) » aux réglages Yeastar.
`allow_self_signed` booléen strict, faux par défaut, conservé si omis et modifiable
uniquement par admin CRM. Aucune requête n’existe encore : le futur connecteur
HTTP devra appliquer ce choix par requête P550 seulement ; ne pas l’appliquer
aux autres services ni au navigateur. HTTPS reste obligatoire. L’UI explique
la perte de vérification d’identité et la restriction du port à l’IP du VPS.
Tests PHP et jsdom étendus, documentation YEASTAR.md synchronisée dans le paquet.
Pas de nouvelle migration, aucune intervention distante. Suite complète locale
`tools/test-nextcloud.sh` réussie, y compris les tests PHP et jsdom de l’option.

## Yeastar settings — 2026-11-09

Call CRM 0.29.5 ajoute Administration → Téléphonie · Yeastar P550 : URL HTTPS
préremplie, ID client, secret ICrypto non exposé, URL Linkus facultative et
associations commercial/extension uniques. Endpoints réservés admin CRM avec
CSRF ; alertes de saisie et bloc repliable conservés. Configuration uniquement,
sans connexion API, appel ni Webhook à ce stade. EasyCrm conserve sa téléphonie
existante. Lire [YEASTAR.md](YEASTAR.md) pour les règles de conservation/effacement
du secret, la topologie sans VPN choisie et le problème de certificat connu.
Aucune migration SQL, aucune intervention sur le VPS. Tests ajoutés au script
commun : tests/yeastar-settings.php et tests/yeastar-settings-ui.cjs.

Updated 2026-11-02 (Africa/Algiers). User-facing specifications and technical decisions are recorded here so Claude, Codex or another maintainer can continue without the chat.

## Repository and delivery

Repository: `rafikdaoud-cpu/EasyCrm`. Starting commit: `f2189af55f246b430837aa939a4a402518f889e7` (main). This work changes `callcrm/` and `nextcloud/`, not the Odoo editions, Android applications, or WhatsApp gateway.

| Edition | Folder / namespace | App version | New migrations |
|---|---|---|---|
| CSM Call CRM | callcrm / OCA\CallCrm | 0.25.1 | 1009 announcements, 1010 presence/history, 1011 kind, 1012 daily lunch policy |
| EasyCrm Nextcloud | nextcloud / OCA\EasyCrm | 1.6.1 | 1005 announcements, 1006 presence/history, 1007 kind, 1008 daily lunch policy |

Current publication branch: `main`. The original `feat/annonces-presence-statistiques` branch was merged at the user’s request; publication must be established from the actual Git push result, not inferred from a local commit. The new Statistiques response-type table counts all call outcomes (not distinct dossiers), includes configured zero-count types and observed historical types, and sorts by clickable headings. Never hard-code counts from the screenshot.

Versioned ZIPs can be generated with `tools/package-nextcloud.py OUTPUT_DIRECTORY`. Never include the checkout's local environment or credentials in archives.

## Accepted user requirements

1. Group client dossier states under **Dossiers Clients**. Open **À traiter** whenever that main tab is clicked and initially for non-commercial roles. The approved commercial landing page is now **Ma journée**. Other states are secondary controls; retain pre-existing access restrictions for Tous.
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

Per edition, `tests/presence.php` includes the announcements suite and currently checks 112 conditions: roles, CSRF annotations, recipient scoping, transactional failures, manual date validation, day/week/month/DST boundaries, gap clipping, distinct dossiers and last-result tie handling. SQLite is real; Nextcloud interfaces and query builder are doubles. UI tests execute real functions plus complete app startup/statistics paths in jsdom: Call CRM 25 tests, EasyCrm 26 tests (extra conditional WhatsApp navigation regression). Shared `tests/migrations.php` executes actual new migration callbacks against a schema double, verifies required fields, repeatability, preservation of pre-existing tables and unique cross-app index names.

These are **local validations, not proof of production Nextcloud compatibility**. No PHP/Nextcloud server integration, actual MySQL/MariaDB/PostgreSQL migration, real concurrent multi-process stress or live VPS deployment was performed by this agent. Before deployment, validate both apps on a staging Nextcloud with actual commercial/admin/supervisor accounts. Test pause/reload, multi-tab updates, offline/resume, custom ranges, header sorting, persistent message dismissal and sending from the roster. Verify PBX/WhatsApp and existing call stats still operate on the target installation.

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

## Lunch button and UI proposals (2026-11-02)

Pause déjeuner is implemented for both editions, alongside the ordinary pause button. API `POST /presence/pause` accepts optional `kind: "pause" | "lunch"`; existing clients omitting it keep ordinary pause behavior. The server persists `pause_kind` in current state and closed intervals. New additive migrations: Call CRM 1011 / EasyCrm 1007; existing rows default to ordinary pause. Lunch is labeled explicitly in the user's blocking timer screen and supervisor roster, survives reload/heartbeat and is now reported separately from ordinary pause totals. Resume clears its kind. Do not modify earlier migrations.

The user approved the clear manager table and My day commercial dashboard. These are now implemented; the original HTML proposals are historical mockups. See `UI_THEME.md` for the current layout and lunch rules.

## Approved theme and final lunch policy (2026-11-02)

Call CRM 0.25.1 / EasyCrm 1.6.1 implement the sidebar workspace, session header, manager KPIs, and commercial My day landing page. Dossiers Clients still always routes to À traiter. Preserve per-row dossier details and all existing filters/sorts. Responsive mobile navigation uses a horizontally scrolling menu.

The user corrected lunch policy: **lunch must NOT be included in ordinary pause totals**, once per CRM civil day, no splitting or carryover. Totals now have `online`, `pause`, `lunch` keys for every period. Ordinary pause is overdue after 600 seconds; lunch is in tolerance after 3600 and overdue after 4200 seconds (strict greater-than at thresholds). `pause_alert` is computed server-side and includes text as well as red/orange styling. No automatic resume or reassignment to ordinary pause is applied.

New additive migration: Call CRM 1012 / EasyCrm 1008. `pause_started_at` preserves the original deadline across heartbeat gaps; `lunch_day` records consumption after resume. Duplicate activation of an already running meal is idempotent. A second activation after resume on the same date returns HTTP 409. The compare-and-swap transaction serializes quota updates. Existing closed lunch history is checked when an unpopulated marker could otherwise allow a duplicate after migration. Historical measurements without a known kind cannot be reliably reclassified.

`tests/browser-smoke.py` was executed with real Chromium for both editions and both roles at 1440px and 390px. It uses synthetic API fixtures, not a Nextcloud server. Desktop/mobile navigation, lack of page-level horizontal overflow, per-row outcome details, red alerts and the lunch blocking/resume behavior are checked; screenshots are generated outside the repository. This does not establish live Nextcloud/PBX/WhatsApp compatibility or actual deployment.

## Migration hotfix 0.25.1 / 1.6.1

The user's actual Nextcloud 35 upgrade rejected Call CRM migration 1012: lunch_day was NOT NULL with an empty-string default. The same defect existed in the corresponding EasyCrm 1008 migration. These failed migration definitions now add a nullable string with NULL default; service code already normalizes NULL to an unused marker. Do not use an empty default on a NOT NULL column. The shared schema double now enforces this Nextcloud rule, and the real SQLite service tests use nullable lunch_day.

This correction intentionally changes the migration that FAILED, so Nextcloud can retry it. Do not mark the migration completed, delete tables, force maintenance off while needsDbUpgrade is true, or restore/drop data to work around the failure. If lunch_day unexpectedly exists with incompatible metadata after a partial attempt, inspect the actual schema before another change; no remote SQL alteration has been attempted here. The user must replace the failed migration with its fixed definition (or install the hotfix package), rerun occ upgrade, verify needsDbUpgrade false and then disable maintenance if still enabled. Real VPS retry outcome is not yet known.


## Transfert vers Non assigné (2026-11-03)
Call CRM 0.25.2 / EasyCrm 1.6.2 : destination explicite Non assigné dans le transfert administratif et la sélection de prospects. Le marqueur API `__none` est converti en SQL NULL ; une destination vide reste refusée. Droits assign inchangés, statut et quantité conservés, refus source/destination identiques, audit lisible. Aucun déploiement VPS effectué.

Validation : `tools/test-nextcloud.sh` réussi avec PHP 8.4 (mbstring/PDO SQLite) et jsdom 26. Deux nouveaux parcours UI vérifient le refus du placeholder, le comptage, la quantité/statut, la confirmation et l’envoi explicite vers Non assigné dans chaque édition. Suites présence/annonces et migrations conservées. Pas de recette Nextcloud/VPS réelle.


## Blocs d’administration repliables (2026-11-03)
Call CRM 0.25.3 / EasyCrm 1.6.3 : chaque carte d’option possède un en-tête cliquable pour agrandir/réduire, avec commandes globales Tout réduire / Tout agrandir. Fond gris clair par bloc, champs conservés en blanc. Le repli ne supprime pas les champs ni leurs valeurs non enregistrées ; il ne les enregistre pas automatiquement. Préférence locale au navigateur, isolée par application et identifiant utilisateur, tous ouverts à la première visite. Le repli fonctionne même si le stockage local est indisponible. Les formulaires chargés de façon asynchrone (simulateur) gardent leur bloc. Aucun changement de droits ou de schéma, aucun déploiement VPS.

Validation : suite `tools/test-nextcloud.sh` réussie, dont deux nouveaux parcours jsdom couvrant repli individuel/global, conservation des saisies et événements, restauration des préférences, isolation par compte, remplacement asynchrone du contenu et fonctionnement sans stockage local. Validation locale uniquement.


## Simulateur et historique (2026-11-03)
Call CRM 0.25.4 : le simulateur reste ouvert au clic extérieur, à Échap et après sauvegarde ; fermeture par la croix seulement. Depuis la fiche, Enregistrer dans la note du client cible le client courant. Depuis le menu, rechercher puis choisir un client accessible avant sauvegarde. Bouton bloqué pendant la requête et après sauvegarde de la même estimation ; une modification permet une nouvelle sauvegarde. Dimensions invalides bloquées, erreurs affichées sans vider le formulaire.
Le serveur recalcule les prix et produit le résumé en texte brut, délimité par un astérisque littéral au début et à la fin. Il ajoute ce bloc à `remarque` (commentaire de la fiche), en conservant les textes précédents. L’audit de simulation et la note sont transactionnels. Une comparaison de la note et de l’attribution empêche de remplacer une modification détectée entre lecture et écriture ; conflit HTTP 409. Aucun droit ajouté : sauvegarde réservée aux rôles déjà autorisés à appeler, avec périmètre client serveur et CSRF conservés.
La fiche ouverte reprend le résumé dans son champ commentaire sans jeter les saisies locales. L’historique de simulation est conservé pour la prochaine ouverture de la fiche. Aucun enregistrement automatique à la fermeture. Une réponse perdue après une écriture réussie reste ambiguë : vérifier la note avant de réessayer, pas d’idempotence serveur inter-session ajoutée.
Call CRM 0.25.4 et EasyCrm 1.6.4 : les entrées d’historique d’appel n’affichent plus `user_id`, pour tous les résultats (y compris personnalisés). Résultat, date/heure, motif, note et rappel éventuel restent affichés ; les données et l’auteur restent en base pour audit. Notes multilignes préservées à l’écran. Le simulateur de cuisine est spécifique à Call CRM et n’existe pas dans cette édition EasyCrm : aucune copie intégrale ni nouveau simulateur n’y a été ajouté.
Validation locale : suite `tools/test-nextcloud.sh`, quatre parcours UI supplémentaires (deux éditions d’historique, simulateur fiche, simulateur global/échec réseau) et huit contrôles PHP/SQLite supplémentaires (résumé, ajout à note, audit, rollback, attribution/concurrence et note vide). Pas de déploiement VPS ni de recette intégrée Nextcloud. Aucune migration nouvelle.


## Agenda et fiche client validés (2026-11-03)
Call CRM 0.26.0 / EasyCrm 1.7.0 : onglet Agenda (Aujourd’hui, Demain, En retard), rappels provenant des statuts callback configurés, nouveaux RDV avec révision serveur, filtres type/recherche/commercial et pagination bornée. Les compteurs utilisent le jour civil du fuseau CRM (y compris changement d’heure). Créer les RDV depuis la fiche client ; l’agenda propose ouverture directe et appel, et modification/clôture des RDV. Le périmètre commercial est imposé côté serveur ; lecture seule sans écriture ; admin et superviseur peuvent gérer les RDV accessibles.
Chronologie paginée mélange appels historiques, simulations existantes et nouveaux événements de note, affectation et RDV. Curseur stable même pour plusieurs événements à la même seconde, noms résolus via Nextcloud ; compte disparu affiché Ancien utilisateur. Les notes de fiche et affectations sont désormais tracées transactionnellement (attribution directe, transfert, répartition/réattribution et affectation lors d’un changement de statut en masse). Les anciens changements de notes et transferts non tracés par client ne peuvent pas être reconstitués ; ne pas inventer des événements historiques. L’historique d’audit existant est conservé.
Motifs propres à chaque réponse, boutons de sélection dans la fiche et listes modifiables dans Administration / Réponses d’appel (un motif par ligne, 30 motifs maximum, 100 caractères chacun). Le serveur refuse un motif d’un autre résultat. Le caractère obligatoire reste celui du paramétrage existant. Les anciennes listes globales personnalisées de refus sont préservées à la mise à jour ; les autres réponses reçoivent des suggestions spécifiques. Le champ global historique reste pour compatibilité, mais éditer désormais les motifs par réponse.
Fiche smartphone : coordonnées secondaires et RDV repliables, chronologie prioritaire et barre fixe Appeler / WhatsApp / Enregistrer. Enregistrer sauvegarde le résultat d’appel sélectionné, sinon les informations de fiche. Les appels hors ligne existants sont toujours mis en file locale. Alerte de navigation interne avec Rester / Quitter sans enregistrer pour les informations, résultat d’appel et simulation (simulateur Call CRM uniquement). Fermeture de simulation avec modification non sauvée demande confirmation. Fermeture ou actualisation du navigateur : avertissement natif, au texte décidé par le navigateur. Les champs restent en mémoire, pas d’autosauvegarde ni de restauration après fermeture.
Nouvelles migrations additives : Call CRM 1013 / EasyCrm 1009, tables client_events et appointments ; aucune ancienne migration réécrite. Installer le bon ZIP, sauvegarder base/code, puis lancer `occ upgrade` sous l’utilisateur Nextcloud. Vérifier needsDbUpgrade=false avant de désactiver la maintenance. Ne pas installer sur le VPS sans accès réel et opération explicitement effectuée.
Validation : suite PHP/SQLite + jsdom existante et tests client-activity dans les deux éditions (périmètres, motifs serveur, transitions/RDV, rollback, auteur supprimé, pagination avec événements simultanés, bornes jour/DST). tests/activity-browser.cjs exécute les deux applications dans Chromium avec API fictive, vérifie agenda, fiche, chronologie, barre mobile, sélection de motifs et prévention de perte de saisie ; captures réelles du code générées dans les artefacts, pas du VPS. Pas de recette intégrée Nextcloud/LDAP/PBX et pas de test de charge concurrente. La pagination de l’agenda utilise un décalage et peut bouger si de nouvelles tâches sont ajoutées entre deux pages ; actualiser la liste.


## Icônes du menu (2026-11-03)
Call CRM 0.26.1 / EasyCrm 1.7.1 : icônes SVG locales pour Ma journée, Agenda, Dossiers Clients, WhatsApp, Simulateur (Call CRM), Statistiques, Administration, Annonce et Commerciaux. Traits currentColor pour suivre la couleur active ; tailles desktop/mobile adaptées ; SVG décoratifs aria-hidden et libellés conservés. Aucune dépendance externe et aucun changement de droits ou de migration.


## Relances, recherche et pièces jointes — Call CRM 0.27.0 / EasyCrm 1.8.0

Demande livrée : options 3/4/6, avec réception WhatsApp et e-mail. La boîte n’est pas encore créée ; l’utilisateur souhaite la définir dans les réglages. L’e-mail est désactivé par défaut. Voir [DOCUMENTS.md](DOCUMENTS.md) et les copies embarquées dans chaque application.

- ActivityController.search / ClientActivity.globalSearch : recherche dans nom, prénom, téléphone, e-mail, commentaire et dernière note d’appel ; résolution du nom de commercial via IUserManager pour les rôles voyant tous les dossiers. Périmètre imposé côté serveur. Accès depuis le menu Recherche et l’en-tête.
- ActivityController.neglected : sans appel depuis N jours, rappel dépassé, dernière simulation ancienne sans appel ultérieur. Seuls nouveau/intéressé/statuts callback sont retenus pour les dossiers sans suivi, afin d’exclure les dossiers clôturés. Le contrôle d’ancienneté du devis porte sur le plus récent, pas un devis ancien supersédé. Le nombre de jours reste un filtre d’écran (1–365), sans réaffectation automatique.
- Nouveau champ intégré email, facultatif et validé, utilisant la colonne déjà présente. Les anciens champs personnalisés de type e-mail restent dans extra et ne sont pas automatiquement fusionnés : renseigner le champ intégré pour le rattachement.
- Documents : stockage privé IAppData/client-documents, registre SQL documents, token aléatoire par original/PDF. Les sources et tokens restent internes. Téléchargements via DocumentController contrôlés à chaque appel suivant le client courant ; file à rattacher réservée admin/superviseur ; réglages réservés au rôle fields (admin).
- Les pièces connues sont visibles dans la fiche, ajout manuel limité à 25 Mo. Les documents sans client sont dans Pièces reçues ; recherche et rattachement manuels par la direction. Les fichiers sont dédupliqués par clé de source (e-mail boîte/UIDVALIDITY/UID/partie, WhatsApp ID de message, ajout manuel compte/client/SHA256).
- CollectDocuments (TimedJob, 300 s) collecte WhatsApp et la boîte IMAP puis traite 3 conversions par passage. Registre avec prise de bail comparée et durée 180 s pour éviter deux conversions simultanées. PDF déjà reçus gardés, JPEG convertis nativement en A4, autres images via GD, documents Office via /usr/bin/libreoffice avec profil temporaire propre et délai 90 s. Original conservé même si conversion échoue ; état/erreur affichés, bouton Réessayer. Audio/vidéo/formats hors liste restent en original. La conversion n’est ni une OCR, ni un antivirus.
- DocumentMailbox : boîte définie dans Administration, mot de passe chiffré avec ICrypto et jamais renvoyé au navigateur. IMAP SSL/STARTTLS avec validation du certificat ; connexion en lecture seule et récupération PEEK. Pas d’envoi, pas de suppression ou marquage lu. Parcours existant depuis UID 0 lors de la première activation, 10 messages par passage. UIDVALIDITY et compte/dossier contrôlés, mot de passe à ressaisir si changement de serveur/compte. Texte et structures MIME traités avec noms décodés. Un message défectueux/surdimensionné bloque le curseur et remonte une erreur : le déplacer manuellement hors du dossier surveillé après récupération de l’original, ne pas supprimer automatiquement.
- Rattachement automatique e-mail uniquement si un client unique porte l’adresse expéditeur dans email ; doublon/inconnu en file. WhatsApp suit la conversation mais exige une correspondance téléphonique non ambiguë quand le téléphone est présent. Médias sources sous WhatsApp/ du compte wa_files_user. Un chemin invalide n’est jamais lu et remonte une erreur dans les réglages. Les pièces déjà classées ne sont pas déplacées automatiquement si le contact ou la conversation change ensuite. Une pièce non classée peut être rattachée manuellement.
- La suppression d’un prospect conserve ses pièces dans la file de la direction ; la confirmation UI le précise. Pas de purge automatique ou suppression de la source mail/WhatsApp.
- Migrations additives Call CRM 1014 / EasyCrm 1010. Background job enregistré dans info.xml. Aucun changement des anciennes migrations. LibreOffice, IMAP et GD sont des dépendances de serveur, pas fournis par le ZIP ; leur disponibilité est affichée dans les réglages. L’agent n’a pas installé de paquet sur le VPS ni activé une vraie boîte mail.

Validation locale : tests/documents.php inclut client-activity.php pour chaque édition ; SQLite réel, doubles Nextcloud/fichiers, conversion JPEG réelle vérifiée par pdfinfo (A4, une page, PDF 1.4), conservation des originaux, déduplication, nom de fichier, correspondances ambiguës, réaffectation et droits de téléchargement, mot de passe non exposé, collecte WA simulée et décodage MIME. Tests client-activity étendus à recherche/commentaires/noms, relances et devis récents. tests/documents-browser.cjs exécute les deux applications dans Chromium (API fictive) pour recherche, relances, réglages, liens PJ et parcours mobile/alertes. Aucun test de connexion à une boîte réelle, aucune conversion LibreOffice réelle ou recette Nextcloud/stockage du VPS dans cet environnement. Avant déploiement, tester ces intégrations avec une boîte et des pièces fictives et vérifier le cron. Le registre de conversion peut croître ; surveillance du stockage nécessaire selon la charge. Pagination par décalage limitée à 10 000, affiner les recherches très larges.

## Call CRM 0.28.0 — lien de dépôt de documents client

Voir `callcrm/COLLECTE.md`. Service `Collect` (jeton haché, dépôts en AppData `collect-<id>`,
envoi final vers `Documents clients/…` dans les Fichiers du commercial actuel, copie dans
`callcrm_documents` source `client_link`, événement `documents` en chronologie), contrôleurs
`CollectController` (CRM, CSRF) et `CollectPublicController` (PublicPage, BruteForceProtection,
AnonRateLimit), page `templates/collect.php` + `js/collect.js`, scanner `js/docscan.js` /
`js/scanpdf.js` / `js/vendor/opencv.js` repris de l'application Coordination. Migration 1015.
Tâche de fond `CollectDocuments` : `Collect::cleanup()` expire les liens et purge leurs dépôts.
Tests : `tests/collect.php` (dans `tools/test-nextcloud.sh`) et `tests/collect-browser.py`.
Seulement dans l'édition Call CRM ; EasyCrm non porté.

## Call CRM 0.29.0 — livraison immédiate des documents client

`Collect::upload(link, prospect, owner, index, bytes)` écrit directement dans les Fichiers du
commercial (dossier `Documents clients/<client>/<date du lien>/`, nom `NN - libellé.ext`,
remplacement si le document est refait) et dans `callcrm_documents`. `submit()` ne fait plus
que vérifier les obligatoires et fermer le lien ; il livre encore les dépôts en attente
(`file_token` non vide, dossier AppData `collect-<id>`) laissés par les versions ≤ 0.28.3.
Chronologie : événement `documents` `started` au premier document, `received` à la fin.

## Call CRM 0.29.1 — logo de l'entreprise dans l'aperçu du lien client
- La page `collecte/{token}` ajoute `og:title`, `og:site_name`, `og:description`, `og:image` et `apple-touch-icon`.
- Image = logo Nextcloud « Personnaliser l'apparence » (thème) : favicon PNG/JPEG, sinon logo, sinon logo d'en-tête (les .ico et .svg sont ignorés car WhatsApp/iMessage ne les affichent pas).
- Sans logo personnalisé : pas d'image (pas d'image cassée). Test : `tests/collect-preview.php`.
- Les messageries gardent l'aperçu en cache par lien : seuls les nouveaux liens montrent le logo.

## Call CRM 0.29.2 — message au client en français et en arabe
- Le texte WhatsApp / SMS / e-mail du lien de documents est bilingue (français puis arabe), le lien seul sur la dernière ligne.

## Call CRM 0.29.3 — page client en arabe
- Textes de la page publique dans `js/collect-i18n.js` (fr, ar) ; `docscan.js` accepte `opts.text`, `opts.dir`, `opts.lang` (français par défaut, Coordination inchangée).
- Langue : `?lang=ar|fr`, sinon choix mémorisé (localStorage), sinon langue du téléphone ; bouton 🌐 en haut. Arabe = `dir="rtl"`.
- Les intitulés de documents viennent des réglages admin (pas traduits) : l'admin peut les écrire en deux langues.
- Test navigateur : `tests/collect-browser.py` (passage en arabe, scanner RTL, retour au français).

## Call CRM 0.29.4 — nouvelle icône (conseiller avec casque)
- `img/app.svg` (blanc, barre Nextcloud) et `img/app-dark.svg` (noir, menus clairs) ; même icône dans le logo de la barre latérale (`crm-brand-mark`).

## Call CRM 0.56.0 · Fiches partagées et historique Yeastar

Le partage permanent d’une fiche après décrochage est propre à Call CRM. La migration 1023 crée `callcrm_shared_clients`. La contrainte unique empêche les doublons lors des nouvelles livraisons d’un événement. L’ajout du droit et sa trace dans la chronologie sont enregistrés dans la même transaction. La fiche, la liste, la recherche et la chronologie prennent en compte ce droit pour le commercial concerné. Les notes au dossier et les différences de champs alimentent `callcrm_client_events`.

Le menu **Historique des appels** lit les détails d’appels Yeastar stockés dans `callcrm_pbx_recordings`, y compris les lignes sans audio. Le serveur fixe le filtre du commercial à l’utilisateur connecté. Les rôles administrateur et superviseur peuvent demander l’historique global. La procédure de recette et les limites des données historiques sont décrites dans [Historique des appels](../callcrm/HISTORIQUE_APPELS.md).

Validation locale sur PHP 8.4, SQLite et jsdom. La suite `tools/test-nextcloud.sh` couvre les droits dossier, les notes, la traçabilité, la répétabilité de la migration et l’envoi des filtres et du tri par l’interface. Une instance Nextcloud 32 avec SQLite a servi à vérifier l’installation, le traitement d’événements signés simulés et les requêtes d’historique. La recette sur un standard Yeastar et la base de production reste à faire.

## Call CRM 0.56.1 · Création de dossier

La version 0.56.0 initialisait l’état du formulaire de modification dans le formulaire de création, ce qui bloquait l’ouverture du nouveau dossier. L’état est maintenant initialisé dans la fiche existante. Le test de régression exécute le vrai formulaire en création manuelle et après le décroché d’un appel Yeastar entrant inconnu. Il vérifie le numéro prérempli, l’envoi de la fiche et son ouverture après enregistrement. Ce correctif JavaScript ne comporte aucune nouvelle migration.

## Call CRM 0.56.2 · Liens SMS de dépôt

Les messages SMS, les e-mails associés et les messages WhatsApp libres conservent l’URL publique rendue par Nextcloud. L’alias `/d/` généré côté navigateur dépendait d’une redirection hors du dépôt. Le test jsdom vérifie l’URL transmise au contrôleur SMS et le texte du message. Cette révision ne comporte aucune nouvelle migration.

## Call CRM 0.57.0 · Pilotage commercial et fermeture du simulateur

Le menu **Pilotage commercial** est réservé aux administrateurs et superviseurs autorisés à voir les données. L’administrateur voit toutes les équipes. Le superviseur voit les équipes explicitement attribuées dans l’administration. Les filtres par équipe et commercial sont contrôlés côté serveur. La période peut couvrir une journée, la semaine, le mois ou des dates personnalisées (92 jours maximum).

Le taux de réponse et le graphique reposent sur les détails d’appels reçus de Yeastar, regroupés par identifiant. Le graphique présente des créneaux horaires sur une journée et quotidiens sur plusieurs jours, dans le fuseau CRM. Pour une sélection d’équipe ou de commercial, seuls les appels associés à la sélection sont comptés. Un appel pris par un autre poste après transfert ne compte pas comme manqué pour le filtre actif. Les détails sans poste associé sont comptés dans la vue globale de l’administrateur.

L’indicateur d’objectif réutilise les objectifs d’appels configurés dans le CRM et compte les résultats d’appel enregistrés. La période précédente couvre le même nombre de jours civils. Le tableau **Charge par commercial** affiche les dossiers distincts traités sur la période, les entrants décrochés et les appels sortants. La connexion au CRM, le nombre de dossiers au statut Nouveau et les rappels en retard décrivent la situation actuelle.

Dans le simulateur, la confirmation de fermeture apparaît au-dessus de la fenêtre. Le bouton de fermeture et la touche Échap permettent de quitter en gardant la confirmation pour une simulation non enregistrée. La version inclut le correctif d’URL SMS de la 0.56.2. Aucune nouvelle migration n’est nécessaire pour un serveur déjà en 0.56.x.

Validation avec `tools/test-nextcloud.sh`, les tests d’interface jsdom et un test local du périmètre d’équipe et des indicateurs sur Nextcloud 32 et SQLite avec des données fictives.

## Call CRM 0.57.1 · Durée du dernier appel manqué

La liste des appels manqués affiche la durée totale du dernier appel pour chaque numéro regroupé, telle que fournie par le détail Yeastar. Elle affiche « non renseignée » quand le détail ne permet pas de déterminer la durée. Aucun changement de schéma.

## Call CRM 0.57.2 · Appels manqués et perdus

Le classement rapproche les événements de sonnerie au poste avec les détails d’appels Yeastar. Sans décroché, le seuil de 4 secondes distingue un appel manqué à rappeler d’un appel perdu. Les sonneries simultanées ne s’additionnent pas. La migration 1024 ajoute un index de recherche sur les événements. Voir les [règles de classement et leurs limites](../callcrm/APPELS_MANQUES.md#call-crm-0572--appels-manqués-et-appels-perdus).

## Call CRM 0.57.3 · Détail des compteurs de Pilotage

Les compteurs **Appels manqués**, **Appels perdus** et **Dossiers traités** ouvrent leur liste dans Pilotage. Le détail réutilise les mêmes filtres, le même périmètre et la même classification côté serveur. Il est paginé à 50 lignes. Une sonnerie de 4 secondes ou plus sans décroché correspond à un appel manqué. Les appels perdus sont séparés. Le détail des dossiers retient le dernier résultat par commercial et dossier sur la période. La fiche peut être ouverte si elle est accessible. Aucun changement de schéma depuis 0.57.2.

## Call CRM 0.57.4 · Suivi et navigation dans Pilotage

La liste des appels manqués indique le dossier accessible, le responsable et l’état du rappel quand l’appel peut être rattaché à un épisode de la liste Appels manqués. Les appels perdus indiquent un raccroché avant la sonnerie ou une sonnerie de moins de 4 secondes. Chaque barre de l’activité téléphonique ouvre le détail du créneau, par heure sur une journée et par jour sur une période plus longue. Le retour d’une fiche restaure la période, l’équipe, le commercial, la liste et sa page pendant la session. Aucun changement de schéma depuis 0.57.3.

## Call CRM 0.57.5 · Orientation de WhatsApp par poste

Un message WhatsApp entrant de type texte composé uniquement d’un numéro de poste, par exemple `1014`, affecte la conversation au commercial actif associé à ce poste dans les réglages Yeastar. La migration 1025 ajoute l’affectation propre à WhatsApp. La fiche client garde son titulaire et les droits de consultation habituels. Les messages sortants, les légendes, les numéros inconnus et les numéros intégrés à un texte ne déclenchent aucune affectation. L’orientation est inscrite dans le journal d’activité avec la référence de la conversation, le poste et les affectations avant et après.

## Call CRM 0.57.6 · Résultats des appels sortants

Pilotage sépare les appels sortants aboutis (`ANSWERED`) et les appels sortants sans réponse (`NO ANSWER` et variantes). Les compteurs suivent la période, l’équipe et le commercial sélectionnés. Le tableau affiche les mêmes indicateurs par commercial. Les appels occupés, échoués et annulés figurent uniquement dans le total des sortants. Si plusieurs détails concernent le même appel, un décroché prime sur la tentative sans réponse. Aucun changement de schéma.

## Call CRM 0.57.7 · Listes des appels entrants et sortants

Dans Pilotage, les compteurs **Appels entrants**, **Appels sortants**, **Sortants aboutis** et **Sortants sans réponse** ouvrent des listes paginées qui reprennent la période, l’équipe et le commercial. La liste des entrants inclut les appels indéterminés du périmètre sélectionné. Les appels occupés, échoués ou annulés figurent dans la liste complète des sortants. Le calcul des compteurs et des listes suit les mêmes règles côté serveur. Aucun changement de schéma.

## Call CRM 0.57.8 · Ouverture des fiches depuis les appels

Les listes d’appels entrants et sortants dans Pilotage affichent les colonnes **Dossier** et **Appelant**. Le numéro et le nom du dossier ouvrent la fiche si elle est accessible. Si le détail Yeastar ne référence aucune fiche, Call CRM cherche une correspondance unique par numéro, y compris aux formats national et international algériens. Pour un appel entrant, l’appelant est le client identifié ou son numéro. Pour un sortant, il s’agit du commercial associé au poste, lorsqu’il est connu. Aucun changement de schéma.

## Call CRM 0.57.9 · Tri des listes de Pilotage

Chaque en-tête des listes détaillées permet de trier la sélection complète avant pagination. Un deuxième clic inverse le sens du tri. Les valeurs manquantes restent en fin de liste. Le tri conserve les filtres de période, équipe, commercial et créneau, et il est restauré au retour d’une fiche. Aucun changement de schéma.

## Call CRM 0.57.10 · Statut IVR dans l’Historique des appels

Une ligne entrante Yeastar `ANSWERED` sans commercial identifié affiche **Standard / décroché commercial non confirmé**. Le libellé **Répondu**, le filtre de statut et le tri utilisent ce même statut d’historique. Le statut brut du standard reste disponible pour le diagnostic. Aucun changement de schéma.

## Call CRM 0.57.11 · Recalcul des statistiques téléphoniques

Statistiques recalcule les appels entrants de la période avec la même classification que Pilotage. Un décroché commercial est confirmé par les détails ou événements Yeastar. La sonnerie sans décroché devient un appel manqué à partir de 4 secondes au poste. Les abandons confirmés et les appels indéterminés apparaissent séparément. La liste de suivi de Statistiques ne présente que les appels manqués confirmés. Les rappels déjà enregistrés, y compris les rappels traités, sont conservés. Les événements absents ne sont pas reconstitués. Aucun changement de schéma.

## Call CRM 0.57.12 · Réglages de colonnes du Journal des appels

Le tri et la pagination remplacent le tableau du journal. Les réglages **Colonnes** et **Largeurs par défaut** de l’ancien tableau sont désormais retirés avec lui. Le nouveau tableau récupère les préférences enregistrées. Un test jsdom vérifie qu’il ne reste qu’une barre après plusieurs tris, puis qu’elle disparaît lorsque le tableau est retiré. Aucun changement de schéma.

## Call CRM 0.57.13 · Sens dans le Journal des appels

Le Journal des appels affiche **Entrant**, **Sortant** ou **Indéterminé**. Le nom du client ouvre directement sa fiche. Le serveur détermine le sens d’un nouveau résultat à partir d’un événement Yeastar correspondant au compte, à la fiche ou au numéro, et à l’identifiant d’appel fourni par l’interface. Une ancienne ligne sans rapprochement et une saisie manuelle sans référence restent indéterminées. La migration 1026 ajoute la colonne de sens.

## Call CRM 0.57.14 · Appels hors horaires

La destination Yeastar `IVR CLOSED <6201>` permet de reconnaître les appels sur le message de fermeture même en l’absence d’événement de sonnerie. Ils figurent dans les entrants et dans une liste **Appels hors horaires**. Ils ne dégradent pas le taux de réponse et n’ajoutent pas de rappel. Un décroché commercial confirmé prime. Le réglage Nextcloud `callcrm/yeastar_closed_ivr` permet de préciser un autre numéro de destination. La classification est relue sur les détails existants et ne suppose aucun horaire à partir de l’heure seule. Aucun changement de schéma.

## Call CRM 0.57.15 · Export Excel de l’Historique des appels

Le bouton **Exporter Excel** reprend la sélection et le tri appliqués dans l’Historique, sur toutes les pages. L’export exige le droit d’export et respecte le périmètre d’accès. Le téléphone est inclus uniquement avec le droit d’exporter les numéros. Au-delà de 20 000 lignes, le serveur demande une période plus courte afin de produire un fichier complet. L’opération est consignée dans le journal d’activité. La pagination de l’écran reste celle du serveur. Aucun changement de schéma.

## Call CRM 0.57.16 · Actualisation manuelle de Pilotage

Le bouton **Actualiser** demande au serveur de recalculer Pilotage à partir des données déjà reçues. Il reprend la période, l’équipe et le commercial appliqués, puis recharge la liste ouverte avec le même créneau et le même tri. Aucun changement de schéma.

## Call CRM 0.57.17 · Créneau hors horaires dans Pilotage

La carte Hors horaires compte les entrants visibles entre 16 h 31 la veille et 8 h 30 le jour même. Le jeudi à 16 h 30, la carte bascule vers le créneau du week-end, qui se termine le dimanche à 8 h 30. Ce créneau reste affiché le dimanche. Le CRM utilise son fuseau configuré. L'heure de début est incluse. Le créneau s'arrête à l'heure de réouverture. La carte et sa liste utilisent le même filtre d'équipe et de commercial, indépendamment de la période choisie dans Pilotage. La liste conserve le résultat téléphonique de chaque appel. Aucun changement de schéma.

## Call CRM 0.57.18 · Commercial ayant décroché

Dans Pilotage, la colonne triable Répondu par affiche les commerciaux dont le décroché est confirmé par un événement Yeastar ou un CDR associé à leur poste. Elle figure dans les listes des entrants, des décrochés et du créneau hors horaires. Les noms suivent le périmètre du filtre et les droits du lecteur. Aucun changement de schéma.

## Call CRM 0.57.19 · Rapprochement des appels Yeastar après création

À l’ouverture ou à l’actualisation du bloc Appels et enregistrements Yeastar, le CRM rattache les appels non rattachés correspondant au numéro principal d’une fiche unique, y compris ceux reçus dans les 24 heures avant sa création. Le POST utilise les droits de consultation du bloc et respecte les rattachements existants. Les appels plus anciens ou les numéros ambigus restent disponibles pour un rattachement manuel. Aucun changement de schéma.

## Call CRM 0.57.20 · Nom du commercial sur les enregistrements

Le bloc Appels et enregistrements Yeastar affiche le nom du commercial quand le compte associé possède un nom d’affichage. Le libellé de l’audio disponible devient « Audio enregistré dans le CRM ».
