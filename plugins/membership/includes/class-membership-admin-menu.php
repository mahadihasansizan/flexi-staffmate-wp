<?php
/**
 * Admin menu and screens.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Top-level Membership backend.
 */
final class AdminMenu {
    /** @var AdminMenu|null */
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
     * @return AdminMenu
     */
    public static function instance( ?Settings $settings = null, ?Roles $roles = null ): AdminMenu {
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
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_membership_save_levels', array( $this, 'save_levels' ) );
        add_action( 'admin_post_membership_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_membership_sync_roles', array( $this, 'manual_sync_roles' ) );
        add_action( 'admin_post_membership_create_login_page', array( $this, 'create_login_page' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    /**
     * Register top-level menu.
     *
     * @return void
     */
    public function register_menu(): void {
        add_menu_page( __( 'Membership', 'membership' ), __( 'Membership', 'membership' ), 'manage_options', 'membership', array( $this, 'render_dashboard' ), 'dashicons-groups', 56 );
        add_submenu_page( 'membership', __( 'Dashboard', 'membership' ), __( 'Dashboard', 'membership' ), 'manage_options', 'membership', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'membership', __( 'Levels & Roles', 'membership' ), __( 'Levels & Roles', 'membership' ), 'manage_options', 'membership-levels', array( $this, 'render_levels' ) );
        add_submenu_page( 'membership', __( 'Members', 'membership' ), __( 'Members', 'membership' ), 'list_users', 'membership-members', array( $this, 'render_members' ) );
        add_submenu_page( 'membership', __( 'Settings', 'membership' ), __( 'Settings', 'membership' ), 'manage_options', 'membership-settings', array( $this, 'render_settings' ) );
    }

    /**
     * Dashboard page.
     *
     * @return void
     */
    public function render_dashboard(): void {
        $this->assert_cap( 'manage_options' );
        $levels   = $this->settings->get_levels();
        $settings = $this->settings->get_settings();
        $roles    = get_option( Roles::MANAGED_ROLES_OPTION, array() );
        $roles    = is_array( $roles ) ? $roles : array();
        $enabled  = array_filter( $levels, static function ( array $level ): bool { return ! empty( $level['enabled'] ); } );
        $woo      = class_exists( 'WooCommerce' );
        $members  = $this->count_members();
        $this->header( __( 'Membership Dashboard', 'membership' ), 'membership' );
        ?>
        <div class="membership-grid membership-grid-4">
            <?php $this->stat_card( __( 'Login Gate', 'membership' ), ! empty( $settings['login_gate_enabled'] ) ? __( 'Enabled', 'membership' ) : __( 'Disabled', 'membership' ), __( 'Guests are redirected before entering protected pages.', 'membership' ) ); ?>
            <?php $this->stat_card( __( 'Active Levels', 'membership' ), (string) count( $enabled ), __( 'Each active level becomes a WordPress role.', 'membership' ) ); ?>
            <?php $this->stat_card( __( 'Membership Roles', 'membership' ), (string) count( $roles ), __( 'Shown in Users > Add New > Role.', 'membership' ) ); ?>
            <?php $this->stat_card( __( 'Members', 'membership' ), (string) $members, __( 'Users assigned to membership roles.', 'membership' ) ); ?>
        </div>
        <div class="membership-grid membership-grid-2">
            <section class="membership-card">
                <h2><?php esc_html_e( 'Setup checklist', 'membership' ); ?></h2>
                <ol class="membership-checklist">
                    <li><strong><?php esc_html_e( 'Create levels and roles', 'membership' ); ?></strong><span><?php esc_html_e( 'Open Levels & Roles, add or remove levels, and save to synchronize WordPress roles.', 'membership' ); ?></span></li>
                    <li><strong><?php esc_html_e( 'Assign members', 'membership' ); ?></strong><span><?php esc_html_e( 'Go to Users > Add New and choose a Member role or use the Membership field.', 'membership' ); ?></span></li>
                    <li><strong><?php esc_html_e( 'Configure product rules', 'membership' ); ?></strong><span><?php esc_html_e( 'Edit a WooCommerce product and open the Membership Pricing tab.', 'membership' ); ?></span></li>
                    <li><strong><?php esc_html_e( 'Protect the site', 'membership' ); ?></strong><span><?php esc_html_e( 'Keep the login gate enabled and use the Membership Login page.', 'membership' ); ?></span></li>
                </ol>
            </section>
            <section class="membership-card">
                <h2><?php esc_html_e( 'Pricing priority', 'membership' ); ?></h2>
                <div class="membership-priority-flow">
                    <div><span>1</span><?php esc_html_e( 'Product-specific membership rule', 'membership' ); ?></div>
                    <div><span>2</span><?php esc_html_e( 'User-specific pricing override', 'membership' ); ?></div>
                    <div><span>3</span><?php esc_html_e( 'Global membership level rule', 'membership' ); ?></div>
                </div>
                <p><?php esc_html_e( 'WooCommerce pricing hooks are active only when WooCommerce is active.', 'membership' ); ?> <strong><?php echo $woo ? esc_html__( 'WooCommerce detected.', 'membership' ) : esc_html__( 'WooCommerce not detected.', 'membership' ); ?></strong></p>
            </section>
        </div>
        <?php
        $this->footer();
    }

    /**
     * Levels page.
     *
     * @return void
     */
    public function render_levels(): void {
        $this->assert_cap( 'manage_options' );
        $levels = $this->settings->get_levels();
        $types  = $this->settings->pricing_types();
        $this->header( __( 'Levels & Roles', 'membership' ), 'membership-levels' );
        ?>
        <section class="membership-card">
            <div class="membership-card-header membership-card-header-split">
                <div>
                    <h2><?php esc_html_e( 'Membership roles', 'membership' ); ?></h2>
                    <p><?php esc_html_e( 'Add, remove, rename, enable, disable, and price membership levels. Every enabled level creates a WordPress role.', 'membership' ); ?></p>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="membership_sync_roles" />
                    <?php wp_nonce_field( 'membership_sync_roles' ); ?>
                    <button class="button" type="submit"><?php esc_html_e( 'Synchronize roles now', 'membership' ); ?></button>
                </form>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="membership-levels-form">
                <input type="hidden" name="action" value="membership_save_levels" />
                <?php wp_nonce_field( 'membership_save_levels' ); ?>
                <div class="membership-table-wrap">
                    <table class="widefat striped membership-levels-table">
                        <thead><tr><th><?php esc_html_e( 'Active', 'membership' ); ?></th><th><?php esc_html_e( 'Level key', 'membership' ); ?></th><th><?php esc_html_e( 'Display name', 'membership' ); ?></th><th><?php esc_html_e( 'Global pricing', 'membership' ); ?></th><th><?php esc_html_e( 'Amount', 'membership' ); ?></th><th><?php esc_html_e( 'WordPress role', 'membership' ); ?></th><th><?php esc_html_e( 'Description', 'membership' ); ?></th><th><?php esc_html_e( 'Actions', 'membership' ); ?></th></tr></thead>
                        <tbody data-membership-level-rows>
                            <?php if ( empty( $levels ) ) : ?><tr class="membership-empty-row"><td colspan="8"><?php esc_html_e( 'No levels yet. Click Add level to create one.', 'membership' ); ?></td></tr><?php endif; ?>
                            <?php $index = 0; foreach ( $levels as $level ) : ?>
                                <?php $this->level_row( $level, $types, (string) $index ); $index++; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="membership-form-actions">
                    <button type="button" class="button" data-membership-add-level><?php esc_html_e( 'Add level', 'membership' ); ?></button>
                    <button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save levels and synchronize roles', 'membership' ); ?></button>
                </div>
            </form>
        </section>
        <section class="membership-card">
            <h2><?php esc_html_e( 'How roles work', 'membership' ); ?></h2>
            <div class="membership-info-grid">
                <div><strong><?php esc_html_e( 'Create', 'membership' ); ?></strong><p><?php esc_html_e( 'Every active level creates a role named Member - Level Name.', 'membership' ); ?></p></div>
                <div><strong><?php esc_html_e( 'Remove', 'membership' ); ?></strong><p><?php esc_html_e( 'Removing or disabling a level removes that plugin-managed role when you save.', 'membership' ); ?></p></div>
                <div><strong><?php esc_html_e( 'Assign', 'membership' ); ?></strong><p><?php esc_html_e( 'Use the normal WordPress Role dropdown or the Membership field on the user screen.', 'membership' ); ?></p></div>
            </div>
        </section>
        <script type="text/template" id="membership-level-row-template">
            <?php $this->level_row( array( 'key' => '', 'name' => '', 'pricing_type' => 'percent', 'amount' => 0, 'description' => '', 'enabled' => 1 ), $types, '__index__' ); ?>
        </script>
        <?php
        $this->footer();
    }

    /**
     * Members page.
     *
     * @return void
     */
    public function render_members(): void {
        $this->assert_cap( 'list_users' );
        $users = $this->member_users();
        $this->header( __( 'Members', 'membership' ), 'membership-members' );
        ?>
        <section class="membership-card">
            <div class="membership-card-header membership-card-header-split"><div><h2><?php esc_html_e( 'Membership users', 'membership' ); ?></h2><p><?php esc_html_e( 'Users assigned to membership levels or roles.', 'membership' ); ?></p></div><a class="button button-primary" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'Add new member', 'membership' ); ?></a></div>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'User', 'membership' ); ?></th><th><?php esc_html_e( 'Email', 'membership' ); ?></th><th><?php esc_html_e( 'Membership', 'membership' ); ?></th><th><?php esc_html_e( 'Roles', 'membership' ); ?></th><th><?php esc_html_e( 'Actions', 'membership' ); ?></th></tr></thead><tbody>
                <?php if ( empty( $users ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No member users found yet.', 'membership' ); ?></td></tr><?php endif; ?>
                <?php foreach ( $users as $user ) : ?>
                    <?php $key = $this->roles->get_user_level_key( (int) $user->ID ); $level = $key ? $this->settings->get_level( $key ) : null; ?>
                    <tr><td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><code>#<?php echo esc_html( (string) $user->ID ); ?></code></td><td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td><td><?php echo $level ? esc_html( (string) $level['name'] ) : '&mdash;'; ?></td><td><?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?></td><td><a class="button" href="<?php echo esc_url( get_edit_user_link( (int) $user->ID ) ); ?>"><?php esc_html_e( 'Edit user', 'membership' ); ?></a></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </section>
        <?php
        $this->footer();
    }

    /**
     * Settings page.
     *
     * @return void
     */
    public function render_settings(): void {
        $this->assert_cap( 'manage_options' );
        $settings = $this->settings->get_settings();
        $login_url = LoginGate::instance( $this->settings )->get_login_url();
        $this->header( __( 'Settings', 'membership' ), 'membership-settings' );
        ?>
        <div class="membership-grid membership-grid-2">
            <section class="membership-card">
                <h2><?php esc_html_e( 'Login Gate', 'membership' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="membership_save_settings" />
                    <?php wp_nonce_field( 'membership_save_settings' ); ?>
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e( 'Require login before entering site', 'membership' ); ?></th><td><label><input type="checkbox" name="membership_settings[login_gate_enabled]" value="1" <?php checked( 1, (int) $settings['login_gate_enabled'] ); ?> /> <?php esc_html_e( 'Enabled', 'membership' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Allow public homepage', 'membership' ); ?></th><td><label><input type="checkbox" name="membership_settings[allow_homepage]" value="1" <?php checked( 1, (int) $settings['allow_homepage'] ); ?> /> <?php esc_html_e( 'Visitors can see homepage without logging in', 'membership' ); ?></label></td></tr>
                        <tr><th><label for="membership_login_page_id"><?php esc_html_e( 'Login page', 'membership' ); ?></label></th><td><?php wp_dropdown_pages( array( 'name' => 'membership_settings[login_page_id]', 'id' => 'membership_login_page_id', 'selected' => absint( $settings['login_page_id'] ), 'show_option_none' => __( 'Use WordPress login', 'membership' ), 'option_none_value' => 0 ) ); ?><p class="description"><?php echo wp_kses_post( sprintf( __( 'Place %s on this page.', 'membership' ), '<code>[membership_login_form]</code>' ) ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'After login', 'membership' ); ?></th><td><select name="membership_settings[after_login]"><option value="requested" <?php selected( $settings['after_login'], 'requested' ); ?>><?php esc_html_e( 'Requested protected page', 'membership' ); ?></option><option value="home" <?php selected( $settings['after_login'], 'home' ); ?>><?php esc_html_e( 'Homepage', 'membership' ); ?></option><option value="shop" <?php selected( $settings['after_login'], 'shop' ); ?>><?php esc_html_e( 'WooCommerce shop', 'membership' ); ?></option><option value="account" <?php selected( $settings['after_login'], 'account' ); ?>><?php esc_html_e( 'WooCommerce My Account', 'membership' ); ?></option><option value="custom" <?php selected( $settings['after_login'], 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'membership' ); ?></option></select> <input class="regular-text" type="url" name="membership_settings[custom_redirect]" value="<?php echo esc_attr( (string) $settings['custom_redirect'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" /></td></tr>
                        <tr><th><?php esc_html_e( 'Public paths', 'membership' ); ?></th><td><textarea class="large-text code" rows="5" name="membership_settings[public_paths]" placeholder="/privacy-policy/&#10;/public/*"><?php echo esc_textarea( (string) $settings['public_paths'] ); ?></textarea><p class="description"><?php esc_html_e( 'One path per line. Add * at the end for a wildcard prefix.', 'membership' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'REST API', 'membership' ); ?></th><td><label><input type="checkbox" name="membership_settings[protect_rest]" value="1" <?php checked( 1, (int) $settings['protect_rest'] ); ?> /> <?php esc_html_e( 'Also protect frontend REST requests', 'membership' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Member price notice', 'membership' ); ?></th><td><label><input type="checkbox" name="membership_settings[show_member_price_notice]" value="1" <?php checked( 1, (int) $settings['show_member_price_notice'] ); ?> /> <?php esc_html_e( 'Show notice when member price is active', 'membership' ); ?></label></td></tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'membership' ); ?></button></p>
                </form>
            </section>
            <section class="membership-card">
                <h2><?php esc_html_e( 'Login page status', 'membership' ); ?></h2>
                <p><?php esc_html_e( 'Use this frontend login entry page for members.', 'membership' ); ?></p>
                <div class="membership-login-preview"><code><?php echo esc_html( $login_url ); ?></code><a class="button" href="<?php echo esc_url( $login_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open login page', 'membership' ); ?></a></div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="membership-inline-form"><input type="hidden" name="action" value="membership_create_login_page" /><?php wp_nonce_field( 'membership_create_login_page' ); ?><button type="submit" class="button"><?php esc_html_e( 'Create or repair login page', 'membership' ); ?></button></form>
                <h3><?php esc_html_e( 'Shortcode', 'membership' ); ?></h3><pre class="membership-code">[membership_login_form]</pre>
            </section>
        </div>
        <?php
        $this->footer();
    }

    /**
     * Save levels.
     *
     * @return void
     */
    public function save_levels(): void {
        $this->assert_cap( 'manage_options' );
        check_admin_referer( 'membership_save_levels' );
        $raw = isset( $_POST['levels'] ) && is_array( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : array();
        $levels = $this->settings->update_levels( $raw );
        $roles  = $this->roles->sync_roles( $levels, true );
        $this->notice( 'success', sprintf( __( 'Membership levels saved. %1$d levels and %2$d WordPress roles are synchronized.', 'membership' ), count( $levels ), count( $roles ) ) );
        wp_safe_redirect( $this->page_url( 'membership-levels' ) );
        exit;
    }

    /**
     * Save settings.
     *
     * @return void
     */
    public function save_settings(): void {
        $this->assert_cap( 'manage_options' );
        check_admin_referer( 'membership_save_settings' );
        $raw = isset( $_POST['membership_settings'] ) && is_array( $_POST['membership_settings'] ) ? wp_unslash( $_POST['membership_settings'] ) : array();
        $this->settings->update_settings( $raw );
        $this->roles->sync_roles( null, true );
        $this->notice( 'success', __( 'Membership settings saved.', 'membership' ) );
        wp_safe_redirect( $this->page_url( 'membership-settings' ) );
        exit;
    }

    /**
     * Manual sync.
     *
     * @return void
     */
    public function manual_sync_roles(): void {
        $this->assert_cap( 'manage_options' );
        check_admin_referer( 'membership_sync_roles' );
        $roles = $this->roles->sync_roles( null, true );
        $this->notice( 'success', sprintf( __( '%d membership roles synchronized.', 'membership' ), count( $roles ) ) );
        wp_safe_redirect( $this->page_url( 'membership-levels' ) );
        exit;
    }

    /**
     * Create login page action.
     *
     * @return void
     */
    public function create_login_page(): void {
        $this->assert_cap( 'manage_options' );
        check_admin_referer( 'membership_create_login_page' );

        $page_id  = Activator::create_login_page();
        $settings = $this->settings->get_settings();
        $settings['login_page_id'] = absint( $page_id );
        $this->settings->update_settings( $settings );

        if ( $page_id ) {
            $this->notice( 'success', __( 'Membership login page created or repaired.', 'membership' ) );
        } else {
            $this->notice( 'error', __( 'The login page could not be created. Please create a page manually and add [membership_login_form].', 'membership' ) );
        }

        wp_safe_redirect( $this->page_url( 'membership-settings' ) );
        exit;
    }

    /**
     * Admin notices.
     *
     * @return void
     */
    public function admin_notices(): void {
        $notices = get_transient( 'membership_admin_notices' );
        if ( ! is_array( $notices ) || empty( $notices ) ) {
            return;
        }
        delete_transient( 'membership_admin_notices' );
        foreach ( $notices as $notice ) {
            $type = isset( $notice['type'] ) ? sanitize_html_class( (string) $notice['type'] ) : 'info';
            $message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
            printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), wp_kses_post( $message ) );
        }
    }

    /**
     * Header.
     *
     * @param string $title Title.
     * @param string $active Active slug.
     * @return void
     */
    private function header( string $title, string $active ): void {
        $tabs = array( 'membership' => __( 'Dashboard', 'membership' ), 'membership-levels' => __( 'Levels & Roles', 'membership' ), 'membership-members' => __( 'Members', 'membership' ), 'membership-settings' => __( 'Settings', 'membership' ) );
        ?>
        <div class="wrap membership-admin-wrap">
            <div class="membership-hero"><div><p class="membership-eyebrow"><?php esc_html_e( 'Membership plugin', 'membership' ); ?></p><h1><?php echo esc_html( $title ); ?></h1><p><?php esc_html_e( 'Login protection, member roles, and WooCommerce pricing in one plugin.', 'membership' ); ?></p></div><div class="membership-version">v<?php echo esc_html( MEMBERSHIP_VERSION ); ?></div></div>
            <nav class="membership-tabs" aria-label="<?php esc_attr_e( 'Membership sections', 'membership' ); ?>">
                <?php foreach ( $tabs as $slug => $label ) : ?><a class="<?php echo esc_attr( $active === $slug ? 'is-active' : '' ); ?>" href="<?php echo esc_url( $this->page_url( $slug ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
            </nav>
        <?php
    }

    /**
     * Footer.
     *
     * @return void
     */
    private function footer(): void { echo '</div>'; }

    /**
     * Stat card.
     *
     * @param string $label Label.
     * @param string $value Value.
     * @param string $description Description.
     * @return void
     */
    private function stat_card( string $label, string $value, string $description ): void {
        ?><section class="membership-card membership-stat-card"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong><p><?php echo esc_html( $description ); ?></p></section><?php
    }

    /**
     * Row renderer.
     *
     * @param array<string,mixed> $level Level.
     * @param array<string,string> $types Types.
     * @param string $index Field index.
     * @return void
     */
    private function level_row( array $level, array $types, string $index ): void {
        $key = isset( $level['key'] ) ? (string) $level['key'] : '';
        $role = $key ? $this->roles->role_name( $key ) : 'membership_';
        ?>
        <tr class="membership-level-row">
            <td><label class="membership-switch"><input type="checkbox" name="levels[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $level['enabled'] ) ); ?> /><span></span></label></td>
            <td><input class="regular-text membership-level-key" type="text" name="levels[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $key ); ?>" placeholder="partner" /><p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores.', 'membership' ); ?></p></td>
            <td><input class="regular-text" type="text" name="levels[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $level['name'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Partner', 'membership' ); ?>" /></td>
            <td><select name="levels[<?php echo esc_attr( $index ); ?>][pricing_type]"><?php foreach ( $types as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $level['pricing_type'] ?? 'none' ), $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
            <td><input class="small-text" type="number" step="0.0001" min="0" name="levels[<?php echo esc_attr( $index ); ?>][amount]" value="<?php echo esc_attr( (string) ( $level['amount'] ?? '' ) ); ?>" /></td>
            <td><code class="membership-role-preview"><?php echo esc_html( $role ); ?></code></td>
            <td><input class="regular-text" type="text" name="levels[<?php echo esc_attr( $index ); ?>][description]" value="<?php echo esc_attr( (string) ( $level['description'] ?? '' ) ); ?>" /></td>
            <td><button type="button" class="button button-link-delete" data-membership-remove-row><?php esc_html_e( 'Remove', 'membership' ); ?></button></td>
        </tr>
        <?php
    }

    /**
     * Page URL.
     *
     * @param string $page Page slug.
     * @return string
     */
    private function page_url( string $page ): string { return admin_url( 'admin.php?page=' . sanitize_key( $page ) ); }

    /**
     * Add notice.
     *
     * @param string $type Type.
     * @param string $message Message.
     * @return void
     */
    private function notice( string $type, string $message ): void {
        $notices = get_transient( 'membership_admin_notices' );
        $notices = is_array( $notices ) ? $notices : array();
        $notices[] = array( 'type' => sanitize_key( $type ), 'message' => wp_kses_post( $message ) );
        set_transient( 'membership_admin_notices', $notices, 60 );
    }

    /**
     * Member count.
     *
     * @return int
     */
    private function count_members(): int { return count( $this->member_users() ); }

    /**
     * Member users.
     *
     * @return array<int,\WP_User>
     */
    private function member_users(): array {
        $users = get_users( array( 'number' => 300, 'orderby' => 'registered', 'order' => 'DESC' ) );
        $members = array();
        foreach ( $users as $user ) {
            if ( $user instanceof \WP_User && $this->roles->get_user_level_key( (int) $user->ID ) ) {
                $members[] = $user;
            }
        }
        return $members;
    }

    /**
     * Assert capability.
     *
     * @param string $cap Capability.
     * @return void
     */
    private function assert_cap( string $cap ): void {
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'membership' ) );
        }
    }
}
