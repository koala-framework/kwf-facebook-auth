<?php
class Kwf_FacebookAuth_Auth extends Kwf_User_Auth_Abstract implements Kwf_User_Auth_Interface_Redirect
{
    protected $_client;
    protected $_clientId;
    protected $_clientSecret;
    protected $_registerRole;
    protected $_matchByEmail;
    protected $_storeUserInSession;

    public function __construct(array $config, $model)
    {
        $this->_clientId = $config['clientId'];
        $this->_clientSecret = $config['clientSecret'];
        $this->_registerRole = isset($config['registerRole']) ? $config['registerRole'] : null;
        $this->_matchByEmail = isset($config['matchByEmail']) ? $config['matchByEmail'] : null;
        $this->_storeUserInSession = isset($config['storeUserInSession']) ? $config['storeUserInSession'] : false;
        parent::__construct($model);
    }

    public function getLoginRedirectFormOptions()
    {
        return array();
    }

    public function getLoginRedirectUrl($redirectBackUrl, $state, $formValues)
    {
        $url = 'https://www.facebook.com/dialog/oauth';
        $url .= '?'.http_build_query(array(
            'scope' => 'email',
            'state'=> $state,
            'redirect_uri' => $redirectBackUrl,
            'response_type' => 'code',
            'client_id' => $this->_clientId,
        ));
        return $url;
    }

    private function _getUserDataByParams($redirectBackUrl, array $params)
    {
        $url = 'https://graph.facebook.com/oauth/access_token';
        $url .= '?'.http_build_query(array(
            'client_id' => $this->_clientId,
            'redirect_uri' => $redirectBackUrl,
            'client_secret' => $this->_clientSecret,
            'code'=> $params['code'],
        ));
        $httpClientConfig = array();
        if (Kwf_Config::getValue('http.proxy.host')) {
            $httpClientConfig['adapter'] = 'Zend_Http_Client_Adapter_Curl';
            $httpClientConfig['proxy_host'] = Kwf_Config::getValue('http.proxy.host');
            $httpClientConfig['proxy_port'] = Kwf_Config::getValue('http.proxy.port');
        }
        $c = new Zend_Http_Client($url, $httpClientConfig);
        $response = $c->request('GET');
        if (!$response->isSuccessful()) throw new Kwf_Exception("Request failed: ".$response->getBody());
        $r = json_decode($response->getBody(), true);
        $accessToken = $r['access_token'];

        $url = 'https://graph.facebook.com/me?access_token='.$accessToken.'&fields=name,email,first_name,last_name,gender';
        $c = new Zend_Http_Client($url, $httpClientConfig);
        $response = $c->request('GET');
        if (!$response->isSuccessful()) throw new Kwf_Exception("Request failed: ".$response->getBody());
        $userData = json_decode($response->getBody());

        return $userData;
    }

    public function getUserToLoginByParams(array $params)
    {
        return null;
    }

    public function associateUserByParams(Kwf_Model_Row_Interface $user, $redirectBackUrl, array $params)
    {
        $userData = $this->_getUserDataByParams($redirectBackUrl, $params);
        $user->facebook_user_id = $userData->id;
        $user->save();
    }

    public function showInBackend()
    {
        return false;
    }

    public function showForActivation()
    {
        return false;
    }

    public function showInFrontend()
    {
        return true;
    }

    public function getLoginRedirectLabel()
    {
        return array(
            'name' => trlKwfStatic('Facebook'),
            'linkText' => trlKwfStatic('Facebook'),
            //'icon' => 'kwfFacebookAuth/Kwf/FacebookAuth/signInWithFacebook.png'
        );
    }

    public function allowPasswordForUser(Kwf_Model_Row_Interface $user)
    {
        return true;
    }

    public function isRedirectCompatibleWith(Kwf_User_Auth_Interface_Redirect $auth)
    {
        while ($auth instanceof Kwf_User_Auth_Proxy_Abstract || $auth instanceof Kwf_User_Auth_Union_Abstract) {
            $auth = $auth->getInnerAuth();
        }
        if ($auth instanceof Kwf_FacebookAuth_Auth
            && $this->_clientId == $auth->_clientId
        ) {
            return true;
        }
        return false;
    }

    public function getLoginRedirectHtml($redirectBackUrl, $state, $formValues)
    {
        return null;
    }

    public function getUserToLoginByCallbackParams($redirectBackUrl, array $params)
    {
        $userData = $this->_getUserDataByParams($redirectBackUrl, $params);
        $s = new Kwf_Model_Select();
        $s->whereEquals('facebook_user_id', $userData->id);
        $ret = $this->_model->getRow($s);

        if (!$ret && $this->_matchByEmail) {
            $s = new Kwf_Model_Select();
            $s->whereEquals('email', $userData->email);
            $ret = $this->_model->getRow($s);
            if ($ret) {
                $ret->facebook_user_id = $userData->id;
                $ret->save();
            }
        }

        if ($this->_storeUserInSession) {
            $kwfUsersModel = Kwf_Model_Abstract::getInstance('KwfUsers');
            $select = new Kwf_Model_Select();
            $select->whereEquals('email', $userData->email);
            $existingUser = $kwfUsersModel->getRow($select);
            $select->where(new Kwf_Model_Select_Expr_IsNull('accepted_terms_and_conditions'));
            $existingUserWithoutTermsAndConditionsAccepted = $kwfUsersModel->getRow($select);

            if ($existingUserWithoutTermsAndConditionsAccepted || !$existingUser) {
                $session = new Kwf_Session_Namespace('FacebookAuth');
                $session->userData = $userData;
                $redirectUrl = Kwf_Component_Data_Root::getInstance()->getChildComponent('-website')->getChildComponent('_login')->getUrl();
                if (isset($params['redirect'])) {
                    Kwf_Util_Redirect::redirect($redirectUrl . '?redirect=' . $params['redirect']);
                } else {
                    Kwf_Util_Redirect::redirect($redirectUrl);
                }
            }
        }

        if (!$ret && $this->_registerRole) {
            $ret = $this->_model->createUserRow($userData->email);
            $ret->role = $this->_registerRole;
            $ret->facebook_user_id = $userData->id;
            $ret->firstname = $userData->first_name;
            $ret->lastname = $userData->last_name;
            $ret->gender = $userData->gender == 'male' ? 'male' : ($userData->gender == 'female' ? 'female' : '');
            if ($ret instanceof Kwf_User_EditRow) $ret->setSendMails(false); //we don't want a register mail
            $ret->save();
        }
        return $ret;
    }

    public function associateUserByCallbackParams(Kwf_Model_Row_Interface $user, $redirectBackUrl, array $params)
    {
        $userData = $this->_getUserDataByParams($redirectBackUrl, $params);
        $user->facebook_user_id =$userData->id;
        $user->save();
    }
}
