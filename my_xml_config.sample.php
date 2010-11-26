<?php
$dbhost = '127.0.0.1';
$dbname = '';
$dbusername = '';
$dbpassword = '';


$mysql = 1;//set to 1 if this is mysql - for the digit quoting in mod_lcr. Else, set to 0.



$db=NEW db;
$db->connect($dbhost,$dbusername,$dbpassword);
$db->select_db($dbname);
?>
