<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates WebP copies of the original + intermediate image sizes,
 * and rewrites front-end <img> tags to use WebP with a <picture>-less
 * approach via the wp_content_img_tag filter (WP 6.1+) so browsers that
 * don't support WebP still get the original as fallback via srcset logic
 * is not needed here -- we swap only when the browser sent an Accept header
 * indicating WebP support is assumed by using the picture element helper.
 */
class AIIO_Webp_Converter {

	private $settings;

	public function __construct( AIIO_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create .webp files alongside the original + each generated size.
	 *
	 * @param int   $attachment_id
	 * @param array $metadata
	 * @return array List of created webp paths.
	 */
	public function generate_webp_versions( $attachment_id, $metadata ) {
		$created   = array();
		$file_path = get_attached_file( $attachment_id );

		if ( $file_path && file_exists( $file_path ) ) {
			$webp = $this->convert_to_webp( $file_path );
			if ( $webp ) {
				$created['full'] = $webp;
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = trailingslashit( dirname( $file_path ) );
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_path = $base_dir . $size_data['file'];
				if ( file_exists( $size_path ) ) {
					$webp = $this->convert_to_webp( $size_path );
					if ( $webp ) {
						$created[ $size_name ] = $webp;
					}
				}
			}
		}

		if ( ! empty( $created ) ) {
			update_post_meta( $attachment_id, '_aiio_webp_files', $created );
		}

		return $created;
	}

	/**
	 * Convert a single image file to WebP. Skips if already WebP or
	 * an unsupported type. Returns the new file's path, or false.
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function convert_to_webp( $path ) {
		$mime = wp_check_filetype( $path );
		$mime = isset( $mime['type'] ) ? $mime['type'] : '';

		if ( 'image/webp' === $mime ) {
			return false; // Already WebP.
		}

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		// Confirm the active editor can actually output WebP.
		if ( ! $this->editor_supports_webp( $editor ) ) {
			return false;
		}

		$editor->set_quality( (int) $this->settings->get( 'webp_quality', 80 ) );

		$path_info  = pathinfo( $path );
		$webp_path  = trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.webp';

		$saved = $editor->save( $webp_path, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return false;
		}

		return isset( $saved['path'] ) ? $saved['path'] : $webp_path;
	}

	/**
	 * Check whether the current image editor backend can write WebP.
	 *
	 * @param WP_Image_Editor $editor
	 * @return bool
	 */
	private function editor_supports_webp( $editor ) {
		if ( $editor instanceof WP_Image_Editor_Imagick ) {
			return in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true );
		}
		if ( $editor instanceof WP_Image_Editor_GD ) {
			return function_exists( 'imagewebp' );
		}
		// Unknown/custom editor: attempt anyway, save() will fail gracefully if unsupported.
		return true;
	}

	/**
	 * Filter callback: swap <img> src/srcset to .webp equivalents in
	 * post content output, when a WebP version exists and the visitor's
	 * browser advertises WebP support via the Accept header.
	 *
	 * @param string $filtered_image The HTML img tag.
	 * @param string $context        Context the filter is running in.
	 * @param int    $attachment_id  Attachment ID.
	 * @return string
	 */
	public function maybe_swap_to_webp_in_html( $filtered_image, $context, $attachment_id ) {
		if ( ! $this->settings->get( 'enable_webp', true ) ) {
			return $filtered_image;
		}

		if ( ! $this->browser_accepts_webp() ) {
			return $filtered_image;
		}

		$webp_files = get_post_meta( $attachment_id, '_aiio_webp_files', true );
		if ( empty( $webp_files ) || ! is_array( $webp_files ) ) {
			return $filtered_image;
		}

		$upload_dir = wp_upload_dir();

		// Replace every referenced file (src + srcset entries) that has a known webp counterpart.
		foreach ( $webp_files as $webp_path ) {
			$relative_original = $this->original_relative_path_for_webp( $webp_path );
			if ( ! $relative_original ) {
				continue;
			}
			$original_url = trailingslashit( $upload_dir['baseurl'] ) . $relative_original;
			$webp_url     = trailingslashit( $upload_dir['baseurl'] ) . $this->relative_path( $webp_path, $upload_dir['basedir'] );

			$filtered_image = str_replace( $original_url, $webp_url, $filtered_image );
		}

		return $filtered_image;
	}

	private function relative_path( $absolute_path, $base_dir ) {
		return ltrim( str_replace( trailingslashit( $base_dir ), '', $absolute_path ), '/' );
	}

	/**
	 * Given a .webp path we generated, try to figure out the original
	 * (jpg/png) relative path it replaces, so we can find/replace URLs.
	 */
	private function original_relative_path_for_webp( $webp_path ) {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( dirname( $webp_path ) );
		$filename   = pathinfo( $webp_path, PATHINFO_FILENAME );

		foreach ( array( 'jpg', 'jpeg', 'png' ) as $ext ) {
			$candidate = $dir . $filename . '.' . $ext;
			if ( file_exists( $candidate ) ) {
				return $this->relative_path( $candidate, $upload_dir['basedir'] );
			}
		}
		return false;
	}

	/**
	 * Whether the current request's browser accepts WebP images,
	 * based on the standard Accept header.
	 *
	 * @return bool
	 */
	private function browser_accepts_webp() {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}
		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		return ( false !== strpos( $accept, 'image/webp' ) );
	}
}
