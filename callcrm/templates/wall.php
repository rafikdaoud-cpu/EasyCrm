<?php
/** Écran du plateau commercial (TV), ouvert par un lien secret. */
\OCP\Util::addStyle('callcrm', 'wall');
\OCP\Util::addScript('callcrm', 'wall');
?>
<main id="crm-wall" class="crm-wall" data-api="<?php p($_['api']); ?>">
	<p class="crm-wall-loading">Chargement…</p>
	<noscript>Activez JavaScript pour afficher l’écran du plateau.</noscript>
</main>
