<?php

/**
 * LEEFTIJD STATUS ENGINE
 * Centraal punt voor het bepalen van Participant statussen op basis van datums.
 */

function leeftijd_status_sync_participant($part_id, $data = []) {
    $extdebug = 1;
    
    // 1. Haal actuele data op als deze niet is meegegeven
    if (empty($data)) {
        $data = civicrm_api4('Participant', 'get', [
            'checkPermissions' => FALSE,
            'where' => [['id', '=', $part_id]],
            'select' => [
                'status_id', 'register_date', 'role_id:name',
                'PART_DEEL_INTERN.wachtlijst_erop', 
                'PART_DEEL_INTERN.wachtlijst_eraf',
                'PART_DEEL_INTERN.criteriacheck_start',
                'PART_DEEL_INTERN.criteriacheck_einde',
                'PART_KAMPGELD.contribid'
            ],
        ])->first();
    }

    $old_status_id = $data['status_id'];
    $new_status_id = $old_status_id;
    
    $wachtlijst_erop = $data['PART_DEEL_INTERN.wachtlijst_erop'] 		?? NULL;
    $wachtlijst_eraf = $data['PART_DEEL_INTERN.wachtlijst_eraf'] 		?? NULL;
    $check_start     = $data['PART_DEEL_INTERN.criteriacheck_start'] 	?? NULL;
    $check_einde     = $data['PART_DEEL_INTERN.criteriacheck_einde'] 	?? NULL;
    $has_paylink     = !empty($data['PART_KAMPGELD.contribid']);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### STATUS SYNC: PID $part_id", "[CHECK]");
    wachthond($extdebug, 2, "########################################################################");

    // --- LOGICA 1: WACHTLIJST AFHANDELING ---
    // Indien 'Wachtlijst Eraf' is gevuld -> Verplaats naar Afwachting (9) of Bevestigd (1)
    if (!empty($wachtlijst_eraf)) {
        if ($has_paylink) {
            $new_status_id = 1; // Bevestigd
            $label = "Wachtlijst -> Bevestigd (Betaald)";
        } else {
            $new_status_id = 9; // Afwachting (Wacht op betaling/bevestiging ouder)
            $label = "Wachtlijst -> Afwachting (Mail verstuurd)";
        }
    } 
    // Indien 'Wachtlijst Erop' gevuld en Eraf leeg -> Forceer status Wachtlijst (7)
    elseif (!empty($wachtlijst_erop)) {
        $new_status_id = 7;
        $label = "Status geforceerd: Wachtlijst";
    }

    // --- LOGICA 2: CRITERIACHECK AFHANDELING ---
    // Indien Einde is gevuld -> Verplaats van Criteriacheck (8) naar Bevestigd (1) of Afwachting (9)
    if (!empty($check_einde) && $new_status_id == 8) {
        $new_status_id = ($has_paylink) ? 1 : 9;
        $label = "Criteriacheck voltooid -> " . ($new_status_id == 1 ? "Bevestigd" : "Afwachting");
    }
    // Indien Start is gevuld en Einde leeg -> Forceer status Criteriacheck (8)
    elseif (!empty($check_start) && empty($check_einde)) {
        // Alleen als we niet op de wachtlijst staan, want wachtlijst is dominanter
        if ($new_status_id != 7) {
            $new_status_id = 8;
            $label = "Status geforceerd: Criteriacheck";
        }
    }

    // --- 3. DATABASE UPDATE ---
    if ($new_status_id != $old_status_id) {
        wachthond($extdebug, 1, "!!! STATUS WIJZIGING !!!", "$label ($old_status_id -> $new_status_id)");
        
        civicrm_api4('Participant', 'update', [
            'checkPermissions' => FALSE,
            'where' => [['id', '=', $part_id]],
            'values' => ['status_id' => $new_status_id],
        ]);
    }
}