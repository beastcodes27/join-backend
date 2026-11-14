<?php

namespace App\Contracts;

interface IdentityVerifier
{
    public function verify(string $token): array;
}
