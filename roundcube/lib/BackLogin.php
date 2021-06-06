<?php
/**
 * ownCloud - RoundCube mail plugin
 *
 * @author 2019 Leonardo R. Morelli github.com/LeonardoRM
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\RoundCube;

use OCP\Util;
use OCA\RoundCube\AuthHelper;
use OCA\RoundCube\InternalAddress;

/**
 * This class provides the login to RC server using curl.
 */
class BackLogin
{
    const FORM_URLENCODED = 'application/x-www-form-urlencoded';

    // Credentials
    private $email;
    private $password;
    // Config
    private $config;
    private $enableSSLVerify = true;
    private $rcInternalAddress;
    private $rcServer;
    // Params
    private $rcSessionID = "";
    private $rcSessionAuth = "";

    /**
     * @param string $email Email address.
     * @param string $password The password.
     * @return array/bool ['sessid', 'sessauth'] on success, false on error.
     */
    public function __construct($email, $password) {
        $this->email           = $email;
        $this->password        = $password;
        $this->config          = \OC::$server->getConfig();
        $this->enableSSLVerify = $this->config->getAppValue('roundcube', 'enableSSLVerify', true);
        $this->rcServer          = \OC::$server->getSession()->get(AuthHelper::SESSION_RC_SERVER, '');
        $this->rcInternalAddress = \OC::$server->getSession()->get(AuthHelper::SESSION_RC_ADDRESS, '');
    }

    /**
     * Performs a login and stores RoundCube cookies.
     * On success, RoundCube is ready to show up.
     * @return bool True on login, false otherwise.
     */
    public function login() {
        // End previous session:
        // Delete cookies sessauth & sessid by expiring them.
        setcookie(AuthHelper::COOKIE_RC_SESSID, "-del-", 1, "/", "", true, true);
        setcookie(AuthHelper::COOKIE_RC_SESSAUTH, "-del-", 1, "/", "", true, true);
        // Get login page, extracts sessionID and token.
        $loginPageObj = $this->sendRequest("?_task=login", "GET");
        if ($loginPageObj === false) {
            Util::writeLog('roundcube', __METHOD__ . ": Could not get login page.", Util::ERROR);
            return false;
        }
        $cookies = self::parseCookies($loginPageObj['headers']['set-cookie']);
        if (isset($cookies[AuthHelper::COOKIE_RC_SESSID])) {
            $this->rcSessionID = $cookies[AuthHelper::COOKIE_RC_SESSID];
        }
        // Get input values from login form and prepare data to send.
        $inputs = self::parseInputs($loginPageObj['html']);
        $data = array(
            "_token"    => $inputs["_token"]["value"],
            "_task"     => "login",
            "_action"   => "login",
            "_timezone" => $inputs["_timezone"]["value"],
            "_url"      => $inputs["_url"]["value"],
            "_user"     => $this->email,
            "_pass"     => $this->password
        );
        // Post login form.
        $loginAnswerObj = $this->sendRequest("?_task=login&_action=login", "POST", $data);
        if ($loginAnswerObj === false) {
            Util::writeLog('roundcube', __METHOD__ . ": Could not get login response.", Util::ERROR);
            return false;
        }
        // Set cookies sessauth and sessid.
        $cookiesLogin = self::parseCookies($loginAnswerObj['headers']['set-cookie']);
        if (isset($cookiesLogin[AuthHelper::COOKIE_RC_SESSID]) &&
            $cookiesLogin[AuthHelper::COOKIE_RC_SESSID] !== "-del-") {
            $this->rcSessionID = $cookiesLogin[AuthHelper::COOKIE_RC_SESSID];
            setcookie(AuthHelper::COOKIE_RC_SESSID, $this->rcSessionID,
                0, "/", "", true, true);
        }
        if (isset($cookiesLogin[AuthHelper::COOKIE_RC_SESSAUTH]) &&
            $cookiesLogin[AuthHelper::COOKIE_RC_SESSAUTH] !== "-del-") {
            // We received a sessauth => logged in!
            $this->rcSessionAuth = $cookiesLogin[AuthHelper::COOKIE_RC_SESSAUTH];
            setcookie(AuthHelper::COOKIE_RC_SESSAUTH, $this->rcSessionAuth,
                0, "/", "", true, true);
            return true;
        }
        // Check again whether input fields of login form exist.
        $inputsLogin = self::parseInputs($loginAnswerObj['html']);
        if (empty($inputsLogin) || !isset($inputsLogin["_user"]) || !isset($inputsLogin["_pass"])) {
            return true; // It shouldn't get here ever.
        } else {
            Util::writeLog('roundcube', __METHOD__ . ": Could not login.", Util::ERROR);
            return false;
        }
    }

    /**
     * @param string $text The text where to look for input fields.
     * @return array [name => [key, value]] Input fields indexed by name.
     */
    private static function parseInputs($text) {
        $inputs = array();
        if (preg_match_all('/<input ([^>]*)>/i', $text, $inputMatches)) {
            foreach ($inputMatches[1] as $input) {
                if (preg_match_all('/(\w+)="([^"]*)"/i', $input, $keyvalMatches)) {
                    $tmp = array();
                    $name = "";
                    foreach ($keyvalMatches[1] as $index => $key) {
                        if ($key === "name") {
                            $name = $keyvalMatches[2][$index];
                        } else {
                            $tmp[$key] = $keyvalMatches[2][$index];
                        }
                    }
                    if ($name !== "") {
                        $inputs[$name] = $tmp;
                    }
                }
            }
        }
        return $inputs;
    }

    /**
     * Send request using cURL.
     *
     * @param string $rcQuery  Query string to append to rcInternalAddress
     *                       (Example: "?_task=login").
     * @param string $method POST or GET request.
     * @param string $data   Data to send.
     * @return array ['headers' => [headers], 'html' => html]
     */
    private function sendRequest($rcQuery, $method, $data = null) {
        $response = false;
        $rcQuery = $this->rcInternalAddress . "$rcQuery";
        Util::writeLog('roundcube', __METHOD__ . ": URL: '$rcQuery'.", Util::DEBUG);
        try {
            $curl = curl_init();
            // general settings
            $curlOpts = array(
                CURLOPT_URL            => $rcQuery,
                CURLOPT_HEADER         => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FRESH_CONNECT  => true
            );
            if ($method === 'POST') {
                $curlOpts[CURLOPT_POST] = true;
                if ($data) {
                    $postData = http_build_query($data);
                    $curlOpts[CURLOPT_POSTFIELDS] = $postData;
                    $curlOpts[CURLOPT_TIMEOUT] = 60;
                    $curlOpts[CURLOPT_HTTPHEADER] = array(
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                        'Accept-Encoding: identity',
                        'Content-Type: ' . self::FORM_URLENCODED,
                        'Cache-Control: no-cache',
                        'Pragma: no-cache'
                    );
                }
            } else {
                $curlOpts[CURLOPT_HTTPGET] = true;
            }
            $cookies = "";
            if ($this->rcSessionID !== "") {
                $cookies .= AuthHelper::COOKIE_RC_SESSID . "={$this->rcSessionID}; ";
            }
            if ($this->rcSessionAuth !== "") {
                $cookies .= AuthHelper::COOKIE_RC_SESSAUTH . "={$this->rcSessionAuth}; ";
            }
            $curlOpts[CURLOPT_COOKIE] = rtrim($cookies, "; ");
            if (!$this->enableSSLVerify) {
                Util::writeLog('roundcube', __METHOD__ . ": Disabling SSL verification.", Util::WARN);
                $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
                $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
            curl_setopt_array($curl, $curlOpts);

            $rawResponse = curl_exec($curl);

            // Error handling
            $curlErrorNum   = curl_errno($curl);
            $curlError      = curl_error($curl);
            $headerSize     = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $respHttpCode   = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            Util::writeLog('roundcube', __METHOD__ . ": Got the following HTTP Status Code: ($respHttpCode) $curlErrorNum: $curlError", Util::DEBUG);
            if ($curlErrorNum === CURLE_OK && $respHttpCode < 400) {
                $response = self::splitResponse($rawResponse, $headerSize);
            } else {
                Util::writeLog('roundcube', __METHOD__ . ": Opening url '$rcQuery' failed with '$curlError'", Util::WARN);
            }
            curl_close($curl);
        } catch (Exception $e) {
            Util::writeLog('roundcube', __METHOD__ . ": URL '$rcQuery' open failed.", Util::WARN);
        }
        return $response;
    }

    /**
     * Splits a curl response into headers and html.
     * @param string $response
     * @param int    $headerSize
     * @return array ['headers' => [headers], 'html' => html]
     */
    private static function splitResponse($response, $headerSize) {
        $headers = $html = "";
        if ($headerSize) {
            $headers = substr($response, 0, $headerSize);
            $html    = substr($response, $headerSize);
        } else {
            $hh = explode("\r\n\r\n", $response, 2);
            $headers = $hh[0];
            $html    = $hh[1];
        }
        $headersArray = self::parseResponseHeaders($headers);
        return array(
            'headers' => $headersArray,
            'html'    => $html
        );
    }

    /**
     * @param string $rawHeaders String of headers from a curl response.
     * Example:
     * HTTP/1.1 200 OK
     * Date: Tue, 19 Mar 2019 15:19:28 GMT
     * Server: Apache/2.2.22 (Debian)
     * X-Powered-By: PHP/5.4.45-0+deb7u7
     * Expires: Tue, 19 Mar 2019 15:19:28 GMT
     * Cache-Control: private, no-cache, no-store, must-revalidate, post-check=0, pre-check=0
     * Pragma: no-cache
     * Last-Modified: Tue, 19 Mar 2019 15:19:28 GMT
     * X-DNS-Prefetch-Control: off
     * Content-Language: es
     * Vary: Accept-Encoding
     * Content-Type: text/html; charset=UTF-8
     * Set-Cookie: roundcube_sessid=h5s3o6qasjhbd6bq4gfrl5amh2; path=/; secure; HttpOnly
     * Transfer-Encoding: chunked
     *
     * @return array ['name0' => [header0:0, header0:1], 'name1' => [header1:0], ...]
     */
    private static function parseResponseHeaders($rawHeaders) {
        $responseHeaders = array();
        $headerLines = explode("\r\n", trim($rawHeaders));
        foreach ($headerLines as $header) {
            if ($header && is_string($header) && strpos($header, ':') !== false) {
                list($name, $value) = explode(': ', $header, 2);
                $name = strtolower($name);
                if (!isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = array($value);
                } else {
                    $responseHeaders[$name][] = $value;
                }
            }
        }
        return $responseHeaders;
    }

    /**
     * @param array $cookieHeaders ['name=value; text', ...]
     * @return array ['name' => 'value', ...]
     */
    private static function parseCookies($cookieHeaders) {
        $cookies = array();
        if (is_array($cookieHeaders)) {
            foreach ($cookieHeaders as $ch) {
                if (preg_match('/^([^=]+)=([^;]+);/i', $ch, $match)) {
                    if ($match[1] !== "" && $match[2] !== "" && strlen($match[2]) > 5) {
                        $cookies[$match[1]] = $match[2];
                    }
                }
            }
        }
        return $cookies;
    }
}
