<?php
namespace OCA\Reclamations\Service;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** Mail aux membres d'un groupe quand une réclamation lui est affectée. */
class Notify {
	public function __construct(private IGroupManager $gm, private IMailer $mailer, private IURLGenerator $url, private LoggerInterface $log) {}

	/** @return int nombre de mails envoyés */
	public function group(array $c, string $gid, string $actor): int {
		$g = $this->gm->get($gid);
		if (!$g) { return 0; }
		$link = $this->url->linkToRouteAbsolute('reclamations.page.index') . '#r' . $c['id'];
		$ref = $c['ref'] ?: ('#' . $c['id']);
		$sent = 0;
		foreach ($g->getUsers() as $u) {
			$to = $u->getEMailAddress();
			if (!$to || $u->getUID() === $actor) { continue; }
			try {
				$t = $this->mailer->createEMailTemplate('reclamations.group', ['ref' => $ref]);
				$t->setSubject('Réclamation ' . $ref . ' à prendre (' . $gid . ')');
				$t->addHeader();
				$t->addHeading('Nouvelle réclamation pour ' . $gid);
				$t->addBodyText('Client : ' . $c['client_name'] . ($c['phone'] ? ' · ' . $c['phone'] : ''));
				$t->addBodyText('Type : ' . $c['type'] . ' · Priorité : ' . $c['priority'] . ($c['due_at'] ? ' · Échéance : ' . date('d/m/Y H:i', (int)$c['due_at']) : ''));
				$t->addBodyText(mb_substr((string)$c['description'], 0, 600));
				$t->addBodyButton('Ouvrir et prendre la réclamation', $link);
				$t->addBodyText('La première personne du groupe qui clique sur « Prendre » devient responsable du traitement.');
				$t->addFooter();
				$m = $this->mailer->createMessage();
				$m->setTo([$to => $u->getDisplayName()]);
				$m->useTemplate($t);
				$this->mailer->send($m);
				$sent++;
			} catch (\Throwable $e) {
				$this->log->warning('Réclamations : mail non envoyé à ' . $u->getUID(), ['exception' => $e]);
			}
		}
		return $sent;
	}
}
