# HTTP API: OpenAPI description

The HTTP API ([api.md](15-api.md)) is described by an OpenAPI 3.1 document, so a client, an SDK generator or a test tool can read the endpoints, scopes, parameters, response shapes and error format without reading this documentation.

## Getting it

- From a host: `GET /api/v1/openapi.json` with any valid token that has the `read` scope. It describes the API only, never the host.
- From the repository: `app/resources/api/openapi.yaml`. The endpoint serves that file as JSON.

```
curl -fsS -H "Authorization: Bearer $FLEET_TOKEN" https://fleet.example.com/api/v1/openapi.json
```

Each operation carries `x-scope`, the scope the token needs (`read`, `deploy`, `lifecycle`, `lifecycle:destroy`, or `any`). Every error response is `application/problem+json` (the `Problem` schema).

## How it is kept true

The file is maintained by hand (Nelmio and API Platform were considered and rejected: the endpoints are job actions, not CRUD, and annotating every controller would put the same facts in two places). A contract test, `app/tests/Functional/Api/OpenApiContractTest.php`, fails when:

- a `/api/v1` route has no operation in the file, or an operation has no route;
- an operation lacks an `operationId`, a summary, an `x-scope` or responses;
- a real response (status, content type, required headers, body) does not fit its schema.

When you add or change an endpoint, change the YAML in the same commit.

## Compatibility

Inside `/api/v1` changes are additive: a new optional field, endpoint or enum value. Clients should ignore unknown fields. A breaking change would be served as `/api/v2` next to v1.
