# API contract

Base path: `/api/v1`. JSON only. Source of truth for backend and frontend agents.

## Phase 0

### GET /api/v1/health
- Auth: none (public)
- Scope: none (no company data)
- Request: no body, no query parameters
- Response 200:
  ```json
  { "status": "ok" }
  ```
- Errors: none expected. Any unhandled failure returns 500 with no stack trace (APP_DEBUG=false in production).
