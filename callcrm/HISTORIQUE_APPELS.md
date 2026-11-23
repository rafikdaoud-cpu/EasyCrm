# Fiches partagées et historique des appels dans Call CRM 0.56.0

## Accès à une fiche après un appel

Lorsqu’un commercial décroche un appel Yeastar entrant, Call CRM ouvre automatiquement la fiche si le numéro correspond à une fiche unique et si l’interface peut quitter la page en cours. Lorsque la fiche est attribuée à un autre commercial, le décrochage accorde au commercial qui a répondu un accès permanent à cette fiche. Le titulaire conserve son affectation. Plusieurs commerciaux peuvent obtenir l’accès en répondant à des appels successifs.

Le serveur exige un événement Yeastar signé de type 30011, un état `ANSWER` ou `ANSWERED` et un poste associé au commercial. Une sonnerie, un appel sortant ou un numéro associé à plusieurs fiches ne déclenche aucun partage. Un utilisateur sans droit de consultation ne peut pas utiliser un accès partagé. La migration 1023 crée la table de partage et son index.

Les commerciaux qui ont accès retrouvent la fiche dans la liste et la recherche. Ils peuvent modifier les informations, ajouter une note et consigner un résultat d’appel. Les pièces jointes, les fonctions WhatsApp et l’administration des affectations utilisent leurs droits habituels. Une fiche modifiée entre-temps doit être rechargée avant d’enregistrer un formulaire devenu obsolète.

## Chronologie du client

Le partage apparaît avec le commercial qui a répondu, la date, l’heure et la référence de l’appel Yeastar. La chronologie consigne les notes ajoutées et les modifications de champs avec leur auteur et leur horodatage. Les modifications indiquent les valeurs avant et après. Les filtres **Modifications** et **Accès partagés** permettent de retrouver ces événements. Le champ **Note au dossier** ajoute une note à la chronologie et conserve les notes précédentes.

## Menu Historique des appels

- Chaque commercial voit ses appels entrants décrochés et ses appels sortants enregistrés par Yeastar.
- Les administrateurs et superviseurs voient l’ensemble des appels, y compris les appels sans commercial identifié. Ils disposent du filtre et de la colonne **Commercial**.
- Les filtres couvrent les dates de début et de fin, le client, le téléphone, le sens et le statut. Cliquez sur un en-tête pour trier la liste complète. Le tableau affiche 50 lignes par page et permet d’ouvrir une fiche accessible.
- La source est le détail d’appel Yeastar, transmis par l’événement signé 30012. Les appels sans fichier audio sont inclus. Après le passage dans une file, Call CRM peut identifier le poste à partir du nom du fichier audio ou d’un décrochage signé associé au même appel. Un appel historique sans commercial identifié reste visible dans l’historique des responsables. Les appels sans détail transmis par Yeastar et les partages antérieurs à la version 0.56.0 ne font pas l’objet d’une reprise automatique.

## Mise à jour et validation

Installer Call CRM 0.56.0 et exécuter la migration via `occ upgrade` selon la procédure de déploiement. Vérifier les abonnements Yeastar aux événements 30011 et 30012 et l’association entre les postes et les comptes des commerciaux.

En préproduction, faire appeler un client de test attribué à un premier commercial. Laisser sonner sans décrocher, puis faire décrocher un deuxième commercial et vérifier l’ouverture de la fiche, sa présence dans la recherche des deux comptes et l’absence d’accès pour un troisième compte. Ajouter une note et modifier un champ depuis les deux comptes, puis contrôler la chronologie. Vérifier les appels entrants et sortants, y compris un appel de file sans audio, puis contrôler les filtres et le tri en tant que commercial, superviseur et administrateur. Utiliser un standard Yeastar de test pour ces vérifications.
