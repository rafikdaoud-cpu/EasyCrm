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
                    'call_id'=>$r['call_id'], 'received_at'=>(int)$r['created_at'], 'agents'=>[], 'extensions'=>[], 'prospects'=>[], 'answered'=>false, 'outbound_answered'=>false, 'outbound_no_answer'=>false, 'duration'=>0, 'total_duration'=>null];
            }
            $c =& $calls[$key];
            $c['received_at'] = min($c['received_at'], (int)$r['created_at']);
            $c['extensions'][$r['extension']] = true;
            // Durée totale reçue du standard ; anciens détails sans cette donnée : temps passé dans le SVI / la file.
            $c['duration'] = max($c['duration'], (int)($r['call_duration'] ?? $r['talk_duration'] ?? 0));
            if (isset($r['call_duration'])) $c['total_duration'] = max($c['total_duration'] ?? 0, (int)$r['call_duration']);
            $c['prospects'][(string)($r['prospect_id'] ?? '')] = true;
            $status = strtoupper(trim($r['call_status']));
            $answered = $status === 'ANSWERED';
            $noAnswer = in_array($status,['NO ANSWER','NO_ANSWER','NOANSWER','UNANSWERED'],true);
            if ($r['direction'] === 'outbound') {
                $c['outbound_answered'] = $c['outbound_answered'] || $answered;
                $c['outbound_no_answer'] = $c['outbound_no_answer'] || $noAnswer;
            }
            // user_id is the extension association snapshotted when the CDR arrived.
            if ($r['user_id'] !== '') {
                $uid = $r['user_id'];
                $a = $c['agents'][$uid] ?? ['answered'=>false, 'no_answer'=>false, 'talk'=>0];
                $a['no_answer'] = $a['no_answer'] || $noAnswer;
                $a['answered'] = $a['answered'] || $answered;
                if ($answered) $a['talk'] = max($a['talk'], (int)$r['talk_duration']);
                $c['agents'][$uid] = $a;
                $c['answered'] = $c['answered'] || $answered;
            }
            unset($c);
        }
        return array_values($calls);
    }

    private function callsForRange(array $range): array {
        $q = $this->db->getQueryBuilder();
        $records = $q->select('id','pbx_url','call_id','phone','direction','created_at','extension','prospect_id','user_id','call_status','talk_duration','call_duration')
            ->from('callcrm_pbx_recordings')
            ->where($q->expr()->gte('created_at',$q->createNamedParameter($range['start'])))
            ->andWhere($q->expr()->lt('created_at',$q->createNamedParameter($range['end'])))
            ->orderBy('id','ASC')->setMaxResults(10001)->executeQuery()->fetchAll();
        if (count($records)>10000) throw new \InvalidArgumentException('Plus de 10 000 détails téléphoniques : choisissez une période plus courte.');
        return self::aggregate($records);
    }

    /** Étapes du journal complet du standard et correspondance poste → compte, pour classer les appels. */
    public static function journal(array $callIds): array {
        try {
            $cdr = \OCP\Server::get(YeastarCdr::class);
            return [$cdr->legs($callIds), $cdr->owners()];
        } catch (\Throwable $e) {
            return [[], []];
        }
    }

    /** Pour chaque appel non décroché : heure du premier appel sortant vers ce numéro après lui, s'il existe. */
    private function calledBack(array $calls): array {
        if (!$calls) return [];
        $phones = array_values(array_unique(array_column($calls,'phone')));
        $first = [];
        foreach (array_chunk($phones,200) as $chunk) {
            $q = $this->db->getQueryBuilder();
            $rows = $q->select('phone','created_at')->from('callcrm_pbx_recordings')
                ->where($q->expr()->eq('direction',$q->createNamedParameter('outbound')))
                ->andWhere($q->expr()->in('phone',$q->createNamedParameter($chunk,IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter(min(array_column($calls,'at')),IQueryBuilder::PARAM_INT)))
                ->orderBy('created_at','ASC')->executeQuery()->fetchAll();
            foreach ($rows as $r) $first[$r['phone']][] = (int)$r['created_at'];
        }
        $out = [];
        foreach ($calls as $i => $c) {
            foreach ($first[$c['phone']] ?? [] as $at) {
                if ($at > $c['at']) { $out[$i] = $at; break; }
            }
        }
        return $out;
    }

    public static function closureRange(int $now, \DateTimeZone $zone): array {
        $today = (new \DateTimeImmutable('@'.$now))->setTimezone($zone);
        $weekday = (int)$today->format('N');
        $weekend = $weekday >= 5 || ($weekday === 4 && $today >= $today->setTime(16, 30));
        $start = $today->modify('-'.($weekend ? $weekday - 4 : 1).' day')->setTime(16, $weekend ? 30 : 31);
        $end = $today->modify('+'.($weekend ? 7 - $weekday : 0).' day')->setTime(8, 30);
        return [
            'start'=>$start->getTimestamp(), 'end'=>$end->getTimestamp(),
            'from'=>$start->format('Y-m-d'), 'to'=>$end->format('Y-m-d'),
            'label'=>$start->format('d/m/Y H:i').' → '.$end->format('d/m/Y H:i'),
        ];
    }

    public function activity(array $range, array $uids, bool $includeUnassigned, \DateTimeZone $zone, string $detail = '', ?int $slot = null): array {
        $agents = array_fill_keys($uids, ['incoming'=>0, 'outgoing'=>0, 'outgoing_answered'=>0, 'outgoing_no_answer'=>0]);
        $start = (new \DateTimeImmutable('@'.$range['start']))->setTimezone($zone);
        $end = (new \DateTimeImmutable('@'.$range['end']))->setTimezone($zone);
        $days = (int)$start->diff($end)->days;
        if ($days > 92) throw new \InvalidArgumentException('Choisissez une période de 92 jours maximum.');
        $hourly = $days === 1;
        $buckets = [];
        for ($time=$start; $time<$end; $time=$hourly?$time->add(new \DateInterval('PT1H')):$time->modify('+1 day')) {
            $key = $hourly ? $time->format('Y-m-d H P') : $time->format('Y-m-d');
            $buckets[$key] = ['slot'=>$time->getTimestamp(), 'label'=>$time->format($hourly?'H':'d/m'), 'answered'=>0, 'missed'=>0, 'lost'=>0, 'menu'=>0, 'unknown'=>0, 'closed'=>0];
        }
        if ($slot !== null && !in_array($slot,array_column($buckets,'slot'),true)) {
            throw new \InvalidArgumentException('Créneau hors période.');
        }
        $totals = ['answered'=>0,'missed'=>0,'lost'=>0,'menu'=>0,'unknown'=>0,'closed'=>0,'inbound'=>0,'outbound'=>0,'outgoing_answered'=>0,'outgoing_no_answer'=>0,
            'missed_called_back'=>0,'lost_called_back'=>0];
        $matches = $unanswered = [];
        $calls = ($uids || $includeUnassigned) ? $this->callsForRange($range) : [];
        $closed = InboundOutcome::closedDestinations();
        $evidence = (new InboundOutcome($this->db))->events(array_column($calls,'call_id'));
        [$legs, $owners] = self::journal(array_column($calls,'call_id'));
        foreach ($calls as $call) {
            $visible = array_intersect_key($call['agents'], $agents);
            $prospects = array_keys($call['prospects']);
            $prospectId = count($prospects)===1 && $prospects[0]!=='' ? (int)$prospects[0] : null;
            if ($call['direction'] === 'outbound') {
                if (!$visible && !$includeUnassigned) continue;
                $totals['outbound']++;
                $answered = $includeUnassigned && $call['outbound_answered'];
                $noAnswer = $includeUnassigned && $call['outbound_no_answer'];
                foreach ($visible as $uid=>$agent) {
                    $agents[$uid]['outgoing']++;
                    if ($agent['answered']) $agents[$uid]['outgoing_answered']++;
                    elseif ($agent['no_answer']) $agents[$uid]['outgoing_no_answer']++;
                    $answered = $answered || $agent['answered'];
                    $noAnswer = $noAnswer || $agent['no_answer'];
                }
                $outcome = $answered ? 'outgoing_answered' : ($noAnswer ? 'outgoing_no_answer' : 'outgoing_other');
                if ($outcome !== 'outgoing_other') $totals[$outcome]++;
                if ($detail === 'outbound' || $detail === $outcome) {
                    $matches[] = ['id'=>$call['id'],'phone'=>$call['phone'],'at'=>$call['received_at'],
                        'outcome'=>$outcome,'total_seconds'=>$call['total_duration'],'prospect_id'=>$prospectId,
                        'caller_agents'=>array_keys($visible)];
                }
                continue;
            }
            if ($call['direction'] !== 'inbound') continue;
            foreach ($visible as $uid=>$agent) {
                if ($agent['answered']) $agents[$uid]['incoming']++;
            }
            $events = $evidence[$call['call_id']] ?? [];
            $result = YeastarCdr::classify($legs[$call['call_id']] ?? [],$closed,$owners) ?? InboundOutcome::classify($events,$call['phone'],$call['answered'],
                !$call['agents'] && (bool)array_intersect($closed,array_keys($call['extensions'])));
            $ringAgents = array_intersect($uids,$result['agents']);
            if (!$visible && !$ringAgents && !$includeUnassigned) continue;
            $responders = array_unique(array_merge(
                array_keys(array_filter($call['agents'], static fn($agent) => $agent['answered'])),
                $result['responders']
            ));
            if (!$includeUnassigned) $responders = array_intersect($responders,$uids);
            if ($result['kind']==='answered' && !$includeUnassigned && !$responders) continue;
            $time = (new \DateTimeImmutable('@'.$call['received_at']))->setTimezone($zone);
            $key = $hourly ? $time->format('Y-m-d H P') : $time->format('Y-m-d');
            $totals['inbound']++;
            $totals[$result['kind']]++;
            if ($detail === '') $buckets[$key][$result['kind']]++;
            if (in_array($result['kind'],['missed','lost'],true)) $unanswered[] = ['phone'=>$call['phone'],'at'=>$call['received_at'],'kind'=>$result['kind'],'match'=>null];
            if (($detail === 'inbound' || $detail === $result['kind'] || in_array($detail,['missed_called_back','lost_called_back'],true) && $detail === $result['kind'].'_called_back')
                && ($slot === null || $buckets[$key]['slot'] === $slot)) {
                $matches[] = ['id'=>$call['id'],'phone'=>$call['phone'],'at'=>$call['received_at'],
                    'ring_seconds'=>$result['seconds'],'total_seconds'=>$call['total_duration'],'outcome'=>$result['kind'],
                    'prospect_id'=>$prospectId,'responders'=>$responders,'called_back_at'=>null,
                    'reason'=>$result['kind']==='lost' ? ($result['seconds']===null?'before_ring':'short_ring') : null];
                if (in_array($result['kind'],['missed','lost'],true)) $unanswered[array_key_last($unanswered)]['match'] = array_key_last($matches);
            }
        }
        // Rappelés = un appel sortant vers ce numéro après l'appel manqué ou perdu (même après la période affichée).
        $back = $this->calledBack($unanswered);
        foreach ($unanswered as $i => $u) {
            if (!isset($back[$i])) continue;
            $totals[$u['kind'].'_called_back']++;
            if ($u['match'] !== null) $matches[$u['match']]['called_back_at'] = $back[$i];
        }
        if (in_array($detail,['missed_called_back','lost_called_back'],true)) $matches = array_values(array_filter($matches, fn($m) => $m['called_back_at'] !== null));
        if ($detail !== '') {
            usort($matches,fn($a,$b)=>$b['at']<=>$a['at'] ?: $b['id']<=>$a['id']);
            return $matches;
        }
        return $totals + ['series'=>array_values($buckets), 'agents'=>$agents, 'days'=>$days];
    }

    public function report(array $range, ?string $viewer, array $uids, bool $global = true): array {
        $calls = $this->callsForRange($range);
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
        $closed = InboundOutcome::closedDestinations();
        $evidence = (new InboundOutcome($this->db))->events(array_column($calls,'call_id'));
        [$legs, $owners] = self::journal(array_column($calls,'call_id'));
        $incoming = [];
        $totals = ['inbound'=>0,'answered'=>0,'missed'=>0,'lost'=>0,'menu'=>0,'unknown'=>0,'closed'=>0,'treated'=>0];
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
            $events = $evidence[$c['call_id']] ?? [];
            $result = YeastarCdr::classify($legs[$c['call_id']] ?? [],$closed,$owners) ?? InboundOutcome::classify($events,$c['phone'],$c['answered'],
                !$c['agents'] && (bool)array_intersect($closed,array_keys($c['extensions'])));
            if ($viewer === null && !$global) {
                if (!array_intersect($uids,array_merge(array_keys($c['agents']),$result['agents']))) continue;
                if ($result['kind']==='answered' && !array_intersect($uids,$result['responders']) && !$this->answeredBySelection($c,$events,$uids)) continue;
            }
            $totals['inbound']++;
            $totals[$result['kind']]++;
            if ($result['kind']!=='missed') continue;
            $f=$follow[$c['key']]??null;
            if ($f && $f['state']==='done') $totals['treated']++;
            $incoming[]=['id'=>$c['id'],'phone'=>$c['phone'],'prospect_id'=>$pid,'received_at'=>$c['received_at'],
                'extensions'=>array_keys($c['extensions']), 'kind'=>'missed', 'ring_seconds'=>$result['seconds'], 'duration'=>$c['total_duration'],
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
