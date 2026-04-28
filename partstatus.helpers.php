<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.helpers.php
 * =======================================================================================
 *   partstatus_configure()  FUNCTIONEEL: Vergelijkt de berekende "Super Array" met de huidige d...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (De Sync Engine / Executor)
 * FUNCTIONEEL: Vergelijkt de berekende "Super Array" met de huidige database
 * en slaat uitsluitend de gewijzigde waarden op via de API Wrapper.
 * @param int $part_id              Het ID van de deelnemer (Verplicht)
 * @param array|null $array_part    Optioneel: Vooraf opgehaalde data
 * @param array|null $array_criteria Optioneel: Vooraf berekende criteria
 */
function partstatus_configure($part_id, $array_part = NULL, $array_criteria = NULL, $context = 'default') {
    
    $extdebug = 'partstatus.sync'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    if (empty($part_id)) { // Veiligheidscheck: Zonder Participant ID kunnen we niks opslaan
        wachthond($extdebug, 1, "PARTSTATUS ERROR ($context)", "Geen PID ontvangen. Sync afgebroken."); 
        return NULL; // Breek de executie af
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS CONFIGURE 1.0 - START CONSOLIDATIE","[$context: $part_id]");    
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 1.0:
     * Roept de "Brain" (partstatus_consolidate) aan om alle leeftijden, criteria 
     * en de definitieve wachtlijst-status te berekenen. Haalt ook de oude data op 
     * om straks te kunnen vergelijken (Smart Guard).
     */

    // 1. DATA CONSOLIDEREN via de Brain
    $ctx = partstatus_consolidate($part_id, $array_part, $array_criteria); // Roep de brain aan en sla op in de Context ($ctx) array
    if (!$ctx) return NULL; // Als de consolidatie faalt, stop dan het proces

    // Haal de originele data op voor de Smart Guard vergelijking (Lazy load indien nodig)
    $old_data = $array_part ?: (base_pid2part($part_id) ?: []);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS CONFIGURE 2.0 - DATA PREPARATIE",              "[MAPPING]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 2.0:
     * We bepalen eerst of dit record criteria-velden mág krijgen. 
     * (Alleen deelnemers van dít jaar op een criteria-kamp). Zo niet, maken we de velden leeg.
     * Vervolgens mappen we alles naar de 'new_' variabelen voor de injectie-loops.
     */

    // 1. BEPAAL EVENT TYPE, ROL EN FISCAAL JAAR
    $event_type_id  = $old_data['event_type_id'] ?? ($old_data['event_id.event_type_id'] ?? 0);
    $rol            = $old_data['part_rol'] ?? ($old_data['PART_kamprol'] ?? 'deelnemer');
    $event_start    = $old_data['event_start_date'] ?? date('Y-m-d');
    $event_jaar     = (int) date('Y', strtotime($event_start));
    
    // FISCAAL JAAR LOGICA: 
    // Bepaal hier de grens. (Bijv: na 1 september valt de rest al onder het 'volgende' kampjaar)
    $huidige_maand          = (int) date('n');
    $huidig_fiscaal_jaar    = ($huidige_maand >= 9) ? (date('Y') + 1) : (int)date('Y');
    $is_dit_jaar            = ($event_jaar == $huidig_fiscaal_jaar);

    $is_criteria_event      = in_array($event_type_id, [11, 12, 13, 14, 21, 22, 23, 24, 33]);

    // 2. CIVICRM WAARDE MAPPING (Zorg dat de rechterkant matcht met de CiviCRM values)
    $civicrm_map = [
        'prima'             => 'prima',
        'afwijkend'         => 'afwijkend',
        'schoolwijkt'       => 'schoolwijkt',
        'schoolwijktaf'     => 'schoolwijktaf',
        'criteriaprima'     => 'criteriaprima',
        'oordeelprima'      => 'oordeelprima',
        'oordeelnietnodig'  => 'oordeelnietnodig',
        'onbekend'          => ''
    ];

    // 3. DATA FILTEREN (VULLEN OF LEEGMAKEN)
    // REGEL: Alléén vullen als (Rol = Deelnemer) EN (Event = Criteria-Event) EN (Het is Dit Jaar)
    if ($rol == 'deelnemer' && $is_criteria_event && $is_dit_jaar) {
        wachthond($extdebug, 3, "Deelnemer Dit Jaar (Criteria Event): velden worden gemapt", "[FILL]");
        
        $mapped_leeftijd            = $civicrm_map[$ctx['criteria']['criteria_leeftijd']    ?? '']  ?? '';
        $mapped_school              = $civicrm_map[$ctx['criteria']['criteria_school']      ?? '']  ?? '';
        $mapped_indicatie           = $civicrm_map[$ctx['criteria']['criteria_indicatie']   ?? '']  ?? '';
        $mapped_oordeel             = $civicrm_map[$ctx['criteria']['criteria_oordeel']     ?? '']  ?? '';
        
        $mapped_chk_start           = $ctx['criteriacheck']['start']                        ?? '';
        $mapped_chk_einde           = $ctx['criteriacheck']['einde']                        ?? '';
        $mapped_wl_erop             = $ctx['wachtlijst']['erop']                            ?? '';
        $mapped_wl_eraf             = $ctx['wachtlijst']['eraf']                            ?? '';

    } else {
        wachthond($extdebug, 3, "Leiding, Ander Jaar, of Geen-Criteria Event: criteria leeggemaakt", "[CLEAR]");
        
        $mapped_leeftijd            = "";
        $mapped_school              = "";
        $mapped_indicatie           = "";
        $mapped_oordeel             = "";
        
        $mapped_chk_start           = "";
        $mapped_chk_einde           = "";
        $mapped_wl_erop             = "";
        $mapped_wl_eraf             = "";
    }

    // 4. KOPPELEN AAN DE DYNAMISCHE VARIABELEN ('new_')
    $val_status_label = $ctx['status_label'] ?? '';
    $val_groepklas    = $ctx['criteria']['new_groepklas'] ?? NULL;

    // Participant velden
    $new_deelnamestatus                 = $val_status_label;
    $new_criterialeeftijd               = $mapped_leeftijd;
    $new_criteriaschool                 = $mapped_school;
    $new_criteriaindicatie              = $mapped_indicatie;
    $new_criteriaoordeel                = $mapped_oordeel;
    $new_criteriacheckstart             = $mapped_chk_start;
    $new_criteriacheckeinde             = $mapped_chk_einde;
    $new_wachtlijsterop                 = $mapped_wl_erop;
    $new_wachtlijsteraf                 = $mapped_wl_eraf;
    $new_groepklas                      = $val_groepklas;

    // Contact velden (Spiegel-velden voor DITJAAR)
    $new_ditjaardeelnamestatus          = $val_status_label;
    $new_ditjaarleeftijd                = $mapped_leeftijd;
    $new_ditjaarschool                  = $mapped_school;
    $new_ditjaarcriteriaindicatie       = $mapped_indicatie;
    $new_ditjaarcriteriaoordeel         = $mapped_oordeel;
    $new_ditjaarcriteriacheckstart      = $mapped_chk_start;
    $new_ditjaarcriteriacheckeinde      = $mapped_chk_einde;
    $new_ditjaarwachtlijsterop          = $mapped_wl_erop;
    $new_ditjaarwachtlijsteraf          = $mapped_wl_eraf;
    $new_ditjaargroepklas               = $val_groepklas;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS CONFIGURE 3.0 - EXECUTE UPDATE PARTICIPANT",      "[PART]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 3.0:
     * Verzamelt alle wijzigingen voor het Participant-record door de field map te itereren.
     * De motor mapt automatisch variabelen met het prefix 'new_' naar de DB-kolommen.
     * De SMART GUARD controleert of de nieuwe waarde daadwerkelijk afwijkt van de 
     * database voordat hij aan de inject-array wordt toegevoegd.
     */

    $inject_part = []; // Maak een lege array voor de verzamelde updates
    
    // Core veld: Check handmatig voor het status_id (dit is geen custom field, vandaar buiten de loop)
    if ($ctx['status_id'] != ($old_data['status_id'] ?? NULL)) {    // Als de berekende status anders is dan in DB
        $inject_part['status_id']   = $ctx['status_id'];            // Voeg de nieuwe status toe aan de updates
        $inject_part['contact_id']  = $old_data['contact_id'];      // <--- BUGFIX VOOR CIVICRM CRASH
    }

    $map_part = partstatus_get_field_map_participant();             // Haal de Field Map array op uit partstatus.php
    
    foreach ($map_part as $db_col => $api_name) {                   // Loop door elk veld uit de map
        $api_parts  = explode('.', (string)$api_name);              // Knip de API naam op (bijv. 'PART_DEEL_INTERN.criteria_school')
        $suffix     = str_replace(':label', '', end($api_parts));   // Pak het laatste deel ('criteria_school')
        
        // MAPPING LOGICA:
        // 'Groep_klas' uit de Field Map wordt hier gekoppeld aan 'new_groepklas' uit de criteria motor.
        $var_new    = 'new_' . strtolower(str_replace('_', '', $suffix));

        // SMART GUARD: Bestaat de variabele EN is hij anders dan wat we al in de database hadden?
        if (isset($$var_new) && $$var_new !== ($old_data[$api_name] ?? 'NULL_CHECK')) {
            // Gebruik format_civicrm_smart om te zorgen dat datums en decimalen correct geformatteerd de DB in gaan
            $inject_part[$api_name] = format_civicrm_smart($$var_new, $api_name);
        }
    }

    if (!empty($inject_part)) {
        wachthond($extdebug, 3, "Wijzigingen gedetecteerd voor Participant. Start API update",      "[EXECUTE]");
        base_api_wrapper('Participant', $part_id, $inject_part, "PARTSTATUS_PART", $extdebug);
        wachthond($extdebug, 2, "Participant succesvol bijgewerkt naar Status " . $ctx['status_id'], "[$ctx[status_label]]");
    } else {
        wachthond($extdebug, 3, "Geen wijzigingen geconstateerd voor Participant. API overgeslagen","[SKIP]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS CONFIGURE 4.0 - EXECUTE UPDATE CONTACT",          "[CONT]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 4.0:
     * Spiegelt de actuele gegevens naar het Contact-record (de DITJAAR velden).
     * EXTREEM BELANGRIJK: We beschermen DITJAAR velden tegen evenementen uit voorgaande
     * jaren door deze sectie alleen uit te voeren als het event in het huidige jaar valt.
     */

    if ($is_dit_jaar) {

        $inject_cont    = []; // Maak een lege array voor de verzamelde Contact-updates
        $map_cont       = partstatus_get_field_map_contact(); // Haal de Field Map op voor Contact
        $contact_id     = $old_data['contact_id'] ?? NULL; // We hebben wél een Contact ID nodig om op te slaan

        if ($contact_id) {                                                  // Als we het Contact ID van de deelnemer weten...
            foreach ($map_cont as $db_col => $api_name) {                   // Loop door elk veld uit de map
                $api_parts  = explode('.', (string)$api_name);              // Knip de API naam op
                $suffix     = str_replace(':label', '', end($api_parts));   // Pak de suffix, en strip de CiviCRM OptionGroup ':label' tag weg
                
                // MAPPING LOGICA:
                // 'ditjaar_groep_klas' uit de Field Map wordt hier gekoppeld aan 'new_groepklas'.
                $var_new    = 'new_' . strtolower(str_replace('_', '', $suffix)); 

                // SMART GUARD: Vergelijk de nieuwe waarde met de oude waarde uit de database
                if (isset($$var_new) && $$var_new !== ($old_data[$api_name] ?? 'NULL_CHECK')) {
                    // Voeg toe aan inject array (zonder formatter, want contact velden zijn hier pure strings)
                    $inject_cont[$api_name] = $$var_new; 
                }
            }

            if (!empty($inject_cont)) {
                wachthond($extdebug, 3, "Wijzigingen gedetecteerd voor Contact DITJAAR. Start API update",  "[CONT]");
                base_api_wrapper('Contact', $contact_id, $inject_cont, "PARTSTATUS_CONT", $extdebug);
                
                wachthond($extdebug, 2, "Contact DITJAAR succesvol bijgewerkt", "[$ctx[status_label]]");
            } else { // Als de inject-array leeg is gebleven...
                wachthond($extdebug, 3, "Geen wijzigingen voor Contact DITJAAR. API update overgeslagen",   "[SKIP]");
            }
        } else { // Als er geen Contact ID was meegegeven...
            wachthond($extdebug, 1, "FOUT: Geen Contact ID gevonden om DITJAAR velden op te slaan",         "[ERROR]");
        }

    } else {
        // HET DITJAAR HEK IS DICHT:
        wachthond($extdebug, 3, "Event valt buiten huidig fiscaal jaar. DITJAAR velden veilig genegeerd.", "[PROTECT]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS CONFIGURE 5.0 - EINDE CONSOLIDATIE SYNC",        "[EINDE]");
    wachthond($extdebug, 2, "########################################################################");

    return $ctx; // Geef de berekende context (Super Array) terug aan degene die deze functie opriep

}