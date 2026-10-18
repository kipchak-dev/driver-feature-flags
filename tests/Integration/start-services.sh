#!/usr/bin/env bash
# Starts fresh Unleash, Flagsmith and GrowthBook servers in Docker, creates the same three flags in each, and
# writes the keys the integration tests need to tests/Integration/.keys.env.
#
#   new-checkout  on for attribute plan = "pro", with the value {"colour": "blue"} ("blue" in Flagsmith)
#   kill-switch   off
#   rate-limit    on, with the value 100
#
# Stop them with: docker compose -f tests/Integration/compose.yaml down -v
set -euo pipefail

cd "$(dirname "$0")"
KEYS=.keys.env
JSON=(-H "Content-Type: application/json")

docker compose down -v --remove-orphans >/dev/null 2>&1 || true
docker compose up -d >/dev/null 2>&1

wait_for() {
    for _ in $(seq 1 90); do
        [ "$(curl -s -o /dev/null -w '%{http_code}' "$2")" = 200 ] && return 0
        sleep 2
    done
    echo "$1 did not start" >&2
    exit 1
}
wait_for Unleash http://localhost:54242/health
wait_for Flagsmith http://localhost:58000/health/liveness/
wait_for GrowthBook http://localhost:53100/healthcheck

# Unleash: the tokens are created at start-up by INIT_*_API_TOKENS in compose.yaml.
U=http://localhost:54242/api/admin/projects/default/features
UA=(-H "Authorization: *:*.kipchak-test-admin-token" "${JSON[@]}")
for flag in new-checkout kill-switch rate-limit; do
    curl -sf "${UA[@]}" -X POST "$U" -d "{\"name\":\"$flag\"}" >/dev/null
done
curl -sf "${UA[@]}" -X POST "$U/new-checkout/environments/development/strategies" -d '{
    "name": "flexibleRollout",
    "parameters": {"rollout": "100", "stickiness": "default", "groupId": "new-checkout"},
    "constraints": [{"contextName": "plan", "operator": "IN", "values": ["pro"]}],
    "variants": [{"name": "blue", "weight": 1000, "weightType": "variable", "stickiness": "default",
                  "payload": {"type": "json", "value": "{\"colour\":\"blue\"}"}}]
}' >/dev/null
curl -sf "${UA[@]}" -X POST "$U/rate-limit/environments/development/strategies" -d '{
    "name": "flexibleRollout",
    "parameters": {"rollout": "100", "stickiness": "default", "groupId": "rate-limit"},
    "variants": [{"name": "limit", "weight": 1000, "weightType": "variable", "stickiness": "default",
                  "payload": {"type": "number", "value": "100"}}]
}' >/dev/null
curl -sf "${UA[@]}" -X POST "$U/kill-switch/environments/development/strategies" -d '{"name": "default"}' >/dev/null
for flag in new-checkout rate-limit; do
    curl -sf "${UA[@]}" -X POST "$U/$flag/environments/development/on" >/dev/null
done

# Flagsmith: a user, organisation, project and environment, then the features, a "pro" segment and a server key.
F=http://localhost:58000/api/v1
curl -sf "${JSON[@]}" -X POST $F/auth/users/ -d '{"email": "admin@example.com", "password": "Kipchak-test-password-1",
    "re_password": "Kipchak-test-password-1", "first_name": "Test", "last_name": "Admin"}' >/dev/null
FLAGSMITH_ADMIN_TOKEN=$(curl -sf "${JSON[@]}" -X POST $F/auth/login/ \
    -d '{"email": "admin@example.com", "password": "Kipchak-test-password-1"}' | jq -r .key)
FA=(-H "Authorization: Token $FLAGSMITH_ADMIN_TOKEN" "${JSON[@]}")
ORG=$(curl -sf "${FA[@]}" -X POST $F/organisations/ -d '{"name": "Kipchak"}' | jq -r .id)
PROJECT=$(curl -sf "${FA[@]}" -X POST $F/projects/ -d "{\"name\": \"Flags\", \"organisation\": $ORG}" | jq -r .id)
ENVIRONMENT=$(curl -sf "${FA[@]}" -X POST $F/environments/ -d "{\"name\": \"Development\", \"project\": $PROJECT}")
ENV_ID=$(echo "$ENVIRONMENT" | jq -r .id)
FLAGSMITH_KEY=$(echo "$ENVIRONMENT" | jq -r .api_key)
CHECKOUT=$(curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/features/ \
    -d '{"name": "new-checkout", "default_enabled": false, "initial_value": "blue"}' | jq -r .id)
curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/features/ -d '{"name": "kill-switch", "default_enabled": false}' >/dev/null
curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/features/ \
    -d '{"name": "rate-limit", "default_enabled": true, "initial_value": "100"}' >/dev/null
SEGMENT=$(curl -sf "${FA[@]}" -X POST $F/projects/$PROJECT/segments/ -d "{\"name\": \"pro\", \"project\": $PROJECT,
    \"rules\": [{\"type\": \"ALL\", \"conditions\": [], \"rules\": [{\"type\": \"ANY\", \"rules\": [],
    \"conditions\": [{\"property\": \"plan\", \"operator\": \"EQUAL\", \"value\": \"pro\"}]}]}]}" | jq -r .id)
OVERRIDE=$(curl -sf "${FA[@]}" -X POST $F/features/feature-segments/ \
    -d "{\"feature\": $CHECKOUT, \"segment\": $SEGMENT, \"environment\": $ENV_ID}" | jq -r .id)
curl -sf "${FA[@]}" -X POST $F/features/featurestates/ -d "{\"feature\": $CHECKOUT, \"environment\": $ENV_ID,
    \"feature_segment\": $OVERRIDE, \"enabled\": true,
    \"feature_state_value\": {\"type\": \"unicode\", \"string_value\": \"blue\"}}" >/dev/null
FLAGSMITH_SERVER_KEY=$(curl -sf "${FA[@]}" -X POST "$F/environments/$FLAGSMITH_KEY/api-keys/" \
    -d '{"name": "kipchak-tests"}' | jq -r .key)

# GrowthBook: the first user, an admin API key, the features, and an SDK connection for the PHP SDK.
G=http://localhost:53100
GROWTHBOOK_JWT=$(curl -sf "${JSON[@]}" -X POST $G/auth/firsttime -d '{"companyname": "Kipchak",
    "email": "admin@example.com", "name": "Admin", "password": "Kipchak-test-password-1"}' | jq -r .token)
ORG=$(curl -sf -H "Authorization: Bearer $GROWTHBOOK_JWT" $G/user | jq -r '.organizations[0].id')
SECRET=$(curl -sf -H "Authorization: Bearer $GROWTHBOOK_JWT" -H "X-Organization: $ORG" "${JSON[@]}" -X POST $G/keys \
    -d '{"description": "kipchak tests", "type": "admin"}' | jq -r '.key.key // .key')
GA=(-H "Authorization: Bearer $SECRET" "${JSON[@]}")
curl -sf "${GA[@]}" -X POST $G/api/v1/features -d '{"id": "new-checkout", "owner": "admin@example.com",
    "valueType": "json", "defaultValue": "null", "environments": {"production": {"enabled": true, "rules": [
    {"type": "force", "condition": "{\"plan\":\"pro\"}", "value": "{\"colour\":\"blue\"}", "enabled": true}]}}}' >/dev/null
curl -sf "${GA[@]}" -X POST $G/api/v1/features -d '{"id": "kill-switch", "owner": "admin@example.com",
    "valueType": "boolean", "defaultValue": "false", "environments": {"production": {"enabled": true, "rules": []}}}' >/dev/null
curl -sf "${GA[@]}" -X POST $G/api/v1/features -d '{"id": "rate-limit", "owner": "admin@example.com",
    "valueType": "number", "defaultValue": "100", "environments": {"production": {"enabled": true, "rules": []}}}' >/dev/null
GROWTHBOOK_CLIENT_KEY=$(curl -sf "${GA[@]}" -X POST $G/api/v1/sdk-connections \
    -d '{"name": "kipchak-tests", "language": "php", "environment": "production"}' | jq -r .sdkConnection.key)

cat > $KEYS <<EOF
UNLEASH_URL=http://localhost:54242/api
UNLEASH_TOKEN=*:development.kipchak-test-client-token
UNLEASH_ADMIN_TOKEN=*:*.kipchak-test-admin-token
FLAGSMITH_URL=http://localhost:58000/api/v1
FLAGSMITH_ENVIRONMENT_KEY=$FLAGSMITH_KEY
FLAGSMITH_SERVER_KEY=$FLAGSMITH_SERVER_KEY
FLAGSMITH_ADMIN_TOKEN=$FLAGSMITH_ADMIN_TOKEN
GROWTHBOOK_API_HOST=http://localhost:53100
GROWTHBOOK_CLIENT_KEY=$GROWTHBOOK_CLIENT_KEY
GROWTHBOOK_SECRET=$SECRET
EOF

echo "Unleash, Flagsmith and GrowthBook are ready; keys are in tests/Integration/$KEYS"
