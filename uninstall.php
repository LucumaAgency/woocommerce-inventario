<?php
/**
 * Desinstalación: borra tablas, roles y metadatos del plugin.
 *
 * @package Multisede_POS
 */

// Solo se ejecuta desde el proceso de desinstalación de WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Borrar tablas propias.
$tablas = array(
	$wpdb->prefix . 'msp_stock',
	$wpdb->prefix . 'msp_caja_sesiones',
	$wpdb->prefix . 'msp_caja_movimientos',
);
foreach ( $tablas as $tabla ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$tabla}" );
}

// Borrar las sedes (CPT) y sus metadatos.
$sedes = get_posts(
	array(
		'post_type'      => 'msp_sede',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	)
);
foreach ( $sedes as $sede_id ) {
	wp_delete_post( $sede_id, true );
}

// Quitar roles y capacidades.
remove_role( 'msp_gerente_sede' );
remove_role( 'msp_cajero' );

$admin = get_role( 'administrator' );
if ( $admin ) {
	$caps = array(
		'msp_gestionar_sedes',
		'msp_gestionar_stock',
		'msp_ver_stock',
		'msp_usar_pos',
		'msp_gestionar_caja',
		'msp_ver_reportes',
	);
	foreach ( $caps as $cap ) {
		$admin->remove_cap( $cap );
	}
}

// Opciones.
delete_option( 'msp_db_version' );

// Metadatos de usuario (asignación de sedes).
delete_metadata( 'user', 0, '_msp_sedes', '', true );
