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

    $this->assertNotNull($result, 'Scenario A (prima leeftijd + prima school) moet een resultaat opleveren.');
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
    $this->assertEquals('binnenmarges',     $result['criteria_indicatie'], 'Indicatie moet binnenmarges zijn bij marge-leeftijd aan de bovenkant.');
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel'],   'Marge aan de bovenkant wordt automatisch goedgekeurd.');
  }

  /**
   * tk1, leeftijd 15.0 (prima), klas_4 (marge voor tk) → binnenmarges + niet-nodig
   */
  public function testScenarioBMargeSchool() {
    $part   = $this->maakDeelnemer('tk1', 'klas_4', NULL, 13);
    $result = partstatus_criteria(0, $part, 15.0);

    $this->assertEquals('marge',            $result['criteria_school'],    'klas_4 op tk1 moet school-marge zijn.');
    $this->assertEquals('binnenmarges',     $result['criteria_indicatie'], 'Indicatie moet binnenmarges zijn bij school-marge (B2).');
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel'],   'School-marge wordt automatisch goedgekeurd.');
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

    $this->assertEquals('prima',          $result['criteria_leeftijd'],  'Leeftijd 9.0 op kk1 moet prima blijven in scenario C.');
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
    $this->assertEquals('prima',           $result['criteria_school'],    'groep_5 op kk1 moet prima blijven in scenario D.');
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

    $this->assertEquals('afwijkend',       $result['criteria_leeftijd'],  'Leeftijd 5.0 op kk1 moet afwijkend zijn in scenario E.');
    $this->assertEquals('afwijkend',       $result['criteria_school'],    'groep_8 op kk1 moet afwijkend zijn in scenario E.');
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
   * Ontbrekende deelnemer-data (geen $array_part, en part_id 0 dus ook geen lazy-load
   * via base_pid2part()) → NULL. Dit dekt het foutpad in partstatus.criteria.php
   * regel ~40 ("if (empty($array_part)) return NULL;"), dat voorheen niet expliciet
   * getest werd.
   */
  public function testOntbrekendeDeelnemerDataGeeftNull() {
    $result = partstatus_criteria(0, NULL, 9.0);
    $this->assertNull($result, 'Zonder deelnemer-data (geen $array_part, geen ophaalbare $part_id) moet de check NULL opleveren, niet crashen.');
  }

  /**
   * Lege array als deelnemer-data (geen velden ingevuld) → NULL.
   * Ander pad dan NULL zelf: empty([]) is ook TRUE, maar het is de moeite waard dit
   * expliciet vast te leggen zodat een toekomstige refactor van de empty()-check dit
   * niet stilzwijgend kan laten doorschieten naar de rol/event-type-filters hieronder.
   */
  public function testLegeDeelnemerArrayGeeftNull() {
    $result = partstatus_criteria(0, [], 9.0);
    $this->assertNull($result, 'Een lege deelnemer-array moet net als NULL worden behandeld en NULL opleveren.');
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

    $this->assertNotNull($result, 'Groep/klas-correctie mag geen NULL-resultaat opleveren.');
    $this->assertEquals('groep_5', $result['new_groepklas'], 'klas_5 op kk1 moet automatisch gecorrigeerd worden naar groep_5.');
    $this->assertEquals('prima',   $result['criteria_school'], 'Na correctie naar groep_5 moet school prima zijn.');
  }

  // ########################################################################
  // ### KAMPKORT-AANWEZIGHEID (3 waarden nodig: kampkort + groep/klas + leeftijd)
  // ########################################################################
  //
  // Regressie voor Rowan Buijl (jun 2026): een gelijktijdige dubbel-submit liet de
  // registratie afbreken op een DB-deadlock (1213/1412 op de ACL-cache) vóór CORE 8.2
  // (core.php), waardoor part_kampkort nooit vanuit het event werd gesynct. Met lege
  // kampkort matcht 17.4 geen enkele leeftijdsband → onterecht 'afwijkend' → wachtlijst.

  /**
   * Lege part_kampkort, maar het event-kampkort (kenmerken_kampkort) is bekend:
   * de check moet terugvallen op het event-kampkort. jk1 + 17.4 + klas_4 → prima.
   */
  public function testLegeKampkortValtTerugOpEventKampkort() {
    $part = [
      'part_rol'           => 'deelnemer',
      'event_type_id'      => 14,            // jeugdkamp
      'part_kampkort'      => '',            // leeg: nog niet gesynct (afgebroken registratie)
      'kenmerken_kampkort' => 'jk1',         // event-kampkort is wél bekend
      'part_groepklas'     => 'klas_4',
    ];
    $result = partstatus_criteria(0, $part, 17.4);

    $this->assertNotNull($result, 'Met een bekende event-kampkort mag de check niet afbreken.');
    $this->assertEquals('prima',            $result['criteria_leeftijd'],  '17.4 op jk1 moet prima zijn via event-fallback.');
    $this->assertEquals('prima',            $result['criteria_school'],    'klas_4 op jk1 moet prima zijn.');
    $this->assertEquals('criteriaprima',    $result['criteria_indicatie'], 'Indicatie moet criteriaprima zijn (geen wachtlijst).');
    $this->assertEquals('oordeelnietnodig', $result['criteria_oordeel'],   'Geen handmatig oordeel nodig bij perfecte match.');
  }

  /**
   * De event-fallback levert exact hetzelfde resultaat als wanneer part_kampkort wél gevuld is.
   */
  public function testEventFallbackGeeftZelfdeResultaatAlsGevuldeKampkort() {
    $base = [
      'part_rol'        => 'deelnemer',
      'event_type_id'   => 14,
      'part_groepklas'  => 'klas_4',
    ];
    $met_part  = partstatus_criteria(0, $base + ['part_kampkort' => 'jk1'], 17.4);
    $met_event = partstatus_criteria(0, $base + ['part_kampkort' => '', 'kenmerken_kampkort' => 'jk1'], 17.4);

    $this->assertEquals($met_part, $met_event, 'Event-fallback moet identiek oordelen aan een gevulde part_kampkort.');
  }

  /**
   * Kampkort volledig onbekend (part én event leeg): de check mag GEEN vals 'afwijkend'
   * produceren. Onze systeemfout → neutrale uitkomst: noggeenindicatie + oordeelnognodig +
   * de vlag criteria_incompleet (waarop de orkestratielaag status 8 forceert en webteam mailt).
   */
  public function testGeenKampkortGeeftIncompleetGeenValsAfwijkend() {
    $part = [
      'part_rol'        => 'deelnemer',
      'event_type_id'   => 14,
      'part_kampkort'   => '',   // part leeg
      // kenmerken_kampkort ontbreekt volledig
      'part_groepklas'  => 'klas_4',
    ];
    $result = partstatus_criteria(0, $part, 17.4);

    $this->assertNotNull($result, 'Onvolledige data mag geen NULL geven; de orkestratielaag heeft de vlag nodig.');
    $this->assertEquals('noggeenindicatie', $result['criteria_indicatie'], 'Indicatie moet neutraal zijn, niet leeftijd/school-wijktaf.');
    $this->assertEquals('oordeelnognodig',  $result['criteria_oordeel'],   'Oordeel moet op nog-nodig (handmatig) staan.');
    $this->assertTrue(!empty($result['criteria_incompleet']),             'criteria_incompleet moet gezet zijn bij ontbrekend kampkort.');
    $this->assertNotContains($result['criteria_indicatie'], ['leeftijdwijktaf', 'schoolwijktaf', 'criteriawijktaf'], 'Mag nooit een alarmerende wijkt-af-indicatie zijn.');
  }

  /**
   * Ontbrekend kampkort, maar er is al een handmatig beheerderoordeel: dat blijft behouden
   * en de aanmelding wordt NIET als incompleet gemarkeerd (geen webteam-alert nodig).
   */
  public function testGeenKampkortBehoudtHandmatigOordeel() {
    $part = [
      'part_rol'         => 'deelnemer',
      'event_type_id'    => 14,
      'part_kampkort'    => '',
      'part_groepklas'   => 'klas_4',
      'criteria_oordeel' => 'oordeelprima',   // beheerder heeft al beslist
    ];
    $result = partstatus_criteria(0, $part, 17.4);

    $this->assertEquals('oordeelprima', $result['criteria_oordeel'], 'Handmatig oordeel moet behouden blijven.');
    $this->assertTrue(empty($result['criteria_incompleet']),         'Met een handmatig oordeel is ingrijpen/alerting niet nodig.');
  }
}
