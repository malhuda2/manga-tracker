/* globals page */
$(function() {
	'use strict';
	if(page !== 'login') { return false; }

	$('.button-checkbox').each(function () {
		let $widget   = $(this),
			$button   = $widget.find('button'),
			$checkbox = $widget.find('input:checkbox'),
			color     = $button.attr('data-color'),
			settings  = {
				on : {
					icon: 'glyphicon glyphicon-check'
				},
				off: {
					icon: 'glyphicon glyphicon-unchecked'
				}
			};

		$button.on('click', function () {
			$checkbox.prop('checked', !$checkbox.is(':checked'));
			$checkbox.triggerHandler('change');
			updateDisplay();
		});

		$checkbox.on('change', function () {
			updateDisplay();
		});

		function updateDisplay() {
			let isChecked = $checkbox.is(':checked');
			// Set the button's state
			$button.attr('data-state', (isChecked) ? 'on' : 'off');

			// Set the button's icon
			$button.find('.state-icon')
				.removeClass()
				.addClass('state-icon ' + settings[$button.attr('data-state')].icon);

			// Update the button's color
			if (isChecked) {
				$button
					.removeClass('btn-default')
					.addClass('btn-' + color + ' active');
			} else {
				$button
					.removeClass('btn-' + color + ' active')
					.addClass('btn-default');
			}
		}

		function init() {
			updateDisplay();
			// Inject the icon if applicable
			if ($button.find('.state-icon').length === 0) {
				$button.prepend('<i class="state-icon ' + settings[$button.attr('data-state')].icon + '"></i> ');
			}
		}

		init();
	});
});
