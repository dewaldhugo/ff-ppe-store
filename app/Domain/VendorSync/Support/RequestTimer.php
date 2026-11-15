<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Support;

/**
 * Counts vendor API requests and the time spent waiting for them, for one
 * run. Shared by the API client (which records) and the importer (which
 * resets it at the start of a run and reads it at the end).
 *
 * Response time is the time between sending a request and having its
 * response. Throttle and backoff waits are counted separately, so the
 * numbers show where a run's time goes.
 */
final class RequestTimer
{
    private int $requests = 0;

    private float $responseSeconds = 0.0;

    private float $waitSeconds = 0.0;

    public function reset(): void
    {
        $this->requests = 0;
        $this->responseSeconds = 0.0;
        $this->waitSeconds = 0.0;
    }

    public function request(float $seconds): void
    {
        $this->requests++;
        $this->responseSeconds += max(0.0, $seconds);
    }

    public function waited(float $seconds): void
    {
        $this->waitSeconds += max(0.0, $seconds);
    }

    /**
     * @return array{requests: int, response_seconds: float, average_response_ms: float, wait_seconds: float}
     */
    public function stats(): array
    {
        return [
            'requests' => $this->requests,
            'response_seconds' => round($this->responseSeconds, 3),
            'average_response_ms' => $this->requests === 0 ? 0.0 : round($this->responseSeconds / $this->requests * 1000, 1),
            'wait_seconds' => round($this->waitSeconds, 3),
        ];
    }
}
