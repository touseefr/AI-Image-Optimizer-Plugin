<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates SEO-friendly alt text for images using an AI vision model
 * (OpenAI's gpt-4o family by default). Only fills in alt text that is
 * currently empty, so it never overwrites text an editor wrote by hand
 * unless explicitly forced.
 */
class AIIO_Alt_Text_Generator {

	private $settings;

	public function __construct( AIIO_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Generate + save alt text for an attachment, but only if it
	 * doesn't already have one.
	 *
	 * @param int  $attachment_id
	 * @param bool $force Overwrite existing alt text if true.
	 * @return string|WP_Error The generated alt text, or WP_Error on failure.
	 */
	public function maybe_generate_alt_text( $attachment_id, $force = false ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( ! $force && ! empty( trim( (string) $existing ) ) ) {
			return $existing; // Don't clobber human-written alt text.
		}

		$alt_text = $this->generate_alt_text( $attachment_id );

		if ( is_wp_error( $alt_text ) ) {
			update_post_meta( $attachment_id, '_aiio_alt_text_error', $alt_text->get_error_message() );
			return $alt_text;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		delete_post_meta( $attachment_id, '_aiio_alt_text_error' );

		return $alt_text;
	}

	/**
	 * Call the AI provider to generate alt text for the given attachment.
	 *
	 * @param int $attachment_id
	 * @return string|WP_Error
	 */
	public function generate_alt_text( $attachment_id ) {
		$api_key = $this->settings->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'aiio_no_api_key', __( 'No OpenAI API key configured. Add one under Media → Image Optimizer.', 'ai-image-optimizer' ) );
		}

		$image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
		if ( ! $image_url ) {
			$image_url = wp_get_attachment_url( $attachment_id );
		}
		if ( ! $image_url ) {
			return new WP_Error( 'aiio_no_url', __( 'Could not determine a public URL for this image.', 'ai-image-optimizer' ) );
		}

		// Local/dev sites (localhost, .local, .test) aren't reachable by OpenAI's servers,
		// so fall back to sending the image as base64 data instead of a URL.
		$image_payload = $this->is_publicly_reachable( $image_url )
			? array( 'url' => $image_url )
			: array( 'url' => $this->image_to_data_uri( $attachment_id ) );

		if ( empty( $image_payload['url'] ) ) {
			return new WP_Error( 'aiio_no_image_data', __( 'Could not read image file to send to the AI provider.', 'ai-image-optimizer' ) );
		}

		$language  = $this->settings->get( 'alt_text_language', 'English' );
		$max_words = (int) $this->settings->get( 'alt_text_max_words', 20 );

		$prompt = sprintf(
			'Write a concise, descriptive, SEO-friendly alt text attribute for this image, in %1$s. ' .
			'Describe what is literally visible (subject, setting, notable action or detail). ' .
			'Do not start with phrases like "image of" or "photo of". ' .
			'Keep it under %2$d words. Return ONLY the alt text with no quotation marks and no extra commentary.',
			$language,
			$max_words
		);

		$body = array(
			'model'      => $this->settings->get( 'openai_model', 'gpt-4o-mini' ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => $image_payload['url'] ),
						),
					),
				),
			),
			'max_tokens' => 80,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : __( 'Unknown error from AI provider.', 'ai-image-optimizer' );
			return new WP_Error( 'aiio_api_error', $message, array( 'status' => $code ) );
		}

		$text = isset( $response_body['choices'][0]['message']['content'] ) ? $response_body['choices'][0]['message']['content'] : '';
		$text = trim( $text, " \t\n\r\0\x0B\"'" );

		if ( '' === $text ) {
			return new WP_Error( 'aiio_empty_response', __( 'AI provider returned an empty description.', 'ai-image-optimizer' ) );
		}

		return sanitize_text_field( $text );
	}

	/**
	 * Rough check for whether a URL looks publicly reachable (i.e. not
	 * localhost/staging behind auth). Not bulletproof, just a heuristic.
	 *
	 * @param string $url
	 * @return bool
	 */
	private function is_publicly_reachable( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$local_patterns = array( 'localhost', '.local', '.test', '127.0.0.1', '::1' );
		foreach ( $local_patterns as $pattern ) {
			if ( false !== strpos( $host, $pattern ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read an attachment's medium-size file and encode as a base64 data URI,
	 * used as a fallback when the site isn't publicly reachable.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	private function image_to_data_uri( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// Prefer a smaller intermediate size if one exists, to keep payload small.
		$medium = image_get_intermediate_size( $attachment_id, 'medium' );
		if ( $medium && ! empty( $medium['path'] ) ) {
			$upload_dir = wp_upload_dir();
			$candidate  = trailingslashit( $upload_dir['basedir'] ) . $medium['path'];
			if ( file_exists( $candidate ) ) {
				$file_path = $candidate;
			}
		}

		$mime = wp_check_filetype( $file_path );
		$mime = isset( $mime['type'] ) ? $mime['type'] : 'image/jpeg';

		$data = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return false;
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
