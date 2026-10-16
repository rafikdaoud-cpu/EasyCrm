<?php
namespace OCA\EasyCrm\Migration;
use OCA\EasyCrm\Service\Access;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
class CreateGroups implements IRepairStep {
	public function __construct(private IGroupManager $gm, private IConfig $cfg) {}
	public function getName(): string { return 'Création des groupes d’accès EasyCrm'; }
	public function run(IOutput $output): void {
		// Groupes fournis par l'AD : on ne crée aucun groupe local
		if ($this->cfg->getAppValue('easycrm', 'use_ad_groups', 'no') === 'yes') { return; }
		foreach (Access::DEF_GROUPS as $g) {
			if (!$this->gm->groupExists($g)) { $this->gm->createGroup($g); }
		}
	}
}
