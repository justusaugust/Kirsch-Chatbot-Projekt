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

function kdcb_chat_behavior_pack()
{
    $lines = array(
        '- Intent zuerst klären: Kauf/Miete, Leistungen, Projekt/Objekt, FAQ, Kontakt oder Mängel.',
        '- Für konkrete Daten, Preise, Flächen, Adressen nur belastbare Werte aus dem Kontext nennen.',
        '- Bei Überblicksfragen erst die Kernpunkte nennen, dann kurze Details.',
        '- Wenn Kontext fehlt: transparent sagen, was fehlt, und Mängelformular als Kontaktweg anbieten.',
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

function kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw)
{
    $admin_system = trim((string) kdcb_get_option('system_instructions', kdcb_default_options()['kdcb_system_instructions']));
    $context_pack = trim((string) kdcb_get_option('context_pack', kdcb_default_options()['kdcb_context_pack']));
    $compact_faq = kdcb_chat_compact_faq_context($faq_raw, 6);

    $rules = array(
        $admin_system,
        'Du bist der Website-Assistent von K&D Hausbau.',
        'Nutze strikt nur den bereitgestellten Kontext und erfinde keine Fakten.',
        'Kontext-Priorität: 1) CURRENT_PAGE (hoch), 2) WP_SEARCH (mittel), 3) FAQ_MATCHES (niedrig).',
        'Wenn nach einem Überblick (z. B. "Leistungen") gefragt wird, kombiniere CURRENT_PAGE mit relevanten WP_SEARCH-Treffern.',
        'Wenn Informationen fehlen oder uneindeutig sind, sage das klar und verweise auf das Mängelformular als Kontaktweg.',
        'Sicherheitsregel: Frage nicht aktiv nach sensiblen persönlichen Daten.',
        "Antwort-Playbook (kompakt):\n" . kdcb_chat_behavior_pack(),
        'Antwortsprache: Deutsch. Stil: klar, kurz, hilfreich.',
        'Antwortformat: zuerst eine direkte Antwort in 2-6 Sätzen, optional kurze Aufzählung für Details.',
        'Nenne am Ende nur tatsächlich genutzte Quellen als "Quelle: <url>" (eine Zeile, mehrere URLs mit Komma).',
    );

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

    $faq_raw = (string) kdcb_get_option('faq_raw', '');
    $context = kdcb_rag_build_context($page_url, $latest_user_message, $faq_raw);
    $context_text = kdcb_rag_context_to_text($context);

    $openai_messages = array(
        array(
            'role' => 'system',
            'content' => kdcb_chat_build_system_prompt($context_text, $page_title, $faq_raw),
        ),
    );

    foreach ($clean_messages as $message) {
        $openai_messages[] = $message;
    }

    $reply = kdcb_openai_create_response($openai_messages);

    if (is_wp_error($reply)) {
        error_log('KDCB chat generation failed: ' . $reply->get_error_code());
        $reply = 'Ich kann gerade keine zuverlässige Antwort erzeugen. Bitte nutzen Sie das Mängelformular oder kontaktieren Sie K&D direkt.';
    }

    return new WP_REST_Response(array(
        'reply' => (string) $reply,
        'sources' => isset($context['sources']) ? $context['sources'] : array(),
        'action' => null,
    ), 200);
}
