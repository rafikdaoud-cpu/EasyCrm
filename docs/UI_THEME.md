# Thème appliqué — Call CRM 0.25.1 / EasyCrm 1.6.1

Le thème est implémenté, ce n’est plus uniquement une maquette.

## Responsables : tableau clair

Navigation latérale, en-tête de session, période séparée des chiffres clés et cinq indicateurs : en poste, pause classique, déjeuner, absents, dossiers traités. Les temps en poste, pause classique et déjeuner sont présentés séparément. Chaque commercial conserve sur sa ligne le total et les compteurs de tous ses états, y compris les zéros. Les noms ouvrent le message direct et les titres permettent le tri.

Les badges de pause sont calculés par le serveur : pause classique rouge au-delà de 10 min ; déjeuner orange après 1 h, rouge au-delà de 1 h 10. Le texte « délai dépassé » ne fait pas dépendre l’information de la couleur seule.

## Commerciaux : Ma journée

Point d’entrée : temps du jour, pause classique et déjeuner séparés, rappels en retard, dossiers traités, appels du jour et résultats par état. Le bouton principal ouvre À traiter ; Mes statistiques conserve les autres périodes et les filtres. Le bandeau de session garde les boutons de pause accessibles.

Le déjeuner est disponible une seule fois par jour dans le fuseau CRM. Le quota est contrôlé par le serveur et le bouton devient indisponible après reprise. Les pauses bloquent la page avec leur chronomètre. Une mise à jour de l’état n’efface pas l’heure de début.

## Responsive et maintenance

Sur ordinateur : grille sidebar / contenu ; sous 1 100 px, la sidebar et les marges se réduisent ; sous 760 px, menu horizontal défilable et blocs réorganisés. Les grandes tables ont leur propre défilement. Les styles restent limités à la racine de l’application et utilisent les variables de couleur Nextcloud ; ils ne remplacent pas le thème général du serveur.

`show()` construit `.crm-sidebar` et `.crm-main`, avec `.crm-session-header` et `.crm-page`. `myDay()` / `refreshMyDay()` rendent le tableau de bord personnel. Les chemins PBX/WhatsApp et la disponibilité conditionnelle de WhatsApp dans EasyCrm sont conservés. Aucun nouveau service backend n’est requis pour les cartes de journée : elles utilisent les rapports personnels et `/counters` existants.

Les nouvelles formulations non encore présentes dans les dictionnaires EasyCrm restent en français ; compléter et faire relire les traductions séparément.

## Validation

- Suites PHP et DOM : `tools/test-nextcloud.sh` (préparation décrite dans AI_HANDOFF).
- Navigateur réel : installer Playwright Python, fournir Chromium via `CHROMIUM_BIN`, puis `python3 tests/browser-smoke.py /tmp/crm-captures`.
- Les tests navigateur utilisent des données synthétiques et vérifient ordinateur/mobile, absence de débordement global, parcours commercial et règles visibles du déjeuner.
- Tester ensuite sur la vraie instance Nextcloud et son thème : les fixtures Chromium ne simulent ni toutes les feuilles de style Nextcloud, ni les intégrations serveur.
