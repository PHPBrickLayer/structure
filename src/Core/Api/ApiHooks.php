<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\LayConfig;

abstract class ApiHooks
{
    public readonly ApiEngine $request;

    public function __construct(
        private readonly bool $prefetch = true
    ) {
        if(!isset($this->request))
            $this->request = ApiEngine::new();
    }

    public final function init() : void
    {
        LayConfig::connect();
        
        if($this->prefetch)
            $this->request::fetch();
        
        $this->hooks();
        $this->request::end();
    }
    
    public function hooks() : void
    {
        $this->request::fetch();
    }
}
