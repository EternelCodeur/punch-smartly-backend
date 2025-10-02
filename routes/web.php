<?php

use Illuminate\Support\Facades\Route;

// routes/web.php
Route::get('/{any}', function () {
    return file_get_contents(public_path('build/index.html'));
})->where('any', '^(?!api).*$');
