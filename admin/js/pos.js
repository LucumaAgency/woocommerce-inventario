/* global jQuery, mspPOS */
( function ( $ ) {
	'use strict';

	var ticket = {}; // id -> { id, nombre, precio, qty }

	function fmt( valor ) {
		return mspPOS.simbolo + ' ' + Number( valor ).toFixed( mspPOS.decimals );
	}

	function totalTicket() {
		var t = 0;
		$.each( ticket, function ( _, it ) {
			t += it.precio * it.qty;
		} );
		return t;
	}

	function pintarTicket() {
		var $body = $( '#msp-pos-items' );
		$body.empty();

		var ids = Object.keys( ticket );
		if ( ! ids.length ) {
			$body.append(
				'<tr class="msp-pos-vacio"><td colspan="4">' + mspPOS.i18n.vacio + '</td></tr>'
			);
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
			$tr.append( $( '<td/>' ).text( fmt( it.precio * it.qty ) ) );
			$tr.append(
				$( '<td/>' ).append(
					$( '<a href="#" class="msp-pos-quitar">&times;</a>' )
				)
			);
			$body.append( $tr );
		} );

		$( '#msp-pos-total' ).text( fmt( totalTicket() ) );
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
				qty: 1
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
	$( '#msp-pos-items' ).on( 'click', '.msp-pos-quitar', function ( e ) {
		e.preventDefault();
		var id = $( this ).closest( 'tr' ).data( 'id' );
		delete ticket[ id ];
		pintarTicket();
	} );

	$( '#msp-pos-metodo, #msp-pos-recibido' ).on( 'change keyup', calcularVuelto );

	// Cobrar.
	$( '#msp-pos-cobrar' ).on( 'click', function () {
		var $msg = $( '#msp-pos-mensaje' ).empty();
		var ids = Object.keys( ticket );
		if ( ! ids.length ) {
			$msg.html( '<span class="err">' + mspPOS.i18n.vacio + '</span>' );
			return;
		}
		if ( ! window.confirm( mspPOS.i18n.confirmar ) ) {
			return;
		}

		var items = ids.map( function ( id ) {
			return { id: ticket[ id ].id, qty: ticket[ id ].qty };
		} );

		var $btn = $( this ).prop( 'disabled', true );

		$.post(
			mspPOS.ajaxurl,
			{
				action: 'msp_pos_cobrar',
				nonce: mspPOS.nonce,
				sede: $( '#msp-pos-sede' ).val(),
				metodo: $( '#msp-pos-metodo' ).val(),
				items: JSON.stringify( items )
			}
		).done( function ( resp ) {
			if ( resp.success ) {
				$msg.html( '<span class="ok">' + resp.data.msg + '</span>' );
				ticket = {};
				pintarTicket();
				$( '#msp-pos-recibido' ).val( '' );
				$( '#msp-pos-buscar' ).val( '' );
				$( '#msp-pos-resultados' ).empty();
			} else {
				$msg.html( '<span class="err">' + ( resp.data && resp.data.msg ? resp.data.msg : mspPOS.i18n.error ) + '</span>' );
			}
		} ).fail( function () {
			$msg.html( '<span class="err">' + mspPOS.i18n.error + '</span>' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Estado inicial.
	calcularVuelto();
} )( jQuery );
