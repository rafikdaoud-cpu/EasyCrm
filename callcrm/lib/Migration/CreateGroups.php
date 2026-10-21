<?php
namespace OCA\CallCrm\Migration;
use OCA\CallCrm\Service\Access;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
class CreateGroups implements IRepairStep {
	public function __construct(private IGroupManager $gm, private IConfig $cfg) {}
	public function getName(): string { return 'Création des groupes Call CRM'; }
	public function run(IOutput $output): void {
		// Groupes fournis par l'AD : on ne crée aucun groupe local
		if ($this->cfg->getAppValue('callcrm', 'use_ad_groups', 'no') === 'yes') { return; }
		foreach (Access::DEF_TEAMS as $gid => $name) {
			if (!$this->gm->groupExists($gid)) {
				$grp = $this->gm->createGroup($gid);
				if ($grp) { $grp->setDisplayName('Call CRM – ' . $name); }
			}
		}
		foreach (Access::DEF_GROUPS as $g) {
			if (!$this->gm->groupExists($g)) { $this->gm->createGroup($g); }
		}
	}
}
