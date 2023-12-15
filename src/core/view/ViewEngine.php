<?php
declare(strict_types=1);
namespace BrickLayer\Lay\core\view;

use BrickLayer\Lay\libs\LayObject;
use Closure;
use BrickLayer\Lay\core\Exception;
use BrickLayer\Lay\core\LayConfig;
use BrickLayer\Lay\core\traits\IsSingleton;
use BrickLayer\Lay\core\view\tags\Link;
use BrickLayer\Lay\core\view\tags\Script;

/**
 * Page Creator
 */
final class ViewEngine {
    use IsSingleton;
    private const view_engine_session = "__LAY_VIEW_ENGINE__";

    const key_core = "core";
    const key_page = "page";
    const key_body_attr = "body_attr";
    const key_body = "body";
    const key_head = "head";
    const key_script = "script";
    const key_assets = "assets";
    const key_local = "local";

    private static array $constant_attributes = [];
    private static array $assets = [];
    private static object $meta_data;

    public static function constants(array $const) : void {
        $route = ViewBuilder::new()->request('route');
        $url = DomainResource::get()->domain->domain_uri . ($route == "index" ? "" : $route);

        self::$constant_attributes = [
            self::key_core => [
                "close_connection" => $const[self::key_core]['close_connection'] ?? true,
                "use_lay_script" => $const[self::key_core]['use_lay_script'] ?? true,
                "skeleton" => $const[self::key_core]['skeleton'] ?? true,
                "append_site_name" => $const[self::key_core]['append_site_name'] ?? true,
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
             * `assets` searches for assets based on the `ARRAY_KEY`/`DIRECTORY_NAME`
             * @example "assets" => [ "@shared_js/contact-us.js", "@css/style.css" ].
             * The entries can also be an array:
             * @example "assets" => [ ["src" => "@shared_js/contact-us.js", "async" => true, "type" => "text/javascript"], ]
             **/
            self::key_assets => $const[self::key_assets] ?? [],
            self::key_local => $const[self::key_local] ?? [],
        ];
    }

    public function paint(array $page_data) : void {
        if(empty(self::$constant_attributes))
            self::constants([]);

        $layConfig = LayConfig::new();
        $data = $layConfig::site_data();


        $const = array_replace_recursive(self::$constant_attributes, $page_data);;

        $const[self::key_page]['title_raw'] = $const[self::key_page]['title'];

        if(strtolower($const[self::key_page]['title_raw']) == "homepage"){
            $const[self::key_page]['title'] = $data->name->full;
            $const[self::key_page]['title_raw'] = $data->name->short;
        }
        else{
            $const[self::key_page]['title'] = !$const[self::key_core]['append_site_name'] ?
                $const[self::key_page]['title_raw'] :
                $const[self::key_page]['title_raw'] . " :: " . $data->name->short;
        }

        self::$assets = $const[self::key_assets];
        unset($const[self::key_assets]);

        self::$meta_data = LayObject::new()->to_object($const);
        $this->create_html_page();
    }

    private function create_html_page() : void {
        $meta = self::$meta_data;

        $layConfig = LayConfig::instance();
        $site_data = $layConfig::site_data();
        $client = DomainResource::get();
        $page = $meta->{self::key_page};

        $lay_api = $site_data->global_api ?? $site_data->domain . "api/";
        $img = ViewSrc::gen($page->img ?? $client->shared->img_default->meta ?? $client->shared->img_default->logo);
        $favicon = ViewSrc::gen($client->shared->img_default->favicon);
        $author = $page->author ?? $site_data->author;
        $title = $page->title;
        $title_raw = $page->title_raw;
        $base = $page->base ?? $client->domain->domain_uri;
        $charset = $page->charset;
        $desc = $page->desc;
        $color = $site_data->color->pry;
        $canonical = <<<LINK
            <link rel="canonical" href="$page->canonical" />
        LINK;
        $body_attr = $meta->{self::key_body_attr};

        $page = <<<STR
        <!DOCTYPE html>
        <html itemscope lang="en" id="LAY-HTML">
        <head>
            <title id="LAY-PAGE-TITLE-FULL">$title</title>
            <base href="$base" id="LAY-PAGE-BASE">
            <meta http-equiv="content-type" content="text/html;charset=$charset" />
            <meta name="description" id="LAY-PAGE-DESC" content="$desc">
            <meta name="author" content="$author">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
            <meta name="theme-color" content="$color">
            <meta name="msapplication-navbutton-color" content="$color">
            <meta name="msapplication-tap-highlight" content="no">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <!-- Framework Tags-->
            <meta property="lay:site_name_short" id="LAY-SITE-NAME-SHORT" content="{$site_data->name->short}">
            <meta property="lay:url" id="LAY-PAGE-URL" content="$page->route">
            <!-- // Framework Tags-->
            <meta property="og:title" id="LAY-PAGE-TITLE" content="$title_raw">
            <meta property="og:url" id="LAY-PAGE-FULL-URL" content="$page->url">
            <meta property="og:type" content="website">
            <meta property="og:site_name" id="LAY-SITE-NAME" content="{$site_data->name->full}">
            <meta property="og:description" content="$desc">
            <meta property="og:image" content="$img">
            <meta itemprop="name" content="$title">
            <meta itemprop="description" content="$desc">
            <meta itemprop="image" id="LAY-PAGE-IMG" content="$img">
            <link rel="icon" type="image/x-icon" href="$favicon">
            <link rel="shortcut icon" href="{$base}favicon.ico">
            <link rel="apple-touch-icon" href="$favicon" />
            $canonical
            {$this->skeleton_head()}
        </head>
        <body class="$body_attr->class" $body_attr->attr>
            <!--//START LAY CONSTANTS-->
            <input type="hidden" id="LAY-API" value="$lay_api">
            <input type="hidden" id="LAY-UPLOAD" value="$client->upload">
            <input type="hidden" id="LAY-SHARED-IMG" value="{$client->shared->img}">
            <input type="hidden" id="LAY-SHARED-ROOT" value="{$client->shared->root}">
            <input type="hidden" id="LAY-DOMAIN-IMG" value="$client->img">
            <input type="hidden" id="LAY-DOMAIN-ROOT" value="$client->root">
            <!--//END LAY CONSTANTS-->
            {$this->skeleton_body()}
        </body></html>
        STR;
        
        if($layConfig::$ENV_IS_PROD && $layConfig::is_page_compressed())
            $page = preg_replace("/>(\s)+</m","><",preg_replace("/<!--(.|\s)*?-->/","",$page));

        echo $page;
    }

    private function skeleton_head() : string
    {
        ob_start();

        $this->add_view_section(self::key_head);
        $this->dump_assets("css");

        return ob_get_clean();
    }

    private function skeleton_body() : string
    {
        ob_start();

        $this->add_view_section(self::key_body);
        $this->add_view_section(self::key_script);

        $this->dump_assets("js");

        if(self::$meta_data->{self::key_core}->close_connection)
            LayConfig::new()->close_sql();

        return ob_get_clean();
    }

    private function add_view_section(string $view_section) : void
    {
        $meta = self::$meta_data;
        $meta_view = $meta->{$view_section};

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

        if($asset_type == "js")
            $view = $this->core_script() . $view;

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

    private function core_script() : string {
        $meta = self::$meta_data;
        $layConfig = LayConfig::new();
        $js_template = fn ($src, $attr = []) => $this->script_tag_template($src, $attr);
        $core_script = "";

        if(!$meta->{self::key_core}->use_lay_script)
            return $core_script;

        $s = DIRECTORY_SEPARATOR;
        $domain = DomainResource::get();
        $lay_base = $domain->lay->uri;
        $lay_root = $domain->lay->root;

        list($omj,$const) = null;

        if ($layConfig::$ENV_IS_PROD) {
            if (file_exists($lay_root . $s . 'index.min.js'))
                $omj = $js_template($lay_base . 'index.min.js', ['defer' => false]);

            if (file_exists($lay_root . $s . "constants.min.js"))
                $const = $js_template($lay_base . 'constants.min.js', ['defer' => false]);
        }

        $core_script .= $omj ?? $js_template($lay_base . 'index.js',['defer' => false]);
        $core_script .= $const ?? $js_template($lay_base . 'constants.js', ['defer' => false]);

        return $core_script;
    }
}
