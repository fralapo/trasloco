<?php
/**
 * WP-CLI view regression for the conditional live WooCommerce export warning.
 * Usage: wp eval-file tests/export-consistency-warning.php present|absent
 */
if ( ! isset( $args[0] ) || ! in_array( $args[0], array( 'present', 'absent' ), true ) ) {
	throw new InvalidArgumentException( 'Expected present or absent.' );
}

$woocommerce_active = function_exists( 'wc_get_order' );
$expect_warning = $args[0] === 'present';
if ( $woocommerce_active !== $expect_warning ) {
	throw new RuntimeException( 'WooCommerce fixture activation does not match the expected state.' );
}

ob_start();
include TRASLOCO_TEMPLATES_PATH . '/export/index.php';
$html = ob_get_clean();
$warning_present = strpos( $html, 'WooCommerce order consistency' ) !== false &&
	strpos( $html, 'pause checkout, webhooks' ) !== false;
if ( $warning_present !== $expect_warning ) {
	throw new RuntimeException( 'The export page showed an unexpected WooCommerce consistency warning state.' );
}

echo 'PASS: WooCommerce consistency warning ' . $args[0] . PHP_EOL;
