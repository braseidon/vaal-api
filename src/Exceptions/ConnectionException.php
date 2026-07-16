<?php

namespace Braseidon\VaalApi\Exceptions;

/**
 * Thrown when GGG never returned a response — connect/read timeout or
 * other transport-level failure. Distinct from ServerException, which
 * means GGG answered with a 5xx.
 */
class ConnectionException extends VaalApiException {}
