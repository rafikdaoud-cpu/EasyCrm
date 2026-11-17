# Call CRM 0.33.0 — appels en cours, fiche à la prise d'appel, onglet Enregistrements

## Rappels qui ne dérangent plus pendant un appel (0.32.3)

Le popup « ⏰ Rappel client » attend, sans être perdu, tant que le commercial traite
un appel. Le contrôle continue toutes les 30 s et le rappel s'affiche dès la fin.
Le commercial est considéré occupé quand :

- il a lancé un appel (lien ☎ / Yeastar) depuis une fiche encore ouverte, jusqu'à
  l'enregistrement du résultat, avec 20 min au plus ;
- un résultat d'appel ou une autre saisie n'est pas encore enregistré ;
- une notification d'appel Yeastar est affichée et l'appel n'est pas terminé ;
- il crée une fiche (« Nouveau client ») ;
- une autre fenêtre est ouverte (annonce, pause, rendez-vous, simulateur…).

Après l'enregistrement d'un résultat, le rappel dû apparaît environ 1,5 s plus tard.
Le compteur « Rappels en retard » reste visible à tout moment.

## Appel entrant décroché : fiche ouverte ou à créer (0.33.0)

Quand le poste associé au commercial **décroche** un appel entrant (état ANSWER /
ANSWERED reçu par le Webhook 30011) :

- numéro d'une fiche accessible au commercial → la fiche s'ouvre automatiquement,
  une seule fois par appel, et les rappels attendent la fin de l'appel ;
- numéro inconnu du CRM → le formulaire « Nouveau client » s'ouvre avec le numéro
  pré-rempli ; le commercial complète et enregistre (fiche affectée à lui) ;
- numéro existant mais fiche d'un autre commercial ou numéro partagé par plusieurs
  fiches → rien n'est créé (pas de doublon) ; la notification reste affichée ;
- saisie non enregistrée en cours → aucune navigation ; la notification garde son
  bouton « Ouvrir la fiche ».

Les appels sortants ne déclenchent rien. Le champ `known` de
`/api/yeastar/incoming` indique si le numéro existe dans une fiche, quel que soit
son commercial (formats 0…, +213…, 00213… reconnus).

**Prérequis Yeastar** : le Webhook doit envoyer l'événement **30011 — Call State
Changed** en plus du 30012. Au 17/11/2026, le P550 n'envoyait que le 30012 (fin
d'appel) : sans 30011, ni la notification de sonnerie ni l'ouverture automatique
ne peuvent fonctionner. Voir YEASTAR.md, section Webhook.

## Onglet « Enregistrements » (0.33.0)

Visible et autorisé uniquement pour l'administrateur et les superviseurs
(`GET /api/yeastar/recordings/all`, refus 403 sinon). Liste de tous les appels
Yeastar reçus (rapport 30012), 50 par page, du plus récent au plus ancien :

- filtres : recherche (numéro, nom du client, poste), sens, commercial,
  rattachés / à rattacher, période (par défaut aujourd'hui), « seulement avec audio » ;
- par appel : date du standard, sens, statut, durée de conversation, fiche
  rattachée (lien), poste et commercial, état de l'audio ;
- écoute et téléchargement de l'audio (mêmes contrôles que la fiche) ;
- relance d'une récupération en échec, rattachement d'un appel sans fiche.

Les dates filtrées sont celles du standard (`started_at`). Aucune migration.

## Tests

`tests/callcrm-calls-ui.cjs` (jsdom) : ouverture une seule fois par appel, création
proposée seulement pour un numéro inconnu, pas de navigation avec une saisie en
cours ni pour un sortant ; rappel différé dans chaque situation d'occupation puis
affiché après l'enregistrement ; onglet Enregistrements (filtres, pagination,
lien client échappé, audio, rattachement). Recherche SQL et `known()` vérifiées
sur la base réelle du VPS.
