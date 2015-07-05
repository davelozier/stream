<?php
namespace WP_Stream;

class Feeds {
	/**
	 * Hold Plugin class
	 * @var Plugin
	 */
	public $plugin;

	public $user_feed_option_key = 'stream_user_feed_key';

	const FEED_QUERY_VAR         = 'stream';
	const FEED_KEY_QUERY_VAR     = 'key';
	const FEED_TYPE_QUERY_VAR    = 'type';
	const GENERATE_KEY_QUERY_VAR = 'stream_new_user_feed_key';

	/**
	 * Class constructor.
	 *
	 * @param Plugin $plugin The main Plugin class.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		if (
			$this->plugin->is_vip()
			||
			! isset( $this->plugin->settings->options['general_private_feeds'] )
			||
			! $this->plugin->settings->options['general_private_feeds']
		) {
			return;
		}

		add_action( 'show_user_profile', array( $this, 'save_user_feed_key' ) );
		add_action( 'edit_user_profile', array( $this, 'save_user_feed_key' ) );

		add_action( 'show_user_profile', array( $this, 'user_feed_key' ) );
		add_action( 'edit_user_profile', array( $this, 'user_feed_key' ) );

		// Generate new Stream Feed Key
		add_action( 'wp_ajax_wp_stream_feed_key_generate', array( $this, 'generate_user_feed_key' ) );

		add_feed( self::FEED_QUERY_VAR, array( $this, 'feed_template' ) );
	}

	/**
	 * Sends a new user key when the
	 *
	 * @return string JSON data.
	 */
	public function generate_user_feed_key() {
		check_ajax_referer( 'wp_stream_generate_key', 'nonce' );

		$user_id = (int) filter_input( INPUT_POST, 'user', FILTER_SANITIZE_NUMBER_INT );

		if ( $user_id ) {
			$feed_key = wp_generate_password( 32, false );

			$this->plugin->admin->update_user_meta( $user_id, $this->user_feed_option_key, $feed_key );

			$link      = $this->get_user_feed_url( $feed_key );
			$xml_feed  = add_query_arg( array( 'type' => 'json' ), $link );
			$json_feed = add_query_arg( array( 'type' => 'json' ), $link );

			wp_send_json_success(
				array(
					'message'   => 'User feed key successfully generated.',
					'feed_key'  => $feed_key,
					'xml_feed'  => $xml_feed,
					'json_feed' => $json_feed,
				)
			);
		} else {
			wp_send_json_error( 'User ID error' );
		}
	}

	/**
	 * Generates and saves a unique key as user meta if the user does not
	 * already have a key, or has requested a new one.
	 *
	 * @action show_user_profile
	 * @action edit_user_profile
	 *
	 * @param \WP_User $user
	 */
	public function save_user_feed_key( $user ) {
		$generate_key = filter_input( INPUT_GET, self::GENERATE_KEY_QUERY_VAR );
		$nonce        = filter_input( INPUT_GET, 'wp_stream_nonce' );

		if ( ! $generate_key && $this->plugin->admin->get_user_meta( $user->ID, $this->user_feed_option_key ) ) {
			return;
		}

		if ( $generate_key && ! wp_verify_nonce( $nonce, 'wp_stream_generate_key' ) ) {
			return;
		}

		$feed_key = wp_generate_password( 32, false );

		$this->plugin->admin->update_user_meta( $user->ID, $this->user_feed_option_key, $feed_key );
	}

	/**
	 * Output for Stream Feed URL field in user profiles.
	 *
	 * @action show_user_profile
	 * @action edit_user_profile
	 * @param \WP_User $user
	 * @return string
	 */
	public function user_feed_key( $user ) {
		if ( ! array_intersect( $user->roles, $this->plugin->settings->options['general_role_access'] ) ) {
			return;
		}

		$key  = $this->plugin->admin->get_user_meta( $user->ID, $this->user_feed_option_key );
		$link = $this->get_user_feed_url( $key );

		$nonce = wp_create_nonce( 'wp_stream_generate_key' );
		?>
		<table class="form-table">
			<tr>
				<th><label for="<?php echo esc_attr( $this->user_feed_option_key ) ?>"><?php esc_html_e( 'Stream Feeds Key', 'stream' ) ?></label></th>
				<td>
					<p class="wp-stream-feeds-key">
						<?php wp_nonce_field( 'wp_stream_generate_key', 'wp_stream_generate_key_nonce' ) ?>
						<input type="text" name="<?php echo esc_attr( $this->user_feed_option_key ) ?>" id="<?php echo esc_attr( $this->user_feed_option_key ) ?>" class="regular-text code" value="<?php echo esc_attr( $key ) ?>" readonly>
						<small><a href="<?php echo esc_url( add_query_arg( array( self::GENERATE_KEY_QUERY_VAR => true, 'wp_stream_nonce' => $nonce ) ) ) ?>" id="<?php echo esc_attr( $this->user_feed_option_key ) ?>_generate"><?php esc_html_e( 'Generate new key', 'stream' ) ?></a></small>
						<span class="spinner" style="display: none;"></span>
					</p>
					<p class="description"><?php esc_html_e( 'This is your private key used for accessing feeds of Stream Records securely. You can change your key at any time by generating a new one using the link above.', 'stream' ) ?></p>
					<p class="wp-stream-feeds-links">
						<a href="<?php echo esc_url( add_query_arg( array( 'type' => 'rss' ), $link ) ) ?>" class="rss-feed" target="_blank"><?php esc_html_e( 'RSS Feed', 'stream' ) ?></a>
						|
						<a href="<?php echo esc_url( add_query_arg( array( 'type' => 'atom' ), $link ) ) ?>" class="atom-feed" target="_blank"><?php esc_html_e( 'ATOM Feed', 'stream' ) ?></a>
						|
						<a href="<?php echo esc_url( add_query_arg( array( 'type' => 'json' ), $link ) ) ?>" class="json-feed" target="_blank"><?php esc_html_e( 'JSON Feed', 'stream' ) ?></a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Return Stream Feed URL
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function get_user_feed_url( $key ) {
		$pretty_permalinks = get_option( 'permalink_structure' );
		$query_var         = self::FEED_QUERY_VAR;

		if ( empty( $pretty_permalinks ) ) {
			$link = add_query_arg(
				array(
					'feed'                   => $query_var,
					self::FEED_KEY_QUERY_VAR => $key,
				),
				home_url( '/' )
			);
		} else {
			$link = add_query_arg(
				array(
					self::FEED_KEY_QUERY_VAR => $key,
				),
				home_url(
					sprintf(
						'/feed/%s/',
						$query_var
					)
				)
			);
		}

		return $link;
	}

	/**
	 * Return the Profile admin page, with the feed settings highlighted
	 *
	 * @return string
	 */
	public function get_user_feed_settings_admin_url() {
		admin_url( sprintf( 'profile.php#wp-stream-highlight:%s', $this->user_feed_option_key ) );
	}

	/**
	 * Output for Stream Records as a feed.
	 *
	 * @return xml
	 */
	public function feed_template() {
		$die_title   = esc_html__( 'Access Denied', 'stream' );
		$die_message = sprintf( '<h1>%s</h1><p>%s</p>', $die_title, esc_html__( "You don't have permission to view this feed, please contact your site Administrator.", 'stream' ) );
		$query_var   = self::FEED_QUERY_VAR;

		$args = array(
			'meta_key'   => $this->user_feed_option_key,
			'meta_value' => filter_input( INPUT_GET, self::FEED_KEY_QUERY_VAR ),
			'number'     => 1,
		);
		$user = get_users( $args );

		if ( empty( $user ) ) {
			wp_die( $die_message, $die_title ); // xss ok
		}

		if ( ! is_super_admin( $user[0]->ID ) ) {
			$roles = isset( $user[0]->roles ) ? (array) $user[0]->roles : array();

			if ( ! $roles || ! array_intersect( $roles, $this->plugin->settings->options['general_role_access'] ) ) {
				wp_die( $die_message, $die_title ); // xss ok
			}
		}

		$args = array(
			'search'           => filter_input( INPUT_GET, 'search' ),
			'record_after'     => filter_input( INPUT_GET, 'record_after' ), // Deprecated, use date_after instead
			'date'             => filter_input( INPUT_GET, 'date' ),
			'date_from'        => filter_input( INPUT_GET, 'date_from' ),
			'date_to'          => filter_input( INPUT_GET, 'date_to' ),
			'date_after'       => filter_input( INPUT_GET, 'date_after' ),
			'date_before'      => filter_input( INPUT_GET, 'date_before' ),
			'record'           => filter_input( INPUT_GET, 'record' ),
			'record__in'       => filter_input( INPUT_GET, 'record__in' ),
			'record__not_in'   => filter_input( INPUT_GET, 'record__not_in' ),
			'records_per_page' => filter_input( INPUT_GET, 'records_per_page', FILTER_SANITIZE_NUMBER_INT ),
			'order'            => filter_input( INPUT_GET, 'order' ),
			'orderby'          => filter_input( INPUT_GET, 'orderby' ),
			'meta'             => filter_input( INPUT_GET, 'meta' ),
			'fields'           => filter_input( INPUT_GET, 'fields' ),
		);

		$properties = array(
			'author',
			'author_role',
			'ip',
			'object_id',
			'connector',
			'context',
			'action',
		);

		foreach ( $properties as $property ) {
			$args[ $property ]             = filter_input( INPUT_GET, $property );
			$args[ "{$property}__in" ]     = filter_input( INPUT_GET, "{$property}__in" );
			$args[ "{$property}__not_in" ] = filter_input( INPUT_GET, "{$property}__not_in" );
		}

		$records = $this->plugin->db->query->query( $args );

		$latest_record = isset( $records[0]->created ) ? $records[0]->created : null;

		$records_admin_url = add_query_arg(
			array(
				'page' => $this->plugin->admin->records_page_slug,
			),
			admin_url( $this->plugin->admin->admin_parent_page )
		);

		$latest_link = null;

		if ( isset( $records[0]->ID ) ) {
			$latest_link = add_query_arg(
				array(
					'record__in' => absint( $records[0]->ID ),
				),
				$records_admin_url
			);
		}

		$domain = parse_url( $records_admin_url, PHP_URL_HOST );
		$format = filter_input( INPUT_GET, self::FEED_TYPE_QUERY_VAR );

		if ( 'atom' === $format ) {
			require_once WP_STREAM_INC_DIR . 'feeds/atom.php';
		} elseif ( 'json' === $format ) {
			require_once WP_STREAM_INC_DIR . 'feeds/json.php';
		} else {
			require_once WP_STREAM_INC_DIR . 'feeds/rss-2.0.php';
		}

		exit;
	}

}
