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
