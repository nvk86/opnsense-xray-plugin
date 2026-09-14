#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');
function output(array $data): void { echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; exit(0); }
function tracked(string $uuid,string $kind): int {
    $hev=$kind==='hev'; $pf='/var/run/xray-'.($hev?'hev-':'').$uuid.'.pid';
    $raw=is_file($pf)?trim((string)@file_get_contents($pf)):''; if(!ctype_digit($raw)||(int)$raw<=1)return 0; $pid=(int)$raw;
    $cmd=trim((string)shell_exec('/bin/ps -ww -o command= -p '.$pid.' 2>/dev/null'));
    $bin=$hev?'/usr/local/libexec/xray/hev-socks5-tunnel':'/usr/local/libexec/xray/xray';
    $conf='/usr/local/etc/opnsense-xray/'.($hev?'hev-':'config-').$uuid.($hev?'.yaml':'.json');
    return $cmd!==''&&strpos($cmd,$bin)!==false&&strpos($cmd,$conf)!==false?$pid:0;
}
$uuid=isset($argv[1])?trim((string)$argv[1]):'';
if(!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D',$uuid)) output(['result'=>'failed','message'=>'Invalid instance UUID']);
$cfg=OPNsense\Core\Config::getInstance()->object(); $inst=null;
foreach(($cfg->OPNsense->xray->instances->instance??[]) as $c){if((string)$c['uuid']===$uuid){$inst=$c;break;}}
if($inst===null) output(['result'=>'failed','message'=>'Instance not found']);
$iface=(string)($inst->tun_interface??'tun0'); $expected=(string)($inst->tun_address??''); [$ip,$prefix]=array_pad(explode('/',$expected,2),2,'');
$xpid=tracked($uuid,'xray'); $hpid=tracked($uuid,'hev');
$socksHost=(string)($inst->socks5_listen??'127.0.0.1'); $socksPort=(int)($inst->socks5_port??10808);
$errno=0;$errstr='';$fp=@fsockopen($socksHost,$socksPort,$errno,$errstr,0.25);$socks=$fp!==false;if($fp)fclose($fp);
$if=[];exec('/sbin/ifconfig '.escapeshellarg($iface).' 2>/dev/null',$if,$irc);$txt=implode("\n",$if);$exists=$irc===0;
$marked=$exists&&preg_match('/\bdescription:\s+xray:'.preg_quote($uuid,'/').'(?:\s|$)/m',$txt)===1;
$opened=$exists&&$hpid>1&&preg_match('/^\s*Opened by PID\s+'.preg_quote((string)$hpid,'/').'\s*$/mi',$txt)===1;$owned=$marked||$opened;
$addr=$owned&&$prefix==='32'&&preg_match('/\binet\s+'.preg_quote($ip,'/').'(?:\s+-->\s+\S+)?\s+netmask\s+(?:0xffffffff|255\.255\.255\.255)\b/i',$txt)===1;
$up=$exists&&preg_match('/flags=[^<]*<([^>]+)>/',$txt,$fm)&&in_array('UP',array_map('strtoupper',array_map('trim',explode(',',$fm[1]))),true);
$mtu=(int)($inst->mtu??1500);$mtuOk=$exists&&preg_match('/\bmtu\s+'.preg_quote((string)$mtu,'/').'\b/',$txt)===1;
$assigned=false;$assignmentEnabled=false;$dynamic=false;$ipNone=false;
if(isset($cfg->interfaces))foreach($cfg->interfaces->children() as $ifcfg){if((string)($ifcfg->if??'')!==$iface)continue;$assigned=true;$assignmentEnabled=(string)($ifcfg->enable??'0')==='1';$dynamic=(string)($ifcfg->gateway_interface??'0')==='1';$v4=strtolower((string)($ifcfg->ipaddr??'none'));$v6=strtolower((string)($ifcfg->ipaddrv6??'none'));$ipNone=($v4===''||$v4==='none')&&($v6===''||$v6==='none');break;}
$pf=false;exec('/sbin/pfctl -sr 2>/dev/null',$rules,$prc);if($prc===0)foreach($rules as $r){if(strpos($r,'route-to')!==false&&preg_match('/\b'.preg_quote($iface,'/').'\b/',$r)){$pf=true;break;}}
$server=(string)($inst->server??'');$port=(int)($inst->port??0);
$checks=['xray_running'=>$xpid>1,'socks5_ready'=>$socks,'hev_running'=>$hpid>1,'tun_exists'=>$exists,'tun_owned'=>$owned,'tun_address_ok'=>$addr,'tun_up_ok'=>(bool)$up,'tun_mtu_ok'=>$mtuOk,'interface_assigned'=>$assigned,'interface_enabled'=>$assignmentEnabled,'interface_ip_config_none'=>$ipNone,'dynamic_gateway_policy'=>$dynamic,'pf_route_to_present'=>$pf];
// The local runtime chain must be complete before an end-to-end proxy probe can be meaningful.
// OPNsense assignment/gateway/PF remain diagnostic-only; the active probe itself verifies
// that the local SOCKS listener can reach the Internet through the configured Xray outbound.
$required=$checks['xray_running']&&$checks['socks5_ready']&&$checks['hev_running']&&$checks['tun_exists']&&$checks['tun_owned']&&$checks['tun_address_ok']&&$checks['tun_up_ok']&&$checks['tun_mtu_ok'];
if(!$required){
    $labels=['xray_running'=>'Xray process','socks5_ready'=>'SOCKS5 listener','hev_running'=>'HEV process','tun_exists'=>'HEV TUN','tun_owned'=>'HEV TUN ownership','tun_address_ok'=>'TUN /32 address','tun_up_ok'=>'TUN UP flag','tun_mtu_ok'=>'TUN MTU'];
    $missing=[];foreach($labels as $k=>$v)if(empty($checks[$k]))$missing[]=$v;
    output(['result'=>'failed','message'=>'Runtime incomplete: '.implode(', ',$missing),'checks'=>$checks,'server'=>$server,'port'=>$port,'online'=>false]);
}
$healthScript='/usr/local/opnsense/scripts/Xray/xray-health.php';
$healthOut=[];$healthRc=1;
exec('/usr/local/bin/php '.escapeshellarg($healthScript).' '.escapeshellarg($uuid).' 2>/dev/null',$healthOut,$healthRc);
$health=json_decode(implode("\n",$healthOut),true);
if(!is_array($health)){
    output(['result'=>'failed','message'=>'Runtime OK, but connectivity probe returned no valid result','checks'=>$checks,'server'=>$server,'port'=>$port,'online'=>false]);
}
$health['checks']=$checks;$health['server']=$server;$health['port']=$port;
output($health);
