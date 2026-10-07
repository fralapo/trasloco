<?php
/**
 * Checks languages/translations.php against the code.
 *
 *   php tools/check-translations.php
 *
 * Fails (exit 1) when a string used in the code is missing, when a language
 * is missing, or when a translation lost a placeholder (%s, %d, %%) or an
 * HTML tag. Warns about strings in the file that the code no longer uses.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Command line only: inside the published plugin it must not answer the web
if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$root   = dirname( __DIR__ );
$langs  = array( 'it', 'es', 'fr', 'de' );
$domain = 'trasloco';
$ignore = array( 'tools/', 'languages/' );

$functions = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'trasloco_translate' );

function literal( $token ) {
	$body = substr( $token, 1, -1 );
	if ( "'" === $token[0] ) {
		return strtr( $body, array( '\\\\' => '\\', "\\'" => "'" ) );
	}
	return stripcslashes( $body );
}

$used  = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( substr( $file, -4 ) !== '.php' ) {
		continue;
	}
	$rel = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
	foreach ( $ignore as $prefix ) {
		if ( strpos( $rel, $prefix ) === 0 ) {
			continue 2;
		}
	}

	$tokens = array_values( array_filter( token_get_all( file_get_contents( $file ) ), function ( $t ) {
		return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	} ) );

	for ( $i = 1, $n = count( $tokens ); $i < $n - 2; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], $functions, true ) || '(' !== $tokens[ $i + 1 ] ) {
			continue;
		}
		// $obj->__() and Foo::__() are not gettext calls
		if ( is_array( $tokens[ $i - 1 ] ) && in_array( $tokens[ $i - 1 ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
			continue;
		}
		$text = '';
		$j    = $i + 2;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
			$text .= literal( $tokens[ $j ][1] );
			$j    += ( isset( $tokens[ $j + 1 ] ) && '.' === $tokens[ $j + 1 ] ) ? 2 : 1;
		}
		if ( '' === $text ) {
			continue;
		}
		$arg = isset( $tokens[ $j + 1 ] ) && is_array( $tokens[ $j + 1 ] ) ? $tokens[ $j + 1 ] : null;
		$own = 'trasloco_translate' === $tokens[ $i ][1]
			|| ( ',' === $tokens[ $j ] && $arg && ( 'TRASLOCO_PLUGIN_NAME' === $arg[1] || ( T_CONSTANT_ENCAPSED_STRING === $arg[0] && literal( $arg[1] ) === $domain ) ) );
		if ( $own ) {
			$used[ $text ][] = $rel . ':' . $tokens[ $i ][2];
		}
	}
}

// Plugin header: WordPress translates Description and Author with our text domain
$header = file_get_contents( $root . '/trasloco.php', false, null, 0, 2048 );
foreach ( array( 'Description', 'Author' ) as $field ) {
	if ( preg_match( '/^ \* ' . $field . ': (.+)$/m', $header, $m ) ) {
		$used[ trim( $m[1] ) ][] = 'trasloco.php header';
	}
}

$strings = include $root . '/languages/translations.php';
$errors  = 0;

function shape( $s ) {
	preg_match_all( '/%(?:\d+\$)?[sd%]/', $s, $p );
	preg_match_all( '/<\/?(a|b|br|code|em|i|p|span|strong)\b/i', $s, $t );
	$p = $p[0];
	$t = array_map( 'strtolower', $t[1] );
	sort( $p );
	sort( $t );
	return json_encode( array( $p, $t ) );
}

foreach ( $used as $text => $where ) {
	if ( ! isset( $strings[ $text ] ) ) {
		echo "MISSING  {$where[0]}\n         " . $text . "\n";
		$errors++;
		continue;
	}
	foreach ( $langs as $lang ) {
		if ( empty( $strings[ $text ][ $lang ] ) ) {
			echo "LANGUAGE {$lang} missing: " . $text . "\n";
			$errors++;
		} elseif ( shape( $strings[ $text ][ $lang ] ) !== shape( $text ) ) {
			echo "SHAPE    {$lang}: placeholders or tags differ in: " . $text . "\n";
			$errors++;
		}
	}
}

foreach ( array_diff_key( $strings, $used ) as $text => $unused ) {
	echo "WARNING  not used by the code: " . $text . "\n";
}

printf( "%d strings used, %d in the file, languages: %s. Errors: %d\n", count( $used ), count( $strings ), implode( ', ', $langs ), $errors );
exit( $errors ? 1 : 0 );
