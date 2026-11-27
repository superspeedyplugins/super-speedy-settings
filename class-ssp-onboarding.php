<?php
/**
 * The Super Speedy screen's onboarding and sales pages, the deals bubble and the trial key
 * pick-up (settings 1.15.3).
 *
 * - Pages, all inside the existing Super Speedy menu (admin.php?page=superspeedy&tab=...):
 *   Your plugins (the licence screen, with licence chips), For this site (recommendations worked
 *   out here), one sales page per plugin, the pack, Request a free trial, and Deals.
 * - Content comes from a static catalogue feed fetched by WP-Cron twice a day, exactly like the
 *   dashboard widget's feed: a bare GET with a generic user-agent, nothing about the site sent,
 *   faults kept and shown, the last good copy kept. No page in wp-admin ever fetches it.
 * - Recommendations are computed from this site's own facts (WooCommerce, product count, active
 *   plugins, cache plugin, page builder). They never leave the site.
 * - The trial request form is the only thing here that sends anything. It says exactly what it
 *   sends and sends it only when the administrator presses Submit.
 * - The deals bubble counts the dashboard feed's offer and launch items this user has not seen,
 *   in the brand purple, beside WordPress's own red update bubble. Opening Deals clears it.
 * - A free-trial download carries <slug>/ssp-license-key.php. On an admin load with no licence
 *   key stored, the key is stored, the pick-up recorded in ssp_trial_from_zip, and the file
 *   deleted.
 *
 * Plan: superspeedy-plugin-meta/super-speedy-settings/.docs/2026-11-27-onboarding-trials-and-in-plugin-sales.md
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

if ( ! class_exists( 'SSP_Onboarding', false ) ) {

	final class SSP_Onboarding {
		const FEED_URL     = 'https://www.superspeedyplugins.com/assets/ssp-feed/catalogue-v1.json';
		const SCHEMA       = 1;
		const OPTION       = 'ssp_catalogue_feed';
		const CRON_HOOK    = 'ssp_catalogue_feed_fetch';
		const USER_AGENT   = 'SuperSpeedyCatalogueFeed/1';
		const TIMEOUT      = 10;
		const TRIAL_URL    = 'https://www.superspeedyplugins.com/wp-json/ssp-trials/v1/request';
		const TRIAL_UA     = 'SuperSpeedyTrialRequest/1';
		const TRIAL_DAYS   = 14;
		const SEEN_META    = 'ssp_deals_seen';
		const KEY_FILE     = 'ssp-license-key.php';
		const TRIAL_OPTION = 'ssp_trial_from_zip';
		const PAGE         = 'superspeedy';
		const DEALS_PAGE   = 'superspeedy-deals';
		const BUBBLE       = '#8035be';
		const MEDIA_HOSTS  = array( 'www.superspeedyplugins.com', 'videos.superspeedyplugins.com' );
		const LINK_HOSTS   = array( 'www.superspeedyplugins.com', 'superspeedy.org' );
		const TABS         = array( 'site', 'catalogue', 'pack', 'trial', 'deals' );

		/** Folder slugs of cache plugins, for the site facts. */
		const CACHE_PLUGINS = array(
			'wp-rocket'               => 'WP Rocket',
			'litespeed-cache'         => 'LiteSpeed Cache',
			'w3-total-cache'          => 'W3 Total Cache',
			'wp-super-cache'          => 'WP Super Cache',
			'wp-fastest-cache'        => 'WP Fastest Cache',
			'sg-cachepress'           => 'SiteGround Speed Optimizer',
			'breeze'                  => 'Breeze',
			'cache-enabler'           => 'Cache Enabler',
			'comet-cache'             => 'Comet Cache',
			'hummingbird-performance' => 'Hummingbird',
			'nginx-helper'            => 'Nginx Helper',
			'wp-optimize'             => 'WP-Optimize',
			'swift-performance-lite'  => 'Swift Performance',
			'powered-cache'           => 'Powered Cache',
			'flying-press'            => 'FlyingPress',
		);

		/** Folder slugs of page builder plugins, and theme folders that are builders. */
		const BUILDERS = array(
			'elementor'                  => 'Elementor',
			'beaver-builder-lite-version' => 'Beaver Builder',
			'bb-plugin'                  => 'Beaver Builder',
			'divi-builder'               => 'Divi',
			'js_composer'                => 'WPBakery',
			'oxygen'                     => 'Oxygen',
			'breakdance'                 => 'Breakdance',
			'brizy'                      => 'Brizy',
		);
		const BUILDER_THEMES = array( 'Divi' => 'Divi', 'bricks' => 'Bricks', 'Avada' => 'Avada' );

		private static $bootstrapped = false;
		private static $deals_hook   = '';

		public static function bootstrap() {
			if ( self::$bootstrapped ) {
				return;
			}
			self::$bootstrapped = true;
			add_action( self::CRON_HOOK, array( __CLASS__, 'fetch' ) );
			if ( is_admin() ) {
				add_action( 'admin_init', array( __CLASS__, 'pick_up_key' ), 1 );
				add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
				// After the settings core has placed its update counts (999).
				add_action( 'admin_menu', array( __CLASS__, 'menu' ), 1000 );
				add_action( 'admin_head', array( __CLASS__, 'bubble_style' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
				add_action( 'admin_post_ssp_trial_request', array( __CLASS__, 'handle_trial_request' ) );
				add_filter( 'admin_referrer_policy', array( __CLASS__, 'referrer_policy' ) );
			}
		}

		// ------------------------------------------------------------------ helpers

		/** Is this request one of our screens? */
		public static function on_screen() {
			global $pagenow;
			if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) ) {
				return false;
			}
			$page = sanitize_key( wp_unslash( $_GET['page'] ) );
			return self::PAGE === $page || self::DEALS_PAGE === $page;
		}

		/** The tab asked for: '' is Your plugins. */
		public static function current_tab() {
			if ( isset( $_GET['page'] ) && self::DEALS_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
				return 'deals';
			}
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			return in_array( $tab, self::TABS, true ) ? $tab : '';
		}

		public static function tab_url( $tab = '', array $args = array() ) {
			if ( 'deals' === $tab ) {
				return add_query_arg( $args, admin_url( 'admin.php?page=' . self::DEALS_PAGE ) );
			}
			$base = admin_url( 'admin.php?page=' . self::PAGE );
			if ( '' !== $tab ) {
				$args = array_merge( array( 'tab' => $tab ), $args );
			}
			return add_query_arg( $args, $base );
		}

		/**
		 * Media on our screens is loaded from our own hosts. Send no referrer, so loading a poster
		 * or a video does not name this site (WordPress's default sends the origin).
		 */
		public static function referrer_policy( $policy ) {
			return self::on_screen() ? 'no-referrer' : $policy;
		}

		/** Exact-host https URL check. */
		private static function url_on( $url, array $hosts ) {
			$parts = wp_parse_url( (string) $url );
			return is_array( $parts )
				&& 'https' === ( $parts['scheme'] ?? '' )
				&& in_array( strtolower( $parts['host'] ?? '' ), $hosts, true )
				&& ! isset( $parts['user'] ) && ! isset( $parts['port'] );
		}

		/** A trimmed string no longer than $max, '' when missing, WP_Error when too long. */
		private static function text( $raw, $key, $max ) {
			$value = is_array( $raw ) && isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
			return mb_strlen( $value ) > $max ? new WP_Error( 'ssp_catalogue', $key . ' is longer than ' . $max . ' characters' ) : $value;
		}

		/** Folder slugs of every active plugin (and network-active plugin) on this site. */
		public static function active_slugs() {
			$files = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			$slugs = array();
			foreach ( $files as $file ) {
				$file    = (string) $file;
				$slugs[] = sanitize_key( false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ) );
			}
			return array_values( array_unique( $slugs ) );
		}

		/** Super Speedy plugins registered with the settings core (folder slugs). */
		private static function registered_slugs() {
			// This file is only loaded by the settings core, so its registry is the list.
			return class_exists( 'SuperSpeedySettingsCore', false ) ? SuperSpeedySettingsCore::registered_plugin_slugs() : array();
		}

		public static function is_installed( $slug ) {
			return '' !== $slug && ( in_array( $slug, self::registered_slugs(), true ) || is_dir( WP_PLUGIN_DIR . '/' . $slug ) );
		}

		// ------------------------------------------------------------------ catalogue fetch (cron only)

		public static function maybe_schedule() {
			$paid = ! class_exists( 'SSP_Dashboard_Widget', false ) || SSP_Dashboard_Widget::paid_edition_present();
			if ( ! $paid ) {
				if ( wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
				}
				return;
			}
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
			}
		}

		public static function state() {
			$state = get_option( self::OPTION, array() );
			$state = is_array( $state ) ? $state : array();
			return array_merge(
				array( 'fetched_at' => 0, 'last_attempt' => 0, 'last_error' => '', 'generated' => '', 'currency' => '', 'plugins' => array(), 'packs' => array(), 'skipped' => array() ),
				$state
			);
		}

		public static function fetch() {
			$state                 = self::state();
			$state['last_attempt'] = time();
			$response              = wp_safe_remote_get( self::FEED_URL, array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array( 'Accept' => 'application/json' ),
				'cookies'     => array(),
			) );
			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
			} else {
				$code  = (int) wp_remote_retrieve_response_code( $response );
				$error = 200 === $code ? '' : 'HTTP ' . $code;
			}
			$feed = null;
			if ( '' === $error ) {
				$feed = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! is_array( $feed ) || ! isset( $feed['plugins'] ) || ! is_array( $feed['plugins'] ) ) {
					$error = 'the catalogue is not valid JSON with a plugins list';
				} elseif ( (int) ( $feed['schema'] ?? 0 ) !== self::SCHEMA ) {
					$error = 'the catalogue uses schema ' . (int) ( $feed['schema'] ?? 0 ) . ', this site reads schema ' . self::SCHEMA;
				}
			}
			if ( '' !== $error ) {
				$state['last_error'] = $error;
				update_option( self::OPTION, $state, false );
				return;
			}
			$clean = self::validate_catalogue( $feed );
			$state = array_merge( $state, $clean, array( 'fetched_at' => time(), 'last_error' => '' ) );
			update_option( self::OPTION, $state, false );
		}

		/** A decoded catalogue -> the clean parts this site keeps, with what was skipped and why. */
		public static function validate_catalogue( array $feed ) {
			$plugins = $packs = $skipped = array();
			foreach ( $feed['plugins'] as $index => $raw ) {
				$plugin = self::validate_plugin( $raw );
				if ( is_wp_error( $plugin ) ) {
					$skipped[] = ( is_array( $raw ) && isset( $raw['slug'] ) && is_scalar( $raw['slug'] ) ? sanitize_key( (string) $raw['slug'] ) : '#' . $index ) . ': ' . $plugin->get_error_message();
					continue;
				}
				$plugins[ $plugin['slug'] ] = $plugin;
			}
			foreach ( ( isset( $feed['packs'] ) && is_array( $feed['packs'] ) ? $feed['packs'] : array() ) as $index => $raw ) {
				$pack = self::validate_pack( $raw );
				if ( is_wp_error( $pack ) ) {
					$skipped[] = 'pack #' . $index . ': ' . $pack->get_error_message();
					continue;
				}
				$packs[] = $pack;
			}
			$currency = isset( $feed['currency'] ) && is_string( $feed['currency'] ) && preg_match( '/^[A-Z]{3}$/', $feed['currency'] ) ? $feed['currency'] : '';
			$generated = isset( $feed['generated'] ) && is_string( $feed['generated'] ) ? substr( sanitize_text_field( $feed['generated'] ), 0, 40 ) : '';
			return array( 'plugins' => $plugins, 'packs' => $packs, 'skipped' => $skipped, 'currency' => $currency, 'generated' => $generated );
		}

		public static function validate_plugin( $raw ) {
			if ( ! is_array( $raw ) ) {
				return new WP_Error( 'ssp_catalogue', 'not an object' );
			}
			$slug = isset( $raw['slug'] ) && is_scalar( $raw['slug'] ) ? sanitize_key( (string) $raw['slug'] ) : '';
			if ( '' === $slug ) {
				return new WP_Error( 'ssp_catalogue', 'no slug' );
			}
			$plugin = array( 'slug' => $slug, 'refused' => 0 );
			foreach ( array( 'name' => 80, 'headline' => 160, 'sub' => 500, 'problem' => 1000 ) as $key => $max ) {
				$value = self::text( $raw, $key, $max );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$plugin[ $key ] = $value;
			}
			if ( '' === $plugin['name'] ) {
				return new WP_Error( 'ssp_catalogue', 'no name' );
			}
			if ( ! self::url_on( $raw['product_url'] ?? '', self::LINK_HOSTS ) ) {
				return new WP_Error( 'ssp_catalogue', 'product_url is not on superspeedyplugins.com' );
			}
			$plugin['product_url'] = (string) $raw['product_url'];
			$plugin['kb_url']      = self::url_on( $raw['kb_url'] ?? '', self::LINK_HOSTS ) ? (string) $raw['kb_url'] : '';
			foreach ( array( 'hero_mp4', 'hero_poster' ) as $key ) {
				$plugin[ $key ] = '';
				if ( ! empty( $raw[ $key ] ) ) {
					if ( self::url_on( $raw[ $key ], self::MEDIA_HOSTS ) ) {
						$plugin[ $key ] = (string) $raw[ $key ];
					} else {
						$plugin['refused']++;
					}
				}
			}
			$plugin['screenshots'] = array();
			foreach ( array_slice( isset( $raw['screenshots'] ) && is_array( $raw['screenshots'] ) ? $raw['screenshots'] : array(), 0, 12 ) as $shot ) {
				$caption = self::text( $shot, 'caption', 240 );
				if ( ! is_array( $shot ) || ! self::url_on( $shot['url'] ?? '', self::MEDIA_HOSTS ) || is_wp_error( $caption ) ) {
					$plugin['refused']++;
					continue;
				}
				$plugin['screenshots'][] = array( 'url' => (string) $shot['url'], 'caption' => $caption );
			}
			// A measured claim is only shown with its source. One without a usable source is dropped.
			$plugin['claims'] = array();
			foreach ( array_slice( isset( $raw['claims'] ) && is_array( $raw['claims'] ) ? $raw['claims'] : array(), 0, 8 ) as $claim ) {
				$figure = self::text( $claim, 'figure', 40 );
				$label  = self::text( $claim, 'label', 240 );
				if ( ! is_array( $claim ) || is_wp_error( $figure ) || is_wp_error( $label ) || '' === $figure || '' === $label || ! self::url_on( $claim['source_url'] ?? '', self::LINK_HOSTS ) ) {
					$plugin['refused']++;
					continue;
				}
				$plugin['claims'][] = array( 'figure' => $figure, 'label' => $label, 'source_url' => (string) $claim['source_url'] );
			}
			$plugin['features'] = array();
			foreach ( array_slice( isset( $raw['features'] ) && is_array( $raw['features'] ) ? $raw['features'] : array(), 0, 12 ) as $feature ) {
				$title = self::text( $feature, 'title', 80 );
				$text  = self::text( $feature, 'text', 300 );
				if ( is_wp_error( $title ) || is_wp_error( $text ) || '' === $title ) {
					$plugin['refused']++;
					continue;
				}
				$icon = is_array( $feature ) && isset( $feature['icon'] ) && is_string( $feature['icon'] ) && preg_match( '/^at-[a-z0-9-]{1,60}$/', $feature['icon'] ) ? $feature['icon'] : '';
				$plugin['features'][] = array( 'icon' => $icon, 'title' => $title, 'text' => $text );
			}
			$plugin['trial'] = ! empty( $raw['trial'] ) && true === $raw['trial'];
			$rec = isset( $raw['recommend'] ) && is_array( $raw['recommend'] ) ? $raw['recommend'] : array();
			$plugin['recommend'] = array(
				'woocommerce'  => ! empty( $rec['woocommerce'] ),
				'min_products' => isset( $rec['min_products'] ) ? max( 0, (int) $rec['min_products'] ) : 0,
				'replaces_any' => array_values( array_filter( array_map( static function ( $s ) {
					return is_scalar( $s ) ? sanitize_key( (string) $s ) : '';
				}, isset( $rec['replaces_any'] ) && is_array( $rec['replaces_any'] ) ? $rec['replaces_any'] : array() ) ) ),
				'always'       => ! empty( $rec['always'] ),
			);
			return $plugin;
		}

		public static function validate_pack( $raw ) {
			if ( ! is_array( $raw ) ) {
				return new WP_Error( 'ssp_catalogue', 'not an object' );
			}
			$name  = self::text( $raw, 'name', 80 );
			$pitch = self::text( $raw, 'pitch', 400 );
			if ( is_wp_error( $name ) || is_wp_error( $pitch ) || '' === $name ) {
				return new WP_Error( 'ssp_catalogue', 'a pack needs a name and a short pitch' );
			}
			$pack = array(
				'id'       => isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '',
				'name'     => $name,
				'pitch'    => $pitch,
				'includes' => isset( $raw['includes'] ) && is_string( $raw['includes'] ) ? sanitize_key( $raw['includes'] ) : '',
			);
			foreach ( array( 'yearly', 'lifetime' ) as $term ) {
				$t = isset( $raw[ $term ] ) && is_array( $raw[ $term ] ) ? $raw[ $term ] : null;
				$pack[ $term ] = ( $t && isset( $t['from_price'] ) && is_numeric( $t['from_price'] ) && self::url_on( $t['url'] ?? '', self::LINK_HOSTS ) )
					? array( 'product_id' => (int) ( $t['product_id'] ?? 0 ), 'from_price' => (float) $t['from_price'], 'url' => (string) $t['url'] )
					: null;
			}
			if ( ! $pack['yearly'] && ! $pack['lifetime'] ) {
				return new WP_Error( 'ssp_catalogue', 'a pack needs a yearly or lifetime price with a link' );
			}
			return $pack;
		}

		// ------------------------------------------------------------------ site facts and the recommender

		/** This site's facts, worked out here. Never sent anywhere except in a trial request the admin submits. */
		public static function site_facts() {
			$active = self::active_slugs();
			$wc     = in_array( 'woocommerce', $active, true ) || class_exists( 'WooCommerce', false );
			$products = 0;
			if ( $wc && post_type_exists( 'product' ) ) {
				$counts   = wp_count_posts( 'product' );
				$products = isset( $counts->publish ) ? (int) $counts->publish : 0;
			}
			$cache = array_values( array_intersect_key( self::CACHE_PLUGINS, array_flip( $active ) ) );
			$builders = array_values( array_intersect_key( self::BUILDERS, array_flip( $active ) ) );
			foreach ( array_unique( array( get_template(), get_stylesheet() ) ) as $theme ) {
				if ( isset( self::BUILDER_THEMES[ $theme ] ) ) {
					$builders[] = self::BUILDER_THEMES[ $theme ];
				}
			}
			return array(
				'woocommerce'   => $wc,
				'products'      => $products,
				'cache_plugins' => array_values( array_unique( $cache ) ),
				'page_builders' => array_values( array_unique( $builders ) ),
				'active'        => $active,
			);
		}

		/** Add-ons carry no "requires" field: their parent is known from the slug prefix. */
		const ADDON_PARENTS = array(
			'ssap-addon-' => 'super-speedy-ajax-prices',
			'ssi-addon-'  => 'super-speedy-imports',
			'spro-addon-' => 'scalability-pro',
			'sse-addon-'  => 'super-speedy-emails',
		);

		/** The parent plugin an add-on needs, or ''. */
		public static function addon_parent( $slug ) {
			foreach ( self::ADDON_PARENTS as $prefix => $parent ) {
				if ( 0 === strpos( $slug, $prefix ) ) {
					return $parent;
				}
			}
			return '';
		}

		/**
		 * Which catalogue plugins to recommend for this site, best first. Pure: no I/O.
		 *
		 * A plugin not already installed is recommended when
		 *   (recommend.always OR an active plugin is in recommend.replaces_any)
		 *   AND (recommend.woocommerce is false OR WooCommerce is active)
		 *   AND the published product count >= recommend.min_products.
		 * Plugins that replace or speed up something active come first, then catalogue order. An
		 * add-on whose parent is not active is still recommended, marked with the parent it needs.
		 *
		 * @return array list of array( 'plugin', 'score', 'matched' => slugs, 'reason' => replaces|always, 'needs' => parent slug or '' )
		 */
		public static function recommend( array $plugins, array $facts, array $installed ) {
			$out    = array();
			$pos    = 0;
			$active = (array) $facts['active'];
			foreach ( $plugins as $plugin ) {
				$pos++;
				if ( in_array( $plugin['slug'], $installed, true ) ) {
					continue;
				}
				$rec     = $plugin['recommend'];
				$matched = array_values( array_intersect( $rec['replaces_any'], $active ) );
				if ( ! $rec['always'] && ! $matched ) {
					continue;
				}
				if ( $rec['woocommerce'] && empty( $facts['woocommerce'] ) ) {
					continue;
				}
				if ( (int) $facts['products'] < $rec['min_products'] ) {
					continue;
				}
				$parent = self::addon_parent( $plugin['slug'] );
				$out[]  = array(
					'plugin'  => $plugin,
					'score'   => $matched ? 2 : 1,
					'matched' => $matched,
					'reason'  => $matched ? 'replaces' : 'always',
					'needs'   => ( '' !== $parent && ! in_array( $parent, $active, true ) ) ? $parent : '',
					'pos'     => $pos,
				);
			}
			usort( $out, static function ( $a, $b ) {
				return array( $b['score'], $a['pos'] ) <=> array( $a['score'], $b['pos'] );
			} );
			return $out;
		}

		/** Human names for plugin folder slugs, from this site's own plugin headers. */
		private static function plugin_names( array $slugs ) {
			static $by_folder = null;
			if ( null === $by_folder ) {
				$by_folder = array();
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ( get_plugins() as $file => $data ) {
					$folder = sanitize_key( false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ) );
					$by_folder[ $folder ] = $data['Name'];
				}
			}
			return array_map( static function ( $slug ) use ( $by_folder ) {
				return isset( $by_folder[ $slug ] ) && '' !== $by_folder[ $slug ] ? $by_folder[ $slug ] : $slug;
			}, $slugs );
		}

		private static function reason_text( array $rec, array $facts ) {
			$name = $rec['plugin']['name'];
			switch ( $rec['reason'] ) {
				case 'replaces':
					/* translators: 1: plugins active on this site, 2: our plugin */
					return sprintf( __( 'This site runs %1$s. %2$s replaces it or makes it faster.', 'super-speedy-settings' ), implode( ', ', self::plugin_names( $rec['matched'] ) ), $name );
			}
			if ( ! empty( $facts['woocommerce'] ) && $rec['plugin']['recommend']['woocommerce'] ) {
				/* translators: %s: number of products */
				return sprintf( _n( 'Built for WooCommerce stores like this one (%s published product).', 'Built for WooCommerce stores like this one (%s published products).', $facts['products'], 'super-speedy-settings' ), number_format_i18n( $facts['products'] ) );
			}
			return __( 'Fits any WordPress site.', 'super-speedy-settings' );
		}

		/** "Needs Super Speedy Ajax Prices" for an add-on whose parent is not active. */
		private static function needs_note( $parent ) {
			if ( '' === $parent ) {
				return '';
			}
			$catalogue = self::state()['plugins'];
			$name      = isset( $catalogue[ $parent ] ) ? $catalogue[ $parent ]['name'] : $parent;
			/* translators: %s: the plugin this add-on needs */
			return '<span class="ssp-ob-needs">' . esc_html( sprintf( __( 'Needs %s', 'super-speedy-settings' ), $name ) ) . '</span>';
		}

		/** A feature icon: from the sprite, a bundled Atlas font, or the bolt. Never blank. */
		private static function icon( $name ) {
			static $map = null;
			if ( null === $map ) {
				$map = is_file( __DIR__ . '/onboarding-icons.php' ) ? (array) include __DIR__ . '/onboarding-icons.php' : array();
			}
			$kind = isset( $map[ $name ] ) ? $map[ $name ] : '';
			if ( 'svg' === $kind ) {
				return '<svg class="ssp-ob-icon" aria-hidden="true" focusable="false"><use href="' . esc_url( plugins_url( 'onboarding-icons.svg', __FILE__ ) ) . '#' . esc_attr( $name ) . '"></use></svg>';
			}
			if ( 'font' === $kind ) {
				return '<i class="ssp-ob-icon ' . esc_attr( $name ) . '" aria-hidden="true"></i>';
			}
			return self::bolt();
		}

		// ------------------------------------------------------------------ licence chips

		/**
		 * The chip for a plugin's licence. $result is a check_license() result (or the cached one);
		 * the licence object comes from the auth server's additive `licence` field.
		 */
		public static function licence_chip( $slug, $result, $free = false, $fallback = '' ) {
			if ( $free ) {
				return self::chip( __( 'Free', 'super-speedy-settings' ), 'free' );
			}
			if ( null === $result ) {
				// Not checked yet on this site (the sales pages never call the licence server).
				$zip = get_option( self::TRIAL_OPTION );
				if ( ! ( is_array( $zip ) && ( $zip['slug'] ?? '' ) === $slug ) ) {
					return self::chip( __( 'Installed', 'super-speedy-settings' ), 'none' );
				}
			}
			$result  = is_array( $result ) ? $result : array();
			$error   = isset( $result['error'] ) ? strtolower( (string) $result['error'] ) : '';
			$licence = isset( $result['licence'] ) && is_array( $result['licence'] ) ? $result['licence'] : null;
			if ( ! $licence ) {
				$zip = get_option( self::TRIAL_OPTION );
				if ( is_array( $zip ) && ( $zip['slug'] ?? '' ) === $slug && ! empty( $zip['trial_expires'] ) && in_array( $error, array( '', 'valid' ), true ) ) {
					$licence = array( 'type' => 'trial', 'expires' => $zip['trial_expires'] );
				}
			}
			if ( $licence && 'trial' === $licence['type'] && in_array( $error, array( 'valid', 'expired', '' ), true ) ) {
				$days = self::days_left( $licence['expires'] );
				if ( null === $days || $days < 0 || 'expired' === $error ) {
					return self::chip( __( 'Free trial ended', 'super-speedy-settings' ), 'ended' );
				}
				if ( 0 === $days ) {
					return self::chip( __( 'Free trial: last day', 'super-speedy-settings' ), 'trial' );
				}
				/* translators: %d: days left in the free trial */
				return self::chip( sprintf( _n( 'Free trial: %d day left', 'Free trial: %d days left', $days, 'super-speedy-settings' ), $days ), 'trial' );
			}
			if ( 'valid' === $error ) {
				if ( $licence && 'lifetime' === $licence['type'] ) {
					return self::chip( __( 'Lifetime', 'super-speedy-settings' ), 'lifetime' );
				}
				if ( $licence && in_array( $licence['type'], array( 'yearly', 'monthly' ), true ) && $licence['expires'] ) {
					$when = wp_date( get_option( 'date_format' ), strtotime( $licence['expires'] . ' 12:00:00' ) );
					/* translators: %s: date the licence runs to */
					return self::chip( sprintf( 'yearly' === $licence['type'] ? __( 'Yearly until %s', 'super-speedy-settings' ) : __( 'Monthly until %s', 'super-speedy-settings' ), $when ), 'yearly' );
				}
				return self::chip( __( 'Licensed', 'super-speedy-settings' ), 'yearly' );
			}
			if ( 'expired' === $error ) {
				return self::chip( __( 'Licence expired', 'super-speedy-settings' ), 'ended' );
			}
			// The auth server answers an expired trial like a lapsed licence ("Not Purchased", no
			// licence object). If this plugin's trial key came from its download and that trial has
			// run out, say so.
			$zip = get_option( self::TRIAL_OPTION );
			if ( 'not purchased' === $error && is_array( $zip ) && ( $zip['slug'] ?? '' ) === $slug ) {
				$days = self::days_left( $zip['trial_expires'] ?? '' );
				if ( null !== $days && $days < 0 ) {
					return self::chip( __( 'Free trial ended', 'super-speedy-settings' ), 'ended' );
				}
			}
			if ( in_array( $error, array( '', 'invalid', 'not purchased' ), true ) ) {
				return self::chip( __( 'Not licensed', 'super-speedy-settings' ), 'none' );
			}
			return self::chip( '' !== $fallback ? $fallback : __( 'Not licensed', 'super-speedy-settings' ), 'none' );
		}

		/** Whole days from today (site time zone) to $date (Y-m-d); null when not a date. */
		public static function days_left( $date ) {
			if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return null;
			}
			$today = new DateTimeImmutable( wp_date( 'Y-m-d' ), wp_timezone() );
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
			if ( ! $end ) {
				return null;
			}
			return (int) $today->diff( $end )->format( '%r%a' );
		}

		private static function chip( $label, $tone ) {
			return '<span class="ssp-ob-chip ssp-ob-chip-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
		}

		/** The cached licence result for a slug, without any network. */
		private static function cached_licence( $slug ) {
			$cached = get_transient( 'superspeedy_l_' . $slug );
			return is_array( $cached ) ? $cached : null;
		}

		/** True when this site is on a free trial, or has exactly one paid Super Speedy plugin. */
		public static function pack_audience() {
			$paid  = 0;
			$trial = false;
			$not_paid = class_exists( 'SSP_Dashboard_Widget', false ) ? SSP_Dashboard_Widget::NOT_PAID_SLUGS : array();
			foreach ( self::registered_slugs() as $slug ) {
				if ( in_array( $slug, $not_paid, true ) ) {
					continue;
				}
				$paid++;
				$cached = self::cached_licence( $slug );
				if ( $cached && isset( $cached['licence']['type'] ) && 'trial' === $cached['licence']['type'] ) {
					$trial = true;
				}
			}
			$zip = get_option( self::TRIAL_OPTION );
			return $trial || is_array( $zip ) || 1 === $paid;
		}

		// ------------------------------------------------------------------ trial key pick-up

		/**
		 * On admin load: when no licence key is stored and a Super Speedy plugin folder holds
		 * ssp-license-key.php (added to a free-trial download), store its key, record the pick-up
		 * and delete the file.
		 */
		public static function pick_up_key() {
			if ( ! class_exists( 'SuperSpeedySettingsCore', false ) || '' !== SuperSpeedySettingsCore::stored_license_key() ) {
				return;
			}
			$slugs = self::registered_slugs();
			foreach ( $slugs as $slug ) {
				$file = WP_PLUGIN_DIR . '/' . $slug . '/' . self::KEY_FILE;
				if ( ! is_file( $file ) ) {
					continue;
				}
				$data = include $file;
				$key  = is_array( $data ) && isset( $data['key'] ) && is_string( $data['key'] ) ? trim( sanitize_text_field( $data['key'] ) ) : '';
				if ( '' === $key || strlen( $key ) > 200 ) {
					continue; // not a key file we understand; leave it for a person to look at
				}
				$expires = isset( $data['trial_expires'] ) && is_string( $data['trial_expires'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['trial_expires'] ) ? $data['trial_expires'] : '';
				$options = get_option( 'superspeedy_options', array() );
				$options = is_array( $options ) ? $options : array();
				$options['superspeedy_field_licensekey'] = $key;
				update_option( 'superspeedy_options', $options );
				foreach ( $slugs as $s ) {
					delete_transient( 'superspeedy_l_' . $s );
				}
				wp_delete_file( $file );
				clearstatcache( true, $file );
				update_option( self::TRIAL_OPTION, array(
					'slug'          => $slug,
					'trial_expires' => $expires,
					'picked_up'     => time(),
					'deleted'       => ! file_exists( $file ),
				), false );
				return;
			}
		}

		// ------------------------------------------------------------------ deals and the bubble

		/** Offer and launch items from the dashboard feed that are live for this site now. */
		public static function deal_items() {
			if ( ! class_exists( 'SSP_Dashboard_Widget', false ) ) {
				return array();
			}
			$state = SSP_Dashboard_Widget::state();
			$items = SSP_Dashboard_Widget::visible_items( SSP_Dashboard_Widget::drop_installed_products( (array) $state['items'] ), time(), self::active_slugs(), false );
			return array_values( array_filter( $items, static function ( $item ) {
				return in_array( $item['type'], array( 'offer', 'launch' ), true );
			} ) );
		}

		public static function unseen_count( $user_id = 0 ) {
			$user_id = $user_id ?: get_current_user_id();
			if ( class_exists( 'SSP_Dashboard_Widget', false ) && SSP_Dashboard_Widget::hides_offers( $user_id ) ) {
				return 0; // they chose Hide offers on the dashboard
			}
			$seen = get_user_meta( $user_id, self::SEEN_META, true );
			$seen = is_array( $seen ) ? $seen : array();
			$n = 0;
			foreach ( self::deal_items() as $item ) {
				$n += in_array( $item['id'], $seen, true ) ? 0 : 1;
			}
			return $n;
		}

		public static function mark_seen( $user_id = 0 ) {
			$user_id = $user_id ?: get_current_user_id();
			$seen    = get_user_meta( $user_id, self::SEEN_META, true );
			$seen    = is_array( $seen ) ? $seen : array();
			foreach ( self::deal_items() as $item ) {
				$seen[] = $item['id'];
			}
			update_user_meta( $user_id, self::SEEN_META, array_slice( array_values( array_unique( $seen ) ), -100 ) );
		}

		private static function deals_bubble( $count ) {
			$count = (int) $count;
			/* translators: %d: number of new deals */
			$label = sprintf( _n( '%d new deal', '%d new deals', $count, 'super-speedy-settings' ), $count );
			return ' <span class="update-plugins ssp-deals-count count-' . $count . '" title="' . esc_attr( $label ) . '"><span class="plugin-count" aria-hidden="true">' . $count . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
		}

		public static function menu() {
			global $menu;
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// Opening Deals is what clears the bubble, so do it before the count is placed.
			if ( 'deals' === self::current_tab() && self::on_screen() ) {
				self::mark_seen();
			}
			$count = self::unseen_count();
			self::$deals_hook = (string) add_submenu_page(
				self::PAGE,
				__( 'Super Speedy Deals', 'super-speedy-settings' ),
				__( 'Deals', 'super-speedy-settings' ) . ( $count ? self::deals_bubble( $count ) : '' ),
				'manage_options',
				self::DEALS_PAGE,
				array( __CLASS__, 'render_deals_page' )
			);
			if ( ! $count ) {
				return;
			}
			foreach ( (array) $menu as $index => $item ) {
				if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
					$menu[ $index ][0] .= self::deals_bubble( $count );
					break;
				}
			}
		}

		public static function bubble_style() {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<style id="ssp-deals-bubble">#adminmenu .ssp-deals-count{background-color:' . esc_attr( self::BUBBLE ) . ';color:#fff}</style>' . "\n";
			}
		}

		public static function enqueue( $hook ) {
			if ( ! self::on_screen() ) {
				return;
			}
			$dir = plugin_dir_url( __FILE__ );
			wp_enqueue_style( 'ssp-onboarding', $dir . 'onboarding.css', array(), (string) filemtime( __DIR__ . '/onboarding.css' ) );
			wp_enqueue_script( 'ssp-onboarding', $dir . 'onboarding.js', array(), (string) filemtime( __DIR__ . '/onboarding.js' ), true );
		}

		// ------------------------------------------------------------------ trial request (only on Submit)

		/** What a trial request sends, built only from the submitted form. */
		public static function trial_payload( array $post, array $catalogue_slugs ) {
			$plugins = array();
			foreach ( isset( $post['plugins'] ) && is_array( $post['plugins'] ) ? $post['plugins'] : array() as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( in_array( $slug, $catalogue_slugs, true ) ) {
					$plugins[] = $slug;
				}
			}
			$payload = array(
				'email'        => sanitize_email( (string) ( $post['email'] ?? '' ) ),
				'name'         => mb_substr( sanitize_text_field( (string) ( $post['name'] ?? '' ) ), 0, 100 ),
				'site_url'     => home_url( '/' ),
				'plugins'      => array_values( array_unique( $plugins ) ),
				'requirements' => mb_substr( sanitize_textarea_field( (string) ( $post['requirements'] ?? '' ) ), 0, 2000 ),
				'site_facts'   => new stdClass(),
			);
			if ( ! empty( $post['include_facts'] ) ) {
				$payload['site_facts'] = self::shareable_facts();
			}
			return $payload;
		}

		/** The facts a trial request may include: shown in full on the form before Submit. */
		public static function shareable_facts() {
			$f = self::site_facts();
			$catalogue = self::state()['plugins'];
			$ours = array();
			foreach ( $catalogue as $p ) {
				$ours = array_merge( $ours, $p['recommend']['replaces_any'] );
			}
			// Flat scalar values only: the request endpoint takes site_facts as an object of at most
			// 50 scalars.
			return array(
				'woocommerce'          => (bool) $f['woocommerce'],
				'products'             => (int) $f['products'],
				'cache_plugins'        => implode( ', ', $f['cache_plugins'] ),
				'page_builders'        => implode( ', ', $f['page_builders'] ),
				'plugins_we_replace'   => implode( ', ', array_values( array_intersect( $f['active'], array_unique( $ours ) ) ) ),
				'super_speedy_plugins' => implode( ', ', self::registered_slugs() ),
				'active_plugin_count'  => count( $f['active'] ),
			);
		}

		public static function handle_trial_request() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'super-speedy-settings' ), 403 );
			}
			check_admin_referer( 'ssp_trial_request' );
			$post    = wp_unslash( $_POST );
			$payload = self::trial_payload( $post, array_keys( self::state()['plugins'] ) );
			$result  = array( 'ok' => false, 'message' => '' );
			if ( ! is_email( $payload['email'] ) ) {
				$result['message'] = __( 'Please enter an email address we can reply to. Nothing was sent.', 'super-speedy-settings' );
			} elseif ( mb_strlen( trim( $payload['requirements'] ) ) < 10 ) {
				$result['message'] = __( 'Please tell us what the site does and what you want from the trial. Nothing was sent.', 'super-speedy-settings' );
			} else {
				$response = wp_safe_remote_post( self::TRIAL_URL, array(
					'timeout'     => 15,
					'redirection' => 0,
					'user-agent'  => self::TRIAL_UA,
					'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
					'cookies'     => array(),
					'body'        => wp_json_encode( $payload ),
					'data_format' => 'body',
				) );
				if ( is_wp_error( $response ) ) {
					/* translators: %s: the error */
					$result['message'] = sprintf( __( 'The request could not be sent: %s', 'super-speedy-settings' ), $response->get_error_message() );
				} else {
					$code = (int) wp_remote_retrieve_response_code( $response );
					$body = json_decode( wp_remote_retrieve_body( $response ), true );
					$said = is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ? mb_substr( sanitize_text_field( $body['message'] ), 0, 300 ) : '';
					if ( $code >= 200 && $code < 300 ) {
						$result = array( 'ok' => true, 'message' => '' !== $said ? $said : __( 'Request sent. We will reply by email, usually within a working day.', 'super-speedy-settings' ) );
					} else {
						/* translators: 1: HTTP status, 2: the server's message */
						$result['message'] = trim( sprintf( __( 'The request was not accepted (HTTP %1$d). %2$s', 'super-speedy-settings' ), $code, $said ) );
					}
				}
			}
			$result['form'] = array(
				'email'        => $payload['email'],
				'name'         => $payload['name'],
				'plugins'      => $payload['plugins'],
				'requirements' => $payload['requirements'],
			);
			set_transient( 'ssp_trial_result_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::tab_url( 'trial', array( 'sent' => $result['ok'] ? '1' : '0' ) ) );
			exit;
		}

		// ------------------------------------------------------------------ rendering (no network)

		private static function link( $url, $text, $class = '' ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . $text . '</a>';
		}

		private static function money( $amount, $currency ) {
			$symbols = array( 'EUR' => '€', 'USD' => '$', 'GBP' => '£' );
			$amount  = (float) $amount;
			$num     = number_format_i18n( $amount, floor( $amount ) == $amount ? 0 : 2 );
			return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $num : trim( $num . ' ' . $currency );
		}

		private static function tile( $slug, $name, $size = 56 ) {
			$file = __DIR__ . '/images/' . $slug . '.png';
			if ( is_file( $file ) ) {
				return '<img class="ssp-ob-tile" src="' . esc_url( plugins_url( 'images/' . $slug . '.png', __FILE__ ) ) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '">';
			}
			$initials = '';
			foreach ( preg_split( '/\s+/', preg_replace( '/^Super Speedy\s+/i', '', $name ) ) as $word ) {
				$initials .= mb_substr( $word, 0, 1 );
			}
			return '<span class="ssp-ob-tile ssp-ob-tile-blank" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $initials, 0, 2 ) ) ) . '</span>';
		}

		private static function bolt() {
			return '<svg class="ssp-ob-bolt" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>';
		}

		/** The tab strip. */
		public static function render_nav( $current ) {
			$count = self::unseen_count();
			$tabs  = array(
				''          => __( 'Your plugins', 'super-speedy-settings' ),
				'site'      => __( 'For this site', 'super-speedy-settings' ),
				'catalogue' => __( 'Plugins', 'super-speedy-settings' ),
				'pack'      => __( 'All Access', 'super-speedy-settings' ),
				'trial'     => __( 'Free trial', 'super-speedy-settings' ),
				'deals'     => __( 'Deals', 'super-speedy-settings' ),
			);
			echo '<nav class="ssp-ob-nav" aria-label="' . esc_attr__( 'Super Speedy pages', 'super-speedy-settings' ) . '">';
			foreach ( $tabs as $tab => $label ) {
				$is = ( $tab === $current );
				echo '<a class="ssp-ob-navlink' . ( $is ? ' is-current' : '' ) . '" href="' . esc_url( self::tab_url( $tab ) ) . '"' . ( $is ? ' aria-current="page"' : '' ) . '>' . esc_html( $label );
				if ( 'deals' === $tab && $count ) {
					echo ' <span class="ssp-ob-navcount">' . (int) $count . '</span>';
				}
				echo '</a>';
			}
			echo '</nav>';
		}

		/** A non-default tab's page body. */
		public static function render_tab( $tab ) {
			echo '<div class="ssp-ob">';
			switch ( $tab ) {
				case 'site':
					self::render_site();
					break;
				case 'catalogue':
					$slug = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
					$slug ? self::render_plugin( $slug ) : self::render_catalogue();
					break;
				case 'pack':
					self::render_pack();
					break;
				case 'trial':
					self::render_trial();
					break;
				case 'deals':
					self::render_deals();
					break;
			}
			self::render_status();
			echo '</div>';
		}

		/** The settings core's screen renderer (header, tab strip, then render_tab()). */
		private static $screen = null;

		public static function set_screen_renderer( $callable ) {
			self::$screen = $callable;
		}

		/** Submenu callback for Deals: the same screen, on the Deals tab. */
		public static function render_deals_page() {
			if ( is_callable( self::$screen ) ) {
				call_user_func( self::$screen );
				return;
			}
			echo '<div class="wrap">';
			self::render_nav( 'deals' );
			self::render_tab( 'deals' );
			echo '</div>';
		}

		/** When the catalogue was fetched, and what went wrong if it did. */
		private static function render_status() {
			$state = self::state();
			echo '<p class="ssp-ob-status">';
			if ( $state['fetched_at'] ) {
				/* translators: %s: time since the last good check */
				echo esc_html( sprintf( __( 'Plugin details updated %s ago from superspeedyplugins.com.', 'super-speedy-settings' ), human_time_diff( $state['fetched_at'], time() ) ) );
			}
			if ( '' !== $state['last_error'] && $state['last_attempt'] ) {
				/* translators: 1: time since the failed check, 2: the error */
				echo ' <span class="ssp-ob-error">' . esc_html( sprintf( __( 'The check %1$s ago failed: %2$s.', 'super-speedy-settings' ), human_time_diff( $state['last_attempt'], time() ), $state['last_error'] ) ) . '</span>';
			}
			if ( $state['skipped'] ) {
				/* translators: %d: number of catalogue entries skipped */
				echo ' <span class="ssp-ob-error">' . esc_html( sprintf( _n( '%d catalogue entry was skipped:', '%d catalogue entries were skipped:', count( $state['skipped'] ), 'super-speedy-settings' ), count( $state['skipped'] ) ) . ' ' . implode( '; ', $state['skipped'] ) ) . '</span>';
			}
			echo ' ' . esc_html__( 'This page is worked out on your server; it sends nothing about your site.', 'super-speedy-settings' ) . '</p>';
		}

		/** No catalogue yet: say when it arrives. */
		private static function render_waiting() {
			$state = self::state();
			$next  = wp_next_scheduled( self::CRON_HOOK );
			echo '<div class="ssp-ob-card ssp-ob-waiting"><h2>' . esc_html__( 'Plugin details are on their way', 'super-speedy-settings' ) . '</h2><p>';
			echo esc_html__( 'This page fills in after this site\'s first check of the Super Speedy catalogue, which runs in the background.', 'super-speedy-settings' );
			if ( $next ) {
				/* translators: %s: time until the next check */
				echo ' ' . esc_html( sprintf( __( 'The next check is due in %s.', 'super-speedy-settings' ), human_time_diff( time(), max( time() + 60, $next ) ) ) );
			}
			if ( '' !== $state['last_error'] ) {
				echo '</p><p class="ssp-ob-error">' . esc_html( sprintf( /* translators: %s: error */ __( 'The last check failed: %s.', 'super-speedy-settings' ), $state['last_error'] ) );
			}
			echo '</p></div>';
		}

		/** On Your plugins: the trial key was picked up from a download. */
		public static function render_key_notice() {
			$zip = get_option( self::TRIAL_OPTION );
			if ( ! is_array( $zip ) || empty( $zip['picked_up'] ) || time() - (int) $zip['picked_up'] > ( self::TRIAL_DAYS + 1 ) * DAY_IN_SECONDS ) {
				return;
			}
			/* translators: 1: plugin folder, 2: date */
			echo '<div class="notice notice-success ssp-ob-notice ssp-ob-keynotice"><p>' . esc_html( sprintf( __( 'Your free trial licence key was set up automatically from the %1$s download on %2$s. Updates and support are switched on.', 'super-speedy-settings' ), $zip['slug'], wp_date( get_option( 'date_format' ), (int) $zip['picked_up'] ) ) ) . '</p>';
			if ( empty( $zip['deleted'] ) ) {
				/* translators: %s: file path */
				echo '<p class="ssp-ob-error">' . esc_html( sprintf( __( 'The key file could not be deleted. Please delete %s by hand.', 'super-speedy-settings' ), 'wp-content/plugins/' . $zip['slug'] . '/' . self::KEY_FILE ) ) . '</p>';
			}
			echo '</div>';
		}

		/** The pack banner on Your plugins, for trial users and single-plugin owners. */
		public static function render_pack_banner() {
			$state = self::state();
			if ( ! $state['packs'] || ! self::pack_audience() ) {
				return;
			}
			$pack = $state['packs'][0];
			$term = $pack['yearly'] ? $pack['yearly'] : $pack['lifetime'];
			echo '<a class="ssp-ob ssp-ob-banner" href="' . esc_url( self::tab_url( 'pack' ) ) . '">';
			echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html__( 'Is this your only website?', 'super-speedy-settings' ) . '</span>';
			/* translators: %s: price */
			echo '<span class="ssp-ob-banner-title">' . esc_html( sprintf( __( 'Every Super Speedy plugin on this site, from %s', 'super-speedy-settings' ), self::money( $term['from_price'], $state['currency'] ) ) ) . '</span>';
			echo '<span class="ssp-ob-btn">' . esc_html__( 'See what this site would get', 'super-speedy-settings' ) . ' <span aria-hidden="true">&rarr;</span></span>';
			echo '</a>';
		}

		/**
		 * The hero media, best first: the MP4 loop (muted, looped, given its source by onboarding.js
		 * when it scrolls into view, with its poster), else the poster, else the first screenshot,
		 * else a branded panel with the plugin's tile. Never an empty box.
		 */
		private static function render_media( array $p, $class = 'ssp-ob-media' ) {
			if ( $p['hero_mp4'] ) {
				echo '<div class="' . esc_attr( $class ) . '"><video class="ssp-ob-video" muted loop playsinline preload="none" width="1280" height="720"'
					. ( $p['hero_poster'] ? ' poster="' . esc_url( $p['hero_poster'] ) . '"' : '' )
					. ' data-src="' . esc_url( $p['hero_mp4'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: plugin */ __( '%s in action', 'super-speedy-settings' ), $p['name'] ) ) . '"></video></div>';
			} elseif ( $p['hero_poster'] ) {
				echo '<div class="' . esc_attr( $class ) . '"><img src="' . esc_url( $p['hero_poster'] ) . '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>';
			} elseif ( $p['screenshots'] ) {
				echo '<div class="' . esc_attr( $class ) . ' ssp-ob-media-shot"><img src="' . esc_url( $p['screenshots'][0]['url'] ) . '" alt="' . esc_attr( $p['screenshots'][0]['caption'] ) . '" loading="lazy" referrerpolicy="no-referrer"></div>';
			} else {
				echo '<div class="' . esc_attr( $class ) . ' ssp-ob-media-art">' . self::tile( $p['slug'], $p['name'], 96 ) . '<span class="ssp-ob-art-name">' . esc_html( $p['name'] ) . '</span>' . ( '' !== $p['headline'] ? '<span class="ssp-ob-art-head">' . esc_html( $p['headline'] ) . '</span>' : '' ) . '</div>'; // phpcs:ignore -- tile() escapes
			}
		}

		private static function claims_strip( array $claims ) {
			if ( ! $claims ) {
				return;
			}
			echo '<div class="ssp-ob-claims">';
			foreach ( $claims as $c ) {
				echo '<div class="ssp-ob-claim"><span class="ssp-ob-figure">' . esc_html( $c['figure'] ) . '</span><span class="ssp-ob-claim-label">' . esc_html( $c['label'] ) . '</span>';
				echo self::link( $c['source_url'], esc_html__( 'Source', 'super-speedy-settings' ) . ' &rarr;', 'ssp-ob-source' ) . '</div>';
			}
			echo '</div>';
		}

		private static function ctas( array $p ) {
			echo '<div class="ssp-ob-ctas">';
			if ( $p['trial'] && ! self::is_installed( $p['slug'] ) ) {
				echo '<a class="ssp-ob-btn" href="' . esc_url( self::tab_url( 'trial', array( 'plugin' => $p['slug'] ) ) ) . '">' . esc_html__( 'Try it free', 'super-speedy-settings' ) . ' <span aria-hidden="true">&rarr;</span></a>';
			}
			echo self::link( $p['product_url'], esc_html__( 'Buy', 'super-speedy-settings' ) . ' ' . esc_html( $p['name'] ), 'ssp-ob-btn ssp-ob-btn-ghost' );
			echo '</div>';
		}

		/** For this site. */
		private static function render_site() {
			$state = self::state();
			$facts = self::site_facts();
			echo '<section class="ssp-ob-hero ssp-ob-hero-compact"><div class="ssp-ob-hero-copy">';
			echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html__( 'For this site', 'super-speedy-settings' ) . '</span>';
			echo '<h1 class="ssp-ob-h1">' . esc_html__( 'What would make this site faster', 'super-speedy-settings' ) . '</h1>';
			echo '<p class="ssp-ob-sub">' . esc_html__( 'Worked out on your own server from what this site runs. Nothing about your site was sent anywhere.', 'super-speedy-settings' ) . '</p>';
			echo '</div><ul class="ssp-ob-facts">';
			$fact = static function ( $label, $value ) {
				echo '<li><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></li>';
			};
			$fact( __( 'WooCommerce', 'super-speedy-settings' ), $facts['woocommerce'] ? __( 'Yes', 'super-speedy-settings' ) : __( 'No', 'super-speedy-settings' ) );
			if ( $facts['woocommerce'] ) {
				$fact( __( 'Published products', 'super-speedy-settings' ), number_format_i18n( $facts['products'] ) );
			}
			$fact( __( 'Cache plugin', 'super-speedy-settings' ), $facts['cache_plugins'] ? implode( ', ', $facts['cache_plugins'] ) : __( 'None found', 'super-speedy-settings' ) );
			$fact( __( 'Page builder', 'super-speedy-settings' ), $facts['page_builders'] ? implode( ', ', $facts['page_builders'] ) : __( 'None found', 'super-speedy-settings' ) );
			$fact( __( 'Active plugins', 'super-speedy-settings' ), number_format_i18n( count( $facts['active'] ) ) );
			echo '</ul></section>';

			if ( ! $state['plugins'] ) {
				self::render_waiting();
				return;
			}
			$installed = array_values( array_filter( array_keys( $state['plugins'] ), array( __CLASS__, 'is_installed' ) ) );
			$recs      = self::recommend( array_values( $state['plugins'] ), $facts, $installed );
			if ( ! $recs ) {
				echo '<div class="ssp-ob-card"><h2>' . esc_html__( 'Nothing to add right now', 'super-speedy-settings' ) . '</h2><p>' . esc_html__( 'This site already runs every Super Speedy plugin that fits what it does.', 'super-speedy-settings' ) . '</p></div>';
				return;
			}
			echo '<h2 class="ssp-ob-h2">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d plugin fits this site', '%d plugins fit this site', count( $recs ), 'super-speedy-settings' ), count( $recs ) ) ) . '</h2>';
			echo '<div class="ssp-ob-recs">';
			foreach ( $recs as $i => $rec ) {
				$p = $rec['plugin'];
				echo '<article class="ssp-ob-rec' . ( 0 === $i ? ' is-top' : '' ) . '" data-slug="' . esc_attr( $p['slug'] ) . '" data-reason="' . esc_attr( $rec['reason'] ) . '">';
				self::render_media( $p, 'ssp-ob-rec-media' );
				echo '<div class="ssp-ob-rec-body">';
				echo '<span class="ssp-ob-why">' . self::bolt() . esc_html( self::reason_text( $rec, $facts ) ) . '</span>' . self::needs_note( $rec['needs'] ); // phpcs:ignore -- escaped in needs_note()
				echo '<h3>' . esc_html( $p['name'] ) . '</h3>';
				if ( $p['headline'] ) {
					echo '<p class="ssp-ob-rec-head">' . esc_html( $p['headline'] ) . '</p>';
				}
				if ( $p['claims'] ) {
					$c = $p['claims'][0];
					echo '<p class="ssp-ob-rec-claim"><strong>' . esc_html( $c['figure'] ) . '</strong> ' . esc_html( $c['label'] ) . ' ' . self::link( $c['source_url'], esc_html__( 'Source', 'super-speedy-settings' ), 'ssp-ob-source' ) . '</p>';
				}
				echo '<div class="ssp-ob-ctas">';
				echo '<a class="ssp-ob-more" href="' . esc_url( self::tab_url( 'catalogue', array( 'plugin' => $p['slug'] ) ) ) . '">' . esc_html__( 'See how it works', 'super-speedy-settings' ) . ' &rarr;</a>';
				if ( $p['trial'] ) {
					echo '<a class="ssp-ob-btn ssp-ob-btn-sm" href="' . esc_url( self::tab_url( 'trial', array( 'plugin' => $p['slug'] ) ) ) . '">' . esc_html__( 'Try it free', 'super-speedy-settings' ) . '</a>';
				}
				echo self::link( $p['product_url'], esc_html__( 'Buy', 'super-speedy-settings' ), 'ssp-ob-btn ssp-ob-btn-sm ssp-ob-btn-ghost' );
				echo '</div></div></article>';
			}
			echo '</div>';
			if ( $state['packs'] ) {
				echo '<p class="ssp-ob-next"><a href="' . esc_url( self::tab_url( 'pack' ) ) . '">' . esc_html__( 'Get all of them, and every other Super Speedy plugin, in one licence', 'super-speedy-settings' ) . ' &rarr;</a></p>';
			}
		}

		/** Plugins: the index. */
		private static function render_catalogue() {
			$state = self::state();
			echo '<h1 class="ssp-ob-h1 ssp-ob-h1-dark">' . esc_html__( 'Every Super Speedy plugin', 'super-speedy-settings' ) . '</h1>';
			if ( ! $state['plugins'] ) {
				self::render_waiting();
				return;
			}
			echo '<div class="ssp-ob-grid">';
			foreach ( $state['plugins'] as $p ) {
				$installed = self::is_installed( $p['slug'] );
				echo '<a class="ssp-ob-gridcard" href="' . esc_url( self::tab_url( 'catalogue', array( 'plugin' => $p['slug'] ) ) ) . '">';
				echo self::tile( $p['slug'], $p['name'], 48 ); // phpcs:ignore -- escaped in tile()
				echo '<span class="ssp-ob-gridname">' . esc_html( $p['name'] ) . '</span>';
				if ( $installed ) {
					echo self::licence_chip( $p['slug'], self::cached_licence( $p['slug'] ) ); // phpcs:ignore -- escaped in chip()
				}
				echo '<span class="ssp-ob-gridhead">' . esc_html( $p['headline'] ) . '</span></a>';
			}
			echo '</div>';
		}

		/** Plugins: one plugin's sales page, with previous and next. */
		private static function render_plugin( $slug ) {
			$state = self::state();
			if ( ! isset( $state['plugins'][ $slug ] ) ) {
				if ( ! $state['plugins'] ) {
					self::render_waiting();
					return;
				}
				echo '<div class="ssp-ob-card"><p>' . esc_html__( 'That plugin is not in the catalogue.', 'super-speedy-settings' ) . ' <a href="' . esc_url( self::tab_url( 'catalogue' ) ) . '">' . esc_html__( 'See every plugin', 'super-speedy-settings' ) . '</a></p></div>';
				return;
			}
			$p     = $state['plugins'][ $slug ];
			$slugs = array_keys( $state['plugins'] );
			$at    = array_search( $slug, $slugs, true );
			$installed = self::is_installed( $slug );

			echo '<article class="ssp-ob-page" data-slug="' . esc_attr( $slug ) . '">';
			echo '<section class="ssp-ob-hero"><div class="ssp-ob-hero-copy">';
			echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html( $p['name'] ) . '</span>';
			if ( $installed ) {
				echo ' ' . self::licence_chip( $slug, self::cached_licence( $slug ) ); // phpcs:ignore -- escaped in chip()
			}
			echo '<h1 class="ssp-ob-h1">' . esc_html( '' !== $p['headline'] ? $p['headline'] : $p['name'] ) . '</h1>';
			echo '<span class="ssp-ob-line" aria-hidden="true"></span>';
			if ( $p['sub'] ) {
				echo '<p class="ssp-ob-sub">' . esc_html( $p['sub'] ) . '</p>';
			}
			self::ctas( $p );
			echo '</div>';
			self::render_media( $p );
			echo '</section>';

			if ( $p['claims'] ) {
				echo '<section class="ssp-ob-section"><h2 class="ssp-ob-h2">' . esc_html__( 'Measured, not promised', 'super-speedy-settings' ) . '</h2>';
				self::claims_strip( $p['claims'] );
				echo '</section>';
			}
			if ( $p['problem'] ) {
				echo '<section class="ssp-ob-section ssp-ob-problem"><h2 class="ssp-ob-h2">' . esc_html__( 'The problem it solves', 'super-speedy-settings' ) . '</h2><p>' . esc_html( $p['problem'] ) . '</p></section>';
			}
			if ( $p['features'] ) {
				echo '<section class="ssp-ob-section"><h2 class="ssp-ob-h2">' . esc_html( sprintf( /* translators: %s: plugin */ __( 'What %s does for you', 'super-speedy-settings' ), $p['name'] ) ) . '</h2><div class="ssp-ob-features">';
				foreach ( $p['features'] as $f ) {
					echo '<div class="ssp-ob-feature">' . self::icon( $f['icon'] ) . '<h3>' . esc_html( $f['title'] ) . '</h3><p>' . esc_html( $f['text'] ) . '</p></div>';
				}
				echo '</div></section>';
			}
			if ( $p['screenshots'] ) {
				echo '<section class="ssp-ob-section"><h2 class="ssp-ob-h2">' . esc_html__( 'See it in wp-admin', 'super-speedy-settings' ) . '</h2><div class="ssp-ob-shots">';
				foreach ( $p['screenshots'] as $shot ) {
					echo '<figure class="ssp-ob-shot"><a href="' . esc_url( $shot['url'] ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $shot['url'] ) . '" alt="' . esc_attr( $shot['caption'] ) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer"></a>';
					if ( '' !== $shot['caption'] ) {
						echo '<figcaption>' . esc_html( $shot['caption'] ) . '</figcaption>';
					}
					echo '</figure>';
				}
				echo '</div></section>';
			}

			// Does it fit this site? The same local rules as For this site.
			if ( ! $installed ) {
				$facts = self::site_facts();
				$recs  = self::recommend( array( $p ), $facts, array() );
				if ( $recs ) {
					echo '<section class="ssp-ob-section ssp-ob-fit"><h2 class="ssp-ob-h2">' . esc_html__( 'Why it fits this site', 'super-speedy-settings' ) . '</h2><p>' . self::bolt() . esc_html( self::reason_text( $recs[0], $facts ) ) . '</p>' . self::needs_note( $recs[0]['needs'] ) . '</section>'; // phpcs:ignore
				}
			}

			echo '<section class="ssp-ob-section ssp-ob-pricing"><h2 class="ssp-ob-h2">' . esc_html( $installed ? __( 'More licences, more sites', 'super-speedy-settings' ) : sprintf( /* translators: %s: plugin */ __( 'Get %s', 'super-speedy-settings' ), $p['name'] ) ) . '</h2><div class="ssp-ob-pricecards">';
			echo '<div class="ssp-ob-pricecard"><h3>' . esc_html( $p['name'] ) . '</h3><p>' . esc_html__( 'Yearly and lifetime licences for 1, 5 or more sites, on the product page.', 'super-speedy-settings' ) . '</p>';
			echo self::link( $p['product_url'], esc_html__( 'See prices and buy', 'super-speedy-settings' ) . ' &rarr;', 'ssp-ob-btn' ) . '</div>';
			if ( $state['packs'] ) {
				$pack = $state['packs'][0];
				$term = $pack['yearly'] ? $pack['yearly'] : $pack['lifetime'];
				echo '<div class="ssp-ob-pricecard is-pack"><span class="ssp-ob-eyebrow">' . esc_html__( 'Best value', 'super-speedy-settings' ) . '</span><h3>' . esc_html( $pack['name'] ) . '</h3>';
				/* translators: 1: plugin, 2: price */
				echo '<p>' . esc_html( sprintf( __( '%1$s plus every other Super Speedy plugin, from %2$s.', 'super-speedy-settings' ), $p['name'], self::money( $term['from_price'], $state['currency'] ) ) ) . '</p>';
				echo '<a class="ssp-ob-btn" href="' . esc_url( self::tab_url( 'pack' ) ) . '">' . esc_html__( 'Compare', 'super-speedy-settings' ) . ' &rarr;</a></div>';
			}
			echo '</div></section>';

			echo '<section class="ssp-ob-section ssp-ob-final"><h2 class="ssp-ob-h2">' . esc_html( sprintf( /* translators: %s: plugin */ __( 'Put %s to work on this site', 'super-speedy-settings' ), $p['name'] ) ) . '</h2>';
			self::ctas( $p );
			if ( $p['kb_url'] ) {
				echo '<p>' . self::link( $p['kb_url'], esc_html__( 'Read the setup guides first', 'super-speedy-settings' ) . ' &rarr;', 'ssp-ob-more' ) . '</p>';
			}
			echo '</section>';

			echo '<nav class="ssp-ob-pager" aria-label="' . esc_attr__( 'Plugin pages', 'super-speedy-settings' ) . '">';
			if ( $at > 0 ) {
				$prev = $state['plugins'][ $slugs[ $at - 1 ] ];
				echo '<a rel="prev" href="' . esc_url( self::tab_url( 'catalogue', array( 'plugin' => $prev['slug'] ) ) ) . '">&larr; ' . esc_html( $prev['name'] ) . '</a>';
			} else {
				echo '<span></span>';
			}
			/* translators: 1: position, 2: total */
			echo '<span class="ssp-ob-pagecount">' . esc_html( sprintf( __( '%1$d of %2$d', 'super-speedy-settings' ), $at + 1, count( $slugs ) ) ) . '</span>';
			if ( $at < count( $slugs ) - 1 ) {
				$next = $state['plugins'][ $slugs[ $at + 1 ] ];
				echo '<a rel="next" href="' . esc_url( self::tab_url( 'catalogue', array( 'plugin' => $next['slug'] ) ) ) . '">' . esc_html( $next['name'] ) . ' &rarr;</a>';
			} else {
				echo '<span></span>';
			}
			echo '</nav></article>';
		}

		/** The pack page. */
		private static function render_pack() {
			$state = self::state();
			if ( ! $state['packs'] ) {
				self::render_waiting();
				return;
			}
			$pack  = $state['packs'][0];
			$facts = self::site_facts();
			$installed = array_values( array_filter( array_keys( $state['plugins'] ), array( __CLASS__, 'is_installed' ) ) );
			$recs  = self::recommend( array_values( $state['plugins'] ), $facts, $installed );
			$would = array_merge( $installed, array_map( static function ( $r ) {
				return $r['plugin']['slug'];
			}, $recs ) );

			echo '<section class="ssp-ob-hero ssp-ob-hero-pack"><div class="ssp-ob-hero-copy">';
			echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html__( 'All Access', 'super-speedy-settings' ) . '</span>';
			echo '<h1 class="ssp-ob-h1">' . esc_html__( 'Every Super Speedy plugin. One licence.', 'super-speedy-settings' ) . '</h1>';
			echo '<span class="ssp-ob-line" aria-hidden="true"></span>';
			if ( $pack['pitch'] ) {
				echo '<p class="ssp-ob-sub">' . esc_html( $pack['pitch'] ) . '</p>';
			}
			echo '</div><div class="ssp-ob-packtiles">';
			foreach ( $state['plugins'] as $p ) {
				$on = in_array( $p['slug'], $would, true );
				echo '<span class="ssp-ob-packtile' . ( $on ? ' is-on' : '' ) . '" title="' . esc_attr( $p['name'] ) . '">' . self::tile( $p['slug'], $p['name'], 44 ) . '<span>' . esc_html( preg_replace( '/^Super Speedy\s+/i', '', $p['name'] ) ) . '</span></span>'; // phpcs:ignore
			}
			echo '</div></section>';

			/* translators: 1: number of plugins this site would use, 2: number of plugins in the catalogue */
			echo '<p class="ssp-ob-would">' . self::bolt() . esc_html( sprintf( __( 'This site runs or would use %1$d of the %2$d plugins shown, worked out from what it runs.', 'super-speedy-settings' ), count( array_unique( $would ) ), count( $state['plugins'] ) ) ) . '</p>';

			// Yearly / Lifetime, and "Is this your only website?". CSS-only toggles; they work without JavaScript.
			$default = $pack['yearly'] ? 'yearly' : 'lifetime';
			echo '<div class="ssp-ob-toggles">';
			echo '<input type="radio" class="ssp-ob-r" name="ssp-ob-term" id="ssp-ob-term-yearly" value="yearly"' . checked( 'yearly', $default, false ) . ( $pack['yearly'] ? '' : ' disabled' ) . '>';
			echo '<input type="radio" class="ssp-ob-r" name="ssp-ob-term" id="ssp-ob-term-lifetime" value="lifetime"' . checked( 'lifetime', $default, false ) . ( $pack['lifetime'] ? '' : ' disabled' ) . '>';
			echo '<input type="radio" class="ssp-ob-r" name="ssp-ob-sites" id="ssp-ob-sites-one" value="one" checked>';
			echo '<input type="radio" class="ssp-ob-r" name="ssp-ob-sites" id="ssp-ob-sites-many" value="many">';
			echo '<div class="ssp-ob-switches">';
			if ( $pack['yearly'] && $pack['lifetime'] ) {
				echo '<div class="ssp-ob-switch" role="group" aria-label="' . esc_attr__( 'Licence term', 'super-speedy-settings' ) . '"><label for="ssp-ob-term-yearly">' . esc_html__( 'Yearly', 'super-speedy-settings' ) . '</label><label for="ssp-ob-term-lifetime">' . esc_html__( 'Lifetime', 'super-speedy-settings' ) . '</label></div>';
			}
			echo '<div class="ssp-ob-question"><span>' . esc_html__( 'Is this your only website?', 'super-speedy-settings' ) . '</span><div class="ssp-ob-switch ssp-ob-switch-sites" role="group"><label for="ssp-ob-sites-one">' . esc_html__( 'Yes', 'super-speedy-settings' ) . '</label><label for="ssp-ob-sites-many">' . esc_html__( 'No, I run more', 'super-speedy-settings' ) . '</label></div></div>';
			echo '</div>';
			foreach ( array( 'yearly', 'lifetime' ) as $term ) {
				if ( ! $pack[ $term ] ) {
					continue;
				}
				$t = $pack[ $term ];
				echo '<div class="ssp-ob-offer ssp-ob-offer-' . esc_attr( $term ) . '">';
				echo '<svg class="ssp-ob-spark-frame" viewBox="0 0 500 260" preserveAspectRatio="none" aria-hidden="true"><rect x="1" y="1" width="498" height="258" rx="16" pathLength="1000" class="ssp-ob-spark"/></svg>';
				echo '<span class="ssp-ob-eyebrow">' . esc_html( 'yearly' === $term ? __( 'Yearly', 'super-speedy-settings' ) : __( 'Lifetime', 'super-speedy-settings' ) ) . '</span>';
				echo '<span class="ssp-ob-price"><small>' . esc_html__( 'from', 'super-speedy-settings' ) . '</small> ' . esc_html( self::money( $t['from_price'], $state['currency'] ) ) . '<small>' . esc_html( 'yearly' === $term ? __( '/year', 'super-speedy-settings' ) : __( ' once', 'super-speedy-settings' ) ) . '</small></span>';
				echo '<p class="ssp-ob-sites-text ssp-ob-sites-one-text">' . esc_html( 'yearly' === $term
					? __( 'One site: every plugin this site needs today, and every one it needs as it grows, for one yearly price. Updates and live support from the developers included.', 'super-speedy-settings' )
					: __( 'One site: pay once, never renew. Every plugin in the pack, with updates and support for life.', 'super-speedy-settings' ) ) . '</p>';
				echo '<p class="ssp-ob-sites-text ssp-ob-sites-many-text">' . esc_html__( 'Several sites: one licence covers every plugin on every site it allows. Pick how many sites on the next page.', 'super-speedy-settings' ) . '</p>';
				echo self::link( $t['url'], esc_html__( 'Choose your licence', 'super-speedy-settings' ) . ' <span aria-hidden="true">&rarr;</span>', 'ssp-ob-btn' );
				echo '</div>';
			}
			echo '</div>';
			echo '<p class="ssp-ob-next"><a href="' . esc_url( self::tab_url( 'trial' ) ) . '">' . esc_html__( 'Not sure yet? Try any plugin free for 14 days', 'super-speedy-settings' ) . ' &rarr;</a></p>';
		}

		/** Request a free trial. Sends only on Submit, and shows exactly what it sends. */
		private static function render_trial() {
			$state  = self::state();
			$user   = wp_get_current_user();
			$result = get_transient( 'ssp_trial_result_' . get_current_user_id() );
			if ( isset( $_GET['sent'] ) && is_array( $result ) ) {
				delete_transient( 'ssp_trial_result_' . get_current_user_id() );
				echo '<div class="notice ' . ( $result['ok'] ? 'notice-success' : 'notice-error' ) . ' ssp-ob-notice"><p>' . esc_html( $result['message'] ) . '</p></div>';
			} else {
				$result = null;
			}
			$form      = $result && ! $result['ok'] ? $result['form'] : array();
			$preselect = isset( $_GET['plugin'] ) ? array( sanitize_key( wp_unslash( $_GET['plugin'] ) ) ) : ( $form['plugins'] ?? array() );
			if ( ! $preselect && $state['plugins'] ) {
				$installed = array_values( array_filter( array_keys( $state['plugins'] ), array( __CLASS__, 'is_installed' ) ) );
				$recs      = self::recommend( array_values( $state['plugins'] ), self::site_facts(), $installed );
				$preselect = $recs ? array( $recs[0]['plugin']['slug'] ) : array();
			}
			$facts = self::shareable_facts();

			echo '<section class="ssp-ob-hero ssp-ob-hero-compact"><div class="ssp-ob-hero-copy">';
			echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html__( 'Free trial', 'super-speedy-settings' ) . '</span>';
			/* translators: %d: days */
			echo '<h1 class="ssp-ob-h1">' . esc_html( sprintf( __( 'Try it on this site free for %d days', 'super-speedy-settings' ), self::TRIAL_DAYS ) ) . '</h1>';
			echo '<p class="ssp-ob-sub">' . esc_html__( 'Tell us about the site and what is slow. We set up the trial for you and email you a download with the licence key already in it, so it is licensed the moment you activate it.', 'super-speedy-settings' ) . '</p>';
			echo '</div></section>';

			echo '<form class="ssp-ob-trial" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-site-url="' . esc_attr( home_url( '/' ) ) . '" data-facts="' . esc_attr( wp_json_encode( $facts ) ) . '">';
			echo '<input type="hidden" name="action" value="ssp_trial_request">';
			wp_nonce_field( 'ssp_trial_request' );
			echo '<div class="ssp-ob-trial-grid"><div class="ssp-ob-card">';
			echo '<p><label for="ssp-ob-email">' . esc_html__( 'Email for the reply', 'super-speedy-settings' ) . '</label><input type="email" id="ssp-ob-email" name="email" required value="' . esc_attr( $form['email'] ?? $user->user_email ) . '"></p>';
			echo '<p><label for="ssp-ob-name">' . esc_html__( 'Your name', 'super-speedy-settings' ) . '</label><input type="text" id="ssp-ob-name" name="name" maxlength="100" value="' . esc_attr( $form['name'] ?? $user->display_name ) . '"></p>';
			if ( $state['plugins'] ) {
				echo '<fieldset><legend>' . esc_html__( 'Plugins to try', 'super-speedy-settings' ) . '</legend><div class="ssp-ob-picks">';
				foreach ( $state['plugins'] as $p ) {
					if ( ! $p['trial'] ) {
						continue;
					}
					$installed = self::is_installed( $p['slug'] );
					echo '<label class="ssp-ob-pick"><input type="checkbox" name="plugins[]" value="' . esc_attr( $p['slug'] ) . '"' . checked( in_array( $p['slug'], $preselect, true ), true, false ) . '> ' . self::tile( $p['slug'], $p['name'], 28 ) . ' <span>' . esc_html( $p['name'] ) . ( $installed ? ' <em>' . esc_html__( '(installed)', 'super-speedy-settings' ) . '</em>' : '' ) . '</span></label>'; // phpcs:ignore
				}
				echo '</div></fieldset>';
			}
			echo '<p><label for="ssp-ob-req">' . esc_html__( 'What does the site do, what is slow, and what do you want from the trial?', 'super-speedy-settings' ) . '</label><textarea id="ssp-ob-req" name="requirements" rows="6" minlength="10" maxlength="2000" required>' . esc_textarea( $form['requirements'] ?? '' ) . '</textarea></p>';
			echo '<p><label class="ssp-ob-inline"><input type="checkbox" name="include_facts" value="1" id="ssp-ob-facts" checked> ' . esc_html__( 'Include this site\'s facts (shown on the right) so we can set the trial up for it', 'super-speedy-settings' ) . '</label></p>';
			echo '<p><button type="submit" class="ssp-ob-btn">' . esc_html__( 'Submit trial request', 'super-speedy-settings' ) . ' <span aria-hidden="true">&rarr;</span></button></p>';
			echo '</div><aside class="ssp-ob-card ssp-ob-sends"><h2>' . esc_html__( 'Exactly what Submit sends', 'super-speedy-settings' ) . '</h2>';
			echo '<p>' . esc_html__( 'Nothing is sent until you press Submit. Then this, and only this, goes to:', 'super-speedy-settings' ) . ' <code>' . esc_html( self::TRIAL_URL ) . '</code></p>';
			$preview = self::trial_payload( array(
				'email' => $form['email'] ?? $user->user_email, 'name' => $form['name'] ?? $user->display_name,
				'plugins' => $preselect, 'requirements' => $form['requirements'] ?? '', 'include_facts' => '1',
			), array_keys( $state['plugins'] ) );
			echo '<pre class="ssp-ob-preview" aria-live="polite">' . esc_html( wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			echo '<p class="ssp-ob-small">' . esc_html__( 'No licence key, no cookies, no other site data. We use it only to set up and follow up your trial.', 'super-speedy-settings' ) . '</p>';
			echo '</aside></div></form>';
		}

		/** Deals: offer and launch items from the dashboard feed. */
		private static function render_deals() {
			$items = self::deal_items();
			echo '<h1 class="ssp-ob-h1 ssp-ob-h1-dark">' . esc_html__( 'Deals and launches', 'super-speedy-settings' ) . '</h1>';
			if ( ! $items ) {
				echo '<div class="ssp-ob-card"><p>' . esc_html__( 'No deals right now. New ones show here, and as a purple count on the Super Speedy menu.', 'super-speedy-settings' ) . '</p></div>';
				return;
			}
			echo '<div class="ssp-ob-deals">';
			foreach ( $items as $item ) {
				$is_offer = 'offer' === $item['type'];
				$label    = '' !== $item['label'] ? $item['label'] : ( $is_offer ? __( 'Offer', 'super-speedy-settings' ) : __( 'Coming soon', 'super-speedy-settings' ) );
				echo '<a class="ssp-ob-deal ssp-ob-deal-' . esc_attr( $item['type'] ) . '" data-id="' . esc_attr( $item['id'] ) . '" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">';
				if ( $is_offer ) {
					echo '<svg class="ssp-ob-spark-frame" viewBox="0 0 500 260" preserveAspectRatio="none" aria-hidden="true"><rect x="1" y="1" width="498" height="258" rx="16" pathLength="1000" class="ssp-ob-spark"/></svg>';
				}
				echo '<span class="ssp-ob-eyebrow"><span class="ssp-ob-live" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
				if ( $item['image'] ) {
					echo '<img class="ssp-ob-deal-img" src="' . esc_url( $item['image'] ) . '" alt="" width="64" height="64" loading="lazy" referrerpolicy="no-referrer">';
				}
				echo '<span class="ssp-ob-deal-title">' . esc_html( $item['title'] ) . '</span>';
				if ( '' !== $item['text'] ) {
					echo '<span class="ssp-ob-deal-text">' . esc_html( $item['text'] ) . '</span>';
				}
				if ( $is_offer && $item['end'] && $item['end'] - time() < 14 * DAY_IN_SECONDS ) {
					// Driven by onboarding.js (same cell markup as the dashboard widget's countdown).
					echo '<span class="ssw-countdown ssp-ob-countdown" data-ssw-end="' . esc_attr( (string) $item['end'] ) . '" role="timer">';
					foreach ( array( 'd' => __( 'days', 'super-speedy-settings' ), 'h' => __( 'hours', 'super-speedy-settings' ), 'm' => __( 'mins', 'super-speedy-settings' ), 's' => __( 'secs', 'super-speedy-settings' ) ) as $unit => $name ) {
						echo '<span class="ssw-cd"><span class="ssw-cd-v" data-ssw-unit="' . esc_attr( $unit ) . '">--</span><span class="ssw-cd-u">' . esc_html( $name ) . '</span></span>';
					}
					echo '</span>';
				}
				if ( $item['products'] ) {
					echo '<span class="ssp-ob-deal-tiles">';
					foreach ( $item['products'] as $product ) {
						echo '<span class="ssp-ob-deal-tile">' . ( $product['image'] ? '<img src="' . esc_url( $product['image'] ) . '" alt="" width="44" height="44" loading="lazy" referrerpolicy="no-referrer">' : self::tile( '', $product['name'], 44 ) ) . '<span>' . esc_html( $product['name'] ) . '</span></span>'; // phpcs:ignore
					}
					echo '</span>';
				}
				$button = '' !== $item['button'] ? $item['button'] : __( 'See the offer', 'super-speedy-settings' );
				echo '<span class="ssp-ob-btn">' . esc_html( $button ) . ' <span aria-hidden="true">&rarr;</span></span></a>';
			}
			echo '</div>';
		}
	}
}
