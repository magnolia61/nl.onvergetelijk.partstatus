<?php

namespace Civi\Partstatus;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Structuurtest voor CiviRules rondom criteria-afwijkingen ("buiten CRITERIA").
 *
 * @group e2e
 *
 * =======================================================================================
 * ACHTERGROND — CONSOLIDATIE 2026-07-11 (per-kamp → uniform)
 * =======================================================================================
 * Oorspronkelijk was er per kamp één "NOTIFICATIE buiten CRITERIA deel [kamp]"-rule:
 *
 *     279=KK1  281=KK2  282=BK1  283=BK2  284=TK1  285=TK2  286=JK1  287=JK2  288=TOP
 *
 * Deze zijn op 2026-07-11 13:07:12 (user 27, Richard) BEWUST gedeactiveerd als onderdeel
 * van de bredere CiviRules→"uniform"-consolidatie (~117 rules → ~15). Op exact hetzelfde
 * moment (13:06:43) is één vervangende rule aangemaakt:
 *
 *     602  buiten_criteria_deel_uniform   (trigger 58 = new_participant)
 *
 * Rule 602 is de functionele superset van 279–287:
 *   - Actie 51  participant_update_status → status 8 (Afwachting oordeel)      [identiek]
 *   - Actie 137 emailapi_send → template 500 (gezin)                          [identiek aan 279]
 *   - Actie 196 civirulescc_relatedcontact_renderoriginal → template 502      [notif_kamp,
 *               nu via het render-original mechanisme i.p.v. per-kamp alt-receiver]
 *   - Eén event_type_id-conditie die ALLE reguliere kampen dekt: 11,12,13,14,21,22,23,24
 *   - Extra guard t.o.v. de oude rules: Event.start_date >= today
 *   - Status-operator "1" (gelijk aan) → dit REPAREERT de oude bug in rule 284 (TK1),
 *     die operator "0" (ongelijk) gebruikte.
 *
 * TOP (rule 288, event_type 33) valt BUITEN de consolidatie: 602 dekt event_type 33 niet,
 * daarom is rule 288 met opzet actief gebleven en handelt TOP nog steeds zelfstandig af.
 *
 * Deze test valideert dus de HUIDIGE realiteit:
 *   1) De 8 oude per-kamp-rules (279–287) staan bewust op is_active=0.
 *   2) De TOP-rule (288) is nog actief (aparte afhandeling).
 *   3) De uniforme rule (602) is actief en heeft de juiste condities (voorheen B–G per kamp).
 *
 * Zet de oude rules NIET opnieuw aan: samen met 602 zouden ze dubbele status-updates en
 * dubbele mails veroorzaken.
 *
 * Veldnummers:
 *   custom_1428 = PART_DEEL_INTERN.criteria_indicatie
 *   custom_1429 = PART_DEEL_INTERN.criteria_oordeel
 *   custom_2082 = DITJAAR.ditjaar_criteria_indicatie (Contact-entiteit)
 */
class CiviRulesCriteriaTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

    /** De uniforme rule die op 2026-07-11 de per-kamp-rules verving. */
    private const UNIFORM_RULE = 602;

    /** De per-kamp-rules die bewust zijn gedeactiveerd (vervangen door 602). */
    private const LEGACY_RULES_UIT = [279, 281, 282, 283, 284, 285, 286, 287];

    /** TOP valt buiten de consolidatie en blijft zelfstandig actief. */
    private const TOP_RULE = 288;

    /** Reguliere kamp-event_types die de uniforme rule moet dekken. */
    private const KAMP_EVENT_TYPES = [11, 12, 13, 14, 21, 22, 23, 24];

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
    // ### A: Consolidatie-staat — oude rules uit, TOP + uniform actief
    // ########################################################################

    /**
     * De 8 per-kamp-rules moeten NA de consolidatie van 2026-07-11 uit staan.
     * Ze zijn vervangen door rule 602. Weer aanzetten = dubbele mails/statusupdates.
     *
     * @dataProvider legacyRuleProvider
     */
    public function testOudeCriteriaRuleGedeactiveerd(int $ruleId): void {
        $this->assertFalse(
            $this->ruleIsActive($ruleId),
            "CiviRule $ruleId (oude per-kamp 'buiten CRITERIA') moet INACTIEF zijn: bewust " .
            "gedeactiveerd op 2026-07-11 en vervangen door de uniforme rule " . self::UNIFORM_RULE . "."
        );
    }

    /** TOP valt buiten de consolidatie en moet nog steeds actief zijn. */
    public function testTopCriteriaRuleNogActief(): void {
        $this->assertTrue(
            $this->ruleIsActive(self::TOP_RULE),
            "CiviRule " . self::TOP_RULE . " (TOP, event_type 33) valt buiten de uniforme rule " .
            self::UNIFORM_RULE . " en moet zelfstandig actief blijven."
        );
    }

    /** De uniforme vervanger moet actief zijn. */
    public function testUniformeRuleActief(): void {
        $this->assertTrue(
            $this->ruleIsActive(self::UNIFORM_RULE),
            "CiviRule " . self::UNIFORM_RULE . " (buiten_criteria_deel_uniform) moet actief zijn — " .
            "dit is de vervanger van de oude per-kamp-rules."
        );
    }

    // ########################################################################
    // ### B: Indicatie-conditie (PART custom_1428)
    // ########################################################################

    public function testUniformeRuleHeeftIndicatieConditie(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond = $this->findCondition($conditions, 'Participant', self::CUSTOM_INDICATIE_PART);

        $this->assertNotNull($cond,
            "Rule " . self::UNIFORM_RULE . " moet een Participant.custom_1428 (criteria_indicatie) conditie hebben."
        );

        $smileys = (array) ($cond['multi_value'] ?? []);
        foreach (self::INDICATIE_AFWIJKEND as $val) {
            $this->assertContains($val, $smileys,
                "Rule " . self::UNIFORM_RULE . ": indicatie '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### C: Oordeel-conditie (PART custom_1429)
    // ########################################################################

    public function testUniformeRuleHeeftOordeelConditie(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond = $this->findCondition($conditions, 'Participant', self::CUSTOM_OORDEEL_PART);

        $this->assertNotNull($cond,
            "Rule " . self::UNIFORM_RULE . " moet een Participant.custom_1429 (criteria_oordeel) conditie hebben."
        );

        $values = (array) ($cond['multi_value'] ?? []);
        foreach (self::OORDEEL_HANDMATIG as $val) {
            $this->assertContains($val, $values,
                "Rule " . self::UNIFORM_RULE . ": oordeel '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### D: Status-conditie = 9, operator "1" (gelijk aan)
    // ########################################################################

    public function testUniformeRuleStatusIs9(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond       = $this->findStatusCondition($conditions);

        $this->assertNotNull($cond, "Rule " . self::UNIFORM_RULE . " moet een status_id-conditie hebben.");
        $this->assertContains('9', (array) ($cond['status_id'] ?? []),
            "Rule " . self::UNIFORM_RULE . ": status_id moet 9 (Afwachting betaling) bevatten."
        );
    }

    /**
     * Status-conditie moet operator "1" (= gelijk aan) gebruiken, NIET "0" (= ongelijk).
     *
     * Historie: de oude TK1-rule 284 had per abuis operator "0". De consolidatie naar de
     * uniforme rule 602 heeft dit gerepareerd — 602 hoort operator "1" te gebruiken.
     */
    public function testUniformeRuleStatusOperatorGelijkAan(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond       = $this->findStatusCondition($conditions);

        $this->assertNotNull($cond, "Rule " . self::UNIFORM_RULE . " moet een status_id-conditie hebben.");
        $this->assertSame('1', (string) ($cond['operator'] ?? ''),
            "Rule " . self::UNIFORM_RULE . ": status_id operator moet '1' (gelijk aan) zijn, niet '0' (ongelijk). " .
            "De uniforme rule repareert de oude operator-bug van rule 284."
        );
    }

    // ########################################################################
    // ### E: DITJAAR-indicatie-conditie (Contact custom_2082)
    // ########################################################################

    public function testUniformeRuleHeeftDitjaarIndicatieConditie(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond = $this->findCondition($conditions, 'Contact', self::CUSTOM_INDICATIE_DITJAAR);

        $this->assertNotNull($cond,
            "Rule " . self::UNIFORM_RULE . " moet ook een Contact.custom_2082 (ditjaar_criteria_indicatie) conditie hebben."
        );

        $values = (array) ($cond['multi_value'] ?? []);
        foreach (self::INDICATIE_AFWIJKEND as $val) {
            $this->assertContains($val, $values,
                "Rule " . self::UNIFORM_RULE . ": DITJAAR-indicatie '$val' moet in de conditie staan."
            );
        }
    }

    // ########################################################################
    // ### F: is_test = 0
    // ########################################################################

    public function testUniformeRuleIsTestNul(): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond = $this->findCondition($conditions, 'Participant', 'is_test');

        $this->assertNotNull($cond, "Rule " . self::UNIFORM_RULE . " moet een is_test-conditie hebben.");
        $this->assertSame('0', (string) ($cond['value'] ?? ''),
            "Rule " . self::UNIFORM_RULE . ": is_test moet 0 zijn (geen testregistraties)."
        );
    }

    // ########################################################################
    // ### G: Event type — één rule dekt ALLE reguliere kampen
    // ########################################################################

    /**
     * De kracht van de consolidatie: waar vroeger 8 rules elk één event_type checkten,
     * dekt de uniforme rule alle reguliere kampen in één event_type_id-conditie.
     *
     * @dataProvider kampEventTypeProvider
     */
    public function testUniformeRuleDektKampEventType(int $eventTypeId): void {
        $conditions = $this->ruleConditions(self::UNIFORM_RULE);
        $cond = $this->findCondition($conditions, 'Event', 'event_type_id');

        $this->assertNotNull($cond, "Rule " . self::UNIFORM_RULE . " moet een Event.event_type_id-conditie hebben.");

        $values = array_merge(
            [$cond['value'] ?? ''],
            (array) ($cond['multi_value'] ?? [])
        );
        $this->assertContains(
            (string) $eventTypeId,
            $values,
            "Rule " . self::UNIFORM_RULE . ": event_type_id $eventTypeId moet in de conditie staan " .
            "(uniforme rule dekt alle reguliere kampen)."
        );
    }

    // ########################################################################
    // ### DataProviders
    // ########################################################################

    public function legacyRuleProvider(): array {
        $cases = [];
        foreach (self::LEGACY_RULES_UIT as $ruleId) {
            $cases["rule_$ruleId"] = [$ruleId];
        }
        return $cases;
    }

    public function kampEventTypeProvider(): array {
        $cases = [];
        foreach (self::KAMP_EVENT_TYPES as $eventTypeId) {
            $cases["event_type_$eventTypeId"] = [$eventTypeId];
        }
        return $cases;
    }

}
