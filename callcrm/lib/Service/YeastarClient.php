<?php
namespace OCA\CallCrm\Service;

use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/** P-Series OpenAPI v1.0. No credentials or PBX responses are exposed to browsers. */
class YeastarClient {
    public function __construct(private YeastarSettings $settings, private IClientService $http, private ICacheFactory $caches, private ICrypto $crypto, private ILockingProvider $locks) {}

    private function request(string $path, array $payload, ?string $token = null): array {
        $s = $this->settings->credentials();
        if (!$s['client_id'] || !$s['secret']) throw new \RuntimeException('Enregistrez l’ID client et la clé secrète Yeastar.');
        $url = $s['url'].'/openapi/v1.0/'.$path;
        if ($token !== null) $url .= '?access_token='.rawurlencode($token);
        try {
            $response = $this->http->newClient()->post($url, [
                'headers' => ['User-Agent'=>'CallCRM-OpenAPI', 'Content-Type'=>'application/json'],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout'=>15, 'connect_timeout'=>5, 'allow_redirects'=>false,
                'verify'=>!$s['allow_self_signed'],
            ]);
            $body = (string)$response->getBody();
            if ($response->getStatusCode() !== 200 || strlen($body)>1048576) throw new \RuntimeException();
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // HTTP exceptions can contain the secret, token or complete request URL.
            throw new \RuntimeException('Connexion Yeastar impossible : vérifiez l’adresse, le réseau et le certificat HTTPS.');
        }
        if (!is_array($result) || !isset($result['errcode']) || (string)$result['errcode'] !== '0') {
            $code = is_scalar($result['errcode'] ?? null) ? substr((string)$result['errcode'],0,12) : '?';
            throw new \RuntimeException('Yeastar a refusé la requête (code '.$code.'). Vérifiez les identifiants et les permissions API.');
        }
        return $result;
    }

    private function token(): string {
        $s=$this->settings->credentials();
        $key=hash('sha256',json_encode([$s['url'],$s['client_id'],$s['secret'],$s['allow_self_signed']]));
        $cache=$this->caches->createDistributed('callcrm-yeastar');
        // file_locks.key is limited to 64 characters with the SQL locking provider.
        // Hash the namespace as well; prefix + a full SHA256 exceeds that limit.
        $resource=hash('sha256','callcrm-yeastar-token|'.$key);
        $cached=$cache->get($key);
        if (is_string($cached) && $cached!=='') return $this->crypto->decrypt($cached);
        $persistent=$this->settings->cachedToken($key);
        if ($persistent!==null) return $persistent;
        try {
            $this->locks->acquireLock($resource, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException $e) {
            throw new \RuntimeException('Une connexion Yeastar est déjà en cours. Réessayez dans quelques secondes.');
        }
        try {
            $cached=$cache->get($key);
            if (is_string($cached) && $cached!=='') return $this->crypto->decrypt($cached);
            $persistent=$this->settings->cachedToken($key);
            if ($persistent!==null) return $persistent;
            $r=$this->request('get_token',['username'=>$s['client_id'],'password'=>$s['secret']]);
            if (empty($r['access_token']) || !is_string($r['access_token'])) throw new \RuntimeException('Réponse Yeastar sans jeton API.');
            $ttl=max(1,min(1700,(int)($r['access_token_expire_time']??1800)-60));
            $cache->set($key,$this->crypto->encrypt($r['access_token']),$ttl);
            $this->settings->cacheToken($key,$r['access_token'],$ttl);
            return $r['access_token'];
        } finally { $this->locks->releaseLock($resource, ILockingProvider::LOCK_EXCLUSIVE); }
    }

    public function test(): void { $this->token(); }
    public function dial(string $extension, string $number): string {
        if (!preg_match('/^\d{1,10}$/D',$extension) || !preg_match('/^\+?\d{3,20}$/D',$number)) throw new \InvalidArgumentException('Extension ou numéro invalide.');
        // Never override PBX dial permissions; the commercial must answer in Linkus Web.
        $r=$this->request('call/dial',['caller'=>$extension,'callee'=>$number,'auto_answer'=>'no'],$this->token());
        return substr((string)($r['call_id']??''),0,128);
    }
}
