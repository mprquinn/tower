/*-----------------------

	Set Header Size
	on load

------------------------*/

var HeaderSize = (function () {
	var s;

	return {

		settings: {
			$header: $('.home header')
		},

		init: function () {
			s = this.settings;
			this.determineHeight();
		},

		determineHeight: function () {
			var height = $(window).height();

			this.setHeight(height);
		},

		setHeight: function (height) {
			s.$header.css({
				height: height + 'px'
			});
		}
	}

})();