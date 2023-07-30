<?php
declare(strict_types=1);

use App\Fetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use TYPO3\CMS\Core\Http\Uri;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/fetch', static function (Request $request) {
    $url = new Uri($request->get('url'));
    return (new Fetcher($url, $request))->getContentSecurityPolicy() ?? '';
});
