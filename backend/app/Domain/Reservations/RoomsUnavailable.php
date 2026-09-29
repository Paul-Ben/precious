<?php

namespace App\Domain\Reservations;

use RuntimeException;

/** Internal signal: rooms could not be (re)allocated; the savepoint is rolled back. */
final class RoomsUnavailable extends RuntimeException {}
