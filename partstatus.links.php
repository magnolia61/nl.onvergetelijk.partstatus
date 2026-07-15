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
 *   1. "Voorheen wachtlijst"  — zichtbaar zodra het wachtlijst-proces ooit gestart is
 *                               (PART_DEEL_INTERN.wachtlijst_erop gevuld). Is de
 *                               registratie al doorgezet (wachtlijst_eraf óók gevuld),
 *                               dan biedt de popup de herinneringsmail aan (tmpl 365).
 *   2. "Criteria prima"       — zichtbaar zodra de criteriacheck ooit gestart is
 *                               (PART_DEEL_INTERN.criteriacheck_start gevuld). Is de
 *                               check al afgerond (criteriacheck_einde óók gevuld),
 *                               dan biedt de popup de goedkeuringsmail opnieuw aan
 *                               (tmpl 578).
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

    $wachtlijst_erop     = $part['wachtlijst_erop']       ?? NULL;
    $criteriacheck_start = $part['criteriacheck_start']   ?? NULL;
    wachthond($extdebug, 3, 'wachtlijst_erop',     $wachtlijst_erop);
    wachthond($extdebug, 3, 'criteriacheck_start', $criteriacheck_start);

    // ------------------------------------------------------------------------------
    // 3.0 LINKS: voeg de acties conditioneel toe. De class 'medium-popup' laat
    // CiviCRM core de route als AJAX-modal openen (bevestigings-popup).
    // De condities zijn bewust PROCESVELD-gebaseerd (datum gevuld) en niet
    // status-gebaseerd: de actie blijft ook ná de doorzet/afronding in het menu
    // staan, zodat de popup dan de (herinnerings)mail kan aanbieden.
    // ------------------------------------------------------------------------------

    // 3.1 "Voorheen wachtlijst": zodra het wachtlijst-proces gestart is (erop-datum
    // gevuld). Ook een al-doorgezette registratie (eraf-datum gevuld) houdt de actie —
    // de popup schakelt dan zelf om naar de herinneringsmail-modus
    // (zie CRM_Partstatus_Form_WachtlijstEraf).
    if (!empty($wachtlijst_erop)) {
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

    // 3.2 "Criteria prima": zodra de criteriacheck gestart is (start-datum gevuld).
    // Bewust GEEN status- of oordeel-check meer: ook een al-afgeronde check
    // (einde-datum gevuld, oordeel gegeven) houdt de actie — de popup schakelt dan
    // zelf om naar de mail-opnieuw-modus (zie CRM_Partstatus_Form_CriteriaPrima).
    if (!empty($criteriacheck_start)) {
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
