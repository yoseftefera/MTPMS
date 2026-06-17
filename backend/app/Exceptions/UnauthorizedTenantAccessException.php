<?php

namespace App\Exceptions;

use RuntimeException;

class UnauthorizedTenantAccessException extends RuntimeException
{
    public function __construct(string $message = 'Cross-tenant access is not permitted.')
    {
        parent::__construct($message);
    }
}
