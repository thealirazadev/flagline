<?php

use App\Http\Controllers\Api\RulesetController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/ruleset', RulesetController::class)->middleware(['sdk.key', 'throttle:sdk']);
