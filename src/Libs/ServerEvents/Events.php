<?php

namespace BrickLayer\Lay\Libs\ServerEvents;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Fiber;

class Events
{
    public static bool $is_streaming = false;

    /**
     * @var array{
     *     event: string,
     *     fiber: Fiber,
     *     timeout: int,
     * }
     */
    private array $fiber_buffer = [];

    private bool $close_connection = false;

    /**
     * Send the event to the event loop
     * @return void
     */
    private function send() : void
    {
        $this->event_loop();
    }

    /**
     * @param string|null $event
     * @param Fiber $fiber
     */
    private function fiber_struct(?string $event, Fiber $fiber) : void
    {
        $out = [];

        if($event)
            $out['event'] = "event: " . $event . "\n";

        $out['fiber'] = $fiber;

        $out['timeout'] = $this->timeout > 0 ? LayDate::unix("$this->timeout seconds") : 0;

        $this->fiber_buffer = $out;
    }

    private function event_loop() : void
    {
        $this->set_headers();

        if(empty($this->fiber_buffer)) {
            $this->close();
            return;
        }

        $exec_safely = function (callable $exec) : array
        {
            try {
                $data = $exec();
            } catch (\Throwable $e) {
                LayException::log("", $e,log_title: "EventsErr");
                $this->error("An error occurred");
                $this->close();
                return ["__lay_out__" => "__SAFE_EXEC__ERR__"];
            }
            return $data ?? [];
        };

        $can_expire = $this->fiber_buffer['timeout'] != 0;

        $fiber = $this->fiber_buffer['fiber'];
        $data = $exec_safely(fn() => $fiber->start($this, $fiber));

        if($data == "__SAFE_EXEC__ERR__") return;

        while (!$fiber->isTerminated()) {
            if($can_expire && LayDate::expired($this->fiber_buffer['timeout'])) break;

            $lay_out = $data['__lay_out__'] ?? null;

            if( $lay_out == "__LAY_EVENTS_DONE__" or connection_aborted() or $this->close_connection ) break;

            if($this->fiber_buffer['event'])
                echo "event: {$this->fiber_buffer['event']}\n";

            echo "data: " . LayFn::json_encode($data) . "\n\n";

            flush();

            if($fiber->isSuspended()) {
                $data = $exec_safely(fn() => $fiber->resume());
                $lay_out = $data['__lay_out__'] ?? null;
                if($lay_out == "__SAFE_EXEC__ERR__") return;
            }
        }

        $this->close();
    }

    /**
     * Send a message event from the server to a client
     * @param null|string $event The event to send
     * @param callable(self, Fiber):self $handler The data to be sent should be echoed inside here using the `Events::fiber_out` method
     * @see fiber_out
     * @example `->event(fn (Events $event, Fiber $fiber) => $event::out("Values: Data"))->send();`
     */
    public function fiber_event(?string $event, callable $handler) : void
    {
        $this->fiber_struct($event, new Fiber(fn($me, $fiber)  => $handler($me, $fiber)));
        $this->send();
    }

    /**
     * Send a message event from the server to a client
     * @see event
     */
    public function fiber_message(callable $handler) : void
    {
        $this->fiber_event("message", $handler);
    }

    /**
     * @see fiber_event
     * @param array $data
     */
    public function fiber_out(array $data) : void
    {
        if(isset($this->fiber_buffer['fiber'])) {
            $this->fiber_buffer['fiber']::suspend($data);
            return;
        }

        Fiber::suspend($data);
    }

    public function fiber_timeout(int $timeout) : self
    {
        $this->timeout = $timeout;
        return $this;
    }


    /**
     * Echo an event and data for streaming
     * @param string|null $event
     * @param array $data
     * @return void
     */
    public function event(?string $event, array $data, ApiStatus|int $status = ApiStatus::OK) : void
    {
        $this->set_headers();

        if($status instanceof ApiStatus) $status->respond();
        else LayFn::http_response_code($status, true);

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
    public function stream(array $data) : void
    {
        $this->event("message", $data);
    }

    public function done(?array $data = null) : void
    {
        $this->event("complete", $data ?? ['status' => '[DONE]'], ApiStatus::NO_CONTENT);
    }

    public function error(
        string $message = "Could not complete request at the moment, please try again later",
        ApiStatus|int $status = ApiStatus::INTERNAL_SERVER_ERROR
    ) : void
    {
        $this->event("error", ['message' => $message], $status);
    }

    /**
     * Close the event connection
     * @return void
     */
    public function close() : void
    {
        self::$is_streaming = false;
        $this->close_connection = true;

        $this->event("error", ['message' => "Connection closed"], ApiStatus::NO_CONTENT);
    }

    /**
     * An alias for close
     * @return void
     */
    public function end() : void
    {
        $this->close();
    }

    public function set_headers() : void
    {
        self::$is_streaming = true;

        LayFn::header("X-Accel-Buffering: no");
        LayFn::header("Content-Type: text/event-stream");
        LayFn::header("Cache-Control: no-cache");
        LayFn::header("Connection: keep-alive");

        while (ob_get_level()) {
            ob_end_flush();
        }
    }

    public function __construct(
        protected int $timeout = 25, // When set to 0, it means no timeout
    ) { }
}