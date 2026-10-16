# EasyCRM pour Odoo 18+

Plateforme de **prospection téléphonique / centre d'appels** pour Odoo (Community ou Enterprise).
Fonctionne seule (dépend uniquement de `base` et `mail`) et ne touche pas au module CRM d'Odoo.

## Fonctions
- **Prospects** : nom, téléphone (normalisé), e-mail, sexe, wilaya/région, catégorie, commercial, statut, rappel, remarque, historique (chatter).
- **Appels** : bouton « Enregistrer l'appel » (réponse, note, date de rappel, durée, enregistrement en pièce jointe) ; « Enregistrer et suivant » ; menu **Appeler le suivant** (rappels dus d'abord, puis nouveaux).
- **Rappels** : activité Odoo planifiée automatiquement + vue calendrier + filtre « Rappels à faire ».
- **Réponses configurables** : en file d'appels ou non, rappel obligatoire, note obligatoire, reprogrammation automatique (ex. injoignable → +3 h), compte comme client / refus.
- **Champs personnalisés** (Configuration → Champs personnalisés) : texte, texte long, nombre, décimal, date, oui/non, liste, choix multiples. Ce sont de vrais champs Odoo : filtres, regroupements, export et import natifs fonctionnent.
- **Équipes et catégories** : chaque équipe gère des catégories ; le commercial voit ses prospects + le pool non attribué des catégories de son équipe.
- **Répartition** : assistant « Répartir les prospects » (par catégorie ou sur une sélection de la liste → *Action → Attribuer / répartir*).
- **Import Excel (.xlsx) / CSV** : colonnes reconnues par leur nom, détection des doublons (base + fichier), numéros normalisés, wilayas ramenées au nom officiel (Algérie), catégories créées au besoin, champs personnalisés remplis.
- **WhatsApp** : bouton qui ouvre `wa.me` avec un message d'accueil.
- **Statistiques** : appels par jour, par heure, par réponse, par commercial, par catégorie ; prospects par catégorie × statut ; export Excel depuis les tableaux croisés.
- **Pays** : Algérie, Maroc, Tunisie, France ou autre (Configuration → Paramètres : indicatif et préfixe national).

## Rôles (Réglages → Utilisateurs → EasyCRM)
| Rôle | Droits |
|---|---|
| Lecture seule | voit tous les prospects et appels, ne modifie rien |
| Commercial | appelle ses prospects, voit ses appels et ses statistiques |
| Superviseur | voit tout, importe, répartit, modifie les prospects et catégories |
| Administrateur | + configuration (réponses, équipes, champs, paramètres) |

## Installation
1. Copier le dossier `easycrm` dans un répertoire de l'`addons_path` d'Odoo.
2. Redémarrer Odoo, activer le mode développeur, *Applications → Mettre à jour la liste des applications*.
3. Installer **EasyCRM** (la bibliothèque `openpyxl`, fournie avec Odoo, lit les .xlsx).
4. *EasyCRM → Configuration* : régler le pays, créer catégories et équipes, affecter les rôles aux utilisateurs.

## Notes
- Interface en français (chaînes standard Odoo, traduisibles par fichiers `.po`).
- Un champ personnalisé ne peut pas changer de type après création (en créer un nouveau).
- Licence OPL-1.

## Telephony & WhatsApp presets (SIP / PBX)

Both editions (Odoo and Nextcloud) ship admin-page presets — pick a provider, the fields are pre-filled:

| Telephony (click-to-call) | How |
|---|---|
| Disabled | smartphone dialer (`tel:`) |
| Softphone link | `tel:` / `sip:` / `callto:` opened by the browser |
| Asterisk / FreePBX / Issabel | AMI `Originate` (rings the agent's extension, then dials the prospect) |
| Yeastar P-Series | OpenAPI `get_token` + `call/dial` |
| 3CX | Call Control API (`/connect/token`, `/callcontrol/{ext}/makecall`) |
| Generic HTTP | any PBX with a REST API: URL / method / headers / body with `{ext}` and `{number}` |

| WhatsApp | How |
|---|---|
| Link (default) | `wa.me` link, message sent by hand |
| Meta Cloud API | official API, free text or approved template |
| 360dialog | WhatsApp Business API |
| Twilio | WhatsApp sender, free text or Content SID |
| Generic HTTP | any gateway: `{number}`, `{text}`, `{name}` |

Each user gets a PBX **extension** (Odoo: user preferences; Nextcloud: Administration → Telephony). A **Test the connection** button checks the credentials. Number format sent to the PBX (national, E.164, `intl`, `00…`) and an outside-line prefix are configurable.

**Webhooks** (secret token generated in the admin page): the PBX posts end-of-call events and the WhatsApp provider posts incoming messages; they are attached to the prospect by the last 8 digits of the phone number and logged (and optionally set a status for answered / missed calls).
- Odoo: `/easycrm/pbx/webhook?token=…`, `/easycrm/wa/webhook?token=…`
- Nextcloud: `/apps/easycrm/api/hook/pbx?token=…`, `/apps/easycrm/api/hook/wa?token=…`

Testing status: unit/integration tests run against **simulated** servers (fake AMI socket, fake Yeastar/3CX/generic HTTP, mocked WhatsApp endpoints). Nothing has been validated against a real PBX or live WhatsApp account yet — try with a test extension first, and expect to tweak vendor-specific details (API paths, versions, templates).
