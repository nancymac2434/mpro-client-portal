<?php

/**
 * Return clients for the select as [ [client_id, "Title (client_id)"], ... ].
 * Only include posts that actually have a client_id value.
 */

add_action( 'rest_api_init', function () {

	register_rest_route( 'mpro-client-portal/v1', '/boxes', [
		[
			'methods'  => 'GET',
			'callback' => function ( WP_REST_Request $req ) {
				if ( ! is_user_logged_in() || ! current_user_can( mpro_client_portal_manage_capability() ) ) {
					return new WP_Error( 'forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
				}
			
				// Get & normalize
				$boxes = array_values( mpro_get_saved_boxes() );
				foreach ($boxes as $i => &$b) {
					// cast to int; missing order goes to the end
					$b['order'] = isset($b['order']) && $b['order'] !== '' ? (int)$b['order'] : PHP_INT_MAX;
					$b['title'] = (string)($b['title'] ?? '');
				}
				unset($b);
			
				// Sort by order asc, tie-break by title (case-insensitive)
				usort($boxes, function($a, $b){
					if ($a['order'] === $b['order']) {
						return strcasecmp($a['title'], $b['title']);
					}
					return $a['order'] <=> $b['order'];
				});
			
				return [
					'ok' => true,
					'boxes'   => $boxes, // <-- sorted here
					'clients' => mpro_get_clients_for_select(),
					'roles'   => [ 'administrator', 'contract', 'group_leader', 'mentee' ],
				];
			},

			'permission_callback' => '__return_true',
		],
		[
			'methods'  => 'POST',
			'callback' => function ( WP_REST_Request $req ) {
				if ( ! is_user_logged_in() || ! current_user_can( mpro_client_portal_manage_capability() ) ) {
					return new WP_Error( 'forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
				}

				$payload = $req->get_json_params();
				if ( ! is_array( $payload ) ) $payload = [];
				
				$valid_ids = mpro_get_valid_client_ids_set();

				// basic sanitize
				$box = [
					'id'          => sanitize_text_field( $payload['id'] ?? wp_generate_uuid4() ),
					'image'       => mpro_sanitize_url_allow_relative( $payload['image'] ?? '' ),
					'link'        => mpro_sanitize_url_allow_relative( $payload['link'] ?? '' ),
					'title'       => sanitize_text_field( $payload['title'] ?? '' ),
					'description' => wp_kses_post( $payload['description'] ?? '' ),
					//'collections' => array_values( array_filter( array_unique( array_map( 'sanitize_title', (array) ( $payload['collections'] ?? [] ) ) ) ) ),
					'collections' => array_values( array_filter( array_unique( array_map(
						function($v){ return sanitize_title((string)$v); },
						(array)($payload['collections'] ?? [])
					) ) ) ),

					'order' => isset($payload['order']) ? intval($payload['order']) : null,
					'roles'       => array_values( array_intersect(
						(array) ( $payload['roles'] ?? [] ),
						[ 'administrator', 'contract', 'group_leader', 'mentee' ]
					) ),
					'clients' => array_values( array_filter( array_unique( array_map( function( $v ) use ( $valid_ids ) {
						$v = sanitize_text_field( (string) $v );
						return $v !== '' && isset( $valid_ids[ strtolower($v) ] ) ? $v : '';
					}, (array) ( $payload['clients'] ?? [] ) ) ) ) ),
				];

				$boxes = mpro_get_saved_boxes();

				// upsert by id
				$found = false;
				foreach ( $boxes as $i => $b ) {
					if ( ! empty( $b['id'] ) && $b['id'] === $box['id'] ) {
						$boxes[$i] = $box;
						$found = true;
						break;
					}
				}
				if ( ! $found ) { $boxes[] = $box; }

				mpro_save_boxes( $boxes );
				
				// Re-fetch, normalize, sort (same rules as GET)
				$boxes = array_values( mpro_get_saved_boxes() );
				foreach ($boxes as $i => &$b) {
					$b['order'] = isset($b['order']) && $b['order'] !== '' ? (int)$b['order'] : PHP_INT_MAX;
					$b['title'] = (string)($b['title'] ?? '');
				}
				unset($b);
				usort($boxes, function($a, $b){
					if ($a['order'] === $b['order']) {
						return strcasecmp($a['title'], $b['title']);
					}
					return $a['order'] <=> $b['order'];
				});
				return [ 'ok' => true, 'boxes' => $boxes ];
			},
			'permission_callback' => function () {
				return wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'wp_rest' );
			},
		],
	] );

	register_rest_route( 'mpro-client-portal/v1', '/boxes/(?P<id>[^/]+)', [
		'methods'  => 'DELETE',
		'callback' => function ( WP_REST_Request $req ) {
			if ( ! is_user_logged_in() || ! current_user_can( mpro_client_portal_manage_capability() ) ) {
				return new WP_Error( 'forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
			}
			$id = sanitize_text_field( $req['id'] ?? '' );
			$boxes = mpro_get_saved_boxes();
			$boxes = array_values( array_filter( $boxes, fn($b) => ( $b['id'] ?? '' ) !== $id ) );
			mpro_save_boxes( $boxes );
			
			$boxes = array_values( mpro_get_saved_boxes() );
			foreach ($boxes as $i => &$b) {
				$b['order'] = isset($b['order']) && $b['order'] !== '' ? (int)$b['order'] : PHP_INT_MAX;
				$b['title'] = (string)($b['title'] ?? '');
			}
			unset($b);
			usort($boxes, function($a, $b){
				if ($a['order'] === $b['order']) {
					return strcasecmp($a['title'], $b['title']);
				}
				return $a['order'] <=> $b['order'];
			});
			
			return [ 'ok' => true, 'boxes' => $boxes ];
		},
		'permission_callback' => function () {
			return wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'wp_rest' );
		},
	] );


	register_rest_route('mpro-client-portal/v1', '/boxes/reorder', [
    	'methods'  => 'POST',
  	  	'callback' => function ( WP_REST_Request $req ) {
	  	  if ( ! is_user_logged_in() || ! current_user_can( mpro_client_portal_manage_capability() ) ) {
		  return new WP_Error('forbidden', 'Insufficient permissions', ['status' => 403]);
	  }
	  $body = $req->get_json_params();
	  $pairs = is_array($body['order'] ?? null) ? $body['order'] : [];

	  // Expect: [{id: 'uuid', order: 0}, ...]
	  $map = [];
	  foreach ($pairs as $p) {
		  if (!is_array($p)) continue;
		  $id = sanitize_text_field($p['id'] ?? '');
		  $ord = intval($p['order'] ?? 0);
		  if ($id !== '') $map[$id] = $ord;
	  }
	  if (empty($map)) {
		  return new WP_Error('bad_request', 'No order payload.', ['status' => 400]);
	  }

	  $boxes = mpro_get_saved_boxes();
	  $boxes = mpro_sort_boxes_by_order_then_title( $boxes );
	  $changed = false;
	  foreach ($boxes as &$b) {
		  if (!empty($b['id']) && array_key_exists($b['id'], $map)) {
			  $new = $map[$b['id']];
			  $old = isset($b['order']) ? intval($b['order']) : null;
			  if ($old !== $new) {
				  $b['order'] = $new;
				  $changed = true;
			  }
		  }
	  }
	  if ($changed) {
		  mpro_save_boxes($boxes);
	  }
	  return ['ok' => true];
  },
  'permission_callback' => function () {
	  return wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'wp_rest' );
  },
]);

});



