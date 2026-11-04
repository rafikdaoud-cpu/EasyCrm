<?php
/** Page publique de dépôt de documents (client, sans compte Nextcloud). */
\OCP\Util::addStyle('callcrm', 'collect');
\OCP\Util::addScript('callcrm', 'scanpdf');
\OCP\Util::addScript('callcrm', 'docscan');
\OCP\Util::addScript('callcrm', 'collect');
?>
<main id="crm-collect" class="crm-collect" data-api="<?php p($_['api']); ?>">
	<p class="crm-collect-loading">Chargement…</p>
	<noscript>Activez JavaScript pour envoyer vos documents.</noscript>
</main>
