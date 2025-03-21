<?php

namespace App\Providers;

use App\Events\TimelogsSynchronized;
use App\Listeners\ActivityMonitor;
use App\Listeners\PostTimelogsSynchronization;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Facebook\FacebookExtendSocialite;
use SocialiteProviders\Google\GoogleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        TimelogsSynchronized::class => [
            PostTimelogsSynchronization::class,
            ActivityMonitor::class,
        ],
        SocialiteWasCalled::class => [
            GoogleExtendSocialite::class.'@handle',
            MicrosoftExtendSocialite::class.'@handle',
            FacebookExtendSocialite::class.'@handle',
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
