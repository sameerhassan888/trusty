<?php
/**
 * Plugin Name: WooCommerce Tour Bookings
 * Description: A high-fidelity Bókun-style alternative for managing tour and activity bookings in WooCommerce.
 * Version: 2.4.0
 * Author: Jules
 * Text Domain: wc-tour-bookings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Tour_Bookings {

    public function __construct() {
        add_action( 'woocommerce_loaded', array( $this, 'includes' ) );
        add_filter( 'product_type_selector', array( $this, 'add_tour_product_type' ) );
        add_filter( 'woocommerce_product_class', array( $this, 'register_tour_product_class' ), 10, 2 );
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tour_bookings_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'add_tour_bookings_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_tour_bookings_data' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_booking_selection' ) );

        // Cart and Order Integration
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );

        // Validation
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
        add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update_cart' ), 10, 4 );

        // Admin CSS/JS for product type visibility
        add_action( 'admin_footer', array( $this, 'admin_product_type_js' ) );

        // Admin Menu for Booking Management (Top Level)
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // Custom /dashboard endpoint
        add_action( 'init', array( $this, 'add_custom_rewrite_rule' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'render_frontend_dashboard' ) );

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Activation / Deactivation
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    public function activate() {
        $this->add_custom_rewrite_rule();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function add_custom_rewrite_rule() {
        add_rewrite_rule( '^dashboard/?$', 'index.php?tour_dashboard=1', 'top' );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'tour_dashboard';
        return $vars;
    }

    public function render_frontend_dashboard() {
        if ( get_query_var( 'tour_dashboard' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( __( 'You do not have sufficient permissions to access this page.', 'wc-tour-bookings' ), 403 );
            }

            // Enqueue assets for frontend view of dashboard
            wp_enqueue_style( 'wc-tour-bookings-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin-dashboard.css', array(), '2.4.0' );
            wp_enqueue_style( 'dashicons' );

            // Minimal WP head for style support
            echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . get_bloginfo( 'charset' ) . '"><title>' . __( 'Tour Bookings Dashboard', 'wc-tour-bookings' ) . '</title>';
            wp_head();
            echo '<style>body { margin: 0; padding: 0; overflow-x: hidden; } .bokun-page-wrapper { margin-left: 0; }</style></head><body>';

            $this->admin_dashboard_page();

            wp_footer();
            echo '</body></html>';
            exit;
        }
    }

    public function declare_hpos_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    public function includes() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-product-tour.php';
    }

    public function add_tour_product_type( $types ) {
        $types['tour'] = __( 'Tour', 'wc-tour-bookings' );
        return $types;
    }

    public function register_tour_product_class( $classname, $product_type ) {
        if ( $product_type === 'tour' ) {
            $classname = 'WC_Product_Tour';
        }
        return $classname;
    }

    public function add_tour_bookings_tab( $tabs ) {
        $tabs['tour_bookings'] = array(
            'label'  => __( 'Bookings', 'wc-tour-bookings' ),
            'target' => 'tour_bookings_data',
            'class'  => array( 'show_if_tour' ),
        );
        return $tabs;
    }

    public function add_tour_bookings_panel() {
        global $post;
        wp_nonce_field( 'save_tour_bookings_meta', 'tour_bookings_nonce' );
        ?>
        <div id="tour_bookings_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input( array(
                    'id'          => '_tour_capacity',
                    'label'       => __( 'Capacity', 'wc-tour-bookings' ),
                    'placeholder' => __( 'Maximum participants per slot', 'wc-tour-bookings' ),
                    'desc_tip'    => 'true',
                    'description' => __( 'Enter the maximum number of participants for this tour per time slot.', 'wc-tour-bookings' ),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'step' => '1',
                        'min'  => '1'
                    )
                ) );

                woocommerce_wp_text_input( array(
                    'id'          => '_tour_time_slots',
                    'label'       => __( 'Time Slots', 'wc-tour-bookings' ),
                    'placeholder' => __( '09:00, 14:00, 18:00', 'wc-tour-bookings' ),
                    'desc_tip'    => 'true',
                    'description' => __( 'Enter comma-separated time slots for this tour.', 'wc-tour-bookings' ),
                ) );

                woocommerce_wp_checkbox( array(
                    'id'          => '_tour_collect_participants',
                    'label'       => __( 'Collect Participant Names', 'wc-tour-bookings' ),
                    'description' => __( 'Enable to collect names of participants on the product page.', 'wc-tour-bookings' ),
                ) );
                ?>
            </div>
        </div>
        <?php
    }

    public function save_tour_bookings_data( $post_id ) {
        if ( ! isset( $_POST['tour_bookings_nonce'] ) || ! wp_verify_nonce( $_POST['tour_bookings_nonce'], 'save_tour_bookings_meta' ) ) {
            return;
        }

        $capacity = isset( $_POST['_tour_capacity'] ) ? sanitize_text_field( $_POST['_tour_capacity'] ) : '';
        update_post_meta( $post_id, '_tour_capacity', $capacity );

        $slots = isset( $_POST['_tour_time_slots'] ) ? sanitize_text_field( $_POST['_tour_time_slots'] ) : '';
        update_post_meta( $post_id, '_tour_time_slots', $slots );

        $collect = isset( $_POST['_tour_collect_participants'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_tour_collect_participants', $collect );
    }

    public function enqueue_scripts() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        global $post;
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = wc_get_product( $post->ID );
        if ( $product && $product->is_type( 'tour' ) ) {
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_style( 'jquery-ui-style', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
            wp_enqueue_script( 'wc-tour-bookings-frontend', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), '2.4.0', true );
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wc-tour-bookings') !== false) {
            wp_enqueue_style('wc-tour-bookings-admin', plugin_dir_url(__FILE__) . 'assets/css/admin-dashboard.css', array(), '2.4.0');
            echo '<style>#wpadminbar, #adminmenumain, #wpfooter { display: none !important; } #wpcontent { margin-left: 0 !important; padding: 0 !important; } .update-nag, .notice { display: none !important; }</style>';
        }
    }

    public function display_booking_selection() {
        global $product;
        if ( ! $product || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'tour' ) ) {
            return;
        }
        $slots_raw = get_post_meta( $product->get_id(), '_tour_time_slots', true );
        $slots = ! empty( $slots_raw ) ? array_map( 'trim', explode( ',', $slots_raw ) ) : array();
        $collect = get_post_meta( $product->get_id(), '_tour_collect_participants', true ) === 'yes';
        ?>
        <div class="tour-booking-selection" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
            <div style="margin-bottom: 15px;">
                <label for="tour_booking_date" style="display: block; font-weight: bold; margin-bottom: 5px;"><?php _e( 'Select Date:', 'wc-tour-bookings' ); ?></label>
                <input type="text" id="tour_booking_date" name="tour_booking_date" class="tour-booking-date" readonly placeholder="<?php _e( 'Choose a date...', 'wc-tour-bookings' ); ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;">
            </div>

            <?php if ( ! empty( $slots ) ) : ?>
                <div style="margin-bottom: 15px;">
                    <label for="tour_booking_slot" style="display: block; font-weight: bold; margin-bottom: 5px;"><?php _e( 'Select Time Slot:', 'wc-tour-bookings' ); ?></label>
                    <select name="tour_booking_slot" id="tour_booking_slot" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;">
                        <option value=""><?php _e( 'Choose a time...', 'wc-tour-bookings' ); ?></option>
                        <?php foreach ( $slots as $slot ) : ?>
                            <option value="<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $slot ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ( $collect ) : ?>
                <div style="margin-bottom: 10px;" class="participant-names">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;"><?php _e( 'Participant Names:', 'wc-tour-bookings' ); ?></label>
                    <div id="participant_fields_container">
                        <input type="text" name="tour_participants[]" placeholder="<?php _e( 'Participant 1', 'wc-tour-bookings' ); ?>" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; margin-bottom: 5px;">
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return $cart_item_data;
        }
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_type( 'tour' ) ) {
            if ( isset( $_POST['tour_booking_date'] ) && ! empty( $_POST['tour_booking_date'] ) ) {
                $cart_item_data['tour_booking_date'] = sanitize_text_field( $_POST['tour_booking_date'] );
            }
            if ( isset( $_POST['tour_booking_slot'] ) && ! empty( $_POST['tour_booking_slot'] ) ) {
                $cart_item_data['tour_booking_slot'] = sanitize_text_field( $_POST['tour_booking_slot'] );
            }
            if ( isset( $_POST['tour_participants'] ) && is_array( $_POST['tour_participants'] ) ) {
                $cart_item_data['tour_participants'] = array_filter( array_map( 'sanitize_text_field', $_POST['tour_participants'] ) );
            }
        }
        return $cart_item_data;
    }

    public function get_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['tour_booking_date'] ) ) {
            $item_data[] = array(
                'key'   => __( 'Booking Date', 'wc-tour-bookings' ),
                'value' => $cart_item['tour_booking_date'],
            );
        }
        if ( isset( $cart_item['tour_booking_slot'] ) ) {
            $item_data[] = array(
                'key'   => __( 'Time Slot', 'wc-tour-bookings' ),
                'value' => $cart_item['tour_booking_slot'],
            );
        }
        if ( isset( $cart_item['tour_participants'] ) && ! empty( $cart_item['tour_participants'] ) ) {
            $item_data[] = array(
                'key'   => __( 'Participants', 'wc-tour-bookings' ),
                'value' => implode( ', ', $cart_item['tour_participants'] ),
            );
        }
        return $item_data;
    }

    public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['tour_booking_date'] ) ) {
            $item->add_meta_data( '_tour_booking_date', $values['tour_booking_date'] );
        }
        if ( isset( $values['tour_booking_slot'] ) ) {
            $item->add_meta_data( '_tour_booking_slot', $values['tour_booking_slot'] );
        }
        if ( isset( $values['tour_participants'] ) ) {
            $item->add_meta_data( '_tour_participants', implode( ', ', (array) $values['tour_participants'] ) );
        }
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return $passed;
        }
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_type( 'tour' ) ) {
            if ( ! isset( $_POST['tour_booking_date'] ) || empty( $_POST['tour_booking_date'] ) ) {
                wc_add_notice( __( 'Please select a booking date.', 'wc-tour-bookings' ), 'error' );
                return false;
            }

            $slots_raw = get_post_meta( $product_id, '_tour_time_slots', true );
            $slots = ! empty( $slots_raw ) ? array_map( 'trim', explode( ',', $slots_raw ) ) : array();
            if ( ! empty( $slots ) && ( ! isset( $_POST['tour_booking_slot'] ) || empty( $_POST['tour_booking_slot'] ) ) ) {
                wc_add_notice( __( 'Please select a time slot.', 'wc-tour-bookings' ), 'error' );
                return false;
            }

            $booking_date = sanitize_text_field( $_POST['tour_booking_date'] );
            $booking_slot = isset( $_POST['tour_booking_slot'] ) ? sanitize_text_field( $_POST['tour_booking_slot'] ) : '';

            if ( strtotime( $booking_date ) < strtotime( 'today' ) ) {
                wc_add_notice( __( 'Please select a valid future date.', 'wc-tour-bookings' ), 'error' );
                return false;
            }

            $capacity = get_post_meta( $product_id, '_tour_capacity', true );
            if ( ! empty( $capacity ) && $capacity > 0 ) {
                $booked_count = $this->get_booked_count( $product_id, $booking_date, $booking_slot );
                $in_cart_count = $this->get_in_cart_count( $product_id, $booking_date, $booking_slot );

                if ( ( $booked_count + $in_cart_count + $quantity ) > $capacity ) {
                    $msg = $booking_slot ? sprintf( __( 'Sorry, this tour is full for %s at %s. Only %d spots left.', 'wc-tour-bookings' ), $booking_date, $booking_slot, max( 0, $capacity - $booked_count - $in_cart_count ) ) : sprintf( __( 'Sorry, this tour is full for %s. Only %d spots left.', 'wc-tour-bookings' ), $booking_date, max( 0, $capacity - $booked_count - $in_cart_count ) );
                    wc_add_notice( $msg, 'error' );
                    return false;
                }
            }
        }
        return $passed;
    }

    public function validate_update_cart( $passed, $cart_item_key, $values, $quantity ) {
        if ( ! function_exists( 'WC' ) ) {
            return $passed;
        }
        $cart_item = WC()->cart->get_cart_item( $cart_item_key );
        if ( isset( $cart_item['tour_booking_date'] ) ) {
            $product_id = $cart_item['product_id'];
            $booking_date = $cart_item['tour_booking_date'];
            $booking_slot = isset( $cart_item['tour_booking_slot'] ) ? $cart_item['tour_booking_slot'] : '';
            $capacity = get_post_meta( $product_id, '_tour_capacity', true );

            if ( ! empty( $capacity ) && $capacity > 0 ) {
                $booked_count = $this->get_booked_count( $product_id, $booking_date, $booking_slot );
                $in_cart_count = $this->get_in_cart_count( $product_id, $booking_date, $booking_slot, $cart_item_key );

                if ( ( $booked_count + $in_cart_count + $quantity ) > $capacity ) {
                    $msg = $booking_slot ? sprintf( __( 'Sorry, you cannot increase the spots to %d. Only %d spots left for %s at %s.', 'wc-tour-bookings' ), $quantity, max( 0, $capacity - $booked_count - $in_cart_count ), $booking_date, $booking_slot ) : sprintf( __( 'Sorry, you cannot increase the spots to %d. Only %d spots left for %s.', 'wc-tour-bookings' ), $quantity, max( 0, $capacity - $booked_count - $in_cart_count ), $booking_date );
                    wc_add_notice( $msg, 'error' );
                    return false;
                }
            }
        }
        return $passed;
    }

    private function get_booked_count( $product_id, $date, $slot = '' ) {
        global $wpdb;
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }
        $valid_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
        $placeholders   = implode( ',', array_fill( 0, count( $valid_statuses ), '%s' ) );

        $sql = "SELECT SUM(item_meta_qty.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_date
             INNER JOIN {$wpdb->prefix}woocommerce_order_items as items ON item_meta_date.order_item_id = items.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_product ON items.order_item_id = item_meta_product.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_qty ON items.order_item_id = item_meta_qty.order_item_id";

        if ( ! class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) || ! \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::is_order_tabs_enabled() ) {
            $sql .= " INNER JOIN {$wpdb->prefix}posts as posts ON items.order_id = posts.ID";
            $status_where = " AND posts.post_status IN ($placeholders)";
        } else {
            return $this->get_booked_count_safe($product_id, $date, $slot);
        }

        $params = array( $date, $product_id );
        $where = " WHERE item_meta_date.meta_key = '_tour_booking_date'
             AND item_meta_date.meta_value = %s
             AND item_meta_product.meta_key = '_product_id'
             AND item_meta_product.meta_value = %d
             AND item_meta_qty.meta_key = '_qty'" . $status_where;

        if ( ! empty( $slot ) ) {
            $sql .= " INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_slot ON items.order_item_id = item_meta_slot.order_item_id";
            $where .= " AND item_meta_slot.meta_key = '_tour_booking_slot' AND item_meta_slot.meta_value = %s";
            $params[] = $slot;
        }

        $query = $wpdb->prepare( $sql . $where, array_merge( $params, $valid_statuses ) );
        return (int) $wpdb->get_var( $query );
    }

    private function get_booked_count_safe($product_id, $date, $slot = '') {
        $orders = wc_get_orders( array(
            'status' => array( 'processing', 'completed', 'on-hold' ),
            'limit'  => -1,
            'date_created' => '>' . date('Y-m-d', strtotime('-1 year')),
        ) );

        $count = 0;
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_product_id() == $product_id ) {
                    if ( $item->get_meta( '_tour_booking_date' ) === $date ) {
                        if ( empty( $slot ) || $item->get_meta( '_tour_booking_slot' ) === $slot ) {
                            $count += $item->get_quantity();
                        }
                    }
                }
            }
        }
        return $count;
    }

    private function get_in_cart_count( $product_id, $date, $slot = '', $exclude_cart_item_key = '' ) {
        $count = 0;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                if ( $cart_item_key === $exclude_cart_item_key ) {
                    continue;
                }
                if ( $cart_item['product_id'] == $product_id && isset( $cart_item['tour_booking_date'] ) && $cart_item['tour_booking_date'] === $date ) {
                    $item_slot = isset( $cart_item['tour_booking_slot'] ) ? $cart_item['tour_booking_slot'] : '';
                    if ( $item_slot === $slot ) {
                        $count += $cart_item['quantity'];
                    }
                }
            }
        }
        return $count;
    }

    public function admin_product_type_js() {
        if ( 'product' !== get_post_type() ) {
            return;
        }
        ?>
        <script type='text/javascript'>
            jQuery(document).ready(function($) {
                $('.options_group.pricing, .inventory_tab').addClass('show_if_tour');
                $('.options_group.pricing').addClass('show_if_tour');
                $('select#product-type').trigger('change');
            });
        </script>
        <?php
    }

    public function register_admin_menu() {
        add_menu_page(
            __( 'Tour Bookings', 'wc-tour-bookings' ),
            __( 'Tour Bookings', 'wc-tour-bookings' ),
            'manage_woocommerce',
            'wc-tour-bookings-dashboard',
            array( $this, 'admin_dashboard_page' ),
            'dashicons-calendar-alt',
            55
        );

        add_submenu_page(
            'wc-tour-bookings-dashboard',
            __( 'Dashboard', 'wc-tour-bookings' ),
            __( 'Dashboard', 'wc-tour-bookings' ),
            'manage_woocommerce',
            'wc-tour-bookings-dashboard',
            array( $this, 'admin_dashboard_page' )
        );

        add_submenu_page(
            'wc-tour-bookings-dashboard',
            __( 'Experiences', 'wc-tour-bookings' ),
            __( 'Experiences', 'wc-tour-bookings' ),
            'manage_woocommerce',
            'edit.php?post_type=product&product_type=tour',
            null
        );

        add_submenu_page(
            'wc-tour-bookings-dashboard',
            __( 'All Bookings', 'wc-tour-bookings' ),
            __( 'All Bookings', 'wc-tour-bookings' ),
            'manage_woocommerce',
            'wc-tour-bookings-list',
            array( $this, 'admin_bookings_list_page' )
        );
    }

    private function render_bokun_sidebar($active_page = 'dashboard') {
        $user = wp_get_current_user();
        ?>
        <div class="bokun-sidebar">
            <div class="bokun-sidebar-logo">
                <div class="bokun-logo-icon">B</div>
                <span style="font-size: 18px; font-weight: 700;">Bókun</span>
                <i class="dashicons dashicons-arrow-left-alt2" style="margin-left: auto; font-size: 14px; cursor: pointer;"></i>
            </div>

            <div class="bokun-sidebar-search">
                <span>Search... (ctrl + k)</span>
                <i class="dashicons dashicons-search" style="font-size: 14px;"></i>
            </div>

            <div class="bokun-nav-group">
                <a href="<?php echo home_url('/dashboard'); ?>" class="bokun-nav-item <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-dashboard bokun-nav-icon"></i>
                    <span>Dashboard</span>
                </a>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-calendar-alt bokun-nav-icon"></i>
                    <span>Bookings</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-group">
                    <div class="bokun-nav-item active">
                        <i class="dashicons dashicons-location-alt bokun-nav-icon"></i>
                        <span>Experiences</span>
                        <i class="dashicons dashicons-arrow-up-alt2 bokun-nav-arrow"></i>
                    </div>
                    <div class="bokun-sub-nav">
                        <a href="<?php echo admin_url('edit.php?post_type=product&product_type=tour'); ?>" class="bokun-sub-item">Experiences overview</a>
                        <a href="#" class="bokun-sub-item">Gift cards</a>
                        <a href="#" class="bokun-sub-item">Price catalogs</a>
                        <a href="#" class="bokun-sub-item">Price schedules</a>
                        <a href="#" class="bokun-sub-item">Resource management</a>
                        <a href="#" class="bokun-sub-item">Allocation manager <span class="bokun-beta-tag">Beta</span></a>
                    </div>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-cart bokun-nav-icon"></i>
                    <span>Sales tools</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-store bokun-nav-icon"></i>
                    <span>Marketplace</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-clipboard bokun-nav-icon"></i>
                    <span>Operations</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-chart-bar bokun-nav-icon"></i>
                    <span>Reports</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-grid-view bokun-nav-icon"></i>
                    <span>App store</span>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-businessman bokun-nav-icon"></i>
                    <span>Agents</span>
                    <i class="dashicons dashicons-arrow-down-alt2 bokun-nav-arrow"></i>
                </div>
                <div class="bokun-nav-item">
                    <i class="dashicons dashicons-share-alt2 bokun-nav-icon"></i>
                    <span>Refer a friend</span>
                </div>
            </div>

            <div class="bokun-sidebar-footer">
                <div class="bokun-footer-icons">
                    <div class="bokun-footer-icon-btn"><i class="dashicons dashicons-megaphone"></i></div>
                    <div class="bokun-footer-icon-btn"><i class="dashicons dashicons-editor-help"></i></div>
                    <div class="bokun-footer-icon-btn"><i class="dashicons dashicons-admin-generic"></i></div>
                    <div class="bokun-footer-icon-btn"><i class="dashicons dashicons-bell"></i></div>
                </div>
                <div class="bokun-user-profile">
                    <div class="bokun-avatar"><?php echo get_avatar($user->ID, 36); ?></div>
                    <div class="bokun-user-info">
                        <span class="bokun-user-name"><?php echo esc_html($user->display_name); ?></span>
                        <span class="bokun-user-org">Experience Qatar (87078)</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_dashboard_page() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="wrap"><h1>' . __( 'Tour Bookings Dashboard', 'wc-tour-bookings' ) . '</h1><p>' . __( 'WooCommerce is not active.', 'wc-tour-bookings' ) . '</p></div>';
            return;
        }

        $today_date = wp_date('Y-m-d');
        $orders = wc_get_orders( array(
            'status' => array( 'processing', 'completed', 'on-hold' ),
            'limit'  => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ) );

        $stats = array(
            'total_bookings' => 0,
            'total_passengers' => 0,
            'booking_value' => 0,
            'today_passengers' => 0
        );

        $upcoming_departures = array();
        $weekly_trends = array_fill(0, 5, 0);

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $booking_date = $item->get_meta( '_tour_booking_date' );
                if ( $booking_date ) {
                    $qty = (int) $item->get_quantity();
                    $stats['total_bookings']++;
                    $stats['total_passengers'] += $qty;
                    $stats['booking_value'] += (float) $item->get_total();

                    if ( $booking_date === $today_date ) {
                        $stats['today_passengers'] += $qty;
                    }

                    $diff = (strtotime($booking_date) - strtotime($today_date)) / (60 * 60 * 24);
                    if ($diff >= 0 && $diff <= 7) {
                        $slot = $item->get_meta( '_tour_booking_slot' );
                        $key = $booking_date . ($slot ? ' ' . $slot : '');
                        if (!isset($upcoming_departures[$key])) {
                            $upcoming_departures[$key] = array(
                                'date' => $booking_date,
                                'slot' => $slot,
                                'name' => $item->get_name(),
                                'spots' => 0,
                                'capacity' => (int) get_post_meta($item->get_product_id(), '_tour_capacity', true)
                            );
                        }
                        $upcoming_departures[$key]['spots'] += $qty;
                    }

                    $order_week = (int) wp_date('W', strtotime($order->get_date_created()->date('Y-m-d')));
                    $current_week = (int) wp_date('W');
                    $week_diff = $current_week - $order_week;
                    if ($week_diff >= 0 && $week_diff < 5) {
                        $weekly_trends[4 - $week_diff] += $qty;
                    }
                }
            }
        }
        ksort($upcoming_departures);
        ?>
        <div class="bokun-page-wrapper">
            <?php $this->render_bokun_sidebar('dashboard'); ?>
            <div class="bokun-content-area">
                <div class="bokun-dashboard">
                    <div class="bokun-top-bar">
                        <h1><?php echo sprintf(__( 'Hello, is it me you\'re looking for?', 'wc-tour-bookings' )); ?></h1>
                        <div class="pro-badge" style="color: #2271b1; font-weight: 600; text-decoration: underline; cursor: pointer;"><?php _e('PRO subscription', 'wc-tour-bookings'); ?></div>
                    </div>

                    <div class="bokun-stats-grid" style="margin-top: 25px;">
                        <div class="bokun-card">
                            <div class="bokun-card-header">
                                <span class="bokun-card-title"><?php _e('Bookings', 'wc-tour-bookings'); ?></span>
                                <span class="bokun-badge bokun-badge-blue">Last 6 months</span>
                            </div>
                            <div class="bokun-card-value"><?php echo $stats['total_bookings']; ?></div>
                        </div>
                        <div class="bokun-card">
                            <div class="bokun-card-header">
                                <span class="bokun-card-title"><?php _e('Passengers', 'wc-tour-bookings'); ?></span>
                                <span class="bokun-badge bokun-badge-blue">Last 14 days</span>
                            </div>
                            <div class="bokun-card-value"><?php echo $stats['total_passengers']; ?></div>
                        </div>
                        <div class="bokun-card">
                            <div class="bokun-card-header">
                                <span class="bokun-card-title"><?php _e('Booking Value', 'wc-tour-bookings'); ?></span>
                                <span class="bokun-badge bokun-badge-blue">Total</span>
                            </div>
                            <div class="bokun-card-value"><?php echo wc_price($stats['booking_value']); ?></div>
                        </div>
                    </div>

                    <div class="bokun-main-grid">
                        <div class="bokun-left-col">
                            <div class="bokun-card" style="margin-bottom: 20px;">
                                <div class="bokun-card-header">
                                    <span class="bokun-card-title"><?php _e('Your bookings', 'wc-tour-bookings'); ?></span>
                                    <span style="font-size: 12px; color: #646970;"><?php echo wp_date('M d') . ' - ' . wp_date('M d', strtotime('+30 days')); ?></span>
                                </div>
                                <div class="bokun-chart-container">
                                    <?php foreach($weekly_trends as $index => $val): ?>
                                        <div class="bokun-bar-group">
                                            <div class="bokun-bar" style="height: <?php echo min(100, ($val / max(1, array_max_helper_v3($weekly_trends))) * 100); ?>%;"></div>
                                            <span class="bokun-bar-label">Week <?php echo $index + 1; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="bokun-card">
                                <div class="bokun-card-header">
                                    <span class="bokun-card-title"><?php _e('Manage your customers', 'wc-tour-bookings'); ?></span>
                                </div>
                                <p style="color: #646970; margin: 15px 0;"><?php _e('Congratulations on getting your first booking! Make sure to keep your travelers up to date using the Tour Bookings features.', 'wc-tour-bookings' ); ?></p>
                                <a href="<?php echo admin_url('admin.php?page=wc-tour-bookings-list'); ?>" class="button button-primary" style="background: #1d2327; border: none; padding: 10px 25px; border-radius: 6px; font-weight: 600;"><?php _e('Manage customers', 'wc-tour-bookings'); ?></a>
                            </div>
                        </div>

                        <div class="bokun-right-col">
                            <div class="bokun-card">
                                <div class="bokun-card-header">
                                    <span class="bokun-card-title"><?php _e('Upcoming departures', 'wc-tour-bookings'); ?></span>
                                </div>
                                <div class="bokun-departures-list">
                                    <?php if ( ! empty( $upcoming_departures ) ) : ?>
                                        <?php foreach ( array_slice($upcoming_departures, 0, 5) as $dep ) : ?>
                                            <div class="bokun-departure-item">
                                                <div class="bokun-departure-time"><?php echo esc_html($dep['date']); ?> <?php echo esc_html($dep['slot']); ?></div>
                                                <a href="#" class="bokun-departure-name"><?php echo esc_html($dep['name']); ?></a>
                                                <div class="bokun-departure-meta">
                                                    <span><?php echo $dep['spots']; ?><?php echo $dep['capacity'] ? '/' . $dep['capacity'] : ''; ?> <i class="dashicons dashicons-admin-users" style="font-size: 14px; width: 14px; height: 14px; line-height: 1;"></i></span>
                                                </div>
                                                <?php if ($dep['capacity'] > 0) : ?>
                                                    <div class="bokun-capacity-bar">
                                                        <div class="bokun-capacity-fill" style="width: <?php echo min(100, ($dep['spots'] / $dep['capacity']) * 100); ?>%;"></div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <p style="color: #646970; font-size: 13px;"><?php _e('No upcoming departures found.', 'wc-tour-bookings'); ?></p>
                                    <?php endif; ?>
                                </div>
                                <p style="margin-top: 15px; border-top: 1px solid #f0f0f1; padding-top: 15px;">
                                    <a href="#" style="color: #2271b1; text-decoration: none; font-weight: 600; font-size: 13px;"><?php _e('Booking Calendar', 'wc-tour-bookings'); ?></a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_bookings_list_page() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="wrap"><h1>' . __( 'All Bookings', 'wc-tour-bookings' ) . '</h1><p>' . __( 'WooCommerce is not active.', 'wc-tour-bookings' ) . '</p></div>';
            return;
        }
        ?>
        <div class="bokun-page-wrapper">
            <?php $this->render_bokun_sidebar('bookings'); ?>
            <div class="bokun-content-area">
                <h1 class="wp-heading-inline"><?php _e( 'All Tour Bookings', 'wc-tour-bookings' ); ?></h1>
                <hr class="wp-header-end">
                <?php
                $orders = wc_get_orders( array(
                    'status' => array( 'processing', 'completed', 'on-hold' ),
                    'limit'  => 500,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ) );

                $bookings = array();
                foreach ( $orders as $order ) {
                    foreach ( $order->get_items() as $item ) {
                        $booking_date = $item->get_meta( '_tour_booking_date' );
                        if ( $booking_date ) {
                            $bookings[] = (object) array(
                                'order_id'     => $order->get_id(),
                                'product_name' => $item->get_name(),
                                'booking_date' => $booking_date,
                                'booking_slot' => $item->get_meta( '_tour_booking_slot' ),
                                'participants' => $item->get_meta( '_tour_participants' ),
                                'qty'          => $item->get_quantity(),
                                'status'       => $order->get_status(),
                            );
                        }
                    }
                }

                usort( $bookings, function( $a, $b ) {
                    return strcmp( $b->booking_date, $a->booking_date );
                } );
                ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; border-radius: 8px; overflow: hidden; border: 1px solid #eef0f2; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <thead>
                        <tr>
                            <th><?php _e( 'Order ID', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Tour', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Date', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Time Slot', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Spots', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Participants', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Status', 'wc-tour-bookings' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $bookings ) ) : ?>
                            <?php foreach ( $bookings as $booking ) : ?>
                                <tr>
                                    <td><a href="<?php echo get_edit_post_link( $booking->order_id ); ?>"><strong>#<?php echo $booking->order_id; ?></strong></a></td>
                                    <td><?php echo esc_html( $booking->product_name ); ?></td>
                                    <td><?php echo esc_html( $booking->booking_date ); ?></td>
                                    <td><?php echo esc_html( $booking->booking_slot ? $booking->booking_slot : '-' ); ?></td>
                                    <td><?php echo esc_html( $booking->qty ); ?></td>
                                    <td><small><?php echo esc_html( $booking->participants ? $booking->participants : '-' ); ?></small></td>
                                    <td><mark class="order-status status-<?php echo esc_attr( $booking->status ); ?>" style="background: #e7f3ff; color: #0073aa; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;"><span><?php echo esc_html( wc_get_order_status_name( $booking->status ) ); ?></span></mark></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7"><?php _e( 'No bookings found.', 'wc-tour-bookings' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

// Global helper for chart
if (!function_exists('array_max_helper_v3')) {
    function array_max_helper_v3($arr) {
        return !empty($arr) ? max($arr) : 0;
    }
}

new WC_Tour_Bookings();
