<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\RecaptchaConfig;
use JsonException;

require_once __DIR__ . '/../../config/recaptcha.php';

final class RecaptchaVerificationResult
{
    public const VERIFIED = 'VERIFIED';
    public const INVALID = 'INVALID';
    public const UNAVAILABLE = 'UNAVAILABLE';

    public function __construct(public readonly string $status)
    {
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }
}

final class RecaptchaVerifier
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    public const MAX_TOKEN_LENGTH = 8192;
    private const MAX_RESPONSE_BYTES = 16384;

    /** @var null|callable(string, array<string, string>, RecaptchaConfig): array{status:int, body:string, transport_error:bool} */
    private $transport;

    public function __construct(
        private readonly RecaptchaConfig $config,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    public function verify(string $token, ?string $remoteIp = null): RecaptchaVerificationResult
    {
        if ($token === ''
            || strlen($token) > self::MAX_TOKEN_LENGTH
            || preg_match('/^[\x21-\x7E]+$/', $token) !== 1) {
            return new RecaptchaVerificationResult(RecaptchaVerificationResult::INVALID);
        }

        $fields = [
            'secret' => $this->config->secretKey(),
            'response' => $token,
        ];
        if ($remoteIp !== null && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
            $fields['remoteip'] = $remoteIp;
        }

        $response = $this->transport !== null
            ? ($this->transport)(self::VERIFY_URL, $fields, $this->config)
            : $this->curlRequest($fields);

        if ($response['transport_error'] || $response['status'] < 200 || $response['status'] >= 300) {
            return new RecaptchaVerificationResult(RecaptchaVerificationResult::UNAVAILABLE);
        }

        try {
            $payload = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new RecaptchaVerificationResult(RecaptchaVerificationResult::UNAVAILABLE);
        }

        if (!is_array($payload)
            || array_is_list($payload)
            || !array_key_exists('success', $payload)
            || !is_bool($payload['success'])) {
            return new RecaptchaVerificationResult(RecaptchaVerificationResult::UNAVAILABLE);
        }

        if ($payload['success'] !== true) {
            $errorCodes = is_array($payload['error-codes'] ?? null) ? $payload['error-codes'] : [];
            $configurationFailure = in_array('missing-input-secret', $errorCodes, true)
                || in_array('invalid-input-secret', $errorCodes, true);

            return new RecaptchaVerificationResult(
                $configurationFailure
                    ? RecaptchaVerificationResult::UNAVAILABLE
                    : RecaptchaVerificationResult::INVALID
            );
        }

        $hostname = strtolower(rtrim(trim((string) ($payload['hostname'] ?? '')), '.'));
        if ($hostname === '' || !in_array($hostname, $this->config->allowedHostnames(), true)) {
            return new RecaptchaVerificationResult(RecaptchaVerificationResult::INVALID);
        }

        return new RecaptchaVerificationResult(RecaptchaVerificationResult::VERIFIED);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status:int, body:string, transport_error:bool}
     */
    private function curlRequest(array $fields): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'transport_error' => true];
        }

        $handle = curl_init(self::VERIFY_URL);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'transport_error' => true];
        }

        $body = '';
        $responseTooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->config->connectTimeoutMs(),
            CURLOPT_TIMEOUT_MS => $this->config->requestTimeoutMs(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$responseTooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $responseTooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $executed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $transportError = $executed === false || $responseTooLarge;
        curl_close($handle);

        return ['status' => $status, 'body' => $body, 'transport_error' => $transportError];
    }
}
