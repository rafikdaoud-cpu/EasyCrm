<?php
namespace OCA\Reclamations\Migration;
use OCA\Reclamations\Service\Access;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
class CreateGroups implements IRepairStep {
	public function __construct(private IGroupManager $gm) {}
	public function getName(): string { return 'Création des groupes Réclamations'; }
	public function run(IOutput $output): void {
		foreach (Access::GROUPS as $g) { if (!$this->gm->groupExists($g)) { $this->gm->createGroup($g); } }
	}
}
