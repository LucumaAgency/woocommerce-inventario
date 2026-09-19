/* global jQuery, mspPOS */
( function ( $ ) {
	'use strict';

	var ticket = {}; // id -> { id, nombre, precio, qty, desc }

	function fmt( valor ) {
		return mspPOS.simbolo + ' ' + Number( valor ).toFixed( mspPOS.decimals );
	}

	// Importe de lista de una línea, antes de su descuento.
	function brutoLinea( it ) {
		return Math.round( it.precio * it.qty * 100 ) / 100;
	}

	// Descuento de UNA línea, acotado a la propia línea: nunca negativo y
	// nunca tanto como para dejarla en cero, que no es una venta.
	function descLinea( it ) {
		var bruto = brutoLinea( it );
		var d = Number( it.desc );
		if ( ! d || d < 0 || ! bruto ) {
			return 0;
		}
		d = Math.round( d * 100 ) / 100;
		var techo = Math.round( ( bruto - 0.01 ) * 100 ) / 100;
		return d > techo ? techo : d;
	}

	// Lo que se cobra por esa línea.
	function netoLinea( it ) {
		return Math.round( ( brutoLinea( it ) - descLinea( it ) ) * 100 ) / 100;
	}

	// Suma del ticket a precio de lista.
	function subtotalTicket() {
		var t = 0;
		$.each( ticket, function ( _, it ) {
			t += brutoLinea( it );
		} );
		return Math.round( t * 100 ) / 100;
	}

	// Suma de los descuentos de todas las líneas.
	function descuentoTicket() {
		var t = 0;
		$.each( ticket, function ( _, it ) {
			t += descLinea( it );
		} );
		return Math.round( t * 100 ) / 100;
	}

	// Lo que paga el cliente. Es el número que manda en TODAS partes: vuelto,
	// límite del DNI y el importe que va al servidor.
	function totalTicket() {
		return Math.round( ( subtotalTicket() - descuentoTicket() ) * 100 ) / 100;
	}

	function pintarTicket() {
		var $body = $( '#msp-pos-items' );
		$body.empty();

		var ids = Object.keys( ticket );
		if ( ! ids.length ) {
			$body.append(
				'<tr class="msp-pos-vacio"><td colspan="4">' + mspPOS.i18n.vacio + '</td></tr>'
			);
			$( '#msp-pos-subtotal' ).text( '—' );
			$( '#msp-pos-descuento-total' ).text( '—' );
			$( '#msp-pos-total' ).text( '—' );
			calcularVuelto();
			return;
		}

		ids.forEach( function ( id ) {
			var it = ticket[ id ];
			var $tr = $( '<tr/>' ).attr( 'data-id', id );
			$tr.append( $( '<td/>' ).text( it.nombre ) );
			$tr.append(
				$( '<td/>' ).append(
					$( '<input type="number" min="1" class="msp-qty" />' ).val( it.qty )
				)
			);
			$tr.append(
				$( '<td/>' ).append(
					$( '<input type="number" min="0" step="0.01" class="msp-desc" placeholder="0.00" />' )
						.val( it.desc ? it.desc : '' )
				)
			);
			var $importe = $( '<td/>' ).text( fmt( netoLinea( it ) ) );
			if ( descLinea( it ) > 0 ) {
				// El precio de lista se queda a la vista, tachado: el cliente
				// tiene que poder ver de dónde sale la rebaja.
				$importe.append( $( '<s class="msp-lista"/>' ).text( ' ' + fmt( brutoLinea( it ) ) ) );
			}
			$tr.append( $importe );
			$tr.append(
				$( '<td/>' ).append(
					$( '<a href="#" class="msp-pos-quitar">&times;</a>' )
				)
			);
			$body.append( $tr );
		} );

		$( '#msp-pos-subtotal' ).text( fmt( subtotalTicket() ) );
		$( '#msp-pos-descuento-total' ).text( descuentoTicket() > 0 ? '− ' + fmt( descuentoTicket() ) : '—' );
		$( '#msp-pos-total' ).text( fmt( totalTicket() ) );
		if ( typeof avisarDni === 'function' ) {
			avisarDni();
		}
		calcularVuelto();
	}

	function agregar( prod ) {
		if ( ticket[ prod.id ] ) {
			ticket[ prod.id ].qty += 1;
		} else {
			ticket[ prod.id ] = {
				id: prod.id,
				nombre: prod.nombre,
				precio: prod.precio,
				qty: 1,
				desc: 0
			};
		}
		pintarTicket();
	}

	function calcularVuelto() {
		var metodo = $( '#msp-pos-metodo' ).val();
		var $wrap = $( '#msp-pos-efectivo-wrap' );
		if ( 'efectivo' !== metodo ) {
			$wrap.hide();
			$( '#msp-pos-vuelto' ).text( '' );
			return;
		}
		$wrap.show();
		var recibido = parseFloat( $( '#msp-pos-recibido' ).val() ) || 0;
		var vuelto = recibido - totalTicket();
		if ( recibido > 0 ) {
			$( '#msp-pos-vuelto' ).text( mspPOS.i18n.vuelto + ': ' + fmt( vuelto >= 0 ? vuelto : 0 ) );
		} else {
			$( '#msp-pos-vuelto' ).text( '' );
		}
	}

	// Búsqueda de productos (con debounce).
	var timer = null;
	$( '#msp-pos-buscar' ).on( 'keyup', function () {
		var term = $( this ).val();
		clearTimeout( timer );
		if ( term.length < 2 ) {
			$( '#msp-pos-resultados' ).empty();
			return;
		}
		timer = setTimeout( function () {
			$.get(
				mspPOS.ajaxurl,
				{
					action: 'msp_pos_buscar',
					nonce: mspPOS.nonce,
					term: term,
					sede: $( '#msp-pos-sede' ).val()
				},
				function ( resp ) {
					var $ul = $( '#msp-pos-resultados' ).empty();
					if ( ! resp.success || ! resp.data.length ) {
						$ul.append( '<li class="msp-no-stock">' + mspPOS.i18n.sin_resultados + '</li>' );
						return;
					}
					resp.data.forEach( function ( p ) {
						var sinStock = ( p.stock !== null && p.stock <= 0 );
						var meta = ( p.sku ? p.sku + ' · ' : '' ) +
							( p.stock !== null ? ( sinStock ? mspPOS.i18n.sin_stock : 'Stock: ' + p.stock ) : '' );
						var $li = $( '<li/>' )
							.toggleClass( 'msp-no-stock', sinStock )
							.append( $( '<span/>' ).html(
								'<strong>' + $( '<i/>' ).text( p.nombre ).html() + '</strong>' +
								'<br><span class="msp-prod-meta">' + $( '<i/>' ).text( meta ).html() + '</span>'
							) )
							.append( $( '<span/>' ).text( fmt( p.precio ) ) );
						if ( ! sinStock ) {
							$li.on( 'click', function () {
								agregar( p );
							} );
						}
						$ul.append( $li );
					} );
				}
			);
		}, 250 );
	} );

	// Cambios de cantidad / quitar.
	$( '#msp-pos-items' ).on( 'change', '.msp-qty', function () {
		var id = $( this ).closest( 'tr' ).data( 'id' );
		var q = parseInt( $( this ).val(), 10 );
		if ( ticket[ id ] && q >= 1 ) {
			ticket[ id ].qty = q;
		}
		pintarTicket();
	} );
	// Descuento de la línea. Se repinta al soltar el campo, no en cada tecla,
	// para no reescribir el valor mientras el cajero lo está escribiendo.
	$( '#msp-pos-items' ).on( 'change blur', '.msp-desc', function () {
		var id = $( this ).closest( 'tr' ).data( 'id' );
		var d = parseFloat( $( this ).val() );
		if ( ticket[ id ] ) {
			ticket[ id ].desc = ! d || d < 0 ? 0 : Math.round( d * 100 ) / 100;
		}
		pintarTicket();
	} );
	$( '#msp-pos-items' ).on( 'click', '.msp-pos-quitar', function ( e ) {
		e.preventDefault();
		var id = $( this ).closest( 'tr' ).data( 'id' );
		delete ticket[ id ];
		pintarTicket();
	} );

	$( '#msp-pos-metodo, #msp-pos-recibido' ).on( 'change keyup', calcularVuelto );

	// DNI: solo dígitos, y aviso en cuanto el ticket pasa del límite. El aviso
	// aquí es cortesía para que el cajero lo pida a tiempo; quien realmente
	// impide el cobro es el servidor.
	function dniValor() {
		return ( $( '#msp-pos-dni' ).val() || '' ).replace( /[^0-9]/g, '' );
	}

	function nombreValor() {
		return $.trim( $( '#msp-pos-cliente-nombre' ).val() || '' );
	}

	function esFactura() {
		return 'factura' === ( $( '#msp-pos-tipo' ).val() || 'boleta' );
	}

	function rucValor() {
		return ( $( '#msp-pos-ruc' ).val() || '' ).replace( /[^0-9]/g, '' );
	}

	function razonValor() {
		return $.trim( $( '#msp-pos-razon-social' ).val() || '' );
	}

	// El dígito verificador del RUC, comprobado aquí para avisar mientras el
	// cajero teclea. El servidor lo vuelve a comprobar: esto es comodidad, no
	// seguridad.
	function rucValido( ruc ) {
		if ( ruc.length !== 11 ) { return false; }
		if ( [ '10', '15', '17', '20' ].indexOf( ruc.slice( 0, 2 ) ) === -1 ) { return false; }
		var pesos = [ 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 ];
		var suma = 0;
		for ( var i = 0; i < 10; i++ ) { suma += parseInt( ruc[ i ], 10 ) * pesos[ i ]; }
		var v = 11 - ( suma % 11 );
		if ( v === 10 ) { v = 0; }
		if ( v === 11 ) { v = 1; }
		return parseInt( ruc[ 10 ], 10 ) === v;
	}

	function faltaFactura() {
		if ( ! esFactura() ) { return ''; }
		if ( ! rucValor() ) { return mspPOS.i18n.falta_ruc; }
		if ( ! rucValido( rucValor() ) ) { return mspPOS.i18n.ruc_corto; }
		if ( ! razonValor() ) { return mspPOS.i18n.falta_razon; }
		return '';
	}

	// Muestra u oculta los campos de factura, y avisa si la tienda elegida no
	// puede emitirlas: mejor saberlo al elegir que al cobrar.
	function sincronizarTipo() {
		var $sel = $( '#msp-pos-tipo' );
		if ( ! $sel.length ) { return; }

		var factura = esFactura();
		$( '#msp-pos-factura' ).toggle( factura );
		// En una factura el comprador va identificado por RUC: el DNI sobra.
		$( '#msp-pos-cliente' ).toggle( ! factura );

		var permitidas = $sel.data( 'sedes-factura' ) || [];
		var sede = parseInt( $( '#msp-pos-sede' ).val(), 10 );
		var puede = permitidas.indexOf( sede ) !== -1;

		$( '#msp-pos-tipo-aviso' )
			.text( factura && ! puede ? mspPOS.i18n.sede_sin_serie : '' )
			.css( 'color', '#b32d2e' );

		// El aviso del padrón (RUC de baja, no habido, sin razón social) solo se
		// ve cuando no falta ningún dato: primero lo que impide cobrar.
		$( '#msp-pos-ruc-aviso' ).text( faltaFactura() || rucAviso ).css( 'color', '#b32d2e' );
	}

	$( '#msp-pos-tipo, #msp-pos-sede' ).on( 'change', sincronizarTipo );

	// Ticket de prueba de la sede elegida, en otra pestaña: no toca el ticket
	// que el cajero esté armando.
	$( '#msp-pos-prueba' ).on( 'click', function () {
		window.open( mspPOS.pruebaUrl + '&sede=' + encodeURIComponent( $( '#msp-pos-sede' ).val() ), '_blank', 'noopener' );
	} );
	// Consulta del RUC: la razón social se rellena sola en cuanto el número
	// está completo. Es una ayuda, no un requisito — si el padrón no contesta,
	// el campo se queda editable y el cajero lo escribe como hasta ahora.
	var rucUltimo = '';
	var rucTimer = null;
	// Lo que dijo el padrón sobre este RUC. Vive aparte porque sincronizarTipo()
	// reescribe ese mismo aviso y si no se perdería en el siguiente repintado.
	var rucAviso = '';

	function buscarRazon() {
		if ( ! mspPOS.ruc || ! mspPOS.ruc.activa ) {
			return;
		}
		var ruc = rucValor();
		if ( 11 !== ruc.length || ruc === rucUltimo ) {
			return;
		}
		rucUltimo = ruc;

		rucAviso = '';
		$( '#msp-pos-ruc-aviso' ).text( mspPOS.i18n.ruc_buscando ).css( 'color', '' );

		$.post( mspPOS.ajaxurl, {
			action: 'msp_consultar_ruc',
			nonce: mspPOS.ruc.nonce,
			ruc: ruc
		} ).done( function ( resp ) {
			// Entre la consulta y la respuesta el cajero pudo cambiar el RUC.
			if ( rucValor() !== ruc ) {
				return;
			}
			if ( ! resp || ! resp.success || ! resp.data || ! resp.data.encontrado ) {
				rucAviso = mspPOS.i18n.ruc_sin_datos;
				sincronizarTipo();
				return;
			}
			// No se pisa lo que el cajero ya escribió: si hay un nombre puesto
			// a mano, manda el suyo.
			if ( ! razonValor() ) {
				$( '#msp-pos-razon-social' ).val( resp.data.razon_social );
			}
			rucAviso = resp.data.aviso || '';
			sincronizarTipo();
		} ).fail( function () {
			if ( rucValor() === ruc ) {
				rucAviso = mspPOS.i18n.ruc_sin_datos;
				sincronizarTipo();
			}
		} );
	}

	$( '#msp-pos-ruc' ).on( 'input', function () {
		this.value = this.value.replace( /[^0-9]/g, '' ).slice( 0, 11 );
		if ( 11 !== rucValor().length ) {
			rucAviso = '';
		}
		sincronizarTipo();
		clearTimeout( rucTimer );
		rucTimer = setTimeout( buscarRazon, 250 );
	} );
	$( '#msp-pos-razon-social' ).on( 'input', sincronizarTipo );
	sincronizarTipo();

	// Por encima del límite hacen falta LAS DOS COSAS: documento y nombre. Una
	// boleta de S/ 900 con DNI real a nombre de "CLIENTE VARIOS" es
	// contradictoria, y así saldría impresa.
	function faltaIdentificar() {
		// La factura ya identifica al comprador con su RUC: el límite del DNI
		// es una regla de las boletas.
		if ( ! mspPOS.boletas || esFactura() || totalTicket() <= mspPOS.limiteDni ) {
			return '';
		}
		if ( dniValor().length !== 8 && ! nombreValor() ) {
			return mspPOS.i18n.dni_requerido;
		}
		if ( dniValor().length !== 8 ) {
			return mspPOS.i18n.falta_dni;
		}
		if ( ! nombreValor() ) {
			return mspPOS.i18n.falta_nombre;
		}
		return '';
	}

	function avisarDni() {
		if ( ! mspPOS.boletas ) {
			return;
		}
		$( '#msp-pos-dni-aviso' ).text( faltaIdentificar() ).css( 'color', '#b32d2e' );
	}

	$( '#msp-pos-dni' ).on( 'input', function () {
		this.value = this.value.replace( /[^0-9]/g, '' ).slice( 0, 8 );
		avisarDni();
	} );
	$( '#msp-pos-cliente-nombre' ).on( 'input', avisarDni );

	// Cobrar.
	$( '#msp-pos-cobrar' ).on( 'click', function () {
		var $msg = $( '#msp-pos-mensaje' ).empty();
		var ids = Object.keys( ticket );
		if ( ! ids.length ) {
			$msg.html( '<span class="err">' + mspPOS.i18n.vacio + '</span>' );
			return;
		}
		var dni = dniValor();
		if ( mspPOS.boletas && dni && dni.length !== 8 ) {
			$msg.html( '<span class="err">' + mspPOS.i18n.dni_corto + '</span>' );
			return;
		}
		var faltaF = faltaFactura();
		if ( faltaF ) {
			$msg.html( '<span class="err">' + faltaF + '</span>' );
			$( rucValor().length === 11 ? '#msp-pos-razon-social' : '#msp-pos-ruc' ).trigger( 'focus' );
			return;
		}
		var falta = faltaIdentificar();
		if ( falta ) {
			$msg.html( '<span class="err">' + falta + '</span>' );
			$( dni.length === 8 ? '#msp-pos-cliente-nombre' : '#msp-pos-dni' ).trigger( 'focus' );
			return;
		}
		if ( ! window.confirm( mspPOS.i18n.confirmar ) ) {
			return;
		}

		var items = ids.map( function ( id ) {
			return {
				id: ticket[ id ].id,
				qty: ticket[ id ].qty,
				desc: descLinea( ticket[ id ] )
			};
		} );

		var $btn = $( this ).prop( 'disabled', true );

		cobrar( $btn, $msg, items, dni );
	} );

	// Cobro, aislado para poder reintentarlo tras abrir la caja sin que el
	// cajero tenga que volver a armar el ticket.
	function cobrar( $btn, $msg, items, dni ) {
		$.post(
			mspPOS.ajaxurl,
			{
				action: 'msp_pos_cobrar',
				nonce: mspPOS.nonce,
				sede: $( '#msp-pos-sede' ).val(),
				metodo: $( '#msp-pos-metodo' ).val(),
				dni: dni,
				cliente_nombre: $( '#msp-pos-cliente-nombre' ).val() || '',
				tipo_comprobante: esFactura() ? 'factura' : 'boleta',
				ruc: rucValor(),
				razon_social: razonValor(),
				items: JSON.stringify( items )
			}
		).done( function ( resp ) {
			manejarRespuesta( resp, $btn, $msg, items, dni );
		} ).fail( function ( jqXHR ) {
			// Los errores del servidor viajan con estado HTTP 4xx (400, 403,
			// 409), y jQuery manda cualquier 4xx aquí, no a done(). El cuerpo
			// sigue siendo el JSON de wp_send_json_error, así que hay que
			// leerlo: sin esto, TODO error específico del POS —sin stock, sede
			// no válida, falta el DNI, no hay caja abierta— se veía como un
			// "ocurrió un error" genérico.
			if ( jqXHR && jqXHR.responseJSON ) {
				manejarRespuesta( jqXHR.responseJSON, $btn, $msg, items, dni );
				return;
			}
			$msg.html( '<span class="err">' + mspPOS.i18n.error + '</span>' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	}

	// Interpreta la respuesta del cobro, venga de done() o de fail().
	function manejarRespuesta( resp, $btn, $msg, items, dni ) {
		if ( resp.success ) {
			var html = '<span class="ok">' + resp.data.msg + '</span>';
			if ( resp.data.ticket ) {
				html += ' <a class="button button-primary" target="_blank" rel="noopener" href="' +
					resp.data.ticket + '">' + mspPOS.i18n.imprimir +
					( resp.data.boleta ? ' (' + resp.data.boleta + ')' : '' ) + '</a>';
			}
			$msg.html( html );
			ticket = {};
			pintarTicket();
			$( '#msp-pos-recibido' ).val( '' );
			$( '#msp-pos-dni' ).val( '' );
			$( '#msp-pos-cliente-nombre' ).val( '' );
			$( '#msp-pos-dni-aviso' ).text( '' );
			// Volver a boleta: el siguiente cliente es otro, y dejar "factura"
			// puesta acabaría en una factura con el RUC del anterior.
			$( '#msp-pos-ruc' ).val( '' );
			$( '#msp-pos-razon-social' ).val( '' );
			$( '#msp-pos-tipo' ).val( 'boleta' );
			sincronizarTipo();
			$( '#msp-pos-buscar' ).val( '' );
			$( '#msp-pos-resultados' ).empty();
			return;
		}

		if ( resp.data && resp.data.sin_caja ) {
			// Cobro en efectivo sin caja abierta: se ofrece abrirla aquí mismo
			// en vez de mandar al cajero a otra pantalla con el ticket a medias
			// y un cliente esperando.
			$msg.html( '<span class="err">' + resp.data.msg + '</span>' );
			var apertura = window.prompt( mspPOS.i18n.abrir_caja, '0' );
			if ( null === apertura ) {
				return;
			}
			$msg.html( mspPOS.i18n.abriendo );
			$.post(
				mspPOS.ajaxurl,
				{
					action: 'msp_pos_abrir_caja',
					nonce: mspPOS.nonce,
					sede: $( '#msp-pos-sede' ).val(),
					apertura: parseFloat( apertura ) || 0
				}
			).done( function ( r2 ) {
				if ( r2.success ) {
					$msg.html( '<span class="ok">' + r2.data.msg + '</span>' );
					cobrar( $btn, $msg, items, dni );
				} else {
					$msg.html( '<span class="err">' + ( r2.data && r2.data.msg ? r2.data.msg : mspPOS.i18n.error ) + '</span>' );
				}
			} ).fail( function ( x2 ) {
				var m = ( x2 && x2.responseJSON && x2.responseJSON.data && x2.responseJSON.data.msg )
					? x2.responseJSON.data.msg
					: mspPOS.i18n.error;
				$msg.html( '<span class="err">' + m + '</span>' );
			} );
			return;
		}

		$msg.html( '<span class="err">' + ( resp.data && resp.data.msg ? resp.data.msg : mspPOS.i18n.error ) + '</span>' );
	}

	// Estado inicial.
	calcularVuelto();
} )( jQuery );
