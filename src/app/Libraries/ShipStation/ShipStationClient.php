<?php

declare(strict_types=1);

namespace App\Libraries\ShipStation;

use App\Libraries\ShipStation\Exceptions\ShipStationApiException;
use Config\ShipStation as ShipStationConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class ShipStationClient
{
    private Client $http;

    public function __construct(private readonly ShipStationConfig $config)
    {
        $this->http = new Client([
            'base_uri' => rtrim($this->config->baseURL, '/') . '/',
            'timeout'  => $this->config->timeout,
            'headers'  => [
                'Accept'                       => 'application/json',
                $this->config->apiKeyHeader   => $this->config->apiKey,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function request(string $method, string $uri, array $options = []): array|null
    {
        if ($this->config->apiKey === '') {
            throw new ShipStationApiException(
                'ShipStation API key is not configured. Set shipstation.apiKey in your .env file.',
                500,
            );
        }

        try {
            $response = $this->http->request($method, $uri, $options);

            return $this->decodeResponse($response);
        } catch (RequestException $exception) {
            throw $this->buildException($exception);
        } catch (GuzzleException $exception) {
            throw new ShipStationApiException($exception->getMessage(), 0, null, $exception);
        }
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function get(string $uri, array $query = []): array|null
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * Follow a ShipStation API pagination link (links.next.href).
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getFromLink(string $href): array|null
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        return $this->request('GET', $href);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function post(string $uri, array $body = []): array|null
    {
        return $this->request('POST', $uri, ['json' => $body]);
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
            throw new ShipStationApiException(
                'ShipStation API returned invalid JSON.',
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    private function buildException(RequestException $exception): ShipStationApiException
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
                } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
                    $messages = [];

                    foreach ($decoded['errors'] as $error) {
                        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                            $messages[] = $error['message'];
                        }
                    }

                    if ($messages !== []) {
                        $message = implode(' ', $messages);
                    }
                }
            }
        }

        return new ShipStationApiException($message, $statusCode, $body, $exception);
    }
}
