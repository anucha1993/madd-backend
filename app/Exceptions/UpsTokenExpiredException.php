<?php

namespace App\Exceptions;

/**
 * Thrown when a UPS API call fails specifically with HTTP 401 while using a token we had
 * cached — distinguishes "our cached token died early" from every other UPS API error, so
 * callers can retry ONCE with a force-refreshed token instead of failing the whole request.
 */
class UpsTokenExpiredException extends \RuntimeException
{
}
