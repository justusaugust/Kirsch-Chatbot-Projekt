<?php
/**
 * Plugin Name: KDCB Chatbot
 * Description: Sitewide floating chat widget with lightweight RAG and a separate defect form flow.
 * Version: 1.0.5
 * Author: K&D Hausbau
 * Text Domain: kd-chatbot
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KDCB_PLUGIN_VERSION', '1.0.5');
define('KDCB_PLUGIN_FILE', __FILE__);
define('KDCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KDCB_PLUGIN_URL', plugin_dir_url(__FILE__));

function kdcb_default_options()
{
    return array(
        'kdcb_enable_widget' => 1,
        'kdcb_openai_api_key' => '',
        'kdcb_model' => 'gpt-5.2',
        'kdcb_system_instructions' => "Du bist der offizielle KI-Mitarbeiter der Kirsch & Drechsler Hausbau GmbH. Antworte präzise, verbindlich und nur auf Basis des bereitgestellten Kontexts.",
        'kdcb_context_pack' => "K&D Profil (kompakt): Kirsch & Drechsler Hausbau GmbH entwickelt, verkauft und verwaltet Wohnimmobilien in Potsdam und Umgebung. Kernleistungen: Beratung, Projektentwicklung & Bauträger, Hausverwaltung & Vermietung. Kommunikationslinie: souverän, lösungsorientiert, in wir-Form. Bei Vorwürfen keine Spekulation, unbelegte Behauptungen sachlich zurückweisen und konkrete Klärungswege anbieten.",
        'kdcb_faq_raw' => "",
        'kdcb_defect_email_to' => get_option('admin_email'),
        'kdcb_chat_rate_limit_hourly' => 60,
        'kdcb_defect_rate_limit_daily' => 5,
    );
}

function kdcb_get_option($name, $fallback = null)
{
    $key = (strpos($name, 'kdcb_') === 0) ? $name : 'kdcb_' . $name;
    $defaults = kdcb_default_options();

    if ($fallback === null && isset($defaults[$key])) {
        $fallback = $defaults[$key];
    }

    return get_option($key, $fallback);
}

function kdcb_get_defect_trades()
{
    return array(
        'Dach',
        'Fenster',
        'Sanitär',
        'Elektro',
        'Fassade',
        'Innenausbau',
        'Sonstiges',
    );
}

function kdcb_get_defect_urgencies()
{
    return array('niedrig', 'mittel', 'hoch');
}

function kdcb_activate_plugin()
{
    foreach (kdcb_default_options() as $key => $value) {
        if (get_option($key, null) === null) {
            add_option($key, $value);
        }
    }
}

function kdcb_deactivate_plugin()
{
    // No-op by design.
}

register_activation_hook(__FILE__, 'kdcb_activate_plugin');
register_deactivation_hook(__FILE__, 'kdcb_deactivate_plugin');

require_once KDCB_PLUGIN_DIR . 'includes/admin-settings.php';
require_once KDCB_PLUGIN_DIR . 'includes/security.php';
require_once KDCB_PLUGIN_DIR . 'includes/rag.php';
require_once KDCB_PLUGIN_DIR . 'includes/openai.php';
require_once KDCB_PLUGIN_DIR . 'includes/rest-chat.php';
require_once KDCB_PLUGIN_DIR . 'includes/rest-defect.php';

function kdcb_enqueue_widget_assets()
{
    if (is_admin()) {
        return;
    }

    if (!((bool) kdcb_get_option('enable_widget', 1))) {
        return;
    }

    wp_enqueue_style(
        'kdcb-widget',
        KDCB_PLUGIN_URL . 'assets/widget.css',
        array(),
        KDCB_PLUGIN_VERSION
    );

    wp_enqueue_script(
        'kdcb-widget',
        KDCB_PLUGIN_URL . 'assets/widget.js',
        array(),
        KDCB_PLUGIN_VERSION,
        true
    );

    wp_localize_script('kdcb-widget', 'KDCB_CONFIG', array(
        'enabled' => true,
        'rest_base_url' => esc_url_raw(rest_url('kdcb/v1/')),
        'chat_url' => esc_url_raw(rest_url('kdcb/v1/chat')),
        'defect_url' => esc_url_raw(rest_url('kdcb/v1/submit_defect')),
        'config_url' => esc_url_raw(rest_url('kdcb/v1/config')),
        'max_messages' => 12,
        'strings' => array(
            'toggle_label' => 'K&D Chat',
            'title' => 'K&D Hausbau Chat',
            'placeholder' => 'Ihre Nachricht ...',
            'send' => 'Senden',
            'open_defect' => 'Mängel melden',
            'loading' => 'Antwort wird geladen ...',
            'error' => 'Der Chat ist aktuell nicht erreichbar. Bitte versuchen Sie es später erneut.',
        ),
        'defect_schema' => array(
            'trades' => kdcb_get_defect_trades(),
            'urgencies' => kdcb_get_defect_urgencies(),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'kdcb_enqueue_widget_assets');
