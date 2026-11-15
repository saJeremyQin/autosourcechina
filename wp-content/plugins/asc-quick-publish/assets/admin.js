/* global wp, ascQp */
( function () {
	'use strict';

	const form = document.getElementById( 'asc-qp-form' );
	if ( ! form ) {
		return;
	}

	const $ = ( selector ) => document.querySelector( selector );
	const tbody = $( '#asc-qp-fitment tbody' );
	const rowTemplate = $( '#asc-qp-fitment-row' );
	const notice = $( '#asc-qp-notice' );
	const seriesSelect = $( '#asc-qp-series-cat' );
	const submitBtn = $( '#asc-qp-submit' );
	const spinner = form.querySelector( '.spinner' );

	const knownModels = new Set( ( ascQp.models || [] ).map( ( m ) => m.toLowerCase() ) );
	const natural = ( a, b ) => a.localeCompare( b, undefined, { numeric: true, sensitivity: 'base' } );

	const state = { imageId: 0, galleryIds: [], seriesTouched: false };

	// Fill the model suggestions list.
	const datalist = $( '#asc-qp-models' );
	( ascQp.models || [] ).slice().sort( natural ).forEach( ( name ) => {
		const option = document.createElement( 'option' );
		option.value = name;
		datalist.append( option );
	} );

	// ----- Fitment rows -------------------------------------------------

	function addRow() {
		const row = rowTemplate.content.firstElementChild.cloneNode( true );
		row.querySelector( '.asc-qp-remove-row' ).addEventListener( 'click', () => {
			row.remove();
			if ( ! tbody.children.length ) {
				addRow();
			}
			update();
		} );
		tbody.append( row );
		return row;
	}

	function fitmentRows() {
		return Array.from( tbody.querySelectorAll( 'tr' ) ).map( ( row ) => ( {
			el: row,
			model: row.querySelector( '.asc-qp-model' ).value.trim(),
			from: row.querySelector( '.asc-qp-from' ).value.trim(),
			to: row.querySelector( '.asc-qp-to' ).value.trim(),
		} ) );
	}

	$( '#asc-qp-add-row' ).addEventListener( 'click', () => {
		addRow().querySelector( '.asc-qp-model' ).focus();
	} );

	// ----- Live preview -------------------------------------------------

	function selectedText( select ) {
		return select.value !== '0' ? select.options[ select.selectedIndex ].text.trim() : '';
	}

	function buildTitle() {
		const make = selectedText( $( '#asc-qp-brand' ) );
		const part = form.part_name.value.trim();
		const oe = form.oe.value.trim();
		const rows = fitmentRows().filter( ( r ) => r.model && r.from );
		if ( ! make || ! part || ! oe || ! rows.length ) {
			return '';
		}
		const models = rows.map( ( r ) => r.model ).sort( natural );
		const years = rows.flatMap( ( r ) => [ Number( r.from ), Number( r.to || r.from ) ] );
		const min = Math.min( ...years );
		const max = Math.max( ...years );
		const range = min === max ? String( min ) : `${ min }-${ max }`;
		return `${ make } ${ models.join( ' ' ) } ${ part } ${ range } OE ${ oe }`;
	}

	// Suggest the series category, e.g. brand "Lexus" + model "IS350" → "Lexus IS".
	function suggestSeries() {
		if ( state.seriesTouched ) {
			return;
		}
		const make = selectedText( $( '#asc-qp-brand' ) );
		const first = fitmentRows().find( ( r ) => r.model );
		if ( ! make || ! first ) {
			return;
		}
		const prefix = ( first.model.match( /^[A-Za-z]+/ ) || [ '' ] )[ 0 ];
		const wanted = `${ make } ${ prefix }`.toLowerCase();
		const match = Array.from( seriesSelect.options ).find(
			( o ) => o.text.trim().replace( /\s+/g, ' ' ).toLowerCase() === wanted
		);
		seriesSelect.value = match ? match.value : '0';
	}

	function update() {
		$( '#asc-qp-title-preview' ).textContent = buildTitle() || '—';
		fitmentRows().forEach( ( r ) => {
			r.el.querySelector( '.asc-qp-new-badge' ).hidden = ! r.model || knownModels.has( r.model.toLowerCase() );
		} );
		suggestSeries();
	}

	form.addEventListener( 'input', update );
	form.addEventListener( 'change', update );
	seriesSelect.addEventListener( 'change', () => {
		state.seriesTouched = true;
	} );

	// ----- Media picker -------------------------------------------------

	function renderThumbs( container, attachments ) {
		container.replaceChildren(
			...attachments.map( ( a ) => {
				const img = document.createElement( 'img' );
				const size = ( a.sizes && ( a.sizes.thumbnail || a.sizes.medium ) ) || a;
				img.src = size.url;
				img.alt = a.alt || '';
				return img;
			} )
		);
	}

	function mediaFrame( title, multiple, onSelect ) {
		let frame;
		return () => {
			if ( ! frame ) {
				frame = wp.media( { title, multiple, library: { type: 'image' }, button: { text: 'Use selected' } } );
				frame.on( 'select', () => onSelect( frame.state().get( 'selection' ).toJSON() ) );
			}
			frame.open();
		};
	}

	$( '#asc-qp-image-btn' ).addEventListener(
		'click',
		mediaFrame( 'Main image', false, ( items ) => {
			state.imageId = items[ 0 ] ? items[ 0 ].id : 0;
			renderThumbs( $( '#asc-qp-image-preview' ), items );
		} )
	);

	$( '#asc-qp-gallery-btn' ).addEventListener(
		'click',
		mediaFrame( 'Gallery images', 'add', ( items ) => {
			state.galleryIds = items.map( ( a ) => a.id );
			renderThumbs( $( '#asc-qp-gallery-preview' ), items );
		} )
	);

	// ----- Submit -------------------------------------------------------

	function showNotice( type, html ) {
		notice.className = `notice inline notice-${ type }`;
		notice.innerHTML = html;
		notice.hidden = false;
		notice.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	function payload() {
		const prices = {};
		form.querySelectorAll( '.asc-qp-price' ).forEach( ( input ) => {
			prices[ input.dataset.condition ] = input.value.trim();
		} );
		return {
			brand_id: Number( form.brand_id.value ),
			part_name: form.part_name.value.trim(),
			part_cat_id: Number( form.part_cat_id.value ),
			series_cat_id: Number( form.series_cat_id.value ),
			oe: form.oe.value.trim(),
			partslink: form.partslink.value.trim(),
			includes: form.includes.value.trim(),
			fitment: fitmentRows().map( ( { model, from, to } ) => ( { model, from, to } ) ),
			prices,
			weight: form.weight.value.trim(),
			length: form.length.value.trim(),
			width: form.width.value.trim(),
			height: form.height.value.trim(),
			image_id: state.imageId,
			gallery_ids: state.galleryIds,
			extra_facts: form.extra_facts.value.trim(),
			copy: copyPayload(),
		};
	}

	// "Title: sentence" per line → [ { title, text } ].
	function copyPayload() {
		const features = form.copy_features.value
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean )
			.map( ( line ) => {
				const i = line.indexOf( ':' );
				return i > 0
					? { title: line.slice( 0, i ).trim(), text: line.slice( i + 1 ).trim() }
					: { title: '', text: line };
			} );
		return {
			intro: form.copy_intro.value.trim(),
			features,
			alternate_names: form.copy_alternate_names.value.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
			placement: form.copy_placement.value.trim(),
			sold_separately: form.copy_sold_separately.value.trim(),
		};
	}

	function errorHtml( error ) {
		const messages = error.additional_errors
			? [ error.message, ...error.additional_errors.map( ( e ) => e.message ) ]
			: [ error.message || 'Something went wrong.' ];
		return `<ul>${ messages.map( ( m ) => `<li>${ escapeHtml( m ) }</li>` ).join( '' ) }</ul>`;
	}

	// ----- Generate with AI ---------------------------------------------

	const generateBtn = $( '#asc-qp-generate' );
	const generateSpinner = $( '#asc-qp-generate-spinner' );
	const generateStatus = $( '#asc-qp-generate-status' );
	const copyFields = [ 'copy_intro', 'copy_features', 'copy_alternate_names', 'copy_placement', 'copy_sold_separately' ];

	generateBtn.addEventListener( 'click', async () => {
		const hasCopy = copyFields.some( ( name ) => form[ name ].value.trim() );
		if ( hasCopy && ! window.confirm( 'Replace the current description copy with a new AI version?' ) ) {
			return;
		}
		generateBtn.disabled = true;
		generateSpinner.classList.add( 'is-active' );
		generateStatus.textContent = 'Writing… this usually takes 10–20 seconds.';
		notice.hidden = true;
		try {
			const r = await wp.apiFetch( { path: ascQp.generateEndpoint, method: 'POST', data: payload() } );
			form.copy_intro.value = r.intro;
			form.copy_features.value = r.features.map( ( f ) => `${ f.title }: ${ f.text }` ).join( '\n' );
			form.copy_alternate_names.value = r.alternate_names.join( ', ' );
			form.copy_placement.value = r.placement;
			form.copy_sold_separately.value = r.sold_separately;
			generateStatus.textContent = `Done: ${ r.usage.input_tokens } + ${ r.usage.output_tokens } tokens ≈ US$${ Number( r.usage.cost_usd ).toFixed( 3 ) }. Review and edit before creating the draft.`;
		} catch ( error ) {
			generateStatus.textContent = '';
			showNotice( 'error', errorHtml( error ) );
		} finally {
			generateBtn.disabled = false;
			generateSpinner.classList.remove( 'is-active' );
		}
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		submitBtn.disabled = true;
		spinner.classList.add( 'is-active' );
		try {
			const result = await wp.apiFetch( { path: ascQp.endpoint, method: 'POST', data: payload() } );
			const warnings = result.warnings || [];
			let html = `<p>Draft created: <strong>${ escapeHtml( result.title ) }</strong> — <a href="${ escapeHtml( result.edit_url ) }" target="_blank" rel="noopener">review and publish</a></p>`;
			if ( warnings.length ) {
				html += `<ul>${ warnings.map( ( w ) => `<li>⚠ ${ escapeHtml( w ) }</li>` ).join( '' ) }</ul>`;
			}
			showNotice( warnings.length ? 'warning' : 'success', html );
		} catch ( error ) {
			let html = errorHtml( error );
			if ( error.data && error.data.edit_url ) {
				html += `<p><a href="${ escapeHtml( error.data.edit_url ) }" target="_blank" rel="noopener">Open the existing product</a></p>`;
			}
			showNotice( 'error', html );
		} finally {
			submitBtn.disabled = false;
			spinner.classList.remove( 'is-active' );
		}
	} );

	$( '#asc-qp-reset' ).addEventListener( 'click', () => {
		form.reset();
		tbody.replaceChildren();
		addRow();
		state.imageId = 0;
		state.galleryIds = [];
		state.seriesTouched = false;
		$( '#asc-qp-image-preview' ).replaceChildren();
		$( '#asc-qp-gallery-preview' ).replaceChildren();
		notice.hidden = true;
		if ( ! generateBtn.disabled ) {
			generateStatus.textContent = '';
		}
		update();
	} );

	addRow();
	update();
}() );
