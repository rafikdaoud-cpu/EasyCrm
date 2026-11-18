<?php
namespace OCA\CallCrm\Repair;
use OCP\IDBConnection;
use OCP\Migration\{IOutput,IRepairStep};

/**
 * Reconstitue l'origine des prospects créés avant l'enregistrement de la source (une fois ; ne touche que source vide) :
 * formulaire (trace de la demande publique), whatsapp (journal wa_prospect), import (3 fiches ou plus créées à la même
 * seconde : un import crée toutes ses fiches avec le même horodatage), sinon saisie manuelle.
 */
class BackfillProspectSource implements IRepairStep {
    public function __construct(private IDBConnection $db) {}
    public function getName(): string { return 'Call CRM : origine des prospects existants'; }
    public function run(IOutput $output): void {
        $t=fn($name)=>$this->db->getQueryBuilder()->getTableName($name);
        $p=$t('callcrm_prospects');$a=$t('callcrm_audit');
        $this->db->executeStatement("UPDATE $p SET source='formulaire' WHERE source IS NULL AND (c1='Facebook /prospects' OR extra LIKE '%_csm_public_requests%')");
        $this->db->executeStatement("UPDATE $p SET source='whatsapp' WHERE source IS NULL AND id IN (SELECT prospect_id FROM $a WHERE action='wa_prospect')");
        $batches=$this->db->executeQuery("SELECT created_at FROM $p WHERE source IS NULL GROUP BY created_at HAVING COUNT(*)>=3")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_chunk($batches,500) as $chunk) {
            $q=$this->db->getQueryBuilder();
            $q->update('callcrm_prospects')->set('source',$q->createNamedParameter('import'))->where($q->expr()->isNull('source'))
              ->andWhere($q->expr()->in('created_at',$q->createNamedParameter(array_map('intval',$chunk),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
        }
        $this->db->executeStatement("UPDATE $p SET source='manuel' WHERE source IS NULL");
    }
}
