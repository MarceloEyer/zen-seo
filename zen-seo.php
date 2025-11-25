<?php
/**
 * Plugin Name: Zen SEO Lite (Headless)
 * Description: SEO essencial, leve e focado em performance para arquiteturas Headless/React. Gera Meta Tags, Open Graph, Schema e Sitemaps.
 * Version: 1.0.0
 * Author: Zen Eyer
 * Text Domain: zen-seo
 */

if (!defined('ABSPATH')) exit;

class Zen_SEO_Lite {

    public function __construct() {
        // 1. Admin Interface (Meta Boxes)
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_data']);

        // 2. API REST (Expor dados para o Frontend)
        add_action('rest_api_init', [$this, 'register_api_fields']);

        // 3. Sitemap XML (Gerado on-the-fly para economizar disco)
        add_action('init', [$this, 'register_sitemap_rewrite']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'render_sitemap']);
    }

    // =========================================================================
    // 1. ADMIN INTERFACE
    // =========================================================================

    public function add_meta_boxes() {
        $screens = ['post', 'page', 'product', 'remixes', 'flyers', 'events'];
        foreach ($screens as $screen) {
            add_meta_box(
                'zen_seo_box',
                '✨ Zen SEO Settings',
                [$this, 'render_meta_box'],
                $screen,
                'normal',
                'high'
            );
        }
    }

    public function render_meta_box($post) {
        $meta = get_post_meta($post->ID, '_zen_seo_data', true) ?: [];
        wp_nonce_field('zen_seo_save', 'zen_seo_nonce');
        ?>
        <div class="zen-seo-wrapper" style="display: grid; gap: 15px;">
            <div>
                <label><strong>Título SEO</strong> (Máx 60 chars)</label>
                <input type="text" name="zen_seo[title]" value="<?php echo esc_attr($meta['title'] ?? ''); ?>" style="width:100%" placeholder="<?php echo get_the_title($post); ?>">
            </div>
            <div>
                <label><strong>Meta Description</strong> (Máx 160 chars)</label>
                <textarea name="zen_seo[desc]" rows="2" style="width:100%" maxlength="160"><?php echo esc_textarea($meta['desc'] ?? ''); ?></textarea>
            </div>
            <div>
                <label><strong>Imagem de Compartilhamento (OG Image)</strong></label>
                <input type="url" name="zen_seo[image]" value="<?php echo esc_url($meta['image'] ?? ''); ?>" style="width:100%" placeholder="URL da imagem...">
                <p class="description">Se vazio, usa a Imagem Destacada.</p>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="zen_seo[noindex]" value="1" <?php checked(isset($meta['noindex']) && $meta['noindex']); ?>>
                    🚫 NoIndex (Esconder do Google)
                </label>
            </div>
        </div>
        <?php
    }

    public function save_meta_data($post_id) {
        if (!isset($_POST['zen_seo_nonce']) || !wp_verify_nonce($_POST['zen_seo_nonce'], 'zen_seo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['zen_seo'])) {
            $data = array_map('sanitize_text_field', $_POST['zen_seo']);
            // Sanitize checkbox specially
            $data['noindex'] = isset($_POST['zen_seo']['noindex']) ? 1 : 0;
            update_post_meta($post_id, '_zen_seo_data', $data);
        }
    }

    // =========================================================================
    // 2. API REST (O CORAÇÃO DO HEADLESS)
    // =========================================================================

    public function register_api_fields() {
        register_rest_field(
            get_post_types(['public' => true]),
            'head_tags', // O campo que o React vai ler
            [
                'get_callback' => [$this, 'get_seo_data_for_api'],
                'schema' => null,
            ]
        );
    }

    public function get_seo_data_for_api($object) {
        $post_id = $object['id'];
        $meta = get_post_meta($post_id, '_zen_seo_data', true) ?: [];
        
        // Fallbacks Inteligentes
        $title = !empty($meta['title']) ? $meta['title'] : get_the_title($post_id) . ' | Zen Eyer';
        $desc = !empty($meta['desc']) ? $meta['desc'] : wp_trim_words(get_post_field('post_content', $post_id), 20);
        $image = !empty($meta['image']) ? $meta['image'] : get_the_post_thumbnail_url($post_id, 'large');
        $canonical = get_permalink($post_id);
        $robots = !empty($meta['noindex']) ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';

        // Schema.org Automático baseado no Post Type
        $schema = $this->generate_schema($post_id, $object['type'], $title, $desc, $image);

        return [
            'title' => $title,
            'meta' => [
                ['name' => 'description', 'content' => $desc],
                ['name' => 'robots', 'content' => $robots],
                ['property' => 'og:title', 'content' => $title],
                ['property' => 'og:description', 'content' => $desc],
                ['property' => 'og:image', 'content' => $image],
                ['property' => 'og:type', 'content' => 'article'],
                ['property' => 'og:url', 'content' => $canonical],
                ['name' => 'twitter:card', 'content' => 'summary_large_image'],
            ],
            'schema' => $schema
        ];
    }

    private function generate_schema($post_id, $type, $title, $desc, $image) {
        $base_schema = [
            '@context' => 'https://schema.org',
            '@graph' => []
        ];

        // 1. WebPage Schema (Padrão)
        $base_schema['@graph'][] = [
            '@type' => 'WebPage',
            '@id' => get_permalink($post_id) . '#webpage',
            'url' => get_permalink($post_id),
            'name' => $title,
            'description' => $desc,
            'isPartOf' => ['@id' => home_url('/#website')]
        ];

        // 2. Specific Schema
        if ($type === 'product') {
            // Lógica básica para produto (WooCommerce handles detailed schema usually, but we can enhance)
            $product = wc_get_product($post_id);
            if ($product) {
                $base_schema['@graph'][] = [
                    '@type' => 'Product',
                    'name' => $product->get_name(),
                    'image' => $image,
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => $product->get_price(),
                        'priceCurrency' => get_woocommerce_currency()
                    ]
                ];
            }
        } elseif ($type === 'remixes') {
            $base_schema['@graph'][] = [
                '@type' => 'MusicComposition',
                'name' => $title,
                'composer' => ['@type' => 'Person', 'name' => 'DJ Zen Eyer']
            ];
        }

        return $base_schema;
    }

    // =========================================================================
    // 3. SITEMAP XML (SIMPLES E AUTOMÁTICO)
    // =========================================================================

    public function register_sitemap_rewrite() {
        add_rewrite_rule('sitemap\.xml$', 'index.php?zen_sitemap=1', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'zen_sitemap';
        return $vars;
    }

    public function render_sitemap() {
        if (get_query_var('zen_sitemap')) {
            header('Content-Type: application/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            
            $post_types = ['post', 'page', 'product', 'remixes', 'flyers'];
            
            foreach ($post_types as $pt) {
                $posts = get_posts(['post_type' => $pt, 'posts_per_page' => -1, 'post_status' => 'publish']);
                foreach ($posts as $post) {
                    // Não incluir se estiver marcado como noindex
                    $meta = get_post_meta($post->ID, '_zen_seo_data', true);
                    if (!empty($meta['noindex'])) continue;

                    echo '<url>';
                    echo '<loc>' . esc_url(get_permalink($post->ID)) . '</loc>';
                    echo '<lastmod>' . get_the_modified_date('Y-m-d', $post->ID) . '</lastmod>';
                    echo '<changefreq>monthly</changefreq>';
                    echo '<priority>0.8</priority>';
                    echo '</url>';
                }
            }
            
            echo '</urlset>';
            exit;
        }
    }
}

new Zen_SEO_Lite();
