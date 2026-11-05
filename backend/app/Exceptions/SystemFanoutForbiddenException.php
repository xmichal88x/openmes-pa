<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Raised when a caller without the `start system fanout` permission asks to
 * start a merged sheet's source step as a system fan-out.
 */
class SystemFanoutForbiddenException extends AuthorizationException {}
