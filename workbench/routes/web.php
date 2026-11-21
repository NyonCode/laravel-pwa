<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Ukázková aplikace pro ruční a prohlížečové testy: vendor/bin/testbench serve
Route::get('/', fn () => view('demo', ['title' => 'Úvod']))->middleware('pwa.cache');
Route::get('/orders', fn () => view('demo', ['title' => 'Objednávky']))->middleware('pwa.cache');
Route::get('/private', fn () => view('demo', ['title' => 'Bez cache']));
Route::post('/items', fn (Request $request) => response()->json(['saved' => $request->input('name')]));
