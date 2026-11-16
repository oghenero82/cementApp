<?php

namespace App\Libraries\Cement;

final class Problem extends \RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 422, public array $fields = [])
    {
        parent::__construct($message);
    }
}
