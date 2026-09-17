<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function assertVerifierFix(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name . ' failed.');
    }
    echo $name . '=PASS' . PHP_EOL;
}

function baseReviewCanonical(string $definition): string
{
    $withoutCasts = preg_replace(
        '/::(text|jsonb)/i',
        '',
        str_replace('pg_catalog.', '', $definition)
    );
    $withoutFormatting = is_string($withoutCasts)
        ? preg_replace('/[[:space:]()]/', '', $withoutCasts)
        : null;
    if (!is_string($withoutFormatting)) {
        throw new RuntimeException('Could not canonicalize verifier definition.');
    }
    return strtolower($withoutFormatting);
}

function fixedReviewCanonical(string $definition): string
{
    $quote = chr(39);
    $base = baseReviewCanonical($definition);
    $versionNot = 'notreviewed_snapshot->>' . $quote . 'payload_version' . $quote
        . 'isdistinctfrompayload_version';
    $versionYes = 'reviewed_snapshot->>' . $quote . 'payload_version' . $quote
        . 'isnotdistinctfrompayload_version';
    $hashNot = 'notreviewed_snapshot->>' . $quote . 'payload_hash' . $quote
        . 'isdistinctfrompayload_hash';
    $hashYes = 'reviewed_snapshot->>' . $quote . 'payload_hash' . $quote
        . 'isnotdistinctfrompayload_hash';
    return str_replace([$versionNot, $hashNot], [$versionYes, $hashYes], $base);
}

function expectedVerifierCheck(string $migration, string $tag): string
{
    $marker = '$' . $tag . '$';
    $start = strpos($migration, $marker);
    $end = $start === false ? false : strpos($migration, $marker, $start + strlen($marker));
    if ($start === false || $end === false) {
        throw new RuntimeException('Expected verifier constraint definition is missing.');
    }
    return substr($migration, $start + strlen($marker), $end - $start - strlen($marker));
}

$migration = file_get_contents(
    __DIR__ . '/../supabase/migrations/20260917000100_module4_phase4b2_verifier_fix.sql'
);
if (!is_string($migration)) {
    throw new RuntimeException('Verifier-fix migration could not be read.');
}
$expected = expectedVerifierCheck($migration, 'review_snapshot');
$appliedMigration = file_get_contents(
    __DIR__ . '/../supabase/migrations/20260916000200_module4_phase4b2_external_advisory_review.sql'
);
if (!is_string($appliedMigration)) {
    throw new RuntimeException('Applied Phase 4B.2 migration could not be read.');
}
assertVerifierFix('ReviewStateRulesPreserved',
    baseReviewCanonical(expectedVerifierCheck($migration, 'review_state'))
        === baseReviewCanonical(expectedVerifierCheck($appliedMigration, 'review_state')));
assertVerifierFix('SnapshotRulesPreserved',
    baseReviewCanonical($expected)
        === baseReviewCanonical(expectedVerifierCheck($appliedMigration, 'review_snapshot')));
$quote = chr(39);
$versionDirect = 'reviewed_snapshot->>' . $quote . 'payload_version' . $quote
    . ' is not distinct from payload_version::text';
$hashDirect = 'reviewed_snapshot->>' . $quote . 'payload_hash' . $quote
    . ' is not distinct from payload_hash';
$versionDeparsed = 'NOT (reviewed_snapshot ->> ' . $quote . 'payload_version' . $quote
    . ') IS DISTINCT FROM payload_version::text';
$hashDeparsed = 'NOT (reviewed_snapshot ->> ' . $quote . 'payload_hash' . $quote
    . ') IS DISTINCT FROM payload_hash';
assertVerifierFix('BothExpectedEqualitiesPresent',
    str_contains($expected, $versionDirect) && str_contains($expected, $hashDirect));
$deparsed = str_replace(
    [$versionDirect, $hashDirect],
    [$versionDeparsed, $hashDeparsed],
    $expected
);
assertVerifierFix('DeparsedVariantConstructed', $deparsed !== $expected);
assertVerifierFix('OldCanonicalizerFalseNegative',
    baseReviewCanonical($deparsed) !== baseReviewCanonical($expected));
$expectedCanonical = fixedReviewCanonical($expected);
assertVerifierFix('DirectIsNotDistinctAccepted',
    fixedReviewCanonical($expected) === $expectedCanonical);
assertVerifierFix('DeparsedNotIsDistinctAccepted',
    fixedReviewCanonical($deparsed) === $expectedCanonical);

$versionNotToken = 'notreviewed_snapshot->>' . $quote . 'payload_version' . $quote
    . 'isdistinctfrompayload_version';
$hashNotToken = 'notreviewed_snapshot->>' . $quote . 'payload_hash' . $quote
    . 'isdistinctfrompayload_hash';
$versionYesToken = 'reviewed_snapshot->>' . $quote . 'payload_version' . $quote
    . 'isnotdistinctfrompayload_version';
$hashYesToken = 'reviewed_snapshot->>' . $quote . 'payload_hash' . $quote
    . 'isnotdistinctfrompayload_hash';
assertVerifierFix('MigrationHasFieldSpecificDeparserNormalization',
    str_contains($migration, $quote . str_replace($quote, $quote . $quote, $versionNotToken) . $quote)
    && str_contains($migration, $quote . str_replace($quote, $quote . $quote, $hashNotToken) . $quote)
    && str_contains($migration, $quote . str_replace($quote, $quote . $quote, $versionYesToken) . $quote)
    && str_contains($migration, $quote . str_replace($quote, $quote . $quote, $hashYesToken) . $quote)
    && str_contains($migration, 'pg_catalog.pg_get_expr(actual.conbin, actual.conrelid)')
    && str_contains($migration, 'review_snapshot_constraint_valid'));

function assertWeakenedVerifierRejected(
    string $name,
    string $candidate,
    string $baseline
): void {
    assertVerifierFix($name, $candidate !== $baseline);
}

$canonicalDeparsed = fixedReviewCanonical($deparsed);
$weakSize = str_replace('<=98304', '<=983040', $canonicalDeparsed);
assertWeakenedVerifierRejected('WeakenedSizeRejected', $weakSize, $expectedCanonical);
assertWeakenedVerifierRejected('MissingSizeBoundRejected',
    str_replace('andoctet_lengthreviewed_snapshot<=98304', '', $canonicalDeparsed),
    $expectedCanonical);
assertWeakenedVerifierRejected('MissingObjectTypeRejected',
    str_replace('jsonb_typeofreviewed_snapshot=' . $quote . 'object' . $quote . 'and',
        '', $canonicalDeparsed),
    $expectedCanonical);
$missingKey = str_replace($quote . 'source_code' . $quote . ',', '', $canonicalDeparsed);
assertWeakenedVerifierRejected('MissingRequiredKeyRejected', $missingKey, $expectedCanonical);
$noExtraStart = strpos($canonicalDeparsed, 'andreviewed_snapshot-array[');
$noExtraEnd = $noExtraStart === false
    ? false : strpos($canonicalDeparsed, 'andreviewed_snapshot->>', $noExtraStart);
if ($noExtraStart === false || $noExtraEnd === false) {
    throw new RuntimeException('No-extra-keys expression is missing.');
}
$allowsExtra = substr($canonicalDeparsed, 0, $noExtraStart)
    . substr($canonicalDeparsed, $noExtraEnd);
assertWeakenedVerifierRejected('ExtraKeysAllowedRejected', $allowsExtra, $expectedCanonical);
$versionEquality = 'andreviewed_snapshot->>' . $quote . 'payload_version' . $quote
    . 'isnotdistinctfrompayload_version';
$hashEquality = 'andreviewed_snapshot->>' . $quote . 'payload_hash' . $quote
    . 'isnotdistinctfrompayload_hash';
assertVerifierFix('BothEqualityClausesStillRequired',
    str_contains($expectedCanonical, $versionEquality)
    && str_contains($expectedCanonical, $hashEquality));
assertWeakenedVerifierRejected('MissingVersionEqualityRejected',
    str_replace($versionEquality, '', $canonicalDeparsed), $expectedCanonical);
assertWeakenedVerifierRejected('MissingHashEqualityRejected',
    str_replace($hashEquality, '', $canonicalDeparsed), $expectedCanonical);

assertVerifierFix('VerifierOnlyMigration',
    substr_count($migration, 'create or replace function public.verify_module4_external_advisory_review_schema()') === 1
    && substr_count($migration, 'create or replace function') === 1
    && preg_match('/(?m)^\s*(alter table|create table|create index|drop table|insert into|update |delete from)\b/i', $migration) === 0);
assertVerifierFix('VerifierSecurityPreserved',
    str_contains($migration, 'language sql')
    && str_contains($migration, 'stable')
    && str_contains($migration, 'security definer')
    && str_contains($migration, 'set search_path = pg_catalog, public')
    && str_contains($migration, 'from public, anon, authenticated, service_role;')
    && str_contains($migration, 'to service_role;')
    && preg_match('/(?m)^\s*execute\b/i', $migration) === 0);
echo 'Module 4 Phase 4B.2 verifier fix: OK' . PHP_EOL;
