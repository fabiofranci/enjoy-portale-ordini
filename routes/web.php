<?php

use App\Http\Controllers\OrderDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ordini/{ordine}/documenti/{format}', OrderDocumentController::class)
    ->where('format', 'pdf|xlsx')
    ->name('orders.documents.download');
