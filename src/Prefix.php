<?php

declare(strict_types=1);

namespace SOFe\Pharynx;

final class Prefix{

    private function __construct(){}

    public static $prefix = "";

    public static function validatePrefix(string $path) : string{
        return str_replace(static::$prefix, "", $path);
    }
    
    public static function setPrefix(string $prefix) : void{
        static::$prefix = str_replace("\\", "/", $prefix);
    }
}