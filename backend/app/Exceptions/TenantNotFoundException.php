<?php

namespace App\Exceptions;

use RuntimeException;

class TenantNotFoundException extends RuntimeException
{
    public function __construct(string $identifier = '')
    {
        parent::__construct(
            $identifier
                ? "Tenant not found for identifier: {$identifier}"
                : 'Tenant not found.'
        );
    }
}
