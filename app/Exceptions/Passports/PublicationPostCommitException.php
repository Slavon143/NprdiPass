<?php

namespace App\Exceptions\Passports;

use App\Data\Passports\PublicationResult;
use RuntimeException;
use Throwable;

/** The publication is committed; this exception must not trigger asset cleanup. */
class PublicationPostCommitException extends RuntimeException
{
    public function __construct(
        public readonly PublicationResult $result,
        public readonly string $step,
        Throwable $previous,
    ) {
        parent::__construct("Passport publication committed, but post-commit {$step} failed.", 0, $previous);
    }
}
