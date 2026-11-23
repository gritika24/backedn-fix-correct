<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->registerSearchMacro();

        // During development, log (rather than crash on) accidental N+1 queries
        // so they can be found and fixed with eager loading.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::warning('Lazy loading detected: '.$model::class.'::'.$relation);
        });
    }

    /**
     * ->search(['name', 'email'], $term): case-insensitive "contains" search.
     * %, _ and ! typed by the user are matched literally instead of acting
     * as SQL wildcards. Column names always come from our code, never input.
     */
    private function registerSearchMacro(): void
    {
        Builder::macro('search', function (array $columns, ?string $term) {
            /** @var Builder $this */
            $term = trim((string) $term);

            if ($term === '') {
                return $this;
            }

            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

            return $this->where(function (Builder $query) use ($columns, $pattern) {
                foreach ($columns as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        });
    }

    private function configureRateLimiting(): void
    {
        // Every API request: 120 per minute per user (or per IP when logged out).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login and registration: blocks password guessing.
        RateLimiter::for('auth', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Sensitive account actions such as changing a password.
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
