<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Annotate\CurrentRouteData;
use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Core\View\Tags\Anchor;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use Closure;
use JetBrains\PhpStorm\ExpectedValues;
use JetBrains\PhpStorm\NoReturn;

/**
 * @phpstan-import-type DomainRouteData from Domain
 */
final class ViewBuilder
{
    use IsSingleton;

    const DEFAULT_ROUTE = "*";

    private  const route_storage_key = "__LAY_VIEWS__";
    private  const view_constants = "__LAY_VIEW_PRELUDE__";

    private static bool $is_404 = false;
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
    private static Closure $default_handler;

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

    /**
     * @param string $key
     * @param mixed $value
     * @return ViewBuilder
     */
    public function local(string $key, mixed $value): self
    {
        return $this->store_page_data(ViewEngine::key_local, $key, $value);
    }

    public function set_response_code(ApiStatus|int $code): self
    {
        return $this->store_page_data(ViewEngine::key_page, "response_code", ApiStatus::get_code($code));
    }

    /**
     * @param string $section
     * @param string|null $key
     * @param mixed $value
     * @return ViewBuilder
     * @throws \Exception
     */
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
        return LayArray::to_object($this->get_route_details(self::view_constants));
    }

    public function get_route_details(string $route): ?array
    {
        return self::$route_container[self::route_storage_key][$route] ?? null;
    }

    public function end(): void
    {
        if (self::$view_found)
            return;

        $this->set_response_code(404);
        self::$is_404 = true;
        self::$route = self::DEFAULT_ROUTE;

        // push default handler to self::$route_container, so it can be accessed by the ViewEngine
        (self::$default_handler)($this);
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

    /**
     * Bind a page to a route
     * @param Closure $handler
     * @return $this
     */
    public function bind(Closure $handler): self
    {
        // Cache default page
        if (self::$route == self::DEFAULT_ROUTE)
            self::$default_handler = $handler;

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

    public function is_404(): bool
    {
        return self::$is_404;
    }

    public function is_found(): bool
    {
        return self::$view_found;
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


    /**
     * Get the metadata of a request received in a ViewBuilder class
     *
     * @param string $key
     * @return DomainRouteData
     */
    public function request(#[ExpectedValues(CurrentRouteData::ANNOTATE)] string $key): DomainType|string|array
    {
        if (!isset(self::$current_route_data))
            self::$current_route_data = Domain::current_route_data("*");

        if ($key == "*")
            return self::$current_route_data;

        return self::$current_route_data[$key] ?? '';
    }

    #[NoReturn]
    public function relocate(string $url, ?string $domain_id = null): void
    {
        header("location: " . Anchor::new()->href($url, $domain_id)->get_href());
        die;
    }

    #[NoReturn]
    public function redirect(string $route, ViewCast $viewCast): void
    {
        if (self::$view_found)
            Exception::throw_exception(
                "You cannot redirect an already rendered page, this may cause resources to load twice thereby causing catastrophic errors!",
                "ViewSentAlready"
            );

        if ($route == self::DEFAULT_ROUTE) {
            self::$is_404 = true;
            $this->invoke(fn() => $viewCast->default());
        }

        self::$redirecting = true;
        self::$route = $route;

        $this->rebuild_route();
        $viewCast->pages();

        die;
    }

    public function invoke(Closure $handler, bool $kill_on_done = true): void
    {
        self::$invoking = true;

        if(self::$route == self::DEFAULT_ROUTE)
            self::$is_404 = true;

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
            "use_lay_script",
            "skeleton",
            "append_site_name",
            "allow_page_embed",
            "page_embed_whitelist",
        ])]
        string $key,
        bool $value
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
            "charset",
            "base",
            "route",
            "url",
            "canonical",
            "title",
            "desc",
            "img",
            "author",
            "html_lang",
            "lang",
            "type",

            /**
             * [
             * 'last_mod' => '?int',
             * 'max_age' => 'int|string|null',
             * 'public' => 'bool|null',
             * ]
             */
            "cache",
        ])] string $key,
        string|null|array $value
    ): self
    {
        return $this->store_page_data(ViewEngine::key_page, $key, $value);
    }


    /**
     * Adds attributes to the html tag `<html>`
     * @param string|null $class Body class name
     * @param string|null $attribute Other attributes for the body tag
     * @return self
     */
    public function html_attr(?string $class = null, ?string $attribute = null): self
    {
        return $this->store_page_data(ViewEngine::key_html_attr, value: ["class" => $class, "attr" => $attribute]);
    }

    /**
     * Adds attributes to the html tag `<body>`
     * @param string|null $class Body class name
     * @param string|null $attribute Other attributes for the body tag
     * @return self
     */
    public function body_attr(?string $class = null, ?string $attribute = null): self
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
