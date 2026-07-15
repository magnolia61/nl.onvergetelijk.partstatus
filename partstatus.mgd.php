<?php

use CRM_Partstatus_ExtensionUtil as E;

/**
 * =======================================================================================
 * MANAGED ENTITY: eigen activity type voor partstatus-statuswijzigingen
 * =======================================================================================
 *
 * FUNCTIONEEL:
 * partstatus logt elke deelnemer-statuswijziging als een CiviCRM-activiteit
 * (partstatus_log_activity()). Die activiteiten hebben een EIGEN activity type
 * nodig, gescheiden van delta's "Notificatie aandachtspunten" (numeriek 154,
 * label "Gegevens aangepast").
 *
 * TECHNISCH:
 * Historisch schreef partstatus.activities.php hardcoded activity_type_id 154 —
 * exact hetzelfde type dat delta gebruikt voor échte wijzigingsnotificaties.
 * Daardoor konden CiviRules die op type 154 triggeren (m.n. regel 433) ten
 * onrechte op een statuswijziging reageren en een lege "AANPASSING …"-mail
 * versturen (de partstatus-activiteit vult de AANPASSINGEN-velden nooit).
 * Dit eigen type ontkoppelt beide stromen definitief.
 *
 * Deze managed OptionValue wordt automatisch aangemaakt/bijgewerkt door CiviCRM
 * (mgd-php-mixin). Match op option_group + name, zodat het numerieke 'value'
 * door CiviCRM wordt toegekend en stabiel blijft.
 * =======================================================================================
 */
return [
  [
    'name'    => 'OptionValue_Partstatus_Deelnamestatus_Gewijzigd',
    'entity'  => 'OptionValue',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'option_group_id.name' => 'activity_type',
        'name'                 => 'Deelnamestatus gewijzigd',
        'label'                => E::ts('Deelnamestatus gewijzigd'),
        'description'          => E::ts('Automatische logactiviteit van de Partstatus Engine bij een deelnemer-statuswijziging.'),
        'filter'               => 1,
        'icon'                 => 'fa-exchange',
        'is_reserved'          => FALSE,
        'is_active'            => TRUE,
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];
