<?php

namespace App\Exceptions;

use RuntimeException;

/** The cart costs something else than the customer expected at checkout (POST /orders `expected_subtotal_cents`): answered 409 with the fresh cart. */
class CartPricesChanged extends RuntimeException {}
