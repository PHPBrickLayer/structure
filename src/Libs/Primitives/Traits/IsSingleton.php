<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;
/**
 * Singleton Implementation
 */
trait IsSingleton {
    protected static self $instance;

    private function __construct(){}
    private function __clone(){}

    private  static function SINGLETON() : self
    {
        if(!isset(self::$instance))
            self::$instance = new self();
        return self::$instance;
    }

    public static function instance() : self
    {
        return self::SINGLETON();
    }

    public static function new() : self
    {
        return self::SINGLETON();
    }
}