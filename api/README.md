# /api

The JSON endpoints used by the dashboard UI are served by the front controller in
`public/index.php`, not by separate scripts in this folder.

Where the API code actually lives:

| Concern | Path |
|---|---|
| Routes (prefixed `/api`, behind the `auth` middleware) | `routes/api.php` |
| AI endpoints | `app/Controllers/AiController.php` |
| Client lookup / quick create | `app/Controllers/ClientController.php` |

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/ai/document` | Draft a document from a plain-language requirement |
| POST | `/api/ai/write` | Writing tools: `improve`, `rewrite`, `professional`, `shorter`, `expand`, `grammar` |
| POST | `/api/ai/email` | Draft a covering email for a saved document |
| POST | `/api/ai/terms` | Generate terms & conditions |
| POST | `/api/documents/calculate` | Server-side recalculation of subtotal, discount, tax and total |
| GET | `/api/clients` | Search the signed-in user's clients |
| POST | `/api/clients` | Create a client from the wizard modal |

All endpoints are same-origin and session authenticated. Non-GET requests require the
CSRF token, sent by `public/assets/js/app.js` as the `X-CSRF-Token` header.
Every OpenRouter call happens server-side — the API key is never sent to the browser.

This folder is blocked from direct web access by the root `.htaccess`.
