/**
 * Plugin Settings Finder: filter field in the admin bar dropdown.
 *
 * Groups each plugin heading with the entries below it and hides groups
 * whose plugin name, basename or page labels do not contain the query.
 * Reads textContent only; never writes HTML.
 */
( function () {
	'use strict';

	document.addEventListener( 'input', function ( event ) {
		var input = event.target;
		if ( ! input.classList || ! input.classList.contains( 'psf-filter-input' ) ) {
			return;
		}

		var query = input.value.toLowerCase().trim();
		var groups = [];
		var current = null;

		document.querySelectorAll( '#wp-admin-bar-psf-default > li' ).forEach( function ( item ) {
			if ( item.classList.contains( 'psf-plugin' ) ) {
				// The heading link's title attribute holds the plugin basename.
				var link = item.querySelector( '.ab-item' );
				current = { items: [ item ], text: item.textContent + ' ' + ( ( link && link.getAttribute( 'title' ) ) || '' ) };
				groups.push( current );
			} else if ( item.classList.contains( 'psf-entry' ) && current ) {
				current.items.push( item );
				current.text += ' ' + item.textContent;
			} else if ( item.classList.contains( 'psf-section' ) ) {
				item.style.display = query ? 'none' : '';
			}
		} );

		groups.forEach( function ( group ) {
			var show = ! query || group.text.toLowerCase().indexOf( query ) > -1;
			group.items.forEach( function ( item ) {
				item.style.display = show ? '' : 'none';
			} );
		} );
	} );
}() );
