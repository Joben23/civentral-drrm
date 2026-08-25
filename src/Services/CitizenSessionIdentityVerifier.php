<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

final class CitizenAuthenticationRequiredException extends RuntimeException
{
}

final class CitizenAuthenticationUnavailableException extends RuntimeException
{
}

final class CitizenIdentity
{
    public function __construct(public readonly int $citizenUserId)
    {
        if ($citizenUserId < 1) {
            throw new RuntimeException('A citizen identity must have a positive stable identifier.');
        }
    }

    public function reporterReference(): string
    {
        return 'CITIZEN:' . $this->citizenUserId;
    }
}

interface CitizenIdentityVerifierInterface
{
    public function verify(): CitizenIdentity;
}

/**
 * Resolves identity only through the server-held upstream PHP session.
 * No email or citizen ID from a request body/query is used for authorization.
 */
final class CitizenSessionIdentityVerifier implements CitizenIdentityVerifierInterface
{
    public function __construct(
        private readonly string $profileUrl,
        private readonly ?string $remoteSessionId,
        private readonly int $connectionTimeoutSeconds = 4,
        private readonly int $requestTimeoutSeconds = 10
    ) {
    }

    public function verify(): CitizenIdentity
    {
        $sessionId = trim((string) $this->remoteSessionId);
        if ($sessionId === '' || preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sessionId) !== 1) {
            throw new CitizenAuthenticationRequiredException('Citizen authentication is required.');
        }
        if (!extension_loaded('curl')) {
            throw new CitizenAuthenticationUnavailableException('Citizen identity verification is unavailable.');
        }

        $handle = curl_init($this->profileUrl);
        if ($handle === false) {
            throw new CitizenAuthenticationUnavailableException('Citizen identity verification is unavailable.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'CIVENTRAL-DRRM-Citizen-Identity/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cookie: PHPSESSID=' . $sessionId,
            ],
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        $response = curl_exec($handle);
        if (!is_string($response)) {
            curl_close($handle);
            throw new CitizenAuthenticationUnavailableException('Citizen identity verification is unavailable.');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (in_array($status, [401, 403], true)) {
            throw new CitizenAuthenticationRequiredException('Citizen authentication is required.');
        }
        if ($status !== 200 || strlen($response) > 65536) {
            throw new CitizenAuthenticationUnavailableException('Citizen identity verification is unavailable.');
        }

        try {
            $payload = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CitizenAuthenticationUnavailableException('Citizen identity verification is unavailable.');
        }

        $profile = is_array($payload) && !array_is_list($payload) ? ($payload['data'] ?? null) : null;
        $citizenId = is_array($profile) ? ($profile['citizen_user_id'] ?? null) : null;
        $accountStatus = is_array($profile) ? trim((string) ($profile['status'] ?? '')) : '';

        if (($payload['status'] ?? null) !== 'success'
            || !(is_int($citizenId) || (is_string($citizenId) && ctype_digit($citizenId)))
            || (int) $citizenId < 1 || strcasecmp($accountStatus, 'Active') !== 0) {
            throw new CitizenAuthenticationRequiredException('An active citizen account is required.');
        }

        return new CitizenIdentity((int) $citizenId);
    }
}
