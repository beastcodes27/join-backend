<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn() => response()->json(['service'=>'Join Meeting API','company'=>'DevTZ']));
