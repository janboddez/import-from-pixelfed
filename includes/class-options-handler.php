<?php
/**
 * Handles admin pages and the like.
 *
 * @package Import_From_Pixelfed
 */

namespace Import_From_Pixelfed;

/**
 * Options handler.
 */
class Options_Handler {
	/**
	 * Default plugin settings.
	 */
	const DEFAULT_SETTINGS = array(
		'pixelfed_host'          => '',
		'pixelfed_client_id'     => '',
		'pixelfed_client_secret' => '',
		'pixelfed_access_token'  => '',
		'pixelfed_refresh_token' => '',
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'post_format'            => '',
		'denylist'               => '',
	);

	/**
	 * (Some of) the post types users shouldn't be able to select.
	 */
	const DEFAULT_POST_TYPES = array(
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'user_request',
		'oembed_cache',
		'wp_block',
		'wp_global_styles',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'jp_mem_plan',
		'jp_pay_order',
		'jp_pay_product',
		'coblocks_pattern',
		'genesis_custom_block',
	);

	/**
	 * Allowable post statuses.
	 *
	 * @var array POST_STATUSES Allowable post statuses.
	 */
	const POST_STATUSES = array(
		'publish',
		'draft',
		'pending',
		'private',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->options = get_option(
			'import_from_pixelfed_settings',
			self::DEFAULT_SETTINGS
		);
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_import_from_pixelfed', array( $this, 'admin_post' ) );

		// Background token refreshes.
		add_action( 'import_from_pixelfed_refresh_token', array( $this, 'cron_refresh_token' ) );
	}

	/**
	 * Registers the plugin settings page.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Import From Pixelfed', 'import-from-pixelfed' ),
			__( 'Import From Pixelfed', 'import-from-pixelfed' ),
			'manage_options',
			'import-from-pixelfed',
			array( $this, 'settings_page' )
		);

		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 */
	public function add_settings() {
		register_setting(
			'import-from-pixelfed-settings-group',
			'import_from_pixelfed_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		if ( isset( $settings['pixelfed_host'] ) ) {
			$pixelfed_host = untrailingslashit( trim( $settings['pixelfed_host'] ) );

			if ( '' === $pixelfed_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not revoke access just yet.
				$this->options['pixelfed_host'] = '';
			} else {
				if ( 0 !== strpos( $pixelfed_host, 'https://' ) && 0 !== strpos( $pixelfed_host, 'http://' ) ) {
					// Missing protocol. Try adding `https://`.
					$pixelfed_host = 'https://' . $pixelfed_host;
				}

				if ( wp_http_validate_url( $pixelfed_host ) ) {
					if ( $pixelfed_host !== $this->options['pixelfed_host'] ) {
						// Updated URL.

						// (Try to) revoke access. Forget token regardless of
						// the outcome.
						$this->revoke_access();

						// Then, save the new URL.
						$this->options['pixelfed_host'] = untrailingslashit( $pixelfed_host );

						// Forget client ID and secret. A new client ID and secret will
						// be requested next time the page is loaded.
						$this->options['pixelfed_client_id']     = '';
						$this->options['pixelfed_client_secret'] = '';
					}
				} else {
					// Invalid URL. Display error message.
					add_settings_error(
						'import-from-pixelfed-pixelfed-host',
						'invalid-url',
						esc_html__( 'Please provide a valid URL.', 'import-from-pixelfed' )
					);
				}
			}
		}

		if ( isset( $settings['post_type'] ) ) {
			// Post types considered valid.
			$supported_post_types = array_diff(
				get_post_types(),
				self::DEFAULT_POST_TYPES
			);

			if ( in_array( wp_unslash( $settings['post_type'] ), $supported_post_types, true ) ) {
				$this->options['post_type'] = wp_unslash( $settings['post_type'] );
			}
		}

		if ( isset( $settings['post_status'] ) && in_array( wp_unslash( $settings['post_status'] ), self::POST_STATUSES, true ) ) {
			$this->options['post_status'] = wp_unslash( $settings['post_status'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( isset( $settings['denylist'] ) ) {
			// Normalize line endings.
			$denylist                  = preg_replace( '~\R~u', "\r\n", $settings['denylist'] );
			$this->options['denylist'] = trim( $denylist );
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import From Pixelfed', 'import-from-pixelfed' ); ?></h1>

			<h2><?php esc_html_e( 'Settings', 'import-from-pixelfed' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'import-from-pixelfed-settings-group' );

				// Post types considered valid.
				$supported_post_types = array_diff(
					get_post_types(),
					self::DEFAULT_POST_TYPES
				);
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="import_from_pixelfed_settings[pixelfed_host]"><?php esc_html_e( 'Instance', 'import-from-pixelfed' ); ?></label></th>
						<td><input type="url" id="import_from_pixelfed_settings[pixelfed_host]" name="import_from_pixelfed_settings[pixelfed_host]" style="min-width: 40%;" value="<?php echo esc_attr( $this->options['pixelfed_host'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Your Pixelfed instance&rsquo;s URL.', 'import-from-pixelfed' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Post Type', 'import-from-pixelfed' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php
							foreach ( $supported_post_types as $post_type ) :
								$post_type = get_post_type_object( $post_type );
								?>
								<li><label><input type="radio" name="import_from_pixelfed_settings[post_type]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( $post_type->name, $this->options['post_type'] ); ?>> <?php echo esc_html( $post_type->labels->singular_name ); ?></label></li>
								<?php
							endforeach;
							?>
						</ul>
						<p class="description"><?php esc_html_e( 'Post type for newly imported statuses.', 'import-from-pixelfed' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Post Status', 'import-from-pixelfed' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php
							foreach ( self::POST_STATUSES as $post_status ) :
								$post_type = get_post_type_object( $post_type );
								?>
								<li><label><input type="radio" name="import_from_pixelfed_settings[post_status]" value="<?php echo esc_attr( $post_status ); ?>" <?php checked( $post_status, $this->options['post_status'] ); ?>> <?php echo esc_html( ucfirst( $post_status ) ); ?></label></li>
								<?php
							endforeach;
							?>
						</ul>
						<p class="description"><?php esc_html_e( 'Post status for newly imported statuses.', 'import-from-pixelfed' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><label for="import_from_pixelfed_settings[denylist]"><?php esc_html_e( 'Blocklist', 'import-from-pixelfed' ); ?></label></th>
						<td><textarea id="import_from_pixelfed_settings[denylist]" name="import_from_pixelfed_settings[denylist]" style="min-width: 40%;" rows="5"><?php echo esc_html( $this->options['denylist'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Ignore statuses with these words (case-insensitive).', 'import-from-pixelfed' ); ?></p></td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Authorize Access', 'import-from-pixelfed' ); ?></h2>
			<?php
			if ( ! empty( $this->options['pixelfed_host'] ) ) {
				// A valid instance URL was set.
				if ( empty( $this->options['pixelfed_client_id'] ) || empty( $this->options['pixelfed_client_secret'] ) ) {
					// No app is currently registered. Let's try to fix that!
					$this->register_app();
				}

				if ( ! empty( $this->options['pixelfed_client_id'] ) && ! empty( $this->options['pixelfed_client_secret'] ) ) {
					// App registered OK.
					$this->handle_access_token();
				} else {
					// Still couldn't register our app.
					?>
					<p><?php esc_html_e( 'Something went wrong contacting your Pixelfed instance. Please reload this page to try again.', 'import-from-pixelfed' ); ?></p>
					<?php
				}
			} else {
				// We can't do much without an instance URL.
				?>
				<p><?php esc_html_e( 'Please fill out and save your Pixelfed instance&rsquo;s URL first.', 'import-from-pixelfed' ); ?></p>
				<p style="margin-bottom: 2rem;"><?php printf( '<button class="button" disabled="disabled">%s</button>', esc_html__( 'Authorize Access', 'import-from-pixelfed' ) ); ?>
				<?php
			}
			?>

			<h2><?php esc_html_e( 'Debugging', 'import-from-pixelfed' ); ?></h2>
			<p><?php esc_html_e( 'Just in case, the button below lets you delete Import From Pixelfed&rsquo;s settings. Note: This will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Account &gt; Authorized apps&rdquo; page.)', 'import-from-pixelfed' ); ?></p>
			<p style="margin-bottom: 2rem;">
				<?php
				printf(
					'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'action'   => 'import_from_pixelfed',
								'reset'    => 'true',
								'_wpnonce' => wp_create_nonce( 'import-from-pixelfed-reset' ),
							),
							admin_url( 'admin-post.php' )
						)
					),
					esc_html__( 'Reset Settings', 'import-from-pixelfed' )
				);
				?>
			</p>
			<?php
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				?>
				<p><?php esc_html_e( 'The information below is not meant to be shared with anyone but may help when troubleshooting issues.', 'import-from-pixelfed' ); ?></p>
				<p><textarea class="widefat" rows="5"><?php var_export( $this->options ); ?></textarea></p><?php // phpcs:ignore WordPress.PHP.DevelopmentFunctions ?>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Loads (admin) scripts.
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_import-from-pixelfed' !== $hook_suffix ) {
			// Not the "Import From Pixelfed" settings page.
			return;
		}

		// Enqueue JS.
		wp_enqueue_script( 'import-from-pixelfed', plugins_url( '/assets/import-from-pixelfed.js', dirname( __FILE__ ) ), array( 'jquery' ), '0.1.0', true );

		wp_localize_script(
			'import-from-pixelfed',
			'import_from_pixelfed_obj',
			array( 'message' => esc_attr__( 'Are you sure you want to reset all settings?', 'import-from-pixelfed' ) ) // Confirmation message.
		);
	}

	/**
	 * Registers a new Pixelfed app (client).
	 */
	private function register_app() {
		// Register a new app. Should probably only run once (per host).
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => __( 'Import From Pixelfed' ),
					'redirect_uris' => add_query_arg(
						array(
							'page' => 'import-from-pixelfed',
						),
						admin_url(
							'options-general.php'
						)
					), // Allowed redirect URLs.
					'scopes'        => 'read',
					'website'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['pixelfed_client_id']     = $app->client_id;
			$this->options['pixelfed_client_secret'] = $app->client_secret;
			update_option( 'import_from_pixelfed_settings', $this->options );
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Display access token buttons (request, refresh, or revoke).
	 */
	private function handle_access_token() {
		if ( ! empty( $_GET['code'] ) && empty( $this->options['pixelfed_access_token'] ) ) {
			// Access token request.
			if ( $this->request_access_token( wp_unslash( $_GET['code'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Access granted!', 'import-from-pixelfed' ); ?></p>
				</div>
				<?php
			}
		}

		if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'import-from-pixelfed-reset' ) ) {
			// Revoke access. Forget access token regardless of the
			// outcome.
			$this->revoke_access();
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			$url = $this->options['pixelfed_host'] . '/oauth/authorize?' . http_build_query(
				array(
					'response_type' => 'code',
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'import-from-pixelfed', // Redirect here after authorization.
						),
						admin_url( 'options-general.php' )
					),
					'scope'         => 'read',
				)
			);
			?>
			<p><?php esc_html_e( 'Authorize WordPress to read from your Pixelfed timeline. Nothing will ever be posted there.', 'import-from-pixelfed' ); ?></p>
			<p style="margin-bottom: 2rem;"><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'import-from-pixelfed' ) ); ?>
			<?php
		} else {
			// An access token exists. Show revoke button.
			?>
			<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read from your Pixelfed timeline.', 'import-from-pixelfed' ); ?></p>
			<p style="margin-bottom: 2rem;">
				<?php
				printf(
					'<a href="%1$s" class="button">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'page'     => 'import-from-pixelfed',
								'action'   => 'revoke',
								'_wpnonce' => wp_create_nonce( 'import-from-pixelfed-reset' ),
							),
							admin_url( 'options-general.php' )
						)
					),
					esc_html__( 'Revoke Access', 'import-from-pixelfed' )
				);
				?>
			</p>
			<?php
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @param string $code Authorization code.
	 */
	private function request_access_token( $code ) {
		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'import-from-pixelfed',
						),
						admin_url( 'options-general.php' )
					), // Redirect here after authorization.
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;

			update_option( 'import_from_pixelfed_settings', $this->options );

			return true;
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return false;
	}

	/**
	 * Revokes WordPress' access to Pixelfed.
	 *
	 * @return boolean If access was revoked.
	 */
	private function revoke_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			// Insufficient rights.
			return false;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_client_id'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_client_secret'] ) ) {
			return false;
		}

		// Revoke access. This is where Pixelfed differs from Mastodon/OAuth 2.
		$response = wp_remote_post(
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found,Squiz.Commenting.InlineComment.InvalidEndChar
			// esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/revoke',
			esc_url_raw( $this->options['pixelfed_host'] . '/oauth/tokens/' . $this->options['pixelfed_access_token'] ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
				),
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			error_log( '[Import From Pixelfed] ' . sprintf( __( 'Something went wrong contacting the instance: %s', 'share-on-pixelfed' ), $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			/* @todo: Replace with actual error message. */
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return false;
		}

		// Success. Delete access token.
		error_log( '[Import From Pixelfed] ' . __( 'Access revoked.', 'share-on-pixelfed' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		$this->options['pixelfed_access_token'] = '';

		update_option( 'share_on_pixelfed_settings', $this->options );
		return true;
	}

	/**
	 * Checks whether the access token is up for refresh.
	 */
	public function cron_refresh_token() {
		if ( empty( $this->options['pixelfed_token_expiry'] ) ) {
			// No expiry date set.
			return;
		}

		if ( $this->options['pixelfed_token_expiry'] > time() + 2 * DAY_IN_SECONDS ) {
			// Token doesn't expire till two days from now.
			return;
		}

		$this->refresh_access_token();
	}

	/**
	 * Requests a token refresh.
	 *
	 * Separate method so it _could_ be run manually.
	 */
	private function refresh_access_token() {
		if ( ! current_user_can( 'manage_options' ) && ! wp_doing_cron() ) {
			// A refresh should be initiated by either a cron action or a user
			// with administrative (`manage_options`) rights.
			return false;
		}

		// Request an access token refresh.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $this->options['pixelfed_refresh_token'],
					'scope'         => 'read',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Import From Pixelfed] Token refresh failed. ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;

			if ( isset( $token->refresh_token ) ) {
				$this->options['pixelfed_refresh_token'] = $token->refresh_token;
			}

			if ( isset( $token->expires_in ) ) {
				$this->options['pixelfed_token_expiry'] = time() + (int) $token->expires_in;
			}

			error_log( '[Import From Pixelfed] Token refresh successful, or token not up for renewal, yet.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			update_option( 'share_on_pixelfed_settings', $this->options );

			return true;
		} else {
			/* @todo: Provide proper error message. */
			error_log( '[Import From Pixelfed] ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		return false;
	}

	/**
	 * `admin-post.php` callback.
	 */
	public function admin_post() {
		if ( isset( $_GET['reset'] ) && 'true' === $_GET['reset']
		  && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'import-from-pixelfed-reset' ) ) {
			// Reset all of this plugin's settings.
			$this->reset_options();
		}

		// Redirect _always_. This why we don't return early.
		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect
			esc_url_raw(
				add_query_arg(
					array(
						'page' => 'import-from-pixelfed',
					),
					admin_url( 'options-general.php' )
				)
			)
		);
		exit;
	}

	/**
	 * Resets all plugin options.
	 */
	private function reset_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->options = self::DEFAULT_SETTINGS;
		update_option( 'import_from_pixelfed_settings', $this->options );
	}

	/**
	 * Returns the plugin options.
	 *
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}
}
