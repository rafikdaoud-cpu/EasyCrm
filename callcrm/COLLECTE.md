# Lien de dépôt de documents pour le client (Call CRM 0.29.0)

## Parcours

1. **Commercial** — fiche client, carte « Documents à demander au client » →
   « 📨 Envoyer le lien de documents au client ». Il ne choisit ni la liste ni la durée :
   l'administrateur les fixe (voir plus bas). Le lien s'affiche une seule fois, avec
   « Copier », « WhatsApp », « SMS » et « E-mail ». Créer un nouveau lien annule le
   précédent encore ouvert ; « Annuler le lien » le désactive.
2. **Client** — ouvre le lien sur son téléphone, sans compte. Il voit la liste et
   « 📷 Scanner mes documents » : caméra plein écran, photo automatique quand le document
   est détecté (OpenCV, détection rapide en attendant le chargement), redressement,
   aperçu, « ✅ Envoyer et suivant », document suivant. « 🖼️ Galerie / PDF » permet aussi
   d'envoyer des photos existantes (assemblées en PDF sur le téléphone) ou un PDF.
   « 📤 Envoyer et terminer » est actif quand les documents obligatoires sont envoyés.
3. **Arrivée immédiate** (depuis 0.29.0) — chaque document est livré **dès que le client
   l'envoie** (« ✅ Envoyer et suivant » ou Galerie / PDF), sans attendre la fin : copié
   dans les **Fichiers Nextcloud du commercial actuel de la fiche** (à défaut, celui qui a
   créé le lien) :
   `Documents clients/<Nom Prénom> (n°ID)/<date du lien>/01 - <document>.pdf`, et ajouté aux
   « Pièces jointes du client ». Refaire un document remplace le fichier ; « Retirer » le
   supprime du dossier (la pièce reste dans la fiche). Si la fiche est réaffectée en cours
   de route, les documents suivants vont au nouveau commercial. La chronologie indique
   « Le client a commencé à envoyer ses documents », puis la fin. « ✅ Terminer » ne fait que
   clôturer le lien (documents obligatoires présents) ; un client qui abandonne en cours de
   route a déjà transmis ce qu'il a envoyé.

L'administrateur seul règle la liste des documents (obligatoires ou facultatifs, 30 au
maximum) et la validité des liens (1 à 30 jours, 3 par défaut) dans Administration →
« Demande de documents au client · liste et validité ». Le serveur ignore toute liste ou
durée envoyée par un autre utilisateur ; les superviseurs et commerciaux ne voient pas ces
réglages. Depuis 0.28.1, la carte « Rendez-vous » n'est plus affichée sur la fiche client
(les rendez-vous existants et l'Agenda sont conservés).

## Sécurité

- Jeton aléatoire de 32 caractères, stocké uniquement haché (SHA-256) ; le lien n'est
  affiché qu'à la création. Expiration, annulation, et fermeture après l'envoi final.
- La page publique n'affiche ni le nom du client ni aucune donnée de la fiche.
- Jetons invalides ralentis par la protection anti-force brute de Nextcloud
  (`BruteForceProtection`) et limitation des requêtes anonymes (`AnonRateLimit`).
- Contenu réel contrôlé côté serveur : PDF complet, JPEG ou PNG ; 20 Mo par document,
  150 Mo par lien. Les dépôts restent dans les données privées de l'application tant que
  le client n'a pas terminé ; la tâche de fond supprime ceux des liens expirés.
- Côté CRM : création, liste et annulation suivent les droits de la fiche (commercial
  affecté, superviseur, administrateur) avec protection CSRF ; lecture seule = consultation.
- La page publique autorise la caméra (`camera 'self'`) et un Web Worker du même serveur
  (`worker-src 'self'`). Depuis 0.28.2, OpenCV.js tourne dans ce worker
  (`js/docscan-worker.js`, fichier statique) : la page n'autorise ni eval ni WASM. Si le
  serveur web ajoute une CSP aux fichiers .js, l'écran caméra affiche « CSP des fichiers .js ».

## Déploiement

Migration `Version1015Date20261105100000` (tables `callcrm_collect_links`,
`callcrm_collect_files`) appliquée par `occ upgrade`. Fichiers ajoutés : `js/vendor/opencv.js`
(OpenCV.js 4.10, 10,4 Mo, Apache-2.0, chargé seulement par la page client),
`js/docscan.js`, `js/scanpdf.js`, `js/collect.js`, `css/collect.css`, `templates/collect.php`.
PHP web : `upload_max_filesize` et `post_max_size` ≥ 20 Mo ; proxy web idem
(`client_max_body_size`). Le cron Nextcloud doit tourner (nettoyage des liens expirés).

## Tests

`tools/test-nextcloud.sh` inclut `tests/collect.php` (43 contrôles : réglages, droits,
jeton haché, remplacement, contrôle de contenu, documents obligatoires, dossier du
commercial actuel, nettoyage, attributs de sécurité). Navigateur (séparé) :
`CHROMIUM_BIN=… python3 tests/collect-browser.py /tmp/collect-previews` — page client à
360 et 390 px, fausse caméra, scan guidé OpenCV, galerie, envoi final, API simulée.
Non vérifié : instance Nextcloud réelle (écriture dans les Fichiers, en-têtes CSP),
vrais téléphones Android/iPhone, interface CRM de la fiche dans un navigateur.
