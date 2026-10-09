<?php

namespace App\Exception;

/**
 * Ein unzulaessiger Zustandswechsel, z. B. eine bereits abgeschlossene
 * Arbeit nochmals abschliessen. Wird als 409 Conflict ausgeliefert.
 */
final class WorkflowException extends \DomainException
{
}
