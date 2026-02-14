#!/usr/bin/env bash
set -euo pipefail

BASE="http://localhost:8881/wp-json/kdcb/v1/chat"
PAGE="http://localhost:8881/"
DELAY=3

send() {
    local id="$1" desc="$2" payload="$3"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Q${id}: ${desc}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    local resp
    resp=$(curl -s -X POST "$BASE" -H "Content-Type: application/json" -d "$payload" --max-time 30)
    echo "$resp" | jq -r '.reply' 2>/dev/null
    echo ""
    local inp out
    inp=$(echo "$resp" | jq -r '.usage.input_tokens // "-"' 2>/dev/null)
    out=$(echo "$resp" | jq -r '.usage.output_tokens // "-"' 2>/dev/null)
    echo "[tokens: in=$inp out=$out]"
    echo ""
    sleep "$DELAY"
}

echo "KDCB Quality Evaluation — Baseline (pre-tool)"
echo "$(date)"
echo ""

# Q1: Specific factual — leadership (tests if RAG finds the right page)
send 1 "Specific factual: Wer leitet K&D?" \
    '{"messages":[{"role":"user","content":"Wer ist die Geschäftsführung von Kirsch und Drechsler?"}],"page_url":"'"$PAGE"'","page_title":"Startseite"}'

# Q2: Broad overview (tests RAG breadth across multiple pages)
send 2 "Broad overview: Was bietet K&D alles an?" \
    '{"messages":[{"role":"user","content":"Gebt mir bitte einen Überblick über alle Leistungen und Angebote von K&D."}],"page_url":"'"$PAGE"'","page_title":"Startseite"}'

# Q3: Multi-turn with vague follow-up (known RAG weakness)
send 3 "Multi-turn vague follow-up: Erzählt mir mehr darüber" \
    '{"messages":[{"role":"user","content":"Was macht Kirsch und Drechsler?"},{"role":"assistant","content":"Kirsch & Drechsler Hausbau GmbH entwickelt, verkauft und verwaltet Wohnimmobilien in Potsdam und Umgebung. Unsere Kernleistungen umfassen **Beratung**, **Projektentwicklung & Bauträger** sowie **Hausverwaltung & Vermietung**."},{"role":"user","content":"Erzählt mir mehr darüber."}],"page_url":"'"$PAGE"'","page_title":"Startseite"}'

# Q4: Multi-turn with anaphoric reference (tests if "dafür" resolves correctly)
send 4 "Multi-turn anaphoric reference: Wie bewerbe ich mich dafür?" \
    '{"messages":[{"role":"user","content":"Gibt es offene Stellen bei euch?"},{"role":"assistant","content":"Aktuelle Stellenangebote finden Sie auf unserer Karriereseite. Wir suchen regelmäßig Verstärkung in verschiedenen Bereichen."},{"role":"user","content":"Was für Stellen sind das genau?"},{"role":"assistant","content":"Konkrete offene Stellen entnehmen Sie bitte unserer Karriereseite. Dort finden Sie aktuelle Ausschreibungen mit Details zu Anforderungen und Aufgaben."},{"role":"user","content":"Und wie bewerbe ich mich dafür?"}],"page_url":"'"$PAGE"'","page_title":"Startseite"}'

# Q5: Cross-topic switch in conversation
send 5 "Cross-topic switch: defect → rental" \
    '{"messages":[{"role":"user","content":"Ich habe Schimmel in meiner Wohnung entdeckt."},{"role":"assistant","content":"Schimmel sollte schnell behandelt werden. Sofortmaßnahmen:\n\n- **Lüften** Sie den betroffenen Raum gut durch.\n- **Möbel** nicht direkt an die betroffene Wand stellen.\n- **Dokumentieren** Sie den Befall mit Fotos.\n\nFür eine offizielle Meldung nutzen Sie bitte den Button **„Mängel melden\"**."},{"role":"user","content":"Danke für die Tipps. Übrigens, habt ihr aktuell auch Mietwohnungen im Angebot?"}],"page_url":"'"$PAGE"'","page_title":"Startseite"}'

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Baseline evaluation complete."
