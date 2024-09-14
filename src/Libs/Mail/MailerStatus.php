<?php

namespace BrickLayer\Lay\Libs\Mail;

enum MailerStatus
{
    case QUEUED;
    case SENDING;
    case RETRY;
    case SENT;
    case FAILED;
}
