<?php
/** Standalone, non-executing SQL inventory regression tests. */
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-archiver.php';
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-extractor.php';
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-sql-preview.php';

function sql_preview_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function sql_preview_archive( $sql ) {
    $archive = sys_get_temp_dir() . '/trasloco-sql-preview-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
    $body = pack( 'a255a14a12a4096', 'package.json', 2, '0', '.' ) . '{}';
    $body .= pack( 'a255a14a12a4096', 'database.sql', strlen( $sql ), '0', '.' ) . $sql;
    $body .= str_repeat( chr( 0 ), 4377 );
    file_put_contents( $archive, $body );
    file_put_contents( $archive . '.manifest.json', json_encode( array(
        'manifest_version' => 1, 'algorithm' => 'sha256',
        'size_bytes' => strlen( $body ), 'sha256' => hash( 'sha256', $body ),
    ) ) );
    return $archive;
}

$archive = sql_preview_archive(
    "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (\n `ID` bigint NOT NULL\n);\n" .
    "INSERT INTO `wp_posts` VALUES (1,'text; still data');\nINSERT INTO `wp_posts` VALUES (2);\n" .
    "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (`option_id` bigint);\n"
);
try {
    $preview = Trasloco_Offline_SQL_Preview::preview( $archive );
    sql_preview_assert( $preview['tables_seen'] === 2, 'The SQL inventory should identify each table.' );
    sql_preview_assert( $preview['tables']['wp_posts']['drop'] === 1 &&
        $preview['tables']['wp_posts']['create'] === 1 && $preview['tables']['wp_posts']['insert'] === 2,
        'The SQL inventory should count per-table operations without parsing row values.' );
    sql_preview_assert( strpos( $preview['warning'], 'does not execute SQL' ) !== false,
        'The report should state its read-only limitation.' );
    try {
        Trasloco_Offline_SQL_Preview::preview( $archive, 8 );
        throw new RuntimeException( 'SQL preview ignored the byte limit.' );
    } catch ( RuntimeException $expected ) {
        sql_preview_assert( strpos( $expected->getMessage(), 'byte limit' ) !== false,
            'Oversized SQL should fail with a bounded diagnostic.' );
    }
} finally {
    @unlink( $archive . '.manifest.json' );
    @unlink( $archive );
}

$legacy = sys_get_temp_dir() . '/trasloco-sql-preview-legacy-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
$body = pack( 'a255a14a12a4096', 'package.json', 2, '0', '.' ) . '{}';
$legacy_sql = 'SELECT 1;';
$body .= pack( 'a255a14a12a4096', 'database.sql', strlen( $legacy_sql ), '0', '.' ) . $legacy_sql;
$body .= str_repeat( chr( 0 ), 4377 );
file_put_contents( $legacy, $body );
$legacy_rejected = false;
try {
    Trasloco_Offline_SQL_Preview::preview( $legacy );
} catch ( RuntimeException $expected ) {
    $legacy_rejected = strpos( $expected->getMessage(), 'SHA-256-verified' ) !== false;
} finally {
    @unlink( $legacy );
}
sql_preview_assert( $legacy_rejected, 'Unverified archives should fail closed.' );
echo "Offline SQL preview smoke tests passed.\n";
