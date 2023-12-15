<?php

namespace BrickLayer\Lay\core\api;

use BrickLayer\Lay\core\LayConfig;

abstract class ApiHooks
{
    public readonly ApiEngine $request;

    public function __construct(
        private bool $prefetch = true
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
