<?php
/**
 * Plugin Name: Temporary Document Access
 * Plugin URI:  https://leodeveloper.com.ua/
 * Description: Adds a "Documents" Custom Post Type with time-limited tokenized access links (1 hour TTL).
 * Version:     1.0.0
 * Author:      Dmytro Tsaran
 * Author URI:  https://leodeveloper.com.ua/
 * License:     GPL-2.0-or-later
 * Text Domain: tda
 * Requires PHP: 8.2
 */

declare(strict_types=1);

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class.
 *
 * Encapsulates all CPT registration, meta box rendering, token generation/validation,
 * and front-end template loading in a single, self-contained class.
 */
final class Temporary_Document_Access {

    /**
     * The custom post type slug.
     */
    private const CPT_SLUG = 'shared_document';

    /**
     * Meta key used to store the last generated access link.
     */
    private const META_KEY_LINK = '_tda_access_link';

    /**
     * How long (in seconds) a generated token is valid.
     */
    private const TOKEN_TTL = 3600; // 1 hour

    /**
     * Query-string parameter names.
     */
    private const PARAM_DOC     = 'view_doc';
    private const PARAM_TOKEN   = 'token';
    private const PARAM_EXPIRES = 'expires';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Singleton instance holder.
     */
    private static ?self $instance = null;

    /**
     * Returns (and on first call creates) the single plugin instance.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor – registers all WordPress hooks.
     */
    private function __construct() {
        add_action( 'init',                  [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_meta_box' ] );
        // AJAX handlers: wp_ajax_{action} fires for logged-in users only.
        add_action( 'wp_ajax_tda_generate_link', [ $this, 'ajax_generate_link' ] );
        add_action( 'template_redirect',     [ $this, 'handle_token_request' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        // Branding hooks
        add_action( 'admin_head',            [ $this, 'inject_meta_tag' ] );
        add_action( 'admin_footer',          [ $this, 'inject_footer' ] );
    }

    // -------------------------------------------------------------------------
    // 1. CPT Registration
    // -------------------------------------------------------------------------

    /**
     * Registers the `shared_document` Custom Post Type.
     *
     * Intentionally non-public so documents are never reachable via direct URL,
     * search results, or the WP REST API without explicit permission.
     */
    public function register_cpt(): void {
        register_post_type(
            self::CPT_SLUG,
            [
                'label'               => __( 'Documents', 'tda' ),
                'labels'              => [
                    'name'          => __( 'Documents', 'tda' ),
                    'singular_name' => __( 'Document', 'tda' ),
                    'add_new_item'  => __( 'Add New Document', 'tda' ),
                    'edit_item'     => __( 'Edit Document', 'tda' ),
                ],
                // Non-public: keeps documents out of search and standard URLs.
                'public'              => false,
                'publicly_queryable'  => false,
                'has_archive'         => false,
                'show_ui'             => true,   // still visible in WP admin
                'show_in_menu'        => true,
                'show_in_rest'        => false,  // no REST endpoint
                'supports'            => [ 'title', 'editor' ],
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'rewrite'             => false,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // 2. Admin Meta Box
    // -------------------------------------------------------------------------

    /**
     * Registers the "Access Control" meta box on the document editor screen.
     */
    public function add_meta_box(): void {
        add_meta_box(
            'tda_access_control',
            __( 'Access Control', 'tda' ),
            [ $this, 'render_meta_box' ],
            self::CPT_SLUG,
            'side',
            'high'
        );
    }

    /**
     * Renders the meta box HTML.
     *
     * The "Generate Link" button fires an AJAX request so the page never
     * reloads and WordPress does not show the "unsaved changes" warning.
     * The link area is updated in-place via JavaScript after a successful response.
     *
     * @param WP_Post $post Current post object.
     */
    public function render_meta_box( WP_Post $post ): void {
        $saved_link = get_post_meta( $post->ID, self::META_KEY_LINK, true );
        ?>
        <div class="tda-meta-box" id="tda-meta-box-<?php echo absint( $post->ID ); ?>">

            <!--
                A plain <button type="button"> — NOT inside a <form> and NOT type="submit".
                This prevents any interaction with the post's own save form,
                which is what caused the "unsaved changes" warning before.
            -->
            <button
                type="button"
                id="tda-generate-btn"
                class="button button-primary"
                style="width:100%;margin-bottom:10px;"
                data-post-id="<?php echo absint( $post->ID ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'tda_generate_link_' . $post->ID ) ); ?>"
            >
                <?php esc_html_e( 'Generate Link', 'tda' ); ?>
            </button>

            <!-- Spinner shown while AJAX request is in-flight -->
            <span id="tda-spinner" class="spinner" style="float:none;display:none;margin:0 0 8px 0;"></span>

            <!-- Error message area -->
            <p id="tda-error" style="color:#b32d2e;font-size:12px;margin:0 0 6px;display:none;"></p>

            <!-- Link output area – pre-filled if a link was already saved -->
            <div id="tda-link-area" style="<?php echo empty( $saved_link ) ? 'display:none;' : ''; ?>">
                <p style="margin:0 0 4px;font-weight:600;font-size:12px;">
                    <?php esc_html_e( 'Shareable Link (valid 1 hour from generation):', 'tda' ); ?>
                </p>
                <textarea
                    id="tda-link-output"
                    readonly
                    style="width:100%;height:70px;font-size:11px;font-family:monospace;resize:none;word-break:break-all;"
                ><?php echo esc_url( $saved_link ); ?></textarea>
                <button
                    type="button"
                    id="tda-copy-btn"
                    class="button"
                    style="width:100%;margin-top:4px;"
                >
                    <?php esc_html_e( 'Copy Link', 'tda' ); ?>
                </button>
            </div>

            <?php if ( empty( $saved_link ) ) : ?>
                <p id="tda-no-link-msg" style="color:#999;font-size:12px;margin:0;">
                    <?php esc_html_e( 'No link generated yet.', 'tda' ); ?>
                </p>
            <?php endif; ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 3. Token Generation (AJAX handler)
    // -------------------------------------------------------------------------

    /**
     * AJAX handler for the "Generate Link" button.
     *
     * Expects POST fields: post_id (int), nonce (string).
     * Returns JSON: { success: true, data: { link: "https://..." } }
     *           or: { success: false, data: { message: "..." } }
     *
     * Using wp_send_json_success / wp_send_json_error automatically sets the
     * Content-Type header, encodes the payload, and calls wp_die() — so we
     * never need an explicit exit after these calls.
     */
    public function ajax_generate_link(): void {
        // Retrieve and sanitize the post ID.
        $post_id = absint( $_POST['post_id'] ?? 0 );

        // Gate: valid post ID.
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid document ID.', 'tda' ) ], 400 );
        }

        // Gate: nonce verification (action is tied to the specific post ID).
        if (
            ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['nonce'] ) ),
                'tda_generate_link_' . $post_id
            )
        ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'tda' ) ], 403 );
        }

        // Gate: user capability.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'tda' ) ], 403 );
        }

        // Build signed URL, persist it, return it.
        $link = $this->build_signed_url( $post_id );
        update_post_meta( $post_id, self::META_KEY_LINK, esc_url_raw( $link ) );

        wp_send_json_success( [ 'link' => esc_url( $link ) ] );
    }

    /**
     * Constructs a time-limited, HMAC-signed access URL for a document.
     *
     * URL format:
     *   https://site.com/?view_doc={ID}&token={HASH}&expires={TIMESTAMP}
     *
     * The HMAC is computed over "{post_id}:{expires}" using the WP secret salt
     * so it cannot be forged without server-side knowledge.
     *
     * @param int $post_id Post ID for which the link is generated.
     * @return string Fully-formed, signed URL.
     */
    private function build_signed_url( int $post_id ): string {
        $expires = time() + self::TOKEN_TTL;
        $token   = $this->compute_token( $post_id, $expires );

        return add_query_arg(
            [
                self::PARAM_DOC     => $post_id,
                self::PARAM_TOKEN   => $token,
                self::PARAM_EXPIRES => $expires,
            ],
            home_url( '/' )
        );
    }

    /**
     * Computes the HMAC token for a given post ID and expiry timestamp.
     *
     * Uses wp_salt('auth') as the HMAC key so the token is tied to this
     * specific WordPress installation.
     *
     * @param int $post_id  The document post ID.
     * @param int $expires  Unix timestamp when the link expires.
     * @return string Hex-encoded SHA-256 HMAC.
     */
    private function compute_token( int $post_id, int $expires ): string {
        return hash_hmac(
            'sha256',
            $post_id . ':' . $expires,
            wp_salt( 'auth' )
        );
    }

    // -------------------------------------------------------------------------
    // 4. Token Validation & Front-end Rendering
    // -------------------------------------------------------------------------

    /**
     * Intercepts requests carrying `view_doc`, validates the token, and either
     * renders the document or terminates with a 403 error.
     *
     * Hooked to `template_redirect` (runs before any template is loaded).
     */
    public function handle_token_request(): void {
        // Only act when our query parameter is present.
        if ( ! isset( $_GET[ self::PARAM_DOC ] ) ) {
            return;
        }

        // Sanitize all incoming parameters.
        $post_id       = absint( $_GET[ self::PARAM_DOC ] );
        $provided_token = sanitize_text_field( wp_unslash( $_GET[ self::PARAM_TOKEN ] ?? '' ) );
        $expires        = absint( $_GET[ self::PARAM_EXPIRES ] ?? 0 );

        // --- Validation 1: All parameters must be present and sensible. ---
        if ( $post_id <= 0 || $expires <= 0 || empty( $provided_token ) ) {
            wp_die(
                esc_html__( 'Invalid request parameters.', 'tda' ),
                esc_html__( 'Access Denied', 'tda' ),
                [ 'response' => 403 ]
            );
        }

        // --- Validation 2: Token must not be expired. ---
        if ( time() > $expires ) {
            wp_die(
                esc_html__( 'This link has expired. Please request a new one.', 'tda' ),
                esc_html__( 'Link Expired', 'tda' ),
                [ 'response' => 403 ]
            );
        }

        // --- Validation 3: Recompute and compare the HMAC (timing-safe). ---
        $expected_token = $this->compute_token( $post_id, $expires );
        if ( ! hash_equals( $expected_token, $provided_token ) ) {
            wp_die(
                esc_html__( 'Invalid or tampered token.', 'tda' ),
                esc_html__( 'Access Denied', 'tda' ),
                [ 'response' => 403 ]
            );
        }

        // --- Validation 4: The referenced post must exist and be the right type. ---
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || $post->post_type !== self::CPT_SLUG ) {
            wp_die(
                esc_html__( 'Document not found.', 'tda' ),
                esc_html__( 'Not Found', 'tda' ),
                [ 'response' => 404 ]
            );
        }

        // All checks passed – render the document inline.
        $this->render_document( $post );
        exit; // Prevent any further WordPress template processing.
    }

    /**
     * Outputs the document content as a minimal, standalone HTML page.
     *
     * In a real project you could load a theme template file instead; this
     * self-contained approach avoids theme dependencies.
     *
     * @param WP_Post $post The document post to render.
     */
    private function render_document( WP_Post $post ): void {
        // Make sure the global $post is set correctly (needed for shortcodes, etc.).
        global $wp_query;
        $wp_query->is_singular = true;
        setup_postdata( $GLOBALS['post'] = $post );

        $title   = get_the_title( $post );
        $content = apply_filters( 'the_content', $post->post_content );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $title ); ?> — <?php bloginfo( 'name' ); ?></title>
            <style>
                /* Minimal, readable document stylesheet */
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: Georgia, 'Times New Roman', serif;
                    font-size: 17px;
                    line-height: 1.75;
                    color: #1a1a1a;
                    background: #faf9f7;
                    padding: 3rem 1.5rem;
                }
                .tda-document {
                    max-width: 760px;
                    margin: 0 auto;
                    background: #fff;
                    padding: 3rem 3.5rem;
                    border: 1px solid #e4e0d8;
                    border-radius: 3px;
                    box-shadow: 0 2px 12px rgba(0,0,0,.06);
                }
                .tda-document__badge {
                    display: inline-block;
                    background: #fff3cd;
                    color: #856404;
                    border: 1px solid #ffc107;
                    border-radius: 3px;
                    font-family: sans-serif;
                    font-size: 12px;
                    font-weight: 600;
                    letter-spacing: .05em;
                    text-transform: uppercase;
                    padding: 3px 8px;
                    margin-bottom: 1.5rem;
                }
                .tda-document__title {
                    font-size: 2rem;
                    font-weight: 700;
                    line-height: 1.2;
                    margin-bottom: 2rem;
                    border-bottom: 2px solid #1a1a1a;
                    padding-bottom: 1rem;
                }
                .tda-document__body h1,
                .tda-document__body h2,
                .tda-document__body h3 { margin: 1.5rem 0 .75rem; line-height: 1.3; }
                .tda-document__body p  { margin-bottom: 1rem; }
                .tda-document__body ul,
                .tda-document__body ol { margin: .5rem 0 1rem 1.5rem; }
                .tda-document__footer {
                    margin-top: 3rem;
                    padding-top: 1rem;
                    border-top: 1px solid #e4e0d8;
                    font-family: sans-serif;
                    font-size: 12px;
                    color: #888;
                }
            </style>
        </head>
        <body>
            <article class="tda-document">
                <span class="tda-document__badge">
                    <?php esc_html_e( '🔒 Temporary Access', 'tda' ); ?>
                </span>
                <h1 class="tda-document__title"><?php echo esc_html( $title ); ?></h1>
                <div class="tda-document__body">
                    <?php echo wp_kses_post( $content ); ?>
                </div>
                <footer class="tda-document__footer">
                    <?php
                    printf(
                        /* translators: %s: human-readable expiry time */
                        esc_html__( 'This link expires %s.', 'tda' ),
                        esc_html( human_time_diff( absint( $_GET[ self::PARAM_EXPIRES ] ), time() ) . ' ' . __( 'from now', 'tda' ) )
                    );
                    ?>
                </footer>
            </article>
        </body>
        </html>
        <?php
        // phpcs:enable
    }

    // -------------------------------------------------------------------------
    // 5. Admin Assets
    // -------------------------------------------------------------------------

    /**
     * Enqueues styles and the AJAX JavaScript for the meta box.
     * Only runs on the `shared_document` edit/new-post screen.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_admin_assets( string $hook_suffix ): void {
        global $post;

        if (
            ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true )
            || ! $post instanceof WP_Post
            || $post->post_type !== self::CPT_SLUG
        ) {
            return;
        }

        // Inline style – piggybacked on the always-present wp-admin stylesheet.
        wp_add_inline_style( 'wp-admin', '
            #tda-generate-btn { display:block; }
            #tda-spinner.is-active { display:inline-block !important; }
        ' );

        /*
         * Inline JavaScript – handles:
         *   1. Click on "Generate Link" → AJAX POST to admin-ajax.php
         *   2. On success → reveal textarea with the new link
         *   3. Click on "Copy Link" → clipboard copy with visual feedback
         *
         * We use wp_add_inline_script on 'jquery' (always available in WP admin)
         * so we don't need to register/enqueue a separate JS file.
         */
        wp_add_inline_script( 'jquery', '
            (function($) {
                "use strict";

                $(function() {

                    var btn      = $("#tda-generate-btn");
                    var spinner  = $("#tda-spinner");
                    var errBox   = $("#tda-error");
                    var linkArea = $("#tda-link-area");
                    var textarea = $("#tda-link-output");
                    var copyBtn  = $("#tda-copy-btn");
                    var noLink   = $("#tda-no-link-msg");

                    // ── Generate Link ────────────────────────────────────────────────────
                    btn.on("click", function() {
                        var postId = btn.data("post-id");
                        var nonce  = btn.data("nonce");

                        // Show spinner, disable button, clear previous errors.
                        btn.prop("disabled", true);
                        spinner.addClass("is-active");
                        errBox.hide().text("");

                        $.ajax({
                            url:    window.ajaxurl,   // ajaxurl is always defined in WP admin
                            method: "POST",
                            data: {
                                action:  "tda_generate_link",  // matches wp_ajax_{action}
                                post_id: postId,
                                nonce:   nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Put the new link into the textarea and show the area.
                                    textarea.val(response.data.link);
                                    linkArea.show();
                                    noLink.hide();
                                } else {
                                    // Show the server-side error message.
                                    errBox.text(response.data.message || "Unknown error.").show();
                                }
                            },
                            error: function() {
                                errBox.text("Request failed. Please try again.").show();
                            },
                            complete: function() {
                                // Always re-enable the button and hide the spinner.
                                btn.prop("disabled", false);
                                spinner.removeClass("is-active");
                            }
                        });
                    });

                    // ── Copy Link ────────────────────────────────────────────────────────
                    copyBtn.on("click", function() {
                        if (!textarea.val()) { return; }

                        // Modern Clipboard API (HTTPS required), falls back to execCommand.
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(textarea.val()).then(function() {
                                flashCopied();
                            });
                        } else {
                            textarea[0].select();
                            document.execCommand("copy");
                            flashCopied();
                        }
                    });

                    function flashCopied() {
                        var original = copyBtn.text();
                        copyBtn.text("Copied!");
                        setTimeout(function() { copyBtn.text(original); }, 1500);
                    }

                });

            }(jQuery));
        ' );
    }

    // -------------------------------------------------------------------------
    // 6. Branding
    // -------------------------------------------------------------------------

    /**
     * Inject designer meta tag in admin <head>
     */
    public function inject_meta_tag(): void {
        $screen = get_current_screen();

        if ( $screen && in_array( $screen->id, [ 'plugins', 'edit-shared_document', 'post.php', 'post-new.php' ], true ) ) {
            if ( in_array( $screen->id, [ 'post.php', 'post-new.php' ], true ) ) {
                global $post;
                if ( ! ( $post instanceof WP_Post && $post->post_type === self::CPT_SLUG ) ) {
                    return;
                }
            }
            echo '<meta name="designer" content="Created with Soul and Care by Tsaran Digital | https://leodeveloper.com.ua/">' . "\n";
        }
    }

    /**
     * Inject designer footer link on plugin pages
     */
    public function inject_footer(): void {
        $screen = get_current_screen();

        if ( $screen && in_array( $screen->id, [ 'plugins', 'edit-shared_document', 'post.php', 'post-new.php' ], true ) ) {
            if ( in_array( $screen->id, [ 'post.php', 'post-new.php' ], true ) ) {
                global $post;
                if ( ! ( $post instanceof WP_Post && $post->post_type === self::CPT_SLUG ) ) {
                    return;
                }
            }
            echo '<div id="tda-credential-links" class="tda-credential-links">'
                . '<a id="tda-credential-link-tsaran" class="tda-credential-link-tsaran" '
                . 'href="https://leodeveloper.com.ua/" '
                . 'title="Created with Soul and Care by Tsaran Digital" '
                . 'target="_blank">Created by Tsaran Digital</a>'
                . '</div>' . "\n";
        }
    }
}

// Boot the plugin.
Temporary_Document_Access::instance();