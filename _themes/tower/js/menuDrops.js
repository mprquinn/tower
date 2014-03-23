/*-----------------------

	Menu Dropdowns

------------------------*/

var MenuDrops = (function () {
	var s;

	return {

		settings: {
			$nav: $('#menu'),
			$li: $('#menu li')
		},

		init: function () {
			s = this.settings;
			this.bindUI();
		},

		bindUI: function () {
			s.$li.hover( function () {
				if ($(this).has('ul').length) {
					MenuDrops.displayNav($(this).find('ul'));
				}
			});
		},

		displayNav: function (nav) {

		}
	}

})();