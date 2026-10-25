<?php
if(!defined('_FX_ADINIT'))define('_FX_ADINIT',1);
if(!function_exists('_ax_domain')){
    if(!is_file(__DIR__.'/ad/r.php')){
        if(!headers_sent()){header('HTTP/1.1 503 Service Unavailable');header('Retry-After: 3600');header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');}
        echo 'Service Unavailable';
        exit();
    }
    require_once __DIR__.'/ad/r.php';
}
if(!function_exists('fx_ad_gate')){
    function fx_ad_gate():string{
        return _ax_domain();
    }
}
fx_ad_gate();
