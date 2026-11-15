<?php
/**
 * Products → Quick Publish admin page.
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Admin {

	const SLUG = 'asc-quick-publish';

	private static string $hook_suffix = '';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		self::$hook_suffix = (string) add_submenu_page(
			'edit.php?post_type=product',
			'Quick Publish',
			'Quick Publish',
			'edit_products',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return; // Only load on our own page.
		}

		wp_enqueue_media();
		wp_enqueue_style( 'asc-qp-admin', ASC_QP_URL . 'assets/admin.css', array(), ASC_QP_VERSION );
		wp_enqueue_script( 'asc-qp-admin', ASC_QP_URL . 'assets/admin.js', array( 'wp-api-fetch' ), ASC_QP_VERSION, true );

		$model_taxonomy = wc_attribute_taxonomy_name( ASC_QP_Config::ATTR_MODEL );
		$models         = taxonomy_exists( $model_taxonomy )
			? get_terms(
				array(
					'taxonomy'   => $model_taxonomy,
					'hide_empty' => false,
					'fields'     => 'names',
				)
			)
			: array();

		$config = array(
			'endpoint'         => '/' . ASC_QP_Rest::NAMESPACE . '/products',
			'generateEndpoint' => '/' . ASC_QP_Rest::NAMESPACE . '/generate',
			'models'           => is_array( $models ) ? array_values( $models ) : array(),
		);
		wp_add_inline_script( 'asc-qp-admin', 'window.ascQp = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function render(): void {
		$model_taxonomy = wc_attribute_taxonomy_name( ASC_QP_Config::ATTR_MODEL );
		?>
		<div class="wrap asc-qp">
			<h1>Quick Publish</h1>
			<p class="description">Fill in the part details. A <strong>draft</strong> variable product is created with attributes, condition variations and descriptions. Review it, then publish.</p>

			<div id="asc-qp-notice" class="notice inline" hidden></div>

			<form id="asc-qp-form" novalidate>
				<h2>Part</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asc-qp-brand">Brand</label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'taxonomy'          => ASC_QP_Config::BRAND_TAXONOMY,
									'name'              => 'brand_id',
									'id'                => 'asc-qp-brand',
									'hide_empty'        => false,
									'show_option_none'  => '— Select brand —',
									'option_none_value' => '0',
									'orderby'           => 'name',
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-part-name">Part name</label></th>
						<td><input type="text" id="asc-qp-part-name" name="part_name" class="regular-text" placeholder="Rear Bumper Assembly" maxlength="100"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-part-cat">Part type category</label></th>
						<td>
							<?php self::category_dropdown( 'part_cat_id', 'asc-qp-part-cat', '— Select part type —' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-oe">OE number</label></th>
						<td><input type="text" id="asc-qp-oe" name="oe" class="regular-text code" placeholder="52159-53940" maxlength="40"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-partslink">Partslink number</label></th>
						<td>
							<input type="text" id="asc-qp-partslink" name="partslink" class="regular-text code" placeholder="LX1100168" maxlength="40">
							<p class="description">Optional.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-includes">Includes / notes</label></th>
						<td>
							<input type="text" id="asc-qp-includes" name="includes" class="large-text" maxlength="300" placeholder="Rear bumper cover shell (without rear clearance sonar / parking aid sensor cutouts)">
							<p class="description">Optional. Used in the short description and the specifications table.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-extra-facts">Extra facts for AI</label></th>
						<td>
							<textarea id="asc-qp-extra-facts" name="extra_facts" class="large-text" rows="3" maxlength="1000" placeholder="without parking sensors; new parts primed/unpainted; lower diffuser not included"></textarea>
							<p class="description">Optional. Only sent to the AI. It may only use these facts, so list anything the features should mention.</p>
						</td>
					</tr>
				</table>

				<h2>Fitment</h2>
				<p class="description">One row per model. Models are matched to existing ones (case-insensitive); a new model is created only if it does not exist yet.</p>
				<table class="widefat striped asc-qp-fitment" id="asc-qp-fitment">
					<thead>
						<tr><th>Model</th><th>Year from</th><th>Year to</th><th></th></tr>
					</thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button" id="asc-qp-add-row">+ Add model</button></p>
				<datalist id="asc-qp-models"></datalist>
				<template id="asc-qp-fitment-row">
					<tr>
						<td>
							<input type="text" class="asc-qp-model" list="asc-qp-models" placeholder="IS350" maxlength="60">
							<span class="asc-qp-new-badge" hidden>new model</span>
						</td>
						<td><input type="number" class="asc-qp-from small-text" min="1950" max="2100" placeholder="2014"></td>
						<td><input type="number" class="asc-qp-to small-text" min="1950" max="2100" placeholder="2016"></td>
						<td><button type="button" class="button-link asc-qp-remove-row" aria-label="Remove row">Remove</button></td>
					</tr>
				</template>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asc-qp-series-cat">Series category</label></th>
						<td>
							<?php self::category_dropdown( 'series_cat_id', 'asc-qp-series-cat', '— None —' ); ?>
							<p class="description">Picked automatically from brand + model (e.g. "Lexus IS"). Change it if needed.</p>
						</td>
					</tr>
				</table>

				<h2>Prices &amp; shipping</h2>
				<table class="form-table" role="presentation">
					<?php foreach ( ASC_QP_Config::conditions() as $key => $condition ) : ?>
						<tr>
							<th scope="row"><label for="asc-qp-price-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $condition['name'] ); ?> ($)</label></th>
							<td>
								<input type="text" inputmode="decimal" id="asc-qp-price-<?php echo esc_attr( $key ); ?>" name="prices[<?php echo esc_attr( $key ); ?>]" class="small-text asc-qp-price" data-condition="<?php echo esc_attr( $key ); ?>">
								<span class="description">SKU suffix -<?php echo esc_html( $condition['suffix'] ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"></th>
						<td><p class="description">Leave a price empty to skip that condition.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-weight">Weight (kg)</label></th>
						<td><input type="text" inputmode="decimal" id="asc-qp-weight" name="weight" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row">Dimensions (cm)</th>
						<td class="asc-qp-dimensions">
							<input type="text" inputmode="decimal" name="length" class="small-text" placeholder="L" aria-label="Length">
							×
							<input type="text" inputmode="decimal" name="width" class="small-text" placeholder="W" aria-label="Width">
							×
							<input type="text" inputmode="decimal" name="height" class="small-text" placeholder="H" aria-label="Height">
							<p class="description">Applied to all variations.</p>
						</td>
					</tr>
				</table>

				<h2>Images</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Main image</th>
						<td>
							<div class="asc-qp-thumbs" id="asc-qp-image-preview"></div>
							<button type="button" class="button" id="asc-qp-image-btn">Choose image</button>
						</td>
					</tr>
					<tr>
						<th scope="row">Gallery</th>
						<td>
							<div class="asc-qp-thumbs" id="asc-qp-gallery-preview"></div>
							<button type="button" class="button" id="asc-qp-gallery-btn">Choose gallery images</button>
						</td>
					</tr>
				</table>

				<h2>Description copy</h2>
				<p class="description">Optional. Fill these by hand or with AI, then edit freely. Empty fields fall back to the standard template.</p>
				<p>
					<button type="button" class="button" id="asc-qp-generate" <?php disabled( ! ASC_QP_Ai::is_configured() ); ?>>Generate with AI</button>
					<span class="spinner" id="asc-qp-generate-spinner"></span>
					<span class="description" id="asc-qp-generate-status">
						<?php echo ASC_QP_Ai::is_configured() ? '' : 'AI is off: set ANTHROPIC_API_KEY on the server to enable it.'; ?>
					</span>
				</p>
				<table class="form-table asc-qp-copy" role="presentation">
					<tr>
						<th scope="row"><label for="asc-qp-intro">Intro paragraph</label></th>
						<td><textarea id="asc-qp-intro" name="copy_intro" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-features">Key features</label></th>
						<td>
							<textarea id="asc-qp-features" name="copy_features" class="large-text" rows="6" placeholder="Exact Factory Fitment: Engineered to match the original body contours."></textarea>
							<p class="description">One per line, as <code>Title: sentence</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-alt-names">Alternate names</label></th>
						<td><input type="text" id="asc-qp-alt-names" name="copy_alternate_names" class="large-text" placeholder="Rear Bumper Cover, Rear Bumper Fascia"><p class="description">Comma-separated.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-placement">Placement on vehicle</label></th>
						<td><input type="text" id="asc-qp-placement" name="copy_placement" class="regular-text" placeholder="Rear (Upper)"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asc-qp-sold-separately">Sold separately note</label></th>
						<td><input type="text" id="asc-qp-sold-separately" name="copy_sold_separately" class="large-text" placeholder="Lower rear diffuser, reflectors and mounting brackets sold separately."></td>
					</tr>
				</table>

				<h2>Preview</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Title</th>
						<td><code id="asc-qp-title-preview">—</code></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-large" id="asc-qp-submit">Create draft</button>
					<button type="button" class="button" id="asc-qp-reset">Clear form</button>
					<span class="spinner"></span>
				</p>
			</form>

			<?php if ( ! taxonomy_exists( $model_taxonomy ) ) : ?>
				<div class="notice notice-warning inline"><p>The global attribute <code><?php echo esc_html( $model_taxonomy ); ?></code> is missing. Deactivate and reactivate ASC Quick Publish to create it.</p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function category_dropdown( string $name, string $id, string $none_label ): void {
		wp_dropdown_categories(
			array(
				'taxonomy'          => 'product_cat',
				'name'              => $name,
				'id'                => $id,
				'hide_empty'        => false,
				'hierarchical'      => true,
				'show_option_none'  => $none_label,
				'option_none_value' => '0',
				'orderby'           => 'name',
			)
		);
	}
}
