<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.leeftijd.php
 * =======================================================================================
 *   partstatus_leeftijd_diff()       FUNCTIONEEL: Berekent het exacte leeftijdsverschil tussen een geboo...
 *   partstatus_leeftijd_configure()  FUNCTIONEEL: Berekent leeftijden op drie peilmomenten (Vandaag, Dit...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Leeftijd Calculator)
 * FUNCTIONEEL: Berekent het exacte leeftijdsverschil tussen een geboortedatum en een peildatum.
 * TECHNISCH: Vertaalt standaard DateTime verschillen naar een decimaal formaat (bijv. 12.5 jaar).
 * OPTIMALISATIE: Maakt gebruik van Static Caching in het RAM om dubbele datumberekeningen te voorkomen.
 * @param string $job           Label voor de context (bijv. 'vandaag', 'ditevent', 'nextkamp') voor logging
 * @param string $birthdate     De geboortedatum in Y-m-d formaat
 * @param string $vergelijk     De peildatum waartegen we rekenen in Y-m-d formaat
 * @return array|null           Array met afgeronde en decimale leeftijden, of NULL bij fouten
 */
function partstatus_leeftijd_diff($job, $birthdate, $vergelijk) {

	$extdebug = 0; // Zet het debug-niveau op 1 (verhoog naar 3 voor RAM-cache details)

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### PARTSTATUS LEEFTIJD 1.0 - CACHE & VALIDATIE",				  "[$job]");
	wachthond($extdebug, 2, "########################################################################");

	static $static_age_cache = []; 
	$cache_key 				 = $birthdate . '_' . $vergelijk; 

	// RAM CACHE CHECK
	if (isset($static_age_cache[$cache_key])) { 
		wachthond($extdebug, 3, "Leeftijd reeds berekend voor deze match. Direct hergebruik uit RAM", 	"[HIT]"); 
		return $static_age_cache[$cache_key]; 
	} 

	// VALIDATIE
	if (empty($birthdate) || empty($vergelijk)) { 
		wachthond($extdebug, 3, "Berekening afgebroken wegens ontbrekende birthdate of peildatum", 		"[ABORT]"); 
		return NULL; 
	} 

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### PARTSTATUS LEEFTIJD 2.0 - DATUM BEREKENING",			   "[REKENEN]");
	wachthond($extdebug, 2, "########################################################################");

	wachthond($extdebug, 3, "Nieuwe berekening gestart voor $birthdate t.o.v. peildatum", "[$vergelijk]"); 

	$date_birth 			= new DateTime($birthdate);
	$date_ref   			= new DateTime($vergelijk);
	$diff       			= $date_birth->diff($date_ref); 
	
	$diffyears  			= $diff->y; 
	$diffmonths 			= $diff->m; 

	wachthond($extdebug, 4, "Exact tijdsverschil in kalenderjaren en maanden", "[$diffyears jr, $diffmonths mnd]"); 

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### PARTSTATUS LEEFTIJD 3.0 - AFRONDING & UITVOER",			  "[DONE]");
	wachthond($extdebug, 2, "########################################################################");

	// Omzetting naar decimaal (bijv 10 jaar en 11 maanden wordt 10.9)
	$diffmonths_dec 		= round(($diffmonths / 12 * 10), 0); 
	
	$leeftijd_decimalen 	= (float)($diffyears . "." . $diffmonths_dec); 
	$leeftijd_rondjaren 	= (int)$diffyears; 
	$leeftijd_rondmaand 	= (int)round((($leeftijd_decimalen - $leeftijd_rondjaren) * 12), 0); 

	$leeftijd_return = [ 
		'leeftijd_birthdate'    => $birthdate, 
		'leeftijd_refdate'      => $vergelijk, 
		'leeftijd_decimalen'    => $leeftijd_decimalen, 
		'leeftijd_rondjaren'    => $leeftijd_rondjaren, 
		'leeftijd_rondmaand'    => $leeftijd_rondmaand, 
	]; 

	// De finale log-output conform jouw kolom-uitlijning
	wachthond($extdebug, 1, "Berekende decimale leeftijd voor taak: $job", "[$leeftijd_decimalen]"); 

	$static_age_cache[$cache_key] = $leeftijd_return; 

	return $leeftijd_return; 
}

/**
 * MODULE: PARTSTATUS (Leeftijd Configuratie)
 * FUNCTIONEEL: Berekent leeftijden op drie peilmomenten (Vandaag, Dit Event, Next Kamp).
 *              Optioneel: schrijft WERVING-velden naar Contact/Participant wanneer een matching $groupID is meegegeven.
 * @param array|null  $array_part   Participant data (verwacht: birth_date, contact_id, id, event_start_date)
 * @param string|null $basedate     Peildatum voor "vandaag" (default = Y-m-d). Hiermee bereken je leeftijd op een willekeurige datum.
 * @param string|null $groupID      Profiel ID; alleen bij ["149","225","139","190"] (Contact) of ["139","190"] (Participant) worden writes uitgevoerd. NULL = alleen rekenen.
 * @param string      $job          Context label voor logging en write-conditie (default 'event')
 * @return array                    ['today' => array|null, 'event' => array|null, 'next' => array|null]
 */
function partstatus_leeftijd_configure($array_part = NULL, $basedate = NULL, $groupID = NULL, $job = 'event') {

	$extdebug = 0;

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS AGE 1.0 - DATA & DATUMS VERZAMELEN",			 "[START]");
	wachthond($extdebug, 2, "########################################################################");

	$basedate				= $basedate ?: date("Y-m-d");
	$contact_id				= $array_part['contact_id']			?? NULL;
	$birthdate				= $array_part['birth_date']			?? NULL;
	$ditevent_part_id		= $array_part['id']					?? NULL;
	$ditevent_event_start	= $array_part['event_start_date']	?? NULL;

	$today_lastnext 		= (function_exists('find_lastnext')) ? find_lastnext($basedate) : ['next_start_date' => NULL];
	$datumnextkamp			= $today_lastnext['next_start_date'];

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS AGE 2.0 - LEEFTIJDEN BEREKENEN",				  "[CALC]");
	wachthond($extdebug, 2, "########################################################################");

	$age_today		= partstatus_leeftijd_diff('vandaag',  $birthdate, $basedate);
	$age_event		= partstatus_leeftijd_diff('ditevent', $birthdate, $ditevent_event_start);
	$age_next		= partstatus_leeftijd_diff('nextkamp', $birthdate, $datumnextkamp);

	wachthond($extdebug, 4, "Resultaat: Vandaag (" . ($age_today['leeftijd_decimalen'] ?? '-') . ") | Event (" . ($age_event['leeftijd_decimalen'] ?? '-') . ") | Next (" . ($age_next['leeftijd_decimalen'] ?? '-') . ")", "[DONE]");

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS AGE 3.0 - DATA VOORBEREIDEN",					  "[PREP]");
	wachthond($extdebug, 2, "########################################################################");

	$inject_cont = [];
	$inject_part = [];

	if ($groupID !== NULL && in_array($groupID, ["149", "225", "139", "190"])) {
		wachthond($extdebug, 3, "Profiel matcht WERVING update eisen. Bepalen peildatum", "[MATCH]");

		$source = ($age_event && $ditevent_event_start && $datumnextkamp && date("Y", strtotime($ditevent_event_start)) == date("Y", strtotime($datumnextkamp)))
				  ? $age_event
				  : $age_next;

		if ($source) {
			$inject_cont['WERVING.nextkamp_decimalen'] = format_civicrm_smart($source['leeftijd_decimalen'], 'WERVING.nextkamp_decimalen');
			$inject_cont['WERVING.nextkamp_rondjaren'] = format_civicrm_smart($source['leeftijd_rondjaren'], 'WERVING.nextkamp_rondjaren');
			$inject_cont['WERVING.nextkamp_rondmaand'] = format_civicrm_smart($source['leeftijd_rondmaand'], 'WERVING.nextkamp_rondmaand');
			wachthond($extdebug, 4, "Contact WERVING velden succesvol geprepareerd", "[OK]");
		}
	} else {
		wachthond($extdebug, 4, "Contact update overgeslagen (geen of niet relevant Profiel)", "[SKIP]");
	}

	if ($job == 'event' && $groupID !== NULL && in_array($groupID, ["139", "190"]) && $ditevent_part_id) {
		$inject_part['PART.nextkamp_decimalen'] = format_civicrm_smart($age_event['leeftijd_decimalen'] ?? 0, 'PART.nextkamp_decimalen');
		wachthond($extdebug, 4, "Participant PART velden succesvol geprepareerd", "[OK]");
	} else {
		wachthond($extdebug, 4, "Participant update overgeslagen (Profiel/Job matcht niet)", "[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS AGE 4.0 - OPSLAAN VIA API WRAPPER",				  "[EXEC]");
	wachthond($extdebug, 2, "########################################################################");

	if ($job == 'event' && !empty($inject_cont) && $contact_id) {
		base_api_wrapper('Contact', $contact_id, $inject_cont, "PARTSTATUS_AGE_CONT");
		wachthond($extdebug, 3, "Contact (Werving) leeftijden succesvol weggeschreven", 			"[SUCCESS]");
	} else {
		wachthond($extdebug, 4, "Geen Contact updates uitgevoerd (Geen data of job was geen event)","[SKIP]");
	}

	if (!empty($inject_part) && $ditevent_part_id) {
		base_api_wrapper('Participant', $ditevent_part_id, $inject_part, "PARTSTATUS_AGE_PART");
		wachthond($extdebug, 3, "Participant leeftijden succesvol weggeschreven", 					"[SUCCESS]");
	} else {
		wachthond($extdebug, 4, "Geen Participant updates uitgevoerd (Geen wijzigingen)", 			"[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS AGE 5.0 - BEREKENING VOLTOOID",					  "[DONE]");
	wachthond($extdebug, 2, "########################################################################");

	return [
		'today'		=> $age_today,
		'event'		=> $age_event,
		'next'		=> $age_next,
	];
}