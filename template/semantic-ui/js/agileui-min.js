function leftMenu(){var e=991,i=$(".ui.left.sidebar").outerWidth(),l=$(window).width();console.log(991,l,i),$(".ui.left.sidebar").prepend('<a href="javascript:void(0)" class="item atk-leftMenuClose"><i class="close icon"></i></a>'),$(".atk-leftMenuClose").click(function(){$("body").removeClass("atk-leftMenu-visible")}),l<991?$(".atk-leftMenuTrigger").click(function(){$("body").toggleClass("atk-leftMenu-visible")}):l>991&&$("body.atk-leftMenu-visible").length&&$("body").removeClass("atk-leftMenu-visible")}$(function(){leftMenu(),$(".atk-leftMenuTrigger").click(function(){$(".ui.left.sidebar").toggleClass("visible")})}),$(window).resize(function(){leftMenu()});