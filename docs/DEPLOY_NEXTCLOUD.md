# Déployer Call CRM / EasyCrm

Mise à jour du 2026-11-10 : **Call CRM 0.30.2**, connecteur Yeastar entrant/sortant,
avec migration SQL additive 1016. EasyCrm reste en 1.8.0. Voir [YEASTAR.md](YEASTAR.md).
Après installation et `occ upgrade`, ouvrir Administration → Téléphonie · Yeastar
P550 et saisir les paramètres. Activer l’intégration, tester l’API et configurer le Webhook signé selon YEASTAR.md.

Versions de ce lot : **Call CRM 0.25.1** et **EasyCrm 1.6.1**. Les deux applications sont indépendantes : ni les tables, ni les rôles, ni les préférences ne sont partagés.

## Préparer et vérifier

1. Lire `AI_HANDOFF.md`, relever la version actuellement installée et comparer le code du serveur aux modifications locales éventuelles. Conserver ces personnalisations ; ne pas écraser un serveur sans les vérifier.
2. Tester le paquet sur une instance Nextcloud de préproduction, en particulier les migrations avec la même base que la production. Les tests isolés locaux ne remplacent pas cette étape.
3. Sauvegarder ensemble la base Nextcloud et le code des applications. Vérifier comment restaurer la sauvegarde.
4. Depuis le dépôt : `python3 tools/package-nextcloud.py /chemin/vers/les-paquets`. Les SHA256 sont écrits à côté des ZIPs.

| ZIP | Dossier racine dans le ZIP | Destination |
|---|---|---|
| callcrm-0.30.2.zip | callcrm/ | custom_apps/callcrm/ |
| easycrm-1.8.0.zip | easycrm/ | custom_apps/easycrm/ |

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


## Simulateur et historique (2026-11-03)
Call CRM 0.25.4 : le simulateur reste ouvert au clic extérieur, à Échap et après sauvegarde ; fermeture par la croix seulement. Depuis la fiche, Enregistrer dans la note du client cible le client courant. Depuis le menu, rechercher puis choisir un client accessible avant sauvegarde. Bouton bloqué pendant la requête et après sauvegarde de la même estimation ; une modification permet une nouvelle sauvegarde. Dimensions invalides bloquées, erreurs affichées sans vider le formulaire.
Le serveur recalcule les prix et produit le résumé en texte brut, délimité par un astérisque littéral au début et à la fin. Il ajoute ce bloc à `remarque` (commentaire de la fiche), en conservant les textes précédents. L’audit de simulation et la note sont transactionnels. Une comparaison de la note et de l’attribution empêche de remplacer une modification détectée entre lecture et écriture ; conflit HTTP 409. Aucun droit ajouté : sauvegarde réservée aux rôles déjà autorisés à appeler, avec périmètre client serveur et CSRF conservés.
La fiche ouverte reprend le résumé dans son champ commentaire sans jeter les saisies locales. L’historique de simulation est conservé pour la prochaine ouverture de la fiche. Aucun enregistrement automatique à la fermeture. Une réponse perdue après une écriture réussie reste ambiguë : vérifier la note avant de réessayer, pas d’idempotence serveur inter-session ajoutée.
Call CRM 0.25.4 et EasyCrm 1.6.4 : les entrées d’historique d’appel n’affichent plus `user_id`, pour tous les résultats (y compris personnalisés). Résultat, date/heure, motif, note et rappel éventuel restent affichés ; les données et l’auteur restent en base pour audit. Notes multilignes préservées à l’écran. Le simulateur de cuisine est spécifique à Call CRM et n’existe pas dans cette édition EasyCrm : aucune copie intégrale ni nouveau simulateur n’y a été ajouté.
Validation locale : suite `tools/test-nextcloud.sh`, quatre parcours UI supplémentaires (deux éditions d’historique, simulateur fiche, simulateur global/échec réseau) et huit contrôles PHP/SQLite supplémentaires (résumé, ajout à note, audit, rollback, attribution/concurrence et note vide). Pas de déploiement VPS ni de recette intégrée Nextcloud. Aucune migration nouvelle.


## Agenda et fiche client validés (2026-11-03)
Call CRM 0.26.0 / EasyCrm 1.7.0 : onglet Agenda (Aujourd’hui, Demain, En retard), rappels provenant des statuts callback configurés, nouveaux RDV avec révision serveur, filtres type/recherche/commercial et pagination bornée. Les compteurs utilisent le jour civil du fuseau CRM (y compris changement d’heure). Créer les RDV depuis la fiche client ; l’agenda propose ouverture directe et appel, et modification/clôture des RDV. Le périmètre commercial est imposé côté serveur ; lecture seule sans écriture ; admin et superviseur peuvent gérer les RDV accessibles.
Chronologie paginée mélange appels historiques, simulations existantes et nouveaux événements de note, affectation et RDV. Curseur stable même pour plusieurs événements à la même seconde, noms résolus via Nextcloud ; compte disparu affiché Ancien utilisateur. Les notes de fiche et affectations sont désormais tracées transactionnellement (attribution directe, transfert, répartition/réattribution et affectation lors d’un changement de statut en masse). Les anciens changements de notes et transferts non tracés par client ne peuvent pas être reconstitués ; ne pas inventer des événements historiques. L’historique d’audit existant est conservé.
Motifs propres à chaque réponse, boutons de sélection dans la fiche et listes modifiables dans Administration / Réponses d’appel (un motif par ligne, 30 motifs maximum, 100 caractères chacun). Le serveur refuse un motif d’un autre résultat. Le caractère obligatoire reste celui du paramétrage existant. Les anciennes listes globales personnalisées de refus sont préservées à la mise à jour ; les autres réponses reçoivent des suggestions spécifiques. Le champ global historique reste pour compatibilité, mais éditer désormais les motifs par réponse.
Fiche smartphone : coordonnées secondaires et RDV repliables, chronologie prioritaire et barre fixe Appeler / WhatsApp / Enregistrer. Enregistrer sauvegarde le résultat d’appel sélectionné, sinon les informations de fiche. Les appels hors ligne existants sont toujours mis en file locale. Alerte de navigation interne avec Rester / Quitter sans enregistrer pour les informations, résultat d’appel et simulation (simulateur Call CRM uniquement). Fermeture de simulation avec modification non sauvée demande confirmation. Fermeture ou actualisation du navigateur : avertissement natif, au texte décidé par le navigateur. Les champs restent en mémoire, pas d’autosauvegarde ni de restauration après fermeture.
Nouvelles migrations additives : Call CRM 1013 / EasyCrm 1009, tables client_events et appointments ; aucune ancienne migration réécrite. Installer le bon ZIP, sauvegarder base/code, puis lancer `occ upgrade` sous l’utilisateur Nextcloud. Vérifier needsDbUpgrade=false avant de désactiver la maintenance. Ne pas installer sur le VPS sans accès réel et opération explicitement effectuée.
Validation : suite PHP/SQLite + jsdom existante et tests client-activity dans les deux éditions (périmètres, motifs serveur, transitions/RDV, rollback, auteur supprimé, pagination avec événements simultanés, bornes jour/DST). tests/activity-browser.cjs exécute les deux applications dans Chromium avec API fictive, vérifie agenda, fiche, chronologie, barre mobile, sélection de motifs et prévention de perte de saisie ; captures réelles du code générées dans les artefacts, pas du VPS. Pas de recette intégrée Nextcloud/LDAP/PBX et pas de test de charge concurrente. La pagination de l’agenda utilise un décalage et peut bouger si de nouvelles tâches sont ajoutées entre deux pages ; actualiser la liste.


## Icônes du menu (2026-11-03)
Call CRM 0.26.1 / EasyCrm 1.7.1 : icônes SVG locales pour Ma journée, Agenda, Dossiers Clients, WhatsApp, Simulateur (Call CRM), Statistiques, Administration, Annonce et Commerciaux. Traits currentColor pour suivre la couleur active ; tailles desktop/mobile adaptées ; SVG décoratifs aria-hidden et libellés conservés. Aucune dépendance externe et aucun changement de droits ou de migration.


## Réception des pièces — 0.27.0 / 1.8.0
Nouvelles migrations 1014 / 1010 : sauvegarder code/base, installer le ZIP correspondant puis exécuter occ upgrade sous l’utilisateur Nextcloud. La boîte se définit ultérieurement dans Administration → Réception des pièces. Consulter [DOCUMENTS.md](DOCUMENTS.md) pour le cron, les extensions PHP IMAP/GD et LibreOffice, puis tester la connexion et un document fictif avant activation réelle. Aucune configuration ni installation VPS n’a été faite par l’agent.
