# WhatsApp → CsmCloud (Nextcloud)

Chaque photo, document, vocal ou vidéo reçu sur ton WhatsApp Business est rangé automatiquement dans Nextcloud :

```
WhatsApp/
└── 213555123456/
    ├── 2026-10-21_144000_image_11112222.jpg
    ├── 2026-10-21_144010_document_33334444_Fiche_de_paie.pdf
    └── historique.txt     ← date, nom WhatsApp du client, type, légende, messages texte
```

Flux : **client → WhatsApp (Meta) → webhook `https://wa.csmtaksit.dz/webhook` → ce service → WebDAV Nextcloud**

---

## 0. À savoir avant de connecter ton numéro (coexistence)

Ton numéro WhatsApp Business reste utilisable sur le téléphone ; les messages sont dupliqués vers l'API. Conséquences :

- Le badge **Meta Verified** (coche bleue), s'il existe, est retiré.
- Les appareils liés (WhatsApp Web / Desktop) sont **déconnectés** et doivent être reliés à nouveau. **WhatsApp pour Windows** n'est pas pris en charge en coexistence.
- Les **groupes** ne sont pas synchronisés vers l'API (seules les conversations individuelles arrivent).
- Les réponses envoyées **depuis l'appli** restent gratuites. Seuls les messages envoyés **par l'API** (l'accusé de réception automatique) sont facturés au-delà de 1 000 par mois.
- Le raccordement passe par le parcours **Embedded Signup**, option « Connecter votre application WhatsApp Business existante ». Si cette option n'apparaît pas directement dans ton espace Meta for Developers, il faut passer par un fournisseur (BSP) qui la propose, par exemple 360dialog. Dans ce cas, le téléchargement des médias diffère légèrement : dis-le-moi et j'adapte `download_media()`.

---

## 1. Nextcloud : compte de service

1. Crée un utilisateur **`whatsapp`** (Administration → Comptes), sans droits admin.
2. Récupère l'identifiant interne : Fichiers → Paramètres de fichiers → WebDAV ; la fin de l'URL (ex. `6CA18F00-…`) va dans `NC_USER_ID`, le login AD dans `NC_LOGIN`.
3. Connecte-toi avec ce compte → **Paramètres personnels → Sécurité → Créer un nouveau mot de passe d'application** (nom : `wa-nextcloud`). Copie-le pour `NC_APP_PASSWORD`.
4. Crée le dossier **`WhatsApp`** et **partage-le** avec ton compte et/ou un groupe (ex. « Commerciaux »), en lecture seule si l'équipe ne doit rien supprimer.
5. Protection anti force brute : le service appelle Nextcloud depuis l'IP du serveur. Avec l'app **Brute-force settings**, ajoute l'IP publique du VPS (et `127.0.0.1`) en liste blanche.

Les fichiers passent par Nextcloud, donc ton antivirus ClamAV les analyse à l'arrivée.

## 2. Serveur : service systemd + Apache (installation réelle)

Le serveur n'a pas CloudLinux (pas de « Setup Python App ») : le service tourne sous systemd avec gunicorn,
derrière Apache en reverse proxy. Fichiers fournis dans `deploy/`.

```bash
dnf install -y python3.12 python3.12-pip
cd /home/csmtak11/wa-nextcloud
python3.12 -m venv venv && venv/bin/pip install -r requirements.txt
cp .env.example .env && chmod 600 .env && nano .env        # secrets : jamais dans Git
mkdir -p logs data && chown -R csmtak11:csmtak11 /home/csmtak11/wa-nextcloud
cp deploy/wa-nextcloud.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now wa-nextcloud
for t in std ssl; do d=/etc/apache2/conf.d/userdata/$t/2_4/csmtak11/wa.csmtaksit.dz; mkdir -p $d; cp deploy/proxy.conf $d/; done
/scripts/rebuildhttpdconf && /scripts/restartsrv_httpd
```

DNS : l'enregistrement `wa` se crée chez Octenium (la zone WHM locale n'est pas publiée), puis AutoSSL.

## 3. Meta : application et webhook

1. **business.facebook.com** : vérifie que ton entreprise (Meta Business) existe. La vérification de l'entreprise est conseillée.
2. **developers.facebook.com → Mes apps → Créer une app** de type **Entreprise**, reliée à ton portefeuille Business. Ajoute le produit **WhatsApp**.
3. Connecte ton numéro existant via **Embedded Signup → « Connecter votre application WhatsApp Business »**. Suis les instructions : un QR code est à scanner depuis l'appli sur le téléphone, et l'historique peut être synchronisé.
4. **Jeton permanent** : Business Settings → **Utilisateurs système** → crée un utilisateur (Admin) → attribue-lui l'app et le compte WhatsApp → **Générer un jeton** avec les autorisations `whatsapp_business_messaging` et `whatsapp_business_management`, sans expiration. Colle-le dans `WA_ACCESS_TOKEN`.
5. **Clé secrète de l'app** : Paramètres de l'app → Général → *Clé secrète*. Colle-la dans `WA_APP_SECRET`.
6. **WhatsApp → Configuration → Webhook** :
   - URL de rappel : `https://wa.csmtaksit.dz/webhook`
   - Jeton de vérification : la valeur de `WA_VERIFY_TOKEN`
   - Cliquer **Vérifier et enregistrer**, puis **s'abonner au champ `messages`**.
7. Si les messages n'arrivent pas, abonne l'app au compte WhatsApp (WABA) :
   ```bash
   curl -X POST "https://graph.facebook.com/v23.0/<WABA_ID>/subscribed_apps" \
        -H "Authorization: Bearer <WA_ACCESS_TOKEN>"
   ```
8. Passe l'app en mode **Live** (en mode développement, seuls les numéros de test déclenchent le webhook).

## 4. Test

Envoie une photo avec une légende à ton numéro Business depuis un autre téléphone :
- dans Nextcloud : `WhatsApp/<ton_numero>/` contient la photo et `historique.txt` ;
- sur le téléphone : tu reçois l'accusé de réception, si `AUTO_REPLY=1`.

## 5. Exploitation

| Besoin | Où / comment |
|---|---|
| Journaux | `/home/csmtak11/wa-nextcloud/logs/app.log` (rotation 5 × 5 Mo), `journalctl -u wa-nextcloud` |
| Messages en échec (Nextcloud injoignable, etc.) | `data/failed/*.json`, puis `python app.py retry` (environnement virtuel activé). Meta garde les médias environ 30 jours. |
| Désactiver l'accusé de réception | `AUTO_REPLY=0` dans `.env`, puis Restart |
| Modifier le texte de l'accusé | `AUTO_REPLY_TEXT` dans `.env`, puis Restart |
| Taille max d'un média | `MAX_MEDIA_MB` (100 Mo par défaut) |
| Version de l'API Graph | `WA_GRAPH_VERSION` (mettre la version courante affichée dans ton app Meta) |
| Anti-doublon / accusés envoyés | `data/state.db` (SQLite) |

Pour mettre en place un rejeu automatique, tu peux ajouter une tâche cron cPanel toutes les heures :
```
/home/csmtak11/virtualenv/wa-nextcloud/3.11/bin/python /home/csmtak11/wa-nextcloud/app.py retry >/dev/null 2>&1
```

## Sécurité

- Chaque appel au webhook est authentifié par la signature HMAC-SHA256 de Meta (`X-Hub-Signature-256`) ; tout appel non signé est rejeté (403).
- Le service utilise un compte Nextcloud dédié avec mot de passe d'application, révocable à tout moment.
- `.env` est en `chmod 600` et placé hors du dossier web.
- Le service ne fait qu'ajouter des fichiers (`If-None-Match: *`) : il n'écrase et ne supprime jamais rien.

---

## 6. Boîte WhatsApp dans le CRM (callcrm ≥ 0.15.0)

Chaque message reçu est aussi transmis à callcrm (`CRM_URL` + `CRM_SECRET`) :

- **client connu** (numéro présent dans une fiche avec commercial) → conversation attribuée à ce commercial ;
- **numéro inconnu** ou fiche non assignée → **file d'attente** du superviseur, qui attribue ou crée la fiche ;
- les commerciaux répondent depuis le CRM (texte, photo, PDF…) : callcrm appelle `http://127.0.0.1:8085/send`,
  le service envoie via l'API Meta, range une copie dans `WhatsApp/<numéro>/` et l'ajoute à `historique.txt` ;
- accusés de livraison (✓ envoyé, ✓✓ remis, ✓✓ bleu lu, ⚠ échec) remontés dans le CRM ;
- si le CRM est indisponible, les événements attendent dans `data/crm_queue/` : `python app.py retry` les renvoie.

`/send` n'est accepté que depuis le serveur lui-même (refusé s'il arrive par Apache) et avec le secret partagé.

## 7. Robustesse

- Messages traités un par un (et jamais deux à la fois pour le même client) ; réessai si Nextcloud renvoie 423 (fichier verrouillé).
- `historique.txt` ouvert dans un éditeur : la ligne attend dans `data/hist_pending/` et part à l'écriture suivante, sans bloquer le CRM.
- Clients avec **nom d'utilisateur WhatsApp** : Meta peut omettre le numéro ; le service utilise l'identifiant BSUID
  (`from_user_id`, dossier `WhatsApp/DZ.xxxx/`) et répond via le champ `recipient`.
