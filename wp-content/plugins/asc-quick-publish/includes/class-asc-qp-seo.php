<?php
/**
 * Rank Math fields and image alt text for a new Quick Publish product.
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Seo {

	/** Google shows roughly this many characters of a meta description. */
	const DESCRIPTION_MAX = 160;

	/**
	 * Save the Rank Math fields and fill empty image alt texts.
	 *
	 * @param int   $product_id New product ID.
	 * @param array $d          Data from ASC_QP_Builder::create() (resolved make, fitment, years).
	 * @return string[] Warnings to show the user (nothing is blocked).
	 */
	public static function apply( int $product_id, array $d ): array {
		$warnings    = array();
		$description = self::description( $d );

		// Rank Math reads these post meta keys. They are saved even if Rank Math
		// is inactive, and picked up once it is active.
		update_post_meta( $product_id, 'rank_math_title', ASC_QP_Builder::title( $d ) );
		update_post_meta( $product_id, 'rank_math_description', $description );
		update_post_meta( $product_id, 'rank_math_focus_keyword', self::focus_keyword( $d ) );

		$length = mb_strlen( $description );
		if ( $length > self::DESCRIPTION_MAX ) {
			$warnings[] = sprintf(
				'The SEO meta description is %d characters (Google shows about %d). Consider shortening it in Rank Math before publishing.',
				$length,
				self::DESCRIPTION_MAX
			);
		}

		self::fill_alt_texts( $d );

		return $warnings;
	}

	/**
	 * e.g. "IS350 Rear Bumper Assembly": last model + part name, an exact phrase in the title.
	 */
	public static function focus_keyword( array $d ): string {
		return self::last_model( $d ) . ' ' . $d['part_name'];
	}

	/**
	 * e.g. "IS350 rear bumper assembly OE 52159-53940 also fits IS200t, IS250, IS300 2014–2016.
	 * Inspected before shipping worldwide from Guangzhou."
	 */
	public static function description( array $d ): string {
		$models = wp_list_pluck( $d['fitment'], 'model' );
		$last   = array_pop( $models );
		$fits   = $models ? 'also fits ' . implode( ', ', $models ) : 'fits';

		return sprintf(
			'%s %s OE %s %s %s. Inspected before shipping worldwide from Guangzhou.',
			$last,
			mb_strtolower( $d['part_name'] ),
			$d['oe'],
			$fits,
			self::year_range( $d )
		);
	}

	/**
	 * Main image: "Lexus IS350 rear bumper assembly 5215953940".
	 * Gallery images: the same with " 2", " 3", … so every alt text is unique.
	 * Only empty alt texts are filled; anything written by hand is kept.
	 */
	private static function fill_alt_texts( array $d ): void {
		$base = sprintf(
			'%s %s %s %s',
			$d['make'],
			self::last_model( $d ),
			mb_strtolower( $d['part_name'] ),
			str_replace( '-', '', $d['oe'] )
		);

		$images = array_merge( $d['image_id'] ? array( $d['image_id'] ) : array(), $d['gallery_ids'] );
		foreach ( $images as $i => $attachment_id ) {
			$alt = 0 === $i ? $base : $base . ' ' . ( $i + 1 );

			if ( '' === trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}

	private static function last_model( array $d ): string {
		$last = end( $d['fitment'] );
		return $last['model'];
	}

	private static function year_range( array $d ): string {
		$min = reset( $d['years'] );
		$max = end( $d['years'] );
		return $min === $max ? (string) $min : $min . '–' . $max;
	}
}
