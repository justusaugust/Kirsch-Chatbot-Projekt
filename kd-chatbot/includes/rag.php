<?php

if (!defined('ABSPATH')) {
    exit;
}

function kdcb_rag_clean_text($text, $max_len)
{
    $text = strip_shortcodes((string) $text);
    $text = wp_strip_all_tags($text, true);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $text = kdcb_text_substr($text, $max_len);

    return $text;
}

function kdcb_rag_count_term_hits($text, $terms)
{
    if (!is_array($terms) || empty($terms)) {
        return 0;
    }

    $haystack = kdcb_text_lower((string) $text);
    $hits = 0;

    foreach ($terms as $term) {
        if ($term === '') {
            continue;
        }
        if (strpos($haystack, kdcb_text_lower($term)) !== false) {
            $hits++;
        }
    }

    return $hits;
}

function kdcb_rag_make_focus_snippet($text, $terms, $max_len)
{
    $text = kdcb_rag_clean_text($text, 4000);
    if ($text === '') {
        return '';
    }

    if (!is_array($terms) || empty($terms)) {
        return kdcb_text_substr($text, $max_len);
    }

    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text);
    if (!is_array($sentences) || empty($sentences)) {
        return kdcb_text_substr($text, $max_len);
    }

    $picked = array();
    foreach ($sentences as $sentence) {
        $sentence = trim((string) $sentence);
        if ($sentence === '') {
            continue;
        }

        if (kdcb_rag_count_term_hits($sentence, $terms) > 0) {
            $picked[] = $sentence;
        }

        if (strlen(implode(' ', $picked)) >= $max_len) {
            break;
        }
    }

    if (empty($picked)) {
        return kdcb_text_substr($text, $max_len);
    }

    $candidate = implode(' ', $picked);

    // If keyword matches are sparse, blend in leading context to keep broad coverage.
    if (strlen($candidate) < (int) ($max_len * 0.65)) {
        $leading = kdcb_text_substr($text, $max_len);
        $candidate = trim($candidate . ' ' . $leading);
    }

    return kdcb_text_substr($candidate, $max_len);
}

function kdcb_rag_resolve_page($page_url)
{
    $page_url = esc_url_raw((string) $page_url);
    if ($page_url === '') {
        return null;
    }

    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    $url_host = wp_parse_url($page_url, PHP_URL_HOST);

    if ($site_host && $url_host && strtolower($site_host) !== strtolower($url_host)) {
        return null;
    }

    $post_id = (int) url_to_postid($page_url);

    if ($post_id <= 0) {
        $path = (string) wp_parse_url($page_url, PHP_URL_PATH);
        $path = trim($path, '/');

        if ($path === '') {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                $post_id = $front_page_id;
            }
        } else {
            $post = get_page_by_path($path, OBJECT, array('page', 'post'));
            if ($post instanceof WP_Post) {
                $post_id = (int) $post->ID;
            }
        }
    }

    if ($post_id <= 0) {
        return null;
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
        return null;
    }

    $content = kdcb_rag_clean_text($post->post_content, 1800);

    return array(
        'post_id' => (int) $post_id,
        'title' => get_the_title($post),
        'url' => get_permalink($post),
        'content' => $content,
    );
}

function kdcb_rag_search_posts($query, $exclude_post_id)
{
    $query = kdcb_rag_clean_text($query, 120);
    if ($query === '') {
        return array();
    }

    $args = array(
        'post_type' => array('page', 'post'),
        'post_status' => 'publish',
        'posts_per_page' => 3,
        's' => $query,
        'ignore_sticky_posts' => true,
    );

    if ($exclude_post_id > 0) {
        $args['post__not_in'] = array((int) $exclude_post_id);
    }

    $results = array();
    $query_obj = new WP_Query($args);

    if ($query_obj->have_posts()) {
        foreach ($query_obj->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }

            $excerpt = has_excerpt($post) ? get_the_excerpt($post) : '';
            if ($excerpt === '') {
                $excerpt = kdcb_rag_clean_text($post->post_content, 300);
            } else {
                $excerpt = kdcb_rag_clean_text($excerpt, 300);
            }

            $results[] = array(
                'title' => get_the_title($post),
                'url' => get_permalink($post),
                'snippet' => $excerpt,
            );
        }
    }

    wp_reset_postdata();

    return $results;
}

function kdcb_rag_parse_faq($faq_raw)
{
    $faq_raw = trim((string) $faq_raw);
    if ($faq_raw === '') {
        return array();
    }

    $blocks = preg_split('/\R{2,}/', $faq_raw);
    $pairs = array();

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $question = '';
        $answer = '';

        if (preg_match('/Q:\s*(.+?)\R+A:\s*(.+)/is', $block, $matches)) {
            $question = trim($matches[1]);
            $answer = trim($matches[2]);
        } else {
            $lines = preg_split('/\R/', $block);
            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'Q:') === 0) {
                    $question = trim(substr($line, 2));
                }
                if (stripos($line, 'A:') === 0) {
                    $answer = trim(substr($line, 2));
                }
            }
        }

        if ($question === '' || $answer === '') {
            continue;
        }

        $pairs[] = array(
            'question' => kdcb_rag_clean_text($question, 240),
            'answer' => kdcb_rag_clean_text($answer, 500),
        );
    }

    return $pairs;
}

function kdcb_rag_query_terms($query)
{
    $query = kdcb_text_lower((string) $query);
    preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $matches);

    $stopwords = array(
        'der', 'die', 'das', 'und', 'oder', 'aber', 'eine', 'einer', 'einen', 'einem', 'ist', 'sind',
        'wie', 'was', 'wer', 'wo', 'wann', 'warum', 'wieso', 'kann', 'koennen', 'mit', 'fuer', 'zum', 'zur',
        'von', 'bei', 'den', 'dem', 'des', 'ich', 'wir', 'sie', 'ein', 'auf', 'im', 'in', 'am', 'an', 'zu',
    );

    $terms = array();
    foreach ($matches[0] as $term) {
        if (in_array($term, $stopwords, true)) {
            continue;
        }
        $terms[$term] = true;
    }

    return array_keys($terms);
}

function kdcb_rag_build_search_query($latest_message, $query_terms, $current_page)
{
    $compact_terms = array();

    if (is_array($query_terms) && !empty($query_terms)) {
        $compact_terms = array_slice($query_terms, 0, 5);
    }

    // For section-overview questions, the page title is often the strongest signal.
    if (is_array($current_page) && !empty($current_page['title'])) {
        $title_term = kdcb_text_lower(kdcb_rag_clean_text($current_page['title'], 60));
        if ($title_term !== '' && !in_array($title_term, $compact_terms, true)) {
            if (strpos(kdcb_text_lower((string) $latest_message), $title_term) !== false) {
                array_unshift($compact_terms, $title_term);
            }
        }
    }

    $compact_terms = array_values(array_unique(array_filter($compact_terms)));
    if (!empty($compact_terms)) {
        return implode(' ', array_slice($compact_terms, 0, 5));
    }

    return kdcb_rag_clean_text($latest_message, 120);
}

function kdcb_rag_merge_search_results($primary_results, $fallback_results, $max_results)
{
    $all = array_merge(
        is_array($primary_results) ? $primary_results : array(),
        is_array($fallback_results) ? $fallback_results : array()
    );

    if (empty($all)) {
        return array();
    }

    $by_url = array();
    foreach ($all as $item) {
        if (empty($item['url'])) {
            continue;
        }
        $by_url[$item['url']] = $item;
    }

    $deduped = array_values($by_url);
    usort($deduped, function ($a, $b) {
        $a_hits = isset($a['term_hits']) ? (int) $a['term_hits'] : 0;
        $b_hits = isset($b['term_hits']) ? (int) $b['term_hits'] : 0;
        if ($a_hits === $b_hits) {
            return 0;
        }

        return ($a_hits > $b_hits) ? -1 : 1;
    });

    return array_slice($deduped, 0, $max_results);
}

function kdcb_rag_match_faq($query, $faq_raw, $max_results)
{
    $pairs = kdcb_rag_parse_faq($faq_raw);
    if (empty($pairs)) {
        return array();
    }

    $terms = kdcb_rag_query_terms($query);
    if (empty($terms)) {
        return array_slice($pairs, 0, $max_results);
    }

    $scored = array();

    foreach ($pairs as $pair) {
        $haystack = kdcb_text_lower($pair['question'] . ' ' . $pair['answer']);
        $score = 0;

        foreach ($terms as $term) {
            if (strpos($haystack, $term) !== false) {
                $score++;
            }
        }

        if ($score > 0) {
            $pair['score'] = $score;
            $scored[] = $pair;
        }
    }

    if (empty($scored)) {
        return array();
    }

    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return 0;
        }
        return ($a['score'] > $b['score']) ? -1 : 1;
    });

    $scored = array_slice($scored, 0, $max_results);

    foreach ($scored as &$item) {
        unset($item['score']);
    }
    unset($item);

    return $scored;
}

function kdcb_rag_build_context($page_url, $latest_message, $faq_raw)
{
    $query_terms = kdcb_rag_query_terms($latest_message);
    $context = array(
        'latest_message' => kdcb_rag_clean_text($latest_message, 220),
        'query_terms' => $query_terms,
        'current_page' => null,
        'search_results' => array(),
        'faq_results' => array(),
        'sources' => array(),
    );

    $current_page = kdcb_rag_resolve_page($page_url);
    if (is_array($current_page)) {
        $current_page['focus_snippet'] = kdcb_rag_make_focus_snippet($current_page['content'], $query_terms, 900);
        $context['current_page'] = $current_page;
        $context['sources'][] = array(
            'title' => $current_page['title'],
            'url' => $current_page['url'],
        );
    }

    $exclude_post_id = is_array($current_page) ? (int) $current_page['post_id'] : 0;
    $search_query = kdcb_rag_build_search_query($latest_message, $query_terms, $current_page);
    $search_results = kdcb_rag_search_posts($search_query, $exclude_post_id);

    $fallback_search_results = array();
    if (count($search_results) < 3 && is_array($current_page) && !empty($current_page['title'])) {
        $fallback_search_results = kdcb_rag_search_posts($current_page['title'], $exclude_post_id);
    }

    $search_results = kdcb_rag_merge_search_results($search_results, $fallback_search_results, 3);

    foreach ($search_results as &$item) {
        $item['term_hits'] = kdcb_rag_count_term_hits($item['title'] . ' ' . $item['snippet'], $query_terms);
    }
    unset($item);

    usort($search_results, function ($a, $b) {
        $a_hits = isset($a['term_hits']) ? (int) $a['term_hits'] : 0;
        $b_hits = isset($b['term_hits']) ? (int) $b['term_hits'] : 0;
        if ($a_hits === $b_hits) {
            return 0;
        }

        return ($a_hits > $b_hits) ? -1 : 1;
    });
    $context['search_results'] = $search_results;

    foreach ($search_results as $result) {
        $context['sources'][] = array(
            'title' => $result['title'],
            'url' => $result['url'],
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

    // Dedupe sources by URL.
    $unique = array();
    $clean_sources = array();

    foreach ($context['sources'] as $source) {
        if (empty($source['url']) || isset($unique[$source['url']])) {
            continue;
        }

        $unique[$source['url']] = true;
        $clean_sources[] = array(
            'title' => (string) $source['title'],
            'url' => esc_url_raw((string) $source['url']),
        );
    }

    $context['sources'] = $clean_sources;

    return $context;
}

function kdcb_rag_context_to_text($context)
{
    $chunks = array();

    if (!empty($context['latest_message'])) {
        $chunks[] = "[USER_QUESTION]\n" . $context['latest_message'];
    }

    if (!empty($context['query_terms'])) {
        $chunks[] = "[KEY_TERMS]\n" . implode(', ', $context['query_terms']);
    }

    if (!empty($context['current_page'])) {
        $page = $context['current_page'];
        $page_excerpt = !empty($page['focus_snippet']) ? $page['focus_snippet'] : $page['content'];
        $chunks[] = "[CURRENT_PAGE | PRIORITY: HIGH]\nTitel: " . $page['title'] . "\nURL: " . $page['url'] . "\nRelevanter Auszug: " . $page_excerpt;
    }

    if (!empty($context['search_results'])) {
        $search_chunks = array();
        foreach ($context['search_results'] as $item) {
            $search_chunks[] = "- " . $item['title'] . "\n  URL: " . $item['url'] . "\n  Treffer: " . (isset($item['term_hits']) ? (int) $item['term_hits'] : 0) . "\n  Snippet: " . $item['snippet'];
        }
        $chunks[] = "[WP_SEARCH | PRIORITY: MEDIUM]\n" . implode("\n", $search_chunks);
    }

    if (!empty($context['faq_results'])) {
        $faq_chunks = array();
        foreach ($context['faq_results'] as $faq) {
            $faq_chunks[] = "Q: " . $faq['question'] . "\nA: " . $faq['answer'];
        }
        $chunks[] = "[FAQ_MATCHES | PRIORITY: LOW]\n" . implode("\n\n", $faq_chunks);
    }

    return implode("\n\n", $chunks);
}
