$(document).ready( function () {
	
	// Set up header
	// HeaderSize.init();

	// Set up menus
	MenuDrops.init();

	//Responsive nav
	;( function () {
		var jPM = $.jPanelMenu({
			trigger: '.nav-toggle'
		});

		jPM.on();
	})();

	$('#menu').click( function() {
		$(this).toggleClass('hidden');
	});
});