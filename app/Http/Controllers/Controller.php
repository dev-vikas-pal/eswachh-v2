<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     * Gives every controller $this->authorize(). Abilities resolve through the
     * UserRole enum, so authorising an action is a one-line statement rather
     * than a hand-rolled role check.
     */
    use AuthorizesRequests;
}
