<?php
/**
 * Admin settings page for Extroverting Core.
 *
 * Holds the AWS SES SMS transport configuration and the OTP dev-mode switch.
 * Values are read by Ext_Aws_Sms_Transport::config() and Ext_Auth::is_dev_mode().
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings.
 */
final class Ext_Settings {

	const OPT_GROUP = 'extroverting_core_settings';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the admin menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_management_page(
			__( 'Extroverting', 'extroverting-core' ),
			__( 'Extroverting', 'extroverting-core' ),
			'manage_options',
			'extroverting-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings + fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( self::OPT_GROUP, 'ext_aws_region', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'us-east-1',
		) );
		register_setting( self::OPT_GROUP, 'ext_aws_access_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( self::OPT_GROUP, 'ext_aws_secret_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( self::OPT_GROUP, 'ext_aws_session_token', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( self::OPT_GROUP, 'ext_aws_from_number', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_phone' ),
		) );
		register_setting( self::OPT_GROUP, 'ext_otp_dev_mode', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::OPT_GROUP, 'extroverting_default_city', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
	}

	/**
	 * Sanitize a phone number to E.164-ish.
	 *
	 * @param string $v Raw.
	 * @return string
	 */
	public function sanitize_phone( $v ) {
		return ext_normalize_phone( sanitize_text_field( wp_unslash( (string) $v ) ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$default_city = (int) get_option( 'extroverting_default_city', 0 );
		$cities        = get_posts( array(
			'post_type'      => 'ext_city',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		$region  = get_option( 'ext_aws_region', 'us-east-1' );
		$access  = get_option( 'ext_aws_access_key', '' );
		$secret  = get_option( 'ext_aws_secret_key', '' );
		$session = get_option( 'ext_aws_session_token', '' );
		$from    = get_option( 'ext_aws_from_number', '' );
		$dev     = (int) get_option( 'ext_otp_dev_mode', 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Extroverting Settings', 'extroverting-core' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPT_GROUP ); ?>

				<h2><?php esc_html_e( 'Site', 'extroverting-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="extroverting_default_city"><?php esc_html_e( 'Default (hero) city', 'extroverting-core' ); ?></label></th>
						<td>
							<select name="extroverting_default_city" id="extroverting_default_city">
								<option value="0"><?php esc_html_e( '— Automatic (launched city with most events) —', 'extroverting-core' ); ?></option>
								<?php foreach ( $cities as $c ) : ?>
									<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $default_city, $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'City shown on the homepage. Must be a launched city; otherwise the site falls back to the launched city with the most upcoming events. Can also be set via wp-cli: wp option update extroverting_default_city <post ID>.', 'extroverting-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'OTP — dev mode', 'extroverting-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show code on screen', 'extroverting-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ext_otp_dev_mode" value="1" <?php checked( $dev, 1 ); ?>>
								<?php esc_html_e( 'Display the one-time code on the login screen instead of delivering it. Auto-on when WP_DEBUG is true; leave unchecked in production. (Forces off when checked-elsewhere via the extroverting/otp/dev_mode filter.)', 'extroverting-core' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SMS — AWS End User Messaging (SES v2)', 'extroverting-core' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Used for the mobile channel of passwordless login. Requires an AWS account with SES SMS enabled, a verified/registered sender (10DLC for US numbers), and an IAM credential with ses:SendSmsMessage. Credentials may alternatively be supplied via AWS_REGION / AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN / AWS_SMS_FROM environment variables (options take precedence when set).', 'extroverting-core' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ext_aws_region"><?php esc_html_e( 'Region', 'extroverting-core' ); ?></label></th>
						<td><input class="regular-text" type="text" id="ext_aws_region" name="ext_aws_region" value="<?php echo esc_attr( $region ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ext_aws_from_number"><?php esc_html_e( 'Sender number (E.164)', 'extroverting-core' ); ?></label></th>
						<td>
							<input class="regular-text" type="text" id="ext_aws_from_number" name="ext_aws_from_number" value="<?php echo esc_attr( $from ); ?>" placeholder="+14155550123">
							<p class="description"><?php esc_html_e( 'The verified phone number (or sender ID) that messages are sent from.', 'extroverting-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ext_aws_access_key"><?php esc_html_e( 'Access key ID', 'extroverting-core' ); ?></label></th>
						<td><input class="regular-text" type="text" id="ext_aws_access_key" name="ext_aws_access_key" value="<?php echo esc_attr( $access ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ext_aws_secret_key"><?php esc_html_e( 'Secret access key', 'extroverting-core' ); ?></label></th>
						<td>
							<input class="regular-text" type="password" id="ext_aws_secret_key" name="ext_aws_secret_key" value="<?php echo esc_attr( $secret ); ?>" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Stored in the options table in plaintext. Prefer environment variables for production.', 'extroverting-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ext_aws_session_token"><?php esc_html_e( 'Session token (optional)', 'extroverting-core' ); ?></label></th>
						<td><input class="regular-text" type="password" id="ext_aws_session_token" name="ext_aws_session_token" value="<?php echo esc_attr( $session ); ?>" autocomplete="new-password"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
