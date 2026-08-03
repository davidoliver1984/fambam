<?php

namespace App\Services;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Psr\Log\LoggerInterface;
use Throwable;

class PwnedPasswordVerifier implements UncompromisedVerifier
{
    public function __construct(
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds,
    ) {}

    /** @param array{value: mixed, threshold: int} $data */
    public function verify($data): bool
    {
        $password = (string) $data['value'];

        if ($password === '') {
            return false;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = $this->http
                ->withHeaders(['Add-Padding' => 'true'])
                ->timeout($this->timeoutSeconds)
                ->get('https://api.pwnedpasswords.com/range/'.$prefix);
        } catch (Throwable) {
            $this->logger->warning('Compromised-password screening could not be completed.', [
                'provider' => 'haveibeenpwned',
            ]);

            return true;
        }

        if (! $response->successful()) {
            $this->logger->warning('Compromised-password screening could not be completed.', [
                'provider' => 'haveibeenpwned',
                'status' => $response->status(),
            ]);

            return true;
        }

        foreach (preg_split('/\r\n|\r|\n/', trim($response->body())) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', $line, 2), 2, '0');

            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > $data['threshold']) {
                return false;
            }
        }

        return true;
    }
}
