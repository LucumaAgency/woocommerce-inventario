<?php
/**
 * Roles y capacidades del plugin.
 *
 * @package Multisede_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona los roles personalizados: gerente de sede y cajero.
 */
class MSP_Roles {

	/**
	 * Capacidades propias del plugin.
	 *
	 * @return array
	 */
	public static function capacidades() {
		return array(
			'msp_gestionar_sedes',     // Crear/editar sedes (admin).
			'msp_gestionar_stock',     // Ajustar stock por sede.
			'msp_ver_stock',           // Ver inventario de su sede.
			'msp_usar_pos',            // Vender en mostrador.
			'msp_gestionar_caja',      // Abrir/cerrar caja, arqueo.
			'msp_ver_reportes',        // Reportes por sede.
		);
	}

	/**
	 * Crea los roles al activar el plugin.
	 */
	public static function crear_roles() {
		// Gerente de sede: ve y ajusta su sede, caja y reportes.
		add_role(
			'msp_gerente_sede',
			__( 'Gerente de sede', 'multisede-pos' ),
			array(
				'read'                 => true,
				'msp_ver_stock'        => true,
				'msp_gestionar_stock'  => true,
				'msp_usar_pos'         => true,
				'msp_gestionar_caja'   => true,
				'msp_ver_reportes'     => true,
			)
		);

		// Cajero: solo POS y su caja.
		add_role(
			'msp_cajero',
			__( 'Cajero', 'multisede-pos' ),
			array(
				'read'               => true,
				'msp_ver_stock'      => true,
				'msp_usar_pos'       => true,
				'msp_gestionar_caja' => true,
			)
		);

		// El administrador recibe todas las capacidades.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capacidades() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Elimina los roles al desinstalar.
	 */
	public static function eliminar_roles() {
		remove_role( 'msp_gerente_sede' );
		remove_role( 'msp_cajero' );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capacidades() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Devuelve las sedes asignadas a un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return int[] Lista de IDs de sede.
	 */
	public static function sedes_de_usuario( $user_id ) {
		$sedes = get_user_meta( $user_id, '_msp_sedes', true );
		return is_array( $sedes ) ? array_map( 'absint', $sedes ) : array();
	}
}
