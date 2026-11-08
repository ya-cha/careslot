<?php

namespace App\Providers;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use TallStackUi\Facades\TallStackUi;

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
        $this->configureDefaults();
        DevCommands::artisan('schedule:work', 'scheduler');
        Gate::define('manage-groups', fn (User $user): bool => $user->isSystemAdministrator());
        Gate::define('impersonate', fn (User $user): bool => $user->isSystemAdministrator() && ! session()->has('impersonator_id'));
        Carbon::setLocale('de');
        CarbonImmutable::setLocale('de');

        TallStackUi::customize()->layout()
            ->block('main', 'mx-auto w-full min-w-0 p-4 sm:p-6 lg:p-10');
        TallStackUi::customize()->sideBar('item')
            ->block('item.state.normal', 'text-gray-600 hover:bg-gray-100 dark:text-dark-300 dark:hover:bg-dark-700')
            ->block('item.icon', 'size-5 shrink-0 transition-all');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
