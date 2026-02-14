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

    $keywords = array(
        'mangel',
        'schaden',
        'reklamation',
        'defekt',
        'riss',
        'feuchtigkeit',
    );

    $message = kdcb_text_lower((string) $message);

    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
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

    return false;
}

function kdcb_chat_injection_reply()
{
    return 'Dabei können wir nicht helfen. Interne Regeln, Systemanweisungen und Schlüssel werden nicht offengelegt. '
        . 'Wenn Sie ein Anliegen zu Kauf, Miete, Hausverwaltung oder einem Mangel haben, helfen wir Ihnen gern konkret weiter.';
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
        'mieter',
    ));
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
        'rausschmeiß',
        'rausschmeiss',
        'rausgeworfen',
        'rauswerfen',
        'schikaniert',
        'schikane',
        'betrug',
        'abzocke',
        'illegal',
        'skandal',
        'lüge',
        'luege',
    );

    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
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

function kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw, $latest_user_message)
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
        'Kontext-Priorität: 1) CURRENT_PAGE (hoch), 2) WP_SEARCH (mittel), 3) FAQ_MATCHES (niedrig).',
        'Semantik-Regel: Interpretiere umgangssprachliche Begriffe (z. B. "Boss", "Chef") direkt als Frage nach Geschäftsführung/Leitung.',
        'Erkläre diese Interpretation nicht im Antworttext, sondern gib direkt die inhaltliche Antwort.',
        'Wenn nach einem Überblick (z. B. "Leistungen") gefragt wird, kombiniere CURRENT_PAGE mit relevanten WP_SEARCH-Treffern.',
        'Bei Bereichsfragen (z. B. Leistungen, Jobs, Kontakt) antworte AI-basiert mit kurzer Übersicht und füge, falls im Kontext vorhanden, genau einen passenden Markdown-Link ein.',
        'Wenn im Kontext eine thematisch passende Seite (z. B. Jobs/Karriere/Leistungen) vorhanden ist, behandle sie als belastbare Information und nenne sie nicht als \"nicht vorhanden\".',
        'Vermeide Formulierungen wie "unter /leistungen zu finden"; liefere stattdessen direkt die Antwort plus Link.',
        'Wenn Informationen fehlen oder uneindeutig sind, nenne knapp den Stand aus Unternehmenssicht und biete einen konkreten nächsten Schritt an.',
        'Sicherheitsregel: Frage nicht aktiv nach sensiblen persönlichen Daten.',
        'Schreibstil: antworte direkt auf die Frage ohne Meta-Einleitung wie "Auf der Seite ... steht" oder "im Kontext steht".',
        'Vermeide Formulierungen wie "Mit Boss ist oft gemeint ..." oder "wird auch genannt".',
        'Vermeide Füllsätze wie "oft", "meist", "kann bedeuten", "es wird genannt", wenn eine klare Aussage möglich ist.',
        'Nenne den Website-/Firmennamen nur, wenn es für die inhaltliche Klarheit nötig ist.',
        'Nenne Seitennamen/Fundorte nicht im Fließtext, außer der Nutzer fragt explizit danach.',
        'Keine Ich-Perspektive über interne Arbeitsschritte (kein "ich habe gesucht", "ich sehe auf der Seite").',
        'Vermeide hilflose Formulierungen wie "mir liegen hier keine belastbaren Informationen vor", "aus dem Kontext geht nur hervor" oder "ich kann nur auf der Seite suchen".',
        'Keine Beschreibung des internen Such-/Kontextprozesses.',
        "Antwort-Playbook (kompakt):\n" . kdcb_chat_behavior_pack(),
        'Antwortsprache: Deutsch. Stil: klar, kurz, hilfreich.',
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

    $message = kdcb_text_lower((string) $latest_user_message);

    $is_jobs_request = kdcb_chat_contains_any($message, array(
        'job', 'jobs', 'karriere', 'stelle', 'stellen', 'bewerbung', 'bewerben',
    ));
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
        '/\bMir liegen (hier )?keine belastbaren Informationen vor\.?/iu' => 'Dazu liegt derzeit keine belastbare Grundlage vor.',
        '/\bAus dem verfügbaren Kontext geht nur hervor, dass\b/iu' => 'Aktuell gilt:',
        '/\bim bereitgestellten Kontext\b/iu' => 'derzeit',
        '/\bMit\s+[„"\'`]?(Boss|Chef)[”"\'`]?\s+ist\s+(oft|meist|in der regel)\s+gemeint[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bMit\s+[„"\'`]?(Boss|Chef)[”"\'`]?\s+ist[^\.!\n]*(gemeint|bezeichnet)[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bAuf\s+der\s+(aktuellen|dieser)\s+Seite\s+wird\s+nicht\s+genannt[^\.!\n]*[\.!\n]?/iu' => 'Dafür gibt es aktuell keine belastbare Grundlage. ',
        '/\bAuf\s+(unserer|der)\s+[^\.!\n]{0,80}Seite\s+wird\s+außerdem\s+/iu' => 'Außerdem ',
        '/\bAuf\s+(unserer|der)\s+[^\.!\n]{0,80}Seite\s+wird\s+/iu' => '',
        '/\bAuf\s+(unserer|der)\s+[^\.!\n]{0,80}Seite\s+ist\s+/iu' => '',
        '/\bDafür fehlt mir im bereitgestellten Kontext[^\.!\n]*[\.!\n]?/iu' => 'Dafür gibt es aktuell keine belastbare Grundlage. ',
        '/\bNach unserem Kenntnisstand liegt dafür keine belastbare Grundlage vor\.?/iu' => 'Dafür gibt es aktuell keine belastbare Grundlage.',
        '/\bDafür liegt keine belastbare Grundlage vor\.?/iu' => 'Dafür gibt es aktuell keine belastbare Grundlage.',
        '/\bich habe (hier )?(nur )?(im|auf der)\s+[^\.!\n]*gesucht[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bich kann (hier )?nur auf der seite suchen[^\.!\n]*[\.!\n]?/iu' => '',
        '/\bwird auch genannt\b/iu' => 'heißt',
    );

    foreach ($soft_rewrites as $pattern => $replacement) {
        $reply = preg_replace($pattern, $replacement, $reply);
    }

    $reply = kdcb_chat_strip_markdown_tables($reply);

    if (kdcb_chat_is_reputation_sensitive($latest_user_message)) {
        if (!preg_match('/Diesen Vorwurf können wir so nicht bestätigen\./iu', $reply)) {
            $reply = "Diesen Vorwurf können wir so nicht bestätigen.\n\n" . ltrim($reply);
        }
    }

    $reply = preg_replace('/\R{3,}/u', "\n\n", trim($reply));

    return trim((string) $reply);
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

    $faq_raw = (string) kdcb_get_option('faq_raw', '');
    $context = kdcb_rag_build_context($page_url, $latest_user_message, $faq_raw);
    $context_text = kdcb_rag_context_to_text($context);

    $openai_messages = array(
        array(
            'role' => 'system',
            'content' => kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw, $latest_user_message),
        ),
    );

    foreach ($clean_messages as $message) {
        $openai_messages[] = $message;
    }

    $reply = kdcb_openai_create_response($openai_messages, array(
        'max_output_tokens' => kdcb_chat_wants_long_output($latest_user_message) ? 1600 : 420,
    ));

    if (is_wp_error($reply)) {
        error_log('KDCB chat generation failed: ' . $reply->get_error_code());
        $reply = 'Ich kann gerade keine zuverlässige Antwort erzeugen. Bitte nutzen Sie das Mängelformular oder kontaktieren Sie K&D direkt.';
    } else {
        $reply = kdcb_chat_postprocess_reply($reply, $latest_user_message);
        $reply = kdcb_chat_append_context_link_if_missing(
            $reply,
            $latest_user_message,
            isset($context['sources']) ? (array) $context['sources'] : array()
        );
    }

    return new WP_REST_Response(array(
        'reply' => (string) $reply,
        'sources' => isset($context['sources']) ? $context['sources'] : array(),
        'action' => null,
    ), 200);
}
