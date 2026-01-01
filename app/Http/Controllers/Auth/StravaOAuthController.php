<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Services\StravaOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Strava OAuth Controller
 *
 * Handles the OAuth2 flow for Strava integration:
 * 1. Redirects user to Strava authorization
 * 2. Handles callback with authorization code
 * 3. Exchanges code for tokens and stores them
 */
class StravaOAuthController extends Controller
{
    public function __construct(
        private StravaOAuthService $oauthService
    ) {}

    /**
     * Redirect user to Strava authorization page
     */
    public function redirect(): RedirectResponse
    {
        $authUrl = $this->oauthService->getAuthorizationUrl();
        
        return redirect($authUrl);
    }

    /**
     * Handle Strava OAuth callback
     */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $error = $request->query('error');

        if ($error) {
            Log::error('Strava OAuth error', [
                'error' => $error,
                'error_description' => $request->query('error_description'),
            ]);
            
            return redirect('/')->with('error', 'Strava authorization failed: ' . $error);
        }

        if (!$code) {
            Log::warning('Strava OAuth callback missing code');
            return redirect('/')->with('error', 'Authorization code not provided');
        }

        try {
            // Exchange code for tokens
            $tokenData = $this->oauthService->exchangeCodeForToken($code);

            // Get or create the Strava data source
            $dataSource = DataSource::firstOrCreate(
                ['name' => 'strava'],
                ['is_active' => false, 'config' => []]
            );

            // Store tokens
            $this->oauthService->storeTokens($dataSource, $tokenData);

            return redirect('/')->with('success', 'Strava integration connected successfully!');
        } catch (\Exception $e) {
            Log::error('Strava OAuth callback error', [
                'error' => $e->getMessage(),
            ]);

            return redirect('/')->with('error', 'Failed to connect Strava: ' . $e->getMessage());
        }
    }
}

