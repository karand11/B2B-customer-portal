<?php

namespace App\Support;

use InvalidArgumentException;

/** A boot-time refusal of an environment value (TRUSTED_PROXIES, TRUSTED_HOSTS). The message names the variable and never its value. */
class InvalidConfiguration extends InvalidArgumentException {}
