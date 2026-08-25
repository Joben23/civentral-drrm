<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\CitizenIdentity;
use App\Services\DrrmCitizenIncidentTrackingService;
use App\Services\SupabaseRestClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenIncidentTrackingService.php';

$client = new SupabaseRestClient(
    SupabaseConfig::fromEnvironment(__DIR__ . '/../.env')
);
$allIncidentsBefore = $client->get('drrm_incidents', [
    'select' => 'id',
    'order' => 'created_at.asc,id.asc',
]);
$resolved = $client->get('drrm_incidents', [
    'select' => 'id,incident_number,reporter_reference,status',
    'reporter_type' => 'eq.CITIZEN',
    'status' => 'eq.RESOLVED',
    'order' => 'reported_at.desc',
    'limit' => 10,
]);

$controlled = null;
foreach ($resolved as $record) {
    if (is_array($record)
        && preg_match(
            '/^CITIZEN:([1-9][0-9]*)$/',
            (string) ($record['reporter_reference'] ?? ''),
            $matches
        ) === 1) {
        $controlled = $record;
        $citizenId = (int) $matches[1];
        break;
    }
}
if (!is_array($controlled) || !isset($citizenId)) {
    fwrite(STDERR, 'ExistingResolvedCitizenIncidentFound=FAIL' . PHP_EOL);
    exit(1);
}

$incidentId = (string) ($controlled['id'] ?? '');
$incidentNumber = (string) ($controlled['incident_number'] ?? '');
$snapshotIncident = $client->get('drrm_incidents', [
    'select' => '*',
    'id' => 'eq.' . $incidentId,
    'limit' => 2,
]);
$snapshotHistory = $client->get('drrm_incident_status_history', [
    'select' => '*',
    'incident_id' => 'eq.' . $incidentId,
    'order' => 'changed_at.asc,id.asc',
]);

$service = new DrrmCitizenIncidentTrackingService($client);
$identity = new CitizenIdentity($citizenId);
$list = $service->myIncidents($identity);
$ownedNumbers = array_column($list['incidents'], 'incident_number');
if (!in_array($incidentNumber, $ownedNumbers, true)) {
    fwrite(STDERR, 'ResolvedIncidentPresentInOwnedList=FAIL' . PHP_EOL);
    exit(1);
}

$details = $service->incidentDetails($incidentNumber, $identity);
$timeline = $details['timeline'] ?? [];
$lastTimelineStatus = $timeline === []
    ? null
    : $timeline[count($timeline) - 1]['status'];
if (($details['status'] ?? null) !== 'RESOLVED'
    || $lastTimelineStatus !== 'RESOLVED') {
    fwrite(STDERR, 'ResolvedIncidentCitizenProjection=FAIL' . PHP_EOL);
    exit(1);
}

$finalIncident = $client->get('drrm_incidents', [
    'select' => '*',
    'id' => 'eq.' . $incidentId,
    'limit' => 2,
]);
$finalHistory = $client->get('drrm_incident_status_history', [
    'select' => '*',
    'incident_id' => 'eq.' . $incidentId,
    'order' => 'changed_at.asc,id.asc',
]);
$allIncidentsAfter = $client->get('drrm_incidents', [
    'select' => 'id',
    'order' => 'created_at.asc,id.asc',
]);

if ($finalIncident !== $snapshotIncident || $finalHistory !== $snapshotHistory) {
    fwrite(STDERR, 'ExistingResolvedIncidentUnchanged=FAIL' . PHP_EOL);
    exit(1);
}
if ($allIncidentsAfter !== $allIncidentsBefore) {
    fwrite(STDERR, 'OperationalIncidentCreated=UNEXPECTED_CHANGE' . PHP_EOL);
    exit(1);
}

echo 'ExistingResolvedCitizenIncidentFound=PASS' . PHP_EOL;
echo 'ResolvedIncidentPresentInOwnedList=PASS' . PHP_EOL;
echo 'ResolvedIncidentCitizenProjection=PASS' . PHP_EOL;
echo 'ExistingResolvedIncidentUnchanged=PASS' . PHP_EOL;
echo 'ResolvedIncidentNumber=' . $incidentNumber . PHP_EOL;
echo 'IncidentCountBefore=' . count($allIncidentsBefore) . PHP_EOL;
echo 'IncidentCountAfter=' . count($allIncidentsAfter) . PHP_EOL;
echo 'OperationalIncidentCreated=NO' . PHP_EOL;
