<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds UI hooks in the Media Library: a status column, per-attachment
 * "Regenerate alt text" action, and the AJAX endpoints that power them.
 */
class AIIO_Media_Integration {

	private $settings;
	private $alt_text_generator;

	public function __construct( AIIO_Settings $settings, AIIO_Alt_Text_Generator $alt_text_generator ) {
		$this->settings           = $settings;
		$this->alt_text_generator = $alt_text_generator;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// List table column showing optimization status.
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );

		// Attachment edit screen: single "Regenerate with AI" button next to alt text field.
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_regenerate_button' ), 10, 2 );

		// AJAX handlers.
		add_action( 'wp_ajax_aiio_regenerate_alt_text', array( $this, 'ajax_regenerate_alt_text' ) );
	}

	public function enqueue_assets( $hook ) {
		$is_media_page    = in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true );
		$is_settings_page = ( 'media_page_ai-image-optimizer' === $hook );

		if ( ! $is_media_page && ! $is_settings_page ) {
			return;
		}

		wp_enqueue_style( 'aiio-admin', AIIO_PLUGIN_URL . 'assets/css/admin.css', array(), AIIO_VERSION );
		wp_enqueue_script( 'aiio-admin', AIIO_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), AIIO_VERSION, true );

		wp_localize_script(
			'aiio-admin',
			'AIIO',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'aiio_nonce' ),
				'bulkNonce'    => wp_create_nonce( 'aiio_bulk_nonce' ),
				'strings'      => array(
					'generating'      => __( 'Generating…', 'ai-image-optimizer' ),
					'regenerate'      => __( 'Regenerate Alt Text with AI', 'ai-image-optimizer' ),
					'error'           => __( 'Something went wrong. Please try again.', 'ai-image-optimizer' ),
					'bulkStarting'    => __( 'Starting…', 'ai-image-optimizer' ),
					'bulkDone'        => __( 'Done!', 'ai-image-optimizer' ),
				),
			)
		);
	}

	public function add_media_column( $columns ) {
		$columns['aiio_status'] = __( 'Optimization', 'ai-image-optimizer' );
		return $columns;
	}

	public function render_media_column( $column_name, $attachment_id ) {
		if ( 'aiio_status' !== $column_name ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			echo '&#8212;';
			return;
		}

		$processed   = get_post_meta( $attachment_id, '_aiio_processed', true );
		$webp_files  = get_post_meta( $attachment_id, '_aiio_webp_files', true );
		$bytes_saved = (int) get_post_meta( $attachment_id, '_aiio_bytes_saved', true );
		$alt_error   = get_post_meta( $attachment_id, '_aiio_alt_text_error', true );
		$alt_text    = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( ! $processed ) {
			echo '<span class="aiio-badge aiio-badge-pending">' . esc_html__( 'Not yet processed', 'ai-image-optimizer' ) . '</span>';
			return;
		}

		echo '<ul class="aiio-status-list">';

		if ( $bytes_saved > 0 ) {
			printf(
				'<li>✅ %s</li>',
				esc_html( sprintf(
					/* translators: %s: human readable file size */
					__( 'Compressed (saved %s)', 'ai-image-optimizer' ),
					size_format( $bytes_saved )
				) )
			);
		}

		if ( ! empty( $webp_files ) ) {
			printf( '<li>✅ %s</li>', esc_html__( 'WebP created', 'ai-image-optimizer' ) );
		}

		if ( ! empty( $alt_text ) ) {
			printf( '<li>✅ %s</li>', esc_html__( 'Alt text set', 'ai-image-optimizer' ) );
		} elseif ( $alt_error ) {
			printf( '<li>⚠️ %s</li>', esc_html( $alt_error ) );
		} else {
			printf( '<li>&#8212; %s</li>', esc_html__( 'No alt text', 'ai-image-optimizer' ) );
		}

		echo '</ul>';
	}

	/**
	 * Adds a "Regenerate with AI" button under the Alt Text field
	 * on the attachment edit/details screen.
	 *
	 * @param array   $form_fields
	 * @param WP_Post $post
	 * @return array
	 */
	public function add_regenerate_button( $form_fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$button_html  = '<button type="button" class="button aiio-regenerate-btn" data-attachment-id="' . esc_attr( $post->ID ) . '">';
		$button_html .= esc_html__( 'Regenerate Alt Text with AI', 'ai-image-optimizer' );
		$button_html .= '</button>';
		$button_html .= '<span class="aiio-regenerate-status" style="margin-left:8px;"></span>';

		$form_fields['aiio_regenerate'] = array(
			'label'         => __( 'AI Alt Text', 'ai-image-optimizer' ),
			'input'         => 'html',
			'html'          => $button_html,
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $form_fields;
	}

	public function ajax_regenerate_alt_text() {
		check_ajax_referer( 'aiio_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-image-optimizer' ) ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'ai-image-optimizer' ) ), 400 );
		}

		$result = $this->alt_text_generator->maybe_generate_alt_text( $attachment_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'alt_text' => $result ) );
	}
}
