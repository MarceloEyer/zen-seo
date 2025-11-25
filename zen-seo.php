<?php
/**
 * Plugin Name: Zen SEO Lite (Artist Ultimate Edition)
 * Description: SEO definitivo para DJ Zen Eyer. Gerenciador de Rotas React, Knowledge Graph Completo, Schema Musical Avançado e Sitemap Híbrido.
 * Version: 3.1.0
 * Author: Zen Eyer
 * Text Domain: zen-seo
 */

if (!defined('ABSPATH')) exit;

class Zen_SEO_Lite {

    public function __construct() {
        // Admin
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_data']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);

        // API
        add_action('rest_api_init', [$this, 'register_api_fields']);

        // Sitemap & Robots
        add_action('init', [$this, 'register_sitemap_rewrite']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'render_sitemap']);
        add_filter('robots_txt', [$this, 'custom_robots_txt'], 10, 2);
        
        // Cache
        add_action('save_post', [$this, 'clear_sitemap_cache']);
        add_action('update_option_zen_seo_global', [$this, 'clear_sitemap_cache']);
    }

    // =========================================================================
    // 1. PAINEL DE CONTROLE (IDENTIDADE + ROTAS)
    // =========================================================================
    public function add_admin_menu() {
        add_menu_page('Zen SEO', 'Zen SEO', 'manage_options', 'zen-seo-settings', [$this, 'render_settings_page'], 'dashicons-google', 99);
    }

    public function register_settings() {
        register_setting('zen_seo_options', 'zen_seo_global');
        
        // 1. IDs & Dados Mestre
        add_settings_section('zen_ids', '🆔 Identificadores & IDs (A base do Knowledge Graph)', null, 'zen-seo-settings');
        $ids = [
            'wikidata' => 'Wikidata URL',
            'musicbrainz' => 'MusicBrainz Artist URL',
            'isni' => 'ISNI Code',
            'google_kg' => 'Google Knowledge Panel ID (kg_mid)',
            'discogs' => 'Discogs Artist URL'
        ];
        foreach ($ids as $id => $label) add_settings_field($id, $label, [$this, 'render_text_field'], 'zen-seo-settings', 'zen_ids', ['id' => $id]);

        // 2. Streaming & Lojas (DJ Focus)
        add_settings_section('zen_streaming', '🎧 Streaming & Lojas', null, 'zen-seo-settings');
        $streaming = [
            'spotify' => 'Spotify Artist URL',
            'apple_music' => 'Apple Music URL',
            'soundcloud' => 'SoundCloud URL',
            'beatport' => 'Beatport DJ URL',
            'traxsource' => 'Traxsource URL',
            'mixcloud' => 'Mixcloud URL',
            'deezer' => 'Deezer URL',
            'amazon_music' => 'Amazon Music URL'
        ];
        foreach ($streaming as $id => $label) add_settings_field($id, $label, [$this, 'render_text_field'], 'zen-seo-settings', 'zen_streaming', ['id' => $id]);

        // 3. Social & Eventos
        add_settings_section('zen_social', '📱 Social & Agenda', null, 'zen-seo-settings');
        $social = [
            'instagram' => 'Instagram URL',
            'facebook' => 'Facebook URL',
            'youtube' => 'YouTube Channel URL',
            'tiktok' => 'TikTok URL',
            'resident_advisor' => 'Resident Advisor URL',
            'bandsintown' => 'Bandsintown URL',
            'songkick' => 'Songkick URL'
        ];
        foreach ($social as $id => $label) add_settings_field($id, $label, [$this, 'render_text_field'], 'zen-seo-settings', 'zen_social', ['id' => $id]);

        // 4. Geral & Rotas
        add_settings_section('zen_general', '⚙️ Configurações Gerais', null, 'zen-seo-settings');
        add_settings_field('default_image', 'Imagem Padrão (URL)', [$this, 'render_text_field'], 'zen-seo-settings', 'zen_general', ['id' => 'default_image']);
        
        add_settings_field('react_routes', 'Mapeamento de Rotas React', [$this, 'render_textarea_field'], 'zen-seo-settings', 'zen_general', [
            'id' => 'react_routes',
            'desc' => 'Liste as URLs do seu site React para o Sitemap (uma por linha).'
        ]);
    }

    public function render_text_field($args) {
        $options = get_option('zen_seo_global');
        $value = $options[$args['id']] ?? '';
        echo "<input type='text' name='zen_seo_global[{$args['id']}]' value='" . esc_attr($value) . "' class='regular-text' style='width:100%' placeholder='https://...'>";
    }

    public function render_textarea_field($args) {
        $options = get_option('zen_seo_global');
        $value = $options[$args['id']] ?? "/\n/events\n/shop\n/music\n/zentribe\n/work-with-me\n/faq";
        echo "<textarea name='zen_seo_global[{$args['id']}]' rows='10' class='large-text code'>" . esc_textarea($value) . "</textarea>";
        echo "<p class='description'>{$args['desc']}</p>";
    }

    public function render_settings_page() {
        echo '<div class="wrap"><h1>🚀 Zen SEO - Painel de Identidade</h1><form method="post" action="options.php">';
        settings_fields('zen_seo_options');
        do_settings_sections('zen-seo-settings');
        submit_button('Salvar Identidade');
        echo '</form></div>';
    }

    // =========================================================================
    // 2. METADADOS POR POST
    // =========================================================================
    public function add_meta_boxes() {
        $screens = ['post', 'page', 'product', 'remixes', 'flyers', 'events'];
        foreach ($screens as $screen) add_meta_box('zen_seo_box', '✨ Zen SEO Settings', [$this, 'render_meta_box'], $screen, 'normal', 'high');
    }

    public function render_meta_box($post) {
        $meta = get_post_meta($post->ID, '_zen_seo_data', true) ?: [];
        wp_nonce_field('zen_seo_save', 'zen_seo_nonce');
        ?>
        <div style="display: grid; gap: 15px;">
            <label>Título SEO: <input type="text" name="zen_seo[title]" value="<?php echo esc_attr($meta['title'] ?? ''); ?>" style="width:100%"></label>
            <label>Descrição: <textarea name="zen_seo[desc]" style="width:100%"><?php echo esc_textarea($meta['desc'] ?? ''); ?></textarea></label>
            <label>Imagem OG: <input type="url" name="zen_seo[image]" value="<?php echo esc_url($meta['image'] ?? ''); ?>" style="width:100%"></label>
            <label><input type="checkbox" name="zen_seo[noindex]" value="1" <?php checked($meta['noindex'] ?? 0); ?>> NoIndex</label>
        </div>
        <?php
    }

    public function save_meta_data($post_id) {
        if (!isset($_POST['zen_seo_nonce']) || !wp_verify_nonce($_POST['zen_seo_nonce'], 'zen_seo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['zen_seo'])) {
            $data = array_map('sanitize_text_field', $_POST['zen_seo']);
            $data['noindex'] = isset($_POST['zen_seo']['noindex']) ? 1 : 0;
            update_post_meta($post_id, '_zen_seo_data', $data);
        }
    }

    public function admin_notices() {
        if (isset($_GET['settings-updated'])) echo '<div class="notice notice-success"><p>Configurações salvas!</p></div>';
    }

    // =========================================================================
    // 3. API REST & SCHEMA (GERAÇÃO AUTOMÁTICA DE SAMEAS)
    // =========================================================================
    public function register_api_fields() {
        register_rest_field(get_post_types(['public' => true]), 'head_tags', ['get_callback' => [$this, 'get_api_data']]);
    }

    public function get_api_data($object) {
        $post_id = $object['id'];
        $meta = get_post_meta($post_id, '_zen_seo_data', true) ?: [];
        $global = get_option('zen_seo_global') ?: [];
        
        $title = $meta['title'] ?: get_the_title($post_id) . ' | Zen Eyer';
        $desc = $meta['desc'] ?: wp_trim_words(get_post_field('post_content', $post_id), 20);
        $image = $meta['image'] ?: get_the_post_thumbnail_url($post_id, 'large') ?: $global['default_image'] ?: '';
        
        // Gera lista completa de SameAs (exclui campos vazios e chaves de configuração como react_routes/default_image)
        $same_as = [];
        $ignored_keys = ['react_routes', 'default_image'];
        foreach ($global as $key => $value) {
            if (!empty($value) && !in_array($key, $ignored_keys) && filter_var($value, FILTER_VALIDATE_URL)) {
                $same_as[] = $value;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Person', // Ou MusicGroup
                    '@id' => home_url('/#artist'),
                    'name' => 'DJ Zen Eyer',
                    'url' => home_url(),
                    'jobTitle' => 'Brazilian Zouk DJ & Producer',
                    'sameAs' => $same_as,
                    'image' => $global['default_image'] ?? '',
                    'memberOf' => [
                        '@type' => 'Organization',
                        'name' => 'Mensa International'
                    ]
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => get_permalink($post_id) . '#webpage',
                    'url' => get_permalink($post_id),
                    'name' => $title,
                    'description' => $desc,
                    'about' => ['@id' => home_url('/#artist')]
                ]
            ]
        ];

        return [
            'title' => $title,
            'meta' => [
                ['name' => 'description', 'content' => $desc],
                ['property' => 'og:title', 'content' => $title],
                ['property' => 'og:description', 'content' => $desc],
                ['property' => 'og:image', 'content' => $image],
                ['property' => 'og:type', 'content' => 'website'],
            ],
            'schema' => $schema
        ];
    }

    // =========================================================================
    // 4. SITEMAP & ROBOTS
    // =========================================================================
    public function register_sitemap_rewrite() { add_rewrite_rule('sitemap\.xml$', 'index.php?zen_sitemap=1', 'top'); }
    public function register_query_vars($vars) { $vars[] = 'zen_sitemap'; return $vars; }
    public function clear_sitemap_cache() { delete_transient('zen_seo_sitemap'); }

    public function render_sitemap() {
        if (get_query_var('zen_sitemap')) {
            $sitemap = get_transient('zen_seo_sitemap');
            
            if (false === $sitemap) {
                ob_start();
                echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
                
                // 1. Rotas React
                $global = get_option('zen_seo_global');
                $raw_routes = $global['react_routes'] ?? "/\n/events\n/shop\n/music\n/zentribe\n/work-with-me\n/faq";
                $routes = array_filter(array_map('trim', explode("\n", $raw_routes)));
                
                $home = home_url();
                foreach ($routes as $path) {
                    if (empty($path)) continue;
                    $path = '/' . ltrim($path, '/');
                    $loc = $home . $path;
                    $loc_pt = ($path === '/') ? $home . '/pt/' : str_replace($home, $home . '/pt', $loc);
                    
                    $prio = ($path === '/') ? '1.0' : '0.8';
                    echo '<url><loc>' . esc_url($loc) . '</loc><changefreq>weekly</changefreq><priority>' . $prio . '</priority>';
                    echo '<xhtml:link rel="alternate" hreflang="en" href="' . esc_url($loc) . '"/>';
                    echo '<xhtml:link rel="alternate" hreflang="pt-BR" href="' . esc_url($loc_pt) . '"/></url>';
                }

                // 2. Posts WP
                $types = ['post', 'page', 'product', 'remixes', 'flyers', 'events'];
                foreach ($types as $pt) {
                    $posts = get_posts(['post_type' => $pt, 'posts_per_page' => -1, 'post_status' => 'publish']);
                    foreach ($posts as $post) {
                        $meta = get_post_meta($post->ID, '_zen_seo_data', true);
                        if (!empty($meta['noindex'])) continue;
                        echo '<url><loc>' . esc_url(get_permalink($post->ID)) . '</loc><lastmod>' . get_the_modified_date('Y-m-d', $post->ID) . '</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>';
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

    public function custom_robots_txt($output, $public) {
        $sitemap = home_url('/sitemap.xml');
        return "User-agent: *\nAllow: /\n\n# Bloqueios\nDisallow: /wp-admin/\nDisallow: /wp-json/\n\n# IAs\nUser-agent: GPTBot\nAllow: /\nUser-agent: Google-Extended\nAllow: /\n\nSitemap: $sitemap";
    }
}

new Zen_SEO_Lite();
