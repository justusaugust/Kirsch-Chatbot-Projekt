<?php

if (!defined('ABSPATH')) {
    exit;
}

function kdcb_register_defect_route()
{
    register_rest_route('kdcb/v1', '/submit_defect', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'kdcb_rest_submit_defect_handler',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'kdcb_register_defect_route');

function kdcb_defect_validate_required($fields)
{
    foreach ($fields as $key => $value) {
        if (trim((string) $value) === '') {
            return new WP_Error(
                'kdcb_missing_field_' . sanitize_key($key),
                'Pflichtfeld fehlt: ' . $key,
                array('status' => 400)
            );
        }
    }

    return true;
}

function kdcb_rest_submit_defect_handler($request)
{
    $origin_check = kdcb_validate_origin($request);
    if (is_wp_error($origin_check)) {
        return $origin_check;
    }

    $rate_limit = (int) kdcb_get_option('defect_rate_limit_daily', 5);
    $rate_check = kdcb_enforce_rate_limit('defect', $rate_limit, DAY_IN_SECONDS);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('kdcb_invalid_payload', 'Ungültige JSON-Daten.', array('status' => 400));
    }

    $payload_encoded = wp_json_encode($payload);
    if (is_string($payload_encoded) && strlen($payload_encoded) > 30000) {
        return new WP_Error('kdcb_payload_too_large', 'Payload ist zu gross.', array('status' => 413));
    }

    $full_name = kdcb_sanitize_line(isset($payload['full_name']) ? $payload['full_name'] : '', 120);
    $email = sanitize_email(isset($payload['email']) ? (string) $payload['email'] : '');
    $phone = kdcb_sanitize_line(isset($payload['phone']) ? $payload['phone'] : '', 80);
    $object_address = kdcb_sanitize_line(isset($payload['object_address']) ? $payload['object_address'] : '', 220);
    $trade = kdcb_sanitize_line(isset($payload['trade']) ? $payload['trade'] : '', 80);
    $defect_location = kdcb_sanitize_line(isset($payload['defect_location']) ? $payload['defect_location'] : '', 120);
    $defect_description = kdcb_sanitize_paragraph(isset($payload['defect_description']) ? $payload['defect_description'] : '', 2000);
    $urgency = kdcb_sanitize_line(isset($payload['urgency']) ? $payload['urgency'] : '', 20);
    $callback_requested = !empty($payload['callback_requested']);
    $page_url = esc_url_raw(isset($payload['page_url']) ? (string) $payload['page_url'] : '');
    $session_id = kdcb_sanitize_line(isset($payload['session_id']) ? $payload['session_id'] : '', 120);

    $required_check = kdcb_defect_validate_required(array(
        'full_name' => $full_name,
        'email' => $email,
        'object_address' => $object_address,
        'trade' => $trade,
        'defect_location' => $defect_location,
        'defect_description' => $defect_description,
        'urgency' => $urgency,
    ));

    if (is_wp_error($required_check)) {
        return $required_check;
    }

    if (!is_email($email)) {
        return new WP_Error('kdcb_invalid_email', 'E-Mail Adresse ist ungültig.', array('status' => 400));
    }

    if ($callback_requested && $phone === '') {
        return new WP_Error(
            'kdcb_missing_phone_for_callback',
            'Telefonnummer ist erforderlich, wenn Rückruf erwünscht ist.',
            array('status' => 400)
        );
    }

    $allowed_trades = kdcb_get_defect_trades();
    if (!in_array($trade, $allowed_trades, true)) {
        return new WP_Error('kdcb_invalid_trade', 'Gewerk/Bereich ist ungültig.', array('status' => 400));
    }

    $allowed_urgencies = kdcb_get_defect_urgencies();
    if (!in_array($urgency, $allowed_urgencies, true)) {
        return new WP_Error('kdcb_invalid_urgency', 'Dringlichkeit ist ungültig.', array('status' => 400));
    }

    $recipient = kdcb_get_option('defect_email_to', get_option('admin_email'));
    if (!is_email($recipient)) {
        $recipient = get_option('admin_email');
    }

    $subject = sprintf('Mängelmeldung von %s | %s', $full_name, $object_address);

    $body_lines = array(
        'Neue Mängelmeldung',
        '===================',
        'Zeitpunkt: ' . current_time('mysql'),
        'Session-ID: ' . $session_id,
        '',
        'Vor- und Nachname: ' . $full_name,
        'E-Mail: ' . $email,
        'Telefonnummer: ' . ($phone !== '' ? $phone : '-'),
        'Objektadresse: ' . $object_address,
        'Gewerk/Bereich: ' . $trade,
        'Ort des Mangels: ' . $defect_location,
        'Dringlichkeit: ' . $urgency,
        'Rückruf erwünscht: ' . ($callback_requested ? 'Ja' : 'Nein'),
        'Gemeldet von URL: ' . ($page_url !== '' ? $page_url : '-'),
        '',
        'Beschreibung des Mangels:',
        $defect_description,
    );

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $full_name . ' <' . $email . '>',
    );

    $sent = wp_mail($recipient, $subject, implode("\n", $body_lines), $headers);

    if (!$sent) {
        return new WP_Error('kdcb_mail_failed', 'E-Mail konnte nicht versendet werden.', array('status' => 500));
    }

    return new WP_REST_Response(array('ok' => true), 200);
}
