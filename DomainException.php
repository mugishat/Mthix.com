<?php
declare(strict_types=1);

namespace App\Services;

/** A business-rule violation whose message is safe to show to the user. */
final class DomainException extends \RuntimeException
{
}
