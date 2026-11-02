# Propositions UI/UX — choix validé

Le bouton Pause déjeuner est implémenté. Le choix 1 pour les responsables et le choix 3 pour les commerciaux ont été validés puis appliqués en 0.25.0 / 1.6.0. Les maquettes ci-dessous restent des exemples initiaux ; elles ne représentent pas les règles finales de comptage des déjeuners. Ouvrir `ui-proposals.html` dans un navigateur ; seules les trois propositions peuvent être sélectionnées, les données et les autres boutons sont illustratifs.

1. **Tableau clair (recommandé pour les responsables)** : navigation latérale, état et boutons Pause / Pause déjeuner dans une barre de session, quatre chiffres clés, filtre de période bien identifié, en-têtes sobres, détails de chaque état sur la même ligne que le commercial. Garder le clic sur les titres pour le tri et le clic sur le nom pour les messages. Éviter les nombreuses couleurs très saturées en concurrence.
2. **Fiches commerciales** : une fiche par personne avec son état, ses temps et tous ses résultats. Meilleure adaptation mobile et accès direct au message ; moins pratique pour comparer une grande équipe.
3. **Ma journée (pour les commerciaux)** : une page centrée sur À traiter, rappels, temps de travail / pause et messages, avec les statistiques en second niveau. Peut être combinée avec le tableau clair des responsables.

La vue par fiches (proposition 2) n’a pas été retenue. Pour l’implémentation actuelle, voir `UI_THEME.md`. Préserver notamment l'exigence de détail par état pour CHAQUE commercial, les zéros, les filtres et les tris par en-tête. Les exemples ne représentent ni les données du VPS ni une mesure réelle des performances.
