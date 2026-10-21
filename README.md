# EasyCrm

Telephone prospecting / light call-center CRM, in two editions:

| Folder | Platform | Notes |
|---|---|---|
| `odoo/easycrm` | Odoo 18 | Odoo Store package (OPL-1) — tested on Odoo 18 only |
| `nextcloud/` | Nextcloud 28–40 | Nextcloud app (`easycrm`), WhatsApp shared inbox since 1.3.0 |
| `callcrm/` | Nextcloud 28–40 | CSM Taksit in-house edition (French, AD groups), WhatsApp inbox since 0.15.0 |
| `wa-nextcloud/` | Python 3.12 service | WhatsApp Cloud API webhook → CsmCloud folders + callcrm inbox (systemd + Apache) |

Languages: French, English, Arabic (RTL), Spanish, Italian, German. Translations are machine-quality: have a native speaker review them before a commercial release.

See each folder's README / `static/description/index.html` for features and installation.

## Telephony & WhatsApp presets (SIP / PBX)

Both editions (Odoo and Nextcloud) ship admin-page presets — pick a provider, the fields are pre-filled:

| Telephony (click-to-call) | How |
|---|---|
| Disabled | smartphone dialer (`tel:`) |
| Softphone link | `tel:` / `sip:` / `callto:` opened by the browser |
| Asterisk / FreePBX / Issabel | AMI `Originate` (rings the agent's extension, then dials the prospect) |
| Yeastar P-Series | OpenAPI `get_token` + `call/dial` |
| 3CX | Call Control API (`/connect/token`, `/callcontrol/{ext}/makecall`) |
| Generic HTTP | any PBX with a REST API: URL / method / headers / body with `{ext}` and `{number}` |

| WhatsApp | How |
|---|---|
| Link (default) | `wa.me` link, message sent by hand |
| Meta Cloud API | official API, free text or approved template |
| 360dialog | WhatsApp Business API |
| Twilio | WhatsApp sender, free text or Content SID |
| Generic HTTP | any gateway: `{number}`, `{text}`, `{name}` |

Each user gets a PBX **extension** (Odoo: user preferences; Nextcloud: Administration → Telephony). A **Test the connection** button checks the credentials. Number format sent to the PBX (national, E.164, `intl`, `00…`) and an outside-line prefix are configurable.

**Webhooks** (secret token generated in the admin page): the PBX posts end-of-call events and the WhatsApp provider posts incoming messages; they are attached to the prospect by the last 8 digits of the phone number and logged (and optionally set a status for answered / missed calls).
- Odoo: `/easycrm/pbx/webhook?token=…`, `/easycrm/wa/webhook?token=…`
- Nextcloud: `/apps/easycrm/api/hook/pbx?token=…`, `/apps/easycrm/api/hook/wa?token=…`

Testing status: unit/integration tests run against **simulated** servers (fake AMI socket, fake Yeastar/3CX/generic HTTP, mocked WhatsApp endpoints). Nothing has been validated against a real PBX or live WhatsApp account yet — try with a test extension first, and expect to tweak vendor-specific details (API paths, versions, templates).
