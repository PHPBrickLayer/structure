<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Api\ApiEngine;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayFn;
use Closure;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\View\Tags\Link;
use BrickLayer\Lay\Core\View\Tags\Script;

//TODO: Find a way to generate static pages for domains
// The static pages should be able to use classes when necessary for things that are dynamic like a blog page

/**
 * Page Creator
 */
final class ViewEngine {
    use IsSingleton;
    private const view_engine_session = "__LAY_VIEW_ENGINE__";

    const key_core = "core";
    const key_page = "page";
    const key_html_attr = "html_attr";
    const key_body_attr = "body_attr";
    const key_body = "body";
    const key_head = "head";
    const key_script = "script";
    const key_assets = "assets";
    const key_local = "local";

    private static array $constant_attributes = [];
    private static array $assets = [];
    private static object $meta_data;
    private static array $head_styles;

    public static function constants(array $const) : void {
        $route = ViewBuilder::new()->request('route');
        $url = DomainResource::get()->domain->domain_uri . ($route == "index" ? "" : $route);

        self::$constant_attributes = [
            self::key_core => [
                "use_lay_script" => $const[self::key_core]['use_lay_script'] ?? true,
                "skeleton" => $const[self::key_core]['skeleton'] ?? true,
                "append_site_name" => $const[self::key_core]['append_site_name'] ?? true,
                "allow_page_embed" => $const[self::key_core]['allow_page_embed'] ?? true,
                "page_embed_whitelist" => $const[self::key_core]['page_embed_whitelist'] ?? [],
            ],
            self::key_page => [
                "charset" =>  $const[self::key_page]['charset'] ?? "UTF-8",
                "base" =>  $const[self::key_page]['base'] ?? null,
                "route" => $const[self::key_page]['route'] ?? $route,
                "url" => $const[self::key_page]['url'] ?? $url,
                "canonical" => $const[self::key_page]['canonical'] ?? $url,
                "title" => $const[self::key_page]['title'] ?? "Untitled Page",
                "desc" => $const[self::key_page]['desc'] ?? "",
                "img" => $const[self::key_page]['img'] ?? null,
                "author" => $const[self::key_page]['author'] ?? null,
                "lang" => $const[self::key_page]['lang'] ?? "en_US",
                "html_lang" => $const[self::key_page]['html_lang'] ?? "en",
                "type" => $const[self::key_page]['type'] ?? "website",
                "response_code" => $const[self::key_page]['response_code'] ?? 200,
                "cache" => $const[self::key_page]['cache'] ?? null,
            ],
            self::key_html_attr =>  [
                "class" =>  $const[self::key_html_attr]['class'] ?? null,
                "attr" =>   $const[self::key_html_attr]['attr'] ?? null,
            ],
            self::key_body_attr =>  [
                "class" =>  $const[self::key_body_attr]['class'] ?? null,
                "attr" =>   $const[self::key_body_attr]['attr'] ?? null,
            ],
            /**
             * `view` is an array that accepts three [optional] keys for each section of the html page,
             *     `head` for <link>, <meta> tags or anything you wish to put in the <head>.
             *     `body` for anything that needs to go into the <body> tag, including <script>
             *     `script` is used to explicitly include <script> tags or anything you may wish to add
             *         before the closing of the </body> tag.
             *
             * The keys can be a void Closure that accepts the `$meta` array parameter as its argument.
             *     @example: 'head' => function($meta) : void {echo '<meta name="robots" content="allow" />'; }.
             *
             * The keys can be a string, this string is the location of the file inside the view folder.
             *     The file extension is `.view` when your key is `body`; but `.inc` when it's others.
             *     This means ViewPainter looks for files the value of `body` key inside the view folder,
             *     while it looks for the value of the other keys, inside the includes folder.
             *
             *     `ViewPainter` will look for the files in a folder that matches {__front|__back} depending on the value
             *     of `$meta[self::key_page]['type']`.
             *    @example: 'head' => 'header', 'body' => 'homepage',
             **/
            self::key_head => $const[self::key_head] ?? null,
            self::key_body => $const[self::key_body] ?? null,
            self::key_script => $const[self::key_script] ?? null,
            /**
             * `assets` searches for assets based on the `ARRAY_KEY`/`PATH_TO_FILE`
             * @example "assets" => [ "@shared_js/contact-us.js", "@css/style.css" ].
             * The entries can also be an array:
             * @example "assets" => [ ["src" => "@shared_js/contact-us.js", "async" => true, "type" => "text/javascript"], ]
             **/
            self::key_assets => $const[self::key_assets] ?? [],
            self::key_local => $const[self::key_local] ?? [],
        ];
    }

    private function add_cache_header(?array $cache = null) : void
    {
        if(is_null($cache))
            return;

        ApiEngine::add_cache_header(
            $cache['last_mod'] ?? null,
            [
                "max_age" => $cache['max_age'] ?? null,
                "public" => $cache['public'] ?? true,
            ]
        );
    }

    public function paint(array $page_data) : void {
        if(empty(self::$constant_attributes))
            self::constants([]);

        $layConfig = LayConfig::new();
        $data = $layConfig::site_data();

        $const = array_replace_recursive(self::$constant_attributes, $page_data);;

        $const[self::key_page]['title_raw'] = $const[self::key_page]['title'];

        if(strtolower($const[self::key_page]['title_raw']) == "homepage"){
            $const[self::key_page]['title'] = $data->name->long;
            $const[self::key_page]['title_raw'] = $data->name->short;
        }
        else{
            $const[self::key_page]['title'] = !$const[self::key_core]['append_site_name'] ?
                $const[self::key_page]['title_raw'] :
                $const[self::key_page]['title_raw'] . " :: " . $data->name->short;
        }

        self::$assets = $const[self::key_assets];
        unset($const[self::key_assets]);

        self::$meta_data = LayArray::to_object($const);

        if($const[self::key_core]['skeleton'])
            $this->create_html_page($const[self::key_page]['cache']);
        else {
            $x = $this->skeleton_body();

            $this->add_cache_header($const[self::key_page]['cache']);

            echo $x;
        }
    }

    private function create_html_page(?array $cache = null) : void {
        $meta = self::$meta_data;

        $layConfig = LayConfig::instance();
        $site_data = $layConfig::site_data();
        $client = DomainResource::get();
        $page = $meta->{self::key_page};
        $env = $layConfig::$ENV_IS_PROD ? "PROD" : "DEV";
        $iframe_embed = $page->iframe_embed ?? true;

        $lay_api = $layConfig->get_global_api() ?? $client->domain->domain_uri . "api/";
        $img = ViewSrc::gen($page->img ?? $client->shared->img_default->meta ?? $client->shared->img_default->logo);
        $favicon = ViewSrc::gen($client->shared->img_default->favicon);
        $author = $page->author ?? $site_data->author;
        $title = $page->title;
        $title_raw = $page->title_raw;
        $base = $page->base ?? $client->domain->domain_uri;
        $charset = $page->charset;
        $desc = $page->desc;
        $color = $site_data->color->pry;
        $route = $client->domain->route;
        $route_array = json_encode($client->domain->route_as_array);
        $canonical = <<<LINK
            <link rel="canonical" href="$page->canonical" />
        LINK;
        $html_attr = $meta->{self::key_html_attr};
        $body_attr = $meta->{self::key_body_attr};
        $iframe_embed_blocker = <<<FRAME
        <!-- Prevent your website from being embedded via iframe -->
        <script>if (window.top !== window.self) { window.top.location.replace(window.self.location.href); }</script>
        FRAME;

        if(isset($cache['public']))
            $canonical .= $cache['public'] ? '<meta http-equiv="Cache-control" content="public">' : '<meta http-equiv="Cache-control" content="private">';

        if($iframe_embed)
            $iframe_embed_blocker = "";

        // The reason we put skeleton_body() in a variable is to give the function time to run so that variables can be
        // extracted and used by other functions like the skeleton_head()
        $body = $this->skeleton_body();

        $page = <<<STR
        <html itemscope lang="$page->html_lang" id="LAY-HTML" class="$html_attr->class" $html_attr->attr>
        <head>
            <title id="LAY-PAGE-TITLE-FULL">$title</title>
            <base href="$base" id="LAY-PAGE-BASE">
            <meta http-equiv="content-type" content="text/html;charset=$charset">
            <meta name="description" id="LAY-PAGE-DESC" content="$desc">
            <meta name="author" content="$author">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <meta name="theme-color" content="$color">
            <meta name="msapplication-navbutton-color" content="$color">
            <meta name="msapplication-tap-highlight" content="no">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            
            <!-- Framework Tags-->
            <meta property="lay:site_name_short" id="LAY-SITE-NAME-SHORT" content="{$site_data->name->short}">
            <meta property="lay:url" id="LAY-PAGE-URL" content="$page->route">
            <meta property="lay:domain" id="LAY-DOMAIN-NAME" content="{$client->domain->domain_name}">
            <meta property="lay:domain_id" id="LAY-DOMAIN-ID" content="{$client->domain->domain_id}">
            <meta property="lay:meta:host" id="LAY-HOST" content="{$client->domain->host}">
            <meta property="lay:meta:api" id="LAY-API" content="$lay_api">
            <meta property="lay:meta:upload" id="LAY-UPLOAD" content="$client->upload">
            <meta property="lay:meta:shared_root" id="LAY-SHARED-ROOT" content="{$client->shared->root}">
            <meta property="lay:meta:shared_img" id="LAY-SHARED-IMG" content="{$client->shared->img}">
            <meta property="lay:meta:shared_env" id="LAY-SHARED-ENV" content="{$client->shared->env}">
            <meta property="lay:meta:domain_root" id="LAY-DOMAIN-ROOT" content="$client->root">
            <meta property="lay:meta:static_img" id="LAY-STATIC-IMG" content="$client->img">
            <meta property="lay:meta:static_env" id="LAY-STATIC-ENV" content="$client->static_env">
            <meta property="lay:meta:route" id="LAY-ROUTE" content="$route">
            <meta property="lay:meta:route_as_array" id="LAY-ROUTE-AS-ARRAY" content='$route_array'>
            <meta property="lay:meta:env" id="LAY-ENVIRONMENT" content='$env'>
            <!-- // Framework Tags-->
            
            <!-- Facebook Tags -->
            <meta property="og:locale" content="$page->lang" />
            <meta property="og:url" id="LAY-PAGE-FULL-URL" content="$page->url">
            <meta property="og:type" content="$page->type">
            <meta property="og:title" id="LAY-PAGE-TITLE" content="$title_raw">
            <meta property="og:description" content="$desc">
            <meta property="og:image" content="$img">
            <meta property="og:site_name" id="LAY-SITE-NAME" content="{$site_data->name->full}">
            <!-- // Facebook Tags -->
            
            <!--Twitter Tags -->
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:site" content="{$site_data->name->full}">
            <meta name="twitter:title" content="$title_raw">
            <meta name="twitter:description" content="$desc">
            <meta name="twitter:image" content="$img">
            <meta property="twitter:domain" content="{$client->domain->domain_uri}">
            <meta property="twitter:url" content="$page->url">
            <!--//Twitter Tags -->
            
            <!--Generic Tags -->
            <meta itemprop="name" content="$title">
            <meta itemprop="description" content="$desc">
            <meta itemprop="image" id="LAY-PAGE-IMG" content="$img">
            <!--//Generic Tags -->
            
            <link rel="icon" type="image/x-icon" href="$favicon">
            <link rel="shortcut icon" href="$favicon">
            <link rel="apple-touch-icon" href="$favicon">
            $canonical
            {$this->skeleton_head()}
            
            $iframe_embed_blocker
        </head>
        <body class="$body_attr->class" $body_attr->attr>
        $body
        </body></html>
        STR;

        if($layConfig::$ENV_IS_PROD && $layConfig::is_page_compressed()) {
            $page = str_replace(
                ['@styletop', '@scripttop', '@style', '@script'],
                ["@styletop ", "@scripttop ", "@style ", "@script "],
                $page
            );
            $page = preg_replace("/>(\s)+</m", "><", preg_replace("/<!--(.|\s)*?-->/", "", $page));
        }

        $x = "<!DOCTYPE html>\n" . $page;

        $this->add_cache_header($cache);

        http_response_code($meta->{self::key_page}->response_code);
        LayFn::header("Content-Type: text/html");

        echo $x;
    }

    private function skeleton_head() : string
    {
        $onpage_styles_pre = "";
        $onpage_styles_app = "";

        if(isset(self::$head_styles)){
            $onpage_styles_pre = self::$head_styles['pre'];
            $onpage_styles_app = self::$head_styles['app'];
        }

        ob_start();

        echo $onpage_styles_pre;
        $this->add_view_section(self::key_head);
        $this->dump_assets("css");
        echo $onpage_styles_app;

        return ob_get_clean();
    }

    private function skeleton_body() : string
    {
        ob_start();
        $this->add_view_section(self::key_body);
        $body = ob_get_clean();

        $parsed = $this->parse_html_content($body);

        self::$head_styles = [
            "pre" => implode("\n", $parsed['style_top']),
            "app" => implode("\n", $parsed['style_dwn']),
        ];

        ob_start();

        if(empty($parsed['html_content'])) {
            $length = LayFn::num_format(strlen($body), 6) . "B";
            Exception::log("Parsed HTML content is empty. Body Size: $length", log_title: "ViewEngine::PageTooLarge");
            echo "<h1 style='padding: 2rem'>HTML Page Could Not Be Parsed. Size: $length</h1>";
        }
        else
            echo implode('', $parsed['html_content']);

        $this->core_script();
        echo implode('', $parsed['script_top']);
        $this->add_view_section(self::key_script);
        $this->dump_assets("js");
        echo implode('', $parsed['script_dwn']);

        return ob_get_clean();
    }

    public function parse_html_content(string $body): array
    {
        $sections = [
            'style_top' => [],
            'script_top' => [],
            'style_dwn' => [],
            'script_dwn' => [],
            'html_content' => [],
        ];

        $tags = [
            '@styletop' => ['end' => '@endstyle', 'key' => 'style_top'],
            '@scripttop' => ['end' => '@endscript', 'key' => 'script_top'],
            '@style' => ['end' => '@endstyle', 'key' => 'style_dwn'],
            '@script' => ['end' => '@endscript', 'key' => 'script_dwn'],
        ];

        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $next_tag_pos = $length;
            $next_tag = null;

            // Find the nearest tag
            foreach ($tags as $start => $meta) {
                $pos = strpos($body, $start, $offset);
                if ($pos !== false && $pos < $next_tag_pos) {
                    $next_tag_pos = $pos;
                    $next_tag = $start;
                }
            }

            // If no tags found, the rest is HTML content
            if ($next_tag === null) {
                $sections['html_content'][] = substr($body, $offset);
                break;
            }

            // Capture content before the tag as HTML
            if ($next_tag_pos > $offset) {
                $sections['html_content'][] = substr($body, $offset, $next_tag_pos - $offset);
            }

            $end_tag = $tags[$next_tag]['end'];
            $block_start = $next_tag_pos + strlen($next_tag);
            $end_pos = strpos($body, $end_tag, $block_start);

            if ($end_pos === false) {
                // Broken layout block, skip
                break;
            }

            $content = substr($body, $block_start, $end_pos - $block_start);
            $sections[$tags[$next_tag]['key']][] = trim($content);

            $offset = $end_pos + strlen($end_tag);
        }

        return $sections;
    }

    private function add_view_section(string $view_section) : void
    {
        $meta = self::$meta_data;
        $meta_view = $meta->{$view_section};
        DomainResource::make_plaster(self::$meta_data);

        // Accept the type of unique view type from the current page and store it in the `$meta_view` variable.
        // This could be a view file, which will simply be the filename without its file extension (.view).
        // Or use a closure which may or may not return a string; If not returning a string, it should echo a string.
        ob_start();

        if($meta_view instanceof Closure)
            echo $meta_view($meta);

        elseif($meta_view)
            echo $this->insert_view(explode(".view", $meta_view)[0], "view", true);

        self::$meta_data->{$view_section} = ob_get_clean();

        // This includes the `inc file` related to the section.
        // That is: body.inc for `body section`, head.inc for `head section`.
        if($meta->{self::key_core}->skeleton === true)
            $this->insert_view($view_section, "inc", false);

        // The echos the body value without the skeleton of Lay.
        // This only works with the `body` method, it doesn't work with `head` or `script` method
        else {
            DomainResource::make_plaster(self::$meta_data);
            echo self::$meta_data->{$view_section};
        }
    }

    private function insert_view(?string $file, string $type, bool $as_string) : ?string
    {
        $domain = DomainResource::get()->domain;
        $inc_root = $domain->layout;
        $view_root = $domain->plaster;
        $root = $type == "inc" ? $inc_root : $view_root;
        $type = "." . $type;

        $file = $root . $file . $type;

        DomainResource::make_plaster(self::$meta_data);

        if(!file_exists($file))
            Exception::throw_exception("execution Failed trying to include file ($file)","FileNotFound");

        if($as_string) {
            ob_start();
            include_once $file;
            return ob_get_clean();
        }

        include_once $file;
        return null;
    }

    private function dump_assets(string $asset_type) : void
    {
        $resolve_asset = function (string|array &$asset, string|int $assets_key) use ($asset_type) : string {
            $asset_template = $asset_type == "js" ?
                fn ($src, $attr = []) => $this->script_tag_template($src, $attr):
                fn ($href, $attr = []) => $this->link_tag_template($href, $attr);

            // If the asset item found is not the asset type indicated.
            // That is: if Painter is looking for `js` file, and it sees css, it should return an empty string.
            if(is_string($asset) && !str_ends_with($asset,".$asset_type"))
                return "";

            // if the asset item is an array;
            // ['src' => asset_file, 'defer' => true, 'async' => false, 'type' => 'text/javascript']
            if (is_array($asset)) {
                if(isset($asset['href'])) {
                    $asset['src'] = $asset['href'];
                    unset($asset['href']);
                }

                if (isset($asset['src'])) {
                    if(!str_ends_with($asset['src'],".$asset_type"))
                        return "";

                    // cleanup the array after adding the asset
                    if(is_int($assets_key))
                        unset(self::$assets[$assets_key]);

                    if(empty($asset['src']))
                        return "";

                    return $asset_template($asset['src'], $asset);
                }

                Exception::throw_exception("Trying to add assets as an array, but the `src` key was not specified","RequiredKeyIgnored");
            }

            if(empty($asset))
                return "";

            // cleanup the array after adding the asset
            if(is_int($assets_key))
                unset(self::$assets[$assets_key]);

            return $asset_template($asset);
        };

        $view = "";

        foreach (self::$assets as $k => $asset) {
            $view .= $resolve_asset($asset, $k);
        }

        echo $view;
    }

    private function link_tag_template(string $href, array $attributes = []) : string
    {
        $rel = $attributes['rel'] ?? "stylesheet";
        $lazy_load = $attributes['lazy'] ?? false;

        if(isset($attributes['rel']))
            unset($attributes['rel']);

        if(isset($attributes['href']))
            unset($attributes['href']);

        if($lazy_load)
            unset($attributes['lazy']);

        $link = Link::new();

        foreach ($attributes as $i => $a) {
            $link->attr($i, $a);
        }

        return $link->rel($rel)->href($href, false, $lazy_load);
    }

    private function script_tag_template(string $src, array $attributes = []) : string
    {
        $defer = str_replace([1, true], 'true', (string)filter_var($attributes['defer'] ?? true, FILTER_VALIDATE_INT));

        if (isset($attributes['src']))
            unset($attributes['src']);

        if (isset($attributes['defer']))
            unset($attributes['defer']);

        $script = Script::new();

        foreach ($attributes as $i => $a) {
            $script->attr($i, $a);
        }

        return $script->defer((bool) $defer)->src($src, false);
    }

    private function core_script() : void {
        $meta = self::$meta_data;
        $layConfig = LayConfig::new();
        $js_template = fn ($src, $attr = []) => $this->script_tag_template($src, $attr);
        $core_script = "";

        if(!$meta->{self::key_core}->use_lay_script) {
            echo $core_script;
            return;
        }

        $s = DIRECTORY_SEPARATOR;
        $domain = DomainResource::get();
        $lay_base = $domain->lay->uri;
        $lay_root = $domain->lay->root;

        list($omj, $const) = null;

        if ($layConfig::$ENV_IS_PROD) {
            if (file_exists($lay_root . $s . 'index.min.js'))
                $omj = $js_template($lay_base . 'index.min.js', ['defer' => false]);

            if (file_exists($lay_root . $s . "constants.min.js"))
                $const = $js_template($lay_base . 'constants.min.js', ['defer' => false]);
        }

        $core_script .= $omj ?? $js_template($lay_base . 'index.js',['defer' => false]);
        $core_script .= $const ?? $js_template($lay_base . 'constants.js', ['defer' => false]);

        echo $core_script;

        echo <<<MODULE_MAP
        <script type="importmap">
        {
            "imports": {
                "@static/": "$domain->static_env",
                "@shared/": "{$domain->shared->static}"
            }
        }
        </script>
        MODULE_MAP;
    }
}
