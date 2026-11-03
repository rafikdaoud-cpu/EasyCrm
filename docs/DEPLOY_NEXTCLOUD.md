# Déployer Call CRM / EasyCrm

Versions de ce lot : **Call CRM 0.25.1** et **EasyCrm 1.6.1**. Les deux applications sont indépendantes : ni les tables, ni les rôles, ni les préférences ne sont partagés.

## Préparer et vérifier

1. Lire `AI_HANDOFF.md`, relever la version actuellement installée et comparer le code du serveur aux modifications locales éventuelles. Conserver ces personnalisations ; ne pas écraser un serveur sans les vérifier.
2. Tester le paquet sur une instance Nextcloud de préproduction, en particulier les migrations avec la même base que la production. Les tests isolés locaux ne remplacent pas cette étape.
3. Sauvegarder ensemble la base Nextcloud et le code des applications. Vérifier comment restaurer la sauvegarde.
4. Depuis le dépôt : `python3 tools/package-nextcloud.py /chemin/vers/les-paquets`. Les SHA256 sont écrits à côté des ZIPs.

| ZIP | Dossier racine dans le ZIP | Destination |
|---|---|---|
| callcrm-0.25.3.zip | callcrm/ | custom_apps/callcrm/ |
| easycrm-1.6.3.zip | easycrm/ | custom_apps/easycrm/ |

Ne pas créer `callcrm/callcrm` ou `easycrm/easycrm`. Ne pas renommer les identifiants des applications pour les faire correspondre à un dossier. Le dossier Git `nextcloud/` contient l'application d'identifiant `easycrm` et le script l'archive correctement.

## Appliquer

Pendant une fenêtre de maintenance, avec l'interpréteur PHP et l'utilisateur habituels de Nextcloud :

```sh
cd /chemin/vers/nextcloud
php occ config:app:get callcrm installed_version
php occ config:app:get easycrm installed_version
php occ maintenance:mode --on
```

Remplacer le code de l'application concernée par le paquet vérifié, conserver le propriétaire et les permissions, puis exécuter :

```sh
php occ upgrade
php occ status
```

Attendre la fin complète. En cas d'erreur, conserver la sortie, inspecter le journal Nextcloud et ne pas forcer la suppression de la maintenance. Si l'upgrade a réussi et `needsDbUpgrade: false`, désactiver la maintenance si elle reste activée :

```sh
php occ maintenance:mode --off
```

Recontrôler installed_version, puis recharger les navigateurs (Ctrl+F5) et l'application Android. Ce lot ne nécessite pas de nouveau binaire Android.

Pour l'instance CSM décrite par l'utilisateur, depuis `/home/csmtak11/cloud.csmtaksit.dz`, la forme déjà utilisée est `runuser -u csmtak11 -- php occ …`. Ce chemin et cet utilisateur sont spécifiques à cette installation ; ne pas les appliquer à EasyCrm ailleurs. Le dépôt ne fournit pas un accès SSH au VPS.

## Vérification fonctionnelle

Avec des comptes de test commercial / superviseur / administrateur, contrôler :

- Dossiers Clients ouvre À traiter, autres états accessibles selon les rôles.
- Annonce visible aux responsables, envoi individuel et général, popup persistant sans réponse ; message direct depuis le nom d'un commercial.
- Présence, pause bloquante, chronomètre, reprise confirmée et restauration après rechargement. Fermer tous les onglets du compte et vérifier l'absence après deux minutes.
- Temps personnels sans visibilité sur les autres comptes, comptes multiples / plusieurs onglets sans double comptage.
- Filtres Jour / Semaine / Mois / Du–Au, journée Au incluse, tris par titres conservés après rafraîchissement.
- Dossiers distincts par dernier résultat sur l'intervalle ; toutes les réponses configurées visibles. Dans Statistiques, le tableau Résultats d'appels compte chaque résultat d'appel, contrairement aux dossiers distincts. Vérifier À rappeler (occupé), Intéressé, Ne répond pas, Non éligible, Pas intéressé et Transmis sur Plateforme si ces statuts sont configurés.
- Appels, import/export, PBX, WhatsApp et langues de l'installation existante toujours opérationnels.

## Revenir en arrière

Une baisse du numéro de version n'annule pas les migrations. En cas de problème, remettre l'instance en maintenance puis restaurer une sauvegarde cohérente de code ET de base selon le plan validé. Ne pas supprimer les tables manuellement pour « réessayer » : cela détruirait les annonces et l'historique des temps. Conserver une copie de l'état défaillant pour le diagnostic avant toute restauration.

La version Pause déjeuner nécessite les migrations additives Call CRM 1011 / EasyCrm 1007 (`pause_kind` dans l’état courant et l’historique). Même depuis le paquet précédent, exécuter `occ upgrade` avant de charger le nouveau JavaScript.

Pour 0.25.1 / 1.6.1, appliquer aussi Call CRM 1012 / EasyCrm 1008 avant le nouveau JavaScript : conservation de l’heure initiale de pause et quota déjeuner quotidien. Le déjeuner est maintenant un total séparé. Vérifier qu’un second déjeuner est refusé (HTTP 409), les seuils exacts de 10 min et 70 min, et le nouveau point d’entrée Ma journée pour les commerciaux.

## Reprise après l’échec de migration lunch_day

Sur Nextcloud 35, la définition initiale NOT NULL avec valeur par défaut vide était refusée. Le correctif 0.25.1 / 1.6.1 utilise NULL et autorise la colonne nullable. Remplacer le paquet ou la migration échouée (Call CRM 1012 / EasyCrm 1008), puis relancer `occ upgrade`. Ne pas sauter la migration ni supprimer les tables. Garder la maintenance jusqu’à la réussite et `needsDbUpgrade: false`.


## Transfert vers Non assigné (2026-11-03)
Call CRM 0.25.2 / EasyCrm 1.6.2 : destination explicite Non assigné dans le transfert administratif et la sélection de prospects. Le marqueur API `__none` est converti en SQL NULL ; une destination vide reste refusée. Droits assign inchangés, statut et quantité conservés, refus source/destination identiques, audit lisible. Aucun déploiement VPS effectué.


## Blocs d’administration repliables (2026-11-03)
Call CRM 0.25.3 / EasyCrm 1.6.3 : chaque carte d’option possède un en-tête cliquable pour agrandir/réduire, avec commandes globales Tout réduire / Tout agrandir. Fond gris clair par bloc, champs conservés en blanc. Le repli ne supprime pas les champs ni leurs valeurs non enregistrées ; il ne les enregistre pas automatiquement. Préférence locale au navigateur, isolée par application et identifiant utilisateur, tous ouverts à la première visite. Le repli fonctionne même si le stockage local est indisponible. Les formulaires chargés de façon asynchrone (simulateur) gardent leur bloc. Aucun changement de droits ou de schéma, aucun déploiement VPS.
