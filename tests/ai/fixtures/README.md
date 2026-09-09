# Test fixtures only

Every record in this directory is fictional and exists solely to test data
validation and preprocessing behavior. Nothing here is a historical incident,
PAGASA observation, operational negative, or model-training record.

These files must never be copied to `ml/flood-risk/data/`, included in a real
readiness report, or cited as evidence of model accuracy.

Phase 3B1 pilot fixtures additionally carry all three machine-readable markers:
`TEST_ONLY`, `SYNTHETIC`, and `NOT_FOR_TRAINING`. Even fixture sources that are
configured to exercise an approved-source code path remain prohibited by the
top-level dataset classification, and every generated pilot manifest remains
`NOT_APPROVED` with `training_ready=false`.
