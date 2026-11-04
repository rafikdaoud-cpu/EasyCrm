# AI / maintainer handoff — announcements, presence and statistics

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
