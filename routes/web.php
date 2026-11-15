<?php

declare(strict_types=1);

use App\Livewire\Catalogue\CategoryListing;
use App\Livewire\Catalogue\ProductPage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/c/{slug}', CategoryListing::class)->name('category');
Route::get('/search', CategoryListing::class)->name('search');
Route::get('/p/{slug}', ProductPage::class)->name('product');
