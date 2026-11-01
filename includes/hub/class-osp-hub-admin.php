<?php
/**
 * The "חשבון OC Pluginz" screen under the popups menu: connect a licence key,
 * see its status, sites and updates, refresh, check for updates, disconnect.
 * Opening the screen re-asks the hub when the last contact is older than ten
 * minutes. ("Check for updates" is OSP_Hub_Updater::check_now, shared with
 * the link in the plugin's row on the Plugins screen.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OSP_Hub_Admin {

	const PAGE = 'osp-account';

	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . OSP_BASENAME, array( __CLASS__, 'action_link' ) );
		foreach ( array( 'osp_hub_connect', 'osp_hub_refresh', 'osp_hub_disconnect' ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle' ) );
		}
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . OSP_Simple_Popup::CPT,
			'חשבון OC Pluginz',
			'חשבון OC Pluginz',
			OSP_Hub::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/** "רישיון" next to Deactivate on the Plugins screen. */
	public static function action_link( $links ) {
		if ( current_user_can( OSP_Hub::CAP ) ) {
			array_unshift( $links, '<a href="' . esc_url( OSP_Hub::settings_url() ) . '">רישיון</a>' );
		}
		return $links;
	}

	/** admin-post handler: connect / refresh / disconnect. */
	public static function handle() {
		if ( ! current_user_can( OSP_Hub::CAP ) ) {
			wp_die( 'אין הרשאה.' );
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		check_admin_referer( $action );
		$back = OSP_Hub::settings_url();

		switch ( $action ) {
			case 'osp_hub_connect':
				$res = OSP_Hub::connect( isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '' );
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'osp_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				OSP_Hub_Updater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'osp_hub', 'connected', $back ) );
				exit;

			case 'osp_hub_refresh':
				$res = OSP_Hub::refresh();
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'osp_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				wp_safe_redirect( add_query_arg( 'osp_hub', 'refreshed', $back ) );
				exit;

			case 'osp_hub_disconnect':
				OSP_Hub::disconnect();
				OSP_Hub_Updater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'osp_hub', 'disconnected', $back ) );
				exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( OSP_Hub::CAP ) ) {
			return;
		}
		OSP_Hub::refresh_if_stale();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['osp_hub'] ) ? sanitize_key( wp_unslash( $_GET['osp_hub'] ) ) : '';
		$error  = isset( $_GET['osp_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['osp_err'] ) ) ) : '';
		// phpcs:enable

		$state    = OSP_Hub::state();
		$ent      = OSP_Hub::entitlement();
		$constant = OSP_Hub::key_from_constant();
		$reason   = OSP_Hub::status();
		$life_map = array(
			'valid'    => 'בתוקף',
			'expiring' => 'מתחדש בקרוב',
			'grace'    => 'ימי חסד',
			'expired'  => 'פג תוקף',
		);
		?>
		<div class="wrap">
			<h1>חשבון OC Pluginz</h1>
			<div style="max-width:760px;">
				<?php if ( 'connected' === $notice ) : ?>
					<div class="notice notice-success inline"><p>מחובר. הרישיון פעיל באתר הזה.</p></div>
				<?php elseif ( 'disconnected' === $notice ) : ?>
					<div class="notice notice-info inline"><p>נותק. המקום של האתר הזה ברישיון התפנה.</p></div>
				<?php elseif ( 'refreshed' === $notice ) : ?>
					<div class="notice notice-success inline"><p>מצב הרישיון עודכן.</p></div>
				<?php elseif ( 'updates' === $notice ) : ?>
					<div class="notice notice-success inline"><p>בדקנו עדכונים. אם יש גרסה חדשה, היא מופיעה עכשיו במסך התוספים.</p></div>
				<?php endif; ?>
				<?php if ( '' !== $error ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<p class="description" style="max-width:680px">
					הרישיון שולט בעדכונים ורושם את האתר הזה ב-OC Pluginz. הפופ אפים ממשיכים לעבוד גם בלעדיו.
				</p>

				<?php if ( OSP_Hub::agency() ) : ?>
					<div class="card" style="max-width:none;">
						<h2>מצב סוכנות</h2>
						<p>OC_PLUGINZ_AGENCY מוגדר ב-wp-config.php: האתר הזה לא משתמש ברישיון OC Pluginz, והעדכונים מגיעים מ-GitHub.</p>
					</div>
				<?php elseif ( '' === OSP_Hub::key() ) : ?>
					<div class="card" style="max-width:none;">
						<h2>חיבור ל-OC Pluginz</h2>
						<p><strong style="color:#646970">לא מחובר</strong></p>
						<p>הדביקו את מפתח הרישיון מחשבון OC Pluginz שלכם. הוא נשלח במייל עם הרכישה ומופיע גם בחשבון.</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'osp_hub_connect' ); ?>
							<input type="hidden" name="action" value="osp_hub_connect">
							<p>
								<label class="screen-reader-text" for="osp-license-key">מפתח רישיון</label>
								<input type="text" id="osp-license-key" name="license_key" class="regular-text code" dir="ltr" placeholder="ocpz_…" autocomplete="off" required style="direction:ltr;text-align:left;">
								<button class="button button-primary">חיבור</button>
							</p>
						</form>
						<?php self::account_links(); ?>
					</div>
				<?php else : ?>
					<div class="card" style="max-width:none;">
						<h2>רישיון OC Pluginz</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">מפתח רישיון</th>
								<td>
									<code dir="ltr"><?php echo esc_html( OSP_Hub::masked( OSP_Hub::key() ) ); ?></code>
									<?php if ( $constant ) : ?>
										<p class="description">מוגדר ב-OSP_LICENSE_KEY בקובץ wp-config.php.</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">מצב</th>
								<td>
									<?php
									if ( $ent ) {
										$life = OSP_Hub::lifecycle( $ent, time() );
										$ok   = OSP_Hub::updates_allowed();
										$txt  = isset( $life_map[ $life ] ) ? $life_map[ $life ] : $life;
										if ( 'suspended' === $reason ) {
											$txt = 'מושהה';
										}
										echo '<strong style="color:' . ( $ok ? '#15803d' : '#b32d2e' ) . '">' . esc_html( $txt ) . '</strong>';
										if ( ! empty( $ent['expires_at'] ) && false !== strtotime( (string) $ent['expires_at'] ) ) {
											echo ' <span class="description">· עד ' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $ent['expires_at'] ) ) ) . '</span>';
										} else {
											echo ' <span class="description">· ללא תאריך סיום</span>';
										}
									} elseif ( 'refused' === $reason ) {
										echo '<strong style="color:#b32d2e">פג תוקף</strong>';
									} else {
										echo '<strong style="color:#b32d2e">עוד לא אושר</strong>';
									}
									if ( ! empty( $state['error'] ) ) {
										echo '<p class="description" style="color:#b32d2e">' . esc_html( (string) $state['error'] ) . '</p>';
									}
									?>
								</td>
							</tr>
							<?php if ( $ent ) : ?>
								<?php if ( ! empty( $ent['plan'] ) ) : ?>
									<tr>
										<th scope="row">מסלול</th>
										<td><code><?php echo esc_html( (string) $ent['plan'] ); ?></code></td>
									</tr>
								<?php endif; ?>
								<tr>
									<th scope="row">אתרים</th>
									<td>
										<?php
										$sites = ( isset( $ent['sites'] ) && is_array( $ent['sites'] ) ) ? $ent['sites'] : array();
										$seats = isset( $ent['seats'] ) ? (int) $ent['seats'] : 1;
										if ( ! $sites ) {
											echo '<span class="description">—</span>';
										}
										foreach ( $sites as $site ) {
											$d  = isset( $site['domain'] ) ? (string) $site['domain'] : '';
											$me = ( $d === OSP_Hub::domain() );
											echo '<div><code dir="ltr">' . esc_html( $d ) . '</code>' . ( $me ? ' <span class="description">(האתר הזה)</span>' : '' ) . '</div>';
										}
										echo '<p class="description">' . esc_html( sprintf( '%1$d מתוך %2$d אתרים בשימוש.', count( $sites ), max( 1, $seats ) ) ) . '</p>';
										?>
									</td>
								</tr>
							<?php endif; ?>
							<tr>
								<th scope="row">עדכונים</th>
								<td>
									<?php
									$latest = isset( $state['latest'] ) ? (string) $state['latest'] : '';
									echo esc_html( 'מותקנת: ' . OSP_VERSION );
									if ( '' !== $latest ) {
										echo ' · ' . esc_html( 'האחרונה ב-OC Pluginz: ' . $latest );
										if ( version_compare( $latest, OSP_VERSION, '>' ) ) {
											echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">לעדכון ממסך התוספים</a>';
										}
									}
									?>
									<p class="description">גרסאות חדשות מוצעות במסך התוספים כל עוד הרישיון בתוקף.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">בדיקה אחרונה</th>
								<td>
									<?php
									$at = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
									echo esc_html( $at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '—' );
									?>
									<p class="description">הרישיון נבדק שוב כל שעה, וגם בכל פעם שהמסך הזה נפתח.</p>
								</td>
							</tr>
						</table>
						<div style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'osp_hub_refresh' ); ?>
								<input type="hidden" name="action" value="osp_hub_refresh">
								<button class="button">רענון</button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( OSP_Hub_Updater::ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( OSP_Hub_Updater::ACTION ); ?>">
								<button class="button">בדיקת עדכונים</button>
							</form>
							<?php if ( ! $constant ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( 'לנתק את האתר הזה מהרישיון? העדכונים מ-OC Pluginz ייפסקו עד שיחובר מפתח שוב. הפופ אפים ממשיכים לעבוד.' ); ?>');">
									<?php wp_nonce_field( 'osp_hub_disconnect' ); ?>
									<input type="hidden" name="action" value="osp_hub_disconnect">
									<button class="button button-link-delete">ניתוק האתר הזה</button>
								</form>
							<?php endif; ?>
						</div>
						<?php self::account_links(); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** The two links under the licence card: the account at the hub, and where a new licence is bought. */
	private static function account_links() {
		?>
		<p class="description">
			<a href="<?php echo esc_url( OSP_Hub::ACCOUNT_URL ); ?>" target="_blank" rel="noopener">לחשבון OC Pluginz</a>
			&nbsp;·&nbsp;
			רישיון חדש?
			<a href="<?php echo esc_url( OSP_Hub::PRICING_URL ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( OSP_Hub::PRICING_URL ); ?></a>
		</p>
		<?php
	}
}
