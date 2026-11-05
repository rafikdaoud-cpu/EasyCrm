<?php
/** Page publique de dépôt de documents (client, sans compte Nextcloud). */
\OCP\Util::addStyle('callcrm', 'collect');
\OCP\Util::addScript('callcrm', 'scanpdf');
\OCP\Util::addScript('callcrm', 'docscan');
\OCP\Util::addScript('callcrm', 'collect');
// Aperçu du lien (WhatsApp, SMS, iMessage) : nom et logo de l'entreprise.
$preview = $_['preview'] ?? [];
foreach (['og:site_name' => $preview['site'] ?? '', 'og:title' => $preview['title'] ?? '', 'og:description' => $preview['description'] ?? '', 'og:type' => 'website', 'og:image' => $preview['image'] ?? ''] as $property => $content) {
	if ($content !== '') {
		\OCP\Util::addHeader('meta', ['property' => $property, 'content' => $content]);
	}
}
if (($preview['image'] ?? '') !== '') {
	\OCP\Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => $preview['image']]);
	\OCP\Util::addHeader('meta', ['name' => 'twitter:card', 'content' => 'summary']);
}
?>
<main id="crm-collect" class="crm-collect" data-api="<?php p($_['api']); ?>">
	<p class="crm-collect-loading">Chargement…</p>
	<noscript>Activez JavaScript pour envoyer vos documents.</noscript>
</main>
