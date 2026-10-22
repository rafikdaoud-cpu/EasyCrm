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

NC_URL = env("NC_URL", required=True).rstrip("/")         # https://cloud.csmtaksit.dz
NC_LOGIN = env("NC_LOGIN", required=True)                 # login AD du compte de service
NC_USER_ID = env("NC_USER_ID") or NC_LOGIN                # identifiant interne Nextcloud (URL WebDAV)
NC_APP_PASSWORD = env("NC_APP_PASSWORD", required=True)   # mot de passe d'application
NC_ROOT = env("NC_ROOT", "WhatsApp").strip("/")

# CRM (callcrm) — vide = pas de transfert vers le CRM
CRM_URL = (env("CRM_URL", "") or "").rstrip("/")          # https://cloud.csmtaksit.dz/index.php/apps/callcrm/api/wa
CRM_SECRET = env("CRM_SECRET", "")                        # secret partagé avec callcrm (occ … wa_secret)

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
        r = self.s.request("MKCOL", self._u(path), timeout=30)
        if r.status_code not in (201, 405):  # 405 = existe déjà
            raise RuntimeError(f"MKCOL {path} -> HTTP {r.status_code} {r.text[:200]}")
        self._dirs.add(path)

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
def crm_post(path, data, queue_on_fail=True):
    if not CRM_URL or not CRM_SECRET:
        return True
    try:
        r = requests.post(CRM_URL + path, json=data, timeout=20,
                          headers={"X-CRM-Secret": CRM_SECRET, "OCS-APIRequest": "true", "User-Agent": "wa-nextcloud/1.1"})
        if r.status_code < 300:
            return True
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
        body = (msg.get("button") or {}).get("text") or json.dumps(msg.get("interactive", {}), ensure_ascii=False)[:500]
        line = f"[{stamp}] {who} | {mtype} | {body}"
        event["text"] = body
    else:
        line = f"[{stamp}] {who} | {mtype} | (type non archivé)"

    hist_append(f"{folder}/historique.txt", line)
    crm_post("/inbound", event)
    mark_processed(msg_id)

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


def process_payload(payload):
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


def _serial(payload):
    with _serial_lock:
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
    who = str(j.get("user_name") or j.get("user") or "CRM")
    if not to or (not text and not f):
        return jsonify(error="Destinataire ou contenu manquant"), 400
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
