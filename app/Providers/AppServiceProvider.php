<?php

namespace App\Providers;

use App\Models\Student;
use App\Models\StudentMutation;
use App\Observers\StudentMutationObserver;
use App\Observers\StudentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Student::observe(StudentObserver::class);
        StudentMutation::observe(StudentMutationObserver::class);
    }
}
