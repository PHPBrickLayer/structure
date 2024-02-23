<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\LayCopyDir;
use BrickLayer\Lay\Libs\LayUnlinkDir;
use BrickLayer\Lay\Libs\String\Pluralize;

trait Brick
{
    public function brick() : void
    {
        if(!isset($this->tags['make_brick']))
            return;

        $brick = $this->tags['make_brick'][0] ?? null;
        $singleton = $this->tags['make_brick'][1] ?? true;

        $talk = fn($msg) => $this->plug->write_talk($msg, ['silent' => true]);

        if (!$brick)
            $this->plug->write_fail("No brick specified");

        if(is_string($singleton) && !str_starts_with($singleton, "-"))
            $this->plug->write_warn("*$singleton* is not a valid tag.\n"
                . "This command only accepts *BrickName* and *--n-singleton* as it's arguments");

        if($singleton === "--n-singleton")
            $singleton = false;

        $talk("- If your brick is written in plural form, it'll be converted to singular form");

        $brick = str_replace("_", "", ucwords(Pluralize::to_singular($brick)));
        $brick_dir = $this->plug->server->bricks . $brick;
        $exists = is_dir($brick_dir);

        if (!$this->plug->force && $exists)
            $this->plug->write_fail(
                "Brick directory *$brick_dir* exists already!\n"
                . "If you wish to force this action, pass the tag *--force* with the command\n"
                . "Note, using *--force* will delete the existing directory and this process cannot be reversed!"
            );

        if ($exists) {
            $talk(
                "- Directory *$brick_dir* exists but *--force* tag detected\n"
                . "- Deleting existing *$brick_dir*"
            );

            new LayUnlinkDir($brick_dir);
        }

        $talk("- Creating new Brick directory in *$brick_dir*");
        new LayCopyDir($this->internal_dir . "Brick", $brick_dir);

        $talk("- Creating default brick files");

        $this->brick_default_files($brick, $brick_dir, $singleton);
    }

    public function brick_default_files(string $brick, string $brick_dir, bool $singleton): void
    {
        $brick_plural = Pluralize::to_plural($brick);
        // convert uppercase to _ and lowercase for the tables
        $brick_words = preg_split('/\B(?=[A-Z])/s', $brick_plural);
        $brick_table = strtolower(implode("_", $brick_words));
        $brick_plural = ucwords(implode("", $brick_words));

        $import = "";
        $body = "";
        $brick_init = "new $brick()";

        if($singleton) {
            $import = "use BrickLayer\Lay\Core\Traits\IsSingleton;";
            $body = "use IsSingleton;";
            $brick_init = "$brick::new()";
        }

        // default api hook
        file_put_contents(
            $brick_dir . $this->plug->s . "Api" . $this->plug->s . "Hook.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace bricks\\$brick\Api;
            
            use BrickLayer\Lay\Core\Api\ApiEngine;
            use BrickLayer\Lay\Core\Api\ApiHooks;
            
            // This is the alpha Api Hook class.
            // If you wish to create more individual hook classes,
            // know that those will not be executed automatically
            class Hook extends ApiHooks
            {
                // you can create public methods which can be used in other parts of your projects
                // In as much as this is an ApiHooks class, it is also a normal class,
                // So every normal class rule applies to it.
            
                public function hooks(): void
                {
                    // All hooks placed here are added to the general hooks of the project
                    // You don't have to do anything extra.
                }
            }
            
            FILE
        );

        /**
         * Default model file
         */
        // delete placeholder file
        unlink($brick_dir . $this->plug->s . "Model" . $this->plug->s . "model.php");

        // make brick default model file
        file_put_contents(
            $brick_dir . $this->plug->s . "Model" . $this->plug->s . "$brick.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace bricks\\$brick\Model;
            
            use JetBrains\PhpStorm\ArrayShape;
            use BrickLayer\Lay\Orm\SQL;
            $import
            
            class $brick
            {
                $body
                
                public static string \$table = "$brick_table";
                
                public static function orm(?string \$table = null) : SQL 
                {
                    if(\$table)
                        return SQL::instance()->open(\$table);
            
                    return SQL::instance();
                }
                
                #[ArrayShape(["code" => "int", "msg" => "string", "data" => "bool"])]
                public function add(array \$columns) : array 
                {
                    \$columns['id'] = \$columns['id'] ?? 'UUID()';
            
                    return [
                        "code" => 200,
                        "msg" => "Ok",
                        "data" => self::orm(self::\$table)->insert(\$columns)
                    ];
                }
            }
            
            FILE
        );

        /**
         * Default controller file
         */

        // delete placeholder file
        unlink($brick_dir . $this->plug->s . "Controller" . $this->plug->s . "controller.php");

        // make brick default controller
        file_put_contents(
            $brick_dir . $this->plug->s . "Controller" . $this->plug->s . "$brick_plural.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace bricks\\$brick\Controller;
            
            $import
            
            use bricks\\$brick\Model\\$brick;
            
            class $brick_plural
            {
                $body
                
                private static function model(): $brick
                {
                    return $brick_init;
                }
                
            }
            
            FILE
        );


    }
}