# Tests with Symfony HttpClient regarding timeout and duration

Considering you can configure the "timeout" (connection timeout) and "max_duration" (max request/response time) in "http_client",
one would presume that setting a "timeout" of 3s and a "max_duration" of 4s would mean the following:

1. If the connection is not established within 3 seconds, we should get:
`Symfony\Component\HttpClient\Exception\TimeoutException: Idle timeout reached for "host"`

    This works as expected, see example: https://localhost:8000/test/timeout

2. If the connection is established within 3 seconds, but the request takes longer than 4 seconds, we should get:
`Symfony\Component\HttpClient\Exception\TransportException: Operation timed out after 4001 milliseconds with 0 bytes received for "host"`

    This does not work as expected, instead we get a TimeoutException after 3 seconds.
    See example: https://localhost:8000/test/max-duration

3. If the connection is established within 3 seconds, and the request takes about 5 seconds with the "max_duration" is set to 10s, we should get: HTTP OK

    Instead we get TimeoutException after 3 seconds.
    See example: https://localhost:8000/test/slow-response

For a workaround see: https://localhost:8000/test/workaround
But that's not something one would want to do for each request. It could be handled by HttpClient by adding a check with
`($response->getInfo('connect_time') === 0.0)` in the `Symfony\Component\HttpClient\Response\CommonResponseTrait::initialize()`
method like so:

```php
    private static function initialize(self $response): void
    {
        if (null !== $response->getInfo('error')) {
            throw new TransportException($response->getInfo('error'));
        }

        try {
            if (($response->initializer)($response, -0.0)) {
                foreach (self::stream([$response], -0.0) as $chunk) {
                    if ($chunk->isFirst()) {
                        break;
                    }
                }
            }
        } catch (TimeoutException $e) {
            if ($response->getInfo('connect_time') === 0.0) {
                $response->info['error'] = $e->getMessage();
                $response->close();
                throw $e;
            }
        } catch (\Throwable $e) {
            // Persist timeouts thrown during initialization
            $response->info['error'] = $e->getMessage();
            $response->close();
            throw $e;
        }

        $response->initializer = null;
    }
```

I also created a decorator to do the same thing, which you can see on the "solution-decorator" branch of this project.
