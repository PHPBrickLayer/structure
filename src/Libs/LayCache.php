<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class LayCache
{
    use IsSingleton;

    private const default_path_to_cache = "cache";
    private string $cache_store;

    public function get_cache_path(): ?string
    {
        return $this->cache_store ?? null;
    }

    public function cache_exists(): bool
    {
        return isset($this->cache_store) && file_exists($this->cache_store);
    }

    public function store(string $key, mixed $value): bool
    {
        $cache = $this->read($key) ?? [];
        $cache[$key] = $value;

        $cache = json_encode($cache);

        if (!$cache)
            Exception::throw_exception("Could not store data in cache, please check your data", "MalformedCacheData");

        $cache = file_put_contents($this->cache_store, $cache);

        return !($cache === false);
    }

    public function update(array $key_chain, mixed $value): bool
    {
        try{
            $data = json_decode(file_get_contents($this->cache_store), true);
        } catch (\Exception $e){
            $this->cache_store ??= "";
            Exception::throw_exception("Cache storage [$this->cache_store] does not exist!", "CacheStoreNotFound", exception: $e);
        }

        $new_data = $data;
        $depth = &$new_data;

        foreach ($key_chain as $key) {
            $depth = &$depth[$key];
        }

        $depth = $value;

        $new_data = json_encode($new_data);

        if ($new_data === false)
            Exception::throw_exception("Could not store data in cache, please check your data", "MalformedCacheData");

        return (bool) file_put_contents($this->cache_store, $new_data);
    }

    public function read(string $key, bool $associative = true): mixed
    {
        if (!isset($this->cache_store))
            $this->cache_file(self::default_path_to_cache);

        if (!file_exists($this->cache_store))
            return null;

        $data = json_decode(file_get_contents($this->cache_store), $associative);

        if ($key === "*")
            return $data;

        if($associative)
            return $data[$key] ?? null;

        if(isset($data?->{$key}))
            return $data->{$key};

        return  null;
    }

    public function cache_file(string $path_to_cache = "./", bool $use_lay_temp_dir = true, bool $invalidate = false): self
    {
        $server = LayConfig::server_data();

        $this->cache_store = $use_lay_temp_dir ? LayConfig::mk_tmp_dir() : $server->root;
        $this->cache_store = $this->cache_store . $path_to_cache;

        if(str_contains($path_to_cache, "/")) {
            $path = str_replace(["/", DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $this->cache_store);

            $x = explode(DIRECTORY_SEPARATOR, $path);

            array_pop($x);

            $x = implode(DIRECTORY_SEPARATOR, $x);

            if(!is_dir($x)) {
                umask(0);
                mkdir($x, 0755, true);
            }
        }

        if(!file_exists($this->cache_store) || $invalidate)
            file_put_contents($this->cache_store, "");

        return $this;
    }

    /**
     * @param mixed $data json encodable datatype
     * @return bool
     * @throws \Exception
     */
    public function dump(mixed $data): bool
    {
        if (!isset($this->cache_store))
            $this->cache_file(self::default_path_to_cache);

        $data = json_encode($data);

        if (!$data)
            Exception::throw_exception("Could not store data in cache, please check your data", "MalformedCacheData");

        $data = file_put_contents($this->cache_store, $data);

        return !($data === false);
    }

    public function export() : array
    {
        if (!isset($this->cache_store))
            $this->cache_file(self::default_path_to_cache);

        return json_decode(file_get_contents($this->cache_store), true);
    }
}