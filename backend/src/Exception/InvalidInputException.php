<?php

namespace App\Exception;

/**
 * Fachlich ungueltige Eingabe, z. B. Ende vor Beginn. Wird als
 * 422 Unprocessable Entity ausgeliefert.
 */
final class InvalidInputException extends \InvalidArgumentException
{
}
