<?php
namespace OCA\EasyCrm\Service;

/** Listes de référence et correction automatique des valeurs importées (français / arabe) */
class Lookup {
	/** Nom français => variantes (arabe, orthographes courantes). L'ordre donne le code de la wilaya (1 à 58). */
	public const WILAYAS = [
		'Adrar' => ['أدرار'], 'Chlef' => ['الشلف', 'chelef', 'ech chelif', 'chef'], 'Laghouat' => ['الأغواط', 'el aghouat', 'aghouat'],
		'Oum El Bouaghi' => ['أم البواقي', 'oum bouaghi', 'el bouaghi'], 'Batna' => ['باتنة'], 'Béjaïa' => ['بجاية', 'bgayet', 'bougie'],
		'Biskra' => ['بسكرة'], 'Béchar' => ['بشار'], 'Blida' => ['البليدة', 'بليدة'], 'Bouira' => ['البويرة', 'بويرة'],
		'Tamanrasset' => ['تمنراست', 'tamenghasset', 'tamanghasset'], 'Tébessa' => ['تبسة'], 'Tlemcen' => ['تلمسان'], 'Tiaret' => ['تيارت'],
		'Tizi Ouzou' => ['تيزي وزو', 'tizi wezzu'], 'Alger' => ['الجزائر', 'الجزائر العاصمة', 'algiers'], 'Djelfa' => ['الجلفة'], 'Jijel' => ['جيجل'],
		'Sétif' => ['سطيف'], 'Saïda' => ['سعيدة'], 'Skikda' => ['سكيكدة'], 'Sidi Bel Abbès' => ['سيدي بلعباس', 'sba'],
		'Annaba' => ['عنابة'], 'Guelma' => ['قالمة'], 'Constantine' => ['قسنطينة'], 'Médéa' => ['المدية', 'lemdiyya'],
		'Mostaganem' => ['مستغانم'], "M'Sila" => ['المسيلة', 'msila'], 'Mascara' => ['معسكر', 'mascaras', 'mouaskar'], 'Ouargla' => ['ورقلة', 'wargla'],
		'Oran' => ['وهران'], 'El Bayadh' => ['البيض'], 'Illizi' => ['إليزي', 'اليزي'], 'Bordj Bou Arréridj' => ['برج بوعريريج', 'bba', 'bordj bou arrerij'],
		'Boumerdès' => ['بومرداس'], 'El Tarf' => ['الطارف'], 'Tindouf' => ['تندوف'], 'Tissemsilt' => ['تيسمسيلت'],
		'El Oued' => ['الوادي', 'eloued'], 'Khenchela' => ['خنشلة'], 'Souk Ahras' => ['سوق أهراس'], 'Tipaza' => ['تيبازة', 'tipasa'],
		'Mila' => ['ميلة'], 'Aïn Defla' => ['عين الدفلى'], 'Naâma' => ['النعامة'], 'Aïn Témouchent' => ['عين تموشنت', 'ain tmouchent'],
		'Ghardaïa' => ['غرداية'], 'Relizane' => ['غليزان'], 'Timimoun' => ['تيميمون'], 'Bordj Badji Mokhtar' => ['برج باجي مختار', 'bbm'],
		'Beni Abbès' => ['بني عباس'], 'Ouled Djellal' => ['أولاد جلال', 'ouled djelal'], 'In Salah' => ['عين صالح', 'ain salah', 'a salah'],
		'In Guezzam' => ['عين قزام', 'ain guezzam'], 'Touggourt' => ['تقرت', 'tougourt'], 'Djanet' => ['جانت'],
		"El M'Ghair" => ['المغير', 'el meghaier'], 'El Ménia' => ['المنيعة', 'el meniaa'],
	];

	public static function latin(string $s): string {
		return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
	}

	/** Minuscules, sans accents ni signes arabes, alef/ya/ta marbouta unifiés, ponctuation remplacée par des espaces */
	public static function fold(string $s): string {
		$s = mb_strtolower(trim($s));
		if (class_exists('Normalizer')) { $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s; }
		$s = preg_replace('/[\p{Mn}\x{0640}]/u', '', $s);
		$s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
		return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
	}

	/** Retrouve la valeur de liste correspondante (sans tenir compte de la casse, des accents ni de l'arabe/latin), sinon chaîne vide */
	public static function canon(string $raw, array $opts): string {
		$f = self::fold($raw);
		if ($f === '') { return ''; }
		foreach ($opts as $o) { if (self::fold((string)$o) === $f) { return (string)$o; } }
		$hits = [];
		foreach ($opts as $o) {
			$of = self::fold((string)$o);
			if ($of === '' || mb_strlen($of) < 3) { continue; }
			if (str_contains(' ' . $f . ' ', ' ' . $of . ' ') || (mb_strlen($f) >= 4 && str_starts_with($of, $f)) || (mb_strlen($of) >= 4 && str_starts_with($f, $of))) { $hits[] = (string)$o; }
		}
		return count($hits) === 1 ? $hits[0] : '';
	}

	public static function wilaya(string $raw): string {
		$f = self::fold(self::latin($raw));
		if ($f === '') { return ''; }
		if (ctype_digit($f)) { return array_keys(self::WILAYAS)[(int)$f - 1] ?? ''; }
		static $idx = null;
		if ($idx === null) {
			$idx = [];
			foreach (self::WILAYAS as $fr => $alts) { foreach (array_merge([$fr], $alts) as $a) { $idx[self::fold($a)] = $fr; } }
			uksort($idx, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
		}
		if (isset($idx[$f])) { return $idx[$f]; }
		$pad = ' ' . $f . ' ';
		foreach ($idx as $a => $fr) { if (str_contains($pad, ' ' . $a . ' ')) { return $fr; } }
		foreach ($idx as $a => $fr) { if (mb_strlen($a) >= 5 && str_contains($f, $a)) { return $fr; } }
		return '';
	}
}
