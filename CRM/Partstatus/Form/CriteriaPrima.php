<?php

require_once 'CRM/Core/Form.php';

/**
 * Bevestigings-popup "Criteria prima".
 *
 * FUNCTIONEEL: Toont de gegevens waarop het criteria-oordeel gebaseerd wordt
 * (displaynaam, leeftijd op kamp, school/klas) met daarnaast de automatische
 * indicaties (indicatie leeftijd, indicatie school/klas), zodat de beheerder in één
 * oogopslag kan beslissen. De knop "Oordeel prima geven" keurt de criteria goed.
 *
 * TECHNISCH: Bevestigen zet PART_DEEL_INTERN.criteria_oordeel op 'oordeelprima' en
 * PART_DEEL_INTERN.criteriacheck_einde op vandaag, en roept daarna partstatus_configure()
 * aan. De status-doorzet (naar geregistreerd/bevestigd) loopt via de bestaande motor —
 * bewust géén directe status-write. NB: partstatus_criteria() zelf NIET gebruiken om het
 * oordeel te forceren; die reset juist naar 'oordeelnognodig' zolang er geen override staat.
 */
class CRM_Partstatus_Form_CriteriaPrima extends CRM_Core_Form {

    public $_participantId;
    public $_part;

    /**
     * Controleer rechten, haal de registratie op en bewaak de conditie (oordeel open).
     */
    public function preProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CRITPRIMA [FORM] 1.0 RECHTEN EN REGISTRATIE OPHALEN",      "[START]");
        wachthond($extdebug, 2, "########################################################################");

        // 1.1 Rechten: zelfde eis als de rest van het participant-beheer.
        if (!CRM_Core_Permission::checkActionPermission('CiviEvent', CRM_Core_Action::UPDATE)) {
            CRM_Core_Error::statusBounce(ts('Je hebt geen rechten om registraties aan te passen.'));
        }

        // 1.2 Registratie ophalen op basis van het pid uit de actielink.
        $this->_participantId = CRM_Utils_Request::retrieve('pid', 'Positive', $this);
        $this->_part          = base_pid2part($this->_participantId);
        wachthond($extdebug, 3, 'participant_id', $this->_participantId);

        if (empty($this->_part)) {
            CRM_Core_Error::statusBounce(ts('Registratie niet gevonden.'));
        }

        // 1.3 Guard: dezelfde conditie als de actielink (zie partstatus.links.php 3.2):
        // de status moet 8 "Afwachting oordeel" of 33 "Wachtlijst + Criteria" zijn EN het
        // oordeel-veld moet nog openstaan (leeg of 'oordeelnognodig'). Vangt verouderde
        // menu's af (popup geopend nadat het oordeel intussen al is gegeven).
        $status_id        = (int) ($this->_part['status_id'] ?? 0);
        $criteria_oordeel = $this->_part['criteria_oordeel'] ?? NULL;
        $oordeel_open     = (empty($criteria_oordeel) || $criteria_oordeel === 'oordeelnognodig');
        if (!in_array($status_id, [8, 33]) || !$oordeel_open) {
            CRM_Core_Error::statusBounce(ts('Deze registratie wacht niet (meer) op een criteria-oordeel; er valt niets goed te keuren.'));
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CRITPRIMA [FORM] 2.0 DETAILS VOOR DE POPUP KLAARZETTEN",   "[ASSIGN]");
        wachthond($extdebug, 2, "########################################################################");

        // 2.1 Nette optie-labels + leeftijd-op-kamp (aparte call: base_pid2part levert ruwe waarden).
        $labels = partstatus_form_labels($this->_participantId);
        wachthond($extdebug, 3, 'labels', $labels);

        // 2.2 Alles wat de template toont. Zelfde kernblok als de WachtlijstEraf-popup
        // (naam, event, datum registratie, keren deelnemer + de tabel leeftijd/school met
        // hun indicaties), zodat de twee modals herkenbaar op elkaar lijken.
        $this->assign('displayname',                $this->_part['displayname']         ?? NULL);
        $this->assign('event_title',                $this->_part['event_title']         ?? NULL);
        $this->assign('register_date',              $this->_part['register_date']       ?? NULL);
        $this->assign('curcv_keer_deel',            $this->_part['curcv_keer_deel']     ?? 0);
        $this->assign('leeftijd_rondjaren',         $labels['leeftijd_rondjaren']       ?? NULL);
        $this->assign('leeftijd_rondmaand',         $labels['leeftijd_rondmaand']       ?? NULL);
        $this->assign('groepklas_label',            $labels['groepklas_label']          ?? NULL);
        $this->assign('criteria_leeftijd_label',    $labels['criteria_leeftijd_label']  ?? NULL);
        $this->assign('criteria_school_label',      $labels['criteria_school_label']    ?? NULL);

        parent::preProcess();
    }

    /**
     * Alleen een bevestig-/annuleerknop; er valt niets in te vullen.
     */
    public function buildQuickForm() {
        CRM_Utils_System::setTitle(ts('Criteria prima'));

        $this->add('hidden', 'participant_id', $this->_participantId);

        $this->addButtons([
            [
                'type'      => 'submit',
                'name'      => ts('Oordeel prima geven'),
                'isDefault' => TRUE,
            ],
            [
                'type'      => 'cancel',
                'name'      => ts('Annuleren'),
            ],
        ]);

        parent::buildQuickForm();
    }

    /**
     * Keur de criteria goed en laat de motor de status doorzetten.
     */
    public function postProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
        $waarden        = $this->exportValues();
        $participant_id = (int) ($waarden['participant_id'] ?? 0);

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CRITPRIMA [FORM] 3.0 OORDEEL PRIMA GEVEN $participant_id", "[WRITE]");
        wachthond($extdebug, 2, "########################################################################");

        // 3.1 Oordeel + einddatum criteriacheck in één write; base_api_wrapper formatteert
        // en skipt bij ongewijzigd.
        $data_update = [
            'PART_DEEL_INTERN.criteria_oordeel'     => 'oordeelprima',
            'PART_DEEL_INTERN.criteriacheck_einde'  => date('Y-m-d'),
        ];
        $result_update = base_api_wrapper('Participant', $participant_id, $data_update, 'PARTSTATUS_LINKS_CRIT_PRIMA', $extdebug);

        // 3.2 Motor expliciet aanjagen. Het custom-hook-pad doet dit normaliter ook al,
        // maar deze aanroep garandeert de doorzet ongeacht hook-volgorde (re-entrancy-safe).
        partstatus_configure($participant_id, NULL, NULL, 'links_criteriaprima');

        // 3.3 Terugkoppeling met de VERSE status (force_refresh: cache is nu stale).
        $part_na    = base_pid2part($participant_id, TRUE);
        $status_na  = $part_na['status_name']       ?? '?';
        $oordeel_na = $part_na['criteria_oordeel']  ?? NULL;
        wachthond($extdebug, 3, 'status_na',  $status_na);
        wachthond($extdebug, 3, 'oordeel_na', $oordeel_na);

        if ($oordeel_na === 'oordeelprima') {
            CRM_Core_Session::setStatus(
                ts('Het criteria-oordeel is goedgekeurd. Nieuwe status: %1.', [1 => $status_na]),
                ts('Criteria prima'), 'success');
        } else {
            CRM_Core_Session::setStatus(
                ts('Het goedkeuren van het criteria-oordeel is niet gelukt; zie de wachthond-log.'),
                ts('Criteria prima'), 'error');
        }

        parent::postProcess();
    }
}
