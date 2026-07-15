<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.activities.php
 * =======================================================================================
 *   partstatus_log_activity()  FUNCTIONEEL: Maakt automatisch een CiviCRM Activity aan bij een sta...
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Activity Logger)
 * FUNCTIONEEL: Maakt automatisch een CiviCRM Activity aan bij een statuswijziging.
 * TECHNISCH: Gebruikt CiviCRM API4. Toont labels voor oude/nieuwe status en PART-waarden.
 */
function partstatus_log_activity($part_id, $contact_id, $old_status_id, $new_status_id, $ctx) {

    $extdebug   = 'partstatus.post'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    $apidebug   = FALSE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS ACTIVITY 1.0 - VALIDATIE & PREPARATIE",          "[START]");
    wachthond($extdebug, 2, "########################################################################");

    if ($old_status_id == $new_status_id) {
        wachthond($extdebug, 3, "Geen statuswijziging gedetecteerd. Activiteit overgeslagen.",  "[SKIP]");
        return;
    }

    // 1. VOORBEREIDEN VARIABELEN
    // Map voor tekstlabels van statussen (Exact conform jouw CiviCRM instellingen)
    $status_map = [
        0  => 'Onbekend',
        1  => 'Geregistreerd',
        2  => 'Deelgenomen',
        3  => 'Niet-gekomen',
        4  => 'Geannuleerd',
        5  => 'Afwachting van betaling',
        6  => 'Betaling onderbroken',
        7  => 'Op de wachtlijst',
        8  => 'Afwachting oordeel',
        9  => 'Voorheen wachtlijst',
        10 => 'Beoordeling OK',
        11 => 'Niet goedgekeurd',
        12 => 'Verlopen',
        14 => 'Definitief',
        15 => 'Gedeeltelijk betaald',
        18 => 'TEST',
        19 => 'Eerder naar huis',
        21 => 'Ik kan zeker niet',
        22 => 'Ik weet het nog niet',
        23 => 'Ik ben erbij',
        24 => 'Nog niet bekend',
        26 => 'Annulering ivm protocol',
        31 => 'Invited',
        33 => 'Afwachting Oordeel [+wacht]'
    ];

    $old_label      = $status_map[$old_status_id] ?? 'Onbekend';
    $new_label      = $status_map[$new_status_id] ?? ($ctx['status_label'] ?? 'Onbekend');
    
    $subject        = "Status: " . $old_label . " > " . $new_label;
    
    $session        = CRM_Core_Session::singleton();
    $current_user_id = $session->get('userID');

    // Criteria data (Originele PART waarden uit de Super Array)
    $crit_leeftijd  = $ctx['criteria']['criteria_leeftijd']     ?? 'onbekend';
    $crit_school    = $ctx['criteria']['criteria_school']       ?? 'onbekend';
    $crit_indicatie = $ctx['criteria']['criteria_indicatie']    ?? 'onbekend';
    $crit_oordeel   = $ctx['criteria']['criteria_oordeel']      ?? 'onbekend';
    $groep_klas     = $ctx['criteria']['new_groepklas']         ?? 'onbekend';

    // Proces datums
    $wl_erop        = $ctx['wachtlijst']['erop']                ?? '-';
    $wl_eraf        = $ctx['wachtlijst']['eraf']                ?? '-';
    $check_start    = $ctx['criteriacheck']['start']            ?? '-';
    $check_einde    = $ctx['criteriacheck']['einde']            ?? '-';

    // Toelichting
                                    $toelichting = "De status van deze deelnemer is gewijzigd door de Partstatus Engine.";
    if     ($new_status_id == 8)    $toelichting = "In afwachting gezet: handmatig oordeel vereist (Criteria wijken af of marge).";
    elseif ($new_status_id == 9)    $toelichting = "Doorstroom naar betaling: wacht op afhandeling paylink.";
    elseif ($new_status_id == 4)    $toelichting = "De inschrijving is geannuleerd.";

    wachthond($extdebug, 3, "Variabelen geprepareerd voor tabel-injectie.",                 "[PREP OK]");

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS ACTIVITY 2.0 - TABEL INJECTIE (PART FOCUS)",            "[TABLE]");
    wachthond($extdebug, 2, "########################################################################");

    // 2. BOUW DE TABEL (Nu met labels voor beide statussen en PART vernoeming)
    $details_html  = "<p><strong>Toelichting:</strong> " . $toelichting . "</p>";
    $details_html .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%; max-width: 600px;'>";
    $details_html .= "<tr><th style='text-align: left; background-color: #f2f2f2; width: 45%;'>Veld</th><th style='text-align: left; background-color: #f2f2f2;'>Waarde</th></tr>";
    
    $details_html .= "<tr><td><strong>Oude Status ID</strong></td>              <td>" . $old_status_id . " (" . $old_label . ")</td></tr>";
    $details_html .= "<tr><td><strong>Nieuwe Status ID</strong></td>            <td>" . $new_status_id . " (" . $new_label . ")</td></tr>";
    
    $details_html .= "<tr><td><strong>PART school beoordeling</strong></td>     <td>" . $crit_school . "    </td></tr>";
    $details_html .= "<tr><td><strong>PART leeftijd beoordeling</strong></td>   <td>" . $crit_leeftijd . "  </td></tr>";
    $details_html .= "<tr><td><strong>PART criteria indicatie</strong></td>     <td>" . $crit_indicatie . " </td></tr>";
    $details_html .= "<tr><td><strong>PART criteria oordeel</strong></td>       <td>" . $crit_oordeel . "   </td></tr>";
    
    $details_html .= "<tr><td><strong>PART wachtlijst erop</strong></td>        <td>" . $wl_erop . "        </td></tr>";
    $details_html .= "<tr><td><strong>PART wachtlijst eraf</strong></td>        <td>" . $wl_eraf . "        </td></tr>";
    $details_html .= "<tr><td><strong>PART criteriacheck start</strong></td>    <td>" . $check_start . "    </td></tr>";
    $details_html .= "<tr><td><strong>PART criteriacheck einde</strong></td>    <td>" . $check_einde . "    </td></tr>";
    
    $details_html .= "<tr><td><strong>PART groep / klas</strong></td>           <td>" . $groep_klas . "     </td></tr>";
    $details_html .= "</table>";

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS ACTIVITY 3.0 - UITVOEREN VIA API4",           "[EXECUTE]");
    wachthond($extdebug, 2, "########################################################################");

    $params_activity_create = [
        'checkPermissions'          => FALSE,
        'debug'                     => $apidebug,
        'values'                    => [
            // Eigen activity type (managed entity, zie partstatus.mgd.php) — bewust NIET
            // delta's 'Notificatie aandachtspunten' (154). Naam-lookup i.p.v. hardcoded ID:
            // zo ontkoppelt een statuswijziging van delta's notificatie-pijplijn en kunnen
            // CiviRules op type 154 (bv. regel 433) er niet meer per ongeluk op reageren.
            'activity_type_id:name'     => 'Deelnamestatus gewijzigd',
            'subject'                   => $subject,
            'details'                   => $details_html,
            'status_id:name'            => 'Completed',
            'activity_date_time'        => date('Y-m-d H:i:s'),
            'source_contact_id'         => $current_user_id ?: $contact_id,
            'target_contact_id'         => [$contact_id],
        ],
    ];

    wachthond($extdebug, 3, 'params_activity_create',                   $params_activity_create);
    
    try {
        $result_activity = civicrm_api4('Activity', 'create',           $params_activity_create);
        wachthond($extdebug, 9, 'result_activity',                      $result_activity);
        wachthond($extdebug, 3, "Activiteit aangemaakt voor CID $contact_id",                   "[SUCCESS]");
    } catch (Exception $e) {
        wachthond($extdebug, 1, "API4 Fout: " . $e->getMessage(),                               "[API ERROR]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### PARTSTATUS ACTIVITY 4.0 - EINDE",                             "[EINDE]");
    wachthond($extdebug, 2, "########################################################################");
}