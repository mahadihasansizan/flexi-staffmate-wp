<?php
/**
 * Membership role synchronization.
 *
 * @package Membership
 */

namespace Membership;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates, updates, removes, and assigns plugin-managed roles.
 */
final class Roles {
    public const ROLE_PREFIX          = 'membership_';
    public const MANAGED_ROLES_OPTION = 'membership_managed_roles';
    public const USER_LEVEL_META      = 'membership_level_key';

    /** @var Roles|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings service.
     */
    private function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Get singleton.
     *
     * @param Settings|null $settings Settings service.
     * @return Roles
     */
    public static function instance( ?Settings $settings = null ): Roles {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings ?: Settings::instance() );
        }
        return self::$instance;
    }

    /**
     * Register role hooks.
     *
     * @return void
     */
    public function hooks(): void {
        add_action( 'init', array( $this, 'ensure_roles' ), 5 );
        add_action( 'set_user_role', array( $this, 'sync_after_set_user_role' ), 10, 3 );
        add_action( 'add_user_role', array( $this, 'sync_after_add_user_role' ), 10, 2 );
        add_action( 'remove_user_role', array( $this, 'sync_after_remove_user_role' ), 10, 2 );
    }

    /**
     * Ensure current roles exist without removing stale roles on every request.
     *
     * @return void
     */
    public function ensure_roles(): void {
        $this->sync_roles( null, false );
    }

    /**
     * Build role slug.
     *
     * @param string $level_key Level key.
     * @return string
     */
    public function role_name( string $level_key ): string {
        return substr( self::ROLE_PREFIX . $this->settings->normalize_key( $level_key ), 0, 64 );
    }

    /**
     * Role display label.
     *
     * @param array<string,mixed> $level Level.
     * @return string
     */
    public function role_label( array $level ): string {
        $name = isset( $level['name'] ) ? (string) $level['name'] : '';
        if ( '' === $name ) {
            $name = ucwords( str_replace( array( '-', '_' ), ' ', (string) ( $level['key'] ?? '' ) ) );
        }

        return sprintf(
            /* translators: %s: membership level display name */
            __( 'Member - %s', 'membership' ),
            $name
        );
    }

    /**
     * Synchronize roles to enabled levels.
     *
     * @param array<string,array<string,mixed>>|null $levels Optional levels.
     * @param bool                                  $remove_stale Remove stale roles.
     * @return array<string,string> Map role slug to display name.
     */
    public function sync_roles( ?array $levels = null, bool $remove_stale = true ): array {
        $levels = null === $levels ? $this->settings->get_levels() : $this->settings->sanitize_levels( $levels );
        $desired = array();

        foreach ( $levels as $key => $level ) {
            if ( empty( $level['enabled'] ) ) {
                continue;
            }
            $desired[ $this->role_name( (string) $key ) ] = $this->role_label( $level );
        }

        $managed = get_option( self::MANAGED_ROLES_OPTION, array() );
        if ( ! is_array( $managed ) ) {
            $managed = array();
        }

        if ( $remove_stale ) {
            foreach ( array_keys( $managed ) as $old_role ) {
                if ( ! isset( $desired[ $old_role ] ) ) {
                    $this->migrate_users_from_removed_role( (string) $old_role );
                    remove_role( (string) $old_role );
                }
            }
        }

        $caps = $this->base_capabilities();
        foreach ( $desired as $role_name => $display_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                add_role( $role_name, $display_name, $caps );
            } else {
                $this->ensure_capabilities( $role_name, $caps );
                $this->rename_role( $role_name, $display_name );
            }
        }

        update_option( self::MANAGED_ROLES_OPTION, $desired, false );
        return $desired;
    }

    /**
     * Base capabilities copied from Customer/Subscriber with unsafe caps removed.
     *
     * @return array<string,bool>
     */
    private function base_capabilities(): array {
        $source = get_role( 'customer' );
        if ( ! $source instanceof \WP_Role ) {
            $source = get_role( 'subscriber' );
        }

        $caps = $source instanceof \WP_Role ? $source->capabilities : array( 'read' => true );
        $caps['read'] = true;

        foreach ( array( 'manage_options', 'edit_users', 'delete_users', 'promote_users', 'activate_plugins', 'edit_plugins', 'delete_plugins', 'edit_themes', 'switch_themes', 'unfiltered_html' ) as $dangerous ) {
            unset( $caps[ $dangerous ] );
        }

        return array_map( 'boolval', $caps );
    }

    /**
     * Ensure capabilities.
     *
     * @param string             $role_name Role slug.
     * @param array<string,bool> $caps Capabilities.
     * @return void
     */
    private function ensure_capabilities( string $role_name, array $caps ): void {
        $role = get_role( $role_name );
        if ( ! $role instanceof \WP_Role ) {
            return;
        }

        foreach ( $caps as $cap => $grant ) {
            if ( $grant ) {
                $role->add_cap( $cap, true );
            }
        }
    }

    /**
     * Rename a role while keeping assignments.
     *
     * @param string $role_name Role slug.
     * @param string $display_name Display name.
     * @return void
     */
    private function rename_role( string $role_name, string $display_name ): void {
        $wp_roles = wp_roles();
        if ( ! isset( $wp_roles->roles[ $role_name ] ) ) {
            return;
        }

        $wp_roles->roles[ $role_name ]['name'] = $display_name;
        $wp_roles->role_names[ $role_name ] = $display_name;
        update_option( $wp_roles->role_key, $wp_roles->roles );
    }

    /**
     * Move users away from removed role.
     *
     * @param string $role_name Removed role.
     * @return void
     */
    private function migrate_users_from_removed_role( string $role_name ): void {
        $users = get_users( array( 'role' => $role_name, 'fields' => 'ID', 'number' => 500 ) );
        $default_role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );

        foreach ( $users as $user_id ) {
            $user = new \WP_User( (int) $user_id );
            $user->remove_role( $role_name );
            if ( empty( $user->roles ) && $default_role ) {
                $user->add_role( $default_role );
            }
            delete_user_meta( (int) $user_id, self::USER_LEVEL_META );
        }
    }

    /**
     * Is role managed by this plugin?
     *
     * @param string $role_name Role slug.
     * @return bool
     */
    public function is_membership_role( string $role_name ): bool {
        return 0 === strpos( $role_name, self::ROLE_PREFIX );
    }

    /**
     * Get level key from role slug.
     *
     * @param string $role_name Role slug.
     * @return string
     */
    public function level_key_from_role( string $role_name ): string {
        if ( ! $this->is_membership_role( $role_name ) ) {
            return '';
        }
        return $this->settings->normalize_key( substr( $role_name, strlen( self::ROLE_PREFIX ) ) );
    }

    /**
     * Get a user's membership level from meta or roles.
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_user_level_key( int $user_id ): string {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return '';
        }

        $levels = $this->settings->get_levels();
        $meta   = $this->settings->normalize_key( (string) get_user_meta( $user_id, self::USER_LEVEL_META, true ) );
        if ( $meta && isset( $levels[ $meta ] ) && ! empty( $levels[ $meta ]['enabled'] ) ) {
            return $meta;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) {
            return '';
        }

        foreach ( (array) $user->roles as $role_name ) {
            $key = $this->level_key_from_role( (string) $role_name );
            if ( $key && isset( $levels[ $key ] ) && ! empty( $levels[ $key ]['enabled'] ) ) {
                update_user_meta( $user_id, self::USER_LEVEL_META, $key );
                return $key;
            }
        }

        return '';
    }

    /**
     * Assign one membership level to a user.
     *
     * @param int    $user_id User ID.
     * @param string $level_key Level key. Empty removes membership role.
     * @return void
     */
    public function assign_level_to_user( int $user_id, string $level_key ): void {
        $user_id   = absint( $user_id );
        $level_key = $this->settings->normalize_key( $level_key );

        if ( ! $user_id ) {
            return;
        }

        $user = new \WP_User( $user_id );
        if ( ! $user->exists() ) {
            return;
        }

        foreach ( (array) $user->roles as $role_name ) {
            if ( $this->is_membership_role( (string) $role_name ) ) {
                $user->remove_role( (string) $role_name );
            }
        }

        $levels = $this->settings->get_levels();
        if ( $level_key && isset( $levels[ $level_key ] ) && ! empty( $levels[ $level_key ]['enabled'] ) ) {
            $role_name = $this->role_name( $level_key );
            if ( ! get_role( $role_name ) ) {
                $this->sync_roles( $levels, false );
            }
            $user->add_role( $role_name );
            update_user_meta( $user_id, self::USER_LEVEL_META, $level_key );
            return;
        }

        delete_user_meta( $user_id, self::USER_LEVEL_META );
    }

    /**
     * Sync meta from user roles.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function sync_user_meta_from_roles( int $user_id ): void {
        $user = get_user_by( 'id', absint( $user_id ) );
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        foreach ( (array) $user->roles as $role_name ) {
            $key = $this->level_key_from_role( (string) $role_name );
            if ( $key ) {
                update_user_meta( (int) $user_id, self::USER_LEVEL_META, $key );
                return;
            }
        }

        delete_user_meta( (int) $user_id, self::USER_LEVEL_META );
    }

    /**
     * Sync after primary role is set.
     *
     * @param int          $user_id User ID.
     * @param string       $role Role.
     * @param array|string $old_roles Old roles.
     * @return void
     */
    public function sync_after_set_user_role( int $user_id, string $role, $old_roles ): void {
        unset( $old_roles );
        if ( $this->is_membership_role( $role ) ) {
            update_user_meta( $user_id, self::USER_LEVEL_META, $this->level_key_from_role( $role ) );
            return;
        }
        $this->sync_user_meta_from_roles( $user_id );
    }

    /**
     * Sync after role added.
     *
     * @param int    $user_id User ID.
     * @param string $role Role.
     * @return void
     */
    public function sync_after_add_user_role( int $user_id, string $role ): void {
        if ( $this->is_membership_role( $role ) ) {
            update_user_meta( $user_id, self::USER_LEVEL_META, $this->level_key_from_role( $role ) );
        }
    }

    /**
     * Sync after role removed.
     *
     * @param int    $user_id User ID.
     * @param string $role Role.
     * @return void
     */
    public function sync_after_remove_user_role( int $user_id, string $role ): void {
        if ( $this->is_membership_role( $role ) ) {
            $this->sync_user_meta_from_roles( $user_id );
        }
    }
}
