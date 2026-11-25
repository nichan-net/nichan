/*
 * upload-selection.js - makes upload fields in post form more compact
 * https://github.com/vichan-devel/Tinyboard/blob/master/js/upload-selection.js
 *
 * Released under the MIT license
 * Copyright (c) 2014 Marcin Łabanowski <marcin@6irc.net>
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/nichan-draw.js';
 *   $config['additional_javascript'][] = 'js/upload-selection.js';
 *                                                  
 */

$(function(){
  var enabled_file = true;
  var enabled_url = $("#upload_url").length > 0;
  var enabled_embed = $("#upload_embed").length > 0;
  var can_draw = typeof window.nichanOpenPaint === 'function';

  var disable_all = function(keep_files) {
    $("#upload").hide();
    $("[id^=upload_file]").hide();
    $(".file_separator").hide();
    $("#upload_url").hide();
    $("#upload_embed").hide();
    $(".add_image").hide();
    $(".dropzone-wrap").hide();

    if (!keep_files) $('[id^=upload_file]').val('');
  };

  enable_file = function(keep_files) {
    disable_all(keep_files === true);
    $("#upload").show();
    $(".dropzone-wrap").show();
    $(".file_separator").show();
    $("[id^=upload_file]").show();
    $(".add_image").show();
  };

  enable_url = function() {
    disable_all();
    $("#upload").show();
    $("#upload_url").show();

    $('label[for="file_url"]').html(_("URL"));
  };

  enable_embed = function() {
    disable_all();
    $("#upload_embed").show();
  };

  if (enabled_url || enabled_embed || can_draw) {
    var en = window.nichan_lang === "en"; // (English on /int/)
    $("<tr><th>"+(en ? "Select" : "Додати")+"</th><td id='upload_selection'></td></tr>").insertBefore("#upload");
    var my_html = "<a href='javascript:void(0)' onclick='enable_file(); return false;'>"+(en ? "File" : "Файл")+"</a>";
    if (enabled_url) {
      my_html += " / <a href='javascript:void(0)' onclick='enable_url(); return false;'>"+_("Remote")+"</a>";
    }
    if (enabled_embed) {
      my_html += " / <a href='javascript:void(0)' onclick='enable_embed(); return false;'>"+_("Embed")+"</a>";
    }
    if (can_draw) {
      my_html += " / <a href='#' data-nichan-draw aria-haspopup='dialog'>" + (en ? "Draw" : "Малювати") + "</a>";
    }
    $("#upload_selection").html(my_html);
    $("#upload_selection [data-nichan-draw]").on('click', function (event) {
      event.preventDefault();
      window.nichanOpenPaint(this);
    });

    enable_file();
  }
});
