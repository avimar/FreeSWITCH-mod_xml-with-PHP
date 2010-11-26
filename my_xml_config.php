<?php
		$dbhost = '127.0.0.1';
		$dbname = '';
		$dbusername = '';
		$dbpassword = '';
		$db=NEW db;
		$db->connect($dbhost,$dbusername,$dbpassword);
		$db->select_db($dbname);
?>
