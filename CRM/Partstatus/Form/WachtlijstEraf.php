<?php

require_once 'CRM/Core/Form.php';

/**
 * Bevestigings-popup "Voorheen wachtlijst".
 *
 * FUNCTIONEEL: Toont de details van een registratie waarvan het wachtlijst-proces
 * gestart is (displaynaam, datum registratie, keren deelnemer, criteria-indicaties
 * ter controle). De popup kent twee modi:
 *   - DOORZET-modus (wachtlijst_eraf nog leeg): bevestigen zet het proces "van de
 *     wachtlijst af" in gang.
 *   - REMINDER-modus (wachtlijst_eraf al gevuld, registratie dus al doorgezet):
 *     bevestigen verstuurt de herinneringsmail "AFRONDEN aanmelding voorheen op
 *     wachtlijst (REMINDER)" (MessageTemplate 365) naar het gezin. Dit template heeft
 *     bewust géén automatisch verzendpad (geen CiviRule/schedule) — deze knop is het
 *     enige kanaal.
 *
 * TECHNISCH: In doorzet-modus zet bevestigen alleen PART_DEEL_INTERN.wachtlijst_eraf
 * op vandaag en roept daarna partstatus_configure() aan. De status-doorzet zelf (naar
 * 9 "Voorheen wachtlijst" / Afwachting Betaling) is bewust GEEN directe status-write:
 * die loopt via de bestaande wachtlijst-motor (Regel D in partstatus.wachtlijst.php),
 * zodat alle guards en de DITJAAR-spiegel gewoon meedraaien. In reminder-modus gaat de
 * mail via APIv3 Email.send (EmailAPI) mét participant_id in de params, zodat de
 * token-render-listener (cssinliner) de {$user_*}-Smarty-vars kan vullen — zelfde
 * patroon als CiviRule 611 en vog_planb_reminders.php.
 */
class CRM_Partstatus_Form_WachtlijstEraf extends CRM_Core_Form {

    public $_participantId;
    public $_part;
    public $_reminderModus = FALSE;

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
        // het wachtlijst-proces moet ooit gestart zijn (erop-datum gevuld). Vangt
        // verouderde menu's en handmatig getypte URLs af.
        // NB: testregistraties (is_test=1) komen hier nooit — base_pid2part draait op
        // APIv4 Participant.get, die is_test=1 standaard verbergt → guard 1.2 bounce't al.
        if (empty($this->_part['wachtlijst_erop'])) {
            CRM_Core_Error::statusBounce(ts('Deze registratie heeft nooit op de wachtlijst gestaan; er valt niets in gang te zetten.'));
        }

        // 1.4 Modus bepalen: is de registratie al van de wachtlijst gehaald (eraf-datum
        // gevuld), dan valt er niets meer door te zetten — de popup biedt dan alléén de
        // herinneringsmail aan (reminder-modus).
        $this->_reminderModus = !empty($this->_part['wachtlijst_eraf']);
        wachthond($extdebug, 3, 'reminder_modus', $this->_reminderModus ? 'ja' : 'nee');

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 2.0 DETAILS VOOR DE POPUP KLAARZETTEN",      "[ASSIGN]");
        wachthond($extdebug, 2, "########################################################################");

        // 2.1 Nette optie-labels + leeftijd-op-kamp (aparte call: base_pid2part levert ruwe waarden).
        $labels = partstatus_form_labels($this->_participantId);
        wachthond($extdebug, 3, 'labels', $labels);

        // 2.2 Alles wat de template toont. reminder_modus stuurt in de .tpl de
        // helptekst, de extra eraf-datumrij en het "wat gaat er gebeuren"-blok.
        $this->assign('displayname',                $this->_part['displayname']        ?? NULL);
        $this->assign('event_title',                $this->_part['event_title']        ?? NULL);
        $this->assign('register_date',              $this->_part['register_date']      ?? NULL);
        $this->assign('wachtlijst_erop',            $this->_part['wachtlijst_erop']    ?? NULL);
        $this->assign('wachtlijst_eraf',            $this->_part['wachtlijst_eraf']    ?? NULL);
        $this->assign('reminder_modus',             $this->_reminderModus);
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
     * De knoptekst volgt de modus (doorzetten vs. herinneringsmail).
     */
    public function buildQuickForm() {
        CRM_Utils_System::setTitle(ts('Voorheen wachtlijst'));

        // Dubbelklik-preventie: één klik = één actie. Vooral in reminder-modus
        // essentieel — elke submit verstuurt een e-mail en Email.send kent geen
        // eigen dubbele-verzend-guard.
        $this->submitOnce = TRUE;

        $this->add('hidden', 'participant_id', $this->_participantId);
        // De modus waarin de popup GEOPEND is reist mee als hidden field; postProcess
        // vergelijkt die met de dan-verse modus en weigert bij een mismatch (situatie
        // intussen veranderd, bv. door een collega die al doorzette).
        $this->add('hidden', 'popup_modus', $this->_reminderModus ? 'reminder' : 'doorzetten');

        $this->addButtons([
            [
                'type'      => 'submit',
                'name'      => $this->_reminderModus
                                    ? ts('Herinneringsmail sturen')
                                    : ts('Voorheen wachtlijst in gang zetten'),
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
     * Doorzet-modus: zet de einddatum van de wachtlijst en laat de motor de status
     * doorzetten. Reminder-modus: verstuur de herinneringsmail (template 365).
     */
    public function postProcess() {
        $extdebug = 'partstatus.links'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
        $waarden        = $this->exportValues();
        $participant_id = (int) ($waarden['participant_id'] ?? 0);
        $popup_modus    = $waarden['popup_modus'] ?? 'doorzetten';

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 3.0 MODUS-CHECK $participant_id",            "[GUARD]");
        wachthond($extdebug, 2, "########################################################################");

        // 3.1 Race-guard: preProcess draaide bij deze submit-request opnieuw op verse
        // data. Wijkt de verse modus af van de modus waarin de popup geopend werd
        // (hidden field), dan is de situatie intussen veranderd — bv. een collega die
        // de registratie al doorzette. Dan NIETS uitvoeren: geen onbedoelde dubbele
        // doorzet en zeker geen onbedoelde mail.
        $verse_modus = $this->_reminderModus ? 'reminder' : 'doorzetten';
        wachthond($extdebug, 3, 'popup_modus', $popup_modus);
        wachthond($extdebug, 3, 'verse_modus', $verse_modus);
        if ($popup_modus !== $verse_modus) {
            CRM_Core_Session::setStatus(
                ts('De situatie van deze registratie is intussen veranderd; er is niets uitgevoerd. Open de actie opnieuw en controleer de gegevens.'),
                ts('Voorheen wachtlijst'), 'alert');
            parent::postProcess();
            return;
        }

        if ($this->_reminderModus) {

            wachthond($extdebug, 2, "########################################################################");
            wachthond($extdebug, 1, "### WLERAF [FORM] 4.0 HERINNERINGSMAIL STUREN $participant_id", "[MAIL]");
            wachthond($extdebug, 2, "########################################################################");

            // 4.1 Verstuur de herinneringsmail via de EmailAPI — zelfde patroon als de
            // CiviRules-mails (rule 611) en vog_planb_reminders.php. participant_id MOET
            // mee: de token-render-listener (cssinliner) vult daarmee de {$user_*}-vars
            // in subject en body. location_type_id 11 = dezelfde doeladres-keuze als de
            // automatische wachtlijst-mails (rules 179/180/611).
            $params_email_send = [
                'contact_id'        => (int) ($this->_part['contact_id'] ?? 0),
                'template_id'       => 365,   // "AFRONDEN aanmelding voorheen op wachtlijst (REMINDER)"
                'participant_id'    => $participant_id,
                'location_type_id'  => 11,
            ];
            wachthond($extdebug, 7, 'params_email_send', $params_email_send);
            try {
                $result_email_send = civicrm_api3('Email', 'send', $params_email_send);
                wachthond($extdebug, 9, 'result_email_send', $result_email_send);
                CRM_Core_Session::setStatus(
                    ts('De herinneringsmail is verstuurd naar het gezin van %1.', [1 => $this->_part['displayname'] ?? '?']),
                    ts('Voorheen wachtlijst'), 'success');
            } catch (Exception $e) {
                wachthond($extdebug, 3, 'email_send_fout', $e->getMessage());
                CRM_Core_Session::setStatus(
                    ts('Het versturen van de herinneringsmail is niet gelukt: %1', [1 => $e->getMessage()]),
                    ts('Voorheen wachtlijst'), 'error');
            }

            parent::postProcess();
            return;
        }

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### WLERAF [FORM] 5.0 WACHTLIJST ERAF ZETTEN $participant_id", "[WRITE]");
        wachthond($extdebug, 2, "########################################################################");

        // 5.1 Alleen de procesdatum zetten; base_api_wrapper formatteert en skipt bij ongewijzigd.
        $data_update = [
            'PART_DEEL_INTERN.wachtlijst_eraf'  => date('Y-m-d'),
        ];
        $result_update = base_api_wrapper('Participant', $participant_id, $data_update, 'PARTSTATUS_LINKS_WL_ERAF', $extdebug);

        // 5.2 Motor expliciet aanjagen. Het custom-hook-pad doet dit normaliter ook al,
        // maar deze aanroep garandeert de doorzet ongeacht hook-volgorde (re-entrancy-safe).
        partstatus_configure($participant_id, NULL, NULL, 'links_wachtlijsteraf');

        // 5.3 Terugkoppeling met de VERSE status (force_refresh: cache is nu stale).
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
