<?php

namespace App\Services;

use RuntimeException;

/** The uploaded file is not a TSM SavedVariables file we can safely read. Message is user-safe. */
class TsmParseException extends RuntimeException {}
