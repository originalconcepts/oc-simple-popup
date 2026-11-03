<?php
/**
 * Notices from OC Pluginz (my.ocpluginz.com): what the hub answers under
 * `notices` next to the licence (a new plugin, an important release, a service
 * message) is kept with the entitlement (osp_hub) and shown on every admin
 * screen, like any plugin's notice, until the admin dismisses it.
 *
 * Every OC plugin on a site shares one dismissal list (user meta
 * ocpz_dismissed_notices) and one guard per request ($GLOBALS['ocpz_notices_shown']),
 * so a notice meant for every product appears once, however many OC plugins
 * the site runs. The list is refreshed whenever the licence is (hourly, and
 * when the account screen opens), so a notice reaches the site within the hour
 * and a withdrawn one leaves the same way. Agency mode and a legacy site
 * without a key never ask the hub, so they never have a list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OSP_Notices {

	const META   = 'ocpz_dismissed_notices';
	const ACTION = 'osp_dismiss_notice';
	const TYPES  = array( 'info', 'success', 'warning' );
	const MAX    = 5;

	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/* ---------------- The rules, without WordPress ---------------- */

	/**
	 * The notices in the hub's answer, cleaned: at most MAX, each with an id and a title.
	 *
	 * @param array $data The hub's answer.
	 * @return array[] list of {id, type, title, body, url, url_text}
	 */
	public static function from_answer( array $data ) {
		$out = array();
		$raw = isset( $data['notices'] ) ? (array) $data['notices'] : array();
		foreach ( $raw as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$id    = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $n['id'] ) ? (string) $n['id'] : '' ) );
			$title = trim( strip_tags( isset( $n['title'] ) ? (string) $n['title'] : '' ) );
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$type  = isset( $n['type'] ) ? (string) $n['type'] : 'info';
			$url   = isset( $n['url'] ) ? (string) $n['url'] : '';
			$out[] = array(
				'id'       => $id,
				'type'     => in_array( $type, self::TYPES, true ) ? $type : 'info',
				'title'    => $title,
				'body'     => trim( strip_tags( isset( $n['body'] ) ? (string) $n['body'] : '' ) ),
				'url'      => preg_match( '#^https?://#i', $url ) ? $url : '',
				'url_text' => trim( strip_tags( isset( $n['url_text'] ) ? (string) $n['url_text'] : '' ) ),
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What is left to show: not dismissed by this user, not already shown by another OC plugin this request.
	 *
	 * @param array[] $list      The kept notices.
	 * @param string[] $dismissed The user's dismissed ids.
	 * @param array   $shown     Ids shown already this request (id => true).
	 * @return array[]
	 */
	public static function pending( array $list, array $dismissed, array $shown ) {
		$out = array();
		foreach ( $list as $n ) {
			if ( ! is_array( $n ) || '' === ( isset( $n['id'] ) ? (string) $n['id'] : '' ) ) {
				continue;
			}
			if ( in_array( $n['id'], $dismissed, true ) || isset( $shown[ $n['id'] ] ) ) {
				continue;
			}
			$out[] = $n;
		}
		return $out;
	}

	/* ---------------- WordPress ---------------- */

	/** @return string[] */
	private static function dismissed() {
		$list = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	public static function render() {
		if ( OSP_Hub::agency() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = OSP_Hub::state();
		$kept  = isset( $state['notices'] ) ? $state['notices'] : array();
		if ( ! is_array( $kept ) || ! $kept ) {
			return;
		}
		if ( ! isset( $GLOBALS['ocpz_notices_shown'] ) || ! is_array( $GLOBALS['ocpz_notices_shown'] ) ) {
			$GLOBALS['ocpz_notices_shown'] = array();
		}
		$list = self::pending( $kept, self::dismissed(), $GLOBALS['ocpz_notices_shown'] );
		if ( ! $list ) {
			return;
		}
		foreach ( $list as $n ) {
			$GLOBALS['ocpz_notices_shown'][ $n['id'] ] = true;
			$type     = isset( $n['type'] ) ? (string) $n['type'] : 'info';
			$body     = isset( $n['body'] ) ? (string) $n['body'] : '';
			$url      = isset( $n['url'] ) ? (string) $n['url'] : '';
			$url_text = isset( $n['url_text'] ) ? (string) $n['url_text'] : '';
			$dismiss  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=' . rawurlencode( $n['id'] ) ), self::ACTION . '_' . $n['id'] );
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible ocpz-notice" data-ocpz-dismiss="' . esc_url( $dismiss ) . '">';
			echo '<p><strong>' . esc_html( isset( $n['title'] ) ? (string) $n['title'] : '' ) . '</strong>';
			if ( '' !== $body ) {
				echo '<br>' . nl2br( esc_html( $body ) );
			}
			if ( '' !== $url ) {
				echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $url_text ? $url_text : $url ) . '</a>';
			}
			echo '</p><p style="margin:0 0 6px;color:#646970;font-size:12px">OC Pluginz</p></div>' . "\n";
		}
		if ( empty( $GLOBALS['ocpz_notices_js'] ) ) {
			$GLOBALS['ocpz_notices_js'] = true;
			// The X that WordPress adds to a dismissible notice only hides it; this tells the plugin to remember.
			echo '<script>document.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest(".ocpz-notice .notice-dismiss"):null;if(!b)return;var n=b.closest(".ocpz-notice");if(n&&n.getAttribute("data-ocpz-dismiss")){fetch(n.getAttribute("data-ocpz-dismiss"),{credentials:"same-origin"}).catch(function(){});}});</script>' . "\n";
		}
	}

	/** Remember the dismissal for this user, for every OC plugin on the site. */
	public static function dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		$id = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $_GET['notice'] ) ? (string) $_GET['notice'] : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next
		check_admin_referer( self::ACTION . '_' . $id );
		if ( '' !== $id ) {
			$list   = self::dismissed();
			$list[] = $id;
			update_user_meta( get_current_user_id(), self::META, array_values( array_unique( array_slice( $list, -100 ) ) ) );
		}
		if ( ! headers_sent() ) {
			status_header( 204 );
		}
		exit;
	}
}
