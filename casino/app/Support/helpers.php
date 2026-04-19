<?php

use Illuminate\Support\Str;
if (! function_exists('settings')) {

    function settings($key = null, $default = null): mixed
    {
        $settings = Settings::first();
//        if (is_null($key)) {
//            return app('anlutro\LaravelSettings\SettingStore');
//        }
//
//        return app('anlutro\LaravelSettings\SettingStore')->get($key, $default);
        return null;
    }
}

function encoded($str)
{
    return base64_encode(base64_encode($str));
}
function decoded($str)
{
    return base64_decode(base64_decode($str));
}

function hpRand($digit = 4)
{
    return substr(rand(0, 12345) . strrev(time()), 0, $digit);
}
function hpRandStr($digit = 4)
{
    $random = Str::random($digit);
    return $random;
}
