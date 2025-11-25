<?php
/**
 * Plugin Name: Zen SEO Lite (Artist Edition)
 * Description: Otimização SEO definitiva para Músicos e DJs. Integração profunda com Knowledge Graph (MusicBrainz, Wikidata, ISNI), Schema.org Musical e Sitemaps.
 * Version: 2.1.0
 * Author: Zen Eyer
 * Text Domain: zen-seo
 */

if (!defined('ABSPATH')) exit;

class Zen_SEO_Lite {

    public function __construct() {
        // 1. Admin (Meta Boxes & Configurações)
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_data']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);

        // 2. API REST (Frontend Data)
        add_action('rest_api_init', [$this, 'register_api_fields']);

        // 3. Sitemap XML
        add_action('init', [$this, 'register_sitemap_rewrite']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'render_sitemap']);
        add_action('save_post', [$this, 'clear_sitemap_cache']);
    }

    // =========================================================================
    // 1. PAINEL DE IDENTIDADE DO ARTISTA (O "HUB" DE CREDIBILIDADE)
    // =========================================================================

    public function add_admin_menu() {
        add_menu_page('Zen SEO', 'Zen SEO', 'manage_options', 'zen-seo-settings', [$this, 'render_settings_page'], 'dashicons-album', 99);
    }

    public function register_settings() {
        register_setting('zen_seo_options', 'zen_seo_global');
        
        // Seção 1: Identidade & Autoridade (Vital para IAs)
        add_settings_section('zen_seo_authority', '🎵 Autoridade & Identidade (Google Knowledge Graph)', null, 'zen-seo-settings');
        
        $authority_fields = [
            'wikidata' => 'Wikidata URL (ex: Q136551855)',
            'musicbrainz' => 'MusicBrainz Artist URL',
            'isni' => 'ISNI Code (International Standard Name Identifier)',
            'google_knowledge' => 'Google Knowledge Panel ID (se já tiver)'
        ];

        foreach ($authority_fields as $id => $label) {
            add_settings_field($id, $label, [$this, 'render_field'], 'zen-seo-settings', 'zen_seo_authority', ['id' => $id, 'tip' => 'Fundamental para IAs saberem quem é você.']);
        }

        // Seção 2: Streaming & Plataformas (Credibilidade)
        add_settings_section('zen_seo_streaming', '🎧 Streaming & Social', null, 'zen-seo-settings');

        $streaming_fields = [
            'spotify' => 'Spotify Artist URL',
            'apple_music' => 'Apple Music URL',
            'soundcloud' => 'SoundCloud URL',
            'bandsintown' => 'Bandsintown URL',
            'youtube' => 'YouTube Channel URL',
            'instagram' => 'Instagram URL',
            'facebook' => 'Facebook URL',
            'tiktok' => 'TikTok URL',
            'default_image' => 'Imagem de Compartilhamento Padrão (URL)'
        ];

        foreach ($streaming_fields as $id => $label) {
            add_settings_field($id, $label, [$this, 'render_field'], 'zen-seo-settings', 'zen_seo_streaming', ['id' => $id]);
        }
    }

    public function render_field($args) {
        $options = get_option('zen_seo_global');
        $value = $options[$args['id']] ?? '';
        $placeholder = empty($value) ? 'Cole o link aqui...' : '';
        echo "<input type='text' name='zen_seo_global[{$args['id']}]' value='" . esc_attr($value) . "' class='regular-text' placeholder='{$placeholder}'>";
        if (empty($value) && isset($args['tip'])) {
            echo "<p class='description' style='color: #d63638;'>⚠️ " . $args['tip'] . " (Pendente)</p>";
        }
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>🚀 Zen SEO - Painel do Artista</h1>
            <p>Preencha esses campos para conectar seu site ao ecossistema musical global (Google, Spotify, MusicBrainz).</p>
            <form method="post" action="options.php">
                <?php
                settings_fields('zen_seo_options');
                do_settings_sections('zen-seo-settings');
                submit_button('Salvar Identidade');
                ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // 2. ADMIN INTERFACE (PER POST)
    // =========================================================================

    public function add_meta_boxes() {
        $screens = ['post', 'page', 'product', 'remixes', 'flyers', 'events'];
        foreach ($screens as $screen) {
            add_meta_box('zen_seo_box', __('✨ Zen SEO Settings', 'zen-seo'), [$this, 'render_meta_box'], $screen, 'normal', 'high');
        }
    }

    public function render_meta_box($post) {
        $meta = get_post_meta($post->ID, '_zen_seo_data', true) ?: [];
        wp_nonce_field('zen_seo_save', 'zen_seo_nonce');
        ?>
        <div class="zen-seo-wrapper" style="display: grid; gap: 15px;">
            <div>
                <label><strong>Título SEO</strong> (Máx 60 chars)</label>
                <input type="text" name="zen_seo[title]" value="<?php echo esc_attr($meta['title'] ?? ''); ?>" style="width:100%" placeholder="<?php echo esc_attr(get_the_title($post)); ?>">
            </div>
            <div>
                <label><strong>Meta Description</strong> (Máx 160 chars)</label>
                <textarea name="zen_seo[desc]" rows="2" style="width:100%" maxlength="160"><?php echo esc_textarea($meta['desc'] ?? ''); ?></textarea>
            </div>
            <div>
                <label><strong>Imagem OG</strong></label>
                <input type="url" name="zen_seo[image]" value="<?php echo esc_url($meta['image'] ?? ''); ?>" style="width:100%">
            </div>
            <div>
                <label><input type="checkbox" name="zen_seo[noindex]" value="1" <?php checked(isset($meta['noindex']) && $meta['noindex']); ?>> 🚫 NoIndex</label>
            </div>
        </div>
        <?php
    }

    public function save_meta_data($post_id) {
        if (!isset($_POST['zen_seo_nonce']) || !wp_verify_nonce($_POST['zen_seo_nonce'], 'zen_seo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['zen_seo'])) {
            $data = [
                'title'   => mb_substr(sanitize_text_field($_POST['zen_seo']['title']), 0, 60),
                'desc'    => mb_substr(sanitize_textarea_field($_POST['zen_seo']['desc']), 0, 160),
                'image'   => esc_url_raw($_POST['zen_seo']['image']),
                'noindex' => isset($_POST['zen_seo']['noindex']) ? 1 : 0,
            ];
            update_post_meta($post_id, '_zen_seo_data', $data);
        }
    }

    public function admin_notices() {
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success"><p>Identidade do Artista atualizada! O Google Knowledge Graph agradece.</p></div>';
        }
    }

    // =========================================================================
    // 3. API REST & SCHEMA (O CÉREBRO)
    // =========================================================================

    public function register_api_fields() {
        register_rest_field(get_post_types(['public' => true]), 'head_tags', [
            'get_callback' => [$this, 'get_seo_data_for_api'],
            'schema' => null,
        ]);
    }

    public function get_seo_data_for_api($object) {
        $post_id = $object['id'];
        $meta = get_post_meta($post_id, '_zen_seo_data', true) ?: [];
        $global = get_option('zen_seo_global') ?: [];
        
        $title = !empty($meta['title']) ? $meta['title'] : get_the_title($post_id) . ' | Zen Eyer';
        $desc = !empty($meta['desc']) ? $meta['desc'] : wp_trim_words(get_post_field('post_content', $post_id), 20);
        
        $image = $meta['image'] ?? '';
        if (empty($image)) $image = get_the_post_thumbnail_url($post_id, 'large');
        if (empty($image)) $image = $global['default_image'] ?? '';

        $canonical = get_permalink($post_id);
        $robots = !empty($meta['noindex']) ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';

        $schema = $this->generate_schema($post_id, $object['type'], $title, $desc, $image, $global);

        return [
            'title'  => $title,
            'meta'   => [
                ['name' => 'description', 'content' => $desc],
                ['name' => 'robots', 'content' => $robots],
                ['property' => 'og:title', 'content' => $title],
                ['property' => 'og:description', 'content' => $desc],
                ['property' => 'og:image', 'content' => $image],
                ['property' => 'og:type', 'content' => 'website'],
                ['property' => 'og:url', 'content' => $canonical],
                ['name' => 'twitter:card', 'content' => 'summary_large_image'],
            ],
            'schema' => $schema,
        ];
    }

    private function generate_schema($post_id, $type, $title, $desc, $image, $global) {
        // Lista de autoridade (SameAs)
        $same_as = [];
        $keys = ['wikidata', 'musicbrainz', 'isni', 'spotify', 'apple_music', 'soundcloud', 'bandsintown', 'youtube', 'instagram', 'facebook', 'tiktok'];
        foreach ($keys as $key) {
            if (!empty($global[$key])) $same_as[] = $global[$key];
        }

        $base_schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [],
        ];

        // 1. Person (A Entidade DJ Zen Eyer) - Aparece em TUDO
        $base_schema['@graph'][] = [
            '@type' => 'Person',
            '@id' => home_url('/#artist'),
            'name' => 'DJ Zen Eyer',
            'url' => home_url(),
            'jobTitle' => 'Brazilian Zouk DJ & Producer',
            'nationality' => 'Brazilian',
            'sameAs' => $same_as,
            'image' => $global['default_image'] ?? '',
            'memberOf' => [
                '@type' => 'Organization',
                'name' => 'Mensa International'
            ]
        ];

        // 2. WebPage
        $base_schema['@graph'][] = [
            '@type' => 'WebPage',
            '@id' => get_permalink($post_id) . '#webpage',
            'url' => get_permalink($post_id),
            'name' => $title,
            'description' => $desc,
            'isPartOf' => ['@id' => home_url('/#website')],
            'about' => ['@id' => home_url('/#artist')]
        ];

        // 3. Schemas Específicos
        if ($type === 'remixes') {
            $base_schema['@graph'][] = [
                '@type' => 'MusicRecording',
                'name' => $title,
                'byArtist' => ['@id' => home_url('/#artist')],
                'url' => get_permalink($post_id)
            ];
        } elseif ($type === 'events') {
            $base_schema['@graph'][] = [
                '@type' => 'Event',
                'name' => $title,
                'description' => $desc,
                'image' => $image,
                'performer' => ['@id' => home_url('/#artist')],
            ];
        }

        return $base_schema;
    }

    // =========================================================================
    // 4. SITEMAP XML (CACHE SEMANAL)
    // =========================================================================

    public function register_sitemap_rewrite() { add_rewrite_rule('sitemap\.xml$', 'index.php?zen_sitemap=1', 'top'); }
    public function register_query_vars($vars) { $vars[] = 'zen_sitemap'; return $vars; }
    public function clear_sitemap_cache() { delete_transient('zen_seo_sitemap'); }

    public function render_sitemap() {
        if (get_query_var('zen_sitemap')) {
            $sitemap = get_transient('zen_seo_sitemap');
            if (false === $sitemap) {
                ob_start();
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                $post_types = ['post', 'page', 'product', 'remixes', 'flyers', 'events'];
                foreach ($post_types as $pt) {
                    $posts = get_posts(['post_type' => $pt, 'posts_per_page' => -1, 'post_status' => 'publish']);
                    foreach ($posts as $post) {
                        $meta = get_post_meta($post->ID, '_zen_seo_data', true);
                        if (!empty($meta['noindex'])) continue;
                        $priority = (current_time('timestamp') - get_the_modified_time('U', $post->ID) < MONTH_IN_SECONDS) ? '0.9' : '0.7';
                        if ($post->ID === get_option('page_on_front')) $priority = '1.0';
                        echo '<url><loc>' . esc_url(get_permalink($post->ID)) . '</loc><lastmod>' . get_the_modified_date('Y-m-d', $post->ID) . '</lastmod><changefreq>weekly</changefreq><priority>' . $priority . '</priority></url>';
                    }
                }
                echo '</urlset>';
                $sitemap = ob_get_clean();
                set_transient('zen_seo_sitemap', $sitemap, WEEK_IN_SECONDS);
            }
            if (!headers_sent()) {
                header('Content-Type: application/xml; charset=utf-8');
                header('X-Robots-Tag: noindex, follow');
            }
            echo $sitemap;
            exit;
        }
    }
}

new Zen_SEO_Lite();
