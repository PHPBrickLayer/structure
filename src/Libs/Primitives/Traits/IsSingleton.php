<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;

/**
 * Singleton Implementation
 */
trait IsSingleton {
    protected static self $instance;

    private function __construct(){}
    private function __clone(){}

    private static function SINGLETON() : self
    {
        if(!isset(static::$instance))
            self::$instance = new static();

        return static::$instance;
    }

    public static function instance() : self
    {
        return static::SINGLETON();
    }

    public static function new() : self
    {
        return static::SINGLETON();
    }
}