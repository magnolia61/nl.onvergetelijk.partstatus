# Memory — nl.onvergetelijk.partstatus + Google Sheets sync
_Bijgewerkt: 2026-06-03_

---

## Bestandsstructuur sync

| Bestand | Rol |
|---------|-----|
| `partstatus.sheetsync.php` | Alle sync-logica (dirty flag, shutdown handler, SQL, Sheets API) |
| `partstatus.php` | Triggert `ozk_sheet_sync_set_dirty()` vanuit `civicrm_post` |
| `/var/www/vhosts/ozkprod/web/bin/api-sheet-update.php` | Dunne HTTP-wrapper (bootstrapt Drupal + roept sheetsync aan) |
| `/usr/local/bin/maintenance/sheet-sync-capaciteit.sh` | Bash-wrapper voor cron/terminal (incl. `-v` verbose tabel) |
| `/usr/local/bin/maintenance/sheet-sync-capaciteit.php` | PHP-bootstrapper voor cron |

---

## Google Sheets

| Item | Waarde |
|------|--------|
| Sheet ID | `1NyGIPjdxUOA82Xt0dzCrJwJRA8YzzVZrE3KEGCpBetI` |
| Tabblad | `TOTAALOVERZICHT` |
| Service account | `ozkcivicrm@onvergetelijk-209212.iam.gserviceaccount.com` |
| Sleutelbestand | `/home/webteam/.config/google/credentials_serviceaccount.json` |
| Vendor autoload | `/var/www/vhosts/ozkprod/web/bin/vendor/autoload.php` |
| Scopes | `auth/drive`, `spreadsheets.google.com/feeds`, `auth/spreadsheets` (alle drie nodig) |
| Cron-log | `/var/log/cron/sheet-sync-capaciteit.log` |

---

## Kolom- en rijmapping TOTAALOVERZICHT

### Kampen (kolommen)
| Kolom | Kamp | Event ID (fiscaal jaar 2026) |
|-------|------|------------------------------|
| B | KK1 | 298 |
| C | KK2 | 299 |
| D | BK1 | 300 |
| E | BK2 | 301 |
| F | TK1 | 302 |
| G | TK2 | 303 |
| H | JK1 | 304 |
| I | JK2 | 305 |
| J | TOP | — (geen event 2026) |
| K | Totaal | SUM formule |

Leiding event ID: 307. Event-IDs worden dynamisch opgehaald o.b.v. fiscaal jaar (1 dec – 30 nov),
gecached 1 uur in Civi-cache (`ozk_sheet_event_ids`).

### Rijen (wat het script schrijft)
| Rij | Label | Slot |
|-----|-------|------|
| 2 | jongens (deelnemers) | `deel_j` |
| 3 | meisjes (deelnemers) | `deel_m` |
| 7 | Wachtlijst jongens | `wait_j` |
| 8 | Wachtlijst meisjes | `wait_m` |
| 9 | Voorheen WL jongens | `appr_j` |
| 10 | Voorheen WL meisjes | `appr_m` |
| 11 | St.Gave jongens | `gave_j` |
| 12 | St.Gave meisjes | `gave_m` |
| 13 | capaciteit jongens | `cap_j` |
| 14 | capaciteit meisjes | `cap_m` |
| 15 | max jongens | `max_j` |
| 16 | max meisjes | `max_m` |
| 20 | heren (leiding) | `leid_j` |
| 21 | dames (leiding) | `leid_m` |
| 33 | aanmeldingen bijgewerkt tot | timestamp |

Rijen 4–6, 17–19, 22–32: formules in de sheet — script schrijft hier NIET naar.

### Event custom fields (capaciteit, uit `civicrm_value_event_kenmerk_211`)
| Veld | Kolom in DB |
|------|-------------|
| capaciteit jongens | `capaciteit_jongens_2225` |
| capaciteit meisjes | `capaciteit_meisjes_2227` |
| max jongens | `max_jongens_2228` |
| max meisjes | `max_meisjes_2229` |

### Participant status IDs
| Constante | Status IDs | Betekenis |
|-----------|-----------|-----------|
| `OZK_STATUS_DEEL` | 1,5,6,15 | Bevestigd/betaald |
| `OZK_STATUS_WACHT` | 7 | Wachtlijst |
| `OZK_STATUS_APPR` | 9,10 | Doorgestroomd/oordeel |
| `OZK_STATUS_BREED` | 1,5,6,7,9,15 | Breed (incl. wachtlijst, voor gave/leid) |

### Leiding-filter
Leiding wordt gefilterd op `civicrm_value_part_118.part_kamptype_id_961` = `event_type_id` van het kamp.
**Let op:** `custom_962` bestaat NIET — altijd `custom_961` gebruiken.

---

## Event type IDs (stabiel, niet jaarsgebonden)
| Kamp | event_type_id |
|------|--------------|
| KK1 | 11 |
| KK2 | 21 |
| BK1 | 12 |
| BK2 | 22 |
| TK1 | 13 |
| TK2 | 23 |
| JK1 | 14 |
| JK2 | 24 |
| TOP | 33 |
| LEID | 1 |

---

## Sync-mechanisme (3 lagen)
1. **Dirty flag** — `ozk_sheet_sync_set_dirty()` vanuit `civicrm_post` bij elke participant-mutatie
2. **Shutdown handler** — 1× per request, na alle hooks, max 1 Google API-call per request
3. **Cron** — `sheet-sync-capaciteit.sh` elk uur, forceert sync ongeacht dirty flag

---

## Kampblad-formules die TOTAALOVERZICHT uitlezen
Elke kampblad (KK1–JK2) heeft formules die hun eigen kolom in TOTAALOVERZICHT refereren:
- **KK1** → kolom B, **KK2** → C, **BK1** → D, **BK2** → E, **TK1** → F, **TK2** → G, **JK1** → H, **JK2** → I

Bugs gevonden en gecorrigeerd op 2026-06-03:
- BK2 verwees naar kolom D (BK1) i.p.v. E (BK2)
- TK2 verwees naar kolom I (JK2) i.p.v. G (TK2) voor wachtlijst/voorheen WL
- JK1 verwees naar kolom I (JK2) i.p.v. H (JK1)
- TK1!I34 verwees naar H10 (JK1) i.p.v. F10 (TK1)
- KK1 had hardcoded totalen voor leiding (24→21), voorheen WL (0→2), St.Gave

### (deel+leid) Capaciteit rij 31 — broncel per kamp
| Kamp | Formule in TOTAALOVERZICHT!rij31 | Waarde |
|------|----------------------------------|--------|
| KK1 | `='KK1'!D26` | 161 |
| KK2 | `='KK2'!D26` | 157 |
| BK1 | `='BK1'!H23` | 97 |
| BK2 | `='BK2'!H23` | 97 |
| TK1 | `='TK1'!C31` | 69 |
| TK2 | `='TK2'!C31` | 72 |
| JK1 | `='JK1'!C28` | 76 |
| JK2 | `='JK2'!C28` | 57 |
