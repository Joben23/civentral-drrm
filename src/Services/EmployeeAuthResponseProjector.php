<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Converts employee-authentication authority responses into the deliberately
 * small response contract exposed to the admin login browser.
 */
final class EmployeeAuthResponseProjector
{
    /**
     * @param array<string, mixed> $upstream
     * @return array{status_code:int,payload:array{status:string,message:string}}
     */
    public static function login(array $upstream): array
    {
        $status = self::upstreamStatus($upstream);
        $statusCode = self::upstreamStatusCode($upstream);

        if ($status === null) {
            return self::response(502, 'error', 'The authentication service is temporarily unavailable.');
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            if ($status === 'success') {
                return self::response(200, 'success', 'Login successful.');
            }

            if ($status === 'otp_required') {
                return self::response(
                    200,
                    'otp_required',
                    'A verification code was sent to your registered email.'
                );
            }
        }

        if ($status === 'maintenance'
            && ($statusCode === 503 || ($statusCode >= 200 && $statusCode < 300))) {
            return self::response(
                503,
                'maintenance',
                'Sign-in is temporarily unavailable due to maintenance. Please try again later.'
            );
        }

        if ($statusCode === 429) {
            return self::response(429, 'error', 'Too many sign-in attempts. Please try again later.');
        }

        if ($statusCode < 1 || $statusCode >= 500) {
            return self::response(502, 'error', 'The authentication service is temporarily unavailable.');
        }

        return self::response(401, 'error', 'Sign-in failed. Check your credentials and try again.');
    }

    /**
     * @param array<string, mixed> $upstream
     * @return array{status_code:int,payload:array{status:string,message:string}}
     */
    public static function verifyOtp(array $upstream): array
    {
        $status = self::upstreamStatus($upstream);
        $statusCode = self::upstreamStatusCode($upstream);

        if ($status === null) {
            return self::response(502, 'error', 'The verification service is temporarily unavailable.');
        }

        if ($status === 'success' && $statusCode >= 200 && $statusCode < 300) {
            return self::response(200, 'success', 'Verification successful.');
        }

        if ($statusCode === 429) {
            return self::response(
                429,
                'error',
                'Too many verification attempts. Please try again later.'
            );
        }

        if ($statusCode < 1 || $statusCode >= 500) {
            return self::response(502, 'error', 'The verification service is temporarily unavailable.');
        }

        return self::response(401, 'error', 'Verification failed. Check the code and try again.');
    }

    /**
     * @param array<string, mixed> $upstream
     * @return array{status_code:int,payload:array{status:string,message:string}}
     */
    public static function resendOtp(array $upstream): array
    {
        $status = self::upstreamStatus($upstream);
        $statusCode = self::upstreamStatusCode($upstream);

        if ($status === null) {
            return self::response(502, 'error', 'The verification service is temporarily unavailable.');
        }

        if ($status === 'success' && $statusCode >= 200 && $statusCode < 300) {
            return self::response(200, 'success', 'A new verification code has been sent.');
        }

        if ($statusCode === 429) {
            return self::response(
                429,
                'error',
                'Too many resend attempts. Please try again later.'
            );
        }

        if ($statusCode < 1 || $statusCode >= 500) {
            return self::response(502, 'error', 'The verification service is temporarily unavailable.');
        }

        return self::response(400, 'error', 'Unable to resend the verification code. Please try again.');
    }

    /** @param array<string, mixed> $upstream */
    private static function upstreamStatus(array $upstream): ?string
    {
        $body = $upstream['body'] ?? null;
        if (!is_array($body) || !isset($body['status']) || !is_string($body['status'])) {
            return null;
        }

        return $body['status'];
    }

    /** @param array<string, mixed> $upstream */
    private static function upstreamStatusCode(array $upstream): int
    {
        $statusCode = $upstream['code'] ?? null;
        if (!is_int($statusCode)) {
            return 0;
        }

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 0;
    }

    /** @return array{status_code:int,payload:array{status:string,message:string}} */
    private static function response(int $statusCode, string $status, string $message): array
    {
        return [
            'status_code' => $statusCode,
            'payload' => [
                'status' => $status,
                'message' => $message,
            ],
        ];
    }
}
