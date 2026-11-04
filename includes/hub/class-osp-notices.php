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
	/** The line above the title: who is talking, in which plugin. */
	const SOURCE = 'הודעה מ-OC Pluginz · OC Popup';
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
	 * @return array[] list of {id, type, title, body, url, url_text, image}
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
			$img   = isset( $n['image'] ) ? (string) $n['image'] : '';
			$out[] = array(
				'id'       => $id,
				'type'     => in_array( $type, self::TYPES, true ) ? $type : 'info',
				'title'    => $title,
				'body'     => trim( strip_tags( isset( $n['body'] ) ? (string) $n['body'] : '' ) ),
				'url'      => preg_match( '#^https?://#i', $url ) ? $url : '',
				'url_text' => trim( strip_tags( isset( $n['url_text'] ) ? (string) $n['url_text'] : '' ) ),
				'image'    => preg_match( '#^https://#i', $img ) ? $img : '',
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
		self::styles();
		foreach ( $list as $n ) {
			$GLOBALS['ocpz_notices_shown'][ $n['id'] ] = true;
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=' . rawurlencode( $n['id'] ) ), self::ACTION . '_' . $n['id'] );
			$img     = (string) ( $n['image'] ?? '' );
			echo '<div class="notice ocpz-notice ocpz-banner ocpz-t-' . esc_attr( $n['type'] ) . ' is-dismissible" data-ocpz-dismiss="' . esc_url( $dismiss ) . '">';
			if ( '' !== $img ) {
				// The image lives on the hub; if it cannot load, the column goes and the text takes the width.
				echo '<div class="ocpz-b-img"><img src="' . esc_url( $img ) . '" alt="" loading="lazy" onerror="this.parentNode.style.display=\'none\'" /></div>';
			}
			echo '<div class="ocpz-b-tx">';
			echo '<p class="ocpz-b-src">' . esc_html( self::SOURCE ) . '</p>';
			echo '<p class="ocpz-b-h">' . esc_html( $n['title'] ) . '</p>';
			if ( '' !== $n['body'] ) {
				echo '<p class="ocpz-b-b">' . nl2br( esc_html( $n['body'] ) ) . '</p>';
			}
			echo '<p class="ocpz-b-cta">';
			if ( '' !== $n['url'] ) {
				echo '<a class="ocpz-b-p" href="' . esc_url( $n['url'] ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $n['url_text'] ? $n['url_text'] : 'לפרטים' ) . '</a>';
			}
			echo '<a class="ocpz-b-g ocpz-b-ok" href="#" role="button">הבנתי</a>';
			echo '</p></div></div>' . "\n";
		}
		if ( empty( $GLOBALS['ocpz_notices_js'] ) ) {
			$GLOBALS['ocpz_notices_js'] = true;
			// "הבנתי" and the X WordPress adds both tell the plugin to remember the dismissal.
			echo '<script>document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target:null;if(!t)return;var ok=t.closest(".ocpz-banner .ocpz-b-ok"),x=t.closest(".ocpz-notice .notice-dismiss");if(!ok&&!x)return;var n=(ok||x).closest(".ocpz-notice");if(!n)return;if(ok){e.preventDefault();n.style.display="none";}var u=n.getAttribute("data-ocpz-dismiss");if(u){fetch(u,{credentials:"same-origin"}).catch(function(){});}});</script>' . "\n";
		}
	}

	/**
	 * The banner's stylesheet, once per request across OC plugins. Logical
	 * properties, so the accent bar and the X land on the right side in RTL and LTR.
	 */
	private static function styles() {
		if ( ! empty( $GLOBALS['ocpz_notices_css'] ) ) {
			return;
		}
		$GLOBALS['ocpz_notices_css'] = true;
		echo '<style>'
			. '.ocpz-banner{display:flex!important;align-items:stretch;padding:0!important;margin:14px 20px 14px 2px!important;background:#fff;border:1px solid #d9d4f5!important;border-inline-start:6px solid #534ab7!important;border-radius:4px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.06)}'
			. '.ocpz-banner .ocpz-b-img{flex:0 0 200px;background:#eeedfe;border-inline-end:1px solid #e6e2f8}'
			. '.ocpz-banner .ocpz-b-img img{display:block;width:200px;height:100%;min-height:124px;object-fit:cover}'
			. '.ocpz-banner .ocpz-b-tx{flex:1;min-width:0;padding:16px 20px;padding-inline-end:48px;display:flex;flex-direction:column;justify-content:center}'
			. '.ocpz-banner p{margin:0!important;padding:0!important}'
			. '.ocpz-banner .ocpz-b-src{font-size:12px;font-weight:600;color:#534ab7;margin-bottom:4px!important}'
			. '.ocpz-banner .ocpz-b-h{font-size:18px;font-weight:700;color:#26215c;line-height:1.35}'
			. '.ocpz-banner .ocpz-b-b{font-size:14px;color:#50556b;line-height:1.6;margin-top:4px!important}'
			. '.ocpz-banner .ocpz-b-cta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px!important}'
			. '.ocpz-banner .ocpz-b-p{background:#534ab7;color:#fff!important;text-decoration:none;font-size:14px;font-weight:600;padding:8px 16px;border-radius:8px;line-height:1.3}'
			. '.ocpz-banner .ocpz-b-p:hover{background:#3c3489}'
			. '.ocpz-banner .ocpz-b-g{color:#3c3489!important;background:#fff;border:1px solid #d9d4f5;text-decoration:none;font-size:14px;font-weight:600;padding:7px 14px;border-radius:8px;line-height:1.3}'
			. '.ocpz-banner .ocpz-b-g:hover{background:#f3f1fc}'
			. '.ocpz-banner.ocpz-t-success{border-inline-start-color:#15803d!important}.ocpz-banner.ocpz-t-success .ocpz-b-src{color:#15803d}.ocpz-banner.ocpz-t-success .ocpz-b-p{background:#15803d}'
			. '.ocpz-banner.ocpz-t-warning{border-inline-start-color:#b45309!important}.ocpz-banner.ocpz-t-warning .ocpz-b-src{color:#b45309}.ocpz-banner.ocpz-t-warning .ocpz-b-p{background:#b45309}'
			. '.ocpz-banner .notice-dismiss{top:4px}'
			. '@media(max-width:640px){.ocpz-banner{flex-direction:column}.ocpz-banner .ocpz-b-img{flex-basis:auto}.ocpz-banner .ocpz-b-img img{width:100%;height:150px;min-height:0}}'
			. '</style>' . "\n";
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
