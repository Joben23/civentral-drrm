<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/** Checksum-pinned point-in-polygon validation using the existing Module 1 city boundary. */
final class DrrmCaloocanBoundaryService
{
    public const EXPECTED_SHA256 = '9647f3cac1758a07cfdc6a5bb8767fe9e4f1eb70b4e7d2c14a99abf2de1f9d50';

    /** @var array<mixed>|null */
    private ?array $multiPolygon = null;

    public function __construct(private readonly string $boundaryPath)
    {
    }

    public function contains(float $latitude, float $longitude): bool
    {
        if (!is_finite($latitude) || !is_finite($longitude)) {
            return false;
        }

        foreach ($this->geometry() as $polygon) {
            if (!is_array($polygon) || $polygon === [] || !is_array($polygon[0] ?? null)) {
                throw new RuntimeException('The Caloocan reference boundary contains an invalid polygon.');
            }

            $exterior = $this->pointInRing($longitude, $latitude, $polygon[0]);
            if ($exterior === 0) {
                continue;
            }
            if ($exterior === 2) {
                return true;
            }

            $insideHole = false;
            for ($index = 1, $count = count($polygon); $index < $count; $index++) {
                $hole = $this->pointInRing($longitude, $latitude, $polygon[$index]);
                if ($hole === 2) {
                    return true;
                }
                if ($hole === 1) {
                    $insideHole = true;
                    break;
                }
            }
            if (!$insideHole) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed> */
    private function geometry(): array
    {
        if ($this->multiPolygon !== null) {
            return $this->multiPolygon;
        }
        if (!is_file($this->boundaryPath)
            || !hash_equals(self::EXPECTED_SHA256, strtolower((string) hash_file('sha256', $this->boundaryPath)))) {
            throw new RuntimeException('The reviewed Caloocan reference boundary is unavailable.');
        }

        $contents = file_get_contents($this->boundaryPath);
        if (!is_string($contents)) {
            throw new RuntimeException('The reviewed Caloocan reference boundary is unavailable.');
        }
        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The reviewed Caloocan reference boundary is invalid.');
        }

        $feature = is_array($document) && ($document['type'] ?? null) === 'FeatureCollection'
            && is_array($document['features'] ?? null) && count($document['features']) === 1
            ? $document['features'][0]
            : null;
        $properties = is_array($feature) ? ($feature['properties'] ?? null) : null;
        $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
        if (!is_array($properties) || ($properties['adm3_name'] ?? null) !== 'Caloocan City'
            || ($properties['adm3_pcode'] ?? null) !== 'PH1307501'
            || !is_array($geometry) || ($geometry['type'] ?? null) !== 'MultiPolygon'
            || !is_array($geometry['coordinates'] ?? null) || count($geometry['coordinates']) !== 2) {
            throw new RuntimeException('The reviewed Caloocan reference boundary is invalid.');
        }

        return $this->multiPolygon = $geometry['coordinates'];
    }

    /** @param array<mixed> $ring @return int 0=outside, 1=inside, 2=boundary */
    private function pointInRing(float $longitude, float $latitude, array $ring): int
    {
        $inside = false;
        $count = count($ring);
        if ($count < 4) {
            throw new RuntimeException('The Caloocan reference boundary contains an invalid ring.');
        }

        for ($index = 0, $previous = $count - 1; $index < $count; $previous = $index++) {
            if (!is_array($ring[$previous] ?? null) || !is_array($ring[$index] ?? null)
                || !is_numeric($ring[$previous][0] ?? null) || !is_numeric($ring[$previous][1] ?? null)
                || !is_numeric($ring[$index][0] ?? null) || !is_numeric($ring[$index][1] ?? null)) {
                throw new RuntimeException('The Caloocan reference boundary contains an invalid coordinate.');
            }
            $x1 = (float) $ring[$previous][0];
            $y1 = (float) $ring[$previous][1];
            $x2 = (float) $ring[$index][0];
            $y2 = (float) $ring[$index][1];
            $cross = (($x2 - $x1) * ($latitude - $y1)) - (($y2 - $y1) * ($longitude - $x1));
            if (abs($cross) <= 1.0E-12
                && $longitude >= min($x1, $x2) - 1.0E-12 && $longitude <= max($x1, $x2) + 1.0E-12
                && $latitude >= min($y1, $y2) - 1.0E-12 && $latitude <= max($y1, $y2) + 1.0E-12) {
                return 2;
            }
            if (($y1 > $latitude) !== ($y2 > $latitude)) {
                $intersection = (($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1)) + $x1;
                if ($longitude < $intersection) {
                    $inside = !$inside;
                }
            }
        }

        return $inside ? 1 : 0;
    }
}
