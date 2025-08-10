<?php
namespace AIW;
if ( ! defined( 'ABSPATH' ) ) exit;

class Diagnostics {
    public static function add_hooks(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aiw_run_selftest', [__CLASS__, 'run_selftest']);
        add_action('admin_notices', [__CLASS__, 'post_activation_notice']);
    }

    public static function post_activation_notice(){
        if (!current_user_can('manage_options')) return;
        $flag = get_option('aiw_post_activation_notice');
        if ($flag){
            delete_option('aiw_post_activation_notice');
            $url = admin_url('admin.php?page=aiw-diagnostics');
            echo '<div class="notice notice-info is-dismissible"><p><strong>SEO+LLMO Writer:</strong> plugin ativado. Recomendo executar o <a href="'.esc_url($url).'">Diagnóstico</a> para checar compatibilidade (PHP/WP/APIs).</p></div>';
        }
        if (isset($_GET['aiw_diag']) && $_GET['aiw_diag']==='ok'){
            echo '<div class="notice notice-success is-dismissible"><p><strong>SEO+LLMO Writer:</strong> Diagnóstico executado. Veja os resultados abaixo.</p></div>';
        }
        if (isset($_GET['aiw_diag_err'])){
            echo '<div class="notice notice-error is-dismissible"><p><strong>SEO+LLMO Writer:</strong> '.esc_html(wp_unslash($_GET['aiw_diag_err'])).'</p></div>';
        }
    }

    public static function menu(){
        add_submenu_page(
            'aiw-settings',
            __('Diagnóstico', 'seo-llmo-writer'),
            __('Diagnóstico', 'seo-llmo-writer'),
            'manage_options',
            'aiw-diagnostics',
            [__CLASS__, 'render']
        );
    }

    public static function render(){
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        $results = get_transient('aiw_last_selftest');
        ?>
        <div class="wrap aiw-wrap">
            <h1>Diagnóstico — SEO+LLMO Writer</h1>
            <p>Execute o self-test para checar requisitos, integrações e detectar a causa de erros fatais/ativação.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aiw_selftest_nonce', 'aiw_nonce'); ?>
                <input type="hidden" name="action" value="aiw_run_selftest" />
                <p><button class="button button-primary" type="submit">Executar diagnóstico</button></p>
            </form>
            <?php if ($results): ?>
                <h2>Resultados recentes</h2>
                <?php self::render_results_table($results); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_results_table($r){
        echo '<table class="widefat fixed striped"><thead><tr><th>Item</th><th>Status</th><th>Detalhe</th></tr></thead><tbody>';
        foreach ($r as $row){
            $ok = !empty($row['ok']);
            $name = esc_html($row['name'] ?? '');
            $msg = esc_html($row['msg'] ?? '');
            echo '<tr><td>'.$name.'</td><td>'.($ok?'<span style="color:green;font-weight:bold;">OK</span>':'<span style="color:#c00;font-weight:bold;">Falhou</span>').'</td><td>'.$msg.'</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function run_selftest(){
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        if (!isset($_POST['aiw_nonce']) || !wp_verify_nonce($_POST['aiw_nonce'], 'aiw_selftest_nonce')){
            wp_safe_redirect( admin_url('admin.php?page=aiw-diagnostics&aiw_diag_err=' . rawurlencode('Nonce inválido.')) ); exit;
        }
        $rows = [];

        // PHP version
        $rows[] = ['name'=>'PHP >= 7.4', 'ok' => version_compare(PHP_VERSION, '7.4', '>='), 'msg' => 'Versão atual: '.PHP_VERSION];

        // WP HTTP API
        $http_ok = function_exists('wp_remote_get') && function_exists('wp_remote_post');
        $rows[] = ['name'=>'WP HTTP API', 'ok' => $http_ok, 'msg' => $http_ok ? 'wp_remote_* disponível' : 'Faltando wp_remote_get/wp_remote_post'];

        // JSON
        $json_ok = function_exists('json_encode') && function_exists('json_decode');
        $rows[] = ['name'=>'Extensão JSON', 'ok'=>$json_ok, 'msg'=>$json_ok?'OK':'Extensão JSON não disponível'];

        // Rank Math
        $rm = defined('RANK_MATH_VERSION');
        $rows[] = ['name'=>'Rank Math detectado', 'ok'=>$rm, 'msg'=>$rm?('Versão: '.RANK_MATH_VERSION):'Plugin Rank Math não detectado'];

        // Provider/model/key checks
        $opt = get_option('aiw_settings', []);
        $prov = $opt['provider_active'] ?? 'openai';
        $mode = $opt['model_mode'] ?? 'preset';
        $model = ($mode==='custom') ? ($opt['models']['custom'] ?? '') : ($opt['models'][$prov] ?? '');
        $rows[] = ['name'=>'Provedor ativo', 'ok'=>!empty($prov) && $prov!=='none', 'msg'=>$prov];
        $rows[] = ['name'=>'Modelo selecionado', 'ok'=>!empty($model), 'msg'=>$model?:'—'];

        $keys = $opt['providers'] ?? [];
        $ok_openai = !empty($keys['openai']['api_key']);
        $ok_gemini = !empty($keys['gemini']['api_key']);
        $ok_anth = !empty($keys['anthropic']['api_key']);
        $rows[] = ['name'=>'OpenAI API key', 'ok'=>$ok_openai, 'msg'=>$ok_openai?'definida':'vazia'];
        $rows[] = ['name'=>'Gemini API key', 'ok'=>$ok_gemini, 'msg'=>$ok_gemini?'definida':'vazia'];
        $rows[] = ['name'=>'Anthropic API key', 'ok'=>$ok_anth, 'msg'=>$ok_anth?'definida':'vazia'];

        // Connectivity quick check
        $probe = wp_remote_head('https://www.google.com', ['timeout'=>5]);
        $rows[] = ['name'=>'Conectividade de saída (teste rápido)', 'ok'=>!is_wp_error($probe), 'msg'=> is_wp_error($probe)?$probe->get_error_message():'OK'];

        // Provider test
        if ($prov==='openai' && $ok_openai){
            $resp = wp_remote_get('https://api.openai.com/v1/models', ['headers'=>['Authorization'=>'Bearer '.$keys['openai']['api_key']], 'timeout'=>15]);
            $rows[] = ['name'=>'OpenAI: listar modelos', 'ok'=>!is_wp_error($resp) && wp_remote_retrieve_response_code($resp)===200, 'msg'=> is_wp_error($resp)?$resp->get_error_message():'HTTP '.wp_remote_retrieve_response_code($resp)];
        } elseif ($prov==='gemini' && $ok_gemini){
            $resp = wp_remote_get('https://generativelanguage.googleapis.com/v1/models?key='.rawurlencode($keys['gemini']['api_key']), ['timeout'=>15]);
            $rows[] = ['name'=>'Gemini: listar modelos', 'ok'=>!is_wp_error($resp) && wp_remote_retrieve_response_code($resp)===200, 'msg'=> is_wp_error($resp)?$resp->get_error_message():'HTTP '.wp_remote_retrieve_response_code($resp)];
        } elseif ($prov==='anthropic' && $ok_anth){
            $endpoint = 'https://api.anthropic.com/v1/messages';
            $headers = ['x-api-key'=>$keys['anthropic']['api_key'],'anthropic-version'=>'2023-06-01','content-type'=>'application/json'];
            $body = json_encode(['model'=>'claude-3-5-sonnet','max_tokens'=>1,'messages'=>[['role'=>'user','content'=>'ping']]]);
            $resp = wp_remote_post($endpoint, ['headers'=>$headers,'body'=>$body,'timeout'=>15]);
            $code = wp_remote_retrieve_response_code($resp);
            $okresp = !is_wp_error($resp) && in_array($code, [200,400,401,403], true);
            $rows[] = ['name'=>'Anthropic: reachability', 'ok'=>$okresp, 'msg'=> is_wp_error($resp)?$resp->get_error_message():'HTTP '.$code];
        }

        set_transient('aiw_last_selftest', $rows, 600);
        wp_safe_redirect( admin_url('admin.php?page=aiw-diagnostics&aiw_diag=ok') ); exit;
    }
}
