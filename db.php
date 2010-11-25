<?
class db { 
	function db(){ 
		$this->querysum = 0; 
		$this->querytime = 0; 
		$this->querylist = ""; 
		$this->table = array(); 
		} 
	function connect($dbip, $dbuser, $dbpass) { 
		$this->link = mysql_connect($dbip, $dbuser, $dbpass) or die("Could not connect"); 
		GLOBAL $dbh;
		$dbh=$this->link;
		} 
	function select_db($dbname) { 
		$select = mysql_select_db($dbname, $this->link) or die(mysql_error()); 
		} 
	function query($q,$echo=0) { 
		//list($Msec, $sec) = explode(" ", microtime()); $fetchstart=(float)$sec+(float)$Msec;
		if(!$res = mysql_query($q, $this->link)) {
			//if ($_SESSION['level'] == "admin")	
			echo "<br>Invalid Query : ".substr($q,0,4000)." <br>Mysql Error : ".mysql_error();
			//else echo "MySQL error";
			//error();
			}
		//list($Msec, $sec) = explode(" ", microtime()); $fetchend=(float)$sec+(float)$Msec;
		//$this->one_querytime=$fetchend-$fetchstart;
		//$this->querytime+=$this->one_querytime;
		//$this->querysum++; 
		//			if(round($this->one_querytime,2)>0) 
		//$this->querylist .= $this->querysum . ": (".round($this->one_querytime,2).") ".str_ireplace(array("update ","insert "),array("<font color='red'>update</font> ","<font color='red'>insert</font> "),htmlentities($q))."<br>"; 
		if ($echo) return $this->result($res,0,0); 
		return($res); 
		} 
	function fetch_array($res) { 
		$row = mysql_fetch_array($res); 
		return($row); 
		} 
	function affected_rows() { return(mysql_affected_rows()); } 
    function fetch_row($res) { 
		$row = mysql_fetch_row($res); 
		return($row); 
		} 
    function fetch_assoc($res) { 
		$row = mysql_fetch_assoc($res); 
		return($row); 
		} 
	function insert_id() { return(mysql_insert_id()); } 
	function num_rows($res) {	return(mysql_num_rows($res)); } 
	function safe($value){
		if(is_array($value)){
			$values=array_values($value);
			$count=count($values);
			}
		else {
			$values[0]=$value;
			$count=1;
			}
//		if (get_magic_quotes_gpc()) $magic=1;
	//	else $magic=0;
		for($i=0;$i<$count;$i++){
			//if($magic)
			$val = stripslashes($values[$i]);
			$return .= "'".mysql_real_escape_string($val)."',";
			}
		   return substr($return,0,strlen($return)-1);
		}
	function result($res, $row, $field=-1) { 
		if (is_resource($res) && $this->num_rows($res)){
			if($field==-1)	return mysql_result($res, $row); 
			else	return mysql_result($res, $row, $field); 
			}
		}
	}//end class

function print_r_html($a){
	echo "<pre>";
	print_r($a);
	echo "</pre>";	
	}

		$dbtype = 'mysql'; 
		$dbhost = '127.0.0.1';
		$dbname = '';
		$dbusername = '';
		$dbpassword = '';
		$db=NEW db;
		$db->connect($dbhost,$dbusername,$dbpassword);
		$db->select_db($dbname);


?>
