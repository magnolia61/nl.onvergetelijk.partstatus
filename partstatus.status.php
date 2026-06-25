<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.status.php
 * =======================================================================================
 *   partstatus_consolidate()  FUNCTIONEEL: Centraliseert alle berekende data (leeftijd, criteria,...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Status Consolidering)
 * FUNCTIONEEL: Centraliseert alle berekende data (leeftijd, criteria, wachtlijst) tot één status-object.
 * @param int $part_id              Het ID van de deelnemer (Verplicht)
 * @param array|null $array_part    Optioneel: Vooraf opgehaalde data (Lazy Loading fallback naar base_pid2part)
 * @param array|null $array_criteria    Optioneel: Vooraf berekende criteria resultaten
 */
function partstatus_consolidate($part_id, $array_part = NULL, $array_criteria = NULL) { // Definieer de functie met 1 verplichte en 2 optionele parameters

    $extdebug = 'partstatus.sync'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 1.0 - DATA VERZAMELEN",                   "[START]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 1.0:
     * We zorgen voor een volledige dataset voordat we de berekeningen starten.
     * 1. Indien de $array_part leeg is, laden we de inschrijfgegevens via base_pid2part.
     * 2. De geboortedatum halen we voor de betrouwbaarheid ALTIJD op via base_cid2cont
     * indien deze nog niet in de dataset aanwezig is.
     */

    // STAP A: Zorg dat we basis participantgegevens hebben
    if (empty($array_part) && $part_id) {
        wachthond($extdebug, 3, "Geen pre-data meegegeven. Laden via base_pid2part...",             "[LAZY LOAD]");
        if (function_exists('base_pid2part')) {
            $array_part = base_pid2part($part_id) ?: [];
        }
    }

    // STAP B: Geboortedatum verificatie (Altijd via Contact ID voor 100% zekerheid)
    if ($part_id && (empty($array_part['birth_date']))) {
        $target_cid = $array_part['contact_id'] ?? NULL;
        
        if ($target_cid && function_exists('base_cid2cont')) {
            wachthond($extdebug, 3, "Birth_date ontbreekt in dataset. Ophalen via base_cid2cont...", "[REPAIR]");
            $contact_data = base_cid2cont($target_cid);
            $array_part['birth_date'] = $contact_data['birth_date'] ?? NULL;

            if (!empty($array_part['birth_date'])) {
                wachthond($extdebug, 3, "Geboortedatum opgehaald",                "[" . $array_part['birth_date'] . "]");
            } else {
                // GUARD: zonder geboortedatum is leeftijdsberekening onmogelijk → stop hier.
                // Doorgaan zou criteria_leeftijd = 'onbekend' of 'afwijkend' opleveren
                // op basis van leeftijd 0, wat foutieve mails en statuswijzigingen veroorzaakt.
                wachthond($extdebug, 1, "!!! ABORT !!! birth_date ontbreekt na base_cid2cont voor CID: $target_cid. Criteria-berekening geannuleerd.", "[NO_BIRTHDATE]");
                return NULL;
            }
        }
    } else {
        wachthond($extdebug, 4, "Pre-data is aanwezig en birth_date is bekend", "[OK]");
    }

    // Finale check: zonder deze data kunnen we niet betrouwbaar consolideren
    if (empty($array_part)) {
        wachthond($extdebug, 1, "!!! FATAL ERROR !!! Geen participant data gevonden voor PID", "[$part_id]");
        return NULL;
    }

    // Trek de basisgegevens uit de array voor verder gebruik
    $register_date  = $array_part['register_date']  ?? date("Y-m-d H:i:s"); // Registratiedatum, default is NU
    $part_rol       = $array_part['part_rol']       ?? 'deelnemer';         // De rol (leiding of deelnemer), default is deelnemer
    $birth_date     = $array_part['birth_date']     ?? NULL;                // Geboortedatum (belangrijk voor leeftijdsberekening)
    $new_groepklas  = $array_part['groepklas']      ?? NULL;                // Gecorrigeerde groep/klas variabele

    wachthond($extdebug, 3, "Input voor rekenaar: Geboortedatum: $birth_date | Groep: $new_groepklas", "[READY]");

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 2.0 - LEEFTIJDEN BEREKENEN",           "[LEEFTIJD]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 2.0:
     * Berekent de exacte leeftijd van de deelnemer (in decimalen) op drie belangrijke peilmomenten:
     * 1. Vandaag (Voor realtime weergave)
     * 2. Startdatum van dit specifieke evenement (Voor criteria toetsing)
     * 3. Startdatum van het 'nextkamp' (Voor werving van volgend jaar)
     * Delegatie naar partstatus_leeftijd_configure() — zonder $groupID = alleen rekenen, geen DB-writes.
     */

    $ages       = partstatus_leeftijd_configure($array_part); // Geen groupID meegegeven → puur rekenen, geen writes
    $age_today  = $ages['today'];
    $age_event  = $ages['event'];
    $age_next   = $ages['next'];

    // Log de uitkomsten van de leeftijdsberekeningen
    wachthond($extdebug, 4, "Leeftijd Vandaag",     $age_today['leeftijd_decimalen'] ?? 'Onbekend');
    wachthond($extdebug, 4, "Leeftijd Dit Event",   $age_event['leeftijd_decimalen'] ?? 'Onbekend');
    wachthond($extdebug, 4, "Leeftijd Next Kamp",   $age_next['leeftijd_decimalen']  ?? 'Onbekend');

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 3.0 - CRITERIA EVALUATIE",             "[CRITERIA]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 3.0:
     * Beoordeelt of de deelnemer binnen de bandbreedte (leeftijd/school) van de groep valt.
     * Als we deze nog niet van de pre-hook hebben gekregen, delegeert hij deze 
     * zware beoordeling naar partstatus_criteria().
     */

    if (empty($array_criteria)) { // Als de criteria nog niet berekend of meegegeven waren...
        wachthond($extdebug, 3, "Geen actuele criteria ontvangen. Berekenen via partstatus_criteria()..."); // Log actie
        // Bereken verse criteria, gebruikmakend van de zojuist berekende 'Leeftijd Dit Event'
        $array_criteria = partstatus_criteria($part_id, $array_part, $age_event['leeftijd_decimalen'] ?? NULL); 
        wachthond($extdebug, 4, "Resultaat Indicatie",  $array_criteria['criteria_indicatie'] ?? 'NULL'); // Log uitkomst indicatie
        wachthond($extdebug, 4, "Resultaat Oordeel",    $array_criteria['criteria_oordeel']   ?? 'NULL'); // Log uitkomst oordeel
    } else { // Als we de criteria wél al hadden gekregen...
        wachthond($extdebug, 4, "Overgeslagen (Actuele criteria waren al meegegeven door aanroeper)"); // Log dat we efficiënt overslaan
    } // Einde criteria bepaling

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 4.1 - STATUS & WACHTLIJST LOGICA",       "[STATUS]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 4.1:
     * Hier rolt de definitieve CiviCRM Deelnamestatus uit.
     * - Leiding is vrijgesteld van criteria/wachtlijst en krijgt direct Status 1 (Bevestigd).
     * - Deelnemers worden door de partstatus_evaluate_wachtlijst() motor gehaald,
     * die rekening houdt met betalingen, oordelen en datum 'erop' / 'eraf'.
     */

    $old_status_id  = $array_part['status_id']              ?? 0; // Wat was de status vóórdat we begonnen?
    $oordeel        = $array_criteria['criteria_oordeel']   ?? $array_part['criteria_oordeel'] ?? NULL; // Wat is het actuele oordeel?

    if ($part_rol == 'leiding' && $old_status_id != 4) { // Als dit leiding is, én ze zijn niet geannuleerd (4)...
        wachthond($extdebug, 3, "Rol is 'Leiding' en niet geannuleerd. Wachtlijst overgeslagen."); // Log de bypass
        $res_status = [ // Zet de status array hard op 'Bevestigd' en leeg de wachtlijstdatums
            'status_id'     => 1, 
            'status_label'  => 'Bevestigd', 
            'wl_erop'       => NULL, 
            'wl_eraf'       => NULL
        ];
        wachthond($extdebug, 4, "Resultaat", "Direct Bevestigd (Status 1)"); // Log resultaat
    } else { // In alle andere gevallen (reguliere deelnemer, of wel geannuleerd)...
        wachthond($extdebug, 3, "Rol is 'Deelnemer'. Delegeren naar partstatus_evaluate_wachtlijst()..."); // Log de delegatie
        // DELEGATIE: We geven de verse $array_criteria mee, zodat een "Oordeel OK" direct wordt opgepakt door de motor!
        $res_status = partstatus_evaluate_wachtlijst($part_id, $array_part, $array_criteria);
        wachthond($extdebug, 4, "Resultaat", "Status " . $res_status['status_id'] . " toegewezen door wachtlijst motor."); // Log uitkomst motor
    }   // Einde statusbepaling

    // FALLBACK ONVOLLEDIGE DATA: konden de criteria niet betrouwbaar bepaald worden (bv. kampkort
    // niet gesynct door een afgebroken registratie — onze systeemfout), dan houden we de deelnemer
    // bewust op status 8 (Afwachting oordeel). NIET auto-bevestigen: er is mogelijk geen plek of de
    // criteria wijken alsnog af. Een mens lost het op (partstatus_configure stuurt de webteam-alert).
    // Geannuleerd (4) laten we met rust.
    if (!empty($array_criteria['criteria_incompleet']) && $old_status_id != 4) {
        wachthond($extdebug, 1, "Criteria incompleet (ontbrekend veld). Status geforceerd op 8 (Afwachting oordeel).", "[INCOMPLEET]");
        $res_status['status_id']    = 8;
        $res_status['status_label'] = 'Afwachting oordeel';
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 4.2 - CRITERIACHECK DATUM BEHEER",       "[DATUMS]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 4.2:
     * Synchroon houden van de procestijden (start en einde criteriacheck).
     * Zorgt dat een oordeel dat verandert naar "Niet nodig" de datums schoonveegt, 
     * en dat deelnemers die wachten op een oordeel minimaal een startdatum hebben.
     */

    $check_start    = $array_part['criteriacheck_start'] ?? NULL; // Bestaande startdatum ophalen
    $check_einde    = $array_part['criteriacheck_einde'] ?? NULL; // Bestaande einddatum ophalen

    if ($res_status['status_id'] != 7) { // Als iemand (nog) NIET op de wachtlijst (7) staat...
        
        if ($oordeel == 'oordeelnietnodig') { // Indien een beheerder het oordeel handmatig op 'niet nodig' heeft gezet...
            wachthond($extdebug, 3, "Actie", "Oordeel niet (meer) nodig. Wis actieve check-datums."); // Log de actie
            $check_start = ""; // Maak startdatum leeg
            $check_einde = ""; // Maak einddatum leeg
        } 
        elseif ($oordeel == 'oordeelnognodig' || $res_status['status_id'] == 8) { // Als er wél een oordeel nodig is of we staan al op status 8...
            if (empty($check_start)) {              // Check of een startdatum ontbreekt...
                wachthond($extdebug, 3, "Actie", "Status 8 of Oordeel nodig, maar geen startdatum. Gebruik registratiedatum."); // Log de actie
                $check_start = $register_date;      // ...vul in met registratiedatum
            } else {                                // Als er al wel een startdatum was...
                wachthond($extdebug, 4, "Actie", "Startdatum reeds aanwezig ($check_start)."); // Log het behoud
            }
        } // Einde sub-check
        
        // FUNCTIONEEL BEHOUD: Wanneer de check wordt afgerond (einddatum gezet) bij een record op status 8,
        // hergebruiken we de uitkomst (1 of 9) die res_status (de wachtlijst motor) hierboven zojuist veilig heeft berekend.
        if (!empty($check_einde) && $old_status_id == 8) {          // Controleer op de statusverandering
            wachthond($extdebug, 3, "Actie", "Oordeel afgerond vanuit Status 8. Behoud berekende doorstroom-status."); // Log de transitie
            $res_status['status_id'] = $res_status['status_id'];    // Veilige toewijzing voor leesbaarheid
        } // Einde transitie-check

    } else { // Als de deelnemer WEL op de wachtlijst (7) staat...
        wachthond($extdebug, 4, "Actie", "Overgeslagen. Datumbeheer criteriacheck niet actief zolang status Wachtlijst (7) is.");
    } // Einde grote if-lus

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS STATUS 5.0 - CONSOLIDATIE VOLTOOID",             "[EINDE]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 5.0:
     * Bouwt de zogenoemde "Super Array". Deze array bevat alle in dit script berekende
     * datapunten, netjes opgedeeld per categorie. De partstatus_helpers zal deze
     * array uitlezen en vervolgens de gewijzigde velden opslaan in CiviCRM.
     */

    // Retourneer de "Super Array" naar de aanroeper
    return [
        'part_id'           => $part_id,                    // Het ID van de deelnemer
        'status_id'         => $res_status['status_id'],    // Het berekende CiviCRM Status ID (bijv 1, 7, 8 of 9)
        'status_label'      => $res_status['status_label'], // Tekstuele representatie
        'leeftijd' => [
            'today'         => $age_today,                  // Leeftijdsarray van Vandaag
            'event'         => $age_event,                  // Leeftijdsarray van Dit Event
            'next'          => $age_next                    // Leeftijdsarray van Komend Jaar
        ],
        'criteria'          => $array_criteria,             // Actuele indicaties en oordelen
        'wachtlijst' => [
            'erop'          => $res_status['wl_erop'],      // Berekende/behouden wachtlijst startdatum
            'eraf'          => $res_status['wl_eraf']       // Berekende/behouden wachtlijst einddatum
        ],
        'criteriacheck' => [
            'start'         => $check_start,                // Berekende/behouden check startdatum
            'einde'         => $check_einde                 // Berekende/behouden check einddatum
        ]
    ];

} // Einde functie partstatus_consolidate