# nl.onvergetelijk.partstatus

## Functionele beschrijving

De `partstatus`-extensie berekent en synchroniseert de deelnamestatus van elke kamper. Op basis van betalingen, leeftijd, schooltype, indicaties en handmatige oordelen bepaalt de module automatisch of een deelnemer de status "Bevestigd", "Wachtlijst", "Oordeel nodig" of "Geannuleerd" krijgt.

De module werkt in twee lagen: een "Brain" (`partstatus_consolidate`) die alle data verzamelt en de juiste status berekent, en een "Executor" (`partstatus_configure`) die het resultaat vergelijkt met wat al in de database staat en alleen de daadwerkelijk gewijzigde velden opslaat (Smart Guard). Zo worden onnodige API-calls vermeden.

Naast de status bewaart `partstatus` ook de criteria-uitkomsten (leeftijdsoordeel, schooloordeel, indicatie, eindoordeel), de wachtlijstdatums (wanneer op de wachtlijst gezet, wanneer eraf) en de criteria-checkdatums (wanneer begonnen met beoordelen, wanneer afgerond).

## Afhankelijkheden

- `nl.onvergetelijk.base`

---

## Technische documentatie

### Bestandsstructuur

| Bestand | Inhoud |
|---|---|
| `partstatus.php` | Hooks, field maps, `partstatus_civicrm_pre`, `partstatus_civicrm_post` |
| `partstatus.status.php` | Brain: `partstatus_consolidate` — verzamelt en berekent alles |
| `partstatus.helpers.php` | Executor: `partstatus_configure` — vergelijkt en slaat op |
| `partstatus.criteria.php` | Leeftijds- en schoolcriteriaberekening |
| `partstatus.wachtlijst.php` | Wachtlijstlogica: `partstatus_evaluate_wachtlijst` |
| `partstatus.leeftijd.php` | Leeftijdsberekening op drie peilmomenten |

### Kernfuncties

- `partstatus_get_field_map_participant()` — field map voor participant custom fields (PART_DEEL_INTERN)
- `partstatus_get_field_map_contact()` — field map voor contact custom fields (DITJAAR)
- `partstatus_civicrm_customPre($op, $groupID, $entityID, &$params)` — pre-hook: extraheert criteriumvelden uit het formulier vóór opslaan
- `partstatus_civicrm_pre($op, $objectName, $id, &$params)` — pre-hook: bewaakt de oude status in geheugen (RAM) voor vergelijking
- `partstatus_civicrm_post($op, $objectName, $objectId, &$objectRef)` — post-hook: triggert de activiteitslogger bij statuswijzigingen
- `partstatus_getset_old_status($action, $part_id, $status_id)` — lees/schrijf de vorige status in de static cache

### Brain: partstatus_consolidate
Centraliseert alle data: leeftijden op drie peilmomenten (vandaag, event, nextkamp), criteriaresultaten en wachtlijststatus. Retourneert een "Super Array" met alle berekende waarden.

### Executor: partstatus_configure
Vergelijkt de Super Array met de huidige DB-waarden. Alleen afwijkende waarden worden opgeslagen via `base_api_wrapper`. Schrijft ook naar de DITJAAR-velden op het contactrecord (alleen als het event dit jaar is — bescherming tegen verleden).

### Statussen
- `1` — Bevestigd
- `7` — Wachtlijst
- `8` — Oordeel nodig
- `4` — Geannuleerd
- `9` — Doorgestroomd (na afgerond oordeel)

### Hooks geïmplementeerd
- `civicrm_customPre`
- `civicrm_pre`
- `civicrm_post`
- `civicrm_config`, `civicrm_install`, `civicrm_enable`

---

## Google Sheets capaciteitsync (`partstatus.sheetsync.php`)

Deze module bevat ook de sync-logica voor de Google Sheet **CAPACITEIT[jaar]**
(tabblad TOTAALOVERZICHT). De sheet toont per kamp en geslacht de actuele
aantallen voor deelnemers, wachtlijst, goedkeuring, St.Gave en leiding.

### Sync-mechanisme — 3 lagen

#### Laag 1 — Dirty flag (per participant-wijziging)
`partstatus_civicrm_post` roept bij elke `create`, `edit` of `delete` van een
Participant `ozk_sheet_sync_set_dirty()` aan. Dit schrijft uitsluitend een
boolean naar de Civi-cache (< 1 ms). Er wordt op dit moment nog niets
gesynchroniseerd.

#### Laag 2 — Shutdown-handler (1× per request, automatisch)
`ozk_sheet_sync_set_dirty()` registreert bij de **eerste aanroep binnen een
request** eenmalig een PHP shutdown-handler via `register_shutdown_function()`.
Aan het einde van het request — nadat alle hooks zijn afgerond — checkt deze
handler de dirty flag en voert precies **1 volledige sync** uit.

> **Waarom shutdown en niet direct in de post-hook?**
> Bij een bulk-import van 100 deelnemers triggert `partstatus_civicrm_post`
> 100× de dirty flag, maar de shutdown-handler loopt slechts 1× en stuurt
> slechts 1 `batchUpdate` naar Google. Dit voorkomt een storm van API-calls
> en houdt de response-tijd voor de gebruiker acceptabel.

#### Laag 3 — Cron (periodieke dubbelcheck)
`/usr/local/bin/maintenance/sheet-sync-capaciteit.sh` draait via cron
(bijv. elk uur) met `$force = TRUE`. Dit vangt situaties op waarbij de
shutdown-handler niet liep (CLI-import, server-herstart) en garandeert dat
de sheet nooit langdurig verouderd blijft.

### Performance
| Stap | Aanpak | Aantal calls |
|---|---|---|
| Event-IDs ophalen | CiviCRM API3, gecached 1 uur | 10 (eenmalig) |
| Tellingen ophalen | Directe SQL met JOIN + GROUP BY | 5 queries |
| Sheet bijwerken | Google Sheets `batchUpdate` | 1 HTTP-call |

### Publieke functies

| Functie | Gebruik |
|---|---|
| `ozk_sheet_sync_set_dirty()` | Vanuit `civicrm_post` — zet dirty flag + registreert shutdown |
| `ozk_sheet_sync_on_shutdown()` | Automatisch via shutdown — synct als dirty flag gezet is |
| `ozk_sheet_sync_capaciteit(bool $force)` | Hoofd-sync; `$force=TRUE` negeert dirty flag (cron/handmatig) |
| `ozk_sheet_sync_get_counts()` | Geeft ruwe tellingen terug zonder te schrijven (verbose output) |

### Bestanden
```
partstatus.sheetsync.php                    sync-logica (dit bestand)
/usr/local/bin/maintenance/
  sheet-sync-capaciteit.sh                   bash-wrapper (cron + terminal)
  sheet-sync-capaciteit.php                  PHP-bootstrapper
/var/log/cron/sheet-sync-capaciteit.log      cron-log
```

### Credentials
- **Service account**: `ozkcivicrm@onvergetelijk-209212.iam.gserviceaccount.com`
- **Sleutelbestand**: `/home/webteam/.config/google/credentials_serviceaccount.json`
- **Sheet-toegang**: service account toegevoegd als Editor op de CAPACITEIT-sheet

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
