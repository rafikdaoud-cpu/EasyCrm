<?php
namespace OCA\CallCrm\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class InboundOutcome {
    public const MIN_RING_SECONDS = 4;

    public function __construct(private IDBConnection $db) {}

    public static function closedDestinations(): array {
        try {
            $extension = \OCP\Server::get(\OCP\IConfig::class)->getAppValue('callcrm','yeastar_closed_ivr','6201');
        } catch (\Throwable $e) {
            $extension = '6201';
        }
        if (!preg_match('/^\d{1,10}$/D',$extension)) return [];
        return [$extension, 'IVR CLOSED<'.$extension.'>', 'IVR CLOSED <'.$extension.'>'];
    }

    public function events(array $ids): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
            $q = $this->db->getQueryBuilder();
            $rows = $q->select('call_id','phone','user_id','state','created_at')->from('callcrm_pbx_events')
                ->where($q->expr()->in('call_id',$q->createNamedParameter($chunk,IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($q->expr()->eq('direction',$q->createNamedParameter('inbound')))
                ->orderBy('created_at','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAll();
            foreach ($rows as $row) $out[$row['call_id']][] = $row;
        }
        return $out;
    }

    public static function classify(array $events, string $phone, bool $answered, bool $closed = false): array {
        $ring = $last = $length = $agents = $responders = [];
        $started = false;
        $ended = null;
        $incomplete = false;
        foreach ($events as $event) {
            if (MissedCalls::phone($event['phone']) !== MissedCalls::phone($phone)) continue;
            $at = (int)$event['created_at'];
            $uid = $event['user_id'];
            $state = $event['state'];
            if ($state === 'TRUNK_START') $started = true;
            if ($state === 'TRUNK_END') $ended ??= $at;
            if ($uid === '') continue;
            $agents[$uid] = true;
            if (in_array($state, ['ANSWER','ANSWERED'], true)) {
                $answered = true;
                $responders[$uid] = true;
            }
            if ($state === 'RING' && $ended === null) {
                $ring[$uid] ??= $at;
                $last[$uid] = $at;
            }
            if ($state === 'BYE') {
                if (isset($ring[$uid])) {
                    $length[$uid] = max($length[$uid] ?? 0, min($ended ?? $at,$at)-$ring[$uid]);
                    unset($ring[$uid],$last[$uid]);
                } elseif ($ended === null) {
                    $incomplete = true;
                }
            }
        }
        foreach ($ring as $uid=>$at) {
            $length[$uid] = max($length[$uid] ?? 0, ($ended ?? $last[$uid])-$at);
        }
        $seconds = $length ? max($length) : null;
        $kind = match (true) {
            $answered => 'answered',
            $seconds !== null && $seconds >= self::MIN_RING_SECONDS => 'missed',
            $closed && !$agents => 'closed',
            $ended !== null && !$incomplete && ($seconds !== null || ($started && !$agents)) => 'lost',
            default => 'unknown',
        };
        return ['kind'=>$kind,'agents'=>array_keys($agents),'responders'=>array_keys($responders),'seconds'=>$seconds];
    }
}
