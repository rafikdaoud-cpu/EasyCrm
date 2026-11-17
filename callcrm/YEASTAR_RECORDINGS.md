# Call CRM 0.31.0 — récupération automatique des enregistrements

## Fonctionnement

Le P550 enregistre sur le partage SMB Windows du LAN. À la fin de l'appel, il
envoie un Webhook signé 30012 à Call CRM sur le VPS. Call CRM mémorise les détails
de l'appel et met l'audio en file de récupération. Le cron Nextcloud demande le
fichier via l'API HTTPS du P550, puis en conserve une copie privée dans Nextcloud.
L'audio apparaît dans « Appels et enregistrements Yeastar » sur la fiche client.
Les boutons Écouter et Télécharger utilisent cette copie, sans communiquer de
jeton API au navigateur. Le partage Windows reste la source du PBX.

Stockage : IAppData Call CRM, sous-dossier configurable (par défaut `yeastar-recordings`), fichiers nommés
par une empreinte interne stable. Le classement par fiche est enregistré en base,
dans `callcrm_pbx_recordings`. Il s'agit du stockage privé de l'application : les
audios ne sont pas des fichiers personnels visibles dans l'application Fichiers.
Le responsable voit les appels non rattachés dans Administration → Audios Yeastar
à rattacher ; il peut indiquer l'identifiant du bon dossier et confirmer le lien.
Le rattachement automatique exige une correspondance téléphonique unique
(variantes algériennes nationales/internationales). Aucun dossier n'est créé.

## Installation sur le VPS

1. Sauvegarder le dossier de l'application et la base Nextcloud. Vérifier que la
   version déployée correspond au code fourni et conserver toute adaptation locale.
2. Extraire `callcrm-0.31.0.zip`, puis remplacer le code dans
   `/home/csmtak11/cloud.csmtaksit.dz/custom_apps/callcrm` par le contenu du dossier
   `callcrm` de l'archive. Éviter un sous-dossier `callcrm/callcrm`.
3. Depuis la racine Nextcloud, lancer `occ upgrade` avec l'utilisateur et le PHP
   habituels, puis `occ status`. Exemple à adapter à l'hébergement :

   ```sh
   cd /home/csmtak11/cloud.csmtaksit.dz
   runuser -u csmtak11 -- /opt/cpanel/ea-php85/root/usr/bin/php occ upgrade
   runuser -u csmtak11 -- /opt/cpanel/ea-php85/root/usr/bin/php occ status
   ```

   La migration additive 1017 crée la table des appels/audio. Aucune ancienne
   migration n'est modifiée. Attendre `needsDbUpgrade: false`.
4. Vérifier dans les paramètres d'administration Nextcloud que les tâches de fond
   utilisent **Cron**, exécuté régulièrement par le serveur (habituellement toutes
   les 5 minutes). Ne pas ajouter un second cron s'il existe déjà. Exemple :

   ```cron
   */5 * * * * /opt/cpanel/ea-php85/root/usr/bin/php -f /home/csmtak11/cloud.csmtaksit.dz/cron.php
   ```

   Cette ligne doit s'exécuter sous le compte propriétaire de Nextcloud. La tâche
   `OCA\CallCrm\BackgroundJob\CollectYeastarRecordings` est enregistrée par
   l'installation/mise à jour via `appinfo/info.xml`.
5. Dans Call CRM → Administration → Téléphonie, conserver les réglages API et
   les associations commercial/extension. L'intégration doit rester activée.
   Cocher **Récupérer automatiquement les audios dans Nextcloud**, choisir le
   dossier privé et la taille maximale (1 à 64 Mio), puis enregistrer. La collecte
   est désactivée par défaut ; la connexion API existante est conservée.
   Les appels reçus restent en attente jusqu'à activation de la collecte.
   Changer le dossier concerne les nouveaux appels et ne déplace pas les anciens
   fichiers. Une ancienne référence conserve son dossier d'origine.
6. Dans Yeastar → Intégrations → API → Push Webhook, utiliser l'adresse déjà
   affichée par Call CRM. Ajouter **30012 — Call End Details Notification** à la
   destination POST existante, en conservant **30011**. Garder le même secret
   Webhook dans les deux applications. Tester la destination et appliquer.
7. Vérifier que l'identité API Yeastar peut accéder aux enregistrements et que
   l'enregistrement reste activé sur les extensions souhaitées.

## Flux réseau / FortiGate

| Source | Destination | Usage |
|---|---|---|
| IP publique du VPS | Adresse HTTPS publique du P550 et son port API, actuellement indiqué dans le dépôt comme `erp.csmtaksit.dz:8088` | Authentification API, demande puis téléchargement audio |
| P550 sur le LAN | `cloud.csmtaksit.dz`, TCP 443 | Webhooks signés 30011 et 30012 |
| P550 sur le LAN | Serveur Windows `192.168.110.251`, TCP 445 | Écriture/lecture du partage SMB |

Si l'API utilise une redirection NAT/VIP sur le FortiGate, restreindre la règle
entrante à l'IP publique de sortie du VPS et au port HTTPS du P550. Vérifier
l'adresse effectivement enregistrée dans Call CRM : le dépôt ne prouve pas la
configuration actuelle. Nextcloud doit accéder tant à `/openapi/v1.0/...` qu'à
`/api/download/...` ou `/api/downloadattachment/...` sur cette même origine.
Le téléchargement est effectué par le VPS ; les PC des commerciaux n'ont pas
besoin d'accéder à ces adresses. Aucun accès SMB depuis Internet n'est nécessaire.
Un certificat HTTPS valide est préférable ; l'exception autosignée préexistante
est conservée si elle a été explicitement activée dans les réglages.

## Recette après installation

- Appeler un client connu depuis Call CRM et vérifier l'audio sur le partage LAN.
- Vérifier l'arrivée du CDR dans « Appels et enregistrements Yeastar ».
- Après le passage du cron, cliquer Actualiser : l'état doit devenir « Audio
  enregistré dans Nextcloud ». Écouter les deux interlocuteurs et télécharger.
- Réaffecter un dossier de test : l'ancien commercial perd l'accès, le nouveau
  peut écouter. Les responsables y ont accès ; le rôle lecture seule n'y a pas accès.
- Pour un numéro inconnu ou ambigu, vérifier la liste Administration → Audios
  Yeastar à rattacher. Confirmer le bon dossier à l'aide de son identifiant.

## Reprises, limites et exploitation

- Sans cron, les audios restent en attente, même si le Webhook arrive.
- Cinquante téléchargements maximum par passage, budget de trois minutes avant
  de commencer un autre fichier ; intervalle minimum de la tâche :
  60 secondes, cadence réelle dépendant du cron Nextcloud et de sa charge.
- Douze essais automatiques, avec délai croissant plafonné à une heure. Après
  échec, un responsable peut utiliser « Réessayer la récupération » sur la fiche
  ou dans la liste non rattachée. Les erreurs ne divulguent pas les jetons.
- Changer l'adresse du P550 ne redirige pas les anciens téléchargements vers un
  autre serveur. Ils restent en erreur jusqu'au rétablissement de leur origine.
- WAV et MP3, maximum 64 Mio par fichier. Une réponse HTML/JSON ou trop volumineuse
  est refusée. Le téléchargement utilise le mode synchrone de l'API ; le mode
  asynchrone Yeastar 30033 n'est pas pris en charge par cette version.
- Seuls les nouveaux CDR 30012 effectivement reçus sont traités. Aucun import
  rétroactif des fichiers déjà présents sur le partage et aucune reconstruction
  des Webhooks perdus. Configurer les reprises de livraison côté Yeastar.
- Appels externes entrants/sortants. Le poste provient du CDR : en cas de file
  d'attente ou transfert, il peut désigner une destination plutôt qu'un commercial.
  Le commercial n'est renseigné que si cette extension a une association connue.
- Aucun résultat commercial ni statistique métier n'est modifié automatiquement.
- La copie privée est conservée si le PBX supprime ensuite son fichier. Pas de
  purge automatique de ces copies dans cette version : surveiller l'espace VPS et
  inclure les données d'application et la base dans les sauvegardes Nextcloud.
- La suppression d'un dossier détache ses audios vers la liste des responsables.
- Le bouton Actualiser recharge les états ; pas de rafraîchissement automatique
  pendant la lecture. L'audio est servi en réponse complète, sans navigation HTTP
  par plages garantie pour les longs fichiers.

## Validation

Tests automatisés locaux avec SQLite, HTTP et stockage Nextcloud simulés : CDR
signés, classement unique/ambigu, téléchargement, reprise, expiration de verrou,
échec de stockage, droits après réaffectation, chemins malveillants, réponses non
audio, limite de taille et interface de lecture/téléchargement. La migration est
testée pour sa répétabilité. Validation réelle sur le VPS/P550 à effectuer après
installation ; aucun déploiement ni appel réel n'a été effectué par l'agent.
Le script Bash `tools/test-nextcloud.sh` n'a pas pu démarrer sur cet hôte Windows
(MSYS : accès refusé). Ses commandes PHP/Node ont été exécutées depuis PowerShell,
avec les mêmes suites pour les deux éditions ; les journaux restent dans `work/`.

Sources :
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/event-new-cdr.html
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/download-a-recording-file.html
