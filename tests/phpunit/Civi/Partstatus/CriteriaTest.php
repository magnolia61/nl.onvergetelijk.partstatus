<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor partstatus_criteria().
 *
 * @group e2e
 *
 * Alle scenario's worden volledig aangestuurd via $array_part + $leeftijd_dec,
 * zodat er geen DB-lookups plaatsvinden.
 *
 * Scenario's (conform de broncode):
 * A: Prima leeftijd + prima school         → criteriaprima    / oordeelnietnodig
 * B: Marge leeftijd + prima school         → binnenmarges     / oordeelnietnodig
 * B2: Prima leeftijd + marge school (tk)   → binnenmarges     / oordeelnietnodig
 * C: Prima leeftijd + afwijkende school    → schoolwijktaf    / oordeelnognodig
 * D: Afwijkende leeftijd + prima school    → leeftijdwijktaf  / oordeelnognodig
 * E: Beide afwijkend                       → criteriawijktaf  / oordeelnognodig
 * -: Rol is leiding                        → NULL
 * -: Event type niet in criteria-lijst     → NULL
 * -: Handmatig oordeel (admin override)    → oordeel ongewijzigd
 * -: Groep/klas correctie (kk: klas→groep) → new_groepklas gecorrigeerd
 */
class CriteriaTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('partstatus_criteria')) {
      $this->markTestSkipped('partstatus_criteria() niet beschikbaar; is nl.onvergetelijk.partstatus geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### HELPERS
  // ########################################################################

  private function maakDeelnemer(string $kampkort, string $groepklas, ?string $oordeel = NULL, int $eventType = 11): array {
    return [
      'part_rol'         => 'deelnemer',
      'event_type_id'    => $eventType,
      'part_kampkort'    => $kampkort,
      'part_groepklas'   => $groepklas,
      'criteria_oordeel' => $oordeel,
    ];
  }

  // ########################################################################
  // ### SCENARIO A: PRIMA + PRIMA
  // ########################################################################

  /**
   * kk1, leeftijd 9.0 (prima), groep_5 (prima) → criteriaprima + niet-nodig
   */
  public function testScenarioAPrimaLeeftijdPrimaSchool() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5');
    $result = partstatus_criteria(0, $part, 9.0);

    $this->assertNotNull($result);
    $this->assertEquals('prima',            $result['criteria_leeftijd'],  'Leeftijd 9.0 op kk1 moet prima zijn.');
    $this->assertEquals('prima',            $result['criteria_school'],    'groep_5 op kk1 moet prima zijn.');
    $this->assertEquals('criteriaprima',    $result['criteria_indicatie'], 'Indicatie moet criteriaprima zijn.');
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel'],   'Oordeel moet automatisch niet-nodig worden bij perfecte match.');
  }

  // ########################################################################
  // ### SCENARIO B: MARGE
  // ########################################################################

  /**
   * kk1, leeftijd 6.8 (marge: 6.7-7.0), groep_5 (prima) → binnenmarges + niet-nodig
   */
  public function testScenarioBMargeLeeftijdOnderkant() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5');
    $result = partstatus_criteria(0, $part, 6.8);

    $this->assertEquals('marge',            $result['criteria_leeftijd'],  'Leeftijd 6.8 op kk1 moet marge zijn (onderkant 6.7-7.0).');
    $this->assertEquals('binnenmarges',     $result['criteria_indicatie'], 'Indicatie moet binnenmarges zijn.');
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel'],   'Marge wordt automatisch goedgekeurd.');
  }

  /**
   * kk1, leeftijd 12.2 (marge: 12.0-12.3), groep_5 (prima) → binnenmarges + niet-nodig
   */
  public function testScenarioBMargeLeeftijdBovenkant() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5');
    $result = partstatus_criteria(0, $part, 12.2);

    $this->assertEquals('marge',            $result['criteria_leeftijd'],  'Leeftijd 12.2 op kk1 moet marge zijn (bovenkant 12.0-12.3).');
    $this->assertEquals('binnenmarges',     $result['criteria_indicatie']);
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel']);
  }

  /**
   * tk1, leeftijd 15.0 (prima), klas_4 (marge voor tk) → binnenmarges + niet-nodig
   */
  public function testScenarioBMargeSchool() {
    $part   = $this->maakDeelnemer('tk1', 'klas_4', NULL, 13);
    $result = partstatus_criteria(0, $part, 15.0);

    $this->assertEquals('marge',            $result['criteria_school'],    'klas_4 op tk1 moet school-marge zijn.');
    $this->assertEquals('binnenmarges',     $result['criteria_indicatie']);
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel']);
  }

  // ########################################################################
  // ### SCENARIO C: SCHOOL AFWIJKEND (LEEFTIJD OK)
  // ########################################################################

  /**
   * kk1, leeftijd 9.0 (prima), groep_8 (afwijkend) → schoolwijktaf
   */
  public function testScenarioCSchoolAfwijkend() {
    $part   = $this->maakDeelnemer('kk1', 'groep_8');
    $result = partstatus_criteria(0, $part, 9.0);

    $this->assertEquals('prima',          $result['criteria_leeftijd']);
    $this->assertEquals('afwijkend',      $result['criteria_school'],    'groep_8 op kk1 moet afwijkend zijn.');
    $this->assertEquals('schoolwijktaf',  $result['criteria_indicatie'], 'Indicatie moet schoolwijktaf zijn.');
    $this->assertEquals('oordeelnognodig', $result['criteria_oordeel'],  'Handmatig oordeel vereist bij schoolafwijking.');
  }

  // ########################################################################
  // ### SCENARIO D: LEEFTIJD AFWIJKEND (SCHOOL OK)
  // ########################################################################

  /**
   * kk1, leeftijd 13.0 (te oud voor kk: max 12.3), groep_5 (prima) → leeftijdwijktaf
   */
  public function testScenarioDLeeftijdAfwijkend() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5');
    $result = partstatus_criteria(0, $part, 13.0);

    $this->assertEquals('afwijkend',       $result['criteria_leeftijd'],  'Leeftijd 13.0 op kk1 moet afwijkend zijn.');
    $this->assertEquals('prima',           $result['criteria_school']);
    $this->assertEquals('leeftijdwijktaf', $result['criteria_indicatie'], 'Indicatie moet leeftijdwijktaf zijn.');
  }

  // ########################################################################
  // ### SCENARIO E: BEIDE AFWIJKEND
  // ########################################################################

  /**
   * kk1, leeftijd 5.0 (te jong), groep_8 (afwijkend) → criteriawijktaf
   */
  public function testScenarioEBeideAfwijkend() {
    $part   = $this->maakDeelnemer('kk1', 'groep_8');
    $result = partstatus_criteria(0, $part, 5.0);

    $this->assertEquals('afwijkend',       $result['criteria_leeftijd']);
    $this->assertEquals('afwijkend',       $result['criteria_school']);
    $this->assertEquals('criteriawijktaf', $result['criteria_indicatie'], 'Beide afwijkend moet criteriawijktaf opleveren.');
  }

  // ########################################################################
  // ### GRENSGEVALLEN
  // ########################################################################

  /**
   * Rol is leiding → NULL (geen criteria van toepassing)
   */
  public function testLeidingRolGeeftNull() {
    $part             = $this->maakDeelnemer('kk1', 'groep_5');
    $part['part_rol'] = 'leiding';
    $result           = partstatus_criteria(0, $part, 9.0);
    $this->assertNull($result, 'Leiding heeft geen leeftijds/schoolcriteria; moet NULL zijn.');
  }

  /**
   * Event type buiten de criteria-lijst → NULL
   */
  public function testEventTypeZonderCriteria() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5', NULL, 99);
    $result = partstatus_criteria(0, $part, 9.0);
    $this->assertNull($result, 'Event type 99 valt buiten de criteria-lijst; moet NULL zijn.');
  }

  /**
   * Handmatig admin-oordeel 'oordeelprima' blijft behouden, ook bij leeftijdsafwijking.
   */
  public function testHandmatigOordeelBlijftBehouden() {
    $part   = $this->maakDeelnemer('kk1', 'groep_5', 'oordeelprima');
    $result = partstatus_criteria(0, $part, 13.0);

    $this->assertEquals('oordeelprima', $result['criteria_oordeel'], 'Handmatig admin-oordeel mag niet overschreven worden door de automatische check.');
  }

  /**
   * Groep/klas correctie: kk-kamp met "klas_5" → wordt gecorrigeerd naar "groep_5"
   */
  public function testGroepKlasCorrectieKK() {
    $part   = $this->maakDeelnemer('kk1', 'klas_5');
    $result = partstatus_criteria(0, $part, 9.0);

    $this->assertNotNull($result);
    $this->assertEquals('groep_5', $result['new_groepklas'], 'klas_5 op kk1 moet automatisch gecorrigeerd worden naar groep_5.');
    $this->assertEquals('prima',   $result['criteria_school'], 'Na correctie naar groep_5 moet school prima zijn.');
  }
}
