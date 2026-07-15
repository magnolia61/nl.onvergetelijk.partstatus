<?php

require_once 'CRM/Core/Form.php';

/**
 * Bevestigings-popup "Criteria prima".
 *
 * FUNCTIONEEL: Toont de gegevens waarop het criteria-oordeel gebaseerd wordt
 * (displaynaam, leeftijd op kamp, school/klas) met daarnaast de automatische
 * indicaties (indicatie leeftijd, indicatie school/klas), zodat de beheerder in één
 * oogopslag kan beslissen. De popup kent twee modi:
 *   - OORDEEL-modus (criteriacheck_einde nog leeg): de knop "Oordeel prima geven"
 *     keurt de criteria goed.
 *   - RESEND-modus (criteriacheck_einde al gevuld, check dus al afgerond):
 *     bevestigen verstuurt de goedkeuringsmail "00. CRITERIA leeftijd/school
 *     goedgekeurd [OUDERS]" (MessageTemplate 578) nogmaals naar het gezin. NB: bij
 *     een 8→9-doorzet (geen paylink) heeft de automatische rule 520 nooit gevuurd —
 *     deze knop verstuurt de mail dan voor het eerst.
 *
 * TECHNISCH: In oordeel-modus zet bevestigen PART_DEEL_INTERN.criteria_oordeel op
 * 'oordeelprima' en PART_DEEL_INTERN.criteriacheck_einde op vandaag, en roept daarna
 * partstatus_configure() aan. De status-doorzet (naar geregistreerd/bevestigd) loopt
 * via de bestaande motor — bewust géén directe status-write. NB: partstatus_criteria()
 * zelf NIET gebruiken om het oordeel te forceren; die reset juist naar 'oordeelnognodig'
 * zolang er geen override staat. In resend-modus gaat de mail via APIv3 Email.send
 * (EmailAPI) mét participant_id (token-render vult {$user_*}-vars) en dezelfde
 * cc/location_type als de automatische CiviRule 520 "BEOORDELEN naar BEVESTIGD".
 */
class CRM_Partstatus_Form_CriteriaPrima extends CRM_Core_Form {

    public $_participantId;
    public $_part;
    public $_reminderModus = FALSE;

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
        // de criteriacheck moet ooit gestart zijn (start-datum gevuld). Vangt verouderde
        // menu's en handmatig getypte URLs af.
        // NB: testregistraties (is_test=1) komen hier nooit — base_pid2part draait op
        // APIv4 Participant.get, die is_test=1 standaard verbergt → guard 1.2 bounce't al.
        if (empty($this->_part['criteriacheck_start'])) {
            CRM_Core_Error::statusBounce(ts('Voor deze registratie is geen criteriacheck gestart; er valt niets goed te keuren.'));
        }

        // 1.4 Modus bepalen: is de criteriacheck al afgerond (einde-datum gevuld), dan
        // valt er niets meer goed te keuren — de popup biedt dan alléén aan de
        // goedkeuringsmail (opnieuw) te versturen (resend-modus).
        $this->_reminderModus = !empty($this->_part['criteriacheck_einde']);
        wachthond($extdebug, 3, 'resend_modus', $this->_reminderModus ? 'ja' : 'nee');

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CRITPRIMA [FORM] 2.0 DETAILS VOOR DE POPUP KLAARZETTEN",   "[ASSIGN]");
        wachthond($extdebug, 2, "########################################################################");

        // 2.1 Nette optie-labels + leeftijd-op-kamp (aparte call: base_pid2part levert ruwe waarden).
        $labels = partstatus_form_labels($this->_participantId);
        wachthond($extdebug, 3, 'labels', $labels);

        // 2.2 Alles wat de template toont. Zelfde kernblok als de WachtlijstEraf-popup
        // (naam, event, datum registratie, keren deelnemer + de tabel leeftijd/school met
        // hun indicaties), zodat de twee modals herkenbaar op elkaar lijken.
        // resend_modus stuurt in de .tpl de helptekst, de extra einde-datumrij en het
        // "wat gaat er gebeuren"-blok.
        $this->assign('displayname',                $this->_part['displayname']         ?? NULL);
        $this->assign('event_title',                $this->_part['event_title']         ?? NULL);
        $this->assign('register_date',              $this->_part['register_date']       ?? NULL);
        $this->assign('criteriacheck_einde',        $this->_part['criteriacheck_einde'] ?? NULL);
        $this->assign('resend_modus',               $this->_reminderModus);
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
     * De knoptekst volgt de modus (oordeel geven vs. mail opnieuw sturen).
     */
    public function buildQuickForm() {
        CRM_Utils_System::setTitle(ts('Criteria prima'));

        // Dubbelklik-preventie: één klik = één actie. Vooral in resend-modus
        // essentieel — elke submit verstuurt een e-mail en Email.send kent geen
        // eigen dubbele-verzend-guard.
        $this->submitOnce = TRUE;

        $this->add('hidden', 'participant_id', $this->_participantId);
        // De modus waarin de popup GEOPEND is reist mee als hidden field; postProcess
        // vergelijkt die met de dan-verse modus en weigert bij een mismatch (situatie
        // intussen veranderd, bv. een collega die het oordeel al gaf).
        $this->add('hidden', 'popup_modus', $this->_reminderModus ? 'resend' : 'oordeel');

        $this->addButtons([
            [
                'type'      => 'submit',
                'name'      => $this->_reminderModus
                                    ? ts('Goedkeuringsmail opnieuw sturen')
                                    : ts('Oordeel prima geven'),
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
     * Oordeel-modus: keur de criteria goed en laat de motor de status doorzetten.
     * Resend-modus: verstuur de goedkeuringsmail (template 578) opnieuw.
     */
    public function postProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
        $waarden        = $this->exportValues();
        $participant_id = (int) ($waarden['participant_id'] ?? 0);
        $popup_modus    = $waarden['popup_modus'] ?? 'oordeel';

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CRITPRIMA [FORM] 2.5 MODUS-CHECK $participant_id",         "[GUARD]");
        wachthond($extdebug, 2, "########################################################################");

        // 2.5 Race-guard: preProcess draaide bij deze submit-request opnieuw op verse
        // data. Wijkt de verse modus af van de modus waarin de popup geopend werd
        // (hidden field), dan is de situatie intussen veranderd — bv. een collega die
        // het oordeel al gaf. Dan NIETS uitvoeren: geen onbedoeld dubbel oordeel en
        // zeker geen onbedoelde mail.
        $verse_modus = $this->_reminderModus ? 'resend' : 'oordeel';
        wachthond($extdebug, 3, 'popup_modus', $popup_modus);
        wachthond($extdebug, 3, 'verse_modus', $verse_modus);
        if ($popup_modus !== $verse_modus) {
            CRM_Core_Session::setStatus(
                ts('De situatie van deze registratie is intussen veranderd; er is niets uitgevoerd. Open de actie opnieuw en controleer de gegevens.'),
                ts('Criteria prima'), 'alert');
            parent::postProcess();
            return;
        }

        if ($this->_reminderModus) {

            wachthond($extdebug, 2, "########################################################################");
            wachthond($extdebug, 1, "### CRITPRIMA [FORM] 2.6 GOEDKEURINGSMAIL OPNIEUW $participant_id", "[MAIL]");
            wachthond($extdebug, 2, "########################################################################");

            // 2.6 Verstuur de goedkeuringsmail via de EmailAPI — zelfde template, cc en
            // location_type als de automatische CiviRule 520 "BEOORDELEN naar BEVESTIGD".
            // participant_id MOET mee: de token-render-listener (cssinliner) vult daarmee
            // de {$user_*}-vars in subject en body.
            $params_email_send = [
                'contact_id'        => (int) ($this->_part['contact_id'] ?? 0),
                'template_id'       => 578,   // "00. CRITERIA leeftijd/school goedgekeurd [OUDERS]"
                'participant_id'    => $participant_id,
                'location_type_id'  => 11,
                'cc'                => 'info@onvergetelijk.nl',
            ];
            wachthond($extdebug, 7, 'params_email_send', $params_email_send);
            try {
                $result_email_send = civicrm_api3('Email', 'send', $params_email_send);
                wachthond($extdebug, 9, 'result_email_send', $result_email_send);
                CRM_Core_Session::setStatus(
                    ts('De goedkeuringsmail is (opnieuw) verstuurd naar het gezin van %1.', [1 => $this->_part['displayname'] ?? '?']),
                    ts('Criteria prima'), 'success');
            } catch (Exception $e) {
                wachthond($extdebug, 3, 'email_send_fout', $e->getMessage());
                CRM_Core_Session::setStatus(
                    ts('Het versturen van de goedkeuringsmail is niet gelukt: %1', [1 => $e->getMessage()]),
                    ts('Criteria prima'), 'error');
            }

            parent::postProcess();
            return;
        }

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
