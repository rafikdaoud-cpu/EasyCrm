# EasyCrm

Mini CRM et centre d'appels pour Nextcloud : prospection téléphonique, suivi des appels et des rappels, statistiques, import Excel.

Licence : AGPL-3.0 (voir `LICENSE`). Le fichier `js/xlsx.js` (SheetJS) est sous licence Apache-2.0 (`js/xlsx.LICENSE.txt`).

## Fonctionnalités

- Liste de prospects : pagination (10 / 20 / 30), recherche, tri, filtres par statut et par commercial.
- Fiche client : appel et WhatsApp en un clic, historique des appels, notes, rappels avec alerte.
- Import Excel (.xlsx, .xls) ou CSV : nettoyage, suppression des doublons de numéros, correction des numéros et des valeurs de listes.
- Réponses d'appel (statuts) configurables : libellé, couleur, rappel obligatoire, note obligatoire, activation, ordre.
- Champs de la fiche configurables : texte, nombre, date, e-mail, oui/non, liste, liste à choix multiples. Seul « Nom et prénom » est toujours obligatoire.
- Équipes et catégories : répartition automatique des prospects (par catégorie, équitable, ou vers un groupe précis) et réaffectation des prospects orphelins.
- Statistiques : prospects par statut, appels par heure, appels par commercial, journal des appels paginé avec recherche et tri, export Excel.
- Rôles : administrateur, superviseur, commercial, lecture seule.
- Invitation des commerciaux par e-mail.
- Saisie des appels différée si la connexion est coupée (envoi automatique au retour du réseau).
- Enregistrements d'appels (envoi par une application mobile via l'API).

## Installation

Prérequis : Nextcloud 28 à 40, PHP 8.1 ou plus.

1. Décompressez `easycrm.zip` dans le dossier `apps` (ou `custom_apps`) de Nextcloud : le dossier doit s'appeler `easycrm`.
2. Donnez les droits au propriétaire du serveur web : `chown -R <utilisateur>:<groupe> easycrm`.
3. Activez l'application : `occ app:enable easycrm` (ou Administration → Applications).

L'activation crée les tables `easycrm_*` et les quatre groupes d'accès `easycrm_admin`, `easycrm_superviseur`, `easycrm_commercial`, `easycrm_lecture`.

## Premiers pas (menu EasyCrm → Administration)

1. **Général** : nom de l'entreprise, pays (format des téléphones et de WhatsApp : Algérie, Maroc, Tunisie, France, ou autre avec indicatif), et groupes d'accès. Chaque rôle peut utiliser un groupe Nextcloud existant ou un groupe de votre annuaire LDAP / AD.
2. **Équipes et catégories** : créez vos équipes (un groupe de commerciaux chacune) et rattachez-leur vos catégories de produits ou de services.
3. **Champs de la fiche client** : renommez, masquez, ajoutez des champs, choisissez leur type.
4. **Réponses d'appel / statuts** : adaptez les réponses proposées au commercial.
5. Ajoutez les utilisateurs aux groupes : chaque commercial dans le groupe « Commerciaux » et dans le groupe de son équipe.
6. **Importer des prospects** : choisissez le fichier et le mode de répartition.
7. Option : **Inviter les commerciaux par e-mail** (nécessite un serveur de messagerie configuré dans Nextcloud).

## Boîte WhatsApp partagée (1.3.0)

Disponible dès qu'un fournisseur WhatsApp est configuré (Administration → Téléphonie : Meta Cloud API, 360dialog, Twilio ou API HTTP) et que le webhook WhatsApp affiché dans l'administration est déclaré chez le fournisseur.

- **Menu « 💬 WhatsApp »** : une conversation par numéro client, badge des messages non lus, recherche.
- **Attribution automatique** : client connu (fiche avec commercial) → ce commercial, la conversation suit la fiche en cas de transfert ;
  numéro inconnu ou fiche non assignée → **file d'attente** du superviseur, qui attribue ou crée la fiche en un clic.
- **Réponse depuis le CRM** dans la fenêtre de 24 h de WhatsApp : texte, photos (JPG/PNG) et documents (PDF, Word, Excel, PowerPoint)
  — fichiers : Meta Cloud API et 360dialog ; texte seul : Twilio et API HTTP. Au-delà de 24 h : appel ou lien WhatsApp.
- Photos, PDF, vocaux et vidéos reçus affichés dans la conversation (stockés dans les données de l'application, dossier `wa`).
- Accusés ✓ envoyé, ✓✓ remis, ✓✓ bleu lu, ⚠ échec (Meta / 360dialog : champ `messages` ; Twilio : « Status callback URL » = webhook WhatsApp).
- Meta en coexistence (appli WhatsApp Business + API) : abonnez aussi le champ `smb_message_echoes` pour voir dans le CRM les messages envoyés depuis le téléphone.
- Le bouton « Envoyer un WhatsApp » de la fiche est aussi enregistré dans la conversation.

## Rôles

| Rôle | Appeler | Voir tous les prospects | Importer, répartir | Administration |
|---|---|---|---|---|
| Administrateur (admin Nextcloud ou groupe `easycrm_admin`) | oui | oui | oui | oui |
| Superviseur | non | oui | oui | consultation |
| Commercial | oui (ses prospects) | non | non | non |
| Lecture seule | non | oui | non | non |

Les commerciaux ne voient que leurs prospects et leurs propres statistiques.

## Format d'import

Colonnes, dans cet ordre : Nom et prénom ; Téléphone ; Région ; Catégorie ; Profession ; Revenu ; Commentaire (les libellés suivent vos réglages). Pour un fichier Excel, les colonnes sont reconnues par leur titre, y compris celles de vos champs personnalisés. La première ligne (en-tête) est ignorée. Les numéros invalides ou déjà présents ne sont pas importés ; les valeurs de liste non reconnues sont recopiées dans le commentaire.

## Sauvegarde et désinstallation

Sauvegardez la base de données Nextcloud : les données se trouvent dans les tables `easycrm_prospects`, `easycrm_calls` et `easycrm_recordings`, et les enregistrements audio dans le stockage de l'application. La désinstallation (`occ app:remove easycrm`) ne supprime pas les tables : supprimez-les vous-même si vous le souhaitez.

## Limites de cette version

- Interface en français uniquement.
- Aucune application Android n'est fournie ; l'API d'envoi des enregistrements est disponible (`POST /apps/easycrm/api/recordings`).
- Les listes (région, catégories) sont des listes de choix : pas de hiérarchie.

---

## English summary

EasyCrm is a lightweight CRM / call-center app for Nextcloud (AGPL-3.0): prospect lists with pagination, search and sorting; Excel/CSV import with de-duplication; one-tap call and WhatsApp; configurable call outcomes and customer-record fields; teams and categories with automatic lead distribution; roles (admin, supervisor, sales agent, read-only) mapped to Nextcloud or LDAP groups; statistics and call log; offline-tolerant call logging. Install by unzipping into `apps/easycrm`, then `occ app:enable easycrm`, and configure everything under EasyCrm → Administration. The interface is currently French only.

## WhatsApp shared inbox (1.3.0)

One conversation per customer number; known customers go to the salesperson of their record, unknown numbers to the supervisor's queue. Salespeople reply from the CRM within WhatsApp's 24-hour window (text, photos and documents with Meta Cloud API / 360dialog). Delivery receipts and received media are shown in the thread.

## Languages
The interface is available in French, English, Arabic (right-to-left), Spanish, Italian and German. The language follows the Nextcloud user language (unsupported languages fall back to English). Translations live in `js/i18n.js` (UI) and `lib/Service/Tr.php` (Excel export headers). Machine-quality translations: have a native speaker review them before a commercial release.

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
