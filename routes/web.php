<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/



Route::group(['middleware' => 'web'], function () {

    Auth::routes(['verify' => true]);

    Route::get('/', function () {
        return view('welcome');
    });

    Route::group(['middleware' => 'auth'], function () {


        Route::group(['middleware' => 'verified'], function () {

            // route for processing payment
            Route::post('transaction', 'TransactionController@payWithpaypal');

            Route::group(['middleware' => 'onlynot.active'], function () {
                //payment form
                Route::get('activer', 'TransactionController@index')->name('activer');
                // route for check status of the payment
                Route::get('statusactivation', 'TransactionController@getPaymentStatusActivation')->name('statusactivation');
            });

            Route::group(['middleware' => 'valid.membership'], function () {

                Route::get('home', 'HomeController@index')->name('home');
                Route::get('category', 'CategoryController@index')->name('index category');
                

            });

        });



    });
});
