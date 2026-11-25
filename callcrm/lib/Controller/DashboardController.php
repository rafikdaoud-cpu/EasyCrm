<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, Presence, CallDashboard, MissedCalls, Prefs, TeamScope, YeastarEvents};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\{IRequest,IGroupManager,IDBConnection};

class DashboardController extends Controller {
    public function __construct(IRequest $request, private Access $access, private Presence $presence,
        private CallDashboard $dashboard, private IGroupManager $gm, private IDBConnection $db) {
        parent::__construct(Application::APP_ID,$request);
    }
    private function manager(): bool { return in_array($this->access->role(),['admin','superviseur'],true); }
    #[NoAdminRequired]
    public function report(): JSONResponse {
        if (!$this->manager() && $this->access->role()!=='commercial') return new JSONResponse(['error'=>'Accès refusé'],403);
        $users=[];
        if ($this->manager()) {
            $groups = [Access::groups()['commercial']];
            $allowed = $this->access->role()==='admin' || isset(TeamScope::config()[$this->access->uid()]);
            foreach (Access::customProfiles() as $profile) if ($profile['base']==='commercial') $groups[]=$profile['group'];
            foreach ($allowed ? $groups : [] as $group) {
                foreach ($this->gm->get($group)?->getUsers() ?? [] as $user) {
                    if (TeamScope::canTarget($user->getUID())) $users[$user->getUID()] = $user->getDisplayName();
                }
            }
        } else {
            $users[$this->access->uid()] = 'Moi';
        }
        try {
            $filter=['period'=>$this->request->getParam('period','day'),'from'=>$this->request->getParam('from'),'to'=>$this->request->getParam('to')];
            $p=$this->presence->report(array_keys($users),null,$filter);
            $r=$this->dashboard->report($p['range'],$this->manager()?null:$this->access->uid(),array_keys($users),$this->access->role()==='admin');
            $rows=[];
            foreach ($users as $uid=>$name) $rows[]=['uid'=>$uid,'name'=>$name,'telephony'=>$r['agents'][$uid]]+$p['rows'][$uid];
            return new JSONResponse(['rows'=>$rows,'totals'=>$r['totals'],'incoming'=>$r['incoming'],
                'range'=>$p['range'],'timezone'=>$p['timezone'],'server_now'=>$p['server_now']]);
        } catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
    }
    #[NoAdminRequired]
    public function pilotage(): JSONResponse {
        if (!$this->manager() || !$this->access->can('view') || $this->access->can('mask')) {
            return new JSONResponse(['error'=>'Accès refusé'],403);
        }
        $team = $this->request->getParam('team', '');
        $agent = $this->request->getParam('agent', '');
        $detail = $this->request->getParam('detail', '');
        $rawSlot = $this->request->getParam('slot');
        $slot = $rawSlot === null ? null : filter_var($rawSlot,FILTER_VALIDATE_INT);
        if (!is_string($team) || !is_string($agent) || !in_array($detail,['','answered','missed','lost','menu','missed_called_back','lost_called_back','closed','closure_window','processed','inbound','outbound','outgoing_answered','outgoing_no_answer'],true)
            || $slot === false || ($slot !== null && !in_array($detail,['answered','missed','lost','menu'],true))) return new JSONResponse(['error'=>'Filtre invalide'],400);
        $scope = \OCA\CallCrm\Service\TeamScope::users();
        $users = [];
        $groups = [Access::groups()['commercial']];
        foreach (Access::customProfiles() as $profile) if ($profile['base']==='commercial') $groups[]=$profile['group'];
        foreach ($groups as $group) {
            foreach ($this->gm->get($group)?->getUsers() ?? [] as $user) {
                $id = $user->getUID();
                if ($scope === null || in_array($id,$scope,true)) $users[$id] = $user->getDisplayName();
            }
        }
        asort($users,SORT_NATURAL|SORT_FLAG_CASE);
        $teamUsers = $teams = [];
        $allowedTeams = $this->access->role()==='admin' ? null : (\OCA\CallCrm\Service\TeamScope::config()[$this->access->uid()] ?? []);
        foreach (Access::teams() as $id=>$label) {
            if ($allowedTeams !== null && !in_array($id,$allowedTeams,true)) continue;
            $members = [];
            foreach ($this->gm->get($id)?->getUsers() ?? [] as $user) $members[$user->getUID()] = true;
            $teamUsers[$id] = array_intersect_key($users,$members);
            $teams[] = ['id'=>$id,'label'=>$label];
        }
        if ($team !== '' && !isset($teamUsers[$team])) return new JSONResponse(['error'=>'Équipe inaccessible'],403);
        $teamMembers = [];
        foreach ($teamUsers as $members) $teamMembers += $members;
        $all = $allowedTeams === null ? $users : array_intersect_key($users,$teamMembers);
        $available = $team === '' ? $all : $teamUsers[$team];
        if ($agent !== '' && !isset($available[$agent])) return new JSONResponse(['error'=>'Commercial inaccessible'],403);
        $selected = $agent === '' ? $available : [$agent=>$available[$agent]];
        $uids = array_map('strval',array_keys($selected));
        $now = time();
        $zone = new \DateTimeZone(Prefs::all()['tz']);
        $filter = ['period'=>$this->request->getParam('period','day'),'from'=>$this->request->getParam('from'),'to'=>$this->request->getParam('to')];
        $closureRange = CallDashboard::closureRange($now,$zone);
        try {
            $range = \OCA\CallCrm\Service\PresenceRange::resolve($filter,$zone,$now);
            $days = (new \DateTimeImmutable($range['from'],$zone))->diff(new \DateTimeImmutable($range['to'],$zone))->days + 1;
            if ($days > 92) throw new \InvalidArgumentException('Choisissez une période de 92 jours maximum.');
            $global = $allowedTeams===null && $team==='' && $agent==='';
            if ($detail === 'closure_window') {
                $range = $closureRange;
                $detail = 'inbound';
            }
            if ($detail !== '') {
                $items = $detail==='processed' ? $this->processedRows($uids,$range,$now) : $this->dashboard->activity($range,$uids,$global,$zone,$detail,$slot);
                $offset = max(0,(int)$this->request->getParam('offset',0));
                $sort = $this->request->getParam('sort','at');
                $direction = $this->request->getParam('dir','desc');
                $labelFields = ['client','caller','agent','callback_state','answered_by'];
                $fields = array_merge(['at','phone','ring_seconds','total_seconds','outcome','result','reason','called_back_at'],$labelFields);
                if (!in_array($sort,$fields,true) || !in_array($direction,['asc','desc'],true)) {
                    throw new \InvalidArgumentException('Tri invalide.');
                }
                $names = $detail==='processed' ? $selected : $users;
                $byLabel = in_array($sort,$labelFields,true);
                if ($byLabel) {
                    $labeled = [];
                    foreach (array_chunk($items,100) as $chunk) {
                        array_push($labeled,...$this->pilotageLabels($chunk,$names,$detail));
                    }
                    $items = $labeled;
                }
                $key = $sort==='at' && $detail==='processed' ? 'created_at' : $sort;
                $this->sortPilotage($items,$key,$direction);
                $page = array_slice($items,$offset,50);
                if (!$byLabel) $page = $this->pilotageLabels($page,$names,$detail);
                return new JSONResponse(['rows'=>$page,'total'=>count($items),'range'=>$range]);
            }
            $activity = $this->dashboard->activity($range,$uids,$global,$zone);
            $closure = count($this->dashboard->activity($closureRange,$uids,$global,$zone,'inbound'));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error'=>$e->getMessage()],400);
        }
        $presence = $this->presence->report($uids,$now,$filter)['rows'];
        $load = $this->workload($uids,$now);
        $start = (new \DateTimeImmutable('@'.$range['start']))->setTimezone($zone);
        $previousStart = $start->modify('-'.$activity['days'].' days');
        $previous = $this->loggedCalls($uids,$previousStart->getTimestamp(),$range['start']);
        $current = $this->loggedCalls($uids,$range['start'],min($now+1,$range['end']));
        $goal = 0;
        $rows = [];
        foreach ($selected as $uid=>$name) {
            $g = Prefs::goalFor((string)$uid);
            $goal += $range['period']==='week' && $g['week']>0 ? $g['week'] : $g['day']*$activity['days'];
            $rows[] = ['uid'=>(string)$uid,'name'=>$name,'status'=>$presence[$uid]['status'],
                'processed'=>$presence[$uid]['processed'],'pending'=>$load[$uid]['pending']??0,'late'=>$load[$uid]['late']??0]
                + $activity['agents'][$uid];
        }
        $agents = [];
        foreach ($available as $uid=>$name) $agents[] = ['uid'=>(string)$uid,'name'=>$name];
        unset($activity['agents'], $activity['days']);
        return new JSONResponse($activity + ['teams'=>$teams,'agents'=>$agents,'rows'=>$rows,'range'=>$range,'timezone'=>$zone->getName(),
            'closure_window'=>['count'=>$closure,'range'=>$closureRange],
            'objective'=>['current'=>$current,'target'=>$goal,'previous'=>$previous]]);
    }

    private function pilotageLabels(array $rows, array $users, string $detail): array {
        if ($detail==='processed') return $this->processedLabels($rows,$users);
        if ($detail==='missed') $rows = $this->callbackLabels($rows,$users);
        return $this->callLabels($rows,$users);
    }

    private function sortPilotage(array &$rows, string $key, string $direction): void {
        $numeric = in_array($key,['at','created_at','ring_seconds','total_seconds','called_back_at'],true);
        $labels = match ($key) {
            'outcome' => ['answered'=>'Décroché','missed'=>'Manqué','lost'=>'Perdu','menu'=>'Abandon au menu d’accueil','unknown'=>'Indéterminé','closed'=>'Hors horaires',
                'outgoing_answered'=>'Abouti','outgoing_no_answer'=>'Sans réponse','outgoing_other'=>'Autre résultat'],
            'callback_state' => ['open'=>'À rappeler','attempted'=>'Rappel tenté','done'=>'Traité'],
            'reason' => ['before_ring'=>'Raccroché avant la sonnerie','short_ring'=>'Sonnerie de moins de 4 s'],
            'result' => array_column(\OCA\CallCrm\Service\Statuses::all(),'label','key'),
            default => [],
        };
        $factor = $direction==='asc' ? 1 : -1;
        usort($rows,static function ($a,$b) use ($key,$numeric,$labels,$factor): int {
            $left = $a[$key] ?? '';
            $right = $b[$key] ?? '';
            if ($left==='' || $right==='') return ($left==='') <=> ($right==='');
            return $factor * ($numeric ? ((int)$left <=> (int)$right)
                : strnatcasecmp($labels[$left] ?? (string)$left,$labels[$right] ?? (string)$right));
        });
    }

    private function processedRows(array $uids, array $range, int $now): array {
        $rows = [];
        foreach ($this->presence->processedCases($uids,$range,$now) as $cases) {
            foreach ($cases as $case) $rows[] = $case;
        }
        usort($rows,fn($a,$b)=>(int)$b['created_at']<=>(int)$a['created_at'] ?: (int)$b['id']<=>(int)$a['id']);
        return $rows;
    }

    private function processedLabels(array $rows, array $users): array {
        if (!$rows) return [];
        $clients = $this->clients(array_column($rows,'prospect_id'));
        return array_map(function ($row) use ($clients,$users) {
            $client = $clients[$row['prospect_id']] ?? null;
            $visible = $client && \OCA\CallCrm\Service\TeamScope::canSee($client['assigned_to']);
            return ['at'=>(int)$row['created_at'],'agent'=>$users[$row['user_id']],'result'=>$row['result'],
                'prospect_id'=>$visible?(int)$client['id']:null,
                'client'=>$visible?trim($client['firstname'].' '.$client['lastname']):'Dossier indisponible'];
        },$rows);
    }

    private function clients(array $ids): array {
        $ids = array_values(array_unique(array_map('intval',array_filter($ids))));
        if (!$ids) return [];
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('id','firstname','lastname','assigned_to')->from('callcrm_prospects')
            ->where($q->expr()->in('id',$q->createNamedParameter($ids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery()->fetchAll();
        return array_column(array_filter($rows,fn($row)=>TeamScope::canSee($row['assigned_to'])),null,'id');
    }

    private function callbackLabels(array $rows, array $users): array {
        if (!$rows) return [];
        $phones = array_values(array_unique(array_map(fn($row)=>MissedCalls::phone($row['phone']),$rows)));
        $times = array_column($rows,'at');
        $q = $this->db->getQueryBuilder();
        $q->select('phone','first_at','last_at','prospect_id','assigned_to','state','first_attempt_at')
            ->from('callcrm_missed')
            ->where($q->expr()->in('phone',$q->createNamedParameter($phones,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->lte('first_at',$q->createNamedParameter(max($times))))
            ->andWhere($q->expr()->gte('last_at',$q->createNamedParameter(min($times))));
        TeamScope::prospects($q);
        $episodes = [];
        foreach ($q->executeQuery()->fetchAll() as $episode) $episodes[$episode['phone']][] = $episode;
        foreach ($rows as &$row) {
            $matches = array_values(array_filter($episodes[MissedCalls::phone($row['phone'])] ?? [],
                fn($episode)=>$row['at'] >= $episode['first_at'] && $row['at'] <= $episode['last_at']));
            $episode = count($matches)===1 ? $matches[0] : null;
            $row['agent'] = $users[$episode['assigned_to'] ?? ''] ?? '';
            $row['callback_state'] = !$episode ? null : ($episode['state']==='done' ? 'done'
                : ((int)$episode['first_attempt_at']>0 ? 'attempted' : 'open'));
            $row['prospect_id'] ??= $episode['prospect_id'] ?? null;
        }
        unset($row);
        return $rows;
    }

    private function callLabels(array $rows, array $users): array {
        $clients = $this->clients(array_column($rows,'prospect_id'));
        $variants = $numbers = [];
        foreach ($rows as $row) {
            if (!empty($row['prospect_id']) || empty($row['phone'])) continue;
            $numbers[$row['phone']] = YeastarEvents::phoneVariants($row['phone']);
            foreach ($numbers[$row['phone']] as $variant) $variants[$variant] = $variant;
        }
        $matches = [];
        if ($variants) {
            $q = $this->db->getQueryBuilder();
            $records = $q->select('id','phone','firstname','lastname','assigned_to')->from('callcrm_prospects')
                ->where($q->expr()->in('phone',$q->createNamedParameter(array_values($variants),
                    \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchAll();
            foreach ($records as $record) $matches[$record['phone']][] = $record;
        }
        foreach ($rows as &$row) {
            $client = $clients[$row['prospect_id'] ?? 0] ?? null;
            if (!$client && empty($row['prospect_id'])) {
                $found = [];
                foreach ($numbers[$row['phone']] ?? [] as $variant) {
                    foreach ($matches[$variant] ?? [] as $record) $found[$record['id']] = $record;
                }
                if (count($found)===1 && TeamScope::canSee(reset($found)['assigned_to'])) $client = reset($found);
            }
            $row['prospect_id'] = $client ? (int)$client['id'] : null;
            $row['client'] = $client ? trim(($client['firstname'] ?? '').' '.($client['lastname'] ?? '')) : '';
            $row['caller'] = isset($row['caller_agents'])
                ? implode(', ',array_intersect_key($users,array_fill_keys($row['caller_agents'],true)))
                : ($row['client'] ?: $row['phone']);
            $row['answered_by'] = implode(', ',array_intersect_key($users,array_fill_keys($row['responders'] ?? [],true)));
            unset($row['caller_agents'], $row['responders']);
        }
        unset($row);
        return $rows;
    }

    private function loggedCalls(array $uids, int $from, int $to): int {
        if (!$uids || $from >= $to) return 0;
        $q = $this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*'))->from('callcrm_calls')
            ->where($q->expr()->in('user_id',$q->createNamedParameter($uids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter($from)))
            ->andWhere($q->expr()->lt('created_at',$q->createNamedParameter($to)))->executeQuery()->fetchOne();
    }

    private function workload(array $uids, int $now): array {
        if (!$uids) return [];
        $out = [];
        foreach (['pending'=>['nouveau'],'late'=>\OCA\CallCrm\Service\Statuses::callbackKeys()] as $kind=>$statuses) {
            if (!$statuses) continue;
            $q = $this->db->getQueryBuilder();
            $q->select('assigned_to')->selectAlias($q->func()->count('*'),'total')->from('callcrm_prospects')
                ->where($q->expr()->in('assigned_to',$q->createNamedParameter($uids,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($q->expr()->in('status',$q->createNamedParameter($statuses,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->groupBy('assigned_to');
            if ($kind==='late') $q->andWhere($q->expr()->lt('callback_at',$q->createNamedParameter($now)));
            foreach ($q->executeQuery()->fetchAll() as $row) $out[$row['assigned_to']][$kind]=(int)$row['total'];
        }
        return $out;
    }

    /** Objectifs par commercial (jour, semaine) et appels réalisés ; lecture et écriture réservées aux responsables. */
    #[NoAdminRequired]
    public function goals(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $tz=new \DateTimeZone(Prefs::all()['tz']);$d=(new \DateTime('now',$tz))->setTime(0,0,0);$w=(clone $d)->modify('monday this week');
        $out=[];
        foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) {
            $uid=$u->getUID();if(!\OCA\CallCrm\Service\TeamScope::canTarget($uid))continue;$g=Prefs::goalFor($uid);
            $out[]=['uid'=>$uid,'name'=>$u->getDisplayName(),'day'=>$g['day'],'week'=>$g['week'],'personal'=>$g['personal'],
                'today'=>$this->calls($uid,$d->getTimestamp()),'this_week'=>$this->calls($uid,$w->getTimestamp())];
        }
        return new JSONResponse(['rows'=>$out,'default'=>Prefs::all()['goal']]);
    }
    #[NoAdminRequired]
    public function setGoal(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $uid=(string)$this->request->getParam('uid','');
        $known=false;foreach ($this->gm->get(Access::groups()['commercial'])?->getUsers()??[] as $u) if ($u->getUID()===$uid && \OCA\CallCrm\Service\TeamScope::canTarget($uid)) $known=true;
        if (!$known) return new JSONResponse(['error'=>'Commercial inconnu'],400);
        Prefs::setGoal($uid,(int)$this->request->getParam('day',0),(int)$this->request->getParam('week',0));
        return new JSONResponse(['ok'=>true]+Prefs::goalFor($uid));
    }
    private function calls(string $uid,int $since): int {
        $q=$this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*'))->from('callcrm_calls')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->gte('created_at',$q->createNamedParameter($since,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
    }
    #[NoAdminRequired]
    public function followup(): JSONResponse {
        if (!$this->manager()) return new JSONResponse(['error'=>'Accès refusé'],403);
        $id=filter_var($this->request->getParam('id'),FILTER_VALIDATE_INT);$state=$this->request->getParam('state');
        if (!$id || $id<1 || !is_string($state)) return new JSONResponse(['error'=>'Suivi invalide'],400);
        try { $this->dashboard->followup($id,$state,$this->access->uid()); return new JSONResponse(['ok'=>true]); }
        catch (\InvalidArgumentException $e) { return new JSONResponse(['error'=>$e->getMessage()],400); }
    }
}
