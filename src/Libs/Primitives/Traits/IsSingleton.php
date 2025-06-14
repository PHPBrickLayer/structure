<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;

/**
 * Singleton Implementation
 */
trait IsSingleton {
    protected static self $instance;

    private function __construct(){}
    private function __clone(){}

    private static function SINGLETON() : static
    {
        if(!isset(static::$instance))
            static::$instance = new static();

        return static::$instance;
    }

    public static function instance() : static
    {
        return static::SINGLETON();
    }

    public static function new() : static
    {
        return static::SINGLETON();
    }
}