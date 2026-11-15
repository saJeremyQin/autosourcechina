<?php
/**
 * Store conventions in one place: attribute slugs, conditions, fixed wording.
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Config {

	// Global attribute slugs, as used on autosourcechina.com (taxonomy = "pa_" + slug).
	const ATTR_MAKE      = 'make';
	const ATTR_MODEL     = 'model';
	const ATTR_YEAR      = 'vehicle-year';
	const ATTR_CONDITION = 'part-condition';

	// Per-product (local) attribute.
	const ATTR_OE_NAME = 'OE Number';

	const BRAND_TAXONOMY = 'product_brand';

	const DEFAULT_CONDITION = 'ug';

	const SHIPPING_LINE = 'Warehoused in Guangzhou — fast worldwide freight dispatch (shipping rates quoted per order).';

	/**
	 * Global attributes the plugin needs: slug => label.
	 */
	public static function attributes(): array {
		return array(
			self::ATTR_MAKE      => 'Make',
			self::ATTR_MODEL     => 'Model',
			self::ATTR_YEAR      => 'Year',
			self::ATTR_CONDITION => 'Part Condition',
		);
	}

	/**
	 * Variation conditions, in the order the variations are created.
	 * slug/name: the Part Condition term, also the wording used in descriptions.
	 * suffix: appended to the OE number for the variation SKU.
	 */
	public static function conditions(): array {
		return array(
			'ug' => array(
				'slug'   => 'used-genuine',
				'name'   => 'Used Genuine',
				'suffix' => 'UG',
			),
			'ng' => array(
				'slug'   => 'new-genuine',
				'name'   => 'New Genuine',
				'suffix' => 'NG',
			),
			'am' => array(
				'slug'   => 'aftermarket',
				'name'   => 'Aftermarket',
				'suffix' => 'AM',
			),
		);
	}

	public static function ensure_attributes(): void {
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return;
		}
		foreach ( self::attributes() as $slug => $label ) {
			if ( wc_attribute_taxonomy_id_by_name( $slug ) ) {
				continue;
			}
			wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
		}
	}
}
