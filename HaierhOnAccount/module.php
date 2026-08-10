<?php

declare(strict_types=1);

class HaierhOnAccount extends IPSModuleStrict
{
    private const CHILD_TO_PARENT = '{3D4DC5E6-0F30-4F61-8EF3-85675A2DEF79}';
    private const AUTH_EXPIRE_WARNING_SECONDS = 7 * 60 * 60;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('InitialRefreshToken', '');
        $this->RegisterPropertyInteger('Timeout', 30);
        $this->RegisterPropertyString('AppVersion', '2.0.10');
        $this->RegisterPropertyString('ClientId', '3MVG9QDx8IX8nP5T2Ha8ofvlmjLZl5L_gvfbT9.HJvpHGKoAS_dcMN8LYpTSYeVFCraUnV.2Ag1Ki7m4znVO6');
        $this->RegisterPropertyString('ApiBase', 'https://api-iot.he.services');
        $this->RegisterPropertyString('AuthBase', 'https://account2.hon-smarthome.com');
        $this->RegisterPropertyString('UserAgent', 'Chrome/110.0.5481.153');

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('IdToken', '');
        $this->RegisterAttributeString('CognitoToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenTimestamp', 0);
        $this->RegisterAttributeString('AppliancesJson', '[]');
        $this->RegisterAttributeString('LastError', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('Email') === '' && $this->ReadPropertyString('InitialRefreshToken') === '' && $this->ReadAttributeString('RefreshToken') === '') {
            $this->SetStatus(104);
            return;
        }

        if ($this->ReadPropertyString('InitialRefreshToken') !== '' && $this->ReadAttributeString('RefreshToken') === '') {
            $this->WriteAttributeString('RefreshToken', $this->ReadPropertyString('InitialRefreshToken'));
        }

        $this->SetStatus(102);
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || (($data['DataID'] ?? '') !== self::CHILD_TO_PARENT)) {
            return json_encode(['success' => false, 'error' => 'Unsupported data packet']);
        }

        try {
            $result = match ((string) ($data['Action'] ?? '')) {
                'Request' => $this->ApiRequest(
                    (string) ($data['Method'] ?? 'GET'),
                    (string) ($data['Endpoint'] ?? ''),
                    is_array($data['Query'] ?? null) ? $data['Query'] : [],
                    is_array($data['Body'] ?? null) ? $data['Body'] : null
                ),
                'Appliances' => json_decode($this->ReadAttributeString('AppliancesJson'), true),
                default => throw new RuntimeException('Unsupported action')
            };
            return json_encode(['success' => true, 'payload' => $result]);
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage(), 202);
            return json_encode(['success' => false, 'error' => $exception->getMessage()]);
        }
    }

    public function Login(): bool
    {
        try {
            if ($this->ReadAttributeString('RefreshToken') !== '') {
                if ($this->RefreshTokens()) {
                    $this->SetStatus(102);
                    return true;
                }
            }

            $this->AuthenticateWithPassword();
            $this->SetStatus(102);
            return true;
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage(), 201);
            return false;
        }
    }

    public function RefreshAppliances(): string
    {
        try {
            $response = $this->ApiRequest('GET', '/commands/v1/appliance');
            $appliances = $response['payload']['appliances'] ?? [];
            if (!is_array($appliances)) {
                throw new RuntimeException('Appliance response does not contain a valid appliance list');
            }

            $json = json_encode($appliances, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->WriteAttributeString('AppliancesJson', $json === false ? '[]' : $json);
            $this->SetStatus(102);
            return $this->ReadAttributeString('AppliancesJson');
        } catch (Throwable $exception) {
            $this->RememberError($exception->getMessage(), 202);
            return '[]';
        }
    }

    public function GetAppliancesJson(): string
    {
        return $this->ReadAttributeString('AppliancesJson');
    }

    public function GetLastError(): string
    {
        return $this->ReadAttributeString('LastError');
    }

    public function ApiRequest(string $method, string $endpoint, array $query = [], ?array $body = null): array
    {
        $this->EnsureAuthenticated();

        $url = $this->BuildUrl($this->ReadPropertyString('ApiBase'), $endpoint, $query);
        $headers = [
            'Content-Type: application/json',
            'user-agent: ' . $this->ReadPropertyString('UserAgent'),
            'id-token: ' . $this->ReadAttributeString('IdToken'),
            'cognito-token: ' . $this->ReadAttributeString('CognitoToken')
        ];
        $response = $this->HttpRequest($method, $url, $headers, $body);

        if (($response['status'] === 401 || $response['status'] === 403) && $this->RefreshTokens()) {
            $headers[2] = 'id-token: ' . $this->ReadAttributeString('IdToken');
            $headers[3] = 'cognito-token: ' . $this->ReadAttributeString('CognitoToken');
            $response = $this->HttpRequest($method, $url, $headers, $body);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('hOn API returned HTTP ' . $response['status'] . ' for ' . $endpoint);
        }

        return $this->DecodeJson((string) $response['body'], 'hOn API response');
    }

    private function EnsureAuthenticated(): void
    {
        if ($this->ReadAttributeString('IdToken') !== '' && $this->ReadAttributeString('CognitoToken') !== '' && (time() - $this->ReadAttributeInteger('TokenTimestamp')) < self::AUTH_EXPIRE_WARNING_SECONDS) {
            return;
        }

        if (!$this->Login()) {
            throw new RuntimeException('Authentication failed');
        }
    }

    private function RefreshTokens(): bool
    {
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if ($refreshToken === '') {
            return false;
        }

        $url = $this->BuildUrl($this->ReadPropertyString('AuthBase'), '/services/oauth2/token', [
            'client_id' => $this->ReadPropertyString('ClientId'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ]);
        $response = $this->HttpRequest('POST', $url, ['user-agent: ' . $this->ReadPropertyString('UserAgent')], null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return false;
        }

        $data = $this->DecodeJson((string) $response['body'], 'OAuth refresh response');
        $this->WriteAttributeString('AccessToken', (string) ($data['access_token'] ?? ''));
        $this->WriteAttributeString('IdToken', (string) ($data['id_token'] ?? ''));
        return $this->LoginToApi();
    }

    private function AuthenticateWithPassword(): void
    {
        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            throw new RuntimeException('E-mail/password or a refresh token is required');
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'hon-auth-');
        if ($cookieFile === false) {
            throw new RuntimeException('Could not create temporary cookie jar');
        }

        try {
            $introduceUrl = $this->LoadLoginUrl($cookieFile);
            if ($introduceUrl !== '') {
                $loginUrl = $this->PrepareSalesforceLogin($introduceUrl, $cookieFile);
                $tokenUrl = $this->SubmitSalesforceLogin($loginUrl, $cookieFile);
                if ($tokenUrl !== '') {
                    $this->FetchOAuthTokens($tokenUrl, $cookieFile);
                }
            }
            if (!$this->LoginToApi()) {
                throw new RuntimeException('hOn API login did not return a Cognito token');
            }
        } finally {
            @unlink($cookieFile);
        }
    }

    private function LoadLoginUrl(string $cookieFile): string
    {
        $redirectUri = 'hon://mobilesdk/detect/oauth/done';
        $query = [
            'response_type' => 'token id_token',
            'client_id' => $this->ReadPropertyString('ClientId'),
            'redirect_uri' => $redirectUri,
            'display' => 'touch',
            'scope' => 'api openid refresh_token web',
            'nonce' => $this->CreateNonce()
        ];
        $url = $this->BuildUrl($this->ReadPropertyString('AuthBase'), '/services/oauth2/authorize/expid_Login', $query);
        $response = $this->SafeGet($url, $cookieFile);
        $body = (string) $response['body'];

        if (str_contains($body, 'oauth/done#access_token=')) {
            $this->ParseTokenUrl($body);
            return '';
        }

        if (preg_match("/url = '(.+?)'/", $body, $matches) !== 1) {
            if (preg_match('/(?:location(?:\.href)?\s*=|location\.replace\()\s*["\'](.+?)["\']/', $body, $matches) !== 1
                && preg_match('/href\s*=\s*["\'](.+?hOnRedirect.+?)["\']/', $body, $matches) !== 1) {
                $finalUrl = (string) ($response['url'] ?? '');
                if ($finalUrl !== '' && (str_contains($finalUrl, 'hOnRedirect') || str_contains($finalUrl, 'startURL='))) {
                    return $finalUrl;
                }

                throw new RuntimeException(
                    'Could not find hOn login URL in authorize response from '
                    . $this->DescribeUrl($finalUrl !== '' ? $finalUrl : $url)
                    . '; '
                    . $this->DescribeHtmlForDebug($body)
                );
            }
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }

    private function PrepareSalesforceLogin(string $loginUrl, string $cookieFile): string
    {
        $first = $this->HttpRequest('GET', $this->NormalizeAuthUrl($loginUrl), ['user-agent: ' . $this->ReadPropertyString('UserAgent')], null, false, $cookieFile);
        $location = $first['headers']['location'] ?? '';
        if ($location === '') {
            $location = $loginUrl;
        }

        $second = $this->HttpRequest('GET', $this->NormalizeAuthUrl($location), ['user-agent: ' . $this->ReadPropertyString('UserAgent')], null, false, $cookieFile);
        $redirect = $second['headers']['location'] ?? $location;
        $redirect = $this->NormalizeAuthUrl($redirect);
        return $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'System=IoT_Mobile_App&RegistrationSubChannel=hOn';
    }

    private function SubmitSalesforceLogin(string $loginUrl, string $cookieFile): string
    {
        $page = $this->SafeGet($loginUrl, $cookieFile);
        $body = (string) $page['body'];

        $context = $this->ExtractAuraContext($body);
        if ($context === []) {
            $form = $this->ExtractLoginForm($body, $loginUrl);
            if ($form !== []) {
                return $this->SubmitNewHonLoginForm($form, $loginUrl, $cookieFile);
            }
            throw new RuntimeException('Could not read Salesforce Aura login context; ' . $this->DescribeHtmlForDebug($body));
        }

        $loaded = json_decode($context['loaded'], true);
        if (!is_array($loaded)) {
            throw new RuntimeException('Salesforce Aura login context is not valid JSON');
        }

        $pageUri = $this->MakeAuthRelativeUrl($loginUrl);
        $startUrl = $this->ExtractStartUrl($pageUri);
        $action = [
            'id' => '79;a',
            'descriptor' => 'apex://LightningLoginCustomController/ACTION$login',
            'callingDescriptor' => 'markup://c:loginForm',
            'params' => [
                'username' => rawurlencode($this->ReadPropertyString('Email')),
                'password' => rawurlencode($this->ReadPropertyString('Password')),
                'startUrl' => $startUrl
            ]
        ];

        $auraPayload = [
            'message' => ['actions' => [$action]],
            'aura.context' => [
                'mode' => 'PROD',
                'fwuid' => $context['fwuid'],
                'app' => 'siteforce:loginApp2',
                'loaded' => $loaded,
                'dn' => [],
                'globals' => new stdClass(),
                'uad' => false
            ],
            'aura.pageURI' => $pageUri,
            'aura.token' => null
        ];

        $formBody = $this->BuildAuraFormBody($auraPayload);
        $response = $this->HttpRequest('POST', $this->BuildUrl($this->ReadPropertyString('AuthBase'), '/s/sfsites/aura', [
            'r' => 3,
            'other.LightningLoginCustom.login' => 1
        ]), [
            'Content-Type: application/x-www-form-urlencoded',
            'user-agent: ' . $this->ReadPropertyString('UserAgent')
        ], null, false, $cookieFile, $formBody);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Salesforce Aura login returned HTTP ' . $response['status']);
        }

        $data = $this->DecodeJson((string) $response['body'], 'Salesforce Aura login response');
        $url = (string) ($data['events'][0]['attributes']['values']['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Salesforce Aura login did not return a redirect URL');
        }

        return $url;
    }

    private function SubmitNewHonLoginForm(array $form, string $loginUrl, string $cookieFile): string
    {
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $hasUserField = false;
        $hasPasswordField = false;

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $lowerName = strtolower((string) $name);
            $type = strtolower((string) ($field['type'] ?? ''));
            if (!$hasUserField && ($type === 'email' || str_contains($lowerName, 'email') || str_contains($lowerName, 'user') || str_contains($lowerName, 'login'))) {
                $fields[$name]['value'] = $this->ReadPropertyString('Email');
                $hasUserField = true;
                continue;
            }

            if (!$hasPasswordField && ($type === 'password' || str_contains($lowerName, 'pass'))) {
                $fields[$name]['value'] = $this->ReadPropertyString('Password');
                $hasPasswordField = true;
            }
        }

        if (!$hasUserField) {
            $fields['username'] = ['value' => $this->ReadPropertyString('Email')];
        }
        if (!$hasPasswordField) {
            $fields['password'] = ['value' => $this->ReadPropertyString('Password')];
        }
        if (!array_key_exists('startURL', $fields)) {
            $fields['startURL'] = ['value' => $this->ExtractStartUrl($this->MakeAuthRelativeUrl($loginUrl))];
        }

        $postFields = [];
        foreach ($fields as $name => $field) {
            if ((string) $name === '') {
                continue;
            }
            $postFields[(string) $name] = is_array($field) ? (string) ($field['value'] ?? '') : (string) $field;
        }

        $action = (string) ($form['action'] ?? '/NewhOnLogin');
        $actionUrl = $this->ResolveUrl($action, $loginUrl);
        $response = $this->HttpRequest('POST', $actionUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'user-agent: ' . $this->ReadPropertyString('UserAgent'),
            'referer: ' . $this->NormalizeAuthUrl($loginUrl)
        ], null, false, $cookieFile, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));

        if ($response['status'] < 200 || $response['status'] >= 400) {
            throw new RuntimeException('New hOn login form returned HTTP ' . $response['status'] . '; ' . $this->DescribeHtmlForDebug((string) $response['body']));
        }

        $location = (string) ($response['headers']['location'] ?? '');
        if ($location !== '') {
            if ($this->ParseTokenUrl($location)) {
                return '';
            }
            return $this->ResolveUrl($location, $actionUrl);
        }

        $body = (string) $response['body'];
        if ($this->ParseTokenUrl($body)) {
            return '';
        }

        if (preg_match('/(?:location(?:\.href)?\s*=|location\.replace\()\s*["\'](.+?)["\']/', $body, $matches) === 1) {
            return $this->ResolveUrl(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), $actionUrl);
        }

        $href = $this->FindOAuthHref($body);
        if ($href !== '') {
            return $this->ResolveUrl($href, $actionUrl);
        }

        throw new RuntimeException('New hOn login form was not accepted or did not return an hOn OAuth redirect; ' . $this->DescribeHtmlForDebug($body));
    }

    private function FetchOAuthTokens(string $url, string $cookieFile): void
    {
        $currentUrl = $url;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($this->ParseTokenUrl($currentUrl)) {
                return;
            }

            if (!$this->IsHttpUrl($currentUrl) && !str_starts_with(trim($currentUrl), '/')) {
                throw new RuntimeException('OAuth redirect returned an unsupported URL scheme');
            }

            $requestUrl = $this->NormalizeAuthUrl($currentUrl);
            $response = $this->HttpRequest('GET', $requestUrl, ['user-agent: ' . $this->ReadPropertyString('UserAgent')], null, false, $cookieFile);
            $location = (string) ($response['headers']['location'] ?? '');
            if ($location !== '') {
                if ($this->ParseTokenUrl($location)) {
                    return;
                }
                $currentUrl = $this->ResolveUrl($location, $requestUrl);
                continue;
            }

            break;
        }

        if (!isset($response) || $response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('OAuth redirect returned HTTP ' . ($response['status'] ?? 0));
        }

        $body = (string) $response['body'];
        if ($this->ParseTokenUrl($body)) {
            return;
        }

        $nextUrl = $this->FindOAuthHref($body);
        if ($nextUrl === '') {
            throw new RuntimeException('OAuth redirect did not contain a token link');
        }

        $nextUrl = $this->ResolveUrl($nextUrl, $requestUrl);
        if (str_contains($nextUrl, 'ProgressiveLogin')) {
            $progressive = $this->SafeGet($nextUrl, $cookieFile);
            if ($progressive['status'] < 200 || $progressive['status'] >= 300) {
                throw new RuntimeException('Progressive login returned HTTP ' . $progressive['status']);
            }

            $progressiveUrl = (string) ($progressive['url'] ?? $nextUrl);
            $nextUrl = $this->FindOAuthHref((string) $progressive['body']);
            if ($nextUrl === '') {
                throw new RuntimeException('Progressive login did not contain a token link');
            }
            $nextUrl = $this->ResolveUrl($nextUrl, $progressiveUrl);
        }

        $tokenResponse = $this->SafeGet($nextUrl, $cookieFile);
        if ($tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300) {
            throw new RuntimeException('OAuth token page returned HTTP ' . $tokenResponse['status'] . ' for ' . $this->DescribeUrl($nextUrl));
        }

        if (!$this->ParseTokenUrl((string) $tokenResponse['body'])) {
            throw new RuntimeException('OAuth token page did not contain all required tokens; ' . $this->DescribeHtmlForDebug((string) $tokenResponse['body']));
        }
    }

    private function ParseTokenUrl(string $tokenSource): bool
    {
        if (!str_contains($tokenSource, 'id_token=') && !str_contains($tokenSource, 'access_token=') && !str_contains($tokenSource, 'refresh_token=')) {
            return false;
        }

        if (preg_match('/oauth\/done#([^"\']+)/', $tokenSource, $matches) === 1) {
            $tokenSource = $matches[1];
        } elseif (str_contains($tokenSource, '#')) {
            $tokenSource = substr($tokenSource, strpos($tokenSource, '#') + 1);
        }

        $tokens = $this->ParseOAuthFragment($tokenSource);
        $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') {
            return false;
        }

        $this->WriteAttributeString('AccessToken', (string) ($tokens['access_token'] ?? ''));
        $this->WriteAttributeString('IdToken', $idToken);
        if (isset($tokens['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', (string) $tokens['refresh_token']);
        }
        return $this->ReadAttributeString('AccessToken') !== '' && $this->ReadAttributeString('RefreshToken') !== '';
    }

    private function ParseOAuthFragment(string $fragment): array
    {
        $fragment = str_replace('&amp;', '&', $fragment);
        $tokens = [];
        foreach (explode('&', $fragment) as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key = rawurldecode($key);
            if (!in_array($key, ['access_token', 'refresh_token', 'id_token'], true)) {
                continue;
            }

            $tokens[$key] = rawurldecode($value);
        }

        return $tokens;
    }

    private function LoginToApi(): bool
    {
        $idToken = $this->ReadAttributeString('IdToken');
        if ($idToken === '') {
            return false;
        }

        $body = [
            'appVersion' => $this->ReadPropertyString('AppVersion'),
            'mobileId' => $this->CreateMobileId(),
            'osVersion' => 31,
            'os' => 'android',
            'deviceModel' => 'exynos9820'
        ];

        $response = $this->HttpRequest('POST', $this->BuildUrl($this->ReadPropertyString('ApiBase'), '/auth/v1/login'), [
            'Content-Type: application/json',
            'user-agent: ' . $this->ReadPropertyString('UserAgent'),
            'id-token: ' . $idToken
        ], $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return false;
        }

        $data = $this->DecodeJson((string) $response['body'], 'hOn API login response');
        $cognito = (string) ($data['cognitoUser']['Token'] ?? '');
        if ($cognito === '') {
            return false;
        }

        $this->WriteAttributeString('CognitoToken', $cognito);
        $this->WriteAttributeInteger('TokenTimestamp', time());
        $this->WriteAttributeString('LastError', '');
        return true;
    }

    private function HttpRequest(string $method, string $url, array $headers = [], ?array $body = null, bool $followRedirects = true, string $cookieFile = '', string $rawBody = ''): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for hOn HTTP requests');
        }

        $this->AssertHttpUrl($url);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP request');
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $this->ReadPropertyInteger('Timeout'),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $header) use (&$responseHeaders): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            }
        ]);

        if ($cookieFile !== '') {
            curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieFile);
        }

        if ($rawBody !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $rawBody);
        } elseif ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Could not encode HTTP body');
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        }

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed for ' . $this->DescribeUrl($url) . ': ' . $error);
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    private function SafeGet(string $url, string $cookieFile): array
    {
        $currentUrl = $url;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($this->ParseTokenUrl($currentUrl)) {
                return ['status' => 200, 'headers' => [], 'body' => $currentUrl, 'url' => $currentUrl];
            }

            if (!$this->IsHttpUrl($currentUrl) && !str_starts_with(trim($currentUrl), '/')) {
                throw new RuntimeException('Redirect returned an unsupported URL scheme');
            }

            $requestUrl = $this->NormalizeAuthUrl($currentUrl);
            $response = $this->HttpRequest('GET', $requestUrl, ['user-agent: ' . $this->ReadPropertyString('UserAgent')], null, false, $cookieFile);
            $response['url'] = $requestUrl;
            $location = (string) ($response['headers']['location'] ?? '');
            if ($location === '') {
                return $response;
            }

            if ($this->ParseTokenUrl($location)) {
                return ['status' => 200, 'headers' => [], 'body' => $location, 'url' => $location];
            }
            $currentUrl = $location;
        }

        throw new RuntimeException('Too many redirects during hOn login');
    }

    private function BuildUrl(string $base, string $endpoint, array $query = [], bool $encodeValues = true): string
    {
        $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
        if ($query === []) {
            return $url;
        }

        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . ($encodeValues ? rawurlencode((string) $value) : (string) $value);
        }
        return $url . '?' . implode('&', $parts);
    }

    private function DecodeJson(string $json, string $label): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException($label . ' did not contain valid JSON');
        }
        return $data;
    }

    private function ExtractAuraContext(string $html): array
    {
        foreach ([$html, html_entity_decode($html, ENT_QUOTES | ENT_HTML5)] as $candidate) {
            if (preg_match('/"fwuid"\s*:\s*"([^"]+)"/', $candidate, $fwuidMatch) !== 1) {
                continue;
            }

            $loadedPosition = strpos($candidate, '"loaded"');
            if ($loadedPosition === false) {
                continue;
            }

            $colonPosition = strpos($candidate, ':', $loadedPosition);
            if ($colonPosition === false) {
                continue;
            }

            $braceStart = strpos($candidate, '{', $colonPosition);
            if ($braceStart === false) {
                continue;
            }

            $loadedJson = $this->ExtractBalancedJsonObject($candidate, $braceStart);
            if ($loadedJson !== '' && json_decode($loadedJson, true) !== null) {
                return ['fwuid' => $fwuidMatch[1], 'loaded' => $loadedJson];
            }
        }

        return [];
    }

    private function ExtractLoginForm(string $html, string $baseUrl): array
    {
        if (preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms) === 0) {
            return [];
        }

        foreach ($forms[0] as $formHtml) {
            $attributes = $this->ParseHtmlAttributes($formHtml);
            $action = (string) ($attributes['action'] ?? '');
            if ($action === '') {
                $action = $baseUrl;
            }

            if (!str_contains(strtolower($action), 'newhonlogin') && !str_contains(strtolower($formHtml), 'password')) {
                continue;
            }

            $fields = [];
            if (preg_match_all('/<input\b[^>]*>/i', $formHtml, $inputs) > 0) {
                foreach ($inputs[0] as $inputHtml) {
                    $inputAttributes = $this->ParseHtmlAttributes($inputHtml);
                    $name = (string) ($inputAttributes['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }

                    $fields[$name] = [
                        'type' => (string) ($inputAttributes['type'] ?? 'text'),
                        'value' => (string) ($inputAttributes['value'] ?? '')
                    ];
                }
            }

            return ['action' => $action, 'fields' => $fields];
        }

        return [];
    }

    private function ParseHtmlAttributes(string $html): array
    {
        $attributes = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5);
            }
        }

        return $attributes;
    }

    private function ExtractBalancedJsonObject(string $text, int $start): string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }

    private function BuildAuraFormBody(array $payload): string
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Could not encode Salesforce Aura payload');
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode($encoded);
        }
        return implode('&', $parts);
    }

    private function ExtractStartUrl(string $pageUri): string
    {
        $parts = explode('startURL=', $pageUri, 2);
        if (count($parts) !== 2) {
            return '';
        }

        $startUrl = rawurldecode($parts[1]);
        return explode('%3D', $startUrl, 2)[0];
    }

    private function MakeAuthRelativeUrl(string $url): string
    {
        $authBase = rtrim($this->ReadPropertyString('AuthBase'), '/');
        if (str_starts_with($url, $authBase)) {
            return substr($url, strlen($authBase));
        }
        return $url;
    }

    private function NormalizeAuthUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($this->IsHttpUrl($url)) {
            return $url;
        }
        return rtrim($this->ReadPropertyString('AuthBase'), '/') . '/' . ltrim($url, '/');
    }

    private function FindOAuthHref(string $html): string
    {
        if (preg_match_all('/href\s*=\s*["\'](.+?)["\']/', $html, $matches) === 0 || $matches[1] === []) {
            return '';
        }

        $fallback = '';
        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
            $lowerHref = strtolower($href);
            if (str_contains($lowerHref, 'oauth/done') || str_contains($lowerHref, 'access_token=') || str_contains($lowerHref, 'id_token=')) {
                return $href;
            }
            if (str_contains($href, 'ProgressiveLogin') && $this->IsHonOAuthLink($href)) {
                return $href;
            }
            if ($fallback === '' && $this->IsHonOAuthLink($href)) {
                $fallback = $href;
            }
        }

        return $fallback;
    }

    private function IsHonOAuthLink(string $href): bool
    {
        $lowerHref = strtolower($href);
        if (str_contains($lowerHref, 'google.') || str_contains($lowerHref, 'apple.') || str_contains($lowerHref, 'facebook.') || str_contains($lowerHref, 'support.google') || str_contains($lowerHref, 'myaccount.google')) {
            return false;
        }

        return str_contains($href, 'RemoteAccessAuthorizationPage')
            || str_contains($href, 'hOnRedirect')
            || str_contains($href, 'ProgressiveLogin')
            || str_contains($href, 'account2.hon-smarthome.com/services/oauth2')
            || str_starts_with($href, '/services/oauth2/')
            || str_starts_with($href, 'services/oauth2/');
    }

    private function ResolveUrl(string $url, string $baseUrl): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '' || $this->IsHttpUrl($url) || str_starts_with($url, 'hon://')) {
            return $url;
        }

        $base = parse_url($this->NormalizeAuthUrl($baseUrl));
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return $this->NormalizeAuthUrl($url);
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
        return $origin . $directory . $url;
    }

    private function AssertHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new RuntimeException('Invalid HTTP URL before request: ' . $this->DescribeUrl($url));
        }
    }

    private function DescribeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '[unparseable URL]';
        }

        $scheme = (string) ($parts['scheme'] ?? 'no-scheme');
        $host = (string) ($parts['host'] ?? 'no-host');
        $path = (string) ($parts['path'] ?? '');
        return $scheme . '://' . $host . $path;
    }

    private function DescribeHtmlForDebug(string $html): string
    {
        $hints = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $hints[] = 'title=' . $this->SanitizeDebugText($matches[1]);
        }

        if (preg_match_all('/<form[^>]+action=["\']([^"\']+)["\']/i', $html, $matches) > 0) {
            $hints[] = 'formActions=' . implode(',', array_map(fn (string $value): string => $this->DescribeUrl($this->NormalizeAuthUrl($value)), array_slice($matches[1], 0, 3)));
        }

        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches) > 0) {
            $links = [];
            foreach (array_slice($matches[1], 0, 5) as $value) {
                $links[] = $this->SanitizeDebugText($value, 80);
            }
            $hints[] = 'hrefs=' . implode(',', $links);
        }

        foreach (['hOnRedirect', 'oauth/done', 'RemoteAccessAuthorizationPage', 'login', 'error', 'captcha'] as $needle) {
            if (stripos($html, $needle) !== false) {
                $hints[] = 'contains=' . $needle;
            }
        }

        if (preg_match('/(?:error|error_description)["\'\s:=]+([^<>"\']+)/i', $html, $matches) === 1) {
            $hints[] = 'oauthError=' . $this->SanitizeDebugText($matches[1], 120);
        }

        return $hints === [] ? 'no safe HTML hints found' : implode('; ', $hints);
    }

    private function SanitizeDebugText(string $text, int $maxLength = 120): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))) ?? '');
        $text = preg_replace('/([?&#](?:access_token|refresh_token|id_token|password|username|email)=)[^&#\s]+/i', '$1[redacted]', $text) ?? $text;
        if (strlen($text) > $maxLength) {
            return substr($text, 0, $maxLength) . '...';
        }
        return $text;
    }

    private function IsHttpUrl(string $url): bool
    {
        $url = trim($url);
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function CreateNonce(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function CreateMobileId(): string
    {
        return hash('sha256', $this->ReadPropertyString('Email') . ':' . $this->InstanceID);
    }

    private function RememberError(string $message, int $status): void
    {
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('hOn error', $message, 0);
        $this->SetStatus($status);
    }
}
