<?php
/**
 * Plugin Name: WooCommerce Tour Bookings
 * Description: An alternative to Bokun for managing tour and activity bookings in WooCommerce.
 * Version: 1.0.0
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
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_date_selection' ) );

        // Cart and Order Integration
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );

        // Validation
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
        add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update_cart' ), 10, 4 );

        // Admin CSS/JS for product type visibility
        add_action( 'admin_footer', array( $this, 'admin_product_type_js' ) );
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
        ?>
        <div id="tour_bookings_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input( array(
                    'id'          => '_tour_capacity',
                    'label'       => __( 'Capacity', 'wc-tour-bookings' ),
                    'placeholder' => __( 'Maximum number of participants', 'wc-tour-bookings' ),
                    'desc_tip'    => 'true',
                    'description' => __( 'Enter the maximum number of participants for this tour.', 'wc-tour-bookings' ),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'step' => '1',
                        'min'  => '0'
                    )
                ) );
                ?>
            </div>
        </div>
        <?php
    }

    public function save_tour_bookings_data( $post_id ) {
        $capacity = isset( $_POST['_tour_capacity'] ) ? sanitize_text_field( $_POST['_tour_capacity'] ) : '';
        update_post_meta( $post_id, '_tour_capacity', $capacity );
    }

    public function enqueue_scripts() {
        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post->ID );
            if ( $product && $product->is_type( 'tour' ) ) {
                wp_enqueue_script( 'jquery-ui-datepicker' );
                wp_enqueue_style( 'jquery-ui-style', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
                wp_enqueue_script( 'wc-tour-bookings-frontend', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), '1.0.0', true );
            }
        }
    }

    public function display_date_selection() {
        global $product;
        if ( $product && $product->is_type( 'tour' ) ) {
            ?>
            <div class="tour-booking-selection" style="margin-bottom: 20px;">
                <label for="tour_booking_date"><?php _e( 'Select Date:', 'wc-tour-bookings' ); ?></label>
                <input type="text" id="tour_booking_date" name="tour_booking_date" class="tour-booking-date" readonly placeholder="<?php _e( 'Choose a date...', 'wc-tour-bookings' ); ?>">
            </div>
            <?php
        }
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_type( 'tour' ) ) {
            if ( isset( $_POST['tour_booking_date'] ) && ! empty( $_POST['tour_booking_date'] ) ) {
                $cart_item_data['tour_booking_date'] = sanitize_text_field( $_POST['tour_booking_date'] );
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
        return $item_data;
    }

    public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['tour_booking_date'] ) ) {
            $item->add_meta_data( '_tour_booking_date', $values['tour_booking_date'] );
        }
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_type( 'tour' ) ) {
            if ( ! isset( $_POST['tour_booking_date'] ) || empty( $_POST['tour_booking_date'] ) ) {
                wc_add_notice( __( 'Please select a booking date.', 'wc-tour-bookings' ), 'error' );
                return false;
            }

            $booking_date = sanitize_text_field( $_POST['tour_booking_date'] );
            if ( strtotime( $booking_date ) < strtotime( 'today' ) ) {
                wc_add_notice( __( 'Please select a valid future date.', 'wc-tour-bookings' ), 'error' );
                return false;
            }

            $capacity = get_post_meta( $product_id, '_tour_capacity', true );
            if ( ! empty( $capacity ) && $capacity > 0 ) {
                $booked_count = $this->get_booked_count( $product_id, $booking_date );
                $in_cart_count = $this->get_in_cart_count( $product_id, $booking_date );

                if ( ( $booked_count + $in_cart_count + $quantity ) > $capacity ) {
                    wc_add_notice( sprintf( __( 'Sorry, this tour is full for %s. Only %d spots left.', 'wc-tour-bookings' ), $booking_date, max( 0, $capacity - $booked_count - $in_cart_count ) ), 'error' );
                    return false;
                }
            }
        }
        return $passed;
    }

    public function validate_update_cart( $passed, $cart_item_key, $values, $quantity ) {
        $cart_item = WC()->cart->get_cart_item( $cart_item_key );
        if ( isset( $cart_item['tour_booking_date'] ) ) {
            $product_id = $cart_item['product_id'];
            $booking_date = $cart_item['tour_booking_date'];
            $capacity = get_post_meta( $product_id, '_tour_capacity', true );

            if ( ! empty( $capacity ) && $capacity > 0 ) {
                $booked_count = $this->get_booked_count( $product_id, $booking_date );
                $in_cart_count = $this->get_in_cart_count( $product_id, $booking_date, $cart_item_key );

                if ( ( $booked_count + $in_cart_count + $quantity ) > $capacity ) {
                    wc_add_notice( sprintf( __( 'Sorry, you cannot increase the spots to %d. Only %d spots left for %s.', 'wc-tour-bookings' ), $quantity, max( 0, $capacity - $booked_count - $in_cart_count ), $booking_date ), 'error' );
                    return false;
                }
            }
        }
        return $passed;
    }

    private function get_booked_count( $product_id, $date ) {
        global $wpdb;

        $valid_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
        $placeholders   = implode( ',', array_fill( 0, count( $valid_statuses ), '%s' ) );

        $query = $wpdb->prepare(
            "SELECT SUM(item_meta_qty.meta_value)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_date
             INNER JOIN {$wpdb->prefix}woocommerce_order_items as items ON item_meta_date.order_item_id = items.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_product ON items.order_item_id = item_meta_product.order_item_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as item_meta_qty ON items.order_item_id = item_meta_qty.order_item_id
             INNER JOIN {$wpdb->prefix}posts as posts ON items.order_id = posts.ID
             WHERE item_meta_date.meta_key = '_tour_booking_date'
             AND item_meta_date.meta_value = %s
             AND item_meta_product.meta_key = '_product_id'
             AND item_meta_product.meta_value = %d
             AND item_meta_qty.meta_key = '_qty'
             AND posts.post_status IN ($placeholders)",
            array_merge( array( $date, $product_id ), $valid_statuses )
        );

        $count = $wpdb->get_var( $query );
        return (int) $count;
    }

    private function get_in_cart_count( $product_id, $date, $exclude_cart_item_key = '' ) {
        $count = 0;
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                if ( $cart_item_key === $exclude_cart_item_key ) {
                    continue;
                }
                if ( $cart_item['product_id'] == $product_id && isset( $cart_item['tour_booking_date'] ) && $cart_item['tour_booking_date'] === $date ) {
                    $count += $cart_item['quantity'];
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
                // Force WooCommerce to re-evaluate visibility
                $('select#product-type').trigger('change');
            });
        </script>
        <?php
    }
}

new WC_Tour_Bookings();
