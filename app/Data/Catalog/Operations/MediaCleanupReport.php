<?php

namespace App\Data\Catalog\Operations;

readonly class MediaCleanupReport
{
    public function __construct(
        public int $scanned = 0,
        public int $candidates = 0,
        public int $deleted = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $bytesReclaimed = 0,
        /** @var string[] */
        public array $failureReasons = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'candidates' => $this->candidates,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'bytes_reclaimed' => $this->bytesReclaimed,
            'failure_reasons' => $this->failureReasons,
        ];
    }
}
