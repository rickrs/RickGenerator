<?php
namespace AIW;

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_aiw_fetch_models', __NAMESPACE__ . '\\aiw_ajax_fetch_models');

function aiw_ajax_fetch_models(){
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Permissão negada'], 403);
    }
    check_ajax_referer('aiw_models_nonce', 'nonce');

    $opt = get_option('aiw_settings', []);
    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : ($opt['provider_active'] ?? 'openai');
    $models = [];

    if ($provider === 'openai'){
        $key = $opt['providers']['openai']['api_key'] ?? '';
        if (!$key){
            wp_send_json_error(['message'=>'Defina a OpenAI API Key nas configurações.'], 400);
        }
        $resp = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 20
        ]);
        if (is_wp_error($resp)){
            wp_send_json_error(['message'=>$resp->get_error_message()], 500);
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200){
            $err = isset($body['error']['message']) ? $body['error']['message'] : ('HTTP ' . $code);
            wp_send_json_error(['message'=>'OpenAI: ' . $err], $code);
        }
        if (!empty($body['data']) && is_array($body['data'])){
            foreach($body['data'] as $item){
                if (!empty($item['id'])) $models[] = $item['id'];
            }
        }
        $models = array_values(array_filter($models, function($id){
            return (bool) preg_match('/^(gpt|o|omni|text|chat)/i', $id);
        }));
    }
    elseif ($provider === 'gemini'){
        $key = $opt['providers']['gemini']['api_key'] ?? '';
        if (!$key){
            wp_send_json_error(['message'=>'Defina a Gemini API Key nas configurações.'], 400);
        }
        $resp = wp_remote_get('https://generativelanguage.googleapis.com/v1/models?key=' . rawurlencode($key), ['timeout'=>20]);
        if (is_wp_error($resp)){
            wp_send_json_error(['message'=>$resp->get_error_message()], 500);
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200){
            $err = isset($body['error']['message']) ? $body['error']['message'] : ('HTTP ' . $code);
            wp_send_json_error(['message'=>'Gemini: ' . $err], $code);
        }
        if (!empty($body['models']) && is_array($body['models'])){
            foreach($body['models'] as $item){
                if (!empty($item['name'])){
                    $parts = explode('/', $item['name']);
                    $models[] = end($parts);
                }
            }
        }
        $models = array_values(array_filter($models, function($id){
            return (bool) preg_match('/^gemini\-/i', $id);
        }));
    }
    elseif ($provider === 'anthropic'){
        $models = ['claude-3-5-sonnet','claude-3-opus','claude-3-haiku'];
    } else {
        wp_send_json_error(['message'=>'Provedor não suportado.'], 400);
    }

    sort($models, SORT_NATURAL | SORT_FLAG_CASE);
    $models = array_values(array_unique($models));

    wp_send_json_success(['provider'=>$provider, 'models'=>$models]);
}
