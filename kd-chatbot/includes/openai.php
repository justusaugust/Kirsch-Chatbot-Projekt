<?php

if (!defined('ABSPATH')) {
    exit;
}

function kdcb_openai_normalize_messages($messages)
{
    $normalized = array();

    foreach ((array) $messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = isset($message['role']) ? strtolower((string) $message['role']) : 'user';
        if (!in_array($role, array('system', 'user', 'assistant'), true)) {
            $role = 'user';
        }

        $content = isset($message['content']) ? trim((string) $message['content']) : '';
        if ($content === '') {
            continue;
        }

        $normalized[] = array(
            'role' => $role,
            'content' => $content,
        );
    }

    return $normalized;
}

function kdcb_openai_extract_text($decoded)
{
    if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
        $text = trim($decoded['output_text']);
        if ($text !== '') {
            return $text;
        }
    }

    $buffer = array();

    if (!empty($decoded['output']) && is_array($decoded['output'])) {
        foreach ($decoded['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content_item) {
                    if (isset($content_item['text']) && is_string($content_item['text'])) {
                        $line = trim($content_item['text']);
                        if ($line !== '') {
                            $buffer[] = $line;
                        }
                    }
                }
            }
        }
    }

    return trim(implode("\n", $buffer));
}

function kdcb_openai_create_response($messages)
{
    $api_key = trim((string) kdcb_get_option('openai_api_key', ''));
    if ($api_key === '') {
        return new WP_Error('kdcb_openai_missing_key', 'OpenAI API key fehlt.');
    }

    $model = trim((string) kdcb_get_option('model', 'gpt-5.2'));
    if ($model === '') {
        $model = 'gpt-5.2';
    }

    $payload = array(
        'model' => $model,
        'store' => false,
        'input' => kdcb_openai_normalize_messages($messages),
    );

    if (empty($payload['input'])) {
        return new WP_Error('kdcb_openai_invalid_input', 'Keine gültigen Nachrichten für OpenAI.');
    }

    $response = wp_remote_post('https://api.openai.com/v1/responses', array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        error_log('KDCB OpenAI transport error: ' . $response->get_error_code());
        return new WP_Error('kdcb_openai_transport', 'Transportfehler bei OpenAI Anfrage.');
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        error_log('KDCB OpenAI HTTP error: ' . $status);
        return new WP_Error('kdcb_openai_http_' . $status, 'OpenAI API Fehler.');
    }

    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($decoded)) {
        return new WP_Error('kdcb_openai_decode', 'OpenAI Antwort konnte nicht gelesen werden.');
    }

    $text = kdcb_openai_extract_text($decoded);
    if ($text === '') {
        return new WP_Error('kdcb_openai_empty', 'OpenAI Antwort ist leer.');
    }

    return $text;
}
