<?php
/**
 * Plugin Name: WooCommerce Tour Bookings
 * Description: A powerful alternative to Bokun for managing tour and activity bookings in WooCommerce.
 * Version: 2.1.1
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

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
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
            wp_enqueue_script( 'wc-tour-bookings-frontend', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), '2.1.1', true );
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
            __( 'All Bookings', 'wc-tour-bookings' ),
            __( 'All Bookings', 'wc-tour-bookings' ),
            'manage_woocommerce',
            'wc-tour-bookings-list',
            array( $this, 'admin_bookings_list_page' )
        );
    }

    public function admin_dashboard_page() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="wrap"><h1>' . __( 'Tour Bookings Dashboard', 'wc-tour-bookings' ) . '</h1><p>' . __( 'WooCommerce is not active.', 'wc-tour-bookings' ) . '</p></div>';
            return;
        }
        $today_date = wp_date('Y-m-d');
        $orders = wc_get_orders( array(
            'status' => array( 'processing', 'completed', 'on-hold' ),
            'limit'  => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ) );

        $total_bookings = 0;
        $total_spots = 0;
        $today_bookings = 0;

        $bookings_by_date = array();

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $booking_date = $item->get_meta( '_tour_booking_date' );
                if ( $booking_date ) {
                    $total_bookings++;
                    $qty = (int) $item->get_quantity();
                    $total_spots += $qty;
                    if ( $booking_date === $today_date ) {
                        $today_bookings += $qty;
                    }

                    if ( ! isset( $bookings_by_date[$booking_date] ) ) {
                        $bookings_by_date[$booking_date] = 0;
                    }
                    $bookings_by_date[$booking_date] += $qty;
                }
            }
        }
        ksort($bookings_by_date);
        ?>
        <style>
            .bokun-dashboard { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin-top: 20px; }
            .bokun-header { background: #fff; padding: 25px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 25px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .bokun-stats { display: flex; gap: 20px; margin-bottom: 25px; }
            .bokun-stat-card { background: #fff; padding: 20px; border-left: 4px solid #2271b1; border-radius: 4px; flex: 1; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .bokun-stat-value { font-size: 28px; font-weight: 700; color: #1d2327; }
            .bokun-stat-label { font-size: 14px; color: #646970; margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
            .bokun-section { background: #fff; padding: 25px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .bokun-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .bokun-table th, .bokun-table td { text-align: left; padding: 15px; border-bottom: 1px solid #f0f0f1; }
            .bokun-table th { background: #f6f7f7; font-weight: 600; color: #1d2327; }
            .bokun-table tr:hover { background-color: #f6f7f7; }
        </style>
        <div class="wrap bokun-dashboard">
            <div class="bokun-header">
                <h1><?php _e( 'Tour Bookings Dashboard', 'wc-tour-bookings' ); ?></h1>
                <p><?php _e( 'Welcome to your Bokun-style management hub. Monitor your tours and participants in real-time.', 'wc-tour-bookings' ); ?></p>
            </div>

            <div class="bokun-stats">
                <div class="bokun-stat-card">
                    <div class="bokun-stat-value"><?php echo $total_bookings; ?></div>
                    <div class="bokun-stat-label"><?php _e( 'Recent Bookings', 'wc-tour-bookings' ); ?></div>
                </div>
                <div class="bokun-stat-card">
                    <div class="bokun-stat-value"><?php echo $total_spots; ?></div>
                    <div class="bokun-stat-label"><?php _e( 'Total Spots (Recent)', 'wc-tour-bookings' ); ?></div>
                </div>
                <div class="bokun-stat-card" style="border-left-color: #d63638;">
                    <div class="bokun-stat-value"><?php echo $today_bookings; ?></div>
                    <div class="bokun-stat-label"><?php _e( 'Participants Today', 'wc-tour-bookings' ); ?></div>
                </div>
            </div>

            <div class="bokun-section">
                <h2><?php _e( 'Upcoming Availability Overview', 'wc-tour-bookings' ); ?></h2>
                <table class="bokun-table">
                    <thead>
                        <tr>
                            <th><?php _e( 'Date', 'wc-tour-bookings' ); ?></th>
                            <th><?php _e( 'Booked Spots', 'wc-tour-bookings' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $bookings_by_date ) ) : ?>
                            <?php foreach ( array_slice($bookings_by_date, 0, 10, true) as $date => $spots ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $date ); ?></strong></td>
                                    <td><span class="badge" style="background: #e7f3ff; color: #0073aa; padding: 4px 8px; border-radius: 12px; font-weight: 600;"><?php echo esc_html( $spots ); ?> <?php _e( 'spots', 'wc-tour-bookings' ); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="2"><?php _e( 'No upcoming bookings detected in recent orders.', 'wc-tour-bookings' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p style="margin-top: 20px;"><a href="<?php echo admin_url('admin.php?page=wc-tour-bookings-list'); ?>" class="button button-primary button-large"><?php _e( 'View Detailed Bookings List', 'wc-tour-bookings' ); ?></a></p>
            </div>
        </div>
        <?php
    }

    public function admin_bookings_list_page() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="wrap"><h1>' . __( 'All Bookings', 'wc-tour-bookings' ) . '</h1><p>' . __( 'WooCommerce is not active.', 'wc-tour-bookings' ) . '</p></div>';
            return;
        }
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
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e( 'All Tour Bookings', 'wc-tour-bookings' ); ?></h1>
            <hr class="wp-header-end">
            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
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
                                <td><mark class="order-status status-<?php echo esc_attr( $booking->status ); ?>" style="background: #e5e5e5; padding: 4px 8px; border-radius: 4px;"><span><?php echo esc_html( wc_get_order_status_name( $booking->status ) ); ?></span></mark></td>
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
        <?php
    }
}

new WC_Tour_Bookings();
