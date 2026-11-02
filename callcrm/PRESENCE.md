# Présence, thème et pauses — Call CRM 0.25.0

## Interface validée

- **Admin / superviseur** : navigation latérale, chiffres clés de l’équipe et tableau clair. Sur chaque ligne, le commercial conserve son total traité et tous ses compteurs par état (y compris les zéros). Un clic sur le nom ouvre le message individuel.
- **Commercial** : la page **Ma journée** s’ouvre à la connexion. Elle affiche le temps en poste, les pauses classiques, le déjeuner, les rappels en retard, les dossiers traités et les appels du jour. Le bouton principal ouvre les dossiers **À traiter**. Les détails et filtres restent accessibles dans **Mes statistiques**.
- **Dossiers Clients** ouvre toujours **À traiter** lorsqu’on clique dessus. Les autres états restent accessibles selon les droits. WhatsApp, annonces, statistiques et fonctions propres à chaque édition sont conservés.
- Sur mobile, la navigation devient une barre défilable et les blocs se réorganisent. Les tableaux larges défilent dans leur propre zone.

## Présence

Un signal est envoyé toutes les 30 secondes. Sans signal pendant 120 secondes, le commercial passe **Absent**. Les états et rapports s’actualisent toutes les 15 secondes lorsque leur page est visible. L’état actuel reste indépendant de la période choisie pour les cumuls.

Cela indique une connexion à la page CRM et non une présence physique devant le PC. Si la page reste ouverte quand le commercial s’éloigne, il doit se mettre en pause. Mise en veille, navigateur ralenti, expiration de session ou perte réseau peuvent provoquer l’absence. Après une fermeture non détectable immédiatement, au plus 120 secondes de présence supplémentaire peuvent être comptées.

## Pause classique

Le bouton **Me mettre en pause** ouvre un écran bloquant avec chronomètre. Échap et un clic à l’extérieur ne permettent pas de le fermer. **Reprendre le travail** nécessite la confirmation du serveur.

Après **10 minutes strictement dépassées**, l’état apparaît **rouge** dans le tableau admin / superviseur, accompagné du texte « délai dépassé ». À 10 minutes exactement, il reste dans la limite. Le retour au poste reste manuel : aucune reprise automatique n’est imposée.

## Pause déjeuner — règles finales

- Bouton distinct **Pause déjeuner**, même écran bloquant et chronomètre.
- **Une seule activation par jour civil**, dans le fuseau configuré du CRM (`Africa/Algiers` par défaut). Le serveur refuse une seconde activation ; le bouton reste désactivé après la reprise.
- Pause non fractionnable et non cumulable : une partie inutilisée n’est pas récupérable lors d’une seconde pause ni reportée au lendemain.
- Durée prévue : **1 heure**. Au-delà, **10 minutes de tolérance** : état orange « tolérance » de plus de 1 h à 1 h 10 incluses. Au-delà de **1 h 10**, état **rouge** « délai dépassé » pour les responsables.
- Le déjeuner est enregistré dans un compteur **Déjeuner séparé**. Il n’augmente ni le temps en poste, ni le compteur de **pause classique**. Les éventuels dépassements restent dans le temps de déjeuner.
- La reprise reste manuelle. Un clic répété pendant le même déjeuner ne crée pas une deuxième pause et ne remet pas son heure de début à zéro.

La pause est partagée entre les onglets/appareils. Un simple signal, un rechargement ou une reconnexion ne remettent pas son délai à zéro. Le serveur conserve l’heure de début pour les alertes. Les intervalles d’absence restent exclus des temps de présence mesurés ; le délai de dépassement, lui, se réfère à l’heure initiale de la pause. Le chronomètre local se suspend lorsque le signal est trop ancien puis se resynchronise au retour.

## Périodes, dossiers et tris

**Jour / Semaine / Mois / Du–Au** sont disponibles dans Commerciaux et Mes statistiques. Jour = aujourd’hui ; semaine = lundi à aujourd’hui ; mois = premier à aujourd’hui. L’intervalle manuel inclut la journée Au entière, dans le fuseau du CRM.

La même période s’applique aux temps en poste, pauses classiques, déjeuners, dossiers traités et répartition par état. Un dossier est compté **une fois par commercial**, selon son **dernier résultat d’appel sur la période**. Tous les résultats configurés ou présents dans l’historique sont affichés, même à zéro. Un dossier traité par deux commerciaux compte pour chacun ; le total équipe additionne leurs volumes.

Le tableau distinct **Résultats d’appels par type de réponse** dans Statistiques compte chaque résultat d’appel, sans dédupliquer les dossiers. Les filtres du journal des appels restent indépendants de ceux des temps.

Cliquer sur un titre de colonne trie, cliquer à nouveau inverse le sens ; ▲ / ▼ montrent le tri actif. Les libellés dans l’en-tête du détail des états permettent le tri par réponse. Le commercial peut aussi trier son tableau État / Nombre.

## Installation

Sauvegarder la base et le code, vérifier les personnalisations, puis remplacer `custom_apps/callcrm` par le dossier du ZIP. Le chemin de l’instance communiqué est `/home/csmtak11/cloud.csmtaksit.dz/custom_apps/callcrm`.

```sh
cd /home/csmtak11/cloud.csmtaksit.dz
runuser -u csmtak11 -- php occ upgrade
runuser -u csmtak11 -- php occ status
```

Attendre la fin. Si la mise à jour a réussi, `needsDbUpgrade: false`, mais `maintenance: true` :

```sh
runuser -u csmtak11 -- php occ maintenance:mode --off
```

Recharger les navigateurs (Ctrl+F5) et l’application Android. Aucun nouvel APK n’est nécessaire.

Les migrations existantes restent inchangées : 1009 annonces ; 1010 présence/historique ; 1011 type de pause. La **nouvelle migration 1012** ajoute `pause_started_at` et `lunch_day`. Lancer `occ upgrade` avant le nouveau JavaScript. Les lignes de déjeuner déjà clôturées aujourd’hui peuvent être retrouvées dans l’historique afin d’éviter une deuxième activation après cette mise à jour.

Les temps sont collectés depuis l’installation du suivi ; ils ne sont pas inventés pour les périodes antérieures. L’historique d’appels existant peut fournir d’anciens nombres de dossiers. Les pauses anciennes sans type enregistré ne peuvent pas être reclassées automatiquement en déjeuner.

## Vérifications

Depuis le dépôt, `tools/test-nextcloud.sh` teste les deux variantes. Voir `docs/AI_HANDOFF.md` pour les dépendances PHP/Node/jsdom et les limites. Les tests isolés ne remplacent pas une installation Nextcloud de préproduction.

Un test navigateur facultatif avec données fictives couvre ordinateur/mobile, navigation, ouverture d’À traiter, alertes rouges, déjeuner bloquant et bouton déjeuner désactivé après reprise :

```sh
CHROMIUM_BIN=/chemin/vers/chromium python3 tests/browser-smoke.py /tmp/crm-captures
```

Il nécessite Playwright Python et Chromium. Il ne se connecte pas au VPS. Le déploiement réel et les intégrations Nextcloud/PBX/WhatsApp restent à vérifier sur l’instance cible.
