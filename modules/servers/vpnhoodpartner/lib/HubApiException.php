<?php

namespace WHMCS\Module\Server\VpnHoodPartner;

use Exception;

/**
 * A failed Hub call. hubAnswered() tells a Hub rejection (its own JSON envelope, with an
 * optional machine-readable code and details) from a call whose outcome is unknown: a
 * timeout, a connection error, or a proxy's error page in front of the Hub.
 */
class HubApiException extends Exception
{
    private int $httpStatus;
    private string $errorCode;
    private array $details;
    private bool $answered;

    public function __construct(string $message, int $httpStatus, bool $answered, string $errorCode = '', array $details = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->answered = $answered;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    /** 0 when no HTTP response arrived at all. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function hubAnswered(): bool
    {
        return $this->answered;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
