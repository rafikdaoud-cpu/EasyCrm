<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;

/** Simulateur de devis « cuisine équipée » : paramètres modifiables + calcul (identique au calcul JavaScript de l'écran). */
class Sim {
	public const DEF = [
		'models' => [['name' => 'Classique', 'price' => 37000], ['name' => 'Casquette', 'price' => 42000]],
		'extras' => [['name' => 'Tiroir', 'price' => 5000], ['name' => 'Casserolier', 'price' => 5000]],
		'deduct' => 0.6,      // m retirés par équipement (cuisinière, lave-linge, lave-vaisselle) et si pas de colonne
		'depth' => 0.65, 'm2' => 17500, 'labour' => 15000, 'plinth' => 5000, 'plinth_ml' => 0,
		'wt_deduct' => 0.6, 'coef' => 1.3, 'range' => 10, 'round' => 1000,
	];
	private static function cfg(): IConfig { return \OCP\Server::get(IConfig::class); }
	public static function all(): array {
		$j = json_decode((string)self::cfg()->getAppValue('callcrm', 'sim_cfg', ''), true);
		$c = is_array($j) ? array_replace(self::DEF, $j) : self::DEF;
		foreach (['models', 'extras'] as $k) { if (!is_array($c[$k]) || !$c[$k]) { $c[$k] = self::DEF[$k]; } }
		return $c;
	}
	private static function lines($in, string $what): array {
		$out = [];
		foreach ((array)$in as $x) {
			$n = trim((string)($x['name'] ?? '')); $p = (float)($x['price'] ?? 0);
			if ($n === '') { continue; }
			if ($p < 0 || $p > 100000000) { throw new \InvalidArgumentException('Prix invalide : ' . $n); }
			$out[] = ['name' => mb_substr($n, 0, 60), 'price' => $p];
		}
		if (!$out) { throw new \InvalidArgumentException('Au moins un élément requis : ' . $what); }
		return $out;
	}
	public static function save(array $in): array {
		$c = self::all();
		$c['models'] = self::lines($in['models'] ?? $c['models'], 'modèles');
		$c['extras'] = self::lines($in['extras'] ?? $c['extras'], 'suppléments');
		foreach (['deduct' => 5, 'depth' => 3, 'm2' => 1e8, 'labour' => 1e8, 'plinth' => 1e8, 'wt_deduct' => 5, 'coef' => 10, 'range' => 50, 'round' => 100000] as $k => $max) {
			if (isset($in[$k])) { $v = (float)$in[$k]; if ($v < 0 || $v > $max) { throw new \InvalidArgumentException('Valeur invalide : ' . $k); } $c[$k] = $v; }
		}
		if (isset($in['plinth_ml'])) { $c['plinth_ml'] = $in['plinth_ml'] ? 1 : 0; }
		self::cfg()->setAppValue('callcrm', 'sim_cfg', json_encode($c, JSON_UNESCAPED_UNICODE));
		return $c;
	}
	/** $in : w, l (m), hl (haut et bas), column, cooker, washer, dish (bool), model (nom), extras {nom: nombre}. */
	public static function compute(array $c, array $in): array {
		$w = (float)($in['w'] ?? 0); $l = (float)($in['l'] ?? 0);
		if ($w < 0.5 || $w > 20 || $l < 0.5 || $l > 20) { throw new \InvalidArgumentException('Largeur et longueur : entre 0,5 et 20 m'); }
		$price = null;
		foreach ($c['models'] as $m) { if ($m['name'] === ($in['model'] ?? '')) { $price = (float)$m['price']; } }
		if ($price === null) { throw new \InvalidArgumentException('Modèle inconnu'); }
		$ded = (empty($in['column']) ? $c['deduct'] : 0) + $c['deduct'] * (!empty($in['cooker']) + !empty($in['washer']) + !empty($in['dish']));
		$net = max(0, $w + $l - $ded);
		$mult = !empty($in['hl']) ? 2 : 1;
		$furn = $net * $mult * $price;
		$ex = 0; $exl = [];
		foreach ($c['extras'] as $e) {
			$n = max(0, min(20, (int)($in['extras'][$e['name']] ?? 0)));
			if ($n) { $ex += $n * $e['price']; $exl[$e['name']] = $n; }
		}
		$kitchen = $furn + $ex;
		$len = max(0, $w + $l - $c['wt_deduct']);
		$area = $len * $c['depth'];
		$wt = ($area * $c['m2'] + $c['labour'] + ($c['plinth_ml'] ? $c['plinth'] * $len : $c['plinth'])) * $c['coef'];
		$total = $kitchen + $wt;
		return ['net' => $net, 'furniture' => $furn, 'extras' => $ex, 'kitchen' => $kitchen, 'worktop' => $wt, 'total' => $total, 'extras_n' => $exl];
	}
}
