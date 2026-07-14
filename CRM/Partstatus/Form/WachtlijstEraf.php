<?php

require_once 'CRM/Core/Form.php';

/**
 * Bevestigings-popup "Voorheen wachtlijst".
 *
 * FUNCTIONEEL: Toont de details van een registratie die op de wachtlijst staat
 * (displaynaam, datum registratie, keren deelnemer, criteria-indicaties ter controle)
 * en zet na bevestiging het proces "van de wachtlijst af" in gang.
 *
 * TECHNISCH: Bevestigen zet alleen PART_DEEL_INTERN.wachtlijst_eraf op vandaag en roept
 * daarna partstatus_configure() aan. De status-doorzet zelf (naar 9 "Voorheen wachtlijst" /
 * Afwachting Betaling) is bewust GEEN directe status-write: die loopt via de bestaande
 * wachtlijst-motor (Regel D in partstatus.wachtlijst.php), zodat alle guards en de
 * DITJAAR-spiegel gewoon meedraaien.
 */
class CRM_Partstatus_Form_WachtlijstEraf extends CRM_Core_Form {

    public $_participantId;
    public $_part;

    /**
     * Controleer rechten, haal de registratie op en bewaak de conditie (status 7/33).
     */
    public function preProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 1.0 RECHTEN EN REGISTRATIE OPHALEN",         "[START]");
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

        // 1.3 Guard: dezelfde conditie als de actielink (zie partstatus.links.php 3.1):
        // status 7/33 (nog op wachtlijst) of 9 (al voorheen-wachtlijst, tijdelijk mee).
        // Vangt verouderde menu's af (popup geopend bij een niet-passende status).
        $status_id = (int) ($this->_part['status_id'] ?? 0);
        if (!in_array($status_id, [7, 33, 9])) {
            CRM_Core_Error::statusBounce(ts('Deze registratie staat niet (meer) op de wachtlijst; er valt niets in gang te zetten.'));
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 2.0 DETAILS VOOR DE POPUP KLAARZETTEN",      "[ASSIGN]");
        wachthond($extdebug, 2, "########################################################################");

        // 2.1 Nette optie-labels + leeftijd-op-kamp (aparte call: base_pid2part levert ruwe waarden).
        $labels = partstatus_form_labels($this->_participantId);
        wachthond($extdebug, 3, 'labels', $labels);

        // 2.2 Alles wat de template toont.
        $this->assign('displayname',                $this->_part['displayname']        ?? NULL);
        $this->assign('event_title',                $this->_part['event_title']        ?? NULL);
        $this->assign('register_date',              $this->_part['register_date']      ?? NULL);
        $this->assign('wachtlijst_erop',            $this->_part['wachtlijst_erop']    ?? NULL);
        $this->assign('curcv_keer_deel',            $this->_part['curcv_keer_deel']    ?? 0);
        $this->assign('criteria_leeftijd_label',    $labels['criteria_leeftijd_label'] ?? NULL);
        $this->assign('criteria_school_label',      $labels['criteria_school_label']   ?? NULL);
        $this->assign('groepklas_label',            $labels['groepklas_label']         ?? NULL);
        $this->assign('leeftijd_rondjaren',         $labels['leeftijd_rondjaren']      ?? NULL);
        $this->assign('leeftijd_rondmaand',         $labels['leeftijd_rondmaand']      ?? NULL);

        parent::preProcess();
    }

    /**
     * Alleen een bevestig-/annuleerknop; er valt niets in te vullen.
     */
    public function buildQuickForm() {
        CRM_Utils_System::setTitle(ts('Voorheen wachtlijst'));

        $this->add('hidden', 'participant_id', $this->_participantId);

        $this->addButtons([
            [
                'type'      => 'submit',
                'name'      => ts('Voorheen wachtlijst in gang zetten'),
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
     * Zet de einddatum van de wachtlijst en laat de motor de status doorzetten.
     */
    public function postProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
        $waarden        = $this->exportValues();
        $participant_id = (int) ($waarden['participant_id'] ?? 0);

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 3.0 WACHTLIJST ERAF ZETTEN $participant_id", "[WRITE]");
        wachthond($extdebug, 2, "########################################################################");

        // 3.1 Alleen de procesdatum zetten; base_api_wrapper formatteert en skipt bij ongewijzigd.
        $data_update = [
            'PART_DEEL_INTERN.wachtlijst_eraf'  => date('Y-m-d'),
        ];
        $result_update = base_api_wrapper('Participant', $participant_id, $data_update, 'PARTSTATUS_LINKS_WL_ERAF', $extdebug);

        // 3.2 Motor expliciet aanjagen. Het custom-hook-pad doet dit normaliter ook al,
        // maar deze aanroep garandeert de doorzet ongeacht hook-volgorde (re-entrancy-safe).
        partstatus_configure($participant_id, NULL, NULL, 'links_wachtlijsteraf');

        // 3.3 Terugkoppeling met de VERSE status (force_refresh: cache is nu stale).
        $part_na    = base_pid2part($participant_id, TRUE);
        $status_na  = $part_na['status_name'] ?? '?';
        wachthond($extdebug, 3, 'status_na', $status_na);

        if (!empty($result_update) || !empty($part_na['wachtlijst_eraf'])) {
            CRM_Core_Session::setStatus(
                ts('De registratie is van de wachtlijst gehaald. Nieuwe status: %1.', [1 => $status_na]),
                ts('Voorheen wachtlijst'), 'success');
        } else {
            CRM_Core_Session::setStatus(
                ts('Het zetten van de wachtlijst-einddatum is niet gelukt; zie de wachthond-log.'),
                ts('Voorheen wachtlijst'), 'error');
        }

        parent::postProcess();
    }
}
