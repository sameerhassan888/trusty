<?php
/**
 * Tour Product Type
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Product_Tour extends WC_Product {

    public function __construct( $product ) {
        $this->product_type = 'tour';
        parent::__construct( $product );
    }

    public function get_type() {
        return 'tour';
    }
}
