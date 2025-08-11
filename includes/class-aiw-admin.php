<?php
namespace AIW;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    private static $instance = null;
    public static function instance(){
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct(){
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'maybe_notice']);
    }
    public function maybe_notice(){
        if (isset($_GET['aiw_error'])){
            echo '<div class="notice notice-error"><p><strong>SEO+LLMO Writer:</strong> '
                 . esc_html(wp_unslash($_GET['aiw_error'])) . '</p></div>';
        }
        if (isset($_GET['aiw_generated'])){
            $slug = isset($_GET['aiw_slug']) ? sanitize_title(wp_unslash($_GET['aiw_slug'])) : '';
            $kw = isset($_GET['aiw_kw']) ? sanitize_text_field(wp_unslash($_GET['aiw_kw'])) : '';
            $msg = '<strong>SEO+LLMO Writer:</strong> Rascunho gerado com sucesso.';
            if ($slug) $msg .= ' Slug: <code>'.$slug.'</code>.';
            if ($kw) $msg .= ' Palavra-chave: <code>'.$kw.'</code>.';
            if (!empty($_GET['post'])){
                $chk = admin_url('admin.php?page=aiw-checklist&post_id='.(int)$_GET['post']);
                $msg .= ' <a class="button button-small" href="'.$chk.'">Abrir Checklist</a>';
            }
            echo '<div class="notice notice-success"><p>'.$msg.'</p></div>';
        }
    }
    public function enqueue_assets($hook=''){
        if (is_string($hook) && (strpos($hook, 'aiw-settings') !== false || strpos($hook, 'aiw-generate') !== false)) {
            wp_enqueue_style('aiw-admin', AIW_PLUGIN_URL . 'assets/admin.css', [], AIW_PLUGIN_VERSION);
            wp_enqueue_script('aiw-admin-js', AIW_PLUGIN_URL . 'assets/admin.js', ['jquery'], AIW_PLUGIN_VERSION, true);
            wp_localize_script('aiw-admin-js', 'AIW_MODELS', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('aiw_models_nonce')
            ]);
        }
    }
    public function add_menu(){
        add_menu_page(
            __('SEO+LLMO Writer', 'seo-llmo-writer'),
            __('SEO+LLMO Writer', 'seo-llmo-writer'),
            'manage_options',
            'aiw-settings',
            [$this, 'render_settings_page'],
            'dashicons-analytics',
            58
        );
        if (class_exists('\\AIW\\Generator')) {
        add_submenu_page(
            'aiw-settings',
            __('Gerar Artigo', 'seo-llmo-writer'),
            __('Gerar Artigo', 'seo-llmo-writer'),
            'edit_posts',
            'aiw-generate',
            ['\\AIW\\Generator','render_generate_page']
        );
        }
        
        if (class_exists('\\AIW\\Generator')) {
        add_submenu_page(
            'aiw-settings',
            __('Checklist', 'seo-llmo-writer'),
            __('Checklist', 'seo-llmo-writer'),
            'edit_posts',
            'aiw-checklist',
            ['\\AIW\\Generator','render_checklist_page']
        );
        }
        
    }
    public function register_settings(){
        register_setting('aiw_settings_group', 'aiw_settings', ['sanitize_callback' => [$this, 'sanitize_settings']]);
        // Geral
        add_settings_section('aiw_general', __('Geral', 'seo-llmo-writer'), function(){
            echo '<p>' . esc_html__('Configurações gerais.', 'seo-llmo-writer') . '</p>';
        }, 'aiw_settings');
        add_settings_field('ui_lang', __('Idioma da interface', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = $opt['ui_lang'] ?? get_locale();
            echo '<input type="text" name="aiw_settings[ui_lang]" value="' . esc_attr($val) . '" class="regular-text" />';
        }, 'aiw_settings', 'aiw_general');
        add_settings_field('region', __('Região de conteúdo', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = $opt['region'] ?? 'BR';
            echo '<select name="aiw_settings[region]">';
            foreach (['BR'=>'Brasil','LATAM'=>'LatAm','GLOBAL'=>'Global'] as $k=>$label){
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
            }
            echo '</select>';
        }, 'aiw_settings', 'aiw_general');
        // TOC
        add_settings_section('aiw_toc', __('Tabela de Conteúdos (Rank Math TOC)', 'seo-llmo-writer'), function(){
            echo '<p>' . esc_html__('Inserir automaticamente [rank_math_toc] apenas para tipos permitidos.', 'seo-llmo-writer') . '</p>';
            if (!defined('RANK_MATH_VERSION')){
                echo '<p><strong style="color:#c00">Rank Math não detectado. A inserção automática ficará inativa.</strong></p>';
            }
        }, 'aiw_settings');
        add_settings_field('toc_enabled', __('Ativar inserção automática', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = !empty($opt['toc_enabled']);
            echo '<label><input type="checkbox" name="aiw_settings[toc_enabled]" value="1" '.checked($val, true, false).' /> '
                . esc_html__('Inserir [rank_math_toc] após o primeiro parágrafo', 'seo-llmo-writer') . '</label>';
        }, 'aiw_settings', 'aiw_toc');
        add_settings_field('toc_allowed_types', __('Tipos permitidos', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $arr = $opt['toc_allowed_types'] ?? ['post'];
            $val = is_array($arr) ? implode(',', $arr) : $arr;
            echo '<input type="text" name="aiw_settings[toc_allowed_types]" value="' . esc_attr($val) . '" class="regular-text" />';
            echo '<p class="description">'.esc_html__('Ex.: post (separe por vírgulas)', 'seo-llmo-writer').'</p>';
        }, 'aiw_settings', 'aiw_toc');
        add_settings_field('toc_blocked_types', __('Tipos bloqueados', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $arr = $opt['toc_blocked_types'] ?? ['fast_web_story','web-story','web_stories'];
            $val = is_array($arr) ? implode(',', $arr) : $arr;
            echo '<input type="text" name="aiw_settings[toc_blocked_types]" value="' . esc_attr($val) . '" class="regular-text" />';
            echo '<p class="description">'.esc_html__('Ex.: fast_web_story, web-story (separe por vírgulas)', 'seo-llmo-writer').'</p>';
        }, 'aiw_settings', 'aiw_toc');
        // Provedores
        add_settings_section('aiw_providers', __('Provedores de IA', 'seo-llmo-writer'), function(){
            echo '<p>'.esc_html__('Selecione o provedor e o modelo. Clique em "Atualizar modelos" para buscar na API.', 'seo-llmo-writer').'</p>';
        }, 'aiw_settings');
        add_settings_field('provider_active', __('Provedor ativo', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = $opt['provider_active'] ?? 'openai';
            echo '<select id="aiw-provider" name="aiw_settings[provider_active]">';
            foreach (['openai'=>'OpenAI','gemini'=>'Google Gemini','anthropic'=>'Anthropic','none'=>'Nenhum'] as $k=>$label){
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
            }
            echo '</select> ';
            echo '<button class="button" type="button" id="aiw-fetch-models">Atualizar modelos</button>';
            echo '<span id="aiw-fetch-status" style="margin-left:8px;"></span>';
        }, 'aiw_settings', 'aiw_providers');
        add_settings_field('model_select', __('Modelo', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $prov = $opt['provider_active'] ?? 'openai';
            $models = $opt['models'] ?? [];
            $mode = $opt['model_mode'] ?? 'preset';
            $current = $models[$prov] ?? '';
            $custom = $models['custom'] ?? '';
            echo '<div id="aiw-model-wrap">';
            echo '<select id="aiw-model" name="aiw_settings[models]['.esc_attr($prov).']">';
            if (!empty($current)){
                printf('<option value="%s" selected>%s</option>', esc_attr($current), esc_html($current));
            } else {
                echo '<option value="">— selecione —</option>';
            }
            echo '</select> ';
            echo '<label style="margin-left:10px;"><input type="radio" name="aiw_settings[model_mode]" value="preset" '.checked($mode,'preset',false).' /> Preset</label>';
            echo '<label style="margin-left:10px;"><input type="radio" name="aiw_settings[model_mode]" value="custom" '.checked($mode,'custom',false).' /> Custom</label>';
            echo '<input type="text" id="aiw-model-custom" name="aiw_settings[models][custom]" value="'.esc_attr($custom).'" class="regular-text" placeholder="Digite o ID do modelo" style="display:'.($mode==='custom'?'inline-block':'none').'; margin-left:10px;" />';
            echo '<p class="description">OpenAI/Gemini: busca da API. Anthropic: lista padrão. Em "Custom", digite o ID que quiser.</p>';
            echo '</div>';
        }, 'aiw_settings', 'aiw_providers');
        add_settings_field('openai_api_key', __('OpenAI API Key', 'seo-llmo-writer'), function(){
    $opt = get_option('aiw_settings', []);
    $val = $opt['providers']['openai']['api_key'] ?? '';
    echo '<input type="password" name="aiw_settings[providers][openai][api_key]" value="' . esc_attr($val) . '" class="regular-text" />';
    echo '<p class="description">Obtenha em <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a>.</p>';
}, 'aiw_settings', 'aiw_providers');

add_settings_field('gemini_api_key', __('Gemini API Key', 'seo-llmo-writer'), function(){
    $opt = get_option('aiw_settings', []);
    $val = $opt['providers']['gemini']['api_key'] ?? '';
    echo '<input type="password" name="aiw_settings[providers][gemini][api_key]" value="' . esc_attr($val) . '" class="regular-text" />';
    echo '<p class="description">Obtenha em <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.</p>';
}, 'aiw_settings', 'aiw_providers');

add_settings_field('anthropic_api_key', __('Anthropic API Key', 'seo-llmo-writer'), function(){
    $opt = get_option('aiw_settings', []);
    $val = $opt['providers']['anthropic']['api_key'] ?? '';
    echo '<input type="password" name="aiw_settings[providers][anthropic][api_key]" value="' . esc_attr($val) . '" class="regular-text" />';
    echo '<p class="description">Obtenha em <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic Console</a>.</p>';
}, 'aiw_settings', 'aiw_providers');
        // Defaults de geração
        add_settings_section('aiw_defaults', __('Padrões de Geração', 'seo-llmo-writer'), function(){
            echo '<p>'.esc_html__('Defina padrões para mínimo de palavras e tom.', 'seo-llmo-writer').'</p>';
        }, 'aiw_settings');
        add_settings_field('min_words', __('Mínimo de palavras', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = intval($opt['min_words'] ?? 1500);
            echo '<input type="number" min="300" step="100" name="aiw_settings[min_words]" value="'.esc_attr($val).'" />';
        }, 'aiw_settings', 'aiw_defaults');
        add_settings_field('tone', __('Tom do texto', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = $opt['tone'] ?? 'didático';
            echo '<input type="text" name="aiw_settings[tone]" value="'.esc_attr($val).'" class="regular-text" />';
        }, 'aiw_settings', 'aiw_defaults');
        add_settings_field('default_image_id', __('ID da imagem padrão (Biblioteca de Mídia)', 'seo-llmo-writer'), function(){
            $opt = get_option('aiw_settings', []);
            $val = isset($opt['default_image_id']) ? intval($opt['default_image_id']) : 1200;
            echo '<input type="number" min="1" name="aiw_settings[default_image_id]" value="'.esc_attr($val).'" class="small-text" />';
            echo '<p class="description">'.esc_html__('Usada como placeholder e imagem destacada; ideal 1200x675.', 'seo-llmo-writer').'</p>';
        }, 'aiw_settings', 'aiw_defaults');

    }
    public function sanitize_settings($input){
        $out = get_option('aiw_settings', []);
        $out['ui_lang'] = sanitize_text_field($input['ui_lang'] ?? get_locale());
        $out['region'] = sanitize_text_field($input['region'] ?? 'BR');
        $out['toc_enabled'] = !empty($input['toc_enabled']) ? 1 : 0;
        $allowed = $input['toc_allowed_types'] ?? 'post';
        $blocked = $input['toc_blocked_types'] ?? 'fast_web_story,web-story,web_stories';
        $out['toc_allowed_types'] = array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $allowed)))));
        $out['toc_blocked_types'] = array_values(array_filter(array_map('sanitize_key', array_map('trim', explode(',', $blocked)))));
        $out['provider_active'] = sanitize_text_field($input['provider_active'] ?? 'openai');
        $out['model_mode'] = in_array(($input['model_mode'] ?? 'preset'), ['preset','custom'], true) ? $input['model_mode'] : 'preset';
        $existingModels = $out['models'] ?? ['openai'=>'gpt-4o-mini','gemini'=>'gemini-1.5-flash','anthropic'=>'claude-3-5-sonnet','custom'=>''];
        if ($out['model_mode'] === 'custom'){
            $existingModels['custom'] = sanitize_text_field($input['models']['custom'] ?? '');
        } else {
            $prov = $out['provider_active'];
            if (isset($input['models'][$prov])){
                $existingModels[$prov] = sanitize_text_field($input['models'][$prov]);
            }
        }
        $out['models'] = $existingModels;
        $out['providers'] = [
            'openai' => ['api_key' => isset($input['providers']['openai']['api_key']) ? sanitize_text_field($input['providers']['openai']['api_key']) : ''],
            'gemini' => ['api_key' => isset($input['providers']['gemini']['api_key']) ? sanitize_text_field($input['providers']['gemini']['api_key']) : ''],
            'anthropic' => ['api_key' => isset($input['providers']['anthropic']['api_key']) ? sanitize_text_field($input['providers']['anthropic']['api_key']) : ''],
        ];
        $out['min_words'] = max(300, intval($input['min_words'] ?? 1500));
        $out['tone'] = sanitize_text_field($input['tone'] ?? 'didático');
        $out['default_image_id'] = isset($input['default_image_id']) ? intval($input['default_image_id']) : ($out['default_image_id'] ?? 1200);
        return $out;
    }
    public function render_settings_page(){
        ?>
        <div class="wrap aiw-wrap">
            <h1>SEO+LLMO Writer — Configurações</h1>
            <form method="post" action="options.php" id="aiw-settings-form">
                <?php
                    settings_fields('aiw_settings_group');
                    do_settings_sections('aiw_settings');
                    submit_button();
                ?>
            </form>
            <p class="description">Versão <?php echo esc_html(AIW_PLUGIN_VERSION); ?> — com página “Gerar Artigo”.</p>
        </div>
        <?php
    }
}
