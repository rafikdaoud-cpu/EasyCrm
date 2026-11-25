<?php
namespace OCA\CallCrm\Controller;

use OCA\CallCrm\AppInfo\Application;
use OCA\CallCrm\Service\{Access, CallDashboard, Presence, PresenceRange, Prefs, Statuses};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\{AnonRateLimit, BruteForceProtection, NoAdminRequired, NoCSRFRequired, PublicPage};
use OCP\AppFramework\Http\{JSONResponse, TemplateResponse};
use OCP\{IConfig, IDBConnection, IGroupManager, IRequest, IURLGenerator};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Security\ISecureRandom;

/**
 * Écran du plateau (TV) : chiffres du jour en lecture seule, ouverts par un lien secret sans compte.
 * Le lien est créé ou renouvelé par un administrateur ; seul son empreinte est conservée.
 * Aucune donnée client n'est exposée (ni nom, ni numéro) : compteurs et noms des commerciaux uniquement.
 */
class WallController extends Controller {
    public function __construct(IRequest $request, private IConfig $config, private Access $access, private CallDashboard $dashboard,
        private Presence $presence, private IGroupManager $gm, private IDBConnection $db, private IURLGenerator $url, private ISecureRandom $random) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function valid(string $token): bool {
        $hash = $this->config->getAppValue('callcrm', 'wall_token_hash', '');
        return $hash !== '' && preg_match('/^[A-Za-z0-9]{40}$/D', $token) && hash_equals($hash, hash('sha256', $token));
    }

    private function link(string $token): string {
        return $this->url->getAbsoluteURL($this->url->linkToRoute('callcrm.wall.page', ['token' => $token]));
    }

    #[NoAdminRequired]
    public function settings(): JSONResponse {
        if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
        return new JSONResponse(['active' => $this->config->getAppValue('callcrm', 'wall_token_hash', '') !== '']);
    }

    /** Crée un nouveau lien (l'ancien cesse aussitôt de fonctionner) ou le désactive. */
    #[NoAdminRequired]
    public function token(): JSONResponse {
        if (!$this->access->can('fields')) return new JSONResponse(['error' => 'Accès refusé'], 403);
        if ($this->request->getParam('disable')) {
            $this->config->deleteAppValue('callcrm', 'wall_token_hash');
            return new JSONResponse(['active' => false]);
        }
        $token = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->config->setAppValue('callcrm', 'wall_token_hash', hash('sha256', $token));
        return new JSONResponse(['active' => true, 'url' => $this->link($token)]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'callcrm_wall')]
    public function page(string $token): TemplateResponse {
        $r = new TemplateResponse(Application::APP_ID, 'wall', ['api' => $this->url->linkToRoute('callcrm.wall.data', ['token' => $token])], TemplateResponse::RENDER_AS_BASE);
        $r->addHeader('Referrer-Policy', 'no-referrer');
        $r->addHeader('X-Robots-Tag', 'noindex, nofollow');
        if (!$this->valid($token)) $r->throttle(['action' => 'callcrm_wall']);
        return $r;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'callcrm_wall')]
    #[AnonRateLimit(limit: 60, period: 600)]
    public function data(string $token): JSONResponse {
        if (!$this->valid($token)) {
            $r = new JSONResponse(['error' => 'Lien invalide ou renouvelé.'], 404);
            $r->throttle(['action' => 'callcrm_wall']);
            return $r;
        }
        $now = time();
        $zone = new \DateTimeZone(Prefs::all()['tz']);
        $users = [];
        $groups = [Access::groups()['commercial']];
        foreach (Access::customProfiles() as $profile) if ($profile['base'] === 'commercial') $groups[] = $profile['group'];
        foreach ($groups as $group) {
            foreach ($this->gm->get($group)?->getUsers() ?? [] as $user) {
                if ($user->isEnabled()) $users[$user->getUID()] = $user->getDisplayName() ?: $user->getUID();
            }
        }
        $uids = array_map('strval', array_keys($users));
        $range = PresenceRange::resolve(['period' => 'day'], $zone, $now);
        $activity = $this->dashboard->activity($range, $uids, true, $zone);
        $presence = $this->presence->report($uids, $now, ['period' => 'day'])['rows'];
        $agents = [];
        foreach ($users as $uid => $name) {
            $a = $activity['agents'][$uid] ?? [];
            $agents[] = ['name' => $name, 'status' => $presence[$uid]['status'] ?? 'absent', 'processed' => (int)($presence[$uid]['processed'] ?? 0),
                'answered' => (int)($a['incoming'] ?? 0), 'outgoing' => (int)($a['outgoing'] ?? 0), 'reached' => (int)($a['outgoing_answered'] ?? 0)];
        }
        // Classement : dossiers traités (résultats d'appel saisis), puis appels décrochés et sortants aboutis.
        usort($agents, fn($a, $b) => [$b['processed'], $b['answered'] + $b['reached'], $b['outgoing']] <=> [$a['processed'], $a['answered'] + $a['reached'], $a['outgoing']]);
        $q = $this->db->getQueryBuilder();
        $openMissed = (int)$q->select($q->func()->count('*'))->from('callcrm_missed')
            ->where($q->expr()->eq('state', $q->createNamedParameter('open')))->executeQuery()->fetchOne();
        $late = 0;
        if ($keys = Statuses::callbackKeys()) {
            $q = $this->db->getQueryBuilder();
            $late = (int)$q->select($q->func()->count('*'))->from('callcrm_prospects')
                ->where($q->expr()->in('status', $q->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($q->expr()->lt('callback_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
        }
        $count = fn(string $status) => count(array_filter($agents, fn($a) => $a['status'] === $status));
        unset($activity['agents'], $activity['days']);
        return new JSONResponse([
            'now' => $now, 'timezone' => $zone->getName(), 'calls' => $activity,
            'team' => ['online' => $count('online'), 'pause' => $count('pause'), 'total' => count($agents)],
            'agents' => $agents, 'open_missed' => $openMissed, 'late_callbacks' => $late,
        ], 200, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
