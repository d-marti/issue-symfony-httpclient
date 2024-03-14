<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class TestController extends AbstractController
{
    #[Route(path: '/test/timeout', name: 'test_timeout', methods: ['GET'])]
    public function testTimeout(HttpClientInterface $client): Response
    {
        $clientClass = get_class($client);
        $ts = hrtime(true);

        try {
            $response = $client->request(
                'GET',
                'http://193.168.2.2', // should not be able to connect here
                ['timeout' => 3, 'max_duration' => 5] // the connection should timeout after 3s
            );

            echo $response->getContent(false);
        } catch (TimeoutException $e) {
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e); // correct exception
        }

        return self::getResponse(Response::HTTP_OK, $clientClass, $ts);
    }

    #[Route(path: '/test/max-duration', name: 'test_max_duration', methods: ['GET'])]
    public function testMaxDuration(HttpClientInterface $client): Response
    {
        $clientClass = get_class($client);
        $ts = hrtime(true);

        try {
            $response = $client->request(
                'GET',
                'http://localhost:8000/sleep.php', // sleep for 5s
                ['timeout' => 3, 'max_duration' => 4] // the request should timeout after 4s
            );

            echo $response->getContent(false);
        } catch (TimeoutException $e) {
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e); // without the fix we get a TimeoutException
        } catch (TransportException $e) {
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e); // correct exception would be this instead
        }

        return self::getResponse(Response::HTTP_OK, $clientClass, $ts);
    }

    #[Route(path: '/test/slow-response', name: 'test_finish', methods: ['GET'])]
    public function testSlowResponse(HttpClientInterface $client): Response
    {
        $clientClass = get_class($client);
        $ts = hrtime(true);

        try {
            $response = $client->request(
                'GET',
                'http://localhost:8000/sleep.php', // sleep for 5s, then give a response
                ['timeout' => 3, 'max_duration' => 10] // the request should finish after 5s
            );

            echo $response->getContent(false);
        } catch (Throwable $e) {
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e); // without the fix we get a TimeoutException
        }

        return self::getResponse(Response::HTTP_OK, $clientClass, $ts);
    }

    #[Route(path: '/test/workaround', name: 'test_workaround', methods: ['GET'])]
    public function testWorkaround(HttpClientInterface $client): Response
    {
        $clientClass = get_class($client);
        $ts = hrtime(true);

        try {
            $response = $client->request(
                'GET',
                'http://localhost:8000/sleep.php', // sleep for 5s, then give a response
                ['timeout' => 3, 'max_duration' => 10] // the request should finish after 5s
            );

            // this is just another way to fix it (don't do anything with $response until this is done):
            $responseStream = $client->stream($response, $response->getInfo('timeout'));
            if ($responseStream->valid()) {
                $chunk = $responseStream->current();
                try {
                    $chunk->getContent();
                } catch (TimeoutException $e) {
                    //  The below line is needed to check if a connection was made
                    //  Greater than 0.0 it means that a connection was established
                    if ($response->getInfo('connect_time') === 0.0) {
                        $response->cancel();
                        throw $e;
                    }
                }
            }

            // operations with response are "safe" here
            echo $response->getContent(false);
        } catch (TimeoutException $e) {
            // failed to connect within "timeout" seconds
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e);
        } catch (TransportException $e) {
            // failed finish geting the response within "max_duration" seconds, which can be greater than "timeout" seconds
            return self::getResponse(Response::HTTP_REQUEST_TIMEOUT, $clientClass, $ts, $e);
        }

        return self::getResponse(Response::HTTP_OK, $clientClass, $ts);
    }

    private static function getDuration(int $ts): int
    {
        return ((hrtime(true) - $ts) / 1e+6);
    }

    private static function getResponse(int $httpResponseCode, string $clientClass, int $ts, ?Throwable $e = null): Response
    {
        $message = 0;
        if (null !== $e) {
            $message = get_class($e) . ": " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString();
        }
        return new Response(
            nl2br(sprintf(
                "\nClient: %s\nDuration: %d ms\n%s",
                $clientClass,
                self::getDuration($ts),
                $message
            )),
            $httpResponseCode,
            [
                'Content-type: text/html'
            ]
        );
    }
}
