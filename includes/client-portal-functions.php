<?php
if (!defined('ABSPATH')) {
  exit;
}

/* -----------------------------------------------------------------------------
 * CONFIG FILTERS
 * -------------------------------------------------------------------------- */

/**
 * CPT slug for your Clients post type.
 * Default: 'client' (change via filter if needed).
 */
function mpro_get_client_cpt_slug() {
  return apply_filters('mpro_client_cpt', 'client');
}

/**
 * The *only* meta key used to store a client's canonical ID on the CPT.
 * Default: 'client_id' (change via filter if you ever rename it).
 */
function mpro_get_client_id_meta_key() {
  return apply_filters('mpro_client_id_meta_key', 'client_id');
}

/* -----------------------------------------------------------------------------
 * USER CONTEXT
 * -------------------------------------------------------------------------- */

/**
 * Get the current user's client_id from user meta 'assigned_client'.
 * Returns '' (empty string) if not set or not logged in.
 * (No default fallback like 'mentorpro'—boxes with empty clients are "all clients".)
 */
function mpro_get_current_user_client_id() {
  if (!is_user_logged_in()) {
    return '';
  }
  $val = get_user_meta(get_current_user_id(), 'assigned_client', true);
  if (is_array($val)) {
    $val = reset($val);
  }
  $val = is_string($val) ? trim($val) : '';
  return $val ?: '';
}

/**
 * Your unified user context (roles + client_id).
 * Uses WP user roles and the usermeta-based client_id.
 */
function mp_get_user_context() {
  if (!is_user_logged_in()) {
    return null;
  }
  $user  = wp_get_current_user();
  $roles = is_a($user, 'WP_User') ? (array) $user->roles : [];
  $primary = reset($roles) ?: null;

  return [
    'user_id'      => $user->ID,
    'roles'        => $roles,
    'primary_role' => $primary,
    'client_id'    => mpro_get_current_user_client_id(),
  ];
}

/* -----------------------------------------------------------------------------
 * CLIENT CPT HELPERS (client_id ONLY—no slug fallback)
 * -------------------------------------------------------------------------- */

/**
 * Get the canonical client_id for a given Client post.
 * Returns '' if none is set.
 */
function mpro_get_client_post_client_id($post_id) {
  $key = mpro_get_client_id_meta_key();
  $val = get_post_meta($post_id, $key, true);
  $val = is_string($val) ? trim($val) : '';
  return $val ?: '';
}

/**
 * Return an array of [client_id, label] for select controls.
 * Skips any Client posts that do not have a client_id set.
 * Label format: "Title (client_id)"
 */
function mpro_get_clients_for_select() {
  $cpt = mpro_get_client_cpt_slug();
  $ids = get_posts([
    'post_type'        => $cpt,
    'numberposts'      => -1,
    'post_status'      => 'publish',
    'orderby'          => 'title',
    'order'            => 'ASC',
    'fields'           => 'ids',
    'suppress_filters' => false,
  ]);

  $out = [];
  foreach ($ids as $pid) {
    $cid = mpro_get_client_post_client_id($pid);
    if ($cid === '') {
      continue; // strictly client_id-only
    }
    $title = get_the_title($pid);
    $out[] = [$cid, sprintf('%s (%s)', $title, $cid)];
  }
  return $out;
}

/**
 * Build a set (assoc array) of valid client_ids for fast validation.
 * Keys are lowercased client_ids; values are the original client_id (for canonical casing).
 */
function mpro_get_valid_client_ids_set() {
  $pairs = mpro_get_clients_for_select();
  $set = [];
  foreach ($pairs as $pair) {
    $id = $pair[0];
    $set[strtolower($id)] = $id;
  }
  return $set;
}

/* -----------------------------------------------------------------------------
 * URL HELPERS (your relative/absolute logic; unchanged)
 * -------------------------------------------------------------------------- */

/**
 * Allow http/https absolute URLs or root-relative (/path) URLs.
 * Returns a cleaned string or empty string if invalid.
 */
function mpro_sanitize_url_allow_relative($url) {
  $url = trim((string) $url);
  if ($url === '') return '';

  if (preg_match('#^https?://#i', $url)) {
    return esc_url_raw($url);
  }

  if (strpos($url, '//') === 0) {
    return 'https:' . esc_url_raw($url);
  }

  if (strpos($url, '/') === 0) {
    $url = preg_replace('/[\x00-\x1F\x7F]/u', '', $url);
    $url = preg_replace('#[\s"]+#', '', $url);
    return $url;
  }

  return '';
}

/**
 * Normalize stored url (absolute or root-relative) to absolute for output.
 */
function mpro_normalize_url_for_output($url) {
  $url = trim((string) $url);
  if ($url === '') return '';

  if (preg_match('#^https?://#i', $url)) {
    return esc_url($url);
  }
  if (strpos($url, '/') === 0) {
    return esc_url(home_url($url));
  }
  if (strpos($url, '//') === 0) {
    return esc_url('https:' . $url);
  }
  return '';
}

/* -----------------------------------------------------------------------------
 * CLIENTS REPORT SHORTCODE (client_id-only)
 * -------------------------------------------------------------------------- */

/**
 * [mpro_clients_report]
 * Renders a table of Client Name + client_id and offers a CSV download.
 * Only lists posts that actually have client_id set.
 */
add_shortcode('mpro_clients_report', function () {
  $cpt = mpro_get_client_cpt_slug();

  $q = new WP_Query([
    'post_type'      => $cpt,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'fields'         => 'ids',
  ]);

  if (!$q->have_posts()) {
    return '<p>No clients found.</p>';
  }

  $rows = [];
  foreach ($q->posts as $pid) {
    $cid = mpro_get_client_post_client_id($pid);
    if ($cid === '') {
      continue; // enforce client_id-only in the report
    }
    $rows[] = [
      'name' => get_the_title($pid),
      'id'   => $cid,
      'pid'  => $pid,
    ];
  }

  if (empty($rows)) {
    return '<p>No clients with a client_id set.</p>';
  }

  ob_start(); ?>
  <h2>MentorPRO Clients</h2>
  <table class="widefat striped" style="max-width:800px;">
    <thead>
      <tr>
        <th style="width:60%;">Client Name</th>
        <th>Client ID</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo esc_html($r['name']); ?></td>
        <td><code><?php echo esc_html($r['id']); ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
    <input type="hidden" name="action" value="mpro_clients_csv">
    <?php wp_nonce_field('mpro_clients_csv', 'mpro_clients_csv_nonce'); ?>
    <button type="submit" class="button button-primary">Download CSV</button>
  </form>
  <?php
  return ob_get_clean();
});

/**
 * CSV download handler (client_id-only)
 */
add_action('admin_post_mpro_clients_csv', 'mpro_clients_csv_handler');
function mpro_clients_csv_handler() {
  if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Insufficient permissions.');
  }
  if (!isset($_POST['mpro_clients_csv_nonce']) || !wp_verify_nonce($_POST['mpro_clients_csv_nonce'], 'mpro_clients_csv')) {
    wp_die('Invalid request.');
  }

  $cpt = mpro_get_client_cpt_slug();
  $q = new WP_Query([
    'post_type'      => $cpt,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'fields'         => 'ids',
  ]);

  $filename = 'mentorpro-clients-' . gmdate('Ymd-His') . '.csv';
  nocache_headers();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=' . $filename);

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Client Name', 'Client ID', 'Post ID']);

  if ($q->have_posts()) {
    foreach ($q->posts as $pid) {
      $cid = mpro_get_client_post_client_id($pid);
      if ($cid === '') continue;
      fputcsv($out, [get_the_title($pid), $cid, $pid]);
    }
  }

  fclose($out);
  exit;
}

/**
 * Return boxes sorted A→Z by title (case-insensitive). Empty titles go last.
 */
function mpro_sort_boxes_alpha(array $boxes): array {
    usort($boxes, function($a, $b) {
        $ta = isset($a['title']) ? trim((string)$a['title']) : '';
        $tb = isset($b['title']) ? trim((string)$b['title']) : '';
        if ($ta === '' && $tb === '') return 0;
        if ($ta === '') return 1;   // empty goes after non-empty
        if ($tb === '') return -1;
        return strnatcasecmp($ta, $tb); // natural, case-insensitive
    });
    return $boxes;
}

/**
 * Front-end sort: explicit `order` first (lowest to highest), then title A→Z.
 * Boxes without `order` get pushed to the end.
 */
function mpro_sort_boxes_by_order_then_title(array $boxes): array {
    usort($boxes, function ($a, $b) {
        $oa = isset($a['order']) ? intval($a['order']) : PHP_INT_MAX;
        $ob = isset($b['order']) ? intval($b['order']) : PHP_INT_MAX;
        if ($oa !== $ob) return $oa <=> $ob;

        $ta = isset($a['title']) ? trim((string)$a['title']) : '';
        $tb = isset($b['title']) ? trim((string)$b['title']) : '';
        return strnatcasecmp($ta, $tb);
    });
    return $boxes;
}

/**
 * Normalize a list of tokens to comparable, case-insensitive slugs.
 * Accepts strings or arrays; returns array of lowercase slugs.
 */
function mpro_norm_tokens($tokens): array {
    if (is_string($tokens)) {
        $tokens = explode(',', $tokens);
    }
    $tokens = (array) $tokens;
    $out = [];
    foreach ($tokens as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t === '') continue;
        // slugify both user inputs and stored values so they match reliably
        $out[] = sanitize_title($t);
    }
    return array_values(array_unique($out));
}

/**
 * Get a box's collections as normalized slugs (handles old boxes with no key).
 */
function mpro_box_collections_slugs(array $box): array {
    $vals = isset($box['collections']) ? (array) $box['collections'] : [];
    // If older data stored plain text, slugify here too
    return mpro_norm_tokens($vals);
}

/**
 * Filter boxes by include/exclude collections.
 * - $include: if non-empty, box must have at least one of these collections.
 * - $exclude: if non-empty, box must NOT have any of these collections.
 * Exclude has the final say if both apply.
 */
function mpro_filter_boxes_by_collections(array $boxes, $include = [], $exclude = []): array {
    $inc = mpro_norm_tokens($include);
    $exc = mpro_norm_tokens($exclude);

    return array_values(array_filter($boxes, function($b) use ($inc, $exc) {
        $cols = mpro_box_collections_slugs($b);

        // Include rule
        if (!empty($inc)) {
            $ok = array_intersect($cols, $inc);
            if (empty($ok)) return false;
        }

        // Exclude rule (wins)
        if (!empty($exc)) {
            $hit = array_intersect($cols, $exc);
            if (!empty($hit)) return false;
        }

        return true;
    }));
}

/**
 * Get a user's roles as display names (primary first), comma-separated.
 */
function mpro_user_role_names($user_id) {
  $user = get_userdata($user_id);
  if (!$user) return '—';
  $role_slugs = (array) $user->roles;
  if (!$role_slugs) return '—';

  // Map slugs → human display names
  global $wp_roles;
  if (!isset($wp_roles)) $wp_roles = wp_roles();
  $map = $wp_roles->role_names;

  // Determine primary (first stored) then others
  $primary = reset($role_slugs);
  $ordered = array_unique(array_merge([$primary], $role_slugs));

  $names = [];
  foreach ($ordered as $slug) {
    $names[] = isset($map[$slug]) ? translate_user_role($map[$slug]) : $slug;
  }
  return implode(', ', $names);
}


/* -----------------------------------------------------------------------------
 * USERS ⇄ CLIENT_ID AUDIT SHORTCODE (front-end)
 * -------------------------------------------------------------------------- */

/**
 * Helper: Resolve a client_id → Title (if your list helper is present)
 */
function mpro_resolve_client_title($client_id) {
  $client_id = is_string($client_id) ? trim($client_id) : '';
  if ($client_id === '') return '';
  if (function_exists('mpro_get_clients_for_select')) {
    foreach (mpro_get_clients_for_select() as $pair) {
      if ($pair[0] === $client_id) {
        // $pair = [client_id, "Title (client_id)"]
        // Extract the title (before the trailing " (id)")
        if (preg_match('/^(.*)\s+\([^)]+\)$/', $pair[1], $m)) {
          return trim($m[1]);
        }
        return $pair[1];
      }
    }
  }
  return '';
}

/**
 * Bulk handler (front-end posts via admin-post.php)
 * - action: mpro_users_audit_bulk
 */
add_action('admin_post_mpro_users_audit_bulk', 'mpro_users_audit_bulk_handler');
function mpro_users_audit_bulk_handler() {
  if (!is_user_logged_in() || !current_user_can('list_users')) {
    wp_die(__('Insufficient permissions.', 'mentorpro'));
  }

  if (
    !isset($_POST['mpua_nonce']) ||
    !wp_verify_nonce($_POST['mpua_nonce'], 'mpro_users_audit')
  ) {
    wp_die(__('Invalid request.', 'mentorpro'));
  }

  $redir = isset($_POST['_mpua_redirect']) ? esc_url_raw($_POST['_mpua_redirect']) : home_url('/');
  $ids   = array_map('absint', $_POST['user_ids'] ?? []);
  $act   = isset($_POST['mpua_action']) ? sanitize_text_field(wp_unslash($_POST['mpua_action'])) : '';

  if (!$ids || !in_array($act, ['update','delete'], true)) {
    $redir = add_query_arg(['mpua_msg' => 'none'], $redir);
    wp_safe_redirect($redir);
    exit;
  }

  if ($act === 'update') {
    $new = isset($_POST['new_client_id']) ? sanitize_text_field(wp_unslash($_POST['new_client_id'])) : '';
    if (function_exists('mp_norm_client_id') && $new !== '') {
      $new = mp_norm_client_id($new);
    }
    if ($new === '') {
      $redir = add_query_arg(['mpua_msg' => 'empty'], $redir);
      wp_safe_redirect($redir);
      exit;
    }
    $updated = 0;
    foreach ($ids as $uid) {
      if (!current_user_can('edit_user', $uid)) continue;
      update_user_meta($uid, 'assigned_client', $new);
      $updated++;
    }
    $redir = add_query_arg(['mpua_msg' => 'updated', 'n' => $updated], $redir);
    wp_safe_redirect($redir);
    exit;
  }

  if ($act === 'delete') {
    if (!current_user_can('delete_users')) {
      $redir = add_query_arg(['mpua_msg' => 'nodl'], $redir);
      wp_safe_redirect($redir);
      exit;
    }
    $deleted = 0;
    foreach ($ids as $uid) {
      if ((int) get_current_user_id() === (int) $uid) continue; // never delete self
      $user = get_userdata($uid);
      if (!$user) continue;
      if (in_array('administrator', (array) $user->roles, true)) continue; // never delete admins
      if (wp_delete_user($uid)) $deleted++;
    }
    $redir = add_query_arg(['mpua_msg' => 'deleted', 'n' => $deleted], $redir);
    wp_safe_redirect($redir);
    exit;
  }
}

/**
 * Build a <select> of clients (only those with client_id), returns HTML.
 */
function mpro_render_client_select($field_name, $selected = '') {
  $pairs = function_exists('mpro_get_clients_for_select') ? mpro_get_clients_for_select() : [];
  ob_start(); ?>
  <select id="<?php echo esc_attr($field_name); ?>" name="<?php echo esc_attr($field_name); ?>">
    <option value=""><?php esc_html_e('— Select client —', 'mentorpro'); ?></option>
    <?php foreach ($pairs as $pair): // [$client_id, "Title (client_id)"] ?>
      <option value="<?php echo esc_attr($pair[0]); ?>" <?php selected($selected, $pair[0]); ?>>
        <?php echo esc_html($pair[1]); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php
  return ob_get_clean();
}

/**
 * [mpro_users_client_audit]
 * Front-end UI to:
 *  - list users missing assigned_client
 *  - list users by a specific client_id
 *  - bulk update/delete selected users
 */
add_shortcode('mpro_users_client_audit', function ($atts) {
  if (!is_user_logged_in() || !current_user_can('list_users')) {
    return '<p>' . esc_html__('You do not have permission to view this report.', 'mentorpro') . '</p>';
  }

  // Query params (GET)
  $mode      = (isset($_GET['mode']) && $_GET['mode'] === 'by_id') ? 'by_id' : 'missing';
  $client_id = isset($_GET['client_id']) ? sanitize_text_field(wp_unslash($_GET['client_id'])) : '';
  $limit     = isset($_GET['limit']) ? max(10, absint($_GET['limit'])) : 500; // simple guard

  // Build user query
  $args = [
    'fields' => ['ID', 'user_login', 'user_email'],
    'number' => $limit,
  ];
  if ($mode === 'missing') {
    $args['meta_query'] = [
      'relation' => 'OR',
      ['key' => 'assigned_client', 'compare' => 'NOT EXISTS'],
      ['key' => 'assigned_client', 'value' => '', 'compare' => '='],
    ];
  } else {
    $args['meta_query'] = [
      ['key' => 'assigned_client', 'value' => $client_id, 'compare' => '='],
    ];
  }
  $users = get_users($args);

  // URL helpers
  $here    = esc_url(remove_query_arg(['mpua_msg','n'])); // keep current filters
  $link_missing = esc_url(add_query_arg(['mode' => 'missing', 'client_id' => null], $here));
  $link_by_id   = esc_url(add_query_arg(['mode' => 'by_id'], $here));

  // Flash messages from bulk handler
  $msg = isset($_GET['mpua_msg']) ? sanitize_text_field($_GET['mpua_msg']) : '';
  $n   = isset($_GET['n']) ? absint($_GET['n']) : 0;

  ob_start(); ?>
  <div class="mpro-users-client-audit">
    <h2><?php //esc_html_e('Users ⇄ Client ID Audit', 'mentorpro'); ?></h2>

    <?php if ($msg): ?>
      <div class="notice <?php echo in_array($msg, ['updated','deleted']) ? 'notice-success' : 'notice-warning'; ?>" style="padding:10px;border-left:4px solid #72aee6;background:#fff;">
        <?php
        switch ($msg) {
          case 'none':
            esc_html_e('No users selected or no action chosen.', 'mentorpro'); break;
          case 'empty':
            esc_html_e('Client ID cannot be empty for updates.', 'mentorpro'); break;
          case 'updated':
            printf(esc_html__('%d user(s) updated.', 'mentorpro'), $n); break;
          case 'deleted':
            printf(esc_html__('%d user(s) deleted.', 'mentorpro'), $n); break;
          case 'nodl':
            esc_html_e('You do not have permission to delete users.', 'mentorpro'); break;
        }
        ?>
      </div>
    <?php endif; ?>

    <?php if ($mode === 'by_id'): ?>
      <form method="get" style="margin:10px 0;" id="mpro-by-id-form">
        <?php
          // preserve other query args on the page
          foreach (array_merge($_GET, ['mode' => 'by_id']) as $k => $v) {
            if (in_array($k, ['client_id', 'limit'], true)) continue;
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(is_array($v) ? '' : $v) . '"/>';
          }
        ?>
        <input type="hidden" name="mode" value="by_id" />
    
        <label for="client_id"><strong><?php esc_html_e('Client', 'mentorpro'); ?>:</strong></label>
        <?php echo mpro_render_client_select('client_id', $client_id); ?>
    
        &nbsp;&nbsp;
        <label for="limit"><?php esc_html_e('Max results', 'mentorpro'); ?>:</label>
        <input type="number" id="limit" name="limit" min="10" step="10" value="<?php echo esc_attr($limit); ?>" style="width:90px;" />
    
        <?php submit_button(__('Filter', 'mentorpro'), 'secondary', '', false); ?>
    
        <?php if ($client_id): ?>
          <span style="margin-left:8px;color:#666;">
            <?php
              $title = mpro_resolve_client_title($client_id);
              if ($title) {
                printf(esc_html__('Resolved title: %s', 'mentorpro'), esc_html($title));
              }
            ?>
          </span>
        <?php endif; ?>
      </form>
    
      <script>
        (function(){
          var sel = document.getElementById('client_id');
          if (sel) sel.addEventListener('change', function(){ document.getElementById('mpro-by-id-form').submit(); });
        })();
      </script>
    <?php endif; ?>


    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
      <?php wp_nonce_field('mpro_users_audit', 'mpua_nonce'); ?>
      <input type="hidden" name="action" value="mpro_users_audit_bulk" />
      <input type="hidden" name="_mpua_redirect" value="<?php echo esc_url($here); ?>" />

      <p>
        <strong><?php esc_html_e('Results', 'mentorpro'); ?>:</strong>
        <?php echo esc_html(count($users)); ?>
      </p>

      <table class="widefat striped" style="max-width:1000px;">
        <thead>
          <tr>
            <th style="width:28px;"><input type="checkbox" onclick="document.querySelectorAll('.mpua-row').forEach(i=>i.checked=this.checked);" /></th>
            <th><?php esc_html_e('User', 'mentorpro'); ?></th>
            <th><?php esc_html_e('assigned_client', 'mentorpro'); ?></th>
            <th><?php esc_html_e('Role(s)', 'mentorpro'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="6"><?php esc_html_e('No users found.', 'mentorpro'); ?></td></tr>
        <?php else: foreach ($users as $u):
          $val   = get_user_meta($u->ID, 'assigned_client', true);
          $title = mpro_resolve_client_title($val);
        ?>
          <tr>
            <td><input class="mpua-row" type="checkbox" name="user_ids[]" value="<?php echo esc_attr($u->ID); ?>" /></td>
            <td>
              <?php echo esc_html($u->user_login . ' (#' . $u->ID . ')'); ?>
              <?php if (current_user_can('edit_user', $u->ID)): ?>
                <br/><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Edit profile', 'mentorpro'); ?></a>
              <?php endif; ?>
            </td>
            <td><code><?php echo $val !== '' ? esc_html($val) : '—'; ?></code></td>
            <td><?php echo esc_html( mpro_user_role_names($u->ID) ); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <div style="margin-top:12px; display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
        <label>
          <input type="radio" name="mpua_action" value="update" checked />
          <?php esc_html_e('Bulk set assigned_client to:', 'mentorpro'); ?>
        </label>
        <input type="text" name="new_client_id" placeholder="client_123" class="regular-text" />
        <?php if (current_user_can('delete_users')): ?>
          <label style="margin-left:16px;">
            <input type="radio" name="mpua_action" value="delete" />
            <span style="color:#b32d2e;"><?php esc_html_e('Bulk delete selected users', 'mentorpro'); ?></span>
          </label>
        <?php endif; ?>
        <?php submit_button(__('Apply', 'mentorpro'), 'primary', 'submit', false); ?>
      </div>

    </form>
  </div>
  <?php
  return ob_get_clean();
});

/**
 * Get the LearnDash group ID for the mentee (primary) group for a client.
 *
 * @param string $client_id The client's unique ID (stored in Client CPT meta 'client_id').
 * @return int|null LearnDash group ID or null if not found.
 */
function get_client_ld_group( string $client_id ) {
    if ( $client_id === '' ) return null;

    $q = new WP_Query([
        'post_type'      => 'client',
        'post_status'    => 'publish',
        'meta_key'       => 'client_id',
        'meta_value'     => $client_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if ( ! $q->have_posts() ) {
        return null;
    }

    $pid = (int) $q->posts[0];

    // Try ACF first
    if ( function_exists('get_field') ) {
        $gid = get_field( 'client_ld_group', $pid );
    } else {
        $gid = get_post_meta( $pid, 'client_ld_group', true );
    }

    return $gid ? (int) $gid : null;
}


/**
 * Get the LearnDash group ID for the mentor group for a client.
 *
 * @param string $client_id The client's unique ID.
 * @return int|null LearnDash group ID or null if not found.
 */
function get_client_mentor_ld_group( string $client_id ) {
    if ( $client_id === '' ) return null;

    $q = new WP_Query([
        'post_type'      => 'client',
        'post_status'    => 'publish',
        'meta_key'       => 'client_id',
        'meta_value'     => $client_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if ( ! $q->have_posts() ) {
        return null;
    }

    $pid = (int) $q->posts[0];

    if ( function_exists('get_field') ) {
        $gid = get_field( 'client_ld_mentor_group', $pid );
    } else {
        $gid = get_post_meta( $pid, 'client_ld_mentor_group', true );
    }

    return $gid ? (int) $gid : null;
}
