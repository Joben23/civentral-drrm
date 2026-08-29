# Dokploy staging deployment

## Deployment definition

- Dokploy service type: **Docker Compose**
- Git source: GitHub repository **Joben23/civentral-drrm**
- Branch: **main** for the current staging deployment unless a staging branch is selected later
- Compose path: **./docker-compose.yml**
- Public domain target: service **civentral-web**, container port **80**
- Private-only service: **flood-risk-ai**, container port **8098**. Do not add a Dokploy domain.

Dokploy should manage the public reverse-proxy and domain configuration. The
Compose file intentionally has no host port publications, Traefik labels, or
fixed container names.

## Environment variables

Add values in the Dokploy environment UI. Never commit a deployment environment
file.

Required secrets and external data configuration:

- SUPABASE_URL
- SUPABASE_SECRET_KEY
- CIVENTRAL_AI_INTERNAL_KEY

Staging settings and external service endpoints to confirm:

- APP_ENV
- APP_DEBUG
- EXPO_PUBLIC_API_BASE_URL
- CITIZEN_AUTH_PROFILE_URL

- CITIZEN_CORS_ALLOWED_ORIGINS
- OSRM_BASE_URL
- PAGASA_TENDAY_BASE_URL
- PAGASA_API_TOKEN
- CIVENTRAL_AI_CONNECT_TIMEOUT_MS
- CIVENTRAL_AI_REQUEST_TIMEOUT_MS
- CIVENTRAL_AI_MODEL_PATH
- CIVENTRAL_AI_MODEL_MANIFEST_PATH
- CIVENTRAL_AI_RISK_POLICY_PATH
- CIVENTRAL_AI_MAX_REQUEST_BYTES
- CIVENTRAL_AI_LOG_LEVEL

Optional legacy database settings:

- CIVENTRAL_LEGACY_DB_ENABLED
- DB_HOST
- DB_PORT
- DB_NAME
- DB_USER
- DB_PASSWORD

CIVENTRAL_LEGACY_DB_ENABLED remains disabled for normal staging. If it is
explicitly enabled, every DB-prefixed value is required and must identify the
approved existing CIVENTRAL MySQL database.

CIVENTRAL_AI_INTERNAL_KEY must be one server-side value of at least 32
characters shared only by the two Compose services. APP_ENV and APP_DEBUG
should select staging behavior with debugging disabled.

Despite its mobile-oriented historical name, EXPO_PUBLIC_API_BASE_URL is used
by this PHP application as the server-side base URL for employee login, profile,
users, roles, permissions, departments, audit, and login-history proxy calls.
It therefore remains part of the PHP service configuration. It is never passed
to the AI container.

Do not add Supabase, database, citizen, employee, PAGASA, SMTP, or other web
credentials to the AI service. Compose explicitly gives the AI container only
CIVENTRAL_AI-prefixed settings.

## Database and authentication audit

The DRRM operational modules use the external Supabase REST API. The existing
employee and citizen account endpoints are server-side bridges to external
CIVENTRAL APIs. Employee endpoints default to
https://civentral.tech/api/employee and citizen account proxies are currently
hard-coded to https://civentral.tech/api/citizen.

MySQL is now an optional legacy fallback, not a normal staging dependency:

- employee login, OTP verification, and OTP resend call the external employee
  API without loading the database configuration;
- successful remote OTP verification hydrates the local PHP session from the
  remote user response and remote profile endpoint;
- direct remote login success uses the same verified profile hydration path,
  and the shared admin-page bootstrap requires that local authenticated
  session;
- HeaderService uses that server-side profile and refreshes the trusted RBAC map
  from the remote employee permissions endpoint;
- Module 3 department and responder lists use the external employee departments
  and users API proxies; and
- DRRM operational reads and writes remain Supabase-backed.

The legacy UserRepository fallback is resolved lazily only when the remote
profile is absent. It returns no identity when the legacy database is disabled.
Legacy logout-history cleanup is also skipped unless the flag is enabled. A
remote authentication failure is never converted into a local login, and
missing fallback storage never creates a user, role, department, permission, or
Super Admin identity.

The repository contains no MySQL schema, migration, or dump that would justify
creating a new database container. No MySQL service is included. If the legacy
fallback is intentionally enabled later, its DB-prefixed settings must point to
an approved existing server reachable from Dokploy. The code no longer uses
localhost, root, an empty password, or automatic database creation as defaults.

The external civentral.tech employee and citizen services are also operational
dependencies. Confirm that they are reachable from the Dokploy host and that
their server-side PHP sessions work through this application proxy. Most
citizen account proxy endpoints only emit CORS permission for localhost. A
separate browser or mobile origin cannot call those staging endpoints until the
existing CORS behavior is deliberately made configurable. Same-origin web
requests are not affected.

## Deploy in Dokploy

1. Create a project and a **Docker Compose** service.
2. Select the GitHub repository **Joben23/civentral-drrm**, choose **main**, and
   set the Compose path to **./docker-compose.yml**.
3. Add the required environment variables above. Use the same
   CIVENTRAL_AI_INTERNAL_KEY substitution for both services. Do not expose it to
   browser code.
4. Deploy the Compose stack and wait for flood-risk-ai to become healthy.
5. In **Domains**, add the staging hostname to civentral-web on container port
   **80** and enable HTTPS. Do not add a domain to flood-risk-ai.

## Expected AI state

- GET /health returns HTTP 200 and is the container healthcheck.
- GET /ready may return HTTP 503 with MODEL_NOT_AVAILABLE while the governed
  TensorFlow model and compatible risk policy do not exist.

The expected /ready response does not block Compose startup. Prediction
features remain unavailable until approved artifacts and policies are supplied.

## Post-deployment smoke tests

Perform read-only and safe checks against the HTTPS staging domain:

1. Load the web login page and confirm static assets render.
2. Complete admin authentication and OTP using an authorized staging account.
3. Open the dashboard and confirm the session remains valid through the
   Dokploy proxy.
4. Exercise Module 1, Module 3, and Module 4 read views. Confirm Supabase-backed
   data loads without displaying server exception details.
5. Call the expected citizen read APIs from an allowed or same origin and verify
   authentication and session behavior.
6. Open the DRRM AI status UI or API through PHP. Confirm it can reach
   http://flood-risk-ai:8098, reports service health without exposing the
   internal key, and truthfully reports the model as unavailable.
7. Confirm no host or public listener exists for port 8098 and that Dokploy has
   assigned no domain to flood-risk-ai.

Do not run import, migration, incident-creation, warning-activation, or other
mutating scripts as deployment smoke tests.

## Rollback

Use the Dokploy deployment history to redeploy the last known-good image or
revision. If a Git revision must be selected manually, point the Compose service
at the previous known-good commit and redeploy. Do not alter or roll back
Supabase or MySQL data as part of an application-image rollback. Preserve the
previous environment configuration so the external dependencies remain
unchanged.
