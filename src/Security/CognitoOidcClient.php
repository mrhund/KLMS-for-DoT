<?php

namespace App\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\RS256;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\Oidc\OidcTokenHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CognitoOidcClient
{
    private const SESSION_STATE = 'cognito_oauth_state';
    private const SESSION_NONCE = 'cognito_oauth_nonce';
    private const SESSION_VERIFIER = 'cognito_oauth_verifier';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $cognitoDomain,
        private readonly string $cognitoIssuer,
        private readonly string $cognitoClientId,
        private readonly string $cognitoClientSecret,
        private readonly string $cognitoRedirectUri,
        private readonly string $cognitoLogoutUri,
    ) {
    }

    public function startAuthorization(Request $request): RedirectResponse
    {
        $this->assertConfigured();

        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $session = $request->getSession();
        $session->set(self::SESSION_STATE, $state);
        $session->set(self::SESSION_NONCE, $nonce);
        $session->set(self::SESSION_VERIFIER, $verifier);

        $query = http_build_query([
            'client_id' => $this->cognitoClientId,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $this->cognitoRedirectUri,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($this->domainUri('/oauth2/authorize').'?'.$query);
    }

    /**
     * @return array{sub: string, cognito:username: string, email?: string, email_verified?: bool, iss: string, token_use?: string}
     */
    public function exchangeCode(Request $request): array
    {
        $this->assertConfigured();
        $session = $request->getSession();
        $state = (string) $request->query->get('state', '');
        $code = (string) $request->query->get('code', '');
        $expectedState = (string) $session->get(self::SESSION_STATE, '');
        $nonce = (string) $session->get(self::SESSION_NONCE, '');
        $verifier = (string) $session->get(self::SESSION_VERIFIER, '');

        $session->remove(self::SESSION_STATE);
        $session->remove(self::SESSION_NONCE);
        $session->remove(self::SESSION_VERIFIER);

        if ($code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new BadCredentialsException('Invalid Cognito OAuth state.');
        }

        try {
            $options = [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->cognitoClientId,
                    'code' => $code,
                    'redirect_uri' => $this->cognitoRedirectUri,
                    'code_verifier' => $verifier,
                ],
            ];
            if ($this->cognitoClientSecret !== '') {
                $options['headers']['Authorization'] = 'Basic '.base64_encode(
                    rawurlencode($this->cognitoClientId).':'.rawurlencode($this->cognitoClientSecret),
                );
            }

            $response = $this->httpClient->request('POST', $this->domainUri('/oauth2/token'), $options)->toArray();
        } catch (\Throwable $exception) {
            $this->logger->error('Cognito token exchange failed.', ['exception' => $exception]);
            throw new AuthenticationServiceException('Cognito token exchange failed.', 0, $exception);
        }

        if (!isset($response['id_token']) || !is_string($response['id_token'])) {
            throw new BadCredentialsException('Cognito did not return an ID token.');
        }

        $badge = $this->tokenHandler()->getUserBadgeFrom($response['id_token']);
        $claims = $badge->getAttributes();
        if ($nonce === '' || !isset($claims['nonce']) || !hash_equals($nonce, (string) $claims['nonce'])) {
            throw new BadCredentialsException('Invalid Cognito nonce.');
        }
        if (($claims['token_use'] ?? null) !== 'id'
            || !isset($claims['sub'], $claims['cognito:username'], $claims['iss'])
            || !is_string($claims['sub'])
            || !is_string($claims['cognito:username'])
            || !is_string($claims['iss'])) {
            throw new BadCredentialsException('Cognito token is missing required claims.');
        }

        return $claims;
    }

    public function createLogoutRedirect(): RedirectResponse
    {
        $this->assertConfigured();
        $query = http_build_query([
            'client_id' => $this->cognitoClientId,
            'logout_uri' => $this->cognitoLogoutUri,
        ], '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($this->domainUri('/logout').'?'.$query);
    }

    public function getIssuer(): string
    {
        return rtrim($this->cognitoIssuer, '/');
    }

    private function tokenHandler(): OidcTokenHandler
    {
        $handler = new OidcTokenHandler(
            new AlgorithmManager([new RS256()]),
            null,
            $this->cognitoClientId,
            [$this->getIssuer()],
            'sub',
            $this->logger,
        );
        $handler->enableDiscovery(
            $this->cache,
            $this->httpClient->withOptions(['base_uri' => $this->getIssuer().'/']),
            'cognito_oidc_configuration',
        );

        return $handler;
    }

    private function assertConfigured(): void
    {
        foreach ([
            $this->cognitoDomain,
            $this->cognitoIssuer,
            $this->cognitoClientId,
            $this->cognitoRedirectUri,
            $this->cognitoLogoutUri,
        ] as $value) {
            if (trim($value) === '') {
                throw new AuthenticationServiceException('Cognito is not configured.');
            }
        }
    }

    private function domainUri(string $path): string
    {
        return rtrim($this->cognitoDomain, '/').$path;
    }
}
