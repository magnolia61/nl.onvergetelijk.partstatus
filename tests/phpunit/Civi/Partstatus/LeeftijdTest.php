<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor partstatus_leeftijd_diff().
 *
 * @group e2e
 *
 * Draait tegen de live CiviCRM-database zodat alle OZK-extensies beschikbaar zijn.
 * TransactionalInterface rolt alle DB-wijzigingen terug na elke test.
 *
 * Scenario's:
 * 1. Exacte leeftijd (0 maanden)  → decimaal .0
 * 2. Halfjaar                     → decimaal .5
 * 3. 11 maanden                   → decimaal .9
 * 4. Lege geboortedatum           → NULL
 * 5. Lege peildatum               → NULL
 * 6. Static cache                 → zelfde resultaat bij tweede aanroep
 * 7. Retourarray structuur        → alle verwachte sleutels aanwezig
 */
class LeeftijdTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('partstatus_leeftijd_diff')) {
      $this->markTestSkipped('partstatus_leeftijd_diff() niet beschikbaar; is nl.onvergetelijk.partstatus geïnstalleerd?');
    }
    if (!function_exists('wachthond')) {
      $this->markTestSkipped('wachthond() niet beschikbaar; is nl.onvergetelijk.logger geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### SCENARIO 1: EXACTE LEEFTIJDEN
  // ########################################################################

  /**
   * Exact 12 jaar → decimalen = 12.0, rondjaren = 12, rondmaand = 0
   */
  public function testExacteLeeftijdTwaalf() {
    $result = partstatus_leeftijd_diff('test', '2012-07-01', '2024-07-01');
    $this->assertNotNull($result, 'Resultaat mag niet NULL zijn bij exacte 12 jaar leeftijd.');
    $this->assertEquals(12.0, $result['leeftijd_decimalen'], 'Exacte 12 jaar moet 12.0 zijn.');
    $this->assertEquals(12,   $result['leeftijd_rondjaren'], 'Rondjaren moet 12 zijn.');
    $this->assertEquals(0,    $result['leeftijd_rondmaand'], 'Rondmaand moet 0 zijn bij exacte verjaardag.');
  }

  /**
   * 9 jaar en 6 maanden → decimalen = 9.5
   */
  public function testHalfjaarLeeftijd() {
    $result = partstatus_leeftijd_diff('test', '2014-01-01', '2023-07-01');
    $this->assertNotNull($result, 'Resultaat mag niet NULL zijn bij halfjaar-leeftijdsscenario.');
    $this->assertEquals(9.5, $result['leeftijd_decimalen'], '9 jaar en 6 maanden moet 9.5 zijn.');
    $this->assertEquals(9,   $result['leeftijd_rondjaren'], 'Rondjaren moet 9 zijn bij 9 jaar en 6 maanden.');
    $this->assertEquals(6,   $result['leeftijd_rondmaand'], 'Rondmaand moet 6 zijn bij een half jaar sinds de verjaardag.');
  }

  /**
   * 10 jaar en 11 maanden → decimalen = 10.9
   * (11/12 * 10 = 9.17 → afgerond naar 9)
   */
  public function testElfMaanden() {
    $result = partstatus_leeftijd_diff('test', '2013-08-01', '2024-07-01');
    $this->assertNotNull($result, 'Resultaat mag niet NULL zijn bij 11-maanden-scenario.');
    $this->assertEquals(10.9, $result['leeftijd_decimalen'], '10 jaar en 11 maanden moet 10.9 zijn.');
    $this->assertEquals(10,   $result['leeftijd_rondjaren'], 'Rondjaren moet 10 zijn bij 10 jaar en 11 maanden.');
  }

  // ########################################################################
  // ### SCENARIO 2: VALIDATIE (ONTBREKENDE INVOER)
  // ########################################################################

  public function testLegeBirthdate() {
    $result = partstatus_leeftijd_diff('test', '', '2024-07-01');
    $this->assertNull($result, 'Lege geboortedatum moet NULL opleveren.');
  }

  public function testLegePeildatum() {
    $result = partstatus_leeftijd_diff('test', '2012-07-01', '');
    $this->assertNull($result, 'Lege peildatum moet NULL opleveren.');
  }

  // ########################################################################
  // ### SCENARIO 3: STATIC CACHE
  // ########################################################################

  public function testStaticCacheGeeftZelfdeResultaat() {
    $a = partstatus_leeftijd_diff('eerste_aanroep', '2010-03-15', '2024-03-15');
    $b = partstatus_leeftijd_diff('tweede_aanroep', '2010-03-15', '2024-03-15');
    $this->assertEquals($a, $b, 'Cache hit moet hetzelfde resultaat geven als de eerste berekening.');
  }

  // ########################################################################
  // ### SCENARIO 4: RETOURSTRUCTUUR
  // ########################################################################

  public function testRetourArrayStructuur() {
    $result = partstatus_leeftijd_diff('structuur', '2008-06-01', '2026-06-01');
    $this->assertIsArray($result, 'partstatus_leeftijd_diff() moet een array retourneren.');
    foreach (['leeftijd_birthdate', 'leeftijd_refdate', 'leeftijd_decimalen', 'leeftijd_rondjaren', 'leeftijd_rondmaand'] as $key) {
      $this->assertArrayHasKey($key, $result, "Sleutel '$key' ontbreekt in retourarray.");
    }
    $this->assertEquals('2008-06-01', $result['leeftijd_birthdate'], 'leeftijd_birthdate moet de opgegeven geboortedatum bevatten.');
    $this->assertEquals('2026-06-01', $result['leeftijd_refdate'], 'leeftijd_refdate moet de opgegeven peildatum bevatten.');
  }
}
