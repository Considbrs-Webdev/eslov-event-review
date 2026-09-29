<?php
/**
 * Plugin Name: Eslöv Event Review
 * Description: Lokal granskning av evenemang och begränsning av formulärkonton.
 * Version: 1.0.0
 */
namespace EslovEventReview;
defined('ABSPATH') || exit;

const HOST = 'event.eslov.local';
const BLOCKED = '_eslov_event_review_blocked';
function enabled(): bool { return wp_parse_url(home_url(), PHP_URL_HOST) === HOST; }
function submission(int $id): bool {
    return get_post_type($id) === 'event' && (int) get_post_meta($id, 'mod_frontend_form_module_id', true) === 12;
}
register_activation_hook(__FILE__, function ($network) {
    if ($network || !enabled()) { wp_die('Aktivera endast på event.eslov.local, inte nätverksaktiverat.'); }
});
if (!enabled()) { return; }

// Remove generated passwords on new submissions; preserve passwords enabled later.
add_filter('wp_insert_post_data', function ($data, $postarr, $raw, $update) {
    if ($data['post_type'] !== 'event') { return $data; }
    $form = (int) ($postarr['meta_input']['mod_frontend_form_module_id'] ?? 0);
    if (!$update && $form === 12) {
        $data['post_password'] = '';
    }
    return $data;
}, 99, 4);

add_filter('manage_event_posts_columns', function ($columns) {
    $columns['eslov_review'] = 'Granskning';
    return $columns;
});
add_action('manage_event_posts_custom_column', function ($column, $id) {
    if ($column !== 'eslov_review' || !in_array(get_post_status($id), ['pending', 'draft'], true) || !current_user_can('edit_post', $id)) { return; }
    echo '<div class="eslov-event-review-actions">';
    foreach (['approve' => 'Acceptera', 'deny' => 'Neka'] as $action => $label) {
        if ($action === 'approve' ? !current_user_can(get_post_type_object('event')->cap->publish_posts) : !current_user_can('delete_post', $id)) { continue; }
        $url = wp_nonce_url(add_query_arg(['action' => 'eslov_event_review', 'event_id' => $id, 'decision' => $action], admin_url('admin-post.php')), 'eslov_review_' . $id);
        printf('<a class="button button-small%s" href="%s"%s>%s</a>', $action === 'approve' ? ' button-primary' : '', esc_url($url), $action === 'deny' ? ' onclick="return confirm(\'Flytta evenemanget till papperskorgen?\')"' : '', esc_html($label));
    }
    printf('<a class="button button-small" href="%s">Granska</a>', esc_url(get_edit_post_link($id)));
    echo '</div>';
}, 10, 2);
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'edit.php' || get_current_screen()?->post_type !== 'event') { return; }
    wp_add_inline_style('common', '.column-eslov_review{width:16em}.eslov-event-review-actions{display:flex;gap:4px;flex-wrap:wrap}');
});

/** Resolve only Event Manager's organizer handler, not every ACF save callback. */
function organizerHandler(int $id) {
    if (!function_exists('get_field') || !get_field('submitNewOrganization', $id)) { return null; }
    global $wp_filter;
    foreach (($wp_filter['acf/save_post']->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $fn = $callback['function'];
            if (is_array($fn) && $fn[0] instanceof \EventManager\AcfSavePostActions\CreateNewOrganizerFromEventSubmit\CreateNewOrganizerFromEventSubmit && $fn[1] === 'savePost') {
                return $fn[0];
            }
        }
    }
    return new \WP_Error('organizer_handler_missing', 'Event Managers arrangörshanterare saknas.');
}

function decide(int $id, string $decision) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'event' || !in_array($post->post_status, ['pending', 'draft'], true) || !current_user_can('edit_post', $id)) {
        return new \WP_Error('invalid_event', 'Evenemanget kan inte behandlas.');
    }
    if ($decision === 'approve' && current_user_can(get_post_type_object('event')->cap->publish_posts)) {
        $handler = organizerHandler($id);
        if (is_wp_error($handler)) { return $handler; }
        $result = wp_update_post(['ID' => $id, 'post_status' => 'publish', 'post_password' => ''], true);
        if (is_wp_error($result) || !$result || !$handler) { return $result; }
        try {
            $handler->savePost($id);
            if (get_field('submitNewOrganization', $id)) {
                throw new \RuntimeException('Arrangörsuppgifterna kunde inte behandlas.');
            }
        } catch (\Throwable $error) {
            wp_update_post(['ID' => $id, 'post_status' => $post->post_status, 'post_password' => $post->post_password]);
            return new \WP_Error('organizer_creation_failed', 'Arrangören kunde inte skapas. Kontrollera arrangörsuppgifterna.');
        }
        // Refresh save hooks/indexing after Event Manager has assigned the organizer.
        return wp_update_post(['ID' => $id], true);
    }
    if ($decision === 'deny' && current_user_can('delete_post', $id)) { return wp_trash_post($id); }
    return new \WP_Error('forbidden', 'Åtgärden är inte tillåten.');
}
add_action('admin_post_eslov_event_review', function () {
    $id = absint($_GET['event_id'] ?? 0);
    check_admin_referer('eslov_review_' . $id);
    $result = decide($id, sanitize_key($_GET['decision'] ?? ''));
    if (!$result || is_wp_error($result)) { wp_die('Evenemanget kunde inte behandlas.', '', ['response' => 403]); }
    wp_safe_redirect(admin_url('edit.php?post_type=event'));
    exit;
});

// Scope user creation and the Event Manager welcome email to form 12 only.
$GLOBALS['eslov_event_review_context'] = [];
add_action('EventManager/OrganizationCreated', function ($postId) {
    $GLOBALS['eslov_event_review_context'][] = submission((int) $postId);
}, 0);
add_action('EventManager/OrganizationCreated', function () { array_pop($GLOBALS['eslov_event_review_context']); }, PHP_INT_MAX);
function creating(): bool { return (bool) end($GLOBALS['eslov_event_review_context']); }
function blocked($id): bool { return (bool) get_user_meta((int) $id, BLOCKED, true); }
add_action('user_register', function ($id) {
    if (creating()) {
        update_user_meta($id, BLOCKED, 1);
        (new \WP_User($id))->set_role('subscriber');
    }
}, 0);
add_action('set_user_role', function ($id, $role) {
    if (creating() && blocked($id) && $role !== 'subscriber') { (new \WP_User($id))->set_role('subscriber'); }
}, 99, 2);
add_filter('pre_wp_mail', function ($result) { return creating() ? true : $result; }, PHP_INT_MAX);
foreach (['wp_send_new_user_notification_to_user', 'wp_send_new_user_notification_to_admin'] as $hook) {
    add_filter($hook, function ($send, $user) { return blocked($user->ID) ? false : $send; }, 99, 2);
}
add_filter('authenticate', function ($user) {
    return $user instanceof \WP_User && blocked($user->ID) ? new \WP_Error('event_account_disabled', 'Detta formulärkonto kan inte användas för inloggning.') : $user;
}, PHP_INT_MAX);
add_filter('allow_password_reset', function ($allow, $id) { return blocked($id) ? false : $allow; }, 99, 2);
add_filter('wp_is_application_passwords_available_for_user', function ($allow, $user) { return blocked($user->ID) ? false : $allow; }, 99, 2);
add_filter('determine_current_user', function ($id) { return $id && blocked($id) ? 0 : $id; }, PHP_INT_MAX);

// The frontend edit notice uses post_password as its token. Without a token,
// suppress only that notice, leaving all other admin notices untouched.
function hideUnavailableFrontendEditNotice(): void {
    $post = get_post();
    if ($post instanceof \WP_Post && $post->post_type === 'event' && $post->post_password !== '') {
        return;
    }
    global $wp_filter;
    foreach (($wp_filter['admin_notices']->callbacks ?? []) as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            $fn = $callback['function'];
            $owner = $fn instanceof \Closure
                ? (new \ReflectionFunction($fn))->getClosureThis()
                : (is_array($fn) ? $fn[0] : null);
            if ($owner instanceof \ModularityFrontendForm\Admin\DisplayEditLinkInterfaceNotice) {
                remove_action('admin_notices', $fn, $priority);
            }
        }
    }
}
add_action('admin_notices', __NAMESPACE__ . '\\hideUnavailableFrontendEditNotice', -100);
