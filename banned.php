<?php
 require_once 'inc/functions.php';
 require_once 'inc/bans.php';
 checkBan();
 echo Element('page.html', array('config' => $config, 'title' => _('You are not banned.'), 'nojavascript' => true));
?>
