<?php
/**
 * REST endpoint used by the Quick Publish form.
 *
 * POST /wp-json/asc-qp/v1/products  → creates a draft product.
 * POST /wp-json/asc-qp/v1/generate  → AI copy for the long description (not saved).
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Rest {

	const NAMESPACE = 'asc-qp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_product' ),
				// Creating models/years means creating attribute terms, so both are required.
				'permission_callback' => static fn() => current_user_can( 'edit_products' ) && current_user_can( 'manage_product_terms' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate_copy' ),
				// Costs money per call, so only people who can edit products.
				'permission_callback' => static fn() => current_user_can( 'edit_products' ),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate_copy( WP_REST_Request $request ) {
		$result = ASC_QP_Ai::generate( (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_product( WP_REST_Request $request ) {
		$data = ASC_QP_Builder::validate( (array) $request->get_json_params() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = ASC_QP_Builder::create( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}
}
