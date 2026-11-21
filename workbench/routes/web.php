<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Facades\Pwa;

// Ukázková aplikace pro ruční a prohlížečové testy: vendor/bin/testbench serve
Route::get('/', fn () => view('demo', ['title' => 'Úvod']))->middleware('pwa.cache');
Route::get('/orders', fn () => view('demo', ['title' => 'Objednávky']))->middleware('pwa.cache');
Route::get('/private', fn () => view('demo', ['title' => 'Bez cache']));
Route::post('/items', fn (Request $request) => response()->json(['saved' => $request->input('name')]));

// Cache rule demo: the catalogue is served stale-while-revalidate, also offline.
Pwa::serviceWorker()->flush();
Pwa::serviceWorker()->cache('/api/catalog')->staleWhileRevalidate()->name('catalog')->maxEntries(10);
Route::get('/api/catalog', fn () => response()->json(['items' => ['A', 'B'], 'at' => microtime(true)]));
