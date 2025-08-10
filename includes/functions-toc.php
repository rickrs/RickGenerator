<?php
namespace AIW;

if ( ! defined( 'ABSPATH' ) ) exit;

function aiw_should_insert_toc($post){
    if (empty($post) || !isset($post->post_type)) return false;
    $s = get_option('aiw_settings', []);
    if (empty($s['toc_enabled'])) return false;
    if (!defined('RANK_MATH_VERSION')) return false;

    $allowed = isset($s['toc_allowed_types']) && is_array($s['toc_allowed_types']) ? $s['toc_allowed_types'] : ['post'];
    $blocked = isset($s['toc_blocked_types']) && is_array($s['toc_blocked_types']) ? $s['toc_blocked_types'] : ['fast_web_story','web-story','web_stories'];

    if (in_array($post->post_type, $blocked, true)) return false;
    if (!in_array($post->post_type, $allowed, true)) return false;
    return true;
}

function aiw_insert_rankmath_toc_after_intro($content){
    if (stripos($content, '[rank_math_toc]') !== false) return $content;

    if (function_exists('has_blocks') && has_blocks($content)) {
        $blocks = parse_blocks($content);
        $out = [];
        $inserted = false;
        foreach ($blocks as $b) {
            $out[] = $b;
            if (!$inserted && in_array($b['blockName'], ['core/paragraph','core/quote','core/list'], true)) {
                $out[] = [
                    'blockName' => 'core/shortcode',
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => '<p>[rank_math_toc]</p>',
                    'innerContent' => ['[rank_math_toc]'],
                ];
                $inserted = true;
            }
        }
        if ($inserted) return serialize_blocks($out);
    }

    if (preg_match('/<\/(p|blockquote|ul|ol)>/i', $content)) {
        return preg_replace('/<\/(p|blockquote|ul|ol)>/i', '</$1>' . PHP_EOL . '[rank_math_toc]' . PHP_EOL, $content, 1);
    }

    return "[rank_math_toc]\n\n" . $content;
}
