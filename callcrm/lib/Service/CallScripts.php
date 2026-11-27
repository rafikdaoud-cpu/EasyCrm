<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

use OCP\IConfig;

/**
 * Guides d'appel : étapes à suivre par le commercial pendant un appel (entrant, premier contact, relance),
 * en français et en arabe, avec réponses aux objections. Modifiables dans Administration → Guides d'appel.
 * Variables dans les textes : {prenom}, {nom}, {commercial}.
 * Type d'étape : « text » (phrase à dire), « ask » (question : la réponse est notée sur la fiche), « eligibility » (contrôle).
 */
class CallScripts {
	public const KINDS = ['inbound' => 'Appel entrant', 'outbound_new' => 'Premier contact (appel sortant, fiche nouvelle)', 'outbound' => 'Relance (appel sortant, autres fiches)'];
	private const STEP_TYPES = ['text', 'ask', 'eligibility'];
	private const MAX_GUIDES = 12;
	private const MAX_STEPS = 25;
	private const MAX_OBJECTIONS = 20;

	public function __construct(private IConfig $config) {}

	public function get(): array {
		$v = json_decode($this->config->getAppValue('callcrm', 'call_scripts', ''), true);
		return is_array($v) && isset($v['guides']) ? $v : self::defaults();
	}

	public function save(array $in): array {
		$guides = [];
		foreach (array_slice((array)($in['guides'] ?? []), 0, self::MAX_GUIDES) as $g) {
			if (!is_array($g)) continue;
			$name = self::clean($g['name'] ?? '', 80);
			$kind = (string)($g['kind'] ?? '');
			if ($name === '' || !isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Chaque guide doit avoir un nom et un type d’appel.');
			$steps = [];
			foreach (array_slice((array)($g['steps'] ?? []), 0, self::MAX_STEPS) as $s) {
				if (!is_array($s)) continue;
				$step = ['title' => self::clean($s['title'] ?? '', 80), 'fr' => self::clean($s['fr'] ?? '', 1000), 'ar' => self::clean($s['ar'] ?? '', 1000),
					'type' => in_array($s['type'] ?? 'text', self::STEP_TYPES, true) ? (string)$s['type'] : 'text', 'required' => !empty($s['required'])];
				if ($step['title'] === '' && $step['fr'] === '' && $step['ar'] === '') continue;
				if ($step['title'] === '') throw new \InvalidArgumentException('Chaque étape doit avoir un titre (guide « ' . $name . ' »).');
				$steps[] = $step;
			}
			if (!$steps) throw new \InvalidArgumentException('Le guide « ' . $name . ' » n’a aucune étape.');
			$guides[] = ['name' => $name, 'kind' => $kind, 'steps' => $steps];
		}
		$objections = [];
		foreach (array_slice((array)($in['objections'] ?? []), 0, self::MAX_OBJECTIONS) as $o) {
			if (!is_array($o)) continue;
			$row = ['label' => self::clean($o['label'] ?? '', 60), 'fr' => self::clean($o['fr'] ?? '', 1000), 'ar' => self::clean($o['ar'] ?? '', 1000)];
			if ($row['label'] === '' && $row['fr'] === '' && $row['ar'] === '') continue;
			if ($row['label'] === '' || ($row['fr'] === '' && $row['ar'] === '')) throw new \InvalidArgumentException('Chaque objection doit avoir un titre et une réponse.');
			$objections[] = $row;
		}
		$v = ['guides' => $guides, 'objections' => $objections];
		$this->config->setAppValue('callcrm', 'call_scripts', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $v;
	}

	private static function clean(mixed $v, int $max): string {
		return mb_substr(trim(str_replace("\r", '', is_scalar($v) ? (string)$v : '')), 0, $max);
	}

	/** Première rédaction, à corriger par l'équipe. */
	public static function defaults(): array {
		$hello = ['title' => 'Accueil', 'type' => 'text', 'required' => true];
		$record = ['title' => 'Enregistrement de l’appel', 'type' => 'text', 'required' => true,
			'fr' => 'Je vous informe que cet appel peut être enregistré pour la qualité du service.',
			'ar' => 'نعلمكم أن هذه المكالمة قد تُسجَّل لتحسين جودة الخدمة.'];
		$need = ['title' => 'Produit recherché', 'type' => 'ask', 'required' => false,
			'fr' => 'Quel produit vous intéresse ? Électroménager, moto, meubles ou aménagement ? Avez-vous un modèle en tête ?',
			'ar' => 'ما هو المنتج الذي يهمكم؟ أجهزة كهرومنزلية، دراجة نارية، أثاث أو تهيئة؟ هل لديكم موديل معين؟'];
		$wilaya = ['title' => 'Wilaya', 'type' => 'ask', 'required' => false,
			'fr' => 'Dans quelle wilaya habitez-vous ?', 'ar' => 'في أي ولاية تقيمون؟'];
		$job = ['title' => 'Situation professionnelle', 'type' => 'ask', 'required' => false,
			'fr' => 'Êtes-vous salarié, retraité ou militaire ? Votre salaire est-il versé sur un compte CCP ou bancaire ?',
			'ar' => 'هل أنتم أجير، متقاعد أو عسكري؟ هل يُصبّ راتبكم في حساب بريدي أو بنكي؟'];
		$elig = ['title' => 'Éligibilité', 'type' => 'eligibility', 'required' => false,
			'fr' => 'Quel est votre âge et votre salaire mensuel net ? Avez-vous déjà des mensualités en cours ?',
			'ar' => 'كم عمركم وما هو راتبكم الشهري الصافي؟ هل لديكم أقساط جارية؟'];
		$offer = ['title' => 'Proposition', 'type' => 'text', 'required' => false,
			'fr' => 'Nous vous proposons l’achat par facilité, sans apport, de 12 à 60 mois avec nos banques partenaires, et la livraison est gratuite dans toute l’Algérie. Je vous indique la mensualité sur 60 mois pour le produit choisi.',
			'ar' => 'نقترح عليكم الشراء بالتقسيط بدون دفعة أولى، من 12 إلى 60 شهرًا مع بنوكنا الشريكة، والتوصيل مجاني إلى كامل التراب الوطني. سأعطيكم القسط الشهري على 60 شهرًا للمنتج المختار.'];
		$docs = ['title' => 'Documents', 'type' => 'text', 'required' => false,
			'fr' => 'Je vous envoie maintenant la liste des documents à fournir par SMS ou WhatsApp.',
			'ar' => 'سأرسل لكم الآن قائمة الوثائق المطلوبة عبر رسالة قصيرة أو واتساب.'];
		$end = ['title' => 'Conclusion', 'type' => 'text', 'required' => true,
			'fr' => 'Avez-vous d’autres questions ? Je reste à votre disposition. Merci de votre confiance, bonne journée.',
			'ar' => 'هل لديكم أسئلة أخرى؟ أبقى في خدمتكم. شكرا على ثقتكم، يوما سعيدا.'];
		return [
			'guides' => [
				['name' => 'Appel entrant', 'kind' => 'inbound', 'steps' => [
					$hello + ['fr' => 'CSM Taksit bonjour, {commercial} à votre écoute. Puis-je avoir votre nom, s’il vous plaît ?',
						'ar' => 'CSM Taksit مرحبا، معكم {commercial}. هل يمكنني معرفة اسمكم من فضلكم؟'],
					$record, $need, $wilaya, $job, $elig, $offer, $docs, $end]],
				['name' => 'Premier contact', 'kind' => 'outbound_new', 'steps' => [
					$hello + ['fr' => 'Bonjour {prenom}, ici {commercial} de CSM Taksit. Vous avez demandé des informations sur l’achat par facilité : avez-vous quelques minutes pour en parler ?',
						'ar' => 'مرحبا {prenom}، معكم {commercial} من CSM Taksit. طلبتم معلومات حول الشراء بالتقسيط: هل لديكم بضع دقائق للحديث عن ذلك؟'],
					$record, $need, $wilaya, $job, $elig, $offer, $docs, $end]],
				['name' => 'Relance', 'kind' => 'outbound', 'steps' => [
					$hello + ['fr' => 'Bonjour {prenom}, ici {commercial} de CSM Taksit. Je vous rappelle au sujet de votre demande d’achat par facilité.',
						'ar' => 'مرحبا {prenom}، معكم {commercial} من CSM Taksit. أتصل بكم بخصوص طلبكم للشراء بالتقسيط.'],
					$record,
					['title' => 'Point sur le dossier', 'type' => 'ask', 'required' => false,
						'fr' => 'Avez-vous pu réunir les documents ? Lesquels vous manquent encore ?',
						'ar' => 'هل تمكنتم من جمع الوثائق؟ ما هي الوثائق التي تنقصكم بعد؟'],
					$docs, $end]],
			],
			'objections' => [
				['label' => 'C’est cher', 'fr' => 'La mensualité est étalée jusqu’à 60 mois, sans apport et sans frais de dossier, et la livraison est gratuite dans toute l’Algérie.',
					'ar' => 'القسط موزع على مدة تصل إلى 60 شهرًا، بدون دفعة أولى وبدون مصاريف ملف، والتوصيل مجاني إلى كامل التراب الوطني.'],
				['label' => 'Je dois réfléchir', 'fr' => 'Bien sûr. Je vous envoie la liste des documents par WhatsApp et je vous rappelle au moment qui vous convient : quel jour vous arrange ?',
					'ar' => 'بالطبع. سأرسل لكم قائمة الوثائق عبر واتساب وأعاود الاتصال بكم في الوقت المناسب لكم: أي يوم يناسبكم؟'],
				['label' => 'Pas de compte CCP ou bancaire', 'fr' => 'Le financement demande un salaire versé sur un compte CCP ou bancaire. Dès que ce sera le cas, nous pourrons étudier votre dossier.',
					'ar' => 'يتطلب التمويل راتبًا يُصبّ في حساب بريدي أو بنكي. بمجرد توفر ذلك، يمكننا دراسة ملفكم.'],
				['label' => 'Paiement comptant', 'fr' => 'Le paiement comptant se fait dans nos showrooms de Dely Brahim et de Blida.',
					'ar' => 'الدفع نقدًا يتم في معرضينا بدالي إبراهيم والبليدة.'],
				['label' => 'Conforme à la charia ?', 'fr' => 'Oui : notre banque partenaire de référence propose un financement conforme aux préceptes de la charia.',
					'ar' => 'نعم: بنكنا الشريك المرجعي يقترح تمويلًا مطابقًا لأحكام الشريعة الإسلامية.'],
			],
		];
	}
}
