<?php

namespace App\Decorator;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class TimeoutAwareHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->client->request($method, $url, $options);
        // This is just another way to fix it (don't do anything with $response until this is done):
        $responseStream = $this->client->stream($response, $response->getInfo('timeout'));
        if ($responseStream->valid()) {
            $chunk = $responseStream->current();
            try {
                $chunk->getContent();
            } catch (TimeoutException $e) {
                //  This is needed to check if a connection was made.
                //  Greater than 0.0 it means that a connection was established.
                if ($response->getInfo('connect_time') === 0.0) {
                    $response->cancel();
                    throw $e;
                }
            }
        }

        return $response;
    }
}
