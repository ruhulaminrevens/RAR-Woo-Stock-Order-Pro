<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 80 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_rar_wso_add_staff', array( $this, 'add_staff' ) );
        add_action( 'admin_post_rar_wso_staff_action', array( $this, 'staff_action' ) );
        // Flush once after activation/upgrade/slug change, after the /staff/ rule is registered on init.
        add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
    }

    public function menu() {
        add_submenu_page(
            'woocommerce',
            __( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ),
            __( 'Stock & Order', 'rar-woo-stock-order' ),
            'manage_woocommerce',
            'rar-wso',
            array( $this, 'render' )
        );
    }

    public function register_settings() {
        register_setting( 'rar_wso_group', 'rar_wso_settings', array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $old   = RAR_WSO_Plugin::settings();
        $out   = RAR_WSO_Plugin::defaults();
        $out['enabled']              = ! empty( $input['enabled'] ) ? 'yes' : 'no';
        $out['staff_slug']           = sanitize_title( isset( $input['staff_slug'] ) ? $input['staff_slug'] : 'staff' );
        $out['dashboard_title']      = sanitize_text_field( isset( $input['dashboard_title'] ) ? $input['dashboard_title'] : 'Woo Stock & Order' );
        $out['default_order_status'] = RAR_WSO_Ajax::order_status_setting( isset( $input['default_order_status'] ) ? $input['default_order_status'] : 'processing' );
        $out['allow_price_override'] = ! empty( $input['allow_price_override'] ) ? 'yes' : 'no';
        $out['staff_max_discount']   = (string) min( 100, max( 0, (float) ( isset( $input['staff_max_discount'] ) ? $input['staff_max_discount'] : 20 ) ) );
        $out['default_shipping']     = wc_format_decimal( isset( $input['default_shipping'] ) ? $input['default_shipping'] : '0' );
        $out['shipping_dhaka']       = wc_format_decimal( isset( $input['shipping_dhaka'] ) ? $input['shipping_dhaka'] : '' );
        $out['shipping_outside']     = wc_format_decimal( isset( $input['shipping_outside'] ) ? $input['shipping_outside'] : '' );
        $out['low_stock_threshold']  = (string) max( 1, absint( isset( $input['low_stock_threshold'] ) ? $input['low_stock_threshold'] : 10 ) );
        $out['staff_view_orders']    = ! empty( $input['staff_view_orders'] ) ? 'yes' : 'no';
        $out['business_name']        = sanitize_text_field( isset( $input['business_name'] ) ? $input['business_name'] : '' );
        $out['slip_footer']          = sanitize_text_field( isset( $input['slip_footer'] ) ? $input['slip_footer'] : '' );
        // Only an Administrator may let Shop Managers add staff accounts.
        $out['managers_add_staff']   = current_user_can( 'promote_users' ) ? ( ! empty( $input['managers_add_staff'] ) ? 'yes' : 'no' ) : $old['managers_add_staff'];

        if ( empty( $out['staff_slug'] ) ) {
            $out['staff_slug'] = 'staff';
        }
        if ( $old['staff_slug'] !== $out['staff_slug'] ) {
            update_option( 'rar_wso_flush_rewrite', 1, true );
        }
        return $out;
    }

    public function maybe_flush_rewrite() {
        if ( get_option( 'rar_wso_flush_rewrite' ) ) {
            // Keep the option (value 0, autoloaded) so later page loads don't query for a missing option.
            update_option( 'rar_wso_flush_rewrite', 0, true );
            flush_rewrite_rules( false );
        }
    }

    /* --------------------------------------------------------------------
     * Staff accounts (created here by an Administrator — no public registration)
     * ------------------------------------------------------------------ */

    private function back( $args ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=rar-wso' ) ) . '#rar-staff' );
        exit;
    }

    private function flash( $type, $text, $link = '' ) {
        set_transient( 'rar_wso_flash_' . get_current_user_id(), array( 'type' => $type, 'text' => $text, 'link' => $link ), 10 * MINUTE_IN_SECONDS );
    }

    /** One-time "set your password" link (valid for 24 hours), also emailed to the staff member. */
    private function password_link( WP_User $user, $send_mail ) {
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            return '';
        }
        $link = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
        if ( $send_mail && $user->user_email ) {
            $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            wp_mail(
                $user->user_email,
                /* translators: %s site name */
                sprintf( __( '[%s] Your staff app account', 'rar-woo-stock-order' ), $site ),
                sprintf(
                    /* translators: 1: name, 2: username, 3: link, 4: staff app URL */
                    __( "Hello %1\$s,\n\nA staff account was created for you.\nUsername: %2\$s\n\nSet your password (link works for 24 hours):\n%3\$s\n\nThen open the staff app on your phone:\n%4\$s\n", 'rar-woo-stock-order' ),
                    $user->display_name,
                    $user->user_login,
                    $link,
                    RAR_WSO_Plugin::staff_url()
                )
            );
        }
        return $link;
    }

    public function add_staff() {
        if ( ! RAR_WSO_Plugin::can_manage_staff() ) {
            wp_die( esc_html__( 'You do not have permission to add staff accounts.', 'rar-woo-stock-order' ), 403 );
        }
        check_admin_referer( 'rar_wso_add_staff' );

        $login = sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ), true );
        $email = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
        $name  = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );

        if ( '' === $login || strlen( $login ) < 3 ) {
            $this->flash( 'error', __( 'Enter a username of at least 3 letters or numbers.', 'rar-woo-stock-order' ) );
            $this->back( array() );
        }
        if ( ! is_email( $email ) ) {
            $this->flash( 'error', __( 'Enter a valid email address for the staff member.', 'rar-woo-stock-order' ) );
            $this->back( array() );
        }
        if ( username_exists( $login ) || email_exists( $email ) ) {
            $this->flash( 'error', __( 'That username or email already has an account. Use a different one, or change the existing user\'s role under Users.', 'rar-woo-stock-order' ) );
            $this->back( array() );
        }

        RAR_WSO_Security::$creating_staff = true;
        $user_id = wp_insert_user(
            array(
                'user_login'   => $login,
                'user_email'   => $email,
                'display_name' => '' !== $name ? $name : $login,
                'first_name'   => $name,
                'user_pass'    => wp_generate_password( 32, true, true ),
                'role'         => 'rar_wso_staff',
            )
        );
        RAR_WSO_Security::$creating_staff = false;

        if ( is_wp_error( $user_id ) ) {
            $this->flash( 'error', $user_id->get_error_message() );
            $this->back( array() );
        }

        update_user_meta( $user_id, 'rar_wso_added_by', get_current_user_id() );
        $link = $this->password_link( get_userdata( $user_id ), true );
        $this->flash(
            'success',
            /* translators: %s username */
            sprintf( __( 'Staff account "%s" created. A set-password email was sent. You can also send this one-time link on WhatsApp (valid 24 hours, share only with this person):', 'rar-woo-stock-order' ), $login ),
            $link
        );
        $this->back( array() );
    }

    public function staff_action() {
        if ( ! RAR_WSO_Plugin::can_manage_staff() ) {
            wp_die( esc_html__( 'You do not have permission to manage staff accounts.', 'rar-woo-stock-order' ), 403 );
        }
        $user_id = absint( $_POST['user_id'] ?? 0 );
        $do      = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
        check_admin_referer( 'rar_wso_staff_' . $user_id );

        $user = get_userdata( $user_id );
        // Only plain staff accounts can be managed here — never admins or shop managers.
        if ( ! $user || array_values( (array) $user->roles ) !== array( 'rar_wso_staff' ) || get_current_user_id() === $user_id ) {
            $this->flash( 'error', __( 'Only Woo Stock & Order Staff accounts can be managed here.', 'rar-woo-stock-order' ) );
            $this->back( array() );
        }

        switch ( $do ) {
            case 'pause':
                update_user_meta( $user_id, 'rar_wso_suspended', '1' );
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                /* translators: %s name */
                $this->flash( 'success', sprintf( __( '%s is paused and signed out on every device.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'resume':
                delete_user_meta( $user_id, 'rar_wso_suspended' );
                /* translators: %s name */
                $this->flash( 'success', sprintf( __( '%s can sign in again.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'signout':
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                /* translators: %s name */
                $this->flash( 'success', sprintf( __( '%s was signed out on every phone and computer.', 'rar-woo-stock-order' ), $user->display_name ) );
                break;
            case 'link':
                $link = $this->password_link( $user, true );
                /* translators: %s name */
                $this->flash( 'success', sprintf( __( 'New set-password link for %s (emailed too; valid 24 hours, share only with this person):', 'rar-woo-stock-order' ), $user->display_name ), $link );
                break;
        }
        $this->back( array() );
    }

    private function staff_rows() {
        return get_users(
            array(
                'role__in' => array( 'rar_wso_staff', 'shop_manager' ),
                'orderby'  => 'display_name',
                'number'   => 200,
            )
        );
    }

    private function ago( $ts ) {
        if ( ! $ts ) {
            return '—';
        }
        /* translators: %s human time difference */
        return sprintf( __( '%s ago', 'rar-woo-stock-order' ), human_time_diff( (int) $ts, time() ) );
    }

    private function render_security() {
        $r        = RAR_WSO_Security::registration_status();
        $blocked  = get_transient( 'rar_wso_role_blocked' );
        $risky    = in_array( $r['stored_role'], RAR_WSO_Security::protected_roles(), true );
        $ok       = '<span style="color:#1e8a45;font-weight:600">&#10003; ';
        $warn     = '<span style="color:#b32d2e;font-weight:600">&#9888; ';
        $end      = '</span>';
        $settings = admin_url( 'options-general.php' );
        $wc_acc   = admin_url( 'admin.php?page=wc-settings&tab=account' );
        ?>
        <div class="rar-card">
            <h2><?php esc_html_e( 'Registration & login safety', 'rar-woo-stock-order' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Staff accounts are never created by public sign-up. Anyone who registers on the website gets the Customer role only; this plugin blocks the staff, Shop Manager and Administrator roles from being handed out by registration.', 'rar-woo-stock-order' ); ?></p>
            <?php if ( $blocked ) : ?>
                <div class="notice notice-warning inline"><p><?php
                /* translators: %s role */
                echo esc_html( sprintf( __( 'Blocked: someone tried to make "%s" the default role for new sign-ups. It was kept as Customer.', 'rar-woo-stock-order' ), $blocked ) );
                ?></p></div>
            <?php endif; ?>
            <table class="widefat striped rar-kv">
                <tbody>
                    <tr><th><?php esc_html_e( 'Staff accounts from public sign-up', 'rar-woo-stock-order' ); ?></th><td><?php echo $ok . esc_html__( 'Blocked (staff are added in "Staff accounts" above by an Administrator)', 'rar-woo-stock-order' ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
                    <tr><th><?php esc_html_e( 'Default role for new sign-ups', 'rar-woo-stock-order' ); ?></th><td><?php
                    if ( $risky ) {
                        /* translators: %s role */
                        echo $warn . esc_html( sprintf( __( 'Stored as "%s" — overridden to Customer by this plugin. Fix it in Settings → General → New User Default Role.', 'rar-woo-stock-order' ), $r['stored_role'] ) ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    } else {
                        echo $ok . esc_html( $r['default_role'] ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?></td></tr>
                    <tr><th><?php esc_html_e( 'WordPress "Anyone can register"', 'rar-woo-stock-order' ); ?></th><td><?php echo $r['wp_open'] ? $warn . esc_html__( 'On — not needed for a WooCommerce shop. Turn it off in Settings → General.', 'rar-woo-stock-order' ) . $end : $ok . esc_html__( 'Off', 'rar-woo-stock-order' ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Open', 'rar-woo-stock-order' ); ?></a></td></tr>
                    <tr><th><?php esc_html_e( 'Customer sign-up on My Account page', 'rar-woo-stock-order' ); ?></th><td><?php echo $r['wc_account'] ? esc_html__( 'On (customers only). If bots keep registering, turn it off — customers can still create an account at checkout.', 'rar-woo-stock-order' ) : $ok . esc_html__( 'Off', 'rar-woo-stock-order' ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="<?php echo esc_url( $wc_acc ); ?>"><?php esc_html_e( 'Open', 'rar-woo-stock-order' ); ?></a></td></tr>
                    <tr><th><?php esc_html_e( 'Staff login page protection', 'rar-woo-stock-order' ); ?></th><td><?php
                    echo $ok . esc_html(
                        sprintf(
                            /* translators: 1: attempts, 2: attempts, 3: total failures */
                            __( 'On — %1$d wrong passwords per username or %2$d per network in 15 minutes locks the form for 15 minutes. Failed attempts so far: %3$d.', 'rar-woo-stock-order' ),
                            RAR_WSO_Security::USER_LIMIT,
                            RAR_WSO_Security::IP_LIMIT,
                            $r['failures']
                        )
                    ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?><br><span class="description"><?php esc_html_e( 'This covers the /staff/ login form. Protect wp-login.php and XML-RPC with a site-wide security plugin too.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><?php esc_html_e( 'HTTPS', 'rar-woo-stock-order' ); ?></th><td><?php echo 0 === strpos( RAR_WSO_Plugin::staff_url(), 'https://' ) ? $ok . esc_html__( 'Yes', 'rar-woo-stock-order' ) . $end : $warn . esc_html__( 'No — staff passwords travel unencrypted. Enable SSL.', 'rar-woo-stock-order' ) . $end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_staff() {
        $can   = RAR_WSO_Plugin::can_manage_staff();
        $flash = get_transient( 'rar_wso_flash_' . get_current_user_id() );
        if ( $flash ) {
            delete_transient( 'rar_wso_flash_' . get_current_user_id() );
        }
        ?>
        <div class="rar-card" id="rar-staff">
            <h2><?php esc_html_e( 'Staff accounts', 'rar-woo-stock-order' ); ?></h2>
            <?php if ( is_array( $flash ) ) : ?>
                <div class="notice notice-<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?> inline"><p><?php echo esc_html( $flash['text'] ); ?></p>
                <?php if ( ! empty( $flash['link'] ) ) : ?>
                    <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $flash['link'] ); ?>" onclick="this.select()"></p>
                <?php endif; ?></div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Name', 'rar-woo-stock-order' ); ?></th><th><?php esc_html_e( 'Username / email', 'rar-woo-stock-order' ); ?></th><th><?php esc_html_e( 'Role', 'rar-woo-stock-order' ); ?></th><th><?php esc_html_e( 'Last active in app', 'rar-woo-stock-order' ); ?></th><th><?php esc_html_e( 'Status', 'rar-woo-stock-order' ); ?></th><th><?php esc_html_e( 'Actions', 'rar-woo-stock-order' ); ?></th></tr></thead>
                <tbody>
                <?php
                $rows = $this->staff_rows();
                if ( ! $rows ) :
                    ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No staff or Shop Manager accounts yet.', 'rar-woo-stock-order' ); ?></td></tr>
                    <?php
                endif;
                foreach ( $rows as $u ) :
                    $is_staff = array_values( (array) $u->roles ) === array( 'rar_wso_staff' );
                    $paused   = RAR_WSO_Security::is_paused( $u->ID );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $u->display_name ); ?></strong></td>
                        <td><?php echo esc_html( $u->user_login ); ?><br><span class="description"><?php echo esc_html( $u->user_email ); ?></span></td>
                        <td><?php echo $is_staff ? esc_html__( 'Staff', 'rar-woo-stock-order' ) : esc_html__( 'Shop Manager', 'rar-woo-stock-order' ); ?></td>
                        <td><?php echo esc_html( $this->ago( get_user_meta( $u->ID, 'rar_wso_last_seen', true ) ) ); ?></td>
                        <td><?php echo $paused ? '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'Paused', 'rar-woo-stock-order' ) . '</span>' : esc_html__( 'Active', 'rar-woo-stock-order' ); ?></td>
                        <td>
                        <?php if ( $can && $is_staff && get_current_user_id() !== $u->ID ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rar-inline">
                                <input type="hidden" name="action" value="rar_wso_staff_action">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>">
                                <?php wp_nonce_field( 'rar_wso_staff_' . $u->ID ); ?>
                                <?php if ( $paused ) : ?>
                                    <button class="button" name="do" value="resume"><?php esc_html_e( 'Resume', 'rar-woo-stock-order' ); ?></button>
                                <?php else : ?>
                                    <button class="button" name="do" value="pause" onclick="return confirm('<?php echo esc_js( __( 'Pause this account and sign it out everywhere?', 'rar-woo-stock-order' ) ); ?>')"><?php esc_html_e( 'Pause', 'rar-woo-stock-order' ); ?></button>
                                <?php endif; ?>
                                <button class="button" name="do" value="signout"><?php esc_html_e( 'Sign out everywhere', 'rar-woo-stock-order' ); ?></button>
                                <button class="button" name="do" value="link"><?php esc_html_e( 'New password link', 'rar-woo-stock-order' ); ?></button>
                            </form>
                        <?php elseif ( ! $is_staff ) : ?>
                            <span class="description"><?php esc_html_e( 'Managed under Users', 'rar-woo-stock-order' ); ?></span>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $can ) : ?>
                <h3><?php esc_html_e( 'Add a staff account', 'rar-woo-stock-order' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Creates a Woo Stock & Order Staff login (stock updates + order creation only, no wp-admin). The person gets an email to set their own password; you never see or send a password.', 'rar-woo-stock-order' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rar-add">
                    <input type="hidden" name="action" value="rar_wso_add_staff">
                    <?php wp_nonce_field( 'rar_wso_add_staff' ); ?>
                    <label><?php esc_html_e( 'Full name', 'rar-woo-stock-order' ); ?><input type="text" name="display_name" required autocomplete="off"></label>
                    <label><?php esc_html_e( 'Username', 'rar-woo-stock-order' ); ?><input type="text" name="user_login" required minlength="3" autocomplete="off" autocapitalize="none"></label>
                    <label><?php esc_html_e( 'Email', 'rar-woo-stock-order' ); ?><input type="email" name="user_email" required autocomplete="off"></label>
                    <button class="button button-primary"><?php esc_html_e( 'Create staff account', 'rar-woo-stock-order' ); ?></button>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Only an Administrator can add or pause staff accounts (an Administrator can allow Shop Managers to do it in the settings above).', 'rar-woo-stock-order' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rar-woo-stock-order' ) );
        }

        $s        = RAR_WSO_Plugin::settings();
        $statuses = wc_get_order_statuses();
        ?>
        <style>
            .rar-wrap{max-width:1100px}
            .rar-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0}
            .rar-card h2{margin-top:4px}
            .rar-hero{border-left:4px solid #198754}
            .rar-kv th{width:32%;font-weight:600}
            .rar-inline{display:flex;gap:6px;flex-wrap:wrap}
            .rar-add{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
            .rar-add label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .rar-add input{min-width:220px}
            @media (max-width:782px){.rar-add input{min-width:0;width:100%}.rar-add label{flex:1 1 100%}}
        </style>
        <div class="wrap rar-wrap">
            <h1><?php esc_html_e( 'RAR Woo Stock & Order', 'rar-woo-stock-order' ); ?> <span style="font-size:13px;color:#646970">v<?php echo esc_html( RAR_WSO_VERSION ); ?></span></h1>
            <p><?php esc_html_e( 'Mobile-first staff PWA for stock updates and fast WooCommerce order creation.', 'rar-woo-stock-order' ); ?></p>

            <div class="rar-card rar-hero">
                <strong><?php esc_html_e( 'Staff App URL:', 'rar-woo-stock-order' ); ?></strong>
                <a href="<?php echo esc_url( RAR_WSO_Plugin::staff_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( RAR_WSO_Plugin::staff_url() ); ?></a>
                <p style="margin-bottom:0"><?php esc_html_e( 'Open this link on Chrome and use Add to Home Screen for an app-like phone experience.', 'rar-woo-stock-order' ); ?></p>
            </div>

            <form method="post" action="options.php" class="rar-card">
                <h2><?php esc_html_e( 'Settings', 'rar-woo-stock-order' ); ?></h2>
                <?php settings_fields( 'rar_wso_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e( 'Enable staff app', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?>> <?php esc_html_e( 'Enable private staff PWA', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><label for="rar-wso-title"><?php esc_html_e( 'App title', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-title" class="regular-text" type="text" name="rar_wso_settings[dashboard_title]" value="<?php echo esc_attr( $s['dashboard_title'] ); ?>"></td></tr>
                    <tr><th><label for="rar-wso-slug"><?php esc_html_e( 'Staff URL slug', 'rar-woo-stock-order' ); ?></label></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="rar-wso-slug" type="text" name="rar_wso_settings[staff_slug]" value="<?php echo esc_attr( $s['staff_slug'] ); ?>" style="width:180px"><code>/</code></td></tr>
                    <tr><th><?php esc_html_e( 'Default new order status', 'rar-woo-stock-order' ); ?></th><td><select name="rar_wso_settings[default_order_status]"><?php foreach ( $statuses as $key => $label ) : $slug = str_replace( 'wc-', '', $key ); if ( in_array( $slug, array( 'refunded', 'cancelled', 'failed', 'checkout-draft' ), true ) ) { continue; } ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['default_order_status'], $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Order price override', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[allow_price_override]" value="1" <?php checked( $s['allow_price_override'], 'yes' ); ?>> <?php esc_html_e( 'Allow staff to change item price while billing', 'rar-woo-stock-order' ); ?></label><p class="description"><?php esc_html_e( 'A lower rate counts toward the staff discount limit below, together with the discount.', 'rar-woo-stock-order' ); ?></p></td></tr>
                    <tr><th><label for="rar-wso-maxdisc"><?php esc_html_e( 'Staff discount limit', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-maxdisc" type="number" min="0" max="100" step="0.5" name="rar_wso_settings[staff_max_discount]" value="<?php echo esc_attr( $s['staff_max_discount'] ); ?>"> % <span class="description"><?php esc_html_e( 'Largest total reduction (discount + lower item rates) a staff user can give on one order, as a percent of the list-price subtotal. 0 = no reductions. Shop Managers and Administrators are not limited.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-ship-dhaka"><?php esc_html_e( 'Shipping — Inside Dhaka', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-ship-dhaka" type="number" min="0" step="0.01" name="rar_wso_settings[shipping_dhaka]" value="<?php echo esc_attr( $s['shipping_dhaka'] ); ?>"> <span class="description"><?php esc_html_e( 'Filled in automatically when the District is Dhaka. Staff can still change it per order.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-ship-out"><?php esc_html_e( 'Shipping — Outside Dhaka', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-ship-out" type="number" min="0" step="0.01" name="rar_wso_settings[shipping_outside]" value="<?php echo esc_attr( $s['shipping_outside'] ); ?>"> <span class="description"><?php esc_html_e( 'Used for every other district.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-shipping"><?php esc_html_e( 'Default shipping charge', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-shipping" type="number" min="0" step="0.01" name="rar_wso_settings[default_shipping]" value="<?php echo esc_attr( $s['default_shipping'] ); ?>"> <span class="description"><?php esc_html_e( 'Used before a district is chosen, or when the two fields above are empty.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><label for="rar-wso-low"><?php esc_html_e( 'Low stock level', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-low" type="number" min="1" step="1" name="rar_wso_settings[low_stock_threshold]" value="<?php echo esc_attr( $s['low_stock_threshold'] ); ?>"> <span class="description"><?php esc_html_e( 'Quantities from 1 up to this number show orange (low). Above it shows green; 0 shows red.', 'rar-woo-stock-order' ); ?></span></td></tr>
                    <tr><th><?php esc_html_e( 'Staff order lists', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[staff_view_orders]" value="1" <?php checked( $s['staff_view_orders'], 'yes' ); ?>> <?php esc_html_e( 'Let staff open today / 7-day / month order lists (view only). Status changes, All Orders, Live Orders and sales reports stay Shop Manager only.', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><?php esc_html_e( 'Staff accounts', 'rar-woo-stock-order' ); ?></th><td><label><input type="checkbox" name="rar_wso_settings[managers_add_staff]" value="1" <?php checked( $s['managers_add_staff'], 'yes' ); ?> <?php disabled( ! current_user_can( 'promote_users' ) ); ?>> <?php esc_html_e( 'Shop Managers may add, pause and sign out staff accounts (Administrators always can).', 'rar-woo-stock-order' ); ?></label></td></tr>
                    <tr><th><label for="rar-wso-bname"><?php esc_html_e( 'Business name on slip', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-bname" class="regular-text" type="text" name="rar_wso_settings[business_name]" value="<?php echo esc_attr( $s['business_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td></tr>
                    <tr><th><label for="rar-wso-footer"><?php esc_html_e( 'Slip footer line', 'rar-woo-stock-order' ); ?></label></th><td><input id="rar-wso-footer" class="regular-text" type="text" name="rar_wso_settings[slip_footer]" value="<?php echo esc_attr( $s['slip_footer'] ); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php $this->render_staff(); ?>
            <?php $this->render_security(); ?>

            <div class="rar-card">
                <h2><?php esc_html_e( 'Permissions', 'rar-woo-stock-order' ); ?></h2>
                <p><?php esc_html_e( 'Staff can use the private app, update stock and create WooCommerce orders. Item-price override remains optional and counts toward the discount limit.', 'rar-woo-stock-order' ); ?></p>
                <p><strong><?php esc_html_e( 'Shop Manager tools:', 'rar-woo-stock-order' ); ?></strong> <?php esc_html_e( 'Administrator and Shop Manager users also get Order Control (All Orders, Live Orders, Processing with status changes) and the Sales & Growth report inside the staff app.', 'rar-woo-stock-order' ); ?></p>
                <p><strong><?php esc_html_e( 'Catalog management:', 'rar-woo-stock-order' ); ?></strong> <?php esc_html_e( 'Product creation, editing and deletion intentionally stay in WooCommerce → Products for Administrator / Shop Manager users. The staff app does not duplicate those controls.', 'rar-woo-stock-order' ); ?></p>
            </div>
        </div>
        <?php
    }
}
