<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reading/writing plugin settings and rendering the settings page.
 */
class AIIO_Settings {

	const OPTION_KEY = 'aiio_settings';

	private $cache = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function defaults() {
		return array(
			'enable_compression'   => 1,
			'enable_webp'          => 1,
			'enable_alt_text'      => 1,
			'jpeg_quality'         => 82,
			'webp_quality'         => 80,
			'ai_provider'          => 'openai',
			'openai_api_key'       => '',
			'openai_model'         => 'gpt-4o-mini',
			'alt_text_language'    => 'English',
			'alt_text_max_words'   => 20,
			'keep_original_on_webp' => 1,
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$this->cache = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
		}
		return $this->cache;
	}

	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function get_api_key() {
		// Allow overriding via wp-config.php constant so the key never touches the DB.
		if ( defined( 'AIIO_OPENAI_API_KEY' ) && AIIO_OPENAI_API_KEY ) {
			return AIIO_OPENAI_API_KEY;
		}
		return $this->get( 'openai_api_key', '' );
	}

	public function add_settings_page() {
		add_media_page(
			__( 'AI Image Optimizer', 'ai-image-optimizer' ),
			__( 'Image Optimizer', 'ai-image-optimizer' ),
			'manage_options',
			'ai-image-optimizer',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'aiio_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize' )
		);
	}

	public function sanitize( $input ) {
		$defaults  = self::defaults();
		$sanitized = array();

		$sanitized['enable_compression']    = empty( $input['enable_compression'] ) ? 0 : 1;
		$sanitized['enable_webp']           = empty( $input['enable_webp'] ) ? 0 : 1;
		$sanitized['enable_alt_text']       = empty( $input['enable_alt_text'] ) ? 0 : 1;
		$sanitized['keep_original_on_webp'] = empty( $input['keep_original_on_webp'] ) ? 0 : 1;

		$sanitized['jpeg_quality'] = isset( $input['jpeg_quality'] ) ? min( 100, max( 10, (int) $input['jpeg_quality'] ) ) : $defaults['jpeg_quality'];
		$sanitized['webp_quality'] = isset( $input['webp_quality'] ) ? min( 100, max( 10, (int) $input['webp_quality'] ) ) : $defaults['webp_quality'];

		$sanitized['ai_provider']    = isset( $input['ai_provider'] ) ? sanitize_text_field( $input['ai_provider'] ) : $defaults['ai_provider'];
		$sanitized['openai_api_key'] = isset( $input['openai_api_key'] ) ? sanitize_text_field( trim( $input['openai_api_key'] ) ) : '';
		$sanitized['openai_model']   = isset( $input['openai_model'] ) ? sanitize_text_field( $input['openai_model'] ) : $defaults['openai_model'];

		$sanitized['alt_text_language']  = isset( $input['alt_text_language'] ) ? sanitize_text_field( $input['alt_text_language'] ) : $defaults['alt_text_language'];
		$sanitized['alt_text_max_words'] = isset( $input['alt_text_max_words'] ) ? min( 60, max( 5, (int) $input['alt_text_max_words'] ) ) : $defaults['alt_text_max_words'];

		return $sanitized;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->all();
		?>
		<div class="wrap aiio-settings-wrap">
			<h1><?php esc_html_e( 'AI Image Optimizer', 'ai-image-optimizer' ); ?></h1>
			<p><?php esc_html_e( 'Automatically compress images, generate AI alt text, and create WebP versions on upload.', 'ai-image-optimizer' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'aiio_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Features', 'ai-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Compression', 'ai-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_compression]" value="1" <?php checked( $s['enable_compression'], 1 ); ?> />
								<?php esc_html_e( 'Compress images automatically on upload', 'ai-image-optimizer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiio_jpeg_quality"><?php esc_html_e( 'JPEG/PNG Quality', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<input type="number" min="10" max="100" id="aiio_jpeg_quality" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[jpeg_quality]" value="<?php echo esc_attr( $s['jpeg_quality'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '1-100. Lower = smaller file size, lower quality. Recommended: 80-85.', 'ai-image-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP Generation', 'ai-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_webp]" value="1" <?php checked( $s['enable_webp'], 1 ); ?> />
								<?php esc_html_e( 'Create WebP versions of uploaded images', 'ai-image-optimizer' ); ?>
							</label><br/>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[keep_original_on_webp]" value="1" <?php checked( $s['keep_original_on_webp'], 1 ); ?> />
								<?php esc_html_e( 'Keep original format as fallback (recommended)', 'ai-image-optimizer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiio_webp_quality"><?php esc_html_e( 'WebP Quality', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<input type="number" min="10" max="100" id="aiio_webp_quality" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webp_quality]" value="<?php echo esc_attr( $s['webp_quality'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI Alt Text', 'ai-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_alt_text]" value="1" <?php checked( $s['enable_alt_text'], 1 ); ?> />
								<?php esc_html_e( 'Generate alt text automatically for images missing it', 'ai-image-optimizer' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'AI Provider', 'ai-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aiio_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<?php if ( defined( 'AIIO_OPENAI_API_KEY' ) && AIIO_OPENAI_API_KEY ) : ?>
								<input type="password" id="aiio_openai_api_key" value="••••••••••••••••" class="regular-text" disabled />
								<p class="description"><?php esc_html_e( 'API key is defined in wp-config.php via AIIO_OPENAI_API_KEY and cannot be edited here.', 'ai-image-optimizer' ); ?></p>
							<?php else : ?>
								<input type="password" id="aiio_openai_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_api_key]" value="<?php echo esc_attr( $s['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to OpenAI dashboard */
										esc_html__( 'Get an API key from %s. For better security, define AIIO_OPENAI_API_KEY in wp-config.php instead.', 'ai-image-optimizer' ),
										'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com</a>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiio_openai_model"><?php esc_html_e( 'Vision Model', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<select id="aiio_openai_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_model]">
								<?php foreach ( array( 'gpt-4o-mini', 'gpt-4o' ) as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $s['openai_model'], $model ); ?>><?php echo esc_html( $model ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiio_alt_text_language"><?php esc_html_e( 'Alt Text Language', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<input type="text" id="aiio_alt_text_language" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[alt_text_language]" value="<?php echo esc_attr( $s['alt_text_language'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiio_alt_text_max_words"><?php esc_html_e( 'Max Alt Text Length (words)', 'ai-image-optimizer' ); ?></label></th>
						<td>
							<input type="number" min="5" max="60" id="aiio_alt_text_max_words" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[alt_text_max_words]" value="<?php echo esc_attr( $s['alt_text_max_words'] ); ?>" class="small-text" />
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr/>

			<h2 class="title"><?php esc_html_e( 'Bulk Process Existing Media', 'ai-image-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Run compression, WebP generation, and AI alt text on images already in your Media Library.', 'ai-image-optimizer' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="aiio-start-bulk">
					<?php esc_html_e( 'Start Bulk Optimization', 'ai-image-optimizer' ); ?>
				</button>
			</p>
			<div id="aiio-bulk-progress-wrap" style="display:none; max-width:600px;">
				<div style="background:#e5e5e5; border-radius:4px; overflow:hidden; height:20px;">
					<div id="aiio-bulk-progress-bar" style="background:#2271b1; width:0%; height:100%; transition:width .3s;"></div>
				</div>
				<p id="aiio-bulk-progress-text"></p>
			</div>
		</div>
		<?php
	}
}
