<?php
namespace AIW;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {
    private static $instance = null;
    public static function instance(){
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct(){
        add_action('save_post', [$this, 'maybe_insert_toc_on_save'], 20, 3);
    }
    public function maybe_insert_toc_on_save($post_id, $post, $update){
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
        require_once AIW_PLUGIN_DIR . 'includes/functions-toc.php';
        if (!\AIW\aiw_should_insert_toc($post)) return;
        $content = $post->post_content;
        $new_content = \AIW\aiw_insert_rankmath_toc_after_intro($content);
        if ($new_content !== $content){
            remove_action('save_post', [$this, 'maybe_insert_toc_on_save'], 20);
            wp_update_post(['ID'=>$post_id,'post_content'=>$new_content]);
            add_action('save_post', [$this, 'maybe_insert_toc_on_save'], 20, 3);
        }
    }
}
