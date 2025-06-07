<?php

namespace BrickLayer\Lay\Libs\ServerEvents;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Fiber;

class Events
{
    /**
     * @var array{
     *     event: string,
     *     fiber: Fiber,
     *     timeout: int,
     * }
     */
    private array $fiber_buffer = [];

    /**
     * @var array{
     *     event: string,
     *     data: string,
     * }
     */
    private array $exit_buffer = [];

    private bool $close_connection = false;

    private function default_headers() : void
    {
        LayFn::header("X-Accel-Buffering: no");
        LayFn::header("Content-Type: text/event-stream");
        LayFn::header("Cache-Control: no-cache");
        @ob_end_flush();
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

    private function struct_n_exit(string $event, string $message, ApiStatus $status, bool $exit = true) : void
    {
        $this->close_connection = true;
        $status->respond();

        $this->exit_buffer = [
            "event" => "event: " . $event . "\n",
            "data" => "data: " . $message . "\n\n",
        ];

        if($exit) $this->exit();
    }

    private function event_loop() : void
    {
        $this->default_headers();

        if(empty($this->fiber_buffer)) {
            $this->exit();
            return;
        }

        $exec_safely = function (callable $exec) {
            try {
                $data = $exec();
            } catch (\Throwable $e) {
                LayException::log("", $e,log_title: "EventsErr");
                $this->error("An error occurred");
                $this->close();
                return "__SAFE_EXEC__ERR__";
            }
            return $data;
        };

        $can_expire = $this->fiber_buffer['timeout'] != 0;

        $fiber = $this->fiber_buffer['fiber'];
        $data = $exec_safely(fn() => $fiber->start($this, $fiber));

        if($data == "__SAFE_EXEC__ERR__") return;

        while (!$fiber->isTerminated()) {
            if( $data == "__LAY_EVENTS_DONE__" or connection_aborted() or $this->close_connection ) break;

            if($this->fiber_buffer['event'])
                echo "event: {$this->fiber_buffer['event']}\n";

            $data = json_encode(["content" => $data]);
            echo "data: $data\n\n";

            flush();

            if($can_expire && LayDate::expired($this->fiber_buffer['timeout'])) break;

            if($fiber->isSuspended()) {
                $data = $exec_safely(fn() => $fiber->resume());
                if($data == "__SAFE_EXEC__ERR__") return;
            }
        }

        $this->exit();
    }

    private function exit() : void
    {
        if(@empty($this->exit_buffer))
            $this->struct_n_exit("close", "Connection closed", ApiStatus::NO_CONTENT, false);

        echo $this->exit_buffer['event'];
        echo $this->exit_buffer['data'];
    }

    public function timeout(int $timeout) : self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Send a message event from the server to a client
     * @param null|string $event The event to send
     * @param callable(self, Fiber):self $handler The data to be sent should be echoed inside here using the `Events::out` method
     * @see out
     * @example `->event(fn (Events $event, Fiber $fiber) => $event::out("Values: Data"))->send();`
     * @return $this
     */
    public function event(?string $event, callable $handler) : self
    {
        $this->fiber_struct($event, new Fiber(fn($me, $fiber)  => $handler($me, $fiber)));
        return $this;
    }

    /**
     * Send a message event from the server to a client
     * @see event
     * @return $this
     */
    public function message(callable $handler) : self
    {
        return $this->event("message", $handler);
    }

    /**
     * Output a stream of data from a handler
     * @param string $data
     */
    public function out(string $data) : void
    {
        if(isset($this->fiber_buffer['fiber'])) {
            $this->fiber_buffer['fiber']::suspend($data);
            return;
        }

        Fiber::suspend($data);
    }

    public function done() : void
    {
        $this->close_connection = true;
        $this->out("__LAY_EVENTS_DONE__");
    }

    public function error(
        string $message = "Could not complete request at the moment, please try again later",
        ApiStatus $status = ApiStatus::INTERNAL_SERVER_ERROR
    ) : void
    {
        $this->struct_n_exit("error", $message, $status);
    }

    /**
     * Close the event connection
     * @return void
     */
    public function close() : void
    {
        $this->struct_n_exit("close", "Connection closed", ApiStatus::NO_CONTENT);
    }

    /**
     * An alias for close
     * @return void
     */
    public function end() : void
    {
        $this->close();
    }
    /**
     * Send the event to the event loop
     * @return void
     */
    public function send() : void
    {
        $this->event_loop();
    }

    public function __construct(
        protected int $timeout = 30, // When set to 0, it means no timeout
    ) { }
}