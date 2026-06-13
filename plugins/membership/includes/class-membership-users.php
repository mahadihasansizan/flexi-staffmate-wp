<?php
/**
 * User membership fields.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds membership assignment and user-specific pricing overrides to user screens.
 */
final class Users {
    public const OVERRIDE_ENABLED_META = 'membership_price_override_enabled';
    public const OVERRIDE_TYPE_META    = 'membership_price_override_type';
    public const OVERRIDE_AMOUNT_META  = 'membership_price_override_amount';

    /** @var Users|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /** @var Roles */
    private $roles;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings service.
     * @param Roles    $roles Roles service.
     */
    private function __construct( Settings $settings, Roles $roles ) {
        $this->settings = $settings;
        $this->roles    = $roles;
    }

    /**
     * Get singleton.
     *
     * @param Settings|null $settings Settings service.
     * @param Roles|null    $roles Roles service.
     * @return Users
     */
    public static function instance( ?Settings $settings = null, ?Roles $roles = null ): Users {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings ?: Settings::instance(), $roles ?: Roles::instance() );
        }
        return self::$instance;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_action( 'show_user_profile', array( $this, 'render_existing_user_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_existing_user_fields' ) );
        add_action( 'user_new_form', array( $this, 'render_new_user_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );
        add_action( 'user_register', array( $this, 'save_new_user_fields' ), 20 );
        add_filter( 'manage_users_columns', array( $this, 'users_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'users_column_content' ), 10, 3 );
    }

    /**
     * Existing user fields.
     *
     * @param \WP_User $user User.
     * @return void
     */
    public function render_existing_user_fields( \WP_User $user ): void {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        $this->render_fields( (int) $user->ID );
    }

    /**
     * New user fields.
     *
     * @return void
     */
    public function render_new_user_fields(): void {
        if ( ! current_user_can( 'create_users' ) ) {
            return;
        }
        $this->render_fields( 0 );
    }

    /**
     * Render fields.
     *
     * @param int $user_id User ID, or 0 for add user.
     * @return void
     */
    private function render_fields( int $user_id ): void {
        $levels        = $this->settings->get_levels();
        $pricing_types = $this->settings->pricing_types();
        $level_key     = $user_id ? $this->roles->get_user_level_key( $user_id ) : '';
        $override_on   = $user_id ? (int) get_user_meta( $user_id, self::OVERRIDE_ENABLED_META, true ) : 0;
        $override_type = $user_id ? sanitize_key( (string) get_user_meta( $user_id, self::OVERRIDE_TYPE_META, true ) ) : 'none';
        $amount        = $user_id ? (string) get_user_meta( $user_id, self::OVERRIDE_AMOUNT_META, true ) : '';

        if ( ! isset( $pricing_types[ $override_type ] ) ) {
            $override_type = 'none';
        }
        ?>
        <h2><?php esc_html_e( 'Membership', 'membership' ); ?></h2>
        <div class="membership-user-panel">
            <?php wp_nonce_field( 'membership_save_user_membership', 'membership_user_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="membership_level_key"><?php esc_html_e( 'Membership role', 'membership' ); ?></label></th>
                    <td>
                        <select name="membership_level_key" id="membership_level_key">
                            <option value=""><?php esc_html_e( 'No membership role', 'membership' ); ?></option>
                            <?php foreach ( $levels as $key => $level ) : ?>
                                <?php if ( empty( $level['enabled'] ) ) : ?>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $level_key, (string) $key ); ?>>
                                    <?php echo esc_html( $this->roles->role_label( $level ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Assign a membership role here, or choose a Member role from the normal WordPress Role dropdown.', 'membership' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'User-specific pricing override', 'membership' ); ?></th>
                    <td>
                        <label class="membership-inline-check">
                            <input type="checkbox" name="membership_price_override_enabled" value="1" <?php checked( 1, $override_on ); ?> />
                            <?php esc_html_e( 'Use this pricing rule for this user when no product-specific rule exists.', 'membership' ); ?>
                        </label>
                        <div class="membership-user-pricing-grid">
                            <select name="membership_price_override_type">
                                <?php foreach ( $pricing_types as $type => $label ) : ?>
                                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $override_type, $type ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.0001" min="0" name="membership_price_override_amount" value="<?php echo esc_attr( $amount ); ?>" placeholder="<?php esc_attr_e( 'Amount', 'membership' ); ?>" />
                        </div>
                        <p class="description"><?php esc_html_e( 'Priority: product-specific rule, then this user override, then the global membership level rule.', 'membership' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Save new user fields.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function save_new_user_fields( int $user_id ): void {
        $this->save_user_fields( $user_id );
        $this->roles->sync_user_meta_from_roles( $user_id );
    }

    /**
     * Save fields.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function save_user_fields( int $user_id ): void {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        if ( empty( $_POST['membership_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['membership_user_nonce'] ) ), 'membership_save_user_membership' ) ) {
            return;
        }

        $level_key   = isset( $_POST['membership_level_key'] ) ? $this->settings->normalize_key( wp_unslash( (string) $_POST['membership_level_key'] ) ) : '';
        $posted_role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : '';
        if ( '' === $level_key && $posted_role && $this->roles->is_membership_role( $posted_role ) ) {
            $level_key = $this->roles->level_key_from_role( $posted_role );
        }

        $this->roles->assign_level_to_user( $user_id, $level_key );

        $override_enabled = ! empty( $_POST['membership_price_override_enabled'] ) ? 1 : 0;
        $override_type    = isset( $_POST['membership_price_override_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['membership_price_override_type'] ) ) : 'none';
        $amount           = isset( $_POST['membership_price_override_amount'] ) ? $this->settings->sanitize_decimal( $_POST['membership_price_override_amount'] ) : 0;

        if ( ! isset( $this->settings->pricing_types()[ $override_type ] ) ) {
            $override_type = 'none';
        }

        update_user_meta( $user_id, self::OVERRIDE_ENABLED_META, $override_enabled );
        update_user_meta( $user_id, self::OVERRIDE_TYPE_META, $override_type );
        update_user_meta( $user_id, self::OVERRIDE_AMOUNT_META, $amount );
    }

    /**
     * Add users list column.
     *
     * @param array<string,string> $columns Columns.
     * @return array<string,string>
     */
    public function users_columns( array $columns ): array {
        $columns['membership_level'] = __( 'Membership', 'membership' );
        return $columns;
    }

    /**
     * Column content.
     *
     * @param string $value Existing value.
     * @param string $column Column key.
     * @param int    $user_id User ID.
     * @return string
     */
    public function users_column_content( string $value, string $column, int $user_id ): string {
        if ( 'membership_level' !== $column ) {
            return $value;
        }

        $key   = $this->roles->get_user_level_key( $user_id );
        $level = $key ? $this->settings->get_level( $key ) : null;
        if ( ! $level ) {
            return '&mdash;';
        }

        return esc_html( (string) $level['name'] );
    }
}
