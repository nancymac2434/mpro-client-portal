<?php

// Helper: current user's client_id (adjust fallbacks to your app)
function mpro_get_current_client_id() {
	$current_client_id = '';
	if ( function_exists('mp_get_user_context') ) {
		$ctx = mp_get_user_context();
		$current_client_id = isset($ctx['client_id']) ? (string)$ctx['client_id'] : '';
	} elseif ( class_exists('\MentorPro\MP_User_Context') ) {
		$ctx = \MentorPro\MP_User_Context::get_current();
		$current_client_id = isset($ctx['client_id']) ? (string)$ctx['client_id'] : '';
	} else {
		$uid = get_current_user_id();
		if ($uid) {
			$meta_client = get_user_meta($uid, 'assigned_client', true);
			if ($meta_client) $current_client_id = (string)$meta_client;
		}
	}
	return $current_client_id;
}

// Optional fallback map if you want to hard-code per-topic allow-lists in code.
// If you use term meta exclusively, you can leave this empty array.
function mpro_topic_clients_fallback_map() {
	return [
		//'mentor-ai' => ['umb','umb-cla' , 'drew'], // example
	];
}

// Check if a topic term is allowed for this client_id.
// Priority: term meta 'mpro_clients_allow' (CSV) > fallback PHP map.
// Empty allow-list means "show to all".
function mpro_topic_allowed_for_client( WP_Term $term, $client_id ) {
	// 1) Term meta CSV
	$csv = (string) get_term_meta($term->term_id, 'mpro_clients_allow', true);
	$allow = [];
	if ($csv !== '') {
		$allow = array_values(array_filter(array_map('trim', explode(',', $csv))));
	} else {
		// 2) Fallback map by slug
		$map = mpro_topic_clients_fallback_map();
		if (isset($map[$term->slug]) && is_array($map[$term->slug])) {
			$allow = array_values(array_filter(array_map('trim', $map[$term->slug])));
		}
	}

	// Empty allow => no restriction
	if (empty($allow)) return true;

	// If user has no client_id or not in allow-list => not allowed
	if (empty($client_id)) return false;

	return in_array($client_id, $allow, true);
}

/**
 * Shortcode: [mpro_help_boxes role="mentor-help-page" title="Help Center"]
 * Renders the portal, but hides any topic (box + section) unless the user is in that topic's allowed clients.
 */
function mpro_faq_portal_shortcode( $atts ) {
	$atts = shortcode_atts( [
		'role'  => 'mentor-help-page',
		'title' => '',
	], $atts );

	$current_client_id = mpro_get_current_client_id();

	// Normalize role
	$role_slug_in = trim( (string) $atts['role'] );
	$role_slug    = sanitize_title( str_replace('_','-', $role_slug_in) );

	// Role terms to exclude from topics
	$role_slugs_blocklist = [
		'mentor-help-page','mentee-help-page','program-manager',
		'mentor_help_page','mentee_help_page','program_manager',
	];

	// Topic images
	$topic_images = [
		'mentorpro-set-up'          => '/wp-content/uploads/2025/08/MentorPRO-Setup—Help-Center.png',
		'announcements'             => '/wp-content/uploads/2025/08/Announcements—Help-Center.png',
		'assessments-and-surveys'   => '/wp-content/uploads/2025/08/Assessments-and-Surveys—Help-Center.png',
		'chat-and-messages'         => '/wp-content/uploads/2025/08/Chats-and-Messages—Help-Center.png',
		'check-in'                  => '/wp-content/uploads/2025/08/Check-Ins—Help-Center.png',
		'data-security-and-privacy' => '/wp-content/uploads/2025/08/Data-Privacy-and-Security—Help-Center.png',
		'document-manager'          => '/wp-content/uploads/2025/08/Document-Manager—Help-Center.png',
		'events'                    => '/wp-content/uploads/2025/08/Events—Help-Center.png',
		'flagged-mentor'            => '/wp-content/uploads/2025/08/Flagged-Mentor—Help-Center.png',
		'goals'                     => '/wp-content/uploads/2025/08/Goals—Help-Center.png',
		'identifiers'               => '/wp-content/uploads/2025/08/Identifiers—Help-Center.png',
		'invite-codes'              => '/wp-content/uploads/2025/08/Invite-Codes—Help-Center.png',
		'meetings'                  => '/wp-content/uploads/2025/08/Meetings—Help-Center.png',
		'mentorai'                  => '/wp-content/uploads/2025/08/MentorAI.png',
		'reports-and-data'          => '/wp-content/uploads/2025/08/Reports-and-Data—Help-Center.png',
		'resource-hub'              => '/wp-content/uploads/2025/08/Resource-Hub—Help-Center.png',
		'tasks'                  => '/wp-content/uploads/2025/09/Tasks—Client-Portal-Icons.png',
	];
	$allowed_topic_slugs = array_keys($topic_images);
	$default_img         = '/wp-content/uploads/2025/08/Help-Center.png';

	$title_overrides = [
		'reports-and-data' => 'Reports<br>& Data',
		'data-security-and-privacy' => 'Data Security<br>& Privacy',
		'mentorpro-set-up' => 'MentorPRO<br>Setup',
		'chat-and-messages' => 'Chats<br>& Messages',
		'assessments-and-surveys' => 'Assessments<br>& Surveys',
	];
	
	// Verify role term exists
	$role_term = get_term_by('slug', $role_slug, 'ufaq-category');
	if (!$role_term || is_wp_error($role_term)) return '<p>No FAQs found for this role.</p>';

	// All FAQs in this role
	$faqs_for_role = get_posts([
		'post_type'      => 'ufaq',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [[
			'taxonomy' => 'ufaq-category',
			'field'    => 'slug',
			'terms'    => $role_slug,
		]],
		'no_found_rows'            => true,
		'update_post_term_cache'   => false,
		'update_post_meta_cache'   => false,
	]);
	if (empty($faqs_for_role)) return '<p>No FAQs available yet.</p>';

	// Collect co-occurring topics (excluding role slugs), then *gate by client_id*
	$topic_terms = [];
	foreach ($faqs_for_role as $faq_id) {
		$terms = get_the_terms($faq_id, 'ufaq-category');
		if (empty($terms) || is_wp_error($terms)) continue;

		foreach ($terms as $t) {
			$slug = $t->slug;

			if ($slug === $role_slug || in_array($slug, $role_slugs_blocklist, true)) continue;
			if (!in_array($slug, $allowed_topic_slugs, true)) continue;

			$topic_terms[$t->term_id] = $t; // de-dupe
		}
	}

	if (empty($topic_terms)) return '<p>FAQs are not categorized by topic yet.</p>';

	// Now filter out topics not allowed for this user
	$topic_terms = array_values(array_filter($topic_terms, function($term) use ($current_client_id) {
		return mpro_topic_allowed_for_client($term, $current_client_id);
	}));

	if (empty($topic_terms)) return '<p>No topics available for your program.</p>';

	// Sort by name
	usort($topic_terms, function($a,$b){ return strcasecmp($a->name, $b->name); });

	// --- Output ---
	ob_start();

	if (!empty($atts['title'])) {
		echo '<h1 class="mpro-faq-portal-title">' . esc_html($atts['title']) . '</h1>';
	}

	// Topic grid
	echo '<div class="mpro-grid-15">';
	foreach ($topic_terms as $term) {
		$slug  = esc_attr($term->slug);
		$title = isset($title_overrides[$slug])
			? $title_overrides[$slug]
			: str_replace(['&amp;'], ['&'], $term->name);
//		$title = wp_kses(str_replace(['&amp;'], ['&'], $term->name), ['br'=>[]]);
		$title = wp_kses($title, ['br' => []]);
		$img   = esc_url($topic_images[$slug] ?? $default_img);

		echo '<div class="mpro-grid-item">';
		echo '<a href="#faq-' . $slug . '">';
		echo '<div class="mpro-grid-title">' . $title . '</div>';
		echo '<img src="' . $img . '" width="80" height="80" alt="' . esc_attr(wp_strip_all_tags($title)) . '">';
		echo '</a>';
		echo '</div>';
	}
	echo '</div>';

	// Sections per (allowed) topic
	foreach ($topic_terms as $term) {
		$slug  = esc_attr($term->slug);
		$title = esc_html($term->name);

		echo '<h2 id="faq-' . $slug . '" class="mpro-faq-title">' . $title . '</h2>';

		$faqs = get_posts([
			'post_type'      => 'ufaq',
			'posts_per_page' => -1,
			'orderby'        => ['menu_order'=>'ASC','title'=>'ASC'],
			'tax_query'      => [
				'relation' => 'AND',
				['taxonomy'=>'ufaq-category','field'=>'slug','terms'=>$role_slug],
				['taxonomy'=>'ufaq-category','field'=>'slug','terms'=>$term->slug],
			],
			'no_found_rows'  => true,
		]);

		if (empty($faqs)) { echo '<p class="mpro-faq-empty">No articles yet in this topic.</p>'; continue; }

		echo '<div class="mpro-faq-group">';
		foreach ($faqs as $faq) {
			$q = esc_html(get_the_title($faq));
			$a_raw = apply_filters('the_content', get_post_field('post_content', $faq));

			$allowed = wp_kses_allowed_html('post');
			$allowed['iframe'] = [
				'src'=>true,'width'=>true,'height'=>true,'frameborder'=>true,
				'allow'=>true,'allowfullscreen'=>true,'title'=>true,'loading'=>true,
				'referrerpolicy'=>true,'sandbox'=>true,'style'=>true,
			];
			$allowed['figure']     = ['class'=>true,'style'=>true];
			$allowed['figcaption'] = ['class'=>true,'style'=>true];
			$allowed['div']        = ['class'=>true,'style'=>true];
			$allowed['span']       = ['class'=>true,'style'=>true];
			$allowed['video']      = [
				'controls'=>true,'width'=>true,'height'=>true,'poster'=>true,'preload'=>true,
				'loop'=>true,'muted'=>true,'playsinline'=>true,'src'=>true,'style'=>true,
			];
			$allowed['audio']  = ['controls'=>true,'src'=>true];
			$allowed['source'] = ['type'=>true,'src'=>true,'media'=>true];

			$a_safe = wp_kses($a_raw, $allowed);

			echo '<details class="mpro-faq"><summary class="mpro-faq-q">' . $q . '</summary>';
			echo '<div class="mpro-faq-a">' . $a_safe . '</div></details>';
		}
		echo '</div>';
	}

	return ob_get_clean();
}
add_shortcode('mpro_help_boxes', 'mpro_faq_portal_shortcode');


// Simple term meta UI for 'ufaq-category' to store allowed clients CSV.
add_action('ufaq-category_add_form_fields', function() {
	?>
	<div class="form-field">
		<label for="mpro_clients_allow">Allowed Clients (CSV)</label>
		<input type="text" name="mpro_clients_allow" id="mpro_clients_allow" placeholder="e.g. leap4ed,wvjc">
		<p class="description">Only users whose client_id is in this list will see this topic’s box. Leave empty to show to all.</p>
	</div>
	<?php
});

add_action('ufaq-category_edit_form_fields', function($term) {
	$val = get_term_meta($term->term_id, 'mpro_clients_allow', true);
	?>
	<tr class="form-field">
		<th scope="row"><label for="mpro_clients_allow">Allowed Clients (CSV)</label></th>
		<td>
			<input type="text" name="mpro_clients_allow" id="mpro_clients_allow" value="<?php echo esc_attr($val); ?>" class="regular-text">
			<p class="description">Comma-separated client IDs. Empty = no restriction.</p>
		</td>
	</tr>
	<?php
});

add_action('created_ufaq-category', function($term_id){
	if (isset($_POST['mpro_clients_allow'])) {
		update_term_meta($term_id, 'mpro_clients_allow', sanitize_text_field($_POST['mpro_clients_allow']));
	}
}, 10, 1);

add_action('edited_ufaq-category', function($term_id){
	if (isset($_POST['mpro_clients_allow'])) {
		update_term_meta($term_id, 'mpro_clients_allow', sanitize_text_field($_POST['mpro_clients_allow']));
	}
}, 10, 1);

 ?>