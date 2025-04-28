<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\Dir\LayDir;


trait JsConfig
{
    public function jsconfig(): void
    {
        if(!isset($this->tags['make_jsconfig']))
            return;

        $config_file = $this->plug->server->root . "jsconfig.json";

        $exists = file_exists($config_file);

        if (!$this->plug->force && $exists)
            $this->plug->write_fail(
                "JSConfig file *$config_file* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
                . "Note, using --force will delete the existing file and this process cannot be reversed!"
            );

        if ($exists) {
            $this->talk(
                "- JSConfig file *$config_file* exists but --force tag detected\n"
                . "- Deleting existing file: *$config_file*"
            );

            LayDir::unlink($config_file);
        }

        $this->dump_config_file($config_file);
    }

    private function dump_config_file(string $config_file): void
    {
        $static = "";
        $shared = "";

        foreach(\BrickLayer\Lay\Core\View\Domain::new()->list() as $domain_list) {
            $domain = explode("\\", $domain_list['builder'])[1];

            if($domain == "Api")
                continue;

            $static .= "\t\t\"./web/domains/$domain/static/dev/*\",\n";
            $shared .= "\t\t\"./web/domains/$domain/shared/static/dev/*\",\n";
        }

        $static = rtrim($static, ",\n");
        $static = ltrim($static, ",\t\t");

        $shared = rtrim($shared, ",\n");
        $shared = ltrim($shared, ",\t\t");

        // root index.php
        file_put_contents(
            $config_file,
            <<<FILE
            {
              "compilerOptions": {
                "baseUrl": ".",
                "module": "ES6",
                "target": "ES6",
                "moduleResolution": "node",
                "paths": {
                  "@static/*": [
                    $static 
                  ],
                  "@shared/*": [
                    $shared 
                  ]
                },
                "exclude": [
                  "node_modules",
                  "dist"
                ]
              }
            }
            
            FILE
        );
    }
}