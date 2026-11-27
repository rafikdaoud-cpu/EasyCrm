# Call CRM 0.36 — appels manqués, session d'appels, objectifs

## Appels manqués à rappeler

Source : détails d'appel Yeastar 30012 déjà reçus (`callcrm_pbx_recordings`). Un appel entrant est
**répondu** si une ligne ANSWERED concerne le poste d'un commercial, ou si la conversation dure au
moins `missed_min_talk` secondes (réglage d'application, 25 par défaut). Constat du 17/11/2026 : la
file 6200 « répond » en ~10 s (accueil / attente) sans qu'un commercial parle au client ; avec la règle,
283 des 297 appels entrants du jour étaient sans réponse d'une personne (157 numéros).

- Table `callcrm_missed` (migration 1019) : un épisode **par numéro** tant qu'il est ouvert (appels
  répétés regroupés, compteur). Synchronisation incrémentale (`missed_last_id`) à chaque consultation et
  par la tâche de fond `SyncMissedCalls` (60 s), verrou exclusif ; on attend 120 s qu'un appel soit complet.
- Clôture automatique : rappel sortant répondu (`callback`), client qui rappelle et obtient une réponse
  (`client_recalled`), résultat saisi sur la fiche après l'appel (`crm_result`). Clôture manuelle :
  déjà rappelé, faux numéro / spam, autre. Chaque tentative sortante est comptée ; le délai avant rappel
  = première tentative (ou premier résultat saisi) − premier appel manqué.
- Attribution : commercial du client s'il existe ; sinon, pour les appels des **4 dernières heures**,
  commercial **en poste** le moins chargé ; sinon « à prendre » (bouton « Je le prends », un seul gagne).
- Commercial : carte dans Ma journée (les siens + à prendre), badge sur Ma journée, « Rappeler » (lien ☎,
  puis fiche ou formulaire « Nouveau client » prérempli). Responsables : onglet « Appels manqués »
  (indicateurs jour / semaine / mois, filtre, répartition, clôture), badge = appels à prendre.
- API : `GET /api/missed?period=`, `POST /api/missed/{id}/take|assign|close`.

## Session d'appels

« Démarrer une session d'appels » (Ma journée) : file = appels manqués avec fiche, rappels en retard,
puis nouveaux dossiers (sans doublon). La fiche affiche la barre de session (traités, restants, durée,
Passer, Terminer) ; « Enregistrer et client suivant » avance dans la file ; bilan à la fin.

## Productivité et pilotage

- Réponses WhatsApp rapides : Réglages des appels (séparées par une ligne vide, `{nom}`), proposées en
  pastilles dans le composeur quand la fenêtre de 24 h est ouverte.
- Chronologie : l'audio Yeastar est attaché au résultat d'appel le plus proche (2 h avant à 10 min après).
- Objectifs par commercial (jour, semaine) dans Commerciaux (`GET/POST /api/goals`, appconfig `goals`) ;
  objectif du jour vide = objectif général. Ma journée : tuiles temps en poste, appels du jour et de la
  semaine avec progression, rappels en retard, dossiers traités.

## Tests

`tests/callcrm-missed-ui.cjs` (jsdom) ; contrôle réel sur le VPS en transaction annulée : visibilité
commercial / superviseur, prise unique, clôture, répartition, objectifs. Synchronisation exécutée sur les
données réelles du 17/11/2026.

## Regroupement par numéro (0.36.3)

- Onglet Enregistrements : « Regrouper par numéro » activé par défaut (`group=1`) — une ligne par numéro
  avec appels distincts (identifiant PBX), entrants, sortants, sans réponse d'une personne, audios et
  dernier appel ; un numéro s'ouvre pour charger ses appels (`phone=` exact) avec écoute et rattachement.
  Décocher revient à la liste détaillée appel par appel.
- Statistiques, « Appels entrants : suivi » : une ligne par numéro (nombre d'appels, dernier, liste
  dépliable) ; « Marquer traité » s'applique à tous les appels ouverts du numéro.

## Call CRM 0.57.2 · Appels manqués et appels perdus

Pour les nouveaux détails entrants, Call CRM rapproche les événements signés 30011 et le détail 30012 par identifiant d’appel et numéro. Un décroché au poste prime. Sans décroché, au moins **4 secondes** de sonnerie sur un même poste donnent un appel manqué à rappeler. Une fin d’appel dans le SVI, en file ou avant ce seuil donne un appel perdu si les événements permettent de le déterminer. Les sonneries simultanées ne s’additionnent pas. Les appels indéterminés sont signalés dans Pilotage et ne créent pas de nouveau rappel. Les anciens rappels restent dans leur état actuel.

Le taux de réponse de Pilotage inclut les appels perdus dans son dénominateur et exclut les appels indéterminés. Le diagnostic repose sur les événements transmis par le P550. Les trunks entrants et les postes commerciaux doivent être surveillés. La migration 1024 ajoute un index pour le rapprochement des événements.

## Call CRM 0.57.30 · Classement par le journal du standard

Le classement s’appuie d’abord sur le journal complet du standard (durée de sonnerie de la file et des postes). Un appel qui raccroche dans le message d’accueil devient un « Abandon au menu d’accueil » et n’ouvre pas de rappel. Les appels hors horaires (message de fermeture, de 16 h 30 à 8 h 30) restent à part. Pilotage indique pour chaque appel manqué ou perdu s’il a été rappelé (appel sortant vers le numéro ensuite). Voir `docs/AI_HANDOFF.md`, section 0.57.30.

## SMS automatique aux appelants (0.58.20)

Administration → SMS · ICOSNET → « SMS automatiques aux appelants » : un modèle par cas, « Désactivé » par défaut.

- **Appel hors des horaires de travail** : le client est tombé sur le message de fermeture (`closed`).
- **Appel manqué ou perdu** : personne n'a décroché, ou le client a raccroché avant d'être servi (`missed`, `lost`).

Envoyé par la synchronisation des appels manqués (tâche de fond, chaque minute), hors du verrou. Un seul SMS par numéro et
par 24 h (`sms_auto_sent`), mobiles algériens uniquement, appels de plus de 2 h ignorés. Chaque envoi est noté dans le
journal (`sms_auto`) et dans l'historique de la fiche quand l'appelant en a une. Variables : `{prenom}`, `{nom}`,
`{commercial}` (« CSM Taksit » pour un appelant sans fiche). Code : `lib/Service/AutoSms.php`.

### WhatsApp automatique aux appelants (0.58.21)

Même cadre, deux choix de plus : modèle WhatsApp approuvé par Meta et sans variable (`auto_wa`, « nom|langue »), envoyé
par le service wa-nextcloud (`/send`), noté dans la conversation WhatsApp et dans le journal (`wa_auto`). Un message par
numéro et par 24 h (`wa_auto_sent`), indépendant du SMS. Modèles soumis le 27/11/2026 : `csm_appel_hors_horaires` (fr),
`csm_appel_manque` (fr), `csm_documents_a_fournir` (fr et ar, envoyé depuis la fiche par le commercial).
