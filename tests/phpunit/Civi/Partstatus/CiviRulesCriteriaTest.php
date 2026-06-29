<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Structuurtest voor CiviRules rondom criteria-afwijkingen ("buiten CRITERIA").
 *
 * @group e2e
 *
 * Controleert de database-structuur van CiviRules 279–288
 * (NOTIFICATIE buiten CRITERIA deel KK1/KK2/BK1/BK2/TK1/TK2/JK1/JK2/TOP):
 *
 *   A) Elke campkort-rule is actief.
 *
 *   B) Indicatie-conditie: custom_1428 (PART criteria_indicatie) is één van
 *      criteriawijktaf / schoolwijktaf / leeftijdwijktaf.
 *
 *   C) Oordeel-conditie: custom_1429 (PART criteria_oordeel) is één van
 *      oordeelnognodig / oordeelbuitencriteria.
 *
 *   D) Status-conditie: status_id = 9 (Afwachting betaling), operator "1" (= gelijk aan).
 *      LET OP: rule 284 (TK1) heeft operator "0" (= NIET gelijk aan) — dat is een bug!
 *      Deze test vangt die bug expliciet op.
 *
 *   E) DITJAAR-conditie: custom_2082 (Contact ditjaar_criteria_indicatie) is één van
 *      dezelfde wijkt-af-waarden (dubbele check: PART én Contact moeten matchen).
 *
 *   F) is_test-conditie = 0 (geen testregistraties).
 *
 *   G) Event type klopt per kamp:
 *      279=KK1→11, 281=KK2→21, 282=BK1→12, 283=BK2→22, 284=TK1→13,
 *      285=TK2→23, 286=JK1→14, 287=JK2→24, 288=TOP→33
 *
 * Veldnummers:
 *   custom_1428 = PART_DEEL_INTERN.criteria_indicatie
 *   custom_1429 = PART_DEEL_INTERN.criteria_oordeel
 *   custom_2082 = DITJAAR.ditjaar_criteria_indicatie (Contact-entiteit)
 */
class CiviRulesCriteriaTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

    private const CRITERIA_RULES = [
        279 => 11,  // KK1
        281 => 21,  // KK2
        282 => 12,  // BK1
        283 => 22,  // BK2
        284 => 13,  // TK1  ← operator-bug: status_id "0" i.p.v. "1"
        285 => 23,  // TK2
        286 => 14,  // JK1
        287 => 24,  // JK2
        288 => 33,  // TOP
    ];

    private const INDICATIE_AFWIJKEND = ['criteriawijktaf', 'schoolwijktaf', 'leeftijdwijktaf'];
    private const OORDEEL_HANDMATIG   = ['oordeelnognodig', 'oordeelbuitencriteria'];

    private const CUSTOM_INDICATIE_PART    = 'custom_1428';
    private const CUSTOM_OORDEEL_PART      = 'custom_1429';
    private const CUSTOM_INDICATIE_DITJAAR = 'custom_2082';

    // ########################################################################
    // ### Helpers
    // ########################################################################

    private function ruleIsActive(int $ruleId): bool {
        $sql    = "SELECT is_active FROM civirule_rule WHERE id = %1";
        $result = \CRM_Core_DAO::singleValueQuery($sql, [1 => [$ruleId, 'Integer']]);
        return (bool) $result;
    }

    /** @return array[] */
    private function ruleConditions(int $ruleId): array {
        $sql = "SELECT condition_params FROM civirule_rule_condition WHERE rule_id = %1";
        $dao = \CRM_Core_DAO::executeQuery($sql, [1 => [$ruleId, 'Integer']]);
        $out = [];
        while ($dao->fetch()) {
            $out[] = unserialize($dao->condition_params);
        }
        return $out;
    }

    /** Zoek een conditie op entity + field. */
    private function findCondition(array $conditions, string $entity, string $field): ?array {
        foreach ($conditions as $c) {
            if (($c['entity'] ?? '') === $entity && ($c['field'] ?? '') === $field) {
                return $c;
            }
        }
        return NULL;
    }

    /** Zoek een status_id-conditie via het legacy (geserialiseerd) formaat. */
    private function findStatusCondition(array $conditions): ?array {
        foreach ($conditions as $c) {
            if (isset($c['status_id']) && isset($c['operator'])) {
                return $c;
            }
        }
        return NULL;
    }

    // ########################################################################
    // ### A: Alle buiten-criteria-rules actief
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaRuleActief(int $ruleId, int $eventTypeId): void {
        $this->assertTrue(
            $this->ruleIsActive($ruleId),
            "CiviRule $ruleId (event_type $eventTypeId) — NOTIFICATIE buiten CRITERIA — moet actief zijn."
        );
    }

    // ########################################################################
    // ### B: Indicatie-conditie (PART custom_1428)
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaHeeftIndicatieConditie(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond = $this->findCondition($conditions, 'Participant', self::CUSTOM_INDICATIE_PART);

        $this->assertNotNull($cond,
            "Rule $ruleId moet een Participant.custom_1428 (criteria_indicatie) conditie hebben."
        );

        $smileys = (array) ($cond['multi_value'] ?? []);
        foreach (self::INDICATIE_AFWIJKEND as $val) {
            $this->assertContains($val, $smileys,
                "Rule $ruleId: indicatie '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### C: Oordeel-conditie (PART custom_1429)
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaHeeftOordeelConditie(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond = $this->findCondition($conditions, 'Participant', self::CUSTOM_OORDEEL_PART);

        $this->assertNotNull($cond,
            "Rule $ruleId moet een Participant.custom_1429 (criteria_oordeel) conditie hebben."
        );

        $values = (array) ($cond['multi_value'] ?? []);
        foreach (self::OORDEEL_HANDMATIG as $val) {
            $this->assertContains($val, $values,
                "Rule $ruleId: oordeel '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### D: Status-conditie = 9, operator "1" (gelijk aan)
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaStatusIs9(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond       = $this->findStatusCondition($conditions);

        $this->assertNotNull($cond, "Rule $ruleId moet een status_id-conditie hebben.");
        $this->assertContains('9', (array) ($cond['status_id'] ?? []),
            "Rule $ruleId: status_id moet 9 (Afwachting betaling) bevatten."
        );
    }

    /**
     * Status-conditie moet operator "1" (= gelijk aan) gebruiken, NIET "0" (= ongelijk).
     *
     * Bekende bug: rule 284 (TK1) heeft operator "0" i.p.v. "1". Die test FAALT en signaleert de bug.
     *
     * @dataProvider criteriaRuleProvider
     */
    public function testBuitenCriteriaStatusOperatorGelijkAan(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond       = $this->findStatusCondition($conditions);

        $this->assertNotNull($cond, "Rule $ruleId moet een status_id-conditie hebben.");
        $this->assertSame('1', (string) ($cond['operator'] ?? ''),
            "Rule $ruleId (event_type $eventTypeId): status_id operator moet '1' (gelijk aan) zijn, niet '0' (ongelijk)." .
            ($ruleId === 284 ? " [BEKENDE BUG in TK1-rule 284 — operator staat op 0 (ongelijk)]" : "")
        );
    }

    // ########################################################################
    // ### E: DITJAAR-indicatie-conditie (Contact custom_2082)
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaHeeftDitjaarIndicatieConditie(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond = $this->findCondition($conditions, 'Contact', self::CUSTOM_INDICATIE_DITJAAR);

        $this->assertNotNull($cond,
            "Rule $ruleId moet ook een Contact.custom_2082 (ditjaar_criteria_indicatie) conditie hebben."
        );

        $values = (array) ($cond['multi_value'] ?? []);
        foreach (self::INDICATIE_AFWIJKEND as $val) {
            $this->assertContains($val, $values,
                "Rule $ruleId: DITJAAR-indicatie '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### F: is_test = 0
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaIsTestNul(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond = $this->findCondition($conditions, 'Participant', 'is_test');

        $this->assertNotNull($cond, "Rule $ruleId moet een is_test-conditie hebben.");
        $this->assertSame('0', (string) ($cond['value'] ?? ''),
            "Rule $ruleId: is_test moet 0 zijn (geen testregistraties)."
        );
    }

    // ########################################################################
    // ### G: Event type per kamp
    // ########################################################################

    /** @dataProvider criteriaRuleProvider */
    public function testBuitenCriteriaEventTypeKlopt(int $ruleId, int $eventTypeId): void {
        $conditions = $this->ruleConditions($ruleId);
        $cond = $this->findCondition($conditions, 'Event', 'event_type_id');

        $this->assertNotNull($cond, "Rule $ruleId moet een Event.event_type_id-conditie hebben.");

        $values = array_merge(
            [$cond['value'] ?? ''],
            (array) ($cond['multi_value'] ?? [])
        );
        $this->assertContains(
            (string) $eventTypeId,
            $values,
            "Rule $ruleId: event_type_id $eventTypeId moet in de conditie staan."
        );
    }

    // ########################################################################
    // ### Symmetrie: alle regels bevatten dezelfde indicatie-waarden
    // ########################################################################

    public function testAlleRulesHebbenDezelfdeIndicatieWaarden(): void {
        $vorige = NULL;
        foreach (array_keys(self::CRITERIA_RULES) as $ruleId) {
            $conditions = $this->ruleConditions($ruleId);
            $cond       = $this->findCondition($conditions, 'Participant', self::CUSTOM_INDICATIE_PART);
            $values     = (array) ($cond['multi_value'] ?? []);
            sort($values);

            if ($vorige !== NULL) {
                $this->assertSame($vorige, $values,
                    "Rule $ruleId heeft andere indicatie-waarden dan de vorige rule — alle buiten-criteria-rules moeten gelijk zijn."
                );
            }
            $vorige = $values;
        }
    }

    // ########################################################################
    // ### DataProvider
    // ########################################################################

    public function criteriaRuleProvider(): array {
        $cases = [];
        foreach (self::CRITERIA_RULES as $ruleId => $eventTypeId) {
            $cases["rule_$ruleId"] = [$ruleId, $eventTypeId];
        }
        return $cases;
    }

}
