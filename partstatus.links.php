<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: partstatus.links.php
 * =======================================================================================
 *   partstatus_civicrm_links()      HOOK: voegt conditionele acties toe aan het
 *                                   Participant-actiemenu (dropdown per registratie)
 *   partstatus_form_labels()        Helper: haalt optie-LABELS + leeftijd-op-kamp op
 *                                   voor de bevestigings-popups (WachtlijstEraf/CriteriaPrima)
 * =======================================================================================
 */

/**
 * MODULE: PARTSTATUS (Actiemenu-links)
 *
 * FUNCTIONEEL (Het 'Waarom'):
 * Beheerders moesten tot nu toe handmatig procesdatums/oordeel-velden invullen om een
 * registratie van de wachtlijst te halen of het criteria-oordeel goed te keuren. Deze
 * module voegt daarvoor twee acties toe aan het actiemenu van een registratie, die elk
 * een bevestigings-popup openen (medium-popup) met details en een bevestigknop:
 *   1. "Voorheen wachtlijst"  — alleen zichtbaar als de registratie NU op de wachtlijst
 *                               staat (status 7 Wachtlijst of 33 Wachtlijst + Criteria).
 *   2. "Criteria prima"       — alleen zichtbaar als het oordeel nog openstaat
 *                               (criteria_oordeel leeg of 'oordeelnognodig').
 *
 * TECHNISCH (Het 'Hoe'):
 * hook_civicrm_links vuurt per rij in de participant-selector met op
 * 'participant.selector.row'. CiviCRM geeft in $values alleen id/cid/cxt mee — status en
 * oordeel halen we dus zelf op via base_pid2part() (heeft een static cache, dus dit is
 * per request maar één API-call per registratie). De popups zelf zijn CRM_Core_Form
 * classes (CRM/Partstatus/Form/) met routes in xml/Menu/partstatus.xml; de class
 * 'medium-popup' zorgt dat CiviCRM core (CRM.popup in crm.ajax.js) de route als
 * AJAX-modal opent — daar is geen eigen JavaScript voor nodig.
 */

/**
 * HOOK: LINKS (Actiemenu van een registratie)
 * Voegt "Voorheen wachtlijst" en "Criteria prima" conditioneel toe.
 *
 * @param string $op          De context, hier relevant: 'participant.selector.row'
 * @param string $objectName  Entiteitsnaam, hier relevant: 'Participant'
 * @param int    $objectId    Het participant ID van de rij
 * @param array  $links       De actielinks van het menu (by reference)
 * @param int    $mask        Bitmask van toegestane acties (niet gebruikt)
 * @param array  $values      Placeholder-waarden voor de querystrings (by reference)
 */
function partstatus_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
    $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    // ------------------------------------------------------------------------------
    // 1.0 GUARD: alleen het actiemenu van een Participant-rij is interessant.
    // ('participant.contact.row' bestaat niet in core; alleen selector.row vuurt.)
    // ------------------------------------------------------------------------------
    if ($objectName != 'Participant' || $op != 'participant.selector.row') {
        return;
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### LINKS 1.0 ACTIEMENU REGISTRATIE $objectId",                "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // ------------------------------------------------------------------------------
    // 2.0 DATA: haal status en criteria-oordeel op. base_pid2part heeft een static
    // cache, dus meerdere hooks in dezelfde request kosten maar één API-call.
    // ------------------------------------------------------------------------------
    $part = base_pid2part($objectId);
    if (empty($part)) {
        wachthond($extdebug, 3, 'part_niet_gevonden', $objectId);
        return;
    }

    $status_id          = (int) ($part['status_id']      ?? 0);
    $criteria_oordeel   = $part['criteria_oordeel']      ?? NULL;
    wachthond($extdebug, 3, 'status_id',        $status_id);
    wachthond($extdebug, 3, 'criteria_oordeel', $criteria_oordeel);

    // ------------------------------------------------------------------------------
    // 3.0 LINKS: voeg de acties conditioneel toe. De class 'medium-popup' laat
    // CiviCRM core de route als AJAX-modal openen (bevestigings-popup).
    // ------------------------------------------------------------------------------

    // 3.1 "Voorheen wachtlijst": als de registratie op de wachtlijst staat
    // (7 = Wachtlijst, 33 = Wachtlijst + Criteria), OF al voorheen-wachtlijst is
    // (9 = Voorheen wachtlijst) — die laatste tijdelijk mee, zodat de actie ook zichtbaar
    // blijft bij een al-doorgezette registratie (zie partstatus.wachtlijst.php).
    if (in_array($status_id, [7, 33, 9])) {
        $links[] = [
            'name'  => ts('Voorheen wachtlijst'),
            'title' => ts('Haal deze registratie van de wachtlijst'),
            'url'   => 'civicrm/partstatus/wachtlijsteraf',
            'qs'    => 'reset=1&pid=%%pspid%%',
            'class' => 'action-item crm-hover-button medium-popup',
            'icon'  => 'fa-hourglass-end',
        ];
        wachthond($extdebug, 3, 'link_toegevoegd', 'voorheen_wachtlijst');
    }

    // 3.2 "Criteria prima": alleen als de registratie op een oordeel WACHT. Twee eisen:
    //   a. de status is 8 "Afwachting oordeel" of 33 "Wachtlijst + Criteria" — dit sluit
    //      bevestigde (leiding-)registraties uit die toevallig nog 'oordeelnognodig'
    //      in het veld hebben staan;
    //   b. het oordeel-veld staat open (leeg of 'oordeelnognodig', de default van de
    //      motor zolang er geen automatisch of handmatig oordeel is).
    $oordeel_open = (empty($criteria_oordeel) || $criteria_oordeel === 'oordeelnognodig');
    if (in_array($status_id, [8, 33]) && $oordeel_open) {
        $links[] = [
            'name'  => ts('Criteria prima'),
            'title' => ts('Keur de criteria van deze registratie goed'),
            'url'   => 'civicrm/partstatus/criteriaprima',
            'qs'    => 'reset=1&pid=%%pspid%%',
            'class' => 'action-item crm-hover-button medium-popup',
            'icon'  => 'fa-check',
        ];
        wachthond($extdebug, 3, 'link_toegevoegd', 'criteria_prima');
    }

    // De %%pspid%%-placeholder in de querystrings wordt hiermee gevuld.
    $values['pspid'] = $objectId;
}

/**
 * Helper voor de bevestigings-popups: haalt de optie-LABELS (voor nette weergave) en
 * de opgeslagen leeftijd-op-kamp op. base_pid2part() levert alleen de ruwe optie-
 * waarden (bv. 'prima', 'groep_6'); voor de popup willen we de leesbare labels en de
 * door partstatus_leeftijd_configure() opgeslagen PART.nextkamp_* velden.
 *
 * @param  int $part_id  Het participant ID
 * @return array         ['leeftijd_rondjaren', 'leeftijd_rondmaand', 'leeftijd_decimalen',
 *                        'groepklas_label', 'criteria_leeftijd_label', 'criteria_school_label',
 *                        'criteria_indicatie_label'] (waarden NULL als onbekend)
 */
function partstatus_form_labels(int $part_id): array {
    $extdebug   = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    $apidebug   = FALSE;

    $params_participant_labels = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'select'           => [
            'PART.nextkamp_rondjaren',
            'PART.nextkamp_rondmaand',
            'PART.nextkamp_decimalen',
            'PART_DEEL.Groep_klas:label',
            'PART_DEEL_INTERN.criteria_leeftijd:label',
            'PART_DEEL_INTERN.criteria_school:label',
            'PART_DEEL_INTERN.criteria_indicatie:label',
        ],
        'where'            => [
            ['id', '=', $part_id],
        ],
    ];
    wachthond($extdebug, 7, 'params_participant_labels', $params_participant_labels);
    $result_participant_labels = civicrm_api4('Participant', 'get', $params_participant_labels);
    wachthond($extdebug, 9, 'result_participant_labels', $result_participant_labels);

    $row = $result_participant_labels[0] ?? [];

    return [
        'leeftijd_rondjaren'        => $row['PART.nextkamp_rondjaren']                       ?? NULL,
        'leeftijd_rondmaand'        => $row['PART.nextkamp_rondmaand']                       ?? NULL,
        'leeftijd_decimalen'        => $row['PART.nextkamp_decimalen']                       ?? NULL,
        'groepklas_label'           => $row['PART_DEEL.Groep_klas:label']                    ?? NULL,
        'criteria_leeftijd_label'   => $row['PART_DEEL_INTERN.criteria_leeftijd:label']      ?? NULL,
        'criteria_school_label'     => $row['PART_DEEL_INTERN.criteria_school:label']        ?? NULL,
        'criteria_indicatie_label'  => $row['PART_DEEL_INTERN.criteria_indicatie:label']     ?? NULL,
    ];
}
