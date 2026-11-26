# Réclamations (Nextcloud)
Installation : dézipper dans custom_apps/, chown csmtak11:csmtak11, puis
/opt/cpanel/ea-php85/root/usr/bin/php -d memory_limit=512M occ app:enable reclamations
Groupes créés : reclam_admin, reclam_superviseur, reclam_agent, reclam_lecture.

## Déploiement
Ce module n'est pas déployé par `crm-deploy` (qui ne gère que `callcrm` et `wa-nextcloud`).
Copie manuelle vers `cloud.csmtaksit.dz/custom_apps/reclamations` (`/bin/cp -af`, puis `chown -R csmtak11`),
et `occ upgrade` aussitôt après tout changement de `<version>` dans `appinfo/info.xml`.
