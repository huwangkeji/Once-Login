<?php
namespace UIC\SDK\Exception;

/**
 * API 业务异常
 */
class ApiException extends \Exception
{
    private $responseData;

    public function __construct(string $message, int $code = 0, $responseData = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->responseData = $responseData;
    }

    public function getResponseData()
    {
        return $this->responseData;
    }
}
