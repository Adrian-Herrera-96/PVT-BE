<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group([
    'middleware' => 'api',
    'prefix' => 'v1',
], function () {
    Route::resource('auth', 'API\AuthController')->only('store');
    Route::resource('city', 'API\CityController')->only('index');
    Route::resource('eco_com_state', 'API\EcoComStateController')->only('index');
    Route::resource('affiliate.observation', 'API\AffiliateObservationController')->only('index');
    Route::resource('news', 'API\NewsController')->only('index', 'show');
});

Route::group([
    'middleware' => ['api', 'api_auth'],
    'prefix' => 'v1',
], function () {
    Route::resource('auth', 'API\AuthController')->only('index', 'destroy');
    Route::resource('economic_complement', 'API\EconomicComplementController')->only('index', 'store', 'show');
    Route::resource('liveness', 'API\LivenessController')->only('index', 'store', 'show');
    Route::resource('eco_com_procedure', 'API\EcoComProcedureController')->only('show');
});

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Route::group(['middleware' => ['asssspi']], function () {
    Route::get('documents/received/{rol_id}/{user_id}', 'API\DocumentController@received');
    Route::get('documents/edited/{rol_id}/{user_id}', 'API\DocumentController@edited');
// });/