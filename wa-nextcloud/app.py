"""
WhatsApp -> Nextcloud (CsmCloud) + CRM (callcrm)
------------------------------------------------
Reçoit les messages WhatsApp via le webhook de l'API Cloud officielle (Meta),
range les photos / documents / vocaux / vidéos dans Nextcloud :

    <NC_ROOT>/<numero_client>/2026-10-21_144512_image_ab12cd34.jpg
    <NC_ROOT>/<numero_client>/historique.txt

transmet chaque message au CRM (callcrm : boîte WhatsApp partagée des commerciaux),
et envoie les réponses des commerciaux (endpoint local /send, appelé par callcrm).

Hébergement : service systemd (gunicorn 127.0.0.1:8085) derrière Apache (wa.csmtaksit.dz).
"""

import base64
import fcntl
import hashlib
import hmac
import json
import logging
import mimetypes
import os
import re
import sqlite3
import sys
import threading
import time
import unicodedata
from datetime import datetime
from logging.handlers import RotatingFileHandler
from urllib.parse import quote
from zoneinfo import ZoneInfo

import requests
from dotenv import load_dotenv
from flask import Flask, abort, jsonify, request

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(BASE_DIR, ".env"))


def env(name, default=None, required=False):
    val = os.getenv(name, default)
    if required and not val:
        raise RuntimeError(f"Variable {name} manquante dans .env")
    return val


VERIFY_TOKEN = env("WA_VERIFY_TOKEN", required=True)      # jeton choisi par toi (webhook)
APP_SECRET = env("WA_APP_SECRET", required=True)          # « Clé secrète » de l'app Meta
WA_TOKEN = env("WA_ACCESS_TOKEN", required=True)          # jeton permanent (utilisateur système)
GRAPH_VERSION = env("WA_GRAPH_VERSION", "v25.0")
GRAPH = f"https://graph.facebook.com/{GRAPH_VERSION}"
WA_PHONE_NUMBER_ID = env("WA_PHONE_NUMBER_ID", "")        # ID du numéro (envoi des réponses du CRM)
WA_WABA_ID = env("WA_WABA_ID", "")                        # compte WhatsApp Business (modèles) ; sinon appris du webhook

NC_URL = env("NC_URL", required=True).rstrip("/")         # https://cloud.csmtaksit.dz
NC_LOGIN = env("NC_LOGIN", required=True)                 # login AD du compte de service
NC_USER_ID = env("NC_USER_ID") or NC_LOGIN                # identifiant interne Nextcloud (URL WebDAV)
NC_APP_PASSWORD = env("NC_APP_PASSWORD", required=True)   # mot de passe d'application
NC_ROOT = env("NC_ROOT", "WhatsApp").strip("/")

# CRM (callcrm) — vide = pas de transfert vers le CRM
CRM_URL = (env("CRM_URL", "") or "").rstrip("/")          # https://cloud.csmtaksit.dz/index.php/apps/callcrm/api/wa
CRM_SECRET = env("CRM_SECRET", "")                        # secret partagé avec callcrm (occ … wa_secret)

CHATBOT_ENABLED = env("CHATBOT_ENABLED", "0") == "1"
AUTO_REPLY = env("AUTO_REPLY", "1") == "1"                # accusé de réception 1x/jour/client
AUTO_REPLY_TEXT = env(
    "AUTO_REPLY_TEXT",
    "Bonjour, nous avons bien reçu votre document. Merci, notre équipe le traite rapidement.",
)
MAX_MEDIA_MB = int(env("MAX_MEDIA_MB", "100"))

# Photos reçues -> PDF (Stirling-PDF, sinon conversion locale)
IMAGE_TO_PDF = env("IMAGE_TO_PDF", "1") == "1"
KEEP_IMAGE = env("KEEP_IMAGE", "1") == "1"                # 0 = ne garder que le PDF
STIRLING_URL = (env("STIRLING_URL", "") or "").rstrip("/") # ex. http://erp.csmtaksit.dz:8080
STIRLING_USER = env("STIRLING_USER", "")                  # compte AD si Stirling est derrière Nginx + AD
STIRLING_PASSWORD = env("STIRLING_PASSWORD", "")
STIRLING_API_KEY = env("STIRLING_API_KEY", "")            # si la sécurité de Stirling est activée
STIRLING_FIT = env("STIRLING_FIT", "maintainAspectRatio")  # fillPage | fitDocumentToImage | maintainAspectRatio
STIRLING_COLOR = env("STIRLING_COLOR", "color")            # color | greyscale | blackwhite
STIRLING_TIMEOUT = int(env("STIRLING_TIMEOUT", "60"))
TZ = ZoneInfo(env("TZ_NAME", "Africa/Algiers"))

DATA_DIR = os.path.join(BASE_DIR, "data")
FAILED_DIR = os.path.join(DATA_DIR, "failed")
CRM_QUEUE = os.path.join(DATA_DIR, "crm_queue")
LOG_DIR = os.path.join(BASE_DIR, "logs")
for d in (DATA_DIR, FAILED_DIR, CRM_QUEUE, LOG_DIR):
    os.makedirs(d, exist_ok=True)

HIST_PENDING = os.path.join(DATA_DIR, "hist_pending")
os.makedirs(HIST_PENDING, exist_ok=True)

MEDIA_TYPES = ("image", "document", "audio", "video", "sticker")

EXT_OVERRIDES = {
    "image/jpeg": ".jpg",
    "image/png": ".png",
    "image/webp": ".webp",
    "audio/ogg": ".ogg",
    "audio/mpeg": ".mp3",
    "audio/mp4": ".m4a",
    "audio/aac": ".aac",
    "audio/amr": ".amr",
    "video/mp4": ".mp4",
    "video/3gpp": ".3gp",
    "application/pdf": ".pdf",
}

# Types acceptés par WhatsApp pour l'envoi
SEND_IMAGE = {"image/jpeg", "image/png"}
SEND_DOC = {
    "application/pdf", "text/plain",
    "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.ms-excel", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.ms-powerpoint", "application/vnd.openxmlformats-officedocument.presentationml.presentation",
}

# --------------------------------------------------------------------------- #
# Journalisation
# --------------------------------------------------------------------------- #
log = logging.getLogger("wa-nextcloud")
log.setLevel(logging.INFO)
_h = RotatingFileHandler(os.path.join(LOG_DIR, "app.log"), maxBytes=5_000_000, backupCount=5,
                         encoding="utf-8")
_h.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
log.addHandler(_h)

# --------------------------------------------------------------------------- #
# État local (anti-doublon + suivi des accusés de réception)
# --------------------------------------------------------------------------- #
DB_PATH = os.path.join(DATA_DIR, "state.db")
_db_lock = threading.Lock()


def db():
    con = sqlite3.connect(DB_PATH, timeout=30)
    con.execute("CREATE TABLE IF NOT EXISTS processed (msg_id TEXT PRIMARY KEY, ts INTEGER)")
    con.execute("CREATE TABLE IF NOT EXISTS replied (phone TEXT, day TEXT, PRIMARY KEY (phone, day))")
    con.execute("CREATE TABLE IF NOT EXISTS chatbot (phone TEXT PRIMARY KEY, step TEXT, language TEXT)")
    return con


def already_processed(msg_id):
    with _db_lock, db() as con:
        return con.execute("SELECT 1 FROM processed WHERE msg_id=?", (msg_id,)).fetchone() is not None


def mark_processed(msg_id):
    with _db_lock, db() as con:
        con.execute("INSERT OR IGNORE INTO processed VALUES (?, ?)", (msg_id, int(time.time())))


def should_reply_today(phone):
    """Retourne True la première fois de la journée pour ce numéro (et l'enregistre)."""
    day = datetime.now(TZ).strftime("%Y-%m-%d")
    with _db_lock, db() as con:
        cur = con.execute("INSERT OR IGNORE INTO replied VALUES (?, ?)", (phone, day))
        return cur.rowcount == 1


# --------------------------------------------------------------------------- #
# Client Nextcloud (WebDAV)
# --------------------------------------------------------------------------- #
class Nextcloud:
    def __init__(self, url, login, user_id, password):
        self.base = f"{url}/remote.php/dav/files/{quote(user_id)}"
        self.s = requests.Session()
        self.s.auth = (login, password)
        self.s.headers["User-Agent"] = "wa-nextcloud/1.1"
        self._dirs = set()

    def _u(self, path):
        return self.base + "/" + "/".join(quote(p) for p in path.strip("/").split("/"))

    def ensure_dir(self, path):
        if path in self._dirs:
            return
        for attempt in range(8):
            r = self.s.request("MKCOL", self._u(path), timeout=30)
            if r.status_code in (201, 405):  # 405 = existe déjà
                self._dirs.add(path)
                return
            # 423 : dossier verrouillé par Nextcloud (création simultanée) ; 502-504 : maintenance ou redémarrage
            if r.status_code not in (423, 502, 503, 504):
                break
            time.sleep(min(attempt + 1, 5))
        raise RuntimeError(f"MKCOL {path} -> HTTP {r.status_code} {r.text[:200]}")

    def upload(self, path, data, mime="application/octet-stream"):
        """Envoie un fichier sans écraser l'existant (suffixe -1, -2... si besoin)."""
        root, ext = os.path.splitext(path)
        candidate, n, locked = path, 0, 0
        while True:
            r = self.s.put(self._u(candidate), data=data, timeout=300,
                           headers={"Content-Type": mime, "If-None-Match": "*"})
            if r.status_code in (201, 204):
                return candidate
            if r.status_code == 412 and n < 20:  # nom déjà pris
                n += 1
                candidate = f"{root}-{n}{ext}"
                continue
            if r.status_code == 423 and locked < 10:  # fichier verrouillé par Nextcloud : on patiente
                locked += 1
                time.sleep(min(locked, 5))
                continue
            raise RuntimeError(f"PUT {candidate} -> HTTP {r.status_code} {r.text[:200]}")

    def append_text(self, path, line):
        """Ajoute une ligne à un fichier texte (GET + PUT protégé par ETag)."""
        for attempt in range(12):
            r = self.s.get(self._u(path), timeout=30)
            if r.status_code == 404:
                body, headers = "", {"If-None-Match": "*"}
            elif r.status_code == 423:
                if attempt >= 3:
                    raise RuntimeError(f"{path} verrouillé (ouvert dans un éditeur ?)")
                time.sleep(1)
                continue
            elif r.status_code == 200:
                body = r.content.decode("utf-8", errors="replace")
                headers = {"If-Match": r.headers.get("ETag", "*")}
            else:
                raise RuntimeError(f"GET {path} -> HTTP {r.status_code}")
            headers["Content-Type"] = "text/plain; charset=utf-8"
            new = body + line + "\n"
            p = self.s.put(self._u(path), data=new.encode("utf-8"), headers=headers, timeout=60)
            if p.status_code in (201, 204):
                return
            if p.status_code == 423:  # verrouillé (souvent : fichier ouvert dans un éditeur)
                if attempt >= 3:
                    raise RuntimeError(f"{path} verrouillé (ouvert dans un éditeur ?)")
                time.sleep(1)
                continue
            if p.status_code == 412:  # modifié entre-temps : on recommence
                time.sleep(min(0.5 * (attempt + 1), 5))
                continue
            raise RuntimeError(f"PUT {path} -> HTTP {p.status_code}")
        raise RuntimeError(f"Impossible de mettre à jour {path} (conflits répétés)")


nc = Nextcloud(NC_URL, NC_LOGIN, NC_USER_ID, NC_APP_PASSWORD)

# --------------------------------------------------------------------------- #
# API WhatsApp Cloud
# --------------------------------------------------------------------------- #
wa = requests.Session()
wa.headers["Authorization"] = f"Bearer {WA_TOKEN}"


def download_media(media_id):
    meta = wa.get(f"{GRAPH}/{media_id}", timeout=30)
    meta.raise_for_status()
    info = meta.json()
    size = int(info.get("file_size") or 0)
    if size > MAX_MEDIA_MB * 1024 * 1024:
        raise RuntimeError(f"Média {media_id} trop volumineux ({size} octets)")
    r = wa.get(info["url"], timeout=300, headers={"User-Agent": "wa-nextcloud/1.1"})
    r.raise_for_status()
    return r.content, info.get("mime_type", "application/octet-stream")


def wa_send(phone_number_id, payload):
    """Envoie un message ; retourne le wamid ou lève une erreur lisible. payload["to"] : numéro ou BSUID."""
    body = {"messaging_product": "whatsapp", "recipient_type": "individual"}
    body.update(payload)
    if BSUID_RE.match(str(body.get("to", ""))):  # client avec nom d'utilisateur : champ "recipient"
        body["recipient"] = body.pop("to")
    r = wa.post(f"{GRAPH}/{phone_number_id}/messages", timeout=60, json=body)
    j = r.json() if r.content else {}
    if r.status_code >= 300:
        err = (j.get("error") or {})
        raise RuntimeError(err.get("error_user_msg") or err.get("message") or f"HTTP {r.status_code}")
    return (j.get("messages") or [{}])[0].get("id", "")


def wa_upload_media(phone_number_id, data, name, mime):
    r = wa.post(f"{GRAPH}/{phone_number_id}/media", timeout=300,
                files={"file": (name, data, mime)}, data={"messaging_product": "whatsapp", "type": mime})
    j = r.json() if r.content else {}
    if r.status_code >= 300 or not j.get("id"):
        raise RuntimeError(((j.get("error") or {}).get("message")) or f"Envoi du fichier refusé (HTTP {r.status_code})")
    return j["id"]


def _pdf_local(data):
    """Conversion de secours sur le VPS (Pillow) : image centrée sur une page A4, orientation EXIF respectée."""
    import io
    from PIL import Image, ImageOps
    img = ImageOps.exif_transpose(Image.open(io.BytesIO(data)))
    if img.mode not in ("RGB", "L"):
        img = img.convert("RGB")
    w, h = img.size
    pw, ph = (1240, 1754) if h >= w else (1754, 1240)   # A4 à 150 dpi, orienté comme la photo
    scale = min((pw - 80) / w, (ph - 80) / h)
    img = img.resize((max(1, int(w * scale)), max(1, int(h * scale))))
    page = Image.new("RGB", (pw, ph), "white")
    page.paste(img, ((pw - img.size[0]) // 2, (ph - img.size[1]) // 2))
    out = io.BytesIO()
    page.save(out, "PDF", resolution=150.0)
    return out.getvalue()


def image_to_pdf(data, name, mime):
    """Retourne (pdf, moteur). Stirling-PDF d'abord, conversion locale en secours ; (None, erreur) si impossible."""
    if STIRLING_URL:
        try:
            headers = {"X-API-KEY": STIRLING_API_KEY} if STIRLING_API_KEY else {}
            auth = (STIRLING_USER, STIRLING_PASSWORD) if STIRLING_USER else None
            r = requests.post(f"{STIRLING_URL}/api/v1/convert/img/pdf", timeout=STIRLING_TIMEOUT, headers=headers, auth=auth,
                              files={"fileInput": (name, data, mime)},
                              data={"fitOption": STIRLING_FIT, "colorType": STIRLING_COLOR, "autoRotate": "true"})
            if r.status_code == 200 and r.content[:5] == b"%PDF-":
                return r.content, "Stirling-PDF"
            log.warning("Stirling-PDF a refusé la conversion (HTTP %s) : %s", r.status_code, r.text[:200])
        except Exception as e:  # noqa: BLE001
            log.warning("Stirling-PDF injoignable : %s", e)
    try:
        return _pdf_local(data), "local"
    except Exception as e:  # noqa: BLE001
        log.warning("Conversion PDF locale impossible : %s", e)
        return None, str(e)


def send_text(phone_number_id, to, text):
    try:
        return wa_send(phone_number_id, {"to": to, "type": "text", "text": {"body": text}})
    except Exception as e:  # noqa: BLE001
        log.warning("Accusé de réception non envoyé à %s : %s", to, e)
        return ""


# --------------------------------------------------------------------------- #
# Transfert vers le CRM (callcrm)
# --------------------------------------------------------------------------- #
def crm_post(path, data, queue_on_fail=True, response=False):
    if not CRM_URL or not CRM_SECRET:
        return None if response else True
    try:
        r = requests.post(CRM_URL + path, json=data, timeout=20,
                          headers={"X-CRM-Secret": CRM_SECRET, "OCS-APIRequest": "true", "User-Agent": "wa-nextcloud/1.1"})
        if r.status_code < 300:
            return r.json() if response else True
        log.warning("CRM %s -> HTTP %s %s", path, r.status_code, r.text[:200])
    except Exception as e:  # noqa: BLE001
        log.warning("CRM %s injoignable : %s", path, e)
    if queue_on_fail:
        fn = os.path.join(CRM_QUEUE, f"{time.time():.6f}_{path.strip('/')}.json")
        with open(fn, "w", encoding="utf-8") as f:
            json.dump({"path": path, "data": data}, f, ensure_ascii=False)
    return False


def flush_crm_queue():
    files = sorted(os.listdir(CRM_QUEUE))
    ok = 0
    for fn in files:
        p = os.path.join(CRM_QUEUE, fn)
        try:
            with open(p, encoding="utf-8") as f:
                item = json.load(f)
        except Exception:  # noqa: BLE001
            os.remove(p)
            continue
        if crm_post(item["path"], item["data"], queue_on_fail=False):
            os.remove(p)
            ok += 1
        else:
            break  # CRM toujours indisponible : on garde l'ordre
    return ok, len(files)


# --------------------------------------------------------------------------- #
# Traitement des messages
# --------------------------------------------------------------------------- #
def safe_name(name, maxlen=80):
    name = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    name = re.sub(r"[^A-Za-z0-9._-]+", "_", name).strip("._")
    return name[:maxlen] or "fichier"


def extension_for(mime, original_name=None):
    if original_name and os.path.splitext(original_name)[1]:
        return ""  # le nom d'origine garde déjà son extension
    mime = (mime or "").split(";")[0].strip().lower()
    return EXT_OVERRIDES.get(mime) or mimetypes.guess_extension(mime) or ".bin"


BSUID_RE = re.compile(r"^[A-Z]{2}\.[A-Za-z0-9.]{1,140}$")


def client_ids(msg, value, direction="in"):
    """(numéro en chiffres ou '', BSUID ou '') du client. Avec un nom d'utilisateur WhatsApp, Meta peut omettre le numéro."""
    if direction == "out":
        num = msg.get("to") or msg.get("recipient_id") or ""
        uid = msg.get("to_user_id") or msg.get("recipient_user_id") or ""
    else:
        num = msg.get("from") or ""
        uid = msg.get("from_user_id") or ""
        if not num or not uid:
            for c in value.get("contacts", []):
                if uid and c.get("user_id") == uid or num and c.get("wa_id") == num or len(value.get("contacts", [])) == 1:
                    num = num or c.get("wa_id") or ""
                    uid = uid or c.get("user_id") or ""
                    break
    num = re.sub(r"\D", "", str(num))
    uid = str(uid).strip() if BSUID_RE.match(str(uid).strip()) else ""
    return num, uid


def folder_key(num, uid):
    return num or re.sub(r"[^A-Za-z0-9.]", "", uid) or "inconnu"


def bot_state(phone):
    with _db_lock, db() as con:
        return con.execute("SELECT step, language FROM chatbot WHERE phone=?", (phone,)).fetchone()


def set_bot_state(phone, step, language=""):
    with _db_lock, db() as con:
        con.execute("INSERT INTO chatbot VALUES (?, ?, ?) ON CONFLICT(phone) DO UPDATE "
                    "SET step=excluded.step, language=excluded.language", (phone, step, language))


def bot_key(value):
    folded = unicodedata.normalize("NFKD", value.casefold())
    return " ".join(re.findall(r"[^\W_]+", "".join(c for c in folded if not unicodedata.combining(c))))


def bot_answer(config, body, lang, expect_model=False):
    question = bot_key(body)
    if not question:
        return None
    faq = [row for row in config.get("faq", [])
           if question in (bot_key(row["question_fr"]), bot_key(row["question_ar"]))]
    if len(faq) == 1:
        return faq[0]["answer_" + lang]
    if faq:
        return None
    words = set(question.split())
    if not expect_model and not words.intersection({"prix", "tarif", "combien", "سعر", "السعر", "ثمن", "الثمن", "بكم", "شحال"}):
        return None
    matches = []
    for row in config.get("catalog", []):
        terms = [row["model"], *row["aliases"].split("|")]
        keys = [key for term in terms if (key := bot_key(term))]
        if any(key == question or f" {key} " in f" {question} " for key in keys):
            matches.append(row)
    if len(matches) != 1 or not matches[0]["price"]:
        return None
    row = matches[0]
    availability = " · " + row["availability"] if row["availability"] else ""
    if lang == "ar":
        return f'{row["model"]} · السعر {row["price"]} دج · آخر تحديث {row["updated"]}{availability}'
    return f'{row["model"]} · Prix {row["price"]} DA · Mis à jour le {row["updated"]}{availability}'


def bot_duration_question(body):
    words = set(bot_key(body).split())
    duration = {"duree", "durees", "مده", "مدة", "المدة", "المده"}
    financing = {"pret", "prets", "credit", "financement", "taksit", "taqsit", "تقسيط", "التقسيط", "قرض", "القرض"}
    query = {"combien", "كم", "شحال", "قداش"}
    units = {"mois", "annees", "شهر", "اشهر", "شهور", "سنة", "سنوات"}
    return bool((words & duration and words & financing) or (words & query and words & units))


def bot_duration_reply(lang):
    return ("مدة الدفع بالتقسيط تتراوح من 12 إلى 60 شهرًا." if lang == "ar"
            else "La durée du paiement en plusieurs fois va de 12 à 60 mois.")


def bot_service_reply(body, lang):
    question = bot_key(body)
    words = set(question.split())
    arabic = lang == "ar"
    answers = []
    if bot_duration_question(body):
        answers.append(bot_duration_reply(lang))
    if words & {"livraison", "livrez", "livrer", "توصيل", "التوصيل", "شحن", "الشحن"}:
        answers.append("التوصيل مجاني إلى جميع ولايات الجزائر." if arabic else
                       "La livraison est gratuite dans toutes les wilayas d’Algérie.")
    if words & {"dossier", "documents", "justificatifs", "pieces", "ملف", "الملف", "وثائق", "الوثائق", "الاوراق", "اوراق"}:
        label = "الوثائق المطلوبة لتكوين الملف" if arabic else "Liste des pièces à fournir"
        answers.append(f"{label} : https://eshop.csmtaksit.dz/dossier-a-fournir/")
    if words & {"faq"} or any(term in question for term in ("questions frequentes", "الاسئلة الشائعة", "اسئلة شائعة")):
        label = "الأسئلة الشائعة" if arabic else "Questions fréquentes"
        answers.append(f"{label} : https://eshop.csmtaksit.dz/faq/")
    return "\n\n".join(answers)


def bot_step(state, choice, body, config=None):
    config = config or {}
    texts = config.get("texts", {})
    if state is None or state[0] == "language":
        lang = {"fr": "fr", "français": "fr", "ar": "ar", "العربية": "ar", "عربي": "ar"}.get(choice or body)
        if not lang:
            detected = "ar" if re.search(r"[\u0600-\u06ff]", body) else "fr"
            answer = bot_service_reply(body, detected)
            if answer:
                step, lang, prompt, choices = bot_step(("language", ""), detected, "", config)
                text = answer + "\n\n" + prompt
                return step, lang, text if len(text) <= 1024 else answer, choices
        if not lang:
            return ("language", "", texts.get("welcome", "Bonjour • مرحباً بكم في CSM Taksit. Choisissez votre langue / اختاروا اللغة."),
                    [("fr", "Français"), ("ar", "العربية")])
        t = texts.get(lang, {})
        defaults = ("ما المنتج الذي تبحثون عنه؟", "دراجة نارية", "أجهزة منزلية", "أثاث وتجهيز المنزل", "التحدث إلى مستشار") if lang == "ar" else (
            "Quel produit recherchez-vous ?", "Moto", "Électroménager", "Meubles / Aménagement", "Un commercial")
        return ("product", lang, t.get("product", defaults[0]),
                [(key, t.get(key, default)) for key, default in zip(("moto", "appliance", "furniture", "agent"), defaults[1:])])
    step, lang = state
    if step == "done":
        return None
    t = texts.get(lang, {})
    arabic = lang == "ar"
    handoff = ("done", lang, t.get("handoff", "سيتابع أحد مستشارينا طلبكم هنا. شكراً لكم." if arabic else
               "Un commercial reprendra votre demande ici. Merci !"), [])
    if choice == "agent" or body in {"commercial", "parler à un commercial", "مستشار", "التحدث إلى مستشار"}:
        return handoff
    answer = bot_service_reply(body, lang)
    if answer:
        return step, lang, answer, []
    answer = bot_answer(config, body, lang, expect_model=step=="price")
    if answer:
        return ("product" if step == "price" else step, lang, answer, [])
    price_words = {"prix", "tarif", "combien", "سعر", "السعر", "ثمن", "الثمن", "بكم", "شحال"}
    tokens = set(bot_key(body).split())
    if config.get("catalog") and tokens & price_words:
        return ("price", lang, t.get("model", "ما هو الموديل المطلوب؟" if arabic else "Quel modèle recherchez-vous ?"), [])
    if step == "product":
        if bot_key(choice or body) in {"moto", "appliance", "electromenager", "furniture", "meuble", "meubles",
                                      "amenagement", "meuble amenagement", "meubles amenagement", "دراجة نارية", "اجهزة منزلية", "اثاث",
                                      "اثاث وتجهيز المنزل"} | {
                bot_key(t[key]) for key in ("moto", "appliance", "furniture") if t.get(key)
        }:
            return ("model", lang, t.get("model", "ما هو الموديل المطلوب؟" if arabic else "Quel modèle recherchez-vous ?"), [])
        return handoff
    if step == "model" and body and not (tokens & price_words or "?" in body or "؟" in body):
        return ("wilaya", lang, t.get("wilaya", "في أي ولاية تقيمون؟" if arabic else "Dans quelle wilaya êtes-vous ?"), [])
    return handoff


def reply_with_bot(phone, num, uid, value, event, choice, crm):
    if not isinstance(crm, dict) or not crm.get("created"):
        return
    state = bot_state(phone)
    if crm.get("routed") or (state is None and crm.get("recent_reply")):
        set_bot_state(phone, "done")
        return
    if event["type"] not in ("text", "interactive") or not (num or uid):
        return
    if not 0 <= time.time() - event["ts"] < 86400:
        return
    reply = bot_step(state, choice, event["text"].strip().casefold(), crm.get("chatbot"))
    if reply is None:
        return
    step, lang, text, buttons = reply
    payload = {"to": num or uid, "type": "text", "text": {"body": text}}
    if len(buttons) > 3:
        payload = {"to": num or uid, "type": "interactive", "interactive": {
            "type": "list", "body": {"text": text}, "action": {
                "button": "عرض الخيارات" if lang == "ar" else "Voir les choix",
                "sections": [{"rows": [{"id": "crm_" + key, "title": title} for key, title in buttons]}]}}}
    elif buttons:
        payload = {"to": num or uid, "type": "interactive", "interactive": {
            "type": "button", "body": {"text": text}, "action": {
                "buttons": [{"type": "reply", "reply": {"id": "crm_" + key, "title": title}}
                            for key, title in buttons]}}}
    try:
        pnid = value.get("metadata", {}).get("phone_number_id") or WA_PHONE_NUMBER_ID
        wamid = wa_send(pnid, payload)
    except Exception:  # noqa: BLE001
        log.warning("Réponse chatbot non envoyée")
        return
    if not wamid:
        return
    set_bot_state(phone, step, lang)
    readable = text + (" · " + " / ".join(title for _, title in buttons) if buttons else "")
    crm_post("/inbound", {"wamid": wamid, "wa_id": num, "user_id": uid, "type": "text", "text": readable,
                          "direction": "out", "user": "auto", "ts": int(time.time())})
    hist_append(f"{NC_ROOT}/{phone}/historique.txt", f"[{datetime.now(TZ):%Y-%m-%d %H:%M:%S}] chatbot | {readable}")


def handle_message(msg, value, direction="in"):
    """direction 'in' : message client ; 'out' : écho d'un message envoyé depuis l'appli WhatsApp Business."""
    msg_id = msg["id"]
    if already_processed(msg_id):
        return

    num, uid = client_ids(msg, value, direction)
    phone = folder_key(num, uid)
    contact = ""
    if direction == "in":
        for c in value.get("contacts", []):
            if (num and c.get("wa_id") == num) or (uid and c.get("user_id") == uid) or len(value.get("contacts", [])) == 1:
                contact = c.get("profile", {}).get("name", "") or c.get("profile", {}).get("username", "")
                break
    ts = datetime.fromtimestamp(int(msg.get("timestamp", time.time())), TZ)
    mtype = msg.get("type", "inconnu")
    folder = f"{NC_ROOT}/{phone}"

    nc.ensure_dir(NC_ROOT)
    nc.ensure_dir(folder)

    stamp = ts.strftime("%Y-%m-%d %H:%M:%S")
    who = (f"{phone}" + (f" ({contact})" if contact else "")) if direction == "in" else f"→ {phone} (appli WhatsApp)"
    event = {"wamid": msg_id, "wa_id": num, "user_id": uid, "name": contact, "type": mtype, "ts": int(ts.timestamp()),
             "direction": direction, "user": "app" if direction == "out" else "", "text": ""}

    choice = ""
    if mtype in MEDIA_TYPES:
        media = msg[mtype]
        original = media.get("filename")
        caption = media.get("caption", "")
        data, mime = download_media(media["id"])
        base = f"{ts:%Y-%m-%d_%H%M%S}_{'envoye_' if direction == 'out' else ''}{mtype}_{msg_id[-8:]}"
        if original:
            base += "_" + safe_name(original)
        fname = base + extension_for(mime, original)
        pdf, engine = (None, "")
        if IMAGE_TO_PDF and direction == "in" and mtype == "image":
            pdf, engine = image_to_pdf(data, fname, mime)
        if pdf is not None and not KEEP_IMAGE:
            saved = None
        else:
            saved = nc.upload(f"{folder}/{fname}", data, mime)
            log.info("Fichier enregistré : %s (%d octets)", saved, len(data))
        pdf_saved = nc.upload(f"{folder}/{base}.pdf", pdf, "application/pdf") if pdf is not None else None
        if pdf_saved:
            log.info("PDF créé (%s) : %s", engine, pdf_saved)
        files = " + ".join(os.path.basename(x) for x in (saved, pdf_saved) if x)
        line = f"[{stamp}] {who} | {mtype} | {files}"
        if caption:
            line += f" | légende : {caption}"
        if saved:
            event.update(text=caption, file_path=saved, file_name=original or os.path.basename(saved), mime=mime)
        else:
            event.update(type="document", text=caption, file_path=pdf_saved, file_name=os.path.basename(pdf_saved), mime="application/pdf")

    elif mtype == "text":
        body = msg["text"].get("body", "")
        line = f"[{stamp}] {who} | texte | {body}"
        event["text"] = body
    elif mtype == "location":
        loc = msg["location"]
        txt = f"{loc.get('latitude')},{loc.get('longitude')} {loc.get('name', '')}".strip()
        line = f"[{stamp}] {who} | position | {txt}"
        event["text"] = f"https://maps.google.com/?q={loc.get('latitude')},{loc.get('longitude')} {loc.get('name', '')}".strip()
    elif mtype in ("button", "interactive"):
        interactive = msg.get("interactive") or {}
        button = interactive.get("button_reply") or interactive.get("list_reply") or {}
        choice = str(button.get("id", ""))[4:] if str(button.get("id", "")).startswith("crm_") else ""
        body = button.get("title") or (msg.get("button") or {}).get("text") or json.dumps(msg.get("interactive", {}), ensure_ascii=False)[:500]
        line = f"[{stamp}] {who} | {mtype} | {body}"
        event["text"] = body
    else:
        line = f"[{stamp}] {who} | {mtype} | (type non archivé)"

    hist_append(f"{folder}/historique.txt", line)
    if CHATBOT_ENABLED and direction == "in" and mtype in ("text", "interactive"):
        event["chatbot"] = True
    crm = crm_post("/inbound", event, response=CHATBOT_ENABLED)
    mark_processed(msg_id)
    if CHATBOT_ENABLED and (num or uid):
        if direction == "in":
            reply_with_bot(phone, num, uid, value, event, choice, crm)
        else:
            set_bot_state(phone, "done")

    if direction == "in" and mtype in MEDIA_TYPES and AUTO_REPLY and should_reply_today(phone):
        pnid = value.get("metadata", {}).get("phone_number_id") or WA_PHONE_NUMBER_ID
        wamid = send_text(pnid, num or uid, AUTO_REPLY_TEXT)
        if wamid:
            crm_post("/inbound", {"wamid": wamid, "wa_id": num, "user_id": uid, "type": "text", "text": AUTO_REPLY_TEXT,
                                  "direction": "out", "user": "auto", "ts": int(time.time())})


def hist_append(path, line):
    """Ajoute une ligne à historique.txt ; si le fichier est verrouillé, la ligne est mise de côté et réécrite plus tard."""
    pf = os.path.join(HIST_PENDING, re.sub(r"[^A-Za-z0-9]+", "_", path) + ".txt")
    pending = ""
    if os.path.exists(pf):
        with open(pf, encoding="utf-8") as f:
            pending = f.read()
    try:
        nc.append_text(path, pending + line)
        if pending:
            os.remove(pf)
    except Exception as e:  # noqa: BLE001
        log.warning("Historique %s non mis à jour (%s) : ligne mise en attente", path, e)
        with open(pf, "a", encoding="utf-8") as f:
            f.write(line + "\n")


_phone_locks = {}
_phone_guard = threading.Lock()


def phone_lock(key):
    with _phone_guard:
        return _phone_locks.setdefault(str(key or "inconnu"), threading.Lock())


def handle_locked(msg, value, direction="in"):
    num, uid = client_ids(msg, value, direction)
    with phone_lock(folder_key(num, uid)):
        handle_message(msg, value, direction)


def save_failed(msg, value, err, direction="in"):
    path = os.path.join(FAILED_DIR, f"{int(time.time())}_{msg.get('id', 'x')[-12:]}.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"error": str(err), "msg": msg, "value": value, "direction": direction}, f, ensure_ascii=False, indent=2)


# --------------------------------------------------------------------------- #
# Modèles de messages approuvés (premier message / relance hors fenêtre de 24 h)
# --------------------------------------------------------------------------- #
WABA_FILE = os.path.join(DATA_DIR, "waba_id")
_tpl_cache = {"at": 0.0, "data": None}


def waba_id():
    if WA_WABA_ID:
        return WA_WABA_ID
    try:
        with open(WABA_FILE, encoding="utf-8") as f:
            return f.read().strip()
    except OSError:
        return ""


def remember_waba(payload):
    """L'identifiant du compte WhatsApp Business est l'« entry.id » des webhooks Meta."""
    for entry in payload.get("entry", []):
        wid = str(entry.get("id", ""))
        if re.fullmatch(r"\d{5,30}", wid) and wid != waba_id():
            with open(WABA_FILE, "w", encoding="utf-8") as f:
                f.write(wid)
            log.info("Compte WhatsApp Business mémorisé pour les modèles")
        return


def template_params(text):
    """Variables d'un texte de modèle : {{1}}, {{2}}… ou {{nom}}."""
    seen = []
    for v in re.findall(r"\{\{\s*([A-Za-z0-9_]+)\s*\}\}", text or ""):
        if v not in seen:
            seen.append(v)
    return seen


def list_templates():
    if _tpl_cache["data"] is not None and time.time() - _tpl_cache["at"] < 600:
        return _tpl_cache["data"]
    wid = waba_id()
    if not wid:
        raise RuntimeError("Compte WhatsApp Business inconnu : renseignez WA_WABA_ID dans .env ou attendez le prochain message reçu.")
    out, url, params = [], f"{GRAPH}/{wid}/message_templates", {"limit": 100, "fields": "name,status,language,category,components,parameter_format"}
    while url:
        r = wa.get(url, params=params, timeout=30)
        j = r.json() if r.content else {}
        if r.status_code >= 300:
            raise RuntimeError((j.get("error") or {}).get("message") or f"HTTP {r.status_code}")
        for t in j.get("data", []):
            if t.get("status") != "APPROVED":
                continue
            comps = {c.get("type"): c for c in t.get("components", [])}
            header, body = comps.get("HEADER") or {}, comps.get("BODY") or {}
            buttons = (comps.get("BUTTONS") or {}).get("buttons", [])
            # Pris en charge : en-tête texte (ou aucun), corps à variables, boutons fixes ou bouton lien à une variable
            unsupported = ""
            button_params = []
            for i, b in enumerate(buttons):
                ps = template_params(b.get("url", ""))
                if not ps:
                    continue
                if b.get("type") != "URL" or len(ps) != 1:
                    unsupported = "bouton à variable"
                button_params.append({"index": i, "param": ps[0], "text": b.get("text", ""), "url": b.get("url", "")})
            if header and header.get("format") not in (None, "TEXT"):
                unsupported = "en-tête " + str(header.get("format")).lower()
            out.append({"name": t["name"], "language": t.get("language", ""), "category": t.get("category", ""),
                        "named": t.get("parameter_format") == "NAMED",
                        "header": header.get("text", "") if header.get("format") == "TEXT" else "",
                        "header_params": template_params(header.get("text", "")) if header.get("format") == "TEXT" else [],
                        "body": body.get("text", ""), "body_params": template_params(body.get("text", "")),
                        "footer": (comps.get("FOOTER") or {}).get("text", ""),
                        "buttons": [b.get("text", "") for b in buttons], "button_params": button_params, "unsupported": unsupported})
        url, params = (j.get("paging") or {}).get("next"), None
    out.sort(key=lambda t: (t["name"], t["language"]))
    _tpl_cache.update(at=time.time(), data=out)
    return out


def template_payload(to, tpl, values):
    """Construit le message « template » ; renvoie (payload, texte lisible)."""
    found = next((t for t in list_templates() if t["name"] == tpl.get("name") and t["language"] == tpl.get("language")), None)
    if not found:
        raise ValueError("Modèle introuvable ou non approuvé")
    if found["unsupported"]:
        raise ValueError(f"Modèle non pris en charge par le CRM ({found['unsupported']})")
    comps, text = [], found["body"]

    def params(names, part):
        ps = []
        for n in names:
            v = str(values.get(part, {}).get(n, "")).strip()
            if not v:
                raise ValueError(f"Variable {{{{{n}}}}} du modèle à remplir")
            if len(v) > 1000 or "\n" in v or "\t" in v or "    " in v:
                raise ValueError(f"Variable {{{{{n}}}}} : une seule ligne, 1000 caractères maximum")
            p = {"type": "text", "text": v}
            if found["named"]:
                p["parameter_name"] = n
            ps.append(p)
        return ps
    if found["header_params"]:
        comps.append({"type": "header", "parameters": params(found["header_params"], "header")})
    if found["body_params"]:
        comps.append({"type": "body", "parameters": params(found["body_params"], "body")})
    for b in found.get("button_params", []):
        v = str((values.get("button") or {}).get(b["param"], "")).strip()
        if not v or len(v) > 200 or not re.fullmatch(r"[A-Za-z0-9_\-./?=&%]+", v):
            raise ValueError(f"Lien du bouton « {b['text']} » à remplir (caractères de lien uniquement)")
        comps.append({"type": "button", "sub_type": "url", "index": str(b["index"]), "parameters": [{"type": "text", "text": v}]})
    for n in found["body_params"]:
        text = re.sub(r"\{\{\s*" + re.escape(n) + r"\s*\}\}", lambda _m: values["body"][n].strip(), text)
    head = found["header"]
    for n in found["header_params"]:
        head = re.sub(r"\{\{\s*" + re.escape(n) + r"\s*\}\}", lambda _m: values["header"][n].strip(), head)
    readable = "\n".join(x for x in (head and "*" + head + "*", text, found["footer"], *("[" + b + "]" for b in found["buttons"])) if x)
    payload = {"to": to, "type": "template", "template": {"name": found["name"], "language": {"code": found["language"]}, **({"components": comps} if comps else {})}}
    return payload, readable


def process_payload(payload):
    try:
        remember_waba(payload)
    except Exception as e:  # noqa: BLE001
        log.warning("Compte WhatsApp Business non mémorisé : %s", e)
    for entry in payload.get("entry", []):
        for change in entry.get("changes", []):
            field = change.get("field")
            value = change.get("value", {})
            if field == "messages":
                for msg in value.get("messages", []):
                    try:
                        handle_locked(msg, value)
                    except Exception as e:  # noqa: BLE001
                        log.exception("Échec traitement message %s", msg.get("id"))
                        save_failed(msg, value, e)
                for st in value.get("statuses", []):  # accusés : envoyé / remis / lu / échec
                    errs = st.get("errors") or [{}]
                    crm_post("/status", {"wamid": st.get("id", ""), "status": st.get("status", ""),
                                         "error": errs[0].get("title") or errs[0].get("message") or ""}, queue_on_fail=False)
            elif field == "smb_message_echoes":  # messages envoyés depuis l'appli (coexistence)
                for msg in value.get("message_echoes", []):
                    try:
                        handle_locked(msg, value, direction="out")
                    except Exception as e:  # noqa: BLE001
                        log.exception("Échec traitement écho %s", msg.get("id"))
                        save_failed(msg, value, e, direction="out")


def retry_failed():
    """python app.py retry -> rejoue les messages en échec (médias dispo ~30 jours chez Meta) et la file du CRM."""
    files = sorted(os.listdir(FAILED_DIR))
    ok = 0
    for fn in files:
        path = os.path.join(FAILED_DIR, fn)
        with open(path, encoding="utf-8") as f:
            item = json.load(f)
        try:
            with serial_lock():
                handle_locked(item["msg"], item["value"], item.get("direction", "in"))
            os.remove(path)
            ok += 1
        except Exception as e:  # noqa: BLE001
            print(f"Toujours en échec : {fn} -> {e}")
    print(f"{ok}/{len(files)} message(s) rejoué(s).")
    c_ok, c_tot = flush_crm_queue()
    print(f"{c_ok}/{c_tot} événement(s) transmis au CRM.")


# --------------------------------------------------------------------------- #
# Application web (webhook + envoi local)
# --------------------------------------------------------------------------- #
app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 80 * 1024 * 1024


_serial_lock = threading.Lock()


SERIAL_LOCK_FILE = os.path.join(DATA_DIR, ".serial.lock")


class serial_lock:
    """Un message à la fois pour TOUS les processus (workers gunicorn et relance cron), pas seulement les threads."""
    def __enter__(self):
        _serial_lock.acquire()
        self.f = open(SERIAL_LOCK_FILE, "a")
        fcntl.flock(self.f, fcntl.LOCK_EX)
        return self

    def __exit__(self, *exc):
        try:
            fcntl.flock(self.f, fcntl.LOCK_UN)
            self.f.close()
        finally:
            _serial_lock.release()


def _serial(payload):
    with serial_lock():
        process_payload(payload)


@app.get("/")
def health():
    return "ok", 200


@app.get("/confidentialite")
def privacy():
    with open(os.path.join(BASE_DIR, "privacy.html"), encoding="utf-8") as f:
        return f.read(), 200, {"Content-Type": "text/html; charset=utf-8"}


@app.get("/webhook")
def verify():
    if (request.args.get("hub.mode") == "subscribe"
            and hmac.compare_digest(request.args.get("hub.verify_token", ""), VERIFY_TOKEN)):
        return request.args.get("hub.challenge", ""), 200
    abort(403)


@app.post("/webhook")
def receive():
    raw = request.get_data()
    sig = request.headers.get("X-Hub-Signature-256", "")
    expected = "sha256=" + hmac.new(APP_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(sig, expected):
        log.warning("Signature invalide depuis %s", request.remote_addr)
        abort(403)
    payload = json.loads(raw or b"{}")
    # Réponse immédiate à Meta, traitement en arrière-plan
    threading.Thread(target=_serial, args=(payload,), daemon=True).start()
    return "OK", 200


@app.get("/templates")
def templates():
    """Modèles approuvés, pour callcrm uniquement (127.0.0.1 + secret)."""
    if request.headers.get("X-Forwarded-For") or request.headers.get("X-Forwarded-Host"):
        abort(404)
    if not CRM_SECRET or not hmac.compare_digest(request.headers.get("X-CRM-Secret", ""), CRM_SECRET):
        abort(403)
    try:
        return jsonify(templates=list_templates())
    except Exception as e:  # noqa: BLE001
        return jsonify(error=str(e)), 502


@app.post("/send")
def send():
    """Appelé uniquement par callcrm sur 127.0.0.1 (refusé s'il arrive par Apache)."""
    if request.headers.get("X-Forwarded-For") or request.headers.get("X-Forwarded-Host"):
        abort(404)
    if not CRM_SECRET or not hmac.compare_digest(request.headers.get("X-CRM-Secret", ""), CRM_SECRET):
        abort(403)
    if not WA_PHONE_NUMBER_ID:
        return jsonify(error="WA_PHONE_NUMBER_ID manquant dans .env"), 500
    j = request.get_json(silent=True) or {}
    raw_to = str(j.get("to", "")).strip()
    to = raw_to if BSUID_RE.match(raw_to) else re.sub(r"\D", "", raw_to)
    fkey = folder_key("" if BSUID_RE.match(to) else to, to if BSUID_RE.match(to) else "")
    text = str(j.get("text", "")).strip()
    f = j.get("file") or None
    tpl = j.get("template") or None
    who = str(j.get("user_name") or j.get("user") or "CRM")
    if tpl:
        if not to or not isinstance(tpl, dict):
            return jsonify(error="Destinataire ou modèle manquant"), 400
        try:
            payload, readable = template_payload(to, tpl, j.get("values") or {})
        except ValueError as e:
            return jsonify(error=str(e)), 400
        except Exception as e:  # noqa: BLE001
            return jsonify(error=str(e)), 502
        if CHATBOT_ENABLED:
            with phone_lock(fkey):
                set_bot_state(fkey, "done")
        try:
            wamid = wa_send(WA_PHONE_NUMBER_ID, payload)
        except Exception as e:  # noqa: BLE001
            log.warning("Modèle vers %s refusé : %s", to, e)
            return jsonify(error=str(e)), 502
        try:
            ts = datetime.now(TZ)
            nc.ensure_dir(NC_ROOT)
            nc.ensure_dir(f"{NC_ROOT}/{fkey}")
            with phone_lock(fkey):
                hist_append(f"{NC_ROOT}/{fkey}/historique.txt", f"[{ts:%Y-%m-%d %H:%M:%S}] → {to} | envoyé par {who} | modèle {tpl.get('name')} | {readable}")
        except Exception as e:  # noqa: BLE001
            log.warning("Historique non mis à jour pour %s : %s", to, e)
        log.info("Modèle %s envoyé à %s par %s (%s)", tpl.get("name"), to, who, wamid)
        return jsonify(wamid=wamid, text=readable)
    if not to or (not text and not f):
        return jsonify(error="Destinataire ou contenu manquant"), 400
    if CHATBOT_ENABLED:
        with phone_lock(fkey):
            set_bot_state(fkey, "done")
    saved = ""
    try:
        if f:
            name = os.path.basename(str(f.get("name") or "document"))
            mime = (str(f.get("mime") or "") or mimetypes.guess_type(name)[0] or "application/octet-stream").split(";")[0].lower()
            if mime == "image/jpg":
                mime = "image/jpeg"
            if mime not in SEND_IMAGE | SEND_DOC:
                return jsonify(error=f"Type de fichier non accepté par WhatsApp ({mime}) : utilisez JPG, PNG, PDF, Word, Excel ou PowerPoint."), 400
            data = base64.b64decode(f.get("b64") or "")
            media_id = wa_upload_media(WA_PHONE_NUMBER_ID, data, name, mime)
            if mime in SEND_IMAGE:
                payload = {"to": to, "type": "image", "image": {"id": media_id, **({"caption": text} if text else {})}}
            else:
                payload = {"to": to, "type": "document", "document": {"id": media_id, "filename": name, **({"caption": text} if text else {})}}
            wamid = wa_send(WA_PHONE_NUMBER_ID, payload)
            # copie dans le dossier du client
            ts = datetime.now(TZ)
            folder = f"{NC_ROOT}/{fkey}"
            nc.ensure_dir(NC_ROOT)
            nc.ensure_dir(folder)
            saved = nc.upload(f"{folder}/{ts:%Y-%m-%d_%H%M%S}_envoye_{safe_name(name)}", data, mime)
        else:
            wamid = wa_send(WA_PHONE_NUMBER_ID, {"to": to, "type": "text", "text": {"body": text, "preview_url": True}})
    except Exception as e:  # noqa: BLE001
        log.warning("Envoi CRM vers %s refusé : %s", to, e)
        return jsonify(error=str(e)), 502
    try:
        ts = datetime.now(TZ)
        nc.ensure_dir(NC_ROOT)
        nc.ensure_dir(f"{NC_ROOT}/{fkey}")
        line = f"[{ts:%Y-%m-%d %H:%M:%S}] → {to} | envoyé par {who} | " + (f"fichier | {os.path.basename(saved)}" if saved else "texte")
        if text:
            line += f" | {text}"
        with phone_lock(fkey):
            hist_append(f"{NC_ROOT}/{fkey}/historique.txt", line)
    except Exception as e:  # noqa: BLE001
        log.warning("Historique non mis à jour pour %s : %s", to, e)
    log.info("Message envoyé à %s par %s (%s)", to, who, wamid)
    return jsonify(wamid=wamid, file_path=saved)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "retry":
        retry_failed()
    else:
        app.run(host="127.0.0.1", port=8085, debug=False)
