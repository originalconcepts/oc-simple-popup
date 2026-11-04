<?php
/**
 * The link to OC Pluginz (my.ocpluginz.com): the licence that brings updates
 * from the hub and lists this install among the hub's sites.
 *
 * One option (osp_hub) holds the key, a copy of the entitlement the hub
 * last answered with, and the hub's notices for the admin (OSP_Notices). The
 * hub is asked again hourly (WP-Cron) and when the
 * account screen opens; an unreachable hub is survived on the cached copy, a
 * hub that refuses the key is not.
 *
 * The licence decides whether popups show on the site (allows()), like שקוף:
 * an active licence, or one past its end within the grace days. A declined
 * charge (past_due), a suspended, deleted or expired licence, or a key the
 * hub refused, stops them at the next check (hourly, or when the account
 * screen opens); nothing is deleted, and they come back once the licence is
 * active again. It also decides whether updates come from the hub
 * (updates_allowed()) and lists this site in the owner's OC Pluginz account.
 *
 * Three kinds of installs share this code base:
 *   - Sites that had popups before the licence existed (1.7 and earlier, our
 *     own clients updating from GitHub): marked once as legacy() on the first
 *     request of this version, they keep showing popups without a key and
 *     get updates from GitHub through OSP_GitHub_Updater as before.
 *   - OC Pluginz customers: a key is connected on the account screen (or
 *     fixed as OSP_LICENSE_KEY in wp-config.php). The licence decides, also
 *     on a legacy site once a key is set. Once the hub has answered for the
 *     key, connected() is true, OSP_Hub_Updater serves updates and the GitHub
 *     updater stays silent.
 *   - A new install without a key: the popups can be built and previewed in
 *     the admin, but are not shown on the site until a key is connected.
 * Agency mode (OC_PLUGINZ_AGENCY = true in wp-config.php) skips the hub
 * entirely: no calls, no notices, popups always on, GitHub updates.
 *
 * What goes to the hub: the key, the product, this site's domain and address,
 * the plugin and WordPress versions. Nothing about visitors or popups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OSP_Hub {

	const URL         = 'https://my.ocpluginz.com';
	const PRODUCT     = 'oc-popup';
	const OPTION      = 'osp_hub';
	const LEGACY      = 'osp_hub_legacy';
	const CRON        = 'osp_hub_refresh';
	const ACCOUNT_URL = 'https://my.ocpluginz.com/account/';
	const PRICING_URL = 'https://ocpluginz.com/oc-popup.html#pricing';
	const CAP         = 'manage_options';

	/** The hub's thresholds (OCPZS_Entitlements), mirrored so a cached copy ages the same way. */
	const EXPIRING_DAYS = 14;
	const GRACE_DAYS    = 7;

	/** Codes that mean "this key buys nothing here": the cached entitlement goes. An outage keeps it. */
	const REFUSALS = array( 'ocpzs_bad_key', 'ocpzs_inactive', 'ocpzs_seats_full', 'ocpzs_bad_product', 'ocpzs_bad_request' );

	/** @var bool|null Per-request memo of allows(). */
	private static $allowed = null;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'mark_install' ), 1 );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	/* ---------------- The rules, without WordPress ---------------- */

	/**
	 * valid | expiring | grace | expired, from expires_at as the hub ages it. No end date never expires.
	 *
	 * @param array|null $ent The entitlement.
	 * @param int        $now Unix time.
	 * @return string
	 */
	public static function lifecycle( $ent, $now ) {
		if ( ! is_array( $ent ) ) {
			return 'expired';
		}
		$end = isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '';
		if ( '' === $end ) {
			return 'valid';
		}
		$exp = strtotime( $end );
		if ( false === $exp ) {
			return isset( $ent['state'] ) ? (string) $ent['state'] : 'valid';
		}
		if ( $exp > $now ) {
			return ( $exp - $now < self::EXPIRING_DAYS * DAY_IN_SECONDS ) ? 'expiring' : 'valid';
		}
		return ( $now - $exp < self::GRACE_DAYS * DAY_IN_SECONDS ) ? 'grace' : 'expired';
	}

	/**
	 * Are updates offered? The hub's own rule for a download (OCPZS_Packages::license_for): an active
	 * licence, or one past due within its grace days.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function decide( array $state, $key, $now ) {
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( '' === $key || null === $ent ) {
			return false;
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		$life   = self::lifecycle( $ent, $now );
		if ( in_array( $status, array( 'suspended', 'deleted' ), true ) || 'expired' === $life ) {
			return false;
		}
		return 'active' === $status || 'grace' === $life;
	}

	/**
	 * Where the licence stands, for the screen and the notice: '' (fine), no_key, refused, unverified,
	 * suspended, grace, expired.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return string
	 */
	public static function reason( array $state, $key, $now ) {
		if ( '' === $key ) {
			return 'no_key';
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return ! empty( $state['refused'] ) ? 'refused' : 'unverified';
		}
		if ( in_array( isset( $ent['status'] ) ? (string) $ent['status'] : '', array( 'suspended', 'deleted' ), true ) ) {
			return 'suspended';
		}
		if ( ! self::decide( $state, $key, $now ) ) {
			return 'expired';
		}
		return 'grace' === self::lifecycle( $ent, $now ) ? 'grace' : '';
	}

	/** An OC Pluginz key: ocpz_ and hex. */
	public static function looks_like_key( $key ) {
		return 1 === preg_match( '/^ocpz_[a-f0-9]{20,64}$/i', (string) $key );
	}

	/** The domain the hub binds a seat to: the host, lower case, without www. */
	public static function domain_of( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/** The key as the screen shows it: the start and the end. */
	public static function masked( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 14 ? substr( $key, 0, 9 ) . '…' . substr( $key, -4 ) : $key;
	}

	/* ---------------- The site's state ---------------- */

	public static function url() {
		return rtrim( defined( 'OSP_HUB_URL' ) ? (string) OSP_HUB_URL : self::URL, '/' );
	}

	/** Agency mode (our own sites): no licence, no hub calls, no notices. */
	public static function agency() {
		return defined( 'OC_PLUGINZ_AGENCY' ) && OC_PLUGINZ_AGENCY;
	}

	/** The key fixed in wp-config.php. */
	public static function key_from_constant() {
		return defined( 'OSP_LICENSE_KEY' ) && '' !== trim( (string) OSP_LICENSE_KEY );
	}

	/** @return array */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function patch( array $patch ) {
		update_option( self::OPTION, array_merge( self::state(), $patch ), false );
		self::$allowed = null;
	}

	/**
	 * Once per site, on the first request of this version: did the site already have popups (it was
	 * installed before the licence existed)? Then it stays legacy() for good. A new install has none
	 * at that moment, because nothing can be built before the first admin request.
	 */
	public static function mark_install() {
		if ( false !== get_option( self::LEGACY ) ) {
			return;
		}
		global $wpdb;
		$had = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", OSP_Simple_Popup::CPT ) );
		add_option( self::LEGACY, $had > 0 ? '1' : '0', '', 'yes' );
	}

	/** A site that had popups before the licence existed: they keep showing without a key. */
	public static function legacy() {
		return '1' === get_option( self::LEGACY );
	}

	public static function key() {
		if ( self::key_from_constant() ) {
			return trim( (string) OSP_LICENSE_KEY );
		}
		$s = self::state();
		return isset( $s['key'] ) ? (string) $s['key'] : '';
	}

	public static function domain() {
		return self::domain_of( home_url( '/' ) );
	}

	/** @return array|null the entitlement the hub last answered with */
	public static function entitlement() {
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) ? $s['entitlement'] : null;
	}

	/**
	 * Is this site in the hub's hands? A key is set (not agency mode) and the hub has answered for it
	 * at least once — accepted it (entitlement cached) or refused it. From then on OSP_Hub_Updater owns
	 * updates and the GitHub updater stays silent, whatever the licence's state: an expired or refused
	 * licence means no updates, not GitHub updates. Disconnecting (or removing the key) hands updates
	 * back to GitHub. A wp-config key the hub has never been reached for does not count yet.
	 *
	 * @return bool
	 */
	public static function connected() {
		if ( self::agency() || '' === self::key() ) {
			return false;
		}
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) || ! empty( $s['refused'] );
	}

	public static function updates_allowed() {
		return ! self::agency() && self::decide( self::state(), self::key(), time() );
	}

	/**
	 * May popups show on the site right now? Agency mode: yes. No key: only on a legacy() site. A key:
	 * the licence decides, the same rule as updates (decide()): active, or within its grace days.
	 *
	 * @return bool
	 */
	public static function allows() {
		if ( null === self::$allowed ) {
			if ( self::agency() ) {
				$ok = true;
			} elseif ( '' === self::key() ) {
				$ok = self::legacy();
			} else {
				$ok = self::decide( self::state(), self::key(), time() );
			}
			self::$allowed = (bool) apply_filters( 'osp_hub_allows', $ok );
		}
		return self::$allowed;
	}

	public static function status() {
		return self::reason( self::state(), self::key(), time() );
	}

	/** The account screen in this site's admin: a hidden page of the suite's shared OC Pluginz menu. */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . OSP_Hub_Admin::PAGE );
	}

	/* ---------------- The hub ---------------- */

	/** @return array */
	private static function identity() {
		return array(
			'key'            => self::key(),
			'product'        => self::PRODUCT,
			'domain'         => self::domain(),
			'site_url'       => home_url( '/' ),
			'version'        => OSP_VERSION,
			'plugin_version' => OSP_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
		);
	}

	/** The headers every call to the hub carries. */
	public static function headers() {
		return array(
			'X-OCPZ-Key'     => self::key(),
			'X-OCPZ-Site'    => self::domain(),
			'X-OCPZ-Version' => OSP_VERSION,
			'Accept'         => 'application/json',
			'User-Agent'     => 'OC-Popup/' . OSP_VERSION . '; ' . home_url( '/' ),
		);
	}

	/**
	 * A call to /wp-json/ocpz/v1{$path}.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Under /wp-json/ocpz/v1.
	 * @param array  $body   Extra parameters.
	 * @return array|WP_Error the decoded answer
	 */
	private static function request( $method, $path, array $body = array() ) {
		$body = array_merge( self::identity(), $body );
		$args = array(
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => self::headers(),
		);
		$url  = self::url() . '/wp-json/ocpz/v1' . $path;
		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'osp_hub_http', sprintf( 'OC Pluginz ענה בסטטוס %d.', $code ) );
		}
		if ( $code >= 400 || ( isset( $data['ok'] ) && false === $data['ok'] ) ) {
			$msg = isset( $data['message'] ) ? (string) $data['message'] : sprintf( 'OC Pluginz ענה בסטטוס %d.', $code );
			return new WP_Error( isset( $data['code'] ) ? (string) $data['code'] : 'osp_hub_http', $msg );
		}
		return $data;
	}

	/** The hub's refusals in words the store owner can act on. */
	private static function friendly( WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case 'ocpzs_bad_key':
				return 'OC Pluginz לא מזהה את המפתח הזה עבור OC Popup. בדקו אותו בחשבון OC Pluginz שלכם.';
			case 'ocpzs_inactive':
				return 'הרישיון הזה לא פעיל. אפשר לחדש אותו בחשבון OC Pluginz.';
			case 'ocpzs_seats_full':
				return 'כל האתרים שהרישיון מאפשר כבר מחוברים. נתקו קודם אתר אחר (במסך החשבון שלו, או בחשבון OC Pluginz).';
			case 'ocpzs_bad_product':
				return 'OC Pluginz עוד לא מציע את OC Popup. פנו ל-Original Concepts.';
			case 'rest_forbidden':
				return 'OC Pluginz דחה את הבקשה כרגע. נסו שוב בעוד דקה.';
		}
		return $error->get_error_message();
	}

	/**
	 * Keep what the hub answered.
	 *
	 * @param array $data The decoded answer.
	 * @return true|WP_Error
	 */
	private static function accept( array $data ) {
		$ent = ( isset( $data['entitlement'] ) && is_array( $data['entitlement'] ) ) ? $data['entitlement'] : null;
		if ( null === $ent ) {
			return new WP_Error( 'osp_hub_shape', 'OC Pluginz ענה בלי רישיון.' );
		}
		$s = self::state();
		self::patch(
			array(
				'entitlement' => $ent,
				'notices'     => OSP_Notices::from_answer( $data ),
				'latest'      => isset( $data['latest'] ) ? (string) $data['latest'] : ( isset( $s['latest'] ) ? (string) $s['latest'] : '' ),
				'checked_at'  => time(),
				'error'       => '',
				'error_code'  => '',
				'error_at'    => 0,
				'refused'     => false,
			)
		);
		return true;
	}

	/** A failure: a refusal drops the cached entitlement, an outage does not. */
	private static function note_error( WP_Error $error ) {
		$patch = array(
			'error'      => self::friendly( $error ),
			'error_code' => $error->get_error_code(),
			'error_at'   => time(),
		);
		if ( in_array( $error->get_error_code(), self::REFUSALS, true ) ) {
			$patch['entitlement'] = null;
			$patch['refused']     = true;
		}
		self::patch( $patch );
	}

	/**
	 * Take a seat for this site. The key is kept only once the hub accepts it, so a typo never leaves
	 * the site half connected.
	 *
	 * @param string $key The licence key.
	 * @return true|WP_Error
	 */
	public static function connect( $key ) {
		$key = trim( (string) $key );
		if ( ! self::looks_like_key( $key ) ) {
			return new WP_Error( 'osp_hub_key', 'זה לא נראה כמו מפתח רישיון של OC Pluginz (ocpz_…).' );
		}
		$previous = self::state();
		update_option( self::OPTION, array( 'key' => $key ), false );
		self::$allowed = null;
		$res = self::request( 'POST', '/sdk/activate' );
		$ok  = is_wp_error( $res ) ? $res : self::accept( $res );
		if ( is_wp_error( $ok ) ) {
			update_option( self::OPTION, $previous, false );
			self::$allowed = null;
			return new WP_Error( $ok->get_error_code(), self::friendly( $ok ) );
		}
		self::patch( array( 'activated_at' => time() ) );
		self::maybe_schedule();
		return true;
	}

	/**
	 * Ask the hub again; a wp-config key that never took its seat takes it first.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh() {
		if ( self::agency() || '' === self::key() ) {
			return new WP_Error( 'osp_hub_no_key', 'אין מפתח רישיון.' );
		}
		$s     = self::state();
		$fresh = self::key_from_constant() && null === self::entitlement() && empty( $s['refused'] );
		$res   = self::request( $fresh ? 'POST' : 'GET', $fresh ? '/sdk/activate' : '/sdk/status' );
		if ( is_wp_error( $res ) ) {
			self::note_error( $res );
			return new WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		$ok = self::accept( $res );
		if ( true === $ok && $fresh ) {
			self::patch( array( 'activated_at' => time() ) );
		}
		return $ok;
	}

	/** The hourly check (WP-Cron). */
	public static function cron() {
		self::refresh();
	}

	/**
	 * Ask again when the last contact is older than $max_age seconds (the account screen).
	 *
	 * @param int $max_age Seconds.
	 */
	public static function refresh_if_stale( $max_age = 600 ) {
		if ( self::agency() || '' === self::key() ) {
			return;
		}
		$s    = self::state();
		$last = max( isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0, isset( $s['error_at'] ) ? (int) $s['error_at'] : 0 );
		if ( time() - $last >= (int) $max_age ) {
			self::refresh();
		}
	}

	/** Free the seat at the hub and forget the key. Updates go back to GitHub. */
	public static function disconnect() {
		if ( '' !== self::key() && ! self::agency() ) {
			self::request( 'POST', '/sdk/deactivate' );
		}
		delete_option( self::OPTION );
		self::$allowed = null;
		wp_clear_scheduled_hook( self::CRON );
		return true;
	}

	/** The hourly check runs while a key is set, and only then. */
	public static function maybe_schedule() {
		$want = '' !== self::key() && ! self::agency();
		$next = wp_next_scheduled( self::CRON );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/* ---------------- The notice ---------------- */

	/** Why popups do not show on the site, in words the store owner can act on; '' when they show. */
	public static function off_text() {
		if ( self::allows() ) {
			return '';
		}
		switch ( self::status() ) {
			case 'no_key':
				return 'הפופ אפים לא מוצגים באתר עד שמחברים רישיון OC Pluginz. אפשר לבנות ולערוך אותם כבר עכשיו.';
			case 'refused':
				return 'OC Pluginz לא קיבל את מפתח הרישיון של OC Popup, ולכן הפופ אפים לא מוצגים באתר. שום דבר לא נמחק.';
			case 'unverified':
				return 'מפתח הרישיון של OC Popup עוד לא אושר מול OC Pluginz, ולכן הפופ אפים לא מוצגים באתר.';
			case 'suspended':
				return 'הרישיון של OC Popup מושהה, ולכן הפופ אפים לא מוצגים באתר. שום דבר לא נמחק, והם יחזרו ברגע שהרישיון יחודש.';
		}
		return 'הרישיון של OC Popup לא בתוקף (תשלום שלא עבר או מנוי שהסתיים), ולכן הפופ אפים לא מוצגים באתר. שום דבר לא נמחק, והם יחזרו ברגע שהרישיון יחודש.';
	}

	/**
	 * On the popup screens (the list and the editor; the account page says it itself): why the popups
	 * are off the site, or a warning while a licence is on its grace days. A legacy site without a key
	 * is never nagged.
	 */
	public static function notice() {
		if ( self::agency() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || OSP_Simple_Popup::CPT !== $screen->post_type ) {
			return;
		}
		$text = self::off_text();
		$kind = 'error';
		if ( '' === $text ) {
			if ( 'grace' !== self::status() ) {
				return;
			}
			$text = 'תוקף הרישיון של OC Popup הסתיים והוא בימי חסד. חדשו עכשיו, אחרת הפופ אפים יפסיקו להופיע באתר.';
			$kind = 'warning';
		}
		echo '<div class="notice notice-' . $kind . '"><p>' . esc_html( $text )
			. ' <a href="' . esc_url( self::settings_url() ) . '">חשבון OC Pluginz</a></p></div>';
	}
}
