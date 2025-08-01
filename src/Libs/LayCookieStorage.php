<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayCrypt\LayCrypt;
use BrickLayer\Lay\Libs\Primitives\Traits\TableTrait;

/**
 * Store session as cookie through accurate environment storage and encrypted storage token.
 * @example
 * \Lay\libs\LayCookieStorage::save_to_db("my-cookie"): void;
 * \Lay\libs\LayCookieStorage::check_db(): array;
 * \Lay\libs\LayCookieStorage::clear_from_db(): bool;
 */
final class LayCookieStorage
{
    use TableTrait;

    public static string $SESSION_KEY;
    protected static string $table = "lay_cookie_storages";

    private static string $session_user_cookie;

    protected static function init(string $table): void
    {
        $project_id = LayConfig::get_project_identity();

        self::$SESSION_KEY = "LAY_COOKIE__" . $project_id;

        if (!isset(self::$session_user_cookie))
            self::$session_user_cookie = "lay_jar__" . $project_id;

        $_SESSION[self::$SESSION_KEY]  = $_SESSION[self::$SESSION_KEY]  ?? [];
        self::$created_table_name = $table;

        self::create_table();
    }

    protected static function table_creation_query() : void
    {

        self::orm()->query("CREATE TABLE IF NOT EXISTS `" . self::$table . "` (
                `id` char(36) UNIQUE PRIMARY KEY,
                `created_by` char(36) NOT NULL,
                `created_at` datetime,
                `deleted` int(1) DEFAULT 0 NOT NULL,
                `deleted_by` char(36),
                `deleted_at` datetime,
                `env_info` text,
                `auth` text,
                `expire` datetime
            )
        ");
    }

    private static function delete_expired_tokens(): void
    {
        self::orm()->open(self::$table)
            ->where(self::orm()->days_diff(LayDate::date(), 'expire'),">", "30")
            ->delete();
    }

    private static function set_cookie(string $name, string $value, array $options = []): bool
    {
        $expires = $options['expires'] ?? "30 days";
        $path = $options['path'] ?? "/";
        $domain = $options['domain'] ?? null;
        $httponly = $options['httponly'] ?? false;
        $same_site = $options['samesite'] ?? "Lax";
        $secure = $options['secure'] ?? null;

        if (LayConfig::$ENV_IS_DEV)
            $secure = $secure ?? false;

        $name = str_replace(["=", ",", ";", " ", "\t", "\r", "\n", "\013", "\014"], "", $name);

        setcookie($name, $value, [
            "expires" => $expires == 0 ? (int)$expires : strtotime($expires),
            "path" => $path,
            "domain" => $domain ?? $_SERVER['HTTP_HOST'],
            "secure" => $secure ?? true,
            "httponly" => $httponly,
            "samesite" => $same_site
        ]);

        return isset($_COOKIE[$name]);
    }

    private static function save_user_token(string $user_id): ?string
    {
        $today = LayDate::date();
        $data = self::validate_cookie()['data'];

        if (empty($data))
            return self::store_user_token($user_id);

        self::orm()->open(self::$table)
            ->column(["expire" => $today])
            ->then_update("WHERE created_by='{$data['created_by']}' AND auth='{$data['auth']}'");

        return null;
    }

    /**
     * @return array{
     *     found: bool,
     *     data?: array {
     *       created_by: string,
     *       auth: string,
     *     },
     *     why?: string,
     * }
     */
    public static function validate_cookie(): array
    {
        self::init(self::$table);

        if (!isset($_COOKIE[self::$session_user_cookie]))
            return [
                "found" => false,
                "why" => "NOT_SET"
            ];

        if ($id = self::decrypt_cookie())
            return [
                "found" => true,
                "data" => self::get_user_token($id)
            ];

        return [
            "found" => false,
            "why" => "ERROR"
        ];
    }

    private static function decrypt_cookie(): ?string
    {
        self::init(self::$table);

        if (!isset($_COOKIE[self::$session_user_cookie]))
            return null;

        $cookie = $_COOKIE[self::$session_user_cookie] ?? null;

        if (!$cookie)
            return null;

        return LayCrypt::basic($cookie, false);
    }

    /**
     * @param string $id
     * @return array{
     *     created_by: string,
     *     auth: string,
     * }
     */
    private static function get_user_token(string $id): array
    {
        return self::orm()->open(self::$table)
            ->column("created_by, auth")
            ->where("id", self::clean($id))
            ->then_select();
    }

    private static function store_user_token(string $user_id): string
    {
        $orm = self::orm();
        $env_info = self::browser_info();
        $expire = LayDate::date("30 days");
        $now = LayDate::date();
        self::cleanse($user_id);

        self::delete_expired_tokens();

        $id = $orm->uuid();

        $orm->open(self::$table)->then_insert([
            "id" => $id,
            "created_by" => $user_id,
            "created_at" => $now,
            "auth" => LayCrypt::basic($user_id),
            "expire" => $expire,
            "env_info" => $env_info
        ]);

        return $id;
    }

    private static function destroy_cookie(string $name): void
    {
        self::set_cookie($name, "", ["expires" => "now",]);
    }


    /*
     * ### PUBLIC ###
     */

    public static function browser_info(): string
    {
        return LayConfig::get_os() . " " . LayConfig::get_header("User-Agent") . " IP: " . LayConfig::get_ip();
    }

    public static function clear_from_db(): void
    {
        self::init(self::$table);

        if ($id = self::decrypt_cookie()) {
            self::cleanse($id);
            (new self())->delete_record($id);
        }

        self::destroy_cookie(self::$session_user_cookie);
    }

    public static function save_to_db(string $immutable_value): bool
    {
        self::init(self::$table);

        self::delete_expired_tokens();

        if (isset($_COOKIE[self::$session_user_cookie]))
            return true;

        return self::set_cookie(
            self::$session_user_cookie,
            LayCrypt::basic(LayCookieStorage::save_user_token($immutable_value))
        );
    }

    public static function save(string $cookie_name, string $cookie_value, string $expire = "30 days", string $path = "/", ?string $domain = null, ?bool $secure = null, ?bool $http_only = null, ?string $same_site = null): bool
    {
        return self::set_cookie($cookie_name, $cookie_value,
            [
                "expires" => $expire,
                "path" => $path,
                "domain" => $domain,
                "secure" => $secure,
                "httponly" => $http_only,
                "samesite" => $same_site,
            ]
        );
    }

    public static function clear(string $cookie_name): void
    {
        self::destroy_cookie($cookie_name);
    }

    public static function get(string $cookie_name) : mixed
    {
        return $_COOKIE[$cookie_name] ?? null;
    }
}