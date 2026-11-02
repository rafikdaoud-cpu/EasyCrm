# Présence des commerciaux — Call CRM 0.23.0

## Utilisation

- Pour le commercial : état affiché dans la navigation et bouton **Me mettre en pause**. Un écran modal bloque alors la vue et affiche le chronomètre ; seul **Reprendre le travail** permet de revenir au CRM. Échap et le clic à l’extérieur ne ferment pas la pause. Le rechargement restaure la pause depuis le serveur. En cas d’échec réseau, l’écran reste bloqué jusqu’à confirmation de la reprise.
- Pour l'administrateur et le superviseur : onglet **Commerciaux**, affichant le nom, l'état et la dernière présence reçue dans Call CRM. Actualisation toutes les 15 secondes tant que cet onglet est visible.
- **En poste** : un signal du compte a été reçu depuis moins de 120 secondes.
- **En pause** : le compte répond et le commercial a activé sa pause.
- **Absent** : jamais connecté à Call CRM ou aucun signal depuis au moins 120 secondes.

Call CRM envoie un signal toutes les 30 secondes. La fermeture de la page, la déconnexion Nextcloud, la perte de réseau ou la mise en veille entraînent l'absence après expiration de ce délai, puis au prochain rafraîchissement du superviseur. Les navigateurs peuvent ralentir les onglets en arrière-plan ; un compte peut alors apparaître absent. Le retour sur la page envoie immédiatement un signal.

Ce mécanisme mesure la connexion à Call CRM et ne détecte pas physiquement la présence devant l'écran. Aucun suivi du clavier, de la souris ou de l'activité du poste n'est ajouté. Si la page reste ouverte quand le commercial s'éloigne, il doit activer la pause. La connexion à une autre application Nextcloud ne suffit pas à signaler la présence dans Call CRM.

La pause est partagée entre les onglets et appareils du même compte. Un simple signal ne l'annule pas. Après une coupure, elle reste activée jusqu'à **Reprendre le travail**. Si le réseau échoue pendant un changement, l'interface indique **État non confirmé** au lieu d'annoncer une réussite.

## Périodes, dossiers traités et tris

L’admin et le superviseur disposent du tableau **Commerciaux**. Le commercial retrouve ses propres indicateurs dans **Mes statistiques**. Les deux vues proposent le filtre :

- **Jour** : aujourd’hui depuis minuit ;
- **Semaine** : depuis le lundi de la semaine en cours ;
- **Mois** : depuis le premier du mois en cours ;
- **Du / Au** : intervalle manuel, les deux dates incluses. Cliquer sur Appliquer.

Le fuseau est celui configuré dans Call CRM, `Africa/Algiers` par défaut. Le même intervalle s’applique aux temps en poste, temps de pause, dossiers traités et à leur répartition par état. Les dates invalides ou inversées sont refusées par le serveur. Les filtres du journal des appels restent indépendants.

**Dossiers traités** : nombre de dossiers distincts pour lesquels le commercial a enregistré un résultat d’appel pendant la période. Plusieurs appels sur un même dossier ne le comptent pas plusieurs fois. La répartition (À rappeler, Intéressé, Pas intéressé, etc.) utilise le dernier résultat de ce commercial sur ce dossier dans l’intervalle ; il ne s’agit pas de son éventuel état actuel après d’autres traitements hors période. Les résultats simultanés sont départagés par l’identifiant d’enregistrement. La somme des états est égale au nombre de dossiers traités. Un dossier traité par deux commerciaux compte une fois pour chacun ; le total équipe additionne leurs volumes.

Le tableau superviseur se trie en cliquant sur les titres de colonnes : commercial, état actuel, pause en cours, temps en poste, temps de pause, dossiers traités ou un état de dossier. Un premier clic trie en ordre croissant ; un second inverse le sens. Les flèches ▲ / ▼ indiquent le tri actif. Chaque état de dossier possède sa propre colonne chiffrée. Les menus de tri sont supprimés. Le tri est conservé lors des rafraîchissements. Le commercial clique sur les titres État ou Nombre dans son tableau personnel pour trier dans les deux sens. Les noms restent cliquables pour envoyer un message individuel.

Le tableau montre toujours **l’état actuel** des commerciaux, même si les cumuls sont filtrés sur une période ancienne. Le tableau et les compteurs personnels s’actualisent toutes les 15 secondes sur leur page visible ; le chronomètre de pause s’actualise chaque seconde. Une erreur réseau masque les anciens compteurs plutôt que de les présenter comme actuels.

Les temps sont affichés en heures:minutes:secondes, sans remise à zéro après 24 heures. Les changements d’état et les intervalles sont enregistrés côté serveur. Un compte n’a qu’un compteur, même avec plusieurs onglets. Une pause reste activée sur les autres appareils et est synchronisée au prochain signal, normalement sous 30 secondes. Une reprise est effective après confirmation serveur.

En cas de perte de signal, la présence est prolongée pendant le délai de tolérance de 120 secondes, puis le temps d’absence est exclu des cumuls. Il peut donc y avoir jusqu’à deux minutes de présence comptée après une fermeture ou une coupure. Le chronomètre se suspend au-delà de cette tolérance si la connexion ne revient pas. Au retour après une absence, un nouveau segment commence : une pause toujours activée reprend avec un nouveau chronomètre, sans ajouter l’absence au total de pause. Les durées mesurent la présence déclarée dans Call CRM, pas un contrôle physique du travail.

Le commercial ne peut accéder qu’à ses propres indicateurs. Son identité est imposée par le serveur et ne dépend pas des paramètres transmis par le navigateur.

## Message direct depuis Commerciaux

L’admin et le superviseur peuvent cliquer sur le nom d’un commercial dans **Commerciaux** pour ouvrir une fenêtre de rédaction qui lui est exclusivement destinée. Le message utilise le même mécanisme popup sans réponse que l’onglet Annonce. Un échec réseau conserve le brouillon ; une réussite désactive l’envoi pour éviter un double clic. L’onglet Annonce reste disponible pour les envois multiples ou à tous.

## Mise à jour

Le ZIP contient le dossier `callcrm` complet, les annonces et la navigation Dossiers Clients des versions précédentes. Sauvegarder le code installé et la base avant remplacement. Le chemin communiqué pour cette installation est :

`/home/csmtak11/cloud.csmtaksit.dz/custom_apps/callcrm`

Après installation du code, depuis la racine Nextcloud :

```sh
cd /home/csmtak11/cloud.csmtaksit.dz
runuser -u csmtak11 -- php occ upgrade
runuser -u csmtak11 -- php occ status
```

Attendre la fin de la mise à jour. Si `needsDbUpgrade: false` et que la commande a réussi mais que `maintenance: true`, désactiver la maintenance :

```sh
runuser -u csmtak11 -- php occ maintenance:mode --off
```

Conserver les propriétaire et permissions de l'installation. Recharger les pages et l'application Android pour charger le nouveau JavaScript. La migration 1010 crée les tables `callcrm_presence` et `callcrm_presence_log`. La migration des annonces reste incluse pour les installations venant d’une version antérieure. Les durées ne sont collectées qu’à partir de l’installation de la fonction de suivi ; aucun historique de temps ancien n’est reconstitué. Les dossiers traités utilisent l’historique d’appels conservé en base, y compris antérieur à cette version. Aucune nouvelle migration depuis la version 0.20.0 n’est nécessaire ; lancer occ upgrade pour enregistrer la version applicative reste requis.

Cette version n'a pas été déployée sur le VPS. Vérifier sur une instance de test avec deux comptes : connexion commercial, pause, reprise, fermeture de tous les onglets du compte, absence après deux minutes et retour. Tester aussi plusieurs onglets et une perte réseau.

## Tests locaux

Avec PHP et les extensions mbstring, PDO et pdo_sqlite :

```sh
php callcrm/tests/presence.php
```

Ce script exécute aussi les tests des annonces. Les tests utilisent les vrais services et contrôleurs avec des doubles Nextcloud ; ils ne remplacent pas un test d'intégration sur Nextcloud.

Pour les tests DOM Node.js, voir `ANNONCES.md`. Le fichier `tests/announcements-ui.cjs` couvre également Dossiers Clients et la présence : droits d'affichage, pause / reprise, erreur réseau et liste des commerciaux.

## Résultats d’appels

Le tableau « Résultats d’appels par type de réponse » des Statistiques affiche toutes les réponses configurées (y compris celles à zéro) et les anciennes réponses présentes dans l’historique. Il compte les résultats d’appels, avec des colonnes triables par leur titre. Il est distinct du nombre de dossiers traités, qui déduplique les dossiers sur la période.
