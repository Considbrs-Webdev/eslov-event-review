<?php
/**
 * Plugin Name: Eslöv Event Review
 * Plugin URI: https://github.com/Considbrs-Webdev/eslov-event-review
 * Description: Event submission review and restrictions for organizer accounts.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: eslov-event-review
 * Domain Path: /languages
 */
namespace EslovEventReview;
defined('ABSPATH') || exit;

add_action('init', function () {
    load_plugin_textdomain('eslov-event-review', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);

const BLOCKED = '_eslov_event_review_blocked';
const FORMS_FIELD = 'eslov_event_review_forms';

/** ACF stores options per site; an empty selection disables new review flows. */
function selectedForms(): array {
    return array_values(array_filter(array_unique(array_map('absint', (array) get_option('options_' . FORMS_FIELD, [])))));
}
function selectedForm(int $id): bool {
    return $id > 0 && in_array($id, selectedForms(), true);
}
function submission(int $id): bool {
    return get_post_type($id) === 'event' && selectedForm((int) get_post_meta($id, 'mod_frontend_form_module_id', true));
}
register_activation_hook(__FILE__, function ($network) {
    if ($network) { wp_die(__('Activate Eslöv Event Review separately on each site.', 'eslov-event-review')); }
});

add_action('acf/init', function () {
    acf_add_local_field_group([
        'key' => 'group_eslov_event_review_settings',
        'title' => __('Event review', 'eslov-event-review'),
        'fields' => [[
            'key' => 'field_eslov_event_review_forms',
            'label' => __('Forms requiring review', 'eslov-event-review'),
            'name' => FORMS_FIELD,
            'type' => 'post_object',
            'post_type' => ['mod-frontend-form'],
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'multiple' => 1,
            'allow_null' => 1,
            'return_format' => 'id',
            'ui' => 1,
            'instructions' => __('Select one or more event submission forms. New events are saved as Pending Review without a password. New organizer accounts become subscribers, cannot log in, and receive no welcome emails. Other forms are unaffected. Leave empty to disable review of new submissions.', 'eslov-event-review'),
        ]],
        'location' => [[[
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'mod-frontend-form-options',
        ]]],
    ]);
});

// Modularity removes module post types from generic ACF post-object queries.
// Restore the type only for our explicit form selector.
add_filter('acf/fields/post_object/query/key=field_eslov_event_review_forms', function ($args) {
    $args['post_type'] = ['mod-frontend-form'];
    return $args;
});
add_filter('acf/fields/post_object/result/key=field_eslov_event_review_forms', function ($title, $post) {
    /* translators: %d: Frontend form module ID. */
    return $title . ' ' . sprintf(__('(ID %d)', 'eslov-event-review'), $post->ID);
}, 10, 2);

// Require review on new submissions; preserve existing events during later edits.
add_filter('wp_insert_post_data', function ($data, $postarr, $raw, $update) {
    if ($data['post_type'] !== 'event') { return $data; }
    $form = (int) ($postarr['meta_input']['mod_frontend_form_module_id'] ?? 0);
    if (!$update && selectedForm($form)) {
        $data['post_status'] = 'pending';
        $data['post_password'] = '';
    }
    return $data;
}, 99, 4);

add_filter('manage_event_posts_columns', function ($columns) {
    $columns['eslov_review'] = __('Review', 'eslov-event-review');
    return $columns;
});
add_action('manage_event_posts_custom_column', function ($column, $id) {
    if ($column !== 'eslov_review' || !submission((int) $id) || !in_array(get_post_status($id), ['pending', 'draft'], true) || !current_user_can('edit_post', $id)) { return; }
    echo '<div class="eslov-event-review-actions">';
    foreach (['approve' => __('Accept', 'eslov-event-review'), 'deny' => __('Reject', 'eslov-event-review')] as $action => $label) {
        if ($action === 'approve' ? !current_user_can(get_post_type_object('event')->cap->publish_posts) : !current_user_can('delete_post', $id)) { continue; }
        $url = wp_nonce_url(add_query_arg(['action' => 'eslov_event_review', 'event_id' => $id, 'decision' => $action], admin_url('admin-post.php')), 'eslov_review_' . $id);
        $confirm = $action === 'deny'
            ? ' onclick="return confirm(' . esc_attr(wp_json_encode(__('Move the event to the Trash?', 'eslov-event-review'))) . ')"'
            : '';
        printf('<a class="button button-small%s" href="%s"%s>%s</a>', $action === 'approve' ? ' button-primary' : '', esc_url($url), $confirm, esc_html($label));
    }
    printf('<a class="button button-small" href="%s">%s</a>', esc_url(get_edit_post_link($id)), esc_html__('Review event', 'eslov-event-review'));
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
    return new \WP_Error('organizer_handler_missing', __('The Event Manager organizer handler is unavailable.', 'eslov-event-review'));
}

function decide(int $id, string $decision) {
    $post = get_post($id);
    if (!$post || !submission($id) || $post->post_type !== 'event' || !in_array($post->post_status, ['pending', 'draft'], true) || !current_user_can('edit_post', $id)) {
        return new \WP_Error('invalid_event', __('The event cannot be processed.', 'eslov-event-review'));
    }
    if ($decision === 'approve' && current_user_can(get_post_type_object('event')->cap->publish_posts)) {
        $handler = organizerHandler($id);
        if (is_wp_error($handler)) { return $handler; }
        $result = wp_update_post(['ID' => $id, 'post_status' => 'publish', 'post_password' => ''], true);
        if (is_wp_error($result) || !$result || !$handler) { return $result; }
        try {
            $handler->savePost($id);
            if (get_field('submitNewOrganization', $id)) {
                throw new \RuntimeException(__('The organizer details could not be processed.', 'eslov-event-review'));
            }
        } catch (\Throwable $error) {
            wp_update_post(['ID' => $id, 'post_status' => $post->post_status, 'post_password' => $post->post_password]);
            return new \WP_Error('organizer_creation_failed', __('The organizer could not be created. Check the organizer details.', 'eslov-event-review'));
        }
        // Refresh save hooks/indexing after Event Manager has assigned the organizer.
        return wp_update_post(['ID' => $id], true);
    }
    if ($decision === 'deny' && current_user_can('delete_post', $id)) { return wp_trash_post($id); }
    return new \WP_Error('forbidden', __('This action is not allowed.', 'eslov-event-review'));
}
add_action('admin_post_eslov_event_review', function () {
    $id = absint($_GET['event_id'] ?? 0);
    check_admin_referer('eslov_review_' . $id);
    $result = decide($id, sanitize_key($_GET['decision'] ?? ''));
    if (!$result || is_wp_error($result)) { wp_die(__('The event could not be processed.', 'eslov-event-review'), '', ['response' => 403]); }
    wp_safe_redirect(admin_url('edit.php?post_type=event'));
    exit;
});

// Scope user creation and the Event Manager welcome email to selected forms.
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
    return $user instanceof \WP_User && blocked($user->ID) ? new \WP_Error('event_account_disabled', __('This form account cannot be used to log in.', 'eslov-event-review')) : $user;
}, PHP_INT_MAX);
add_filter('allow_password_reset', function ($allow, $id) { return blocked($id) ? false : $allow; }, 99, 2);
add_filter('wp_is_application_passwords_available_for_user', function ($allow, $user) { return blocked($user->ID) ? false : $allow; }, 99, 2);
add_filter('determine_current_user', function ($id) { return $id && blocked($id) ? 0 : $id; }, PHP_INT_MAX);

// The frontend edit notice uses post_password as its token. Without a token,
// suppress only that notice, leaving all other admin notices untouched.
function hideUnavailableFrontendEditNotice(): void {
    $post = get_post();
    if (!$post instanceof \WP_Post || !submission((int) $post->ID) || $post->post_password !== '') {
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
