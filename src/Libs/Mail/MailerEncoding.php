<?php

namespace BrickLayer\Lay\Libs\Mail;

use PHPMailer\PHPMailer\PHPMailer;

enum MailerEncoding: string
{
    case ENCODING_7BIT = PHPMailer::ENCODING_7BIT;
    case ENCODING_8BIT = PHPMailer::ENCODING_8BIT;
    case ENCODING_BASE64 = PHPMailer::ENCODING_BASE64;
    case ENCODING_BINARY = PHPMailer::ENCODING_BINARY;
    case ENCODING_QUOTED_PRINTABLE = PHPMailer::ENCODING_QUOTED_PRINTABLE;
}
