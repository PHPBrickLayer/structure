#!/usr/bin/env php
<?php

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Mail\Mailer;
use BrickLayer\Lay\Libs\Mail\MailerQueueHandler;
use BrickLayer\Lay\Libs\Mail\MailerStatus;

const SAFE_TO_INIT_LAY = true;

$s = DIRECTORY_SEPARATOR;

include_once __DIR__ . "{$s}..{$s}..{$s}..{$s}..{$s}..{$s}foundation.php"; // Project scope foundation

Mailer::write_to_log("[x] -- Starting a New Mailer Queue Session");

Mailer::write_to_log("[x] -- Attempting to connect to DB");

// Connect to the DB
LayConfig::connect();

Mailer::write_to_log("[x] -- Connected successfully to DB");

$mailer = new MailerQueueHandler();
$max_retries = LayFn::env('SMTP_MAX_QUEUE_RETRIES',3);
$max_queue_items = LayFn::env('SMTP_MAX_QUEUE_ITEMS', 5);
$max_queue_items = max($max_queue_items, 1);

$send_on_dev = LayFn::extract_cli_tag("--send-on-dev", false) ?? false;

// There should always be an Email class inside the utils/ directory.
// This class is where the email handler will get the template of the mails
// and any other required thing needed to send emails
$SENDER_CLASS_CHILD = LayFn::env("LAY_MAILER_CHILD", "\Utils\Email\Email");

Mailer::write_to_log("[x] -- Trying to determine location of Sender's Child, tried: $SENDER_CLASS_CHILD");

if(class_exists($SENDER_CLASS_CHILD)){
    Mailer::write_to_log("[x] -- Sender located at: $SENDER_CLASS_CHILD; Initializing via ReflectionClass");

    $sender = new \ReflectionClass($SENDER_CLASS_CHILD);

    try {
        $sender = $sender->newInstance();
    } catch (\Throwable $e) {
        LayException::throw("", "$SENDER_CLASS_CHILD::MailerError", $e);
    }

} else
    $sender = new Mailer();

Mailer::write_to_log("[x] -- Sender initialized using: " . $sender::class . "; Checking if mails are currently being sent");


$total = $mailer->is_still_sending();

if($total >= $max_queue_items) {
    Mailer::write_to_log("[x] -- [$total] mails are currently being sent, so exiting;");
    return;
}

if($send_on_dev) {
    Mailer::write_to_log("[x] -- Send on Dev is active, so sending even in development environment;");
    $sender->send_on_dev_env();
}

Mailer::write_to_log("[x] -- Attempting to send mails on Queue");

foreach ($mailer->next_items() as $mail) {
    $skip_to_send = false;

    // If it is retrying, update status to SENDING and update total retries
    if($mail['status'] == MailerStatus::RETRY->name) {
        $skip_to_send = true;

        if($mail['retries'] > $max_retries) {
            $mailer->failed_to_send($mail['id']);
            continue;
        }

        $mailer->want_to_retry($mail['id'], $mail['retries']);
    }

    // If it could not change the status to SENDING, meaning there is an error;
    // Change status to RETRY or FAILED depending on the max retries
    if(!$skip_to_send && !$mailer->want_to_send($mail['id'])) {
        $skip_to_send = true;

        if($mail['retries'] > $max_retries) {
            $mailer->failed_to_send($mail['id']);
            continue;
        }
        else
            $mailer->try_again($mail['id']);
    }

    $actors = json_decode($mail['actors'], true);
    $attachment = json_decode($mail['attachment'], true);

    if(@$actors['send_on_dev'] == "TRUE")
        $sender->send_on_dev_env();

    $sender = $sender
        ->subject($mail['subject'])
        ->body($mail['body'], true)
        ->client($actors['client']['email'] ?? "", $actors['client']['name'] ?? "")
        ->server($actors['server']['email'] ?? "", $actors['server']['name'] ?? "")
        ->server_from($actors['server_from']['email'] ?? "", $actors['server_from']['name'] ?? "")
        ->cc(...json_decode($mail['cc'], true))
        ->bcc(...json_decode($mail['bcc'], true))
        ->attachment(
            $attachment["filename"] ?? "",
            $attachment["data"] ?? null,
            $attachment["type"] ?? "",
            $attachment["encoding"] ?? null,
            $attachment["disposition"] ?? null,
            $attachment["as_string"] ?? null,
        );

    $action = false;

    try {
        $action = $actors['send_to'] == "TO_CLIENT" ? $sender->to_client(false) : $sender->to_server(false);

        if ($action)
            $mailer->email_sent($mail['id']);
        else
            $mailer->try_again($mail['id']);

    } catch (\Throwable $e) {
        $mailer->failed_to_send($mail['id']);
        Mailer::write_to_log("[x] -- Failed to send mail [{$mail['id']}] due to an exception. Check exception log for details");
        LayException::log("", $e, "Mailer::Error");
    }
}

Mailer::write_to_log("[x] -- Finished going through queue");

$mailer->stop_on_finish();

$mailer->delete_stale_mails(LayConfig::site_data()->delete_sent_mails);
