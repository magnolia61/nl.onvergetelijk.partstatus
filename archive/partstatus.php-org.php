<?php

/**
 * Leeftijd Module - CiviCRM Extension
 * * * FUNCTIONELE BESCHRIJVING:
 * Deze module fungeert als de centrale rekenmachine voor deelname-criteria.
 * 1. Berekent leeftijden op 3 momenten: Vandaag, Start Event, Start Volgend Kamp.
 * 2. Valideert of een deelnemer voldoet aan de criteria (Leeftijd & Schoolgroep).
 * 3. Synchroniseert statussen (bijv. zet op 'Criteriacheck' als leeftijd afwijkt).
 * * * TECHNISCHE OPTIMALISATIES:
 * - Static Caching: Voorkomt dubbele berekeningen binnen één request (CPU winst).
 * - APIv4 Reload False: Voorkomt oneindige loops bij het updaten van contacten (DB winst).
 * - DateTime Objecten: Vervangt verouderde strtotime functies voor precisie.
 */

require_once 'leeftijd.civix.php';

/**
 * TECHNISCH: Berekent exact leeftijdverschil in decimalen.
 * OPTIMALISATIE: Gebruikt Static Caching. Als we voor kind X op datum Y al hebben gerekend,
 * doen we dat niet nog een keer. Dit scheelt honderden CPU-cycles per request.
 */
function leeftijd_civicrm_diff($job, $birthdate, $vergelijk) {

    $extdebug = 0;

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD DIFF - BEREKEN DE LEEFTIJD TOV BEPAALDE DATUM",	 "[START]");
    wachthond($extdebug, 1, "########################################################################");

    // 0. Static Cache check
    static $static_age_cache = [];
    $cache_key = $birthdate . '_' . $vergelijk;

    if (isset($static_age_cache[$cache_key])) {
        return $static_age_cache[$cache_key];
    }

    if (empty($birthdate) || empty($vergelijk)) {
        return NULL;
    }

    // 1. DateTime objecten (sneller dan oude strtotime loops)
    $date_birth = new DateTime($birthdate);
    $date_ref   = new DateTime($vergelijk);
    $diff       = $date_birth->diff($date_ref);
    
    $diffyears  = $diff->y;
    $diffmonths = $diff->m;

    // 2. Bereken decimalen (maanden naar fractie van 10)
    $diffmonths_dec = round(($diffmonths / 12 * 10), 0);
    $leeftijd_decimalen = (float)($diffyears . "." . $diffmonths_dec);
    
    $leeftijd_rondjaren = (int)$diffyears;
    
    // 3. Bereken de 'rondmaand' (terugrekenen van decimaal naar 12-maands schaal)
    $leeftijd_rondmaand = (int)round((($leeftijd_decimalen - $leeftijd_rondjaren) * 12), 0);

    // Logging (Severity 4 voor performance)
    wachthond($extdebug, 4, 'job',           $job);
    wachthond($extdebug, 4, 'birthdate',     $birthdate); 
    wachthond($extdebug, 4, 'vergelijk',     $vergelijk); 
    wachthond($extdebug, 4, 'leeftijd_dec',  $leeftijd_decimalen); 

    $leeftijd_return = array(
        'leeftijd_birthdate'    => $birthdate,
        'leeftijd_vergelijk'    => $vergelijk,
        'leeftijd_decimalen'    => $leeftijd_decimalen,
        'leeftijd_rondjaren'    => $leeftijd_rondjaren,
        'leeftijd_rondmaand'    => $leeftijd_rondmaand,
    );

    // 4. Opslaan in cache
    $static_age_cache[$cache_key] = $leeftijd_return;

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD DIFF - BEREKEN DE LEEFTIJD TOV BEPAALDE DATUM",	 "[EINDE]");
    wachthond($extdebug, 1, "########################################################################");

    return $leeftijd_return;
}

####################################################################################################################
# SECTIE 2: HOOKS (DE INGANGEN VAN DE MODULE)
####################################################################################################################

function leeftijd_civicrm_validateprofile($profileName)
{
    $extdebug = 0;

    // Static om log-vervuiling te voorkomen bij multiple validation passes
    static $logged = [];

    $target_profiles = [
        'BEHEER_GEBOORTEDATUM_101', 'Verjaardag_en_geslacht_68', 'Verjaardag_en_geslacht_97',
        'Verjaardag_en_geslacht_66', 'Verjaardag_en_geslacht_67', 'Verjaardag_en_geslacht_99',
        'Verjaardag_en_geslacht_19'
    ];

    if (in_array($profileName, $target_profiles) && !isset($logged[$profileName])) {
        wachthond($extdebug, 3, "########################################################################");
        wachthond($extdebug, 1, "### LEEFTIJD (VAL) - KAMPLEEFTIJD (VALIDATE PROFILE)", $profileName);
        wachthond($extdebug, 3, "########################################################################");
        $logged[$profileName] = true;
    }
}

function leeftijd_civicrm_custom($op, $groupID, $entityID)
{
    $extdebug = 0;

    if ($op != 'create' && $op != 'edit') {
        wachthond($extdebug, 4, "EXIT: op != create OR op != edit");
        return;
    }

    $relevant_groups = [101, 139, 190, 140, 106, 103, 149, 150, 165, 213, 205, 225];

    if (in_array($groupID, $relevant_groups)) {
		wachthond($extdebug, 3, "########################################################################");
        wachthond($extdebug, 4, "LEEFTIJD CUSTOM - START KAMPLEEFTIJD STANDALONE ---", "Group: $groupID");
		wachthond($extdebug, 3, "########################################################################");        
        // Logic placeholder indien nodig in toekomst
    }
}

/**
 * Implementation of hook_civicrm_customPre
 * FUNCTIONEEL: Bepaalt vóór opslaan of criteriacheck datums gezet of gewist moeten worden.
 * TECHNISCH: Gebruikt een field-map om de loop door $params extreem snel te maken.
 */
function leeftijd_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    $extdebug = 0;
    
    // Alleen draaien voor profiel 'Deelname Intern' (ID 271) [PART]
    if (($op != 'create' && $op != 'edit') || $groupID != 271) return;

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD PRE - DEEL_INTERN [PRE] VOOR $entityID", 			 "[START]");
    wachthond($extdebug, 1, "########################################################################");

    $today_datetime_db = date("YmdHis");

    // Mapping van technische kolomnaam naar leesbare variabele (Vervangt trage foreach loops)
    $field_map = [
        'criteria_leeftijd_1148'      => 'criteria_leeftijd',
        'criteria_school_1149'        => 'criteria_school',
        'criteria_indicatie_1428'     => 'criteria_indicatie',
        'criteria_beoordeling_1429'   => 'criteria_oordeel',
        'criteriacheck_start_2091'    => 'criteriacheck_start',
        'criteriacheck_einde_2092'    => 'criteriacheck_einde',
        'wachtlijst_erop_2093'        => 'wachtlijst_erop',
        'wachtlijst_eraf_2094'        => 'wachtlijst_eraf',
    ];

    $values 	= [];
    $indices 	= [];

    // Efficiënte loop om waarden op te halen
    foreach ($params as $idx => $field) {
        $col = $field['column_name'] ?? '';
        if (isset($field_map[$col])) {
            $key = $field_map[$col];
            $values[$key] = $field['value'];
            $indices[$key] = $idx;
        }
    }
    
    // Helper
    $get = fn($k) => $values[$k] ?? NULL;

    $new_start     = NULL;
    $new_einde     = NULL;
    $new_indicatie = NULL;
    $new_oordeel   = NULL;

    $indicatie = $get('criteria_indicatie');
    $oordeel   = $get('criteria_oordeel');

    // REGEL 1: Startdatum zetten als er een afwijking is en er nog geen datum staat
    if (in_array($indicatie, ['criteriawijktaf','schoolwijktaf','leeftijdwijktaf'])) {
        if (empty($get('criteriacheck_start'))) {
            $new_start = $today_datetime_db;
            wachthond($extdebug, 1, "SET START: Indicatie wijkt af");
        }
    }

    // REGEL 2: Resetten datums als indicatie 'prima' is
    if ($indicatie == 'criteriaprima') {
        if ($get('criteriacheck_start') || $get('criteriacheck_einde')) {
            $new_start   = "";
            $new_einde   = "";
            $new_oordeel = 'nietnodig'; 
            wachthond($extdebug, 1, "RESET: Indicatie is prima");
        }
    }

    // REGEL 3: School & Leeftijd beide prima (dubbele check)
    if ($get('criteria_school') == 'prima' && $get('criteria_leeftijd') == 'prima') {
        $new_start     = "";
        $new_einde     = "";
        $new_indicatie = 'criteriaprima';
        $new_oordeel   = 'oordeelnietnodig';
        wachthond($extdebug, 1, "RESET: Alles prima");
    }

    // REGEL 4: Oordeel 'niet nodig' dwingt reset startdatum
    if ($oordeel == 'oordeelnietnodig') {
         if ($new_start !== "") {
             $new_start = "";
         }
    }

    // UPDATES TOEPASSEN (Alleen als waarde echt verandert)
    $updates = [
        'criteria_indicatie'  => $new_indicatie,
        'criteria_oordeel'    => $new_oordeel,
        'criteriacheck_start' => $new_start,
        'criteriacheck_einde' => $new_einde,
    ];

    foreach ($updates as $key => $val) {
        if ($val !== NULL && isset($indices[$key])) {
            // Check of update nodig is om DB calls te sparen
            if ($params[$indices[$key]]['value'] != $val) {
                $params[$indices[$key]]['value'] = $val;
                wachthond($extdebug, 2, "PRE-UPDATE $key", $val);
            }
        }
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD PRE - DEEL_INTERN [PRE] VOOR $entityID", 			 "[EINDE]");
    wachthond($extdebug, 1, "########################################################################");

}

####################################################################################################################
# SECTIE 3: CORE LOGICA (BEREKENEN & UPDATEN)
####################################################################################################################

function leeftijd_configure($job, $groupID, $entityID, $basedate, $array_partditevent = NULL)
{
    $extdebug = 0;
    
    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD CONFIGURE - KAMPLEEFTIJD PROCESS", 				 "[START]");
    wachthond($extdebug, 1, "########################################################################");

    wachthond($extdebug, 1, "job",          $job);
    wachthond($extdebug, 1, "groupID",      $groupID);
    wachthond($extdebug, 1, "entityID",     $entityID);

    // OPTIMALISATIE: Gebruik find_fiscalyear uit de base module (Static Caching)
    $fiscal_data            = find_fiscalyear();
    $cache_fiscalyear_start = $fiscal_data['today_start'];
    $cache_fiscalyear_end   = $fiscal_data['today_einde'];
    
    wachthond($extdebug, 1, "cache_fiscalyear_start",   $cache_fiscalyear_start);
    wachthond($extdebug, 1, "cache_fiscalyear_end",     $cache_fiscalyear_end);

    // Initialisatie variabelen
    $todaydatetime              = date("Y-m-d");
    $displayname                = $array_partditevent['displayname']            ?? NULL;
    $contact_id                 = $array_partditevent['contact_id']             ?? NULL;
    $birthdate                  = $array_partditevent['birth_date']             ?? NULL; 

    $ditevent_part_id           = $array_partditevent['id']                     ?? NULL;
    $ditevent_event_start       = $array_partditevent['event_start_date']       ?? NULL;
    $ditevent_kampjaar          = $array_partditevent['ditevent_kampjaar']      ?? NULL;

    wachthond($extdebug, 3, 'contact_id',           $contact_id);
    wachthond($extdebug, 3, 'ditevent_part_id',     $ditevent_part_id);

    // Datumbepaling
    if ($basedate) { $datumditevent = $basedate; } else { $datumditevent = NULL; }

    if ($job == 'vandaag') {
        $birthdate      = $basedate;
        $basedate       = date("Y-m-d");
        $datumditevent  = date("Y-m-d");
    }

    $datumditevent = $ditevent_event_start ?: $datumditevent;

    // OPTIMALISATIE: Gebruik find_lastnext (Static Caching)
    $today_lastnext = find_lastnext($todaydatetime);    
    $datumnextkamp  = $today_lastnext['next_start_date'];
    
    wachthond($extdebug, 3, 'datumnextkamp', $datumnextkamp);

    // BEREKENINGEN (Gebruiken nu interne static cache in diff functie)
    $leeftijd_vantoday = $leeftijd_ditevent = $leeftijd_nextkamp = [];

    if ($birthdate) {
        $leeftijd_vantoday = leeftijd_civicrm_diff('vandaag',   $birthdate, $todaydatetime);
        $leeftijd_ditevent = leeftijd_civicrm_diff('ditevent',  $birthdate, $datumditevent);
        $leeftijd_nextkamp = leeftijd_civicrm_diff('nextkamp',  $birthdate, $datumnextkamp);
    }

    $leeftijd_vantoday_decimalen = $leeftijd_vantoday['leeftijd_decimalen'] ?? 0;
    $leeftijd_ditevent_decimalen = $leeftijd_ditevent['leeftijd_decimalen'] ?? 0;
    $leeftijd_nextkamp_decimalen = $leeftijd_nextkamp['leeftijd_decimalen'] ?? 0;

    // --- VOORBEREIDEN UPDATES ---
    
    // Params voor Contact Update
    $params_cont_update = [
        'checkPermissions' => FALSE,
        'where'            => [['id', '=', $contact_id]],
        'values'           => ['id' => $contact_id],                
    ];

    // Params voor Participant Update
    $params_part_update = [
        'checkPermissions' => FALSE,
        'where'            => [['id', '=', $ditevent_part_id]],
        'values'           => ['id' => $ditevent_part_id],                
    ];

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD PRE - PREPARE DB UPDATE");
    wachthond($extdebug, 1, "########################################################################");

    // LOGICA: Update Werving tab data op Contact (TAB & PART groups)
    if (in_array($groupID, array("149", "225", "139", "190"))) {
        $timestampditevent = strtotime($datumditevent);
        $timestampnextkamp = strtotime($datumnextkamp);
        
        // Is het event waar we naar kijken in hetzelfde jaar als het volgende kamp?
        // Zo ja, gebruik de event-leeftijd. Zo nee, gebruik de next-kamp leeftijd.
        $source = ($leeftijd_ditevent && date("Y", $timestampditevent) == date("Y", $timestampnextkamp)) 
                  ? $leeftijd_ditevent 
                  : $leeftijd_nextkamp;

        if ($source) {
            // Gebruik format_civicrm_smart voor veiligheid (voorkom array errors)
            $params_cont_update['values']['WERVING.nextkamp_decimalen'] = format_civicrm_smart($source['leeftijd_decimalen'], 'WERVING.nextkamp_decimalen');
            $params_cont_update['values']['WERVING.nextkamp_rondjaren'] = format_civicrm_smart($source['leeftijd_rondjaren'], 'WERVING.nextkamp_rondjaren');
            $params_cont_update['values']['WERVING.nextkamp_rondmaand'] = format_civicrm_smart($source['leeftijd_rondmaand'], 'WERVING.nextkamp_rondmaand');
        }
    }

    // UITVOEREN: Contact Update
    // TECHNISCH: reload=FALSE voorkomt dat CiviCRM na de update alle data opnieuw ophaalt (Performance!)
    if ($job == 'event' && count($params_cont_update['values']) > 1) {
        $params_cont_update['reload'] = FALSE; 
        wachthond($extdebug, 3, "params_cont_update", $params_cont_update);
        
        if ($contact_id) {
            $res = civicrm_api4('Contact', 'update', $params_cont_update);
            wachthond($extdebug, 3, "result_leeftijd_cont_update", $res);
        }
    }

    // UITVOEREN: Participant Update
    if ($job == 'event' && in_array($groupID, array("139", "190"))) {
        $val = format_civicrm_smart($leeftijd_ditevent_decimalen, 'PART.nextkamp_decimalen');
        $params_part_update['values']['PART.nextkamp_decimalen'] = $val;
        
        if (count($params_part_update['values']) > 1) {
            $params_part_update['reload'] = FALSE;
            wachthond($extdebug, 3, "params_part_update", $params_part_update);
            
            if ($ditevent_part_id) {
                $res = civicrm_api4('Participant', 'update', $params_part_update);
                wachthond($extdebug, 3, "result_leeftijd_part_update", $res);
            }
        }
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD CONFIGURE - KAMPLEEFTIJD PROCESS", 				 "[EINDE]");
    wachthond($extdebug, 1, "########################################################################");

    return array(
        'leeftijdvantoday_decimalen'    => $leeftijd_vantoday_decimalen,
        'leeftijdditevent_decimalen'    => $leeftijd_ditevent_decimalen,
        'leeftijdnextkamp_decimalen'    => $leeftijd_nextkamp_decimalen,
    );
}

function leeftijd_civicrm_criteria($array_partditevent, $leeftijd_ditevent_decimalen)
{
    $extdebug = 0;
    wachthond($extdebug, 4, 'leeftijd_ditevent_decimalen', $leeftijd_ditevent_decimalen);

    if (empty($array_partditevent)) return;

    $ditevent_part_rol = $array_partditevent['part_rol'] ?? NULL;

    // Leiding heeft geen leeftijdscriteria
    if ($ditevent_part_rol != 'deelnemer') {
        wachthond($extdebug, 4, 'EXIT: Rol is geen deelnemer', $ditevent_part_rol);
        return;
    }

    // Variabelen toewijzen
    $contact_id              = $array_partditevent['contact_id']            ?? NULL;
    $part_id                 = $array_partditevent['id']                    ?? NULL;
    $kampkort                = $array_partditevent['kenmerken_kampkort']    ?? NULL;
    $leeftijd                = (float)$leeftijd_ditevent_decimalen;
    $part_groepklas          = $array_partditevent['groepklas']             ?? NULL;
    $part_criteria_oordeel   = $array_partditevent['criteria_oordeel']      ?? NULL;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 1.0 CORRECTIE MIXUP GROEP VS KLAS",            "[GROEPKLAS]");
    wachthond($extdebug, 2, "########################################################################");
    
    $new_groepklas = $part_groepklas;
    
    if (in_array($kampkort, ['kk1', 'kk2'])) {
        $map = ['klas_3' => 'groep_3', 'klas_4' => 'groep_4', 'klas_5' => 'groep_5', 'klas_6' => 'groep_6'];
        $new_groepklas = $map[$part_groepklas] ?? $new_groepklas;
    }
    if (in_array($kampkort, ['tk1', 'tk2'])) {
        $map = ['groep_2' => 'klas_2', 'groep_3' => 'klas_3'];
        $new_groepklas = $map[$part_groepklas] ?? $new_groepklas;
    }
    if (in_array($kampkort, ['jk1', 'jk2'])) {
        $map = ['groep_3' => 'klas_3', 'groep_4' => 'klas_4', 'groep_5' => 'klas_5', 'groep_6' => 'klas_6'];
        $new_groepklas = $map[$part_groepklas] ?? $new_groepklas;
    }

    if ($new_groepklas != $part_groepklas) {
        wachthond($extdebug, 1, "!!! MIXUP HERSTELD: $part_groepklas -> $new_groepklas");
    }

    $criteria_school   = 'afwijkend';
    $criteria_leeftijd = 'afwijkend';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 2.0 BEOORDELING SCHOOL",                          "[SCHOOL]");
    wachthond($extdebug, 2, "########################################################################");

    $prima_school = [
        'kk1' => ["groep_3","groep_4","groep_5","groep_6","groep_7"],
        'kk2' => ["groep_3","groep_4","groep_5","groep_6","groep_7"],
        'bk1' => ["groep_8","klas_1"],
        'bk2' => ["groep_8","klas_1"],
        'tk1' => ["klas_2","klas_3"],
        'tk2' => ["klas_2","klas_3"],
        'jk1' => ["klas_4","klas_5","klas_6","vervolg"],
        'jk2' => ["klas_4","klas_5","klas_6","vervolg"],
    ];

    if (isset($prima_school[$kampkort]) && in_array($new_groepklas, $prima_school[$kampkort])) {
        $criteria_school = 'prima';
    }

    // Marge Check School
    if (in_array($kampkort, ["tk1","tk2"]) && $new_groepklas == "klas_4")           { $criteria_school = 'marge'; }
    if (in_array($kampkort, ["jk1","jk2"]) && in_array($new_groepklas, ["klas_2","klas_3"])) { $criteria_school = 'marge'; }

    wachthond($extdebug, 3, "criteria_school", $criteria_school);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 3.0 BEOORDELING LEEFTIJD",                      "[LEEFTIJD]");
    wachthond($extdebug, 2, "########################################################################");

    // Prima checks
    if (in_array($kampkort, ['kk1', 'kk2']) && ($leeftijd >= 7.0  && $leeftijd < 12.0))  { $criteria_leeftijd = 'prima'; }
    if (in_array($kampkort, ['bk1', 'bk2']) && ($leeftijd >= 12.0 && $leeftijd < 14.0))  { $criteria_leeftijd = 'prima'; }
    if (in_array($kampkort, ['tk1', 'tk2']) && ($leeftijd >= 14.0 && $leeftijd < 16.0))  { $criteria_leeftijd = 'prima'; }
    if (in_array($kampkort, ['jk1', 'jk2']) && ($leeftijd >= 16.0 && $leeftijd < 18.0))  { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'top'                  && ($leeftijd >= 18.0 && $leeftijd < 21.0))  { $criteria_leeftijd = 'prima'; }

    // Marge Check Leeftijd - Ondergrenzen
    if (in_array($kampkort, ["kk1", "kk2"]) && ($leeftijd >= 6.7  && $leeftijd < 7.0))   { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["bk1", "bk2"]) && ($leeftijd >= 11.3 && $leeftijd < 12.0))  { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["tk1", "tk2"]) && ($leeftijd >= 13.7 && $leeftijd < 14.0))  { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["jk1", "jk2"]) && ($leeftijd >= 15.7 && $leeftijd < 16.0))  { $criteria_leeftijd = 'marge'; }

    // Marge Check Leeftijd - Bovengrenzen
    if (in_array($kampkort, ["kk1", "kk2"]) && ($leeftijd >= 12.0 && $leeftijd <= 12.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["bk1", "bk2"]) && ($leeftijd >= 14.0 && $leeftijd <= 14.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["tk1", "tk2"]) && ($leeftijd >= 16.0 && $leeftijd <= 16.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, ["jk1", "jk2"]) && ($leeftijd >= 18.0 && $leeftijd <= 18.3)) { $criteria_leeftijd = 'marge'; }

    wachthond($extdebug, 3, "criteria_leeftijd", $criteria_leeftijd);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 5.0 START BEPALING EINDOORDEEL",                 "[OORDEEL]");
    wachthond($extdebug, 2, "########################################################################");

    $criteria_indicatie = 'noggeenindicatie';
    $criteria_oordeel   = 'oordeelnognodig';

    if (in_array($part_criteria_oordeel, ['oordeelnietnodig', 'oordeelprima', 'oordeelaangepast', 'oordeelafgewezen', 'buitencriteria'])) {
        $criteria_oordeel = $part_criteria_oordeel;
        wachthond($extdebug, 3, "STATUS: Handmatig oordeel behouden", $criteria_oordeel);
    }

    if ($criteria_leeftijd == 'prima' && $criteria_school == 'prima') {
        $criteria_indicatie = 'criteriaprima';
        if ($criteria_oordeel == 'oordeelnognodig') { $criteria_oordeel = 'oordeelnietnodig'; }
        wachthond($extdebug, 3, "BRANCH: Alles prima", "Hit");
    } 
    elseif ($criteria_leeftijd == 'marge' || $criteria_school == 'marge') {
        $criteria_indicatie = 'binnenmarges'; 
        if ($criteria_oordeel == 'oordeelnognodig') { $criteria_oordeel = 'oordeelnietnodig'; }
        wachthond($extdebug, 3, "BRANCH: Binnen marges", "Hit");
    }
    elseif ($criteria_leeftijd == 'afwijkend' && $criteria_school != 'afwijkend') {
        $criteria_indicatie = 'leeftijdwijktaf';
        wachthond($extdebug, 3, "BRANCH: Leeftijd afwijkt", "Hit");
    }
    elseif ($criteria_leeftijd != 'afwijkend' && $criteria_school == 'afwijkend') {
        $criteria_indicatie = 'schoolwijktaf';
        wachthond($extdebug, 3, "BRANCH: School afwijkt", "Hit");
    }
    else {
        $criteria_indicatie = 'criteriawijktaf';
        wachthond($extdebug, 3, "BRANCH: Volledig afwijkend", "Hit");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 5.1 EXTRA KAMP CORRECTIES",                   "[CORRECTIE]");
    wachthond($extdebug, 2, "########################################################################");

    if ($kampkort == 'top' && $criteria_leeftijd == 'prima') {
        $criteria_school    = 'prima';
        $criteria_indicatie = 'criteriaprima';
        $criteria_oordeel   = 'oordeelnietnodig';
        wachthond($extdebug, 1, "EXTRA: Topkamp correctie");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 6.0 DB UPDATES MET SMART GUARD",                 "[UPDATES]");
    wachthond($extdebug, 2, "########################################################################");

    static $processing_criteria = [];
    $current_state_key = md5($criteria_leeftijd . $criteria_school . $criteria_indicatie . $criteria_oordeel . $new_groepklas);

    if ($contact_id && ($processing_criteria['c_' . $contact_id] ?? '') !== $current_state_key) {
        $processing_criteria['c_' . $contact_id] = $current_state_key;
        civicrm_api4('Contact', 'update', [
            'checkPermissions' => FALSE,
            'reload'           => FALSE,
            'where'            => [['id', '=', $contact_id]],
            'values'           => [
                'DITJAAR.ditjaar_leeftijd'           => $criteria_leeftijd,
                'DITJAAR.ditjaar_school'             => $criteria_school,
                'DITJAAR.ditjaar_criteria_indicatie' => $criteria_indicatie,
                'DITJAAR.ditjaar_criteria_oordeel'   => $criteria_oordeel,
            ]
        ]);
        wachthond($extdebug, 2, "UPDATE CONTACT: $contact_id");
    }

    if ($part_id && ($processing_criteria['p_' . $part_id] ?? '') !== $current_state_key) {
        $processing_criteria['p_' . $part_id] = $current_state_key;
        $part_values = [
            'PART_DEEL_INTERN.criteria_leeftijd'  => $criteria_leeftijd,
            'PART_DEEL_INTERN.criteria_school'    => $criteria_school,
            'PART_DEEL_INTERN.criteria_indicatie' => $criteria_indicatie,
            'PART_DEEL_INTERN.criteria_oordeel'   => $criteria_oordeel,
        ];
        if ($new_groepklas) { $part_values['PART_DEEL.Groep_klas'] = $new_groepklas; }

        civicrm_api4('Participant', 'update', [
            'checkPermissions' => FALSE,
            'reload'           => FALSE,
            'where'            => [['id', '=', $part_id]],
            'values'           => $part_values,
        ]);
        wachthond($extdebug, 2, "UPDATE PARTICIPANT: $part_id");
    }

    $leeftijd_criteria_array = array(
        'invoer_kampkort'       => $kampkort,
        'invoer_groepklas'      => $part_groepklas,
        'invoer_leeftijd'       => $leeftijd,
        'criteria_leeftijd'     => $criteria_leeftijd,
        'criteria_school'       => $criteria_school,
        'criteria_indicatie'    => $criteria_indicatie,
        'criteria_oordeel'      => $criteria_oordeel,
    );

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### CRITERIA - 7.0 RETURN LEEFTIJD_CRTITERIA_ARRAY", $leeftijd_criteria_array);
    wachthond($extdebug,3, "########################################################################");

    return $leeftijd_criteria_array;
}

/**
 * =======================================================================================
 * FUNCTIONEEL: 
 * Deze functie bepaalt de definitieve deelnamestatus (bijv. Bevestigd, Wachtlijst, 
 * Afwachting of Criteriacheck) van een deelnemer. Hij kijkt hierbij naar leeftijd, 
 * schoolklas, handmatige oordelen en of de inschrijving financieel is afgerond.
 * * TECHNISCH:   
 * Vergelijkt de huidige (oude) status met de nieuw berekende criteria. Maakt gebruik 
 * van "Smart Guards" (MD5 hashes van de array) om te voorkomen dat de CiviCRM API 
 * continu onnodige updates uitvoert en in een oneindige loop belandt.
 * =======================================================================================
 */
function leeftijd_civicrm_status($array_partditevent, $array_criteria = NULL) {

    $extdebug = 0;
    
    // TECHNISCH: Stop direct als de input geen geldige array is om fatale PHP errors te voorkomen.
    if (!is_array($array_partditevent)) return;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.1 BEPAAL STATUS REGISTRATIE & DEELNAME",  "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // --------------------------------------------------------------------------------------
    // STAP 1: BASISDATA OPHALEN EN UITLIJNEN
    // --------------------------------------------------------------------------------------
    $ditevent_part_id           = $array_partditevent['id']                     ?? NULL;
    $contact_id                 = $array_partditevent['contact_id']             ?? NULL;
    $ditevent_part_status_id    = $array_partditevent['status_id']              ?? NULL;
    $ditevent_part_rol          = $array_partditevent['part_rol']               ?? NULL;
    $ditevent_register_date     = $array_partditevent['register_date']          ?? NULL;

    // FUNCTIONEEL: We moeten weten of de deelnemer al betaald heeft (of een factuur heeft).
    // TECHNISCH: We proberen eerst de gecachte array-waarde. Als die leeg is, doen we een 
    // live database check via de Pecunia module om 100% zeker te zijn van de financiële status.
    $ditevent_contribid         = $array_partditevent['part_kampgeld_contribid'] ?? NULL;

    if (empty($ditevent_contribid) && function_exists('pecunia_get_contribid_by_partid')) {
        $ditevent_contribid         = pecunia_get_contribid_by_partid($ditevent_part_id);
        
        // Ultieme fallback: zoek via contact_id en datum als line-item faalt
        if (empty($ditevent_contribid) && function_exists('pecunia_get_contribid')) {
            $ditevent_contribid         = pecunia_get_contribid($array_partditevent);
        }
    }

    // --------------------------------------------------------------------------------------
    // STAP 2: BESTAANDE CRITERIA & WACHTLIJST DATUMS OPHALEN
    // --------------------------------------------------------------------------------------
    $part_criteria_leeftijd     = $array_partditevent['criteria_leeftijd']      ?? NULL;
    $part_criteria_school       = $array_partditevent['criteria_school']        ?? NULL;
    $part_criteria_indicatie    = $array_partditevent['criteria_indicatie']     ?? NULL;
    $part_criteria_oordeel      = $array_partditevent['criteria_oordeel']       ?? NULL;

    wachthond($extdebug, 2, 'part_criteria_leeftijd',       $part_criteria_leeftijd);
    wachthond($extdebug, 2, 'part_criteria_school',         $part_criteria_school);
    wachthond($extdebug, 2, 'part_criteria_indicatie',      $part_criteria_indicatie);
    wachthond($extdebug, 2, 'part_criteria_oordeel',        $part_criteria_oordeel);    

    $part_wachtlijst_erop       = $array_partditevent['wachtlijst_erop']        ?? NULL;
    $part_wachtlijst_eraf       = $array_partditevent['wachtlijst_eraf']        ?? NULL;
    $part_criteriacheck_start   = $array_partditevent['criteriacheck_start']    ?? NULL;
    $part_criteriacheck_einde   = $array_partditevent['criteriacheck_einde']    ?? NULL;

    wachthond($extdebug, 3, 'part_wachtlijst_erop',         $part_wachtlijst_erop);
    wachthond($extdebug, 3, 'part_wachtlijst_eraf',         $part_wachtlijst_eraf);
    wachthond($extdebug, 3, 'part_criteriacheck_start',     $part_criteriacheck_start);
    wachthond($extdebug, 3, 'part_criteriacheck_einde',     $part_criteriacheck_einde);

    // --------------------------------------------------------------------------------------
    // STAP 3: ACTUELE WAARDEN BEPALEN (Nieuw berekend OF behoud bestaande)
    // --------------------------------------------------------------------------------------
    // TECHNISCH: Als deze functie wordt aangeroepen MET een $array_criteria, overschrijven
    // we de oude database waarden met de live berekende waarden.
    $actueel_criteria_leeftijd  = $array_criteria['criteria_leeftijd']  ?? $part_criteria_leeftijd;
    $actueel_criteria_school    = $array_criteria['criteria_school']    ?? $part_criteria_school;
    $actueel_criteria_indicatie = $array_criteria['criteria_indicatie'] ?? $part_criteria_indicatie;
    $actueel_criteria_oordeel   = $array_criteria['criteria_oordeel']   ?? $part_criteria_oordeel;

    // We initialiseren de 'nieuwe' variabelen eerst met de 'oude' waarden.
    // Pas als we in de logica hieronder bepalen dat er iets moet veranderen, passen we ze aan.
    $new_status_id              = $ditevent_part_status_id;
    $new_status_label           = NULL;
    $new_wachtlijst_erop        = $part_wachtlijst_erop;
    $new_wachtlijst_eraf        = $part_wachtlijst_eraf;
    $new_criteriacheck_start    = $part_criteriacheck_start;
    $new_criteriacheck_einde    = $part_criteriacheck_einde;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.2 BEREKEN NIEUWE STATUS & DATUMS",      "[LOGICA]");
    wachthond($extdebug, 2, "########################################################################");

    // --------------------------------------------------------------------------------------
    // STAP 4: LOGICA - CORRECTIE VAN ONBEKENDE STATUS (0, 5, 6)
    // --------------------------------------------------------------------------------------
    // FUNCTIONEEL: Soms krijgt een deelnemer geen of een foute status vanuit de inschrijving.
    // Dit blok dwingt de deelnemer in een correcte status (Wachtlijst, Bevestigd of Criteriacheck).
    if (in_array($ditevent_part_status_id, [0, 5, 6]) && $ditevent_part_rol == 'deelnemer') {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### LEEFTIJD STATUS - CORRECTIE ONBEKENDE STATUS (0, 5, 6)",    "[FIX]");
        wachthond($extdebug, 2, "########################################################################");

        if (!empty($new_wachtlijst_erop) && empty($new_wachtlijst_eraf)) {
            
            // SCENARIO 1: Heeft al een startdatum voor de wachtlijst, maar is er nog niet af.
            $new_status_id          = 7; // On waitlist
            $new_status_label       = 'Wachtlijst';
            wachthond($extdebug, 4, "LOGICA: Onbekend -> Wachtlijst (Staat op wachtlijst)",         "[HIT]");

        } elseif (in_array($actueel_criteria_oordeel, ['oordeelprima', 'oordeelaangepast', 'buitencriteria'])) {
            
            // SCENARIO 2: Is handmatig al goedgekeurd door beheerder.
            $new_status_id          = 1; // Registered
            $new_status_label       = 'Bevestigd';
            wachthond($extdebug, 4, "LOGICA: Onbekend -> Bevestigd (Handmatig Oordeel OK)",         "[HIT]");

        } elseif (in_array($actueel_criteria_indicatie, ['criteriaprima', 'binnenmarges'])) {
            
            // SCENARIO 3: Systeem ziet geen problemen met leeftijd of klas.
            $new_status_id          = 1; // Registered
            $new_status_label       = 'Bevestigd';
            wachthond($extdebug, 4, "LOGICA: Onbekend -> Bevestigd (Prima of Binnen Marges)",       "[HIT]");

        } else {
            
            // SCENARIO 4: Wijkt af van criteria EN is nog niet handmatig goedgekeurd.
            $new_status_id          = 8; // Awaiting approval
            $new_status_label       = 'Criteriacheck';
            
            // Start de stopwatch voor de criteriacheck als deze nog niet liep.
            if (empty($new_criteriacheck_start)) {
                $new_criteriacheck_start    = $ditevent_register_date;
                wachthond($extdebug, 4, "LOGICA: Onbekend -> Zet Criteriacheck startdatum",         "[HIT]");
            }
            wachthond($extdebug, 4, "LOGICA: Onbekend -> Criteriacheck (Criteria wijkt af)",        "[HIT]");
        }
    }

    // --------------------------------------------------------------------------------------
    // STAP 5: LOGICA - BEVESTIGD & WACHTLIJST STANDAARDEN
    // --------------------------------------------------------------------------------------
    // Forceer het label voor status 1 om consistentie te garanderen.
    if ($ditevent_part_status_id == 1) {
        $new_status_label           = 'Bevestigd';
        wachthond($extdebug, 4, "LOGICA: Behoud status Bevestigd", "Hit");
    }
    
    // FUNCTIONEEL: Als iemand expliciet de status Wachtlijst (7) heeft, leg dan de datum vast
    // waarop ze op de wachtlijst zijn gekomen (mits die nog leeg was).
    if ($ditevent_part_status_id == 7) {
        $new_status_label           = 'Wachtlijst';
        if (empty($new_wachtlijst_erop)) {
            $new_wachtlijst_erop        = $ditevent_register_date; 
            wachthond($extdebug, 4, "LOGICA: Wachtlijst -> Zet wachtlijst_erop datum", "Hit");
        }
        wachthond($extdebug, 4, "LOGICA: Wachtlijst status herkend", "Hit");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS - AFHANDELING WACHTLIJST & CONTRIBUTIE",  "[CHECK]");
    wachthond($extdebug, 2, "########################################################################");

    // --------------------------------------------------------------------------------------
    // STAP 6: LOGICA - VAN WACHTLIJST AF (CONTRIBUTIE CHECK)
    // --------------------------------------------------------------------------------------
    // FUNCTIONEEL: Dit triggert als iemand status 9 (Afwachting) heeft, OF als we net 
    // handmatig een 'wachtlijst_eraf' datum hebben ingevuld terwijl ze nog een oude status hadden.
    if ($ditevent_part_status_id == 9 || (!empty($new_wachtlijst_eraf) && in_array($ditevent_part_status_id, [0, 5, 6, 7]))) {
        
        if ($ditevent_contribid > 0) {
            
            // FUNCTIONEEL: Ouders hebben de aanmelding afgerond (er is een factuur/bijdrage).
            $new_status_id              = 1;
            $new_status_label           = 'Bevestigd';
            wachthond($extdebug, 4, "LOGICA: Wachtlijst eraf + Contributie -> Bevestigd",           "[HIT]");
            
        } else {
            
            // FUNCTIONEEL: Ouders zijn gemaild dat er plek is, maar hebben de inschrijving nog niet voltooid.
            $new_status_id              = 9;
            $new_status_label           = 'Afwachting';
            wachthond($extdebug, 4, "LOGICA: Wachtlijst eraf (geen betaling) -> Afwachting",        "[HIT]");
        }

        // TECHNISCH: Schrijf de huidige datum/tijd weg om te meten hoe lang ze op de lijst stonden.
        if (empty($new_wachtlijst_eraf)) {
            $new_wachtlijst_eraf        = date("Y-m-d H:i:s");
            wachthond($extdebug, 4, "LOGICA: Wachtlijst eraf datum weggeschreven",                  "[HIT]");
        }
    }

    // --------------------------------------------------------------------------------------
    // STAP 7: LOGICA - LEIDING EN CRITERIACHECK RESET
    // --------------------------------------------------------------------------------------
    // FUNCTIONEEL: Leidinggevenden staan (tenzij geannuleerd) altijd direct op Bevestigd.
    if ($ditevent_part_rol == 'leiding' && $ditevent_part_status_id != 4) {
        $new_status_id              = 1;
        $new_status_label           = 'Bevestigd';
        wachthond($extdebug, 4, "LOGICA: Leiding (niet geannul) -> Bevestigd", "Hit");
    }

    // FUNCTIONEEL: Als het oordeel "niet nodig" is geworden (omdat alles prima is of overruled),
    // maken we de start/eind datums van de criteriacheck weer leeg om het dashboard schoon te houden.
    if ($actueel_criteria_oordeel == 'oordeelnietnodig') {
        $new_criteriacheck_start    = "";
        $new_criteriacheck_einde    = "";
        wachthond($extdebug, 4, "LOGICA: Oordeel niet nodig -> Wis check datums", "Hit");
    }

    // FUNCTIONEEL: Als er wel een oordeel nodig is, of ze vallen in status 8 (Criteriacheck),
    // zorg dan dat de startdatum gezet is.
    if ($actueel_criteria_oordeel == 'oordeelnognodig' || $new_status_id == 8) {
        if (empty($new_criteriacheck_start)) {
            $new_criteriacheck_start    = $ditevent_register_date;
            wachthond($extdebug, 4, "LOGICA: Oordeel nog nodig -> Zet startdatum", "Hit");
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.3 UPDATES UITVOEREN",                 "[UPDATES]");
    wachthond($extdebug, 2, "########################################################################");

    // --------------------------------------------------------------------------------------
    // STAP 8: DATABASE UPDATES PREPAREREN EN UITVOEREN
    // --------------------------------------------------------------------------------------
    
    // ==========================================
    // 8A. UPDATE PARTICIPANT
    // ==========================================
    $updates_part = [];

    // Voeg alleen waarden toe aan de update array als ze daadwerkelijk zijn veranderd
    if ($new_status_id              != $ditevent_part_status_id)    $updates_part['status_id']                  = $new_status_id;
    if ($new_status_label)                                          $updates_part['PART.deelnamestatus:label']  = $new_status_label;

    // TECHNISCH: Gebruik 'format_civicrm_smart' om datums correct te formatteren voor de CiviCRM API.
    if ($new_wachtlijst_erop        !== $part_wachtlijst_erop)      {
        $updates_part['PART_DEEL_INTERN.wachtlijst_erop']       = format_civicrm_smart($new_wachtlijst_erop,     'PART_DEEL_INTERN.wachtlijst_erop');
    }
    if ($new_wachtlijst_eraf        !== $part_wachtlijst_eraf)      {
        $updates_part['PART_DEEL_INTERN.wachtlijst_eraf']       = format_civicrm_smart($new_wachtlijst_eraf,     'PART_DEEL_INTERN.wachtlijst_eraf');
    }
    if ($new_criteriacheck_start    !== $part_criteriacheck_start)  {
        $updates_part['PART_DEEL_INTERN.criteriacheck_start']   = format_civicrm_smart($new_criteriacheck_start, 'PART_DEEL_INTERN.criteriacheck_start');
    }
    if ($new_criteriacheck_einde    !== $part_criteriacheck_einde)  {
        $updates_part['PART_DEEL_INTERN.criteriacheck_einde']   = format_civicrm_smart($new_criteriacheck_einde, 'PART_DEEL_INTERN.criteriacheck_einde');
    }

    // Als we een ID hebben én er is iets gewijzigd, voer de API call uit.
    if ($ditevent_part_id && !empty($updates_part)) {
        
        // SMART GUARD: We genereren een MD5 hash van de updates. Als deze identiek is aan de vorige
        // run binnen hetzelfde proces, slaan we hem over. Dit voorkomt infinite loops in hooks.
        static $processing_status = [];
        $part_state_key           = md5(serialize($updates_part));

        if (($processing_status['p_' . $ditevent_part_id] ?? '') !== $part_state_key) {
            
            $processing_status['p_' . $ditevent_part_id] = $part_state_key;

            $params_part = [
                'checkPermissions'  => FALSE,
                'where'             => [['id', '=', $ditevent_part_id]],
                'values'            => $updates_part,
            ];
            
            wachthond($extdebug, 7, 'params_part',               $params_part);
            $result_part = civicrm_api4('Participant', 'update', $params_part);
            wachthond($extdebug, 9, 'result_part',               $result_part);

            wachthond($extdebug, 2, "STATUS UPDATED PART",       "$new_status_id ($new_status_label)");
        } else {
            wachthond($extdebug, 3, "STATUS UPDATED PART",       "Overgeslagen (Smart Guard): Geen nieuwe wijzigingen");
        }
    }

    // ==========================================
    // 8B. UPDATE CONTACT (Spiegelen van velden)
    // ==========================================
    // FUNCTIONEEL: We spiegelen bepaalde participant-gegevens direct naar het contact record 
    // ('DITJAAR' velden) zodat we ze makkelijk in overzichten of mailings kunnen gebruiken.
    
    $updates_contact = [];
    
    if ($new_status_label           !== NULL) { $updates_contact['DITJAAR.ditjaar_deelnamestatus:label']    = $new_status_label; }
    
    if ($new_wachtlijst_erop        !== NULL) {
        $updates_contact['DITJAAR.ditjaar_wachtlijst_erop']     = format_civicrm_smart($new_wachtlijst_erop,     'DITJAAR.ditjaar_wachtlijst_erop');
    }
    if ($new_wachtlijst_eraf        !== NULL) {
        $updates_contact['DITJAAR.ditjaar_wachtlijst_eraf']     = format_civicrm_smart($new_wachtlijst_eraf,     'DITJAAR.ditjaar_wachtlijst_eraf');
    }
    if ($new_criteriacheck_start    !== NULL) {
        $updates_contact['DITJAAR.ditjaar_criteriacheck_start'] = format_civicrm_smart($new_criteriacheck_start, 'DITJAAR.ditjaar_criteriacheck_start');
    }
    if ($new_criteriacheck_einde    !== NULL) {
        $updates_contact['DITJAAR.ditjaar_criteriacheck_einde'] = format_civicrm_smart($new_criteriacheck_einde, 'DITJAAR.ditjaar_criteriacheck_einde');
    }

    if ($contact_id && !empty($updates_contact)) {
        
        // SMART GUARD voor Contact updates
        $cont_state_key = md5(serialize($updates_contact));

        if (($processing_status['c_' . $contact_id] ?? '') !== $cont_state_key) {
            
            $processing_status['c_' . $contact_id] = $cont_state_key;

            // TECHNISCH: reload=FALSE voorkomt dat CiviCRM de hele DB weer uitleest na update
            $params_contact = [
                'checkPermissions'  => FALSE,
                'reload'            => FALSE,
                'where'             => [['id', '=', $contact_id]],
                'values'            => $updates_contact,
            ];
            
            wachthond($extdebug, 7, 'params_contact',            $params_contact);
            $result_contact = civicrm_api4('Contact', 'update',  $params_contact);
            wachthond($extdebug, 9, 'result_contact',            $result_contact);

            $log_status = $new_status_label ?? "Updated";
            wachthond($extdebug, 2, "STATUS UPDATED CONT",       $log_status);
        } else {
            wachthond($extdebug, 3, "STATUS UPDATED CONT",       "Overgeslagen (Smart Guard): Geen nieuwe wijzigingen");
        }
    }

    // We geven de nieuw berekende status en datums terug zodat andere modules (zoals Core)
    // hier ook mee verder kunnen rekenen.
    return [
        'status_id'                 => $new_status_id,
        'status_label'              => $new_status_label,
        'ditevent_deelnamestatus'   => $new_status_label,
        'criteriacheck_start'       => $new_criteriacheck_start,
        'criteriacheck_einde'       => $new_criteriacheck_einde,
        'wachtlijst_erop'           => $new_wachtlijst_erop,
        'wachtlijst_eraf'           => $new_wachtlijst_eraf
    ];
}

####################################################################################################################
# SECTIE 4: STANDAARD CIVICRM BOILERPLATE
####################################################################################################################

function leeftijd_civicrm_config(&$config) {
    _leeftijd_civix_civicrm_config($config);
}

function leeftijd_civicrm_install() {
    return _leeftijd_civix_civicrm_install();
}

function leeftijd_civicrm_enable() {
    return _leeftijd_civix_civicrm_enable();
}