# Governed flood pilot data zones

- `raw/precipitation/` holds immutable acquired source files.
- `raw/event-evidence/` holds immutable acquired reports or controlled references.
- `reviewed/precipitation/` holds canonical observations derived reproducibly from raw files.
- `reviewed/events/` holds reviewer work products; the current machine-readable registry is in `../../manifests/`.
- `processed/pilot/` holds reproducible candidate CSV outputs.
- `manifests/` holds generated pilot dataset and quality manifests.

Actual data is ignored by default. No file may be force-added until source terms,
sensitivity, retention, and repository-size implications are reviewed.
