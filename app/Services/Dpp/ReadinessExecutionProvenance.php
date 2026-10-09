<?php

namespace App\Services\Dpp;

use App\Contracts\Passports\PassportReadinessRule;
use App\Services\Passports\CanonicalJsonEncoder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/** Identifies executed code; never claims that stored PHP can be replayed. */
class ReadinessExecutionProvenance
{
    private ?array $release = null;

    /** @var array<string, string> */
    private array $fileHashes = [];

    public function __construct(private readonly CanonicalJsonEncoder $encoder) {}

    /** @param array<PassportReadinessRule> $rules */
    public function capture(array $rules): array
    {
        $handlers = [];
        foreach ($rules as $rule) {
            $reflection = new ReflectionClass($rule);
            $file = $reflection->getFileName();
            // PHP anonymous class names embed an absolute file path. Use a
            // source-hash/line identity without publishing that path.
            $identity = $reflection->isAnonymous() && is_string($file)
                ? 'anonymous:'.$this->hashFile($file).':'.$reflection->getStartLine() : $rule::class;
            $handlers[$identity] = ['code' => $rule->code(), 'class' => $identity,
                'source_sha256' => is_string($file) ? $this->hashFile($file) : null];
        }
        ksort($handlers);
        $manifest = ['version' => 1, 'replay_guarantee' => 'provenance_only',
            'release' => $this->release(), 'handlers' => array_values($handlers)];

        return $manifest + ['fingerprint' => $this->encoder->hash($manifest)];
    }

    private function release(): array
    {
        if ($this->release !== null) {
            return $this->release;
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
                $files[$relative] = $this->hashFile($file->getPathname());
            }
        }
        ksort($files);

        return $this->release = ['application_sha256' => $this->encoder->hash($files),
            'dependencies_sha256' => $this->hashFile(base_path('composer.lock')),
            'php_version' => PHP_VERSION];
    }

    private function hashFile(string $path): string
    {
        if (isset($this->fileHashes[$path])) {
            return $this->fileHashes[$path];
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Unable to identify readiness implementation.');
        }

        return $this->fileHashes[$path] = $hash;
    }
}
