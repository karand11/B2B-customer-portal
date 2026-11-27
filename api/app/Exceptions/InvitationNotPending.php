<?php

namespace App\Exceptions;

use RuntimeException;

/** The invitation cannot be accepted (the user is not pending any more, or the token was used). The one failure accept() turns into the generic 422. */
class InvitationNotPending extends RuntimeException {}
