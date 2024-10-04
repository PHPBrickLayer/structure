#!/usr/bin/env php
<?php

use BrickLayer\Lay\Libs\Cron\LayCron;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Mail\Mailer;
use BrickLayer\Lay\Libs\Mail\MailerQueueHandler;
use BrickLayer\Lay\Libs\Mail\MailerStatus;

const SAFE_TO_INIT_LAY = true;

include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "foundation.php";

$mailer = new MailerQueueHandler();
$max_retries = $_ENV['SMTP_MAX_QUEUE_RETRIES'] ?? 3;
$send_on_dev = false;

// Check if sending, and return if found

if($mailer->is_still_sending())
    return;

$send_on_dev = \BrickLayer\Lay\Libs\LayFn::extract_cli_tag("--send-on-dev", false);

$sender = class_exists(\Utils\Email\Email::class) ?
    \Utils\Email\Email::new() : new Mailer();

if($send_on_dev)
    $sender->send_on_dev_env();

foreach ($mailer->next_items() as $mail) {
    // There should always be an Email class inside the utils/ directory.
    // This class is where the email handler will get the template of the mails
    // and any other required thing needed to send emails
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

    $sender = $sender
        ->subject($mail['subject'])
        ->body($mail['body'], true)
        ->client($actors['client']['email'], $actors['client']['name'])
        ->server($actors['server']['email'], $actors['server']['name'])
        ->server_from($actors['server_from']['email'], $actors['server_from']['name'])
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
        $action = $actors['send_to'] == "TO_CLIENT" ?
            $sender->to_client(false) :
            $sender->to_server(false);

        if ($action)
            $mailer->email_sent($mail['id']);
        else
            $mailer->try_again($mail['id']);

    } catch (\Exception|\Error $e) {
        $mailer->failed_to_send($mail['id']);
        LayCron::new()->log_output(
            "[" . LayDate::date() . "]\n" .
            \BrickLayer\Lay\Core\Exception::text($e, false)
        );
    }
}

$mailer->stop_on_finish();
