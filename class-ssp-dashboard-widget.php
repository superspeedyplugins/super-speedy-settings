<?php
/**
 * The Super Speedy Plugins dashboard widget (settings 1.15.0).
 *
 * One box on the wp-admin dashboard, for administrators of sites running at least one PAID
 * Super Speedy plugin: offers, launches, upgrade prompts, speed figures and new guides, read
 * from a static JSON feed on superspeedyplugins.com.
 *
 * - One per site: only the newest settings core loads (super-speedy-settings.php), and
 *   bootstrap() guards against a second physical core loaded in an activation request.
 * - The dashboard never touches the network. The feed is fetched by WP-Cron, twice a day, with
 *   a 5 second timeout, as a bare GET: no query string, no cookies, and a generic user-agent
 *   instead of WordPress's default one, which carries the site URL. Nothing about the site,
 *   its plugins or its licence is sent.
 * - Targeting (show_if_active / hide_if_active) is decided here, from this site's own active
 *   plugin list, which never leaves the site.
 * - Faults surface: a failed fetch keeps the last good items and says so in the footer; an
 *   item that fails validation is skipped and counted in the footer.
 * - The wordpress.org editions never contain this file: their builds delete the whole
 *   super-speedy-settings submodule.
 *
 * Plan: superspeedy-plugin-meta/super-speedy-settings/.docs/2026-11-24-dashboard-widget.md
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

if ( ! class_exists( 'SSP_Dashboard_Widget', false ) ) {

	final class SSP_Dashboard_Widget {
		const FEED_URL   = 'https://www.superspeedyplugins.com/assets/ssp-feed/dashboard-v1.json';
		const SCHEMA     = 1;
		const OPTION     = 'ssp_dashboard_feed';
		const CRON_HOOK  = 'ssp_dashboard_feed_fetch';
		const WIDGET_ID  = 'ssp_dashboard_news';
		const USER_META  = 'ssp_dashboard_hide_offers';
		const USER_AGENT = 'SuperSpeedyDashboardFeed/1';
		const TIMEOUT    = 5;

		/**
		 * Registered Super Speedy slugs that are NOT a paid edition. A site whose only Super
		 * Speedy plugins are these gets no box: the free GitHub editions (Imports Lite, the
		 * plain Performance Analysis folder), the free plugins, and our own website code.
		 */
		const NOT_PAID_SLUGS = array(
			'super-speedy-imports-lite',
			'super-speedy-performance-analysis',
			'super-speedy-sitemaps',
			'fullscreen-typeform',
			'ssp-business-operations',
		);

		const TYPES        = array( 'offer', 'news', 'launch', 'upgrade' );
		const IMAGE_HOSTS  = array( 'www.superspeedyplugins.com' );
		const LINK_HOSTS   = array( 'www.superspeedyplugins.com', 'superspeedy.org' );
		const MAX_NEWS     = 5;

		private static $bootstrapped = false;

		public static function bootstrap() {
			if ( self::$bootstrapped ) {
				return;
			}
			self::$bootstrapped = true;
			add_action( self::CRON_HOOK, array( __CLASS__, 'fetch' ) );
			if ( is_admin() ) {
				add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
				add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
				add_action( 'admin_post_ssp_dashboard_offers', array( __CLASS__, 'handle_offers_toggle' ) );
			}
		}

		/** Folder slugs of the Super Speedy plugins that registered with the settings core. */
		public static function registered_slugs() {
			return class_exists( 'SuperSpeedySettingsCore', false ) ? SuperSpeedySettingsCore::registered_plugin_slugs() : array();
		}

		/** True when at least one registered Super Speedy plugin is a paid edition. */
		public static function paid_edition_present() {
			return array() !== array_diff( self::registered_slugs(), self::NOT_PAID_SLUGS );
		}

		public static function maybe_schedule() {
			if ( ! self::paid_edition_present() ) {
				if ( wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
				}
				return;
			}
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
			}
		}

		// ------------------------------------------------------------------ fetch (cron only)

		public static function state() {
			$state = get_option( self::OPTION, array() );
			$state = is_array( $state ) ? $state : array();
			return array_merge(
				array( 'fetched_at' => 0, 'last_attempt' => 0, 'last_error' => '', 'items' => array(), 'skipped' => array() ),
				$state
			);
		}

		public static function fetch() {
			if ( ! self::paid_edition_present() ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
				return;
			}
			$state                 = self::state();
			$state['last_attempt'] = time();
			$response              = wp_safe_remote_get( self::FEED_URL, array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array( 'Accept' => 'application/json' ),
				'cookies'     => array(),
			) );
			$error = self::response_error( $response );
			if ( '' === $error ) {
				$feed = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! is_array( $feed ) || ! isset( $feed['items'] ) || ! is_array( $feed['items'] ) ) {
					$error = 'the feed is not valid JSON with an items list';
				} elseif ( (int) ( $feed['schema'] ?? 0 ) !== self::SCHEMA ) {
					$error = 'the feed uses schema ' . (int) ( $feed['schema'] ?? 0 ) . ', this site reads schema ' . self::SCHEMA;
				}
			}
			if ( '' !== $error ) {
				$state['last_error'] = $error;
				update_option( self::OPTION, $state, false );
				return;
			}
			$items   = array();
			$skipped = array();
			foreach ( $feed['items'] as $index => $raw ) {
				$item = self::validate_item( $raw );
				if ( is_wp_error( $item ) ) {
					$skipped[] = array( 'id' => is_array( $raw ) && isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '#' . $index, 'reason' => $item->get_error_message() );
					continue;
				}
				$items[] = $item;
			}
			$state['items']      = $items;
			$state['skipped']    = $skipped;
			$state['fetched_at'] = time();
			$state['last_error'] = '';
			update_option( self::OPTION, $state, false );
		}

		private static function response_error( $response ) {
			if ( is_wp_error( $response ) ) {
				return $response->get_error_message();
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			return 200 === $code ? '' : 'HTTP ' . $code;
		}

		/** Exact-host https URL check. */
		private static function url_on( $url, array $hosts ) {
			$parts = wp_parse_url( (string) $url );
			return is_array( $parts )
				&& 'https' === ( $parts['scheme'] ?? '' )
				&& in_array( strtolower( $parts['host'] ?? '' ), $hosts, true )
				&& ! isset( $parts['user'] ) && ! isset( $parts['port'] );
		}

		private static function text( $raw, $key, $max ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
			return '' === $value ? '' : ( mb_strlen( $value ) > $max ? new WP_Error( 'ssp_feed_item', $key . ' is longer than ' . $max . ' characters' ) : $value );
		}

		private static function slugs( $raw, $key ) {
			$list = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			return array_values( array_filter( array_map( static function ( $slug ) {
				return is_scalar( $slug ) ? sanitize_key( (string) $slug ) : '';
			}, $list ) ) );
		}

		private static function time_field( $raw, $key ) {
			if ( empty( $raw[ $key ] ) ) {
				return 0;
			}
			$time = is_string( $raw[ $key ] ) ? strtotime( $raw[ $key ] ) : false;
			return false === $time ? new WP_Error( 'ssp_feed_item', $key . ' is not a date' ) : $time;
		}

		/** One feed item -> a clean item, or WP_Error naming why it was skipped. */
		public static function validate_item( $raw ) {
			if ( ! is_array( $raw ) ) {
				return new WP_Error( 'ssp_feed_item', 'not an object' );
			}
			$id = isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
			if ( '' === $id ) {
				return new WP_Error( 'ssp_feed_item', 'no id' );
			}
			$type = isset( $raw['type'] ) && is_string( $raw['type'] ) ? $raw['type'] : '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				return new WP_Error( 'ssp_feed_item', 'unknown type' );
			}
			$item = array( 'id' => $id, 'type' => $type );
			foreach ( array( 'title' => 120, 'text' => 300, 'button' => 40, 'label' => 24 ) as $key => $max ) {
				$value = self::text( $raw, $key, $max );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$item[ $key ] = $value;
			}
			if ( '' === $item['title'] ) {
				return new WP_Error( 'ssp_feed_item', 'no title' );
			}
			if ( ! self::url_on( $raw['url'] ?? '', self::LINK_HOSTS ) ) {
				return new WP_Error( 'ssp_feed_item', 'url is not on superspeedyplugins.com or superspeedy.org' );
			}
			$item['url']           = (string) $raw['url'];
			$item['image']         = '';
			$item['image_refused'] = false;
			if ( ! empty( $raw['image'] ) ) {
				if ( self::url_on( $raw['image'], self::IMAGE_HOSTS ) ) {
					$item['image'] = (string) $raw['image'];
				} else {
					$item['image_refused'] = true;
				}
			}
			foreach ( array( 'start', 'end' ) as $key ) {
				$time = self::time_field( $raw, $key );
				if ( is_wp_error( $time ) ) {
					return $time;
				}
				$item[ $key ] = $time;
			}
			$item['priority']       = isset( $raw['priority'] ) ? (int) $raw['priority'] : 0;
			$item['show_if_active'] = self::slugs( $raw, 'show_if_active' );
			$item['hide_if_active'] = self::slugs( $raw, 'hide_if_active' );
			$item['figure']         = null;
			if ( isset( $raw['figure'] ) && is_array( $raw['figure'] ) ) {
				$f      = $raw['figure'];
				$before = isset( $f['before'] ) && is_numeric( $f['before'] ) ? (float) $f['before'] : 0.0;
				$after  = isset( $f['after'] ) && is_numeric( $f['after'] ) ? (float) $f['after'] : 0.0;
				$label  = self::text( $f, 'label', 80 );
				$unit   = self::text( $f, 'unit', 8 );
				$before_label = self::text( $f, 'before_label', 24 );
				$after_label  = self::text( $f, 'after_label', 24 );
				if ( is_wp_error( $label ) || is_wp_error( $unit ) || is_wp_error( $before_label ) || is_wp_error( $after_label )
					|| '' === $label || $before <= 0 || $after <= 0 || $after >= $before ) {
					return new WP_Error( 'ssp_feed_item', 'figure needs a label, short labels, and an after smaller than before' );
				}
				$item['figure'] = array(
					'label'        => $label,
					'unit'         => $unit,
					'before'       => $before,
					'after'        => $after,
					'before_label' => '' !== $before_label ? $before_label : 'Before',
					'after_label'  => '' !== $after_label ? $after_label : 'After',
				);
			}
			$item['products'] = array();
			if ( isset( $raw['products'] ) && is_array( $raw['products'] ) ) {
				foreach ( array_slice( $raw['products'], 0, 6 ) as $product ) {
					$name = is_array( $product ) ? self::text( $product, 'name', 40 ) : '';
					if ( is_wp_error( $name ) || '' === $name ) {
						return new WP_Error( 'ssp_feed_item', 'a product has no name' );
					}
					$image = ! empty( $product['image'] ) && self::url_on( $product['image'], self::IMAGE_HOSTS ) ? (string) $product['image'] : '';
					if ( ! empty( $product['image'] ) && '' === $image ) {
						$item['image_refused'] = true;
					}
					$item['products'][] = array( 'name' => $name, 'image' => $image );
				}
			}
			return $item;
		}

		// ------------------------------------------------------------------ render (no network)

		public static function can_see() {
			return current_user_can( 'manage_options' ) && self::paid_edition_present();
		}

		public static function register() {
			if ( ! self::can_see() ) {
				return;
			}
			wp_add_dashboard_widget( self::WIDGET_ID, 'Super Speedy Plugins', array( __CLASS__, 'render' ), null, null, 'normal', 'high' );
		}

		public static function enqueue( $hook ) {
			if ( 'index.php' !== $hook || ! self::can_see() ) {
				return;
			}
			$dir = plugin_dir_url( __FILE__ );
			wp_enqueue_style( 'ssp-dashboard-widget', $dir . 'dashboard-widget.css', array(), SuperSpeedySettingsCore::SETTINGS_VERSION );
			wp_enqueue_script( 'ssp-dashboard-widget', $dir . 'dashboard-widget.js', array(), SuperSpeedySettingsCore::SETTINGS_VERSION, true );
		}

		/** Folder slugs of every active plugin on this site (and network-active on multisite). */
		private static function active_slugs() {
			$files = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			$slugs = array();
			foreach ( $files as $file ) {
				$slugs[] = sanitize_key( false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ) );
			}
			return array_unique( $slugs );
		}

		/** The items to show now, in display order, for this site and this user. */
		public static function visible_items( array $items, $now, array $active, $hide_offers ) {
			$live = array();
			foreach ( $items as $item ) {
				if ( ( $item['start'] && $now < $item['start'] ) || ( $item['end'] && $now > $item['end'] ) ) {
					continue;
				}
				if ( $item['show_if_active'] && ! array_intersect( $item['show_if_active'], $active ) ) {
					continue;
				}
				if ( $item['hide_if_active'] && array_intersect( $item['hide_if_active'], $active ) ) {
					continue;
				}
				if ( $hide_offers && in_array( $item['type'], array( 'offer', 'launch' ), true ) ) {
					continue;
				}
				$live[] = $item;
			}
			usort( $live, static function ( $a, $b ) {
				return array( $b['priority'], $b['start'] ) <=> array( $a['priority'], $a['start'] );
			} );
			return $live;
		}

		public static function hides_offers( $user_id = 0 ) {
			return (bool) get_user_meta( $user_id ?: get_current_user_id(), self::USER_META, true );
		}

		public static function handle_offers_toggle() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'super-speedy-settings' ), 403 );
			}
			check_admin_referer( 'ssp_dashboard_offers' );
			$hide = isset( $_POST['hide'] ) && '1' === sanitize_key( wp_unslash( $_POST['hide'] ) );
			if ( $hide ) {
				update_user_meta( get_current_user_id(), self::USER_META, 1 );
			} else {
				delete_user_meta( get_current_user_id(), self::USER_META );
			}
			wp_safe_redirect( admin_url( 'index.php#' . self::WIDGET_ID ) );
			exit;
		}

		private static function link_attrs( $url ) {
			return 'href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"';
		}

		public static function render() {
			$state = self::state();
			$hide  = self::hides_offers();
			$items = self::visible_items( $state['items'], time(), self::active_slugs(), $hide );

			$hero = $upgrades = $figures = $launches = $news = array();
			foreach ( $items as $item ) {
				if ( 'offer' === $item['type'] && ! $hero ) {
					$hero = $item;
				} elseif ( 'upgrade' === $item['type'] && count( $upgrades ) < 2 ) {
					$upgrades[] = $item;
				} elseif ( 'news' === $item['type'] && $item['figure'] && ! $figures ) {
					$figures[] = $item;
				} elseif ( 'launch' === $item['type'] && ! $launches ) {
					$launches[] = $item;
				} elseif ( 'news' === $item['type'] && count( $news ) < self::MAX_NEWS ) {
					$news[] = $item;
				}
			}

			echo '<div class="ssw">';
			if ( ! $state['fetched_at'] ) {
				$next = wp_next_scheduled( self::CRON_HOOK );
				echo '<p class="ssw-empty">' . esc_html__( 'Super Speedy news will appear here after the first check.', 'super-speedy-settings' );
				if ( $next ) {
					/* translators: %s: time until the next check, e.g. "5 mins" */
					echo ' ' . esc_html( sprintf( __( 'The next check is due in %s.', 'super-speedy-settings' ), human_time_diff( time(), max( time() + 60, $next ) ) ) );
				}
				echo '</p>';
			} elseif ( ! $items ) {
				echo '<p class="ssw-empty">' . esc_html__( 'Nothing new from Super Speedy Plugins right now.', 'super-speedy-settings' ) . '</p>';
			}
			if ( $hero ) {
				self::render_hero( $hero );
			}
			foreach ( $upgrades as $item ) {
				self::render_upgrade( $item );
			}
			foreach ( $figures as $item ) {
				self::render_figure( $item );
			}
			foreach ( $launches as $item ) {
				self::render_launch( $item );
			}
			if ( $news ) {
				self::render_news( $news );
			}
			self::render_footer( $state, $hide );
			echo '</div>';
		}

		private static function render_hero( array $item ) {
			$label = '' !== $item['label'] ? $item['label'] : __( 'Offer', 'super-speedy-settings' );
			echo '<a class="ssw-hero" ' . self::link_attrs( $item['url'] ) . '>';
			echo '<svg class="ssw-spark-frame" viewBox="0 0 500 260" preserveAspectRatio="none" aria-hidden="true"><rect x="1" y="1" width="498" height="258" rx="16" pathLength="1000" class="ssw-spark"/></svg>';
			echo '<svg class="ssw-ghost" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12.6V4a1 1 0 0 1 1-1h8.6a1 1 0 0 1 .7.3l7.4 7.4a1 1 0 0 1 0 1.4l-8.6 8.6a1 1 0 0 1-1.4 0L3.3 13.3a1 1 0 0 1-.3-.7z"/><circle cx="7.5" cy="7.5" r="1.4"/><path d="m9.5 15.5 5-5M10 11h.01M14 15h.01"/></svg>';
			echo '<span class="ssw-hero-top"><span class="ssw-eyebrow"><span class="ssw-live" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
			if ( $item['image'] ) {
				echo '<img class="ssw-hero-img ssw-tile" src="' . esc_url( $item['image'] ) . '" alt="" width="56" height="56" loading="lazy" referrerpolicy="no-referrer">';
			}
			echo '</span>';
			echo '<span class="ssw-hero-title">' . esc_html( $item['title'] ) . '</span>';
			echo '<span class="ssw-line" aria-hidden="true"></span>';
			if ( '' !== $item['text'] ) {
				echo '<span class="ssw-hero-text">' . esc_html( $item['text'] ) . '</span>';
			}
			if ( $item['end'] && $item['end'] - time() < 14 * DAY_IN_SECONDS ) {
				echo '<span class="ssw-countdown" data-ssw-end="' . esc_attr( (string) $item['end'] ) . '" role="timer" aria-label="' . esc_attr( sprintf( /* translators: %s: date and time */ __( 'Ends %s', 'super-speedy-settings' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['end'] ) ) ) . '">';
				foreach ( array( 'd' => __( 'days', 'super-speedy-settings' ), 'h' => __( 'hours', 'super-speedy-settings' ), 'm' => __( 'mins', 'super-speedy-settings' ), 's' => __( 'secs', 'super-speedy-settings' ) ) as $unit => $name ) {
					echo '<span class="ssw-cd"><span class="ssw-cd-v" data-ssw-unit="' . esc_attr( $unit ) . '">--</span><span class="ssw-cd-u">' . esc_html( $name ) . '</span></span>';
				}
				echo '</span>';
			}
			$button = '' !== $item['button'] ? $item['button'] : __( 'See the offer', 'super-speedy-settings' );
			echo '<span class="ssw-btn">' . esc_html( $button ) . ' <span aria-hidden="true">&rarr;</span></span>';
			echo '</a>';
		}

		private static function render_upgrade( array $item ) {
			$label = '' !== $item['label'] ? $item['label'] : __( 'Upgrade for this site', 'super-speedy-settings' );
			echo '<div class="ssw-card ssw-upgrade">';
			if ( $item['image'] ) {
				echo '<img class="ssw-icon ssw-tile" src="' . esc_url( $item['image'] ) . '" alt="" width="44" height="44" loading="lazy" referrerpolicy="no-referrer">';
			}
			echo '<div class="ssw-body"><span class="ssw-chip ssw-chip-cyan">' . esc_html( $label ) . '</span>';
			echo '<div class="ssw-card-title">' . esc_html( $item['title'] ) . '</div>';
			if ( '' !== $item['text'] ) {
				echo '<p class="ssw-card-text">' . esc_html( $item['text'] ) . '</p>';
			}
			$button = '' !== $item['button'] ? $item['button'] : __( 'Find out more', 'super-speedy-settings' );
			echo '<a class="ssw-more" ' . self::link_attrs( $item['url'] ) . '>' . esc_html( $button ) . ' &rarr;</a>';
			if ( $item['show_if_active'] ) {
				echo '<p class="ssw-why">' . esc_html__( 'Shown because of a plugin active on this site. Worked out here; nothing was sent.', 'super-speedy-settings' ) . '</p>';
			}
			echo '</div></div>';
		}

		private static function render_figure( array $item ) {
			$f     = $item['figure'];
			$width = max( 2, min( 100, round( $f['after'] / $f['before'] * 100, 1 ) ) );
			$fmt   = static function ( $value ) use ( $f ) {
				return trim( rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ) . ' ' . $f['unit'] );
			};
			$label = '' !== $item['label'] ? $item['label'] : __( 'Speed figure', 'super-speedy-settings' );
			echo '<a class="ssw-card ssw-figure" ' . self::link_attrs( $item['url'] ) . '>';
			echo '<span class="ssw-chip ssw-chip-purple">' . esc_html( $label ) . '</span>';
			echo '<span class="ssw-card-title">' . esc_html( $item['title'] ) . '</span>';
			echo '<span class="ssw-race" role="img" aria-label="' . esc_attr( $f['label'] . ': ' . $f['before_label'] . ' ' . $fmt( $f['before'] ) . ', ' . $f['after_label'] . ' ' . $fmt( $f['after'] ) ) . '">';
			echo '<span class="ssw-race-l">' . esc_html( $f['before_label'] ) . '</span><span class="ssw-track"><span class="ssw-bar ssw-slow"></span></span><span class="ssw-race-t ssw-t-slow">' . esc_html( $fmt( $f['before'] ) ) . '</span>';
			echo '<span class="ssw-race-l ssw-race-ours">' . esc_html( $f['after_label'] ) . '</span><span class="ssw-track"><span class="ssw-bar ssw-fast" style="width:' . esc_attr( (string) $width ) . '%"></span></span><span class="ssw-race-t ssw-t-fast"><span class="ssw-pop">' . esc_html( $fmt( $f['after'] ) ) . '</span></span>';
			echo '</span>';
			$button = '' !== $item['button'] ? $item['button'] : __( 'Read the case study', 'super-speedy-settings' );
			echo '<span class="ssw-more">' . esc_html( $button ) . ' &rarr;</span>';
			echo '</a>';
		}

		private static function render_launch( array $item ) {
			$label = '' !== $item['label'] ? $item['label'] : __( 'Coming soon', 'super-speedy-settings' );
			echo '<a class="ssw-card ssw-launch" ' . self::link_attrs( $item['url'] ) . '>';
			echo '<span class="ssw-chip ssw-chip-orange">' . esc_html( $label ) . '</span>';
			echo '<span class="ssw-card-title">' . esc_html( $item['title'] ) . '</span>';
			if ( '' !== $item['text'] ) {
				echo '<span class="ssw-card-text">' . esc_html( $item['text'] ) . '</span>';
			}
			if ( $item['products'] ) {
				echo '<span class="ssw-tiles">';
				foreach ( $item['products'] as $product ) {
					echo '<span class="ssw-tilebox">';
					if ( $product['image'] ) {
						echo '<img class="ssw-tile" src="' . esc_url( $product['image'] ) . '" alt="" width="48" height="48" loading="lazy" referrerpolicy="no-referrer">';
					} else {
						$initials = '';
						foreach ( preg_split( '/\s+/', $product['name'] ) as $word ) {
							$initials .= mb_substr( $word, 0, 1 );
						}
						echo '<span class="ssw-tile ssw-tile-blank" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $initials, -2 ) ) ) . '</span>';
					}
					echo '<span class="ssw-tilename">' . esc_html( $product['name'] ) . '</span></span>';
				}
				echo '</span>';
			}
			echo '</a>';
		}

		private static function render_news( array $news ) {
			echo '<div class="ssw-card ssw-news"><div class="ssw-news-head">' . esc_html__( 'New from Super Speedy Plugins', 'super-speedy-settings' ) . '</div>';
			foreach ( $news as $item ) {
				$label = '' !== $item['label'] ? $item['label'] : __( 'News', 'super-speedy-settings' );
				$tone  = array( 'case study' => 'green', 'guide' => 'cyan', 'kb' => 'cyan' );
				$tone  = $tone[ strtolower( $label ) ] ?? 'purple';
				echo '<a class="ssw-row" ' . self::link_attrs( $item['url'] ) . '>';
				echo '<span class="ssw-chip ssw-chip-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
				echo '<span class="ssw-row-body"><span class="ssw-row-title">' . esc_html( $item['title'] ) . '</span>';
				if ( $item['start'] ) {
					echo '<span class="ssw-row-date">' . esc_html( wp_date( 'j F', $item['start'] ) ) . '</span>';
				}
				echo '</span></a>';
			}
			echo '</div>';
		}

		private static function render_footer( array $state, $hide ) {
			echo '<div class="ssw-foot"><span class="ssw-status">';
			if ( $state['fetched_at'] ) {
				/* translators: %s: time since the last successful check, e.g. "2 hours" */
				echo esc_html( sprintf( __( 'Updated %s ago.', 'super-speedy-settings' ), human_time_diff( $state['fetched_at'], time() ) ) );
			}
			if ( '' !== $state['last_error'] && $state['last_attempt'] ) {
				/* translators: 1: time since the failed check, 2: the error */
				echo ' <span class="ssw-error">' . esc_html( sprintf( __( 'The check %1$s ago failed: %2$s.', 'super-speedy-settings' ), human_time_diff( $state['last_attempt'], time() ), $state['last_error'] ) ) . '</span>';
			}
			$refused = 0;
			foreach ( $state['items'] as $item ) {
				$refused += $item['image_refused'] ? 1 : 0;
			}
			$skipped = count( $state['skipped'] );
			if ( $skipped || $refused ) {
				/* translators: 1: number of skipped items, 2: number of items shown without an image */
				echo ' <span class="ssw-error">' . esc_html( sprintf( __( '%1$d feed items skipped and %2$d shown without an off-site image.', 'super-speedy-settings' ), $skipped, $refused ) ) . '</span>';
			}
			echo '</span><span class="ssw-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ssp_dashboard_offers"><input type="hidden" name="hide" value="' . ( $hide ? '0' : '1' ) . '">';
			wp_nonce_field( 'ssp_dashboard_offers' );
			echo '<button type="submit" class="ssw-toggle" aria-pressed="' . ( $hide ? 'true' : 'false' ) . '">' . esc_html( $hide ? __( 'Show offers', 'super-speedy-settings' ) : __( 'Hide offers', 'super-speedy-settings' ) ) . '</button></form>';
			echo '<a ' . self::link_attrs( 'https://www.superspeedyplugins.com/blog/' ) . '>' . esc_html__( 'Blog', 'super-speedy-settings' ) . '</a>';
			echo '<a ' . self::link_attrs( 'https://www.superspeedyplugins.com/kb/' ) . '>' . esc_html__( 'Knowledge base', 'super-speedy-settings' ) . '</a>';
			echo '</span></div>';
		}
	}
}
