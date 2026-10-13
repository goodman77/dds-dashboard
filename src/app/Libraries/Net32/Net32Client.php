<?php

declare(strict_types=1);

namespace App\Libraries\Net32;

use App\Libraries\Net32\Exceptions\Net32ApiException;
use CodeIgniter\CLI\CLI;
use Config\Net32 as Net32Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

class Net32Client
{
    private Client $http;

    private string $subscriptionKey;

    public function __construct(private readonly Net32Config $config, ?string $subscriptionKey = null)
    {
        $this->subscriptionKey = $subscriptionKey ?? $this->config->subscriptionKey;

        $this->http = new Client([
            'base_uri' => rtrim($this->config->baseURL, '/') . '/',
            'timeout'  => $this->config->timeout,
            'headers'  => [
                'Accept'                          => 'application/json',
                $this->config->subscriptionHeader => $this->subscriptionKey,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options (json, query, body, etc.)
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        if ($this->subscriptionKey === '') {
            throw new Net32ApiException(
                'Net32 subscription key is not configured. Set net32.subscriptionKey in your .env file.',
                500,
            );
        }

        $maxRetries = max(0, $this->config->rateLimitMaxRetries);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->http->request($method, ltrim($uri, '/'), $options);

                return $this->decodeResponse($response);
            } catch (RequestException $exception) {
                $apiException = $this->buildException($exception);

                if ($apiException->getStatusCode() === 429 && $attempt < $maxRetries) {
                    $waitSeconds = $this->resolveRateLimitWaitSeconds($apiException);
                    log_message(
                        'warning',
                        'Net32 rate limit for {method} {uri}. Retrying in {seconds}s ({attempt}/{max}).',
                        [
                            'method'  => strtoupper($method),
                            'uri'     => $uri,
                            'seconds' => $waitSeconds,
                            'attempt' => $attempt + 1,
                            'max'     => $maxRetries,
                        ],
                    );

                    if (is_cli()) {
                        CLI::write(sprintf(
                            'Rate limited on %s. Retrying in %.1fs (%d/%d)...',
                            $uri,
                            $waitSeconds,
                            $attempt + 1,
                            $maxRetries,
                        ), 'yellow');
                    }

                    $this->sleepSeconds($waitSeconds);

                    continue;
                }

                throw $apiException;
            } catch (GuzzleException $exception) {
                throw new Net32ApiException($exception->getMessage(), 0, null, $exception);
            }
        }

        throw new Net32ApiException('Net32 rate limit retries exhausted.', 429);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function get(string $uri, array $query = []): array|null
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
    {
        if ($this->subscriptionKey === '') {
            return Create::rejectionFor(new Net32ApiException(
                'Net32 subscription key is not configured. Set net32.subscriptionKey in your .env file.',
                500,
            ));
        }

        return $this->http->requestAsync($method, ltrim($uri, '/'), $options)->then(
            fn (ResponseInterface $response): array|null => $this->decodeResponse($response),
            function ($exception) use ($method, $uri, $options) {
                if ($exception instanceof RequestException) {
                    $apiException = $this->buildException($exception);

                    if ($apiException->getStatusCode() === 429) {
                        return $this->request($method, $uri, $options);
                    }

                    throw $apiException;
                }

                if ($exception instanceof GuzzleException) {
                    throw new Net32ApiException($exception->getMessage(), 0, null, $exception);
                }

                throw $exception instanceof \Throwable
                    ? $exception
                    : new Net32ApiException((string) $exception);
            },
        );
    }

    /**
     * @return PromiseInterface
     */
    public function getAsync(string $uri, array $query = []): PromiseInterface
    {
        return $this->requestAsync('GET', $uri, ['query' => $query]);
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function post(string $uri, array $body = []): array|null
    {
        return $this->request('POST', $uri, ['json' => $body]);
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function put(string $uri, array $body = []): array|null
    {
        return $this->request('PUT', $uri, ['json' => $body]);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function delete(string $uri): array|null
    {
        return $this->request('DELETE', $uri);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeResponse(ResponseInterface $response): array|null
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Net32ApiException(
                'Net32 API returned invalid JSON.',
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    private function buildException(RequestException $exception): Net32ApiException
    {
        $response   = $exception->getResponse();
        $statusCode = $response?->getStatusCode() ?? 0;
        $body       = null;
        $message    = $exception->getMessage();

        if ($response !== null) {
            $decoded = json_decode((string) $response->getBody(), true);

            if (is_array($decoded)) {
                $body = $decoded;

                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    $message = $decoded['message'];
                }
            }
        }

        return new Net32ApiException($message, $statusCode, $body, $exception);
    }

    private function resolveRateLimitWaitSeconds(Net32ApiException $exception): float
    {
        $configured = max(0.0, $this->config->rateLimitRetryDelaySeconds);

        if (preg_match('/try again in (\d+(?:\.\d+)?)\s*seconds?/i', $exception->getMessage(), $matches)) {
            return max($configured, (float) $matches[1] + 0.5);
        }

        return $configured;
    }

    private function sleepSeconds(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
