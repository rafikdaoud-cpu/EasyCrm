<?php
namespace OCA\EasyCrm\Service;

/** Dates civiles dans le fuseau CRM ; la borne Au inclut toute la journée. */
class PresenceRange {
 public static function resolve(array $filter, \DateTimeZone $zone, int $now): array {
  $today = (new \DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
  $preset = $filter['period'] ?? 'day';
  if (!is_string($preset) || !in_array($preset,['day','week','month','custom'],true)) { throw new \InvalidArgumentException('Période invalide.'); }
  if ($preset === 'custom') {
   $parse = function ($v) use ($zone) {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)) { throw new \InvalidArgumentException('Renseignez les dates Du et Au au format AAAA-MM-JJ.'); }
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d',$v,$zone);
    if (!$d || $d->format('Y-m-d') !== $v) { throw new \InvalidArgumentException('Date invalide.'); }
    return $d;
   };
   $start = $parse($filter['from'] ?? null); $last = $parse($filter['to'] ?? null);
   if ($start > $last) { throw new \InvalidArgumentException('La date Du doit précéder ou être égale à la date Au.'); }
  } else {
   $start = match ($preset) {
    'week' => $today->modify('-'.((int)$today->format('N')-1).' days'),
    'month' => $today->modify('first day of this month'),
    default => $today,
   };
   $last = $today;
  }
  return ['period'=>$preset,'from'=>$start->format('Y-m-d'),'to'=>$last->format('Y-m-d'),
   'start'=>$start->getTimestamp(),'end'=>$last->modify('+1 day')->getTimestamp()];
 }
}
