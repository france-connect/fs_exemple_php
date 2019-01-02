<?php
#    This file is part of the PHP example for FranceConnect
#
#    Copyright (C) 2015-2016 Eric Pommateau, Maxime Reyrolle, Arnaud Bétrémieux
#
#    This example is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    (at your option) any later version.
#
#    This example is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this example.  If not, see <http://www.gnu.org/licenses/>.

class FranceConnect {

	const OPENID_SESSION_TOKEN = "open_id_session_token";
	const OPENID_SESSION_NONCE = "open_id_session_nonce";

	private $france_connect_base_url;
	private $client_id;
	private $client_secret;
	private $url_callback;
	private $cert_folder;

	public function __construct($france_connect_base_url,$client_id,$client_secret, $url_callback){
		$this->france_connect_base_url = $france_connect_base_url;
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->url_callback = $url_callback;
		$this->cert_folder = dirname(__DIR__)."/ssl";
	}

	public function authenticationRedirect($url_callback, $sup_scope = array()){
		$_SESSION[self::OPENID_SESSION_TOKEN] = $this->getRandomToken();
		$state = "token={$_SESSION[self::OPENID_SESSION_TOKEN]}";

		$_SESSION[self::OPENID_SESSION_NONCE] = $this->getRandomToken();

		$scope = "openid%20profile%20".implode("%20",$sup_scope);

		$info=array("response_type"=>'code',
					"client_id"=> $this->client_id,
					"scope"=>$scope,
					"redirect_uri"=>$this->url_callback,
					"state"=>urlencode($state),
					"nonce"=>$_SESSION[self::OPENID_SESSION_NONCE]
		);

		$url = $this->getURLforService("authorize");
		foreach($info as $key=>$value){
			$url.=$key."=".$value."&";
		}

		header("Location: $url");
	}

	public function callback(){
		$error = $this->recupGET('error');
		if ($error){
			//TODO France Connect rappelle ce callback si jamais il y a une erreur lor de l'appel à la vérif de token...
			throw new Exception("Erreur : $error");
		}

		$state = $this->recupGET('state');
		$this->verifToken($state);

		$code =$this->recupGET('code');
		$access_token = $this->getAccessToken($code);

		$user_info = $this->getInfoFromFI($access_token);
		$user_info['access_token'] = $access_token;
		return $user_info;
	}

	private function verifToken($state){

		$state = urldecode($state);

		$state_array = array();
		parse_str($state, $state_array);

		$token = $state_array['token'];

		if ($token != $_SESSION[self::OPENID_SESSION_TOKEN]){
			throw new Exception("Le token ne correspond pas");
		}
		return true;
	}

	private function getAccessToken($code){
		$curlWrapper = new CurlWrapper();
		$curlWrapper->setServerCertificatePath($this->cert_folder);

		$post_data = array(
				"grant_type" =>"authorization_code",
				"code" => $code,
				"redirect_uri" => $this->url_callback,
				"client_id"=>$this->client_id,
				"client_secret"=>$this->client_secret
		);

		$curlWrapper->setPostDataUrlEncode($post_data);
		$token_url = $this->getURLforService("token");

		$result = $curlWrapper->get($token_url);
		if ($curlWrapper->getHTTPCode() != 200){
			if (! $result){
				throw new Exception($curlWrapper->getLastError());
			}
			$result_array = json_decode($result,true);
			throw new Exception($result_array['error']);
		}

		$result_array = json_decode($result,true);

		$id_token = $result_array['id_token'];

		$all_part = explode(".",$id_token);
		$header = json_decode(base64_decode($all_part[0]),true);
		$payload = json_decode(base64_decode($all_part[1]),true);

		if ($payload['nonce'] != $_SESSION[self::OPENID_SESSION_NONCE]){
			throw new Exception("La nonce ne correspond pas");
		}

		require_once(__DIR__."/../ext/Akita_JOSE/JWS.php");
		$jws = Akita_JOSE_JWS::load($id_token, true);
		$verify = $jws->verify($this->client_secret);
		if (! $verify){
			throw new Exception("Vérification du token : Echec");
		}

		unset($_SESSION[self::OPENID_SESSION_NONCE]);
		return $result_array['access_token'];
	}

	public function getInfoFromFI($access_token){
		$curlWrapper = new CurlWrapper();
		$curlWrapper->setServerCertificatePath($this->cert_folder);
		$curlWrapper->addHeader("Authorization", "Bearer $access_token");
		$user_info_url = $this->getURLforService("userinfo");
		$result = $curlWrapper->get($user_info_url);
		if ($curlWrapper->getHTTPCode() != 200){
			if (! $result){
				$message_erreur = $this->curlWrapper->getLastError();
			} else {
				$result_array = json_decode($result,true);
				$message_erreur = $result_array['error'];
			}
			throw new Exception("Erreur lors de la récupération des infos sur le serveur OpenID : ".$message_erreur);
		}

		return json_decode($result,true);
	}

	public function logout(){
		$logout_url = $this->getURLforService("logout");
		header("Location: $logout_url");
	}

	public function getInfoFromFD($fd_url, $access_token){
		$curlWrapper = new CurlWrapper();
		$curlWrapper->setServerCertificatePath($this->cert_folder);
		$curlWrapper->addHeader("Authorization", "Bearer $access_token");
		$result = $curlWrapper->get($fd_url);
		return json_decode($result,true);
	}

	private function getURLforService($service){
		return trim($this->france_connect_base_url,"/")."/$service?";
	}

	private function getRandomToken(){
		return sha1(mt_rand(0,mt_getrandmax()));
	}

	private function recupGET($variable_name,$default=false){
		if (! isset($_GET[$variable_name])){
			return $default;
		}
		return $_GET[$variable_name];
	}

}