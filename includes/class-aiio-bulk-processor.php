<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Powers the "Bulk Process Existing Media" button on the settings page.
 * Processes attachments in small batches via repeated AJAX calls so we
 * never hit PHP execution time limits on large libraries.
 */
class AIIO_Bulk_Processor {

	const BATCH_SIZE = 5;

	private $settings;
	private $compressor;
	private $webp_converter;
	private $alt_text_generator;

	public function __construct( AIIO_Settings $settings, AIIO_Compressor $compressor, AIIO_Webp_Converter $webp_converter, AIIO_Alt_Text_Generator $alt_text_generator ) {
		$this->settings           = $settings;
		$this->compressor         = $compressor;
		$this->webp_converter     = $webp_converter;
		$this->alt_text_generator = $alt_text_generator;

		add_action( 'wp_ajax_aiio_bulk_get_queue', array( $this, 'ajax_get_queue' ) );
		add_action( 'wp_ajax_aiio_bulk_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * Returns the full list of unprocessed (or all, if forced) image
	 * attachment IDs so the browser can chunk through them.
	 */
	public function ajax_get_queue() {
		check_ajax_referer( 'aiio_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-image-optimizer' ) ), 403 );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		wp_send_json_success(
			array(
				'ids'        => array_map( 'intval', $ids ),
				'batch_size' => self::BATCH_SIZE,
			)
		);
	}

	/**
	 * Process one batch of attachment IDs sent from the browser.
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'aiio_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-image-optimizer' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();

		$results = array();

		foreach ( $ids as $attachment_id ) {
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );
			$entry    = array( 'id' => $attachment_id );

			if ( $this->settings->get( 'enable_compression', true ) && ! get_post_meta( $attachment_id, '_aiio_bulk_compressed', true ) ) {
				$this->compressor->compress_attachment( $attachment_id, (array) $metadata );
				update_post_meta( $attachment_id, '_aiio_bulk_compressed', 1 );
				$entry['compressed'] = true;
			}

			if ( $this->settings->get( 'enable_webp', true ) ) {
				$existing_webp = get_post_meta( $attachment_id, '_aiio_webp_files', true );
				if ( empty( $existing_webp ) ) {
					$this->webp_converter->generate_webp_versions( $attachment_id, (array) $metadata );
					$entry['webp'] = true;
				}
			}

			if ( $this->settings->get( 'enable_alt_text', true ) ) {
				$result = $this->alt_text_generator->maybe_generate_alt_text( $attachment_id, false );
				$entry['alt_text'] = is_wp_error( $result ) ? false : $result;
			}

			update_post_meta( $attachment_id, '_aiio_processed', time() );
			$results[] = $entry;
		}

		wp_send_json_success( array( 'results' => $results ) );
	}
}
