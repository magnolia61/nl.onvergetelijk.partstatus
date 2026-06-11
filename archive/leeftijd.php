<?php

#error_reporting(E_ALL);
#ini_set('display_errors', TRUE);
#ini_set('display_startup_errors', TRUE);

require_once 'leeftijd.civix.php';

/**
 * Implementation of hook_civicrm_custom
 *
 * This is needed only if there is a computed (View Only) custom field in this set.
 */

function leeftijd_civicrm_validateprofile($profileName)
{

		$extdebug            = 0;
		$processkampleeftijd = 1;

		if ($profileName === 'BEHEER_GEBOORTEDATUM_101'  or
			$profileName === 'Verjaardag_en_geslacht_68' or
			$profileName === 'Verjaardag_en_geslacht_97' or
			$profileName === 'Verjaardag_en_geslacht_66' or
			$profileName === 'Verjaardag_en_geslacht_67' or
			$profileName === 'Verjaardag_en_geslacht_99' or
			$profileName === 'Verjaardag_en_geslacht_19'
		) {
				wachthond($extdebug,3, "########################################################################");
				wachthond($extdebug,1, "### START KAMPLEEFTIJD (VAL)", "$displayname");
				wachthond($extdebug,3, "########################################################################");

				wachthond($extdebug,1, 'job', 								$job);
				wachthond($extdebug,1, 'validateprofile: profile_name:', 	$profileName);
				wachthond($extdebug,1, 'processkampleeftijd', 				$processkampleeftijd);

				$processkampleeftijd = 1;

				wachthond($extdebug,1, 'id', 								$id);
				wachthond($extdebug,1, 'gid', 								$gid);
				wachthond($extdebug,1, 'group_id', 							$group_id);
				wachthond($extdebug,1, 'entity_id', 						$entity_id);

				wachthond($extdebug,3, "########################################################################");
				wachthond($extdebug,1, "### EINDE KAMPLEEFTIJD (VAL)", "$displayname");
				wachthond($extdebug,3, "########################################################################");

		}
}

function leeftijd_civicrm_diff($job, $birthdate, $vergelijk)
{
	####################################################################################################################
	# BEREKEN DE LEEFTIJD TOV BEPAALDE DATUM
	####################################################################################################################

	// 0. Static Cache: Onthoud berekeningen binnen ditzelfde request.
	static $static_age_cache = [];
	$cache_key = $birthdate . '_' . $vergelijk;

	if (isset($static_age_cache[$cache_key])) {
		return $static_age_cache[$cache_key];
	}

	if (empty($birthdate) OR empty($vergelijk)) {
		return NULL;
	}

	$extdebug = 0;

	// 1. Gebruik DateTime objecten (efficiënter dan strtotime en date_create)
	$date_birth = new DateTime($birthdate);
	$date_ref   = new DateTime($vergelijk);
	
	// 2. Bereken het verschil
	$diff = $date_birth->diff($date_ref);
	
	$diffyears  = $diff->y;
	$diffmonths = $diff->m;

	// 3. Bereken decimalen (maanden naar fractie van 10)
	$diffmonths_dec = round(($diffmonths / 12 * 10), 0);
	$leeftijd_decimalen = $diffyears . "." . $diffmonths_dec;
	
	$leeftijd_rondjaren = $diffyears;
	
	// Bereken de 'rondmaand' (terugrekenen van decimaal naar 12-maands schaal)
	$leeftijd_rondmaand = round((((float)$leeftijd_decimalen - $leeftijd_rondjaren) * 12), 0);

	// 4. Debug logging (Severity naar 4 gezet voor performance in productie)
	wachthond($extdebug, 4, 'job',           $job);
	wachthond($extdebug, 4, 'birthdate',     $birthdate); 
	wachthond($extdebug, 4, 'vergelijk',     $vergelijk); 
	wachthond($extdebug, 4, 'leeftijd_dec',  $leeftijd_decimalen); 

	$leeftijd_return = array(
		'leeftijd_birthdate' => $birthdate,
		'leeftijd_vergelijk' => $vergelijk,
		'leeftijd_decimalen' => (float)$leeftijd_decimalen,
		'leeftijd_rondjaren' => (int)$leeftijd_rondjaren,
		'leeftijd_rondmaand' => (int)$leeftijd_rondmaand,
	);

	// 5. Sla op in de statische cache voor hergebruik bij volgende aanroep
	$static_age_cache[$cache_key] = $leeftijd_return;

	return $leeftijd_return;
}


function leeftijd_civicrm_custom($op, $groupID, $entityID)
{
	$extdebug = 0;

	if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?

		wachthond($extdebug,1, "EXIT: op != create OR op != edit");
		return; 							//    if not, get out of here
	}

	if (in_array($groupID, array("13900000000000000000000"))) { // CV & PART

		// 101  EVENT KENMERKEN
		// 139	PART DEEL
		// 190	PART LEID
		// 140	PART LEID VOG
		// 106	TAB  WERVING
		// 103	TAB  CURRICULUM
		// 149  TAB  TALENT
		// 150	TAB  PROMOTIE
		// 165	PART REFERENTIE
		// 213  PART REF
		// 205  PART 
		// 225  JAAROVERZICHT

		$job = 'event';

		#$result = leeftijd_configure($op, $groupID, $entityID, NULL);

		wachthond($extdebug,1, "--- START KAMPLEEFTIJD STANDALONE ---");
		wachthond($extdebug,1, "op", 						$op);
		wachthond($extdebug,1, "groupID",					$groupID);
		wachthond($extdebug,1, "entityID",					$entityID);
		wachthond($extdebug,1, "processkampleeftijd",		$processkampleeftijd);
		wachthond($extdebug,1, "--- EINDE KAMPLEEFTIJD STANDALONE ---");

	#} elseif ($processkampleeftijd == 1) {

	#	$result = leeftijd_configure($op, $groupID, $entityID, NULL);
	#	($extdebug,1, "groupID",					$groupID);

	} else {

//		wachthond($extdebug,1, "civicrm_custom",			$groupID);
//		wachthond($extdebug,1, "set_processkampleeftijd",	$processkampleeftijd);

		return; // if not, get out of here
	}
}

// M61: TODO CHECKEN OF LEEFTIJD_CONFIGURE NOG WEL ERGENS GEBRUIKT WORDT
// VERANDER DIT WELLICHT IN EEN WRITE TO DB FUNCTIE

function leeftijd_configure($job, $groupID, $entityID, $basedate, $array_partditevent = NULL)
{
	$extdebug = 0;
	#if ($basedate) { $extdebug = 0; } else { $extdebug = 1; }

	wachthond($extdebug,1, "*** 1. STARTKAMPLEEFTIJD PROCESS", 		"[groupID: $groupID] [op: $op] [entityID: $entityID]");

	wachthond($extdebug,1, "job", 		$job);
	wachthond($extdebug,1, "groupID", 	$groupID);
	wachthond($extdebug,1, "entityID", 	$entityID);
	wachthond($extdebug,1, "basedate", 	$basedate);

	// OPTIMALISATIE: Gebruik de nieuwe find_fiscalyear() die gebruik maakt van Static Caching.
	// Dit vervangt de losse Civi::cache()->get() calls die voorheen database hits veroorzaakten.
	$fiscal_data = find_fiscalyear();
	$cache_fiscalyear_start = $fiscal_data['today_start'];
	$cache_fiscalyear_end 	= $fiscal_data['today_einde'];
	wachthond($extdebug,1, "cache_fiscalyear_start",	$cache_fiscalyear_start);
	wachthond($extdebug,1, "cache_fiscalyear_end", 		$cache_fiscalyear_end);

	$groepklas			    = NULL;
	$partstatusnot 			= array(4,11,17,26);	//	PARTICIPANT STATUS BETEKENT DEELNAME = NO 
													//	(GECANCELLED, NIET GOEDGEKEURD OF OVERGEDRAGEN)
	$todaydatetime			= date("Y-m-d");

	$displayname 							= $array_partditevent['displayname'] 							?? NULL;
	$contact_id 							= $array_partditevent['contact_id'] 							?? NULL;

    $ditevent_part_contact_id 				= $array_partditevent['contact_id']								?? NULL;
    $ditevent_part_eventid 					= $array_partditevent['event_id']								?? NULL;
    $ditevent_part_id   					= $array_partditevent['id'] 									?? NULL;
    $ditevent_part_role_id     				= $array_partditevent['role_id']								?? NULL;
    $ditevent_part_status_id 				= $array_partditevent['status_id']								?? NULL;
    $ditevent_part_status_name  			= $array_partditevent['status_name']							?? NULL;

   	$ditevent_register_date     			= $array_partditevent['register_date']							?? NULL;
    $ditevent_event_start 					= $array_partditevent['event_start_date']						?? NULL;
    $ditevent_event_einde 					= $array_partditevent['event_end_date']							?? NULL;

    $ditevent_event_kampnaam   				= $array_partditevent['kenmerken_kampnaam']						?? NULL;
    $ditevent_event_kampkort 				= $array_partditevent['kenmerken_kampkort']						?? NULL;

	$ditevent_part_functie              	= $array_partditevent['part_functie']                   		?? NULL;
    $ditevent_part_rol                  	= $array_partditevent['part_rol']                       		?? NULL;

	$ditevent_wachtlijst_erop				= $array_partditevent['part_wachtlijst_erop'] 					?? NULL;
	$ditevent_wachtlijst_eraf				= $array_partditevent['part_wachtlijst_eraf'] 					?? NULL;
	$ditevent_criteriacheck_start 			= $array_partditevent['part_criteriacheck_start'] 				?? NULL;
	$ditevent_criteriacheck_einde 			= $array_partditevent['part_criteriacheck_einde'] 				?? NULL;

	$ditevent_criteria_leeftijd 			= $array_partditevent['part_criteria_leeftijd'] 				?? NULL;
	$ditevent_criteria_school 				= $array_partditevent['part_criteria_school'] 					?? NULL;
	$ditevent_criteria_indicatie 			= $array_partditevent['part_criteria_indicatie'] 				?? NULL;
	$ditevent_criteria_oordeel 				= $array_partditevent['part_criteria_oordeel'] 					?? NULL;

    wachthond($extdebug,3, 'contact_id',           				$contact_id);
    wachthond($extdebug,3, 'ditevent_contact_id',           	$ditevent_part_contact_id);

    wachthond($extdebug,3, 'ditevent_event_kampnaam',       	$ditevent_event_kampnaam);
    wachthond($extdebug,3, 'ditevent_event_kampkort',       	$ditevent_event_kampkort);

    wachthond($extdebug,3, 'ditevent_register_date',      		$ditevent_register_date);
    wachthond($extdebug,3, 'ditevent_event_start',          	$ditevent_event_start);
    wachthond($extdebug,3, 'ditevent_event_einde',          	$ditevent_event_einde);
    wachthond($extdebug,3, 'ditevent_kampjaar',             	$ditevent_kampjaar);    

    wachthond($extdebug,3, 'ditevent_part_id',              	$ditevent_part_id);
    wachthond($extdebug,2, 'ditevent_part_eventid',         	$ditevent_part_eventid);
    wachthond($extdebug,3, 'ditevent_part_status_id',       	$ditevent_part_status_id);
    wachthond($extdebug,3, 'ditevent_part_status_name',     	$ditevent_part_status_name);

	wachthond($extdebug,2, 'ditevent_part_functie',				$ditevent_part_functie);
    wachthond($extdebug,2, 'ditevent_part_rol',             	$ditevent_part_rol);

    wachthond($extdebug,3, 'ditevent_criteria_leeftijd', 		$ditevent_criteria_leeftijd);
	wachthond($extdebug,3, 'ditevent_criteria_school', 			$ditevent_criteria_school);
	wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
	wachthond($extdebug,3, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);

	wachthond($extdebug,3, 'ditevent_wachtlijst_erop', 			$ditevent_wachtlijst_erop);
	wachthond($extdebug,3, 'ditevent_wachtlijst_eraf', 			$ditevent_wachtlijst_eraf);
	wachthond($extdebug,3, 'ditevent_criteriacheck_start',		$ditevent_criteriacheck_start);
	wachthond($extdebug,3, 'ditevent_criteriacheck_einde',		$ditevent_criteriacheck_einde);

	if ($basedate) { $datumditevent = $basedate; } else { $datumditevent = NULL; }

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD - 1.1 BEPAAL LEEFTIJD VANDAAG",  			   $todaydatetime);
	wachthond($extdebug,3, "########################################################################");

	if ($job == 'vandaag') {
		$birthdate 		= $basedate;
		$basedate 		= date("Y-m-d");
		$datumditevent 	= date("Y-m-d");

		wachthond($extdebug,2, "value_datumbirthday",	$birthdate);
		wachthond($extdebug,2, "value_datumvandaag",	$basedate);			
	}

	$datumditevent 	= $ditevent_event_start;

	// OPTIMALISATIE: Gebruik de static cache van find_lastnext
    $today_lastnext = find_lastnext($today_datetime);	
    $datumnextkamp 	=   $today_lastnext['next_start_date'];
    wachthond($extdebug,3, 'datumnextkamp',         $datumnextkamp);    

	wachthond($extdebug,2, "value_datumnextkamp",	$datumnextkamp);
	wachthond($extdebug,2, "value_datumditevent",	$datumditevent);

	// OPTIMALISATIE: De functie leeftijd_civicrm_diff bevat nu zelf Static Caching. 
	// Dubbele aanroepen voor hetzelfde contactId op dezelfde referentiedatum kosten nu geen extra tijd.
	if ($birthdate) { $leeftijd_vantoday = leeftijd_civicrm_diff('vandaag',	  $birthdate, $todaydatetime);	}
	if ($birthdate) { $leeftijd_ditevent = leeftijd_civicrm_diff('ditevent',  $birthdate, $datumditevent);	}
	if ($birthdate) { $leeftijd_nextkamp = leeftijd_civicrm_diff('nextkamp',  $birthdate, $datumnextkamp);	}	

	wachthond($extdebug,2, "leeftijd_vantoday", 				$leeftijd_vantoday);
	wachthond($extdebug,2, "leeftijd_ditevent", 				$leeftijd_ditevent);
	wachthond($extdebug,2, "leeftijd_nextkamp", 				$leeftijd_nextkamp);		

	$leeftijd_vantoday_decimalen	= $leeftijd_vantoday['leeftijd_decimalen'];
	$leeftijd_vantoday_rondjaren 	= $leeftijd_vantoday['leeftijd_rondjaren'];
	$leeftijd_vantoday_rondmaand 	= $leeftijd_vantoday['leeftijd_rondmaand'];

	$leeftijd_ditevent_decimalen	= $leeftijd_ditevent['leeftijd_decimalen'];
	$leeftijd_ditevent_rondjaren 	= $leeftijd_ditevent['leeftijd_rondjaren'];
	$leeftijd_ditevent_rondmaand 	= $leeftijd_ditevent['leeftijd_rondmaand'];

	$leeftijd_nextkamp_decimalen 	= $leeftijd_nextkamp['leeftijd_decimalen'];
	$leeftijd_nextkamp_rondjaren 	= $leeftijd_nextkamp['leeftijd_rondjaren'];
	$leeftijd_nextkamp_rondmaand 	= $leeftijd_nextkamp['leeftijd_rondmaand'];

	##################################################################################################

	$params_cont_update = [
		#'reload' 			=> TRUE,
		'checkPermissions' => FALSE,
		'where' => [
			['id', '=', $contact_id],
		],
		'values' => [
			'id' 	=> 	$contact_id,
		],				
	];

	$params_part_update = [
		#'reload' 			=> TRUE,
		'checkPermissions' => FALSE,
		'where' => [
			['id', '=', $part_id],
		],
		'values' => [
			'id' 	=> 	$part_id,
		],				
	];

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD - 1.4 PREPARE DB UPDATE MET LEEFTIJDEN");
	wachthond($extdebug,3, "########################################################################");

	if (in_array($groupID, array("149", "225", "139", "190"))) { // TAB & PART

		$timestampditevent 	= strtotime($datumditevent);
		$timestampnextkamp 	= strtotime($datumnextkamp);
		$jaarditevent		= date("Y", $timestampditevent);
		$jaarnextkamp		= date("Y", $timestampnextkamp);

		wachthond($extdebug,3, "jaarditevent", 	$jaarditevent);
		wachthond($extdebug,3, "jaarnextkamp",	$jaarnextkamp);

		if ($jaarditevent == $jaarnextkamp AND $leeftijd_ditevent) {
			$params_cont_update['values']['WERVING.nextkamp_decimalen']		= $leeftijd_ditevent_decimalen;
			$params_cont_update['values']['WERVING.nextkamp_rondjaren']		= $leeftijd_ditevent_rondjaren;
			$params_cont_update['values']['WERVING.nextkamp_rondmaand']		= $leeftijd_ditevent_rondmaand;
		} else {
			$params_cont_update['values']['WERVING.nextkamp_decimalen']		= $leeftijd_nextkamp_decimalen;
			$params_cont_update['values']['WERVING.nextkamp_rondjaren']		= $leeftijd_nextkamp_rondjaren;
			$params_cont_update['values']['WERVING.nextkamp_rondmaand']		= $leeftijd_nextkamp_rondmaand;
		}
	}

	if ($job == 'event') { // PART

		if (!empty($params_cont_update)) {
			$params_cont_update['reload']			= FALSE;
			$params_cont_update['checkPermissions']	= FALSE;
			$params_cont_update['debug']			= FALSE;

			wachthond($extdebug,3, "params_cont_update", 						$params_cont_update);
			if ($contact_id) {
				$result_leeftijd_cont_update = civicrm_api4('Contact','update', $params_cont_update);
				wachthond($extdebug,3, "params_cont_update", 			"EXECUTED");
				wachthond($extdebug,3, "result_leeftijd_cont_update", 			$result_leeftijd_cont_update);
			}
		}
	}
	if ($job == 'event' AND in_array($groupID, array("139", "190"))) { // PART

		$params_part_update['values']['PART.nextkamp_decimalen']			= $leeftijd_ditevent;

		if (!empty($params_part_update)) {
			$params_part_update['reload']			= FALSE;
			$params_part_update['checkPermissions']	= FALSE;
			$params_part_update['debug']			= FALSE;

			wachthond($extdebug,3, "params_part_update", 					$params_part_update);
			if ($part_eventid AND $part_id) {
				$result_leeftijd_part_update = civicrm_api4('Participant', 'update', $params_part_update);
				wachthond($extdebug,3, "params_part_update", 				"EXECUTED");
				wachthond($extdebug,3, "result_leeftijd_part_update", 		$result_leeftijd_part_update);

			}
		}
	}

	wachthond($extdebug,1, "*** 1. EINDKAMPLEEFTIJD PROCESS $displayname", 	$result_leeftijd_part_update);

   	$leeftijd_return = array(
    	'leeftijdvantoday_decimalen'	=> $leeftijd_vantoday_decimalen,
    	'leeftijdvantoday_rondjaren' 	=> $leeftijd_ditevent_rondjaren,
    	'leeftijdvantoday_rondmaand' 	=> $leeftijd_ditevent_rondmaand,
    	'leeftijdditevent_decimalen'	=> $leeftijd_ditevent_decimalen,
    	'leeftijdditevent_rondjaren' 	=> $leeftijd_ditevent_rondjaren,
    	'leeftijdditevent_rondmaand' 	=> $leeftijd_ditevent_rondmaand,
    	'leeftijdnextkamp_decimalen'	=> $leeftijd_nextkamp_decimalen,
    	'leeftijdnextkamp_rondjaren' 	=> $leeftijd_nextkamp_rondjaren,
    	'leeftijdnextkamp_rondmaand' 	=> $leeftijd_nextkamp_rondmaand,
	);

	return $leeftijd_return;
}

function leeftijd_civicrm_criteria($array_partditevent, $leeftijd_ditevent_decimalen)
{

	$extdebug 			= 0;

	wachthond($extdebug,4, 'array_partevent',           	$array_partevent);
	wachthond($extdebug,4, 'leeftijd_ditevent_decimalen', 	$leeftijd_ditevent_decimalen);

	if ($array_partditevent) {
//		$array_partditevent = $array_partevent;
	} else {
		return;
	}

	$displayname 							= $array_partditevent['displayname'] 						?? NULL;
    $ditevent_part_rol                  	= $array_partditevent['part_rol']                       	?? NULL;

	if ($ditevent_part_rol != 'deelnemer') {
		wachthond($extdebug,4, 'ditevent_part_rol',         $ditevent_part_rol);
		return;
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.X BEOORDEEL CRITERIA SCHOOL & LEEFTIJD",    $displayname); 
	wachthond($extdebug,3, "########################################################################");	

	$criteria_school	= NULL;
	$criteria_leeftijd	= NULL;
	$criteria_indicatie	= NULL;

	$displayname 							= $array_partditevent['displayname'] 						?? NULL;
	$contact_id 							= $array_partditevent['contact_id'] 						?? NULL;

    $ditevent_part_contact_id 				= $array_partditevent['contact_id']							?? NULL;
    $ditevent_part_eventid 					= $array_partditevent['event_id']							?? NULL;
    $ditevent_part_id   					= $array_partditevent['id'] 								?? NULL;

    $ditevent_event_kampnaam   				= $array_partditevent['kenmerken_kampnaam']					?? NULL;
    $ditevent_event_kampkort 				= $array_partditevent['kenmerken_kampkort']					?? NULL;
    $ditevent_kampjaar 						= $array_partditevent['ditevent_kampjaar']					?? NULL;

	$ditevent_part_functie              	= $array_partditevent['part_functie']                   	?? NULL;
    $ditevent_part_rol                  	= $array_partditevent['part_rol']                       	?? NULL;
	$ditevent_part_groepklas				= $array_partditevent['part_groepklas'] 					?? NULL;

	if ($ditevent_part_rol == 'deelnemer') {

		$ditevent_wachtlijst_erop			= $array_partditevent['part_wachtlijst_erop'] 				?? NULL;
		$ditevent_wachtlijst_eraf			= $array_partditevent['part_wachtlijst_eraf'] 				?? NULL;
		$ditevent_criteriacheck_start 		= $array_partditevent['part_criteriacheck_start'] 			?? NULL;
		$ditevent_criteriacheck_einde 		= $array_partditevent['part_criteriacheck_einde'] 			?? NULL;

		$ditevent_criteria_leeftijd 		= $array_partditevent['part_criteria_leeftijd'] 			?? NULL;
		$ditevent_criteria_school 			= $array_partditevent['part_criteria_school'] 				?? NULL;
		$ditevent_criteria_indicatie 		= $array_partditevent['part_criteria_indicatie'] 			?? NULL;
		$ditevent_criteria_oordeel 			= $array_partditevent['part_criteria_oordeel'] 				?? NULL;
	}

    wachthond($extdebug,3, 'displayname',          				$displayname);
    wachthond($extdebug,3, 'contact_id',           				$contact_id);
    wachthond($extdebug,3, 'ditevent_contact_id',           	$ditevent_part_contact_id);
    wachthond($extdebug,2, 'ditevent_part_eventid',         	$ditevent_part_eventid);
    wachthond($extdebug,3, 'ditevent_part_id',              	$ditevent_part_id);

    wachthond($extdebug,3, 'ditevent_event_kampnaam',       	$ditevent_event_kampnaam);
    wachthond($extdebug,3, 'ditevent_event_kampkort',       	$ditevent_event_kampkort);
    wachthond($extdebug,3, 'ditevent_kampjaar',             	$ditevent_kampjaar);

	wachthond($extdebug,2, 'ditevent_part_functie',             $ditevent_part_functie);
    wachthond($extdebug,2, 'ditevent_part_rol',             	$ditevent_part_rol);
	wachthond($extdebug,2, 'ditevent_part_groepklas', 			$ditevent_part_groepklas);

	if ($ditevent_part_rol == 'deelnemer') {

        wachthond($extdebug,3, 'ditevent_criteria_leeftijd', 	$ditevent_criteria_leeftijd);
        wachthond($extdebug,3, 'ditevent_criteria_school',      $ditevent_criteria_school);
        wachthond($extdebug,3, 'ditevent_criteria_indicatie',   $ditevent_criteria_indicatie);
        wachthond($extdebug,3, 'ditevent_criteria_oordeel',     $ditevent_criteria_oordeel);

		wachthond($extdebug,3, 'ditevent_wachtlijst_erop', 		$ditevent_wachtlijst_erop);
		wachthond($extdebug,3, 'ditevent_wachtlijst_eraf', 		$ditevent_wachtlijst_eraf);
		wachthond($extdebug,3, 'ditevent_criteriacheck_start',	$ditevent_criteriacheck_start);
		wachthond($extdebug,3, 'ditevent_criteriacheck_einde',	$ditevent_criteriacheck_einde);
	}

	$contact_id = $ditevent_part_contact_id;
	$part_id 	= $ditevent_part_id;
	$kampkort 	= $ditevent_event_kampkort;
	$groepklas 	= $ditevent_part_groepklas;
	$leeftijd 	= $leeftijd_ditevent_decimalen;

	wachthond($extdebug,1, 'kampkort', 	$kampkort); 
	wachthond($extdebug,1, 'groepklas', $groepklas);
	wachthond($extdebug,1, 'leeftijd', 	$leeftijd); 

	wachthond($extdebug,1, 'contact_id',$contact_id); 
	wachthond($extdebug,1, 'leeftijd', 	$leeftijd); 
	wachthond($extdebug,1, 'part_id', 	$part_id); 

	$params_cont_update = [
		#'reload' 		   => TRUE,
		'checkPermissions' => FALSE,
		'where' => [
			['id', '=', $contact_id],
		],
		'values' => [
			'id' 	=> 	$contact_id,
		],				
	];

	$params_part_update = [
		#'reload' 			=> TRUE,
		'checkPermissions' 	=> FALSE,
		'where' => [
			['id', '=', $part_id],
		],
		'values' => [
			'id' 	=> 	$part_id,
		],				
	];

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.1 CORRIGEER EVT MIXUP VAN GROEP VS KLAS");
	wachthond($extdebug,3, "########################################################################");

	if ($kampkort == 'kk1' OR $kampkort == 'kk2') {

		if (in_array($groepklas, array("klas_3"))) {
			$new_groepklas = 'groep_3';
		}
		if (in_array($groepklas, array("klas_4"))) {
			$new_groepklas = 'groep_4';
		}
		if (in_array($groepklas, array("klas_5"))) {
			$new_groepklas = 'groep_5';
		}
		if (in_array($groepklas, array("klas_6"))) {
			$new_groepklas = 'groep_6';
		}
	}

	if ($kampkort == 'tk1' OR $kampkort == 'tk2') {

		if (in_array($groepklas, array("groep_2"))) {
			$new_groepklas = 'klas_2';
		}
		if (in_array($groepklas, array("groep_3"))) {
			$new_groepklas = 'klas_3';
		}
	}

	if ($kampkort == 'jk1' OR $kampkort == 'jk2') {

		if (in_array($groepklas, array("groep_3"))) {
			$new_groepklas = 'klas_3';
		}
		if (in_array($groepklas, array("groep_4"))) {
			$new_groepklas = 'klas_4';
		}
		if (in_array($groepklas, array("groep_5"))) {
			$new_groepklas = 'klas_5';
		}
		if (in_array($groepklas, array("groep_6"))) {
			$new_groepklas = 'klas_6';
		}
	}

	if ($new_groepklas) {
		wachthond($extdebug,1, "!!! CRITERIA - MIXUP HERSTELD VOOR $kampkort VAN $groepklas NAAR $new_groepklas");
		$groepklas = $new_groepklas;
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.2 INDICATIE GROEP/KLAS: PRIMA OF AFWIJKEND");
	wachthond($extdebug,3, "########################################################################");

	if ($kampkort == 'kk1' AND in_array($groepklas, array("groep_3","groep_4","groep_5","groep_6","groep_7")))	{
	 	$criteria_school = 'prima';
	} elseif ($kampkort == 'kk1')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'kk2' AND in_array($groepklas, array("groep_3","groep_4","groep_5","groep_6","groep_7")))	{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'kk2')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'bk1' AND in_array($groepklas, array("groep_8","klas_1")))		{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'bk1')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'bk2' AND in_array($groepklas, array("groep_8","klas_1")))		{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'bk2')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'tk1' AND in_array($groepklas, array("klas_2","klas_3")))		{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'tk1')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'tk2' AND in_array($groepklas, array("klas_2","klas_3")))		{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'tk2')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'jk1' AND in_array($groepklas, array("klas_4","klas_5","klas_6","vervolg")))	{
		$criteria_school = 'prima';
	} elseif ($kampkort == 'jk1')		{
		$criteria_school = 'afwijkend';
	}
	if ($kampkort == 'jk2' AND in_array($groepklas, array("klas_4","klas_5","klas_6","vervolg")))	{
		$criteria_school = 'prima';	
	} elseif ($kampkort == 'jk2')		{
		$criteria_school = 'afwijkend';
	}

	wachthond($extdebug,1, "school ($groepklas) binnen basiscriteria", 		 	$criteria_school); 

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.3 INDICATIE GROEP/KLAS",                       "[MARGE]");
	wachthond($extdebug,3, "########################################################################");

	if (in_array($kampkort,array("tk1","tk2")) AND in_array($groepklas,array("klas_4")))						
		{ $criteria_school = 'marge';		}
	if (in_array($kampkort,array("jk1","jk2")) AND in_array($groepklas,array("klas_2","klas_3")))				
		{ $criteria_school = 'marge';		}

	wachthond($extdebug,1, "school ($groepklas) binnen criteriamarges", 		$criteria_school); 

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.4 INDICATIE LEEFTIJD",            "[PRIMA OF AFWIJKEND]");
	wachthond($extdebug,3, "########################################################################");

	if ($kampkort == 'kk1' AND ($leeftijd >=  7.0 AND $leeftijd < 12.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'kk1')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'kk2' AND ($leeftijd >=  7.0 AND $leeftijd < 12.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'kk2')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'bk1' AND ($leeftijd >= 12.0 AND $leeftijd < 14.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'bk1')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'bk2' AND ($leeftijd >= 12.0 AND $leeftijd < 14.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'bk2')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'tk1' AND ($leeftijd >= 14.0 AND $leeftijd < 16.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'tk1')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'tk2' AND ($leeftijd >= 14.0 AND $leeftijd < 16.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'tk2')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'jk1' AND ($leeftijd >= 16.0 AND $leeftijd < 18.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'jk1')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'jk2' AND ($leeftijd >= 16.0 AND $leeftijd < 18.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'jk2')	{ $criteria_leeftijd = 'afwijkend';
	}
	if ($kampkort == 'top' AND ($leeftijd >= 18.0 AND $leeftijd < 21.0))	{
		$criteria_leeftijd = 'prima'; } elseif ($kampkort == 'top')	{ $criteria_leeftijd = 'afwijkend';
	}

	wachthond($extdebug,1, "leeftijd ($leeftijd) binnen basiscriteria?", 		$criteria_leeftijd); 

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 1.5 INDICATIE LEEFTIJD",               			"[MARGE]");
	wachthond($extdebug,3, "########################################################################");

	if (in_array($kampkort, array("kk1","kk2")) AND ($leeftijd >=  6.7 AND $leeftijd <   7.0)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("kk1","kk2")) AND ($leeftijd >= 12.0 AND $leeftijd <= 12.3)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("bk1","bk2")) AND ($leeftijd >= 11.3 AND $leeftijd <  12.0)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("bk1","bk2")) AND ($leeftijd >= 14.0 AND $leeftijd <= 14.3)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("tk1","tk2")) AND ($leeftijd >= 13.7 AND $leeftijd <  14.0))	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("tk1","tk2")) AND ($leeftijd >= 16.0 AND $leeftijd <= 16.3)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("jk1","jk2")) AND ($leeftijd >= 15.7 AND $leeftijd <  16.0)) 	{
		$criteria_leeftijd = 'marge';
	}
	if (in_array($kampkort, array("jk1","jk2")) AND ($leeftijd >= 18.0 AND $leeftijd <= 18.3)) 	{
		$criteria_leeftijd = 'marge';
	}

	wachthond($extdebug,1, "leeftijd ($leeftijd) binnen criteriamarges?",	$criteria_leeftijd); 

	$criteria_indicatie 	= NULL;
	$criteria_oordeel 		= NULL;
//	$criteria_indicatie 	= 'noggeenindicatie'; 	// M61 ZET EERST EEN DEFAULT WAARDE
//	$criteria_oordeel 		= 'oordeelnognodig'; 	// M61 ZET EERST EEN DEFAULT WAARDE

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.X INDICATIE EN INITIELE BEOORDELING",       $displayname);
	wachthond($extdebug,3, "########################################################################");

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.1 INDIEN ZOWEL LEEFTIJD ALS SCHOOL",           "[PRIMA]");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'prima'     AND $criteria_school == 'prima') 			{ 
		$criteria_indicatie = 'criteriaprima'; 		$criteria_oordeel = 'oordeelnietnodig';

//		$params_part_update['values']['PART_DEEL_INTERN.criteriacheck_start']	= "";
//		$params_part_update['values']['PART_DEEL_INTERN.criteriacheck_einde']	= "";

		wachthond($extdebug,4, "params_part_update", 							$params_part_update);

		wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
		wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);
//		wachthond($extdebug,1, "criteriacheck_start", 	$criteriacheck_start);
//		wachthond($extdebug,1, "criteriacheck_einde", 	$criteriacheck_einde);
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.1 INDIEN ZOWEL LEEFTIJD ALS SCHOOL", 		 "[AFWIJKEND");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'afwijkend' AND $criteria_school == 'afwijkend') 		{ 
		$criteria_indicatie = 'criteriawijktaf'; 	$criteria_oordeel = 'oordeelnognodig';

		wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
		wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);		
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.2 INDIEN ALLEEN LEEFTIJD", 				"[AFWIJKEND]");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'afwijkend' AND $criteria_school != 'afwijkend') 			{

		if ($criteria_leeftijd == 'afwijkend' AND $criteria_school == 'prima') 			{ 
			$criteria_indicatie = 'leeftijdwijktaf'; 	$criteria_oordeel = 'oordeelnognodig'; 	}
		if ($criteria_leeftijd == 'afwijkend' AND $criteria_school == 'marge') 			{ 
			$criteria_indicatie = 'leeftijdwijktaf'; 	$criteria_oordeel = 'oordeelnognodig'; 	}

		wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
		wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);		
	}
	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.3 INDIEN ALLEEN SCHOOL",  					"[AFWIJKEND]");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd != 'afwijkend' AND $criteria_school == 'afwijkend') 			{

		if ($criteria_leeftijd == 'prima'     AND $criteria_school == 'afwijkend') 		{ 
			$criteria_indicatie = 'schoolwijktaf'; 		$criteria_oordeel = 'oordeelnognodig';
		}
		if ($criteria_leeftijd == 'marge' 	  AND $criteria_school == 'afwijkend') 		{ 
			$criteria_indicatie = 'schoolwijktaf'; 		$criteria_oordeel = 'oordeelnognodig';
		}

		wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
		wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);		
	}
	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 2.4 INDIEN LEEFTIJD OF SCHOOL", 					"[MARGE]");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'marge' 	  OR $criteria_school == 'marge') 				{ 

		if ($criteria_leeftijd == 'marge' 	  AND $criteria_school == 'marge') 			{ 
			$criteria_indicatie = 'binnenmarges'; 		$criteria_oordeel = 'oordeelnietnodig';
		}
		if ($criteria_leeftijd == 'prima'     AND $criteria_school == 'marge') 			{ 
			$criteria_indicatie = 'binnenmarges'; 		$criteria_oordeel = 'oordeelnietnodig';
		}
		if ($criteria_leeftijd == 'marge' 	  AND $criteria_school == 'prima') 			{ 
			$criteria_indicatie = 'binnenmarges'; 		$criteria_oordeel = 'oordeelnietnodig';
		}

		wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
		wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 3.1 EASY OP JUISTE LEEFTIJD BIJ KK/BK/TK INDIEN SCHOOL OK");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'marge' AND $criteria_school == 'prima' AND in_array($kampkort, array("kk1","kk2")))	{
	 	$criteria_indicatie = 'binnenmarges'; $criteria_oordeel = 'oordeelnietnodig';
	}
	if ($criteria_leeftijd == 'marge' AND $criteria_school == 'prima' AND in_array($kampkort, array("bk1","bk2")))	{ 
		$criteria_indicatie = 'binnenmarges'; $criteria_oordeel = 'oordeelnietnodig';
	}
	if ($criteria_leeftijd == 'marge' AND $criteria_school == 'prima' AND in_array($kampkort, array("tk1","tk2")))	{ 
		$criteria_indicatie = 'binnenmarges'; $criteria_oordeel = 'oordeelnietnodig';
	}

	wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
	wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);		

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 3.2 EASY OP JUISTE KLAS BIJ JK INDIEN LEEFIJD OK IS");
	wachthond($extdebug,3, "########################################################################");

	if ($criteria_leeftijd == 'prima' AND $criteria_school == 'marge' AND in_array($kampkort,array("jk1","jk2")))	{ 
		$criteria_indicatie = 'binnenmarges'; $criteria_oordeel = 'oordeelnietnodig';
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 3.3 EASY OP JUISTE KLAS BIJ JK INDIEN LEEFIJD OK IS");
	wachthond($extdebug,3, "########################################################################");

	if ($kampkort == 'top' AND $criteria_leeftijd == 'prima') {
		$criteria_school 	= 'prima';
		$criteria_indicatie = 'criteriaprima';
		$criteria_oordeel 	= 'oordeelnietnodig';
	}

	wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
	wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 4.1 SET DEFAULTS INDIEN WAARDE NU NOG NIET GEVULD");
	wachthond($extdebug,3, "########################################################################");

	if (empty($criteria_indicatie)) 	{ $criteria_indicatie 	= 'noggeenindicatie'; 		}
	if (empty($criteria_oordeel))		{ $criteria_oordeel 	= 'oordeelnognodig'; 		}

	wachthond($extdebug,3, "criteria_indicatie", 	$criteria_indicatie);
	wachthond($extdebug,3, "criteria_oordeel", 		$criteria_oordeel);		

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 4.2 OVERSCHRIJF HANDMATIGE BEOORDELING NIET");
	wachthond($extdebug,3, "########################################################################");

	if (in_array($ditevent_criteria_oordeel, array("leeftijdtejong","leeftijdteoud","oordeelprima"))) {
		$criteria_oordeel 	= $ditevent_criteria_oordeel;
		wachthond($extdebug,2, "BEHOUD HANDMATIG BEOORDELING CRITIERIA", 	$criteria_oordeel);
	}			

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 5.1 SCHRIJF DE NIEUWE WAARDEN NAAR CONTACT");
	wachthond($extdebug,3, "########################################################################");

	$params_cont_update['values']['DITJAAR.ditjaar_leeftijd']					= $criteria_leeftijd;
	$params_cont_update['values']['DITJAAR.ditjaar_school']						= $criteria_school;
	$params_cont_update['values']['DITJAAR.ditjaar_criteria_indicatie']			= $criteria_indicatie;
	$params_cont_update['values']['DITJAAR.ditjaar_criteria_oordeel']			= $criteria_oordeel;	

	if ($new_groepklas) {
		$params_cont_update['values']['DITJAAR.ditjaar_groep_klas']				= $new_groepklas;
	}

	if ($contact_id) {
		wachthond($extdebug,3, "params_cont_update", 					$params_cont_update);
		$result_leeftijd_cont_update = civicrm_api4('Contact','update', $params_cont_update);
		wachthond($extdebug,1, "params_cont_update", "EXECUTED");
		wachthond($extdebug,3, "result_leeftijd_cont_update", 			$result_leeftijd_cont_update);
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 5.2 SCHRIJF DE NIEUWE WAARDEN NAAR REGISTRATIE");
	wachthond($extdebug,3, "########################################################################");

	$params_part_update['values']['PART_DEEL_INTERN.criteria_leeftijd']			= $criteria_leeftijd;
	$params_part_update['values']['PART_DEEL_INTERN.criteria_school']			= $criteria_school;
	$params_part_update['values']['PART_DEEL_INTERN.criteria_indicatie']		= $criteria_indicatie;
	$params_part_update['values']['PART_DEEL_INTERN.criteria_oordeel']			= $criteria_oordeel;

	if ($new_groepklas) {
		$params_part_update['values']['PART_DEEL.Groep_klas'] 					= $new_groepklas;
	}

	if ($part_id) {
		wachthond($extdebug,3, "params_part_update", 							$params_part_update);
		$result_leeftijd_part_update = civicrm_api4('Participant', 'update', 	$params_part_update);
		wachthond($extdebug,1, "params_part_update", "EXECUTED");
		wachthond($extdebug,3, "result_leeftijd_part_update", 					$result_leeftijd_part_update);
	}

	$leeftijd_criteria_array = array(
		'invoer_kampkort'			=> $kampkort,
		'invoer_groepklas'			=> $groepklas,
		'invoer_leeftijd'			=> $leeftijd,
		'criteria_leeftijd'			=> $criteria_leeftijd,
		'criteria_school'			=> $criteria_school,
		'criteria_indicatie' 		=> $criteria_indicatie,
		'criteria_oordeel' 			=> $criteria_oordeel,
	);

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### CRITERIA - 6.0 RETURN LEEFTIJD_CRTITERIA_ARRAY", $leeftijd_criteria_array);
	wachthond($extdebug,3, "########################################################################");

	return $leeftijd_criteria_array;

}

function leeftijd_civicrm_status($array_partditevent, $array_criteria = NULL) {

	$extdebug 				= 0;
	$apidebug   			= FALSE;

	if (is_array($array_partditevent)) {
//		$array_partditevent = $array_partevent;
	} else {
		return;
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 1.1 BEPAAL STATUS REGISTRATIE & DEELNAME",  "[START]");
	wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, 'array_partditevent', 	$array_partditevent);

	$displayname 							= $array_partditevent['displayname'] 						?? NULL;
	$contact_id 							= $array_partditevent['contact_id'] 						?? NULL;

    $ditevent_part_contact_id 				= $array_partditevent['contact_id']							?? NULL;
    $ditevent_part_eventid 					= $array_partditevent['event_id']							?? NULL;
    $ditevent_part_id   					= $array_partditevent['id'] 								?? NULL;
    $ditevent_part_role_id     				= $array_partditevent['role_id']							?? NULL;
    $ditevent_part_status_id 				= $array_partditevent['status_id']							?? NULL;
    $ditevent_part_status_name  			= $array_partditevent['status_name']						?? NULL;

   	$ditevent_register_date     			= $array_partditevent['register_date']						?? NULL;
    $ditevent_event_start 					= $array_partditevent['event_start_date']					?? NULL;
    $ditevent_event_einde 					= $array_partditevent['event_end_date']						?? NULL;

    $ditevent_event_kampnaam   				= $array_partditevent['kenmerken_kampnaam']					?? NULL;
    $ditevent_event_kampkort 				= $array_partditevent['kenmerken_kampkort']					?? NULL;

    $ditevent_part_kampnaam   				= $array_partditevent['part_kampnaam']						?? NULL;
    $ditevent_part_kampkort 				= $array_partditevent['part_kampkort']						?? NULL;

	$ditevent_part_functie              	= $array_partditevent['part_functie']                   	?? NULL;
    $ditevent_part_rol                  	= $array_partditevent['part_rol']                       	?? NULL;

    wachthond($extdebug,2, 'ditevent_part_functie',             	$ditevent_part_functie);
    wachthond($extdebug,2, 'ditevent_part_rol',                 	$ditevent_part_rol);

	if ($ditevent_part_rol == 'deelnemer') {

		$ditevent_wachtlijst_erop			= $array_partditevent['part_wachtlijst_erop'] 				?? NULL;
		$ditevent_wachtlijst_eraf			= $array_partditevent['part_wachtlijst_eraf'] 				?? NULL;
		$ditevent_criteriacheck_start 		= $array_partditevent['part_criteriacheck_start'] 			?? NULL;
		$ditevent_criteriacheck_einde 		= $array_partditevent['part_criteriacheck_einde'] 			?? NULL;

		$ditevent_criteria_leeftijd 		= $array_partditevent['part_criteria_leeftijd'] 			?? NULL;
		$ditevent_criteria_school 			= $array_partditevent['part_criteria_school'] 				?? NULL;
		$ditevent_criteria_indicatie 		= $array_partditevent['part_criteria_indicatie'] 			?? NULL;
		$ditevent_criteria_oordeel 			= $array_partditevent['part_criteria_oordeel'] 				?? NULL;
	}

    wachthond($extdebug,3, 'displayname',           				$displayname);
    wachthond($extdebug,3, 'contact_id',           					$contact_id);
    wachthond($extdebug,3, 'ditevent_contact_id',           		$ditevent_part_contact_id);

    wachthond($extdebug,3, 'ditevent_event_kampnaam',       		$ditevent_event_kampnaam);
    wachthond($extdebug,3, 'ditevent_event_kampkort',       		$ditevent_event_kampkort);
    wachthond($extdebug,3, 'ditevent_part_kampnaam',       			$ditevent_part_kampnaam);
    wachthond($extdebug,3, 'ditevent_part_kampkort',       			$ditevent_part_kampkort);

    wachthond($extdebug,3, 'ditevent_register_date',      			$ditevent_register_date);
    wachthond($extdebug,3, 'ditevent_event_start',          		$ditevent_event_start);
    wachthond($extdebug,3, 'ditevent_event_einde',          		$ditevent_event_einde);
    wachthond($extdebug,3, 'ditevent_kampjaar',             		$ditevent_kampjaar);    

    wachthond($extdebug,3, 'ditevent_part_id',              		$ditevent_part_id);
    wachthond($extdebug,2, 'ditevent_part_eventid',         		$ditevent_part_eventid);
    wachthond($extdebug,3, 'ditevent_part_status_id',       		$ditevent_part_status_id);
    wachthond($extdebug,3, 'ditevent_part_status_name',     		$ditevent_part_status_name);

	wachthond($extdebug,2, 'ditevent_functie',              		$ditevent_part_functie);
    wachthond($extdebug,2, 'ditevent_part_rol',             		$ditevent_part_rol);

	if ($ditevent_part_rol == 'deelnemer') {

	    wachthond($extdebug,3, 'ditevent_criteria_leeftijd', 		$ditevent_criteria_leeftijd);
		wachthond($extdebug,3, 'ditevent_criteria_school', 			$ditevent_criteria_school);
		wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
		wachthond($extdebug,3, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);

		wachthond($extdebug,3, 'ditevent_wachtlijst_erop', 			$ditevent_wachtlijst_erop);
		wachthond($extdebug,3, 'ditevent_wachtlijst_eraf', 			$ditevent_wachtlijst_eraf);
		wachthond($extdebug,3, 'ditevent_criteriacheck_start',		$ditevent_criteriacheck_start);
		wachthond($extdebug,3, 'ditevent_criteriacheck_einde',		$ditevent_criteriacheck_einde);

		$ditevent_criteria_leeftijd		= $array_criteria['criteria_leeftijd'] 		?? NULL;
		$ditevent_criteria_school		= $array_criteria['criteria_school'] 		?? NULL;
		$ditevent_criteria_indicatie	= $array_criteria['criteria_indicatie'] 	?? NULL;
//		$ditevent_criteria_oordeel		= $array_criteria['criteria_oordeel'] 		?? NULL;

// M61: TODO WAAROM STAAT REGEL HIERBOVEN HIERIN?

		wachthond($extdebug,2, 'ditevent_criteria_leeftijd', 	$ditevent_criteria_leeftijd);
		wachthond($extdebug,2, 'ditevent_criteria_school', 		$ditevent_criteria_school);
		wachthond($extdebug,2, 'ditevent_criteria_indicatie', 	$ditevent_criteria_indicatie);
		wachthond($extdebug,2, 'ditevent_criteria_oordeel', 	$ditevent_criteria_oordeel);	

		wachthond($extdebug,2, "########################################################################");
		wachthond($extdebug,2, 'ditevent_criteria_array', 		$array_criteria);

		// ZET DE WAARDE VOOR DE NIEUWE VELDEN DEFAULT OP DE BESTAANDE WAARDE

		if ($ditevent_part_status_id) 	   	{ $new_ditevent_part_status_id 	  	= $ditevent_part_status_id 	  	?? NULL; }
		if ($ditevent_part_deelnamestatus) 	{ $new_ditevent_deelnamestatus 	  	= $ditevent_part_deelnamestatus ?? NULL; }

		if ($ditevent_wachtlijst_erop) 		{ $new_wachtlijst_erop				= $ditevent_wachtlijst_erop 	?? NULL; }
		if ($ditevent_wachtlijst_eraf) 		{ $new_wachtlijst_eraf				= $ditevent_wachtlijst_eraf 	?? NULL; }
		if ($ditevent_criteriacheck_start) 	{ $new_criteriacheck_start			= $ditevent_criteriacheck_start ?? NULL; }
		if ($ditevent_criteriacheck_einde) 	{ $new_criteriacheck_einde			= $ditevent_criteriacheck_einde ?? NULL; }

		$new_ditevent_part_status_id		= $ditevent_part_status_id;
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 1.2 CORRIGEER STATUSSEN 0, 5 & 6",       $displayname);
	wachthond($extdebug,2, "########################################################################");

	###########################################################################################
	// (onbekend [0], afwachting betaling [5], afgebroken betaling [6] > geregistreerd)
	// M61: TODO: de status hoort sws niet op 0 / Onbekend te kunnen komen
	###########################################################################################

	wachthond($extdebug,3, 'new_ditevent_part_status_id',    		$new_ditevent_part_status_id);
    wachthond($extdebug,4, 'new_ditevent_part_status_name',  		$new_ditevent_part_status_name);

	if (in_array($ditevent_part_status_id, array(0)) OR in_array($ditevent_part_status_id, array(5,6))) {

		wachthond($extdebug,3, 'ditevent_part_rol', 				$ditevent_part_rol);
		wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);

		// M61: VERY URGENT TODO: HIER NOG OPHALEN OF EVENT OP WACHTLIJST STAAT, NIET ZOMAAR BEVESTIGEN !!!

		if ($ditevent_part_rol == 'deelnemer' AND empty($ditevent_wachtlijst_erop)) {

			// M61: TODO ALS EXTRA CHECK KAN HET OOK NOG VIA API4 EVT

			if ($ditevent_criteria_indicatie == 'criteriaprima') {
				$new_ditevent_part_status_id 			= 1;
				$new_ditevent_part_status_name 			= 'Registered';
				$new_ditevent_deelnamestatus 			= 'Bevestigd';
				wachthond($extdebug,2, 'ditevent_part_status_id was 0', "updated to 1");
			}

        	if ($ditevent_criteria_indicatie == 'criteriawijktaf' AND !in_array($ditevent_criteria_oordeel, array('buitencriteria'))) {
				$new_ditevent_part_status_id 			= 8;
				$new_ditevent_part_status_name 			= 'Awaiting approval';
				$new_ditevent_deelnamestatus 			= 'Criteriacheck';
				$new_criteriacheck_start 				= $ditevent_register_date;
				wachthond($extdebug,2, 'ditevent_part_status_id was 0', "updated to 8");
			}
		}

		wachthond($extdebug,4, 'org_ditevent_part_status_id', 			$ditevent_part_status_id);
		wachthond($extdebug,4, 'new_ditevent_part_status_id', 			$new_ditevent_part_status_id);

		if ($ditevent_part_rol == 'deelnemer') {
			wachthond($extdebug,4, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
			wachthond($extdebug,4, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);
		}

	} else {
		wachthond($extdebug,3, "correctie niet nodig");
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 2.1 BEPAAL DEELNAMESTATUS");
	wachthond($extdebug,2, "########################################################################");

	$status_positive 		= Civi::cache()->get('cache_status_positive');
	$status_pending 		= Civi::cache()->get('cache_status_pending');
	$status_waiting 		= Civi::cache()->get('cache_status_waiting');
	$status_negative 		= Civi::cache()->get('cache_status_negative');

	wachthond($extdebug,4, 'statusids_positive',	$status_positive);
	wachthond($extdebug,4, 'statusids_pending',		$status_pending);
	wachthond($extdebug,4, 'statusids_waiting',		$status_waiting);
	wachthond($extdebug,4, 'statusids_negative',	$status_negative);

	if (in_array($new_ditevent_part_status_id,	$status_positive)) 		{
		// M61: DIT BETREFT ALLE POSITIEVE STATUSSEN ZOALS OVERGEDRAGEN / EERDER NAAR HUIS / EN IK BEN ER BIJ
		$new_ditevent_deelnamestatus	= 'Bevestigd';
	}

	if ($ditevent_part_status_id == '1') {
		// M61: DIT MOET HIER ECHT 1 ZIJN ipv ALLE POSITIEVE
		$new_ditevent_part_status_id 	= 1;
		$new_ditevent_part_status_name 	= 'Registered';
		$new_ditevent_deelnamestatus 	= 'Bevestigd';
		wachthond($extdebug,2, "a FORCEER DEELNAMESTATUS BEVESTIGD INDIEN STATUS = 1 EN OORDEELNIETNODIG");

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);
	}

//	if (in_array($new_ditevent_part_status_id,	$status_waiting)) 		{ 
	if ($ditevent_part_status_id == '7') {
		$new_ditevent_part_status_id 	= 7;
		$new_ditevent_part_status_name 	= 'On waitlist';
		$new_ditevent_deelnamestatus 	= "Wachtlijst";
		wachthond($extdebug,2, "b FORCEER STATUS WACHTLIJST INDIEN WACHTLIJST");
		// M61: Indien wachtlijst & criteriacheck zet dan status op wachtlijst en deelnamestatus op criteriacheck

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);

	}

	if ($ditevent_part_status_id == '8' AND $ditevent_criteria_oordeel == 'oordeelnognodig') {
		$new_ditevent_part_status_id 	= 8;
		$new_ditevent_part_status_name 	= 'Awaiting approval';
		$new_ditevent_deelnamestatus 	= "Criteriacheck";
		wachthond($extdebug,2, "c FORCEER STATUS CRITERIACHECK INDIEN AFWACHTING OORDEEL");

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);
	}

//	if (in_array($new_ditevent_part_status_id,	$status_negative)) 		{ 
	if ($ditevent_part_status_id == '4') {
		$new_ditevent_part_status_id 	= 4;
		$new_ditevent_part_status_name 	= 'Cancelled';
		$new_ditevent_deelnamestatus 	= "Geannuleerd";
		wachthond($extdebug,2, "d FORCEER STATUS GEANNULEERD INDIEN GEANNULEERD");

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);
	}

	if ($ditevent_part_status_id == '2') { 	// STATUS 'DEELNGENOMEN'
		$new_ditevent_part_status_id 	= 2;
		$new_ditevent_part_status_name 	= 'Deelgenomen';
		$new_ditevent_deelnamestatus 	= 'Deelgenomen';
		wachthond($extdebug,2, "e HOUDT STATUS DEELGENOMEN HETZELFDE");

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);
	}

	if ($ditevent_part_rol == 'leiding' AND $ditevent_part_status_id != '4') {	
		$new_ditevent_part_status_id 	= 1;
		$new_ditevent_part_status_name 	= 'Registered';
		$new_ditevent_deelnamestatus 	= 'Bevestigd';
		wachthond($extdebug,2, "f FORCEER STATUS BEVESTIGD VOOR KAMPLEIDERS");

	    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
	    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
	    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 3.2 FORCEER AFWACHTING OORDEEL",         $displayname);
	wachthond($extdebug,2, "########################################################################");

	// M61: dit maakt het ook onmogelijk om handmatig 'afwachting oordeel' naar 'regisdtered' te zetten.

	if ($ditevent_part_rol == 'deelnemer') {

		if ($ditevent_criteria_oordeel == 'oordeelnognodig' AND $ditevent_criteria_indicatie == 'criteriawijktaf') {
			$new_ditevent_part_status_id 			= 8;
			$new_ditevent_part_status_name 			= "Awaiting approval";
			$new_ditevent_deelnamestatus 			= "Criteriacheck";
			$new_criteriacheck_start 				= $ditevent_register_date;
			wachthond($extdebug,2, "a FORCEER VOORLOPIG STATUS AFWACHTING OORDEEL IVM LEEFTIJD/SCHOOL");
		}

		if ($ditevent_criteria_oordeel == 'oordeelnognodig' AND $ditevent_criteria_indicatie == 'schoolwijktaf' 
			AND in_array($ditevent_event_kampkort, array("kk1","kk2","bk1","bk2"))) {
			$new_ditevent_part_status_id 			= 8;
			$new_ditevent_part_status_name 			= "Awaiting approval";		
			$new_ditevent_deelnamestatus 			= "Criteriacheck";
			wachthond($extdebug,2, "b FORCEER VOORLOPIG STATUS AFWACHTING OORDEEL IVM SCHOOL BIJ KK/BK");
		}
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 4.1 HANDLE DATUM WACHTLIJST EROP / ERAF");
	wachthond($extdebug,2, "########################################################################");
/*
	$wachtlijst_ditmoment 		= 0;
	$wachtlijst_voorheen 		= 0;

	if ($ditevent_wachtlijst_erop AND empty($ditevent_wachtlijst_erop)) {
		$wachtlijst_ditmoment 	= 1;
	} else 
	if ($ditevent_wachtlijst_erop AND $ditevent_wachtlijst_erop) {
		$wachtlijst_voorheen 	= 1;
	}
*/
	wachthond($extdebug,2, 'new_ditevent_part_status_id', 		$new_ditevent_part_status_id);
	wachthond($extdebug,2, 'ditevent_register_date', 			$ditevent_register_date);

	// M61 TODO: wachtlijst_erop moet niet hier pas. Moet via ext & profiel al via custom_pre

	if ($new_ditevent_part_status_id == '7') 	{	// 'WACHTLIJST'

		wachthond($extdebug,2, 'ditevent_register_date', 		$ditevent_register_date);	
		$new_wachtlijst_erop = $ditevent_register_date;
		wachthond($extdebug,1, "### FORCEER DATUM WACHTLIJST EROP INDIEN VAN TOEPASSING ###");

		wachthond($extdebug,3, 'ditevent_wachtlijst_erop', 		$ditevent_wachtlijst_erop);
		wachthond($extdebug,3, 'new_wachtlijst_erop', 			$new_wachtlijst_erop);
	}

	// M61 TODO: wachtlijst_eraf moet niet hier pas. Moet via ext & profiel al via custom_pre

	if ($new_ditevent_part_status_id == '9') 	{	// 'VOORHEEN WACHTLIJST'

		if (empty($ditevent_wachtlijst_eraf)) {
			$new_wachtlijst_eraf = $today_datetime;
			wachthond($extdebug,1, "### FORCEER DATUM WACHTLIJST ERAF INDIEN VAN TOEPASSING ###");
		}

		wachthond($extdebug,3, 'ditevent_wachtlijst_eraf', 		$ditevent_wachtlijst_eraf);
		wachthond($extdebug,3, 'new_wachtlijst_eraf', 			$new_wachtlijst_eraf);

	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 4.2 HANDLE DATUM CRITERIACHECK START/STOP");
	wachthond($extdebug,2, "########################################################################");

	wachthond($extdebug,2, 'ditevent_criteria_indicatie',		$ditevent_criteria_indicatie);
	wachthond($extdebug,2, 'ditevent_criteria_oordeel',			$ditevent_criteria_oordeel);

	wachthond($extdebug,3, 'ditevent_criteriacheck_start', 		$ditevent_criteriacheck_start);
	wachthond($extdebug,3, 'ditevent_criteriacheck_einde', 		$ditevent_criteriacheck_einde);

/*
	$criteriacheck_ditmoment 		= 0;
	$criteriacheck_voorheen 		= 0;

	if ($ditevent_criteriacheck_start AND empty($ditevent_criteriacheck_einde)) {
		$criteriacheck_ditmoment 	= 1;
	}
	if ($ditevent_criteriacheck_start AND $ditevent_criteriacheck_einde) {
		$criteriacheck_voorheen 	= 1;
	}

	wachthond($extdebug,3, 'criteriacheck_voorheen', 			$criteriacheck_voorheen);
	wachthond($extdebug,3, 'criteriacheck_ditmoment', 			$criteriacheck_ditmoment);
*/

	if ($new_ditevent_criteria_oordeel == 'oordeelnietnodig') {
		$new_criteriacheck_start = "";
		wachthond($extdebug,1, "### EMPTY DATUM CRITERIACHECK INDIEN OVERBODIG ###", $new_criteriacheck_start);
	}

	# INDIEN CRITERIAOORDEEL NOG NODIG
	if ($new_ditevent_criteria_oordeel == 'oordeelnognodig') {
		$new_criteriacheck_start = $ditevent_register_date;
		wachthond($extdebug,1, "### FORCEER DATUM CRITERIACHECK INDIEN NODIG ###", $new_criteriacheck_start);
	}

	# INDIEN OP CRITERIACHECK EN DATUM CRITERIACHECK START IS NOG LEEG
	if ($new_ditevent_part_status_id == '8') {
		$new_criteriacheck_start = $ditevent_register_date;
		wachthond($extdebug,1, "### FORCEER DATUM CRITERIACHECK INDIEN NODIG ###", $new_criteriacheck_start);
	}

	if ($new_ditevent_criteria_oordeel == "oordeelnietnodig") {
		$new_criteriacheck_start = "";
		wachthond($extdebug,1, "### CORRIGEER DATUM CRITERIACHECK INDIEN ONTERECHT INGEVULD ###", "[maak leeg]");
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 4.3 STATUS NA HANDMATIGE BEOORDELING",   $displayname);
	wachthond($extdebug,2, "########################################################################");

	if ($ditevent_part_rol == 'deelnemer') {

		###########################################################################################
		### INDIEN HANDMATIGE BEOORDELING CRITERIA = PRIMA > PAS DAN STATUS AAN 
		// M61 CHECK URGENT: 	heel erg tricky want moet rekening houden met wachtlijst
		// M61:					status kan alleen van 'afwachting beoordeling' naar geregistreerd indien er ruimte is
		// M61:					een check kan zijn lineitem want bij 'echte wachtlijst' is er nog geen betaling 
		// M61: dit hangt af van het perfect kloppen van de waarde van part_deel wachtlijst_erop en wachtlijst_eraf
		###########################################################################################
/*
		wachthond($extdebug,3, 'ditevent_part_status_id',			$ditevent_part_status_id);
		wachthond($extdebug,3, "ditevent_deelnamestatus",			$ditevent_deelnamestatus);
		wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
		wachthond($extdebug,3, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);

		wachthond($extdebug,3, "########################################################################");
	    wachthond($extdebug,3, 'ditevent_criteria_leeftijd', 		$ditevent_criteria_leeftijd);
		wachthond($extdebug,3, 'ditevent_criteria_school', 			$ditevent_criteria_school);
		wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
		wachthond($extdebug,3, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);

		wachthond($extdebug,3, "########################################################################");
		wachthond($extdebug,3, 'ditevent_wachtlijst_erop',			$ditevent_wachtlijst_erop);
		wachthond($extdebug,3, 'ditevent_wachtlijst_eraf',			$ditevent_wachtlijst_eraf);
		wachthond($extdebug,3, 'ditevent_criteriacheck_start', 		$ditevent_criteriacheck_start);
		wachthond($extdebug,3, 'ditevent_criteriacheck_einde',		$ditevent_criteriacheck_einde);
		wachthond($extdebug,3, "########################################################################");
*/
		if ($ditevent_part_status_id == 8) { // = 'AFWACHTING OORDEEL'

			if ($ditevent_criteria_oordeel == "oordeelprima" AND $ditevent_criteriacheck_einde) {

				if (empty($ditevent_wachtlijst_erop) AND empty($ditevent_wachtlijst_eraf)) {

					$new_ditevent_part_status_id 	= 1;
					$new_ditevent_part_status_name 	= 'Registered';
					$new_ditevent_deelnamestatus 	= 'Bevestigd';

					wachthond($extdebug,3, "org_ditevent_deelnamestatus",		$ditevent_deelnamestatus);
					wachthond($extdebug,3, "new_ditevent_deelnamestatus",		$new_ditevent_deelnamestatus);

					wachthond($extdebug,3, "'AFWACHTING OORDEEL' > 'REGISTERED' NA 'OORDEELPRIMA'", "[!= WACHTLIJST]");
				}

				if ($ditevent_wachtlijst_erop AND $ditevent_wachtlijst_eraf) {

					// M61 TODO deze conditie zou niet moeten kunnen.
					// Na aanmelding meteen criteriacheck
					// Niet eerst voorheen wachtlijst en dan pas criteriacheck
					// Tenzij ineens tussentijds geboortedatum / school wordt aangepast

//					$new_ditevent_part_status_id 	= 1;
//					$new_ditevent_part_status_name 	= 'Registered';
//					$new_ditevent_deelnamestatus 	= 'Bevestigd';

					wachthond($extdebug,3, "org_ditevent_deelnamestatus",		$ditevent_deelnamestatus);
					wachthond($extdebug,3, "new_ditevent_deelnamestatus",		$new_ditevent_deelnamestatus);

					wachthond($extdebug,3, "'AFWACHTING OORDEEL' > 'REGISTERED' NA 'OORDEELPRIMA'", "[WACHTLIJST ERAF]");
				}

				// ZET (NA AFWACHTING OORDEEL) OP STATUS WACHTLIJST INDIEN WACHTLIJST

				if ($ditevent_wachtlijst_erop AND empty($ditevent_wachtlijst_eraf)) {

					$new_ditevent_part_status_id 	= 7;
					$new_ditevent_part_status_name 	= 'On waitlist';
					$new_ditevent_deelnamestatus 	= 'Wachtlijst';

					wachthond($extdebug,3, "'AFWACHTING OORDEEL' > 'WACHTLIJST' NA 'OORDEELPRIMA'", "[== WACHTLIJST]");
				}
			}
		}

		if ($ditevent_part_status_id == 9) { // = 'VOORHEEN WACHTLIJST'

			$new_ditevent_part_status_id 	= 9;
			$new_ditevent_part_status_name 	= 'Pending from waitlist';
			$new_ditevent_deelnamestatus 	= 'Afwachting';

		}

		// M61: DIT WAS EERST EEN MANIER OM TE CHECKEN OF HET WACHTLIJST WAS

		if ($ditevent_lineitem_contribid > 0) {
			// M61: GEEN WACHTLIJST 	?
		} else {
			// M61: WEL WACHTLIJST 		?
		}

	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS - 5.0 SCHRIJF DE NIEUWE WAARDEN NAAR REGISTRATIE");
	wachthond($extdebug,3, "########################################################################");

	$params_status_part_update = [
#		'reload' 			=> TRUE,
		'checkPermissions' 	=> FALSE,
		'where' => [
			['id', '=', $ditevent_part_id],
		],
		'values' => [
			'id' 	=> 	$ditevent_part_id,
		],				
	];

	if ($new_ditevent_part_status_id) {
		$params_status_part_update['values']['status_id']								= $new_ditevent_part_status_id;
	}
	if ($new_ditevent_deelnamestatus) {
		$params_status_part_update['values']['PART.deelnamestatus:label']				= $new_ditevent_deelnamestatus;
	}
	if ($new_wachtlijst_erop) {
		$params_status_part_update['values']['PART_DEEL_INTERN.wachtlijst_erop']		= $new_wachtlijst_erop;
	}
	if ($new_wachtlijst_eraf) {
		$params_status_part_update['values']['PART_DEEL_INTERN.wachtlijst_eraf']		= $new_wachtlijst_eraf;
	}
	if ($new_criteriacheck_start) {
		$params_status_part_update['values']['PART_DEEL_INTERN.criteriacheck_start']	= $new_criteriacheck_start;
	}
	if ($new_criteriacheck_einde) {
		$params_status_part_update['values']['PART_DEEL_INTERN.criteriacheck_einde']	= $new_criteriacheck_einde;
	}

	wachthond($extdebug,3, "params_status_part_update", 						$params_status_part_update);

	if ($ditevent_part_id) {
		$result_status_part_update = civicrm_api4('Participant', 'update', 		$params_status_part_update);
		wachthond($extdebug,1, "params_status_part_update", "EXECUTED");
		wachthond($extdebug,9, "result_status_part_update", 					$result_status_part_update);
	}

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS 6.0 MAAK DEFINITIEF RESULTAAT",          $displayname);
	wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, 'displayname',           				$displayname);
    wachthond($extdebug,3, 'ditevent_contact_id',           		$ditevent_part_contact_id);
    wachthond($extdebug,3, 'ditevent_part_eventid',         		$ditevent_part_eventid);
    wachthond($extdebug,3, 'ditevent_event_kampnaam',       		$ditevent_event_kampnaam);
    wachthond($extdebug,3, 'ditevent_event_kampkort',       		$ditevent_event_kampkort);
    wachthond($extdebug,3, 'ditevent_part_rol',       				$ditevent_part_rol);

	if ($ditevent_part_rol == 'deelnemer') {
		wachthond($extdebug,3, 'ditevent_criteria_indicatie', 		$ditevent_criteria_indicatie);
		wachthond($extdebug,3, 'ditevent_criteria_oordeel', 		$ditevent_criteria_oordeel);
	}

    wachthond($extdebug,3, 'new_ditevent_part_status_id',       	$new_ditevent_part_status_id);
    wachthond($extdebug,3, 'new_ditevent_part_status_name',     	$new_ditevent_part_status_name);
    wachthond($extdebug,3, 'new_ditevent_deelnamestatus',     		$new_ditevent_deelnamestatus);

	if ($ditevent_part_rol == 'deelnemer') {

	   	$leeftijd_status = array(
	   		'displayname'							=> 	$displayname,
	   		'contact_id'							=> 	$ditevent_part_contact_id,
	   		'ditevent_eventid'						=> 	$ditevent_part_eventid,
	   		'ditevent_event_kampnaam'				=> 	$ditevent_event_kampnaam,
	   		'ditevent_event_kampkort'				=> 	$ditevent_event_kampkort,
	   		'ditevent_part_kampnaam'				=> 	$ditevent_part_kampnaam,
	   		'ditevent_part_kampkort'				=> 	$ditevent_part_kampkort,

			'wachtlijst_erop'						=>	$new_wachtlijst_erop,
			'wachtlijst_eraf'						=>	$new_wachtlijst_eraf,
			'criteriacheck_start'					=>	$new_criteriacheck_start,
			'criteriacheck_einde'					=>	$new_criteriacheck_einde,

	    	'ditevent_part_status_id'				=> 	$new_ditevent_part_status_id,
	    	'ditevent_part_status_name' 			=> 	$new_ditevent_part_status_name,
	    	'ditevent_deelnamestatus' 				=> 	$new_ditevent_deelnamestatus,
		);
	}
	if ($ditevent_part_rol == 'leiding') {

	   	$leeftijd_status = array(
	   		'displayname'							=> 	$displayname,
	   		'contact_id'							=> 	$ditevent_part_contact_id,
	   		'ditevent_eventid'						=> 	$ditevent_part_eventid,
	   		'ditevent_event_kampnaam'				=> 	$ditevent_event_kampnaam,
	   		'ditevent_event_kampkort'				=> 	$ditevent_event_kampkort,
	   		'ditevent_part_kampnaam'				=> 	$ditevent_part_kampnaam,
	   		'ditevent_part_kampkort'				=> 	$ditevent_part_kampkort,

	    	'ditevent_part_status_id'				=> 	$new_ditevent_part_status_id,
	    	'ditevent_part_status_name' 			=> 	$new_ditevent_part_status_name,
	    	'ditevent_deelnamestatus' 				=> 	$new_ditevent_deelnamestatus,
		);
	}

	wachthond($extdebug,2, "########################################################################");		
	wachthond($extdebug,3, "RETURN leeftijd_status", 		                         $leeftijd_status);
	wachthond($extdebug,2, "########################################################################");

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,1, "### LEEFTIJD STATUS - BEPAAL PART STATUS & DEELNAMESTATUS",     "[EINDE]");
	wachthond($extdebug,2, "########################################################################");

	return $leeftijd_status;
}

function leeftijd_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    $extdebug   = 0;  		//  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug   = FALSE;

    if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?
        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,4, "### DEEL_INTERN [PRE] EXIT: op != create OR op != edit", "(op: $op)");
        wachthond($extdebug,4, "########################################################################");
        return;
    }

    $extwrite               = 1;
    $extpartdeelintern		= 1;

    $today_datetime         = date("Y-m-d H:i:s");
    $today_datetime_past    = date('Y-m-d H:i:s', strtotime('-99 year', strtotime($today_datetime)) );
    wachthond($extdebug,4, 'today_datetime_past',       $today_datetime_past);

    $today_datetime_db  	= date("YmdHis");
    wachthond($extdebug,4, 'today_datetime',          	$today_datetime);
    wachthond($extdebug,4, 'today_datetime_db',         $today_datetime_db);

    $profilepartdeelintern 	= array(271);

    if (in_array($groupID, $profilepartdeelintern))  {

        $contact_id = $entityID;

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] START VOOR $entityID", "[groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

    } else {
        return;
    }

    if (in_array($groupID, $profilepartdeelintern))  {

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### DEEL_INTERN [PRE] 1.1 RETRIEVE VALUES FROM PARAMS");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,2, "entityid",    $entityID);
        wachthond($extdebug,4, "params",      $params);

        foreach($params as $i=>$item) {

            if ( !isset($indexed[$i][$item['id']]) ) {
                $indexed[$i]['key']         = $i;
//              $indexed[$i]['entity_id']   = $item['entity_id']    ?? NULL;
                $indexed[$i]['column_name'] = $item['column_name']  ?? NULL;
//              $indexed[$i]['table_name']  = $item['table_name']   ?? NULL;
                $indexed[$i]['value']       = $item['value']        ?? NULL;
            }

            if (!isset($key_criteria_leeftijd[$i])  	AND $item['column_name'] == "criteria_leeftijd_1148") 		{
            	$all_criteria_leeftijd[]  		= $i;
            }
            if (!isset($key_criteria_school[$i])    	AND $item['column_name'] == "criteria_school_1149") 		{
            	$all_criteria_school[]   		= $i;
            }
            if (!isset($key_criteria_indicatie[$i]) 	AND $item['column_name'] == "criteria_indicatie_1428") 		{
            	$all_criteria_indicatie[] 		= $i;
            }
            if (!isset($key_criteria_oordeel[$i])   	AND $item['column_name'] == "criteria_beoordeling_1429")	{
            	$all_criteria_oordeel[] 		= $i;
            }
            if (!isset($key_criteriacheck_start[$i])    AND $item['column_name'] == "criteriacheck_start_2091") 	{
            	$all_criteriacheck_start[]     	= $i;
            }
            if (!isset($key_criteriacheck_einde[$i])	AND $item['column_name'] == "criteriacheck_einde_2092") 	{
            	$all_criteriacheck_einde[]    	= $i;
            }
            if (!isset($key_wachtlijst_erop[$i])    	AND $item['column_name'] == "wachtlijst_erop_2093") 		{
            	$all_wachtlijst_erop[]    		= $i;
            }
            if (!isset($key_wachtlijst_eraf[$i])    	AND $item['column_name'] == "wachtlijst_eraf_2094") 		{
            	$all_wachtlijst_eraf[]    		= $i;
            }

        }

        wachthond($extdebug,4, "indexed", $indexed);

        $key_criteria_leeftijd  	= $all_criteria_leeftijd[0];
        $key_criteria_school     	= $all_criteria_school[0];
        $key_criteria_indicatie   	= $all_criteria_indicatie[0];
        $key_criteria_oordeel   	= $all_criteria_oordeel[0];        

        $key_criteriacheck_start    = $all_criteriacheck_start[0];
		$key_criteriacheck_einde	= $all_criteriacheck_einde[0];        
        $key_wachtlijst_erop      	= $all_wachtlijst_erop[0];
        $key_wachtlijst_eraf      	= $all_wachtlijst_eraf[0];

        wachthond($extdebug,3,  "key_criteria_leeftijd",    $key_criteria_leeftijd);
        wachthond($extdebug,3,  "key_criteria_school",   	$key_criteria_school);    
        wachthond($extdebug,3,  "key_criteria_indicatie", 	$key_criteria_indicatie);
        wachthond($extdebug,3,  "key_criteria_oordeel", 	$key_criteria_oordeel);

        wachthond($extdebug,3,  "key_criteriacheck_start", 	$key_criteriacheck_start);
        wachthond($extdebug,3,  "key_criteriacheck_einde",  $key_criteriacheck_einde);
        wachthond($extdebug,3,  "key_wachtlijst_erop",		$key_wachtlijst_erop);
        wachthond($extdebug,3,  "key_wachtlijst_eraf",    	$key_wachtlijst_eraf);

       	##########################################################################################
        ### GET CRITERIA LEEFTIJD
        ##########################################################################################        

        if ($key_criteria_leeftijd >= 0) {
            $raw_criteria_leeftijd   	= $params[$key_criteria_leeftijd]['value']    		?? NULL;
			$val_criteria_leeftijd 		= $raw_criteria_leeftijd;
//          $val_criteria_leeftijd 		= array_filter(explode('', $raw_criteria_leeftijd));
            wachthond($extdebug,2,      "val_criteria_leeftijd",  $val_criteria_leeftijd);
        }

       	##########################################################################################
        ### GET CRITERIA SCHOOL
        ##########################################################################################        

        if ($key_criteria_school >= 0) {
            $raw_criteria_school   		= $params[$key_criteria_school]['value']    		?? NULL;
			$val_criteria_school		= $raw_criteria_school;
//          $val_criteria_school      	= array_filter(explode('', $raw_criteria_school));
            wachthond($extdebug,2,      "val_criteria_school",  $val_criteria_school);
        }

       	##########################################################################################
        ### GET CRITERIA INDICATIE
        ##########################################################################################        

        if ($key_criteria_indicatie >= 0) {
            $raw_criteria_indicatie  	= $params[$key_criteria_indicatie]['value']    		?? NULL;
			$val_criteria_indicatie 	= $raw_criteria_indicatie;
//          $val_criteria_indicatie 	= array_filter(explode('', $raw_criteria_indicatie));
            wachthond($extdebug,2,      "val_criteria_indicatie",  $val_criteria_indicatie);
        }

       	##########################################################################################
        ### GET CRITERIA OORDEEL
        ##########################################################################################        

        if ($key_criteria_oordeel >= 0) {
            $raw_criteria_oordeel   	= $params[$key_criteria_oordeel]['value']			?? NULL;
            $val_criteria_oordeel 		= $raw_criteria_oordeel;
//          $val_criteria_oordeel      	= array_filter(explode('', $raw_criteria_oordeel));
            wachthond($extdebug,2,      "val_criteria_oordeel",  $val_criteria_oordeel);
        }

        ##########################################################################################
        ### GET CRITERIACHECK START 
        ##########################################################################################        

        if ($key_criteriacheck_start >= 0) {
            $raw_criteriacheck_start  	= $params[$key_criteriacheck_start]['value']       	?? NULL;
			if ($raw_criteriacheck_start) {
            	$val_criteriacheck_start    = date("Y-m-d H:i:s", strtotime($raw_criteriacheck_start)); 
            } else {
            	$val_criteriacheck_start    = NULL; 
            }
            wachthond($extdebug,4,      "raw_criteriacheck_start",   	$raw_criteriacheck_start);
            wachthond($extdebug,2,      "val_criteriacheck_start",   	$val_criteriacheck_start);
        }

        ##########################################################################################
        ### GET CRITERIACHECK EINDE
        ##########################################################################################        

        if ($key_criteriacheck_einde >= 0) {
            $raw_criteriacheck_einde  	= $params[$key_criteriacheck_einde]['value']       	?? NULL;
			if ($raw_criteriacheck_einde) {
            	$val_criteriacheck_einde    = date("Y-m-d H:i:s", strtotime($raw_criteriacheck_einde)); 
            } else {
            	$val_criteriacheck_einde    = NULL; 
            }
            wachthond($extdebug,4,      "raw_criteriacheck_einde",     $raw_criteriacheck_einde);
            wachthond($extdebug,2,      "val_criteriacheck_einde",     $val_criteriacheck_einde);
        }

        ##########################################################################################
        ### GET WACHTLIJST START 
        ##########################################################################################        

        if ($key_wachtlijst_start >= 0) {
            $raw_wachtlijst_start  		= $params[$key_wachtlijst_start]['value']      		?? NULL;
			if ($raw_wachtlijst_start) {
            	$val_wachtlijst_start    = date("Y-m-d H:i:s", strtotime($raw_wachtlijst_start)); 
            } else {
            	$val_wachtlijst_start    = NULL; 
            }
            wachthond($extdebug,4,      "raw_wachtlijst_start",     $raw_wachtlijst_start);
            wachthond($extdebug,2,      "val_wachtlijst_start",     $val_wachtlijst_start);
        }

        ##########################################################################################
        ### GET WACHTLIJST EINDE
        ##########################################################################################        

        if ($key_wachtlijst_einde >= 0) {
            $raw_wachtlijst_einde  		= $params[$key_wachtlijst_einde]['value']      		?? NULL;
			if ($raw_wachtlijst_einde) {
            	$val_wachtlijst_einde    = date("Y-m-d H:i:s", strtotime($raw_wachtlijst_einde)); 
            } else {
            	$val_wachtlijst_einde    = NULL; 
            }
			wachthond($extdebug,4,      "raw_wachtlijst_einde",     $raw_wachtlijst_einde);
            wachthond($extdebug,2,      "val_wachtlijst_einde",     $val_wachtlijst_einde);
        }

        ##########################################################################################
        ### SET DE OUDE WAARDE ALS DEFAULTS VOOR DE NIEUWE WAARDEN
        ##########################################################################################        

		$new_criteria_school		=	$val_criteria_school;
		$new_criteria_leeftijd		=	$val_criteria_leeftijd;
		$new_criteria_indicatie		=	$val_criteria_indicatie;
		$new_criteria_oordeel		=	$val_criteria_oordeel;

		$new_criteriacheck_start	=	$val_criteriacheck_start;
		$new_criteriacheck_einde	=	$val_criteriacheck_einde;
		$new_wachtlijst_start		=	$val_wachtlijst_start;
		$new_wachtlijst_einde		=	$val_wachtlijst_einde;

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 1.2 UPDATE CRITERIACHECK_START INDIEN NODIG");
        wachthond($extdebug,1, "########################################################################");

		if (in_array($val_criteria_indicatie, array('criteriawijktaf','schoolwijktaf','leeftijdwijktaf'))) {

			wachthond($extdebug,3, "val_criteria_indicatie",   	$val_criteria_indicatie);

	        if (empty($val_criteriacheck_start)) {

	        	$new_criteriacheck_start = $today_datetime_db;
				wachthond($extdebug,3, "new_criteriacheck_start",	$new_criteriacheck_start);
	        }
		}

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 1.3 MAAK CRITERIACHECK_START LEEG INDIEN OVERBODIG");
        wachthond($extdebug,1, "########################################################################");

		if ($val_criteria_indicatie == 'criteriaprima') {

			wachthond($extdebug,3, "val_criteria_indicatie",   	$val_criteria_indicatie);

			wachthond($extdebug,3, "val_criteriacheck_start",   $val_criteriacheck_start);
			wachthond($extdebug,3, "val_criteriacheck_einde",   $val_criteriacheck_einde);

	        if ($val_criteriacheck_start OR $val_criteriacheck_einde) {

	        	$new_criteriacheck_start 	= "";
	        	$new_criteriacheck_einde 	= "";

	        	$new_criteria_oordeel 		= 'nietnodig';

				wachthond($extdebug,3, "new_criteriacheck_start",	$new_criteriacheck_start);
				wachthond($extdebug,3, "new_criteriacheck_einde",	$new_criteriacheck_einde);

	        }
		}

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 1.4 BEPAAL WAARDEN INDIEN SCHOOL & LEEFTIJD PRIMA");
        wachthond($extdebug,1, "########################################################################");

		if ($val_criteria_school == 'prima' AND $val_criteria_leeftijd == 'prima') {

			wachthond($extdebug,3, "val_criteria_school",   	$val_criteria_school);
			wachthond($extdebug,3, "val_criteria_leeftijd",   	$val_criteria_leeftijd);			

        	$new_criteriacheck_start 	= "";
        	$new_criteriacheck_einde 	= "";

        	$new_criteria_indicatie 	= 'criteriaprima';
        	$new_criteria_oordeel 		= 'oordeelnietnodig';

			wachthond($extdebug,3, "new_criteria_indicatie",	$new_criteria_indicatie);
			wachthond($extdebug,3, "new_criteria_oordeel",		$new_criteria_oordeel);				
			wachthond($extdebug,4, "new_criteriacheck_start",	$new_criteriacheck_start);
			wachthond($extdebug,4, "new_criteriacheck_einde",	$new_criteriacheck_einde);
		}

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 1.5 UPDATE CRITERIACHECK_EINDE INDIEN OORDEEL PRIMA");
        wachthond($extdebug,1, "########################################################################");

		if (in_array($val_criteria_indicatie, array('criteriawijktaf','schoolwijktaf','leeftijdwijktaf'))) {

			wachthond($extdebug,3, "val_criteria_indicatie",   	$val_criteria_indicatie);

	        if (empty($val_criteriacheck_einde) AND  $val_criteria_indicatie == 'oordeelprima') {

//				M61: TODO Nog even niet automatisch omdat anders HL goed nieuwsmail kan triggeren
//				M61: En dan nog geen rekening houdt met wachtlijsten, voorkeuren en capaciteit enzo

//	        	$new_criteriacheck_einde = $today_datetime_db;
	        }
		}

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 2.1 UPDATE CRITERIA_INDICATIE", 	       "[PARAMS]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,4, 'key_criteria_indicatie',                  	$key_criteria_indicatie);

        if (is_numeric($key_criteria_indicatie) AND $new_criteria_indicatie != $val_criteria_indicatie) {

            wachthond($extdebug,3, 'OLD params[key_criteria_indicatie]',   	$params[$key_criteria_indicatie]);
            $params[$key_criteria_indicatie]['value'] =                    	$new_criteria_indicatie;
            wachthond($extdebug,3, 'NEW params[key_criteria_indicatie]',   	$params[$key_criteria_indicatie]);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 2.2 UPDATE CRITERIA_OORDEEL", 	       "[PARAMS]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,4, 'key_criteria_oordeel',                  	$key_criteria_oordeel);

        if (is_numeric($key_criteria_oordeel) AND $new_criteria_oordeel != $val_criteria_oordeel) {

            wachthond($extdebug,3, 'OLD params[key_criteria_oordeel]',   	$params[$key_criteria_oordeel]);
            $params[$key_criteria_oordeel]['value'] =                    	$new_criteria_oordeel;
            wachthond($extdebug,3, 'NEW params[key_criteria_oordeel]',   	$params[$key_criteria_oordeel]);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 2.3 UPDATE CRITERIACHECK START",         "[PARAMS]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,4, 'key_criteriacheck_start',                  	$key_criteriacheck_start);

        wachthond($extdebug,4, 'new_criteriacheck_start',                  	$new_criteriacheck_start);
        wachthond($extdebug,4, 'val_criteriacheck_start',                  	$val_criteriacheck_start);

      	if (is_numeric($key_criteriacheck_start) AND $new_criteriacheck_start != $val_criteriacheck_start) {

            wachthond($extdebug,3, 'OLD params[key_criteriacheck_start]',   $params[$key_criteriacheck_start]);
            $params[$key_criteriacheck_start]['value'] =                    $new_criteriacheck_start;
            wachthond($extdebug,3, 'NEW params[key_criteriacheck_start]',   $params[$key_criteriacheck_start]);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] 2.4 UPDATE CRITERIACHECK EINDE",         "[PARAMS]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,4, 'key_criteriacheck_einde',                  	$key_criteriacheck_einde);

        if (is_numeric($key_criteriacheck_einde) AND $new_criteriacheck_einde != $val_criteriacheck_einde) {

            wachthond($extdebug,3, 'OLD params[key_criteriacheck_einde]',   $params[$key_criteriacheck_einde]);
            $params[$key_criteriacheck_einde]['value'] =                    $new_criteriacheck_einde;
            wachthond($extdebug,3, 'NEW params[key_criteriacheck_einde]',   $params[$key_criteriacheck_einde]);
        }

        wachthond($extdebug,4, "NEW params",                $params);		

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### DEEL_INTERN [PRE] EINDE VOOR $entityID",        "[groupID: $groupID]");
        wachthond($extdebug,1, "########################################################################");

   	}            
}

/**
 * Implementation of hook_civicrm_config
 */
function leeftijd_civicrm_config(&$config)
{
		_leeftijd_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */

/*
function leeftijd_civicrm_xmlMenu(&$files)
{
		_leeftijd_civix_civicrm_xmlMenu($files);
}
*/


/**
 * Implementation of hook_civicrm_install
 */
function leeftijd_civicrm_install()
{
		return _leeftijd_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 */
function leeftijd_civicrm_enable()
{
		return _leeftijd_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */

/*
function leeftijd_civicrm_managed(&$entities)
{
		return _leeftijd_civix_civicrm_managed($entities);
}
*/