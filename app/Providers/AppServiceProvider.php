<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\HttpSecurityScheme;
use Illuminate\Support\ServiceProvider;
use Metered\Shared\Presentation\Http\ProblemDocumentation;

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
        // The OpenAPI generator is a development dependency and absent from
        // the production image, so this only runs where it is installed.
        // Every route in the document takes a bearer API key, and the schema
        // should say so rather than leave a reader to find out from a 401.
        if (class_exists(Scramble::class)) {
            Scramble::configure()->withDocumentTransformers([
                static function (OpenApi $openApi): void {
                    $openApi->secure(new HttpSecurityScheme('bearer'));
                },
                new ProblemDocumentation(),
            ]);
        }
    }
}
