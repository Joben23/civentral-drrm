"""Canonical Phase 3C rainfall history validation and feature construction."""

from __future__ import annotations

import math
from datetime import datetime, timedelta, timezone
from typing import Any, Mapping, Sequence


EXPECTED_HISTORY_COUNT = 48
EXPECTED_FEATURE_COUNT = 57
EXPECTED_INTERVAL = timedelta(minutes=30)


class RainfallFeatureError(ValueError):
    """Stable fail-closed validation error shared by offline and service paths."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def parse_utc(value: Any) -> datetime:
    if not isinstance(value, str):
        raise RainfallFeatureError(
            "TIMESTAMP_GAP", "timestamp_utc must be an ISO-8601 UTC string."
        )
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise RainfallFeatureError(
            "TIMESTAMP_GAP", "timestamp_utc must be an ISO-8601 UTC string."
        ) from exc
    if parsed.tzinfo is None or parsed.utcoffset() != timedelta(0):
        raise RainfallFeatureError(
            "TIMESTAMP_GAP", "History timestamps must explicitly use UTC."
        )
    return parsed.astimezone(timezone.utc)


def iso_z(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def feature_names() -> list[str]:
    return [
        f"lag_precipitation_mm_{index:02d}"
        for index in range(EXPECTED_HISTORY_COUNT, 0, -1)
    ] + [
        "antecedent_1h_mm",
        "antecedent_3h_mm",
        "antecedent_6h_mm",
        "antecedent_12h_mm",
        "antecedent_24h_mm",
        "hour_sin",
        "hour_cos",
        "day_of_year_sin",
        "day_of_year_cos",
    ]


def validate_history(
    history: Sequence[Mapping[str, Any]],
) -> list[dict[str, Any]]:
    if len(history) != EXPECTED_HISTORY_COUNT:
        raise RainfallFeatureError(
            "INSUFFICIENT_HISTORY",
            "Exactly 48 historical half-hour observations are required.",
        )

    normalized: list[dict[str, Any]] = []
    previous: datetime | None = None
    seen: set[datetime] = set()
    for item in history:
        if not isinstance(item, Mapping):
            raise RainfallFeatureError(
                "INVALID_PRECIPITATION", "Each history observation must be an object."
            )
        timestamp = parse_utc(item.get("timestamp_utc"))
        if timestamp in seen:
            raise RainfallFeatureError(
                "DUPLICATE_TIMESTAMP", "Duplicate history timestamp."
            )
        if previous is not None and timestamp - previous != EXPECTED_INTERVAL:
            raise RainfallFeatureError(
                "TIMESTAMP_GAP",
                "History timestamps must be chronological continuous 30-minute intervals.",
            )
        value = item.get("city_mean_precipitation_mm")
        if (
            isinstance(value, bool)
            or not isinstance(value, (int, float))
            or not math.isfinite(float(value))
        ):
            raise RainfallFeatureError(
                "INVALID_PRECIPITATION", "Rainfall must be a finite JSON number."
            )
        if float(value) < 0:
            raise RainfallFeatureError(
                "NEGATIVE_SOURCE_PRECIPITATION", "Source rainfall cannot be negative."
            )
        normalized.append(
            {
                "observed_at_start": iso_z(timestamp),
                "observed_at_end": iso_z(timestamp + EXPECTED_INTERVAL),
                "city_mean_precipitation_mm": float(value),
            }
        )
        seen.add(timestamp)
        previous = timestamp
    return normalized


def make_features(
    records: Sequence[Mapping[str, Any]], origin_index: int
) -> tuple[list[float], list[str]]:
    history = [
        float(item["city_mean_precipitation_mm"])
        for item in records[
            origin_index - EXPECTED_HISTORY_COUNT + 1 : origin_index + 1
        ]
    ]
    if len(history) != EXPECTED_HISTORY_COUNT:
        raise RainfallFeatureError(
            "INSUFFICIENT_HISTORY", "Insufficient historical lookback."
        )
    accumulations = [
        sum(history[-2:]),
        sum(history[-6:]),
        sum(history[-12:]),
        sum(history[-24:]),
        sum(history[-48:]),
    ]
    timestamp = parse_utc(records[origin_index]["observed_at_start"])
    hour_fraction = timestamp.hour + timestamp.minute / 60
    day_fraction = timestamp.timetuple().tm_yday - 1 + hour_fraction / 24
    values = history + accumulations + [
        math.sin(2 * math.pi * hour_fraction / 24),
        math.cos(2 * math.pi * hour_fraction / 24),
        math.sin(2 * math.pi * day_fraction / 365.2425),
        math.cos(2 * math.pi * day_fraction / 365.2425),
    ]
    return values, feature_names()
