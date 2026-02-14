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

/**
 * Send a request to OpenAI Responses API, with optional tool-calling loop.
 *
 * Options:
 *   max_output_tokens  int        (default 420, clamped 120-2000)
 *   tools              array      Tool definitions for function calling
 *   tool_handler       callable   fn($name, $arguments) => string
 */
function kdcb_openai_create_response($messages, $options = array())
{
    $api_key = trim((string) kdcb_get_option('openai_api_key', ''));
    if ($api_key === '') {
        return new WP_Error('kdcb_openai_missing_key', 'OpenAI API key fehlt.');
    }

    $model = trim((string) kdcb_get_option('model', 'gpt-5.2'));
    if ($model === '') {
        $model = 'gpt-5.2';
    }

    $max_output_tokens = isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 420;
    if ($max_output_tokens < 120) {
        $max_output_tokens = 120;
    }
    if ($max_output_tokens > 2000) {
        $max_output_tokens = 2000;
    }

    $tools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : array();
    $tool_handler = isset($options['tool_handler']) && is_callable($options['tool_handler']) ? $options['tool_handler'] : null;

    $input = kdcb_openai_normalize_messages($messages);
    if (empty($input)) {
        return new WP_Error('kdcb_openai_invalid_input', 'Keine gültigen Nachrichten für OpenAI.');
    }

    $total_usage = array('input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0);
    $max_rounds = (!empty($tools) && $tool_handler) ? 4 : 1;
    $tool_used = false;

    for ($round = 0; $round < $max_rounds; $round++) {
        $payload = array(
            'model' => $model,
            'store' => false,
            'max_output_tokens' => $max_output_tokens,
            'input' => $input,
        );

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            // Only force text on the last allowed round after tools have been used.
            if ($tool_used && $round >= $max_rounds - 1) {
                $payload['tool_choice'] = 'none';
            }
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
            error_log('KDCB OpenAI HTTP error: ' . $status . ' body: ' . wp_remote_retrieve_body($response));
            return new WP_Error('kdcb_openai_http_' . $status, 'OpenAI API Fehler.');
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return new WP_Error('kdcb_openai_decode', 'OpenAI Antwort konnte nicht gelesen werden.');
        }

        // Accumulate usage across rounds.
        $round_usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : array();
        $total_usage['input_tokens'] += isset($round_usage['input_tokens']) ? (int) $round_usage['input_tokens'] : 0;
        $total_usage['output_tokens'] += isset($round_usage['output_tokens']) ? (int) $round_usage['output_tokens'] : 0;
        $total_usage['total_tokens'] += isset($round_usage['total_tokens']) ? (int) $round_usage['total_tokens'] : 0;

        // Check for function calls in output.
        $function_calls = array();
        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            foreach ($decoded['output'] as $item) {
                if (isset($item['type']) && $item['type'] === 'function_call') {
                    $function_calls[] = $item;
                }
            }
        }

        // No function calls: extract text and return.
        if (empty($function_calls) || !$tool_handler) {
            $text = kdcb_openai_extract_text($decoded);
            if ($text === '') {
                return new WP_Error('kdcb_openai_empty', 'OpenAI Antwort ist leer.');
            }

            return array(
                'text' => $text,
                'usage' => $total_usage,
            );
        }

        // Append all output items to input for continuation.
        $tool_used = true;
        foreach ($decoded['output'] as $output_item) {
            $input[] = $output_item;
        }

        // Execute each tool call and append results.
        foreach ($function_calls as $fc) {
            $fc_name = isset($fc['name']) ? (string) $fc['name'] : '';
            $fc_args = json_decode(isset($fc['arguments']) ? (string) $fc['arguments'] : '{}', true);
            if (!is_array($fc_args)) {
                $fc_args = array();
            }

            $tool_result = call_user_func($tool_handler, $fc_name, $fc_args);

            $input[] = array(
                'type' => 'function_call_output',
                'call_id' => isset($fc['call_id']) ? (string) $fc['call_id'] : '',
                'output' => (string) $tool_result,
            );
        }
    }

    // Exhausted rounds without a final text response.
    return new WP_Error('kdcb_openai_tool_loop', 'Tool-Aufrufe konnten nicht abgeschlossen werden.');
}
