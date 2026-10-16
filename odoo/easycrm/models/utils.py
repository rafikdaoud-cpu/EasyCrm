# -*- coding: utf-8 -*-
import re
import unicodedata

PRESETS = {
    # cc = indicatif international, trunk = préfixe national, rx = format national valide
    'DZ': {'cc': '213', 'trunk': '0', 'rx': r'^(0[567]\d{8}|0[2349]\d{7,8})$', 'label': 'Algeria'},
    'MA': {'cc': '212', 'trunk': '0', 'rx': r'^0[5-7]\d{8}$', 'label': 'Morocco'},
    'TN': {'cc': '216', 'trunk': '', 'rx': r'^[2-579]\d{7}$', 'label': 'Tunisia'},
    'FR': {'cc': '33', 'trunk': '0', 'rx': r'^0[1-9]\d{8}$', 'label': 'France'},
    'OTHER': {'cc': '', 'trunk': '', 'rx': None, 'label': 'Other country'},
}
COUNTRIES = [(k, v['label']) for k, v in PRESETS.items()]


def get_cfg(env):
    """Paramètres pays / indicatif / préfixe national."""
    icp = env['ir.config_parameter'].sudo()
    country = icp.get_param('easycrm.country') or 'DZ'
    if country not in PRESETS:
        country = 'OTHER'
    preset = PRESETS[country]
    cc = icp.get_param('easycrm.cc')
    trunk = icp.get_param('easycrm.trunk')
    return {
        'country': country,
        'cc': cc if cc else preset['cc'],
        'trunk': trunk if trunk else preset['trunk'],
        'company': icp.get_param('easycrm.company') or '',
    }


def normalize_phone(raw, cfg):
    """Retourne (numéro_national_normalisé, valide)."""
    digits = re.sub(r'\D', '', raw or '')
    if not digits:
        return '', False
    preset = PRESETS.get(cfg['country'], PRESETS['OTHER'])
    cc, trunk, rx = cfg['cc'], cfg['trunk'], preset['rx']
    intl = False
    if digits.startswith('00'):
        digits, intl = digits[2:], True

    def ok(n):
        return bool(re.match(rx, n)) if rx else len(n) >= 6

    if cc and digits.startswith(cc):
        cand = trunk + digits[len(cc):]
        if intl or ok(cand):
            if ok(cand) or not rx:
                digits = cand
    if trunk and not digits.startswith(trunk):
        cand = trunk + digits
        if ok(cand):
            digits = cand
    return digits, ok(digits)


def wa_number(number, cfg):
    """Numéro au format international sans « + » pour wa.me."""
    n = number or ''
    if cfg['trunk'] and n.startswith(cfg['trunk']):
        n = n[len(cfg['trunk']):]
    return (cfg['cc'] + n) if cfg['cc'] else n


def canon(s):
    s = unicodedata.normalize('NFKD', str(s or ''))
    s = ''.join(c for c in s if not unicodedata.combining(c)).lower()
    return re.sub(r'[^a-z0-9؀-ۿ]+', ' ', s).strip()


def stem(s):
    """Forme canonique insensible à la casse, aux accents et au pluriel."""
    return ' '.join(w[:-1] if len(w) > 3 and w[-1] in 'sx' else w for w in canon(s).split())


def match_option(value, options):
    c = stem(value)
    for o in options:
        if stem(o) == c:
            return o
    return None


# (code, nom FR, nom AR)
WILAYAS = [
    ('01', 'Adrar', 'أدرار'), ('02', 'Chlef', 'الشلف'), ('03', 'Laghouat', 'الأغواط'),
    ('04', 'Oum El Bouaghi', 'أم البواقي'), ('05', 'Batna', 'باتنة'), ('06', 'Béjaïa', 'بجاية'),
    ('07', 'Biskra', 'بسكرة'), ('08', 'Béchar', 'بشار'), ('09', 'Blida', 'البليدة'),
    ('10', 'Bouira', 'البويرة'), ('11', 'Tamanrasset', 'تمنراست'), ('12', 'Tébessa', 'تبسة'),
    ('13', 'Tlemcen', 'تلمسان'), ('14', 'Tiaret', 'تيارت'), ('15', 'Tizi Ouzou', 'تيزي وزو'),
    ('16', 'Alger', 'الجزائر'), ('17', 'Djelfa', 'الجلفة'), ('18', 'Jijel', 'جيجل'),
    ('19', 'Sétif', 'سطيف'), ('20', 'Saïda', 'سعيدة'), ('21', 'Skikda', 'سكيكدة'),
    ('22', 'Sidi Bel Abbès', 'سيدي بلعباس'), ('23', 'Annaba', 'عنابة'), ('24', 'Guelma', 'قالمة'),
    ('25', 'Constantine', 'قسنطينة'), ('26', 'Médéa', 'المدية'), ('27', 'Mostaganem', 'مستغانم'),
    ('28', "M'Sila", 'المسيلة'), ('29', 'Mascara', 'معسكر'), ('30', 'Ouargla', 'ورقلة'),
    ('31', 'Oran', 'وهران'), ('32', 'El Bayadh', 'البيض'), ('33', 'Illizi', 'إليزي'),
    ('34', 'Bordj Bou Arréridj', 'برج بوعريريج'), ('35', 'Boumerdès', 'بومرداس'),
    ('36', 'El Tarf', 'الطارف'), ('37', 'Tindouf', 'تندوف'), ('38', 'Tissemsilt', 'تيسمسيلت'),
    ('39', 'El Oued', 'الوادي'), ('40', 'Khenchela', 'خنشلة'), ('41', 'Souk Ahras', 'سوق أهراس'),
    ('42', 'Tipaza', 'تيبازة'), ('43', 'Mila', 'ميلة'), ('44', 'Aïn Defla', 'عين الدفلى'),
    ('45', 'Naâma', 'النعامة'), ('46', 'Aïn Témouchent', 'عين تموشنت'), ('47', 'Ghardaïa', 'غرداية'),
    ('48', 'Relizane', 'غليزان'), ('49', 'Timimoun', 'تيميمون'),
    ('50', 'Bordj Badji Mokhtar', 'برج باجي مختار'), ('51', 'Ouled Djellal', 'أولاد جلال'),
    ('52', 'Béni Abbès', 'بني عباس'), ('53', 'In Salah', 'عين صالح'), ('54', 'In Guezzam', 'عين قزام'),
    ('55', 'Touggourt', 'تقرت'), ('56', 'Djanet', 'جانت'), ('57', "El M'Ghair", 'المغير'),
    ('58', 'El Menia', 'المنيعة'),
]
_WIL_INDEX = {}
for _c, _fr, _ar in WILAYAS:
    _WIL_INDEX[canon(_fr)] = _fr
    _WIL_INDEX[canon(_ar)] = _fr
    _WIL_INDEX[_c] = _fr
    _WIL_INDEX[_c.lstrip('0')] = _fr
    _WIL_INDEX[canon(_c + ' ' + _fr)] = _fr


def normalize_region(value, cfg):
    """Pour l'Algérie : ramène la wilaya à son nom français officiel."""
    value = (value or '').strip()
    if not value or cfg['country'] != 'DZ':
        return value
    c = canon(value)
    if c in _WIL_INDEX:
        return _WIL_INDEX[c]
    m = re.match(r'^(\d{1,2})\b', c)
    if m and m.group(1) in _WIL_INDEX:
        return _WIL_INDEX[m.group(1)]
    return value
