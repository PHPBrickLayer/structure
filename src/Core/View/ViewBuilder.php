<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Core\View\Tags\Anchor;
use BrickLayer\Lay\Libs\LayObject;
use Closure;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use JetBrains\PhpStorm\NoReturn;

final class ViewBuilder
{
    use IsSingleton;

    const DEFAULT_ROUTE = "*";
    const route_storage_key = "__LAY_VIEWS__";
    const view_constants = "__LAY_VIEW_PRELUDE__";

    private static bool $in_init = false;
    private static bool $redirecting = false;
    private static bool $invoking = false;
    private static bool $href_set = false;
    private static string $redirect_url;
    private static bool $alias_checked = false;
    private static array $current_route_data;
    private static string $route;
    private static array $route_aliases;
    private static array $route_container;
    private static bool $view_found = false;

    public function get_all_routes(): array
    {
        return self::$route_container[self::route_storage_key] ?? [];
    }

    public function connect_db(): self
    {
        LayConfig::connect();
        return $this;
    }

    public function init_start(): self
    {
        self::$in_init = true;

        if (!self::$href_set) {
            self::$href_set = true;
            $this->local("href", fn(?string $href = "", ?string $domain_id = null, ?bool $use_subdomain = null) => Anchor::new()->href($href, $domain_id, $use_subdomain)->get_href());
        }

        return $this;
    }

    public function local(string $key, mixed $value): self
    {
        return $this->store_page_data(ViewEngine::key_local, $key, $value);
    }

    private function store_page_data(string $section, ?string $key = null, mixed $value = null): self
    {
        if (self::$view_found)
            return $this;

        if (self::$in_init) {
            if (empty($key)) {
                self::$route_container[self::route_storage_key][self::view_constants][$section] = $value;
                return $this;
            }

            self::$route_container[self::route_storage_key][self::view_constants][$section][$key] = $value;
            return $this;
        }

        if (!isset(self::$route))
            Exception::throw_exception("No valid route found", "NoRouteFound");

        if (empty($key)) {
            self::$route_container[self::route_storage_key][self::$route][$section] = $value;
            return $this;
        }

        self::$route_container[self::route_storage_key][self::$route][$section][$key] = $value;
        return $this;
    }

    public function init_end(): void
    {
        self::$in_init = false;
        $this->store_constants();
    }

    private function store_constants(): void
    {
        ViewEngine::constants($this->get_route_details(self::view_constants) ?? []);
    }

    public function constants(): object
    {
        return LayObject::new()->to_object($this->get_route_details(self::view_constants));
    }

    public function get_route_details(string $route): ?array
    {
        return self::$route_container[self::route_storage_key][$route] ?? null;
    }

    public function end(): void
    {
        if (self::$view_found)
            return;

        ViewEngine::new()->paint($this->get_route_details(self::DEFAULT_ROUTE));
    }

    public function route(string $route, string ...$aliases): self
    {
        self::$route_aliases = [];
        self::$alias_checked = false;

        if (self::$view_found)
            return $this;

        self::$route = trim($route, "/");
        self::$route_aliases = $aliases;

        return $this;
    }

    public function bind(Closure $handler): self
    {
        // Cache default page
        if (self::$route == self::DEFAULT_ROUTE)
            $handler($this);

        if (self::$view_found)
            return $this;

        $route = null;

        if ($this->is_invoked()) {
            $route = "__INVOKED_URI__" . self::$route;
            self::$route = $route;
        }

        $route ??= $this->bind_uri();

        if (self::$route == $route) {
            if ($route == self::DEFAULT_ROUTE)
                return $this;

            $handler($this, self::$route, self::$route_aliases);
            $current_page = $this->get_route_details($route) ?? [];

            self::$view_found = true;

            if (isset($current_page['page']['title']) || @$current_page['core']['skeleton'] === false)
                ViewEngine::new()->paint($current_page);
        }

        return $this;
    }

    public function is_invoked(): bool
    {
        return self::$invoking;
    }

    private function bind_uri(): string
    {
        $data = $this->request('*');

        if (empty($data['route_as_array'][0]))
            $data['route_as_array'][0] = 'index';

        $found = false;
        $route_and_alias = [self::$route, ...self::$route_aliases];

        foreach ($route_and_alias as $route_index => $route) {
            self::$route = $route;
            $uri = explode("/", self::$route);
            $uri_size = count($uri);

            if (count($data['route_as_array']) == $uri_size) {
                foreach ($uri as $i => $u) {
                    $current_uri = $data['route_as_array'][$i];
                    $found = true;

                    if (str_starts_with($u, "{")) {
                        $data['route_as_array'][$i] = $u;
                        continue;
                    }

                    if ($current_uri != $u) {
                        $found = false;
                        break;
                    }
                }

                if(!$found && count($route_and_alias) != ($route_index))
                    continue;

                $data['route'] = implode("/", $data['route_as_array']);
                break;
            }
        }

        return $data['route'];
    }

    #[ArrayShape(['route' => 'string', 'route_as_array' => 'array', 'domain_type' => DomainType::class, 'domain_id' => 'string', 'domain_uri' => 'string', 'pattern' => 'string', 0, 1, 2, 3, 4, 5, 6, 7, 8])]
    public function request(#[ExpectedValues(['route', 'route_as_array', 'domain_type', 'domain_id', 'domain_uri', 'pattern', '*'])] string $key): DomainType|string|array
    {
        if (!isset(self::$current_route_data))
            self::$current_route_data = Domain::current_route_data("*");

        if ($key == "*")
            return self::$current_route_data;

        return self::$current_route_data[$key] ?? '';
    }

    #[NoReturn] public function relocate(string $url, ?string $domain_id = null): void
    {
        header("location: " . Anchor::new()->href($url, $domain_id)->get_href());
        die;
    }

    #[NoReturn] public function redirect(string $route, ViewCast $viewCast): void
    {
        if (self::$view_found)
            Exception::throw_exception(
                "You cannot redirect an already rendered page, this may cause resources to load twice thereby causing catastrophic errors!",
                "ViewSentAlready"
            );

        if ($route == self::DEFAULT_ROUTE)
            $this->invoke(fn() => $viewCast->default());

        self::$redirecting = true;
        self::$route = $route;

        $this->rebuild_route();
        $viewCast->pages();

        die;
    }

    public function invoke(Closure $handler, bool $kill_on_done = true): void
    {
        self::$invoking = true;

        $handler($this, self::$route, self::$route_aliases);

        if ($kill_on_done)
            die;
    }

    private function rebuild_route(): void
    {
        $details = $this->request("*");

        self::$current_route_data = array_merge($details, [
            "route" => self::$route,
            "route_as_array" => explode("/", self::$route),
        ]);
    }

    public function is_redirected(): bool
    {
        return self::$redirecting;
    }

    /**
     * Configure the behavior of the view engine
     * @param string $key
     * @param bool $value
     * @return self
     */
    public function core(
        #[ExpectedValues([
            "close_connection" => "bool [true]",
            "use_lay_script" => "bool [true]",
            "skeleton" => "bool [true]",
            "append_site_name" => "bool [true]",
        ])]
        string $key,
        bool   $value
    ): self
    {
        return $this->store_page_data(ViewEngine::key_core, $key, $value);
    }

    /**
     * Customize the current page meta-tags, title, etc
     * @param string $key
     * @param string|null $value
     * @return self
     */
    public function page(
        #[ExpectedValues([
            "charset" => "string [UTF-8]",
            "base" => "string",
            "route" => "string",
            "url" => "string",
            "canonical" => "string",
            "title" => "string",
            "desc" => "string",
            "img" => "string",
            "author" => "string",
        ])] string $key,
        ?string    $value
    ): self
    {
        return $this->store_page_data(ViewEngine::key_page, $key, $value);
    }

    /**
     * Adds attributes to the html body tag `<body>`
     * @param string|null $class Body class name
     * @param string|null $attribute Other attributes for the body tag
     * @return self
     */
    public function body_attr(
        #[ExpectedValues([
            "class" => "string",
            "attr" => "string",
        ])] ?string $class = null,
        ?string $attribute = null
    ): self
    {
        return $this->store_page_data(ViewEngine::key_body_attr, value: ["class" => $class, "attr" => $attribute]);
    }

    /**
     * @param string|Closure $file_or_func
     * This applies to the indicated route.
     *
     * You can pass the path of a view file located in the plaster folder of the current domain.
     *
     * Alternatively, You can create a closure that echoes the html you want to render.
     * @return self
     */
    public function head(string|Closure $file_or_func): self
    {
        return $this->store_page_data(ViewEngine::key_head, value: $file_or_func);
    }

    /**
     * @param string|Closure $file_or_func
     * This applies to the indicated route.
     *
     * You can pass the path of a view file located in the plaster folder of the current domain.
     *
     * Alternatively, You can create a closure that echoes the html you want to render.
     * @return self
     */
    public function body(string|Closure $file_or_func): self
    {
        return $this->store_page_data(ViewEngine::key_body, value: $file_or_func);
    }

    /**
     * @param string|Closure $file_or_func
     * This applies to the indicated route.
     *
     * You can pass the path of a view file located in the plaster folder of the current domain.
     *
     * Alternatively, You can create a closure that echoes the html you want to render.
     * @return self
     */
    public function script(string|Closure $file_or_func): self
    {
        return $this->store_page_data(ViewEngine::key_script, value: $file_or_func);
    }

    /**
     * @param string|array ...$assets
     * Assets to be used on the specified route.

     * Each entry can either be a string or an associative array.
     * The associative array that accepts valid html attributes
     * for `link` and
     *
     * @example "@css/another.css"
     * @example ["src" => "@css/another.css", "lazy" => true ]
     * @return self
     */
    public function assets(string|array ...$assets): self
    {
        return $this->store_page_data(ViewEngine::key_assets, value: $assets);
    }

}
