#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Token usage test runner for KDCB Chatbot
# Sends 20 realistic requests to the live WP endpoint
# and collects usage stats from the response.
# ──────────────────────────────────────────────────────────

BASE_URL="${KDCB_BASE_URL:-http://localhost:8881/wp-json/kdcb/v1/chat}"
PAGE_URL="${KDCB_PAGE_URL:-http://localhost:8881/}"
PAGE_TITLE="${KDCB_PAGE_TITLE:-Startseite}"
RESULTS_FILE="/tmp/kdcb_token_results.tsv"
DELAY="${KDCB_DELAY:-2}"  # seconds between requests to avoid rate limiting

# Cost per 1M tokens (estimates for common models)
# gpt-4.1-mini: $0.40 input / $1.60 output
# gpt-4.1:      $2.00 input / $8.00 output
# gpt-4o:       $2.50 input / $10.00 output
INPUT_COST_PER_M=1.75
OUTPUT_COST_PER_M=14.00

echo "KDCB Token Usage Test"
echo "Endpoint: $BASE_URL"
echo "Page context: $PAGE_URL"
echo "=========================================="
echo ""

# Write TSV header
printf "ID\tType\tTurns\tDescription\tInput\tOutput\tTotal\tCost_USD\n" > "$RESULTS_FILE"

send_chat() {
    local id="$1"
    local type="$2"
    local turns="$3"
    local desc="$4"
    local payload="$5"

    local response
    response=$(curl -s -X POST "$BASE_URL" \
        -H "Content-Type: application/json" \
        --max-time 30 \
        -d "$payload" 2>/dev/null) || { echo "  ✗ #$id FAILED (curl error)"; return; }

    local input_tokens output_tokens total_tokens reply_preview
    input_tokens=$(echo "$response" | jq -r '.usage.input_tokens // 0' 2>/dev/null)
    output_tokens=$(echo "$response" | jq -r '.usage.output_tokens // 0' 2>/dev/null)
    total_tokens=$(echo "$response" | jq -r '.usage.total_tokens // 0' 2>/dev/null)
    reply_preview=$(echo "$response" | jq -r '.reply // "NO_REPLY"' 2>/dev/null | head -c 60)

    if [ "$total_tokens" = "0" ] || [ "$total_tokens" = "null" ]; then
        echo "  ⚠ #$id no usage data (plugin may not be updated yet)"
        printf "%s\t%s\t%s\t%s\t-\t-\t-\t-\n" "$id" "$type" "$turns" "$desc" >> "$RESULTS_FILE"
        return
    fi

    # Cost calculation
    local cost
    cost=$(awk "BEGIN { printf \"%.5f\", ($input_tokens * $INPUT_COST_PER_M / 1000000) + ($output_tokens * $OUTPUT_COST_PER_M / 1000000) }")

    printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n" \
        "$id" "$type" "$turns" "$desc" "$input_tokens" "$output_tokens" "$total_tokens" "$cost" >> "$RESULTS_FILE"

    echo "  ✓ #$id $desc → in:${input_tokens} out:${output_tokens} (\$${cost})"
}

# Helper to build JSON payload
msg() { printf '{"role":"%s","content":"%s"}' "$1" "$2"; }
payload() {
    local msgs="$1"
    printf '{"messages":[%s],"page_url":"%s","page_title":"%s"}' "$msgs" "$PAGE_URL" "$PAGE_TITLE"
}

echo "── Single-turn tests (1 user message, no history) ──"

send_chat 1 single 1 "Company overview" \
    "$(payload "$(msg user "Was macht Kirsch und Drechsler?")")"
sleep "$DELAY"

send_chat 2 single 1 "Services question" \
    "$(payload "$(msg user "Welche Leistungen bietet ihr an?")")"
sleep "$DELAY"

send_chat 3 single 1 "Job inquiry" \
    "$(payload "$(msg user "Gibt es offene Stellen bei euch?")")"
sleep "$DELAY"

send_chat 4 single 1 "Contact info" \
    "$(payload "$(msg user "Wie kann ich euch erreichen? Telefon oder E-Mail?")")"
sleep "$DELAY"

send_chat 5 single 1 "Property purchase" \
    "$(payload "$(msg user "Ich suche eine Eigentumswohnung in Potsdam. Welche Projekte gibt es aktuell?")")"
sleep "$DELAY"

send_chat 6 single 1 "Defect report" \
    "$(payload "$(msg user "In meiner Wohnung ist ein Wasserschaden im Badezimmer aufgetreten, was soll ich tun?")")"
sleep "$DELAY"

send_chat 7 single 1 "Rental inquiry" \
    "$(payload "$(msg user "Ich suche eine Mietwohnung in Potsdam. Habt ihr aktuell etwas frei?")")"
sleep "$DELAY"

send_chat 8 single 1 "Leadership question" \
    "$(payload "$(msg user "Wer leitet das Unternehmen?")")"
sleep "$DELAY"

send_chat 9 single 1 "Legal topic" \
    "$(payload "$(msg user "Wie läuft eine Kündigung des Mietvertrags ab?")")"
sleep "$DELAY"

send_chat 10 single 1 "Long detailed question" \
    "$(payload "$(msg user "Ich bin Eigentümer einer Wohnung in Potsdam und überlege, die Verwaltung an eine professionelle Hausverwaltung abzugeben. Welche Leistungen bietet ihr genau an und wie läuft der Prozess ab?")")"
sleep "$DELAY"

echo ""
echo "── Multi-turn tests (with conversation history) ──"

# 11: 2-turn follow-up
send_chat 11 multi 2 "2-turn follow-up" \
    "$(payload "$(msg user "Was macht Kirsch und Drechsler?"),$(msg assistant "Kirsch & Drechsler Hausbau GmbH entwickelt, verkauft und verwaltet Wohnimmobilien in Potsdam und Umgebung. Unsere Kernleistungen umfassen Beratung, Projektentwicklung und Bauträgerschaft sowie Hausverwaltung und Vermietung."),$(msg user "Erzählt mir mehr über die Hausverwaltung.")")"
sleep "$DELAY"

# 12: 3-turn defect flow
send_chat 12 multi 3 "3-turn defect flow" \
    "$(payload "$(msg user "Mein Fenster hat einen Riss."),$(msg assistant "Das sollten Sie zeitnah dokumentieren. Hier drei Sofortmaßnahmen: 1) Fotografieren Sie den Schaden. 2) Vermeiden Sie weitere Belastung des Fensters. 3) Lüften Sie nicht über das beschädigte Fenster. Für eine offizielle Meldung nutzen Sie bitte den Button Mängel melden."),$(msg user "Der Riss ist im Schlafzimmer, circa 20 Zentimeter lang."),$(msg assistant "Verstanden. Ein 20 cm langer Riss im Schlafzimmerfenster sollte zeitnah behoben werden, insbesondere wegen Wärmedämmung und Sicherheit. Bitte melden Sie den Schaden über unser Mängelformular, damit wir einen Handwerker beauftragen können."),$(msg user "Wie schnell kann das repariert werden?")")"
sleep "$DELAY"

# 13: 4-turn services deep-dive
send_chat 13 multi 4 "4-turn services deep-dive" \
    "$(payload "$(msg user "Welche Leistungen bietet ihr an?"),$(msg assistant "Unsere Kernleistungen sind Beratung, Projektentwicklung und Bauträgerschaft sowie Hausverwaltung und Vermietung. Wir betreuen Wohnimmobilien in Potsdam und Umgebung."),$(msg user "Was genau macht die Hausverwaltung?"),$(msg assistant "Die Hausverwaltung umfasst die kaufmännische und technische Betreuung von Wohnimmobilien. Dazu gehören Mieterverwaltung, Nebenkostenabrechnungen, Instandhaltungsmanagement und die Kommunikation mit Eigentümern und Mietern."),$(msg user "Und die Projektentwicklung?"),$(msg assistant "In der Projektentwicklung planen und realisieren wir Neubauprojekte. Wir kümmern uns um Grundstückssuche, Planung, Baugenehmigungen und die Vermarktung der fertigen Wohnungen."),$(msg user "Was kostet die Hausverwaltung ungefähr?")")"
sleep "$DELAY"

# 14: 3-turn job topic
send_chat 14 multi 3 "3-turn job inquiry" \
    "$(payload "$(msg user "Gibt es Jobs bei euch?"),$(msg assistant "Aktuelle Stellenangebote finden Sie auf unserer Karriereseite. Wir suchen regelmäßig Verstärkung in verschiedenen Bereichen."),$(msg user "Was für Stellen sind aktuell offen?"),$(msg assistant "Konkrete offene Stellen entnehmen Sie bitte unserer Karriereseite. Dort finden Sie aktuelle Ausschreibungen mit Details zu Anforderungen und Aufgaben."),$(msg user "Wie kann ich mich bewerben?")")"
sleep "$DELAY"

# 15: 5-turn property buying
send_chat 15 multi 5 "5-turn property buying" \
    "$(payload "$(msg user "Ich möchte eine Wohnung kaufen."),$(msg assistant "Gern helfen wir Ihnen dabei. Wir entwickeln und verkaufen Wohnimmobilien in Potsdam und Umgebung. Haben Sie schon eine bestimmte Lage oder Größe im Blick?"),$(msg user "Etwas in Potsdam Zentrum, 3 Zimmer wäre ideal."),$(msg assistant "In Potsdam Zentrum haben wir verschiedene Projekte. Für konkrete Verfügbarkeiten und Preise empfehlen wir ein persönliches Beratungsgespräch, da sich das Angebot laufend ändert."),$(msg user "Was kosten die Wohnungen ungefähr?"),$(msg assistant "Die Preise variieren je nach Lage, Größe und Ausstattung. Für eine 3-Zimmer-Wohnung in Potsdam Zentrum sollten Sie mit einem marktüblichen Preisniveau rechnen. Genaue Preise nennen wir Ihnen gern im persönlichen Gespräch."),$(msg user "Wie läuft der Kaufprozess ab?"),$(msg assistant "Der Kaufprozess beginnt mit einer Beratung und Besichtigung. Danach folgen Reservierung, Kaufvertragsvorbereitung durch den Notar und die notarielle Beurkundung. Wir begleiten Sie durch jeden Schritt."),$(msg user "Kann ich eine Besichtigung vereinbaren?")")"
sleep "$DELAY"

# 16: 6-turn extended defect conversation
send_chat 16 multi 6 "6-turn extended defect" \
    "$(payload "$(msg user "Ich habe Schimmel in der Wohnung entdeckt."),$(msg assistant "Schimmel sollte schnell behandelt werden. Sofortmaßnahmen: 1) Lüften Sie den Raum gut durch. 2) Stellen Sie keine Möbel direkt an die betroffene Wand. 3) Dokumentieren Sie den Befall mit Fotos. Für eine offizielle Meldung nutzen Sie bitte den Button Mängel melden."),$(msg user "Es ist an der Außenwand im Schlafzimmer."),$(msg assistant "Schimmel an der Außenwand deutet oft auf ein Feuchtigkeitsproblem hin, etwa mangelnde Dämmung oder Wärmebrücken. Das muss fachgerecht untersucht werden. Bitte melden Sie den Schaden offiziell über das Mängelformular."),$(msg user "Ist das gesundheitsschädlich?"),$(msg assistant "Schimmel kann gesundheitliche Auswirkungen haben, besonders bei empfindlichen Personen. Bis zur Behebung empfehlen wir regelmäßiges Lüften und möglichst wenig Aufenthalt im betroffenen Raum."),$(msg user "Wie lange dauert die Behebung normalerweise?"),$(msg assistant "Die Dauer hängt vom Umfang des Befalls und der Ursache ab. Nach einer Fachbegutachtung können wir den Zeitrahmen besser einschätzen. Kleinere Befälle werden meist innerhalb weniger Tage behandelt."),$(msg user "Wer trägt die Kosten dafür?"),$(msg assistant "Bei baulichen Mängeln wie Wärmebrücken trägt in der Regel der Vermieter bzw. die Hausverwaltung die Kosten. Eine genaue Klärung erfolgt nach der Begutachtung."),$(msg user "Gut, ich melde es jetzt offiziell. Danke für die Infos.")")"
sleep "$DELAY"

# 17: 2-turn vague then specific
send_chat 17 multi 2 "2-turn vague to specific" \
    "$(payload "$(msg user "Ich brauche Hilfe mit meiner Wohnung."),$(msg assistant "Gern. Wobei genau können wir helfen? Geht es um einen Mangel oder Schaden, eine Frage zur Verwaltung, oder etwas anderes?"),$(msg user "Es geht um einen Schaden. Die Heizung in meinem Wohnzimmer funktioniert nicht mehr seit drei Tagen.")")"
sleep "$DELAY"

# 18: 3-turn with ausführlich request
send_chat 18 multi 3 "3-turn with ausführlich" \
    "$(payload "$(msg user "Eure Leistungen?"),$(msg assistant "Unsere Kernleistungen: Beratung, Projektentwicklung und Bauträger, Hausverwaltung und Vermietung. Wir betreuen Wohnimmobilien in Potsdam und Umgebung."),$(msg user "Details zur Hausverwaltung?"),$(msg assistant "Die Hausverwaltung umfasst kaufmännische und technische Betreuung: Mieterverwaltung, Nebenkostenabrechnungen, Instandhaltung und Kommunikation mit allen Beteiligten."),$(msg user "Bitte erklärt das ausführlich und detailliert.")")"
sleep "$DELAY"

# 19: 4-turn topic switch
send_chat 19 multi 4 "4-turn topic switch" \
    "$(payload "$(msg user "Habt ihr Wohnungen zu verkaufen?"),$(msg assistant "Ja, wir entwickeln und verkaufen Wohnimmobilien in Potsdam und Umgebung. Für aktuelle Objekte und Preise beraten wir Sie gern persönlich."),$(msg user "In welcher Preisklasse bewegen sich die Wohnungen?"),$(msg assistant "Die Preise variieren nach Lage, Größe und Ausstattung. Für konkrete Zahlen vereinbaren Sie am besten ein Beratungsgespräch mit uns."),$(msg user "Und was ist mit Mietwohnungen? Habt ihr da auch etwas?"),$(msg assistant "Ja, wir verwalten und vermieten ebenfalls Wohnungen. Verfügbare Mietobjekte finden Sie auf unserer Website oder kontaktieren Sie uns direkt."),$(msg user "Wie erreiche ich euch am besten für eine Besichtigung?")")"
sleep "$DELAY"

# 20: 6-turn complex mixed topics
send_chat 20 multi 6 "6-turn mixed topics" \
    "$(payload "$(msg user "Hallo, ich bin neu in Potsdam und suche eine Wohnung."),$(msg assistant "Willkommen in Potsdam! Wir helfen Ihnen gern bei der Wohnungssuche. Suchen Sie eine Mietwohnung oder möchten Sie kaufen?"),$(msg user "Erstmal mieten. Was habt ihr aktuell im Angebot?"),$(msg assistant "Unsere verfügbaren Mietobjekte finden Sie auf unserer Website im Bereich Mieten. Für aktuelle Verfügbarkeiten kontaktieren Sie uns am besten direkt."),$(msg user "Wie hoch sind die Nebenkosten ungefähr?"),$(msg assistant "Die Nebenkosten variieren je nach Objekt und umfassen Heizung, Wasser, Müllentsorgung und Gemeinschaftskosten. Konkrete Angaben finden Sie in den jeweiligen Exposés."),$(msg user "Und wenn ich später kaufen möchte, könnt ihr da auch helfen?"),$(msg assistant "Selbstverständlich. Wir sind sowohl als Hausverwaltung als auch als Bauträger tätig. Wenn Sie sich für einen Kauf interessieren, beraten wir Sie gern zu unseren aktuellen Projekten."),$(msg user "Wer wäre mein Ansprechpartner dafür?"),$(msg assistant "Für eine persönliche Beratung wenden Sie sich am besten direkt an unser Team über die Kontaktseite oder telefonisch. Wir vermitteln Sie dann an den passenden Ansprechpartner."),$(msg user "Gut, und noch eine Frage: Wie ist eure Hausverwaltung organisiert? Ich möchte wissen, an wen ich mich bei Problemen wenden kann.")")"

echo ""
echo "=========================================="
echo "All tests complete. Building results table..."
echo ""

# Read results and format as table
total_input=0
total_output=0
total_cost=0
count=0

printf "%-4s %-8s %-6s %-28s %8s %8s %8s %10s\n" \
    "#" "Type" "Turns" "Description" "Input" "Output" "Total" "Cost USD"
printf "%-4s %-8s %-6s %-28s %8s %8s %8s %10s\n" \
    "----" "--------" "------" "----------------------------" "--------" "--------" "--------" "----------"

while IFS=$'\t' read -r id type turns desc input output total cost; do
    [ "$id" = "ID" ] && continue  # skip header

    printf "%-4s %-8s %-6s %-28s %8s %8s %8s %10s\n" \
        "$id" "$type" "$turns" "$desc" "$input" "$output" "$total" "\$$cost"

    if [ "$input" != "-" ] && [ "$total" != "-" ]; then
        total_input=$((total_input + input))
        total_output=$((total_output + output))
        total_cost=$(awk "BEGIN { printf \"%.5f\", $total_cost + $cost }")
        count=$((count + 1))
    fi
done < "$RESULTS_FILE"

echo ""
if [ "$count" -gt 0 ]; then
    avg_input=$((total_input / count))
    avg_output=$((total_output / count))
    avg_total=$(( (total_input + total_output) / count ))
    avg_cost=$(awk "BEGIN { printf \"%.5f\", $total_cost / $count }")

    printf "%-4s %-8s %-6s %-28s %8s %8s %8s %10s\n" \
        "" "" "" "TOTALS ($count tests)" "$total_input" "$total_output" "$((total_input + total_output))" "\$$total_cost"
    printf "%-4s %-8s %-6s %-28s %8s %8s %8s %10s\n" \
        "" "" "" "AVERAGES" "$avg_input" "$avg_output" "$avg_total" "\$$avg_cost"

    echo ""
    echo "Cost model: \$${INPUT_COST_PER_M}/1M input, \$${OUTPUT_COST_PER_M}/1M output"
    echo ""

    # Monthly projections
    echo "── Monthly cost projections ──"
    for daily in 20 50 100 200; do
        monthly_cost=$(awk "BEGIN { printf \"%.2f\", $avg_cost * $daily * 30 }")
        echo "  ${daily} chats/day → ~\$${monthly_cost}/month"
    done
fi

echo ""
echo "Raw data saved to: $RESULTS_FILE"
