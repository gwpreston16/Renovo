<?php

declare(strict_types=1);

namespace App\Service\Update;

use RuntimeException;

/**
 * A check that did not produce an answer: GitHub unreachable, slow, refusing,
 * or answering with something that is not a release.
 */
final class UpdateCheckException extends RuntimeException
{
}
