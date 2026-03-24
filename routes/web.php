<?php
use App\Jobs\TestRedisJob;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-redis-job', function () {
    TestRedisJob::dispatch();

    return 'Redis job dispatched';
});