<?php

namespace App\Domain\Payments\Gateways;

use RuntimeException;

/** The gateway could not be reached or refused the request. */
class GatewayException extends RuntimeException {}
