from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, Preformatted, KeepTogether
NAVY=colors.HexColor('#1E2447'); BLUE=colors.HexColor('#4A90E2'); MIST=colors.HexColor('#D8D8D8'); INK=colors.HexColor('#222222')
B=ParagraphStyle('b',fontName='Helvetica',fontSize=10,leading=14.5,textColor=INK,spaceAfter=5)
H1=ParagraphStyle('h1',parent=B,fontName='Helvetica-Bold',fontSize=15,leading=19,textColor=NAVY,spaceBefore=12,spaceAfter=6)
H2=ParagraphStyle('h2',parent=B,fontName='Helvetica-Bold',fontSize=11.5,textColor=BLUE,spaceBefore=8,spaceAfter=3)
BL=ParagraphStyle('bl',parent=B,leftIndent=14,bulletIndent=3,spaceAfter=2)
CODE=ParagraphStyle('c',fontName='Courier',fontSize=8.4,leading=11.2,textColor=colors.white)
CELL=ParagraphStyle('cell',parent=B,fontSize=9,leading=12,spaceAfter=0)
def build(path,title,sub,content):
    def deco(c,d):
        c.saveState(); c.setFillColor(NAVY); c.rect(0,A4[1]-14*mm,A4[0],14*mm,stroke=0,fill=1)
        c.setFillColor(colors.white); c.setFont('Helvetica-Bold',11); c.drawString(18*mm,A4[1]-9*mm,'CSM TAKSIT')
        c.setFont('Helvetica',9); c.drawRightString(A4[0]-18*mm,A4[1]-9*mm,title)
        c.setFillColor(colors.HexColor('#6b6b6b')); c.setFont('Helvetica',8); c.drawString(18*mm,10*mm,'Direction des systèmes d’information — octobre 2026'); c.drawRightString(A4[0]-18*mm,10*mm,'Page %d'%d.page); c.restoreState()
    doc=SimpleDocTemplate(path,pagesize=A4,leftMargin=18*mm,rightMargin=18*mm,topMargin=24*mm,bottomMargin=18*mm,title=title,author='DSI CSM TAKSIT')
    st=[Spacer(1,6*mm),Paragraph(title,ParagraphStyle('t',parent=H1,fontSize=24,leading=28,spaceBefore=0)),Paragraph(sub,ParagraphStyle('s',parent=B,fontSize=11,textColor=colors.HexColor('#555555'),spaceAfter=10)),Table([['']],colWidths=[174*mm],rowHeights=[2],style=[('BACKGROUND',(0,0),(-1,-1),BLUE)]),Spacer(1,4)]
    for k,v in content:
        if k=='h1': st.append(Paragraph(v,H1))
        elif k=='h2': st.append(Paragraph(v,H2))
        elif k=='p': st.append(Paragraph(v,B))
        elif k=='ul': st+= [Paragraph(x,BL,bulletText='•') for x in v]+[Spacer(1,4)]
        elif k=='ol': st+= [Paragraph(x,BL,bulletText=f'{i+1}.') for i,x in enumerate(v)]+[Spacer(1,4)]
        elif k=='code': st+=[Table([[Preformatted(v.strip('\n'),CODE)]],colWidths=[174*mm],style=[('BACKGROUND',(0,0),(-1,-1),NAVY),('LEFTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6)]),Spacer(1,8)]
        elif k=='table':
            rows=[[Paragraph(f'<b>{c}</b>' if i==0 else c,ParagraphStyle('x',parent=CELL,textColor=colors.white if i==0 else INK)) for c in r] for i,r in enumerate(v[0])]
            t=Table(rows,colWidths=[w*mm for w in v[1]],repeatRows=1)
            t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),NAVY),('GRID',(0,0),(-1,-1),.4,MIST),('VALIGN',(0,0),(-1,-1),'TOP'),('ROWBACKGROUNDS',(0,1),(-1,-1),[colors.white,colors.HexColor('#F3F5FA')]),('TOPPADDING',(0,0),(-1,-1),4),('BOTTOMPADDING',(0,0),(-1,-1),4)]))
            st+=[t,Spacer(1,8)]
        elif k=='pb': st.append(PageBreak())
        elif k=='note': st.append(Table([[Paragraph(v,CELL)]],colWidths=[174*mm],style=[('BACKGROUND',(0,0),(-1,-1),colors.HexColor('#EAF2FD')),('LINEBEFORE',(0,0),(0,-1),3,BLUE),('LEFTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6)])); st.append(Spacer(1,8))
    doc.build(st,onFirstPage=deco,onLaterPages=deco)

# ---------------- 1. Résumé
build('/home/claude/docs/1_Resume_applications_Nextcloud.pdf','Résumé des applications','Call CRM et Réclamations, hébergées sur CsmCloud (Nextcloud)',[
('h1','Vue d’ensemble'),
('p','Deux applications maison, intégrées à Nextcloud (cloud.csmtaksit.dz), donc sans licence supplémentaire. Elles utilisent les comptes de l’entreprise (Active Directory, connexion unique SSO via ADFS) et fonctionnent sur PC comme sur smartphone.'),
('table',([['','Call CRM (callcrm)','Réclamations (reclamations)'],
 ['Rôle','Prospection téléphonique : mini CRM et centre de contact pour les ~30 commerciaux.','Suivi des réclamations clients, de la saisie à la clôture.'],
 ['Version','0.17.1','1.2.0'],
 ['Utilisateurs','Commerciaux, superviseurs, administrateurs, lecture seule.','Agents, superviseurs, administrateurs, lecture seule, saisie (tout autre utilisateur connecté).'],
 ['Fonctions clés','Liste de prospects, appel en un clic, résultats d’appel, rappels programmés avec limite de tentatives, objectif quotidien, simulateur de devis de cuisine, import Excel, répartition par catégorie, boîte WhatsApp partagée, statistiques, journal d’audit, invitations par e-mail.','Saisie et référence automatique, statuts (nouvelle, en cours, en attente, résolue, clôturée, rejetée), priorités, échéances et retards, affectation par service et par agent, historique daté, statistiques et export Excel, import CSV.'],
 ['Écrans','PC et mobile, mode clair et sombre, aux couleurs de la charte CSM.','Idem.']],[28,73,73])),
('h1','Ce qui est fourni autour'),
('ul',['<b>SSO</b> : les utilisateurs d’un PC du domaine entrent automatiquement (ADFS + application user_saml).','<b>WhatsApp</b> : un service (wa-nextcloud) reçoit les messages de l’API officielle, enregistre photos et documents dans un dossier par numéro client et les fait remonter dans la fiche prospect du CRM. L’envoi se fait depuis le CRM, dans la fenêtre de 24 h autorisée par WhatsApp.','<b>Collabora</b> : ouverture directe des documents Office dans le navigateur (office.csmtaksit.dz).','<b>Sécurité</b> : HSTS actif, OPcache dimensionné, MariaDB réglé.']),
('h1','Droits par rôle'),
('h2','Call CRM'),
('table',([['Action','Admin','Superviseur','Commercial','Lecture'],
 ['Voir les prospects de tous','Oui','Oui','Ses prospects','Oui'],
 ['Appeler / traiter','Oui','Non','Oui','Non'],
 ['Créer un prospect','Oui','Oui','Oui','Non'],
 ['Importer, répartir, transférer','Oui','Oui','Non','Non'],
 ['Journal d’audit','Oui','Oui','Non','Non'],
 ['Champs, statuts, réglages, invitations, suppression','Oui','Non','Non','Non'],
 ['Exporter','Oui','Oui','Oui','Oui']],[62,28,32,30,22])),
('h2','Réclamations'),
('table',([['Action','Admin','Superviseur','Agent / saisie','Lecture'],
 ['Créer et traiter','Oui','Oui','Oui','Non'],
 ['Affecter à un service / agent','Oui','Oui','Non','Non'],
 ['Voir toutes les réclamations','Oui','Oui','Saisie : oui','Oui'],
 ['Importer, configurer, supprimer','Oui','Non','Non','Non']],[62,28,32,30,22])),
('note','Tout administrateur Nextcloud est administrateur des deux applications. Les autres rôles s’obtiennent en ajoutant l’utilisateur au groupe correspondant (voir le guide d’installation).'),
('h1','Points d’attention'),
('ul',['Ne changez plus le mot de passe du compte AD <b>whatsapp</b> sans recréer son mot de passe d’application Nextcloud : le CRM ne recevrait plus les messages.','Le certificat de signature ADFS expire en octobre 2027.','Les sauvegardes de Nextcloud (fichiers + base) doivent inclure les tables des deux applications.'])
])

# ---------------- 2. Installation
build('/home/claude/docs/2_Guide_installation.pdf','Guide d’installation','Call CRM 0.17.1 et Réclamations 1.2.0 sur Nextcloud (cPanel / AlmaLinux)',[
('h1','Prérequis'),
('ul',['Nextcloud 28 à 40 (installé : 35). PHP 8.5 pour ce site (<b>ea-php85</b>).','Accès root SSH au VPS. Le dossier Nextcloud est <b>/home/csmtak11/cloud.csmtaksit.dz</b>, propriétaire <b>csmtak11</b>.','Les fichiers <b>callcrm.zip</b> et <b>reclamations.zip</b> transférés sur le serveur (par exemple dans /root).']),
('h1','1. Installer ou mettre à jour une application'),
('p','La procédure est la même pour les deux. Exemple avec Call CRM ; remplacez par <b>reclamations</b> pour l’autre.'),
('ol',['Mettre Nextcloud en maintenance (évite les erreurs pendant la copie).','Sauvegarder l’ancienne version si elle existe.','Décompresser le zip dans <b>custom_apps</b>, remettre les bons droits.','Activer (première installation) ou mettre à niveau (mise à jour).','Sortir de maintenance et recharger le navigateur (Ctrl+F5).']),
('code','''cd /home/csmtak11/cloud.csmtaksit.dz
PHP="/opt/cpanel/ea-php85/root/usr/bin/php -d memory_limit=512M"
su -s /bin/bash csmtak11 -c "$PHP occ maintenance:mode --on"

# sauvegarde de l'ancienne version (mise à jour seulement)
cp -a custom_apps/callcrm /root/callcrm.bak.$(date +%F) 2>/dev/null

cd custom_apps && rm -rf callcrm && unzip -q /root/callcrm.zip
chown -R csmtak11:csmtak11 callcrm
cd ..

# premiere installation :
su -s /bin/bash csmtak11 -c "$PHP occ app:enable callcrm"
# mise a jour :
su -s /bin/bash csmtak11 -c "$PHP occ upgrade"

su -s /bin/bash csmtak11 -c "$PHP occ maintenance:mode --off"'''),
('p','Même chose pour Réclamations : remplacez <b>callcrm</b> par <b>reclamations</b> (zip : reclamations.zip).'),
('note','Les tables de base de données sont créées ou mises à jour automatiquement par <b>occ upgrade</b>. Rien à faire à la main. Pour revenir en arrière, remettez le dossier sauvegardé dans custom_apps, remettez les droits, puis lancez occ upgrade.'),
('h1','2. Groupes et rôles'),
('p','Les groupes sont créés automatiquement à l’activation. Ajoutez chaque utilisateur au groupe de son rôle (Nextcloud, Utilisateurs, ou occ group:adduser).'),
('table',([['Application','Groupes'],
 ['Call CRM','callcrm_admin, callcrm_superviseur, callcrm_commercial, callcrm_lecture'],
 ['Call CRM : équipes par catégorie','callcrm_motos, callcrm_electro_media, callcrm_amenagement (le commercial y est rattaché pour recevoir les prospects de sa catégorie)'],
 ['Réclamations','reclam_admin, reclam_superviseur, reclam_agent, reclam_lecture']],[50,124])),
('code','''su -s /bin/bash csmtak11 -c "$PHP occ group:adduser callcrm_commercial prenom.nom"'''),
('p','Pour utiliser des groupes de l’Active Directory à la place des groupes par défaut :'),
('code','''su -s /bin/bash csmtak11 -c "$PHP occ config:app:set callcrm group_commercial --value='NomDuGroupeAD'"'''),
('h1','3. Réglages de départ (Call CRM)'),
('ul',['Ouvrir Call CRM, onglet <b>Administration</b>.','Vérifier les <b>statuts</b> et leurs couleurs, les <b>champs</b> de la fiche client, et les réglages : nombre maximum de tentatives de rappel, plage horaire et espacement des rappels, objectif d’appels par jour.','Importer la liste des prospects (Excel .xlsx ou .xls) puis les répartir entre les commerciaux.']),
('h1','4. Réglages de départ (Réclamations)'),
('ul',['Onglet <b>Administration</b> : définir les services, importer la liste initiale en CSV si besoin.','Affecter les agents à leur service.']),
('h1','5. WhatsApp (Call CRM)'),
('p','Le service <b>wa-nextcloud</b> est installé à part, dans /home/csmtak11/wa-nextcloud (gunicorn). Son fichier .env contient les clés de l’API WhatsApp, le compte Nextcloud <b>whatsapp</b> avec son mot de passe d’application, et l’adresse du CRM avec le secret partagé. Vérifier après toute mise à jour de Call CRM :'),
('code','''systemctl status wa-nextcloud --no-pager | head -5
tail -n 20 /home/csmtak11/wa-nextcloud/app.log'''),
('note','Si les messages n’arrivent plus : cause la plus fréquente, mot de passe d’application invalidé après un changement de mot de passe AD du compte whatsapp (erreur 401 dans le journal). Recréez le mot de passe d’application depuis l’interface web, connecté en tant que whatsapp, puis mettez-le dans le .env et redémarrez le service.'),
('h1','6. Contrôles après installation'),
('ul',['Les deux applications s’ouvrent depuis le menu Nextcloud et affichent la nouvelle interface (Ctrl+F5 si l’ancienne apparaît).','Un compte de chaque rôle voit bien les bons onglets.','Aucune erreur dans <b>Paramètres, Administration, Journalisation</b>.','Test d’un appel (sur smartphone) et d’un message WhatsApp entrant.']),
('h1','Dépannage rapide'),
('table',([['Symptôme','Cause probable / action'],
 ['Page blanche ou ancienne interface','Ctrl+F5 ; vérifier les droits (chown csmtak11) et que occ upgrade a bien été lancé.'],
 ['« Accès refusé »','L’utilisateur n’est dans aucun groupe de l’application (Call CRM). Ajouter au bon groupe.'],
 ['Mise à jour refusée','Version de Nextcloud hors plage 28-40, ou dossier avec mauvais propriétaire.'],
 ['Messages WhatsApp absents','Voir la section 5 (erreur 401 / service arrêté).'],
 ['Erreur mémoire PHP en ligne de commande','Garder l’option -d memory_limit=512M dans la commande occ.']],[55,119]))
])

# ---------------- 3. Utilisation
build('/home/claude/docs/3_Guide_utilisation.pdf','Guide d’utilisation','Call CRM et Réclamations : le quotidien, pas à pas',[
('h1','Call CRM : le quotidien d’un commercial'),
('h2','La barre d’onglets'),
('p','En haut : <b>À traiter</b> (nouveaux prospects), <b>À rappeler</b> (avec une pastille rouge pour les retards), un onglet par résultat (Intéressé, Pas intéressé, etc.), puis <b>Tous</b>, <b>WhatsApp</b>, <b>Statistiques</b> et <b>Administration</b> selon vos droits. Quand il y a beaucoup d’onglets, des flèches ‹ et › apparaissent sur PC pour les faire défiler ; sur téléphone, faites glisser le doigt. À droite, votre objectif du jour s’affiche avec sa barre de progression.'),
('h2','Traiter un prospect'),
('ol',['Ouvrez la liste <b>À traiter</b>. Chaque ligne montre le prospect, son numéro, son statut et l’heure de rappel. Une ligne à liseré rouge est en retard.','Touchez la ligne pour ouvrir la fiche.','Touchez <b>Appeler</b> : le téléphone compose le numéro. Sur PC, le bouton lance l’application d’appel du poste.','Après l’appel, choisissez le <b>résultat</b> (boutons colorés). Pour un rappel, indiquez la date et l’heure (raccourcis 1 h, 2 h, 4 h, demain) et ajoutez un motif ou une note.','Enregistrez : le prospect passe dans la bonne liste et l’appel est ajouté à l’historique.']),
('p','Les informations du client (champs configurés) sont à droite de la fiche sur PC, sous la fiche sur téléphone. Pensez à les compléter pendant l’appel, puis à enregistrer.'),
('h2','Rappels et tentatives'),
('ul',['Un rappel arrive sous forme de fenêtre contextuelle à l’heure prévue, tant que la page est ouverte.','Le nombre de tentatives est limité par l’administrateur : la fiche indique « Tentatives de rappel : 2 / 3 » dans un cadre non modifiable. À la limite, le prospect est clôturé automatiquement.','Hors connexion, vos appels sont mis en file et envoyés au retour du réseau (bandeau en bas de l’écran).']),
('h2','Simulateur de cuisine'),
('p','Pendant l’appel, touchez <b>Simulateur de cuisine</b> sur la fiche (ou l’onglet <b>Simulateur</b> en haut). Saisissez la largeur et la longueur, indiquez si les meubles sont hauts et bas, puis les équipements (colonne, cuisinière, machine à laver, lave-vaisselle), le modèle (Classique ou Casquette) et les suppléments (tiroir, casserolier). L’estimation s’affiche en bas de l’écran, avec une fourchette, et le schéma de la cuisine se met à jour en direct.'),
('ul',['<b>Enregistrer dans la fiche</b> garde la simulation dans la fiche du prospect (rubrique « Simulations de cuisine »).','<b>Copier le résumé</b> met le détail dans le presse-papiers pour le coller dans une note ou un message WhatsApp.','Le prix est indicatif : à confirmer après prise de mesures.']),
('h2','WhatsApp depuis la fiche'),
('p','Le bouton vert <b>WhatsApp</b> ouvre la conversation. Les messages et pièces jointes reçus du client apparaissent dans la fiche. Vous pouvez répondre dans la fenêtre de 24 h après son dernier message ; au-delà, WhatsApp n’autorise plus que les messages modèles. Les photos arrivent comme photos dans la conversation ; la copie dans le dossier du client sur CsmCloud est enregistrée en PDF.'),
('h2','L’onglet WhatsApp'),
('p','Boîte de réception partagée : <b>File d’attente</b> (conversations à attribuer), <b>Mes conversations</b>, <b>Toutes</b> et <b>Résolues</b>. Une conversation non lue a un liseré vert ; « Inconnu » signale un numéro absent de la base. Dans une conversation : répondre (texte ou fichier), attribuer à un collègue, créer un prospect à partir du numéro, marquer comme lue ou résolue.'),
('h2','Statistiques'),
('p','Choisissez la période et les filtres (commercial, groupe, catégorie, résultat). Vous voyez le nombre d’appels, la répartition par statut, les appels par heure, le tableau par commercial et le journal des appels. Le bouton d’export produit un fichier Excel.'),
('h1','Call CRM : superviseur et administrateur'),
('ul',['<b>Importer</b> : choisissez un fichier Excel ; l’application nettoie les lignes, retire les numéros en double et affiche le bilan avant validation.','<b>Répartir</b> : par catégorie de produit, équitablement entre les commerciaux cochés, ou vers un groupe précis.','<b>Transférer</b> : dans une liste, cochez des fiches puis changez de commercial ou de statut (barre de sélection en haut). Un transfert en masse par statut existe dans Administration.','<b>Journal d’audit</b> : qui a importé, transféré, changé un statut et quand.','<b>Administration</b> (admin) : statuts et couleurs, champs de la fiche, <b>prix et coefficients du simulateur de cuisine</b> (modèles, équipements avec leur déduction et leur prix, suppléments, coefficients, plan de travail, fourchette, arrondi : tout est modifiable), réglages (tentatives, plage et espacement des rappels, objectif quotidien), invitations par e-mail aux commerciaux, suppression.']),
('pb',''),
('h1','Réclamations'),
('h2','Saisir une réclamation'),
('ol',['Cliquez <b>＋ Nouvelle réclamation</b> (bouton bleu dans la barre d’onglets).','Renseignez le client, son téléphone, la facture, le produit, la catégorie, le type, la priorité et l’échéance. Choisissez le service ; la liste des agents se limite à ce service.','Décrivez le problème puis enregistrez. Une référence est créée automatiquement.']),
('h2','Les listes'),
('p','<b>Nouvelles</b>, <b>En cours</b>, <b>En retard</b>, <b>Résolues</b>, <b>Toutes</b> : chaque ligne indique la référence, le client, le type, le service, l’agent, le statut, la priorité et l’échéance (en rouge si dépassée). La recherche et les filtres sont au-dessus de la liste.'),
('h2','Traiter une réclamation'),
('p','Ouvrez la fiche. En haut, une frise montre l’avancement (Nouvelle, En cours, Résolue, Clôturée). À droite, la carte <b>Traitement</b> permet de changer le statut, le service, l’agent et d’ajouter une note. Une note est obligatoire pour passer en « Résolue » ou « Rejetée ». Chaque action est ajoutée à l’<b>historique</b> avec la date et l’auteur ; rien n’est effacé.'),
('h2','Statuts'),
('table',([['Statut','Signification'],['Nouvelle','Enregistrée, pas encore prise en charge.'],['En cours','Un agent la traite.'],['Attente client / fournisseur','En pause, on attend une réponse.'],['Résolue','Solution trouvée et notée.'],['Clôturée','Terminée (superviseur ou administrateur).'],['Rejetée','Non recevable, avec le motif.']],[55,119])),
('h2','Statistiques et administration'),
('p','Le tableau de bord affiche les chiffres clés puis les tableaux par statut, type, catégorie et agent, avec export Excel. L’administrateur gère les services, importe des réclamations en CSV et consulte les rôles.'),
('h1','Astuces'),
('ul',['<b>Ctrl+F5</b> après une mise à jour pour charger la nouvelle interface.','Sur téléphone, ajoutez la page à l’écran d’accueil pour l’ouvrir comme une application.','Une question sur les droits ? Votre rôle dépend de votre groupe Nextcloud : demandez à la DSI.'])
])
