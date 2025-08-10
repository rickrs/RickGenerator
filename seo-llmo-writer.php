<?php
/**
 * Plugin Name: SEO+LLMO Writer
 * Description: Gera rascunhos otimizados para SEO+LLMO com IA (página "Gerar Artigo"), seleção dinâmica de modelos e TOC automático apenas para posts.
 * Version: 0.5.4
 * Author: Você
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: seo-llmo-writer
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// Diagnostic on activation
register_activation_hook(__FILE__, function(){
    global $wp_version;
    $errors = [];
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = "PHP 7.4 ou superior é necessário. Versão atual: " . PHP_VERSION;
    }
    if (version_compare($wp_version, '5.8', '<')) {
        $errors[] = "WordPress 5.8 ou superior é necessário. Versão atual: " . $wp_version;
    }
    if (!class_exists('RankMath')) {
        $errors[] = "O plugin Rank Math não foi detectado. Algumas funções podem não funcionar.";
    }
    if (!function_exists('add_submenu_page')) {
        $errors[] = "Função add_submenu_page indisponível (possível conflito com hooks de admin).";
    }
    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die("<h1>Falha na ativação do SEO+LLMO Writer</h1><ul><li>" . implode("</li><li>", array_map('esc_html',$errors)) . "</li></ul><p>Corrija os problemas acima e tente ativar novamente.</p>", "Erro de ativação", array('back_link' => true));
    }
});


// Min PHP check
if ( version_compare(PHP_VERSION, '7.4', '<') ) {
    add_action('admin_notices', function(){
        echo '<div class="notice notice-error"><p>SEO+LLMO Writer requer PHP 7.4+.</p></div>';
    });
    return;
}


define( 'AIW_PLUGIN_VERSION', '0.5.4' );
define( 'AIW_PLUGIN_FILE', __FILE__ );
define( 'AIW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIW_PLUGIN_DIR . 'includes/class-aiw-plugin.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-admin.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-generator.php';
require_once AIW_PLUGIN_DIR . 'includes/functions-toc.php';
require_once AIW_PLUGIN_DIR . 'includes/functions-models.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-diagnostics.php';

add_action( 'plugins_loaded', function(){
    \AIW\Plugin::instance();
    \AIW\Admin::instance();
    \AIW\Generator::instance();
    \AIW\Diagnostics::add_hooks();
});

register_activation_hook( __FILE__, function(){ update_option('aiw_post_activation_notice', 1, false );
    $defaults = [
        'ui_lang' => get_locale(),
        'region' => 'BR',
        'toc_enabled' => true,
        'toc_allowed_types' => ['post'],
        'toc_blocked_types' => ['fast_web_story', 'web-story', 'web_stories'],
        'provider_active' => 'openai',
        'providers' => [
            'openai' => ['api_key' => ''],
            'gemini' => ['api_key' => ''],
            'anthropic' => ['api_key' => ''],
        ],
        'models' => [
            'openai' => 'gpt-4o-mini',
            'gemini' => 'gemini-1.5-flash',
            'anthropic' => 'claude-3-5-sonnet',
            'custom' => ''
        ],
        'model_mode' => 'preset',
        'min_words' => 1500,
        'tone' => 'didático'
    ];
    $existing = get_option('aiw_settings');
    if (!is_array($existing)) {
        add_option('aiw_settings', $defaults, '', false);
    } else {
        update_option('aiw_settings', array_merge($defaults, $existing));
    }
});

// Ensure thumbnails and a 1200x675 image size for SEO/Discover
add_action('after_setup_theme', function(){
    add_theme_support('post-thumbnails');
    if (function_exists('add_image_size')){
        add_image_size('aiw_og', 1200, 675, true);
    }
});
