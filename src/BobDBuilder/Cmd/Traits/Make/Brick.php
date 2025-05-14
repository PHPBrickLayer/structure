<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make;

use BrickLayer\Lay\Libs\Dir\LayDir;
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

        if(is_string($singleton) && !str_starts_with($singleton, "-")) {
            $this->plug->failed();
            $this->plug->write_warn("*$singleton* is not a valid tag.\n"
                . "This command only accepts *BrickName* and *--n-singleton* as it's arguments");
        }

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

            LayDir::unlink($brick_dir);
        }

        $talk("- Creating new Brick directory in *$brick_dir*");
        LayDir::copy($this->internal_dir . "Brick", $brick_dir);

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

        // default api hook
        file_put_contents(
            $brick_dir . $this->plug->s . "Api" . $this->plug->s . "Hook.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace Bricks\\$brick\Api;
            
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
            
            namespace Bricks\\$brick\Model;
            
            use BrickLayer\Lay\Libs\Primitives\Abstracts\BaseModelHelper;
            
            /**
             * @property string \$id
             * @property int \$created_at
             * @property int \$updated_at
            */
            class $brick extends BaseModelHelper
            {
                public static string \$table = "$brick_table";
                
            }
            
            FILE
        );

        $import = "";
        $body = "";

        if($singleton) {
            $import = "use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;";
            $body = "use IsSingleton;";
        }

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
            
            namespace Bricks\\$brick\Controller;
            
            $import
            
            use Bricks\\$brick\Model\\$brick;
            use Bricks\\$brick\Resource\\{$brick}Resource;
            
            class $brick_plural
            {
                $body
                
                public function list(): array
                {
                    return {$brick}Resource::collect(
                        (new $brick())->all()
                    );
                }
                
            }
            
            FILE
        );


        /**
         * Default resource file
         */

        // delete placeholder file
        unlink($brick_dir . $this->plug->s . "Resource" . $this->plug->s . "resource.php");

        // make brick default resource
        file_put_contents(
            $brick_dir . $this->plug->s . "Resource" . $this->plug->s . "{$brick}Resource.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace Bricks\\$brick\Resource;
            
            use BrickLayer\Lay\Libs\Primitives\Abstracts\ResourceHelper;
            use Bricks\\$brick\Model\\$brick;
            
            class {$brick}Resource extends ResourceHelper
            {
                
                protected function schema(object|array \$data): array
                {
                    \$data = new $brick(\$data);

                    return [
                        "id" => \$data->id,
                        "dateCreated" => \$data->created_at,
                        "dateUpdated" => \$data->updated_at ?? null,
                    ];
                }
                
            }
            
            FILE
        );

        /**
         * Default request file
         */

        // delete placeholder file
        unlink($brick_dir . $this->plug->s . "Request" . $this->plug->s . "request.php");

        // make brick default request
        file_put_contents(
            $brick_dir . $this->plug->s . "Request" . $this->plug->s . "Create{$brick}Request.php",
            <<<FILE
            <?php
            declare(strict_types=1);
            
            namespace Bricks\\$brick\Request;
            
            use BrickLayer\Lay\Libs\Primitives\Abstracts\RequestHelper;
            
            class Create{$brick}Request extends RequestHelper
            {
                
                protected function rules(): void
                {
                
                    \$this->vcm([ 'field' => 'field_name_1' ]);
                    \$this->vcm([ 'field' => 'field_name_2', 'required' => false ]);
                
                }
                
            }
            
            FILE
        );


    }
}