<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * E2E-tests voor de twee actiemenu-popups (CRM_Partstatus_Form_CriteriaPrima en
 * CRM_Partstatus_Form_WachtlijstEraf).
 *
 * @group e2e
 *
 * Deze forms waren tot nu toe alleen handmatig via de UI te testen — en dat kón niet
 * veilig, want base_pid2part() verbergt is_test=1, dus elke UI-klik raakt een echte
 * registratie en verstuurt een echte mail. Deze tests dekken het runtime-pad van
 * postProcess() af op een fixture-registratie (is_test=0), waarbij de e-mail in de
 * testomgeving naar de mail-spool gaat (geen echte verzending).
 *
 * Per form vier gedragingen:
 *   - WRITE-modus (proces nog niet afgerond): de form-eigen DB-write gebeurt en de
 *     motor (partstatus_configure) draait door.
 *       CriteriaPrima  → criteria_oordeel = 'oordeelprima' + criteriacheck_einde = vandaag
 *       WachtlijstEraf → wachtlijst_eraf = vandaag
 *   - MAIL-modus (proces al afgerond, datum-einde gevuld): alléén de (herinnerings/
 *     goedkeurings)mail, GEEN DB-write op de procesvelden.
 *   - RACE-GUARD: submit met een popup_modus die afwijkt van de verse modus → NIETS
 *     uitvoeren (geen write, geen mail).
 *
 * De forms lezen hun submit-waarden via exportValues(); de doubles onderaan dit bestand
 * overschrijven die met een gecontroleerde array, zodat postProcess() zonder volledige
 * QuickForm-lifecycle aangeroepen kan worden. CRM_Core_Form::postProcess() is leeg, dus
 * er is geen controller nodig.
 */
class LinksFormsTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!class_exists('CRM_Partstatus_Form_WachtlijstEraf')
        || !class_exists('CRM_Partstatus_Form_CriteriaPrima')
        || !function_exists('base_pid2part')
        || !function_exists('partstatus_configure')) {
      $this->markTestSkipped('partstatus-forms of base-helpers niet beschikbaar.');
    }

    // Pin de session-userID op het systeemcontact (1). De motor (partstatus_configure)
    // laat anders een userID achter die naar een door TransactionalInterface teruggedraaide
    // fixture-contact wijst; de volgende Contact.create schrijft dan naar civicrm_log met
    // een modified_id-FK die niet meer bestaat → 'constraint violation'. Zie memory
    // accountscenario_orphan_drupal_users (session-userID-lek).
    \CRM_Core_Session::singleton()->set('userID', 1);
  }

  // ########################################################################
  // ### FIXTURE
  // ########################################################################

  /**
   * Maakt een echte (is_test=0) registratie met een e-mailadres en de meegegeven
   * PART_DEEL_INTERN-procesvelden, en geeft het participant-ID terug.
   *
   * @param array $internVelden  bijv. ['PART_DEEL_INTERN.wachtlijst_erop' => '2026-03-01']
   */
  private function maakRegistratieFixture(string $naamsuffix, array $internVelden): int {
    $contactId = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'LinksForm',
        'last_name'    => $naamsuffix,
      ],
    ])->first()['id'];

    // Fictief, niet-bestaand adres — de testomgeving levert toch niet af, maar zo raakt
    // een eventuele echte verzending sowieso nooit een echt gezin.
    civicrm_api4('Email', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'       => $contactId,
        'email'            => 'linksform.' . strtolower($naamsuffix) . '@example.invalid',
        'location_type_id' => 1,
        'is_primary'       => TRUE,
      ],
    ]);

    $eventId = civicrm_api4('Event', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'title'         => 'LinksForm ' . $naamsuffix,
        'event_type_id' => 2,
        'start_date'    => date('Y-m-d', strtotime('+10 days')),
        'is_active'     => TRUE,
      ],
    ])->first()['id'];

    $participantId = civicrm_api4('Participant', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'     => $contactId,
        'event_id'       => $eventId,
        'status_id:name' => 'Registered',
        'is_test'        => FALSE,
      ],
    ])->first()['id'];

    // Procesvelden zetten (maakt de PART_DEEL_INTERN-rij aan).
    civicrm_api4('Participant', 'update', [
      'checkPermissions' => FALSE,
      'values'           => ['id' => $participantId] + $internVelden,
    ]);

    return $participantId;
  }

  /**
   * Leest één PART_DEEL_INTERN-veld vers uit de DB.
   */
  private function leesInternVeld(int $participantId, string $veld) {
    return civicrm_api4('Participant', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ["PART_DEEL_INTERN.$veld"],
      'where'            => [['id', '=', $participantId]],
    ])->first()["PART_DEEL_INTERN.$veld"] ?? NULL;
  }

  /**
   * Als leesInternVeld, maar geeft alleen het datumdeel terug. Date-velden komen via
   * APIv4 terug als 'YYYY-MM-DD 00:00:00'; zo vergelijken we op de kale datum.
   */
  private function leesDatum(int $participantId, string $veld): ?string {
    $waarde = $this->leesInternVeld($participantId, $veld);
    return $waarde ? substr((string) $waarde, 0, 10) : NULL;
  }

  // ########################################################################
  // ### SCENARIO A: WachtlijstEraf — DOORZET-modus zet de eraf-datum
  // ########################################################################

  /**
   * Wachtlijst-proces gestart (erop gevuld) maar nog niet doorgezet (eraf leeg) →
   * doorzet-modus. postProcess moet PART_DEEL_INTERN.wachtlijst_eraf op vandaag zetten
   * (de form-eigen write, vóór de motor) en de motor zonder fatale fout laten draaien.
   */
  public function testWachtlijstErafDoorzetModusZetErafDatum(): void {
    $pid = $this->maakRegistratieFixture('WlDoorzet', [
      'PART_DEEL_INTERN.wachtlijst_erop' => '2026-03-01',
    ]);

    $form = new WachtlijstErafDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = FALSE; // eraf leeg → doorzet-modus
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'doorzetten'];
    $form->postProcess();

    $this->assertSame(date('Y-m-d'), $this->leesDatum($pid, 'wachtlijst_eraf'),
      'Doorzet-modus moet wachtlijst_eraf op vandaag zetten.');
  }

  // ########################################################################
  // ### SCENARIO B: WachtlijstEraf — REMINDER-modus mailt, geen write
  // ########################################################################

  /**
   * Wachtlijst al doorgezet (eraf gevuld) → reminder-modus. postProcess mag alléén de
   * herinneringsmail versturen en de procesvelden ONgemoeid laten.
   */
  public function testWachtlijstErafReminderModusMailtGeenWrite(): void {
    $pid = $this->maakRegistratieFixture('WlReminder', [
      'PART_DEEL_INTERN.wachtlijst_erop' => '2026-03-01',
      'PART_DEEL_INTERN.wachtlijst_eraf' => '2026-04-01',
    ]);

    $form = new WachtlijstErafDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = TRUE; // eraf gevuld → reminder-modus
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'reminder'];
    $form->postProcess();

    $this->assertSame('2026-04-01', $this->leesDatum($pid, 'wachtlijst_eraf'),
      'Reminder-modus mag wachtlijst_eraf niet wijzigen (alleen mailen).');
  }

  // ########################################################################
  // ### SCENARIO C: WachtlijstEraf — RACE-GUARD (modus-mismatch doet niets)
  // ########################################################################

  /**
   * De popup is in doorzet-modus geopend, maar de situatie is intussen veranderd
   * (verse modus = reminder). De race-guard moet dan NIETS doen: geen eraf-datum zetten.
   */
  public function testWachtlijstErafRaceGuardMismatchDoetNiets(): void {
    $pid = $this->maakRegistratieFixture('WlRace', [
      'PART_DEEL_INTERN.wachtlijst_erop' => '2026-03-01',
    ]);

    $form = new WachtlijstErafDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = TRUE;                    // verse modus = reminder
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'doorzetten']; // geopend in doorzet
    $form->postProcess();

    $this->assertEmpty($this->leesInternVeld($pid, 'wachtlijst_eraf'),
      'Bij een modus-mismatch mag de race-guard niets uitvoeren (eraf blijft leeg).');
  }

  // ########################################################################
  // ### SCENARIO D: CriteriaPrima — OORDEEL-modus keurt goed
  // ########################################################################

  /**
   * Criteriacheck gestart (start gevuld) maar nog niet afgerond (einde leeg) →
   * oordeel-modus. postProcess moet criteria_oordeel op 'oordeelprima' en
   * criteriacheck_einde op vandaag zetten, en de motor laten doordraaien.
   */
  public function testCriteriaPrimaOordeelModusKeurtGoed(): void {
    $pid = $this->maakRegistratieFixture('CritOordeel', [
      'PART_DEEL_INTERN.criteriacheck_start' => '2026-03-01',
    ]);

    $form = new CriteriaPrimaDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = FALSE; // einde leeg → oordeel-modus
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'oordeel'];
    $form->postProcess();

    $this->assertSame('oordeelprima', $this->leesInternVeld($pid, 'criteria_oordeel'),
      'Oordeel-modus moet criteria_oordeel op oordeelprima zetten.');
    $this->assertSame(date('Y-m-d'), $this->leesDatum($pid, 'criteriacheck_einde'),
      'Oordeel-modus moet criteriacheck_einde op vandaag zetten.');
  }

  // ########################################################################
  // ### SCENARIO E: CriteriaPrima — RESEND-modus mailt, geen write
  // ########################################################################

  /**
   * Criteriacheck al afgerond (einde gevuld) → resend-modus. postProcess mag alléén de
   * goedkeuringsmail versturen en het oordeel/de einddatum ONgemoeid laten.
   */
  public function testCriteriaPrimaResendModusMailtGeenWrite(): void {
    $pid = $this->maakRegistratieFixture('CritResend', [
      'PART_DEEL_INTERN.criteriacheck_start' => '2026-03-01',
      'PART_DEEL_INTERN.criteriacheck_einde' => '2026-03-15',
      'PART_DEEL_INTERN.criteria_oordeel'    => 'oordeelprima',
    ]);

    $form = new CriteriaPrimaDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = TRUE; // einde gevuld → resend-modus
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'resend'];
    $form->postProcess();

    $this->assertSame('2026-03-15', $this->leesDatum($pid, 'criteriacheck_einde'),
      'Resend-modus mag criteriacheck_einde niet wijzigen (alleen mailen).');
  }

  // ########################################################################
  // ### SCENARIO F: CriteriaPrima — RACE-GUARD (modus-mismatch doet niets)
  // ########################################################################

  /**
   * De popup is in oordeel-modus geopend, maar de verse modus is intussen resend.
   * De race-guard moet dan NIETS doen: geen oordeel geven.
   */
  public function testCriteriaPrimaRaceGuardMismatchDoetNiets(): void {
    $pid = $this->maakRegistratieFixture('CritRace', [
      'PART_DEEL_INTERN.criteriacheck_start' => '2026-03-01',
    ]);

    $form = new CriteriaPrimaDouble();
    $form->_participantId = $pid;
    $form->_part          = base_pid2part($pid, TRUE);
    $form->_reminderModus = TRUE;                    // verse modus = resend
    $form->testValues     = ['participant_id' => $pid, 'popup_modus' => 'oordeel']; // geopend in oordeel
    $form->postProcess();

    $oordeel = $this->leesInternVeld($pid, 'criteria_oordeel');
    $this->assertNotSame('oordeelprima', $oordeel,
      'Bij een modus-mismatch mag de race-guard geen oordeel geven.');
  }
}

/**
 * Form-doubles: overschrijven exportValues() met een gecontroleerde array, zodat
 * postProcess() zonder QuickForm-submit aangeroepen kan worden.
 */
class WachtlijstErafDouble extends \CRM_Partstatus_Form_WachtlijstEraf {
  public $testValues = [];
  public function exportValues($elementList = NULL, $filterInternal = FALSE) {
    return $this->testValues;
  }
}

class CriteriaPrimaDouble extends \CRM_Partstatus_Form_CriteriaPrima {
  public $testValues = [];
  public function exportValues($elementList = NULL, $filterInternal = FALSE) {
    return $this->testValues;
  }
}
