<?php
/**
 * Plugin Name: Zen SEO Headless
 * Description: Plugin leve de SEO focado em performance para React/Headless.
 * Version: 1.0
 * Author: Zen Eyer
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// 1. CRIAR A CAIXA DE SEO NO EDITOR (META BOX)
// ============================================================================

function zen_seo_add_meta_box() {
    $screens = ['post', 'page', 'product', 'remixes', 'flyers']; // Onde o SEO vai aparecer
    foreach ($screens as $screen) {
        add_meta_box(
            'zen_seo_box',           // ID
            '🚀 Zen SEO Headless',   // Título
            'zen_seo_box_html',      // Função de callback
            $screen,                 // Tela
            'normal',                // Contexto
            'high'                   // Prioridade
        );
    }
}
add_action('add_meta_boxes', 'zen_seo_add_meta_box');

function zen_seo_box_html($post) {
    $title = get_post_meta($post->ID, '_zen_seo_title', true);
    $desc = get_post_meta($post->ID, '_zen_seo_desc', true);
    $image = get_post_meta($post->ID, '_zen_seo_image', true);
    
    wp_nonce_field('zen_seo_save', 'zen_seo_nonce');
    ?>
    <div style="padding: 10px;">
        <p>
            <label style="font-weight:bold; display:block;">Meta Title (Título Azul do Google):</label>
            <input type="text" name="zen_seo_title" value="<?php echo esc_attr($title); ?>" style="width:100%;" placeholder="<?php echo get_the_title($post); ?>" />
            <small>Deixe vazio para usar o título padrão.</small>
        </p>
        <p>
            <label style="font-weight:bold; display:block;">Meta Description (Texto abaixo):</label>
            <textarea name="zen_seo_desc" rows="3" style="width:100%;" maxlength="160"><?php echo esc_textarea($desc); ?></textarea>
            <small>Ideal: 140-160 caracteres.</small>
        </p>
        <p>
            <label style="font-weight:bold; display:block;">Imagem de Compartilhamento (URL):</label>
            <input type="url" name="zen_seo_image" value="<?php echo esc_attr($image); ?>" style="width:100%;" />
            <small>Se vazio, usa a Imagem Destacada.</small>
        </p>
    </div>
    <?php
}

// ============================================================================
// 2. SALVAR OS DADOS
// ============================================================================

function zen_seo_save($post_id) {
    if (!isset($_POST['zen_seo_nonce']) || !wp_verify_nonce($_POST['zen_seo_nonce'], 'zen_seo_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['zen_seo_title'])) update_post_meta($post_id, '_zen_seo_title', sanitize_text_field($_POST['zen_seo_title']));
    if (isset($_POST['zen_seo_desc'])) update_post_meta($post_id, '_zen_seo_desc', sanitize_text_field($_POST['zen_seo_desc']));
    if (isset($_POST['zen_seo_image'])) update_post_meta($post_id, '_zen_seo_image', esc_url_raw($_POST['zen_seo_image']));
}
add_action('save_post', 'zen_seo_save');

// ============================================================================
// 3. EXPOR NA API (O PULO DO GATO)
// ============================================================================

function zen_seo_register_api_field() {
    register_rest_field(
        ['post', 'page', 'product', 'remixes', 'flyers'], // Tipos de post que terão SEO na API
        'zen_seo', // Nome do campo no JSON
        array(
            'get_callback' => 'zen_seo_get_api_data',
            'schema' => null,
        )
    );
}
add_action('rest_api_init', 'zen_seo_register_api_field');

function zen_seo_get_api_data($object) {
    $post_id = $object['id'];
    
    // Lógica de Fallback (Se não preencheu, usa o padrão)
    $custom_title = get_post_meta($post_id, '_zen_seo_title', true);
    $final_title = !empty($custom_title) ? $custom_title : get_the_title($post_id);

    $custom_desc = get_post_meta($post_id, '_zen_seo_desc', true);
    $final_desc = !empty($custom_desc) ? $custom_desc : wp_trim_words(get_post_field('post_content', $post_id), 20);

    $custom_image = get_post_meta($post_id, '_zen_seo_image', true);
    $final_image = !empty($custom_image) ? $custom_image : get_the_post_thumbnail_url($post_id, 'large');

    return [
        'title' => $final_title . ' | Zen Eyer',
        'description' => $final_desc,
        'image' => $final_image,
        'canonical' => get_permalink($post_id) // Canonical automático
    ];
}
