<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;

/** A citizen may select an event, never a recipient or warning mutation. */
final class DrrmCitizenWarningNotificationRequest
{
    public static function eventId(string $json): string
    {
        if ($json === '' || strlen($json) > 1024) {
            throw new InvalidArgumentException('A small notification request is required.');
        }
        try {
            $body = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The notification request must contain valid JSON.');
        }
        if (!$body instanceof \stdClass
            || array_keys(get_object_vars($body)) !== ['notification_event_id']
            || !is_string($body->notification_event_id)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $body->notification_event_id
            ) !== 1) {
            throw new InvalidArgumentException('Only a valid notification_event_id is permitted.');
        }
        return strtolower($body->notification_event_id);
    }
}
