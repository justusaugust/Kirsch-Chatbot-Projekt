# KDCB Chatbot (Developer Notes)

WordPress plugin slug/namespace: `kdcb`  
Current plugin version: `1.1.8`

## What This Plugin Does

- Injects a sitewide floating Cherry chat widget (no build step, vanilla JS/CSS)
- Exposes REST endpoints:
  - `POST /wp-json/kdcb/v1/chat`
  - `POST /wp-json/kdcb/v1/submit_defect`
  - `GET /wp-json/kdcb/v1/config`
- Implements lightweight RAG without vector DB:
  - current page resolution + focused snippet extraction
  - WordPress search ranking by term hits
  - FAQ keyword matching (`Q:` / `A:` pairs from admin settings)
- Uses OpenAI Responses API server-side with `store: false` and no streaming
- Runs a separate 2-step Mängelformular flow via `wp_mail`
- Stores chat state only in browser `localStorage` (last 12 messages + session id)

## Architecture Snapshot

### Core files

- `kd-chatbot.php`: bootstrap, defaults, asset enqueue, `KDCB_CONFIG`
- `includes/admin-settings.php`: settings page, validation, optional test email button
- `includes/rest-chat.php`: chat route, deterministic guards, prompt assembly
- `includes/rest-defect.php`: defect route, validation, email dispatch
- `includes/rag.php`: page lookup, search ranking, FAQ matching, context assembly
- `includes/openai.php`: Responses API wrapper
- `includes/security.php`: origin check, IP-hashed transient rate limiting
- `assets/widget.js`: Cherry UI, chat flow, local draft/session storage
- `assets/widget.css`: visual system, responsive layout, typography

### UI behavior (current)

- Collapsed: Cherry launcher in the bottom-right corner
- Open: one assistant speech bubble (top-left from Cherry) + separate input field (bottom-right)
- Animated waiting dots while response is pending
- Dismissible privacy banner shown once per browser
- Mängelformular opens as a separate overlay card (strict 2-step flow)

## Installation and Setup

1. Build ZIP:
   ```bash
   cd /path/to/workspace
   zip -r kd-chatbot.zip kd-chatbot
   ```
2. WordPress Admin -> Plugins -> Add New -> Upload Plugin -> choose `kd-chatbot.zip`
3. Activate `KDCB Chatbot`
4. Configure under `Settings -> KDCB Chatbot`:
   - `Widget aktivieren`
   - `OpenAI API Key`
   - `Modell` (default `gpt-5.2`)
   - `System Instructions`
   - `Komprimierter Kontext (immer aktiv)`
   - `FAQ (Q/A Paare)`
   - `Empfänger E-Mail für Mängel`
   - `Chat Rate Limit` / `Defect Rate Limit`

## REST API Contracts

### `POST /wp-json/kdcb/v1/chat`

Request:

```json
{
  "session_id": "uuid-or-client-id",
  "page_url": "https://example.com/leistungen/",
  "page_title": "Leistungen",
  "messages": [
    {"role":"user","content":"Welche Leistungen bietet ihr?"}
  ]
}
```

Response:

```json
{
  "reply": "string",
  "sources": [{"title":"...", "url":"..."}],
  "action": null
}
```

Possible action:

```json
{"type":"show_defect_form"}
```

### `POST /wp-json/kdcb/v1/submit_defect`

Request fields:

- required: `full_name`, `email`, `object_address`, `trade`, `defect_location`, `defect_description`, `urgency`
- optional: `phone`, `callback_requested`, `page_url`, `session_id`

Response:

```json
{"ok":true}
```

## Security, Privacy, and Storage Rules

- No custom tables; no chat logs in WP DB
- No server-side conversation persistence
- OpenAI calls always include `store: false`
- Defect form payload is never sent to `/chat` or OpenAI
- Origin validation: if `Origin` header exists, host must match `site_url()`
- Rate limiting via transients keyed by hashed IP + time bucket
  - chat: default `60/hour/IP`
  - defect: default `5/day/IP`

## Prompt and Context Strategy

### RAG order

1. `CURRENT_PAGE` (high priority)
2. `WP_SEARCH` snippets (medium priority)
3. `FAQ_MATCHES` (low priority)

### Prompt structure

- Admin-configured `System Instructions` + `Context Pack`
- Behavior pack for concise, service-oriented, company-voice responses
- Dynamic rules toggled by intent category:
  - prompt injection
  - vague/short input
  - legal-sensitive requests
  - reputational allegations
  - long-form request

### Deterministic guardrails (before LLM call)

- Injection attempt detection -> fixed safe refusal
- Vague short message detection -> short clarifying options
- Defect keywords or explicit trigger -> `action: show_defect_form`
- Status ping handling (`[DEFECT_FORM_OPENED]`, `[DEFECT_FORM_SUBMITTED]`)

### Post-processing guardrails (after LLM call)

- Removes unsolicited `Quelle:` lines unless user explicitly asks for sources
- Rewrites weak/meta phrasing to direct service voice
- Converts markdown pipe tables into list format
- Enforces reputational disclaimer prefix when needed

## Prompt Optimization Findings (from 15-case Eval)

Date of run: `2026-02-14`  
Result file: `output/evals/kdcb_test_results_v5.json`

### Category summary

| Category | Result | Notes |
|---|---:|---|
| Prompt injection | 3/3 pass | No prompt leakage, no key disclosure |
| Retrieval general | 3/3 pass | Context routing and source recall stable |
| Legal/difficult | 2/3 pass, 1 partial | Safe refusal works; one case still too template-like |
| Long output | 2/3 pass, 1 partial | Rich output; one case showed truncation tendency |
| Vague inputs | 3/3 pass | Consistent short clarification response |

### What improved after optimization

- Stronger deterministic blocking for injection and jailbreak phrasing
- Better retrieval ranking by `term_hits`; zero-hit noise filtered when positive-hit results exist
- Clearer legal-sensitive handling instructions in the system prompt
- Cleaner UX text style: less meta-search language, more direct service tone
- Markdown stability: no pipe-table output leaking into frontend renderer

### Open gaps and next iteration

- Tighten legal safety further:
  - block generation of legal document templates for requests with "guaranteed/legal-proof" intent
- Reduce long-output truncation:
  - add completion check + optional one-time retry with higher `max_output_tokens`
- Add deterministic "strict short mode" override for normal intents to cap verbosity drift

## Visual Design Tokens (KUD-aligned)

- Primary: `#D1AF1A`
- Primary hover: `#C4A002`
- Navy: `#071424`
- Surface: `#F5F6F6`
- White: `#FDFDFD`
- Border: `#D9DDE3`
- Body font: `Inter`
- Heading/accent font: `Outfit`
- Radius: `8px` inputs/buttons, `16px+` cards/bubbles

## Manual API Testing

Set base URL:

```bash
BASE="http://localhost:8881"
```

Chat:

```bash
curl -X POST "$BASE/wp-json/kdcb/v1/chat" \
  -H 'Content-Type: application/json' \
  -d '{
    "session_id":"test-session-123",
    "page_url":"'"$BASE"'/",
    "page_title":"Startseite",
    "messages":[{"role":"user","content":"Welche Leistungen bietet ihr?"}]
  }'
```

Defect submit:

```bash
curl -X POST "$BASE/wp-json/kdcb/v1/submit_defect" \
  -H 'Content-Type: application/json' \
  -d '{
    "session_id":"test-session-123",
    "page_url":"'"$BASE"'/",
    "full_name":"Max Mustermann",
    "email":"max@example.com",
    "phone":"0123456789",
    "object_address":"Musterstrasse 1, 12345 Musterstadt",
    "trade":"Fenster",
    "defect_location":"EG Wohnzimmer",
    "defect_description":"Am Fensterrahmen ist ein Riss sichtbar.",
    "urgency":"mittel",
    "callback_requested":true
  }'
```

## Useful Dev Checks

```bash
# Confirm no defect fields enter OpenAI path
rg -n "defect_description|object_address|full_name|submit_defect" kd-chatbot/includes

# Confirm Responses API storage disabled
rg -n "store\\s*=>\\s*false|\"store\"\\s*:\\s*false" kd-chatbot

# Confirm deterministic guards exist
rg -n "is_injection_attempt|is_vague_input|is_legal_sensitive|strip_markdown_tables" kd-chatbot/includes/rest-chat.php
```
