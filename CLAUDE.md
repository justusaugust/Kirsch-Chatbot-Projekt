# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress chatbot plugin (`kd-chatbot`) for Kirsch & Drechsler Hausbau GmbH. Floating Cherry mascot widget with RAG-powered chat (OpenAI gpt-5.2), a separate Mängelformular (defect reporting via email), and deterministic safety guardrails. No build step, no npm, no frameworks — vanilla PHP/JS/CSS.

See `kd-chatbot/README.md` for full architecture details, API contracts, and security model.

## Local Development Environment

**WP Studio** serves the site at `http://localhost:8881/`.

Plugin files live at: `~/.studio/sites/kd-test/wp-content/plugins/kd-chatbot/`

### Deploying changes

Copy updated files from the project into WP Studio's plugin directory:

```bash
yes | cp kd-chatbot/includes/*.php ~/.studio/sites/kd-test/wp-content/plugins/kd-chatbot/includes/
yes | cp kd-chatbot/assets/widget.js ~/.studio/sites/kd-test/wp-content/plugins/kd-chatbot/assets/widget.js
yes | cp kd-chatbot/assets/widget.css ~/.studio/sites/kd-test/wp-content/plugins/kd-chatbot/assets/widget.css
```

No server restart needed — PHP changes apply immediately. JS/CSS changes require a hard refresh (Cmd+Shift+R) in the browser.

### Building the distribution ZIP

```bash
cd kd-chatbot && zip -r ../kd-chatbot.zip . -x '*.DS_Store' && cd ..
```

Upload via WordPress Admin > Plugins > Add New > Upload Plugin (or replace files at the WP Studio path).

### WP Admin

- URL: `http://localhost:8881/wp-admin/`
- Username: `admin`
- Plugin settings: Settings > KDCB Chatbot

### WP-CLI

Available at `/opt/homebrew/bin/wp`. Very noisy with PHP 8.4 deprecation warnings — always filter output:

```bash
# List options
wp option list --search='kdcb_*' --url=http://localhost:8881 2>/dev/null | grep -v "^Deprecated\|^PHP Deprecated"

# Read a specific option
wp option get kdcb_token_log --format=json --url=http://localhost:8881 2>/dev/null | grep -v "Deprecated" | jq '.'

# Update an option
wp option update kdcb_model 'gpt-5.2' --url=http://localhost:8881 2>/dev/null
```

## Testing

### Manual chat test via curl

```bash
curl -s -X POST "http://localhost:8881/wp-json/kdcb/v1/chat" \
  -H "Content-Type: application/json" \
  -d '{"messages":[{"role":"user","content":"Welche Leistungen bietet ihr?"}],"page_url":"http://localhost:8881/","page_title":"Startseite"}' \
  | jq '{reply: .reply, usage: .usage}'
```

### Token usage test suite (20 scenarios)

```bash
bash test-token-usage.sh
```

Sends 10 single-turn and 10 multi-turn requests, outputs a table with input/output tokens and cost per request. Results saved to `/tmp/kdcb_token_results.tsv`.

### Evaluation test matrix

Previous eval results in `output/evals/kdcb_test_results_v5.json` (15 cases across 5 categories). Test definitions in `output/evals/kdcb_test_matrix.json`.

## Architecture (Cross-File Flow)

### Chat request lifecycle

1. **Frontend** (`widget.js`): User sends message → builds `{messages, page_url, page_title}` → POST to `/kdcb/v1/chat`
2. **Validation** (`rest-chat.php`): Origin check → rate limit → payload size → message sanitization (last 12, user max 1500 chars, assistant max 2000 chars)
3. **Deterministic guards** (`rest-chat.php`): Run *before* any LLM call. Short-circuit for: injection attempts, vague inputs, defect form triggers, navigation intents, status pings, wrongdoing requests, out-of-scope requests. Each returns a fixed response — no API cost.
4. **RAG context assembly** (`rag.php`): Resolve current page → WordPress search (up to 3 queries: primary keywords, intent-rewritten, fallback by page title) → FAQ keyword matching → rank by term hits → assemble `[CURRENT_PAGE]` + `[WP_SEARCH]` + `[FAQ_MATCHES]` blocks
5. **System prompt assembly** (`rest-chat.php`, `kdcb_chat_build_system_prompt`): Admin instructions + ~35 behavioral rules + conditional rules (reputation/legal/long-output) + context pack + FAQ compact + RAG context. Rebuilt from scratch every turn.
6. **LLM call** (`openai.php`): OpenAI Responses API, `store: false`, max 420 output tokens (1600 for "ausführlich" requests). Returns text + usage stats.
7. **Post-processing** (`rest-chat.php`): Strip unsolicited source lines → rewrite weak/meta phrasing → convert tables to lists → enforce reputation disclaimer → append missing context links
8. **Response**: `{reply, sources, action, usage}`

### Key design constraint: RAG uses only the latest message

`kdcb_rag_build_context()` extracts search terms from the *latest user message only*. The full chat history is sent to the LLM so it understands conversational context, but the retrieved content (search results, page snippets) is based solely on the newest message's keywords. Follow-up questions like "tell me more about that" produce weak RAG context because "that" gets stopword-filtered.

### Token logging

`kdcb_log_token_usage()` in `rest-chat.php` appends to the `kdcb_token_log` WP option (capped at 500 entries). Each entry: `{ts, model, input, output, total}`. The `usage` field is also returned in every chat API response.

## Cost Model (gpt-5.2)

| | Per 1M tokens |
|---|---|
| Input | $1.75 |
| Cached input | $0.175 |
| Output | $14.00 |

Benchmarked averages (20 tests, 2026-02-14): ~2,034 input tokens, ~148 output tokens per turn. System prompt dominates input (~1,700 tokens); chat history adds only ~200-500 tokens even at 6 turns. Average cost per chat turn: ~$0.005. At 50 chats/day: ~$8/month.

## Code Conventions

- **PHP**: WordPress coding standards. All functions prefixed `kdcb_`. Options stored as `kdcb_*` in `wp_options`.
- **JS**: Vanilla ES5 IIFE, no modules/imports. All DOM classes prefixed `kdcb-`. State stored in a single `state` object. localStorage keys versioned (`_v3`).
- **CSS**: Custom properties prefixed `--kdcb-`. No preprocessor. Typography: Inter (body), Outfit (headings).
- **German UI text**: Hardcoded in PHP (chat replies, error messages) and JS (UI strings). The chatbot speaks German by default; the system prompt enforces this.
- **Terminology**: Use "Feststellung" not "Mangel" when speaking as the company (per client preference). In code/comments, "defect" and "Mängel" are fine.
- **No build step**: Edit `.js`/`.css`/`.php` files directly. No transpilation, bundling, or minification.
