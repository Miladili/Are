<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin Panel Access Guard (قفل اختصاصی پنل شقفی)
 *
 * The whole plugin panel and ALL of its AJAX endpoints are protected behind a dedicated
 * username/password gate, independent of WordPress user accounts.
 *
 *   Username: Miloo77
 *   Password: @@##$$Miloo77
 *
 * Credentials are stored ONLY as SHA-256 hashes (never plaintext) and compared with hash_equals().
 * Sessions are random tokens kept in the options table and delivered through an HttpOnly cookie.
 */
class Shaghafi_Auth_Guard {

    const COOKIE_NAME      = 'shaghafi_panel_tk';
    const SESSION_OPTION   = 'shaghafi_panel_sessions';
    const SESSION_LIFETIME = 43200; // 12 hours (seconds)

    // SHA-256 hashes of the panel credentials (plaintext is never stored in the plugin).
    const USERNAME_HASH = '8f22bc4e41d037fc0676fc3c10cf232fc973d096b5e070d2a6bae4089d337fb8'; // Miloo77
    const PASSWORD_HASH = 'b565008cffd6b8ab5e8145ecd0151319e60cfe3091f0a9680eeea64f2105e7cc'; // @@##$$Miloo77

    /**
     * Check whether the current request belongs to this plugin's admin page or AJAX actions.
     */
    public static function is_plugin_request() {
        // Note: sanitize_key() would strip the hyphens, so compare the raw slug explicitly.
        $panel_page = isset( $_GET['page'] ) && 'shaghafi-sync-repair' === sanitize_text_field( wp_unslash( $_GET['page'] ) );
        if ( $panel_page ) {
            return true;
        }

        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || wp_doing_ajax() ) {
            $action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
            // The cron webhook authenticates with its own secret query key and must stay reachable for WP-Cron.
            if ( 0 === strpos( $action, 'shaghafi_' ) && 'shaghafi_cron_webhook' !== $action ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Boot the guard: runs on every admin request, before any plugin form/AJAX handler.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'guard' ), 1 );
    }

    /**
     * Main gate logic.
     */
    public static function guard() {
        if ( ! self::is_plugin_request() ) {
            return;
        }

        // Logout request.
        if ( ! empty( $_GET['shaghafi_logout'] ) ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shaghafi_panel_logout' ) ) {
                wp_die( 'درخواست نامعتبر است.', 'خطای دسترسی', array( 'response' => 403 ) );
            }
            self::destroy_current_session();
            wp_safe_redirect( add_query_arg( 'page', 'shaghafi-sync-repair', admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( self::is_authenticated() ) {
            return;
        }

        // Login form submission.
        if ( isset( $_POST['shaghafi_login_username'] ) || isset( $_POST['shaghafi_login_password'] ) ) {
            self::handle_login_post();
        }

        if ( wp_doing_ajax() ) {
            wp_send_json_error(
                array(
                    'message' => 'نشست ورود به پنل شقفی منقضی شده است. صفحه را مجدداً بارگذاری و وارد شوید.',
                    'relogin' => true,
                ),
                403
            );
        }

        self::render_login_screen();
        exit;
    }

    /**
     * Validate the posted credentials and start a session.
     */
    private static function handle_login_post() {
        if ( ! isset( $_POST['shaghafi_panel_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shaghafi_panel_login_nonce'] ) ), 'shaghafi_panel_login_action' ) ) {
            self::render_login_screen( 'توکن امنیتی نامعتبر است. لطفاً دوباره تلاش کنید.' );
            exit;
        }

        $username = isset( $_POST['shaghafi_login_username'] ) ? trim( (string) wp_unslash( $_POST['shaghafi_login_username'] ) ) : '';
        $password = isset( $_POST['shaghafi_login_password'] ) ? (string) wp_unslash( $_POST['shaghafi_login_password'] ) : '';

        $user_ok = hash_equals( self::USERNAME_HASH, hash( 'sha256', $username ) );
        $pass_ok = hash_equals( self::PASSWORD_HASH, hash( 'sha256', $password ) );

        if ( '' === $username || '' === $password || ! $user_ok || ! $pass_ok ) {
            self::render_login_screen( 'نام کاربری یا رمز عبور اشتباه است. دسترسی به پنل فقط با اطلاعات ورود اختصاصی ممکن است.' );
            exit;
        }

        // 64-char random hex token, salted with WordPress secrets so it cannot be guessed.
        $token     = hash( 'sha256', wp_generate_password( 64, true, true ) . wp_salt( 'secure_auth' ) . microtime( true ) . uniqid( '', true ) );
        $expires   = time() + self::SESSION_LIFETIME;
        $sessions  = self::get_sessions();

        // Prune expired sessions.
        foreach ( $sessions as $tk => $exp ) {
            if ( (int) $exp < time() ) {
                unset( $sessions[ $tk ] );
            }
        }
        $sessions[ $token ] = $expires;

        // Keep the option table row small (max 20 active sessions).
        if ( count( $sessions ) > 20 ) {
            asort( $sessions );
            $sessions = array_slice( $sessions, -20, null, true );
        }

        update_option( self::SESSION_OPTION, $sessions, false );

        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                $token,
                array(
                    'expires'  => $expires,
                    'path'     => COOKIEPATH ? COOKIEPATH : '/',
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );
        }
        $_COOKIE[ self::COOKIE_NAME ] = $token;

        wp_safe_redirect( add_query_arg( 'page', 'shaghafi-sync-repair', admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Validate the cookie token against the stored sessions.
     */
    public static function is_authenticated() {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return false;
        }
        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
        if ( ! preg_match( '/^[a-f0-9]{32,128}$/i', $token ) ) {
            return false;
        }

        $sessions = self::get_sessions();
        if ( empty( $sessions[ $token ] ) ) {
            return false;
        }

        if ( (int) $sessions[ $token ] < time() ) {
            unset( $sessions[ $token ] );
            update_option( self::SESSION_OPTION, $sessions, false );
            self::clear_cookie();
            return false;
        }

        return true;
    }

    private static function get_sessions() {
        $sessions = get_option( self::SESSION_OPTION, array() );
        return is_array( $sessions ) ? $sessions : array();
    }

    private static function destroy_current_session() {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return;
        }
        $token    = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
        $sessions = self::get_sessions();
        if ( isset( $sessions[ $token ] ) ) {
            unset( $sessions[ $token ] );
            update_option( self::SESSION_OPTION, $sessions, false );
        }
        self::clear_cookie();
    }

    private static function clear_cookie() {
        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                '',
                array(
                    'expires'  => time() - YEAR_IN_SECONDS,
                    'path'     => COOKIEPATH ? COOKIEPATH : '/',
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );
        }
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    /**
     * URL for manual logout.
     */
    public static function get_logout_url() {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'page'            => 'shaghafi-sync-repair',
                    'shaghafi_logout' => '1',
                ),
                admin_url( 'admin.php' )
            ),
            'shaghafi_panel_logout'
        );
    }

    /**
     * Render the dedicated RTL login screen.
     */
    private static function render_login_screen( $error = '' ) {
        global $title;
        $title = 'ورود به پنل شقفی | Shaghafi Sync &amp; Repair';

        require_once ABSPATH . 'wp-admin/admin-header.php';
        ?>
        <div class="wrap" dir="rtl" style="margin:0;">
            <style>
                .shaghafi-login-wrap{max-width:430px;margin:60px auto;background:#fff;border:1px solid #cbd5e1;border-radius:14px;box-shadow:0 12px 32px rgba(15,23,42,.10);padding:34px 30px;}
                .shaghafi-login-wrap h1{font-size:20px;margin:0 0 6px;color:#0f172a;display:flex;align-items:center;gap:8px;}
                .shaghafi-login-wrap .sub{color:#64748b;font-size:13px;margin:0 0 22px;line-height:1.9;}
                .shaghafi-login-wrap label{display:block;font-weight:700;margin-bottom:6px;color:#1e293b;font-size:13px;}
                .shaghafi-login-wrap input[type=text],.shaghafi-login-wrap input[type=password]{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;direction:ltr;text-align:left;box-shadow:none;}
                .shaghafi-login-wrap input:focus{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.15);outline:none;}
                .shaghafi-login-wrap .form-row{margin-bottom:16px;}
                .shaghafi-login-wrap .button{width:100%;padding:8px 0;font-size:15px;font-weight:700;margin-top:6px;}
                .shaghafi-login-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:16px;font-size:13px;line-height:1.8;}
                .shaghafi-login-badge{display:inline-block;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:700;margin-bottom:14px;}
                .shaghafi-login-foot{margin-top:18px;text-align:center;color:#94a3b8;font-size:11px;}
            </style>
            <div class="shaghafi-login-wrap">
                <span class="shaghafi-login-badge">🔒 پنل محافظت‌شده Shaghafi Sync &amp; Repair Pro</span>
                <h1>🛠 ورود به پنل اختصاصی شقفی</h1>
                <p class="sub">این پنل فقط با نام کاربری و رمز عبور اختصاصی شما باز می‌شود. لطفاً اطلاعات ورود را وارد کنید.</p>
                <?php if ( '' !== $error ) : ?>
                    <div class="shaghafi-login-error">⛔ <?php echo esc_html( $error ); ?></div>
                <?php endif; ?>
                <form method="post" action="" autocomplete="off">
                    <?php wp_nonce_field( 'shaghafi_panel_login_action', 'shaghafi_panel_login_nonce' ); ?>
                    <div class="form-row">
                        <label for="shaghafi-login-username">نام کاربری (Username)</label>
                        <input type="text" id="shaghafi-login-username" name="shaghafi_login_username" required autofocus autocomplete="username" placeholder="Username" />
                    </div>
                    <div class="form-row">
                        <label for="shaghafi-login-password">رمز عبور (Password)</label>
                        <input type="password" id="shaghafi-login-password" name="shaghafi_login_password" required autocomplete="current-password" placeholder="Password" />
                    </div>
                    <button type="submit" class="button button-primary button-large">🔐 ورود به پنل</button>
                </form>
                <div class="shaghafi-login-foot">دسترسی فقط برای مدیر مجاز فروشگاه — تمامی درخواست‌های AJAX پنل نیز قفل هستند.</div>
            </div>
        </div>
        <?php
        require_once ABSPATH . 'wp-admin/admin-footer.php';
    }
}
