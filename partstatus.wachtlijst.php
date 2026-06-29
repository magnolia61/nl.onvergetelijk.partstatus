<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.wachtlijst.php
 * =======================================================================================
 *   partstatus_evaluate_wachtlijst()  FUNCTIONEEL: Handelt statusovergangen af voor wachtlijst en afwacht...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Wachtlijst Component)
 * FUNCTIONEEL: Handelt statusovergangen af voor wachtlijst en afwachting.
 * @param int $part_id				Het ID van de deelnemer (Verplicht)
 * @param array|null $array_part	Optioneel: Vooraf opgehaalde data
 * @param array|null $array_criteria Optioneel: Vooraf berekende criteria (Doorslaggevend!)
 */
function partstatus_evaluate_wachtlijst($part_id, $array_part = NULL, $array_criteria = NULL) { // Definieer de functie met 1 verplichte en 2 optionele parameters
	
	$extdebug = 'partstatus.wachtlijst'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

	if (empty($array_part) && $part_id) { 				// Controleer of de participant data ontbreekt, maar er wél een participant ID is
		if (function_exists('base_pid2part')) { 		// Controleer voor de zekerheid of de externe ophaal-functie bestaat
			$array_part = base_pid2part($part_id); 		// Haal de participant data op uit de database op basis van het ID
		}
	}

	if (empty($array_part)) return NULL; // Als we na de poging tot ophalen nog steeds geen data hebben, breek de functie af

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS WACHTLIJST 1.0 - DATA VERZAMELEN",				 "[START]");
	wachthond($extdebug, 2, "########################################################################");

	$current_status_id 	= $array_part['status_id'] 				?? 0; 		// Haal de huidige status op, of gebruik 0 (onbekend) als fallback
	$wl_erop 			= $array_part['wachtlijst_erop'] 		?? NULL; 	// Haal de startdatum van de wachtlijst op, of gebruik NULL
	$wl_eraf 			= $array_part['wachtlijst_eraf'] 		?? NULL; 	// Haal de einddatum van de wachtlijst op, of gebruik NULL
	$check_einde		= $array_part['criteriacheck_einde'] 	?? NULL; 	// Haal de afgeronde datum van de criteriacheck op, of NULL

	// GEBRUIK DE MEEST VERSE CRITERIA DATA (Prioriteit boven database)
	$indicatie 			= $array_criteria['criteria_indicatie']  ?? $array_part['criteria_indicatie'] 	?? NULL; // Gebruik verse indicatie, anders DB versie, anders NULL
	$oordeel 			= $array_criteria['criteria_oordeel'] 	 ?? $array_part['criteria_oordeel'] 	?? NULL; // Gebruik vers oordeel, anders DB versie, anders NULL
	$register_date 		= $array_part['register_date'] 			 ?? date("Y-m-d H:i:s"); // Haal registratiedatum op, of gebruik de exacte datum/tijd van nu als fallback

	$contribid 			= $array_part['part_kampgeld_contribid'] ?? 0; // Zoek het ID van de pecunia betaling, default is 0
	if (empty($contribid) && function_exists('pecunia_get_contribid_by_partid')) { // Als er nog geen betalings-ID is en de financiële module is actief...
		$contribid = pecunia_get_contribid_by_partid($part_id); // ...vraag het betalings-ID dan direct op bij de financiële module
	}
	$has_paylink 		= ($contribid > 0); 	// Maak een boolean (Waar/Niet Waar) die aangeeft of er een geldig betalingsdossier is (ID groter dan 0)
	$new_status_id 		= $current_status_id; 	// Stel de nieuwe status initieel in op wat de status nu al is
	$new_status_label 	= NULL; 				// Maak een lege variabele aan om straks de tekstuele naam van de status in op te slaan

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS WACHTLIJST 2.0 - EVALUATIE LOGICA",			     "[LOGIC]");
	wachthond($extdebug, 2, "########################################################################");

	wachthond($extdebug, 3, "WACHTLIJST INPUT - Status: $current_status_id | Oordeel: $oordeel | Paylink: " . ($has_paylink ? 'Ja' : 'Nee')); 

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL A: CORRECTIE VOOR ONBEKENDE STATUS (0, 5, 6)");
	wachthond($extdebug, 2, "########################################################################");
	
	/*
	 * SAMENVATTING REGEL A:
	 * Vangt 'zwevende' deelnemers op die buiten de actuele flow vallen. 
	 * * TECHNISCHE TOELICHTING STATUS-ID'S:
	 * - Status 0: Geen status (gevolg van mislukte import of database-fout).
	 * - Status 5: 'In afwachting' (oude, uitgefaseerde CiviCRM systeem-status).
	 * - Status 6: 'Op wachtlijst' (oude systeem-status, vervangen door Status 7).
	 * * Op basis van bekende wachtlijstdatums of criteria-oordelen worden deze records 
	 * hersteld en naar de juiste actuele status (1, 7 of 8) gestuurd.
	 */

	if (in_array($current_status_id, [0, 5, 6])) { // Controleer op 0 (leeg), 5 (legacy pending) of 6 (legacy wachtlijst)
		wachthond($extdebug, 3, "Status incompleet of verouderd ($current_status_id). Herstel start", 	"[START]"); 
		
		if (!empty($wl_erop) && empty($wl_eraf)) { 
			// Herstel naar actuele wachtlijst-status
			$new_status_id = 7; 
			wachthond($extdebug, 4, "Staat op wachtlijst -> Hersteld naar actuele Status 7", 			"[FIXED]"); 
		} elseif (in_array($oordeel, ['oordeelprima', 'oordeelaangepast', 'buitencriteria']) || in_array($indicatie, ['criteriaprima', 'binnenmarges'])) { 
			// Herstel naar bevestigd indien criteria reeds akkoord zijn
			$new_status_id = 1; 
			wachthond($extdebug, 4, "Oordeel/Indicatie is OK -> Direct hersteld naar Status 1", 		"[FIXED]"); 
		} else { 
			// Veiligheids-fallback: dwing een handmatig oordeel af via de actuele status 8
			$new_status_id = 8; 
			wachthond($extdebug, 4, "Geen duidelijke koers -> Doorgezet naar Status 8 voor controle", 	"[CHECK]"); 
		} 
	} else { 
		wachthond($extdebug, 4, "Huidige status $current_status_id is valide of reeds geactiveerd", 	"[SKIP]"); 
	} // Einde Regel A

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL 33: WACHTLIJST + AFWIJKENDE CRITERIA → COMBI-STATUS 33");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING REGEL 33:
	 * Vangt deelnemers op die op de wachtlijst staan én tegelijk afwijkende criteria hebben
	 * waarvoor een handmatig oordeel nodig is. Status 7 (Wachtlijst) is dan niet juist,
	 * want een betaalmail sturen is te vroeg — het oordeel moet eerst gegeven worden.
	 * Deze deelnemers krijgen status 33 (Wachtlijst + Criteria) als tussenstation.
	 */

	if ($new_status_id == 7
		&& $oordeel == 'oordeelnognodig'
		&& in_array($indicatie, ['leeftijdwijktaf', 'schoolwijktaf', 'criteriawijktaf'])) {
		$new_status_id = 33;
		wachthond($extdebug, 3, "Status 7 + afwijkend criteria ($indicatie) → combi-status 33", "[WACHT+CRITERIA]");
	} else {
		wachthond($extdebug, 4, "Geen combi-situatie (status=$new_status_id indicatie=$indicatie), Regel 33 overgeslagen", "[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL 33b: OORDEEL GOEDGEKEURD → TERUG NAAR NORMALE WACHTLIJST (7)");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING REGEL 33b:
	 * Als een beheerder een positief oordeel geeft terwijl er nog geen plek vrij is
	 * (deelnemer staat op status 33), stroomt de deelnemer door naar de normale wachtlijst
	 * (status 7). Zodra er dan een plek vrijkomt pakt Regel D dat op.
	 */

	if ($new_status_id == 33
		&& in_array($oordeel, ['oordeelprima', 'oordeelaangepast', 'buitencriteria'])) {
		$new_status_id = 7;
		wachthond($extdebug, 3, "Status 33 + oordeel positief ($oordeel) → terug naar normale wachtlijst (7)", "[PROMOTED]");
	} else {
		wachthond($extdebug, 4, "Geen positief oordeel op status 33, Regel 33b overgeslagen", "[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL B: OORDEEL AFGEROND (VAN STATUS 8 NAAR 9)");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING REGEL B:
	 * Ontgrendelt deelnemers die op status 8 (Afwachting Oordeel) stilstaan. 
	 * Zodra een beheerder een positief oordeel geeft of de einddatum van de check invult, 
	 * stromen ze door naar de financiële afhandeling (Status 9).
	 */

	if ($current_status_id == 8) { 	// Controleer of de huidige status 8 (Afwachting Oordeel) is
		wachthond($extdebug, 3, "Controleren of oordeel is gegeven voor deelnemer in afwachting", 		"[CHECK]"); 
		
		if (!empty($check_einde) || in_array($oordeel, ['oordeelprima', 'oordeelaangepast', 'buitencriteria', 'oordeelnietnodig'])) {
									// Bevordering naar de volgende fase: Afwachting betaling
			$new_status_id = 9; 
			wachthond($extdebug, 4, "Oordeel positief of afgerond -> Vrijgegeven naar Status 9", 		"[PROMOTED]"); 
		} else { 
									// Geen wijziging: oordeel is nog onvoldoende of incompleet
			wachthond($extdebug, 4, "Oordeel is nog niet afgerond -> Deelnemer blijft in fase 8", 		"[STAY]"); 
		} 
	} else { 
		wachthond($extdebug, 4, "Huidige status is niet 8, Regel B is niet van toepassing", 			"[SKIP]"); 
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL C: WACHTLIJST EROP (STATUS 7)");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING REGEL C:
	 * Zorgt dat de administratie sluitend is. Elke deelnemer op Status 7 (Wachtlijst) 
	 * móét een begindatum hebben. Ontbreekt deze, dan vullen we de initiële 
	 * registratiedatum van het formulier in als startpunt.
	 */

	if (in_array($new_status_id, [7, 33])) { // Controleer of de status is vastgesteld op 7 (Wachtlijst) of 33 (Wachtlijst + Criteria)
		wachthond($extdebug, 3, "Status is Wachtlijst ($new_status_id), datums voor administratie controleren", "[CHECK]");

		if (empty($wl_erop)) { 				// Als de datum dat iemand op de wachtlijst kwam leeg is
			$wl_erop = $register_date; 		// Vul automatisch met de originele registratiedatum
			wachthond($extdebug, 4, "Wachtlijst erop datum ontbrak, gezet op register_date: $wl_erop", 	"[FIXED]");
		} else { 							// Datum is al aanwezig, geen actie nodig
			wachthond($extdebug, 4, "Wachtlijst erop datum is reeds aanwezig: $wl_erop", 				"[OK]");
		}
	} else { 								// Deelnemer staat niet op de wachtlijst
		wachthond($extdebug, 4, "Deelnemer is niet geplaatst op de wachtlijst, Regel C overgeslagen", 	"[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### REGEL D: BEVESTIGING & BETALING (STATUS 9)");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING REGEL D:
	 * De eindsluis voor financiële afhandeling. Controleert of deelnemers op Status 9 
	 * (Afwachting Betaling) of deelnemers die van de wachtlijst afkomen, financieel 
	 * akkoord zijn. Bij een actieve paylink volgt promotie naar Status 1 (Bevestigd).
	 */

	// Check of we in fase 9 zitten, óf dat de deelnemer zojuist van de wachtlijst is gehaald
	if ($new_status_id == 9 || (!empty($wl_eraf) && in_array($new_status_id, [0, 5, 6, 7, 33]))) {

		wachthond($extdebug, 3, "Deelnemer mag doorstromen, controleren op financiële paylink", 		"[CHECK]");

		if ($new_status_id == 33 && $oordeel == 'oordeelnognodig' && !$has_paylink) {
			// Plek vrij maar criteria-oordeel is nog open en geen paylink → oordeel eerst, betaalmail nog niet
			$new_status_id    = 8;
			$new_status_label = 'Afwachting Oordeel';
			wachthond($extdebug, 3, "Status 33 + wl_eraf + oordeel nog open + geen paylink → Status 8 (oordeel eerst)", "[OORDEEL EERST]");
		} elseif ($has_paylink) { 	// Financiële koppeling is aanwezig	: deelnemer definitief bevestigen
			$new_status_id    = 1;
			$new_status_label = 'Bevestigd';
			wachthond($extdebug, 3, "Paylink gevonden -> Promotie naar Status 1 (Geregistreerd)", 		"[PROMOTED]");
		} else { 					// Geen betalingsgegevens gevonden	: deelnemer registratie laten afronden
			$new_status_id    = 9;
			$new_status_label = 'Afwachting (Betaling)';
			wachthond($extdebug, 3, "Geen paylink -> Gestagneerd op Status 9 (Voorheen wachtlijst)", 	"[HOLD]");
		}
	} else { 						// Deelnemer bevindt zich nog niet in de doorstroomfase
		wachthond($extdebug, 4, "Niet in fase voor doorstroming, Regel D overgeslagen", 				"[SKIP]");
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 2, "### PART STATUS LABELS BEREKENEN", 						  "[FALLBACK]");
	wachthond($extdebug, 2, "########################################################################");

	/*
	 * SAMENVATTING FALLBACK:
	 * Veiligheidsnet dat ervoor zorgt dat de log-engine (en de return array) altijd 
	 * een leesbare naam meekrijgt, zelfs als de status buiten voorgaande regels viel.
	 */

	if (empty($new_status_label)) { // Controleer of het label na alle regels nog steeds leeg is

		$labels = [ 						// Maak een vaste lijst (array) met alle mogelijke CiviCRM statussen en hun teksten
			1  => 'Bevestigd', 				// Status 1  = Bevestigd
			2  => 'Aangemeld', 				// Status 2  = Aangemeld
			4  => 'Geannuleerd', 			// Status 4  = Geannuleerd
			7  => 'Wachtlijst', 			// Status 7  = Wachtlijst
			8  => 'Afwachting Oordeel', 	// Status 8  = Afwachting Oordeel
			9  => 'Afwachting (Betaling)', 	// Status 9  = Afwachting Betaling
			33 => 'Wachtlijst + Criteria', 	// Status 33 = Wachtlijst én criteria-oordeel nog open
		];

		$new_status_label = $labels[$new_status_id] ?? 'Onbekend'; // Zoek het label op in de lijst, gebruik 'Onbekend' als fallback
		
		// Log de fallback actie met de juiste visuele uitlijning
		wachthond($extdebug, 3, "Label voor status $new_status_id handmatig toegewezen via fallback", "[$new_status_label]"); 
		
	} else { 
		// Als het label reeds door Regel C of D was gevuld
		wachthond($extdebug, 4, "Labeltoekenning overgeslagen, waarde was reeds aanwezig", "[SKIP]"); 
	}

	wachthond($extdebug, 2, "########################################################################");
	wachthond($extdebug, 1, "### PARTSTATUS WACHTLIJST 3.0 - VOLTOOID",						 "[EINDE]");
	wachthond($extdebug, 2, "########################################################################");

	return [ 									// Geef een array terug aan degene die de functie aanriep (meestal partstatus_consolidate)
		'status_id'		=> $new_status_id,		// Retourneer het berekende status ID
		'status_label'	=> $new_status_label, 	// Retourneer het bijbehorende tekst-label
		'wl_erop'		=> $wl_erop, 			// Retourneer de berekende of behouden 'wachtlijst erop' datum
		'wl_eraf'		=> $wl_eraf 			// Retourneer de behouden 'wachtlijst eraf' datum
	];

}