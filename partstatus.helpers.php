<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.helpers.php
 * =======================================================================================
 *   partstatus_configure()  FUNCTIONEEL: Vergelijkt de berekende "Super Array" met de huidige d...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Deelnamestatus-map — coarse samenvatting)
 * FUNCTIONEEL: Vertaalt een fijnmazige CiviCRM participant-status (status_id) naar de grove
 * 5-waarden `deelnamestatus` (optiegroep 643: 1 Bevestigd / 2 Wachtlijst / 3 Criteriacheck /
 * 4 Afwachting / 5 Geannuleerd). Dit vervangt de oude, fragiele label-string-matching (waarbij
 * status 8/9/33 op NULL uitkwamen omdat hun label niet exact op een optie-label matchte).
 * Dekt zowel de deelnemer-flow als de beschikbaarheids-respons van leiding (21-24).
 * TECHNISCH: geeft de optie-WAARDE (int) terug, of NULL voor niet-gemapte statussen — bij NULL
 * laat de aanroeper `deelnamestatus` bewust ONGEWIJZIGD (geen wipe).
 * @param int|null $status_id  CiviCRM participant status_id
 * @return int|null            Optie-waarde 1..5, of NULL als er geen mapping is
 */
function partstatus_deelnamestatus_from_status($status_id) {
    $map = [
        // --- Deelnemer-flow ---
        1  => 1,  // Geregistreerd        → Bevestigd
        7  => 2,  // Op de wachtlijst      → Wachtlijst
        8  => 3,  // Afwachting oordeel    → Criteriacheck
        9  => 4,  // Voorheen wachtlijst   → Afwachting
        33 => 3,  // Wachtlijst + criteria → Criteriacheck
        4  => 5,  // Geannuleerd           → Geannuleerd
        2  => 6,  // Deelgenomen (na afloop aanwezig geweest) → Deelgenomen
        // --- Beschikbaarheids-respons leiding (event-types Kampstaf/Training/Online/Meetup) ---
        21 => 5,  // Ik kan zeker niet     → Geannuleerd
        22 => 4,  // Ik weet het nog niet  → Afwachting
        23 => 1,  // Ik ben erbij          → Bevestigd
        24 => 4,  // Nog niet bekend       → Afwachting
    ];
    return $map[(int) $status_id] ?? NULL;
}

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

    /*
     * RE-ENTRANCY LOCK (gedeeld per request, per deelnemer).
     * FUNCTIONEEL: Deze functie schrijft via base_api_wrapper terug naar de Participant
     * (status_id + deelnamestatus + criteria) én naar het Contact (DITJAAR-spiegel). Die
     * Participant-write triggert de partstatus post-hook opnieuw, die — sinds we de sync
     * daar aanroepen — hier weer zou uitkomen. Zonder slot ontstaat een geneste (in het
     * ergste geval loopende) sync-cascade op hetzelfde record.
     * TECHNISCH: We zetten een vlag in Civi::$statics vóór het rekenen/schrijven en geven
     * die bij ELKE uitgang weer vrij (try/finally). Bij een geneste aanroep voor dezelfde
     * deelnemer doen we GEEN writes, maar geven we wél de verse (read-only) context terug
     * via partstatus_consolidate(), zodat de aanroeper (bv. de activity-logger) door kan.
     * Zelfde familie als de pecunia busy-lock; zie geheugen pecunia_reentrancy_deadlock.
     */
    if (!empty(Civi::$statics['partstatus']['busy'][$part_id])) {
        wachthond($extdebug, 3, "Geneste configure-aanroep voor PID $part_id overgeslagen (lock actief). Alleen read-only context.", "[REENTRANT]");
        return partstatus_consolidate($part_id, $array_part, $array_criteria);
    }
    Civi::$statics['partstatus']['busy'][$part_id] = TRUE;

    try {

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

    // WEBTEAM-ALERT — ALLEEN wanneer de indicatie niet gemaakt kón worden (criteria_incompleet,
    // bv. kampkort niet gesynct door een afgebroken registratie — onze systeemfout). LET OP: we
    // hangen dit bewust NIET aan status 8, want status 8 ontstaat ook legitiem bij een échte
    // criteria-afwijking die handmatig oordeel vraagt. De dedupe gebeurt op de incomplete-staat
    // zelf: vuren alleen bij INTREDE (oude opgeslagen indicatie was nog geen 'noggeenindicatie'),
    // zodat herhaalde saves geen mailstroom veroorzaken. De familie krijgt bewust géén mail.
    if (!empty($ctx['criteria']['criteria_incompleet'])
        && ($old_data['criteria_indicatie'] ?? NULL) !== 'noggeenindicatie'
        && function_exists('partstatus_mail_webteam_incomplete')) {
        partstatus_mail_webteam_incomplete($part_id, $old_data, $ctx);
    }

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

    // 2. CIVICRM WAARDE MAPPING (Exact gesynchroniseerd met actieve CiviCRM opties)
    $civicrm_map = [
        // Basis beoordelingen (interne waarden)
        'prima'             => 'prima',
        'afwijkend'         => 'afwijkend',
        'marge'             => 'marge',

        // Criteria Indicatie (Actieve opties, 'waarschijnlijk' genegeerd)
        'noggeenindicatie'  => 'noggeenindicatie',
        'criteriaprima'     => 'criteriaprima',
        'binnenmarges'      => 'binnenmarges',
        'leeftijdwijktaf'   => 'leeftijdwijktaf',
        'schoolwijktaf'     => 'schoolwijktaf',
        'criteriawijktaf'   => 'criteriawijktaf',

        // Oordeel (Alleen de ingeschakelde opties)
        'oordeelnietnodig'  => 'oordeelnietnodig',
        'oordeelnognodig'   => 'oordeelnognodig',
        'oordeelprima'      => 'oordeelprima',
        'buitencriteria'    => 'buitencriteria',

        // Systeem fallback
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

    // Coarse deelnamestatus via expliciete status_id-map (NIET meer via label-matching, dat gaf
    // NULL voor 8/9/33). NULL = niet-gemapte status → $new_deelnamestatus blijft NULL → de
    // inject-loop (isset-guard) slaat 'm over → bestaande deelnamestatus blijft ongewijzigd.
    $val_deelnamestatus = partstatus_deelnamestatus_from_status($ctx['status_id'] ?? 0);

    // Participant velden
    $new_deelnamestatus                 = $val_deelnamestatus;
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
    $new_ditjaardeelnamestatus          = $val_deelnamestatus;
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
            // Ruwe waarde meegeven; base_api_wrapper formatteert zelf via format_civicrm_smart
            $inject_part[$api_name] = $$var_new;
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

    } finally {
        // Geef het slot ALTIJD vrij, ook als base_api_wrapper of consolidate een exception gooit.
        Civi::$statics['partstatus']['busy'][$part_id] = FALSE;
    }

}

/**
 * MODULE: PARTSTATUS (Webteam Alert — onvolledige criteria)
 * FUNCTIONEEL: Stuurt een HTML-alertmail naar webteam@onvergetelijk.nl wanneer de criteria-check
 *              voor een deelnemer NIET gemaakt kon worden doordat één of meer velden ontbreken
 *              (typisch: part_kampkort niet gesynct na een afgebroken registratie). Dit is altijd
 *              ONZE systeemfout; de familie krijgt hierover bewust geen mail. De deelnemer staat
 *              zolang op status 8 (Afwachting oordeel) zodat er niet automatisch bevestigd wordt.
 * TECHNISCH: Direct via CRM_Utils_Mail::send() — geen template, geen contact-mail, geen schedule.
 *            Gemodelleerd naar account_mail_admin_alert() in nl.onvergetelijk.account.
 * @param int   $part_id   Participant ID van de onvolledige aanmelding
 * @param array $old_data  De base_pid2part-array (registratiegegevens voor in de mail)
 * @param array $ctx       De consolidate-context (bevat criteria_missing)
 */
function partstatus_mail_webteam_incomplete($part_id, $old_data, $ctx) {

    $extdebug   = 'partstatus.alert'; // Kanaal voor centrale debug-config
    $apidebug   = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS ALERT - WEBTEAM MAIL (ONVOLLEDIGE CRITERIA)",     "[ALERT]");
    wachthond($extdebug, 2, "########################################################################");

    // 1.0 GEGEVENS VERZAMELEN
    $contact_id     = $old_data['contact_id']            ?? NULL;
    $displayname    = $old_data['displayname']           ?? ('Contact ' . $contact_id);
    $event_id       = $old_data['event_id']              ?? NULL;
    $kampnaam       = $old_data['kenmerken_kampnaam']    ?? ($old_data['part_kampnaam'] ?? '-');
    $kampkort_part  = $old_data['part_kampkort']         ?: '(leeg)';
    $kampkort_event = $old_data['kenmerken_kampkort']    ?: '(leeg)';
    $event_start    = $old_data['event_start_date']      ?? '-';
    $groepklas      = $old_data['part_groepklas']        ?? '-';
    $birth_date     = $old_data['birth_date']            ?? '-';
    $missing        = $ctx['criteria']['criteria_missing'] ?? ['onbekend'];
    $missing_txt    = implode(', ', (array) $missing);

    // 1.1 LINKS NAAR CIVICRM (contact + deze inschrijving)
    $contact_url = $contact_id
        ? 'https://www.onvergetelijk.nl/civicrm/contact/view?reset=1&cid=' . $contact_id
        : NULL;
    $part_url = ($contact_id && $part_id)
        ? 'https://www.onvergetelijk.nl/civicrm/contact/view/participant?reset=1&action=view&id=' . $part_id . '&cid=' . $contact_id
        : NULL;

    wachthond($extdebug, 3, "Onvolledige aanmelding", "[PID $part_id / CID $contact_id / mist: $missing_txt]");

    // 2.0 HTML-BODY OPBOUWEN
    $rij = function ($label, $waarde) {
        return "<tr><td style='padding:4px 8px;border:1px solid #ddd;'><strong>" . htmlspecialchars($label)
             . "</strong></td><td style='padding:4px 8px;border:1px solid #ddd;'>" . htmlspecialchars((string) $waarde) . "</td></tr>";
    };

    $body_html  = "<p>De criteria-check voor onderstaande aanmelding kon <strong>niet</strong> worden uitgevoerd "
                . "omdat één of meer velden ontbraken: <strong>" . htmlspecialchars($missing_txt) . "</strong>.</p>";
    $body_html .= "<p>Dit is een systeemfout aan onze kant (meestal: het kampkort is niet vanuit het event op de "
                . "deelnemer gesynct, bv. doordat de inschrijving halverwege afbrak). De deelnemer staat daarom op "
                . "<strong>Afwachting oordeel</strong> (status 8) en de ouders hebben hierover géén mail gekregen. "
                . "Vul het ontbrekende veld aan en sla de inschrijving opnieuw op; de criteria worden dan herrekend.</p>";

    $body_html .= "<table style='border-collapse:collapse;width:100%;max-width:640px;'>";
    $body_html .= $rij('Deelnemer',        $displayname);
    $body_html .= $rij('Geboortedatum',    $birth_date);
    $body_html .= $rij('Participant ID',   $part_id);
    $body_html .= $rij('Kamp',             $kampnaam . ' (EID ' . $event_id . ')');
    $body_html .= $rij('Kampstart',        $event_start);
    $body_html .= $rij('Kampkort (event)', $kampkort_event);
    $body_html .= $rij('Kampkort (deelnemer)', $kampkort_part);
    $body_html .= $rij('Groep/klas',       $groepklas);
    $body_html .= $rij('Ontbrekend',       $missing_txt);
    $body_html .= "</table>";

    if ($contact_url) {
        $body_html .= "<p style='margin-top:16px;'>"
                    . "<a href='" . $contact_url . "'>Contact $contact_id openen in CiviCRM</a>";
        if ($part_url) {
            $body_html .= " &middot; <a href='" . $part_url . "'>Deze inschrijving openen</a>";
        }
        $body_html .= "</p>";
    }

    $body_html .= "<hr style='margin:24px 0;border:none;border-top:1px solid #ccc;'>"
                . "<p style='font-size:12px;color:#666;'>Gegenereerd door nl.onvergetelijk.partstatus op "
                . date('d-m-Y H:i:s') . "</p>";

    // 3.0 VERSTUREN (direct, zonder template/contact — vgl. account_mail_admin_alert)
    $to_mail    = 'webteam@onvergetelijk.nl';
    $from_mail  = \Civi::settings()->get('mailing_from_email') ?: 'noreply@onvergetelijk.nl';
    $from_name  = \Civi::settings()->get('mailing_from_name')  ?: 'OZK CiviCRM';

    $params_alert = [
        'from'    => '"' . $from_name . '" <' . $from_mail . '>',
        'toEmail' => $to_mail,
        'toName'  => 'Webteam OZK',
        'subject' => '[OZK CRITERIA] Onvolledige aanmelding — handmatig oordeel nodig: ' . $displayname,
        'html'    => $body_html,
        'text'    => strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>'], "\n", $body_html)),
    ];

    wachthond($extdebug, 7, 'params_alert', $params_alert);

    try {
        $sent = \CRM_Utils_Mail::send($params_alert);
        wachthond($extdebug, 9, 'alert_sent', $sent ? 'OK' : 'MISLUKT');
        if (!$sent) {
            watchdog('nl_partstatus', 'Webteam-alert (onvolledige criteria) niet verstuurd voor PID @pid.',
                ['@pid' => $part_id], WATCHDOG_ERROR);
        }
    } catch (\Exception $e) {
        wachthond($extdebug, 1, "ALERT MAIL EXCEPTION", $e->getMessage());
        watchdog('nl_partstatus', 'Webteam-alert exception: @msg', ['@msg' => $e->getMessage()], WATCHDOG_ERROR);
    }
}