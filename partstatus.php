<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.php
 * =======================================================================================
 *   partstatus_get_field_map_participant()  Field Map voor de Participant entiteit (Custom Group: PART_DEEL_INT...
 *   partstatus_get_field_map_contact()      Field Map voor de Contact entiteit (Custom Group: DITJAAR)
 *   partstatus_civicrm_customPre()          FUNCTIONEEL (Het 'Waarom'):
 *   partstatus_getset_old_status()          Helper functie om de oude status tijdelijk in het RAM te bewaren
 *   partstatus_civicrm_pre()                HOOK: PRE (Vóór het opslaan in de database)
 *   partstatus_civicrm_post()               HOOK: POST (Nadat de database is bijgewerkt)
 *   partstatus_civicrm_config()             STANDAARD CIVICRM BOILERPLATE
 *   partstatus_civicrm_install()
 *   partstatus_civicrm_enable()
 * =======================================================================================
 */

/*

RELEVANTE CIVIRULES
- wachtlijst        > voorheen wachtlijst
- oordeel nodig     > geregistreerd


*/


/**
 * MODULE: PARTSTATUS (De Hoofdmodule / Hooks)
 * FUNCTIONEEL: Centrale autoriteit voor Participant Status Synchronisatie.
 * TECHNISCHE OPTIMALISATIES:
 * - Event-Driven: Statussen volgen datums en wijzigingen via CiviCRM Hooks.
 * - Static Caching: Voorkomt dubbele berekeningen in de helpers (CPU winst).
 * - Smart Guards: Voorkomt oneindige API update loops (DB winst).
 */

require_once 'partstatus.civix.php';

// Laad alle functionele componenten (specialisten) in het geheugen
require_once 'partstatus.sheetsync.php';
require_once 'partstatus.helpers.php';
require_once 'partstatus.leeftijd.php';
require_once 'partstatus.criteria.php';
require_once 'partstatus.wachtlijst.php';
require_once 'partstatus.status.php';
require_once 'partstatus.activities.php';
require_once 'partstatus.links.php';

/**
 * =======================================================================================
 * FIELD MAPS: De "Single Source of Truth" voor database-kolommen
 * =======================================================================================
 */

/**
 * Field Map voor de Participant entiteit (Custom Group: PART_DEEL_INTERN)
 * @return array ['db_kolom_ID' => 'API_naam']
 */
function partstatus_get_field_map_participant(): array {
    return [
        'criteria_leeftijd_1148'        => 'PART_DEEL_INTERN.criteria_leeftijd',
        'criteria_school_1149'          => 'PART_DEEL_INTERN.criteria_school',
        'criteria_indicatie_1428'       => 'PART_DEEL_INTERN.criteria_indicatie',
        'criteria_beoordeling_1429'     => 'PART_DEEL_INTERN.criteria_oordeel',
        'criteriacheck_start_2091'      => 'PART_DEEL_INTERN.criteriacheck_start',
        'criteriacheck_einde_2092'      => 'PART_DEEL_INTERN.criteriacheck_einde',
        'wachtlijst_erop_2093'          => 'PART_DEEL_INTERN.wachtlijst_erop',
        'wachtlijst_eraf_2094'          => 'PART_DEEL_INTERN.wachtlijst_eraf',
        'groep_klas_593'                => 'PART_DEEL.Groep_klas',
        // Schrijf de WAARDE (1..5), niet het label: de motor levert nu de gemapte optie-waarde
        // via partstatus_deelnamestatus_from_status(). Label-matching gaf voorheen NULL voor 8/9/33.
        'deelnamestatus_1663'           => 'PART.deelnamestatus',
    ];
}

/**
 * Field Map voor de Contact entiteit (Custom Group: DITJAAR)
 * @return array ['db_kolom_ID' => 'API_naam']
 */
function partstatus_get_field_map_contact(): array {
    return [
        'ditjaar_deelnamestatus_1887'       => 'DITJAAR.ditjaar_deelnamestatus',
        'ditjaar_leeftijd_1263'             => 'DITJAAR.ditjaar_leeftijd',
        'ditjaar_school_1264'               => 'DITJAAR.ditjaar_school',
        'ditjaar_criteria_indicatie_2082'   => 'DITJAAR.ditjaar_criteria_indicatie',
        'ditjaar_criteria_oordeel_2083'     => 'DITJAAR.ditjaar_criteria_oordeel',
        'ditjaar_wachtlijst_erop_2084'      => 'DITJAAR.ditjaar_wachtlijst_erop',
        'ditjaar_wachtlijst_eraf_2085'      => 'DITJAAR.ditjaar_wachtlijst_eraf',
        'ditjaar_criteriacheck_start_2232'  => 'DITJAAR.ditjaar_criteriacheck_start',
        'ditjaar_criteriacheck_einde_2233'  => 'DITJAAR.ditjaar_criteriacheck_einde',
        'ditjaar_groep_klas_1051'           => 'DITJAAR.ditjaar_groep_klas',
    ];
}

/**
 * LET OP — WAT DEZE HOOK WEL/NIET DOET:
 * `hook_civicrm_customPre` IS een echte CiviCRM-hook (CRM/Utils/Hook.php ~r577, aangeroepen
 * vanuit CRM_Core_BAO_CustomValueTable) en vuurt VÓÓR de commit voor het participant-pad
 * (groep 271). Deze functie draait dus wél — hij doet de "shield" (leeg-formulier-bescherming)
 * en de datum-opschoning. Wat hij NIET doet: de status-/wachtlijst-motor aanjagen. Het zetten
 * van een procesdatum (wachtlijst_eraf / criteriacheck_einde) werd hierdoor niet direct
 * verwerkt. Die betrouwbare trigger zit in `partstatus_civicrm_custom()` (post-commit) verderop.
 * (NB: de intake-extensie meldt dat háár customPre nooit vuurt — dat geldt voor het webform-pad
 *  naar Contact-groep 181, dat CustomValueTable::customPre niet raakt; hier op groep 271 wél.)
 *
 * MODULE: PARTSTATUS (Pre-Storage Validatie & Race-Condition Schild)
 * * FUNCTIONEEL (Het 'Waarom'):
 * Deze module is de poortwachter tussen het formulier op het scherm en de database.
 * Hij doet twee dingen:
 * 1. Bescherming: Voorkomt dat een leeg formulierveld het rekenwerk van onze automatische
 * motor overschrijft (de motor werkt immers razendsnel op de achtergrond).
 * 2. Opschoning: Zorgt dat er geen onlogische data in het systeem komt (bijv. wel
 * procesdatums hebben, maar geen oordeel nodig hebben).
 * * TECHNISCH (Het 'Hoe'):
 * Grijpt in via de CiviCRM `customPre` hook. Deze hook vuurt nádat de gebruiker op
 * opslaan klikt, maar vóórdat CiviCRM de SQL-queries uitvoert. We manipuleren hier
 * de interne `$params` array om de uiteindelijke database-schrijfopdracht te sturen.
 * * @param string  $op        De operatie (bijv. 'create', 'edit')
 * @param int     $groupID   Het ID van de Custom Group
 * @param int     $entityID  Het ID van de entiteit (Participant)
 * @param array   $params    De inkomende data die opgeslagen gaat worden (manipuleerbaar)
 */
function partstatus_civicrm_customPre($op, $groupID, $entityID, &$params) {

    $extdebug = 'partstatus.custompre'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    
    // FILTER: Alleen draaien voor profiel 'part deel intern' (Groep ID 271) bij aanmaken of bewerken
    if (($op != 'create' && $op != 'edit') || $groupID != 271) return;

    // GLOBAL EVENT FILTER: Bepaal het event type om irrelevante events te blokkeren
    $part = civicrm_api4('Participant', 'get', [
        'select'            => ['event_id.event_type_id'],
        'where'             => [['id', '=', $entityID]],
        'checkPermissions'  => FALSE
    ])->first();
    
    if (!in_array($part['event_id.event_type_id'] ?? 0, [11, 12, 13, 14, 21, 22, 23, 24, 33, 101, 102, 103])) {
        return; // Stop: Dit event valt buiten de scope van de Partstatus module
    }

    $partstatus_custompre_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START partstatus_custompre [GID: $groupID / EID: $entityID]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 2, "### PARTSTATUS PRE - DEEL_INTERN EXTRACTIE",                    "[START]");
    wachthond($extdebug, 3, "########################################################################");

    /*
     * =======================================================================================
     * DEEL 1: DE RACE-CONDITION FIX (HET SCHILD)
     * =======================================================================================
     * PROBLEEM (Simpel):  Als de beheerder opslaat, gaat onze motor direct rekenen via de POST-hook
     * en slaat bijv. "Prima" op. Een milliseconde later gooit CiviCRM de 
     * originele, lege dropdowns van het formulier óók in de database. 
     * Resultaat: "Prima" wordt direct weer vernietigd door "NULL".
     * * OPLOSSING (Tech):   We filteren de $params array. Als een door de motor beheerd veld
     * leeg is of op '- selecteer -' staat, verwijderen (unset) we het uit 
     * de array. Hierdoor weigert CiviCRM dat specifieke veld te updaten, 
     * waardoor de APIv4 actie vanuit de motor veilig in de DB blijft staan.
     */
    $protected_fields = [1148, 1149, 1428, 1429];
    
    foreach ($params as $k => $v) {
        if (is_array($v) && isset($v['custom_field_id']) && in_array($v['custom_field_id'], $protected_fields)) {
            
            // Check robuust of de waarde leeg is (null, lege string, of 'onbekend')
            if (!isset($v['value']) || $v['value'] === '' || $v['value'] === null || $v['value'] === '- selecteer -') {
                unset($params[$k]);
                wachthond($extdebug, 3, "Formulier overwrite GEBLOKKEERD voor veld", "custom_" . $v['custom_field_id']);
            }
        }
    }

    /*
     * =======================================================================================
     * DEEL 2: BEWAKING DATUMINTEGRITEIT (DE OPSCHONING)
     * =======================================================================================
     * PROBLEEM (Simpel):  Een beheerder zet het oordeel handmatig op "Niet nodig", maar 
     * vergeet de procesdatums (start/einde) leeg te maken.
     * * OPLOSSING (Tech):   We lezen de gekozen dropdown-waarde uit de $params. Is deze
     * 'oordeelnietnodig'? Dan zoeken we de datumvelden op in de array 
     * en forceren we de waarde naar een lege string (""). CiviCRM 
     * schrijft vervolgens de databasevelden netjes leeg.
     */

    // Lokale map om de inkomende 'custom_XX' velden leesbaar te maken
    $name_map = [
        'custom_1148' => 'criteria_leeftijd',
        'custom_1429' => 'criteria_oordeel',
        'custom_2091' => 'criteriacheck_start',
        'custom_2092' => 'criteriacheck_einde',
    ];

    // Haal de waarden uit de formulier-verzending via de base-helper
    $extracted      = base_extract_from_params($params, $name_map);

    $in_oordeel     = $extracted['criteria_oordeel']        ?? NULL;
    $in_check_start = $extracted['criteriacheck_start']     ?? NULL;
    $in_check_einde = $extracted['criteriacheck_einde']     ?? NULL;

    wachthond($extdebug, 3, "Formulierdata geëxtraheerd, beoordeel datum-interventie",      "[EXTRACT]");

    // INTERVENTIE: Bij oordeel 'niet nodig' de actieve procesdatums opschonen
    if ($in_oordeel == 'oordeelnietnodig') {
        wachthond($extdebug, 4, "Oordeel is 'Niet nodig'. Opschonen actieve check-datums",  "[CLEANUP]");
        
        // Gebruik base_inject_params om de waarden in de hook-params te overschrijven
        base_inject_params($params, ['criteria_oordeel' => $in_oordeel], ['criteria_oordeel' => 1429], $entityID, "PRE_CLEANUP", $extdebug);
        
        // Specifieke datums op leeg zetten in de params array
        foreach ($params as $key => $val) {
            if (is_array($val) && in_array($val['custom_field_id'], [2091, 2092])) {
                $params[$key]['value'] = ""; 
            }
        }

    } else {
        wachthond($extdebug, 4, "Geen handmatige datum-interventie vereist in de pre-hook", "[SKIP]");
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 2, "### PARTSTATUS PRE - DEEL_INTERN EXTRACTIE",                    "[EINDE]");
    wachthond($extdebug, 3, "########################################################################");

    $total_partstatus_custompre_duur = number_format(microtime(TRUE) - $partstatus_custompre_start, 3);
    watchdog('civicrm_timing', base_microtimer("EINDE partstatus_custompre"), NULL, WATCHDOG_DEBUG);
}

/**
 * =======================================================================================
 * ACTIVITY LOGGER HOOKS (PRE & POST)
 * =======================================================================================
 */

/**
 * Helper functie om de oude status tijdelijk in het RAM te bewaren
 * tussen de PRE en POST hook executie.
 */
function partstatus_getset_old_status($action, $part_id = NULL, $status_id = NULL) {
    static $old_statuses = [];
    
    if ($action == 'set') {
        $old_statuses[$part_id] = $status_id;
    } elseif ($action == 'get') {
        return $old_statuses[$part_id] ?? NULL;
    } elseif ($action == 'clear') {
        unset($old_statuses[$part_id]);
    }
}

/**
 * HOOK: PRE (Vóór het opslaan in de database)
 * FUNCTIONEEL: Functioneert als de 'uitsmijter' en 'notulist' van de database.
 * 1. Beschermt statussen tegen ongewenste overschrijvingen door externe systemen (Mollie).
 * 2. Onthoudt de originele status voor logica die pas ná het opslaan mag draaien.
 */
function partstatus_civicrm_pre($op, $objectName, $id, &$params) {

    $extdebug = 'partstatus.pre'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    // =======================================================================================
    // FILTER: Sluiswachter - Alleen doorlaten als het een Participant status-update is
    // =======================================================================================
    if ($objectName != 'Participant' || $op != 'edit' || empty($id) || !isset($params['status_id'])) {
        return; 
    }

    $partstatus_pre_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START partstatus_pre [PID: $id]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS PRE 1.0 - DATA VERZAMELEN",                      "[START]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 1.0:
     * We moeten weten wat de huidige staat in de database is, voordat de nieuwe
     * $params deze overschrijven. We halen de status en het oordeel op.
     */

    $params_part_get = [
        'checkPermissions'  => FALSE,
        'select'            => ['status_id', 'PART_DEEL_INTERN.criteria_oordeel', 'event_id.event_type_id'], // AANGEPAST
        'where'             => [['id', '=', $id]],
    ];
    $result_part = civicrm_api4('Participant', 'get', $params_part_get)->first();

    // GLOBAL EVENT FILTER
    if (!in_array($result_part['event_id.event_type_id'] ?? 0, [11, 12, 13, 14, 21, 22, 23, 24, 33, 101, 102, 103])) {
        return; // Stop: Geen bewerkingen toegestaan voor dit event type
    }

    // BUGFIX: $result_part is al één rij (via ->first() op regel hierboven), géén result-set.
    // De index [0] bestond dus niet → $old_status_id/$huidig_oordeel waren ALTIJD NULL, waardoor
    // de statuswijziging nooit werd onthouden en de post-hook (activity-log + sync) nooit draaide
    // bij een handmatige status-edit. Regel 'event_id.event_type_id' hierboven las al correct
    // zónder [0]; deze twee zijn nu gelijkgetrokken.
    $old_status_id  = $result_part['status_id'] ?? NULL;
    $huidig_oordeel = $result_part['PART_DEEL_INTERN.criteria_oordeel'] ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS PRE 2.0 - STATUS BEWAKING (CIVIRULE)",           "[CHECK]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 2.0 (MIGRATIE CIVIRULE):
     * Als de beheerder nog geen oordeel heeft geveld (status 8), mogen 
     * automatische betalingssystemen de status NIET doordrukken naar Geregistreerd (1).
     * We blokkeren de wijziging door de $params te overschrijven.
     */
    if ($old_status_id == 8 && in_array($params['status_id'], [1, 5, 6, 15, 16])) {
        
        if (!in_array($huidig_oordeel, ['oordeelprima', 'oordeelnietnodig'])) {
            wachthond($extdebug, 1, "CiviRule: Statuswijziging geblokkeerd. Oordeel ontbreekt.", "[BLOCKED]");
            
            // Forceer de inkomende wijziging veilig terug naar 8
            $params['status_id'] = 8; 
        }
    }


    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS PRE 3.0 - STATUS GEHEUGEN (RAM)",                "[STORE]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 3.0:
     * Als de status daadwerkelijk verandert (na onze eventuele blokkades uit 2.0), 
     * slaan we de OUDE status tijdelijk op in het RAM. De POST-hook (die later vuurt) 
     * gebruikt dit om te bepalen of er mail- of statustriggers nodig zijn.
     */
    if ($old_status_id && $old_status_id != $params['status_id']) {
        wachthond($extdebug, 3, "Statuswijziging gedetecteerd. Oude status ($old_status_id) bewaard.", "[RAM]");
        
        // Sla op in de statische variabele
        partstatus_getset_old_status('set', $id, $old_status_id);
        
    } else {
        wachthond($extdebug, 4, "Geen (toegestane) wijziging in status_id gedetecteerd.",          "[SKIP]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS PRE - VOLTOOID",                                 "[EINDE]");
    wachthond($extdebug, 2, "########################################################################");

    $total_partstatus_pre_duur = number_format(microtime(TRUE) - $partstatus_pre_start, 3);
    watchdog('civicrm_timing', base_microtimer("EINDE partstatus_pre"), NULL, WATCHDOG_DEBUG);
}

/**
 * HOOK: POST (Nadat de database is bijgewerkt)
 * Doel: Haalt de oude status uit het RAM en triggert de Activity Logger.
 */
function partstatus_civicrm_post($op, $objectName, $objectId, &$objectRef) {

    $extdebug = 'partstatus.post'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    // Sheet-sync dirty flag: zet bij elke participant-mutatie (create/edit/delete)
    if ($objectName == 'Participant' && in_array($op, ['create', 'edit', 'delete'])) {
        ozk_sheet_sync_set_dirty();
    }

    if ($objectName == 'Participant' && $op == 'edit') {
        
        // Vraag op of we in de PRE hook een oude status hadden klaargezet voor dit ID
        $old_status_id = partstatus_getset_old_status('get', $objectId);

        if ($old_status_id) {

            $partstatus_post_start = microtime(TRUE);
            watchdog('civicrm_timing', base_microtimer("START partstatus_post [PID: $objectId]"), NULL, WATCHDOG_DEBUG);

            wachthond($extdebug, 2, "########################################################################");
            wachthond($extdebug, 1, "### PARTSTATUS POST-HOOK - TRIGGER ACTIVITY LOGGER",             "[EXEC]");
            wachthond($extdebug, 2, "########################################################################");
            
            $new_status_id  = $objectRef->status_id;
            $contact_id     = $objectRef->contact_id;

            wachthond($extdebug, 3, "Status gewijzigd van $old_status_id naar $new_status_id. Sync-motor draaien...", "[SYNC]");

            /*
             * VOLLEDIGE SYNC bij een (handmatige) statuswijziging.
             * WAAROM: Een status-edit via het scherm (bv. secretariaat verplaatst iemand van de
             * wachtlijst) draait NIET de core-registratiecascade. Voorheen berekende deze hook
             * alleen een read-only context voor de activity-log, waardoor de spiegelvelden
             * PART.deelnamestatus én DITJAAR.ditjaar_deelnamestatus achterbleven op hun oude waarde
             * (zie casus Rowan Buijl: core-status 1/Geregistreerd, maar deelnamestatus nog 2/Wachtlijst).
             * We roepen nu partstatus_configure() aan: die herberekent de status, stempelt de
             * wachtlijst-datums (incl. de nieuwe eraf-datum bij uitstroom) en schrijft beide
             * deelnamestatus-spiegels weg naar wat het volgens de motor MOET zijn.
             * De re-entrancy-lock in partstatus_configure() vangt de nested hook-aanroep af die
             * ontstaat doordat de sync zelf de Participant bijwerkt (die valt dan terug op read-only).
             */
            $ctx = partstatus_configure($objectId);

            if ($ctx && function_exists('partstatus_log_activity')) {
                partstatus_log_activity($objectId, $contact_id, $old_status_id, $new_status_id, $ctx);
            }

            // Ruim het RAM-geheugen netjes op
            partstatus_getset_old_status('clear', $objectId);

            wachthond($extdebug, 3, "Activity flow voltooid en geheugen gewist.",                   "[CLEANUP]");

            $total_partstatus_post_duur = number_format(microtime(TRUE) - $partstatus_post_start, 3);
            watchdog('civicrm_timing', base_microtimer("EINDE partstatus_post"), NULL, WATCHDOG_DEBUG);
        }
    }

    // -------------------------------------------------------------------------
    // COARSE deelnamestatus voor statussen BUITEN de kamp-motor (beschikbaarheid 21-24 + Deelgenomen 2).
    // -------------------------------------------------------------------------
    // FUNCTIONEEL: Deze statussen leven op event-types (Kampstaf/Trainingsdag/Online/Meetup) die de
    // partstatus-motor bewust NIET verwerkt — leiding-beschikbaarheid (21-24: Ik ben erbij / Weet niet /
    // Kan niet / Geen reactie) en 'Deelgenomen' (2) op bv. een trainingsdag. Toch willen we die als grove
    // deelnamestatus tonen. We schrijven hier — los van de motor en van het kamp-event-filter — ALLEEN het
    // participant-veld PART.deelnamestatus (NIET de DITJAAR-spiegel: DITJAAR hoort bij het hoofdkamp, niet
    // bij een training/meetup). Voor Deelgenomen ÓP een kamp (event-type in het filter) zet de motor de
    // DITJAAR-spiegel wél; daar is dit slechts een — via base_api_wrapper diff-checked — no-op.
    if ($objectName == 'Participant' && in_array($op, ['create', 'edit'])) {
        $los_status = (int) ($objectRef->status_id ?? 0);
        if (in_array($los_status, [2, 21, 22, 23, 24]) && function_exists('partstatus_deelnamestatus_from_status')) {
            $ds = partstatus_deelnamestatus_from_status($los_status);
            if ($ds !== NULL && function_exists('base_api_wrapper')) {
                // base_api_wrapper doet zelf een diff-check → schrijft alleen bij een echte wijziging.
                base_api_wrapper('Participant', (int) $objectId, ['PART.deelnamestatus' => $ds], 'PARTSTATUS_DS_LOS', $extdebug);
            }
        }
    }
}

/**
 * HOOK: CUSTOM (Nadat custom-veldwaarden zijn gecommit) — DE ECHTE POST-SAVE TRIGGER
 * =======================================================================================
 * FUNCTIONEEL (Het 'Waarom'):
 * Dit is de ONTBREKENDE schakel waardoor het invullen van een PROCESDATUM de status
 * aanjaagt — symmetrisch voor wachtlijst én criteria:
 *   - `wachtlijst_eraf` invullen   → deelnemer van Wachtlijst (7) naar Voorheen wachtlijst (9)
 *   - `criteriacheck_einde` invullen → deelnemer van Afwachting Oordeel (8) naar 9
 * De statuslogica bestaat al (Regel D resp. Regel B in partstatus.wachtlijst.php); het enige
 * dat ontbrak was een hook die de motor draait als een veld in groep 271 (PART_DEEL_INTERN)
 * wordt opgeslagen. `partstatus_civicrm_customPre` draait wél (pre-save) maar jaagt de motor
 * NIET aan (alleen shield + datum-opschoning), en groep 271 zit niet in `cvmax` (dus
 * core_civicrm_custom draait de motor er ook niet voor).
 *
 * TECHNISCH (Het 'Hoe'):
 * `_civicrm_custom` is de ECHTE CiviCRM-hook; vuurt NÁ de commit. We herkennen relevante
 * wijzigingen aan de `column_name` per veld en draaien dan de motor via partstatus_configure(),
 * die de status herberekent, de deelnamestatus-spiegels (PART + DITJAAR) bijwerkt en Regel D/B
 * de promotie laat doen. Gemodelleerd naar het bewezen intake_civicrm_custom() (intake.php).
 *
 * @param string $op        'create' | 'edit' | 'delete'
 * @param int    $groupID   ID van de gewijzigde custom group
 * @param int    $entityID  Participant ID (groep 271 hangt aan de Participant)
 * @param array  $params    De zojuist gecommitte custom-waarden (read-only hier)
 */
function partstatus_civicrm_custom($op, $groupID, $entityID, &$params) {

    // -------------------------------------------------------------------------
    // 1.0 POORTWACHTER: GROEP, OPERATIE & RELEVANT VELD
    // -------------------------------------------------------------------------

    // 1.1 Alleen PART_DEEL_INTERN (271) en alleen bij aanmaken/wijzigen
    if ($groupID != 271 || !in_array($op, ['create', 'edit'])) {
        return;
    }

    // 1.2 Draaide er daadwerkelijk een DATUM-/OORDEEL-aanjager mee in deze save?
    //     Zo niet: niets te doen (voorkomt onnodige ~12-19s motor-run bij elke 271-save).
    //     hook_civicrm_custom levert per veld een array met o.a. 'column_name'.
    $partstatus_trigger_fields = [
        'wachtlijst_eraf_2094',     // uitstroom wachtlijst → status 7/33 → 9 (Regel D)
        'criteriacheck_einde_2092', // oordeel afgerond    → status 8    → 9 (Regel B)
        'criteria_beoordeling_1429',// handmatig oordeel   → status 8    → 9 (Regel B)
    ];

    $heeft_trigger = FALSE;
    if (is_array($params)) {
        foreach ($params as $veld) {
            $column_name = is_array($veld) ? ($veld['column_name'] ?? '') : '';
            if (in_array($column_name, $partstatus_trigger_fields)) {
                $heeft_trigger = TRUE;
                break;
            }
        }
    }
    if (!$heeft_trigger) {
        return;
    }

    // 1.3 Per-entiteit guard binnen één request: de motor schrijft zelf groep-271-velden
    //     terug (Regel C, criteria), waardoor deze hook genest opnieuw vuurt. Deze guard
    //     (plus de busy-lock in partstatus_configure) sluit een loop uit.
    static $processing_partstatus_custom = [];
    if (!empty($processing_partstatus_custom[$entityID])) {
        return;
    }

    $extdebug = 'partstatus.custom'; // Kanaal voor centrale debug-config; niveau in ozk.debug.config.php

    // -------------------------------------------------------------------------
    // 2.0 SLOT DICHT + MOTOR DRAAIEN
    // -------------------------------------------------------------------------
    $processing_partstatus_custom[$entityID] = TRUE;

    $partstatus_custom_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START partstatus_custom [GID: $groupID / EID: $entityID]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS [CUSTOM] POST-COMMIT TRIGGER [PID: $entityID]",    "[START]");
    wachthond($extdebug, 2, "########################################################################");

    try {
        // Groep 271 hangt aan de Participant: entityID IS het participant-ID.
        $part_id = (int) $entityID;

        // CRUCIAAL: haal de participant-data VERS op (force_refresh = TRUE).
        // hook_civicrm_custom vuurt ná de commit, maar een EERDERE hook in dezelfde request
        // (delta/core) kan de static cache van base_pid2part al hebben gevuld VÓÓR die commit.
        // Zonder force_refresh leest de motor dan de oude waarde van het zojuist gezette veld
        // (bv. criteriacheck_einde nog leeg) → geen promotie. Zelfde patroon als intake
        // (base_cid2cont($cid, TRUE) in intake_civicrm_custom).
        $part_array = function_exists('base_pid2part') ? (base_pid2part($part_id, TRUE) ?: NULL) : NULL;

        // partstatus_configure herberekent status + spiegelt deelnamestatus (PART + DITJAAR)
        // en laat Regel D (wachtlijst_eraf) / Regel B (criteriacheck_einde) de promotie doen.
        partstatus_configure($part_id, $part_array, NULL, 'custom_hook');

        wachthond($extdebug, 1, "### PARTSTATUS [CUSTOM] MOTOR VOLTOOID",                      "[OK]");
    }
    finally {
        // Slot altijd open, ook bij een exception, zodat we niet vast blijven zitten.
        $processing_partstatus_custom[$entityID] = FALSE;

        $total_partstatus_custom_duur = number_format(microtime(TRUE) - $partstatus_custom_start, 3);
        watchdog('civicrm_timing', base_microtimer("EINDE partstatus_custom"), NULL, WATCHDOG_DEBUG);
    }
}

/**
 * =======================================================================================
 * STANDAARD CIVICRM BOILERPLATE
 * =======================================================================================
 */

function partstatus_civicrm_config(&$config) { _partstatus_civix_civicrm_config($config);   }
function partstatus_civicrm_install()        { return _partstatus_civix_civicrm_install();  }
function partstatus_civicrm_enable()         { return _partstatus_civix_civicrm_enable();   }