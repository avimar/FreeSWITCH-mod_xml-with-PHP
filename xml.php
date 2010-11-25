<?php
require "db.php";
require "freeswitchxml.inc.php";

//$time = microtime(true);echo "<p>Time elapsed: ",microtime(true) - $time, " seconds\n\n";

/*
variable_user_context=default
variable_effective_caller_id_name=name
variable_effective_caller_id_number=12019999999
variable_outbound_caller_id_name=name
variable_outbound_caller_id_number=12019999999
variable_nibble_account=1
*/


if($_GET['debug']) header('Content-Type: text/html');
else header('Content-Type: text/xml');
$xmlw = new Freeswitchxml();//XMLWriter();
$xmlw -> openMemory();
$xmlw -> setIndent(true);
$xmlw -> setIndentString('  ');
$xmlw -> startDocument('1.0', 'UTF-8', 'no');

$xmlw -> startElement('document');
$xmlw -> writeAttribute('type', 'freeswitch/xml');

$do=0;

if($_POST['section']=="dialplan"){
	$number=$_POST['Caller-Destination-Number'];
	if($_POST['Caller-Context']=="default"){
		$xmlw -> startElement('section');
		$xmlw -> writeAttribute('name', 'dialplan');
		//$xmlw -> writeComment('Heres the debug info:'.print_r($_POST,1));

		//start the context
		$xmlw -> startElement('context');$xmlw -> writeAttribute('name', 'default');

		//1: Get the user's information
		//1) Has cordia or not. 2) personal or biz. 3)  his CID info per country. 4) kosher mobile prefs - nothing, delay, block.
		//we can "layer" from nibble account, overridden by extension.
		if(empty($_POST['variable_nibble_account'])) {
/*			// --> unless it's an extension or I'm sending to fax, echo, conference, etc.
			$xmlw -> start_extension("no Nibblebill ID");
			$xmlw -> startElement('condition');
			$xmlw -> dp_action('pre_answer');
			$xmlw -> dp_action('sleep',1000);
			$xmlw -> dp_action('speak','flite|kal| You don\'t have a billing ID set, please contact support.');
			$xmlw -> endElement();//</condition>
			$xmlw -> endElement();//</extension>*/
			}
		else {
			$cordia=$db->query("select service_id as cordia_global from a_services where nibble={$_POST['variable_nibble_account']} and service_id=1",1);
			$cordia_account=$_POST['variable_nibble_account'];
			}
		$israel_kosher=	$db->query("select israel_kosher from v_extensions where extension={$_POST['variable_user_name']} limit 1",1);
		$israel_cid	=	$db->query("select outbound_caller_id_number_israel from v_extensions where extension={$_POST['variable_user_name']} limit 1",1);


		$xmlw -> start_extension("global_curl",1);//duplicate the standard stuff in the default.xml file.
		$xmlw -> startElement('condition');$xmlw -> writeAttribute('field', '${call_debug}');$xmlw -> writeAttribute('expression', '^true$');$xmlw -> writeAttribute('break', 'never');
			$xmlw -> dp_action('info');
		$xmlw -> endElement();//</condition>
		$xmlw -> startElement('condition');//no attributes means it always triggers
			$xmlw -> dp_action('hash','insert/${domain_name}-spymap/${caller_id_number}/${uuid}');
			$xmlw -> dp_action('hash','insert/${domain_name}-last_dial/${caller_id_number}/${destination_number}');
			$xmlw -> dp_action('hash','insert/${domain_name}-last_dial/global/${uuid}');
		$xmlw -> endElement();//</condition>
		$xmlw -> endElement();//</extension>

		$price = ($plan=="business") ? 0.02 : 0.0125;

		//2: Speed dial check.
		if(preg_match('~^\d{1,3}$~',$number)) {
			$sql_result=$db->query("select dial as speed_dial from a_speeddial where digits='$number' and (user={$_POST['variable_nibble_account']} or everyone=1)",1);
			if(strlen($sql_result)) {
			//	$xmlw->dp_log("DEBUG Speed dial of $number converted to $sql_result");
				$number=$sql_result;
				}
			else {
			//	$xmlw->dp_log("DEBUG Speed dial lookup of $number returned 0 results.");
				}
			unset($sql_result);
			}//end speed dial lookup

		//Call to the extensions with or without voicemail.
		if(preg_match('~(^\d{3,6}$)~',$number)){//Local extensions - for BOTH voicemail and NON voicemail, checking the ${user_voicemail}==false false channel variable
			$do++;//Maybe we should also check the DB to see the extension actually exists?
			$xmlw -> start_extension("Local_Extension_curl");
			$xmlw -> startElement('condition');
				$xmlw -> dp_set			('dialed_extension',$number);
				$xmlw -> dp_application	('export',"dialed_extension=$number");
				//<!-- bind_meta_app can have these args <key> [a|b|ab] [a|b|o|s] <app> -->
				$xmlw -> dp_application	('bind_meta_app','1 b s execute_extension::dx XML features');
				//$xmlw -> dp_application	('bind_meta_app','2 b s record_session::$${base_dir}/recordings/${caller_id_number}.${strftime(%Y-%m-%d-%H-%M-%S)}.wav');
				$xmlw -> dp_application	('bind_meta_app','2 b s record_session::$${recordings_dir}/${caller_id_number}.${strftime(%Y-%m-%d-%H-%M-%S)}.wav');
				$xmlw -> dp_application	('bind_meta_app','3 b s execute_extension::cf XML features');
				$xmlw -> dp_set			('ringback','${us-ring}');
				$xmlw -> dp_set			('transfer_ringback','$${hold_music}');
				$xmlw -> dp_set			('call_timeout',(($_POST['variable_user_voicemail']=='false') ? '45' : '30'));
				//$xmlw -> dp_set			('sip_exclude_contact','${network_addr}');
				$xmlw -> dp_action_hangup_after_bridge();
				//$xmlw -> dp_set			('continue_on_fail','NORMAL_TEMPORARY_FAILURE,USER_BUSY,NO_ANSWER,TIMEOUT,NO_ROUTE_DESTINATION');
				$xmlw -> dp_set			('continue_on_fail','true');
				$xmlw -> dp_action('hash','insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}');
				$xmlw -> dp_action('hash','insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}');
				$xmlw -> dp_set	  ('called_party_callgroup','${user_data(${dialed_extension}@${domain_name} var callgroup)}');
				//$xmlw -> dp_action('export', 'nolocal:sip_secure_media=${user_data(${dialed_extension}@${domain_name} var sip_secure_media)}');
				$xmlw -> dp_action('hash','insert/${domain_name}-last_dial/${called_party_callgroup}/${uuid}');
				
				$xmlw -> dp_action('hash','');
				$xmlw -> dp_action_bridge('user/${dialed_extension}@${domain_name}');
				if(!isset($_POST['variable_user_voicemail']) || $_POST['variable_user_voicemail']!='false'){//when we should actually do voicemail
					$xmlw -> dp_action('answer');
					$xmlw -> dp_action('info');//why?
					$xmlw -> dp_action('sleep',1000);
					$xmlw -> dp_action('voicemail','default ${domain_name} ${dialed_extension}');
					}
			$xmlw -> endElement();//</condition>
			$xmlw -> endElement();//</extension>
			}//end local extension


		//3: e.164 number resolution/expansion --- after speed dial!!
		$new_number=Freeswitchxml::e164($number,array('local'=>9722, 'strip'=>1,'usa'=>1, 'israel'=>1, 'uk'=>1));
		if($new_number!=$number) {
		//	$xmlw->dp_log("DEBUG e.164 $number converted to $new_number");
			$number=$new_number;
			unset($new_number);
			}


		//5: 1800/0800 freephone calls.
		if(preg_match('~^1(8(00|55|66|77|88)[2-9]\d{6})$~',$number)){//USA Free 	$country="USA";$country_specific="free";
			$do++;
			$xmlw -> start_extension("USA Freephone");//$country.' '.$country_specific);
				$xmlw -> startElement('condition');
				$xmlw -> dp_action_hangup_after_bridge();
				$xmlw -> dp_action_bridge_gateway('grn',$number,'usa');
				$xmlw -> endElement();//</condition>
			$xmlw -> endElement();//</extension>
			}//end us free

		elseif(preg_match('~^44((800|808|500)\d{7})$~',$number)||preg_match('~^(44800\d{6})$~',$number)){ //UK 800 - only old 800 can have 6 length. $country="UK";$country_specific="free";
			$do++;
			$xmlw -> start_extension("UK Freephone");//$country.' '.$country_specific);
				$xmlw -> startElement('condition');//all conditions.
				$xmlw -> dp_action_hangup_after_bridge();
	 			$xmlw -> dp_action_bridge_external($number,'freenum.sip.telecomplete.net');
	 			$xmlw -> dp_action_bridge_external($number,'selfnet.at');
	 			$xmlw -> dp_action_bridge_external($number,'proxy.ideasip.com');
				$xmlw -> endElement();//</condition>
			$xmlw -> endElement();//</extension>
			}//end uk free


		//5b. e.164 arpa Enum lookup???
  

		//6: Hit the a-z dialplan.
		$area_codes['non-usa']="264|268|242|246|441|284|345|767|809|473|876|664|787|939|869|758|784|868|649|340|684|671|670|680";//Carribeans & Pacific
		$area_codes['usa2']="808|907";
		$area_codes['canada']="403|780|250|604|778|204|506|709|902|289|416|519|613|647|705|807|905|418|450|514|819|306|867";
		//preg_match('~^1([2-9]\d\d[2-9]\d{6})$~',$number) all 11 digit americas.

		if(preg_match('~^(1|2[1-689]\d|2[07]|3[0-469]|3[578]\d|4[0-13-9]|42\d|5[09]\d|5[1-8]|6[0-6]|6[7-9]\d|7|8[035789]\d|8[1246]|9[0-58]|9[679]\d)(\d{7,20})$~',$number,$match)){
			$country=$match[1];
//				$price = ($plan=="business") ? 0.02 : 0.0125;
			$xmlw -> start_extension("Calling country $match[1]");//.$country.' '.$country_specific);
			$xmlw -> startElement('condition');//all conditions.
			$xmlw -> dp_action_hangup_after_bridge();


			$time = microtime(true);
			//if 1, and a valid number, and not canada or carribeans, do LRN. Also, see if intra-state or not.
			if($country==1 && preg_match('~^1([2-9]\d\d[2-9]\d{6})$~',$number) && !preg_match('~^1('.$area_codes['canada'].'|'.$area_codes['non-usa'].')(\d{7})$~',$number)){ //not non-usa nanpa
				$sql="select new_npa,new_nxx from a_lrn where digits=$number";
				$result=$db->query($sql);
				if($db->num_rows($result)){
					$result=$db->fetch_assoc($result);
					$new_npa=$result['new_npa'];
					$new_nxx=$result['new_nxx'];
					$xmlw->dp_log("DEBUG LCR: LRN relocated from $number to 1".$new_npa.$new_nxx."0000");
					$lcr_number="1".$new_npa.$new_nxx."0000";
					}
				else $lcr_number=$number;//no LRN result

				//Which rate? IN USA!
				if(preg_match('~^1([2-9]\d\d)([2-9]\d{2})\d{4}$~',$number,$lcr_callee) && preg_match('~^1([2-9]\d\d)([2-9]\d{2})\d{4}$~',$_POST['variable_effective_caller_id_number'],$lcr_caller)){
					if(strlen($new_npa)) {$lcr_callee[1]=$new_npa;$lcr_callee[2]=$new_nxx;}
					$sql="SELECT 'state', count(DISTINCT state) FROM npa_nxx_company_ocn WHERE (npa={$lcr_callee[1]} AND nxx={$lcr_callee[2]}) OR (npa={$lcr_caller[1]} AND nxx={$lcr_caller[2]})
						UNION SELECT 'lata', count(DISTINCT lata) FROM npa_nxx_company_ocn WHERE (npa={$lcr_callee[1]} AND nxx={$lcr_callee[2]}) OR (npa={$lcr_caller[1]} AND nxx={$lcr_caller[2]})";
					$result=$db->query($sql);$list1=$db->fetch_array($result); $list2=$db->fetch_array($result);
					if($list1[1]==1 && $list2[1]==1) $rate_field="intralata_rate";//always more expensive in-state.
					elseif($list1[1]==1) $rate_field="intrastate_rate";//always more expensive in-state.
					else $rate_field="rate";
					$xmlw->dp_log("DEBUG LCR: STATE=$list1[1], LATA=$list2[1] therefore using '$rate_field'");
					}
				else {
					$rate_field="intrastate_rate";
					$xmlw->dp_log("DEBUG LCR: No rate center, so using 'intrastate_rate'");//always more expensive in-state - guilty until proven otherwise!
					}
				}//end USA use LRN & intrastate
			else {
				$lcr_number=$number;//not USA
				$rate_field="rate";//not USA
				$xmlw->dp_log("DEBUG LCR: Not USA, so using 'rate'");
				}


			$lcr=$xmlw->lcr($number,$lcr_number,$rate_field);
			$xmlw->dp_log('INFO LCR total query time: '.round((microtime(true) - $time),3)." seconds");
			if(strlen($lcr['route'])) $do++;			

			if($country==972 && strlen($israel_cid)) $xmlw->dp_set('effective_caller_id_number',$israel_cid);

			if($israel_kosher>0 && preg_match('~^972(5041|5271|5276|5484|5731)\d{5}$~',$number)){
				$xmlw -> dp_action('pre_answer');
				$xmlw -> dp_action('sleep',1000);
				if($israel_kosher==1) {
					$xmlw -> dp_action('speak','flite|kal| This is a kosher number, and probably free from your cell phone.');
					$xmlw -> dp_action('sleep',3000);
					}//end warn
				elseif($israel_kosher==2) {
					$xmlw -> dp_action('speak','flite|kal| This is a kosher number.');
					$xmlw -> dp_action('sleep',1000);
					$xmlw -> dp_action('hangup',57);//403 or 603 or CALL_REJECTED or OUTGOING_CALL_BARRED or BEARERCAPABILITY_NOTAUTH
					}//end block
				}//end is a kosher number

			$xmlw->dp_set('bypass_media','true');// BYPASS MEDIA!

			if($cordia && $xmlw->cordia($number))	{	//$info=$xmlw->cordia_lookup;
				$xmlw->dp_log("NOTICE Account has cordia, using unlimited trunk.");
				$xmlw -> dp_action_bridge_gateway('cordia-global-'.$cordia_account,$number);	$do++;
				}//end use cordia

			$xmlw -> dp_action_set_nibble($lcr['price'],6,6);
			$xmlw -> private_enum($number);//Place here to charge them!
			$xmlw -> dp_action_bridge($lcr['route']);//LCR


			$xmlw -> endElement();//</condition>
			$xmlw -> endElement();//</extension>
			}//end A-Z


/* Old cordia check - and XML comment writing.
				$sql='SELECT l.digits AS lcr_digits, l.rate AS lcr_rate_field, l.name as lcr_destination FROM lcr l WHERE l.carrier_id=8 AND digits IN '.$expanded.' ORDER BY digits DESC, rate limit 1';
				$cordia_free=$db->query($sql);//****does't have USA!!!
				if($db->num_rows($cordia_free)) {
					$cordia_free=$db->fetch_assoc($cordia_free);
					if($cordia_free['lcr_rate_field']==0){
						$xmlw -> writeComment(str_pad($cordia_free['lcr_digits'],9)."|".str_pad("FREE",8)."|".str_pad("Unlimited Global",16)."|".str_pad('',5)."|".str_pad('',45)."|".str_pad('0.0000',8)."|".str_pad($lcr_routes[$i]['lcr_rate_name'],30));
						}//end is 0.
					else $xmlw -> writeComment("Not included in global unlimited.");//Not 0:".print_r($cordia_free,1)); 
					}//end has rows result
				else $xmlw -> writeComment("Not included in global unlimited. (not listed)");//Cordia returned nothing. - $sql");
*/



		// </context>
		$xmlw -> endElement();

		//</section>
		$xmlw -> endElement();




if($do==0)$xmlw->noresult();
	
		}//end default context.
	elseif($_POST['Caller-Context']=="public"){
		$xmlw->noresult();
		}//end context
	else{
		$xmlw->noresult();
		}//end context
	}//end dialplan.


//</document>
$xmlw -> endElement();

if($_GET['debug']) echo "<html><body><pre>".wordwrap(htmlentities($xmlw -> outputMemory()),200,"\n",1)."</pre></body></html>";
else echo $xmlw -> outputMemory();
?>
