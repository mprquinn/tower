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

	// Galleries
	 $(window).load(function () {
        $('.Collage').collagePlus();
    });

	$('#menu').click( function() {
		$(this).toggleClass('hidden');
	});
});