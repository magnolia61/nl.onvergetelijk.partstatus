#!/bin/bash

# ==============================================================================
# PARTSTATUS TEST RUNNER
# Voert alle unit tests uit voor nl.onvergetelijk.partstatus.
# Gebruik: bash run-tests.sh [optioneel: specifiek testbestand]
#   Voorbeeld: bash run-tests.sh tests/phpunit/Civi/Partstatus/CriteriaTest.php
# ==============================================================================

PHPUNIT="/home/webteam/buildkit/bin/phpunit9"
FILTER="${1:-tests/phpunit}"

echo "########################################################################"
echo "### PARTSTATUS [TEST] Starten..."
echo "### Target: $FILTER"
echo "########################################################################"

$PHPUNIT --configuration=phpunit.xml.dist "$FILTER" 2>&1 | grep -vEi "Deprecated|Strict Standards"

EXIT_CODE=$?

echo "########################################################################"
if [ $EXIT_CODE -eq 0 ]; then
    echo "### PARTSTATUS [TEST] Klaar. Alle tests geslaagd."
else
    echo "### PARTSTATUS [TEST] Klaar. Er zijn FOUTEN gevonden (exit: $EXIT_CODE)."
fi
echo "########################################################################"

exit $EXIT_CODE
