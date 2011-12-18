<?php
#
#  "About Me" is simply a synonym for Article $blog_aboutme;
#
function aboutPage() {
	global $blog_aboutme, $sub_page;
	$sub_page = $blog_aboutme;
	callFunction( 'articlePage' );
}
?>
