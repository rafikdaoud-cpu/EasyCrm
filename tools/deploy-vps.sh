#!/usr/bin/env bash
# Déploiement de Call CRM et du service wa-nextcloud sur le VPS CSM Taksit, uniquement depuis GitHub.
#
#   tools/deploy-vps.sh check                 état : commit déployé, écarts du VPS, nouveautés sur GitHub
#   tools/deploy-vps.sh deploy [REF] [options] déploie REF (défaut origin/main) : callcrm puis wa-nextcloud
#   tools/deploy-vps.sh rollback              remet la dernière sauvegarde de callcrm (code seulement)
#
# Options de deploy :
#   --only callcrm|wa   un seul composant         --tests   lance aussi tools/test-nextcloud.sh
#   --dry-run           montre ce qui serait fait --force   ignore les écarts du VPS et la version inchangée
#
# Garde-fous : refuse si les fichiers du VPS ont été modifiés à la main depuis le dernier déploiement,
# si le code change sans nouveau numéro de version, ou si la syntaxe PHP / JavaScript est invalide.
# Sauvegarde avant chaque déploiement (5 dernières gardées). occ upgrade seulement s'il y a une migration
# ou un changement d'info.xml autre que la version ; sinon la version est enregistrée avant la copie, sans coupure.
set -euo pipefail

REPO="${REPO:-/root/easycrm}"
NC="${NC:-/home/csmtak11/cloud.csmtaksit.dz}"
APP="${APP:-$NC/custom_apps/callcrm}"
WA="${WA:-/home/csmtak11/wa-nextcloud}"
OWNER="${OWNER:-csmtak11}"
PHP="${PHP:-/opt/cpanel/ea-php85/root/usr/bin/php}"
BACKUPS="${BACKUPS:-/root/archives/deploy}"
STATE="${STATE:-/root/archives/deploy/state}"
LOG="${LOG:-/var/log/callcrm-deploy.log}"
KEEP=5
WA_SERVICE="${WA_SERVICE:-wa-nextcloud}"
WA_HEALTH="${WA_HEALTH:-http://127.0.0.1:8085/}"

say() { printf '%s\n' "$*"; printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG" 2>/dev/null || true; }
die() { say "ERREUR : $*"; exit 1; }
occ() { (cd "$NC" && sudo -u "$OWNER" "$PHP" occ "$@"); }
mkdir -p "$BACKUPS" "$STATE"
exec 9>"$STATE/.lock"; flock -n 9 || die "un autre déploiement est en cours"

deployed() { cat "$STATE/$1.commit" 2>/dev/null || true; }   # dernier commit déployé du composant
version_at() { git -C "$REPO" show "$1:callcrm/appinfo/info.xml" | sed -n 's:.*<version>\(.*\)</version>.*:\1:p' | head -1; }

# Écarts entre les fichiers en ligne et le commit déployé (fins de ligne ignorées).
drift() { # $1 composant, $2 dossier en ligne, $3 dossier dans le dépôt
  local c; c=$(deployed "$1"); [ -n "$c" ] || { echo "(aucun déploiement enregistré)"; return 0; }
  local tmp; tmp=$(mktemp -d); git -C "$REPO" archive "$c" "$3" | tar -x -C "$tmp"
  (cd "$tmp/$3" && find . -type f ! -path './tests/*' | sort) | while read -r f; do
    if [ ! -f "$2/$f" ]; then echo "manquant : $f"; elif ! cmp -s <(tr -d '\r' < "$2/$f") <(tr -d '\r' < "$tmp/$3/$f"); then echo "modifié : $f"; fi
  done
  (cd "$2" && find . -type f ! -path './tests/*' ! -name '*.pyc' ! -path './venv/*' ! -path './data/*' ! -path './logs/*' ! -name '.env' ! -name 'error_log' ! -path './__pycache__/*' | sort) | while read -r f; do
    [ -f "$tmp/$3/$f" ] || echo "ajouté à la main : $f"
  done
  rm -rf "$tmp"
}

cmd_check() {
  git -C "$REPO" fetch -q origin main
  say "Call CRM en ligne : $(occ app:list | sed -n 's/.*- callcrm: //p') · commit déployé : $(deployed callcrm | cut -c1-9)"
  say "GitHub main : $(git -C "$REPO" rev-parse --short origin/main) · version $(version_at origin/main)"
  local d; d=$(drift callcrm "$APP" callcrm); [ -z "$d" ] && say "callcrm : aucun écart avec le commit déployé" || { say "callcrm : ÉCARTS"; echo "$d"; }
  if [ -n "$(deployed wa)" ]; then local w; w=$(git -C "$REPO" show "$(deployed wa):wa-nextcloud/app.py" | cmp -s - <(tr -d '\r' < "$WA/app.py") && echo "" || echo "modifié : app.py")
    [ -z "$w" ] && say "wa-nextcloud : aucun écart" || { say "wa-nextcloud : ÉCARTS"; echo "$w"; }; fi
  local c; c=$(deployed callcrm); [ -n "$c" ] && { local n; n=$(git -C "$REPO" rev-list --count "$c..origin/main" -- callcrm wa-nextcloud/app.py); say "$n commit(s) à déployer depuis GitHub"; }
}

lint() { # $1 dossier extrait
  local bad=0
  while IFS= read -r -d '' f; do "$PHP" -l "$f" >/dev/null 2>&1 || { say "PHP invalide : ${f#$1/}"; bad=1; }; done < <(find "$1/callcrm/lib" "$1/callcrm/appinfo" -name '*.php' -print0)
  while IFS= read -r -d '' f; do node --check "$f" 2>/dev/null || { say "JavaScript invalide : ${f#$1/}"; bad=1; }; done < <(find "$1/callcrm/js" -maxdepth 1 -name '*.js' -print0)
  [ -f "$1/wa-nextcloud/app.py" ] && { python3 -m py_compile "$1/wa-nextcloud/app.py" 2>/dev/null || { say "Python invalide : wa-nextcloud/app.py"; bad=1; }; }
  return $bad
}

deploy_callcrm() { # $1 commit, $2 dossier extrait, $3 dry-run, $4 force
  local c=$1 src=$2/callcrm prev; prev=$(deployed callcrm)
  local d; d=$(drift callcrm "$APP" callcrm)
  if [ -n "$d" ]; then say "Le VPS a été modifié à la main depuis le dernier déploiement :"; echo "$d"
    [ "$4" = 1 ] || die "reportez ces changements dans GitHub, ou relancez avec --force pour les écraser"; fi
  local live new; live=$(occ app:list 2>/dev/null | sed -n 's/.*- callcrm: //p' || true); new=$(version_at "$c")
  [ -n "$live" ] || die "impossible de lire la version installée (occ ne répond pas) : rien n'a été déployé"
  [ -n "$new" ] || die "version introuvable dans callcrm/appinfo/info.xml du commit"
  local changed; changed=$( [ -n "$prev" ] && git -C "$REPO" diff --name-only "$prev" "$c" -- callcrm | grep -v '^callcrm/tests/' || true)
  [ -z "$prev" ] && changed=all
  if [ -z "$changed" ]; then say "callcrm : rien à déployer (déjà au commit $(echo "$c" | cut -c1-9))"; return 0; fi
  if [ "$new" = "$live" ] && [ "$4" != 1 ]; then die "le code change mais la version reste $new : augmentez <version> dans callcrm/appinfo/info.xml (les navigateurs garderaient l'ancien JavaScript)"; fi
  local upgrade=0
  if [ "$changed" = all ] || echo "$changed" | grep -q '^callcrm/lib/Migration/'; then upgrade=1; fi
  if [ -n "$prev" ] && git -C "$REPO" diff "$prev" "$c" -- callcrm/appinfo/info.xml | grep '^[-+][^-+]' | grep -vq '<version>'; then upgrade=1; fi
  say "callcrm : $live → $new ($(echo "$changed" | wc -l) fichier(s)) · $( [ $upgrade = 1 ] && echo 'occ upgrade (migration ou info.xml)' || echo 'sans coupure')"
  [ "$3" = 1 ] && { echo "$changed"; return 0; }
  local bk="$BACKUPS/callcrm-$(date +%Y%m%d-%H%M%S)-$live.tar.gz"
  tar czf "$bk" -C "$(dirname "$APP")" "$(basename "$APP")"; echo "$live" > "$bk.version"; say "sauvegarde : $bk"
  ls -1 "$BACKUPS"/callcrm-*.tar.gz | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do rm -f "$old" "$old.version"; done
  [ $upgrade = 0 ] && occ config:app:set callcrm installed_version --value "$new" >/dev/null
  rsync -a --delete --exclude tests "$src/" "$APP/"; chown -R "$OWNER:$OWNER" "$APP"
  if [ $upgrade = 1 ]; then occ upgrade || { occ maintenance:mode --off || true; die "occ upgrade a échoué : voir $NC/../ncdata/nextcloud.log ; retour possible avec « rollback »"; }; fi
  occ status | grep -q 'maintenance: false' || { occ maintenance:mode --off; say "maintenance désactivée"; }
  [ "$(occ app:list | sed -n 's/.*- callcrm: //p')" = "$new" ] || die "Nextcloud n'indique pas la version $new"
  echo "$c" > "$STATE/callcrm.commit"; occ config:app:set callcrm deployed_commit --value "$c" >/dev/null
  say "callcrm $new déployé (commit $(echo "$c" | cut -c1-9))"
}

deploy_wa() { # $1 commit, $2 dossier extrait, $3 dry-run, $4 force
  local c=$1 f=$2/wa-nextcloud/app.py prev; prev=$(deployed wa)
  if cmp -s "$f" <(tr -d '\r' < "$WA/app.py"); then echo "$c" > "$STATE/wa.commit"; say "wa-nextcloud : rien à déployer"; return 0; fi
  if [ -n "$prev" ] && ! git -C "$REPO" show "$prev:wa-nextcloud/app.py" | cmp -s - <(tr -d '\r' < "$WA/app.py"); then
    say "wa-nextcloud : app.py a été modifié à la main sur le VPS"; [ "$4" = 1 ] || die "reportez-le dans GitHub ou relancez avec --force"; fi
  say "wa-nextcloud : nouvelle version de app.py"; [ "$3" = 1 ] && return 0
  local bkf; bkf="$BACKUPS/wa-app.py.$(date +%Y%m%d-%H%M%S)"; cp -p "$WA/app.py" "$bkf"; ls -1 "$BACKUPS"/wa-app.py.* | sort -r | tail -n +$((KEEP + 1)) | xargs -r rm -f
  install -o "$OWNER" -g "$OWNER" -m 644 "$f" "$WA/app.py"
  systemctl restart "$WA_SERVICE"; sleep 3
  systemctl is-active -q "$WA_SERVICE" && curl -fs -o /dev/null "$WA_HEALTH" || { install -o "$OWNER" -g "$OWNER" -m 644 "$bkf" "$WA/app.py"; systemctl restart "$WA_SERVICE"; die "le service ne répond pas : ancienne version remise"; }
  echo "$c" > "$STATE/wa.commit"; say "wa-nextcloud déployé et redémarré (commit $(echo "$c" | cut -c1-9))"
}

cmd_deploy() {
  local ref=origin/main only='' tests=0 dry=0 force=0
  while [ $# -gt 0 ]; do case "$1" in
    --only) only=$2; shift ;; --tests) tests=1 ;; --dry-run) dry=1 ;; --force) force=1 ;; -*) die "option inconnue : $1" ;; *) ref=$1 ;; esac; shift; done
  [ -z "$(git -C "$REPO" status --porcelain)" ] || die "le dépôt $REPO a des modifications non commitées : le déploiement se fait depuis GitHub"
  git -C "$REPO" fetch -q origin
  local c; c=$(git -C "$REPO" rev-parse --verify "$ref^{commit}") || die "référence inconnue : $ref"
  git -C "$REPO" merge-base --is-ancestor "$c" origin/main || [ $force = 1 ] || die "$ref n'est pas dans main sur GitHub (--force pour déployer quand même)"
  local tmp; tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
  git -C "$REPO" archive "$c" callcrm wa-nextcloud | tar -x -C "$tmp"
  lint "$tmp" || die "vérification de syntaxe en échec : rien n'a été déployé"
  if [ $tests = 1 ]; then git -C "$REPO" worktree add -f -q "$tmp/wt" "$c"; (cd "$tmp/wt" && PHP_BIN="$PHP" bash tools/test-nextcloud.sh >"$tmp/tests.log" 2>&1) || { git -C "$REPO" worktree remove -f "$tmp/wt"; tail -20 "$tmp/tests.log"; die "tests en échec : rien n'a été déployé"; }
    git -C "$REPO" worktree remove -f "$tmp/wt"; say "tests : tous passés"; fi
  say "déploiement du commit $(echo "$c" | cut -c1-9) ($(git -C "$REPO" log -1 --format=%s "$c" | cut -c1-70))"
  [ "$only" = wa ] || deploy_callcrm "$c" "$tmp" "$dry" "$force"
  [ "$only" = callcrm ] || deploy_wa "$c" "$tmp" "$dry" "$force"
}

cmd_rollback() {
  local bk; bk=$(ls -1 "$BACKUPS"/callcrm-*.tar.gz 2>/dev/null | sort -r | head -1); [ -n "$bk" ] || die "aucune sauvegarde"
  local v; v=$(cat "$bk.version"); say "retour à $(basename "$bk") (version $v) — code seulement, une migration déjà passée n'est pas annulée"
  local tmp; tmp=$(mktemp -d); tar xzf "$bk" -C "$tmp"
  occ config:app:set callcrm installed_version --value "$v" >/dev/null
  rsync -a --delete --exclude tests "$tmp/$(basename "$APP")/" "$APP/"; chown -R "$OWNER:$OWNER" "$APP"; rm -rf "$tmp"
  rm -f "$STATE/callcrm.commit"; say "callcrm remis en $v ; le prochain « check » signalera l'écart avec GitHub"
}

case "${1:-check}" in
  check) cmd_check ;; deploy) shift; cmd_deploy "$@" ;; rollback) cmd_rollback ;;
  *) sed -n '2,15p' "$0"; exit 1 ;;
esac
