<?php
$dbhost = '127.0.0.1';
$dbname = '';
$dbusername = '';
$dbpassword = '';


$cfg['mysql'] = 1;//set to 1 if this is mysql - for the digit quoting in mod_lcr. Else, set to 0.

$cfg['lrn']['cwu']=0;//Should we use callwithus to look up LRN information? set to 1 for yes. - each lookup is $0.0003
$cfg['lrn']['cwu_u']=0;//Your callwithus username
$cfg['lrn']['cwu_p']=0;//your callwithus password
$cfg['lrn']['expire']=0;//How many days before an entry expires? Set 0 for never.

$db=NEW db;
$db->connect($dbhost,$dbusername,$dbpassword);
$db->select_db($dbname);
?>
