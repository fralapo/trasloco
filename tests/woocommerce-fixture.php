<?php
/**
 * WP-CLI fixture for real WooCommerce CRUD checks against both order stores.
 * Usage: wp eval-file tests/woocommerce-fixture.php seed hpos|legacy
 *        wp eval-file tests/woocommerce-fixture.php verify ORDER REFUND PRODUCT hpos|legacy
 */
if ( ! isset( $args[0] ) || ! function_exists( 'wc_get_order' ) ) {
    throw new RuntimeException( 'WooCommerce must be active for this fixture.' );
}

if ( $args[0] === 'seed' ) {
    if ( count( $args ) !== 2 || ! in_array( $args[1], array( 'hpos', 'legacy' ), true ) ) {
        throw new InvalidArgumentException( 'Expected seed hpos|legacy.' );
    }
    $expected_hpos = $args[1] === 'hpos';
    if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() !== $expected_hpos ) {
        throw new RuntimeException( 'WooCommerce is using an unexpected order store.' );
    }

    $product = new WC_Product_Simple();
    $product->set_name( 'Trasloco HPOS fixture product' );
    $product->set_regular_price( '12.50' );
    $product->set_virtual( true );
    $product_id = $product->save();

    $order = wc_create_order();
    $order->set_billing_first_name( 'Trasloco' );
    $order->set_billing_last_name( 'Fixture' );
    $order->set_billing_email( 'woo-fixture@example.test' );
    $order->set_currency( 'EUR' );
    $order->set_payment_method( 'cod' );
    $order->add_product( wc_get_product( $product_id ), 2 );
    $order->update_meta_data( '_trasloco_fixture', 'hpos-order-marker-2026' );
    $order->calculate_totals();
    $order->set_status( 'processing' );
    $order->save();

    $items = $order->get_items();
    $item = reset( $items );
    $item->add_meta_data( '_trasloco_line_fixture', 'line-meta-marker-2026', true );
    $item->save();

    $refund = wc_create_refund( array(
        'amount' => '5.00',
        'reason' => 'Trasloco migration fixture',
        'order_id' => $order->get_id(),
        'refund_payment' => false,
        'restock_items' => false,
    ) );
    if ( is_wp_error( $refund ) ) {
        throw new RuntimeException( $refund->get_error_message() );
    }
    $order->add_order_note( 'Trasloco HPOS migration note marker' );

    echo wp_json_encode( array(
        'order_id' => $order->get_id(),
        'refund_id' => $refund->get_id(),
        'product_id' => $product_id,
    ) );
    return;
}

if ( $args[0] !== 'verify' || count( $args ) !== 5 || ! in_array( $args[4], array( 'hpos', 'legacy' ), true ) ) {
    throw new InvalidArgumentException( 'Expected seed hpos|legacy or verify ORDER REFUND PRODUCT hpos|legacy.' );
}
$expected_hpos = $args[4] === 'hpos';

$order = wc_get_order( (int) $args[1] );
$refund = wc_get_order( (int) $args[2] );
$items = $order ? $order->get_items() : array();
$item = reset( $items );
$notes = $order ? wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) : array();
$checks = array(
    'expected order store remains active' => Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() === $expected_hpos,
    'order type and status' => $order && $order->get_type() === 'shop_order' && $order->get_status() === 'processing',
    'order total and currency' => $order && (float) $order->get_total() === 25.0 && $order->get_currency() === 'EUR',
    'billing email' => $order && $order->get_billing_email() === 'woo-fixture@example.test',
    'order metadata' => $order && $order->get_meta( '_trasloco_fixture' ) === 'hpos-order-marker-2026',
    'refund amount and parent' => $refund && $refund->get_type() === 'shop_order_refund' &&
        (float) $refund->get_amount() === 5.0 && (int) $refund->get_parent_id() === (int) $args[1],
    'line item product and metadata' => $item && (int) $item->get_product_id() === (int) $args[3] &&
        (int) $item->get_quantity() === 2 && $item->get_meta( '_trasloco_line_fixture' ) === 'line-meta-marker-2026',
    'order note' => count( array_filter( $notes, function ( $note ) {
        return strpos( $note->content, 'Trasloco HPOS migration note marker' ) !== false;
    } ) ) === 1,
);

foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}
if ( in_array( false, $checks, true ) ) {
    exit( 1 );
}
