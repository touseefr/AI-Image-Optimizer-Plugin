/* global AIIO, jQuery */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.aiio-regenerate-btn', function ( e ) {
		e.preventDefault();

		var $btn    = $( this );
		var $status = $btn.siblings( '.aiio-regenerate-status' );
		var id      = $btn.data( 'attachment-id' );

		$btn.prop( 'disabled', true );
		$status.text( AIIO.strings.generating );

		$.post( AIIO.ajaxUrl, {
			action: 'aiio_regenerate_alt_text',
			nonce: AIIO.nonce,
			attachment_id: id
		} ).done( function ( response ) {
			if ( response.success ) {
				$status.text( response.data.alt_text );

				// Try to update the visible alt text field on the same screen, if present.
				var $altField = $( '#attachment-details-two-column-alt-text, .compat-field-post_excerpt textarea, #attachment_alt' );
				$( 'input[name*="[image_alt]"], textarea[name*="[image_alt]"], #attachment_alt' ).val( response.data.alt_text );
			} else {
				$status.text( ( response.data && response.data.message ) || AIIO.strings.error );
			}
		} ).fail( function () {
			$status.text( AIIO.strings.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ---- Bulk processing on the settings page ----

	$( document ).on( 'click', '#aiio-start-bulk', function () {
		var $btn         = $( this );
		var $wrap        = $( '#aiio-bulk-progress-wrap' );
		var $bar         = $( '#aiio-bulk-progress-bar' );
		var $text        = $( '#aiio-bulk-progress-text' );

		$btn.prop( 'disabled', true );
		$wrap.show();
		$text.text( AIIO.strings.bulkStarting );

		$.post( AIIO.ajaxUrl, {
			action: 'aiio_bulk_get_queue',
			nonce: AIIO.bulkNonce
		} ).done( function ( response ) {
			if ( ! response.success ) {
				$text.text( ( response.data && response.data.message ) || AIIO.strings.error );
				$btn.prop( 'disabled', false );
				return;
			}

			var ids       = response.data.ids;
			var batchSize = response.data.batch_size || 5;
			var total     = ids.length;
			var processed = 0;

			if ( total === 0 ) {
				$text.text( AIIO.strings.bulkDone );
				$btn.prop( 'disabled', false );
				return;
			}

			function processNextBatch() {
				var batch = ids.splice( 0, batchSize );

				if ( batch.length === 0 ) {
					$bar.css( 'width', '100%' );
					$text.text( AIIO.strings.bulkDone + ' (' + processed + '/' + total + ')' );
					$btn.prop( 'disabled', false );
					return;
				}

				$.post( AIIO.ajaxUrl, {
					action: 'aiio_bulk_process_batch',
					nonce: AIIO.bulkNonce,
					ids: batch
				} ).done( function () {
					processed += batch.length;
					var pct = Math.min( 100, Math.round( ( processed / total ) * 100 ) );
					$bar.css( 'width', pct + '%' );
					$text.text( processed + ' / ' + total );
					processNextBatch();
				} ).fail( function () {
					$text.text( AIIO.strings.error );
					$btn.prop( 'disabled', false );
				} );
			}

			processNextBatch();
		} ).fail( function () {
			$text.text( AIIO.strings.error );
			$btn.prop( 'disabled', false );
		} );
	} );
} )( jQuery );
