<?php
namespace OCA\CallCrm\Service;

use OCP\IConfig;

class WaChatbot {
	private const TEXTS = [
		'welcome' => 'Bonjour • مرحباً بكم في CSM Taksit. Choisissez votre langue / اختاروا اللغة.',
		'fr' => ['product'=>'Quel produit recherchez-vous ?', 'moto'=>'Moto', 'appliance'=>'Électroménager',
			'agent'=>'Un commercial', 'model'=>'Quel modèle recherchez-vous ?', 'wilaya'=>'Dans quelle wilaya êtes-vous ?',
			'handoff'=>'Un commercial reprendra votre demande ici. Merci !'],
		'ar' => ['product'=>'ما المنتج الذي تبحثون عنه؟', 'moto'=>'دراجة نارية', 'appliance'=>'أجهزة منزلية',
			'agent'=>'التحدث إلى مستشار', 'model'=>'ما هو الموديل المطلوب؟', 'wilaya'=>'في أي ولاية تقيمون؟',
			'handoff'=>'سيتابع أحد مستشارينا طلبكم هنا. شكراً لكم.'],
	];
	private const COLUMNS = [
		'catalog'=>['model'=>120,'aliases'=>300,'price'=>24,'availability'=>100,'updated'=>10],
		'faq'=>['question_fr'=>300,'question_ar'=>300,'answer_fr'=>1200,'answer_ar'=>1200],
	];

	public function __construct(private IConfig $config) {}

	public function get(): array {
		$raw = json_decode($this->config->getAppValue('callcrm','wa_chatbot',''),true);
		return is_array($raw) ? $raw : ['texts'=>self::TEXTS,'catalog'=>[],'faq'=>[]];
	}

	public function save(array $draft): array {
		$texts = $draft['texts'] ?? [];
		$clean = ['texts'=>['welcome'=>self::text($texts['welcome'] ?? null,900,'Accueil')]];
		foreach (['fr','ar'] as $lang) {
			foreach (array_keys(self::TEXTS[$lang]) as $key) {
				$limit = in_array($key,['moto','appliance','agent'],true) ? 20 : 900;
				$clean['texts'][$lang][$key] = self::text($texts[$lang][$key] ?? null,$limit,$lang.' / '.$key);
			}
		}
		foreach (self::COLUMNS as $sheet=>$columns) {
			$rows = $draft[$sheet] ?? null;
			if (!is_array($rows) || !array_is_list($rows) || count($rows)>500) {
				throw new \InvalidArgumentException('Maximum 500 lignes par onglet.');
			}
			$clean[$sheet] = [];
			foreach ($rows as $index=>$row) {
				if (!is_array($row)) throw new \InvalidArgumentException('Ligne Excel invalide.');
				$values = [];
				foreach ($columns as $key=>$limit) {
					$optional = in_array($key,['aliases','availability','price'],true);
					$values[$key] = self::text($row[$key] ?? '',$limit,$sheet.' · ligne '.($index+2).' · '.$key,$optional);
				}
				if ($sheet==='catalog') {
					$date = \DateTimeImmutable::createFromFormat('!Y-m-d',$values['updated']);
					if (!$date || $date->format('Y-m-d') !== $values['updated']) {
						throw new \InvalidArgumentException('Catalogue · ligne '.($index+2).' · date attendue au format AAAA-MM-JJ.');
					}
					$price = str_replace(',','.',preg_replace('/\s+/u','',$values['price']));
					if ($price!=='' && !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$price)) {
						throw new \InvalidArgumentException('Catalogue · ligne '.($index+2).' · prix en DA invalide.');
					}
					$values['price'] = $price;
				}
				$clean[$sheet][] = $values;
			}
		}
		$json = json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		if (strlen($json)>60000) throw new \InvalidArgumentException('Le catalogue et la FAQ dépassent 60 Ko de texte.');
		$this->config->setAppValue('callcrm','wa_chatbot',$json);
		return $clean;
	}

	private static function text(mixed $value, int $limit, string $field, bool $optional=false): string {
		$value = is_string($value) ? trim($value) : null;
		if ($value===null || (!$optional && $value==='') || mb_strlen($value)>$limit) {
			throw new \InvalidArgumentException($field.' · '.($optional?'':'texte obligatoire, ').$limit.' caractères maximum.');
		}
		return $value;
	}
}
