<?php
class Freeswitchxml extends XMLWriter {

	public function e164($number,$p=array('local'=>9722, 'strip'=>1,'usa'=>1, 'israel'=>1, 'uk'=>1)) {
		//This function will convert the numbers presented into e.164 - converting local numbers to full international, stripping international access numbers, and making sure N. America has a 1 prefix.
		//While USA, Israel, and UK local dialplans do not conflict, other may.

		//What should we do with a 7 digit local?
		if(preg_match('~^(\d{7})$~',$number,$match))$number=$p['local'].$match[1];	//Just in case, passs it back into the regex. make the next an IF, not elseif.

		//USA - missing a 1 and/or international access numbers.
		if($p['usa'] && preg_match('~^(?:\+1|001)?([2-9]\d\d[2-9]\d{6})$~',$number,$match)){ $number="1".$match[1];}//USA - strip prefixes, make sure 1 is there.//$country="USA";$country_specific="proper";

		//Israel
		elseif($p['israel'] && preg_match('~^0(7?\d{8})$~',$number,$match))$number="972".$match[1];//Israel landlines
		elseif($p['israel'] && preg_match('~^0(5[0247]\d{7})$~',$number,$match))$number="972".$match[1];//Israel Mobile

		//UK
		elseif($p['uk'] && preg_match('~^0(\d{10})$~',$number,$match)){ $number="44".$match[1];}//UK normal 10 digit
		elseif($p['uk'] && preg_match('~^0(1\d{8})$~',$number,$match)){ $number="44".$match[1];}//UK short 1xxx - doesn't conflict with Israel
		elseif($p['uk'] && preg_match('~^0(800\d{6})$~',$number,$match)){ $number="44".$match[1]; }//UK short 800

		if($p['strip']=="normalize" && preg_match('~^(?:\+|00)(.*)$~',$number,$match)){ $number="011".$match[1]; }//strip the dialing prefix.
		elseif($p['strip'] && preg_match('~^(?:\+|011|00)(.*)$~',$number,$match)){ $number=$match[1]; }//strip the dialing prefix.

		return $number;
		}
	function expand_digits($number){
		GLOBAL $cfg;
		$count=strlen($number);
		$expanded="(";
		for($i=0;$i<$count;$i++){
			if(isset($cfg['mysql']) && $cfg['mysql'])	$expanded.="'".substr($number,0,$count-$i)."',";
			else 						$expanded.=    substr($number,0,$count-$i). ",";
			}
		return substr($expanded, 0, -1).")";
		}


	function private_enum($number){
		GLOBAL $do,$db;
		$sql_result=$db->query("select route from a_did where number=".$number,1);
		if(strlen($sql_result)){
			$this->dp_log("DEBUG private enum hit for $number = $sql_result");
			if(strpos($sql_result,'sip:')!== false)	$this -> dp_action_bridge("sofia/external/".$sql_result);
			else									$this -> dp_action_transfer($sql_result." XML default");
			$do++;
			}//end enum result
		}//end enum lookup.

	function cordia($number){
		GLOBAL $db;
		$sql ='SELECT l.digits AS lcr_digits, l.rate AS lcr_rate_field, l.name as lcr_destination ';
		$sql.='FROM lcr l WHERE l.carrier_id=8 AND digits IN '.$this->expand_digits($number).' ORDER BY digits DESC, rate limit 1';
		$cordia_free=$db->query($sql);//only has USA because I added "1"!!!
		if($db->num_rows($cordia_free)) {
			$this->cordia_lookup=$db->fetch_assoc($cordia_free);
			if($this->cordia_lookup['lcr_rate_field']==0) return 1;
			else  return 0;
			}//end has rows result
		else return -1;
		}//end cordia check

	function lrn_lookup_api($number, $exist=0){
		GLOBAL $cfg,$db,$xmlw;
		if($cfg['lrn']['cwu']){
			$time2=microtime(true);
			$lrn_lookup=trim(file_get_contents("http://lrn.callwithus.com/api/lrn/index.php?username={$cfg['lrn']['cwu_u']}&password={$cfg['lrn']['cwu_p']}&number=$number"));
			$xmlw->dp_log('DEBUG cwu lookup time: '.round((microtime(true) - $time2),3)." seconds");
			if(preg_match('~^1(\d{3})(\d{3})\d{4}$~',$lrn_lookup,$match)){
				if($exist)	$sql="update a_lrn set new_npa=$match[1], new_nxx=$match[2], date_lrn=NOW() where digits=$number";
				else 		$sql="insert into a_lrn (digits, new_npa,new_nxx) values ($number,$match[1],$match[2])";
				$db->query($sql);
				//echo "<br>".$sql."<Br>";
				if($lrn_lookup==$number) $xmlw->dp_log("INFO LCR: LRN for $number has not changed, and has been inserted into the DB.");
				else $xmlw->dp_log("INFO LCR: CallWithUs lookup for $number returned $lrn_lookup and has been inserted/updated in the DB.");
				return $lrn_lookup;
				}
			else {
				$cwu_error=array("-1000"=>"invalid user name or password.","-1003"=>"the queried number is not a valid US/Canada number.",
					"-1004"=>"lookup error.","-1005"=>"Your account balance is low.");
				$xmlw->dp_log("ERR LCR: CallWithUs lookup for $number returned error $lrn_lookup : ".$cwu_error[$lrn_lookup]);
				return $number;
				}
			}//LRN lookup with API.
		else return $number;
		}//end function LRN lookup

	function lcr($number,$lcr_number=0,$rate_field){
			if(empty($lcr_number)) $lcr_number=$number;
			GLOBAL $db,$price,$plan;
			$sql ="SELECT l.digits AS lcr_digits, c.carrier_name AS lcr_carrier_name, l.$rate_field AS lcr_rate_field, c.minimum as nibble_minimum, c.increment as nibble_increment, ";
			$sql.="c.prefix AS lcr_gw_prefix, c.suffix AS lcr_gw_suffix, l.name as lcr_rate_name FROM lcr l JOIN carriers c ON l.carrier_id=c.id WHERE c.enabled = 1 AND digits IN ";
			//AND l.enabled = '1'  - i haven't turned off individual lcr table fields.
			$sql.=$this->expand_digits($lcr_number)." AND lcr_profile=1 ORDER BY digits DESC, $rate_field;";//AND CURRENT_TIMESTAMP BETWEEN date_start AND date_end  // ,rand()
			//$this->dp_log('DEBUG LCR Query: '.$sql);
			$result=$db->query($sql);

			$lcr_auto_route="";$providers=$provider=$rates=array();
			if($db->num_rows($result)){
				while($list=$db->fetch_assoc($result)) {
					if(!isset($providers) || !in_array($list['lcr_carrier_name'],$providers)) {//don't add the ones with less digit matches!
						$providers[]=$list['lcr_carrier_name'];
						$provider[]=$list;
						$rates[]=$list['lcr_rate_field'];
						}//only if we don't have this provider yet
					}//loop through rows
			asort($rates,SORT_NUMERIC);//sort by rates, then use the keys to grab the $provider info resorted by rates.
			$order=array_keys($rates);
			$route_count=count($rates);
//$plan="business";
			for($i=0;$i<$route_count;$i++){
				$lcr_routes[$i]=$provider[$order[$i]];
				//$price = ($plan=="business") ? '.02' : '.0125';
				//Rules:	1) never charge less that .0125/.02.
				//			2) Use 1 * 1.15 or 1.3 OR 2nd price, whichever is higher.
				//			3) for 3rd and on, set price to 100%.
				//UK - the 03 - cap at 1.25c? e.g. if #1 < price, then just use that? don't bother with #2 - to preserve a uniformity?
				
				$pricing1=number_format($provider[$order[0]]['lcr_rate_field']*(($plan=="business") ? 1.3 : 1.15),4);	//2) Use 1 * 1.15 or 1.3
				$pricing2=number_format($provider[$order[1]]['lcr_rate_field'],4);									//...OR 2nd price, (access via the original array because it may not yet be set.
				$pricing= ( $pricing1 > $pricing2) ? $pricing1 : $pricing2;										//... whichever is higher.
				if($pricing<$price) $pricing=$price; //1) never charge less that .0125/.02.
				if($provider[$order[0]]['lcr_rate_field']<$price && $price>=$lcr_routes[$i]['lcr_rate_field']) $pricing=$price;//if 1st is under our cap, make all carriers under that price stick.
				$pricing_global=$pricing;
				if($i>0 && $pricing<$lcr_routes[$i]['lcr_rate_field']) {//Yes, I WILL ignore my 2nd route if the price is high. e.g. 18677660000
					$provider[$order[$i]]['high']=1;
					$pricing=$lcr_routes[$i]['lcr_rate_field']; //3) for 3rd and on, set price to 100%. (but not less than the earlier ones.)
					}

				$provider[$order[$i]]['nibble_rate']=$pricing;

				$this -> writeComment(str_pad($lcr_routes[$i]['lcr_digits'],9)."|".str_pad($lcr_routes[$i]['lcr_rate_field'],8)."|".str_pad($lcr_routes[$i]['lcr_carrier_name'],16)."|".
					str_pad($lcr_routes[$i]['nibble_minimum']."/".$lcr_routes[$i]['nibble_increment'],5)."|".
					str_pad($lcr_routes[$i]['lcr_gw_prefix'].$number.$lcr_routes[$i]['lcr_gw_suffix'],45)."|".str_pad($pricing,8)."|".
					str_pad($lcr_routes[$i]['lcr_rate_name'],30).(($provider[$order[$i]]['high'])? "ignoring" : ""));
				//echo "new route.\n";
				if($provider[$order[$i]]['high']) {}//$xmlw -> writeComment('Ignoring route '.$lcr_routes[$i]['lcr_carrier_name'].' because of price.');
				else {
					$lcr_routes[$i]['bridge']="[";
					foreach ($provider[$order[$i]] as $key=>$val){
						if($key=="lcr_rate_name") $lcr_routes[$i]['bridge'].=$key."=".preg_replace('[^a-zA-Z 0-9]','',$val).",";//make sure the value is safe.
						//elseif($key=="lcr_rate_field" && $val>$pricing) 	$lcr_routes[$i]['bridge'].='lcr_rate'."=".$val.",nibble_rate=".$pricing.",";//protection - raise the charged rate!
						//	elseif($key=="lcr_rate_field" && $val<=$pricing)$lcr_routes[$i]['bridge'].='lcr_rate'."=".$val.",nibble_rate=".$val.",";//protection - raise the charged rate!
						elseif($key=="lcr_carrier_name") $lcr_routes[$i]['bridge'].='lcr_carrier'."=".$val.",";
						elseif(!strpos($key,'_gw_') && $key!="bridge") $lcr_routes[$i]['bridge'].=$key."=".$val.",";//I don't need the prefix/suffix, and I don't want to duplicate itself!
						}//end add each variable.
					$lcr_routes[$i]['bridge']=substr($lcr_routes[$i]['bridge'],0,-1);//strip the extra ","
					$lcr_routes[$i]['bridge'].="]".$lcr_routes[$i]['lcr_gw_prefix'].$number.$lcr_routes[$i]['lcr_gw_suffix'];
					$lcr_auto_route.=$lcr_routes[$i]['bridge']."|";
					}//end it's not too high price.
				}//end loop of sorted gateways.
			$lcr_auto_route=substr($lcr_auto_route,0,-1);
		//echo "<pre>";print_r($lcr_routes);	echo "</pre>";
				
			}//end have results
		return array('price'=>$pricing_global,'route'=>$lcr_auto_route);
		}




	function noresult(){ 
		parent::startElement('section');
		parent::writeAttribute('name', 'result');
			parent::startElement('result');
			parent::writeAttribute('status', 'not found');
			parent::endElement();
		parent::endElement();
		}

	function start_extension($name,$continue=0) {
		parent::startElement('extension');
		parent::writeAttribute('name', $name);
		if($continue) parent::writeAttribute('continue', 'true');
		}
   /* function end_extension() {
		parent::endElement();
		}*/


	function dp_action($application,$data=null,$inline=0) {
		parent::startElement('action');
		parent::writeAttribute('application', $application);
		if(!empty($data)) parent::writeAttribute('data', $data);
		if($inline) parent::writeAttribute('inline', 'true');
		parent::endElement();
		}
	function dp_action_hangup_after_bridge() {
		parent::startElement('action');
		parent::writeAttribute('application', 'set');
		parent::writeAttribute('data', 'hangup_after_bridge=true');
		parent::endElement();
		}
	function dp_action_set_rpid() {//Not sure this even works! I think you need to set it 
		parent::startElement('action');
		parent::writeAttribute('application', 'set');
		parent::writeAttribute('data', 'sip_cid_type=rpid');
		parent::endElement();
		}
	function dp_set($k,$v) {
		parent::startElement('action');
		parent::writeAttribute('application', 'set');
		parent::writeAttribute('data', "$k=$v");
		parent::endElement();
		}
	function dp_action_set_nibble($rate,$minimum=null,$increment=null) {
		$this->dp_set("nibble_rate",$rate);
		if(!empty($minimum)) $this->dp_action_set_nibble_increment($minimum,$increment);
		}
	function dp_action_set_nibble_increment($minimum,$increment=null) {
		$this->dp_set('nibble_minimum',$minimum);
		if(!empty($increment)) $this->dp_set('nibble_increment',$increment);
		}
	function dp_log($text) {
		parent::startElement('action');
		parent::writeAttribute('application', 'log');
		parent::writeAttribute('data', $text);
		parent::endElement();
		}
	function dp_action_transfer($to) {
		parent::startElement('action');
		parent::writeAttribute('application', 'transfer');
		parent::writeAttribute('data', $to);
		parent::endElement();
		}
	function dp_action_bridge($to) {
		parent::startElement('action');
		parent::writeAttribute('application', 'bridge');
		parent::writeAttribute('data', $to);
		parent::endElement();
		}
	function dp_action_bridge_external($number,$domain) {
		parent::startElement('action');
		parent::writeAttribute('application', 'bridge');
		parent::writeAttribute('data', 'sofia/external/'.$number."@".$domain);
		parent::endElement();
		}
	function dp_action_bridge_sip($number,$domain) {//can't pass a full sip: uri here!
		$this->dp_action_bridge_external($number,$domain);
		}
	function dp_action_bridge_gateway($gateway,$number,$ratedeck=null) {//grn code.
		parent::startElement('action');
		parent::writeAttribute('application', 'bridge');
		if($ratedeck=='usa'||$ratedeck=='standard'||$ratedeck=='std'||$ratedeck=='grey') $number='991391500'.$number;//USA - make sure to prefix with 1.
		elseif($ratedeck=='premium'||$ratedeck=='prm'||$ratedeck=='white') $number='991391501'.$number;
		elseif($ratedeck=='grey1/1') $number='991391502'.$number;
		parent::writeAttribute('data', 'sofia/gateway/'.$gateway."/".$number);
		parent::endElement();
		}

	}//end extend class


//<action application="log" data="INFO DIALING Extension DialURI [${sip_uri_to_dial}]"/>
//<action application="log" data="DEBUG DIALING Extension DialURI [${sip_uri_to_dial}]"/>
?>
