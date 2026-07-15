<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor de koppeling tussen criteria-invoer en de berekende uitkomst.
 *
 * @group e2e
 *
 * Elk scenario begint met drie invoerwaarden:
 *   $kampkort  — kamp waarvoor de deelnemer zich aanmeldt
 *   $leeftijd  — decimale leeftijd op kampdatum
 *   $groepklas — ingevulde groep of klas
 *
 * Verder niks: geen status, geen paylink, geen event-metadata.
 * De functie partstatus_criteria() berekent de uitkomst puur op basis van die drie waarden.
 *
 * Scenarios:
 *   A  prima + prima        → criteriaprima    / oordeelnietnodig
 *   B  marge leeftijd       → binnenmarges     / oordeelnietnodig
 *   B2 marge school         → binnenmarges     / oordeelnietnodig
 *   C  school afwijkend     → schoolwijktaf    / oordeelnognodig
 *   D  leeftijd afwijkend   → leeftijdwijktaf  / oordeelnognodig
 *   E  beide afwijkend      → criteriawijktaf  / oordeelnognodig
 *
 * Extra:
 *   - auto-correctie groep/klas (ouder typt 'klas_5' op kk → wordt 'groep_5')
 *   - handmatig admin-oordeel wordt nooit overschreven
 *   - ontbrekend kampkort → criteria_incompleet (veilige neutrale uitkomst)
 */
class CriteriaStatusTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

    public function setUp(): void {
        parent::setUp();
        if (!function_exists('partstatus_criteria')) {
            $this->markTestSkipped('partstatus_criteria() niet beschikbaar; is nl.onvergetelijk.partstatus geïnstalleerd?');
        }
    }

    // ########################################################################
    // ### Helper
    // ########################################################################

    /**
     * Voert partstatus_criteria() uit met minimale invoer.
     *
     * @param string      $kampkort   Kamp waarvoor de deelnemer zich aanmeldt
     * @param float       $leeftijd   Decimale leeftijd op kampdatum
     * @param string      $groepklas  Ingevulde groep of klas
     * @param string|null $oordeel    Optioneel: bestaand handmatig oordeel
     */
    private function criteria(string $kampkort, float $leeftijd, string $groepklas, ?string $oordeel = NULL): array {
        $deel = [
            'part_rol'         => 'deelnemer',
            'event_type_id'    => 11,           // irrelevant voor de pure functie; enkel de guard-check passeert
            'part_kampkort'    => $kampkort,
            'part_groepklas'   => $groepklas,
            'criteria_oordeel' => $oordeel,
        ];
        return partstatus_criteria(0, $deel, $leeftijd);
    }

    // ########################################################################
    // ### SCENARIO A: PRIMA + PRIMA
    // ########################################################################

    public function testKK_LeeftijdPrimaSchoolPrima(): void {
        $r = $this->criteria('kk1', 9.0, 'groep_5');

        $this->assertSame('prima',            $r['criteria_leeftijd'],  '9 jaar op kk1 → prima');
        $this->assertSame('prima',            $r['criteria_school'],    'groep_5 op kk1 → prima');
        $this->assertSame('criteriaprima',    $r['criteria_indicatie'],  'Prima leeftijd + prima school op kk1 → indicatie criteriaprima (scenario A)');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Perfecte match → geen handmatig oordeel nodig');
    }

    public function testJK_LeeftijdPrimaSchoolPrima(): void {
        $r = $this->criteria('jk1', 17.0, 'klas_5');

        $this->assertSame('prima',            $r['criteria_leeftijd'],  '17 jaar op jk1 → prima (scenario A)');
        $this->assertSame('prima',            $r['criteria_school'],    'klas_5 op jk1 → prima (scenario A)');
        $this->assertSame('criteriaprima',    $r['criteria_indicatie'], 'Prima leeftijd + prima school op jk1 → indicatie criteriaprima (scenario A)');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Perfecte match op jk1 → geen handmatig oordeel nodig (scenario A)');
    }

    public function testBK_LeeftijdPrimaSchoolPrima(): void {
        $r = $this->criteria('bk1', 13.0, 'groep_8');

        $this->assertSame('prima',         $r['criteria_leeftijd'],  '13 jaar op bk1 → prima (scenario A)');
        $this->assertSame('prima',         $r['criteria_school'],    'groep_8 op bk1 → prima (scenario A)');
        $this->assertSame('criteriaprima', $r['criteria_indicatie'], 'Prima leeftijd + prima school op bk1 → indicatie criteriaprima (scenario A)');
    }

    // ########################################################################
    // ### SCENARIO B: MARGE LEEFTIJD
    // ########################################################################

    public function testKK_MargeLeeftijdOnderkant(): void {
        // kk1 marge: 6.7–7.0
        $r = $this->criteria('kk1', 6.8, 'groep_5');

        $this->assertSame('marge',            $r['criteria_leeftijd'],  '6.8 op kk1 → marge (6.7–7.0)');
        $this->assertSame('prima',            $r['criteria_school'],    'groep_5 op kk1 → prima (scenario B)');
        $this->assertSame('binnenmarges',     $r['criteria_indicatie'], 'Marge leeftijd + prima school op kk1 → indicatie binnenmarges (scenario B)');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Marge wordt automatisch goedgekeurd');
    }

    public function testKK_MargeLeeftijdBovenkant(): void {
        // kk1 marge bovenkant: 12.0–12.3
        $r = $this->criteria('kk1', 12.2, 'groep_5');

        $this->assertSame('marge',            $r['criteria_leeftijd'],  '12.2 op kk1 → marge (12.0–12.3)');
        $this->assertSame('binnenmarges',     $r['criteria_indicatie'], 'Marge leeftijd op kk1 → indicatie binnenmarges (scenario B, bovenkant)');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Marge wordt automatisch goedgekeurd (scenario B, bovenkant)');
    }

    public function testJK_MargeLeeftijdOnderkant(): void {
        // jk1 marge: 15.7–16.0
        $r = $this->criteria('jk1', 15.8, 'klas_4');

        $this->assertSame('marge',        $r['criteria_leeftijd'],  '15.8 op jk1 → marge (15.7–16.0)');
        $this->assertSame('binnenmarges', $r['criteria_indicatie'], 'Marge leeftijd op jk1 → indicatie binnenmarges (scenario B, onderkant)');
    }

    public function testJK_MargeLeeftijdBovenkant(): void {
        // jk1 marge bovenkant: 18.0–18.3
        $r = $this->criteria('jk1', 18.2, 'klas_5');

        $this->assertSame('marge',        $r['criteria_leeftijd'],  '18.2 op jk1 → marge (18.0–18.3)');
        $this->assertSame('binnenmarges', $r['criteria_indicatie'], 'Marge leeftijd op jk1 → indicatie binnenmarges (scenario B, bovenkant)');
    }

    // ########################################################################
    // ### SCENARIO B2: MARGE SCHOOL
    // ########################################################################

    public function testTK_MargeSchoolKlas4(): void {
        // tk1: prima leeftijd, klas_4 is marge (klas_2/klas_3 = prima, klas_4 = marge)
        $r = $this->criteria('tk1', 15.0, 'klas_4');

        $this->assertSame('prima',            $r['criteria_leeftijd'],  '15 jaar op tk1 → prima (scenario B2)');
        $this->assertSame('marge',            $r['criteria_school'],    'klas_4 op tk1 → marge');
        $this->assertSame('binnenmarges',     $r['criteria_indicatie'], 'Marge school op tk1 → indicatie binnenmarges (scenario B2)');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Marge school wordt automatisch goedgekeurd (scenario B2)');
    }

    public function testJK_MargeSchoolKlas3(): void {
        // jk1: prima leeftijd, klas_3 is marge (klas_4/5/6/vervolg = prima, klas_2/3 = marge)
        $r = $this->criteria('jk1', 17.0, 'klas_3');

        $this->assertSame('marge',        $r['criteria_school'],    'klas_3 op jk1 → marge');
        $this->assertSame('binnenmarges', $r['criteria_indicatie'], 'Marge school op jk1 → indicatie binnenmarges (scenario B2)');
    }

    // ########################################################################
    // ### SCENARIO C: SCHOOL AFWIJKEND (LEEFTIJD PRIMA)
    // ########################################################################

    public function testKK_SchoolAfwijkend(): void {
        // groep_8 is afwijkend op kk1 (prima = groep_3 t/m groep_7)
        $r = $this->criteria('kk1', 9.0, 'groep_8');

        $this->assertSame('prima',           $r['criteria_leeftijd'],  '9 jaar op kk1 → prima (scenario C)');
        $this->assertSame('afwijkend',       $r['criteria_school'],    'groep_8 op kk1 → afwijkend');
        $this->assertSame('schoolwijktaf',   $r['criteria_indicatie'], 'Afwijkende school + prima leeftijd op kk1 → indicatie schoolwijktaf (scenario C)');
        $this->assertSame('oordeelnognodig', $r['criteria_oordeel'],   'Handmatig oordeel vereist');
    }

    public function testJK_SchoolAfwijkend(): void {
        // groep_8 is afwijkend op jk1 (klas_4+ prima, klas_2/3 marge, groep_8 afwijkend)
        $r = $this->criteria('jk1', 17.0, 'groep_8');

        $this->assertSame('prima',         $r['criteria_leeftijd'],  '17 jaar op jk1 → prima (scenario C)');
        $this->assertSame('afwijkend',     $r['criteria_school'],    'groep_8 op jk1 → afwijkend');
        $this->assertSame('schoolwijktaf', $r['criteria_indicatie'], 'Afwijkende school + prima leeftijd op jk1 → indicatie schoolwijktaf (scenario C)');
    }

    public function testBK_SchoolAfwijkend(): void {
        // klas_2 is afwijkend op bk1 (prima = groep_8 en klas_1)
        $r = $this->criteria('bk1', 13.0, 'klas_2');

        $this->assertSame('prima',         $r['criteria_leeftijd'],  '13 jaar op bk1 → prima (scenario C)');
        $this->assertSame('afwijkend',     $r['criteria_school'],    'klas_2 op bk1 → afwijkend');
        $this->assertSame('schoolwijktaf', $r['criteria_indicatie'], 'Afwijkende school + prima leeftijd op bk1 → indicatie schoolwijktaf (scenario C)');
    }

    // ########################################################################
    // ### SCENARIO D: LEEFTIJD AFWIJKEND (SCHOOL PRIMA)
    // ########################################################################

    public function testKK_LeeftijdAfwijkendTeOud(): void {
        // 13.0 jaar is te oud voor kk1 (max marge 12.3)
        $r = $this->criteria('kk1', 13.0, 'groep_5');

        $this->assertSame('afwijkend',        $r['criteria_leeftijd'],  '13.0 op kk1 → te oud (max marge 12.3)');
        $this->assertSame('prima',            $r['criteria_school'],    'groep_5 op kk1 → prima ondanks leeftijdsafwijking (scenario D)');
        $this->assertSame('leeftijdwijktaf',  $r['criteria_indicatie'], 'Afwijkende leeftijd + prima school op kk1 → indicatie leeftijdwijktaf (scenario D)');
        $this->assertSame('oordeelnognodig',  $r['criteria_oordeel'],   'Leeftijdsafwijking vereist handmatig oordeel (scenario D)');
    }

    public function testKK_LeeftijdAfwijkendTeJong(): void {
        // 6.5 jaar is te jong voor kk1 (min marge 6.7)
        $r = $this->criteria('kk1', 6.5, 'groep_4');

        $this->assertSame('afwijkend',       $r['criteria_leeftijd'],  '6.5 op kk1 → te jong (min marge 6.7)');
        $this->assertSame('prima',           $r['criteria_school'],    'groep_4 op kk1 → prima ondanks leeftijdsafwijking (scenario D)');
        $this->assertSame('leeftijdwijktaf', $r['criteria_indicatie'], 'Afwijkende leeftijd + prima school op kk1 → indicatie leeftijdwijktaf (scenario D)');
    }

    public function testJK_LeeftijdAfwijkendTeOud(): void {
        // 18.5 jaar is te oud voor jk1 (max marge 18.3)
        $r = $this->criteria('jk1', 18.5, 'klas_5');

        $this->assertSame('afwijkend',       $r['criteria_leeftijd'],  '18.5 op jk1 → te oud (max marge 18.3)');
        $this->assertSame('prima',           $r['criteria_school'],    'klas_5 op jk1 → prima ondanks leeftijdsafwijking (scenario D)');
        $this->assertSame('leeftijdwijktaf', $r['criteria_indicatie'], 'Afwijkende leeftijd + prima school op jk1 → indicatie leeftijdwijktaf (scenario D)');
    }

    public function testBK_LeeftijdAfwijkendTeJong(): void {
        // 10.5 jaar is te jong voor bk1 (min marge 11.3)
        $r = $this->criteria('bk1', 10.5, 'groep_8');

        $this->assertSame('afwijkend',       $r['criteria_leeftijd'],  '10.5 op bk1 → te jong (min marge 11.3)');
        $this->assertSame('prima',           $r['criteria_school'],    'groep_8 op bk1 → prima ondanks leeftijdsafwijking (scenario D)');
        $this->assertSame('leeftijdwijktaf', $r['criteria_indicatie'], 'Afwijkende leeftijd + prima school op bk1 → indicatie leeftijdwijktaf (scenario D)');
    }

    // ########################################################################
    // ### SCENARIO E: BEIDE AFWIJKEND
    // ########################################################################

    public function testKK_BeideAfwijkend(): void {
        // 5.0 jaar (te jong) + groep_8 (te oud voor kk)
        $r = $this->criteria('kk1', 5.0, 'groep_8');

        $this->assertSame('afwijkend',       $r['criteria_leeftijd'],  '5.0 op kk1 → te jong (scenario E)');
        $this->assertSame('afwijkend',       $r['criteria_school'],    'groep_8 op kk1 → afwijkend (scenario E)');
        $this->assertSame('criteriawijktaf', $r['criteria_indicatie'], 'Beide afwijkend op kk1 → indicatie criteriawijktaf (scenario E)');
        $this->assertSame('oordeelnognodig', $r['criteria_oordeel'],   'Beide afwijkend vereist handmatig oordeel (scenario E)');
    }

    public function testJK_BeideAfwijkend(): void {
        // 13.0 jaar (te jong) + groep_8 (niet prima/marge voor jk)
        $r = $this->criteria('jk1', 13.0, 'groep_8');

        $this->assertSame('afwijkend',       $r['criteria_leeftijd'],  '13.0 op jk1 → te jong (scenario E)');
        $this->assertSame('afwijkend',       $r['criteria_school'],    'groep_8 op jk1 → afwijkend (scenario E)');
        $this->assertSame('criteriawijktaf', $r['criteria_indicatie'], 'Beide afwijkend op jk1 → indicatie criteriawijktaf (scenario E)');
    }

    // ########################################################################
    // ### AUTO-CORRECTIE GROEP/KLAS
    // ########################################################################

    public function testKK_AutoCorrectiePrimaVolgensCorrectie(): void {
        // Ouder typt 'klas_5' (middelbaar) → motor corrigeert naar 'groep_5' (basisschool)
        $r = $this->criteria('kk1', 9.0, 'klas_5');

        $this->assertSame('groep_5',       $r['new_groepklas'],      'klas_5 op kk1 → gecorrigeerd naar groep_5');
        $this->assertSame('prima',         $r['criteria_school'],    'Na correctie is school prima');
        $this->assertSame('criteriaprima', $r['criteria_indicatie'], 'Na auto-correctie naar groep_5 → indicatie criteriaprima');
    }

    public function testTK_AutoCorrectieNaarKlas(): void {
        // Ouder typt 'groep_2' (basisschool) op tk → motor corrigeert naar 'klas_2'
        $r = $this->criteria('tk1', 15.0, 'groep_2');

        $this->assertSame('klas_2',    $r['new_groepklas'],   'groep_2 op tk1 → gecorrigeerd naar klas_2');
        $this->assertSame('prima',     $r['criteria_school'], 'Na correctie is school prima');
    }

    public function testJK_AutoCorrectieNaarKlas(): void {
        // Ouder typt 'groep_5' (basisschool) op jk → motor corrigeert naar 'klas_5'
        $r = $this->criteria('jk1', 17.0, 'groep_5');

        $this->assertSame('klas_5',        $r['new_groepklas'],   'groep_5 op jk1 → gecorrigeerd naar klas_5');
        $this->assertSame('prima',         $r['criteria_school'],    'Na correctie is school prima');
        $this->assertSame('criteriaprima', $r['criteria_indicatie'], 'Na auto-correctie naar klas_5 → indicatie criteriaprima');
    }

    // ########################################################################
    // ### HANDMATIG ADMIN-OORDEEL WORDT NOOIT OVERSCHREVEN
    // ########################################################################

    public function testAdminOordeelPrimaBlijftBijLeeftijdAfwijking(): void {
        // Admin heeft goedgekeurd; leeftijdsafwijking mag het oordeel niet terugzetten
        $r = $this->criteria('kk1', 13.0, 'groep_5', 'oordeelprima');

        $this->assertSame('oordeelprima',    $r['criteria_oordeel'],   'Admin-override mag niet gereset worden');
        $this->assertSame('leeftijdwijktaf', $r['criteria_indicatie'], 'Indicatie wordt wél correct berekend');
    }

    public function testAdminOordeelBuitencriteriaBlijftBijSchoolAfwijking(): void {
        $r = $this->criteria('kk1', 9.0, 'groep_8', 'buitencriteria');

        $this->assertSame('buitencriteria', $r['criteria_oordeel'],  'buitencriteria-oordeel mag niet worden gereset');
        $this->assertSame('schoolwijktaf',  $r['criteria_indicatie'], 'Indicatie wordt wél correct berekend als schoolwijktaf, ondanks vast admin-oordeel');
    }

    // ########################################################################
    // ### ONTBREKEND KAMPKORT → VEILIGE NEUTRALE UITKOMST
    // ########################################################################

    public function testGeenKampkortGeeftNeutralIndicatieNietWijktaf(): void {
        // Kampkort niet gesynct (afgebroken registratie / deadlock): mag NOOIT alarmerende mail sturen
        $deel = [
            'part_rol'       => 'deelnemer',
            'event_type_id'  => 14,
            'part_kampkort'  => '',     // leeg: nog niet gesynct
            'part_groepklas' => 'klas_5',
        ];
        $r = partstatus_criteria(0, $deel, 17.0);

        $this->assertSame('noggeenindicatie', $r['criteria_indicatie'], 'Neutraal: mag geen wijktaf-indicatie zijn');
        $this->assertSame('oordeelnognodig',  $r['criteria_oordeel'],   'Ontbrekend kampkort → oordeel blijft nognodig (veilige neutrale uitkomst)');
        $this->assertTrue(!empty($r['criteria_incompleet']),            'criteria_incompleet-vlag moet gezet zijn → forceert status 8 in consolidate');
        $this->assertNotContains($r['criteria_indicatie'], ['leeftijdwijktaf', 'schoolwijktaf', 'criteriawijktaf'], 'Ontbrekend kampkort mag nooit een alarmerende wijktaf-indicatie opleveren');
    }

    public function testEventFallbackVervangLegeKampkort(): void {
        // Lege part_kampkort + bekende kenmerken_kampkort → fallback op event-kampkort
        $deel = [
            'part_rol'           => 'deelnemer',
            'event_type_id'      => 14,
            'part_kampkort'      => '',
            'kenmerken_kampkort' => 'jk1',
            'part_groepklas'     => 'klas_5',
        ];
        $r = partstatus_criteria(0, $deel, 17.0);

        $this->assertSame('criteriaprima',    $r['criteria_indicatie'], 'Event-fallback werkt: 17j + klas_5 op jk1 → prima');
        $this->assertSame('oordeelnietnodig', $r['criteria_oordeel'],   'Event-fallback levert prima match op → geen handmatig oordeel nodig');
        $this->assertTrue(empty($r['criteria_incompleet']),              'kenmerken_kampkort-fallback voorkomt dat criteria_incompleet gezet wordt');
    }

    // ########################################################################
    // ########################################################################
    // ### SECTIE: CRITERIA AFWIJKEND + WACHTLIJST
    // ########################################################################
    // ########################################################################

    /**
     * Wanneer een kamp vol is én de criteria niet kloppen, moeten beide
     * tegelijk verwerkt worden. Deze sectie test de combinatie en welke
     * mails er in elke fase worden gestuurd.
     *
     * Fase-overzicht:
     *
     *   Fase 1 — Aanmelding op volle kamp + afwijkende criteria:
     *     status 7 → 33 (Wachtlijst + Criteria) via Regel 33
     *     Mail: NOTIFICATIE aanmelding WACHTLIJST + CRITERIA (nieuwe rules, status=33)
     *           Geen betaalmail — oordeel moet eerst gegeven worden
     *
     *   Fase 2a — Plek vrijgekomen, oordeel nog open (wl_eraf gezet, geen paylink):
     *     status 33 → 8 (Afwachting oordeel) via Regel D
     *     Geen betaalmail — admin moet eerst oordelen
     *     Na positief oordeel: status 8 → 9 → betaalmail → 1
     *
     *   Fase 2b — Plek vrijgekomen, direct met betaling (wl_eraf gezet + paylink):
     *     status 33 → 1 (Geregistreerd) via Regel D
     *     Mail: NOTIFICATIE wachtlijst bevestigd (rules 210–218, transitie 9→1)
     *
     * Aanvullend (via Regel 33b):
     *   Admin keurt goed terwijl er nog geen plek is → status 33 → 7 (normale wachtlijst)
     *   Zodra plek vrijkomt → normale Regel D: status 7 → 9 → betaalmail → 1
     *
     * CiviRules-structuur voor deze flows is gedekt in CiviRulesCriteriaTest (279–288)
     * en CiviRulesWachtlijstBevestigdTest (210–218).
     */

    // ########################################################################
    // ### FASE 1: Aanmelding op volle kamp — status 33 (Wachtlijst + Criteria)
    // ########################################################################

    /**
     * Deelnemer meldt zich aan op een vol kamp (status 7) met een leeftijdsafwijking.
     * De wachtlijst-motor zet de status op 33 (Wachtlijst + Criteria): de plek kan
     * pas vrijgegeven worden nadat het criteria-oordeel is gegeven.
     * Geen betaalmail in deze fase.
     */
    public function testWachtlijstEnLeeftijdAfwijkend_Status33(): void {
        $criteria = $this->criteria('kk1', 13.0, 'groep_5'); // leeftijd te oud → leeftijdwijktaf

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 7,
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => NULL,
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
            'criteria_oordeel'        => NULL,
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(33,                $wl['status_id'],
            'Status 7 + leeftijdwijktaf + oordeel=nognodig → status 33 (Wachtlijst + Criteria).');
        $this->assertSame('leeftijdwijktaf', $criteria['criteria_indicatie'],
            'Criteria-motor berekent de afwijking correct.');
        $this->assertSame('oordeelnognodig', $criteria['criteria_oordeel'],
            'Handmatig oordeel vereist — mag niet als betaald worden behandeld.');
        $this->assertNotEmpty($wl['wl_erop'], 'wl_erop moet gevuld zijn (Regel C geldt ook voor 33).');
    }

    /**
     * Deelnemer op wachtlijst met afwijkende school → status 33.
     */
    public function testWachtlijstEnSchoolAfwijkend_Status33(): void {
        $criteria = $this->criteria('kk1', 9.0, 'groep_8'); // school afwijkend → schoolwijktaf

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 7,
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => NULL,
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(33,               $wl['status_id'],          'Status 7 + schoolwijktaf → status 33 (Wachtlijst + Criteria, fase 1)');
        $this->assertSame('schoolwijktaf',  $criteria['criteria_indicatie'], 'Criteria-motor berekent schoolwijktaf correct voor deze wachtlijst-deelnemer');
    }

    /**
     * Deelnemer op wachtlijst met BEIDE afwijkend → status 33.
     */
    public function testWachtlijstEnBeideAfwijkend_Status33(): void {
        $criteria = $this->criteria('kk1', 5.0, 'groep_8'); // beide afwijkend → criteriawijktaf

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 7,
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => NULL,
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(33,                $wl['status_id'],          'Status 7 + criteriawijktaf → status 33 (Wachtlijst + Criteria, fase 1)');
        $this->assertSame('criteriawijktaf', $criteria['criteria_indicatie'], 'Criteria-motor berekent criteriawijktaf correct (beide afwijkend)');
        $this->assertSame('oordeelnognodig', $criteria['criteria_oordeel'],   'Beide afwijkend vereist handmatig oordeel');
    }

    /**
     * Status 33: admin keurt goed (oordeel=prima) terwijl er nog geen plek is.
     * Motor zet status terug naar normale wachtlijst (7) zodat Regel D kan doorstromen
     * zodra een plek vrijkomt.
     */
    public function testStatus33_AdminKeurGoed_StatusTerug7(): void {
        $criteria = $this->criteria('kk1', 13.0, 'groep_5', 'oordeelprima'); // admin goedkeuring

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 33,
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => NULL,   // nog geen plek
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(7, $wl['status_id'],
            'Status 33 + oordeel prima + geen plek → terug naar normale wachtlijst (7) via Regel 33b.');
    }

    /**
     * wl_erop-datum ontbreekt bij status 33: motor vult automatisch de register_date in.
     * (Regel C van partstatus_evaluate_wachtlijst geldt ook voor status 33.)
     */
    public function testWachtlijstZonderEropDatum_WordtAutoGevuld(): void {
        $criteria = $this->criteria('kk1', 13.0, 'groep_5');

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 7,
            'wachtlijst_erop'         => NULL,   // bewust leeg
            'wachtlijst_eraf'         => NULL,
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        // Status wordt 33 (leeftijdwijktaf + nognodig), maar wl_erop is wél gevuld door Regel C
        $this->assertSame(33,                     $wl['status_id'],   'Ontbrekende wl_erop-datum verandert niets aan de status-berekening (blijft 33)');
        $this->assertSame('2026-03-01 10:00:00',  $wl['wl_erop'],
            'wl_erop moet automatisch gevuld worden met register_date (Regel C geldt voor 7 én 33).');
    }

    // ########################################################################
    // ### FASE 2a: Plek vrijgekomen, oordeel nog open → status 8 (Afwachting oordeel)
    // ########################################################################

    /**
     * HL zet wl_eraf: deelnemer heeft een plek maar het criteria-oordeel is nog open.
     *   status 33 → 8 (Afwachting oordeel)
     *   Pas na positief oordeel promoveert de motor naar status 9 → betaalmail.
     *   CiviRule 279–288 (buiten CRITERIA) mag NIET vieren op status 8.
     */
    public function testPlekVrijgekomenZonderPaylink_Status8_OordeelNogOpen(): void {
        $criteria = $this->criteria('kk1', 13.0, 'groep_5'); // leeftijdwijktaf + oordeel=nognodig

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 33,            // was 33: wachtlijst + criteria
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => '2026-05-01',  // HL heeft een plek gegeven
            'part_kampgeld_contribid' => 0,             // nog geen betaling aangemaakt
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(8, $wl['status_id'],
            'Status 33 + wl_eraf + oordeel nog open → status 8 (Afwachting oordeel, betaalmail wacht).');

        // Criteria-velden bevestigen de afwijking:
        $this->assertSame('leeftijdwijktaf', $criteria['criteria_indicatie'], 'Criteria-motor bevestigt leeftijdwijktaf in fase 2a');
        $this->assertSame('oordeelnognodig', $criteria['criteria_oordeel'],
            'CiviRule 279–288 mag nog niet vieren: die vereist status 9, niet 8.');
    }

    /**
     * Variant: school afwijkend op jk1, plek vrijgekomen zonder betaling → status 8.
     */
    public function testPlekVrijgekomenSchoolAfwijkend_Status8(): void {
        $criteria = $this->criteria('jk1', 17.0, 'groep_8'); // schoolwijktaf

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 33,
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => '2026-05-01',
            'part_kampgeld_contribid' => 0,
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(8,               $wl['status_id'],           'Status 33 + wl_eraf + schoolwijktaf + oordeel nog open → status 8 (fase 2a, variant jk1)');
        $this->assertSame('schoolwijktaf', $criteria['criteria_indicatie'], 'Criteria-motor bevestigt schoolwijktaf voor deze jk1-deelnemer');
        $this->assertSame('oordeelnognodig', $criteria['criteria_oordeel'], 'Oordeel blijft nognodig zolang admin niet heeft beoordeeld');
    }

    // ########################################################################
    // ### FASE 2b: Plek vrijgekomen + direct betaling, criteria nog open → status 8
    // ########################################################################

    /**
     * Dezelfde afwijkende deelnemer (bk1, klas_3, 13 jaar) krijgt een plek én er is
     * al een paylink (back-office heeft contributie aangemaakt) — maar het criteria-
     * oordeel is nog niet gegeven.
     *
     * Regel D's criteria-poort (partstatus.wachtlijst.php) gaat bewust VÓÓR de paylink-
     * check: geschiktheid moet eerst beoordeeld zijn, ook als een back-office-medewerker
     * al een betaallink heeft aangemaakt. Een paylink is geen oordeel over de criteria.
     * Zonder dat oordeel (geen criteriacheck_einde, geen positief oordeel) blijft de
     * deelnemer dus op status 8 (Afwachting Oordeel) hangen, ook mét paylink — identiek
     * aan fase 2a zonder paylink (testPlekVrijgekomenSchoolAfwijkend_Status8). Pas zodra
     * een beheerder het oordeel geeft (of criteriacheck_einde zet), pakt Regel B de
     * doorstroom naar 9 op; vandaar promoveert de aanwezige paylink alsnog naar 1.
     *
     * CiviRule 279–288 (buiten CRITERIA) vereist status=9; die conditie matcht dus nooit
     * — status 9 wordt hier niet bereikt zolang het oordeel ontbreekt.
     */
    public function testPlekVrijgekomenMetPaylinkMaarGeenOordeel_Status8(): void {
        // Dezelfde deelnemer als fase 1 en 2a: bk1, 13 jaar, klas_3 → schoolwijktaf
        $criteria = $this->criteria('bk1', 13.0, 'klas_3');

        $this->assertSame('schoolwijktaf',   $criteria['criteria_indicatie'],
            'Criteria zijn afwijkend — klas_3 is te hoog voor bk1 (prima = groep_8/klas_1).');
        $this->assertSame('oordeelnognodig', $criteria['criteria_oordeel'], 'Schoolafwijking vereist handmatig oordeel vóórdat paylink kan doorstromen (fase 2b)');

        $deel = [
            'part_rol'                => 'deelnemer',
            'status_id'               => 33,            // wachtlijst + criteria
            'wachtlijst_erop'         => '2026-03-01',
            'wachtlijst_eraf'         => '2026-05-01',  // plek vrijgekomen
            'part_kampgeld_contribid' => 14999,         // paylink direct aangemaakt via back-office
            'register_date'           => '2026-03-01 10:00:00',
        ];
        $wl = partstatus_evaluate_wachtlijst(0, $deel, $criteria);

        $this->assertSame(8, $wl['status_id'],
            'Status 33 + wl_eraf + paylink, maar criteria-oordeel nog open → status 8 (Beoordeling Nodig): '
            . 'geschiktheid gaat vóór betaling, ook als er al een paylink is.');

        // Zonder oordeel wordt status 9 niet bereikt, dus de criteria-mail (279–288) vuurt niet.
        $this->assertNotSame(9, $wl['status_id'],
            'Status mag niet 9 zijn zonder afgerond oordeel — de criteria-mail (279–288) mag dan niet vieren.');
    }

    // ########################################################################
    // ### CiviRules-structuur: NOTIFICATIE wachtlijst bevestigd (210–218)
    // ########################################################################

    /**
     * Rules 210–218 sturen de bevestigd-mail bij de transitie 9→1.
     * Ze controleren of de VORIGE status 9 was (via de previous-status-conditie).
     *
     * Structuurcheck: rule 210 (KK1) als representatief voorbeeld.
     *   - Eén conditie op Participant.status_id = 1 (huidige status)
     *   - Eén conditie op vorige status = 9 (previous-status CiviRule-conditie)
     *   - is_test = 0
     */
    public function testWachtlijstBevestigdRuleHeeftStatusEnVorigeStatusConditie(): void {
        $sql = "SELECT condition_params FROM civirule_rule_condition WHERE rule_id = 210";
        $dao = \CRM_Core_DAO::executeQuery($sql);

        $condities = [];
        while ($dao->fetch()) {
            $condities[] = unserialize($dao->condition_params);
        }

        // Zoek de Participant.status_id conditie (huidige status = 1)
        $statusCond = NULL;
        foreach ($condities as $c) {
            if (($c['entity'] ?? '') === 'Participant' && ($c['field'] ?? '') === 'status_id') {
                $statusCond = $c;
                break;
            }
        }
        $this->assertNotNull($statusCond, 'Rule 210 moet een Participant.status_id conditie hebben.');
        $huidigeStatussen = array_merge([$statusCond['value'] ?? ''], (array)($statusCond['multi_value'] ?? []));
        $this->assertContains('1', $huidigeStatussen,
            'Rule 210 moet vieren op status 1 (Geregistreerd = na betaling vanaf wachtlijst).');

        // Zoek de vorige-status conditie (previous_status_id = 9, via geserialiseerd legacy-formaat)
        $previousCond = NULL;
        foreach ($condities as $c) {
            if (isset($c['original_status_id']) || isset($c['new_status_id'])) {
                $previousCond = $c;
                break;
            }
        }
        $this->assertNotNull($previousCond,
            'Rule 210 moet een "vorige status"-conditie hebben (transitie van 9 naar 1).');
        $vorigeStatus = $previousCond['original_status_id'] ?? $previousCond['value'] ?? '';
        $this->assertSame('9', (string) $vorigeStatus,
            'Vorige status moet 9 (Afwachting betaling) zijn — de mail is alléén voor wachtlijst-doorstromers.');
    }

    /**
     * Bevestigd-rule (210) check: is_test = 0 (geen testregistraties).
     */
    public function testWachtlijstBevestigdRuleIsTestNul(): void {
        $sql = "SELECT condition_params FROM civirule_rule_condition WHERE rule_id = 210";
        $dao = \CRM_Core_DAO::executeQuery($sql);

        $isTestCond = NULL;
        while ($dao->fetch()) {
            $c = unserialize($dao->condition_params);
            if (($c['field'] ?? '') === 'is_test') {
                $isTestCond = $c;
                break;
            }
        }
        $this->assertNotNull($isTestCond, 'Rule 210 moet een is_test conditie hebben.');
        $this->assertSame('0', (string)($isTestCond['value'] ?? ''),
            'is_test moet 0 zijn — bevestigd-mail niet naar testregistraties.');
    }

}
