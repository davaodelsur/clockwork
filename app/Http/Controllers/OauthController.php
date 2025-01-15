<?php

namespace App\Http\Controllers;

use App\Models\Social;
use DutchCodingCompany\FilamentSocialite\Events\Login;
use DutchCodingCompany\FilamentSocialite\Events\RegistrationNotEnabled;
use DutchCodingCompany\FilamentSocialite\Events\SocialiteUserConnected;
use DutchCodingCompany\FilamentSocialite\Events\UserNotAllowed;
use DutchCodingCompany\FilamentSocialite\Exceptions\ProviderNotConfigured;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Http\Controllers\SocialiteLoginController;
use DutchCodingCompany\FilamentSocialite\Http\Middleware\PanelFromUrlQuery;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class OauthController extends SocialiteLoginController
{
    private ?FilamentSocialitePlugin $plugin = null;

    public function redirectToProvider(string $provider): mixed
    {
        if (! $this->plugin()->isProviderConfigured($provider)) {
            return $this->redirectToLogin('You are not authorized to access this page.');
        }

        /** @var \Laravel\Socialite\Two\AbstractProvider|\Laravel\Socialite\Two\FacebookProvider $driver */
        $driver = Socialite::driver($provider);

        $response = $driver
            ->with([
                ...$this->plugin()->getProvider($provider)->getWith(),
                'state' => $state = PanelFromUrlQuery::encrypt($this->plugin()->getPanel()->getId()),
            ])
            ->scopes($this->plugin()->getProvider($provider)->getScopes());

        if ($provider === 'facebook') {
            $response->reRequest();
        }

        $response = $response->redirect();

        session()->put('state', $state);

        session()->put('guard', request()->input('guard'));

        if (request()->filled('link') && (bool) request()->input('link')) {
            session()->flash('oauth-link', 1);

            if (request()->filled('url')) {
                session()->flash('oauth-url', request()->input('url'));
            }
        }

        return $response;
    }

    public function disconnectProvider(Request $request, string $provider): JsonResponse
    {
        $socials = Social::where('provider', $provider)
            ->where('provider_id', $request->id)
            ->get();

        if ($socials->isNotEmpty()) {
            $socials->each->delete();

            return response()->json(['status' => 'success']);
        }

        return response()->json(['status' => 'error'], 404);
    }

    public function processCallback(string $provider): Response
    {
        if (! $this->plugin()->isProviderConfigured($provider)) {
            throw ProviderNotConfigured::make($provider);
        }

        $oauthUser = $this->retrieveOauthUser($provider);

        if (! array_key_exists('email', $oauthUser->user)) {
            return $this->redirectToLogin('Grant email first access to continue.');
        }

        if (is_null($oauthUser)) {
            return $this->redirectToLogin('filament-socialite::auth.login-failed');
        }

        if (! $this->authorizeUser($oauthUser)) {
            UserNotAllowed::dispatch($oauthUser);

            return $this->redirectToLogin('filament-socialite::auth.user-not-allowed');
        }

        $socialiteUser = $this->retrieveSocialiteUser($provider, $oauthUser);

        if ($socialiteUser) {
            return $this->loginUser($provider, $socialiteUser, $oauthUser);
        }

        if (session()->get('oauth-link')) {
            return $this->linkUser($provider, $oauthUser);
        }

        $user = app()->call($this->plugin()->getResolveUserUsing(), [
            'provider' => $provider,
            'oauthUser' => $oauthUser,
            'plugin' => $this->plugin,
        ]);

        if (! $this->evaluate($this->plugin()->getRegistration(), ['provider' => $provider, 'oauthUser' => $oauthUser, 'user' => $user])) {
            RegistrationNotEnabled::dispatch($provider, $oauthUser, $user);

            return $this->redirectToLogin('filament-socialite::auth.registration-not-enabled');
        }

        return $user
            ? $this->registerSocialiteUser($provider, $oauthUser, $user)
            : $this->redirectToLogin('User not found.');
    }

    protected function registerSocialiteUser(string $provider, User $oauthUser, Authenticatable $user): Response
    {
        $socialiteUser = $this->plugin()->getSocialiteUserModel()::createForProvider($provider, $oauthUser, $user, $this->getModel());

        SocialiteUserConnected::dispatch($socialiteUser);

        return $this->loginUser($provider, $socialiteUser, $oauthUser);
    }

    protected function retrieveSocialiteUser(string $provider, User $oauthUser): ?FilamentSocialiteUser
    {
        return $this->plugin()->getSocialiteUserModel()::findForProvider($provider, $oauthUser, $this->getModel());
    }

    protected function linkUser(string $provider, User $oauthUser): RedirectResponse
    {
        $this->plugin()->getSocialiteUserModel()::createForProvider($provider, $oauthUser, Auth::guard(session()->get('guard'))->user(), $this->getModel());

        return redirect()->to(url(session()->get('oauth-url')));
    }

    protected function loginUser(string $provider, $socialiteUser, User $oauthUser): Response
    {
        Auth::guard(session()->get('guard'))->login($socialiteUser->getUser(), $this->plugin()->getRememberLogin());

        Login::dispatch($socialiteUser, $oauthUser);

        return app()->call($this->plugin()->getRedirectAfterLoginUsing(), ['provider' => $provider, 'socialiteUser' => $socialiteUser, 'plugin' => $this->plugin]);
    }

    protected function redirectToLogin(string $message): RedirectResponse
    {
        session()->flash('filament-socialite-login-error', __($message));

        return redirect()->route('filament.auth.auth.login');
    }

    protected function plugin(): FilamentSocialitePlugin
    {
        return $this->plugin ??= parent::plugin();
    }

    protected function getModel(): string
    {
        return match (session()->get('guard')) {
            'employee' => \App\Models\Employee::class,
            default => \App\Models\User::class,
        };
    }
}
