<?php

if (!defined('ABSPATH')) {
    exit;
}

function kdcb_register_settings_page()
{
    add_options_page(
        'KDCB Chatbot',
        'KDCB Chatbot',
        'manage_options',
        'kdcb-chatbot',
        'kdcb_render_settings_page'
    );
}
add_action('admin_menu', 'kdcb_register_settings_page');

function kdcb_register_settings()
{
    register_setting('kdcb_settings_group', 'kdcb_enable_widget', array(
        'type' => 'boolean',
        'sanitize_callback' => 'kdcb_sanitize_checkbox',
        'default' => 1,
    ));

    register_setting('kdcb_settings_group', 'kdcb_openai_api_key', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_api_key',
        'default' => '',
    ));

    register_setting('kdcb_settings_group', 'kdcb_model', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_model',
        'default' => 'gpt-5.2',
    ));

    register_setting('kdcb_settings_group', 'kdcb_system_instructions', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_system_instructions',
        'default' => kdcb_default_options()['kdcb_system_instructions'],
    ));

    register_setting('kdcb_settings_group', 'kdcb_context_pack', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_context_pack',
        'default' => kdcb_default_options()['kdcb_context_pack'],
    ));

    register_setting('kdcb_settings_group', 'kdcb_faq_raw', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_faq_raw',
        'default' => '',
    ));

    register_setting('kdcb_settings_group', 'kdcb_defect_email_to', array(
        'type' => 'string',
        'sanitize_callback' => 'kdcb_sanitize_defect_email',
        'default' => get_option('admin_email'),
    ));

    register_setting('kdcb_settings_group', 'kdcb_chat_rate_limit_hourly', array(
        'type' => 'integer',
        'sanitize_callback' => 'kdcb_sanitize_chat_rate_limit',
        'default' => 60,
    ));

    register_setting('kdcb_settings_group', 'kdcb_defect_rate_limit_daily', array(
        'type' => 'integer',
        'sanitize_callback' => 'kdcb_sanitize_defect_rate_limit',
        'default' => 5,
    ));
}
add_action('admin_init', 'kdcb_register_settings');

function kdcb_sanitize_checkbox($value)
{
    return empty($value) ? 0 : 1;
}

function kdcb_sanitize_api_key($value)
{
    return sanitize_text_field((string) $value);
}

function kdcb_sanitize_model($value)
{
    $model = sanitize_text_field((string) $value);
    if ($model === '') {
        $model = 'gpt-5.2';
    }

    return $model;
}

function kdcb_sanitize_system_instructions($value)
{
    return sanitize_textarea_field((string) $value);
}

function kdcb_sanitize_context_pack($value)
{
    return sanitize_textarea_field((string) $value);
}

function kdcb_sanitize_faq_raw($value)
{
    return sanitize_textarea_field((string) $value);
}

function kdcb_sanitize_defect_email($value)
{
    $email = sanitize_email((string) $value);
    if ($email === '' || !is_email($email)) {
        add_settings_error('kdcb_messages', 'kdcb_email_invalid', 'Bitte geben Sie eine gültige Empfänger-E-Mail ein.', 'error');
        return get_option('admin_email');
    }

    return $email;
}

function kdcb_sanitize_chat_rate_limit($value)
{
    $limit = absint($value);
    return ($limit > 0) ? $limit : 60;
}

function kdcb_sanitize_defect_rate_limit($value)
{
    $limit = absint($value);
    return ($limit > 0) ? $limit : 5;
}

function kdcb_maybe_handle_test_email()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_POST['kdcb_send_test_email'])) {
        return;
    }

    check_admin_referer('kdcb_send_test_email_action', 'kdcb_test_email_nonce');

    $recipient = kdcb_get_option('defect_email_to', get_option('admin_email'));
    $subject = 'KDCB Chatbot Test E-Mail';
    $body = "Dies ist eine Test-E-Mail vom KDCB Chatbot Plugin.\n\nZeitpunkt: " . current_time('mysql');

    $sent = wp_mail($recipient, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));

    if ($sent) {
        add_settings_error('kdcb_messages', 'kdcb_test_mail_ok', 'Test-E-Mail wurde versendet.', 'updated');
    } else {
        add_settings_error('kdcb_messages', 'kdcb_test_mail_failed', 'Test-E-Mail konnte nicht versendet werden.', 'error');
    }
}

function kdcb_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    kdcb_maybe_handle_test_email();
    settings_errors('kdcb_messages');

    $enable_widget = (int) kdcb_get_option('enable_widget', 1);
    $api_key = (string) kdcb_get_option('openai_api_key', '');
    $model = (string) kdcb_get_option('model', 'gpt-5.2');
    $system_instructions = (string) kdcb_get_option('system_instructions', kdcb_default_options()['kdcb_system_instructions']);
    $context_pack = (string) kdcb_get_option('context_pack', kdcb_default_options()['kdcb_context_pack']);
    $faq_raw = (string) kdcb_get_option('faq_raw', '');
    $defect_email_to = (string) kdcb_get_option('defect_email_to', get_option('admin_email'));
    $chat_rate_limit = (int) kdcb_get_option('chat_rate_limit_hourly', 60);
    $defect_rate_limit = (int) kdcb_get_option('defect_rate_limit_daily', 5);
    ?>
    <div class="wrap">
        <h1>KDCB Chatbot</h1>
        <p>Konfiguration für Chat-Widget, OpenAI Anbindung und Mängelformular.</p>

        <form method="post" action="options.php">
            <?php settings_fields('kdcb_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="kdcb_enable_widget">Widget aktivieren</label></th>
                    <td>
                        <input type="checkbox" id="kdcb_enable_widget" name="kdcb_enable_widget" value="1" <?php checked(1, $enable_widget); ?> />
                        <p class="description">Blendet das schwebende Chat-Widget auf allen Frontend-Seiten ein.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_widget_mode">Widget Modus</label></th>
                    <td>
                        <input type="text" id="kdcb_widget_mode" value="Floating (v1)" class="regular-text" readonly />
                        <p class="description">In Version 1 ist nur der schwebende Modus verfügbar.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <input type="password" id="kdcb_openai_api_key" name="kdcb_openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Wird nur in WordPress Optionen gespeichert und serverseitig für Responses API genutzt.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_model">Modell</label></th>
                    <td>
                        <input type="text" id="kdcb_model" name="kdcb_model" value="<?php echo esc_attr($model); ?>" class="regular-text" />
                        <p class="description">Standard: <code>gpt-5.2</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_system_instructions">System Instructions</label></th>
                    <td>
                        <textarea id="kdcb_system_instructions" name="kdcb_system_instructions" rows="8" class="large-text code"><?php echo esc_textarea($system_instructions); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_context_pack">Komprimierter Kontext (immer aktiv)</label></th>
                    <td>
                        <textarea id="kdcb_context_pack" name="kdcb_context_pack" rows="8" class="large-text code"><?php echo esc_textarea($context_pack); ?></textarea>
                        <p class="description">Kurzer Wissensblock für FAQ-Kernaussagen, Terminologie und Antwortstil. Wird bei jeder Chatanfrage als kompakter Kontext mitgegeben.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_faq_raw">FAQ (Q/A Paare)</label></th>
                    <td>
                        <textarea id="kdcb_faq_raw" name="kdcb_faq_raw" rows="10" class="large-text code"><?php echo esc_textarea($faq_raw); ?></textarea>
                        <p class="description">Format: <code>Q: ...\nA: ...</code> und Paare mit Leerzeile trennen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_defect_email_to">Empfänger E-Mail für Mängel</label></th>
                    <td>
                        <input type="email" id="kdcb_defect_email_to" name="kdcb_defect_email_to" value="<?php echo esc_attr($defect_email_to); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_chat_rate_limit_hourly">Chat Rate Limit (pro Stunde / IP)</label></th>
                    <td>
                        <input type="number" id="kdcb_chat_rate_limit_hourly" name="kdcb_chat_rate_limit_hourly" value="<?php echo esc_attr($chat_rate_limit); ?>" min="1" step="1" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kdcb_defect_rate_limit_daily">Defect Rate Limit (pro Tag / IP)</label></th>
                    <td>
                        <input type="number" id="kdcb_defect_rate_limit_daily" name="kdcb_defect_rate_limit_daily" value="<?php echo esc_attr($defect_rate_limit); ?>" min="1" step="1" />
                    </td>
                </tr>
            </table>
            <?php submit_button('Einstellungen speichern'); ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field('kdcb_send_test_email_action', 'kdcb_test_email_nonce'); ?>
            <input type="hidden" name="kdcb_send_test_email" value="1" />
            <?php submit_button('Test E-Mail senden', 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}
