<?php

namespace App\Services;

use App\Models\DataSource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Strava OAuth Service
 *
 * Handles OAuth2 authentication flow for Strava API,
 * including token exchange, refresh, and storage.
 */
class StravaOAuthService
{
    /**
     * Get the authorization URL for Strava OAuth
     */
    public function getAuthorizationUrl(): string
    {
        $config = Config::get('integrations.strava');
        
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $config['scope'],
            'approval_prompt' => 'force',
        ];

        return $config['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code): array
    {
        $config = Config::get('integrations.strava');

        try {
            // On Windows, try curl first if available
            $useCurl = PHP_OS_FAMILY === 'Windows' && function_exists('curl_init');
            
            if ($useCurl) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $config['token_url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded',
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, (int) $config['timeout']);
                
                $responseBody = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                
                if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
                    Log::error('Strava token exchange failed (curl)', [
                        'status' => $httpCode,
                        'error' => $curlError,
                    ]);
                    throw new \Exception('Failed to exchange authorization code for token');
                }
                
                $data = json_decode($responseBody, true);
            } else {
                $response = Http::timeout($config['timeout'])
                    ->withoutVerifying()
                    ->asForm()
                    ->post($config['token_url'], [
                        'client_id' => $config['client_id'],
                        'client_secret' => $config['client_secret'],
                        'code' => $code,
                        'grant_type' => 'authorization_code',
                    ]);

                if (!$response->successful()) {
                    Log::error('Strava token exchange failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \Exception('Failed to exchange authorization code for token');
                }

                $data = $response->json();
            }

            return [
                'access_token' => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => isset($data['expires_at']) 
                    ? now()->setTimestamp($data['expires_at']) 
                    : null,
                'expires_in' => $data['expires_in'] ?? null,
                'athlete' => $data['athlete'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Strava OAuth error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Refresh an expired access token
     */
    public function refreshToken(DataSource $dataSource): array
    {
        $config = Config::get('integrations.strava');
        $refreshToken = $dataSource->oauth_refresh_token;

        if (!$refreshToken) {
            throw new \Exception('No refresh token available');
        }

        try {
            // On Windows, try curl first if available
            $useCurl = PHP_OS_FAMILY === 'Windows' && function_exists('curl_init');
            
            if ($useCurl) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $config['token_url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded',
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, (int) $config['timeout']);
                
                $responseBody = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                
                if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
                    Log::error('Strava token refresh failed (curl)', [
                        'status' => $httpCode,
                        'error' => $curlError,
                    ]);
                    throw new \Exception('Failed to refresh access token');
                }
                
                $data = json_decode($responseBody, true);
            } else {
                $response = Http::timeout($config['timeout'])
                    ->withoutVerifying()
                    ->asForm()
                    ->post($config['token_url'], [
                        'client_id' => $config['client_id'],
                        'client_secret' => $config['client_secret'],
                        'refresh_token' => $refreshToken,
                        'grant_type' => 'refresh_token',
                    ]);

                if (!$response->successful()) {
                    Log::error('Strava token refresh failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \Exception('Failed to refresh access token');
                }

                $data = $response->json();
            }

            // Update the data source with new tokens
            $dataSource->update([
                'oauth_token' => $data['access_token'] ?? null,
                'oauth_refresh_token' => $data['refresh_token'] ?? $refreshToken, // Keep old if not provided
                'oauth_token_expires_at' => isset($data['expires_at']) 
                    ? now()->setTimestamp($data['expires_at']) 
                    : now()->addSeconds($data['expires_in'] ?? 21600),
            ]);

            Log::info('Strava token refreshed successfully', [
                'source_id' => $dataSource->id,
            ]);

            return [
                'access_token' => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => isset($data['expires_at']) 
                    ? now()->setTimestamp($data['expires_at']) 
                    : null,
            ];
        } catch (\Exception $e) {
            Log::error('Strava token refresh error', [
                'source_id' => $dataSource->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Store tokens in the data source
     */
    public function storeTokens(DataSource $dataSource, array $tokenData): void
    {
        $dataSource->update([
            'oauth_token' => $tokenData['access_token'] ?? null,
            'oauth_refresh_token' => $tokenData['refresh_token'] ?? null,
            'oauth_token_expires_at' => $tokenData['expires_at'] ?? null,
            'is_active' => true,
        ]);

        Log::info('Strava tokens stored', [
            'source_id' => $dataSource->id,
        ]);
    }
}

