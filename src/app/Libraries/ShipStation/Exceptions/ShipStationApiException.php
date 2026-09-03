<?php

declare(strict_types=1);

namespace App\Libraries\ShipStation\Exceptions;

use RuntimeException;
use Throwable;

class ShipStationApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
