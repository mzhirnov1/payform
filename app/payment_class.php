<?php
require_once(__DIR__ . '/../vendor/autoload.php');
use Curl\Curl;

class paymentClass 
{
  private $stripe;

  private $redis;

  private $curl;

  private $amoTokenPath;

  private $out = [
    'status' => true,
    'data' => []
  ];

  public function __construct() 
  {
    $dotEnv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotEnv->load();
    $this->stripe = new \Stripe\StripeClient($_ENV['STRIPE_TOKEN']);
    $this->redis = new \redis();
    $this->redis->pconnect('127.0.0.1', 6379, 0.0);
    $this->redis->auth($_ENV['REDIS_PASS']);
    $this->curl = new Curl();
    $this->curl->setUserAgent('amoCRM-oAuth-client/1.0');
    $this->amoTokenPath = __DIR__ . '/tokkens.json';
  }

  public function load($post, $get) 
  {
    if(isset($get['auth'])) {
      $this->authAmoCrm();
    } elseif(isset($get['stripe'])) {
      $data = file_get_contents('php://input');
      $data = json_decode($data, true);
      $this->sendToAmo('updateAmoLead', $this->redis->get($data['data']['object']['id']));
    } elseif(method_exists($this, $post['action'])) {
      $class = $post['action'];
      $this->$class($post);
    }
    return $this->out;
  }

  private function session($data) 
  {
    try {
      $session = $this->stripe->checkout->sessions->create([
        'line_items' => [[
          'price_data' => [
            'currency' => $data['currency'],
            'unit_amount' => $data['amount'] * 100,
            'product' => $_ENV['PRODUCT_ID']
          ],
          'quantity' => 1
        ]],
        'mode' => 'payment',
        'success_url' => $_ENV['SUCCESS_URL'],
        'cancel_url' => $_ENV['ERROR_URL']
      ]);
      $this->sendToAmo('createAmoLead', array_merge($data, [
        'payment_intent' => $session->payment_intent
      ]));
      $this->out['data'] = $session->url;
    } catch(Exception $e) {
      $this->out = [
        'status' => false,
        'data' => $e->getMessage()
      ];
    }
  }

  private function recalculate($data) 
  {
    $params = [
      'access_key' => $_ENV['FIXER_TOKEN'],
      'from' => $data['from'],
      'to' => $data['to'],
      'amount' => $data['amount']
    ];
    $this->out['data'] = $this->curl->get('http://data.fixer.io/api/convert?' . http_build_query($params, '', '&'));
  }

  private function sendToAmo($action, $data) 
  {
    $dataToken = file_get_contents($this->amoTokenPath);
    $dataToken = json_decode($dataToken, true);
    $token = null;
    if($dataToken['endTokenTime'] < time()) {
      if($dataToken = $this->refreshAmoToken($dataToken['refresh_token'])) {
        $token = $dataToken['access_token'];
      }
    } else {
      $token = $dataToken['access_token'];
    }
    if($token) {
      $this->$action($data, $token);
    }
  }

  private function createAmoLead($data, $token) 
  {
    $this->curl->setHeader('AUTHORIZATION', 'Bearer ' . $token);
    $response = $this->curl->post(
      'https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/private/api/v2/json/leads/set', 
      [
        'request' => [
          'leads' => [
            'add' => [[
              'name' => $data['phone'],
              'price' => (int) $data['amount'],
              'status_id' => (int) $_ENV['AMO_STATUS_FIRST']
            ]]
          ]
        ]
      ]
    );
    $response = json_decode(json_encode($response), true);
    $data['lead_id'] = $response['response']['leads']['add'][0]['id'];
    $this->redis->set($data['payment_intent'], $data['lead_id']);
    $this->sendToAmo('createAmoContact', $data);
  }

  private function updateAmoLead($data, $token) 
  {
    $this->curl->setHeader('AUTHORIZATION', 'Bearer ' . $token);
    $this->curl->post(
      'https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/private/api/v2/json/leads/set', 
      [
        'request' => [
          'leads' => [
            'update' => [[
              'id' => (int) $data,
              'last_modified' => time(),
              'status_id' => (int) $_ENV['AMO_STATUS_LAST']
            ]]
          ]
        ]
      ]
    );
  }

  private function createAmoContact($data, $token) 
  {
    $this->curl->setHeader('AUTHORIZATION', 'Bearer ' . $token);
    $this->curl->post(
      'https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/private/api/v2/json/contacts/set', 
      [
        'request' => [
          'contacts' => [
            'add' => [[
              'name' => $data['phone'],
              'linked_leads_id' => $data['lead_id'],
              'custom_fields' => [[
                'id' => (int) $_ENV['AMO_PHONE_ID'],
                'values' => [[
                  'value' => $data['phone'],
                  'enum' => 'MOB'
                ]]
              ]]
            ]]
          ]
        ]
      ]//359029
    );
    $response = $this->curl->get('https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/api/v2/contacts/');
    
    $this->out['data'] = $response;
  }
  
  private function refreshAmoToken($token) {
    $response = $this->curl->post(
      'https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/oauth2/access_token', 
      [
        'client_id' => $_ENV['AMO_CLIENT_ID'],
        'client_secret' => $_ENV['AMO_CLIENT_SECRET'],
        'grant_type' => 'refresh_token',
        'refresh_token' => $token,
        'redirect_uri' => $_ENV['AMO_REDIRECT_URI']
      ]
    );
    $response = json_decode(json_encode($response), true);
    $response['endTokenTime'] = time() + $response['expires_in'];
    file_put_contents($this->amoTokenPath, json_encode($response));
    if($this->curl->error) {
      $this->out['data'] = [
        'code' => $this->curl->errorCode,
        'message' => $this->curl->errorMessage
      ];
      $out = false;
    } else {
      $out = $response['access_token'];
    }
    return $out;
  }

  private function authAmoCrm() {
    $response = $this->curl->post(
      'https://' . $_ENV['AMO_DOMAIN_NAME'] . '.amocrm.ru/oauth2/access_token', 
      [
        'client_id' => $_ENV['AMO_CLIENT_ID'],
        'client_secret' => $_ENV['AMO_CLIENT_SECRET'],
        'grant_type' => 'authorization_code',
        'code' => $_ENV['AMO_CLIENT_AUTH'],
        'redirect_uri' => $_ENV['AMO_REDIRECT_URI']
      ]
    );
    $response = json_decode(json_encode($response), true);
    if($this->curl->error) {
      $response = [
        'code' => $this->curl->errorCode,
        'message' => $this->curl->errorMessage,
        'data' => $response,
        'info' => $this->curl->getInfo()
      ];
    } else {
      $response = [
        'access_token' => $response['access_token'],
        'refresh_token' => $response['refresh_token'],
        'token_type' => $response['token_type'],
        'expires_in' => $response['expires_in'],
        'endTokenTime' => $response['expires_in'] + time()
      ];
      file_put_contents($this->amoTokenPath, json_encode($response));
    }
    echo '<pre>';
    print_r([$response, [
      'client_id' => $_ENV['AMO_CLIENT_ID'],
      'client_secret' => $_ENV['AMO_CLIENT_SECRET'],
      'grant_type' => 'authorization_code',
      'code' => $_ENV['AMO_CLIENT_AUTH'],
      'redirect_uri' => $_ENV['AMO_REDIRECT_URI']
    ]]);
    exit();
  }
}