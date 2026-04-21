<?php

use App\Jobs\TestRedisJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-redis-job', function () {
    abort_unless(app()->isLocal(), 404);

    TestRedisJob::dispatch();

    return 'Redis job dispatched';
});

Route::get('/{path?}', function (Request $request, ?string $path = null) {
    $frontend = rtrim((string) config('app.frontend_url'), '/');
    $target = $frontend;

    if ($path) {
        $target .= '/'.ltrim($path, '/');
    }

    $query = $request->getQueryString();
    if ($query) {
        $target .= '?'.$query;
    }

    return redirect()->away($target);
})->where('path', '^(?!$|test-redis-job$).+');
