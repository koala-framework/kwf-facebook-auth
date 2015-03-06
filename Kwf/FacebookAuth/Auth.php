<?php
class Kwf_FacebookAuth_Auth extends Kwf_User_Auth_Abstract implements Kwf_User_Auth_Interface_Redirect
{
    protected $_client;
    protected $_clientId;
    protected $_clientSecret;

    public function __construct(array $config, $model)
    {
        $this->_clientId = $config['clientId'];
        $this->_clientSecret = $config['clientSecret'];
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
            'state'=> json_encode($state),
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
        $c = new Zend_Http_Client($url);
        $response = $c->request('GET');
        if (!$response->isSuccessful()) throw new Kwf_Exception("Request failed: ".$response->getBody());
        parse_str($response->getBody(), $r);
        $accessToken = $r['access_token'];

        $url = 'https://graph.facebook.com/me?access_token='.$accessToken;
        $c = new Zend_Http_Client($url);
        $response = $c->request('GET');
        if (!$response->isSuccessful()) throw new Kwf_Exception("Request failed: ".$response->getBody());
        $userData = json_decode($response->getBody());

        return $userData;
    }

    public function getUserToLoginByParams($redirectBackUrl, array $params)
    {
        $userData = $this->_getUserDataByParams($redirectBackUrl, $params);
        $s = new Kwf_Model_Select();
        $s->whereEquals('facebook_user_id', $userData->id);
        return $this->_model->getRow($s);
    }

    public function associateUserByParams(Kwf_Model_Row_Interface $user, $redirectBackUrl, array $params)
    {
        $userData = $this->_getUserDataByParams($redirectBackUrl, $params);
        $user->facebook_user_id = $userData->id;
        $user->save();
    }

    public function showInBackend()
    {
        return true;
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
}
