<?php
namespace OCA\CallCrm\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Aggregates received CDRs; never guesses links between different PBX call IDs. */
class CallDashboard {
    public function __construct(private IDBConnection $db) {}

    public static function key(array $row): string {
        return hash('sha256', json_encode([$row['pbx_url'], $row['call_id'], $row['direction'], $row['phone']]));
    }

    public static function aggregate(array $records): array {
        $calls = [];
        foreach ($records as $r) {
            $key = self::key($r);
            if (!isset($calls[$key])) {
                $calls[$key] = ['id'=>(int)$r['id'], 'key'=>$key, 'phone'=>$r['phone'], 'direction'=>$r['direction'],
                    'received_at'=>(int)$r['created_at'], 'agents'=>[], 'extensions'=>[], 'prospects'=>[], 'answered'=>false, 'duration'=>0];
            }
            $c =& $calls[$key];
            $c['received_at'] = min($c['received_at'], (int)$r['created_at']);
            $c['extensions'][$r['extension']] = true;
            // Durée totale reçue du standard ; anciens détails sans cette donnée : temps passé dans le SVI / la file.
            $c['duration'] = max($c['duration'], (int)($r['call_duration'] ?? $r['talk_duration'] ?? 0));
            $c['prospects'][(string)($r['prospect_id'] ?? '')] = true;
            // user_id is the extension association snapshotted when the CDR arrived.
            if ($r['user_id'] !== '') {
                $uid = $r['user_id'];
                $a = $c['agents'][$uid] ?? ['answered'=>false, 'talk'=>0];
                $answered = strtoupper(trim($r['call_status'])) === 'ANSWERED';
                $a['answered'] = $a['answered'] || $answered;
                if ($answered) $a['talk'] = max($a['talk'], (int)$r['talk_duration']);
                $c['agents'][$uid] = $a;
                $c['answered'] = $c['answered'] || $answered;
            }
            unset($c);
        }
        return array_values($calls);
    }

    public function report(array $range, ?string $viewer, array $uids): array {
        $q = $this->db->getQueryBuilder();
        $records = $q->select('id','pbx_url','call_id','phone','direction','created_at','extension','prospect_id','user_id','call_status','talk_duration','call_duration')
            ->from('callcrm_pbx_recordings')
            ->where($q->expr()->gte('created_at',$q->createNamedParameter($range['start'])))
            ->andWhere($q->expr()->lt('created_at',$q->createNamedParameter($range['end'])))
            ->orderBy('id','ASC')->setMaxResults(10001)->executeQuery()->fetchAll();
        if (count($records)>10000) throw new \InvalidArgumentException('Plus de 10 000 détails téléphoniques : choisissez une période plus courte.');
        $calls = self::aggregate($records);
        $agents = [];
        foreach ($uids as $uid) $agents[$uid] = ['inbound'=>0,'outbound'=>0,'answered'=>0,'talk'=>0];
        $owned = [];
        if ($viewer !== null) {
            $q=$this->db->getQueryBuilder();
            foreach ($q->select('id')->from('callcrm_prospects')->where($q->expr()->eq('assigned_to',$q->createNamedParameter($viewer)))->executeQuery()->fetchAll() as $r) $owned[(string)$r['id']]=true;
        }
        $follow = [];
        foreach (array_chunk(array_column($calls,'key'),200) as $keys) {
            $q=$this->db->getQueryBuilder();
            foreach ($q->select('*')->from('callcrm_pbx_followups')
                ->where($q->expr()->in('call_key',$q->createNamedParameter($keys,IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery()->fetchAll() as $r) $follow[$r['call_key']]=$r;
        }
        $incoming = []; $totals=['inbound'=>0,'answered'=>0,'unanswered'=>0,'unmapped'=>0,'treated'=>0,'short'=>0];
        foreach ($calls as $c) {
            foreach ($c['agents'] as $uid=>$a) {
                if (!isset($agents[$uid])) continue;
                $agents[$uid][$c['direction']]++;
                $agents[$uid]['answered'] += (int)$a['answered'];
                $agents[$uid]['talk'] += $a['talk'];
            }
            if ($c['direction'] !== 'inbound') continue;
            $pids=array_keys($c['prospects']);
            $pid=count($pids)===1 && $pids[0]!=='' ? (int)$pids[0] : null;
            if ($viewer!==null && (!$pid || !isset($owned[(string)$pid]))) continue;
            $totals['inbound']++;
            if ($c['answered']) { $totals['answered']++; continue; }
            $kind=$c['agents'] ? 'unanswered' : 'unmapped';
            $totals[$kind]++;
            $short=$c['duration']<YeastarRecordings::SHORT_SECONDS;
            if ($short) $totals['short']++;
            $f=$follow[$c['key']]??null;
            if ($f && $f['state']==='done') $totals['treated']++;
            $incoming[]=['id'=>$c['id'],'phone'=>$c['phone'],'prospect_id'=>$pid,'received_at'=>$c['received_at'],
                'extensions'=>array_keys($c['extensions']), 'kind'=>$kind, 'short'=>$short, 'duration'=>$c['duration'],
                'state'=>$f['state']??'open','updated_at'=>(int)($f['updated_at']??0)];
        }
        usort($incoming,fn($a,$b)=>$b['received_at']<=>$a['received_at'] ?: $b['id']<=>$a['id']);
        return ['agents'=>$agents,'totals'=>$totals,'incoming'=>$incoming];
    }

    /** Managers explicitly acknowledge a follow-up; this is not proof of a callback. */
    public function followup(int $id, string $state, string $uid): void {
        if (!in_array($state,['open','done'],true)) throw new \InvalidArgumentException('Suivi invalide.');
        $q=$this->db->getQueryBuilder();
        $record=$q->select('*')->from('callcrm_pbx_recordings')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();
        if (!$record || $record['direction']!=='inbound') throw new \InvalidArgumentException('Appel entrant introuvable.');
        $key=self::key($record);
        $this->db->beginTransaction();
        try {
            $q=$this->db->getQueryBuilder();
            $q->delete('callcrm_pbx_followups')->where($q->expr()->eq('call_key',$q->createNamedParameter($key)))->executeStatement();
            $q=$this->db->getQueryBuilder();$q->insert('callcrm_pbx_followups');
            foreach (['call_key'=>$key,'state'=>$state,'updated_by'=>$uid,'updated_at'=>time()] as $k=>$v) $q->setValue($k,$q->createNamedParameter($v));
            $q->executeStatement();$this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
