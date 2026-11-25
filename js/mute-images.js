/*
 * mute-images.js
 *
 * Hide all images.
 *
 * Released under the MIT license
 * Copyright (c) 2015 boku
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/mute-images.js';
 *
 */

$(document).ready(function(){
	$('head').append('<style type="text/css"> .muteimages .post-image:not(:hover) { opacity: 0.03; } </style>');
	// nichan: an eye icon (the words are its title); js/nichan.js puts it in the row of icons
	var en = window.nichan_lang === 'en';
	var label = function (muted) {
		$('#mute_images').attr('title', muted ? (en ? 'Show pictures' : 'Показати картинки (мати вийшла)') : (en ? 'Hide pictures' : 'Сховати картинки (мати в кімнаті!)'))
			.find('i').attr('class', muted ? 'fa fa-eye' : 'fa fa-eye-slash');
	};
	$('hr:first').before('<div style="text-align:right"><a href="javascript:void(0)" id="mute_images"><i class="fa fa-eye-slash"></i></a></div>');
	label(false);
	
	if (!localStorage.imagesmuted){
		localStorage.imagesmuted = 'false';
	}

	// Load data from HTML5 localStorage
	var isMuted = JSON.parse(localStorage.imagesmuted),
	    store_data = function() {
			localStorage.imagesmuted = JSON.stringify(isMuted);
		};

	if(isMuted){
		$('body').addClass('muteimages');
		label(true);
	}   

    $('#mute_images').on('click', function(){
    	if(isMuted){
			$('body').removeClass('muteimages');
			isMuted = false;
			label(false);
		}else{
			$('body').addClass('muteimages');
			isMuted = true;
			label(true);
		}

		store_data();
		return false; 
    });
});
