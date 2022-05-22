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
			'limit'           => apply_filters( 'import_from_pixelfed_limit', 10 ),
			'exclude_replies' => true,
			'only_media'      => true,
			'media_type'      => 'photo',
		);

		$most_recent_toot = self::get_latest_status();

		if ( $most_recent_toot ) {
			// So, it seems `since_id` is not _reall_ supported, but `min_id` is. We
			// will "manually" check for duplicates regardless.
			$args['min_id'] = $most_recent_toot;
		}

		$query_string = http_build_query( apply_filters( 'import_from_pixelfed_api_args', $args ) );

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
			return;
		}

		if ( ! is_array( $statuses ) ) {
			return;
		}

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
				error_log( '[Import From Pixelfed] Skipping status with ID ' . $status->id . ': denylisted' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

			if ( empty( $content ) && empty( $status->media_attachments ) ) {
				// Skip.
				continue;
			}

			$title   = wp_trim_words( $content, 10 );
			$title   = apply_filters( 'import_from_pixelfed_post_title', $title, $status );
			$content = apply_filters( 'import_from_pixelfed_post_content', $content, $status );

			/* @todo: Set default title when title and content are both empty. */

			$post_status = apply_filters(
				'import_from_pixelfed_post_status',
				isset( $this->options['post_status'] ) ? $this->options['post_status'] : 'publish',
				$status
			);

			$args = array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => $post_status,
				'post_type'     => apply_filters( 'import_from_pixelfed_post_type', 'post', $status ),
				'post_date_gmt' => ! empty( $status->created_at ) ? date( 'Y-m-d H:i:s', strtotime( $status->created_at ) ) : '', // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				'meta_input'    => array(),
			);

			if ( ! empty( $this->options['post_author'] ) ) {
				$user = get_userdata( $this->options['post_author'] );

				if ( ! empty( $user->ID ) ) {
					$args['post_author'] = $user->ID;
				}
			}

			if ( ! empty( $this->options['post_category'] ) && term_exists( $this->options['post_category'], 'category' ) ) {
				$args['post_category'] = array( $this->options['post_category'] );
			}

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

			// Make entire `$args` array filterable.
			$post_id = wp_insert_post( apply_filters( 'import_from_pixelfed_args', $args, $status ) );

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				// Skip.
				continue;
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
		if ( ! function_exists( 'wp_crop_image' ) ) {
			// Load image functions.
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Get the "current" WordPress upload dir.
		$wp_upload_dir = wp_upload_dir();

		// *Assuming* unique filenames, here.
		$filename  = pathinfo( $attachment_url, PATHINFO_FILENAME ) . '.' . pathinfo( $attachment_url, PATHINFO_EXTENSION );
		$file_path = trailingslashit( $wp_upload_dir['path'] ) . $filename;

		if ( file_exists( $file_path ) ) {
			error_log( '[Import From Pixelfed] File already exists: ' . $attachment_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// File already exists, somehow. So either we've got a different
			// file with the exact same name or we're trying to re-import the
			// exact same file. Assuming the latter, also because Pixelfed's
			// random file names aren't something we would easily come up with.

			/* @todo: Create our own filenames based on complete URL hashes? */
			$file_url      = str_replace( $wp_upload_dir['basedir'], $wp_upload_dir['baseurl'], $file_path );
			$attachment_id = attachment_url_to_postid( $file_url ); // Attachment ID or 0.
		} else {
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
			if ( ! $wp_filesystem->put_contents( $file_path, $response['body'], 0644 ) ) {
				return 0;
			}

			if ( ! file_is_valid_image( $file_path ) || ! file_is_displayable_image( $file_path ) ) {
				unset( $file_path );

				error_log( '[Import From Pixelfed] Invalid image file: ' . $attachment_url ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return 0;
			}

			// Import the image into WordPress' media library.
			$attachment = array(
				'guid'           => $file_path,
				'post_mime_type' => wp_check_filetype( $filename, null )['type'],
				'post_title'     => $filename,
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, trailingslashit( $wp_upload_dir['path'] ) . $filename, $post_id );
		}

		if ( empty( $attachment_id ) ) {
			// Something went wrong.
			return 0;
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
