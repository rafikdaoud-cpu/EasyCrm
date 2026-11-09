# Réglages Yeastar P550 — Call CRM 0.29.5

## Configuration livrée

Dans **Administration → Téléphonie · Yeastar P550**, un administrateur CRM peut
enregistrer les paramètres suivants :

- Adresse HTTPS du P550, préremplie avec `https://erp.csmtaksit.dz:8088`.
  Utiliser la base du serveur, sans `/integration/api`.
- ID du client API et clé secrète provenant de **Intégrations → API** du P550.
- Adresse HTTPS de Linkus Web, facultative ; ne pas deviner son chemin.
- Associations commerciales : sélectionner un compte du groupe commercial et
  saisir son extension numérique (exemple : `1000`). Les comptes et extensions
  sont uniques. Un compte sorti du groupe doit être retiré de cette liste.

Le bloc conserve les comportements de réduction/agrandissement et le fond des
autres blocs. Les changements non enregistrés participent à l’alerte de navigation.

La clé est chiffrée par Nextcloud ICrypto avant stockage. Elle n'est jamais
renvoyée par l'API des réglages, pas même sous forme chiffrée. Le champ vide conserve
la clé existante ; une case permet de l'effacer. Changer l'adresse du serveur ou
l'ID client supprime la clé précédente, sauf si une nouvelle est saisie au même
enregistrement. Toutes les validations précèdent l'écriture d'une seule valeur
de configuration `callcrm/yeastar_settings`. Les endpoints GET/POST
`/api/yeastar/settings` sont authentifiés, limités au droit `fields` (admin CRM)
et conservent la protection CSRF du framework.

## Limites explicites

Cette livraison prépare la configuration uniquement. Elle n'émet aucune requête
vers le P550 et ne modifie pas les boutons `tel:` existants. Aucun test de connexion,
endpoint Webhook, déclenchement d'appel, événement entrant ou Linkus SDK n'est
implémenté. Ne pas activer de Webhook avec une URL inventée et ne pas considérer
l'enregistrement des paramètres comme une authentification Yeastar réussie.

Le P550 du bureau est derrière FortiGate/box ; l'utilisateur a choisi une
redirection TCP 8088 sans VPN. Limiter sa source à l'IP publique du VPS. Depuis le
VPS, HTTPS a répondu 200 avec `curl -k`, mais la vérification normale a échoué
avec `self signed certificate in certificate chain`. Corriger le certificat et
sa chaîne de confiance avant d'utiliser les identifiants API. Aucun réglage
permettant de désactiver TLS n'est ajouté. L'agent n'a pas de connexion au VPS/P550.

## Suite à développer

Vérifier l'authentification avec ID client/secret et les endpoints exacts dans la
documentation du firmware déployé. La base de connecteur dans
`nextcloud/lib/Service/Telephony.php` ne constitue pas cette vérification et ne
doit pas être copiée aveuglément. La présente livraison ne modifie pas la
téléphonie existante d'EasyCrm.

Avant les premiers appels : intégrer le client HTTP Nextcloud avec validation
TLS, contrôle des destinations/redirections, secret seulement côté serveur,
limitation des droits de l'appelant au dossier et à son extension. Puis ajouter
une réception authentifiée des événements, déduplication, routage par extension,
normalisation des numéros et notification des fiches selon leurs droits courants.
Le son reste assuré par Linkus Web tant que l'intégration SDK n'est pas validée.

## Validation et déploiement

`tools/test-nextcloud.sh` inclut `tests/yeastar-settings.php` (droits, CSRF,
chiffrement, validation sans écriture partielle, changement d'identité, doublons,
appartenance commerciale) et `tests/yeastar-settings-ui.cjs` (chargement,
associations, sauvegarde, champ secret vidé et alerte de saisie).

Publier le bon paquet Call CRM, effectuer la mise à jour Nextcloud sous son
utilisateur habituel puis vérifier la version 0.29.5. Aucune migration SQL ajoutée.
La suite complète `tools/test-nextcloud.sh` a réussi localement pour les deux
éditions, ainsi que les nouveaux tests PHP et jsdom Yeastar.
Les validations locales emploient des doubles Nextcloud et ne remplacent pas une
recette sur l'instance réelle. Aucune configuration ni installation distante
n'est effectuée par le dépôt.
