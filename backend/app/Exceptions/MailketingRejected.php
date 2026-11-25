<?php

namespace App\Exceptions;

use RuntimeException;

/** No delivery was attempted, or the provider explicitly refused it. */
class MailketingRejected extends RuntimeException {}
