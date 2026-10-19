<?php
/**
 * API des listes déroulantes.
 *
 *   GET /ps/v1/lists → { labels: {...}, followupTypes: {...}, lists: { urgency: [...], ... } }
 *
 * Toutes les valeurs sont renvoyées, masquées comprises (« hidden: true ») : l'interface
 * ne propose à la saisie que les valeurs visibles, mais affiche les anciennes valeurs
 * des projets existants.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_lists_routes' );
function ps_register_lists_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/lists',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn() => rest_ensure_response(
				array(
					'labels'        => ps_list_labels(),
					'followupTypes' => ps_followup_types(),
					'lists'         => ps_lists_all(),
				)
			),
		)
	);
}
