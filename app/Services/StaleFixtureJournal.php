<?php

namespace App\Services;

use RuntimeException;

/** Single-host append-only audit, with an OS-owned lock (never expired/stolen). */
final class StaleFixtureJournal
{
    private $handle;

    private string $path;

    private string $chain = '';

    private int $sequence = 0;

    private int $started;

    private int $attempts = 0;

    public array $state = ['cursor' => 0, 'days' => [], 'rows' => []];

    public function __construct(string $path, bool $initialize = false)
    {
        $this->path = $path;
        if (is_link($path)) {
            throw new RuntimeException('journal_symlink');
        }
        $this->handle = @fopen($path, $initialize ? 'x+b' : 'r+b');
        if (! $this->handle || ! flock($this->handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('journal_missing_or_locked');
        }
        $this->started = hrtime(true);
        if ($initialize) {
            chmod($path, 0600);
            $this->append(['type' => 'init', 'manifest' => StaleFixturePolicy::MANIFEST_SHA256]);
        } else {
            if (fstat($this->handle)['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('journal_size_requires_review');
            }
            while (($line = fgets($this->handle)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('journal_torn_tail');
                }
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $event = $record['event'];
                if ($record['seq'] !== $this->sequence + 1 || $record['prev'] !== $this->chain
                    || $record['hash'] !== hash('sha256', $this->chain.json_encode($event, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('journal_integrity');
                }
                if ($this->sequence === 0 && ($event['type'] !== 'init' || ($event['manifest'] ?? null) !== StaleFixturePolicy::MANIFEST_SHA256)) {
                    throw new RuntimeException('journal_manifest');
                }
                $this->sequence++;
                $this->chain = $record['hash'];
                $this->reduce($event);
            }
            if ($this->sequence === 0) {
                throw new RuntimeException('journal_empty');
            }
        }
    }

    public function guard(): void
    {
        clearstatcache(true, $this->path);
        $pathStat = @stat($this->path);
        $handleStat = is_resource($this->handle) ? fstat($this->handle) : false;
        if (! $pathStat || ! $handleStat || is_link($this->path)
            || $pathStat['ino'] !== $handleStat['ino'] || $pathStat['dev'] !== $handleStat['dev']
            || hrtime(true) - $this->started > 240_000_000_000) {
            throw new RuntimeException('journal_run_deadline');
        }
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function claim(int $id): bool
    {
        $this->guard();
        $day = now('UTC')->format('Y-m-d');
        if ($this->attempts >= 5 || ($this->state['days'][$day] ?? 0) >= 20) {
            return false;
        }
        // Reservations are durable BEFORE gateway accounting/HTTP. Denials/crash
        // ambiguity remain charged; never refund or reset to obtain more calls.
        $this->append(['type' => 'attempt', 'id' => $id, 'day' => $day, 'at' => time(), 'next_at' => time() + 7200]);
        $this->attempts++;

        return true;
    }

    public function append(array $event): void
    {
        $this->guard();
        $hash = hash('sha256', $this->chain.json_encode($event, JSON_THROW_ON_ERROR));
        $record = ['seq' => $this->sequence + 1, 'prev' => $this->chain, 'event' => $event, 'hash' => $hash];
        $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($this->handle, $line) !== strlen($line) || ! fflush($this->handle) || ! fsync($this->handle)) {
            throw new RuntimeException('journal_write_failed');
        }
        $this->sequence++;
        $this->chain = $hash;
        $this->reduce($event);
    }

    private function reduce(array $event): void
    {
        if ($event['type'] === 'attempt') {
            $this->state['days'][$event['day']] = ($this->state['days'][$event['day']] ?? 0) + 1;
            $this->state['rows'][$event['id']]['next_at'] = $event['next_at'];
        } elseif ($event['type'] === 'inspect') {
            $this->state['cursor'] = $event['cursor'];
        } elseif ($event['type'] === 'outcome') {
            $this->state['rows'][$event['id']] = $event['retry'];
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
