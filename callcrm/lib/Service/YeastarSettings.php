<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;

/** Configuration only: this service does not place calls or receive webhooks. */
class YeastarSettings {
    public function __construct(private IConfig $config, private ICrypto $crypto) {}

    private function stored(): array {
        $value = json_decode($this->config->getAppValue('callcrm', 'yeastar_settings', '{}'), true);
        return is_array($value) ? $value : [];
    }

    public function settings(): array {
        $s = $this->stored();
        return [
            'url' => $s['url'] ?? 'https://erp.csmtaksit.dz:8088',
            'client_id' => $s['client_id'] ?? '',
            'has_secret' => !empty($s['secret']),
            'linkus_url' => $s['linkus_url'] ?? '',
            'extensions' => $s['extensions'] ?? [],
        ];
    }

    private function url(string $value, bool $base): string {
        $value = trim($value);
        $p = parse_url($value);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || preg_match('/[\s\x00-\x1f\\\\]/', $value)
            || ($base && !in_array($p['path'] ?? '', ['', '/'], true))) {
            throw new \InvalidArgumentException($base
                ? 'Adresse P550 invalide : utilisez https://serveur:port sans /integration/api.'
                : 'Adresse Linkus invalide : utilisez une adresse HTTPS sans identifiants ni paramètres.');
        }
        return $base ? rtrim($value, '/') : $value;
    }

    public function save(array $input): array {
        $s = $this->stored();
        $cur = $this->settings();
        $s['url'] = $this->url((string)($input['url'] ?? $cur['url']), true);
        $s['linkus_url'] = trim((string)($input['linkus_url'] ?? $cur['linkus_url']));
        if ($s['linkus_url'] !== '') $s['linkus_url'] = $this->url($s['linkus_url'], false);
        $s['client_id'] = trim((string)($input['client_id'] ?? $cur['client_id']));
        if (strlen($s['client_id']) > 255 || preg_match('/[\x00-\x1f]/', $s['client_id'])) {
            throw new \InvalidArgumentException('ID client invalide.');
        }
        $rows = $input['extensions'] ?? $cur['extensions'];
        if (!is_array($rows) || count($rows) > 1000) throw new \InvalidArgumentException('Liste des extensions invalide.');
        $uids = []; $numbers = []; $s['extensions'] = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Association commercial / extension invalide.');
            $uid = trim((string)($row['uid'] ?? ''));
            $extension = trim((string)($row['extension'] ?? ''));
            if ($uid === '' || strlen($uid) > 255 || !preg_match('/^\d{1,10}$/D', $extension)) {
                throw new \InvalidArgumentException('Choisissez un commercial et une extension numérique (10 chiffres maximum).');
            }
            if (isset($uids[$uid]) || isset($numbers[$extension])) {
                throw new \InvalidArgumentException('Chaque commercial et chaque extension doivent apparaître une seule fois.');
            }
            $uids[$uid] = true; $numbers[$extension] = true;
            $s['extensions'][] = ['uid' => $uid, 'extension' => $extension];
        }
        $secret = (string)($input['secret'] ?? '');
        if (strlen($secret) > 4096) throw new \InvalidArgumentException('Clé secrète trop longue.');
        if (!empty($input['clear_secret']) && $secret !== '') {
            throw new \InvalidArgumentException('Choisissez de remplacer ou de supprimer la clé secrète.');
        }
        // Credentials belong to one API identity: never retain an old secret for a new server/ID.
        if (!empty($input['clear_secret']) || $s['url'] !== $cur['url'] || $s['client_id'] !== $cur['client_id']) {
            unset($s['secret']);
        }
        if ($secret !== '') $s['secret'] = $this->crypto->encrypt($secret);
        // Validate everything before writing one configuration value.
        $this->config->setAppValue('callcrm', 'yeastar_settings', json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->settings();
    }
}
