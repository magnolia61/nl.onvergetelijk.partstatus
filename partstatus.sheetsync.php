<?php
/**
 * Google Sheets capaciteitsync — TOTAALOVERZICHT (partstatus.sheet_sync.php)
 * ============================================================================
 *
 * DOEL
 *   Houdt de Google Sheet CAPACITEIT[jaar] actueel met de meest recente
 *   deelnemers-, wachtlijst-, goedkeurings-, St.Gave- en leidingaantallen
 *   per kamp en geslacht, direct vanuit CiviCRM.
 *
 * SYNC-MECHANISME  (3-lagen architectuur)
 * ─────────────────────────────────────────────────────────────────────────
 * Laag 1 — Dirty flag (per participant-wijziging, goedkoop)
 *   partstatus_civicrm_post() roept ozk_sheet_sync_set_dirty() aan bij
 *   elke create/edit/delete van een Participant. Dit schrijft alleen een
 *   boolean naar de Civi-cache (< 1 ms). Er wordt nog GEEN sync uitgevoerd.
 *
 * Laag 2 — Shutdown-handler (1× per request, automatisch)
 *   ozk_sheet_sync_set_dirty() registreert bij de EERSTE aanroep binnen een
 *   request eenmalig een PHP shutdown-handler via register_shutdown_function().
 *   Aan het einde van het request — nadat alle hooks zijn afgerond — checkt
 *   deze handler de dirty flag en voert precies 1 volledige sync uit.
 *
 *   Voordeel: bij een bulk-import van 100 deelnemers triggert partstatus_post
 *   100× de dirty flag, maar de shutdown-handler loopt slechts 1× en stuurt
 *   slechts 1 batchUpdate naar Google. Geen storm van API-calls.
 *
 * Laag 3 — Cron (periodieke dubbelcheck)
 *   /usr/local/bin/maintenance/sheet-sync-capaciteit.sh draait via cron
 *   (bijv. elk uur) en roept ozk_sheet_sync_capaciteit(TRUE) aan met $force.
 *   Dit vangt gevallen op waarbij de shutdown-handler niet kon draaien
 *   (CLI-context, CLI-import, server-herstart) en garandeert dat de sheet
 *   nooit langdurig verouderd blijft.
 *
 * AANROEPSCHEMA
 *   ozk_sheet_sync_set_dirty()          → vanuit partstatus_civicrm_post
 *   ozk_sheet_sync_on_shutdown()        → automatisch via register_shutdown_function
 *   ozk_sheet_sync_capaciteit($force)   → vanuit cron of handmatig
 *   ozk_sheet_sync_get_counts()         → voor verbose terminal-output
 *
 * PERFORMANCE
 *   - Event-IDs : 10 API-calls, resultaat gecached 1 uur in Civi-cache
 *   - Tellingen : 5 directe SQL-queries met JOIN (geen PHP array_intersect)
 *   - Sheet      : 1 batchUpdate — alle 11 ranges in één HTTP-call
 *
 * BESTANDEN
 *   partstatus.sheet_sync.php           dit bestand (logica)
 *   /usr/local/bin/maintenance/
 *     sheet-sync-capaciteit.sh          bash-wrapper voor cron/terminal
 *     sheet-sync-capaciteit.php         PHP-bootstrapper, roept dit bestand aan
 *   /var/log/cron/sheet-sync-capaciteit.log  cron-log
 *
 * CREDENTIALS
 *   Service account : ozkcivicrm@onvergetelijk-209212.iam.gserviceaccount.com
 *   Sleutelbestand  : /home/webteam/.config/google/credentials_serviceaccount.json
 *   Sheet           : CAPACITEIT[jaar] — service account toegevoegd als Editor
 */

// ── CONSTANTEN ────────────────────────────────────────────────────────────────

define('OZK_SHEET_ID',        '1NyGIPjdxUOA82Xt0dzCrJwJRA8YzzVZrE3KEGCpBetI');
define('OZK_SHEET_TAB',       'TOTAALOVERZICHT');
define('OZK_SHEET_GAPI_CREDS','/home/webteam/.config/google/credentials_serviceaccount.json');
define('OZK_SHEET_VENDOR',    '/var/www/vhosts/ozkprod/web/bin/vendor/autoload.php');
define('OZK_SHEET_DIRTY_KEY', 'ozk_sheet_capaciteit_dirty');
define('OZK_SHEET_EID_KEY',   'ozk_sheet_event_ids');

// Geslacht (CiviCRM option group: gender)
define('OZK_GENDER_JONGEN', 2);
define('OZK_GENDER_MEISJE', 1);

// Participant status IDs
define('OZK_STATUS_DEEL',    '1,5,6,15');    // Registered, Pf pay later, Pf incomplete, Partially paid
define('OZK_STATUS_WACHT',   '7');            // On waitlist
define('OZK_STATUS_APPR',    '9,10');         // Pending from waitlist, Pending from approval
define('OZK_STATUS_BREED',   '1,5,6,7,9,15'); // Breed: voor St.Gave en Leiding

// event_type_id per kamp — stabiel, niet jaarsgebonden (zie get_event_types() in base)
define('OZK_KAMP_TYPE_MAP', serialize([
    'KK1' => 11, 'KK2' => 21,
    'BK1' => 12, 'BK2' => 22,
    'TK1' => 13, 'TK2' => 23,
    'JK1' => 14, 'JK2' => 24,
    'TOP' => 33,
    'LEID'=> 1,
]));

// Kolomvolgorde in sheet (kolommen B t/m J — J = TOP, verborgen kolom)
define('OZK_KAMP_VOLGORDE', serialize(['KK1','KK2','BK1','BK2','TK1','TK2','JK1','JK2','TOP']));


// ── PUBLIEKE API ──────────────────────────────────────────────────────────────

/**
 * Zet de dirty-vlag en registreert eenmalig een shutdown-handler.
 * De handler synct de sheet aan het einde van het huidige request —
 * ook als er meerdere participant-wijzigingen in één request zijn.
 */
function ozk_sheet_sync_set_dirty(): void {
    Civi::cache()->set(OZK_SHEET_DIRTY_KEY, TRUE, 86400);

    static $shutdown_registered = FALSE;
    if (!$shutdown_registered) {
        register_shutdown_function('ozk_sheet_sync_on_shutdown');
        $shutdown_registered = TRUE;
    }
}

/**
 * Shutdown-handler: synct de sheet als dirty flag gezet is.
 * Maximaal 1 Google API-call per request, ongeacht hoeveel wijzigingen.
 */
function ozk_sheet_sync_on_shutdown(): void {
    try {
        ozk_sheet_sync_capaciteit();
    } catch (Throwable $e) {
        CRM_Core_Error::debug_log_message('ozk_sheet_sync shutdown error: ' . $e->getMessage());
    }
}

/**
 * Hoofd-synchronisatiefunctie. Aanroepen vanuit CiviCRM Scheduled Job of handmatig.
 *
 * @param bool $force  TRUE = altijd uitvoeren, dirty flag negeren (bulk/handmatige trigger).
 *                     FALSE (default) = alleen uitvoeren als dirty flag gezet is.
 *
 * @return string  Status-bericht (verschijnt in job-log of API-response).
 */
function ozk_sheet_sync_capaciteit(bool $force = FALSE): string {
    if (!$force && !Civi::cache()->get(OZK_SHEET_DIRTY_KEY)) {
        return 'Geen wijzigingen — sync overgeslagen.';
    }

    $eids = _ozk_sheet_get_event_ids();
    if (empty($eids['deel'])) {
        return 'Geen kamp-events gevonden voor dit fiscaal jaar — sync afgebroken.';
    }

    $data = _ozk_sheet_fetch_counts($eids);
    _ozk_sheet_write($data);

    Civi::cache()->delete(OZK_SHEET_DIRTY_KEY);
    $via = $force ? ' (geforceerd)' : '';
    return 'Sheet bijgewerkt op ' . date('Y-m-d H:i:s') . $via;
}


/**
 * Geeft de ruwe tellingen terug zonder naar de sheet te schrijven.
 * Handig voor verbose output of debugging.
 *
 * @return array ['eids' => [...], 'data' => ['KK1' => ['deel_j'=>46, ...], ...]]
 */
function ozk_sheet_sync_get_counts(): array {
    $eids = _ozk_sheet_get_event_ids();
    $data = _ozk_sheet_fetch_counts($eids);
    return ['eids' => $eids, 'data' => $data];
}

// ── INTERN: EVENT-IDS ─────────────────────────────────────────────────────────

/**
 * Haalt event-IDs op voor het huidige fiscaal jaar (1 dec – 30 nov).
 * Resultaat wordt 1 uur gecached — events wijzigen zelden.
 *
 * @return array [
 *   'deel'        => ['KK1' => 298, 'KK2' => 299, ...],
 *   'leid'        => 307,
 *   'type_to_kamp'=> [11 => 'KK1', 21 => 'KK2', ...],
 * ]
 */
function _ozk_sheet_get_event_ids(): array {
    $cached = Civi::cache()->get(OZK_SHEET_EID_KEY);
    if ($cached) return $cached;

    $now      = new DateTime();
    $fy_start = (((int)$now->format('n') >= 12) ? (int)$now->format('Y') : (int)$now->format('Y') - 1) . '-12-01';
    $type_map = unserialize(OZK_KAMP_TYPE_MAP);

    $eids = ['deel' => [], 'leid' => 0, 'type_to_kamp' => []];

    foreach ($type_map as $kamp => $type_id) {
        $res = civicrm_api3('Event', 'get', [
            'sequential'    => 1,
            'return'        => 'id',
            'event_type_id' => $type_id,
            'start_date'    => ['>=' => $fy_start],
            'title'         => ['NOT LIKE' => '%TEST%'],
            'options'       => ['limit' => 1, 'sort' => 'start_date ASC'],
        ]);
        if (empty($res['values'])) continue;

        $eid = (int)$res['values'][0]['id'];
        if ($kamp === 'LEID') {
            $eids['leid'] = $eid;
        } else {
            $eids['deel'][$kamp]              = $eid;
            $eids['type_to_kamp'][$type_id]   = $kamp;
        }
    }

    Civi::cache()->set(OZK_SHEET_EID_KEY, $eids, 3600);
    return $eids;
}


// ── INTERN: TELLINGEN ─────────────────────────────────────────────────────────

/**
 * Haalt alle tellingen op in 5 SQL queries.
 * Elke query JOINt participant + contact + event en groepeert op event_type_id + gender_id.
 *
 * @return array ['KK1' => ['deel_j'=>46,'deel_m'=>44,'wait_j'=>0,...], ...]
 */
function _ozk_sheet_fetch_counts(array $eids): array {
    $volgorde     = unserialize(OZK_KAMP_VOLGORDE);
    $type_to_kamp = $eids['type_to_kamp'];
    $deel_ids     = array_filter(array_values($eids['deel']));
    $leid_eid     = (int)$eids['leid'];

    // Init result-matrix
    $result = [];
    $slots  = ['cap_j','cap_m','max_j','max_m','deel_j','deel_m','wait_j','wait_m','appr_j','appr_m','gave_j','gave_m','leid_j','leid_m'];
    foreach ($volgorde as $kamp) {
        $result[$kamp] = array_fill_keys($slots, 0);
    }
    if (empty($deel_ids)) return $result;

    $ids_sql  = implode(',', $deel_ids);
    $gj       = OZK_GENDER_JONGEN;
    $gm       = OZK_GENDER_MEISJE;

    // Queries 1-4: deelnemers, wachtlijst, goedkeuring, St.Gave
    $queries = [
        'deel' => "SELECT e.event_type_id, c.gender_id, COUNT(*) n
                   FROM civicrm_participant p
                   JOIN civicrm_contact c ON c.id = p.contact_id
                     AND c.is_deleted = 0 AND c.gender_id IN ($gj,$gm)
                   JOIN civicrm_event e ON e.id = p.event_id
                   WHERE p.event_id IN ($ids_sql)
                     AND p.status_id IN (" . OZK_STATUS_DEEL . ")
                     AND p.is_test = 0
                   GROUP BY e.event_type_id, c.gender_id",

        'wait' => "SELECT e.event_type_id, c.gender_id, COUNT(*) n
                   FROM civicrm_participant p
                   JOIN civicrm_contact c ON c.id = p.contact_id
                     AND c.is_deleted = 0 AND c.gender_id IN ($gj,$gm)
                   JOIN civicrm_event e ON e.id = p.event_id
                   WHERE p.event_id IN ($ids_sql)
                     AND p.status_id IN (" . OZK_STATUS_WACHT . ")
                     AND p.is_test = 0
                   GROUP BY e.event_type_id, c.gender_id",

        'appr' => "SELECT e.event_type_id, c.gender_id, COUNT(*) n
                   FROM civicrm_participant p
                   JOIN civicrm_contact c ON c.id = p.contact_id
                     AND c.is_deleted = 0 AND c.gender_id IN ($gj,$gm)
                   JOIN civicrm_event e ON e.id = p.event_id
                   WHERE p.event_id IN ($ids_sql)
                     AND p.status_id IN (" . OZK_STATUS_APPR . ")
                     AND p.is_test = 0
                   GROUP BY e.event_type_id, c.gender_id",

        'gave' => "SELECT e.event_type_id, c.gender_id, COUNT(*) n
                   FROM civicrm_participant p
                   JOIN civicrm_contact c ON c.id = p.contact_id
                     AND c.is_deleted = 0 AND c.gender_id IN ($gj,$gm)
                   JOIN civicrm_event e ON e.id = p.event_id
                   WHERE p.event_id IN ($ids_sql)
                     AND p.status_id IN (" . OZK_STATUS_BREED . ")
                     AND p.fee_level LIKE '%St.Gave%'
                     AND p.is_test = 0
                   GROUP BY e.event_type_id, c.gender_id",
    ];

    foreach ($queries as $cat => $sql) {
        $dao = CRM_Core_DAO::executeQuery($sql);
        while ($dao->fetch()) {
            $kamp = $type_to_kamp[(int)$dao->event_type_id] ?? null;
            if (!$kamp) continue;
            $g = ($dao->gender_id == $gj) ? 'j' : 'm';
            $result[$kamp]["{$cat}_{$g}"] = (int)$dao->n;
        }
        $dao->free();
    }

    // Query 5: capaciteit jongens/meisjes — uit event custom fields (civicrm_value_event_kenmerk_211)
    $sql = "SELECT e.event_type_id,
                   v.capaciteit_jongens_2225 AS cap_j,
                   v.capaciteit_meisjes_2227 AS cap_m,
                   v.max_jongens_2228        AS max_j,
                   v.max_meisjes_2229        AS max_m
            FROM civicrm_event e
            JOIN civicrm_value_event_kenmerk_211 v ON v.entity_id = e.id
            WHERE e.id IN ($ids_sql)";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
        $kamp = $type_to_kamp[(int)$dao->event_type_id] ?? null;
        if (!$kamp) continue;
        $result[$kamp]['cap_j'] = (int)$dao->cap_j;
        $result[$kamp]['cap_m'] = (int)$dao->cap_m;
        $result[$kamp]['max_j'] = (int)$dao->max_j;
        $result[$kamp]['max_m'] = (int)$dao->max_m;
    }
    $dao->free();

    // Query 6: leiding — gefilterd op part_kamptype_id_961 (event_type_id van het kamp)
    if ($leid_eid && !empty($type_to_kamp)) {
        $type_ids_sql = implode(',', array_keys($type_to_kamp));
        $sql = "SELECT v.part_kamptype_id_961 AS type_id, c.gender_id, COUNT(*) n
                FROM civicrm_participant p
                JOIN civicrm_contact c ON c.id = p.contact_id
                  AND c.is_deleted = 0 AND c.gender_id IN ($gj,$gm)
                JOIN civicrm_value_part_118 v ON v.entity_id = p.id
                WHERE p.event_id = $leid_eid
                  AND p.status_id IN (" . OZK_STATUS_BREED . ")
                  AND p.is_test = 0
                  AND v.part_kamptype_id_961 IN ($type_ids_sql)
                GROUP BY v.part_kamptype_id_961, c.gender_id";
        $dao = CRM_Core_DAO::executeQuery($sql);
        while ($dao->fetch()) {
            $kamp = $type_to_kamp[(int)$dao->type_id] ?? null;
            if (!$kamp) continue;
            $g = ($dao->gender_id == $gj) ? 'j' : 'm';
            $result[$kamp]["leid_{$g}"] = (int)$dao->n;
        }
        $dao->free();
    }

    return $result;
}


// ── INTERN: GOOGLE SHEETS SCHRIJVEN ──────────────────────────────────────────

/**
 * Schrijft alle tellingen in één batchUpdate naar Google Sheets.
 */
function _ozk_sheet_write(array $data): void {
    if (!file_exists(OZK_SHEET_VENDOR)) {
        CRM_Core_Error::debug_log_message('ozk_sheet_sync: vendor/autoload.php niet gevonden — sync afgebroken.');
        return;
    }
    require_once OZK_SHEET_VENDOR;

    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . OZK_SHEET_GAPI_CREDS);
    $client = new Google_Client();
    $client->useApplicationDefaultCredentials();
    $client->setApplicationName('onvergetelijk-209212');
    $client->setScopes(['https://www.googleapis.com/auth/drive', 'https://spreadsheets.google.com/feeds', 'https://www.googleapis.com/auth/spreadsheets']);
    $client->fetchAccessTokenWithAssertion();

    $service  = new Google_Service_Sheets($client);
    $volgorde = unserialize(OZK_KAMP_VOLGORDE);
    $tab      = OZK_SHEET_TAB;

    // Bouw rijen op in kamp-volgorde
    $rows = array_fill_keys(
        ['cap_j','cap_m','max_j','max_m','deel_j','deel_m','wait_j','wait_m','appr_j','appr_m','gave_j','gave_m','leid_j','leid_m'],
        []
    );
    foreach ($volgorde as $kamp) {
        $d = $data[$kamp] ?? [];
        foreach (array_keys($rows) as $slot) {
            $rows[$slot][] = $d[$slot] ?? 0;
        }
    }

    $range_map = [
        'deel_j' => "$tab!B2:J2",   'deel_m' => "$tab!B3:J3",
        'wait_j' => "$tab!B7:J7",   'wait_m' => "$tab!B8:J8",
        'appr_j' => "$tab!B9:J9",   'appr_m' => "$tab!B10:J10",
        'gave_j' => "$tab!B11:J11", 'gave_m' => "$tab!B12:J12",
        'cap_j'  => "$tab!B13:J13", 'cap_m'  => "$tab!B14:J14",
        'max_j'  => "$tab!B15:J15", 'max_m'  => "$tab!B16:J16",
        'leid_j' => "$tab!B20:J20", 'leid_m' => "$tab!B21:J21",
    ];

    $value_ranges = [];
    foreach ($range_map as $slot => $range) {
        $value_ranges[] = new Google_Service_Sheets_ValueRange([
            'range'  => $range,
            'values' => [$rows[$slot]],
        ]);
    }
    $value_ranges[] = new Google_Service_Sheets_ValueRange([
        'range'  => "$tab!B33",
        'values' => [[date('Y-m-d H:i:s')]],
    ]);

    $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
        'valueInputOption' => 'RAW',
        'data'             => $value_ranges,
    ]);
    $service->spreadsheets_values->batchUpdate(OZK_SHEET_ID, $body);
}
