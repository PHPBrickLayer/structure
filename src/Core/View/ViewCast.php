<?php

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Libs\LayFn;

abstract class ViewCast extends ViewBuilder
{
    public readonly ViewBuilder $builder;

    final public function __construct()
    {
        $this->builder = $this;
    }

    final public function init(): void
    {
        $this->init_pages();
        $this->default();
        $this->pages();

        $this->end();
    }

    protected function init_pages(): void
    {
        $this->init_start()
            ->page("cache", null)
            ->local('section', 'app');
        $this->init_end();
    }

    protected function pages(): void
    {
        $this->route("index")->bind(function (ViewCast $builder) {
            $this->page("title", "Default Lay Page")
                ->page("desc", "A default description. This goes to the meta tags responsible for the page description")
                ->local("current_page", "home")
                ->body("homepage");
        });
    }

    /**
     * This page loads when no route is found.
     * It can be used as a 404 error page
     * @return void
     */
    protected function default(): void
    {
        $this->route($this->builder::DEFAULT_ROUTE)->bind(function (ViewCast $builder) {
            LayFn::http_response_code(404, true);

            $this
                // Using a custom Error page?
                ->core("skeleton", false) // Remove this
                ->core("use_lay_script", false) // Remove this
                ->page("title", $this->request('route') . " - Page not found")
                ->local("section", "error")
                ->body(function () { ?>
                    <style>
                        body{
                            display: flex;
                            flex-direction: column;
                            justify-content: center;
                            align-items: center;
                            height: 100vh;
                            padding: 0;
                            max-width: 80%;
                            margin: auto
                        }
                        .return{
                            color: #000;
                            font-weight: 600;
                            text-decoration: none;
                            background: transparent;
                            border: solid 1px #000;
                            padding: 10px;
                            border-radius: 30px;
                            transition: all ease-in-out .3s;
                        }
                        .return:hover{
                            background: #fff;
                            border-color: #fff;
                            color: #000;
                        }
                    </style>
                    <h1 style="text-align: center"><?= DomainResource::plaster()->page->title_raw ?></h1>
                    <p style="font-size: 1rem">Error 404: This page may have been deleted or moved</p>
                    <a class="return" href="<?= DomainResource::get()->domain->domain_uri ?>">Return Home</a>

                <?php });
        });
    }
}
