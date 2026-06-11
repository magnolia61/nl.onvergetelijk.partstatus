<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor partstatus_evaluate_wachtlijst().
 *
 * @group e2e
 *
 * De functie accepteert $array_part als directe invoer zodat DB-queries worden
 * vermeden. De $part_kampgeld_contribid-sleutel simuleert of er een paylink is.
 *
 * Scenario's:
 * Regel A: Legacy status 0 + wl_erop aanwezig          → status 7  (Wachtlijst)
 * Regel A: Legacy status 5 + positief oordeel          → status 1  (Bevestigd)
 * Regel A: Legacy status 6 + geen info                 → status 8  (Afwachting Oordeel)
 * Regel B: Status 8 + positief oordeel + geen paylink  → status 9  (Afwachting Betaling)
 * Regel B: Status 8 + positief oordeel + paylink       → status 1  (Bevestigd, via Regel D)
 * Regel B: Status 8 + geen oordeel                     → blijft 8
 * Regel C: Status 7 + leeg wl_erop                     → wl_erop gevuld met register_date
 * Regel D: Status 9 + paylink                          → status 1  (Bevestigd)
 * Regel D: Status 9 + geen paylink                     → blijft 9
 * Retourstructuur bevat alle verwachte sleutels
 */
class WachtlijstTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('partstatus_evaluate_wachtlijst')) {
      $this->markTestSkipped('partstatus_evaluate_wachtlijst() niet beschikbaar; is nl.onvergetelijk.partstatus geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### HELPERS
  // ########################################################################

  private function maakPart(int $statusId, array $extra = []): array {
    return array_merge([
      'status_id'               => $statusId,
      'wachtlijst_erop'         => NULL,
      'wachtlijst_eraf'         => NULL,
      'criteriacheck_einde'     => NULL,
      'criteria_indicatie'      => NULL,
      'criteria_oordeel'        => NULL,
      'register_date'           => '2026-01-10 09:00:00',
      'part_kampgeld_contribid' => 0,
    ], $extra);
  }

  // ########################################################################
  // ### REGEL A: LEGACY STATUS HERSTEL (0, 5, 6)
  // ########################################################################

  /**
   * Legacy status 0 + wl_erop aanwezig, geen wl_eraf → hersteld naar status 7
   */
  public function testRegelALegacyNulMetWlErop() {
    $part   = $this->maakPart(0, ['wachtlijst_erop' => '2026-01-10 09:00:00']);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(7, $result['status_id'], 'Status 0 met wl_erop moet hersteld worden naar 7 (Wachtlijst).');
  }

  /**
   * Legacy status 5 + positief oordeel → direct naar status 1 (Bevestigd)
   */
  public function testRegelALegacyVijfMetPositiefOordeel() {
    $part   = $this->maakPart(5, ['criteria_oordeel' => 'oordeelprima']);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(1, $result['status_id'], 'Status 5 met positief oordeel moet hersteld worden naar 1 (Bevestigd).');
  }

  /**
   * Legacy status 6 + indicatie=criteriaprima → direct naar status 1
   */
  public function testRegelALegacyZesMetIndicatiePrima() {
    $part   = $this->maakPart(6, ['criteria_indicatie' => 'criteriaprima']);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(1, $result['status_id'], 'Status 6 met criteriaprima indicatie moet naar 1 (Bevestigd).');
  }

  /**
   * Legacy status 0 + geen wl_erop en geen positief oordeel → veiligheid naar status 8
   */
  public function testRegelALegacyNulZonderInfo() {
    $part   = $this->maakPart(0);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(8, $result['status_id'], 'Status 0 zonder info moet naar 8 (Afwachting Oordeel) als veiligheid.');
  }

  // ########################################################################
  // ### REGEL B: OORDEEL AFGEROND (STATUS 8 → 9)
  // ########################################################################

  /**
   * Status 8 + positief oordeel + geen paylink → 9 (Afwachting Betaling)
   */
  public function testRegelBOordeelPositiefZonderPaylink() {
    $part   = $this->maakPart(8, ['criteria_oordeel' => 'oordeelprima']);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(9, $result['status_id'], 'Status 8 met positief oordeel zonder paylink moet naar 9 (Afwachting Betaling).');
  }

  /**
   * Status 8 + positief oordeel + paylink → 1 (Bevestigd, via Regel D)
   */
  public function testRegelBOordeelPositiefMetPaylink() {
    $part   = $this->maakPart(8, [
      'criteria_oordeel'        => 'oordeelprima',
      'part_kampgeld_contribid' => 42,
    ]);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(1, $result['status_id'], 'Status 8 met positief oordeel én paylink moet doorstromen naar 1 (Bevestigd).');
  }

  /**
   * Status 8 + check_einde gevuld + geen paylink → 9
   */
  public function testRegelBCheckEindeBijgewerkt() {
    $part   = $this->maakPart(8, ['criteriacheck_einde' => '2026-02-15']);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(9, $result['status_id'], 'Status 8 met check_einde gevuld moet vrijgegeven worden naar 9.');
  }

  /**
   * Status 8 + geen oordeel + geen check_einde → blijft status 8
   */
  public function testRegelBBlijftInStatus8ZonderOordeel() {
    $part   = $this->maakPart(8);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(8, $result['status_id'], 'Status 8 zonder oordeel mag niet van 8 verschuiven.');
  }

  // ########################################################################
  // ### REGEL C: WACHTLIJST EROP AUTO-INVULLEN (STATUS 7)
  // ########################################################################

  /**
   * Status 7 + leeg wl_erop → wl_erop wordt gevuld met register_date
   */
  public function testRegelCWlEropAutoInvullen() {
    $regDate = '2026-01-10 09:00:00';
    $part    = $this->maakPart(7, ['register_date' => $regDate]);
    $result  = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(7,        $result['status_id'], 'Status 7 mag niet van status veranderen door Regel C.');
    $this->assertEquals($regDate, $result['wl_erop'],   'Lege wl_erop moet gevuld worden met register_date.');
  }

  /**
   * Status 7 + wl_erop al aanwezig → wl_erop ongewijzigd
   */
  public function testRegelCWlEropOngewijzigdAlsBezet() {
    $bestaandeDatum = '2025-12-01 00:00:00';
    $part           = $this->maakPart(7, ['wachtlijst_erop' => $bestaandeDatum]);
    $result         = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals($bestaandeDatum, $result['wl_erop'], 'Bestaande wl_erop datum mag niet overschreven worden.');
  }

  // ########################################################################
  // ### REGEL D: BETALING → BEVESTIGING (STATUS 9)
  // ########################################################################

  /**
   * Status 9 + paylink aanwezig → status 1 (Bevestigd)
   */
  public function testRegelDStatus9MetPaylink() {
    $part   = $this->maakPart(9, ['part_kampgeld_contribid' => 101]);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(1,          $result['status_id'],    'Status 9 met paylink moet promoveren naar 1 (Bevestigd).');
    $this->assertEquals('Bevestigd', $result['status_label']);
  }

  /**
   * Status 9 + geen paylink → blijft status 9
   */
  public function testRegelDStatus9ZonderPaylink() {
    $part   = $this->maakPart(9);
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertEquals(9, $result['status_id'], 'Status 9 zonder paylink moet op 9 (Afwachting Betaling) blijven.');
    $this->assertStringContainsString('Betaling', $result['status_label']);
  }

  // ########################################################################
  // ### RETOURSTRUCTUUR
  // ########################################################################

  /**
   * Controleer dat alle verwachte sleutels aanwezig zijn in het resultaat.
   */
  public function testRetourstructuurHeeftAlleSleutels() {
    $part   = $this->maakPart(2); // Aangemeld (geen regels van toepassing)
    $result = partstatus_evaluate_wachtlijst(0, $part);

    $this->assertIsArray($result);
    foreach (['status_id', 'status_label', 'wl_erop', 'wl_eraf'] as $key) {
      $this->assertArrayHasKey($key, $result, "Sleutel '$key' ontbreekt in retourarray.");
    }
  }

  /**
   * Lege array_part + geen part_id → NULL
   */
  public function testLegeInvoerGeeftNull() {
    $result = partstatus_evaluate_wachtlijst(0, []);
    $this->assertNull($result, 'Lege $array_part moet NULL opleveren.');
  }
}
