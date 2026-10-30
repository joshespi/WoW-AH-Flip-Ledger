<?php

namespace App\Services\Blizzard;

use RuntimeException;

/** A Blizzard call failed. Messages never contain credentials or tokens. */
class BlizzardException extends RuntimeException {}
