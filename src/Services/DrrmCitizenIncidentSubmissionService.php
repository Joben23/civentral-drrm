<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmBarangayCatalogService.php';
require_once __DIR__ . '/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/DrrmCaloocanBoundaryService.php';

final class DrrmCitizenIncidentSubmissionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}

/** Strict citizen-input boundary; lifecycle, source, severity, and identity remain server-owned. */
final class DrrmCitizenIncidentSubmissionService
{
    public const BARANGAY_DATASET_VERSION_ID = DrrmBarangayCatalogService::LEGACY_DRAFT_DATASET_VERSION_ID;

    /** @var list<string> */
    public const INCIDENT_TYPES = [
        'FLOOD', 'FIRE', 'LANDSLIDE', 'EARTHQUAKE', 'ROAD_BLOCKAGE',
        'FALLEN_TREE', 'STRUCTURAL_DAMAGE', 'MEDICAL_EMERGENCY',
        'UTILITY_HAZARD', 'OTHER',
    ];

    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'request_id', 'incident_type', 'title', 'description', 'barangay_id',
        'location_description', 'latitude', 'longitude',
    ];

    public function __construct(
        private readonly DrrmDataStoreInterface $store,
        private readonly DrrmCaloocanBoundaryService $boundary
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(array $input, CitizenIdentity $identity): array
    {
        if (array_diff(array_keys($input), self::ALLOWED_FIELDS) !== []) {
            throw $this->error('INVALID_REQUEST', 'Unsupported incident submission fields were supplied.', 400);
        }

        $requestId = $this->uuid($input['request_id'] ?? null, 'A valid request_id is required.');
        $incidentType = $this->incidentType($input['incident_type'] ?? null);
        $title = $this->plainText($input['title'] ?? null, 10, 180, 'Invalid incident title.');
        $description = $this->plainText($input['description'] ?? null, 20, 5000, 'Invalid incident description.');
        $location = $this->plainText(
            $input['location_description'] ?? null,
            5,
            500,
            'Invalid location description.',
            'INVALID_LOCATION'
        );
        $barangayId = $this->optionalUuid($input['barangay_id'] ?? null);
        if ($barangayId !== null) {
            $this->validateBarangay($barangayId);
        }
        [$latitude, $longitude] = $this->coordinates($input);

        $fingerprintPayload = [
            'barangay_id' => $barangayId,
            'description' => $description,
            'incident_type' => $incidentType,
            'latitude' => $latitude,
            'location_description' => $location,
            'longitude' => $longitude,
            'title' => $title,
        ];
        try {
            $fingerprint = hash('sha256', json_encode(
                $fingerprintPayload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            ));
        } catch (JsonException) {
            throw $this->error('INVALID_REQUEST', 'The incident submission could not be normalized.', 400);
        }

        try {
            $result = $this->store->rpc('submit_drrm_citizen_incident', [
                'p_reporter_reference' => $identity->reporterReference(),
                'p_request_id' => $requestId,
                'p_request_fingerprint' => $fingerprint,
                'p_incident_type' => $incidentType,
                'p_title' => $title,
                'p_description' => $description,
                'p_location_description' => $location,
                'p_barangay_id' => $barangayId,
                'p_latitude' => $latitude,
                'p_longitude' => $longitude,
            ]);
        } catch (Throwable $exception) {
            throw new DrrmCitizenIncidentSubmissionException(
                'INCIDENT_SERVICE_UNAVAILABLE',
                'Incident submission is temporarily unavailable.',
                503
            );
        }

        if (array_is_list($result) && count($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }
        if (!is_array($result) || array_is_list($result)) {
            throw $this->error('INCIDENT_SUBMISSION_FAILED', 'The incident report could not be submitted.', 500);
        }
        if (($result['success'] ?? null) === false) {
            $code = $result['error_code'] ?? null;
            if ($code === 'RATE_LIMITED') {
                throw $this->error('RATE_LIMITED', 'Too many incident reports were submitted. Please try again later.', 429);
            }
            if ($code === 'DUPLICATE_SUBMISSION') {
                throw $this->error('DUPLICATE_SUBMISSION', 'An identical incident report was recently submitted.', 409);
            }
            throw $this->error('INCIDENT_SUBMISSION_FAILED', 'The incident report could not be submitted.', 500);
        }

        $incidentNumber = $result['incident_number'] ?? null;
        $submittedAt = $result['submitted_at'] ?? null;
        if (($result['success'] ?? null) !== true || ($result['status'] ?? null) !== 'SUBMITTED'
            || !is_string($incidentNumber) || preg_match('/^INC-[0-9]{4}-[0-9]{6,}$/', $incidentNumber) !== 1
            || !is_string($submittedAt)) {
            throw $this->error('INCIDENT_SUBMISSION_FAILED', 'The incident report could not be submitted.', 500);
        }
        try {
            new DateTimeImmutable($submittedAt);
        } catch (Throwable) {
            throw $this->error('INCIDENT_SUBMISSION_FAILED', 'The incident report could not be submitted.', 500);
        }

        return [
            'incident_number' => $incidentNumber,
            'status' => 'SUBMITTED',
            'submitted_at' => $submittedAt,
            'idempotent_replay' => ($result['idempotent_replay'] ?? false) === true,
        ];
    }

    private function validateBarangay(string $barangayId): void
    {
        try {
            $records = (new DrrmBarangayCatalogService($this->store))
                ->writeEligibleBarangaysById([$barangayId]);
        } catch (Throwable) {
            throw $this->error('INCIDENT_SERVICE_UNAVAILABLE', 'Barangay validation is temporarily unavailable.', 503);
        }

        if (count($records) !== 1 || !is_array($records[0]) || array_is_list($records[0])
            || ($records[0]['barangay_id'] ?? null) !== $barangayId
            || !is_string($records[0]['name'] ?? null)
            || trim($records[0]['name']) === '' || $records[0]['name'] === 'Barangay 176') {
            throw $this->error('INVALID_BARANGAY', 'The barangay is not in the validated Caloocan catalog.', 400);
        }
    }

    /** @param array<string, mixed> $input @return array{0: ?float, 1: ?float} */
    private function coordinates(array $input): array
    {
        $hasLatitude = array_key_exists('latitude', $input) && $input['latitude'] !== null;
        $hasLongitude = array_key_exists('longitude', $input) && $input['longitude'] !== null;
        if ($hasLatitude !== $hasLongitude) {
            throw $this->error('INVALID_COORDINATES', 'Latitude and longitude must be supplied together.', 400);
        }
        if (!$hasLatitude) {
            return [null, null];
        }
        if (!(is_int($input['latitude']) || is_float($input['latitude']))
            || !(is_int($input['longitude']) || is_float($input['longitude']))) {
            throw $this->error('INVALID_COORDINATES', 'Coordinates must be JSON numbers.', 400);
        }

        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw $this->error('INVALID_COORDINATES', 'Coordinates are outside valid WGS84 ranges.', 400);
        }

        try {
            $insideCaloocan = $this->boundary->contains($latitude, $longitude);
        } catch (Throwable) {
            throw $this->error('INCIDENT_SERVICE_UNAVAILABLE', 'Location validation is temporarily unavailable.', 503);
        }
        if (!$insideCaloocan) {
            throw $this->error('INVALID_LOCATION', 'Coordinates must fall within the Caloocan City reference boundary.', 400);
        }

        return [round($latitude, 6), round($longitude, 6)];
    }

    private function incidentType(mixed $value): string
    {
        if (!is_string($value)) {
            throw $this->error('INVALID_INCIDENT_TYPE', 'Invalid incident type.', 400);
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, self::INCIDENT_TYPES, true)) {
            throw $this->error('INVALID_INCIDENT_TYPE', 'Invalid incident type.', 400);
        }
        return $value;
    }

    private function uuid(mixed $value, string $message): string
    {
        if (!is_string($value) || preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) !== 1) {
            throw $this->error('INVALID_REQUEST', $message, 400);
        }
        return strtolower($value);
    }

    private function optionalUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) !== 1) {
            throw $this->error('INVALID_BARANGAY', 'Invalid barangay identifier.', 400);
        }
        return strtolower($value);
    }

    private function plainText(
        mixed $value,
        int $minimum,
        int $maximum,
        string $message,
        string $errorCode = 'INVALID_REQUEST'
    ): string {
        if (!is_string($value)) {
            throw $this->error($errorCode, $message, 400);
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum || str_contains($value, '<') || str_contains($value, '>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw $this->error($errorCode, $message, 400);
        }
        return $value;
    }

    private function error(string $code, string $message, int $status): DrrmCitizenIncidentSubmissionException
    {
        return new DrrmCitizenIncidentSubmissionException($code, $message, $status);
    }
}
