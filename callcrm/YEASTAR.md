# Yeastar P550 — Call CRM 0.30.0

## Installation et configuration

Cette version ajoute le raccordement OpenAPI v1.0 au P550, les appels sortants,
la réception signée des appels entrants, les notifications CRM et un journal
technique par client. Linkus Web assure le son : garder son onglet connecté à
l’extension associée au commercial (poste de test utilisateur : 1029).

1. Sauvegarder code et base Nextcloud puis installer le paquet `callcrm-0.30.0.zip`
   dans le dossier `custom_apps/callcrm` (pas de sous-dossier callcrm/callcrm).
2. Depuis la racine Nextcloud, lancer `occ upgrade` avec le PHP et l’utilisateur
   habituels, par exemple :
   ```sh
   runuser -u csmtak11 -- /opt/cpanel/ea-php85/root/usr/bin/php occ upgrade
   runuser -u csmtak11 -- /opt/cpanel/ea-php85/root/usr/bin/php occ status
   ```
   Migration additive 1016 : table `callcrm_pbx_events`. Ne désactiver la maintenance
   qu’après succès et `needsDbUpgrade: false`. Aucune ancienne migration modifiée.
3. Administration → Téléphonie · Yeastar P550 : vérifier l’adresse
   `https://erp.csmtaksit.dz:8088`, l’ID client et la clé API déjà enregistrés.
4. Cocher « Autoriser le certificat autosigné » si nécessaire. Cette exception
   désactive la vérification d’identité pour le P550 seulement ; HTTPS reste requis.
   Limiter la redirection TCP 8088 à l’IP du VPS sur le FortiGate. Ne pas modifier
   les vérifications des autres services. Décocher après correction du certificat.
5. Vérifier l’association du compte CRM à son extension (1029), puis renseigner
   l’adresse complète réelle de Linkus Web si souhaité. Aucun chemin Linkus n’est
   deviné. Cocher « Activer les appels Yeastar », enregistrer puis cliquer sur
   **Tester la connexion Yeastar**. Le test utilise les réglages enregistrés.
6. Dans une fiche client accessible, cliquer sur **Appeler**. Le P550 fait sonner
   l’extension : **répondre dans Linkus Web** pour poursuivre l’appel vers le client.
   L’auto-réponse n’est pas forcée et les permissions de sortie restent celles
   du P550. Les boutons tel: des fiches, agenda et WhatsApp ouverts dans Call CRM
   sont interceptés lorsque Yeastar est activé. Intégration désactivée : liens
   tel: habituels. Un numéro inconnu, partagé par plusieurs fiches ou inaccessible
   est refusé ; les boutons de numéros WhatsApp sans fiche ne lancent pas d’appel.

## Appels entrants : Webhook signé

Dans les réglages Call CRM, copier l’adresse « Adresse à copier dans le P550 ».
Adresse attendue, selon la configuration Nextcloud :
`https://cloud.csmtaksit.dz/index.php/apps/callcrm/api/yeastar/webhook`.
Utiliser **l’adresse réellement affichée**, dont index.php peut être absent.

Dans le P550, Intégrations → API :

1. Activer la surveillance de statut des appels des extensions concernées, ainsi
   que celle des trunks recevant les appels externes.
2. Activer Push Webhook et créer une destination vers l’adresse copiée, méthode
   **POST**, événement **30011 — Call State Changed**. Timeout 10 s et reprises
   selon le besoin. La documentation indique firmware ≥ 37.20.0.21 pour Webhook ;
   la présence de cette fonction est déjà visible sur les captures utilisateur.
3. Copier le **Secret généré par Yeastar pour ce Webhook** dans le champ Call CRM
   « Secret du Webhook Yeastar », puis enregistrer. Il est distinct de la clé API
   et n’est jamais affiché de nouveau. Garder l’intégration activée.
4. Utiliser le test de destination du P550. Le CRM accepte le message de test
   uniquement lorsque sa signature est correcte.
5. Appeler la ligne extérieure. Lorsque le poste 1029 sonne, Call CRM affiche une
   notification avec numéro et fiche si elle est accessible. Ouvrir la fiche ne
   décroche pas : répondre dans Linkus Web. La notification suit les états reçus
   (sonnerie, décroché, attente, terminé) et reste fermable sans quitter une saisie.

Authentification : `X-Signature` comparé en temps constant à
`Base64(HMAC-SHA256(corps brut, secret Webhook))`. Rejet sans secret/activation,
corps limité à 1 Mo, JSON limité en profondeur, payload msg objet ou chaîne JSON.
Aucun secret dans l’URL publique. Dédoublonnage SQL par hash du corps et destinataire.
Les autres événements sont ignorés ; pas d’automatisation commerciale à partir
seulement d’un état téléphonique.

## Historique et droits

Le « Journal technique Yeastar » repliable d’une fiche montre les 50 derniers
événements enregistrés, avec date, sens et état. Ce journal est distinct de
l’historique des résultats commerciaux : intéressé, à rappeler, etc. restent
choisis par l’utilisateur et les statistiques commerciales ne sont pas modifiées.
La durée de conversation, les enregistrements audio, le rapport CDR 30012 et les
appels manqués avant livraison du Webhook ne sont pas reconstruits.

Les notifications sont destinées uniquement au compte lié à l’extension. Les
liens/noms de fiches sont recalculés selon les droits et l’affectation courante.
Un commercial ne peut pas appeler le dossier d’un autre, choisir une autre
extension dans le navigateur ou forcer les permissions du P550. L’administrateur
peut appeler avec sa propre extension, si elle est associée dans les réglages.

Rapprochement téléphonique : variantes nationales et internationales algériennes
0…, +213…, 213…, 00213… ; refus du rapprochement s’il existe plusieurs fiches.
Les anciennes valeurs avec espaces stockées en base peuvent rester non reconnues :
nettoyer le téléphone de la fiche. Aucun dossier n’est créé automatiquement.

## Architecture, tests et limites

- `YeastarClient` : POST get_token et call/dial, User-Agent, délai 15 s,
  redirections interdites, validation TLS par défaut. Identifiants seulement côté
  serveur ; exceptions HTTP remplacées par des messages sans secrets. Jetons
  chiffrés en cache distribué et configuration Nextcloud avec expiration et verrou
  Nextcloud lors de leur obtention (limite Yeastar : 8 jetons simultanés).
- Une erreur API n’est jamais suivie automatiquement d’un second appel : éviter
  les doubles appels si la réponse du P550 se perd. Un jeton révoqué manuellement
  peut rester en cache jusqu’à son expiration (maximum 28 minutes).
- Les notifications sont interrogées toutes les 3 secondes tant que la page CRM
  est ouverte ; le navigateur peut ralentir ce rythme en arrière-plan. Fenêtre de
  reprise de 120 s, pas de notification native garantie quand le CRM est fermé.
  Plusieurs onglets peuvent afficher le même appel. Pas de connexion WebRTC/SIP
  embarquée dans le CRM : garder Linkus Web ouvert.
- Pas de purge automatique du journal, pas d’import historique des appels du P550.
  Surveiller sa taille. En cas de suppression client, ses anciennes lignes ne
  sont plus accessibles par la fiche. Les clés et tokens ne sont pas dans Git.
- EasyCrm conserve son connecteur téléphonique existant ; ce raccordement vise
  Call CRM et ne remplace pas les fichiers d’une édition par ceux de l’autre.

La suite `tools/test-nextcloud.sh` teste paramètres, permissions, CSRF, signature
HMAC, parsing du format officiel, cache et chiffrement de jetons, options TLS,
composition, refus de dossiers hors périmètre, UI de composition et notifications,
ainsi que la migration répétable. Les échanges API sont simulés. L’agent n’a pas
accès au P550/VPS : aucune authentification ni aucun appel réel n’est prétendu testé.
Il faut effectuer la recette des étapes ci-dessus avec une extension et un client
de test avant généralisation.

Références Yeastar consultées :
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/get-access-token.html
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/make-a-call.html
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/event-call-status-changed.html
- https://help.yeastar.com/en/p-series-appliance-edition/developer-guide/monitor-events-via-webhook.html
