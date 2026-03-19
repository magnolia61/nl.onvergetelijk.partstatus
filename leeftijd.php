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

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### CRITERIA - 1.X BEOORDEEL CRITERIA SCHOOL & LEEFTIJD"); 
    wachthond($extdebug,3, "########################################################################");    

    // Variabelen toewijzen
    $contact_id                 = $array_partditevent['contact_id'] 		   ?? NULL;
    $part_id                    = $array_partditevent['id'] 				   ?? NULL;
    $kampkort                   = $array_partditevent['kenmerken_kampkort']    ?? NULL;
    $leeftijd                   = (float)$leeftijd_ditevent_decimalen;

    $part_groepklas             = $array_partditevent['groepklas']             ?? NULL;
    $part_voorkeur              = $array_partditevent['voorkeur']              ?? NULL;

    wachthond($extdebug,2, 'part_groepklas',            $part_groepklas);
    wachthond($extdebug,2, 'part_voorkeur',             $part_voorkeur);

    $part_criteria_leeftijd     = $array_partditevent['criteria_leeftijd']     ?? NULL;
    $part_criteria_school       = $array_partditevent['criteria_school']       ?? NULL;
    $part_criteria_indicatie    = $array_partditevent['criteria_indicatie']    ?? NULL;
    $part_criteria_oordeel      = $array_partditevent['criteria_oordeel']      ?? NULL;

    wachthond($extdebug,2, 'part_criteria_leeftijd',    $part_criteria_leeftijd);
    wachthond($extdebug,2, 'part_criteria_school',      $part_criteria_school);
    wachthond($extdebug,2, 'part_criteria_indicatie',   $part_criteria_indicatie);
    wachthond($extdebug,2, 'part_criteria_oordeel',     $part_criteria_oordeel);    

    // --- CRITERIA LOGICA ---

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 1.0 CORRECTIE MIXUP GROEP VS KLAS",            "[GROEPKLAS]");
    wachthond($extdebug, 2, "########################################################################");
    
    // 1. Correctie mixup Groep vs Klas
    $new_groepklas = $part_groepklas;
    
    if ($kampkort == 'kk1' OR $kampkort == 'kk2') {
        if (in_array($part_groepklas, array("klas_3"))) { $new_groepklas = 'groep_3'; }
        if (in_array($part_groepklas, array("klas_4"))) { $new_groepklas = 'groep_4'; }
        if (in_array($part_groepklas, array("klas_5"))) { $new_groepklas = 'groep_5'; }
        if (in_array($part_groepklas, array("klas_6"))) { $new_groepklas = 'groep_6'; }
    }
    if ($kampkort == 'tk1' OR $kampkort == 'tk2') {
        if (in_array($part_groepklas, array("groep_2"))) { $new_groepklas = 'klas_2'; }
        if (in_array($part_groepklas, array("groep_3"))) { $new_groepklas = 'klas_3'; }
    }
    if ($kampkort == 'jk1' OR $kampkort == 'jk2') {
        if (in_array($part_groepklas, array("groep_3"))) { $new_groepklas = 'klas_3'; }
        if (in_array($part_groepklas, array("groep_4"))) { $new_groepklas = 'klas_4'; }
        if (in_array($part_groepklas, array("groep_5"))) { $new_groepklas = 'klas_5'; }
        if (in_array($part_groepklas, array("groep_6"))) { $new_groepklas = 'klas_6'; }
    }

    if ($new_groepklas) {
        wachthond($extdebug,1, "!!! CRITERIA - MIXUP HERSTELD VOOR $kampkort VAN $part_groepklas NAAR $new_groepklas");
    }

    $criteria_school    = 'afwijkend';
    $criteria_leeftijd  = 'afwijkend';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 2.0 BEOORDELING SCHOOL",                          "[SCHOOL]");
    wachthond($extdebug, 2, "########################################################################");

    // 2. Beoordeel Schoolgroep
    if ($kampkort == 'kk1' AND in_array($new_groepklas, array("groep_3","groep_4","groep_5","groep_6","groep_7"))) { $criteria_school = 'prima'; }
    if ($kampkort == 'kk2' AND in_array($new_groepklas, array("groep_3","groep_4","groep_5","groep_6","groep_7"))) { $criteria_school = 'prima'; }
    if ($kampkort == 'bk1' AND in_array($new_groepklas, array("groep_8","klas_1")))                                { $criteria_school = 'prima'; }
    if ($kampkort == 'bk2' AND in_array($new_groepklas, array("groep_8","klas_1")))                                { $criteria_school = 'prima'; }
    if ($kampkort == 'tk1' AND in_array($new_groepklas, array("klas_2","klas_3")))                                 { $criteria_school = 'prima'; }
    if ($kampkort == 'tk2' AND in_array($new_groepklas, array("klas_2","klas_3")))                                 { $criteria_school = 'prima'; }
    if ($kampkort == 'jk1' AND in_array($new_groepklas, array("klas_4","klas_5","klas_6","vervolg")))              { $criteria_school = 'prima'; }
    if ($kampkort == 'jk2' AND in_array($new_groepklas, array("klas_4","klas_5","klas_6","vervolg")))              { $criteria_school = 'prima'; }

    // Marge Check School
    if (in_array($kampkort,array("tk1","tk2")) AND in_array($new_groepklas,array("klas_4")))                       { $criteria_school = 'marge'; }
    if (in_array($kampkort,array("jk1","jk2")) AND in_array($new_groepklas,array("klas_2","klas_3")))              { $criteria_school = 'marge'; }

    wachthond($extdebug, 3, "criteria_school",        $criteria_school);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 3.0 BEOORDELING LEEFTIJD",                      "[LEEFTIJD]");
    wachthond($extdebug, 2, "########################################################################");

    // 3. Beoordeel Leeftijd
    if ($kampkort == 'kk1' AND ($leeftijd >=  7.0 AND $leeftijd < 12.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'kk2' AND ($leeftijd >=  7.0 AND $leeftijd < 12.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'bk1' AND ($leeftijd >= 12.0 AND $leeftijd < 14.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'bk2' AND ($leeftijd >= 12.0 AND $leeftijd < 14.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'tk1' AND ($leeftijd >= 14.0 AND $leeftijd < 16.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'tk2' AND ($leeftijd >= 14.0 AND $leeftijd < 16.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'jk1' AND ($leeftijd >= 16.0 AND $leeftijd < 18.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'jk2' AND ($leeftijd >= 16.0 AND $leeftijd < 18.0)) { $criteria_leeftijd = 'prima'; }
    if ($kampkort == 'top' AND ($leeftijd >= 18.0 AND $leeftijd < 21.0)) { $criteria_leeftijd = 'prima'; }

    // Marge Check Leeftijd
    if (in_array($kampkort, array("kk1","kk2")) AND ($leeftijd >=  6.7 AND $leeftijd <   7.0)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("kk1","kk2")) AND ($leeftijd >= 12.0 AND $leeftijd <= 12.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("bk1","bk2")) AND ($leeftijd >= 11.3 AND $leeftijd <  12.0)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("bk1","bk2")) AND ($leeftijd >= 14.0 AND $leeftijd <= 14.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("tk1","tk2")) AND ($leeftijd >= 13.7 AND $leeftijd <  14.0)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("tk1","tk2")) AND ($leeftijd >= 16.0 AND $leeftijd <= 16.3)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("jk1","jk2")) AND ($leeftijd >= 15.7 AND $leeftijd <  16.0)) { $criteria_leeftijd = 'marge'; }
    if (in_array($kampkort, array("jk1","jk2")) AND ($leeftijd >= 18.0 AND $leeftijd <= 18.3)) { $criteria_leeftijd = 'marge'; }

    wachthond($extdebug, 3, "criteria_leeftijd",        $criteria_leeftijd);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CRITERIA 5.0 START BEPALING EINDOORDEEL",                 "[OORDEEL]");
    wachthond($extdebug, 2, "########################################################################");

    // Log de inkomende waarden voordat de logica start
    wachthond($extdebug, 3, "INPUT bestaand_indicatie",$part_criteria_indicatie);
    wachthond($extdebug, 3, "INPUT bestaand_oordeel",  $part_criteria_oordeel);
    wachthond($extdebug, 3, "INPUT criteria_leeftijd", $criteria_leeftijd);
    wachthond($extdebug, 3, "INPUT criteria_school",   $criteria_school);
    wachthond($extdebug, 3, "INPUT kampkort",          $kampkort);

    // --- 5.1 CRITERIA INDICATIE ---
    // Functioneel: Als een beheerder of een eerdere actie al een indicatie heeft opgeslagen, 
    // willen we die behouden. Anders laten we hem standaard afwijken.
    // Technisch: We gebruiken !empty() in plaats van ?? omdat CiviCRM een leeggemaakt veld 
    if (!empty($part_criteria_indicatie)) {
        $criteria_indicatie ??= $part_criteria_indicatie;
        wachthond($extdebug, 3, "STATUS: Bestaande indicatie behouden",     $criteria_indicatie);
    } else {
        $criteria_indicatie ??= 'criteriawijktaf';
        wachthond($extdebug, 3, "STATUS: Geen indicatie, zet default",      $criteria_indicatie);
    }

    // --- 5.2 CRITERIA OORDEEL ---
    // Functioneel: Check of er al een handmatig eindoordeel was vastgelegd door een gebruiker.
    // Zo ja, behoud deze. Zo nee, zet de default status naar 'nog nodig'.
    if (in_array($part_criteria_oordeel, ['oordeelnietnodig', 'oordeelprima', 'oordeelaangepast', 'oordeelafgewezen'])) {
        $criteria_oordeel ??= $part_criteria_oordeel;
        wachthond($extdebug, 3, "STATUS: Handmatig oordeel behouden",       $criteria_oordeel);
    } else {
        $criteria_oordeel ??= 'oordeelnognodig';
        wachthond($extdebug, 3, "STATUS: Geen geldig oordeel, default",     $criteria_oordeel);
    }

    // Check of er al een handmatig eindoordeel was vastgelegd door een gebruiker
    // Zo ja, behoud deze. Zo nee, zet de default status naar 'nog nodig'.
    if (in_array($part_criteria_oordeel, ['oordeelnietnodig', 'oordeelprima', 'oordeelaangepast', 'oordeelafgewezen'])) {
        $criteria_oordeel ??= $part_criteria_oordeel;
        wachthond($extdebug, 3, "STATUS: Handmatig oordeel behouden",       $criteria_oordeel);
    } else {
        $criteria_oordeel ??= 'oordeelnognodig';
        wachthond($extdebug, 3, "STATUS: Geen geldig oordeel, default",     $criteria_oordeel);
    }

    // Prima situaties
    if ($criteria_leeftijd == 'prima' && $criteria_school == 'prima') {
        $criteria_indicatie ??= 'criteriaprima';
        $criteria_oordeel   ??= 'oordeelnietnodig';
        wachthond($extdebug, 3, "BRANCH: Alles prima",                      "Hit");
    } 
    // Marge situaties
    elseif ($criteria_leeftijd == 'marge' || $criteria_school == 'marge') {
        $criteria_indicatie ??= 'binnenmarges';
        $criteria_oordeel   ??= 'oordeelnietnodig';
        wachthond($extdebug, 3, "BRANCH: Binnen marges",                    "Hit");
    }
    // Specifieke uitzonderingen voor afwijkingen
    elseif ($criteria_leeftijd == 'afwijkend' && $criteria_school != 'afwijkend') {
        $criteria_indicatie ??= 'leeftijdwijktaf';
        wachthond($extdebug, 3, "BRANCH: Alleen leeftijd wijkt af",         "Hit");
    }
    elseif ($criteria_leeftijd != 'afwijkend' && $criteria_school == 'afwijkend') {
        $criteria_indicatie ??= 'schoolwijktaf';
        wachthond($extdebug, 3, "BRANCH: Alleen school wijkt af",           "Hit");
    }

    // Extra Logica 3.1, 3.2, 3.3
    if ($criteria_leeftijd == 'marge' AND $criteria_school == 'prima' AND in_array($kampkort, array("kk1","kk2","bk1","bk2","tk1","tk2"))) {
        $criteria_indicatie = 'binnenmarges'; 
        $criteria_oordeel   = 'oordeelnietnodig';
        wachthond($extdebug, 3, "EXTRA LOGICA: Marge leeftijd specifiek kamp", "Hit");
    }
    if ($criteria_leeftijd == 'prima' AND $criteria_school == 'marge' AND in_array($kampkort, array("jk1","jk2"))) {
        $criteria_indicatie = 'binnenmarges'; 
        $criteria_oordeel   = 'oordeelnietnodig';
        wachthond($extdebug, 3, "EXTRA LOGICA: Marge school jongerenkamp",     "Hit");
    }
    if ($kampkort == 'top' AND $criteria_leeftijd == 'prima') {
        $criteria_school    = 'prima';
        $criteria_indicatie = 'criteriaprima';
        $criteria_oordeel   = 'oordeelnietnodig';
        wachthond($extdebug, 3, "EXTRA LOGICA: Topkamp leeftijd prima",        "Hit");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 2, "### CRITERIA 5.0 RESULTAAT BEPALING EINDOORDEEL");
    wachthond($extdebug, 2, "########################################################################");
    
    // Log de uiteindelijke uitgaande waarden
    wachthond($extdebug, 2, "FINAL criteria_indicatie",    $criteria_indicatie);
    wachthond($extdebug, 2, "FINAL criteria_oordeel",      $criteria_oordeel);

    // Gebruik van een Smart Guard om oneindige lussen te voorkomen, 
    // maar wel updates toe te staan als de berekende waarden wijzigen.
    static $processing_criteria = [];

    // Unieke vingerafdruk van de huidige berekening
    $current_state_key = md5($criteria_leeftijd . $criteria_school . $criteria_indicatie . $criteria_oordeel);

    // 1. Update Contactgegevens (Tab Dit Jaar)
    if ($contact_id && ($processing_criteria['c_' . $contact_id] ?? '') !== $current_state_key) {
        
        $processing_criteria['c_' . $contact_id] = $current_state_key;

        $params_contact = [
            'checkPermissions' => FALSE,
            'reload'           => FALSE,
            'where'            => [['id', '=', $contact_id]],
            'values'           => [
                'DITJAAR.ditjaar_leeftijd'           => $criteria_leeftijd,
                'DITJAAR.ditjaar_school'             => $criteria_school,
                'DITJAAR.ditjaar_criteria_indicatie' => $criteria_indicatie,
                'DITJAAR.ditjaar_criteria_oordeel'   => $criteria_oordeel,
            ]
        ];
        
        wachthond($extdebug, 3, 'params_contact',            $params_contact);
        $result_contact = civicrm_api4('Contact', 'update',  $params_contact);
        wachthond($extdebug, 9, 'result_contact',            $result_contact);
    }

    // 2. Update Deelnemergegevens (Tab Deelname Intern)
    if ($part_id && ($processing_criteria['p_' . $part_id] ?? '') !== $current_state_key) {
        
        $processing_criteria['p_' . $part_id] = $current_state_key;

        $params_part = [
            'checkPermissions' => FALSE,
            'reload'           => FALSE,
            'where'            => [['id', '=', $part_id]],
            'values'           => [
                'PART_DEEL_INTERN.criteria_leeftijd'  => $criteria_leeftijd,
                'PART_DEEL_INTERN.criteria_school'    => $criteria_school,
                'PART_DEEL_INTERN.criteria_indicatie' => $criteria_indicatie,
                'PART_DEEL_INTERN.criteria_oordeel'   => $criteria_oordeel,
            ]
        ];
        
        // Optioneel: Herstelde Groep/Klas opslaan
        if ($new_groepklas) {
            $params_part['values']['PART_DEEL.Groep_klas'] = $new_groepklas;
        }

        wachthond($extdebug, 3, 'params_part',               $params_part);
        $result_part = civicrm_api4('Participant', 'update', $params_part);
        wachthond($extdebug, 9, 'result_part',               $result_part);
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
    wachthond($extdebug,1, "### CRITERIA - 6.0 RETURN LEEFTIJD_CRTITERIA_ARRAY", $leeftijd_criteria_array);
    wachthond($extdebug,3, "########################################################################");

    return $leeftijd_criteria_array;
}

function leeftijd_civicrm_status($array_partditevent, $array_criteria = NULL) {

    $extdebug = 0;
    
    if (!is_array($array_partditevent)) return;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.1 BEPAAL STATUS REGISTRATIE & DEELNAME",  "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // Data ophalen basis
    $ditevent_part_id           = $array_partditevent['id']                     ?? NULL;
    $contact_id                 = $array_partditevent['contact_id']             ?? NULL;
    $ditevent_part_status_id    = $array_partditevent['status_id']              ?? NULL;
    $ditevent_part_rol          = $array_partditevent['part_rol']               ?? NULL;
    $ditevent_register_date     = $array_partditevent['register_date']          ?? NULL;

    // Veldnamen zoals verzocht
    $part_criteria_leeftijd     = $array_partditevent['criteria_leeftijd']      ?? NULL;
    $part_criteria_school       = $array_partditevent['criteria_school']        ?? NULL;
    $part_criteria_indicatie    = $array_partditevent['criteria_indicatie']     ?? NULL;
    $part_criteria_oordeel      = $array_partditevent['criteria_oordeel']       ?? NULL;

    wachthond($extdebug, 2, 'part_criteria_leeftijd',    $part_criteria_leeftijd);
    wachthond($extdebug, 2, 'part_criteria_school',      $part_criteria_school);
    wachthond($extdebug, 2, 'part_criteria_indicatie',   $part_criteria_indicatie);
    wachthond($extdebug, 2, 'part_criteria_oordeel',     $part_criteria_oordeel);    

    $part_wachtlijst_erop       = $array_partditevent['wachtlijst_erop']        ?? NULL;
    $part_wachtlijst_eraf       = $array_partditevent['wachtlijst_eraf']        ?? NULL;
    $part_criteriacheck_start   = $array_partditevent['criteriacheck_start']    ?? NULL;
    $part_criteriacheck_einde   = $array_partditevent['criteriacheck_einde']    ?? NULL;

    wachthond($extdebug, 3, 'part_wachtlijst_erop',      $part_wachtlijst_erop);
    wachthond($extdebug, 3, 'part_wachtlijst_eraf',      $part_wachtlijst_eraf);
    wachthond($extdebug, 3, 'part_criteriacheck_start',  $part_criteriacheck_start);
    wachthond($extdebug, 3, 'part_criteriacheck_einde',  $part_criteriacheck_einde);

    // Bepaal actuele criteria (nieuw berekend indien meegegeven, anders behoud bestaande)
    $actueel_criteria_leeftijd  = $array_criteria['criteria_leeftijd']  ?? $part_criteria_leeftijd;
    $actueel_criteria_school    = $array_criteria['criteria_school']    ?? $part_criteria_school;
    $actueel_criteria_indicatie = $array_criteria['criteria_indicatie'] ?? $part_criteria_indicatie;
    $actueel_criteria_oordeel   = $array_criteria['criteria_oordeel']   ?? $part_criteria_oordeel;

    wachthond($extdebug, 2, 'actueel_criteria_leeftijd',    $actueel_criteria_leeftijd);
    wachthond($extdebug, 2, 'actueel_criteria_school',      $actueel_criteria_school);
    wachthond($extdebug, 2, 'actueel_criteria_indicatie',   $actueel_criteria_indicatie);
    wachthond($extdebug, 2, 'actueel_criteria_oordeel',     $actueel_criteria_oordeel);    

    // Nieuwe waarden initialiseren met de ORIGINELE waarden om historie te behouden
    $new_status_id              = $ditevent_part_status_id;
    $new_status_label           = NULL;
    $new_wachtlijst_erop        = $part_wachtlijst_erop;
    $new_wachtlijst_eraf        = $part_wachtlijst_eraf;
    $new_criteriacheck_start    = $part_criteriacheck_start;
    $new_criteriacheck_einde    = $part_criteriacheck_einde;

    wachthond($extdebug, 3, 'new_wachtlijst_erop',      $new_wachtlijst_erop);
    wachthond($extdebug, 3, 'new_wachtlijst_eraf',      $new_wachtlijst_eraf);
    wachthond($extdebug, 3, 'new_criteriacheck_start',  $new_criteriacheck_start);
    wachthond($extdebug, 3, 'new_criteriacheck_einde',  $new_criteriacheck_einde);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.2 BEREKEN NIEUWE STATUS & DATUMS",      "[LOGICA]");
    wachthond($extdebug, 2, "########################################################################");

    // Correctie van onbekende status (0, 5, 6) naar geregistreerd of wacht op goedkeuring
    if (in_array($ditevent_part_status_id, [0, 5, 6]) && $ditevent_part_rol == 'deelnemer' && empty($part_wachtlijst_erop)) {

        if ($actueel_criteria_indicatie == 'criteriaprima') {
            $new_status_id              = 1; // Registered
            $new_status_label           = 'Bevestigd';
            wachthond($extdebug, 4, "LOGICA: Onbekende status -> Bevestigd (Prima)", "Hit");
        } elseif ($actueel_criteria_indicatie == 'criteriawijktaf' && $actueel_criteria_oordeel != 'buitencriteria') {
            $new_status_id              = 8; // Awaiting approval
            $new_status_label           = 'Criteriacheck';
            // Startdatum alleen zetten als hij nog niet bestond
            if (empty($new_criteriacheck_start)) {
                $new_criteriacheck_start    = $ditevent_register_date;
                wachthond($extdebug, 4, "LOGICA: Onbekende status -> Zet Criteriacheck start", "Hit");
            }
            wachthond($extdebug, 4, "LOGICA: Onbekende status -> Criteriacheck", "Hit");
        }
    }

    // Forceer status 1 naar Bevestigd
    if ($ditevent_part_status_id == 1) {
        $new_status_label           = 'Bevestigd';
        wachthond($extdebug, 4, "LOGICA: Behoud status Bevestigd", "Hit");
    }
    
    // Status Wachtlijst (7)
    if ($ditevent_part_status_id == 7) {
        $new_status_label           = 'Wachtlijst';
        // Enkel overschrijven als er nog geen eerdere wachtlijst_erop datum stond
        if (empty($new_wachtlijst_erop)) {
            $new_wachtlijst_erop        = $ditevent_register_date; 
            wachthond($extdebug, 4, "LOGICA: Wachtlijst -> Zet wachtlijst_erop datum", "Hit");
        }
        wachthond($extdebug, 4, "LOGICA: Wachtlijst status herkend", "Hit");
    }

    // Status Voorheen Wachtlijst (9)
    if ($ditevent_part_status_id == 9) {
        $new_status_label           = 'Afwachting';
        if (empty($new_wachtlijst_eraf)) {
            $new_wachtlijst_eraf        = date("Y-m-d H:i:s");
            wachthond($extdebug, 4, "LOGICA: Afwachting -> Zet wachtlijst_eraf datum", "Hit");
        }
        wachthond($extdebug, 4, "LOGICA: Afwachting status herkend", "Hit");
    }

    // Leiding altijd bevestigd (tenzij geannuleerd)
    if ($ditevent_part_rol == 'leiding' && $ditevent_part_status_id != 4) {
        $new_status_id              = 1;
        $new_status_label           = 'Bevestigd';
        wachthond($extdebug, 4, "LOGICA: Leiding (niet geannul) -> Bevestigd", "Hit");
    }

    // Criteriacheck Start/Einde Datum Logica
    if ($actueel_criteria_oordeel == 'oordeelnietnodig') {
        $new_criteriacheck_start    = "";
        $new_criteriacheck_einde    = "";
        wachthond($extdebug, 4, "LOGICA: Oordeel niet nodig -> Wis check datums", "Hit");
    }
    if ($actueel_criteria_oordeel == 'oordeelnognodig' || $new_status_id == 8) {
        if (empty($new_criteriacheck_start)) {
            $new_criteriacheck_start    = $ditevent_register_date;
            wachthond($extdebug, 4, "LOGICA: Oordeel nog nodig -> Zet startdatum", "Hit");
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LEEFTIJD STATUS 1.3 UPDATES UITVOEREN",                 "[UPDATES]");
    wachthond($extdebug, 2, "########################################################################");

    // ==============================================================================================
    // 3. UPDATE PARTICIPANT
    // ==============================================================================================
    
    $updates_part = [];

    // Update de status en het label indien gewijzigd
    if ($new_status_id            != $ditevent_part_status_id)  $updates_part['status_id']                  = $new_status_id;
    if ($new_status_label)                                      $updates_part['PART.deelnamestatus:label']  = $new_status_label;

    // Gebruik de smart helper voor de interne deelname-velden (datums)
    if ($new_wachtlijst_erop      !== $part_wachtlijst_erop)    {
        $updates_part['PART_DEEL_INTERN.wachtlijst_erop']     = format_civicrm_smart($new_wachtlijst_erop,     'PART_DEEL_INTERN.wachtlijst_erop');
    }
    if ($new_wachtlijst_eraf      !== $part_wachtlijst_eraf)    {
        $updates_part['PART_DEEL_INTERN.wachtlijst_eraf']     = format_civicrm_smart($new_wachtlijst_eraf,     'PART_DEEL_INTERN.wachtlijst_eraf');
    }
    if ($new_criteriacheck_start  !== $part_criteriacheck_start) {
        $updates_part['PART_DEEL_INTERN.criteriacheck_start'] = format_civicrm_smart($new_criteriacheck_start, 'PART_DEEL_INTERN.criteriacheck_start');
    }
    if ($new_criteriacheck_einde  !== $part_criteriacheck_einde) {
        $updates_part['PART_DEEL_INTERN.criteriacheck_einde'] = format_civicrm_smart($new_criteriacheck_einde, 'PART_DEEL_INTERN.criteriacheck_einde');
    }

    if ($ditevent_part_id && !empty($updates_part)) {
        
        // SMART GUARD: Voorkomt lussen, maar staat updates toe als de data (bijv. status) wijzigt.
        static $processing_status = [];
        $part_state_key           = md5(serialize($updates_part));

        if (($processing_status['p_' . $ditevent_part_id] ?? '') !== $part_state_key) {
            
            $processing_status['p_' . $ditevent_part_id] = $part_state_key;

            $params_part = [
                'checkPermissions' => FALSE,
                'where'            => [['id', '=', $ditevent_part_id]],
                'values'           => $updates_part,
            ];
            
            wachthond($extdebug, 7, 'params_part',               $params_part);
            $result_part = civicrm_api4('Participant', 'update', $params_part);
            wachthond($extdebug, 9, 'result_part',               $result_part);

            wachthond($extdebug, 2, "STATUS UPDATED PART",       "$new_status_id ($new_status_label)");
        } else {
            wachthond($extdebug, 3, "STATUS UPDATED PART",       "Overgeslagen (Smart Guard): Geen nieuwe wijzigingen");
        }
    }

    // ==============================================================================================
    // 4. UPDATE CONTACT
    // ==============================================================================================
    
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
        
        // SMART GUARD: Gebruikt dezelfde static array voor consistentie.
        $cont_state_key = md5(serialize($updates_contact));

        if (($processing_status['c_' . $contact_id] ?? '') !== $cont_state_key) {
            
            $processing_status['c_' . $contact_id] = $cont_state_key;

            $params_contact = [
                'checkPermissions' => FALSE,
                'reload'           => FALSE,
                'where'            => [['id', '=', $contact_id]],
                'values'           => $updates_contact,
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

    return [
        'status_id'               => $new_status_id,
        'status_label'            => $new_status_label,
        'ditevent_deelnamestatus' => $new_status_label,
        'criteriacheck_start'     => $new_criteriacheck_start,
        'criteriacheck_einde'     => $new_criteriacheck_einde,
        'wachtlijst_erop'         => $new_wachtlijst_erop,
        'wachtlijst_eraf'         => $new_wachtlijst_eraf
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