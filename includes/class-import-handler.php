<?php
/**
 * Our "API client," responsible for turning recent toots into WordPress posts.
 *
 * @package Import_From_Pixelfed
 */

namespace Import_From_Pixelfed;

/**
 * Import handler.
 */
class Import_Handler {
	/**
	 * This plugin's settings.
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @param Options_Handler $options_handler The plugin's Options Handler.
	 */
	public function __construct( $options_handler ) {
		$this->options = $options_handler->get_options();
	}

	/**
	 * Registers hook callbacks.
	 */
	public function register() {
		add_action( 'import_from_pixelfed_get_statuses', array( $this, 'get_statuses' ) );
	}

	/**
	 * Grabs statuses off Pixelfed and adds 'em as WordPress posts.
	 */
	public function get_statuses() {
		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return;
		}

		$args = array(
			'exclude_reblogs' => empty( $this->options['include_reblogs'] ),
			'exclude_replies' => empty( $this->options['include_replies'] ),
			'limit'           => apply_filters( 'import_from_pixelfed_limit', 15 ),
		);

		$most_recent_toot = self::get_latest_status();

		if ( $most_recent_toot ) {
			error_log( '[Import From Pixelfed] Found the following status to be the most recent one: ' . $most_recent_toot ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// So, it seems `since_id` is not _reall_ supported, but `min_id` is. We
			// will "manually" check for duplicates regardless.
			$args['min_id'] = $most_recent_toot;
		}

		$query_string = http_build_query( $args );

		if ( $this->options['tags'] ) {
			$tags = explode( ',', (string) $this->options['tags'] );

			foreach ( $tags as $tag ) {
				$query_string .= '&tagged[]=' . rawurlencode( $tag );
			}
		}

		$response = wp_remote_get(
			esc_url_raw( $this->options['pixelfed_host'] . '/api/v1/accounts/' . $this->get_account_id() . '/statuses?' . $query_string ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
				),
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$body     = wp_remote_retrieve_body( $response );
		$statuses = json_decode( $body );

		if ( empty( $statuses ) ) {
			error_log( '[Import From Pixelfed] No new statuses found' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( ! is_array( $statuses ) ) {
			return;
		}

		error_log( '[Import From Pixelfed] Found ' . count( $statuses ) . ' new status(es)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( ! empty( $this->options['denylist'] ) ) {
			// Prep our denylist; doing this only once.
			$denylist = explode( "\n", (string) $this->options['denylist'] );
			$denylist = array_map( 'trim', $denylist );
		}

		// Reverse the array, so that the most recent status is inserted last.
		$statuses = array_reverse( $statuses );

		foreach ( $statuses as $status ) {
			if ( empty( $status->id ) ) {
				// This should not happen. Skip.
				continue;
			}

			$exists = self::already_exists( $status->id );

			if ( false !== $exists ) {
				// Imported before. Skip.
				error_log( '[Import From Pixelfed] Skipping status with ID ' . $status->id . ', which was imported before (post ID: ' . $exists . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			$content = '';
			$title   = '';

			if ( ! empty( $denylist ) && isset( $status->content ) && str_ireplace( $denylist, '', $status->content ) !== $status->content ) {
				// Denylisted.
				continue;
			}

			$content = trim(
				wp_kses(
					$status->content,
					array(
						'a'  => array(
							'class' => array(),
							'href'  => array(),
						),
						'br' => array(),
					)
				)
			);

			if ( isset( $status->reblog->url ) && isset( $status->reblog->account->username ) ) {
				// Add a little bit of context to boosts.
				if ( ! empty( $content ) ) {
					$content  = '<blockquote>' . $content . PHP_EOL . PHP_EOL;
					$content .= '&mdash;<a href="' . esc_url( $status->reblog->url ) . '" rel="nofollow">' . esc_html( $status->reblog->account->username ) . '</a>';
					$content .= '</blockquote>';
				}

				// Could eventually do something similar for replies. Would be
				// somehat more difficult, though. For now, there's the filters
				// below.
			}

			if ( empty( $content ) && empty( $status->media_attachments ) ) {
				// Skip.
				continue;
			}

			$title = wp_trim_words( $content, 10 );

			$content = apply_filters( 'import_from_pixelfed_post_content', $content, $status );
			$title   = apply_filters( 'import_from_pixelfed_post_title', $title, $status );

			$post_type = apply_filters(
				'import_from_pixelfed_post_type',
				isset( $this->options['post_type'] ) ? $this->options['post_type'] : 'post',
				$status
			);
			$post_category= apply_filters(
				'import_from_pixelfed_post_category',
				isset( $this->options['post_category'] ) ? $this->options['post_category'] : '1',
				$status
			);

			$args = array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => apply_filters(
					'import_from_pixelfed_post_status',
					isset( $this->options['post_status'] ) ? $this->options['post_status'] : 'publish',
					$status
				),
				'post_type'     => $post_type,
				'post_category' => array($post_category),
				'post_date_gmt' => ! empty( $status->created_at ) ? date( 'Y-m-d H:i:s', strtotime( $status->created_at ) ) : '', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'meta_input'    => array(),
			);

			// ID (on our instance).
			$args['meta_input']['_import_from_pixelfed_id'] = $status->id;

			// (Original) URL.
			if ( ! empty( $status->reblog->url ) ) {
				$args['meta_input']['_import_from_pixelfed_url'] = esc_url_raw( $status->reblog->url );
			} elseif ( ! empty( $status->url ) ) {
				$args['meta_input']['_import_from_pixelfed_url'] = esc_url_raw( $status->url );
			}

			if ( empty( $title ) && ! empty( $args['meta_input']['_import_from_pixelfed_url'] ) ) {
				$args['post_title'] = $args['meta_input']['_import_from_pixelfed_url'];
			}

			$post_id = wp_insert_post( apply_filters( 'import_from_pixelfed_args', $args, $status ) );

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				// Skip.
				continue;
			}

			if ( post_type_supports( $post_type, 'post-formats' ) ) {
				set_post_format(
					$post_id,
					apply_filters(
						'import_from_pixelfed_post_format',
						! empty( $this->options['post_format'] ) ? $this->options['post_format'] : 'standard',
						$status
					)
				);
			}

			if ( ! empty( $status->media_attachments ) ) {
				$i = 0;

				foreach ( $status->media_attachments as $attachment ) {
					if ( empty( $attachment->type ) || 'image' !== $attachment->type ) {
						// For now, only images are supported.
						continue;
					}

					if ( empty( $attachment->url ) || ! wp_http_validate_url( $attachment->url ) ) {
						continue;
					}

					// Download the image into WordPress's uploads folder, and
					// attach it to the newly created post.
					$attachment_id = $this->create_attachment(
						$attachment->url,
						$post_id,
						! empty( $attachment->description ) ? $attachment->description : ''
					);

					if ( 0 === $i && 0 !== $attachment_id && apply_filters( 'import_from_pixelfed_featured_image', true ) ) {
						// Set the first successfully uploaded attachment as
						// featured image.
						set_post_thumbnail( $post_id, $attachment_id );
					}

					$i++;
				}
			}
		}
	}

	/**
	 * Uploads an image to a certain post.
	 *
	 * @param  string $attachment_url Image URL.
	 * @param  int    $post_id        Post ID.
	 * @param  string $description    Image `alt` text.
	 * @return int                    Attachment ID, and 0 on failure.
	 */
	private function create_attachment( $attachment_url, $post_id, $description ) {
		// Get the 'current' WordPress upload dir.
		$wp_upload_dir = wp_upload_dir();

		// Assuming unique filenames, here.
		$filename = pathinfo( $attachment_url, PATHINFO_FILENAME ) . '.' . pathinfo( $attachment_url, PATHINFO_EXTENSION );

		if ( is_file( $filename ) ) {
			return 0; // To do: return attachment ID.
		}

		// Download attachment.
		$response = wp_remote_get(
			esc_url_raw( $attachment_url ),
			array(
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		if ( empty( $response['body'] ) ) {
			return 0;
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Write image data.
		if ( ! $wp_filesystem->put_contents( trailingslashit( $wp_upload_dir['path'] ) . $filename, $response['body'], 0644 ) ) {
			return 0;
		}

		// Import the image into WordPress' media library.
		$attachment = array(
			'guid'           => trailingslashit( $wp_upload_dir['url'] ) . $filename,
			'post_mime_type' => wp_check_filetype( $filename, null )['type'],
			'post_title'     => $filename,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, trailingslashit( $wp_upload_dir['path'] ) . $filename, $post_id );

		if ( 0 === $attachment_id ) {
			// Something went wrong.
			return 0;
		}

		if ( ! function_exists( 'wp_crop_image' ) ) {
			// Load image functions.
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Generate metadata. Generates thumbnails, too.
		$metadata = wp_generate_attachment_metadata(
			$attachment_id,
			trailingslashit( $wp_upload_dir['path'] ) . $filename
		);

		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Explicitly set image `alt` text.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_textarea_field( $description ) );

		return $attachment_id;
	}

	/**
	 * Get the authenticated user's account ID.
	 *
	 * @return int|null Account ID.
	 */
	private function get_account_id() {
		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return null;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return null;
		}

		$response = wp_remote_get(
			esc_url_raw( $this->options['pixelfed_host'] . '/api/v1/accounts/verify_credentials' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
				),
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return null;
		}

		$body    = wp_remote_retrieve_body( $response );
		$account = json_decode( $body );

		if ( ! empty( $account->id ) ) {
			return (int) $account->id;
		}
	}

	/**
	 * Checks for the existence of a similar post.
	 *
	 * @param  string $toot_id Pixelfed ID.
	 * @return int|bool        The corresponding post ID, or false.
	 */
	public static function already_exists( $toot_id ) {
		// Fetch the most recent toot's post ID.
		$query = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'fields'      => 'ids',
				'limit'       => 1,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => '_import_from_pixelfed_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_import_from_pixelfed_id',
						'compare' => '=',
						'value'   => $toot_id,
					),
				),
			)
		);

		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return false;
		}

		if ( ! is_array( $posts ) ) {
			return false;
		}

		return reset( $posts );
	}

	/**
	 * Returns the most recent toot's Pixelfed ID.
	 *
	 * @return string|null Pixelfed ID, or `null`.
	 */
	public static function get_latest_status() {
		// Fetch the most recent toot's post ID.
		$query = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				// Ordering by ID would not necessarily give us the most recent status.
				'meta_key'    => '_import_from_pixelfed_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'     => 'meta_value_num',
				'order'       => 'DESC',
				'fields'      => 'ids',
				'limit'       => 1,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_import_from_pixelfed_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_import_from_pixelfed_id',
						'compare' => '!=',
						'value'   => '',
					),
				),
			)
		);

		$posts = $query->posts;

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			error_log( '[Import From Pixelfed] Could not find a previously imported status; skipping `min_id`' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		// Return Pixelfed ID of most recent post with a Pixelfed ID.
		return get_post_meta( reset( $posts ), '_import_from_pixelfed_id', true ); // A (numeric, most likely) string.
	}
}
