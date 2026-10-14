<?php

use App\Http\Controllers\Webhooks\StripeController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/stripe', [StripeController::class, 'store'])->middleware('throttle:120,1,stripe-webhook:');
