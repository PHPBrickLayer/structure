<?php

namespace BrickLayer\Lay\Core\View;

abstract class ViewCast
{
    public readonly ViewBuilder $builder;

    public function __construct()
    {
        if(!isset($this->builder))
            $this->builder = ViewBuilder::new();
    }

    public function init_pages(): void
    {
        $this->builder->init_start()
            ->page('section', 'app');
        $this->builder->init_end();
    }

    final public function init(): void
    {
        if(!isset($this->builder))
            $this->builder = ViewBuilder::new();

        $this->init_pages();
        $this->default();
        $this->pages();

        $this->builder->end();
    }

    public function pages(): void
    {
        $this->builder->route("index")->bind(function (ViewBuilder $builder) {
            $builder->page("title", "Default Lay Page")
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
    public function default(): void
    {
        $this->builder->route($this->builder::DEFAULT_ROUTE)->bind(function (ViewBuilder $builder) {
            $builder
                ->page("title", $builder->request('route') . " - Page not found")
                ->body_attr("default-home")
                ->local("current_page", "error")
                ->local("section", "error")
                ->head(fn() => <<<ST
                <style>
                    body{
                        display: flex;
                        flex-direction: column;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        padding: 0;
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
                ST)
                ->body(function () { ?>
                    <h1><?= DomainResource::plaster()->page->title ?></h1>
                    <p>This is the default error page of Lay Framework</p>
                    <a class="return" href="<?= DomainResource::get()->domain->domain_uri ?>">Return Home</a>
                <?php });
        });
    }
}
