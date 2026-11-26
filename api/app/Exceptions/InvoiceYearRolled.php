<?php

namespace App\Exceptions;

use RuntimeException;

/** The clock crossed midnight on 31 December between choosing the counter year and reading the issue date. Nothing was consumed: retry. */
class InvoiceYearRolled extends RuntimeException {}
