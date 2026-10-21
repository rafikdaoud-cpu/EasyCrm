<?php
namespace OCA\EasyCrm\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** WhatsApp : clients avec nom d'utilisateur (identifiant BSUID sans numéro de téléphone). */
class Version1004Date20261021230000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$s = $schemaClosure();
		if ($s->hasTable('easycrm_wa_conv')) {
			$t = $s->getTable('easycrm_wa_conv');
			if ($t->getColumn('wa_id')->getLength() < 191) { $t->getColumn('wa_id')->setLength(191); }
			if (!$t->hasColumn('bsuid')) { $t->addColumn('bsuid', Types::STRING, ['notnull' => false, 'length' => 191]); }
			if (!$t->hasIndex('ec_wc_bsuid')) { $t->addIndex(['bsuid'], 'ec_wc_bsuid'); }
		}
		return $s;
	}
}
