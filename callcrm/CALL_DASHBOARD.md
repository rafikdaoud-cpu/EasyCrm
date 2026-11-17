## Call CRM 0.32.1 — lisibilité des statistiques

Boutons de période explicites, dates personnalisées avec aide, tableau regroupé
en cinq colonnes et blocs verticaux sur petit écran. Résultats conservés sur la
ligne du commercial. Libellé des suivis ouverts clarifié : ce ne sont pas des
appels perdus. Calculs, droits et routage inchangés ; aucune migration nouvelle.
Validation locale PHP/jsdom ; aucune installation distante effectuée.

# Call CRM 0.32.0 — performance et suivi entrant

Dans Statistiques, un nouveau tableau associe les dossiers traités, leurs derniers
résultats, les temps de présence/pause/déjeuner et les appels Yeastar par commercial.
Les résultats restent sur la ligne de chaque commercial. Jour, semaine, mois ou
dates personnalisées ; filtres distincts des rapports historiques situés plus bas.

Les responsables voient l'équipe et la liste des entrants à examiner. Ils peuvent
marquer un suivi comme traité ou le rouvrir. La liste possède une recherche par
numéro, un filtre ouvert/traité/tous et une pagination de 20 lignes. Le bouton
Ouvrir le dossier permet la prise en charge et le rappel avec les actions existantes.
Les numéros sans dossier unique restent dans le circuit de rattachement des audios.

## Définitions et limites

- Résultats : un dossier distinct par commercial, dernier résultat de la période.
  Un dossier traité par plusieurs commerciaux apparaît chez chacun.
- Appels : détails 30012 signés déjà reçus dans le CRM, classés par **date de
  réception CRM**, dans le fuseau configuré. Pas de réimport de l'historique PBX.
- Regroupement exact par origine PBX, call_id, sens et numéro externe. Les doublons
  de détail/audio ne gonflent pas les comptes. Aucun rapprochement approximatif
  entre deux call_id différents, même si le numéro est identique.
- Réponse agent : statut ANSWERED sur un poste associé à un utilisateur lors de
  la réception. Un SVI ou une file non associés ne sont jamais une réponse agent.
  Ne pas associer les numéros SVI/files à un compte commercial dans le CRM.
- Par agent : chaque groupe d'appel une fois ; durée maximale reçue dans ce groupe
  et pour cet agent. Les transferts peuvent compter pour plusieurs agents. Ce
  calcul évite le doublonnage mais ne reconstitue pas tous les segments de transfert.
- Sans réponse : aucun agent associé n'a ANSWERED dans les détails de la période.
  SVI/non associé : aucun agent associé n'est identifié. Ce sont des **situations à
  vérifier**, pas un taux certifié d'appels perdus : événements absents, tardifs,
  identifiants différents ou changements de période peuvent empêcher la corrélation.
- Traité : validation manuelle, auteur et date conservés. N'atteste ni un rappel
  effectué ni une conversation réussie. Aucun résultat commercial n'est modifié.
- Plus de 10 000 détails sur la période : message demandant une période plus courte,
  jamais de chiffres silencieusement tronqués. Les agents historiques sortis du
  groupe commercial n'apparaissent pas dans le tableau du groupe actuel.
- La présence CRM ne représente pas l'état Linkus ni la disponibilité de la file.

## Accès

Admin/superviseur : équipe, entrants, numéros inconnus et modification du suivi.
Commercial : ses agrégats uniquement ; détails entrants uniquement lorsqu'un seul
dossier est identifié et lui est actuellement affecté. La réaffectation retire
immédiatement cet accès. Lecture/aucun rôle : accès refusé au nouveau tableau.
Les appels d'autres agents peuvent servir côté serveur à déterminer si un appel
de son dossier a été pris ; aucune statistique personnelle de ces agents n'est renvoyée.
Session et CSRF Nextcloud restent requis. Les liens audio gardent leurs contrôles.

## Installation et recette

Remplacer le code de callcrm après sauvegarde, lancer `occ upgrade` avec le compte
et le PHP habituels de Nextcloud. Migration additive 1018 : table du suivi et index
de réception ; les migrations déjà livrées sont conservées. Vérifier `occ status`,
puis recharger le navigateur. L'intégration/audio 0.31.0 est incluse sans changement
de ses paramètres. Aucune configuration de routage Yeastar n'est modifiée.

Recette : SVI seul ; entrée sans réponse ; appel répondu par un autre agent de la
file ; sortie répondue ; marquer traité/rouvrir ; tester un commercial distinct et
une réaffectation. Comparer des appels connus avec le journal Yeastar. Les tests
locaux SQLite et jsdom ne remplacent pas cette recette sur le P550/Nextcloud réel.

Pour les appels entrants collectifs : commerciaux dans les files 6400/6401 selon
les langues souhaitées, poste Linkus enregistré et agent non en pause. Le compte
administrateur 1029 est exclu de la réception à la demande de l'utilisateur.
Le CRM ne route pas automatiquement selon le propriétaire du dossier et ne crée
pas automatiquement de prospect pour un numéro inconnu dans cette version.

Références :
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/api-interfaces-and-events-summary.html
- https://help.yeastar.com/en/p-series-appliance-edition/contact-center-guide/create-a-queue.html
