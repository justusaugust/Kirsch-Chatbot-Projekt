<?php

if (!defined('ABSPATH')) {
    exit;
}

function kdcb_register_chat_routes()
{
    register_rest_route('kdcb/v1', '/chat', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'kdcb_rest_chat_handler',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('kdcb/v1', '/config', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'kdcb_rest_config_handler',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'kdcb_register_chat_routes');

function kdcb_rest_config_handler()
{
    return new WP_REST_Response(array(
        'enabled' => (bool) kdcb_get_option('enable_widget', 1),
        'rest_base_url' => esc_url_raw(rest_url('kdcb/v1/')),
        'chat_url' => esc_url_raw(rest_url('kdcb/v1/chat')),
        'defect_url' => esc_url_raw(rest_url('kdcb/v1/submit_defect')),
        'ui_mode' => 'cherry_bubble_v1',
        'sources_mode' => 'history_only',
        'cherry_assets' => array(
            'idle_url' => esc_url_raw(KDCB_PLUGIN_URL . 'assets/cherry/idle.png'),
            'hover_url' => esc_url_raw(KDCB_PLUGIN_URL . 'assets/cherry/hover.png'),
            'open_idle_url' => esc_url_raw(KDCB_PLUGIN_URL . 'assets/cherry/open_idle.png'),
            'open_talk_url' => esc_url_raw(KDCB_PLUGIN_URL . 'assets/cherry/open_talk.png'),
        ),
        'max_messages' => 12,
        'strings' => array(
            'toggle_label' => 'K&D Chat',
            'title' => 'K&D Hausbau Chat',
            'placeholder' => 'Ihre Nachricht ...',
            'send' => 'Senden',
            'open_defect' => 'Mängel melden',
        ),
        'defect_schema' => array(
            'trades' => kdcb_get_defect_trades(),
            'urgencies' => kdcb_get_defect_urgencies(),
        ),
    ), 200);
}

function kdcb_chat_should_show_defect_form($message, $request)
{
    if (!empty($request['trigger_defect_form'])) {
        return true;
    }

    $message_raw = (string) $message;
    $message = kdcb_text_lower($message_raw);
    if ($message === '') {
        return false;
    }

    // Respect explicit user intent to NOT open the form (German/English).
    if (kdcb_chat_user_forbids_defect_form($message_raw)) {
        return false;
    }

    // Only auto-open when the user clearly expresses reporting intent.
    $explicit_form_intent = (bool) preg_match(
        '/\b(mängel\s+melden|maengel\s+melden|mangel\s+melden|mängelformular|maengelformular|mängelmeldung|maengelmeldung)\b/iu',
        $message_raw
    );

    // Don't auto-open when the user is asking about the process (informational intent).
    $is_informational_question = (bool) preg_match(
        '/\b(wie\s+(läuft|laeuft|funktioniert|geht)|ablauf|prozess|was\s+(ist|passiert|bedeutet)|erkläre|erklaere|erklär|erklaer)\b/iu',
        $message_raw
    );

    if ($explicit_form_intent && !$is_informational_question) {
        return true;
    }

    $has_defect_topic = kdcb_chat_contains_any($message, array(
        'mangel',
        'schaden',
        'reklamation',
        'defekt',
        'riss',
        'feuchtigkeit',
        'schimmel',
        'wasser',
        'leck',
        // English fallbacks (rare, but helps avoid accidental auto-open).
        'mold',
        'damage',
        'defect',
        'leak',
    ));

    $has_report_intent = (bool) preg_match('/\b(melden|einreichen|reklamier|anzeigen|beschwer|report|submit)\w*\b/iu', $message_raw);

    return $has_defect_topic && $has_report_intent;
}

function kdcb_chat_user_forbids_defect_form($message_raw)
{
    $message_raw = (string) $message_raw;
    $message = kdcb_text_lower($message_raw);
    if ($message === '') {
        return false;
    }

    // "No / don't / do not" close to "form/formular" (any language).
    if (preg_match('/\b(kein|keine|nicht|ohne|no|don\'?t|do\s+not)\b.{0,34}\b(form|formular|mängelformular|maengelformular|defect\s*form)\b/iu', $message_raw)) {
        return true;
    }

    // Explicitly: do not open/show the form.
    if (preg_match('/\b(nicht|kein|no|don\'?t|do\s+not)\b.{0,16}\b(öffne|oeffne|zeige|open|show)\b.{0,22}\b(form|formular|mängelformular|maengelformular)\b/iu', $message_raw)) {
        return true;
    }

    // User wants chat-only handling.
    if (preg_match('/\b(nur|only)\b.{0,14}\b(im\s+chat|hier|chat\s+only|in\s+chat)\b/iu', $message_raw) && strpos($message, 'form') !== false) {
        return true;
    }

    return false;
}

function kdcb_chat_should_open_history($message)
{
    $message = kdcb_text_lower(trim((string) $message));
    if ($message === '') {
        return false;
    }

    if (kdcb_chat_contains_any($message, array('chatverlauf', 'gesprächsverlauf', 'gespraechsverlauf'))) {
        return true;
    }

    if (preg_match('/\b(zeige|zeig|öffne|oeffne|öffnen|oeffnen)\b.{0,28}\b(verlauf|historie)\b/iu', $message)) {
        return true;
    }

    if (preg_match('/\bwas\s+(hatten|haben)\s+wir\b/iu', $message) && strpos($message, '?') !== false) {
        return true;
    }

    return false;
}

function kdcb_chat_internal_nav_targets()
{
    return array(
        '/kontakt/' => array(
            'label' => 'Kontakt',
            'keywords' => array('kontakt', 'kontaktseite', 'kontaktformular', 'erreichbarkeit'),
        ),
        '/faq/' => array(
            'label' => 'FAQ',
            'keywords' => array('faq', 'fragen', 'hilfebereich'),
        ),
        '/kaufen/' => array(
            'label' => 'Kaufen',
            'keywords' => array('kaufen', 'kauf', 'kaufobjekte', 'kaufangebote'),
        ),
        '/mieten/' => array(
            'label' => 'Mieten',
            'keywords' => array('mieten', 'miete', 'mietobjekte', 'mietangebote'),
        ),
        '/leistungen/' => array(
            'label' => 'Leistungen',
            'keywords' => array('leistungen', 'services', 'service'),
        ),
        '/ueber-uns/' => array(
            'label' => 'Über uns',
            'keywords' => array('über uns', 'ueber uns', 'unternehmen', 'team'),
        ),
        '/job/' => array(
            'label' => 'Jobs',
            'keywords' => array('jobs', 'job', 'karriere', 'stellen'),
        ),
    );
}

function kdcb_chat_is_same_host_url($url)
{
    $url_host = parse_url((string) $url, PHP_URL_HOST);
    $site_host = parse_url(home_url('/'), PHP_URL_HOST);
    if (!$url_host || !$site_host) {
        return false;
    }

    return strtolower((string) $url_host) === strtolower((string) $site_host);
}

function kdcb_chat_detect_internal_navigation($message)
{
    $message = kdcb_text_lower(trim((string) $message));
    if ($message === '') {
        return null;
    }

    $has_navigation_intent = preg_match('/\b(öffne|oeffne|öffnet|oeffnet|öffnen|oeffnen|geh(?:e)?\s+zu|navigier(?:e)?|weiter\s+zu|bring\s+mich\s+zu|direkt\s+zu)\b/iu', $message);
    if (!$has_navigation_intent) {
        return null;
    }

    foreach (kdcb_chat_internal_nav_targets() as $path => $config) {
        $keywords = isset($config['keywords']) && is_array($config['keywords']) ? $config['keywords'] : array();
        foreach ($keywords as $keyword) {
            $needle = kdcb_text_lower((string) $keyword);
            if ($needle !== '' && strpos($message, $needle) !== false) {
                $url = home_url($path);
                if (!kdcb_chat_is_same_host_url($url)) {
                    return null;
                }

                return array(
                    'type' => 'navigate_internal',
                    'url' => esc_url_raw($url),
                    'label' => kdcb_sanitize_line(isset($config['label']) ? (string) $config['label'] : 'Seite', 80),
                );
            }
        }
    }

    return null;
}

function kdcb_chat_is_status_ping($message)
{
    $message = trim((string) $message);
    return in_array($message, array('[DEFECT_FORM_OPENED]', '[DEFECT_FORM_SUBMITTED]'), true);
}

function kdcb_chat_status_reply($message)
{
    if (trim((string) $message) === '[DEFECT_FORM_SUBMITTED]') {
        return 'Vielen Dank. Ihre Mängelmeldung wurde übermittelt. Unser Team meldet sich zeitnah.';
    }

    return 'Bitte füllen Sie das Mängelformular aus. Es wird direkt an K&D weitergeleitet.';
}

function kdcb_chat_contains_any($message, $needles)
{
    $message = kdcb_text_lower((string) $message);
    if ($message === '') {
        return false;
    }

    foreach ((array) $needles as $needle) {
        $needle = kdcb_text_lower((string) $needle);
        if ($needle !== '' && strpos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function kdcb_chat_normalize_lang_code($lang_code)
{
    $lang_code = strtolower(trim((string) $lang_code));
    $supported = array('de', 'en', 'fr', 'es', 'it');
    return in_array($lang_code, $supported, true) ? $lang_code : 'de';
}

function kdcb_chat_detect_requested_language($message_raw)
{
    $message_raw = (string) $message_raw;
    $m = kdcb_text_lower($message_raw);
    if ($m === '') {
        return 'de';
    }

    // Explicit language request patterns. Keep narrow to avoid misclassifying
    // questions like "Bietet ihr Beratung auf Englisch?".
    $patterns = array(
        'de' => array(
            '/\b(antworte|antworten|answer|respond|reply)\b.{0,24}\b(auf|in)\s+deutsch\b/iu',
            '/\bauf\s+deutsch\b.{0,16}\b(bittee?|please|antwort)\b/iu',
            '/\bdeutsch\s+(bitte|please)\b\s*$/iu',
        ),
        'en' => array(
            '/\b(answer|respond|reply)\b.{0,24}\bin\s+english\b/iu',
            '/\b(antworte|antworten)\b.{0,24}\b(auf|in)\s+englisch\b/iu',
            '/\bauf\s+englisch\b.{0,16}\b(bittee?|please|antwort)\b/iu',
            '/\bin\s+english\b.{0,16}\bplease\b/iu',
            '/\benglish\s+please\b\s*$/iu',
            '/\bauf\s+englisch\b\s*$/iu',
            '/\bin\s+english\b\s*$/iu',
        ),
        'fr' => array(
            '/\b(answer|respond|reply)\b.{0,24}\bin\s+french\b/iu',
            '/\b(antworte|antworten)\b.{0,24}\b(auf|in)\s+franz(ö|oe)sisch\b/iu',
            '/\bauf\s+franz(ö|oe)sisch\b.{0,16}\b(bittee?|please|antwort)\b/iu',
            '/\ben\s+fran(c|ç)ais\b.{0,16}\b(s\'?il\\s+vous\\s+pla[iî]t|svp)\b/iu',
            '/\bfran(c|ç)ais\s+(svp|s\'?il\\s+vous\\s+pla[iî]t)\b\s*$/iu',
        ),
        'es' => array(
            '/\b(answer|respond|reply)\b.{0,24}\bin\s+spanish\b/iu',
            '/\b(antworte|antworten)\b.{0,24}\b(auf|in)\s+spanisch\b/iu',
            '/\bauf\s+spanisch\b.{0,16}\b(bittee?|please|antwort)\b/iu',
            '/\b(responde|respuesta)\b.{0,24}\ben\s+espa(ñ|n)ol\b/iu',
            '/\bespa(ñ|n)ol\s+(por\\s+favor|please)\b\s*$/iu',
        ),
        'it' => array(
            '/\b(answer|respond|reply)\b.{0,24}\bin\s+italian\b/iu',
            '/\b(antworte|antworten)\b.{0,24}\b(auf|in)\s+italienisch\b/iu',
            '/\bauf\s+italienisch\b.{0,16}\b(bittee?|please|antwort)\b/iu',
            '/\b(in)\s+italiano\b.{0,16}\b(per\\s+favore)\b/iu',
            '/\bitaliano\s+(per\\s+favore)\b\s*$/iu',
        ),
    );

    foreach ($patterns as $lang => $list) {
        foreach ($list as $pattern) {
            if (preg_match($pattern, $message_raw)) {
                return $lang;
            }
        }
    }

    return 'de';
}

function kdcb_chat_language_label($lang_code)
{
    $lang_code = kdcb_chat_normalize_lang_code($lang_code);
    $labels = array(
        'de' => 'Deutsch',
        'en' => 'Englisch',
        'fr' => 'Französisch',
        'es' => 'Spanisch',
        'it' => 'Italienisch',
    );
    return isset($labels[$lang_code]) ? $labels[$lang_code] : 'Deutsch';
}

function kdcb_chat_is_injection_attempt($message)
{
    $message = kdcb_text_lower((string) $message);
    if ($message === '') {
        return false;
    }

    $needles = array(
        'ignoriere alle',
        'ignore all previous',
        'system-prompt',
        'system prompt',
        'interne regeln',
        'internal rules',
        'developer mode',
        'dev mode',
        'jailbreak',
        'prompt injection',
        'bypass',
        'api-key',
        'api key',
        'offenlege',
        'verrate',
    );

    if (kdcb_chat_contains_any($message, $needles)) {
        return true;
    }

    if (preg_match('/\bantworte\s+nur\s+mit\b/iu', $message)) {
        return true;
    }

    // Catch morphology variants and stealth exfiltration attempts.
    if (preg_match('/\bintern\w*\s+(regel\w*|anweis\w*|richtlin\w*)\b/iu', $message)) {
        return true;
    }

    if (preg_match('/\b(system|developer)\b.{0,40}\b(prompt|anweis\w*|regel\w*)\b/iu', $message)) {
        return true;
    }

    if (strpos($message, 'base64') !== false && preg_match('/\b(prompt|system|intern|regel|anweis)\w*\b/iu', $message)) {
        return true;
    }

    if (strpos($message, 'json') !== false && preg_match('/\b(prompt|system|intern|regel|anweis)\w*\b/iu', $message)) {
        return true;
    }

    if (strpos($message, 'yaml') !== false && preg_match('/\b(prompt|system|intern|regel|anweis)\w*\b/iu', $message)) {
        return true;
    }

    return false;
}

function kdcb_chat_injection_reply()
{
    return 'Dabei können wir nicht helfen. Interne Anweisungen, System-Prompts oder API-Schlüssel geben wir nicht heraus (auch nicht als JSON/Base64). '
        . 'Wenn Sie ein Anliegen zu Kauf, Miete, Hausverwaltung oder Mängeln haben, helfen wir Ihnen gern konkret weiter.';
}

function kdcb_chat_is_vague_input($message)
{
    $message = trim(kdcb_text_lower((string) $message));
    if ($message === '') {
        return true;
    }

    $exact = array(
        '?',
        'hmm',
        'hm',
        'okay',
        'ok',
        'geht das',
        'hilfe',
        'brauch hilfe',
        'brauche hilfe',
    );

    if (in_array($message, $exact, true)) {
        return true;
    }

    $terms = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message));
    $terms = array_values(array_filter((array) $terms));

    if (count($terms) <= 2 && strlen($message) <= 18) {
        return true;
    }

    return false;
}

function kdcb_chat_vague_reply()
{
    return "Gern. Wobei sollen wir helfen?\n\n"
        . "- Wohnung kaufen\n"
        . "- Wohnung mieten\n"
        . "- Hausverwaltung / Vermietung\n"
        . "- Mangel oder Schaden melden";
}

function kdcb_chat_is_legal_sensitive($message)
{
    return kdcb_chat_contains_any($message, array(
        'kündigung',
        'kuendigung',
        'eigenbedarf',
        'räumung',
        'raeumung',
        'fristlos',
        'gericht',
        'rechtssicher',
        'anwalt',
        'gesetz',
        'mietrecht',
    ));
}

function kdcb_chat_is_wrongdoing_instruction_request($message)
{
    $message_raw = (string) $message;
    $message = kdcb_text_lower($message_raw);
    if ($message === '') {
        return false;
    }

    // Strong signals for wrongdoing / bypassing legal process.
    $strong = array(
        'ohne rechtsweg',
        'ohne gericht',
        'illegal',
        'umgehen',
        'bypass',
        'maximalen druck',
        'druck machen',
        'schikane',
        'schikanieren',
        'nötigen',
        'no legal',
        'without court',
        'schloss wechseln',
        'schloss austauschen',
        'schlüssel tauschen',
        'schluessel tauschen',
        'strom abstellen',
        'wasser abstellen',
        'heizung abstellen',
        'utilities shut off',
        'change locks',
    );

    if (kdcb_chat_contains_any($message, $strong)) {
        return true;
    }

    // Coercive utility shut-off / lock-out tactics.
    if (
        preg_match('/\b(mieter|tenant)\b/iu', $message_raw) &&
        (
            preg_match('/\b(strom|wasser|heizung|gas|utilities)\b.{0,40}\b(abschalt\w*|abstell\w*|abdrehen|zudrehen|sperr\w*|unterbrech\w*)\b/iu', $message_raw) ||
            preg_match('/\b(schalt\w*|stell\w*)\b.{0,50}\b(strom|wasser|heizung|gas|utilities)\b.{0,24}\b(ab|aus)\b/iu', $message_raw)
        )
    ) {
        return true;
    }

    // Eviction / kick-out instructions (even without explicit "illegal").
    $eviction_topic = (bool) preg_match('/\b(mieter|tenant)\b/iu', $message)
        && (bool) preg_match('/\b(raus\w*|loswerd\w*|rauswerf\w*|rausschmei(ss|ß)\w*|kick\s*out|evict\w*|auszieh\w*|auszug)\b/iu', $message_raw);
    $instruction_intent = (bool) preg_match('/\b(wie\s+kann\s+ich|how\s+(do|can)\s+i|gib\s+mir|give\s+me|anleitung|step\s*by\s*step|schritt\s*f(ü|ue)r\s*schritt|tipps?|trick)\b/iu', $message_raw);
    $bypass_legal = (bool) preg_match('/\b(ohne\s+gericht|without\s+court|ohne\s+rechtsweg|illegal|umgehen)\b/iu', $message_raw);
    $harmful_tactic = kdcb_chat_contains_any($message, array(
        'schloss',
        'schlüssel',
        'schluessel',
        'strom',
        'wasser',
        'heizung',
        'utilities',
        'lock',
    ));

    if ($eviction_topic && ($instruction_intent || $bypass_legal || $harmful_tactic)) {
        return true;
    }

    // Procedural wrongdoing requests.
    if (preg_match('/\b(schritt\s*f(ü|ue)r\s*schritt|step\s*by\s*step|anleitung|plan)\b.{0,80}\b(illegal|ohne\s+gericht|without\s+court|ohne\s+rechtsweg|umgehen|druck|schikane)\b/iu', $message_raw)) {
        return true;
    }

    return false;
}

function kdcb_chat_is_beyond_scope_request($message)
{
    $message_raw = (string) $message;
    $message = kdcb_text_lower($message_raw);
    if ($message === '') {
        return false;
    }

    // Creative writing / entertainment requests are out of scope for this service chat.
    // We keep this narrow to avoid blocking legitimate "Unternehmensgeschichte" questions.
    if (preg_match('/\b(schreib|schreibe|verfass|verfasse|dicht|dichte|komponier|komponiere|mach|mache|erstelle|generier|erzähl|erzaehl|erzähle)\w*\b.{0,60}\b(gedicht|poem|roman|novel|novelle|kurzgeschichte|märchen|maerchen|fabel|songtext|liedtext|lyrics|rap|haiku)\b/iu', $message_raw)) {
        return true;
    }

    if (preg_match('/\b(write|compose|draft|create)\b.{0,60}\b(poem|novel|short\\s+story|story|lyrics|song|rap|haiku)\b/iu', $message_raw)) {
        return true;
    }

    // "Tell me a story" style prompts.
    if (preg_match('/\b(erzähl|erzaehl|erzähle|tell)\w*\b.{0,40}\b(ein|eine|einen|a)\b.{0,18}\b(geschichte|story|märchen|maerchen|fabel)\b/iu', $message_raw)) {
        return true;
    }

    return false;
}

function kdcb_chat_beyond_scope_reply()
{
    return "Dabei können wir nicht helfen. Dieser Chat ist für Fragen zu **Kauf**, **Miete**, **Leistungen**, **Kontakt** und **Mängeln** da.\n\n"
        . "Wenn Sie eine konkrete Frage zu einem dieser Themen haben, schreiben Sie sie bitte kurz.";
}

function kdcb_chat_wrongdoing_reply()
{
    return "Dabei können wir nicht helfen. Wir können keine Rechtsberatung leisten und geben keine Anleitungen zu rechtswidrigem oder schädigendem Vorgehen.\n\n"
        . "Wenn es ein konkretes Problem im Mietverhältnis gibt, empfehlen wir eine rechtliche Beratung oder die Klärung über die vorgesehenen gesetzlichen Wege. "
        . "Wenn Sie möchten, schildern Sie kurz den Sachverhalt (ohne sensible Daten), dann nennen wir Ihnen den passenden Kontaktweg.";
}

function kdcb_log_token_usage($usage, $model = '')
{
    if (!is_array($usage) || empty($usage['total_tokens'])) {
        return;
    }

    $entry = array(
        'ts' => time(),
        'model' => $model !== '' ? $model : (string) kdcb_get_option('model', 'gpt-5.2'),
        'input' => (int) $usage['input_tokens'],
        'output' => (int) $usage['output_tokens'],
        'total' => (int) $usage['total_tokens'],
    );

    $log = get_option('kdcb_token_log', array());
    if (!is_array($log)) {
        $log = array();
    }

    $log[] = $entry;

    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }

    update_option('kdcb_token_log', $log, false);
}

function kdcb_chat_search_tool_schema()
{
    return array(
        'type' => 'function',
        'name' => 'search_website',
        'description' => 'Durchsuche die K&D-Website. Liefert eine Liste relevanter Seiten mit Titel, URL und Vorschau. Nutze anschließend get_page um den vollständigen Inhalt einer Seite zu laden.',
        'parameters' => array(
            'type' => 'object',
            'properties' => array(
                'query' => array(
                    'type' => 'string',
                    'description' => 'Suchbegriffe auf Deutsch',
                ),
            ),
            'required' => array('query'),
            'additionalProperties' => false,
        ),
        'strict' => true,
    );
}

function kdcb_chat_get_page_tool_schema()
{
    return array(
        'type' => 'function',
        'name' => 'get_page',
        'description' => 'Lade den vollständigen Inhalt einer K&D-Webseite. Verwende eine URL aus den search_website-Ergebnissen oder aus dem Kontext.',
        'parameters' => array(
            'type' => 'object',
            'properties' => array(
                'url' => array(
                    'type' => 'string',
                    'description' => 'URL der Seite',
                ),
            ),
            'required' => array('url'),
            'additionalProperties' => false,
        ),
        'strict' => true,
    );
}

function kdcb_chat_behavior_pack()
{
    $lines = array(
        '- Intent zuerst klären: Kauf/Miete, Leistungen, Projekt/Objekt, FAQ, Kontakt oder Mängel.',
        '- Für konkrete Daten, Preise, Flächen, Adressen nur belastbare Werte aus dem Kontext nennen.',
        '- Bei Überblicksfragen erst die Kernpunkte nennen, dann kurze Details.',
        '- Wenn Kontext fehlt: klar den aktuellen Stand nennen und einen konkreten Klärungsweg anbieten.',
        '- Keine Spekulation, keine Rechts- oder Finanzberatung über den Kontext hinaus.',
    );

    return implode("\n", $lines);
}

function kdcb_chat_strip_language_instructions($text)
{
    $text = (string) $text;
    if ($text === '') {
        return '';
    }

    $patterns = array(
        // English
        '/\b(answer|respond|reply)\b.{0,24}\b(in)\s+(english|german|spanish|french|italian)\b[^.?!\n]*[.?!]?/iu',
        '/\b(in)\s+(english|spanish|french|italian)\b\s*(please)?\s*$/iu',
        // German
        '/\b(antworte|antworten|bitte)\b.{0,24}\b(auf|in)\s+(englisch|deutsch|spanisch|französisch|franzoesisch|italienisch)\b[^.?!\n]*[.?!]?/iu',
        '/\b(auf|in)\s+(englisch|spanisch|französisch|franzoesisch|italienisch)\b\s*(bitte)?\s*$/iu',
        // Spanish/French short forms
        '/\b(responde|respuesta)\b.{0,24}\b(en)\s+(español|espanol|francés|frances|inglés|ingles)\b[^.?!\n]*[.?!]?/iu',
        '/\b(réponds|reponds)\b.{0,24}\b(en)\s+(français|francais|anglais)\b[^.?!\n]*[.?!]?/iu',
    );

    $clean = preg_replace($patterns, ' ', $text);
    $clean = preg_replace('/\s{2,}/u', ' ', (string) $clean);
    return trim((string) $clean);
}

function kdcb_chat_compact_faq_context($faq_raw, $max_items)
{
    $pairs = kdcb_rag_parse_faq($faq_raw);
    if (empty($pairs)) {
        return '';
    }

    $items = array();
    foreach (array_slice($pairs, 0, $max_items) as $pair) {
        $question = kdcb_text_substr((string) $pair['question'], 120);
        $answer = kdcb_text_substr((string) $pair['answer'], 180);
        $items[] = '- ' . $question . ': ' . $answer;
    }

    return implode("\n", $items);
}

function kdcb_chat_is_reputation_sensitive($message)
{
    $message = kdcb_text_lower((string) $message);
    if ($message === '') {
        return false;
    }

    $keywords = array(
        'stimmt es',
        'gerücht',
        'geruecht',
        'vorwurf',
        'beschuldigung',
        'schikaniert',
        'schikane',
        'betrug',
        'abzocke',
        'skandal',
        'lüge',
        'luege',
    );

    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
    }

    // "Ich habe gehört dass..." + negative context about the company.
    if (preg_match('/\b(gehört|gehoert|angeblich|man\s+sagt|man\s+hört)\b/iu', $message)
        && preg_match('/\b(probleme|schlecht|schlimm|unseriös|unserioees|ärger|aerger|beschwer|negativ|rauswerf|rausschmei(ss|ß))\b/iu', $message)
    ) {
        return true;
    }

    // "rauswerfen/illegal" only reputation-sensitive when about the company (not user's own situation).
    if (preg_match('/\b(rauswerf\w*|rausgeschmissen|rausschmei(ss|ß)\w*|illegal)\b/iu', $message)
        && preg_match('/\b(bei\s+euch|bei\s+ihnen|ihr\b|kirsch|drechsler|k\s*&\s*d|kud)\b/iu', $message)
    ) {
        return true;
    }

    return false;
}

function kdcb_chat_wants_long_output($message)
{
    return kdcb_chat_contains_any($message, array(
        'ausführlich',
        'ausfuehrlich',
        'detailliert',
        'leitfaden',
        'mindestens',
        '500 wörter',
        '500 woerter',
        'lange antwort',
        'sehr ausführlich',
        'sehr ausfuehrlich',
    ));
}

function kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw, $latest_user_message, $use_search_tool = false)
{
    $admin_system = trim((string) kdcb_get_option('system_instructions', kdcb_default_options()['kdcb_system_instructions']));
    $context_pack = trim((string) kdcb_get_option('context_pack', kdcb_default_options()['kdcb_context_pack']));
    $compact_faq = kdcb_chat_compact_faq_context($faq_raw, 6);
    $is_reputation_sensitive = kdcb_chat_is_reputation_sensitive($latest_user_message);
    $is_legal_sensitive = kdcb_chat_is_legal_sensitive($latest_user_message);
    $wants_long_output = kdcb_chat_wants_long_output($latest_user_message);

    $rules = array(
        $admin_system,
        'Rolle: Du bist der digitale Service-Mitarbeiter der Hausverwaltung von Kirsch & Drechsler und antwortest verbindlich im Namen des Unternehmens.',
        'Sprich professionell in wir-Form, kundenorientiert, ruhig und bestimmt.',
        'Klinge wie ein erfahrener Mitarbeiter im direkten Kundendialog, nicht wie ein Suchassistent.',
        'Antworte aus Unternehmenssicht mit klaren Aussagen statt vorsichtiger Vermutungen.',
        'Nutze strikt nur den bereitgestellten Kontext und erfinde keine Fakten.',
        $use_search_tool
            ? 'Du hast zwei Werkzeuge: search_website (findet Seiten) und get_page (lädt vollständigen Seiteninhalt). Wenn CURRENT_PAGE und FAQ die Frage nicht beantworten: erst search_website nutzen, dann get_page für die relevanteste Seite aufrufen. Antworte erst, wenn du den Seiteninhalt geladen hast.'
            : 'Kontext-Priorität: 1) CURRENT_PAGE (hoch), 2) WP_SEARCH (mittel), 3) FAQ_MATCHES (niedrig).',
        'Semantik-Regel: Interpretiere umgangssprachliche Begriffe (z. B. "Boss", "Chef") direkt als Frage nach Geschäftsführung/Leitung.',
        'Erkläre diese Interpretation nicht im Antworttext, sondern gib direkt die inhaltliche Antwort.',
        $use_search_tool
            ? 'Geladene Seiteninhalte (via get_page) sind verbindliche, aktuelle Website-Information. Antworte daraus konkret und nenne gefundene Inhalte beim Namen (z. B. Jobtitel, Leistungen, Projekte). Was auf der Website steht, ist offizielle Auskunft.'
            : 'Wenn nach einem Überblick (z. B. "Leistungen") gefragt wird, kombiniere CURRENT_PAGE mit relevanten WP_SEARCH-Treffern.',
        'Bei Bereichsfragen (z. B. Leistungen, Jobs, Kontakt) antworte AI-basiert mit kurzer Übersicht und füge, falls im Kontext vorhanden, genau einen passenden Markdown-Link ein.',
        'Wenn im Kontext eine thematisch passende Seite (z. B. Jobs/Karriere/Leistungen) vorhanden ist, behandle sie als belastbare Information und nenne sie nicht als \"nicht vorhanden\".',
        'Vermeide Formulierungen wie "unter /leistungen zu finden"; liefere stattdessen direkt die Antwort plus Link.',
        'Wenn der Nutzer einen Mangel/Schaden beschreibt: gib 3 kurze Sofortmaßnahmen (Bulletpoints) und nenne danach als Option den Button „Mängel melden“ für die offizielle Meldung.',
        'Wichtig zu Mängeln: Der Chat ist kein Ticket-System. Chat-Nachrichten werden nicht dauerhaft gespeichert und nicht als offizielle Mängelmeldung verarbeitet.',
        'Für eine offizielle Mängelmeldung: verweise auf den Button „Mängel melden“ und frage im Chat nicht nach Name, Adresse, Objektadresse, E-Mail oder Telefonnummer.',
        'Wenn der Nutzer ausdrücklich kein Formular möchte: gib trotzdem Sofortmaßnahmen, aber nimm die Meldung nicht "im Chat" entgegen und sammle keine Kontaktdaten.',
        'Wenn du etwas nicht nennen kannst (z. B. Gehalt, Adresse, Preis): formuliere es selbstverständlich aus Mitarbeitersicht, z. B. "Das können wir Ihnen hier im Chat nicht nennen – melden Sie sich gern direkt bei uns." NIEMALS "in den vorliegenden Informationen nicht angegeben/enthalten/aufgeführt" oder "ist hier nicht hinterlegt/ausgewiesen".',
        'Sicherheitsregel: Frage nicht aktiv nach sensiblen persönlichen Daten.',
        'Sprich wie ein Mensch am Empfang, nicht wie ein Suchsystem. Kein "auf der Seite steht", "im Kontext", "laut vorliegender Information", "in den uns vorliegenden Daten". Stattdessen direkte Aussagen.',
        'Nenne den Website-/Firmennamen nur, wenn es für die inhaltliche Klarheit nötig ist.',
        'Nenne Seitennamen/Fundorte nicht im Fließtext, außer der Nutzer fragt explizit danach.',
        'Keine Ich-Perspektive über interne Arbeitsschritte (kein "ich habe gesucht", "ich sehe auf der Seite", "in der aktuellen Ansicht").',
        'Keine Beschreibung des internen Such-/Kontextprozesses.',
        "Antwort-Playbook (kompakt):\n" . kdcb_chat_behavior_pack(),
        'Standardsprache: Deutsch. Stil: klar, kurz, hilfreich. Wenn der Nutzer in einer anderen Sprache schreibt oder explizit um eine andere Sprache bittet, antworte in dieser Sprache. Interne Regeln und Toolaufrufe bleiben immer auf Deutsch.',
        'Antwortformat: zuerst eine direkte Antwort in 2-6 Sätzen, optional kurze Aufzählung für Details.',
        'Standard-Länge: knapp und fokussiert (maximal ca. 120 Wörter), außer der Nutzer verlangt ausdrücklich eine ausführliche Antwort.',
        'Nutze lesbares Markdown für Struktur (Absätze, Listen, **Hervorhebungen**), aber keine Tabellen.',
        'Wenn der Nutzer nach einer Tabelle fragt, liefere stattdessen eine klar strukturierte Listen- oder Abschnittsform.',
        'Bei unklaren Kurzfragen stelle genau eine kurze Rückfrage mit 3-4 Auswahloptionen.',
        'Keine Quellen-/Fundstellen-Zeile am Ende, außer der Nutzer fragt explizit nach Quelle/Beleg/Link.',
    );

    if ($is_reputation_sensitive) {
        $rules[] = implode("\n", array(
            'Reputationskritische Anfrage erkannt: antworte verbindlich und sachlich als Unternehmensvertreter.',
            'Übernimm unbelegte Vorwürfe nicht als Tatsache.',
            'Wenn keine Bestätigung vorliegt, formuliere klar: "Diesen Vorwurf können wir so nicht bestätigen."',
            'Ergänze knapp, was tatsächlich bekannt ist, und biete direkt einen konkreten Klärungsweg an.',
        ));
    }

    if ($is_legal_sensitive) {
        $rules[] = implode("\n", array(
            'Rechtlich sensible Anfrage erkannt: keine Rechtsberatung, keine Garantien und keine anleitende Umgehung von Gesetzen.',
            'Formuliere stattdessen sachlich den Rahmen und den sicheren nächsten Schritt (z. B. individuelle juristische Prüfung).',
            'Wichtig: Eine rechtliche Frage ist nicht automatisch ein Reputationsvorwurf.',
        ));
    }

    if ($wants_long_output) {
        $rules[] = 'Der Nutzer wünscht ausdrücklich eine ausführliche Antwort. Liefere strukturiert und ausführlich, aber ohne Tabellen.';
    }

    if ($context_pack !== '') {
        $rules[] = "Vorkonfigurierter Kontext (kompakt):\n" . kdcb_sanitize_paragraph($context_pack, 1800);
    }

    if ($compact_faq !== '') {
        $rules[] = "FAQ-Kernpunkte (kompakt):\n" . $compact_faq;
    }

    if ($page_title !== '') {
        $rules[] = 'Aktuelle Seite: ' . $page_title;
    }

    if ($context_text !== '') {
        $rules[] = "Kontext (verbindlich):\n" . $context_text;
    } else {
        $rules[] = 'Es liegt kein belastbarer Kontext vor. Antworte in diesem Fall transparent und verweise auf Kontakt per Mängelformular.';
    }

    return implode("\n\n", $rules);
}

function kdcb_chat_user_asked_for_source($latest_user_message)
{
    $message = kdcb_text_lower((string) $latest_user_message);
    $keywords = array(
        'quelle',
        'beleg',
        'woher',
        'link',
        'fundstelle',
        'source',
        'herkunft',
    );

    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

function kdcb_chat_strip_markdown_tables($reply)
{
    $lines = preg_split('/\R/u', (string) $reply);
    if (!is_array($lines) || empty($lines)) {
        return (string) $reply;
    }

    $has_table = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\|.*\|\s*$/u', $line)) {
            $has_table = true;
            break;
        }
    }

    if (!$has_table) {
        return (string) $reply;
    }

    $out = array();
    foreach ($lines as $line) {
        $trim = trim((string) $line);

        if (preg_match('/^\s*\|?\s*[:\-]+\s*(\|\s*[:\-]+\s*)+\|?\s*$/u', $trim)) {
            continue;
        }

        if (preg_match('/^\s*\|.*\|\s*$/u', $trim)) {
            $cells = array_map('trim', explode('|', trim($trim, '|')));
            $cells = array_values(array_filter($cells, static function ($value) {
                return $value !== '';
            }));

            if (count($cells) >= 2) {
                $head = array_shift($cells);
                $tail = implode('; ', $cells);
                $out[] = '- **' . $head . '**: ' . $tail;
                continue;
            }
        }

        $out[] = $line;
    }

    return implode("\n", $out);
}

function kdcb_chat_reply_contains_markdown_link($reply, $path_fragment)
{
    $reply = (string) $reply;
    if ($reply === '' || $path_fragment === '') {
        return false;
    }

    $pattern = '/\[[^\]]+\]\([^)]*' . preg_quote((string) $path_fragment, '/') . '[^)]*\)/iu';
    return (bool) preg_match($pattern, $reply);
}

function kdcb_chat_pick_source_url($sources, $path_fragments)
{
    foreach ((array) $sources as $source) {
        if (!is_array($source) || empty($source['url'])) {
            continue;
        }

        $url = (string) $source['url'];
        foreach ((array) $path_fragments as $fragment) {
            if ($fragment !== '' && strpos($url, (string) $fragment) !== false) {
                return esc_url_raw($url);
            }
        }
    }

    return '';
}

function kdcb_chat_pick_site_url($paths)
{
    foreach ((array) $paths as $path) {
        $url = esc_url_raw(home_url((string) $path));
        $post_id = (int) url_to_postid($url);
        if ($post_id > 0) {
            $permalink = get_permalink($post_id);
            if (is_string($permalink) && $permalink !== '') {
                return esc_url_raw($permalink);
            }
        }
    }

    return '';
}

function kdcb_chat_append_context_link_if_missing($reply, $latest_user_message, $sources)
{
    $reply = trim((string) $reply);
    if ($reply === '') {
        return '';
    }

    $message_raw = (string) $latest_user_message;
    $message = kdcb_text_lower($message_raw);

    // Avoid false positives like "wie stelle ich ..." (stellen=verb). Keep "Stelle(n)" only when clearly in career context.
    $is_jobs_request = (bool) preg_match('/\b(jobs?|karriere|bewerb\w*|praktik\w*|werkstudent\w*|stellenangebot\w*|stellenanzeig\w*)\b/iu', $message_raw)
        || (bool) preg_match('/\b(offene|freie)\s+stellen?\b/iu', $message_raw);
    $is_services_request = kdcb_chat_contains_any($message, array(
        'leistung', 'leistungen', 'service', 'services', 'angebot', 'angebote', 'was bietet ihr an', 'was bietet ihr',
    ));

    if ($is_jobs_request) {
        $has_jobs_link = kdcb_chat_reply_contains_markdown_link($reply, '/job')
            || kdcb_chat_reply_contains_markdown_link($reply, '/jobs')
            || kdcb_chat_reply_contains_markdown_link($reply, '/karriere');

        if (!$has_jobs_link) {
            $jobs_url = kdcb_chat_pick_source_url((array) $sources, array('/job', '/jobs', '/karriere'));
            if ($jobs_url === '') {
                $jobs_url = kdcb_chat_pick_site_url(array('/job/', '/jobs/', '/karriere/'));
            }
            if ($jobs_url !== '') {
                $reply .= "\n\nMehr Details: [Jobs](" . $jobs_url . ')';
            }
        }
    }

    if ($is_services_request) {
        $has_services_link = kdcb_chat_reply_contains_markdown_link($reply, '/leistungen');

        if (!$has_services_link) {
            $services_url = kdcb_chat_pick_source_url((array) $sources, array('/leistungen'));
            if ($services_url === '') {
                $services_url = kdcb_chat_pick_site_url(array('/leistungen/'));
            }
            if ($services_url !== '') {
                $reply .= "\n\nMehr Details: [Leistungen](" . $services_url . ')';
            }
        }
    }

    return trim($reply);
}

function kdcb_chat_postprocess_reply($reply, $latest_user_message)
{
    $reply = trim((string) $reply);
    if ($reply === '') {
        return '';
    }

    if (!kdcb_chat_user_asked_for_source($latest_user_message)) {
        $reply = preg_replace('/(^|\R)\s*Quelle:\s*https?:\/\/\S+\s*(?=\R|$)/iu', "\n", $reply);
        $reply = preg_replace('/(^|\R)\s*Quellen:\s*https?:\/\/\S+\s*(?=\R|$)/iu', "\n", $reply);
        $reply = preg_replace('/\R{3,}/u', "\n\n", trim($reply));
    }

    $soft_rewrites = array(
        // ── "Search assistant" speak → natural employee speak ──
        // "in den (uns) vorliegenden/verfügbaren Informationen nicht angegeben/enthalten"
        '/\bin\s+den\s+(uns\s+)?(vorliegenden|verfügbaren|aktuell\s+verfügbaren|aktuell\s+vorliegenden)\s+(Informationen|Daten|Unterlagen)\s+[^\.!\n]{0,80}(angegeben|enthalten|aufgeführt|hinterlegt|ausgewiesen|genannt|ersichtlich)[^\.!\n]*[\.!\n]?/iu'
            => '',
        // "ist ... nicht angegeben" or "nicht angegeben/ausgewiesen ist" (any word order)
        '/\bist\s+(hier\s+|dort\s+|aktuell\s+|derzeit\s+|in\s+dieser\s+Ansicht\s+)?(leider\s+)?(nicht|kein\w*)\s+(angegeben|hinterlegt|ausgewiesen|aufgeführt|ersichtlich|vermerkt|verfügbar)/iu'
            => 'können wir Ihnen hier im Chat nicht nennen',
        '/\bnicht\s+(öffentlich\s+)?(angegeben|hinterlegt|ausgewiesen|aufgeführt|ersichtlich|vermerkt)\s+(ist|wird|war)\b/iu'
            => 'können wir hier im Chat nicht nennen',
        // trailing "weil/da ... nicht ausgewiesen/angegeben ist" clauses
        '/,?\s*(weil|da)\s+[^\.!\n]{0,80}(nicht\s+)?(angegeben|hinterlegt|ausgewiesen|aufgeführt|ersichtlich)\s+(ist|wird|war)[^\.!\n]*[\.!\n]?/iu'
            => '.',
        // "laut vorliegender Information" / "laut den uns vorliegenden"
        '/\blaut\s+(vorliegender|den\s+uns\s+vorliegenden)\s+(Information\w*|Daten|Unterlagen)/iu' => 'nach aktuellem Stand',
        // "in der aktuellen Ansicht / in der uns vorliegenden Übersicht"
        '/\bin\s+der\s+(aktuellen|uns\s+vorliegenden)\s+(Ansicht|Übersicht|Uebersicht|Darstellung)/iu' => 'hier im Chat',
        // "im bereitgestellten Kontext"
        '/\bim\s+bereitgestellten\s+Kontext\b/iu' => 'derzeit',
        // "aus dem verfügbaren Kontext geht hervor"
        '/\bAus\s+dem\s+verfügbaren\s+Kontext\s+geht\s+(nur\s+)?hervor,?\s+(dass\s+)?/iu' => '',
        // "mir liegen (hier) keine belastbaren Informationen vor"
        '/\b(Mir|uns)\s+liegen\s+(hier\s+)?(dazu\s+)?(keine|nicht)\s+[^\.!\n]{0,40}(Informationen|Angaben|Daten)\s+vor[^\.!\n]*[\.!\n]?/iu'
            => 'Das können wir Ihnen hier im Chat nicht im Detail beantworten.',
        // "Auf der (aktuellen/dieser/unserer) Seite" references
        '/\bAuf\s+der\s+(aktuellen|dieser)\s+Seite\b[^\.!\n]*(nicht\s+genannt|nicht\s+aufgeführt|nicht\s+angegeben)[^\.!\n]*[\.!\n]?/iu'
            => 'Das können wir Ihnen hier im Chat leider nicht nennen.',
        '/\bAuf\s+(unserer|der)\s+[^\.!\n]{0,80}Seite\s+wird\s+außerdem\s+/iu' => 'Außerdem ',
        '/\bAuf\s+(unserer|der)\s+[^\.!\n]{0,80}Seite\s+(wird|ist|steht|finden Sie)\s+/iu' => '',
        // "ich habe gesucht / ich sehe auf der Seite"
        '/\bich habe\s+(hier\s+)?(nur\s+)?(im|auf der)\s+[^\.!\n]*gesucht[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bich kann\s+(hier\s+)?nur auf der seite suchen[^\.!\n]*[\.!\n]?/iu' => '',
        // "Boss/Chef" explanation
        '/\bMit\s+[„"\'`]?(Boss|Chef)[""\'`]?\s+ist[^\.!\n]*(gemeint|bezeichnet|oft\s+gemeint)[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bwird auch genannt\b/iu' => 'heißt',
        // ── Defect channel boundary ──
        '/\bwir\s+nehmen\s+(Ihre\s+)?[^\.!\n]{0,80}meldung\s+(auch\s+)?ohne\s+formular\s+entgegen\.?/iu' =>
            'Für eine offizielle Meldung nutzen Sie bitte den Button **„Mängel melden"**.',
    );

    foreach ($soft_rewrites as $pattern => $replacement) {
        $reply = preg_replace($pattern, $replacement, $reply);
    }

    $reply = kdcb_chat_strip_markdown_tables($reply);

    if (kdcb_chat_is_legal_sensitive($latest_user_message)) {
        if (!preg_match('/\bkeine\s+Rechtsberatung\b/iu', $reply)) {
            $reply = "Wir können keine Rechtsberatung leisten.\n\n" . ltrim($reply);
        }
    }

    if (kdcb_chat_is_reputation_sensitive($latest_user_message)) {
        if (!preg_match('/Diesen Vorwurf können wir so nicht bestätigen\./iu', $reply)) {
            $reply = "Diesen Vorwurf können wir so nicht bestätigen.\n\n" . ltrim($reply);
        }
    }

    $reply = preg_replace('/\R{3,}/u', "\n\n", trim($reply));

    return trim((string) $reply);
}

function kdcb_chat_build_slim_context($page_url, $latest_message, $faq_raw)
{
    $query_terms = kdcb_rag_query_terms($latest_message);
    $query_terms = kdcb_rag_expand_query_terms($latest_message, $query_terms);

    $context = array(
        'current_page' => null,
        'faq_results' => array(),
        'sources' => array(),
        'context_text' => '',
    );

    $current_page = kdcb_rag_resolve_page($page_url);
    if (is_array($current_page)) {
        $current_page['focus_snippet'] = kdcb_rag_make_focus_snippet(
            $current_page['content'],
            $query_terms,
            900
        );
        $context['current_page'] = $current_page;
        $context['sources'][] = array(
            'title' => $current_page['title'],
            'url' => $current_page['url'],
        );
    }

    $faq_results = kdcb_rag_match_faq($latest_message, $faq_raw, 2);
    $context['faq_results'] = $faq_results;

    if (!empty($faq_results)) {
        $context['sources'][] = array(
            'title' => 'K&D FAQ',
            'url' => site_url('/'),
        );
    }

    $chunks = array();

    if (!empty($context['current_page'])) {
        $page = $context['current_page'];
        $excerpt = !empty($page['focus_snippet']) ? $page['focus_snippet'] : $page['content'];
        $chunks[] = "[CURRENT_PAGE | PRIORITY: HIGH]\nTitel: " . $page['title']
            . "\nURL: " . $page['url']
            . "\nRelevanter Auszug: " . $excerpt;
    }

    if (!empty($faq_results)) {
        $faq_chunks = array();
        foreach ($faq_results as $faq) {
            $faq_chunks[] = "Q: " . $faq['question'] . "\nA: " . $faq['answer'];
        }
        $chunks[] = "[FAQ_MATCHES]\n" . implode("\n\n", $faq_chunks);
    }

    $context['context_text'] = implode("\n\n", $chunks);

    return $context;
}

function kdcb_rest_chat_handler($request)
{
    $origin_check = kdcb_validate_origin($request);
    if (is_wp_error($origin_check)) {
        return $origin_check;
    }

    $rate_limit = (int) kdcb_get_option('chat_rate_limit_hourly', 60);
    $rate_check = kdcb_enforce_rate_limit('chat', $rate_limit, HOUR_IN_SECONDS);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('kdcb_invalid_payload', 'Ungültige JSON-Daten.', array('status' => 400));
    }

    $payload_encoded = wp_json_encode($payload);
    if (is_string($payload_encoded) && strlen($payload_encoded) > 50000) {
        return new WP_Error('kdcb_payload_too_large', 'Payload ist zu gross.', array('status' => 413));
    }

    $messages = isset($payload['messages']) && is_array($payload['messages']) ? $payload['messages'] : array();
    if (empty($messages)) {
        return new WP_Error('kdcb_missing_messages', 'Nachrichtenliste fehlt.', array('status' => 400));
    }

    $messages = array_slice($messages, -12);

    $clean_messages = array();
    $latest_user_message = '';

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
        if (!in_array($role, array('user', 'assistant'), true)) {
            continue;
        }

        $max_len = ($role === 'user') ? 1500 : 2000;
        $content = kdcb_sanitize_message_text(isset($message['content']) ? $message['content'] : '', $max_len);

        if ($content === '') {
            continue;
        }

        if (
            $role === 'assistant' &&
            $content === 'Ich kann gerade keine zuverlässige Antwort erzeugen. Bitte nutzen Sie das Mängelformular oder kontaktieren Sie K&D direkt.'
        ) {
            continue;
        }

        $clean_messages[] = array(
            'role' => $role,
            'content' => $content,
        );

        if ($role === 'user') {
            $latest_user_message = $content;
        }
    }

    if ($latest_user_message === '') {
        return new WP_Error('kdcb_missing_user_message', 'Keine gültige User-Nachricht gefunden.', array('status' => 400));
    }

    $page_url = isset($payload['page_url']) ? esc_url_raw((string) $payload['page_url']) : '';
    $page_title = isset($payload['page_title']) ? kdcb_sanitize_line($payload['page_title'], 180) : '';

    if (kdcb_chat_is_status_ping($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply' => kdcb_chat_status_reply($latest_user_message),
            'sources' => array(),
            'action' => null,
        ), 200);
    }

    if (kdcb_chat_should_show_defect_form($latest_user_message, $payload)) {
        return new WP_REST_Response(array(
            'reply' => 'Ich habe das Mängelformular für Sie geöffnet. Bitte tragen Sie die Angaben ein, damit K&D direkt reagieren kann.',
            'sources' => array(),
            'action' => array('type' => 'show_defect_form'),
        ), 200);
    }

    if (kdcb_chat_should_open_history($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply' => 'Ich habe den Verlauf für Sie geöffnet.',
            'sources' => array(),
            'action' => array('type' => 'open_history'),
        ), 200);
    }

    $navigation_action = kdcb_chat_detect_internal_navigation($latest_user_message);
    if (is_array($navigation_action) && !empty($navigation_action['url'])) {
        $label = isset($navigation_action['label']) ? (string) $navigation_action['label'] : 'Seite';

        return new WP_REST_Response(array(
            'reply' => 'Gern, ich öffne die Seite „' . $label . '“.',
            'sources' => array(),
            'action' => array(
                'type' => 'navigate_internal',
                'url' => $navigation_action['url'],
            ),
        ), 200);
    }

    if (kdcb_chat_is_injection_attempt($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply' => kdcb_chat_injection_reply(),
            'sources' => array(),
            'action' => null,
        ), 200);
    }

    if (kdcb_chat_is_wrongdoing_instruction_request($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply' => kdcb_chat_wrongdoing_reply(),
            'sources' => array(),
            'action' => null,
        ), 200);
    }

    if (kdcb_chat_is_beyond_scope_request($latest_user_message)) {
        return new WP_REST_Response(array(
            'reply' => kdcb_chat_beyond_scope_reply(),
            'sources' => array(),
            'action' => null,
        ), 200);
    }

    $faq_raw = (string) kdcb_get_option('faq_raw', '');
    $latest_user_message_for_rag = kdcb_chat_strip_language_instructions($latest_user_message);
    if ($latest_user_message_for_rag === '') {
        $latest_user_message_for_rag = $latest_user_message;
    }

    $slim = kdcb_chat_build_slim_context($page_url, $latest_user_message_for_rag, $faq_raw);
    $context_text = $slim['context_text'];
    $exclude_post_id = is_array($slim['current_page']) ? (int) $slim['current_page']['post_id'] : 0;

    $tool_sources = array();

    $tool_handler = function ($name, $arguments) use ($exclude_post_id, &$tool_sources) {
        // ── search_website: discovery (find pages) ──
        if ($name === 'search_website') {
            $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
            if ($query === '') {
                return 'Keine Suchbegriffe angegeben.';
            }

            $primary_results = kdcb_rag_search_posts($query, $exclude_post_id);

            $query_terms = kdcb_rag_query_terms($query);
            $intent_query = kdcb_rag_build_intent_query($query, $query_terms);
            $intent_results = array();
            if ($intent_query !== '' && kdcb_text_lower($intent_query) !== kdcb_text_lower($query)) {
                $intent_results = kdcb_rag_search_posts($intent_query, $exclude_post_id);
            }

            $expanded_terms = kdcb_rag_expand_query_terms($query, $query_terms);
            $all_results = kdcb_rag_rank_search_results(
                array_merge($primary_results, $intent_results),
                $expanded_terms,
                5
            );
            $all_results = kdcb_rag_inject_intent_boost_results($query, $expanded_terms, $all_results);
            $all_results = kdcb_rag_rank_search_results($all_results, $expanded_terms, 5);

            // Find related pages by slug prefix (e.g. "job" → "job-weg-administrator").
            $seen_urls = array();
            foreach ($all_results as $item) {
                if (!empty($item['url'])) {
                    $seen_urls[$item['url']] = true;
                }
            }
            foreach ($all_results as $item) {
                if (empty($item['url'])) {
                    continue;
                }
                $path = trim((string) wp_parse_url($item['url'], PHP_URL_PATH), '/');
                $slug = sanitize_title(wp_basename($path));
                if (strlen($slug) < 2 || strlen($slug) > 14) {
                    continue;
                }
                global $wpdb;
                $like_pattern = $wpdb->esc_like($slug) . '-%';
                $related_posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status = 'publish' AND post_name LIKE %s LIMIT 4",
                    $like_pattern
                ));
                if (!is_array($related_posts)) {
                    continue;
                }
                foreach ($related_posts as $rp) {
                    $rp_url = get_permalink((int) $rp->ID);
                    if (!$rp_url || isset($seen_urls[$rp_url])) {
                        continue;
                    }
                    $rp_post = get_post((int) $rp->ID);
                    if (!$rp_post instanceof WP_Post) {
                        continue;
                    }
                    $all_results[] = array(
                        'title' => get_the_title($rp_post),
                        'url' => $rp_url,
                        'snippet' => kdcb_rag_extract_post_text($rp_post, 200),
                    );
                    $seen_urls[$rp_url] = true;
                }
            }

            if (empty($all_results)) {
                return 'Keine Treffer für "' . $query . '".';
            }

            foreach ($all_results as $item) {
                if (!empty($item['url']) && !empty($item['title'])) {
                    $tool_sources[] = array('title' => $item['title'], 'url' => $item['url']);
                }
            }

            $lines = array();
            foreach (array_slice($all_results, 0, 6) as $item) {
                $snippet = kdcb_text_substr(isset($item['snippet']) ? (string) $item['snippet'] : '', 200);
                $lines[] = '- ' . $item['title'] . ' | ' . $item['url'] . "\n  " . $snippet;
            }
            return implode("\n", $lines);
        }

        // ── get_page: read full page content ──
        if ($name === 'get_page') {
            $url = isset($arguments['url']) ? trim((string) $arguments['url']) : '';
            if ($url === '') {
                return 'Keine URL angegeben.';
            }

            $page = kdcb_rag_resolve_page($url);
            if (!is_array($page)) {
                return 'Seite nicht gefunden: ' . $url;
            }

            $tool_sources[] = array('title' => $page['title'], 'url' => $page['url']);

            $post = get_post((int) $page['post_id']);
            $content = ($post instanceof WP_Post) ? kdcb_rag_extract_post_text($post, 3000) : $page['content'];

            return $page['title'] . "\nURL: " . $page['url'] . "\n\n" . $content;
        }

        return 'Unbekanntes Tool.';
    };

    $openai_messages = array(
        array(
            'role' => 'system',
            'content' => kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw, $latest_user_message, true),
        ),
    );

    foreach ($clean_messages as $message) {
        $role = isset($message['role']) ? (string) $message['role'] : '';
        $content = isset($message['content']) ? (string) $message['content'] : '';
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }

        $openai_messages[] = array(
            'role' => $role,
            'content' => $content,
        );
    }

    $result = kdcb_openai_create_response($openai_messages, array(
        'max_output_tokens' => kdcb_chat_wants_long_output($latest_user_message) ? 1600 : 420,
        'tools' => array(kdcb_chat_search_tool_schema(), kdcb_chat_get_page_tool_schema()),
        'tool_handler' => $tool_handler,
    ));

    $usage = null;

    if (is_wp_error($result)) {
        error_log('KDCB chat generation failed: ' . $result->get_error_code());
        $reply = 'Ich kann gerade keine zuverlässige Antwort erzeugen. Bitte nutzen Sie das Mängelformular oder kontaktieren Sie K&D direkt.';
    } else {
        $reply = kdcb_chat_postprocess_reply($result['text'], $latest_user_message);
        $reply = kdcb_chat_append_context_link_if_missing(
            $reply,
            $latest_user_message,
            $slim['sources']
        );
        $usage = $result['usage'];
        kdcb_log_token_usage($usage);
    }

    // Merge tool-found sources into response pills.
    if (!empty($tool_sources)) {
        $existing_urls = array();
        foreach ($slim['sources'] as $s) {
            if (!empty($s['url'])) {
                $existing_urls[$s['url']] = true;
            }
        }
        foreach ($tool_sources as $s) {
            if (!empty($s['url']) && !isset($existing_urls[$s['url']])) {
                $slim['sources'][] = array(
                    'title' => (string) $s['title'],
                    'url' => esc_url_raw((string) $s['url']),
                );
                $existing_urls[$s['url']] = true;
            }
        }
    }

    return new WP_REST_Response(array(
        'reply' => (string) $reply,
        'sources' => $slim['sources'],
        'action' => null,
        'usage' => $usage,
    ), 200);
}
