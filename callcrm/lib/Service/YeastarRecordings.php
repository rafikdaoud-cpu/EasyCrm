<?php
namespace OCA\CallCrm\Service;

use OCP\IDBConnection;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Durable, private audio copies. Only controllers decide who can read them. */
class YeastarRecordings {
    public const MAX_SIZE = 67108864;
    public function __construct(private IDBConnection $db, private IAppData $data,
        private YeastarClient $client, private YeastarSettings $settings) {}

    public static function parse(array $event): ?array {
        if ((int)($event['type'] ?? 0) !== 30012) return null;
        if (isset($event['sn']) && !is_scalar($event['sn'])) throw new \InvalidArgumentException('CDR invalide.');
        $m = $event['msg'] ?? [];
        if (is_string($m)) $m = json_decode($m, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($m)) throw new \InvalidArgumentException('CDR invalide.');
        foreach (['call_id','call_from','call_to','type','recording','time_start','status','uid'] as $key) {
            if (isset($m[$key]) && !is_scalar($m[$key])) throw new \InvalidArgumentException('CDR invalide.');
        }
        $direction = strtolower((string)($m['type'] ?? ''));
        if (!in_array($direction, ['inbound','outbound'], true)) return null;
        $call = (string)($m['call_id'] ?? '');
        $file = (string)($m['recording'] ?? '');
        if ($call === '' || strlen($call) > 128 || strlen($file) > 255) throw new \InvalidArgumentException('CDR invalide.');
        if ($file !== '' && !self::validFilename($file)) throw new \InvalidArgumentException('Nom audio invalide.');
        $phone = YeastarEvents::number((string)($m[$direction === 'inbound' ? 'call_from' : 'call_to'] ?? ''));
        $extension = (string)($m[$direction === 'inbound' ? 'call_to' : 'call_from'] ?? '');
        if (!preg_match('/^\+?\d{3,20}$/D', $phone) || strlen($extension) > 32) throw new \InvalidArgumentException('Numéro CDR invalide.');
        $duration = filter_var($m['talk_duration'] ?? 0, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 0 || $duration > 604800) throw new \InvalidArgumentException('Durée CDR invalide.');
        return ['source_key'=>hash('sha256', json_encode([(string)($event['sn'] ?? ''), $call, (string)($m['uid'] ?? ''), $file])),
            'call_id'=>$call, 'phone'=>$phone, 'extension'=>$extension, 'direction'=>$direction,
            'call_status'=>substr((string)($m['status'] ?? ''),0,32), 'started_at'=>substr((string)($m['time_start'] ?? ''),0,32),
            'talk_duration'=>$duration, 'recording'=>$file];
    }

    public static function validFilename(string $name): bool {
        return strlen($name) <= 255 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+ -]*\.(wav|mp3)$/iD', $name)
            && !str_contains($name, '..');
    }

    public function find(int $id): ?array {
        $q=$this->db->getQueryBuilder();
        return $q->select('*')->from('callcrm_pbx_recordings')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch() ?: null;
    }
    private function update(int $id, array $values): void {
        $q=$this->db->getQueryBuilder();$q->update('callcrm_pbx_recordings');
        foreach($values as $key=>$value) $q->set($key,$q->createNamedParameter($value));
        $q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
    }
    public function list(?int $pid, int $offset=0): array {
        $q=$this->db->getQueryBuilder();$q->select('*')->from('callcrm_pbx_recordings');
        $q->where($pid===null ? $q->expr()->isNull('prospect_id') : $q->expr()->eq('prospect_id',$q->createNamedParameter($pid)));
        $rows=$q->orderBy('id','DESC')->setFirstResult(max(0,$offset))->setMaxResults(51)->executeQuery()->fetchAll();
        return ['rows'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['id','phone','extension','user_id','direction','call_status','started_at','talk_duration','state','error','size','created_at'])),array_slice($rows,0,50)), 'has_more'=>count($rows)>50];
    }
    /** Liste filtrée de tous les appels (50 par page), avec le nom du client rattaché. */
    public function search(array $f, int $offset=0): array {
        $q=$this->db->getQueryBuilder();
        $q->select('r.id','r.phone','r.extension','r.user_id','r.direction','r.call_status','r.started_at','r.talk_duration','r.state','r.error','r.size','r.created_at','r.prospect_id','p.lastname','p.firstname')
          ->from('callcrm_pbx_recordings','r')->leftJoin('r','callcrm_prospects','p',$q->expr()->eq('p.id','r.prospect_id'));
        $w=[];
        if($f['q']!==''){$like='%'.$this->db->escapeLikeParameter($f['q']).'%';$w[]=$q->expr()->orX($q->expr()->like('r.phone',$q->createNamedParameter($like)),$q->expr()->like('p.lastname',$q->createNamedParameter($like)),$q->expr()->like('p.firstname',$q->createNamedParameter($like)),$q->expr()->like('r.extension',$q->createNamedParameter($like)));}
        if($f['direction']!=='')$w[]=$q->expr()->eq('r.direction',$q->createNamedParameter($f['direction']));
        if($f['user']!=='')$w[]=$q->expr()->eq('r.user_id',$q->createNamedParameter($f['user']));
        if($f['from']!=='')$w[]=$q->expr()->gte('r.started_at',$q->createNamedParameter($f['from'].' 00:00:00'));
        if($f['to']!=='')$w[]=$q->expr()->lte('r.started_at',$q->createNamedParameter($f['to'].' 23:59:59'));
        if($f['audio'])$w[]=$q->expr()->eq('r.state',$q->createNamedParameter('ready'));
        if($f['link']==='linked')$w[]=$q->expr()->isNotNull('r.prospect_id');
        if($f['link']==='unlinked')$w[]=$q->expr()->isNull('r.prospect_id');
        foreach($w as $c)$q->andWhere($c);
        $rows=$q->orderBy('r.id','DESC')->setFirstResult(max(0,$offset))->setMaxResults(51)->executeQuery()->fetchAll();
        return ['rows'=>array_map(function($r){$r['client']=$r['prospect_id']?trim(($r['lastname']??'').' '.($r['firstname']??'')):'';unset($r['lastname'],$r['firstname']);return $r;},array_slice($rows,0,50)),'has_more'=>count($rows)>50];
    }
    public function attach(int $id, int $pid): void { $this->update($id,['prospect_id'=>$pid]); }
    public function retry(int $id): void {
        $r=$this->find($id);
        if (!$r || $r['state']!=='failed') throw new \InvalidArgumentException('Seul un téléchargement en échec peut être relancé.');
        $q=$this->db->getQueryBuilder();$q->update('callcrm_pbx_recordings');
        foreach(['state'=>'queued','attempts'=>0,'next_attempt'=>0,'lease_until'=>0,'error'=>''] as $k=>$v)$q->set($k,$q->createNamedParameter($v));
        $q->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('failed')))->executeStatement();
    }
    private function folder(string $name) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$name)) throw new \RuntimeException('Dossier audio invalide.');
        try { return $this->data->getFolder($name); }
        catch (NotFoundException $e) { return $this->data->newFolder($name); }
    }
    public function bytes(array $row): string {
        if ($row['state']!=='ready') throw new \RuntimeException('Audio indisponible.');
        return $this->folder($row['storage_folder'])->getFile($row['source_key'])->getContent();
    }
    public function processQueue(): void {
        $config=$this->settings->settings();
        if (!$config['enabled'] || !$config['recordings_enabled']) return;
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('*')->from('callcrm_pbx_recordings')
            ->where($q->expr()->in('state',$q->createNamedParameter(['queued','processing'],IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->lt('next_attempt',$q->createNamedParameter(time())))
            ->andWhere($q->expr()->lt('lease_until',$q->createNamedParameter(time())))
            ->orderBy('id','ASC')->setMaxResults(50)->executeQuery()->fetchAll();
        $deadline=microtime(true)+180;
        foreach ($rows as $row) {
            if(microtime(true)>=$deadline)break;
            // Atomic lease prevents overlapping cron workers from downloading twice.
            $claim=$this->db->getQueryBuilder();$claim->update('callcrm_pbx_recordings')
                ->set('state',$claim->createNamedParameter('processing'))
                ->set('lease_until',$claim->createNamedParameter(time()+600))
                ->where($claim->expr()->eq('id',$claim->createNamedParameter($row['id'])))
                ->andWhere($claim->expr()->eq('state',$claim->createNamedParameter($row['state'])))
                ->andWhere($claim->expr()->eq('lease_until',$claim->createNamedParameter($row['lease_until'])));
            if ($claim->executeStatement()!==1) continue;
            $error='L’adresse Yeastar a changé depuis cet appel : vérifier le serveur source.';
            try {
                if($row['pbx_url']!==$config['url'])throw new \RuntimeException('Adresse PBX modifiée.');
                $error='Audio indisponible : vérifier le fichier Yeastar, les droits API, le réseau et la taille maximale configurée.';
                $audio=$this->client->downloadRecording($row['recording']);
                $error='Écriture impossible : vérifier l’espace et les droits du stockage Nextcloud.';
                $folder=$this->folder($row['storage_folder']);
                try { $file=$folder->getFile($row['source_key']); }
                catch(NotFoundException $e) { $file=$folder->newFile($row['source_key']); }
                // A deterministic private name makes retry after a worker crash safe.
                $file->putContent($audio['bytes']);
                $this->update((int)$row['id'],['state'=>'ready','mime'=>$audio['mime'],'size'=>strlen($audio['bytes']),'lease_until'=>0,'error'=>'']);
            } catch (\Throwable $e) {
                $attempt=(int)$row['attempts']+1;
                $this->update((int)$row['id'],['state'=>$attempt>=12?'failed':'queued','attempts'=>$attempt,
                    'next_attempt'=>time()+min(3600,60*(2**min($attempt,6))),'lease_until'=>0,
                    'error'=>$error]);
            }
        }
    }
}
