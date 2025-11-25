<?php
/**
 * Plugin Name: Zen SEO Lite (Artist Ultimate Edition)
 * Description: SEO definitivo para DJ Zen Eyer. Integração com Knowledge Graph, Schema Musical Avançado, Sitemap Híbrido (React + WP) e Robots.txt Otimizado.
 * Version: 2.5.0
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
        
        // Cache Clearing
        add_action('save_post', [$this, 'clear_sitemap_cache']);
    }

    // =========================================================================
    // 1. PAINEL DE IDENTIDADE DO ARTISTA
    // =========================================================================
    public function add_admin_menu() {
        add_menu_page('Zen SEO', 'Zen SEO', 'manage_options', 'zen-seo-settings', [$this, 'render_settings_page'], 'dashicons-album', 99);
    }

    public function register_settings() {
        register_setting('zen_seo_options', 'zen_seo_global');
        add_settings_section('zen_seo_authority', __('🎵 Autoridade & Identidade (Knowledge Graph)', 'zen-seo'), null, 'zen-seo-settings');
        
        $fields = [
            'wikidata' => 'Wikidata URL',
            'musicbrainz' => 'MusicBrainz Artist URL',
            'isni' => 'ISNI Code',
            'spotify' => 'Spotify URL',
            'apple_music' => 'Apple Music URL',
            'soundcloud' => 'SoundCloud URL',
            'youtube' => 'YouTube Channel',
            'instagram' => 'Instagram URL',
            'facebook' => 'Facebook URL',
            'tiktok' => 'TikTok URL',
            'default_image' => 'Imagem Padrão (URL)'
        ];

        foreach ($fields as $id => $label) {
            add_settings_field($id, $label, [$this, 'render_field'], 'zen-seo-settings', 'zen_seo_authority', ['id' => $id]);
        }
    }

    public function render_field($args) {
        $options = get_option('zen_seo_global');
        $value = $options[$args['id']] ?? '';
        echo "<input type='text' name='zen_seo_global[{$args['id']}]' value='" . esc_attr($value) . "' class='regular-text' style='width:100%'>";
    }

    public function render_settings_page() {
        echo '<div class="wrap"><h1>🚀 Zen SEO - Painel do Artista</h1><form method="post" action="options.php">';
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
            <label><input type="checkbox" name="zen_seo[noindex]" value="1" <?php checked(isset($meta['noindex']) && $meta['noindex']); ?>> NoIndex</label>
        </div>
        <?php
    }

    public function save_meta_data($post_id) {
        if (!isset($_POST['zen_seo_nonce']) || !wp_verify_nonce($_POST['zen_seo_nonce'], 'zen_seo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['zen_seo'])) {
            $data = [
                'title' => sanitize_text_field($_POST['zen_seo']['title']),
                'desc' => sanitize_textarea_field($_POST['zen_seo']['desc']),
                'image' => esc_url_raw($_POST['zen_seo']['image']),
                'noindex' => isset($_POST['zen_seo']['noindex']) ? 1 : 0
            ];
            update_post_meta($post_id, '_zen_seo_data', $data);
        }
    }

    public function admin_notices() {
        if (isset($_GET['settings-updated'])) echo '<div class="notice notice-success"><p>Identidade salva!</p></div>';
    }

    // =========================================================================
    // 3. API & SCHEMA
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
        
        // Schema Generator
        $same_as = array_values(array_filter([
            $global['wikidata'] ?? '', $global['musicbrainz'] ?? '', $global['spotify'] ?? '',
            $global['soundcloud'] ?? '', $global['instagram'] ?? '', $global['facebook'] ?? ''
        ]));

        $schema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Person',
                    '@id' => home_url('/#artist'),
                    'name' => 'DJ Zen Eyer',
                    'url' => home_url(),
                    'jobTitle' => 'Brazilian Zouk DJ',
                    'sameAs' => $same_as,
                    'image' => $global['default_image'] ?? ''
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
    
    // [CORREÇÃO] Função que faltava no seu rascunho
    public function clear_sitemap_cache() { delete_transient('zen_seo_sitemap'); }

    public function render_sitemap() {
        if (get_query_var('zen_sitemap')) {
            $sitemap = get_transient('zen_seo_sitemap');
            
            if (false === $sitemap) {
                ob_start();
                echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
                
                // 1. Rotas React (Do seu seo.php)
                $static_urls = [
                    ['loc' => '/', 'prio' => '1.0'],
                    ['loc' => '/events', 'prio' => '0.9'],
                    ['loc' => '/shop', 'prio' => '0.9'],
                    ['loc' => '/music', 'prio' => '0.8'],
                    ['loc' => '/zentribe', 'prio' => '0.8'],
                    ['loc' => '/work-with-me', 'prio' => '0.8'],
                    ['loc' => '/faq', 'prio' => '0.6']
                ];
                $home = home_url();
                foreach ($static_urls as $page) {
                    $loc = $home . $page['loc'];
                    $loc_pt = ($page['loc'] === '/') ? $home . '/pt/' : str_replace($home, $home . '/pt', $loc);
                    echo '<url><loc>' . esc_url($loc) . '</loc><changefreq>weekly</changefreq><priority>' . $page['prio'] . '</priority>';
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
                
                // [CORREÇÃO] Salva o cache corretamente
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
        return "User-agent: *\nAllow: /\n\n# Bloqueios\nDisallow: /wp-admin/\nDisallow: /wp-json/\n\n# IAs\nUser-agent: GPTBot\nAllow: /\n\nSitemap: $sitemap";
    }
}

new Zen_SEO_Lite();
