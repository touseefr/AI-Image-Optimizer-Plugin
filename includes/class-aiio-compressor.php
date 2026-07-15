<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compresses the original image and all generated intermediate sizes.
 * Uses WordPress's built-in image editor abstraction (Imagick or GD),
 * so no extra PHP extensions are required beyond what WP already needs.
 */
class AIIO_Compressor {

	private $settings;

	public function __construct( AIIO_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Compress the original file plus every registered thumbnail size
	 * for a given attachment.
	 *
	 * @param int   $attachment_id
	 * @param array $metadata
	 * @return array Stats about savings, for logging/debugging.
	 */
	public function compress_attachment( $attachment_id, $metadata ) {
		$stats      = array();
		$file_path  = get_attached_file( $attachment_id );

		if ( $file_path && file_exists( $file_path ) ) {
			$stats['full'] = $this->compress_file( $file_path );
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = trailingslashit( dirname( $file_path ) );
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_path = $base_dir . $size_data['file'];
				if ( file_exists( $size_path ) ) {
					$stats[ $size_name ] = $this->compress_file( $size_path );
				}
			}
		}

		$total_saved = array_sum( wp_list_pluck( $stats, 'saved_bytes' ) );
		update_post_meta( $attachment_id, '_aiio_bytes_saved', $total_saved );

		return $stats;
	}

	/**
	 * Compress a single image file in place using WP_Image_Editor.
	 *
	 * @param string $path Absolute path to the image.
	 * @return array{original_bytes:int,new_bytes:int,saved_bytes:int}
	 */
	public function compress_file( $path ) {
		$original_bytes = file_exists( $path ) ? filesize( $path ) : 0;

		$mime = wp_check_filetype( $path );
		$mime = isset( $mime['type'] ) ? $mime['type'] : '';

		// Only these types benefit from lossy re-encoding.
		$supported = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( ! in_array( $mime, $supported, true ) ) {
			return array(
				'original_bytes' => $original_bytes,
				'new_bytes'       => $original_bytes,
				'saved_bytes'     => 0,
			);
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return array(
				'original_bytes' => $original_bytes,
				'new_bytes'       => $original_bytes,
				'saved_bytes'     => 0,
			);
		}

		$quality = (int) $this->settings->get( 'jpeg_quality', 82 );
		$editor->set_quality( $quality );

		// Strip EXIF/metadata where the editor supports it (Imagick).
		if ( method_exists( $editor, 'strip_meta' ) ) {
			$editor->strip_meta();
		}

		$saved = $editor->save( $path, $mime );

		if ( is_wp_error( $saved ) ) {
			return array(
				'original_bytes' => $original_bytes,
				'new_bytes'       => $original_bytes,
				'saved_bytes'     => 0,
			);
		}

		clearstatcache();
		$new_bytes = file_exists( $path ) ? filesize( $path ) : $original_bytes;

		return array(
			'original_bytes' => $original_bytes,
			'new_bytes'       => $new_bytes,
			'saved_bytes'     => max( 0, $original_bytes - $new_bytes ),
		);
	}
}
