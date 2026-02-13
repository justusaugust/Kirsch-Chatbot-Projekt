=== KDCB Chatbot ===
Contributors: kdcb
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sitewide floating chat widget with lightweight RAG (current page + WP search + FAQ) and a separate defect form email flow.

== Description ==
KDCB Chatbot provides:

* Floating chat widget on all frontend pages
* REST API endpoints:
  * POST /wp-json/kdcb/v1/chat
  * POST /wp-json/kdcb/v1/submit_defect
  * GET /wp-json/kdcb/v1/config
* RAG without vector DB:
  * Current page context (url_to_postid / page mapping)
  * WordPress search snippets (top 3)
  * FAQ keyword matching (admin-defined Q/A pairs)
  * Optional compact context pack (always injected into system prompt)
* Separate defect flow that sends email via wp_mail
* Zero server-side chat storage and OpenAI Responses API with store:false

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/ or install the ZIP via WordPress Admin.
2. Activate "KDCB Chatbot".
3. Open Settings -> KDCB Chatbot.
4. Configure OpenAI API key, model, system instructions, FAQ, and defect email recipient.
5. Ensure widget is enabled.

== Frequently Asked Questions ==
= Does this plugin store chat logs in WordPress DB? =
No. Chat messages are only kept in browser localStorage. The server does not persist conversations.

= Is defect form data sent to OpenAI? =
No. Defect form fields are only sent to /submit_defect and emailed via wp_mail.

== Changelog ==
= 1.0.0 =
* Initial release.
