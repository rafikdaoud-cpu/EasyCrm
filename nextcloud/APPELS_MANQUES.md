# EasyCrm 1.9.0 — nouveautés portées depuis Call CRM 0.33 à 0.36

Portage volontaire (pas de copie de l'application) : identifiants `easycrm`, tables `easycrm_*`,
index `ecrm_*`, textes passés par `T()` quand c'était simple (les nouveaux libellés restent en français
tant que les dictionnaires ne sont pas complétés).

## Porté
- **Interface** : navigation en sections, recherche Ctrl K, onglets d'états au-dessus de la liste,
  tableau à colonnes triables par les titres, fiche (en-tête, résultat d'appel en premier avec touches
  1 à 9, « Enregistrer et client suivant », dossier précédent / suivant, informations en lecture avec
  « Modifier »), états en pastilles douces, mention « Créé par Rafik DAOUD ».
- **Appels manqués à rappeler** (migration 1011, table `easycrm_missed`) : alimentés par le webhook du
  standard `/api/hook/pbx` (tout PBX : Asterisk, Yeastar, 3CX, HTTP) à chaque fin d'appel, client connu
  ou non. Entrant non répondu → épisode par numéro (appels répétés regroupés) ; sortant → tentative
  (clôture « rappelé » s'il est répondu) ; entrant répondu → clôture « le client a rappelé » ; résultat saisi
  sur la fiche → clôture. Attribution et clôture par résultat à chaque consultation et par la tâche
  `SyncMissedCalls`. « Rappeler » passe par le standard s'il est configuré et la fiche connue.
  Carte dans Ma journée, onglet « Appels manqués » pour les responsables. Le standard décide seul si un
  appel est répondu (pas de seuil de durée comme pour la file Yeastar de Call CRM).
- **Session d'appels**, **rappels différés pendant un appel** (lien ☎ ou appel par le standard depuis la
  fiche, saisie en cours, fenêtre ouverte), **objectifs jour / semaine par commercial**
  (`GoalsController`, `/api/goals`), **nouvelle Ma journée**, **réponses WhatsApp rapides** (réglages
  des appels ; pastilles dans la réponse d'une conversation WhatsApp API).

## Non porté (dépend de Yeastar ou du service wa-nextcloud de Call CRM)
Ouverture automatique de la fiche au décroché (événements 30011), onglet Enregistrements et audio sur
le résultat d'appel (détails 30012 et récupération des audios), envoi de modèles WhatsApp approuvés
depuis la fiche (EasyCrm envoie par ses propres connecteurs WhatsApp).

## Tests
Suite complète (`tools/test-nextcloud.sh`), `tests/callcrm-missed-ui.cjs` pour les deux éditions, tests
navigateur `activity-browser` / `documents-browser`. Service des appels manqués EasyCrm exécuté sur une
vraie base Nextcloud (tables Call CRM, transaction annulée) : regroupement, tentatives, clôtures,
numéros internes ignorés, rattachement à la fiche et au commercial.
