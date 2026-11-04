# Réception des documents clients et conversion PDF

## Ce qui est disponible

Dans Call CRM 0.27.0 et EasyCrm 1.8.0 : recherche globale, vue À relancer et
pièces jointes par client. Une boîte e-mail peut être créée et configurée plus tard.
L’application n’a besoin d’aucun mot de passe de messagerie pour fonctionner tant
que la collecte e-mail reste désactivée.

Les originaux sont conservés avec le PDF généré. Le stockage est privé à
l’application Nextcloud ; la fiche représente le dossier client. Ce n’est pas un
dossier partagé automatiquement dans l’application Fichiers. Les téléchargements
suivent les droits actuels sur la fiche, même après réaffectation.

## Déploiement

1. Sauvegarder les fichiers de l’application et la base Nextcloud.
2. Installer le ZIP correspondant sans mélanger callcrm et easycrm, ni imbriquer
   deux dossiers d’application. Conserver propriétaire et permissions du serveur.
3. Exécuter `occ upgrade` avec l’utilisateur Nextcloud et le PHP habituel du site.
   Nouvelles migrations : Call CRM 1014 / EasyCrm 1010, table documents et index
   distincts. Vérifier `needsDbUpgrade: false` avant de quitter la maintenance.
4. Vérifier que le cron Nextcloud s’exécute toutes les cinq minutes. Le job
   CollectDocuments est inscrit par l’application et s’exécute via ce cron.
5. Vérifier les dépendances indiquées dans Administration → Réception des pièces.

Sur le VPS communiqué, diagnostic sans modification :

```bash
runuser -u csmtak11 -- /opt/cpanel/ea-php85/root/usr/bin/php -r 'foreach (["imap","gd","fileinfo"] as $e) { echo $e . ": " . (extension_loaded($e) ? "disponible" : "absent") . PHP_EOL; }'
test -x /usr/bin/libreoffice && /usr/bin/libreoffice --version
crontab -u csmtak11 -l
```

Installer les composants manquants selon le système et la configuration cPanel :
IMAP pour le PHP web **et** celui du cron, GD pour les images hors JPEG, LibreOffice
headless à `/usr/bin/libreoffice` pour les formats Office. IMAP n’est plus fourni
directement avec PHP depuis PHP 8.4 : utiliser l’extension compatible avec le PHP
réel du serveur. Ne pas supposer qu’installer un module pour un autre PHP suffit.
Le PHP du cron doit autoriser `proc_open` pour lancer LibreOffice. Pour les ajouts
manuels, ajuster aussi upload_max_filesize/post_max_size du PHP web à au moins
25 Mo (avec une marge pour le corps HTTP). Le ZIP ne contient pas ces programmes. Un composant absent est signalé ; l’original
reste disponible et la conversion peut être relancée après installation.

## Configurer la future boîte e-mail

Dans Administration, ouvrir **Réception des pièces · WhatsApp et e-mail** :

- Serveur IMAP, par exemple `mail.votre-domaine.dz` (sans `https://`).
- Adresse/utilisateur de la boîte dédiée.
- Mot de passe ; il est stocké chiffré par Nextcloud. Ne pas le transmettre dans
  une conversation ni le placer dans le dépôt. Vide conserve le mot de passe
  existant ; changement de serveur/utilisateur impose de le ressaisir.
- Dossier à surveiller, par exemple INBOX ou un dossier dédié créé dans la boîte.
- Port 993 et SSL/TLS, ou port 143 et STARTTLS selon le serveur. Le certificat
  doit être valide pour le nom de serveur choisi.

Enregistrer puis utiliser **Tester la connexion enregistrée**. Activer la collecte
uniquement après création effective de la boîte et réussite du test. La dernière
relève et les erreurs sont affichées dans les réglages.

La première relève commence au début du dossier, avec dix messages maximum par
passage. Pour ne traiter que les nouvelles pièces, utiliser un dossier de collecte
vide au départ et une règle de messagerie qui y classe les messages entrants.
Les messages sont lus sans suppression et sans modification de leur état lu.
Un changement de compte/dossier réinitialise la progression pour cette nouvelle
source. Les reprises sur le même message ne créent pas de doublons de pièces.

Renseigner le nouveau champ intégré **E-mail du client** sur les fiches. Si un seul
client correspond à l’expéditeur, ses pièces sont classées dans sa fiche. Si aucun
client ou plusieurs clients correspondent, elles restent **À rattacher**. L’adresse
d’expéditeur sert au rapprochement ; cette fonction ne constitue pas une vérification
d’identité du client. Les anciens champs personnalisés e-mail ne sont pas déplacés
ni fusionnés automatiquement avec le nouveau champ intégré.

## WhatsApp

La réception existante doit déjà enregistrer les médias dans Nextcloud. Le réglage
`wa_files_user` doit désigner le compte de stockage et les fichiers doivent être
sous `WhatsApp/`. Le collecteur enregistre les messages entrants ayant une pièce,
par lots de trente, puis copie l’original dans le registre documentaire privé.
Les conversations connues permettent le rattachement au dossier ; un téléphone
ambigu laisse la pièce dans la file de la direction.

Les anciennes pièces WhatsApp sont également parcourues au démarrage ; le rattrapage
et les conversions peuvent prendre plusieurs passages si l’historique est volumineux.
Les messages vocaux et vidéos sont conservés en original, sans conversion PDF.

## Conversion et traitement quotidien

- Limite : 25 Mo par fichier original et par PDF généré.
- JPEG : conversion native en PDF A4, sans LibreOffice ni GD.
- PNG, GIF, WebP et autres images : selon les formats supportés par le GD du serveur.
  Des limites de dimensions et de mémoire empêchent des décodages trop lourds.
- PDF reçu : disponible en original et en PDF.
- DOC, DOCX, ODT, RTF, TXT, XLS, XLSX, ODS, PPT, PPTX, ODP : conversion par LibreOffice,
  avec profil temporaire et délai de 90 secondes.
- Formats non pris en charge : original conservé et état explicite. Les photos
  HEIC ou formats exotiques peuvent nécessiter une conversion préalable.

Trois conversions maximum par passage du cron. L’état apparaît dans la fiche :
en attente, en cours, PDF disponible ou erreur. Cliquer **Actualiser** pour voir
le résultat, et **Réessayer la conversion** après correction d’un problème.
La conversion n’effectue pas de reconnaissance de texte (OCR).

Si un message e-mail est trop gros ou mal formé, la relève s’arrête avec une erreur
visible pour ne pas perdre silencieusement une pièce. Récupérer manuellement son
original puis déplacer ce message hors du dossier surveillé pour reprendre la collecte.

## Pièces à rattacher et gestion des accès

Admin/superviseur : menu **Pièces reçues**, recherche du client puis rattachement
explicite. Commercial : consultation/téléchargement et ajout de pièces sur ses
fiches uniquement. Configuration de la boîte réservée à l’admin. Les documents
originaux ne sont jamais envoyés au navigateur d’un autre commercial.

La suppression d’une fiche place ses pièces dans la file de la direction, comme
annoncé dans la confirmation ; elle ne supprime ni les originaux de messagerie ni
les pièces de la source WhatsApp. Pas de purge automatique du registre : prévoir
une politique de conservation adaptée à l’activité.

## Recherche et relances

La recherche globale est accessible dans l’en-tête et le menu Recherche : nom,
prénom, téléphone, e-mail, commentaires, dernière note d’appel et nom de commercial.
La recherche par nom de commercial est proposée aux profils ayant accès à tous les
clients ; un commercial reste limité à ses dossiers.

À relancer propose : sans appel depuis N jours, rappels dépassés, dernière estimation
ancienne sans appel ultérieur. Le seuil de jours s’applique aux appels/devis ; les
rappels dépassés sont calculés à l’instant courant. Aucun transfert automatique.
Les actions ouvrent la fiche ou déclenchent l’appel ; les réaffectations utilisent
les contrôles existants de la fiche et de l’administration.

## Vérification avant activation réelle

Tester sur une copie Nextcloud : pièce JPEG et DOCX fictives, original intact,
PDF ouvrable, collecte IMAP avec un message déjà lu, doublons de réception,
expéditeur inconnu/ambigu, rattachement manuel et réaffectation de la fiche.
Contrôler qu’un autre commercial reçoit un refus au téléchargement. Les tests
locaux livrés utilisent SQLite, des fichiers de test et une API de démonstration ;
ils ne remplacent pas un essai IMAP/LibreOffice/Nextcloud sur votre serveur.
