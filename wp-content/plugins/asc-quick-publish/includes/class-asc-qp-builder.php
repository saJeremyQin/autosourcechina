<?php
/**
 * Validates the Quick Publish payload and builds the draft variable product.
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Builder {

	const PART_NUMBER_PATTERN = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

	/**
	 * Validate and normalise the raw form payload.
	 *
	 * @return array|WP_Error Normalised data, or every problem found.
	 */
	public static function validate( array $in ) {
		$err = new WP_Error();

		$brand = get_term( absint( $in['brand_id'] ?? 0 ), ASC_QP_Config::BRAND_TAXONOMY );
		if ( ! $brand instanceof WP_Term ) {
			$err->add( 'brand', 'Choose a brand.' );
		}

		$part_cat_id = absint( $in['part_cat_id'] ?? 0 );
		if ( ! get_term( $part_cat_id, 'product_cat' ) instanceof WP_Term ) {
			$err->add( 'part_cat', 'Choose a part type category.' );
		}

		$series_cat_id = absint( $in['series_cat_id'] ?? 0 );
		if ( $series_cat_id && ! get_term( $series_cat_id, 'product_cat' ) instanceof WP_Term ) {
			$err->add( 'series_cat', 'The series category no longer exists.' );
		}

		$part_name = sanitize_text_field( $in['part_name'] ?? '' );
		if ( '' === $part_name || mb_strlen( $part_name ) > 100 ) {
			$err->add( 'part_name', 'Enter a part name (up to 100 characters).' );
		}

		$oe = trim( sanitize_text_field( $in['oe'] ?? '' ) );
		if ( ! preg_match( self::PART_NUMBER_PATTERN, $oe ) || strlen( $oe ) > 40 ) {
			$err->add( 'oe', 'Enter the OE number using letters, digits and dashes only, e.g. 52159-53940.' );
		}

		$partslink = trim( sanitize_text_field( $in['partslink'] ?? '' ) );
		if ( '' !== $partslink && ( ! preg_match( self::PART_NUMBER_PATTERN, $partslink ) || strlen( $partslink ) > 40 ) ) {
			$err->add( 'partslink', 'The Partslink number may contain letters, digits and dashes only.' );
		}

		$includes = sanitize_text_field( $in['includes'] ?? '' );
		if ( mb_strlen( $includes ) > 300 ) {
			$err->add( 'includes', '"Includes / notes" must be 300 characters or fewer.' );
		}

		$fitment = self::validate_fitment( is_array( $in['fitment'] ?? null ) ? $in['fitment'] : array(), $err );
		$prices  = self::validate_prices( is_array( $in['prices'] ?? null ) ? $in['prices'] : array(), $err );

		$dimensions = array();
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $field ) {
			$raw = trim( (string) ( $in[ $field ] ?? '' ) );
			if ( '' === $raw ) {
				$dimensions[ $field ] = '';
				continue;
			}
			$value = wc_format_decimal( $raw );
			if ( ! is_numeric( $value ) || (float) $value < 0 ) {
				$err->add( $field, ucfirst( $field ) . ' must be a positive number.' );
				continue;
			}
			$dimensions[ $field ] = $value;
		}

		$image_id = absint( $in['image_id'] ?? 0 );
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			$err->add( 'image', 'The main image is not a valid image.' );
		}
		$gallery_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', is_array( $in['gallery_ids'] ?? null ) ? $in['gallery_ids'] : array() ),
					static fn( $id ) => $id && $id !== $image_id && wp_attachment_is_image( $id )
				)
			)
		);

		if ( $err->has_errors() ) {
			$err->add_data( array( 'status' => 400 ) );
			return $err;
		}

		return array(
			'brand'         => $brand,
			'part_cat_id'   => $part_cat_id,
			'series_cat_id' => $series_cat_id,
			'part_name'     => $part_name,
			'oe'            => $oe,
			'partslink'     => $partslink,
			'includes'      => $includes,
			'fitment'       => $fitment,
			'prices'        => $prices,
			'dimensions'    => $dimensions,
			'image_id'      => $image_id,
			'gallery_ids'   => $gallery_ids,
			'copy'          => self::validate_copy( is_array( $in['copy'] ?? null ) ? $in['copy'] : array() ),
		);
	}

	/**
	 * The optional AI copy, as reviewed and edited in the form. All empty means
	 * the plain template is used.
	 */
	private static function validate_copy( array $in ): array {
		$features = array();
		foreach ( array_slice( is_array( $in['features'] ?? null ) ? $in['features'] : array(), 0, 8 ) as $feature ) {
			$title = is_array( $feature ) ? sanitize_text_field( $feature['title'] ?? '' ) : '';
			$text  = is_array( $feature ) ? sanitize_text_field( $feature['text'] ?? '' ) : '';
			if ( '' !== $title || '' !== $text ) {
				$features[] = array(
					'title' => $title,
					'text'  => $text,
				);
			}
		}

		$names = is_array( $in['alternate_names'] ?? null ) ? $in['alternate_names'] : array();

		return array(
			'intro'           => mb_substr( sanitize_textarea_field( $in['intro'] ?? '' ), 0, 1000 ),
			'features'        => $features,
			'alternate_names' => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( $names, 0, 8 ) ) ) ),
			'placement'       => mb_substr( sanitize_text_field( $in['placement'] ?? '' ), 0, 60 ),
			'sold_separately' => mb_substr( sanitize_text_field( $in['sold_separately'] ?? '' ), 0, 300 ),
		);
	}

	public static function validate_fitment( array $rows, WP_Error $err ): array {
		$max_year = (int) gmdate( 'Y' ) + 2;
		$fitment  = array();
		$invalid  = false;

		foreach ( array_values( $rows ) as $i => $row ) {
			$row   = is_array( $row ) ? $row : array();
			$n     = $i + 1;
			$model = sanitize_text_field( $row['model'] ?? '' );
			$from  = absint( $row['from'] ?? 0 );
			$to    = '' === trim( (string) ( $row['to'] ?? '' ) ) ? $from : absint( $row['to'] );

			if ( '' === $model && ! $from ) {
				continue; // Empty row left in the form.
			}
			$invalid = true;
			if ( '' === $model ) {
				$err->add( "fitment_$n", "Fitment row $n: enter a model." );
				continue;
			}
			if ( $from < 1950 || $from > $max_year || $to < $from || $to > $max_year ) {
				$err->add( "fitment_$n", "Fitment row $n ($model): years must be between 1950 and $max_year, and \"to\" cannot be before \"from\"." );
				continue;
			}
			$key = strtolower( $model );
			if ( isset( $fitment[ $key ] ) ) {
				$err->add( "fitment_$n", "$model is listed twice. Use one row with a year range." );
				continue;
			}
			$invalid         = false;
			$fitment[ $key ] = array(
				'model' => $model,
				'from'  => $from,
				'to'    => $to,
			);
		}

		if ( ! $fitment && ! $invalid ) {
			$err->add( 'fitment', 'Add at least one fitment row (model and years).' );
		}
		return array_values( $fitment );
	}

	private static function validate_prices( array $raw_prices, WP_Error $err ): array {
		$prices  = array();
		$invalid = false;
		foreach ( ASC_QP_Config::conditions() as $key => $condition ) {
			$raw = trim( (string) ( $raw_prices[ $key ] ?? '' ) );
			if ( '' === $raw ) {
				continue; // Condition not offered for this part.
			}
			$price = wc_format_decimal( $raw );
			if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
				$err->add( "price_$key", "The {$condition['name']} price must be a number above 0." );
				$invalid = true;
				continue;
			}
			$prices[ $key ] = $price;
		}
		if ( ! $prices && ! $invalid ) {
			$err->add( 'prices', 'Enter a price for at least one condition.' );
		}
		return $prices;
	}

	/**
	 * Create the draft product from validated data.
	 *
	 * @return array|WP_Error { id, title, edit_url } on success.
	 */
	public static function create( array $d ) {
		$conditions = ASC_QP_Config::conditions();

		// Refuse duplicates before touching anything.
		$skus = array( $d['oe'] );
		foreach ( array_keys( $d['prices'] ) as $key ) {
			$skus[] = $d['oe'] . '-' . $conditions[ $key ]['suffix'];
		}
		foreach ( $skus as $sku ) {
			$existing = wc_get_product_id_by_sku( $sku );
			if ( $existing ) {
				$parent = wp_get_post_parent_id( $existing ) ?: $existing;
				return new WP_Error(
					'asc_qp_duplicate',
					sprintf( 'SKU %s already exists (product #%d, "%s").', $sku, $parent, get_the_title( $parent ) ),
					array(
						'status'   => 409,
						'edit_url' => get_edit_post_link( $parent, 'raw' ),
					)
				);
			}
		}

		// Resolve (or create) attribute terms. Canonical term names replace what was
		// typed, so "is350" becomes the existing "IS350".
		$make = self::attribute_term( ASC_QP_Config::ATTR_MAKE, $d['brand']->name );
		if ( is_wp_error( $make ) ) {
			return $make;
		}

		$fitment   = $d['fitment'];
		$model_ids = array();
		foreach ( $fitment as &$row ) {
			$term = self::attribute_term( ASC_QP_Config::ATTR_MODEL, $row['model'] );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$row['model'] = $term->name;
			$model_ids[]  = $term->term_id;
		}
		unset( $row );
		usort( $fitment, static fn( $a, $b ) => strnatcasecmp( $a['model'], $b['model'] ) );

		$years = array();
		foreach ( $fitment as $row ) {
			$years = array_merge( $years, range( $row['from'], $row['to'] ) );
		}
		$years = array_values( array_unique( $years ) );
		sort( $years );
		$year_ids = array();
		foreach ( $years as $year ) {
			$term = self::attribute_term( ASC_QP_Config::ATTR_YEAR, (string) $year );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$year_ids[] = $term->term_id;
		}

		$condition_terms = array();
		foreach ( array_keys( $d['prices'] ) as $key ) {
			$term = self::attribute_term( ASC_QP_Config::ATTR_CONDITION, $conditions[ $key ]['name'], $conditions[ $key ]['slug'] );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$condition_terms[ $key ] = $term;
		}

		$d['make']    = $d['brand']->name;
		$d['fitment'] = $fitment;
		$d['years']   = $years;
		$title        = self::title( $d );

		$product = new WC_Product_Variable();
		try {
			$product->set_name( $title );
			$product->set_status( 'draft' );
			$product->set_sku( $d['oe'] );
			$product->set_short_description( self::short_description( $d ) );
			$product->set_description( self::long_description( $d ) );
			$product->set_category_ids( array_values( array_unique( array_filter( array( $d['part_cat_id'], $d['series_cat_id'] ) ) ) ) );
			$product->set_weight( $d['dimensions']['weight'] );
			$product->set_length( $d['dimensions']['length'] );
			$product->set_width( $d['dimensions']['width'] );
			$product->set_height( $d['dimensions']['height'] );
			$product->set_image_id( $d['image_id'] );
			$product->set_gallery_image_ids( $d['gallery_ids'] );

			$product->set_attributes(
				array(
					self::taxonomy_attribute( ASC_QP_Config::ATTR_MAKE, array( $make->term_id ), 0 ),
					self::taxonomy_attribute( ASC_QP_Config::ATTR_MODEL, $model_ids, 1 ),
					self::taxonomy_attribute( ASC_QP_Config::ATTR_YEAR, $year_ids, 2 ),
					self::taxonomy_attribute( ASC_QP_Config::ATTR_CONDITION, wp_list_pluck( $condition_terms, 'term_id' ), 3, true ),
					self::local_attribute( ASC_QP_Config::ATTR_OE_NAME, array( $d['oe'] ), 4 ),
				)
			);

			$default_key = isset( $condition_terms[ ASC_QP_Config::DEFAULT_CONDITION ] ) ? ASC_QP_Config::DEFAULT_CONDITION : array_key_first( $condition_terms );
			$product->set_default_attributes(
				array( wc_attribute_taxonomy_name( ASC_QP_Config::ATTR_CONDITION ) => $condition_terms[ $default_key ]->slug )
			);

			$product_id = $product->save();
			wp_set_object_terms( $product_id, array( $d['brand']->term_id ), ASC_QP_Config::BRAND_TAXONOMY );

			$menu_order = 0;
			foreach ( $condition_terms as $key => $term ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( array( wc_attribute_taxonomy_name( ASC_QP_Config::ATTR_CONDITION ) => $term->slug ) );
				$variation->set_sku( $d['oe'] . '-' . $conditions[ $key ]['suffix'] );
				$variation->set_regular_price( $d['prices'][ $key ] );
				$variation->set_stock_status( 'instock' );
				$variation->set_menu_order( $menu_order++ );
				$variation->save(); // Weight and dimensions are inherited from the parent.
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
		} catch ( Exception $e ) {
			self::discard( $product );
			return new WP_Error( 'asc_qp_create_failed', 'Could not create the product: ' . $e->getMessage(), array( 'status' => 500 ) );
		}

		$warnings = ASC_QP_Seo::apply( $product_id, $d );

		/**
		 * Fires after Quick Publish has created a draft product.
		 *
		 * @param int   $product_id New product ID.
		 * @param array $d          Validated form data (with resolved make, fitment and years).
		 */
		do_action( 'asc_qp_product_created', $product_id, $d );

		return array(
			'id'       => $product_id,
			'title'    => $title,
			'edit_url' => get_edit_post_link( $product_id, 'raw' ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Remove a half-built product (and its variations) after a failure.
	 */
	private static function discard( WC_Product $product ): void {
		$id = $product->get_id();
		if ( ! $id ) {
			return;
		}
		WC_Data_Store::load( 'product-variable' )->delete_variations( $id, true );
		$product->delete( true );
	}

	/**
	 * Find a term of a global attribute by slug (if given) or name, creating it if missing.
	 *
	 * @return WP_Term|WP_Error
	 */
	private static function attribute_term( string $attribute, string $name, string $slug = '' ) {
		$taxonomy = wc_attribute_taxonomy_name( $attribute );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'asc_qp_missing_attribute',
				sprintf( 'The global attribute "%s" does not exist. Deactivate and reactivate ASC Quick Publish to create it.', $taxonomy ),
				array( 'status' => 500 )
			);
		}

		$term = $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;
		if ( ! $term ) {
			$term = get_term_by( 'name', $name, $taxonomy );
		}
		if ( $term instanceof WP_Term ) {
			return $term;
		}

		$created = wp_insert_term( $name, $taxonomy, $slug ? array( 'slug' => $slug ) : array() );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				return get_term( (int) $created->get_error_data(), $taxonomy );
			}
			return $created;
		}
		return get_term( $created['term_id'], $taxonomy );
	}

	private static function taxonomy_attribute( string $attribute, array $term_ids, int $position, bool $variation = false ): WC_Product_Attribute {
		$attr = new WC_Product_Attribute();
		$attr->set_id( wc_attribute_taxonomy_id_by_name( $attribute ) );
		$attr->set_name( wc_attribute_taxonomy_name( $attribute ) );
		$attr->set_options( array_values( array_unique( array_map( 'intval', $term_ids ) ) ) );
		$attr->set_position( $position );
		$attr->set_visible( true );
		$attr->set_variation( $variation );
		return $attr;
	}

	private static function local_attribute( string $name, array $values, int $position ): WC_Product_Attribute {
		$attr = new WC_Product_Attribute();
		$attr->set_id( 0 );
		$attr->set_name( $name );
		$attr->set_options( $values );
		$attr->set_position( $position );
		$attr->set_visible( true );
		$attr->set_variation( false );
		return $attr;
	}

	// ---------------------------------------------------------------------
	// Text templates. In step 3 the AI can replace the long description.
	// ---------------------------------------------------------------------

	private static function models( array $d ): array {
		return wp_list_pluck( $d['fitment'], 'model' );
	}

	private static function year_range( array $d, string $dash ): string {
		$min = reset( $d['years'] );
		$max = end( $d['years'] );
		return $min === $max ? (string) $min : $min . $dash . $max;
	}

	/**
	 * "a", "a & b", "a, b & c".
	 */
	private static function join_human( array $items, string $last = ' & ' ): string {
		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}
		$tail = array_pop( $items );
		return implode( ', ', $items ) . $last . $tail;
	}

	private static function condition_labels( array $d ): array {
		$conditions = ASC_QP_Config::conditions();
		return array_map( static fn( $key ) => $conditions[ $key ]['name'], array_keys( $d['prices'] ) );
	}

	/**
	 * e.g. "Lexus IS200t IS250 IS300 IS350 Rear Bumper Assembly 2014-2016 OE 52159-53940".
	 * The last model sits next to the part name, so "IS350 Rear Bumper Assembly"
	 * is always an exact phrase in the title (used as the focus keyword).
	 */
	public static function title( array $d ): string {
		return sprintf(
			'%s %s %s %s OE %s',
			$d['make'],
			implode( ' ', self::models( $d ) ),
			$d['part_name'],
			self::year_range( $d, '-' ),
			$d['oe']
		);
	}

	private static function short_description( array $d ): string {
		$lines   = array();
		$lines[] = sprintf(
			'<strong>Direct Replacement Options:</strong> Fits %s %s (%s) models (%s).',
			esc_html( $d['make'] ),
			esc_html( self::join_human( self::models( $d ) ) ),
			esc_html( self::year_range( $d, '–' ) ),
			esc_html( $d['part_name'] )
		);

		$oe_line = '<strong>OE Part Number:</strong> ' . esc_html( $d['oe'] );
		if ( $d['partslink'] ) {
			$oe_line .= ' (Partslink: ' . esc_html( $d['partslink'] ) . ')';
		}
		$lines[] = $oe_line;

		$available = 'Available in ' . self::join_human( self::condition_labels( $d ), ' and ' ) . ' options.';
		$lines[]   = $d['includes']
			? '<strong>Includes:</strong> ' . esc_html( rtrim( $d['includes'], '. ' ) . '. ' . $available )
			: '<strong>Options:</strong> ' . esc_html( $available );

		$lines[] = '<strong>Global Shipping:</strong> ' . esc_html( ASC_QP_Config::SHIPPING_LINE );

		return '<p>' . implode( "</p>\n<p>", $lines ) . '</p>';
	}

	private static function long_description( array $d ): string {
		$models     = self::models( $d );
		$oe_compact = str_replace( '-', '', $d['oe'] );

		// The heading joins models with " / ", so it still contains the focus
		// keyword (last model + part name) at the start of the content.
		$html  = sprintf(
			"<h2>%s %s %s (OE %s)</h2>\n",
			esc_html( $d['make'] ),
			esc_html( implode( ' / ', $models ) ),
			esc_html( $d['part_name'] ),
			esc_html( $d['oe'] )
		);
		$copy = $d['copy'];

		if ( '' !== $copy['intro'] ) {
			$html .= '<p>' . esc_html( $copy['intro'] ) . "</p>\n";
		} else {
			$html .= sprintf(
				"<p>Replacement %s for %s %s (%s). Available in %s options.</p>\n",
				esc_html( strtolower( $d['part_name'] ) ),
				esc_html( $d['make'] ),
				esc_html( self::join_human( $models, ' and ' ) ),
				esc_html( self::year_range( $d, '–' ) ),
				esc_html( self::join_human( self::condition_labels( $d ), ' and ' ) )
			);
		}

		if ( $copy['features'] ) {
			$html .= "<h2>Key Features &amp; Highlights</h2>\n<ul>\n";
			foreach ( $copy['features'] as $feature ) {
				$html .= '' !== $feature['title']
					? sprintf( "<li><strong>%s:</strong> %s</li>\n", esc_html( $feature['title'] ), esc_html( $feature['text'] ) )
					: sprintf( "<li>%s</li>\n", esc_html( $feature['text'] ) );
			}
			$html .= "</ul>\n";
		}

		$specs = array(
			'Part Number'                    => $oe_compact !== $d['oe'] ? $d['oe'] . ' / ' . $oe_compact : $d['oe'],
			'Partslink / Alternate Number'   => $d['partslink'],
			'Alternate / Superseded Names'   => implode( ', ', $copy['alternate_names'] ),
			'Available Conditions'           => implode( ' / ', self::condition_labels( $d ) ),
			'Placement on Vehicle'           => $copy['placement'],
			'Options &amp; Notes'            => $d['includes'],
		);
		$html .= "<h2>Product Specifications</h2>\n<table>\n<tbody>\n";
		foreach ( $specs as $label => $value ) {
			if ( '' !== $value ) {
				$html .= sprintf( "<tr><th>%s</th><td>%s</td></tr>\n", $label, esc_html( $value ) );
			}
		}
		$html .= "</tbody>\n</table>\n";

		$html .= "<h2>Vehicle Compatibility</h2>\n<ul>\n";
		foreach ( $d['fitment'] as $row ) {
			$years = range( $row['from'], $row['to'] );
			$html .= sprintf(
				"<li>%s %s – Fits Model Year%s %s</li>\n",
				esc_html( $d['make'] ),
				esc_html( $row['model'] ),
				count( $years ) > 1 ? 's' : '',
				esc_html( implode( ', ', $years ) )
			);
		}
		$html .= "</ul>\n";
		$sold_separately = '' !== $copy['sold_separately'] ? rtrim( $copy['sold_separately'], '. ' ) . '. ' : '';
		$html           .= '<p>(Note: ' . esc_html( $sold_separately ) . "Always verify your vehicle's VIN prior to purchase to confirm exact trim and option package fitment.)</p>";

		return $html;
	}
}
