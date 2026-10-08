# Changelog

## v1.5.0

- Add the `on_response` config closure: called once per HTTP response the client receives (`get()`, `post()`, every `getMany()` pool request), every status included, and once per attempt the retry middleware sends. It receives the request path, the status, the response headers and the attempt's transfer time in seconds. A request with no response does not call it, and neither do `public()` clients or token-refresh requests. Anything it throws is caught and logged as a warning when a `logger` is configured (exception class, message, request path). A value that is set but not a `Closure` throws `InvalidArgumentException` from the constructor. Unset, nothing changes.
