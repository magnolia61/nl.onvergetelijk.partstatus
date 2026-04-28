<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.criteria.php
 * =======================================================================================
 *   partstatus_criteria()  FUNCTIONEEL: Bepaalt of een deelnemer voldoet aan de leeftijd- en s...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Criteria Component)
 * FUNCTIONEEL: Bepaalt of een deelnemer voldoet aan de leeftijd- en schoolcriteria.
 * @param int $part_id				Het ID van de deelnemer (Verplicht)
 * @param array|null $array_part	Optioneel: Vooraf opgehaalde data (Lazy Loading fallback)
 * @param float|null $leeftijd_dec	Optioneel: Vooraf berekende leeftijd
 */
function partstatus_criteria($part_id, $array_part = NULL, $leeftijd_dec = NULL) {
	
	$extdebug = 0; // Zet op 3 of 4 als je de output van de logica wilt zien in Watchdog

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 1.0 - DATA VERZAMELEN",			     "[START]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING SECTIE 1.0:
	 * Controleert de basisvereisten voor de criteria-check. Haalt ontbrekende data op via 
	 * Lazy Loading en stopt direct als de deelnemer 'leiding' is (zij hebben geen criteria).
	 * Berekent ook de leeftijd (als fallback) indien deze niet expliciet was meegegeven.
	 */

	if (empty($array_part) && $part_id) {
		wachthond($extdebug, 3, "Geen data meegegeven, ophalen via base_pid2part()", "[LAZY LOAD]");
		if (function_exists('base_pid2part')) {
			$array_part = base_pid2part($part_id);
		}
	}

	if (empty($array_part)) return NULL;

	// FILTER: Controleer de rol. Leiding is vrijgesteld van leeftijds- en schoolcriteria.
	$part_rol = $array_part['part_rol'] ?? NULL;
	if ($part_rol != 'deelnemer') {
		wachthond($extdebug, 3, "Rol is '$part_rol'. Criteria-check geannuleerd", "[CANCEL]");
		return NULL; 
	}

	// FILTER: Criteria-berekeningen gelden alleen voor specifieke event types
	$event_type_id = $array_part['event_type_id'] ?? ($array_part['event_id.event_type_id'] ?? 0);
	if (!in_array($event_type_id, [11, 12, 13, 14, 21, 22, 23, 24, 33])) {
		wachthond($extdebug, 3, "Event type ($event_type_id) gebruikt geen criteria-berekeningen", "[CANCEL]");
		return NULL;
	}

	// FALLBACK: Als er geen leeftijd is meegegeven, berekenen we deze zelf op basis van de startdatum van het event.
	if ($leeftijd_dec === NULL && !empty($array_part['birth_date']) && !empty($array_part['event_start_date'])) {
		wachthond($extdebug, 3, "Leeftijd niet meegegeven, lokale herberekening gestart", "[CALC]");
		$calc			= partstatus_leeftijd_diff('ditevent', $array_part['birth_date'], $array_part['event_start_date']);
		$leeftijd_dec	= $calc['leeftijd_decimalen'] ?? NULL;
		wachthond($extdebug, 4, "Leeftijd berekend via fallback logica", "[$leeftijd_dec]");
	}

	// Basisvariabelen inladen voor de komende regels
	$kampkort			= $array_part['part_kampkort']		?? NULL;
	$groepklas			= $array_part['part_groepklas']		?? NULL;
	$leeftijd			= (float)$leeftijd_dec;
	$criteria_oordeel	= $array_part['criteria_oordeel']	?? NULL;
	$criteria_indicatie	= NULL;

	wachthond($extdebug, 1, "Input voor beoordeling kampkort",  	"[$kampkort]");
	wachthond($extdebug, 1, "Input voor beoordeling groep/klas",   	"[$groepklas]");
	wachthond($extdebug, 1, "Input voor beoordeling leeftijd",  	"[$leeftijd]");

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 2.0 - CORRECTIE GROEP/KLAS MIXUP",  "[GROEPKLAS]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING SECTIE 2.0:
	 * Herstelt veelgemaakte invulfouten door ouders door middel van een mapping array.
	 * (bijv. een deelnemer op een tienerkamp die invult in 'groep 2' te zitten, 
	 * wordt automatisch gecorrigeerd naar 'klas 2').
	 */

	$new_groepklas 		= $groepklas;
	
	// Map groep/klas terminologie op basis van kamptype
	if (in_array($kampkort, ['kk1', 'kk2'])) {
		$map 			= ['klas_3' => 'groep_3', 'klas_4' => 'groep_4', 'klas_5' => 'groep_5', 'klas_6' => 'groep_6'];
		$new_groepklas 	= $map[$groepklas] ?? $new_groepklas;
	}
	if (in_array($kampkort, ['tk1', 'tk2'])) {
		$map 			= ['groep_2' => 'klas_2', 'groep_3' => 'klas_3'];
		$new_groepklas 	= $map[$groepklas] ?? $new_groepklas;
	}
	if (in_array($kampkort, ['jk1', 'jk2'])) {
		$map 			= ['groep_3' => 'klas_3', 'groep_4' => 'klas_4', 'groep_5' => 'klas_5', 'groep_6' => 'klas_6'];
		$new_groepklas 	= $map[$groepklas] ?? $new_groepklas;
	}

	wachthond($extdebug, 3, "Origineel: $groepklas", "Gecorrigeerd: $new_groepklas");

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 3.0 - BEOORDELING SCHOOL",				"[SCHOOL]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING SECTIE 3.0:
	 * Beoordeelt de gecorrigeerde school/klas aan de hand van de kampspecifieke vereisten 
	 * vanuit een overzichtelijke goedkeurings-matrix ($prima_school).
	 */

	$criteria_school   	= 'afwijkend';
	$prima_school 		= [
		'kk1' => ["groep_3", "groep_4", "groep_5", "groep_6", "groep_7"],
		'kk2' => ["groep_3", "groep_4", "groep_5", "groep_6", "groep_7"],
		'bk1' => ["groep_8", "klas_1"],
		'bk2' => ["groep_8", "klas_1"],
		'tk1' => ["klas_2",  "klas_3"],
		'tk2' => ["klas_2",  "klas_3"],
		'jk1' => ["klas_4",  "klas_5", "klas_6", "vervolg"],
		'jk2' => ["klas_4",  "klas_5", "klas_6", "vervolg"],
	];

	if (isset($prima_school[$kampkort]) && in_array($new_groepklas, $prima_school[$kampkort])) {
		$criteria_school = 'prima';
	}

	// Marge Check School (specifieke randgevallen)
	if (in_array($kampkort, ["tk1","tk2"]) && $new_groepklas == "klas_4")                    { $criteria_school = 'marge'; }
	if (in_array($kampkort, ["jk1","jk2"]) && in_array($new_groepklas, ["klas_2","klas_3"])) { $criteria_school = 'marge'; }

	// Override voor TOP kamp of andere kampen zonder strikte school-eisen in de array
	if (!isset($prima_school[$kampkort]) && !in_array($kampkort, ["tk1","tk2","jk1","jk2"])) {
		$criteria_school = 'prima';
	}

	wachthond($extdebug, 3, "Resultaat School ($new_groepklas) voor $kampkort beoordeeld als", $criteria_school);

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 4.0 - BEOORDELING LEEFTIJD",		  "[LEEFTIJD]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING SECTIE 4.0:
	 * Berekent de exacte leeftijd tegen de harde kampgrenzen. Definieert 'prima' (veilig),
	 * of 'marge' (nét eronder/erboven, handmatige check vereist). Alles daarbuiten is 'afwijkend'.
	 */

	$criteria_leeftijd 	= 'afwijkend';

	if (empty($leeftijd)) {
		$criteria_leeftijd = 'onbekend';
	} else {
		// Prima ijkpunten per kamptype
		if (in_array($kampkort, ['kk1', 'kk2']) && ($leeftijd >= 7.0  && $leeftijd < 12.0))     { $criteria_leeftijd = 'prima'; }
		if (in_array($kampkort, ['bk1', 'bk2']) && ($leeftijd >= 12.0 && $leeftijd < 14.0))     { $criteria_leeftijd = 'prima'; }
		if (in_array($kampkort, ['tk1', 'tk2']) && ($leeftijd >= 14.0 && $leeftijd < 16.0))     { $criteria_leeftijd = 'prima'; }
		if (in_array($kampkort, ['jk1', 'jk2']) && ($leeftijd >= 16.0 && $leeftijd < 18.0))     { $criteria_leeftijd = 'prima'; }
		if ($kampkort == 'top'                  && ($leeftijd >= 18.0 && $leeftijd < 21.0))     { $criteria_leeftijd = 'prima'; }

		// Marge Check Leeftijd per kamptype
		if (in_array($kampkort, ["kk1", "kk2"]) && (($leeftijd >= 6.7  && $leeftijd < 7.0)  || ($leeftijd >= 12.0 && $leeftijd <= 12.3))) { $criteria_leeftijd = 'marge'; }
		if (in_array($kampkort, ["bk1", "bk2"]) && (($leeftijd >= 11.3 && $leeftijd < 12.0) || ($leeftijd >= 14.0 && $leeftijd <= 14.3))) { $criteria_leeftijd = 'marge'; }
		if (in_array($kampkort, ["tk1", "tk2"]) && (($leeftijd >= 13.7 && $leeftijd < 14.0) || ($leeftijd >= 16.0 && $leeftijd <= 16.3))) { $criteria_leeftijd = 'marge'; }
		if (in_array($kampkort, ["jk1", "jk2"]) && (($leeftijd >= 15.7 && $leeftijd < 16.0) || ($leeftijd >= 18.0 && $leeftijd <= 18.3))) { $criteria_leeftijd = 'marge'; }
	}

	wachthond($extdebug, 3, "Resultaat Leeftijd ($leeftijd) voor $kampkort beoordeeld als", $criteria_leeftijd);

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 5.0 - BEPAAL EINDOORDEEL",			   "[OORDEEL]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING SECTIE 5.0:
	 * Combineert de leeftijd- en schoolbeoordeling tot één 'Indicatie'. 
	 * Bepaalt vervolgens of het huidige, handmatige 'Oordeel' gereset moet worden.
	 */

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### REGEL 5.1: CONTROLEER OP HANDMATIGE OVERRIDES");
	wachthond($extdebug, 2, "########################################################################");

	// BEHOUD: Als een beheerder expliciet heeft aangegeven dat deze persoon is afgewezen, 
	// goedgekeurd of buiten de criteria mag vallen, respecteren we die handmatige keuze.
	if (in_array($criteria_oordeel, ['oordeelprima', 'oordeelaangepast', 'oordeelafgewezen', 'buitencriteria'])) {
		wachthond($extdebug, 3, "Handmatig beheerderoordeel gedetecteerd en behouden: $criteria_oordeel", "[OVERRIDE]");
	} else {
		// Reset het oordeel als er geen expliciete override was ingesteld door een beheerder
		$criteria_oordeel = 'oordeelnognodig'; 
		wachthond($extdebug, 3, "Geen geldig handmatig oordeel gevonden. Status gereset naar", "[NOG NODIG]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### REGEL 5.2: EVALUATIE COMBINATIE-SCENARIO'S");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * OVERZICHT SCENARIO'S:
	 * A: Volledige match: Zowel leeftijd als schoolklas zijn 100% conform de kampnorm.
	 * B: Binnen marges: Eén van de waarden zit in de marge; wordt standaard goedgekeurd.
	 * C: Alleen school afwijkend: De leeftijd is prima, maar de schoolklas matcht niet.
	 * D: Alleen leeftijd afwijkend: De schoolklas is prima, maar de leeftijd matcht niet.
	 * E: Kritieke afwijking: Zowel leeftijd als school wijken af of er is onvoldoende data.
	 */

	// --------------------------------------------------------------------------------------
	// SCENARIO A: VOLLEDIGE MATCH (LEEFTIJD & SCHOOL)
	// --------------------------------------------------------------------------------------

	// REGEL: Als alles prima is, is een handmatig oordeel niet meer nodig
	if ($criteria_leeftijd == 'prima' && $criteria_school == 'prima') {
		
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 2, "### SCENARIO A: VOLLEDIGE MATCH (LEEFTIJD & SCHOOL)");
		wachthond($extdebug, 2, "########################################################################");
		
		$criteria_indicatie = 'criteriaprima';
		wachthond($extdebug, 3, "Match op leeftijd (prima) én school (prima) vastgesteld", "[OK]");

		if ($criteria_oordeel == 'oordeelnognodig') { 
			$criteria_oordeel = 'oordeelnietnodig'; 
			wachthond($extdebug, 3, "Status 'Nog nodig' automatisch goedgekeurd wegens perfecte match", "[NIET NODIG]");
		}
	} 

	// --------------------------------------------------------------------------------------
	// SCENARIO B: BINNEN DE MARGES
	// --------------------------------------------------------------------------------------

	// REGEL: Ook bij randgevallen keuren we het standaard automatisch goed
	elseif ($criteria_leeftijd == 'marge' || $criteria_school == 'marge') {
		
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 2, "### SCENARIO B: BINNEN DE MARGES");
		wachthond($extdebug, 2, "########################################################################");

		$criteria_indicatie = 'binnenmarges'; 
		wachthond($extdebug, 3, "Eén van de waarden valt binnen de marge (leeftijd of school)", "[MARGE]");

		if ($criteria_oordeel == 'oordeelnognodig') { 
			$criteria_oordeel = 'oordeelnietnodig'; 
			wachthond($extdebug, 3, "Status 'Nog nodig' automatisch goedgekeurd wegens marge-norm", "[NIET NODIG]");
		}
	}

	// --------------------------------------------------------------------------------------
	// SCENARIO C: SCHOOL AFWIJKEND (LEEFTIJD OK)
	// --------------------------------------------------------------------------------------

	// CORRECTIE: Check specifiek of alleen de SCHOOL afwijkt (bijv: leeftijd prima, groep 8 afwijkend)
	elseif ($criteria_leeftijd == 'prima' && $criteria_school == 'afwijkend') {
		
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 2, "### SCENARIO C: SCHOOL AFWIJKEND (LEEFTIJD OK)");
		wachthond($extdebug, 2, "########################################################################");

		$criteria_indicatie = 'schoolwijktaf';
		wachthond($extdebug, 3, "Leeftijd is prima, maar schoolklas wijkt af van kampnorm", "[SCHOOL_AFW]");
	}

	// --------------------------------------------------------------------------------------
	// SCENARIO D: LEEFTIJD AFWIJKEND (SCHOOL OK)
	// --------------------------------------------------------------------------------------

	// REGEL: Check of alleen de LEEFTIJD afwijkt
	elseif ($criteria_leeftijd == 'afwijkend' && $criteria_school == 'prima') {
		
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 2, "### SCENARIO D: LEEFTIJD AFWIJKEND (SCHOOL OK)");
		wachthond($extdebug, 2, "########################################################################");

		$criteria_indicatie = 'leeftijdwijktaf';
		wachthond($extdebug, 3, "Schoolklas is prima, maar leeftijd wijkt af van kampnorm", "[AGE_AFW]");
	}

	// --------------------------------------------------------------------------------------
	// SCENARIO E: DUBBELE AFWIJKING OF DATA-FOUT
	// --------------------------------------------------------------------------------------

	// REGEL: In alle andere gevallen (beide afwijkend, of leeftijd onbekend/0)
	else {
		
		wachthond($extdebug, 2, "########################################################################");
		wachthond($extdebug, 2, "### SCENARIO E: DUBBELE AFWIJKING OF DATA-FOUT");
		wachthond($extdebug, 2, "########################################################################");

		$criteria_indicatie = 'criteriawijktaf';
		wachthond($extdebug, 3, "Zowel leeftijd als school wijken af, of data is incompleet", "[DATA_AFW]");
	}

	wachthond($extdebug, 3, "Eindresultaat - Indicatie: $criteria_indicatie | Oordeel: $criteria_oordeel", "[FINAL]");

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS CRITERIA 1.0",			     					 "[EINDE]");
	wachthond($extdebug, 2, "########################################################################");

	// Retourneer alle verzamelde data en oordelen naar de aanroeper
	return [
		'criteria_leeftijd'		=> $criteria_leeftijd,
		'criteria_school'		=> $criteria_school,
		'criteria_indicatie'	=> $criteria_indicatie,
		'criteria_oordeel'		=> $criteria_oordeel,
		'new_groepklas'			=> $new_groepklas // Belangrijk: stuurt de ge-autocorrecte groep/klas terug voor opslag in DB!
	];

}