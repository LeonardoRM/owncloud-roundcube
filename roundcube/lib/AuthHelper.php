<?php
/**
 * ownCloud - RoundCube mail plugin
 *
 * @author Martin Reinhardt
 * @author 2019 Leonardo R. Morelli github.com/LeonardoRM
 * @copyright 2013 Martin Reinhardt contact@martinreinhardt-online.de
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

use OCA\RoundCube\BackLogin;
use OCA\RoundCube\Crypto;
use OCP\Util;

class AuthHelper
{
    const COOKIE_RC_TOKEN     = "oc-rc-token";
    const COOKIE_RC_STRING    = "oc-rc-string";
    const COOKIE_RC_SESSID    = "roundcube_sessid";
    const COOKIE_RC_SESSAUTH  = "roundcube_sessauth";
    const SESSION_RC_PRIVKEY  = 'oc-rc-privateKey';
    const SESSION_RC_ADDRESS  = 'oc-rc-internal-address';
    const SESSION_RC_SERVER   = 'oc-rc-server';

    /**
     * Save Login data for later login into roundcube server
     *
     * @param array $params Keys are: [run,uid,password]
     * @return true if login was successfull otherwise false
     */
    public static function postLogin($params) {
        \OCP\App::checkAppEnabled('roundcube');
        if (strpos($params['uid'], '@') === false) {
            Util::writeLog('roundcube', __METHOD__ . ": username ({$params['uid']}) is not an email address. Hence, the user needs to have an email address configured.", Util::DEBUG);
        }
        $via = \OC::$server->getRequest()->getRequestUri();
        if (preg_match(
            '#(/ocs/v\d.php|'.
            '/apps/calendar/caldav.php|'.
            '/apps/contacts/carddav.php|'.
            '/remote.php/webdav)/#', $via)
        ) {
            return false;
        }
        Util::writeLog('roundcube', __METHOD__ . ": Preparing login of roundcube user '{$params['uid']}'", Util::DEBUG);
        $passphrase = Crypto::generateToken();
        $pair = Crypto::generateKeyPair($passphrase);
        $plainText = $params['password'];
        $b64crypted = Crypto::publicEncrypt($plainText, $pair['publicKey']);
        \OC::$server->getSession()->set(self::SESSION_RC_PRIVKEY, $pair['privateKey']);
        setcookie(self::COOKIE_RC_TOKEN, $passphrase, 0, "/", "", true, true);
        setcookie(self::COOKIE_RC_STRING, $b64crypted, 0, "/", "", true, true);

        $app = new \OCP\AppFramework\App('roundcube');
        $rcIA = $app->getContainer()->query('OCA\RoundCube\InternalAddress');
        $rcAddress = $rcIA->getAddress();
        $rcServer  = $rcIA->getServer();
        \OC::$server->getSession()->set(AuthHelper::SESSION_RC_ADDRESS, $rcAddress);
        \OC::$server->getSession()->set(AuthHelper::SESSION_RC_SERVER, $rcServer);

        return true;
    }

    /**
     * Logs in to RC webmail.
     * @return bool True on login, false otherwise.
     */
    public static function login() {
        $passphrase = \OC::$server->getRequest()->getCookie(self::COOKIE_RC_TOKEN);
        $b64crypted = \OC::$server->getRequest()->getCookie(self::COOKIE_RC_STRING);
        $encPrivKey = \OC::$server->getSession()->get(self::SESSION_RC_PRIVKEY);
        $password = Crypto::privateDecrypt($b64crypted, $encPrivKey, $passphrase);
        $email = self::getUserEmail();
        $backLogin = new BackLogin($email, $password);
        return $backLogin->login();
    }

    /**
     * Logout from RoundCube server by cleaning up session on OwnCloud logout
     * @return boolean True on success, false otherwise.
     */
    public static function logout() {
        \OCP\App::checkAppEnabled('roundcube');
        $email = self::getUserEmail();
        if (strpos($email, '@') === false) {
            Util::writeLog('roundcube', __METHOD__ . ": user email ($email) is not an email address.", Util::WARN);
            return false;
        }
        \OC::$server->getSession()->remove(self::SESSION_RC_PRIVKEY);
        // Expires cookies.
        setcookie(self::COOKIE_RC_TOKEN,    "-del-", 1, "/", "", true, true);
        setcookie(self::COOKIE_RC_STRING,   "-del-", 1, "/", "", true, true);
        setcookie(self::COOKIE_RC_SESSID,   "-del-", 1, "/", "", true, true);
        setcookie(self::COOKIE_RC_SESSAUTH, "-del-", 1, "/", "", true, true);
        Util::writeLog('roundcube', __METHOD__ . ": Logout of user '$email' from RoundCube done.", Util::INFO);
        return true;
    }

    /**
     * Listener which gets invoked if password is changed within ownCloud.
     * @param array $params ['uid', 'password']
     */
    public static function changePasswordListener($params) {
        if (isset($params['uid']) && isset($params['password'])) {
            self::login();
        }
    }

    /**
     * Returns the email address of user, if any.
     * If the uid is an email, it'll return it regardless of the user email.
     * If neither the uid or the user email are an email, it'll return the uid.
     */
    public static function getUserEmail() {
        $uid = \OC::$server->getUserSession()->getUser()->getUID();
        if (strpos($uid, '@') !== false) {
            return $uid;
        }

        $email = \OC::$server->getUserSession()->getUser()->getEMailAddress();
        if (strpos($email, '@') !== false) {
            return $email;
        }

        return $uid; // returns a non-empty default
    }
}
