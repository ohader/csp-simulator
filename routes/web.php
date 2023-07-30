<?php
declare(strict_types=1);

use App\Fetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\StringUtility;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', static function () {
    return view('index');
});
Route::get('/empty', static function () {
    return view('empty');
});

$proxyHostName = sprintf(
    'proxy.%s.%s',
    getenv('DDEV_SITENAME'),
    getenv('DDEV_TLD')
);
Route::post('/', static function (Request $request) {
    $url = new Uri($request->get('url'));
    $csp = $request->get('csp') ?? '';
    return (new Fetcher($url, $request))->getAppliedResponse($csp);
})->domain($proxyHostName)->name('apply');

Route::get('/{value}/tail{tail?}', static function (Request $request, string $value, string $tail = '') {
    $decodedUrl = StringUtility::base64urlDecode($value);
    // `$tail` might be `.js` added by RequireJS or similar dynamic handlers
    $url = new Uri($decodedUrl . $tail);
    $fetcher = new Fetcher($url, $request);
    return $fetcher->getProxiedResponse()
        ?? response('', 418, [
            'x-fetched-uri' => $decodedUrl,
            'x-failure-message' => preg_replace('#\v#', ' ', $fetcher->errorMessage),
        ]);
})->domain($proxyHostName)->name('proxy');

// special handling, since Laravel fails to handle a trailing slash
Route::get('/{value}/{tail}', static function (Request $request, string $value, string $tail) {
    $decodedUrl = StringUtility::base64urlDecode($value);
    // original (encoded) URI: '/base64-encoded/tail`
    // some handler removed `/tail` (like `dirname`) and appended their stuff
    // which results in `/base64-encoded/their-stuff.js`
    $url = new Uri(dirname($decodedUrl) . '/' . $tail);
    $fetcher = new Fetcher($url, $request);
    return $fetcher->getProxiedResponse()
        ?? response('', 418, [
            'x-fetched-uri' => $decodedUrl,
            'x-failure-message' => preg_replace('#\v#', ' ', $fetcher->errorMessage),
        ]);
})->domain($proxyHostName);
