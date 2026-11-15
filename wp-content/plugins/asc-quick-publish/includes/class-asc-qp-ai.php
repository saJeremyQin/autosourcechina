<?php
/**
 * "Generate with AI": asks Claude for the marketing parts of the long description.
 *
 * Plain HTTP with wp_remote_post (no SDK). Facts (part numbers, fitment,
 * conditions) never come from the AI; it only writes the intro, key features,
 * alternate names, placement and the "sold separately" note.
 */

defined( 'ABSPATH' ) || exit;

final class ASC_QP_Ai {

	const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
	const MODEL         = 'claude-opus-5-5';
	const API_VERSION   = '2026-10-21';
	const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

	// Prices in US$ per million tokens, only used to show the cost per call.
	const PRICE_INPUT  = 4.0;
	const PRICE_OUTPUT = 20.0;

	// wp_remote_post waits 5 seconds by default, far too short for an LLM call.
	const TIMEOUT     = 45;
	const MAX_RETRIES = 2;

	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * The key comes from the server environment only: never the database, never the browser.
	 */
	private static function api_key(): string {
		return trim( (string) getenv( 'ANTHROPIC_API_KEY' ) );
	}

	/**
	 * @return array|WP_Error { intro, features[{title,text}], alternate_names[], placement, sold_separately, cost }
	 */
	public static function generate( array $in ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'asc_qp_ai_not_configured', 'The Claude API key is not set (ANTHROPIC_API_KEY on the server).', array( 'status' => 503 ) );
		}

		$facts = self::facts( $in );
		if ( is_wp_error( $facts ) ) {
			return $facts;
		}

		$json = self::request(
			array(
				'model'         => self::MODEL,
				'max_tokens'    => 4000, // Thinking counts towards this too.
				'system'        => self::system_prompt(),
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => $facts,
					),
				),
				'output_config' => array(
					'effort' => 'low',
					'format' => array(
						'type'   => 'json_schema',
						'schema' => self::schema(),
					),
				),
				// If a safety filter wrongly declines, the API retries on another model.
				'fallbacks'     => 'default',
			)
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		return self::parse( $json );
	}

	// ---------------------------------------------------------------------
	// Prompt
	// ---------------------------------------------------------------------

	private static function system_prompt(): string {
		return <<<'PROMPT'
You write product page copy for AutoSourceChina, an online store in Guangzhou that sells car body parts to customers worldwide.

Rules:
- Use only the facts in the request, plus general, well-known knowledge about this type of part (what it is, where it sits on the vehicle, its common alternative names).
- Never invent fitment, model years, trims, part numbers, sensor or option configurations, finishes, materials or what is included. If a fact is not given, leave it out.
- Some facts only apply to one condition. For example, new parts may ship primed or unpainted, while used genuine parts keep their original paint. Say which condition a fact applies to; do not present it as true for every condition.
- Refer to conditions only by the exact names given in the request.
- Do not mention prices, delivery times, warranties, returns or guarantees.
- Plain text only: no HTML, no markdown, no emoji.

Fields:
- intro: 2 sentences, 35-60 words. Mention the part, the make and the models naturally, and that it is available in the conditions given.
- features: 4 or 5 items. title: 2-4 words in Title Case, such as "Exact Factory Fitment". text: one sentence.
- alternate_names: 2-4 common alternative names for this part, not including the part name itself.
- placement: where the part sits on the vehicle, very short, such as "Rear (Upper)". Empty string if unclear.
- sold_separately: one sentence naming related parts that are not included and are usually sold separately for this kind of part. It must not contradict the "Includes" or "Extra facts" lines. Empty string if unsure.
PROMPT;
	}

	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'intro'           => array( 'type' => 'string' ),
				'features'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'title' => array( 'type' => 'string' ),
							'text'  => array( 'type' => 'string' ),
						),
						'required'             => array( 'title', 'text' ),
						'additionalProperties' => false,
					),
				),
				'alternate_names' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'placement'       => array( 'type' => 'string' ),
				'sold_separately' => array( 'type' => 'string' ),
			),
			'required'             => array( 'intro', 'features', 'alternate_names', 'placement', 'sold_separately' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Turn the form payload into the facts message. Lighter checks than creating
	 * a product: prices and categories are not needed to write copy.
	 *
	 * @return string|WP_Error
	 */
	private static function facts( array $in ) {
		$err = new WP_Error();

		$brand = get_term( absint( $in['brand_id'] ?? 0 ), ASC_QP_Config::BRAND_TAXONOMY );
		if ( ! $brand instanceof WP_Term ) {
			$err->add( 'brand', 'Choose a brand.' );
		}
		$part_name = sanitize_text_field( $in['part_name'] ?? '' );
		if ( '' === $part_name ) {
			$err->add( 'part_name', 'Enter a part name.' );
		}
		$fitment = ASC_QP_Builder::validate_fitment( is_array( $in['fitment'] ?? null ) ? $in['fitment'] : array(), $err );

		if ( $err->has_errors() ) {
			$err->add_data( array( 'status' => 400 ) );
			return $err;
		}

		$conditions = array();
		$prices     = is_array( $in['prices'] ?? null ) ? $in['prices'] : array();
		foreach ( ASC_QP_Config::conditions() as $key => $condition ) {
			if ( '' !== trim( (string) ( $prices[ $key ] ?? '' ) ) ) {
				$conditions[] = $condition['name'];
			}
		}
		if ( ! $conditions ) {
			$conditions = wp_list_pluck( ASC_QP_Config::conditions(), 'name' );
		}

		$lines   = array();
		$lines[] = 'Make: ' . $brand->name;
		$lines[] = 'Part name: ' . $part_name;
		$lines[] = 'OE number: ' . sanitize_text_field( $in['oe'] ?? '' );
		$lines[] = 'Fitment:';
		foreach ( $fitment as $row ) {
			$years   = $row['from'] === $row['to'] ? $row['from'] : $row['from'] . '–' . $row['to'];
			$lines[] = '- ' . $row['model'] . ': ' . $years;
		}
		$lines[] = 'Conditions offered: ' . implode( ', ', $conditions );

		$optional = array(
			'Includes'    => sanitize_text_field( $in['includes'] ?? '' ),
			'Extra facts' => sanitize_textarea_field( $in['extra_facts'] ?? '' ),
		);
		foreach ( $optional as $label => $value ) {
			if ( '' !== $value ) {
				$lines[] = $label . ': ' . mb_substr( $value, 0, 1000 );
			}
		}

		return implode( "\n", $lines );
	}

	// ---------------------------------------------------------------------
	// HTTP
	// ---------------------------------------------------------------------

	/**
	 * POST to the Messages API, retrying on rate limits and server errors.
	 *
	 * @return array|WP_Error Decoded response body.
	 */
	private static function request( array $body ) {
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'x-api-key'         => self::api_key(),
				'anthropic-version' => self::API_VERSION,
				'anthropic-beta'    => self::FALLBACK_BETA,
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		for ( $attempt = 0; ; $attempt++ ) {
			$response = wp_remote_post( self::ENDPOINT, $args );

			// Network problem or timeout: no HTTP status at all. Not retried,
			// a second 45-second wait would outlast the web server's timeout.
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'asc_qp_ai_network', 'Could not reach the Claude API: ' . $response->get_error_message(), array( 'status' => 502 ) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && is_array( $json ) ) {
				return $json;
			}

			$retryable = 429 === $code || $code >= 500;
			if ( ! $retryable || $attempt >= self::MAX_RETRIES ) {
				return self::http_error( $code, $json );
			}

			// Wait as long as the API asks (retry-after header), at most 10 seconds.
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			sleep( min( 10, max( 1, $wait ?: 2 ** ( $attempt + 1 ) ) ) );
		}
	}

	private static function http_error( int $code, $json ): WP_Error {
		$detail = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : 'no details';

		if ( 401 === $code || 403 === $code ) {
			$message = 'The Claude API key is invalid or has no access.';
		} elseif ( 429 === $code ) {
			$message = 'The Claude API rate limit was reached. Wait a minute and try again.';
		} elseif ( $code >= 500 ) {
			$message = 'The Claude API is busy or unavailable. Try again shortly.';
		} else {
			$message = 'The Claude API rejected the request.';
		}

		return new WP_Error( 'asc_qp_ai_http', sprintf( '%s (HTTP %d: %s)', $message, $code, $detail ), array( 'status' => 502 ) );
	}

	// ---------------------------------------------------------------------
	// Response
	// ---------------------------------------------------------------------

	/**
	 * @return array|WP_Error
	 */
	private static function parse( array $json ) {
		$stop = $json['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'asc_qp_ai_refusal', 'Claude declined to write this description. Write it by hand, or change the extra facts and try again.', array( 'status' => 502 ) );
		}
		if ( 'end_turn' !== $stop ) {
			return new WP_Error( 'asc_qp_ai_incomplete', sprintf( 'The AI answer was incomplete (stop reason: %s). Try again.', $stop ?: 'unknown' ), array( 'status' => 502 ) );
		}

		// Thinking blocks come first; the JSON is in the first text block.
		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text = (string) $block['text'];
				break;
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'asc_qp_ai_bad_json', 'The AI answer could not be read. Try again.', array( 'status' => 502 ) );
		}

		$features = array();
		foreach ( array_slice( (array) ( $data['features'] ?? array() ), 0, 6 ) as $feature ) {
			$title = sanitize_text_field( $feature['title'] ?? '' );
			$text  = sanitize_text_field( $feature['text'] ?? '' );
			if ( '' !== $title && '' !== $text ) {
				$features[] = array(
					'title' => $title,
					'text'  => $text,
				);
			}
		}

		$input_tokens  = (int) ( $json['usage']['input_tokens'] ?? 0 );
		$output_tokens = (int) ( $json['usage']['output_tokens'] ?? 0 );
		$cost          = ( $input_tokens * self::PRICE_INPUT + $output_tokens * self::PRICE_OUTPUT ) / 1000000;

		return array(
			'intro'           => sanitize_textarea_field( $data['intro'] ?? '' ),
			'features'        => $features,
			'alternate_names' => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $data['alternate_names'] ?? array() ), 0, 6 ) ) ) ),
			'placement'       => sanitize_text_field( $data['placement'] ?? '' ),
			'sold_separately' => sanitize_text_field( $data['sold_separately'] ?? '' ),
			'usage'           => array(
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'cost_usd'      => round( $cost, 4 ),
			),
		);
	}
}
