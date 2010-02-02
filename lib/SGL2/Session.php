<?php
/* Reminder: always indent with 4 spaces (no tabs). */
// +---------------------------------------------------------------------------+
// | Copyright (c) 2008, Demian Turner                                         |
// | All rights reserved.                                                      |
// |                                                                           |
// | Redistribution and use in source and binary forms, with or without        |
// | modification, are permitted provided that the following conditions        |
// | are met:                                                                  |
// |                                                                           |
// | o Redistributions of source code must retain the above copyright          |
// |   notice, this list of conditions and the following disclaimer.           |
// | o Redistributions in binary form must reproduce the above copyright       |
// |   notice, this list of conditions and the following disclaimer in the     |
// |   documentation and/or other materials provided with the distribution.    |
// | o The names of the authors may not be used to endorse or promote          |
// |   products derived from this software without specific prior written      |
// |   permission.                                                             |
// |                                                                           |
// | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS       |
// | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT         |
// | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR     |
// | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT      |
// | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,     |
// | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT          |
// | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,     |
// | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY     |
// | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT       |
// | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE     |
// | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.      |
// |                                                                           |
// +---------------------------------------------------------------------------+
// | Seagull 2.0                                                               |
// +---------------------------------------------------------------------------+
// | Session.php                                                               |
// +---------------------------------------------------------------------------+
// | Author:   Demian Turner <demian@phpkitchen.com>                           |
// +---------------------------------------------------------------------------+
// $Id: Session.php 4201 2008-10-24 11:59:47Z demian $

/**
 * Handles session management.
 *
 * Typically looks like this for an admin login:
    Array
    (
        [uid] => 1
        [rid] => 1
        [oid] => 1
        [username] => admin
        [startTime] => 1131308728
        [lastRefreshed] => 1131309174
        [key] => 0e3b45dea658ac339d26395ff528db1d
        [currentResRange] => all
        [sortOrder] => ASC
        [aPrefs] => Array
            (
                [sessionTimeout] => 1800
                [timezone] => UTC
                [theme] => default
                [dateFormat] => UK
                [language] => en-iso-8859-15
                [resPerPage] => 10
                [showExecutionTimes] => 1
                [locale] => en_GB
            )

        [aPerms] => Array
            (
            )

        [currentCatId] => 1
        [dataTypeId] => 1
    )
 *
 * @package SGL
 * @author  Demian Turner <demian@phpkitchen.com>
 */
class SGL2_Session
{
    const UPDATE_WINDOW = 10;
    /**
     * Session timeout configurable in preferences.
     *
     * @access  private
     * @var     int
     */
    protected $_timeout;

    /**
     * Setup session.
     *
     *  o custimise session name
     *  o configure custom cookie params
     *  o setup session backed, ie, file or DB
     *  o start session
     *  o persist user object in session
     *
     * @access  public
     * @param   int $uid             user id if present
     * @param   boolean $rememberMe  set remember me cookie
     * @return  void
     */
    public function __construct($uid = -1, $rememberMe = null)
    {
        //  customise session
        $sessName = SGL2_Config::get('cookie.name')
            ? SGL2_Config::get('cookie.name')
            : 'SGLSESSID';
        session_name($sessName);

        //  set session timeout to 0 (until the browser is closed) initially,
        //  then use user timeout in isTimedOut() method
        session_set_cookie_params(
            0,
            SGL2_Config::get('cookie.path'),
            SGL2_Config::get('cookie.domain'),
            SGL2_Config::get('cookie.secure'));

        if (SGL2_File::exists(SGL2_TMP_DIR)) {
            session_save_path(SGL2_TMP_DIR);
        } else {
            throw new Exception(SGL2_TMP_DIR .' either does not exist or is not writable');
        }

        //  start PHP session handler
        $ok = session_start();

        //  if user id is passed in constructor, ie, during login, init user
        if ($uid > 0) {
            require_once 'DB/DataObject.php';
            $sessUser = DB_DataObject::factory(SGL2_Config::get('table.user'));
            $sessUser->get($uid);
            $this->_init($sessUser, $rememberMe);
            if ($rememberMe) {
                $this->_setRememberMeCookie();
            }

        //  if session doesn't exist, initialise
        } elseif (!SGL2_Session::exists()) {
            $this->_init();
        }
    }

    protected function _setRememberMeCookie()
    {
        $cookie = serialize(array($_SESSION['username'], $_SESSION['cookie']));
        $ok = setcookie(
            'SGL2_REMEMBER_ME',
            $cookie,
            time() + 31104000, // 360 days
            SGL2_Config::get('cookie.path'),
            SGL2_Config::get('cookie.domain'),
            SGL2_Config::get('cookie.secure')
        );
    }

    /**
     * Initialises session, sets some default values.
     *
     * @param   object  $oUser  user object if present
     * @return  boolean true on successful initialisation
     */
    protected function _init($oUser = null, $rememberMe = null)
    {
        //  set secure session key
        $startTime = time();
        $acceptLang = @$_SERVER['HTTP_ACCEPT_LANGUAGE'];
        $userAgent = @$_SERVER['HTTP_USER_AGENT'];

        //  user object is passed only during login
        if (is_object($oUser)) {
            //  get UserDAO object
            require_once SGL2_MOD_DIR . '/user/classes/UserDAO.php';
            $da = UserDAO::singleton();

            $aSessVars = array(
                'uid'               => $oUser->usr_id,
                'rid'               => $oUser->role_id,
                'username'          => $oUser->username,
                'startTime'         => $startTime,
                'lastRefreshed'     => $startTime,
                'key'               => md5($oUser->username . $startTime . $acceptLang . $userAgent),
                'aPrefs'            => $da->getPrefsByUserId($oUser->usr_id, $oUser->role_id)
            );

            // for admin we don't need any perms
            if ($oUser->role_id == SGL2_ADMIN) {
                $aPerms = array();
            // check for customized perms
            } elseif (($method = SGL2_Config::get('session.permsRetrievalMethod'))
                    && is_callable(array($da, $method))) {
                $aPerms = $da->$method($oUser);
            // get permissions by user
            } else {
                $aPerms = $da->getPermsByUserId($oUser->usr_id);
            }
            $aSessVars['aPerms'] = $aPerms;

            //  check for rememberMe cookie
            list(, $cookieValue) = @unserialize($_COOKIE['SGL2_REMEMBER_ME']);
            //  if 'remember me' cookie is set remove it
            if (!empty($cookieValue)) {
                $da->deleteUserLoginCookieByUserId($oUser->usr_id, $cookieValue);
            }
            //  add new 'remember me' cookie
            if (!empty($rememberMe)) {
                $salt = 'SGL2_SALT'; // @todo: make salt configurable
                $cookieValue = md5($salt . $aSessVars['key']);
                $da->addUserLoginCookie($oUser->usr_id, $cookieValue);
                $aSessVars['cookie'] = $cookieValue;
            }

        //  otherwise it's a guest session, these values always get
        //  set and exist in the session before a login
        } else {
            //  initialise session with some default values
            $aSessVars = array(
                'uid'               => 0,
                'rid'               => 0,
                'username'          => 'guest',
                'startTime'         => $startTime,
                'lastRefreshed'     => $startTime,
                'key'               => md5($startTime . $acceptLang . $userAgent),
                'currentResRange'   => 'all',
                'sortOrder'         => 'ASC',
                'aPrefs'            => array (
                    'sessionTimeout' => 1800,
                    'timezone' => 'UTC',
                    'theme' => 'default',
                    'dateFormat' => 'UK',
                    'language' => 'en-utf-8',
                    'resPerPage' => 10,
                    'showExecutionTimes' => 1,
                    'locale' => 'en_GB'),
                'aPerms'            => 0,
            );
        }
        //  set vars in session
        if (isset($_SESSION)) {
            foreach ($aSessVars as $k => $v) {
                $_SESSION[$k] = $v;
            }
        }

        //  make session more secure if possible
        if  (function_exists('session_regenerate_id')) {
            $oldSessionId = session_id();
            session_regenerate_id();

            if (SGL2_Config::get('session.handler') == 'file') {

                //  manually remove old session file, see http://ilia.ws/archives/47-session_regenerate_id-Improvement.html
                $ok = unlink(SGL2_TMP_DIR . '/sess_'.$oldSessionId);

            } else {
                die('Internal Error: unknown session handler');
            }
        }
        return true;
    }

    /**
     * Determines whether a session currently exists.
     *
     * @access  public
     * @return  boolean true if session exists and has 1 or more elements
     */
    public static function exists()
    {
        return isset($_SESSION) && count($_SESSION);
    }

    /**
     * Determines whether the current session is valid.
     *
     * @access  public
     * @return  boolean true if session is valid
     */
    public static function isValid()
    {
        $acceptLang = @$_SERVER['HTTP_ACCEPT_LANGUAGE'];
        $userAgent = @$_SERVER['HTTP_USER_AGENT'];
        $currentKey = md5($_SESSION['username'] . $_SESSION['startTime'] .
            $acceptLang . $userAgent);

        //  compare actual key with session key
        return  ($currentKey == $_SESSION['key']);
    }

    /**
     * Returns true if current user is a guest (not logged in)
     *
     * @return boolean
     */
    public function isAnonymous()
    {
        $ret = !((bool) $_SESSION['uid']);
        return $ret;
    }

    /**
     * Determines whether the current session is timed out.
     *
     * @access  public
     * @return  boolean true if session is timed out
     */
    public function isTimedOut()
     {
        //  check for session timeout
        $currentTime = time();
        $lastPageRefreshTime = $_SESSION['lastRefreshed'];
        $timeout = isset($_SESSION['aPrefs']['sessionTimeout'])
            ? $_SESSION['aPrefs']['sessionTimeout']
            : '';
        //  if timeout is set to zero session never expires
        if (empty($timeout)) {
            return false;
        }
        if ($currentTime - $lastPageRefreshTime > $timeout) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Updates the idle time.
     *
     * @return  boolean true if session idle time delayed
     */
    public function updateIdle()
     {
        $ret = false;
        //  check for session timeout
        if (!$this->isTimedOut()) {
            if (time() - $_SESSION['lastRefreshed'] > self::UPDATE_WINDOW ) {
                $_SESSION['lastRefreshed'] = time();
            }
            $ret = true;
        }
        return $ret;
    }


    /**
     * Returns true if specified permission exists in the session.
     *
     * @param   int $permId the permission id
     * @return  boolean if perm exists or not
     */
    public function hasPerms($permId)
    {
        if (!isset($_SESSION) || !count($_SESSION)) {
            return false;
        }
        //  if admin role, give perms by default
        if ($_SESSION['rid'] == SGL2_ADMIN) {
            $ret = true;
        } else {
            if (is_array($_SESSION['aPerms'])) {
                $ret = in_array($permId, $_SESSION['aPerms']);
            } else {
                $ret = false;
            }
        }
        return $ret;
    }

    /**
     * Enter description here...
     *
     * @param unknown_type $ownerId
     * @return unknown
     * @todo is this used?
     */
    public function currentUserIsOwner($ownerId)
    {
        if (!isset($_SESSION)) {
            return false;
        }
        return $_SESSION['uid'] == $ownerId;
    }

    public function hasAdminGui()
    {
        $aRoles = explode(',', SGL2_Config::get('site.rolesHaveAdminGui'));
        foreach ($aRoles as $k => $role) {
            $aRoles[$k] = SGL2_String::pseudoConstantToInt($role);
        }
        //  at least admin must have admin gui rights
        if (!in_array(SGL2_ADMIN, $aRoles)) {
            $aRoles[] = SGL2_ADMIN;
        }
        if (!isset($_SESSION['rid'])) {
            $ret = false;
        } else {
            $ret = in_array($_SESSION['rid'], $aRoles);
        }
        return $ret;
    }

    /**
     * Returns the current user's id.
     *
     * @return  int the id
     */
    public static function getUid()
    {
        if (count($_SESSION && isset($_SESSION['uid']))) {
            return $_SESSION['uid'];
        } else {
            return false;
        }
    }

    /**
     * Returns the current user's username.
     *
     * @return  int the role id
     */
    public static function getUsername()
    {
        if (count($_SESSION && isset($_SESSION['username']))) {
            return $_SESSION['username'];
        } else {
            return false;
        }
    }

    /**
     * Returns the current user's role id.
     *
     * @return  int the role id
     */
    public static function getRoleId()
    {
        if (isset($_SESSION) && count($_SESSION) && isset($_SESSION['rid'])) {
            return $_SESSION['rid'];
        } else {
            return false;
        }
    }

    /**
     * Removes specified var from session.
     *
     * @param   string  $sessVarName   name of session var
     * @return  boolean
     */
    public static function remove($sessVarName)
    {
        if (isset($sessVarName)) {
            unset($_SESSION[$sessVarName]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Gets specified var from session.
     *
     * @access  public
     * @param   string  $sessVarName    name of session var
     * @return  string                  value of session variable
     */
    public static function get($sessVarName)
    {
        if (isset($sessVarName) && isset($_SESSION)) {
            return is_array($_SESSION)
                ? (array_key_exists($sessVarName, $_SESSION) ? $_SESSION[$sessVarName] : null)
                : '';
        } else {
            return null;
        }
    }

    /**
     * Sets specified var in session.
     *
     * @access  public
     * @param   string  $sessVarName   name of session var
     * @param   mixed   $sessVarValue  value of session var
     * @return  void
     */
    public static function set($sessVarName, $sessVarValue)
    {
        $_SESSION[$sessVarName] = $sessVarValue;
    }

    /**
     * Dumps session contents.
     *
     * @return  void
     */
    public static function debug()
    {
        $ret = '';
        foreach ($_SESSION as $sessVarName => $sessVarValue) {
            $ret .= "$sessVarName => $sessVarValue<br />\n";
        }
        return $ret;
    }

    /**
     * Returns a valid session identifier that can be used as a URL paramenter, ie
     * SGLSESSID=1cgmq51l7jh8og8qvt0qu1ntf4
     *
     * @return string
     */
    public static function getId()
    {
        return defined('SID') && SID !=''
            ? SID
            : SGL2_Config::get('cookie.name') . '='. session_id();
    }

    /**
     * Destroys current session.
     *
     * @access  public
     * @return  void
     * @todo    why does session_destroy fail sometimes?
     */
    public static function destroy()
    {
        foreach ($_SESSION as $sessVarName => $sessVarValue) {
            if (isset($_SESSION)) {
                unset($sessVarName);
            }
        }
        session_destroy();
        $_SESSION = array();

        //  clear session cookie so theme comes from DB and not session
        setcookie(  SGL2_Config::get('cookie.name'), null, 0, SGL2_Config::get('cookie.path'),
                    SGL2_Config::get('cookie.domain'), SGL2_Config::get('cookie.secure'));
        //  clear SGL2_REMEMBER_ME cookie to actually destroy the permanent session
        if (SGL2_Config::get('cookie.rememberMeEnabled')) {
            $ok = setcookie('SGL2_REMEMBER_ME', null, 0, SGL2_Config::get('cookie.path'),
                SGL2_Config::get('cookie.domain'), SGL2_Config::get('cookie.secure'));
        }
        new SGL2_Session();
    }

    /**
     * Detect if it is a first anonymous request to SGL.
     *
     * @param boolean $clean  clean first launch info about anon request
     * @return boolean
     */
    public static function isFirstAnonRequest($clean = null)
    {
        static $ret;
        if (!empty($clean)) {
            if (isset($_SESSION['isFirstAnonRequest'])) {
                unset($_SESSION['isFirstAnonRequest']);
            }
            if (isset($ret)) {
                unset($ret);
            }
            return true;
        } elseif (SGL2_Session::getRoleId() == SGL2_GUEST && !isset($ret)) {
            $ret = !isset($_SESSION['isFirstAnonRequest']);
            if (!isset($_SESSION['isFirstAnonRequest'])) {
                $_SESSION['isFirstAnonRequest'] = true;
            }
            return isset($ret) ? $ret : false;
        }
    }

    /**
     * Detect if it is a first authenticated request to SGL.
     *
     * @param boolean $clean  clean first launch info about auth request.
     * @return boolean
     */
    public static function isFirstAuthenticatedRequest($clean = null)
    {
        static $ret;
        if (!empty($clean)) {
            if (isset($_SESSION['isFirstAuthRequest'])) {
                unset($_SESSION['isFirstAuthRequest']);
            }
            if (isset($ret)) {
                unset($ret);
            }
            return true;
        } elseif (SGL2_Session::getRoleId() > SGL2_GUEST && !isset($ret)) {
            $ret = !isset($_SESSION['isFirstAuthRequest']);
            if (!isset($_SESSION['isFirstAuthRequest'])) {
                $_SESSION['isFirstAuthRequest'] = true;
            }
            return isset($ret) ? $ret : false;
        }
    }
}

?>