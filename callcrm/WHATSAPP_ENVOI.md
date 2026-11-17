# Call CRM 0.34.0 — envoyer des messages WhatsApp depuis le CRM

Le compte WhatsApp déjà configuré (API WhatsApp Cloud de Meta, service local
`wa-nextcloud` sur 127.0.0.1:8085) sert maintenant aussi à **écrire en premier**
à un client, et à le relancer après 24 h.

## Règle WhatsApp (Meta)

- Le client a écrit depuis moins de 24 h → message libre (texte, et fichiers
  dans la conversation).
- Le client n'a jamais écrit, ou plus de 24 h → **modèle approuvé** uniquement.
  Les modèles se créent et se font approuver dans le Gestionnaire WhatsApp de
  Meta (business.facebook.com → WhatsApp Manager → Modèles de message). Le CRM
  affiche ceux dont le statut est APPROVED (liste mise en cache 10 min).

## Dans le CRM

- **Fiche client** : bloc « 💬 WhatsApp » avec le bouton « ✉ Envoyer un message
  WhatsApp », même sans conversation existante. La fenêtre propose le message
  libre si la fenêtre de 24 h est ouverte (un modèle reste possible), sinon le
  choix d'un modèle, ses variables (la première est pré-remplie avec le nom du
  client) et l'aperçu du message. Après l'envoi, la conversation s'ouvre ; elle
  est créée et rattachée à la fiche si besoin.
- **Conversation** dont la fenêtre est fermée : le même choix de modèle remplace
  l'ancien message « réponse impossible » (Appeler et wa.me restent proposés).
- Droits : administrateur, superviseurs, et le commercial **à qui la fiche est
  attribuée**. Lecture seule : rien. Numéro absent ou non algérien : refus.
- Le message envoyé apparaît dans la conversation (« 📋 Modèle « nom » » + texte),
  dans `historique.txt` du dossier WhatsApp du client, et suit les accusés
  (envoyé, remis, lu, échec) comme les autres messages.

Modèles non pris en charge (affichés grisés) : en-tête image, vidéo ou document,
boutons à variable. Les variables : une ligne, 1000 caractères maximum.

## Technique

- CRM : `GET /api/wa/templates`, `POST /api/wa/prospect/{pid}/send`, et
  `POST /api/wa/{id}/send` accepte `template`, `language`, `values`
  (`{header:{…}, body:{…}}`). Nom et langue du modèle validés avant l'appel.
- Service `wa-nextcloud` (`app.py`) : `GET /templates` et `/send` avec
  `template` ; 127.0.0.1 + secret partagé uniquement, comme `/send`. Le jeton
  Meta ne quitte jamais le service.
- Compte WhatsApp Business (WABA) : `WA_WABA_ID` dans `.env`, sinon mémorisé
  automatiquement dans `data/waba_id` au premier webhook reçu (`entry.id`). Le
  jeton actuel ne permet pas de le retrouver par l'API.
- Version passée à 0.34.0 sans fenêtre de maintenance : `installed_version`
  réglé avant le changement de `info.xml` (aucune migration).

## Tests

`tests/callcrm-whatsapp-ui.cjs` (jsdom) : modèles approuvés et non pris en
charge, variables obligatoires, aperçu en texte, charge utile envoyée ; message
libre seulement dans la fenêtre ; bouton réservé au commercial de la fiche, aux
superviseurs et admins ; ouverture de la conversation créée. Contrôleur testé
dans le vrai Nextcloud (client HTTP factice, transaction annulée) : envoi depuis
la fiche, conversation créée et rattachée, refus pour la fiche d'un autre
commercial, modèle/variables invalides, erreur du service, texte libre hors
fenêtre refusé. Construction des modèles du service testée (positionnels,
nommés, en-tête, refus).
