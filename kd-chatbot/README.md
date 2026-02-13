# KDCB Chatbot (Developer Notes)

WordPress plugin slug/namespace: `kdcb`

## Features

- Sitewide floating chat widget (`assets/widget.js`, `assets/widget.css`)
- REST endpoints:
  - `POST /wp-json/kdcb/v1/chat`
  - `POST /wp-json/kdcb/v1/submit_defect`
  - `GET /wp-json/kdcb/v1/config`
- Lightweight RAG:
  - Current page context via `url_to_postid()` / path fallback
  - WordPress fulltext search snippets (top 3)
  - FAQ keyword matching from admin settings
- OpenAI Responses API server-side with `store: false`
- Separate defect form flow (never sent to LLM)
- Chat is not persisted in DB (browser localStorage only)

## Install on WordPress test site

1. Zip plugin folder:
   ```bash
   cd /path/to/workspace
   zip -r kd-chatbot.zip kd-chatbot
   ```
2. In WP Admin: Plugins -> Add New -> Upload Plugin -> choose `kd-chatbot.zip`
3. Activate plugin.
4. Go to Settings -> KDCB Chatbot and configure:
   - OpenAI API key
   - Model (`gpt-5.2` default)
   - System instructions
   - FAQ entries (`Q: ...` / `A: ...` blocks)
   - Defect recipient email

## API test snippets

Set your base URL:

```bash
BASE="https://your-wp-site.example"
```

### Chat endpoint

```bash
curl -X POST "$BASE/wp-json/kdcb/v1/chat" \
  -H 'Content-Type: application/json' \
  -d '{
    "session_id": "test-session-123",
    "page_url": "https://your-wp-site.example/",
    "page_title": "Startseite",
    "messages": [
      {"role":"user","content":"Welche Leistungen bietet K&D Hausbau?"}
    ]
  }'
```

Expected shape:

```json
{
  "reply": "...",
  "sources": [{"title":"...","url":"..."}],
  "action": null
}
```

### Defect submit endpoint

```bash
curl -X POST "$BASE/wp-json/kdcb/v1/submit_defect" \
  -H 'Content-Type: application/json' \
  -d '{
    "session_id": "test-session-123",
    "page_url": "https://your-wp-site.example/",
    "full_name": "Max Mustermann",
    "email": "max@example.com",
    "phone": "0123456789",
    "object_address": "Musterstrasse 1, 12345 Musterstadt",
    "trade": "Fenster",
    "defect_location": "EG Wohnzimmer",
    "defect_description": "Am Fensterrahmen ist ein Riss sichtbar.",
    "urgency": "mittel",
    "callback_requested": true
  }'
```

Expected shape:

```json
{"ok":true}
```

## Privacy and storage notes

- No custom DB tables are created.
- No server-side conversation persistence.
- OpenAI Responses requests use `store:false`.
- Defect form fields are never forwarded to OpenAI.

## Useful code checks

```bash
# Verify no defect form fields are sent through OpenAI wrapper path
rg -n "defect_description|object_address|full_name|submit_defect" kd-chatbot/includes

# Verify OpenAI requests explicitly disable storage
rg -n "store\s*=>\s*false|\"store\"\s*:\s*false" kd-chatbot
```
