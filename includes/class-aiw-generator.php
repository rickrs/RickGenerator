<?php
namespace AIW;

if ( ! defined( 'ABSPATH' ) ) exit;

class Generator {
    private static $instance = null;
    public static function instance(){
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    private function __construct(){
        add_action('admin_post_aiw_generate_post', [$this, 'handle_generate']);
        add_action('admin_post_aiw_approve_draft', [$this, 'handle_approve']);
        add_action('admin_post_aiw_discard_session', [$this, 'handle_discard']);
        add_action('admin_post_aiw_update_session', [$this, 'handle_update_session']);
    }

    /* ========= PAGES ========= */

    public static function render_generate_page(){
        if (!current_user_can('edit_posts')){ wp_die(__('Sem permissão', 'seo-llmo-writer')); }
        $opt = get_option('aiw_settings', []);
        $prov = $opt['provider_active'] ?? 'openai';
        $mode = $opt['model_mode'] ?? 'preset';
        $model = ($mode==='custom') ? ($opt['models']['custom'] ?? '') : ($opt['models'][$prov] ?? '');
        $min_words = intval($opt['min_words'] ?? 1500);
        if ($min_words < 1500) $min_words = 1500;
        $tone = $opt['tone'] ?? 'didático';
        $err = isset($_GET['aiw_error']) ? sanitize_text_field(wp_unslash($_GET['aiw_error'])) : '';
        ?>
        <div class="wrap aiw-wrap">
          <h1>Gerar Artigo com IA</h1>
          <?php if ($err): ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('aiw_generate_nonce', 'aiw_nonce'); ?>
            <input type="hidden" name="action" value="aiw_generate_post" />
            <table class="form-table">
              <tr>
                <th><label for="aiw_keyword">Palavra-chave principal</label></th>
                <td><input required type="text" id="aiw_keyword" name="keyword" class="regular-text" placeholder="ex.: consórcio investimento" /></td>
              </tr>
              <tr>
                <th><label for="aiw_tone">Tom</label></th>
                <td><input type="text" id="aiw_tone" name="tone" class="regular-text" value="<?php echo esc_attr($tone); ?>" /></td>
              </tr>
              <tr>
                <th><label for="aiw_min_words">Mínimo de palavras</label></th>
                <td><input type="number" id="aiw_min_words" name="min_words" value="<?php echo esc_attr($min_words); ?>" min="1500" step="100" /></td>
              </tr>
              <tr>
                <th><label for="aiw_outline">Gerar outline antes?</label></th>
                <td><label><input type="checkbox" id="aiw_outline" name="outline" value="1" checked /> Sim, gerar e usar outline otimizado</label></td>
              </tr>
              <tr>
                <th><label for="aiw_notes">Observações / tópicos</label></th>
                <td><textarea id="aiw_notes" name="notes" class="large-text" rows="5" placeholder="Tópicos a cobrir, links internos obrigatórios, perguntas do PAA, etc."></textarea></td>
              </tr>
            </table>
            <p class="submit">
              <button class="button button-primary" type="submit">Gerar para revisão</button>
              <span class="description" style="margin-left:8px;">Provedor: <strong><?php echo esc_html(strtoupper($prov)); ?></strong> | Modelo: <code><?php echo esc_html($model ?: '—'); ?></code></span>
            </p>
          </form>
        </div>
        <?php
    }

    public static function render_checklist_page(){
        if (!current_user_can('edit_posts')) wp_die(__('Sem permissão','seo-llmo-writer'));
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        $session = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
        $data = false; $is_session=false;
        $content = ''; $title=''; $slug=''; $kw=''; $suggested_kw='';

        if ($session){
            $data = self::instance()->get_review_session($session);
            if ($data){
                $is_session = true;
                $content = $data['content'] ?? '';
                $title = self::instance()->suggest_title_from_content($content, '');
                $auto_kw = self::extract_focus_keyword($title, $content);
                $kw = !empty($data['keyword']) ? $data['keyword'] : $auto_kw;
                $suggested_kw = $auto_kw;
                $slug = sanitize_title($kw);
            }
        } elseif ($post_id){
            $post = get_post($post_id);
            if (!$post){ echo '<div class="wrap"><h1>Checklist</h1><p>Post não encontrado.</p></div>'; return; }
            $content = $post->post_content; $title = $post->post_title; $slug = $post->post_name;
            $kw = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (empty($kw)) { $kw = self::extract_focus_keyword($title, $content); }
        } else {
            echo '<div class="wrap"><h1>Checklist</h1><p>Selecione uma sessão de revisão ou um post.</p></div>'; return;
        }

        // Settings
        $opt = get_option('aiw_settings', []);
        $dens_min = isset($opt['density_min']) ? floatval($opt['density_min']) : 1.2;
        $dens_max = isset($opt['density_max']) ? floatval($opt['density_max']) : 1.8;

        // Metrics
        $word_count = str_word_count( wp_strip_all_tags($content) );
        $has_toc = (stripos($content,'[rank_math_toc]')!==false);
        $first_para = '';
        if (preg_match('/<p>(.*?)<\/p>/i', $content, $m)) $first_para = wp_strip_all_tags($m[1]);

        $images = [];
        if (preg_match_all('/<img[^>]+>/i', $content, $m)){ $images = $m[0]; }
        $alts_with_kw = 0;
        foreach($images as $img){
            if (preg_match('/alt\s*=\s*"([^"]*)"/i',$img,$mm)){
                if ($kw && stripos($mm[1], $kw)!==false) $alts_with_kw++;
            }
        }

        preg_match_all('/<a\s[^>]*href\s*=\s*"([^"]+)"[^>]*>/i', $content, $lm);
        $links = $lm[1] ?? [];
        $internal = 0; $external = 0;
        foreach($links as $href){
            if (strpos($href, home_url())===0 || strpos($href,'/')===0) $internal++; else $external++;
        }

        preg_match_all('/<(h2|h3)[^>]*>(.*?)<\/\1>/i',$content,$hm);
        $headings_kw = 0;
        foreach($hm[2] ?? [] as $h){
            if ($kw && stripos(wp_strip_all_tags($h), $kw)!==false) $headings_kw++;
        }

        $text = wp_strip_all_tags($content);
        $kw_count = 0;
        if ($kw){ $kw_count = preg_match_all('/\b'.preg_quote(mb_strtolower($kw,'UTF-8'),'/').'\b/u', mb_strtolower($text,'UTF-8'), $mm); }
        $density = $word_count>0 ? ($kw_count / max(1,$word_count)) * 100 : 0;

        $checks = [
            ['Keyword válida (<=5 palavras e diferente do título)', ( (str_word_count($kw) <= 5) && (mb_strtolower(trim($kw),'UTF-8') !== mb_strtolower(trim($title),'UTF-8')) )],
            ['Título contém keyword no início', ($kw && stripos($title, $kw)===0)],
            ['Meta focus keyword definida (Rank Math)', !!$kw],
            ['Keyword no primeiro parágrafo', ($kw && stripos($first_para, $kw)!==false)],
            ['[rank_math_toc] presente após intro', $has_toc],
            ['Palavras (>=1500)', $word_count >= 1500],
            ['Densidade exata ('.number_format($dens_min,1).'–'.number_format($dens_max,1).'%) — atual '.number_format($density,2).'%', ($density>=$dens_min and $density<=$dens_max)],
            ['Subtítulos com keyword (>=1)', $headings_kw>=1],
            ['Link interno (>=1)', $internal>=1],
            ['Link externo (>=1)', $external>=1],
            ['Imagem com alt contendo keyword (>=1)', $alts_with_kw>=1],
            ['Slug curto e com keyword', (strlen($slug)<=60) && ($kw && stripos($slug, sanitize_title($kw))!==false)]
        ];
        $pass_all = true; foreach($checks as $c){ if(!$c[1]){ $pass_all=false; break; } }

        echo '<div class="wrap aiw-wrap"><h1>Checklist — '.($is_session?'Sessão de Revisão':'Post').'</h1>';
        $kw_html = '<strong>Keyword:</strong> <code>'.esc_html($kw).'</code>';
        if ($is_session && !empty($suggested_kw) && (mb_strtolower(trim($suggested_kw),'UTF-8') !== mb_strtolower(trim($kw),'UTF-8'))){
            $kw_html .= ' <span style="margin-left:8px;">(sugerida: <code>'.esc_html($suggested_kw).'</code>)</span>';
        }
        echo '<p><strong>Título:</strong> '.esc_html($title).'<br/><strong>Slug:</strong> <code>'.esc_html($slug).'</code><br/>'.$kw_html.'<br/><strong>Palavras:</strong> '.intval($word_count).' de mínimo 1500 (<em>densidade aprox.: '.number_format($density,2).' %</em>)</p>';
        echo '<p><em>Meta aproximada de ocorrências exatas: '.intval( ceil(($word_count * (($dens_min+$dens_max)/2)) / 100) ).'</em></p>';
        echo '<table class="widefat fixed striped"><thead><tr><th>Item</th><th>Status</th></tr></thead><tbody>';
        foreach($checks as $c){
            echo '<tr><td>'.esc_html($c[0]).'</td><td>'.($c[1] ? '<span style="color:green;">✔ OK</span>' : '<span style="color:#c00;">✘ Ajustar</span>').'</td></tr>';
        }
        echo '</tbody></table>';

        if ($is_session){
            echo '<hr/><h2>Ajustar</h2>';
            if (!empty($suggested_kw) && (mb_strtolower(trim($suggested_kw),'UTF-8') !== mb_strtolower(trim($kw),'UTF-8'))){
                echo '<p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-top:8px;">';
                wp_nonce_field('aiw_update_session_nonce', 'aiw_nonce');
                echo '<input type="hidden" name="action" value="aiw_update_session" />';
                echo '<input type="hidden" name="session" value="'.esc_attr($session).'" />';
                echo '<input type="hidden" name="keyword" value="'.esc_attr($suggested_kw).'" />';
                echo '<button class="button">Usar keyword sugerida</button>';
                echo '</form></p>';
            }
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="aiw-adjust-form">';
            wp_nonce_field('aiw_update_session_nonce', 'aiw_nonce');
            echo '<input type="hidden" name="action" value="aiw_update_session" />';
            echo '<input type="hidden" name="session" value="'.esc_attr($session).'" />';
            echo '<table class="form-table">';
            echo '<tr><th>Título</th><td><input type="text" name="title" class="regular-text" value="'.esc_attr($title).'" /></td></tr>';
            echo '<tr><th>Slug</th><td><input type="text" name="slug" class="regular-text" value="'.esc_attr($slug).'" /></td></tr>';
            echo '<tr><th>Keyword</th><td><input type="text" name="keyword" class="regular-text" value="'.esc_attr($kw).'" /></td></tr>';
            echo '<tr><th>Adicionar H2 com keyword</th><td><label><input type="checkbox" name="add_h2_kw" value="1" /> Inserir H2 com a keyword</label></td></tr>';
            echo '<tr><th>Link interno</th><td><input type="text" name="internal_url" placeholder="/caminho-interno" class="regular-text" /> <input type="text" name="internal_text" placeholder="Âncora" class="regular-text" /></td></tr>';
            echo '<tr><th>Link externo</th><td><input type="url" name="external_url" placeholder="https://..." class="regular-text" /> <input type="text" name="external_text" placeholder="Âncora" class="regular-text" /></td></tr>';
            echo '<tr><th>Imagem</th><td><input type="url" name="image_url" placeholder="https://.../imagem.jpg" class="regular-text" /><p class="description">Se usar URL, sobrescreve a imagem padrão.</p></td></tr>';
            echo '<tr><th>Acrescentar parágrafo</th><td><textarea name="append_paragraph" class="large-text" rows="4" placeholder="Texto adicional para aumentar palavras e densidade."></textarea></td></tr>';
            echo '</table>';
            echo '<p class="submit">'
                .'<button class="button button-secondary" type="submit">Aplicar ajustes e recalcular</button> '
                .'<button class="button" name="boost_density" value="1" type="submit">Ajustar densidade automaticamente</button>'
                .'</p>';
            echo '</form>';

            // Approve / Force / Discard
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px; display:inline-block;">';
            wp_nonce_field('aiw_approve_nonce', 'aiw_nonce');
            echo '<input type="hidden" name="action" value="aiw_approve_draft" />';
            echo '<input type="hidden" name="session" value="'.esc_attr($session).'" />';
            echo '<button class="button button-primary" type="submit" '.($pass_all?'':'disabled').'>'
                .($pass_all?'Aprovar e salvar como Rascunho':'Aprovar (corrija os itens pendentes)')
                .'</button>';
            echo '</form> ';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px; display:inline-block; margin-left:8px;">';
            wp_nonce_field('aiw_approve_nonce', 'aiw_nonce');
            echo '<input type="hidden" name="action" value="aiw_approve_draft" />';
            echo '<input type="hidden" name="session" value="'.esc_attr($session).'" />';
            echo '<input type="hidden" name="force" value="1" />';
            echo '<button class="button" type="submit">Aprovar assim mesmo</button>';
            echo '</form> ';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px; display:inline-block; margin-left:8px;">';
            wp_nonce_field('aiw_discard_nonce', 'aiw_nonce');
            echo '<input type="hidden" name="action" value="aiw_discard_session" />';
            echo '<input type="hidden" name="session" value="'.esc_attr($session).'" />';
            echo '<button class="button" type="submit">Descartar</button>';
            echo '</form>';
        } else {
            $edit = admin_url('post.php?post='.$post_id.'&action=edit');
            echo '<p style="margin-top:12px;"><a class="button" href="'.$edit.'">Editar post</a></p>';
        }
        echo '</div>';
    }

    /* ========= HANDLERS ========= */

    public function handle_generate(){
        if (!current_user_can('edit_posts')) wp_die(__('Sem permissão','seo-llmo-writer'));
        if (!isset($_POST['aiw_nonce']) || !wp_verify_nonce($_POST['aiw_nonce'], 'aiw_generate_nonce')){
            wp_die(__('Nonce inválido','seo-llmo-writer'));
        }
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        if (!$keyword){ $this->redirect_back_with_error('Informe a palavra-chave.'); }
        $tone = sanitize_text_field($_POST['tone'] ?? 'didático');
        $min_words = max(1500, intval($_POST['min_words'] ?? 1500));
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $want_outline = !empty($_POST['outline']);

        $opt = get_option('aiw_settings', []);
        $prov = $opt['provider_active'] ?? 'openai';
        $mode = $opt['model_mode'] ?? 'preset';
        $model = ($mode==='custom') ? ($opt['models']['custom'] ?? '') : ($opt['models'][$prov] ?? '');
        if (!$prov || $prov==='none'){ $prov = 'openai'; }
        if (!$model){ $model = ($prov==='openai' ? 'gpt-4o-mini' : ($prov==='gemini' ? 'gemini-1.5-flash' : 'claude-3-5-sonnet')); }

        $outline = '';
        if ($want_outline){
            $outline_prompt = $this->prompt_outline($keyword, $tone);
            $outline = $this->call_provider($prov, $model, $outline_prompt);
            if (is_wp_error($outline)){ $this->redirect_back_with_error($outline->get_error_message()); }
        }
        $draft_prompt = $this->prompt_draft($keyword, $tone, $min_words, $outline, $notes);
        $content = $this->call_provider($prov, $model, $draft_prompt);
        if (is_wp_error($content)){ $this->redirect_back_with_error($content->get_error_message()); }

        $session = $this->create_review_session([
            'keyword' => $keyword,
            'tone' => $tone,
            'min_words' => $min_words,
            'content' => $content,
            'provider' => $prov,
            'model' => $model,
            'time' => time(),
            'user' => get_current_user_id(),
        ]);
        $url = admin_url('admin.php?page=aiw-checklist&session=' . rawurlencode($session));
        wp_safe_redirect($url); exit;
    }

    public function handle_update_session(){
        if (!current_user_can('edit_posts')) wp_die('Sem permissão');
        if (!isset($_POST['aiw_nonce']) || !wp_verify_nonce($_POST['aiw_nonce'], 'aiw_update_session_nonce')){
            wp_safe_redirect( admin_url('admin.php?page=aiw-generate&aiw_error=' . rawurlencode('Nonce inválido. Recarregue a página.')) ); exit;
        }
        $session = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        $data = $this->get_review_session($session);
        if (!$data){ wp_safe_redirect( admin_url('admin.php?page=aiw-generate&aiw_error=' . rawurlencode('Sessão inválida ou expirada.')) ); exit; }

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $slug  = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
        $kw    = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
        $content = isset($data['content']) ? (string)$data['content'] : '';

        if (!empty($_POST['add_h2_kw']) && $kw){
            $content .= "\n<h2>".esc_html($kw)."</h2>\n<p>".esc_html($kw)."</p>\n";
        }
        $int_url = isset($_POST['internal_url']) ? esc_url_raw($_POST['internal_url']) : '';
        $int_txt = isset($_POST['internal_text']) ? sanitize_text_field($_POST['internal_text']) : '';
        if ($int_url && $int_txt){
            $content .= "\n<p><a href=\"" . esc_url($int_url) . "\">" . esc_html($int_txt) . "</a></p>\n";
        }
        $ext_url = isset($_POST['external_url']) ? esc_url_raw($_POST['external_url']) : '';
        $ext_txt = isset($_POST['external_text']) ? sanitize_text_field($_POST['external_text']) : '';
        if ($ext_url && $ext_txt){
            $content .= "\n<p><a href=\"" . esc_url($ext_url) . "\" rel=\"nofollow noopener\" target=\"_blank\">" . esc_html($ext_txt) . "</a></p>\n";
        }
        $img_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
        if ($img_url && $kw){
            $content .= "\n<figure class=\"aiw-figure\"><img src=\"" . esc_url($img_url) . "\" alt=\"" . esc_attr($kw) . "\" width=\"1200\" height=\"675\" /><figcaption>".esc_html(ucfirst($kw))." || Editorial illustration about &quot;".esc_html($kw)."&quot;, 16:9 (1200x675), high detail, clean lighting, depth of field || ".esc_html('Ilustração sobre '.ucfirst($kw))."</figcaption></figure>\n";
        }
        $append = isset($_POST['append_paragraph']) ? wp_kses_post($_POST['append_paragraph']) : '';
        if ($append){
            $content .= "\n<p>{$append}</p>\n";
        }

        // Auto density boost (midpoint) if requested
        $boost = isset($_POST['boost_density']) && $_POST['boost_density']=='1';
        if ($boost && $kw){
            $opt = get_option('aiw_settings', []);
            $dens_min = isset($opt['density_min']) ? floatval($opt['density_min']) : 1.2;
            $dens_max = isset($opt['density_max']) ? floatval($opt['density_max']) : 1.8;
            $target_pct = max($dens_min, min( ($dens_min + $dens_max)/2, $dens_max ));
            $text = wp_strip_all_tags($content);
            $words = max(1, str_word_count($text));
            $current = preg_match_all('/\b'.preg_quote(mb_strtolower($kw,'UTF-8'),'/').'\b/u', mb_strtolower($text,'UTF-8'), $mm);
            $target = (int) ceil( ($words * $target_pct) / 100 );
            $need = max(0, $target - $current);

            $parts = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!$parts || count($parts)<2){ $parts = [$content]; }
            $slots = max(1, floor(count($parts)/max(1,$need)) );
            $inserted = 0; $pidx = 0; $new_parts = [];
            for ($i=0; $i<count($parts); $i++){
                $new_parts[] = $parts[$i];
                if (preg_match('/^<\/p>$/i', $parts[$i])){
                    if ($need>0 && ($pidx % $slots)==0){
                        $new_parts[] = '<p>'.esc_html($kw).'</p>';
                        $inserted++; $need--;
                    }
                    $pidx++;
                }
            }
            $content = implode('', $new_parts);
            while ($need>0){ $content .= '<p>'.esc_html($kw).'</p>'; $need--; }
        }

        if ($title) $data['title_override'] = $title;
        if ($slug)  $data['slug_override']  = $slug;
        if ($kw)    $data['keyword']        = $kw;
        $data['content'] = $content;
        set_transient($session, $data, 60*60);
        wp_safe_redirect( admin_url('admin.php?page=aiw-checklist&session=' . rawurlencode($session)) ); exit;
    }

    public function handle_approve(){
        if (!current_user_can('edit_posts')) wp_die('Sem permissão');
        if (!isset($_POST['aiw_nonce']) || !wp_verify_nonce($_POST['aiw_nonce'], 'aiw_approve_nonce')){
            wp_die('Nonce inválido');
        }
        $session = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        $force = !empty($_POST['force']);
        $data = $this->get_review_session($session);
        if (!$data){ wp_safe_redirect( admin_url('admin.php?page=aiw-generate&aiw_error=' . rawurlencode('Sessão inválida ou expirada.')) ); exit; }

        $keyword = sanitize_text_field($data['keyword'] ?? '');
        $content = (string)($data['content'] ?? '');
        $title = !empty($data['title_override']) ? $data['title_override'] : $this->suggest_title_from_content($content, $keyword);

        // Remove qualquer H1 do conteúdo (tema já exibe título)
        $content = preg_replace('/<h1[^>]*>.*?<\/h1>/is','',$content);

        if (empty($keyword)){
            $auto_kw = self::extract_focus_keyword($title, $content);
            $keyword = $auto_kw ? $auto_kw : $keyword;
        }

        if (!$force){
            $opt = get_option('aiw_settings', []);
            $dens_min = isset($opt['density_min']) ? floatval($opt['density_min']) : 1.2;
            $dens_max = isset($opt['density_max']) ? floatval($opt['density_max']) : 1.8;
            $target_pct = max($dens_min, min( ($dens_min + $dens_max)/2, $dens_max ));
            $text_tmp = wp_strip_all_tags($content);
            $words_tmp = max(1, str_word_count($text_tmp));
            $current_cnt = $keyword ? preg_match_all('/\b'.preg_quote(mb_strtolower($keyword,'UTF-8'),'/').'\b/u', mb_strtolower($text_tmp,'UTF-8'), $m1) : 0;
            $target_cnt = (int) ceil( ($words_tmp * $target_pct) / 100 );
            $need_cnt = max(0, $target_cnt - $current_cnt);

            if ($keyword && $need_cnt > 0){
                $parts = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
                if (!$parts || count($parts)<2){ $parts = [$content]; }
                $slots = max(1, floor(count($parts)/max(1,$need_cnt)) );
                $inserted = 0; $pidx = 0; $new_parts = [];
                for ($i=0; $i<count($parts); $i++){
                    $new_parts[] = $parts[$i];
                    if (preg_match('/^<\/p>$/i', $parts[$i])){
                        if ($need_cnt>0 && ($pidx % $slots)==0){
                            $new_parts[] = '<p>'.esc_html($keyword).'</p>';
                            $inserted++; $need_cnt--;
                        }
                        $pidx++;
                    }
                }
                $content = implode('', $new_parts);
                while ($need_cnt>0){ $content .= '<p>'.esc_html($keyword).'</p>'; $need_cnt--; }
            }
        }

        // Inserir imagens com legenda/prompt/alt e featured
        $opt_img = get_option('aiw_settings', []);
        $att_id = isset($opt_img['default_image_id']) ? intval($opt_img['default_image_id']) : 0;
        // Auto quantidade: ~1 a cada 350 palavras (mín 3, máx 8)
        $plain = wp_strip_all_tags($content);
        $wc = max(1, str_word_count($plain));
        $img_count = max(3, min(8, (int) ceil($wc / 350)));
        $content = self::insert_images_with_captions($content, $keyword, $img_count, $att_id);

        // Cria rascunho
        $postarr = [
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $content
        ];
        $post_id = wp_insert_post($postarr);
        if (!$post_id) wp_die('Falha ao criar rascunho.');

        // Slug pela keyword (ou override)
        $slug = !empty($data['slug_override']) ? sanitize_title($data['slug_override']) : sanitize_title($keyword);
        if ($slug){
            $unique = wp_unique_post_slug($slug, $post_id, 'draft', 'post', 0);
            wp_update_post(['ID'=>$post_id,'post_name'=>$unique]);
        }

        // Featured image
        if ($att_id){ set_post_thumbnail($post_id, $att_id); }

        // Rank Math focus keyword
        if (defined('RANK_MATH_VERSION')){
            update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
        }

        delete_transient($session);
        wp_safe_redirect( admin_url('post.php?post=' . intval($post_id) . '&action=edit&aiw_generated=1') ); exit;
    }

    public function handle_discard(){
        if (!current_user_can('edit_posts')) wp_die('Sem permissão');
        if (!isset($_POST['aiw_nonce']) || !wp_verify_nonce($_POST['aiw_nonce'], 'aiw_discard_nonce')){
            wp_die('Nonce inválido');
        }
        $session = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        if ($session) delete_transient($session);
        wp_safe_redirect( admin_url('admin.php?page=aiw-generate&aiw_error=' . rawurlencode('Sessão descartada.')) ); exit;
    }

    /* ========= HELPERS ========= */

    private function redirect_back_with_error($msg){
        wp_safe_redirect( admin_url('admin.php?page=aiw-generate&aiw_error=' . rawurlencode($msg)) );
        exit;
    }

    private function create_review_session($data){
        $user = get_current_user_id();
        $key = 'aiw_sess_' . md5(json_encode($data) . '|' . $user . '|' . microtime(true));
        set_transient($key, $data, 60*60); // 1h
        return $key;
    }

    private function get_review_session($key){
        if (!$key) return false;
        $data = get_transient($key);
        if (!$data || empty($data['user']) || (int)$data['user'] !== get_current_user_id()){
            return false;
        }
        return $data;
    }

    private function prompt_outline($keyword, $tone){
        return "Você é um editor SEO+LLMO. Crie um OUTLINE em português do Brasil para um artigo que rankeie no Google e seja facilmente resumível por LLMs.\n"
            ."Inclua 6 a 9 H2 com títulos claros. Para cada H2, escreva 1–2 frases de resposta direta.\n"
            ."Tema/palavra-chave: {$keyword}\n"
            ."Tom: {$tone}\n"
            ."Formato: liste H2 e, abaixo de cada, 1–2 frases curtas respondendo de forma direta. Nada de markdown, apenas texto com linhas.";
    }

    private function prompt_draft($keyword, $tone, $min_words, $outline, $notes){
        $extra = $notes ? "\\nNotas do editor: {$notes}" : '';
        $outline_txt = $outline ? "Use este outline como base:\\n{$outline}\\n" : "Crie um outline otimizado dentro do texto.\\n";
        return "Escreva um artigo completo em PT-BR, pronto para WordPress, otimizando para SEO (Rank Math) e LLMO.\\n"
            ."Regras obrigatórias:\\n"
            ."- Mínimo de {$min_words} palavras reais.\\n"
            ."- Título (H1) com a palavra-chave no início.\\n"
            ."- Introdução curta com gancho, seguida do shortcode literal [rank_math_toc] após o primeiro parágrafo.\\n"
            ."- Para cada H2: inicie com 1–2 frases que respondem diretamente ao tópico; depois, detalhes em parágrafos curtos e listas.\\n"
            ."- Conclusão com resumo + CTA.\\n"
            ."- Adicione uma seção FAQ com 5–7 perguntas e respostas objetivas.\\n"
            ."- Adicione um Glossário com 5–10 termos definidos claramente.\\n"
            ."- Proíba qualquer texto em caixa de código; entregue apenas HTML simples com <h2>, <h3>, <p>, <ul>, <li>.\\n"
            ."- Não use tabelas.\\n"
            ."- Mencione a palavra-chave no primeiro parágrafo e na conclusão.\\n"
            ."- Mantenha a densidade da palavra-chave entre 1.2% e 1.8% do total de palavras.\\n"
            ."Palavra-chave: {$keyword}\\n"
            ."Tom: {$tone}\\n"
            ."{$outline_txt}{$extra}";
    }

    private function suggest_title_from_content($content, $keyword){
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $m)){
            return wp_strip_all_tags($m[1]);
        }
        return ucfirst($keyword);
    }

    private static function extract_focus_keyword($title, $content){
        $stop = ['de','da','do','das','dos','para','por','o','a','os','as','um','uma','é','e','ou','em','no','na','nos','nas','com','sem','que','se','como','porque','por que','ser','sobre','mais','menos','melhor','pior','atualidade','guia','completo','2024','2025'];
        $text = mb_strtolower( wp_strip_all_tags($title.' '.($content ? (preg_match('/<p>(.*?)<\/p>/i',$content,$m)?$m[1]:'') : '')), 'UTF-8');
        $text = preg_replace('/[^a-z0-9áàâãéêíóôõúç\s-]/u',' ', $text);
        $words = array_values(array_filter(preg_split('/\s+/u',$text)));
        $words = array_values(array_filter($words, function($w) use ($stop){
            return (mb_strlen($w,'UTF-8')>2) && !in_array($w,$stop,true);
        }));
        $cands = [];
        for ($n=3; $n>=2; $n--){
            for ($i=0; $i<=count($words)-$n; $i++){
                $ng = implode(' ', array_slice($words, $i, $n));
                if (count(array_intersect(explode(' ', $ng), $stop))>0) continue;
                if (!isset($cands[$ng])) $cands[$ng]=0;
                $cands[$ng]++;
            }
            if (!empty($cands)) break;
        }
        arsort($cands);
        $key = key($cands);
        if (!$key && !empty($words)){
            $key = implode(' ', array_slice($words, 0, min(2,count($words))));
        }
        if ($key){
            $parts = preg_split('/\s+/u', trim($key));
            // drop consecutive duplicates & limit to 3 tokens
            $norm = [];
            foreach ($parts as $p){
                if ($p==='') continue;
                if (!empty($norm) && mb_strtolower(end($norm),'UTF-8')===mb_strtolower($p,'UTF-8')) continue;
                $norm[] = $p;
            }
            $key = implode(' ', array_slice($norm, 0, 3));
        }
        return trim($key);
    }

    /* ========= PROVIDERS ========= */
    private function call_provider($prov, $model, $prompt){
        if ($prov === 'openai'){
            $opt = get_option('aiw_settings', []);
            $key = $opt['providers']['openai']['api_key'] ?? '';
            if (!$key) return new \WP_Error('aiw_no_key', 'Configure a OpenAI API Key em Configurações.');
            $body = [
                'model' => $model,
                'messages' => [
                    ['role'=>'system','content'=>'Você é um redator sênior focado em SEO e LLMO. Entregue em PT-BR.'],
                    ['role'=>'user','content'=>$prompt]
                ]
            ];
            $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json'
                ],
                'body' => wp_json_encode($body),
                'timeout' => 90
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = wp_remote_retrieve_response_code($resp);
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if ($code !== 200){
                $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
                return new \WP_Error('aiw_openai', 'OpenAI: '.$msg);
            }
            $txt = $data['choices'][0]['message']['content'] ?? '';
            return $txt ? $txt : new \WP_Error('aiw_empty', 'Resposta vazia da OpenAI.');
        }
        if ($prov === 'gemini'){
            $opt = get_option('aiw_settings', []);
            $key = $opt['providers']['gemini']['api_key'] ?? '';
            if (!$key) return new \WP_Error('aiw_no_key', 'Configure a Gemini API Key em Configurações.');
            $endpoint = 'https://generativelanguage.googleapis.com/v1/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key);
            $body = ['contents'=>[['parts'=>[['text'=>$prompt]]]]];
            $resp = wp_remote_post($endpoint, [
                'headers' => ['Content-Type'=>'application/json'],
                'body' => wp_json_encode($body),
                'timeout' => 90
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = wp_remote_retrieve_response_code($resp);
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if ($code !== 200){
                $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
                return new \WP_Error('aiw_gemini', 'Gemini: '.$msg);
            }
            $txt = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return $txt ? $txt : new \WP_Error('aiw_empty', 'Resposta vazia do Gemini.');
        }
        if ($prov === 'anthropic'){
            $opt = get_option('aiw_settings', []);
            $key = $opt['providers']['anthropic']['api_key'] ?? '';
            if (!$key) return new \WP_Error('aiw_no_key', 'Configure a Anthropic API Key em Configurações.');
            $resp = wp_remote_post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json'
                ],
                'body' => wp_json_encode([
                    'model' => $model,
                    'max_tokens' => 4096,
                    'messages' => [['role'=>'user','content'=>$prompt]]
                ]),
                'timeout' => 90
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = wp_remote_retrieve_response_code($resp);
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if ($code !== 200){
                $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
                return new \WP_Error('aiw_anthropic', 'Anthropic: '.$msg);
            }
            $txt = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
            return $txt ? $txt : new \WP_Error('aiw_empty', 'Resposta vazia da Anthropic.');
        }
        return new \WP_Error('aiw_provider', 'Provedor não suportado.');
    }

    /* ========= IMAGES ========= */

    private static function insert_images_with_captions($content, $kw, $count, $attachment_id){
        $count = max(1, intval($count));
        $kw = trim((string)$kw);
        $kw_uc = $kw ? ucfirst($kw) : '';

        // resolve source (aiw_og size 1200x675 preferred)
        $src = '';
        if ($attachment_id){
            $img = wp_get_attachment_image_src($attachment_id, 'aiw_og');
            if ((!$img || empty($img[0])) && $attachment_id){
                $img = wp_get_attachment_image_src($attachment_id, 'full');
            }
            if ($img && !empty($img[0])){ $src = $img[0]; }
        }
        if (!$src){ $src = includes_url('images/media/default.png'); }

        // prompt variants (English)
        $scenes_en = array(
            'Hero header image, subtle depth',
            'Contextual scene showing people using the concept',
            'Close-up detail with texture',
            'Clean infographic vibe',
            'Minimal header with soft gradients'
        );

        // caption/alt variants (Portuguese)
        $cap_pt_variants = array(
            $kw_uc ? ('Comparativo: ' . $kw_uc) : 'Imagem ilustrativa',
            $kw_uc ? ('Visão geral de ' . $kw_uc) : 'Visão geral do tema',
            $kw_uc ? ($kw_uc . ' na prática') : 'Exemplo prático do tema',
            $kw_uc ? ('Entenda ' . $kw_uc) : 'Entenda o assunto',
            $kw_uc ? ('Guia visual de ' . $kw_uc) : 'Guia visual do assunto'
        );
        $alt_pt_variants = array(
            $kw_uc ? ('Ilustração sobre ' . $kw_uc) : 'Ilustração temática para blog',
            $kw_uc ? ('Conceito visual de ' . $kw_uc) : 'Conceito visual do tema',
            $kw_uc ? ('Exemplo prático: ' . $kw_uc) : 'Exemplo prático do tema',
            $kw_uc ? ('Cenário relacionado a ' . $kw_uc) : 'Cenário relacionado ao assunto',
            $kw_uc ? ('Resumo visual de ' . $kw_uc) : 'Resumo visual do assunto'
        );

        // distribute among paragraphs
        $parts = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts)<2){ $parts = array($content); }
        $slots = max(1, floor(count($parts)/($count+1)) );
        $inserted = 0; $pidx = 0; $new_parts = array();

        for ($i=0; $i<count($parts); $i++){
            $new_parts[] = $parts[$i];
            if (preg_match('/^<\/p>$/i',$parts[$i])){
                if ($inserted < $count && ($pidx % $slots)==0){
                    $scene_en = $scenes_en[$inserted % count($scenes_en)];
                    $prompt_en = $scene_en . ', Editorial illustration about "' . $kw . '", 16:9 (1200x675), high detail, clean lighting, depth of field';
                    $cap_pt = $cap_pt_variants[$inserted % count($cap_pt_variants)];
                    $alt_pt = $alt_pt_variants[$inserted % count($alt_pt_variants)];
                    $fig = '<figure class="aiw-figure"><img src="'.esc_url($src).'" alt="'.esc_attr($alt_pt).'" width="1200" height="675" />'
                         . '<figcaption>'.esc_html($cap_pt).' || '.esc_html($prompt_en).' || '.esc_html($alt_pt).'</figcaption></figure>';
                    $new_parts[] = $fig;
                    $inserted++;
                }
                $pidx++;
            }
        }
        while ($inserted < $count){
            $scene_en = $scenes_en[$inserted % count($scenes_en)];
            $prompt_en = $scene_en . ', Editorial illustration about "' . $kw . '", 16:9 (1200x675), high detail, clean lighting, depth of field';
            $cap_pt = $cap_pt_variants[$inserted % count($cap_pt_variants)];
            $alt_pt = $alt_pt_variants[$inserted % count($alt_pt_variants)];
            $new_parts[] = '<figure class="aiw-figure"><img src="'.esc_url($src).'" alt="'.esc_attr($alt_pt).'" width="1200" height="675" /><figcaption>'.esc_html($cap_pt).' || '.esc_html($prompt_en).' || '.esc_html($alt_pt).'</figcaption></figure>';
            $inserted++;
        }
        return implode('', $new_parts);
    }
}
