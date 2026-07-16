<?php
/**
 * Plugin Name: פופ אפ פשוט (Simple Popup)
 * Description: תוסף פופ אפ פשוט — תמונה או מוצרים, בחירת עמודים בחיפוש, קוקי לשליטה בתדירות.
 * Version: 1.5.0
 * Author: Original Concepts
 * Text Domain: osp-simple-popup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OSP_Simple_Popup {

	const CPT   = 'osp_popup';
	const NONCE = 'osp_popup_save';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'force_status' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_ajax_osp_search_pages', array( $this, 'ajax_search_pages' ) );
		add_action( 'wp_ajax_osp_products_preview', array( $this, 'ajax_products_preview' ) );
		add_action( 'wp_ajax_osp_toggle_active', array( $this, 'ajax_toggle_active' ) );
		add_action( 'wp_ajax_osp_track', array( $this, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_osp_track', array( $this, 'ajax_track' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'render_popups' ) );
	}

	/* ---------------- Admin: CPT ---------------- */

	public function register_cpt() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'               => 'פופ אפים',
				'singular_name'      => 'פופ אפ',
				'add_new'            => 'הוסף פופ אפ',
				'add_new_item'       => 'הוספת פופ אפ',
				'edit_item'          => 'עריכת פופ אפ',
				'new_item'           => 'פופ אפ חדש',
				'search_items'       => 'חיפוש פופ אפים',
				'not_found'          => 'לא נמצאו פופ אפים',
				'not_found_in_trash' => 'לא נמצאו פופ אפים בפח',
				'menu_name'          => 'פופ אפים',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-format-image',
			'menu_position'   => 58,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
		) );
	}

	/* ---------------- Meta ---------------- */

	private function get_meta( $post_id ) {
		$defaults = array(
			'title'           => '',
			'content'         => '',
			'content_type'    => 'image',
			'image_id'        => 0,
			'image_link_on'   => '0',
			'image_link'      => '',
			'image_full'      => '0',
			'products'        => array(),
			'cta_text'        => '',
			'cta_link'        => '',
			'display'         => 'all',
			'pages'           => array(),
			'exclude'         => array(),
			'device'          => 'both',
			'cookie_days'     => 1,
			'size'            => 'auto',
			'max_width'       => 600,
			'bg_color'        => '#ffffff',
			'text_color'      => '',
			'title_size'      => 30,
			'content_size'    => 17,
			'close_pos'       => 'left',
			'close_style'     => 'light',
			'cta_bg'          => '#111111',
			'cta_color'       => '#ffffff',
			'cta_hover_bg'    => '#333333',
			'cta_hover_color' => '#ffffff',
			'cta_radius'      => 'soft',
			'cta_size'        => 16,
			'disc_text'       => '',
			'disc_size'       => 12,
			'disc_color'      => '#666666',
		);
		$meta = array();
		foreach ( $defaults as $key => $default ) {
			$val = get_post_meta( $post_id, '_osp_' . $key, true );
			$meta[ $key ] = ( '' === $val || null === $val ) ? $default : $val;
		}
		$meta['products'] = is_array( $meta['products'] ) ? $meta['products'] : array();
		$meta['pages']    = is_array( $meta['pages'] ) ? $meta['pages'] : array();
		$meta['exclude']  = is_array( $meta['exclude'] ) ? $meta['exclude'] : array();
		return $meta;
	}

	private static function hex_or( $value, $fallback ) {
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $value ) ? $value : $fallback;
	}

	private static function radius_px( $radius ) {
		if ( 'square' === $radius ) {
			return '0';
		}
		if ( 'round' === $radius ) {
			return '50px';
		}
		return '6px';
	}

	/* ---------------- Admin: metabox ---------------- */

	public function add_metabox() {
		add_meta_box( 'osp_settings', 'הגדרות פופ אפ', array( $this, 'metabox_html' ), self::CPT, 'normal', 'high' );
	}

	public function metabox_html( $post ) {
		$m = $this->get_meta( $post->ID );
		wp_nonce_field( self::NONCE, self::NONCE );
		$img_src      = $m['image_id'] ? wp_get_attachment_image_url( (int) $m['image_id'], 'large' ) : '';
		$pv_width     = 'custom' === $m['size'] ? (int) $m['max_width'] : 640;
		$bg_color     = self::hex_or( $m['bg_color'], '#ffffff' );
		$text_color   = self::hex_or( $m['text_color'], '' );
		$cta_bg       = self::hex_or( $m['cta_bg'], '#111111' );
		$cta_color    = self::hex_or( $m['cta_color'], '#ffffff' );
		$cta_hbg      = self::hex_or( $m['cta_hover_bg'], '#333333' );
		$cta_hcolor   = self::hex_or( $m['cta_hover_color'], '#ffffff' );
		$cta_radius   = self::radius_px( $m['cta_radius'] );
		$cta_size     = max( 10, min( 40, (int) $m['cta_size'] ) );
		$title_size   = max( 10, min( 80, (int) $m['title_size'] ) );
		$content_size = max( 10, min( 60, (int) $m['content_size'] ) );
		$disc_size    = max( 8, min( 30, (int) $m['disc_size'] ) );
		$disc_color   = self::hex_or( $m['disc_color'], '#666666' );
		$pv_full      = ( 'image' === $m['content_type'] && '1' === $m['image_full'] ) ? ' pv-full' : '';
		$post_status  = get_post_status( $post );
		$is_active    = in_array( $post_status, array( 'publish', 'auto-draft' ), true );
		$color_style  = $text_color ? 'color:' . esc_attr( $text_color ) . ';' : '';
		$pv_box_style = 'max-width:' . (int) $pv_width . 'px;background-color:' . esc_attr( $bg_color ) . ';' . $color_style
			. '--obg:' . esc_attr( $cta_bg ) . ';--oc:' . esc_attr( $cta_color ) . ';--ohbg:' . esc_attr( $cta_hbg ) . ';--ohc:' . esc_attr( $cta_hcolor ) . ';--obr:' . esc_attr( $cta_radius ) . ';--obfs:' . (int) $cta_size . 'px;';
		?>
		<style>
			.osp-admin-wrap{display:flex;gap:24px;align-items:flex-start}
			.osp-settings-col{flex:0 0 46%;max-width:46%;min-width:340px}
			.osp-preview-col{flex:1;position:sticky;top:60px;min-width:0}
			@media (max-width:1100px){.osp-admin-wrap{flex-direction:column}.osp-settings-col{max-width:none;flex:1;width:100%}.osp-preview-col{position:static;width:100%}}
			.osp-field{margin:0 0 18px}
			.osp-field > label.osp-label, .osp-field p > label.osp-label{display:block;font-weight:600;margin-bottom:6px}
			.osp-field .description{margin-top:4px}
			.osp-hidden{display:none}
			.osp-row{display:flex;gap:16px;flex-wrap:wrap}
			.osp-row .osp-field{flex:1;min-width:150px}
			#osp-image-preview img{max-width:220px;height:auto;display:block;margin-bottom:8px;border:1px solid #ddd;border-radius:4px}
			.osp-field select.osp-select2{width:100%;max-width:480px}
			.osp-field input[type=text],.osp-field input[type=url]{max-width:480px;width:100%}
			.osp-field select.osp-dd{width:100%;max-width:240px}
			.osp-eyedrop{margin-inline-start:4px;vertical-align:top}
			.osp-eyedrop .dashicons{vertical-align:text-bottom}

			/* ---- Switch ---- */
			.osp-switch{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle}
			.osp-switch input{opacity:0;width:0;height:0;position:absolute}
			.osp-slider{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:22px;transition:.15s}
			.osp-slider:before{content:"";position:absolute;height:16px;width:16px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.15s}
			.osp-switch input:checked + .osp-slider{background:#00a32a}
			.osp-switch input:checked + .osp-slider:before{transform:translateX(-18px)}

			/* ---- Tabs ---- */
			.osp-tabs{display:flex;gap:4px;margin:0 0 18px;border-bottom:1px solid #dcdcde}
			.osp-tab{border:1px solid #dcdcde;border-bottom:none;background:#f6f7f7;padding:8px 22px;cursor:pointer;border-radius:6px 6px 0 0;font-weight:600;font-size:13px}
			.osp-tab.active{background:#fff;position:relative;top:1px}

			/* ---- Live preview ---- */
			.osp-preview-head{font-weight:600;margin-bottom:8px;font-size:13px}
			.osp-preview-stage{background:#4a4c52;border-radius:8px;padding:34px 18px;display:flex;align-items:center;justify-content:center;min-height:320px}
			.osp-pv-box{position:relative;background:#fff;border-radius:10px;padding:34px 24px 24px;text-align:center;direction:rtl;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.25);transition:max-width .2s}
			.osp-pv-close{position:absolute;top:10px;width:30px;height:30px;border:0;border-radius:50%;font-size:17px;line-height:30px;padding:0;text-align:center;cursor:default}
			.osp-pv-close.pos-left{left:10px;right:auto}
			.osp-pv-close.pos-right{right:10px;left:auto}
			.osp-pv-close.style-light{background:#f3f3f3;color:#111}
			.osp-pv-close.style-dark{background:#111;color:#fff}
			.osp-pv-title{margin:0 0 10px;font-weight:700;line-height:1.25;color:inherit}
			.osp-pv-content{margin-bottom:14px;color:inherit}
			.osp-pv-content p{font-size:inherit;color:inherit;margin:0 0 .6em}
			.osp-pv-content p:last-child{margin-bottom:0}
			.osp-pv-image img{max-width:100%;height:auto;display:block;margin:0 auto;border-radius:6px}
			.osp-pv-image-empty{border:2px dashed #c3c4c7;border-radius:6px;padding:38px 10px;color:#787c82;font-size:12px}
			.osp-pv-products{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:14px;margin-top:6px}
			.osp-pv-product{display:block;color:inherit}
			.osp-pv-product img{max-width:100%;height:auto;border-radius:6px}
			.osp-pv-pname{display:block;margin-top:6px;font-weight:600;font-size:.9em}
			.osp-pv-pprice{display:block;margin-top:2px;font-size:.85em}
			.osp-pv-btn{display:inline-block;margin-top:16px;padding:11px 28px;border-radius:var(--obr,6px);font-size:var(--obfs,16px);text-decoration:none;font-weight:600;background:var(--obg,#111);color:var(--oc,#fff);cursor:default;transition:background .15s,color .15s,border-radius .15s}
			.osp-pv-btn:hover{background:var(--ohbg,#333);color:var(--ohc,#fff)}
			.osp-pv-disc{margin-top:14px;line-height:1.5}
			.osp-pv-disc p{font-size:inherit;color:inherit;margin:0 0 .5em}
			.osp-pv-disc p:last-child{margin-bottom:0}
			.osp-pv-box.pv-full{padding:0;overflow:hidden}
			.osp-pv-box.pv-full .osp-pv-title{padding:18px 24px 0}
			.osp-pv-box.pv-full .osp-pv-content{padding:0 24px}
			.osp-pv-box.pv-full .osp-pv-image img{border-radius:0;width:100%}
			.osp-pv-box.pv-full .osp-pv-image-empty{border-radius:0;border-width:0;padding:60px 10px}
			.osp-pv-box.pv-full .osp-pv-cta{padding:0 24px 20px}
			.osp-pv-box.pv-full .osp-pv-disc{padding:0 24px 18px;margin-top:10px}
		</style>

		<div class="osp-admin-wrap">
		<div class="osp-settings-col">

		<div class="osp-tabs">
			<button type="button" class="osp-tab active" data-tab="settings">הגדרות</button>
			<button type="button" class="osp-tab" data-tab="design">עיצוב</button>
		</div>

		<!-- ================= טאב הגדרות ================= -->
		<div class="osp-tab-panel" data-panel="settings">

		<div class="osp-field">
			<label class="osp-label">סטטוס</label>
			<label class="osp-switch"><input type="checkbox" name="osp[active]" value="1" <?php checked( $is_active ); ?>><span class="osp-slider"></span></label>
			<span style="margin-inline-start:8px;vertical-align:middle">פעיל</span>
			<p class="description">כבוי = הפופ אפ נשמר אך לא מוצג באתר. נשמר בלחיצה על עדכון/פרסום. אפשר לשלוט גם מהמתג בטבלת הפופ אפים.</p>
		</div>

		<div class="osp-field">
			<label class="osp-label">כותרת (לא חובה)</label>
			<input type="text" name="osp[title]" id="osp-in-title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="כותרת שתוצג בראש הפופ אפ">
		</div>

		<div class="osp-field">
			<label class="osp-label">תיאור / אזור תוכן (לא חובה)</label>
			<?php
			wp_editor( $m['content'], 'osp_content_editor', array(
				'textarea_name' => 'osp[content]',
				'textarea_rows' => 4,
				'media_buttons' => false,
				'teeny'         => true,
			) );
			?>
		</div>

		<div class="osp-field">
			<label class="osp-label">תוכן</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[content_type]" value="image" <?php checked( $m['content_type'], 'image' ); ?>> תמונה</label>
			<label><input type="radio" name="osp[content_type]" value="products" <?php checked( $m['content_type'], 'products' ); ?>> מוצרים</label>
		</div>

		<div class="osp-field osp-type-image <?php echo 'image' === $m['content_type'] ? '' : 'osp-hidden'; ?>">
			<label class="osp-label">תמונה</label>
			<div id="osp-image-preview"><?php if ( $img_src ) : ?><img src="<?php echo esc_url( $img_src ); ?>" alt=""><?php endif; ?></div>
			<input type="hidden" name="osp[image_id]" id="osp-image-id" value="<?php echo (int) $m['image_id']; ?>">
			<button type="button" class="button" id="osp-image-upload">העלאת / בחירת תמונה</button>
			<button type="button" class="button <?php echo $m['image_id'] ? '' : 'osp-hidden'; ?>" id="osp-image-remove">הסרת תמונה</button>
			<p style="margin-top:10px">
				<label><input type="checkbox" name="osp[image_full]" id="osp-in-imagefull" value="1" <?php checked( $m['image_full'], '1' ); ?>> תמונה על כל הפופ אפ (ללא שוליים)</label>
			</p>
			<p style="margin-top:8px">
				<label><input type="checkbox" name="osp[image_link_on]" id="osp-in-imagelinkon" value="1" <?php checked( $m['image_link_on'], '1' ); ?>> הוסף קישור לתמונה</label>
			</p>
			<p class="osp-image-link-field <?php echo '1' === $m['image_link_on'] ? '' : 'osp-hidden'; ?>" style="margin-top:8px">
				<input type="url" name="osp[image_link]" value="<?php echo esc_url( $m['image_link'] ); ?>" placeholder="https://" dir="ltr">
			</p>
		</div>

		<div class="osp-field osp-type-products <?php echo 'products' === $m['content_type'] ? '' : 'osp-hidden'; ?>">
			<label class="osp-label">מוצרים</label>
			<?php if ( function_exists( 'wc_get_product' ) ) : ?>
				<select class="wc-product-search osp-select2" id="osp-in-products" multiple name="osp[products][]" data-placeholder="חיפוש מוצר…" data-action="woocommerce_json_search_products_and_variations">
					<?php
					foreach ( $m['products'] as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( $product ) {
							echo '<option value="' . esc_attr( $product_id ) . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select>
			<?php else : ?>
				<p class="description">ווקומרס אינו פעיל — לא ניתן לבחור מוצרים.</p>
			<?php endif; ?>
		</div>

		<div class="osp-field">
			<label class="osp-label">כפתור הנעה לפעולה (לא חובה)</label>
			<div class="osp-row">
				<div class="osp-field" style="margin-bottom:0">
					<input type="text" name="osp[cta_text]" id="osp-in-ctatext" value="<?php echo esc_attr( $m['cta_text'] ); ?>" placeholder="טקסט הכפתור, למשל: לכל המבצעים">
				</div>
				<div class="osp-field" style="margin-bottom:0">
					<input type="url" name="osp[cta_link]" value="<?php echo esc_url( $m['cta_link'] ); ?>" placeholder="https:// קישור" dir="ltr">
				</div>
			</div>
			<p class="description">הכפתור יופיע אחרי התוכן. השאר את הטקסט ריק כדי שלא יוצג. ללא קישור — לחיצה על הכפתור תסגור את הפופ אפ. עיצוב הכפתור — בטאב עיצוב.</p>
		</div>

		<div class="osp-field">
			<label class="osp-label">דיסקליימר (לא חובה)</label>
			<textarea name="osp[disc_text]" id="osp-in-disctext" rows="2" style="width:100%;max-width:480px" placeholder="טקסט קטן שיופיע בתחתית הפופ אפ, למשל: בכפוף לתקנון"><?php echo esc_textarea( $m['disc_text'] ); ?></textarea>
			<p class="description">גודל וצבע הפונט — בטאב עיצוב.</p>
		</div>

		<div class="osp-field">
			<label class="osp-label">באילו עמודים להציג</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[display]" value="all" <?php checked( $m['display'], 'all' ); ?>> כל העמודים</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[display]" value="home" <?php checked( $m['display'], 'home' ); ?>> רק עמוד הבית</label>
			<label><input type="radio" name="osp[display]" value="specific" <?php checked( $m['display'], 'specific' ); ?>> עמודים ספציפיים</label>

			<div class="osp-display-specific <?php echo 'specific' === $m['display'] ? '' : 'osp-hidden'; ?>" style="margin-top:8px">
				<select class="osp-page-search osp-select2" multiple name="osp[pages][]" data-placeholder="חיפוש עמוד…">
					<?php foreach ( $m['pages'] as $pid ) : if ( get_post( $pid ) ) : ?>
						<option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html( get_the_title( $pid ) ); ?></option>
					<?php endif; endforeach; ?>
				</select>
			</div>

			<div class="osp-display-all <?php echo 'all' === $m['display'] ? '' : 'osp-hidden'; ?>" style="margin-top:8px">
				<label class="osp-label">החרגת עמודים (לא יוצג בעמודים אלה)</label>
				<select class="osp-page-search osp-select2" multiple name="osp[exclude][]" data-placeholder="חיפוש עמוד להחרגה…">
					<?php foreach ( $m['exclude'] as $pid ) : if ( get_post( $pid ) ) : ?>
						<option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html( get_the_title( $pid ) ); ?></option>
					<?php endif; endforeach; ?>
				</select>
			</div>
		</div>

		<div class="osp-field">
			<label class="osp-label">באיזה מכשיר להציג</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[device]" value="both" <?php checked( $m['device'], 'both' ); ?>> דסקטופ + מובייל</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[device]" value="desktop" <?php checked( $m['device'], 'desktop' ); ?>> דסקטופ בלבד</label>
			<label><input type="radio" name="osp[device]" value="mobile" <?php checked( $m['device'], 'mobile' ); ?>> מובייל בלבד</label>
		</div>

		<div class="osp-field">
			<label class="osp-label">קוקי — הצגה חוזרת</label>
			<input type="number" name="osp[cookie_days]" value="<?php echo (int) $m['cookie_days']; ?>" min="0" max="365" style="width:80px"> ימים
			<p class="description">לאחר סגירת הפופ אפ הוא לא יוצג שוב לאותו גולש למשך מספר הימים שנקבע (ברירת מחדל: יום אחד. 0 = יוצג בכל טעינה).</p>
		</div>

		</div><!-- /panel settings -->

		<!-- ================= טאב עיצוב ================= -->
		<div class="osp-tab-panel osp-hidden" data-panel="design">

		<div class="osp-field">
			<label class="osp-label">גודל פופ אפ</label>
			<label style="margin-inline-end:16px"><input type="radio" name="osp[size]" value="auto" <?php checked( $m['size'], 'auto' ); ?>> אוטומטי</label>
			<label><input type="radio" name="osp[size]" value="custom" <?php checked( $m['size'], 'custom' ); ?>> הגבלת רוחב</label>
			<p class="osp-size-custom <?php echo 'custom' === $m['size'] ? '' : 'osp-hidden'; ?>" style="margin-top:8px">
				<input type="number" name="osp[max_width]" id="osp-in-maxwidth" value="<?php echo (int) $m['max_width']; ?>" min="200" max="2000" step="10" style="width:100px"> פיקסלים (רוחב מקסימלי)
			</p>
		</div>

		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label">צבע רקע הפופ אפ</label>
				<input type="text" name="osp[bg_color]" id="osp-in-bgcolor" value="<?php echo esc_attr( $bg_color ); ?>" class="osp-color" data-default-color="#ffffff">
			</div>
			<div class="osp-field">
				<label class="osp-label">צבע טקסט (כותרת ותיאור)</label>
				<input type="text" name="osp[text_color]" id="osp-in-textcolor" value="<?php echo esc_attr( $text_color ); ?>" class="osp-color">
				<p class="description">ריק = צבע התבנית</p>
			</div>
		</div>

		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label">גודל כותרת (px)</label>
				<input type="number" name="osp[title_size]" id="osp-in-titlesize" value="<?php echo (int) $title_size; ?>" min="10" max="80" style="width:90px">
			</div>
			<div class="osp-field">
				<label class="osp-label">גודל תיאור (px)</label>
				<input type="number" name="osp[content_size]" id="osp-in-contentsize" value="<?php echo (int) $content_size; ?>" min="10" max="60" style="width:90px">
			</div>
		</div>

		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label">מיקום כפתור סגירה</label>
				<select name="osp[close_pos]" id="osp-in-closepos" class="osp-dd">
					<option value="left" <?php selected( $m['close_pos'], 'left' ); ?>>פינה שמאלית עליונה</option>
					<option value="right" <?php selected( $m['close_pos'], 'right' ); ?>>פינה ימנית עליונה</option>
				</select>
			</div>
			<div class="osp-field">
				<label class="osp-label">צבע כפתור סגירה</label>
				<select name="osp[close_style]" id="osp-in-closestyle" class="osp-dd">
					<option value="light" <?php selected( $m['close_style'], 'light' ); ?>>בהיר — רקע אפרפר, איקס שחור</option>
					<option value="dark" <?php selected( $m['close_style'], 'dark' ); ?>>כהה — רקע שחור, איקס לבן</option>
				</select>
			</div>
		</div>

		<div class="osp-field" style="margin-bottom:8px"><label class="osp-label">כפתור הנעה לפעולה</label></div>
		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">צבע רקע</label>
				<input type="text" name="osp[cta_bg]" id="osp-in-ctabg" value="<?php echo esc_attr( $cta_bg ); ?>" class="osp-color" data-default-color="#111111">
			</div>
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">צבע טקסט</label>
				<input type="text" name="osp[cta_color]" id="osp-in-ctacolor" value="<?php echo esc_attr( $cta_color ); ?>" class="osp-color" data-default-color="#ffffff">
			</div>
		</div>
		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">רול אובר — צבע רקע</label>
				<input type="text" name="osp[cta_hover_bg]" id="osp-in-ctahbg" value="<?php echo esc_attr( $cta_hbg ); ?>" class="osp-color" data-default-color="#333333">
			</div>
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">רול אובר — צבע טקסט</label>
				<input type="text" name="osp[cta_hover_color]" id="osp-in-ctahcolor" value="<?php echo esc_attr( $cta_hcolor ); ?>" class="osp-color" data-default-color="#ffffff">
			</div>
		</div>
		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">פינות הכפתור</label>
				<select name="osp[cta_radius]" id="osp-in-ctaradius" class="osp-dd">
					<option value="square" <?php selected( $m['cta_radius'], 'square' ); ?>>פינות ישרות</option>
					<option value="soft" <?php selected( $m['cta_radius'], 'soft' ); ?>>מעוגלות במעט</option>
					<option value="round" <?php selected( $m['cta_radius'], 'round' ); ?>>פינות מעוגלות</option>
				</select>
			</div>
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">גודל טקסט (px)</label>
				<input type="number" name="osp[cta_size]" id="osp-in-ctasize" value="<?php echo (int) $cta_size; ?>" min="10" max="40" style="width:90px">
			</div>
		</div>
		<p class="description" style="margin-top:0">עבור עם העכבר על הכפתור בתצוגה המקדימה כדי לראות את צבעי הרול אובר. כפתור הפיפטה (דגימת צבע מהמסך) זמין בדפדפני כרום/אדג'.</p>

		<div class="osp-field" style="margin:14px 0 8px"><label class="osp-label">דיסקליימר</label></div>
		<div class="osp-row">
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">גודל פונט (px)</label>
				<input type="number" name="osp[disc_size]" id="osp-in-discsize" value="<?php echo (int) $disc_size; ?>" min="8" max="30" style="width:90px">
			</div>
			<div class="osp-field">
				<label class="osp-label" style="font-weight:400">צבע פונט</label>
				<input type="text" name="osp[disc_color]" id="osp-in-disccolor" value="<?php echo esc_attr( $disc_color ); ?>" class="osp-color" data-default-color="#666666">
			</div>
		</div>

		</div><!-- /panel design -->

		</div><!-- /.osp-settings-col -->

		<div class="osp-preview-col">
			<div class="osp-preview-head">תצוגה מקדימה</div>
			<div class="osp-preview-stage">
				<div class="osp-pv-box<?php echo esc_attr( $pv_full ); ?>" id="osp-pv-box" style="<?php echo $pv_box_style; ?>">
					<button type="button" class="osp-pv-close pos-<?php echo esc_attr( $m['close_pos'] ); ?> style-<?php echo esc_attr( $m['close_style'] ); ?>" id="osp-pv-close" tabindex="-1">&times;</button>
					<h3 class="osp-pv-title" id="osp-pv-title" style="font-size:<?php echo (int) $title_size; ?>px;<?php echo $color_style; ?><?php if ( ! $m['title'] ) echo 'display:none;'; ?>"><?php echo esc_html( $m['title'] ); ?></h3>
					<div class="osp-pv-content" id="osp-pv-content" style="font-size:<?php echo (int) $content_size; ?>px;<?php echo $color_style; ?><?php if ( ! trim( $m['content'] ) ) echo 'display:none;'; ?>"><?php echo wp_kses_post( wpautop( $m['content'] ) ); ?></div>
					<div class="osp-pv-image" id="osp-pv-image" <?php if ( 'image' !== $m['content_type'] ) echo 'style="display:none"'; ?>>
						<?php if ( $img_src ) : ?>
							<img src="<?php echo esc_url( $img_src ); ?>" alt="">
						<?php else : ?>
							<div class="osp-pv-image-empty">כאן תוצג התמונה שתעלה</div>
						<?php endif; ?>
					</div>
					<div class="osp-pv-products" id="osp-pv-products" <?php if ( 'products' !== $m['content_type'] ) echo 'style="display:none"'; ?>>
						<?php
						if ( function_exists( 'wc_get_product' ) ) {
							foreach ( $m['products'] as $product_id ) {
								$product = wc_get_product( $product_id );
								if ( ! $product ) {
									continue;
								}
								echo '<span class="osp-pv-product">'
									. '<span class="osp-pv-pimg">' . $product->get_image( 'woocommerce_thumbnail' ) . '</span>'
									. '<span class="osp-pv-pname">' . esc_html( $product->get_name() ) . '</span>'
									. '<span class="osp-pv-pprice">' . wp_kses_post( $product->get_price_html() ) . '</span>'
									. '</span>';
							}
						}
						?>
					</div>
					<div class="osp-pv-cta" id="osp-pv-cta" <?php if ( ! $m['cta_text'] ) echo 'style="display:none"'; ?>>
						<span class="osp-pv-btn" id="osp-pv-btn"><?php echo esc_html( $m['cta_text'] ); ?></span>
					</div>
					<div class="osp-pv-disc" id="osp-pv-disc" style="font-size:<?php echo (int) $disc_size; ?>px;color:<?php echo esc_attr( $disc_color ); ?>;<?php if ( ! trim( $m['disc_text'] ) ) echo 'display:none;'; ?>"><?php echo wp_kses_post( wpautop( $m['disc_text'] ) ); ?></div>
				</div>
			</div>
			<p class="description">תצוגה להתרשמות בלבד — באתר עצמו הפונטים של התבנית עשויים להשפיע מעט על המראה.</p>
		</div><!-- /.osp-preview-col -->

		</div><!-- /.osp-admin-wrap -->
		<?php
	}

	public function force_status( $data, $postarr ) {
		if ( self::CPT !== $data['post_type'] ) {
			return $data;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
			return $data;
		}
		if ( in_array( $data['post_status'], array( 'trash', 'auto-draft' ), true ) ) {
			return $data;
		}
		$active              = isset( $_POST['osp']['active'] ) && '1' === $_POST['osp']['active'];
		$data['post_status'] = $active ? 'publish' : 'draft';
		return $data;
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['osp'] ) && is_array( $_POST['osp'] ) ? wp_unslash( $_POST['osp'] ) : array();

		$hex = function ( $key, $fallback, $allow_empty = false ) use ( $in ) {
			$val = isset( $in[ $key ] ) ? sanitize_hex_color( $in[ $key ] ) : '';
			if ( $val ) {
				return $val;
			}
			return $allow_empty ? '' : $fallback;
		};

		$display = 'all';
		if ( isset( $in['display'] ) && in_array( $in['display'], array( 'all', 'home', 'specific' ), true ) ) {
			$display = $in['display'];
		}
		$device = 'both';
		if ( isset( $in['device'] ) && in_array( $in['device'], array( 'both', 'desktop', 'mobile' ), true ) ) {
			$device = $in['device'];
		}
		$cta_radius = 'soft';
		if ( isset( $in['cta_radius'] ) && in_array( $in['cta_radius'], array( 'square', 'soft', 'round' ), true ) ) {
			$cta_radius = $in['cta_radius'];
		}

		$fields = array(
			'title'           => isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : '',
			'content'         => isset( $in['content'] ) ? wp_kses_post( $in['content'] ) : '',
			'content_type'    => ( isset( $in['content_type'] ) && 'products' === $in['content_type'] ) ? 'products' : 'image',
			'image_id'        => isset( $in['image_id'] ) ? absint( $in['image_id'] ) : 0,
			'image_link_on'   => ( isset( $in['image_link_on'] ) && '1' === $in['image_link_on'] ) ? '1' : '0',
			'image_link'      => isset( $in['image_link'] ) ? esc_url_raw( $in['image_link'] ) : '',
			'image_full'      => ( isset( $in['image_full'] ) && '1' === $in['image_full'] ) ? '1' : '0',
			'products'        => isset( $in['products'] ) && is_array( $in['products'] ) ? array_values( array_filter( array_map( 'absint', $in['products'] ) ) ) : array(),
			'cta_text'        => isset( $in['cta_text'] ) ? sanitize_text_field( $in['cta_text'] ) : '',
			'cta_link'        => isset( $in['cta_link'] ) ? esc_url_raw( $in['cta_link'] ) : '',
			'display'         => $display,
			'pages'           => isset( $in['pages'] ) && is_array( $in['pages'] ) ? array_values( array_filter( array_map( 'absint', $in['pages'] ) ) ) : array(),
			'exclude'         => isset( $in['exclude'] ) && is_array( $in['exclude'] ) ? array_values( array_filter( array_map( 'absint', $in['exclude'] ) ) ) : array(),
			'device'          => $device,
			'cookie_days'     => isset( $in['cookie_days'] ) ? min( 365, absint( $in['cookie_days'] ) ) : 1,
			'size'            => ( isset( $in['size'] ) && 'custom' === $in['size'] ) ? 'custom' : 'auto',
			'max_width'       => isset( $in['max_width'] ) ? max( 200, min( 2000, absint( $in['max_width'] ) ) ) : 600,
			'bg_color'        => $hex( 'bg_color', '#ffffff' ),
			'text_color'      => $hex( 'text_color', '', true ),
			'title_size'      => isset( $in['title_size'] ) ? max( 10, min( 80, absint( $in['title_size'] ) ) ) : 30,
			'content_size'    => isset( $in['content_size'] ) ? max( 10, min( 60, absint( $in['content_size'] ) ) ) : 17,
			'close_pos'       => ( isset( $in['close_pos'] ) && 'right' === $in['close_pos'] ) ? 'right' : 'left',
			'close_style'     => ( isset( $in['close_style'] ) && 'dark' === $in['close_style'] ) ? 'dark' : 'light',
			'cta_bg'          => $hex( 'cta_bg', '#111111' ),
			'cta_color'       => $hex( 'cta_color', '#ffffff' ),
			'cta_hover_bg'    => $hex( 'cta_hover_bg', '#333333' ),
			'cta_hover_color' => $hex( 'cta_hover_color', '#ffffff' ),
			'cta_radius'      => $cta_radius,
			'cta_size'        => isset( $in['cta_size'] ) ? max( 10, min( 40, absint( $in['cta_size'] ) ) ) : 16,
			'disc_text'       => isset( $in['disc_text'] ) ? wp_kses_post( $in['disc_text'] ) : '',
			'disc_size'       => isset( $in['disc_size'] ) ? max( 8, min( 30, absint( $in['disc_size'] ) ) ) : 12,
			'disc_color'      => $hex( 'disc_color', '#666666' ),
		);
		foreach ( $fields as $key => $value ) {
			update_post_meta( $post_id, '_osp_' . $key, $value );
		}
	}

	/* ---------------- Admin: list table ---------------- */

	public function admin_columns( $cols ) {
		$new = array();
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['osp_active'] = 'פעיל';
				$new['osp_device'] = 'מכשיר';
				$new['osp_stats']  = 'סטטיסטיקה';
			}
		}
		return $new;
	}

	public function admin_column_content( $col, $post_id ) {
		if ( 'osp_active' === $col ) {
			echo '<label class="osp-switch"><input type="checkbox" class="osp-active-toggle" data-post="' . (int) $post_id . '" '
				. checked( 'publish', get_post_status( $post_id ), false )
				. '><span class="osp-slider"></span></label>';
			return;
		}
		if ( 'osp_device' === $col ) {
			$device = get_post_meta( $post_id, '_osp_device', true );
			$labels = array( 'desktop' => 'דסקטופ', 'mobile' => 'מובייל' );
			echo esc_html( isset( $labels[ $device ] ) ? $labels[ $device ] : 'דסקטופ + מובייל' );
			return;
		}
		if ( 'osp_stats' === $col ) {
			$views  = (int) get_post_meta( $post_id, '_osp_stat_views', true );
			$clicks = (int) get_post_meta( $post_id, '_osp_stat_clicks', true );
			$closes = (int) get_post_meta( $post_id, '_osp_stat_closes', true );
			echo esc_html( 'ראו: ' . $views . ' | הקליקו: ' . $clicks . ' | סגרו: ' . $closes );
			return;
		}
	}

	public function ajax_toggle_active() {
		check_ajax_referer( 'osp_admin' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || self::CPT !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}
		$active = ! empty( $_POST['active'] );
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => $active ? 'publish' : 'draft',
		) );
		wp_send_json_success( array( 'status' => $active ? 'publish' : 'draft' ) );
	}

	public function ajax_track() {
		$post_id = isset( $_POST['popup'] ) ? absint( $_POST['popup'] ) : 0;
		$event   = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$map     = array( 'view' => 'views', 'click' => 'clicks', 'close' => 'closes' );
		if ( ! $post_id || ! isset( $map[ $event ] ) || self::CPT !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			wp_die();
		}
		$key = '_osp_stat_' . $map[ $event ];
		update_post_meta( $post_id, $key, (int) get_post_meta( $post_id, $key, true ) + 1 );
		wp_die( '1' );
	}

	private function list_assets() {
		$nonce = wp_create_nonce( 'osp_admin' );
		$css   = '.column-osp_active{width:70px}.column-osp_device{width:130px}.column-osp_stats{width:220px}'
			. '.osp-switch{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle}'
			. '.osp-switch input{opacity:0;width:0;height:0;position:absolute}'
			. '.osp-slider{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:22px;transition:.15s}'
			. '.osp-slider:before{content:"";position:absolute;height:16px;width:16px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.15s}'
			. '.osp-switch input:checked + .osp-slider{background:#00a32a}'
			. '.osp-switch input:checked + .osp-slider:before{transform:translateX(-18px)}'
			. '.osp-switch input:disabled + .osp-slider{opacity:.5;cursor:wait}';
		wp_add_inline_style( 'common', $css );
		$js = <<<JS
jQuery(function($){
	$(document).on('change', '.osp-active-toggle', function(){
		var cb = $(this);
		cb.prop('disabled', true);
		$.post(ajaxurl, {
			action: 'osp_toggle_active',
			post: cb.data('post'),
			active: cb.is(':checked') ? 1 : 0,
			_wpnonce: '{$nonce}'
		}, function(res){
			cb.prop('disabled', false);
			if (!res || !res.success) {
				cb.prop('checked', !cb.is(':checked'));
				alert('שגיאה בעדכון סטטוס הפופ אפ');
			}
		}).fail(function(){
			cb.prop('disabled', false);
			cb.prop('checked', !cb.is(':checked'));
			alert('שגיאה בעדכון סטטוס הפופ אפ');
		});
	});
});
JS;
		wp_add_inline_script( 'jquery-core', $js, 'after' );
	}

	/* ---------------- Admin: assets ---------------- */

	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		if ( 'edit.php' === $hook ) {
			$this->list_assets();
			return;
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		if ( function_exists( 'WC' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		$nonce = wp_create_nonce( 'osp_admin' );
		$js    = <<<JS
jQuery(function($){
	var ospNonce = '{$nonce}';

	/* ---------- Tabs ---------- */
	$('.osp-tab').on('click', function(){
		var tab = $(this).data('tab');
		$('.osp-tab').removeClass('active');
		$(this).addClass('active');
		$('.osp-tab-panel').addClass('osp-hidden');
		$('.osp-tab-panel[data-panel="' + tab + '"]').removeClass('osp-hidden');
	});

	/* ---------- Live preview helpers ---------- */
	function pvVar(name, val){
		document.getElementById('osp-pv-box').style.setProperty(name, val);
	}
	function pvTitle(){
		var v = $('#osp-in-title').val() || '';
		$('#osp-pv-title').text(v).toggle(!!v);
	}
	function getEditorContent(){
		if (window.tinymce) {
			var ed = tinymce.get('osp_content_editor');
			if (ed && !ed.isHidden()) { return ed.getContent(); }
		}
		return $('#osp_content_editor').val() || '';
	}
	var lastContent = null;
	function pvContent(){
		var c = getEditorContent();
		if (c === lastContent) { return; }
		lastContent = c;
		$('#osp-pv-content').html(c).toggle(!!$.trim($('<div>').html(c).text()) || /<img/i.test(c));
	}
	function pvType(){
		var t = $('input[name="osp[content_type]"]:checked').val();
		$('#osp-pv-image').toggle(t === 'image');
		$('#osp-pv-products').toggle(t === 'products');
	}
	function pvSize(){
		var s = $('input[name="osp[size]"]:checked').val();
		var w = s === 'custom' ? (parseInt($('#osp-in-maxwidth').val(), 10) || 600) : 640;
		$('#osp-pv-box').css('max-width', w + 'px');
	}
	function pvFontSizes(){
		$('#osp-pv-title').css('font-size', (parseInt($('#osp-in-titlesize').val(), 10) || 30) + 'px');
		$('#osp-pv-content').css('font-size', (parseInt($('#osp-in-contentsize').val(), 10) || 17) + 'px');
	}
	function pvClose(){
		var pos = $('#osp-in-closepos').val() || 'left';
		var st  = $('#osp-in-closestyle').val() || 'light';
		$('#osp-pv-close').attr('class', 'osp-pv-close pos-' + pos + ' style-' + st);
	}
	function pvImage(url){
		if (url) {
			$('#osp-pv-image').html('<img src="' + url + '" alt="">');
		} else {
			$('#osp-pv-image').html('<div class="osp-pv-image-empty">כאן תוצג התמונה שתעלה</div>');
		}
	}
	function pvFull(){
		var full = $('#osp-in-imagefull').is(':checked')
			&& $('input[name="osp[content_type]"]:checked').val() === 'image';
		$('#osp-pv-box').toggleClass('pv-full', full);
	}
	function pvBg(color){
		$('#osp-pv-box').css('background-color', color || '#ffffff');
	}
	function pvTextColor(color){
		$('#osp-pv-box, #osp-pv-title, #osp-pv-content').css('color', color || '');
	}
	function pvCta(){
		var t = $('#osp-in-ctatext').val() || '';
		$('#osp-pv-btn').text(t);
		$('#osp-pv-cta').toggle(!!t);
	}
	function pvCtaRadius(){
		var v = $('#osp-in-ctaradius').val();
		pvVar('--obr', v === 'square' ? '0' : (v === 'round' ? '50px' : '6px'));
	}
	function pvCtaSize(){
		pvVar('--obfs', (parseInt($('#osp-in-ctasize').val(), 10) || 16) + 'px');
	}
	function pvDisc(){
		var t = $('#osp-in-disctext').val() || '';
		$('#osp-pv-disc').html($.trim(t).split('\\n').join('<br>')).toggle(!!$.trim(t));
	}
	function pvDiscStyle(){
		$('#osp-pv-disc').css('font-size', (parseInt($('#osp-in-discsize').val(), 10) || 12) + 'px');
	}
	var pvProductsTimer;
	function pvProducts(){
		clearTimeout(pvProductsTimer);
		pvProductsTimer = setTimeout(function(){
			var ids = $('#osp-in-products').val() || [];
			if (!ids.length) { $('#osp-pv-products').empty(); return; }
			$.get(ajaxurl, { action: 'osp_products_preview', ids: ids, _wpnonce: ospNonce }, function(res){
				if (!res || !res.length) { $('#osp-pv-products').empty(); return; }
				var html = '';
				for (var i = 0; i < res.length; i++) {
					html += '<span class="osp-pv-product"><span class="osp-pv-pimg">' + res[i].img + '</span>'
						+ '<span class="osp-pv-pname"></span><span class="osp-pv-pprice">' + res[i].price + '</span></span>';
				}
				var \$wrap = $(html);
				$('#osp-pv-products').empty().append(\$wrap);
				$('#osp-pv-products .osp-pv-pname').each(function(idx){ $(this).text(res[idx] ? res[idx].name : ''); });
			});
		}, 300);
	}

	/* ---------- Media uploader ---------- */
	var frame;
	$('#osp-image-upload').on('click', function(e){
		e.preventDefault();
		if (frame) { frame.open(); return; }
		frame = wp.media({ title: 'בחירת תמונה', multiple: false, library: { type: 'image' } });
		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			$('#osp-image-id').val(att.id);
			var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
			var big = (att.sizes && att.sizes.large) ? att.sizes.large.url : att.url;
			$('#osp-image-preview').html('<img src="' + url + '" alt="">');
			$('#osp-image-remove').removeClass('osp-hidden');
			pvImage(big);
		});
		frame.open();
	});
	$('#osp-image-remove').on('click', function(){
		$('#osp-image-id').val('');
		$('#osp-image-preview').empty();
		$(this).addClass('osp-hidden');
		pvImage('');
	});

	/* ---------- Color pickers + eyedropper ---------- */
	function initPicker(sel, cb){
		if (!$.fn.wpColorPicker) { return; }
		var input = $(sel);
		input.wpColorPicker({
			change: function(e, ui){ cb(ui.color.toString()); },
			clear: function(){ cb(''); }
		});
		if (window.EyeDropper) {
			var btn = $('<button type="button" class="button osp-eyedrop" title="דגימת צבע מהמסך"><span class="dashicons dashicons-color-picker"></span></button>');
			input.closest('.wp-picker-container').find('.wp-color-result').after(btn);
			btn.on('click', function(e){
				e.preventDefault();
				new EyeDropper().open().then(function(r){
					input.wpColorPicker('color', r.sRGBHex);
				}).catch(function(){});
			});
		}
	}
	initPicker('#osp-in-bgcolor', pvBg);
	initPicker('#osp-in-textcolor', pvTextColor);
	initPicker('#osp-in-ctabg', function(c){ pvVar('--obg', c || '#111111'); });
	initPicker('#osp-in-ctacolor', function(c){ pvVar('--oc', c || '#ffffff'); });
	initPicker('#osp-in-ctahbg', function(c){ pvVar('--ohbg', c || '#333333'); });
	initPicker('#osp-in-ctahcolor', function(c){ pvVar('--ohc', c || '#ffffff'); });
	initPicker('#osp-in-disccolor', function(c){ $('#osp-pv-disc').css('color', c || '#666666'); });

	/* ---------- Field toggles + preview bindings ---------- */
	$('input[name="osp[content_type]"]').on('change', function(){
		$('.osp-type-image').toggleClass('osp-hidden', this.value !== 'image');
		$('.osp-type-products').toggleClass('osp-hidden', this.value !== 'products');
		pvType();
		pvFull();
	});
	$('#osp-in-imagefull').on('change', pvFull);
	$('#osp-in-imagelinkon').on('change', function(){
		$('.osp-image-link-field').toggleClass('osp-hidden', !this.checked);
	});
	$('input[name="osp[size]"]').on('change', function(){
		$('.osp-size-custom').toggleClass('osp-hidden', this.value !== 'custom');
		pvSize();
	});
	$('input[name="osp[display]"]').on('change', function(){
		$('.osp-display-specific').toggleClass('osp-hidden', this.value !== 'specific');
		$('.osp-display-all').toggleClass('osp-hidden', this.value !== 'all');
	});
	$('#osp-in-title').on('input', pvTitle);
	$('#osp-in-ctatext').on('input', pvCta);
	$('#osp-in-maxwidth').on('input change', pvSize);
	$('#osp-in-titlesize, #osp-in-contentsize').on('input change', pvFontSizes);
	$('#osp-in-closepos, #osp-in-closestyle').on('change', pvClose);
	$('#osp-in-ctaradius').on('change', pvCtaRadius);
	$('#osp-in-ctasize').on('input change', pvCtaSize);
	$('#osp-in-disctext').on('input', pvDisc);
	$('#osp-in-discsize').on('input change', pvDiscStyle);
	$('#osp-in-products').on('change', pvProducts);
	$('#osp_content_editor').on('input change', pvContent);
	setInterval(pvContent, 700); // catches TinyMCE (visual mode) typing

	/* ---------- Page search (select2/selectWoo via WooCommerce) ---------- */
	if ($.fn.selectWoo || $.fn.select2) {
		var initFn = $.fn.selectWoo ? 'selectWoo' : 'select2';
		$('.osp-page-search').each(function(){
			$(this)[initFn]({
				dir: 'rtl',
				minimumInputLength: 1,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function(params){ return { action: 'osp_search_pages', s: params.term, _wpnonce: ospNonce }; },
					processResults: function(data){ return { results: data }; }
				}
			});
		});
	}
});
JS;
		wp_add_inline_script( 'jquery-core', $js, 'after' );
	}

	public function ajax_search_pages() {
		check_ajax_referer( 'osp_admin' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		$term    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$results = array();
		if ( '' !== $term ) {
			$q = new WP_Query( array(
				'post_type'      => array( 'page', 'post', 'product' ),
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 20,
				'orderby'        => 'relevance',
			) );
			foreach ( $q->posts as $p ) {
				$type_labels = array( 'page' => 'עמוד', 'post' => 'פוסט', 'product' => 'מוצר' );
				$label       = isset( $type_labels[ $p->post_type ] ) ? $type_labels[ $p->post_type ] : $p->post_type;
				$results[]   = array(
					'id'   => $p->ID,
					'text' => $p->post_title . ' (' . $label . ')',
				);
			}
		}
		wp_send_json( $results );
	}

	public function ajax_products_preview() {
		check_ajax_referer( 'osp_admin' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		$results = array();
		if ( function_exists( 'wc_get_product' ) && isset( $_GET['ids'] ) && is_array( $_GET['ids'] ) ) {
			$ids = array_slice( array_filter( array_map( 'absint', wp_unslash( $_GET['ids'] ) ) ), 0, 30 );
			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				$results[] = array(
					'id'    => $product_id,
					'name'  => $product->get_name(),
					'img'   => $product->get_image( 'woocommerce_thumbnail' ),
					'price' => $product->get_price_html(),
				);
			}
		}
		wp_send_json( $results );
	}

	/* ---------------- Frontend ---------------- */

	public function render_popups() {
		if ( is_admin() ) {
			return;
		}
		$popups = get_posts( array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order date',
			'order'          => 'ASC',
		) );
		if ( ! $popups ) {
			return;
		}

		$current_id = (int) get_queried_object_id();
		$is_home    = is_front_page();
		$to_render  = array();

		foreach ( $popups as $popup ) {
			$m = $this->get_meta( $popup->ID );
			if ( 'home' === $m['display'] ) {
				if ( ! $is_home ) {
					continue;
				}
			} elseif ( 'specific' === $m['display'] ) {
				if ( ! $current_id || ! in_array( $current_id, array_map( 'intval', $m['pages'] ), true ) ) {
					continue;
				}
			} else {
				if ( $current_id && in_array( $current_id, array_map( 'intval', $m['exclude'] ), true ) ) {
					continue;
				}
			}
			$to_render[] = array( 'post' => $popup, 'meta' => $m );
		}

		if ( ! $to_render ) {
			return;
		}

		$this->print_css();
		foreach ( $to_render as $item ) {
			$this->print_popup( $item['post'], $item['meta'] );
		}
		$this->print_js();
	}

	private function print_popup( $popup, $m ) {
		$style = 'auto' === $m['size']
			? 'max-width:min(90vw,640px);'
			: 'width:100%;max-width:min(90vw,' . (int) $m['max_width'] . 'px);';
		$style .= 'background-color:' . self::hex_or( $m['bg_color'], '#ffffff' ) . ';';
		$text_color  = self::hex_or( $m['text_color'], '' );
		$color_style = $text_color ? 'color:' . $text_color . ';' : '';
		$style      .= $color_style;
		$style .= '--osp-btn-bg:' . self::hex_or( $m['cta_bg'], '#111111' ) . ';'
			. '--osp-btn-c:' . self::hex_or( $m['cta_color'], '#ffffff' ) . ';'
			. '--osp-btn-hbg:' . self::hex_or( $m['cta_hover_bg'], '#333333' ) . ';'
			. '--osp-btn-hc:' . self::hex_or( $m['cta_hover_color'], '#ffffff' ) . ';'
			. '--osp-btn-r:' . self::radius_px( $m['cta_radius'] ) . ';'
			. '--osp-btn-fs:' . max( 10, min( 40, (int) $m['cta_size'] ) ) . 'px;';

		$title_size   = max( 10, min( 80, (int) $m['title_size'] ) );
		$content_size = max( 10, min( 60, (int) $m['content_size'] ) );
		$disc_size    = max( 8, min( 30, (int) $m['disc_size'] ) );
		$disc_color   = self::hex_or( $m['disc_color'], '#666666' );

		$box_classes = 'osp-box';
		if ( 'image' === $m['content_type'] && $m['image_id'] && '1' === $m['image_full'] ) {
			$box_classes .= ' osp-box--full';
		}
		$close_classes = 'osp-close osp-close--' . ( 'right' === $m['close_pos'] ? 'right' : 'left' )
			. ' osp-close--' . ( 'dark' === $m['close_style'] ? 'dark' : 'light' );
		$device = in_array( $m['device'], array( 'desktop', 'mobile' ), true ) ? $m['device'] : 'both';
		?>
		<div class="osp-overlay" data-popup="<?php echo (int) $popup->ID; ?>" data-days="<?php echo (int) $m['cookie_days']; ?>" data-device="<?php echo esc_attr( $device ); ?>" role="dialog" aria-modal="true" aria-hidden="true">
			<div class="<?php echo esc_attr( $box_classes ); ?>" style="<?php echo esc_attr( $style ); ?>">
				<button type="button" class="<?php echo esc_attr( $close_classes ); ?>" aria-label="סגירה">&times;</button>
				<?php if ( $m['title'] ) : ?>
					<h3 class="osp-title" style="font-size:<?php echo (int) $title_size; ?>px;<?php echo esc_attr( $color_style ); ?>"><?php echo esc_html( $m['title'] ); ?></h3>
				<?php endif; ?>
				<?php if ( $m['content'] ) : ?>
					<div class="osp-content" style="font-size:<?php echo (int) $content_size; ?>px;<?php echo esc_attr( $color_style ); ?>"><?php echo wp_kses_post( wpautop( $m['content'] ) ); ?></div>
				<?php endif; ?>
				<?php if ( 'image' === $m['content_type'] && $m['image_id'] ) : ?>
					<div class="osp-image">
						<?php
						$img = wp_get_attachment_image( (int) $m['image_id'], 'large' );
						if ( '1' === $m['image_link_on'] && $m['image_link'] ) {
							echo '<a href="' . esc_url( $m['image_link'] ) . '">' . $img . '</a>';
						} else {
							echo $img;
						}
						?>
					</div>
				<?php elseif ( 'products' === $m['content_type'] && $m['products'] && function_exists( 'wc_get_product' ) ) : ?>
					<div class="osp-products">
						<?php foreach ( $m['products'] as $product_id ) :
							$product = wc_get_product( $product_id );
							if ( ! $product || ! $product->is_visible() ) {
								continue;
							}
							?>
							<a class="osp-product" href="<?php echo esc_url( get_permalink( $product_id ) ); ?>">
								<span class="osp-product-img"><?php echo $product->get_image( 'woocommerce_thumbnail' ); ?></span>
								<span class="osp-product-name"><?php echo esc_html( $product->get_name() ); ?></span>
								<span class="osp-product-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( $m['cta_text'] ) : ?>
					<div class="osp-cta">
						<a class="osp-btn<?php echo $m['cta_link'] ? '' : ' osp-btn--close'; ?>" href="<?php echo esc_url( $m['cta_link'] ? $m['cta_link'] : '#' ); ?>"><?php echo esc_html( $m['cta_text'] ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( trim( $m['disc_text'] ) ) : ?>
					<div class="osp-disclaimer" style="font-size:<?php echo (int) $disc_size; ?>px;color:<?php echo esc_attr( $disc_color ); ?>"><?php echo wp_kses_post( wpautop( $m['disc_text'] ) ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function print_css() {
		?>
		<style id="osp-popup-css">
		.osp-overlay{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:20px;direction:rtl}
		.osp-overlay.osp-open{display:flex}
		.osp-box{position:relative;background:#fff;border-radius:10px;padding:34px 24px 24px;max-height:88vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.25);text-align:center}
		.osp-close{position:absolute;top:10px;width:32px;height:32px;border:0;border-radius:50%;font-size:19px;line-height:32px;padding:0;text-align:center;cursor:pointer;transition:filter .15s}
		.osp-close:hover{filter:brightness(.92)}
		.osp-close--left{left:10px;right:auto}
		.osp-close--right{right:10px;left:auto}
		.osp-close--light{background:#f3f3f3;color:#111}
		.osp-close--dark{background:#111;color:#fff}
		.osp-title{margin:0 0 10px;font-weight:700;line-height:1.25;color:inherit}
		.osp-content{margin-bottom:14px;color:inherit}
		.osp-content p{font-size:inherit;color:inherit;margin:0 0 .8em}
		.osp-content p:last-child{margin-bottom:0}
		.osp-image img{max-width:100%;height:auto;display:block;margin:0 auto;border-radius:6px}
		.osp-products{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-top:6px}
		.osp-product{display:block;text-decoration:none;color:inherit}
		.osp-product-img img{max-width:100%;height:auto;border-radius:6px}
		.osp-product-name{display:block;margin-top:6px;font-weight:600;font-size:.95em}
		.osp-product-price{display:block;margin-top:2px;font-size:.9em}
		.osp-cta{margin-top:18px}
		.osp-btn{display:inline-block;padding:11px 28px;border-radius:var(--osp-btn-r,6px);font-size:var(--osp-btn-fs,16px);text-decoration:none;font-weight:600;background:var(--osp-btn-bg,#111);color:var(--osp-btn-c,#fff);transition:background .15s,color .15s}
		.osp-btn:hover{background:var(--osp-btn-hbg,#333);color:var(--osp-btn-hc,#fff)}
		.osp-disclaimer{margin-top:14px;line-height:1.5}
		.osp-disclaimer p{font-size:inherit;color:inherit;margin:0 0 .5em}
		.osp-disclaimer p:last-child{margin-bottom:0}
		.osp-box--full{padding:0;overflow:hidden}
		.osp-box--full .osp-title{padding:18px 24px 0}
		.osp-box--full .osp-content{padding:0 24px}
		.osp-box--full .osp-image img{border-radius:0;width:100%}
		.osp-box--full .osp-cta{padding:0 24px 20px;margin-top:16px}
		.osp-box--full .osp-disclaimer{padding:0 24px 18px;margin-top:10px}
		@media (max-width:600px){.osp-box{padding:30px 14px 16px}.osp-box--full{padding:0}.osp-products{grid-template-columns:repeat(auto-fit,minmax(110px,1fr))}}
		</style>
		<?php
	}

	private function print_js() {
		?>
		<script id="osp-popup-js">
		(function(){
			var OSP_AJAX = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			function track(id, ev){
				try {
					var data = new FormData();
					data.append('action', 'osp_track');
					data.append('popup', id);
					data.append('event', ev);
					if (navigator.sendBeacon) {
						navigator.sendBeacon(OSP_AJAX, data);
					} else {
						var x = new XMLHttpRequest();
						x.open('POST', OSP_AJAX, true);
						x.send(data);
					}
				} catch(e) {}
			}
			function getCookie(name){
				var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
				return m ? m.pop() : '';
			}
			function setCookie(name, days){
				var ex = '';
				if (days > 0) {
					var d = new Date();
					d.setTime(d.getTime() + days*24*60*60*1000);
					ex = ';expires=' + d.toUTCString();
				}
				document.cookie = name + '=1;path=/' + ex + ';SameSite=Lax';
			}
			function init(){
				var isMobile = window.matchMedia('(max-width: 768px)').matches;
				var overlays = document.querySelectorAll('.osp-overlay');
				for (var i = 0; i < overlays.length; i++) {
					var ov = overlays[i];
					var device = ov.getAttribute('data-device') || 'both';
					if (device === 'desktop' && isMobile) { continue; }
					if (device === 'mobile' && !isMobile) { continue; }
					var name = 'osp_popup_' + ov.getAttribute('data-popup');
					if (getCookie(name)) { continue; }
					open(ov, name);
					break; // show only one popup per page load
				}
			}
			function open(ov, cookieName){
				var id = ov.getAttribute('data-popup');
				ov.classList.add('osp-open');
				ov.setAttribute('aria-hidden', 'false');
				track(id, 'view');
				var days = parseInt(ov.getAttribute('data-days'), 10) || 0;
				function doClose(sendClose){
					ov.classList.remove('osp-open');
					ov.setAttribute('aria-hidden', 'true');
					setCookie(cookieName, days);
					if (sendClose) { track(id, 'close'); }
					document.removeEventListener('keydown', onKey);
				}
				function close(){ doClose(true); }
				function onKey(e){ if (e.key === 'Escape') { close(); } }
				ov.querySelector('.osp-close').addEventListener('click', close);
				ov.addEventListener('click', function(e){ if (e.target === ov) { close(); } });
				document.addEventListener('keydown', onKey);
				var links = ov.querySelectorAll('a');
				for (var j = 0; j < links.length; j++) {
					if (links[j].classList.contains('osp-btn--close')) {
						links[j].addEventListener('click', function(e){
							e.preventDefault();
							track(id, 'click');
							doClose(false);
						});
					} else {
						links[j].addEventListener('click', function(){
							setCookie(cookieName, days);
							track(id, 'click');
						});
					}
				}
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}
}

new OSP_Simple_Popup();
