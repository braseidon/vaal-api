<?php

namespace Braseidon\VaalApi\Exceptions;

/**
 * Thrown for authentication and authorization failures.
 *
 * Covers expired tokens, invalid credentials, insufficient scopes,
 * and 401/403 responses from the API.
 */
class AuthenticationException extends VaalApiException {}
