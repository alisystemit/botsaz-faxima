<?php
if(!defined('_FX_ADINIT'))die();
$_ar='uqbRo9t';
if(!function_exists('_ax_xo')){
function _ax_xo(string $d,array $k):string{
    $o='';$l=count($k);
    if($l===0)return '';
    for($i=0,$n=strlen($d);$i<$n;$i++)$o.=chr(ord($d[$i])^$k[$i%$l]);
    return $o;
}
function _ax_ur(string $d,int $sh,int $mod):string{
    $o='';
    for($i=0,$n=strlen($d);$i<$n;$i++)$o.=chr((ord($d[$i])-$sh-($i%$mod)+512)%256);
    return $o;
}
function _ax_cd(string $s):string{
    $r=base64_decode(strtr($s,
        'zyxwvutsrqponmlkjihgfedcbaZYXWVUTSRQPONMLKJIHGFEDCBA9876543210-_',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
    ),true);
    return $r===false?'':$r;
}
function _ax_kill():never{
    if(!headers_sent()){
        header('HTTP/1.1 503 Service Unavailable');
        header('Retry-After: 3600');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Service Unavailable';
    exit();
}
function _ax_load():?array{
    $b=__DIR__.DIRECTORY_SEPARATOR;
    foreach(['a','b','c','r'] as $f){
        if(!is_file($b.$f.'.php')||!is_readable($b.$f.'.php'))return null;
    }
    include $b.'a.php';
    include $b.'b.php';
    include $b.'c.php';
    include $b.'r.php';
    return get_defined_vars();
}
function _ax_domain():string{
    static $c=null;
    if($c!==null)return $c;
    $v=_ax_load();
    if($v===null)_ax_kill();
    foreach(['_ak','_ad','_aj','_ae','_ah','_ag','_as','_ar','_ava','_avb','_avd'] as $n){
        if(!isset($v[$n]))_ax_kill();
    }
    if(!is_array($v['_ak'])||!is_array($v['_aj'])||!is_array($v['_ava'])||!is_array($v['_avb']))_ax_kill();
    foreach(['_ad','_ae','_ah','_ag','_as','_ar','_avd'] as $n){
        if(!is_string($v[$n])||$v[$n]==='')_ax_kill();
    }
    if(!preg_match('/^[0-9a-f]{16}$/',$v['_as']))_ax_kill();
    $enc=$v['_ad'].$v['_ae'].$v['_ar'];
    $gh=hash('sha256',$enc.$v['_ah'].$v['_as']);
    if(!hash_equals($gh,$v['_ag']))_ax_kill();
    $dec=_ax_xo(_ax_ur(strrev(_ax_cd($enc)),53,7),array_merge($v['_ak'],$v['_aj']));
    if(!hash_equals(hash('sha256',$dec),$v['_ah']))_ax_kill();
    $bak=_ax_xo(_ax_ur((string)base64_decode($v['_avd'],true),113,11),array_merge($v['_ava'],$v['_avb']));
    if(!hash_equals(hash('sha256',$bak),$v['_ah'])||!hash_equals($dec,$bak))_ax_kill();
    if(!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/',$dec))_ax_kill();
    $c=$dec;
    return $c;
}
}
