# Annonces — EasyCrm 1.6.1

L'onglet **Annonce** est visible pour les administrateurs EasyCrm / Nextcloud et les superviseurs. Sélectionner un commercial, plusieurs commerciaux ou **Tous les commerciaux**, saisir le message (2 000 caractères maximum), puis cliquer sur **Envoyer le message**.

Les commerciaux reçoivent un popup avec le nom de l'expéditeur, la date et le texte. Aucun champ de réponse. Le bouton **Fermer** enregistre la fermeture pour ce compte sur le serveur. Si cette opération échoue, le popup reste ouvert avec un message d'erreur pour permettre de réessayer.

La recherche de nouveaux messages a lieu à l'ouverture de EasyCrm, toutes les 15 secondes pendant son utilisation et au retour sur la page. Les messages des commerciaux absents restent en attente. Le navigateur / l'application Android doit afficher EasyCrm : ce mécanisme ne produit pas de notification système lorsque l'application est fermée. Les rappels clients et les annonces partagent le verrou de popup afin de ne pas se superposer.

## Dossiers Clients

Les états des dossiers sont regroupés sous l'onglet principal **Dossiers Clients** : À traiter, À rappeler, les autres états actifs configurés et Tous pour les rôles disposant déjà de cet accès. À l'ouverture de EasyCrm et à chaque clic sur Dossiers Clients, la vue **À traiter** s'ouvre par défaut pour tous les rôles. Les états se sélectionnent dans une barre secondaire ; WhatsApp, Simulateur, Statistiques, Administration et Annonce restent des fonctions séparées selon les droits.

## Mise à jour de l'installation existante

Ce paquet concerne l'application d'identifiant **easycrm**, pas **callcrm**. Aucun changement de l'APK Android n'est requis.

1. Sauvegarder la base Nextcloud et le dossier actuellement installé `custom_apps/easycrm` (ou son emplacement configuré). Comparer les personnalisations locales avec cette version avant remplacement.
2. Pendant une fenêtre de maintenance, remplacer le code de `easycrm` par le dossier fourni et conserver le propriétaire / les permissions utilisés par Nextcloud.
3. Depuis la racine de Nextcloud, exécuter `php occ upgrade` avec l'utilisateur du serveur web pour appliquer la migration et créer `easycrm_announcements`. Par exemple, si cet utilisateur est `www-data` : `sudo -u www-data php occ upgrade`.
4. Recharger EasyCrm dans les navigateurs et dans l'application Android. Vérifier avec un compte superviseur et un compte commercial de test : onglet Annonce, envoi individuel, réception, fermeture puis rechargement ; répéter l'envoi avec tous les commerciaux de test.

Ne pas déployer sans sauvegarde ni remplacer les éventuelles modifications propres au serveur. Cette version a été vérifiée localement ; la migration et les échanges complets doivent encore être validés sur une instance Nextcloud de test. Les annonces fermées restent enregistrées en base ; aucun historique d'administration ni purge automatique n'est ajouté.

## Vérifications locales

PHP 8.1+ avec les extensions `mbstring`, `pdo` et `pdo_sqlite` :

```sh
php nextcloud/tests/announcements.php
```

Le test utilise le vrai contrôleur et le vrai service avec SQLite en mémoire et des doubles des interfaces Nextcloud. Il vérifie les droits, les destinataires, l'isolation des comptes, la fermeture persistante, la validation et l'annulation transactionnelle en cas d'échec. Il ne remplace pas un test sur Nextcloud.

Node.js 18+ ; installer jsdom uniquement dans un dossier temporaire :

```sh
npm install --prefix /tmp/easycrm-ui-tests --no-audit --no-fund jsdom@26.1.0
NODE_PATH=/tmp/easycrm-ui-tests/node_modules node nextcloud/tests/announcements-ui.cjs
node --check nextcloud/js/app.js
```

Les tests d'interface exécutent les fonctions de production dans un DOM simulé : visibilité de l'onglet, sélection individuelle / générale, échec réseau, affichage du texte sans interprétation HTML, succession des popups et fermeture persistante.

La version 0.20.0 ajoute la présence, la pause bloquante et les cumuls de temps des commerciaux ; voir `PRESENCE.md`.

Depuis la version 0.21.0, cliquer sur le nom d’un commercial dans l’onglet Commerciaux ouvre également une fenêtre pour lui envoyer un message individuel.
