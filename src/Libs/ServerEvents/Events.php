<?php

namespace BrickLayer\Lay\Libs\ServerEvents;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Core\View\Domain;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;

class Events
{
    public static bool $is_streaming = false;
    private bool $close_connection = false;
    protected ?string $event_id = null;

    private function cleanup() : void
    {
        self::$is_streaming = false;
        $this->close_connection = true;

        $this->delete_event_id();
    }

    private function gen_id() : string
    {
        $id = "/";

        if (Domain::is_in_use())
            $id = Domain::current_route_data("route");

        return LayConfig::get_ip() . "_+_" . $id;
    }

    private function cache_event_id() : void
    {
        if(isset($_SESSION['__LAY_SSE_IDS__']["__LAY_EXCEPTION__"])){
            LayException::log(
                "You have an exception preventing you from executing server sent events. 
                After fixing it, run `Events::clear_exception()` in the same session to allow SSE execution",
                log_title: "SSE_Error"
            );
            $this->event("error", ['message' => "You have an exception, fix it, clear exception and try again"], ApiStatus::INTERNAL_SERVER_ERROR);
            die;
        }

        $this->event_id ??= $this->gen_id();

        if($this->is_duplicate($this->event_id)) {
            $this->close_connection = true;

            $this->event("error", ['message' => "User has an existing live connection"], ApiStatus::NO_CONTENT);
            die;
        }

        $_SESSION['__LAY_SSE_IDS__'][$this->event_id] = "live";
    }

    private function delete_event_id() : void
    {
        if($this->event_id) unset($_SESSION['__LAY_SSE_IDS__'][$this->event_id]);
        session_write_close();

        flush();

        while (ob_get_level()) {
            ob_end_flush();
        }
    }

    public function event_loop(callable $callback, bool $close = true) : void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $this->set_headers();

        $abort_event_loop = LayConfig::server_data()->temp . "abort_event_loop";

        while (!connection_aborted() && self::$is_streaming) {
            if (
                connection_status() != CONNECTION_NORMAL ||
                $this->close_connection || file_exists($abort_event_loop)
            ) break;

            try{
                $out = $callback($this);

                if($out == LayLoop::BREAK) break;
            } catch (\Throwable $e) {
                LayException::log("", $e, "ServerEventsErr");
                $this->error();
                break;
            }

            flush();
        }

        if($close) $this->close();
    }

    /**
     * Echo an event and data for streaming
     * @param string|null $event
     * @param array $data
     * @param ApiStatus|int $status
     * @return void
     */
    public function event(?string $event, array $data, ApiStatus|int $status = ApiStatus::OK) : void
    {
        if ($status instanceof ApiStatus) $status->respond(log_error: false);
        else LayFn::http_response_code($status, true, log_sent: false);

        $this->set_headers();

        if($event)
            echo "event: $event\n";

        echo "data: " . LayFn::json_encode($data) . "\n\n";

        flush();
    }

    /**
     * @see event
     * @param array $data
     * @return void
     */
    public function message(array $data) : void
    {
        $this->event("message", $data);
    }

    public function complete(?array $data = null) : void
    {
        $this->event("complete", $data ?? ['status' => '[DONE]'], ApiStatus::NO_CONTENT);
        $this->cleanup();
    }

    public function error(
        string $message = "Could not complete request at the moment, please try again later",
        ApiStatus|int $status = ApiStatus::INTERNAL_SERVER_ERROR
    ) : void
    {
        $this->event("error", ['message' => $message], $status);
        $this->cleanup();
    }

    public function __exception() : void
    {
        $this->event("error", ['message' => "An internal server error occurred, please check the server logs for more info"], ApiStatus::INTERNAL_SERVER_ERROR);

        self::$is_streaming = false;
        $this->close_connection = true;

        $_SESSION['__LAY_SSE_IDS__']["__LAY_EXCEPTION__"] = true;
    }

    /**
     * Close the event connection
     * @return void
     */
    public function close() : void
    {
        $this->event("error", ['message' => "Connection closed"], ApiStatus::NO_CONTENT);
        $this->cleanup();
    }

    /**
     * An alias for close
     * @return void
     */
    public function end() : void
    {
        $this->close();
    }

    public function set_headers(bool $cache_id = true) : void
    {
        if(self::$is_streaming) return;

        self::$is_streaming = true;

        LayFn::header("X-Accel-Buffering: no");
        LayFn::header("Content-Type: text/event-stream");
        LayFn::header("Cache-Control: no-cache");
        LayFn::header("Connection: keep-alive");

        while (ob_get_level()) {
            ob_end_flush();
        }

        if($cache_id) $this->cache_event_id();
    }

    public function set_event_id(string $id) : void
    {
        $this->event_id = $id;
    }

    public function is_duplicate(?string $id = null) : bool
    {
        $id = $id ?? $this->event_id ?? $this->gen_id();

        return isset($_SESSION['__LAY_SSE_IDS__'][$id]);
    }

    public static function clear_exception() : void
    {
        unset($_SESSION['__LAY_SSE_IDS__']);
    }
}